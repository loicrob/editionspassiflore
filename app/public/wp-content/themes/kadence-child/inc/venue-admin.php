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

function pf_enqueue_venue_admin_assets( $hook ) {
	global $post;
	if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
	if ( ! isset( $post->post_type ) || ! in_array( $post->post_type, [ 'tribe_venue', 'tribe_events' ], true ) ) return;

	wp_enqueue_script(
		'pf-venue-admin',
		get_stylesheet_directory_uri() . '/assets/js/venue-admin.js',
		[ 'jquery' ],
		'1.0.0',
		true
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
