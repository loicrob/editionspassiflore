<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
    $hook = add_menu_page(
        'Accueil',
        'Accueil',
        'edit_pages',
        'pf-actualites-edit',
        'passiflore_render_accueil_admin_page',
        'dashicons-admin-home',
        25
    );
    add_action( 'load-' . $hook, 'acf_form_head' );
} );

function passiflore_render_accueil_admin_page() {
    $front_page_id = (int) get_option( 'page_on_front' );

    if ( ! $front_page_id ) {
        echo '<div class="wrap"><p>Aucune page d’accueil n’est définie (Réglages → Lecture).</p></div>';
        return;
    }
    ?>
    <div class="wrap">
        <h1>Page d’accueil</h1>
        <?php
        acf_form( [
            'id'              => 'pf-accueil-form',
            'post_id'         => $front_page_id,
            'field_groups'    => [ 'group_6a1b1c9de461a' ],
            'form'            => true,
            'submit_value'    => 'Enregistrer',
            'updated_message' => 'Page d’accueil mise à jour.',
        ] );
        ?>
    </div>
    <?php
}

// Le groupe de champs "Accueil" reste localisé sur la page d'accueil (pour
// que ses données restent attachées au post), mais on masque sa boîte méta
// sur l'écran d'édition standard : la page d'admin ci-dessus est la seule
// UI d'édition, pour éviter deux endroits éditant la même donnée.
add_action( 'add_meta_boxes', function () {
    remove_meta_box( 'acf-group_6a1b1c9de461a', 'page', 'normal' );
}, 20 );

// Enqueue bookshelf assets before wp_head (priority 20, after register_assets at 10)
// so do_shortcode('[passiflore_etagere]') in the template doesn't miss the CSS.
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_front_page() ) return;
    wp_enqueue_style( 'pf-bookshelf' );
    wp_enqueue_script( 'pf-bookshelf' );
}, 20 );

add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_front_page() ) return;

    wp_enqueue_style(
        'splide',
        'https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/css/splide.min.css',
        [],
        '4.1.4'
    );
    wp_enqueue_style(
        'pf-events',
        get_stylesheet_directory_uri() . '/assets/css/events.css',
        [],
        filemtime( get_stylesheet_directory() . '/assets/css/events.css' )
    );
    wp_enqueue_style(
        'pf-accueil',
        get_stylesheet_directory_uri() . '/assets/css/accueil.css',
        [ 'splide', 'pf-events' ],
        filemtime( get_stylesheet_directory() . '/assets/css/accueil.css' )
    );
    wp_enqueue_script(
        'splide',
        'https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/js/splide.min.js',
        [],
        '4.1.4',
        true
    );
    wp_enqueue_script(
        'pf-scroll-fade',
        get_stylesheet_directory_uri() . '/assets/js/scroll-fade.js',
        [],
        filemtime( get_stylesheet_directory() . '/assets/js/scroll-fade.js' ),
        true
    );
    wp_enqueue_script(
        'pf-accueil',
        get_stylesheet_directory_uri() . '/assets/js/accueil.js',
        [ 'splide', 'pf-scroll-fade' ],
        filemtime( get_stylesheet_directory() . '/assets/js/accueil.js' ),
        true
    );
} );
