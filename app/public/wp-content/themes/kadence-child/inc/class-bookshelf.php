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
	// Incrémenter à chaque changement d'algorithme de couleur de dos : le cache
	// COLOR_META_KEY est alors purgé une fois par environnement (admin_init).
	const COLOR_ALGO_VER    = 2;
	const COLOR_PURGE_OPT   = 'pf_cover_color_algo';
	// Préchauffage du cache couleur, par lots depuis l'admin (cf.
	// maybe_warm_cover_colors) : ~30 ms par couverture, à ne pas faire porter
	// d'un bloc au premier rendu du catalogue.
	const COLOR_WARM_OPT    = 'pf_cover_color_warm';
	const COLOR_WARM_BATCH  = 15;
	// Bornes du corps du titre sur un dos généré (cf. spine_layout()).
	const SPINE_FONT_MIN    = 7;
	const SPINE_FONT_MAX    = 14;
	// Seuil de CONFORT, distinct du plancher : on préfère raccourcir les
	// auteurs plutôt que descendre sous ce corps. SPINE_FONT_MIN ne sert qu'en
	// dernier recours, quand même le titre seul ne rentre pas.
	const SPINE_FONT_OK     = 9;
	// Les auteurs se composent un cran sous le titre, comme sur un vrai dos.
	const SPINE_AUTHOR_RATIO = 0.85;
	// Marges haute + basse d'un dos généré, cumulées.
	// ⚠️ DOIT rester synchronisé avec le `padding` de .pf-spine-generated
	// (bookshelf.css) : c'est ce qu'on déduit du budget vertical du texte.
	const SPINE_PAD_Y       = 26;
	// Largeur moyenne d'un caractère, en em, letter-spacing (0.02em) inclus.
	// Mesurée au canvas sur un corpus de vrais titres et noms du catalogue :
	// Newsreader 700 = 0.480, Inter 700 = 0.513, Inter 400 = 0.497.
	const SPINE_EM_SERIF    = 0.500;
	const SPINE_EM_SANS     = 0.533;
	const SPINE_EM_AUTHOR   = 0.517;
	// Tailles d'image dédiées à l'étagère GÉNÉRIQUE (les ~300 livres du
	// catalogue, servis × chaque visite / chaque filtre) à la place de
	// medium_large (768) / large (1024) surdimensionnés. Non-crop → le ratio
	// est préservé : indispensable pour les dos, images très étroites et
	// hautes (la hauteur est la dimension contraignante, la largeur suit).
	// Calées sur le rendu réel : couverture ≤ ~420px de large / ~480px de haut
	// (mode covers) ; dos ≤ ~108px de large / ~720px de haut (mode spines).
	// Le mode HÉROS (fiche livre, livre affiché bien plus grand) conserve
	// medium_large / large — cf. prepare_books().
	const SHELF_COVER_SIZE  = 'pf-shelf-cover'; // 400 × 600 (boîte englobante)
	const SHELF_SPINE_SIZE  = 'pf-shelf-spine'; // 300 × 760 (boîte englobante)
	// Les livres numériques sont rendus comme une liseuse posée sur
	// l'étagère : hauteur d'appareil fixe, largeur dérivée du ratio de la
	// couverture pour que l'écran l'affiche en entier, sans letterbox.
	const EREADER_HEIGHT_MM = 210;
	const EREADER_SPINE_MM  = 9;
	// Bezels de l'appareil, en FRACTION DE SA HAUTEUR — doivent refléter le
	// padding CSS de .pf-book--ereader .pf-book-cover (--pf-ereader-bezel /
	// --pf-ereader-chin, écrits avec les mêmes fractions). Proportionnels et
	// non figés en px : bookshelf.js redimensionne le livre (hero de la fiche
	// livre, anti-débordement mobile) en multipliant --book-h / --cover-w, et
	// un bezel qui ne suivrait pas ce facteur ferait dériver le ratio de
	// l'écran de celui de la couverture → bandes blanches d'object-fit:contain
	// (haut/bas quand l'appareil grandit, côtés quand il rétrécit).
	const EREADER_BEZEL_RATIO = 7 / 252;  // 7px sur l'appareil de base (210mm × SCALE)
	const EREADER_CHIN_RATIO  = 24 / 252; // 24px de menton sur ce même appareil
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
		add_action( 'admin_init', [ __CLASS__, 'maybe_purge_cover_colors' ] );
		add_action( 'admin_init', [ __CLASS__, 'maybe_warm_cover_colors' ], 11 );
		$this->register_counts_cache_hooks();
	}

	/* ─── Cache des compteurs de filtres (get_filter_counts) ─────────
	 *
	 * get_filter_counts() lance ~50 requêtes (une par option de filtre) pour les
	 * compteurs « (N) » du catalogue, et il est appelé 2× au rendu de la page
	 * (état courant + compteurs globaux) puis 1× à chaque filtrage AJAX → coût
	 * dominant du catalogue (constaté : 8 s connecté). On mémoïse le RÉSULTAT
	 * (petit tableau d'entiers) dans un transient keyé par une version globale +
	 * l'état de filtre, invalidé à tout changement produit (bump de version ;
	 * les anciennes clés expirent via leur TTL). Registre d'invalidation posé une
	 * seule fois (drapeau static — la classe est instanciée plusieurs fois/requête).
	 */
	const COUNTS_VERSION_OPTION = 'pf_shelf_counts_ver';
	private static $counts_hooks_registered = false;

	private function register_counts_cache_hooks() {
		if ( self::$counts_hooks_registered ) return;
		self::$counts_hooks_registered = true;
		add_action( 'save_post',          [ __CLASS__, 'bump_counts_version_for_post' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'bump_counts_version_for_post' ] );
		add_action( 'trashed_post',       [ __CLASS__, 'bump_counts_version_for_post' ] );
		add_action( 'untrashed_post',     [ __CLASS__, 'bump_counts_version_for_post' ] );
		add_action( 'woocommerce_update_product', [ __CLASS__, 'bump_counts_version' ] );
		add_action( 'woocommerce_new_product',    [ __CLASS__, 'bump_counts_version' ] );
		add_action( 'set_object_terms',   [ __CLASS__, 'bump_counts_version_on_terms' ], 10, 4 );
	}

	public static function bump_counts_version() {
		$ver = (int) get_option( self::COUNTS_VERSION_OPTION, 1 );
		update_option( self::COUNTS_VERSION_OPTION, $ver + 1, false );
	}

	public static function bump_counts_version_for_post( $post_id ) {
		if ( 'product' === get_post_type( $post_id ) ) {
			self::bump_counts_version();
		}
	}

	public static function bump_counts_version_on_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( in_array( $taxonomy, [ 'product_cat', 'product_tag', 'pa_format_particulier', 'format_groupe' ], true )
			&& 'product' === get_post_type( $object_id ) ) {
			self::bump_counts_version();
		}
	}

	/**
	 * Sous-tailles dédiées à l'étagère générique (cf. constantes SHELF_*_SIZE).
	 * Non-crop : le ratio est préservé — pour un dos (étroit et haut)
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

		// Composant global .pf-scroll-fade (ombres de bord d'un défilement
		// horizontal) : dépendance des étagères en mode scroll, enqueué par
		// render_scroll(). Enregistré ici plutôt qu'enqueué page par page —
		// une étagère scroll peut apparaître n'importe où (shortcode).
		// wp_register_script() ne fait rien si un autre appelant l'a déjà
		// enregistré (functions.php le fait, avec le même fichier).
		wp_register_script(
			'pf-scroll-fade',
			$theme_uri . '/assets/js/scroll-fade.js',
			[],
			filemtime( $theme_dir . '/assets/js/scroll-fade.js' ),
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
	 * Enqueue (une seule fois) le contrôleur « liste de lecture » + le composant
	 * toast, et localise `pfBookmarks`. Réutilise le nonce de Passiflore_Reading_List
	 * (même endpoint pf_reading_list_toggle). Le CSS du signet vit dans bookshelf.css
	 * (déjà enqueué) et celui du toast dans style.css (toujours chargé).
	 *
	 * Publique : la fiche livre l'appelle aussi (Passiflore_Reading_List::enqueue_assets),
	 * son bouton de hero étant piloté par le même contrôleur que les signets.
	 */
	public static function enqueue_bookmark_assets() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		// Invité : aucun nonce (l'endpoint le refuserait de toute façon) — le
		// contrôleur se contente d'inviter à se connecter.
		$logged_in = is_user_logged_in();
		$login_url = function_exists( 'pf_auth_url' ) ? pf_auth_url( 'login' ) : wc_get_page_permalink( 'myaccount' );

		wp_enqueue_script( 'pf-shelf-bookmarks' );
		wp_localize_script( 'pf-shelf-bookmarks', 'pfBookmarks', [
			'ajax_url'      => admin_url( 'admin-ajax.php' ),
			'nonce'         => $logged_in ? wp_create_nonce( Passiflore_Reading_List::NONCE ) : '',
			'toggle_action' => 'pf_reading_list_toggle',
			'login_url'     => $login_url,
			'icons'         => Passiflore_Reading_List::toast_icons(),
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
				/* translators: %s = titre du livre (déjà mis en forme). */
				'login'      => 'Veuillez vous connecter pour pouvoir ajouter %s à votre liste de lecture.',
				'login_cta'  => 'Se connecter',
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
			return '<p class="pf-empty">' . esc_html__( 'Aucun livre ne correspond à votre recherche.', 'kadence-child' ) . '</p>';
		}

		$show_formats = filter_var( $atts['display_formats'], FILTER_VALIDATE_BOOLEAN );

		$is_hero      = filter_var( $atts['hero'], FILTER_VALIDATE_BOOLEAN );
		$books        = $this->prepare_books( $products, $display, $is_hero );
		self::$last_total = count( $books );

		// Opt-in: arrange the books tallest → shortest. Enabled per shelf via
		// orderby="hauteur" (used by the home-page « Culture Sud-Ouest » shelf).
		// The sort key is `height_px` — the very value used to render each
		// spine's height (book height in mm × scale; cover/dos images only
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
			self::enqueue_bookmark_assets();
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

		$cat_theme = $this->resolve_category_theme( $atts );

		if ( $mode === 'scroll' ) {
			return $this->render_scroll( $books, $display, $show_price, $nb_first, $is_hero, $cat_theme, $show_formats, $libraires_url, $show_bookmarks );
		}

		return $this->render_shelves( $books, $display, $show_price, $per_shelf, $nb_first, $is_hero, $cat_theme, $show_formats, $libraires_url, $show_bookmarks );
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
			$ids = self::get_default_format_ids();
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

	/**
	 * Nombre d'OUVRAGES publiés, dédupliqué par format_groupe : une œuvre compte
	 * pour une, quel que soit son nombre d'éditions (classique, grands
	 * caractères, numérique…). Exactement l'ensemble que [passiflore_etagere] et
	 * le catalogue servent en mode `format` par défaut — c'est le même
	 * get_default_format_ids(), pas un comptage parallèle qui pourrait diverger.
	 *
	 * Mémoïsé sur la version de cache des compteurs de filtres, donc invalidé par
	 * les mêmes hooks produit (cf. register_counts_cache_hooks).
	 */
	public static function count_oeuvres() {
		$key    = 'pf_shelf_oeuvres_' . (int) get_option( self::COUNTS_VERSION_OPTION, 1 );
		$cached = get_transient( $key );
		if ( false !== $cached ) return (int) $cached;

		$count = count( self::get_default_format_ids() );
		set_transient( $key, $count, 6 * HOUR_IN_SECONDS );
		return $count;
	}

	private static function get_default_format_ids() {
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
		$representants = self::get_group_representatives_batch();

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
	private static function get_group_representatives_batch() {
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

		// La recherche a une cardinalité quasi infinie (une entrée par chaîne
		// tapée) → on ne la met PAS en cache (seul vrai risque de bloat) : calcul
		// en direct. Ses résultats forment de toute façon un sous-ensemble plus
		// petit, donc moins lourd à compter. Seuls les combos de FILTRES (bornés,
		// entrées ~1 Ko) sont mémoïsés.
		$use_cache = ( '' === trim( (string) $atts['search'] ) );

		if ( $use_cache ) {
			$cache_key = $this->counts_cache_key( $atts );
			$cached    = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$counts = [
			'format'    => $this->count_options( $atts, 'format',    $this->get_format_options() ),
			'category'  => $this->count_options( $atts, 'category',  $this->get_term_option_slugs( 'product_cat' ) ),
			'public'    => $this->count_options( $atts, 'public',    $this->get_scf_choice_slugs( 'public' ) ),
			'type'      => $this->count_options( $atts, 'type',      $this->get_scf_choice_slugs( 'type' ) ),
			'langues'   => $this->count_options( $atts, 'langues',   $this->get_scf_choice_slugs( 'langues' ) ),
			'decouvrir' => $this->count_options( $atts, 'decouvrir', [ '', 'nouveautes', 'prix-litteraires', 'a-paraitre' ] ),
		];

		if ( $use_cache ) {
			set_transient( $cache_key, $counts, 6 * HOUR_IN_SECONDS );
		}
		return $counts;
	}

	/**
	 * Clé de cache des compteurs : version globale + état de filtre. Seules les
	 * atts qui changent l'ENSEMBLE des produits comptés entrent dans la clé.
	 * `orderby` en fait partie : trier par une meta (date_de_parution, _price,
	 * nombre_de_pages) fait une jointure INNER qui EXCLUT les livres dépourvus de
	 * cette meta — le nombre compté en dépend donc. `order` (ASC/DESC) et
	 * l'affichage n'ont, eux, aucun effet sur l'ensemble.
	 */
	private function counts_cache_key( $atts ) {
		$relevant = [];
		foreach ( [ 'ids', 'category', 'tag', 'format', 'public', 'type', 'langues',
			'decouvrir', 'disponibilite', 'reliure', 'auteur', 'role', 'search', 'orderby' ] as $k ) {
			$relevant[ $k ] = (string) ( $atts[ $k ] ?? '' );
		}
		$ver = (int) get_option( self::COUNTS_VERSION_OPTION, 1 );
		return 'pf_ccounts_' . $ver . '_' . md5( wp_json_encode( $relevant ) );
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
		$cover_size = ( $is_hero || $display === 'spines' ) ? 'medium_large' : self::SHELF_COVER_SIZE;
		$dos_size   = $is_hero ? 'large' : self::SHELF_SPINE_SIZE;

		// Amorçage groupé des attachments (couvertures + dos). Sans ça, les
		// wp_get_attachment_image_url/_src de la boucle déclenchent 1-2 requêtes
		// par image (les vignettes ne sont pas dans le cache de la requête
		// produits) → N+1. Un seul _prime_post_caches charge posts + métas d'un
		// coup ; les IDs eux-mêmes se lisent en cache (méta produit déjà amorcée).
		$att_ids = [];
		foreach ( $products as $post ) {
			$tid = get_post_thumbnail_id( $post->ID );
			if ( $tid ) $att_ids[] = (int) $tid;
			// `tranche` est le nom (slug) réel du champ SCF ; il désigne le dos.
			$dos_id = (int) get_post_meta( $post->ID, 'tranche', true );
			if ( $dos_id ) $att_ids[] = $dos_id;
		}
		$att_ids = array_values( array_unique( $att_ids ) );
		if ( $att_ids ) {
			_prime_post_caches( $att_ids, false, true );
		}

		// Auteurs + famille typo des dos générés, en lot (cf. spine_meta_for()).
		$spine_meta = ( $display === 'spines' ) ? $this->spine_meta_for( $products ) : [];

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
				// Liseuse : hauteur d'appareil fixe, pas de dos papier ni
				// d'épaisseur dérivée des pages. La largeur est calculée plus
				// bas, en px, à partir du ratio de la couverture.
				$height_mm = self::EREADER_HEIGHT_MM;
				$width_mm  = self::DEFAULT_WIDTH_MM; // recalculé en px ci-dessous
				$spine_mm  = self::EREADER_SPINE_MM;
				$dos_id    = 0;
			} elseif ( $is_cd ) {
				// Boîtier CD : dimensions de boîtier cristal standard.
				$height_mm = self::CD_HEIGHT_MM;
				$width_mm  = self::CD_WIDTH_MM;
				$spine_mm  = self::CD_SPINE_MM;
				$dos_id    = 0;
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

				// Lecture directe en post meta : `tranche` (nom réel du champ SCF,
				// désigne le dos, return_format=id) et `nombre_de_pages` stockent
				// une valeur brute, le formatage SCF de get_field() est inutile
				// ici (méta déjà en cache via la requête).
				$dos_id = (int) get_post_meta( $post->ID, 'tranche', true );
				$pages  = absint( get_post_meta( $post->ID, 'nombre_de_pages', true ) );

				$spine_mm    = 0;
				$from_image  = false;
				if ( $dos_id ) {
					$img = wp_get_attachment_image_src( $dos_id, 'full' );
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
				// Only clamp the page-derived fallback. When a dos image exists,
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
				$bezel      = $height_px * self::EREADER_BEZEL_RATIO;
				$screen_h   = $height_px - $bezel - $height_px * self::EREADER_CHIN_RATIO;
				$cover_w_px = (int) round( $screen_h * $ratio + 2 * $bezel );
			}

			$thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, $cover_size ) : '';
			$dos_url   = $dos_id ? wp_get_attachment_image_url( $dos_id, $dos_size ) : '';
			// Couleur dominante de la couverture. Extraite pour TOUS les livres
			// (et plus seulement ceux sans image de dos) : elle sert désormais
			// aussi à peindre la quatrième de couverture sur l'arête arrière du
			// bandeau de pages, qui existe quel que soit le dos. Une liseuse n'a
			// ni bloc pages ni couverture cartonnée → rien à extraire.
			$cover_color = $is_ereader ? '' : self::extract_cover_color( $thumb_id );

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
				'dos_url'      => $dos_url,
				'cover_color'  => $cover_color,
				'price'        => $price_html,
				'height_px'    => $height_px,
				'cover_w_px'   => $cover_w_px,
				'spine_w_px'   => $spine_w_px,
				'is_ereader'   => $is_ereader,
				'is_cd'        => $is_cd,
				'is_aparaitre' => $is_aparaitre,
				'is_nouveaute' => $is_nouveaute,
				'format_label' => $format_label,
				'authors'      => $spine_meta[ $post->ID ]['authors'] ?? [],
				'spine_font'   => $spine_meta[ $post->ID ]['font'] ?? '',
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

	/**
	 * Couleur du dos fictif : couleur DOMINANTE de la couverture.
	 *
	 * On ne prend pas la moyenne (l'ancienne méthode, un resize en 1x1) : elle
	 * régresse vers un gris-brun dès que la couverture est contrastée, et sur
	 * 33 couvertures elle donnait 39 % de dos où le titre blanc était illisible.
	 * On ne prend pas non plus un pixel du coin ni la moyenne du bord : le fond
	 * est souvent le blanc de la marge (mesuré : 5 à 6 dos quasi blancs sur 33).
	 *
	 * Méthode : histogramme QUANTIFIÉ. On réduit d'abord à 64x64 (le
	 * rééchantillonnage est fait en C, donc quasi gratuit), puis on range les
	 * 4096 pixels dans 4096 casiers de couleur (4 bits par canal) — sans cette
	 * quantification, deux pixels n'ont presque jamais exactement la même
	 * valeur et le comptage ne veut rien dire. Le casier gagnant est celui qui
	 * maximise « fréquence x saturation » : la fréquence prime, la saturation
	 * départage en faveur d'une vraie couleur d'imprimeur plutôt qu'un gris.
	 * Les casiers quasi blancs (papier) et quasi noirs (encre) sont écartés :
	 * ils dominent souvent le comptage sans jamais faire une couleur de dos.
	 *
	 * Coût mesuré ~17 ms/couverture, sans importance : le résultat est figé
	 * dans la post-meta COLOR_META_KEY, donc calculé une seule fois à vie.
	 * Changer l'algorithme => incrémenter COLOR_ALGO_VER (purge automatique).
	 */
	private static function extract_cover_color( $attachment_id ) {
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

		$hex = self::dominant_color( $im );
		imagedestroy( $im );

		update_post_meta( $attachment_id, self::COLOR_META_KEY, $hex );
		return $hex;
	}

	/**
	 * Auteurs et famille typographique de chaque livre, pour les dos générés.
	 *
	 * Tout est amorcé EN LOT — 1 requête pour l'arbre product_cat, 1 pour les
	 * catégories des livres, 1 pour les termes auteur et leurs métas — puis la
	 * boucle de prepare_books() ne lit plus que des caches. Sans ça on
	 * retomberait sur le N+1 déjà corrigé pour les pièces jointes.
	 *
	 * Famille : Newsreader (serif) sous « Littérature », Inter (sans-serif)
	 * sous « Culture Sud-Ouest ». Les deux ancres sont des catégories racines,
	 * on remonte donc l'arbre depuis la catégorie du livre. Ces deux polices
	 * sont déjà chargées par Kadence (vérifié : Inter 400/700 + Newsreader 700),
	 * il n'y a aucune webfont à ajouter.
	 *
	 * @return array [ post_id => [ 'authors' => [ ['full','last'], … ], 'font' => 'serif'|'sans' ] ]
	 */
	private function spine_meta_for( $products ) {
		$ids = [];
		foreach ( $products as $p ) $ids[] = (int) $p->ID;
		if ( ! $ids ) return [];

		// --- Famille typo : term_id de catégorie -> serif | sans.
		$font_of_term = [];
		$cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		if ( ! is_wp_error( $cats ) ) {
			$parent = []; $slug = [];
			foreach ( $cats as $t ) { $parent[ $t->term_id ] = (int) $t->parent; $slug[ $t->term_id ] = $t->slug; }
			foreach ( $parent as $tid => $p ) {
				$cur = $tid; $guard = 0;
				while ( ! empty( $parent[ $cur ] ) && $guard++ < 10 ) $cur = $parent[ $cur ];
				$root = $slug[ $cur ] ?? '';
				if ( 'litterature' === $root )            $font_of_term[ $tid ] = 'serif';
				elseif ( 'culture-sud-ouest' === $root )  $font_of_term[ $tid ] = 'sans';
			}
		}

		$out = [];
		foreach ( $ids as $pid ) $out[ $pid ] = [ 'authors' => [], 'font' => '' ];

		$rel = wp_get_object_terms( $ids, 'product_cat', [ 'fields' => 'all_with_object_id' ] );
		if ( ! is_wp_error( $rel ) ) {
			foreach ( $rel as $t ) {
				$pid = (int) $t->object_id;
				if ( isset( $out[ $pid ] ) && '' === $out[ $pid ]['font'] && isset( $font_of_term[ $t->term_id ] ) ) {
					$out[ $pid ]['font'] = $font_of_term[ $t->term_id ];
				}
			}
		}

		// --- Auteurs : toutes les contributions, dans l'ordre du repeater SCF.
		if ( ! function_exists( 'passiflore_get_product_author_ids' ) ) return $out;

		$per_book = []; $all = [];
		foreach ( $ids as $pid ) {
			$a = passiflore_get_product_author_ids( $pid );
			$per_book[ $pid ] = $a;
			foreach ( $a as $tid ) $all[] = (int) $tid;
		}
		$all = array_values( array_unique( $all ) );
		if ( ! $all ) return $out;
		_prime_term_caches( $all, true );   // termes + term meta en une fois

		foreach ( $per_book as $pid => $tids ) {
			foreach ( $tids as $tid ) {
				$term = get_term( (int) $tid, 'auteur' );
				if ( ! $term || is_wp_error( $term ) ) continue;
				$nom    = (string) get_term_meta( (int) $tid, 'nom', true );
				$prenom = (string) get_term_meta( (int) $tid, 'prenom', true );
				$last   = $nom !== '' ? $nom : $term->name;
				$full   = trim( $prenom . ' ' . $nom );
				$out[ $pid ]['authors'][] = [
					'full' => $full !== '' ? $full : $term->name,
					'last' => $last,
				];
			}
		}
		return $out;
	}

	/** Histogramme quantifié — voir extract_cover_color(). */
	private static function dominant_color( $im ) {
		$size  = 64;
		$small = imagecreatetruecolor( $size, $size );
		imagecopyresampled( $small, $im, 0, 0, 0, 0, $size, $size, imagesx( $im ), imagesy( $im ) );

		$buckets = [];
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				$rgb = imagecolorat( $small, $x, $y );
				$r   = ( $rgb >> 16 ) & 0xFF;
				$g   = ( $rgb >> 8 ) & 0xFF;
				$b   = $rgb & 0xFF;
				// 4 bits par canal : 16x16x16 = 4096 casiers.
				$key = ( ( $r >> 4 ) << 8 ) | ( ( $g >> 4 ) << 4 ) | ( $b >> 4 );
				if ( ! isset( $buckets[ $key ] ) ) {
					$buckets[ $key ] = [ 0, 0, 0, 0 ];
				}
				$buckets[ $key ][0]++;
				$buckets[ $key ][1] += $r;
				$buckets[ $key ][2] += $g;
				$buckets[ $key ][3] += $b;
			}
		}
		imagedestroy( $small );

		$best = null; $best_score = -1;   // hors papier / encre
		$any  = null; $any_score  = -1;   // repli si tout est écarté
		foreach ( $buckets as $d ) {
			$n = $d[0];
			$r = $d[1] / $n; $g = $d[2] / $n; $b = $d[3] / $n;

			$max = max( $r, $g, $b );
			$min = min( $r, $g, $b );
			$lum = ( $max + $min ) / 510;                       // L de HSL, 0..1
			$den = 255 - abs( $max + $min - 255 );
			$sat = $den > 0 ? ( $max - $min ) / $den : 0;

			$score = $n * ( 0.25 + $sat );
			if ( $score > $any_score ) {
				$any_score = $score;
				$any       = [ $r, $g, $b ];
			}
			if ( $lum > 0.93 || $lum < 0.07 ) continue;
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = [ $r, $g, $b ];
			}
		}

		$c = $best ?: $any;
		if ( ! $c ) return self::PASSIFLORE_RED;

		return sprintf( '#%02x%02x%02x', (int) round( $c[0] ), (int) round( $c[1] ), (int) round( $c[2] ) );
	}

	/**
	 * Le titre blanc passe-t-il sur ce fond ? Seuil WCAG AA (4.5:1). En dessous,
	 * le dos bascule en « clair » : titre et logo sombres. On garde la couleur
	 * exacte de la couverture — un livre pâle a un dos pâle, c'est ce que fait
	 * un vrai livre — plutôt que de l'assombrir pour sauver le texte blanc.
	 */
	public static function spine_is_light( $hex ) {
		$rgb = sscanf( $hex, '#%02x%02x%02x' );
		if ( ! $rgb || count( $rgb ) < 3 ) return false;
		$lin = static function ( $c ) {
			$c /= 255;
			return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		};
		$l = 0.2126 * $lin( $rgb[0] ) + 0.7152 * $lin( $rgb[1] ) + 0.0722 * $lin( $rgb[2] );
		return ( 1.05 / ( $l + 0.05 ) ) < 4.5;
	}

	/**
	 * Purge unique du cache couleur quand l'algorithme change (idiome maison,
	 * cf. pf_auteur_terms_synced / pf_shipping_seuil_migrated) : rien à lancer
	 * à la main au déploiement, la première visite d'un écran d'admin suffit.
	 */
	public static function maybe_purge_cover_colors() {
		if ( (int) get_option( self::COLOR_PURGE_OPT ) === self::COLOR_ALGO_VER ) return;
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::COLOR_META_KEY
		) );
		if ( $ids ) {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", self::COLOR_META_KEY
			) );
			// Le cache objet garderait sinon les anciennes valeurs.
			foreach ( $ids as $id ) {
				wp_cache_delete( (int) $id, 'post_meta' );
			}
		}
		update_option( self::COLOR_PURGE_OPT, self::COLOR_ALGO_VER, false );
		delete_option( self::COLOR_WARM_OPT );   // le préchauffage est à refaire
	}

	/**
	 * Préchauffage borné du cache couleur, par lots, depuis l'admin.
	 *
	 * La couleur dominante est extraite pour TOUTES les couvertures (le plat
	 * arrière du bandeau de pages en a besoin, image de dos ou non) : 165 sur
	 * ce catalogue, ~30 ms pièce. Laissé purement paresseux, le premier rendu
	 * du catalogue les calculerait TOUTES dans la même requête — mesuré ~3,8 s
	 * en local, et l'hébergement de prod est nettement plus lent. On draine
	 * donc par lots de COLOR_WARM_BATCH à chaque écran d'admin, jusqu'à
	 * épuisement (option posée à ce moment-là, une seule lecture ensuite).
	 *
	 * Le rendu reste capable de calculer à la volée : ce préchauffage n'est
	 * qu'une avance de phase, jamais une dépendance.
	 */
	public static function maybe_warm_cover_colors() {
		if ( get_option( self::COLOR_WARM_OPT ) ) return;
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT t.meta_value
			   FROM {$wpdb->postmeta} t
			   JOIN {$wpdb->posts} p ON p.ID = t.post_id
			   LEFT JOIN {$wpdb->postmeta} c
			          ON c.post_id = t.meta_value AND c.meta_key = %s
			  WHERE t.meta_key = '_thumbnail_id'
			    AND p.post_type = 'product' AND p.post_status = 'publish'
			    AND c.meta_id IS NULL
			  LIMIT %d",
			self::COLOR_META_KEY, self::COLOR_WARM_BATCH
		) );
		if ( ! $ids ) {
			update_option( self::COLOR_WARM_OPT, 1, false );
			return;
		}
		foreach ( $ids as $id ) {
			self::extract_cover_color( (int) $id );
		}
	}

	/* ─── Render Modes ───────────────────────────────────────────── */

	private function render_scroll( $books, $display, $show_price, $nb_first = 12, $is_hero = false, $cat_theme = '', $show_formats = false, $libraires_url = '', $show_bookmarks = false ) {
		// En spines le chevalet n'apparaît qu'au survol (superposé) :
		// seule la grille covers réserve de la hauteur pour lui.
		$has_chevalet = $display === 'covers' && $show_price;
		$hero_class  = $is_hero ? ' pf-bookshelf--hero' : '';
		$cat_class   = $cat_theme ? ' pf-bookshelf--cat-' . esc_attr( $cat_theme ) : '';
		$price_class = $has_chevalet ? ' pf-bookshelf--show-price' : '';

		// Ombres de bord (composant global .pf-scroll-fade) : le mode scroll est le
		// seul où des livres peuvent sortir du champ — en shelves les rangées sont
		// re-réparties pour la largeur réelle, il n'y a rien à signaler.
		wp_enqueue_script( 'pf-scroll-fade' );

		$html  = '<div class="pf-bookshelf pf-bookshelf--scroll' . $hero_class . $cat_class . $price_class . ' pf-bookshelf--' . esc_attr( $display ) . '">';
		// Le wrapper du fondu s'intercale ENTRE le cadre (.pf-bookshelf : fond,
		// bordure, rayon, ombre portée) et le scroller (.pf-shelf) : le masque ne
		// doit dissoudre que les livres et la planche, jamais le cadre — porté par
		// .pf-bookshelf, il y aurait effacé bordure et coins arrondis. Les deux
		// partagent --wall-color, donc le fond ne bouge pas sous le fondu.
		// Contrainte du composant : le scroller est l'unique enfant direct du
		// wrapper (cf. scroll-fade.js).
		$html .= '<div class="pf-scroll-fade">';
		$html .= '<div class="pf-shelf">';
		$html .= '<div class="pf-shelf-inner"><div class="pf-shelf-books">';

		$index = 0;
		foreach ( $books as $b ) {
			$html .= $this->render_book( $b, $display, $show_price, $index, $nb_first, $is_hero, $show_formats, $show_bookmarks );
			$index++;
		}

		$html .= '</div><div class="pf-shelf-plank"></div>' . $this->render_libraires_link( $libraires_url ) . '</div></div></div></div>';
		return $html;
	}

	/**
	 * Lien « Voir sur Place des libraires » — mode hero uniquement, rendu dans
	 * .pf-shelf juste sous .pf-shelf-plank (cf. render_scroll()/render_shelves()).
	 */
	private function render_libraires_link( $libraires_url ) {
		if ( ! $libraires_url ) return '';
		return '<a class="bs-hero__libraires" href="' . esc_url( $libraires_url ) . '" target="_blank" rel="noopener noreferrer">Voir sur Place des libraires' . pf_new_window_note() . '</a>';
	}

	private function render_shelves( $books, $display, $show_price, $per_shelf, $nb_first = 12, $is_hero = false, $cat_theme = '', $show_formats = false, $libraires_url = '', $show_bookmarks = false ) {
		$has_chevalet = $display === 'covers' && $show_price;
		$hero_class  = $is_hero ? ' pf-bookshelf--hero' : '';
		$cat_class   = $cat_theme ? ' pf-bookshelf--cat-' . esc_attr( $cat_theme ) : '';
		$price_class = $has_chevalet ? ' pf-bookshelf--show-price' : '';

		// `data-fixed-rows` : un per_shelf explicite gèle la répartition —
		// le re-packing adaptatif JS (bookshelf.js) ignore ces étagères.
		$fixed = $per_shelf > 0 ? ' data-fixed-rows="1"' : '';
		$html  = '<div class="pf-bookshelf pf-bookshelf--shelves' . $hero_class . $cat_class . $price_class . ' pf-bookshelf--' . esc_attr( $display ) . '"' . $fixed . '>';

		// Voile anti-saut. La répartition calculée plus bas vise une étagère de
		// 1100px ; bookshelf.js la recalcule pour la largeur réelle du
		// conteneur au DOMContentLoaded. Sans voile, la première peinture
		// montre donc la répartition théorique — rangées en `nowrap` centrées,
		// donc livres débordant à gauche ET à droite — avant de sauter à la
		// bonne. Ce script, exécuté par le parseur ICI (pas au chargement),
		// pose la classe qui active le voile avant le premier paint ; le JS la
		// lève une fois le re-packing fait (cf. .pf-shelves-js / .is-packed
		// dans bookshelf.css). Idiome maison, cf. .pf-cat-js du catalogue.
		//
		// Sans JavaScript la classe n'existe pas → l'étagère s'affiche
		// normalement, à sa répartition théorique. Le setTimeout est un filet
		// pour le cas inverse (classe posée mais bookshelf.js jamais exécuté —
		// asset manquant après un déploiement partiel) : l'étagère se dévoile
		// quand même plutôt que de rester invisible.
		//
		// Inerte lors des swaps AJAX (innerHTML n'exécute pas les scripts), et
		// c'est très bien : l'appelant y rappelle init() dans la même tâche que
		// l'insertion, donc aucune peinture intermédiaire à masquer.
		$html .= '<script>(function(s){s.classList.add(\'pf-shelves-js\');'
			. 'setTimeout(function(){s.classList.add(\'is-packed\');},1500);'
			. '})(document.currentScript.parentNode);</script>';

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
			$html .= '<div class="pf-shelf">';
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

		// Couleur des plats (quatrième de couverture peinte sur l'arête arrière
		// du bandeau de pages, cf. .pf-book-pages). Absente pour les liseuses,
		// qui n'ont pas de bandeau ; le CD garde le noir de son boîtier.
		if ( ! empty( $b['cover_color'] ) ) {
			$style .= '--cover-color:' . $b['cover_color'] . ';';
		}

		// Le prix (chevalet) n'existe qu'en covers : en spines les dos
		// sont trop étroits pour le porter, et on ne l'affiche pas non plus
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
		// --cover-w / --book-h ; the dos image inherits cover height
		// and is as wide as the spine.
		$cover_dims = sprintf( 'width="%d" height="%d"', $b['cover_w_px'], $b['height_px'] );
		$dos_dims   = sprintf( 'width="%d" height="%d"', $b['spine_w_px'], $b['height_px'] );

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

		// Spine face: dos image OR generated (cover-extracted color +
		// vertical title + logo Passiflore) OR chant de liseuse. The generated
		// rendering is used in both modes; CSS hides title/icon in covers mode
		// where the spine is just a thin parallelogram. En covers, la liseuse
		// est une ardoise plate : ni dos ni bandeau de pages.
		$dos_attrs = ( $display === 'spines' ) ? $primary_attrs : 'loading="lazy"';
		if ( ! ( $is_ereader && $display === 'covers' ) ) {
			$html .= '<div class="pf-book-spine">' . $this->spine_face_html( $b, $dos_attrs, $dos_dims ) . '</div>';
		}

		// Cover face. In spines mode the cover is hidden until hover, so we
		// defer fetching to first hover (data-src swapped in by JS).
		// Pour les liseuses, l'image est dans .pf-ereader-screen (fond blanc,
		// overflow:hidden) qui clipe la rotation sans bouger le cadre device.
		$html .= '<div class="pf-book-cover">';
		// Mode spines : la couverture qui s'ouvre au curseur est un SOUS-bloc.
		// .pf-book-cover reste la face rigide du volume ; .pf-cover-leaf porte
		// la rotation d'ouverture dans une scène 3D locale (perspective propre)
		// — sinon la caméra déportée du volume amplifie sa profondeur et la
		// couverture s'étire vers la droite au lieu de pivoter. Cf. bookshelf.css.
		$cover_leaf = ( $display === 'spines' && ! $is_hero && ! $is_ereader );
		if ( $cover_leaf ) {
			$html .= '<div class="pf-cover-leaf">';
		}
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
			$html .= '<span class="pf-ereader-brand">' . $this->spine_icon_html( true ) . '</span>';
		}

		if ( $cover_leaf ) {
			$html .= '</div>'; // .pf-cover-leaf
		}

		$html .= '</div>';

		// Top face: page edges. CSS gives it a 3D rotation in spines mode
		// and a clip-path parallelogram in covers mode.
		if ( ! $is_ereader ) {
			$html .= '<div class="pf-book-pages"></div>';
		}

		$html .= '</div>';

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
	 * Face visible du dos : image de dos, dos généré
	 * (couleur extraite + titre vertical + logo), ou chant de liseuse.
	 * Utilisée par .pf-book-spine.
	 */
	private function spine_face_html( $b, $img_attrs, $dos_dims ) {
		if ( ! empty( $b['is_ereader'] ) ) {
			// Le chant d'une liseuse n'est pas un dos de livre : titre seul.
			$device = $b; $device['authors'] = [];
			return '<div class="pf-spine-generated pf-spine-generated--device" style="--spine-bg:#2a2a2c;">'
				. $this->spine_texts_html( $device )
				. $this->spine_icon_html( false )
				. '</div>';
		}
		if ( $b['dos_url'] ) {
			return '<img src="' . esc_url( $b['dos_url'] ) . '" alt="" ' . $img_attrs . ' ' . $dos_dims . ' draggable="false" />';
		}
		$bg    = $b['cover_color'] ?: self::PASSIFLORE_RED;
		$light = self::spine_is_light( $bg );
		$serif = ( 'serif' === ( $b['spine_font'] ?? '' ) );
		return '<div class="pf-spine-generated' . ( $light ? ' pf-spine-generated--light' : '' )
			. ( $serif ? ' pf-spine-generated--serif' : '' )
			. '" style="--spine-bg:' . esc_attr( $bg ) . ';">'
			. $this->spine_texts_html( $b )
			. $this->spine_icon_html( $light )
			. '</div>';
	}

	/**
	 * Titre imprimé sur le dos.
	 *
	 * Le suffixe entre parenthèses est retiré : sur ce catalogue il vaut
	 * toujours « (grands caractères) », « (numérique) » ou « (deuxième
	 * édition) » — un marqueur de format, jamais du titre, et qu'aucun
	 * imprimeur ne mettrait sur un dos. C'est aussi ce qui faisait déborder
	 * les titres : les 3 seuls qui dépassaient 42 caractères le devaient
	 * entièrement à ce suffixe.
	 *
	 * Filet pour le reste : plutôt que de tronquer, on réduit la police quand
	 * le titre ne rentre pas. En écriture verticale un caractère occupe ~0,53
	 * em (mesuré : 290 px pour 49 caractères à 11 px), et .pf-spine-title est
	 * plafonné à 75 % de la hauteur du dos, padding déduit.
	 */
	/** Titre imprimé, suffixe de format retiré (cf. spine_texts_html()). */
	private function spine_label( $title ) {
		$label = trim( preg_replace( '/\s*\([^()]*\)\s*$/u', '', $title ) );
		return '' === $label ? $title : $label;
	}

	/**
	 * Auteurs + titre d'un dos généré : auteurs en haut, titre au milieu (le
	 * logo, 3e enfant flex, ferme le bas — c'est le `space-between` qui centre
	 * le titre). L'élément auteurs est émis même vide, pour que ce centrage
	 * tienne aussi sur les livres sans auteur.
	 */
	private function spine_texts_html( $b ) {
		$layout = $this->spine_layout( $b );
		$fs_a   = round( $layout['size'] * self::SPINE_AUTHOR_RATIO * 2 ) / 2;

		// Corps émis en VARIABLES et non en `font-size` direct : le CSS les
		// multiplie par --pf-spines-scale, qui rétrécit le dos entier (corps,
		// marges, logo) sur mobile. Une font-size en dur ne suivrait pas et le
		// titre déborderait d'un dos réduit.
		return '<span class="pf-spine-authors" style="--pf-spine-fs:' . $fs_a . 'px;">'
			. esc_html( $layout['authors'] ) . '</span>'
			. '<span class="pf-spine-title" style="--pf-spine-fs:' . $layout['size'] . 'px;">'
			. esc_html( $layout['title'] ) . '</span>';
	}

	/**
	 * « A & B », « A, B & C ».
	 * $key vaut 'full' (Prénom Nom) ou 'last' (patronyme seul).
	 */
	private function spine_authors_label( $authors, $key ) {
		$names = [];
		foreach ( $authors as $a ) {
			if ( ! empty( $a[ $key ] ) ) $names[] = $a[ $key ];
		}
		if ( ! $names ) return '';
		if ( 1 === count( $names ) ) return $names[0];
		$last = array_pop( $names );
		return implode( ', ', $names ) . ' & ' . $last;
	}

	/**
	 * Corps du texte et forme des auteurs, contraints par les DEUX dimensions.
	 *
	 * - Hauteur : hauteur interne du dos (padding 8+6 déduit), moins la place
	 *   du logo, moins une respiration. Le coût d'un texte vaut
	 *   caractères x em/caractère (constantes SPINE_EM_*, mesurées au canvas),
	 *   les auteurs comptant pour SPINE_AUTHOR_RATIO de moins.
	 * - Largeur : la ligne couchée occupe `line-height` (1,15) x le corps, dans
	 *   la largeur du dos moins 2 px de padding de chaque côté. C'est ce qui
	 *   fait grossir le texte sur les livres épais et le contient sur les
	 *   minces, au lieu d'un corps uniforme.
	 *
	 * ÉCHELLE DE REPLI quand tout ne rentre pas — le titre est l'identifiant
	 * principal d'un dos, c'est donc toujours lui qu'on protège :
	 *   1. Prénom Nom  ->  2. patronymes seuls  ->  3. « Nom & al. » (2 auteurs
	 *   et plus)  ->  4. titre seul, auteurs abandonnés.
	 * On descend d'un cran tant que le corps calculé passe sous SPINE_FONT_MIN.
	 * Filet ultime côté CSS : `overflow:hidden` + `text-overflow:ellipsis`.
	 *
	 * Simple arithmétique (pas de traitement d'image), donc calculé au rendu
	 * sans cache — contrairement à la couleur.
	 */
	private function spine_layout( $b ) {
		$title = $this->spine_label( $b['title'] );
		$em_t  = ( 'serif' === ( $b['spine_font'] ?? '' ) ) ? self::SPINE_EM_SERIF : self::SPINE_EM_SANS;
		$cost_title = mb_strlen( $title ) * $em_t;

		// Le logo fait 70 % de la largeur du dos, plafonné à 24 px, et son
		// ratio hauteur/largeur vaut ~1,04.
		$logo_h  = min( 24, (int) $b['spine_w_px'] * 0.7 ) * 1.04;
		$budget  = ( (int) $b['height_px'] - self::SPINE_PAD_Y ) - $logo_h - 8;
		$fit_w   = ( (int) $b['spine_w_px'] - 4 ) / 1.15;

		$authors = isset( $b['authors'] ) && is_array( $b['authors'] ) ? $b['authors'] : [];
		$forms   = [];
		if ( $authors ) {
			// Le prénom n'est conservé que pour un auteur SEUL. À partir de deux,
			// la forme complète est toujours au moins le double de la forme
			// patronymes (mesuré sur le catalogue : 30 à 52 caractères contre 15
			// à 28), souvent plus longue que le titre — les prénoms composés
			// français y sont pour beaucoup. C'est aussi l'usage typographique
			// sur un dos à plusieurs auteurs.
			if ( 1 === count( $authors ) ) {
				$forms[] = $this->spine_authors_label( $authors, 'full' );
			}
			$forms[] = $this->spine_authors_label( $authors, 'last' );
			if ( count( $authors ) > 1 && ! empty( $authors[0]['last'] ) ) {
				$forms[] = $authors[0]['last'] . ' & al.';
			}
		}
		$forms[] = '';                       // titre seul
		$forms   = array_values( array_unique( $forms ) );

		$last_i = count( $forms ) - 1;
		foreach ( $forms as $i => $auth ) {
			$cost = $cost_title;
			if ( '' !== $auth ) {
				// +2 caractères : l'espace entre le bloc auteurs et le titre.
				$cost += mb_strlen( $auth ) * self::SPINE_EM_AUTHOR * self::SPINE_AUTHOR_RATIO
					+ 2 * $em_t;
			}
			$fs = min( $cost > 0 ? $budget / $cost : self::SPINE_FONT_MAX, $fit_w, self::SPINE_FONT_MAX );
			// Sur un dos très mince c'est la largeur qui plafonne, et aucune
			// forme d'auteur n'y changera rien : on ne dégrade pas pour ça.
			$ok = min( self::SPINE_FONT_OK, $fit_w );
			if ( $fs >= $ok || $i === $last_i ) {
				return [
					'title'   => $title,
					'authors' => $auth,
					'size'    => round( max( $fs, self::SPINE_FONT_MIN ) * 2 ) / 2,
				];
			}
		}
	}

	/**
	 * Logo rond Passiflore (site icon) en bas des dos générés et dans
	 * le menton des liseuses. Repli sur le pictogramme SVG embarqué si le
	 * site icon n'est pas configuré.
	 */
	private function spine_icon_html( $light = false ) {
		// Dos sombre : macaron blanc détouré (asset du thème, servi en 96px —
		// l'original fait 520px pour un affichage à 24px). Dos clair : site
		// icon, qui est un carré sombre recadré en disque par le CSS.
		if ( ! $light ) {
			return '<img class="pf-spine-icon pf-spine-icon--white" src="'
				. esc_url( get_stylesheet_directory_uri() . '/assets/img/macaron_logo_blanc-96.png' )
				. '" alt="" loading="lazy" draggable="false" />';
		}
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
