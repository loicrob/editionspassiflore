<?php
/**
 * Duplication d'un événement (tribe_events) depuis l'admin.
 *
 * The Events Calendar n'offre pas de duplication (contrairement au « Dupliquer » natif de
 * WooCommerce sur les livres). On reproduit ici la même affordance : lien dans la liste des
 * événements + bouton dans la boîte « Publier » de l'écran d'édition.
 *
 * ⚠️ TEC 6 stocke AUSSI les événements dans ses tables personnalisées (`tec_events`,
 * `tec_occurrences`) : sans elles, l'événement dupliqué existe en base mais n'apparaît dans
 * AUCUNE vue calendrier. Rien à faire côté code pour autant — TEC les réalimente tout seul :
 * `Meta_Watcher` marque l'événement dès qu'une meta de date est écrite (_EventStartDate,
 * _EventEndDate, _EventDuration, _EventTimezone, _EventAllDay) et `Updates\Provider` commite sur
 * `shutdown`, qui s'exécute aussi après un `wp_safe_redirect()` + `exit`. D'où l'obligation d'écrire
 * ces metas par l'API WordPress (`add_post_meta`), jamais par SQL direct — un INSERT brut
 * ne déclenche pas `added_post_meta` et laisserait la copie invisible côté front.
 *
 * Le `save_post` de TEC (`Tribe__Events__Meta__Save`), lui, ne fait rien ici : il exige son nonce
 * d'écran d'édition, absent de notre requête.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Metas à ne pas recopier : verrous d'édition, anciens slugs, rapports de migration TEC,
 * et notre index de recherche (reconstruit à la fin, le titre changeant).
 */
function pf_event_duplicate_skipped_meta() {
	return [ '_edit_lock', '_edit_last', '_wp_old_slug', '_pf_search_index', '_pf_search_title' ];
}

/**
 * URL nonçée de duplication, ou '' si l'utilisateur n'a pas les droits.
 */
function pf_event_duplicate_url( $post_id ) {
	$post_id = absint( $post_id );
	$type    = get_post_type_object( 'tribe_events' );

	if ( ! $type || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( $type->cap->create_posts ) ) {
		return '';
	}

	return wp_nonce_url(
		admin_url( 'admin.php?action=pf_duplicate_event&post=' . $post_id ),
		'pf-duplicate-event_' . $post_id
	);
}

/**
 * Crée une copie en brouillon d'un événement : champs du post, toutes les taxonomies,
 * toutes les metas (donc image mise en avant, champs SCF, lieu/organisateur, `_pf_event_books`,
 * `_pf_event_daily_hours`…).
 *
 * @return int|WP_Error ID du nouvel événement.
 */
function pf_duplicate_event( WP_Post $post ) {
	// wp_insert_post() attend des données échappées (slashées) ; get_post() en renvoie des non-échappées.
	$new_id = wp_insert_post( wp_slash( [
		'post_type'      => $post->post_type,
		'post_status'    => 'draft',
		'post_title'     => $post->post_title . ' (copie)',
		'post_content'   => $post->post_content,
		'post_excerpt'   => $post->post_excerpt,
		'post_author'    => get_current_user_id() ?: $post->post_author,
		'post_parent'    => $post->post_parent,
		'menu_order'     => $post->menu_order,
		'comment_status' => $post->comment_status,
		'ping_status'    => $post->ping_status,
	] ), true );

	if ( is_wp_error( $new_id ) ) {
		return $new_id;
	}

	// Taxonomies (catégories d'événements, étiquettes…).
	foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
		$terms = wp_get_object_terms( $post->ID, $taxonomy, [ 'fields' => 'ids' ] );
		if ( ! is_wp_error( $terms ) && $terms ) {
			wp_set_object_terms( $new_id, $terms, $taxonomy, false );
		}
	}

	// Metas. get_post_meta() sans clé renvoie les valeurs BRUTES (non désérialisées), d'où le
	// maybe_unserialize() ; et add_post_meta() attend des données slashées, d'où le wp_slash().
	$skip = pf_event_duplicate_skipped_meta();

	foreach ( get_post_meta( $post->ID ) as $key => $values ) {
		if ( in_array( $key, $skip, true ) || 0 === strpos( $key, '_tec_ct1_' ) ) {
			continue;
		}

		// Purge d'abord : `_EventOrigin` (posé par TEC à l'insertion) ou toute meta greffée par un
		// autre plugin sur save_post donnerait sinon une deuxième ligne au lieu d'être remplacée.
		delete_post_meta( $new_id, $key );

		foreach ( $values as $value ) {
			add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
		}
	}

	if ( function_exists( 'pf_search_index_event' ) ) {
		pf_search_index_event( $new_id );
	}

	return $new_id;
}

/**
 * Lien « Dupliquer » dans la liste des événements.
 */
add_filter( 'post_row_actions', function ( $actions, $post ) {
	if ( 'tribe_events' !== $post->post_type ) {
		return $actions;
	}

	$url = pf_event_duplicate_url( $post->ID );
	if ( $url ) {
		$actions['pf_duplicate'] = '<a href="' . esc_url( $url ) . '">Dupliquer</a>';
	}

	return $actions;
}, 10, 2 );

/**
 * Bouton « Copier vers un nouveau brouillon » dans la boîte « Publier » de l'écran d'édition.
 */
add_action( 'post_submitbox_misc_actions', function ( $post ) {
	if ( ! $post instanceof WP_Post || 'tribe_events' !== $post->post_type ) {
		return;
	}

	$url = pf_event_duplicate_url( $post->ID );
	if ( ! $url ) {
		return;
	}
	?>
	<div class="misc-pub-section">
		<a class="button" href="<?php echo esc_url( $url ); ?>">Copier vers un nouveau brouillon</a>
	</div>
	<?php
} );

/**
 * Exécution de la duplication, puis redirection vers l'écran d'édition de la copie.
 */
add_action( 'admin_action_pf_duplicate_event', function () {
	$post_id = isset( $_REQUEST['post'] ) ? absint( $_REQUEST['post'] ) : 0;
	$post    = $post_id ? get_post( $post_id ) : null;

	if ( ! $post instanceof WP_Post || 'tribe_events' !== $post->post_type ) {
		wp_die( 'Événement introuvable.' );
	}

	check_admin_referer( 'pf-duplicate-event_' . $post_id );

	if ( ! pf_event_duplicate_url( $post_id ) ) {
		wp_die( 'Vous n’avez pas l’autorisation de dupliquer cet événement.' );
	}

	$new_id = pf_duplicate_event( $post );

	if ( is_wp_error( $new_id ) ) {
		wp_die( esc_html( $new_id->get_error_message() ) );
	}

	wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $new_id ) );
	exit;
} );
