<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Passiflore_Recherche_Globale
 *
 * Barre de recherche globale intégrée dans le header Kadence via le shortcode
 * [passiflore_recherche]. L'élément se place après la nav dans la section
 * centrale du header builder. Un clic sur la loupe cache la nav et étend la
 * barre sur toute la section ; les résultats (livres, auteurs, événements)
 * s'affichent en panneau fixe sous le header.
 *
 * Action AJAX : pf_global_search
 */
class Passiflore_Recherche_Globale {

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

	/* ─── Shortcode ──────────────────────────────────────────────── */

	public function render() {
		// Placeholder long (desktop) / court (mobile, ≤768px — cf. recherche-globale.js)
		// pour chaque type de résultats du sélecteur.
		$placeholders = [
			'all'        => [
				'long'  => __( 'Rechercher un livre, un auteur, un événement à venir…', 'kadence-child' ),
				'short' => __( 'Livre, auteur, événement à venir…', 'kadence-child' ),
			],
			'livres'     => [
				'long'  => __( 'Rechercher par titre, auteur, ISBN, prix littéraire, mot-clé…', 'kadence-child' ),
				'short' => __( 'Par titre, auteur, ISBN, prix littéraire…', 'kadence-child' ),
			],
			'auteurs'    => [
				'long'  => __( 'Rechercher par nom, prénom, livre…', 'kadence-child' ),
				'short' => __( 'Par nom, prénom, livre…', 'kadence-child' ),
			],
			'evenements' => [
				'long'  => __( 'Rechercher par mot-clé, participant, lieu, livre…', 'kadence-child' ),
				'short' => __( 'Par mot-clé, participant, lieu, livre…', 'kadence-child' ),
			],
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
							placeholder="<?php echo esc_attr( $placeholders['all']['long'] ); ?>"
							autocomplete="off"
							aria-label="<?php esc_attr_e( 'Recherche globale', 'kadence-child' ); ?>"
						>
						<button class="pf-search-clear pf-gsearch-clear" type="button"
							aria-label="<?php esc_attr_e( 'Effacer', 'kadence-child' ); ?>">×</button>
					</div>
					<div class="pf-gsearch-type-wrap pf-dropdown">
						<button type="button" class="pf-gsearch-type-btn pf-dropdown__trigger"
							aria-haspopup="listbox"
							aria-expanded="false"
							aria-label="<?php esc_attr_e( 'Type de résultats', 'kadence-child' ); ?>">
							<span class="pf-gsearch-type-label"><?php esc_html_e( 'Tout', 'kadence-child' ); ?></span>
							<span class="pf-gsearch-type-caret" aria-hidden="true">&#9662;</span>
						</button>
						<div class="pf-gsearch-type-menu pf-dropdown__menu" role="listbox">
							<button type="button" class="pf-gsearch-type-option pf-dropdown__option is-selected" data-value="all"><?php esc_html_e( 'Tout', 'kadence-child' ); ?></button>
							<button type="button" class="pf-gsearch-type-option pf-dropdown__option" data-value="livres"><?php esc_html_e( 'Livres', 'kadence-child' ); ?></button>
							<button type="button" class="pf-gsearch-type-option pf-dropdown__option" data-value="auteurs"><?php esc_html_e( 'Auteurs', 'kadence-child' ); ?></button>
							<button type="button" class="pf-gsearch-type-option pf-dropdown__option" data-value="evenements"><?php esc_html_e( 'Événements à venir', 'kadence-child' ); ?></button>
						</div>
						<input type="hidden" class="pf-gsearch-type" value="all">
					</div>
				</div>
				<div class="pf-gsearch-results" role="status" aria-live="polite"></div>
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
		$type   = isset( $_POST['type'] )   ? sanitize_key( wp_unslash( $_POST['type'] ) )          : 'all';

		if ( mb_strlen( trim( $search ) ) < 2 ) {
			wp_send_json_success( [ 'html' => '' ] );
			return;
		}

		// Livres correspondants : calculés une fois, affichés dans la section
		// Ouvrages ET réutilisés pour faire remonter leurs auteurs et leurs
		// événements liés dans les sections respectives (utile en recherche
		// ISBN : l'index auteur/événement est construit à partir des titres
		// de livres, pas des ISBN, donc son propre matching flou ne les
		// ferait pas remonter).
		$book_ids = array_slice( pf_bg_dedup( pf_search_products_ranked( $search ) ), 0, 6 );

		$html = '';

		if ( $type === 'all' || $type === 'livres' ) {
			$html .= $this->search_livres( $book_ids );
		}
		if ( $type === 'all' || $type === 'auteurs' ) {
			$html .= $this->search_auteurs( $search, $book_ids );
		}
		if ( $type === 'all' || $type === 'evenements' ) {
			$html .= $this->search_evenements( $search, $book_ids );
		}

		if ( $html === '' ) {
			$messages = [
				'all'        => 'Aucun livre, auteur ou événement à venir trouvé avec votre recherche.',
				'livres'     => 'Aucun livre trouvé avec votre recherche.',
				'auteurs'    => 'Aucun auteur trouvé avec votre recherche.',
				'evenements' => 'Aucun événement à venir trouvé avec votre recherche.',
			];
			$html = '<p class="pf-empty">' . esc_html( $messages[ $type ] ?? $messages['all'] ) . '</p>';
		}

		wp_send_json_success( [ 'html' => $html ] );
	}

	/* ─── Recherche livres ───────────────────────────────────────── */

	private function search_livres( array $ids ) {
		if ( empty( $ids ) ) return '';

		$items = '';
		foreach ( $ids as $id ) {
			$img_id = (int) get_post_thumbnail_id( $id );
			$title  = get_the_title( $id );
			$stitle = (string) get_field( 'sous-titre', $id );
			$label  = $stitle ? $title . ', ' . $stitle : $title;
			$meta   = wp_strip_all_tags( get_post_field( 'post_excerpt', $id ) );
			$items .= $this->render_item( $label, get_permalink( $id ), $img_id, $meta );
		}

		return $this->render_group( __( 'Livres', 'kadence-child' ), $items );
	}

	/* ─── Recherche auteurs ──────────────────────────────────────── */

	private function search_auteurs( $search, array $book_ids = [] ) {
		$book_author_ids = [];
		foreach ( $book_ids as $bid ) {
			foreach ( $this->get_book_author_term_ids( $bid ) as $tid ) {
				$book_author_ids[] = $tid;
			}
		}

		$fuzzy_ids = pf_search_filter_pool( $search, pf_search_pool_auteurs() );
		$ids       = array_slice( array_values( array_unique( array_merge( $book_author_ids, $fuzzy_ids ) ) ), 0, 5 );
		if ( empty( $ids ) ) return '';

		$terms = get_terms( [
			'taxonomy'   => 'auteur',
			'include'    => $ids,
			'hide_empty' => false,
			'orderby'    => 'include',
			'number'     => 5,
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) return '';

		$items = '';
		foreach ( $terms as $term ) {
			$photo_id = (int) get_field( 'photo', 'auteur_' . $term->term_id );
			$bio      = (string) get_field( 'biographie_synthetique', 'auteur_' . $term->term_id );
			$items   .= $this->render_item( $term->name, get_term_link( $term ), $photo_id, $bio );
		}

		return $this->render_group( __( 'Auteurs', 'kadence-child' ), $items );
	}

	/* ─── Recherche événements ───────────────────────────────────── */

	private function search_evenements( $search, array $book_ids = [] ) {
		if ( ! post_type_exists( 'tribe_events' ) ) return '';

		// Le second argument inclut les événements liés aux livres déjà trouvés
		// (ex. recherche ISBN : le texte indexé de l'événement contient les
		// titres des livres associés, pas leur ISBN — son matching flou ne les
		// ferait donc pas remonter tout seul). Troisième argument : la recherche
		// globale ne remonte que les événements à venir (les passés restent
		// trouvables via la recherche locale de /evenements).
		$ids = pf_search_events_ranked( $search, pf_search_events_for_products( $book_ids ), true );
		if ( empty( $ids ) ) return '';
		$ids = array_slice( $ids, 0, 6 );

		// Métadonnées supplémentaires pour les 6 événements retenus.
		global $wpdb;
		$ids_in6    = implode( ',', array_map( 'intval', $ids ) );
		$meta_rows  = $wpdb->get_results(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key IN ('_EventStartDate','_EventEndDate','_EventVenueID','_EventAllDay')
			 AND post_id IN ($ids_in6)"
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

		return $this->render_group( __( 'Événements à venir', 'kadence-child' ), $items );
	}

	/* ─── Helpers données ───────────────────────────────────────────── */

	private function get_book_contributors_str( $post_id ) {
		$contribs = get_field( 'contributions', $post_id );
		if ( ! is_array( $contribs ) ) return '';
		$names = [];
		foreach ( $contribs as $row ) {
			if ( ( $row['assignation'] ?? '' ) === 'fiche-auteur' ) {
				$items = $row['fiche-auteur'] ?? [];
				if ( ! is_array( $items ) ) $items = [ $items ];
				foreach ( $items as $item ) {
					$tid = is_object( $item ) ? (int) $item->term_id : absint( $item );
					if ( $tid ) $names[] = passiflore_auteur_display_name( $tid );
				}
			} else {
				$nom = (string) ( $row[ "nom_de_l\xE2\x80\x99auteur" ] ?? ( $row["nom_de_l'auteur"] ?? '' ) );
				if ( $nom !== '' ) $names[] = $nom;
			}
		}
		return implode( ', ', array_unique( $names ) );
	}

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

	private function get_author_books_str( $term_id ) {
		global $wpdb;
		$like       = '%"' . (int) $term_id . '"%';
		$candidates = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'
			 AND pm.meta_key LIKE 'contributions_%_fiche-auteur'
			 AND (pm.meta_value = %s OR pm.meta_value LIKE %s)",
			(string) $term_id, $like
		) );
		if ( empty( $candidates ) ) return '';

		$ids_in = implode( ',', array_map( 'intval', $candidates ) );
		$rows   = $wpdb->get_results(
			"SELECT p.ID, p.post_title, MAX(pm.meta_value) AS date_parution
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'date_de_parution'
			 WHERE p.ID IN ($ids_in)
			 GROUP BY p.ID, p.post_title
			 ORDER BY date_parution DESC"
		);
		if ( empty( $rows ) ) return '';
		return implode( ', ', array_slice( wp_list_pluck( (array) $rows, 'post_title' ), 0, 4 ) );
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

	private function render_group( $label, $items_html ) {
		if ( $items_html === '' ) return '';
		return '<div class="pf-gsearch-group">'
			. '<p class="pf-gsearch-group-label">' . esc_html( $label ) . '</p>'
			. $items_html
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
