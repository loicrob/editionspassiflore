<?php
function passiflore_remove_color_picker() {
    wp_dequeue_script('custom-color-picker');
    wp_deregister_script('custom-color-picker');
}
add_action('admin_enqueue_scripts', 'passiflore_remove_color_picker', 20);

// Le Tableau de bord (index.php) redirige vers les Statistiques pour les
// utilisateurs habilités, que ce soit juste après connexion ou en y revenant
// plus tard (favori, historique...).
function passiflore_redirect_dashboard_to_analytics() {
    global $pagenow;
    if ( $pagenow !== 'index.php' || isset( $_GET['page'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    wp_safe_redirect( admin_url( 'admin.php?page=wc-admin&path=%2Fanalytics%2Foverview' ) );
    exit;
}
add_action( 'admin_init', 'passiflore_redirect_dashboard_to_analytics' );

// La « catégorie primaire » Rank Math (lien "Make Primary" dans la metabox
// Catégories) n'est utilisée nulle part sur ce site (pas de %category% dans
// les permaliens, breadcrumbs Rank Math désactivés) → on masque le contrôle.
add_filter( 'rank_math/admin/disable_primary_term', '__return_true' );