<?php
/**
 * Customisations de l'écran d'édition d'un produit WooCommerce.
 *
 * - Contraint la taxonomie `format_groupe` à un seul terme par produit (côté serveur + UI)
 * - Cache le sélecteur de parent lors de la création d'un terme `format_groupe`
 *   (la hiérarchie n'a pas de sens fonctionnel ici, on l'utilise juste pour la checklist)
 * - Pré-remplit le champ "nom" du nouveau groupe avec le titre du produit
 *   (sans suffixe « (grands caractères) » ou « (numérique) »)
 * - Ajoute des indications dans l'admin (titre produit, catégories)
 */

// ─────────────────────────────────────────────────────────────────────────
// Contrainte serveur : un seul terme `format_groupe` par produit
// ─────────────────────────────────────────────────────────────────────────

add_action('save_post_product', function ($post_id) {
    $terms = wp_get_object_terms($post_id, 'format_groupe');
    if (!is_wp_error($terms) && count($terms) > 1) {
        wp_set_object_terms($post_id, [$terms[0]->term_id], 'format_groupe');
    }
});

// ─────────────────────────────────────────────────────────────────────────
// CSS / JS pour l'écran d'édition produit
// ─────────────────────────────────────────────────────────────────────────

$admin_footer_product = function () {
    global $post;
    if (!$post || $post->post_type !== 'product') return;
    ?>
    <style>
        /* Cache le sélecteur de parent dans le formulaire d'ajout d'un nouveau format_groupe.
           WP n'enveloppe pas ces éléments, donc on les cible un par un. */
        #format_groupediv label[for="newformat_groupe_parent"],
        #format_groupediv #newformat_groupe_parent {
            display: none !important;
        }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function () {

        // 1. Indications sous le titre du produit
        const titleInput = document.querySelector('#title');
        if (titleInput) {
            const hintTitle = document.createElement('p');
            hintTitle.className = 'description';
            hintTitle.innerHTML = '<strong>Si format particulier, le mettre entre parenthèses.</strong><br>Exemples : “L’homme semence“, “L’homme semence (grands caractères)”, “L’homme semence (audio)“, etc.<br>Le permalien sera alors : <u>https://www.editions-passiflore.com/livre/lhomme-semence</u>, <u>https://www.editions-passiflore.com/livre/lhomme-semence-grands-caracteres</u>, etc.<br>Pour un <strong>format particulier</strong>, <strong>le spécifier</strong> dans “Données produit > Attributs > Ajouter existant > Format particulier“.';
            titleInput.insertAdjacentElement('afterend', hintTitle);
        }

        // 2. Indication sur le bloc Catégories
        const categoriesHeader = document.querySelector('#product_catdiv .postbox-header');
        if (categoriesHeader) {
            const hintCategories = document.createElement('p');
            hintCategories.className = 'description';
            hintCategories.style.padding = '0 12px';
            hintCategories.innerHTML = '<strong>Sélectionner aussi la catégorie parente</strong> (ex. : “Culture Sud-Ouest” et “Art”).';
            categoriesHeader.insertAdjacentElement('afterend', hintCategories);
        }

        // 3. Format_groupe : un seul terme à la fois (transforme les checkboxes en radio)
        const container = document.querySelector('#format_groupediv');
        if (!container) return;

        // Toutes les checklists (l'onglet "Tous" + l'onglet "Plus utilisés")
        const checklists = container.querySelectorAll('.categorychecklist');
        if (!checklists.length) return;

        function getAllCheckboxes() {
            const boxes = [];
            checklists.forEach(cl => {
                cl.querySelectorAll('input[type="checkbox"]').forEach(cb => boxes.push(cb));
            });
            return boxes;
        }

        function enforceSingleSelection(triggeredBox) {
            // Décoche toutes les autres cases ayant une valeur DIFFÉRENTE de celle cochée.
            // On compare par .value parce qu'un même terme apparaît 2× (onglet "Tous" + "Plus utilisés").
            const keepValue = triggeredBox.value;
            getAllCheckboxes().forEach(cb => {
                if (cb.value !== keepValue) cb.checked = false;
            });
        }

        // Au chargement : si plusieurs cases sont cochées (anomalie de migration), n'en garde qu'une
        const checkedAtStart = getAllCheckboxes().filter(cb => cb.checked);
        const distinctValues = [...new Set(checkedAtStart.map(cb => cb.value))];
        if (distinctValues.length > 1) {
            enforceSingleSelection(checkedAtStart[0]);
        }

        // Intercepte les clics sur les deux onglets de checklist
        checklists.forEach(cl => {
            cl.addEventListener('change', function (e) {
                const box = e.target;
                if (box.type !== 'checkbox' || !box.checked) return;
                enforceSingleSelection(box);
            });
        });

        // 4. Pré-remplit le nom d'un nouveau groupe avec le titre du produit (sans suffixe)
        const newTermInput = container.querySelector('#newformat_groupe');
        const addNewToggle = container.querySelector('.taxonomy-add-new');
        const addWrapper   = container.querySelector('#format_groupe-add');
        if (newTermInput && titleInput) {
            // WP pré-remplit avec un faux placeholder = label par défaut. On le considère "vide".
            const PLACEHOLDER = 'Nom du nouveau Groupe de formats';
            const isEmpty = (val) => {
                const t = val.trim();
                return t === '' || t === PLACEHOLDER;
            };
            const prefill = () => {
                if (!isEmpty(newTermInput.value)) return;
                const cleaned = titleInput.value
                    .replace(/\s*\((grands\s+caract[eè]res?|numérique|numerique|audio|poche)\)\s*$/i, '')
                    .trim();
                if (cleaned) {
                    newTermInput.value = cleaned;
                    newTermInput.select();
                }
            };
            // Au clic sur "+ Ajouter un nouveau" : WordPress focus le champ avant qu'on
            // ait pu pré-remplir. On replace le pré-remplissage juste après, en gardant le focus.
            if (addNewToggle) {
                addNewToggle.addEventListener('click', () => {
                    setTimeout(() => {
                        prefill();
                        newTermInput.focus();
                        newTermInput.select();
                    }, 0);
                });
            }
            newTermInput.addEventListener('focus', prefill);
        }

        // 5. Texte indicatif sous le formulaire d'ajout
        if (addWrapper && !addWrapper.querySelector('.pf-hint-add')) {
            const hintAdd = document.createElement('p');
            hintAdd.className = 'description pf-hint-add';
            hintAdd.textContent = 'Titre exact commun aux formats. Si un autre format existe déjà, ne pas oublier de l’ajouter au groupe ici créé.';
            addWrapper.appendChild(hintAdd);
        }
    });
    </script>
    <?php
};

add_action('admin_footer-post.php',     $admin_footer_product);
add_action('admin_footer-post-new.php', $admin_footer_product);