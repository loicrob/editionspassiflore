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

/**
 * [pf_frais_de_port]
 *
 * Tableau des frais de livraison, construit à la lecture des réglages WooCommerce :
 * zones d'expédition et leurs méthodes actives, seuil de tarif réduit des méthodes
 * « Forfait » (champs `pf_seuil` / `pf_cout_reduit`, cf. inc/shipping.php) et retrait
 * sur place. Les CGV devant citer ces montants, on les lit à la source plutôt que de
 * les recopier : changer un tarif dans WooCommerce met la page à jour, sans réédition
 * — et surtout sans risque qu'un tarif affiché diverge du tarif réellement facturé.
 *
 * Le retrait sur place est lu à part : ce n'est pas une méthode de zone mais un réglage
 * global du panier en blocs (option `woocommerce_pickup_location_settings`).
 *
 * Rendu : un tableau au markup d'un bloc « Tableau » de l'éditeur, donc stylé comme lui.
 * Deux colonnes de tarifs quand toutes les méthodes à seuil partagent le même seuil
 * (le cas courant) ; sinon une seule colonne, la condition passant dans la cellule.
 */
/**
 * [pf_maj] — date de dernière mise à jour de la page courante.
 *
 * `post_modified` seul ne suffit pas sur une page dont une partie du contenu est
 * générée : les CGV affichent les frais de livraison via [pf_frais_de_port], donc
 * leur texte réel change quand un tarif change, sans que la page soit rééditée. On
 * rend le plus récent des deux événements — mais on ne tient compte du second QUE si
 * la page embarque effectivement le tableau, sans quoi un changement de tarif
 * daterait aussi des pages qui n'en parlent pas (CGU, politique de confidentialité).
 * Le lien se fait donc par le contenu, pas par un identifiant de page en dur.
 *
 * Format « 29 juillet 2026 à 14h18 » : l'heure importe parce que deux versions
 * peuvent se succéder dans la même journée (un tarif corrigé dans la foulée).
 */
add_shortcode( 'pf_maj', function () {
	$post = get_post();
	if ( ! $post ) return '';

	$ts = (int) get_post_timestamp( $post, 'modified' );
	if ( has_shortcode( $post->post_content, 'pf_frais_de_port' ) ) {
		$ts = max( $ts, (int) get_option( 'pf_frais_de_port_maj', 0 ) );
	}

	// `G\hi` et non `H\hi` : « 9h05 » plutôt que « 09h05 ». Le `h` est échappé,
	// il vaut sinon « heure sur 12 » dans les formats de date PHP.
	return $ts ? wp_date( 'j F Y', $ts ) . ' à ' . wp_date( 'G\hi', $ts ) : '';
} );

/**
 * Horodate toute modification d'un réglage d'expédition, pour [pf_cgv_maj].
 *
 * Ces réglages vivent à deux endroits : une option par instance de méthode (le tarif,
 * le seuil, le retrait sur place) et les tables de zones (ajout, suppression, activation
 * d'une méthode, renommage d'une zone) — d'où deux jeux de crochets.
 *
 * `update_option()` ne déclenche `updated_option` que si la valeur a réellement changé :
 * réenregistrer une popup sans rien toucher ne fait pas bouger la date.
 */
function pf_stamp_frais_de_port() {
	update_option( 'pf_frais_de_port_maj', time(), false );
}

function pf_maybe_stamp_frais_de_port( $option ) {
	if ( 'woocommerce_pickup_location_settings' === $option
		|| preg_match( '/^woocommerce_(flat_rate|free_shipping|local_pickup)_\d+_settings$/', $option ) ) {
		pf_stamp_frais_de_port();
	}
}
add_action( 'updated_option', 'pf_maybe_stamp_frais_de_port' );
add_action( 'added_option', 'pf_maybe_stamp_frais_de_port' );

foreach ( [
	'woocommerce_shipping_zone_method_added',
	'woocommerce_shipping_zone_method_deleted',
	'woocommerce_shipping_zone_method_status_toggled',
	'woocommerce_after_shipping_zone_object_save',
] as $pf_shipping_hook ) {
	add_action( $pf_shipping_hook, 'pf_stamp_frais_de_port' );
}
unset( $pf_shipping_hook );

add_shortcode( 'pf_frais_de_port', function () {
	if ( ! class_exists( 'WC_Shipping_Zones' ) ) return '';

	/** Un coût WooCommerce tel qu'affiché au client. Vide ou nul = gratuit. */
	$prix = function ( $cost ) {
		$cost = trim( (string) $cost );
		if ( '' === $cost ) return 'Gratuit';
		// Le champ « Coût » accepte des formules (ex. « 10 + 2 * [qty] ») : on ne
		// tente pas de les évaluer, on les rend telles quelles plutôt que de mentir.
		if ( ! is_numeric( $cost ) ) return esc_html( $cost );
		return ( 0.0 === (float) $cost ) ? 'Gratuit' : wc_price( (float) $cost );
	};

	$rows = [];

	/**
	 * Boxtal Connect (méthode `boxtal_connect`, utilisée pour le point relais) ne pose
	 * pas d'option `cost` : ses tarifs vivent dans sa propre table (`bw_pricing_items`),
	 * en paliers prix/poids réglés depuis son propre écran de zone d'expédition. Sans
	 * ce helper, `$method->get_option( 'cost' )` renvoie toujours vide → affiché
	 * « Gratuit » quel que soit le tarif réellement configuré côté Boxtal.
	 *
	 * On ne reprend que les paliers déclenchés par un seuil de PRIX (poids ignoré : les
	 * CGV ne détaillent pas par poids, comme le reste de ce tableau) pour rester dans le
	 * même modèle « tarif de base + un seuil réduit » que pf_seuil/pf_cout_reduit ci-
	 * dessous — un éventuel 3e palier de prix ne serait pas repris ici.
	 */
	$boxtal_tiers = function ( $method ) {
		if ( ! class_exists( '\Boxtal\BoxtalConnectWoocommerce\Shipping_Method\Controller' ) ) return [];

		$items = \Boxtal\BoxtalConnectWoocommerce\Shipping_Method\Controller::get_pricing_items(
			$method->id . ':' . $method->instance_id
		);

		$tiers = []; // clé = seuil en chaîne (évite la troncature des clés float en int)
		foreach ( $items as $item ) {
			if ( ! in_array( $item['pricing'], [ 'rate', 'free' ], true ) ) continue; // palier désactivé

			$from = ( null !== $item['price_from'] ) ? (float) $item['price_from'] : 0.0;
			if ( isset( $tiers[ (string) $from ] ) ) continue; // 1er palier rencontré à ce seuil

			$tiers[ (string) $from ] = [ $from, ( 'free' === $item['pricing'] ) ? '' : (string) $item['flat_rate'] ];
		}

		ksort( $tiers, SORT_NUMERIC );
		return array_values( $tiers );
	};

	$collect = function ( $methods, $prefix ) use ( &$rows, $boxtal_tiers ) {
		foreach ( $methods as $method ) {
			if ( ! $method->is_enabled() ) continue;

			$label = $prefix ? $prefix . ' — ' . $method->get_title() : $method->get_title();

			if ( 'boxtal_connect' === $method->id ) {
				$tiers  = $boxtal_tiers( $method );
				$seuil  = $tiers[1][0] ?? 0.0;

				$rows[] = [
					'label'  => $label,
					'cost'   => $tiers[0][1] ?? '',
					'seuil'  => $seuil,
					'reduit' => $seuil > 0 ? ( $tiers[1][1] ?? null ) : null,
				];
				continue;
			}

			$seuil = ( 'flat_rate' === $method->id )
				? (float) str_replace( ',', '.', (string) $method->get_option( 'pf_seuil' ) )
				: 0.0;

			$rows[] = [
				'label'  => $label,
				'cost'   => ( 'free_shipping' === $method->id ) ? '' : $method->get_option( 'cost' ),
				'seuil'  => $seuil,
				'reduit' => $seuil > 0 ? $method->get_option( 'pf_cout_reduit' ) : null,
			];
		}
	};

	foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
		$collect( $zone['shipping_methods'], $zone['zone_name'] );
	}

	// Retrait sur place (réglage global, hors zones).
	$pickup = (array) get_option( 'woocommerce_pickup_location_settings', [] );
	if ( 'yes' === ( $pickup['enabled'] ?? 'no' ) ) {
		$rows[] = [
			'label'  => $pickup['title'] ?: 'Retrait sur place',
			'cost'   => $pickup['cost'] ?? '',
			'seuil'  => 0.0,
			'reduit' => null,
		];
	}

	// Zone « reste du monde » en dernier : c'est le repli des autres zones.
	$collect( ( new WC_Shipping_Zone( 0 ) )->get_shipping_methods( true ), 'Autres pays' );

	if ( ! $rows ) return '';

	$seuils = array_unique( array_filter( wp_list_pluck( $rows, 'seuil' ) ) );
	$seuil  = ( 1 === count( $seuils ) ) ? (float) reset( $seuils ) : null;

	$html = '<figure class="wp-block-table"><table><thead><tr><th>Mode de livraison</th>';
	$html .= ( null !== $seuil )
		? '<th>Commande inférieure à ' . wc_price( $seuil ) . '</th><th>À partir de ' . wc_price( $seuil ) . '</th>'
		: '<th>Frais de livraison</th>';
	$html .= '</tr></thead><tbody>';

	foreach ( $rows as $row ) {
		$html .= '<tr><td>' . esc_html( $row['label'] ) . '</td>';

		if ( null !== $seuil ) {
			// Une méthode sans seuil applique le même tarif de part et d'autre.
			$reduit = ( $row['seuil'] > 0 ) ? $row['reduit'] : $row['cost'];
			$html  .= '<td>' . $prix( $row['cost'] ) . '</td><td>' . $prix( $reduit ) . '</td>';
		} else {
			$cell = $prix( $row['cost'] );
			if ( $row['seuil'] > 0 ) {
				$cell .= ', puis ' . $prix( $row['reduit'] ) . ' à partir de ' . wc_price( $row['seuil'] );
			}
			$html .= '<td>' . $cell . '</td>';
		}

		$html .= '</tr>';
	}

	return $html . '</tbody></table></figure>';
} );

/**
 * [pf_footer_legal] — liens légaux, insérés dans `footer_html_content` (même mécanisme
 * que `header_html_content` → [passiflore_account_btn] côté header : un shortcode posé
 * depuis le Customizer, là où on ne peut pas appeler de fonction PHP directement).
 *
 * Résolu par slug, comme les autres liens vers ces pages (cf. checkout-consent.php,
 * newsletter.php) : une page absente ou non publiée (ex. CGV libraires tant qu'elle est
 * en brouillon) disparaît silencieusement de la liste plutôt que de produire un lien mort.
 *
 * Espaces INSÉCABLES dans les libellés à plusieurs mots (même ceinture que « Mon
 * compte » dans header-hooks.php) : la rangée doit pouvoir se replier entre deux
 * liens sans jamais couper un lien lui-même en deux lignes.
 *
 * Séparateur « · » entre les liens d'une même ligne, masqué par JS
 * (assets/js/footer-legal.js) quand il tombe pile sur un retour à la ligne — CSS
 * seul ne sait pas détecter qu'un repli vient de se produire à cet endroit précis.
 * `.pf-footer-legal__sep` est un item flex à part entière (pas du texte inline) :
 * le `gap` du conteneur (style.css) espace alors liens ET séparateurs de façon
 * identique, sans espace codé en dur ici.
 */
add_shortcode( 'pf_footer_legal', function () {
	$pages = [
		'mentions-legales'                        => "Mentions\u{00A0}légales",
		'conditions-generales-de-vente'           => 'CGV',
		'conditions-generales-de-vente-libraires' => "CGV\u{00A0}libraires",
		'conditions-generales-dutilisation'       => 'CGU',
		'politique-de-confidentialite'            => "Politique\u{00A0}de\u{00A0}confidentialité",
		'a-propos-de-ce-site'                     => "À\u{00A0}propos\u{00A0}de\u{00A0}ce\u{00A0}site",
	];

	$links = [];
	foreach ( $pages as $slug => $label ) {
		$page = get_page_by_path( $slug );
		if ( ! $page || 'publish' !== $page->post_status ) continue;
		$links[] = '<a href="' . esc_url( get_permalink( $page ) ) . '">' . esc_html( $label ) . '</a>';
	}

	if ( ! $links ) return '';

	$sep = '<span class="pf-footer-legal__sep" aria-hidden="true">·</span>';
	return '<span class="pf-footer-legal">' . implode( $sep, $links ) . '</span>';
} );
