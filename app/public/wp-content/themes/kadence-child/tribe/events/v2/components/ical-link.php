<?php
/**
 * Component: iCal Link (override Passiflore).
 *
 * En vue MOIS, le bloc « S'abonner » est déplacé dans le header sticky
 * (cf. components/header.php). On supprime donc l'instance par défaut rendue en
 * bas de page (month.php) — sauf quand il est rendu explicitement depuis le
 * header, signalé par `pf_in_header`.
 *
 * Pour les autres vues : comportement d'origine inchangé.
 *
 * Override of /wp-content/plugins/the-events-calendar/src/views/v2/components/ical-link.php
 *
 * @var array  $subscribe_links List of links to display with associated data.
 * @var object $ical            Object containing iCal data (legacy).
 * @var bool   $pf_in_header     Passiflore : true quand rendu depuis le header (vue mois).
 */

use Tribe\Events\Views\V2\iCalendar\iCalendar_Handler;
use Tribe\Events\Views\V2\iCalendar\Links\Link_Abstract;

// Vues d'archive (liste + mois) : le bloc « S'abonner » est rendu une seule fois,
// à l'emplacement du header (1er appel, depuis components/header.php qui réinitialise
// le drapeau). On supprime les rendus suivants — notamment l'appel par défaut en bas
// de page (list.php / month.php). Drapeau global plutôt qu'une variable de template
// (TEC fusionne les variables de template globalement → elles « fuiteraient »).
if ( in_array( $this->get_view_slug(), [ 'list', 'month' ], true ) ) {
	if ( ! empty( $GLOBALS['pf_events_ical_rendered'] ) ) {
		return;
	}
	$GLOBALS['pf_events_ical_rendered'] = true;
}

/* @var Tribe\Events\Views\V2\iCalendar\iCalendar_Handler $handler */
$handler = tribe( iCalendar_Handler::class );

if ( $handler->use_subscribe_links() && empty( $subscribe_links ) ) {
	return;
}

if ( ! $handler->use_subscribe_links() && empty( $ical->display_link ) ) {
	return;
}

// Users can turn off the link list via a filter, handle that.
if ( ! $handler->use_subscribe_links() ) {
	$this->template( 'components/subscribe-links/legacy', [ 'ical' => $ical ] );

	return;
}

$view  = $this->get_view();
$count = array_filter(
	$subscribe_links,
	static function( Link_Abstract $link_obj ) use ( $view ) {
		return $link_obj->is_visible( $view );
	}
);

if ( 1 === count( $count ) ) {
	// If we only have one link in the list, show a "button".
	$key = array_keys( $count )[0];
	$this->template( 'components/subscribe-links/single', [ 'item' => $subscribe_links[ $key ] ] );
} else {
	// If we have multiple links in the list, show a "dropdown".
	$this->template( 'components/subscribe-links/list', [ 'items' => $subscribe_links ] );
}
