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
} else {
	// Produit autonome (pas de format_groupe) mais portant un format
	// particulier : même traitement que dans un groupe, avec un seul membre
	// non cliquable (ex. livre qui n'existe qu'en numérique).
	$own_format = wp_get_object_terms( $id, 'pa_format_particulier', [ 'fields' => 'names' ] );
	if ( ! is_wp_error( $own_format ) && ! empty( $own_format ) ) {
		$format_variants[] = [
			'id'      => $id,
			'url'     => get_permalink( $id ),
			'label'   => $own_format[0],
			'current' => true,
		];
	}
}

$group_title = '';
if ( ! empty( $format_variants ) ) {
	if ( ! empty( $fg_terms ) && ! is_wp_error( $fg_terms ) ) {
		$fg_term = get_term( $fg_terms[0], 'format_groupe' );
		if ( $fg_term && ! is_wp_error( $fg_term ) ) {
			$group_title = $fg_term->name;
		}
	} else {
		$group_title = pf_format_root( $id );
	}
}

/* ─── Message épuisé (remplace bs-price-row) ───────────────────── */

$epuise_message = '';
if ( ! $product->is_in_stock() ) {
	$other_labels = [];
	if ( ! empty( $format_variants ) ) {
		$epuise_map = pf_format_epuise_map( wp_list_pluck( $format_variants, 'id' ) );
		foreach ( $format_variants as $v ) {
			if ( ! $v['current'] && ! isset( $epuise_map[ $v['id'] ] ) ) {
				$other_labels[] = mb_strtolower( $v['label'] );
			}
		}
	}
	if ( empty( $other_labels ) ) {
		$epuise_message = 'Ce livre est désormais épuisé.';
	} else {
		$plural = count( $other_labels ) > 1;
		$epuise_message = sprintf(
			'Ce format est désormais épuisé, mais %s %s %s %s toujours %s.',
			$plural ? 'les' : 'la',
			$plural ? 'versions' : 'version',
			passiflore_join_with_et( $other_labels ),
			$plural ? 'sont' : 'est',
			$plural ? 'disponibles' : 'disponible'
		);
	}
}

/* ─── Champs divers ────────────────────────────────────────────── */

$sous_titre      = get_field( 'sous-titre' );
$lien_libraires  = get_field( 'lien_place_des_libraires' );
$nouveaute       = get_field( 'nouveaute' );
$description     = $product->get_description(); // même source que la section « Résumé » plus bas (inc/book-single-tabs.php)

?>

<div id="product-<?php the_ID(); ?>" <?php wc_product_class( '', $product ); ?>>

	<div class="bs-hero">

		<!-- ═══ En-tête (auteurs + titre + sous-titre) ══════════════ -->
		<div class="bs-hero__heading">

			<div class="bs-hero__title-group">
				<h1 class="bs-hero__title pf-titre-1"><?= esc_html( $group_title ?: get_the_title() ) ?></h1>

				<?php if ( $sous_titre ) : ?>
				<p class="bs-hero__subtitle"><?= esc_html( $sous_titre ) ?></p>
				<?php endif; ?>
			</div>

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

		</div><!-- /.bs-hero__heading -->

		<!-- ═══ Colonne info (prix, actions, formats…) ═══════════════ -->
		<div class="bs-hero__info">

			<div class="bs-hero__actions">

				<!-- Bloc 1 : liste de lecture / feuilleter -->
				<div class="bs-hero__secondary-row">
					<?php if ( class_exists( 'Passiflore_Reading_List' ) ) echo Passiflore_Reading_List::render_button( $id ); ?>
					<?php if ( $pdf_id ) : ?>
					<button type="button" class="pf-info__flip pf-btn pf-btn--outline pf-btn--icon">Feuilleter<svg class="pf-info__flip-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="M458.5-181q-10.5-3-19.5-8-41-25-86-38t-93-13q-42 0-82.5 11T100-198q-21 11-40.5-1T40-234v-482q0-11 5.5-21T62-752q46-24 96-36t102-12q58 0 113.5 15T480-740v484q51-32 107-48t113-16q36 0 70.5 6t69.5 18v-480q15 5 29.5 10.5T898-752q11 5 16.5 15t5.5 21v482q0 23-19.5 35t-40.5 1q-37-20-77.5-31T700-240q-48 0-93 13t-86 38q-9 5-19.5 8t-21.5 3q-11 0-21.5-3ZM593-390q-10 9-21.5 3.5T560-405v-327q0-4 1.5-7.5t4.5-6.5l160-160q10-10 22-5t12 19v343q0 5-2 8.5t-5 6.5L593-390Zm-193 95v-396q-33-14-68.5-21.5T260-720q-37 0-72 7t-68 21v397q35-13 69.5-19t70.5-6q36 0 70.5 6t69.5 19Zm0 0v-396 396Z"/></svg></button>
					<?php endif; ?>
				</div>

				<!-- Bloc 2 : résumé -->
				<?php if ( $description ) : ?>
				<div class="bs-hero__middle">
					<div class="bs-hero__resume">
						<div class="bs-hero__resume-text entry-content"><?php echo apply_filters( 'the_content', $description ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
						<a href="#resume" class="bs-hero__resume-more" hidden>Lire la suite</a>
					</div>
				</div>
				<?php endif; ?>

				<!-- Bloc 3 : formats, prix et ajouter au panier -->
				<div class="bs-hero__purchase">
					<?php if ( ! empty( $format_variants ) ) : ?>
					<div class="bs-formats">
						<span class="bs-formats__label">Format<?= count( $format_variants ) > 1 ? 's' : '' ?> :</span>
						<?php foreach ( $format_variants as $v ) :
							$cls = 'bs-format-btn pf-btn pf-btn--xs ' . ( $v['current'] ? 'pf-btn--outline bs-format-btn--active' : 'pf-btn--neutral' );
							if ( $v['current'] ) : ?>
								<span class="<?= esc_attr( $cls ) ?>" aria-current="true"><?= esc_html( $v['label'] ) ?></span>
							<?php else : ?>
								<a href="<?= esc_url( $v['url'] ) ?>" class="<?= esc_attr( $cls ) ?>"><?= esc_html( $v['label'] ) ?></a>
							<?php endif;
						endforeach; ?>
					</div>
					<?php endif; ?>
					<?php if ( $product->is_in_stock() ) : ?>
					<div class="bs-price-row">
						<?php woocommerce_template_single_price(); ?>
						<?php
						if ( function_exists( 'pf_numerique_render_offer_checkbox' ) ) {
							echo pf_numerique_render_offer_checkbox( $id ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						if ( function_exists( 'pf_numerique_render_ebook_tip' ) ) {
							echo pf_numerique_render_ebook_tip( $id ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						?>
					</div>
					<?php else : ?>
					<p class="bs-epuise-msg"><?= esc_html( $epuise_message ) ?></p>
					<?php endif; ?>
					<?php if ( $product->is_purchasable() && $product->is_in_stock() ) : ?>
					<a href="<?= esc_url( $product->add_to_cart_url() ) ?>"
					   class="button bs-hero__cart add_to_cart_button ajax_add_to_cart"
					   data-product_id="<?= esc_attr( $product->get_id() ) ?>"
					   data-quantity="1"
					   <?= $product->is_sold_individually() ? 'data-pf-sold-individually="1"' : '' ?>
					   rel="nofollow">
						<?= esc_html( $product->single_add_to_cart_text() ) ?>
						<svg class="bs-hero__cart-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="M223.5-103.5Q200-127 200-160t23.5-56.5Q247-240 280-240t56.5 23.5Q360-193 360-160t-23.5 56.5Q313-80 280-80t-56.5-23.5Zm400 0Q600-127 600-160t23.5-56.5Q647-240 680-240t56.5 23.5Q760-193 760-160t-23.5 56.5Q713-80 680-80t-56.5-23.5ZM120-800H80q-17 0-28.5-11.5T40-840q0-17 11.5-28.5T80-880h66q11 0 21 6t15 17l159 337h280l145-260q5-10 14-15t20-5q23 0 34.5 19.5t.5 39.5L692-482q-11 20-29.5 31T622-440H324l-44 80h440q17 0 28.5 11.5T760-320q0 17-11.5 28.5T720-280H280q-45 0-68.5-39t-1.5-79l54-98-144-304Zm367 120H360q-17 0-28.5-11.5T320-720q0-17 11.5-28.5T360-760h127l-36-36q-12-12-11.5-28t12.5-28q12-11 28-11.5t28 11.5l104 104q12 12 12 28t-12 28L508-588q-11 11-27.5 11.5T452-588q-11-11-11-28t11-28l35-36Z"/></svg>
					</a>
					<?php endif; ?>
				</div>
			</div>

		</div><!-- /.bs-hero__info -->

		<!-- ═══ Colonne visuel (droite) ══════════════════════════════ -->
		<div class="bs-hero__visual">
			<?php
			// libraires_url : rendu par Passiflore_Bookshelf dans .pf-shelf, sous la
			// planche (mode hero uniquement) — rawurlencode() pour traverser le
			// parseur de shortcode sans risque (guillemets/esperluettes de l'URL).
			$etagere_shortcode = '[passiflore_etagere ids="' . $id . '" hero="true" nb_books_first_displayed="1" display-aparaitre="false"';
			if ( $lien_libraires ) {
				$etagere_shortcode .= ' libraires_url="' . rawurlencode( $lien_libraires ) . '"';
			}
			$etagere_shortcode .= ']';
			echo do_shortcode( $etagere_shortcode );
			?>
			<noscript><style>.pf-bookshelf--hero .pf-book { visibility: visible; }</style></noscript>
			<?php
			// Priorité : À paraître > Nouveauté (même règle que l'étiquette de
			// planche de l'étagère, class-bookshelf.php) — un livre à paraître
			// n'affiche pas aussi « Nouveauté ».
			$show_nouveaute = $nouveaute && $dispo_val !== 'a-paraitre';
			?>
			<?php if ( $show_nouveaute || $dispo ) : ?>
			<div class="bs-dispo-line">
				<?php if ( $show_nouveaute ) : ?>
				<span class="pf-badge pf-badge--accent">Nouveauté</span>
				<?php endif; ?>
				<?php if ( $dispo ) : ?>
				<span class="pf-badge <?= esc_attr( $dispo['class'] ) ?>"><?= esc_html( $dispo['label'] ) ?></span>
				<?php endif; ?>
			</div>
			<?php endif; ?>
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
