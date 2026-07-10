<?php
/**
 * Fiche événement — orchestration des sections (nav sticky partagée) et blocs
 * du hero. Le squelette est l'override tribe-events/single-event.php ; les
 * fonctions de rendu réutilisées (horaires, présence, livres) vivent dans
 * inc/events.php, la carte du lieu dans inc/class-events-map.php.
 *
 * @package kadence-child
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Compose les sections de la fiche événement en DEUX zones (layout desktop) :
 *  - **top** (à gauche de l'image collée) : Horaires, Lieu, Organisateur ;
 *  - **bot** (pleine largeur, sous le hero — l'image a repris son scroll) : Présence,
 *    Livres associés ;
 *  - **nav** : barre listant TOUTES les sections (≥3), affichée dans la zone haute.
 * Le template (tribe-events/single-event.php) place `nav` + `top` dans la grille du
 * hero et `bot` en pleine largeur juste après. Chaque section omise si vide.
 *
 * @param int $event_id
 * @return array{nav:string, top:string, bot:string}
 */
function passiflore_get_event_sections_parts( $event_id ) {
	$top = [];
	$bot = [];

	if ( function_exists( 'passiflore_render_event_hours' ) ) {
		$html = passiflore_render_event_hours( $event_id );
		if ( $html ) $top[] = [ 'label' => 'Horaires', 'html' => $html ];
	}

	if ( class_exists( 'Passiflore_Events_Map' ) ) {
		$html = Passiflore_Events_Map::render_single_venue_map( $event_id );
		if ( $html ) $top[] = [ 'label' => 'Lieu', 'html' => $html ];
	}

	$html = passiflore_render_event_organizer( $event_id );
	if ( $html ) $top[] = [ 'label' => 'Organisateur', 'html' => $html ];

	if ( function_exists( 'passiflore_render_event_participants_tiles' ) ) {
		$html = passiflore_render_event_participants_tiles( $event_id );
		if ( $html ) $bot[] = [ 'label' => 'Participants', 'html' => $html ];
	}

	if ( function_exists( 'passiflore_render_event_books' ) ) {
		$html = passiflore_render_event_books( $event_id );
		if ( $html ) $bot[] = [ 'label' => 'Livres associés', 'html' => $html ];
	}

	$all = array_merge( $top, $bot );

	return [
		'nav' => pf_sectionnav_bar( $all ),
		'top' => pf_sectionnav_sections( $top ),
		'bot' => pf_sectionnav_sections( $bot ),
	];
}

/**
 * Section « Organisateur » : nom(s) + coordonnées (site, email, tél) si renseignés.
 *
 * @param int $event_id
 * @return string HTML, ou '' si aucun organisateur nommé.
 */
function passiflore_render_event_organizer( $event_id ) {
	if ( ! function_exists( 'tribe_get_organizer_ids' ) ) return '';
	$ids = tribe_get_organizer_ids( $event_id );
	if ( empty( $ids ) ) return '';

	$blocks = [];
	foreach ( $ids as $oid ) {
		$name = trim( (string) get_the_title( $oid ) );
		if ( '' === $name ) continue;

		$rows = [];
		$site = function_exists( 'tribe_get_organizer_website_url' ) ? tribe_get_organizer_website_url( $oid ) : '';
		if ( $site ) {
			$rows[] = '<a href="' . esc_url( $site ) . '" target="_blank" rel="noopener noreferrer">'
				. esc_html( preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( $site ) ) ) . '</a>';
		}
		$email = function_exists( 'tribe_get_organizer_email' ) ? tribe_get_organizer_email( $oid ) : '';
		if ( $email && is_email( $email ) ) {
			$rows[] = '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
		}
		$phone = function_exists( 'tribe_get_organizer_phone' ) ? tribe_get_organizer_phone( $oid ) : '';
		if ( $phone ) {
			$rows[] = esc_html( $phone );
		}

		$block  = '<div class="pf-event-organizer">';
		$block .= '<p class="pf-event-organizer__name">' . esc_html( $name ) . '</p>';
		if ( $rows ) {
			$block .= '<p class="pf-event-organizer__contact">' . implode( ' · ', $rows ) . '</p>';
		}
		$block .= '</div>';
		$blocks[] = $block;
	}

	if ( empty( $blocks ) ) return '';

	return '<div class="pf-event-organizers">' . implode( '', $blocks ) . '</div>';
}

/**
 * Bloc d'actions du hero : site de l'événement + « Ajouter au calendrier » (.ics).
 * Le flux .ics est enrichi par nos filtres (inc/event-hours.php : un VEVENT par
 * jour + détail des horaires) pour les événements à planning par jour.
 *
 * @param int $event_id
 * @return string HTML, ou '' si rien à afficher.
 */
function passiflore_render_event_hero_meta( $event_id ) {
	$items = [];

	if ( function_exists( 'tribe_get_event_website_url' ) ) {
		$site = tribe_get_event_website_url( $event_id );
		if ( $site ) {
			$items[] = '<a class="button" href="' . esc_url( $site )
				. '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Site de l\'événement', 'kadence-child' ) . '</a>';
		}
	}
	if ( function_exists( 'tribe_get_single_ical_link' ) ) {
		$ics = tribe_get_single_ical_link( $event_id );
		if ( $ics ) {
			$items[] = '<a class="button" href="' . esc_url( $ics )
				. '">' . esc_html__( 'Ajouter au calendrier', 'kadence-child' ) . '</a>';
		}
	}

	if ( empty( $items ) ) return '';

	return '<div class="pf-event-hero__actions">' . implode( '', $items ) . '</div>';
}
