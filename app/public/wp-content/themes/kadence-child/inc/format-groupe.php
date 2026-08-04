<?php
/**
 * Modèle « œuvre / format / représentant » — source unique.
 *
 * Une œuvre existe en plusieurs éditions (classique, grands caractères,
 * numérique…), chacune étant un produit WooCommerce distinct. Trois éléments
 * les relient :
 *  - taxonomie `format_groupe`        : à quelle œuvre appartient l'édition ;
 *  - attribut  `pa_format_particulier`: quel format elle porte (ABSENCE de
 *    terme = édition classique, jamais un terme nommé « classique ») ;
 *  - le titre                         : racine commune + suffixe de format.
 *
 * Ce fichier ne contient QUE le modèle (lecture + règles). La saisie vit dans
 * inc/product-format-admin.php, qui en est le seul point d'écriture côté
 * formulaire.
 *
 * ⚠️ Aucun ordre de formats en dur ici ni ailleurs : `pf_format_order()` est
 * la seule autorité, dérivée de l'ordre glissé-déposé des termes de l'attribut
 * dans WooCommerce. Une liste en dur avait déjà laissé `folio` invisible — un
 * groupe qui n'aurait eu que ce format n'avait aucun représentant.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Term meta portant le représentant choisi à la main (ID produit). */
const PF_GROUP_REP_META = '_pf_group_rep';

/* ═══════════════════════════════════════════════════════════════
   Ordre canonique des formats
   ═══════════════════════════════════════════════════════════════ */

/**
 * Slugs de format dans l'ordre de priorité, `''` (classique) en tête.
 *
 * WooCommerce impose `orderby = menu_order` sur cette taxonomie
 * (`attribute_orderby` en base → wc_change_pre_get_terms()), donc l'ordre rendu
 * est celui du glisser-déposer dans Produits → Attributs → Format particulier.
 *
 * ⚠️ `hide_empty => false` obligatoire : `poche`, `folio` et `audio` sont à 0
 * produit et disparaîtraient de l'ordre avec le défaut de get_terms().
 */
function pf_format_order(): array {
	static $cache = null;
	if ( $cache !== null ) return $cache;

	$slugs = get_terms( [
		'taxonomy'   => 'pa_format_particulier',
		'hide_empty' => false,
		'fields'     => 'slugs',
	] );
	if ( is_wp_error( $slugs ) ) $slugs = [];

	array_unshift( $slugs, '' );
	return $cache = array_values( array_unique( array_map( 'strval', $slugs ) ) );
}

/** Termes de format dans l'ordre canonique (le classique n'en fait pas partie). */
function pf_format_terms(): array {
	static $cache = null;
	if ( $cache !== null ) return $cache;

	$terms = get_terms( [
		'taxonomy'   => 'pa_format_particulier',
		'hide_empty' => false,
	] );
	return $cache = is_wp_error( $terms ) ? [] : $terms;
}

/* ═══════════════════════════════════════════════════════════════
   Titres : racine + suffixe de format
   ═══════════════════════════════════════════════════════════════ */

/** Slug de format d'un produit — `''` pour une édition classique. */
function pf_format_of( int $product_id ): string {
	$slugs = wp_get_object_terms( $product_id, 'pa_format_particulier', [ 'fields' => 'slugs' ] );
	if ( is_wp_error( $slugs ) || empty( $slugs ) ) return '';
	return (string) $slugs[0];
}

/**
 * Suffixe de titre d'un format : « (grands caractères) ».
 *
 * Le nom du terme est passé en minuscules — c'est la forme que portent les 85
 * produits déjà en base (terme « Grands caractères », titre « … (grands
 * caractères) »).
 */
function pf_format_suffix( string $slug ): string {
	if ( $slug === '' ) return '';
	$term = get_term_by( 'slug', $slug, 'pa_format_particulier' );
	if ( ! $term || is_wp_error( $term ) ) return '';
	return ' (' . mb_strtolower( $term->name ) . ')';
}

/** Nom de produit final = racine + suffixe du format. */
function pf_format_compose( string $root, string $slug ): string {
	return trim( $root ) . pf_format_suffix( $slug );
}

/**
 * Racine du titre d'un produit = son nom moins le suffixe EXACT de son propre
 * format (comparaison insensible à la casse).
 *
 * ⚠️ Jamais de motif générique : ni la regex à liste blanche qui vivait dans
 * modifier-produit.php (elle ratait « (deuxième édition) »), ni le
 * `\([^()]*\)$` de spine_label() (il mangerait une parenthèse légitime du
 * titre). Quand le titre ne se termine pas par le suffixe attendu, il est
 * renvoyé TEL QUEL — c'est la seule façon de ne pas renommer en silence un
 * produit hérité ; l'écran de saisie le signale à la place.
 */
function pf_format_root( int $product_id ): string {
	// Contexte `raw` obligatoire : le défaut (`display`) applique wptexturize,
	// qui transformerait les apostrophes droites en typographiques — la racine
	// recomposée ne correspondrait plus au titre stocké.
	$title  = (string) get_post_field( 'post_title', $product_id, 'raw' );
	$suffix = pf_format_suffix( pf_format_of( $product_id ) );
	if ( $suffix === '' ) return $title;

	$len = mb_strlen( $suffix );
	if ( mb_strtolower( mb_substr( $title, -$len ) ) !== mb_strtolower( $suffix ) ) {
		return $title;
	}
	return rtrim( mb_substr( $title, 0, mb_strlen( $title ) - $len ) );
}

/** Le nom stocké suit-il la convention « racine + suffixe du format » ? */
function pf_format_title_is_coherent( int $product_id ): bool {
	$title  = (string) get_post_field( 'post_title', $product_id, 'raw' );
	$suffix = pf_format_suffix( pf_format_of( $product_id ) );
	if ( $suffix === '' ) return true;

	$len = mb_strlen( $suffix );
	return mb_strtolower( mb_substr( $title, -$len ) ) === mb_strtolower( $suffix );
}

/* ═══════════════════════════════════════════════════════════════
   Membres d'un groupe
   ═══════════════════════════════════════════════════════════════ */

/**
 * Membres publiés d'un `format_groupe` : [ product_id => format_slug ].
 *
 * Une seule requête (au lieu d'un get_posts par format), ordonnée post_date
 * DESC pour conserver le départage historique à format égal.
 */
function pf_group_members( int $term_id, bool $refresh = false ): array {
	static $cache = [];
	if ( ! $refresh && isset( $cache[ $term_id ] ) ) return $cache[ $term_id ];
	if ( $term_id <= 0 ) return [];

	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID AS product_id, ft.slug AS format_slug
		 FROM {$wpdb->term_relationships} gtr
		 INNER JOIN {$wpdb->term_taxonomy} gtt
			 ON gtt.term_taxonomy_id = gtr.term_taxonomy_id AND gtt.taxonomy = 'format_groupe'
		 INNER JOIN {$wpdb->posts} p
			 ON p.ID = gtr.object_id AND p.post_type = 'product' AND p.post_status = 'publish'
		 LEFT JOIN {$wpdb->term_relationships} ftr
			 ON ftr.object_id = p.ID
		 LEFT JOIN {$wpdb->term_taxonomy} ftt
			 ON ftt.term_taxonomy_id = ftr.term_taxonomy_id AND ftt.taxonomy = 'pa_format_particulier'
		 LEFT JOIN {$wpdb->terms} ft
			 ON ft.term_id = ftt.term_id
		 WHERE gtt.term_id = %d
		 ORDER BY p.post_date DESC, p.ID DESC",
		$term_id
	) );

	return $cache[ $term_id ] = pf_group_rows_to_members( $rows );
}

/**
 * Agrège des lignes SQL (product_id, format_slug) en [ id => slug ].
 *
 * Le LEFT JOIN produit une ligne par terme de format : si un produit en portait
 * deux (anomalie), on garde le slug non vide plutôt que de le prendre pour un
 * classique.
 */
function pf_group_rows_to_members( array $rows ): array {
	$members = [];
	foreach ( $rows as $r ) {
		$pid  = (int) $r->product_id;
		$slug = (string) ( $r->format_slug ?? '' );
		if ( ! isset( $members[ $pid ] ) || ( $members[ $pid ] === '' && $slug !== '' ) ) {
			$members[ $pid ] = $slug;
		}
	}
	return $members;
}

/**
 * Sous-ensemble épuisé d'une liste de produits, en une requête : [ id => true ].
 *
 * Union `_stock_status = outofstock` OU `disponibilite = epuise`. inc/stock-sync.php
 * rend déjà les deux équivalents ; l'union est une ceinture contre la dérive,
 * même raison que pf_search_products_ranked_global().
 *
 * ⚠️ `onbackorder` (à paraître / bientôt disponible) n'est PAS épuisé : un livre
 * à paraître reste un représentant légitime.
 */
function pf_format_epuise_map( array $product_ids ): array {
	$ids = array_filter( array_map( 'intval', $product_ids ) );
	if ( empty( $ids ) ) return [];

	global $wpdb;
	$in   = implode( ',', $ids );
	$rows = $wpdb->get_col(
		"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
		 WHERE post_id IN ($in)
		   AND ( ( meta_key = '_stock_status' AND meta_value = 'outofstock' )
			  OR ( meta_key = 'disponibilite'  AND meta_value = 'epuise' ) )"
	);
	return array_fill_keys( array_map( 'intval', $rows ), true );
}

/* ═══════════════════════════════════════════════════════════════
   Représentant — LA règle, écrite une seule fois
   ═══════════════════════════════════════════════════════════════ */

/**
 * Représentant d'un groupe à partir de ses membres.
 *
 * ⭐ Point unique de décision : la version unitaire comme la version en lot
 * passent par ici. Les deux ne peuvent donc pas diverger — c'est tout l'objet
 * de cette fonction, trois implémentations concurrentes ayant coexisté avant.
 *
 *  1. le choix manuel, s'il est membre et non épuisé ;
 *  2. sinon le premier format non épuisé dans l'ordre canonique ;
 *  3. si TOUS les membres sont épuisés, le choix manuel reprend la main,
 *     à défaut le premier format dans l'ordre canonique.
 *
 * @param array    $members  [ product_id => format_slug ], ordre post_date DESC
 * @param int|null $override ID du représentant choisi à la main
 * @param array    $epuise   [ product_id => true ]
 */
function pf_group_rank( array $members, ?int $override, array $epuise ): ?int {
	if ( empty( $members ) ) return null;

	$ranked = pf_group_sort_members( $members );

	// Un choix manuel périmé (produit sorti du groupe, dépublié, supprimé) est
	// simplement ignoré : rien à nettoyer au détachement.
	$override = ( $override && isset( $members[ $override ] ) ) ? (int) $override : null;

	if ( $override && ! isset( $epuise[ $override ] ) ) return $override;

	foreach ( $ranked as $pid ) {
		if ( ! isset( $epuise[ $pid ] ) ) return $pid;
	}

	return $override ?: $ranked[0];
}

/**
 * Membres triés selon l'ordre canonique des formats ; à format égal, l'ordre
 * d'entrée est conservé (post_date DESC tel que fourni par pf_group_members()).
 * Un slug inconnu de l'ordre canonique tombe en fin plutôt que d'être perdu.
 *
 * @param array $members [ product_id => format_slug ]
 * @return int[]
 */
function pf_group_sort_members( array $members ): array {
	$order = array_flip( pf_format_order() );
	$last  = count( $order );

	$ranked = [];
	$seq    = 0;
	foreach ( $members as $pid => $slug ) {
		$ranked[] = [
			'id'  => (int) $pid,
			'pos' => $order[ $slug ] ?? $last,
			'seq' => $seq++,
		];
	}
	usort( $ranked, fn( $a, $b ) => [ $a['pos'], $a['seq'] ] <=> [ $b['pos'], $b['seq'] ] );

	return array_map( fn( $r ) => $r['id'], $ranked );
}

/** Représentant d'un terme `format_groupe`, ou null si le groupe est vide. */
function pf_group_representative( int $term_id, bool $refresh = false ): ?int {
	static $cache = [];
	if ( ! $refresh && array_key_exists( $term_id, $cache ) ) return $cache[ $term_id ];

	$members = pf_group_members( $term_id, $refresh );
	if ( empty( $members ) ) return $cache[ $term_id ] = null;

	$override = (int) get_term_meta( $term_id, PF_GROUP_REP_META, true );
	$epuise   = pf_format_epuise_map( array_keys( $members ) );

	return $cache[ $term_id ] = pf_group_rank( $members, $override ?: null, $epuise );
}

/**
 * Représentant de CHAQUE groupe, en trois requêtes au lieu d'une par terme.
 * Même règle que pf_group_representative() — littéralement, via pf_group_rank().
 *
 * @return int[] Les groupes sans membre publié sont ignorés.
 */
function pf_group_representatives_all(): array {
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT gtt.term_id AS group_id, p.ID AS product_id, ft.slug AS format_slug
		 FROM {$wpdb->term_relationships} gtr
		 INNER JOIN {$wpdb->term_taxonomy} gtt
			 ON gtt.term_taxonomy_id = gtr.term_taxonomy_id AND gtt.taxonomy = 'format_groupe'
		 INNER JOIN {$wpdb->posts} p
			 ON p.ID = gtr.object_id AND p.post_type = 'product' AND p.post_status = 'publish'
		 LEFT JOIN {$wpdb->term_relationships} ftr
			 ON ftr.object_id = p.ID
		 LEFT JOIN {$wpdb->term_taxonomy} ftt
			 ON ftt.term_taxonomy_id = ftr.term_taxonomy_id AND ftt.taxonomy = 'pa_format_particulier'
		 LEFT JOIN {$wpdb->terms} ft
			 ON ft.term_id = ftt.term_id
		 ORDER BY p.post_date DESC, p.ID DESC"
	);

	$by_group = [];
	foreach ( $rows as $r ) {
		$by_group[ (int) $r->group_id ][] = $r;
	}
	if ( empty( $by_group ) ) return [];

	// Choix manuels et états d'épuisement lus en lot.
	$overrides = [];
	foreach ( $wpdb->get_results(
		"SELECT term_id, meta_value FROM {$wpdb->termmeta}
		 WHERE meta_key = '" . PF_GROUP_REP_META . "'"
	) as $m ) {
		$overrides[ (int) $m->term_id ] = (int) $m->meta_value;
	}

	$all_ids = array_map( fn( $r ) => (int) $r->product_id, $rows );
	$epuise  = pf_format_epuise_map( $all_ids );

	$reps = [];
	foreach ( $by_group as $gid => $group_rows ) {
		$rep = pf_group_rank(
			pf_group_rows_to_members( $group_rows ),
			$overrides[ $gid ] ?? null,
			$epuise
		);
		if ( $rep ) $reps[] = $rep;
	}
	return $reps;
}

/**
 * Représentant de l'œuvre à laquelle appartient un produit — le produit
 * lui-même s'il est autonome.
 */
function pf_format_representative_of( int $product_id ): int {
	$fg = wp_get_object_terms( $product_id, 'format_groupe', [ 'fields' => 'ids' ] );
	if ( is_wp_error( $fg ) || empty( $fg ) ) return $product_id;

	$rep = pf_group_representative( (int) $fg[0] );
	return $rep ?: $product_id;
}

/** Terme `format_groupe` d'un produit, 0 s'il est autonome. */
function pf_format_group_of( int $product_id ): int {
	$fg = wp_get_object_terms( $product_id, 'format_groupe', [ 'fields' => 'ids' ] );
	if ( is_wp_error( $fg ) || empty( $fg ) ) return 0;
	return (int) $fg[0];
}

/* ═══════════════════════════════════════════════════════════════
   Assets admin partagés
   ═══════════════════════════════════════════════════════════════ */

/**
 * Recherche floue locale, partagée par les trois sélecteurs de livres de
 * l'admin. Fichier autonome (pas de dépendance à jQuery ni à jquery-ui-sortable)
 * pour que l'écran produit n'ait pas à charger le picker multi-sélection.
 */
function pf_enqueue_book_filter() {
	wp_enqueue_script(
		'pf-book-filter',
		get_stylesheet_directory_uri() . '/assets/js/book-filter.js',
		[],
		filemtime( get_stylesheet_directory() . '/assets/js/book-filter.js' ),
		true
	);
}

