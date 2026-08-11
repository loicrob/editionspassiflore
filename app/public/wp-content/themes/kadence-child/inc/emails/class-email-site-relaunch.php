<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Annonce de la nouvelle version du site, envoyée une fois à chaque compte
 * migré depuis PrestaShop. Rattachée à un utilisateur (pas une commande),
 * sur le modèle de WC_Email_Customer_New_Account — mêmes réglages admin
 * (Réglages → Emails) que les autres emails du site, mais aucun hook
 * `woocommerce_*` ne la déclenche : seul pf_send_relaunch_emails()
 * (inc/site-relaunch-email.php) l'appelle, en lot, à la demande.
 */
class Passiflore_Email_Site_Relaunch extends WC_Email {

	public function __construct() {
		$this->id             = 'pf_site_relaunch';
		$this->customer_email = true;
		$this->title          = __( 'Nouvelle version du site', 'kadence-child' );
		$this->description    = __( "Annonce envoyée une fois aux comptes migrés depuis l'ancien site — sans déclenchement automatique, cf. pf_send_relaunch_emails().", 'kadence-child' );
		$this->template_html  = 'emails/pf-site-relaunch.php';
		$this->template_plain = 'emails/plain/pf-site-relaunch.php';

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Le nouveau site {site_title} est arrivé — retrouvez votre compte', 'kadence-child' );
	}

	public function get_default_heading() {
		return __( 'Le site fait peau neuve', 'kadence-child' );
	}

	public function get_default_additional_content() {
		return __( 'Des questions ? Écrivez-nous à {store_email}.', 'kadence-child' );
	}

	/** @param int $user_id */
	public function trigger( $user_id ): void {
		$this->setup_locale();

		if ( $user_id ) {
			$this->object    = new WP_User( $user_id );
			$this->recipient = stripslashes( $this->object->user_email );
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			[
				'user'               => $this->object,
				'lost_password_url'  => wc_get_account_endpoint_url( 'lost-password' ),
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => false,
				'email'              => $this,
			]
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			[
				'user'               => $this->object,
				'lost_password_url'  => wc_get_account_endpoint_url( 'lost-password' ),
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
			]
		);
	}
}
