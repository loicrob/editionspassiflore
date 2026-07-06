<?php
/**
 * Outil global « Groupes de livres » (Produits → Groupes de livres).
 *
 * Remplace les anciens champs relationnels SCF par-fiche (série, traductions,
 * « vous aimerez aussi ») par une gestion centralisée :
 *
 *  - Séries        → taxonomie `pf_serie`        (1 terme/livre, symétrique)
 *  - Traductions   → taxonomie `pf_traduction`   (1 terme/livre, symétrique)
 *  - Vous aimerez  → post meta `_pf_vous_aimerez` (liste orientée sur la source)
 *
 * L'ordre d'affichage vient du drag-reorder, stocké :
 *  - en term meta (`_pf_serie_order` / `_pf_traduction_order`) = tableau ordonné
 *    d'IDs représentants pour les groupes symétriques,
 *  - en post meta (`_pf_vous_aimerez`) pour les recos orientées.
 *
 * Granularité « œuvre » : on travaille sur le représentant du `format_groupe`
 * (édition classique). Le terme est posé sur TOUTES les éditions d'une œuvre
 * (S1) → lookup inverse direct depuis n'importe quelle édition, sans fallback.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ═══════════════════════════════════════════════════════════════
   Taxonomies (non publiques — gérées uniquement par cet outil)
   ═══════════════════════════════════════════════════════════════ */

add_action( 'init', function () {
	$args = [
		'public'            => false,
		'show_ui'           => false,
		'show_in_menu'      => false,
		'show_in_rest'      => false,
		'show_admin_column' => false,
		'hierarchical'      => false,
		'rewrite'           => false,
		'query_var'         => false,
	];
	register_taxonomy( 'pf_serie',      'product', $args + [ 'labels' => [ 'name' => 'Séries' ] ] );
	register_taxonomy( 'pf_traduction', 'product', $args + [ 'labels' => [ 'name' => 'Groupes de traduction' ] ] );
} );

/**
 * Configuration des deux groupes symétriques portés par une taxonomie.
 */
function pf_bg_group_taxonomies(): array {
	return [
		'series'      => [ 'tax' => 'pf_serie',      'order_meta' => '_pf_serie_order',      'create' => 'Créer une série',                'select_hint' => 'Sélectionnez une série à gauche, ou créez-en une.' ],
		'traductions' => [ 'tax' => 'pf_traduction', 'order_meta' => '_pf_traduction_order', 'create' => 'Créer un groupe de traduction', 'select_hint' => 'Sélectionnez un groupe de traduction à gauche, ou créez-en un.' ],
	];
}

/* ═══════════════════════════════════════════════════════════════
   Représentant d'œuvre (édition classique du format_groupe)
   ═══════════════════════════════════════════════════════════════ */

/**
 * Représentant canonique d'un livre = édition classique de son format_groupe
 * (ou le livre lui-même s'il est autonome). Identique à la résolution utilisée
 * pour le swap de format côté front, pour que les IDs stockés concordent.
 */
function pf_bg_representative( int $id ): int {
	$fg = wp_get_object_terms( $id, 'format_groupe', [ 'fields' => 'ids' ] );
	if ( is_wp_error( $fg ) || empty( $fg ) ) return $id;
	$rep = Passiflore_Bookshelf::get_group_representative( (int) $fg[0] );
	return $rep ? (int) $rep : $id;
}

/**
 * Réduit une liste de produits à leurs représentants d'œuvre, sans doublon,
 * en préservant l'ordre de première apparition.
 */
function pf_bg_dedup( array $ids ): array {
	$seen = [];
	$out  = [];
	foreach ( $ids as $id ) {
		$rep = pf_bg_representative( (int) $id );
		if ( isset( $seen[ $rep ] ) ) continue;
		$seen[ $rep ] = true;
		$out[] = $rep;
	}
	return $out;
}

/**
 * Liste de toutes les œuvres publiées (dédupliquées par format_groupe), pour la
 * recherche locale en combobox côté admin : [ {id, title, date}, … ], triée par
 * titre. Mise en cache (transient) car la déduplication interroge la taxo par
 * produit ; invalidée à chaque modification de produit.
 */
function pf_bg_all_books_data(): array {
	$cached = get_transient( 'pf_bg_all_books' );
	if ( is_array( $cached ) ) return $cached;

	$ids = get_posts( [
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'title',
		'order'          => 'ASC',
	] );
	$reps = pf_bg_dedup( array_map( 'intval', $ids ) );
	usort( $reps, fn( $a, $b ) => strcasecmp( (string) get_the_title( $a ), (string) get_the_title( $b ) ) );

	$data = pf_eb_books_data( $reps );
	set_transient( 'pf_bg_all_books', $data, DAY_IN_SECONDS );
	return $data;
}

function pf_bg_flush_books_cache() {
	delete_transient( 'pf_bg_all_books' );
}
add_action( 'save_post_product', 'pf_bg_flush_books_cache' );
add_action( 'deleted_post',      'pf_bg_flush_books_cache' );
add_action( 'trashed_post',      'pf_bg_flush_books_cache' );
add_action( 'untrashed_post',    'pf_bg_flush_books_cache' );

/* ═══════════════════════════════════════════════════════════════
   Lecture / écriture des groupes symétriques (série, traduction)
   ═══════════════════════════════════════════════════════════════ */

/**
 * Représentants membres d'un terme de groupe, triés selon l'ordre enregistré.
 * La composition vient de la taxonomie (source de vérité) ; le term meta d'ordre
 * n'est qu'un indice de tri — les entrées périmées sont ignorées, les nouvelles
 * tombent en fin.
 */
function pf_bg_group_member_reps( string $tax, string $order_meta, int $term_id ): array {
	$ids = get_posts( [
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'tax_query'      => [ [ 'taxonomy' => $tax, 'terms' => $term_id ] ],
	] );
	$reps = pf_bg_dedup( array_map( 'intval', $ids ) );

	$order = get_term_meta( $term_id, $order_meta, true );
	$order = is_array( $order ) ? array_map( 'intval', $order ) : [];
	$pos   = array_flip( $order );
	usort( $reps, function ( $a, $b ) use ( $pos ) {
		return ( $pos[ $a ] ?? PHP_INT_MAX ) <=> ( $pos[ $b ] ?? PHP_INT_MAX );
	} );
	return $reps;
}

/**
 * Enregistre la composition + l'ordre d'un terme de groupe.
 * Pose le terme sur toutes les éditions des œuvres membres (S1) et le retire des
 * éditions des œuvres exclues. `wp_set_object_terms` en mode remplacement
 * applique la contrainte « un seul terme de cette taxonomie par livre ».
 */
function pf_bg_save_group_membership( string $tax, string $order_meta, int $term_id, array $rep_ids ) {
	$rep_ids = array_values( array_unique( array_filter( array_map( 'intval', $rep_ids ) ) ) );
	$old     = pf_bg_group_member_reps( $tax, $order_meta, $term_id );

	foreach ( array_diff( $old, $rep_ids ) as $rep ) {
		foreach ( passiflore_get_format_groupe_product_ids( (int) $rep ) as $eid ) {
			wp_remove_object_terms( $eid, $term_id, $tax );
		}
	}
	foreach ( $rep_ids as $rep ) {
		foreach ( passiflore_get_format_groupe_product_ids( (int) $rep ) as $eid ) {
			wp_set_object_terms( $eid, [ $term_id ], $tax, false );
		}
	}

	update_term_meta( $term_id, $order_meta, $rep_ids );
}

/* ═══════════════════════════════════════════════════════════════
   Menu + traitement des formulaires (avant tout output)
   ═══════════════════════════════════════════════════════════════ */

add_action( 'admin_menu', function () {
	$hook = add_submenu_page(
		'edit.php?post_type=product',
		'Groupes de livres',
		'Groupes de livres',
		'edit_products',
		'pf-book-groups',
		'pf_bg_render_page'
	);
	if ( $hook ) {
		add_action( 'load-' . $hook, 'pf_bg_handle_post' );
		add_action( 'admin_enqueue_scripts', function ( $current ) use ( $hook ) {
			if ( $current === $hook ) pf_bg_enqueue();
		} );
	}
} );

function pf_bg_base_url(): string {
	return admin_url( 'edit.php?post_type=product&page=pf-book-groups' );
}

function pf_bg_redirect( array $args ) {
	wp_safe_redirect( add_query_arg( $args, pf_bg_base_url() ) );
	exit;
}

function pf_bg_handle_post() {
	if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return;
	if ( ! isset( $_POST['pf_bg_action'] ) ) return;
	if ( ! current_user_can( 'edit_products' ) ) return;
	check_admin_referer( 'pf_bg_save', 'pf_bg_nonce' );

	$action = sanitize_key( $_POST['pf_bg_action'] );
	$tab    = sanitize_key( $_POST['tab'] ?? 'series' );
	$groups = pf_bg_group_taxonomies();

	// Actions sur les groupes symétriques (série / traduction).
	if ( isset( $groups[ $tab ] ) ) {
		$cfg = $groups[ $tab ];
		$tax = $cfg['tax'];

		if ( $action === 'create_term' ) {
			$name = sanitize_text_field( wp_unslash( $_POST['pf_bg_name'] ?? '' ) );
			if ( $name !== '' ) {
				$res = wp_insert_term( $name, $tax );
				$gid = ( ! is_wp_error( $res ) ) ? (int) $res['term_id'] : 0;
				pf_bg_redirect( [ 'tab' => $tab, 'group_id' => $gid, 'msg' => 'created' ] );
			}
			pf_bg_redirect( [ 'tab' => $tab, 'msg' => 'error' ] );
		}

		$gid = (int) ( $_POST['group_id'] ?? 0 );

		if ( $action === 'rename_term' && $gid ) {
			$name = sanitize_text_field( wp_unslash( $_POST['pf_bg_name'] ?? '' ) );
			if ( $name !== '' ) wp_update_term( $gid, $tax, [ 'name' => $name ] );
			pf_bg_redirect( [ 'tab' => $tab, 'group_id' => $gid, 'msg' => 'saved' ] );
		}

		if ( $action === 'delete_term' && $gid ) {
			wp_delete_term( $gid, $tax );
			pf_bg_redirect( [ 'tab' => $tab, 'msg' => 'deleted' ] );
		}

		if ( $action === 'save_members' && $gid ) {
			$ids = array_map( 'intval', (array) ( $_POST['pf_bg_books'] ?? [] ) );
			pf_bg_save_group_membership( $tax, $cfg['order_meta'], $gid, $ids );
			pf_bg_redirect( [ 'tab' => $tab, 'group_id' => $gid, 'msg' => 'saved' ] );
		}
	}

	// Action « vous aimerez aussi » (orientée, post meta sur la source).
	if ( $tab === 'aimerez' && $action === 'save_aimerez' ) {
		$source = pf_bg_representative( (int) ( $_POST['pf_bg_source'] ?? 0 ) );
		if ( $source ) {
			$targets = pf_bg_dedup( array_map( 'intval', (array) ( $_POST['pf_bg_books'] ?? [] ) ) );
			$targets = array_values( array_filter( $targets, fn( $t ) => $t !== $source ) );
			if ( $targets ) {
				update_post_meta( $source, '_pf_vous_aimerez', $targets );
			} else {
				delete_post_meta( $source, '_pf_vous_aimerez' );
			}
			pf_bg_redirect( [ 'tab' => 'aimerez', 'source' => $source, 'msg' => 'saved' ] );
		}
		pf_bg_redirect( [ 'tab' => 'aimerez', 'msg' => 'error' ] );
	}

	pf_bg_redirect( [ 'tab' => $tab ] );
}

/* ═══════════════════════════════════════════════════════════════
   Assets
   ═══════════════════════════════════════════════════════════════ */

function pf_bg_enqueue() {
	wp_enqueue_script(
		'pf-book-picker',
		get_stylesheet_directory_uri() . '/assets/js/book-picker.js',
		[ 'jquery', 'jquery-ui-sortable' ],
		'1.0.0',
		true
	);
	wp_enqueue_script(
		'pf-book-groups-admin',
		get_stylesheet_directory_uri() . '/assets/js/book-groups-admin.js',
		[ 'pf-book-picker' ],
		'1.0.0',
		true
	);
	wp_localize_script( 'pf-book-groups-admin', 'pfBG', [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'pf_book_groups_ajax' ),
		'baseUrl' => pf_bg_base_url(),
		'books'   => pf_bg_all_books_data(),
	] );

	wp_add_inline_style( 'wp-admin', '
		.pf-bg-layout { display:flex; gap:24px; align-items:flex-start; }
		.pf-bg-sidebar { width:260px; flex-shrink:0; }
		.pf-bg-sidebar ul { margin:0 0 12px; }
		.pf-bg-sidebar li { margin:0; }
		.pf-bg-sidebar li a { display:block; padding:6px 10px; border:1px solid #e0e0e0; border-bottom:none; text-decoration:none; }
		.pf-bg-sidebar li:last-child a { border-bottom:1px solid #e0e0e0; }
		.pf-bg-sidebar li a.current { background:#2271b1; color:#fff; border-color:#2271b1; font-weight:600; }
		.pf-bg-editor { flex:1; min-width:0; background:#fff; border:1px solid #e0e0e0; padding:16px 20px; }
		.pf-bg-wrap { display:flex; flex-direction:column; gap:12px; max-width:520px; }
		.pf-bg-add { display:flex; flex-direction:column; gap:6px; position:relative; }
		.pf-bg-author-row { display:flex; gap:6px; }
		.pf-bg-author-row select { flex:1; }
		.pf-bg-source-wrap { position:relative; max-width:520px; }
		#pf-bg-search, #pf-bg-source-search { width:100%; box-sizing:border-box; }
		#pf-bg-results, #pf-bg-source-results { margin:0; padding:0; list-style:none; border:1px solid #ddd; border-radius:3px; max-height:280px; overflow-y:auto; display:none; position:absolute; left:0; right:0; top:100%; z-index:50; background:#fff; box-shadow:0 6px 18px rgba(0,0,0,.14); }
		#pf-bg-results li, #pf-bg-source-results li { display:flex; align-items:center; justify-content:space-between; padding:5px 8px; border-bottom:1px solid #f0f0f0; }
		#pf-bg-results li:last-child, #pf-bg-source-results li:last-child { border-bottom:none; }
		#pf-bg-source-results li { cursor:pointer; }
		#pf-bg-source-results li:hover { background:#f6f7f7; }
		#pf-bg-results li.pf-bg-added { color:#999; }
		#pf-bg-results li span { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
		.pf-bg-selected { border-top:1px solid #eee; padding-top:10px; }
		.pf-bg-selected-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; }
		.pf-bg-selected-label { margin:0; font-weight:600; font-size:12px; text-transform:uppercase; color:#666; }
		#pf-bg-list { margin:0; padding:0; list-style:none; }
		#pf-bg-list li { display:flex; align-items:center; gap:6px; padding:4px 0; border-bottom:1px solid #f5f5f5; }
		#pf-bg-list li span:not(.pf-bg-handle) { flex:1; }
		.pf-bg-handle { cursor:grab; color:#aaa; flex-shrink:0; }
		.pf-bg-handle:active { cursor:grabbing; }
		.ui-sortable-helper { background:#fff; box-shadow:0 2px 6px rgba(0,0,0,.15); }
		.ui-sortable-placeholder { border:1px dashed #ccc; visibility:visible !important; height:28px; }
		.pf-bg-remove { background:none; border:none; cursor:pointer; color:#a00; padding:0; width:20px; height:20px; flex-shrink:0; }
		.pf-bg-remove:hover { color:#d00; }
		#pf-bg-empty, #pf-bg-feedback { margin:4px 0; font-size:12px; color:#888; }
		.pf-bg-save-row { margin-top:14px; }
	' );
}

/* ═══════════════════════════════════════════════════════════════
   Rendu de la page
   ═══════════════════════════════════════════════════════════════ */

function pf_bg_notice() {
	$map = [
		'created' => 'Groupe créé.',
		'saved'   => 'Modifications enregistrées.',
		'deleted' => 'Groupe supprimé.',
		'error'   => 'Une erreur est survenue.',
	];
	$msg = $map[ sanitize_key( $_GET['msg'] ?? '' ) ] ?? '';
	if ( $msg ) {
		$cls = ( ( $_GET['msg'] ?? '' ) === 'error' ) ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $cls ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}
}

function pf_bg_render_page() {
	if ( ! current_user_can( 'edit_products' ) ) return;

	$tabs = [ 'series' => 'Séries', 'traductions' => 'Traductions', 'aimerez' => 'Vous aimerez aussi' ];
	$tab  = sanitize_key( $_GET['tab'] ?? 'series' );
	if ( ! isset( $tabs[ $tab ] ) ) $tab = 'series';

	echo '<div class="wrap">';
	echo '<h1>Groupes de livres</h1>';
	pf_bg_notice();

	echo '<nav class="nav-tab-wrapper">';
	foreach ( $tabs as $slug => $label ) {
		$url = add_query_arg( [ 'tab' => $slug ], pf_bg_base_url() );
		$cls = 'nav-tab' . ( $slug === $tab ? ' nav-tab-active' : '' );
		echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</nav>';

	echo '<div style="margin-top:16px">';
	if ( $tab === 'aimerez' ) {
		pf_bg_render_aimerez_tab();
	} else {
		pf_bg_render_group_tab( $tab );
	}
	echo '</div></div>';
}

/**
 * Bloc HTML du picker (recherche + ajout par auteur + liste triable).
 */
function pf_bg_render_picker( array $current_reps ) {
	$terms = get_terms( [ 'taxonomy' => 'auteur', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ] );
	if ( is_wp_error( $terms ) ) $terms = [];
	?>
	<div class="pf-bg-wrap">
		<div class="pf-bg-add">
			<div class="pf-bg-author-row">
				<select id="pf-bg-author-select">
					<option value="">— Livres d'un auteur —</option>
					<?php foreach ( $terms as $t ) : ?>
						<option value="<?php echo esc_attr( $t->term_id ); ?>"><?php echo esc_html( $t->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" id="pf-bg-author-btn" class="button">Ajouter</button>
			</div>
			<input type="search" id="pf-bg-search" placeholder="Rechercher un livre…" autocomplete="off">
			<ul id="pf-bg-results"></ul>
		</div>

		<div class="pf-bg-selected">
			<div class="pf-bg-selected-header">
				<p class="pf-bg-selected-label">Livres du groupe (glisser pour ordonner)</p>
				<button type="button" id="pf-bg-clear" class="button button-small">Retirer tout</button>
			</div>
			<ul id="pf-bg-list">
				<?php foreach ( $current_reps as $pid ) :
					$title = get_the_title( $pid );
					if ( ! $title ) continue;
				?>
					<li data-id="<?php echo (int) $pid; ?>" data-date="<?php echo esc_attr( (string) get_post_meta( $pid, 'date_de_parution', true ) ); ?>">
						<span class="pf-bg-handle dashicons dashicons-menu" aria-hidden="true"></span>
						<span><?php echo esc_html( $title ); ?></span>
						<button type="button" class="pf-bg-remove dashicons dashicons-no-alt" aria-label="Retirer"></button>
						<input type="hidden" name="pf_bg_books[]" value="<?php echo (int) $pid; ?>">
					</li>
				<?php endforeach; ?>
			</ul>
			<p id="pf-bg-empty" <?php echo $current_reps ? 'style="display:none"' : ''; ?>>Aucun livre dans ce groupe.</p>
		</div>
		<p id="pf-bg-feedback"></p>
	</div>
	<?php
}

function pf_bg_render_group_tab( string $key ) {
	$groups = pf_bg_group_taxonomies();
	$cfg    = $groups[ $key ];
	$tax    = $cfg['tax'];

	$terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ] );
	if ( is_wp_error( $terms ) ) $terms = [];

	$gid     = (int) ( $_GET['group_id'] ?? 0 );
	$current = ( $gid && get_term( $gid, $tax ) && ! is_wp_error( get_term( $gid, $tax ) ) ) ? get_term( $gid, $tax ) : null;

	echo '<div class="pf-bg-layout">';

	// Colonne de gauche : liste + création.
	echo '<div class="pf-bg-sidebar">';
	if ( $terms ) {
		echo '<ul>';
		foreach ( $terms as $t ) {
			$url = add_query_arg( [ 'tab' => $key, 'group_id' => $t->term_id ], pf_bg_base_url() );
			$cls = ( $current && $t->term_id === $current->term_id ) ? ' class="current"' : '';
			// Nombre d'œuvres (dédupliqué par format_groupe), pas d'éditions.
			$n   = count( pf_bg_group_member_reps( $tax, $cfg['order_meta'], (int) $t->term_id ) );
			echo '<li><a' . $cls . ' href="' . esc_url( $url ) . '">' . esc_html( $t->name ) . ' <span style="opacity:.6">(' . $n . ')</span></a></li>';
		}
		echo '</ul>';
	} else {
		echo '<p>Aucun groupe pour l\'instant.</p>';
	}
	echo '<form method="post" action="' . esc_url( pf_bg_base_url() ) . '">';
	wp_nonce_field( 'pf_bg_save', 'pf_bg_nonce' );
	echo '<input type="hidden" name="pf_bg_action" value="create_term">';
	echo '<input type="hidden" name="tab" value="' . esc_attr( $key ) . '">';
	echo '<input type="text" name="pf_bg_name" placeholder="Nom du nouveau groupe" style="width:100%;margin-bottom:6px" required>';
	echo '<button type="submit" class="button button-primary">' . esc_html( $cfg['create'] ) . '</button>';
	echo '</form>';
	echo '</div>';

	// Colonne de droite : éditeur.
	echo '<div class="pf-bg-editor">';
	if ( ! $current ) {
		echo '<p>' . esc_html( $cfg['select_hint'] ) . '</p>';
	} else {
		$reps = pf_bg_group_member_reps( $tax, $cfg['order_meta'], $current->term_id );

		// Renommer / supprimer.
		echo '<form method="post" action="' . esc_url( pf_bg_base_url() ) . '" style="display:flex;gap:8px;align-items:center;margin-bottom:16px">';
		wp_nonce_field( 'pf_bg_save', 'pf_bg_nonce' );
		echo '<input type="hidden" name="pf_bg_action" value="rename_term">';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $key ) . '">';
		echo '<input type="hidden" name="group_id" value="' . (int) $current->term_id . '">';
		echo '<input type="text" name="pf_bg_name" value="' . esc_attr( $current->name ) . '" style="flex:1;max-width:360px">';
		echo '<button type="submit" class="button">Renommer</button>';
		echo '</form>';

		// Composition (picker).
		echo '<form method="post" action="' . esc_url( pf_bg_base_url() ) . '">';
		wp_nonce_field( 'pf_bg_save', 'pf_bg_nonce' );
		echo '<input type="hidden" name="pf_bg_action" value="save_members">';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $key ) . '">';
		echo '<input type="hidden" name="group_id" value="' . (int) $current->term_id . '">';
		pf_bg_render_picker( $reps );
		echo '<div class="pf-bg-save-row"><button type="submit" class="button button-primary">Enregistrer la composition</button></div>';
		echo '</form>';

		// Suppression (formulaire séparé).
		echo '<form method="post" action="' . esc_url( pf_bg_base_url() ) . '" style="margin-top:24px" onsubmit="return confirm(\'Supprimer ce groupe ? Les livres ne sont pas supprimés, seulement dissociés.\');">';
		wp_nonce_field( 'pf_bg_save', 'pf_bg_nonce' );
		echo '<input type="hidden" name="pf_bg_action" value="delete_term">';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $key ) . '">';
		echo '<input type="hidden" name="group_id" value="' . (int) $current->term_id . '">';
		echo '<button type="submit" class="button-link-delete" style="color:#b32d2e;background:none;border:none;cursor:pointer;padding:0">Supprimer ce groupe</button>';
		echo '</form>';
	}
	echo '</div>';

	echo '</div>';
}

/**
 * Livres (représentants) disposant d'une liste « Vous aimerez aussi » non vide,
 * triés par titre.
 */
function pf_bg_aimerez_sources(): array {
	$ids = get_posts( [
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'title',
		'order'          => 'ASC',
		'meta_query'     => [ [ 'key' => '_pf_vous_aimerez', 'compare' => 'EXISTS' ] ],
	] );
	$out = [];
	foreach ( $ids as $id ) {
		$v = get_post_meta( $id, '_pf_vous_aimerez', true );
		if ( is_array( $v ) && array_filter( array_map( 'intval', $v ) ) ) $out[] = (int) $id;
	}
	return $out;
}

function pf_bg_render_aimerez_tab() {
	$source  = pf_bg_representative( (int) ( $_GET['source'] ?? 0 ) );
	$sources = pf_bg_aimerez_sources();

	echo '<div class="pf-bg-layout">';

	// Colonne de gauche : livres ayant une liste + recherche d'un livre source.
	echo '<div class="pf-bg-sidebar">';
	if ( $sources ) {
		echo '<ul>';
		foreach ( $sources as $sid ) {
			$title = get_the_title( $sid );
			if ( ! $title ) continue;
			$targets = (array) get_post_meta( $sid, '_pf_vous_aimerez', true );
			$n       = count( array_filter( array_map( 'intval', $targets ) ) );
			$url     = add_query_arg( [ 'tab' => 'aimerez', 'source' => $sid ], pf_bg_base_url() );
			$cls     = ( $source && $sid === $source ) ? ' class="current"' : '';
			echo '<li><a' . $cls . ' href="' . esc_url( $url ) . '">' . esc_html( $title ) . ' <span style="opacity:.6">(' . $n . ')</span></a></li>';
		}
		echo '</ul>';
	} else {
		echo '<p>Aucun livre avec une liste pour l\'instant.</p>';
	}
	echo '<p class="pf-bg-selected-label" style="margin-top:12px">Choisir un livre source</p>';
	echo '<div class="pf-bg-source-wrap">';
	echo '<input type="search" id="pf-bg-source-search" placeholder="Rechercher un livre…" autocomplete="off">';
	echo '<ul id="pf-bg-source-results"></ul>';
	echo '</div>';
	echo '</div>';

	// Colonne de droite : éditeur de la liste du livre source sélectionné.
	echo '<div class="pf-bg-editor">';
	if ( ! $source ) {
		echo '<p>Choisissez un livre source à gauche (ou recherchez-en un) pour composer sa liste « Vous aimerez aussi » — liens orientés : la réciproque n\'est pas automatique.</p>';
	} else {
		$targets = (array) get_post_meta( $source, '_pf_vous_aimerez', true );
		$targets = pf_bg_dedup( array_map( 'intval', $targets ) );
		$targets = array_values( array_filter( $targets, fn( $t ) => $t !== $source ) );

		echo '<h2 style="margin-top:0">' . esc_html( get_the_title( $source ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( pf_bg_base_url() ) . '">';
		wp_nonce_field( 'pf_bg_save', 'pf_bg_nonce' );
		echo '<input type="hidden" name="pf_bg_action" value="save_aimerez">';
		echo '<input type="hidden" name="tab" value="aimerez">';
		echo '<input type="hidden" name="pf_bg_source" value="' . (int) $source . '">';
		pf_bg_render_picker( $targets );
		echo '<div class="pf-bg-save-row"><button type="submit" class="button button-primary">Enregistrer</button></div>';
		echo '</form>';
	}
	echo '</div>';

	echo '</div>';
}

/* ═══════════════════════════════════════════════════════════════
   AJAX (recherche + livres d'un auteur)
   ═══════════════════════════════════════════════════════════════ */

add_action( 'wp_ajax_pf_bg_author_books', function () {
	check_ajax_referer( 'pf_book_groups_ajax', 'nonce' );
	if ( ! current_user_can( 'edit_products' ) ) wp_send_json_error();

	$term_id = (int) ( $_POST['term_id'] ?? 0 );
	if ( $term_id <= 0 ) wp_send_json_error();

	$ids = pf_bg_dedup( passiflore_product_ids_by_auteur_terms( [ $term_id ] ) );
	usort( $ids, function ( $a, $b ) {
		return strcmp(
			(string) get_post_meta( $b, 'date_de_parution', true ),
			(string) get_post_meta( $a, 'date_de_parution', true )
		);
	} );
	wp_send_json_success( pf_eb_books_data( $ids ) );
} );
