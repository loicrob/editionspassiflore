<?php
/**
 * 301 des anciens slugs de TERMES vers leur URL courante — l'équivalent, côté taxonomies, de ce
 * que WordPress fait nativement pour les contenus.
 *
 * Le cœur mémorise chaque ancien slug de post en post-meta `_wp_old_slug`
 * (`wp_check_for_changed_slugs()`) et le redirige depuis `wp_old_slug_redirect()`. Il n'existe
 * RIEN de tel pour les termes, et le plugin Redirection ne comble pas le trou : son moniteur ne
 * s'accroche qu'à `pre_post_update` / `post_updated` / `wp_trash_post` et filtre sur
 * `get_post_type()` (cf. `redirection/models/monitor.php`), donc il ignore les taxonomies.
 *
 * Or le slug des auteurs est resynchronisé SANS CONDITION sur « Prénom Nom » à chaque
 * enregistrement SCF (`passiflore_auteur_sync_on_save()`, inc/auteurs.php) : corriger la casse
 * ou une coquille dans une fiche auteur déplaçait donc son URL en 404 silencieux — y compris les
 * 74 correspondances PrestaShop de `redirections/auteurs.csv`, qui visent /auteur/<slug>/.
 *
 * Même contrat que le cœur : les anciens slugs s'EMPILENT (term-meta `_pf_old_term_slug`,
 * une entrée par slug abandonné), donc une chaîne de renommages successifs reste résolvable.
 *
 * On se branche sur les hooks GÉNÉRIQUES de `wp_update_term()` plutôt que dans la synchro des
 * auteurs : un slug peut aussi changer depuis l'écran d'édition du terme, en WP-CLI ou à
 * l'import, et ces chemins-là méritent le même filet.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Taxonomies dont les anciens slugs sont mémorisés puis redirigés.
 *
 * `product_cat` est HIÉRARCHIQUE (7 des 14 rayons sont des sous-catégories) mais ne demande
 * aucun traitement de chemin : le cœur réduit lui-même la variable de requête au dernier
 * segment (`wp_basename()`, WP_Query::parse_tax_query) et les slugs de termes sont uniques par
 * taxonomie — ce segment identifie donc le terme sans ambiguïté, et `get_term_link()` reconstruit
 * l'arborescence courante côté cible.
 */
function pf_term_slug_taxonomies() {
	return [ 'auteur', 'product_cat' ];
}

/**
 * Relève le slug ENCORE en base, avant que wp_update_term() n'écrive.
 *
 * @param int    $term_id
 * @param string $taxonomy
 * @return void
 */
function pf_term_slug_remember( $term_id, $taxonomy ) {
	if ( ! in_array( $taxonomy, pf_term_slug_taxonomies(), true ) ) return;

	$term = get_term( $term_id, $taxonomy );
	if ( $term && ! is_wp_error( $term ) ) {
		$GLOBALS['pf_term_slug_before'][ (int) $term_id ] = $term->slug;
	}
}
add_action( 'edit_terms', 'pf_term_slug_remember', 10, 2 );

/**
 * Compare après écriture et mémorise le slug abandonné.
 *
 * @param int    $term_id
 * @param int    $tt_id
 * @param string $taxonomy
 * @return void
 */
function pf_term_slug_store( $term_id, $tt_id, $taxonomy ) {
	if ( ! in_array( $taxonomy, pf_term_slug_taxonomies(), true ) ) return;

	$term_id = (int) $term_id;
	$avant   = $GLOBALS['pf_term_slug_before'][ $term_id ] ?? '';
	unset( $GLOBALS['pf_term_slug_before'][ $term_id ] );

	// Création de terme, ou slug inchangé : rien à mémoriser.
	if ( '' === $avant ) return;

	$term = get_term( $term_id, $taxonomy );
	if ( ! $term || is_wp_error( $term ) || $term->slug === $avant ) return;

	// Aller-retour A → B → A : purger le slug redevenu courant, sinon il se redirigerait
	// vers lui-même. Le cœur fait exactement pareil dans wp_check_for_changed_slugs().
	delete_term_meta( $term_id, '_pf_old_term_slug', $term->slug );

	if ( ! in_array( $avant, (array) get_term_meta( $term_id, '_pf_old_term_slug' ), true ) ) {
		add_term_meta( $term_id, '_pf_old_term_slug', $avant );
	}
}
add_action( 'edited_term', 'pf_term_slug_store', 10, 3 );

/**
 * Sur 404 d'une URL de terme, retrouve le terme par son ancien slug et sert un 301.
 *
 * Ne se déclenche QUE sur 404 : si un autre terme a entre-temps repris le slug abandonné, la
 * page résout normalement et cette fonction n'est jamais atteinte — le terme vivant l'emporte
 * donc toujours sur l'historique, sans arbitrage à écrire.
 *
 * @return void
 */
function pf_old_term_slug_redirect() {
	if ( ! is_404() ) return;

	global $wpdb;

	foreach ( pf_term_slug_taxonomies() as $taxonomy ) {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax ) continue;

		// `query_var` vaut le nom de la variable, ou true (= le nom de la taxonomie).
		$slug = get_query_var( is_string( $tax->query_var ) ? $tax->query_var : $taxonomy );
		if ( ! is_string( $slug ) || '' === $slug ) continue;

		// ⚠️ Lecture directe de la table, et NON `get_terms()` avec une meta_query : sur
		// `product_cat`, WooCommerce (`wc_terms_clauses`, prio 99) remplace la jointure
		// `termmeta` par la sienne — celle du tri des rayons, `meta_key = 'order'` — en
		// laissant le WHERE de la meta_query se greffer dessus. La requête cherche alors un
		// terme dont l'ORDRE DE TRI vaut le slug, et ne rend jamais rien (constaté : aucune
		// redirection sur les rayons, alors que la meta était bien écrite).
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = '_pf_old_term_slug' AND meta_value = %s",
			$slug
		) );

		foreach ( $ids as $id ) {
			// `get_term()` écarte de lui-même un terme d'une autre taxonomie — ce qui borne
			// la recherche à celle dont l'URL a été demandée.
			$terme = get_term( (int) $id, $taxonomy );
			if ( ! $terme || is_wp_error( $terme ) ) continue;

			$url = get_term_link( $terme );
			if ( is_wp_error( $url ) ) continue;

			wp_safe_redirect( $url, 301 );
			exit;
		}
	}
}
add_action( 'template_redirect', 'pf_old_term_slug_redirect' );
