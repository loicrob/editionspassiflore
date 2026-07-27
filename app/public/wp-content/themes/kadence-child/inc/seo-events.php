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

/**
 * 3) Canonical des vues d'archive événements : retrait du slash final.
 *
 * Sur ces vues (liste / mois / carte, paginées ou non), Rank Math dérive le
 * canonical de get_post_type_archive_link( 'tribe_events' ), que TEC filtre
 * (Tribe__Events__Main::event_archive_link) pour y forcer un slash final. Or la
 * structure de permaliens du site est sans slash (/%postname%) → WordPress
 * (redirect_canonical) redirige /evenements/ en 301 vers /evenements. Le
 * canonical pointait donc vers une URL qui redirige.
 *
 * Garde-fou de portabilité : on ne normalise que si le site est bien en mode
 * « sans slash final ». Si la structure de permaliens passait un jour en mode
 * avec slash, le slash de TEC redeviendrait correct et ce filtre s'efface.
 */
add_filter( 'rank_math/frontend/canonical', function ( $canonical ) {
    global $wp_rewrite;

    if ( ! is_string( $canonical ) || '' === $canonical ) {
        return $canonical;
    }
    if ( empty( $wp_rewrite ) || $wp_rewrite->use_trailing_slashes ) {
        return $canonical;
    }
    // Vues d'archive TEC uniquement (fiche événement exclue : elle est déjà
    // sans slash, son canonical vient de get_permalink()).
    if ( ! function_exists( 'tribe_is_event_query' ) || ! tribe_is_event_query() || is_singular() ) {
        return $canonical;
    }

    return untrailingslashit( $canonical );
} );

/**
 * 4) Titre <title> de l'archive événements — remplacement d'un titre TEC buggé.
 *
 * Pour construire le <title> (uniquement — le contenu affiché de la page est
 * correct), TEC (Title::build_post_range_title()) relance une requête interne
 * triée par date croissante SANS filtrer sur « à venir seulement », et affiche
 * la plage [premier événement retourné → dernier]. Avec ~1300 événements
 * historiques importés de PrestaShop, cette requête remonte les tout premiers
 * événements jamais enregistrés (2014-2015) au lieu des événements à venir
 * affichés sur la page : titre du type « Évènements depuis samedi 18 octobre
 * 2014 – jeudi 26 février 2015 ». Bug de TEC, pas de notre code : on se
 * contente de remplacer ce titre par un simple « Événements ».
 *
 * Vue mois également ramenée à « Événements » (simple choix éditorial, son
 * titre par date n'était pas buggé — cf. Title::build_month_title()). Jour
 * garde son propre titre ; fiche événement (vue singulière) non concernée,
 * ce filtre n'y étant pas atteint avec ce titre.
 */
add_filter( 'tribe_events_v2_view_title', function ( $title ) {
    if ( is_singular( 'tribe_events' ) || tribe_is_day() ) {
        return $title;
    }
    return 'Événements';
} );
