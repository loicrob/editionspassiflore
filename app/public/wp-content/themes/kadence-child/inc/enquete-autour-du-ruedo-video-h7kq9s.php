<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// URL exclusive au QR code imprimé dans "Enquête autour du ruedo" — segment
// non devinable sous la fiche du livre, jamais lié nulle part sur le site.
add_action( 'init', function () {
	add_rewrite_rule(
		'^livre/enquete-autour-du-ruedo/h7kq9s/?$',
		'index.php?pf_qr_video=1',
		'top'
	);
}, 20 );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'pf_qr_video';
	return $vars;
} );

add_action( 'admin_init', function () {
	if ( get_option( 'pf_qr_video_rewrite_v' ) !== '1' ) {
		flush_rewrite_rules( false );
		update_option( 'pf_qr_video_rewrite_v', '1', false );
	}
} );

// Contenu géré depuis l'admin (Pages), pas en dur ici — voir la page de slug
// PF_QR_VIDEO_PAGE_SLUG (brouillon, jamais publiée ni liée : récupérée
// directement par slug, sans passer par une requête publique, donc son
// statut brouillon ne bloque pas son affichage). Slug plutôt qu'ID : l'ID
// numérique d'un post n'a aucune raison de coïncider entre le local et la
// production, le slug si (à créer identique dans les deux environnements).
const PF_QR_VIDEO_PAGE_SLUG = 'enquete-autour-du-ruedo-video-h7kq9s';

// Un brouillon reste visible à son permalien natif pour qui a le droit de
// l'éditer (le public, lui, tombe en 404 avant d'arriver ici) — rendu par
// le template normal du thème (header/footer), différent du rendu nu de la
// route QR ci-dessous. On renvoie vers l'URL canonique pour éviter la
// confusion, et par sécurité si la page était un jour publiée par erreur
// (son slug lisible est plus devinable que le hash de la route QR).
// is_preview() exclu : l'Aperçu de l'éditeur doit continuer de fonctionner.
add_action( 'template_redirect', function () {
	if ( is_preview() || ! is_page( PF_QR_VIDEO_PAGE_SLUG ) ) {
		return;
	}
	wp_safe_redirect( home_url( '/livre/enquete-autour-du-ruedo/h7kq9s' ), 302 );
	exit;
}, 5 );

// Rank Math calcule son propre meta robots au vol, indépendamment de l'en-tête
// X-Robots-Tag envoyé plus bas — sans ce filtre il affiche "index, follow" par
// défaut (aucun objet interrogé sur cette route custom), en contradiction avec
// le noindex HTTP. Même mécanisme que seo-events.php pour les événements.
add_filter( 'rank_math/frontend/robots', function ( $robots ) {
	if ( get_query_var( 'pf_qr_video' ) ) {
		$robots['index'] = 'noindex';
	}
	return $robots;
} );

// Marque « Catalogue » et les catégories du livre (Culture Sud-Ouest >
// Tauromachie) comme actifs dans le header, comme sur la fiche du livre.
// Priorité 20, après passiflore_inject_product_cat_children() (header-hooks.php,
// priorité par défaut 10) : "Tauromachie" est un item virtuel injecté par
// cette fonction (get_terms() à l'affichage, jamais stocké dans le menu) —
// il n'existe pas encore dans $items tant qu'elle n'a pas tourné.
add_filter( 'wp_nav_menu_objects', function ( $items ) {
	if ( ! get_query_var( 'pf_qr_video' ) ) {
		return $items;
	}
	$shop_page_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;
	$target_slugs = [ 'culture-sud-ouest', 'tauromachie' ];
	foreach ( $items as $item ) {
		if ( 'page' === $item->object && (int) $item->object_id === (int) $shop_page_id ) {
			$item->classes[] = 'current-menu-item';
			continue;
		}
		if ( 'product_cat' !== $item->object ) {
			continue;
		}
		$term = get_term( $item->object_id, 'product_cat' );
		if ( $term && ! is_wp_error( $term ) && in_array( $term->slug, $target_slugs, true ) ) {
			$item->classes[] = 'current-menu-item';
		}
	}
	return $items;
}, 20 );

add_action( 'template_redirect', function () {
	if ( ! get_query_var( 'pf_qr_video' ) ) {
		return;
	}

	// 'any' n'inclut PAS les brouillons (WP_Query le résout comme "tout statut
	// visible en recherche", ce qui exclut draft/pending/private) — lister les
	// statuts explicitement pour retrouver la page quel que soit son état.
	$pages = get_posts( [
		'name'        => PF_QR_VIDEO_PAGE_SLUG,
		'post_type'   => 'page',
		'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
		'numberposts' => 1,
	] );
	$page = $pages ? $pages[0] : null;

	// get_header()/get_footer() plutôt qu'un document HTML autonome : la page
	// doit porter le chrome habituel du site (demande explicite), pas juste
	// afficher la vidéo nue. On n'a pas de requête principale résolue sur un
	// vrai post (query var custom, pas un WP_Query singulier) — les blocs du
	// thème qui dépendent du post courant (fil d'Ariane, partage…) n'en ont
	// simplement pas ici, comme sur les autres pages hors contexte singulier
	// du site (archives, etc.).
	header( 'X-Robots-Tag: noindex' );
	get_header();
	?>
	<main style="max-width: 640px; margin: 0 auto; padding: 4rem 1.5rem;">
		<?php echo $page ? apply_filters( 'the_content', $page->post_content ) : '<p>La vidéo sera bientôt disponible.</p>'; ?>
	</main>
	<?php
	get_footer();
	exit;
} );
