<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Incrémenter à chaque évolution du schéma d'index (nouveaux champs, nouvelles
// entités…). Sur le premier chargement admin suivant un déploiement, le hook
// admin_init détecte la divergence et relance pf_search_reindex_all().
define( 'PF_SEARCH_INDEX_VERSION', 3 );

// Nombre minimal de chiffres consécutifs pour qu'une requête numérique soit
// traitée comme un ISBN (partiel ou complet) plutôt qu'ignorée.
define( 'PF_SEARCH_ISBN_MIN_DIGITS', 6 );

/**
 * Cœur de recherche partagé — Éditions Passiflore
 * ------------------------------------------------
 * Tous les champs de recherche custom (recherche globale, catalogue, auteurs,
 * méta-box événements, pickers admin) passent par ce module pour un
 * comportement homogène :
 *
 *   • insensible à la casse           (normalisation minuscules)
 *   • insensible aux accents/ligatures (remove_accents : é→e, œ→oe, ç→c…)
 *   • insensible aux caractères spéciaux (apostrophes, traits d'union,
 *     ponctuation → tout devient des séparateurs de tokens)
 *   • tolérant aux fautes de frappe   (distance de Levenshtein par token)
 *
 * Stratégie : on précalcule un index de recherche normalisé par entité
 * (produit, terme auteur, événement) stocké en meta, et on filtre/range en PHP.
 * Le catalogue étant petit (quelques centaines d'items), le scoring en PHP est
 * négligeable et évite toute dépendance externe.
 *
 * Le pendant JS de pf_search_normalize()/scoring vit dans book-picker.js pour
 * les pickers admin à liste préchargée.
 */

/* ─────────────────────────────────────────────────────────────────────────
 * 1. Normalisation
 * ──────────────────────────────────────────────────────────────────────── */

/**
 * Réduit une chaîne à une suite de tokens alphanumériques en minuscules, sans
 * accents ni ponctuation. Ex. « L'Œuvre d'Émile Zola » → « l oeuvre d emile zola ».
 */
function pf_search_normalize( $s ) {
	$s = (string) $s;
	if ( $s === '' ) return '';
	$s = remove_accents( $s );                 // é→e, œ→oe, æ→ae, ç→c, …
	$s = strtolower( $s );
	$s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
	return trim( preg_replace( '/\s+/', ' ', $s ) );
}

/**
 * Seuil de Levenshtein toléré pour un token de recherche, selon sa longueur.
 * Les tokens courts ne tolèrent aucune faute (trop de faux positifs).
 */
function pf_search_threshold( $token ) {
	$n = strlen( $token );
	if ( $n < 4 ) return 0;
	if ( $n < 7 ) return 1;
	return 2;
}

/* ─────────────────────────────────────────────────────────────────────────
 * 2. Scoring
 * ──────────────────────────────────────────────────────────────────────── */

/**
 * Score de pertinence d'un texte normalisé vis-à-vis d'une requête normalisée.
 * 0 = pas de correspondance. Plus le score est haut, plus c'est pertinent.
 *
 * Paliers (du plus fort au plus faible) :
 *   - la requête entière (espaces retirés) est une sous-chaîne du texte
 *     → match « collé », gère les caractères spéciaux (« lhomme » → « l'homme »)
 *   - sinon, CHAQUE token de la requête doit matcher un token du texte
 *     (préfixe > sous-chaîne > Levenshtein) ; un token sans match → rejet.
 */
function pf_search_score( $query_norm, $text_norm ) {
	if ( $query_norm === '' || $text_norm === '' ) return 0;

	$qjoin = str_replace( ' ', '', $query_norm );
	$tjoin = str_replace( ' ', '', $text_norm );

	// Palier 1 : requête entière collée présente telle quelle.
	$pos = strpos( $tjoin, $qjoin );
	if ( $pos !== false ) {
		return 1000 + ( $pos === 0 ? 500 : 0 );
	}

	// Palier 2 : correspondance token par token (AND).
	$qtoks = explode( ' ', $query_norm );
	$ttoks = explode( ' ', $text_norm );
	$total = 0;

	foreach ( $qtoks as $qt ) {
		$best = 0;
		foreach ( $ttoks as $tt ) {
			if ( $tt === $qt ) {
				$best = 100;
				break;
			}
			$p = strpos( $tt, $qt );
			if ( $p === 0 )            $best = max( $best, 80 ); // préfixe de token
			elseif ( $p !== false )    $best = max( $best, 60 ); // sous-chaîne de token
		}
		if ( $best === 0 ) {
			// Repli flou : faute de frappe tolérée selon la longueur du token.
			$thr = pf_search_threshold( $qt );
			if ( $thr > 0 ) {
				foreach ( $ttoks as $tt ) {
					if ( abs( strlen( $tt ) - strlen( $qt ) ) > $thr ) continue;
					$d = levenshtein( $qt, $tt );
					if ( $d <= $thr ) $best = max( $best, 40 - $d * 10 );
				}
			}
		}
		if ( $best === 0 ) return 0; // un token de la requête sans aucun match → rejet
		$total += $best;
	}

	return $total;
}

/**
 * Filtre + range un pool [ id => texte_normalisé ] selon la requête.
 * Renvoie un tableau d'IDs (int) ordonnés par score décroissant, l'ordre
 * d'origine du pool départageant les ex æquo.
 */
function pf_search_filter_pool( $query, array $pool ) {
	$qn = pf_search_normalize( $query );
	if ( $qn === '' ) return [];

	$scored = [];
	$i      = 0;
	foreach ( $pool as $id => $text_norm ) {
		$s = pf_search_score( $qn, (string) $text_norm );
		if ( $s > 0 ) $scored[] = [ (int) $id, $s, $i ];
		$i++;
	}
	usort( $scored, function ( $a, $b ) {
		if ( $a[1] !== $b[1] ) return $b[1] - $a[1]; // score DESC
		return $a[2] - $b[2];                        // ordre du pool en cas d'égalité
	} );

	return array_map( function ( $r ) { return $r[0]; }, $scored );
}

/**
 * Comme pf_search_filter_pool() mais retourne [ id => score ] au lieu d'un
 * tableau d'IDs trié — utile quand on veut fusionner plusieurs pools et
 * appliquer un tri secondaire (par date, etc.).
 */
function pf_search_score_pool( $query, array $pool ) {
	$qn = pf_search_normalize( $query );
	if ( $qn === '' ) return [];
	$scored = [];
	foreach ( $pool as $id => $text_norm ) {
		$s = pf_search_score( $qn, (string) $text_norm );
		if ( $s > 0 ) $scored[ (int) $id ] = $s;
	}
	return $scored;
}

/* ─────────────────────────────────────────────────────────────────────────
 * 3. Construction des index normalisés
 * ──────────────────────────────────────────────────────────────────────── */

/**
 * Texte brut d'un produit pour l'index « complet » : titre + sous-titre +
 * noms d'auteurs (fiche-auteur ET texte libre) + nom de plume + illustrateur
 * de couverture + distinctions + étiquettes.
 *
 * L'ISBN (_global_unique_id) n'est volontairement PAS inclus ici : il est
 * cherché à part, par égalité exacte, dans pf_search_products_ranked() — le
 * moteur flou par tokens de ce fichier est pensé pour du texte, pas pour des
 * codes numériques (ses segments courts génèrent des faux positifs massifs
 * en cas de recherche ISBN, cf. commentaire sur pf_search_products_ranked()).
 */
function pf_search_product_text( $post_id ) {
	$parts = [
		get_post_field( 'post_title', $post_id ),
		(string) get_field( 'sous-titre', $post_id ),
		(string) get_field( 'nom_de_plume', $post_id ),
		(string) get_field( 'illustration_de_couverture', $post_id ),
	];

	$contribs = get_field( 'contributions', $post_id );
	if ( is_array( $contribs ) ) {
		foreach ( $contribs as $row ) {
			if ( ( $row['assignation'] ?? '' ) === 'fiche-auteur' ) {
				foreach ( (array) ( $row['fiche-auteur'] ?? [] ) as $item ) {
					$tid = is_object( $item ) ? (int) $item->term_id : absint( $item );
					if ( $tid ) $parts[] = passiflore_auteur_display_name( $tid );
				}
			} else {
				// Champ texte libre — la clé contient une apostrophe typographique
				// (U+2019) ; on couvre aussi la variante ASCII par précaution.
				$parts[] = (string) ( $row[ "nom_de_l\xE2\x80\x99auteur" ] ?? ( $row["nom_de_l'auteur"] ?? '' ) );
			}
		}
	}

	$distinctions = get_field( 'distinctions', $post_id );
	if ( is_array( $distinctions ) ) {
		foreach ( $distinctions as $row ) {
			if ( ! empty( $row['distinction'] ) ) $parts[] = (string) $row['distinction'];
		}
	}

	$tags = get_the_terms( $post_id, 'product_tag' );
	if ( is_array( $tags ) ) {
		foreach ( $tags as $t ) $parts[] = $t->name;
	}

	return implode( ' ', array_filter( array_map( 'trim', $parts ) ) );
}

/**
 * (Re)construit les deux index d'un produit :
 *   _pf_search_title — titre + sous-titre (sert au rang prioritaire « titre »)
 *   _pf_search_index — texte complet (titre + auteurs + étiquettes…)
 */
function pf_search_index_product( $post_id ) {
	$post_id = (int) $post_id;
	if ( get_post_type( $post_id ) !== 'product' ) return;

	$title = get_post_field( 'post_title', $post_id ) . ' ' . (string) get_field( 'sous-titre', $post_id );
	update_post_meta( $post_id, '_pf_search_title', pf_search_normalize( $title ) );
	update_post_meta( $post_id, '_pf_search_index', pf_search_normalize( pf_search_product_text( $post_id ) ) );
}

/**
 * Index d'un événement : titre + extrait + participants + titres des livres
 * associés. Les événements marquants reçoivent en plus _pf_search_title
 * (comme les livres) pour être remontés en priorité lors du scoring.
 */
function pf_search_index_event( $post_id ) {
	$post_id = (int) $post_id;
	if ( get_post_type( $post_id ) !== 'tribe_events' ) return;

	$title = get_post_field( 'post_title', $post_id );
	$parts = [ $title, get_post_field( 'post_excerpt', $post_id ) ];

	$participants = get_field( "personnes_participant_a_l'evenement", $post_id );
	if ( is_array( $participants ) ) {
		foreach ( $participants as $row ) {
			if ( ( $row['assignation'] ?? '' ) === 'fiche-auteur' ) {
				foreach ( (array) ( $row['fiche_auteur'] ?? [] ) as $item ) {
					$tid = is_object( $item ) ? (int) $item->term_id : absint( $item );
					if ( $tid ) $parts[] = passiflore_auteur_display_name( $tid );
				}
			} else {
				$parts[] = (string) ( $row['nom_de_la_personne'] ?? '' );
			}
		}
	}

	$book_ids = get_post_meta( $post_id, '_pf_event_books', true );
	if ( is_array( $book_ids ) ) {
		foreach ( $book_ids as $bid ) {
			$parts[] = get_post_field( 'post_title', (int) $bid );
		}
	}

	// Organisateur TEC (meta _OrganizerID, post type tribe_organizer)
	foreach ( (array) get_post_meta( $post_id, '_OrganizerID', false ) as $oid ) {
		if ( $oid ) $parts[] = get_post_field( 'post_title', (int) $oid );
	}

	// Lieu TEC (meta _EventVenueID, titre + adresse + ville + département +
	// région du post tribe_venue — département/région sont des champs
	// Passiflore ajoutés au formulaire lieu, cf. inc/venue-admin.php)
	$venue_id = (int) get_post_meta( $post_id, '_EventVenueID', true );
	if ( $venue_id ) {
		$parts[] = get_post_field( 'post_title', $venue_id );
		$parts[] = (string) get_post_meta( $venue_id, '_VenueAddress', true );
		$parts[] = (string) get_post_meta( $venue_id, '_VenueCity', true );
		$parts[] = (string) get_post_meta( $venue_id, '_VenueDepartement', true );
		$parts[] = (string) get_post_meta( $venue_id, '_VenueRegion', true );
	}

	$text = mb_substr( implode( ' ', array_filter( array_map( 'trim', $parts ) ) ), 0, 2000 );
	update_post_meta( $post_id, '_pf_search_index', pf_search_normalize( $text ) );

	if ( get_field( 'evenement_marquant', $post_id ) ) {
		update_post_meta( $post_id, '_pf_search_title', pf_search_normalize( $title ) );
	} else {
		delete_post_meta( $post_id, '_pf_search_title' );
	}
}

/**
 * Index d'un terme auteur : nom affiché + prénom + nom + nom du terme
 * + titres des livres où cet auteur apparaît en contribution (fiche-auteur).
 * Le cache statique $contrib_book_ids évite de re-exécuter la requête SQL pour
 * chaque auteur lors d'un reindex complet.
 */
function pf_search_index_auteur( $term_id ) {
	$term_id = (int) $term_id;
	$parts   = [
		passiflore_auteur_display_name( $term_id ),
		(string) get_field( 'prenom', 'auteur_' . $term_id ),
		(string) get_field( 'nom',    'auteur_' . $term_id ),
	];
	$term = get_term( $term_id, 'auteur' );
	if ( $term && ! is_wp_error( $term ) ) $parts[] = $term->name;

	global $wpdb;
	static $contrib_book_ids = null;
	if ( $contrib_book_ids === null ) {
		$contrib_book_ids = $wpdb->get_col(
			"SELECT DISTINCT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'
			 AND pm.meta_key LIKE 'contributions_%_fiche-auteur'"
		);
	}
	foreach ( $contrib_book_ids as $bid ) {
		$contribs = get_field( 'contributions', (int) $bid );
		if ( ! is_array( $contribs ) ) continue;
		foreach ( $contribs as $row ) {
			if ( ( $row['assignation'] ?? '' ) !== 'fiche-auteur' ) continue;
			foreach ( (array) ( $row['fiche-auteur'] ?? [] ) as $item ) {
				$tid = is_object( $item ) ? (int) $item->term_id : absint( $item );
				if ( $tid === $term_id ) {
					$parts[] = get_post_field( 'post_title', (int) $bid );
					break;
				}
			}
		}
	}

	update_term_meta( $term_id, '_pf_search_index', pf_search_normalize( implode( ' ', array_filter( $parts ) ) ) );
}

/**
 * Reconstruit l'index de tous les auteurs qui apparaissent dans les
 * contributions d'un produit donné. Appelé après chaque sauvegarde produit
 * pour que les index auteurs reflètent les titres de livres mis à jour.
 */
function pf_search_reindex_product_authors( $post_id ) {
	$contribs = get_field( 'contributions', $post_id );
	if ( ! is_array( $contribs ) ) return;
	$seen = [];
	foreach ( $contribs as $row ) {
		if ( ( $row['assignation'] ?? '' ) !== 'fiche-auteur' ) continue;
		foreach ( (array) ( $row['fiche-auteur'] ?? [] ) as $item ) {
			$tid = is_object( $item ) ? (int) $item->term_id : absint( $item );
			if ( $tid && ! isset( $seen[ $tid ] ) ) {
				$seen[ $tid ] = true;
				pf_search_index_auteur( $tid );
			}
		}
	}
}

/**
 * Reconstruit l'index de tous les livres où un auteur apparaît en contribution.
 * Appelé après chaque modification d'auteur pour que les index livres reflètent
 * le nouveau nom d'auteur.
 */
function pf_search_reindex_author_books( $term_id ) {
	global $wpdb;
	$term_id  = (int) $term_id;
	$book_ids = $wpdb->get_col(
		"SELECT DISTINCT pm.post_id
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE p.post_type = 'product' AND p.post_status = 'publish'
		 AND pm.meta_key LIKE 'contributions_%_fiche-auteur'"
	);
	foreach ( $book_ids as $bid ) {
		$contribs = get_field( 'contributions', (int) $bid );
		if ( ! is_array( $contribs ) ) continue;
		foreach ( $contribs as $row ) {
			if ( ( $row['assignation'] ?? '' ) !== 'fiche-auteur' ) continue;
			foreach ( (array) ( $row['fiche-auteur'] ?? [] ) as $item ) {
				$tid = is_object( $item ) ? (int) $item->term_id : absint( $item );
				if ( $tid === $term_id ) {
					pf_search_index_product( (int) $bid );
					break;
				}
			}
		}
	}
}

/* ─────────────────────────────────────────────────────────────────────────
 * 4. Hooks de maintenance de l'index
 * ──────────────────────────────────────────────────────────────────────── */

// SCF/ACF : capture la sauvegarde des champs (sous-titre, contributions, fiche auteur…).
add_action( 'acf/save_post', function ( $post_id ) {
	if ( is_numeric( $post_id ) ) {
		$pt = get_post_type( $post_id );
		if ( $pt === 'product' ) {
			pf_search_index_product( $post_id );
			pf_search_reindex_product_authors( $post_id );
		} elseif ( $pt === 'tribe_events' ) {
			pf_search_index_event( $post_id );
		}
	} elseif ( is_string( $post_id ) && strpos( $post_id, 'auteur_' ) === 0 ) {
		$term_id = (int) substr( $post_id, 7 );
		pf_search_index_auteur( $term_id );
		pf_search_reindex_author_books( $term_id );
	}
}, 20 );

// Sauvegarde post standard (titre modifié hors SCF, import, quick edit…).
add_action( 'save_post_product', function ( $id ) {
	if ( ! wp_is_post_revision( $id ) ) {
		pf_search_index_product( $id );
		pf_search_reindex_product_authors( $id );
	}
}, 20 );
add_action( 'save_post_tribe_events', function ( $id ) {
	if ( ! wp_is_post_revision( $id ) ) pf_search_index_event( $id );
}, 20 );

// Terme auteur édité/créé hors SCF.
add_action( 'edited_auteur', function ( $term_id ) {
	pf_search_index_auteur( $term_id );
	pf_search_reindex_author_books( $term_id );
}, 20 );
add_action( 'created_auteur', 'pf_search_index_auteur', 20 );

// Reindex automatique sur premier chargement admin après un changement de schéma.
add_action( 'admin_init', function () {
	if ( (int) get_option( 'pf_search_index_version', 0 ) !== PF_SEARCH_INDEX_VERSION ) {
		pf_search_reindex_all();
	}
} );

/**
 * Reconstruit tous les index et enregistre la version courante du schéma.
 * Déclenché automatiquement par admin_init si la version stockée diverge de
 * PF_SEARCH_INDEX_VERSION ; peut aussi être appelé manuellement via WP-CLI :
 *   wp eval 'echo pf_search_reindex_all() . " entités réindexées.\n";'
 */
function pf_search_reindex_all() {
	global $wpdb;
	$count = 0;

	// IDs récupérés en SQL direct : évite les filtres de requête de tiers
	// (The Events Calendar restreint get_posts('tribe_events') aux à-venir).
	foreach ( [ 'product', 'tribe_events' ] as $pt ) {
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			$pt
		) );
		$fn = $pt === 'product' ? 'pf_search_index_product' : 'pf_search_index_event';
		foreach ( $ids as $id ) { $fn( (int) $id ); $count++; }
	}

	foreach ( get_terms( [ 'taxonomy' => 'auteur', 'hide_empty' => false, 'fields' => 'ids' ] ) as $tid ) {
		pf_search_index_auteur( $tid ); $count++;
	}

	update_option( 'pf_search_index_version', PF_SEARCH_INDEX_VERSION );
	return $count;
}

/* ─────────────────────────────────────────────────────────────────────────
 * 5. Chargement des pools de recherche
 * ──────────────────────────────────────────────────────────────────────── */

/**
 * Pool [ post_id => index ] pour un meta_key donné, restreint aux posts publiés
 * d'un type. Ex. pf_search_pool_posts( '_pf_search_index', 'product' ).
 */
function pf_search_pool_posts( $meta_key, $post_type ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT pm.post_id AS id, pm.meta_value AS v
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = 'publish'",
		$meta_key, $post_type
	) );
	$pool = [];
	foreach ( $rows as $r ) $pool[ (int) $r->id ] = $r->v;
	return $pool;
}

/**
 * Pool [ term_id => index ] des termes auteur.
 */
function pf_search_pool_auteurs() {
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT tm.term_id AS id, tm.meta_value AS v
		 FROM {$wpdb->termmeta} tm
		 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
		 WHERE tm.meta_key = '_pf_search_index' AND tt.taxonomy = 'auteur'"
	);
	$pool = [];
	foreach ( $rows as $r ) $pool[ (int) $r->id ] = $r->v;
	return $pool;
}

/**
 * Pool [ post_id => isbn_chiffres ] des produits ayant un ISBN renseigné
 * (_global_unique_id, natif WooCommerce), valeur réduite à ses seuls chiffres.
 */
function pf_search_pool_isbn() {
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT pm.post_id AS id, pm.meta_value AS v
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key = '_global_unique_id' AND pm.meta_value != ''
		 AND p.post_type = 'product' AND p.post_status = 'publish'"
	);
	$pool = [];
	foreach ( $rows as $r ) $pool[ (int) $r->id ] = preg_replace( '/\D+/', '', $r->v );
	return $pool;
}

/**
 * IDs d'événements (tribe_events publiés) associés à au moins un des produits
 * donnés, via le meta _pf_event_books (tableau d'IDs géré par event-admin.php
 * — pas un champ SCF). Même scan que passiflore_get_product_events()
 * (book-single-tabs.php), mais pour plusieurs produits à la fois et sans le
 * regroupement par format_groupe ni le filtre passé/marquant (spécifiques à
 * l'affichage sur la fiche livre).
 */
function pf_search_events_for_products( array $product_ids ) {
	if ( empty( $product_ids ) || ! post_type_exists( 'tribe_events' ) ) return [];

	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT p.ID, pm.meta_value
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_pf_event_books'
		 WHERE p.post_type = 'tribe_events' AND p.post_status = 'publish'"
	);

	$wanted = array_map( 'intval', $product_ids );
	$ids    = [];
	foreach ( $rows as $row ) {
		$books = maybe_unserialize( $row->meta_value );
		if ( is_array( $books ) && array_intersect( array_map( 'intval', $books ), $wanted ) ) {
			$ids[] = (int) $row->ID;
		}
	}
	return $ids;
}

/**
 * Détecte une requête « forme ISBN » (chiffres, espaces, tirets uniquement —
 * ex. ISBN complet ou tronqué, avec ou sans tirets) et renvoie les IDs de
 * produits dont l'ISBN (_global_unique_id) contient cette suite de chiffres,
 * triés égalité exacte puis préfixe puis sous-chaîne. Renvoie null si la
 * requête n'a pas cette forme (laisse la main au moteur flou de
 * pf_search_products_ranked()).
 *
 * Match par égalité/sous-chaîne uniquement, sans tolérance de faute de frappe :
 * le moteur flou par tokens plus bas est pensé pour du texte, pas pour des
 * codes numériques — ses segments courts (« 978 », « 2 », « 9 »…) et son repli
 * Levenshtein y génèrent des faux positifs massifs sur un ISBN (testé : ~90
 * livres sans rapport pour une seule recherche ISBN formatée).
 */
function pf_search_products_by_isbn( $query ) {
	$trimmed = trim( (string) $query );
	if ( $trimmed === '' || ! preg_match( '/^[\d\s\-]+$/', $trimmed ) ) return null;

	$digits = preg_replace( '/\D+/', '', $trimmed );
	if ( strlen( $digits ) < PF_SEARCH_ISBN_MIN_DIGITS ) return null;

	$exact = $prefix = $contains = [];
	foreach ( pf_search_pool_isbn() as $id => $isbn_digits ) {
		if ( $isbn_digits === '' ) continue;
		if ( $isbn_digits === $digits ) { $exact[] = $id; continue; }
		$pos = strpos( $isbn_digits, $digits );
		if ( $pos === false ) continue;
		if ( $pos === 0 ) $prefix[] = $id; else $contains[] = $id;
	}
	return array_merge( $exact, $prefix, $contains );
}

/**
 * Raccourci : IDs de produits matchant la requête, rangés titre puis complet.
 * Réutilisé par le catalogue, la recherche globale et la méta-box événements.
 */
function pf_search_products_ranked( $query ) {
	$isbn_ids = pf_search_products_by_isbn( $query );
	if ( $isbn_ids !== null ) return $isbn_ids;

	$title = pf_search_filter_pool( $query, pf_search_pool_posts( '_pf_search_title', 'product' ) );
	$full  = pf_search_filter_pool( $query, pf_search_pool_posts( '_pf_search_index', 'product' ) );

	$seen    = [];
	$ordered = [];
	foreach ( [ $title, $full ] as $bucket ) {
		foreach ( $bucket as $id ) {
			if ( ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$ordered[]   = $id;
			}
		}
	}
	return $ordered;
}

/**
 * IDs d'événements (tribe_events publiés) matchant la requête, rangés à
 * venir d'abord (le plus proche en premier) puis passés (le plus récent en
 * premier) — pas de filtre « marquant » : un événement passé quelconque
 * reste trouvable ici comme il l'est déjà via le bouton « charger les passés »
 * de la page /evenements. Réutilisé par la recherche globale (Passiflore_Recherche_Globale)
 * et par la recherche de la page /evenements (Passiflore_Events_Search).
 *
 * @param string $query
 * @param int[]  $extra_ids     IDs à inclure même sans correspondance texte
 *                              (ex. événements liés à des livres déjà trouvés
 *                              par ISBN — voir Passiflore_Recherche_Globale::search_evenements()).
 * @param bool   $upcoming_only Si vrai, exclut les événements déjà passés
 *                              (utilisé par la recherche globale — la
 *                              recherche locale /evenements garde les passés).
 */
function pf_search_events_ranked( $query, array $extra_ids = [], $upcoming_only = false ) {
	if ( ! post_type_exists( 'tribe_events' ) ) return [];

	$title_scores = pf_search_score_pool( $query, pf_search_pool_posts( '_pf_search_title', 'tribe_events' ) );
	$full_scores  = pf_search_score_pool( $query, pf_search_pool_posts( '_pf_search_index', 'tribe_events' ) );

	$merged = [];
	foreach ( $full_scores as $id => $s ) {
		$merged[ $id ] = $s + ( $title_scores[ $id ] ?? 0 );
	}
	foreach ( $title_scores as $id => $s ) {
		if ( ! isset( $merged[ $id ] ) ) $merged[ $id ] = $s;
	}
	foreach ( $extra_ids as $id ) {
		$id = (int) $id;
		if ( $id && ! isset( $merged[ $id ] ) ) $merged[ $id ] = 1;
	}
	if ( empty( $merged ) ) return [];

	global $wpdb;
	$ids_in     = implode( ',', array_map( 'intval', array_keys( $merged ) ) );
	$start_rows = $wpdb->get_results(
		"SELECT post_id, meta_value FROM {$wpdb->postmeta}
		 WHERE meta_key = '_EventStartDate' AND post_id IN ($ids_in)"
	);
	$start_strs = [];
	foreach ( $start_rows as $r ) $start_strs[ (int) $r->post_id ] = $r->meta_value;

	$now = current_time( 'timestamp' );
	$ids = array_keys( $merged );

	if ( $upcoming_only ) {
		$ids = array_values( array_filter( $ids, function ( $id ) use ( $start_strs, $now ) {
			return isset( $start_strs[ $id ] ) && strtotime( $start_strs[ $id ] ) >= $now;
		} ) );
	}

	usort( $ids, function ( $a, $b ) use ( $start_strs, $now ) {
		$ta = isset( $start_strs[ $a ] ) ? strtotime( $start_strs[ $a ] ) : 0;
		$tb = isset( $start_strs[ $b ] ) ? strtotime( $start_strs[ $b ] ) : 0;
		$fa = $ta >= $now; $fb = $tb >= $now;
		if ( $fa !== $fb ) return $fa ? -1 : 1;
		return abs( $ta - $now ) - abs( $tb - $now );
	} );

	return $ids;
}
