<?php
/**
 * Aligne `_stock_status` (WooCommerce, pilote le bouton d'achat) sur la SCF
 * `disponibilite` (décision éditoriale) : sans synchro les deux peuvent
 * diverger (cf. l'union de secours dans inc/search.php), et un livre marqué
 * épuisé resterait achetable — ou l'inverse bloquerait un livre disponible.
 * `disponibilite` fait foi ; aucun stock n'étant réellement compté ici
 * (`_manage_stock` = 'no' sur tout le catalogue), rien ne justifierait que
 * `_stock_status` diverge de sa propre initiative.
 */
function passiflore_sync_stock_status_from_disponibilite( $post_id ) {
    if ( ! is_numeric( $post_id ) ) return;             // acf/save_post passe aussi "options", "auteur_123"…
    if ( get_post_type( $post_id ) !== 'product' ) return;
    if ( wp_is_post_revision( $post_id ) ) return;

    static $is_syncing = false;
    if ( $is_syncing ) return;
    $is_syncing = true;

    $post_id = (int) $post_id;
    switch ( get_post_meta( $post_id, 'disponibilite', true ) ) {
        case 'epuise':
            $target = 'outofstock';
            break;
        case 'bientot-disponible':
        case 'a-paraitre':
            $target = 'onbackorder';
            break;
        default:
            $target = 'instock';
    }

    if ( get_post_meta( $post_id, '_stock_status', true ) !== $target ) {
        wc_update_product_stock_status( $post_id, $target );
    }

    $is_syncing = false;
}
// acf/save_post @20 : après l'écriture des champs SCF (disponibilite à jour).
add_action( 'acf/save_post', 'passiflore_sync_stock_status_from_disponibilite', 20 );
// save_post_product : import, quick edit, modif hors SCF.
add_action( 'save_post_product', 'passiflore_sync_stock_status_from_disponibilite', 20 );
