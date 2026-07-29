<?php
/**
 * Mes adresses — surcharge Passiflore
 *
 * Écarts avec le cœur WooCommerce, tous markup-level (donc pas faisables par
 * hooks) :
 *
 *  - **Phrase d'introduction retirée** (« Les adresses suivantes seront
 *    utilisées par défaut sur la page de commande. ») : les deux cartes
 *    portent déjà leur titre, la phrase n'ajoute rien.
 *
 *  - **« Vous n'avez pas encore défini ce type d'adresse. » retirée** : le
 *    bouton « Renseigner l'adresse » dit déjà que l'adresse est vide, et le
 *    dit en proposant l'action.
 *
 *  - **Le lien du cœur sort de `<header>` et passe SOUS l'`<address>`** — sa
 *    seule position possible ici : la carte se lit titre → adresse → action.
 *    C'est aussi ce qui le libère du `float: right` que Kadence pose sur
 *    `.title .edit`, donc du calage à contre-courant qu'il fallait sinon.
 *
 *  - **Lien → bouton primaire centré**, libellé unique par état :
 *    « Renseigner l'adresse » (vide) / « Modifier l'adresse » (renseignée),
 *    sans le type d'adresse que le cœur y répète (« Ajouter Adresse de
 *    facturation ») — le `<h2>` de la carte le porte déjà. Le libellé étant
 *    du coup identique d'une carte à l'autre, le type est rappelé en
 *    `.screen-reader-text` : sans lui, une navigation par liens n'entendrait
 *    que deux fois la même chose pour deux destinations différentes.
 *    ⚠️ L'espace séparateur vit DANS le span (hors de lui, il appartiendrait
 *    au flux visible et décalerait le libellé du bouton).
 *
 *  - Un seul `<a>` pour les deux états (même classe, même place) : seul le
 *    libellé change. Le cœur en écrivait deux, hérités du `float`.
 *
 * Le hook `woocommerce_my_account_after_my_address` et le filtre
 * `woocommerce_my_account_get_addresses` sont préservés.
 *
 * @see plugins/woocommerce/templates/myaccount/my-address.php
 * @package WooCommerce\Templates
 * @version 9.3.0
 */

defined( 'ABSPATH' ) || exit;

$customer_id = get_current_user_id();

if ( ! wc_ship_to_billing_address_only() && wc_shipping_enabled() ) {
	$get_addresses = apply_filters(
		'woocommerce_my_account_get_addresses',
		array(
			'billing'  => __( 'Billing address', 'woocommerce' ),
			'shipping' => __( 'Shipping address', 'woocommerce' ),
		),
		$customer_id
	);
} else {
	$get_addresses = apply_filters(
		'woocommerce_my_account_get_addresses',
		array(
			'billing' => __( 'Billing address', 'woocommerce' ),
		),
		$customer_id
	);
}

$oldcol = 1;
$col    = 1;
?>

<?php if ( ! wc_ship_to_billing_address_only() && wc_shipping_enabled() ) : ?>
	<div class="u-columns woocommerce-Addresses col2-set addresses">
<?php endif; ?>

<?php foreach ( $get_addresses as $name => $address_title ) : ?>
	<?php
		$address = wc_get_account_formatted_address( $name );
		$col     = $col * -1;
		$oldcol  = $oldcol * -1;
	?>

	<div class="u-column<?php echo $col < 0 ? 1 : 2; ?> col-<?php echo $oldcol < 0 ? 1 : 2; ?> woocommerce-Address">
		<header class="woocommerce-Address-title title">
			<h2><?php echo esc_html( $address_title ); ?></h2>
		</header>
		<?php // Pas d'espace entre les balises et le PHP : sans adresse ni greffon, l'élément doit être VRAIMENT vide (cf. `address:empty`, account.css). ?>
		<address><?php
			echo $address ? wp_kses_post( $address ) : '';

			/**
			 * Used to output content after core address fields.
			 *
			 * @param string $name Address type.
			 * @since 8.7.0
			 */
			do_action( 'woocommerce_my_account_after_my_address', $name );
		?></address>
		<a href="<?php echo esc_url( wc_get_endpoint_url( 'edit-address', $name ) ); ?>" class="edit button pf-btn pf-btn--primary pf-address-btn"><?php echo $address ? 'Modifier l’adresse' : 'Renseigner l’adresse'; ?><span class="screen-reader-text"> — <?php echo esc_html( $address_title ); ?></span></a>
	</div>

<?php endforeach; ?>

<?php if ( ! wc_ship_to_billing_address_only() && wc_shipping_enabled() ) : ?>
	</div>
	<?php
endif;
