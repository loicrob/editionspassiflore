<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Encart « S'abonner à la newsletter » — footer (site-wide), page /contact,
 * et « Détails du compte » (WooCommerce).
 *
 * Formulaire custom Passiflore → AJAX (admin-ajax) → relais serveur vers le
 * formulaire eTarget hébergé (POST vers recorder.php, comme le ferait l'iframe).
 * eTarget gère le stockage, la déduplication par email et l'email de bienvenue
 * (Scénario Welcome).
 *
 * Anti-spam (honeypot + piège temporel) pour les visiteurs anonymes.
 * En cas d'échec du relais : l'inscription est mise en file d'attente locale
 * (jamais perdue) + alerte admin.
 *
 * ⚠️ ENDPOINT + IDs de champs ci-dessous = couplés au formulaire eTarget actuel.
 * Si le formulaire est recréé dans eTarget, mettre à jour l'URL et le mapping.
 */

const PASSIFLORE_NEWSLETTER_ENDPOINT = 'https://go.formulaire.info/data_user/4gDHGNxG/SErtbvap/preview/recorder.php';
const PASSIFLORE_NEWSLETTER_REFERER  = 'https://go.formulaire.info/form?p=SErtbvap';

function passiflore_newsletter_etarget_map() {
	return [
		'email'       => 'INPUT-37396',
		'prenom'      => 'INPUT-76078',
		'nom'         => 'INPUT-99833',
		'code_postal' => 'INPUT-56818',
		'consent'     => 'INPUT-74473',
	];
}

/** Un utilisateur connecté est-il déjà inscrit (état local) ? */
function passiflore_newsletter_is_subscribed( $user_id = 0 ) {
	$user_id = $user_id ?: get_current_user_id();
	return $user_id && get_user_meta( $user_id, '_pf_newsletter_subscribed', true );
}


/* ─── Rendu ───────────────────────────────────────────────────────────── */

function passiflore_newsletter_render() {
	// Utilisateur connecté déjà inscrit → confirmation + désabonnement, pas de formulaire.
	if ( passiflore_newsletter_is_subscribed() ) {
		return '<section class="pf-newsletter pf-newsletter--done"><div class="pf-newsletter__inner">'
			. '<h2 class="pf-newsletter__titre">Newsletter</h2>'
			. '<p class="pf-newsletter__subscribed">Vous êtes inscrit(e) à notre newsletter.</p>'
			. '<button type="button" class="pf-btn pf-btn--neutral pf-btn--sm pf-newsletter__unsub-btn">Se désabonner</button>'
			. '<p class="pf-newsletter__message" role="status" aria-live="polite"></p>'
			. '</div></section>';
	}

	// Pré-remplissage pour un utilisateur connecté.
	$email = $prenom = $nom = '';
	$email_ro = $prenom_ro = $nom_ro = false;
	if ( is_user_logged_in() ) {
		$u        = wp_get_current_user();
		$email    = $u->user_email;
		$email_ro = true;
		$fn = get_user_meta( $u->ID, 'first_name', true );
		$ln = get_user_meta( $u->ID, 'last_name', true );
		if ( '' !== $fn ) { $prenom = $fn; $prenom_ro = true; }
		if ( '' !== $ln ) { $nom = $ln; $nom_ro = true; }
	}

	$privacy     = get_page_by_path( 'politique-de-confidentialite' );
	$privacy_url = $privacy ? get_permalink( $privacy ) : '';
	$consent_label = 'J\'accepte de recevoir la newsletter des Éditions Passiflore';
	if ( $privacy_url ) {
		$consent_label .= ' et j\'ai pris connaissance de la <a href="' . esc_url( $privacy_url ) . '" target="_blank" rel="noopener">politique de confidentialité</a>';
	}
	$consent_label .= '.';

	$ts  = time();
	$sig = $ts . ':' . hash_hmac( 'sha256', (string) $ts, wp_salt( 'auth' ) );

	$ro = function ( $on ) { return $on ? ' readonly' : ''; };

	ob_start();
	?>
	<section class="pf-newsletter" aria-labelledby="pf-newsletter-titre">
		<div class="pf-newsletter__inner">
			<h2 id="pf-newsletter-titre" class="pf-newsletter__titre">S'abonner à la newsletter</h2>
			<p class="pf-newsletter__intro">Recevez nos actualités, nouveautés et rencontres.</p>
			<p class="pf-newsletter__note">Connaître votre code postal nous permettra de vous envoyer uniquement les infos les plus pertinentes.</p>

			<form class="pf-newsletter__form" novalidate>
				<div class="pf-newsletter__fields">
					<input type="email" name="email" class="pf-newsletter__input" aria-label="Adresse e-mail"
						placeholder="Adresse e-mail *" required autocomplete="email"
						value="<?php echo esc_attr( $email ); ?>"<?php echo $ro( $email_ro ); ?>>

					<input type="text" name="prenom" class="pf-newsletter__input" aria-label="Prénom"
						placeholder="Prénom" autocomplete="given-name"
						value="<?php echo esc_attr( $prenom ); ?>"<?php echo $ro( $prenom_ro ); ?>>

					<input type="text" name="nom" class="pf-newsletter__input" aria-label="Nom"
						placeholder="Nom" autocomplete="family-name"
						value="<?php echo esc_attr( $nom ); ?>"<?php echo $ro( $nom_ro ); ?>>

					<input type="text" name="code_postal" class="pf-newsletter__input" aria-label="Code postal"
						placeholder="Code postal" autocomplete="postal-code" inputmode="numeric"
						pattern="[0-9]{5}" maxlength="5" title="5 chiffres">
				</div>

				<label class="pf-newsletter__consent">
					<input type="checkbox" name="consent" value="1" required>
					<span><?php echo wp_kses_post( $consent_label ); ?></span>
				</label>

				<?php // Anti-spam : honeypot (caché) + timestamp signé. ?>
				<p class="pf-hp" aria-hidden="true" style="position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden">
					<label for="pf-newsletter-hp">Ne remplissez pas ce champ</label>
					<input type="text" id="pf-newsletter-hp" name="pf_hp" value="" tabindex="-1" autocomplete="off">
				</p>
				<input type="hidden" name="pf_ts" value="<?php echo esc_attr( $sig ); ?>">

				<button type="submit" class="button pf-newsletter__btn">S'abonner</button>

				<p class="pf-newsletter__message" role="status" aria-live="polite"></p>
			</form>
		</div>
	</section>
	<?php
	return ob_get_clean();
}

// Shortcode (page /contact).
add_shortcode( 'passiflore_newsletter', 'passiflore_newsletter_render' );

// Bande site-wide avant le footer (présente sur toutes les pages).
add_action( 'kadence_before_footer', function () {
	echo passiflore_newsletter_render();
} );


/* ─── Handler AJAX ────────────────────────────────────────────────────── */

add_action( 'wp_ajax_pf_newsletter_subscribe',        'passiflore_newsletter_subscribe' );
add_action( 'wp_ajax_nopriv_pf_newsletter_subscribe', 'passiflore_newsletter_subscribe' );

function passiflore_newsletter_subscribe() {
	check_ajax_referer( 'pf_newsletter', 'nonce' );

	$logged = is_user_logged_in();

	// Anti-spam : visiteurs anonymes uniquement (les comptes connectés sont déjà authentifiés).
	if ( ! $logged ) {
		if ( ! empty( $_POST['pf_hp'] ) ) {
			wp_send_json_error( [ 'message' => 'Votre inscription n\'a pas pu être enregistrée.' ] );
		}
		$min_seconds  = 4;
		$raw          = isset( $_POST['pf_ts'] ) ? (string) wp_unslash( $_POST['pf_ts'] ) : '';
		[ $tsv, $sigv ] = array_pad( explode( ':', $raw, 2 ), 2, '' );
		$signature_ok = '' !== $tsv && hash_equals( hash_hmac( 'sha256', $tsv, wp_salt( 'auth' ) ), $sigv );
		if ( ! $signature_ok || ( time() - (int) $tsv ) < $min_seconds ) {
			wp_send_json_error( [ 'message' => 'Votre inscription n\'a pas pu être enregistrée.' ] );
		}
	}

	// Consentement obligatoire.
	if ( empty( $_POST['consent'] ) ) {
		wp_send_json_error( [ 'message' => 'Veuillez accepter de recevoir la newsletter pour vous inscrire.' ] );
	}

	$field = function ( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	};
	$prenom      = $field( 'prenom' );
	$nom         = $field( 'nom' );
	$code_postal = $field( 'code_postal' );

	if ( '' !== $code_postal && ! preg_match( '/^[0-9]{5}$/', $code_postal ) ) {
		wp_send_json_error( [ 'message' => 'Veuillez saisir un code postal valide (5 chiffres).' ] );
	}

	$save_first = $save_last = false;

	if ( $logged ) {
		$u     = wp_get_current_user();
		$email = $u->user_email; // jamais le POST pour un compte connecté
		$fn    = get_user_meta( $u->ID, 'first_name', true );
		$ln    = get_user_meta( $u->ID, 'last_name', true );
		// Si le compte a déjà la valeur → on l'impose ; sinon on prend la saisie et on la sauvegardera.
		if ( '' !== $fn ) { $prenom = $fn; } elseif ( '' !== $prenom ) { $save_first = true; }
		if ( '' !== $ln ) { $nom = $ln; }    elseif ( '' !== $nom )    { $save_last  = true; }
	} else {
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	}

	if ( ! is_email( $email ) ) {
		wp_send_json_error( [ 'message' => 'Veuillez saisir une adresse e-mail valide.' ] );
	}

	// Relais vers eTarget (gère lui-même le fallback + l'alerte en cas d'échec).
	passiflore_newsletter_push_to_etarget( compact( 'email', 'prenom', 'nom', 'code_postal' ) );

	// État local + sauvegarde du nom/prénom dans le compte si manquants.
	if ( $logged ) {
		$uid = get_current_user_id();
		update_user_meta( $uid, '_pf_newsletter_subscribed', time() );
		if ( $save_first ) { update_user_meta( $uid, 'first_name', $prenom ); }
		if ( $save_last )  { update_user_meta( $uid, 'last_name', $nom ); }
	}

	wp_send_json_success( [ 'message' => 'Merci ! Votre inscription est bien enregistrée.' ] );
}


/* ─── Désabonnement ───────────────────────────────────────────────────── */

// Réservé aux comptes connectés : seul cas où un état d'abonnement local existe.
add_action( 'wp_ajax_pf_newsletter_unsubscribe', 'passiflore_newsletter_unsubscribe' );

function passiflore_newsletter_unsubscribe() {
	check_ajax_referer( 'pf_newsletter', 'nonce' );

	$uid = get_current_user_id();
	update_user_meta( $uid, '_pf_newsletter_subscribed', false );

	passiflore_newsletter_alert_unsubscribe( wp_get_current_user() );

	wp_send_json_success( [
		'message' => 'Votre demande de désabonnement a bien été prise en compte.',
		'html'    => passiflore_newsletter_render(),
	] );
}

/** eTarget ne gère pas la suppression de contact par API : file d'attente locale + alerte admin. */
function passiflore_newsletter_alert_unsubscribe( $user ) {
	$queue   = get_option( 'pf_newsletter_unsub_requests', [] );
	$queue[] = [ 'email' => $user->user_email, 'nom' => $user->last_name, 'prenom' => $user->first_name, 'time' => time() ];
	update_option( 'pf_newsletter_unsub_requests', $queue, false );

	if ( ! get_transient( 'pf_newsletter_unsub_alert_sent' ) ) {
		wp_mail(
			get_option( 'admin_email' ),
			'[Passiflore] Demande(s) de désabonnement newsletter',
			"Un utilisateur a demandé à se désabonner de la newsletter (" . $user->user_email . ").\n\n"
			. "eTarget ne permettant pas de supprimer un contact par API, merci de le retirer manuellement du formulaire.\n"
			. "Liste complète des demandes en attente : voir l'avis dans l'administration WordPress."
		);
		set_transient( 'pf_newsletter_unsub_alert_sent', 1, HOUR_IN_SECONDS );
	}
}

// Lien « C'est fait » du bandeau : vide la file une fois les contacts retirés d'eTarget.
add_action( 'admin_init', function () {
	if ( ! isset( $_GET['pf_newsletter_unsub_clear'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'pf_newsletter_unsub_clear' );
	delete_option( 'pf_newsletter_unsub_requests' );
	wp_safe_redirect( remove_query_arg( [ 'pf_newsletter_unsub_clear', '_wpnonce' ] ) );
	exit;
} );

// Avis admin tant que des demandes de désabonnement sont en attente.
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$queue = get_option( 'pf_newsletter_unsub_requests', [] );
	if ( empty( $queue ) ) {
		return;
	}
	$emails    = implode( ', ', wp_list_pluck( $queue, 'email' ) );
	$clear_url = wp_nonce_url( add_query_arg( 'pf_newsletter_unsub_clear', '1' ), 'pf_newsletter_unsub_clear' );
	echo '<div class="notice notice-warning"><p><strong>Newsletter :</strong> '
		. (int) count( $queue )
		. ' demande(s) de désabonnement à la newsletter en attente — à retirer manuellement du formulaire eTarget : '
		. esc_html( $emails )
		. ' — <a href="' . esc_url( $clear_url ) . '">C\'est fait</a></p></div>';
} );


/* ─── Relais eTarget + fallback ───────────────────────────────────────── */

function passiflore_newsletter_push_to_etarget( $data ) {
	$map  = passiflore_newsletter_etarget_map();
	$body = [
		$map['email']       => $data['email'],
		$map['prenom']      => $data['prenom'],
		$map['nom']         => $data['nom'],
		$map['code_postal'] => $data['code_postal'],
		$map['consent']     => '1',
		// Champs cachés statiques du formulaire eTarget (envoyés tels quels).
		'meta_form'    => 'x_meta_form',
		'nextPageType' => 'simple',
		'nextPageUrl'  => '',
	];

	$response = wp_remote_post( PASSIFLORE_NEWSLETTER_ENDPOINT, [
		'timeout' => 15,
		'body'    => $body,
		'headers' => [ 'Referer' => PASSIFLORE_NEWSLETTER_REFERER ],
	] );

	$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		passiflore_newsletter_handle_failure( $data, $response );
		return false;
	}
	return true;
}

/** Échec du relais : file d'attente locale (jamais perdue) + alerte admin. */
function passiflore_newsletter_handle_failure( $data, $response ) {
	$queue   = get_option( 'pf_newsletter_failed', [] );
	$queue[] = [ 'data' => $data, 'time' => time() ];
	update_option( 'pf_newsletter_failed', $queue, false );

	// Une alerte email par heure maximum.
	if ( ! get_transient( 'pf_newsletter_alert_sent' ) ) {
		$err = is_wp_error( $response )
			? $response->get_error_message()
			: ( 'HTTP ' . wp_remote_retrieve_response_code( $response ) );
		wp_mail(
			get_option( 'admin_email' ),
			'[Passiflore] Échec de relais newsletter vers eTarget',
			"Une inscription à la newsletter n'a pas pu être transmise à eTarget (" . $err . ").\n\n"
			. "Les inscriptions sont mises en file d'attente locale (option « pf_newsletter_failed ») et ne sont pas perdues.\n"
			. "Vérifie l'URL du formulaire eTarget et les IDs de champs dans inc/newsletter.php."
		);
		set_transient( 'pf_newsletter_alert_sent', 1, HOUR_IN_SECONDS );
	}
}

// Avis admin tant que des inscriptions sont en attente.
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$queue = get_option( 'pf_newsletter_failed', [] );
	if ( empty( $queue ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>Newsletter :</strong> '
		. (int) count( $queue )
		. ' inscription(s) n\'ont pas pu être transmises à eTarget et sont en attente. '
		. 'Vérifiez la configuration du formulaire (URL / IDs de champs).</p></div>';
} );
