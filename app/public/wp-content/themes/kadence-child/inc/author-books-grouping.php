<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ═══════════════════════════════════════════════════════════════
   Groupement des livres par ensemble exact d'auteurs
   Partagé entre les fiches livres et les fiches auteurs.
   ═══════════════════════════════════════════════════════════════ */

/**
 * Deduplicate a list of product IDs by format_groupe: for each group, keep
 * the classique edition (no pa_format_particulier term), or the first found.
 * Products with no format_groupe are always kept as-is.
 */
function passiflore_dedup_by_format_groupe( array $ids ): array {
	$standalone = [];
	$buckets    = [];

	foreach ( $ids as $id ) {
		$id       = (int) $id;
		$fg_terms = wp_get_object_terms( $id, 'format_groupe', [ 'fields' => 'ids' ] );
		if ( is_wp_error( $fg_terms ) || empty( $fg_terms ) ) {
			$standalone[] = $id;
		} else {
			$buckets[ (int) $fg_terms[0] ][] = $id;
		}
	}

	$result = $standalone;
	foreach ( $buckets as $group_ids ) {
		$rep = null;
		foreach ( $group_ids as $gid ) {
			$pa = wp_get_object_terms( $gid, 'pa_format_particulier', [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $pa ) && empty( $pa ) ) {
				$rep = $gid;
				break;
			}
		}
		$result[] = $rep ?? $group_ids[0];
	}

	return $result;
}

/**
 * Tous les produits publiés partageant le format_groupe du produit donné,
 * produit courant inclus. Retourne [ $product_id ] si le produit n'a pas de
 * terme format_groupe (livre autonome).
 */
function passiflore_get_format_groupe_product_ids( int $product_id ): array {
	$fg_terms = wp_get_object_terms( $product_id, 'format_groupe', [ 'fields' => 'ids' ] );
	if ( is_wp_error( $fg_terms ) || empty( $fg_terms ) ) {
		return [ $product_id ];
	}
	$ids = get_posts( [
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'tax_query'      => [ [ 'taxonomy' => 'format_groupe', 'terms' => $fg_terms ] ],
		'orderby'        => 'date',
		'order'          => 'ASC',
	] );
	return ! empty( $ids ) ? array_map( 'intval', $ids ) : [ $product_id ];
}

/**
 * Fusionne un repeater SCF sur plusieurs produits (typiquement les éditions
 * d'un même format_groupe), en dédupliquant les entrées dont les sous-champs
 * listés dans $key_fields sont identiques.
 */
function passiflore_collect_group_repeater( array $product_ids, string $field, array $key_fields ): array {
	// Normalise pour la comparaison : tout enchaînement d'espaces (y compris
	// retours à la ligne et espaces insécables) devient un espace simple. Une même
	// entrée saisie sur deux formats avec un espacement légèrement différent doit
	// produire la même clé et n'apparaître qu'une fois.
	$norm = fn( $s ) => preg_replace( '/[\s\x{00A0}]+/u', ' ', trim( (string) $s ) );
	$seen = [];
	$out  = [];
	foreach ( $product_ids as $pid ) {
		foreach ( (array) ( get_field( $field, $pid ) ?: [] ) as $a ) {
			$parts = [];
			foreach ( $key_fields as $kf ) {
				$parts[] = $norm( $a[ $kf ] ?? '' );
			}
			$key = md5( implode( '|', $parts ) );
			if ( isset( $seen[ $key ] ) ) continue;
			$seen[ $key ] = true;
			$out[] = $a;
		}
	}
	return $out;
}

/**
 * Fusionne un repeater SCF d'avis (avis_des_lecteurs / avis_des_libraires) sur
 * plusieurs produits, en dédupliquant les entrées identiques (titre+auteur+avis).
 */
function passiflore_collect_avis_scf( array $product_ids, string $field ): array {
	return passiflore_collect_group_repeater( $product_ids, $field, [ 'titre', 'auteur', 'avis' ] );
}

/**
 * Display name (Prénom Nom) for an auteur term.
 */
function passiflore_auteur_display_name( int $term_id ): string {
	$prenom = (string) get_field( 'prenom', 'auteur_' . $term_id );
	$nom    = (string) get_field( 'nom',    'auteur_' . $term_id );
	$name   = trim( $prenom . ' ' . $nom );
	if ( $name ) return $name;
	$term = get_term( $term_id, 'auteur' );
	return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
}

/**
 * Join an array of names in French: "A", "A et B", "A, B et C".
 */
function passiflore_join_auteur_names( array $names ): string {
	if ( empty( $names ) ) return '';
	if ( count( $names ) === 1 ) return $names[0];
	$last = array_pop( $names );
	return implode( ', ', $names ) . ' et ' . $last;
}

/**
 * Exact author term IDs for a product (contributions of type=auteur, assignation=fiche-auteur).
 * Returns a sorted array of unique integer term IDs.
 */
function passiflore_get_product_author_ids( int $post_id ): array {
	$term_ids      = [];
	$contributions = get_field( 'contributions', $post_id );
	if ( ! is_array( $contributions ) ) return [];
	foreach ( $contributions as $row ) {
		if ( ( $row['type'] ?? '' ) !== 'auteur' ) continue;
		if ( ( $row['assignation'] ?? '' ) !== 'fiche-auteur' ) continue;
		foreach ( (array) ( $row['fiche-auteur'] ?? [] ) as $item ) {
			$tid = is_object( $item ) ? (int) $item->term_id : absint( $item );
			if ( $tid ) $term_ids[] = $tid;
		}
	}
	$term_ids = array_values( array_unique( $term_ids ) );
	sort( $term_ids );
	return $term_ids;
}

/**
 * Product IDs whose contributions (type=auteur) reference one of the given auteur term IDs.
 * Mirrors Passiflore_Bookshelf::get_product_ids_by_auteur() — handles the three storage
 * shapes SCF uses for multi_select taxonomy fields.
 */
function passiflore_product_ids_by_auteur_terms( array $term_ids ): array {
	global $wpdb;
	$term_ids = array_values( array_unique( array_filter( array_map( 'intval', $term_ids ) ) ) );
	if ( empty( $term_ids ) ) return [];

	$or_clauses = [];
	$params     = [ 'contributions_%_fiche-auteur' ];
	foreach ( $term_ids as $tid ) {
		$or_clauses[] = '(a.meta_value = %d OR a.meta_value LIKE %s OR a.meta_value LIKE %s)';
		$params[]     = $tid;
		$params[]     = '%"' . $tid . '"%';
		$params[]     = '%i:' . $tid . ';%';
	}

	$sql  = "SELECT DISTINCT a.post_id FROM {$wpdb->postmeta} a";
	$sql .= " INNER JOIN {$wpdb->postmeta} t ON t.post_id = a.post_id AND t.meta_key = REPLACE( a.meta_key, '_fiche-auteur', '_type' )";
	$sql .= " WHERE a.meta_key LIKE %s AND ( " . implode( ' OR ', $or_clauses ) . " ) AND t.meta_value = 'auteur'";

	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
}

/**
 * Gender-accorded label for the "same authors" group.
 */
function passiflore_meme_auteur_label( array $term_ids ): string {
	$all_feminin = true;
	foreach ( $term_ids as $tid ) {
		$genre = get_field( 'genre', 'auteur_' . $tid );
		if ( ! in_array( $genre, [ 'f', 'feminin', 'Féminin' ], true ) ) {
			$all_feminin = false;
			break;
		}
	}
	if ( count( $term_ids ) === 1 ) {
		return $all_feminin ? 'De la même autrice' : 'Du même auteur';
	}
	return $all_feminin ? 'Des mêmes autrices' : 'Des mêmes auteurs';
}

/**
 * Group candidate product IDs by their exact author fingerprint, relative to a reference set.
 *
 * Each returned group:
 *   'fingerprint'  => int[]  — sorted author term IDs of the group
 *   'product_ids'  => int[]  — products in this group
 *   'known_ids'    => int[]  — fingerprint ∩ reference_ids
 *   'external_ids' => int[]  — fingerprint \ reference_ids
 *
 * Order (phase 1 before phase 2):
 *   Phase 1 — multiple known authors:
 *     P1  all known, no external      ("Des mêmes auteurs")
 *     P2  all known, with external
 *     P3  2+ known subset, no external
 *     P4  2+ known subset, with external
 *   Phase 2 — single known author, grouped by author position in reference_ids:
 *     P5  1 known, no external        ("[name]" solo)
 *     P6  1 known, with external      ("[name] avec …")
 */
function passiflore_group_books_by_author_set( array $reference_ids, array $candidate_ids ): array {
	if ( empty( $candidate_ids ) || empty( $reference_ids ) ) return [];

	$reference_set = array_values( array_unique( array_map( 'intval', $reference_ids ) ) );
	sort( $reference_set );
	$ref_count = count( $reference_set );

	$raw = [];
	foreach ( $candidate_ids as $pid ) {
		$author_ids = passiflore_get_product_author_ids( (int) $pid );
		if ( empty( $author_ids ) ) continue;
		$key = implode( ',', $author_ids );
		if ( ! isset( $raw[ $key ] ) ) {
			$known    = array_values( array_intersect( $author_ids, $reference_set ) );
			$external = array_values( array_diff( $author_ids, $reference_set ) );
			$raw[ $key ] = [
				'fingerprint'  => $author_ids,
				'product_ids'  => [],
				'known_ids'    => $known,
				'external_ids' => $external,
			];
		}
		$raw[ $key ]['product_ids'][] = (int) $pid;
	}

	if ( empty( $raw ) ) return [];

	foreach ( $raw as &$g ) {
		$k    = count( $g['known_ids'] );
		$e    = count( $g['external_ids'] );
		$full = ( $k === $ref_count );

		if ( $full && $e === 0 )         $g['_p'] = 1;
		elseif ( $full )                 $g['_p'] = 2;
		elseif ( $k >= 2 && $e === 0 )   $g['_p'] = 3;
		elseif ( $k >= 2 )               $g['_p'] = 4;
		elseif ( $e === 0 )              $g['_p'] = 5;
		else                             $g['_p'] = 6;

		$g['_kpos'] = ( $k === 1 )
			? (int) array_search( $g['known_ids'][0], $reference_set, true )
			: 0;
	}
	unset( $g );

	usort( $raw, function ( $a, $b ) {
		$pa = $a['_p']; $pb = $b['_p'];
		$pha = $pa <= 4 ? 1 : 2;
		$phb = $pb <= 4 ? 1 : 2;
		if ( $pha !== $phb ) return $pha - $phb;
		if ( $pha === 1 ) {
			if ( $pa !== $pb ) return $pa - $pb;
			return count( $b['known_ids'] ) - count( $a['known_ids'] );
		}
		// Phase 2: group by known author position, then solo (P5) before external (P6)
		if ( $a['_kpos'] !== $b['_kpos'] ) return $a['_kpos'] - $b['_kpos'];
		return $pa - $pb;
	} );

	return array_map( function ( $g ) {
		unset( $g['_p'], $g['_kpos'] );
		return $g;
	}, $raw );
}

/**
 * Human-readable title for a group, relative to a reference author set.
 *
 * @param array  $group         One element from passiflore_group_books_by_author_set()
 * @param array  $reference_ids The reference author term IDs
 * @param string $context       'book' or 'auteur'
 */
function passiflore_author_group_title( array $group, array $reference_ids, string $context = 'book' ): string {
	$known     = $group['known_ids'];
	$external  = $group['external_ids'];
	$ref_count = count( $reference_ids );

	$known_names    = array_map( 'passiflore_auteur_display_name', $known );
	$external_names = array_map( 'passiflore_auteur_display_name', $external );
	$known_str      = passiflore_join_auteur_names( $known_names );
	$external_str   = passiflore_join_auteur_names( $external_names );

	if ( $context === 'auteur' ) {
		return empty( $external )
			? 'Ouvrages'
			: 'Ouvrages avec ' . $external_str;
	}

	// Book context
	$is_full = ( count( $known ) === $ref_count );

	if ( $is_full ) {
		$base = passiflore_meme_auteur_label( $reference_ids );
		return empty( $external ) ? $base : $base . ' avec ' . $external_str;
	}

	if ( count( $known ) === 1 && empty( $external ) ) {
		return $known_names[0];
	}

	if ( count( $known ) === 1 ) {
		return $known_names[0] . ' avec ' . $external_str;
	}

	return empty( $external )
		? $known_str
		: $known_str . ' avec ' . $external_str;
}
