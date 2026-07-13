<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Passiflore_Catalogue
 *
 * Renders the filter/sort/search UI on top of an étagère grid, on the
 * Catalogue page (= WC shop) and on product category archives.
 *
 * Shortcode: [passiflore_catalogue]
 *
 * AJAX action: pf_catalogue_filter — re-renders the grid + counts when
 * filters change.
 */
class Passiflore_Catalogue {

	const URL_TO_ATT = [
		'search'    => 'search',
		'tri'       => 'orderby',
		'sens'      => 'order',
		'format'    => 'format',
		'public'    => 'public',
		'type'      => 'type',
		'langues'   => 'langues',
		'decouvrir' => 'decouvrir',
		'affichage' => 'display',
	];

	// URL values for `affichage` use French labels; map to internal slugs.
	const DISPLAY_URL_TO_INTERNAL = [
		'couvertures' => 'covers',
		'tranches'    => 'spines',
	];

	// Top-level product categories shown as the rayon switch.
	const TOP_CATEGORIES = [
		'litterature'       => 16,
		'culture-sud-ouest' => 17,
	];

	const TOP_CATEGORY_LABELS = [
		'litterature'       => 'Littérature',
		'culture-sud-ouest' => 'Culture Sud-Ouest',
	];

	const FILTER_GROUPS = [
		'univers', 'category', 'format', 'public', 'type', 'langues', 'decouvrir',
	];

	public function __construct() {
		add_shortcode( 'passiflore_catalogue', [ $this, 'render_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
		add_action( 'wp_ajax_pf_catalogue_filter',        [ $this, 'ajax_filter' ] );
		add_action( 'wp_ajax_nopriv_pf_catalogue_filter', [ $this, 'ajax_filter' ] );
	}

	public function register_assets() {
		$uri = get_stylesheet_directory_uri();
		$dir = get_stylesheet_directory();
		wp_register_style(
			'pf-catalogue',
			$uri . '/assets/css/catalogue.css',
			[ 'pf-bookshelf' ],
			filemtime( $dir . '/assets/css/catalogue.css' )
		);
		wp_register_script(
			'pf-catalogue',
			$uri . '/assets/js/catalogue.js',
			[],
			filemtime( $dir . '/assets/js/catalogue.js' ),
			true
		);
	}

	/* ─── Shortcode render ───────────────────────────────────────── */

	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( $this->default_atts(), $atts, 'passiflore_catalogue' );

		// On a WC product category archive, derive univers and category from the
		// queried term so deep links work: /catalogue/litterature/ activates the
		// rayon switch; /catalogue/litterature/litterature-generale/ activates both.
		if ( is_product_taxonomy() ) {
			$term = get_queried_object();
			if ( $term && ! is_wp_error( $term ) ) {
				if ( $term->parent === 0 ) {
					if ( $atts['univers'] === '' ) $atts['univers'] = $term->slug;
				} else {
					if ( $atts['category'] === '' ) $atts['category'] = $term->slug;
					if ( $atts['univers'] === '' ) {
						$parent = get_term( $term->parent, 'product_cat' );
						if ( $parent && ! is_wp_error( $parent ) ) {
							$atts['univers'] = $parent->slug;
						}
					}
				}
			}
		}

		// URL query params override everything (so deep links work).
		$atts = $this->merge_url_params( $atts );
		$atts = $this->normalize_display( $atts );

		wp_enqueue_style( 'pf-catalogue' );
		wp_enqueue_script( 'pf-catalogue' );

		$bs            = new Passiflore_Bookshelf();
		$counts        = $bs->get_filter_counts( $this->to_bookshelf_atts( $atts ) );
		// Global counts (no filters) — used to decide which options to even
		// list in the dropdown (we hide options the catalog never uses, but
		// keep options that other books use, just with a (0) when current
		// filters exclude them).
		$global_counts = $bs->get_filter_counts( $this->to_bookshelf_atts( $this->default_atts() ) );
		$grid          = $this->render_grid( $atts );
		$config        = $this->js_config( $atts, $counts );

		ob_start();
		?>
		<div class="pf-catalogue"
		     data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<div class="pf-catalogue-sticky pf-sticky-bar">
				<?php echo $this->render_top_bar( $atts, $counts, $global_counts ); ?>
				<?php echo $this->render_bar( $atts, $counts, $global_counts ); ?>
				<?php echo $this->render_chips( $atts ); ?>
			</div>
			<div class="pf-catalogue-grid"><?php echo $grid; ?></div>
			<?php echo $this->render_filter_panel(); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Panneau de filtres mobile/tablette (bottom-sheet). Rendu HORS du conteneur
	 * sticky (transformé/flouté quand collé → casserait un position:fixed enfant)
	 * mais À L'INTÉRIEUR de .pf-catalogue, pour que les root.querySelector du JS
	 * voient toujours les contrôles qu'il y reloge. Le JS y déplace tri, format,
	 * public, type, langue, découvrir, affichage et PDF en mobile. Masqué ≥1024px.
	 */
	private function render_filter_panel() {
		ob_start();
		?>
		<div class="pf-cat-filter-backdrop"></div>
		<div class="pf-cat-filter-panel" role="dialog" aria-modal="true" aria-label="Tri et filtres">
			<div class="pf-cat-filter-panel__head">
				<span class="pf-cat-filter-panel__title">Tri et filtres</span>
				<button type="button" class="pf-cat-filter-close" aria-label="Fermer les filtres">&times;</button>
			</div>
			<div class="pf-cat-filter-panel__body"></div>
			<div class="pf-cat-filter-panel__foot">
				<button type="button" class="pf-cat-panel-reset">Tout réinitialiser</button>
				<button type="button" class="pf-cat-panel-apply pf-btn pf-btn--primary pf-btn--sm">Voir les résultats</button>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private function default_atts() {
		return [
			'search'    => '',
			'orderby'   => 'date',
			'order'     => 'DESC',
			'format'    => '',
			'public'    => '',
			'type'      => '',
			'langues'   => '',
			'decouvrir' => '',
			'display'   => 'covers',
			'univers'   => '',
			'category'  => '',
			'mode'      => 'shelves',
			'per_shelf' => 0,
			'show_price' => 'true',
		];
	}

	/**
	 * L'affichage tranches n'a pas de sens pour les liseuses (livres
	 * numériques) : quand le format sélectionné peut en afficher
	 * (« numerique » ou « tous »), on force les couvertures. Le JS grise
	 * l'option spines du switch en miroir.
	 */
	private function normalize_display( $atts ) {
		if ( $atts['display'] === 'spines'
			&& in_array( $atts['format'], [ 'tous', 'numerique' ], true ) ) {
			$atts['display'] = 'covers';
		}
		return $atts;
	}

	private function merge_url_params( $atts ) {
		foreach ( self::URL_TO_ATT as $url_key => $att_key ) {
			if ( isset( $_GET[ $url_key ] ) ) {
				$atts[ $att_key ] = wp_unslash( $_GET[ $url_key ] );
			}
		}
		if ( isset( $atts['display'] ) && isset( self::DISPLAY_URL_TO_INTERNAL[ $atts['display'] ] ) ) {
			$atts['display'] = self::DISPLAY_URL_TO_INTERNAL[ $atts['display'] ];
		}
		// Sanitization happens at meta_query / shortcode_atts layer; we keep
		// raw here to preserve commas in `langues=fr,en` etc.
		return $atts;
	}

	private function to_bookshelf_atts( $atts ) {
		$bs_atts = $atts;
		// Use the most specific category constraint for the bookshelf:
		// subcategory if set, otherwise the univers (top-level category).
		if ( $bs_atts['category'] === '' && ! empty( $bs_atts['univers'] ) ) {
			$bs_atts['category'] = $bs_atts['univers'];
		}
		unset( $bs_atts['univers'] );
		return $bs_atts;
	}

	private function js_config( $atts, $counts ) {
		return [
			'ajax_url'         => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'pf_catalogue' ),
			'state'            => $this->state_from_atts( $atts ),
			'counts'           => $counts,
			'defaults'         => [ 'orderby' => 'date', 'order' => 'DESC', 'display' => 'covers', 'format' => '' ],
			'category_parents' => $this->get_category_parents(),
		];
	}

	private function state_from_atts( $atts ) {
		return [
			'search'    => (string) $atts['search'],
			'orderby'   => (string) $atts['orderby'],
			'order'     => (string) $atts['order'],
			'format'    => (string) $atts['format'],
			'public'    => (string) $atts['public'],
			'type'      => (string) $atts['type'],
			'langues'   => (string) $atts['langues'],
			'decouvrir' => (string) $atts['decouvrir'],
			'display'   => (string) $atts['display'],
			'univers'   => (string) $atts['univers'],
			'category'  => (string) $atts['category'],
		];
	}

	/* ─── AJAX endpoint ──────────────────────────────────────────── */

	public function ajax_filter() {
		check_ajax_referer( 'pf_catalogue', 'nonce' );

		$atts = $this->default_atts();
		foreach ( $this->state_from_atts( $atts ) as $k => $_ ) {
			if ( isset( $_POST[ $k ] ) ) {
				$atts[ $k ] = wp_unslash( $_POST[ $k ] );
			}
		}
		$atts = $this->normalize_display( $atts );

		$bs     = new Passiflore_Bookshelf();
		$counts = $bs->get_filter_counts( $this->to_bookshelf_atts( $atts ) );
		$html   = $this->render_grid( $atts );

		wp_send_json_success( [
			'html'   => $html,
			'counts' => $counts,
		] );
	}

	private function render_grid( $atts ) {
		$shortcode_atts = $this->to_bookshelf_atts( $atts );
		$serialized = '';
		foreach ( $shortcode_atts as $k => $v ) {
			$serialized .= ' ' . $k . '="' . esc_attr( $v ) . '"';
		}
		return do_shortcode( '[passiflore_etagere' . $serialized . ']' );
	}

	/* ─── Top bar (rayon switch + collection dropdown) ───────────── */

	private function render_top_bar( $atts, $counts, $global_counts ) {
		$univers    = (string) $atts['univers'];
		$selected   = (string) $atts['category'];
		$by_univers = $this->get_category_options_by_univers();

		ob_start();
		?>
		<div class="pf-catalogue-bar pf-catalogue-bar-top">
			<div class="pf-cat-univers" role="group" aria-label="Rayon">
				<?php foreach ( $this->get_top_categories_ordered() as $slug => $_ ) :
					$is_active       = $univers === $slug;
					$cats            = $by_univers[ $slug ] ?? [];
					$cat_values      = array_column( $cats, 'value' );
					$dropdown_active = $selected !== '' && in_array( $selected, $cat_values, true );
				?>
				<div class="pf-cat-coll-pair">
					<button type="button"
					        class="pf-cat-univers-btn<?php echo $is_active ? ' is-active' : ''; ?>"
					        data-value="<?php echo esc_attr( $slug ); ?>">
						<?php echo esc_html( self::TOP_CATEGORY_LABELS[ $slug ] ); ?>
					</button>
					<div class="pf-cat-dropdown pf-dropdown<?php echo $dropdown_active ? ' is-active' : ''; ?>"
					     data-filter="category" data-multi="false"
					     data-univers="<?php echo esc_attr( $slug ); ?>">
						<button type="button" class="pf-cat-trigger pf-dropdown__trigger" aria-haspopup="true" aria-expanded="false"
						        aria-label="Thématiques <?php echo esc_attr( self::TOP_CATEGORY_LABELS[ $slug ] ); ?>">
							<span class="pf-cat-caret" aria-hidden="true">⋮</span>
						</button>
						<div class="pf-cat-menu pf-dropdown__menu" role="menu">
							<?php foreach ( $cats as $opt ) :
								echo $this->render_cat_option( $opt, $selected, $counts, $global_counts );
							endforeach; ?>
						</div>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<?php echo $this->render_format_switch( $atts, $counts, $global_counts ); ?>
			<?php /* PDFs — primary CTA, poussé à droite via margin-left:auto CSS */
			$pdf_catalogues = passiflore_get_pdf_catalogues();
			if ( ! empty( $pdf_catalogues ) ) : ?>
			<div class="pf-cat-dropdown pf-dropdown pf-cat-pdf pf-cat-primary" data-filter="_pdf" data-multi="false">
				<button type="button" class="pf-cat-trigger pf-dropdown__trigger" aria-haspopup="true" aria-expanded="false">
					<span class="pf-cat-label">Catalogues PDF</span>
					<span class="pf-cat-caret" aria-hidden="true">⋮</span>
				</button>
				<div class="pf-cat-menu pf-dropdown__menu" role="menu">
					<?php foreach ( $pdf_catalogues as $cat ) : ?>
					<a class="pf-cat-pdf-link" href="<?php echo esc_url( $cat['url'] ); ?>" target="_blank" rel="noopener">
						<span><?php echo esc_html( $cat['libelle'] ); ?></span>
						<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h6v6"/><path d="M10 14L21 3"/><path d="M21 14v7H3V3h7"/></svg>
					</a>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function render_format_switch( $atts, $counts, $global_counts ) {
		$format       = (string) $atts['format'];
		$switch_slugs = [ 'grands-caracteres' => 'Grands caractères', 'numerique' => 'Numérique' ];
		$autres_active = $format !== '' && ! array_key_exists( $format, $switch_slugs );

		ob_start();
		?>
		<div class="pf-cat-format-switch pf-switch">
			<?php foreach ( $switch_slugs as $slug => $label ) :
				$is_active = $format === $slug;
			?>
				<button type="button"
				        class="pf-cat-format-btn pf-switch__btn<?php echo $is_active ? ' is-active' : ''; ?>"
				        data-value="<?php echo esc_attr( $slug ); ?>">
					<?php echo esc_html( $label ); ?>
				</button>
			<?php endforeach; ?>
			<?php echo $this->render_dropdown( 'format', 'Autres formats',
				$this->build_format_options(),
				$format, false, $counts['format'] ?? [], $global_counts['format'] ?? [],
				$autres_active ); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function render_cat_option( $opt, $selected, $counts, $global_counts ) {
		$count   = $counts['category'][ $opt['value'] ] ?? null;
		$g_count = $global_counts['category'][ $opt['value'] ] ?? null;
		if ( $g_count !== null && (int) $g_count === 0 ) return '';
		$is_sel = $selected === $opt['value'];
		$is_dis = $count === 0;
		$cls    = 'pf-cat-option pf-dropdown__option' . ( $is_sel ? ' is-selected' : '' ) . ( $is_dis ? ' is-disabled' : '' );
		ob_start();
		?>
		<button type="button" role="menuitem"
		        class="<?php echo esc_attr( $cls ); ?>"
		        data-value="<?php echo esc_attr( $opt['value'] ); ?>"
		        <?php echo $is_dis ? 'disabled' : ''; ?>>
			<span class="pf-cat-opt-label"><?php echo esc_html( $opt['label'] ); ?></span>
			<?php if ( $count !== null ) : ?><span class="pf-cat-opt-count">(<?php echo (int) $count; ?>)</span><?php endif; ?>
		</button>
		<?php
		return ob_get_clean();
	}

	private function get_top_categories_ordered() {
		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'include'    => array_values( self::TOP_CATEGORIES ),
			'hide_empty' => false,
			'orderby'    => 'meta_value_num',
			'order'      => 'ASC',
			'meta_key'   => 'order',
		] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return self::TOP_CATEGORIES;
		}
		$out = [];
		foreach ( $terms as $t ) {
			if ( isset( self::TOP_CATEGORIES[ $t->slug ] ) ) {
				$out[ $t->slug ] = $t->term_id;
			}
		}
		return $out ?: self::TOP_CATEGORIES;
	}

	private function get_category_options_by_univers() {
		$out = [];
		foreach ( self::TOP_CATEGORIES as $top_slug => $top_id ) {
			$terms = get_terms( [
				'taxonomy'   => 'product_cat',
				'parent'     => $top_id,
				'hide_empty' => false,
				'orderby'    => 'meta_value_num',
				'order'      => 'ASC',
				'meta_key'   => 'order',
			] );
			$out[ $top_slug ] = [];
			if ( is_wp_error( $terms ) ) continue;
			foreach ( $terms as $t ) {
				$out[ $top_slug ][] = [ 'value' => $t->slug, 'label' => $t->name ];
			}
		}
		return $out;
	}

	private function get_category_parents() {
		$out = [];
		foreach ( self::TOP_CATEGORIES as $top_slug => $top_id ) {
			$terms = get_terms( [ 'taxonomy' => 'product_cat', 'parent' => $top_id, 'hide_empty' => false, 'orderby' => 'meta_value_num', 'order' => 'ASC', 'meta_key' => 'order' ] );
			if ( is_wp_error( $terms ) ) continue;
			foreach ( $terms as $t ) {
				$out[ $t->slug ] = $top_slug;
			}
		}
		return $out;
	}

	/* ─── Main bar (search / sort / filters) ─────────────────────── */

	private function render_bar( $atts, $counts, $global_counts ) {
		$bs           = new Passiflore_Bookshelf();
		$is_searching = $atts['search'] !== '';

		ob_start();
		?>
		<div class="pf-catalogue-bar pf-sub-header <?php echo $is_searching ? 'is-searching' : ''; ?>" role="toolbar" aria-label="Tri et filtres du catalogue">

			<?php /* Single row that flexes: [search + sort wrapper] [scroll container] */ ?>
			<div class="pf-cat-bar-row pf-cat-bar-row-primary">

				<?php /* Search + sort live in a common wrapper. The wrapper takes
				       all available space (flex-grow); inside, sort hugs the right
				       of search. When the user focuses/types, sort collapses to 0
				       and search grows naturally to fill the freed wrapper space. */ ?>
				<div class="pf-cat-search-sort">

					<div class="pf-cat-search">
						<svg class="pf-cat-search-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>
						<input type="search" class="pf-cat-search-input"
							placeholder="Rechercher par titre, auteur, ISBN, prix littéraire, mot-clé…"
							data-placeholder-sm="Par titre, auteur, ISBN, prix littéraire…"
							value="<?php echo esc_attr( $atts['search'] ); ?>" />
						<button type="button" class="pf-cat-search-clear" aria-label="Effacer la recherche">×</button>
					</div>

					<?php /* Sort: criterion + direction. Trigger label is always "Tri". */ ?>
					<div class="pf-cat-sort">
						<?php
						$tri_is_active = ( $atts['orderby'] !== 'date' );
						echo $this->render_dropdown( 'orderby', 'Tri', [
							[ 'value' => 'date',  'label' => 'Date de parution' ],
							[ 'value' => 'titre', 'label' => 'Titre' ],
							[ 'value' => 'prix',  'label' => 'Prix' ],
							[ 'value' => 'pages', 'label' => 'Nombre de pages' ],
						], $atts['orderby'], false, [], [], $tri_is_active );
						?>
						<?php $sens = strtoupper( $atts['order'] ) === 'ASC' ? 'ASC' : 'DESC'; ?>
						<button type="button" class="pf-cat-sort-dir <?php echo $sens === 'ASC' ? 'is-active' : ''; ?>"
						        data-sens="<?php echo $sens; ?>"
						        aria-label="<?php echo $sens === 'ASC' ? 'Ordre croissant' : 'Ordre décroissant'; ?>">
							<span class="pf-cat-arrow-asc">↓</span>
							<span class="pf-cat-arrow-desc">↑</span>
						</button>
					</div>

				</div><?php /* end .pf-cat-search-sort */ ?>

				<?php /* Mobile/tablette : ouvre le panneau de filtres (rangé à droite
				       de la recherche). Masqué ≥1024px et sans JS (classe .pf-cat-js). */ ?>
				<button type="button" class="pf-cat-filter-trigger" aria-haspopup="dialog" aria-expanded="false">
					<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/></svg>
					<span class="pf-cat-filter-trigger-label">Filtres</span>
					<span class="pf-cat-filter-count" hidden>0</span>
				</button>

				<?php /* Scroll container — holds the filter row.
				       On mobile it drops to a row below search. */ ?>
				<div class="pf-cat-row-scroll">

			<?php echo $this->render_dropdown( 'public', 'Public',
					$this->build_scf_options( $bs->get_scf_choices( 'public' ) ),
					$atts['public'], false, $counts['public'] ?? [], $global_counts['public'] ?? [] ); ?>

				<?php echo $this->render_dropdown( 'type', 'Type',
					$this->build_scf_options( $bs->get_scf_choices( 'type' ) ),
					$atts['type'], true, $counts['type'] ?? [], $global_counts['type'] ?? [] ); ?>

				<?php echo $this->render_dropdown( 'langues', 'Langue',
					$this->build_scf_options( $bs->get_scf_choices( 'langues' ) ),
					$atts['langues'], true, $counts['langues'] ?? [], $global_counts['langues'] ?? [] ); ?>

				<?php echo $this->render_dropdown( 'decouvrir', 'Découvrir', [
					[ 'value' => 'nouveautes',       'label' => 'Nouveautés' ],
					[ 'value' => 'prix-litteraires', 'label' => 'Prix et distinctions' ],
					[ 'value' => 'a-paraitre',       'label' => 'À paraître' ],
				], $atts['decouvrir'], false, $counts['decouvrir'] ?? [], $global_counts['decouvrir'] ?? [] ); ?>

				<?php /* Display segmented control. L'option tranches est grisée
				       quand le format courant peut afficher des liseuses
				       (cf. normalize_display). */ ?>
				<?php $spines_blocked = in_array( $atts['format'], [ 'tous', 'numerique' ], true ); ?>
				<div class="pf-cat-display pf-switch" role="group" aria-label="Mode d'affichage">
					<button type="button" data-value="covers" aria-label="Vue couvertures"
					        class="pf-switch__btn <?php echo $atts['display'] === 'covers' ? 'is-active' : ''; ?>">
						<svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
							<rect x="1"  y="1"  width="8" height="8" rx="1.5"/>
							<rect x="11" y="1"  width="8" height="8" rx="1.5"/>
							<rect x="1"  y="11" width="8" height="8" rx="1.5"/>
							<rect x="11" y="11" width="8" height="8" rx="1.5"/>
						</svg>
						<span class="pf-cat-display-label">Vue couvertures</span>
					</button>
					<button type="button" data-value="spines" aria-label="Vue tranches"
					        <?php echo $spines_blocked ? 'disabled ' : ''; ?>class="pf-switch__btn <?php echo $atts['display'] === 'spines' ? 'is-active' : ''; ?>">
						<svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
							<rect x="1"  y="1" width="4" height="18" rx="1.5"/>
							<rect x="6"  y="1" width="4" height="18" rx="1.5"/>
							<rect x="11" y="1" width="4" height="18" rx="1.5"/>
							<rect x="16" y="1" width="4" height="18" rx="1.5"/>
						</svg>
						<span class="pf-cat-display-label">Vue tranches</span>
					</button>
				</div>

				</div><?php /* end .pf-cat-row-scroll */ ?>
			</div><?php /* end .pf-cat-bar-row-primary */ ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render a filter dropdown.
	 *
	 * @param string     $filter_key     State key (also used for data-filter)
	 * @param string     $label          Stable label shown on the trigger button (always)
	 * @param array      $options        [ ['value'=>..., 'label'=>..., 'indent'=>0..N], ... ]
	 * @param string     $selected       Current value (CSV if $multi)
	 * @param bool       $multi          Multi-select (checkboxes)
	 * @param array      $label_counts   Counts to show in (N) labels per option (current state)
	 * @param array|null $hide_counts    Counts used to DECIDE which options to render at all
	 *                                   (null = no filtering, show all options of the SCF/taxonomy choices)
	 * @param bool|null  $is_active      Override the "is-active" trigger highlight. If null, default rule applies.
	 */
	private function render_dropdown( $filter_key, $label, $options, $selected, $multi, $label_counts, $hide_counts = null, $is_active = null ) {
		$selected_values = $multi
			? array_values( array_filter( array_map( 'trim', explode( ',', (string) $selected ) ) ) )
			: [ (string) $selected ];

		// Filter out options that have NEVER been used (global counts === 0).
		// $hide_counts === null means "show everything" (default).
		if ( is_array( $hide_counts ) ) {
			$options = array_values( array_filter( $options, function( $opt ) use ( $hide_counts ) {
				$v = (string) $opt['value'];
				if ( ! array_key_exists( $v, $hide_counts ) ) return true;
				return (int) $hide_counts[ $v ] > 0;
			} ) );
		}

		if ( $is_active === null ) {
			$is_active = ( $selected !== '' );
		}

		// If no options remain (e.g. no book uses this SCF field), hide the
		// entire dropdown — it would just be a trigger with an empty menu.
		if ( empty( $options ) ) return '';

		ob_start();
		?>
		<div class="pf-cat-dropdown pf-dropdown <?php echo $multi ? 'pf-cat-multi' : ''; ?> <?php echo $is_active ? 'is-active' : ''; ?>"
		     data-filter="<?php echo esc_attr( $filter_key ); ?>"
		     data-multi="<?php echo $multi ? 'true' : 'false'; ?>">
			<button type="button" class="pf-cat-trigger pf-dropdown__trigger" aria-haspopup="true" aria-expanded="false">
				<span class="pf-cat-label"><?php echo esc_html( $label ); ?></span>
				<span class="pf-cat-caret" aria-hidden="true">⋮</span>
			</button>
			<div class="pf-cat-menu pf-dropdown__menu" role="menu">
				<?php foreach ( $options as $opt ) :
					$value         = (string) $opt['value'];
					$count         = $label_counts[ $value ] ?? null;
					$is_sel        = in_array( $value, $selected_values, true );
					$is_disabled   = ( $count === 0 );
					$indent_style  = ! empty( $opt['indent'] ) ? 'padding-left: ' . ( 12 + 16 * (int) $opt['indent'] ) . 'px;' : '';
				?>
					<?php if ( $multi ) : ?>
						<label class="pf-cat-option pf-dropdown__option <?php echo $is_sel ? 'is-selected' : ''; ?> <?php echo $is_disabled ? 'is-disabled' : ''; ?>" style="<?php echo esc_attr( $indent_style ); ?>">
							<input type="checkbox" value="<?php echo esc_attr( $value ); ?>" <?php echo $is_sel ? 'checked' : ''; ?> <?php echo $is_disabled ? 'disabled' : ''; ?> />
							<span class="pf-cat-opt-label"><?php echo esc_html( $opt['label'] ); ?></span>
							<?php if ( $count !== null ) : ?><span class="pf-cat-opt-count">(<?php echo (int) $count; ?>)</span><?php endif; ?>
						</label>
					<?php else : ?>
						<button type="button" role="menuitem"
						        class="pf-cat-option pf-dropdown__option <?php echo $is_sel ? 'is-selected' : ''; ?> <?php echo $is_disabled ? 'is-disabled' : ''; ?>"
						        data-value="<?php echo esc_attr( $value ); ?>"
						        style="<?php echo esc_attr( $indent_style ); ?>"
						        <?php echo $is_disabled ? 'disabled' : ''; ?>>
							<span class="pf-cat-opt-label"><?php echo esc_html( $opt['label'] ); ?></span>
							<?php if ( $count !== null ) : ?><span class="pf-cat-opt-count">(<?php echo (int) $count; ?>)</span><?php endif; ?>
						</button>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ─── Option builders ────────────────────────────────────────── */

	private function build_format_options() {
		$out = [
			[ 'value' => 'tous',      'label' => 'Tous' ],
			[ 'value' => 'classique', 'label' => 'Classique' ],
		];
		$terms = get_terms( [
			'taxonomy'   => 'pa_format_particulier',
			'hide_empty' => true,
		] );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( $t->slug === 'classique' ) continue;
				$out[] = [ 'value' => $t->slug, 'label' => $t->name ];
			}
		}
		return $out;
	}

	private function build_scf_options( $choices ) {
		$out = [];
		foreach ( $choices as $slug => $label ) {
			$out[] = [ 'value' => $slug, 'label' => $label ];
		}
		return $out;
	}

	/* ─── Chips render ───────────────────────────────────────────── */

	private function render_chips( $atts ) {
		$chips = $this->build_chips( $atts );
		$hidden = empty( $chips ) ? 'hidden' : '';
		ob_start();
		?>
		<div class="pf-catalogue-chips" <?php echo $hidden; ?>>
			<?php foreach ( $chips as $chip ) : ?>
				<button type="button" class="pf-cat-chip"
				        data-filter="<?php echo esc_attr( $chip['filter'] ); ?>"
				        data-value="<?php echo esc_attr( $chip['value'] ); ?>"
				        aria-label="Retirer le filtre <?php echo esc_attr( $chip['label'] ); ?>">
					<span><?php echo esc_html( $chip['label'] ); ?></span>
					<span class="pf-cat-chip-x" aria-hidden="true">×</span>
				</button>
			<?php endforeach; ?>
			<?php if ( count( $chips ) >= 2 ) : ?>
				<button type="button" class="pf-cat-reset-all">Tout réinitialiser</button>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function build_chips( $atts ) {
		$chips = [];
		$bs    = new Passiflore_Bookshelf();

		if ( $atts['univers'] !== '' ) {
			$chips[] = [
				'filter' => 'univers',
				'value'  => $atts['univers'],
				'label'  => self::TOP_CATEGORY_LABELS[ $atts['univers'] ] ?? $atts['univers'],
			];
		}

		if ( $atts['category'] !== '' ) {
			$term = get_term_by( 'slug', $atts['category'], 'product_cat' );
			$chips[] = [ 'filter' => 'category', 'value' => $atts['category'],
			             'label'  => 'Thématique : ' . ( $term ? $term->name : $atts['category'] ) ];
		}

		if ( $atts['format'] !== '' ) {
			$labels = [ 'tous' => 'Tous les formats', 'classique' => 'Classique' ];
			$label  = $labels[ $atts['format'] ] ?? null;
			if ( ! $label ) {
				$t = get_term_by( 'slug', $atts['format'], 'pa_format_particulier' );
				$label = $t ? $t->name : $atts['format'];
			}
			$chips[] = [ 'filter' => 'format', 'value' => $atts['format'], 'label' => 'Format : ' . $label ];
		}

		// Single-select SCF radio filters (plus type/langues which are multi).
		foreach ( [
			'public'  => [ 'title' => 'Public', 'scf' => 'public',  'multi' => false ],
			'type'    => [ 'title' => 'Type',   'scf' => 'type',    'multi' => true  ],
			'langues' => [ 'title' => 'Langue', 'scf' => 'langues', 'multi' => true  ],
		] as $key => $meta ) {
			if ( $atts[ $key ] === '' ) continue;
			$choices = $bs->get_scf_choices( $meta['scf'] );
			if ( $meta['multi'] ) {
				foreach ( array_filter( array_map( 'trim', explode( ',', $atts[ $key ] ) ) ) as $slug ) {
					$chips[] = [ 'filter' => $key, 'value' => $slug, 'label' => $meta['title'] . ' : ' . ( $choices[ $slug ] ?? $slug ) ];
				}
			} else {
				$label = $choices[ $atts[ $key ] ] ?? $atts[ $key ];
				$chips[] = [ 'filter' => $key, 'value' => $atts[ $key ], 'label' => $meta['title'] . ' : ' . $label ];
			}
		}

		if ( $atts['decouvrir'] !== '' ) {
			$labels = [
				'nouveautes'       => 'Nouveautés',
				'prix-litteraires' => 'Prix et distinctions',
				'a-paraitre'       => 'À paraître',
			];
			$chips[] = [ 'filter' => 'decouvrir', 'value' => $atts['decouvrir'],
			             'label'  => 'Découvrir : ' . ( $labels[ $atts['decouvrir'] ] ?? $atts['decouvrir'] ) ];
		}

		// Sort chip: only if non-default
		$is_default_sort = ( $atts['orderby'] === 'date' && strtoupper( $atts['order'] ) === 'DESC' );
		if ( ! $is_default_sort && $atts['search'] === '' ) {
			$sort_labels = [ 'date' => 'Date de parution', 'titre' => 'Titre', 'prix' => 'Prix', 'pages' => 'Nombre de pages' ];
			// Arrows inverted: DESC = ↑, ASC = ↓ (matches the toggle button).
			$sens        = strtoupper( $atts['order'] ) === 'ASC' ? '↓' : '↑';
			$chips[] = [ 'filter' => 'sort', 'value' => '',
			             'label'  => 'Tri : ' . ( $sort_labels[ $atts['orderby'] ] ?? $atts['orderby'] ) . ' ' . $sens ];
		}

		return $chips;
	}
}

new Passiflore_Catalogue();
