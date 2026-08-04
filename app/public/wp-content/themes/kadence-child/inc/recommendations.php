<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Espace « Mon compte » — plomberie transverse.
 *
 * Contient : la bascule d'affichage des étagères (Couvertures | Dos), l'ordre et
 * les conditions du menu du compte, le retrait du chrome de navigation de
 * Kadence, les champs Prénom/Nom à l'inscription, et la suppression de compte
 * (RGPD).
 *
 * ⚠️ Le NOM DU FICHIER est historique : il abritait un moteur de
 * recommandation, retiré le 2026-07-30 (les suggestions de l'accueil du compte
 * n'ont pas convaincu). Renommer le fichier toucherait functions.php et la
 * documentation pour zéro changement de comportement — noté comme suivi plutôt
 * que fait au passage. Ce qui a disparu avec le moteur : les sept fonctions
 * `pf_reco_*`, l'entrée `reco` de pf_shelf_display_specs(),
 * assets/js/account-reco.js, et le canal d'annotations de Passiflore_Bookshelf.
 * L'accueil du compte est désormais une grille de tuiles — cf. inc/account-hub.php.
 */

/* ═══════════════════════════════════════════════════════════════
   Bascule d'affichage des étagères du compte (Couvertures | Dos)
   ───────────────────────────────────────────────────────────────
   Un switch .pf-switch posé à droite du titre re-rend l'étagère en
   « covers » ou « spines » via AJAX. Les deux affichages diffèrent au rendu
   SERVEUR (épaisseur des dos ×1,5, packing des rangées, signets/prix/badges
   propres aux couvertures) → un simple toggle CSS ne suffirait pas.
   Étagères concernées : Ma liste de lecture et Catalogue, toutes deux sur la
   page /liste-de-lecture. (L'étagère des livres numériques n'en a pas : une
   rangée de liseuses vue de dos est une rangée de bandes noires.)
   ═══════════════════════════════════════════════════════════════ */

/**
 * Callbacks de rendu de l'INTÉRIEUR de chaque étagère basculable, indexés par
 * clé. Source unique partagée entre le rendu initial et l'endpoint AJAX.
 *
 * @return array<string,callable>
 */
function pf_shelf_display_specs(): array {
	return [
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
		. $btn( 'covers', 'Vue couvertures' ) . $btn( 'spines', 'Vue dos' )
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

	// « Accueil » (dashboard) retiré de la sectionnav : le hub (inc/account-hub.php)
	// est déjà la page d'atterrissage du compte, ce lien de retour était redondant.
	unset( $items['dashboard'] );

	// L'entrée `downloads` est devenue « Livres numériques », une vraie page avec
	// son étagère de liseuses : c'est Passiflore_Ebooks qui la libelle et la
	// masque quand le client n'a aucun ePub (inc/class-ebooks.php). Ici, on ne
	// décide plus que de sa POSITION, dans $order ci-dessous.

	$uid = get_current_user_id();

	// « Commandes » seulement s'il y a au moins une commande réelle. Même filtre que
	// woocommerce_account_orders() (core) : exclut les brouillons wc-checkout-draft
	// (commande auto-créée par le tunnel de commande en blocs, souvent abandonnée),
	// que wc_get_orders() sans ce filtre compterait à tort comme une vraie commande.
	if ( isset( $items['orders'] ) && $uid && function_exists( 'wc_get_orders' ) ) {
		$orders_query = apply_filters( 'woocommerce_my_account_my_orders_query', [
			'customer_id' => $uid,
			'limit'       => 1,
			'return'      => 'ids',
		] );
		$has_orders = ! empty( wc_get_orders( $orders_query ) );
		if ( ! $has_orders ) {
			unset( $items['orders'] );
		}
	}

	$order = [ 'downloads', 'liste-de-lecture', 'orders', 'edit-address', 'payment-methods', 'edit-account', 'customer-logout' ];
	$out   = [];
	foreach ( $order as $k ) {
		if ( isset( $items[ $k ] ) ) {
			$out[ $k ] = $items[ $k ];
			unset( $items[ $k ] );
		}
	}

	// Garde-fou : toute entrée que $order ne connaît pas (nouveau plugin, MAJ WooCommerce…)
	// atterrirait silencieusement en queue — cf. le bug « Moyens de paiement » après
	// « Se déconnecter ». On la journalise pour que l'oubli soit visible plutôt que silencieux.
	if ( ! empty( $items ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'pf_account_menu_reorder: entrée(s) menu compte absente(s) de $order : ' . implode( ', ', array_keys( $items ) ) );
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
		<label class="screen-reader-text" for="reg_first_name">Prénom</label>
		<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="first_name" id="reg_first_name" placeholder="Prénom" autocomplete="given-name" autocapitalize="words" value="<?php echo esc_attr( $first ); ?>" />
	</p>
	<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
		<label class="screen-reader-text" for="reg_last_name">Nom</label>
		<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="last_name" id="reg_last_name" placeholder="Nom" autocomplete="family-name" autocapitalize="words" value="<?php echo esc_attr( $last ); ?>" />
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
