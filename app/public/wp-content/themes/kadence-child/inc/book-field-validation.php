<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Validation à l'enregistrement du produit (écran WooCommerce natif, metabox
 * SCF group_69c7d37956f1c "Informations du livre") : bloque la sauvegarde
 * plutôt que de filtrer indéfiniment en lecture. Les filtres de lecture
 * existants (pf_distinction_labels() dans book-single-tabs.php, etc.) restent
 * en place : ils couvrent les données déjà en base avant ce correctif et les
 * canaux d'écriture qui ne passent pas par ce formulaire (import, API).
 *
 * "Vide" = aucun caractère alphanumérique Unicode — un champ composé
 * uniquement d'espaces ou de ponctuation compte comme vide, pas seulement une
 * chaîne strictement vide.
 *
 * Clés de champs relevées via acf_get_field() (pas depuis l'export JSON, qui
 * omet certaines clés de sous-champs) — ⚠️ 'contributions' ne doit JAMAIS être
 * résolu par nom : un onglet SCF s'appelle aussi "Contributions", et
 * acf_get_field('contributions') renvoie CET onglet (type=tab, sans
 * sous-champs), pas le repeater.
 */

function pf_field_has_alnum( $v ): bool {
	return is_string( $v ) && preg_match( '/[\p{L}\p{N}]/u', $v ) === 1;
}

/**
 * Champ texte simple : optionnel (une chaîne strictement vide est autorisée —
 * l'éditeur n'a rien tapé), mais dès qu'il y a un caractère, il doit y avoir
 * de l'alphanumérique. ⚠️ Ne PAS utiliser trim()==='' ici : un champ à seuls
 * espaces est truthy en PHP (`if ($nom_de_plume)`), donc affiché vide côté
 * front si on le laissait passer — exactement le bug d'origine.
 */
function pf_validate_simple_text_field( array $acf, string $key, string $label ) {
	if ( ! array_key_exists( $key, $acf ) ) return;
	$v = (string) $acf[ $key ];
	if ( $v === '' ) return; // rien tapé : champ optionnel, autorisé
	if ( ! pf_field_has_alnum( $v ) ) {
		acf_add_validation_error( 'acf[' . $key . ']', "$label : sans caractère alphanumérique." );
	}
}

/** Repeater à une ligne = un texte : toute ligne présente doit être remplie (sinon la supprimer). */
function pf_validate_repeater_rows_required( array $acf, string $repeater_key, string $subfield_key, string $label ) {
	$rows = $acf[ $repeater_key ] ?? null;
	if ( ! is_array( $rows ) ) return;
	foreach ( $rows as $i => $row ) {
		if ( ! is_array( $row ) ) continue;
		if ( ! pf_field_has_alnum( (string) ( $row[ $subfield_key ] ?? '' ) ) ) {
			acf_add_validation_error( '', "$label : la ligne " . ( (int) $i + 1 ) . ' est vide — la remplir ou la supprimer.' );
		}
	}
}

/** Repeater multi-sous-champs : chaque ligne doit avoir au moins UN sous-champ rempli. */
function pf_validate_repeater_rows_any_field( array $acf, string $repeater_key, array $subfields, string $label ) {
	$rows = $acf[ $repeater_key ] ?? null;
	if ( ! is_array( $rows ) ) return;
	foreach ( $rows as $i => $row ) {
		if ( ! is_array( $row ) ) continue;
		$filled = false;
		foreach ( $subfields as $sf ) {
			$v = $row[ $sf['key'] ] ?? '';
			if ( $sf['type'] === 'file' ) {
				if ( ! empty( $v ) ) { $filled = true; break; }
			} elseif ( pf_field_has_alnum( (string) $v ) ) {
				$filled = true; break;
			}
		}
		if ( ! $filled ) {
			acf_add_validation_error( '', "$label : la ligne " . ( (int) $i + 1 ) . ' est vide — la remplir ou la supprimer.' );
		}
	}
}

/** Contributions : une ligne « Champ texte » doit porter un nom réel. */
function pf_validate_contributions_rows( array $acf ) {
	$key_repeater    = 'field_69c7d477b3fad';
	$key_assignation = 'field_69cd31e1156ae';
	$key_nom         = 'field_69cd3251156af'; // nom_de_l'auteur (curly apostrophe en base)

	$rows = $acf[ $key_repeater ] ?? null;
	if ( ! is_array( $rows ) ) return;
	foreach ( $rows as $i => $row ) {
		if ( ! is_array( $row ) ) continue;
		if ( ( $row[ $key_assignation ] ?? '' ) !== 'champ-texte' ) continue;
		if ( ! pf_field_has_alnum( (string) ( $row[ $key_nom ] ?? '' ) ) ) {
			acf_add_validation_error( '', 'Contributions : le nom de la ligne ' . ( (int) $i + 1 ) . ' (assignation « Champ texte ») est vide.' );
		}
	}
}

/**
 * Aucun autre produit publié/en attente/brouillon ne doit porter exactement le
 * même titre.
 *
 * ⚠️ `$_POST['post_title']` porte la RACINE du titre depuis la refonte de la
 * saisie (inc/product-format-admin.php), pas le nom de produit final : la
 * comparaison doit donc porter sur le titre COMPOSÉ. Sans cela, saisir
 * « L'homme semence » pour l'édition grands caractères se heurterait à
 * l'édition classique du même nom — un faux doublon sur chaque nouveau format.
 */
function pf_validate_duplicate_product_title( int $post_id ) {
	$title = trim( wp_unslash( (string) ( $_POST['post_title'] ?? '' ) ) );
	if ( $title === '' ) return;

	if ( function_exists( 'pf_fmt_form_posted' ) && pf_fmt_form_posted() ) {
		$title = pf_format_compose( $title, pf_fmt_posted_format() );
	}

	global $wpdb;
	$existing_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft')
		 AND ID != %d AND LOWER(TRIM(post_title)) = LOWER(%s) LIMIT 1",
		$post_id, $title
	) );
	if ( $existing_id ) {
		acf_add_validation_error( 'post_title', 'Un autre produit (#' . (int) $existing_id . ') porte déjà exactement ce titre — vérifier qu’il ne s’agit pas d’un doublon.' );
	}
}

add_action( 'acf/validate_save_post', function () {
	$post_id = (int) ( $_POST['post_ID'] ?? 0 );
	if ( ! $post_id || get_post_type( $post_id ) !== 'product' ) return;
	if ( ! isset( $_POST['acf'] ) || ! is_array( $_POST['acf'] ) ) return;
	$acf = wp_unslash( $_POST['acf'] );

	pf_validate_duplicate_product_title( $post_id );

	// Champs texte simples.
	pf_validate_simple_text_field( $acf, 'field_69c7d379b3faa', 'Sous-titre' );                  // sous-titre
	pf_validate_simple_text_field( $acf, 'field_69c7e79e24ea8', 'Nom de plume' );                 // nom_de_plume
	pf_validate_simple_text_field( $acf, 'field_69c7e9f27ff5d', 'Illustration de couverture' );   // illustration_de_couverture
	pf_validate_simple_text_field( $acf, 'field_69cb86d1d4600', 'Lien Place des libraires' );     // lien_place_des_libraires

	// Distinctions : une ligne = un texte, toute ligne présente doit être remplie.
	pf_validate_repeater_rows_required( $acf, 'field_69ca3174cd2ff', 'field_69ca3199cd300', 'Distinctions' );

	// Contributions : cas particulier (le sous-champ requis dépend de l'assignation de la ligne).
	pf_validate_contributions_rows( $acf );

	// Repeaters multi-sous-champs : au moins un sous-champ rempli par ligne.
	pf_validate_repeater_rows_any_field( $acf, 'field_69ca5ed3e53ce', [
		[ 'key' => 'field_69cb82908699e', 'type' => 'text' ], // titre
		[ 'key' => 'field_69cb82ac8699f', 'type' => 'text' ], // description
		[ 'key' => 'field_69cb8312869a1', 'type' => 'text' ], // lien
		[ 'key' => 'field_69cb83b3869a2', 'type' => 'file' ], // fichier
	], 'Articles de presse' );

	pf_validate_repeater_rows_any_field( $acf, 'field_69cb842b869a3', [
		[ 'key' => 'field_69cb842b869a4', 'type' => 'text' ], // titre
		[ 'key' => 'field_69cb842b869a5', 'type' => 'text' ], // description
		[ 'key' => 'field_69cb842b869a7', 'type' => 'text' ], // lien
		[ 'key' => 'field_6a2e72090a5ff', 'type' => 'file' ], // fichier_video
	], 'Vidéos' );

	pf_validate_repeater_rows_any_field( $acf, 'field_69cb848a869ad', [
		[ 'key' => 'field_69cb848a869ae', 'type' => 'text' ], // titre
		[ 'key' => 'field_69cb848a869af', 'type' => 'text' ], // description
		[ 'key' => 'field_69cb848a869b1', 'type' => 'text' ], // lien
		[ 'key' => 'field_69cb848a869b2', 'type' => 'file' ], // fichier_audio
	], 'Podcasts' );

	pf_validate_repeater_rows_any_field( $acf, 'field_69cb8538d45f6', [
		[ 'key' => 'field_69cb8554d45f7', 'type' => 'text' ], // titre
		[ 'key' => 'field_69cb85b0d45f8', 'type' => 'text' ], // auteur
		[ 'key' => 'field_69cb8617d45fa', 'type' => 'text' ], // avis
	], 'Avis des lecteurs' );

	pf_validate_repeater_rows_any_field( $acf, 'field_69cb8663d45fb', [
		[ 'key' => 'field_69cb8663d45fc', 'type' => 'text' ], // titre
		[ 'key' => 'field_69cb8663d45fd', 'type' => 'text' ], // auteur
		[ 'key' => 'field_69cb8663d45ff', 'type' => 'text' ], // avis
	], 'Avis des libraires' );
} );
