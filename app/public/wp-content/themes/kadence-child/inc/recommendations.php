<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Espace « Mon compte » — suggestions de livres + agencement du menu.
 *
 * Moteur de recommandation (lecture seule, sans état → procédural pf_reco_*) :
 * croise les achats du client et sa liste de lecture, agrège les œuvres liées
 * (vous aimerez aussi, série, traductions, même auteur, mêmes mots-clés/étiquettes),
 * classe par nombre de
 * « graines » contributrices, exclut le déjà-possédé, et rend l'étagère 3D avec
 * une explication par livre (« Parce que vous avez commandé… »).
 *
 * Réutilise les helpers existants :
 *  - pf_bg_representative() / pf_bg_dedup() / pf_bg_group_member_reps()  (book-groups-admin.php)
 *  - passiflore_get_product_author_ids() / passiflore_product_ids_by_auteur_terms()  (author-books-grouping.php)
 *  - Passiflore_Reading_List::get_ids()  (class-reading-list.php)
 *  - [passiflore_etagere] + Passiflore_Bookshelf::set_reco_annotations()  (class-bookshelf.php)
 *
 * Pas de cache : le calcul n'a lieu que lorsqu'un client connecté ouvre son
 * tableau de bord ou sa liste de lecture (faible fréquence, jamais pour un
 * invité). Une transient imposerait une invalidation transverse (commande,
 * toggle liste, édition des relations) hors de proportion. Si un profilage le
 * justifiait, une transient courte SANS invalidation (la fraîcheur des recos
 * étant sans enjeu) suffirait.
 */

/* ═══════════════════════════════════════════════════════════════
   Moteur de recommandation
   ═══════════════════════════════════════════════════════════════ */

/**
 * Représentants d'œuvre des livres achetés par le client (commandes payées).
 *
 * @return int[]
 */
function pf_reco_purchased_ids( int $user_id ): array {
	if ( ! $user_id || ! function_exists( 'wc_get_orders' ) ) {
		return [];
	}
	$order_ids = wc_get_orders( [
		'customer_id' => $user_id,
		'status'      => wc_get_is_paid_statuses(),
		'limit'       => -1,
		'return'      => 'ids',
	] );

	$product_ids = [];
	foreach ( $order_ids as $oid ) {
		$order = wc_get_order( $oid );
		if ( ! $order ) {
			continue;
		}
		foreach ( $order->get_items() as $item ) {
			$pid = (int) $item->get_product_id();
			if ( $pid ) {
				$product_ids[] = $pid;
			}
		}
	}
	return array_values( array_unique( $product_ids ) );
}

/**
 * Graines de recommandation, avec leur provenance.
 * Achats et liste de lecture, ramenés au représentant d'œuvre. Une œuvre à la
 * fois achetée et en liste est marquée « achat » (provenance la plus forte).
 *
 * @return array<int,string> [ rep_id => 'achat'|'liste' ]
 */
function pf_reco_seed_sources( int $user_id ): array {
	$sources = [];

	foreach ( pf_reco_purchased_ids( $user_id ) as $pid ) {
		$sources[ pf_bg_representative( $pid ) ] = 'achat';
	}

	if ( class_exists( 'Passiflore_Reading_List' ) ) {
		foreach ( Passiflore_Reading_List::get_ids( $user_id ) as $pid ) {
			$rep = pf_bg_representative( $pid );
			if ( ! isset( $sources[ $rep ] ) ) {
				$sources[ $rep ] = 'liste';
			}
		}
	}

	return $sources;
}

/**
 * Candidats bruts d'une œuvre-graine, chacun étiqueté de sa relation.
 * Calque (sans rendu) de la collecte de passiflore_get_livres_lies_sections().
 *
 * @return array<int,array{id:int,relation:string}>
 */
function pf_reco_candidates_for_seed( int $seed_id ): array {
	$rep = pf_bg_representative( $seed_id );

	// Exclusion : la graine et toutes ses éditions sœurs (mêmes format_groupe).
	$exclude = [ $seed_id => true, $rep => true ];
	if ( function_exists( 'passiflore_get_format_groupe_product_ids' ) ) {
		foreach ( passiflore_get_format_groupe_product_ids( $seed_id ) as $sib ) {
			$exclude[ (int) $sib ] = true;
		}
	}

	$out = [];
	$add = function ( $ids, $relation ) use ( &$out, $exclude ) {
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( ! $id || isset( $exclude[ $id ] ) ) {
				continue;
			}
			$out[] = [ 'id' => $id, 'relation' => $relation ];
		}
	};

	// Vous aimerez aussi (post meta sur le représentant).
	$add( (array) get_post_meta( $rep, '_pf_vous_aimerez', true ), 'aimerez' );

	// Même série.
	$serie_terms = wp_get_object_terms( $seed_id, 'pf_serie', [ 'fields' => 'ids' ] );
	if ( ! is_wp_error( $serie_terms ) && ! empty( $serie_terms ) ) {
		$add( pf_bg_group_member_reps( 'pf_serie', '_pf_serie_order', (int) $serie_terms[0] ), 'serie' );
	}

	// Traductions.
	$trad_terms = wp_get_object_terms( $seed_id, 'pf_traduction', [ 'fields' => 'ids' ] );
	if ( ! is_wp_error( $trad_terms ) && ! empty( $trad_terms ) ) {
		$add( pf_bg_group_member_reps( 'pf_traduction', '_pf_traduction_order', (int) $trad_terms[0] ), 'traduction' );
	}

	// Même(s) auteur(s).
	if ( function_exists( 'passiflore_get_product_author_ids' ) ) {
		$auteur_ids = passiflore_get_product_author_ids( $seed_id );
		if ( $auteur_ids ) {
			$add( pf_bg_dedup( passiflore_product_ids_by_auteur_terms( $auteur_ids ) ), 'auteur' );
		}
	}

	// Mêmes mots-clés (étiquettes produit WooCommerce, ex. « Landes »). Une seule
	// requête indexée par graine (tax_query sur wp_term_relationships), pas de
	// parcours du catalogue. À égalité avec les autres relations (choix acté).
	$tag_terms = wp_get_object_terms( $seed_id, 'product_tag', [ 'fields' => 'ids' ] );
	if ( ! is_wp_error( $tag_terms ) && ! empty( $tag_terms ) ) {
		$tagged = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => [ [
				'taxonomy' => 'product_tag',
				'field'    => 'term_id',
				'terms'    => $tag_terms,
			] ],
		] );
		$add( pf_bg_dedup( $tagged ), 'mots-cles' );
	}

	return $out;
}

/**
 * Recommandations ordonnées pour un client.
 * Score = nombre de graines distinctes ayant produit le candidat. Tri : score
 * décroissant, puis date de parution décroissante. Exclut le déjà-acheté /
 * déjà-en-liste et les brouillons. Le stock est laissé à l'étagère (rendu).
 *
 * @return array<int,array{id:int,score:int,reasons:array}>
 */
function pf_reco_get_recommendations( int $user_id, int $limit = 12 ): array {
	$sources = pf_reco_seed_sources( $user_id );
	if ( empty( $sources ) ) {
		return [];
	}

	$exclude = $sources; // les œuvres déjà possédées / en liste (clés = rep_ids)
	$cand    = [];        // rep_id => [ 'seeds' => [seed_id=>true], 'reasons' => [...] ]

	foreach ( $sources as $seed_id => $source ) {
		$seed_title = get_the_title( $seed_id );
		foreach ( pf_reco_candidates_for_seed( $seed_id ) as $c ) {
			$rep = pf_bg_representative( $c['id'] );
			if ( isset( $exclude[ $rep ] ) ) {
				continue;
			}
			if ( get_post_status( $rep ) !== 'publish' ) {
				continue;
			}
			if ( ! isset( $cand[ $rep ] ) ) {
				$cand[ $rep ] = [ 'seeds' => [], 'reasons' => [] ];
			}
			$cand[ $rep ]['seeds'][ $seed_id ] = true;
			$cand[ $rep ]['reasons'][]         = [
				'seed_id'  => (int) $seed_id,
				'title'    => $seed_title,
				'source'   => $source,
				'relation' => $c['relation'],
			];
		}
	}

	if ( empty( $cand ) ) {
		return [];
	}

	$recos = [];
	foreach ( $cand as $rep => $data ) {
		$recos[] = [
			'id'      => (int) $rep,
			'score'   => count( $data['seeds'] ),
			'reasons' => $data['reasons'],
		];
	}

	usort( $recos, function ( $a, $b ) {
		if ( $a['score'] !== $b['score'] ) {
			return $b['score'] <=> $a['score'];
		}
		$da = (string) get_post_meta( $a['id'], 'date_de_parution', true );
		$db = (string) get_post_meta( $b['id'], 'date_de_parution', true );
		return strcmp( $db, $da );
	} );

	return array_slice( $recos, 0, max( 1, $limit ) );
}

/**
 * Phrase d'explication (FR) d'un candidat. Titres en gras + italique, regroupés
 * par provenance (liste de lecture, puis achats), ex. :
 * « Suggéré parce que <em>A</em> et <em>B</em> sont dans votre liste de lecture
 *   et parce que vous avez commandé <em>C</em>. » Accord est/sont géré. Au-delà de
 * 3 titres par groupe, le surplus est résumé (« et N autres livres ») pour borner la
 * longueur quand beaucoup de graines contribuent.
 * ⚠ Contient du HTML (<strong>/<em>) → à rendre via wp_kses, pas esc_html.
 */
function pf_reco_explanation( array $reasons ): string {
	// Titres regroupés par provenance, dédupliqués par graine.
	$groups = [ 'liste' => [], 'achat' => [] ];
	$seen   = [];
	foreach ( $reasons as $r ) {
		$sid = (int) $r['seed_id'];
		if ( isset( $seen[ $sid ] ) ) {
			continue;
		}
		$seen[ $sid ] = true;
		$src              = ( 'achat' === $r['source'] ) ? 'achat' : 'liste';
		$groups[ $src ][] = (string) $r['title'];
	}

	$clauses = [];
	// Ordre imposé par le pattern : liste de lecture, puis achats.
	if ( $groups['liste'] ) {
		$verb      = ( count( $groups['liste'] ) > 1 ) ? 'sont' : 'est';
		$clauses[] = pf_reco_format_titles( $groups['liste'] ) . ' ' . $verb . ' dans votre liste de lecture';
	}
	if ( $groups['achat'] ) {
		$clauses[] = 'vous avez commandé ' . pf_reco_format_titles( $groups['achat'] );
	}
	if ( empty( $clauses ) ) {
		return '';
	}

	return 'Suggéré parce que ' . implode( ' et parce que ', $clauses ) . '.';
}

/**
 * Titres en gras + italique, joints « A, B et C » (passiflore_join_with_et).
 * Au-delà de 3, le surplus est résumé en « et N autres livres » — on ne résume qu'à
 * partir de 5 titres (à 4, « et 1 autre » n'économiserait rien : on les liste
 * tous). Titres échappés (esc_html) avant d'être enveloppés de <strong><em>.
 */
function pf_reco_format_titles( array $titles ): string {
	$max = 3;
	if ( count( $titles ) > $max + 1 ) {
		$extra  = count( $titles ) - $max;
		$titles = array_slice( $titles, 0, $max );
	} else {
		$extra = 0;
	}
	$items = array_map( static function ( $t ) {
		return '<strong><em>' . esc_html( $t ) . '</em></strong>';
	}, $titles );
	if ( $extra > 0 ) {
		$s       = ( $extra > 1 ) ? 's' : '';
		$items[] = $extra . ' autre' . $s . ' livre' . $s;
	}
	return passiflore_join_with_et( $items );
}

/**
 * Rend l'étagère de suggestions (annotée), ou un repli « nouveautés » pour un
 * client sans historique exploitable.
 */
function pf_reco_render( int $user_id = 0, int $limit = 12, string $display = 'covers' ): string {
	$user_id = $user_id ?: get_current_user_id();
	$display = ( 'spines' === $display ) ? 'spines' : 'covers';
	$recos   = pf_reco_get_recommendations( $user_id, $limit );

	if ( ! empty( $recos ) ) {
		$ids = [];
		$ann = [];
		foreach ( $recos as $r ) {
			$ids[]            = $r['id'];
			$ann[ $r['id'] ]  = [ 'score' => $r['score'], 'why' => pf_reco_explanation( $r['reasons'] ) ];
		}
		if ( class_exists( 'Passiflore_Bookshelf' ) ) {
			Passiflore_Bookshelf::set_reco_annotations( $ann );
		}
		$html = do_shortcode( '[passiflore_etagere ids="' . implode( ',', $ids ) . '" mode="shelves" display="' . $display . '" show_price="true" show-bookmarks="true"]' );
		if ( class_exists( 'Passiflore_Bookshelf' ) ) {
			Passiflore_Bookshelf::set_reco_annotations( [] );
		}
		return $html;
	}

	// Repli : aucun historique exploitable → nouveautés.
	return do_shortcode( '[passiflore_etagere decouvrir="nouveautes" mode="shelves" display="' . $display . '" show_price="true" show-bookmarks="true"]' );
}

/* ═══════════════════════════════════════════════════════════════
   Bascule d'affichage des étagères du compte (Couvertures | Dos)
   ───────────────────────────────────────────────────────────────
   Un switch .pf-switch posé à droite du titre re-rend l'étagère en
   « covers » ou « spines » via AJAX. Les deux affichages diffèrent au rendu
   SERVEUR (épaisseur des dos ×1,5, packing des rangées, signets/prix/badges
   propres aux couvertures) → un simple toggle CSS ne suffirait pas.
   Étagères concernées : Suggestions (accueil compte), Ma liste de lecture et
   Catalogue (page /liste-de-lecture).
   ═══════════════════════════════════════════════════════════════ */

/**
 * Callbacks de rendu de l'INTÉRIEUR de chaque étagère basculable, indexés par
 * clé. Source unique partagée entre le rendu initial et l'endpoint AJAX.
 *
 * @return array<string,callable>
 */
function pf_shelf_display_specs(): array {
	return [
		'reco'      => static function ( string $display ): string {
			return function_exists( 'pf_reco_render' ) ? pf_reco_render( get_current_user_id(), 12, $display ) : '';
		},
		'readlist'  => static function ( string $display ): string {
			return class_exists( 'Passiflore_Reading_List' ) ? Passiflore_Reading_List::render_shelf_inner( $display ) : '';
		},
		'catalogue' => static function ( string $display ): string {
			return do_shortcode( '[passiflore_etagere mode="shelves" display="' . $display . '" show_price="true" show-bookmarks="true"]' );
		},
	];
}

/**
 * Switch « Couvertures | Dos » d'une étagère, à poser à droite du titre.
 * $shelf = clé de pf_shelf_display_specs() ; $active = affichage courant.
 */
function pf_shelf_display_switch( string $shelf, string $active = 'covers' ): string {
	$active = ( 'spines' === $active ) ? 'spines' : 'covers';
	$btn    = static function ( string $display, string $label ) use ( $active ): string {
		$is = ( $display === $active );
		return '<button type="button" class="pf-switch__btn' . ( $is ? ' is-active' : '' ) . '"'
			. ' data-display="' . esc_attr( $display ) . '" aria-pressed="' . ( $is ? 'true' : 'false' ) . '">'
			. esc_html( $label ) . '</button>';
	};
	return '<div class="pf-switch pf-shelf-switch" role="group" aria-label="Affichage de l\'étagère" data-pf-shelf="' . esc_attr( $shelf ) . '">'
		. $btn( 'covers', 'Couvertures' ) . $btn( 'spines', 'Dos' )
		. '</div>';
}

/**
 * Préférence d'affichage (covers|spines) d'une étagère pour l'utilisateur
 * courant, mémorisée en user meta `_pf_shelf_display` → l'utilisateur retrouve
 * toujours son affichage préféré. Défaut « covers ».
 */
function pf_shelf_display_pref( string $shelf ): string {
	$uid = get_current_user_id();
	if ( ! $uid ) {
		return 'covers';
	}
	$prefs = get_user_meta( $uid, '_pf_shelf_display', true );
	$val   = ( is_array( $prefs ) && isset( $prefs[ $shelf ] ) ) ? $prefs[ $shelf ] : 'covers';
	return ( 'spines' === $val ) ? 'spines' : 'covers';
}

/** Enregistre la préférence d'affichage d'une étagère (au basculement du switch). */
function pf_shelf_display_save_pref( string $shelf, string $display ): void {
	$uid = get_current_user_id();
	if ( ! $uid ) {
		return;
	}
	$prefs = get_user_meta( $uid, '_pf_shelf_display', true );
	if ( ! is_array( $prefs ) ) {
		$prefs = [];
	}
	$prefs[ $shelf ] = ( 'spines' === $display ) ? 'spines' : 'covers';
	update_user_meta( $uid, '_pf_shelf_display', $prefs );
}

/** Endpoint AJAX : re-rend l'intérieur d'une étagère dans l'affichage demandé, et mémorise la préférence. */
add_action( 'wp_ajax_pf_shelf_display', 'pf_shelf_display_ajax' );
function pf_shelf_display_ajax() {
	check_ajax_referer( 'pf_shelf_display', 'nonce' );
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'reason' => 'auth' ], 403 );
	}
	$shelf   = isset( $_POST['shelf'] ) ? sanitize_key( wp_unslash( $_POST['shelf'] ) ) : '';
	$display = ( isset( $_POST['display'] ) && 'spines' === $_POST['display'] ) ? 'spines' : 'covers';
	$specs   = pf_shelf_display_specs();
	if ( ! isset( $specs[ $shelf ] ) ) {
		wp_send_json_error();
	}
	pf_shelf_display_save_pref( $shelf, $display );
	wp_send_json_success( [ 'html' => $specs[ $shelf ]( $display ) ] );
}

/* ═══════════════════════════════════════════════════════════════
   Menu « Mon compte » — ordre, libellés, regroupements, conditionnels
   ═══════════════════════════════════════════════════════════════ */

/**
 * Normalise l'ordre et les libellés du menu compte, masque les entrées vides
 * et retire celles fusionnées ailleurs (adresses → compte, téléchargements →
 * commandes). Priorité 100 : passe APRÈS les filtres des classes maison.
 */
add_filter( 'woocommerce_account_menu_items', 'pf_account_menu_reorder', 100 );
function pf_account_menu_reorder( $items ) {
	if ( ! is_array( $items ) ) {
		return $items;
	}

	if ( isset( $items['dashboard'] ) ) {
		$items['dashboard'] = 'Accueil';
	}

	// Téléchargements fusionnés dans « Commandes » — endpoint conservé.
	unset( $items['downloads'] );

	$uid = get_current_user_id();

	// « Commandes » seulement s'il y a au moins une commande.
	if ( isset( $items['orders'] ) && $uid && function_exists( 'wc_get_orders' ) ) {
		$has_orders = ! empty( wc_get_orders( [ 'customer_id' => $uid, 'limit' => 1, 'return' => 'ids' ] ) );
		if ( ! $has_orders ) {
			unset( $items['orders'] );
		}
	}

	// « Mes avis » seulement s'il y a au moins un avis.
	if ( isset( $items['mes-avis'] ) && class_exists( 'Passiflore_Mes_Avis' )
		&& ! Passiflore_Mes_Avis::get_user_reviews() ) {
		unset( $items['mes-avis'] );
	}

	$order = [ 'dashboard', 'liste-de-lecture', 'orders', 'mes-avis', 'edit-address', 'edit-account', 'customer-logout' ];
	$out   = [];
	foreach ( $order as $k ) {
		if ( isset( $items[ $k ] ) ) {
			$out[ $k ] = $items[ $k ];
			unset( $items[ $k ] );
		}
	}

	return $out + $items; // entrées imprévues (plugins…) préservées en queue
}

/**
 * Retire le « chrome » que Kadence greffe autour de la navigation du compte :
 *  - l'avatar (« kadence-account-avatar ») en tête de nav — inutile ici ;
 *  - le conteneur `.account-navigation-wrap` (hooks wrap_start/wrap_end) — sa
 *    feuille `woocommerce-account.min.css` stylise `.account-navigation-wrap li a`
 *    (bord gauche 5px, padding, couleur) et impose un layout flottant 30/70 % :
 *    tout cela écrasait notre composant `.pf-sectionnav--static` (spécificité
 *    supérieure). En supprimant le wrap, ces règles ne matchent plus notre nav,
 *    qui devient un enfant flex direct de `.woocommerce` (layout + sticky gérés
 *    par account.css / style.css). Détaché une fois le composant WC instancié.
 */
add_action( 'init', 'pf_account_strip_kadence_nav_chrome' );
function pf_account_strip_kadence_nav_chrome() {
	if ( ! class_exists( '\Kadence\Theme' ) ) {
		return;
	}
	$kadence = \Kadence\Theme::instance();
	if ( empty( $kadence->components['woocommerce'] ) ) {
		return;
	}
	$wc = $kadence->components['woocommerce'];
	remove_action( 'woocommerce_before_account_navigation', [ $wc, 'myaccount_nav_avatar' ], 20 );
	remove_action( 'woocommerce_before_account_navigation', [ $wc, 'myaccount_nav_wrap_start' ], 2 );
	remove_action( 'woocommerce_after_account_navigation', [ $wc, 'myaccount_nav_wrap_end' ], 50 );
}

/**
 * Fusion « Téléchargements » → page « Commandes » : table des téléchargements
 * sous la liste des commandes, uniquement s'il en existe (et donc pas sur une
 * commande individuelle, où ce hook ne se déclenche pas).
 */
add_action( 'woocommerce_after_account_orders', 'pf_account_downloads_in_orders' );
function pf_account_downloads_in_orders( $has_orders ) {
	if ( ! function_exists( 'wc_get_customer_available_downloads' ) ) {
		return;
	}
	if ( ! wc_get_customer_available_downloads( get_current_user_id() ) ) {
		return;
	}
	echo '<h2 class="pf-titre-2 pf-account-section-title">Téléchargements</h2>';
	do_action( 'woocommerce_account_downloads_endpoint' );
}

/**
 * Rend « Prénom » et « Nom » facultatifs dans « Détails du compte » (validation
 * serveur). Le template form-edit-account.php retire en parallèle l'astérisque
 * et aria-required de ces deux champs.
 */
add_filter( 'woocommerce_save_account_details_required_fields', 'pf_account_optional_name_fields' );
function pf_account_optional_name_fields( $fields ) {
	unset( $fields['account_first_name'], $fields['account_last_name'] );
	return $fields;
}

/**
 * Champs « Prénom » et « Nom » (facultatifs) sur le formulaire d'inscription,
 * rendus côte à côte en tête du formulaire (avant l'e-mail). Repeuplés depuis
 * $_POST en cas d'erreur de validation (le nonce d'inscription est vérifié par
 * le gestionnaire WooCommerce avant ce rendu / la sauvegarde).
 */
add_action( 'woocommerce_register_form_start', 'pf_register_name_fields' );
function pf_register_name_fields() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- simple repeuplement ; nonce vérifié par WC.
	$first = isset( $_POST['first_name'] ) ? wc_clean( wp_unslash( $_POST['first_name'] ) ) : '';
	$last  = isset( $_POST['last_name'] ) ? wc_clean( wp_unslash( $_POST['last_name'] ) ) : '';
	// phpcs:enable
	?>
	<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
		<label for="reg_first_name">Prénom</label>
		<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="first_name" id="reg_first_name" autocomplete="given-name" value="<?php echo esc_attr( $first ); ?>" />
	</p>
	<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
		<label for="reg_last_name">Nom</label>
		<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="last_name" id="reg_last_name" autocomplete="family-name" value="<?php echo esc_attr( $last ); ?>" />
	</p>
	<div class="clear"></div>
	<?php
}

/**
 * À l'inscription : enregistre prénom/nom et alimente le « nom d'affichage ».
 * Priorité au prénom, sinon le nom ; si aucun n'est renseigné, on laisse le
 * défaut WooCommerce (l'identifiant, dérivé de la partie avant l'@ de l'e-mail
 * quand la génération automatique d'identifiant est active).
 */
add_action( 'woocommerce_created_customer', 'pf_register_save_name' );
function pf_register_save_name( $customer_id ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce d'inscription déjà vérifié par WC.
	$first = isset( $_POST['first_name'] ) ? wc_clean( wp_unslash( $_POST['first_name'] ) ) : '';
	$last  = isset( $_POST['last_name'] ) ? wc_clean( wp_unslash( $_POST['last_name'] ) ) : '';
	// phpcs:enable

	$data = [ 'ID' => $customer_id ];
	if ( '' !== $first ) {
		$data['first_name'] = $first;
	}
	if ( '' !== $last ) {
		$data['last_name'] = $last;
	}

	if ( '' !== $first ) {
		$data['display_name'] = $first;
	} elseif ( '' !== $last ) {
		$data['display_name'] = $last;
	}

	if ( count( $data ) > 1 ) {
		wp_update_user( $data );
	}
}

/* ═══════════════════════════════════════════════════════════════
   Suppression de compte (self-service, conforme RGPD)
   ───────────────────────────────────────────────────────────────
   - Compte + métas (adresses, liste de lecture…) : supprimés.
   - Avis : anonymisés (texte conservé, identité effacée).
   - Commandes : CONSERVÉES (obligation comptable/fiscale) ; leur purge
     viendra plus tard via la planification WooCommerce.
   Garde-fous : mot de passe + case à cocher, comptes privilégiés exclus,
   blocage si une commande est en cours.
   ═══════════════════════════════════════════════════════════════ */

/** Un compte « à privilèges » ne peut pas être supprimé via cette page. */
function pf_account_is_privileged( $user ) {
	return user_can( $user, 'manage_options' )
		|| user_can( $user, 'manage_woocommerce' )
		|| user_can( $user, 'edit_posts' );
}

/** Statuts de commande considérés « en cours » (non finalisés). */
function pf_account_in_progress_statuses() {
	return [ 'pending', 'processing', 'on-hold' ];
}

/** Anonymise les avis (commentaires produit) d'un utilisateur : texte conservé, identité effacée. */
function pf_account_anonymize_reviews( $user_id ) {
	foreach ( [ 'all', 'trash', 'spam' ] as $status ) {
		$comments = get_comments( [
			'user_id'   => $user_id,
			'post_type' => 'product',
			'status'    => $status,
		] );
		foreach ( $comments as $c ) {
			wp_update_comment( [
				'comment_ID'           => $c->comment_ID,
				'user_id'              => 0,
				'comment_author'       => 'Lecteur supprimé',
				'comment_author_email' => '',
				'comment_author_url'   => '',
				'comment_author_IP'    => '',
			] );
		}
	}
}

/** Section « Supprimer mon compte » en bas de la page « Détails du compte ». */
add_action( 'woocommerce_after_edit_account_form', 'pf_render_delete_account_section' );
function pf_render_delete_account_section() {
	$user = wp_get_current_user();
	if ( ! $user || ! $user->ID || pf_account_is_privileged( $user ) ) {
		return;
	}
	?>
	<section class="pf-account-danger">
		<h2 class="pf-titre-2">Supprimer mon compte</h2>
		<p>
			La suppression efface définitivement vos informations personnelles
			(identifiants, adresses, liste de lecture) et anonymise vos avis (le
			texte est conservé, sans votre nom). Conformément à nos obligations
			légales (comptables et fiscales), vos commandes sont conservées le
			temps requis, puis supprimées. <strong>Cette action est irréversible.</strong>
		</p>
		<form method="post" class="pf-delete-account-form">
			<p class="woocommerce-form-row form-row form-row-wide">
				<label for="pf_delete_password">Mot de passe&nbsp;<span class="required" aria-hidden="true">*</span></label>
				<input type="password" name="pf_delete_password" id="pf_delete_password" class="woocommerce-Input woocommerce-Input--password input-text" autocomplete="current-password" required />
			</p>
			<p class="woocommerce-form-row form-row form-row-wide">
				<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
					<input type="checkbox" name="pf_delete_confirm" id="pf_delete_confirm" value="1" required />
					<span>Je comprends que la suppression de mon compte est définitive et irréversible.</span>
				</label>
			</p>
			<?php wp_nonce_field( 'pf_delete_account', 'pf_delete_account_nonce' ); ?>
			<button type="submit" name="pf_delete_account" value="1" class="button pf-delete-account-btn">Supprimer définitivement mon compte</button>
		</form>
	</section>
	<?php
}

/** Traite la demande de suppression de compte. */
add_action( 'template_redirect', 'pf_handle_account_deletion' );
function pf_handle_account_deletion() {
	if ( empty( $_POST['pf_delete_account'] ) || ! is_user_logged_in() ) {
		return;
	}

	if ( ! isset( $_POST['pf_delete_account_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['pf_delete_account_nonce'] ) ), 'pf_delete_account' ) ) {
		wc_add_notice( 'Échec de la vérification de sécurité. Merci de réessayer.', 'error' );
		return;
	}

	$user_id = get_current_user_id();
	$user    = wp_get_current_user();

	if ( pf_account_is_privileged( $user ) ) {
		wc_add_notice( 'Ce compte ne peut pas être supprimé depuis cette page. Contactez un administrateur.', 'error' );
		return;
	}

	if ( empty( $_POST['pf_delete_confirm'] ) ) {
		wc_add_notice( 'Veuillez cocher la case de confirmation pour supprimer votre compte.', 'error' );
		return;
	}

	$password = isset( $_POST['pf_delete_password'] ) ? (string) wp_unslash( $_POST['pf_delete_password'] ) : '';
	if ( '' === $password || ! wp_check_password( $password, $user->user_pass, $user_id ) ) {
		wc_add_notice( 'Mot de passe incorrect.', 'error' );
		return;
	}

	// Blocage si une commande est en cours (non finalisée).
	if ( function_exists( 'wc_get_orders' ) ) {
		$in_progress = wc_get_orders( [
			'customer_id' => $user_id,
			'status'      => pf_account_in_progress_statuses(),
			'limit'       => 1,
			'return'      => 'ids',
		] );
		if ( ! empty( $in_progress ) ) {
			wc_add_notice( 'Vous avez une commande en cours. La suppression sera possible une fois cette commande finalisée. Pour toute question, n\'hésitez pas à nous contacter.', 'error' );
			return;
		}
	}

	// 1. Anonymiser les avis.
	pf_account_anonymize_reviews( $user_id );

	// 2. Supprimer les jetons de paiement enregistrés (best-effort).
	if ( class_exists( 'WC_Payment_Tokens' ) ) {
		foreach ( WC_Payment_Tokens::get_customer_tokens( $user_id ) as $token ) {
			$token->delete();
		}
	}

	// 3. Supprimer l'utilisateur WP (les commandes restent, indépendantes du compte).
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $user_id );

	// 4. Déconnexion + redirection accueil avec message.
	wp_logout();
	wp_safe_redirect( add_query_arg( 'compte_supprime', '1', home_url( '/' ) ) );
	exit;
}

/** Bandeau de confirmation après suppression (accueil). */
add_action( 'wp_body_open', 'pf_account_deleted_notice' );
function pf_account_deleted_notice() {
	if ( empty( $_GET['compte_supprime'] ) ) {
		return;
	}
	echo '<div class="pf-account-deleted-banner" role="status" style="background:var(--pf-accent);color:var(--pf-cream);text-align:center;padding:0.85rem 1rem;font-size:0.95rem;">'
		. 'Votre compte a bien été supprimé. Vos avis ont été anonymisés ; vos commandes sont conservées le temps légal requis.'
		. '</div>';
}
