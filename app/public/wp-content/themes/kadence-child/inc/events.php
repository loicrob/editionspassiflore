<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'tribe_the_notices', '__return_empty_string' );

add_filter( 'ngettext_with_context', function( $translation, $single, $plural, $number, $context, $domain ) {
    if ( $domain === 'the-events-calendar' && $context === 'category list label' ) {
        return $number <= 1 ? 'Catégorie de l’événement ' : 'Catégories de l’événement ';
    }
    return $translation;
}, 10, 6 );

// Slash final « fantôme » sur la bascule de vue AJAX.
// TEC génère toujours ses URLs canoniques de vue (get_url(true) → get_clean_url())
// avec un slash final. En navigation réelle, redirect_canonical() le retire
// (permaliens du site = /%postname%, sans slash). Mais la bascule Liste↔Mois se
// fait en AJAX : manager.js pousse cette URL telle quelle via history.pushState,
// sans repasser par redirect_canonical → un slash apparaît dans la barre d'adresse,
// uniquement en transition vue TEC → vue TEC (jamais via la Carte, rechargement complet).
// user_trailingslashit() réaligne l'URL sur la préférence de permaliens du site.
// Gardé sur $canonical : la pagination préc./suiv. passe par get_url() sans canonical
// puis filter_next/prev_url, donc n'est pas touchée.
add_filter( 'tribe_events_views_v2_view_url', function( $url, $canonical ) {
    return $canonical ? user_trailingslashit( $url ) : $url;
}, 10, 2 );

add_action( 'wp_footer', function() {
    if ( empty( $_GET['tribe-bar-date'] ) ) return;
    $date_raw = sanitize_text_field( $_GET['tribe-bar-date'] );
    ?>
    <script>
    (function() {
        var dateStr = <?php echo wp_json_encode( $date_raw ); ?>;
        var m = dateStr.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) return;

        var day   = parseInt(m[3], 10);
        var month = parseInt(m[2], 10) - 1;
        var months = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
        if (month < 0 || month > 11) return;

        var dayLabel = day === 1 ? '1er' : String(day);
        var label    = 'À partir du ' + dayLabel + ' ' + months[month];

        function updateBtn() {
            var btn = document.querySelector('.tribe-events-c-top-bar__datepicker-button');
            if (btn && btn.textContent.trim() !== label) {
                btn.textContent = label;
            }
        }

        updateBtn();
        new MutationObserver(updateBtn).observe(document.body, { childList: true, subtree: true });
    })();
    </script>
    <?php
} );

// Jointure "X, Y et Z" partagée par la phrase pf-event-participants (day view, single event)
// et la valeur "Personnes présentes" des tiles liste/recherche (voir passiflore_render_event_card_meta).
function passiflore_join_with_et( array $parts ) {
    if ( empty( $parts ) ) return '';
    if ( count( $parts ) === 1 ) return $parts[0];
    if ( count( $parts ) === 2 ) return $parts[0] . ' et ' . $parts[1];
    $last = array_pop( $parts );
    return implode( ', ', $parts ) . ' et ' . $last;
}

// Icône "lieu" partagée par la ligne meta des tiles (liste/recherche) et des cards
// événement (Passiflore_Event_Tiles::render_tile).
function pf_lieu_icon_svg() {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="M480-186q122-112 181-203.5T720-552q0-109-69.5-178.5T480-800q-101 0-170.5 69.5T240-552q0 71 59 162.5T480-186Zm-28 74q-14-5-25-15-65-60-115-117t-83.5-110.5q-33.5-53.5-51-103T160-552q0-150 96.5-239T480-880q127 0 223.5 89T800-552q0 45-17.5 94.5t-51 103Q698-301 648-244T533-127q-11 10-25 15t-28 5q-14 0-28-5Zm28-448Zm56.5 56.5Q560-527 560-560t-23.5-56.5Q513-640 480-640t-56.5 23.5Q400-593 400-560t23.5 56.5Q447-480 480-480t56.5-23.5Z"/></svg>';
}

// Icône "date" (horloge) de la ligne meta date/heure des cards événement
// (Passiflore_Event_Tiles::render_tile).
function pf_date_icon_svg() {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="M200-80q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-800h40v-40q0-17 11.5-28.5T280-880q17 0 28.5 11.5T320-840v40h320v-40q0-17 11.5-28.5T680-880q17 0 28.5 11.5T720-840v40h40q33 0 56.5 23.5T840-720v560q0 33-23.5 56.5T760-80H200Zm0-80h560v-400H200v400Zm0-480h560v-80H200v80Zm0 0v-80 80Zm280 240q-17 0-28.5-11.5T440-440q0-17 11.5-28.5T480-480q17 0 28.5 11.5T520-440q0 17-11.5 28.5T480-400Zm-188.5-11.5Q280-423 280-440t11.5-28.5Q303-480 320-480t28.5 11.5Q360-457 360-440t-11.5 28.5Q337-400 320-400t-28.5-11.5ZM640-400q-17 0-28.5-11.5T600-440q0-17 11.5-28.5T640-480q17 0 28.5 11.5T680-440q0 17-11.5 28.5T640-400ZM480-240q-17 0-28.5-11.5T440-280q0-17 11.5-28.5T480-320q17 0 28.5 11.5T520-280q0 17-11.5 28.5T480-240Zm-188.5-11.5Q280-263 280-280t11.5-28.5Q303-320 320-320t28.5 11.5Q360-297 360-280t-11.5 28.5Q337-240 320-240t-28.5-11.5ZM640-240q-17 0-28.5-11.5T600-280q0-17 11.5-28.5T640-320q17 0 28.5 11.5T680-280q0 17-11.5 28.5T640-240Z"/></svg>';
}

/**
 * Nom + ville (+ département entre parenthèses) du lieu d'un événement, pour la ligne
 * meta "Lieu" partagée par les tiles (liste/recherche) et les cards événement.
 */
function pf_get_event_venue_parts( int $event_id ): array {
    $name = '';
    $city = '';
    if ( function_exists( 'tribe_get_venue_id' ) ) {
        $venue_id = tribe_get_venue_id( $event_id );
        if ( $venue_id ) {
            $name = (string) tribe_get_venue( $event_id );
            $city = (string) tribe_get_city( $event_id );
            if ( $city !== '' && function_exists( 'pf_venue_departement_code' ) ) {
                $dept = pf_venue_departement_code( (string) get_post_meta( $venue_id, '_VenueDepartement', true ) );
                if ( $dept !== '' ) $city .= ' (' . $dept . ')';
            }
        }
    }
    return [ $name, $city ];
}

/**
 * Ligne meta générique (icône + valeur, sans label) : ligne principale, puis une
 * sous-ligne optionnelle en plus petit (ville, heure…), toutes deux vertical-centrées
 * avec l'icône. Partagée par les cards événement (Passiflore_Event_Tiles) et le bloc
 * meta des tiles liste/recherche (passiflore_render_event_card_meta).
 */
function pf_render_meta_row( string $icon_svg, string $main, string $sub = '', string $modifier = '' ): string {
    if ( $main === '' && $sub === '' ) return '';
    $row_class = 'pf-event-card-meta-row pf-event-card-meta-row--nolabel'
        . ( $modifier !== '' ? ' pf-event-card-meta-row--' . $modifier : '' );
    ob_start();
    ?>
    <p class="<?php echo esc_attr( $row_class ); ?>">
        <span class="pf-event-card-meta-icon"><?php echo $icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput — SVG interne de confiance ?></span>
        <span class="pf-card-text">
            <span class="pf-event-card-meta-name"><?php echo esc_html( $main ); ?></span>
            <?php if ( $sub !== '' ) : ?>
            <span class="pf-event-card-meta-sub"><?php echo esc_html( $sub ); ?></span>
            <?php endif; ?>
        </span>
    </p>
    <?php
    return ob_get_clean();
}

/**
 * Ligne meta "Lieu" : nom du lieu, puis ville (+ département) en dessous en plus petit.
 */
function pf_render_lieu_meta_row( string $name, string $city ): string {
    return pf_render_meta_row( pf_lieu_icon_svg(), $name, $city, 'lieu' );
}

function passiflore_get_event_participant_parts( $event_id, $mode = 'link' ) {
    $passiflore_participe = (bool) get_field( 'passiflore_participe', $event_id );
    $rows                 = get_field( 'personnes_participant_a_l’evenement', $event_id );
    if ( ! is_array( $rows ) ) $rows = [];

    $parts = [];

    if ( $passiflore_participe ) {
        $parts[] = 'Passiflore';
    }

    foreach ( $rows as $row ) {
        $assignation = $row['assignation'] ?? '';
        if ( $assignation === 'fiche-auteur' ) {
            $tid = $row['fiche_auteur'] ?? null;
            if ( is_array( $tid ) ) $tid = reset( $tid );
            $tid = (int) $tid;
            if ( $tid > 0 ) {
                $term = get_term( $tid, 'auteur' );
                if ( $term && ! is_wp_error( $term ) ) {
                    // Mode 'text' : nom de l'auteur en texte simple (non cliquable).
                    if ( 'text' === $mode ) {
                        $parts[] = esc_html( $term->name );
                    } else {
                        $url     = get_term_link( $term, 'auteur' );
                        $parts[] = '<a href="' . esc_url( $url ) . '" class="pf-event-participants-auteur">' . esc_html( $term->name ) . '</a>';
                    }
                }
            }
        } elseif ( $assignation === 'champ-texte' ) {
            $nom = trim( (string) ( $row['nom_de_la_personne'] ?? '' ) );
            if ( $nom !== '' ) $parts[] = esc_html( $nom );
        }
    }

    return $parts;
}

function passiflore_render_event_participants( $event_id, $mode = 'link' ) {
    $parts = passiflore_get_event_participant_parts( $event_id, $mode );
    if ( empty( $parts ) ) return '';

    return '<div class="pf-event-participants"><p>Présence de ' . passiflore_join_with_et( $parts ) . '.</p></div>';
}

/**
 * Contenu principal d'un événement pour les tiles (liste + recherche + cards
 * Passiflore_Event_Tiles) : texte aplati (balises de bloc retirées) avec gras/italique
 * conservés. Pas de découpe en PHP — la troncature est purement visuelle (CSS).
 *
 * @param WP_Post $event N'importe quel objet exposant ->post_content (WP_Post natif ou
 *                        décoré par tribe_get_event()).
 */
function passiflore_render_event_description_text( $event ) {
    $content = (string) $event->post_content;
    if ( '' === trim( $content ) ) return '';

    if ( function_exists( 'excerpt_remove_blocks' ) ) {
        $content = excerpt_remove_blocks( $content );
    }

    // Shortcodes retirés bruts (pas exécutés) — même logique que tribe_events_get_the_excerpt().
    $content = preg_replace( '#\[.+\]#U', '', $content );

    $content = wp_kses( $content, [
        'strong' => [],
        'b'      => [],
        'em'     => [],
        'i'      => [],
    ] );

    return trim( preg_replace( '/\s+/', ' ', $content ) );
}

/**
 * Bloc Lieu / Organisateur / Personnes présentes des tiles (liste + recherche), en
 * remplacement de l'adresse complète rendue nativement par TEC (list/event/venue.php).
 * Pattern lieu = nom + ville, identique à Passiflore_Event_Tiles::render_tile() (tuiles
 * auteurs/accueil) — pas l'adresse complète.
 *
 * @param WP_Post $event Objet event décoré par tribe_get_event() (->venues, ->organizer_names).
 */
function passiflore_render_event_card_meta( $event ) {
    $icons = [
        'lieu'         => pf_lieu_icon_svg(),
        'organisateur' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="M287-527q-47-47-47-113t47-113q47-47 113-47t113 47q47 47 47 113t-47 113q-47 47-113 47t-113-47ZM80-240v-32q0-33 17-62t47-44q58-29 120-45.5T391-440q17 0 28.5 12t11.5 29q0 17-11.5 28.5T391-359q-56 0-108.5 14T180-306q-10 5-15 14t-5 20v32h231q17 0 28.5 11.5T431-200q0 17-11.5 28.5T391-160H160q-33 0-56.5-23.5T80-240Zm554 88-6-28q-12-5-22.5-10.5T584-204l-29 9q-13 4-25.5-1T510-212l-8-14q-7-12-5-26t13-23l22-19q-2-14-2-26t2-26l-22-19q-11-9-13-22.5t5-25.5l9-15q7-11 19-16t25-1l29 9q11-8 21.5-13.5T628-460l6-29q3-14 13.5-22.5T672-520h16q14 0 24.5 9t13.5 23l6 28q12 5 22.5 11t21.5 15l27-9q14-5 27 0t20 17l8 14q7 12 5 26t-13 23l-22 19q2 12 2 25t-2 25l22 19q11 9 13 22.5t-5 25.5l-9 15q-7 11-19 16t-25 1l-29-9q-11 8-21.5 13.5T732-180l-6 29q-3 14-13.5 22.5T688-120h-16q-14 0-24.5-9T634-152Zm102.5-111.5Q760-287 760-320t-23.5-56.5Q713-400 680-400t-56.5 23.5Q600-353 600-320t23.5 56.5Q647-240 680-240t56.5-23.5Zm-280-320Q480-607 480-640t-23.5-56.5Q433-720 400-720t-56.5 23.5Q320-673 320-640t23.5 56.5Q367-560 400-560t56.5-23.5ZM400-640Zm12 400Z"/></svg>',
        'presence'     => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="M360-120v-489q-83-22-135.5-88T162-847q-2-14 10-23.5t28-9.5q16 0 28 8.5t14 23.5q11 72 62 120t126 48h100q30 0 56 11t47 32l153 153q11 11 11 28t-11 28q-11 11-28 11t-28-11L600-558v438q0 17-11.5 28.5T560-80q-17 0-28.5-11.5T520-120v-200h-80v200q0 17-11.5 28.5T400-80q-17 0-28.5-11.5T360-120Zm63.5-623.5Q400-767 400-800t23.5-56.5Q447-880 480-880t56.5 23.5Q560-833 560-800t-23.5 56.5Q513-720 480-720t-56.5-23.5Z"/></svg>',
    ];

    $rows = [];

    if ( $event->venues->count() ) {
        $venue = $event->venues[0];
        $city  = (string) $venue->city;
        if ( $city !== '' && function_exists( 'pf_venue_departement_code' ) ) {
            $dept = pf_venue_departement_code( (string) get_post_meta( $venue->ID, '_VenueDepartement', true ) );
            if ( $dept !== '' ) $city .= ' (' . $dept . ')';
        }
        $lieu = array_filter( [ (string) $venue->post_title, $city ] );
        if ( $lieu ) $rows[] = [ 'icon' => 'lieu', 'label' => 'Lieu', 'value' => implode( ', ', $lieu ) ];
    }

    $organisateurs = array_filter( $event->organizer_names->all() );
    if ( $organisateurs ) $rows[] = [ 'icon' => 'organisateur', 'label' => 'Organisateur', 'value' => implode( ', ', $organisateurs ) ];

    if ( function_exists( 'passiflore_get_event_participant_parts' ) ) {
        $parts = passiflore_get_event_participant_parts( $event->ID, 'text' );
        if ( $parts ) $rows[] = [ 'icon' => 'presence', 'label' => 'Participants', 'value' => passiflore_join_with_et( $parts ) ];
    }

    if ( empty( $rows ) ) return '';

    ob_start();
    ?>
    <div class="pf-event-card-meta">
        <?php foreach ( $rows as $row ) : ?>
        <p class="pf-event-card-meta-row">
            <span class="pf-event-card-meta-icon"><?php echo $icons[ $row['icon'] ]; ?></span>
            <span class="pf-label"><?php echo esc_html( $row['label'] ); ?></span>
            <span class="pf-card-text"><?php echo esc_html( $row['value'] ); ?></span>
        </p>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

function passiflore_render_event_participants_tiles( int $event_id ): string {
    $passiflore_participe = (bool) get_field( 'passiflore_participe', $event_id );
    $rows                 = get_field( 'personnes_participant_a_l’evenement', $event_id );
    if ( ! is_array( $rows ) ) $rows = [];

    if ( ! $passiflore_participe && empty( $rows ) ) return '';

    $svg = '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="width:100%;height:100%;display:block"><rect width="100" height="100" fill="#f0ebe3"/><circle cx="50" cy="37" r="20" fill="#c0ad8e"/><ellipse cx="50" cy="88" rx="34" ry="24" fill="#c0ad8e"/></svg>';

    ob_start();

    echo '<div class="pf-event-presence-tiles">';
    echo '<div class="pf-scroll-fade"><div class="pf-event-auteurs-scroll pf-event-tiles-scroll pf-hscroll">';

    if ( $passiflore_participe ) {
        echo '<div class="pf-card pf-card--static pf-auteur-card pf-card--compact pf-auteur-card--passiflore">';
        echo '<div class="pf-auteur-photo"><img src="' . esc_url( content_url( 'uploads/2026/04/cropped-icone.png' ) ) . '" alt="" aria-hidden="true" /></div>';
        echo '<div class="pf-card-content"><span class="pf-card-title"><span class="pf-auteur-nom-de-famille">Passiflore</span></span><p class="pf-card-text">Une ou plusieurs personnes de l’équipe seront présentes.</p></div>';
        echo '</div>';
    }

    foreach ( $rows as $row ) {
        $assignation = $row['assignation'] ?? '';
        if ( $assignation === 'fiche-auteur' ) {
            $tid = $row['fiche_auteur'] ?? null;
            if ( is_array( $tid ) ) $tid = reset( $tid );
            $tid = (int) $tid;
            if ( $tid > 0 ) echo passiflore_render_auteur_card( $tid, [ 'variant' => 'compact', 'show_bio' => true ] );
        } elseif ( $assignation === 'champ-texte' ) {
            $nom = trim( (string) ( $row['nom_de_la_personne'] ?? '' ) );
            if ( $nom !== '' ) {
                echo '<div class="pf-card pf-card--static pf-auteur-card pf-card--compact pf-auteur-card--texte">';
                echo '<div class="pf-auteur-photo">' . $svg . '</div>';
                echo '<div class="pf-card-content"><span class="pf-card-title"><span>' . esc_html( $nom ) . '</span></span></div>';
                echo '</div>';
            }
        }
    }

    echo '</div></div></div>';

    return ob_get_clean();
}

// Rendu en section « Présence » via l'override tribe-events/single-event.php
// (passiflore_render_event_sections, inc/event-single.php) — plus d'injection après le contenu.

/**
 * Tableau "Jour -> horaire" sous le hero, uniquement si l'evenement a un planning
 * par jour reel (saisi via la meta box "Horaires par jour"), sur au moins 2 jours.
 */
function passiflore_render_event_hours( $event_id ) {
	if ( ! function_exists( 'pf_event_get_daily_hours' ) ) return '';
	$hours = pf_event_get_daily_hours( (int) $event_id );
	if ( count( $hours ) < 2 ) return '';
	if ( ! pf_event_daily_hours_informative( $hours ) ) return '';

	ob_start();
	echo '<div class="pf-event-horaires">';
	echo '<ul class="pf-event-horaires-liste">';
	foreach ( $hours as $ymd => $h ) {
		$label = ucfirst( date_i18n( 'l j F', (int) strtotime( (string) $ymd ) ) );
		$slot  = ! empty( $h['closed'] ) ? 'Fermé' : pf_event_day_slot_label( $h );
		if ( '' === $slot ) $slot = '—';
		$closed_cls = ! empty( $h['closed'] ) ? ' is-closed' : '';
		echo '<li class="pf-event-horaire' . $closed_cls . '"><span class="pf-eh-jour">' . esc_html( $label ) . '</span><span class="pf-eh-creneau">' . esc_html( $slot ) . '</span></li>';
	}
	echo '</ul>';
	echo '</div>';
	return ob_get_clean();
}

// Rendu en section « Horaires » via l'override tribe-events/single-event.php.

/**
 * Reecrit la chaine de schedule (titre / H2) — meme pattern que les cards evenement
 * (Passiflore_Event_Tiles::render_tile), a deux differences pres : jour de semaine
 * conserve sur le cas mono-jour, et phrase "Du … au …" sur les cas multi-jours.
 * Toujours applique (plus de repli sur le format natif TEC).
 */
add_filter( 'tribe_events_event_schedule_details', function ( $schedule, $event_id, $before, $after ) {
	$s  = (string) get_post_meta( $event_id, '_EventStartDate', true );
	$e  = (string) get_post_meta( $event_id, '_EventEndDate', true );
	$sd = $s ? DateTime::createFromFormat( 'Y-m-d H:i:s', $s ) : false;
	$ed = $e ? DateTime::createFromFormat( 'Y-m-d H:i:s', $e ) : false;
	if ( ! $sd || ! $ed ) return $schedule;

	$start_ts = $sd->getTimestamp();
	$end_ts   = $ed->getTimestamp();
	$allday   = (bool) get_post_meta( $event_id, '_EventAllDay', true );

	// Fin effective : decale de 12h pour neutraliser la sentinelle "23h59 = pas de fin"
	// (evenement mono-jour) sans la confondre avec un veritable evenement multi-jours.
	$eff_end_ts = $end_ts - 3600 * 12;
	$multi_jour = date( 'Ymd', $start_ts ) !== date( 'Ymd', $eff_end_ts );

	$cur_annee   = (int) date( 'Y' );
	$annee_debut = (int) date( 'Y', $start_ts );

	if ( ! $multi_jour ) {
		$str = ucfirst( date_i18n( 'l j F', $start_ts ) ) . ( $annee_debut !== $cur_annee ? ' ' . $annee_debut : '' );
	} else {
		$annee_fin = (int) date( 'Y', $eff_end_ts );
		if ( $annee_debut !== $annee_fin ) {
			$str = 'Du ' . date_i18n( 'j F Y', $start_ts ) . ' au ' . date_i18n( 'j F Y', $eff_end_ts );
		} elseif ( date( 'm', $start_ts ) !== date( 'm', $eff_end_ts ) ) {
			$str = 'Du ' . date_i18n( 'j F', $start_ts ) . ' au ' . date_i18n( 'j F', $eff_end_ts ) . ( $annee_debut !== $cur_annee ? ' ' . $annee_debut : '' );
		} else {
			$str = 'Du ' . date_i18n( 'j', $start_ts ) . ' au ' . date_i18n( 'j F', $eff_end_ts ) . ( $annee_debut !== $cur_annee ? ' ' . $annee_debut : '' );
		}
	}

	// Heure(s) : jamais affichees sur plusieurs jours (planning par jour = son propre tableau
	// sous la section Horaires). Sinon "journee entiere", heure de debut seule, ou plage si
	// une heure de fin reelle (non sentinelle) est renseignee.
	if ( ! $multi_jour ) {
		if ( $allday ) {
			$str .= ' · journée entière';
		} elseif ( function_exists( 'pf_event_format_hm' ) ) {
			$heure = pf_event_format_hm( (int) date( 'G', $start_ts ), (int) date( 'i', $start_ts ) );

			$fin_reelle = $end_ts !== $start_ts
				&& ( ! function_exists( 'pf_event_is_sentinel_time' ) || ! pf_event_is_sentinel_time( $ed->format( 'H:i:s' ) ) );
			if ( $fin_reelle ) {
				$heure .= ' - ' . pf_event_format_hm( (int) date( 'G', $end_ts ), (int) date( 'i', $end_ts ) );
			}

			$str .= ' · ' . $heure;
		}
	}

	return $before . '<span class="tribe-event-date-start">' . esc_html( $str ) . '</span>' . $after;
}, 10, 4 );

/**
 * Ligne meta "Heure" (fiche evenement mono-jour) : masque le "- 23h59" sentinelle,
 * on ne garde que l'heure de debut.
 */
add_filter( 'tribe_events_single_event_time_formatted', function ( $formatted, $event_id ) {
	if ( ! function_exists( 'pf_event_is_sentinel_time' ) ) return $formatted;

	$e  = (string) get_post_meta( $event_id, '_EventEndDate', true );
	$ed = $e ? DateTime::createFromFormat( 'Y-m-d H:i:s', $e ) : false;
	if ( ! $ed || ! pf_event_is_sentinel_time( $ed->format( 'H:i:s' ) ) ) return $formatted;

	$s  = (string) get_post_meta( $event_id, '_EventStartDate', true );
	$sd = $s ? DateTime::createFromFormat( 'Y-m-d H:i:s', $s ) : false;
	if ( ! $sd ) return $formatted;

	return esc_html( date_i18n( get_option( 'time_format' ) ?: 'G\hi', $sd->getTimestamp() ) );
}, 10, 2 );

/**
 * Affichage humain de la date de fin (ligne meta "Fin", etc.) : pour une heure de fin
 * sentinelle (23h59 = "pas d'heure de fin"), on n'affiche que la date, sans l'heure.
 * On ne touche que le format humain par defaut (date_format vide) : les formats machine
 * (ex. 'U', 'Y-m-d') restent intacts.
 */
add_filter( 'tribe_get_end_date', function ( $end_date, $event, $display_time, $date_format ) {
	if ( ! $display_time || '' !== $date_format ) return $end_date;
	if ( ! function_exists( 'pf_event_is_sentinel_time' ) ) return $end_date;

	$eid = is_object( $event ) ? (int) $event->ID : (int) $event;
	if ( ! $eid ) return $end_date;

	$e  = (string) get_post_meta( $eid, '_EventEndDate', true );
	$ed = $e ? DateTime::createFromFormat( 'Y-m-d H:i:s', $e ) : false;
	if ( ! $ed || ! pf_event_is_sentinel_time( $ed->format( 'H:i:s' ) ) ) return $end_date;

	// Re-formate sans l'heure (display_time = false -> ce filtre court-circuite, pas de recursion).
	return tribe_get_end_date( $event, false, $date_format );
}, 10, 4 );

function passiflore_render_event_books( $event_id ) {
    $raw = get_post_meta( $event_id, '_pf_event_books', true );
    if ( ! is_array( $raw ) ) $raw = [];
    $all_ids = array_values( array_filter( array_map( 'intval', $raw ) ) );

    if ( empty( $all_ids ) ) return '';

    ob_start();
    echo '<div class="pf-event-livres">';
    echo do_shortcode( '[passiflore_etagere ids="' . implode( ',', $all_ids ) . '" mode="scroll" display="covers" nb_books_first_displayed="20"]' );
    echo '</div>';
    return ob_get_clean();
}

// Rendu en section « Livres associés » via l'override tribe-events/single-event.php.

function passiflore_get_upcoming_events_for_auteur( $term_id ) {
    global $wpdb;

    $now = current_time( 'mysql' );

    $sql = $wpdb->prepare(
        "SELECT DISTINCT p.ID
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm_auteur
             ON pm_auteur.post_id = p.ID
            AND pm_auteur.meta_key LIKE %s
            AND pm_auteur.meta_value = %s
         INNER JOIN {$wpdb->postmeta} pm_date
             ON pm_date.post_id = p.ID
            AND pm_date.meta_key = %s
         WHERE p.post_type = %s
           AND p.post_status = %s
           AND pm_date.meta_value >= %s
         ORDER BY pm_date.meta_value ASC",
        'personnes_participant_a_l%_fiche_auteur',
        (string) $term_id,
        '_EventStartDate',
        'tribe_events',
        'publish',
        $now
    );

    return array_map( 'intval', $wpdb->get_col( $sql ) );
}

function passiflore_get_upcoming_events() : array {
    $now = current_time( 'mysql' );

    $query = new WP_Query( [
        'post_type'      => 'tribe_events',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_key'       => '_EventStartDate',
        'orderby'        => 'meta_value',
        'order'          => 'ASC',
        'meta_query'     => [
            [
                'key'     => '_EventStartDate',
                'value'   => $now,
                'compare' => '>=',
                'type'    => 'DATETIME',
            ],
        ],
    ] );

    $events = [];

    foreach ( $query->posts as $post ) {
        $id             = $post->ID;
        $start_str      = get_post_meta( $id, '_EventStartDate', true );
        $start_date     = $start_str
            ? DateTime::createFromFormat( 'Y-m-d H:i:s', $start_str )
            : null;

        [ $venue_name, $venue_city ] = pf_get_event_venue_parts( $id );

        $end_str    = get_post_meta( $id, '_EventEndDate', true );
        $end_date   = $end_str ? DateTime::createFromFormat( 'Y-m-d H:i:s', $end_str ) : null;
        $is_all_day = (bool) get_post_meta( $id, '_EventAllDay', true );

        $events[] = [
            'id'         => $id,
            'title'      => get_the_title( $id ),
            'permalink'  => get_permalink( $id ),
            'start_date' => $start_date,
            'end_date'   => $end_date,
            'is_all_day' => $is_all_day,
            'excerpt'    => passiflore_render_event_description_text( $post ),
            'venue_name' => $venue_name,
            'venue_city' => $venue_city,
            'image_id'   => (int) get_post_thumbnail_id( $id ),
        ];
    }

    wp_reset_postdata();
    return $events;
}

function passiflore_get_past_notable_events_for_auteur( $term_id ) {
    global $wpdb;

    $now = current_time( 'mysql' );

    $sql = $wpdb->prepare(
        "SELECT DISTINCT p.ID
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm_auteur
             ON pm_auteur.post_id = p.ID
            AND pm_auteur.meta_key LIKE %s
            AND pm_auteur.meta_value = %s
         INNER JOIN {$wpdb->postmeta} pm_date
             ON pm_date.post_id = p.ID
            AND pm_date.meta_key = %s
         INNER JOIN {$wpdb->postmeta} pm_marquant
             ON pm_marquant.post_id = p.ID
            AND pm_marquant.meta_key = %s
            AND pm_marquant.meta_value = %s
         WHERE p.post_type = %s
           AND p.post_status = %s
           AND pm_date.meta_value < %s
         ORDER BY pm_date.meta_value DESC",
        'personnes_participant_a_l%_fiche_auteur',
        (string) $term_id,
        '_EventStartDate',
        'evenement_marquant',
        '1',
        'tribe_events',
        'publish',
        $now
    );

    return array_map( 'intval', $wpdb->get_col( $sql ) );
}

function passiflore_render_auteur_past_notable_events( $term_id, $nom_complet ) {
    $event_ids = passiflore_get_past_notable_events_for_auteur( $term_id );
    if ( empty( $event_ids ) ) return '';

    $events = array_map( [ Passiflore_Event_Tiles::class, 'normalize_event' ], $event_ids );

    ob_start();
    ?>
    <section class="pf-auteur-events pf-auteur-events--marquants">
        <h2 class="pf-auteur-events-titre">Événements marquants</h2>
        <?php echo Passiflore_Event_Tiles::render_row( $events ); ?>
    </section>
    <?php
    return ob_get_clean();
}

function passiflore_render_auteur_upcoming_events( $term_id, $nom_complet ) {
    $event_ids = passiflore_get_upcoming_events_for_auteur( $term_id );
    if ( empty( $event_ids ) ) return '';

    $events = array_map( [ Passiflore_Event_Tiles::class, 'normalize_event' ], $event_ids );

    ob_start();
    ?>
    <section class="pf-auteur-events">
        <h2 class="pf-auteur-events-titre">Événements à venir</h2>
        <?php echo Passiflore_Event_Tiles::render_row( $events ); ?>
    </section>
    <?php
    return ob_get_clean();
}

class Passiflore_Event_Tiles {

    public static function normalize_event( int $id ): array {
        $post      = get_post( $id );
        $start_str = get_post_meta( $id, '_EventStartDate', true );
        $end_str   = get_post_meta( $id, '_EventEndDate', true );

        [ $venue_name, $venue_city ] = pf_get_event_venue_parts( $id );

        return [
            'id'         => $id,
            'title'      => get_the_title( $id ),
            'permalink'  => get_permalink( $id ),
            'start_date' => $start_str ? DateTime::createFromFormat( 'Y-m-d H:i:s', $start_str ) : null,
            'end_date'   => $end_str   ? DateTime::createFromFormat( 'Y-m-d H:i:s', $end_str )   : null,
            'is_all_day' => (bool) get_post_meta( $id, '_EventAllDay', true ),
            'excerpt'    => passiflore_render_event_description_text( $post ),
            'venue_name' => $venue_name,
            'venue_city' => $venue_city,
            'image_id'   => ( (int) get_post_thumbnail_id( $id ) ) ?: null,
        ];
    }

    public static function render_row( array $events ): string {
        if ( empty( $events ) ) return '';
        ob_start();
        echo '<div class="pf-scroll-fade">';
        echo '<div class="pf-event-tiles-scroll pf-hscroll">';
        foreach ( $events as $event ) {
            echo self::render_tile( $event );
        }
        echo '</div>';
        echo '</div>';
        return ob_get_clean();
    }

    /**
     * @param bool $show_lieu Afficher la ligne meta « Lieu ». Mise à false quand le
     *                        contexte donne déjà le lieu (ex. infobulle carte, dont
     *                        le titre EST le lieu → pas de redite).
     */
    public static function render_tile( array $event, bool $show_lieu = true ): string {
        $ts       = $event['start_date'] instanceof DateTime ? $event['start_date']->getTimestamp() : 0;
        $end_ts   = isset( $event['end_date'] ) && $event['end_date'] instanceof DateTime ? $event['end_date']->getTimestamp() : 0;
        $lieu_row = $show_lieu
            ? pf_render_lieu_meta_row( (string) ( $event['venue_name'] ?? '' ), (string) ( $event['venue_city'] ?? '' ) )
            : '';

        // Fin effective : decale de 12h pour neutraliser la sentinelle "23h59 = pas de fin"
        // (evenement mono-jour) sans la confondre avec un veritable evenement multi-jours.
        $eff_end_ts = $end_ts ? $end_ts - 3600 * 12 : 0;
        $multi_jour = $eff_end_ts && date( 'Ymd', $ts ) !== date( 'Ymd', $eff_end_ts );

        // Date : "j F[ Y]" mono-jour, "j-j F[ Y]" multi-jours meme mois, "j F - j F[ Y]" sinon
        // (tiret espace des que chaque cote porte son propre mois). Annee affichee seulement si
        // differente de l'annee en cours, sauf si debut/fin sont sur des annees differentes.
        $cur_annee   = (int) date( 'Y' );
        $annee_debut = $ts ? (int) date( 'Y', $ts ) : 0;

        if ( ! $multi_jour ) {
            $date_txt = $ts ? date_i18n( 'j F', $ts ) . ( $annee_debut !== $cur_annee ? ' ' . $annee_debut : '' ) : '';
        } else {
            $annee_fin = (int) date( 'Y', $eff_end_ts );
            if ( $annee_debut !== $annee_fin ) {
                $date_txt = date_i18n( 'j F Y', $ts ) . ' - ' . date_i18n( 'j F Y', $eff_end_ts );
            } elseif ( date( 'm', $ts ) !== date( 'm', $eff_end_ts ) ) {
                $date_txt = date_i18n( 'j F', $ts ) . ' - ' . date_i18n( 'j F', $eff_end_ts ) . ( $annee_debut !== $cur_annee ? ' ' . $annee_debut : '' );
            } else {
                $date_txt = date_i18n( 'j', $ts ) . '-' . date_i18n( 'j F', $eff_end_ts ) . ( $annee_debut !== $cur_annee ? ' ' . $annee_debut : '' );
            }
        }

        // Heure(s) : jamais affichees pour un evenement sur plusieurs jours. Sinon, heure de
        // debut seule, ou plage si une heure de fin reelle (non sentinelle) est renseignee.
        $heure = '';
        if ( $ts && ! $multi_jour && empty( $event['is_all_day'] ) && function_exists( 'pf_event_format_hm' ) ) {
            $heure = pf_event_format_hm( (int) date( 'G', $ts ), (int) date( 'i', $ts ) );

            $fin_reelle = $end_ts && $end_ts !== $ts
                && ( ! function_exists( 'pf_event_is_sentinel_time' ) || ! pf_event_is_sentinel_time( date( 'H:i:s', $end_ts ) ) );
            if ( $fin_reelle ) {
                $heure .= '-' . pf_event_format_hm( (int) date( 'G', $end_ts ), (int) date( 'i', $end_ts ) );
            }
        }

        $heure_line = '';
        if ( $heure !== '' ) {
            $heure_line = $heure;
        } elseif ( ! $multi_jour && ! empty( $event['is_all_day'] ) ) {
            $heure_line = 'journée entière';
        }

        $date_row = $ts ? pf_render_meta_row( pf_date_icon_svg(), $date_txt, $heure_line ) : '';

        ob_start();
        ?>
        <a class="pf-card pf-card--compact pf-event-tile" href="<?php echo esc_url( $event['permalink'] ); ?>">

            <?php if ( ! empty( $event['image_id'] ) ) : ?>
            <div class="pf-event-tile__image" aria-hidden="true">
                <?php echo wp_get_attachment_image( $event['image_id'], 'medium', false, [
                    'loading' => 'lazy',
                    'alt'     => '',
                ] ); ?>
            </div>
            <?php else : ?>
            <div class="pf-event-tile__image pf-event-tile__image--placeholder" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="M240-80q-33 0-56.5-23.5T160-160v-560q0-33 23.5-56.5T240-800h40v-80h80v80h240v-80h80v80h40q33 0 56.5 23.5T800-720v560q0 33-23.5 56.5T720-80H240Zm0-80h480v-400H240v400Zm0-480h480v-80H240v80Zm0 0v-80 80Z"/></svg>
            </div>
            <?php endif; ?>

            <div class="pf-card-content pf-card-content--separated">

            <div class="pf-event-info">
                <h3 class="pf-card-title"><?php echo esc_html( $event['title'] ); ?></h3>
                <?php if ( ! empty( $event['excerpt'] ) ) : ?>
                <p class="pf-card-text"><?php echo $event['excerpt']; // phpcs:ignore WordPress.Security.EscapeOutput — déjà assaini (wp_kses) par passiflore_render_event_description_text() ?></p>
                <?php endif; ?>
            </div>

            <?php if ( $date_row !== '' || $lieu_row !== '' ) : ?>
            <div class="pf-event-card-meta">
                <?php echo $date_row; // phpcs:ignore WordPress.Security.EscapeOutput — échappé dans pf_render_meta_row() ?>
                <?php echo $lieu_row; // phpcs:ignore WordPress.Security.EscapeOutput — échappé dans pf_render_lieu_meta_row() ?>
            </div>
            <?php endif; ?>

            </div><!-- .pf-card-content -->

        </a>
        <?php
        return ob_get_clean();
    }
}
