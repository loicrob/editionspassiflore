<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Email d'annonce de la nouvelle version — cf.
 * inc/emails/class-email-site-relaunch.php pour la classe. Enregistrement +
 * envoi en lot, à la demande (pas de déclenchement automatique) :
 * `wp eval 'pf_send_relaunch_emails( false );'` une fois la migration des
 * comptes PrestaShop → v2 terminée.
 *
 * Cible par défaut : les comptes portant la méta `_pf_migrated_from_prestashop`
 * (posée par le futur script de migration), jamais `role => customer` seul —
 * cela enverrait aussi aux comptes de test créés directement sur v2.
 */

add_filter( 'woocommerce_email_classes', 'pf_register_site_relaunch_email' );
function pf_register_site_relaunch_email( array $email_classes ): array {
	require_once __DIR__ . '/emails/class-email-site-relaunch.php';
	$email_classes['Passiflore_Email_Site_Relaunch'] = new Passiflore_Email_Site_Relaunch();
	return $email_classes;
}

/**
 * @param bool  $dry_run       Si true (défaut), n'envoie rien : retourne juste le nombre de destinataires trouvés.
 * @param array $user_ids      IDs à cibler explicitement. Vide = tous les comptes migrés (méta _pf_migrated_from_prestashop).
 * @param int   $batch_size    Nombre d'emails entre deux pauses.
 * @param int   $pause_seconds Pause (secondes) entre deux lots, pour ne pas saturer l'envoi du serveur.
 * @return int Nombre d'emails envoyés (ou qui auraient été envoyés en dry-run).
 */
function pf_send_relaunch_emails( bool $dry_run = true, array $user_ids = [], int $batch_size = 20, int $pause_seconds = 5 ): int {
	$mailer = WC()->mailer()->emails;
	if ( ! isset( $mailer['Passiflore_Email_Site_Relaunch'] ) ) {
		return 0;
	}
	$email = $mailer['Passiflore_Email_Site_Relaunch'];

	if ( ! $user_ids ) {
		$user_ids = get_users( [
			'meta_key'   => '_pf_migrated_from_prestashop',
			'meta_value' => '1',
			'fields'     => 'ID',
		] );
	}

	$sent = 0;
	foreach ( $user_ids as $i => $user_id ) {
		if ( ! $dry_run ) {
			$email->trigger( (int) $user_id );
		}
		$sent++;

		if ( ! $dry_run && $batch_size > 0 && 0 === ( $i + 1 ) % $batch_size ) {
			sleep( $pause_seconds );
		}
	}

	return $sent;
}
