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
		return __( 'Votre commande Passiflore (n°{order_number}) vous attend en boutique', 'kadence-child' );
	}

	public function get_default_heading() {
		return __( 'Votre commande vous attend en boutique', 'kadence-child' );
	}

	protected function get_default_intro(): string {
		return __( 'Vous pouvez passer en boutique durant nos horaires d’ouverture (du lundi au vendredi de 9h à 12h30 et de 14h à 17h30) pour la récupérer.', 'kadence-child' );
	}
}
