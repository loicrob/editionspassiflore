<?php
/**
 * Synchronise le slug (post_name) d'un contenu sur son titre, à chaque enregistrement.
 *
 * WordPress fige le slug à la première publication : renommer laisse derrière soi une URL qui ne
 * veut plus rien dire (constaté en base avant ce correctif sur les événements — « Rencontre à la
 * librairie Passages » vivait sous /evenement/rencontre-a-ombres-blanches/).
 *
 * Resynchronisation SANS CONDITION (choix acté) : le titre fait foi, y compris si le slug avait
 * été retouché à la main. ⚠️ Conséquence directe — le champ « Permalien » de l'écran d'édition
 * devient inopérant sur ces types de contenu : toute valeur saisie est réécrite depuis le titre à
 * l'enregistrement. Pour rendre la main sur un contenu précis, il faudrait ne réécrire que
 * lorsque le slug est encore celui de l'ancien titre (`sanitize_title( $post_before->post_title )`).
 *
 * PAS de redirection à écrire ici : le cœur de WordPress mémorise chaque ancien slug dans la
 * post-meta `_wp_old_slug` (`wp_check_for_changed_slugs()`, publiés non hiérarchiques) et
 * `wp_old_slug_redirect()` sert un 301 vers la nouvelle URL sur `template_redirect`. Les anciens
 * slugs s'EMPILENT : les redirections PrestaShop de `redirections/*.csv` continuent donc
 * d'aboutir après un ou plusieurs renommages.
 *
 * Filtre posé sur `wp_insert_post_data`, qui s'exécute AVANT `pre_post_update` : le plugin
 * Redirection — s'il surveille un jour ce type de contenu — y relève encore l'ancien permalien
 * (la base n'est pas écrite) et voit bien le nouveau sur `post_updated`. Rien à réordonner.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Types de contenu dont le slug suit le titre.
 *
 * ⚠️ `product` inclus : les URL de livres perdent donc leur stabilité face à un renommage — à
 * mettre en balance avec le ré-import de données à venir, qui peut faire bouger des titres en
 * masse (`import-livres*.php`). `wp_old_slug_redirect()` couvre l'ancien lien à chaque fois.
 */
function pf_slug_sync_post_types() {
	return [ 'tribe_events', 'product' ];
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
	// (au moment du wp_update_post() de wp_untrash_post(), la base porte encore « trash »).
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

/**
 * Boîte « Permalien » de l'écran d'édition, sur ces mêmes types de contenu : le bouton
 * « Modifier » y est trompeur (cf. plus haut, le slug saisi serait de toute façon réécrit
 * depuis le titre à l'enregistrement). Retiré ; remplacé par un aperçu qui se met à jour en
 * direct pendant la frappe du titre (assets/js/admin-permalink-preview.js).
 *
 * @param string  $html Balisage HTML généré par get_sample_permalink_html().
 * @param int     $post_id
 * @param ?string $new_title
 * @param ?string $new_slug
 * @param WP_Post $post
 * @return string
 */
function pf_permalink_box_html( $html, $post_id, $new_title, $new_slug, $post ) {
	if ( ! in_array( $post->post_type, pf_slug_sync_post_types(), true ) ) return $html;

	// Retire le bouton « Modifier » (et le bloc OK/Annuler qu'il ouvrirait, jamais atteint sans lui).
	$html = preg_replace( '#<span id="edit-slug-buttons">.*?</span>\s*#s', '', $html );

	// Gabarit du permalien, jeton %postname%/%pagename% encore en place : évite au JS de redériver
	// la structure de permaliens (base de taxonomie hiérarchique, rewrite base…) pour prévisualiser.
	list( $template ) = get_sample_permalink( $post_id, $new_title, $new_slug );
	$html = str_replace(
		'<span id="sample-permalink">',
		'<span id="sample-permalink" data-pf-permalink-template="' . esc_attr( urldecode( $template ) ) . '">',
		$html
	);

	// Cliquer ne doit pas faire quitter l'écran d'édition : nouvel onglet + mention d'accessibilité
	// (WCAG 3.2.5, cf. inc/a11y.php — même technique que la mention « opens in a new tab » du cœur).
	$html = str_replace( '<a href="', '<a target="_blank" rel="noopener" href="', $html );
	$html = str_replace( '</a>', pf_new_window_note() . '</a>', $html );

	// Rassure sur la conséquence directe du renommage sans confirmation ci-dessus (wp_old_slug_redirect()
	// couvre l'ancien lien, cf. le commentaire de fichier).
	$html .= '<p class="description">L\'ancien permalien redirigera automatiquement vers le nouveau.</p>';

	return $html;
}
add_filter( 'get_sample_permalink_html', 'pf_permalink_box_html', 10, 5 );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	global $post;
	if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
	if ( ! isset( $post->post_type ) || ! in_array( $post->post_type, pf_slug_sync_post_types(), true ) ) return;

	wp_enqueue_script(
		'pf-admin-permalink-preview',
		get_stylesheet_directory_uri() . '/assets/js/admin-permalink-preview.js',
		[ 'wp-url' ],
		filemtime( get_stylesheet_directory() . '/assets/js/admin-permalink-preview.js' ),
		true
	);
} );
