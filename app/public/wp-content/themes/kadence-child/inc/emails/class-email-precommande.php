<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Commande contenant un article indisponible (livre « à paraître » ou ebook
 * sans fichier). Si la commande contient aussi un article déjà disponible
 * (ex. ebook compagnon), son lien de téléchargement apparaît normalement dans
 * le tableau de commande ci-dessous : `is_download_permitted()` inclut déjà
 * ce statut (inc/order-statuses.php), aucune logique supplémentaire ici.
 */
class Passiflore_Email_Precommande extends Passiflore_Email_Order_Status_Base {

	public function __construct() {
		$this->id    = 'pf_precommande';
		$this->title = __( 'En attente de disponibilité', 'kadence-child' );
		$this->description = __( 'Envoyé quand une commande contient un article pas encore disponible (livre à paraître ou livre numérique sans fichier).', 'kadence-child' );

		parent::__construct();
	}

	protected function status_slug(): string {
		return 'pf-precommande';
	}

	public function get_default_subject() {
		return __( 'Votre précommande chez Passiflore (n°{order_number}) a bien été prise en compte…', 'kadence-child' );
	}

	public function get_default_heading() {
		return __( '…et nous vous en remercions !', 'kadence-child' );
	}

	protected function get_default_intro(): string {
		return __( 'Un ou plusieurs ouvrages ne sont pas encore disponibles (à paraître, bientôt disponible ou livre numérique dont le fichier n’est pas tout à fait prêt) : nous finalisons votre commande dès que tout est réuni, vous recevrez alors un e-mail de confirmation.

Si vous avez passé commande d’un livre numérique pas encore disponible en étant connecté(e), vous le trouverez directement dans votre compte. Si vous n’étiez pas connecté(e), vous recevrez un lien de téléchargement.', 'kadence-child' );
	}
}
