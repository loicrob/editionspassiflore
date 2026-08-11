<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Commande livrée — terminal pour le papier expédié. */
class Passiflore_Email_Livree extends Passiflore_Email_Order_Status_Base {

	public function __construct() {
		$this->id    = 'pf_livree';
		$this->title = __( 'Livrée', 'kadence-child' );
		$this->description = __( 'Envoyé quand une commande est marquée comme livrée.', 'kadence-child' );

		parent::__construct();
	}

	protected function status_slug(): string {
		return 'pf-livree';
	}

	public function get_default_subject() {
		return __( 'Votre commande Passiflore (n°{order_number}) est arrivée !', 'kadence-child' );
	}

	public function get_default_heading() {
		return __( 'Votre commande est dans votre boîte aux lettres ou votre point relais', 'kadence-child' );
	}

	protected function get_default_intro(): string {
		return __( 'Nous vous souhaitons une belle lecture !', 'kadence-child' );
	}
}
