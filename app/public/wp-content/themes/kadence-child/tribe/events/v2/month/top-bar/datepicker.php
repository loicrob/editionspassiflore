<?php
/**
 * View: Top Bar - Date Picker (override)
 *
 * Surcharge de the-events-calendar/src/views/v2/month/top-bar/datepicker.php
 *
 * En vue mois, le bouton du datepicker affichait un JOUR (format compact « 15/07/2026 »
 * en mobile, format « Mois et année » en desktop). On affiche plutôt le MOIS sélectionné :
 *   - mobile        → « 07/2026 » ($the_date->format('m/Y')) ;
 *   - tablette + desktop → « Juillet 2026 » (mois localisé, 1re lettre capitalisée).
 * La bascule entre les deux <span> (`-mobile` / `-desktop`) est descendue du point de
 * rupture `--breakpoint-full` (desktop) à `--breakpoint-medium` (tablette) via events.css,
 * qui règle aussi la taille du texte/caret ; ici on ne touche qu'au CONTENU des libellés.
 *
 * Le reste est identique au cœur : la valeur de l'<input> (sélection réelle du datepicker)
 * reste une date complète au format compact — on ne change QUE l'étiquette affichée.
 *
 * @link http://evnt.is/1aiy
 *
 * @version 6.14.0
 *
 * @var string    $now                 The current date and time in the `Y-m-d H:i:s` format.
 * @var object    $date_formats        Object containing the date formats.
 * @var \DateTime $the_date            The Month current date object.
 * @var bool      $past                Whether to show only past events.
 */

use Tribe__Date_Utils as Dates;

$default_date        = $now;
$selected_date_value = $this->get( [ 'bar', 'date' ], $default_date );
$datepicker_date     = Dates::build_date_object( $selected_date_value )->format( $date_formats->compact );

// Libellés « mois » (et non un jour) dérivés de la date courante de la grille.
$pf_month_mobile  = $the_date->format( 'm/Y' );
$pf_month_desktop = ucfirst( wp_date( 'F Y', $the_date->getTimestamp(), $the_date->getTimezone() ) );

// « Actif » (bordure/texte rouge, comme un filtre catalogue sélectionné) dès que le mois
// affiché n'est pas le mois courant. Style réimposé dans events.css (reset bouton TEC).
$pf_is_current_month = ( $the_date->format( 'Y-m' ) === wp_date( 'Y-m' ) );
?>
<div class="tribe-events-c-top-bar__datepicker">
	<button
		class="tribe-common-c-btn__clear tribe-events-c-top-bar__datepicker-button pf-cat-trigger pf-dropdown__trigger<?php echo $pf_is_current_month ? '' : ' pf-is-active'; ?>"
		data-js="tribe-events-top-bar-datepicker-button"
		type="button"
		aria-description="<?php esc_attr_e( 'Click to toggle datepicker', 'the-events-calendar' ); ?>"
	>
		<time
			datetime="<?php echo esc_attr( $the_date->format( 'Y-m' ) ); ?>"
			class="tribe-events-c-top-bar__datepicker-time"
		>
			<span class="tribe-events-c-top-bar__datepicker-mobile">
				<?php echo esc_html( $pf_month_mobile ); ?>
			</span>
			<span class="tribe-events-c-top-bar__datepicker-desktop tribe-common-a11y-hidden">
				<?php echo esc_html( $pf_month_desktop ); ?>
			</span>
		</time>
		<span class="pf-cat-caret" aria-hidden="true">&#8942;</span>
	</button>
	<label
		class="tribe-events-c-top-bar__datepicker-label tribe-common-a11y-visual-hide"
		for="tribe-events-top-bar-date"
	>
		<?php esc_html_e( 'Select date.', 'the-events-calendar' ); ?>
	</label>
	<input
		type="text"
		class="tribe-events-c-top-bar__datepicker-input tribe-common-a11y-visual-hide"
		data-js="tribe-events-top-bar-date"
		id="tribe-events-top-bar-date"
		name="tribe-events-views[tribe-bar-date]"
		value="<?php echo esc_attr( $datepicker_date ); ?>"
		tabindex="-1"
		autocomplete="off"
		readonly="readonly"
		<?php echo $past ? 'data-date-end-date="0d"' : ''; ?>
	/>
	<div class="tribe-events-c-top-bar__datepicker-container" data-js="tribe-events-top-bar-datepicker-container"></div>
	<template class="tribe-events-c-top-bar__datepicker-template-prev-icon">
		<?php $this->template( 'components/icons/caret-left', [ 'classes' => [ 'tribe-events-c-top-bar__datepicker-nav-icon-svg' ] ] ); ?>
	</template>
	<template class="tribe-events-c-top-bar__datepicker-template-next-icon">
		<?php $this->template( 'components/icons/caret-right', [ 'classes' => [ 'tribe-events-c-top-bar__datepicker-nav-icon-svg' ] ] ); ?>
	</template>
</div>
