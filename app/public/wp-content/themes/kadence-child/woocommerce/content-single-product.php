<?php
/**
 * Single product template — Éditions Passiflore
 * Remplace le template WooCommerce standard.
 */

defined( 'ABSPATH' ) || exit;

global $product;

do_action( 'woocommerce_before_single_product' );

if ( post_password_required() ) {
	echo get_the_password_form();
	return;
}

$id       = get_the_ID();
$pdf_id   = get_field( 'extrait' );
$thumb_id = get_post_thumbnail_id( $id );

/* ─── Dimensions flipbook ──────────────────────────────────────── */

$page_h = 570;
$page_w = 400;
if ( $thumb_id ) {
	$img = wp_get_attachment_image_src( $thumb_id, 'full' );
	if ( $img && $img[1] && $img[2] ) {
		$page_w = (int) round( $page_h * ( $img[1] / $img[2] ) );
	}
}
if ( $page_w === 400 ) {
	$l = (float) $product->get_length();
	$h = (float) $product->get_height();
	if ( $l > 0 && $h > 0 ) {
		$page_w = (int) round( $page_h * ( $l / $h ) );
	}
}

/* ─── Auteurs ──────────────────────────────────────────────────── */

// $authors_by_type : tableau ordonné [ type => [ [name, url], … ] ]
$authors_by_type = [];
if ( have_rows( 'contributions' ) ) {
	while ( have_rows( 'contributions' ) ) {
		the_row();
		$type = get_sub_field( 'type' ) ?: 'auteur';
		if ( get_sub_field( 'assignation' ) === 'fiche-auteur' ) {
			$raw      = get_sub_field( 'fiche-auteur' );
			$term_ids = [];
			if ( is_array( $raw ) ) {
				foreach ( $raw as $item ) {
					$term_ids[] = is_object( $item ) ? (int) $item->term_id : absint( $item );
				}
			} elseif ( is_object( $raw ) ) {
				$term_ids[] = (int) $raw->term_id;
			} elseif ( $raw ) {
				$term_ids[] = absint( $raw );
			}
			foreach ( $term_ids as $tid ) {
				if ( ! $tid ) continue;
				$term = get_term( $tid, 'auteur' );
				if ( ! $term || is_wp_error( $term ) ) continue;
				$prenom = get_field( 'prenom', 'auteur_' . $tid );
				$nom    = get_field( 'nom',    'auteur_' . $tid );
				$name   = trim( $prenom . ' ' . $nom ) ?: $term->name;
				$authors_by_type[ $type ][] = [ 'name' => $name, 'url' => get_term_link( $term ) ];
			}
		} else {
			$n = get_sub_field( 'field_69cd3251156af' ); // nom_de_l'auteur (curly apostrophe in DB)
			if ( $n ) $authors_by_type[ $type ][] = [ 'name' => $n, 'url' => '' ];
		}
	}
}

/* ─── Disponibilité ────────────────────────────────────────────── */

$dispo_val = get_field( 'disponibilite' );
$dispo_map = [
	'disponible'         => [ 'label' => 'Disponible',         'class' => 'pf-badge--success' ],
	'a-paraitre'         => [ 'label' => 'À paraître',         'class' => 'pf-badge--accent' ],
	'bientot-disponible' => [ 'label' => 'Bientôt disponible', 'class' => 'pf-badge--info' ],
	'bientot-epuise'     => [ 'label' => 'Bientôt épuisé',     'class' => 'pf-badge--warning' ],
	'epuise'             => [ 'label' => 'Épuisé',             'class' => 'pf-badge--danger' ],
];
$dispo = ( $dispo_val && $dispo_val !== 'disponible' ) ? ( $dispo_map[ $dispo_val ] ?? [ 'label' => ucfirst( $dispo_val ), 'class' => '' ] ) : null;

/* ─── Variantes de format (format_groupe siblings) ─────────────── */

$format_variants = [];
$fg_terms = wp_get_object_terms( $id, 'format_groupe', [ 'fields' => 'ids' ] );
if ( ! is_wp_error( $fg_terms ) && ! empty( $fg_terms ) ) {
	$all_in_group = get_posts( [
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'tax_query'      => [ [ 'taxonomy' => 'format_groupe', 'terms' => $fg_terms ] ],
		'orderby'        => 'title',
		'order'          => 'ASC',
	] );
	foreach ( $all_in_group as $vid ) {
		$fmt   = wp_get_object_terms( $vid, 'pa_format_particulier', [ 'fields' => 'names' ] );
		$label = ( ! is_wp_error( $fmt ) && ! empty( $fmt ) ) ? $fmt[0] : 'Classique';
		$format_variants[] = [
			'id'      => $vid,
			'url'     => get_permalink( $vid ),
			'label'   => $label,
			'current' => ( $vid === $id ),
		];
	}
	if ( count( $format_variants ) === 0 ) $format_variants = [];
}

$group_title = '';
if ( ! empty( $format_variants ) ) {
	$fg_term = get_term( $fg_terms[0], 'format_groupe' );
	if ( $fg_term && ! is_wp_error( $fg_term ) ) {
		$group_title = $fg_term->name;
	}
}

/* ─── Champs divers ────────────────────────────────────────────── */

$sous_titre      = get_field( 'sous-titre' );
$lien_libraires  = get_field( 'lien_place_des_libraires' );
$nouveaute       = get_field( 'nouveaute' );

?>

<div id="product-<?php the_ID(); ?>" <?php wc_product_class( '', $product ); ?>>

	<div class="bs-hero">

		<!-- ═══ En-tête (auteurs + titre + sous-titre) ══════════════ -->
		<div class="bs-hero__heading">

			<?php if ( $nouveaute || $dispo ) : ?>
			<div class="bs-dispo-line">
				<?php if ( $nouveaute ) : ?>
				<span class="pf-badge pf-badge--accent">Nouveauté</span>
				<?php endif; ?>
				<?php if ( $dispo ) : ?>
				<span class="pf-badge <?= esc_attr( $dispo['class'] ) ?>"><?= esc_html( $dispo['label'] ) ?></span>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php if ( $authors_by_type ) : ?>
			<div class="bs-hero__authors">
				<?php foreach ( $authors_by_type as $type => $people ) :
					$name_parts = [];
					foreach ( $people as $a ) {
						if ( $a['url'] ) {
							$name_parts[] = '<a class="pf-value" href="' . esc_url( $a['url'] ) . '">' . esc_html( $a['name'] ) . '</a>';
						} else {
							$name_parts[] = '<span class="pf-value">' . esc_html( $a['name'] ) . '</span>';
						}
					}
					$names_html = implode( ', ', $name_parts );
				?>
				<p class="bs-hero__authors-line">
					<?php if ( $type !== 'auteur' ) : ?>
					<span class="pf-label"><?= esc_html( ucfirst( $type ) ) ?> : </span>
					<?php endif; ?>
					<?= $names_html ?>
				</p>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<h1 class="bs-hero__title pf-titre-1"><?= esc_html( $group_title ?: get_the_title() ) ?></h1>

			<?php if ( $sous_titre ) : ?>
			<p class="bs-hero__subtitle"><?= esc_html( $sous_titre ) ?></p>
			<?php endif; ?>

		</div><!-- /.bs-hero__heading -->

		<!-- ═══ Colonne info (prix, actions, formats…) ═══════════════ -->
		<div class="bs-hero__info">

			<?php woocommerce_template_single_price(); ?>

			<div class="bs-hero__actions">
				<div class="bs-hero__secondary-row">
					<?php if ( $pdf_id ) : ?>
					<button type="button" class="pf-info__flip pf-btn pf-btn--outline pf-btn--block">Feuilleter l’extrait ➜</button>
					<?php endif; ?>
					<?php if ( class_exists( 'Passiflore_Reading_List' ) ) echo Passiflore_Reading_List::render_button( $id ); ?>
				</div>
				<?php if ( $product->is_purchasable() && $product->is_in_stock() ) : ?>
				<a href="<?= esc_url( $product->add_to_cart_url() ) ?>"
				   class="button bs-hero__cart add_to_cart_button ajax_add_to_cart"
				   data-product_id="<?= esc_attr( $product->get_id() ) ?>"
				   data-quantity="1"
				   rel="nofollow">
					<?= esc_html( $product->single_add_to_cart_text() ) ?>
				</a>
				<?php endif; ?>
			</div>

			<?php if ( $lien_libraires ) : ?>
			<a class="bs-hero__libraires" href="<?= esc_url( $lien_libraires ) ?>" target="_blank" rel="noopener noreferrer">
				Voir sur Place des libraires ➜
			</a>
			<?php endif; ?>

			<?php if ( ! empty( $format_variants ) ) : ?>
			<div class="bs-formats">
				<span class="bs-formats__label">Formats :</span>
				<?php foreach ( $format_variants as $v ) :
					$cls = 'bs-format-btn pf-btn pf-btn--sm ' . ( $v['current'] ? 'pf-btn--primary bs-format-btn--active' : 'pf-btn--neutral' );
					if ( $v['current'] ) : ?>
						<span class="<?= esc_attr( $cls ) ?>" aria-current="true"><?= esc_html( $v['label'] ) ?></span>
					<?php else : ?>
						<a href="<?= esc_url( $v['url'] ) ?>" class="<?= esc_attr( $cls ) ?>"><?= esc_html( $v['label'] ) ?></a>
					<?php endif;
				endforeach; ?>
			</div>
			<?php endif; ?>

		</div><!-- /.bs-hero__info -->

		<!-- ═══ Colonne visuel (droite) ══════════════════════════════ -->
		<div class="bs-hero__visual">
			<?php echo do_shortcode( '[passiflore_etagere ids="' . $id . '" hero="true" nb_books_first_displayed="1" display-aparaitre="false"]' ); ?>
		</div><!-- /.bs-hero__visual -->

	</div><!-- /.bs-hero -->

	<!-- ═══ Overlay fullscreen — flipbook ════════════════════════ -->
	<?php if ( $pdf_id || $thumb_id ) : ?>
	<div class="bs-flipbook-overlay" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Extrait feuilletable"
		data-extrait-url="<?= esc_attr( trailingslashit( get_the_permalink() ) . 'extrait' ) ?>">
		<button type="button" class="bs-flipbook-close" aria-label="Fermer (Échap)">✕</button>
		<div class="bs-flipbook-viewport">
			<div class="bs-flipbook-scale-wrapper">
				<div class="bs-flipbook-inner">
				<div id="passiflore-flipbook"
					<?php if ( $pdf_id ) : ?>data-pdf="<?= esc_attr( wp_get_attachment_url( $pdf_id ) ) ?>"<?php endif; ?>
					data-width="<?= esc_attr( $page_w ) ?>"
					data-height="<?= esc_attr( $page_h ) ?>">

					<?php if ( $thumb_id ) : ?>
					<div class="pf-page pf-page--cover">
						<?= wp_get_attachment_image( $thumb_id, 'large', false, [
							'class'         => 'pf-cover-img',
							'alt'           => 'Couverture de « ' . esc_attr( get_the_title() ) . ' »',
							'fetchpriority' => 'high',
							'loading'       => 'eager',
							'decoding'      => 'sync',
						] ) ?>
					</div>
					<?php endif; ?>

				</div><!-- /#passiflore-flipbook -->
				</div><!-- /.bs-flipbook-inner -->
			</div><!-- /.bs-flipbook-scale-wrapper -->
		</div><!-- /.bs-flipbook-viewport -->

		<div class="bs-flipbook-toolbar" role="toolbar" aria-label="Contrôles du feuilleteur">

			<button type="button" class="bs-tb-btn bs-tb-btn--mode" data-action="single-page" aria-pressed="false" aria-label="Mode page simple">
				<!-- Affiché en mode double → icône simple page (état cible) -->
				<span class="bs-tb-icon-double" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="1.5" width="10" height="13" rx="0.75"/><line x1="5.5" y1="5.5" x2="10.5" y2="5.5"/><line x1="5.5" y1="8" x2="10.5" y2="8"/><line x1="5.5" y1="10.5" x2="9" y2="10.5"/></svg></span>
				<!-- Affiché en mode simple → icône double page (état cible) -->
				<span class="bs-tb-icon-single" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="1.5" width="6" height="13" rx="0.75"/><line x1="2.5" y1="5.5" x2="5.75" y2="5.5"/><line x1="2.5" y1="8" x2="5.75" y2="8"/><line x1="2.5" y1="10.5" x2="5" y2="10.5"/><rect x="9" y="1.5" width="6" height="13" rx="0.75"/><line x1="10" y1="5.5" x2="13.5" y2="5.5"/><line x1="10" y1="8" x2="13.5" y2="8"/><line x1="10" y1="10.5" x2="12.75" y2="10.5"/></svg></span>
			</button>

			<div class="bs-tb-sep" aria-hidden="true"></div>

			<button type="button" class="bs-tb-btn" data-action="first-page" aria-label="Première page">
				<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><rect x="2" y="2.5" width="2" height="11" rx="0.75"/><path d="M13 3.5 5.5 8 13 12.5z"/></svg>
			</button>

			<button type="button" class="bs-tb-btn" data-action="prev-page" aria-label="Page précédente">
				<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M11 3 4 8 11 13z"/></svg>
			</button>

			<div class="bs-tb-sep" aria-hidden="true"></div>

			<button type="button" class="bs-tb-btn" data-action="zoom-out" aria-label="Dézoomer">
				<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><circle cx="7" cy="7" r="4.5"/><line x1="10.5" y1="10.5" x2="13.5" y2="13.5"/><line x1="4.75" y1="7" x2="9.25" y2="7"/></svg>
			</button>

			<button type="button" class="bs-tb-btn" data-action="zoom-in" aria-label="Zoomer">
				<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><circle cx="7" cy="7" r="4.5"/><line x1="10.5" y1="10.5" x2="13.5" y2="13.5"/><line x1="7" y1="4.75" x2="7" y2="9.25"/><line x1="4.75" y1="7" x2="9.25" y2="7"/></svg>
			</button>

			<div class="bs-tb-sep" aria-hidden="true"></div>

			<button type="button" class="bs-tb-btn bs-tb-btn--next" data-action="next-page" aria-label="Page suivante">
				<svg class="bs-tb-chevron" width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M5 3 12 8 5 13z"/></svg>
				<svg class="bs-tb-spinner" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M8 2a6 6 0 1 0 6 6"/></svg>
			</button>

			<button type="button" class="bs-tb-btn" data-action="last-page" aria-label="Dernière page">
				<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><rect x="12" y="2.5" width="2" height="11" rx="0.75"/><path d="M3 3.5 10.5 8 3 12.5z"/></svg>
			</button>

			<?php if ( $pdf_id ) : ?>
			<div class="bs-tb-sep" aria-hidden="true"></div>

			<button type="button" class="bs-tb-btn" data-action="download" aria-label="Ouvrir dans un nouvel onglet">
				<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 11 11 5"/><path d="M7.5 5H11v3.5"/></svg>
			</button>
			<?php endif; ?>

		</div><!-- /.bs-flipbook-toolbar -->

	</div><!-- /.bs-flipbook-overlay -->
	<?php endif; ?>

	<?php
	do_action( 'woocommerce_after_single_product_summary' );
	?>

</div><!-- #product-{id} -->

<?php do_action( 'woocommerce_after_single_product' ); ?>
