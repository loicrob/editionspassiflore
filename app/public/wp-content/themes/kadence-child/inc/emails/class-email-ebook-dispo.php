<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * « Votre livre numérique est disponible » — le pivot de ce chantier : ferme
 * le cas d'un ebook commandé (souvent en invité) avant que son fichier
 * n'existe. Déclenché directement par
 * pf_epub_sync_permissions_for_product() (inc/epub-storage.php) quand un
 * dépôt de fichier accorde RÉTROACTIVEMENT des droits déjà dus — PAS par une
 * transition de statut, donc pas de branchement sur `woocommerce_email_actions`
 * ici (contrairement à Passiflore_Email_Order_Status_Base).
 *
 * Fonctionne pour un·e client·e SANS COMPTE : `get_downloadable_items()` de
 * WC_Order interroge la table `wc_customer_download` par email de
 * facturation + ID de commande — jamais par `user_id` — et le lien qu'elle
 * construit (`add_query_arg` avec `order`/`uid`/`key`) est valable sans
 * connexion tant que `woocommerce_downloads_require_login = no` (réglage
 * actif sur ce site).
 */
class Passiflore_Email_Ebook_Dispo extends WC_Email {

	/** Sous-ensemble de get_downloadable_items() : seulement les produits nouvellement accordés. */
	protected $downloads = [];

	public function __construct() {
		$this->id             = 'pf_ebook_dispo';
		$this->title          = __( 'Livre numérique disponible', 'kadence-child' );
		$this->description    = __( 'Envoyé quand le fichier d’un livre numérique commandé en attente de disponibilité est enfin déposé — y compris pour une commande passée sans compte.', 'kadence-child' );
		$this->customer_email = true;
		$this->email_group    = 'order-updates';
		$this->template_html  = 'emails/pf-ebook-dispo.php';
		$this->template_plain = 'emails/plain/pf-ebook-dispo.php';
		$this->placeholders   = [
			'{order_date}'   => '',
			'{order_number}' => '',
		];

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Livre(s) numérique(s) Passiflore', 'kadence-child' );
	}

	public function get_default_heading() {
		return __( 'Le(s) livre(s) numériques, c’est par ici !', 'kadence-child' );
	}

	public function get_default_additional_content() {
		return __( 'Pour toute question concernant votre commande, vous pouvez directement répondre à cet e-mail.', 'kadence-child' );
	}

	/** Phrase introduisant la liste des fichiers — modifiable depuis Réglages → Emails. */
	protected function get_default_intro(): string {
		return __( 'Vous pouvez le(s) télécharger via ce lien :', 'kadence-child' );
	}

	/** Même champ « intro » que Passiflore_Email_Order_Status_Base — cf. ce fichier pour le détail du mécanisme. */
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
					'description' => __( 'Phrase affichée juste avant la liste des liens de téléchargement.', 'kadence-child' ),
					'placeholder' => $this->get_default_intro(),
					'default'     => '',
				];
			}
		}
		$this->form_fields = $fields;
	}

	protected function get_intro(): string {
		return $this->format_string( $this->get_option_or_transient( 'intro', $this->get_default_intro() ) );
	}

	/**
	 * @param WC_Order $order       Commande ayant droit aux fichiers (invitée ou non).
	 * @param int[]    $product_ids IDs produits dont le droit vient d'être accordé — pas
	 *                              tout le catalogue téléchargeable de la commande, une
	 *                              commande mixte pouvant avoir d'autres ebooks déjà notifiés.
	 */
	public function trigger_for_products( WC_Order $order, array $product_ids ): void {
		if ( ! $product_ids ) {
			return;
		}

		$this->setup_locale();

		$this->object                         = $order;
		$this->recipient                      = $order->get_billing_email();
		$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		$this->placeholders['{order_number}'] = $order->get_order_number();

		$this->downloads = array_values( array_filter(
			$order->get_downloadable_items(),
			static fn( $download ) => in_array( (int) ( $download['product_id'] ?? 0 ), $product_ids, true )
		) );

		if ( $this->downloads && $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			[
				'order'              => $this->object,
				'downloads'          => $this->downloads,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => false,
				'email'              => $this,
				'intro'              => $this->get_intro(),
			]
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			[
				'order'              => $this->object,
				'downloads'          => $this->downloads,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
				'intro'              => $this->get_intro(),
			]
		);
	}
}
