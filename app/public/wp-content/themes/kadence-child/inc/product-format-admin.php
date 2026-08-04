<?php
/**
 * Saisie de l'œuvre et du format sur l'écran d'édition produit.
 *
 * Remplace trois gestes indépendants — cocher un `format_groupe`, taper un
 * titre suffixé à la main, ajouter l'attribut `pa_format_particulier` dans
 * l'onglet Attributs — par une saisie unique : on choisit l'œuvre, puis le
 * format ; le titre, le groupe et l'attribut en découlent.
 *
 * Ce fichier est le SEUL point d'écriture de ce triplet depuis le formulaire.
 * Le modèle (ordre des formats, composition des titres, représentant) vit dans
 * inc/format-groupe.php.
 *
 * Chaîne d'enregistrement :
 *   wp_insert_post_data @9   → compose post_title = racine + suffixe
 *   (post-slug-sync @10      → dérive le permalien du titre composé)
 *   woocommerce_process_… @10→ WooCommerce vide _product_attributes (onglet masqué)
 *   save_post_product @25    → groupe, format + attribut, représentant, cascade
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Verrou de ré-entrance, partagé par le filtre de composition et le gestionnaire
 * d'enregistrement : la cascade de renommage appelle wp_update_post() sur les
 * éditions sœurs, ce qui re-déclencherait les deux — et recomposerait un titre
 * déjà composé en « Titre (grands caractères) (grands caractères) ».
 */
function pf_fmt_busy( ?bool $set = null ): bool {
	static $busy = false;
	if ( $set !== null ) $busy = $set;
	return $busy;
}

/* ═══════════════════════════════════════════════════════════════
   Données du sélecteur d'œuvre
   ═══════════════════════════════════════════════════════════════ */

/**
 * Toutes les œuvres sélectionnables : un groupe de formats existant, ou un
 * livre encore autonome. Triées par date de parution décroissante (comme la
 * liste des produits).
 *
 * `taken` = [ slug_de_format => product_id ] des formats déjà occupés dans
 * l'œuvre, `''` désignant l'édition classique.
 */
function pf_format_works_data(): array {
	$cached = get_transient( 'pf_format_works' );
	if ( is_array( $cached ) ) return $cached;

	$works = [];

	$groups = get_terms( [ 'taxonomy' => 'format_groupe', 'hide_empty' => false ] );
	if ( ! is_wp_error( $groups ) ) {
		foreach ( $groups as $g ) {
			$members = pf_group_members( (int) $g->term_id );
			if ( empty( $members ) ) continue;

			$rep   = pf_group_representative( (int) $g->term_id );
			$taken = [];
			foreach ( $members as $pid => $slug ) {
				$taken[ $slug ] = (int) $pid;
			}
			$works[] = [
				'id'    => (int) $rep,
				'group' => (int) $g->term_id,
				'title' => pf_format_root( (int) $rep ),
				'date'  => (string) get_post_meta( $rep, 'date_de_parution', true ),
				'taken' => $taken,
			];
		}
	}

	$standalone = get_posts( [
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'tax_query'      => [ [ 'taxonomy' => 'format_groupe', 'operator' => 'NOT EXISTS' ] ],
	] );
	foreach ( $standalone as $pid ) {
		$pid = (int) $pid;
		$works[] = [
			'id'    => $pid,
			'group' => 0,
			'title' => pf_format_root( $pid ),
			'date'  => (string) get_post_meta( $pid, 'date_de_parution', true ),
			'taken' => [ pf_format_of( $pid ) => $pid ],
		];
	}

	usort( $works, fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );

	set_transient( 'pf_format_works', $works, DAY_IN_SECONDS );
	return $works;
}

function pf_format_flush_works_cache() {
	delete_transient( 'pf_format_works' );
}
add_action( 'save_post_product', 'pf_format_flush_works_cache' );
add_action( 'deleted_post',      'pf_format_flush_works_cache' );
add_action( 'trashed_post',      'pf_format_flush_works_cache' );
add_action( 'untrashed_post',    'pf_format_flush_works_cache' );

/** Valeur du sélecteur pour un produit : `g:<terme>`, `p:<produit>` ou ''. */
function pf_fmt_work_value( int $post_id ): string {
	$group = pf_format_group_of( $post_id );
	return $group ? 'g:' . $group : '';
}

/**
 * Terme `format_groupe` désigné par la valeur du sélecteur.
 *
 * ⚠️ Quand la valeur désigne un LIVRE qui appartient déjà à un groupe, on
 * rejoint CE groupe — on n'en crée pas un second. C'est exactement le défaut
 * que cette refonte corrige : sans cela, deux formats de la même œuvre se
 * retrouveraient dans deux groupes distincts.
 *
 * @param bool $create Autoriser la création d'un groupe (false en validation).
 * @return int 0 = livre autonome
 */
function pf_fmt_resolve_group( string $raw, int $post_id, bool $create = true ): int {
	$raw = trim( $raw );
	if ( $raw === '' ) return 0;

	if ( strpos( $raw, 'g:' ) === 0 ) {
		$term_id = (int) substr( $raw, 2 );
		$term    = get_term( $term_id, 'format_groupe' );
		return ( $term && ! is_wp_error( $term ) ) ? $term_id : 0;
	}

	if ( strpos( $raw, 'p:' ) !== 0 ) return 0;

	$target = (int) substr( $raw, 2 );
	if ( $target <= 0 || $target === $post_id ) return 0;
	if ( get_post_type( $target ) !== 'product' ) return 0;

	$existing = pf_format_group_of( $target );
	if ( $existing ) return $existing;
	if ( ! $create ) return 0;

	// Livre autonome : créer le groupe et l'y rattacher lui aussi.
	$name = pf_format_root( $target );
	if ( $name === '' ) $name = get_the_title( $target );

	$slug = wp_unique_term_slug( sanitize_title( $name ), (object) [
		'taxonomy'    => 'format_groupe',
		'parent'      => 0,
		'term_group'  => 0,
	] );
	// Slug explicite : wp_insert_term() refuserait un nom déjà pris sans lui, or
	// deux œuvres distinctes peuvent légitimement porter le même titre.
	$res = wp_insert_term( $name, 'format_groupe', [ 'slug' => $slug ] );
	if ( is_wp_error( $res ) ) return 0;

	$new_id = (int) $res['term_id'];
	wp_set_object_terms( $target, [ $new_id ], 'format_groupe', false );
	return $new_id;
}

/* ═══════════════════════════════════════════════════════════════
   Rendu de l'écran
   ═══════════════════════════════════════════════════════════════ */

/** L'écran d'édition d'un produit ? */
function pf_fmt_is_product_screen( $post ): bool {
	return $post instanceof WP_Post && $post->post_type === 'product';
}

/**
 * Le champ natif « Nom de produit » affiche la RACINE, pas le titre stocké.
 *
 * wp-admin/post.php charge le post avec le contexte `edit`, ce qui fait passer
 * post_title par ce filtre — pas de JS, donc pas de scintillement.
 *
 * ⚠️ Garde volontairement étroite : ce filtre sert ailleurs (Quick Edit, autres
 * écrans), où le titre réel doit continuer de s'afficher.
 */
add_filter( 'edit_post_title', function ( $title, $post_id ) {
	global $pagenow;
	if ( $pagenow !== 'post.php' ) return $title;
	if ( ( $_GET['action'] ?? '' ) !== 'edit' ) return $title;
	if ( get_post_type( $post_id ) !== 'product' ) return $title;

	return pf_format_root( (int) $post_id );
}, 10, 2 );

/** Placeholder du champ natif. */
add_filter( 'enter_title_here', function ( $text, $post ) {
	return pf_fmt_is_product_screen( $post ) ? 'Titre de l’œuvre (sans le format)' : $text;
}, 10, 2 );

/** Panneau « Œuvre », au-dessus du champ titre. */
add_action( 'edit_form_top', function ( $post ) {
	if ( ! pf_fmt_is_product_screen( $post ) ) return;

	$value = pf_fmt_work_value( $post->ID );
	?>
	<div class="pf-fmt-panel" id="pf-fmt-work-panel">
		<?php wp_nonce_field( 'pf_fmt_save', 'pf_format_nonce' ); ?>
		<label class="pf-fmt-label" for="pf-fmt-search">Ce produit est un format du livre existant :</label>
		<div class="pf-fmt-combo">
			<input type="text" id="pf-fmt-search" autocomplete="off"
			       placeholder="Rechercher un livre ou un groupe de formats…">
			<ul id="pf-fmt-results" class="pf-fmt-results" hidden></ul>
		</div>
		<input type="hidden" name="pf_fmt_work" id="pf-fmt-work" value="<?php echo esc_attr( $value ); ?>">
		<p id="pf-fmt-current" class="pf-fmt-current" hidden>
			<span class="pf-fmt-chip"></span>
			<button type="button" class="button-link pf-fmt-detach">Retirer du groupe</button>
		</p>
		<p id="pf-fmt-taken" class="description" hidden></p>
		<p id="pf-fmt-warning" class="pf-fmt-warning" hidden></p>
	</div>
	<?php
}, 10 );

/** Format particulier + aperçu du nom composé, juste sous le champ titre. */
add_action( 'edit_form_before_permalink', function ( $post ) {
	if ( ! pf_fmt_is_product_screen( $post ) ) return;

	$current = pf_format_of( $post->ID );
	?>
	<div class="pf-fmt-panel pf-fmt-panel--title">
		<p class="pf-fmt-rename-row">
			<button type="button" class="button button-small" id="pf-fmt-rename">
				✎ Changer le titre du livre et de tous ses formats
			</button>
			<input type="hidden" name="pf_fmt_rename" id="pf-fmt-rename-flag" value="">
		</p>
		<p class="pf-fmt-row">
			<label class="pf-fmt-label" for="pf-fmt-format">Format particulier</label>
			<select name="pf_fmt_format" id="pf-fmt-format">
				<option value="">Classique (aucun format particulier)</option>
				<?php foreach ( pf_format_terms() as $term ) : ?>
					<option value="<?php echo esc_attr( $term->slug ); ?>"
						<?php selected( $current, $term->slug ); ?>>
						<?php echo esc_html( $term->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description">
			Nom du produit : <strong id="pf-fmt-preview"></strong>
		</p>
	</div>
	<?php
}, 10 );

/** Case « représentant du groupe », sous le bloc titre. */
add_action( 'edit_form_after_title', function ( $post ) {
	if ( ! pf_fmt_is_product_screen( $post ) ) return;

	$group = pf_format_group_of( $post->ID );
	$rep   = $group ? pf_group_representative( $group ) : 0;
	$is_manual = $group && (int) get_term_meta( $group, PF_GROUP_REP_META, true ) === $post->ID;

	$order_labels  = [ 'Classique' ];
	$format_labels = [ '' => 'Classique' ];
	foreach ( pf_format_terms() as $term ) {
		$order_labels[] = $term->name;
		$format_labels[ $term->slug ] = $term->name;
	}
	?>
	<div class="pf-fmt-panel" id="pf-fmt-rep-panel" <?php echo $group ? '' : 'hidden'; ?>>
		<label>
			<input type="checkbox" name="pf_fmt_rep" id="pf-fmt-rep" value="1" <?php checked( $is_manual ); ?>>
			<strong>Ce format est le représentant du groupe</strong>
		</label>
		<p class="description">
			Par défaut, <strong>le représentant</strong> est le <strong>premier format</strong> existant dans un groupe <strong>selon cet ordre</strong> :
			<em><?php echo esc_html( implode( ' → ', $order_labels ) ); ?></em> (le représentant est celui qui s’affiche dans une étagère où le livre apparaît). Vous pouvez <strong>changer l’ordre</strong> de cette liste sur la page des <a href='/wp-admin/edit-tags.php?taxonomy=pa_format_particulier&post_type=product' target='_blank'>formats particuliers</a>.
			Un format épuisé cède sa place au suivant dans la liste. Si tous les formats sont épuisés, celui affiché sera à nouveau le représentant.
			<?php if ( $rep ) :
				$rep_label = $format_labels[ pf_format_of( $rep ) ] ?? pf_format_of( $rep );
			?>
				<br>Représentant actuel du groupe :
				<?php if ( (int) $rep === (int) $post->ID ) : ?>
					<strong><?php echo esc_html( $rep_label ); ?> (cette page produit)</strong>.
				<?php else : ?>
					<strong><a href="<?php echo esc_url( admin_url( 'post.php?action=edit&post=' . $rep ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $rep_label ); ?></a></strong>.
				<?php endif; ?>
			<?php endif; ?>
		</p>
	</div>
	<?php
}, 10 );

/* ═══════════════════════════════════════════════════════════════
   Assets
   ═══════════════════════════════════════════════════════════════ */

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	global $post;
	if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
	if ( ! pf_fmt_is_product_screen( $post ) ) return;

	pf_enqueue_book_filter();
	wp_enqueue_script(
		'pf-product-format-admin',
		get_stylesheet_directory_uri() . '/assets/js/product-format-admin.js',
		[ 'jquery', 'pf-book-filter' ],
		filemtime( get_stylesheet_directory() . '/assets/js/product-format-admin.js' ),
		true
	);

	$formats = [ [ 'slug' => '', 'name' => 'Classique', 'suffix' => '' ] ];
	foreach ( pf_format_terms() as $term ) {
		$formats[] = [
			'slug'   => $term->slug,
			'name'   => $term->name,
			'suffix' => pf_format_suffix( $term->slug ),
		];
	}

	// Le livre courant ne peut pas être sa propre œuvre.
	$works = array_values( array_filter(
		pf_format_works_data(),
		fn( $w ) => ! ( $w['group'] === 0 && $w['id'] === $post->ID )
	) );

	// ⚠️ wp_add_inline_script plutôt que wp_localize_script : ce dernier
	// transtype en chaîne toutes les valeurs scalaires de premier niveau, et
	// `postId` doit rester un entier — il est comparé aux IDs (entiers, car
	// imbriqués donc épargnés) de `works[].taken`.
	wp_add_inline_script(
		'pf-product-format-admin',
		'var pfFmt = ' . wp_json_encode( [
			'works'    => $works,
			'formats'  => $formats,
			'postId'   => (int) $post->ID,
			'work'     => pf_fmt_work_value( $post->ID ),
			'format'   => pf_format_of( $post->ID ),
			'coherent' => pf_format_title_is_coherent( $post->ID ),
			'editUrl'  => admin_url( 'post.php?action=edit&post=' ),
		] ) . ';',
		'before'
	);

	wp_add_inline_style( 'wp-admin', '
		.pf-fmt-panel { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:12px; margin:12px 0; }
		.pf-fmt-panel--title { margin-top:6px; }
		.pf-fmt-label { display:block; font-weight:600; margin-bottom:4px; }
		.pf-fmt-combo { position:relative; max-width:520px; }
		.pf-fmt-combo input[type=text] { width:100%; }
		.pf-fmt-results { position:absolute; z-index:20; left:0; right:0; margin:0; max-height:260px;
			overflow-y:auto; background:#fff; border:1px solid #c3c4c7; border-top:none; }
		.pf-fmt-results li { margin:0; padding:6px 10px; cursor:pointer; }
		.pf-fmt-results li:hover, .pf-fmt-results li.is-active { background:#f0f0f1; }
		.pf-fmt-results li .pf-fmt-meta { color:#787c82; font-size:11px; margin-left:6px; }
		.pf-fmt-current { margin:8px 0 4px; }
		.pf-fmt-chip { display:inline-block; background:#f0f0f1; border-radius:3px; padding:3px 8px; font-weight:600; }
		.pf-fmt-current .pf-fmt-detach { margin-left:8px; color:#b32d2e; }
		.pf-fmt-warning { color:#8a6d3b; background:#fcf8e3; border-left:3px solid #dba617; padding:6px 10px; margin:8px 0 0; }
		.pf-fmt-row { display:flex; align-items:center; gap:8px; margin:8px 0; }
		.pf-fmt-row .pf-fmt-label { margin:0; }
		.pf-fmt-rename-row { margin:0 0 8px; }
		#title[readonly] { background:#f6f7f7; color:#50575e; }
	' );
} );

/* ═══════════════════════════════════════════════════════════════
   Onglet Attributs & métabox format_groupe : remplacés par ce qui précède
   ═══════════════════════════════════════════════════════════════ */

/**
 * ⚠️ Sans `attribute_names` dans le POST, WC_Meta_Box_Product_Data::save() @10
 * VIDE `_product_attributes` en laissant les relations de termes intactes.
 * C'est pf_fmt_save_product() @25 qui ré-écrit les deux ensemble — masquer
 * l'onglet n'est sûr que grâce à lui. Restaurer l'onglet si un autre attribut
 * produit devait exister un jour.
 */
add_filter( 'woocommerce_product_data_tabs', function ( $tabs ) {
	unset( $tabs['attribute'] );
	return $tabs;
} );

add_action( 'add_meta_boxes', function () {
	remove_meta_box( 'format_groupediv', 'product', 'side' );
}, 99 );

/**
 * Le duplicateur natif de WooCommerce (Produits → Dupliquer) ignore ce
 * triplet : c'est une requête GET, sans `pf_format_nonce`, donc ni
 * `wp_insert_post_data`@9 ni `pf_fmt_save_product`@25 ne s'exécutent. Il clone
 * pourtant la relation de terme `pa_format_particulier` (et l'attribut qui va
 * avec) sans jamais toucher `format_groupe` — une copie de « Titre (grands
 * caractères) » gardait donc le même format que l'original, prête à entrer en
 * collision au premier rattachement à un groupe. On ramène systématiquement
 * la copie à l'état « classique » (aucun terme, aucun attribut) ; c'est le
 * futur choix de format sur l'écran d'édition, validé par
 * `acf/validate_save_post` ci-dessous, qui lui donnera un format réel.
 */
add_action( 'woocommerce_product_duplicate', function ( $duplicate, $product ) {
	$new_id = $duplicate->get_id();

	wp_set_object_terms( $new_id, [], 'pa_format_particulier', false );
	delete_post_meta( $new_id, '_product_attributes' );

	wp_update_post( [
		'ID'         => $new_id,
		'post_title' => sprintf( __( '%s (Copy)', 'woocommerce' ), pf_format_root( $product->get_id() ) ),
	] );
}, 10, 2 );

/* ═══════════════════════════════════════════════════════════════
   Enregistrement
   ═══════════════════════════════════════════════════════════════ */

/** Le formulaire de cet écran a-t-il bien été posté ? */
function pf_fmt_form_posted(): bool {
	return isset( $_POST['pf_format_nonce'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pf_format_nonce'] ) ), 'pf_fmt_save' );
}

/** Slug de format posté, vide si absent ou inconnu. */
function pf_fmt_posted_format(): string {
	$slug = sanitize_title( wp_unslash( $_POST['pf_fmt_format'] ?? '' ) );
	return in_array( $slug, pf_format_order(), true ) ? $slug : '';
}

/**
 * Neutralise un suffixe de format déjà présent en fin de racine postée.
 *
 * Le champ « Nom de produit » repasse éditable via « Changer le nom du livre
 * et de tous ses formats » (rename button JS) et redevient alors un simple
 * texte libre : rien n'empêche d'y retaper le nom déjà suffixé (ex. copier le
 * texte de l'aperçu affiché juste en dessous). Sans ce filet, la racine
 * postée porterait déjà « (numérique) », et la composer une seconde fois
 * donnerait « … (numérique) (numérique) » — pire, la cascade de renommage
 * propagerait ce même doublon au NOM DU TERME de groupe et à toutes les
 * éditions sœurs.
 */
function pf_fmt_strip_own_suffix( string $root, string $slug ): string {
	$suffix = pf_format_suffix( $slug );
	if ( $suffix === '' ) return $root;

	$len = mb_strlen( $suffix );
	if ( mb_strtolower( mb_substr( $root, -$len ) ) !== mb_strtolower( $suffix ) ) {
		return $root;
	}
	return rtrim( mb_substr( $root, 0, mb_strlen( $root ) - $len ) );
}

/**
 * Compose le nom de produit = racine saisie + suffixe du format.
 *
 * Priorité 9 : post-slug-sync.php écoute le même filtre en 10 et dérivera donc
 * le permalien du titre COMPOSÉ.
 */
add_filter( 'wp_insert_post_data', function ( $data, $postarr ) {
	if ( pf_fmt_busy() ) return $data;
	if ( ( $data['post_type'] ?? '' ) !== 'product' ) return $data;
	if ( ! pf_fmt_form_posted() ) return $data;

	$root = trim( wp_unslash( (string) ( $data['post_title'] ?? '' ) ) );
	if ( $root === '' ) return $data;

	$slug = pf_fmt_posted_format();
	$root = pf_fmt_strip_own_suffix( $root, $slug );
	$data['post_title'] = wp_slash( pf_format_compose( $root, $slug ) );
	return $data;
}, 9, 2 );

/**
 * Écrit le groupe, le représentant, et propage un éventuel renommage aux
 * éditions sœurs. Le format (terme + attribut) est écrit séparément, plus
 * loin dans la requête — voir pf_fmt_apply_posted_format() ci-dessous.
 *
 * Priorité 25 : après les synchros auteurs / stock / index (@20) posées sur
 * ce même hook `save_post_product`.
 */
add_action( 'save_post_product', 'pf_fmt_save_product', 25, 2 );

function pf_fmt_save_product( $post_id, $post ) {
	if ( pf_fmt_busy() ) return;
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( wp_is_post_revision( $post_id ) ) return;
	if ( ! pf_fmt_form_posted() ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;

	$post_id = (int) $post_id;
	pf_fmt_busy( true );

	$old_group = pf_format_group_of( $post_id );
	$group     = pf_fmt_resolve_group(
		sanitize_text_field( wp_unslash( $_POST['pf_fmt_work'] ?? '' ) ),
		$post_id
	);

	// 1. Appartenance à l'œuvre (mode remplacement = un seul terme par produit).
	wp_set_object_terms( $post_id, $group ? [ $group ] : [], 'format_groupe', false );

	// 3. Représentant choisi à la main.
	if ( $group ) {
		$manual = (int) get_term_meta( $group, PF_GROUP_REP_META, true );
		if ( ! empty( $_POST['pf_fmt_rep'] ) ) {
			update_term_meta( $group, PF_GROUP_REP_META, $post_id );
		} elseif ( $manual === $post_id ) {
			delete_term_meta( $group, PF_GROUP_REP_META );
		}
	}

	// 4. Renommage propagé à toutes les éditions de l'œuvre.
	if ( $group && ! empty( $_POST['pf_fmt_rename'] ) ) {
		$root = trim( wp_unslash( (string) ( $_POST['post_title'] ?? '' ) ) );
		pf_fmt_rename_group( $group, pf_fmt_strip_own_suffix( $root, pf_fmt_posted_format() ), $post_id );
	}

	// 5. L'ancien groupe tombe-t-il sous le seuil de 2 membres ?
	if ( $old_group && $old_group !== $group ) {
		pf_fmt_prune_group( $old_group, $post_id );
	}

	pf_format_flush_works_cache();
	if ( function_exists( 'pf_bg_flush_books_cache' ) ) pf_bg_flush_books_cache();
	wc_delete_product_transients( $post_id );

	pf_fmt_busy( false );
}

/**
 * Écrit le format (terme + attribut) posté — séparément de pf_fmt_save_product().
 *
 * `WC_Meta_Box_Product_Data::save()` tourne sur le hook GÉNÉRIQUE `save_post`
 * (via `WC_Admin_Meta_Boxes::save_meta_boxes()`), qui se déclenche APRÈS
 * `save_post_product` — donc après pf_fmt_save_product(). Sans
 * `attribute_names` posté (onglet Attributs masqué), ce `$product->save()`
 * réinitialise les attributs du produit à VIDE, ce qui efface au passage la
 * relation de terme `pa_format_particulier` : l'écrire dans
 * pf_fmt_save_product() ne survivrait pas à la fin de la requête (le titre,
 * lui, survit : sa composition ne touche que $data['post_title'], pas les
 * attributs). On écrit donc le format ici, sur `woocommerce_process_product_
 * meta` — le hook que WC_Admin_Meta_Boxes déclenche pour le type « product »
 * une fois son propre `$product->save()` terminé — à une priorité postérieure
 * à WC_Meta_Box_Product_Data::save() (@10) et WC_Meta_Box_Product_Images::save
 * (@20), pour avoir le dernier mot.
 */
add_action( 'woocommerce_process_product_meta', 'pf_fmt_apply_posted_format', 30 );

function pf_fmt_apply_posted_format( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( wp_is_post_revision( $post_id ) ) return;
	if ( ! pf_fmt_form_posted() ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;

	$post_id = (int) $post_id;
	pf_fmt_apply_format( $post_id, pf_fmt_posted_format() );
	wc_delete_product_transients( $post_id );
}

/**
 * Pose (ou retire) le format : relation de terme ET `_product_attributes`.
 *
 * Les deux doivent bouger ensemble — le front lit tantôt la taxonomie (étagères,
 * catalogue, dédup) tantôt l'attribut via get_attributes() (tableau
 * « Caractéristiques » de la fiche livre). La forme écrite reproduit exactement
 * celle des produits existants ; on l'écrit en direct plutôt que via
 * WC_Product::save(), qui relancerait un save_post_product imbriqué.
 */
function pf_fmt_apply_format( int $post_id, string $slug ) {
	$term = $slug !== '' ? get_term_by( 'slug', $slug, 'pa_format_particulier' ) : null;

	if ( ! $term || is_wp_error( $term ) ) {
		wp_set_object_terms( $post_id, [], 'pa_format_particulier', false );
		delete_post_meta( $post_id, '_product_attributes' );
		return;
	}

	wp_set_object_terms( $post_id, [ (int) $term->term_id ], 'pa_format_particulier', false );
	update_post_meta( $post_id, '_product_attributes', [
		'pa_format_particulier' => [
			'name'         => 'pa_format_particulier',
			'value'        => '',
			'position'     => 0,
			'is_visible'   => 1,
			'is_variation' => 0,
			'is_taxonomy'  => 1,
		],
	] );
}

/**
 * Renomme l'œuvre : le terme de groupe et le titre de chaque édition, chacune
 * conservant le suffixe de son propre format. Les permaliens suivent via
 * post-slug-sync.php, et le cœur empile les `_wp_old_slug` (anciennes URL
 * toujours redirigées).
 *
 * Le slug du terme n'est volontairement pas retouché : il n'est pas public et
 * plusieurs slugs historiques divergent déjà de leur nom.
 */
function pf_fmt_rename_group( int $group, string $root, int $skip_id ) {
	if ( $root === '' ) return;

	wp_update_term( $group, 'format_groupe', [ 'name' => $root ] );

	foreach ( pf_group_members( $group, true ) as $sibling => $slug ) {
		if ( (int) $sibling === $skip_id ) continue;

		$new_title = pf_format_compose( $root, $slug );
		if ( $new_title === (string) get_post_field( 'post_title', $sibling, 'raw' ) ) continue;

		wp_update_post( [ 'ID' => (int) $sibling, 'post_title' => $new_title ] );
	}
}

/**
 * Un groupe n'a de sens qu'à partir de deux éditions : s'il n'en reste qu'une
 * après un détachement, le terme est supprimé et la dernière édition redevient
 * un livre autonome (invariant vérifié en base : aucun groupe < 2 membres).
 */
function pf_fmt_prune_group( int $group, int $left_id ) {
	if ( (int) get_term_meta( $group, PF_GROUP_REP_META, true ) === $left_id ) {
		delete_term_meta( $group, PF_GROUP_REP_META );
	}

	if ( count( pf_group_members( $group, true ) ) <= 1 ) {
		wp_delete_term( $group, 'format_groupe' );
	}
}

/* ═══════════════════════════════════════════════════════════════
   Validation (pipeline SCF, comme inc/book-field-validation.php)
   ═══════════════════════════════════════════════════════════════ */

add_action( 'acf/validate_save_post', function () {
	if ( ! pf_fmt_form_posted() ) return;

	$post_id = (int) ( $_POST['post_ID'] ?? 0 );
	if ( ! $post_id || get_post_type( $post_id ) !== 'product' ) return;

	$root = trim( wp_unslash( (string) ( $_POST['post_title'] ?? '' ) ) );
	if ( $root === '' ) {
		acf_add_validation_error( '', 'Le titre de l’œuvre est obligatoire.' );
		return;
	}

	$group = pf_fmt_resolve_group(
		sanitize_text_field( wp_unslash( $_POST['pf_fmt_work'] ?? '' ) ),
		$post_id,
		false // ne rien créer pendant une validation
	);
	if ( ! $group ) return;

	$slug    = pf_fmt_posted_format();
	$members = pf_group_members( $group );
	unset( $members[ $post_id ] );

	if ( in_array( $slug, $members, true ) ) {
		$label = $slug === '' ? 'classique' : mb_strtolower( (string) pf_format_suffix( $slug ) );
		$label = trim( $label, ' ()' );
		acf_add_validation_error(
			'',
			'Le format « ' . $label . ' » est déjà pris par une autre édition de cette œuvre.'
		);
	}
} );
