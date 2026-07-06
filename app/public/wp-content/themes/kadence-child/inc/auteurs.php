<?php
function passiflore_render_auteur_card( $term_id, $args = [] ) {
    $term = get_term( $term_id, 'auteur' );
    if ( ! $term || is_wp_error( $term ) ) return '';

    $defaults = [
        'variant'  => 'default',
        'link'     => true,
        'loading'  => 'lazy',
        'show_bio' => false,
    ];
    $args = array_merge( $defaults, $args );

    $prenom = get_field( 'prenom', 'auteur_' . $term_id );
    $nom    = get_field( 'nom',    'auteur_' . $term_id );
    $photo  = get_field( 'photo',  'auteur_' . $term_id );
    $bio    = $term->description;
    $url    = get_term_link( $term );

    $classes = [ 'pf-card', 'pf-auteur-card' ];
    if ( $args['variant'] === 'compact' ) {
        $classes[] = 'pf-card--compact';
    }
    $class_attr = implode( ' ', $classes );

    $tag       = $args['link'] ? 'a' : 'div';
    $href_attr = $args['link'] ? ' href="' . esc_url( $url ) . '"' : '';

    ob_start();
    ?>
    <<?php echo $tag; ?><?php echo $href_attr; ?> class="<?php echo esc_attr( $class_attr ); ?>">
        <div class="pf-auteur-photo">
            <?php if ( $photo ) :
                echo wp_get_attachment_image( $photo, 'large', false, [
                    'alt'     => esc_attr( passiflore_build_nom_complet( $prenom, $nom ) ),
                    'loading' => $args['loading'],
                ] );
            endif; ?>
        </div>
        <div class="pf-card-content">
            <span class="pf-card-title">
                <?php if ( $prenom ) : ?>
                    <span><?php echo esc_html( $prenom ); ?></span>
                <?php endif; ?>
                <span class="pf-auteur-nom-de-famille"><?php echo esc_html( $nom ); ?></span>
            </span>
            <?php if ( ( $args['variant'] !== 'compact' || $args['show_bio'] ) && $bio ) : ?>
                <p class="pf-card-text"><?php echo esc_html( $bio ); ?></p>
            <?php endif; ?>
        </div>
    </<?php echo $tag; ?>>
    <?php
    return ob_get_clean();
}

function passiflore_auteurs_shortcode( $atts = [] ) {
    $atts = shortcode_atts( [ 'search' => 'true' ], $atts, 'passiflore_auteurs' );

    // Par défaut, on affiche la barre de recherche (sticky, pleine largeur)
    // au-dessus du trombinoscope. La logique de recherche/affichage vit dans
    // Passiflore_Recherche_Auteurs (cf. inc/class-recherche-auteurs.php), sur
    // le même modèle que Passiflore_Catalogue.
    // `[passiflore_auteurs search="false"]` restitue la grille seule.
    if ( filter_var( $atts['search'], FILTER_VALIDATE_BOOLEAN ) ) {
        return do_shortcode( '[passiflore_recherche_auteurs]' );
    }

    $terms = get_terms( [
        'taxonomy'   => 'auteur',
        'hide_empty' => false,
        'orderby'    => 'meta_value',
        'meta_key'   => 'nom',
        'order'      => 'ASC',
    ] );

    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return '<p>' . esc_html__( 'Aucun auteur trouvé.', 'kadence-child' ) . '</p>';
    }

    $html = '<div class="pf-auteurs-grille">';
    foreach ( $terms as $i => $term ) {
        $html .= passiflore_render_auteur_card( $term->term_id, [
            'loading' => $i < 8 ? 'eager' : 'lazy',
        ] );
    }
    $html .= '</div>';

    return $html;
}
add_shortcode( 'passiflore_auteurs', 'passiflore_auteurs_shortcode' );

function passiflore_get_type_auteur($term_id, $pluriel = false) {
    $type_post = get_field('type', 'auteur_' . $term_id);
    $genre     = get_field('genre', 'auteur_' . $term_id);

    if (!$type_post) return '';

    if ($pluriel) {
        $cle = ($genre === 'f') ? 'pluriel_feminin' : 'pluriel_masculin';
    } else {
        $cle = ($genre === 'f') ? 'feminin' : 'masculin';
    }

    return get_field($cle, $type_post->ID);
}

function passiflore_build_nom_complet($prenom, $nom) {
    return !empty($prenom) ? trim($prenom . ' ' . $nom) : trim($nom);
}

function passiflore_auteur_pre_insert($term, $taxonomy) {
    if ($taxonomy !== 'auteur') return $term;

    $prenom = sanitize_text_field($_POST['acf']['field_69c4088b1802b'] ?? '');
    $nom    = sanitize_text_field($_POST['acf']['field_69c408991802c'] ?? '');

    $nom_complet = passiflore_build_nom_complet($prenom, $nom);

    if ($nom_complet) {
        $_POST['tag-name'] = $nom_complet;
        return $nom_complet;
    }

    return $term;
}
add_filter('pre_insert_term', 'passiflore_auteur_pre_insert', 10, 2);

function passiflore_auteur_sync_on_save($post_id) {
    // ACF/SCF passe "term_{$term_id}" pour les sauvegardes de termes de taxonomie
    if (!is_string($post_id) || strpos($post_id, 'term_') !== 0) return;

    $term_id = (int) substr($post_id, 5);
    $term    = get_term($term_id, 'auteur');
    if (!$term || is_wp_error($term)) return;

    static $is_syncing = false;
    if ($is_syncing) return;
    $is_syncing = true;

    $prenom = get_field('prenom', 'auteur_' . $term_id) ?: '';
    $nom    = get_field('nom',    'auteur_' . $term_id) ?: '';
    $bio    = get_field('biographie_synthetique', 'auteur_' . $term_id) ?: '';

    $nom_complet = passiflore_build_nom_complet($prenom, $nom);

    wp_update_term($term_id, 'auteur', [
        'name'        => $nom_complet,
        'slug'        => sanitize_title($nom_complet),
        'description' => $bio,
    ]);

    $is_syncing = false;
}
add_action('acf/save_post', 'passiflore_auteur_sync_on_save', 20);

function passiflore_clean_auteur_form() {
    ?>
    <style>
        .term-name-wrap,
        .term-description-wrap,
        .term-slug-wrap {
            display: none !important;
        }
    </style>
    <?php
}
add_action('auteur_add_form_fields', 'passiflore_clean_auteur_form');
add_action('auteur_edit_form_fields', 'passiflore_clean_auteur_form');