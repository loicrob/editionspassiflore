<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Passiflore_Ebooks — « Livres numériques »
 *
 * Page compte /mon-compte/livres-numeriques : les ePub achetés par le client,
 * présentés en étagère de liseuses. Un clic ouvre le livre dans le navigateur
 * (lecteur ePub, cf. assets/js/epub-reader.js).
 *
 * Remplace la table de téléchargements du cœur WooCommerce, qui était jusqu'ici
 * masquée du menu et greffée sous la liste des commandes — ce qui n'offrait
 * aucune place à une présentation propre du catalogue numérique du client.
 *
 * ── Choix structurants ───────────────────────────────────────────────────────
 *
 * 1. On RÉUTILISE l'endpoint WooCommerce `downloads` (sa clé est inchangée),
 *    seul son SLUG devient `livres-numeriques`. On garde ainsi gratuitement la
 *    règle de réécriture, la détection de l'onglet actif
 *    (`wc_is_current_account_menu_item`) et l'entrée de menu.
 *
 * 2. Le slug est forcé PAR FILTRE, pas par l'option en base.
 *    `WC_Query::init_query_vars()` lit bien l'option trop tôt pour nous (elle
 *    est appelée sur `plugins_loaded`, donc avant le chargement du thème), MAIS
 *    tous ses consommateurs passent par `get_query_vars()`, qui applique
 *    `woocommerce_get_query_vars` à l'appel — donc bien après. C'est le
 *    mécanisme qu'utilise déjà Passiflore_Reading_List. Avantage sur l'écriture
 *    en base : une base restaurée ou une nouvelle installation se cicatrise
 *    d'elle-même au lieu de repasser en silence à `telechargements`.
 *
 * 3. On REMPLACE LE CALLBACK de l'endpoint plutôt que de surcharger
 *    `myaccount/downloads.php`. Surcharger le template n'apporterait qu'un
 *    fichier `@version` à réconcilier à chaque mise à jour de WooCommerce ; et
 *    se greffer sur `woocommerce_available_downloads` laisserait en place la
 *    branche « état vide » du cœur, qui n'a AUCUN hook à l'intérieur — le
 *    message français serait alors inatteignable.
 *
 * @see inc/epub-storage.php  Stockage protégé des fichiers (et pourquoi).
 */
class Passiflore_Ebooks {

	/** Clé d'endpoint WooCommerce — jamais le slug. */
	const ENDPOINT_KEY = 'downloads';

	/** Slug public, forcé par filtre. */
	const ENDPOINT_SLUG = 'livres-numeriques';

	const MENU_LABEL    = 'Livres numériques';
	const FLUSH_OPTION  = 'pf_epub_endpoint_v';
	const FLUSH_VERSION = '1';

	/** Positions de lecture : user meta [ product_id => ['cfi','pct','at'] ]. */
	const POSITION_META = '_pf_epub_positions';
	const POSITION_NONCE = 'pf_epub_pos';

	/** Un CFI epub.js dépasse rarement 200 caractères ; on plafonne largement. */
	const CFI_MAX = 512;

	public function __construct() {
		add_filter( 'woocommerce_get_query_vars', [ $this, 'filter_query_vars' ] );
		add_action( 'init', [ $this, 'maybe_flush' ], 99 );

		// Nom de l'endpoint, pour `wc_page_endpoint_title` et le <title>.
		// ⚠️ INERTE SUR CE SITE, et c'est normal : la page compte de Kadence ne
		// rend aucun <h1>, et Rank Math tient le <title> (« Mon compte » sur tous
		// les endpoints — vérifié aussi sur /liste-de-lecture, donc préexistant et
		// sans lien avec cette page). On le déclare quand même : c'est la réponse
		// juste à la question « comment s'appelle cet endpoint ? », et le repère
		// de navigation effectif est la pastille active de la nav du compte.
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT_KEY . '_title', [ $this, 'filter_endpoint_title' ] );

		// Notre rendu à la place de la table du cœur. Le cœur enregistre son
		// callback pendant sa phase d'inclusion, donc bien avant le thème.
		remove_action( 'woocommerce_account_' . self::ENDPOINT_KEY . '_endpoint', 'woocommerce_account_downloads' );
		add_action( 'woocommerce_account_' . self::ENDPOINT_KEY . '_endpoint', [ $this, 'render_account' ] );

		add_filter( 'woocommerce_account_menu_items', [ $this, 'filter_menu_item' ] );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 20 );
		add_action( 'wp_ajax_pf_epub_position', [ $this, 'ajax_save_position' ] );

		// Service du fichier au lecteur. On ne s'abonne que si la requête le
		// demande — c'est le motif de WC_Download_Handler::init().
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple aiguillage ; l'autorisation est faite dans serve_file().
		if ( isset( $_GET['pf_epub'] ) ) {
			add_action( 'init', [ $this, 'serve_file' ] );
		}
	}

	/* ─── Assets ─────────────────────────────────────────────────── */

	/**
	 * Bibliothèques AUTO-HÉBERGÉES (convention de Passiflore_Events_Map avec
	 * Leaflet) : dist minifiées à plat dans assets/vendor/, handle = nom nu de la
	 * bibliothèque, version = semver amont codée en dur — jamais filemtime(), qui
	 * est réservé à notre propre code.
	 *
	 * Pas de CDN : le lecteur ne doit pas devenir la deuxième dépendance externe
	 * du thème (la première, la liseuse PDF de inc/pageflip.php, en est une
	 * anomalie connue — cf. CLAUDE.md).
	 *
	 * epub.js est un bundle UMD qui lit le global `JSZip` : d'où l'ordre des
	 * dépendances, et non un simple enqueue côte à côte.
	 */
	public function enqueue() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		// La CLÉ d'endpoint, jamais le slug : is_wc_endpoint_url() attend la clé.
		if ( ! is_wc_endpoint_url( self::ENDPOINT_KEY ) ) {
			return;
		}

		$downloads = self::entitled_downloads();
		if ( ! $downloads ) {
			return;
		}

		$uri = get_stylesheet_directory_uri();
		$dir = get_stylesheet_directory();

		wp_enqueue_style(
			'pf-epub-reader',
			$uri . '/assets/css/epub-reader.css',
			[],
			filemtime( $dir . '/assets/css/epub-reader.css' )
		);

		wp_enqueue_script( 'jszip', $uri . '/assets/vendor/jszip/jszip.min.js', [], '3.10.1', true );
		wp_enqueue_script( 'epubjs', $uri . '/assets/vendor/epubjs/epub.min.js', [ 'jszip' ], '0.3.93', true );

		wp_enqueue_script(
			'pf-epub-reader',
			$uri . '/assets/js/epub-reader.js',
			[ 'epubjs', 'pf-session-toast' ],
			filemtime( $dir . '/assets/js/epub-reader.js' ),
			true
		);

		wp_localize_script(
			'pf-epub-reader',
			'PassifloreEpub',
			[
				'fileUrl'   => home_url( '/' ),
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::POSITION_NONCE ),
				'baseUrl'   => wc_get_account_endpoint_url( self::ENDPOINT_KEY ),
				// Positions envoyées d'emblée : évite un aller-retour à l'ouverture.
				// Cast en objet : un tableau PHP vide se sérialiserait en `[]`, et un
				// tableau JS n'est pas la bonne structure pour un dictionnaire d'IDs.
				'positions' => (object) self::positions(),
				'titles'    => array_map( 'get_the_title', array_combine( array_keys( $downloads ), array_keys( $downloads ) ) ),
				'i18n'      => [
					'loading'   => 'Ouverture du livre…',
					'error'     => 'Ce livre n’a pas pu être ouvert.',
					'forbidden' => 'Votre session a expiré ou ce livre n’est plus accessible.',
					'percent'   => '%s % lu',
				],
			]
		);
	}

	/* ─── Endpoint ───────────────────────────────────────────────── */

	/** Impose notre slug à l'endpoint `downloads`. */
	public function filter_query_vars( $vars ) {
		if ( is_array( $vars ) ) {
			$vars[ self::ENDPOINT_KEY ] = self::ENDPOINT_SLUG;
		}

		return $vars;
	}

	public function filter_endpoint_title( $title ) {
		return self::MENU_LABEL;
	}

	/**
	 * Flush unique après changement de slug d'endpoint : WordPress ne relit pas
	 * les règles ajoutées à chaud tant que le cache en base existe (même idiome
	 * que Passiflore_Reading_List::maybe_flush()).
	 *
	 * On profite du passage pour aligner l'option en base. Elle ne sert à rien
	 * au fonctionnement — le filtre suffit — mais sans ça l'écran
	 * WooCommerce → Avancé afficherait encore `telechargements` et mentirait au
	 * gérant. Écrire l'option est sans risque ici : c'est seulement la FILTRER
	 * qui arriverait trop tard (cf. l'en-tête de classe).
	 */
	public function maybe_flush() {
		if ( get_option( self::FLUSH_OPTION ) === self::FLUSH_VERSION ) {
			return;
		}

		if ( get_option( 'woocommerce_myaccount_downloads_endpoint' ) !== self::ENDPOINT_SLUG ) {
			update_option( 'woocommerce_myaccount_downloads_endpoint', self::ENDPOINT_SLUG );
		}

		flush_rewrite_rules( false );
		update_option( self::FLUSH_OPTION, self::FLUSH_VERSION );
	}

	/**
	 * Libellé français, et entrée masquée quand le client n'a aucun ePub —
	 * cohérent avec « Commandes » et « Avis laissés », déjà masqués à vide par
	 * pf_account_menu_reorder(). L'ordre, lui, reste fixé par cette fonction.
	 */
	public function filter_menu_item( $items ) {
		if ( ! is_array( $items ) || ! isset( $items[ self::ENDPOINT_KEY ] ) ) {
			return $items;
		}

		if ( ! self::entitled_product_ids() ) {
			unset( $items[ self::ENDPOINT_KEY ] );

			return $items;
		}

		$items[ self::ENDPOINT_KEY ] = self::MENU_LABEL;

		return $items;
	}

	/* ─── Droits d'accès ────────────────────────────────────────── */

	/**
	 * ePub auxquels le client a droit, indexés par ID produit.
	 *
	 * Source unique partagée par l'étagère, la liste de téléchargement, la tuile
	 * du tableau de bord et l'endpoint de service du lecteur — il ne doit exister
	 * qu'UNE définition de « ce client a droit à ce livre ».
	 *
	 * On part de `wc_get_customer_available_downloads()`, qui gère déjà la
	 * validité de la commande, `is_download_permitted()` et le drapeau `enabled`
	 * de chaque fichier ; on y ajoute ce qui nous est propre :
	 *  - déduplication par produit (le cœur rend une entrée par commande × produit
	 *    × fichier : racheter le même ePub le ferait apparaître deux fois) ;
	 *  - ePub seulement — le lecteur ne sait pas ouvrir autre chose ;
	 *  - limites de téléchargement et date d'expiration respectées même si elles
	 *    sont illimitées aujourd'hui : on ne construit pas un contournement de
	 *    son propre paywall ;
	 *  - produits publiés seulement (miroir de la liste de lecture) ;
	 *  - commande la plus récente d'abord, pour que le dernier achat soit la
	 *    première liseuse de l'étagère.
	 *
	 * @return array<int,array> [ product_id => entrée wc_get_customer_available_downloads() ]
	 */
	public static function entitled_downloads( int $user_id = 0 ): array {
		$user_id = $user_id ?: get_current_user_id();

		if ( ! $user_id || ! function_exists( 'wc_get_customer_available_downloads' ) ) {
			return [];
		}

		$out = [];

		foreach ( wc_get_customer_available_downloads( $user_id ) as $download ) {
			$product_id = isset( $download['product_id'] ) ? (int) $download['product_id'] : 0;
			$file       = isset( $download['file']['file'] ) ? (string) $download['file']['file'] : '';

			if ( ! $product_id || '' === $file ) {
				continue;
			}

			// Un ePub, et lui seul.
			$ext = strtolower( pathinfo( (string) wp_parse_url( $file, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			if ( 'epub' !== $ext ) {
				continue;
			}

			// Quota épuisé. '' ou null = illimité.
			$remaining = $download['downloads_remaining'] ?? '';
			if ( '' !== $remaining && null !== $remaining && (int) $remaining <= 0 ) {
				continue;
			}

			// Accès expiré.
			$expires = $download['access_expires'] ?? null;
			if ( ! empty( $expires ) && strtotime( $expires ) < time() ) {
				continue;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product || 'publish' !== $product->get_status() ) {
				continue;
			}

			// Première entrée gagnante : la liste du cœur est déjà ordonnée, et on
			// trie ensuite par commande décroissante.
			if ( ! isset( $out[ $product_id ] ) ) {
				$out[ $product_id ] = $download;
			}
		}

		uasort(
			$out,
			static function ( $a, $b ) {
				return (int) ( $b['order_id'] ?? 0 ) <=> (int) ( $a['order_id'] ?? 0 );
			}
		);

		return $out;
	}

	/** @return int[] IDs produit, dans l'ordre d'affichage. */
	public static function entitled_product_ids( int $user_id = 0 ): array {
		return array_keys( self::entitled_downloads( $user_id ) );
	}

	/** Le client a-t-il droit à CE produit ? (contrôle de l'endpoint de service) */
	public static function is_entitled( int $product_id, int $user_id = 0 ): bool {
		return isset( self::entitled_downloads( $user_id )[ $product_id ] );
	}

	/* ─── Service du fichier au lecteur ─────────────────────────── */

	/**
	 * Sert un ePub au lecteur, après contrôle du droit d'accès.
	 *
	 * Pourquoi un endpoint maison et non l'URL de téléchargement de WooCommerce :
	 * `woocommerce_downloads_count_partial` vaut `yes` sur ce site, donc CHAQUE
	 * lecture — même partielle — incrémenterait le compteur de téléchargements et
	 * remplirait `wp_wc_download_log`. Un client qui lit son livre en dix fois
	 * aurait « téléchargé » dix fois. On ne touche donc à aucune des machineries
	 * de download de WooCommerce ; le chemin COMPTÉ reste offert par la liste
	 * dépliable de la page.
	 *
	 * Pourquoi `init` et un paramètre de requête plutôt qu'admin-ajax ou une règle
	 * de réécriture : admin-ajax envoie `nocache_headers()` avant que notre code
	 * ne s'exécute (2 Mo re-téléchargés à chaque ouverture), et une règle de
	 * réécriture imposerait un flush pour rien. `init` s'exécute avant
	 * `WP::send_headers()`, donc rien n'a encore été émis.
	 *
	 * Pourquoi AUCUN NONCE, en accord avec la politique de CLAUDE.md et non par
	 * exception : la ligne de partage y est le CHANGEMENT D'ÉTAT, pas la
	 * confidentialité. Cet endpoint n'écrit rien — un nonce n'apporterait aucune
	 * protection CSRF (faire charger par votre navigateur votre propre ePub ne
	 * permet pas d'en lire la réponse depuis une autre origine) — alors qu'il
	 * ferait échouer en 403 un onglet resté ouvert au-delà de 12–24 h. Pour une
	 * session de lecture, c'est exactement le mauvais mode de défaillance.
	 * L'autorisation, ici, c'est le contrôle de droit d'accès.
	 */
	public function serve_file() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- lecture seule, cf. docblock.
		$product_id = absint( $_GET['pf_epub'] ?? 0 );
		// phpcs:enable

		if ( ! $product_id ) {
			self::deny( 400, 'Requête incomplète.' );
		}

		if ( ! is_user_logged_in() ) {
			self::deny( 403, 'Connexion requise.' );
		}

		$entitled = self::entitled_downloads();
		if ( ! isset( $entitled[ $product_id ] ) ) {
			self::deny( 403, 'Ce livre ne figure pas dans votre bibliothèque.' );
		}

		$stored = (string) ( $entitled[ $product_id ]['file']['file'] ?? '' );

		// Garde unique et suffisante : pf_epub_is_protected_path() vérifie d'un
		// coup que le chemin résout localement (donc pas « remote »), qu'il est
		// bien DANS le répertoire protégé — ce qui exclut toute traversée — et que
		// c'est un .epub.
		if ( ! function_exists( 'pf_epub_is_protected_path' ) || ! pf_epub_is_protected_path( $stored ) ) {
			self::deny( 404, 'Fichier introuvable.' );
		}

		$path = pf_epub_resolve( $stored );
		$size = (int) filesize( $path );
		$etag = '"' . md5( $path . filemtime( $path ) . $size ) . '"';

		// ⚠️ wp_unslash() OBLIGATOIRE : WordPress passe $_SERVER dans
		// wp_magic_quotes(), donc l'en-tête arrive avec ses guillemets ÉCHAPPÉS
		// (`\"abc\"`), et la comparaison échouait silencieusement — le fichier
		// entier était renvoyé à chaque ouverture au lieu d'un 304 (constaté en
		// test, en-tête de sonde à l'appui).
		$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] )
			? trim( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: '';

		if ( $inm === $etag ) {
			header( 'ETag: ' . $etag );
			header( 'Cache-Control: private, max-age=604800' );
			status_header( 304 );
			exit;
		}

		header( 'Content-Type: application/epub+zip' );
		header( 'Content-Length: ' . $size );
		header( 'ETag: ' . $etag );
		// `private` et jamais `public` : contenu payant. Le cache du navigateur
		// suffit à éviter de re-télécharger à chaque ouverture.
		header( 'Cache-Control: private, max-age=604800' );
		header( 'Vary: Cookie' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/** Refus : texte brut, pas de page WordPress (le lecteur lit le statut). */
	private static function deny( int $status, string $message ): void {
		status_header( $status );
		header( 'Content-Type: text/plain; charset=utf-8' );
		nocache_headers();
		echo $message; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/* ─── Mémoire de position ───────────────────────────────────── */

	/** @return array<int,array> [ product_id => ['cfi'=>string,'pct'=>float,'at'=>int] ] */
	public static function positions( int $user_id = 0 ): array {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return [];
		}

		$stored = get_user_meta( $user_id, self::POSITION_META, true );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Enregistre la position de lecture. Endpoint À ÉTAT → il GARDE son nonce
	 * (politique CLAUDE.md), et le front traite le 403 avec
	 * `window.pfSessionExpired({ mode: 'confirm' })` : jamais 'reload', qui
	 * jetterait précisément la position qu'on essayait de sauver.
	 */
	public function ajax_save_position() {
		check_ajax_referer( self::POSITION_NONCE, 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'reason' => 'auth' ], 403 );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$cfi        = isset( $_POST['cfi'] ) ? sanitize_text_field( wp_unslash( $_POST['cfi'] ) ) : '';
		$pct        = isset( $_POST['pct'] ) ? (float) $_POST['pct'] : 0.0;

		// On n'accepte une clé que si le client y a droit : sans ce contrôle, la
		// meta deviendrait un stockage arbitraire à sa main.
		if ( ! $product_id || '' === $cfi || ! self::is_entitled( $product_id ) ) {
			wp_send_json_error();
		}

		$entry = [
			'cfi' => mb_substr( $cfi, 0, self::CFI_MAX ),
			'pct' => max( 0.0, min( 1.0, $pct ) ),
			'at'  => time(),
		];

		$user_id = get_current_user_id();

		// Verrou consultatif MySQL : sérialise le read-modify-write de la meta pour
		// un même utilisateur (deux onglets ouverts sur deux livres s'écraseraient
		// sinon). Même idiome que Passiflore_Reading_List::ajax_toggle().
		global $wpdb;
		$lock = 'pf_epub_' . $user_id;
		$got  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 5 ) );

		wp_cache_delete( $user_id, 'user_meta' );
		$positions                = self::positions( $user_id );
		$positions[ $product_id ] = $entry;
		update_user_meta( $user_id, self::POSITION_META, $positions );

		if ( $got ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}

		wp_send_json_success( $entry );
	}

	/* ─── Rendu ──────────────────────────────────────────────────── */

	public function render_account() {
		$downloads = self::entitled_downloads();

		if ( ! $downloads ) {
			echo self::empty_notice(); // phpcs:ignore WordPress.Security.EscapeOutput

			return;
		}

		$ids = array_keys( $downloads );

		echo '<div class="pf-epub-library">';
		echo '<p class="pf-epub-intro">Cliquez sur une liseuse pour ouvrir le livre dans votre navigateur.</p>';

		// Pas de show_price (le client a payé), pas de show-bookmarks (un livre
		// acheté n'a rien à faire dans une liste de lecture — et ça évite un
		// conflit de clic réel avec le lecteur), pas de switch Couvertures|Dos
		// (une rangée de liseuses vue de dos est une rangée de bandes noires).
		echo do_shortcode(
			'[passiflore_etagere ids="' . implode( ',', array_map( 'absint', $ids ) ) . '" mode="shelves" epub-reader="true"]'
		);

		echo self::render_download_list( $downloads ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';

		echo self::render_overlay(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Coque du lecteur, rendue une seule fois côté serveur et vide (epub.js la
	 * remplit) — même approche que la liseuse PDF de la fiche livre.
	 */
	private static function render_overlay(): string {
		$icon = static function ( string $path, string $size = '20' ): string {
			return '<svg viewBox="0 -960 960 960" width="' . $size . '" height="' . $size . '" aria-hidden="true"><path d="' . $path . '"/></svg>';
		};

		// Material Symbols Rounded : chevron gauche / chevron droit / croix.
		$prev  = 'M400-480l168 168q12 12 12 28t-12 28q-12 12-28 12t-28-12L284-452q-6-6-9-13.5t-3-15.5q0-8 3-15.5t9-13.5l228-228q12-12 28-12t28 12q12 12 12 28t-12 28L400-480Z';
		$next  = 'M504-480L336-648q-12-12-12-28t12-28q12-12 28-12t28 12l228 228q6 6 9 13.5t3 15.5q0 8-3 15.5t-9 13.5L392-140q-12 12-28 12t-28-12q-12-12-12-28t12-28l168-168Z';
		$close = 'M480-424 284-228q-11 11-28 11t-28-11q-11-11-11-28t11-28l196-196-196-196q-11-11-11-28t11-28q11-11 28-11t28 11l196 196 196-196q11-11 28-11t28 11q11 11 11 28t-11 28L536-480l196 196q11 11 11 28t-11 28q-11 11-28 11t-28-11L480-424Z';

		return '<div class="pf-epub-overlay" id="pf-epub-overlay" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Lecteur de livre numérique">'
			. '<header class="pf-epub-bar">'
			. '<h2 class="pf-epub-title" id="pf-epub-title"></h2>'
			. '<span class="pf-epub-progress" role="status" aria-live="polite"></span>'
			. '<button type="button" class="pf-roundbtn pf-epub-close" aria-label="Fermer le lecteur (Échap)">' . $icon( $close, '22' ) . '</button>'
			. '</header>'
			. '<div class="pf-epub-viewport" id="pf-epub-viewport">'
			. '<p class="pf-epub-state" hidden></p>'
			. '</div>'
			. '<nav class="pf-epub-nav" aria-label="Pagination du livre">'
			. '<button type="button" class="pf-roundbtn pf-roundbtn--outline" data-pf-epub-action="prev" aria-label="Page précédente">' . $icon( $prev ) . '</button>'
			. '<button type="button" class="pf-roundbtn pf-roundbtn--outline" data-pf-epub-action="next" aria-label="Page suivante">' . $icon( $next ) . '</button>'
			. '</nav>'
			. '</div>';
	}

	/**
	 * Téléchargement direct des fichiers, replié dans un <details>.
	 *
	 * On a vendu un fichier, pas une licence de consultation : retirer le seul
	 * moyen de récupérer son ePub serait une régression, d'autant que le lecteur
	 * contourne volontairement le compteur de téléchargements de WooCommerce
	 * (`woocommerce_downloads_count_partial` = yes, chaque lecture partielle
	 * l'incrémenterait). Ces liens-ci empruntent le chemin COMPTÉ du cœur.
	 */
	private static function render_download_list( array $downloads ): string {
		$rows = '';

		foreach ( $downloads as $product_id => $download ) {
			$url = isset( $download['download_url'] ) ? (string) $download['download_url'] : '';
			if ( '' === $url ) {
				continue;
			}

			$title = get_the_title( $product_id );
			$size  = '';
			$path  = function_exists( 'pf_epub_resolve' ) ? pf_epub_resolve( (string) $download['file']['file'] ) : false;
			if ( $path ) {
				$size = ' <span class="pf-epub-size">' . esc_html( size_format( (int) filesize( $path ) ) ) . '</span>';
			}

			$rows .= '<li><a href="' . esc_url( $url ) . '" download>' . esc_html( $title ) . '</a>' . $size . '</li>';
		}

		if ( '' === $rows ) {
			return '';
		}

		return '<details class="pf-epub-files">'
			. '<summary>Télécharger mes fichiers ePub</summary>'
			. '<p class="pf-card-text">Pour lire vos livres hors ligne, sur une liseuse ou dans l’application de votre choix.</p>'
			. '<ul class="pf-epub-file-list">' . $rows . '</ul>'
			. '</details>';
	}

	/**
	 * État vide. L'entrée de menu étant masquée dans ce cas, on n'arrive ici que
	 * par une URL directe — d'où le renvoi vers le catalogue numérique.
	 */
	private static function empty_notice(): string {
		$url = '';
		$term = get_term_by( 'slug', 'numerique', 'pa_format_particulier' );
		if ( $term && ! is_wp_error( $term ) ) {
			$link = get_term_link( $term );
			$url  = is_wp_error( $link ) ? '' : $link;
		}
		if ( '' === $url && function_exists( 'wc_get_page_permalink' ) ) {
			$url = wc_get_page_permalink( 'shop' );
		}

		$out = '<p class="pf-liste-lecture-empty">Vous n’avez pas encore de livre numérique.<br>'
			. 'Les ePub que vous achetez apparaissent ici, et se lisent <strong>directement dans votre navigateur</strong>.</p>';

		if ( $url ) {
			$out .= '<p><a class="pf-btn pf-btn--primary" href="' . esc_url( $url ) . '">Découvrir les livres numériques</a></p>';
		}

		return $out;
	}
}

new Passiflore_Ebooks();
