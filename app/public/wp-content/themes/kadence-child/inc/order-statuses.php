<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Statuts de commande sur mesure — plan complet dans
 * ~/.claude/plans/voici-les-statuts-que-rippling-cook.md.
 *
 * 12 statuts racontant le cycle de vie réel d'une commande (papier expédié /
 * papier retiré en boutique / numérique), chacun déclenchant un email client
 * (cf. inc/order-emails.php). Réutilise `wc-pending`/`wc-processing` du cœur
 * (juste relibellés) pour garder gratuitement toute leur plomberie ; n'ajoute
 * que 5 statuts réellement nouveaux.
 *
 * ⚠️ Les filtres WooCommerce de cette zone ont DEUX conventions de slug
 * incompatibles, vérifiées dans le cœur (WC 10.6.1) avant d'écrire quoi que ce
 * soit ici — les mélanger casse tout en silence :
 *   - préfixées `wc-` : `wc_order_statuses` (libellés), et le tableau consommé
 *     par `woocommerce_register_shop_order_post_statuses` (source :
 *     `Automattic\WooCommerce\Enums\OrderInternalStatus`) ;
 *   - nues, sans préfixe : `has_status()`, `woocommerce_order_is_paid_statuses`,
 *     `woocommerce_valid_order_statuses_for_*`,
 *     `woocommerce_order_is_download_permitted` (source :
 *     `Automattic\WooCommerce\Enums\OrderStatus`).
 */

/* ═══════════════════════════════════════════════════════════════
   1. Source unique : slugs (préfixés wc-) → libellés
   ═══════════════════════════════════════════════════════════════ */

/** Les 5 statuts réellement nouveaux, dans l'ordre du cycle de vie. */
function pf_order_statuses(): array {
	return [
		'wc-pf-precommande' => 'En attente de disponibilité',
		'wc-pf-retrait-att'  => 'À retirer en boutique',
		'wc-pf-expediee'     => 'Expédiée',
		'wc-pf-livree'       => 'Livrée',
		'wc-pf-retiree'      => 'Retirée en magasin',
	];
}

/** Mêmes slugs, sans le préfixe `wc-` — forme attendue par has_status() et consorts. */
function pf_order_status_slugs_bare(): array {
	return array_map( static fn( $slug ) => substr( $slug, 3 ), array_keys( pf_order_statuses() ) );
}

/* ═══════════════════════════════════════════════════════════════
   2. Enregistrement — même mécanisme que le cœur (register_post_status()
      sous le capot), pas un appel direct : garantit le même timing (init@9)
      et les mêmes propriétés que les statuts natifs, dont dépend
      get_post_status_object() (utilisé par la liste HPOS pour afficher le
      statut de chaque ligne — sans cet enregistrement, erreur fatale).
   ═══════════════════════════════════════════════════════════════ */

add_filter( 'woocommerce_register_shop_order_post_statuses', 'pf_register_order_post_statuses' );
function pf_register_order_post_statuses( array $statuses ): array {
	foreach ( pf_order_statuses() as $slug => $label ) {
		$statuses[ $slug ] = [
			'label'                     => $label,
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: nombre de commandes */
			'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>' ),
		];
	}
	return $statuses;
}

/* ═══════════════════════════════════════════════════════════════
   3. Libellés + ordre d'affichage (menu déroulant fiche commande,
      filtres de la liste, export CSV…)
   ═══════════════════════════════════════════════════════════════ */

/**
 * Relibellé + réordonnancement complet de la liste affichée à l'admin.
 *
 * ⚠️ « Brouillon » (wc-checkout-draft) DOIT rester dans ce tableau — c'est
 * tentant de l'omettre pour le faire disparaître du menu déroulant, mais
 * `wc_get_order_statuses()` n'est pas qu'un simple libellé d'affichage :
 * `WC_Order::set_status()` (cœur) l'utilise aussi comme LISTE DE VALIDITÉ —
 * `get_valid_statuses()` — et retombe silencieusement sur `pending` pour tout
 * statut absent de cette liste. Un premier essai qui l'omettait ici a cassé
 * `OrderController::create_order_from_cart()` du bloc Checkout : CHAQUE visite
 * de /commander créait directement une vraie commande « En attente de
 * paiement », jamais un brouillon — plus de réservation de stock pendant la
 * saisie, plus de nettoyage automatique à 24h (`delete_expired_draft_orders()`
 * ne cible que le statut `checkout-draft`, jamais atteint dans ce cas). Le
 * masquage du menu déroulant se fait UNIQUEMENT par le filtre JS de la
 * section 9 (`pf_relevant_order_statuses_for()` ne le propose jamais) — un
 * problème d'AFFICHAGE ne doit jamais se corriger en cassant la VALIDITÉ.
 *
 * `wc-on-hold` est conservé (WooCommerce Payments peut encore y placer une
 * commande en litige) mais relibellé et relégué en fin de liste : le tunnel
 * ne l'atteint plus jamais côté virement/chèque/espèces (cf. section 5).
 */
add_filter( 'wc_order_statuses', 'pf_reorder_order_statuses' );
function pf_reorder_order_statuses( array $statuses ): array {
	$relabels = [
		'wc-pending'    => 'En attente de paiement',
		'wc-processing' => 'En cours de préparation',
		'wc-on-hold'    => 'Paiement à vérifier',
	];
	foreach ( $relabels as $slug => $label ) {
		if ( isset( $statuses[ $slug ] ) ) {
			$statuses[ $slug ] = $label;
		}
	}

	$pf     = pf_order_statuses();
	$cycle  = [
		'wc-checkout-draft', 'wc-pending', 'wc-pf-precommande', 'wc-processing', 'wc-pf-retrait-att',
		'wc-pf-expediee', 'wc-pf-livree', 'wc-pf-retiree',
		'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-on-hold',
	];

	$ordered = [];
	foreach ( $cycle as $slug ) {
		if ( isset( $pf[ $slug ] ) ) {
			$ordered[ $slug ] = $pf[ $slug ];
		} elseif ( isset( $statuses[ $slug ] ) ) {
			$ordered[ $slug ] = $statuses[ $slug ];
		}
	}

	return $ordered;
}

/* ═══════════════════════════════════════════════════════════════
   4. Plomberie obligatoire — un statut créé par register_post_status()
      n'est qu'une étiquette, le cœur ignore tout de sa sémantique tant
      qu'on ne le lui dit pas explicitement, filtre par filtre.
   ═══════════════════════════════════════════════════════════════ */

/**
 * « Payé » : sans ça, wc_customer_bought_product() cesse de reconnaître
 * l'acheteur dès que sa commande quitte processing/completed — les avis
 * « achat vérifié » de la fiche livre tombent pour toute commande Expédiée,
 * Livrée, Retirée, ou en attente de disponibilité/retrait.
 */
add_filter( 'woocommerce_order_is_paid_statuses', 'pf_add_paid_statuses' );
function pf_add_paid_statuses( array $statuses ): array {
	return array_merge( $statuses, pf_order_status_slugs_bare() );
}

/**
 * Autorise les téléchargements pour ces 5 statuts. Sans ce filtre,
 * `WC_Order::is_download_permitted()` (codé en dur sur completed/processing
 * dans le cœur) bloque `get_downloadable_items()` — utilisé par l'email
 * invité (inc/epub-storage.php) — ET `wc_get_customer_available_downloads()`
 * — utilisé par la bibliothèque du compte (inc/class-ebooks.php) — même si
 * les droits existent déjà en base. Sans lui : un livre numérique déjà payé
 * et déjà accordé resterait invisible tant que sa commande n'atteint pas
 * completed/processing (jamais, pour une commande 100% retrait en boutique
 * depuis que le paiement y mène directement, cf. section 6).
 */
add_filter( 'woocommerce_order_is_download_permitted', 'pf_add_download_permitted_statuses', 10, 2 );
function pf_add_download_permitted_statuses( bool $permitted, WC_Order $order ): bool {
	return $permitted || $order->has_status( pf_order_status_slugs_bare() );
}

/** « Commander à nouveau » : sans ça, disparaît pour toute commande papier terminée (Livrée/Retirée). */
add_filter( 'woocommerce_valid_order_statuses_for_order_again', 'pf_add_order_again_statuses' );
function pf_add_order_again_statuses( array $statuses ): array {
	return array_merge( $statuses, [ 'pf-livree', 'pf-retiree' ] );
}

/**
 * Droits de téléchargement + réduction/libération de stock : mêmes hooks que
 * le cœur pose sur `completed`/`processing`/`on-hold`
 * (wc-order-functions.php, wc-stock-functions.php), posés ici sur les 5
 * statuts sur mesure.
 *
 * `wc_downloadable_product_permissions()` a sa propre garde de ré-entrance
 * (méta `_download_permissions_granted` posée dès le premier appel) : la
 * poser sur 5 actions est sans risque, elle ne s'exécute réellement qu'une
 * fois par commande.
 *
 * `wc_maybe_reduce_stock_levels()`/`wc_release_stock_for_order()` sont sans
 * effet aujourd'hui (`woocommerce_manage_stock = no` sur tout le catalogue)
 * mais câblés pour ne pas piéger un futur passage en gestion de stock.
 */
add_action( 'init', 'pf_wire_order_status_plumbing', 20 );
function pf_wire_order_status_plumbing(): void {
	foreach ( pf_order_status_slugs_bare() as $slug ) {
		add_action( 'woocommerce_order_status_' . $slug, 'wc_downloadable_product_permissions' );
		if ( function_exists( 'wc_maybe_reduce_stock_levels' ) ) {
			add_action( 'woocommerce_order_status_' . $slug, 'wc_maybe_reduce_stock_levels' );
		}
		if ( function_exists( 'wc_release_stock_for_order' ) ) {
			add_action( 'woocommerce_order_status_' . $slug, 'wc_release_stock_for_order', 11 );
		}
	}
}

/**
 * Fait émettre un email transactionnel à l'arrivée sur chacun des 5 statuts.
 *
 * `WC_Emails::init_transactional_emails()` (cœur) ne ré-émet
 * `{action}_notification` (avec capture d'exception, pour qu'un email cassé
 * ne bloque jamais le changement de statut lui-même) QUE pour les actions de
 * cette liste — sans cette ligne, aucun email sur mesure ne partirait jamais,
 * quels que soient les hooks posés dans inc/order-emails.php.
 */
add_filter( 'woocommerce_email_actions', 'pf_add_order_status_email_actions' );
function pf_add_order_status_email_actions( array $actions ): array {
	foreach ( pf_order_status_slugs_bare() as $slug ) {
		$actions[] = 'woocommerce_order_status_' . $slug;
	}
	return $actions;
}

/* ═══════════════════════════════════════════════════════════════
   5. Passerelles manuelles (virement / chèque / espèces) → un seul
      statut d'attente, avec confirmation de commande par email.
   ═══════════════════════════════════════════════════════════════ */

/**
 * Rapatrie les trois passerelles sur `pending` au lieu de `on-hold`.
 *
 * Écart volontaire du défaut cœur pour la passerelle espèces (COD) : elle ne
 * fait normalement attendre que la partie numérique d'une commande
 * (`$order->has_downloadable_item() ? on-hold : processing`) — une commande
 * papier réglée en espèces part directement en `processing`. La demande
 * couvre les trois passerelles sans distinction papier/numérique : on force
 * donc aussi le cas papier vers `pending`.
 */
add_filter( 'woocommerce_bacs_process_payment_order_status', 'pf_reroute_manual_gateway_status', 10, 2 );
add_filter( 'woocommerce_cheque_process_payment_order_status', 'pf_reroute_manual_gateway_status', 10, 2 );
add_filter( 'woocommerce_cod_process_payment_order_status', 'pf_reroute_manual_gateway_status', 10, 2 );
function pf_reroute_manual_gateway_status( string $status, WC_Order $order ): string {
	pf_send_manual_payment_confirmation( $order );
	return 'pending';
}

/**
 * Déclenche explicitement les emails « nouvelle commande » (admin) et
 * « en attente de règlement » (client) — repositionner une commande sur
 * `pending` alors qu'elle y est déjà N'EST PAS une transition de statut, donc
 * `WC_Email_New_Order` et `WC_Email_Customer_On_Hold_Order` (qui ne
 * s'accrochent QUE sur l'action `..._pending_to_on-hold_notification`,
 * vérifié dans le cœur) ne partiraient jamais tout seuls. Leur CONTENU reste
 * exactement le bon (« merci pour votre commande, en attente de règlement »,
 * coordonnées bancaires/chèque/espèces) — on se contente de les déclencher au
 * bon moment plutôt que d'en écrire une copie.
 *
 * Point d'accroche choisi ici (le filtre de statut de la passerelle, PAS un
 * hook générique de fin de commande) : c'est le seul endroit où l'on connaît
 * SANS AMBIGUÏTÉ à la fois la commande ET la passerelle en cours — à
 * `woocommerce_store_api_checkout_order_processed`, le paiement n'a pas
 * encore été traité et `$order->get_payment_method()` n'est pas fiable.
 *
 * Garde anti-double-appel : filet contre un double POST de checkout (double
 * clic), pas contre un usage légitime répété (chaque commande a son propre
 * ID, la garde est donc par ID et par requête PHP).
 */
function pf_send_manual_payment_confirmation( WC_Order $order ): void {
	static $done = [];
	$order_id = $order->get_id();
	if ( isset( $done[ $order_id ] ) ) {
		return;
	}
	$done[ $order_id ] = true;

	$mailer = WC()->mailer()->emails;
	if ( isset( $mailer['WC_Email_Customer_On_Hold_Order'] ) ) {
		$mailer['WC_Email_Customer_On_Hold_Order']->trigger( $order_id, $order );
	}
	if ( isset( $mailer['WC_Email_New_Order'] ) ) {
		$mailer['WC_Email_New_Order']->trigger( $order_id, $order );
	}
}

/* ═══════════════════════════════════════════════════════════════
   6. Routage automatique vers « En attente de disponibilité » et
      « À retirer en boutique » — un seul point d'interception pour
      le paiement automatique ET le passage manuel par l'admin.
   ═══════════════════════════════════════════════════════════════ */

/** Vrai si un article de la commande est en rupture ou numérique sans fichier. */
function pf_order_needs_precommande( WC_Order $order ): bool {
	foreach ( $order->get_items() as $item ) {
		$product = $item->get_product();
		if ( ! $product ) {
			continue;
		}
		if ( 'onbackorder' === $product->get_stock_status() ) {
			return true;
		}
		if ( $product->is_downloadable() && ! $product->get_downloads() ) {
			return true;
		}
	}
	return false;
}

/** Vrai si la commande est à retirer en boutique (méthode Blocks « Local Pickup »). */
function pf_order_is_pickup( WC_Order $order ): bool {
	foreach ( $order->get_shipping_methods() as $shipping ) {
		if ( 'pickup_location' === $shipping->get_method_id() ) {
			return true;
		}
	}
	return false;
}

/**
 * Redirige toute commande sur le point d'arriver en `processing` vers
 * `pf-precommande` (article indisponible) ou `pf-retrait-att` (retrait en
 * boutique), avant même que `processing` soit écrit en base.
 *
 * Pourquoi ICI et pas via `woocommerce_payment_complete_order_status` (le
 * filtre habituel pour ce genre de redirection) : ce filtre ne couvre que le
 * paiement automatique (carte). Le passage MANUEL (admin qui encaisse un
 * virement/chèque/espèces à la main et choisit « En cours de préparation »
 * dans le menu déroulant) ne passe jamais par `payment_complete()` — il
 * fallait un second point d'interception pour ce chemin, avec le risque
 * qu'une commande y transite brièvement PAR `processing` avant d'être
 * redirigée, déclenchant à tort l'email « En cours de préparation ».
 *
 * `woocommerce_before_order_object_save` couvre les DEUX chemins d'un seul
 * coup, sans ce risque : il se déclenche dans `WC_Order::save()`, AVANT
 * l'écriture en base ET avant que `status_transition()` n'émette la moindre
 * notification (vérifié dans le cœur, class-wc-order.php). `set_status()`
 * étant lui-même conçu pour être appelé plusieurs fois avant un save() — il
 * préserve le `from` d'origine à travers les appels successifs
 * (`$this->status_transition['from']` déjà posé sinon repris) — rediriger ici
 * fait que `processing` n'est JAMAIS persisté ni notifié : la commande passe
 * directement de `pending` (ou `on-hold`) à son statut réel.
 */
add_action( 'woocommerce_before_order_object_save', 'pf_route_processing_transition', 10, 1 );
function pf_route_processing_transition( $order ): void {
	if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
		return;
	}
	if ( 'processing' !== $order->get_status() ) {
		return;
	}

	if ( pf_order_needs_precommande( $order ) ) {
		$order->set_status( 'pf-precommande', '', false );
	} elseif ( pf_order_is_pickup( $order ) ) {
		$order->set_status( 'pf-retrait-att', '', false );
	}
}

/**
 * Fait sortir une commande de « En attente de disponibilité » dès qu'elle
 * n'a plus aucun article indisponible — vers `completed` si elle est 100%
 * numérique (`needs_processing()`, même critère que le cœur pour ce choix),
 * sinon vers `processing` : si la commande est aussi un retrait en boutique,
 * `pf_route_processing_transition()` ci-dessus la redirigera d'elle-même
 * vers `pf-retrait-att`, sans dupliquer ce test ici.
 *
 * Deux appelants : le dépôt d'un fichier ePub (inc/epub-storage.php) et la
 * disponibilité d'un livre papier qui quitte « à paraître » (plus bas).
 */
function pf_order_release_from_precommande( WC_Order $order ): void {
	if ( ! $order->has_status( 'pf-precommande' ) || pf_order_needs_precommande( $order ) ) {
		return;
	}
	$order->update_status( $order->needs_processing() ? 'processing' : 'completed' );
}

/**
 * Libère les commandes en attente de disponibilité dès qu'un livre papier
 * quitte « à paraître » — greffé sur les mêmes hooks que
 * `passiflore_sync_stock_status_from_disponibilite()` (inc/stock-sync.php),
 * à une priorité postérieure (21 vs 20) pour lire `_stock_status` une fois
 * la synchro faite plutôt que de la dupliquer ici.
 */
add_action( 'acf/save_post', 'pf_release_precommande_orders_for_product', 21 );
add_action( 'save_post_product', 'pf_release_precommande_orders_for_product', 21 );
function pf_release_precommande_orders_for_product( $post_id ): void {
	if ( ! is_numeric( $post_id ) || 'product' !== get_post_type( $post_id ) ) {
		return;
	}

	$product = wc_get_product( (int) $post_id );
	if ( ! $product || 'onbackorder' === $product->get_stock_status() ) {
		return; // Toujours indisponible : rien à libérer.
	}

	global $wpdb;
	$order_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT oi.order_id
			FROM {$wpdb->prefix}woocommerce_order_items oi
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
				ON oim_pid.order_item_id = oi.order_item_id AND oim_pid.meta_key = '_product_id'
			WHERE oim_pid.meta_value = %d",
			(int) $post_id
		)
	);

	foreach ( $order_ids as $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof WC_Order ) {
			pf_order_release_from_precommande( $order );
		}
	}
}

/**
 * `WC_Email_New_Order` n'écoute nativement que 3 transitions codées en dur
 * (`pending_to_processing`, `_to_completed`, `_to_on-hold`, cf.
 * class-wc-email-new-order.php du cœur) — jamais nos deux statuts maison. Un
 * paiement automatique (carte/WooCommerce Payments) routé directement vers
 * `pf-precommande`/`pf-retrait-att` par `pf_route_processing_transition()`
 * ci-dessus ne prévenait donc jamais la boutique.
 *
 * Point d'accroche : `woocommerce_payment_complete`, fin de
 * `WC_Order::payment_complete()` — SEUL le paiement automatique l'appelle
 * pour une commande payante. Les 3 passerelles manuelles (virement/chèque/
 * espèces) ne l'appellent jamais dans ce cas : leur `process_payment()`
 * fait `update_status()`, pas `payment_complete()` (cœur, vérifié dans les 3
 * classes de passerelle) — pas de doublon avec
 * `pf_send_manual_payment_confirmation()`, qui a déjà notifié la boutique à
 * la création de la commande. `$order->get_status()` reflète déjà le statut
 * final à ce point : `payment_complete()` appelle `save()` avant d'émettre
 * cette action (cœur, class-wc-order.php:183-185), et notre redirection
 * s'exécute de façon synchrone dans ce même `save()` via
 * `woocommerce_before_order_object_save`.
 *
 * Rejeu sans risque : `payment_complete()` ne ré-émet cette action que si la
 * commande est encore dans un statut « à payer » (`pending`/`on-hold`/
 * `failed`) — un second appel sur une commande déjà routée en precommande/
 * retrait-att n'entre jamais dans cette branche, donc pas de second email.
 */
add_action( 'woocommerce_payment_complete', 'pf_notify_admin_precommande_retrait_direct', 20, 1 );
function pf_notify_admin_precommande_retrait_direct( $order_id ): void {
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	if ( ! in_array( $order->get_status(), [ 'pf-precommande', 'pf-retrait-att' ], true ) ) {
		return;
	}

	$mailer = WC()->mailer()->emails;
	if ( isset( $mailer['WC_Email_New_Order'] ) ) {
		$mailer['WC_Email_New_Order']->trigger( $order_id, $order );
	}
}

/* ═══════════════════════════════════════════════════════════════
   7. Actions groupées — la convention `mark_{slug}` du cœur
      (Automattic\WooCommerce\Internal\Admin\Orders\ListTable::handle_bulk_actions())
      traite déjà tout changement de statut par lot : ajouter l'entrée au
      menu suffit, aucun gestionnaire à écrire.
   ═══════════════════════════════════════════════════════════════ */

/**
 * Les 4 actions natives (`mark_processing`, `mark_on-hold`, `mark_completed`,
 * `mark_cancelled`) sont codées en dur dans `ListTable::define_bulk_actions()`
 * avec leur propre libellé anglais traduit (« Change status to processing »
 * → « Marquer en cours ») — elles ne lisent JAMAIS `wc_get_order_statuses()`,
 * donc notre relibellé (« En cours de préparation », section 3) ne les
 * atteint pas : elles restaient figées sur l'ancienne terminologie,
 * détonnant au milieu des 5 nôtres. On les retire et les reconstruit ici
 * avec le même format et les libellés à jour, dans l'ordre du cycle de vie.
 *
 * `refunded`/`failed` volontairement absents : un remboursement par lot sans
 * passer par l'interface de remboursement (qui calcule le montant réel)
 * afficherait « Remboursée » sans qu'aucun argent n'ait bougé — c'est
 * d'ailleurs pourquoi le cœur ne l'a jamais proposé nativement. `failed`
 * suit la même logique que dans le menu déroulant filtré (section 9) :
 * jamais un choix qu'on fait à la main.
 */
add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'pf_add_status_bulk_actions' );
add_filter( 'bulk_actions-edit-shop_order', 'pf_add_status_bulk_actions' );
function pf_add_status_bulk_actions( array $actions ): array {
	unset( $actions['mark_processing'], $actions['mark_on-hold'], $actions['mark_completed'], $actions['mark_cancelled'] );

	$labels = wc_get_order_statuses(); // Déjà relibellé par pf_reorder_order_statuses() (section 3).
	$cycle  = [ 'pf-precommande', 'processing', 'pf-retrait-att', 'pf-expediee', 'pf-livree', 'pf-retiree', 'completed', 'cancelled', 'on-hold' ];

	foreach ( $cycle as $bare ) {
		if ( isset( $labels[ 'wc-' . $bare ] ) ) {
			$actions[ 'mark_' . $bare ] = 'Marquer comme « ' . $labels[ 'wc-' . $bare ] . ' »';
		}
	}

	return $actions;
}

/* ═══════════════════════════════════════════════════════════════
   8. Pastilles de statut — couleurs propres, même idiome que celles
      du cœur (assets/css/admin.css : fond pastel + texte foncé).
   ═══════════════════════════════════════════════════════════════ */

add_action( 'admin_enqueue_scripts', 'pf_enqueue_order_status_badge_css' );
function pf_enqueue_order_status_badge_css( string $hook ): void {
	if ( ! in_array( $hook, [ 'woocommerce_page_wc-orders', 'edit.php' ], true ) ) {
		return;
	}

	wp_add_inline_style( 'wp-admin', '
		.order-status.status-pf-precommande { background: #ded1f0; color: #3d1a66; }
		.order-status.status-pf-retrait-att  { background: #c6e8e1; color: #00473a; }
		.order-status.status-pf-expediee     { background: #c6d9f0; color: #002966; }
		.order-status.status-pf-livree       { background: #cde9c9; color: #1f5c1f; }
		.order-status.status-pf-retiree      { background: #c9e9d8; color: #0f5c3f; }
	' );
}

/* ═══════════════════════════════════════════════════════════════
   9. Menu déroulant de la fiche commande — ne proposer par défaut
      que les statuts pertinents pour CETTE commande à CE stade,
      avec un lien « Afficher tous les statuts » pour lever le filtre.
   ═══════════════════════════════════════════════════════════════ */

/**
 * Calcule la « branche » de statuts propre à une commande (numérique pur,
 * retrait en boutique, ou expédition), dans l'ordre où elle les traverse
 * normalement — même critère que `pf_route_processing_transition()` pour
 * décider quelle branche (`needs_processing()`, `pf_order_is_pickup()`).
 */
function pf_order_status_branch( WC_Order $order ): array {
	if ( ! $order->needs_processing() ) {
		return [ 'pending', 'pf-precommande', 'completed' ];
	}
	if ( pf_order_is_pickup( $order ) ) {
		return [ 'pending', 'pf-precommande', 'pf-retrait-att', 'pf-retiree' ];
	}
	return [ 'pending', 'pf-precommande', 'processing', 'pf-expediee', 'pf-livree' ];
}

/**
 * Statuts « pertinents » à afficher par défaut dans le menu déroulant d'UNE
 * commande donnée — tout ce qui est déjà DERRIÈRE le statut courant sur sa
 * branche est masqué (ex. une commande passée par `precommande` puis
 * `processing` ne réoffre plus `precommande`), ainsi que les statuts propres
 * à une autre branche (une commande à domicile n'offre jamais « À retirer en
 * boutique »). `on-hold` et `checkout-draft` restent TOUJOURS masqués par
 * défaut, quel que soit le statut courant — orphelins du tunnel actuel,
 * réservés au bouton d'échappement.
 *
 * `pf-precommande` n'est proposée que si la commande a une VRAIE raison d'y
 * aller aujourd'hui (`pf_order_needs_precommande()`) : la branche décrit la
 * forme générale du parcours (papier expédié / retrait / numérique), pas
 * l'état réel de CETTE commande — sans ce filtre, « En attente de
 * disponibilité » apparaissait même sur une commande dont tous les articles
 * sont disponibles.
 *
 * `failed` n'est jamais proposée : c'est la passerelle de paiement elle-même
 * qui y place une commande quand un règlement échoue réellement, jamais un
 * choix fait dans ce menu. Comme `on-hold`, reléguée à « Afficher tous les
 * statuts » pour la correction manuelle exceptionnelle.
 *
 * États d'exception (Annulée/Remboursée/Échouée/Paiement à vérifier/Brouillon)
 * en statut courant : ne font pas partie d'une branche, jeu minimal —
 * rouvrir vers un statut précis à ce stade est volontairement réservé au
 * bouton « Afficher tous les statuts », pas au filtre par défaut.
 */
function pf_relevant_order_statuses_for( WC_Order $order ): array {
	$current = $order->get_status();

	if ( in_array( $current, [ 'cancelled', 'refunded', 'failed', 'on-hold', 'checkout-draft' ], true ) ) {
		return array_unique( [ $current, 'pending', 'cancelled' ] );
	}

	$branch = pf_order_status_branch( $order );
	$rank   = array_search( $current, $branch, true );

	if ( false === $rank ) {
		return array_unique( [ $current, 'pending', 'cancelled' ] );
	}

	$relevant = array_slice( $branch, $rank );

	if ( 'pf-precommande' !== $current && ! pf_order_needs_precommande( $order ) ) {
		$relevant = array_diff( $relevant, [ 'pf-precommande' ] );
	}

	if ( $rank < count( $branch ) - 1 ) {
		$relevant[] = 'cancelled'; // Tant qu'on n'est pas au bout de la branche.
	}
	if ( $rank >= 1 ) {
		$relevant[] = 'refunded'; // Dès que la commande est payée.
	}

	return array_values( array_unique( $relevant ) );
}

/**
 * Filtre le menu déroulant « Statut » de la fiche commande (JS, pas de hook
 * PHP disponible côté cœur pour cette liste précise — vérifié dans
 * `WC_Meta_Box_Order_Data`, la boucle d'options n'est entourée d'aucun
 * filtre). Options non pertinentes DÉTACHÉES du <select> (pas seulement
 * masquées) : Select2 v4 relit les <option> du <select> natif à chaque
 * ouverture, donc les retirer avant la première interaction suffit, sans
 * avoir à ré-initialiser le widget. Le bouton « Afficher tous les statuts »
 * les réinjecte à leur place d'origine.
 */
add_action( 'admin_enqueue_scripts', 'pf_enqueue_order_status_filter' );
function pf_enqueue_order_status_filter( string $hook ): void {
	if ( 'woocommerce_page_wc-orders' !== $hook ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture seule, choix d'écran.
	$order_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	if ( ! $order_id ) {
		return;
	}
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$relevant = array_map( static fn( $slug ) => 'wc-' . $slug, pf_relevant_order_statuses_for( $order ) );

	wp_add_inline_script( 'jquery', '
		jQuery( function ( $ ) {
			var relevant = ' . wp_json_encode( array_values( $relevant ) ) . ';
			var $select  = $( "#order_status" );
			if ( ! $select.length ) { return; }

			var $hidden = $select.find( "option" ).filter( function () {
				return relevant.indexOf( $( this ).val() ) === -1;
			} );
			if ( ! $hidden.length ) { return; }

			// Position d\'origine mémorisée par l\'option qui la précède, pour une
			// restauration fidèle à l\'ordre du cœur (cycle de vie) plutôt qu\'un
			// simple ré-ajout en fin de liste.
			$hidden.each( function () {
				$( this ).data( "pf-prev", $( this ).prev() );
			} );
			$hidden.detach();

			var $toggle = $( "<a href=\"#\" class=\"pf-toggle-all-statuses\" style=\"display:block;margin-top:6px;font-size:12px;\">Afficher tous les statuts</a>" );
			$select.closest( "p.form-field" ).append( $toggle );

			var expanded = false;
			$toggle.on( "click", function ( e ) {
				e.preventDefault();
				expanded = ! expanded;
				if ( expanded ) {
					$hidden.each( function () {
						var $prev = $( this ).data( "pf-prev" );
						if ( $prev && $prev.length ) {
							$prev.after( this );
						} else {
							$select.prepend( this );
						}
					} );
					$toggle.text( "Statuts filtrés uniquement" );
				} else {
					$hidden.detach();
					$toggle.text( "Afficher tous les statuts" );
				}
			} );
		} );
	' );
}
