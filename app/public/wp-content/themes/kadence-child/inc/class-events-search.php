<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Tribe__Date_Utils as Dates;

/**
 * Passiflore_Events_Search
 *
 * Barre de recherche de la page /evenements, rendue dans le header sticky
 * (tribe/.../components/header.php) en vue LISTE uniquement — en vue mois,
 * cet emplacement reste occupé par le top-bar natif (préc./suiv./datepicker).
 *
 * Réutilise le moteur partagé pf_search_events_ranked() (inc/search.php, le
 * même que la recherche globale). Résultats paginés par lots de PAGE_SIZE
 * (même taille que le scroll infini de la liste normale, inc/class-events-feed.php)
 * via un simple offset — pas de fenêtre de dates ici (un classement par
 * pertinence/proximité de date, pas la pagination native de la Vue TEC), donc
 * pas de curseur d'URL comme pf_events_feed : le classement complet est
 * recalculé à chaque requête (négligeable, cf. inc/search.php) et tranché
 * côté serveur selon l'offset envoyé par le client.
 *
 * Rendu des lignes : on ne passe PAS par le pipeline Vue/Repository de TEC
 * (vérifié dans le code source — il force son propre tri par date à
 * plusieurs endroits et ses templates dépendent de variables ambiantes que
 * seul un rendu de Vue complet positionne). render_event_row() reproduit
 * directement le HTML des overrides de ce thème (list/event.php + ses
 * sous-templates date-tag/title/description) et des partiels core encore
 * visibles ici (featured-image, venue) — date/cost/category sont soit
 * masqués en CSS soit jamais alimentés sur ce site, donc omis.
 *
 * Le JS (assets/js/events-search.js) masque la liste à scroll infini sans la
 * détruire pendant la recherche, et restaure sa position de scroll exacte à
 * la fermeture — voir CLAUDE.md, section Événements.
 *
 * Action AJAX : pf_events_search
 */
class Passiflore_Events_Search {

	const PAGE_SIZE = 12;

	public function __construct() {
		add_action( 'wp_enqueue_scripts',              [ $this, 'register_assets' ] );
		add_action( 'wp_ajax_pf_events_search',        [ $this, 'ajax_search' ] );
		add_action( 'wp_ajax_nopriv_pf_events_search', [ $this, 'ajax_search' ] );
	}

	/* ─── Assets ─────────────────────────────────────────────────── */

	public function register_assets() {
		if ( is_singular( 'tribe_events' ) ) return;
		if ( ! function_exists( 'tribe_is_event_query' ) || ! tribe_is_event_query() ) return;

		$uri = get_stylesheet_directory_uri();
		$dir = get_stylesheet_directory();

		wp_enqueue_script(
			'pf-events-search',
			$uri . '/assets/js/events-search.js',
			[],
			filemtime( $dir . '/assets/js/events-search.js' ),
			true
		);
		wp_localize_script( 'pf-events-search', 'PassifloreEventsSearch', [
			'ajax_url'           => admin_url( 'admin-ajax.php' ),
			'placeholder_mobile' => __( 'Événement', 'kadence-child' ),
		] );
	}

	/* ─── Markup (rendu depuis tribe/.../components/header.php) ───── */

	public static function render_bar() {
		$svg_loupe = '<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg>';

		ob_start();
		?>
		<div class="pf-ev-search">
			<div class="pf-search pf-search--cat">
				<?php echo $svg_loupe; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<input
					type="search"
					class="pf-search-input pf-ev-search-input"
					placeholder="<?php esc_attr_e( 'Rechercher par mot-clé, participant, lieu, livre…', 'kadence-child' ); ?>"
					data-placeholder-sm="<?php esc_attr_e( 'Par mot-clé, participant, lieu, livre…', 'kadence-child' ); ?>"
					autocomplete="off"
					aria-label="<?php esc_attr_e( 'Rechercher un événement', 'kadence-child' ); ?>"
				>
				<button class="pf-search-clear pf-ev-search-clear" type="button"
					aria-label="<?php esc_attr_e( 'Effacer', 'kadence-child' ); ?>">×</button>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ─── AJAX ───────────────────────────────────────────────────── */

	public function ajax_search() {
		// Endpoint public en lecture seule : pas de nonce (aucun enjeu CSRF, et
		// un nonce provoquerait un 403 sur un onglet ancien). Cf. recherche globale.

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;

		if ( mb_strlen( trim( $search ) ) < 2 ) {
			wp_send_json_success( [ 'html' => '', 'has_more' => false, 'count' => 0 ] );
			return;
		}

		$all_ids = pf_search_events_ranked( $search );

		if ( empty( $all_ids ) ) {
			wp_send_json_success( [
				'html'     => '<li class="pf-ev-search-empty"><p class="pf-ev-msg is-visible">Aucun événement ne correspond à votre recherche.</p></li>',
				'has_more' => false,
				'count'    => 0,
			] );
			return;
		}

		$page_ids = array_slice( $all_ids, $offset, self::PAGE_SIZE );

		if ( empty( $page_ids ) ) {
			// Offset au-delà de la fin (requête ré-affinée entre-temps, etc.) : rien à rendre.
			wp_send_json_success( [ 'html' => '', 'has_more' => false, 'count' => 0 ] );
			return;
		}

		// Séparateurs mois/année : première occurrence dans l'ENSEMBLE des résultats
		// (comme la liste normale, cf. render_month_separator()), pas seulement sur
		// cette page — il faut donc connaître les mois déjà rendus sur les pages
		// précédentes. Lecture directe de _EventStartDate en un seul batch plutôt que
		// ré-enrichir via tribe_get_event() des événements déjà rendus (pages passées).
		$seen_months = [];
		if ( $offset > 0 ) {
			global $wpdb;
			$prior_ids = array_slice( $all_ids, 0, $offset );
			$ids_in    = implode( ',', array_map( 'intval', $prior_ids ) );
			$rows      = $wpdb->get_results(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_EventStartDate' AND post_id IN ($ids_in)"
			);
			foreach ( $rows as $r ) {
				$seen_months[ substr( $r->meta_value, 0, 7 ) ] = true;
			}
		}

		$html     = '';
		$rendered = 0;
		foreach ( $page_ids as $id ) {
			$event = tribe_get_event( $id );
			if ( ! $event ) continue;
			$rendered++;

			$month_key = $event->dates->start_display->format( 'Y-m' );
			if ( ! isset( $seen_months[ $month_key ] ) ) {
				$seen_months[ $month_key ] = true;
				$html .= self::render_month_separator( $event );
			}

			$html .= self::render_event_row( $event );
		}

		wp_send_json_success( [
			'html'     => $html,
			'has_more' => ( $offset + count( $page_ids ) ) < count( $all_ids ),
			'count'    => $rendered,
		] );
	}

	/**
	 * Séparateur mois/année — même markup que list/month-separator.php (non
	 * overridé par ce thème), pour que le CSS existant (sticky, full-bleed,
	 * assets/css/events.css) s'applique tel quel. Pas de $is_past/$request_date
	 * (même simplification que render_date_tag() — non pertinents hors fenêtre
	 * de dates de la Vue TEC) : on prend directement la date de début affichée.
	 */
	private static function render_month_separator( $event ) {
		ob_start();
		?>
		<li class="tribe-events-calendar-list__month-separator">
			<h3>
				<time class="tribe-events-calendar-list__month-separator-text tribe-common-h7 tribe-common-h6--min-medium tribe-common-h--alt">
					<?php echo esc_html( $event->dates->start_display->format_i18n( 'F Y' ) ); ?>
				</time>
			</h3>
		</li>
		<?php
		return ob_get_clean();
	}

	/* ─── Rendu d'une ligne — même gabarit visuel que list/event.php ─── */

	private static function render_event_row( $event ) {
		ob_start();
		?>
		<li class="tribe-common-g-row tribe-events-calendar-list__event-row<?php echo ! empty( $event->featured ) ? ' tribe-events-calendar-list__event-row--featured' : ''; ?>">

			<?php echo self::render_date_tag( $event ); ?>

			<div class="tribe-events-calendar-list__event-wrapper tribe-common-g-col pf-card">
				<a class="pf-card-link" href="<?php echo esc_url( $event->permalink ); ?>" aria-label="<?php echo esc_attr( wp_strip_all_tags( $event->title ) ); ?>"></a>
				<article <?php tec_classes( tribe_get_post_class( [ 'tribe-events-calendar-list__event', 'tribe-common-g-row', 'tribe-common-g-row--gutters' ], $event->ID ) ); ?>>

					<?php if ( $event->thumbnail->exists ) : ?>
					<div class="tribe-events-calendar-list__event-featured-image-wrapper tribe-common-g-col">
						<img
							class="tribe-events-calendar-list__event-featured-image"
							src="<?php echo esc_url( $event->thumbnail->full->url ); ?>"
							<?php if ( ! empty( $event->thumbnail->srcset ) ) : ?>srcset="<?php echo esc_attr( $event->thumbnail->srcset ); ?>" sizes="(max-width: 781px) 93vw, 360px"<?php endif; ?>
							alt="<?php echo ! empty( $event->thumbnail->alt ) ? esc_attr( $event->thumbnail->alt ) : ''; ?>"
							<?php if ( ! empty( $event->thumbnail->full->width ) && ! empty( $event->thumbnail->full->height ) ) : ?>
								width="<?php echo esc_attr( $event->thumbnail->full->width ); ?>"
								height="<?php echo esc_attr( $event->thumbnail->full->height ); ?>"
							<?php endif; ?>
						/>
					</div>
					<?php endif; ?>

					<div class="tribe-events-calendar-list__event-details tribe-common-g-col">
						<header class="tribe-events-calendar-list__event-header">
							<h4 class="tribe-events-calendar-list__event-title pf-card-title"><?php echo $event->title; // phpcs:ignore WordPress.Security.EscapeOutput ?></h4>
						</header>

						<?php // Lieu (nom+ville)/organisateur/participants : pf-event-card-meta, pas l'adresse complète native TEC. ?>
						<?php $description = function_exists( 'passiflore_render_event_description_text' ) ? passiflore_render_event_description_text( $event ) : ''; ?>
						<?php if ( '' !== $description ) : ?>
						<div class="tribe-events-calendar-list__event-description pf-card-text pf-card-text--clamp-2">
							<?php echo $description; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</div>
						<?php endif; ?>
						<?php
						if ( function_exists( 'passiflore_render_event_card_meta' ) ) {
							echo passiflore_render_event_card_meta( $event ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						?>
					</div>
				</article>
			</div>
		</li>
		<?php
		return ob_get_clean();
	}

	/**
	 * Date-tag : même calcul que l'override list/event/date-tag.php, sans la
	 * bascule $is_past/$request_date (spécifique à la fenêtre de dates de la
	 * vue TEC — non pertinente pour un ensemble de résultats de recherche).
	 */
	private static function render_date_tag( $event ) {
		$display_date = $event->dates->start_display;
		$end_date     = $event->dates->end_display;

		$start_day_str = $display_date->format( 'Ymd' );
		$end_day_str   = $end_date->format( 'Ymd' );
		$is_multi_day  = $start_day_str !== $end_day_str;
		$show_time     = empty( $event->all_day );

		$event_week_day  = $display_date->format_i18n( 'l' );
		$event_day_num   = pf_date_i18n_ordinal( 'j', $display_date->getTimestamp() ); // déjà échappé (avec <sup> brut pour "1er")
		$event_date_attr = $display_date->format( Dates::DBDATEFORMAT );
		$start_time      = $show_time ? pf_event_format_hm( (int) $display_date->format( 'G' ), (int) $display_date->format( 'i' ) ) : '';

		$end_week_day  = $end_date->format_i18n( 'l' );
		$end_day_num   = pf_date_i18n_ordinal( 'j', $end_date->getTimestamp() ); // déjà échappé (avec <sup> brut pour "1er")
		$end_date_attr = $end_date->format( Dates::DBDATEFORMAT );
		$end_time      = $show_time ? pf_event_format_hm( (int) $end_date->format( 'G' ), (int) $end_date->format( 'i' ) ) : '';

		if ( $end_time !== '' && function_exists( 'pf_event_is_sentinel_time' ) && pf_event_is_sentinel_time( $end_date->format( 'H:i:s' ) ) ) {
			$end_time = '';
		}

		$pf_daily_hours_used = false;
		$pf_first_end        = '';
		$pf_last_start       = '';
		if ( function_exists( 'pf_event_get_daily_hours' ) ) {
			$pf_hours = pf_event_get_daily_hours( (int) $event->ID );
			if ( count( $pf_hours ) >= 2 ) {
				[ $pf_first, $pf_last ] = pf_event_first_last_open_day( $pf_hours );
				// Retourne [debut, fin] separement (jamais combines en une seule chaine) pour que
				// le gabarit reconstruise la structure texte/span-sep/texte identique au natif.
				$pf_day_time_parts = static function ( $h ) {
					if ( ! empty( $h['allday'] ) ) return [ '', '' ];
					$fmt = static function ( $t ) {
						[ $hh, $mm ] = array_map( 'intval', explode( ':', $t ) );
						return pf_event_format_hm( $hh, $mm );
					};
					return [
						! empty( $h['start'] ) ? $fmt( $h['start'] ) : '',
						! empty( $h['end'] ) ? $fmt( $h['end'] ) : '',
					];
				};
				if ( $pf_first && $pf_last ) {
					$is_multi_day    = $pf_first !== $pf_last;
					$show_time       = true;
					$pf_daily_hours_used = true;
					$event_week_day  = ucfirst( date_i18n( 'l', (int) strtotime( $pf_first ) ) );
					$event_day_num   = pf_date_i18n_ordinal( 'j', (int) strtotime( $pf_first ) ); // déjà échappé (avec <sup> brut pour "1er")
					$event_date_attr = date( 'Y-m-d', (int) strtotime( $pf_first ) );
					[ $start_time, $pf_first_end ] = $pf_day_time_parts( $pf_hours[ $pf_first ] );
					$end_week_day    = ucfirst( date_i18n( 'l', (int) strtotime( $pf_last ) ) );
					$end_day_num     = pf_date_i18n_ordinal( 'j', (int) strtotime( $pf_last ) ); // déjà échappé (avec <sup> brut pour "1er")
					$end_date_attr   = date( 'Y-m-d', (int) strtotime( $pf_last ) );
					[ $pf_last_start, $end_time ] = $pf_day_time_parts( $pf_hours[ $pf_last ] );
				}
			}
		}

		ob_start();
		$pf_tag1_secondary = $pf_daily_hours_used ? $pf_first_end : ( ! $is_multi_day ? $end_time : '' );
		?>
		<div <?php tec_classes( tribe_get_post_class( [ 'tribe-events-calendar-list__event-date-tag', 'tribe-common-g-col' ], $event->ID ) ); ?>>
			<time class="tribe-events-calendar-list__event-date-tag-datetime" datetime="<?php echo esc_attr( $event_date_attr ); ?>" aria-hidden="true">
				<span class="tribe-events-calendar-list__event-date-tag-weekday"><?php echo esc_html( $event_week_day ); ?></span>
				<span class="tribe-events-calendar-list__event-date-tag-daynum tribe-common-h5 tribe-common-h4--min-medium"><?php echo $event_day_num; // phpcs:ignore WordPress.Security.EscapeOutput — pré-échappé (pf_date_i18n_ordinal) ?></span>
				<?php if ( $show_time && $start_time !== '' ) : ?>
				<span class="tribe-events-calendar-list__event-date-tag-time">
					<?php echo esc_html( $start_time ); ?>
					<?php if ( $pf_tag1_secondary !== '' ) : ?>
					<span class="tribe-events-calendar-list__event-date-tag-time-end"><span class="tribe-events-calendar-list__event-date-tag-time-sep">–</span> <?php echo esc_html( $pf_tag1_secondary ); ?></span>
					<?php endif; ?>
				</span>
				<?php endif; ?>
			</time>
			<?php if ( $is_multi_day ) : ?>
			<span class="tribe-events-calendar-list__event-date-tag-separator" aria-hidden="true">
				<svg width="10" height="11" viewBox="0 0 10 11" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path d="M5 1L5 10M5 10L1.5 6.5M5 10L8.5 6.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</span>
			<time class="tribe-events-calendar-list__event-date-tag-datetime" datetime="<?php echo esc_attr( $end_date_attr ); ?>" aria-hidden="true">
				<span class="tribe-events-calendar-list__event-date-tag-weekday"><?php echo esc_html( $end_week_day ); ?></span>
				<span class="tribe-events-calendar-list__event-date-tag-daynum tribe-common-h5 tribe-common-h4--min-medium"><?php echo $end_day_num; // phpcs:ignore WordPress.Security.EscapeOutput — pré-échappé (pf_date_i18n_ordinal) ?></span>
				<?php if ( $show_time && $end_time !== '' ) : ?>
				<span class="tribe-events-calendar-list__event-date-tag-time">
					<?php if ( $pf_daily_hours_used && $pf_last_start !== '' ) : ?>
					<?php echo esc_html( $pf_last_start ); ?>
					<span class="tribe-events-calendar-list__event-date-tag-time-end"><span class="tribe-events-calendar-list__event-date-tag-time-sep">–</span> <?php echo esc_html( $end_time ); ?></span>
					<?php else : ?>
					<?php echo esc_html( $end_time ); ?>
					<?php endif; ?>
				</span>
				<?php endif; ?>
			</time>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}

new Passiflore_Events_Search();
