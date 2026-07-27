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
				. esc_html( preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( $site ) ) ) . pf_new_window_note() . '</a>';
		}
		// Email brut pour la validation is_email() (antispambot() remplace le @ par une
		// entité HTML, is_email() ne le reconnaîtrait plus) ; obfusqué pour l'affichage —
		// même protection que TEC applique nativement (tribe_get_organizer_email(), qui
		// antispambot() par défaut). esc_url()/esc_html() sont "entity aware" (n'encodent
		// pas deux fois les entités déjà valides) : l'obfuscation survit intacte.
		$email_raw = function_exists( 'tribe_get_organizer_email' ) ? tribe_get_organizer_email( $oid, false ) : '';
		if ( $email_raw && is_email( $email_raw ) ) {
			$email_obfuscated = antispambot( $email_raw );
			$rows[] = '<a href="' . esc_url( 'mailto:' . $email_obfuscated ) . '">' . esc_html( $email_obfuscated ) . '</a>';
		}
		$phone = function_exists( 'tribe_get_organizer_phone' ) ? tribe_get_organizer_phone( $oid ) : '';
		if ( $phone ) {
			$tel_href = 'tel:' . preg_replace( '/[^0-9+]/', '', $phone );
			$rows[] = '<a href="' . esc_attr( $tel_href ) . '">' . esc_html( $phone ) . '</a>';
		}

		// Pas de sous-conteneurs dédiés (nom / contact) : tout directement dans
		// .pf-event-organizer, une ligne par info (site / mail / tél) via <br>.
		$block  = '<div class="pf-event-organizer">';
		$block .= '<strong>' . esc_html( $name ) . '</strong>';
		if ( $rows ) {
			$block .= '<br>' . implode( '<br>', $rows );
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
				. '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Site de l\'événement', 'kadence-child' ) . pf_new_window_note() . '</a>';
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
