<?php
/**
 * Login / Register form — surcharge Passiflore
 *
 * Le cœur WooCommerce affiche « Se connecter » et « S'inscrire » côte à côte,
 * dans deux colonnes. Ici : un seul panneau à la fois — connexion par défaut
 * (le bouton du header promet « Connexion »), création de compte à un clic.
 * La commande invité étant activée, créer un compte n'est jamais un passage
 * obligé pour acheter : la page sert d'abord aux clients qui reviennent.
 *
 * Points structurants :
 *
 *  - **L'état vit dans l'URL** (`/connexion` vs `/creer-un-compte`, cf.
 *    `inc/account-auth.php`), pas seulement en mémoire. Le formulaire poste vers
 *    l'URL courante et WooCommerce, en cas d'erreur d'inscription (email déjà
 *    pris, mot de passe refusé), se contente d'empiler une notice et de
 *    re-rendre cette même URL. Si l'état n'y figurait pas, le visiteur
 *    retomberait sur le formulaire de connexion avec un message d'erreur
 *    orphelin. Les deux bascules sont donc de **vrais liens** : tout fonctionne
 *    sans JavaScript ; `assets/js/account-auth.js` ne fait qu'éviter le
 *    rechargement (et réécrit l'URL, pour que le POST qui suit parte au bon
 *    endroit).
 *
 *  - **Libellés en placeholder**, mais `<label>` conservés en
 *    `.screen-reader-text` : un placeholder n'est pas un libellé accessible
 *    (il disparaît dès la saisie, et tous les lecteurs d'écran ne l'annoncent
 *    pas). Le champ garde donc son étiquette programmatique.
 *
 *  - **Titres en `<h1>`** : la page compte déconnectée n'en avait aucun. Le
 *    panneau masqué l'est en `display:none` (account.css) → hors arbre
 *    d'accessibilité et hors ordre de tabulation, un seul `<h1>` exposé.
 *
 *  - **Claviers mobiles** : `inputmode`/`autocapitalize`/`autocorrect` plutôt
 *    que `type="email"` sur l'identifiant de connexion — voir le commentaire
 *    sur `#username`.
 *
 * Tous les hooks et champs nonce du template d'origine sont préservés.
 *
 * @see plugins/woocommerce/templates/myaccount/form-login.php
 * @package WooCommerce\Templates
 * @version 9.9.0
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_customer_login_form' );

$pf_registration = 'yes' === get_option( 'woocommerce_enable_myaccount_registration' );
$pf_login_url    = pf_auth_url( 'login' );    // /connexion
$pf_register_url = pf_auth_url( 'register' ); // /creer-un-compte

// Panneau ouvert au chargement : lu dans l'URL (cf. pf_auth_current_state()).
$pf_show_register = $pf_registration && 'register' === pf_auth_current_state();
?>

<div id="customer_login" class="pf-auth<?php echo $pf_show_register ? ' is-register' : ''; ?><?php echo $pf_registration ? '' : ' pf-auth--login-only'; ?>">

	<section class="pf-auth__panel pf-auth__panel--login">

		<h1 class="pf-titre-1 pf-auth__title">Se connecter</h1>

		<form class="woocommerce-form woocommerce-form-login login pf-panel pf-auth__form" method="post" novalidate>

			<?php do_action( 'woocommerce_login_form_start' ); ?>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label class="screen-reader-text" for="username">Adresse e-mail ou identifiant <span class="screen-reader-text">obligatoire</span></label>
				<?php
				/*
				 * Clavier e-mail sur mobile SANS type="email" : ce champ accepte
				 * aussi l'identifiant WordPress (généré automatiquement à partir
				 * de l'email, réglage `woocommerce_registration_generate_username`),
				 * que la validation HTML5 de type="email" rejetterait. inputmode
				 * donne le bon clavier, autocapitalize/autocorrect/spellcheck
				 * évitent la majuscule et la correction automatiques d'iOS.
				 */
				?>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="username"
					placeholder="Adresse e-mail *"
					autocomplete="username" inputmode="email" autocapitalize="none" autocorrect="off" spellcheck="false"
					value="<?php echo ( ! empty( $_POST['username'] ) && is_string( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>" required aria-required="true" /><?php // @codingStandardsIgnoreLine ?>
			</p>
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label class="screen-reader-text" for="password">Mot de passe <span class="screen-reader-text">obligatoire</span></label>
				<input class="woocommerce-Input woocommerce-Input--text input-text" type="password" name="password" id="password"
					placeholder="Mot de passe *"
					autocomplete="current-password" autocapitalize="none" spellcheck="false" enterkeyhint="go"
					required aria-required="true" />
			</p>

			<?php do_action( 'woocommerce_login_form' ); ?>

			<p class="form-row pf-auth__actions">
				<label class="woocommerce-form__label woocommerce-form__label-for-checkbox woocommerce-form-login__rememberme">
					<input class="woocommerce-form__input woocommerce-form__input-checkbox" name="rememberme" type="checkbox" id="rememberme" value="forever" /> <span>Rester connecté(e)</span>
				</label>
				<?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>
				<button type="submit" class="woocommerce-button button woocommerce-form-login__submit<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="login" value="Se connecter">Se connecter</button>
			</p>
			<p class="woocommerce-LostPassword lost_password">
				<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">Mot de passe oublié ?</a>
			</p>

			<?php do_action( 'woocommerce_login_form_end' ); ?>

		</form>

		<?php if ( $pf_registration ) : ?>
			<p class="pf-auth__switch">
				Pas encore de compte ?
				<a class="pf-auth__switch-link" href="<?php echo esc_url( $pf_register_url ); ?>" data-pf-auth-target="register">Créer un compte</a>
			</p>
		<?php endif; ?>

	</section>

	<?php if ( $pf_registration ) : ?>

	<section class="pf-auth__panel pf-auth__panel--register">

		<h1 class="pf-titre-1 pf-auth__title">Créer un compte</h1>

		<form method="post" class="woocommerce-form woocommerce-form-register register pf-panel pf-auth__form" <?php do_action( 'woocommerce_register_form_tag' ); ?> >

			<?php do_action( 'woocommerce_register_form_start' ); ?>

			<?php if ( 'no' === get_option( 'woocommerce_registration_generate_username' ) ) : ?>

				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
					<label class="screen-reader-text" for="reg_username">Identifiant <span class="screen-reader-text">obligatoire</span></label>
					<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="reg_username"
						placeholder="Identifiant *"
						autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"
						value="<?php echo ( ! empty( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>" required aria-required="true" /><?php // @codingStandardsIgnoreLine ?>
				</p>

			<?php endif; ?>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label class="screen-reader-text" for="reg_email">Adresse e-mail <span class="screen-reader-text">obligatoire</span></label>
				<input type="email" class="woocommerce-Input woocommerce-Input--text input-text" name="email" id="reg_email"
					placeholder="Adresse e-mail *"
					autocomplete="email" inputmode="email" autocapitalize="none" autocorrect="off" spellcheck="false"
					value="<?php echo ( ! empty( $_POST['email'] ) ) ? esc_attr( wp_unslash( $_POST['email'] ) ) : ''; ?>" required aria-required="true" /><?php // @codingStandardsIgnoreLine ?>
			</p>

			<?php if ( 'no' === get_option( 'woocommerce_registration_generate_password' ) ) : ?>

				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
					<label class="screen-reader-text" for="reg_password">Mot de passe <span class="screen-reader-text">obligatoire</span></label>
					<input class="woocommerce-Input woocommerce-Input--text input-text" type="password" name="password" id="reg_password"
						placeholder="Mot de passe *"
						autocomplete="new-password" autocapitalize="none" spellcheck="false" enterkeyhint="go"
						required aria-required="true" />
				</p>

			<?php else : ?>

				<p class="pf-auth__hint">Un lien de création de mot de passe vous sera envoyé par e-mail.</p>

			<?php endif; ?>

			<?php do_action( 'woocommerce_register_form' ); ?>

			<p class="woocommerce-form-row form-row pf-auth__actions">
				<?php wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce' ); ?>
				<button type="submit" class="woocommerce-Button woocommerce-button button<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?> woocommerce-form-register__submit" name="register" value="Créer mon compte">Créer mon compte</button>
			</p>

			<?php do_action( 'woocommerce_register_form_end' ); ?>

		</form>

		<p class="pf-auth__switch">
			Vous avez déjà un compte ?
			<a class="pf-auth__switch-link" href="<?php echo esc_url( $pf_login_url ); ?>" data-pf-auth-target="login">Se connecter</a>
		</p>

	</section>

	<?php endif; ?>

</div>

<?php do_action( 'woocommerce_after_customer_login_form' ); ?>
