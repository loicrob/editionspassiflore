<?php
add_action( 'admin_menu', function() {
    remove_meta_box( 'tagsdiv-auteur', 'product', 'side' );
} );
add_action( 'add_meta_boxes', function() {
    foreach ( [ 'normal', 'side', 'advanced' ] as $context ) {
        remove_meta_box( 'rank_math_metabox_content_ai', 'product', $context );
    }
}, 99 );
add_action( 'add_meta_boxes', function() {
    foreach ( [ 'normal', 'side', 'advanced' ] as $context ) {
        remove_meta_box( 'product_branddiv', 'product', $context );
    }
}, 99 );
add_action( 'add_meta_boxes', function() {
    foreach ( [ 'normal', 'side', 'advanced' ] as $context ) {
        remove_meta_box( 'rank_math_metabox_link_suggestions', 'product', $context );
    }
}, 99 );
add_action( 'add_meta_boxes', function() {
    foreach ( [ 'normal', 'side', 'advanced' ] as $context ) {
        remove_meta_box( '_kad_classic_meta_control', 'product', $context );
    }
}, 99 );
add_action( 'add_meta_boxes', function() {
    foreach ( [ 'normal', 'side', 'advanced' ] as $context ) {
        remove_meta_box( 'woocommerce-product-images', 'product', $context );
    }
}, 99 );
add_action( 'add_meta_boxes', function() {
    foreach ( [ 'normal', 'side', 'advanced' ] as $context ) {
        remove_meta_box( 'commentsdiv', 'product', $context );
    }
}, 99 );