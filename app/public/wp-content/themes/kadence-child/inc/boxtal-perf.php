<?php
/**
 * Performance / robustesse Boxtal Connect.
 *
 * Le plugin boxtal-connect récupère un « token de carte » (widget point relais)
 * par un appel HTTP POST **bloquant et non caché** vers api.boxtal.com/token/maps,
 * embarqué dans les données front de son intégration WooCommerce Blocks. Rien ne
 * le restreint aux pages panier/commande → il se déclenche aussi sur des pages
 * classiques (accueil, catalogue…), et un aléa DNS/réseau côté hébergeur peut le
 * faire bloquer plusieurs secondes (constaté en prod : cURL 28 « Resolving timed
 * out », ~5 s ajoutées au rendu).
 *
 * Mitigation, sans toucher au plugin (qui serait écrasé aux mises à jour), via
 * deux filtres HTTP WordPress standard :
 *   1. Court-circuiter le token de carte hors contexte panier/commande.
 *   2. Plafonner le timeout de cet appel précis (filet anti-blocage prolongé).
 *
 * La vraie carte point relais reste intacte : elle se charge via l'AJAX
 * bw_get_map_url et le Store API — contextes explicitement laissés passer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sommes-nous dans un contexte où la carte point relais peut légitimement être
 * demandée ? Volontairement permissif : on ne bloque que si l'on est certain
 * d'être hors sujet (page front classique, qui n'affiche jamais de carte).
 */
function pf_boxtal_is_shipping_context() {
	if ( wp_doing_ajax() ) {
		return true; // ouverture réelle de la carte : admin-ajax bw_get_map_url
	}
	if ( wp_doing_cron() ) {
		return true;
	}
	if ( is_admin() ) {
		return true;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true; // Store API des blocs panier/checkout
	}
	if ( function_exists( 'is_cart' ) && is_cart() ) {
		return true;
	}
	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		return true;
	}
	return false;
}

/**
 * Court-circuite le seul appel « token de carte » Boxtal quand aucune carte
 * point relais ne peut s'afficher. Renvoie une réponse 200 au corps vide : le
 * plugin y lit « pas d'accessToken » (get_body → objet vide → get_map_token
 * renvoie null → mapUrl null), sans effet sur ces pages ni warning de log.
 *
 * @param false|array|WP_Error $pre  Court-circuit ('false' = laisser passer).
 * @param array                $args Arguments de la requête.
 * @param string               $url  URL cible.
 * @return false|array|WP_Error
 */
function pf_boxtal_pre_http_request( $pre, $args, $url ) {
	if ( ! is_string( $url ) || false === strpos( $url, 'api.boxtal.com' ) ) {
		return $pre;
	}
	if ( false !== strpos( $url, '/token/maps' ) && ! pf_boxtal_is_shipping_context() ) {
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}
	return $pre;
}
add_filter( 'pre_http_request', 'pf_boxtal_pre_http_request', 10, 3 );

/**
 * Plafonne le timeout du token de carte Boxtal : même en contexte légitime, un
 * DNS/réseau capricieux ne doit pas bloquer le rendu plusieurs secondes. Scopé
 * à ce seul endpoint (qui répond normalement en ~0,1 s) → les autres appels
 * Boxtal (tarifs, points relais, commandes) gardent leur timeout par défaut.
 *
 * @param array  $args Arguments de la requête.
 * @param string $url  URL cible.
 * @return array
 */
function pf_boxtal_http_request_args( $args, $url ) {
	if ( is_string( $url )
		&& false !== strpos( $url, 'api.boxtal.com' )
		&& false !== strpos( $url, '/token/maps' )
		&& isset( $args['timeout'] ) ) {
		$args['timeout'] = min( (float) $args['timeout'], 2.0 );
	}
	return $args;
}
add_filter( 'http_request_args', 'pf_boxtal_http_request_args', 10, 2 );
