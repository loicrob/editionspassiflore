<?php
// ── Catalogues PDF ────────────────────────────────────────────────────────────
// Liste dynamique gérée via le groupe de champs SCF « Menu déroulant Catalogues
// PDF » (group_6a4ba1e58c0bd, repeater `catalogues`), rattaché à la page
// Catalogue (= page Shop WooCommerce). Pour chaque ligne :
//   - `fichier` + `lien` renseignés → `lien` devient le chemin public qui sert
//     ce fichier (réécriture d'URL dynamique, indépendante de l'URL réelle du
//     fichier dans la médiathèque)
//   - `lien` seul → utilisé tel quel (URL externe ou absolue quelconque)
//   - `fichier` seul → URL native du fichier dans la médiathèque

// ── Lecture des lignes (source commune réécriture + front) ───────────────────

function passiflore_get_pdf_catalogue_rows() {
    if (!function_exists('get_field')) return [];

    $page_id = wc_get_page_id('shop');
    if ($page_id <= 0) return [];

    $raw = get_field('catalogues', $page_id);
    if (!$raw) return [];

    $rows = [];
    foreach ($raw as $row) {
        $libelle = trim((string) ($row['libelle'] ?? ''));
        $lien    = trim((string) ($row['lien'] ?? ''));
        $fichier = (int) ($row['fichier'] ?? 0);
        if ($libelle === '') continue;

        // Chemin de réécriture : seulement quand lien + fichier sont tous deux renseignés.
        $path = ($fichier && $lien !== '') ? ltrim(strtok($lien, '?'), '/') : '';

        $rows[] = ['libelle' => $libelle, 'lien' => $lien, 'fichier' => $fichier, 'path' => $path];
    }
    return $rows;
}

// ── Réécriture des URLs (uniquement pour les lignes fichier+lien) ────────────

add_action('init', function () {
    foreach (passiflore_get_pdf_catalogue_rows() as $row) {
        if ($row['path'] === '') continue;
        add_rewrite_rule('^' . preg_quote($row['path'], '#') . '$', 'index.php?pf_catalogue_pdf=' . $row['fichier'], 'top');
    }
}, 10);

add_filter('query_vars', function ($vars) {
    $vars[] = 'pf_catalogue_pdf';
    return $vars;
});

add_action('template_redirect', function () {
    $attachment_id = (int) get_query_var('pf_catalogue_pdf');
    if (!$attachment_id) return;

    $file_path = get_attached_file($attachment_id);
    if (!$file_path || !file_exists($file_path)) {
        wp_die('Fichier introuvable.', '', ['response' => 404]);
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($file_path) . '"');
    header('Content-Length: ' . filesize($file_path));
    readfile($file_path);
    exit;
});

// Les règles de réécriture ne sont relues qu'en base (cache `rewrite_rules`) : toute
// modification du repeater doit donc déclencher un flush pour prendre effet. Mais au
// moment où `acf/save_post` se déclenche (sur la requête d'enregistrement elle-même),
// le hook `init` ci-dessus (qui construit les règles à partir des lignes du repeater)
// s'est déjà exécuté *avant* la sauvegarde — flush immédiat = règles fraîchement
// flushées mais construites avec les données d'AVANT l'enregistrement. On diffère
// donc le flush réel au tout début du prochain `init` (celui de la redirection que
// acf_form() effectue après sauvegarde), une fois que la ligne ci-dessus a déjà
// reconstruit les règles avec les données à jour.
add_action('acf/save_post', function ($post_id) {
    if ((int) $post_id === (int) wc_get_page_id('shop')) {
        update_option('pf_catalogue_needs_flush', 1);
    }
}, 20);

add_action('init', function () {
    if (get_option('pf_catalogue_needs_flush')) {
        delete_option('pf_catalogue_needs_flush');
        flush_rewrite_rules();
    }
}, 20);

// ── Page d'admin ──────────────────────────────────────────────────────────────
// Rend le formulaire natif SCF (repeater avec add/remove/drag + media picker)
// pour ce groupe de champs, sur une page dédiée sous WooCommerce.

add_action('admin_menu', function () {
    $hook = add_submenu_page(
        'woocommerce',
        'Catalogues PDF',
        'Catalogues PDF',
        'manage_options',
        'passiflore-catalogue',
        'passiflore_catalogue_admin_page'
    );
    add_action("load-$hook", 'acf_form_head');
});

function passiflore_catalogue_admin_page() {
    $page_id = wc_get_page_id('shop');
    ?>
    <div class="wrap">
        <h1>Catalogues PDF</h1>
        <?php
        acf_form([
            'post_id'         => $page_id,
            'field_groups'    => ['group_6a4ba1e58c0bd'],
            'submit_value'    => 'Enregistrer',
            'updated_message' => 'Catalogues mis à jour.',
        ]);
        ?>
    </div>
    <?php
}

// ── Lecture pour le front ─────────────────────────────────────────────────────

function passiflore_get_pdf_catalogues() {
    $catalogues = [];
    foreach (passiflore_get_pdf_catalogue_rows() as $row) {
        if ($row['path'] !== '') {
            $url = home_url('/' . $row['path']);
        } elseif ($row['lien'] !== '') {
            $url = $row['lien'];
        } elseif ($row['fichier']) {
            $url = wp_get_attachment_url($row['fichier']);
        } else {
            continue;
        }

        if (!$url) continue;
        $catalogues[] = ['libelle' => $row['libelle'], 'url' => $url];
    }
    return $catalogues;
}
