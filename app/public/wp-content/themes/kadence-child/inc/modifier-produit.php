<?php
/**
 * Customisations de l'écran d'édition et de la liste des produits WooCommerce.
 *
 * - Contrainte serveur « un seul terme `format_groupe` par produit »
 * - Indication sur le bloc Catégories
 * - Liste des produits : masque le bouton natif « Trier » et impose un tri par
 *   défaut par date de parution (plus récent d'abord, puis titre A→Z)
 *
 * La saisie de l'œuvre, du format et du titre a été déplacée dans
 * inc/product-format-admin.php : la métabox `format_groupe`, son émulation
 * checkbox→radio, le pré-remplissage du nom de groupe et l'aide sous le champ
 * titre y sont remplacés par un sélecteur d'œuvre et un menu de format.
 */

// ─────────────────────────────────────────────────────────────────────────
// Contrainte serveur : un seul terme `format_groupe` par produit
//
// Le formulaire produit écrit désormais ce terme en mode remplacement, mais ce
// filet reste utile aux écritures qui ne passent pas par lui (imports, API).
// ─────────────────────────────────────────────────────────────────────────

add_action('save_post_product', function ($post_id) {
    $terms = wp_get_object_terms($post_id, 'format_groupe');
    if (!is_wp_error($terms) && count($terms) > 1) {
        wp_set_object_terms($post_id, [$terms[0]->term_id], 'format_groupe');
    }
});

// ─────────────────────────────────────────────────────────────────────────
// Onglets Produit : "Produits liés" et "Avancé" masqués (non utilisés)
// ─────────────────────────────────────────────────────────────────────────

add_filter('woocommerce_product_data_tabs', function ($tabs) {
    unset($tabs['linked_product'], $tabs['advanced']);
    return $tabs;
});

// ─────────────────────────────────────────────────────────────────────────
// CSS / JS pour l'écran d'édition produit
// ─────────────────────────────────────────────────────────────────────────

// Onglet Livraison : longueur du colis et classe de livraison masquées (non
// utilisées — vérifié en base, aucun produit n'a de `_length` renseigné).
$admin_head_product_shipping = function () {
    global $post;
    if (!$post || $post->post_type !== 'product') return;
    ?>
    <style>
        #shipping_product_data #product_length { display: none; }
        #shipping_product_data .shipping_class_field { display: none; }
    </style>
    <?php
};

add_action('admin_head-post.php',     $admin_head_product_shipping);
add_action('admin_head-post-new.php', $admin_head_product_shipping);

// Onglet Inventaire : _sku masqué (recopié automatiquement depuis l'ISBN, cf.
// script ci-dessous). Bulles d'aide (i) masquées sur tout le bloc Données
// produit (tous onglets confondus), plus verbeuses qu'utiles ici.
$admin_head_product_inventory = function () {
    global $post;
    if (!$post || $post->post_type !== 'product') return;
    ?>
    <style>
        #inventory_product_data ._sku_field { display: none; }
        #woocommerce-product-data .woocommerce-help-tip { display: none; }
        #inventory_product_data p.form-field.show_if_simple.show_if_variable:has(#_manage_stock_disabled) { display: none !important; }
        #inventory_product_data ._stock_status_field { display: none !important; }
        #general_product_data ._sale_price_field .description { display: none !important; }
    </style>
    <?php
};

add_action('admin_head-post.php',     $admin_head_product_inventory);
add_action('admin_head-post-new.php', $admin_head_product_inventory);

$admin_footer_product = function () {
    global $post;
    if (!$post || $post->post_type !== 'product') return;
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const categoriesHeader = document.querySelector('#product_catdiv .postbox-header');
        if (!categoriesHeader) return;
        const hintCategories = document.createElement('p');
        hintCategories.className = 'description';
        hintCategories.style.padding = '0 12px';
        hintCategories.innerHTML = '<strong>S\u00e9lectionner aussi la cat\u00e9gorie parente</strong> (ex. : \u201cCulture Sud-Ouest\u201d et \u201cArt\u201d).';
        categoriesHeader.insertAdjacentElement('afterend', hintCategories);

        // Champ ISBN (ex-"GTIN, UPC, EAN, ou ISBN") : seul champ saisi
        // d\u00e9sormais, _sku (masqu\u00e9) est recopi\u00e9 automatiquement depuis lui.
        const skuField  = document.querySelector('#_sku');
        const guidField = document.querySelector('#_global_unique_id');
        if (skuField && guidField) {
            const guidLabel = document.querySelector('label[for="_global_unique_id"]');
            if (guidLabel) guidLabel.textContent = 'ISBN';

            const isbnFormatted = document.createElement('span');
            isbnFormatted.className = 'description';
            isbnFormatted.style.display = 'block';
            guidField.insertAdjacentElement('afterend', isbnFormatted);

            // D\u00e9coupage ISBN-13 selon les tranches officielles AFNIL (groupes
            // 978-2 et 979-10 \u2014 les seuls utilis\u00e9s par Passiflore). Tables
            // identiques \u00e0 pf_format_isbn() (inc/isbn.php), transmises par PHP
            // pour rester en un seul endroit \u00e0 mettre \u00e0 jour. La longueur du
            // pr\u00e9fixe \u00e9diteur (2 \u00e0 7 chiffres) varie selon le bloc \u2014 pas de
            // largeur fixe possible, le catalogue utilise plusieurs blocs.
            const isbnRules = <?php echo wp_json_encode( [
                '978' => [ 'group' => '2', 'rules' => PF_ISBN_GROUP_2_RULES ],
                '979' => [ 'group' => '10', 'rules' => PF_ISBN_GROUP_10_RULES ],
            ] ); ?>;
            const formatIsbn = function (digits) {
                const prefix = digits.slice(0, 3);
                const conf = prefix === '979' ? (digits.slice(3, 5) === '10' ? isbnRules['979'] : null) : isbnRules[prefix];
                if (!conf) return digits;

                const groupLen = conf.group.length;
                const rest = digits.slice(3 + groupLen, 3 + groupLen + (9 - groupLen));
                if (rest.length < 7) return [prefix, conf.group, rest].filter(Boolean).join('-');

                const bucket = parseInt(rest.slice(0, 7), 10);
                const rule = conf.rules.find(([start, end]) => bucket >= start && bucket <= end);
                if (!rule) return digits;

                const publisher = rest.slice(0, rule[2]);
                const title = rest.slice(rule[2]);
                const check = digits.slice(12, 13);
                return [prefix, conf.group, publisher, title, check].filter(Boolean).join('-');
            };

            const syncIsbn = function () {
                const digits = guidField.value.replace(/[^0-9]/g, '');
                if (digits !== guidField.value) guidField.value = digits;
                skuField.value = digits;
                isbnFormatted.textContent = 'ISBN format\u00e9 : ' + formatIsbn(digits);
            };

            guidField.addEventListener('input', syncIsbn);
            syncIsbn();
        }
    });
    </script>
    <?php
};

add_action('admin_footer-post.php',     $admin_footer_product);
add_action('admin_footer-post-new.php', $admin_footer_product);

// ─────────────────────────────────────────────────────────────────────────
// Liste des produits (edit.php?post_type=product)
// ─────────────────────────────────────────────────────────────────────────

// Masque le bouton « Trier » (tri manuel par glisser-déposer = `menu_order`)
// que WooCommerce ajoute aux vues de la liste des produits. Le front de ce site
// n'utilise jamais `menu_order` (catalogue et étagère ont leur propre tri), ce
// tri manuel n'aurait donc aucun effet visible.
add_filter('views_edit-product', function ($views) {
    unset($views['byorder']);
    return $views;
}, 20);

// Tri par défaut de la liste : de la parution la plus récente à la plus ancienne
// (SCF `date_de_parution`, stockée en Ymd → l'ordre lexicographique correspond à
// l'ordre chronologique), puis par titre A→Z à date de parution égale. Les
// produits sans date de parution (meta absente ou vide) sont renvoyés en tête de
// liste (LEFT JOIN + critère booléen prioritaire).
// But : les admins voient les dernières parutions en premier.
// Ne s'applique que si l'admin n'a pas cliqué sur un en-tête de colonne triable
// (dans ce cas `orderby` est présent dans l'URL → on respecte son choix).
add_action('pre_get_posts', function ($query) {
    global $pagenow;
    if (!is_admin() || 'edit.php' !== $pagenow || !$query->is_main_query()) {
        return;
    }
    if ('product' !== $query->get('post_type') || isset($_GET['orderby'])) {
        return;
    }
    $query->set('pf_parution_sort', true);
});

add_filter('posts_clauses', function ($clauses, $query) {
    if (!$query->get('pf_parution_sort')) {
        return $clauses;
    }
    global $wpdb;
    $clauses['join']   .= " LEFT JOIN {$wpdb->postmeta} pf_parution"
        . " ON ( {$wpdb->posts}.ID = pf_parution.post_id"
        . " AND pf_parution.meta_key = 'date_de_parution' ) ";
    $clauses['orderby'] = "( pf_parution.meta_value IS NULL OR TRIM(pf_parution.meta_value) = '' ) DESC, "
        . "pf_parution.meta_value DESC, {$wpdb->posts}.post_title ASC";
    return $clauses;
}, 10, 2);