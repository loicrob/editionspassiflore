<?php
/**
 * Shortcodes utilitaires génériques, réutilisables depuis l'éditeur (contenu de page,
 * widget "Bloc HTML"…) là où on ne peut pas appeler une fonction PHP directement.
 *
 * @package kadence-child
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * [pf_email address="contact@exemple.fr" text="Optionnel" class="optionnel"]
 *
 * Lien mailto avec adresse obfusquée (antispambot(), comme TEC le fait nativement pour
 * les organisateurs d'événement) — évite d'exposer un email en clair, scrapable, dans le
 * HTML d'une page ou d'un widget édités « à la main » (pas de fonction PHP appelable
 * depuis ces écrans, d'où ce shortcode).
 *
 * - `address` (obligatoire) : l'email réel, en clair dans l'éditeur — jamais émis tel
 *   quel dans le HTML rendu.
 * - `text` (optionnel) : libellé affiché si différent de l'adresse (ex. "Nous écrire").
 *   Non obfusqué : ce n'est pas l'email, l'obfuscation n'aurait pas de sens dessus.
 * - `class` (optionnel) : classe(s) CSS à poser sur le <a>.
 */
add_shortcode( 'pf_email', function ( $atts ) {
	$atts = shortcode_atts( [
		'address' => '',
		'text'    => '',
		'class'   => '',
	], $atts, 'pf_email' );

	$address = trim( (string) $atts['address'] );
	if ( '' === $address || ! is_email( $address ) ) return '';

	$obf_address = antispambot( $address );
	$text        = trim( (string) $atts['text'] );
	$display     = ( '' !== $text ) ? esc_html( $text ) : esc_html( $obf_address );
	$class       = $atts['class'] ? ' class="' . esc_attr( $atts['class'] ) . '"' : '';

	return '<a' . $class . ' href="' . esc_url( 'mailto:' . $obf_address ) . '">' . $display . '</a>';
} );
