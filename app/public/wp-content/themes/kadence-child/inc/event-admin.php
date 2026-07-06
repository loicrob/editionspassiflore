<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'pf-event-books',
        'Livres associés à l’événement',
        'pf_render_event_books_metabox',
        'tribe_events',
        'normal',
        'high'
    );
} );

function pf_render_event_books_metabox( $post ) {
    wp_nonce_field( 'pf_save_event_books', 'pf_event_books_nonce' );

    $raw = get_post_meta( $post->ID, '_pf_event_books', true );
    if ( ! is_array( $raw ) ) $raw = [];
    $current_ids = array_values( array_filter( array_map( 'intval', $raw ) ) );

    $terms = get_terms( [ 'taxonomy' => 'auteur', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ] );
    if ( is_wp_error( $terms ) ) $terms = [];
    ?>
    <div id="pf-eb-wrap">

        <div id="pf-eb-add">
            <div id="pf-eb-author-row">
                <select id="pf-eb-author-select">
                    <option value="">— Livres d'un auteur —</option>
                    <?php foreach ( $terms as $t ) : ?>
                        <option value="<?php echo esc_attr( $t->term_id ); ?>"><?php echo esc_html( $t->name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="pf-eb-author-btn" class="button">Ajouter</button>
            </div>
            <input type="search" id="pf-eb-search" placeholder="Rechercher un livre…" autocomplete="off">
            <ul id="pf-eb-results"></ul>
        </div>

        <div id="pf-eb-selected">
            <div class="pf-eb-selected-header">
                <p class="pf-eb-selected-label">Livres sélectionnés</p>
                <button type="button" id="pf-eb-clear" class="button button-small">Retirer tout</button>
            </div>
            <ul id="pf-eb-list">
                <?php foreach ( $current_ids as $pid ) :
                    $title = get_the_title( $pid );
                    if ( ! $title ) continue;
                ?>
                    <li data-id="<?php echo $pid; ?>" data-date="<?php echo esc_attr( (string) get_post_meta( $pid, 'date_de_parution', true ) ); ?>">
                        <span class="pf-eb-handle dashicons dashicons-menu" aria-hidden="true"></span>
                        <span><?php echo esc_html( $title ); ?></span>
                        <button type="button" class="pf-eb-remove dashicons dashicons-no-alt" aria-label="Supprimer"></button>
                        <input type="hidden" name="pf_event_books[]" value="<?php echo $pid; ?>">
                    </li>
                <?php endforeach; ?>
            </ul>
            <p id="pf-eb-empty" <?php echo $current_ids ? 'style="display:none"' : ''; ?>>Aucun livre sélectionné.</p>
        </div>

        <p id="pf-eb-feedback"></p>
    </div>
    <?php
}

add_action( 'save_post', function ( $post_id ) {
    if ( ! isset( $_POST['pf_event_books_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['pf_event_books_nonce'], 'pf_save_event_books' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $ids = [];
    if ( ! empty( $_POST['pf_event_books'] ) ) {
        $ids = array_values( array_filter( array_map( 'intval', (array) $_POST['pf_event_books'] ) ) );
    }

    update_post_meta( $post_id, '_pf_event_books', $ids );
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    global $post;
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
    if ( ! isset( $post->post_type ) || $post->post_type !== 'tribe_events' ) return;

    wp_enqueue_script(
        'pf-book-picker',
        get_stylesheet_directory_uri() . '/assets/js/book-picker.js',
        [ 'jquery', 'jquery-ui-sortable' ],
        '1.0.0',
        true
    );
    wp_enqueue_script(
        'pf-event-admin',
        get_stylesheet_directory_uri() . '/assets/js/event-admin.js',
        [ 'pf-book-picker' ],
        '1.0.0',
        true
    );
    wp_localize_script( 'pf-event-admin', 'pfEB', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'pf_event_books_ajax' ),
    ] );

    wp_add_inline_style( 'wp-admin', '
        #pf-eb-wrap { display:flex; flex-direction:column; gap:12px; }
        #pf-eb-add { display:flex; flex-direction:column; gap:6px; }
        #pf-eb-author-row { display:flex; gap:6px; }
        #pf-eb-author-row select { flex:1; }
        #pf-eb-search { width:100%; box-sizing:border-box; }
        #pf-eb-results { margin:0; padding:0; list-style:none; border:1px solid #ddd; border-radius:3px; max-height:200px; overflow-y:auto; display:none; }
        #pf-eb-results li { display:flex; align-items:center; justify-content:space-between; padding:5px 8px; border-bottom:1px solid #f0f0f0; }
        #pf-eb-results li:last-child { border-bottom:none; }
        #pf-eb-results li.pf-eb-added { color:#999; }
        #pf-eb-results li span { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        #pf-eb-selected { border-top:1px solid #eee; padding-top:10px; }
        .pf-eb-selected-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; }
        .pf-eb-selected-label { margin:0; font-weight:600; font-size:12px; text-transform:uppercase; color:#666; }
        #pf-eb-list { margin:0; padding:0; list-style:none; }
        #pf-eb-list li { display:flex; align-items:center; gap:6px; padding:4px 0; border-bottom:1px solid #f5f5f5; cursor:default; }
        #pf-eb-list li span:not(.pf-eb-handle) { flex:1; }
        .pf-eb-handle { cursor:grab; color:#aaa; flex-shrink:0; }
        .pf-eb-handle:active { cursor:grabbing; }
        .ui-sortable-helper { background:#fff; box-shadow:0 2px 6px rgba(0,0,0,.15); }
        .ui-sortable-placeholder { border:1px dashed #ccc; visibility:visible !important; height:28px; }
        .pf-eb-remove { background:none; border:none; cursor:pointer; color:#a00; padding:0; width:20px; height:20px; flex-shrink:0; }
        .pf-eb-remove:hover { color:#d00; }
        #pf-eb-empty, #pf-eb-feedback { margin:4px 0; font-size:12px; color:#888; }
    ' );
} );

add_action( 'wp_ajax_pf_event_search_books', function () {
    check_ajax_referer( 'pf_event_books_ajax', 'nonce' );

    $q = sanitize_text_field( $_POST['q'] ?? '' );
    if ( mb_strlen( $q ) < 2 ) wp_send_json_success( [] );

    // Recherche tolérante (casse, accents, caractères spéciaux, fautes de
    // frappe) via le cœur partagé, rangée par pertinence.
    $ids = array_slice( pf_search_products_ranked( $q ), 0, 60 );
    $ids = passiflore_dedup_by_format_groupe( $ids );
    wp_send_json_success( pf_eb_books_data( $ids ) );
} );

add_action( 'wp_ajax_pf_event_author_books', function () {
    check_ajax_referer( 'pf_event_books_ajax', 'nonce' );

    $term_id = (int) ( $_POST['term_id'] ?? 0 );
    if ( $term_id <= 0 ) wp_send_json_error();

    $ids = passiflore_product_ids_by_auteur_terms( [ $term_id ] );
    $ids = passiflore_dedup_by_format_groupe( $ids );
    usort( $ids, function ( $a, $b ) {
        $da = (string) get_post_meta( $a, 'date_de_parution', true );
        $db = (string) get_post_meta( $b, 'date_de_parution', true );
        return strcmp( $db, $da );
    } );
    wp_send_json_success( pf_eb_books_data( $ids ) );
} );

function pf_eb_books_data( array $ids ): array {
    $books = [];
    foreach ( $ids as $pid ) {
        $title = get_the_title( $pid );
        if ( ! $title ) continue;
        $books[] = [
            'id'    => (int) $pid,
            'title' => $title,
            'date'  => (string) get_post_meta( $pid, 'date_de_parution', true ),
        ];
    }
    return $books;
}
