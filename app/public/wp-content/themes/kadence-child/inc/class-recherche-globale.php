<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Passiflore_Recherche_Globale
 *
 * Barre de recherche globale intégrée dans le header Kadence. L'élément se
 * place après la nav dans la section centrale du header builder. Un clic sur la
 * loupe cache la nav et étend la barre sur toute la section ; les résultats
 * (livres, auteurs, événements) s'affichent en panneau fixe sous le header.
 *
 * Elle cherche TOUJOURS dans tout : il n'y a pas de sélecteur de type (le
 * filtrage par catégorie est l'affaire des barres locales de /catalogue,
 * /auteurs et /evenements).
 *
 * Chaque section est servie par lots de PAGE_SIZE, suivis d'un bouton
 * « + de résultats » quand il en reste — lequel rappelle la MÊME action AJAX
 * avec `section` + `offset`.
 *
 * Action AJAX : pf_global_search
 */
class Passiflore_Recherche_Globale {

	/**
	 * Taille d'un lot de résultats, PAR SECTION. Ne vit qu'ici : le client ne
	 * fait aucune arithmétique d'offset, il relit `next_offset` dans la réponse.
	 * Même mécanique que Passiflore_Events_Search (un offset entier, classement
	 * complet recalculé à chaque appel puis tranché — cf. inc/search.php).
	 */
	const PAGE_SIZE = 4;

	/**
	 * Nombre de livres servant d'AMORCE au recoupement vers les auteurs et les
	 * événements (cf. section_pools()). DÉLIBÉRÉMENT découplé de PAGE_SIZE et
	 * FIGÉ : si l'amorce suivait la pagination des livres, cliquer
	 * « + de résultats » dans Livres ferait bouger les sections Auteurs et
	 * Événements sous les yeux du visiteur, et leurs propres lots suivants ne se
	 * raccorderaient plus au premier (doublons et oublis). Valeur historique
	 * (ancien plafond de la section Livres) → recoupement inchangé en nombre ;
	 * son CONTENU, lui, se restreint désormais aux livres éligibles, ce qui est
	 * l'objet même de pf_search_products_ranked_global().
	 */
	const SEED_SIZE = 6;

	public function __construct() {
		add_filter( 'wp_nav_menu_items',                  [ $this, 'inject_into_nav' ], 10, 2 );
		add_action( 'kadence_render_mobile_header_column', [ $this, 'inject_mobile_header' ], 5, 2 );
		add_action( 'wp_enqueue_scripts',                 [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_pf_global_search',           [ $this, 'ajax_search' ] );
		add_action( 'wp_ajax_nopriv_pf_global_search',    [ $this, 'ajax_search' ] );
	}

	/* ─── Injection dans le menu principal (desktop) ────────────── */

	public function inject_into_nav( $items, $args ) {
		if ( ! isset( $args->theme_location ) || $args->theme_location !== 'primary' ) {
			return $items;
		}
		// Cibler uniquement la nav desktop (#primary-menu). Le tiroir mobile
		// (sidenav) retombe sur le menu primary mais avec menu_id 'mobile-menu' :
		// on ne veut plus y injecter la recherche (elle vit dans #mobile-header).
		if ( ! isset( $args->menu_id ) || $args->menu_id !== 'primary-menu' ) {
			return $items;
		}
		return $items . '<li class="menu-item pf-gsearch-nav-item">' . $this->render() . '</li>';
	}

	/* ─── Injection dans le header mobile (tablette + mobile) ────── */

	public function inject_mobile_header( $row, $column ) {
		if ( $row !== 'main' || $column !== 'right' ) {
			return;
		}
		// Priorité 5 (< 10 du rendu du hamburger) → la recherche s'affiche
		// avant le hamburger, donc à sa gauche.
		echo '<div class="site-header-item pf-gsearch-mobile-item">' . $this->render() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/* ─── Assets ─────────────────────────────────────────────────── */

	public function enqueue_assets() {
		$uri = get_stylesheet_directory_uri();
		$dir = get_stylesheet_directory();
		wp_enqueue_style(
			'pf-gsearch',
			$uri . '/assets/css/recherche-globale.css',
			[],
			filemtime( $dir . '/assets/css/recherche-globale.css' )
		);
		wp_enqueue_script(
			'pf-gsearch',
			$uri . '/assets/js/recherche-globale.js',
			[],
			filemtime( $dir . '/assets/js/recherche-globale.js' ),
			true
		);
	}

	/* ─── Rendu de la barre ──────────────────────────────────────── */

	public function render() {
		// Placeholder long (desktop) / court (mobile, ≤768px — cf. recherche-globale.js).
		// UN SEUL couple : la recherche globale cherche toujours dans TOUT, il n'y
		// a plus de sélecteur de type dont il faudrait suivre l'état.
		// ⚠️ Ces textes ne sont PAS partagés : les trois barres locales
		// (/catalogue, /auteurs, /evenements liste + carte) portent les leurs, en
		// dur dans leurs propres fichiers.
		$placeholders = [
			'long'  => __( 'Rechercher un livre, thème, auteur, ISBN…', 'kadence-child' ),
			'short' => __( 'Livre, thème, auteur, ISBN…', 'kadence-child' ),
		];

		$config = [
			'ajax_url'     => admin_url( 'admin-ajax.php' ),
			'placeholders' => $placeholders,
		];

		$svg_loupe = '<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>';
		$svg_close = '<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

		ob_start();
		?>
		<div class="pf-gsearch" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<button class="pf-gsearch-btn" type="button"
				aria-label="<?php esc_attr_e( 'Rechercher sur le site', 'kadence-child' ); ?>"
				aria-expanded="false">
				<span class="pf-gsearch-btn-loupe"><?php echo $svg_loupe; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<span class="pf-gsearch-btn-close"><?php echo $svg_close; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			</button>
			<div class="pf-gsearch-panel">
				<div class="pf-gsearch-bar" role="search">
					<div class="pf-search pf-search--sm">
						<?php echo $svg_loupe; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<input
							type="search"
							class="pf-search-input pf-gsearch-input"
							placeholder="<?php echo esc_attr( $placeholders['long'] ); ?>"
							autocomplete="off"
							aria-label="<?php esc_attr_e( 'Recherche globale', 'kadence-child' ); ?>"
						>
						<button class="pf-search-clear pf-gsearch-clear" type="button"
							aria-label="<?php esc_attr_e( 'Effacer', 'kadence-child' ); ?>">×</button>
					</div>
				</div>
				<?php
				// aria-atomic="false" : role="status" est ATOMIQUE par défaut. Sans
				// cette précision, chaque lot ajouté par « + de résultats » ferait
				// relire tout le panneau au lecteur d'écran. Le remplacement complet
				// (nouvelle recherche) n'y perd rien : tous ses enfants sont alors
				// des ajouts, et aria-relevant vaut « additions text » par défaut.
				?>
				<div class="pf-gsearch-results" role="status" aria-live="polite" aria-atomic="false"></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ─── AJAX ───────────────────────────────────────────────────── */

	public function ajax_search() {
		// Pas de nonce : endpoint public, en lecture seule, renvoyant des
		// données publiques (aucun enjeu CSRF). Un nonce n'apporterait rien ici
		// et provoquerait un 403 sur un onglet resté ouvert au-delà de sa durée
		// de vie (12–24 h) — voir la recherche auteurs, mêmes raisons.

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		// ⚠️ « section » n'est PAS un filtre de type : le sélecteur
		// Tout/Livres/Auteurs/Événements a été retiré, la recherche globale
		// cherche toujours dans tout. Non vide = demande du LOT SUIVANT de cette
		// seule section (bouton « + de résultats »). L'espace de valeurs étant le
		// même que celui de l'ancien filtre, ne pas confondre les deux.
		$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
		$offset  = isset( $_POST['offset'] )  ? max( 0, (int) $_POST['offset'] )                : 0;

		if ( mb_strlen( trim( $search ) ) < 2 ) {
			wp_send_json_success( [ 'html' => '', 'has_more' => false, 'next_offset' => 0 ] );
			return;
		}

		if ( $section !== '' ) {
			$this->send_more( $search, $section, $offset );
			return;
		}

		$labels = $this->sections();
		$html   = '';
		foreach ( $this->section_pools( $search ) as $key => $pool ) {
			$html .= $this->render_group(
				$labels[ $key ],
				$this->render_items( $key, array_slice( $pool, 0, self::PAGE_SIZE ) ),
				$key,
				count( $pool ) > self::PAGE_SIZE ? self::PAGE_SIZE : 0
			);
		}

		if ( $html === '' ) {
			$html = '<p class="pf-empty">'
				. esc_html__( 'Aucun résultat ne correspond à votre recherche.', 'kadence-child' )
				. '</p>';
		}

		wp_send_json_success( [ 'html' => $html ] );
	}

	/**
	 * Sections, dans l'ordre d'affichage. La clé EST la valeur du paramètre
	 * AJAX « section » (et l'attribut data-section du bouton).
	 */
	private function sections() {
		return [
			'livres'     => __( 'Livres', 'kadence-child' ),
			'auteurs'    => __( 'Auteurs', 'kadence-child' ),
			'evenements' => __( 'Événements à venir', 'kadence-child' ),
		];
	}

	/**
	 * Lot suivant d'UNE section : les items seuls (le groupe et son libellé sont
	 * déjà à l'écran, le client insère devant le bouton). `next_offset` vaut 0
	 * quand la section est épuisée — le client retire alors le bouton, sans
	 * jamais calculer d'offset lui-même.
	 */
	private function send_more( $search, $section, $offset ) {
		$labels = $this->sections();
		if ( ! isset( $labels[ $section ] ) ) {
			wp_send_json_success( [ 'html' => '', 'has_more' => false, 'next_offset' => 0 ] );
			return;
		}

		$pool = $this->section_pools( $search, $section )[ $section ];

		// Un offset au-delà de la fin (requête rejouée, données modifiées entre
		// deux lots) donne un lot vide : rien à rendre, le bouton disparaît.
		$page = array_slice( $pool, $offset, self::PAGE_SIZE );
		$next = $offset + count( $page );
		$more = $next < count( $pool );

		wp_send_json_success( [
			'html'        => $this->render_items( $section, $page ),
			'has_more'    => $more,
			'next_offset' => $more ? $next : 0,
		] );
	}

	/**
	 * Listes COMPLÈTES et classées des sections demandées ($only vide = les trois) ;
	 * le découpage en lots est l'affaire de l'appelant.
	 *
	 * Les livres correspondants servent DEUX fois : ils remplissent la section
	 * Livres ET font remonter leurs auteurs et leurs événements liés dans les
	 * deux autres (utile en recherche ISBN : l'index auteur/événement est
	 * construit à partir des TITRES de livres, pas des ISBN, donc son propre
	 * matching flou ne les ferait pas remonter).
	 *
	 * ⚠️ L'amorce ($seed) est reconstruite ICI, à l'identique, par CHAQUE requête
	 * — premier rendu comme lot suivant, et même quand on ne pagine que les
	 * auteurs ou les événements. Ne PAS « optimiser » en sautant la requête
	 * livres dans ces cas : la liste d'auteurs obtenue différerait de celle du
	 * premier lot, donc doublons et oublis d'un lot à l'autre. C'est le prix du
	 * sans-état (aucun curseur serveur), et il est négligeable.
	 *
	 * $only, en revanche, évite le travail inutile : sans lui, paginer les
	 * auteurs déclencherait un classement de tous les événements pour rien.
	 *
	 * L'amorce étant tirée de la liste FILTRÉE (cf. pf_search_products_ranked_global()),
	 * un livre épuisé ou masqué ne fait plus remonter ni ses auteurs ni ses
	 * événements. Seul ce RENFORT disparaît : un auteur dont le nom correspond à
	 * la requête reste trouvé par l'index auteur, et un événement qui correspond
	 * par son propre texte (titre, participant, lieu) reste trouvé par
	 * pf_search_events_ranked(). Sans quoi chercher « Marc Large » ne le
	 * trouverait plus dès qu'un seul de ses livres serait épuisé.
	 *
	 * Le classement est déterministe à requête égale (usort stable en PHP 8 ;
	 * pour les événements, `upcoming_only` réduit le tri par proximité à un tri
	 * par date de début croissante, indépendant de l'instant de la requête).
	 * Seul écart possible, assumé : un événement dont le début est franchi ENTRE
	 * deux lots sort de la liste et la décale d'un cran — même exposition que
	 * Passiflore_Events_Search.
	 *
	 * @return array<string, int[]|WP_Term[]>
	 */
	private function section_pools( $search, $only = '' ) {
		$livres = pf_search_products_ranked_global( $search );
		$seed   = array_slice( $livres, 0, self::SEED_SIZE );

		$pools = [];
		if ( $only === '' || $only === 'livres' )     $pools['livres']     = $livres;
		if ( $only === '' || $only === 'auteurs' )    $pools['auteurs']    = $this->auteur_terms( $search, $seed );
		if ( $only === '' || $only === 'evenements' ) $pools['evenements'] = $this->evenement_ids( $search, $seed );
		return $pools;
	}

	/** Rendu des items d'un lot, selon la section. */
	private function render_items( $section, array $slice ) {
		if ( empty( $slice ) )        return '';
		if ( $section === 'livres' )  return $this->items_livres( $slice );
		if ( $section === 'auteurs' ) return $this->items_auteurs( $slice );
		return $this->items_evenements( $slice );
	}

	/* ─── Livres ─────────────────────────────────────────────────── */

	private function items_livres( array $ids ) {
		$items = '';
		foreach ( $ids as $id ) {
			$img_id = (int) get_post_thumbnail_id( $id );
			$title  = apply_filters( 'the_title', pf_format_root( $id ), $id );
			$stitle = (string) get_field( 'sous-titre', $id );
			$label  = $stitle ? $title . ', ' . $stitle : $title;
			$meta   = wp_strip_all_tags( get_post_field( 'post_excerpt', $id ) );
			$items .= $this->render_item( $label, get_permalink( $id ), $img_id, $meta );
		}
		return $items;
	}

	/* ─── Auteurs ────────────────────────────────────────────────── */

	/**
	 * Termes auteur correspondants, classés : d'abord ceux crédités sur les
	 * livres de l'amorce (recoupement, cf. section_pools()), puis le matching
	 * flou de l'index auteur.
	 *
	 * Renvoie des WP_Term et les charge TOUS d'un coup (la taxonomie auteur ne
	 * compte que quelques dizaines de termes) : c'est ce qui rend le total
	 * EXACT, donc la pagination juste. Paginer via les paramètres
	 * 'number'/'offset' de get_terms() ne le permettrait pas — un ID venu des
	 * contributions SCF peut pointer sur un terme supprimé depuis (référence
	 * recopiée en postmeta), get_terms() en renvoie alors moins que demandé :
	 * un total compté sur les IDs serait surestimé, le bouton
	 * « + de résultats » s'afficherait pour un lot vide, et le dernier lot réel
	 * serait incomplet.
	 *
	 * @return WP_Term[]
	 */
	private function auteur_terms( $search, array $seed ) {
		$from_books = [];
		foreach ( $seed as $bid ) {
			foreach ( $this->get_book_author_term_ids( $bid ) as $tid ) {
				$from_books[] = $tid;
			}
		}

		$fuzzy = pf_search_filter_pool( $search, pf_search_pool_auteurs() );
		$ids   = array_values( array_unique( array_merge( $from_books, $fuzzy ) ) );
		if ( empty( $ids ) ) return [];

		// Pas de 'number' : 'include' borne déjà la requête, et tout plafond ici
		// fausserait le total (cf. docblock).
		$terms = get_terms( [
			'taxonomy'   => 'auteur',
			'include'    => $ids,
			'hide_empty' => false,
			'orderby'    => 'include',
		] );

		return is_wp_error( $terms ) ? [] : $terms;
	}

	private function items_auteurs( array $terms ) {
		$items = '';
		foreach ( $terms as $term ) {
			$photo_id = (int) get_field( 'photo', 'auteur_' . $term->term_id );
			$bio      = (string) get_field( 'biographie_synthetique', 'auteur_' . $term->term_id );
			$items   .= $this->render_item( $term->name, get_term_link( $term ), $photo_id, $bio );
		}
		return $items;
	}

	/* ─── Événements ─────────────────────────────────────────────── */

	private function evenement_ids( $search, array $seed ) {
		if ( ! post_type_exists( 'tribe_events' ) ) return [];

		// Le second argument inclut les événements liés aux livres déjà trouvés
		// (ex. recherche ISBN : le texte indexé de l'événement contient les
		// titres des livres associés, pas leur ISBN — son matching flou ne les
		// ferait donc pas remonter tout seul). Troisième argument : la recherche
		// globale ne remonte que les événements à venir (les passés restent
		// trouvables via la recherche locale de /evenements).
		return pf_search_events_ranked( $search, pf_search_events_for_products( $seed ), true );
	}

	private function items_evenements( array $ids ) {
		// Métadonnées supplémentaires pour les seuls événements du lot.
		global $wpdb;
		$ids_in     = implode( ',', array_map( 'intval', $ids ) );
		$meta_rows  = $wpdb->get_results(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key IN ('_EventStartDate','_EventEndDate','_EventVenueID','_EventAllDay')
			 AND post_id IN ($ids_in)"
		);
		$start_strs = [];
		$end_strs   = [];
		$venue_ids  = [];
		$all_days   = [];
		foreach ( $meta_rows as $r ) {
			$pid = (int) $r->post_id;
			if ( $r->meta_key === '_EventStartDate' ) $start_strs[ $pid ] = $r->meta_value;
			if ( $r->meta_key === '_EventEndDate' )   $end_strs[ $pid ]   = $r->meta_value;
			if ( $r->meta_key === '_EventVenueID' )   $venue_ids[ $pid ]  = (int) $r->meta_value;
			if ( $r->meta_key === '_EventAllDay' )    $all_days[ $pid ]   = (bool) $r->meta_value;
		}

		// Données des lieux.
		$venues          = [];
		$unique_vids     = array_unique( array_filter( array_values( $venue_ids ) ) );
		if ( $unique_vids ) {
			$vids_in     = implode( ',', array_map( 'intval', $unique_vids ) );
			$venue_rows  = $wpdb->get_results(
				"SELECT p.ID, p.post_title, pm.meta_value AS city
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_VenueCity'
				 WHERE p.ID IN ($vids_in)"
			);
			foreach ( $venue_rows as $vr ) {
				$venues[ (int) $vr->ID ] = [ 'title' => $vr->post_title, 'city' => $vr->city ];
			}
		}

		$items = '';
		foreach ( $ids as $id ) {
			$img_id   = (int) get_post_thumbnail_id( $id );
			$venue    = isset( $venue_ids[ $id ] ) ? ( $venues[ $venue_ids[ $id ] ] ?? [] ) : [];
			$subtitle = $this->format_event_subtitle(
				$id,
				$start_strs[ $id ] ?? '',
				$end_strs[ $id ] ?? '',
				$all_days[ $id ] ?? false,
				$venue
			);
			$excerpt  = wp_strip_all_tags( get_post_field( 'post_excerpt', $id ) );
			$items   .= $this->render_item( get_the_title( $id ), get_permalink( $id ), $img_id, $excerpt, $subtitle );
		}

		return $items;
	}

	/* ─── Helpers données ───────────────────────────────────────────── */

	/**
	 * IDs des termes auteur (fiche-auteur) crédités en contribution sur un
	 * livre — les contributions en texte libre (sans fiche auteur) n'ont pas
	 * de terme à faire remonter.
	 */
	private function get_book_author_term_ids( $post_id ) {
		$contribs = get_field( 'contributions', $post_id );
		if ( ! is_array( $contribs ) ) return [];
		$ids = [];
		foreach ( $contribs as $row ) {
			if ( ( $row['assignation'] ?? '' ) !== 'fiche-auteur' ) continue;
			foreach ( (array) ( $row['fiche-auteur'] ?? [] ) as $item ) {
				$tid = is_object( $item ) ? (int) $item->term_id : absint( $item );
				if ( $tid ) $ids[] = $tid;
			}
		}
		return $ids;
	}

	private function format_event_subtitle( $id, $start_str, $end_str, $all_day, $venue ) {
		$parts = [];

		// Partie dates — sans heures ; année omise si année courante. pf_date_i18n_ordinal()
		// renvoie du HTML pré-échappé (jour "1er" avec <sup> brut) : ne pas ré-échapper le
		// résultat de cette méthode (cf. render_item(), $subtitle sorti du esc_html() global).
		$sd = $start_str ? DateTime::createFromFormat( 'Y-m-d H:i:s', $start_str ) : false;
		$ed = $end_str   ? DateTime::createFromFormat( 'Y-m-d H:i:s', $end_str )   : false;
		if ( $sd ) {
			$cur_year = (int) date_i18n( 'Y' );
			$s_ts     = $sd->getTimestamp();
			$e_ts     = $ed ? $ed->getTimestamp() : 0;
			$s_ymd    = $sd->format( 'Ymd' );
			$e_ymd    = $ed ? $ed->format( 'Ymd' ) : $s_ymd;
			$s_year   = (int) $sd->format( 'Y' );
			$e_year   = $ed ? (int) $ed->format( 'Y' ) : $s_year;
			$s_month  = (int) $sd->format( 'n' );
			$e_month  = $ed ? (int) $ed->format( 'n' ) : $s_month;

			if ( $s_ymd === $e_ymd ) {
				// Jour unique
				$date_str = pf_date_i18n_ordinal( 'j F', $s_ts );
				if ( $s_year !== $cur_year ) $date_str .= ' ' . $s_year;
			} elseif ( $s_month === $e_month && $s_year === $e_year ) {
				// Même mois : "Du j au j F [Y]"
				$date_str = 'Du ' . pf_date_i18n_ordinal( 'j', $s_ts ) . ' au ' . pf_date_i18n_ordinal( 'j F', $e_ts );
				if ( $s_year !== $cur_year ) $date_str .= ' ' . $s_year;
			} elseif ( $s_year === $e_year ) {
				// Même année, mois différents : "Du j F au j F [Y]"
				$date_str = 'Du ' . pf_date_i18n_ordinal( 'j F', $s_ts ) . ' au ' . pf_date_i18n_ordinal( 'j F', $e_ts );
				if ( $s_year !== $cur_year ) $date_str .= ' ' . $s_year;
			} else {
				// Années différentes : toujours afficher les années
				$date_str = 'Du ' . pf_date_i18n_ordinal( 'j F Y', $s_ts ) . ' au ' . pf_date_i18n_ordinal( 'j F Y', $e_ts );
			}
			$parts[] = $date_str;
		}

		// Lieu. Échappé ici (texte issu de la BD) : le sous-titre assemblé n'est plus
		// ré-échappé en aval, à cause du HTML pré-échappé produit par pf_date_i18n_ordinal().
		$lieu_parts = array_filter( [ $venue['title'] ?? '', $venue['city'] ?? '' ] );
		if ( $lieu_parts ) $parts[] = esc_html( implode( ', ', $lieu_parts ) );

		// Présences.
		$presences = $this->get_event_participants_str( $id );
		if ( $presences !== '' ) $parts[] = esc_html( $presences );

		return implode( " \xe2\x80\xa2 ", $parts );
	}

	private function get_event_participants_str( $id ) {
		if ( ! function_exists( 'passiflore_render_event_participants' ) ) return '';
		$html = passiflore_render_event_participants( $id );
		return rtrim( trim( wp_strip_all_tags( $html ) ), '.' );
	}

	/* ─── Helpers rendu ─────────────────────────────────────────────── */

	/**
	 * @param string $section     Clé de section (paramètre AJAX du lot suivant).
	 * @param int    $next_offset Offset du lot suivant ; 0 = tout est affiché, pas de bouton.
	 */
	private function render_group( $label, $items_html, $section = '', $next_offset = 0 ) {
		if ( $items_html === '' ) return '';
		return '<div class="pf-gsearch-group site-container">'
			. '<p class="pf-gsearch-group-label">' . esc_html( $label ) . '</p>'
			. $items_html
			. ( $next_offset > 0 ? $this->render_more( $section, $label, $next_offset ) : '' )
			. '</div>';
	}

	/**
	 * Ligne « + de résultats » / « - de résultats » d'une section. Les deux
	 * boutons sont rendus ensemble : « - » repose sur un lot déjà présent dans
	 * le DOM (retiré côté client, sans aller-retour serveur — cf. loadLess()
	 * dans recherche-globale.js), donc il n'a pas de raison d'exister sans son
	 * « + », et inversement le JS a besoin des deux côte à côte pour retrouver
	 * l'un depuis l'autre (closest('.pf-gsearch-more-row')). Six boutons
	 * identiques (2 par section) peuvent cohabiter dans le panneau : le nom
	 * accessible de chacun est donc qualifié par la section, mais il REPREND
	 * le libellé visible (WCAG 2.5.3 « Label in Name » — un nom accessible qui
	 * remplacerait le texte visible casserait la commande vocale).
	 * « - » démarre masqué (`hidden`) : rien n'a encore été chargé en plus du
	 * premier lot.
	 */
	private function render_more( $section, $label, $next_offset ) {
		$more_text = __( '+ de résultats', 'kadence-child' );
		$less_text = __( '- de résultats', 'kadence-child' );
		return '<div class="pf-gsearch-more-row">'
			. '<button type="button" class="pf-gsearch-more pf-btn pf-btn--neutral pf-btn--xs"'
			. ' data-section="' . esc_attr( $section ) . '"'
			. ' data-offset="' . (int) $next_offset . '"'
			. ' aria-label="' . esc_attr( $more_text . ' : ' . $label ) . '">'
			. esc_html( $more_text )
			. '</button>'
			. '<button type="button" class="pf-gsearch-less pf-btn pf-btn--neutral pf-btn--xs" hidden'
			. ' aria-label="' . esc_attr( $less_text . ' : ' . $label ) . '">'
			. esc_html( $less_text )
			. '</button>'
			. '</div>';
	}

	/**
	 * @param string $subtitle HTML déjà échappé (voir format_event_subtitle()) — ne pas
	 *                         passer de texte brut non échappé ici.
	 */
	private function render_item( $title, $url, $img_id, $meta, $subtitle = '' ) {
		if ( $img_id ) {
			$img = wp_get_attachment_image( $img_id, 'woocommerce_single', false, [
				'class'   => 'pf-gsearch-item-img',
				'loading' => 'lazy',
			] );
		} else {
			$img = '';
		}

		return '<a href="' . esc_url( $url ) . '" class="pf-gsearch-item">'
			. '<span class="pf-gsearch-item-img-wrap">' . $img . '</span>'
			. '<span class="pf-gsearch-item-text">'
			. '<span class="pf-gsearch-item-title">' . esc_html( $title ) . '</span>'
			. ( $subtitle !== '' ? '<span class="pf-gsearch-item-subtitle">' . $subtitle . '</span>' : '' )
			. ( $meta !== '' ? '<span class="pf-gsearch-item-meta">' . esc_html( $meta ) . '</span>' : '' )
			. '</span>'
			. '</a>';
	}
}

new Passiflore_Recherche_Globale();
