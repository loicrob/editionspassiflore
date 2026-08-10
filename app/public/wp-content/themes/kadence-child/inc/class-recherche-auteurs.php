<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Passiflore_Recherche_Auteurs
 *
 * Barre de recherche d'auteurs affichée au-dessus du trombinoscope, sur la
 * page Auteurs. Reproduit le comportement de la barre du Catalogue
 * (Passiflore_Catalogue) : conteneur sticky pleine largeur (fond full-bleed,
 * barre alignée sur le content-container) qui se masque au défilement vers le
 * bas et réapparaît vers le haut.
 *
 * Recherche par nom OU prénom, avec LIKE %…% (tolère les recherches
 * partielles), résultats triés par ordre alphabétique.
 *
 * Shortcode : [passiflore_recherche_auteurs]
 * Action AJAX : pf_recherche_auteurs — renvoie la grille filtrée.
 */
class Passiflore_Recherche_Auteurs {

	public function __construct() {
		add_shortcode( 'passiflore_recherche_auteurs', [ $this, 'render_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
		add_action( 'wp_ajax_pf_recherche_auteurs',        [ $this, 'ajax_search' ] );
		add_action( 'wp_ajax_nopriv_pf_recherche_auteurs', [ $this, 'ajax_search' ] );
	}

	public function register_assets() {
		$uri = get_stylesheet_directory_uri();
		$dir = get_stylesheet_directory();
		// Le CSS de la barre de recherche est désormais fusionné dans auteurs.css.
		wp_register_script(
			'pf-recherche-auteurs',
			$uri . '/assets/js/recherche-auteurs.js',
			[],
			filemtime( $dir . '/assets/js/recherche-auteurs.js' ),
			true
		);

		// Sur la page Auteurs, charger CSS/JS dès le <head> pour éviter un
		// flash de la barre non stylée. Ailleurs, le shortcode les enqueue à
		// la volée (cf. render_shortcode).
		if ( is_page( 'auteurs' ) ) {
			wp_enqueue_style(
				'pf-auteurs',
				$uri . '/assets/css/auteurs.css',
				[],
				filemtime( $dir . '/assets/css/auteurs.css' )
			);
			wp_enqueue_script( 'pf-recherche-auteurs' );
		}
	}

	/* ─── Shortcode ──────────────────────────────────────────────── */

	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( [
			'placeholder' => 'Rechercher par nom, prénom, livre…',
		], $atts, 'passiflore_recherche_auteurs' );

		// Recherche initiale possible via ?recherche= (deep-link).
		$search = isset( $_GET['recherche'] ) ? sanitize_text_field( wp_unslash( $_GET['recherche'] ) ) : '';

		// La grille réutilise les styles de carte d'auteur (auteurs.css).
		wp_enqueue_style(
			'pf-auteurs',
			get_stylesheet_directory_uri() . '/assets/css/auteurs.css',
			[],
			filemtime( get_stylesheet_directory() . '/assets/css/auteurs.css' )
		);
		wp_enqueue_script( 'pf-recherche-auteurs' );

		$config = [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'search'   => $search,
		];

		ob_start();
		?>
		<div class="pf-rech-auteurs" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<div class="pf-rech-sticky pf-sticky-bar">
				<div class="pf-sub-header" role="search">
					<div class="pf-search pf-search--cat">
						<svg class="pf-search-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>
						<input type="search" class="pf-search-input" placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>" value="<?php echo esc_attr( $search ); ?>" aria-label="Rechercher un auteur" />
						<button type="button" class="pf-search-clear" aria-label="Effacer la recherche">×</button>
					</div>
				</div>
			</div>
			<?php // Page Auteurs : contenu = shortcode pur, aucun <h1> ailleurs
			// (pas de contenu éditorial où en poser un à la main). ?>
			<h1 class="pf-page-titre">Nos auteurs</h1>
			<div class="pf-rech-grid"><?php echo $this->render_grid( $search ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ─── AJAX ───────────────────────────────────────────────────── */

	public function ajax_search() {
		// Endpoint public en lecture seule : pas de nonce (aucun enjeu CSRF, et
		// un nonce provoquerait un 403 sur un onglet ancien). Cf. recherche globale.
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		wp_send_json_success( [ 'html' => $this->render_grid( $search ) ] );
	}

	/* ─── Grille ─────────────────────────────────────────────────── */

	private function render_grid( $search ) {
		$terms = $this->query_auteurs( $search );

		if ( empty( $terms ) ) {
			$message = trim( (string) $search ) === ''
				? __( 'Aucun auteur pour le moment.', 'kadence-child' )
				: __( 'Aucun auteur ne correspond à votre recherche.', 'kadence-child' );
			return '<p class="pf-empty">' . esc_html( $message ) . '</p>';
		}

		$html = '<div class="pf-auteurs-grille">';
		foreach ( $terms as $i => $term ) {
			$html .= passiflore_render_auteur_card( $term->term_id, [
				'loading' => $i < 8 ? 'eager' : 'lazy',
			] );
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Recherche les termes « auteur » par nom OU prénom, avec LIKE %…%, triés
	 * par date de parution du livre le plus récent (DESC). Auteurs sans livre
	 * apparaissent en dernier, puis ordre alphabétique en cas d'égalité.
	 *
	 * Le nom du terme vaut « Prénom Nom » (cf. passiflore_build_nom_complet),
	 * donc rechercher sur t.name couvre déjà nom et prénom ; on interroge en
	 * plus explicitement les champs SCF `prenom` et `nom` (stockés en term
	 * meta) pour rester robuste si la composition du nom évolue.
	 *
	 * Avec recherche, le tri reste alphabétique (l'utilisateur cherche un nom).
	 *
	 * @return WP_Term[]
	 */
	private function query_auteurs( $search ) {
		$search = trim( (string) $search );

		if ( $search === '' ) {
			$terms = get_terms( [
				'taxonomy'   => 'auteur',
				'hide_empty' => false,
			] );
			if ( is_wp_error( $terms ) || empty( $terms ) ) return [];

			$max_dates = $this->get_max_date_per_auteur();

			usort( $terms, function ( $a, $b ) use ( $max_dates ) {
				$da = $max_dates[ $a->term_id ] ?? '';
				$db = $max_dates[ $b->term_id ] ?? '';
				if ( $da !== $db ) return strcmp( $db, $da ); // DESC
				return strcmp( $a->name, $b->name );          // alphabétique en cas d'égalité
			} );

			return $terms;
		}

		// Recherche tolérante (casse, accents, caractères spéciaux, fautes de
		// frappe) via le cœur partagé : déjà rangée par pertinence.
		$ids = pf_search_filter_pool( $search, pf_search_pool_auteurs() );
		if ( empty( $ids ) ) return [];

		$terms = get_terms( [
			'taxonomy'   => 'auteur',
			'hide_empty' => false,
			'include'    => array_map( 'intval', $ids ),
			'orderby'    => 'include',
		] );
		return is_wp_error( $terms ) ? [] : $terms;
	}

	/**
	 * Retourne un tableau indexé par term_id → date de parution max (format Ymd)
	 * du livre le plus récent de chaque auteur.
	 *
	 * Deux requêtes SQL : une pour récupérer toutes les liaisons auteur↔livre
	 * via `contributions_%_fiche-auteur`, une pour les dates de parution.
	 * Gère les trois formes de stockage coexistantes (cf. extract_term_id).
	 */
	private function get_max_date_per_auteur(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT a.post_id, a.meta_value AS term_raw
			 FROM {$wpdb->postmeta} a
			 INNER JOIN {$wpdb->posts} p ON p.ID = a.post_id
			     AND p.post_type = 'product'
			     AND p.post_status = 'publish'
			 WHERE a.meta_key LIKE 'contributions_%_fiche-auteur'
			   AND a.meta_value != ''"
		);
		if ( empty( $rows ) ) return [];

		$post_to_terms = [];
		foreach ( $rows as $row ) {
			$tid = $this->extract_term_id( $row->term_raw );
			if ( $tid > 0 ) {
				$post_to_terms[ (int) $row->post_id ][] = $tid;
			}
		}
		if ( empty( $post_to_terms ) ) return [];

		$post_ids     = array_keys( $post_to_terms );
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$date_rows    = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_value AS date
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = 'date_de_parution'
			   AND post_id IN ($placeholders)
			   AND meta_value != ''",
			$post_ids
		) );

		$post_dates = [];
		foreach ( $date_rows as $r ) {
			$post_dates[ (int) $r->post_id ] = $r->date;
		}

		$max_dates = [];
		foreach ( $post_to_terms as $post_id => $term_ids ) {
			$date = $post_dates[ $post_id ] ?? '';
			if ( ! $date ) continue;
			foreach ( $term_ids as $tid ) {
				if ( ! isset( $max_dates[ $tid ] ) || $date > $max_dates[ $tid ] ) {
					$max_dates[ $tid ] = $date;
				}
			}
		}

		return $max_dates;
	}

	/**
	 * Extrait un term_id entier depuis les trois formes de stockage SCF/ACF :
	 *   - entier brut    : "759"
	 *   - chaîne sérialisée : s:3:"759";
	 *   - entier sérialisé  : i:759;
	 */
	private function extract_term_id( string $raw ): int {
		$raw = trim( $raw );
		if ( is_numeric( $raw ) ) return (int) $raw;
		$val = @unserialize( $raw );
		if ( is_int( $val ) ) return $val;
		if ( is_string( $val ) && is_numeric( $val ) ) return (int) $val;
		if ( is_array( $val ) && ! empty( $val ) ) {
			$first = reset( $val );
			if ( is_numeric( $first ) ) return (int) $first;
		}
		return 0;
	}
}

new Passiflore_Recherche_Auteurs();
