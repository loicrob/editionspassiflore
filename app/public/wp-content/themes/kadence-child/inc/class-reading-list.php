<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Passiflore_Reading_List — « Liste de lecture »
 *
 * Permet à un client connecté d'enregistrer des livres pour plus tard.
 *
 * - Bouton toggle sur la fiche livre (rendu par render_button()).
 * - Section dédiée dans « Mon compte » (endpoint /liste-lecture), affichée
 *   via l'étagère 3D [passiflore_etagere].
 *
 * Stockage : user meta `_pf_reading_list` = tableau d'IDs produit.
 * Réservé aux utilisateurs connectés (pas de variante invité).
 *
 * AJAX action: pf_reading_list_toggle — ajoute/retire selon l'état courant.
 */
class Passiflore_Reading_List {

	const META_KEY      = '_pf_reading_list';
	const ENDPOINT      = 'liste-lecture';
	const NONCE         = 'pf_reading_list';
	const FLUSH_OPTION  = 'pf_rl_endpoint_v';
	const FLUSH_VERSION = '1';
	const ICON_ADD_PATH    = 'm480-240-168 72q-40 17-76-6.5T200-241v-519q0-33 23.5-56.5T280-840h200q17 0 28.5 11.5T520-800q0 17-11.5 28.5T480-760H280v518l200-86 200 86v-238q0-17 11.5-28.5T720-520q17 0 28.5 11.5T760-480v239q0 43-36 66.5t-76 6.5l-168-72Zm0-520H280h240-40Zm200 80h-40q-17 0-28.5-11.5T600-720q0-17 11.5-28.5T640-760h40v-40q0-17 11.5-28.5T720-840q17 0 28.5 11.5T760-800v40h40q17 0 28.5 11.5T840-720q0 17-11.5 28.5T800-680h-40v40q0 17-11.5 28.5T720-600q-17 0-28.5-11.5T680-640v-40Z';
	const ICON_REMOVE_PATH = 'M640-680q-17 0-28.5-11.5T600-720q0-17 11.5-28.5T640-760h160q17 0 28.5 11.5T840-720q0 17-11.5 28.5T800-680H640ZM480-240l-168 72q-40 17-76-6.5T200-241v-519q0-33 23.5-56.5T280-840h200q17 0 28.5 11.5T520-800q0 17-11.5 28.5T480-760H280v518l200-86 200 86v-238q0-17 11.5-28.5T720-520q17 0 28.5 11.5T760-480v239q0 43-36 66.5t-76 6.5l-168-72Zm0-520H280h240-40Z';

	public function __construct() {
		add_action( 'init', [ $this, 'add_endpoint' ] );
		add_action( 'init', [ $this, 'maybe_flush' ], 99 );
		add_filter( 'query_vars', [ $this, 'add_query_var' ], 0 );
		add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ] );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ $this, 'render_account' ] );
		add_action( 'wp_ajax_pf_reading_list_toggle', [ $this, 'ajax_toggle' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
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
		$ids     = self::get_ids( $user_id );
		$pos     = array_search( $product_id, $ids, true );

		if ( $pos !== false ) {
			unset( $ids[ $pos ] );
			$ids = array_values( $ids );
			$in  = false;
		} else {
			$ids[] = $product_id;
			$in    = true;
		}

		update_user_meta( $user_id, self::META_KEY, $ids );

		wp_send_json_success( [ 'in_list' => $in, 'count' => count( $ids ) ] );
	}

	/* ─── Section « Mon compte » ─────────────────────────────────── */

	public function render_account() {
		$ids = array_values( array_filter( self::get_ids(), function ( $id ) {
			$product = wc_get_product( $id );
			return $product && $product->get_status() === 'publish';
		} ) );

		if ( empty( $ids ) ) {
			printf(
				'<div class="pf-readlist-empty"><p>%s</p><a class="button" href="%s">%s</a></div>',
				esc_html( 'Votre liste de lecture est vide.' ),
				esc_url( wc_get_page_permalink( 'shop' ) ),
				esc_html( 'Parcourir le catalogue' )
			);
			if ( function_exists( 'pf_reco_render' ) ) {
				echo '<section class="pf-reco"><h2 class="pf-titre-2">Nos suggestions pour vous</h2>' . pf_reco_render() . '</section>';
			}
			return;
		}

		echo do_shortcode( '[passiflore_etagere ids="' . implode( ',', $ids ) . '" mode="scroll" show_price="true"]' );

		if ( function_exists( 'pf_reco_render' ) ) {
			echo '<section class="pf-reco"><h2 class="pf-titre-2">Vous aimerez aussi</h2>' . pf_reco_render() . '</section>';
		}
	}

	/* ─── Endpoint + menu compte ─────────────────────────────────── */

	public function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public function add_query_var( $vars ) {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	/** Insère « Ma liste de lecture » juste après le tableau de bord. */
	public function add_menu_item( $items ) {
		$new = [];
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$new[ self::ENDPOINT ] = 'Ma liste de lecture';
			}
		}
		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new = [ self::ENDPOINT => 'Ma liste de lecture' ] + $new;
		}
		return $new;
	}

	/** Flush des règles de réécriture une seule fois après l'ajout de l'endpoint. */
	public function maybe_flush() {
		if ( get_option( self::FLUSH_OPTION ) !== self::FLUSH_VERSION ) {
			flush_rewrite_rules( false );
			update_option( self::FLUSH_OPTION, self::FLUSH_VERSION );
		}
	}

	/* ─── Assets ─────────────────────────────────────────────────── */

	public function enqueue_assets() {
		$is_product = is_product();
		$is_account = function_exists( 'is_account_page' ) && is_account_page();
		if ( ! $is_product && ! $is_account ) {
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

		if ( $is_product && is_user_logged_in() ) {
			wp_enqueue_script(
				'pf-reading-list',
				$uri . '/assets/js/reading-list.js',
				[],
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
