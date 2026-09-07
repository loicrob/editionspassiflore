<?php
/**
 * Champs Département / Région sur la fiche lieu (tribe_venue).
 *
 * Ajoute deux champs à choix contraint (vide, ou exactement une valeur de la
 * liste officielle des départements/régions françaises) au formulaire
 * « Informations du lieu » de The Events Calendar. Alimentent la recherche
 * globale (inc/search.php, pf_search_index_event()) pour qu'un département,
 * une région ou une adresse de lieu remontent l'événement associé.
 *
 * Sauvegarde : on s'appuie sur le mécanisme générique natif de TEC
 * (Tribe__Events__Venue::save_meta() enregistre automatiquement tout champ
 * venue[X] soumis en post meta _VenueX) — nommer les champs venue[Departement]
 * et venue[Region] suffit à les faire persister en _VenueDepartement /
 * _VenueRegion sans code de sauvegarde dédié. Une passe de validation après
 * coup (priorité 20, après le save natif de TEC à 16 sur save_post_tribe_venue)
 * réduit toute valeur hors liste à ''.
 *
 * Champ natif « State or Province » : masqué du formulaire (CSS, cf.
 * pf_enqueue_venue_admin_assets) et synchronisé sur Département à la même
 * passe de validation, pour que les usages natifs déjà branchés dessus —
 * bloc adresse public de la fiche événement (tribe_get_full_address()) et
 * schema.org JSON-LD (addressRegion, Tribe__Events__JSON_LD__Venue) —
 * continuent de fonctionner avec la valeur validée au lieu du texte libre
 * d'origine.
 *
 * Ce fichier porte aussi le champ « Position sur la carte » : statut de
 * géocodage affiché à la frappe (aperçu AJAX, Passiflore_Events_Map::
 * ajax_geocode_preview()) + carte Leaflet avec repère déplaçable. Déplacer le
 * repère fixe des coordonnées manuelles (venue[GeoLat/GeoLng/GeoManual],
 * Passiflore_Events_Map::set_manual_coords()/clear_manual_coords()) : le
 * géocodage automatique (class-events-map.php, geocode_on_save() @25, après
 * les deux passes de sauvegarde ci-dessous) ne les retouche plus tant que ce
 * drapeau tient. Sans déplacement, les coordonnées affichées par l'aperçu
 * sont elles-mêmes persistées si l'adresse postée (venue[GeoKey]) correspond
 * encore aux metas enregistrées (pf_venue_geo_key(), Passiflore_Events_Map::
 * set_geocoded_coords()) — le géocodage automatique ne sert plus alors que de
 * repli (sans-JS, Quick Edit, clé périmée).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ═══════════════════════════════════════════════════════════════
   Listes officielles (101 départements, 18 régions depuis 2016)
   ═══════════════════════════════════════════════════════════════ */

const PF_VENUE_DEPARTEMENTS = [
	[ 'code' => '01',  'name' => 'Ain' ],
	[ 'code' => '02',  'name' => 'Aisne' ],
	[ 'code' => '03',  'name' => 'Allier' ],
	[ 'code' => '04',  'name' => 'Alpes-de-Haute-Provence' ],
	[ 'code' => '06',  'name' => 'Alpes-Maritimes' ],
	[ 'code' => '07',  'name' => 'Ardèche' ],
	[ 'code' => '08',  'name' => 'Ardennes' ],
	[ 'code' => '09',  'name' => 'Ariège' ],
	[ 'code' => '10',  'name' => 'Aube' ],
	[ 'code' => '11',  'name' => 'Aude' ],
	[ 'code' => '12',  'name' => 'Aveyron' ],
	[ 'code' => '67',  'name' => 'Bas-Rhin' ],
	[ 'code' => '13',  'name' => 'Bouches-du-Rhône' ],
	[ 'code' => '14',  'name' => 'Calvados' ],
	[ 'code' => '15',  'name' => 'Cantal' ],
	[ 'code' => '16',  'name' => 'Charente' ],
	[ 'code' => '17',  'name' => 'Charente-Maritime' ],
	[ 'code' => '18',  'name' => 'Cher' ],
	[ 'code' => '19',  'name' => 'Corrèze' ],
	[ 'code' => '2A',  'name' => 'Corse-du-Sud' ],
	[ 'code' => '21',  'name' => 'Côte-d\'Or' ],
	[ 'code' => '22',  'name' => 'Côtes-d\'Armor' ],
	[ 'code' => '23',  'name' => 'Creuse' ],
	[ 'code' => '79',  'name' => 'Deux-Sèvres' ],
	[ 'code' => '24',  'name' => 'Dordogne' ],
	[ 'code' => '25',  'name' => 'Doubs' ],
	[ 'code' => '26',  'name' => 'Drôme' ],
	[ 'code' => '91',  'name' => 'Essonne' ],
	[ 'code' => '27',  'name' => 'Eure' ],
	[ 'code' => '28',  'name' => 'Eure-et-Loir' ],
	[ 'code' => '29',  'name' => 'Finistère' ],
	[ 'code' => '30',  'name' => 'Gard' ],
	[ 'code' => '32',  'name' => 'Gers' ],
	[ 'code' => '33',  'name' => 'Gironde' ],
	[ 'code' => '971', 'name' => 'Guadeloupe' ],
	[ 'code' => '973', 'name' => 'Guyane' ],
	[ 'code' => '68',  'name' => 'Haut-Rhin' ],
	[ 'code' => '2B',  'name' => 'Haute-Corse' ],
	[ 'code' => '31',  'name' => 'Haute-Garonne' ],
	[ 'code' => '43',  'name' => 'Haute-Loire' ],
	[ 'code' => '52',  'name' => 'Haute-Marne' ],
	[ 'code' => '70',  'name' => 'Haute-Saône' ],
	[ 'code' => '74',  'name' => 'Haute-Savoie' ],
	[ 'code' => '87',  'name' => 'Haute-Vienne' ],
	[ 'code' => '05',  'name' => 'Hautes-Alpes' ],
	[ 'code' => '65',  'name' => 'Hautes-Pyrénées' ],
	[ 'code' => '92',  'name' => 'Hauts-de-Seine' ],
	[ 'code' => '34',  'name' => 'Hérault' ],
	[ 'code' => '35',  'name' => 'Ille-et-Vilaine' ],
	[ 'code' => '36',  'name' => 'Indre' ],
	[ 'code' => '37',  'name' => 'Indre-et-Loire' ],
	[ 'code' => '38',  'name' => 'Isère' ],
	[ 'code' => '39',  'name' => 'Jura' ],
	[ 'code' => '974', 'name' => 'La Réunion' ],
	[ 'code' => '40',  'name' => 'Landes' ],
	[ 'code' => '41',  'name' => 'Loir-et-Cher' ],
	[ 'code' => '42',  'name' => 'Loire' ],
	[ 'code' => '44',  'name' => 'Loire-Atlantique' ],
	[ 'code' => '45',  'name' => 'Loiret' ],
	[ 'code' => '46',  'name' => 'Lot' ],
	[ 'code' => '47',  'name' => 'Lot-et-Garonne' ],
	[ 'code' => '48',  'name' => 'Lozère' ],
	[ 'code' => '49',  'name' => 'Maine-et-Loire' ],
	[ 'code' => '50',  'name' => 'Manche' ],
	[ 'code' => '51',  'name' => 'Marne' ],
	[ 'code' => '972', 'name' => 'Martinique' ],
	[ 'code' => '53',  'name' => 'Mayenne' ],
	[ 'code' => '976', 'name' => 'Mayotte' ],
	[ 'code' => '54',  'name' => 'Meurthe-et-Moselle' ],
	[ 'code' => '55',  'name' => 'Meuse' ],
	[ 'code' => '56',  'name' => 'Morbihan' ],
	[ 'code' => '57',  'name' => 'Moselle' ],
	[ 'code' => '58',  'name' => 'Nièvre' ],
	[ 'code' => '59',  'name' => 'Nord' ],
	[ 'code' => '60',  'name' => 'Oise' ],
	[ 'code' => '61',  'name' => 'Orne' ],
	[ 'code' => '75',  'name' => 'Paris' ],
	[ 'code' => '62',  'name' => 'Pas-de-Calais' ],
	[ 'code' => '63',  'name' => 'Puy-de-Dôme' ],
	[ 'code' => '64',  'name' => 'Pyrénées-Atlantiques' ],
	[ 'code' => '66',  'name' => 'Pyrénées-Orientales' ],
	[ 'code' => '69',  'name' => 'Rhône' ],
	[ 'code' => '71',  'name' => 'Saône-et-Loire' ],
	[ 'code' => '72',  'name' => 'Sarthe' ],
	[ 'code' => '73',  'name' => 'Savoie' ],
	[ 'code' => '77',  'name' => 'Seine-et-Marne' ],
	[ 'code' => '76',  'name' => 'Seine-Maritime' ],
	[ 'code' => '93',  'name' => 'Seine-Saint-Denis' ],
	[ 'code' => '80',  'name' => 'Somme' ],
	[ 'code' => '81',  'name' => 'Tarn' ],
	[ 'code' => '82',  'name' => 'Tarn-et-Garonne' ],
	[ 'code' => '90',  'name' => 'Territoire de Belfort' ],
	[ 'code' => '94',  'name' => 'Val-de-Marne' ],
	[ 'code' => '95',  'name' => 'Val-d\'Oise' ],
	[ 'code' => '83',  'name' => 'Var' ],
	[ 'code' => '84',  'name' => 'Vaucluse' ],
	[ 'code' => '85',  'name' => 'Vendée' ],
	[ 'code' => '86',  'name' => 'Vienne' ],
	[ 'code' => '88',  'name' => 'Vosges' ],
	[ 'code' => '89',  'name' => 'Yonne' ],
	[ 'code' => '78',  'name' => 'Yvelines' ],
];

/**
 * Code du departement (ex. "40") a partir du nom stocke en _VenueDepartement
 * (ex. "Landes"). Utilise pour l'affichage "Ville (40)" des lieux d'evenement.
 */
function pf_venue_departement_code( string $name ): string {
	foreach ( PF_VENUE_DEPARTEMENTS as $d ) {
		if ( $d['name'] === $name ) return $d['code'];
	}
	return '';
}

/**
 * Miroir de pf_venue_departement_code() : nom du département (ex. "Landes")
 * à partir de son code (ex. "40"). Utilisé pour résoudre le département
 * depuis l'ISO3166-2-lvl6 de Nominatim (cf. pf_venue_admin_fields_from_geocode()).
 */
function pf_venue_departement_name( string $code ): string {
	foreach ( PF_VENUE_DEPARTEMENTS as $d ) {
		if ( $d['code'] === $code ) return $d['name'];
	}
	return '';
}

const PF_VENUE_REGIONS = [
	'Auvergne-Rhône-Alpes',
	'Bourgogne-Franche-Comté',
	'Bretagne',
	'Centre-Val de Loire',
	'Corse',
	'Grand Est',
	'Guadeloupe',
	'Guyane',
	'Hauts-de-France',
	'Île-de-France',
	'La Réunion',
	'Martinique',
	'Mayotte',
	'Normandie',
	'Nouvelle-Aquitaine',
	'Occitanie',
	'Pays de la Loire',
	'Provence-Alpes-Côte d\'Azur',
];

/**
 * Relation département → région (réforme territoriale 2016), exacte à 100% —
 * chaque département français appartient à une seule région. Sert à
 * synchroniser Région dès qu'un Département valide est retenu (sélection
 * directe ou déduit du code postal, cf. pf_enqueue_venue_admin_assets).
 */
const PF_VENUE_DEPARTEMENT_TO_REGION = [
	'Ain' => 'Auvergne-Rhône-Alpes',
	'Allier' => 'Auvergne-Rhône-Alpes',
	'Ardèche' => 'Auvergne-Rhône-Alpes',
	'Cantal' => 'Auvergne-Rhône-Alpes',
	'Drôme' => 'Auvergne-Rhône-Alpes',
	'Isère' => 'Auvergne-Rhône-Alpes',
	'Loire' => 'Auvergne-Rhône-Alpes',
	'Haute-Loire' => 'Auvergne-Rhône-Alpes',
	'Puy-de-Dôme' => 'Auvergne-Rhône-Alpes',
	'Rhône' => 'Auvergne-Rhône-Alpes',
	'Savoie' => 'Auvergne-Rhône-Alpes',
	'Haute-Savoie' => 'Auvergne-Rhône-Alpes',

	'Côte-d\'Or' => 'Bourgogne-Franche-Comté',
	'Doubs' => 'Bourgogne-Franche-Comté',
	'Jura' => 'Bourgogne-Franche-Comté',
	'Nièvre' => 'Bourgogne-Franche-Comté',
	'Haute-Saône' => 'Bourgogne-Franche-Comté',
	'Saône-et-Loire' => 'Bourgogne-Franche-Comté',
	'Yonne' => 'Bourgogne-Franche-Comté',
	'Territoire de Belfort' => 'Bourgogne-Franche-Comté',

	'Côtes-d\'Armor' => 'Bretagne',
	'Finistère' => 'Bretagne',
	'Ille-et-Vilaine' => 'Bretagne',
	'Morbihan' => 'Bretagne',

	'Cher' => 'Centre-Val de Loire',
	'Eure-et-Loir' => 'Centre-Val de Loire',
	'Indre' => 'Centre-Val de Loire',
	'Indre-et-Loire' => 'Centre-Val de Loire',
	'Loir-et-Cher' => 'Centre-Val de Loire',
	'Loiret' => 'Centre-Val de Loire',

	'Corse-du-Sud' => 'Corse',
	'Haute-Corse' => 'Corse',

	'Ardennes' => 'Grand Est',
	'Aube' => 'Grand Est',
	'Marne' => 'Grand Est',
	'Haute-Marne' => 'Grand Est',
	'Meurthe-et-Moselle' => 'Grand Est',
	'Meuse' => 'Grand Est',
	'Moselle' => 'Grand Est',
	'Bas-Rhin' => 'Grand Est',
	'Haut-Rhin' => 'Grand Est',
	'Vosges' => 'Grand Est',

	'Aisne' => 'Hauts-de-France',
	'Nord' => 'Hauts-de-France',
	'Oise' => 'Hauts-de-France',
	'Pas-de-Calais' => 'Hauts-de-France',
	'Somme' => 'Hauts-de-France',

	'Paris' => 'Île-de-France',
	'Seine-et-Marne' => 'Île-de-France',
	'Yvelines' => 'Île-de-France',
	'Essonne' => 'Île-de-France',
	'Hauts-de-Seine' => 'Île-de-France',
	'Seine-Saint-Denis' => 'Île-de-France',
	'Val-de-Marne' => 'Île-de-France',
	'Val-d\'Oise' => 'Île-de-France',

	'Calvados' => 'Normandie',
	'Eure' => 'Normandie',
	'Manche' => 'Normandie',
	'Orne' => 'Normandie',
	'Seine-Maritime' => 'Normandie',

	'Charente' => 'Nouvelle-Aquitaine',
	'Charente-Maritime' => 'Nouvelle-Aquitaine',
	'Corrèze' => 'Nouvelle-Aquitaine',
	'Creuse' => 'Nouvelle-Aquitaine',
	'Dordogne' => 'Nouvelle-Aquitaine',
	'Gironde' => 'Nouvelle-Aquitaine',
	'Landes' => 'Nouvelle-Aquitaine',
	'Lot-et-Garonne' => 'Nouvelle-Aquitaine',
	'Pyrénées-Atlantiques' => 'Nouvelle-Aquitaine',
	'Deux-Sèvres' => 'Nouvelle-Aquitaine',
	'Vienne' => 'Nouvelle-Aquitaine',
	'Haute-Vienne' => 'Nouvelle-Aquitaine',

	'Ariège' => 'Occitanie',
	'Aude' => 'Occitanie',
	'Aveyron' => 'Occitanie',
	'Gard' => 'Occitanie',
	'Haute-Garonne' => 'Occitanie',
	'Gers' => 'Occitanie',
	'Hérault' => 'Occitanie',
	'Lot' => 'Occitanie',
	'Lozère' => 'Occitanie',
	'Hautes-Pyrénées' => 'Occitanie',
	'Pyrénées-Orientales' => 'Occitanie',
	'Tarn' => 'Occitanie',
	'Tarn-et-Garonne' => 'Occitanie',

	'Loire-Atlantique' => 'Pays de la Loire',
	'Maine-et-Loire' => 'Pays de la Loire',
	'Mayenne' => 'Pays de la Loire',
	'Sarthe' => 'Pays de la Loire',
	'Vendée' => 'Pays de la Loire',

	'Alpes-de-Haute-Provence' => 'Provence-Alpes-Côte d\'Azur',
	'Hautes-Alpes' => 'Provence-Alpes-Côte d\'Azur',
	'Alpes-Maritimes' => 'Provence-Alpes-Côte d\'Azur',
	'Bouches-du-Rhône' => 'Provence-Alpes-Côte d\'Azur',
	'Var' => 'Provence-Alpes-Côte d\'Azur',
	'Vaucluse' => 'Provence-Alpes-Côte d\'Azur',

	'Guadeloupe' => 'Guadeloupe',
	'Martinique' => 'Martinique',
	'Guyane' => 'Guyane',
	'La Réunion' => 'La Réunion',
	'Mayotte' => 'Mayotte',
];

/* ═══════════════════════════════════════════════════════════════
   Rendu des champs (formulaire « Informations du lieu » de TEC)
   ═══════════════════════════════════════════════════════════════ */

add_action( 'tribe_events_after_venue_metabox', 'pf_render_venue_departement_region_fields' );

function pf_render_venue_departement_region_fields( $post ) {
	$departement = $region = '';
	if ( $post && $post->post_type === 'tribe_venue' ) {
		$departement = (string) get_post_meta( $post->ID, '_VenueDepartement', true );
		$region      = (string) get_post_meta( $post->ID, '_VenueRegion', true );
	}
	?>
	<tr class="venue tribe-linked-type-venue-departement">
		<td class="tribe-table-field-label">
			<label for="venueDepartement"><?php esc_html_e( 'Département :', 'kadence-child' ); ?></label>
		</td>
		<td>
			<div class="pf-venue-combo" data-field="departements">
				<input
					type="text"
					id="venueDepartement"
					name="venue[Departement]"
					size="25"
					autocomplete="off"
					value="<?php echo esc_attr( $departement ); ?>"
				/>
				<ul class="pf-venue-combo-menu"></ul>
			</div>
		</td>
	</tr>
	<tr class="venue tribe-linked-type-venue-region">
		<td class="tribe-table-field-label">
			<label for="venueRegion"><?php esc_html_e( 'Région :', 'kadence-child' ); ?></label>
		</td>
		<td>
			<div class="pf-venue-combo" data-field="regions">
				<input
					type="text"
					id="venueRegion"
					name="venue[Region]"
					size="25"
					autocomplete="off"
					value="<?php echo esc_attr( $region ); ?>"
				/>
				<ul class="pf-venue-combo-menu"></ul>
			</div>
		</td>
	</tr>
	<?php
}

/**
 * Champ « Position sur la carte » — fiche lieu autonome.
 */
add_action( 'tribe_events_after_venue_metabox', 'pf_render_venue_geo_map_field' );

function pf_render_venue_geo_map_field( $post ) {
	$lat = $lng = $precision = $key = '';
	if ( $post && $post->post_type === 'tribe_venue' ) {
		$lat       = get_post_meta( $post->ID, Passiflore_Events_Map::GEO_META_LAT, true );
		$lng       = get_post_meta( $post->ID, Passiflore_Events_Map::GEO_META_LNG, true );
		$precision = get_post_meta( $post->ID, Passiflore_Events_Map::GEO_META_PRECISION, true );
		$key       = pf_venue_geo_key( $post->ID );
	}
	?>
	<tr class="venue tribe-linked-type-venue-geo">
		<td class="tribe-table-field-label">
			<label><?php esc_html_e( 'Position sur la carte :', 'kadence-child' ); ?></label>
		</td>
		<td><?php pf_render_venue_geo_map_widget( $lat, $lng, $precision, $key, '' ); ?></td>
	</tr>
	<?php
}

/**
 * Même champ, mini-formulaire « Créer un nouveau lieu » de la fiche événement.
 */
add_action( 'tribe_events_linked_post_new_form', 'pf_render_venue_geo_map_field_inline', 20 );

function pf_render_venue_geo_map_field_inline( $post_type ) {
	if ( 'tribe_venue' !== $post_type ) return;
	?>
	<tr class="linked-post venue tribe-linked-type-venue-geo">
		<td class="tribe-table-field-label">
			<label><?php esc_html_e( 'Position sur la carte :', 'kadence-child' ); ?></label>
		</td>
		<td><?php pf_render_venue_geo_map_widget( '', '', '', '', '[]' ); ?></td>
	</tr>
	<?php
}

/**
 * Markup partagé par les deux rendus ci-dessus. Lat/lng/precision initiales
 * portées en data-attributes sur le conteneur de carte (même convention que
 * #pf-event-venue-map côté public, cf. class-events-map.php) — lues par
 * venue-geo-picker.js, pas dupliquées dans les données localisées globales.
 * $key = pf_venue_geo_key() courante (fraîcheur de l'adresse à l'ouverture du
 * formulaire, cf. pf_save_venue_geo_fields()). $suffix = '' (fiche lieu
 * autonome) ou '[]' (lignes venue[Champ][] clonées de la fiche événement).
 */
function pf_render_venue_geo_map_widget( $lat, $lng, $precision, $key, $suffix ) {
	?>
	<p class="pf-venue-geo-status"></p>
	<div
		class="pf-venue-geo-map"
		data-lat="<?php echo esc_attr( $lat ); ?>"
		data-lng="<?php echo esc_attr( $lng ); ?>"
		data-precision="<?php echo esc_attr( $precision ); ?>"
	></div>
	<button type="button" class="button pf-venue-geo-reset" hidden><?php esc_html_e( 'Revenir au repérage automatique', 'kadence-child' ); ?></button>
	<input type="hidden" class="pf-venue-geo-lat"       name="<?php echo esc_attr( 'venue[GeoLat]' . $suffix ); ?>"       value="<?php echo esc_attr( $lat ); ?>" />
	<input type="hidden" class="pf-venue-geo-lng"       name="<?php echo esc_attr( 'venue[GeoLng]' . $suffix ); ?>"       value="<?php echo esc_attr( $lng ); ?>" />
	<input type="hidden" class="pf-venue-geo-manual"    name="<?php echo esc_attr( 'venue[GeoManual]' . $suffix ); ?>"    value="<?php echo 'manual' === $precision ? '1' : ''; ?>" />
	<input type="hidden" class="pf-venue-geo-key"       name="<?php echo esc_attr( 'venue[GeoKey]' . $suffix ); ?>"       value="<?php echo esc_attr( $key ); ?>" />
	<input type="hidden" class="pf-venue-geo-precision" name="<?php echo esc_attr( 'venue[GeoPrecision]' . $suffix ); ?>" value="<?php echo esc_attr( $precision ); ?>" />
	<?php
}

/**
 * Case « Ce lieu n'a pas de nom » — juste sous le champ Titre natif de
 * l'écran d'édition (celui-ci n'appartient pas au tableau « Informations du
 * lieu » de tribe_events_after_venue_metabox, d'où un hook WP distinct).
 * Coché : le Titre se remplit à la volée avec l'Adresse (JS, venue-admin.js)
 * et reste en lecture seule ; la case elle-même est soumise dans le même
 * tableau venue[...] que les autres champs (name="venue[NameIsAddress]"),
 * donc persistée par la même passe de validation ci-dessous.
 */
add_action( 'edit_form_after_title', 'pf_render_venue_name_is_address_checkbox' );

function pf_render_venue_name_is_address_checkbox( $post ) {
	if ( ! $post || $post->post_type !== 'tribe_venue' ) return;
	$checked = (bool) get_post_meta( $post->ID, '_VenueNameIsAddress', true );
	?>
	<p class="pf-venue-name-is-address">
		<label>
			<input type="checkbox" name="venue[NameIsAddress]" value="1" id="pf-venue-name-is-address" <?php checked( $checked ); ?> />
			<?php esc_html_e( "Ce lieu n'a pas de nom, remplacer par l'adresse.", 'kadence-child' ); ?>
		</label>
	</p>
	<?php
}

/* ═══════════════════════════════════════════════════════════════
   Validation post-sauvegarde
   ═══════════════════════════════════════════════════════════════ */

/**
 * Réduit une valeur Département/Région à '' si elle ne fait pas partie de la
 * liste officielle — factorisé car utilisé à la fois par la sauvegarde de la
 * fiche lieu autonome (pf_validate_venue_departement_region) et par celle du
 * lieu créé en ligne depuis la fiche événement (pf_sync_venue_fields_on_create).
 */
function pf_venue_clamp_departement( $value ) {
	$valid = wp_list_pluck( PF_VENUE_DEPARTEMENTS, 'name' );
	return in_array( $value, $valid, true ) ? $value : '';
}

function pf_venue_clamp_region( $value ) {
	return in_array( $value, PF_VENUE_REGIONS, true ) ? $value : '';
}

/**
 * Verrou de ré-entrance : wp_update_post() dans pf_venue_apply_composed_title()
 * redéclenche save_post_tribe_venue, même motif que pf_fmt_busy()
 * (product-format-admin.php) — sans lui, la recomposition se rappellerait
 * elle-même indéfiniment.
 */
function pf_venue_title_busy( ?bool $set = null ): bool {
	static $busy = false;
	if ( $set !== null ) $busy = $set;
	return $busy;
}

/**
 * Titre composé pour un lieu « nom = adresse » (case cochée, cf.
 * pf_render_venue_name_is_address_checkbox()) : la rue si elle est
 * renseignée, sinon la ville — un lieu réduit à sa seule commune (salle des
 * fêtes, marché, salon du livre) reste ainsi titré au lieu de se retrouver
 * vide. Miroir documenté de wireNameIsAddress() (assets/js/venue-admin.js),
 * qui n'en reste qu'un aperçu live ; cette fonction fait foi côté serveur.
 */
function pf_venue_composed_name( string $street, string $city ): string {
	$street = trim( $street );
	return '' !== $street ? $street : trim( $city );
}

/**
 * Recompose le titre du lieu depuis Adresse/Ville quand « nom = adresse »
 * est actif. Appelée après l'écriture des metas correspondantes, aux deux
 * points de sauvegarde (fiche autonome + création en ligne) — jamais de
 * titre vide (aucune adresse exploitable → on ne touche à rien).
 */
function pf_venue_apply_composed_title( $venue_id ) {
	if ( pf_venue_title_busy() ) return;
	if ( ! get_post_meta( $venue_id, '_VenueNameIsAddress', true ) ) return;

	$name = pf_venue_composed_name(
		(string) get_post_meta( $venue_id, '_VenueAddress', true ),
		(string) get_post_meta( $venue_id, '_VenueCity', true )
	);
	if ( '' === $name ) return;

	$current = get_post( $venue_id );
	if ( ! $current || $current->post_title === $name ) return;

	pf_venue_title_busy( true );
	wp_update_post( [ 'ID' => $venue_id, 'post_title' => $name, 'post_name' => '' ] );
	pf_venue_title_busy( false );
}

add_action( 'save_post_tribe_venue', 'pf_validate_venue_departement_region', 20 );

function pf_validate_venue_departement_region( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( empty( $_POST['venue'] ) || ! is_array( $_POST['venue'] ) ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;

	// Ne traite que la soumission du formulaire propre à CE lieu (mécanisme
	// repris de Tribe__Events__Main::save_venue_data()) : quand un lieu est créé
	// en ligne depuis la fiche événement, $_POST['venue'] existe aussi mais
	// appartient au formulaire de l'ÉVÉNEMENT (post_ID = l'événement, valeurs en
	// tableaux venue[Champ][]) — géré séparément par pf_sync_venue_fields_on_create().
	if ( empty( $_POST['post_ID'] ) || (int) $_POST['post_ID'] !== (int) $post_id ) return;

	$dept = isset( $_POST['venue']['Departement'] ) ? sanitize_text_field( wp_unslash( $_POST['venue']['Departement'] ) ) : '';
	$reg  = isset( $_POST['venue']['Region'] )      ? sanitize_text_field( wp_unslash( $_POST['venue']['Region'] ) )      : '';

	$dept = pf_venue_clamp_departement( $dept );
	$reg  = pf_venue_clamp_region( $reg );

	update_post_meta( $post_id, '_VenueDepartement', $dept );
	update_post_meta( $post_id, '_VenueRegion', $reg );

	// Le champ natif TEC « State or Province » est masqué du formulaire (cf.
	// pf_enqueue_venue_admin_assets) : on le fait pointer sur Département pour
	// que l'affichage public (bloc adresse de la fiche événement) et le
	// schema.org JSON-LD natif (addressRegion, via tribe_get_region() qui lit
	// _VenueStateProvince en priorité) restent alimentés avec la valeur validée.
	update_post_meta( $post_id, '_VenueStateProvince', $dept );
	update_post_meta( $post_id, '_VenueProvince', $dept );

	// Case « Ce lieu n'a pas de nom » (pf_render_venue_name_is_address_checkbox) :
	// une checkbox décochée n'est pas soumise en POST, d'où le isset() plutôt
	// qu'un simple ternaire — sans ça, la décocher ne l'effacerait jamais.
	update_post_meta( $post_id, '_VenueNameIsAddress', isset( $_POST['venue']['NameIsAddress'] ) ? 1 : 0 );

	// Champs géo traités AVANT la composition du titre : celle-ci peut
	// déclencher un wp_update_post() imbriqué (cf. pf_venue_apply_composed_title())
	// qui redéclenche geocode_on_save() @25 — sans les coordonnées de l'aperçu
	// déjà en place, cette passe imbriquée regéocoderait pour rien avant que
	// l'appel ci-dessous n'ait eu la main.
	pf_save_venue_geo_fields( $post_id, $_POST['venue'] );
	pf_venue_apply_composed_title( $post_id );
}

/**
 * Position sur la carte (pf_render_venue_geo_map_widget) : trois cas, dans
 * l'ordre —
 *   1. Repère déplacé (mode manuel) → coordonnées manuelles retenues.
 *   2. Sinon, si l'adresse postée (GeoKey) correspond encore aux metas
 *      qu'on vient d'enregistrer (pf_venue_geo_key()) → les coordonnées
 *      affichées par l'aperçu sont persistées telles quelles (cf. CLAUDE.md :
 *      l'aperçu et le géocodage à l'enregistrement peuvent interroger des
 *      variantes différentes de la même adresse et donc diverger).
 *   3. Sinon (sans-JS, Quick Edit, clé périmée) → on rend la main au
 *      géocodage automatique, qui suit dans la même requête (geocode_on_save
 *      @25, après les deux passes de sauvegarde qui appellent cette fonction).
 * Le mécanisme générique TEC écrit aussi des metas
 * _VenueGeoLat/_VenueGeoLng/_VenueGeoManual/_VenueGeoKey/_VenueGeoPrecision
 * brutes (non validées) pour tout champ venue[X] soumis — on les efface,
 * notre propre lecture ci-dessus fait foi.
 *
 * @param array $venue_data $_POST['venue'] (fiche autonome) ou $data
 *              (tribe_events_venue_created, valeurs déjà aplaties par TEC).
 */
function pf_save_venue_geo_fields( $venue_id, array $venue_data ) {
	// '' !== avant le cast : (float) '' vaut 0.0, une coordonnée dans la
	// plage valide (golfe de Guinée) — un champ non soumis ou vide doit
	// rester null, jamais devenir « 0,0 ».
	$lat_raw = $venue_data['GeoLat'] ?? '';
	$lng_raw = $venue_data['GeoLng'] ?? '';
	$lat     = '' !== $lat_raw ? (float) $lat_raw : null;
	$lng     = '' !== $lng_raw ? (float) $lng_raw : null;
	$manual  = ! empty( $venue_data['GeoManual'] );

	if ( $manual && null !== $lat && null !== $lng ) {
		Passiflore_Events_Map::set_manual_coords( $venue_id, $lat, $lng );
	} elseif (
		null !== $lat && null !== $lng
		&& isset( $venue_data['GeoKey'] )
		&& $venue_data['GeoKey'] === pf_venue_geo_key( $venue_id )
	) {
		Passiflore_Events_Map::set_geocoded_coords(
			$venue_id,
			$lat,
			$lng,
			sanitize_text_field( (string) ( $venue_data['GeoPrecision'] ?? '' ) )
		);
	} else {
		Passiflore_Events_Map::clear_manual_coords( $venue_id );
	}

	delete_post_meta( $venue_id, '_VenueGeoLat' );
	delete_post_meta( $venue_id, '_VenueGeoLng' );
	delete_post_meta( $venue_id, '_VenueGeoManual' );
	delete_post_meta( $venue_id, '_VenueGeoKey' );
	delete_post_meta( $venue_id, '_VenueGeoPrecision' );
}

/* ═══════════════════════════════════════════════════════════════
   Création d'un lieu en ligne depuis la fiche événement (tribe_events)
   ═══════════════════════════════════════════════════════════════

   TEC propose, en plus du sélecteur de lieu existant, un mini-formulaire
   « Créer un nouveau lieu » directement sur l'écran événement (template
   create-venue-fields.php, distinct de venue-meta-box.php utilisé par la
   fiche lieu autonome — nos champs Département/Région n'y apparaissaient
   donc pas). Ce mini-formulaire est rendu une seule fois dans un
   <script type="text/template"> puis cloné en JS ; le hook natif
   tribe_events_linked_post_new_form (déjà utilisé par TEC pour y injecter
   create-venue-fields.php) est le seul point d'extension disponible.

   Sauvegarde : la création passe par Tribe__Events__Venue::create(), où le
   save_meta() natif de TEC (qui écrit _Venue{Champ} bruts pour CHAQUE champ
   soumis, sans validation) s'exécute APRÈS le hook save_post_tribe_venue
   (déclenché plus tôt, pendant le wp_insert_post() interne à create()) —
   contrairement à la fiche lieu autonome où save_post_tribe_venue arrive
   après le save natif. Brancher la validation au même hook que la fiche
   autonome serait donc écrasé juste après par save_meta(). On utilise à la
   place l'action dédiée tribe_events_venue_created, déclenchée juste après
   save_meta() — cf. pf_sync_venue_fields_on_create() plus bas.
   ═══════════════════════════════════════════════════════════════ */

/**
 * Les dropdowns natifs TEC de sélection de lieu ET d'organisateur font aussi
 * office de « créer en tapant » (mode Select2 « freeform », option
 * « Create: <texte tapé> »), source de confusion avec le mini-formulaire
 * dédié à chacun. On désactive ce mode via le filtre officiel prévu à cet
 * effet (contrôle uniquement les attributs data-freeform/data-create-choice-
 * template posés sur le <select> — Tribe__Events__Linked_Posts::
 * saved_linked_post_dropdown()) : chaque dropdown redevient recherche seule,
 * et son placeholder passe automatiquement de « Créer ou trouver un X » à
 * « Trouver un X » (même mécanisme natif, get_create_or_find_labels()). La
 * création passe désormais uniquement par le bouton « Créer un X » dédié
 * (assets/js/venue-admin.js, wireCreateButton()) — même mécanisme pour les
 * deux types de contenu lié, malgré le nom du fichier resté « venue-admin »
 * (premier point d'entrée historique de ce fichier).
 */
add_filter( 'tribe_events_linked_posts_dropdown_enable_creation', 'pf_disable_linked_post_dropdown_creation', 10, 2 );

function pf_disable_linked_post_dropdown_creation( $creation_enabled, $post_type ) {
	if ( in_array( $post_type, [ 'tribe_venue', 'tribe_organizer' ], true ) ) return false;
	return $creation_enabled;
}

/**
 * Masque la note native TEC « L'e-mail sera masqué sur le site pour éviter d'être aspiré
 * par les spammeurs. » (écrans d'édition organisateur : organizer-meta-box.php + création
 * inline sur la fiche événement, create-organizer-fields.php). Devenue trompeuse depuis
 * qu'on affiche réellement l'email (obfusqué en sortie, mais visible/cliquable pour le
 * visiteur) — un·e éditeur·rice pourrait lire « masqué » comme « jamais montré ». Filtre
 * gettext scopé au texte + domaine exacts (pas d'édition du plugin, survit aux MAJ TEC).
 */
add_filter( 'gettext', 'pf_hide_organizer_email_obfuscation_notice', 10, 3 );

function pf_hide_organizer_email_obfuscation_notice( $translated, $text, $domain ) {
	if ( 'the-events-calendar' === $domain
		&& 'The e-mail address will be obfuscated on this site to avoid it getting harvested by spammers.' === $text
	) {
		return '';
	}
	return $translated;
}

add_action( 'tribe_events_linked_post_new_form', 'pf_render_venue_departement_region_fields_inline', 20 );

function pf_render_venue_departement_region_fields_inline( $post_type ) {
	if ( 'tribe_venue' !== $post_type ) return;
	?>
	<tr class="linked-post venue tribe-linked-type-venue-departement">
		<td class="tribe-table-field-label">
			<label><?php esc_html_e( 'Département :', 'kadence-child' ); ?></label>
		</td>
		<td>
			<div class="pf-venue-combo" data-field="departements">
				<input type="text" name="venue[Departement][]" size="25" autocomplete="off" value="" />
				<ul class="pf-venue-combo-menu"></ul>
			</div>
		</td>
	</tr>
	<tr class="linked-post venue tribe-linked-type-venue-region">
		<td class="tribe-table-field-label">
			<label><?php esc_html_e( 'Région :', 'kadence-child' ); ?></label>
		</td>
		<td>
			<div class="pf-venue-combo" data-field="regions">
				<input type="text" name="venue[Region][]" size="25" autocomplete="off" value="" />
				<ul class="pf-venue-combo-menu"></ul>
			</div>
		</td>
	</tr>
	<tr class="linked-post venue tribe-linked-type-venue-name-is-address">
		<td class="tribe-table-field-label"></td>
		<td>
			<label>
				<input type="checkbox" name="venue[NameIsAddress][]" value="1" id="pf-venue-name-is-address-inline" />
				<?php esc_html_e( "Ce lieu n'a pas de nom, remplacer par l'adresse.", 'kadence-child' ); ?>
			</label>
		</td>
	</tr>
	<?php
}

add_action( 'tribe_events_venue_created', 'pf_sync_venue_fields_on_create', 10, 2 );

function pf_sync_venue_fields_on_create( $venue_id, $data ) {
	$dept = isset( $data['Departement'] ) ? pf_venue_clamp_departement( sanitize_text_field( $data['Departement'] ) ) : '';
	$reg  = isset( $data['Region'] )      ? pf_venue_clamp_region( sanitize_text_field( $data['Region'] ) )          : '';

	update_post_meta( $venue_id, '_VenueDepartement', $dept );
	update_post_meta( $venue_id, '_VenueRegion', $reg );

	// cf. pf_validate_venue_departement_region() : le champ natif « State or
	// Province » reste alimenté par Département pour les usages natifs déjà
	// branchés dessus (bloc adresse public, schema.org JSON-LD).
	update_post_meta( $venue_id, '_VenueStateProvince', $dept );
	update_post_meta( $venue_id, '_VenueProvince', $dept );

	update_post_meta( $venue_id, '_VenueNameIsAddress', ! empty( $data['NameIsAddress'] ) ? 1 : 0 );

	// Ordre important : cf. le même commentaire dans
	// pf_validate_venue_departement_region() — ici en particulier, le garde
	// de post_ID de pf_validate_venue_departement_region bloque toute
	// ré-entrée dans CETTE fonction depuis le wp_update_post() imbriqué (elle
	// n'écoute que tribe_events_venue_created, pas save_post_tribe_venue),
	// donc rien d'autre ne traiterait les champs géo avant que le
	// géocodage imbriqué ne parte pour de vrai.
	pf_save_venue_geo_fields( $venue_id, $data );
	pf_venue_apply_composed_title( $venue_id );
}

/* ═══════════════════════════════════════════════════════════════
   Assets admin (combobox à choix contraint)
   ═══════════════════════════════════════════════════════════════ */

add_action( 'admin_enqueue_scripts', 'pf_enqueue_venue_admin_assets' );

/**
 * Fait remonter en tête de liste l'option dont la value vaut $pinned_value
 * (siège de la maison d'édition), le reste conservant l'ordre alphabétique
 * des constantes PF_VENUE_DEPARTEMENTS/PF_VENUE_REGIONS.
 */
function pf_venue_options_pinned_first( array $options, string $pinned_value ): array {
	$pinned = $rest = [];
	foreach ( $options as $opt ) {
		if ( $opt['value'] === $pinned_value ) {
			$pinned[] = $opt;
		} else {
			$rest[] = $opt;
		}
	}
	return array_merge( $pinned, $rest );
}

/**
 * Table [préfixe code postal => nom département] pour l'auto-remplissage JS
 * depuis le code postal (cf. pf_enqueue_venue_admin_assets). Couvre la
 * métropole (préfixe à 2 chiffres = code département) et les DOM (préfixe à
 * 3 chiffres, ex. 971 = Guadeloupe). Corse volontairement exclue : le
 * découpage 2A/2B ne suit pas de règle de préfixe fiable — laissée à la
 * sélection manuelle plutôt que de deviner faux.
 */
function pf_venue_postal_prefix_to_departement(): array {
	$map = [];
	foreach ( PF_VENUE_DEPARTEMENTS as $d ) {
		if ( in_array( $d['code'], [ '2A', '2B' ], true ) ) continue;
		$map[ $d['code'] ] = $d['name'];
	}
	return $map;
}

/**
 * Clé de fraîcheur de l'adresse d'un lieu, pour la persistance des
 * coordonnées prévisualisées (cf. pf_save_venue_geo_fields()). Même ordre
 * exact que currentKey() côté JS (assets/js/venue-geo-picker.js) — valeurs
 * BRUTES des metas enregistrées, pas le texte de venue_address_string() (qui
 * sert à la requête Nominatim, pas à cette comparaison).
 */
function pf_venue_geo_key( $venue_id ): string {
	return implode( '|', [
		trim( (string) get_post_meta( $venue_id, '_VenueAddress', true ) ),
		trim( (string) get_post_meta( $venue_id, '_VenueCity', true ) ),
		trim( (string) get_post_meta( $venue_id, '_VenueZip', true ) ),
		trim( (string) get_post_meta( $venue_id, '_VenueCountry', true ) ),
	] );
}

/**
 * Résout Ville/CP/Département/Région/Pays depuis le bloc `address` d'une
 * réponse Nominatim (cf. Passiflore_Events_Map::nominatim_request(), appelé
 * avec addressdetails=1) — auto-remplissage à la frappe côté JS
 * (assets/js/venue-geo-picker.js, applyFields()).
 *
 * Cascade de résolution du département, aucune source seule ne couvrant tout :
 *   1. `ISO3166-2-lvl6` (ex. "FR-40", "FR-75C", "FR-2A") — seule source qui
 *      résout Paris/Lyon (arrondissement/métropole, pas un vrai département)
 *      et la Corse (2A/2B, non déductible d'un préfixe postal).
 *   2. `county` (ex. "Landes") — filet si l'ISO manque mais pas le nom.
 *   3. Préfixe du CP de la RÉPONSE (`address.postcode`) — rattrape l'outre-mer,
 *      où les deux sources précédentes sont nulles.
 *   4. Préfixe du CP SAISI ($entered_zip) — repli quand la réponse elle-même
 *      ne porte aucun `postcode` (nœuds commune des DOM, ex. Saint-Denis/La
 *      Réunion : ni county, ni ISO3166-2-lvl6, ni postcode).
 *
 * @param array  $address        Bloc `address` de Nominatim (addressdetails=1).
 * @param string $precision      'street'|'city' — la ville n'est retenue qu'à
 *                                précision rue (cf. note ci-dessous).
 * @param string $entered_zip    CP saisi par l'éditeur (repli département,
 *                                cf. étape 4 de la cascade ci-dessous — utile
 *                                aux nœuds commune des DOM, qui ne portent
 *                                souvent aucun `postcode` dans la réponse).
 * @param bool   $commune_unique Un seul candidat de type commune portait le
 *                                nom saisi (cf. pf_venue_pick_commune_candidate()) :
 *                                élargit le verrou du CP à la précision 'city'.
 * @return array{city:string,zip:string,departement:string,region:string,country:string}
 */
function pf_venue_admin_fields_from_geocode( array $address, string $precision, string $entered_zip = '', bool $commune_unique = false ): array {
	$fields = [ 'city' => '', 'zip' => '', 'departement' => '', 'region' => '', 'country' => '' ];

	$country_code = strtoupper( $address['country_code'] ?? '' );
	$is_france    = 'FR' === $country_code;

	if ( $country_code && class_exists( 'Tribe__View_Helpers' ) ) {
		$countries = Tribe__View_Helpers::constructCountries();
		if ( isset( $countries[ $country_code ] ) ) {
			$fields['country'] = $countries[ $country_code ];
		}
	}

	// Ville : seulement à précision rue (cf. CLAUDE.md — un CP seul couvre
	// souvent plusieurs communes, ex. 40200 = Aureilhan ET Mimizan). Jamais
	// `municipality` : en France c'est l'arrondissement, pas la commune
	// (Mimizan a `town: Mimizan` ET `municipality: Mont-de-Marsan`).
	if ( 'street' === $precision ) {
		foreach ( [ 'city', 'town', 'village', 'hamlet' ] as $key ) {
			if ( ! empty( $address[ $key ] ) ) {
				$fields['city'] = sanitize_text_field( $address[ $key ] );
				break;
			}
		}
	}

	// CP affiché dans le formulaire : à précision rue (le CP d'un résultat
	// commune est en général le CP principal, arbitraire pour une commune qui
	// en compte plusieurs, ex. Bordeaux → 33000 alors que le lieu est en
	// 33800) — OU quand un seul candidat commune portait le nom saisi
	// ($commune_unique) : l'ambiguïté qui justifiait la retenue est alors
	// levée pour de bon. $postcode reste en revanche disponible pour la
	// résolution du département ci-dessous (elle n'a jamais eu besoin d'une
	// précision rue).
	$postcode = isset( $address['postcode'] ) ? sanitize_text_field( $address['postcode'] ) : '';
	if ( ( 'street' === $precision || $commune_unique ) && $postcode ) {
		$fields['zip'] = ( $is_france && ! preg_match( '/^\d{5}$/', $postcode ) ) ? '' : $postcode;
	}

	if ( $is_france ) {
		$dept = '';

		if ( ! empty( $address['ISO3166-2-lvl6'] ) && 0 === strpos( $address['ISO3166-2-lvl6'], 'FR-' ) ) {
			$sub = substr( $address['ISO3166-2-lvl6'], 3 ); // "40", "75C", "69M", "2A"
			if ( in_array( $sub, [ '2A', '2B' ], true ) ) {
				$dept = pf_venue_departement_name( $sub );
			} else {
				$digits = preg_replace( '/\D/', '', $sub );
				if ( $digits ) {
					$dept = pf_venue_departement_name( $digits );
				}
			}
		}

		if ( ! $dept && ! empty( $address['county'] ) ) {
			$dept = pf_venue_clamp_departement( sanitize_text_field( $address['county'] ) );
		}

		if ( ! $dept && $postcode ) {
			$dept = pf_venue_departement_from_postal( $postcode );
		}

		if ( ! $dept && $entered_zip ) {
			$dept = pf_venue_departement_from_postal( $entered_zip );
		}

		$fields['departement'] = $dept;
		$fields['region']      = $dept
			? ( PF_VENUE_DEPARTEMENT_TO_REGION[ $dept ] ?? '' )
			: pf_venue_clamp_region( sanitize_text_field( $address['state'] ?? '' ) );
	}

	return $fields;
}

/**
 * Département attendu à partir d'un code postal (cascade préfixe 3 puis 2
 * chiffres — couvre l'outre-mer). Factorisé : utilisé à la fois par
 * pf_venue_admin_fields_from_geocode() (étapes 3/4 de sa cascade) et par
 * pf_venue_pick_commune_candidate() (désambiguïsation des homonymes). Corse
 * (2A/2B) volontairement absente, cf. pf_venue_postal_prefix_to_departement() —
 * un CP 20xxx ne désigne aucun département par préfixe, la sélection retombe
 * simplement sur l'ordre d'importance de Nominatim.
 */
function pf_venue_departement_from_postal( string $zip ): string {
	if ( ! preg_match( '/^\d{5}$/', $zip ) ) return '';
	$map = pf_venue_postal_prefix_to_departement();
	return $map[ substr( $zip, 0, 3 ) ] ?? ( $map[ substr( $zip, 0, 2 ) ] ?? '' );
}

/**
 * "Paris 11e Arrondissement" / "Lyon 3e Arrondissement" / "Marseille 6e
 * Arrondissement" — seule forme qui fait résoudre Nominatim sur l'objet
 * `suburb` de l'arrondissement plutôt que sur l'objet code postal ou un POI
 * (cf. CLAUDE.md, matrice de requêtes mesurées). '' si $city/$zip ne
 * désignent pas l'une des trois villes françaises à arrondissements, ou si
 * $zip n'est pas un CP à 5 chiffres dans leur plage.
 *
 * ⚠️ Ordinal : "1er" pour le 1er arrondissement, "{n}e" sinon — mesuré,
 * "1e" fait dérailler Nominatim sur un hameau du Gers.
 */
function pf_venue_arrondissement_city( string $city, string $zip ): string {
	if ( ! preg_match( '/^\d{5}$/', $zip ) ) return '';

	$norm  = pf_search_normalize( $city );
	$names = [ 'paris' => 'Paris', 'lyon' => 'Lyon', 'marseille' => 'Marseille' ];
	if ( ! isset( $names[ $norm ] ) ) return '';

	// Plages de CP par ville. Paris 75116 (16e) est hors la plage régulière
	// 75001-75020 — deuxième sous-plage dédiée.
	$ranges = [
		'paris'     => [ [ 75001, 75020 ], [ 75116, 75116 ] ],
		'lyon'      => [ [ 69001, 69009 ] ],
		'marseille' => [ [ 13001, 13016 ] ],
	];

	$n   = (int) $zip;
	$num = null;
	foreach ( $ranges[ $norm ] as $range ) {
		if ( $n >= $range[0] && $n <= $range[1] ) {
			$num = $n % 100;
			break;
		}
	}
	if ( ! $num ) return '';

	$ordinal = ( 1 === $num ) ? '1er' : $num . 'e';

	return $names[ $norm ] . ' ' . $ordinal . ' Arrondissement';
}

/**
 * Désambiguïse les homonymes d'une requête city=<ville>&country=<pays>
 * (limit=25, cf. Passiflore_Events_Map::geocode_parts()) que Nominatim ne
 * sait pas trier par lui-même (ex. "Sainte-Colombe" × 12, "Saint-Denis" × 3).
 * Trois filtres en cascade, cf. CLAUDE.md pour la matrice de validation :
 *   1. Type d'entité — écarte l'objet "code postal", les POI, les suburbs et
 *      les municipality (= arrondissement en France, pas la commune).
 *   2. Nom exact — écarte les communes dont le nom ne correspond pas
 *      strictement à la saisie (ex. "Sainte-Colombe-en-Auxois").
 *   3. Département/région attendus, déduits du CP saisi.
 *   4. Repli : premier candidat dans l'ordre d'importance de Nominatim.
 *
 * @param array  $results Liste normalisée renvoyée par Passiflore_Events_Map::nominatim().
 * @param string $city    Ville saisie.
 * @param string $zip     CP saisi (peut être vide).
 * @return array|null Résultat retenu, enrichi de `pf_commune_unique` (bool —
 *                     un seul candidat après le filtre de nom).
 */
function pf_venue_pick_commune_candidate( array $results, string $city, string $zip ): ?array {
	$communes = array_values( array_filter( $results, function ( $r ) {
		return in_array( $r['addresstype'] ?? '', [ 'city', 'town', 'village', 'hamlet' ], true );
	} ) );
	if ( empty( $communes ) ) return null;

	$city_norm = pf_search_normalize( $city );
	$named     = array_values( array_filter( $communes, function ( $r ) use ( $city_norm ) {
		$name = '';
		foreach ( [ 'city', 'town', 'village', 'hamlet' ] as $key ) {
			if ( ! empty( $r['address'][ $key ] ) ) { $name = $r['address'][ $key ]; break; }
		}
		return pf_search_normalize( $name ) === $city_norm;
	} ) );

	$unique = 1 === count( $named );
	$pool   = $named ?: $communes;

	$expected_dept = $zip ? pf_venue_departement_from_postal( $zip ) : '';
	if ( $expected_dept ) {
		foreach ( $pool as $r ) {
			// ⚠️ Ne JAMAIS passer $zip à cet appel (3e argument, entered_zip) :
			// chaque candidat se résoudrait alors au département attendu, tous
			// correspondraient, et la désambiguïsation dégénérerait
			// silencieusement en « premier candidat » — sans erreur ni test
			// rouge. Les deux arguments optionnels restent à leur défaut.
			$candidate = pf_venue_admin_fields_from_geocode( $r['address'] ?? [], 'city' );
			if ( $candidate['departement'] === $expected_dept ) {
				$r['pf_commune_unique'] = $unique;
				return $r;
			}
		}

		$expected_region = PF_VENUE_DEPARTEMENT_TO_REGION[ $expected_dept ] ?? '';
		if ( $expected_region ) {
			foreach ( $pool as $r ) {
				$candidate = pf_venue_admin_fields_from_geocode( $r['address'] ?? [], 'city' );
				if ( $candidate['region'] === $expected_region ) {
					$r['pf_commune_unique'] = $unique;
					return $r;
				}
			}
		}
	}

	$pick                       = $pool[0];
	$pick['pf_commune_unique']  = $unique;
	return $pick;
}

function pf_enqueue_venue_admin_assets( $hook ) {
	global $post;
	if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
	if ( ! isset( $post->post_type ) || ! in_array( $post->post_type, [ 'tribe_venue', 'tribe_events' ], true ) ) return;

	wp_enqueue_script(
		'pf-venue-admin',
		get_stylesheet_directory_uri() . '/assets/js/venue-admin.js',
		[ 'jquery' ],
		filemtime( get_stylesheet_directory() . '/assets/js/venue-admin.js' ),
		true
	);

	// Carte de repositionnement (champ « Position sur la carte ») : mêmes
	// handles/versions Leaflet que le front (class-events-map.php), aucun
	// Leaflet chargé en admin avant ce point.
	$uri = get_stylesheet_directory_uri();
	$dir = get_stylesheet_directory();

	wp_enqueue_style( 'leaflet', $uri . '/assets/vendor/leaflet/leaflet.css', [], '1.9.4' );
	wp_enqueue_script( 'leaflet', $uri . '/assets/vendor/leaflet/leaflet.js', [], '1.9.4', true );

	wp_enqueue_script(
		'pf-venue-geo-picker',
		$uri . '/assets/js/venue-geo-picker.js',
		[ 'jquery', 'leaflet', 'pf-venue-admin' ],
		filemtime( $dir . '/assets/js/venue-geo-picker.js' ),
		true
	);

	// wp_add_inline_script + wp_json_encode, pas wp_localize_script : même
	// règle que product-format-admin.php (transtyperait tout scalaire de
	// premier niveau en chaîne).
	wp_add_inline_script(
		'pf-venue-geo-picker',
		'var pfVenueGeo = ' . wp_json_encode( [
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'pf_venue_geo' ),
			'tileUrl'     => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
			'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
			'franceView'  => [ 46.6, 2.4, 5 ], // centre + zoom de repli (France entière)
			'i18n'        => [
				'loading'  => __( 'Recherche de l\'adresse…', 'kadence-child' ),
				'street'   => __( 'Adresse localisée : %s', 'kadence-child' ),
				'city'     => __( 'Centre de commune%s. Adresse non localisée précisément — déplacez le repère pour ajuster.', 'kadence-child' ),
				'notfound' => __( 'Adresse introuvable. Placez le repère à la main sur la carte.', 'kadence-child' ),
				'manual'   => __( 'Coordonnées ajustées à la main. L\'adresse ci-dessus ne sert plus qu\'au texte affiché.', 'kadence-child' ),
				'error'    => __( 'Vérification impossible pour le moment.', 'kadence-child' ),
			],
		] ) . ';',
		'before'
	);

	/**
	 * pf_disable_linked_post_dropdown_creation() retire côté PHP les attributs
	 * data-freeform/data-force-search/etc. du <select> (tous posés dans le
	 * même bloc conditionnel par Tribe__Events__Linked_Posts::
	 * saved_linked_post_dropdown()), en désactivant proprement le mode
	 * « créer en tapant ». Effet de bord non désiré : Select2 (dropdowns.js,
	 * commun/build) fixe minimumResultsForSearch=10 par défaut et ne le
	 * retire QUE si data-force-search est présent — sans lui, la barre de
	 * recherche interne au dropdown n'apparaît plus qu'à partir de 10
	 * options. Comme les deux attributs sont posés ensemble dans le même
	 * bloc « if ($creation_enabled) », impossible de garder l'un sans
	 * l'autre côté PHP (pas de filtre par attribut individuel) — réinjecté
	 * en JS à la place, en deux temps :
	 *
	 *  1) Au chargement initial : data-force-search posé directement en JS,
	 *     avant l'exécution du script natif TEC (tribe-events-admin est en
	 *     pied de page — $in_footer=true par défaut de la lib Assets — donc
	 *     le <select> existe déjà dans le DOM à ce stade, et le script natif,
	 *     qui lit cet attribut à l'initialisation de Select2, n'a pas encore
	 *     tourné).
	 *
	 *  2) Pour les organisateurs (allow_multiple=true, contrairement aux
	 *     lieux) : chaque clic sur « + Ajouter un autre organisateur » clone
	 *     un NOUVEAU <select>, en dehors de tout chargement de page — le
	 *     correctif du point 1 ne peut pas l'atteindre. Le clic TEC appelle
	 *     .tribe_dropdowns() de façon synchrone sur ce nouveau <select> AVANT
	 *     même de déclencher le hook JS natif tec.events.admin.linked_posts.
	 *     add_post (vérifié dans build/js/events-admin.js) — impossible donc
	 *     de patcher l'attribut a posteriori via ce hook, Select2 aurait déjà
	 *     lu l'ancien minimumResultsForSearch. On enveloppe donc directement
	 *     $.fn.tribe_dropdowns (tribe-dropdowns est une dépendance de
	 *     tribe-events-admin, chargée avant lui, donc déjà définie ici) pour
	 *     poser l'attribut juste avant CHAQUE appel, initial ou futur.
	 */
	if ( wp_script_is( 'tribe-events-admin', 'registered' ) ) {
		$pf_force_search_js = <<<'JS'
document.querySelectorAll('select.linked-post-dropdown[data-post-type="tribe_venue"], select.linked-post-dropdown[data-post-type="tribe_organizer"]').forEach(function (el) {
	el.setAttribute('data-force-search', '');
});
if (window.jQuery && window.jQuery.fn && window.jQuery.fn.tribe_dropdowns) {
	var pfOriginalTribeDropdowns = window.jQuery.fn.tribe_dropdowns;
	window.jQuery.fn.tribe_dropdowns = function () {
		this.filter('select.linked-post-dropdown[data-post-type="tribe_venue"], select.linked-post-dropdown[data-post-type="tribe_organizer"]').attr('data-force-search', '');
		return pfOriginalTribeDropdowns.apply(this, arguments);
	};
}
JS;
		wp_add_inline_script( 'tribe-events-admin', $pf_force_search_js, 'before' );
	}

	wp_localize_script( 'pf-venue-admin', 'pfVenueAdmin', [
		'departements' => pf_venue_options_pinned_first( array_map( function ( $d ) {
			return [ 'value' => $d['name'], 'label' => $d['name'] . ' (' . $d['code'] . ')' ];
		}, PF_VENUE_DEPARTEMENTS ), 'Landes' ),
		'regions' => pf_venue_options_pinned_first( array_map( function ( $r ) {
			return [ 'value' => $r, 'label' => $r ];
		}, PF_VENUE_REGIONS ), 'Nouvelle-Aquitaine' ),
		'postalPrefixToDept' => pf_venue_postal_prefix_to_departement(),
		'deptToRegion'       => PF_VENUE_DEPARTEMENT_TO_REGION,
	] );

	wp_add_inline_style( 'wp-admin', '
		/* Champ natif TEC remplacé par Département (cf. pf_validate_venue_departement_region).
		   !important nécessaire : sur la fiche événement, le JS natif TEC (events-admin.js)
		   affiche ces lignes via jQuery .show() (style inline) quand le mini-formulaire de
		   création de lieu est révélé, ce qui écraserait une simple règle sans !important. */
		tr.tribe-linked-type-venue-state-province { display:none !important; }
		.pf-venue-combo { position:relative; display:inline-block; }
		.pf-venue-combo-menu { position:absolute; top:100%; left:0; z-index:100; margin:2px 0 0; padding:0; list-style:none; min-width:220px; max-height:220px; overflow-y:auto; background:#fff; border:1px solid #ddd; border-radius:3px; box-shadow:0 2px 6px rgba(0,0,0,.15); display:none; }
		.pf-venue-combo-menu li { padding:5px 8px; cursor:pointer; border-bottom:1px solid #f5f5f5; }
		.pf-venue-combo-menu li:last-child { border-bottom:none; }
		.pf-venue-combo-menu li:hover { background:#f0f6fc; }
		.pf-venue-combo-menu li.pf-venue-combo-empty { color:#999; cursor:default; }
		.pf-venue-combo-menu li.pf-venue-combo-empty:hover { background:none; }
		#title.pf-venue-title-readonly { background:#f0f0f0; color:#666; }
		.pf-linked-post-create-btn { margin-left:10px; vertical-align:middle; }
		.pf-linked-post-create-cancel { margin-left:10px; vertical-align:middle; }
		/* Case « Show map » natif TEC (Google Maps) : sans objet, ce site a sa
		   propre vue Carte OpenStreetMap/Leaflet (Passiflore_Events_Map) — pas
		   de JS TEC ne touchant l\'affichage de cette ligne (class
		   remain-visible, jamais .hide()/.show() par events-admin.js), donc
		   pas besoin de !important ici contrairement au reste de ce bloc.
		   Même id sur les deux templates (fiche lieu autonome et fiche
		   événement) — une seule règle couvre les deux. */
		#google_map_toggle { display:none; }
		/* Bloc « Cost » natif TEC (symbole monetaire, devise) sur la fiche
		   evenement : sans objet, la tarification passe par les produits
		   WooCommerce, pas par un cout d entree a l evenement lui-meme.
		   Jamais touche par events-admin.js (aucune reference a cet id). */
		#event_cost { display:none; }
		/* Champ « Position sur la carte » — style.css n\'est pas chargé en admin,
		   hex littéraux plutôt que tokens --pf-* (cf. règle CSS du thème). */
		.pf-venue-geo-map { height:260px; margin:6px 0; border:1px solid #c3c4c7; border-radius:4px; background:#f0f0f1; }
		.pf-venue-geo-status { margin:4px 0; font-size:13px; min-height:1.4em; }
		.pf-venue-geo-status[data-status="street"] { color:#1e7b34; }
		.pf-venue-geo-status[data-status="city"] { color:#996800; }
		.pf-venue-geo-status[data-status="notfound"],
		.pf-venue-geo-status[data-status="error"] { color:#b32d2e; }
		.pf-venue-geo-status[data-status="manual"] { color:#2271b1; }
		.pf-venue-geo-status[data-status="loading"] { color:#666; }
		.pf-venue-geo-reset { margin-bottom:6px; }
		.pf-venue-geo-pin { background:none; border:0; color:#c62836; }
		.pf-venue-geo-pin__svg { display:block; width:100%; height:100%; overflow:visible; filter:drop-shadow(0 1px 1.5px rgba(26,22,21,.3)); }
		.pf-venue-geo-pin__svg path { fill:currentColor; stroke:#fff; stroke-width:2; }
		.pf-venue-geo-pin__svg circle { fill:#fff; }
	' );
}

/* ═══════════════════════════════════════════════════════════════
   Pays par défaut : France (nouveau lieu, champ non encore renseigné)
   ═══════════════════════════════════════════════════════════════ */

add_filter( 'tribe_events_default_value_strategy', function ( $defaults ) {
	return new class extends Tribe__Events__Default_Values {
		public function country() {
			return [ 'FR', 'France' ];
		}
	};
} );

/* ═══════════════════════════════════════════════════════════════
   Liste des lieux — colonne « Carte » (état de géocodage)
   ═══════════════════════════════════════════════════════════════ */

add_filter( 'manage_edit-tribe_venue_columns', 'pf_venue_geo_column' );

function pf_venue_geo_column( $columns ) {
	$columns['pf_venue_geo'] = __( 'Carte', 'kadence-child' );
	return $columns;
}

add_action( 'manage_tribe_venue_posts_custom_column', 'pf_venue_geo_column_content', 10, 2 );

function pf_venue_geo_column_content( $column, $post_id ) {
	if ( 'pf_venue_geo' !== $column ) return;

	$lat       = get_post_meta( $post_id, Passiflore_Events_Map::GEO_META_LAT, true );
	$precision = get_post_meta( $post_id, Passiflore_Events_Map::GEO_META_PRECISION, true );

	if ( '' === $lat ) {
		echo '<span style="color:#b32d2e;">&#10007; ' . esc_html__( 'Non localisé', 'kadence-child' ) . '</span>';
		return;
	}

	switch ( $precision ) {
		case Passiflore_Events_Map::GEO_PRECISION_MANUAL:
			echo '<span style="color:#2271b1;">&#10003; ' . esc_html__( 'Ajusté à la main', 'kadence-child' ) . '</span>';
			break;
		case Passiflore_Events_Map::GEO_PRECISION_STREET:
			echo '<span style="color:#1e7b34;">&#10003; ' . esc_html__( 'Localisé', 'kadence-child' ) . '</span>';
			break;
		default:
			echo '<span style="color:#996800;">&#9888; ' . esc_html__( 'Centre de commune', 'kadence-child' ) . '</span>';
			break;
	}
}

add_filter( 'manage_edit-tribe_venue_sortable_columns', 'pf_venue_geo_sortable_column' );

function pf_venue_geo_sortable_column( $columns ) {
	$columns['pf_venue_geo'] = 'pf_venue_geo';
	return $columns;
}

/**
 * meta_query relation OR (EXISTS + NOT EXISTS) plutôt qu'un simple orderby
 * meta_value : un orderby meta standard EXCLUT les posts sans la meta (INNER
 * JOIN), donc les lieux non localisés — précisément ceux que cette colonne
 * sert à repérer — disparaîtraient de la liste triée.
 */
add_action( 'pre_get_posts', 'pf_venue_geo_column_orderby' );

function pf_venue_geo_column_orderby( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) return;
	if ( 'tribe_venue' !== $query->get( 'post_type' ) ) return;
	if ( 'pf_venue_geo' !== $query->get( 'orderby' ) ) return;

	$query->set( 'meta_query', [
		'relation' => 'OR',
		[ 'key' => Passiflore_Events_Map::GEO_META_PRECISION, 'compare' => 'EXISTS' ],
		[ 'key' => Passiflore_Events_Map::GEO_META_PRECISION, 'compare' => 'NOT EXISTS' ],
	] );
	$query->set( 'meta_key', Passiflore_Events_Map::GEO_META_PRECISION );
	$query->set( 'orderby', 'meta_value' );
}
