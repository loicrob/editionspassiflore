<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Accueil du compte — grille de tuiles.
 *
 * Remplace les « Suggestions de Passiflore » (moteur de recommandation retiré le
 * 2026-07-30 : la maison d'édition n'en voulait pas). L'accueil devient un
 * sommaire de l'espace client, chaque tuile portant une information vivante
 * plutôt qu'un simple libellé — sans quoi le hub ne serait qu'une redite du menu
 * latéral, c'est-à-dire décoratif, exactement le reproche fait aux suggestions.
 *
 * ── Pourquoi une page et non une redirection ────────────────────────────────
 * Les deux autres pistes envisagées (rediriger vers la page la plus pertinente,
 * ou toujours vers « Détails du compte ») butent sur inc/account-auth.php, écrit
 * pour amener le visiteur sur /mon-compte en UN SEUL saut, précisément parce
 * qu'une destination dépendant d'un garde-fou `template_redirect` est fragile
 * derrière un cache. Elles rajouteraient ce second saut, feraient dépendre la
 * destination de l'état du client (qui n'apprend alors jamais où sont les
 * choses), et « Détails du compte » aurait en prime déposé chaque client sur une
 * page se terminant par le panneau rouge « Supprimer mon compte ».
 *
 * ── Source des tuiles ───────────────────────────────────────────────────────
 * `wc_get_account_menu_items()`, donc l'ordre ET les masquages conditionnels de
 * pf_account_menu_reorder() (+ ceux de Passiflore_Ebooks / Passiflore_Reading_List)
 * sont hérités gratuitement : aucune tuile ne peut pointer vers une page absente,
 * et une entrée retirée du menu disparaît d'elle-même de la grille.
 */

/** Entrées du menu qui n'ont pas leur place en tuile. */
function pf_account_hub_skip(): array {
	return [
		'dashboard',       // c'est la page courante
		'customer-logout', // action, pas destination — rendue en lien discret sous la grille
	];
}

/**
 * Ligne d'état d'une tuile. Chaîne vide = tuile sans sous-titre.
 *
 * Coût : quatre requêtes légères au total. À comparer au moteur remplacé, qui
 * lançait une `tax_query` sur tout le catalogue PAR GRAINE — l'accueil du compte
 * est donc strictement plus rapide qu'avant.
 *
 * ⚠️ Renvoie du HTML DÉJÀ ÉCHAPPÉ (pas du texte brut) — le cas « orders » a
 * besoin du « er » en exposant de pf_date_i18n_ordinal(), donc l'appelant
 * (pf_account_hub_tiles()) ne doit plus passer la valeur dans esc_html(). Même
 * motif que pf_render_meta_row()/$main_is_raw_html (inc/events.php) : chaque
 * branche doit échapper elle-même ses morceaux dynamiques.
 */
function pf_account_hub_state( string $key ): string {
	$user_id = get_current_user_id();

	switch ( $key ) {
		case 'downloads':
			if ( ! class_exists( 'Passiflore_Ebooks' ) ) {
				return '';
			}
			$n = count( Passiflore_Ebooks::entitled_product_ids( $user_id ) );

			return $n ? esc_html( sprintf( _n( '%s livre à lire ou télécharger', '%s livres à lire ou télécharger', $n, 'kadence-child' ), number_format_i18n( $n ) ) ) : '';

		case 'liste-de-lecture':
			if ( ! class_exists( 'Passiflore_Reading_List' ) ) {
				return '';
			}
			$n = count( Passiflore_Reading_List::get_ids( $user_id ) );

			return $n
				? esc_html( sprintf( _n( '%s livre mis de côté', '%s livres mis de côté', $n, 'kadence-child' ), number_format_i18n( $n ) ) )
				: 'Aucun livre mis de côté';

		case 'orders':
			if ( ! function_exists( 'wc_get_orders' ) ) {
				return '';
			}
			// Même filtre que pf_account_menu_reorder() : exclut les brouillons
			// wc-checkout-draft, que wc_get_orders() compterait sinon comme de vraies
			// commandes.
			$orders = wc_get_orders(
				apply_filters(
					'woocommerce_my_account_my_orders_query',
					[ 'customer_id' => $user_id, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC' ]
				)
			);
			if ( ! $orders ) {
				return '';
			}
			$order = reset( $orders );
			$date  = $order->get_date_created();

			// pf_date_i18n_ordinal() attend le timestamp « décalé » de pf_date_i18n_ts()
			// (inc/events.php), pas l'instant UTC réel de WC_DateTime::getTimestamp().
			$date_html = '';
			if ( $date && function_exists( 'pf_date_i18n_ts' ) ) {
				$ts        = pf_date_i18n_ts( $date );
				$format    = ( (int) date( 'Y', $ts ) === (int) current_time( 'Y' ) ) ? 'j F' : 'j F Y';
				$date_html = pf_date_i18n_ordinal( $format, $ts );
			}

			// Statut tu si Terminée — seul un statut « anormal » (en attente, annulée…)
			// vaut la peine d'être signalé sur la tuile.
			$status = $order->get_status();
			$suffix = ( 'completed' !== $status ) ? ' — ' . esc_html( wc_get_order_status_name( $status ) ) : '';

			return 'Dernière commande le ' . $date_html . $suffix;

		case 'edit-address':
			$customer      = new WC_Customer( $user_id );
			$billing_addr  = trim( (string) $customer->get_billing_address_1() );
			$billing_city  = trim( (string) $customer->get_billing_city() );
			$shipping_addr = trim( (string) $customer->get_shipping_address_1() );
			$shipping_city = trim( (string) $customer->get_shipping_city() );

			$has_billing  = '' !== $billing_addr;
			$has_shipping = '' !== $shipping_addr;

			if ( ! $has_billing && ! $has_shipping ) {
				return 'Aucune adresse renseignée';
			}
			if ( $has_billing !== $has_shipping ) {
				// Une seule des deux manque : nommer celle qui manque plutôt qu'un
				// compte générique (« 1 adresse ») qui ne dit pas laquelle.
				return 'Adresse de ' . ( $has_billing ? 'livraison' : 'facturation' ) . ' non renseignée';
			}
			if ( $billing_addr === $shipping_addr && $billing_city === $shipping_city ) {
				// Livraison = facturation : une seule adresse existe vraiment, pas
				// la peine de compter « 2 adresses » pour la même adresse deux fois.
				return esc_html( $billing_addr . ', ' . $billing_city );
			}

			return '2 adresses renseignées';

		case 'payment-methods':
			if ( ! class_exists( 'WC_Payment_Tokens' ) ) {
				return '';
			}
			$n = count( WC_Payment_Tokens::get_customer_tokens( $user_id ) );

			return $n
				? esc_html( sprintf( _n( '%s moyen de paiement renseigné', '%s moyens de paiement renseignés', $n, 'kadence-child' ), number_format_i18n( $n ) ) )
				: 'Aucun moyen de paiement renseigné';

		case 'edit-account':
			$user = wp_get_current_user();

			return $user ? esc_html( $user->user_email ) : '';
	}

	return '';
}

/**
 * Grille de tuiles. Composant `.pf-card` de style.css, en carte cliquable à lien
 * étiré — même motif que `.pf-hero-cat` de la page d'accueil (carte de texte,
 * sans image).
 */
function pf_account_hub_tiles(): string {
	if ( ! function_exists( 'wc_get_account_menu_items' ) ) {
		return '';
	}

	$skip  = pf_account_hub_skip();
	$tiles = '';

	foreach ( wc_get_account_menu_items() as $key => $label ) {
		if ( in_array( $key, $skip, true ) ) {
			continue;
		}

		$state = pf_account_hub_state( $key );

		$tiles .= '<div class="pf-card pf-account-tile">'
			. '<a class="pf-card-link" href="' . esc_url( wc_get_account_endpoint_url( $key ) ) . '"'
			. ' aria-label="' . esc_attr( $label ) . '"></a>'
			. '<div class="pf-card-content">'
			. '<h2 class="pf-card-title">' . esc_html( $label ) . '</h2>'
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pf_account_hub_state() renvoie déjà du HTML échappé (voir sa docblock).
			. ( $state ? '<p class="pf-card-text">' . $state . '</p>' : '' )
			. '</div>'
			. '</div>';
	}

	if ( '' === $tiles ) {
		return '';
	}

	return '<div class="pf-account-tiles">' . $tiles . '</div>';
}

/**
 * Lien de déconnexion sous la grille.
 *
 * ⚠️ INDISPENSABLE, et pas décoratif : la navigation latérale est masquée sur le
 * hub (voir plus bas) et `customer-logout` est écarté des tuiles — sans ce lien,
 * il n'y aurait plus AUCUN moyen de se déconnecter depuis l'accueil du compte.
 * `pf_auth_logout_without_confirm()` (inc/account-auth.php) évite la
 * confirmation WooCommerce si le nonce de l'URL a expiré.
 */
function pf_account_hub_logout(): string {
	if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
		return '';
	}

	return '<p class="pf-account-logout"><a class="pf-btn pf-btn--outline" href="'
		. esc_url( wc_get_account_endpoint_url( 'customer-logout' ) )
		. '">Se déconnecter</a></p>';
}

/**
 * Navigation latérale masquée SUR LE HUB UNIQUEMENT : les tuiles en tiennent
 * lieu, afficher les deux serait la même liste de liens deux fois (et, sur
 * mobile, l'une au-dessus de l'autre). Elle réapparaît dès qu'on entre dans une
 * page de compte.
 *
 * Par hook et non par le template : `myaccount/navigation.php` reste un pur
 * rendeur, et c'est la fonctionnalité « hub » qui porte la décision.
 * `is_wc_endpoint_url()` sans argument est le test canonique « suis-je sur
 * l'accueil du compte ? » — le tableau de bord est la seule vue du compte sans
 * endpoint. Le contrôle `is_user_logged_in()` écarte /connexion et
 * /creer-un-compte, qui résolvent eux aussi sur la page compte sans endpoint
 * (cf. inc/account-auth.php) : le cœur n'y rend pas la nav, mais le test doit
 * rester juste par lui-même.
 */
add_action( 'wp', 'pf_account_hub_hide_nav' );
function pf_account_hub_hide_nav(): void {
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}
	if ( ! is_user_logged_in() || is_wc_endpoint_url() ) {
		return;
	}

	remove_action( 'woocommerce_account_navigation', 'woocommerce_account_navigation' );
}
