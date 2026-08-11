<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Commande confirmée pour un retrait en boutique (paiement → directement ce statut, sans passer par « En cours de préparation »). */
class Passiflore_Email_Retrait_Pret extends Passiflore_Email_Order_Status_Base {

	public function __construct() {
		$this->id    = 'pf_retrait_pret';
		$this->title = __( 'À retirer en boutique', 'kadence-child' );
		$this->description = __( 'Envoyé quand une commande à retirer en boutique est confirmée.', 'kadence-child' );

		parent::__construct();
	}

	protected function status_slug(): string {
		return 'pf-retrait-att';
	}

	public function get_default_subject() {
		return __( 'Votre commande chez Passiflore (n°{order_number}) a bien été prise en compte…', 'kadence-child' );
	}

	public function get_default_heading() {
		return __( '…et nous vous en remercions !', 'kadence-child' );
	}

	protected function get_default_intro(): string {
		return __( 'Vous pouvez passer en boutique quand vous le souhaitez (durant nos horaires d’ouverture) pour la récupérer.', 'kadence-child' );
	}
}
