<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Passiflore_Mes_Avis — « Avis laissés »
 *
 * Espace de suivi des avis déposés par un client dans « Mon compte »
 * (endpoint /avis-laisses). Liste les avis (commentaires produit) de
 * l'utilisateur connecté avec leur statut, permet de les modifier ou
 * supprimer, et affiche les réponses de l'éditeur.
 *
 * Contrainte : un avis n'est rattaché à un compte que par `user_id`
 * (le formulaire ayant retiré l'email). Seuls les avis déposés en étant
 * connecté apparaissent ici ; les avis d'invités ne sont pas traçables.
 *
 * Pattern calqué sur Passiflore_Reading_List (class-reading-list.php).
 *
 * AJAX : pf_avis_edit (modifie + repasse en modération), pf_avis_delete (corbeille).
 * Admin : case « Réponse publique ? » sur l'écran d'édition d'un commentaire-réponse.
 */
class Passiflore_Mes_Avis {

	const ENDPOINT          = 'avis-laisses';
	const NONCE             = 'pf_mes_avis';
	const FLUSH_OPTION      = 'pf_avis_endpoint_v';
	const FLUSH_VERSION     = '2';
	const META_USER_DELETED = '_pf_user_deleted';
	const META_REPLY_PUBLIC = '_pf_reply_public';

	public function __construct() {
		add_action( 'init', [ $this, 'add_endpoint' ] );
		add_action( 'init', [ $this, 'maybe_flush' ], 99 );
		add_filter( 'query_vars', [ $this, 'add_query_var' ], 0 );
		add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ] );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ $this, 'render_account' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_pf_avis_edit', [ $this, 'ajax_edit' ] );
		add_action( 'wp_ajax_pf_avis_delete', [ $this, 'ajax_delete' ] );

		// Admin : case « Réponse publique ? » sur les commentaires-réponses des produits.
		add_action( 'add_meta_boxes_comment', [ $this, 'add_reply_meta_box' ] );
		add_action( 'edit_comment', [ $this, 'save_reply_meta' ] );
	}

	/* ─── Récupération des avis du client ────────────────────────── */

	/**
	 * Avis du client, structurés et triés du + récent au + ancien.
	 *
	 * @return array[] { comment: WP_Comment, product: WC_Product, status: string, reply: ?array }
	 */
	public static function get_user_reviews( $user_id = null ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return [];
		}

		$base = [
			'user_id'      => $user_id,
			'post_type'    => 'product',
			'type__not_in' => [ 'pingback', 'trackback' ],
			'parent'       => 0,
		];

		$live    = get_comments( array_merge( $base, [ 'status' => 'all' ] ) );   // approuvés + en attente
		$trashed = get_comments( array_merge( $base, [ 'status' => 'trash' ] ) ); // refusés par l'éditeur

		// Masquer les avis supprimés par le client lui-même (≠ refus de l'éditeur).
		$trashed = array_filter( $trashed, function ( $c ) {
			return ! get_comment_meta( $c->comment_ID, self::META_USER_DELETED, true );
		} );

		$reviews = [];
		foreach ( array_merge( $live, $trashed ) as $c ) {
			$product = wc_get_product( $c->comment_post_ID );
			if ( ! $product ) {
				continue;
			}
			$reviews[] = [
				'comment' => $c,
				'product' => $product,
				'status'  => self::status_of( $c ),
				'reply'   => self::get_reply( $c->comment_ID ),
			];
		}

		usort( $reviews, function ( $a, $b ) {
			return strtotime( $b['comment']->comment_date_gmt ) <=> strtotime( $a['comment']->comment_date_gmt );
		} );

		return $reviews;
	}

	private static function status_of( $c ) {
		if ( 'trash' === $c->comment_approved ) return 'refuse';
		if ( '1' === $c->comment_approved )     return 'publie';
		return 'attente';
	}

	/** Première réponse approuvée de l'éditeur (texte + date), ou null. Affichée dans le compte quelle que soit la case « Réponse publique ? ». */
	private static function get_reply( $comment_id ) {
		$replies = get_comments( [
			'parent'  => $comment_id,
			'status'  => 'approve',
			'number'  => 1,
			'orderby' => 'comment_date_gmt',
			'order'   => 'ASC',
		] );
		if ( empty( $replies ) ) {
			return null;
		}
		return [
			'text' => (string) $replies[0]->comment_content,
			'date' => get_comment_date( 'j F Y', $replies[0] ),
		];
	}

	/* ─── Rendu de la section « Mon compte » ─────────────────────── */

	public function render_account() {
		$reviews = self::get_user_reviews();

		if ( empty( $reviews ) ) {
			printf(
				'<div class="pf-avis-empty"><p>%s</p><a class="button" href="%s">%s</a></div>',
				esc_html( 'Vous n\'avez pas encore déposé d\'avis sur nos livres.' ),
				esc_url( wc_get_page_permalink( 'shop' ) ),
				esc_html( 'Parcourir le catalogue' )
			);
			return;
		}

		echo '<div class="pf-avis-list">';
		foreach ( $reviews as $rev ) {
			$this->render_card( $rev );
		}
		echo '</div>';
	}

	private function render_card( array $rev ) {
		$c       = $rev['comment'];
		$product = $rev['product'];
		$status  = $rev['status'];

		$badges = [
			'publie'  => [ 'Publié', 'pf-badge--success' ],
			'attente' => [ 'En attente de validation', 'pf-badge--attente' ],
			'refuse'  => [ 'Non retenu', 'pf-badge--muted' ],
		];
		[ $badge_label, $badge_class ] = $badges[ $status ];

		$can_act = 'refuse' !== $status; // pas d'action sur un avis écarté par l'éditeur

		echo '<article class="pf-panel pf-avis-card pf-avis-card--' . esc_attr( $status ) . '" data-comment-id="' . (int) $c->comment_ID . '">';

		echo '<header class="pf-avis-card__head">';
		printf(
			'<a class="pf-avis-card__book" href="%s">%s</a>',
			esc_url( get_permalink( $c->comment_post_ID ) ),
			esc_html( $product->get_name() )
		);
		echo '<span class="pf-avis-badge pf-badge ' . esc_attr( $badge_class ) . '">' . esc_html( $badge_label ) . '</span>';
		echo '</header>';

		echo '<p class="pf-avis-card__date">' . esc_html( get_comment_date( 'j F Y', $c ) ) . '</p>';

		echo '<div class="pf-avis-card__text" data-role="text">' . nl2br( esc_html( $c->comment_content ) ) . '</div>';

		if ( $rev['reply'] ) {
			echo '<div class="pf-avis-reponse"><strong>Réponse de l\'éditeur</strong>';
			echo '<p class="pf-avis-reponse__text">' . nl2br( esc_html( $rev['reply']['text'] ) ) . '</p>';
			echo '<span class="pf-avis-reponse__date">' . esc_html( $rev['reply']['date'] ) . '</span>';
			echo '</div>';
		}

		if ( $can_act ) {
			echo '<div class="pf-avis-card__actions">';
			echo '<button type="button" class="pf-btn pf-btn--neutral pf-btn--sm" data-action="edit">Modifier</button>';
			echo '<button type="button" class="pf-btn pf-btn--neutral pf-btn--sm" data-action="delete">Supprimer</button>';
			echo '</div>';

			echo '<form class="pf-avis-edit" data-role="edit-form" hidden>';
			echo '<textarea name="content" rows="5" required>' . esc_textarea( $c->comment_content ) . '</textarea>';
			echo '<div class="pf-avis-edit__actions">';
			echo '<button type="submit" class="pf-btn pf-btn--sm">Enregistrer</button>';
			echo '<button type="button" class="pf-btn pf-btn--neutral pf-btn--sm" data-action="cancel">Annuler</button>';
			echo '</div>';
			echo '<p class="pf-avis-edit__note">Votre avis modifié repassera en validation avant d\'être publié.</p>';
			echo '</form>';
		}

		echo '</article>';
	}

	/* ─── AJAX : modifier / supprimer ses propres avis ───────────── */

	public function ajax_edit() {
		check_ajax_referer( self::NONCE, 'nonce' );
		$comment = $this->owned_comment();

		$content = isset( $_POST['content'] ) ? trim( (string) wp_unslash( $_POST['content'] ) ) : '';
		if ( '' === $content ) {
			wp_send_json_error( [ 'reason' => 'empty' ] );
		}

		wp_update_comment( [
			'comment_ID'       => $comment->comment_ID,
			'comment_content'  => $content,
			'comment_approved' => 0, // repasse en modération
		] );

		wp_send_json_success( [
			'content'     => nl2br( esc_html( $content ) ),
			'status'      => 'attente',
			'badge_label' => 'En attente de validation',
			'badge_class' => 'pf-badge--attente',
		] );
	}

	public function ajax_delete() {
		check_ajax_referer( self::NONCE, 'nonce' );
		$comment = $this->owned_comment();

		// Marqueur pour distinguer une suppression-client d'un refus éditeur (les deux sont en corbeille).
		update_comment_meta( $comment->comment_ID, self::META_USER_DELETED, 1 );
		wp_trash_comment( $comment->comment_ID );

		wp_send_json_success();
	}

	/** Charge le commentaire ciblé et vérifie qu'il appartient bien à l'utilisateur courant. */
	private function owned_comment() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'reason' => 'auth' ], 403 );
		}
		$id      = absint( $_POST['comment_id'] ?? 0 );
		$comment = $id ? get_comment( $id ) : null;
		if ( ! $comment
			|| (int) $comment->user_id !== get_current_user_id()
			|| get_post_type( $comment->comment_post_ID ) !== 'product' ) {
			wp_send_json_error( [ 'reason' => 'forbidden' ], 403 );
		}
		return $comment;
	}

	/* ─── Admin : case « Réponse publique ? » ────────────────────── */

	public function add_reply_meta_box( $comment ) {
		if ( (int) $comment->comment_parent === 0
			|| get_post_type( $comment->comment_post_ID ) !== 'product' ) {
			return;
		}
		add_meta_box( 'pf_reply_public', 'Éditions Passiflore — réponse', [ $this, 'render_reply_meta_box' ], 'comment', 'normal' );
	}

	public function render_reply_meta_box( $comment ) {
		wp_nonce_field( 'pf_reply_public_' . $comment->comment_ID, 'pf_reply_public_nonce' );
		$checked = get_comment_meta( $comment->comment_ID, self::META_REPLY_PUBLIC, true );
		echo '<p><label><input type="checkbox" name="pf_reply_public" value="1"' . checked( $checked, '1', false ) . '> ';
		echo 'Afficher cette réponse publiquement sous l\'avis, sur la fiche livre.</label></p>';
		echo '<p class="description">Décochée, la réponse reste visible uniquement par le client dans son espace « Avis laissés ».</p>';
	}

	public function save_reply_meta( $comment_id ) {
		if ( ! isset( $_POST['pf_reply_public_nonce'] )
			|| ! wp_verify_nonce( wp_unslash( $_POST['pf_reply_public_nonce'] ), 'pf_reply_public_' . $comment_id )
			|| ! current_user_can( 'moderate_comments' ) ) {
			return;
		}
		if ( ! empty( $_POST['pf_reply_public'] ) ) {
			update_comment_meta( $comment_id, self::META_REPLY_PUBLIC, '1' );
		} else {
			delete_comment_meta( $comment_id, self::META_REPLY_PUBLIC );
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

	/** Insère « Avis laissés » juste après le tableau de bord (position finale fixée par pf_account_menu_reorder()). */
	public function add_menu_item( $items ) {
		$new = [];
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$new[ self::ENDPOINT ] = 'Avis laissés';
			}
		}
		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new = [ self::ENDPOINT => 'Avis laissés' ] + $new;
		}
		return $new;
	}

	public function maybe_flush() {
		if ( get_option( self::FLUSH_OPTION ) !== self::FLUSH_VERSION ) {
			flush_rewrite_rules( false );
			update_option( self::FLUSH_OPTION, self::FLUSH_VERSION );
		}
	}

	/* ─── Assets ─────────────────────────────────────────────────── */

	public function enqueue_assets() {
		if ( ! ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
			return;
		}

		$uri = get_stylesheet_directory_uri();
		$dir = get_stylesheet_directory();

		wp_enqueue_style(
			'pf-mes-avis',
			$uri . '/assets/css/mes-avis.css',
			[],
			filemtime( $dir . '/assets/css/mes-avis.css' )
		);
		wp_enqueue_script(
			'pf-mes-avis',
			$uri . '/assets/js/mes-avis.js',
			[ 'pf-session-toast' ],
			filemtime( $dir . '/assets/js/mes-avis.js' ),
			true
		);
		wp_localize_script( 'pf-mes-avis', 'pfAvis', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE ),
		] );
	}
}

new Passiflore_Mes_Avis();
