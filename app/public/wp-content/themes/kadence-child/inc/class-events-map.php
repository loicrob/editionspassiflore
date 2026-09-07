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
	const GEO_META_PRECISION   = '_pf_venue_geo_precision'; // 'street' | 'city' | 'manual' ; absente = jamais géocodé/échec
	const GEO_PRECISION_STREET = 'street';
	const GEO_PRECISION_CITY   = 'city';
	const GEO_PRECISION_MANUAL = 'manual';
	const GEO_BACKFILL_HOOK    = 'pf_geocode_backfill';
	const GEO_MAX_PER_BACKFILL = 5;                  // lieux géocodés par passe de backfill (WP-Cron)
	const GEO_REPAIR_OPTION    = 'pf_venue_geo_repair_version'; // bump → rejoue la réparation ci-dessous
	const GEO_REPAIR_VERSION   = '1';

	/** @var float microtime du dernier appel Nominatim (throttle >= 1,1 s). */
	private static $last_nominatim = 0.0;

	/** @var array|null Cache mémoire des événements cartographiables. */
	private static $events_cache = null;

	public function __construct() {
		add_filter( 'tribe_get_single_option', [ $this, 'enable_view' ], 10, 3 );
		add_action( 'init', [ $this, 'register_view' ], 5 );
		add_action( 'init', [ $this, 'maybe_flush_rewrites' ], 20 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 20 );
		// Mini-carte mono-lieu de la fiche événement (section « Lieu »).
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_single' ], 20 );

		// Géocodage côté écriture (jamais pendant le rendu public) :
		//  - à l'enregistrement d'un lieu dans l'admin ;
		//  - backfill des lieux existants en tâche de fond (WP-Cron auto-drainant) ;
		//  - à la création d'un lieu en ligne depuis la fiche événement : ce chemin
		//    (Tribe__Events__Venue::create()) déclenche save_post_tribe_venue AVANT
		//    d'écrire l'adresse en meta (save_meta() tourne après), donc geocode_on_save
		//    y lirait une adresse vide ; on rebranche sur tribe_events_venue_created,
		//    déclenchée juste après save_meta() — cf. inc/venue-admin.php.
		add_action( 'save_post_tribe_venue', [ $this, 'geocode_on_save' ], 25 );
		add_action( 'tribe_events_venue_created', [ $this, 'geocode_on_save' ], 25 );
		add_action( 'admin_init',            [ $this, 'maybe_schedule_backfill' ] );
		add_action( 'admin_init',            [ $this, 'maybe_repair_empty_geo_src' ] );
		add_action( self::GEO_BACKFILL_HOOK, [ $this, 'run_backfill' ] );

		// Recherche sur la carte : renvoie des IDs d'événements classés (le front
		// filtre ses marqueurs). Même moteur que la recherche liste/globale.
		add_action( 'wp_ajax_pf_events_map_search',        [ $this, 'ajax_search' ] );
		add_action( 'wp_ajax_nopriv_pf_events_map_search', [ $this, 'ajax_search' ] );

		// Aperçu de géocodage pour l'admin (statut affiché à la frappe de
		// l'adresse, avant tout enregistrement) — cf. venue-geo-picker.js.
		// wp_ajax_ uniquement : appel réseau sortant, jamais nopriv.
		add_action( 'wp_ajax_pf_venue_geocode_preview', [ $this, 'ajax_geocode_preview' ] );
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
			'placeholderMobile' => __( 'Événement', 'kadence-child' ), // même placeholder mobile que la recherche liste.
			'markers'           => $this->build_markers(),
		] );
	}

	/* ─── Fiche événement : mini-carte mono-lieu ───────────────────── */

	/**
	 * Enqueue Leaflet + la mini-carte mono-lieu sur la fiche événement, si le
	 * lieu a des coordonnées en cache (géocodage côté écriture). Aucun appel
	 * réseau au rendu : lecture du cache post meta seule (cf. RGPD, CLAUDE.md).
	 */
	public function enqueue_single() {
		if ( ! is_singular( 'tribe_events' ) || ! function_exists( 'tribe_get_venue_id' ) ) {
			return;
		}
		$venue_id = tribe_get_venue_id( get_queried_object_id() );
		if ( ! $venue_id
			|| '' === get_post_meta( $venue_id, self::GEO_META_LAT, true )
			|| '' === get_post_meta( $venue_id, self::GEO_META_LNG, true ) ) {
			return; // pas de coordonnées → pas de carte (repli adresse seule)
		}

		$uri = get_stylesheet_directory_uri();
		$dir = get_stylesheet_directory();

		wp_enqueue_style( 'leaflet', $uri . '/assets/vendor/leaflet/leaflet.css', [], '1.9.4' );
		wp_enqueue_style(
			'pf-events-map',
			$uri . '/assets/css/events-map.css',
			[ 'leaflet', 'pf-events' ],
			filemtime( $dir . '/assets/css/events-map.css' )
		);
		wp_enqueue_script( 'leaflet', $uri . '/assets/vendor/leaflet/leaflet.js', [], '1.9.4', true );
		wp_enqueue_script(
			'pf-event-venue-map',
			$uri . '/assets/js/event-venue-map.js',
			[ 'leaflet' ],
			filemtime( $dir . '/assets/js/event-venue-map.js' ),
			true
		);
		wp_localize_script( 'pf-event-venue-map', 'PassifloreVenueMap', [
			'tileUrl'     => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
			'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
		] );
	}

	/**
	 * Section « Lieu » de la fiche événement : bloc adresse + mini-carte OSM
	 * mono-marqueur (si coordonnées en cache) + lien « Y aller ». Le nom du lieu
	 * est omis quand il duplique l'adresse (flag _VenueNameIsAddress).
	 *
	 * @param int $event_id
	 * @return string HTML, ou '' si aucun lieu.
	 */
	public static function render_single_venue_map( $event_id ) {
		if ( ! function_exists( 'tribe_get_venue_id' ) ) {
			return '';
		}
		$venue_id = tribe_get_venue_id( $event_id );
		if ( ! $venue_id ) {
			return '';
		}

		$name            = tribe_get_venue( $event_id );
		$name_is_address = get_post_meta( $venue_id, '_VenueNameIsAddress', true );
		$address         = tribe_get_address( $event_id );
		$zip             = tribe_get_zip( $event_id );
		$city            = tribe_get_city( $event_id );
		$dept            = get_post_meta( $venue_id, '_VenueDepartement', true );
		$region          = get_post_meta( $venue_id, '_VenueRegion', true );

		$lat        = get_post_meta( $venue_id, self::GEO_META_LAT, true );
		$lng        = get_post_meta( $venue_id, self::GEO_META_LNG, true );
		$has_coords = ( '' !== $lat && '' !== $lng );

		$lines = [];
		if ( $name && ! $name_is_address ) {
			$lines[] = '<span class="pf-event-venue__name">' . esc_html( $name ) . '</span>';
		}
		if ( $address ) {
			$lines[] = esc_html( $address );
		}
		$zip_city = trim( $zip . ' ' . $city );
		if ( '' !== $zip_city ) {
			$lines[] = esc_html( $zip_city );
		}
		$area = array_filter( [ $dept, $region ] );
		if ( $area ) {
			$lines[] = '<span class="pf-event-venue__area">' . esc_html( implode( ' · ', $area ) ) . '</span>';
		}

		if ( empty( $lines ) && ! $has_coords ) {
			return '';
		}

		$label = $name ?: $city;

		ob_start();
		echo '<div class="pf-event-venue">';

		// .pf-event-venue__info regroupe adresse + bouton : sur ordinateur/tablette, la carte
		// (colonne droite) doit se centrer verticalement par rapport à CE BLOC dans son
		// ensemble — pas à l'adresse et au bouton indépendamment (ce que ferait une grille
		// CSS à 2 rangées, en gonflant chaque rangée à la hauteur de la carte et en écartant
		// les deux au lieu de les garder collés). Sur mobile, ce wrapper redevient inerte
		// (display:contents, event-single.css) : adresse/carte/bouton restent 3 éléments
		// indépendants réordonnables (ordre visuel adresse → carte → bouton, via `order`).
		echo '<div class="pf-event-venue__info">';

		if ( $lines ) {
			echo '<address class="pf-event-venue__address">' . implode( '<br>', $lines ) . '</address>';
		}

		if ( $has_coords ) {
			// « Voir sur Google Maps » : recherche par nom de lieu + adresse + ville (repli
			// sur les coordonnées si rien n'est renseigné — lien sortant, au clic). Nom omis
			// quand il duplique l'adresse ($name_is_address) : elle est déjà dans la requête,
			// pas la peine de la répéter deux fois.
			$search_name = ( $name && ! $name_is_address ) ? $name : '';
			$search_q    = trim( implode( ' ', array_filter( [ $search_name, $address, $city ] ) ) );
			if ( '' === $search_q ) $search_q = $lat . ',' . $lng;
			$maps_url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $search_q );
			echo '<a class="button pf-btn pf-btn--outline pf-btn--sm pf-event-venue__go" href="' . esc_url( $maps_url )
				. '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google Maps', 'kadence-child' ) . pf_new_window_note() . '</a>';
		}

		echo '</div>'; // .pf-event-venue__info

		if ( $has_coords ) {
			echo '<div id="pf-event-venue-map" class="pf-event-venue__map"'
				. ' data-lat="' . esc_attr( $lat ) . '" data-lng="' . esc_attr( $lng ) . '"'
				. ' data-label="' . esc_attr( $label ) . '"'
				. ' aria-label="' . esc_attr( sprintf( 'Carte du lieu : %s', $label ) ) . '"></div>';
		}

		echo '</div>';
		return ob_get_clean();
	}

	/* ─── Recherche sur la carte ───────────────────────────────────── */

	/**
	 * Barre de recherche de la vue carte. Rendue dans le `__left` du header
	 * (cf. tribe/.../components/header.php). Reprend le composant visuel
	 * `.pf-search--cat` (comme la recherche liste) mais avec des classes propres
	 * (`.pf-map-search*`) pour que le contrôleur de la liste (events-search.js,
	 * qui cible `.pf-ev-search-input`) ne la détourne pas.
	 */
	public static function render_search_bar() {
		$svg_loupe = '<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>';

		ob_start();
		?>
		<div class="pf-map-search">
			<div class="pf-search pf-search--cat">
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
		// Endpoint public en lecture seule : pas de nonce (aucun enjeu CSRF, et
		// un nonce provoquerait un 403 sur un onglet ancien). Cf. recherche globale.

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

			$evs      = [];
			$lieu_row = '';
			foreach ( $venue_events as $event ) {
				$norm = Passiflore_Event_Tiles::normalize_event( (int) $event->ID );
				if ( '' === $lieu_row ) {
					// Ligne « Lieu » du marqueur (un seul événement affiché → pas d'en-tête
					// commun) : recalculée ici plutôt que dérivée de tribe_get_venue()/city()
					// ci-dessous, qui n'incluent ni le département ni la même forme
					// d'échappement que le rendu accueil (cf. pf_get_event_venue_parts()).
					$lieu_row = pf_render_lieu_meta_row(
						(string) ( $norm['venue_name'] ?? '' ),
						(string) ( $norm['venue_city'] ?? '' )
					);
				}
				$evs[] = [
					'id'   => (int) $event->ID, // pour le filtrage par la recherche carte
					// Card d'événement pré-rendue côté serveur : composant global partagé
					// avec l'accueil/fiche auteur (Passiflore_Event_Tiles::render_tile).
					// Ligne « Lieu » omise (show_lieu=false) : le lieu est porté par l'en-tête
					// de l'infobulle à plusieurs événements, ou ré-injectée par le JS
					// (lieuRow) quand un seul événement est affiché et que l'en-tête disparaît.
					'html' => Passiflore_Event_Tiles::render_tile( $norm, false ),
				];
			}

			$markers[] = [
				'lat'     => $coords['lat'],
				'lng'     => $coords['lng'],
				'venue'   => html_entity_decode( tribe_get_venue( $venue_id ), ENT_QUOTES ),
				'city'    => html_entity_decode( tribe_get_city( $venue_id ), ENT_QUOTES ),
				'events'  => $evs,
				'lieuRow' => $lieu_row,
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
		// save_post peut se déclencher plusieurs fois par requête : une seule passe
		// par (lieu, adresse vue à cet instant) — PAS juste par lieu. Un lieu créé
		// en ligne depuis la fiche événement (Tribe__Events__Venue::create())
		// déclenche save_post_tribe_venue AVANT que save_meta() n'écrive
		// _VenueAddress/_VenueZip/_VenueCity, puis tribe_events_venue_created
		// juste après : sans l'adresse dans la clé, la 1re passe (sans adresse)
		// poserait déjà $done[$venue_id] et la 2e (avec la vraie adresse) serait
		// annulée à tort — exactement le rattrapage qu'on veut ici.
		static $done = [];
		$key = $venue_id . '|' . self::venue_address_string( $venue_id );
		if ( isset( $done[ $key ] ) ) {
			return;
		}
		$done[ $key ] = true;

		$this->geocode_venue( (int) $venue_id );
	}

	/**
	 * Réparation unique : avant le correctif ci-dessus, les lieux créés en ligne
	 * depuis la fiche événement se voyaient stamper GEO_META_SRC à '' (adresse
	 * vue avant save_meta()) sans jamais être repris — ungeocoded_venue_ids()
	 * cherche NOT EXISTS, or la clé existe (valeur ''). On supprime toutes les
	 * valeurs '' : les lieux réellement sans adresse re-stampent '' en une passe
	 * de backfill et ressortent du lot (terminaison garantie) ; les autres sont
	 * enfin géocodés. Auto-cicatrisant, même motif que pf_carte_rw_version.
	 */
	public function maybe_repair_empty_geo_src() {
		if ( get_option( self::GEO_REPAIR_OPTION ) === self::GEO_REPAIR_VERSION ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => self::GEO_META_SRC, 'meta_value' => '' ] );
		update_option( self::GEO_REPAIR_OPTION, self::GEO_REPAIR_VERSION );
	}

	/**
	 * Géocode un lieu et met le résultat en cache dans ses post meta. Stampe
	 * toujours l'adresse tentée (GEO_META_SRC), même en cas d'échec, pour éviter
	 * que le backfill ne repasse indéfiniment sur les lieux non géocodables.
	 *
	 * @return array{lat:float,lng:float}|null
	 */
	private function geocode_venue( $venue_id ) {
		$addr = self::venue_address_string( $venue_id );

		// Coordonnées ajustées à la main (carte admin) : on ne les retouche
		// jamais tant que le drapeau tient, mais on stampe quand même l'adresse
		// courante pour que le backfill ne considère pas ce lieu comme non géocodé.
		if ( self::GEO_PRECISION_MANUAL === get_post_meta( $venue_id, self::GEO_META_PRECISION, true ) ) {
			update_post_meta( $venue_id, self::GEO_META_SRC, $addr );
			$lat = get_post_meta( $venue_id, self::GEO_META_LAT, true );
			$lng = get_post_meta( $venue_id, self::GEO_META_LNG, true );
			return ( '' !== $lat && '' !== $lng ) ? [ 'lat' => (float) $lat, 'lng' => (float) $lng ] : null;
		}

		$src = get_post_meta( $venue_id, self::GEO_META_SRC, true );
		$lat = get_post_meta( $venue_id, self::GEO_META_LAT, true );

		// Déjà géocodé avec succès pour cette adresse → rien à faire.
		if ( $src === $addr && '' !== $lat ) {
			return [ 'lat' => (float) $lat, 'lng' => (float) get_post_meta( $venue_id, self::GEO_META_LNG, true ) ];
		}

		// Lieu sans adresse exploitable : on marque « tenté » et on sort.
		if ( '' === $addr ) {
			update_post_meta( $venue_id, self::GEO_META_SRC, '' );
			delete_post_meta( $venue_id, self::GEO_META_LAT );
			delete_post_meta( $venue_id, self::GEO_META_LNG );
			delete_post_meta( $venue_id, self::GEO_META_PRECISION );
			return null;
		}

		$result = $this->geocode( $venue_id );

		// Adresse tentée mémorisée dans tous les cas (anti-boucle du backfill).
		update_post_meta( $venue_id, self::GEO_META_SRC, $addr );

		if ( ! $result ) {
			delete_post_meta( $venue_id, self::GEO_META_LAT );
			delete_post_meta( $venue_id, self::GEO_META_LNG );
			delete_post_meta( $venue_id, self::GEO_META_PRECISION );
			return null;
		}

		update_post_meta( $venue_id, self::GEO_META_LAT, $result['lat'] );
		update_post_meta( $venue_id, self::GEO_META_LNG, $result['lng'] );
		update_post_meta( $venue_id, self::GEO_META_PRECISION, $result['precision'] );

		return $result;
	}

	/**
	 * Fixe des coordonnées manuelles pour un lieu (repère déplacé sur la carte
	 * admin) : precision passe à 'manual', geocode_venue() ne les retouche plus
	 * tant que ce drapeau tient (cf. son garde en tête de fonction).
	 */
	public static function set_manual_coords( $venue_id, $lat, $lng ) {
		$lat = (float) $lat;
		$lng = (float) $lng;
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return;
		}
		update_post_meta( $venue_id, self::GEO_META_LAT, $lat );
		update_post_meta( $venue_id, self::GEO_META_LNG, $lng );
		update_post_meta( $venue_id, self::GEO_META_PRECISION, self::GEO_PRECISION_MANUAL );
	}

	/**
	 * Persiste les coordonnées affichées par l'aperçu admin (venue-geo-picker.js)
	 * quand elles correspondent encore à l'adresse enregistrée (cf.
	 * pf_venue_geo_key(), inc/venue-admin.php) : l'aperçu à la frappe et le
	 * géocodage à l'enregistrement peuvent interroger des variantes différentes
	 * de la même adresse (ex. avec/sans CP déduit) et donc retomber sur des
	 * coordonnées différentes — cf. CLAUDE.md. En stampant GEO_META_SRC à
	 * l'adresse courante, geocode_on_save() @25 (qui suit dans la même requête)
	 * voit un cache hit et ne relance aucun appel réseau.
	 */
	public static function set_geocoded_coords( $venue_id, $lat, $lng, $precision ) {
		$lat = (float) $lat;
		$lng = (float) $lng;
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return;
		}
		if ( ! in_array( $precision, [ self::GEO_PRECISION_STREET, self::GEO_PRECISION_CITY ], true ) ) {
			return;
		}
		update_post_meta( $venue_id, self::GEO_META_LAT, $lat );
		update_post_meta( $venue_id, self::GEO_META_LNG, $lng );
		update_post_meta( $venue_id, self::GEO_META_PRECISION, $precision );
		update_post_meta( $venue_id, self::GEO_META_SRC, self::venue_address_string( $venue_id ) );
	}

	/**
	 * Rend la main au géocodage automatique : la passe @25 qui suit dans la même
	 * requête (geocode_on_save) regéocode alors l'adresse courante normalement.
	 */
	public static function clear_manual_coords( $venue_id ) {
		if ( self::GEO_PRECISION_MANUAL !== get_post_meta( $venue_id, self::GEO_META_PRECISION, true ) ) {
			return;
		}
		delete_post_meta( $venue_id, self::GEO_META_PRECISION );
		delete_post_meta( $venue_id, self::GEO_META_SRC );
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
	public static function venue_address_string( $venue_id ) {
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
	 * Géocode un lieu (lit ses post meta) en délégant à geocode_parts().
	 *
	 * @return array{lat:float,lng:float,precision:string,label:string}|null
	 */
	private function geocode( $venue_id ) {
		$street  = get_post_meta( $venue_id, '_VenueAddress', true );
		$city    = get_post_meta( $venue_id, '_VenueCity', true );
		$zip     = get_post_meta( $venue_id, '_VenueZip', true );
		$country = get_post_meta( $venue_id, '_VenueCountry', true ) ?: 'France';

		return self::geocode_parts( $street, $city, $zip, $country );
	}

	/**
	 * Géocode une adresse en cascade, du plus précis au plus large, pour rester
	 * robuste aux adresses imparfaites (rue mal orthographiée, etc.) :
	 *   1. requête structurée (n° + rue + ville + CP + pays)
	 *   2. arrondissement (Paris/Lyon/Marseille) si le CP en désigne un —
	 *      requête structurée city="{Ville} {N}e Arrondissement", garde
	 *      d'acceptation sur le CP de la réponse (cf. CLAUDE.md : "75011 Paris"
	 *      en texte libre déraille sur un hameau du Gers)
	 *   3. commune, par sélection de candidats — city=/country=/limit=25,
	 *      désambiguïsation des homonymes via pf_venue_pick_commune_candidate()
	 *      (inc/venue-admin.php), qui filtre par type d'entité puis par
	 *      département/région déduits du CP saisi
	 *   4. « ville, pays » en texte libre (filet : graphie non résolue par la
	 *      requête structurée, ou aucun candidat de type commune)
	 *
	 * Le CP n'est JAMAIS injecté tel quel dans une requête en texte libre
	 * (Nominatim déraille dessus, ex. "40200 Mimizan" → un artisan plutôt que
	 * le centre-ville) — il ne sert plus qu'à choisir/désambiguïser un résultat
	 * structuré déjà obtenu.
	 *
	 * Statique et indépendante de tout lieu enregistré : réutilisée par l'aperçu
	 * AJAX admin (ajax_geocode_preview), qui géocode une adresse en cours de
	 * saisie, pas encore persistée.
	 *
	 * Précision : 'street' seulement si la 1re tentative (adresse structurée)
	 * aboutit sur une entité de rang suffisant (place_rank Nominatim >= 26, rue
	 * ou bâtiment) ; 'city' sinon — repli sur le centroïde de la commune, que ce
	 * soit via les tentatives 2/3/4 ou une 1re tentative retombée sur une entité
	 * administrative plus large.
	 *
	 * @return array{lat:float,lng:float,precision:string,label:string,address:array,commune_unique:bool}|null
	 */
	public static function geocode_parts( $street, $city, $zip, $country ) {
		$country = $country ?: 'France';

		$attempts = [];
		// Rue + (ville OU CP) : élargi par rapport à une simple rue+ville pour
		// couvrir la saisie « rue + CP, ville pas encore renseignée » — sans quoi
		// aucune requête structurée ne part tant que la ville est vide, et la
		// ville ne pourrait jamais être déduite par pf_venue_admin_fields_from_geocode().
		if ( $street && ( $city || $zip ) ) {
			$attempts[] = [ 'street' => $street, 'city' => $city, 'postalcode' => $zip, 'country' => $country ];
		}
		// Arrondissement : clé pf_arrond retirée avant l'appel réseau (cf. boucle
		// ci-dessous), sert seulement à distinguer cette tentative de la commune
		// générique qui suit (les deux portent 'city').
		$arrond_city = function_exists( 'pf_venue_arrondissement_city' ) ? pf_venue_arrondissement_city( (string) $city, (string) $zip ) : '';
		if ( $arrond_city ) {
			$attempts[] = [ 'pf_arrond' => 1, 'city' => $arrond_city, 'country' => $country ];
		}
		if ( $city ) {
			$attempts[] = [ 'city' => $city, 'country' => $country, 'limit' => 25 ];
		}
		if ( $city ) {
			$attempts[] = [ 'q' => trim( "$city, $country" ) ];
		}

		foreach ( $attempts as $params ) {
			// Drapeaux dérivés de la FORME de la requête (jamais de sa position
			// dans $attempts, cf. commentaire ci-dessus) : selon l'adresse en
			// base, certaines tentatives sont absentes de la liste.
			$is_street_attempt  = isset( $params['street'] );
			$is_arrond_attempt  = ! empty( $params['pf_arrond'] );
			$is_commune_attempt = ! $is_street_attempt && ! $is_arrond_attempt && isset( $params['city'] );

			$call_params = $params;
			unset( $call_params['pf_arrond'] );

			$results = self::nominatim( array_filter( $call_params ) );
			if ( ! $results ) {
				continue;
			}

			$picked         = null;
			$commune_unique = false;

			if ( $is_arrond_attempt ) {
				// Garde d'acceptation auto-vérifiante : le CP de la réponse doit
				// correspondre exactement au CP saisi (cf. CLAUDE.md, ordinal
				// "1e" mal formé → hameau du Gers, attrapé ici).
				foreach ( $results as $r ) {
					if ( ( $r['address']['postcode'] ?? '' ) === $zip ) {
						$picked = $r;
						break;
					}
				}
			} elseif ( $is_commune_attempt && function_exists( 'pf_venue_pick_commune_candidate' ) ) {
				$picked = pf_venue_pick_commune_candidate( $results, (string) $city, (string) $zip );
				if ( $picked ) {
					$commune_unique = ! empty( $picked['pf_commune_unique'] );
				}
			} else {
				$picked = $results[0];
			}

			if ( ! $picked ) {
				continue;
			}

			$precision = ( $is_street_attempt && $picked['place_rank'] >= 26 ) ? self::GEO_PRECISION_STREET : self::GEO_PRECISION_CITY;

			return [
				'lat'            => $picked['lat'],
				'lng'            => $picked['lng'],
				'precision'      => $precision,
				'label'          => $picked['display_name'] ?: trim( implode( ', ', array_filter( [ $street, $zip, $city ] ) ) ),
				'address'        => $picked['address'] ?? [],
				'commune_unique' => $commune_unique,
			];
		}

		return null;
	}

	/**
	 * Un appel Nominatim, mis en cache 1 h (clé = paramètres) : l'aperçu à la
	 * frappe (admin) et le géocodage à l'enregistrement portent souvent la même
	 * adresse dans la même minute — sans cache on double les appels OSM et on
	 * rallonge la sauvegarde du throttle cumulé. Un échec est mis en cache aussi
	 * (sentinelle '' ≠ false de get_transient) pour ne pas re-frapper l'API en
	 * boucle sur une adresse invalide.
	 *
	 * @param array $params Paramètres de recherche (structurés ou `q`).
	 * @return array[] Liste de résultats normalisés (vide si échec/aucun résultat).
	 */
	private static function nominatim( array $params ): array {
		$cache_key = 'pf_nomi_' . md5( wp_json_encode( $params ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached ?: [];
		}

		$result = self::nominatim_request( $params );

		set_transient( $cache_key, $result ?: '', HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Respecte la politique d'usage OSM : User-Agent identifiant + espacement
	 * >= 1,1 s entre deux appels (throttle auto sur microtime).
	 *
	 * @return array[] Résultats normalisés (lat/lng/place_rank/display_name/
	 *                 addresstype/address), dans l'ordre de classement de
	 *                 Nominatim. Vide si l'appel échoue ou ne renvoie rien.
	 */
	private static function nominatim_request( array $params ): array {
		$elapsed = microtime( true ) - self::$last_nominatim;
		if ( self::$last_nominatim > 0.0 && $elapsed < 1.1 ) {
			usleep( (int) ( ( 1.1 - $elapsed ) * 1000000 ) );
		}
		self::$last_nominatim = microtime( true );

		$url = add_query_arg(
			array_map( 'rawurlencode', $params ) + [ 'format' => 'jsonv2', 'limit' => '1', 'addressdetails' => '1', 'accept-language' => 'fr' ],
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
			return [];
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) ) {
			return [];
		}

		$results = [];
		foreach ( $body as $entry ) {
			if ( empty( $entry['lat'] ) || empty( $entry['lon'] ) ) {
				continue;
			}
			$results[] = [
				'lat'          => round( (float) $entry['lat'], 6 ),
				'lng'          => round( (float) $entry['lon'], 6 ),
				'place_rank'   => isset( $entry['place_rank'] ) ? (int) $entry['place_rank'] : 0,
				'display_name' => isset( $entry['display_name'] ) ? (string) $entry['display_name'] : '',
				'addresstype'  => isset( $entry['addresstype'] ) ? (string) $entry['addresstype'] : '',
				'address'      => is_array( $entry['address'] ?? null ) ? $entry['address'] : [],
			];
		}

		return $results;
	}

	/**
	 * Aperçu de géocodage pour l'admin (statut affiché à la frappe de l'adresse,
	 * avant tout enregistrement) — cf. assets/js/venue-geo-picker.js. N'écrit
	 * aucune meta, se contente d'interroger geocode_parts().
	 */
	public function ajax_geocode_preview() {
		check_ajax_referer( 'pf_venue_geo', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [], 403 );
		}

		$street  = isset( $_POST['street'] )  ? sanitize_text_field( wp_unslash( $_POST['street'] ) )  : '';
		$city    = isset( $_POST['city'] )    ? sanitize_text_field( wp_unslash( $_POST['city'] ) )    : '';
		$zip     = isset( $_POST['zip'] )     ? sanitize_text_field( wp_unslash( $_POST['zip'] ) )     : '';
		$country = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '';

		$result = self::geocode_parts( $street, $city, $zip, $country );

		if ( ! $result ) {
			wp_send_json_success( [ 'found' => false ] );
		}

		wp_send_json_success( [
			'found'     => true,
			'precision' => $result['precision'],
			'lat'       => $result['lat'],
			'lng'       => $result['lng'],
			'label'     => $result['label'],
			'fields'    => pf_venue_admin_fields_from_geocode( $result['address'] ?? [], $result['precision'], $zip, ! empty( $result['commune_unique'] ) ),
		] );
	}
}

new Passiflore_Events_Map();
