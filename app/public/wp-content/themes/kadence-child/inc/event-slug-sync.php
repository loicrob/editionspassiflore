<?php
/**
 * Synchronise le slug (post_name) d'un événement sur son titre, à chaque enregistrement.
 *
 * WordPress fige le slug à la première publication : renommer un événement laisse derrière lui
 * une URL qui ne veut plus rien dire (constaté en base avant ce correctif — « Rencontre à la
 * librairie Passages » vivait sous /evenement/rencontre-a-ombres-blanches/, et les copies
 * produites par `event-duplicate.php` gardaient leur suffixe `-copie`).
 *
 * Resynchronisation SANS CONDITION (choix acté) : le titre fait foi, y compris si le slug avait
 * été retouché à la main. ⚠️ Conséquence directe — le champ « Permalien » de l'écran d'édition
 * devient inopérant sur les événements : toute valeur saisie est réécrite depuis le titre à
 * l'enregistrement. Pour rendre la main sur un événement précis, il faudrait ne réécrire que
 * lorsque le slug est encore celui de l'ancien titre (`sanitize_title( $post_before->post_title )`).
 *
 * PAS de redirection à écrire ici : le cœur de WordPress mémorise chaque ancien slug dans la
 * post-meta `_wp_old_slug` (`wp_check_for_changed_slugs()`, publiés non hiérarchiques) et
 * `wp_old_slug_redirect()` sert un 301 vers la nouvelle URL sur `template_redirect`. Les anciens
 * slugs s'EMPILENT : les redirections PrestaShop de `redirections/redirections_actualites.csv`,
 * qui visent /evenement/<slug>/, continuent donc d'aboutir après un ou plusieurs renommages.
 *
 * Filtre posé sur `wp_insert_post_data`, qui s'exécute AVANT `pre_post_update` : le plugin
 * Redirection — s'il surveille un jour ce type de contenu — y relève encore l'ancien permalien
 * (la base n'est pas écrite) et voit bien le nouveau sur `post_updated`. Rien à réordonner.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Types de contenu dont le slug suit le titre.
 *
 * Volontairement limité aux événements : les livres sont des pages commerciales dont l'URL a
 * vocation à être stable, et leur ré-import est encore devant nous.
 */
function pf_slug_sync_post_types() {
	return [ 'tribe_events' ];
}

/**
 * @param array $data    Colonnes prêtes pour l'écriture (encore échappées).
 * @param array $postarr Données brutes transmises à wp_insert_post().
 * @return array
 */
function pf_slug_sync_from_title( $data, $postarr ) {
	$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;

	// Création : WordPress dérive déjà le slug du titre, rien à corriger.
	if ( ! $post_id ) return $data;

	if ( ! in_array( $data['post_type'] ?? '', pf_slug_sync_post_types(), true ) ) return $data;

	// Corbeille : le cœur suffixe le slug en `__trashed` puis le restaure à la sortie. Ne pas
	// s'en mêler — d'où le test sur le statut ENREGISTRÉ, qui couvre aussi la restauration
	// (au moment du wp_update_post() de `wp_untrash_post()`, la base porte encore « trash »).
	$status = $data['post_status'] ?? '';
	if ( in_array( $status, [ 'trash', 'auto-draft' ], true ) ) return $data;
	if ( 'trash' === get_post_status( $post_id ) )              return $data;

	$slug = sanitize_title( wp_unslash( (string) ( $data['post_title'] ?? '' ) ) );

	// Titre vide, ou composé de seuls caractères que sanitize_title() ne translittère pas.
	if ( '' === $slug ) return $data;

	if ( $slug === ( $data['post_name'] ?? '' ) ) return $data;

	// `wp_unique_post_slug()` a déjà tourné en amont, sur l'ANCIEN slug : à nous de dédoublonner
	// le nouveau (il s'exclut lui-même du contrôle grâce à $post_id).
	$data['post_name'] = wp_unique_post_slug(
		$slug,
		$post_id,
		$status,
		$data['post_type'],
		(int) ( $data['post_parent'] ?? 0 )
	);

	return $data;
}
add_filter( 'wp_insert_post_data', 'pf_slug_sync_from_title', 10, 2 );
