<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Vue « Carte » pour The Events Calendar (Views V2) — PROTOTYPE.
 *
 * Reproduit la vue Carte de la version payante (Events Calendar Pro) sans licence :
 * on enregistre une vraie vue TEC V2 (3e onglet après Liste / Mois), rendue avec
 * Leaflet (auto-hébergé) + tuiles OpenStreetMap. Les lieux (tribe_venue) n'ayant pas
 * de coordonnées en version gratuite, on les géocode via Nominatim (OSM) — mais
 * uniquement côté écriture : à l'enregistrement d'un lieu et via un backfill de fond
 * (WP-Cron). Le rendu public ne fait JAMAIS d'appel réseau, il lit le cache post meta.
 *
 * Deux classes ici :
 *  - Passiflore_Map_View  : la vue TEC minimale (slug « carte », rend carte.php).
 *  - Passiflore_Events_Map: le contrôleur (enregistrement, activation, géocodage,
 *                           données des marqueurs, enqueue des assets).
 */

/**
 * La vue TEC. Minimale : hérite de la View de base, rend le template `carte.php`
 * (résolu depuis tribe/events/v2/ du thème). La collecte des événements et le
 * rendu de la carte se font côté template + JS, pas via le pipeline Repository.
 */
class Passiflore_Map_View extends \Tribe\Events\Views\V2\View {

	/** @var string Slug historique (déprécié mais lu par certaines branches de TEC). */
	protected $slug = 'carte';

	/** @var string Slug statique (API 6.0.7+). */
	protected static $view_slug = 'carte';

	/** @var bool Visible dans le sélecteur de vues. */
	protected static $publicly_visible = true;

	/** @var string Libellé par défaut (non traduit). */
	protected static $label = 'Carte';

	/**
	 * Libellé affiché dans l'onglet du sélecteur de vue.
	 */
	public static function get_view_label(): string {
		return _x( 'Carte', 'Libellé de la vue carte des événements', 'passiflore' );
	}

	/**
	 * Requête minimale et valide : la carte fait sa propre collecte
	 * (Passiflore_Events_Map::get_mappable_events), on n'a pas besoin de la
	 * pagination liste ici.
	 */
	protected function setup_repository_args( \Tribe__Context $context = null ) {
		$args                   = parent::setup_repository_args( $context );
		$args['posts_per_page'] = 1;

		return $args;
	}
}

/**
 * Contrôleur de la vue Carte.
 */
class Passiflore_Events_Map {

	const SLUG                 = 'carte';
	const REWRITE_VERSION      = '1';                // bump → reflush des règles de réécriture
	const GEO_META_LAT         = '_pf_venue_lat';
	const GEO_META_LNG         = '_pf_venue_lng';
	const GEO_META_SRC         = '_pf_venue_geo_src'; // adresse tentée (fraîcheur + anti-boucle backfill)
	const GEO_BACKFILL_HOOK    = 'pf_geocode_backfill';
	const GEO_MAX_PER_BACKFILL = 5;                  // lieux géocodés par passe de backfill (WP-Cron)

	/** @var float microtime du dernier appel Nominatim (throttle >= 1,1 s). */
	private static $last_nominatim = 0.0;

	/** @var array|null Cache mémoire des événements cartographiables. */
	private static $events_cache = null;

	public function __construct() {
		add_filter( 'tribe_get_single_option', [ $this, 'enable_view' ], 10, 3 );
		add_action( 'init', [ $this, 'register_view' ], 5 );
		add_action( 'init', [ $this, 'maybe_flush_rewrites' ], 20 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 20 );

		// Géocodage côté écriture (jamais pendant le rendu public) :
		//  - à l'enregistrement d'un lieu dans l'admin ;
		//  - backfill des lieux existants en tâche de fond (WP-Cron auto-drainant).
		add_action( 'save_post_tribe_venue', [ $this, 'geocode_on_save' ], 25 );
		add_action( 'admin_init',            [ $this, 'maybe_schedule_backfill' ] );
		add_action( self::GEO_BACKFILL_HOOK, [ $this, 'run_backfill' ] );

		// Recherche sur la carte : renvoie des IDs d'événements classés (le front
		// filtre ses marqueurs). Même moteur que la recherche liste/globale.
		add_action( 'wp_ajax_pf_events_map_search',        [ $this, 'ajax_search' ] );
		add_action( 'wp_ajax_nopriv_pf_events_map_search', [ $this, 'ajax_search' ] );
	}

	/* ─── Enregistrement de la vue ─────────────────────────────────── */

	/**
	 * Enregistre la vue via le Manager TEC : cela câble à la fois la résolution
	 * de la classe (filtre tribe_events_views), les slugs de réécriture et les
	 * routes /evenements/carte/. Rejoué à chaque requête (les filtres doivent être
	 * présents pour le routage) ; les règles ne sont matérialisées qu'au flush.
	 */
	public function register_view() {
		if ( ! function_exists( 'tribe' ) || ! class_exists( '\Tribe\Events\Views\V2\Manager' ) ) {
			return;
		}
		tribe( \Tribe\Events\Views\V2\Manager::class )->register_view(
			self::SLUG,
			__( 'Carte', 'passiflore' ),
			'Passiflore_Map_View',
			40
		);
	}

	/**
	 * Force la vue « carte » dans la liste des vues activées (option tribeEnableViews),
	 * sans modifier l'option stockée : la vue reste toujours proposée dans le sélecteur.
	 */
	public function enable_view( $value, $default, $name ) {
		if ( 'tribeEnableViews' === $name && is_array( $value ) && ! in_array( self::SLUG, $value, true ) ) {
			$value[] = self::SLUG;
		}
		return $value;
	}

	/**
	 * Flush unique des règles de réécriture après ajout de la route (auto-cicatrisant,
	 * gardé par une option de version — pas de WP-CLI requis).
	 */
	public function maybe_flush_rewrites() {
		if ( get_option( 'pf_carte_rw_version' ) === self::REWRITE_VERSION ) {
			return;
		}
		flush_rewrite_rules();
		update_option( 'pf_carte_rw_version', self::REWRITE_VERSION );
	}

	/* ─── Détection de la vue courante + assets ────────────────────── */

	private function is_map_view() {
		return ! is_admin()
			&& function_exists( 'tribe_is_event_query' )
			&& tribe_is_event_query()
			&& self::SLUG === get_query_var( 'eventDisplay' );
	}

	public function enqueue() {
		if ( ! $this->is_map_view() ) {
			return;
		}

		$uri = get_stylesheet_directory_uri();
		$dir = get_stylesheet_directory();

		wp_enqueue_style( 'leaflet', $uri . '/assets/vendor/leaflet/leaflet.css', [], '1.9.4' );
		wp_enqueue_style( 'leaflet-markercluster', $uri . '/assets/vendor/leaflet/MarkerCluster.css', [ 'leaflet' ], '1.5.3' );
		wp_enqueue_style(
			'pf-events-map',
			$uri . '/assets/css/events-map.css',
			[ 'leaflet', 'leaflet-markercluster', 'pf-events' ],
			filemtime( $dir . '/assets/css/events-map.css' )
		);
		wp_enqueue_script( 'leaflet', $uri . '/assets/vendor/leaflet/leaflet.js', [], '1.9.4', true );
		wp_enqueue_script( 'leaflet-markercluster', $uri . '/assets/vendor/leaflet/leaflet.markercluster.js', [ 'leaflet' ], '1.5.3', true );
		wp_enqueue_script(
			'pf-events-map',
			$uri . '/assets/js/events-map.js',
			[ 'leaflet', 'leaflet-markercluster' ],
			filemtime( $dir . '/assets/js/events-map.js' ),
			true
		);
		wp_localize_script( 'pf-events-map', 'PassifloreMap', [
			'tileUrl'           => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
			'attribution'       => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
			'franceView'        => [ 46.6, 2.4, 5 ], // centre + zoom de repli (France entière)
			'emptyText'         => __( 'Aucun événement géolocalisé à afficher pour le moment.', 'passiflore' ),
			'searchEmptyText'   => __( 'Aucun événement ne correspond à votre recherche.', 'passiflore' ),
			'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
			'searchNonce'       => wp_create_nonce( 'pf_events_map_search' ),
			'placeholderMobile' => __( 'Événement', 'kadence-child' ), // même placeholder mobile que la recherche liste.
			'markers'           => $this->build_markers(),
		] );
	}

	/* ─── Recherche sur la carte ───────────────────────────────────── */

	/**
	 * Barre de recherche de la vue carte. Rendue dans le `__left` du header
	 * (cf. tribe/.../components/header.php). Reprend le composant visuel
	 * `.pf-search--sm` (comme la recherche liste) mais avec des classes propres
	 * (`.pf-map-search*`) pour que le contrôleur de la liste (events-search.js,
	 * qui cible `.pf-ev-search-input`) ne la détourne pas.
	 */
	public static function render_search_bar() {
		$svg_loupe = '<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>';

		ob_start();
		?>
		<div class="pf-map-search">
			<div class="pf-search pf-search--sm">
				<?php echo $svg_loupe; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<input
					type="search"
					class="pf-search-input pf-map-search-input"
					placeholder="<?php esc_attr_e( 'Rechercher par mot-clé, participant, lieu, livre…', 'kadence-child' ); ?>"
					data-placeholder-sm="<?php esc_attr_e( 'Par mot-clé, participant, lieu, livre…', 'kadence-child' ); ?>"
					autocomplete="off"
					aria-label="<?php esc_attr_e( 'Rechercher un événement sur la carte', 'kadence-child' ); ?>"
				>
				<button class="pf-search-clear pf-map-search-clear" type="button"
					aria-label="<?php esc_attr_e( 'Effacer', 'kadence-child' ); ?>">×</button>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Endpoint AJAX : renvoie les IDs d'événements (à venir) correspondant à la
	 * requête, classés par le moteur partagé `pf_search_events_ranked()`. Le front
	 * filtre ses marqueurs sur cet ensemble (pas de rendu HTML côté serveur).
	 */
	public function ajax_search() {
		check_ajax_referer( 'pf_events_map_search', 'nonce' );

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		if ( mb_strlen( trim( $search ) ) < 2 || ! function_exists( 'pf_search_events_ranked' ) ) {
			wp_send_json_success( [ 'ids' => [] ] );
		}

		// 3e argument = upcoming_only : la carte n'affiche que les événements à venir.
		$ids = array_slice( pf_search_events_ranked( $search, [], true ), 0, 200 );

		wp_send_json_success( [ 'ids' => array_map( 'intval', $ids ) ] );
	}

	/* ─── Collecte des événements ──────────────────────────────────── */

	/**
	 * Événements cartographiables : à venir ou en cours, triés par date.
	 * (Statique pour être réutilisé par le template de repli.)
	 *
	 * @return WP_Post[]
	 */
	public static function get_mappable_events() {
		if ( null !== self::$events_cache ) {
			return self::$events_cache;
		}
		self::$events_cache = tribe_events()
			->where( 'ends_after', 'now' )
			->order_by( 'event_date', 'ASC' )
			->per_page( 200 )
			->all();

		return self::$events_cache;
	}

	/**
	 * Construit les marqueurs, regroupés par lieu (un point = un lieu, plusieurs
	 * événements possibles dans l'infobulle).
	 *
	 * @return array
	 */
	private function build_markers() {
		$events = self::get_mappable_events();

		// Regroupement par lieu, en préservant l'ordre chronologique.
		$by_venue = [];
		foreach ( $events as $event ) {
			$venue_id = tribe_get_venue_id( $event->ID );
			if ( ! $venue_id ) {
				continue;
			}
			$by_venue[ $venue_id ][] = $event;
		}

		$markers = [];
		foreach ( $by_venue as $venue_id => $venue_events ) {
			$coords = $this->get_venue_coords( (int) $venue_id );
			if ( ! $coords ) {
				continue;
			}

			$evs = [];
			foreach ( $venue_events as $event ) {
				$thumb = get_the_post_thumbnail_url( $event->ID, 'thumbnail' );
				$evs[] = [
					'id'    => (int) $event->ID, // pour le filtrage par la recherche carte
					// Texte brut (décodé) : le JS l'échappe au rendu — éviter le
					// double-encodage des entités (ex. « & » dans un titre/lieu).
					'title' => html_entity_decode( get_the_title( $event->ID ), ENT_QUOTES ),
					'url'   => esc_url_raw( get_permalink( $event->ID ) ),
					'date'  => tribe_get_start_date( $event->ID, false, 'j F Y' ),
					'thumb' => $thumb ? esc_url_raw( $thumb ) : '',
				];
			}

			$markers[] = [
				'lat'    => $coords['lat'],
				'lng'    => $coords['lng'],
				'venue'  => html_entity_decode( tribe_get_venue( $venue_id ), ENT_QUOTES ),
				'city'   => html_entity_decode( tribe_get_city( $venue_id ), ENT_QUOTES ),
				'dept'   => get_post_meta( $venue_id, '_VenueDepartement', true ),
				'region' => get_post_meta( $venue_id, '_VenueRegion', true ),
				'events' => $evs,
			];
		}

		return $markers;
	}

	/* ─── Lecture des coordonnées (front : cache uniquement) ───────── */

	/**
	 * Coordonnées mises en cache pour un lieu, ou null si non (encore) géocodé.
	 * Le front ne fait JAMAIS d'appel réseau : le géocodage a lieu à l'écriture
	 * (geocode_on_save) et via le backfill de fond (run_backfill).
	 *
	 * @return array{lat:float,lng:float}|null
	 */
	private function get_venue_coords( $venue_id ) {
		$lat = get_post_meta( $venue_id, self::GEO_META_LAT, true );
		$lng = get_post_meta( $venue_id, self::GEO_META_LNG, true );

		if ( '' === $lat || '' === $lng ) {
			return null;
		}

		return [ 'lat' => (float) $lat, 'lng' => (float) $lng ];
	}

	/* ─── Géocodage côté écriture (Nominatim / OpenStreetMap) ──────── */

	/**
	 * À l'enregistrement d'un lieu : (re)géocode si l'adresse a changé.
	 */
	public function geocode_on_save( $venue_id ) {
		if ( wp_is_post_revision( $venue_id ) || wp_is_post_autosave( $venue_id ) ) {
			return;
		}
		// save_post peut se déclencher plusieurs fois par requête : une seule passe.
		static $done = [];
		if ( isset( $done[ $venue_id ] ) ) {
			return;
		}
		$done[ $venue_id ] = true;

		$this->geocode_venue( (int) $venue_id );
	}

	/**
	 * Géocode un lieu et met le résultat en cache dans ses post meta. Stampe
	 * toujours l'adresse tentée (GEO_META_SRC), même en cas d'échec, pour éviter
	 * que le backfill ne repasse indéfiniment sur les lieux non géocodables.
	 *
	 * @return array{lat:float,lng:float}|null
	 */
	private function geocode_venue( $venue_id ) {
		$addr = $this->venue_address_string( $venue_id );
		$src  = get_post_meta( $venue_id, self::GEO_META_SRC, true );
		$lat  = get_post_meta( $venue_id, self::GEO_META_LAT, true );

		// Déjà géocodé avec succès pour cette adresse → rien à faire.
		if ( $src === $addr && '' !== $lat ) {
			return [ 'lat' => (float) $lat, 'lng' => (float) get_post_meta( $venue_id, self::GEO_META_LNG, true ) ];
		}

		// Lieu sans adresse exploitable : on marque « tenté » et on sort.
		if ( '' === $addr ) {
			update_post_meta( $venue_id, self::GEO_META_SRC, '' );
			delete_post_meta( $venue_id, self::GEO_META_LAT );
			delete_post_meta( $venue_id, self::GEO_META_LNG );
			return null;
		}

		$coords = $this->geocode( $venue_id );

		// Adresse tentée mémorisée dans tous les cas (anti-boucle du backfill).
		update_post_meta( $venue_id, self::GEO_META_SRC, $addr );

		if ( ! $coords ) {
			delete_post_meta( $venue_id, self::GEO_META_LAT );
			delete_post_meta( $venue_id, self::GEO_META_LNG );
			return null;
		}

		update_post_meta( $venue_id, self::GEO_META_LAT, $coords['lat'] );
		update_post_meta( $venue_id, self::GEO_META_LNG, $coords['lng'] );

		return $coords;
	}

	/* ─── Backfill des lieux existants (WP-Cron, auto-drainant) ────── */

	/**
	 * Planifie une passe de backfill s'il reste des lieux jamais géocodés.
	 * Vérifié en admin uniquement (aucun surcoût sur les requêtes publiques) ;
	 * la passe s'exécute ensuite via WP-Cron.
	 */
	public function maybe_schedule_backfill() {
		if ( wp_next_scheduled( self::GEO_BACKFILL_HOOK ) ) {
			return;
		}
		if ( $this->ungeocoded_venue_ids( 1 ) ) {
			wp_schedule_single_event( time() + 30, self::GEO_BACKFILL_HOOK );
		}
	}

	/**
	 * Une passe de backfill : géocode un lot de lieux, se replanifie s'il en reste.
	 */
	public function run_backfill() {
		foreach ( $this->ungeocoded_venue_ids( self::GEO_MAX_PER_BACKFILL ) as $venue_id ) {
			$this->geocode_venue( (int) $venue_id );
		}
		if ( $this->ungeocoded_venue_ids( 1 ) ) {
			wp_schedule_single_event( time() + 60, self::GEO_BACKFILL_HOOK );
		}
	}

	/**
	 * IDs de lieux jamais géocodés (aucune tentative enregistrée = GEO_META_SRC absent).
	 *
	 * @return int[]
	 */
	private function ungeocoded_venue_ids( $limit ) {
		return get_posts( [
			'post_type'      => 'tribe_venue',
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[ 'key' => self::GEO_META_SRC, 'compare' => 'NOT EXISTS' ],
			],
		] );
	}

	/**
	 * Adresse normalisée d'un lieu (sert de clé de fraîcheur du cache).
	 * Le pays (défaut « France ») n'est ajouté que s'il y a au moins une autre
	 * composante : un lieu réduit au seul pays n'est pas une adresse géocodable
	 * (et ne doit pas produire une clé « France » bidon).
	 */
	private function venue_address_string( $venue_id ) {
		$parts = array_filter(
			[
				get_post_meta( $venue_id, '_VenueAddress', true ),
				get_post_meta( $venue_id, '_VenueZip', true ),
				get_post_meta( $venue_id, '_VenueCity', true ),
			],
			static function ( $p ) { return '' !== trim( (string) $p ); }
		);

		if ( empty( $parts ) ) {
			return '';
		}

		$parts[] = get_post_meta( $venue_id, '_VenueCountry', true ) ?: 'France';

		return trim( implode( ', ', array_map( 'trim', $parts ) ) );
	}

	/**
	 * Géocode un lieu en cascade, du plus précis au plus large, pour rester robuste
	 * aux adresses imparfaites (rue mal orthographiée, etc.) :
	 *   1. requête structurée (n° + rue + ville + CP + pays) → précision « rue »
	 *   2. « CP ville, pays » (repli centroïde de la commune)
	 *   3. « ville, pays »
	 *
	 * @return array{lat:float,lng:float}|null
	 */
	private function geocode( $venue_id ) {
		$street  = get_post_meta( $venue_id, '_VenueAddress', true );
		$city    = get_post_meta( $venue_id, '_VenueCity', true );
		$zip     = get_post_meta( $venue_id, '_VenueZip', true );
		$country = get_post_meta( $venue_id, '_VenueCountry', true ) ?: 'France';

		$attempts = [];
		if ( $street && $city ) {
			$attempts[] = [ 'street' => $street, 'city' => $city, 'postalcode' => $zip, 'country' => $country ];
		}
		if ( $zip && $city ) {
			$attempts[] = [ 'q' => trim( "$zip $city, $country" ) ];
		}
		if ( $city ) {
			$attempts[] = [ 'q' => trim( "$city, $country" ) ];
		}

		foreach ( $attempts as $params ) {
			$coords = $this->nominatim( array_filter( $params ) );
			if ( $coords ) {
				return $coords;
			}
		}

		return null;
	}

	/**
	 * Un appel Nominatim. Respecte la politique d'usage OSM : User-Agent identifiant
	 * + espacement >= 1,1 s entre deux appels (throttle auto sur microtime).
	 *
	 * @param array $params Paramètres de recherche (structurés ou `q`).
	 * @return array{lat:float,lng:float}|null
	 */
	private function nominatim( array $params ) {
		$elapsed = microtime( true ) - self::$last_nominatim;
		if ( self::$last_nominatim > 0.0 && $elapsed < 1.1 ) {
			usleep( (int) ( ( 1.1 - $elapsed ) * 1000000 ) );
		}
		self::$last_nominatim = microtime( true );

		$url = add_query_arg(
			array_map( 'rawurlencode', $params ) + [ 'format' => 'jsonv2', 'limit' => '1' ],
			'https://nominatim.openstreetmap.org/search'
		);

		$resp = wp_remote_get( $url, [
			'timeout' => 8,
			'headers' => [
				'User-Agent' => 'EditionsPassiflore/1.0 (+https://www.editions-passiflore.com)',
				'Accept'     => 'application/json',
			],
		] );

		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $body[0]['lat'] ) || empty( $body[0]['lon'] ) ) {
			return null;
		}

		return [
			'lat' => round( (float) $body[0]['lat'], 6 ),
			'lng' => round( (float) $body[0]['lon'], 6 ),
		];
	}
}

new Passiflore_Events_Map();
