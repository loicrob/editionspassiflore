<?php
/**
 * « M'avertir si à nouveau disponible » — collecte d'intérêt sur les livres épuisés.
 *
 * Sur la fiche d'un livre hors stock, le bloc d'achat est remplacé par un bouton
 * (+ saisie d'e-mail pour les visiteurs non connectés). Chaque demande :
 *   - est stockée dans la post meta `_pf_stock_alerts` du produit ;
 *   - déclenche une notification interne à Passiflore ;
 *   - apparaît dans Produits → « Intérêts pour les épuisés ».
 *
 * L'envoi du mail « le livre est de nouveau disponible » reste MANUEL : l'écran
 * d'admin fournit une liste d'adresses copiable, Passiflore écrit depuis sa
 * messagerie puis retire la ligne à la main. Aucun envoi automatique, aucune
 * purge automatique (la meta reste sur le produit quel que soit son stock futur).
 *
 * Anti-spam (visiteurs anonymes) : honeypot + timestamp signé + déduplication
 * e-mail↔livre + plafond par IP. Aucun mail n'étant envoyé à l'adresse saisie
 * (seul Passiflore est notifié), le formulaire ne peut pas servir de relais de
 * mail-bombing — un captcha serait disproportionné.
 *
 * @package kadence-child
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const PF_SA_META            = '_pf_stock_alerts';
const PF_SA_NONCE           = 'pf_stock_alert';
const PF_SA_TS_MIN_SECONDS  = 4;   // délai minimal entre affichage et soumission
const PF_SA_IP_MAX_PER_HOUR = 5;   // demandes retenues par IP et par heure

// Cloche « notifications » (Material Symbols, viewBox 0 -960 960 960) — icône du bouton.
const PF_SA_ICON_PATH = 'M160-200v-80h80v-280q0-83 50-147.5T420-792v-28q0-25 17.5-42.5T480-880q25 0 42.5 17.5T540-820v28q80 20 130 84.5T720-560v280h80v80H160Zm320-300Zm0 420q-33 0-56.5-23.5T400-160h160q0 33-23.5 56.5T480-80ZM320-280h320v-280q0-66-47-113t-113-47q-66 0-113 47t-47 113v280Z';


/* ═══════════════════════════════════════════════════════════════
   Piège temporel — copie locale assumée
   ═══════════════════════════════════════════════════════════════

   Trois copies de ce motif existent déjà (newsletter, avis rendu, avis AJAX).
   Les mutualiser mérite un chantier à part : hors périmètre ici.

   ⚠️ Limite sous cache : la fiche produit est servie depuis WP Fastest Cache aux
   visiteurs anonymes, donc le couple pf_ts / signature est mis en cache et
   réutilisable pendant toute la vie de la page. On ne vérifie donc QUE la borne
   basse (soumission trop rapide = bot naïf) — jamais une expiration haute, qui
   rejetterait des demandes légitimes (un pf_ts vieux de plusieurs heures est le
   cas normal sous cache). La vraie défense sous cache : honeypot, dédup, plafond IP. */

function pf_sa_ts_field(): string {
	$ts  = time();
	$sig = $ts . ':' . hash_hmac( 'sha256', (string) $ts, wp_salt( 'auth' ) );
	return '<input type="hidden" name="pf_ts" value="' . esc_attr( $sig ) . '">';
}

function pf_sa_ts_valid(): bool {
	$raw          = isset( $_POST['pf_ts'] ) ? (string) wp_unslash( $_POST['pf_ts'] ) : '';
	[ $ts, $sig ] = array_pad( explode( ':', $raw, 2 ), 2, '' );
	$signature_ok = '' !== $ts && hash_equals( hash_hmac( 'sha256', $ts, wp_salt( 'auth' ) ), $sig );
	return $signature_ok && ( time() - (int) $ts ) >= PF_SA_TS_MIN_SECONDS;
}


/* ═══════════════════════════════════════════════════════════════
   Helpers de données
   ═══════════════════════════════════════════════════════════════ */

/** @return array liste (éventuellement vide) des entrées d'alerte d'un produit. */
function pf_sa_get_alerts( int $product_id ): array {
	$alerts = get_post_meta( $product_id, PF_SA_META, true );
	return is_array( $alerts ) ? $alerts : [];
}

/** Un compte connecté a-t-il déjà une alerte sur ce livre ? */
function pf_sa_user_has_alert( int $product_id, int $user_id ): bool {
	if ( ! $user_id ) return false;
	foreach ( pf_sa_get_alerts( $product_id ) as $e ) {
		if ( (int) ( $e['user_id'] ?? 0 ) === $user_id ) return true;
	}
	return false;
}

/** IP cliente (REMOTE_ADDR seul — pas de X-Forwarded-For, falsifiable). */
function pf_sa_client_ip(): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}


/* ═══════════════════════════════════════════════════════════════
   Rendu front — fiche livre
   ═══════════════════════════════════════════════════════════════ */

/**
 * Formulaire d'alerte, rendu dans .bs-hero__purchase à la place du bloc d'achat
 * (woocommerce/content-single-product.php). Le prédicat d'affichage — livre hors
 * stock — est évalué par le template appelant.
 */
function pf_stock_alert_render( int $product_id ): string {
	$logged = is_user_logged_in();

	// Compte connecté déjà inscrit → état « c'est fait », pas de formulaire.
	if ( $logged && pf_sa_user_has_alert( $product_id, get_current_user_id() ) ) {
		return pf_sa_done_markup();
	}

	$privacy     = get_page_by_path( 'politique-de-confidentialite' );
	$privacy_url = $privacy ? get_permalink( $privacy ) : '';

	ob_start();
	?>
	<form class="pf-stockalert" data-product-id="<?php echo (int) $product_id; ?>" novalidate>

		<?php // Ordre visuel demandé : bouton d'abord, champ e-mail ensuite (invité). L'ordre DOM
		      // est identique — comme c'est un vrai <form>, Entrée dans le champ soumet quand même. ?>
		<button type="submit" class="pf-btn pf-btn--primary pf-btn--icon pf-stockalert__btn"<?php echo $logged ? '' : ' disabled'; ?>>
			<svg class="pf-stockalert__btn-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="<?php echo esc_attr( PF_SA_ICON_PATH ); ?>"/></svg>
			M’avertir si à nouveau disponible
		</button>

		<?php if ( ! $logged ) : ?>
		<label class="screen-reader-text" for="pf-stockalert-email">Adresse e-mail</label>
		<input type="email" id="pf-stockalert-email" name="email" class="pf-stockalert__email"
			placeholder="Votre adresse e-mail" required
			inputmode="email" autocomplete="email" autocapitalize="none" autocorrect="off" spellcheck="false">
		<?php endif; ?>

		<?php // Anti-spam : honeypot (caché) + timestamp signé. Markup du honeypot copié de inc/newsletter.php. ?>
		<p class="pf-hp" aria-hidden="true" style="position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden">
			<label for="pf-stockalert-hp">Ne remplissez pas ce champ</label>
			<input type="text" id="pf-stockalert-hp" name="pf_hp" value="" tabindex="-1" autocomplete="off">
		</p>
		<?php echo pf_sa_ts_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

		<?php if ( ! $logged ) : ?>
		<p class="pf-stockalert__rgpd">
			Votre adresse ne servira qu'à cette alerte<?php if ( $privacy_url ) : ?> — <a href="<?php echo esc_url( $privacy_url ); ?>" target="_blank" rel="noopener">politique de confidentialité<?php echo pf_new_window_note(); // phpcs:ignore WordPress.Security.EscapeOutput ?></a><?php endif; ?>.
		</p>
		<?php endif; ?>

		<p class="pf-stockalert__msg" role="status" aria-live="polite"></p>
	</form>
	<?php
	return ob_get_clean();
}

/** État affiché une fois la demande enregistrée (ou pour un compte déjà inscrit). */
function pf_sa_done_markup(): string {
	return '<div class="pf-stockalert__done">'
		. '<p class="pf-stockalert__done-msg">'
		. '<svg class="pf-stockalert__done-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg>'
		. '<span>Vous serez averti·e par e-mail si ce livre est à nouveau disponible un jour.</span>'
		. '</p></div>';
}


/* ═══════════════════════════════════════════════════════════════
   Enqueue front
   ═══════════════════════════════════════════════════════════════ */

// Priorité 20 : pf-session-toast (403 → « session expirée ») n'est enregistré qu'en priorité 10.
add_action( 'wp_enqueue_scripts', 'pf_sa_enqueue', 20 );

function pf_sa_enqueue() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	$product = wc_get_product( get_the_ID() );
	if ( ! $product || $product->is_in_stock() ) {
		return; // même prédicat que le template (onbackorder = « Précommander », pas d'alerte)
	}

	$dir = get_stylesheet_directory();
	wp_enqueue_script(
		'pf-stock-alert',
		get_stylesheet_directory_uri() . '/assets/js/stock-alert.js',
		[ 'pf-session-toast' ],
		filemtime( $dir . '/assets/js/stock-alert.js' ),
		true
	);
	wp_localize_script( 'pf-stock-alert', 'PfStockAlert', [
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( PF_SA_NONCE ),
	] );
}


/* ═══════════════════════════════════════════════════════════════
   Endpoint AJAX + notification à Passiflore
   ═══════════════════════════════════════════════════════════════ */

add_action( 'wp_ajax_pf_stock_alert',        'pf_sa_ajax_submit' );
add_action( 'wp_ajax_nopriv_pf_stock_alert', 'pf_sa_ajax_submit' );

function pf_sa_ajax_submit() {
	// 1. Nonce — seul chemin qui produit un 403 (géré côté client par pfSessionExpired).
	check_ajax_referer( PF_SA_NONCE, 'nonce' );

	// 2. Produit valide et hors stock.
	$product_id = absint( $_POST['product_id'] ?? 0 );
	$product    = $product_id ? wc_get_product( $product_id ) : null;
	if ( ! $product || $product->is_in_stock() ) {
		wp_send_json_error( [ 'message' => "Ce livre n'est plus concerné par une alerte de disponibilité." ] );
	}

	$logged = is_user_logged_in();

	// 3. Anti-spam : visiteurs anonymes uniquement (comptes déjà authentifiés).
	if ( ! $logged && ( ! empty( $_POST['pf_hp'] ) || ! pf_sa_ts_valid() ) ) {
		wp_send_json_error( [ 'message' => "Votre demande n'a pas pu être enregistrée." ] );
	}

	// 4. Plafond par IP — erreur métier (jamais 403), aucun mail admin. Le compteur
	//    n'est incrémenté qu'à la création d'une nouvelle entrée (plus bas) : rejouer
	//    une demande déjà connue ne consomme pas le quota.
	$ip      = pf_sa_client_ip();
	$ip_key  = $ip ? 'pf_sa_ip_' . md5( $ip ) : '';
	$ip_hits = $ip_key ? (int) get_transient( $ip_key ) : 0;
	if ( $ip_key && $ip_hits >= PF_SA_IP_MAX_PER_HOUR ) {
		wp_send_json_error( [ 'message' => 'Trop de demandes depuis votre connexion. Merci de réessayer plus tard.' ] );
	}

	// 5. E-mail : le compte connecté impose le sien ; sinon la saisie.
	if ( $logged ) {
		$email   = wp_get_current_user()->user_email;
		$user_id = get_current_user_id();
	} else {
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$user_id = 0;
	}
	if ( ! is_email( $email ) ) {
		wp_send_json_error( [ 'message' => 'Veuillez saisir une adresse e-mail valide.' ] );
	}

	// 6. Écriture sous verrou consultatif MySQL : sérialise le read-modify-write de
	//    la meta pour un même produit (deux demandes simultanées s'écraseraient).
	//    Calqué sur Passiflore_Reading_List::ajax_toggle(), wp_cache_delete() inclus.
	global $wpdb;
	$lock = 'pf_sa_' . $product_id;
	$got  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 5 ) );

	wp_cache_delete( $product_id, 'post_meta' );
	$alerts  = pf_sa_get_alerts( $product_id );
	$already = false;
	foreach ( $alerts as $e ) {
		if ( ( $user_id && (int) ( $e['user_id'] ?? 0 ) === $user_id )
			|| 0 === strcasecmp( (string) ( $e['email'] ?? '' ), $email ) ) {
			$already = true;
			break;
		}
	}

	if ( ! $already ) {
		$alerts[] = [
			'id'      => wp_generate_password( 8, false ),
			'email'   => $email,
			'user_id' => $user_id,
			'time'    => time(),
		];
		update_post_meta( $product_id, PF_SA_META, $alerts );
	}

	if ( $got ) {
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}

	// Nouvelle demande seulement : compteur IP + notification. Rejouer ne coûte rien.
	if ( ! $already ) {
		if ( $ip_key ) {
			set_transient( $ip_key, $ip_hits + 1, HOUR_IN_SECONDS );
		}
		pf_sa_notify_admin( $product_id, $email, $user_id );
	}

	wp_send_json_success( [ 'html' => pf_sa_done_markup() ] );
}

/**
 * Notification interne à Passiflore (texte brut). Pas une classe WC_Email : les
 * classes de inc/emails/ restent réservées aux envois transactionnels au client.
 */
function pf_sa_notify_admin( int $product_id, string $email, int $user_id ) {
	$to    = apply_filters( 'pf_stock_alert_admin_recipient', get_option( 'admin_email' ) );
	$title = get_the_title( $product_id );

	$lines = [
		'Une personne souhaite être prévenue du retour en stock d’un livre épuisé.',
		'',
		'Livre : ' . $title,
		'Fiche : ' . admin_url( 'post.php?post=' . $product_id . '&action=edit' ),
		'',
		'Demande de : ' . $email,
	];

	if ( $user_id ) {
		$u    = get_userdata( $user_id );
		$name = $u ? trim( $u->first_name . ' ' . $u->last_name ) : '';
		if ( '' === $name && $u ) {
			$name = $u->user_login;
		}
		$lines[] = 'Compte client : ' . ( '' !== $name ? $name : ( 'utilisateur #' . $user_id ) )
			. ' — ' . admin_url( 'user-edit.php?user_id=' . $user_id );
	} else {
		$lines[] = '(visiteur non connecté)';
	}

	$lines[] = '';
	$lines[] = 'Toutes les demandes : ' . admin_url( 'edit.php?post_type=product&page=pf-stock-alerts' );

	wp_mail(
		$to,
		sprintf( '[Passiflore] Intérêt pour un livre épuisé : %s', $title ),
		implode( "\n", $lines )
	);
}


/* ═══════════════════════════════════════════════════════════════
   Admin — Produits → « Intérêts pour les épuisés »
   ═══════════════════════════════════════════════════════════════ */

add_action( 'admin_menu', function () {
	$hook = add_submenu_page(
		'edit.php?post_type=product',
		'Intérêts pour les épuisés',
		'Intérêts pour les épuisés',
		'edit_products',
		'pf-stock-alerts',
		'pf_sa_render_admin'
	);
	if ( $hook ) {
		add_action( 'load-' . $hook, 'pf_sa_handle_post' );
		add_action( 'admin_enqueue_scripts', function ( $current ) use ( $hook ) {
			if ( $current === $hook ) pf_sa_admin_enqueue();
		} );
	}
} );

/**
 * Place l'entrée juste après « Ajouter un nouveau livre » (l'ordre d'un sous-menu
 * dépend de l'ordre d'insertion, peu déterministe entre extensions).
 *
 * ⚠️ Admin Menu Editor est installé et pilote peut-être le libellé / l'ordre du
 * sous-menu depuis la base : si l'entrée n'atterrit pas au bon endroit, régler la
 * position dans l'écran d'Admin Menu Editor, pas ici.
 */
add_action( 'admin_menu', 'pf_sa_reorder_submenu', 100 );
function pf_sa_reorder_submenu() {
	global $submenu;
	$parent = 'edit.php?post_type=product';
	if ( empty( $submenu[ $parent ] ) ) {
		return;
	}
	$items = $submenu[ $parent ];

	$ours = null;
	foreach ( $items as $k => $it ) {
		if ( isset( $it[2] ) && 'pf-stock-alerts' === $it[2] ) {
			$ours = $it;
			unset( $items[ $k ] );
			break;
		}
	}
	if ( null === $ours ) {
		return;
	}
	$items = array_values( $items );

	$at = count( $items );
	foreach ( $items as $i => $it ) {
		if ( isset( $it[2] ) && 'post-new.php?post_type=product' === $it[2] ) {
			$at = $i + 1;
			break;
		}
	}
	array_splice( $items, $at, 0, [ $ours ] );
	$submenu[ $parent ] = $items;
}

function pf_sa_admin_url(): string {
	return admin_url( 'edit.php?post_type=product&page=pf-stock-alerts' );
}

function pf_sa_redirect( array $args ) {
	wp_safe_redirect( add_query_arg( $args, pf_sa_admin_url() ) );
	exit;
}

/** Traitement des formulaires (POST), avant tout output. PRG systématique. */
function pf_sa_handle_post() {
	if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return;
	if ( ! isset( $_POST['pf_sa_action'] ) ) return;
	if ( ! current_user_can( 'edit_products' ) ) return;
	check_admin_referer( 'pf_sa_save', 'pf_sa_nonce' );

	$action     = sanitize_key( $_POST['pf_sa_action'] );
	$product_id = absint( $_POST['product_id'] ?? 0 );

	if ( $product_id && 'delete_entry' === $action ) {
		$entry_id = sanitize_text_field( wp_unslash( $_POST['entry_id'] ?? '' ) );
		pf_sa_delete_entry( $product_id, $entry_id );
		pf_sa_redirect( [ 'msg' => 'deleted' ] );
	}

	if ( $product_id && 'delete_book' === $action ) {
		delete_post_meta( $product_id, PF_SA_META );
		pf_sa_redirect( [ 'msg' => 'book_deleted' ] );
	}

	pf_sa_redirect( [] );
}

/** Retire une entrée par son `id` ; supprime la meta si c'était la dernière. */
function pf_sa_delete_entry( int $product_id, string $entry_id ) {
	if ( '' === $entry_id ) return;
	$alerts = pf_sa_get_alerts( $product_id );
	if ( ! $alerts ) return;

	$alerts = array_values( array_filter(
		$alerts,
		static function ( $e ) use ( $entry_id ) {
			return (string) ( $e['id'] ?? '' ) !== $entry_id;
		}
	) );

	if ( $alerts ) {
		update_post_meta( $product_id, PF_SA_META, $alerts );
	} else {
		delete_post_meta( $product_id, PF_SA_META );
	}
}

function pf_sa_admin_enqueue() {
	$dir = get_stylesheet_directory();
	wp_enqueue_script(
		'pf-stock-alerts-admin',
		get_stylesheet_directory_uri() . '/assets/js/stock-alerts-admin.js',
		[],
		filemtime( $dir . '/assets/js/stock-alerts-admin.js' ),
		true
	);
	wp_add_inline_style( 'wp-admin', '
		.pf-sa-col-count { width:90px; }
		td.pf-sa-col-count { font-variant-numeric:tabular-nums; }
		.pf-sa-col-toggle { width:130px; text-align:right; }
		.pf-sa-main .row-actions form { display:inline; }
		.pf-sa-main .row-actions .button-link { color:#b32d2e; }
		.pf-sa-details > td { background:#f6f7f7; padding:12px 16px; }
		.pf-sa-detail-table { margin:0 0 10px; max-width:640px; }
		.pf-sa-detail-table td, .pf-sa-detail-table th { padding:6px 10px; }
		.pf-sa-detail-del { width:44px; text-align:center; }
		.pf-sa-x { color:#b32d2e; text-decoration:none; font-size:15px; line-height:1; }
		.pf-sa-x:hover { color:#8a2424; }
		.pf-sa-guest { color:#888; }
		.pf-sa-copy-row { margin:0; }
		.pf-sa-copy-src { position:absolute; left:-9999px; width:1px; height:1px; }
	' );
}

function pf_sa_admin_notice() {
	$map = [
		'deleted'      => 'Demande supprimée.',
		'book_deleted' => 'Toutes les demandes de ce livre ont été supprimées.',
	];
	$msg = $map[ sanitize_key( $_GET['msg'] ?? '' ) ] ?? '';
	if ( $msg ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}
}

function pf_sa_render_admin() {
	if ( ! current_user_can( 'edit_products' ) ) return;

	echo '<div class="wrap">';
	echo '<h1>Intérêts pour les épuisés</h1>';
	pf_sa_admin_notice();
	echo '<p class="description" style="max-width:70ch">Demandes « M’avertir si à nouveau disponible » déposées sur les fiches de livres épuisés. '
		. 'L’envoi du mail de retour en stock reste manuel : écrivez depuis votre messagerie, puis retirez la ligne ici.</p>';

	// Livres portant au moins une demande, triés par nombre de demandes décroissant.
	$ids = get_posts( [
		'post_type'      => 'product',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => PF_SA_META,
	] );

	$rows = [];
	foreach ( $ids as $pid ) {
		$alerts = pf_sa_get_alerts( (int) $pid );
		if ( $alerts ) {
			$rows[] = [ 'id' => (int) $pid, 'alerts' => $alerts ];
		}
	}
	usort( $rows, static function ( $a, $b ) {
		return count( $b['alerts'] ) <=> count( $a['alerts'] );
	} );

	if ( ! $rows ) {
		echo '<p>Aucune demande pour le moment.</p></div>';
		return;
	}

	echo '<table class="wp-list-table widefat fixed striped">';
	echo '<thead><tr><th>Livre</th><th class="pf-sa-col-count">Demandes</th><th class="pf-sa-col-toggle"></th></tr></thead><tbody>';

	foreach ( $rows as $row ) {
		$pid       = $row['id'];
		$alerts    = $row['alerts'];
		$title     = get_the_title( $pid ) ?: '(sans titre)';
		$edit_url  = admin_url( 'post.php?post=' . $pid . '&action=edit' );
		$detail_id = 'pf-sa-details-' . $pid;

		echo '<tr class="pf-sa-main">';

		echo '<td><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $title ) . '</a></strong>';
		echo '<div class="row-actions"><span class="trash">';
		echo '<form method="post" action="' . esc_url( pf_sa_admin_url() ) . '" data-confirm="Supprimer toutes les demandes pour ce livre ?">';
		wp_nonce_field( 'pf_sa_save', 'pf_sa_nonce' );
		echo '<input type="hidden" name="pf_sa_action" value="delete_book">';
		echo '<input type="hidden" name="product_id" value="' . $pid . '">';
		echo '<button type="submit" class="button-link">Supprimer</button>';
		echo '</form>';
		echo '</span></div>';
		echo '</td>';

		echo '<td class="pf-sa-col-count">' . count( $alerts ) . '</td>';

		echo '<td class="pf-sa-col-toggle"><button type="button" class="button button-small pf-sa-toggle" aria-expanded="false" aria-controls="' . esc_attr( $detail_id ) . '">Plus d’infos</button></td>';

		echo '</tr>';

		echo '<tr class="pf-sa-details" id="' . esc_attr( $detail_id ) . '" hidden><td colspan="3">';
		pf_sa_render_detail_table( $pid, $alerts );
		echo '</td></tr>';
	}

	echo '</tbody></table></div>';
}

function pf_sa_render_detail_table( int $product_id, array $alerts ) {
	$emails = [];

	echo '<table class="widefat striped pf-sa-detail-table">';
	echo '<thead><tr><th>Nom</th><th>E-mail</th><th>Date</th><th class="pf-sa-detail-del"></th></tr></thead><tbody>';

	foreach ( $alerts as $e ) {
		$email    = (string) ( $e['email'] ?? '' );
		$emails[] = $email;
		$user_id  = (int) ( $e['user_id'] ?? 0 );
		$time     = (int) ( $e['time'] ?? 0 );

		if ( $user_id ) {
			$u    = get_userdata( $user_id );
			$name = $u ? trim( $u->first_name . ' ' . $u->last_name ) : '';
			if ( '' === $name && $u ) {
				$name = $u->user_login;
			}
			$name_html = $u
				? '<a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $user_id ) ) . '">' . esc_html( $name ) . '</a>'
				: '<span class="pf-sa-guest">compte supprimé</span>';
		} else {
			$name_html = '<span class="pf-sa-guest">— (non connecté)</span>';
		}

		echo '<tr>';
		echo '<td>' . $name_html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<td>' . esc_html( $email ) . '</td>';
		echo '<td>' . esc_html( $time ? wp_date( 'j F Y', $time ) : '—' ) . '</td>';
		echo '<td class="pf-sa-detail-del">';
		echo '<form method="post" action="' . esc_url( pf_sa_admin_url() ) . '">';
		wp_nonce_field( 'pf_sa_save', 'pf_sa_nonce' );
		echo '<input type="hidden" name="pf_sa_action" value="delete_entry">';
		echo '<input type="hidden" name="product_id" value="' . (int) $product_id . '">';
		echo '<input type="hidden" name="entry_id" value="' . esc_attr( (string) ( $e['id'] ?? '' ) ) . '">';
		echo '<button type="submit" class="button-link pf-sa-x" aria-label="Supprimer cette demande" title="Supprimer cette demande">&times;</button>';
		echo '</form>';
		echo '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';

	echo '<p class="pf-sa-copy-row">';
	echo '<textarea class="pf-sa-copy-src" readonly tabindex="-1" aria-hidden="true">' . esc_textarea( implode( ', ', $emails ) ) . '</textarea>';
	echo '<button type="button" class="button pf-sa-copy" data-copied="Copié ✓">Copier les adresses</button>';
	echo '</p>';
}
