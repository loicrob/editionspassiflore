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
 * Après connexion / inscription depuis /connexion ou /creer-un-compte :
 * aller DIRECTEMENT à la page compte.
 *
 * `wp_nonce_field()` ajoute d'office un champ `_wp_http_referer`, et WooCommerce
 * s'en sert comme destination après authentification
 * (`WC_Form_Handler::process_login()` et `::process_registration()`). Par défaut
 * on repart donc vers /connexion, puis le garde-fou « déjà connecté » plus bas
 * renvoie vers la page compte : deux redirections, et surtout une destination
 * finale qui **dépend de ce garde-fou**. S'il ne s'exécute pas (plugin sortant
 * plus tôt sur template_redirect, page servie depuis un cache…), le visiteur
 * reste sur /connexion tout en étant connecté. On coupe la dépendance : un seul
 * saut, destination correcte par construction.
 *
 * Ne touche QUE nos deux URL d'authentification : une connexion déclenchée
 * depuis une fiche livre continue de ramener sur la fiche livre.
 */
add_filter( 'woocommerce_login_redirect', 'pf_auth_post_auth_redirect' );
add_filter( 'woocommerce_registration_redirect', 'pf_auth_post_auth_redirect' );
function pf_auth_post_auth_redirect( $redirect ) {
	if ( ! is_string( $redirect ) || '' === $redirect ) {
		return $redirect;
	}

	// $redirect peut être absolu ou relatif à la racine : on normalise avant de comparer.
	$target = ( 0 === strpos( $redirect, 'http' ) ) ? $redirect : home_url( $redirect );
	$target = untrailingslashit( strtok( $target, '?' ) );

	foreach ( array_keys( pf_auth_aliases() ) as $slug ) {
		if ( $target === untrailingslashit( home_url( $slug ) ) ) {
			return wc_get_page_permalink( 'myaccount' );
		}
	}

	return $redirect;
}

/**
 * Déconnexion sans confirmation, même nonce périmé (onglet resté ouvert,
 * retour arrière depuis le bfcache…).
 *
 * Le lien « Se déconnecter » (hub ET sectionnav — tous deux passent par
 * wc_get_account_endpoint_url('customer-logout')) est noncé par WooCommerce ;
 * si ce nonce ne valide plus au clic, wc_template_redirect() n'appelle pas
 * wp_logout(), affiche « Êtes-vous sûr de vouloir vous déconnecter ? » (notice
 * → toast sur ce thème) et renvoie sur la page compte, TOUJOURS CONNECTÉ. Se
 * déconnecter est une action anodine et réversible (se reconnecter est
 * trivial) : cette confirmation ne protège de rien qui vaille la friction.
 *
 * Priorité 5, avant wc_template_redirect() (10) qui fait sinon cette
 * vérification lui-même.
 */
add_action( 'template_redirect', 'pf_auth_logout_without_confirm', 5 );
function pf_auth_logout_without_confirm() {
	if ( ! isset( $GLOBALS['wp']->query_vars['customer-logout'] ) || ! is_user_logged_in() ) {
		return;
	}
	wp_logout();
	wp_safe_redirect( wc_get_logout_redirect_url() );
	exit;
}

/**
 * Après déconnexion : atterrir sur /connexion, pas sur la page compte.
 *
 * WooCommerce renvoie par défaut sur « Mon compte » — où le visiteur, désormais
 * déconnecté, voit le formulaire de connexion sous une adresse qui annonce son
 * espace client. On corrige la **destination** à la source, via le filtre
 * officiel, plutôt que d'ajouter une redirection conditionnelle sur /mon-compte :
 * celle-ci porterait sur le chemin qui sert aussi la récupération de mot de
 * passe (endpoint `mot-de-passe-oublie`, cible du lien envoyé par e-mail), pour
 * un bénéfice purement cosmétique. Ici, un seul filtre, aucun cas limite.
 *
 * Couvre tous les chemins de déconnexion du front (entrée « Déconnexion » du
 * menu compte incluse) : ils passent tous par wc_logout_url() → wc_get_logout_redirect_url().
 */
add_filter( 'woocommerce_logout_default_redirect_url', 'pf_auth_logout_redirect' );
function pf_auth_logout_redirect( $url ) {
	return function_exists( 'pf_auth_url' ) ? pf_auth_url( 'login' ) : $url;
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

/**
 * Textes de la page « Mot de passe oublié » (gettext ciblé, pas d'édition du
 * plugin, survit aux MAJ WooCommerce).
 *
 * Filtre global : les deux msgid ci-dessous n'apparaissent qu'à cet endroit
 * dans WooCommerce/WP core (vérifié dans les .po), donc aucun risque de
 * collision ailleurs.
 */
add_filter( 'gettext', 'pf_auth_lost_password_strings', 10, 3 );
function pf_auth_lost_password_strings( $translated, $text, $domain ) {
	if ( 'woocommerce' !== $domain ) {
		return $translated;
	}
	if ( 'Lost your password? Please enter your username or email address. You will receive a link to create a new password via email.' === $text ) {
		return __( 'Mot de passe oublié ? Veuillez saisir votre identifiant ou votre adresse e-mail. Vous recevrez un lien par e-mail pour créer un nouveau mot de passe.', 'kadence-child' );
	}
	if ( 'Invalid username or email.' === $text ) {
		return __( 'Identifiant ou e-mail inconnu.', 'kadence-child' );
	}
	return $translated;
}

/**
 * Bouton « Réinitialiser le mot de passe » : scopé au rendu du formulaire
 * (woocommerce_before/after_lost_password_form), contrairement aux deux
 * chaînes ci-dessus. Le msgid « Reset password » est partagé avec le libellé
 * admin de l'e-mail de réinitialisation (class-wc-email-customer-reset-password.php),
 * qu'un filtre global renommerait aussi par effet de bord.
 */
add_action( 'woocommerce_before_lost_password_form', function () {
	add_filter( 'gettext', 'pf_auth_reset_password_button', 10, 3 );
} );
add_action( 'woocommerce_after_lost_password_form', function () {
	remove_filter( 'gettext', 'pf_auth_reset_password_button', 10 );
} );
function pf_auth_reset_password_button( $translated, $text, $domain ) {
	if ( 'woocommerce' === $domain && 'Reset password' === $text ) {
		return __( 'Réinitialiser le mot de passe', 'kadence-child' );
	}
	return $translated;
}
