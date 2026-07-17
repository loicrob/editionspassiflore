<?php
/**
 * DIAGNOSTIC TEMPORAIRE — détecteur de « header non-sticky ».
 *
 * Charge assets/js/sticky-debug.js pour les administrateurs uniquement. Le script
 * est totalement inerte tant que le drapeau localStorage `pf_sticky_debug` n'est
 * pas posé (via `pfStickyDebug(true)` en console). Chargé dans le <head> pour
 * commencer la surveillance au plus tôt.
 *
 * À SUPPRIMER une fois la cause du décrochage identifiée :
 *   - ce fichier (inc/sticky-debug.php)
 *   - assets/js/sticky-debug.js
 *   - la ligne require_once correspondante dans functions.php
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_enqueue_scripts', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	wp_enqueue_script(
		'pf-sticky-debug',
		get_stylesheet_directory_uri() . '/assets/js/sticky-debug.js',
		array(),
		filemtime( get_stylesheet_directory() . '/assets/js/sticky-debug.js' ),
		false // dans le <head>, pas le footer
	);
}, 5 );
