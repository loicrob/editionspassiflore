<?php
/**
 * Template for individual author pages — /auteur/{slug}/
 * WordPress uses this file automatically for every "auteur" taxonomy term.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

$term    = get_queried_object();
$term_id = $term->term_id;

$prenom = get_field( 'prenom', 'auteur_' . $term_id );
$nom    = get_field( 'nom',    'auteur_' . $term_id );
$photo  = get_field( 'photo',  'auteur_' . $term_id );
$bio    = get_field( 'biographie_complete', 'auteur_' . $term_id );

$nom_complet = passiflore_build_nom_complet( $prenom, $nom );

?>
<div id="primary" class="content-area">
    <main id="main" class="site-main pf-auteur-single" role="main">

        <header class="pf-auteur-single-header">
            <h1 class="pf-auteur-single-titre">
                <?php if ( $prenom ) : ?>
                    <span class="pf-auteur-single-prenom"><?php echo esc_html( $prenom ); ?></span>
                <?php endif; ?>
                <?php echo esc_html( $nom ); ?>
            </h1>
        </header>

        <div class="pf-auteur-single-corps">

            <?php if ( $photo ) : ?>
                <div class="pf-auteur-single-photo">
                    <?php echo wp_get_attachment_image( $photo, 'large', false, [
                        'alt' => esc_attr( $nom_complet ),
                    ] ); ?>
                </div>
            <?php endif; ?>

            <?php if ( $bio ) : ?>
                <div class="pf-auteur-single-bio">
                    <div class="pf-auteur-single-bio-contenu">
                        <?php echo wp_kses_post( $bio ); ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <?php
        $candidate_ids = passiflore_dedup_by_format_groupe(
            passiflore_product_ids_by_auteur_terms( [ $term_id ] )
        );
        usort( $candidate_ids, function ( $a, $b ) {
            return strcmp(
                (string) get_post_meta( $b, 'date_de_parution', true ),
                (string) get_post_meta( $a, 'date_de_parution', true )
            );
        } );
        $groups        = $candidate_ids ? passiflore_group_books_by_author_set( [ $term_id ], $candidate_ids ) : [];
        if ( $groups ) :
        ?>
        <section class="pf-auteur-livres">
            <?php foreach ( $groups as $group ) :
                $title   = passiflore_author_group_title( $group, [ $term_id ], 'auteur' );
                $ids_str = implode( ',', $group['product_ids'] );
            ?>
            <div class="pf-auteur-livres-section">
                <?php if ( $title ) : ?>
                <h2 class="pf-auteur-livres-titre"><?= esc_html( $title ) ?></h2>
                <?php endif; ?>
                <?= do_shortcode( '[passiflore_etagere ids="' . $ids_str . '" mode="scroll" display="covers" nb_books_first_displayed="20"]' ) ?>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php
        if ( function_exists( 'passiflore_render_auteur_upcoming_events' ) ) {
            echo passiflore_render_auteur_upcoming_events( $term_id, $nom_complet );
        }
        if ( function_exists( 'passiflore_render_auteur_past_notable_events' ) ) {
            echo passiflore_render_auteur_past_notable_events( $term_id, $nom_complet );
        }
        ?>

    </main>
</div>
<?php
get_footer();
