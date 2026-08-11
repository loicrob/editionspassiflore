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
			'received_subject'   => __( 'Votre commande chez Passiflore (n°{order_number}) a bien été prise en compte…', 'kadence-child' ),
			'received_heading'   => __( '…et nous vous en remercions !', 'kadence-child' ),
			'cancelled_heading'  => __( 'Commande annulée : #{order_number}', 'kadence-child' ),
			'additional_generic' => __( 'Nous la préparons dans les plus brefs délais et vous notifierons quand elle sera expédiée. Pour toute question concernant votre commande, vous pouvez directement répondre à cet e-mail.', 'kadence-child' ),
			'additional_support' => __( 'Pour toute question concernant votre commande, vous pouvez directement répondre à cet e-mail.', 'kadence-child' ),
		];
	}
	return $texts[ $key ];
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
	 * intacte. unset() + ré-instanciation avec le MÊME id : les hooks de
	 * déclenchement (posés dans le constructeur hérité) et les réglages déjà
	 * enregistrés continuent de s'appliquer normalement.
	 */
	unset(
		$email_classes['WC_Email_New_Order'],
		$email_classes['WC_Email_Cancelled_Order'],
		$email_classes['WC_Email_Customer_Cancelled_Order'],
		$email_classes['WC_Email_Failed_Order'],
		$email_classes['WC_Email_Customer_Failed_Order'],
		$email_classes['WC_Email_Customer_On_Hold_Order'],
		$email_classes['WC_Email_Customer_Processing_Order'],
		$email_classes['WC_Email_Customer_Completed_Order'],
		$email_classes['WC_Email_Customer_Refunded_Order'],
		$email_classes['WC_Email_Customer_Invoice'],
		$email_classes['WC_Email_Customer_Note']
	);

	$email_classes['Passiflore_Email_New_Order']               = new Passiflore_Email_New_Order();
	$email_classes['Passiflore_Email_Cancelled_Order']         = new Passiflore_Email_Cancelled_Order();
	$email_classes['Passiflore_Email_Customer_Cancelled_Order'] = new Passiflore_Email_Customer_Cancelled_Order();
	$email_classes['Passiflore_Email_Failed_Order']             = new Passiflore_Email_Failed_Order();
	$email_classes['Passiflore_Email_Customer_Failed_Order']    = new Passiflore_Email_Customer_Failed_Order();
	$email_classes['Passiflore_Email_Customer_On_Hold_Order']   = new Passiflore_Email_Customer_On_Hold_Order();
	$email_classes['Passiflore_Email_Customer_Processing_Order'] = new Passiflore_Email_Customer_Processing_Order();
	$email_classes['Passiflore_Email_Customer_Completed_Order']  = new Passiflore_Email_Customer_Completed_Order();
	$email_classes['Passiflore_Email_Customer_Refunded_Order']   = new Passiflore_Email_Customer_Refunded_Order();
	$email_classes['Passiflore_Email_Customer_Invoice']          = new Passiflore_Email_Customer_Invoice();
	$email_classes['Passiflore_Email_Customer_Note']             = new Passiflore_Email_Customer_Note();

	return $email_classes;
}
