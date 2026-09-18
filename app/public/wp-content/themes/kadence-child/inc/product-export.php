<?php
/**
 * Écran d'export CSV des produits (/wp-admin/edit.php?post_type=product&page=product_exporter),
 * refondu pour un catalogue d'éditeur : colonnes livre (SCF), filtres catalogue,
 * regroupement d'une œuvre multi-formats sur une seule ligne.
 *
 * Aucun fichier WooCommerce n'est modifié — tout passe par les hooks natifs de
 * WC_Product_CSV_Exporter (class-wc-product-csv-exporter.php) et de la vue
 * html-admin-page-product-export.php.
 *
 * ⚠️ wc_get_products() ignore silencieusement 'meta_query' (WC_Data_Store_WP::
 * get_wp_query_args() fait `continue` dessus) : le filtrage se fait donc en amont
 * via un WP_Query classique, résolu en liste d'IDs injectée dans 'include'.
 *
 * ⚠️ Un jeu de résultats vide doit devenir [0], jamais [] : prepare_data_to_export()
 * teste `! empty( $args['include'] )` et exporterait sinon tout le catalogue.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Passiflore_Product_Export {

	/**
	 * Colonnes affichées dans l'optgroup "Livre" (essentiel puis le reste).
	 * id/sku/stock/featured/pf_etiquettes retirés de l'export (choix acté, plus
	 * d'entrée dans BOOK_LABELS non plus). pf_disponibilite/pf_genre/pf_public/
	 * pf_langues/pf_reliure/pf_nouveaute/sale_price/pf_nb_* déplacés dans
	 * AUTRES_CUSTOM_IDS (choix acté) — leur libellé/valeur ne changent pas, seul
	 * l'optgroup d'affichage change (cf. columns()/render_columns_script()).
	 */
	const BOOK_IDS = [
		'global_unique_id', 'name', 'pf_sous_titre', 'pf_auteurs', 'pf_roles', 'pf_collection',
		'pf_thematique', 'pf_formats', 'pf_date_parution', 'regular_price', 'pf_pages',
		'pf_distinctions', 'description', 'short_description', 'weight', 'width', 'height',
		'pf_serie', 'pf_nom_de_plume', 'pf_illustration_couverture', 'pf_lien_extrait',
		'pf_lien_libraires', 'pf_url_fiche', 'pf_image_couverture',
	];

	/** Jeu pré-coché à l'ouverture de l'écran. Disponibilité décochée par défaut (choix acté). */
	const ESSENTIAL_IDS = [
		'global_unique_id', 'name', 'pf_sous_titre', 'pf_auteurs', 'pf_roles', 'pf_collection',
		'pf_thematique', 'pf_formats', 'pf_date_parution', 'regular_price', 'pf_pages',
	];

	/** Libellés des colonnes "Livre" + des colonnes custom reléguées sous "Autres"
	 *  (AUTRES_CUSTOM_IDS). weight/width/height absents exprès : leur libellé vient
	 *  du natif WooCommerce (unité incluse, cf. columns()). */
	const BOOK_LABELS = [
		'global_unique_id'            => 'ISBN',
		'name'                        => 'Titre',
		'pf_sous_titre'               => 'Sous-titre',
		'pf_auteurs'                  => 'Auteurs',
		'pf_roles'                    => 'Rôles',
		'pf_collection'               => 'Collection',
		'pf_thematique'               => 'Thématique',
		'pf_formats'                  => 'Formats',
		'pf_date_parution'            => 'Date de parution',
		'regular_price'               => 'Prix',
		'pf_disponibilite'            => 'Disponibilité',
		'pf_pages'                    => 'Nombre de pages',
		'pf_genre'                    => 'Genre',
		'pf_public'                   => 'Public',
		'pf_langues'                  => 'Langues',
		'pf_reliure'                  => 'Reliure',
		'pf_nouveaute'                => 'Nouveauté',
		'pf_distinctions'             => 'Distinctions',
		'description'                 => 'Résumé',
		'short_description'           => 'Accroche',
		'sale_price'                  => 'Prix promo',
		'pf_serie'                    => 'Série',
		'pf_nom_de_plume'             => 'Nom de plume',
		'pf_illustration_couverture'  => 'Illustration de couverture',
		'pf_lien_extrait'             => 'Lien extrait PDF',
		'pf_lien_libraires'           => 'Lien Place des libraires',
		'pf_url_fiche'                => 'URL de la fiche',
		'pf_image_couverture'         => 'Image de couverture',
		'pf_nb_avis'                  => 'Nb avis',
		'pf_nb_presse'                => 'Nb articles de presse',
		'pf_nb_videos'                => 'Nb vidéos',
		'pf_nb_podcasts'              => 'Nb podcasts',
	];

	/**
	 * Colonnes custom (label dans BOOK_LABELS) reléguées sous l'optgroup "Autres"
	 * plutôt que "Livre" — choix acté. Valeur/aggrégation inchangées, seul
	 * l'affichage bouge (cf. OEUVRE_COLS/PER_EDITION_COLS, indifférents à l'optgroup).
	 */
	const AUTRES_CUSTOM_IDS = [
		'pf_disponibilite', 'pf_genre', 'pf_public', 'pf_langues', 'pf_reliure', 'pf_nouveaute',
		'sale_price', 'pf_nb_avis', 'pf_nb_presse', 'pf_nb_videos', 'pf_nb_podcasts',
	];

	/**
	 * Colonnes natives WooCommerce, repliées sous "Autres" (libellé natif repris tel quel).
	 * Élaguée d'une liste native de 24 : retirés (choix acté) — relations produits
	 * (parent_id, grouped_products, upsell_ids, cross_sell_ids, toujours vides : ce
	 * catalogue n'a que des produits simples), produit externe/affilié (product_url,
	 * button_text), category_ids (redondant avec Collection/Thématique), fiscalité
	 * (tax_status, tax_class), promotions programmées (date_on_sale_from/to), gestion
	 * stock avancée (low_stock_amount, backorders, sold_individually, length), avis/
	 * note/expédition/position (reviews_allowed, purchase_note, shipping_class_id,
	 * menu_order), catalog_visibility, download_limit, download_expiry, type, published.
	 * Les deux options codées en dur par le gabarit natif (`downloads`, `attributes`,
	 * hors get_default_column_names()) sont retirées du menu par le script inline,
	 * cf. render_columns_script(). Vide pour l'instant — gardée pour le jour où un
	 * champ natif redevient pertinent, plutôt que de redéfaire tout le mécanisme.
	 */
	const AUTRES_IDS = [];

	/**
	 * Colonnes "d'œuvre" : lues UNE FOIS sur le représentant du groupe, jamais
	 * suffixées par format (cf. plan — Titre, Auteurs, Collection…).
	 */
	const OEUVRE_COLS = [
		'name', 'description', 'short_description', 'pf_sous_titre', 'pf_auteurs', 'pf_roles',
		'pf_collection', 'pf_thematique', 'pf_genre', 'pf_public', 'pf_langues', 'pf_reliure',
		'pf_distinctions', 'pf_serie', 'pf_nouveaute', 'pf_nom_de_plume', 'pf_illustration_couverture',
		'pf_nb_avis', 'pf_nb_presse', 'pf_nb_videos', 'pf_nb_podcasts',
	];

	/**
	 * Colonnes "par édition" : agrégées sur les éditions RETENUES du groupe — valeur
	 * unique si elles s'accordent, sinon chaque valeur suffixée de son format.
	 */
	const PER_EDITION_COLS = [
		'global_unique_id', 'regular_price', 'sale_price', 'weight', 'width', 'height',
		'pf_pages', 'pf_date_parution', 'pf_disponibilite', 'pf_lien_extrait', 'pf_url_fiche',
		'pf_image_couverture', 'pf_lien_libraires',
	];

	/**
	 * ID de la ligne (le "carrier" transmis à wc_get_products()) → contexte de groupe.
	 * Rempli par query_args(), relu par column_value(). Remis à zéro à chaque requête
	 * PHP (une requête = un lot d'export), donc pas de fuite entre exports.
	 */
	private static $rows = [];

	/** Case "Regrouper les éditions…" cochée pour cette requête. */
	private static $grouped = true;

	/** Instance partagée pour get_scf_choices() (évite une ré-instanciation par appel). */
	private static $bookshelf;

	public static function init() {
		add_filter( 'woocommerce_product_export_product_default_columns', [ __CLASS__, 'columns' ] );
		add_filter( 'woocommerce_product_export_product_query_args', [ __CLASS__, 'query_args' ] );
		add_action( 'woocommerce_product_export_row', [ __CLASS__, 'render_filters_row' ] );

		foreach ( array_merge( self::OEUVRE_COLS, self::PER_EDITION_COLS, [ 'pf_formats' ] ) as $id ) {
			add_filter( "woocommerce_product_export_product_column_{$id}", [ __CLASS__, 'column_value' ], 10, 3 );
		}
	}

	/* ─── Colonnes ────────────────────────────────────────────────── */

	public static function columns( $default ) {
		$book = [];
		foreach ( self::BOOK_IDS as $id ) {
			// weight/width/height : pas de libellé maison → on garde le natif (unité incluse).
			$book[ $id ] = self::BOOK_LABELS[ $id ] ?? ( $default[ $id ] ?? $id );
		}

		$autres = [];
		foreach ( self::AUTRES_CUSTOM_IDS as $id ) {
			$autres[ $id ] = self::BOOK_LABELS[ $id ] ?? $id;
		}
		foreach ( self::AUTRES_IDS as $id ) {
			if ( isset( $default[ $id ] ) ) $autres[ $id ] = $default[ $id ];
		}

		return $book + $autres;
	}

	/* ─── Résolution des filtres → IDs, puis regroupement ──────────── */

	public static function query_args( $args ) {
		$vars          = self::form_vars();
		self::$grouped = ! empty( $vars['pf_group'] );

		$ids = self::resolve_filtered_ids( $vars );

		if ( self::$grouped ) {
			$ids = self::build_group_carriers( $ids );
		} else {
			foreach ( $ids as $id ) {
				self::$rows[ $id ] = [ 'editions' => [ $id ], 'representative' => $id ];
			}
		}

		$args['include'] = ! empty( $ids ) ? $ids : [ 0 ];
		// Le natif trie par ID ; un catalogue livre se consulte par titre.
		$args['orderby'] = 'title';
		$args['order']   = 'ASC';
		return $args;
	}

	private static function form_vars(): array {
		$vars = [];
		if ( ! empty( $_POST['form'] ) ) {
			wp_parse_str( wp_unslash( $_POST['form'] ), $vars ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		return $vars;
	}

	private static function resolve_filtered_ids( array $vars ): array {
		$status = ! empty( $vars['pf_status'] ) ? array_map( 'sanitize_key', (array) $vars['pf_status'] ) : [ 'publish' ];

		$tax_query  = [];
		$meta_query = [];

		if ( ! empty( $vars['pf_collection'] ) ) {
			$tax_query[] = [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array_map( 'absint', (array) $vars['pf_collection'] ) ];
		}
		if ( ! empty( $vars['pf_thematique'] ) ) {
			$tax_query[] = [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array_map( 'absint', (array) $vars['pf_thematique'] ) ];
		}
		if ( ! empty( $vars['pf_auteur'] ) ) {
			$tax_query[] = [ 'taxonomy' => 'auteur', 'field' => 'term_id', 'terms' => array_map( 'absint', (array) $vars['pf_auteur'] ) ];
		}

		self::eq_meta( $meta_query, $vars, 'pf_disponibilite', 'disponibilite' );
		self::eq_meta( $meta_query, $vars, 'pf_genre', 'type' );
		self::eq_meta( $meta_query, $vars, 'pf_public', 'public' );
		self::langues_meta( $meta_query, $vars );
		self::nouveaute_meta( $meta_query, $vars );
		self::date_meta( $meta_query, $vars );

		$q = new WP_Query( [
			'post_type'      => 'product',
			'post_status'    => $status,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => $tax_query,  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		] );

		$ids = array_map( 'intval', $q->posts );

		// Format particulier : "classique" est une ABSENCE de terme, pas un terme —
		// résolu en PHP plutôt qu'en tax_query (catalogue trop petit pour que ça pèse).
		if ( ! empty( $vars['pf_format'] ) ) {
			$wanted = array_map( 'sanitize_title', (array) $vars['pf_format'] );
			$ids    = array_values( array_filter( $ids, function ( $id ) use ( $wanted ) {
				return in_array( pf_format_of( $id ), $wanted, true );
			} ) );
		}

		return $ids;
	}

	private static function eq_meta( array &$mq, array $vars, string $field, string $meta_key ): void {
		if ( empty( $vars[ $field ] ) ) return;
		$vals = array_values( array_filter( array_map( 'sanitize_text_field', (array) $vars[ $field ] ) ) );
		if ( empty( $vals ) ) return;
		if ( count( $vals ) === 1 ) {
			$mq[] = [ 'key' => $meta_key, 'value' => $vals[0] ];
			return;
		}
		$clauses = [ 'relation' => 'OR' ];
		foreach ( $vals as $v ) $clauses[] = [ 'key' => $meta_key, 'value' => $v ];
		$mq[] = $clauses;
	}

	/** SCF `langues` : checkbox stocké en tableau sérialisé → LIKE '"slug"' par valeur. */
	private static function langues_meta( array &$mq, array $vars ): void {
		if ( empty( $vars['pf_langues'] ) ) return;
		$vals = array_values( array_filter( array_map( 'sanitize_text_field', (array) $vars['pf_langues'] ) ) );
		if ( empty( $vals ) ) return;
		$clauses = [ 'relation' => 'OR' ];
		foreach ( $vals as $v ) {
			$clauses[] = [ 'key' => 'langues', 'value' => '"' . $v . '"', 'compare' => 'LIKE' ];
		}
		$mq[] = $clauses;
	}

	private static function nouveaute_meta( array &$mq, array $vars ): void {
		$v = $vars['pf_nouveaute'] ?? '';
		if ( $v === '1' ) {
			$mq[] = [ 'key' => 'nouveaute', 'value' => '1' ];
		} elseif ( $v === '0' ) {
			$mq[] = [
				'relation' => 'OR',
				[ 'key' => 'nouveaute', 'compare' => 'NOT EXISTS' ],
				[ 'key' => 'nouveaute', 'value' => '1', 'compare' => '!=' ],
			];
		}
	}

	/** Ymd (stockage SCF) comparé en chaîne : évite les pièges de cast DATE de MySQL sur un format sans tirets. */
	private static function date_meta( array &$mq, array $vars ): void {
		$from = self::to_ymd( (string) ( $vars['pf_date_from'] ?? '' ) );
		$to   = self::to_ymd( (string) ( $vars['pf_date_to'] ?? '' ) );
		if ( ! $from && ! $to ) return;
		$mq[] = [
			'key'     => 'date_de_parution',
			'value'   => [ $from ?: '00000000', $to ?: '99999999' ],
			'compare' => 'BETWEEN',
		];
	}

	private static function to_ymd( string $iso_date ): string {
		if ( $iso_date === '' ) return '';
		$d = DateTime::createFromFormat( 'Y-m-d', $iso_date );
		return $d ? $d->format( 'Ymd' ) : '';
	}

	/**
	 * Ramène chaque ID filtré à son œuvre (format_groupe) et choisit la ligne
	 * "carrier" : le représentant s'il fait partie des éditions retenues, sinon
	 * la première édition retenue dans l'ordre canonique des formats.
	 */
	private static function build_group_carriers( array $ids ): array {
		$by_group = [];
		$order    = [];
		foreach ( $ids as $id ) {
			$gid = pf_format_group_of( $id );
			$key = $gid ?: 'p' . $id; // produit autonome : sa propre clé
			if ( ! isset( $by_group[ $key ] ) ) {
				$by_group[ $key ] = [ 'group_id' => $gid, 'editions' => [] ];
				$order[]          = $key;
			}
			$by_group[ $key ]['editions'][] = $id;
		}

		$carriers = [];
		foreach ( $order as $key ) {
			$g = $by_group[ $key ];

			if ( $g['group_id'] ) {
				$all_members     = pf_group_members( $g['group_id'] );
				$subset          = array_intersect_key( $all_members, array_flip( $g['editions'] ) );
				$sorted_editions = pf_group_sort_members( $subset );
				$rep             = pf_group_representative( $g['group_id'] );
				$carrier         = in_array( $rep, $sorted_editions, true ) ? $rep : $sorted_editions[0];
			} else {
				$sorted_editions = $g['editions'];
				$carrier         = $g['editions'][0];
				$rep             = $carrier;
			}

			self::$rows[ $carrier ] = [ 'editions' => $sorted_editions, 'representative' => $rep ];
			$carriers[]             = $carrier;
		}

		return $carriers;
	}

	/* ─── Valeurs des colonnes maison ───────────────────────────────── */

	public static function column_value( $value, $product, $column_id ) {
		$carrier_id = $product->get_id();
		$row        = self::$rows[ $carrier_id ] ?? [ 'editions' => [ $carrier_id ], 'representative' => $carrier_id ];

		if ( $column_id === 'pf_formats' ) {
			return implode( ', ', array_map( function ( $eid ) {
				return ucfirst( self::format_label( pf_format_of( $eid ) ) );
			}, $row['editions'] ) );
		}

		// Titre : racine (sans suffixe) seulement en mode groupé — en mode "une
		// ligne par édition", le titre réel de cette édition précise est la
		// valeur brute attendue (cf. plan, colonne Titre).
		if ( $column_id === 'name' && ! self::$grouped ) {
			return $product->get_name( 'edit' );
		}

		if ( in_array( $column_id, self::OEUVRE_COLS, true ) ) {
			return self::oeuvre_value( $column_id, $row['representative'] );
		}

		if ( in_array( $column_id, self::PER_EDITION_COLS, true ) ) {
			return self::aggregate( $column_id, $row['editions'] );
		}

		return $value;
	}

	/** Valeur "œuvre" : lue une seule fois sur le représentant, jamais suffixée. */
	private static function oeuvre_value( string $col, int $rep_id ): string {
		switch ( $col ) {
			case 'name':
				return pf_format_root( $rep_id );

			case 'description':
				$p = wc_get_product( $rep_id );
				return $p ? self::strip_description( (string) $p->get_description( 'edit' ) ) : '';

			case 'short_description':
				$p = wc_get_product( $rep_id );
				return $p ? self::strip_description( (string) $p->get_short_description( 'edit' ) ) : '';

			case 'pf_sous_titre':
				return (string) get_field( 'sous-titre', $rep_id );

			case 'pf_auteurs':
				$ids = passiflore_get_product_author_ids( $rep_id );
				return passiflore_join_auteur_names( array_map( 'passiflore_auteur_display_name', $ids ) );

			case 'pf_roles':
				return self::roles_value( $rep_id );

			case 'pf_collection':
				return self::category_value( $rep_id, true );

			case 'pf_thematique':
				return self::category_value( $rep_id, false );

			case 'pf_genre':
				return self::scf_label( 'type', (string) get_post_meta( $rep_id, 'type', true ) );

			case 'pf_public':
				return self::scf_label( 'public', (string) get_post_meta( $rep_id, 'public', true ) );

			case 'pf_langues':
				return self::langues_value( $rep_id );

			case 'pf_reliure':
				return self::scf_label( 'type_de_reliure', (string) get_post_meta( $rep_id, 'type_de_reliure', true ) );

			case 'pf_distinctions':
				return implode( '; ', function_exists( 'pf_distinction_labels_shared' ) ? pf_distinction_labels_shared( $rep_id ) : [] );

			case 'pf_serie':
				$terms = get_the_terms( $rep_id, 'pf_serie' );
				return ( $terms && ! is_wp_error( $terms ) && $terms ) ? $terms[0]->name : '';

			case 'pf_nouveaute':
				return get_post_meta( $rep_id, 'nouveaute', true ) === '1' ? 'Oui' : 'Non';

			case 'pf_nom_de_plume':
				return (string) get_field( 'nom_de_plume', $rep_id );

			case 'pf_illustration_couverture':
				return (string) get_field( 'illustration_de_couverture', $rep_id );

			case 'pf_nb_avis':
				return (string) self::count_avis( $rep_id );

			case 'pf_nb_presse':
				return (string) self::count_repeater( $rep_id, 'articles_de_presse', [ 'titre', 'lien', 'fichier' ] );

			case 'pf_nb_videos':
				return (string) self::count_repeater( $rep_id, 'videos', [ 'titre', 'lien', 'fichier_video' ] );

			case 'pf_nb_podcasts':
				return (string) self::count_repeater( $rep_id, 'podcasts', [ 'titre', 'lien', 'fichier_audio' ] );

			default:
				return '';
		}
	}

	/** Valeur "par édition" pour UNE édition — combinée ensuite par aggregate(). */
	private static function atomic_value( string $col, int $id ): string {
		switch ( $col ) {
			case 'global_unique_id':
				$p = wc_get_product( $id );
				return $p ? (string) $p->get_global_unique_id( 'edit' ) : '';

			case 'regular_price':
				$p = wc_get_product( $id );
				return $p ? self::format_price( $p->get_regular_price( 'edit' ) ) : '';

			case 'sale_price':
				$p = wc_get_product( $id );
				return $p ? self::format_price( $p->get_sale_price( 'edit' ) ) : '';

			case 'weight':
				$p = wc_get_product( $id );
				return $p ? (string) $p->get_weight( 'edit' ) : '';

			case 'width':
				$p = wc_get_product( $id );
				return $p ? (string) $p->get_width( 'edit' ) : '';

			case 'height':
				$p = wc_get_product( $id );
				return $p ? (string) $p->get_height( 'edit' ) : '';

			case 'pf_pages':
				$v = get_post_meta( $id, 'nombre_de_pages', true );
				return $v !== '' ? (string) $v : '';

			case 'pf_date_parution':
				return self::format_date_parution( $id );

			case 'pf_disponibilite':
				return self::scf_label( 'disponibilite', (string) get_post_meta( $id, 'disponibilite', true ) );

			case 'pf_lien_extrait':
				// Lien vers le lecteur pageflip (deep-link /extrait, inc/pageflip.php),
				// pas le fichier PDF brut — cohérent avec ce que le visiteur ouvre.
				$att = (int) get_field( 'extrait', $id );
				return $att ? trailingslashit( get_permalink( $id ) ) . 'extrait' : '';

			case 'pf_url_fiche':
				return (string) get_permalink( $id );

			case 'pf_image_couverture':
				$tid = get_post_thumbnail_id( $id );
				return $tid ? (string) wp_get_attachment_url( $tid ) : '';

			case 'pf_lien_libraires':
				return (string) get_field( 'lien_place_des_libraires', $id );

			default:
				return '';
		}
	}

	/**
	 * Combine les valeurs par édition : une seule fois si les éditions RENSEIGNÉES
	 * s'accordent (les éditions vides sont ignorées, jamais listées avec une valeur
	 * vide), sinon chaque valeur renseignée suffixée de son format. Une seule
	 * édition avec une donnée (ex. poids saisi seulement sur le classique) ⇒ cette
	 * valeur seule, sans suffixe — le suffixe n'a de sens qu'en cas de désaccord
	 * entre éditions qui ont, l'une et l'autre, une vraie valeur.
	 */
	private static function aggregate( string $col, array $editions ): string {
		$values = [];
		foreach ( $editions as $eid ) {
			$v = self::atomic_value( $col, $eid );
			if ( $v !== '' ) $values[ $eid ] = $v;
		}

		if ( empty( $values ) ) return '';

		if ( count( array_unique( $values ) ) <= 1 ) {
			return (string) reset( $values );
		}

		$parts = [];
		foreach ( $editions as $eid ) {
			if ( ! isset( $values[ $eid ] ) ) continue;
			$parts[] = trim( $values[ $eid ] ) . ' (' . self::format_label( pf_format_of( $eid ) ) . ')';
		}
		return implode( ', ', $parts );
	}

	/* ─── Petits formatteurs ─────────────────────────────────────────── */

	private static function roles_value( int $id ): string {
		// Même convention que passiflore_render_auteurs_section() (book-single-tabs.php),
		// avec 'auteur' rendu explicite ('Auteur') plutôt que vide : colonne de
		// données, pas de badge visuel où l'omission se lit comme "auteur simple".
		$type_labels = [
			'auteur'       => 'Auteur',
			'traduction'   => 'Traduction',
			'illustration' => 'Illustration',
			'preface'      => 'Préface',
			'postface'     => 'Postface',
			'photographie' => 'Photographie',
		];

		$contributions = get_field( 'contributions', $id );
		if ( ! is_array( $contributions ) ) return '';

		$labels = [];
		foreach ( $contributions as $row ) {
			if ( ( $row['assignation'] ?? '' ) !== 'fiche-auteur' || empty( $row['fiche-auteur'] ) ) continue;
			$type  = (string) ( $row['type'] ?? '' );
			$label = $type_labels[ $type ] ?? ucfirst( str_replace( '-', ' ', $type ) );
			if ( $label !== '' && ! in_array( $label, $labels, true ) ) $labels[] = $label;
		}
		return implode( ', ', $labels );
	}

	private static function category_value( int $id, bool $top ): string {
		$terms = get_the_terms( $id, 'product_cat' );
		if ( ! $terms || is_wp_error( $terms ) ) return '';

		$names = [];
		foreach ( $terms as $t ) {
			if ( ( (int) $t->parent === 0 ) === $top ) $names[] = $t->name;
		}
		return implode( ', ', $names );
	}

	private static function langues_value( int $id ): string {
		$slugs = get_field( 'langues', $id );
		if ( ! is_array( $slugs ) || empty( $slugs ) ) return '';
		return implode( ', ', array_map( function ( $slug ) {
			return self::scf_label( 'langues', (string) $slug );
		}, $slugs ) );
	}

	private static function count_repeater( int $id, string $field, array $key_fields ): int {
		$group_ids = function_exists( 'passiflore_get_format_groupe_product_ids' )
			? passiflore_get_format_groupe_product_ids( $id )
			: [ $id ];
		return function_exists( 'passiflore_collect_group_repeater' )
			? count( passiflore_collect_group_repeater( $group_ids, $field, $key_fields ) )
			: count( (array) get_field( $field, $id ) );
	}

	private static function count_avis( int $id ): int {
		$group_ids = function_exists( 'passiflore_get_format_groupe_product_ids' )
			? passiflore_get_format_groupe_product_ids( $id )
			: [ $id ];
		if ( ! function_exists( 'passiflore_collect_avis_scf' ) ) {
			return count( (array) get_field( 'avis_des_lecteurs', $id ) ) + count( (array) get_field( 'avis_des_libraires', $id ) );
		}
		return count( passiflore_collect_avis_scf( $group_ids, 'avis_des_lecteurs' ) )
			+ count( passiflore_collect_avis_scf( $group_ids, 'avis_des_libraires' ) );
	}

	/** "19 €" / "7,99 €" — décimales françaises, ",00" superflu retiré. */
	private static function format_price( $amount ): string {
		if ( $amount === '' || $amount === null ) return '';
		$formatted = number_format( (float) $amount, 2, ',', '' );
		$formatted = preg_replace( '/,00$/', '', $formatted );
		return $formatted . ' €';
	}

	/** "20260826" (SCF) → "26/08/2026". */
	private static function format_date_parution( int $id ): string {
		$raw = (string) get_post_meta( $id, 'date_de_parution', true );
		if ( $raw === '' ) return '';
		$d = DateTime::createFromFormat( 'Ymd', $raw );
		return $d ? $d->format( 'd/m/Y' ) : '';
	}

	/**
	 * Texte brut pour Résumé/Accroche : balises HTML retirées (lecture/transmission,
	 * pas de réimport — contrairement au natif WC qui échappe les retours à la ligne
	 * en "\n" littéral pour un réimport fidèle, cf. filter_description_field()).
	 * Un espace précède chaque balise de bloc avant strip_tags pour ne pas coller
	 * deux paragraphes l'un à l'autre ; wp_strip_all_tags( …, true ) retire ensuite
	 * balises + retours à la ligne réels, puis les espaces multiples sont réduits.
	 */
	private static function strip_description( string $html ): string {
		if ( $html === '' ) return '';
		$html = (string) preg_replace( '/<(p|br|div|li)\b[^>]*>/i', ' ', $html );
		$text = wp_strip_all_tags( $html, true );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/** "classique" pour l'absence de terme, sinon le nom du terme en minuscules (forme de pf_format_suffix()). */
	private static function format_label( string $slug ): string {
		if ( $slug === '' ) return 'classique';
		$term = get_term_by( 'slug', $slug, 'pa_format_particulier' );
		return ( $term && ! is_wp_error( $term ) ) ? mb_strtolower( $term->name ) : $slug;
	}

	private static function scf_choices( string $field ): array {
		if ( ! self::$bookshelf ) self::$bookshelf = new Passiflore_Bookshelf();
		return self::$bookshelf->get_scf_choices( $field );
	}

	private static function scf_label( string $field, string $slug ): string {
		if ( $slug === '' ) return '';
		$choices = self::scf_choices( $field );
		return $choices[ $slug ] ?? $slug;
	}

	/* ─── Écran : lignes de filtres + script de mise en forme ───────── */

	public static function render_filters_row() {
		$collections = get_terms( [ 'taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => false ] );
		$collections = is_wp_error( $collections ) ? [] : $collections;

		$all_children = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		$all_children = is_wp_error( $all_children ) ? [] : $all_children;
		$by_parent    = [];
		foreach ( $all_children as $t ) {
			if ( (int) $t->parent !== 0 ) $by_parent[ $t->parent ][] = $t;
		}

		$disponibilite_choices = self::scf_choices( 'disponibilite' );
		$genre_choices         = self::scf_choices( 'type' );
		$public_choices        = self::scf_choices( 'public' );
		$langues_choices       = self::scf_choices( 'langues' );
		$format_terms          = function_exists( 'pf_format_terms' ) ? pf_format_terms() : [];

		$auteurs = get_terms( [ 'taxonomy' => 'auteur', 'hide_empty' => false ] );
		$auteurs = is_wp_error( $auteurs ) ? [] : $auteurs;
		?>
		<tr>
			<th scope="row"><label>Collection</label></th>
			<td>
				<select multiple name="pf_collection[]" class="wc-enhanced-select" style="width:100%;" data-placeholder="Toutes les collections">
					<?php foreach ( $collections as $t ) : ?>
						<option value="<?php echo esc_attr( $t->term_id ); ?>"><?php echo esc_html( $t->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label>Thématique</label></th>
			<td>
				<select multiple name="pf_thematique[]" class="wc-enhanced-select" style="width:100%;" data-placeholder="Toutes les thématiques">
					<?php foreach ( $collections as $parent ) :
						$children = $by_parent[ $parent->term_id ] ?? [];
						if ( ! $children ) continue;
						?>
						<optgroup label="<?php echo esc_attr( $parent->name ); ?>">
							<?php foreach ( $children as $t ) : ?>
								<option value="<?php echo esc_attr( $t->term_id ); ?>"><?php echo esc_html( $t->name ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label>Disponibilité</label></th>
			<td>
				<select multiple name="pf_disponibilite[]" class="wc-enhanced-select" style="width:100%;" data-placeholder="Toutes disponibilités">
					<?php foreach ( $disponibilite_choices as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label>Format particulier</label></th>
			<td>
				<select multiple name="pf_format[]" class="wc-enhanced-select" style="width:100%;" data-placeholder="Tous formats">
					<option value="">Classique</option>
					<?php foreach ( $format_terms as $t ) : ?>
						<option value="<?php echo esc_attr( $t->slug ); ?>"><?php echo esc_html( $t->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label>Date de parution</label></th>
			<td>
				<label>Du <input type="date" name="pf_date_from"></label>
				&nbsp;&nbsp;
				<label>au <input type="date" name="pf_date_to"></label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label>Genre</label></th>
			<td>
				<select multiple name="pf_genre[]" class="wc-enhanced-select" style="width:100%;" data-placeholder="Tous genres">
					<?php foreach ( $genre_choices as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label>Public</label></th>
			<td>
				<select multiple name="pf_public[]" class="wc-enhanced-select" style="width:100%;" data-placeholder="Tous publics">
					<?php foreach ( $public_choices as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label>Langues</label></th>
			<td>
				<select multiple name="pf_langues[]" class="wc-enhanced-select" style="width:100%;" data-placeholder="Toutes langues">
					<?php foreach ( $langues_choices as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label>Auteur</label></th>
			<td>
				<select multiple name="pf_auteur[]" class="wc-enhanced-select" style="width:100%;" data-placeholder="Tous auteurs">
					<?php foreach ( $auteurs as $t ) : ?>
						<option value="<?php echo esc_attr( $t->term_id ); ?>"><?php echo esc_html( $t->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pf-export-nouveaute">Nouveauté</label></th>
			<td>
				<select id="pf-export-nouveaute" name="pf_nouveaute">
					<option value="">Indifférent</option>
					<option value="1">Oui</option>
					<option value="0">Non</option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label>Statut de publication</label></th>
			<td>
				<select multiple name="pf_status[]" class="wc-enhanced-select" style="width:100%;">
					<option value="publish" selected>Publié</option>
					<option value="draft">Brouillon</option>
					<option value="pending">En attente de relecture</option>
					<option value="private">Privé</option>
					<option value="future">Planifié</option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pf-export-group">Regroupement</label></th>
			<td>
				<input type="checkbox" id="pf-export-group" name="pf_group" value="1" checked>
				<label for="pf-export-group">Regrouper les éditions d'un même livre sur une seule ligne</label>
			</td>
		</tr>
		<?php
		self::render_columns_script();
	}

	/**
	 * Script inline exécuté pendant l'analyse du document (avant l'init de select2
	 * au DOMContentLoaded) : remplace le menu déroulant natif par un nuage de cases
	 * à cocher (le `<select multiple>` + select2 se referme à chaque clic — choix
	 * acté de le remplacer), pré-coche le jeu essentiel, retire les 3 lignes
	 * natives devenues inutiles.
	 *
	 * ⚠️ Le `<select>` natif est GARDÉ dans le DOM (juste masqué, jamais select2
	 * — sa classe wc-enhanced-select est retirée avant que select2 ne s'initialise) :
	 * wc-product-export.js lit `$('.woocommerce-exporter-columns').val()` à chaque
	 * lot d'export pour construire `selected_columns`, c'est donc lui qui reste la
	 * source de vérité. Chaque case à cocher se contente de bascule `option.selected`.
	 */
	private static function render_columns_script() {
		$book_ids  = wp_json_encode( array_values( self::BOOK_IDS ) );
		$essential = wp_json_encode( array_values( self::ESSENTIAL_IDS ) );
		?>
		<style>
		.pf-export-columns-cloud { display: flex; flex-wrap: wrap; gap: 0 20px; margin-top: 6px; }
		.pf-export-columns-group { flex: 1 1 260px; min-width: 220px; border: 1px solid #dcdcde; border-radius: 4px; padding: 8px 12px 10px; margin: 0 0 10px; display: flex; flex-wrap: wrap; align-items: center; gap: 6px 16px; }
		.pf-export-columns-group legend { font-weight: 600; padding: 0 4px 2px; flex-basis: 100%; }
		/* .form-table td fieldset label (wp-admin/css/forms.css) impose display:inline-block
		   à spécificité plus élevée qu'un simple sélecteur de classe : sans ce qualificatif
		   par le groupe parent (gain de spécificité plutôt que !important, cf. CLAUDE.md,
		   discipline CSS), la case suivante s'enchaînait sur la même ligne sans marge. */
		.pf-export-columns-group .pf-export-columns-item { display: inline-flex; align-items: center; flex: 0 0 auto; padding: 2px 0; font-size: 13px; font-weight: normal; white-space: nowrap; }
		.pf-export-columns-item input { margin: 0 4px 0 0; }
		</style>
		<script>
		( function() {
			var bookIds   = <?php echo $book_ids; ?>;
			var essential = <?php echo $essential; ?>;
			var select    = document.getElementById( 'woocommerce-exporter-columns' );

			if ( select ) {
				// 'downloads'/'attributes' : deux <option> codées en dur par le gabarit
				// natif, hors columns() — retirées (choix acté), jamais juste reléguées.
				var removeIds = { downloads: true, attributes: true };
				var bookSet = {}, essentialSet = {};
				bookIds.forEach( function( id ) { bookSet[ id ] = true; } );
				essential.forEach( function( id ) { essentialSet[ id ] = true; } );

				Array.prototype.slice.call( select.options ).forEach( function( opt ) {
					if ( removeIds[ opt.value ] ) opt.remove();
				} );

				select.classList.remove( 'wc-enhanced-select' );
				select.style.display = 'none';

				var cloud = document.createElement( 'div' );
				cloud.className = 'pf-export-columns-cloud';

				var makeGroup = function( label, opts ) {
					if ( ! opts.length ) return;
					var fs = document.createElement( 'fieldset' );
					fs.className = 'pf-export-columns-group';
					var legend = document.createElement( 'legend' );
					legend.textContent = label;
					fs.appendChild( legend );
					opts.forEach( function( opt ) {
						var item = document.createElement( 'label' );
						item.className = 'pf-export-columns-item';
						var cb = document.createElement( 'input' );
						cb.type    = 'checkbox';
						cb.value   = opt.value;
						cb.checked = opt.selected;
						cb.addEventListener( 'change', function() {
							opt.selected = cb.checked;
						} );
						item.appendChild( cb );
						item.appendChild( document.createTextNode( ' ' + opt.textContent ) );
						fs.appendChild( item );
					} );
					cloud.appendChild( fs );
				};

				var livreOpts = [], autresOpts = [];
				Array.prototype.slice.call( select.options ).forEach( function( opt ) {
					opt.selected = !! essentialSet[ opt.value ];
					( bookSet[ opt.value ] ? livreOpts : autresOpts ).push( opt );
				} );

				makeGroup( 'Livre', livreOpts );
				makeGroup( 'Autres', autresOpts );

				select.parentNode.insertBefore( cloud, select.nextSibling );
			}

			[ 'woocommerce-exporter-types', 'woocommerce-exporter-category', 'woocommerce-exporter-meta' ].forEach( function( id ) {
				var el  = document.getElementById( id );
				var row = el ? el.closest( 'tr' ) : null;
				if ( row ) row.remove();
			} );
		} )();
		</script>
		<?php
	}
}

Passiflore_Product_Export::init();
