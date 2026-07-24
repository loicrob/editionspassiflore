<?php
/**
 * Confidentialité / cookies — réduction au strict nécessaire (RGPD/CNIL).
 *
 * Objectif : maintenir le site à des cookies « strictement nécessaires »
 * (session/panier WooCommerce, paiement Stripe), donc exemptés de consentement,
 * afin d'éviter d'avoir à afficher un bandeau de consentement.
 *
 * Neutralise la fonction « Attribution des commandes » de WooCommerce (WC 8.5+),
 * qui charge Sourcebuster.js et pose des cookies NON essentiels `sbjs_*`
 * (source de trafic, campagne, pages vues, user-agent…) — de l'analytics
 * marketing, qui exigerait un consentement préalable. La maison d'édition
 * n'exploite pas ces données → on les retire plutôt que d'imposer un bandeau.
 *
 * ⚠️ Pourquoi PAS le filtre `woocommerce_feature_order_attribution_enabled` :
 * WooCommerce câble l'attribution pendant `plugins_loaded`, AVANT le chargement
 * du `functions.php` du thème → un filtre posé ici arrive trop tard (vérifié :
 * sans effet). On retire donc directement les deux scripts au moment de
 * l'enqueue (`sourcebuster-js` = le poseur de cookies, et `wc-order-attribution`
 * + son inline `-extra`). Sans ces scripts front, aucun cookie `sbjs_*` n'est
 * posé et aucune donnée d'attribution n'est collectée.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', 'pf_disable_order_attribution_scripts', 100 );
function pf_disable_order_attribution_scripts() {
	wp_dequeue_script( 'sourcebuster-js' );
	wp_dequeue_script( 'wc-order-attribution' );
}
