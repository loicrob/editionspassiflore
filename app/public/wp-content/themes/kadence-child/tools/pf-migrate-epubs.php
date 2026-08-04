<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║  SCRIPT TEMPORAIRE — À SUPPRIMER APRÈS PASSAGE SUR CHAQUE ENVIRONNEMENT  ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Met les fichiers ePub existants à l'abri.
 *
 * Contexte (mesuré le 2026-07-30) : les 64 ePub du catalogue avaient été
 * importés DANS LA MÉDIATHÈQUE, donc rangés en accès libre dans
 * `uploads/2026/05/` sous leur ISBN nu. `GET /wp-json/wp/v2/media?media_type=application`
 * en listait les 64 avec leur URL, et chaque URL répondait 200 avec le fichier
 * complet : tout le catalogue numérique payant était téléchargeable
 * gratuitement, en deux requêtes.
 *
 * Ce script, pour chaque produit téléchargeable :
 *   1. retrouve le fichier par son ATTACHMENT (seule source indépendante de l'hôte) ;
 *   2. le copie dans le répertoire protégé sous un nom à entropie, puis VÉRIFIE
 *      la copie (taille + md5) ;
 *   3. réécrit `_downloadable_files` en chemin racine-relatif, portable ;
 *   4. supprime l'attachment — c'est ce qui ferme la fuite REST, `wp/v2/media`
 *      ne pouvant lister que des posts ;
 *   5. supprime l'ancienne copie.
 *
 * IDEMPOTENT et REPRENABLE : un produit déjà traité est reconnu à l'étape 2 et
 * sauté. Aucun état n'est persisté, donc rien à nettoyer à la suppression.
 *
 * ── Utilisation ───────────────────────────────────────────────────────────────
 *  • En ligne (aucun shell requis) : se connecter en administrateur, puis ouvrir
 *      …/wp-content/themes/kadence-child/tools/pf-migrate-epubs.php?go=1
 *    Les lots de 10 s'enchaînent d'eux-mêmes.
 *  • En local :  php -d mysqli.default_socket=<socket> pf-migrate-epubs.php go
 *
 * ⚠️ SAUVEGARDER AVANT : ce script supprime des fichiers et des attachments.
 *
 * @package kadence-child
 */

require_once __DIR__ . '/../../../../wp-load.php';

const PF_MIGRATE_BATCH = 10;

$pf_cli = ( 'cli' === PHP_SAPI );

/*
 * Autorisation. En CLI, l'accès au système de fichiers implique déjà tout ce que
 * ce script peut faire : pas de privilège à escalader. Sur le web en revanche,
 * le fichier est directement joignable (nginx ne bloque les .php que sous
 * /uploads/, pas sous /themes/) et il DÉTRUIT des données — d'où la capacité
 * exigée, et le ?go=1 pour qu'une visite ou un préchargement de lien ne
 * déclenche rien.
 */
if ( ! $pf_cli && ! current_user_can( 'manage_options' ) ) {
	status_header( 403 );
	exit( 'Interdit.' );
}

$pf_argv = (array) ( $argv ?? [] );
$pf_go   = $pf_cli ? in_array( 'go', $pf_argv, true ) : isset( $_GET['go'] );
$pf_from = $pf_cli ? 0 : max( 0, (int) ( $_GET['from'] ?? 0 ) );

// En CLI, tout en un passage — sauf `limit=N`, utile pour un premier essai prudent
// sur un seul produit avant de lâcher les 64.
$pf_size = PF_MIGRATE_BATCH;
if ( $pf_cli ) {
	$pf_size = PHP_INT_MAX;
	foreach ( $pf_argv as $pf_arg ) {
		if ( preg_match( '/^limit=(\d+)$/', (string) $pf_arg, $pf_m ) ) {
			$pf_size = max( 1, (int) $pf_m[1] );
		}
	}
}

if ( ! function_exists( 'pf_epub_dir' ) ) {
	exit( "inc/epub-storage.php n'est pas chargé — thème enfant actif ?\n" );
}

/* ─── Sortie (texte en CLI, HTML minimal sur le web) ─────────────────────── */

function pf_mig_out( string $line = '' ): void {
	global $pf_cli;
	echo $pf_cli ? $line . "\n" : esc_html( $line ) . "<br>\n";
	flush();
}

if ( ! $pf_cli ) {
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><meta charset="utf-8"><title>Migration ePub</title>';
	echo '<body style="font:14px/1.6 ui-monospace,Menlo,monospace;max-width:60rem;margin:2rem auto;padding:0 1rem">';
}

if ( ! $pf_go ) {
	pf_mig_out( 'Migration des ePub vers le répertoire protégé.' );
	pf_mig_out( $pf_cli ? 'Relancer avec l\'argument « go ».' : 'Ajouter ?go=1 à l\'URL pour lancer.' );
	exit;
}

/* ─── Inventaire ─────────────────────────────────────────────────────────── */

/** Tous les produits téléchargeables, ordre stable (indispensable pour la pagination). */
function pf_mig_product_ids(): array {
	global $wpdb;

	return array_map(
		'intval',
		$wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_downloadable'
			 WHERE p.post_type = 'product' AND m.meta_value = 'yes'
			 ORDER BY p.ID ASC"
		)
	);
}

/** Attachment ePub rattaché à un produit, ou 0. */
function pf_mig_attachment_id( int $product_id ): int {
	$children = get_children(
		[
			'post_parent'    => $product_id,
			'post_type'      => 'attachment',
			'post_mime_type' => 'application/epub+zip',
			'numberposts'    => 1,
			'fields'         => 'ids',
		]
	);

	return $children ? (int) reset( $children ) : 0;
}

/**
 * Chemin disque du fichier source.
 *
 * L'attachment d'abord : `get_attached_file()` se reconstruit depuis
 * `_wp_attached_file` + le basedir courant, donc il est juste sur n'importe quel
 * hôte — contrairement à la valeur stockée, qui est une URL absolue codée sur
 * l'hôte d'origine. Repli sur cette valeur si l'attachment a disparu.
 */
function pf_mig_source_path( int $product_id, string $stored ) {
	$att_id = pf_mig_attachment_id( $product_id );
	if ( $att_id ) {
		$path = get_attached_file( $att_id );
		if ( $path && file_exists( $path ) ) {
			return wp_normalize_path( $path );
		}
	}

	$path = wp_parse_url( $stored, PHP_URL_PATH );
	if ( $path && false !== strpos( $path, '/uploads/' ) ) {
		$tail = substr( $path, strpos( $path, '/uploads/' ) + strlen( '/uploads/' ) );
		$abs  = wp_normalize_path( trailingslashit( wp_get_upload_dir()['basedir'] ) . $tail );
		if ( file_exists( $abs ) ) {
			return $abs;
		}
	}

	return false;
}

/* ─── Traitement d'un produit ────────────────────────────────────────────── */

function pf_mig_process( int $product_id ): string {
	$files = get_post_meta( $product_id, '_downloadable_files', true );
	if ( ! is_array( $files ) || ! $files ) {
		return 'ignoré (aucun fichier déclaré)';
	}

	$changed = false;
	$notes   = [];

	foreach ( $files as $key => $file ) {
		$stored = isset( $file['file'] ) ? (string) $file['file'] : '';
		if ( '' === $stored ) {
			$notes[] = 'entrée vide';
			continue;
		}

		if ( 'epub' !== strtolower( pathinfo( wp_parse_url( $stored, PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ) ) ) {
			$notes[] = 'non-ePub laissé tel quel';
			continue;
		}

		// ── Étape 2 : déjà à l'abri ? (c'est ce test, et non un marqueur en base,
		// qui rend le script reprenable et rejouable sans effet.)
		if ( pf_epub_is_protected_path( $stored ) ) {
			$portable = pf_epub_stored_path( basename( wp_parse_url( $stored, PHP_URL_PATH ) ) );
			if ( $stored === $portable ) {
				$notes[] = 'déjà protégé';
				continue;
			}
			// Bon répertoire mais forme non portable → simple réécriture.
			$files[ $key ]['file'] = $portable;
			$changed               = true;
			$notes[]               = 'chemin normalisé';
			continue;
		}

		// ── Étape 1 : source
		$src = pf_mig_source_path( $product_id, $stored );
		if ( ! $src ) {
			$notes[] = 'SOURCE INTROUVABLE — laissé intact';
			continue;
		}

		// ── Étape 4 : répertoire
		if ( ! pf_epub_ensure_dir() ) {
			$notes[] = 'ÉCHEC création du répertoire protégé';
			continue;
		}

		// ── Étape 5 : copier puis VÉRIFIER (on s'apprête à supprimer l'unique autre copie)
		$dst = pf_epub_dir() . '/' . pf_epub_unique_basename( basename( $src ) );
		if ( ! copy( $src, $dst ) ) {
			$notes[] = 'ÉCHEC de la copie';
			continue;
		}
		if ( filesize( $src ) !== filesize( $dst ) || md5_file( $src ) !== md5_file( $dst ) ) {
			wp_delete_file( $dst );
			$notes[] = 'COPIE NON CONFORME (taille ou md5) — annulée';
			continue;
		}

		// ── Étape 6 : réécrire la meta. Jamais via set_downloads() : sur ce site
		// les répertoires approuvés sont actifs, et un download jugé invalide y
		// serait set_enabled(false) EN SILENCE.
		$files[ $key ]['file'] = pf_epub_stored_path( basename( $dst ) );
		$changed               = true;

		// ── Étape 7 : supprimer l'attachment SANS délier de fichier. Retirer
		// d'abord _wp_attached_file rend le unlink de wp_delete_attachment inopérant
		// — sinon, sur une reprise où le fichier est déjà à destination, il
		// détruirait l'ebook.
		$att_id = pf_mig_attachment_id( $product_id );
		if ( $att_id ) {
			delete_post_meta( $att_id, '_wp_attached_file' );
			wp_delete_attachment( $att_id, true );
		}

		// ── Étape 8 : supprimer l'ancienne copie, celle qui fuyait
		if ( $src !== $dst ) {
			wp_delete_file( $src );
			if ( file_exists( $src ) ) {
				$notes[] = 'ATTENTION : ancienne copie toujours présente (' . $src . ')';
			}
		}

		$notes[] = 'protégé → ' . basename( $dst );
	}

	if ( $changed ) {
		update_post_meta( $product_id, '_downloadable_files', $files );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
		clean_post_cache( $product_id );
	}

	return implode( ' ; ', $notes );
}

/* ─── Rapport de fin ─────────────────────────────────────────────────────── */

function pf_mig_report(): void {
	global $wpdb;

	pf_mig_out( '' );
	pf_mig_out( '══════════ RAPPORT ══════════' );

	$attachments = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts}
		 WHERE post_type = 'attachment' AND post_mime_type = 'application/epub+zip'"
	);
	pf_mig_out( sprintf( 'Attachments ePub restants : %d   (cible 0)%s', $attachments, 0 === $attachments ? '  ✓' : '  ✗' ) );

	// ePub égarés : n'importe où sous uploads/, sauf dans le répertoire protégé.
	$base    = wp_normalize_path( untrailingslashit( wp_get_upload_dir()['basedir'] ) );
	$guarded = pf_epub_dir();
	$stray   = [];
	$it      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$p = wp_normalize_path( $f->getPathname() );
		if ( 'epub' === strtolower( pathinfo( $p, PATHINFO_EXTENSION ) ) && ! str_starts_with( $p, $guarded . '/' ) ) {
			$stray[] = str_replace( $base, '', $p );
		}
	}
	pf_mig_out( sprintf( 'ePub hors répertoire protégé : %d   (cible 0)%s', count( $stray ), $stray ? '  ✗' : '  ✓' ) );
	foreach ( array_slice( $stray, 0, 10 ) as $s ) {
		pf_mig_out( '    ' . $s );
	}

	$ids = pf_mig_product_ids();
	$ok  = 0;
	$bad = [];
	foreach ( $ids as $pid ) {
		$files = get_post_meta( $pid, '_downloadable_files', true );
		$good  = is_array( $files ) && $files;
		foreach ( (array) $files as $file ) {
			$stored = isset( $file['file'] ) ? (string) $file['file'] : '';
			if ( ! pf_epub_is_protected_path( $stored ) || $stored !== pf_epub_stored_path( basename( $stored ) ) ) {
				$good = false;
			}
		}
		if ( $good ) {
			++$ok;
		} else {
			$bad[] = $pid;
		}
	}
	pf_mig_out( sprintf( 'Produits résolvant vers un fichier protégé et portable : %d/%d%s', $ok, count( $ids ), $ok === count( $ids ) ? '  ✓' : '  ✗' ) );
	if ( $bad ) {
		pf_mig_out( '    produits à revoir : ' . implode( ', ', array_slice( $bad, 0, 20 ) ) );
	}

	$htaccess = pf_epub_dir() . '/.htaccess';
	pf_mig_out( sprintf( 'Règle serveur .htaccess : %s (inopérante sous nginx — cf. CLAUDE.md)', file_exists( $htaccess ) ? 'présente ✓' : 'ABSENTE ✗' ) );

	pf_mig_out( '' );
	pf_mig_out( 0 === $attachments && ! $stray && $ok === count( $ids )
		? 'TERMINÉ — la fuite est fermée. Vérifier ensuite que l\'API REST ne renvoie plus d\'ePub, puis SUPPRIMER CE SCRIPT.'
		: 'INCOMPLET — relancer, et lire les lignes ✗ ci-dessus.' );
}

/* ─── Boucle ─────────────────────────────────────────────────────────────── */

$pf_ids   = pf_mig_product_ids();
$pf_total = count( $pf_ids );
$pf_slice = array_slice( $pf_ids, $pf_from, $pf_size );

pf_mig_out( sprintf( 'Produits téléchargeables : %d — lot %d à %d', $pf_total, $pf_from + 1, min( $pf_from + count( $pf_slice ), $pf_total ) ) );
pf_mig_out( '' );

foreach ( $pf_slice as $pf_i => $pf_pid ) {
	$pf_title = get_the_title( $pf_pid );
	pf_mig_out( sprintf( '[%d/%d] #%d %s', $pf_from + $pf_i + 1, $pf_total, $pf_pid, $pf_title ) );
	pf_mig_out( '        ' . pf_mig_process( $pf_pid ) );
}

$pf_next = $pf_from + count( $pf_slice );

if ( $pf_next >= $pf_total ) {
	pf_mig_report();
} elseif ( $pf_cli ) {
	// Seul cas en CLI : un `limit=N` volontaire. Pas de relance automatique, on
	// veut justement rendre la main.
	pf_mig_out( '' );
	pf_mig_out( sprintf( '%d produit(s) traité(s), %d restant(s). Relancer sans « limit » pour terminer.', $pf_next, $pf_total - $pf_next ) );
} else {
	// Auto-drainage : un lot par chargement, la page appelle la suivante. Évite
	// max_execution_time (70 Mo de copie + md5 en une requête) sans dépendre de
	// set_time_limit(0), souvent interdit en mutualisé.
	$pf_url = add_query_arg( [ 'go' => 1, 'from' => $pf_next ], remove_query_arg( [ 'go', 'from' ], $_SERVER['REQUEST_URI'] ?? '' ) );
	pf_mig_out( '' );
	pf_mig_out( sprintf( 'Lot suivant dans un instant… (%d/%d)', $pf_next, $pf_total ) );
	printf( '<meta http-equiv="refresh" content="1;url=%s">', esc_attr( $pf_url ) );
	printf( '<p><a href="%s">Continuer maintenant</a></p>', esc_url( $pf_url ) );
}

if ( ! $pf_cli ) {
	echo '</body>';
}
