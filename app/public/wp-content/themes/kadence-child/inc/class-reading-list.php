<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Passiflore_Reading_List — « Liste de lecture »
 *
 * Permet à un client connecté d'enregistrer des livres pour plus tard.
 *
 * - Bouton toggle sur la fiche livre (rendu par render_button()).
 * - Endpoint « Mon compte » /liste-de-lecture : étagère 3D « Ma liste de
 *   lecture » (render_shelf(), masquée si vide) puis une étagère « Catalogue ».
 *
 * Stockage : user meta `_pf_reading_list` = tableau d'IDs produit.
 * Réservé aux utilisateurs connectés (pas de variante invité).
 *
 * AJAX action: pf_reading_list_toggle — ajoute/retire selon l'état courant.
 */
class Passiflore_Reading_List {

	const META_KEY      = '_pf_reading_list';
	const NONCE         = 'pf_reading_list';
	const ENDPOINT      = 'liste-de-lecture';
	const FLUSH_OPTION  = 'pf_rl_endpoint_v';
	const FLUSH_VERSION = '3';
	const ICON_ADD_PATH    = 'm480-240-168 72q-40 17-76-6.5T200-241v-519q0-33 23.5-56.5T280-840h200q17 0 28.5 11.5T520-800q0 17-11.5 28.5T480-760H280v518l200-86 200 86v-238q0-17 11.5-28.5T720-520q17 0 28.5 11.5T760-480v239q0 43-36 66.5t-76 6.5l-168-72Zm0-520H280h240-40Zm200 80h-40q-17 0-28.5-11.5T600-720q0-17 11.5-28.5T640-760h40v-40q0-17 11.5-28.5T720-840q17 0 28.5 11.5T760-800v40h40q17 0 28.5 11.5T840-720q0 17-11.5 28.5T800-680h-40v40q0 17-11.5 28.5T720-600q-17 0-28.5-11.5T680-640v-40Z';
	const ICON_REMOVE_PATH = 'M640-680q-17 0-28.5-11.5T600-720q0-17 11.5-28.5T640-760h160q17 0 28.5 11.5T840-720q0 17-11.5 28.5T800-680H640ZM480-240l-168 72q-40 17-76-6.5T200-241v-519q0-33 23.5-56.5T280-840h200q17 0 28.5 11.5T520-800q0 17-11.5 28.5T480-760H280v518l200-86 200 86v-238q0-17 11.5-28.5T720-520q17 0 28.5 11.5T760-480v239q0 43-36 66.5t-76 6.5l-168-72Zm0-520H280h240-40Z';

	public function __construct() {
		add_action( 'init', [ $this, 'maybe_flush' ], 99 );
		add_action( 'wp_ajax_pf_reading_list_toggle', [ $this, 'ajax_toggle' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Endpoint « Mon compte » /liste-de-lecture. En passant par le filtre WC
		// `woocommerce_get_query_vars`, WooCommerce enregistre lui-même la règle
		// de réécriture (add_rewrite_endpoint) ET reconnaît l'endpoint pour la
		// détection de l'onglet actif (is_wc_endpoint_url /
		// wc_is_current_account_menu_item) — indispensable à la nav .pf-sectionnav.
		add_filter( 'woocommerce_get_query_vars', [ $this, 'add_query_var' ] );
		add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ] );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ $this, 'render_account' ] );
	}

	/* ─── Stockage ───────────────────────────────────────────────── */

	/** @return int[] IDs produit enregistrés par l'utilisateur. */
	public static function get_ids( $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return [];
		}
		$ids = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $ids ) ) {
			return [];
		}
		return array_values( array_unique( array_map( 'absint', $ids ) ) );
	}

	public static function is_in_list( $product_id, $user_id = null ) {
		return in_array( (int) $product_id, self::get_ids( $user_id ), true );
	}

	/* ─── Bouton fiche livre ─────────────────────────────────────── */

	/**
	 * HTML du bouton « Liste de lecture ». Centralise la logique
	 * connecté/déconnecté + état courant pour pouvoir être réutilisé ailleurs.
	 */
	public static function render_button( $product_id ) {
		$product_id = absint( $product_id );
		$label_add  = 'Liste de lecture';
		$label_in   = 'Retirer de la liste';
		$title_add  = 'Ajouter à ma liste de lecture';
		$title_in   = 'Retirer de ma liste de lecture';

		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<a class="bs-hero__readlist bs-hero__readlist--guest pf-btn pf-btn--outline pf-btn--icon" href="%s" title="%s">%s<span class="bs-hero__readlist-label">%s</span></a>',
				esc_url( wc_get_page_permalink( 'myaccount' ) ),
				esc_attr( 'Connectez-vous pour enregistrer ce livre dans votre liste de lecture' ),
				self::icon( false ),
				esc_html( $label_add )
			);
		}

		$in = self::is_in_list( $product_id );
		return sprintf(
			'<button type="button" class="bs-hero__readlist pf-btn pf-btn--outline pf-btn--icon%s" data-product-id="%d" data-in-list="%s" data-label-add="%s" data-label-in="%s" data-title-add="%s" data-title-in="%s" data-icon-add="%s" data-icon-in="%s" title="%s" aria-pressed="%s">%s<span class="bs-hero__readlist-label">%s</span></button>',
			$in ? ' is-in-list' : '',
			$product_id,
			$in ? '1' : '0',
			esc_attr( $label_add ),
			esc_attr( $label_in ),
			esc_attr( $title_add ),
			esc_attr( $title_in ),
			esc_attr( self::ICON_ADD_PATH ),
			esc_attr( self::ICON_REMOVE_PATH ),
			esc_attr( $in ? $title_in : $title_add ),
			$in ? 'true' : 'false',
			self::icon( $in ),
			esc_html( $in ? $label_in : $label_add )
		);
	}

	/** Icône bookmark_add (pas dans la liste) / bookmark_remove (déjà dans la liste). */
	private static function icon( $in ) {
		$path = $in ? self::ICON_REMOVE_PATH : self::ICON_ADD_PATH;
		return '<svg class="bs-hero__readlist-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="' . esc_attr( $path ) . '"/></svg>';
	}

	/* ─── AJAX toggle ────────────────────────────────────────────── */

	public function ajax_toggle() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'reason' => 'auth' ], 403 );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );
		if ( ! $product_id || get_post_type( $product_id ) !== 'product' ) {
			wp_send_json_error();
		}

		$user_id = get_current_user_id();

		// Verrou consultatif MySQL : sérialise le read-modify-write de la meta
		// pour un même utilisateur. Le front sérialise déjà ses requêtes ; ce
		// verrou protège en plus des accès concurrents (multi-onglets) qui
		// s'écraseraient sinon (lost update). Vidage du cache meta pour lire
		// sous verrou la valeur fraîche laissée par une requête précédente.
		global $wpdb;
		$lock = 'pf_rl_' . $user_id;
		$got  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 5 ) );

		wp_cache_delete( $user_id, 'user_meta' );
		$ids = self::get_ids( $user_id );
		$pos = array_search( $product_id, $ids, true );

		if ( $pos !== false ) {
			unset( $ids[ $pos ] );
			$ids = array_values( $ids );
			$in  = false;
		} else {
			$ids[] = $product_id;
			$in    = true;
		}

		update_user_meta( $user_id, self::META_KEY, $ids );

		if ( $got ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}

		$payload = [ 'in_list' => $in, 'count' => count( $ids ) ];

		// La page /liste-de-lecture demande le HTML frais de la section « Ma liste
		// de lecture » pour la reconstruire côté client (rendu depuis la meta déjà
		// mise à jour → pas de course toggle→rebuild). Toujours non vide (étagère
		// ou message d'état vide) → permutation par simple remplacement.
		if ( ! empty( $_POST['with_shelf'] ) ) {
			$payload['shelf_html'] = self::render_shelf();
		}

		wp_send_json_success( $payload );
	}

	/* ─── Endpoint « Mon compte » /liste-de-lecture ──────────────── */

	/** Déclare la query var de l'endpoint via le mécanisme natif WooCommerce. */
	public function add_query_var( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Ajoute l'item « Liste de lecture » au menu du compte. Son positionnement
	 * (juste sous « Accueil ») est fixé par pf_account_menu_reorder().
	 */
	public function add_menu_item( $items ) {
		$items[ self::ENDPOINT ] = 'Liste de lecture';
		return $items;
	}

	/**
	 * Contenu de l'endpoint : l'étagère « Ma liste de lecture » (masquée si la
	 * liste est vide) puis une étagère « Catalogue » (tout le catalogue, tri par
	 * défaut, signets actifs) pour enrichir la liste sans quitter la page.
	 */
	public function render_account() {
		$cat_display = function_exists( 'pf_shelf_display_pref' ) ? pf_shelf_display_pref( 'catalogue' ) : 'covers';
		$switch      = function_exists( 'pf_shelf_display_switch' ) ? pf_shelf_display_switch( 'catalogue', $cat_display ) : '';
		echo '<div class="pf-liste-lecture">';
		echo self::render_shelf(); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<section class="pf-reco pf-account-catalogue">'
			. '<div class="pf-shelf-head"><h2 class="pf-titre-3">Catalogue</h2>' . $switch . '</div>'
			. '<div class="pf-shelf-slot" data-pf-shelf-slot="catalogue">'
			. do_shortcode( '[passiflore_etagere mode="shelves" display="' . $cat_display . '" show_price="true" show-bookmarks="true"]' )
			. '</div>'
			. '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';
	}

	/**
	 * Section « Ma liste de lecture », rendue en tête de l'endpoint
	 * /liste-de-lecture. Liste vide (ou aucun livre publié) → message d'état
	 * vide invitant à ajouter des signets ; sinon l'étagère 3D précédée du switch
	 * d'affichage Couvertures | Dos (uniquement quand il y a une étagère). La
	 * section porte TOUJOURS la classe .pf-account-reco--readlist (jamais de
	 * chaîne vide) pour que shelf-bookmarks.js puisse permuter message ⇄ étagère
	 * par simple remplacement après un toggle de signet (ajax_toggle + with_shelf).
	 */
	public static function render_shelf( $display = null ) {
		// Sans affichage explicite (rendu initial de la page, reconstruction après
		// un toggle de signet) → préférence mémorisée de l'utilisateur.
		if ( null === $display ) {
			$display = function_exists( 'pf_shelf_display_pref' ) ? pf_shelf_display_pref( 'readlist' ) : 'covers';
		}
		$ids     = self::published_list_ids();
		$has     = ! empty( $ids );
		$display = ( 'spines' === $display ) ? 'spines' : 'covers';

		$inner  = $has ? self::shelf_shortcode( $ids, $display ) : self::empty_notice();
		$switch = ( $has && function_exists( 'pf_shelf_display_switch' ) )
			? pf_shelf_display_switch( 'readlist', $display )
			: '';

		return '<section class="pf-account-reco pf-reco pf-account-reco--readlist">'
			. '<div class="pf-shelf-head"><h2 class="pf-titre-3">Ma liste de lecture</h2>' . $switch . '</div>'
			. '<div class="pf-shelf-slot" data-pf-shelf-slot="readlist">' . $inner . '</div>'
			. '</section>';
	}

	/**
	 * Intérieur de la section (étagère ou message d'état vide), pour le re-rendu
	 * AJAX du switch d'affichage (pf_shelf_display → recommendations.php).
	 */
	public static function render_shelf_inner( $display = 'covers' ) {
		$ids     = self::published_list_ids();
		$display = ( 'spines' === $display ) ? 'spines' : 'covers';
		return empty( $ids ) ? self::empty_notice() : self::shelf_shortcode( $ids, $display );
	}

	/** IDs produit de la liste de lecture, filtrés aux produits publiés. */
	private static function published_list_ids() {
		return array_values( array_filter( self::get_ids(), function ( $id ) {
			$product = wc_get_product( $id );
			return $product && $product->get_status() === 'publish';
		} ) );
	}

	/** Étagère 3D de la liste de lecture (couvertures ou dos). */
	private static function shelf_shortcode( array $ids, $display ) {
		return do_shortcode( '[passiflore_etagere ids="' . implode( ',', $ids ) . '" mode="shelves" display="' . $display . '" show_price="true" show-bookmarks="true"]' );
	}

	/** Message d'état vide (à la place de l'étagère). */
	private static function empty_notice() {
		return '<p class="pf-liste-lecture-empty">Votre liste de lecture est vide actuellement.<br>Vous pouvez <strong>mettre des marque-pages</strong> aux livres ci-dessous pour <strong>commencer à la remplir</strong>&nbsp;!</p>';
	}

	/* ─── Réécriture ─────────────────────────────────────────────── */

	/**
	 * Flush unique des règles de réécriture après (ré)enregistrement de
	 * l'endpoint /liste-de-lecture. WooCommerce ajoute sa règle sur `init` ;
	 * WordPress ne relit pas les règles ajoutées à chaud tant que le cache en
	 * base existe → bumper FLUSH_VERSION force une régénération unique.
	 */
	public function maybe_flush() {
		if ( get_option( self::FLUSH_OPTION ) !== self::FLUSH_VERSION ) {
			flush_rewrite_rules( false );
			update_option( self::FLUSH_OPTION, self::FLUSH_VERSION );
		}
	}

	/* ─── Assets ─────────────────────────────────────────────────── */

	public function enqueue_assets() {
		// Le bouton « Liste de lecture » ne vit que sur la fiche livre ;
		// l'étagère de l'accueil du compte s'appuie sur bookshelf.css/account.css.
		if ( ! is_product() ) {
			return;
		}

		$uri = get_stylesheet_directory_uri();
		$dir = get_stylesheet_directory();

		wp_enqueue_style(
			'pf-reading-list',
			$uri . '/assets/css/reading-list.css',
			[],
			filemtime( $dir . '/assets/css/reading-list.css' )
		);

		if ( is_user_logged_in() ) {
			wp_enqueue_script(
				'pf-reading-list',
				$uri . '/assets/js/reading-list.js',
				[ 'pf-session-toast' ],
				filemtime( $dir . '/assets/js/reading-list.js' ),
				true
			);
			wp_localize_script( 'pf-reading-list', 'pfRL', [
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE ),
			] );
		}
	}
}

new Passiflore_Reading_List();
