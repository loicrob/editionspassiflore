<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Commande expédiée (manuel/action groupée, ou Boxtal Connect si relié à un compte). */
class Passiflore_Email_Expediee extends Passiflore_Email_Order_Status_Base {

	public function __construct() {
		$this->id    = 'pf_expediee';
		$this->title = __( 'Expédiée', 'kadence-child' );
		$this->description = __( 'Envoyé quand une commande est expédiée.', 'kadence-child' );

		parent::__construct();
	}

	protected function status_slug(): string {
		return 'pf-expediee';
	}

	public function get_default_subject() {
		return __( 'Votre commande Passiflore (n°{order_number}) vient d’être expédiée !', 'kadence-child' );
	}

	public function get_default_heading() {
		return __( 'Votre commande est en chemin…', 'kadence-child' );
	}

	protected function get_default_intro(): string {
		return __( '…et arrivera dans votre boîte aux lettres ou en point relais dans les plus brefs délais.', 'kadence-child' );
	}
}
