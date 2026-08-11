<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Base commune aux 5 emails de statut sur mesure (precommande, retrait-att,
 * expediee, livree, retiree) — structurellement identiques à
 * WC_Email_Customer_Processing_Order (même trigger(), même
 * get_content_html/plain()), seuls varient l'id, les libellés et le
 * paragraphe d'introduction. Chaque sous-classe ne fournit que ces
 * différences ; toute la plomberie WC_Email vit ici, une seule fois.
 *
 * Gabarit PARTAGÉ (woocommerce/emails/pf-order-status.php) plutôt qu'un par
 * statut : le corps est structurellement identique (intro + tableau de
 * commande + coordonnées client), seule l'intro change — 5 fichiers
 * quasi-identiques n'auraient rien apporté de plus que ce paramètre.
 *
 * L'action `woocommerce_order_status_<slug>` doit déjà figurer dans
 * `woocommerce_email_actions` (posé par inc/order-statuses.php) — sans ça,
 * `<action>_notification` ci-dessous ne serait jamais émise et cette classe
 * resterait inerte.
 */
abstract class Passiflore_Email_Order_Status_Base extends WC_Email {

	/** Statut sur mesure déclenchant cet email — slug NU, sans `wc-`. */
	abstract protected function status_slug(): string;

	/** Paragraphe d'introduction par défaut, au-dessus du tableau de commande — modifiable depuis Réglages → Emails. */
	abstract protected function get_default_intro(): string;

	public function __construct() {
		$this->customer_email = true;
		$this->email_group    = 'order-updates';
		$this->template_html  = 'emails/pf-order-status.php';
		$this->template_plain = 'emails/plain/pf-order-status.php';
		$this->placeholders   = [
			'{order_date}'   => '',
			'{order_number}' => '',
		];

		add_action( 'woocommerce_order_status_' . $this->status_slug() . '_notification', [ $this, 'trigger' ], 10, 2 );

		parent::__construct();
	}

	public function get_default_heading() {
		return __( 'Merci pour votre commande', 'kadence-child' );
	}

	public function get_default_additional_content() {
		return __( 'Pour toute question concernant votre commande, vous pouvez directement répondre à cet e-mail.', 'kadence-child' );
	}

	/**
	 * Insère un champ « Introduction » entre le titre et le contenu additionnel
	 * — mêmes conventions que les champs natifs (`subject`/`heading` :
	 * `get_option_or_transient()` avec repli sur le défaut de la sous-classe,
	 * placeholder = ce défaut affiché en filigrane tant que rien n'est saisi).
	 */
	public function init_form_fields() {
		parent::init_form_fields();

		$fields = [];
		foreach ( $this->form_fields as $key => $field ) {
			$fields[ $key ] = $field;
			if ( 'heading' === $key ) {
				$fields['intro'] = [
					'title'       => __( 'Introduction', 'kadence-child' ),
					'type'        => 'textarea',
					'css'         => 'width:400px; height: 100px;',
					'desc_tip'    => true,
					'description' => __( 'Paragraphe affiché juste après « Bonjour » — au-dessus du récapitulatif de commande.', 'kadence-child' ),
					'placeholder' => $this->get_default_intro(),
					'default'     => '',
				];
			}
		}
		$this->form_fields = $fields;
	}

	/** Paragraphe d'introduction (HTML) — réglage admin ou défaut de la sous-classe. */
	protected function get_intro_html(): string {
		return wpautop( wptexturize( $this->format_string( $this->get_option_or_transient( 'intro', $this->get_default_intro() ) ) ) );
	}

	/** Même paragraphe, texte brut. */
	protected function get_intro_plain(): string {
		return wp_strip_all_tags( wptexturize( $this->format_string( $this->get_option_or_transient( 'intro', $this->get_default_intro() ) ) ) );
	}

	/** Identique à WC_Email_Customer_Processing_Order::trigger() (cœur). */
	public function trigger( $order_id, $order = false ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( is_a( $order, 'WC_Order' ) ) {
			$this->object                         = $order;
			$this->recipient                      = $this->object->get_billing_email();
			$this->placeholders['{order_date}']   = wc_format_datetime( $this->object->get_date_created() );
			$this->placeholders['{order_number}'] = $this->object->get_order_number();
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
				'order'              => $this->object,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => false,
				'email'              => $this,
				'intro'              => $this->get_intro_html(),
			]
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			[
				'order'              => $this->object,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
				'intro'              => $this->get_intro_plain(),
			]
		);
	}
}
