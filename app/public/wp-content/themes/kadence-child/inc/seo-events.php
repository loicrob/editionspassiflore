<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SEO des événements (Rank Math) — noindex des événements passés « anciens ».
 *
 * Contexte : ~97 % des événements sont passés depuis longtemps (fiches à faible
 * valeur : personne ne cherche un salon d'il y a des années, et un visiteur qui
 * tombe dessus depuis Google a une mauvaise expérience). Rank Math n'a pas de
 * règle native « par date » — on la pose ici via ses filtres.
 *
 * Politique : un événement terminé depuis plus de PF_EVENT_SEO_STALE_AGE est
 * mis en noindex ET retiré du sitemap, SAUF s'il est marqué « événement
 * marquant » (flag SCF `evenement_marquant`), auquel cas il reste indexé quel
 * que soit son âge. Futur / en cours / passé récent : indexé (comportement par
 * défaut de Rank Math, on ne touche à rien).
 *
 * Deux filtres sont nécessaires : le sitemap de Rank Math ne relit pas le
 * filtre `frontend/robots` — il exclut par SQL les posts dont la post-meta
 * `rank_math_robots` contient « noindex ». Notre noindex étant calculé au vol
 * (pas stocké en meta), il faut retirer explicitement l'URL du sitemap via
 * `rank_math/sitemap/entry`.
 */

// Ancienneté au-delà de laquelle un événement passé bascule en noindex.
if ( ! defined( 'PF_EVENT_SEO_STALE_AGE' ) ) {
    define( 'PF_EVENT_SEO_STALE_AGE', '-6 months' );
}

/**
 * Un événement doit-il être désindexé (passé « ancien » et non marquant) ?
 *
 * @param int $event_id ID du post tribe_events.
 * @return bool
 */
function pf_event_seo_stale( $event_id ) {
    // Événement marquant → toujours indexé, quel que soit son âge.
    if ( get_post_meta( $event_id, 'evenement_marquant', true ) ) {
        return false;
    }

    // Date de fin TEC, format 'Y-m-d H:i:s' en heure locale du site.
    $end = get_post_meta( $event_id, '_EventEndDate', true );
    if ( ! $end ) {
        return false;
    }

    return strtotime( $end ) < strtotime( PF_EVENT_SEO_STALE_AGE );
}

// 1) Balise <meta robots> de la fiche événement (dernier filtre robots de Rank Math).
add_filter( 'rank_math/frontend/robots', function ( $robots ) {
    if ( is_singular( 'tribe_events' ) && pf_event_seo_stale( get_queried_object_id() ) ) {
        $robots['index'] = 'noindex'; // motif exact employé par Rank Math lui-même
    }
    return $robots;
} );

// 2) Exclusion du sitemap XML (retour vide = entrée ignorée).
// ⚠️ Le provider de sitemap de Rank Math passe ici des lignes SQL brutes
// (stdClass via $wpdb->get_results, cf. Post_Type::get_posts()) — PAS des
// WP_Post. On teste donc par propriété (->post_type / ->ID), pas par classe.
add_filter( 'rank_math/sitemap/entry', function ( $url, $type, $object ) {
    if ( 'post' === $type
         && is_object( $object )
         && isset( $object->post_type, $object->ID )
         && 'tribe_events' === $object->post_type
         && pf_event_seo_stale( (int) $object->ID ) ) {
        return false;
    }
    return $url;
}, 10, 3 );
