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
const PF_QR_VIDEO_PAGE_SLUG = 'qr-video-enquete-ruedo';

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

	header( 'X-Robots-Tag: noindex' );
	?>
	<!doctype html>
	<html lang="fr">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>Vidéo — Éditions Passiflore</title>
	</head>
	<body style="font-family: sans-serif; max-width: 640px; margin: 0 auto; padding: 4rem 1.5rem;">
		<?php echo $page ? apply_filters( 'the_content', $page->post_content ) : '<p>La vidéo sera bientôt disponible.</p>'; ?>
	</body>
	</html>
	<?php
	exit;
} );
