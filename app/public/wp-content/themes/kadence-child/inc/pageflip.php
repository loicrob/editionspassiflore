<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── URL /extrait — règles de réécriture ───────────────────────────
// Permet d'ouvrir directement le modal flipbook via un lien partagé.
// Règle 1 : /base/slug/extrait  (ex. /produit/nom-du-livre/extrait)
// Règle 2 : /slug/extrait       (URLs plates sans base)
add_action('init', function () {
    add_rewrite_rule(
        '^[^/]+/([^/]+)/extrait/?$',
        'index.php?product=$matches[1]',
        'top'
    );
    add_rewrite_rule(
        '^([^/]+)/extrait/?$',
        'index.php?product=$matches[1]',
        'top'
    );
}, 20);

// Flush au changement de thème ou si la règle n'est pas encore enregistrée.
add_action('after_switch_theme', 'flush_rewrite_rules');
add_action('admin_init', function () {
    if ( get_option('pf_extrait_rewrite_v') !== '1' ) {
        flush_rewrite_rules();
        update_option('pf_extrait_rewrite_v', '1', false);
    }
});

add_action('wp_enqueue_scripts', function () {
    if ( ! is_product() ) return;

    wp_enqueue_style(
        'passiflore-pageflip',
        get_stylesheet_directory_uri() . '/assets/css/pageflip.css',
        [],
        '1.9'
    );
    // StPageFlip ne sert qu'aux livres avec un extrait PDF réel — un livre
    // sans extrait affiche sa couverture en simple image zoomable (pageflip.js),
    // pas la peine de tirer la lib de feuilletage pour ça.
    $deps = [];
    if ( get_field( 'extrait', get_the_ID() ) ) {
        wp_enqueue_script(
            'stpageflip',
            'https://cdn.jsdelivr.net/npm/page-flip@2.0.7/dist/js/page-flip.browser.js',
            [],
            '2.0.7',
            true
        );
        $deps[] = 'stpageflip';
    }
    wp_enqueue_script(
        'passiflore-pageflip',
        get_stylesheet_directory_uri() . '/assets/js/pageflip.js',
        $deps,
        '2.9',
        true
    );
});

// Charger pageflip.js en tant que module ES (nécessaire pour les imports pdfjs).
add_filter('script_loader_tag', function ($tag, $handle) {
    if ($handle === 'passiflore-pageflip') {
        return str_replace('<script ', '<script type="module" ', $tag);
    }
    return $tag;
}, 10, 2);
