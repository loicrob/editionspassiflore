<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Passiflore_Events_Feed
 *
 * Scroll infini bidirectionnel sur la vue liste de The Events Calendar (Views V2).
 *
 * Principe : on ne duplique aucun markup. Le front lit, dans la nav native rendue
 * par TEC, les URLs « préc. » (passés) et « suiv. » (à venir) comme curseurs opaques,
 * puis demande à cet endpoint AJAX de rendre la vue liste pour un curseur donné. On
 * renvoie uniquement les <li> de la liste (séparateurs de mois + lignes d'événement)
 * et le curseur suivant. Toutes les surcharges de templates du thème, les participants
 * et les séparateurs de mois s'appliquent donc automatiquement.
 *
 * AJAX action : pf_events_feed
 */
class Passiflore_Events_Feed {

	const VIEW_CLASS = '\Tribe\Events\Views\V2\View';

	public function __construct() {
		add_action( 'wp_enqueue_scripts',            [ $this, 'register_assets' ] );
		add_action( 'wp_head',                       [ $this, 'print_scroll_restoration' ], 1 );
		add_action( 'wp_ajax_pf_events_feed',        [ $this, 'ajax_feed' ] );
		add_action( 'wp_ajax_nopriv_pf_events_feed', [ $this, 'ajax_feed' ] );
	}

	/* ─── Anti « rechargement = on arrive tout en bas » ──────────────
	 *
	 * Les passés sont chargés en JS : au rechargement, la page ne contient à nouveau que
	 * les à-venir (courte), mais le navigateur restaure le grand scrollY mémorisé → rogné
	 * au bas de la page courte. On désactive la restauration auto du scroll, le plus tôt
	 * possible (dans le <head>, avant tout rendu — un script en footer arrive trop tard),
	 * et on la rétablit en quittant la page (autres pages / retour bfcache correct). */
	public function print_scroll_restoration() {
		if ( is_singular( 'tribe_events' ) ) return;
		if ( ! function_exists( 'tribe_is_event_query' ) || ! tribe_is_event_query() ) return;
		echo '<script>if("scrollRestoration" in history){history.scrollRestoration="manual";'
			. 'addEventListener("pagehide",function(){history.scrollRestoration="auto";});}</script>' . "\n";
	}

	/* ─── Assets (toutes les vues d'archive : liste / mois / jour) ───
	 *
	 * Chargés sur toute l'archive (et pas seulement la vue initiale) car TEC bascule
	 * de vue en AJAX sans recharger la page : les scripts doivent être présents pour
	 * se (ré)activer sur la vue affichée. events-infinite.js s'active sur la liste,
	 * events-month.js sur le header sticky de la vue mois ; chacun est inerte ailleurs. */
	public function register_assets() {
		if ( is_singular( 'tribe_events' ) ) return;
		if ( ! function_exists( 'tribe_is_event_query' ) || ! tribe_is_event_query() ) return;

		$uri = get_stylesheet_directory_uri();
		$dir = get_stylesheet_directory();

		wp_enqueue_style(
			'pf-events-infinite',
			$uri . '/assets/css/events-infinite.css',
			[ 'pf-events' ],
			filemtime( $dir . '/assets/css/events-infinite.css' )
		);
		wp_enqueue_script(
			'pf-events-infinite',
			$uri . '/assets/js/events-infinite.js',
			[],
			filemtime( $dir . '/assets/js/events-infinite.js' ),
			true
		);
		wp_localize_script( 'pf-events-infinite', 'PassifloreEventsFeed', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'pf_events_feed' ),
		] );
		wp_enqueue_script(
			'pf-events-month',
			$uri . '/assets/js/events-month.js',
			[],
			filemtime( $dir . '/assets/js/events-month.js' ),
			true
		);
		// Pastilles (vue mois) : état « foncé » unifié mono + multi-jours (multi-segment
		// + persistance pendant que le popup est dévoilé). Le popup lui-même reste natif
		// (TEC/tooltipster) → pas de dépendance jQuery ici.
		wp_enqueue_script(
			'pf-events-month-pills',
			$uri . '/assets/js/events-month-pills.js',
			[],
			filemtime( $dir . '/assets/js/events-month-pills.js' ),
			true
		);
		// Composant global .pf-scroll-fade (ombres de bord) : requis par le popup mobile
		// de la vue mois (contenu cloné après chargement → re-câblage via window.pfScrollFade).
		wp_enqueue_script(
			'pf-scroll-fade',
			$uri . '/assets/js/scroll-fade.js',
			[],
			filemtime( $dir . '/assets/js/scroll-fade.js' ),
			true
		);
		// Vue mois, mobile : popup d'événements au-dessus du jour tapé (remplace le
		// panneau natif « en bas de grille »). Inerte hors vue mois / hors mobile.
		wp_enqueue_script(
			'pf-events-month-mobile-pop',
			$uri . '/assets/js/events-month-mobile-pop.js',
			[ 'pf-scroll-fade' ],
			filemtime( $dir . '/assets/js/events-month-mobile-pop.js' ),
			true
		);
	}

	/* ─── Endpoint AJAX ──────────────────────────────────────────── */

	public function ajax_feed() {
		check_ajax_referer( 'pf_events_feed', 'nonce' );

		$url       = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$direction = ( ( $_POST['direction'] ?? 'next' ) === 'prev' ) ? 'prev' : 'next';

		if ( '' === $url ) {
			wp_send_json_error( [ 'message' => 'missing-url' ], 400 );
		}

		$payload = $this->render( $url, $direction );

		if ( false === $payload ) {
			wp_send_json_error( [ 'message' => 'render-failed' ], 500 );
		}

		wp_send_json_success( $payload );
	}

	/**
	 * Rend la vue liste TEC pour une URL-curseur et en extrait les <li>.
	 *
	 * @param string $url       URL-curseur (page à venir « suiv. » ou page passée « préc. »),
	 *                          telle que produite par la nav TEC ou par next_url/prev_url.
	 * @param string $direction 'next' (à venir, vers le bas) | 'prev' (passés, vers le haut).
	 * @return array{html:string, next_url:string, has_more:bool}|false
	 */
	private function render( $url, $direction ) {
		if ( ! class_exists( self::VIEW_CLASS ) ) return false;

		// Construit la vue exactement comme le fait l'endpoint REST natif de TEC :
		// make_for_rest parse l'URL-curseur (page, mode passé, date de la barre…),
		// gère le page-reset et les alias de contexte. On reste découplé du nonce REST.
		$request = new WP_REST_Request( 'GET', '/tribe/events/v2/html' );
		$request->set_param( 'url', $url );
		$request->set_param( 'view', 'list' );
		$request->set_param( 'should_manage_url', false );

		$view = call_user_func( [ self::VIEW_CLASS, 'make_for_rest' ], $request );
		if ( ! $view ) return false;

		$full  = (string) $view->get_html();
		$items = $this->extract_list_items( $full );

		// Curseur suivant dans la MÊME direction visuelle :
		//  - bas / à venir  → next_url() (page à venir plus lointaine)
		//  - haut / passés  → prev_url() (page passée plus ancienne ; en mode 'past',
		//    List_View::prev_url renvoie justement la page « plus ancienne »).
		$next = ( 'prev' === $direction )
			? (string) $view->prev_url( false )
			: (string) $view->next_url( false );

		$has_more = ( '' !== $items && '' !== $next );

		return [
			'html'     => $items,
			'next_url' => $has_more ? $next : '',
			'has_more' => $has_more,
		];
	}

	/**
	 * Extrait, du HTML complet de la vue, les enfants directs du
	 * <ul class="tribe-events-calendar-list"> (séparateurs de mois + lignes
	 * d'événement). On jette en-tête, top-bar, nav, loader et scripts.
	 */
	private function extract_list_items( $full ) {
		$full = trim( (string) $full );
		if ( '' === $full ) return '';

		$dom = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		// Force l'UTF-8 (accents des mois, noms d'auteurs).
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . $full,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query(
			"//ul[contains(concat(' ', normalize-space(@class), ' '), ' tribe-events-calendar-list ')]"
		);
		if ( ! $nodes || 0 === $nodes->length ) return '';

		$ul  = $nodes->item( 0 );
		$out = '';
		foreach ( $ul->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType ) {
				$out .= $dom->saveHTML( $child );
			}
		}

		return $out;
	}
}

new Passiflore_Events_Feed();
