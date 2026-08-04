<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ═══════════════════════════════════════════════════════════════
   Fiche livre — sections avec nav latérale sticky
   ═══════════════════════════════════════════════════════════════ */

add_filter( 'woocommerce_product_tabs', '__return_empty_array' );
add_action( 'woocommerce_after_single_product_summary', 'passiflore_render_sections_layout', 10 );

// Avis (commentaires produit) : anti-spam à la soumission (honeypot + piège temporel) + nom requis
add_filter( 'preprocess_comment', 'passiflore_avis_spam_check' );

// Tous les avis produit sont systématiquement mis en attente de modération,
// quel que soit le statut de l'auteur (contourne l'auto-approbation des comptes ayant déjà des avis approuvés).
// Exception : les réponses de l'éditeur (comment_parent != 0 — le formulaire public
// n'offre pas de « répondre », donc une réponse ne peut venir que de l'admin). Sans
// cette exception, « Approuver et répondre » depuis wp-admin approuve l'avis mais la
// réponse elle-même naît en attente (jamais visible en front, cf.
// passiflore_avis_public_reply qui exige status=approve) et l'email de notification
// (wp_insert_comment, qui ne se redéclenche pas à une approbation manuelle ultérieure)
// ne part jamais.
add_filter( 'pre_comment_approved', function ( $approved, $commentdata ) {
	if ( 'spam' === $approved
		|| empty( $commentdata['comment_post_ID'] )
		|| get_post_type( (int) $commentdata['comment_post_ID'] ) !== 'product' ) {
		return $approved;
	}
	$is_reply_by_moderator = ! empty( $commentdata['comment_parent'] )
		&& ! empty( $commentdata['user_id'] )
		&& user_can( (int) $commentdata['user_id'], 'moderate_comments' );
	return $is_reply_by_moderator ? $approved : '0';
}, 10, 2 );

// Bug WooCommerce (Reviews::handle_reply_to_review(), src/Internal/Admin/ProductReviews/Reviews.php) :
// cette méthode intercepte wp_ajax_replyto-comment en priorité -1 pour tout avis produit
// (avant le cœur WP, qui ne s'exécute alors jamais) et compare `$parent->comment_post_ID`
// (string brute issue de WP_Comment) à `$comment_post_ID` (int) avec `===` strict — donc
// toujours faux. Résultat : le bouton « Approuver et répondre » de l'écran Boutique →
// Avis publie la réponse mais n'approuve JAMAIS l'avis d'origine. Plugin tiers non modifiable
// ici (voir CLAUDE.md) → on approuve nous-mêmes le parent, en priorité AVANT ce hook (-2),
// pour que sa propre vérification défaillante n'ait plus rien à faire (déjà approuvé =
// no-op silencieux de son côté, aucun conflit).
add_action( 'wp_ajax_replyto-comment', 'passiflore_avis_fix_approve_parent', -2 );

function passiflore_avis_fix_approve_parent() {
	if ( empty( $_POST['approve_parent'] ) || empty( $_POST['comment_ID'] ) ) {
		return;
	}
	if ( ! check_ajax_referer( 'replyto-comment', '_ajax_nonce-replyto-comment', false ) ) {
		return;
	}
	$parent = get_comment( absint( $_POST['comment_ID'] ) );
	if ( ! $parent
		|| '0' !== (string) $parent->comment_approved
		|| get_post_type( (int) $parent->comment_post_ID ) !== 'product'
		|| (int) $parent->comment_post_ID !== (int) ( $_POST['comment_post_ID'] ?? 0 )
		|| ! current_user_can( 'edit_comment', $parent->comment_ID ) ) {
		return;
	}
	wp_set_comment_status( $parent, 'approve' );
}

// Après soumission, renvoyer vers la section avis (l'ancre #comment-XX par défaut n'existe pas dans notre rendu)
add_filter( 'comment_post_redirect', 'passiflore_avis_redirect', 10, 2 );

function passiflore_avis_redirect( $location, $comment ) {
	if ( $comment && get_post_type( $comment->comment_post_ID ) === 'product' ) {
		// `unapproved` / `moderation-hash` : args que le cœur ajoute juste AVANT ce
		// filtre (wp-comments-post.php) pour l'ancien bloc « en attente » à usage
		// unique. L'auteur connecté voit désormais son avis dans la liste à chaque
		// visite (cf. include_unapproved plus bas) — plus rien ne les lit.
		$location = remove_query_arg( [ 'unapproved', 'moderation-hash' ], $location );
		$location = preg_replace( '/#.*$/', '', $location ) . '#avis-des-lecteurs';
	}
	return $location;
}

// Dépôt en AJAX (passiflore_ajax_avis_submit, plus bas) : le chemin classique
// ci-dessous (redirection via wp_die_handler + wp_die() anti-spam) ne doit pas
// s'y déclencher, sous peine de renvoyer un 302/HTML là où le client attend du
// JSON. wp_handle_comment_submission() appelé avec $wp_error=true fait déjà
// remonter les échecs du cœur (flood, doublon, commentaire vide…) en WP_Error
// plutôt qu'en wp_die() — seuls nos propres contrôles doivent donc s'effacer.
function passiflore_avis_is_ajax_submit(): bool {
	return wp_doing_ajax() && isset( $_POST['action'] ) && 'pf_avis_submit' === $_POST['action'];
}

// En cas d'échec de soumission d'un avis, renvoyer vers la section avis avec le motif
// (au lieu de la page wp_die brute). Couvre nos contrôles ET les erreurs du core (avis vide, flood, doublon…).
add_action( 'pre_comment_on_post', 'passiflore_avis_catch_errors' );

function passiflore_avis_catch_errors( $post_id ) {
	if ( get_post_type( $post_id ) !== 'product' || passiflore_avis_is_ajax_submit() ) {
		return;
	}
	add_filter( 'wp_die_handler', function () use ( $post_id ) {
		return function ( $message, $title = '', $args = [] ) use ( $post_id ) {
			if ( is_wp_error( $message ) ) {
				$message = $message->get_error_message();
			}
			$text = trim( wp_strip_all_tags( (string) $message ) );
			if ( $text === '' ) {
				$text = 'Votre avis n\'a pas pu être envoyé.';
			}
			$token = wp_generate_password( 20, false ); // alphanumérique
			set_transient( 'pf_avis_err_' . $token, [
				'msg'     => $text,
				'author'  => isset( $_POST['author'] ) ? (string) wp_unslash( $_POST['author'] ) : '',
				'comment' => isset( $_POST['comment'] ) ? (string) wp_unslash( $_POST['comment'] ) : '',
			], 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'avis_erreur', $token, get_permalink( $post_id ) ) . '#avis-des-lecteurs' );
			exit;
		};
	} );
}

function passiflore_avis_spam_check( $commentdata ) {
	$post_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
	if ( ! $post_id || get_post_type( $post_id ) !== 'product' ) {
		return $commentdata;
	}

	// Les modérateurs (réponses depuis l'admin, etc.) ne passent pas par les pièges
	if ( current_user_can( 'moderate_comments' ) ) {
		return $commentdata;
	}

	// Le dépôt AJAX (passiflore_ajax_avis_submit) a déjà rejoué ces mêmes pièges
	// en amont, avant d'appeler wp_handle_comment_submission() — avec une erreur
	// JSON propre en cas d'échec. Les rejouer ici finirait en wp_die(), que le
	// client ne sait pas interpréter.
	if ( passiflore_avis_is_ajax_submit() ) {
		return $commentdata;
	}

	$rejet = function () {
		wp_die( 'Votre avis n\'a pas pu être envoyé.', 'Erreur', [ 'response' => 403, 'back_link' => true ] );
	};

	// 1. Honeypot : champ caché rempli => bot
	if ( ! empty( $_POST['pf_hp'] ) ) {
		$rejet();
	}

	// 2. Piège temporel : timestamp signé, soumission trop rapide après affichage => bot
	$min_seconds = 4;
	$raw           = isset( $_POST['pf_ts'] ) ? (string) wp_unslash( $_POST['pf_ts'] ) : '';
	[ $ts, $sig ]  = array_pad( explode( ':', $raw, 2 ), 2, '' );
	$signature_ok  = $ts !== '' && hash_equals( hash_hmac( 'sha256', $ts, wp_salt( 'auth' ) ), $sig );
	if ( ! $signature_ok || ( time() - (int) $ts ) < $min_seconds ) {
		$rejet();
	}

	// 3. Nom obligatoire (email retiré)
	if ( '' === trim( (string) ( $commentdata['comment_author'] ?? '' ) ) ) {
		wp_die(
			'Veuillez indiquer votre nom pour publier un avis.',
			'Nom requis',
			[ 'response' => 200, 'back_link' => true ]
		);
	}

	return $commentdata;
}


/* ─── Notifications par email au client (validation + réponse de l'éditeur) ─── */

// Email au client quand son avis passe en « publié ».
add_action( 'transition_comment_status', 'passiflore_avis_notify_approved', 10, 3 );

function passiflore_avis_notify_approved( $new_status, $old_status, $comment ) {
	if ( 'approved' !== $new_status || 'approved' === $old_status ) {
		return;
	}
	if ( get_post_type( $comment->comment_post_ID ) !== 'product' || (int) $comment->comment_parent !== 0 ) {
		return; // seuls les avis de premier niveau déclenchent ce message
	}
	if ( ! is_email( $comment->comment_author_email ) ) {
		return;
	}

	$titre = get_the_title( $comment->comment_post_ID );
	$lien  = get_permalink( $comment->comment_post_ID ) . '#avis-des-lecteurs';

	$sujet   = sprintf( 'Votre avis sur « %s » est publié', $titre );
	$message = "Bonjour,\n\n"
		. sprintf( "Votre avis sur « %s » vient d'être validé et est désormais publié sur notre site.\n\n", $titre )
		. sprintf( "Vous pouvez le consulter ici :\n%s\n\n", $lien )
		. "Merci pour votre contribution.\n\n"
		. "— Éditions Passiflore";

	wp_mail( $comment->comment_author_email, $sujet, $message );
}

// Email au client quand l'éditeur répond à son avis.
add_action( 'wp_insert_comment', 'passiflore_avis_notify_reply', 10, 2 );

function passiflore_avis_notify_reply( $comment_id, $comment ) {
	if ( (int) $comment->comment_parent === 0
		|| get_post_type( $comment->comment_post_ID ) !== 'product'
		|| (int) $comment->comment_approved !== 1 ) {
		return;
	}
	$parent = get_comment( $comment->comment_parent );
	if ( ! $parent || ! is_email( $parent->comment_author_email ) ) {
		return;
	}
	if ( get_comment_meta( $comment_id, '_pf_reply_notified', true ) ) {
		return;
	}
	update_comment_meta( $comment_id, '_pf_reply_notified', 1 );

	$titre = get_the_title( $comment->comment_post_ID );
	$lien  = get_permalink( $comment->comment_post_ID ) . '#avis-des-lecteurs';

	$sujet   = sprintf( "L'éditeur a répondu à votre avis sur « %s »", $titre );
	$message = "Bonjour,\n\n"
		. sprintf( "Les Éditions Passiflore ont répondu à l'avis que vous avez déposé sur « %s ».\n\n", $titre )
		. sprintf( "Vous pouvez consulter la réponse ici :\n%s\n\n", $lien )
		. "— Éditions Passiflore";

	wp_mail( $parent->comment_author_email, $sujet, $message );
}


/* ─── Corbeille en cascade (avis + réponse, quelle que soit l'origine) ── */

/**
 * Quand un avis produit part à la corbeille — action « Corbeille » de l'admin
 * Commentaires, de l'écran Boutique → Avis (WooCommerce), action groupée, ou
 * l'auto-suppression ci-dessous — sa réponse doit suivre : wp_trash_comment()
 * ne cascade nativement que pour les commentaires de type 'note' (notes de
 * commande), pas 'comment'. Sans ça, la réponse de l'éditeur reste seule en
 * admin ("Approuvé", répondant à un avis qui n'apparaît plus nulle part côté
 * public — passiflore_avis_public_reply() n'est appelée que pour les avis
 * encore listés).
 *
 * Hooké sur `trashed_comment` (déclenché par TOUT appel à wp_trash_comment(),
 * quel que soit le point d'entrée) plutôt que sur une action UI précise.
 */
add_action( 'trashed_comment', 'passiflore_avis_cascade_trash_replies', 10, 2 );

function passiflore_avis_cascade_trash_replies( $comment_id, $comment ) {
	if ( (int) $comment->comment_parent !== 0 || get_post_type( $comment->comment_post_ID ) !== 'product' ) {
		return;
	}
	foreach ( get_comments( [ 'parent' => $comment_id, 'status' => 'all', 'fields' => 'ids' ] ) as $child_id ) {
		// Ignore une réponse déjà à la corbeille (ex. passiflore_ajax_avis_delete, qui
		// cascade elle-même avant de trasher l'avis) : rejouer wp_trash_comment() sur
		// un commentaire déjà en corbeille écrase _wp_trash_meta_status avec 'trash'
		// et casse la restauration.
		if ( 'trash' !== wp_get_comment_status( $child_id ) ) {
			wp_trash_comment( $child_id );
		}
	}
}


/* ─── Suppression d'un avis par son auteur (fiche livre) ────── */

/**
 * L'auteur connecté supprime son propre avis depuis la fiche livre où il l'a
 * déposé (il n'y a pas d'espace de suivi dans « Mon compte » : l'avis se gère là
 * où il se lit). Mise en corbeille, donc restaurable depuis wp-admin.
 */
add_action( 'wp_enqueue_scripts', 'passiflore_avis_delete_assets' );

function passiflore_avis_delete_assets() {
	// Le bouton n'est rendu que sur son propre avis : rien à charger pour un visiteur
	// déconnecté. Priorité par défaut : le src est fourni, donc `pf-session-toast`
	// (enregistré dans functions.php) est résolu à l'impression, pas ici.
	if ( ! is_product() || ! is_user_logged_in() ) {
		return;
	}

	wp_enqueue_script(
		'pf-avis-delete',
		get_stylesheet_directory_uri() . '/assets/js/avis-delete.js',
		[ 'pf-session-toast' ],
		filemtime( get_stylesheet_directory() . '/assets/js/avis-delete.js' ),
		true
	);
	wp_localize_script( 'pf-avis-delete', 'pfAvisDelete', [
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'pf_avis_delete' ),
		'strings'  => [
			'confirm'     => 'Voulez-vous vraiment supprimer votre avis ?',
			'cancel_btn'  => 'Annuler',
			'confirm_btn' => 'Confirmer',
			'deleted'     => 'Votre avis a bien été supprimé.',
			'error'       => 'Votre avis n’a pas pu être supprimé.',
		],
	] );
}

add_action( 'wp_ajax_pf_avis_delete', 'passiflore_ajax_avis_delete' );
// `nopriv` alors que l'action exige une session : sans lui, un clic dont la session a
// expiré entre-temps reçoit d'admin-ajax un « 0 » brut en HTTP 400 — que le client ne
// peut pas distinguer d'une réponse JSON et avale en silence (le visiteur clique, rien
// ne se passe). Ici la requête atteint le gestionnaire, qui répond 403 → toast de session.
add_action( 'wp_ajax_nopriv_pf_avis_delete', 'passiflore_ajax_avis_delete' );

function passiflore_ajax_avis_delete() {
	// 403 ⇒ « votre session a expiré » côté client (pf-session-toast.js) : réservé au
	// nonce (que check_ajax_referer refuse lui-même en 403) et à l'absence de session.
	// Tout autre refus part en 404, sinon un rejet légitime (double-clic, avis déjà
	// supprimé) s'annoncerait à tort comme une session périmée.
	check_ajax_referer( 'pf_avis_delete', 'nonce' );

	$uid = get_current_user_id();
	if ( ! $uid ) {
		wp_send_json_error( [ 'reason' => 'auth' ], 403 );
	}

	$comment = get_comment( absint( $_POST['comment_id'] ?? 0 ) );
	if ( ! $comment
		|| (int) $comment->user_id !== $uid
		|| (int) $comment->comment_parent !== 0
		|| get_post_type( $comment->comment_post_ID ) !== 'product'
		// Publié ('1') ou en attente ('0') SEULEMENT. Un avis écarté par l'éditeur
		// ('trash'/'spam') n'est plus le sien à reprendre — et surtout wp_trash_comment()
		// rejoué écraserait _wp_trash_meta_status avec 'trash', ce qui casserait
		// définitivement la restauration depuis la corbeille.
		|| ! in_array( (string) $comment->comment_approved, [ '1', '0' ], true ) ) {
		wp_send_json_error( [ 'reason' => 'gone' ], 404 );
	}

	// Les réponses suivent leur avis : wp_trash_comment() ne cascade que sur les
	// commentaires de type 'note', donc sans ça une réponse de l'éditeur survivrait
	// à l'avis auquel elle répond (invisible en front, orpheline en admin).
	foreach ( get_comments( [ 'parent' => $comment->comment_ID, 'status' => 'all', 'fields' => 'ids' ] ) as $child ) {
		wp_trash_comment( $child );
	}

	if ( ! wp_trash_comment( $comment->comment_ID ) ) {
		wp_send_json_error( [ 'reason' => 'failed' ], 500 );
	}

	wp_send_json_success();
}


/* ─── Dépôt d'un avis en AJAX (fiche livre) ──────────────────── */

/**
 * Le formulaire se poste en AJAX pour éviter le rechargement de page : le nouvel
 * avis (en attente de validation) est renvoyé en HTML et ajouté à la liste par
 * le JS. Amélioration progressive : sans JS, le formulaire poste normalement
 * (comment_form() → wp-comments-post.php, chemin classique inchangé).
 */
add_action( 'wp_enqueue_scripts', 'passiflore_avis_submit_assets' );

function passiflore_avis_submit_assets() {
	if ( ! is_product() ) {
		return;
	}

	wp_enqueue_script(
		'pf-avis-submit',
		get_stylesheet_directory_uri() . '/assets/js/avis-submit.js',
		[ 'pf-session-toast' ],
		filemtime( get_stylesheet_directory() . '/assets/js/avis-submit.js' ),
		true
	);
	wp_localize_script( 'pf-avis-submit', 'pfAvisSubmit', [
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'pf_avis_submit' ),
		'strings'  => [
			'success' => 'Merci d’avoir partagé votre avis ! Il est désormais en attente de validation par l’équipe de Passiflore.',
			'error'   => 'Votre avis n’a pas pu être envoyé.',
		],
	] );
}

add_action( 'wp_ajax_pf_avis_submit', 'passiflore_ajax_avis_submit' );
// nopriv : le dépôt d'avis est ouvert aux visiteurs non connectés (nom requis, email retiré).
add_action( 'wp_ajax_nopriv_pf_avis_submit', 'passiflore_ajax_avis_submit' );

function passiflore_ajax_avis_submit() {
	check_ajax_referer( 'pf_avis_submit', 'nonce' );

	$post_id = isset( $_POST['comment_post_ID'] ) ? absint( $_POST['comment_post_ID'] ) : 0;
	if ( ! $post_id || get_post_type( $post_id ) !== 'product' ) {
		wp_send_json_error( [ 'message' => 'Votre avis n’a pas pu être envoyé.' ] );
	}

	// Mêmes pièges anti-spam que le chemin classique (passiflore_avis_spam_check,
	// qui s'efface pendant ce dépôt AJAX — cf. passiflore_avis_is_ajax_submit) :
	// honeypot, piège temporel, nom obligatoire. Rejoués ici pour renvoyer une
	// erreur JSON propre plutôt que le wp_die() du chemin sans JS.
	if ( ! current_user_can( 'moderate_comments' ) ) {
		if ( ! empty( $_POST['pf_hp'] ) ) {
			wp_send_json_error( [ 'message' => 'Votre avis n’a pas pu être envoyé.' ] );
		}
		$min_seconds  = 4;
		$raw          = isset( $_POST['pf_ts'] ) ? (string) wp_unslash( $_POST['pf_ts'] ) : '';
		[ $ts, $sig ] = array_pad( explode( ':', $raw, 2 ), 2, '' );
		$signature_ok = $ts !== '' && hash_equals( hash_hmac( 'sha256', $ts, wp_salt( 'auth' ) ), $sig );
		if ( ! $signature_ok || ( time() - (int) $ts ) < $min_seconds ) {
			wp_send_json_error( [ 'message' => 'Votre avis n\'a pas pu être envoyé.' ] );
		}
		// Le nom n'est exigé que pour un invité : connecté, le champ est disabled
		// (rempli par le compte) donc absent du POST — comme dans le chemin
		// classique, où c'est comment_author déjà résolu par
		// wp_handle_comment_submission() qu'on vérifierait, pas le POST brut.
		if ( ! is_user_logged_in() && '' === trim( (string) wp_unslash( $_POST['author'] ?? '' ) ) ) {
			wp_send_json_error( [ 'message' => 'Veuillez indiquer votre nom pour publier un avis.' ] );
		}
	}

	// Même fonction cœur que le chemin classique (wp-comments-post.php),
	// appelée ici directement : mêmes hooks (preprocess_comment,
	// pre_comment_approved…), mais $wp_error=true fait remonter les échecs du
	// cœur (flood, doublon, commentaire vide…) en WP_Error plutôt qu'en wp_die().
	$result = wp_handle_comment_submission( wp_unslash( $_POST ) );

	if ( is_wp_error( $result ) ) {
		// Le statut HTTP de la WP_Error n'est délibérément PAS transmis : certains
		// (comment_closed, not_logged_in…) valent 403, et le client réserve ce
		// statut à « session expirée » (check_ajax_referer) — cf. politique de
		// nonce du projet. Un rejet légitime partirait sinon à tort pour ça.
		$msg = wp_strip_all_tags( $result->get_error_message() );
		wp_send_json_error( [ 'message' => '' !== $msg ? $msg : 'Votre avis n\'a pas pu être envoyé.' ] );
	}

	do_action( 'set_comment_cookies', $result, wp_get_current_user(), isset( $_POST['wp-comment-cookies-consent'] ) );

	ob_start();
	passiflore_render_avis_item( passiflore_avis_entry_from_comment( $result ), false );
	$html = ob_get_clean();

	wp_send_json_success( [ 'html' => $html ] );
}

/**
 * Nettoyage unique par environnement après le retrait de l'espace « Avis laissés »
 * (endpoint /mon-compte/avis-laisses). Idiome maison (cf. maybe_purge_cover_colors) :
 * la règle de réécriture de l'endpoint survit dans l'option `rewrite_rules` en cache,
 * et les deux métas n'ont plus aucun lecteur. Rien à lancer en WP-CLI.
 * À retirer une fois déployé sur tous les environnements.
 */
add_action( 'admin_init', 'passiflore_avis_cleanup_endpoint' );

function passiflore_avis_cleanup_endpoint() {
	if ( ! get_option( 'pf_avis_endpoint_v' ) ) {
		return;
	}
	delete_option( 'pf_avis_endpoint_v' );
	delete_metadata( 'comment', 0, '_pf_reply_public', '', true ); // case « Réponse publique ? » supprimée
	delete_metadata( 'comment', 0, '_pf_user_deleted', '', true ); // marqueur sans lecteur
	flush_rewrite_rules( false );
}


/* ─── Événements liés à un produit ──────────────────────────── */

// Événements partagés entre tous les formats d'un même format_groupe : un
// événement lié à l'édition classique doit aussi apparaître sur la fiche
// grands-caractères / numérique du même titre.
function passiflore_get_product_events( int $product_id ): array {
	global $wpdb;

	$group_ids = passiflore_get_format_groupe_product_ids( $product_id );

	$rows = $wpdb->get_results(
		"SELECT p.ID, pm.meta_value
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_pf_event_books'
		 WHERE p.post_type = 'tribe_events' AND p.post_status = 'publish'",
		ARRAY_A
	);

	$event_ids = [];
	foreach ( $rows as $row ) {
		$books = maybe_unserialize( $row['meta_value'] );
		if ( is_array( $books ) && array_intersect( array_map( 'intval', $books ), $group_ids ) ) {
			$event_ids[] = (int) $row['ID'];
		}
	}

	if ( empty( $event_ids ) ) return [ 'past' => [], 'upcoming' => [] ];

	$now      = current_time( 'mysql' );
	$past     = [];
	$upcoming = [];

	foreach ( $event_ids as $eid ) {
		$start = get_post_meta( $eid, '_EventStartDate', true );
		$end   = get_post_meta( $eid, '_EventEndDate',   true );

		if ( $end && $end < $now ) {
			if ( get_field( 'evenement_marquant', $eid ) ) {
				$past[] = [ 'id' => $eid, 'start' => $start ];
			}
		} elseif ( $start && $start >= $now ) {
			$upcoming[] = [ 'id' => $eid, 'start' => $start ];
		}
	}

	usort( $past,     fn( $a, $b ) => strcmp( $b['start'], $a['start'] ) );
	usort( $upcoming, fn( $a, $b ) => strcmp( $a['start'], $b['start'] ) );

	return [
		'past'     => array_column( $past,     'id' ),
		'upcoming' => array_column( $upcoming, 'id' ),
	];
}


/**
 * Libellés « réels » du repeater SCF `distinctions` d'un livre — SOURCE UNIQUE.
 * Une ligne du repeater compte comme vide dès que son texte ne contient AUCUN
 * caractère alphanumérique (pas seulement une chaîne strictement vide) : le
 * champ `distinctions` de SCF stocke le NOMBRE de lignes, pas leur contenu, et
 * un éditeur peut créer une ligne puis la laisser blanche (ou n'y taper que de
 * la ponctuation) sans que ça se voie dans ce compteur. Utilisée par la
 * section « Distinctions » ci-dessous, par le filtre catalogue/étagère
 * "decouvrir=distinctions" (Passiflore_Bookshelf::apply_decouvrir_filter) et
 * par l'onglet admin « Tri des livres avec distinction »
 * (pf_bg_distinctions_eligible_reps) — les trois doivent s'accorder sur ce
 * qu'est un livre "avec distinction", sous peine de lister/afficher un livre
 * dans l'un sans qu'il apparaisse dans l'autre.
 */
function pf_distinction_labels( int $id ): array {
	if ( ! function_exists( 'get_field' ) ) return [];
	$rows = get_field( 'distinctions', $id );
	if ( ! is_array( $rows ) ) return [];
	$out = [];
	foreach ( $rows as $row ) {
		$d = isset( $row['distinction'] ) ? trim( (string) $row['distinction'] ) : '';
		if ( $d !== '' && preg_match( '/[\p{L}\p{N}]/u', $d ) ) {
			$out[] = $d;
		}
	}
	return $out;
}

/**
 * Distinctions "de l'œuvre" — agrège pf_distinction_labels() sur toutes les
 * éditions du format_groupe, même logique que passiflore_collect_group_repeater()
 * pour avis/presse/vidéos/podcasts (author-books-grouping.php) : une
 * distinction saisie sur UNE SEULE édition (ex. grands caractères) doit
 * s'afficher sur toutes, y compris le représentant classique affiché par
 * défaut dans le catalogue/l'étagère — sans quoi le médaillon de l'étagère
 * pointerait vers une œuvre "distinguée" dont la fiche n'affiche pourtant
 * aucune distinction (le représentant, lui, n'en porte peut-être aucune en
 * propre).
 */
function pf_distinction_labels_shared( int $id ): array {
	$group_ids = function_exists( 'passiflore_get_format_groupe_product_ids' )
		? passiflore_get_format_groupe_product_ids( $id )
		: [ $id ];
	$seen = [];
	$out  = [];
	foreach ( $group_ids as $gid ) {
		foreach ( pf_distinction_labels( $gid ) as $label ) {
			$norm = mb_strtolower( preg_replace( '/[\s\x{00A0}]+/u', ' ', trim( $label ) ) );
			if ( isset( $seen[ $norm ] ) ) continue;
			$seen[ $norm ] = true;
			$out[] = $label;
		}
	}
	return $out;
}

/**
 * IDs de TOUTES LES ÉDITIONS des œuvres ayant au moins une distinction réelle
 * sur N'IMPORTE LAQUELLE de leurs éditions — source unique pour le filtre
 * catalogue/étagère "decouvrir=distinctions"
 * (Passiflore_Bookshelf::apply_decouvrir_filter) ET pour l'éligibilité de
 * l'onglet admin « Tri des livres avec distinction »
 * (pf_bg_distinctions_eligible_reps, qui déduplique ensuite par représentant).
 *
 * ⚠️ Étendu à TOUTES les éditions de l'œuvre, pas seulement son représentant :
 * apply_format_filter() applique ensuite DEUX mécanismes différents selon le
 * format demandé — intersection avec les représentants en format par défaut,
 * mais tax_query sur pa_format_particulier pour un format précis (numérique,
 * grands caractères…), qui ne connaît PAS la notion de représentant. Si
 * post__in ne contenait que le représentant, une distinction saisie sur une
 * édition non représentante ferait disparaître l'œuvre du format par défaut
 * (le représentant n'a lui-même aucune distinction) ET l'édition qui la porte
 * du filtre par format (pas dans post__in). pf_distinction_labels_shared()
 * ci-dessus assure la réciproque à l'affichage.
 *
 * Pré-filtre SQL bon marché (compteur de lignes SCF, rapide) puis vérification
 * du contenu réel via pf_distinction_labels() (le compteur ne baisse pas
 * quand une ligne est vidée sans être supprimée).
 */
function pf_distinction_book_ids(): array {
	$ids = get_posts( [
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => [ [ 'key' => 'distinctions', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ] ],
	] );
	$real = array_filter(
		array_map( 'intval', $ids ),
		fn( $id ) => ! empty( pf_distinction_labels( $id ) )
	);
	if ( ! function_exists( 'passiflore_get_format_groupe_product_ids' ) ) return array_values( $real );

	$expanded = [];
	foreach ( $real as $id ) {
		foreach ( passiflore_get_format_groupe_product_ids( $id ) as $eid ) {
			$expanded[ $eid ] = true;
		}
	}
	return array_keys( $expanded );
}

/**
 * Icône « distinction » (médaille) — SOURCE UNIQUE. Utilisée par la section
 * « Distinctions » ci-dessous ET par le bouton d'infobulle des étagères
 * filtrées par distinctions (`Passiflore_Bookshelf::distinctions_html()`), qui
 * doit porter exactement la même icône.
 */
function pf_distinction_icon( $class = 'bs-distinctions-icon' ) {
	return '<svg class="' . esc_attr( $class ) . '" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="m438-452-58-57q-11-11-27.5-11T324-508q-11 11-11 28t11 28l86 86q12 12 28 12t28-12l170-170q12-12 11.5-28T636-592q-12-12-28.5-12.5T579-593L438-452ZM326-90l-58-98-110-24q-15-3-24-15.5t-7-27.5l11-113-75-86q-10-11-10-26t10-26l75-86-11-113q-2-15 7-27.5t24-15.5l110-24 58-98q8-13 22-17.5t28 1.5l104 44 104-44q14-6 28-1.5t22 17.5l58 98 110 24q15 3 24 15.5t7 27.5l-11 113 75 86q10 11 10 26t-10 26l-75 86 11 113q2 15-7 27.5T802-212l-110 24-58 98q-8 13-22 17.5T584-74l-104-44-104 44q-14 6-28 1.5T326-90Zm52-72 102-44 104 44 56-96 110-26-10-112 74-84-74-86 10-112-110-24-58-96-102 44-104-44-56 96-110 24 10 112-74 86 74 84-10 114 110 24 58 96Zm102-318Z"/></svg>';
}


/* ─── Layout principal ───────────────────────────────────────── */

function passiflore_render_sections_layout() {
	if ( ! is_product() ) return;
	global $product;
	if ( ! $product ) return;
	$id = $product->get_id();

	$sections = [];

	// ── Distinctions ─────────────────────────────────────
	// Partagées entre toutes les éditions du format_groupe (comme presse/vidéos/
	// podcasts/avis ci-dessous) : consulter n'importe quelle édition doit montrer
	// les mêmes distinctions, même si elles ont été saisies sur une autre.
	$distinctions = pf_distinction_labels_shared( $id );
	if ( $distinctions ) {
		$icon  = pf_distinction_icon();
		$html  = '<ul class="bs-distinctions">';
		foreach ( $distinctions as $d ) {
			$html .= '<li>' . $icon . esc_html( $d ) . '</li>';
		}
		$html .= '</ul>';
		// L'id reste `bs-distinctions` : c'est l'ancre de la nav de section, donc
		// une URL partageable — seul le libellé change.
		$sections[] = [ 'id' => 'bs-distinctions', 'label' => 'Distinctions', 'html' => $html ];
	}

	// ── Résumé ──────────────────────────────────────────────────
	$description = $product->get_description();
	if ( $description ) {
		$sections[] = [
			'id'    => 'bs-resume',
			'label' => 'Résumé',
			'html'  => '<div class="bs-section__body entry-content">' . apply_filters( 'the_content', $description ) . '</div>',
		];
	}

	// ── Caractéristiques ─────────────────────────────────────────
	ob_start();
	passiflore_tab_caracteristiques();
	$caract_html = trim( ob_get_clean() );
	if ( $caract_html ) {
		$sections[] = [ 'id' => 'bs-caract', 'label' => 'Caractéristiques', 'html' => $caract_html ];
	}

	// ── Auteurs ──────────────────────────────────────────────────
	ob_start();
	$auteurs_has = passiflore_render_auteurs_section( $id );
	$auteurs_html = ob_get_clean();
	if ( $auteurs_has ) {
		$contributions  = get_field( 'contributions', $id );
		$contrib_tids   = [];
		$contrib_total  = 0;
		if ( is_array( $contributions ) ) {
			foreach ( $contributions as $row ) {
				$contrib_total++;
				if ( ( $row['assignation'] ?? '' ) === 'fiche-auteur' ) {
					foreach ( (array) ( $row['fiche-auteur'] ?? [] ) as $item ) {
						$tid = is_object( $item ) ? (int) $item->term_id : absint( $item );
						if ( $tid ) $contrib_tids[] = $tid;
					}
				}
			}
		}
		$all_f = ! empty( $contrib_tids );
		foreach ( $contrib_tids as $tid ) {
			$g = get_field( 'genre', 'auteur_' . $tid );
			if ( ! in_array( $g, [ 'f', 'feminin', 'Féminin' ], true ) ) { $all_f = false; break; }
		}
		$label = $contrib_total <= 1
			? ( $all_f ? 'Autrice' : 'Auteur' )
			: ( $all_f ? 'Autrices' : 'Auteurs' );

		$sections[] = [ 'id' => 'bs-auteurs', 'label' => $label, 'html' => $auteurs_html ];
	}

	// Contenus partagés entre tous les formats d'un même format_groupe
	// (avis, presse, vidéos, podcasts, événements).
	$group_ids = passiflore_get_format_groupe_product_ids( $id );

	// ── Articles de presse ───────────────────────────────────────
	$presse = passiflore_collect_group_repeater( $group_ids, 'articles_de_presse', [ 'titre', 'lien', 'fichier' ] );
	if ( $presse ) {
		ob_start();
		passiflore_render_presse_section( $presse );
		$sections[] = [ 'id' => 'bs-presse', 'label' => 'Articles de presse', 'html' => ob_get_clean() ];
	}

	// ── Vidéos ──────────────────────────────────────────────────
	$videos = passiflore_collect_group_repeater( $group_ids, 'videos', [ 'titre', 'lien', 'fichier_video' ] );
	if ( $videos ) {
		ob_start();
		passiflore_render_videos_section( $videos );
		$sections[] = [ 'id' => 'bs-videos', 'label' => 'Vidéos', 'html' => ob_get_clean() ];
	}

	// ── Podcasts ─────────────────────────────────────────────────
	$podcasts = passiflore_collect_group_repeater( $group_ids, 'podcasts', [ 'titre', 'lien', 'fichier_audio' ] );
	if ( $podcasts ) {
		ob_start();
		passiflore_render_podcasts_section( $podcasts );
		$sections[] = [ 'id' => 'bs-podcasts', 'label' => 'Podcasts', 'html' => ob_get_clean() ];
	}

	// ── Avis des lecteurs (sélection éditeur SCF + avis du site, fusionnés) ──
	// `include_unapproved` (cœur WP) ajoute à la liste des avis publiés le ou les
	// avis EN ATTENTE de l'utilisateur courant : la clause devient
	// `( comment_approved = '1' ) OR ( user_id = N AND comment_approved = '0' )`
	// → un avis écarté par l'éditeur (`trash`/`spam`) reste invisible pour tous,
	// y compris son auteur, sans contrôle supplémentaire.
	// ⚠️ JAMAIS `[ 0 ]` : `user_id = 0` désigne TOUS les invités, ce qui publierait
	// chaque avis d'invité en attente de modération (wp_parse_list() ne filtre pas
	// le 0 et is_numeric( 0 ) est vrai). D'où le tableau vide si personne n'est connecté.
	$avis_uid     = get_current_user_id();
	$avis_l       = passiflore_collect_avis_scf( $group_ids, 'avis_des_lecteurs' );
	$site_reviews = get_comments( [
		'post__in'           => $group_ids,
		'status'             => 'approve',
		'parent'             => 0, // exclut les réponses de l'éditeur (rattachées à leur avis)
		'type__not_in'       => [ 'pingback', 'trackback' ],
		'include_unapproved' => $avis_uid ? [ $avis_uid ] : [],
	] );

	$avis_lecteurs = passiflore_merge_avis( $avis_l, $site_reviews );

	if ( $avis_lecteurs || comments_open( $id ) ) {
		ob_start();
		passiflore_render_avis_lecteurs( $id, $avis_lecteurs );
		$sections[] = [ 'id' => 'bs-avis-lecteurs', 'label' => 'Avis des lecteurs', 'html' => ob_get_clean() ];
	}

	// ── Avis des libraires ───────────────────────────────────────
	$avis_lb = passiflore_collect_avis_scf( $group_ids, 'avis_des_libraires' );
	if ( $avis_lb ) {
		ob_start();
		passiflore_render_avis_items( passiflore_normalize_avis_scf( $avis_lb ), 'libraires' );
		$sections[] = [ 'id' => 'bs-avis-libraires', 'label' => 'Avis des libraires', 'html' => ob_get_clean() ];
	}

	// ── Événements marquants / à venir ──────────────────────────
	$product_events = passiflore_get_product_events( $id );

	if ( ! empty( $product_events['upcoming'] ) ) {
		$tiles = array_map( [ Passiflore_Event_Tiles::class, 'normalize_event' ], $product_events['upcoming'] );
		$sections[] = [ 'id' => 'bs-evenements-a-venir', 'label' => 'Événements à venir', 'html' => Passiflore_Event_Tiles::render_row( $tiles ) ];
	}

	if ( ! empty( $product_events['past'] ) ) {
		$tiles = array_map( [ Passiflore_Event_Tiles::class, 'normalize_event' ], $product_events['past'] );
		$sections[] = [ 'id' => 'bs-evenements-marquants', 'label' => 'Événements marquants', 'html' => Passiflore_Event_Tiles::render_row( $tiles ) ];
	}

	// ── Livres associés ──────────────────────────────────────────
	$sections = array_merge( $sections, passiflore_get_livres_lies_sections( $id ) );

	if ( empty( $sections ) ) return;

	// ── Rendu (composant partagé) ────────────────────────────────
	echo pf_render_sectionnav( $sections );
}


/* ─── Section Auteurs ────────────────────────────────────────── */

function passiflore_render_auteur_card_texte( string $name ): string {
	$svg = '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="width:100%;height:100%;display:block"><rect width="100" height="100" fill="#f0ebe3"/><circle cx="50" cy="37" r="20" fill="#c0ad8e"/><ellipse cx="50" cy="88" rx="34" ry="24" fill="#c0ad8e"/></svg>';
	ob_start();
	?>
	<div class="pf-card pf-card--static pf-auteur-card pf-card--compact pf-auteur-card--texte">
		<div class="pf-auteur-photo"><?= $svg ?></div>
		<div class="pf-card-content">
			<span class="pf-card-title">
				<span><?= esc_html( $name ) ?></span>
			</span>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

function passiflore_render_auteurs_section( int $id ): bool {
	$contributions = get_field( 'contributions', $id );
	$nom_de_plume  = get_field( 'nom_de_plume', $id );
	$illus         = get_field( 'illustration_de_couverture', $id );

	$type_labels = [
		'traduction'   => 'Traduction',
		'illustration' => 'Illustration',
		'preface'      => 'Préface',
		'postface'     => 'Postface',
		'photographie' => 'Photographie',
	];

	$all_rows = [];

	if ( have_rows( 'contributions', $id ) ) {
		while ( have_rows( 'contributions', $id ) ) {
			the_row();
			$type        = get_sub_field( 'type' ) ?: '';
			$assignation = get_sub_field( 'assignation' ) ?: '';
			$type_label  = $type === 'auteur' ? '' : ( $type_labels[ $type ] ?? ucfirst( str_replace( '-', ' ', $type ) ) );

			if ( $assignation === 'fiche-auteur' ) {
				foreach ( (array) get_sub_field( 'fiche-auteur' ) as $item ) {
					$tid = is_object( $item ) ? (int) $item->term_id : absint( $item );
					if ( $tid ) $all_rows[] = [ 'kind' => 'fiche', 'tid' => $tid, 'role' => $type_label ];
				}
			} elseif ( $assignation === 'champ-texte' ) {
				$n = get_sub_field( 'field_69cd3251156af' ); // nom_de_l'auteur (curly apostrophe in DB)
				if ( $n ) $all_rows[] = [ 'kind' => 'texte', 'name' => $n, 'role' => $type_label ];
			}
		}
	}

	$has_content = $all_rows || $nom_de_plume || $illus;
	if ( ! $has_content ) return false;

	echo '<div class="bs-tab-auteurs">';

	if ( $all_rows ) {
		echo '<div class="pf-scroll-fade">';
		echo '<div class="pf-event-auteurs-scroll pf-hscroll">';
		foreach ( $all_rows as $r ) {
			echo '<div class="bs-auteur-wrapper">';
			if ( $r['kind'] === 'fiche' ) {
				echo passiflore_render_auteur_card( $r['tid'], [ 'variant' => 'compact', 'show_bio' => true ] );
			} else {
				echo passiflore_render_auteur_card_texte( $r['name'] );
			}
			if ( $r['role'] ) {
				echo '<p class="bs-auteur-role pf-label">' . esc_html( $r['role'] ) . '</p>';
			}
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';
	}

	if ( $nom_de_plume ) {
		echo '<div class="bs-auteur-meta">';
		echo '<p class="pf-label">Nom de plume</p>';
		echo '<p class="bs-auteur-meta-value">' . esc_html( $nom_de_plume ) . '</p>';
		echo '</div>';
	}

	if ( $illus ) {
		echo '<div class="bs-auteur-meta">';
		echo '<p class="pf-label">Illustration de couverture</p>';
		echo '<p class="bs-auteur-meta-value">' . esc_html( $illus ) . '</p>';
		echo '</div>';
	}

	echo '</div>';
	return true;
}


/* ─── Sections Presse / Vidéos / Podcasts ────────────────────── */

function passiflore_render_presse_section( array $items ): void {
	$seuil = 3;
	echo '<div class="bs-media-grid">';
	foreach ( $items as $i => $article ) {
		$hidden = $i >= $seuil ? ' bs-item--hidden' : '';
		$url    = '';
		if ( ( $article['type'] ?? '' ) === 'lien' && ! empty( $article['lien'] ) ) {
			$url = $article['lien'];
		} elseif ( ( $article['type'] ?? '' ) === 'fichier' && ! empty( $article['fichier'] ) ) {
			$url = (string) wp_get_attachment_url( $article['fichier'] );
		}
		echo '<div class="pf-card' . ( $url ? '' : ' pf-card--static' ) . $hidden . '">';
		if ( $url ) {
			echo '<a class="pf-card-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( pf_new_window_label( $article['titre'] ?: 'Lire l’article' ) ) . '"></a>';
		}
		echo '<div class="pf-card-content">';
		if ( ! empty( $article['titre'] ) ) {
			echo '<strong class="pf-card-title">' . esc_html( $article['titre'] ) . '</strong>';
		}
		if ( ! empty( $article['description'] ) ) {
			echo '<p class="pf-card-text">' . nl2br( esc_html( $article['description'] ) ) . '</p>';
		}
		echo '</div></div>';
	}
	echo '</div>';
	if ( count( $items ) > $seuil ) {
		echo '<button class="bs-voir-tout pf-btn pf-btn--neutral pf-btn--sm" type="button">Voir tout (' . ( count( $items ) - $seuil ) . ')</button>';
	}
}

/**
 * Détecte si une URL pointe directement vers un fichier média lisible
 * nativement par le navigateur (d'après l'extension), pour les liens
 * que l'oEmbed ne sait pas embarquer. Retourne 'video', 'audio' ou ''.
 */
function passiflore_direct_media_type( string $url ): string {
	$path = (string) parse_url( $url, PHP_URL_PATH );
	$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	if ( in_array( $ext, [ 'mp4', 'webm', 'ogv', 'm4v', 'mov' ], true ) ) return 'video';
	if ( in_array( $ext, [ 'mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac' ], true ) ) return 'audio';
	return '';
}

/**
 * Dimensions [largeur, hauteur] d'un embed oEmbed depuis les attributs de
 * l'iframe, pour épouser le format réel de la vidéo (aspect-ratio + plafond
 * de hauteur via --bs-ar côté CSS). Retourne [w, h] (floats) ou [] si
 * indétectable.
 */
function passiflore_embed_dimensions( string $html ): array {
	if ( preg_match( '/\bwidth=["\'](\d+(?:\.\d+)?)["\']/', $html, $w )
		&& preg_match( '/\bheight=["\'](\d+(?:\.\d+)?)["\']/', $html, $h )
		&& (float) $h[1] > 0 ) {
		return [ (float) $w[1], (float) $h[1] ];
	}
	return [];
}

/**
 * wp_oembed_get() mis en cache (transient). wp_oembed_get() refait un appel
 * HTTP au fournisseur à chaque rendu : quand cette requête échoue ou expire
 * (réseau, lenteur, rate-limit), elle renvoie false et on retombe sur le lien
 * — d'où des embeds qui apparaissent « au hasard » selon les rechargements.
 * On met les succès en cache une semaine ; les échecs ne sont pas mis en cache
 * (nouvelle tentative au prochain chargement, jusqu'à ce que ça passe).
 */
function passiflore_cached_oembed( string $url ): string {
	$key  = 'pf_oembed_' . md5( $url );
	$html = get_transient( $key );
	if ( is_string( $html ) ) {
		return $html;
	}
	$html = wp_oembed_get( $url );
	if ( $html ) {
		set_transient( $key, $html, WEEK_IN_SECONDS );
		return $html;
	}
	return '';
}

function passiflore_render_videos_section( array $items ): void {
	$seuil = 3;
	echo '<div class="bs-media-grid">';
	foreach ( $items as $i => $video ) {
		$hidden = $i >= $seuil ? ' bs-item--hidden' : '';
		$url    = '';
		$media  = '';

		if ( ( $video['type'] ?? '' ) === 'fichier-video' && ! empty( $video['fichier_video'] ) ) {
			$file_url = wp_get_attachment_url( $video['fichier_video'] );
			if ( $file_url ) $media = '<div class="bs-media-figure"><video class="bs-media-video" controls preload="metadata" src="' . esc_url( $file_url ) . '"></video></div>';
		} elseif ( ! empty( $video['lien'] ) ) {
			$embed = passiflore_cached_oembed( $video['lien'] );
			if ( $embed ) {
				$dims  = passiflore_embed_dimensions( $embed );
				$style = '';
				if ( $dims ) {
					$style = ' style="aspect-ratio:' . esc_attr( $dims[0] . ' / ' . $dims[1] )
						. ';--bs-ar:' . esc_attr( round( $dims[0] / $dims[1], 4 ) ) . '"';
				}
				$media = '<div class="bs-media-figure"><div class="bs-media-embed"' . $style . '>' . $embed . '</div></div>';
			} elseif ( passiflore_direct_media_type( $video['lien'] ) === 'video' ) {
				$media = '<div class="bs-media-figure"><video class="bs-media-video" controls preload="metadata" src="' . esc_url( $video['lien'] ) . '"></video></div>';
			} else {
				$url = $video['lien'];
			}
		}

		echo '<div class="pf-card' . ( $url ? '' : ' pf-card--static' ) . $hidden . '">';
		if ( $url ) {
			echo '<a class="pf-card-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( pf_new_window_label( $video['titre'] ?: 'Voir la vidéo' ) ) . '"></a>';
		}
		echo '<div class="pf-card-content">';
		if ( ! empty( $video['titre'] ) ) {
			echo '<strong class="pf-card-title">' . esc_html( $video['titre'] ) . '</strong>';
		}
		if ( ! empty( $video['description'] ) ) {
			echo '<p class="pf-card-text">' . nl2br( esc_html( $video['description'] ) ) . '</p>';
		}
		echo $media;
		echo '</div></div>';
	}
	echo '</div>';
	if ( count( $items ) > $seuil ) {
		echo '<button class="bs-voir-tout pf-btn pf-btn--neutral pf-btn--sm" type="button">Voir tout (' . ( count( $items ) - $seuil ) . ')</button>';
	}
}

function passiflore_render_podcasts_section( array $items ): void {
	$seuil = 3;
	echo '<div class="bs-media-grid">';
	foreach ( $items as $i => $podcast ) {
		$hidden = $i >= $seuil ? ' bs-item--hidden' : '';
		$url    = '';
		$media  = '';

		if ( ( $podcast['type'] ?? '' ) === 'fichier-audio' && ! empty( $podcast['fichier_audio'] ) ) {
			$file_url = wp_get_attachment_url( $podcast['fichier_audio'] );
			if ( $file_url ) $media = '<div class="bs-media-figure"><audio class="bs-media-audio" controls src="' . esc_url( $file_url ) . '"></audio></div>';
		} elseif ( ( $podcast['type'] ?? '' ) === 'lien' && ! empty( $podcast['lien'] ) ) {
			$embed = passiflore_cached_oembed( $podcast['lien'] );
			if ( $embed ) {
				$media = '<div class="bs-media-figure"><div class="bs-media-embed bs-media-embed--audio">' . $embed . '</div></div>';
			} elseif ( passiflore_direct_media_type( $podcast['lien'] ) === 'audio' ) {
				$media = '<div class="bs-media-figure"><audio class="bs-media-audio" controls preload="metadata" src="' . esc_url( $podcast['lien'] ) . '"></audio></div>';
			} else {
				$url = $podcast['lien'];
			}
		}

		echo '<div class="pf-card' . ( $url ? '' : ' pf-card--static' ) . $hidden . '">';
		if ( $url ) {
			echo '<a class="pf-card-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( pf_new_window_label( $podcast['titre'] ?: 'Écouter' ) ) . '"></a>';
		}
		echo '<div class="pf-card-content">';
		if ( ! empty( $podcast['titre'] ) ) {
			echo '<strong class="pf-card-title">' . esc_html( $podcast['titre'] ) . '</strong>';
		}
		if ( ! empty( $podcast['description'] ) ) {
			echo '<p class="pf-card-text">' . nl2br( esc_html( $podcast['description'] ) ) . '</p>';
		}
		echo $media;
		echo '</div></div>';
	}
	echo '</div>';
	if ( count( $items ) > $seuil ) {
		echo '<button class="bs-voir-tout pf-btn pf-btn--neutral pf-btn--sm" type="button">Voir tout (' . ( count( $items ) - $seuil ) . ')</button>';
	}
}


/* ─── Caractéristiques ───────────────────────────────────────── */

// Formate un ISBN-13 brut (13 chiffres) en "978-2-37946-130-9". Retourne la
// valeur telle quelle si elle ne contient pas exactement 13 chiffres.
function passiflore_format_isbn13( string $isbn ): string {
	$digits = preg_replace( '/\D+/', '', $isbn );
	if ( strlen( $digits ) !== 13 ) return $isbn;
	return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 1 ) . '-' . substr( $digits, 4, 5 ) . '-' . substr( $digits, 9, 3 ) . '-' . substr( $digits, 12, 1 );
}

function passiflore_tab_caracteristiques() {
	global $product;
	$id = $product->get_id();

	$isbn       = $product->get_meta( '_global_unique_id' );
	$pages      = get_field( 'nombre_de_pages', $id );
	$date_raw   = get_field( 'date_de_parution', $id );
	$type       = get_field( 'type', $id );
	$reliure    = get_field( 'type_de_reliure', $id );
	$public_val = get_field( 'public', $id );
	$langues    = get_field( 'langues', $id );

	$type_labels = [
		'roman' => 'Roman', 'nouvelles' => 'Nouvelles', 'bande-dessinee' => 'Bande dessinée',
		'beau-livre' => 'Beau-livre', 'album-illustre' => 'Album illustré',
		'textes-courts' => 'Textes courts', 'chroniques' => 'Chroniques',
		'recit' => 'Récit', 'conte' => 'Conte', 'biographie' => 'Biographie',
		'documentaire' => 'Documentaire',
	];
	$reliure_labels = [ 'broche' => 'Broché', 'cousu' => 'Cousu' ];
	$public_labels  = [ 'tout-public' => 'Tout public', 'adulte' => 'Adulte', 'jeunesse' => 'Jeunesse' ];
	$langues_labels = [
		'francais' => 'Français', 'anglais' => 'Anglais', 'espagnol' => 'Espagnol',
		'allemand' => 'Allemand', 'occitan-gascon' => 'Occitan / Gascon', 'basque' => 'Basque',
	];

	echo '<div class="bs-tab-caract">';

	$rows = [];
	if ( $isbn ) {
		$rows[] = [ 'ISBN', passiflore_format_isbn13( $isbn ) ];
	}
	if ( $pages ) {
		$rows[] = [ 'Pages', $pages . ' pages' ];
	}
	if ( $date_raw ) {
		$date = DateTime::createFromFormat( 'Ymd', $date_raw );
		if ( $date ) {
			if ( (int) $date->format( 'j' ) === 1 ) {
				$date_value = '1<sup>er</sup> ' . esc_html( date_i18n( 'F Y', $date->getTimestamp() ) );
			} else {
				$date_value = esc_html( date_i18n( 'j F Y', $date->getTimestamp() ) );
			}
			$rows[] = [ 'Date de parution', $date_value, true ];
		}
	}
	if ( $type ) {
		$rows[] = [ 'Genre', $type_labels[ $type ] ?? ucfirst( $type ) ];
	}
	if ( $reliure ) {
		$rows[] = [ 'Reliure', $reliure_labels[ $reliure ] ?? ucfirst( $reliure ) ];
	}
	if ( $public_val ) {
		$rows[] = [ 'Public', $public_labels[ $public_val ] ?? ucfirst( $public_val ) ];
	}
	if ( $langues && is_array( $langues ) ) {
		$lang_labels = array_map( fn( $l ) => $langues_labels[ $l ] ?? ucfirst( $l ), $langues );
		$rows[] = [ 'Langue(s)', implode( ', ', $lang_labels ) ];
	}

	$weight = $product->get_weight();
	if ( $weight ) {
		$rows[] = [ 'Poids', wc_format_localized_decimal( $weight ) . ' ' . get_option( 'woocommerce_weight_unit' ) ];
	}
	$dimensions = $product->get_dimensions();
	if ( $dimensions ) {
		$rows[] = [ 'Dimensions', $dimensions ];
	}

	foreach ( $product->get_attributes() as $attr ) {
		if ( ! $attr->get_visible() ) continue;
		$label = wc_attribute_label( $attr->get_name() );
		if ( $attr->is_taxonomy() ) {
			$terms = wc_get_product_terms( $id, $attr->get_name(), [ 'fields' => 'names' ] );
			$value = implode( ', ', $terms );
		} else {
			$value = implode( ', ', $attr->get_options() );
		}
		if ( $value ) {
			$rows[] = [ $label, $value ];
		}
	}

	if ( $rows ) {
		echo '<table class="bs-caract-table">';
		foreach ( $rows as $row ) {
			[ $label, $value ] = $row;
			$is_raw = $row[2] ?? false;
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . ( $is_raw ? $value : esc_html( $value ) ) . '</td></tr>';
		}
		echo '</table>';
	}

	echo '</div>';
}


/* ─── Livres associés ───────────────────────────────────────── */

/**
 * Swap each product ID for its sibling sharing the same format_groupe term
 * and the requested pa_format_particulier slug. Falls back to the original ID
 * when no sibling exists (fallback = format principal / classique).
 * Returns $ids unchanged when $format_slug is '' (current book is classique).
 */
function passiflore_ids_in_format( array $ids, string $format_slug ): array {
	if ( $format_slug === '' || empty( $ids ) ) return $ids;

	$result = [];
	foreach ( $ids as $product_id ) {
		$fg = wp_get_object_terms( $product_id, 'format_groupe', [ 'fields' => 'ids' ] );
		if ( is_wp_error( $fg ) || empty( $fg ) ) {
			$result[] = $product_id;
			continue;
		}
		$sibling = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'tax_query'      => [
				[ 'taxonomy' => 'format_groupe',         'terms' => $fg ],
				[ 'taxonomy' => 'pa_format_particulier', 'field' => 'slug', 'terms' => $format_slug ],
			],
		] );
		if ( ! empty( $sibling ) ) {
			$result[] = (int) $sibling[0];
		} else {
			$result[] = Passiflore_Bookshelf::get_group_representative( $fg[0] ) ?? $product_id;
		}
	}
	return $result;
}

/**
 * Toutes les éditions sœurs de $id (même format_groupe) dans l'ordre canonique
 * des formats — classique en tête, puis l'ordre des termes de l'attribut.
 *
 * Une seule requête (pf_group_members) au lieu d'un get_posts par format, et
 * l'ordre vient de pf_format_order() : plus de liste en dur à resynchroniser
 * avec le résolveur de représentant.
 */
function passiflore_get_format_siblings_ordered( int $id, bool $include_self = false ): array {
	$group = pf_format_group_of( $id );
	if ( ! $group ) return [];

	$members = pf_group_members( $group );
	if ( ! $include_self ) unset( $members[ $id ] );

	return pf_group_sort_members( $members );
}

function passiflore_get_livres_lies_sections( int $id ): array {
	$fmt_terms      = wp_get_object_terms( $id, 'pa_format_particulier', [ 'fields' => 'slugs' ] );
	$current_format = ( ! is_wp_error( $fmt_terms ) && ! empty( $fmt_terms ) ) ? $fmt_terms[0] : '';

	$internal = [];

	$all_formats = passiflore_get_format_siblings_ordered( $id, true );
	if ( ! empty( $all_formats ) && count( $all_formats ) > 1 ) {
		$internal[] = [ 'title' => 'Formats du livre', 'ids' => $all_formats, 'sort_by_date' => false, 'raw_ids' => true, 'display_formats' => true ];
	}

	// Série & traductions : groupes globaux symétriques gérés via Produits →
	// Groupes de livres (taxonomies pf_serie / pf_traduction). La composition
	// vient de la taxonomie, l'ordre du term meta (drag-reorder). Les membres
	// incluent le livre courant ; on n'affiche la section qu'au-delà de 1.
	$serie_terms = wp_get_object_terms( $id, 'pf_serie', [ 'fields' => 'ids' ] );
	if ( ! is_wp_error( $serie_terms ) && ! empty( $serie_terms ) ) {
		$serie_reps = pf_bg_group_member_reps( 'pf_serie', '_pf_serie_order', (int) $serie_terms[0] );
		if ( count( $serie_reps ) > 1 ) {
			$internal[] = [ 'title' => 'Ensemble de la série', 'ids' => $serie_reps, 'sort_by_date' => false ];
		}
	}

	$trad_terms = wp_get_object_terms( $id, 'pf_traduction', [ 'fields' => 'ids' ] );
	if ( ! is_wp_error( $trad_terms ) && ! empty( $trad_terms ) ) {
		$trad_reps = pf_bg_group_member_reps( 'pf_traduction', '_pf_traduction_order', (int) $trad_terms[0] );
		if ( count( $trad_reps ) > 1 ) {
			$internal[] = [ 'title' => 'Traductions', 'ids' => $trad_reps, 'sort_by_date' => false ];
		}
	}

	// Vous aimerez aussi : reco orientée, post meta sur le représentant source.
	$aimerez_ids = (array) get_post_meta( pf_bg_representative( $id ), '_pf_vous_aimerez', true );
	$aimerez_ids = array_values( array_filter( array_map( 'intval', $aimerez_ids ) ) );
	if ( $aimerez_ids ) {
		$internal[] = [ 'title' => 'Vous aimerez aussi', 'ids' => $aimerez_ids, 'sort_by_date' => false ];
	}

	// Livres du/des même(s) auteur(s) : plusieurs groupes, mais UNE SEULE section
	// (cf. plus bas) — le libellé relationnel est porté par son titre, chaque
	// groupe n'a plus qu'un <h3> de noms.
	$auteur_groups   = [];
	$auteur_term_ids = passiflore_get_product_author_ids( $id );
	if ( $auteur_term_ids ) {
		$exclude  = [ $id ];
		$fg_terms = wp_get_object_terms( $id, 'format_groupe', [ 'fields' => 'ids' ] );
		if ( ! is_wp_error( $fg_terms ) && ! empty( $fg_terms ) ) {
			$fg_siblings = get_posts( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post__not_in'   => [ $id ],
				'tax_query'      => [ [ 'taxonomy' => 'format_groupe', 'terms' => $fg_terms ] ],
			] );
			$exclude = array_merge( $exclude, array_map( 'intval', $fg_siblings ) );
		}
		$candidates = passiflore_dedup_by_format_groupe( array_values( array_diff(
			passiflore_product_ids_by_auteur_terms( $auteur_term_ids ),
			$exclude
		) ) );
		$auteur_groups = passiflore_group_books_by_author_set( $auteur_term_ids, $candidates );
	}

	$sort_by_parution = function ( array $ids ): array {
		usort( $ids, function ( $a, $b ) {
			$da = (string) get_post_meta( $a, 'date_de_parution', true );
			$db = (string) get_post_meta( $b, 'date_de_parution', true );
			return strcmp( $db, $da );
		} );
		return $ids;
	};
	$etagere = function ( array $ids, string $extra = '' ) use ( $current_format ): string {
		$ids_str = implode( ',', array_map( 'absint', $ids ) );
		return do_shortcode( '[passiflore_etagere ids="' . $ids_str . '" mode="scroll" display="covers" nb_books_first_displayed="20"' . $extra . ']' );
	};

	$result = [];
	foreach ( $internal as $section ) {
		$ids = $section['ids'];
		if ( ! empty( $section['sort_by_date'] ) ) {
			$ids = $sort_by_parution( $ids );
		}
		if ( empty( $section['raw_ids'] ) ) {
			$ids = passiflore_ids_in_format( $ids, $current_format );
		}
		$result[]   = [
			'id'    => 'bs-lies-' . sanitize_title( $section['title'] ),
			'label' => $section['title'],
			'html'  => $etagere( $ids, ! empty( $section['display_formats'] ) ? ' display_formats="true"' : '' ),
		];
	}

	// Une section unique, donc une seule entrée dans la nav de section : son
	// titre porte la relation (« Du même auteur », « Des mêmes autrices »…),
	// accordé sur TOUS les auteurs du livre. À l'intérieur, une étagère par
	// ensemble d'auteurs, chacune coiffée d'un <h3> nommant ses auteurs — sauf
	// celle qui reprend strictement les auteurs du livre, que le titre de
	// section annonce déjà.
	if ( $auteur_groups ) {
		$html = '';
		foreach ( $auteur_groups as $group ) {
			$titre = passiflore_author_group_title( $group, $auteur_term_ids, 'book' );
			if ( '' !== $titre ) {
				$html .= '<h3>' . esc_html( $titre ) . '</h3>';
			}
			$html .= $etagere( passiflore_ids_in_format( $sort_by_parution( $group['product_ids'] ), $current_format ) );
		}
		$result[] = [
			'id'    => 'bs-lies-meme-auteur',
			'label' => passiflore_meme_auteur_label( $auteur_term_ids ),
			'html'  => $html,
		];
	}

	// Aussi en {thématique} : autres livres partageant la même sous-catégorie
	// product_cat (le rayon racine, lui, est trop large pour être pertinent).
	// Une section par thématique portée par le livre — la quasi-totalité n'en
	// ont qu'une, mais quelques-uns en ont deux.
	$theme_terms = wp_get_object_terms( $id, 'product_cat', [ 'fields' => 'all', 'orderby' => 'name' ] );
	if ( ! is_wp_error( $theme_terms ) ) {
		$self_and_siblings = passiflore_get_format_groupe_product_ids( $id );
		foreach ( $theme_terms as $term ) {
			if ( ! $term->parent ) continue; // rayon, pas une thématique

			$candidates = get_posts( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post__not_in'   => $self_and_siblings,
				'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'terms' => $term->term_id ] ],
			] );
			$candidates = passiflore_dedup_by_format_groupe( array_map( 'intval', $candidates ) );
			if ( empty( $candidates ) ) continue;

			// Minuscule initiale : le nom du terme est capitalisé (titre de
			// catégorie), mais se lit mieux en minuscule après « Aussi en ».
			$theme_label = mb_strtolower( mb_substr( $term->name, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $term->name, 1, null, 'UTF-8' );

			$result[] = [
				'id'    => 'bs-lies-' . sanitize_title( 'aussi-en-' . $term->name ),
				'label' => 'Aussi en ' . $theme_label,
				'html'  => $etagere( passiflore_ids_in_format( $sort_by_parution( $candidates ), $current_format ) ),
			];
		}
	}

	return $result;
}


/* ─── Avis ───────────────────────────────────────────────────── */

// Parse une date SCF `d/m/Y` en timestamp. Retourne 0 si vide ou illisible.
function passiflore_avis_parse_ts( string $dmy ): int {
	$dmy = trim( $dmy );
	if ( $dmy === '' ) return 0;
	$d = DateTime::createFromFormat( 'd/m/Y', $dmy );
	return $d ? $d->getTimestamp() : 0;
}

// Normalise un repeater d'avis SCF en entrées [ titre, text, auteur, date, ts ], triées du + récent au + ancien.
// Les clés `comment_id` / `own` / `pending` sont neutres ici (un avis SCF n'est pas un
// commentaire) mais doivent exister : passiflore_render_avis_items() appelle cette
// fonction en direct pour les avis des libraires, sans passer par merge_avis().
function passiflore_normalize_avis_scf( array $items ): array {
	$entries = [];
	foreach ( $items as $a ) {
		$raw = (string) ( $a['date_de_publication'] ?? '' );
		$ts  = passiflore_avis_parse_ts( $raw );
		$entries[] = [
			'titre'      => (string) ( $a['titre']  ?? '' ),
			'text'       => (string) ( $a['avis']   ?? '' ),
			'auteur'     => (string) ( $a['auteur'] ?? '' ),
			'date'       => $ts ? date_i18n( 'j F Y', $ts ) : $raw,
			'ts'         => $ts,
			'reply'      => null,
			'comment_id' => 0,
			'own'        => false,
			'pending'    => false,
		];
	}
	usort( $entries, fn( $a, $b ) => $b['ts'] <=> $a['ts'] );
	return $entries;
}

// Construit l'entrée normalisée d'UN commentaire WooCommerce — partagée par la
// fusion d'affichage (merge_avis) et par le dépôt AJAX (passiflore_ajax_avis_submit,
// qui rend l'avis fraîchement créé isolément, sans reload).
function passiflore_avis_entry_from_comment( WP_Comment $c ): array {
	$uid = get_current_user_id();
	return [
		'titre'      => '',
		'text'       => (string) $c->comment_content,
		'auteur'     => (string) $c->comment_author,
		'date'       => get_comment_date( 'j F Y', $c ),
		'ts'         => (int) strtotime( $c->comment_date_gmt . ' GMT' ),
		'reply'      => passiflore_avis_public_reply( (int) $c->comment_ID ),
		'comment_id' => (int) $c->comment_ID,
		// `own` : seul l'auteur connecté peut supprimer son avis. Un avis déposé
		// sans être connecté n'a pas de user_id → non rattachable à un compte.
		'own'        => $uid && (int) $c->user_id === $uid,
		'pending'    => '1' !== (string) $c->comment_approved,
	];
}

// Fusionne avis SCF (sélection éditeur) + commentaires WooCommerce en une liste unique triée du + récent au + ancien.
function passiflore_merge_avis( array $scf_items, array $comments ): array {
	$entries = passiflore_normalize_avis_scf( $scf_items );
	foreach ( $comments as $c ) {
		$entries[] = passiflore_avis_entry_from_comment( $c );
	}
	usort( $entries, fn( $a, $b ) => $b['ts'] <=> $a['ts'] );
	return $entries;
}

// Première réponse de l'éditeur à un avis (texte + date), ou null.
// Une réponse APPROUVÉE s'affiche ; pour la retenir, l'éditeur la laisse en attente.
function passiflore_avis_public_reply( int $comment_id ): ?array {
	$replies = get_comments( [
		'parent'  => $comment_id,
		'status'  => 'approve',
		'orderby' => 'comment_date_gmt',
		'order'   => 'ASC',
	] );
	foreach ( $replies as $r ) {
		// Seules les réponses écrites par un modérateur sont signées « Réponse de
		// l'éditeur » : le formulaire public n'offre pas de « répondre », mais
		// `comment_parent` est un champ caché falsifiable et le cœur autorise la
		// réponse à un avis approuvé — sans ce contrôle, une approbation par erreur
		// ferait passer un visiteur pour l'éditeur.
		if ( (int) $r->user_id && user_can( (int) $r->user_id, 'moderate_comments' ) ) {
			return [
				'text' => (string) $r->comment_content,
				'date' => get_comment_date( 'j F Y', $r ),
			];
		}
	}
	return null;
}

// Un seul avis (blockquote) — factorisé pour être appelable isolément par le
// dépôt AJAX (nouvel avis à insérer sans reload, cf. passiflore_ajax_avis_submit)
// autant que par la boucle de la liste complète ci-dessous.
function passiflore_render_avis_item( array $avis, bool $hidden = false ) {
	$own     = ! empty( $avis['own'] );
	$pending = ! empty( $avis['pending'] );

	echo '<blockquote class="pf-quote bs-avis-item' . ( $pending ? ' bs-avis-item--pending' : '' ) . ( $hidden ? ' bs-item--hidden' : '' ) . '"'
		. ( $own ? ' data-comment-id="' . (int) $avis['comment_id'] . '"' : '' ) . '>';
	if ( $avis['titre'] !== '' ) {
		echo '<h4 class="bs-avis-titre">' . esc_html( $avis['titre'] ) . '</h4>';
	}
	if ( $avis['text'] !== '' ) {
		echo '<p class="bs-avis-text">' . nl2br( esc_html( $avis['text'] ) ) . '</p>';
	}
	echo '<footer class="bs-avis-footer">';
	if ( $avis['auteur'] !== '' ) {
		echo '<span class="bs-avis-auteur">' . esc_html( $avis['auteur'] ) . '</span>';
	}
	if ( $own ) {
		// aria-label daté : plusieurs avis d'un même auteur peuvent cohabiter dans
		// la liste (un par format du format_groupe), « Supprimer » seul serait ambigu.
		printf(
			'<button type="button" class="bs-avis-supprimer pf-btn pf-btn--neutral pf-btn--sm" aria-label="%s">Supprimer</button>',
			esc_attr( 'Supprimer mon avis' . ( $avis['date'] !== '' ? ' du ' . $avis['date'] : '' ) )
		);
	}
	echo '</footer>';
	if ( $pending ) {
		echo '<p class="bs-avis-pending-notice">En attente de validation par l’équipe de Passiflore.</p>';
	}
	if ( ! empty( $avis['reply'] ) ) {
		echo '<div class="pf-avis-reponse">';
		echo '<div class="pf-avis-reponse__head">';
		$logo_url = (string) get_site_icon_url( 48 );
		if ( $logo_url !== '' ) {
			echo '<img class="pf-avis-reponse__logo" src="' . esc_url( $logo_url ) . '" alt="" loading="lazy" width="20" height="20" />';
		}
		echo '<strong>Réponse de Passiflore</strong>';
		echo '</div>';
		echo '<p class="pf-avis-reponse__text">' . nl2br( esc_html( $avis['reply']['text'] ) ) . '</p>';
		echo '</div>';
	}
	echo '</blockquote>';
}

// Boucle de rendu des blockquotes + bouton « Voir tout » (sans wrapper de section).
function passiflore_render_avis_list( array $entries ) {
	$seuil   = 3;
	$visible = 0;
	$masques = 0;

	foreach ( $entries as $avis ) {
		$own = ! empty( $avis['own'] );

		// Son propre avis est TOUJOURS déplié : la fiche livre est le seul endroit
		// d'où l'auteur peut le relire et le supprimer, y compris au-delà du seuil.
		$hidden = false;
		if ( $own || $visible < $seuil ) {
			$visible++;
		} else {
			$hidden = true;
			$masques++;
		}

		passiflore_render_avis_item( $avis, $hidden );
	}

	if ( $masques ) {
		echo '<button class="bs-voir-tout pf-btn pf-btn--neutral pf-btn--sm" type="button">Voir tout (' . $masques . ')</button>';
	}
}

// Bloc d'avis autonome (wrapper + liste). Utilisé pour les avis des libraires.
function passiflore_render_avis_items( array $entries, string $section ) {
	if ( ! $entries ) return;
	echo '<div class="bs-tab-avis"><div class="bs-avis-section" data-section="' . esc_attr( $section ) . '">';
	passiflore_render_avis_list( $entries );
	echo '</div></div>';
}


/* ─── Avis des lecteurs (sélection éditeur + avis du site, fusionnés) + formulaire ──── */

function passiflore_render_avis_lecteurs( int $product_id, array $entries ) {
	$error = passiflore_avis_error_data();

	if ( ! empty( $error['msg'] ) ) {
		echo '<p class="pf-notice pf-notice--error bs-avis-erreur" role="alert">' . esc_html( $error['msg'] ) . '</p>';
	}

	if ( $entries ) {
		echo '<div class="bs-tab-avis"><div class="bs-avis-section" data-section="lecteurs">';
		passiflore_render_avis_list( $entries );
		echo '</div></div>';
	}

	// Formulaire de dépôt — ouvert à tous (nom), modéré avant publication
	if ( ! comments_open( $product_id ) ) return;

	$prefill_comment = $error['comment'] ?? '';

	if ( is_user_logged_in() ) {
		$prefill_author = $error['author'] ?? wp_get_current_user()->display_name;
	} else {
		$commenter      = wp_get_current_commenter();
		$prefill_author = $error['author'] ?? $commenter['comment_author'];
	}

	// Anti-spam : honeypot (champ caché) + piège temporel (timestamp signé, vérifié dans passiflore_avis_spam_check)
	$ts    = time();
	$traps = '<p class="pf-hp" aria-hidden="true" style="position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden">'
		. '<label for="pf_hp">Ne remplissez pas ce champ</label>'
		. '<input type="text" id="pf_hp" name="pf_hp" value="" tabindex="-1" autocomplete="off"></p>'
		. '<input type="hidden" name="pf_ts" value="' . esc_attr( $ts . ':' . hash_hmac( 'sha256', (string) $ts, wp_salt( 'auth' ) ) ) . '">';

	$is_logged_in = is_user_logged_in();
	$disabled     = $is_logged_in ? ' disabled' : '';

	$author_note = '';
	if ( $is_logged_in ) {
		$edit_account_url = wc_get_endpoint_url( 'edit-account', '', wc_get_page_permalink( 'myaccount' ) );
		$author_note = '<p class="comment-form-author-note">Pour modifier le nom, rendez-vous dans les '
			. '<a href="' . esc_url( $edit_account_url ) . '" target="_blank" rel="noopener">détails du compte' . pf_new_window_note() . '</a>.</p>';
	}

	// Ordre fixe : textarea → Nom (disabled si connecté) → note → bouton.
	// fields/logged_in_as vidés : tout passe par comment_field, rendu quel que soit l'état de connexion.
	$form_args = [
		'fields'               => [],
		'logged_in_as'         => '',
		'comment_field'        =>
			'<p class="comment-form-comment"><label for="comment">Votre avis <span class="required">*</span></label>'
			. '<textarea id="comment" name="comment" rows="5" required>' . esc_textarea( $prefill_comment ) . '</textarea></p>'
			. $traps
			. '<p class="comment-form-author"><label for="author">Nom <span class="required">*</span></label>'
			. '<input id="author" name="author" type="text" value="' . esc_attr( $prefill_author ) . '" maxlength="245" required aria-required="true"' . $disabled . '></p>'
			. $author_note
			. '<p class="comment-notes-after">Votre avis sera publié après validation par l’équipe de Passiflore.</p>',
		'title_reply'          => '',
		'title_reply_to'       => '',
		'label_submit'         => 'Publier mon avis',
		'comment_notes_before' => '',
		'comment_notes_after'  => '',
	];

	echo '<div class="bs-avis-form">';
	add_filter( 'comment_form_fields', 'passiflore_avis_remove_cookies', 99 );
	comment_form( $form_args, $product_id );
	remove_filter( 'comment_form_fields', 'passiflore_avis_remove_cookies', 99 );
	echo '</div>';
}

// Retire la case de consentement aux cookies (réinjectée d'office par le core) du formulaire d'avis
function passiflore_avis_remove_cookies( $fields ) {
	unset( $fields['cookies'] );
	return $fields;
}

// Données d'un échec de soumission (motif + saisie conservée), à usage unique via transient
function passiflore_avis_error_data(): array {
	if ( empty( $_GET['avis_erreur'] ) ) {
		return [];
	}
	$token = (string) wp_unslash( $_GET['avis_erreur'] );
	if ( ! ctype_alnum( $token ) ) {
		return [];
	}
	$data = get_transient( 'pf_avis_err_' . $token );
	if ( ! is_array( $data ) ) {
		return [];
	}
	delete_transient( 'pf_avis_err_' . $token );
	return $data;
}


/* ─── JS inline : toggle « Voir tout » des sous-listes ────────── */
/* Nav sticky + scrollspy + neutralisation du scroll d'ancre Kadence : désormais
 * dans le composant partagé (assets/js/section-nav.js + inc/section-nav.php, qui
 * pose aussi body.no-anchor-scroll). Ne reste ici que le toggle « Voir tout ». */

add_action( 'wp_footer', 'passiflore_book_tabs_inline_js' );

function passiflore_book_tabs_inline_js() {
	if ( ! is_product() ) return;
	?>
	<script>
	(function () {
		// "Voir tout" / "Voir les n premiers" — toggle des items supplémentaires
		document.querySelectorAll('.bs-voir-tout').forEach(function (btn) {
			var section = btn.closest('.bs-avis-section, .pf-section');
			if (!section) return;

			var extraItems = Array.from(section.querySelectorAll('.bs-item--hidden'));
			if (!extraItems.length) return;

			btn.addEventListener('click', function () {
				var expanded = btn.dataset.expanded === '1';
				if (!expanded) {
					extraItems.forEach(function (el) { el.classList.remove('bs-item--hidden'); });
					btn.textContent = 'Masquer (' + extraItems.length + ')';
					btn.dataset.expanded = '1';
				} else {
					extraItems.forEach(function (el) { el.classList.add('bs-item--hidden'); });
					btn.textContent = 'Voir tout (' + extraItems.length + ')';
					btn.dataset.expanded = '0';
				}
			});
		});

		// Résumé (hero) : « Lire la suite » n'est révélé que si le texte
		// clampé (CSS -webkit-line-clamp) déborde réellement — line-clamp
		// ne permet pas de détecter ça en CSS pur.
		var resumeText = document.querySelector('.bs-hero__resume-text');
		var resumeMore = document.querySelector('.bs-hero__resume-more');
		if (resumeText && resumeMore) {
			var syncResumeMore = function () {
				resumeMore.hidden = resumeText.scrollHeight <= resumeText.clientHeight + 1;
			};
			syncResumeMore();
			window.addEventListener('resize', syncResumeMore);
		}
	})();
	</script>
	<?php
}
