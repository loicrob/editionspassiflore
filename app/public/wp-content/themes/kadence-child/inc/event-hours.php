<?php
/**
 * Horaires par jour pour les evenements multi-jours (The Events Calendar, version gratuite).
 *
 * - Meta box "Horaires par jour" : une ligne par jour entre la date de debut et de fin.
 *   Quatre etats par jour : ferme / journee entiere / debut seul (sans heure de fin) / debut+fin.
 * - Source de verite des heures = postmeta `_pf_event_daily_hours` (clef = jour Ymd).
 *   Les champs natifs `_EventStartDate`/`_EventEndDate`/`_EventAllDay` sont resynchronises a
 *   l'enregistrement (1er/dernier jour ouvert), en respectant la convention d'import
 *   (`end = 23:59:59` = pas d'heure de fin ; `_EventAllDay = yes` = journee entiere).
 * - ICS maison : on intercepte le flux natif `?ical=1` (evenement seul + flux global) pour
 *   emettre un VEVENT par jour. Aucune dependance au code interne du plugin.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

const PF_EH_META   = '_pf_event_daily_hours';
const PF_EH_END_SENTINEL = '23:59:59'; // "pas d'heure de fin" (convention import PrestaShop)

/* ---------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------- */

/**
 * Liste des jours (Ymd) entre deux dates incluses.
 *
 * @return string[]
 */
function pf_event_days_between( string $start_ymd, string $end_ymd ): array {
	$start = DateTime::createFromFormat( 'Ymd', $start_ymd );
	$end   = DateTime::createFromFormat( 'Ymd', $end_ymd );
	if ( ! $start || ! $end || $end < $start ) return $start ? [ $start->format( 'Ymd' ) ] : [];

	$days = [];
	$cur  = clone $start;
	$cur->setTime( 0, 0 );
	$end->setTime( 0, 0 );
	// Garde-fou : pas plus de 366 jours.
	for ( $i = 0; $i <= 366 && $cur <= $end; $i++ ) {
		$days[] = $cur->format( 'Ymd' );
		$cur->modify( '+1 day' );
	}
	return $days;
}

/**
 * Normalise une ligne de jour.
 */
function pf_event_normalize_day( array $row ): array {
	$closed = ! empty( $row['closed'] );
	$allday = ! empty( $row['allday'] );
	$noend  = ! empty( $row['noend'] );

	$start = isset( $row['start'] ) ? trim( (string) $row['start'] ) : '';
	$end   = isset( $row['end'] ) ? trim( (string) $row['end'] ) : '';

	$valid = static function ( $t ) {
		return ( $t !== '' && preg_match( '/^\d{1,2}:\d{2}$/', $t ) )
			? sprintf( '%02d:%02d', ...array_map( 'intval', explode( ':', $t ) ) )
			: null;
	};

	$start = $valid( $start );
	$end   = $valid( $end );

	if ( $allday ) {
		$start = null;
		$end   = null;
	} elseif ( $noend ) {
		$end = null;
	}

	return [
		'start'  => $start,
		'end'    => $end,
		'closed' => $closed,
		'allday' => $allday,
	];
}

/**
 * Horaires stockes (normalises) ou tableau vide si l'evenement n'a jamais ete enregistre
 * avec la meta box. Aucune derivation ici.
 */
function pf_event_get_daily_hours( int $event_id ): array {
	$raw = get_post_meta( $event_id, PF_EH_META, true );
	if ( ! is_array( $raw ) ) return [];

	$out = [];
	foreach ( $raw as $ymd => $row ) {
		if ( ! preg_match( '/^\d{8}$/', (string) $ymd ) || ! is_array( $row ) ) continue;
		$out[ (string) $ymd ] = pf_event_normalize_day( $row );
	}
	ksort( $out );
	return $out;
}

/**
 * L'evenement a-t-il un planning par jour reel (saisi via la meta box) sur au moins 2 jours ?
 */
function pf_event_has_daily_hours( int $event_id ): bool {
	return count( pf_event_get_daily_hours( $event_id ) ) >= 2;
}

/**
 * Le planning contient-il au moins un jour "informatif" (ferme, journee entiere, ou avec
 * une heure de debut saisie) ? Un planning ou AUCUN jour n'a d'info (jamais renseigne au-
 * dela des dates de debut/fin) n'apporte rien — pas la peine d'afficher la section
 * Horaires dans ce cas. Des journees entieres suffisent en revanche a la justifier.
 */
function pf_event_daily_hours_informative( array $hours ): bool {
	foreach ( $hours as $h ) {
		if ( ! empty( $h['closed'] ) || ! empty( $h['allday'] ) || ! empty( $h['start'] ) ) return true;
	}
	return false;
}

/**
 * Une heure "H:i:s" est-elle la sentinelle "pas d'heure de fin" (fin de journee) ?
 */
function pf_event_is_sentinel_time( string $his ): bool {
	return in_array( $his, [ PF_EH_END_SENTINEL, '23:59:00' ], true );
}

/**
 * Format compact d'une heure ("18h", "18h30") : les minutes ne s'affichent que si non nulles.
 * Utilise pour l'affichage des heures dans la liste des evenements (natives TEC et
 * horaires par jour custom), afin que les deux sources rendent le meme format.
 */
function pf_event_format_hm( int $hour, int $minute ): string {
	return $minute ? sprintf( '%dh%02d', $hour, $minute ) : sprintf( '%dh', $hour );
}

/**
 * Lit les bornes natives + applique la convention sentinelle.
 *
 * @return array{allday:bool,start:?string,end:?string}
 */
function pf_event_native_time_parts( int $event_id ): array {
	$allday    = (bool) get_post_meta( $event_id, '_EventAllDay', true );
	$start_str = (string) get_post_meta( $event_id, '_EventStartDate', true );
	$end_str   = (string) get_post_meta( $event_id, '_EventEndDate', true );

	$start_dt = $start_str ? DateTime::createFromFormat( 'Y-m-d H:i:s', $start_str ) : false;
	$end_dt   = $end_str ? DateTime::createFromFormat( 'Y-m-d H:i:s', $end_str ) : false;

	$start = ( $allday || ! $start_dt ) ? null : $start_dt->format( 'H:i' );

	$end = null;
	if ( ! $allday && $end_dt && ! pf_event_is_sentinel_time( $end_dt->format( 'H:i:s' ) ) ) {
		$end = $end_dt->format( 'H:i' );
	}

	return [ 'allday' => $allday, 'start' => $start, 'end' => $end ];
}

/**
 * Horaires par jour pour la meta box : valeurs stockees si presentes, sinon derivees des
 * champs natifs (convention sentinelle). Toujours reconcilie sur la plage [start..end] fournie
 * (ou la plage native si non fournie).
 */
function pf_event_daily_hours_for( int $event_id, ?string $start_ymd = null, ?string $end_ymd = null ): array {
	if ( null === $start_ymd || null === $end_ymd ) {
		$s = (string) get_post_meta( $event_id, '_EventStartDate', true );
		$e = (string) get_post_meta( $event_id, '_EventEndDate', true );
		$start_ymd = $s ? substr( str_replace( '-', '', $s ), 0, 8 ) : null;
		$end_ymd   = $e ? substr( str_replace( '-', '', $e ), 0, 8 ) : $start_ymd;
	}
	if ( ! $start_ymd ) return [];
	if ( ! $end_ymd ) $end_ymd = $start_ymd;

	$stored  = pf_event_get_daily_hours( $event_id );
	$native  = pf_event_native_time_parts( $event_id );
	$default = [
		'start'  => $native['start'],
		'end'    => $native['end'],
		'closed' => false,
		'allday' => $native['allday'],
	];

	$out = [];
	foreach ( pf_event_days_between( $start_ymd, $end_ymd ) as $ymd ) {
		$out[ $ymd ] = $stored[ $ymd ] ?? $default;
	}
	return $out;
}

/**
 * Premier et dernier jour NON fermes.
 *
 * @return array{0:?string,1:?string}
 */
function pf_event_first_last_open_day( array $hours ): array {
	$open = array_keys( array_filter( $hours, static function ( $h ) {
		return empty( $h['closed'] );
	} ) );
	if ( empty( $open ) ) return [ null, null ];
	return [ reset( $open ), end( $open ) ];
}

/**
 * Libelle court d'un creneau pour un jour : "10h00 – 19h00", "a partir de 10h00",
 * "Journee entiere" ou "" (ferme).
 */
function pf_event_day_slot_label( array $h ): string {
	if ( ! empty( $h['closed'] ) ) return '';
	if ( ! empty( $h['allday'] ) ) return 'Journée entière';
	$fmt = static function ( $t ) {
		[ $hh, $mm ] = array_map( 'intval', explode( ':', $t ) );
		return $mm ? sprintf( '%dh%02d', $hh, $mm ) : sprintf( '%dh', $hh );
	};
	if ( ! empty( $h['start'] ) && ! empty( $h['end'] ) ) {
		return $fmt( $h['start'] ) . ' – ' . $fmt( $h['end'] );
	}
	if ( ! empty( $h['start'] ) ) {
		return 'À partir de ' . $fmt( $h['start'] );
	}
	return '';
}

/**
 * Detail texte des horaires par jour, pour les notes Google/Outlook.
 */
function pf_event_hours_text( int $event_id ): string {
	$hours = pf_event_get_daily_hours( $event_id );
	if ( count( $hours ) < 2 ) return '';

	$lines = [];
	foreach ( $hours as $ymd => $h ) {
		$label = date_i18n( 'D j M', (int) strtotime( $ymd ) );
		$slot  = ! empty( $h['closed'] ) ? 'Fermé' : pf_event_day_slot_label( $h );
		$lines[] = $label . ' : ' . ( $slot !== '' ? $slot : 'Fermé' );
	}
	return "Horaires :\n" . implode( "\n", $lines );
}

/* ---------------------------------------------------------------------------
 * Meta box
 * ------------------------------------------------------------------------- */

add_action( 'add_meta_boxes', function () {
	add_meta_box(
		'pf-event-hours',
		'Horaires par jour',
		'pf_render_event_hours_metabox',
		'tribe_events',
		'normal',
		'high'
	);
} );

/**
 * Rendu d'une ligne (reutilise par le rendu initial et l'AJAX de regeneration).
 */
function pf_event_hours_row_html( string $ymd, array $h ): string {
	$label = date_i18n( 'l j F', (int) strtotime( $ymd ) );

	$closed = ! empty( $h['closed'] );
	$allday = ! empty( $h['allday'] );
	$noend  = empty( $h['allday'] ) && empty( $h['end'] ) && ! empty( $h['start'] );
	// Un jour sans start ni end et non allday/closed : on considere "sans heure de fin" coche par defaut si start vide ? non.
	$start  = $h['start'] ?? '';
	$end    = $h['end'] ?? '';

	$dis_times = $closed || $allday;
	$dis_end   = $dis_times || $noend;

	ob_start();
	?>
	<div class="pf-eh-row<?php echo $closed ? ' is-closed' : ''; ?>" data-day="<?php echo esc_attr( $ymd ); ?>">
		<span class="pf-eh-label"><?php echo esc_html( ucfirst( $label ) ); ?></span>
		<span class="pf-eh-times">
			<input type="time" class="pf-eh-start" name="pf_eh[<?php echo esc_attr( $ymd ); ?>][start]" value="<?php echo esc_attr( $start ); ?>"<?php echo $dis_times ? ' disabled' : ''; ?>>
			<span class="pf-eh-sep" aria-hidden="true">&rarr;</span>
			<input type="time" class="pf-eh-end" name="pf_eh[<?php echo esc_attr( $ymd ); ?>][end]" value="<?php echo esc_attr( $end ); ?>"<?php echo $dis_end ? ' disabled' : ''; ?>>
		</span>
		<label class="pf-eh-cb"><input type="checkbox" class="pf-eh-noend" name="pf_eh[<?php echo esc_attr( $ymd ); ?>][noend]" value="1"<?php checked( $noend ); ?><?php echo $dis_times ? ' disabled' : ''; ?>> sans heure de fin</label>
		<label class="pf-eh-cb"><input type="checkbox" class="pf-eh-allday" name="pf_eh[<?php echo esc_attr( $ymd ); ?>][allday]" value="1"<?php checked( $allday ); ?>> journée entière</label>
		<label class="pf-eh-cb"><input type="checkbox" class="pf-eh-closed" name="pf_eh[<?php echo esc_attr( $ymd ); ?>][closed]" value="1"<?php checked( $closed ); ?>> fermé</label>
	</div>
	<?php
	return ob_get_clean();
}

function pf_render_event_hours_metabox( $post ) {
	wp_nonce_field( 'pf_save_event_hours', 'pf_event_hours_nonce' );

	$hours = pf_event_daily_hours_for( (int) $post->ID );
	?>
	<div id="pf-eh-wrap">
		<p class="pf-eh-intro">Les horaires se définissent <strong>ici</strong> (les champs heure natifs de l’agenda sont masqués car remplacés par ce tableau). Réglez la <strong>plage de dates</strong> : le tableau ci-dessous se met à jour automatiquement. Saisissez ensuite les horaires de chaque jour.</p>
		<div id="pf-eh-rows">
			<?php
			if ( empty( $hours ) ) {
				echo '<p id="pf-eh-empty">Definissez d’abord une date de debut et de fin.</p>';
			} else {
				foreach ( $hours as $ymd => $h ) {
					echo pf_event_hours_row_html( (string) $ymd, $h );
				}
			}
			?>
		</div>
		<p class="pf-eh-actions">
			<span id="pf-eh-feedback"></span>
		</p>
	</div>
	<?php
}

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	global $post;
	if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
	if ( ! isset( $post->post_type ) || $post->post_type !== 'tribe_events' ) return;

	wp_enqueue_script(
		'pf-event-hours',
		get_stylesheet_directory_uri() . '/assets/js/event-hours.js',
		[ 'jquery' ],
		'1.0.0',
		true
	);
	wp_localize_script( 'pf-event-hours', 'pfEH', [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'pf_event_hours_ajax' ),
	] );

	wp_add_inline_style( 'wp-admin', '
		#pf-eh-wrap { display:flex; flex-direction:column; gap:10px; }
		.pf-eh-intro { margin:0; color:#666; font-size:12px; }
		#pf-eh-rows { display:flex; flex-direction:column; }
		.pf-eh-row { display:flex; align-items:center; flex-wrap:wrap; gap:8px 14px; padding:7px 0; border-bottom:1px solid #f0f0f0; }
		.pf-eh-row.is-closed .pf-eh-times { opacity:.45; }
		.pf-eh-label { flex:0 0 160px; font-weight:600; }
		.pf-eh-times { display:flex; align-items:center; gap:6px; }
		.pf-eh-times input[type=time] { width:96px; }
		.pf-eh-sep { color:#999; }
		.pf-eh-cb { display:inline-flex; align-items:center; gap:4px; font-size:12px; color:#444; white-space:nowrap; }
		.pf-eh-cb input { margin:0; }
		.pf-eh-actions { display:flex; align-items:center; gap:10px; margin:6px 0 0; }
		#pf-eh-feedback { font-size:12px; color:#888; }
		#pf-eh-empty { color:#888; font-size:13px; margin:4px 0; }

		/* Champs heure / journee entiere natifs de TEC masques : les horaires sont geres
		   par la meta box "Horaires par jour". On conserve les deux dates + le separateur. */
		.tribe-field-start_time,
		.tribe-field-end_time,
		.tribe-field-start_time + .helper-text,
		.tribe-field-end_time + .helper-text,
		p.tribe-allday,
		.event-dynamic-helper { display:none !important; }
	' );
} );

/* ---------------------------------------------------------------------------
 * Enregistrement : stocke `_pf_event_daily_hours` et resynchronise les champs natifs.
 * Priorite 20 : apres TEC (addEventMeta @15) qui a deja ecrit la plage de dates.
 * ------------------------------------------------------------------------- */

add_action( 'save_post', function ( $post_id ) {
	if ( ! isset( $_POST['pf_event_hours_nonce'] ) ) return;
	if ( ! wp_verify_nonce( $_POST['pf_event_hours_nonce'], 'pf_save_event_hours' ) ) return;
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( get_post_type( $post_id ) !== 'tribe_events' ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;

	// Plage de jours d'apres les bornes natives (deja ecrites par TEC).
	$s = (string) get_post_meta( $post_id, '_EventStartDate', true );
	$e = (string) get_post_meta( $post_id, '_EventEndDate', true );
	$start_ymd = $s ? substr( str_replace( '-', '', $s ), 0, 8 ) : '';
	$end_ymd   = $e ? substr( str_replace( '-', '', $e ), 0, 8 ) : $start_ymd;
	if ( '' === $start_ymd ) return;

	$submitted = isset( $_POST['pf_eh'] ) && is_array( $_POST['pf_eh'] ) ? wp_unslash( $_POST['pf_eh'] ) : [];

	$hours = [];
	foreach ( pf_event_days_between( $start_ymd, $end_ymd ) as $ymd ) {
		$row = isset( $submitted[ $ymd ] ) && is_array( $submitted[ $ymd ] ) ? $submitted[ $ymd ] : [];
		$hours[ $ymd ] = pf_event_normalize_day( $row );
	}

	update_post_meta( $post_id, PF_EH_META, $hours );

	pf_event_resync_native_fields( (int) $post_id, $hours );
}, 20 );

/**
 * Reecrit `_EventStartDate`/`_EventEndDate`/`_EventAllDay` (+ UTC + duree) a partir des
 * horaires par jour, selon la convention d'import.
 */
function pf_event_resync_native_fields( int $event_id, array $hours ): void {
	[ $first, $last ] = pf_event_first_last_open_day( $hours );
	if ( null === $first ) return; // tous les jours fermes : on ne touche pas TEC.

	$fh = $hours[ $first ];
	$lh = $hours[ $last ];

	$start_time = ( ! empty( $fh['allday'] ) || empty( $fh['start'] ) ) ? '00:00:00' : $fh['start'] . ':00';
	$end_time   = ( ! empty( $lh['allday'] ) || empty( $lh['end'] ) ) ? PF_EH_END_SENTINEL : $lh['end'] . ':00';

	$start_local = $first . ' ' . $start_time; // Ymd H:i:s
	$end_local   = $last . ' ' . $end_time;

	$start_str = DateTime::createFromFormat( 'Ymd H:i:s', $start_local )->format( 'Y-m-d H:i:s' );
	$end_str   = DateTime::createFromFormat( 'Ymd H:i:s', $end_local )->format( 'Y-m-d H:i:s' );

	// All-day natif : seulement si mono-jour ouvert et journee entiere.
	$all_day = ( $first === $last && ! empty( $fh['allday'] ) );

	$tz = pf_event_timezone( $event_id );
	$start_obj = new DateTime( $start_str, $tz );
	$end_obj   = new DateTime( $end_str, $tz );
	$start_utc = ( clone $start_obj )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	$end_utc   = ( clone $end_obj )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	$duration  = max( 0, $end_obj->getTimestamp() - $start_obj->getTimestamp() );

	update_post_meta( $event_id, '_EventStartDate', $start_str );
	update_post_meta( $event_id, '_EventEndDate', $end_str );
	update_post_meta( $event_id, '_EventStartDateUTC', $start_utc );
	update_post_meta( $event_id, '_EventEndDateUTC', $end_utc );
	update_post_meta( $event_id, '_EventDuration', (string) $duration );
	update_post_meta( $event_id, '_EventAllDay', $all_day ? 'yes' : '' );
}

/**
 * Fuseau de l'evenement (jamais les champs ...UTC importes, faux).
 */
function pf_event_timezone( int $event_id ): DateTimeZone {
	$tz = (string) get_post_meta( $event_id, '_EventTimezone', true );
	if ( '' === $tz ) $tz = wp_timezone_string();
	try {
		return new DateTimeZone( $tz );
	} catch ( Exception $ex ) {
		return wp_timezone();
	}
}

/* ---------------------------------------------------------------------------
 * AJAX : regenerer les lignes d'apres une plage de dates (lue cote client).
 * ------------------------------------------------------------------------- */

add_action( 'wp_ajax_pf_event_rebuild_days', function () {
	check_ajax_referer( 'pf_event_hours_ajax', 'nonce' );

	$post_id = (int) ( $_POST['post_id'] ?? 0 );
	$start   = preg_replace( '/\D/', '', (string) ( $_POST['start'] ?? '' ) );
	$end     = preg_replace( '/\D/', '', (string) ( $_POST['end'] ?? '' ) );

	if ( ! preg_match( '/^\d{8}$/', $start ) ) wp_send_json_error( 'start invalide' );
	if ( ! preg_match( '/^\d{8}$/', $end ) ) $end = $start;

	// Valeurs en cours de saisie (DOM) : prioritaires sur le stocke/derive pour ne rien perdre.
	$current = isset( $_POST['current'] ) && is_array( $_POST['current'] ) ? wp_unslash( $_POST['current'] ) : [];
	$base    = pf_event_daily_hours_for( $post_id, $start, $end );

	$html = '';
	foreach ( pf_event_days_between( $start, $end ) as $ymd ) {
		if ( isset( $current[ $ymd ] ) && is_array( $current[ $ymd ] ) ) {
			$h = pf_event_normalize_day( $current[ $ymd ] );
		} else {
			$h = $base[ $ymd ] ?? pf_event_normalize_day( [] );
		}
		$html .= pf_event_hours_row_html( (string) $ymd, $h );
	}

	wp_send_json_success( [ 'html' => $html ] );
} );

/* ---------------------------------------------------------------------------
 * ICS : generateur partage (un VEVENT par jour) + interception du flux natif.
 * ------------------------------------------------------------------------- */

/**
 * Echappe une valeur de propriete iCal (RFC 5545).
 */
function pf_ics_escape( string $value ): string {
	$value = str_replace( [ '\\', "\r\n", "\n", "\r", ';', ',' ], [ '\\\\', '\\n', '\\n', '\\n', '\\;', '\\,' ], $value );
	return $value;
}

/**
 * Plie une ligne iCal a 75 octets (RFC 5545), continuation indentee d'un espace.
 */
function pf_ics_fold( string $line ): string {
	if ( strlen( $line ) <= 75 ) return $line;
	$out = '';
	$pos = 0;
	$len = strlen( $line );
	$first = true;
	while ( $pos < $len ) {
		$chunk = $first ? 75 : 74;
		$out  .= ( $first ? '' : "\r\n " ) . substr( $line, $pos, $chunk );
		$pos  += $chunk;
		$first = false;
	}
	return $out;
}

/**
 * Construit une ligne "PROP:VALUE" pliee.
 */
function pf_ics_line( string $prop, string $value ): string {
	return pf_ics_fold( $prop . ':' . $value );
}

/**
 * Donnees communes d'un evenement pour l'ICS.
 */
function pf_event_ics_common( int $event_id ): array {
	$host = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'editions-passiflore';

	$decode  = static function ( $s ) {
		return trim( html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' ) );
	};

	$summary = $decode( get_the_title( $event_id ) );

	$post    = get_post( $event_id );
	$raw     = $post && has_excerpt( $event_id ) ? get_the_excerpt( $event_id ) : ( $post->post_content ?? '' );
	$desc    = $decode( $raw );

	$location = '';
	if ( function_exists( 'tribe_get_venue_id' ) && tribe_get_venue_id( $event_id ) ) {
		$venue_id = tribe_get_venue_id( $event_id );
		// Lieu sans nom propre (case « Ce lieu n'a pas de nom », inc/venue-admin.php) :
		// le Titre du lieu EST déjà l'adresse -> ne pas la répéter dans LOCATION.
		$name_is_address = (bool) get_post_meta( $venue_id, '_VenueNameIsAddress', true );
		$location = $decode( implode( ', ', array_filter( [
			tribe_get_venue( $event_id ),
			$name_is_address ? '' : tribe_get_address( $event_id ),
			tribe_get_city( $event_id ),
		] ) ) );
	}

	$modified = get_post_field( 'post_modified_gmt', $event_id );
	$last_mod = $modified ? gmdate( 'Ymd\THis\Z', strtotime( $modified . ' UTC' ) ) : gmdate( 'Ymd\THis\Z' );
	$sequence = $modified ? (string) strtotime( $modified . ' UTC' ) : '0';

	return [
		'host'     => $host,
		'summary'  => $summary,
		'desc'     => $desc,
		'location' => $location,
		'url'      => get_permalink( $event_id ),
		'last_mod' => $last_mod,
		'sequence' => $sequence,
		'dtstamp'  => gmdate( 'Ymd\THis\Z' ),
	];
}

/**
 * Assemble un bloc VEVENT a partir d'un tableau de proprietes deja construites.
 *
 * @param array $props Tableau de lignes "PROP:VALUE" (deja construites et pliees).
 */
function pf_ics_vevent( array $lines ): string {
	return "BEGIN:VEVENT\r\n" . implode( "\r\n", $lines ) . "\r\nEND:VEVENT";
}

/**
 * Genere les VEVENT d'un evenement.
 * - mono-jour ou sans horaires par jour reels : 1 VEVENT (equivalent natif).
 * - multi-jours avec horaires : 1 VEVENT par jour ouvert.
 *
 * @return string[]
 */
function pf_event_vevents( int $event_id ): array {
	$c  = pf_event_ics_common( $event_id );
	$tz = pf_event_timezone( $event_id );

	$base = static function ( string $uid ) use ( $c ) {
		$lines = [
			pf_ics_line( 'UID', $uid ),
			pf_ics_line( 'DTSTAMP', $c['dtstamp'] ),
			pf_ics_line( 'SUMMARY', pf_ics_escape( $c['summary'] ) ),
		];
		if ( $c['desc'] !== '' )     $lines[] = pf_ics_line( 'DESCRIPTION', pf_ics_escape( $c['desc'] ) );
		if ( $c['location'] !== '' ) $lines[] = pf_ics_line( 'LOCATION', pf_ics_escape( $c['location'] ) );
		if ( $c['url'] )             $lines[] = pf_ics_line( 'URL;VALUE=URI', $c['url'] );
		$lines[] = pf_ics_line( 'LAST-MODIFIED', $c['last_mod'] );
		$lines[] = pf_ics_line( 'SEQUENCE', $c['sequence'] );
		return $lines;
	};

	$to_utc = static function ( string $ymd, string $his ) use ( $tz ) {
		$dt = DateTime::createFromFormat( 'Ymd H:i:s', $ymd . ' ' . $his, $tz );
		$dt->setTimezone( new DateTimeZone( 'UTC' ) );
		return $dt->format( 'Ymd\THis\Z' );
	};

	// Minuit (fin de journee) du jour donne = 00:00 du lendemain, en UTC.
	$end_of_day_utc = static function ( string $ymd ) use ( $to_utc ) {
		$next = DateTime::createFromFormat( 'Ymd', $ymd )->modify( '+1 day' )->format( 'Ymd' );
		return $to_utc( $next, '00:00:00' );
	};

	$hours = pf_event_get_daily_hours( $event_id );

	// Cas simple : pas de planning par jour reel -> 1 VEVENT depuis les bornes natives.
	if ( count( $hours ) < 2 ) {
		$parts   = pf_event_native_time_parts( $event_id );
		$s        = (string) get_post_meta( $event_id, '_EventStartDate', true );
		$e        = (string) get_post_meta( $event_id, '_EventEndDate', true );
		$start_dt = $s ? DateTime::createFromFormat( 'Y-m-d H:i:s', $s ) : false;
		$end_dt   = $e ? DateTime::createFromFormat( 'Y-m-d H:i:s', $e ) : false;
		if ( ! $start_dt ) return [];

		$lines = $base( $event_id . '@' . $c['host'] );

		if ( $parts['allday'] ) {
			$start_date = $start_dt->format( 'Ymd' );
			$end_date   = ( $end_dt ? clone $end_dt : clone $start_dt );
			$end_date->modify( '+1 day' );
			$lines[] = pf_ics_line( 'DTSTART;VALUE=DATE', $start_date );
			$lines[] = pf_ics_line( 'DTEND;VALUE=DATE', $end_date->format( 'Ymd' ) );
		} else {
			$lines[] = pf_ics_line( 'DTSTART', $to_utc( $start_dt->format( 'Ymd' ), $start_dt->format( 'H:i:s' ) ) );
			if ( null !== $parts['end'] && $end_dt ) {
				$lines[] = pf_ics_line( 'DTEND', $to_utc( $end_dt->format( 'Ymd' ), $end_dt->format( 'H:i:s' ) ) );
			} else {
				// Pas d'heure de fin : se termine a minuit (fin du jour de fin natif).
				$end_day = $end_dt ? $end_dt->format( 'Ymd' ) : $start_dt->format( 'Ymd' );
				$lines[] = pf_ics_line( 'DTEND', $end_of_day_utc( $end_day ) );
			}
		}
		return [ pf_ics_vevent( $lines ) ];
	}

	// Multi-jours avec horaires : 1 VEVENT par jour ouvert.
	$vevents = [];
	foreach ( $hours as $ymd => $h ) {
		if ( ! empty( $h['closed'] ) ) continue;

		$lines = $base( $event_id . '-' . $ymd . '@' . $c['host'] );

		if ( ! empty( $h['allday'] ) ) {
			$next = DateTime::createFromFormat( 'Ymd', $ymd )->modify( '+1 day' )->format( 'Ymd' );
			$lines[] = pf_ics_line( 'DTSTART;VALUE=DATE', $ymd );
			$lines[] = pf_ics_line( 'DTEND;VALUE=DATE', $next );
		} else {
			$start = ! empty( $h['start'] ) ? $h['start'] . ':00' : '00:00:00';
			$lines[] = pf_ics_line( 'DTSTART', $to_utc( $ymd, $start ) );
			if ( ! empty( $h['end'] ) ) {
				$lines[] = pf_ics_line( 'DTEND', $to_utc( $ymd, $h['end'] . ':00' ) );
			} else {
				// Pas d'heure de fin : se termine a minuit (fin de la meme journee).
				$lines[] = pf_ics_line( 'DTEND', $end_of_day_utc( $ymd ) );
			}
		}
		$vevents[] = pf_ics_vevent( $lines );
	}
	return $vevents;
}

/**
 * Enveloppe VCALENDAR + envoi HTTP.
 *
 * @param string[] $vevents
 */
function pf_ics_output( array $vevents, bool $download = false ): void {
	$cal_name = wp_strip_all_tags( get_bloginfo( 'name' ) );

	$lines   = [];
	$lines[] = 'BEGIN:VCALENDAR';
	$lines[] = 'VERSION:2.0';
	$lines[] = 'PRODID:-//Editions Passiflore//FR';
	$lines[] = 'CALSCALE:GREGORIAN';
	$lines[] = 'METHOD:PUBLISH';
	$lines[] = pf_ics_line( 'X-WR-CALNAME', pf_ics_escape( $cal_name ) );
	$lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT1H';
	$lines[] = 'X-PUBLISHED-TTL:PT1H';
	foreach ( $vevents as $ve ) {
		$lines[] = $ve;
	}
	$lines[] = 'END:VCALENDAR';

	$body = implode( "\r\n", $lines ) . "\r\n";

	if ( ! headers_sent() ) {
		header( 'Content-Type: text/calendar; charset=utf-8' );
		if ( $download ) {
			header( 'Content-Disposition: attachment; filename="passiflore.ics"' );
		}
		header( 'X-Robots-Tag: noindex' );
	}
	echo $body;
	exit;
}

/**
 * Interception du flux natif `?ical=1` / `?outlook-ical=1`, avant le handler de TEC (@10).
 */
add_action( 'template_redirect', function () {
	$is_ical    = ! empty( $_GET['ical'] ) || (int) get_query_var( 'ical' ) === 1;
	$is_outlook = ! empty( $_GET['outlook-ical'] ) || ! empty( get_query_var( 'outlook-ical' ) );
	if ( ! $is_ical && ! $is_outlook ) return;

	// Evenement seul : on ne reprend la main que si planning par jour reel.
	if ( is_singular( 'tribe_events' ) ) {
		$event_id = get_queried_object_id();
		if ( ! pf_event_has_daily_hours( (int) $event_id ) ) return; // TEC gere (non-regression).

		pf_ics_output( pf_event_vevents( (int) $event_id ), true );
	}

	// Flux global (page liste / calendrier) : on reprend toute la selection.
	if ( ! function_exists( 'tribe_get_events' ) ) return;

	$args = [
		'posts_per_page' => (int) apply_filters( 'tribe_ical_feed_posts_per_page', 30 ),
		'start_date'     => current_time( 'Y-m-d H:i:s' ),
		'eventDisplay'   => 'list',
		'orderby'        => 'event_date',
		'order'          => 'ASC',
	];
	if ( ! empty( $_GET['tribe-bar-date'] ) ) {
		$args['start_date'] = sanitize_text_field( wp_unslash( $_GET['tribe-bar-date'] ) );
	}
	if ( ! empty( $_GET['tribe_events_cat'] ) ) {
		$args['tax_query'] = [ [
			'taxonomy' => 'tribe_events_cat',
			'field'    => 'slug',
			'terms'    => array_map( 'sanitize_title', (array) explode( ',', wp_unslash( $_GET['tribe_events_cat'] ) ) ),
		] ];
	}
	if ( ! empty( $_GET['post_tag'] ) ) {
		$args['tag'] = sanitize_text_field( wp_unslash( $_GET['post_tag'] ) );
	}

	$events  = tribe_get_events( $args );
	$vevents = [];
	foreach ( $events as $ev ) {
		foreach ( pf_event_vevents( (int) $ev->ID ) as $ve ) {
			$vevents[] = $ve;
		}
	}

	pf_ics_output( $vevents, $is_outlook );
}, 5 );

/* ---------------------------------------------------------------------------
 * Enrichissement des liens "Ajouter au calendrier" Google / Outlook (evenement seul) :
 * plage 1er jour -> dernier jour (deja resynchronisee) + detail des horaires en notes.
 * ------------------------------------------------------------------------- */

add_filter( 'tec_views_v2_single_event_gcal_link_parameters', function ( $pieces, $event ) {
	$event_id = is_object( $event ) ? (int) $event->ID : (int) $event;
	$text     = pf_event_hours_text( $event_id );
	if ( '' === $text ) return $pieces;

	// $pieces['details'] est deja urlencode() ; on prefixe avec notre texte encode.
	$prefix = rawurlencode( $text . "\n\n" );
	// rawurlencode encode l'espace en %20, urlencode en + : on aligne sur urlencode.
	$prefix = str_replace( '%20', '+', $prefix );
	$prefix = str_replace( '%0A', '%0D%0A', $prefix );
	$pieces['details'] = $prefix . ( $pieces['details'] ?? '' );

	return $pieces;
}, 10, 2 );

add_filter( 'tec_events_ical_outlook_single_event_import_url', function ( $url, $base_url, $params, $instance ) {
	// Retrouve l'evenement courant (le lien est rendu sur la fiche).
	$event_id = get_queried_object_id();
	if ( ! $event_id || ! pf_event_has_daily_hours( (int) $event_id ) ) return $url;

	$text = pf_event_hours_text( (int) $event_id );
	if ( '' === $text ) return $url;

	$body = $text . "\n\n";
	if ( ! empty( $params['body'] ) ) {
		$body .= rawurldecode( str_replace( '%20', ' ', (string) $params['body'] ) );
	}
	// Outlook accepte %0D%0A pour les sauts de ligne ; espaces en %20.
	$params['body'] = str_replace( [ '+', '%0A' ], [ '%20', '%0D%0A' ], rawurlencode( $body ) );

	return add_query_arg( $params, $base_url );
}, 10, 4 );
