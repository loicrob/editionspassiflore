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

// Contenu géré depuis l'admin (Pages), pas en dur ici — voir la page
// "Vidéo QR — Enquête autour du ruedo" (brouillon, jamais publiée ni liée :
// wp_insert_post() la récupère directement par ID, sans passer par une
// requête publique, donc son statut brouillon ne bloque pas son affichage).
const PF_QR_VIDEO_PAGE_ID = 8152;

add_action( 'template_redirect', function () {
	if ( ! get_query_var( 'pf_qr_video' ) ) {
		return;
	}

	$page = get_post( PF_QR_VIDEO_PAGE_ID );

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
