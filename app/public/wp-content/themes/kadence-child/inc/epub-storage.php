<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Stockage protégé des fichiers ePub.
 *
 * Les 64 ePub du catalogue avaient été importés DANS LA MÉDIATHÈQUE : c'étaient
 * donc des attachments publics, rangés dans `uploads/2026/05/` sous leur ISBN nu.
 * Conséquence mesurée le 2026-07-30 : `GET /wp-json/wp/v2/media?media_type=application`
 * (sans authentification) renvoyait les 64 fichiers avec leur `source_url`, et
 * chaque URL répondait 200 avec le fichier complet — tout le catalogue numérique
 * payant était téléchargeable gratuitement, en deux requêtes. Le `deny from all`
 * de `woocommerce_uploads/` n'y changeait rien : il est Apache-only (nginx
 * l'ignore) et 63 des 64 fichiers n'étaient de toute façon pas dans ce dossier.
 *
 * Ce fichier tient la partie PERMANENTE du correctif :
 *  - le répertoire protégé et sa règle serveur (écrite par PHP, cf. plus bas) ;
 *  - la forme portable des chemins stockés dans `_downloadable_files` ;
 *  - le routage des FUTURS envois d'ePub vers ce répertoire ;
 *  - l'enregistrement du répertoire dans la liste approuvée de WooCommerce.
 *
 * La migration des 64 fichiers existants est un événement unique par
 * installation : elle vivait dans `tools/pf-migrate-epubs.php`, script
 * temporaire supprimé après passage sur chaque environnement.
 *
 * ⚠️ La méthode de téléchargement WooCommerce DOIT rester « force »
 * (`woocommerce_file_download_method`). Elle fait lire le fichier par PHP
 * (`readfile_chunked()`, un `fopen()` sur un chemin disque), ce que la règle
 * serveur n'entrave pas — une règle HTTP ne contraint pas le système de
 * fichiers. La passer à « redirect » publierait l'URL réelle et rouvrirait la
 * fuite instantanément.
 */

/* ═══════════════════════════════════════════════════════════════
   Emplacement et forme des chemins
   ═══════════════════════════════════════════════════════════════ */

/**
 * Répertoire protégé, en chemin absolu (sans slash final).
 * Point de passage UNIQUE : déplacer le stock un jour = changer cette fonction.
 */
function pf_epub_dir(): string {
	return wp_normalize_path( trailingslashit( wp_get_upload_dir()['basedir'] ) . 'pf-epub' );
}

/**
 * Même répertoire, en chemin RACINE-RELATIF (`/wp-content/uploads/pf-epub`).
 *
 * C'est sous cette forme que les chemins sont stockés dans `_downloadable_files`,
 * et c'est un choix structurant : contrairement à une URL absolue, elle survit à
 * un changement de domaine COMME à un changement de chemin disque. Les valeurs
 * d'origine étaient des URL absolues (`http://editions-passiflore.local/…`), donc
 * elles ne résolvaient que sur l'hôte qui les avait écrites — ailleurs,
 * `WC_Download_Handler::parse_file_path()` les classait « remote » et
 * `readfile_chunked()` allait chercher le fichier par HTTP. C'est précisément ce
 * qui aurait transformé la règle de blocage en panne totale.
 *
 * Forme de première classe pour WooCommerce : `parse_file_path()` et
 * `WC_Product_Download::file_exists()` ont chacun une branche `/wp-content`
 * explicite (vérifié dans le source, WC 10.x).
 *
 * Repli en chemin absolu si les uploads sortent de l'arborescence WordPress
 * (constante `UPLOADS` exotique) — là, la portabilité n'est plus exprimable.
 */
function pf_epub_rel_dir(): string {
	$dir     = pf_epub_dir();
	$abspath = wp_normalize_path( untrailingslashit( ABSPATH ) );

	return str_starts_with( $dir, $abspath . '/' ) ? substr( $dir, strlen( $abspath ) ) : $dir;
}

/** Valeur à écrire dans `_downloadable_files` pour un fichier donné. */
function pf_epub_stored_path( string $basename ): string {
	return pf_epub_rel_dir() . '/' . $basename;
}

/**
 * URL du répertoire telle que la liste approuvée de WooCommerce la stocke.
 *
 * ⚠️ Valeur NORMALISÉE, à utiliser aussi bien pour enregistrer que pour tester
 * l'existence : `Register::approved_directory_exists()` compare la chaîne brute
 * sans la normaliser (vérifié — `exists('/wp-content/…/')` rend false alors que
 * la ligne existe, `exists('file:///wp-content/…/')` rend true).
 *
 * Un chemin racine-relatif se normalise en `file:///wp-content/uploads/pf-epub/`,
 * chaîne INDÉPENDANTE DE L'HÔTE : une seule ligne suffit donc, et elle reste
 * juste après un clone de base — contrairement aux lignes que WooCommerce crée
 * lui-même, qui embarquent le domaine ou le chemin disque.
 */
function pf_epub_approved_url(): string {
	return 'file://' . trailingslashit( pf_epub_rel_dir() );
}

/**
 * Nom de fichier à entropie : `9782379460005-a7f3k2.epub`.
 *
 * L'entropie est sur le NOM et non sur le répertoire. Les ISBN sont publics et
 * imprimés sur les livres, donc un répertoire fixe + nom ISBN reste énumérable
 * partout où la règle serveur ne s'applique pas (nginx). Un répertoire secret,
 * lui, demanderait une option annexe à persister, fuiterait par les dumps et
 * l'écran d'édition produit, et surtout MASQUERAIT une règle serveur mal
 * configurée. Six caractères aléatoires dans le nom, c'est aussi la convention
 * de WooCommerce (`woocommerce_downloads_add_hash_to_filename`).
 */
function pf_epub_unique_basename( string $filename ): string {
	$name = pathinfo( $filename, PATHINFO_FILENAME );
	$name = $name ? $name : 'livre';

	return sanitize_file_name( $name . '-' . wp_generate_password( 6, false, false ) . '.epub' );
}

/* ═══════════════════════════════════════════════════════════════
   Création du répertoire et de sa règle serveur
   ═══════════════════════════════════════════════════════════════ */

/**
 * Crée le répertoire protégé s'il manque, avec sa règle de refus et son
 * index muet. Idempotent : les deux fichiers ne sont écrits que s'ils sont
 * absents (forme reprise de `WC_Install::create_files()`).
 *
 * ⚠️ La règle est écrite PAR PHP et non livrée par git : `uploads/` et
 * `app/public/.htaccess` sont tous deux gitignorés (.gitignore:51,83), donc
 * aucun fichier de protection ne peut arriver par git-ftp.
 *
 * ⚠️ Le `.htaccess` ne fait rien sous nginx (Local), et le bloc nginx
 * équivalent ne fait rien sous Apache (Ionos). Sur un hôte nginx, ceci est
 * OBLIGATOIRE dans le bloc serveur — cf. CLAUDE.md :
 *     location ~* ^/wp-content/uploads/pf-epub/ { deny all; }
 */
function pf_epub_ensure_dir(): bool {
	$dir = pf_epub_dir();

	if ( ! wp_mkdir_p( $dir ) ) {
		return false;
	}

	$files = [
		'.htaccess'  => "# Fichiers payants : aucun accès HTTP direct.\n"
			. "# Le téléchargement légitime passe par PHP (WooCommerce, méthode « force »)\n"
			. "# ou par l'endpoint du lecteur, qui lisent le fichier sur le disque.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
			. "Options -Indexes\n",
		'index.html' => '',
	];

	foreach ( $files as $name => $contents ) {
		$path = $dir . '/' . $name;
		if ( ! file_exists( $path ) ) {
			$fh = @fopen( $path, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $fh ) {
				fwrite( $fh, $contents );
				fclose( $fh );
			}
		}
	}

	return true;
}

/* ═══════════════════════════════════════════════════════════════
   Résolution d'un chemin stocké
   ═══════════════════════════════════════════════════════════════ */

/**
 * Chemin disque réel d'une valeur de `_downloadable_files`, ou false.
 *
 * Délègue à `WC_Download_Handler::parse_file_path()` (donc exactement la même
 * résolution que le téléchargement lui-même) et refuse tout ce qu'elle classe
 * « remote » : un fichier qu'on ne sait lire que par HTTP n'est pas un fichier
 * protégé.
 */
function pf_epub_resolve( string $stored ) {
	if ( '' === $stored || ! class_exists( 'WC_Download_Handler' ) ) {
		return false;
	}

	$parsed = WC_Download_Handler::parse_file_path( $stored );
	if ( ! empty( $parsed['remote_file'] ) || empty( $parsed['file_path'] ) ) {
		return false;
	}

	$real = realpath( $parsed['file_path'] );

	return $real ? wp_normalize_path( $real ) : false;
}

/**
 * Le fichier est-il DANS le répertoire protégé ?
 *
 * Sert de garde de traversée à l'endpoint de service du lecteur, et de test
 * « déjà migré ? » au script de migration. Comparaison sur les chemins réels,
 * avec le séparateur final, pour qu'un répertoire voisin dont le nom commence
 * pareil (`pf-epub-old/`) ne passe pas.
 */
function pf_epub_is_protected_path( string $stored ): bool {
	$real = pf_epub_resolve( $stored );
	$base = realpath( pf_epub_dir() );

	if ( ! $real || ! $base ) {
		return false;
	}

	return str_starts_with( $real, wp_normalize_path( $base ) . '/' )
		&& 'epub' === strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
}

/* ═══════════════════════════════════════════════════════════════
   Liste des répertoires de téléchargement approuvés
   ═══════════════════════════════════════════════════════════════ */

/**
 * Déclare le répertoire protégé auprès de WooCommerce.
 *
 * Indispensable, et pour un motif dont l'échec est SILENCIEUX :
 * `wc_downloads_approved_directories_mode` vaut `enabled` sur ce site, et
 * `WC_Product::set_downloads()` appelle `check_is_valid()` par fichier — quand
 * la validation lève pour un download EXISTANT, WooCommerce se contente d'un
 * `set_enabled( false )` sans le dire. Sans cette ligne, un simple clic sur
 * « Mettre à jour » un produit numérique couperait l'accès des clients qui
 * l'ont acheté.
 *
 * L'auto-ajout de WooCommerce (`approved_directory_checks()`) ne suffit pas :
 * il n'active la ligne que si l'utilisateur qui enregistre a `manage_options`
 * — un gestionnaire de boutique déclencherait exactement le cas ci-dessus.
 *
 * Pas de garde par option : l'URL étant indépendante de l'hôte, une lecture
 * suffit, elle n'a lieu que sur les écrans d'admin, et s'en passer rend le
 * mécanisme auto-cicatrisant (ligne supprimée à la main → re-créée).
 */
add_action( 'admin_init', 'pf_epub_register_approved_dir' );
function pf_epub_register_approved_dir(): void {
	if ( wp_doing_ajax() || ! function_exists( 'wc_get_container' ) ) {
		return;
	}

	$class = '\Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register';
	if ( ! class_exists( $class ) ) {
		return;
	}

	// Classe marquée @internal côté WooCommerce → tout est sous try/catch, et un
	// échec ne doit jamais casser l'admin.
	try {
		$register = wc_get_container()->get( $class );

		// Forme portable (celle que nous stockons) + forme URL, pour les chemins
		// qui arriveraient en absolu par un import REST. La seconde embarque le
		// domaine, comme les lignes que WooCommerce crée pour ses propres
		// répertoires : elle est donc re-créée d'office après un changement d'hôte,
		// puisqu'on teste chaque forme séparément.
		$urls = [
			pf_epub_approved_url(),
			trailingslashit( wp_get_upload_dir()['baseurl'] ) . 'pf-epub/',
		];

		foreach ( $urls as $url ) {
			if ( ! $register->approved_directory_exists( $url ) ) {
				$register->add_approved_directory( $url, true );
			}
		}
	} catch ( \Throwable $e ) {
		error_log( 'pf_epub_register_approved_dir: ' . $e->getMessage() );
	}
}

/* ═══════════════════════════════════════════════════════════════
   Routage des FUTURS envois d'ePub
   ═══════════════════════════════════════════════════════════════ */

/**
 * Un ePub envoyé désormais — médiathèque, panneau « Fichiers téléchargeables »
 * d'un produit, import — atterrit dans le répertoire protégé sous un nom non
 * devinable, au lieu de repeupler `uploads/AAAA/MM/` en accès libre.
 *
 * C'est ce qui rend le correctif permanent, et c'est la seule protection là où
 * la règle serveur ne s'applique pas.
 *
 * Le drapeau statique porte l'information d'un filtre à l'autre : `upload_dir`
 * est global et sans contexte, on ne peut pas y savoir quel fichier est en
 * cours d'envoi.
 */
function pf_epub_upload_routing( ?bool $set = null ): bool {
	static $routing = false;

	if ( null !== $set ) {
		$routing = $set;
	}

	return $routing;
}

add_filter( 'wp_handle_upload_prefilter', 'pf_epub_upload_prefilter' );
function pf_epub_upload_prefilter( array $file ): array {
	if ( 'epub' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ) {
		return $file;
	}

	if ( ! pf_epub_ensure_dir() ) {
		return $file; // Répertoire impossible à créer : ne pas casser l'envoi.
	}

	pf_epub_upload_routing( true );

	$file['name'] = pf_epub_unique_basename( $file['name'] );

	return $file;
}

add_filter( 'upload_dir', 'pf_epub_upload_dir' );
function pf_epub_upload_dir( array $dirs ): array {
	if ( ! pf_epub_upload_routing() ) {
		return $dirs;
	}

	$dirs['subdir'] = '/pf-epub';
	$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
	$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];

	return $dirs;
}

/** Le drapeau ne doit pas survivre à l'envoi : `upload_dir` sert tout le site. */
add_filter( 'wp_handle_upload', 'pf_epub_upload_done' );
function pf_epub_upload_done( array $upload ): array {
	pf_epub_upload_routing( false );

	return $upload;
}

/**
 * Supprime l'enregistrement d'attachment de tout ePub envoyé.
 *
 * Le routage ci-dessus place déjà le FICHIER dans le répertoire protégé sous un
 * nom non devinable — mais l'écran d'admin (médiathèque comme panneau « Fichiers
 * téléchargeables ») crée toujours un post `attachment` pour ce qu'on envoie,
 * quel que soit l'endroit où atterrissent les octets. Ce post reste listable sans
 * authentification via `wp/v2/media` (`source_url` inclus) : exactement la fuite
 * d'origine, rouverte au prochain ePub envoyé si on ne le supprime pas ici.
 *
 * ⚠️ Suppression reportée à `shutdown`, PAS faite dans `add_attachment` lui-même :
 * `wp_insert_attachment()` (donc `add_attachment`) tourne au milieu de
 * `media_handle_upload()` — l'appelant (l'AJAX d'upload de la médiathèque, comme
 * le REST `wp/v2/media`) se sert encore de l'attachment juste après pour générer
 * ses metadata et construire la réponse JSON (`wp_prepare_attachment_for_js()`).
 * Le supprimer immédiatement fait échouer l'envoi côté navigateur alors que le
 * fichier, lui, est bien arrivé au bon endroit — observé le 2026-08-05. Une fois
 * différée à `shutdown`, la réponse est déjà partie, ce qui reste ne gêne plus
 * personne.
 *
 * `_wp_attached_file` est déjà posée quand `add_attachment` se déclenche
 * (`update_attached_file()` tourne avant, dans `wp_insert_attachment()`) : on la
 * retire avant `wp_delete_attachment( …, true )` pour que WordPress ne supprime
 * pas le fichier qu'il vient de router — même technique, et même raison, que
 * l'étape 7 de `tools/pf-migrate-epubs.php`.
 */
function pf_epub_pending_attachment_ids( ?int $add = null ): array {
	static $ids = [];

	if ( null !== $add ) {
		$ids[] = $add;
	}

	return $ids;
}

add_action( 'add_attachment', 'pf_epub_queue_attachment_strip' );
function pf_epub_queue_attachment_strip( int $attachment_id ): void {
	if ( 'application/epub+zip' !== get_post_mime_type( $attachment_id ) ) {
		return;
	}

	if ( ! pf_epub_pending_attachment_ids() ) {
		add_action( 'shutdown', 'pf_epub_strip_pending_attachments' );
	}

	pf_epub_pending_attachment_ids( $attachment_id );
}

function pf_epub_strip_pending_attachments(): void {
	foreach ( pf_epub_pending_attachment_ids() as $attachment_id ) {
		delete_post_meta( $attachment_id, '_wp_attached_file' );
		wp_delete_attachment( $attachment_id, true );
	}
}

/**
 * Ramène à la forme portable les chemins d'ePub soumis par l'écran produit.
 *
 * Le panneau « Fichiers téléchargeables » insère l'URL du média, donc une URL
 * ABSOLUE avec le domaine — même après routage dans le répertoire protégé. Deux
 * ennuis, tous deux différés :
 *  1. portabilité : la valeur ne résout que sur l'hôte qui l'a écrite (c'est
 *     exactement le défaut V1 que la migration a corrigé pour les 64 fichiers) ;
 *  2. pire, `check_is_valid()` testerait le parent `http://domaine/…/pf-epub/`,
 *     absent de la liste approuvée → auto-ajout, mais activé UNIQUEMENT si
 *     l'utilisateur a `manage_options`. Un gestionnaire de boutique verrait donc
 *     son fichier `set_enabled( false )` en silence.
 *
 * On réécrit donc le `$_POST` AVANT que WooCommerce ne construise ses objets
 * (`WC_Meta_Box_Product_Data::save` est en priorité 10 sur ce hook, d'où le 5) :
 * `prepare_downloads()` est `private static` et n'offre aucun filtre.
 *
 * Filet complémentaire pour les chemins qui n'entrent pas par cet écran (import
 * REST) : `pf_epub_register_approved_dir()` déclare aussi la forme URL.
 */
add_action( 'woocommerce_process_product_meta', 'pf_epub_normalize_posted_paths', 5 );
function pf_epub_normalize_posted_paths(): void {
	foreach ( [ '_wc_file_urls', '_wc_variation_file_urls' ] as $key ) {
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			continue;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- réécriture de valeurs que WooCommerce assainit ensuite.
		array_walk_recursive( $_POST[ $key ], 'pf_epub_normalize_posted_path' );
	}
}

/** Une valeur de chemin : réécrite seulement si elle désigne un ePub déjà protégé. */
function pf_epub_normalize_posted_path( &$value ): void {
	if ( ! is_string( $value ) || '' === trim( $value ) ) {
		return;
	}

	$candidate = wp_unslash( trim( $value ) );
	if ( pf_epub_stored_path( basename( $candidate ) ) === $candidate ) {
		return; // Déjà à la bonne forme.
	}

	if ( pf_epub_is_protected_path( $candidate ) ) {
		$value = wp_slash( pf_epub_stored_path( basename( wp_parse_url( $candidate, PHP_URL_PATH ) ) ) );
	}
}

/* ═══════════════════════════════════════════════════════════════
   Écran produit — panneau natif « Fichiers téléchargeables »
   ═══════════════════════════════════════════════════════════════ */

/**
 * Deux champs du panneau natif ne collent plus au fonctionnement du site :
 *
 *  - « Nom » (`_wc_file_names[]`) : jamais lu. Le libellé montré au client est
 *    toujours composé à partir du titre (`filter_downloadable_item_names()` et
 *    `render_download_list()` dans `inc/class-ebooks.php`), ce champ n'est
 *    consulté nulle part — masqué en entier (en-tête + cellule).
 *  - « URL du fichier » (`_wc_file_urls[]`) : une saisie manuelle contournerait
 *    le routage vers le répertoire protégé (cf. en-tête de ce fichier) — seule
 *    la voie « Choisir un fichier » (upload réel, passe par
 *    `wp_handle_upload_prefilter`) doit pouvoir la renseigner. Champ laissé
 *    VISIBLE en lecture seule plutôt que masqué : c'est la seule façon de
 *    retrouver, pour un livre donné, le nom à entropie du fichier sur le
 *    disque — nécessaire pour le supprimer manuellement (plus d'attachment
 *    listable depuis la médiathèque, cf. `pf_epub_strip_pending_attachments()`
 *    plus haut).
 *
 * `readonly` (pas `disabled`) : la valeur reste sélectionnable/copiable et
 * continue d'être postée avec le formulaire ; seule la saisie clavier est
 * bloquée. Réappliqué après chaque clic sur « Add File » car WooCommerce
 * ajoute cette ligne par un simple `.append()` du template HTML, sans repasser
 * par un hook PHP.
 *
 * Sur une ligne qui a déjà un fichier, « Choisir un fichier » devient
 * « Remplacer le fichier » (confirmation, puis vidage client des deux champs
 * texte de la ligne — ce qui fait réapparaître le bouton natif pour choisir le
 * remplaçant). ⚠️ Le champ caché `_wc_file_hashes[]` n'est JAMAIS
 * touché : c'est ce hash qui sert de `download_id` dans
 * `wc_customer_download` (droits déjà accordés aux acheteurs). Le préserver
 * permet — dans la MÊME sauvegarde — de choisir un fichier de remplacement
 * pour la même ligne : au prochain enregistrement, `prepare_downloads()`
 * (cœur WooCommerce) réutilise ce hash, et les client·es déjà ayant droit au
 * téléchargement reçoivent le nouveau fichier sans repasser par un achat. Si
 * le produit est enregistré alors que le champ est vide, la ligne disparaît
 * de `_downloadable_files` et ce même hash devient orphelin : c'est la
 * suppression réelle, d'où l'avertissement dans la confirmation.
 *
 * Un seul ePub par produit : label au singulier (`gettext` ciblé), colonne
 * de réordonnancement, bouton « Ajouter un fichier » et lien « Supprimer »
 * masqués (aucun sens à plusieurs lignes, et sans bouton d'ajout un
 * « Supprimer » viderait la table sans moyen d'y remettre une ligne avant
 * un rechargement), une ligne toujours présente (ajoutée en JS si le
 * produit n'a encore aucun fichier) pour que « Choisir un fichier » soit
 * immédiatement disponible.
 */
add_filter( 'gettext', function ( $translated, $text, $domain ) {
	if ( 'woocommerce' === $domain && 'Downloadable files' === $text ) {
		return __( 'Fichier téléchargeable', 'kadence-child' );
	}
	return $translated;
}, 10, 3 );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	global $post;

	if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
		return;
	}
	if ( ! ( $post instanceof WP_Post ) || 'product' !== $post->post_type ) {
		return;
	}

	wp_add_inline_style( 'wp-admin', '
		.downloadable_files th:nth-child(2),
		.downloadable_files td.file_name { display: none; }
		.downloadable_files th.sort,
		.downloadable_files td.sort { display: none; }
		.downloadable_files a.insert { display: none; }
		.downloadable_files a.delete { display: none !important; }
		.downloadable_files td.file_url input { background: #f6f7f7; color: #50575e; }
		._download_limit_field { display: none !important; }
		._download_expiry_field { display: none !important; }
		._tax_status_field { display: none !important; }
		._tax_class_field { display: none !important; }
	' );

	wp_enqueue_script( 'jquery' );
	wp_add_inline_script( 'jquery', '
		jQuery( function ( $ ) {
			var CONFIRM_MSG = "Les client·es qui ont déjà acheté ce livre numérique auront accès au fichier que vous choisirez en remplacement. Si vous enregistrez sans en choisir un, elles perdront l’accès. Continuer ?";

			function pfFileUrlInput( $row ) {
				return $row.find( "td.file_url input" );
			}

			function pfRefreshRow( $row ) {
				var hasFile = pfFileUrlInput( $row ).val() !== "";
				var $btn    = $row.find( "td.file_url_choose a" );

				$btn
					.toggleClass( "upload_file_button", ! hasFile )
					.toggleClass( "pf-replace-file-button button-link-delete", hasFile )
					.text( hasFile ? "Remplacer le fichier" : $btn.data( "choose" ) );
			}

			function pfInitRows() {
				$( ".downloadable_files tbody tr" ).each( function () {
					var $row = $( this );
					pfFileUrlInput( $row ).prop( "readonly", true );
					pfRefreshRow( $row );
				} );
			}

			// Bouton « Add File » masqué (un seul fichier) : on relit directement son
			// template `data-row`, plutôt que de déclencher le clic natif, pour ne pas
			// dépendre de l’ordre de chargement entre ce script et celui de WooCommerce.
			function pfEnsureRow() {
				var $panel = $( ".downloadable_files" );
				if ( $panel.find( "tbody tr" ).length === 0 ) {
					$panel.find( "tbody" ).append( $panel.find( "a.insert" ).data( "row" ) );
				}
			}

			pfEnsureRow();
			pfInitRows();

			// Fichier choisi via le sélecteur média natif : la ligne redevient remplaçable.
			$( document ).on( "change", ".downloadable_files td.file_url input", function () {
				pfRefreshRow( $( this ).closest( "tr" ) );
			} );

			$( document ).on( "click", ".pf-replace-file-button", function ( e ) {
				e.preventDefault();
				if ( ! window.confirm( CONFIRM_MSG ) ) {
					return;
				}
				var $row = $( this ).closest( "tr" );
				// ⚠️ Cibler par [name], jamais par la cellule "td.file_name input" :
				// _wc_file_hashes[] vit DANS td.file_name (cf. html-product-download.php
				// du cœur WooCommerce), un sélecteur par cellule l’effacerait aussi et
				// ferait générer un nouvel UUID à l’enregistrement — orphelinant les
				// droits déjà accordés (bug constaté le 2026-08-06).
				$row.find( "input[name=\"_wc_file_names[]\"], input[name=\"_wc_file_urls[]\"]" ).val( "" );
				pfRefreshRow( $row );
			} );
		} );
	' );
} );

/* ═══════════════════════════════════════════════════════════════
   Autocicatrisation des droits de téléchargement
   ═══════════════════════════════════════════════════════════════ */

/**
 * Comble, pour chaque commande valide ayant déjà acheté ce produit, les droits
 * de téléchargement manquants pour ses fichiers ACTUELS.
 *
 * Le cœur WooCommerce (`wc_downloadable_product_permissions()`) n'accorde les
 * droits qu'UNE fois, à la transition de statut de la commande, pour les
 * fichiers du produit à CET instant précis. Deux trous en découlent :
 *  - un livre numérique vendu en précommande, sans fichier, n'accorde alors
 *    RIEN — même une fois l'ePub ajouté plus tard, rien ne relie la commande
 *    passée au nouveau fichier ;
 *  - un remplacement de fichier change le hash (`download_id`) : les droits
 *    déjà accordés restent figés sur l'ancien, orphelins (cf. le bug corrigé
 *    ci-dessus, constaté le 2026-08-06).
 *
 * Purement additif : ne retire ni ne modifie jamais une permission existante,
 * ne fait qu'ajouter les `download_id` manquants pour les commandes qui ont
 * réellement acheté ce produit.
 *
 * ⚠️ `wp_woocommerce_downloadable_product_permissions` n'a AUCUNE contrainte
 * d'unicité (product_id, order_id, download_id) — rejouer
 * `wc_downloadable_file_permission()` pour un couple déjà accordé créerait une
 * ligne en double. La vérification préalable protège donc les 99% des
 * enregistrements du produit qui ne touchent aucun fichier (prix,
 * description…), pas seulement les remplacements d'ePub, rares.
 *
 * Deux ajouts par rapport à la version d'origine (statuts sur mesure,
 * inc/order-statuses.php) :
 *  - `has_status( wc_get_is_paid_statuses() )` au lieu d'un tableau figé
 *    `['completed','processing']` : couvre désormais `pf-precommande` —
 *    précisément le statut d'une commande qui ATTEND ce dépôt de fichier —
 *    ainsi que `pf-retrait-att`/`expediee`/`livree`/`retiree`.
 *  - un droit VRAIMENT nouveau (absent de `$already_granted`, donc pas un
 *    remplacement de fichier où le hash est préservé) déclenche l'email
 *    « Votre livre numérique est disponible » et tente de sortir la commande
 *    de précommande (pf_order_release_from_precommande()).
 */
add_action( 'woocommerce_process_product_meta', 'pf_epub_sync_permissions_for_product', 30 );
add_action( 'woocommerce_update_product', 'pf_epub_sync_permissions_for_product', 30 );
function pf_epub_sync_permissions_for_product( int $post_id ): void {
	// Écran produit classique ET CRUD (`WC_Product::save()`) peuvent tous deux
	// se déclencher pour le même enregistrement — une seule exécution utile.
	static $done = [];
	if ( isset( $done[ $post_id ] ) ) {
		return;
	}
	$done[ $post_id ] = true;

	$product = wc_get_product( $post_id );
	if ( ! $product || ! $product->is_downloadable() ) {
		return;
	}

	$current_download_ids = array_keys( $product->get_downloads() );
	if ( ! $current_download_ids ) {
		return;
	}

	global $wpdb;

	// Une ligne par commande ayant acheté ce produit, quel que soit son statut
	// actuel — filtré juste après par $order->has_status().
	//
	// ⚠️ PAS `wp_wc_order_product_lookup` : cette table analytique n'est peuplée
	// que de façon ASYNCHRONE (Action Scheduler, sur woocommerce_new_order) —
	// une commande qui vient d'être créée peut y être absente pendant plusieurs
	// minutes (constaté en test : 0 ligne immédiatement après update_status()).
	// `wp_woocommerce_order_items`/`_itemmeta` sont écrites de façon SYNCHRONE
	// par `$order->save()`, quel que soit l'état d'Action Scheduler.
	$order_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT oi.order_id, SUM( CAST( oim_qty.meta_value AS UNSIGNED ) ) AS qty
			FROM {$wpdb->prefix}woocommerce_order_items oi
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
				ON oim_pid.order_item_id = oi.order_item_id AND oim_pid.meta_key = '_product_id'
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty
				ON oim_qty.order_item_id = oi.order_item_id AND oim_qty.meta_key = '_qty'
			WHERE oim_pid.meta_value = %d
			GROUP BY oi.order_id",
			$post_id
		)
	);

	if ( ! $order_rows ) {
		return;
	}

	// Même garde que le cœur WooCommerce (wc_downloadable_product_permissions) :
	// une commande "en cours" n'ouvre les droits que si ce réglage l'autorise.
	$grant_on_processing = 'no' !== get_option( 'woocommerce_downloads_grant_access_after_payment' );

	foreach ( $order_rows as $row ) {
		$order = wc_get_order( (int) $row->order_id );
		if ( ! $order || ! $order->has_status( wc_get_is_paid_statuses() ) ) {
			continue;
		}
		if ( $order->has_status( 'processing' ) && ! $grant_on_processing ) {
			continue;
		}

		$already_granted = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT download_id FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions
				WHERE order_id = %d AND product_id = %d",
				$order->get_id(),
				$post_id
			)
		);

		$newly_granted = array_diff( $current_download_ids, $already_granted );
		foreach ( $newly_granted as $download_id ) {
			wc_downloadable_file_permission( $download_id, $product, $order, max( 1, (int) $row->qty ) );
		}

		if ( $newly_granted ) {
			pf_epub_notify_newly_available( $order, $post_id );
		}

		if ( function_exists( 'pf_order_release_from_precommande' ) ) {
			pf_order_release_from_precommande( $order );
		}
	}
}

/**
 * Envoie « Votre livre numérique est disponible » une seule fois par commande
 * × produit — méta `_pf_ebook_notified` (tableau d'IDs produit) en garde
 * complémentaire à celle des permissions ci-dessus : celle-ci protège
 * l'ACCORDAGE des droits (par download_id), celle-ci protège l'EMAIL, un
 * effet visible côté client qui ne doit jamais partir en double.
 */
function pf_epub_notify_newly_available( WC_Order $order, int $product_id ): void {
	$notified = $order->get_meta( '_pf_ebook_notified' );
	$notified = is_array( $notified ) ? $notified : [];

	if ( in_array( $product_id, $notified, true ) ) {
		return;
	}

	$mailer = WC()->mailer()->emails;
	if ( isset( $mailer['Passiflore_Email_Ebook_Dispo'] ) ) {
		$mailer['Passiflore_Email_Ebook_Dispo']->trigger_for_products( $order, [ $product_id ] );
	}

	$notified[] = $product_id;
	$order->update_meta_data( '_pf_ebook_notified', $notified );
	$order->save();
}
