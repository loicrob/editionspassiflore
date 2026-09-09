<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Enregistrement des emails sur mesure — 5 statuts de commande
 * (inc/order-statuses.php) + « Votre livre numérique est disponible »
 * (déclenché depuis inc/epub-storage.php). Classes dans inc/emails/,
 * gabarits dans woocommerce/emails/.
 *
 * ⚠️ Les require_once des classes vivent DANS le callback, pas au niveau du
 * fichier. Le cœur ne charge sa propre classe de base `WC_Email`
 * (`includes/emails/class-wc-email.php`) que dans `WC_Emails::init()`, juste
 * avant d'appliquer `woocommerce_email_classes` — et `WC_Emails::instance()`
 * n'est déclenchée qu'à la demande (envoi d'un email, écran de réglages),
 * pas à chaque requête. Charger nos classes `extends WC_Email` plus tôt (au
 * niveau du fichier, exécuté par functions.php au chargement du thème) les
 * ferait résoudre AVANT que `WC_Email` existe sur toute requête où le
 * mailer n'a pas encore été sollicité — erreur fatale « Class WC_Email not
 * found ». En les chargeant ici, elles ne sont require que lorsque ce
 * filtre s'exécute, donc après coup.
 */

/**
 * Textes réutilisés tels quels par plusieurs emails NATIFS WooCommerce
 * (inc/emails/class-email-natives-override.php) — un seul endroit à modifier
 * pour changer un texte partout où il apparaît. Défauts d'origine WooCommerce
 * (fr_FR) recopiés ici comme point de départ, désormais nos propres défauts.
 */
function pf_email_shared_text( string $key ): string {
	static $texts = null;
	if ( null === $texts ) {
		$texts = [
			'cancelled_heading'  => __( 'Commande annulée : #{order_number}', 'kadence-child' ),
			'additional_generic' => __( 'Nous la préparons dans les plus brefs délais et vous notifierons quand elle sera expédiée. Pour toute question concernant votre commande, vous pouvez directement répondre à cet e-mail.', 'kadence-child' ),
			'additional_support' => __( 'Pour toute question concernant votre commande, vous pouvez directement répondre à cet e-mail.', 'kadence-child' ),
		];
	}
	return $texts[ $key ];
}

/**
 * Matrice des transitions « croisées » → emails à déclencher.
 *
 * Les emails maison partent à l'ARRIVÉE sur un statut `pf-*`
 * (`woocommerce_order_status_<slug>`, posé par inc/order-statuses.php) ; les
 * emails natifs partent sur des TRANSITIONS codées en dur dans le cœur
 * (`pending_to_processing`, `processing_to_cancelled`…). Toute transition
 * partant d'un statut maison tombait donc entre les deux : le client n'était
 * prévenu ni de la sortie de précommande, ni de l'annulation de sa commande.
 *
 * Déclarées ICI en un seul endroit, ces transitions alimentent à la fois
 * `woocommerce_email_actions` (sans quoi `<action>_notification` n'est jamais
 * ré-émise — cf. `WC_Emails::init_transactional_emails()`) et les
 * `add_action()` de `pf_register_order_emails()` ci-dessous.
 *
 * Aucune classe nouvelle : uniquement des sous-classes déjà enregistrées.
 *
 * `pf-precommande → completed` (commande 100% numérique libérée) et
 * `pf-precommande → pf-retrait-att` sont volontairement absents : le premier
 * est déjà couvert par l'action générique `woocommerce_order_status_completed`
 * du cœur, le second par le hook d'arrivée du statut maison.
 */
function pf_email_transitions(): array {
	$cancelled_both = [ 'WC_Email_Cancelled_Order', 'WC_Email_Customer_Cancelled_Order' ];

	return [
		// Le livre paraît / l'ePub est déposé : la commande sort de précommande.
		// Sans ça, la seule nouvelle que le client attendait vraiment était la
		// seule qu'il ne recevait jamais.
		'pf-precommande_to_processing' => [ 'WC_Email_Customer_Processing_Order' ],

		// Annulation depuis un statut maison : le cœur n'écoute que
		// `processing`/`on-hold` → `cancelled`, donc personne n'était prévenu.
		'pf-precommande_to_cancelled'  => $cancelled_both,
		'pf-retrait-att_to_cancelled'  => $cancelled_both,
		'pf-expediee_to_cancelled'     => $cancelled_both,

		// Commande jamais payée (virement/chèque en attente) finalement annulée :
		// le cœur ne prévient jamais le client (choix WooCommerce), ici c'est la
		// seule façon qu'il apprenne que sa commande ne viendra pas. La boutique,
		// elle, est à l'origine de l'annulation : pas de mail vendeur.
		'pending_to_cancelled'         => [ 'WC_Email_Customer_Cancelled_Order' ],
	];
}

/** Classes natives remplacées : clé du tableau `woocommerce_email_classes` → notre sous-classe. */
function pf_native_email_overrides(): array {
	return [
		'WC_Email_New_Order'                 => 'Passiflore_Email_New_Order',
		'WC_Email_Cancelled_Order'           => 'Passiflore_Email_Cancelled_Order',
		'WC_Email_Customer_Cancelled_Order'  => 'Passiflore_Email_Customer_Cancelled_Order',
		'WC_Email_Failed_Order'              => 'Passiflore_Email_Failed_Order',
		'WC_Email_Customer_Failed_Order'     => 'Passiflore_Email_Customer_Failed_Order',
		'WC_Email_Customer_On_Hold_Order'    => 'Passiflore_Email_Customer_On_Hold_Order',
		'WC_Email_Customer_Processing_Order' => 'Passiflore_Email_Customer_Processing_Order',
		'WC_Email_Customer_Completed_Order'  => 'Passiflore_Email_Customer_Completed_Order',
		'WC_Email_Customer_Refunded_Order'   => 'Passiflore_Email_Customer_Refunded_Order',
		'WC_Email_Customer_Invoice'          => 'Passiflore_Email_Customer_Invoice',
		'WC_Email_Customer_Note'             => 'Passiflore_Email_Customer_Note',
	];
}

/**
 * Détache TOUS les hooks posés par une instance d'email donnée.
 *
 * ⚠️ Indispensable avant de remplacer une classe native, et cause du « mail
 * client en double » constaté en production : `WC_Emails::init()` fait
 * `$this->emails[ $class ] = include $path;`, et chaque fichier inclus se
 * termine par `return new WC_Email_X();` — l'instance NATIVE est donc
 * construite, et son constructeur a DÉJÀ posé ses `add_action( '…_notification' )`,
 * AVANT que `woocommerce_email_classes` ne s'applique. Réaffecter l'entrée du
 * tableau remplace la référence, jamais les hooks : sur toute transition
 * écoutée nativement (`pending → processing`, `… → completed`,
 * `processing → cancelled`…), les deux instances se déclenchaient et le client
 * recevait le même email deux fois, une fois avec les textes WooCommerce et
 * une fois avec les nôtres.
 *
 * Détachement par IDENTITÉ de l'objet plutôt que par liste de hooks codée en
 * dur : les déclencheurs natifs varient d'une version de WooCommerce à
 * l'autre, une liste figée manquerait le prochain ajout en silence. Notre
 * sous-classe, construite juste après, repose les mêmes hooks via le
 * constructeur parent — le remplacement est complet, pas superposé.
 */
function pf_unhook_email_instance( $email ): void {
	global $wp_filter;
	if ( ! is_object( $email ) ) {
		return;
	}

	$to_remove = [];
	foreach ( $wp_filter as $hook => $wp_hook ) {
		foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && isset( $callback['function'][0] ) && $callback['function'][0] === $email ) {
					$to_remove[] = [ $hook, $callback['function'], $priority ];
				}
			}
		}
	}

	// Retrait APRÈS le parcours : `remove_filter()` réécrit `$wp_filter`.
	foreach ( $to_remove as [ $hook, $callback, $priority ] ) {
		remove_filter( $hook, $callback, $priority );
	}
}

add_filter( 'woocommerce_email_classes', 'pf_register_order_emails' );
function pf_register_order_emails( array $email_classes ): array {
	require_once __DIR__ . '/emails/class-email-order-status-base.php';
	require_once __DIR__ . '/emails/class-email-precommande.php';
	require_once __DIR__ . '/emails/class-email-retrait-pret.php';
	require_once __DIR__ . '/emails/class-email-expediee.php';
	require_once __DIR__ . '/emails/class-email-livree.php';
	require_once __DIR__ . '/emails/class-email-retiree.php';
	require_once __DIR__ . '/emails/class-email-ebook-dispo.php';
	require_once __DIR__ . '/emails/class-email-natives-override.php';

	$email_classes['Passiflore_Email_Precommande']  = new Passiflore_Email_Precommande();
	$email_classes['Passiflore_Email_Retrait_Pret']  = new Passiflore_Email_Retrait_Pret();
	$email_classes['Passiflore_Email_Expediee']      = new Passiflore_Email_Expediee();
	$email_classes['Passiflore_Email_Livree']        = new Passiflore_Email_Livree();
	$email_classes['Passiflore_Email_Retiree']       = new Passiflore_Email_Retiree();
	$email_classes['Passiflore_Email_Ebook_Dispo']   = new Passiflore_Email_Ebook_Dispo();

	/*
	 * Remplace 11 classes natives par des sous-classes qui ne surchargent que
	 * get_default_subject()/get_default_heading()/get_default_additional_content()
	 * — jamais get_subject()/get_heading() eux-mêmes, qui lisent d'abord
	 * l'option enregistrée (Réglages → Emails) et ne retombent sur le défaut
	 * que si elle est vide. Filtrer la sortie écraserait donc une vraie
	 * personnalisation admin ; surcharger seulement le défaut la laisse
	 * intacte.
	 *
	 * ⚠️ Ré-affectées sous la MÊME clé de tableau (nom de classe natif), pas
	 * sous un nom Passiflore_* : le cœur WooCommerce lit certains emails par
	 * clé littérale en dur, hors de toute boucle — `WC_Meta_Box_Order_Actions::save()`
	 * (« Renvoyer la notification de nouvelle commande ») fait
	 * `WC()->mailer()->emails['WC_Email_New_Order']->trigger(...)`,
	 * `WC_Emails::customer_invoice()` (« Envoyer les détails de la commande »)
	 * fait `$this->emails['WC_Email_Customer_Invoice']`. Une clé renommée
	 * laisse ces accès retomber sur `null` → fatale
	 * « Call to a member function trigger() on null » (bug vécu le 2026-08-11).
	 * `$this->id` (utilisé pour les réglages enregistrés) reste hérité du
	 * constructeur parent, inchangé par ce renommage de clé.
	 */
	foreach ( pf_native_email_overrides() as $key => $subclass ) {
		if ( isset( $email_classes[ $key ] ) ) {
			pf_unhook_email_instance( $email_classes[ $key ] );
		}
		$email_classes[ $key ] = new $subclass();
	}

	/*
	 * Transitions croisées (cf. pf_email_transitions()). Posées ici plutôt que
	 * dans les constructeurs : ce sont les INSTANCES de ce tableau qui doivent
	 * être accrochées, y compris pour les 11 classes natives remplacées ci-dessus.
	 */
	foreach ( pf_email_transitions() as $transition => $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $email_classes[ $key ] ) ) {
				add_action( 'woocommerce_order_status_' . $transition . '_notification', [ $email_classes[ $key ], 'trigger' ], 10, 2 );
			}
		}
	}

	return $email_classes;
}
