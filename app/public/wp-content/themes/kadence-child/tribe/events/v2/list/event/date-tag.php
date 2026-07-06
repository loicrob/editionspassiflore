<?php
/**
 * Override: List View - Single Event Date Tag
 * Utilise le format 'l' (jour complet) + date de fin si multi-jours + heures.
 */

use Tribe__Date_Utils as Dates;

$display_date = empty( $is_past ) && ! empty( $request_date )
	? max( $event->dates->start_display, $request_date )
	: $event->dates->start_display;

$end_date = $event->dates->end_display;

$start_day_str   = $display_date->format( 'Ymd' );
$end_day_str     = $end_date->format( 'Ymd' );
$is_multi_day    = $start_day_str !== $end_day_str;
$show_time       = empty( $event->all_day );

$event_week_day  = $display_date->format_i18n( 'l' );
$event_day_num   = $display_date->format_i18n( 'j' );
$event_date_attr = $display_date->format( Dates::DBDATEFORMAT );
$start_time      = $show_time ? $display_date->format_i18n( 'G\hi' ) : '';

$end_week_day    = $end_date->format_i18n( 'l' );
$end_day_num     = $end_date->format_i18n( 'j' );
$end_date_attr   = $end_date->format( Dates::DBDATEFORMAT );
$end_time        = $show_time ? $end_date->format_i18n( 'G\hi' ) : '';

// L'heure de fin sentinelle (23h59 = "pas d'heure de fin", convention import) ne s'affiche pas.
if ( $end_time !== '' && function_exists( 'pf_event_is_sentinel_time' ) && pf_event_is_sentinel_time( $end_date->format( 'H:i:s' ) ) ) {
	$end_time = '';
}

// Planning par jour reel : afficher le creneau complet du 1er et du dernier jour ouvert.
if ( function_exists( 'pf_event_get_daily_hours' ) ) {
	$pf_hours = pf_event_get_daily_hours( (int) $event->ID );
	if ( count( $pf_hours ) >= 2 ) {
		[ $pf_first, $pf_last ] = pf_event_first_last_open_day( $pf_hours );
		$pf_compact = static function ( $h ) {
			if ( ! empty( $h['allday'] ) ) return '';
			$fmt = static function ( $t ) {
				[ $hh, $mm ] = array_map( 'intval', explode( ':', $t ) );
				return $mm ? sprintf( '%dh%02d', $hh, $mm ) : sprintf( '%dh', $hh );
			};
			if ( ! empty( $h['start'] ) && ! empty( $h['end'] ) ) return $fmt( $h['start'] ) . '–' . $fmt( $h['end'] );
			if ( ! empty( $h['start'] ) ) return $fmt( $h['start'] );
			return '';
		};
		if ( $pf_first && $pf_last ) {
			$is_multi_day    = $pf_first !== $pf_last;
			$show_time       = true;
			$event_week_day  = ucfirst( date_i18n( 'l', (int) strtotime( $pf_first ) ) );
			$event_day_num   = date_i18n( 'j', (int) strtotime( $pf_first ) );
			$event_date_attr = date( 'Y-m-d', (int) strtotime( $pf_first ) );
			$start_time      = $pf_compact( $pf_hours[ $pf_first ] );
			$end_week_day    = ucfirst( date_i18n( 'l', (int) strtotime( $pf_last ) ) );
			$end_day_num     = date_i18n( 'j', (int) strtotime( $pf_last ) );
			$end_date_attr   = date( 'Y-m-d', (int) strtotime( $pf_last ) );
			$end_time        = $pf_compact( $pf_hours[ $pf_last ] );
		}
	}
}

$event_classes   = tribe_get_post_class( [ 'tribe-events-calendar-list__event-date-tag', 'tribe-common-g-col' ], $event->ID );

?>
<div <?php tec_classes( $event_classes ); ?> >
	<time class="tribe-events-calendar-list__event-date-tag-datetime" datetime="<?php echo esc_attr( $event_date_attr ); ?>" aria-hidden="true">
		<span class="tribe-events-calendar-list__event-date-tag-weekday">
			<?php echo esc_html( $event_week_day ); ?>
		</span>
		<span class="tribe-events-calendar-list__event-date-tag-daynum tribe-common-h5 tribe-common-h4--min-medium">
			<?php echo esc_html( $event_day_num ); ?>
		</span>
		<?php if ( $show_time && $start_time !== '' ) : ?>
		<span class="tribe-events-calendar-list__event-date-tag-time">
			<?php echo esc_html( $start_time ); ?>
			<?php if ( ! $is_multi_day && $end_time !== '' ) : ?>
			<span class="tribe-events-calendar-list__event-date-tag-time-sep">–</span>
			<?php echo esc_html( $end_time ); ?>
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
		<span class="tribe-events-calendar-list__event-date-tag-weekday">
			<?php echo esc_html( $end_week_day ); ?>
		</span>
		<span class="tribe-events-calendar-list__event-date-tag-daynum tribe-common-h5 tribe-common-h4--min-medium">
			<?php echo esc_html( $end_day_num ); ?>
		</span>
		<?php if ( $show_time && $end_time !== '' ) : ?>
		<span class="tribe-events-calendar-list__event-date-tag-time">
			<?php echo esc_html( $end_time ); ?>
		</span>
		<?php endif; ?>
	</time>
	<?php endif; ?>
</div>
