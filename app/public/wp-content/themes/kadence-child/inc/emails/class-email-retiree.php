<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Commande retirée en boutique — terminal pour le retrait en magasin. */
class Passiflore_Email_Retiree extends Passiflore_Email_Order_Status_Base {

	public function __construct() {
		$this->id    = 'pf_retiree';
		$this->title = __( 'Retirée en magasin', 'kadence-child' );
		$this->description = __( 'Envoyé quand un retrait en boutique est confirmé.', 'kadence-child' );

		parent::__construct();
	}

	protected function status_slug(): string {
		return 'pf-retiree';
	}

	public function get_default_subject() {
		return __( 'Confirmation du retrait de votre commande n°{order_number} en boutique', 'kadence-child' );
	}

	public function get_default_heading() {
		return __( 'Merci d’être passé(e)', 'kadence-child' );
	}

	protected function get_default_intro(): string {
		return __( 'Nous vous souhaitons une belle lecture !', 'kadence-child' );
	}
}
