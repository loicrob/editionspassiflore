<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Passiflore_Bookshelf {

	const SCALE             = 1.2; // px per mm
	const DEFAULT_HEIGHT_MM = 210;
	const DEFAULT_WIDTH_MM  = 140;
	const MM_PER_PAGE       = 0.08; // ~80µm per page (standard paper)
	const MIN_SPINE_MM      = 10;
	const MAX_SPINE_MM      = 60;
	// Hard bounds on physical dimensions, to guard against bad WC data
	// (e.g. an extra zero in the height field rendering a 2-meter book).
	const MIN_HEIGHT_MM     = 100;
	const MAX_HEIGHT_MM     = 400;
	const MIN_WIDTH_MM      = 80;
	const MAX_WIDTH_MM      = 350;
	const PASSIFLORE_RED    = '#c62836';
	const COLOR_META_KEY    = '_pf_cover_color';
	// Tailles d'image dédiées à l'étagère GÉNÉRIQUE (les ~300 livres du
	// catalogue, servis × chaque visite / chaque filtre) à la place de
	// medium_large (768) / large (1024) surdimensionnés. Non-crop → le ratio
	// est préservé : indispensable pour les tranches, images très étroites et
	// hautes (la hauteur est la dimension contraignante, la largeur suit).
	// Calées sur le rendu réel : couverture ≤ ~420px de large / ~480px de haut
	// (mode covers) ; tranche ≤ ~108px de large / ~720px de haut (mode spines).
	// Le mode HÉROS (fiche livre, livre affiché bien plus grand) conserve
	// medium_large / large — cf. prepare_books().
	const SHELF_COVER_SIZE  = 'pf-shelf-cover'; // 400 × 600 (boîte englobante)
	const SHELF_SPINE_SIZE  = 'pf-shelf-spine'; // 300 × 760 (boîte englobante)
	// Les livres numériques sont rendus comme une liseuse posée sur
	// l'étagère : hauteur d'appareil fixe, largeur dérivée du ratio de la
	// couverture pour que l'écran l'affiche en entier, sans letterbox.
	const EREADER_HEIGHT_MM = 210;
	const EREADER_SPINE_MM  = 9;
	// Bezels de l'appareil en px — doivent refléter le padding CSS de
	// .pf-book--ereader .pf-book-cover (7px côtés/haut, 20px de menton).
	const EREADER_BEZEL_PX  = 7;
	const EREADER_CHIN_PX   = 24;
	// Les livres audio sont rendus comme un boîtier CD cristal.
	const CD_HEIGHT_MM      = 125;
	const CD_WIDTH_MM       = 142;
	const CD_SPINE_MM       = 10;
	// Icône « signet étoilé » (version sharp, coins droits) posée sur les
	// couvertures quand show-bookmarks est actif (toggle « liste de lecture »).
	// Tracés authorés en viewBox 0 -960 960 960 ; deux tracés superposés pour un
	// signet PLEIN : silhouette (remplie + contour) puis étoile (couleur contrastée).
	// Le SVG (bookmark_html()) recadre sur la bounding box du tracé (200 -840 560 720)
	// ÉLARGIE d'une marge pour ne pas rogner le contour (stroke) qui déborde du path.
	const BOOKMARK_BODY_PATH = 'M200-120v-720h560v720L480-240 200-120Z';
	const BOOKMARK_STAR_PATH = 'm389-400 91-55 91 55-24-104 80-69-105-9-42-98-42 98-105 9 80 69-24 104Z';

	/**
	 * Annotations de recommandation, posées par pf_reco_render() le temps d'un
	 * rendu : [ product_id => [ 'score' => int, 'why' => string ] ]. À vide
	 * (cas général), aucun badge n'est émis et le comportement est inchangé.
	 */
	protected static $reco_annotations = [];

	public static function set_reco_annotations( array $map ) {
		self::$reco_annotations = $map;
	}

	/**
	 * Nombre de livres (dédupliqués) du dernier rendu de [passiflore_etagere].
	 * Sous-produit du rendu (count des $books, avant lazy-load d'affichage) : lu
	 * par Passiflore_Catalogue pour le compteur « N résultats » du panneau.
	 */
	public static $last_total = 0;

	public function __construct() {
		add_shortcode( 'passiflore_etagere', [ $this, 'render_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
		add_action( 'after_setup_theme', [ $this, 'register_image_sizes' ] );
	}

	/**
	 * Sous-tailles dédiées à l'étagère générique (cf. constantes SHELF_*_SIZE).
	 * Non-crop : le ratio est préservé — pour une tranche (étroite et haute)
	 * c'est la hauteur qui contraint, la largeur suit le ratio.
	 * ⚠️ Les images déjà en médiathèque doivent être régénérées pour que ces
	 * sous-tailles existent ; sinon wp_get_attachment_image_url() retombe sur
	 * l'original PLEIN format (régression). Les futurs imports les génèrent.
	 */
	public function register_image_sizes() {
		add_image_size( self::SHELF_COVER_SIZE, 400, 600, false );
		add_image_size( self::SHELF_SPINE_SIZE, 300, 760, false );
	}

	/* ─── Frontend: Assets ───────────────────────────────────────── */

	public function register_assets() {
		$theme_uri = get_stylesheet_directory_uri();
		$theme_dir = get_stylesheet_directory();

		wp_register_style(
			'pf-bookshelf',
			$theme_uri . '/assets/css/bookshelf.css',
			[],
			filemtime( $theme_dir . '/assets/css/bookshelf.css' )
		);

		wp_register_script(
			'pf-bookshelf',
			$theme_uri . '/assets/js/bookshelf.js',
			[],
			filemtime( $theme_dir . '/assets/js/bookshelf.js' ),
			true
		);

		// Composant Toast global + interaction des signets « liste de lecture ».
		// Enregistrés ici, enqueués à la demande par enqueue_bookmark_assets().
		wp_register_script(
			'pf-toast',
			$theme_uri . '/assets/js/pf-toast.js',
			[],
			filemtime( $theme_dir . '/assets/js/pf-toast.js' ),
			true
		);
		wp_register_script(
			'pf-shelf-bookmarks',
			$theme_uri . '/assets/js/shelf-bookmarks.js',
			[ 'pf-toast', 'pf-session-toast' ],
			filemtime( $theme_dir . '/assets/js/shelf-bookmarks.js' ),
			true
		);
	}

	/**
	 * Enqueue (une seule fois) le JS des signets sur couverture + le composant
	 * toast, et localise `pfBookmarks`. Réutilise le nonce de Passiflore_Reading_List
	 * (même endpoint pf_reading_list_toggle). Le CSS du signet vit dans bookshelf.css
	 * (déjà enqueué) et celui du toast dans style.css (toujours chargé).
	 */
	private function enqueue_bookmark_assets() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		wp_enqueue_script( 'pf-shelf-bookmarks' );
		wp_localize_script( 'pf-shelf-bookmarks', 'pfBookmarks', [
			'ajax_url'      => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( Passiflore_Reading_List::NONCE ),
			'toggle_action' => 'pf_reading_list_toggle',
			// Vrai sur la page « Mon compte → Liste de lecture » : c'est là que se
			// trouve l'étagère « Ma liste de lecture » à reconstruire après un toggle.
			'rebuild_readlist' => (bool) ( function_exists( 'is_wc_endpoint_url' ) && class_exists( 'Passiflore_Reading_List' ) && is_wc_endpoint_url( Passiflore_Reading_List::ENDPOINT ) ),
			'strings'       => [
				'tip_add'    => 'Ajouter à ma liste de lecture',
				'tip_remove' => 'Retirer de ma liste de lecture',
				/* translators: %s = titre du livre (déjà mis en forme). */
				'added'      => '%s ajouté à votre liste de lecture.',
				/* translators: %s = titre du livre (déjà mis en forme). */
				'removed'    => '%s retiré de votre liste de lecture.',
				'undo'       => 'Annuler',
				'close'      => 'Fermer',
			],
		] );
	}

	/* ─── Frontend: Shortcode ────────────────────────────────────── */

	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( $this->default_atts(), $atts, 'passiflore_etagere' );

		wp_enqueue_style( 'pf-bookshelf' );
		wp_enqueue_script( 'pf-bookshelf' );

		$display = sanitize_key( $atts['display'] );
		if ( ! in_array( $display, [ 'covers', 'spines' ], true ) ) {
			$display = 'covers';
		}

		$products = $this->query_products( $atts );
		if ( empty( $products ) ) {
			self::$last_total = 0;
			return '<p class="pf-bookshelf-empty">' . esc_html__( 'Aucun livre ne correspond à votre recherche.', 'kadence-child' ) . '</p>';
		}

		$show_formats = filter_var( $atts['display_formats'], FILTER_VALIDATE_BOOLEAN );

		$is_hero      = filter_var( $atts['hero'], FILTER_VALIDATE_BOOLEAN );
		$books        = $this->prepare_books( $products, $display, $is_hero );
		self::$last_total = count( $books );

		// Opt-in: arrange the books tallest → shortest. Enabled per shelf via
		// orderby="hauteur" (used by the home-page « Culture Sud-Ouest » shelf).
		// The sort key is `height_px` — the very value used to render each
		// spine's height (book height in mm × scale; cover/tranche images only
		// affect spine *width*) — so it can only run after prepare_books has
		// resolved every book's dimensions. Ties fall back to the parution
		// date, most recent first.
		if ( sanitize_key( $atts['orderby'] ) === 'hauteur' ) {
			foreach ( $books as &$b ) {
				$b['parution'] = (string) get_post_meta( $b['id'], 'date_de_parution', true );
			}
			unset( $b );
			usort( $books, static function ( $a, $b ) {
				if ( $a['height_px'] !== $b['height_px'] ) {
					return $b['height_px'] <=> $a['height_px']; // tallest first
				}
				// date_de_parution is Ymd → lexicographic compare is chrono;
				// reversed for most-recent-first. Undated books sort last.
				return strcmp( $b['parution'], $a['parution'] );
			} );
		}

		$show_price   = filter_var( $atts['show_price'], FILTER_VALIDATE_BOOLEAN );
		$mode         = sanitize_key( $atts['mode'] );
		$per_shelf    = absint( $atts['per_shelf'] );
		$nb_first     = absint( $atts['nb_books_first_displayed'] );
		$libraires_url = ( $is_hero && $atts['libraires_url'] ) ? esc_url( rawurldecode( $atts['libraires_url'] ) ) : '';

		// Signets « liste de lecture » posés sur la couverture : hors mode héros,
		// pour un utilisateur connecté (le back Passiflore_Reading_List ne gère pas
		// les invités). En mode « dos », la couverture — donc le signet posé
		// dessus — n'apparaît qu'au survol, quand le livre pivote pour la révéler.
		$show_bookmarks = filter_var( $atts['show-bookmarks'], FILTER_VALIDATE_BOOLEAN )
			&& ! $is_hero
			&& is_user_logged_in() && class_exists( 'Passiflore_Reading_List' );
		if ( $show_bookmarks ) {
			$this->enqueue_bookmark_assets();
		}

		// Étiquettes « À paraître » / « Nouveauté » : actives par défaut.
		// Si l'une ou l'autre est désactivée, on neutralise le drapeau correspondant.
		$show_ap  = filter_var( $atts['display-aparaitre'],  FILTER_VALIDATE_BOOLEAN );
		$show_nv  = filter_var( $atts['display-nouveautes'], FILTER_VALIDATE_BOOLEAN );
		if ( ! $show_ap || ! $show_nv ) {
			foreach ( $books as &$b ) {
				if ( ! $show_ap ) $b['is_aparaitre'] = false;
				if ( ! $show_nv ) $b['is_nouveaute'] = false;
			}
			unset( $b );
		}

		$max_h = 0;
		foreach ( $books as $b ) {
			if ( $b['height_px'] > $max_h ) $max_h = $b['height_px'];
		}
		$shelf_inner = $max_h + 20;

		$cat_theme = $this->resolve_category_theme( $atts );

		if ( $mode === 'scroll' ) {
			return $this->render_scroll( $books, $display, $show_price, $shelf_inner, $nb_first, $is_hero, $cat_theme, $show_formats, $libraires_url, $show_bookmarks );
		}

		return $this->render_shelves( $books, $display, $show_price, $shelf_inner, $per_shelf, $nb_first, $is_hero, $cat_theme, $show_formats, $libraires_url, $show_bookmarks );
	}

	private function default_atts() {
		return [
			'mode'                     => 'shelves',   // shelves | scroll
			'display'                  => 'covers',    // covers | spines
			'show_price'               => 'false',
			'display-aparaitre'        => 'true',     // étiquette « À paraître » sur le chant de la planche
			'display-nouveautes'       => 'false',    // étiquette « Nouveauté » sur le chant de la planche
			'category'                 => '',
			'tag'                      => '',
			'per_shelf'                => 0,
			'orderby'                  => 'date',      // date | titre | prix | pages | (any WP_Query orderby)
			'order'                    => 'DESC',
			'ids'                      => '',
			'format'                   => '',          // '' (dédup) | tous | classique | <slug pa_format_particulier>
			'search'                   => '',
			'decouvrir'                => '',          // '' | nouveautes | prix-litteraires | a-paraitre
			'disponibilite'            => '',          // slug SCF
			'public'                   => '',          // slug SCF
			'type'                     => '',          // slug SCF
			'reliure'                  => '',          // slug SCF (champ `type_de_reliure`)
			'langues'                  => '',          // CSV des slugs SCF (multi)
			'auteur'                   => '',          // slug(s) ou ID(s) de terme `auteur` — livres où la fiche est en contribution
			'role'                     => '',          // CSV des types de contribution (auteur, traduction…) pour restreindre `auteur`
			'nb_books_first_displayed' => 12,
			'hero'                     => 'false',    // true = mode héros fiche livre (non cliquable, sans hover)
			'display_formats'          => 'false',    // true — affiche le format (pa_format_particulier) dans le chevalet
			'show-bookmarks'           => 'false',    // true — icône signet « liste de lecture » sur chaque couverture (utilisateur connecté)
			'libraires_url'            => '',          // mode hero uniquement : lien « Voir sur Place des libraires », rendu dans .pf-shelf sous la planche. rawurlencode() côté appelant (l'URL traverse le parseur de shortcode en tant que chaîne).
		];
	}

	/* ─── Query & Prepare ────────────────────────────────────────── */

	private function query_products( $atts ) {
		$args = $this->build_query_args( $atts );
		return get_posts( $args );
	}

	/**
	 * Build a WP_Query args array from shortcode atts. Shared by the
	 * main shortcode render and by get_filter_counts().
	 */
	private function build_query_args( $atts ) {
		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'order'          => strtoupper( $atts['order'] ) === 'ASC' ? 'ASC' : 'DESC',
			'tax_query'      => [],
			'meta_query'     => [],
		];

		// `ids` explicitly forces a fixed list — overrides every other filter
		// except OOS visibility. Same behavior as before the refactor.
		if ( ! empty( $atts['ids'] ) ) {
			$args['post__in'] = array_map( 'absint', explode( ',', $atts['ids'] ) );
			$args['orderby']  = 'post__in';
			$this->apply_oos_visibility( $args );
			return $args;
		}

		$this->apply_sort( $args, $atts );
		$this->apply_category_tag( $args, $atts );
		$this->apply_auteur_filter( $args, $atts );
		$this->apply_scf_eq_filter( $args, $atts, 'disponibilite', 'disponibilite' );
		$this->apply_scf_eq_filter( $args, $atts, 'public',        'public' );
		$this->apply_scf_eq_filter( $args, $atts, 'type',          'type' );
		$this->apply_scf_eq_filter( $args, $atts, 'reliure',       'type_de_reliure' );
		$this->apply_langues_filter( $args, $atts );
		$this->apply_decouvrir_filter( $args, $atts );
		$this->apply_format_filter( $args, $atts );
		$this->apply_search_filter( $args, $atts );
		$this->apply_oos_visibility( $args );

		return $args;
	}

	/**
	 * Equality meta filter for SCF radio fields (public, type, reliure, disponibilite).
	 * Accepts a single slug or a comma-separated list — multi values become an OR clause.
	 */
	private function apply_scf_eq_filter( &$args, $atts, $att_key, $meta_key ) {
		$v = sanitize_text_field( $atts[ $att_key ] );
		if ( $v === '' ) return;
		$vals = array_values( array_filter( array_map( 'trim', explode( ',', $v ) ) ) );
		if ( empty( $vals ) ) return;
		if ( count( $vals ) === 1 ) {
			$args['meta_query'][] = [ 'key' => $meta_key, 'value' => $vals[0] ];
			return;
		}
		$clauses = [ 'relation' => 'OR' ];
		foreach ( $vals as $val ) {
			$clauses[] = [ 'key' => $meta_key, 'value' => $val ];
		}
		$args['meta_query'][] = $clauses;
	}

	/**
	 * Multi-value filter for SCF `langues` (checkbox field stored as a
	 * serialized PHP array). We can't compare equality on the blob, so for
	 * each selected language we LIKE-match `"<slug>"` (with quotes, to avoid
	 * sub-string matches) and OR the clauses.
	 */
	private function apply_langues_filter( &$args, $atts ) {
		$csv = sanitize_text_field( $atts['langues'] );
		if ( $csv === '' ) return;
		$vals = array_filter( array_map( 'trim', explode( ',', $csv ) ) );
		if ( empty( $vals ) ) return;

		$clauses = [ 'relation' => 'OR' ];
		foreach ( $vals as $v ) {
			$clauses[] = [
				'key'     => 'langues',
				'value'   => '"' . $v . '"',
				'compare' => 'LIKE',
			];
		}
		$args['meta_query'][] = $clauses;
	}

	/* ─── Sort & filter primitives ───────────────────────────────── */

	private function apply_sort( &$args, $atts ) {
		$orderby = sanitize_key( $atts['orderby'] );

		switch ( $orderby ) {
			case 'date':
				// SCF `date_de_parution` stored as Ymd, sortable lexicographically.
				// Books without a parution date drop out of the result.
				$args['meta_key'] = 'date_de_parution';
				$args['orderby']  = 'meta_value';
				break;
			case 'titre':
				// Title lives on post_title (the SCF `titre` field no longer exists).
				$args['orderby'] = 'title';
				break;
			case 'prix':
				$args['meta_key'] = '_price';
				$args['orderby']  = 'meta_value_num';
				break;
			case 'pages':
				$args['meta_key'] = 'nombre_de_pages';
				$args['orderby']  = 'meta_value_num';
				break;
			case 'hauteur':
				// Spine height is a computed render value (height_px), not a
				// DB-sortable column — the real ordering is applied after
				// prepare_books in render_shortcode(). Keep a neutral query
				// order here and, crucially, set NO meta_key (which would drop
				// books lacking that meta).
				$args['orderby'] = 'date';
				break;
			default:
				$args['orderby'] = $orderby;
		}
	}

	private function resolve_category_theme( $atts ) {
		if ( empty( $atts['category'] ) ) {
			return '';
		}
		$anchors = [ 'litterature', 'culture-sud-ouest' ];
		$slugs   = array_map( 'trim', explode( ',', $atts['category'] ) );
		foreach ( $slugs as $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( ! $term ) continue;
			if ( in_array( $term->slug, $anchors, true ) ) {
				return $term->slug;
			}
			foreach ( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, 'product_cat' );
				if ( $ancestor && in_array( $ancestor->slug, $anchors, true ) ) {
					return $ancestor->slug;
				}
			}
		}
		return '';
	}

	private function apply_category_tag( &$args, $atts ) {
		if ( ! empty( $atts['category'] ) ) {
			$args['tax_query'][] = [
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => array_map( 'trim', explode( ',', $atts['category'] ) ),
			];
		}

		if ( ! empty( $atts['tag'] ) ) {
			$args['tax_query'][] = [
				'taxonomy' => 'product_tag',
				'field'    => 'slug',
				'terms'    => array_map( 'trim', explode( ',', $atts['tag'] ) ),
			];
		}
	}

	/* ─── Auteur filter (exact term — for author pages) ──────────────
	 *
	 * Distinct from the search-by-name path (get_posts_by_author_name_like,
	 * left untouched): here we match EXACT `auteur` terms resolved from a
	 * slug or ID, to list every book a given author contributed to.
	 */

	private function apply_auteur_filter( &$args, $atts ) {
		$raw = sanitize_text_field( $atts['auteur'] );
		if ( $raw === '' ) return;

		$term_ids = $this->resolve_auteur_terms( $raw );
		if ( empty( $term_ids ) ) {
			$args['post__in'] = [ 0 ];
			return;
		}

		$roles = array_values( array_filter( array_map( 'sanitize_title', explode( ',', (string) $atts['role'] ) ) ) );
		$ids   = $this->get_product_ids_by_auteur( $term_ids, $roles );
		$this->intersect_post_in( $args, $ids );
	}

	/**
	 * Resolve a comma-separated list of `auteur` slugs and/or term IDs to
	 * term IDs. Unknown tokens are dropped.
	 */
	private function resolve_auteur_terms( $raw ) {
		$out = [];
		foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $tok ) {
			$term = ctype_digit( $tok )
				? get_term( (int) $tok, 'auteur' )
				: get_term_by( 'slug', $tok, 'auteur' );
			if ( $term && ! is_wp_error( $term ) ) {
				$out[] = (int) $term->term_id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Product IDs whose `contributions` repeater references one of the given
	 * `auteur` term IDs through the `fiche-auteur` sub-field. Optionally
	 * restricted to contributions of a given role (the sibling `type`
	 * sub-field of the SAME repeater row).
	 *
	 * `fiche-auteur` has save_terms:0, so the term is never set as a real
	 * `auteur` taxonomy term on the product — it lives only in postmeta
	 * (contributions_<i>_fiche-auteur). We match the three storage shapes that
	 * coexist from SCF saves and legacy imports:
	 *   plain int (759), serialized str (s:3:"759"), serialized int (i:759;).
	 */
	private function get_product_ids_by_auteur( array $term_ids, array $roles = [] ) {
		global $wpdb;

		$term_ids = array_values( array_unique( array_filter( array_map( 'intval', $term_ids ) ) ) );
		if ( empty( $term_ids ) ) return [];

		$or_clauses = [];
		$params     = [ 'contributions_%_fiche-auteur' ];
		foreach ( $term_ids as $tid ) {
			$or_clauses[] = '(a.meta_value = %d OR a.meta_value LIKE %s OR a.meta_value LIKE %s)';
			$params[]     = $tid;
			$params[]     = '%"' . $tid . '"%';   // s:N:"<id>"
			$params[]     = '%i:' . $tid . ';%';  // i:<id>;
		}

		$sql   = "SELECT DISTINCT a.post_id FROM {$wpdb->postmeta} a";
		$where = " WHERE a.meta_key LIKE %s AND ( " . implode( ' OR ', $or_clauses ) . " )";

		if ( ! empty( $roles ) ) {
			// Constrain to the same row's `type`: derive its meta_key from the
			// fiche-auteur key (contributions_<i>_fiche-auteur → contributions_<i>_type).
			$placeholders = implode( ',', array_fill( 0, count( $roles ), '%s' ) );
			$sql   .= " INNER JOIN {$wpdb->postmeta} t
				ON t.post_id = a.post_id
				AND t.meta_key = REPLACE( a.meta_key, '_fiche-auteur', '_type' )";
			$where .= " AND t.meta_value IN ( $placeholders )";
			$params = array_merge( $params, $roles );
		}

		$ids = $wpdb->get_col( $wpdb->prepare( $sql . $where, $params ) );
		return array_map( 'intval', $ids );
	}

	private function apply_oos_visibility( &$args ) {
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			$args['meta_query'][] = [
				'key'     => '_stock_status',
				'value'   => 'instock',
				'compare' => '=',
			];
		}
	}

	private function apply_decouvrir_filter( &$args, $atts ) {
		// sanitize_key strips hyphens; sanitize_title preserves them.
		$d = sanitize_title( $atts['decouvrir'] );
		switch ( $d ) {
			case 'nouveautes':
				$args['meta_query'][] = [
					'key'   => 'nouveaute',
					'value' => '1',
				];
				break;
			case 'prix-litteraires':
				// SCF repeater meta stores the row count as the field value.
				$args['meta_query'][] = [
					'key'     => 'distinctions',
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				];
				break;
			case 'a-paraitre':
				$args['meta_query'][] = [ 'key' => 'disponibilite', 'value' => 'a-paraitre' ];
				break;
		}
	}

	private function apply_format_filter( &$args, $atts ) {
		$format = sanitize_title( $atts['format'] );

		if ( $format === '' ) {
			// Default mode: dedupe — keep all standalone books (no format_groupe)
			// plus one representative per format_groupe.
			$ids = $this->get_default_format_ids();
			$this->intersect_post_in( $args, $ids );
			return;
		}

		if ( $format === 'tous' ) {
			// Tous formats confondus — no filter at all (no dedup, no slug filter).
			return;
		}

		if ( $format === 'classique' ) {
			$args['tax_query'][] = [
				'taxonomy' => 'pa_format_particulier',
				'operator' => 'NOT EXISTS',
			];
			return;
		}

		$args['tax_query'][] = [
			'taxonomy' => 'pa_format_particulier',
			'field'    => 'slug',
			'terms'    => $format,
		];
	}

	private function apply_search_filter( &$args, $atts ) {
		// rawurldecode() est un no-op sur du texte sans séquence %XX, donc sûr
		// aussi bien pour un $atts brut (get_filter_counts, tableau PHP direct)
		// que pour un $atts issu du parseur de shortcode (render_grid, valeur
		// rawurlencodée côté appelant — cf. commentaire class-catalogue.php).
		$term = trim( rawurldecode( (string) $atts['search'] ) );
		if ( $term === '' ) return;

		$ranked = $this->get_search_ranked_ids( $term );
		if ( empty( $ranked ) ) {
			$args['post__in'] = [ 0 ];
			return;
		}

		if ( ! empty( $args['post__in'] ) ) {
			$ranked = array_values( array_intersect( $ranked, $args['post__in'] ) );
			if ( empty( $ranked ) ) {
				$args['post__in'] = [ 0 ];
				return;
			}
		}

		$args['post__in'] = $ranked;
		$args['orderby']  = 'post__in';
		// Relevance ordering wins over any caller-supplied sort.
		unset( $args['meta_key'] );
	}

	/* ─── Format dedupe ──────────────────────────────────────────── */

	private function get_default_format_ids() {
		static $cache = null;
		if ( $cache !== null ) return $cache;

		// All standalone books (no format_groupe term).
		$sans_groupe = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => [ [
				'taxonomy' => 'format_groupe',
				'operator' => 'NOT EXISTS',
			] ],
		] );

		// One representative per format_groupe — résolus en UNE requête
		// (get_group_representatives_batch) au lieu d'un get_group_representative()
		// par terme (jusqu'à 5 get_posts chacun). Même règle de sélection.
		$representants = $this->get_group_representatives_batch();

		return $cache = array_merge( $sans_groupe, $representants );
	}

	/**
	 * Représentant de CHAQUE terme format_groupe, calculé en une seule requête,
	 * en remplacement d'un appel get_group_representative() par terme. Réplique
	 * exactement sa règle : édition classique (aucun terme pa_format_particulier)
	 * d'abord, sinon par priorité grands-caracteres → poche → numerique → audio ;
	 * à égalité, l'édition la plus récente (post_date DESC, comme le get_posts par
	 * défaut). Un groupe sans représentant publié éligible est ignoré (identique
	 * à l'ancien `if ( $rep )`).
	 */
	private function get_group_representatives_batch() {
		global $wpdb;

		// Toutes les appartenances format_groupe des produits publiés, avec le
		// slug pa_format_particulier de chaque édition (NULL = classique).
		$rows = $wpdb->get_results(
			"SELECT gtt.term_id AS group_id, p.ID AS product_id, ft.slug AS format_slug
			 FROM {$wpdb->term_relationships} gtr
			 INNER JOIN {$wpdb->term_taxonomy} gtt
				 ON gtt.term_taxonomy_id = gtr.term_taxonomy_id AND gtt.taxonomy = 'format_groupe'
			 INNER JOIN {$wpdb->posts} p
				 ON p.ID = gtr.object_id AND p.post_type = 'product' AND p.post_status = 'publish'
			 LEFT JOIN {$wpdb->term_relationships} ftr
				 ON ftr.object_id = p.ID
			 LEFT JOIN {$wpdb->term_taxonomy} ftt
				 ON ftt.term_taxonomy_id = ftr.term_taxonomy_id AND ftt.taxonomy = 'pa_format_particulier'
			 LEFT JOIN {$wpdb->terms} ft
				 ON ft.term_id = ftt.term_id
			 ORDER BY p.post_date DESC, p.ID DESC"
		);

		// group_id => [ product_id => format_slug|'' ], ordre = post_date DESC.
		$groups = [];
		foreach ( $rows as $r ) {
			$gid  = (int) $r->group_id;
			$pid  = (int) $r->product_id;
			$slug = (string) ( $r->format_slug ?? '' );
			if ( ! isset( $groups[ $gid ][ $pid ] ) || ( $groups[ $gid ][ $pid ] === '' && $slug !== '' ) ) {
				$groups[ $gid ][ $pid ] = $slug;
			}
		}

		$priority = [ 'grands-caracteres', 'poche', 'numerique', 'audio' ];
		$reps     = [];
		foreach ( $groups as $members ) {
			$rep = 0;
			// 1. Édition classique : aucun terme pa_format_particulier (slug vide).
			foreach ( $members as $pid => $slug ) {
				if ( $slug === '' ) { $rep = $pid; break; }
			}
			// 2. Sinon, par ordre de priorité de format.
			if ( ! $rep ) {
				foreach ( $priority as $want ) {
					foreach ( $members as $pid => $slug ) {
						if ( $slug === $want ) { $rep = $pid; break 2; }
					}
				}
			}
			if ( $rep ) $reps[] = (int) $rep;
		}

		return $reps;
	}

	/**
	 * Pick the representative book of a format_groupe term:
	 *  1. the classique edition (no pa_format_particulier term)
	 *  2. fallback by priority: grands-caracteres → poche → numerique → audio
	 */
	public static function get_group_representative( $term_id ) {
		$classique = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'tax_query'      => [
				'relation' => 'AND',
				[ 'taxonomy' => 'format_groupe', 'terms' => $term_id ],
				[ 'taxonomy' => 'pa_format_particulier', 'operator' => 'NOT EXISTS' ],
			],
		] );
		if ( $classique ) return (int) $classique[0];

		foreach ( [ 'grands-caracteres', 'poche', 'numerique', 'audio' ] as $slug ) {
			$fallback = get_posts( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'tax_query'      => [
					'relation' => 'AND',
					[ 'taxonomy' => 'format_groupe', 'terms' => $term_id ],
					[ 'taxonomy' => 'pa_format_particulier', 'field' => 'slug', 'terms' => $slug ],
				],
			] );
			if ( $fallback ) return (int) $fallback[0];
		}

		return null;
	}

	private function intersect_post_in( &$args, $ids ) {
		if ( empty( $ids ) ) {
			$args['post__in'] = [ 0 ];
			return;
		}
		if ( ! empty( $args['post__in'] ) ) {
			$args['post__in'] = array_values( array_intersect( $args['post__in'], $ids ) );
			if ( empty( $args['post__in'] ) ) $args['post__in'] = [ 0 ];
		} else {
			$args['post__in'] = $ids;
		}
	}

	/* ─── Search (title > author/tag) ────────────────────────────── */

	/**
	 * Recherche tolérante (casse, accents, caractères spéciaux, fautes de
	 * frappe) déléguée au cœur partagé inc/search.php, qui range les résultats
	 * sur le titre d'abord puis l'index complet (auteurs + étiquettes).
	 */
	private function get_search_ranked_ids( $term ) {
		return pf_search_products_ranked( $term );
	}

	/* ─── Filter counts (public API for future UI) ───────────────── */

	/**
	 * For each option of every filter group, return the number of books
	 * that would be returned if the user picked that option — keeping
	 * every OTHER filter active but ignoring the current value of the
	 * same group ("independent per group" counting).
	 *
	 * Output shape:
	 *   [ 'format' => ['' => N, 'tous' => N, 'classique' => N, ...],
	 *     'category' => ['' => N, 'culture-sud-ouest' => N, ...],
	 *     ... ]
	 */
	public function get_filter_counts( $atts = [] ) {
		$atts = shortcode_atts( $this->default_atts(), $atts, 'passiflore_etagere' );

		return [
			'format'    => $this->count_options( $atts, 'format',    $this->get_format_options() ),
			'category'  => $this->count_options( $atts, 'category',  $this->get_term_option_slugs( 'product_cat' ) ),
			'public'    => $this->count_options( $atts, 'public',    $this->get_scf_choice_slugs( 'public' ) ),
			'type'      => $this->count_options( $atts, 'type',      $this->get_scf_choice_slugs( 'type' ) ),
			'langues'   => $this->count_options( $atts, 'langues',   $this->get_scf_choice_slugs( 'langues' ) ),
			'decouvrir' => $this->count_options( $atts, 'decouvrir', [ '', 'nouveautes', 'prix-litteraires', 'a-paraitre' ] ),
		];
	}

	private function count_options( $atts, $key, $options ) {
		$out = [];
		foreach ( $options as $opt ) {
			$variant         = $atts;
			$variant[ $key ] = $opt;
			$args            = $this->build_query_args( $variant );
			$args['fields']  = 'ids';
			$out[ $opt ]     = count( get_posts( $args ) );
		}
		return $out;
	}

	private function get_format_options() {
		$options = [ '', 'tous', 'classique' ];
		$terms   = get_terms( [
			'taxonomy'   => 'pa_format_particulier',
			'hide_empty' => true,
			'fields'     => 'slugs',
		] );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $slug ) {
				if ( ! in_array( $slug, $options, true ) ) $options[] = $slug;
			}
		}
		return $options;
	}

	private function get_term_option_slugs( $taxonomy ) {
		$out   = [ '' ];
		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
			'fields'     => 'slugs',
		] );
		if ( ! is_wp_error( $terms ) ) {
			$out = array_merge( $out, $terms );
		}
		return $out;
	}

	private function get_scf_choice_slugs( $field_name ) {
		$out     = [ '' ];
		$choices = $this->scf_choices_cached( $field_name );
		foreach ( array_keys( $choices ) as $slug ) {
			$out[] = (string) $slug;
		}
		return $out;
	}

	/**
	 * SCF field keys for fields that have name collisions (e.g. `type` is
	 * both the book type and the contribution type inside a repeater).
	 * Hardcoding keys disambiguates and survives field name changes.
	 */
	const SCF_FIELD_KEYS = [
		'public'          => 'field_69c7e9737ff5b',
		'type'            => 'field_69ca4932f8174',
		'type_de_reliure' => 'field_69c7e9047ff5a',
		'langues'         => 'field_69c7e7d37ff58',
		'disponibilite'   => 'field_69ca3388cd306',
	];

	private function scf_choices_cached( $field_name ) {
		static $cache = [];
		if ( isset( $cache[ $field_name ] ) ) return $cache[ $field_name ];
		if ( ! function_exists( 'get_field_object' ) ) return $cache[ $field_name ] = [];

		// Pick any product as a context for the SCF field lookup.
		$ctx_ids = get_posts( [
			'post_type'      => 'product',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		$ctx = $ctx_ids ? $ctx_ids[0] : 0;

		// Use the field key (more reliable than the field name) when known.
		$selector = self::SCF_FIELD_KEYS[ $field_name ] ?? $field_name;
		$obj      = get_field_object( $selector, $ctx );
		$choices  = ( is_array( $obj ) && ! empty( $obj['choices'] ) ) ? $obj['choices'] : [];
		return $cache[ $field_name ] = $choices;
	}

	/**
	 * Public helper: returns the labels for SCF choices of a given field,
	 * keyed by slug. Used by the catalogue UI to render dropdown labels.
	 */
	public function get_scf_choices( $field_name ) {
		return $this->scf_choices_cached( $field_name );
	}

	private function prepare_books( $products, $display = 'covers', $is_hero = false ) {
		$books = [];
		// Spines mode renders titles vertically, so we scale everything up
		// to keep them legible. 1.5× gives roughly readable type at typical
		// shelf widths while still fitting plenty of books per row.
		$mode_scale = ( $display === 'spines' ) ? 1.5 : 1.0;
		$scale      = self::SCALE * $mode_scale;
		$unit       = get_option( 'woocommerce_dimension_unit', 'cm' );

		// Le mode héros (fiche livre) affiche UN livre bien plus grand → images
		// plus définies (medium_large / large). L'étagère générique (catalogue,
		// accueil…) sert les sous-tailles allégées dédiées. En mode spines, la
		// couverture n'est révélée qu'au survol (chargement en data-src, cf.
		// bookshelf.js) et affichée ~1.5× plus grande que son équivalent en
		// covers (mode_scale) : pf-shelf-cover (400×600) y serait insuffisante
		// et rendrait un agrandissement flou. On réutilise medium_large (déjà
		// générée pour toute la médiathèque, contrairement à une sous-taille
		// dédiée) sans coût perf, la couverture n'étant chargée que pour le
		// livre réellement survolé.
		$cover_size   = ( $is_hero || $display === 'spines' ) ? 'medium_large' : self::SHELF_COVER_SIZE;
		$tranche_size = $is_hero ? 'large' : self::SHELF_SPINE_SIZE;

		// Amorçage groupé des attachments (couvertures + tranches). Sans ça, les
		// wp_get_attachment_image_url/_src de la boucle déclenchent 1-2 requêtes
		// par image (les vignettes ne sont pas dans le cache de la requête
		// produits) → N+1. Un seul _prime_post_caches charge posts + métas d'un
		// coup ; les IDs eux-mêmes se lisent en cache (méta produit déjà amorcée).
		$att_ids = [];
		foreach ( $products as $post ) {
			$tid = get_post_thumbnail_id( $post->ID );
			if ( $tid ) $att_ids[] = (int) $tid;
			$tranche = (int) get_post_meta( $post->ID, 'tranche', true );
			if ( $tranche ) $att_ids[] = $tranche;
		}
		$att_ids = array_values( array_unique( $att_ids ) );
		if ( $att_ids ) {
			_prime_post_caches( $att_ids, false, true );
		}

		foreach ( $products as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) continue;

			$thumb_id   = get_post_thumbnail_id( $post->ID );

			// Termes de format lus une seule fois, réutilisés pour is_ereader /
			// is_cd ET le libellé de format plus bas (au lieu de 2 has_term +
			// 1 get_the_terms). Le cache de termes des produits est déjà amorcé
			// par la requête d'étagère (update_post_term_cache).
			$format_terms = get_the_terms( $post->ID, 'pa_format_particulier' );
			$format_slugs = ( ! empty( $format_terms ) && ! is_wp_error( $format_terms ) )
				? wp_list_pluck( $format_terms, 'slug' )
				: [];
			$is_ereader = in_array( 'numerique', $format_slugs, true );
			$is_cd      = in_array( 'audio', $format_slugs, true );

			if ( $is_ereader ) {
				// Liseuse : hauteur d'appareil fixe, pas de tranche papier ni
				// d'épaisseur dérivée des pages. La largeur est calculée plus
				// bas, en px, à partir du ratio de la couverture.
				$height_mm  = self::EREADER_HEIGHT_MM;
				$width_mm   = self::DEFAULT_WIDTH_MM; // recalculé en px ci-dessous
				$spine_mm   = self::EREADER_SPINE_MM;
				$tranche_id = 0;
			} elseif ( $is_cd ) {
				// Boîtier CD : dimensions de boîtier cristal standard.
				$height_mm  = self::CD_HEIGHT_MM;
				$width_mm   = self::CD_WIDTH_MM;
				$spine_mm   = self::CD_SPINE_MM;
				$tranche_id = 0;
			} else {
				$height_mm = $this->wc_dim_to_mm( $product->get_height(), $unit );
				$width_mm  = $this->wc_dim_to_mm( $product->get_width(), $unit );

				// If dimensions are missing (typically for digital/audio variants),
				// inherit from the base book sharing the same ISBN.
				if ( ! $height_mm || ! $width_mm ) {
					$base = $this->get_base_dimensions( $product, $unit );
					if ( $base ) {
						if ( ! $height_mm ) $height_mm = $base['height_mm'];
						if ( ! $width_mm )  $width_mm  = $base['width_mm'];
					}
				}

				if ( ! $height_mm ) $height_mm = self::DEFAULT_HEIGHT_MM;
				if ( ! $width_mm ) {
					if ( $thumb_id ) {
						$img = wp_get_attachment_image_src( $thumb_id, 'medium_large' );
						if ( $img && $img[1] && $img[2] ) {
							$width_mm = round( $height_mm * ( $img[1] / $img[2] ) );
							$width_mm = max( 90, min( 300, $width_mm ) );
						}
					}
					if ( ! $width_mm ) $width_mm = self::DEFAULT_WIDTH_MM;
				}

				$height_mm = max( self::MIN_HEIGHT_MM, min( self::MAX_HEIGHT_MM, $height_mm ) );
				$width_mm  = max( self::MIN_WIDTH_MM,  min( self::MAX_WIDTH_MM,  $width_mm ) );

				// Lecture directe en post meta : `tranche` (return_format=id) et
				// `nombre_de_pages` stockent une valeur brute, le formatage SCF de
				// get_field() est inutile ici (méta déjà en cache via la requête).
				$tranche_id = (int) get_post_meta( $post->ID, 'tranche', true );
				$pages      = absint( get_post_meta( $post->ID, 'nombre_de_pages', true ) );

				$spine_mm    = 0;
				$from_image  = false;
				if ( $tranche_id ) {
					$img = wp_get_attachment_image_src( $tranche_id, 'full' );
					if ( $img && $img[1] && $img[2] ) {
						$spine_mm   = round( $height_mm * ( $img[1] / $img[2] ) );
						$from_image = true;
					}
				}
				if ( ! $spine_mm && $pages ) {
					$spine_mm = round( $pages * self::MM_PER_PAGE );
				}
				if ( ! $spine_mm ) {
					$spine_mm = 15;
				}
				// Only clamp the page-derived fallback. When a tranche image exists,
				// preserve its aspect ratio exactly so it renders without cropping.
				if ( ! $from_image ) {
					$spine_mm = max( self::MIN_SPINE_MM, min( self::MAX_SPINE_MM, $spine_mm ) );
				}
			}

			$height_px  = (int) round( $height_mm * $scale );
			$cover_w_px = (int) round( $width_mm * $scale );
			$spine_w_px = (int) round( $spine_mm * $scale );

			if ( $is_ereader ) {
				// L'écran (hauteur − bezels) épouse le ratio de la couverture,
				// qui s'affiche donc en entier sans letterbox.
				$ratio = 0.68;
				if ( $thumb_id ) {
					$img = wp_get_attachment_image_src( $thumb_id, 'medium_large' );
					if ( $img && $img[1] && $img[2] ) {
						$ratio = $img[1] / $img[2];
					}
				}
				$ratio      = max( 0.5, min( 1.0, $ratio ) );
				$screen_h   = $height_px - self::EREADER_BEZEL_PX - self::EREADER_CHIN_PX;
				$cover_w_px = (int) round( $screen_h * $ratio ) + 2 * self::EREADER_BEZEL_PX;
			}

			$thumb_url   = $thumb_id ? wp_get_attachment_image_url( $thumb_id, $cover_size ) : '';
			$tranche_url = $tranche_id ? wp_get_attachment_image_url( $tranche_id, $tranche_size ) : '';
			$spine_color = ( $tranche_id || $is_ereader ) ? '' : $this->extract_cover_color( $thumb_id );

			// « À paraître » = valeur brute du champ SCF `disponibilite`
			// (slug stocké tel quel, cf. apply_decouvrir_filter).
			$is_aparaitre = ( 'a-paraitre' === get_post_meta( $post->ID, 'disponibilite', true ) );
			$is_nouveaute = ( '1' === (string) get_post_meta( $post->ID, 'nouveaute', true ) );

			// $format_terms déjà résolu en tête de boucle (cf. is_ereader/is_cd).
			$format_label = ( ! empty( $format_terms ) && ! is_wp_error( $format_terms ) ) ? $format_terms[0]->name : 'Classique';

			// Prix : on supprime les centimes quand ils valent zéro (12,00 € → 12 €).
			// WooCommerce l'applique via le filtre woocommerce_price_trim_zeros,
			// posé/retiré autour de get_price_html() pour ne pas affecter le reste du site.
			add_filter( 'woocommerce_price_trim_zeros', '__return_true' );
			$price_html = $product->get_price_html();
			remove_filter( 'woocommerce_price_trim_zeros', '__return_true' );

			$books[] = [
				'id'           => $post->ID,
				'url'          => get_permalink( $post->ID ),
				'title'        => $product->get_name(),
				'cover_url'    => $thumb_url,
				'tranche_url'  => $tranche_url,
				'spine_color'  => $spine_color,
				'price'        => $price_html,
				'height_px'    => $height_px,
				'cover_w_px'   => $cover_w_px,
				'spine_w_px'   => $spine_w_px,
				'is_ereader'   => $is_ereader,
				'is_cd'        => $is_cd,
				'is_aparaitre' => $is_aparaitre,
				'is_nouveaute' => $is_nouveaute,
				'format_label' => $format_label,
			];
		}

		return $books;
	}

	/**
	 * Look up a physical sibling (same ISBN, `pa_format_particulier`
	 * neither `numerique` nor `audio`) and return its dimensions in mm.
	 * Used as a fallback for digital/audio variants that don't have their
	 * own physical dimensions. Matches the classic edition, grands
	 * caractères, or poche — whichever shares the ISBN.
	 *
	 * Cached per request, keyed by ISBN.
	 */
	private function get_base_dimensions( $product, $unit ) {
		static $cache = [];

		$isbn = $product->get_meta( '_global_unique_id' );
		if ( ! $isbn ) return null;

		if ( array_key_exists( $isbn, $cache ) ) {
			return $cache[ $isbn ];
		}

		$base_ids = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'post__not_in'   => [ $product->get_id() ],
			'meta_query'     => [ [
				'key'   => '_global_unique_id',
				'value' => $isbn,
			] ],
			'tax_query'      => [ [
				'taxonomy' => 'pa_format_particulier',
				'field'    => 'slug',
				'terms'    => [ 'numerique', 'audio' ],
				'operator' => 'NOT IN',
			] ],
			'no_found_rows'  => true,
		] );

		if ( empty( $base_ids ) ) {
			return $cache[ $isbn ] = null;
		}

		$base = wc_get_product( $base_ids[0] );
		if ( ! $base ) {
			return $cache[ $isbn ] = null;
		}

		$h = $this->wc_dim_to_mm( $base->get_height(), $unit );
		$w = $this->wc_dim_to_mm( $base->get_width(), $unit );

		if ( ! $h && ! $w ) {
			return $cache[ $isbn ] = null;
		}

		return $cache[ $isbn ] = [
			'height_mm' => $h,
			'width_mm'  => $w,
		];
	}

	private function wc_dim_to_mm( $value, $unit ) {
		$value = (float) $value;
		if ( $value <= 0 ) return 0;
		switch ( $unit ) {
			case 'mm': return (int) round( $value );
			case 'cm': return (int) round( $value * 10 );
			case 'in': return (int) round( $value * 25.4 );
			case 'm':  return (int) round( $value * 1000 );
			case 'yd': return (int) round( $value * 914.4 );
		}
		return (int) round( $value * 10 );
	}

	private function extract_cover_color( $attachment_id ) {
		if ( ! $attachment_id ) return self::PASSIFLORE_RED;

		$cached = get_post_meta( $attachment_id, self::COLOR_META_KEY, true );
		if ( $cached ) return $cached;

		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return self::PASSIFLORE_RED;
		}

		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) return self::PASSIFLORE_RED;

		$type = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$im   = null;
		switch ( $type ) {
			case 'jpg':
			case 'jpeg':
				if ( function_exists( 'imagecreatefromjpeg' ) ) $im = @imagecreatefromjpeg( $path );
				break;
			case 'png':
				if ( function_exists( 'imagecreatefrompng' ) ) $im = @imagecreatefrompng( $path );
				break;
			case 'webp':
				if ( function_exists( 'imagecreatefromwebp' ) ) $im = @imagecreatefromwebp( $path );
				break;
			case 'gif':
				if ( function_exists( 'imagecreatefromgif' ) ) $im = @imagecreatefromgif( $path );
				break;
		}
		if ( ! $im ) return self::PASSIFLORE_RED;

		$small = imagecreatetruecolor( 1, 1 );
		imagecopyresampled( $small, $im, 0, 0, 0, 0, 1, 1, imagesx( $im ), imagesy( $im ) );
		$rgb = imagecolorat( $small, 0, 0 );
		imagedestroy( $small );
		imagedestroy( $im );

		$r = ( $rgb >> 16 ) & 0xFF;
		$g = ( $rgb >> 8 ) & 0xFF;
		$b = $rgb & 0xFF;
		$hex = sprintf( '#%02x%02x%02x', $r, $g, $b );

		update_post_meta( $attachment_id, self::COLOR_META_KEY, $hex );
		return $hex;
	}

	/* ─── Render Modes ───────────────────────────────────────────── */

	private function render_scroll( $books, $display, $show_price, $shelf_inner, $nb_first = 12, $is_hero = false, $cat_theme = '', $show_formats = false, $libraires_url = '', $show_bookmarks = false ) {
		// En spines le chevalet n'apparaît qu'au survol (superposé) :
		// seule la grille covers réserve de la hauteur pour lui.
		$has_chevalet = $display === 'covers' && $show_price;
		$chevalet_h  = $has_chevalet ? 32 : 0;
		$shelf_h     = $shelf_inner + $chevalet_h;
		$hero_class  = $is_hero ? ' pf-bookshelf--hero' : '';
		$cat_class   = $cat_theme ? ' pf-bookshelf--cat-' . esc_attr( $cat_theme ) : '';
		$price_class = $has_chevalet ? ' pf-bookshelf--show-price' : '';

		$html  = '<div class="pf-bookshelf pf-bookshelf--scroll' . $hero_class . $cat_class . $price_class . ' pf-bookshelf--' . esc_attr( $display ) . '">';
		$html .= '<div class="pf-shelf" style="--shelf-inner:' . $shelf_h . 'px;">';
		$html .= '<div class="pf-shelf-inner"><div class="pf-shelf-books">';

		$index = 0;
		foreach ( $books as $b ) {
			$html .= $this->render_book( $b, $display, $show_price, $index, $nb_first, $is_hero, $show_formats, $show_bookmarks );
			$index++;
		}

		$html .= '</div><div class="pf-shelf-plank"></div>' . $this->render_libraires_link( $libraires_url ) . '</div></div></div>';
		return $html;
	}

	/**
	 * Lien « Voir sur Place des libraires » — mode hero uniquement, rendu dans
	 * .pf-shelf juste sous .pf-shelf-plank (cf. render_scroll()/render_shelves()).
	 */
	private function render_libraires_link( $libraires_url ) {
		if ( ! $libraires_url ) return '';
		return '<a class="bs-hero__libraires" href="' . esc_url( $libraires_url ) . '" target="_blank" rel="noopener noreferrer">Voir sur Place des libraires</a>';
	}

	private function render_shelves( $books, $display, $show_price, $shelf_inner, $per_shelf, $nb_first = 12, $is_hero = false, $cat_theme = '', $show_formats = false, $libraires_url = '', $show_bookmarks = false ) {
		$has_chevalet = $display === 'covers' && $show_price;
		$chevalet_h  = $has_chevalet ? 32 : 0;
		$hero_class  = $is_hero ? ' pf-bookshelf--hero' : '';
		$cat_class   = $cat_theme ? ' pf-bookshelf--cat-' . esc_attr( $cat_theme ) : '';
		$price_class = $has_chevalet ? ' pf-bookshelf--show-price' : '';

		// `data-fixed-rows` : un per_shelf explicite gèle la répartition —
		// le re-packing adaptatif JS (bookshelf.js) ignore ces étagères.
		$fixed = $per_shelf > 0 ? ' data-fixed-rows="1"' : '';
		$html  = '<div class="pf-bookshelf pf-bookshelf--shelves' . $hero_class . $cat_class . $price_class . ' pf-bookshelf--' . esc_attr( $display ) . '"' . $fixed . '>';

		if ( $per_shelf > 0 ) {
			$shelves = array_chunk( $books, $per_shelf );
		} else {
			$max_shelf_width = 1100;
			$shelves         = [];
			$current_shelf   = [];
			$current_width   = 0;
			$gap             = ( $display === 'spines' ) ? 1 : 18;
			$padding         = 60;

			foreach ( $books as $b ) {
				$occupied = ( $display === 'spines' )
					? $b['spine_w_px']
					: ( $b['cover_w_px'] + 2 * $b['spine_w_px'] );
				$book_total_w = $occupied + $gap;
				if ( $current_width + $book_total_w > $max_shelf_width - $padding && ! empty( $current_shelf ) ) {
					$shelves[]     = $current_shelf;
					$current_shelf = [];
					$current_width = 0;
				}
				$current_shelf[] = $b;
				$current_width  += $book_total_w;
			}
			if ( ! empty( $current_shelf ) ) {
				$shelves[] = $current_shelf;
			}
		}

		$index = 0;
		foreach ( $shelves as $shelf_books ) {
			$max_h = 0;
			foreach ( $shelf_books as $b ) {
				if ( $b['height_px'] > $max_h ) $max_h = $b['height_px'];
			}
			$this_shelf_h = $max_h + 20 + $chevalet_h;

			$html .= '<div class="pf-shelf" style="--shelf-inner:' . $this_shelf_h . 'px;">';
			$html .= '<div class="pf-shelf-books">';
			foreach ( $shelf_books as $b ) {
				$html .= $this->render_book( $b, $display, $show_price, $index, $nb_first, $is_hero, $show_formats, $show_bookmarks );
				$index++;
			}
			$html .= '</div><div class="pf-shelf-plank"></div>' . $this->render_libraires_link( $libraires_url ) . '</div>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Unified renderer. Same DOM in both display modes; CSS handles the
	 * mode-specific positioning of each face (cover, spine, pages, inside).
	 */
	private function render_book( $b, $display, $show_price, $index = 0, $nb_first = 12, $is_hero = false, $show_formats = false, $show_bookmarks = false ) {
		// Frozen reference values used by the hover transition:
		//   --spine-w-base / --cover-w-base / --book-h-base  → originals
		//   --book-total-w                                   → cover+spine
		// CSS shrinks the spine toward a fraction of its base, lets the
		// cover absorb the freed width (so the footprint stays constant),
		// and grows --book-h proportionally to --cover-w so the cover
		// image keeps its natural aspect ratio during the animation.
		$style = sprintf(
			'--cover-w:%dpx;--spine-w:%dpx;--book-h:%dpx;--cover-w-base:%dpx;--spine-w-base:%dpx;--book-h-base:%dpx;--book-total-w:%dpx;',
			$b['cover_w_px'], $b['spine_w_px'], $b['height_px'],
			$b['cover_w_px'], $b['spine_w_px'], $b['height_px'],
			$b['cover_w_px'] + $b['spine_w_px']
		);

		// Le prix (chevalet) n'existe qu'en covers : en spines les tranches
		// sont trop étroites pour le porter, et on ne l'affiche pas non plus
		// sur le livre saisi.
		$with_price   = ( $show_price && $b['price'] && $display === 'covers' );
		$with_formats = ( $show_formats && ! empty( $b['format_label'] ) && $display === 'covers' );
		$is_ereader = ! empty( $b['is_ereader'] );
		$is_cd      = ! empty( $b['is_cd'] );

		$has_extract = false;
		if ( $is_hero && function_exists( 'get_field' ) ) {
			$has_extract = ! empty( get_field( 'extrait', $b['id'] ) );
		}

		$classes = 'pf-book';
		if ( $with_price ) {
			$classes .= ' pf-book--has-price';
		} elseif ( $display === 'covers' && ( ! empty( $b['is_aparaitre'] ) || ! empty( $b['is_nouveaute'] ) || $with_formats ) ) {
			$classes .= ' pf-book--has-shelf-label';
		}
		if ( $is_ereader ) {
			$classes .= ' pf-book--ereader';
		}
		if ( $is_cd ) {
			$classes .= ' pf-book--cd';
		}
		if ( $has_extract ) {
			$classes .= ' pf-book--has-extract';
		}

		$sr_label = $b['title'];
		if ( $with_price ) {
			$sr_label .= ' — ' . wp_strip_all_tags( $b['price'] );
		} elseif ( $with_formats ) {
			$sr_label .= ' — ' . $b['format_label'];
		}

		// First N books get priority loading (eager + high fetch priority);
		// the rest are lazy. N is set per shortcode via `nb_books_first_displayed`.
		$is_priority   = ( $index < (int) $nb_first );
		$primary_attrs = $is_priority
			? 'loading="eager" fetchpriority="high"'
			: 'loading="lazy"';

		// Explicit width/height on every <img> so lazy placeholders reserve
		// the right amount of space (CLS = 0). Cover dimensions match
		// --cover-w / --book-h ; the tranche image inherits cover height
		// and is as wide as the spine.
		$cover_dims   = sprintf( 'width="%d" height="%d"', $b['cover_w_px'], $b['height_px'] );
		$tranche_dims = sprintf( 'width="%d" height="%d"', $b['spine_w_px'], $b['height_px'] );

		if ( $is_hero ) {
			$aria  = $has_extract ? esc_attr( "Feuilleter l’extrait" ) : esc_attr( $b['title'] );
			$html  = '<div role="button" tabindex="0" data-trigger-flipbook="1"';
			$html .= ' class="' . esc_attr( $classes ) . '"';
			$html .= ' style="' . esc_attr( $style ) . '"';
			$html .= ' aria-label="' . $aria . '">';
		} else {
			$html = '<a href="' . esc_url( $b['url'] ) . '" class="' . esc_attr( $classes ) . '" style="' . esc_attr( $style ) . '" aria-label="' . esc_attr( $sr_label ) . '">';
		}
		$html .= '<div class="pf-book-inner">';

		// White interior — only used by covers mode (revealed when the cover
		// swings open on hover). Hidden in spines mode via CSS. La liseuse
		// n'a pas de couverture qui s'ouvre, donc pas d'intérieur.
		if ( ! $is_ereader ) {
			$hint = ( $is_hero && $has_extract )
				? '<div class="pf-hero-flip-hint" aria-hidden="true">'
					. '<span class="pf-hero-flip-hint__text">EXTRAIT</span>'
					. '<span class="pf-hero-flip-hint__arrow">&#x2192;</span>'
					. '</div>'
				: '';
			$html .= '<div class="pf-book-inside">' . $hint . '</div>';
		}

		// Spine face: tranche image OR generated (cover-extracted color +
		// vertical title + logo Passiflore) OR chant de liseuse. The generated
		// rendering is used in both modes; CSS hides title/icon in covers mode
		// where the spine is just a thin parallelogram. En covers, la liseuse
		// est une ardoise plate : ni tranche ni bandeau de pages.
		$tranche_attrs = ( $display === 'spines' ) ? $primary_attrs : 'loading="lazy"';
		if ( ! ( $is_ereader && $display === 'covers' ) ) {
			$html .= '<div class="pf-book-spine">' . $this->spine_face_html( $b, $tranche_attrs, $tranche_dims ) . '</div>';
		}

		// Cover face. In spines mode the cover is hidden until hover, so we
		// defer fetching to first hover (data-src swapped in by JS).
		// Pour les liseuses, l'image est dans .pf-ereader-screen (fond blanc,
		// overflow:hidden) qui clipe la rotation sans bouger le cadre device.
		$html .= '<div class="pf-book-cover">';
		if ( $is_ereader ) {
			$html .= '<div class="pf-ereader-screen">';
			if ( $is_hero && $has_extract ) {
				$html .= '<div class="pf-hero-flip-hint pf-hero-flip-hint--screen" aria-hidden="true">'
					. '<span class="pf-hero-flip-hint__text">EXTRAIT</span>'
					. '<span class="pf-hero-flip-hint__arrow">&#x2192;</span>'
					. '</div>';
			}
		}
		if ( $b['cover_url'] ) {
			if ( $display === 'spines' ) {
				$html .= '<img data-src="' . esc_url( $b['cover_url'] ) . '" alt="' . esc_attr( $b['title'] ) . '" ' . $cover_dims . ' draggable="false" />';
			} else {
				$html .= '<img src="' . esc_url( $b['cover_url'] ) . '" alt="' . esc_attr( $b['title'] ) . '" ' . $primary_attrs . ' ' . $cover_dims . ' draggable="false" />';
			}
		} else {
			$html .= '<div class="pf-book-nocover"></div>';
		}
		// Signet « liste de lecture » — posé sur la COUVERTURE. Pour une liseuse,
		// la couverture est l'image dans .pf-ereader-screen (pas le cadre device)
		// → on l'émet AVANT la fermeture de l'écran ; pour un livre normal il
		// reste dans .pf-book-cover, juste après l'image. Tourne avec la couverture
		// au survol ; l'infobulle est un flottant JS global (shelf-bookmarks.js).
		// Rendu seulement si demandé (déjà borné covers + non-héros + connecté).
		if ( $show_bookmarks ) {
			$html .= $this->bookmark_html( $b );
		}

		if ( $is_ereader ) {
			$html .= '</div>'; // .pf-ereader-screen
			// Pastille de marque dans le menton de la liseuse.
			$html .= '<span class="pf-ereader-brand">' . $this->spine_icon_html() . '</span>';
		}

		$html .= '</div>';

		// Top face: page edges. CSS gives it a 3D rotation in spines mode
		// and a clip-path parallelogram in covers mode.
		if ( ! $is_ereader ) {
			$html .= '<div class="pf-book-pages"></div>';
		}

		$html .= '</div>';

		// Décor « livre en main » (spines uniquement) : réplique 2D de
		// l'anatomie covers — tranche biseautée à gauche + bandeau de pages
		// en haut. Au repos la tranche fantôme recouvre exactement la tranche
		// réelle ; pendant la saisie elle glisse/biseaute vers sa place covers
		// et le bandeau se déploie, en synchronisation avec la rotation du
		// volume. Frère de .pf-book-inner : il ne tourne pas avec le volume
		// et ne suit pas l'ouverture de la couverture. La liseuse (ardoise
		// plate) n'en a pas besoin.
		if ( $display === 'spines' && ! $is_hero && ! $is_ereader ) {
			$html .= '<div class="pf-book-held-deco" aria-hidden="true">'
				. '<div class="pf-spine-ghost">' . $this->spine_face_html( $b, 'loading="lazy"', $tranche_dims ) . '</div>'
				. '<div class="pf-pages-ghost"></div>'
				. '</div>';
		}

		if ( $with_price ) {
			$html .= '<div class="pf-chevalet"><div class="pf-chevalet-card"><span class="pf-chevalet-price">' . $b['price'] . '</span></div></div>';
		}

		// Étiquette sur le chant de la planche, uniquement en covers.
		// Priorité : À paraître > Nouveauté > Format.
		if ( $display === 'covers' ) {
			if ( ! empty( $b['is_aparaitre'] ) ) {
				$html .= '<span class="pf-book-shelf-label">' . esc_html__( 'À paraître', 'kadence-child' ) . '</span>';
			} elseif ( ! empty( $b['is_nouveaute'] ) ) {
				$html .= '<span class="pf-book-shelf-label">' . esc_html__( 'Nouveauté', 'kadence-child' ) . '</span>';
			} elseif ( $with_formats ) {
				$html .= '<span class="pf-book-shelf-label pf-book-shelf-label--format">' . esc_html( $b['format_label'] ) . '</span>';
			}
		}

		// Badge + explication de recommandation (espace compte). Enfant de .pf-book
		// (hors couverture) : le badge flotte AU-DESSUS du livre — il ne suit ni le
		// scale ni la rotation. En dos, il est simplement plus petit et centré sur
		// le dos (bookshelf.css), et l'explication s'affiche en carte flottante.
		// L'explication s'ouvre au clic (le « ? » devient une croix) et se referme
		// au clic (croix / explication / extérieur) — assets/js/account-reco.js.
		if ( isset( self::$reco_annotations[ $b['id'] ] ) ) {
			$ann    = self::$reco_annotations[ $b['id'] ];
			$why    = isset( $ann['why'] ) ? (string) $ann['why'] : '';
			$tip_id = 'pf-reco-tip-' . (int) $b['id'];
			$html  .= '<div class="pf-book-reco" data-score="' . esc_attr( $ann['score'] ?? '' ) . '">';
			$html  .= '<span class="pf-book-reco-badge pf-roundbtn pf-roundbtn--secondary" tabindex="0"'
				. ( $why ? ' aria-describedby="' . esc_attr( $tip_id ) . '"' : '' )
				. ' aria-label="' . esc_attr__( 'Pourquoi cette suggestion ?', 'kadence-child' ) . '">'
				. '<span class="pf-book-reco-badge__q" aria-hidden="true">?</span>'
				. '<svg class="pf-book-reco-badge__close" viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>'
				. '</span>';
			if ( $why ) {
				// $why (pf_reco_explanation) contient <strong>/<em> avec titres déjà esc_html'és.
				// Enveloppé dans un seul enfant : la bulle est en flex (centrage vertical) et
				// aurait sinon fait de chaque <strong>/nœud texte un item flex distinct.
				$html .= '<span class="pf-book-reco-tip" id="' . esc_attr( $tip_id ) . '" role="tooltip"><span class="pf-book-reco-tip__text">' . wp_kses( $why, [ 'strong' => [], 'em' => [] ] ) . '</span></span>';
			}
			$html .= '</div>';
		}

		$html .= $is_hero ? '</div>' : '</a>';
		return $html;
	}

	/**
	 * Signet « liste de lecture » posé sur une couverture. `role="button"`
	 * (pas <button>) pour ne pas imbriquer un contrôle dans le <a> du livre —
	 * la navigation est neutralisée en JS (assets/js/shelf-bookmarks.js), qui
	 * porte aussi l'infobulle flottante et le toggle AJAX.
	 */
	private function bookmark_html( $b ) {
		$id    = (int) $b['id'];
		$in    = Passiflore_Reading_List::is_in_list( $id );
		$label = $in ? 'Retirer de ma liste de lecture' : 'Ajouter à ma liste de lecture';
		return sprintf(
			'<span class="pf-book-bookmark%s" role="button" tabindex="0" aria-pressed="%s" aria-label="%s" data-product-id="%d" data-title="%s" data-in-list="%s">'
				. '<svg viewBox="175 -865 610 770" preserveAspectRatio="xMidYMin meet" aria-hidden="true"><path class="pf-book-bookmark__body" d="%s"/><path class="pf-book-bookmark__star" d="%s"/></svg>'
				. '</span>',
			$in ? ' is-in-list' : '',
			$in ? 'true' : 'false',
			esc_attr( $label ),
			$id,
			esc_attr( $b['title'] ),
			$in ? '1' : '0',
			esc_attr( self::BOOKMARK_BODY_PATH ),
			esc_attr( self::BOOKMARK_STAR_PATH )
		);
	}

	/**
	 * Face visible de la tranche : image de tranche, tranche générée
	 * (couleur extraite + titre vertical + logo), ou chant de liseuse.
	 * Utilisée par .pf-book-spine et dupliquée dans .pf-spine-ghost.
	 */
	private function spine_face_html( $b, $img_attrs, $tranche_dims ) {
		if ( ! empty( $b['is_ereader'] ) ) {
			return '<div class="pf-spine-generated pf-spine-generated--device" style="--spine-bg:#2a2a2c;">'
				. '<span class="pf-spine-title">' . esc_html( $b['title'] ) . '</span>'
				. $this->spine_icon_html()
				. '</div>';
		}
		if ( $b['tranche_url'] ) {
			return '<img src="' . esc_url( $b['tranche_url'] ) . '" alt="" ' . $img_attrs . ' ' . $tranche_dims . ' draggable="false" />';
		}
		$bg = $b['spine_color'] ?: self::PASSIFLORE_RED;
		return '<div class="pf-spine-generated" style="--spine-bg:' . esc_attr( $bg ) . ';">'
			. '<span class="pf-spine-title">' . esc_html( $b['title'] ) . '</span>'
			. $this->spine_icon_html()
			. '</div>';
	}

	/**
	 * Logo rond Passiflore (site icon) en bas des tranches générées et dans
	 * le menton des liseuses. Repli sur le pictogramme SVG embarqué si le
	 * site icon n'est pas configuré.
	 */
	private function spine_icon_html() {
		static $url = null;
		if ( $url === null ) {
			$url = (string) get_site_icon_url( 96 );
		}
		if ( $url !== '' ) {
			return '<img class="pf-spine-icon" src="' . esc_url( $url ) . '" alt="" loading="lazy" draggable="false" />';
		}
		return $this->passiflore_icon_svg();
	}

	private function passiflore_icon_svg() {
		return '<svg class="pf-spine-icon" viewBox="0 0 32 32" aria-hidden="true" focusable="false">'
			. '<g fill="currentColor">'
			. '<circle cx="16" cy="16" r="2.5"/>'
			. '<ellipse cx="16" cy="7" rx="1.8" ry="3.5"/>'
			. '<ellipse cx="16" cy="25" rx="1.8" ry="3.5"/>'
			. '<ellipse cx="7" cy="16" rx="3.5" ry="1.8"/>'
			. '<ellipse cx="25" cy="16" rx="3.5" ry="1.8"/>'
			. '<ellipse cx="9.5" cy="9.5" rx="2.2" ry="3" transform="rotate(-45 9.5 9.5)"/>'
			. '<ellipse cx="22.5" cy="9.5" rx="2.2" ry="3" transform="rotate(45 22.5 9.5)"/>'
			. '<ellipse cx="9.5" cy="22.5" rx="2.2" ry="3" transform="rotate(45 9.5 22.5)"/>'
			. '<ellipse cx="22.5" cy="22.5" rx="2.2" ry="3" transform="rotate(-45 22.5 22.5)"/>'
			. '</g></svg>';
	}
}

new Passiflore_Bookshelf();
