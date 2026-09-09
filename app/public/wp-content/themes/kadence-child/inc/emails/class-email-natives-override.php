<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Emails natifs WooCommerce dont on fixe le texte par défaut en code, pour
 * qu'il survive une réinstallation (git-ftp) sans dépendre de la base de
 * données — cf. inc/order-emails.php pour le mécanisme de remplacement et
 * pf_email_shared_text() pour les textes partagés entre plusieurs de ces
 * classes. Défauts d'origine WooCommerce (fr_FR) recopiés comme point de
 * départ ; devenus nos propres défauts à partir de maintenant.
 *
 * Chaque classe ici hérite tout le reste (déclencheurs, destinataires,
 * réglages déjà enregistrés) de sa classe cœur — seuls les textes changent.
 */

class Passiflore_Email_New_Order extends WC_Email_New_Order {
	/**
	 * « (en attente de paiement) » était codé en dur dans le sujet, alors que ce
	 * même email part aussi après un paiement CARTE encaissé
	 * (`pf_notify_admin_precommande_retrait_direct()`, inc/order-statuses.php) :
	 * la boutique lisait « en attente de paiement » sur une commande déjà payée.
	 * Remplacé par le statut réel, via un placeholder maison.
	 */
	public function __construct() {
		parent::__construct();
		$this->placeholders['{order_status}'] = '';
	}
	public function get_default_subject() {
		return __( '[{site_title}] : Nouvelle commande n°{order_number} — {order_status}', 'kadence-child' );
	}
	public function get_default_additional_content() {
		return __( 'Bravo pour cette (future) vente !', 'kadence-child' );
	}
	public function trigger( $order_id, $order = false ) {
		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}
		$this->placeholders['{order_status}'] = is_a( $order, 'WC_Order' )
			? wc_get_order_status_name( $order->get_status() ) // Libellé déjà relibellé par pf_reorder_order_statuses().
			: '';
		parent::trigger( $order_id, $order );
	}
}

/** Destinataire : la BOUTIQUE — d'où un sujet à la 3e personne, et non « Votre commande ». */
class Passiflore_Email_Cancelled_Order extends WC_Email_Cancelled_Order {
	public function get_default_subject() {
		return __( '[{site_title}] : Commande n°{order_number} annulée', 'kadence-child' );
	}
	public function get_default_heading() {
		return pf_email_shared_text( 'cancelled_heading' );
	}
	public function get_default_additional_content() {
		return __( 'Pour toute question concernant votre commande, vous pouvez directement répondre à cet e-mail.', 'kadence-child' );
	}
}

class Passiflore_Email_Customer_Cancelled_Order extends WC_Email_Customer_Cancelled_Order {
	/**
	 * ⚠️ WooCommerce livre cet email DÉSACTIVÉ (`'default' => 'no'` dans
	 * `WC_Email_Customer_Cancelled_Order::init_form_fields()`) : brancher les
	 * transitions d'annulation (pf_email_transitions(), inc/order-emails.php) ne
	 * suffisait pas — le client n'était prévenu d'AUCUNE annulation, pas même
	 * `processing → cancelled` que le cœur écoute pourtant nativement.
	 *
	 * On ne change que le DÉFAUT : une décision contraire prise dans
	 * Réglages → E-mails reste prioritaire, comme pour les textes.
	 */
	public function init_form_fields() {
		parent::init_form_fields();
		if ( isset( $this->form_fields['enabled'] ) ) {
			$this->form_fields['enabled']['default'] = 'yes';
		}
	}
	public function get_default_subject() {
		return __( '[{site_title}] : Votre commande a été annulée', 'kadence-child' );
	}
	public function get_default_heading() {
		return pf_email_shared_text( 'cancelled_heading' );
	}
	public function get_default_additional_content() {
		return __( 'Pour toute question concernant votre commande, vous pouvez directement répondre à cet e-mail.', 'kadence-child' );
	}
}

class Passiflore_Email_Failed_Order extends WC_Email_Failed_Order {
	public function get_default_subject() {
		return __( 'Votre commande Passiflore n’a pas abouti', 'kadence-child' );
	}
	public function get_default_heading() {
		return __( 'Toutes nos excuses, votre commande n°{order_number} a échoué', 'kadence-child' );
	}
	public function get_default_additional_content() {
		return __( 'A priori, le paiement par carte a été refusé ou abandonné. Pour toute question concernant votre commande, vous pouvez directement répondre à cet e-mail.', 'kadence-child' );
	}
}

class Passiflore_Email_Customer_Failed_Order extends WC_Email_Customer_Failed_Order {
	public function get_default_subject() {
		return __( 'Votre commande Passiflore n’a pas abouti', 'kadence-child' );
	}
	public function get_default_heading() {
		return __( 'Toutes nos excuses, votre commande n°{order_number} a échoué', 'kadence-child' );
	}
	public function get_default_additional_content() {
		return __( 'A priori, le paiement par carte a été refusé ou abandonné. Pour toute question concernant votre commande, vous pouvez directement répondre à cet e-mail.', 'kadence-child' );
	}
}

class Passiflore_Email_Customer_On_Hold_Order extends WC_Email_Customer_On_Hold_Order {
	public function get_default_subject() {
		return __( 'Votre commande chez Passiflore (n°{order_number}) a bien été prise en compte', 'kadence-child' );
	}
	public function get_default_heading() {
		return __( 'Merci pour votre commande !', 'kadence-child' );
	}
	public function get_default_additional_content() {
		return __( 'Nous la préparerons dans les plus brefs délais dès réception du règlement.', 'kadence-child' );
	}
}

class Passiflore_Email_Customer_Processing_Order extends WC_Email_Customer_Processing_Order {
	/**
	 * Deux événements très différents mènent ici : l'encaissement d'un règlement
	 * manuel, et la sortie de précommande à la parution du livre
	 * (`pf-precommande → processing`, cf. pf_email_transitions()). « en cours de
	 * préparation » est vrai des deux ; « prise en compte » (le texte partagé
	 * d'avant) faisait doublon avec le mail de création de commande.
	 */
	public function get_default_subject() {
		return __( 'Votre commande Passiflore (n°{order_number}) est en cours de préparation', 'kadence-child' );
	}
	public function get_default_heading() {
		return __( 'Votre commande est en cours de préparation', 'kadence-child' );
	}
	public function get_default_additional_content() {
		return pf_email_shared_text( 'additional_generic' );
	}
}

class Passiflore_Email_Customer_Completed_Order extends WC_Email_Customer_Completed_Order {
	public function get_default_subject() {
		return __( 'Votre commande de livre(s) numérique(s) Passiflore', 'kadence-child' );
	}
	public function get_default_heading() {
		return __( 'Vos livres numériques sont prêts !', 'kadence-child' );
	}
	public function get_default_additional_content() {
		return __( 'Si vous avez passé commande en étant connecté(e), vous le(s) trouverez directement dans votre compte. Pour toute question concernant votre commande, vous pouvez directement répondre à cet e-mail.', 'kadence-child' );
	}
}

class Passiflore_Email_Customer_Refunded_Order extends WC_Email_Customer_Refunded_Order {
	public function get_default_subject( $partial = false ) {
		return $partial
			? __( 'Votre commande Passiflore a été partiellement remboursée', 'kadence-child' )
			: __( 'Votre commande Passiflore a été remboursée', 'kadence-child' );
	}
	public function get_default_heading( $partial = false ) {
		return $partial
			? __( 'Commande partiellement remboursée : {order_number}', 'kadence-child' )
			: __( 'Commande remboursée : {order_number}', 'kadence-child' );
	}
	public function get_default_additional_content() {
		return pf_email_shared_text( 'additional_support' );
	}
}

class Passiflore_Email_Customer_Invoice extends WC_Email_Customer_Invoice {
	public function get_default_subject( $paid = false ) {
		return __( 'Détails de la commande Passiflore n°{order_number}', 'kadence-child' );
	}
	public function get_default_heading( $paid = false ) {
		return __( 'Détails de la commande ci-dessous', 'kadence-child' );
	}
	public function get_default_additional_content() {
		return __( 'Pour toute question concernant votre commande, vous pouvez directement répondre à cet e-mail.', 'kadence-child' );
	}
}

class Passiflore_Email_Customer_Note extends WC_Email_Customer_Note {
	public function get_default_subject() {
		return __( 'Une note a été ajoutée à votre commande Passiflore', 'kadence-child' );
	}
	public function get_default_heading() {
		return __( 'Note ajoutée à votre commande', 'kadence-child' );
	}
	public function get_default_additional_content() {
		return __( 'Pour toute question concernant votre commande, vous pouvez directement répondre à cet e-mail.', 'kadence-child' );
	}
}
