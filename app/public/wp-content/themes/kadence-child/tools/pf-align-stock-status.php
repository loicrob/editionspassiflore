<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║  SCRIPT TEMPORAIRE — À SUPPRIMER QUAND PLUS AUCUN RÉ-IMPORT N'EST PRÉVU  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Rattrape les décalages entre la SCF `disponibilite` (décision éditoriale,
 * source de vérité) et `_stock_status` (WooCommerce, pilote le bouton
 * d'achat) sur les produits déjà en base.
 *
 * `inc/stock-sync.php` empêche tout NOUVEAU décalage à chaque enregistrement
 * (`acf/save_post`/`save_post_product`), mais ne corrige rien rétroactivement.
 * Ce script réutilise directement sa fonction
 * `passiflore_sync_stock_status_from_disponibilite()` plutôt que de dupliquer
 * la correspondance disponibilite → stock_status — elle ne doit vivre qu'à
 * un seul endroit.
 *
 * Utile après un import qui écrit `disponibilite` en base sans déclencher ces
 * hooks (selon la méthode d'écriture de WP All Import) — donc probablement à
 * rejouer après chaque futur ré-import de livres, pas seulement une fois.
 *
 * IDEMPOTENT : un produit déjà aligné est simplement compté, aucune écriture.
 *
 * ── Utilisation ───────────────────────────────────────────────────────────
 *  • En ligne (aucun shell requis) : se connecter en administrateur, puis
 *      ouvrir …/wp-content/themes/kadence-child/tools/pf-align-stock-status.php?go=1
 *  • En local :  php -d mysqli.default_socket=<socket> pf-align-stock-status.php go
 *
 * @package kadence-child
 */

require_once __DIR__ . '/../../../../wp-load.php';

$pf_cli = ( 'cli' === PHP_SAPI );

/*
 * Autorisation. En CLI, l'accès au système de fichiers implique déjà tout ce
 * que ce script peut faire. Sur le web, le fichier est directement joignable
 * (nginx ne bloque les .php que sous /uploads/, pas sous /themes/) et il
 * ÉCRIT des données (stock_status) — d'où la capacité exigée, et le ?go=1
 * pour qu'une visite ou un préchargement de lien ne déclenche rien.
 */
if ( ! $pf_cli && ! current_user_can( 'manage_options' ) ) {
	status_header( 403 );
	exit( 'Interdit.' );
}

$pf_argv = (array) ( $argv ?? [] );
$pf_go   = $pf_cli ? in_array( 'go', $pf_argv, true ) : isset( $_GET['go'] );

if ( ! function_exists( 'passiflore_sync_stock_status_from_disponibilite' ) ) {
	exit( "inc/stock-sync.php n'est pas chargé — thème enfant actif ?\n" );
}

/* ─── Sortie (texte en CLI, HTML minimal sur le web) ─────────────────────── */

function pf_align_out( string $line = '' ): void {
	global $pf_cli;
	echo $pf_cli ? $line . "\n" : esc_html( $line ) . "<br>\n";
	flush();
}

if ( ! $pf_cli ) {
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><meta charset="utf-8"><title>Alignement stock &harr; disponibilité</title>';
	echo '<body style="font:14px/1.6 ui-monospace,Menlo,monospace;max-width:60rem;margin:2rem auto;padding:0 1rem">';
}

if ( ! $pf_go ) {
	pf_align_out( 'Alignement de _stock_status sur la SCF disponibilite (disponibilite fait foi).' );
	pf_align_out( $pf_cli ? "Relancer avec l'argument « go »." : 'Ajouter ?go=1 à l\'URL pour lancer.' );
	exit;
}

/* ─── Inventaire ─────────────────────────────────────────────────────────── */

/** Tous les produits réels (écarte les auto-draft posés par l'écran "Ajouter"). */
function pf_align_product_ids(): array {
	global $wpdb;

	return array_map(
		'intval',
		$wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'product' AND post_status != 'auto-draft'
			 ORDER BY ID ASC"
		)
	);
}

/* ─── Boucle ─────────────────────────────────────────────────────────────── */

$pf_ids   = pf_align_product_ids();
$pf_total = count( $pf_ids );

pf_align_out( sprintf( 'Produits à vérifier : %d', $pf_total ) );
pf_align_out( '' );

$pf_realigned   = [];
$pf_empty_dispo = [];
$pf_already     = 0;

foreach ( $pf_ids as $pf_pid ) {
	$pf_old_status = (string) get_post_meta( $pf_pid, '_stock_status', true );
	$pf_dispo      = (string) get_post_meta( $pf_pid, 'disponibilite', true );

	passiflore_sync_stock_status_from_disponibilite( $pf_pid );

	$pf_new_status = (string) get_post_meta( $pf_pid, '_stock_status', true );

	if ( '' === $pf_dispo ) {
		$pf_empty_dispo[] = $pf_pid;
	}

	if ( $pf_old_status !== $pf_new_status ) {
		$pf_realigned[] = sprintf(
			'#%d %s — disponibilite=%s : %s → %s',
			$pf_pid,
			get_the_title( $pf_pid ),
			'' === $pf_dispo ? '(vide)' : $pf_dispo,
			'' === $pf_old_status ? '(non défini)' : $pf_old_status,
			$pf_new_status
		);
	} else {
		++$pf_already;
	}
}

/* ─── Rapport ────────────────────────────────────────────────────────────── */

pf_align_out( '══════════ RAPPORT ══════════' );
pf_align_out( sprintf( 'Déjà alignés : %d/%d', $pf_already, $pf_total ) );
pf_align_out( sprintf( 'Réalignés par ce script (disponibilite faisait foi) : %d', count( $pf_realigned ) ) );
foreach ( $pf_realigned as $pf_line ) {
	pf_align_out( '    ' . $pf_line );
}

if ( $pf_empty_dispo ) {
	pf_align_out( '' );
	pf_align_out( sprintf( "disponibilite vide (traité comme « disponible » → instock) : %d", count( $pf_empty_dispo ) ) );
	pf_align_out( '    produits : ' . implode( ', ', array_map( static fn( $id ) => '#' . $id, array_slice( $pf_empty_dispo, 0, 20 ) ) ) );
}

pf_align_out( '' );
pf_align_out(
	$pf_realigned
		? 'TERMINÉ — décalages corrigés (liste ci-dessus).'
		: 'TERMINÉ — aucun décalage trouvé, rien à corriger.'
);

if ( ! $pf_cli ) {
	echo '</body>';
}
