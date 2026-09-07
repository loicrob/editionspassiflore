# CLAUDE.md — Éditions Passiflore

> Ce fichier a été élagué le 2026-07-31 pour réduire le coût en tokens de chaque session (passage au forfait Pro). Ce qui a été retiré : les paragraphes « Vérifié » (logs de tests headless), les récits de découverte de bug pas à pas, les mesures px avant/après. Ce qui reste : tout invariant, convention, avertissement ⚠️ ou référence encore utile pour finir le projet. L'historique détaillé (mesures, dates, sessions de debug) reste consultable dans `git log`/`git blame` si jamais nécessaire.

## Discipline documentaire — ne pas re-alourdir ce fichier

`CLAUDE.md`, `docs/design-system.md` et les fichiers de `memory/` sont chargés **intégralement à chaque session** — chaque octet ajouté ici a un coût permanent, pas un coût unique. Avant d'ajouter du contenu à l'un des trois :

- **N'écrire que ce qui reste vrai et utile pour la suite du projet** — jamais un journal de ce qui vient d'être fait. Le code est la source de vérité sur son propre fonctionnement ; `git log`/`git blame` porte l'historique de comment on y est arrivé.
- **Interdit** : paragraphes « Vérifié »/logs de test headless, récits de debug pas à pas (« mesuré X puis Y », « constaté le [date] », « bug signalé le… »). Un ⚠️ gotcha = la règle + une clause « pourquoi » en une phrase, jamais la session de découverte qui y a mené.
- **Avant d'ajouter une mémoire** (`memory/*.md`) : vérifier qu'elle n'est pas déjà dérivable du code (architecture, conventions, chemins de fichiers) ni déjà couverte par ce fichier — sinon c'est un doublon à élaguer plus tard. Mettre à jour une mémoire existante plutôt que d'en empiler une nouvelle sur le même sujet.
- **Signal de dérive** : si une règle s'entoure de plusieurs paragraphes de justification, ou si l'un de ces fichiers regonfle nettement au-delà de sa taille actuelle, c'est le moment d'élaguer — pas d'attendre un futur changement de forfait pour le faire.

## Project overview

WordPress + WooCommerce migration of [www.editions-passiflore.com](https://www.editions-passiflore.com) (currently in PrestaShop) for a French independent publishing house. The site is a book catalog with e-commerce.

- **Theme:** Kadence (parent) + `kadence-child` (all custom code lives here)
- **Custom fields:** Secure Custom Fields (SCF) — **not ACF**, even though `advanced-custom-fields` is also installed (ignore it)
- **Custom post types / taxonomies:** registered via CPT UI plugin
- **Local environment:** Local by Flywheel (PHP 8.2, Nginx, MySQL)
- **Production hosting:** Ionos — `www.editions-passiflore.com` est en production depuis le 2026-08-11 (même compte que l'email quotidien + les autres sites du client ; voir mémoire `hosting_decision`)
- **Kadence Blocks plugin:** not installed; layouts built in PHP templates

---

## Development environment

| Ressource | Valeur |
|-----------|--------|
| Site local | `https://editions-passiflore.local` |
| Admin WP | `https://editions-passiflore.local/wp-admin/` |
| Kadence customizer | `https://editions-passiflore.local/wp-admin/customize.php` |
| WP-CLI | Depuis Local → *Open Site Shell* (WP-CLI dans le PATH) |
| BDD | `local` / user `root` / pass `root` / prefix `wp_` — socket MySQL (voir mémoire `db_access`) |
| PHP local | `~/Library/Application Support/Local/lightning-services/php-8.2.*/bin/darwin-arm64/bin/php` |

**Palette Kadence active (second-palette) :**

| Slot | Hex | Usage |
|------|-----|-------|
| palette1 | `#c62836` | **Rouge Passiflore** — couleur principale |
| palette2 | `#a0212c` | Rouge foncé (survols, variante) |
| palette3 | `#5e524d` | Texte / gris chaud |
| palette4 | `#1a1615` | Quasi-noir |
| palette6 | `#666666` | Gris neutre / texte secondaire (`--pf-muted`) |
| palette7 | `#F5F0E8` | Crème soutenu (`--pf-cream-dark`) |
| palette8 | `#FAF6F0` | Fond crème principal (`--pf-cream`) |
| palette9 | `#FCFBF7` | Fond crème clair (`--pf-surface-alt`) |
| palette10 | `#e0d8cc` | Bordures / filets (`--pf-border`) |

> Couleurs sémantiques `--pf-*` (dans `style.css`, pointant sur la palette) : `--pf-accent` (p1), `--pf-accent-dark` (p2), `--pf-text` (p3), `--pf-heading` (p4), `--pf-muted` (p6), `--pf-cream-dark` (p7), `--pf-cream` (p8), `--pf-surface-alt` (p9), `--pf-border` (p10), `--pf-surface` (#fff), plus `--pf-border-light` (`#e0d8cc`, hors palette), `--pf-sand` (`#D9C8B0`, badge « attente ») et `--pf-text-dim` (`#8b7a72`). Préférer ces tokens aux hex en dur. L'ancien or/bois de l'étagère (`#a89474`) vit dans les `--plank-color-*` de `bookshelf.css`, indépendant de la palette.

---

## Repository

Git repository at the project root. Only custom code is versioned (WordPress core, third-party plugins/themes, and uploads are excluded via `.gitignore`).

- **`.gitignore`** — excludes WP core, third-party plugins, uploads, DB dumps (`*.sql`), Local by Flywheel env
- **`README.md`** — setup instructions
- **`setup.sh`** — installs all required plugins and activates the child theme via WP-CLI (run from Local's "Open Site Shell")

---

## WordPress data model

> **Référence SCF complète :** `docs/scf-export-2026-07-14.json`. Consulter pour clés de champs, logiques conditionnelles, types exacts.

### Books → WooCommerce `product` post type

Books are WooCommerce simple products. Cover image = WooCommerce featured image (`get_post_thumbnail_id()`), not an SCF field.

**SCF group `group_69c7d37956f1c` "Informations du livre" — onglets et champs clés :**

> ⚠️ `titre` supprimé de SCF → utiliser `post_title` / `$product->get_name()`. Ne jamais faire de `meta_query` sur `titre`.

| Onglet SCF | Champs principaux |
|---|---|
| Informations principales | `sous-titre`, `distinctions` (repeater), `extrait` (file PDF → id) |
| Contributions | `contributions` (repeater : `type`, `assignation`, `fiche-auteur` → auteur id, `nom_de_l'auteur`) ; `nom_de_plume`, `illustration_de_couverture` |
| Autres images | `quatrieme_de_couverture`, `tranche`, `autres` (repeater → image id) |
| Caractéristiques | `nouveaute`, `date_de_parution` (**retourne `Ymd`**), `disponibilite`, `public`, `nombre_de_pages`, `type`, `type_de_reliure`, `langues` (checkbox) |

> `disponibilite = 'epuise'` **exclut** le livre de la recherche globale du header (avec ses auteurs/événements), en union avec `_stock_status` WooCommerce (voir « Recherche globale »). Le catalogue continue de les afficher.

| ~~Livres associés~~ | **Onglet supprimé de SCF** → géré globalement (voir « Groupes de livres ») |
| Liens et fichiers | `lien_place_des_libraires`, `articles_de_presse`, `videos`, `podcasts` |
| Avis | `avis_des_lecteurs`, `avis_des_libraires` (repeater : titre, auteur, date_de_publication, avis) |

**Points d'attention :**
- `date_de_parution` : `return_format = Ymd` → parser avec `DateTime::createFromFormat('Ymd', $val)`
- `contributions` → `fiche-auteur` est un `multi_select` taxonomy (retourne id)
- Champs supprimés (ne plus utiliser) : `mots-cles`, `meta_description`, `resume`, `collection`, `thematique`, `format`, `isbn`, `dimensions`, `prix_ht`, `prix_ttc`, `livre_numerique`, `version_grands_caracteres`, `version_litterature_generale`
- **Livres associés supprimés de SCF** (gérés en global) : `autres_ouvrages_de_la_suite` + `ordre_dans_la_suite` (supprimés sans remplacement), `autres_ouvrages_de_la_serie` + `ordre_dans_la_serie`, `vous_aimerez_aussi`, `traductions`. ⚠️ L'onglet « Livres associés » reste à supprimer manuellement dans l'admin SCF.

**Avis lecteurs — deux sources qui coexistent** (`inc/book-single-tabs.php`) :
- **Curés par l'éditeur** : repeaters SCF `avis_des_lecteurs`/`avis_des_libraires` → sous-bloc « Sélection de l'éditeur ».
- **Déposés par les visiteurs** : avis WooCommerce natifs (table `comments`), sans étoiles, nom requis/email retiré, modérés (`comment_moderation = 1`) → sous-bloc + formulaire. Nécessite `comment_status = open` (réglé en masse).
- Anti-spam au dépôt : honeypot + HMAC de timestamp (`passiflore_avis_spam_check` sur `preprocess_comment`), rejet avant enregistrement, modérateurs exemptés.
- **Réponses de l'éditeur** (`comment_parent`) affichées dès qu'**approuvées** (`passiflore_avis_public_reply()`). ⚠️ Filtre obligatoire sur `user_can(…, 'moderate_comments')` : `comment_parent` est un champ caché falsifiable, sans ce filtre une approbation par erreur ferait passer un visiteur pour l'éditeur. D'où aussi l'abandon de `number => 1` (une réponse falsifiée plus ancienne masquerait la vraie).
- **Gestion de son propre avis — sur la fiche livre, pas dans le compte** : l'auteur connecté voit son avis publié **et** en attente, bouton « Supprimer » (AJAX `pf_avis_delete`).
  - Mécanisme unique : `include_unapproved` de `WP_Comment_Query`. ⚠️ **Jamais `[0]`** : `user_id = 0` désigne tous les invités et publierait chaque avis d'invité en modération → tableau vide quand personne n'est connecté.
  - Un avis dont on est l'auteur n'est jamais replié derrière « Voir tout ».
  - Endpoint `pf_avis_delete` : `wp_trash_comment()` (restaurable). ⚠️ Trois points : (a) 403 réservé au nonce/absence de session (un refus légitime part en 404, le client traite tout 403 comme session expirée) ; (b) enregistré aussi en `nopriv` (sinon session expirée = « 0 » brut en 400 avalé en silence par le JS) ; (c) liste blanche de statuts `['1','0']` (rejouer `wp_trash_comment()` sur un commentaire déjà en corbeille casse `_wp_trash_meta_status` et la restauration). Les réponses sont trashées en cascade avec leur avis.
  - Un avis rattaché à un compte ne l'est que par `user_id` → seuls les avis déposés connecté sont supprimables par leur auteur.
- Notifications email (`transition_comment_status`, `wp_insert_comment` anti-doublon via `_pf_reply_notified`).

**Custom WooCommerce product meta** (managed by `Passiflore_Bookshelf`): `_book_pages` (spine thickness calc, sync avec `nombre_de_pages`), `_book_width_mm`, `_book_height_mm`.

**`format_groupe` taxonomy** (CPT UI) : 1 seul terme/produit. Chaque terme = un titre existant en plusieurs formats (classique, grands-caractères, numérique…), toutes les éditions du même livre partagent le terme.

---

### Œuvre / format / titre — saisie unifiée (`inc/format-groupe.php` + `inc/product-format-admin.php`)

Trois éléments doivent s'accorder entre éditions d'une même œuvre : le titre (racine commune + suffixe), la taxonomie `format_groupe`, l'attribut `pa_format_particulier`. Ils sont saisis **en un seul geste** sur l'écran produit — on choisit l'œuvre, puis le format ; le reste en découle.

- **`classique` n'est pas un terme** : c'est l'ABSENCE de terme `pa_format_particulier`. Ne jamais créer de terme nommé ainsi.
- **Ordre canonique des formats = `pf_format_order()`**, dérivé de l'ordre glissé-déposé des termes de l'attribut (Produits → Attributs → Format particulier), classique en tête. ⚠️ **Aucune liste de formats en dur nulle part** — une liste figée avait déjà laissé `folio` invisible (groupe sans représentant). ⚠️ `hide_empty => false` obligatoire à la lecture des termes : `poche`/`folio`/`audio` sont à 0 produit.
- **Représentant : une seule règle**, `pf_group_rank()` — (1) choix manuel s'il n'est pas épuisé, (2) sinon 1er format non épuisé dans l'ordre canonique, (3) tous épuisés → le choix manuel reprend la main, à défaut le 1er de l'ordre. Épuisé = `_stock_status = outofstock` OU `disponibilite = epuise` (`onbackorder` = à paraître **n'est pas** épuisé). Choix manuel = term meta `_pf_group_rep` sur le terme de groupe (un seul emplacement → pas de conflit ; un override périmé est ignoré, rien à nettoyer).
- `Passiflore_Bookshelf::get_group_representative()`/`_batch()`, `pf_bg_representative()` et `passiflore_dedup_by_format_groupe()` sont **tous des façades** sur ce résolveur — ne pas y réintroduire de logique propre.
- **Titre** : `pf_format_root()` retire le suffixe **exact** du format porté par le produit ; si le titre ne s'y termine pas, il est renvoyé tel quel (jamais de renommage silencieux). ⚠️ Ni la regex à liste blanche ni le `\([^()]*\)$` de `spine_label()` ne conviennent ici. Contexte `raw` obligatoire (`display` texturiserait les apostrophes).
- **Écran produit** : le champ natif « Nom de produit » porte la **racine** (filtre `edit_post_title`, gardé sur `post.php?action=edit` seul — la liste et le Quick Edit gardent le titre complet) ; le nom réel est composé sur `wp_insert_post_data` **@9**, avant `post-slug-sync.php` @10 qui en dérive le permalien. Les termes, l'attribut, le représentant et la cascade de renommage sont écrits sur `save_post_product` **@25**.
- ⚠️ **L'onglet Attributs est masqué** : privé de `attribute_names` dans le POST, `WC_Meta_Box_Product_Data::save()` @10 **vide `_product_attributes`** en laissant les relations de termes. C'est l'écriture @25 qui rétablit les deux **en verrou de phase** — restaurer l'onglet si un autre attribut produit devient nécessaire.
- ⚠️ **Verrou de ré-entrance `pf_fmt_busy()`** : la cascade de renommage appelle `wp_update_post()` sur les éditions sœurs, ce qui recomposerait un titre déjà composé (« Titre (grands caractères) (grands caractères) »).
- Un groupe n'a de sens qu'à ≥ 2 membres : un détachement qui le ramènerait à 1 supprime le terme.
- ⚠️ Données du sélecteur passées par `wp_add_inline_script` + `wp_json_encode`, **pas `wp_localize_script`** (qui transtype les scalaires de premier niveau en chaînes et casserait les comparaisons d'ID).

---

### Livres associés → outil global « Groupes de livres » (`inc/book-groups-admin.php`)

Remplace les anciens champs SCF par-fiche. Géré depuis **Produits → Groupes de livres** (3 onglets) : recherche AJAX, ajout par auteur, drag-reorder, déduplication par `format_groupe`. Picker partagé : `assets/js/book-picker.js` (`window.pfBookPicker`), utilisé aussi par `event-admin.js`.

| Relation | Stockage | Sens |
|---|---|---|
| **Série** | Taxonomie `pf_serie` (1 terme/livre) ; ordre = term meta `_pf_serie_order` | Symétrique |
| **Traductions** | Taxonomie `pf_traduction` (1 terme/livre) ; ordre = term meta `_pf_traduction_order` | Symétrique |
| **Vous aimerez aussi** | Post meta `_pf_vous_aimerez` (tableau ordonné d'IDs) sur le représentant source | Orienté |

- **Granularité œuvre** : on stocke des **représentants** de `format_groupe` (édition classique, `pf_bg_representative()`). Pour les taxonomies, le terme est posé sur toutes les éditions de chaque œuvre membre.
- **Composition = taxonomie** (source de vérité) ; **ordre = term meta** (entrées périmées ignorées). Lecture front : `pf_bg_group_member_reps()`.
- Une seule série par livre (`wp_set_object_terms(..., false)`).
- Ordre = drag-reorder uniquement, pas de badge « Tome X ».
- Rendu fiche livre : `passiflore_get_livres_lies_sections()` (`inc/book-single-tabs.php`), ré-aiguillé vers le format consulté via `passiflore_ids_in_format()`, rendu avec `[passiflore_etagere ids="..."]`.

---

### Offre « version numérique » à l'achat d'un livre papier (`inc/numerique-offer.php`)

Incite à acheter la version **numérique** (formats *classique*/*grands caractères*) à tarif réduit. Deux JS front : `assets/js/numerique-offer.js`, `numerique-cart-nudge.js`.

- **Réglage** : Boutique → « Offre version numérique » (entre Clients et Codes promo, réordonné via `pf_numerique_reorder_submenu`). Option `pf_numerique_offer` : deux entrées (`classique`, `grands-caracteres`), chacune `{mode, value}` (`mode ∈ {disabled, percent, fixed, free}`). Dormant par défaut.
- **Modèle** : format source lu sur `pa_format_particulier` ; numérique compagnon = membre du même `format_groupe` avec `pa_format_particulier = numerique`. Point d'entrée unique : `pf_numerique_offer_for($physical_id)`.
- **Fiche livre** : case → attribut `data-pf_add_numerique` sur le bouton d'ajout (le JS cœur WooCommerce recopie tous les `data-*` dans l'AJAX d'ajout, aucun endpoint custom). Serveur : `woocommerce_add_to_cart` (`pf_numerique_maybe_add_companion`) lit `$_REQUEST['pf_add_numerique']`, ajoute avec cart-item meta `pf_numerique_companion` (garde anti-récursion).
- **Masquage si numérique déjà au panier** : côté **client uniquement** (`.is-in-cart`, jamais au rendu — page cacheable). Source de vérité = mini-panier du header (seul fragment reflétant le panier réel derrière un cache) ; `MutationObserver` resynchronise à chaque changement de panier.
- Infobulle partagée `.pf-numerique-tip` (`pf-tooltip.js`, `preventClick` car l'icône vit dans le `<label>`), réutilisée dans l'encart panier.
- **Prix** : `woocommerce_before_calculate_totals` fixe le prix compagnon tant que le papier est au panier ; retiré → prix plein (idempotent, recalcul depuis le prix régulier). Fonctionne en blocs/Store API.
- **Encart panier** (`numerique-cart-nudge.js`) : endpoints `pf_numerique_cart_offers`/`pf_numerique_add_companion`. Confirmation via relais `sessionStorage` `pfCartToast` (lu+effacé par `cart-toast.js`, car `add_to_cart()` n'émet aucune notice depuis ce chemin). Le rechargement nettoie l'URL (`add-to-cart`/`quantity` retirés) pour éviter un double-ajout.
- Ligne « Offre » via `woocommerce_get_item_data` (masquée si le papier est retiré).
- **Mini-panier Kadence** : drapeau `pf_in_mini_cart` supprime la ligne « Offre », affiche prix barré + promo reconstruits (`pf_numerique_mini_cart_price` — l'objet produit ne porte pas toujours encore le prix modifié au moment du rendu).
- Numérique toujours `sold_individually` (quantité verrouillée à 1, sélecteur de quantité retiré en blocs).
- Articles du mini-panier = petites `.pf-card` cliquables (motif partagé avec les cartes événement).

---

### Livres numériques — page compte, lecteur ePub, stockage protégé

Trois fichiers : `inc/epub-storage.php`, `inc/class-ebooks.php`, `assets/js/epub-reader.js`.

#### Sécurité — ePub protégés (fuite fermée le 2026-07-30)

Les 64 ePub étaient auparavant dans la médiathèque, donc listables via `/wp-json/wp/v2/media` sans authentification (tout le catalogue numérique téléchargeable gratuitement). Correctif, trois couches :
- Fichiers déplacés dans `uploads/pf-epub/`, nom à **entropie** (`9782379460005-s6AZFh.epub`) — pas juste l'ISBN (public, imprimé sur les livres).
- Les 64 attachments supprimés (ferme la fuite REST — `wp/v2/media` ne liste que des posts).
- Règle serveur `.htaccess` (`Require all denied` + repli 2.2 + `Options -Indexes`), **écrite par PHP** (`pf_epub_ensure_dir()`) car `uploads/` est gitignoré.

**⚠️ Contraintes à ne jamais lever :**
- Sur un hôte **nginx**, le `.htaccess` est inopérant — il faut le bloc équivalent (`location ~* ^/wp-content/uploads/pf-epub/ { deny all; }`). Ionos est Apache, `.htaccess` y suffit.
- **`woocommerce_file_download_method` doit rester `force`** (lecture par PHP `readfile_chunked()`, pas de redirect vers l'URL réelle).
- **Ne jamais réintroduire d'ePub par la médiathèque sans passer par le routage** : `pf_epub_upload_prefilter`/`pf_epub_upload_dir` placent tout nouvel ePub dans le répertoire protégé sous nom non devinable ; `pf_epub_strip_attachment_record()` (hook `add_attachment`) supprime ensuite le post attachment lui-même — sans ce dernier, l'écran d'admin en créerait un quand même, listable via `wp/v2/media` malgré le bon emplacement du fichier (fuite découverte le 2026-08-05 sur un envoi fait avant le déploiement de ce hook).

**Écran produit — panneau natif « Fichiers téléchargeables »** (`inc/epub-storage.php`) : champ « Nom » masqué (jamais lu — le libellé client est toujours composé depuis le titre, cf. `class-ebooks.php`) ; champ « URL du fichier » en lecture seule (`readonly`, pas masqué — seule la saisie manuelle est bloquée, `Choisir un fichier` continue de l'alimenter en JS) car c'est le seul moyen de retrouver le nom à entropie sur le disque pour supprimer un fichier à la main (SFTP) — plus aucun attachment listable en médiathèque. Sur une ligne déjà remplie, le bouton devient « Remplacer le fichier » (confirmation, vidage client des seuls champs texte). ⚠️ `_wc_file_hashes[]` n'est jamais vidé : ce hash est le `download_id` des droits déjà accordés (`wc_customer_download`) — le préserver permet à un remplacement, dans la même sauvegarde, de rendre le nouveau fichier aux client·es déjà ayant droit sans repasser par un achat. Enregistrer le produit avec le champ resté vide supprime réellement l'entrée.

**Chemins stockés en RACINE-RELATIF** (`/wp-content/uploads/pf-epub/x.epub`), jamais en URL absolue — portable face à un changement de domaine/chemin disque (`WC_Download_Handler::parse_file_path()` a une branche `/wp-content` dédiée).

**Répertoires de téléchargement approuvés** (`wc_downloads_approved_directories_mode = enabled`) : `pf_epub_register_approved_dir()` (sur `admin_init`) déclare le répertoire (`file:///wp-content/uploads/pf-epub/`, forme normalisée indépendante de l'hôte) — sans quoi un simple « Mettre à jour » produit coupe l'accès des clients en silence (`set_enabled(false)`).

#### Page `/mon-compte/livres-numeriques` (`inc/class-ebooks.php`)

Réutilise l'endpoint `downloads` (clé inchangée), slug forcé en `livres-numeriques` **par filtre `woocommerce_get_query_vars`** (l'option est lue trop tôt par `WC_Query::init_query_vars()`). Callback de l'endpoint remplacé (pas de surcharge de template).
- **`entitled_downloads()` = source unique** du droit d'accès (dédup par produit, ePub seulement, quotas/expiration respectés).
- Liste de téléchargement direct dans un `<details>` (chemin **compté** WooCommerce, contrairement au lecteur).
- Entrée de menu masquée si aucun ePub.

#### Lecteur ePub (`assets/js/epub-reader.js` + `assets/css/epub-reader.css`)

epub.js 0.3.93 + JSZip 3.10.1 **auto-hébergés** dans `assets/vendor/` (epub.js est UMD, lit le global `JSZip`).
- Ouverture via `data-pf-epub` (attribut de shortcode `epub-reader="true"`) — `href` = permalien = repli sans JS.
- **Endpoint de service `?pf_epub=<id>`** : contrôle d'accès + `readfile()`. **Aucune écriture dans `wp_wc_download_log`, aucun décrément** (sinon chaque lecture partielle compterait comme un téléchargement).
- **Aucun nonce sur la lecture** (politique du projet, cf. « Sécurité AJAX ») — la sauvegarde de position, elle, garde son nonce + `pfSessionExpired({mode:'confirm'})` (jamais `'reload'`, qui jetterait la position en cours).
- ⚠️ **`wp_unslash()` obligatoire sur `$_SERVER['HTTP_IF_NONE_MATCH']`** (magic-quotes échappe les guillemets, casse le match d'ETag en silence).
- ⚠️ **epub.js rend dans une `<iframe>`** : écouteurs à poser **dans chaque vue** via `rendition.on('rendered', (s, view) => …)`.
- ⚠️ **`location.start.percentage` vaut 0** tant que `book.locations` n'est pas généré (tâche de fond) — afficher le chapitre en attendant.
- **Mémoire de position** : user meta `_pf_epub_positions`, debounce 1,5s + `sendBeacon` au `pagehide`, `GET_LOCK` (multi-onglets), reprise par CFI avec repli au début si périmé. Lien profond `/mon-compte/livres-numeriques/<id>/` + `popstate`.

#### Accueil du compte — grille de tuiles (`inc/account-hub.php`)

Les « Suggestions de Passiflore » ont été **retirées** (moteur `pf_reco_*` supprimé).
- Page (pas une redirection) : `inc/account-auth.php` amène déjà le visiteur sur `/mon-compte` en un seul saut ; rediriger réintroduirait un second saut dépendant de l'état du client.
- Tuiles dérivées de `wc_get_account_menu_items()` (ordre/masquages hérités gratuitement), chaque tuile porte une ligne d'état.
- Nav latérale masquée **sur le hub uniquement** (`is_wc_endpoint_url()` sans argument). ⚠️ Un lien « Se déconnecter » est donc **obligatoire** sous la grille (nav masquée + `customer-logout` écarté des tuiles).
- ⚠️ Ne pas grep-and-delete sur « reco » : `.pf-account-reco`, `.pf-reco`, `.pf-shelf-head`, `.pf-shelf-slot` sont émis par `Passiflore_Reading_List` et interrogés par `shelf-bookmarks.js` (toujours actifs).
- `inc/recommendations.php` garde son nom (ne contient plus que l'ordre du menu, les champs d'inscription et la suppression de compte) — renommer serait pur churn.

---

### Consentements du tunnel de commande (`inc/checkout-consent.php`)

Deux cases obligatoires, **sans JavaScript** (tunnel en blocs/Store API, hooks classiques morts) via l'API **Additional Checkout Fields**.

1. **Acceptation des CGV**, toujours affichée. `woocommerce_terms_and_conditions_page_id` résolu par **slug** `conditions-generales-de-vente` (portable) ; attribut `checkbox` du bloc forcé à `true` via `render_block_data` (faux par défaut).
2. **Renonciation au droit de rétractation**, affichée/obligatoire **uniquement si le panier contient un article `is_downloadable()`** (pas le terme `numerique` — propriété qui déclenche réellement l'article, gratuite, couvre un futur format téléchargeable). `woocommerce_downloads_grant_access_after_payment = yes` donne l'accès dès le paiement, d'où l'obligation légale (L. 221-28, 13°).

**Conditionnalité sans JS maison** : `pf_cart_has_numerique()` exposé dans `cart.extensions.passiflore.has_numerique` (`woocommerce_store_api_register_endpoint_data`) ; règles JSON-Schema `required`/`hidden` pointant dessus.

- ⚠️ **Enregistrer l'extension sur `woocommerce_init`, jamais `woocommerce_blocks_loaded`** (se déclenche avant le chargement du thème → callback jamais appelé, la case s'affiche et se comporte normalement mais n'est **jamais obligatoire** — commande numérique validée sans consentement, en silence).
- ⚠️ Les `required` internes des règles JSON-Schema sont indispensables (sinon une extension absente satisfait la règle par vacuité, rendant la case obligatoire pour tout le monde y compris panier papier).
- **Garde-fou serveur** (`woocommerce_blocks_validate_location_order_fields`) gardé **au POST final uniquement** via un drapeau sur `rest_pre_dispatch` (sinon les PUT/PATCH partiels du tunnel affichent l'erreur dès le chargement de `/commander`).
- **Traçabilité** : `_wc_other/passiflore/renonciation-retractation` (`'1'`/`'0'`) + `_pf_renonciation_date` + `_pf_renonciation_texte` (constante `PF_CONSENT_NUMERIQUE_TEXTE` = source unique du libellé). Rendu admin via `woocommerce_admin_order_data_after_order_details`. Date via `wp_date()` (pas `get_date_from_gmt()`, qui ne traduit pas les mois).
- ⚠️ **`woocommerce_filter_fields_for_order_confirmation` obligatoire** : sans lui, une commande **papier** affiche quand même la phrase juridique + « Non » (WooCommerce transtype une méta absente en `false`, qui passe le filtre « valeur vide »).

> Les CGV contiennent encore des `[À COMPLÉTER: …]` (moyens de paiement, délais, médiateur…) — à solder avant mise en ligne.

---

### Connexion / création de compte (`inc/account-auth.php` + override `woocommerce/myaccount/form-login.php`)

WooCommerce affiche connexion et inscription côte à côte nativement. Remplacé par **un seul panneau à la fois** (connexion par défaut) — cohérent avec la commande invité activée.

- **État dans l'URL** (`/connexion` vs `/creer-un-compte`, `pf_auth_current_state()`) : les formulaires postent vers l'URL courante et WooCommerce ré-affiche cette même URL en cas d'erreur — sans l'état dans l'URL le visiteur retomberait sur le formulaire de connexion avec un message orphelin. Bascules = vrais liens (`data-pf-auth-target`), fonctionnent sans JS ; `account-auth.js` évite juste le rechargement (`history.replaceState`).
- **URL dédiées** via règle de réécriture → `index.php?page_id=<myaccount>&pf_auth=login|register`. `is_account_page()` authentiquement vrai. `?action=register` toujours supporté.
  - `redirect_canonical` neutralisé quand `pf_auth` présent (sinon 301 vers `/mon-compte`).
  - Flush auto-cicatrisant (option `pf_auth_rw_version`). ⚠️ `preg_quote()` volontairement absent du slug (échappe le tiret depuis PHP 7.3, rendrait la table illisible — les slugs sont des littéraux, pas une entrée utilisateur).
  - Deux garde-fous (`template_redirect` prio 1) : déjà connecté sur une URL d'auth → page compte ; 404 si les règles manquent en base → redirection.
  - Après connexion/inscription → page compte en **un seul saut** (`woocommerce_login_redirect`/`_registration_redirect` — `wp_nonce_field()` pose un `_wp_http_referer` qui repartirait sinon vers `/connexion`).
  - **Restauration bfcache** : le bouton Précédent réaffiche le HTML mémorisé (état d'auth potentiellement obsolète) → `pageshow` + `event.persisted` → `location.reload()`.
  - Après déconnexion → `/connexion` (`woocommerce_logout_default_redirect_url`), pas la page compte.
  - ⚠️ **Les points d'entrée du site doivent viser `pf_auth_url('login')`, pas `wc_get_page_permalink('myaccount')`** : header, tiroir mobile, bouton « liste de lecture » invité — tous conditionnés à `! is_user_logged_in()`.
    - Le bouton « liste de lecture » invité ne navigue plus au clic : toast d'invitation (7s, une seule à la fois) avec action « Se connecter ».
- Override de template justifié par des besoins markup-level (`<h1>`, placeholders, attributs clavier mobile) — libellés en placeholder mais `<label>` conservés en `.screen-reader-text`.
- Claviers mobiles : `inputmode="email"` etc. sur l'identifiant de connexion, **pas `type="email"`** (accepte aussi l'identifiant WordPress généré).
- Layout plein écran : `min-height: calc(100svh - var(--pf-auth-top))` — `svh` pour la stabilité face au repli de la barre iOS ; `--pf-auth-top` mesuré en JS (non exprimable en CSS pur : marges variables au-dessus du panneau).
- Fondu de placeholder au focus imposé par WooCommerce neutralisé **globalement dans `style.css`** (règle isolée — ne jamais grouper avec `::placeholder` standard, un pseudo-élément inconnu invalide toute la liste).

---

### Header collant — vrai `position: sticky` (`inc/header-sticky.php` + `style.css`)

Kadence ne pose jamais `position: sticky` : deux headers rendus (desktop/mobile), bascule JS en `position: fixed` au scroll. Causait une bande morte à 1024px pile (JS/CSS breakpoints désaccordés) et un seuil empoisonné à chaque franchissement de point de bascule.

**Correctif : `position: sticky` sur `#masthead`** (parent des deux headers, couvre toutes les largeurs) ; sticky JS de Kadence débranché (filtres `theme_mod_header_sticky`/`_mobile_` → `'no'`).
- `top` = `var(--pf-adminbar)` (barre d'admin WP, `fixed` en desktop).
- Verre dépoli **toujours actif**, sans classe d'état (écart crème opaque/translucide ≈ 1-2/255 au repos — la bascule ne coûtait rien visuellement).
- `#masthead` était déjà `position: relative` ; `#wrapper` en `overflow: clip` (ne casse pas sticky, contrairement à `hidden`).

**Logo fluide entre 1025-1065px** (`#main-header .site-branding img`) : sous ~1060px c'est le **menu** qui manquait de place (repassait à 2 lignes, cassait « Mon compte », visible **uniquement connecté** car « Connexion » est insécable). `clamp(135px, calc(100vw - var(--scrollbar-offset,0px) - 887px), 200px)`, **calibré panier rempli** (le badge décale le seuil de 20px). `max-width: none` obligatoire (un plafond en % rendrait la main à la grille `1fr`, plus petite que la formule). `100vw` corrigé par `--scrollbar-offset` (Kadence). Espace insécable dans « Mon compte » (`inc/header-hooks.php`) en ceinture.

**Header mobile** (`≤767px`, `#mobile-header .site-branding img`) : déclencheur = panier rempli (le burger débordait sous ~355px). `clamp(120px, calc(100vw - var(--scrollbar-offset,0px) - 196px), 150px)`.

---

### Recherche globale du header (`inc/class-recherche-globale.php` + `inc/search.php`)

**Pas de sélecteur de type** (retiré 2026-07-30) : cherche toujours dans tout, le filtrage par catégorie restant l'affaire des trois barres locales.

**Lots de `PAGE_SIZE = 4` par section**, suivis d'un bouton **« + de résultats »**.
- Une seule action AJAX `pf_global_search` (`section` + `offset`). ⚠️ `section` n'est **pas** un filtre de type : non vide = demande du lot suivant de cette seule section.
- `next_offset` fait autorité (`PAGE_SIZE` ne vit qu'en PHP).
- `SEED_SIZE = 6` figée et découplée de `PAGE_SIZE` (l'amorce auteurs/événements est reconstruite à l'identique par chaque requête — ne pas l'optimiser en sautant la requête livres).
- Section Auteurs : charge tous les termes (~80) et tranche en PHP (un ID SCF peut pointer un terme supprimé ; `get_terms(include)` en renverrait moins que demandé).
- Attente portée par le **bouton** (`.is-loading` + spinner), jamais le filet partagé (collé au bord haut, défile avec le contenu). `aria-disabled`, jamais `disabled` (blurrerait le focus clavier).

**Trois règles d'éligibilité des livres, propres à cette recherche** — `pf_search_products_ranked_global()` (`inc/search.php`), qui **enveloppe** `pf_search_products_ranked()` **sans y toucher**.

> ⚠️ **Ne PAS la substituer à `pf_search_products_ranked()`** dans le catalogue ni dans le sélecteur admin événements : ces deux-là doivent continuer à trouver les épuisés.

1. **Épuisé exclu** — union `disponibilite = 'epuise'` OU `_stock_status = 'outofstock'` (l'union est inopérante aujourd'hui, gardée contre la dérive inverse — rien ne synchronise les deux sources).
2. **Visibilité WooCommerce** — terme `exclude-from-search` (« Boutique uniquement » + « Masqué »).
3. **Mis en avant en tête** — partition stable.

**Ordre : exclusions PAR ÉDITION, puis déduplication** (jamais l'inverse) : `pf_bg_dedup()` remplace chaque édition par le représentant du `format_groupe` — filtrer après ferait disparaître une œuvre dont le classique est épuisé mais le numérique disponible.

**Seul le renfort disparaît** : un livre épuisé ne fait plus remonter ses auteurs/événements, mais un auteur/événement trouvé par son propre texte reste trouvé.

---

### Notices WooCommerce → toasts (`inc/wc-notices-toast.php` + `wc-notices-toast.js` + `wc-block-notices-toast.js`)

Seuls les **conteneurs** de notices sont interceptés — jamais un message ancré à un endroit précis (erreurs de champ du tunnel, bannières permanentes comme « aucun moyen de paiement »).

**A. Notices classiques** (`.woocommerce-notices-wrapper`) :
- 1 `<li>` de notice = 1 toast, fermable séparément.
- Wrapper injecté sur les pages en blocs (`render_block` sur `woocommerce/cart`/`checkout`, qui n'en rendent aucun nativement).
- Le CTA (`<a class="button">`) devient un bouton d'action du toast — **sauf** s'il pointe vers la page courante (retiré du texte mais aucune action créée).

**B. Notices React des blocs** (`wc-block-notices-toast.js`) : deux capteurs, aucun ne couvre l'autre.
- **Store `core/notices`** : la notice est retirée (`removeNotice`) aussitôt lue — sinon une notice à ID fixe resterait bloquée et une 2e tentative n'afficherait plus rien ; certaines (contexte `wc/all-products`) n'ont **aucun conteneur** pour les rendre.
- **DOM (`MutationObserver`)** : erreurs Store API passées en props (`additionalNotices`), jamais via le store. ⚠️ **Ne jamais retirer un nœud du DOM de React** (masqué en CSS, on recopie seulement). Anti-doublon 2s.

**Commun** : erreur = durée 0 (fermeture manuelle, `role="alert"`) ; succès/warning/info = défaut (5s). Sévérité → couleur d'icône, pas un aplat de fond. Amélioration progressive : conteneurs masqués uniquement par `html.pf-notices-js` (primer inline avant 1er paint) — sans JS, notices normales à leur place.

---

### Events → `tribe_events` post type (The Events Calendar)

SCF field group `group_69ea16cde9aef` — attaché à `tribe_events` :

| Field name | SCF type | Notes |
|---|---|---|
| `passiflore_participe` | true_false | Éditions Passiflore participe |
| `personnes_participant_a_l'evenement` | repeater | `assignation` (radio `fiche-auteur`/`champ-texte`), `fiche_auteur`, `nom_de_la_personne` |
| `evenement_marquant` | true_false | Si coché, apparaît dans « Événements marquants » même passé |

**`_pf_event_books`** (custom post meta, pas SCF) : tableau ordonné d'IDs produits, meta box (`inc/event-admin.php`), dédup par `format_groupe` à la saisie. Rendu : `[passiflore_etagere ids="..." mode="scroll"]`.

**Lieux (`tribe_venue`) — Département/Région** (`inc/venue-admin.php`) : deux champs sur le formulaire natif, choix contraint (vide ou valeur exacte de `PF_VENUE_DEPARTEMENTS`/`PF_VENUE_REGIONS`). Combobox JS (`assets/js/venue-admin.js`). Cascade CP→Département→Région. Case « nom = adresse » → titre `readonly` composé Rue sinon Ville (jamais vide, `pf_venue_composed_name()`), recomposé côté serveur à chaque enregistrement, persisté `_VenueNameIsAddress`. Sauvegarde via `venue[Departement]`/`[Region]` (mécanisme générique TEC) + validation dédiée. Indexé dans `_pf_search_index`. Champ natif « State or Province » masqué + synchronisé sur Département (alimente le bloc adresse public + JSON-LD natif). ICS `LOCATION` inclut l'adresse (pas dept/région).
- Chemin de création en ligne (mini-formulaire depuis la fiche événement) : câblage séparé via `tribe_events_venue_created` (le `save_meta()` natif y écrase après `wp_insert_post`, différent du chemin fiche autonome). Dropdowns « créer en tapant » désactivés pour lieu **et** organisateur (`tribe_events_linked_posts_dropdown_enable_creation`), remplacés par un bouton dédié.

**Header restructuré** (override `tribe/.../components/header.php`) : barre sticky « smart-hide » full-bleed sur les 3 vues (liste/mois/carte) — top-bar/recherche à gauche, « S'abonner »+switch à droite.
- Sticky posé sur le `<header>` lui-même (parent assez haut pour couvrir tout le contenu).
- Vue liste : séparateurs de mois full-bleed, collent sous le header, effet « push » simulé en JS (translateY).
- « S'abonner » : une seule instance (header), drapeau global supprimant le rendu par défaut en bas de page.
- `subscribe-calendar.js` : relabellise en action directe (Apple/Google détecté), pill scindé `.pf-splitbtn` + kebab pour les 5 autres options — amélioration progressive, menu natif intact sans JS.

**Vue liste — carte cliquable** (override `list/event.php` + `events.css`) : `.pf-card` + lien étiré `.pf-card-link`, titre non-lien, description via `passiflore_render_event_description_text()` (`post_content` toujours, `wp_kses` restrictif, clampé visuellement 2 lignes). Bloc meta (Lieu/Organisateur/Présence) remplace l'adresse complète native. Image à droite desktop (`contain`, absolue), en haut mobile (`cover` 16/9).

**Scroll infini bidirectionnel** (`inc/class-events-feed.php`) : endpoint AJAX `pf_events_feed` rend via `View::make_for_rest()` puis extrait les `<li>` en `DOMDocument`. Curseurs opaques (`next_url`/`prev_url`). Bas = IO auto ; haut = 1er lot au clic puis IO. `history.scrollRestoration='manual'` + primer inline `<head>` (sinon rechargement = tout en bas).

**Recherche événements** (`inc/class-events-search.php`) : moteur partagé `pf_search_events_ranked()` (aussi utilisé par la recherche globale). Endpoint `pf_events_search`, `PAGE_SIZE = 12`, offset simple (classement complet recalculé à chaque appel). Rendu de ligne **sans** passer par le pipeline Vue/Repository de TEC (reproduit directement le HTML des overrides depuis un objet `tribe_get_event()` — le pipeline natif force son propre tri et dépend de variables ambiantes). Éphémère (pas de sync URL).

**Vue « Carte »** (`inc/class-events-map.php`) : réimplémentation maison de la vue Carte payante d'Events Calendar Pro — vraie vue TEC V2 (slug `carte`), Leaflet auto-hébergé + tuiles OSM (pas de licence, pas de CDN).
- Un point = un lieu, infobulle = rangée de tuiles événement (pré-rendues serveur, `Passiflore_Event_Tiles::render_tile()`).
- `Leaflet.markercluster` : regroupe au dézoom, **clic sur un groupe zoome jusqu'à ce qu'il se scinde** ; groupe irréductible (lieux co-localisés, mêmes coordonnées à l'octet près — précision de géocodage « ville ») → zoom niveau ville+2 (15, `events-map.js`) puis spiderfy, au lieu de spiderfier sur place sans contexte. Une infobulle n'affiche donc jamais qu'un seul lieu.
  - `zoomToBoundsOnClick`/`spiderfyOnMaxZoom` **désactivés côté lib** : le clic est géré entièrement à la main (les deux cas, réductible et co-localisé) — les laisser actifs faisait tourner le handler interne de la lib EN PARALLÈLE du nôtre sur le même clic (elle vers son maxZoom, nous vers le niveau ville), et la double transition qui en résultait laissait le spiderfy s'ouvrir puis se refermer aussitôt tout seul.
  - ⚠️ **`moveend` seul ne suffit pas** pour déclencher le spiderfy après le zoom : il peut se déclencher avant la fin du décompte interne `_inZoomAnimation` de la lib, auquel cas `spiderfy()` s'auto-annule silencieusement (son propre garde-fou). D'où le double abonnement `moveend`/`animationend` avec re-vérification du drapeau à chaque déclenchement.
  - ⚠️ **Reclic sur un cluster déjà spiderfié : tester `layer._spiderfied === cluster` AVANT `isColocated()`, jamais après** — `spiderfy()` déplace réellement les marqueurs enfants vers leurs positions en éventail (`setLatLng`), donc `isColocated()` les verrait comme non co-localisés et tomberait dans `zoomToBounds()` (fitBounds sur des points artificiellement écartés → zoom jusqu'au maxZoom réel de la carte). Le cas déjà-spiderfié appelle `cluster.unspiderfy()` et s'arrête là, sans toucher au zoom.
  - ⚠️ **Cluster réductible : `cluster.zoomToBounds()` doit toujours recevoir un padding** (`zoomToCluster()`, `events-map.js`) — sans lui la lib retient le zoom maximal où les bornes remplissent EXACTEMENT le viewport, collant les repères scindés aux bords (pins rognés). Garde-fou obligatoire (zoom paddé ≤ zoom courant → repasser au cadrage serré d'origine), sinon un cluster large rend le clic mort (plus aucun zoom, le groupe ne se scinde jamais).
  - ⚠️ **`assets/vendor/leaflet/leaflet.markercluster.js` patché** (`L.Path.SVG` → `L.Browser.svg`, 2 occurrences) : le build vendored teste une API pré-1.0 qui n'existe plus dans Leaflet 1.9.4 (vendored ici) — le test échouait silencieusement et aucune tige de spiderfy (`.leaflet-cluster-spider-leg`) ne se dessinait jamais, y compris avant ce chantier. Réappliquer ce patch si le fichier est un jour remplacé par un téléchargement neuf.
- ⚠️ Le reset `.tribe-common *` (0,1,1) bat les sélecteurs à classe unique même sur du contenu injecté en JS (popup Leaflet, héritant d'un ancêtre `.tribe-common`) — toutes les règles single-class d'`events-map.css` doublées en (0,2,0).
- **Géocodage (Nominatim) côté écriture uniquement** : à l'enregistrement du lieu + backfill WP-Cron auto-drainant (5/lot). Cache `_pf_venue_lat/lng/geo_src` (stampé même en échec, anti-boucle) + `_pf_venue_geo_precision` (`street`/`city`/`manual`, absente = jamais géocodé). Throttle ≥1,1s/appel, résultats (succès et échecs) en transient 1h. Front = zéro appel réseau (hors chargement des tuiles OSM par le navigateur — RGPD documenté dans la politique de confidentialité).
- **Auto-remplissage Ville/CP/Département/Région/Pays à la saisie** (aperçu AJAX admin, `pf_venue_admin_fields_from_geocode()` dans `inc/venue-admin.php`) : réponse Nominatim lue avec `addressdetails=1`. Département résolu en cascade — `ISO3166-2-lvl6` (seule source couvrant Paris/Lyon/Corse) puis `county` puis préfixe du CP de la réponse puis, à défaut, préfixe du CP **saisi** (`$entered_zip`, repli pour les nœuds commune des DOM qui ne portent aucun `postcode`) — Région dérivée du département. Ville renseignée **seulement à précision rue** (jamais depuis `municipality` = arrondissement en France, pas la commune). CP renseigné à précision rue **ou** quand un seul candidat commune portait le nom saisi (`$commune_unique`, cf. ci-dessous) — l'ancienne règle « CP seulement à précision rue » n'était pas un principe mais la conséquence d'un CP de résultat commune arbitraire ; l'unicité lève ce doute. Hors France, Département/Région restent vides. Ville/CP/Pays n'écrasent jamais un champ déjà rempli ; Département/Région sont toujours rafraîchis tant qu'un résultat existe.
- **CP saisi jamais en texte libre** (`Passiflore_Events_Map::geocode_parts()`) : Nominatim déraille sur `q="{CP} {ville}, {pays}"` (ex. `40200 Mimizan` → un artisan constructeur, `93200 Saint-Denis` → l'objet code postal, ~450m du centre) — cascade à 4 tentatives : 1) rue structurée, 2) arrondissement (Paris/Lyon/Marseille) si le CP en désigne un, `city="{Ville} {N}e Arrondissement"`, garde sur `address.postcode === CP saisi` (⚠️ ordinal `1er`, jamais `1e` — dérape sur un hameau du Gers), 3) commune par sélection de candidats, `city=/country=/limit=25` + `pf_venue_pick_commune_candidate()` (`inc/venue-admin.php`) : filtre `addresstype` (city/town/village/hamlet, écarte l'objet CP/POI/`municipality`) → filtre nom exact (`pf_search_normalize()`) → arbitrage département/région déduits du CP saisi (jamais passé au 3ᵉ argument de `pf_venue_admin_fields_from_geocode()` lors de la résolution par candidat, sous peine de faire correspondre tous les candidats et dégénérer en « premier résultat » sans erreur), 4) `q="{ville}, {pays}"` en filet. Clé de cache de la tentative 3 indépendante du CP → ajouter/corriger un CP après la ville ne redéclenche aucun appel réseau.
- ⚠️ Un lieu créé en ligne depuis la fiche événement déclenche `save_post_tribe_venue` **avant** que `save_meta()` n'écrive l'adresse (`Tribe__Events__Venue::create()`) — la garde de ré-entrance de `geocode_on_save()` inclut donc l'adresse vue dans sa clé, pas seulement l'ID du lieu, sinon la passe « sans adresse » bloque la vraie passe qui suit (`tribe_events_venue_created`).
- **L'aperçu admin fait foi** (`inc/venue-admin.php` + `venue-geo-picker.js`) : les coordonnées affichées à la frappe sont persistées telles quelles dès que l'adresse postée (`venue[GeoKey]`) correspond encore aux metas enregistrées (`pf_venue_geo_key()` → `Passiflore_Events_Map::set_geocoded_coords()`) — le géocodage serveur (`geocode_on_save()`) ne sert plus alors que de repli (sans-JS, Quick Edit, clé périmée). The Events Calendar ne géocode rien lui-même, tout passe par ce module. Mode manuel (repère déplacé) : `_pf_venue_geo_precision = 'manual'`, `geocode_venue()` ne retouche plus les coordonnées tant que ce drapeau tient (l'adresse continue de servir au texte affiché) ; bouton « Revenir à l'automatique » l'efface.
- Recherche carte : endpoint `pf_events_map_search`, renvoie des **IDs** (pas de HTML), filtre les marqueurs existants côté client.

**Fiche événement (single)** — override classique `tribe-events/single-event.php` (chemin distinct des vues v2) : grille unique `.pf-event-hero` (head/media/body/sections) pour que l'image traverse en colonne collée. Sections : Horaires, Lieu (adresse + Dept/Région + mini-carte OSM mono-lieu), Organisateur, Présence, Livres associés (pleine largeur, dépasse la colonne image). Nav (`pf_sectionnav_bar()`, partagée avec la fiche livre) affichée si ≥3 sections.
- ⚠️ TEC pose `overflow:hidden` sur `.tribe-events-single > .tribe_events`, ce qui casse le sticky de la nav — shimmé en `overflow: visible !important`.

---

### SEO — désindexation des événements passés anciens (`inc/seo-events.php`)

Rank Math n'a pas de règle « noindex par date » ; posée ici. Événement terminé depuis > `PF_EVENT_SEO_STALE_AGE` (`-6 months`) → **noindex** + hors **sitemap**, sauf si `evenement_marquant`. ~97% des événements sont d'anciens passés.

- `rank_math/frontend/robots` calcule le noindex au vol (non stocké en meta) → le sitemap ne relit pas ce filtre, d'où un **second** filtre `rank_math/sitemap/entry` (`false` = exclu).
  - ⚠️ L'objet passé est un `stdClass` SQL brut, **pas** un `WP_Post` — tester par propriété, jamais `instanceof WP_Post`.
  - ⚠️ **Après déploiement, purger le cache sitemap** (`\RankMath\Sitemap\Cache::invalidate_storage()` ou `wp rank-math sitemap generate`).
- Type « Événements » activé dans Rank Math **en ligne uniquement**, pas en local.
- Interaction avec le déréférencement global (site en test, `blog_public = 0`) : tant que réglé ainsi, tout le site est noindex et ce filtre est redondant — sans réactivation nécessaire au repassage en public.

**Canonical des vues d'archive TEC — slash final retiré** (même fichier) : TEC force un slash sur `get_post_type_archive_link()`, la structure de permaliens du site n'en a pas → filtre applique `untrailingslashit()` (gardé par `use_trailing_slashes`).

**Rappel** : canonical absent = page `noindex` par conception de Rank Math (pas un défaut à corriger) — concerne les événements périmés, `product_tag` vides, recherche/404, panier/commander/mon-compte (forcés noindex par le module WooCommerce de Rank Math).

---

### Authors → `auteur` custom taxonomy

SCF field group `group_69c2ca10aa3d2` "Fiche d'auteur" :

| Field name | SCF type | Notes |
|---|---|---|
| `nom` | text | Requis |
| `prenom` | text | |
| `genre` | radio | `feminin`/`masculin` |
| `photo` | image (id) | Requis |
| `biographie_synthetique` | textarea | Requis |
| `biographie_complete` | wysiwyg | Requis |

Nom/slug/description du terme auto-synchronisés depuis SCF (`inc/auteurs.php`) ; champs natifs masqués en admin.

**Relations de termes `auteur` synchronisées depuis SCF** (`inc/auteurs.php`) : les livres ne référencent leurs auteurs que via le repeater SCF (postmeta), jamais par vraies relations de termes → sans correctif tous les termes `auteur` ont `count=0`, donc Rank Math les traite comme vides (noindex + hors sitemap) alors qu'ils affichent des livres.

- `passiflore_sync_auteur_terms()` pose la vraie relation (`wp_set_object_terms(..., false)`) depuis `passiflore_get_product_author_ids()`. Hooks `acf/save_post`@20 + `save_post_product`.
- **Toute contribution compte, quel que soit le rôle** (`type` = simple label, décision actée 2026-07-24) — les deux helpers partagés ne filtrent pas sur `type` (filtrage par rôle possible via `role=` de `[passiflore_etagere]`).
- Backfill : `passiflore_backfill_auteur_terms()`, une fois par environnement (flag `pf_auteur_terms_synced`). ⚠️ Après une ré-importation qui ne déclenche pas `save_post_product` : relancer la synchro (supprimer le flag, ou `wp eval 'passiflore_backfill_auteur_terms();'`).

---

## Child theme structure

```
kadence-child/
├── functions.php                    — Enqueues styles conditionally; requires all inc/ files
├── style.css                        — Design system global : tokens (:root) + composants .pf-* + bases + bloc newsletter + header collant
├── screenshot.png
├── taxonomy-auteur.php              — Author single/archive page template
│
├── inc/
│   ├── a11y.php                     — pf_new_window_note()/pf_new_window_label() : mention « (nouvelle fenêtre) » pour les liens target="_blank" (WCAG 3.2.5)
│   ├── admin.php                    — Removes color picker script
│   ├── auteurs.php                  — Author taxonomy hooks + [passiflore_auteurs] shortcode + sync des relations de termes
│   ├── term-slug-redirect.php       — 301 des anciens slugs de TERMES (auteur + product_cat) : équivalent maison de _wp_old_slug pour les taxonomies. ⚠️ Requête directe sur wp_termmeta (jamais get_terms()+meta_query, WooCommerce réécrit la jointure sur product_cat). Non couvert : les PAGES
│   ├── book-sheet.php               — Admin: removes auteur meta box from product, adds custom meta boxes
│   ├── book-single-tabs.php         — Fiche livre : sections sous le hero (nav sticky partagée), avis lecteurs (SCF + WooCommerce + formulaire, anti-spam), avis en attente visible par son auteur + suppression (pf_avis_delete), pf_distinction_icon() (source unique icône médaille)
│   ├── catalogues.php               — Catalogues PDF (repeater SCF sur la page Catalogue) : admin (acf_form()) + lecture front passiflore_get_pdf_catalogues()
│   ├── class-bookshelf.php          — Passiflore_Bookshelf — [passiflore_etagere] shortcode
│   ├── class-catalogue.php          — Passiflore_Catalogue — [passiflore_catalogue] shortcode (filtering, AJAX, panneau filtres mobile)
│   ├── class-recherche-auteurs.php  — Passiflore_Recherche_Auteurs — [passiflore_recherche_auteurs] shortcode
│   ├── book-groups-admin.php        — Produits → Groupes de livres : taxonomies pf_serie/pf_traduction + _pf_vous_aimerez
│   ├── event-admin.php              — Meta box _pf_event_books : AJAX search + add-by-author, drag reorder
│   ├── event-duplicate.php          — Duplication d'un événement (copie en brouillon). ⚠️ Metas via add_post_meta() jamais SQL direct (déclenche added_post_meta → commit tables tec_events/tec_occurrences)
│   ├── post-slug-sync.php           — Slug (événements + produits) suit le titre à chaque save (sans condition). ⚠️ Rien d'équivalent côté TAXONOMIES (auteurs, product_cat) hors term-slug-redirect.php. Même fichier : bouton « Modifier » du permalien retiré sur ces écrans (saisie manuelle sans effet) + aperçu live du slug (`get_sample_permalink_html`, `assets/js/admin-permalink-preview.js`)
│   ├── venue-admin.php              — Champs Département/Région (tribe_venue) : listes officielles + combobox + validation + indexation
│   ├── events.php                   — TEC: translation fixes, layout customizations, book rendering
│   ├── class-events-feed.php        — Passiflore_Events_Feed — vue liste : scroll infini bidirectionnel
│   ├── class-events-search.php      — Passiflore_Events_Search — barre de recherche /evenements (vue liste)
│   ├── class-events-map.php         — Passiflore_Events_Map + Passiflore_Map_View — vue Carte (Leaflet + OSM) + mini-carte fiche événement
│   ├── event-single.php             — Fiche événement (single) : passiflore_get_event_sections_parts()
│   ├── seo-events.php               — noindex + exclusion sitemap événements passés > 6 mois ; canonical archives TEC sans slash
│   ├── section-nav.php              — Composant partagé (fiche livre + événement) : pf_sectionnav_bar()/pf_sectionnav_sections()/pf_render_sectionnav()
│   ├── header-hooks.php             — Header customizations; [passiflore_account_btn] shortcode
│   ├── header-sticky.php            — Débranche le sticky JS de Kadence ; header colle via position:sticky sur #masthead (style.css)
│   ├── format-groupe.php            — Modèle œuvre/format/représentant : pf_format_order() (ordre canonique), composition/racine des titres, pf_group_members(), pf_group_rank() (LA règle du représentant), versions unitaire + lot
│   ├── product-format-admin.php     — Écran produit : sélecteur d'œuvre, menu de format, case représentant, écriture groupe+attribut+titre, onglet Attributs masqué
│   ├── modifier-produit.php         — format_groupe single-term (filet imports) ; aide catégories ; tri produits par défaut (date_de_parution DESC puis titre ASC)
│   ├── numerique-offer.php          — Offre version numérique promo : option pf_numerique_offer + admin + helpers + panier + prix + endpoints
│   ├── newsletter.php               — Bloc d'abonnement site-wide + endpoints AJAX (rendu via kadence_top_footer prio 5, dans <footer>)
│   ├── wc-notices-toast.php         — Notices WooCommerce → toasts : primer inline + enqueue des deux contrôleurs
│   ├── checkout-consent.php         — Consentements du tunnel (CGV + renonciation rétractation)
│   ├── account-auth.php             — Connexion/création de compte : URL dédiées /connexion et /creer-un-compte
│   ├── pageflip.php                 — Enqueues pageflip assets on single product pages
│   ├── epub-storage.php             — Stockage protégé des ePub (pf_epub_dir/stored_path/ensure_dir/is_protected_path)
│   ├── class-ebooks.php             — Passiflore_Ebooks — page /mon-compte/livres-numeriques, entitled_downloads(), endpoint ?pf_epub=<id>
│   └── account-hub.php              — Accueil du compte : grille de tuiles, nav latérale masquée sur le seul hub
│
├── assets/
│   ├── css/                         — account.css, epub-reader.css, auteur-single.css, auteurs.css, book-single.css, bookshelf.css, cart.css, catalogue.css, checkout.css, events*.css, event-single.css, pageflip.css, reading-list.css, recherche-auteurs.css, recherche-globale.css
│   └── js/                         — bookshelf.js, epub-reader.js, book-filter.js (recherche floue partagée `window.pfBookFilter`, consommée par book-picker.js/book-groups-admin.js/product-format-admin.js), book-picker.js (+ book-groups-admin.js/event-admin.js), product-format-admin.js, catalogue.js, scroll-fade.js, mobile-nav.js, footer-legal.js (masque le « · » du footer au retour à la ligne), venue-admin.js, events-infinite.js, events-month.js, events-search.js, subscribe-calendar.js, section-nav.js, event-venue-map.js, event-single-media.js, pageflip.js, pf-tooltip.js, pf-toast.js (composant toast partagé), shelf-distinctions.js, shelf-bookmarks.js, pf-session-toast.js (window.pfSessionExpired), avis-delete.js, add-to-cart-toast.js + add-to-cart-flight.js + cart-toast.js (chorégraphie vol du livre vers le panier), numerique-offer.js, numerique-cart-nudge.js, wc-notices-toast.js, wc-block-notices-toast.js, account-auth.js, recherche-auteurs.js, admin-permalink-preview.js (aperçu live du permalien produit/événement, `wp.url.cleanForSlug`)
│
├── redirections/
│   ├── auteurs.csv                  — 74 PrestaShop → WordPress author URL mappings
│   └── redirections_actualites.csv  — PrestaShop → WordPress news URL mappings
│
├── tribe/events/v2/                 — The Events Calendar template overrides (components/header.php, ical-link.php, events-bar/views.php, list/event.php + sous-templates, day/event/description.php)
│
├── tribe-events/
│   └── single-event.php             — TEC override CLASSIQUE (chemin distinct de tribe/events/v2/)
│
└── woocommerce/
    ├── archive-product.php          — Overrides product archive to render [passiflore_catalogue]
    ├── content-single-product.php   — Single book page hero (pageflip, prix, case offre numérique, auteurs) + sections (book-single-tabs.php)
    ├── cart/mini-cart.php           — Mini-panier dropdown header (calqué WC 10.0.0) : <li> = petite .pf-card cliquable
    └── myaccount/
        ├── dashboard.php, form-edit-account.php
        ├── form-login.php           — Connexion/création (calqué WC 9.9.0) : un panneau à la fois
        ├── my-address.php           — Page « Adresses » (calqué WC 9.3.0)
        └── navigation.php
```

---

## Shortcodes

| Shortcode | Class / file | Description |
|-----------|-------------|-------------|
| `[passiflore_etagere]` | `Passiflore_Bookshelf` / `class-bookshelf.php` | 3D bookshelf — voir attributs complets ci-dessous |
| `[passiflore_catalogue]` | `Passiflore_Catalogue` / `class-catalogue.php` | Catalogue filtrable — `search`, `orderby`, `order`, `format`, `public`, `type`, `langues`, `decouvrir`, `display`, `category`, `mode`, `per_shelf`, `show_price` |
| `[passiflore_auteurs]` | `auteurs.php` | Archive auteurs (wraps `[passiflore_recherche_auteurs]`) |
| `[passiflore_recherche_auteurs]` | `Passiflore_Recherche_Auteurs` / `class-recherche-auteurs.php` | Recherche auteurs AJAX |
| `[passiflore_account_btn]` | `header-hooks.php` | Bouton "Mon compte" / "Se connecter" dans le header |

**`[passiflore_etagere]` — tous les attributs :**

| Attribut | Défaut | Description |
|---|---|---|
| `mode` | `shelves` | `shelves` (multi-rangées) ou `scroll` (défilement horizontal) |
| `display` | `covers` | `covers` (couvertures) ou `spines` (dos) |
| `show_price` | `false` | Affiche une étiquette prix sous chaque livre |
| `category` | — | Slug(s) `product_cat`, séparés par virgule |
| `tag` | — | Slug(s) `product_tag` |
| `per_shelf` | `0` | Livres par rangée (0 = auto-fit) |
| `orderby` | `date` | `date`, `titre`, `prix`, `pages`, ou tout `WP_Query orderby` |
| `order` | `DESC` | `ASC` ou `DESC` |
| `ids` | — | IDs produits séparés par virgule |
| `format` | — | `''` (déduplique par `format_groupe`), `tous`, `classique`, ou slug PA |
| `search` | — | Recherche plein texte |
| `decouvrir` | — | `nouveautes`, `distinctions`, `a-paraitre` |
| `disponibilite` | — | Slug SCF (`disponible`, `a-paraitre`, …) |
| `public` | — | Slug SCF (`tout-public`, `adulte`, `jeunesse`) |
| `type` | — | Slug SCF (`roman`, `nouvelles`, …) |
| `reliure` | — | Slug SCF (`broche`, `cousu`) |
| `langues` | — | Slugs SCF séparés par virgule |
| `auteur` | — | Slug(s) ou ID(s) du terme `auteur` — filtre sur les contributions |
| `role` | — | Types de contribution à restreindre avec `auteur` (`auteur`, `traduction`, …) |
| `nb_books_first_displayed` | `12` | Nombre de livres visibles au chargement (le reste est lazy-loaded) |
| `epub-reader` | `false` | `true` — les liseuses portent `data-pf-epub` et ouvrent le lecteur ePub. Le `href` reste le permalien (repli sans JS) |

---

## Bookshelf feature (`Passiflore_Bookshelf`)

A 3D book-on-shelf display system. **Design fonctionnel mais pas définitif** — raffinement visuel prévu. L'historique complet des dérivations/mesures derrière chaque invariant ci-dessous reste dans `git log` si jamais nécessaire.

**Constantes de sizing (`class-bookshelf.php`) :**

| Constante | Valeur | Rôle |
|---|---|---|
| `SCALE` | `1.2` px/mm | Facteur de conversion mm → pixels |
| `DEFAULT_HEIGHT_MM` | `210` | Hauteur par défaut |
| `DEFAULT_WIDTH_MM` | `140` | Largeur par défaut |
| `MM_PER_PAGE` | `0.08` | Épaisseur de dos par page |
| `MIN_SPINE_MM` | `10` | Épaisseur minimum du dos |
| `MAX_SPINE_MM` | `60` | Épaisseur maximum du dos |
| `PASSIFLORE_RED` | `#c62836` | Couleur de fallback pour les dos sans couverture |

**Calcul de l'épaisseur du dos :** 1) photo de dos → largeur en mm ; 2) sinon `nombre_de_pages` → `pages × 0.08` ; 3) sinon `MIN_SPINE_MM` ; clamp `[10, 60]` ; `spine_px = round(spine_mm × SCALE × mode_scale)` (`mode_scale` = 1.5 en spines, 1.0 en covers).

**Variables CSS clés :** `--book-h`, `--book-w`, `--spine-w` (inline par livre), `--plank-color-top/-front/-dark`, `--wall-color`, `--frame-color`, `--pf-obl` (angle de fuite partagé covers/spines, `0.3249`/`0.2309` sous 767px — **même grandeur que le `skewY` du dos, à changer ensemble aux deux breakpoints**), `--pf-backcover` (`4px`, épaisseur des plats), `--pf-plank-inset` (`20px`/`8px` sous 767px — un seul token pour 3 nombres liés : marge planche, retrait clip-path, padding rangée).

> ⚠️ `--shelf-inner` **supprimé** (2026-07-28) — ne pas réintroduire, il était inerte (`min-height` ne peut qu'agrandir une rangée, jamais la resserrer).

**Mobilier responsive** : `--pf-plank-inset` cale le pied du livre sur l'arête arrière de sa planche par construction — les désaccorder décolle le livre. Le hero impose 64px en **minimum** (`padding ≥ 2×inset`), pas égalité. Le padding-top de rangée ne se réduit qu'en mode shelves+covers (le mode scroll a besoin du dégagement pour le grab-shift, le mode spines pour la bascule au survol) — hero et étagère de recommandations exclus explicitement.

**Réduction des livres — un facteur par étagère, calé sur le p95** (`bookshelf.js`, `FIT_PERCENTILE`) : évite qu'un unique très grand format impose sa réduction à tout le catalogue (les ~5% au-dessus sont écrêtés individuellement, `#wrapper` étant `overflow: clip`). Plage utile `[0.95, 1.00]` — en dessous le facteur sature à 1 (réintroduit le défaut). Inerte au-dessus de 768px. ⚠️ Le percentile porte sur les livres **affichés** — filtrer le catalogue peut changer la taille de tous les livres (cohérent en interne, assumé).

**`--pf-chevalet-reserve`** (réserve du chevalet de prix) : doit varier avec `--plank-h` du cas chevalet, sinon l'assise du livre s'effondre sous 767px. L'étiquette « À paraître » se dérive de cette réserve plutôt que d'être un 3e nombre à resynchroniser.

**`--pf-reveal-scale`** (taille du livre saisi en mode dos) : `computeRevealShift()` plafonne l'agrandissement à ce qui tient dans le rayon (`scale = min(1.1, (largeur rayon − 28px)/(cover+spine))`). ⚠️ Vit à **trois endroits** qui doivent rester d'accord : la `transform` de saisie, le terme `(scale−1)×--book-h` du `perspective-origin`, et `--reveal-dx`/`-dy` — désaccordés, l'atterrissage ne retombe plus juste.

**`--pf-spines-scale`** (dos rendus à 1,2× au lieu de 1,5× sous 767px) : s'applique à **tout** l'intérieur du dos (dimensions JS, `--pf-spine-fs`, marges, logo) — `spine_layout()` (PHP) ne reste juste que par similitude si tout est multiplié pareil. ⚠️ Scopé `.pf-bookshelf--spines` uniquement (les dos générés existent aussi en mode couvertures, où ils ne doivent pas rétrécir).

**`.pf-book--releasing`** (retour au repos, 0,3s, distinct de la saisie 0,5s) : ⚠️ **doit figurer dans la règle `z-index: 10`** aux côtés de `:hover`/`:focus-visible`/`--cover-revealed` — sinon le livre retombe à `z-index: 2` dès le 1er pixel du retour et sa couverture (encore large, pivotante) se peint derrière les frères suivants dans le DOM.
- Préchauffage couverture (`.pf-book--arming`, **`visibility` seul** — jamais `opacity`/`will-change`, qui aplatissent un membre `preserve-3d` et créent un filet visible) : absorbe le coût fixe de première rastérisation avant le vol.
- Repli **ININTERRUPTIBLE** : `pointer-events: none` sur `.pf-book--releasing` (hover:hover uniquement) — sinon ramener le curseur en plein retour relance un cycle avec une autre courbe.
- ⚠️ **`perspective` doit valoir `--persp` dès la 1re image du retour** (règle de relâchement à spécificité (0,3,0), après `:hover`, sans délai) — sinon la perspective de bascule (`--pf-tip-persp`) traîne le livre hors écran à gauche (« couverture étirée », bug du 2026-07-29).
- Tactile : origine du clic **datée globalement** (`lastTouchAt`, fenêtre 1s), jamais flagée par élément — iOS recale le `click` vers la cible la plus proche, indépendamment du `touchstart` réel.

**`--pf-cam-x`/`--pf-cam-y`** (caméra du vol repliée dans la `transform`) : `perspective-origin` est la seule propriété du vol que le compositeur ne sait pas jouer (causait un tremblement mesuré). Un cisaillement en z équivalent est prépendu à la transform ; `perspective-origin` reste fixe (`50% 0%`) et n'est plus jamais animée.

**Projection** : covers = projection cavalière 2D ; spines = vrai volume 3D. Le raccord se fait par une **caméra mobile** (`perspective-origin` animée sur `.pf-book`), garantissant que le rapport bandeau/épaisseur du livre grabbed vaut exactement `--pf-obl`, comme en covers. ⚠️ Ce n'est qu'une **construction partagée**, pas une superposition : taille (~1,65×), image servie (`medium_large` vs `pf-shelf-cover`), ombres et fond diffèrent délibérément entre les deux modes — ne pas chercher à les unifier.
- Ne pas réintroduire de « déco » plate 2D pour le dos saisi (supprimée) — ne peut pas suivre la vraie perspective en vol.
- `.pf-book-inside` ne doit **pas** être reculé en z en mode spines (laisserait un liseré blanc le long du dos vu la caméra déportée) — arbitrer par `z-index`.
- L'ouverture de couverture au survol (`.pf-cover-leaf`) a besoin de sa **propre scène 3D locale** (perspective imbriquée) — la caméra du volume (~45°) transformerait sinon sa profondeur en étirement latéral.

**Bascule au survol en mode spines** (étape 1, avant saisie) : le livre bascule vers le lecteur autour de son arête basse (`rotateX(--pf-tip)`, 10°) — aucun agrandissement, le geste seul détache le livre. Perspective **propre** à la bascule (`--pf-tip-persp`, 1200px — physiquement réaliste ; `--persp` à 6000px rendrait le flare imperceptible).
- ⚠️ `perspective` n'est **jamais interpolée** dans ce cycle — commutée à un instant invisible (livre à plat en entrée, redressé en sortie avec délai 0,3s, et **au départ du vol** — interpolée là, elle rendait l'ouverture non-monotone).
- Plats amincis pendant la bascule (`--pf-backcover-tip` 2,5px vs 4px, l'exagération de covers serait trop visible presque de face) ; plat avant ajouté à droite du bandeau (ferme visuellement le dessus avant que la vraie couverture ne charge) ; ombrage/ombre de bascule sur `::after` (voile de saisie est sur `::before`, coexistent).
- ⚠️ Les listes de fonctions de `transform` des étapes bascule et saisie doivent rester **alignées** (mêmes fonctions, même ordre) — sinon interpolation par décomposition de matrice = trajectoire parasite. Même spécificité : l'ordre dans le fichier fait gagner l'étape 2, ne pas la remonter.
- ⚠️ Piège de test : un `mouseenter` synthétique en JS ne fait pas matcher `:hover` en CSS (seul un vrai déplacement de pointeur CDP le fait) — la saisie, elle, est pilotée par une classe donc insensible à ce piège.

**Assombrissement de profondeur** — une seule règle pour les deux modes (dégradé progressif `0 → rgba(0,0,0,.18)`, jamais un voile plat) : bandeau de pages (`to bottom`), dos en covers (`to left`), dos+bandeau saisis en spines (`to left`, mais **deux pseudo-éléments distincts** car l'axe du bandeau change entre bascule et saisie — un `background` ne se cross-fade pas).

**Quatrième de couverture** : plat arrière peint en bordure de `.pf-book-pages` (`--pf-backcover: 4px`, exagération assumée vs le vrai ratio 0,3mm). Même arête physique, deux côtés CSS différents selon le mode (`border-top` en covers 2D, `border-left` en spines 3D). `box-sizing: border-box` obligatoire.

**Dos fictifs (pas d'image de `tranche`)** :
- Couleur = dominante de la couverture (`dominant_color()`, histogramme quantifié 4 bits/canal sur 4096 casiers, casiers quasi-blancs/noirs écartés, fallback `PASSIFLORE_RED`). Extraite pour **toutes** les couvertures (le plat arrière du bandeau de pages en a besoin partout). Cache : postmeta `_pf_cover_color` sur la pièce jointe (mutualisé) ; changer l'algo = incrémenter `COLOR_ALGO_VER` (`maybe_purge_cover_colors()`).
- Encre selon contraste WCAG du blanc sur le fond (`spine_is_light()` → `--light`), jamais de correction de la couleur elle-même.
- Logo : même macaron, variante blanche (`macaron_logo_blanc-96.png`, **pas** un média WP, à régénérer à la main si l'original change) sur dos sombre, site icon sur dos clair.
- Titre : suffixe entre parenthèses retiré (format, jamais du titre réel).
- Composition : auteurs haut / titre milieu / logo bas. Prénom conservé **seulement pour un auteur seul** (2+ → patronymes directement, toujours ≥2× plus court).
- Polices résolues **par livre** (arbre `product_cat`) : Newsreader 700 serif (Littérature) / Inter 700 (Culture Sud-Ouest). ⚠️ Kadence ne charge Newsreader qu'en 700.
- Sizing (`spine_layout()`) contraint par hauteur ET largeur, dégrade par paliers si ça ne rentre pas : `Prénom Nom` (auteur seul) → patronymes → `Nom & al.` → titre seul. Filet ultime `overflow:hidden`+ellipsis. Calculé sans cache (arithmétique pure).

**Étagère filtrée par distinctions** (`decouvrir="distinctions"`, covers uniquement) : bouton rond médaille sur l'emplacement du chant → infobulle. Icône = `pf_distinction_icon()` (source unique, partagée avec la fiche livre). Contenu dans un `<template>` inerte cloné au clic (pas de JSON en attribut). Infobulle = flottant global unique en `<body>` (rognée sinon par l'overflow du rayon en scroll), ouverte au **clic** (jamais survol, elle défile). ⚠️ Le catalogue enqueue le contrôleur **sans condition** (grille rechargée en AJAX, où `wp_enqueue_script()` n'aboutit à rien) ; l'étagère ne l'enqueue que si elle rend effectivement des boutons.

**Chargement des couvertures — `decode()` obligatoire** (`bookshelf.js`, `startCoverLoad`) : `load` ne signifie que « octets reçus », le décodage bitmap arriverait sinon au 1er dévoilement (saccade). `img.decode()` force le travail en amont, absorbé par `REVEAL_DELAY_MS`.

**Mode `scroll` — ombres de bord** (composant global `.pf-scroll-fade`) : posé par `render_scroll()` sur **toute** étagère scroll (acquis pour toute étagère future). Le wrap s'intercale entre le cadre et le scroller (masquer directement `.pf-bookshelf` dissoudrait sa bordure/ombre). Rampe réduite avec la fenêtre (`--pf-fade-size`: 60/40/28px).

**Mode `shelves` — voile jusqu'au re-packing** (`render_shelves()`/`relayoutAll()`) : le PHP répartit pour 1100px fixe, `repackShelves()` re-répartit à `DOMContentLoaded` — entre les deux, voilé via `visibility: hidden` (**pas `display:none`**, le re-packing mesure des `offsetWidth`), levé par `relayoutAll()` (classe `.is-packed`). Gate `.pf-shelves-js` posée par un script inline en tête de conteneur (avant 1er paint), avec filet `setTimeout` 1,5s si `bookshelf.js` ne charge jamais.
- ⚠️ **Lectures et écritures DOM strictement séparées** dans `repackShelves()`/`fitBookshelf()` — les intercaler force un recalcul de layout synchrone (coûtait ~950ms sur 146 livres, ramené à ~35ms). Ne pas ré-intercaler de mesure dans ces boucles.
- La répartition PHP est quasi gratuite (~0,01ms/146 livres) et **ne doit pas être retirée** : partir d'une rangée unique serait le pire cas pour le re-packing JS.

**Variable CSS globale partagée `--pf-sticky-offset`** : bord bas du header collant, en px. Calculée par `assets/js/recherche-globale.js` (chargé site-wide) au `DOMContentLoaded`/`resize`/`orientationchange`/`load` — **pas** au scroll (`#masthead` étant vraiment sticky, une seule mesure suffit à tout moment ; exception iOS, `getBoundingClientRect()` suit le repli de la barre Safari). Défaut CSS `:root: 80px` ; primers inline sur `front-page.php`/`section-nav.php` posent la vraie valeur avant 1er paint.

**Tailles d'image servies (perf)** : étagère générique sert `pf-shelf-cover` (400×600) / `pf-shelf-spine` (300×760), non-crop ; mode héros (fiche livre) sert `medium_large`/`large`. ⚠️ **Gotcha** : `wp_get_attachment_image_url()` sur une sous-taille non générée retombe silencieusement sur l'original plein format — toute image déjà en médiathèque doit être régénérée après ajout d'une nouvelle taille (`wp_update_image_subsizes()` ou `wp media regenerate`).

---

## PDF Catalog feature

Repeater SCF `catalogues` (group `group_6a4ba1e58c0bd`, `lien` = champ **text**, pas `url`, pour permettre des chemins racine-relatifs) attaché à la page Catalogue (ID 8). Chaque ligne : `libelle`, `lien` optionnel, `fichier` optionnel (attachment ID) :
- **`fichier` + `lien`** → `lien` devient le chemin virtuel public qui sert ce fichier exact (règle de réécriture dynamique).
- **`lien` seul** → href verbatim (URL externe/absolue arbitraire).
- **`fichier` seul** → href = URL native médiathèque.

- **Admin** : Boutique → Catalogues PDF (`inc/catalogues.php`), formulaire natif SCF via `acf_form()`/`acf_form_head()`.
- **Réécriture dynamique** : `passiflore_get_pdf_catalogue_rows()` calcule un chemin normalisé, `add_rewrite_rule()` (hook `init`, position `top`) le mappe vers `index.php?pf_catalogue_pdf=<attachment_id>` ; `template_redirect` sert le fichier via `readfile()`. ⚠️ Le cœur WP ne relit **jamais en direct** les règles ajoutées par `add_rewrite_rule()` (cache en base tant qu'il existe) → `acf/save_post` appelle `flush_rewrite_rules()` à chaque enregistrement du repeater.
- **Front** : `passiflore_get_pdf_catalogues()` retourne `[{libelle, url}, …]` ; `class-catalogue.php` (`render_top_bar()`) rend le dropdown, masqué si vide.

---

### Tarif d'expédition réduit au-delà d'un seuil (`inc/shipping.php`)

Réduit le forfait d'expédition d'une méthode `flat_rate` une fois un montant atteint. **Réglé par instance de méthode**, dans la popup « Configurer forfait » d'une zone.

- Deux champs ajoutés (`woocommerce_shipping_instance_form_fields_flat_rate`) : `pf_seuil` (seuil € TTC), `pf_cout_reduit` (forfait réduit, vide = gratuit).
- Application : `woocommerce_package_rates` (prio 100) — si `pf_seuil > 0` et sous-total TTC affiché ≥ seuil → `set_cost(pf_cout_reduit)`.

**Recalcul de la livraison au changement de pays — checkout en blocs** (`assets/js/checkout-shipping-country.js`) : contourne un bug WooCommerce Blocks (changer le `<select>` pays ne déclenche pas le push Store API qui recalcule les tarifs, contrairement aux champs texte). Écoute le `change`, debounce 300ms, rappelle `wp.data.dispatch('wc/store/cart').updateCustomerData(...)`.

---

## Design

- **Brand color:** Rouge Passiflore `#c62836` (palette1 Kadence)
- **Color palette and typography:** defined in Kadence customizer (`/wp-admin/customize.php`)
- **Direction:** fresh design (not a copy of the PrestaShop site), but keeps the Passiflore red identity
- **Approach:** custom PHP templates + CSS; Gutenberg blocks for content where appropriate

---

## CSS — Design System (règles impératives)

> Référence : `docs/design-system.md`.

1. **Jamais de nouvelle valeur en dur.** Couleur → token `--pf-*` ; espacement structurel → `--pf-space-*` ; radius → `--pf-radius`/`-md`/`-lg`/`-card` ; ombre → `--pf-shadow`/`-hover`/`-stuck`/`-float` ; focus → `--pf-focus-ring(-accent)` ; statuts → `--pf-success/info/warning/danger(-bg)`. Alpha dérivé → `color-mix(in srgb, var(--pf-…) N%, transparent)` (jamais un rgba figé). Exceptions tolérées : `em` relatifs au texte, micro-géométrie documentée par une var locale commentée.
2. **Avant d'écrire du CSS : lire les composants de `style.css`.** Existants : `.pf-btn(--primary/--outline/--neutral/--sm/--block)`, `.pf-card` (+`--static/--compact`, `-title`, `-content`, `-text`), `.pf-panel(--alt/--danger)`, `.pf-badge--*`, `.pf-quote(--accent)`, `.pf-notice(--error/--success)`, `.pf-empty`, `.pf-search(--sm)`, `.pf-switch(--solid)`, `.pf-splitbtn(--solid)`, `.pf-dropdown`, `.pf-sticky-bar`/`.pf-sub-header`, `.pf-roundbtn`, `.pf-spinner`/`@keyframes pf-spin`, filet d'attente (`.pf-gsearch-results.is-loading::before`/`.pf-sub-header.is-loading::after`), `.pf-hscroll`, `.pf-scroll-fade`, `.pf-section-titre`, `.pf-titre-1/2`, `.pf-label`, `.pf-avis-reponse`, bloc newsletter. **Un motif présent sur ≥ 2 pages appartient à `style.css`**, préfixe `.pf-` obligatoire (`.bs-*` de book-single = legacy gelé).
3. **Un CSS de page ne contient que** : layout propre à la page, comportements bespoke, shims de spécificité anti-plugin. Toute règle « apparence d'un composant » remonte dans `style.css`.
4. **Boutons** : markup custom → `.pf-btn .pf-btn--…` ; boutons WooCommerce/plugins → garder le sélecteur existant, pointer sur les tokens.
5. **Kadence/plugins** : couleur des boutons natifs via `--global-palette-btn-*`. `!important` **interdit entre fichiers du thème enfant** ; autorisé uniquement contre TEC/Woo/Kadence/Customizer. ⚠️ Le reset `.tribe-common *` (0,1,0) écrase les composants globaux sur les pages TEC → shim (0,2,0) dans `events.css`.
6. **Breakpoints** : 480 / 768 / 1024 (`max-width: 767px` ↔ `min-width: 768px`). Exceptions documentées : 600 (photo auteur), 781 (TEC medium), 540 (newsletter).
7. **Hauteur de contrôle de barre** : `--pf-control-h` (défaut 38px, surchargée par barre) ; géométrie du champ de recherche via `--search-*` de `.pf-search`.
8. **Après tout ajout/modification de token ou composant** : mettre à jour `docs/design-system.md`, vérification visuelle avant/après (headless).

---

## Site structure (pages created)

Pages WordPress publiées :
- Accueil (`accueil`), Catalogue (`catalogue`), Auteurs (`auteurs` — `[passiflore_auteurs]`), Présentation (`presentation`), Contact (`contact`), Mon compte (`mon-compte`), Panier (`panier`), Validation de la commande (`commander`), Conditions générales de vente (`conditions-generales-de-vente` — rattachée par **slug** dans `inc/checkout-consent.php`, renommer le slug débranche la case CGV), Politique de confidentialité (`politique-de-confidentialite` — liée par slug dans `inc/newsletter.php` et `inc/checkout-consent.php`, renommer le slug casse ces deux liens)

Page en brouillon : Politique en matière de remboursements et de retours.

"Actualités" : remplacée par le post type `tribe_events` — pas de page WP dédiée.

---

## Migration status

**Bascule en production effectuée le 2026-08-11.** `www.editions-passiflore.com` est la production réelle — même installation que l'ex-`v2.editions-passiflore.com` (bascule de domaine, pas de migration de données séparée). Déroulé, gotchas rencontrés (version PHP par domaine côté Ionos, case NS à ne pas cocher, `.htaccess` WPFC à re-régénérer après purge) et points encore à traiter (webhooks Stripe, CSV redirections, comptes clients) : mémoire `prod_cutover_checklist`.
- Les scripts d'import ci-dessous ont servi à peupler l'installation ; ils ne seront **pas** rejoués — il n'y a qu'un seul jeu de données, désormais en production.

- **Books:** imported once; updated data received from publishing house — re-import pending
- **Authors:** previously imported then deleted; updated data received — re-import pending
- **Import scripts:** `import-auteurs.php`, `import-livres.php`, `import-livres-1-classiques.php`, `import-livres-2-grands-caracteres.php`, `import-livres-3-numeriques.php`, `import-livres-4-actualites.php`, `import-livres-common.php`, `import-actualites.php` (all in `app/public/`)
- **URL redirections:** CSVs in `kadence-child/redirections/` — pas encore chargés dans le plugin Redirection

---

## Installed plugins (relevant)

| Plugin | Role |
|---|---|
| Secure Custom Fields (SCF) | Custom fields — **the one in use** |
| Advanced Custom Fields (ACF) | Installed but not used — ignore |
| Custom Post Type UI | Registers `auteur` taxonomy and `format_groupe` taxonomy |
| WooCommerce | E-commerce / book catalog |
| WooCommerce Payments | Payments |
| Rank Math SEO | SEO |
| Redirection | URL redirections from PrestaShop |
| WP All Import | CSV/XML import tool |
| UpdraftPlus | Backups (destination cloud non encore configurée) |
| The Events Calendar | Events management |
| Contact Form 7 | Formulaire de contact |
| WP Add Mime Types | Ajout de types MIME (ex. EPUB) |
| Child Theme Configurator | Génération initiale de `functions.php` — ne plus utiliser activement |

---

## Gotcha — guillemets typographiques dans les fichiers PHP

**Problème récurrent :** l'outil Edit introduit parfois des guillemets typographiques Unicode (`'` U+2018, `'` U+2019, `„` U+201E) à la place des apostrophes ASCII (`'` 0x27) dans le code PHP. PHP ne reconnaît pas ces caractères comme délimiteurs de chaîne → erreur fatale.

**Symptôme :** `Parse error: syntax error, unexpected identifier 'xxx'` sur une ligne contenant une chaîne PHP `'...'`.

**Vérification :** après tout Edit sur un fichier PHP, lancer :
```
"/Users/loicrobin/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php" -l "chemin/vers/fichier.php"
```

**Correction si détectée :** Python pour remplacer les bytes exacts :
```python
import re
path = "chemin/vers/fichier.php"
data = open(path, 'rb').read()
data = data.replace(b'\xe2\x80\x98', b"'")           # U+2018 → ASCII '
data = re.sub(rb'\xe2\x80\x99(?=[;,)\].\'\\s])', b"'", data)  # U+2019 closing → ASCII '
open(path, 'wb').write(data)
```
Vérifier ensuite que les U+2019 légitimes (apostrophes françaises dans du contenu, ex. `l'événement`) ne sont pas touchées.

**Règle :** après chaque Edit sur un fichier PHP, faire un `php -l` systématique.

---

## Gotcha — ne jamais tuer Chrome globalement pendant les tests visuels

Les vérifications visuelles lancent des instances **Chrome headless** (CDP / `--screenshot`). **Ne JAMAIS** faire `pkill`/`killall` sur « Google Chrome » (ni aucun motif large) : le navigateur GUI de l'utilisateur tourne sous le même nom et serait tué. Chaque instance de test doit utiliser un `--user-data-dir` **et** un `--remote-debugging-port` uniques, et n'être arrêtée que par son propre lanceur (kill ciblé du PID). Pas de nettoyage « au nom » ; un `--user-data-dir` unique par run suffit.

---

## Vérification visuelle — seulement si nécessaire

Ne pas lancer de vérification visuelle headless par défaut à chaque changement CSS/PHP front — coûteux et rarement nécessaire pour un ajustement mineur ou évident (couleur de token, texte, petit espacement). Réserver la vérification aux changements à risque réel de régression (nouveau composant, changement de layout/breakpoint, interaction JS/animation). Sinon, indiquer la page/URL locale où voir le changement pour que l'utilisateur vérifie lui-même.

---

## Sécurité AJAX — politique de nonce

**Endpoints publics en lecture seule = pas de nonce** (choix acté). Un nonce n'a de valeur que pour la protection CSRF d'une action qui **modifie un état** ; sur un endpoint `nopriv` qui ne fait que lire, il n'apporte rien et provoque un 403 sur un onglet resté ouvert >12-24h. N'appellent **pas** `check_ajax_referer` : `pf_global_search`, `pf_recherche_auteurs`, `pf_catalogue(_filter)`, `pf_events_feed`, `pf_events_search`, `pf_events_map_search`. **Ne pas « réparer » en re-ajoutant un nonce.**

**Endpoints à état = nonce conservé + dégradation gracieuse.** Ceux qui écrivent (panier `pf_numerique_*`, newsletter, avis `pf_avis_*`, liste de lecture `pf_reading_list_toggle`, suppressions, sauvegardes admin) **gardent** leur nonce. Leur JS détecte `response.status === 403` **avant** `.json()` et appelle `window.pfSessionExpired({mode})` (`assets/js/pf-session-toast.js`) : `'reload'` pour les actions pures (panier), `'confirm'` là où un auto-reload perdrait une saisie/position (avis, newsletter, liste de lecture, signets). ⚠️ La suppression d'un avis prend `'confirm'` malgré une action pure (la section porte aussi le formulaire de dépôt). Le sondage de fond du panier avale le 403 en silence (pas d'action utilisateur → pas de toast).

---

## PHP conventions

Use **OOP classes** for complex, stateful features (e.g. `Passiflore_Bookshelf`, `Passiflore_Catalogue`). Use **procedural hooks** for lightweight, single-purpose functionality (e.g. `inc/auteurs.php`, `inc/admin.php`). Follow the existing patterns in the child theme. All custom code belongs in `kadence-child/`.

---

## Implementation plan

### Phase 1 — Core book experience
1. ✅ **Pageflip viewer** — cover image + PDF extract displayed as a flipbook
2. ✅ **Full single book page** — hero + sections (résumé, caractéristiques, auteurs, presse, vidéos, podcasts, avis, événements, livres associés). Reste à explorer : mini `[passiflore_etagere]` dans le hero.
3. ⬜ **Bookshelf display refinement** — visual design not final

### Phase 2 — Authors
4. ✅ **Author single page** — photo, bio, book list
5. ✅ **Authors archive page** — grid with AJAX search

### Phase 3 — Catalog & navigation
6. ✅ **Catalogue pages** — filterable catalogue with AJAX, auto-detects current WC category
7. ✅ **Shop/catalog landing page** — handled by `archive-product.php` override

### Phase 4 — Import & content
8. ⬜ **Re-import authors** — data received, ready to import
9. ⬜ **Re-import books** — data received; align SCF fields with `_book_pages`/`_book_width_mm`/`_book_height_mm` post meta
10. ⬜ **URL redirections** — load CSVs from `kadence-child/redirections/` into the Redirection plugin

### Phase 5 — Remaining pages
11. ⬜ **Home page** — 1–2 test bookshelves in place; full design and editorial build pending
12. ✅ **Événements** (ex-Actualités) — set up; associated books via `[passiflore_etagere]`
13. ⬜ **Qui sommes-nous ?** and **Nous contacter** — static content pages
14. ⬜ **WooCommerce pages** — Cart, Checkout, My Account — much of the styling/consent/UX work is done (see sections above); confirm remaining gaps

### Phase 6 — Launch preparation
15. ⬜ **SEO** — configure Rank Math: meta descriptions, sitemaps, schema for books/authors (partial: events + authors noindex/sitemap logic already in place)
16. ⬜ **Performance** — image optimization, caching strategy (baseline established, see mémoire `performance_optimization`)
17. ✅ **Production deployment** — live on Ionos at `www.editions-passiflore.com` (2026-08-11)
18. ⬜ **PDF catalog** — link the catalog page PDF once ready

## Karpathy Skills - Build Commands

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them - don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

---

**These guidelines are working if:** fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.
