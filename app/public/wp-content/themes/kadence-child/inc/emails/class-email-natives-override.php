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
	public function get_default_subject() {
		return __( '[{site_title}] : Nouvelle commande n°{order_number} (en attente de paiement)', 'kadence-child' );
	}
	public function get_default_additional_content() {
		return __( 'Bravo pour cette (future) vente !', 'kadence-child' );
	}
}

class Passiflore_Email_Cancelled_Order extends WC_Email_Cancelled_Order {
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

class Passiflore_Email_Customer_Cancelled_Order extends WC_Email_Customer_Cancelled_Order {
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
		return __( 'Toutes nos excuses, votre commande n°#{order_number} a échoué', 'kadence-child' );
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
		return __( 'Toutes nos excuses, votre commande n°#{order_number} a échoué', 'kadence-child' );
	}
	public function get_default_additional_content() {
		return __( 'A priori, le paiement par carte a été refusé ou abandonné. Pour toute question concernant votre commande, vous pouvez directement répondre à cet e-mail.', 'kadence-child' );
	}
}

class Passiflore_Email_Customer_On_Hold_Order extends WC_Email_Customer_On_Hold_Order {
	public function get_default_subject() {
		return pf_email_shared_text( 'received_subject' );
	}
	public function get_default_heading() {
		return pf_email_shared_text( 'received_heading' );
	}
	public function get_default_additional_content() {
		return __( 'Nous la préparerons dans les plus brefs délais dès réception du règlement.', 'kadence-child' );
	}
}

class Passiflore_Email_Customer_Processing_Order extends WC_Email_Customer_Processing_Order {
	public function get_default_subject() {
		return pf_email_shared_text( 'received_subject' );
	}
	public function get_default_heading() {
		return pf_email_shared_text( 'received_heading' );
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
	public function get_default_subject() {
		return __( 'Détails de la commande Passiflore n°#{order_number}', 'kadence-child' );
	}
	public function get_default_heading() {
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
