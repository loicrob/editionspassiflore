<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Connexion / création de compte — URL dédiées + JS de bascule.
 *
 * Le rendu vit dans woocommerce/myaccount/form-login.php (panneau unique,
 * connexion par défaut). Ici : les deux URL publiques et l'enqueue du script.
 *
 * WooCommerce n'a pas de page de connexion distincte — « Mon compte » est une
 * page unique dont le contenu dépend de la session. Plutôt que de *simuler* une
 * page compte ailleurs (ce qui casserait is_account_page() et, dans son sillage,
 * le noindex, les notices et les gestionnaires de formulaire), on fait résoudre
 * /connexion et /creer-un-compte SUR la vraie page compte, via une règle de
 * réécriture vers son page_id. La requête est authentiquement la page compte :
 * seule l'adresse affichée change.
 *
 * Conséquence : ce fichier ne dépend d'aucun interne de WooCommerce — que des
 * API du cœur WordPress (add_rewrite_rule, page_id, redirect_canonical) et un
 * seul appel public, wc_get_page_id( 'myaccount' ).
 *
 * Pas de contenu dupliqué à craindre : la page compte est en noindex (module
 * WooCommerce de Rank Math), et Rank Math n'émet aucun canonical sur une page
 * noindex — les deux URL sont simplement invisibles pour les moteurs.
 */

/**
 * Slug public => état du panneau (valeur de la query var `pf_auth`).
 *
 * @return array<string,string>
 */
function pf_auth_aliases() {
	return [
		'connexion'       => 'login',
		'creer-un-compte' => 'register',
	];
}

/**
 * URL publique d'un état.
 *
 * @param string $state 'register' ou '' / 'login'.
 * @return string
 */
function pf_auth_url( $state = 'login' ) {
	$slug = array_search( 'register' === $state ? 'register' : 'login', pf_auth_aliases(), true );
	return $slug ? home_url( user_trailingslashit( $slug ) ) : wc_get_page_permalink( 'myaccount' );
}

/**
 * État demandé pour le rendu : query var (URL dédiée) ou ?action= (historique).
 *
 * `?action=register` reste supporté : c'était l'ancienne forme, et un navigateur
 * ayant mis en cache la redirection 301 qui la produisait continuera d'y aboutir.
 *
 * @return string 'login' | 'register'
 */
function pf_auth_current_state() {
	$state = (string) get_query_var( 'pf_auth' );
	if ( '' === $state && isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- état d'affichage.
		$state = sanitize_key( wp_unslash( $_GET['action'] ) );
	}
	return 'register' === $state ? 'register' : 'login';
}

/** La query var doit être déclarée pour être lisible depuis une règle de réécriture. */
add_filter( 'query_vars', 'pf_auth_query_vars' );
function pf_auth_query_vars( $vars ) {
	$vars[] = 'pf_auth';
	return $vars;
}

/**
 * Règles de réécriture : /connexion et /creer-un-compte → page « Mon compte ».
 *
 * Flush unique auto-cicatrisant, gardé par une option dont la signature inclut
 * l'ID de la page compte : si cette page change, les règles se régénèrent seules.
 * Même motif que `pf_carte_rw_version` (vue Carte des événements) — pas de
 * WP-CLI ni de visite des réglages de permaliens requise au déploiement.
 */
add_action( 'init', 'pf_auth_register_rewrites' );
function pf_auth_register_rewrites() {
	if ( ! function_exists( 'wc_get_page_id' ) ) {
		return;
	}
	$page_id = (int) wc_get_page_id( 'myaccount' );
	if ( $page_id <= 0 ) {
		return;
	}

	$aliases = pf_auth_aliases();
	foreach ( $aliases as $slug => $state ) {
		// Slugs littéraux définis juste au-dessus (aucune entrée utilisateur) :
		// pas de preg_quote, qui échapperait le tiret depuis PHP 7.3 et rendrait
		// la table de réécriture pénible à relire (`^creer\-un\-compte/?$`).
		add_rewrite_rule(
			'^' . $slug . '/?$',
			'index.php?page_id=' . $page_id . '&pf_auth=' . $state,
			'top'
		);
	}

	$signature = 'v2:' . $page_id . ':' . implode( ',', array_keys( $aliases ) );
	if ( get_option( 'pf_auth_rw_version' ) !== $signature ) {
		flush_rewrite_rules( false ); // false = règles seules, pas de réécriture du .htaccess (serveur nginx).
		update_option( 'pf_auth_rw_version', $signature, false );
	}
}

/**
 * Empêche WordPress de « corriger » l'URL vers le permalien de la page compte.
 *
 * Sans ça, redirect_canonical() voit que l'URL demandée diffère du permalien de
 * l'objet interrogé (page 11) et renvoie un 301 vers /mon-compte — l'URL dédiée
 * disparaîtrait aussitôt de la barre d'adresse.
 */
add_filter( 'redirect_canonical', 'pf_auth_keep_url' );
function pf_auth_keep_url( $redirect_url ) {
	return get_query_var( 'pf_auth' ) ? false : $redirect_url;
}

/**
 * Deux garde-fous, au plus tôt (avant redirect_canonical, priorité 10).
 *
 * 1. Visiteur déjà connecté sur une URL d'authentification → page compte. Sans
 *    ça, il verrait son tableau de bord sous l'adresse « /connexion ».
 * 2. Repli si les règles de réécriture manquent en base (migration, option
 *    `rewrite_rules` purgée…) : l'URL tombe en 404 et on redirige vers la page
 *    compte dans le bon état. Le cœur de WordPress ne relit jamais en direct les
 *    règles ajoutées par add_rewrite_rule() — il se contente du cache en base —,
 *    d'où ce filet, déjà éprouvé sur les catalogues PDF.
 */
add_action( 'template_redirect', 'pf_auth_guards', 1 );
function pf_auth_guards() {
	if ( is_admin() || ! function_exists( 'wc_get_page_permalink' ) ) {
		return;
	}

	$account_url = wc_get_page_permalink( 'myaccount' );
	if ( ! $account_url ) {
		return;
	}

	if ( get_query_var( 'pf_auth' ) ) {
		if ( is_user_logged_in() ) {
			wp_safe_redirect( $account_url );
			exit;
		}
		return;
	}

	if ( ! is_404() ) {
		return;
	}

	$request = isset( $GLOBALS['wp']->request ) ? trim( (string) $GLOBALS['wp']->request, '/' ) : '';
	$aliases = pf_auth_aliases();
	if ( '' === $request || ! isset( $aliases[ $request ] ) ) {
		return;
	}

	$url = 'register' === $aliases[ $request ] ? add_query_arg( 'action', 'register', $account_url ) : $account_url;
	wp_safe_redirect( $url );
	exit;
}

/**
 * Script de bascule connexion ↔ création de compte.
 *
 * Chargé uniquement là où les deux panneaux existent : page compte, visiteur
 * déconnecté. Pure amélioration progressive — sans lui, les bascules restent
 * deux liens qui chargent la page sur la bonne URL.
 */
add_action( 'wp_enqueue_scripts', 'pf_auth_enqueue_assets', 20 );
function pf_auth_enqueue_assets() {
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || is_user_logged_in() ) {
		return;
	}

	$rel = '/assets/js/account-auth.js';
	wp_enqueue_script(
		'passiflore-account-auth',
		get_stylesheet_directory_uri() . $rel,
		[],
		filemtime( get_stylesheet_directory() . $rel ),
		true
	);
}
