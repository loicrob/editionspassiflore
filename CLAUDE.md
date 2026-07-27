# CLAUDE.md — Éditions Passiflore

## Project overview

WordPress + WooCommerce migration of [www.editions-passiflore.com](https://www.editions-passiflore.com) (currently in PrestaShop) for a French independent publishing house. The site is a book catalog with e-commerce.

- **Theme:** Kadence (parent) + `kadence-child` (all custom code lives here)
- **Custom fields:** Secure Custom Fields (SCF) — **not ACF**, even though the `advanced-custom-fields` plugin is also installed (ignore it)
- **Custom post types / taxonomies:** registered via CPT UI plugin
- **Local environment:** Local by Flywheel (PHP 8.2, Nginx, MySQL)
- **Production hosting:** TBD — possibly Ionos or a better alternative
- **Kadence Blocks plugin:** not installed; decision pending (not needed as long as layouts are built in PHP templates)

---

## Development environment

| Ressource | Valeur |
|-----------|--------|
| Site local | `https://editions-passiflore.local` |
| Admin WP | `https://editions-passiflore.local/wp-admin/` |
| Kadence customizer | `https://editions-passiflore.local/wp-admin/customize.php` |
| WP-CLI | Depuis Local → *Open Site Shell* (WP-CLI dans le PATH) |
| BDD | `local` / user `root` / pass `root` / prefix `wp_` — socket MySQL (voir mémoire `db-access`) |
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
| palette10 | `#e0d8cc` | Bordures / filets (`--pf-border`) — valeur actée le 2026-07-02 (audit CSS) |

> Couleurs sémantiques `--pf-*` (définies dans `style.css`, pointant sur la palette) : `--pf-accent` (p1), `--pf-accent-dark` (p2), `--pf-text` (p3), `--pf-heading` (p4), `--pf-muted` (p6), `--pf-cream-dark` (p7), `--pf-cream` (p8), `--pf-surface-alt` (p9), `--pf-border` (p10), `--pf-surface` (#fff), plus `--pf-border-light` (`#e0d8cc`, hors palette), `--pf-sand` (`#D9C8B0`, sable historique — badge « attente »…) et `--pf-text-dim` (`#8b7a72`). Préférer ces tokens aux hex en dur. L'ancienne couleur or/bois de l'étagère (`#a89474`) vit désormais dans les `--plank-color-*` de `bookshelf.css`, indépendamment de la palette.

---

## Repository

Git repository at the project root. Only custom code is versioned (WordPress core, third-party plugins/themes, and uploads are excluded via `.gitignore`).

- **`.gitignore`** — excludes WP core, third-party plugins, uploads, DB dumps (`*.sql`), Local by Flywheel env
- **`README.md`** — setup instructions
- **`setup.sh`** — installs all required plugins and activates the child theme via WP-CLI (run from Local's "Open Site Shell")

---

## WordPress data model

> **Référence SCF complète :** `docs/scf-export-2026-07-14.json` (export SCF du 2026-07-14, structuré par onglet ; inclut les groupes "Accueil" et "Menu déroulant Catalogues PDF"). Consulter ce fichier pour les clés de champs, les logiques conditionnelles et les types exacts. Ce qui suit est un résumé des points essentiels pour le développement.

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
| ~~Livres associés~~ | **Onglet supprimé de SCF** → désormais géré globalement (voir « Groupes de livres » plus bas) |
| Liens et fichiers | `lien_place_des_libraires`, `articles_de_presse`, `videos`, `podcasts` |
| Avis | `avis_des_lecteurs`, `avis_des_libraires` (repeater : titre, auteur, date_de_publication, avis) |

**Points d'attention :**
- `date_de_parution` : `return_format = Ymd` → parser avec `DateTime::createFromFormat('Ymd', $val)`
- `contributions` → `fiche-auteur` est un `multi_select` taxonomy (retourne id)
- Champs supprimés (ne plus utiliser) : `mots-cles`, `meta_description`, `resume`, `collection`, `thematique`, `format`, `isbn`, `dimensions`, `prix_ht`, `prix_ttc`, `livre_numerique`, `version_grands_caracteres`, `version_litterature_generale`
- **Livres associés supprimés de SCF** (gérés désormais en global, voir « Groupes de livres ») : `autres_ouvrages_de_la_suite` + `ordre_dans_la_suite` (supprimés sans remplacement), `autres_ouvrages_de_la_serie` + `ordre_dans_la_serie`, `vous_aimerez_aussi`, `traductions`. ⚠️ L'onglet « Livres associés » reste à supprimer manuellement dans l'admin SCF (Custom Fields).

**Avis lecteurs — deux sources qui coexistent** (rendu par `inc/book-single-tabs.php`, section « Avis des lecteurs ») :
- **Avis curés par l'éditeur** : repeaters SCF `avis_des_lecteurs` / `avis_des_libraires` (sous-champs `titre`, `auteur`, `date_de_publication`, `avis`), saisis depuis le back-office → sous-bloc « Sélection de l'éditeur ».
- **Avis déposés par les visiteurs** : avis WooCommerce natifs (commentaires WP, table `comments`), **sans note par étoiles**, ouverts à tous (nom requis, email retiré), modérés avant publication (`comment_moderation = 1`) → sous-bloc « Avis des lecteurs du site » + formulaire. Nécessite `comment_status = open` sur le produit (réglé en masse sur les livres existants).
- « Avis des libraires » reste une section SCF distincte.
- Anti-spam à la soumission : honeypot + piège temporel (timestamp signé HMAC) via `passiflore_avis_spam_check` sur `preprocess_comment`, rejet *avant* enregistrement, modérateurs exemptés. Pas de plugin anti-spam installé (Antispam Bee envisageable si besoin).
- **Réponses de l'éditeur** : commentaires-réponses (`comment_parent`) saisis depuis wp-admin. Affichées publiquement sous l'avis sur la fiche livre **uniquement si** le commentaire-réponse porte le meta `_pf_reply_public` (case « Réponse publique ? » sur l'écran d'édition du commentaire, gérée par `Passiflore_Mes_Avis`). Toujours visibles par le client dans son compte. Rendu fiche via `passiflore_avis_public_reply()`.
- **Suivi dans le compte client** (`inc/class-mes-avis.php`, `Passiflore_Mes_Avis`) : endpoint `/mon-compte/avis-laisses` (libellé menu « Avis laissés ») listant les avis du client connecté avec statut (publié / en attente / non retenu), édition (repasse en modération) et suppression (corbeille + meta `_pf_user_deleted` pour distinguer du refus éditeur) via AJAX (`pf_avis_edit`, `pf_avis_delete`). **Contrainte :** un avis n'est rattaché à un compte que par `user_id` → seuls les avis déposés en étant **connecté** sont traçables (email retiré du formulaire, donc avis d'invités non rattachables).
- **Notifications email au client** (fonctions dans `inc/book-single-tabs.php`) : à la validation de son avis (`transition_comment_status`) et lorsque l'éditeur y répond (`wp_insert_comment`, anti-doublon via meta `_pf_reply_notified`).

**Custom WooCommerce product meta** (post meta, managed by `Passiflore_Bookshelf`):
- `_book_pages` — spine thickness calculation (sync with `nombre_de_pages`)
- `_book_width_mm`, `_book_height_mm`

**`format_groupe` taxonomy** (CPT UI, managed by `inc/modifier-produit.php`):
- Constrained to a single term per product
- Each term = a book title that exists in multiple formats (classique, grands-caractères, numérique…). All format editions of the same book share the same term.

---

### Livres associés → outil global « Groupes de livres » (`inc/book-groups-admin.php`)

Remplace les anciens champs relationnels SCF par-fiche (onglet « Livres associés », supprimé). Géré depuis **Produits → Groupes de livres** (sous-menu, 3 onglets), sur le modèle de la méta-box événements : recherche AJAX, ajout par auteur, drag-reorder, déduplication par `format_groupe`. Picker réutilisable partagé : `assets/js/book-picker.js` (`window.pfBookPicker`), instancié par `assets/js/book-groups-admin.js` et `assets/js/event-admin.js`.

| Relation | Stockage | Sens |
|---|---|---|
| **Série** | Taxonomie `pf_serie` (1 terme/livre) ; ordre = term meta `_pf_serie_order` (tableau d'IDs représentants) | Symétrique |
| **Traductions** | Taxonomie `pf_traduction` (1 terme/livre) ; ordre = term meta `_pf_traduction_order` | Symétrique |
| **Vous aimerez aussi** | Post meta `_pf_vous_aimerez` (tableau ordonné d'IDs cibles) sur le représentant source | Orienté (pas de réciprocité) |

**Conventions clés :**
- **Granularité œuvre** : on stocke des **représentants** de `format_groupe` (édition classique, via `pf_bg_representative()` = `Passiflore_Bookshelf::get_group_representative()`). Pour les taxonomies, le terme est posé sur **toutes les éditions** de chaque œuvre membre (lookup inverse direct depuis n'importe quelle édition).
- **Composition = taxonomie** (source de vérité) ; **ordre = term meta** (simple indice de tri, entrées périmées ignorées). Lecture front via `pf_bg_group_member_reps()`.
- **Une seule série par livre** : appliqué par `wp_set_object_terms(..., false)` (remplacement) à la sauvegarde.
- **Ordre = drag-reorder uniquement** : pas de numéro de tome, **pas de badge « Tome X »** (la plomberie `show_ordre`/`resolve_ordre` du bookshelf a été retirée).
- Rendu fiche livre : `passiflore_get_livres_lies_sections()` (`inc/book-single-tabs.php`) lit ces sources puis ré-aiguille vers le format consulté via `passiflore_ids_in_format()`, et affiche chaque section avec `[passiflore_etagere ids="..."]`.

---

### Offre « version numérique » à l'achat d'un livre papier (`inc/numerique-offer.php`)

Incite à acheter la version **numérique** d'un ouvrage quand le client prend sa version **papier** (format *classique* ou *grands caractères*), à tarif réduit. Tout le back (option, logique, admin, hooks panier, endpoints AJAX, rendu case fiche) vit dans `inc/numerique-offer.php` ; deux JS front (`assets/js/numerique-offer.js`, `numerique-cart-nudge.js`).

**Réglage** — écran admin **Boutique (WooCommerce) → « Offre version numérique »** (`add_submenu_page` sous le parent `woocommerce`, capability `manage_woocommerce`, sauvegarde nonce + PRG sur `load-{$hook}`, calquée sur `book-groups-admin.php`). Positionné **entre « Clients » et « Codes promo »** via `pf_numerique_reorder_submenu` (hook `admin_menu` prio 100, réinsère juste avant `coupons-moved`) — l'ordre d'un sous-menu WooCommerce dépend de l'ordre d'insertion, peu fiable, d'où le réordonnancement explicite plutôt que le paramètre `$position`. Option globale **`pf_numerique_offer`** (non-autoload) : **deux entrées** (`classique`, `grands-caracteres`), chacune `{ mode, value }` avec `mode ∈ {disabled, percent, fixed, free}`. **Dormant par défaut** : option absente → mode `disabled` → aucune UI, aucun impact panier.

**Modèle** — le format source est lu sur `pa_format_particulier` (classique = **aucun** terme ; sinon slug `grands-caracteres`) ; le numérique compagnon = membre du même `format_groupe` avec `pa_format_particulier = numerique`. Helpers (point d'entrée unique **`pf_numerique_offer_for( $physical_id )`** → `{numerique_id, source, mode, value, regular, price}` ou `null`) : `pf_numerique_source_format()`, `pf_numerique_companion_id()`, `pf_numerique_price()` (parse la virgule décimale FR, clamp % à 100). `value` = % de réduction (mode `percent`) ou prix € (mode `fixed`) ; base = `get_regular_price()` du numérique.

**Fiche livre** — `pf_numerique_render_offer_checkbox( $id )` echoée dans `woocommerce/content-single-product.php` juste après le prix (`.bs-hero__info`). La case pilote (via `numerique-offer.js`) l'attribut **`data-pf_add_numerique`** sur le bouton `.bs-hero__cart` : le JS cœur WooCommerce (`add-to-cart.js`) **recopie tous les `data-*` du bouton dans la requête AJAX d'ajout** → aucun endpoint custom. Côté serveur, `woocommerce_add_to_cart` (`pf_numerique_maybe_add_companion`) lit `$_REQUEST['pf_add_numerique']` et ajoute le numérique avec la cart-item meta **`pf_numerique_companion`** = ID du papier (garde anti-récursion sur cette meta). Amélioration progressive : sans JS, le papier s'ajoute normalement, l'offre n'est simplement pas appliquée. Une **infobulle** (composant partagé `.pf-numerique-tip`, styles dans `style.css`, comportement dans `assets/js/pf-tooltip.js`) suit **le prix barré** (prix normal du numérique) : texte via `pf_numerique_offer_sentence()` (ex. « Pour l'achat d'un format classique, la version numérique est à -70 %. » — le format cité est la source de l'offre : classique / grands caractères). Sur la fiche, `numerique-offer.js` appelle `window.pfTooltip.wire( tip, { preventClick: true } )` — `preventClick` car l'icône vit dans le `<label>` (sinon le clic cocherait la case). Survol/focus gérés en CSS ; `pf-tooltip.js` ajoute l'ouverture au tap/clavier, la fermeture au clic extérieur/Échap, et **recale la bulle horizontalement** (`translateX`) pour qu'elle reste dans le `.site-container` le plus proche (bulle ancrée à droite, elle passerait sinon sous le bord gauche). **La même infobulle est réutilisée dans l'encart panier** (cf. plus bas).

**Prix de la ligne numérique** — `woocommerce_before_calculate_totals` (`pf_numerique_apply_prices`) : tant que le papier référencé est au panier, `set_price( offer.price )` sur la ligne compagnon ; s'il est retiré → on ne touche à rien = **prix plein** (« repasse au prix plein »). Idempotent (recalcul depuis le prix régulier, jamais depuis un prix déjà modifié). Fonctionne en **panier/checkout blocs (Store API)**, qui respecte le prix de ligne serveur.

**Encart de rappel — panier en blocs** — pas de hook PHP de rendu : `numerique-cart-nudge.js` (chargé sur `is_cart()`, dép. `wp-data`) interroge l'endpoint **`pf_numerique_cart_offers`** (livres papier au panier dont le numérique n'est pas encore ajouté) au chargement + à chaque variation du store `wc/store/cart`, injecte un encart `.pf-numerique-nudge` (`.pf-panel`) au-dessus du bloc panier ; « Ajouter » → **`pf_numerique_add_companion`** → `window.location.reload()`. Nonce `pf_numerique_cart`. Chaque ligne « Version numérique de *Titre* : *prix* » porte **la même infobulle** que la fiche (markup `.pf-numerique-tip` construit en JS, phrase `tip` fournie par l'endpoint `pf_numerique_cart_offers`, câblée via `window.pfTooltip.wire()` après insertion dans le DOM).

**Mention « Offre »** — `woocommerce_get_item_data` (`pf_numerique_cart_item_data`) ajoute une ligne « Offre : version numérique liée à l'achat du livre papier » sous la ligne numérique (panier + checkout, classique **et** Store API — le Store API expose `item_data` via ce filtre). Masquée si le papier a été retiré.

**Mini-panier Kadence (dropdown header)** — override `woocommerce/cart/mini-cart.php` (calqué sur le cœur WooCommerce 10.0.0, seul le `<li>` par article change : petite carte cliquable, voir plus bas). Là, on **ne montre pas** la « variation » (ligne Offre) : à la place, la ligne de prix affiche le **prix régulier barré** (`.pf-mini-old-amount`, style dans `style.css`) accolé au prix promo. Mécanisme : un drapeau global **`pf_in_mini_cart`** posé entre `woocommerce_before_mini_cart` / `woocommerce_after_mini_cart` cible ce seul contexte → `pf_numerique_cart_item_data` s'abstient, et `pf_numerique_mini_cart_price` (sur `woocommerce_cart_item_price`) **reconstruit** le prix depuis l'offre (`prix promo` + `<del>` régulier). ⚠️ On reconstruit plutôt que d'accoler au HTML reçu : selon le moment du rendu du mini-panier (ex. `?wc-ajax=get_refreshed_fragments`), l'objet produit ne porte pas encore le prix posé par `before_calculate_totals` → sans reconstruction, la ligne afficherait le prix plein. L'override préserve ces actions `before`/`after_mini_cart` à l'identique (seul point d'ancrage du mécanisme), donc rien à changer côté offre numérique.

**Numérique = vendu à l'unité** — filtre `woocommerce_is_sold_individually` (`pf_numerique_sold_individually`) : tout produit `numerique` est `sold_individually` (indépendant de l'offre — remisé ou non). Effet : quantité verrouillée à 1 et **sélecteur de quantité retiré du panier/checkout en blocs** (le Store API expose alors `quantity_limits.editable = false`, `max = 1` → le composant `.wc-block-components-quantity-selector` n'est pas rendu). Acheter plusieurs fois le même fichier n'a pas de sens.

**Articles du mini-panier en petites cartes cliquables** — chaque `<li class="woocommerce-mini-cart-item">` est une `.pf-card` (+ `.pf-card--static` si le produit n'a pas de permalien) : lien étiré `.pf-card-link` vers la fiche livre, titre en `.pf-card-title` (rougit au survol de la carte entière via la règle globale déjà utilisée par les cartes événement). `display:block` (override du `display:flex` par défaut de `.pf-card`, dans `style.css`) conserve la mise en page interne héritée de WooCommerce/Kadence (image flottante, `.quantity` en `padding-left`). Bordure fine ajoutée (le dropdown a déjà un fond blanc) ; le bouton **×** de suppression reste cliquable au-dessus du lien étiré via un `z-index` dédié. `.pf-card-link` (positionnement absolu) et `position: relative` sur `.pf-card` sont désormais des règles **partagées** dans `style.css` (auparavant dupliquées dans `events.css` pour les cartes événement, seul autre usage du lien étiré).

---

### Connexion / création de compte (`inc/account-auth.php` + override `woocommerce/myaccount/form-login.php`)

WooCommerce n'a **pas** de page de connexion distincte : « Mon compte » est une page unique dont le contenu dépend de la session (déconnecté → `form-login.php`). Le cœur y affiche connexion et inscription **côte à côte**, ce qui fait hésiter le visiteur (deux boîtes aux champs quasi identiques, empilées sur mobile) et contredit le bouton du header, qui promet « Connexion ». Remplacé par **un seul panneau à la fois**, connexion par défaut, création de compte à un clic. Cohérent avec le fait que la **commande invité est activée** : créer un compte n'est jamais un passage obligé pour acheter, la page sert d'abord aux clients qui reviennent.

**L'état vit dans l'URL** (`/connexion` vs `/creer-un-compte`), lu au rendu par `pf_auth_current_state()` pour poser `.is-register` sur `#customer_login`. Ce n'est pas cosmétique : les formulaires postent vers l'URL courante et WooCommerce, en cas d'erreur (email déjà pris, mot de passe refusé), se contente d'empiler une notice et de **re-rendre cette même URL** — si l'état n'y figurait pas, le visiteur retomberait sur le formulaire de connexion avec un message d'erreur orphelin. Les deux bascules sont donc de **vrais liens** (`data-pf-auth-target`) : tout fonctionne sans JavaScript ; `assets/js/account-auth.js` ne fait qu'éviter le rechargement, et **réécrit l'URL via `history.replaceState`** (en reprenant le `href` du lien, pas en reconstruisant l'URL) pour que le POST suivant parte au bon endroit.

- **URL dédiées, servies sur place** : règle de réécriture `^connexion/?$` et `^creer-un-compte/?$` → `index.php?page_id=<myaccount>&pf_auth=login|register`. La requête résout sur la **vraie page compte** — `is_account_page()` est authentiquement vrai, donc noindex, notices et gestionnaires de formulaire fonctionnent normalement ; **rien n'est simulé**. Seule l'adresse affichée change. `?action=register` reste supporté (forme historique, et cible d'une éventuelle 301 déjà en cache navigateur).
  - **Dépendances** : uniquement des API du cœur WordPress (`add_rewrite_rule`, `page_id`, `redirect_canonical`, `query_vars`) + un seul appel public WooCommerce, `wc_get_page_id('myaccount')`. **Moins exposé aux MAJ WooCommerce que l'override `form-login.php`**, qui lui demande une comparaison de version.
  - **`redirect_canonical` neutralisé** quand `pf_auth` est présent : sinon WordPress voit que l'URL demandée diffère du permalien de la page interrogée et renvoie un 301 vers `/mon-compte` — l'URL dédiée disparaîtrait aussitôt.
  - **Flush unique auto-cicatrisant** (option `pf_auth_rw_version`, signature incluant l'ID de la page compte → changer de page compte régénère les règles). Même motif que `pf_carte_rw_version`. ⚠️ `preg_quote()` volontairement **absent** du slug : depuis PHP 7.3 il échappe le tiret, et la table de réécriture devient illisible (`^creer\-un\-compte/?$`) — les slugs sont des littéraux du fichier, pas une entrée utilisateur.
  - **Deux garde-fous** (`template_redirect` priorité 1) : visiteur **déjà connecté** sur une URL d'auth → page compte (sinon il verrait son tableau de bord sous l'adresse « /connexion ») ; et **repli 404** si les règles manquent en base (migration, option `rewrite_rules` purgée) → redirection vers la page compte dans le bon état. Le cœur ne relit jamais en direct les règles ajoutées par `add_rewrite_rule()`, d'où ce filet — même gotcha que les catalogues PDF.
  - **Pas de contenu dupliqué** : la page étant en noindex, Rank Math n'émet aucun canonical — les deux URL sont simplement invisibles pour les moteurs.
  - ⚠️ **Les points d'entrée du site doivent viser `pf_auth_url('login')`, pas `wc_get_page_permalink('myaccount')`** — sinon un bouton libellé « Connexion » dépose le visiteur sur `/mon-compte` et l'URL dédiée ne sert jamais. Trois aujourd'hui, tous conditionnés à `! is_user_logged_in()` (connecté, c'est bien la page compte) : bouton du header et entrée du tiroir mobile (`inc/header-hooks.php`), bouton « liste de lecture » invité de la fiche livre (`inc/class-reading-list.php`). Chacun retombe sur la page compte si `pf_auth_url()` n'existe pas.
    - **Le bouton « liste de lecture » invité ne navigue plus au clic** : `shelf-bookmarks.js` intercepte (`preventDefault`) et lève un toast « Veuillez vous connecter pour pouvoir ajouter *Titre* à votre liste de lecture. » + action **« Se connecter » → `/connexion`**. Fermeture automatique à **7 s** (plus que les 5 s des confirmations d'ajout/retrait : il y a davantage à lire ET un bouton à atteindre) et **une seule invitation à la fois** (re-cliquer n'empile rien tant que la précédente n'est pas quittée). Le `<a href>` reste un vrai lien : c'est le repli sans JS **et** la cible de l'action du toast — donc `pf_auth_url('login')` est toujours l'unique source de l'URL.
- **Pourquoi un override de template ici** (alors que la seule bascule aurait pu se faire par hooks + CSS) : les besoins sont markup-level — `<h1>`, libellés en placeholder, attributs de clavier mobile. ⚠️ Override calqué sur le cœur **`@version 9.9.0`** : à comparer lors d'une MAJ majeure de WooCommerce (même obligation que `cart/mini-cart.php`). Tous les `do_action` et champs nonce d'origine sont préservés.
- **Libellés en placeholder** (convention du site, cf. bloc newsletter : ` *` suffixé sur les champs obligatoires, rien sur les facultatifs), mais **`<label>` conservés en `.screen-reader-text`** — un placeholder n'est pas un libellé accessible (il disparaît à la saisie). Les champs Prénom/Nom (facultatifs, greffés par `pf_register_name_fields()` dans `inc/recommendations.php`) suivent la même convention.
- **Titres en `<h1>`** : la page compte déconnectée n'en avait **aucun**. Le panneau masqué l'est en `display:none` → hors arbre d'accessibilité et hors ordre de tabulation, donc un seul `<h1>` exposé et pas de champ invisible captant l'autocomplétion.
- **Claviers mobiles** : `inputmode="email"` + `autocapitalize="none"` + `autocorrect="off"` + `spellcheck="false"` sur l'identifiant de connexion — **pas `type="email"`**, ce champ acceptant aussi l'identifiant WordPress (généré depuis l'email, réglage `woocommerce_registration_generate_username`), que la validation HTML5 rejetterait. Le champ email d'inscription garde `type="email"`. `autocapitalize="words"` sur Prénom/Nom, `enterkeyhint="go"` sur les mots de passe.
- **Layout (`account.css`)** : le titre vit **hors** de la carte, seul le formulaire est encadré (`.pf-panel`) — WooCommerce pose son propre cadre gris sur `form.login`/`form.register` en (0,2,1), ré-imposé en (0,3,1) avec les tokens du composant, sans `!important`. ⚠️ `.woocommerce` est en **flex** (layout nav + contenu du compte connecté) et `.woocommerce-notices-wrapper` en est un autre enfant : `.pf-auth` prend `flex: 0 0 100%` pour occuper sa ligne, sinon une notice d'erreur courte tiendrait à côté du formulaire. Le plafond de largeur (`--pf-auth-width`) est porté par le **panneau**, pas par l'item flex (un `max-width` sur l'item réduirait sa taille hypothétique et autoriserait à nouveau la cohabitation) ; les notices sont calées sur la même colonne via `:has()`.
- **Plein écran** : `min-height: calc(100svh - var(--pf-auth-top))` + `align-content: center` sur la zone → header + panneau occupent au moins la hauteur du viewport, panneau centré verticalement, **le haut du bloc newsletter tombant exactement sur le bas de l'écran**. Pour ça il faut aussi neutraliser la `margin-bottom` de `#primary.content-area` (48 px) — elle s'ajouterait sous la zone et repousserait la newsletter sous la ligne de flottaison ; scopée par `:has(.pf-auth)`. La marge **haute** du même élément est conservée (respiration sous le header) : elle est déjà comptée dans `--pf-auth-top`, mesuré depuis le haut du viewport. `svh` et non `dvh`/`vh`, même choix que le hero d'accueil (stable face au repli de la barre iOS). **`--pf-auth-top` = distance réelle entre le haut du viewport et le début de la zone** : CSS ne sait pas l'exprimer, car `--pf-sticky-offset` ne compte que les barres **collantes** (80 px) alors que la zone démarre à ~184 px — le bandeau bêta, non collant, occupe le haut du header, plus les 32 px de `margin-top` de `#primary.content-area` (`--global-md-spacing`, seul ancêtre porteur d'espacement, vérifié en parcourant la chaîne). `account-auth.js` mesure donc la position réelle et écrase la variable en inline ; la valeur déclarée en CSS (`sticky-offset + md-spacing`) est le repli sans JS, exact une fois le bandeau bêta retiré. Le `gap` du conteneur est mis à 0 (le wrapper de notices, **même vide**, forme une ligne flex → un gap complet s'insérerait au-dessus du panneau), l'espacement notice → panneau étant porté par la notice via `:has(*)`.
- **Placeholder qui s'efface au focus** : WooCommerce pose `[placeholder]:focus::-webkit-input-placeholder { opacity: 0 }` avec un fondu retardé d'une demi-seconde, sur tout `form .form-row` (règle identique dans `woocommerce.css` **et** dans le `woocommerce.min.css` de Kadence, toutes deux en 0,4,2) — comportement isolé sur le site, où les placeholders tiennent jusqu'à la saisie. Neutralisé **globalement dans `style.css`** (tous les formulaires WooCommerce classiques : compte, connexion/inscription, adresses, mot de passe perdu), en préfixant `body` → (0,4,3), ce qui l'emporte quel que soit l'ordre de chargement des feuilles, sans `!important`. ⚠️ Règle **isolée**, à ne jamais grouper avec la version standard `::placeholder` : un navigateur qui ne connaît pas l'un des pseudo-éléments d'une liste de sélecteurs invalide la règle **entière**.
- **« Rester connecté »** : Kadence impose `bold` + 90 % + palette5 sur tout `form .form-row label` (0,2,2) ; retour à la voix de texte courante en (0,3,0), case et libellé alignés (`display:flex; align-items:center`) avec un `gap`.

---

### Events → `tribe_events` post type (The Events Calendar)

SCF field group `group_69ea16cde9aef` ("Participants et événement marquant") — attached to `tribe_events`:

| Field name | SCF type | Notes |
|---|---|---|
| `passiflore_participe` | true_false | Whether Éditions Passiflore is participating in the event |
| `personnes_participant_a_l'evenement` | repeater | People participating — sub-fields below |
| `evenement_marquant` | true_false | `field_6a2d7bf8f5514` — if checked, the event appears in "Événements marquants" on associated book and author pages even after it has passed |

**`personnes_participant` sub-fields:**
- `assignation` (radio): `fiche-auteur` or `champ-texte`
- `fiche_auteur` (taxonomy field → `auteur`): shown when assignation = fiche-auteur
- `nom_de_la_personne` (text): shown when assignation = champ-texte

**Books associated with an event — `_pf_event_books` (custom post meta):**

Books are stored as a plain array of product IDs in the `_pf_event_books` post meta key — **not an SCF field**. Managed via a custom meta box in `inc/event-admin.php` that replaces the former SCF relationship field.

- Order is user-defined (drag-and-drop in the admin) and preserved as-is on the front end.
- Deduplication by `format_groupe` happens at *input time* (AJAX endpoints), not at render time.
- Read with `get_post_meta( $event_id, '_pf_event_books', true )` → array of int IDs.

**Logic for displaying books on an event page:**
1. Read `get_post_meta( $event_id, '_pf_event_books', true )` — ordered array of product IDs
2. Display via `[passiflore_etagere ids="..." mode="scroll"]` — Bookshelf respects `post__in` order

**Lieux (`tribe_venue`) — Département / Région (`inc/venue-admin.php`) :**

Deux champs ajoutés au formulaire natif TEC « Informations du lieu » (hook `tribe_events_after_venue_metabox`), à choix contraint : vide, ou exactement une valeur des listes officielles `PF_VENUE_DEPARTEMENTS` (101, avec code) / `PF_VENUE_REGIONS` (18, régions post-2016 type Nouvelle-Aquitaine) définies dans ce fichier. Combobox JS réutilisable (`assets/js/venue-admin.js`, calqué sur le mode « liste préchargée » de `book-picker.js`) : dropdown affiché dès le focus, filtré à la frappe (normalisation accents/casse), sélection au clic ; toute saisie ne correspondant pas exactement à une entrée de la liste est ré-écrasée par la dernière valeur valide au blur.

- **Position dans le formulaire** : Ville → Code postal → Département → Région → Pays. Le hook `tribe_events_after_venue_metabox` ne s'exécute qu'en toute fin de formulaire (pas de point d'ancrage natif à cet endroit) → Code postal + les deux nouveaux `<tr>` sont repositionnés en JS après rendu (`venue-admin.js`, déplacement DOM juste après `tr.tribe-linked-type-venue-city`), plutôt que de dupliquer le template natif TEC pour gagner un point d'insertion.
- **Pays par défaut** : « France » pour tout nouveau lieu sans `_VenueCountry` déjà enregistré, via le filtre natif TEC `tribe_events_default_value_strategy` (sous-classe anonyme de `Tribe__Events__Default_Values::country()`) — pas de rétro-application aux lieux existants ayant déjà une valeur (même vide).
- **Landes / Nouvelle-Aquitaine épinglés en tête des listes déroulantes** (siège de la maison d'édition) via `pf_venue_options_pinned_first()` — appliqué uniquement à l'ordre d'affichage JS (`pfVenueAdmin.departements`/`.regions`), les constantes `PF_VENUE_DEPARTEMENTS`/`PF_VENUE_REGIONS` restent alphabétiques (validation, maintenance).
- **Cascade d'auto-remplissage** (`venue-admin.js`) : Code postal → Département (table locale `pfVenueAdmin.postalPrefixToDept`, préfixe 2 chiffres métropole / 3 chiffres DOM, Corse 20xxx exclue — 2A/2B non déductible du préfixe, laissée en saisie manuelle) → Région (`pfVenueAdmin.deptToRegion`, relation exacte 1:1, `PF_VENUE_DEPARTEMENT_TO_REGION` en PHP). La sync Département→Région s'applique aussi à une sélection manuelle de Département (pas seulement via le code postal) : les deux passent par la même fonction `confirm()` du combobox.
- **Case « Ce lieu n'a pas de nom, remplacer par l'adresse »** (`pf_render_venue_name_is_address_checkbox`, hook WP `edit_form_after_title` — le champ Titre natif n'appartient pas au tableau « Informations du lieu », donc point d'ancrage distinct de `tribe_events_after_venue_metabox`) : cochée, le Titre (`#title`) passe en `readonly` (pas `disabled` : un champ disabled est exclu du POST) et se remplit à la volée depuis l'Adresse (`#venueAddress`, JS). Persistée en `_VenueNameIsAddress` par `pf_validate_venue_departement_region()` — `isset()` plutôt qu'un ternaire, une checkbox décochée n'étant pas soumise en POST. Le générateur ICS (`pf_event_ics_common()`, `inc/event-hours.php`) omet l'adresse du `LOCATION` quand ce flag est vrai (déjà présente dans le nom) ; le bloc adresse public natif de TEC (`modules/address.php`) n'est en revanche pas corrigé — la duplication visuelle y reste (nécessiterait de surcharger le template).
- **Sauvegarde** : champs nommés `venue[Departement]` / `venue[Region]` → persistés automatiquement en post meta `_VenueDepartement` / `_VenueRegion` par le mécanisme générique natif de TEC (`Tribe__Events__Venue::save_meta()`, qui enregistre tout champ `venue[X]` soumis en `_VenueX`) — pas de code de sauvegarde dédié. Une passe de validation (`save_post_tribe_venue`, priorité 20, après le save TEC à 16) réduit toute valeur hors liste à `''`.
- **Recherche** : `_VenueAddress` (adresse), `_VenueDepartement` et `_VenueRegion` sont indexés dans `_pf_search_index` par `pf_search_index_event()` (`inc/search.php`), au même titre que le titre/ville du lieu — un événement remonte donc en recherche globale par rue, département ou région.
- **Champ natif TEC « State or Province » remplacé** : masqué du formulaire (CSS ciblant `tr.tribe-linked-type-venue-state-province`, dans `pf_enqueue_venue_admin_assets`) et synchronisé sur Département à chaque sauvegarde (`_VenueProvince` / `_VenueStateProvince` écrasés avec la valeur validée) — ce champ natif alimente déjà, en amont, le bloc adresse **public** de la fiche événement (`tribe_get_full_address()` / `tribe_get_region()`) et le schema.org JSON-LD natif de TEC (`addressRegion`, `Tribe__Events__JSON_LD__Venue`) ; la synchronisation évite la double saisie et garantit que ces deux sorties utilisent la valeur contrainte plutôt que l'ancien texte libre.
- **ICS** : le `LOCATION` du VEVENT (`inc/event-hours.php`, `pf_event_ics_common()`) inclut désormais l'adresse (`tribe_get_address()`) entre le nom du lieu et la ville — département/région volontairement exclus (granularité trop large pour la géolocalisation calendrier).
- **Création en ligne depuis la fiche événement** : TEC propose aussi un mini-formulaire « Créer un nouveau lieu » directement sur l'écran événement (`tribe_events`), rendu par un template TEC **distinct** (`create-venue-fields.php`, cloné en JS depuis un `<script type="text/template">` par `events-admin.js`) — les champs Département/Région n'y apparaissaient donc pas par défaut. Portés via le hook `tribe_events_linked_post_new_form` (priorité 20, après le callback natif de `Tribe__Events__Venue`), en tableau `venue[Departement][]`/`venue[Region][]` (convention imposée par ce template) plutôt que `venue[Departement]`. Case « nom = adresse » également portée (`#pf-venue-name-is-address-inline`, ciblant le champ « Nom du lieu » `venue[Venue][]` plutôt que `#title`, absent dans ce contexte) — positionnée juste après le dropdown de sélection/création du lieu, avant Adresse (Département/Région/CP restent groupés entre Ville et Pays, cf. `reposition()` dans `venue-admin.js`).
  - **Sauvegarde distincte** : ce chemin passe par `Tribe__Events__Venue::create()`, où `save_meta()` natif (écrit `_Venue{Champ}` bruts pour chaque champ soumis) s'exécute **après** `wp_insert_post()` — donc après que `save_post_tribe_venue` a déjà fireé. Brancher la validation au même hook que la fiche autonome serait donc écrasée juste après par `save_meta()` ; utilisé à la place l'action dédiée **`tribe_events_venue_created`** (déclenchée juste après `save_meta()`), cf. `pf_sync_venue_fields_on_create()`. `pf_validate_venue_departement_region()` (fiche autonome) est de son côté gardée par un contrôle `$_POST['post_ID'] === $post_id` (repris de `Tribe__Events__Main::save_venue_data()`) pour ne pas se déclencher à tort sur ce second chemin.
  - **CSS `!important` requis** sur `tr.tribe-linked-type-venue-state-province { display:none }` : dans ce contexte (pas sur la fiche lieu autonome), le JS natif TEC affiche les lignes `.linked-post` via `.show()` jQuery (style inline) quand le mini-formulaire est révélé, ce qui écraserait une règle sans `!important`.
  - **Ré-initialisation JS différée** : `venue-admin.js` clone ces champs depuis le **propre** `document.ready` de `events-admin.js` (TEC), sans dépendance de script explicite entre les deux fichiers → ordre d'exécution non garanti entre les deux handlers `document.ready`. `pfVenueAdminRun()` est donc rejouée une seconde fois au `window load` (après tous les `document.ready`), avec des garde-fous d'idempotence (`data('pfInited')`) sur chaque élément.
  - **Case « nom = adresse » vidée à l'enregistrement malgré une coche visible** : le JS natif TEC réinitialise `value=""` sur tous les `<input>`/`<select>` des lignes `.linked-post` dès qu'un `change` se déclenche sur le dropdown de lieu — y compris automatiquement une fois au chargement. Cocher la case ensuite ne restaure pas cet attribut (cocher/décocher ne touche que `checked`, jamais `value`) : elle reste visuellement cochée et remplit bien le Nom depuis l'Adresse, mais se soumet vide → `_VenueNameIsAddress` enregistré à `0` malgré la coche. Rempart dans `wireNameIsAddress()` : `value="1"` réimposé juste avant l'envoi du formulaire (`submit` sur `$checkbox.closest('form')`), dernier moment possible.
  - **Géocodage** (`inc/class-events-map.php`) : même souci d'ordre — `geocode_on_save()` (sur `save_post_tribe_venue`) lirait une adresse encore vide pour ce chemin de création ; rebranché aussi sur `tribe_events_venue_created`.
  - Pas de 3ᵉ chemin de création de lieu à couvrir en pratique : `toggle_blocks_editor` est désactivé sur ce site (vérifié en base) donc l'éditeur classique est actif pour `tribe_events`, seul chemin emprunté ici. TEC expose bien des endpoints REST (`/wp/v2/tribe_venue`, `/wp-json/tec/v1/venues`, legacy `/wp-json/tribe/events/v1/venues`) indépendants de ce toggle, mais aucune UI de ce site ne les utilise — un lieu créé par ce biais (script externe, futur client headless) ne passerait pas par `pf_sync_venue_fields_on_create()`.
  - **Dropdown « Créer ou trouver un lieu/organisateur » restreint à la recherche** : les dropdowns natifs (lieu **et** organisateur) font aussi office de « créer en tapant » (mode Select2 « freeform », option « Create: <texte> »). Désactivé pour `tribe_venue` **et** `tribe_organizer` via le filtre officiel `tribe_events_linked_posts_dropdown_enable_creation` (`pf_disable_linked_post_dropdown_creation`, `inc/venue-admin.php` — nom de fichier resté historique, couvre les deux types) — le placeholder passe automatiquement à « Trouver un lieu »/« Trouver un organisateur » (même mécanisme natif, `get_create_or_find_labels()`). Un bouton dédié « Créer un lieu »/« Créer un organisateur » (+ lien « Annuler ») révèle le mini-formulaire à la place (`wireCreateButton()`, `venue-admin.js`), en reproduisant à la main l'état interne de TEC (`.tribe-is-creating-linked-post`, affichage des `.linked-post`, sentinelle `-1`) — **pas de hook officiel pour ça**, plus fragile que le reste (à surveiller aux mises à jour de TEC). Comme le formulaire natif garde toujours le champ « Nom » caché (rempli en douce par le texte tapé dans le dropdown), ce champ redevient visible ici — c'est la seule façon de nommer le lieu/l'organisateur sans passer par le dropdown.
    - **Organisateurs : `allow_multiple` vrai par défaut** (contrairement aux lieux) → plusieurs lignes dropdown possibles (une par organisateur déjà lié à l'événement) et bouton natif « + Ajouter un autre organisateur » pouvant en cloner de nouvelles à tout moment, en dehors de tout `document.ready`. `wireCreateButtonsFor()` câble toutes les lignes présentes au chargement (`.each()`) ; `hookDynamicRows()` s'abonne en plus au hook JS natif `tec.events.admin.linked_posts.add_post` (`wp.hooks`, dépendance de `tribe-events-admin`) pour câbler chaque nouvelle ligne clonée. Chaque ligne a son propre bouton/état, scopé à son `<tbody>` — pas d'interférence entre organisateurs.

**Header restructuré (liste + mois)** (override `tribe/.../components/header.php`) :

Sur les deux vues d'archive, le `<header>` est réorganisé en barre **sticky « smart-hide » full-bleed** `.pf-events-header-bar.pf-sticky-bar` (mécanisme commun avec /auteurs et /catalogue, cf. style.css) : **top-bar à gauche**, **« S'abonner » + sélecteur de vue (Liste/Mois) à droite** (CSS dans `events.css`). Layout posé via `display:block` sur le header (TEC le met en flex) + flex interne `__inner`/`__left`/`__right`.

- **Sticky sur le `<header>` lui-même** (et non sur un enfant) car son parent `.tribe-events-l-container` est haut → il colle sur toute la hauteur du contenu. ⚠️ TEC pose `position:relative` **et des marges/paddings** sur `.tribe-events-header` (0,2,0) → on rétablit `position:sticky` **et le full-bleed** (width 100vw + marges/paddings négatives) avec une spécificité supérieure (`.tribe-events-view .tribe-events-header.pf-sticky-bar`), sinon le header reste contraint au conteneur (marge à gauche, débordement à droite). `assets/js/events-month.js` bascule `.is-hidden`/`.is-stuck` (re-query à chaque frame → résilient aux re-rendus AJAX), pour les **deux** vues.
- **Vue liste** : top-bar masqué (`.tribe-events-view--list .tribe-events-header__top-bar { display:none !important }`, dans events-infinite.css) ; `__left` contient la recherche événements (voir plus bas), « S'abonner » + switch à droite. Les **séparateurs de mois** du scroll infini sont **full-bleed pleine largeur écran** (`width:100vw` + marges négatives en `!important`, sinon TEC pose des marges qui décalaient la barre à droite) et collent sous le header tant qu'il est apparent, remontent quand il se masque : `top = calc(var(--pf-sticky-offset) + var(--pf-ev-header-offset))`, où `--pf-ev-header-offset` (= hauteur du header visible, 0 si masqué) est tenu à jour par `events-month.js`. Ombre portée quand collé (classe `is-stuck` posée par `events-infinite.js` selon `rect.top` vs la ligne sticky ; **pas** de transition `top` sur le séparateur, sinon `rect.top` serait lu en cours d'animation). **« Push » entre séparateurs** : tous les `<li>` étant frères d'un même `<ul>`, leur containing block sticky descend jusqu'au bas → pas de chasse CSS native (ils s'empileraient à la même ligne, ombres superposées). `updateStuck()` simule la chasse : quand le mois suivant empiète, il pousse le séparateur sortant vers le haut via `translateY(-overlap)` (bas du sortant collé au haut de l'entrant, qui — plus bas dans le DOM, même `z-index` — le recouvre → une seule ombre visible). Mesure en deux passes (reset des transforms avant lecture des rects) ; transforms purgés au teardown.
- **« S'abonner » : une seule instance, dans le header** (liste + mois). `header.php` le rend dans le header ; l'override `ical-link.php` supprime le rendu par défaut en bas de page via le drapeau `$GLOBALS['pf_events_ical_rendered']` (réinitialisé par le header). ⚠️ Drapeau global, **pas** une variable de template : TEC fusionne les variables globalement, elles « fuiteraient » entre les deux appels.
- **Action directe vers le calendrier détecté** (`assets/js/subscribe-calendar.js`, amélioration progressive JS pure — aucun changement serveur, menu natif TEC intact sans JS) : au lieu d'ouvrir d'emblée le menu à 6 options, le bouton relabellé (« Ajouter à Apple Calendar » / « Ajouter à Google Calendar », détection `Macintosh|iPad|iPhone|iPod` vs reste) déclenche directement le lien correspondant (`.click()` sur le vrai `<a>` du menu, slug `ical`/`gcal` — réutilise le href/target/rel déjà corrects plutôt que de les reconstruire). Visuellement, le pill uni devient deux moitiés soudées via **`.pf-splitbtn`/`.pf-splitbtn--solid`** (style.css, composant partagé) — même motif que la paire rayon/thématiques du catalogue (Littérature ⋮ / Culture Sud-Ouest ⋮, `.pf-cat-coll-pair`) et même hauteur que le sélecteur Liste/Mois/Carte (padding/font-size/line-height alignés sur `.pf-switch__btn`). Le menu complet reste accessible via le kebab (moitié droite, `.pf-splitbtn__more`) : il ne porte aucun handler dédié, un clic dedans suffit à déclencher le toggle délégué de TEC (`ical-links.js`, bindé sur tout `.tribe-events-c-subscribe-dropdown__button`). Sans JS, le pill reste uni (fallback CSS scopé via `:not([data-pf-ical-enhanced])`, kebab décoratif `::after`). Ré-init AJAX : même idiome MutationObserver que `events-infinite.js`/`events-search.js`.

**Vue liste — événement = carte cliquable** (override `tribe/.../list/event.php` + `events.css`) :

- L'override ajoute la classe **`pf-card`** au wrapper `.tribe-events-calendar-list__event-wrapper` → fond/rayon/ombre/survol hérités de `style.css`. Le **date-tag reste à gauche, hors carte** (frère du wrapper dans le `<li>`).
- **Carte entièrement cliquable via lien étiré** : un `<a class="pf-card-link">` **vide** (href = permalien, `aria-label` = titre) en `position:absolute; inset:0; z-index:1` couvre toute la carte. On évite un `<a>` englobant (HTML invalide : il imbriquerait d'autres ancres).
- **Titre non-lien** (override `list/event/title.php`) : `<h4 class="… pf-card-title">` sans `<a>`. Le look « titre de carte » est réappliqué dans `events.css` avec une spécificité supérieure à TEC (couleur `--pf-heading`, taille `--pf-text-base` **en `!important`** car un reset global `h4 { font-size: var(--pf-text-sm) !important }` l'emporterait sinon ; poids semibold). Rougit au survol de la carte. On **ne touche pas** à `order` (layout du header). 
- **Description** (`list/event/description.php`) : `passiflore_render_event_description_text( $event )` (`inc/events.php`) source toujours `post_content` (jamais l'extrait manuel `post_excerpt`), blocs Gutenberg/shortcodes retirés, seuls `<strong>/<b>/<em>/<i>` conservés (`wp_kses`), paragraphes/`<br>` aplatis en un flux de texte. Pas de découpe en PHP : troncature purement visuelle à 2 lignes (`pf-card-text pf-card-text--clamp-2`, `-webkit-line-clamp`). Même fonction réutilisée par la recherche (`class-events-search.php`).
- **Lieu / organisateur / participants** (`list/event/description.php`, sous la description) : `passiflore_render_event_card_meta( $event )` (`inc/events.php`) — remplace l'adresse complète native TEC (`list/event/venue.php`, retiré de l'override `list/event.php`, tout comme `list/event/category` et `list/event/cost`, non pertinents ici) par un bloc `.pf-event-card-meta` à lignes `LIEU`/`ORGANISATEUR`/`PERSONNES PRÉSENTES` (label `.pf-label`, valeur `.pf-card-text`), chacune omise si vide. Lieu = nom + ville (`$event->venues[0]->post_title`/`->city`), même pattern que `Passiflore_Event_Tiles::render_tile()` (tuiles auteurs/accueil) — pas l'adresse complète. Organisateur = `$event->organizer_names` (TEC, natif mais jamais affiché nulle part ailleurs sur ce site). Participants : `passiflore_get_event_participant_parts( $id, 'text' )` + `passiflore_join_with_et()` (extraits de `passiflore_render_event_participants()`, qui garde son rendu `<p>Présence de …</p>` pour les vues *day*/single event) — noms en **texte simple**, non cliquables (carte entière = lien). `.pf-event-card-meta` porte le filet de séparation (`border-top`) qu'avait auparavant `.pf-event-participants` seul.
- **Image** : à droite sur **ordinateur/tablette** (`.tribe-common--breakpoint-medium`, rangée `row-reverse` posée par TEC), affichée **entière** (`object-fit:contain`) — l'image est en **absolu** dans son wrapper pour que sa hauteur ne dicte pas celle de la carte (elle remplit la hauteur des détails). En **haut sur mobile** (rangée passée en `column`), **format tuile 16/9 `cover`** comme les tuiles d'événement. **Pas de zoom au survol** (`transform … !important` bat le `.pf-card:hover img`).
- Les cartes ajoutées par le scroll infini héritent automatiquement de l'override (même rendu serveur via `make_for_rest`). ⚠️ Après modif de l'override : vider le cache HTML TEC (transients).

**Vue liste — scroll infini bidirectionnel** (`inc/class-events-feed.php`, `Passiflore_Events_Feed`) :

Remplace la pagination préc./suiv. native par un défilement continu dans les deux sens, en réutilisant le rendu natif de TEC (surcharges de templates, participants, séparateurs de mois — aucun markup dupliqué).

- **Endpoint AJAX `pf_events_feed`** (nonce `pf_events_feed`) : rend la vue liste pour une **URL-curseur** via `Tribe\Events\Views\V2\View::make_for_rest()` (chemin natif REST V2, mais notre nonce admin-ajax — découplé du double-nonce REST volatil de TEC), puis extrait par `DOMDocument` les seuls `<li>` du `<ul class="tribe-events-calendar-list">`. Renvoie `{ html, next_url, has_more }`.
- **Curseurs opaques** : le front lit les hrefs « préc. »/« suiv. » de la nav native, la masque, puis suit le curseur renvoyé par chaque réponse. `has_more` faux quand `next_url`/`prev_url` est vide.
- **Front** (`assets/js/events-infinite.js`) : bas = `IntersectionObserver` auto (append) ; haut = le **1er lot** de passés ne se charge qu'au **clic** sur le bouton rond (aucun geste de scroll/molette/tactile ne le déclenche), puis un `IntersectionObserver` prend le relais pour les lots **suivants** au fil du scroll vers le haut (prepend) avec ancrage du scroll (anti-saut), dé-dup des séparateurs de mois, verrou de concurrence par direction. Une fois descendu dans les passés, un **bouton rond fixe en bas** (flèche bas) ramène au 1er événement à venir (référence figée sur la 1ʳᵉ row au montage) ; il n'apparaît que lorsque le bas du dernier événement passé atteint le bas de l'écran (plus aucun à-venir visible). Boutons ronds calqués sur le bouton de fermeture de la recherche globale (rouge plein rond, icône crème), chacun accompagné d'un **label texte** (`.pf-ev-btn-label`, pilule verre dépoli — coins totalement arrondis, fond `--pf-cream` translucide + `backdrop-filter: blur(var(--pf-aero-blur))` comme les headers sticky, sans ombre ni effet de survol propre) : « Événements précédents » sous le bouton du haut, « Événements à venir » au-dessus du bouton du bas.
- **Anti « rechargement = tout en bas »** : les passés étant chargés en JS, au rechargement la page redevient courte (à-venir seuls) mais le navigateur restaure le grand scrollY mémorisé → rognage au bas. `Passiflore_Events_Feed::print_scroll_restoration` (inline `<head>`, priorité 1 — un script footer arrive trop tard) pose `history.scrollRestoration='manual'` (rétabli `auto` au `pagehide`), + filet `forceTop()` au 1er chargement dans le JS.
- **Ré-init AJAX** : la bascule de vue Liste/Mois de TEC remplace tout le conteneur. Le script est un **contrôleur ré-initialisable** : un `MutationObserver` sur le parent stable ré-active le contrôleur dès qu'une liste réapparaît (et le démonte sinon). Sans ça, après Mois→Liste on retombait sur la nav par flèches au lieu du scroll infini.
- **Gate** : `events-infinite.js` + `events-month.js` + `events-infinite.css` chargés sur **toutes les vues d'archive** (`tribe_is_event_query()`, hors singulier) — pas seulement la vue initiale — pour survivre aux bascules AJAX ; chaque script s'active sur sa vue, inerte ailleurs. **Repli no-JS** : la nav native n'est masquée que par JS.
- ⚠️ **Cache HTML des vues TEC** : après modification d'un override de template, vider le cache (transients `_transient_*tribe*`) sinon l'ancien HTML est resservi.

**Recherche événements** (`inc/class-events-search.php`, `Passiflore_Events_Search`) :

Barre de recherche rendue dans le header sticky (`__left`, à gauche de « S'abonner »), **vue liste uniquement** — en vue mois cet emplacement reste occupé par le top-bar natif (préc./suiv./datepicker) ; le rendu conditionnel (`if ( 'list' === $pf_slug )` dans l'override `components/header.php`) suffit, TEC re-rendant tout le header à chaque bascule de vue.

- **Moteur partagé** : `pf_search_events_ranked()` (`inc/search.php`), la même logique de scoring/tri (« à venir d'abord, plus proche en premier, puis passés ») que la section Événements de la recherche globale (`Passiflore_Recherche_Globale::search_evenements()`, qui l'appelle aussi désormais — refactor sans changement de comportement). Pas de filtre « marquant » : un événement passé quelconque est trouvable ici, comme il l'est déjà via le bouton « charger les passés ».
- **Endpoint AJAX `pf_events_search`** (nonce `pf_events_search`) : paginé par lots de `PAGE_SIZE = 12` (même taille que le scroll infini de la liste normale) via un simple `offset` entier envoyé par le client — pas de curseur d'URL comme `pf_events_feed` : le classement complet (`pf_search_events_ranked()`) est recalculé à chaque appel (négligeable) et tranché côté serveur selon l'offset. Réponse `{ html, has_more, count }` (`count` = nb d'événements réellement rendus dans ce lot, pour que le client incrémente son offset). Séparateurs mois/année : première occurrence dans l'ensemble des résultats (pas seulement sur la page courante) — pour les pages > 0, les mois déjà vus sont déduits d'un batch direct sur `_EventStartDate` (pas de ré-enrichissement `tribe_get_event()` des événements déjà rendus). Min. 2 caractères pour déclencher une recherche.
- **Rendu des lignes sans passer par le pipeline Vue/Repository de TEC** : vérifié dans le code source de TEC — `List_View` force son propre tri par date à plusieurs endroits et ses templates dépendent de variables ambiantes (`$is_past`, `$request_date`…) que seul un rendu de Vue complet positionne ; y injecter un `post__in` classé aurait été fragile. `Passiflore_Events_Search::render_event_row()` reproduit donc directement, à partir d'un objet `tribe_get_event()`, le HTML des overrides de ce thème (`list/event.php` + `date-tag`/`title`/`description`) et des partiels core encore visibles ici (`featured-image`, `venue` — `date`/`cost`/`category` sont soit masqués en CSS soit jamais alimentés sur ce site, donc omis).
- **Deux scroll infinis superposés pendant une recherche** (`assets/js/events-search.js`) : (1) la liste normale + son échafaudage (`.tribe-events-calendar-list`, `.pf-ev-top`, `.pf-ev-bottom`, `.pf-ev-tofuture`) sont masqués via la classe `.pf-ev-search-hidden` **sans être détruits** — lots déjà chargés (y compris les passés tirés vers le haut) et position de scroll sont préservés — et restaurés tels quels (+ `window.scrollTo` à la position mémorisée) à la fermeture de la recherche (champ vidé ou bouton effacer) ; (2) les résultats ont leur propre pagination : `<ul class="tribe-events-calendar-list pf-ev-search-results">` frère (sélecteur `:not(.pf-ev-search-results)` pour que la liste réelle reste identifiable sans ambiguïté une fois affichée) suivi de son propre `.pf-ev-bottom`/`.pf-ev-sentinel` + `IntersectionObserver` — mêmes classes que la liste normale, donc même spinner logo, sans CSS dédié. Une nouvelle requête (frappe) réinitialise l'offset à 0 et remplace le contenu ; le scroll dans les résultats charge la suite (`insertAdjacentHTML('beforeend', …)`). Ce second échafaudage est créé une seule fois par session de recherche active puis réutilisé d'une frappe à l'autre (seul son contenu change), et démonté à la fermeture de la recherche ou au changement de vue.
- **Éphémère** : pas de synchronisation URL (choix délibéré, comme la recherche globale) — recharger la page ou changer de vue (Liste↔Mois, qui remplace tout le conteneur y compris la barre) vide la recherche.
- **Ré-init AJAX** : même mécanisme que `events-infinite.js` — `MutationObserver` sur le parent stable, contrôleur ré-activé si une nouvelle barre apparaît (et démonté sinon, sans tenter de restaurer le scroll : le conteneur entier part avec).
- **Placeholder responsive** : mêmes textes long/court que la recherche globale pour le type « Événements » (`inc/class-recherche-globale.php`), repris tels quels via `data-placeholder-sm`. Desktop (>768px) : placeholder long, toujours affiché. Mobile (≤768px) : champ au repos affiche un simple « Événement » (`PassifloreEventsSearch.placeholder_mobile`, localisé PHP) — la barre resserrée à côté de « S'abonner » n'a pas la place pour le texte complet — et ne bascule sur le placeholder court détaillé qu'au focus (retour à « Événement » au blur).
- **Mobile (≤781px, 3 vues d'archive)** : `__right` (S'abonner + switch) est « déballé » (`display:contents`) pour que ses deux enfants redeviennent des flex items indépendants de `__inner` — le contenu de `__left` (recherche en liste, date-picker en mois — déjà compact nativement à cette largeur —, recherche carte via `Passiflore_Events_Map::render_search_bar()`) + « S'abonner » restent sur la même ligne (`__left` en `flex:1 1 0%`, base à 0 pour ne pas forcer un retour à la ligne avant même le calcul du grow/shrink), le switch Liste/Mois/Carte passe seul sur une 2ᵉ ligne pleine largeur (`!important` nécessaire pour battre les règles desktop `!important` du même fichier qui figent l'events-bar à sa largeur de contenu). Sélecteurs dupliqués par vue (`.tribe-events-view--list/--month/--carte`, classe posée automatiquement par TEC sur toute vue V2) plutôt qu'un sélecteur générique, pour garder le scope explicite. Recherche « active » (focus ou texte, classe `is-searching` posée sur `.pf-sub-header` par `events-search.js` **et** `events-map.js` — même détection que `.pf-catalogue-bar` côté /catalogue) : « S'abonner » se réduit à `max-width:0` et la recherche grandit dans l'espace libéré. Liste et carte uniquement (règle CSS scopée à ces deux vues) : le date-picker de la vue mois ne pose pas cette classe.
  - ⚠️ **`.tribe-events-c-subscribe-dropdown__button-text` : `white-space: nowrap` obligatoire.** Sans ça, pendant que le conteneur `max-width` transite en continu (0.3s), le libellé « S'abonner au calendrier » repasse à la ligne (jusqu'à 3 lignes) avant d'atteindre `overflow:hidden` → le bouton et donc tout le header gonflent en hauteur le temps de la transition. Avec `nowrap`, le texte déborde simplement et se fait rogner proprement par l'`overflow:hidden` du conteneur, à hauteur constante.

**Vue « Carte » — 3ᵉ onglet après Liste / Mois** (`inc/class-events-map.php`, `Passiflore_Events_Map` + `Passiflore_Map_View`) :

Réimplémentation maison de la vue Carte d'Events Calendar **Pro** (payant), sans licence : une **vraie vue TEC V2** (slug `carte`) rendue avec **Leaflet auto-hébergé** (`assets/vendor/leaflet/`, pas de CDN) + **tuiles OpenStreetMap**. Un point = un lieu ; l'infobulle liste le(s) événement(s) à venir de ce lieu (vignette + titre + date, cliquables).

- **Enregistrement de la vue** : `Passiflore_Map_View extends \Tribe\Events\Views\V2\View` (vue minimale : slug `carte`, `publicly_visible`, rend `tribe/events/v2/carte.php`). Enregistrée via `Manager::register_view('carte', …)` sur `init:5` (câble résolution de classe + slugs de réécriture + route `/evenements/carte/`). Forcée dans les vues activées via le filtre `tribe_get_single_option` sur `tribeEnableViews` (sans modifier l'option stockée). Flush unique des permaliens auto-cicatrisant, gardé par l'option `pf_carte_rw_version` (pas de WP-CLI requis).
- **Template `carte.php`** : calqué sur le core `list.php` (même conteneur `.tribe-events-view` + `components/header`), corps = conteneur `#pf-events-map` + repli `<noscript>`. Le header sticky et le switch sont réutilisés tels quels : `components/header.php` traite `carte` comme une vue d'archive (barre sticky + « S'abonner » + switch, **sans** top-bar ni recherche).
- **Switch** (`components/events-bar/views.php`) : l'onglet Carte est un **lien simple** (pas de `data-js`) → tout aller/retour impliquant la carte est un **rechargement complet** (init Leaflet propre, pas de bascule AJAX TEC) ; l'AJAX Liste↔Mois reste intact.
- **Assets** (`assets/js/events-map.js`, `assets/css/events-map.css`) enqueués seulement sur la vue carte (`eventDisplay === 'carte'`). Marqueurs = **DivIcon avec pin SVG inline** (`fill: currentColor` = `--pf-accent`, anneau + point blancs ; pointe exactement au centre = `iconAnchor` bas-centre — pas de transform:rotate approximatif). `venue`/`city` **décodés** (`html_entity_decode`) avant localisation → le JS échappe au rendu, pas de double-encodage des `&`. **Infobulle** : en-tête lieu (nom + ville, hors zone de scroll) + une **rangée horizontale de tuiles d'événement** = le composant global `.pf-card .pf-card--compact .pf-event-tile` (même tuile que l'accueil / la fiche auteur), **pré-rendu côté serveur** par `Passiflore_Event_Tiles::render_tile( $event, $show_lieu = false )` et passé en `html` dans les données de marqueur (l'événement ne porte plus que `id` + `html`). Ligne « Lieu » omise (`$show_lieu = false`) : le lieu est déjà le titre de l'infobulle. Mise en rangée + scroll horizontal via `.pf-map-pop__events` (events-map.css), enveloppée dans le composant global **`.pf-scroll-fade`** (ombres de bord gauche/droite signalant les tuiles hors champ ; fondu vers `--pf-surface-alt` = fond de l'infobulle). `scroll-fade.js` ne scanne qu'au `DOMContentLoaded` donc ne voit pas les infobulles injectées par Leaflet → sa logique (bascule `.is-scroll-left/-right`) est rejouée par `wireScrollFade()` au `popupopen`. Petit `padding` sur `.pf-map-pop__events` : dégage l'ombre de survol des tuiles (sinon rognée par l'`overflow` du scroll) et les écarte de la barre de défilement. Le meta de la tuile est déjà blindé (`!important` / 0,2,0) contre le reset `.tribe-common *`, car partagé avec les cards de la vue liste. Débordement vertical (cluster à plusieurs lieux) : `.pf-map-pop__scroll` plafonné (`max-height: min(340px, 46vh)`) + auto-pan Leaflet (`autoPan:true`) pour révéler l'infobulle entière sans la rogner (un rognage vertical écraserait la rangée de tuiles).
- **Regroupement au dézoom** (`Leaflet.markercluster` 1.5.3, auto-hébergé) : les lieux proches fusionnent en cluster au dézoom, se séparent au zoom. `iconCreateFunction` custom → le pin de cluster affiche le **nombre d'ÉVÉNEMENTS agrégés** (somme des `events.length` des lieux enfants, via l'option marqueur `pfEventCount`), **pas** le nombre de lieux. `zoomToBoundsOnClick:false` + `spiderfyOnMaxZoom:false` → un **clic sur cluster ouvre une infobulle combinée** (au lieu de zoomer), en-tête = **dénominateur commun** des lieux (`commonLabel()` : ville si toutes identiques, sinon département, sinon région, sinon « Plusieurs lieux ») + total d'événements, puis un bloc par lieu (`venueBlock( m, areaLabel )` = sous-en-tête lieu + rangée `eventsRow()` ; `eventsRow()` est partagée avec l'infobulle simple). Dans un cluster, la **ville d'un lieu est masquée si elle égale le dénominateur commun** (évite « Dax » répété sous un en-tête « Dax » ; conservée quand l'en-tête est un département/une région). Repli sur marqueurs simples si le plugin est absent. Instance Leaflet exposée en `el._pfMap` (débogage/tests).
- **Pin de cluster : ancrage explicite** (`iconAnchor:[20,20]` = centre du badge 40×40 quel que soit le palier sm/md/lg ; `popupAnchor:[0,-34]`, aligné sur celui du pin simple). Sans ces deux options, Leaflet retombe sur `popupAnchor:[0,0]` (défaut de `L.Icon` — aucun décalage) : l'infobulle de cluster s'ouvrait quasiment sur le badge, sans jamais s'en détacher visuellement.
- **⚠️ Gotcha de spécificité étendu** (cf. reset `.tribe-common *` déjà documenté plus haut dans ce fichier) : ce reset couvre en réalité `.tribe-common div/p/ul/span/...` avec la spécificité **(0,1,1)** (classe + balise), qui **bat** un sélecteur à classe unique (0,1,0) — y compris pour des éléments **injectés en JS après coup** (le popup Leaflet), dès lors qu'ils héritent d'un ancêtre `.tribe-common` (la vue carte est un template TEC V2, rendu sous un tel ancêtre). Toutes les règles `events-map.css` posant `padding`/`margin`/`border` sur un sélecteur à classe unique doivent donc être blindées en **(0,2,0)** via une classe répétée (`.foo.foo { … }`) : `.leaflet-popup-content-wrapper`, `.pf-map-pop__area`, `.pf-map-pop__venue`, `.pf-events-map-empty`, `.pf-events-map-fallback`. `.pf-map-pop-card.pf-card` et les sélecteurs descendants à 2 classes (`.pf-map-pop-card .pf-card-content`, etc.) étaient déjà naturellement à l'abri. **Diagnostic** : `Array.from(document.styleSheets).flatMap(s=>Array.from(s.cssRules)).filter(r=>r.selectorText && el.matches(r.selectorText))` pour lister toutes les règles concurrentes par ordre de cascade.
- **Filet anti-débordement mobile** (`map.on('popupopen', …)` dans `events-map.js`) : sur un conteneur carte bas (mobile), une infobulle de cluster à plusieurs événements peut dépasser du haut du conteneur (rogné par son `overflow:hidden`) — l'auto-pan natif de Leaflet ne suffit pas toujours et, pire, **entre en conflit** avec un ajustement manuel de hauteur s'il reste actif (d'où `autoPan:false` sur les deux `bindPopup`). Le handler mesure le dépassement réel une fois l'infobulle positionnée, pose `content.style.height` (jamais `maxHeight` seul — rappeler `_updateLayout()` après coup réévalue par rapport à l'option `maxHeight` **d'origine** et retire `leaflet-popup-scrolled`, laissant le contenu déborder visuellement de sa boîte sans y être contenu) + `overflow-y:auto` directement, puis ne rappelle que `_updatePosition()`. Deux passes (marge de 20 px) pour résorber tout résidu.
- **Recherche sur la carte** : barre rendue dans le `__left` du header (comme la vue liste), via `Passiflore_Events_Map::render_search_bar()` — composant visuel `.pf-search--sm` partagé mais **classes propres** (`.pf-map-search` / `.pf-map-search-input`, **pas** `.pf-ev-search-input`) pour que le contrôleur de la liste (`events-search.js`) ne la détourne pas. Endpoint AJAX `pf_events_map_search` (`Passiflore_Events_Map::ajax_search`) : réutilise le **moteur classé partagé** `pf_search_events_ranked( $q, [], true )` (`true` = **à venir uniquement**) et renvoie des **IDs** (pas de HTML). Le front (`events-map.js` : `renderMarkers( list )` extrait pour être ré-appelable + `initSearch()`) filtre `PassifloreMap.markers` sur ces IDs (chaque `events[].id` ajouté au payload), n'affiche que les événements correspondants (les compteurs de cluster suivent), recadre sur les résultats, message `searchEmptyText` si vide, restauration au vidage/`.pf-map-search-clear`. Débounce 250 ms + garde de séquence (dernière requête gagne). Matching riche identique à la recherche liste/globale (titre, participant/auteur, livre associé, lieu/ville/département/région). ⚠️ un événement créé programmatiquement avant que son lieu soit lié n'indexe que le titre → re-lancer `pf_search_index_event( $id )` pour réindexer avec les champs du lieu.
  - **Mobile** : même placeholder « Événement » au repos / détaillé au focus (`PassifloreMap.placeholderMobile`, `data-placeholder-sm`) et même classe `is-searching` (collapse de « S'abonner ») que la recherche liste — logique dupliquée dans `events-map.js` (pas de contrôleur JS partagé entre liste et carte, cf. classes propres ci-dessus) mais mécanisme identique à `events-search.js`.
- **Géocodage (Nominatim / OSM) — côté écriture uniquement** : les lieux (`tribe_venue`) n'ont pas de coordonnées en TEC gratuit. Le front **ne fait jamais d'appel réseau** (lecture du cache post meta seule). Le géocodage a lieu (a) **à l'enregistrement du lieu** (`save_post_tribe_venue`, prio 25) si l'adresse a changé, et (b) via un **backfill WP-Cron auto-drainant** (`pf_geocode_backfill`, planifié en `admin_init`, lot de 5, se replanifie tant qu'il reste des lieux) pour les lieux existants. Résultat en cache dans `_pf_venue_lat` / `_pf_venue_lng` / `_pf_venue_geo_src` (adresse tentée — clé de fraîcheur **et** anti-boucle : stampée même en cas d'échec pour ne pas re-tenter en boucle). Requête Nominatim **en cascade** (structurée rue → `CP ville` → `ville`) pour rester robuste aux adresses imparfaites (rue mal orthographiée ⇒ repli sur le centroïde de la commune). Throttle ≥ 1,1 s/appel + User-Agent identifiant (politique OSM).
- **RGPD** : géocodage = serveur → OSM (adresse du lieu, **aucune donnée visiteur**). Les **tuiles** sont chargées par le navigateur du visiteur (IP + User-Agent + Referer + zone regardée transmis à `tile.openstreetmap.org`) → transfert **inhérent au chargement d'une ressource tierce** (l'IP est indispensable pour recevoir les images ; on pourrait l'éviter en proxifiant les tuiles via notre serveur, disproportionné ici). Bien plus léger que Google Maps de la version Pro (pas de cookies/fingerprinting). Une section dédiée **« Carte des événements (OpenStreetMap) »** a été ajoutée à la politique de confidentialité (page brouillon #3, sous « Quelles données collectons-nous »).
- **Périmètre actuel** : événements à venir / en cours uniquement.
- ⚠️ Après modif d'un override de template (`carte.php`, `header.php`, `views.php`) : vider le cache HTML des vues TEC (transients).

**Fiche événement (single) — override `tribe-events/single-event.php`** (`inc/event-single.php`) :

Contrairement aux vues d'archive (v2, dossier `tribe/events/v2/`), la fiche d'un événement est le template **classique** de TEC → override dans **`tribe-events/single-event.php`** (racine du thème, chemin distinct). Structure : **une grille unique** `.pf-event-hero` (4 zones `head` / `media` / `body` / `sections`, placées par `grid-template-areas`) réunit le hero (titre + planning + image + `post_content` + méta) ET les sections (via `pf_render_sectionnav()`, `.pf-sectionnav` mutualisé avec la fiche livre) — pour que l'**image traverse tout en colonne collée** (voir Layout desktop plus bas).

- **Hero** : `__head` (titre + planning), `__body` (`post_content` **dans le hero** — pas une section de nav — + actions via `passiflore_render_event_hero_meta`), `__media` (image). Actions = **Site de l'événement** (`tribe_get_event_website_url`) + **.ics** (`tribe_get_single_ical_link`, enrichi par nos filtres `event-hours.php`), en classe `.button` (Google Agenda retiré). Le planning (H2) passe par nos filtres `tribe_events_event_schedule_details` (sentinelle 23h59 / planning par jour).
- **Sections** (`passiflore_render_event_sections`, chacune omise si vide) : **Horaires** (`passiflore_render_event_hours`), **Lieu** (`Passiflore_Events_Map::render_single_venue_map` — adresse + Dépt/Région + **mini-carte OSM mono-lieu** Leaflet lisant le cache `_pf_venue_lat/lng` + « Y aller » Google Maps), **Organisateur** (nom + site/email/tél), **Présence** (tuiles participants), **Livres associés** (`[passiflore_etagere]`). **Section Lieu — carte à droite de l'adresse (≥768px, desktop+tablette)** : `.pf-event-venue` passe en grille 2 colonnes [adresse | carte], « Y aller » sous la carte, le titre `LIEU` restant pleine largeur au-dessus (`event-single.css`). `align-items:center` : adresse et carte centrées verticalement l'une par rapport à l'autre (la carte, carrée, est généralement plus haute que le texte de l'adresse). Colonne adresse dimensionnée à son **contenu** (`max-content`, pas `1fr`) : sinon elle absorberait tout l'espace restant et repousserait la carte loin à droite. Scopé à `.pf-event-venue:has(.pf-event-venue__map)` → un lieu sans coordonnées (pas de carte) garde son adresse pleine largeur. Empilé sur mobile ≤767px (adresse, carte, « Y aller »). **Carte toujours carrée et plafonnée à 350px** (`.pf-event-venue__map { aspect-ratio: 1/1; max-width: 350px }`, toutes tailles d'écran) : c'est la largeur disponible de la colonne (avec ce plafond) qui régit la hauteur. Les 3 fonctions de rendu réutilisées (`inc/events.php`) ont perdu leur `<h5>` interne (le `<h2>` de section le remplace) ; leurs anciens hooks `tribe_events_single_event_after_the_content` sont **débranchés** (l'override orchestre).
- **Layout desktop (≥1024px) — DEUX zones** (grille `.pf-event-hero` en `grid-template-areas`, colonnes `[nav | contenu | image]`) :
  - **Zone haute** (rangées head/body/topsec) : head (titre/planning) + body (description/actions) + **topsec** = Horaires/Lieu/**Organisateur** à gauche ; **image collée pleine hauteur à droite**. Un **filet de délimitation** (border-bottom de `.pf-event-hero__body`) marque le passage hero → sections, sur les colonnes nav+contenu **sans l'image** (col3, qui continue). *(Pendant fiche livre : `border-top` sur `.pf-body`.)*
  - **Zone basse** (rangée botsec) : **Présence + Livres associés en PLEINE LARGEUR** (span contenu+image, à droite de la nav), avec un **filet de séparation pleine largeur au-dessus de Présence** (le filet inter-section `.pf-section` est un `border-top` — pas `border-bottom` — pour appartenir à la section pleine largeur ; restauré sur `.pf-event-hero__botsec .pf-section:first-child` que le `:first-child` global neutralise).
  - La **nav** (points, colonne de gauche) longe **topsec ET botsec** → **reste collée partout** (sticky bornée par la grille entière). L'**image** ne couvre que la zone haute → **reprend son scroll dès Présence**. ⚠️ Un élément `sticky` en grille colle dans TOUT le conteneur, pas dans sa seule zone → l'image est enveloppée dans **`.pf-event-hero__media-inner`** (le sticky), dont le containing block `.pf-event-hero__media` (rangées 1-3) borne le collage.
  - Image à proportions naturelles, centrée verticalement, plafonnée à **40%** ; la **colonne image se rétrécit à sa largeur réelle** via **`assets/js/event-single-media.js`** (`--pf-event-media-col = min(40%·conteneur, hauteurDispo·ratio, largeurNaturelle)`, calculé depuis `naturalWidth/Height` — pas de mesure DOM). Mobile/tablette ≤1023px : tout empilé (`head → image → body → nav → topsec → botsec`), image pleine largeur.
  - **`.pf-event-hero` sans `padding-top`** : `.entry-content.single-content` (Kadence) pose déjà `margin-top: var(--global-md-spacing)` au-dessus — un padding-top propre à cette page se serait ajouté au lieu de le remplacer (double espacement).
  - **Espace garanti autour de l'image collée** (`.pf-event-hero__media-inner`) : porté sur `top`/`height`, **pas** `margin` — un sticky se pose exactement sur la valeur de `top` une fois accroché, sa propre marge ne compte plus à ce moment-là (vérifié via puppeteer : `margin-top` ignoré une fois collé). `top: calc(var(--pf-sticky-offset) + var(--pf-space-6))` donne donc un espace constant sous la nav collée **quelle que soit l'image** (contrairement au seul centrage flex, qui ne laisse du vide que si l'image ne remplit pas toute la hauteur dispo) ; `height` réduite d'autant (`calc(100vh - offset - 2×space-6)`) garde le même espace en bas avant de décrocher. Même valeur reprise en JS (`event-single-media.js`, fonction `pxVar()` — résout un token `--pf-*` en px via un élément jetable, car `getComputedStyle` sur une custom property renvoie la valeur brute non résolue, ex. `"1.5rem"`, pas des px).
- **Nav rendue seulement si ≥ 3 sections** (à toute taille d'écran, **sur les deux pages**). Helper `inc/section-nav.php` **découplé** en `pf_sectionnav_bar()` (la barre `<nav>` + primer, `''` si < 3 — liste TOUTES les sections même réparties en plusieurs conteneurs) et `pf_sectionnav_sections()` (les blocs `.pf-section`). `pf_render_sectionnav()` (fiche livre) les combine dans `.pf-body` ; la fiche événement les compose en 2 zones via `passiflore_get_event_sections_parts()` (`{nav, top, bot}`).
- **Écarts assumés vs natif** : navigation *événement précédent/suivant* **retirée** ; bloc **meta natif** (`modules/meta` : Détails / Lieu / Organisateur / carte Google) **remplacé** par nos sections (pas de catégorie/mots-clés/prix sur ce site) ; hooks `before/after_the_content` **non déclenchés** (le modèle d'extension devient la liste de sections). ⚠️ En cas de MAJ majeure de TEC, comparer le `@version` de `single-event.php`.
- **Carte** : `Passiflore_Events_Map::enqueue_single()` (constructeur, `wp_enqueue_scripts` @20) charge Leaflet + `events-map.css` (pin `.pf-map-pin`) + `assets/js/event-venue-map.js` **uniquement** si le lieu a des coordonnées en cache — aucun appel réseau au rendu (RGPD : seules les tuiles OSM sont chargées par le navigateur, comme la vue carte).
- ⚠️ **Gotcha nav sticky** : TEC (`tribe-events-single-skeleton.css`) pose `overflow:hidden` sur `.tribe-events-single > .tribe_events` (le wrapper `post_class`), ce qui **casse `position:sticky`** de `.pf-sectionnav` (le sticky se cale sur ce conteneur au lieu du viewport → la nav défile). Shim dans `event-single.css` : `.tribe-events-single > .tribe_events { overflow: visible !important; }` (même sélecteur, `!important` autorisé contre TEC ; le clip horizontal global reste assuré par `#wrapper` en `overflow:clip`). Diagnostic : puppeteer-core sur le Chrome système → parcours de la chaîne d'ancêtres en logguant `overflow`/`transform`/`contain` (les 3 casseurs de sticky).

---

### SEO — désindexation des événements passés anciens (`inc/seo-events.php`)

Rank Math n'a pas de règle « noindex par date » ; ce fichier la pose via ses filtres. Un événement **terminé depuis plus de `PF_EVENT_SEO_STALE_AGE`** (constante, `-6 months`) passe en **noindex** et sort du **sitemap**, **sauf** s'il porte le flag SCF `evenement_marquant` (alors indexé quel que soit son âge). Futur / en cours / passé récent : indexé (défaut Rank Math, non touché). Contexte : ~97 % des événements sont d'anciens passés (fiches à faible valeur SEO + mauvaise UX pour un visiteur qui y atterrit depuis Google).

- **`pf_event_seo_stale( $event_id )`** : `true` si `evenement_marquant` non coché **et** `_EventEndDate` (heure locale TEC, `Y-m-d H:i:s`) antérieur à `strtotime( PF_EVENT_SEO_STALE_AGE )`. Comparaison à la maille du mois → l'écart de fuseau (local vs UTC) est négligeable, pas de conversion.
- **Balise `<meta robots>`** : filtre `rank_math/frontend/robots` (dernier filtre robots de Rank Math, `class-paper.php`) → `$robots['index'] = 'noindex'` sur la fiche périmée. Rendu au vol, pas de cache.
- **Sitemap** : le sitemap Rank Math ne relit **pas** ce filtre robots — son SQL exclut les posts dont la post-meta `rank_math_robots` contient « noindex ». Notre noindex étant calculé au vol (non stocké en meta), un **second** filtre `rank_math/sitemap/entry` (retour `false` = URL exclue) retire les fiches périmées.
  - ⚠️ **L'objet passé est une ligne SQL brute (`stdClass`), PAS un `WP_Post`** — `Post_Type::get_posts()` fait un `$wpdb->get_results()` avec un `SELECT` de colonnes. Tester par propriété (`isset( $o->post_type, $o->ID )` + `$o->post_type === 'tribe_events'`), **jamais `instanceof WP_Post`** (échouerait silencieusement → aucune exclusion).
  - ⚠️ **Cache** (fichiers dans `uploads/` + transients) : après déploiement du code, purge obligatoire pour régénérer — `\RankMath\Sitemap\Cache::invalidate_storage()` (bootstrap WP), ou `wp rank-math sitemap generate`, ou ré-enregistrer les réglages du sitemap, ou éditer un post (invalide alors le seul cache du type concerné).
  - ⚠️ **Sous-sitemaps vides possibles** : l'index pagine sur le compteur **brut** d'événements (le filtre `entry` ne réduit pas ce compteur), donc `tribe_events-sitemapN` peut renvoyer 0 URL quand ses ~200 candidats sont tous périmés. **Sans effet** (sitemaps vides tolérés par Google, tous les événements à garder restent présents). Un filtrage au niveau SQL (`sitemap/get_posts/where` **+** `sitemap/post_count/where`, tous deux obligatoires pour ne pas perdre d'entrées) rendrait l'index exact mais dupliquerait la logique `stale` en SQL — non retenu (simplicité).
- **Type « Événements » dans le sitemap** : activé côté Rank Math **en ligne uniquement** (`pt_tribe_events_sitemap`) — **pas en local** (l'index local ne liste que pages + produits, donc le filtre sitemap y est un filet inerte). Sans ce réglage, les événements restent découvrables par le maillage interne (archive `/evenements`).
- **Portabilité** : aucune valeur d'environnement en dur (ni domaine ni slug) — uniquement `is_singular('tribe_events')`, les meta `evenement_marquant`/`_EventEndDate` et les hooks Rank Math. Rien à changer au déploiement.
- **Interaction avec le déréférencement global (site en test)** : tant que WordPress est réglé « Demander aux moteurs de ne pas indexer » (`blog_public = 0`), tout le site est noindex et ce filtre est redondant. Au repassage en public, le défaut redevient `index` et le filtre reprend seul son rôle (anciens → noindex, récents → index), sans réactivation.
- **Vérifié** (headless, site local) : périmé non marquant → `noindex, follow` ; récent → `index, follow` ; périmé **marquant** → `index` (bascule temporaire de `evenement_marquant` sur un événement périmé, puis restauration). 1282/1323 événements concernés. Sitemap (activé en local) après purge du cache : **1324 → 42 URLs**, ancien exclu / récent conservé.

**Canonical des vues d'archive TEC — slash final retiré** (même fichier, filtre `rank_math/frontend/canonical`) : sur `/evenements` (+ vues `liste`/`mois`/`carte`, mois daté, pagination), Rank Math dérive le canonical de `get_post_type_archive_link( 'tribe_events' )`, que TEC filtre (`Tribe__Events__Main::event_archive_link`) pour y **forcer un slash final**. La structure de permaliens du site étant `/%postname%` (sans slash), `redirect_canonical` renvoie `/evenements/` en **301** vers `/evenements` → le canonical pointait vers une URL qui redirige. Le filtre applique `untrailingslashit()`, gardé par `$wp_rewrite->use_trailing_slashes` (si la structure passait un jour en mode « avec slash », le filtre s'efface) et scopé aux vues d'archive (`tribe_is_event_query() && ! is_singular()`). Sans effet sur la pagination (`/evenements/page/2` reste auto-référent) ni sur les fiches événement.

**Rappel — canonical absent = page `noindex`, par conception de Rank Math** : `includes/frontend/class-head.php` retire la balise canonical dès que `robots['index'] === 'noindex'` (noindex + canonical étant contradictoires pour Google). Sur ce site, cela concerne : les 1282 événements périmés (règle ci-dessus), les archives `product_tag` (réglage `tax_product_tag_robots`), les termes vides (`noindex_empty_taxonomies`), la recherche, les 404, et **panier / commander / mon-compte** (forcés en `noindex, follow` par le module WooCommerce de Rank Math, `includes/modules/woocommerce/class-woocommerce.php::robots()`, qui débranche au passage le `wc_page_noindex` du cœur WooCommerce). Ce n'est donc **pas** un défaut à corriger. Audit complet du 2026-07-25 : 2270 URL publiques balayées, 0 canonical en double, 0 canonical non auto-référent hors vues d'archive événements.

---

### Authors → `auteur` custom taxonomy

Registered via CPT UI. SCF field group `group_69c2ca10aa3d2` ("Fiche d'auteur"):

| Field name | SCF type | Notes |
|---|---|---|
| `nom` | text | Required. Last name or organisation name |
| `prenom` | text | |
| `genre` | radio | `feminin` or `masculin` — returns label |
| `photo` | image (id) | Required |
| `biographie_synthetique` | textarea | Required |
| `biographie_complete` | wysiwyg | Required |

The `auteur` taxonomy term name, slug and description are auto-synced from SCF fields via hooks in `inc/auteurs.php`. The standard WordPress name/slug/description fields are hidden in the admin form.

**Relations de termes `auteur` sur les livres — synchronisées depuis SCF (`inc/auteurs.php`) :**

Les livres ne référencent leurs auteurs que via le repeater SCF `contributions → fiche-auteur` (IDs en postmeta), **jamais** par de vraies relations de termes WordPress → sans correctif, tous les termes `auteur` ont `count=0`. Conséquence SEO : Rank Math les traite comme « vides » et (a) **`noindex_empty_taxonomies=on` → passe les fiches auteurs en `noindex`**, (b) `tax_auteur_include_empty=off` → les **exclut du sitemap**. Les pages auteurs étaient donc désindexées ET absentes du sitemap alors qu'elles affichent des livres.

- **Correctif** : `passiflore_sync_auteur_terms()` pose la vraie relation `wp_set_object_terms( $product, …, 'auteur', false )` (mode remplacement = miroir fidèle du SCF, nettoie les orphelines) à partir de **`passiflore_get_product_author_ids()`** (`inc/author-books-grouping.php`) — exactement l'ensemble que la page auteur affiche via `passiflore_product_ids_by_auteur_terms()`. Hooks `acf/save_post` @20 (contributions déjà écrites) **+** `save_post_product` (import/quick edit).
- **Toute contribution `fiche-auteur` compte, quel que soit le rôle** : `type` (auteur, photographies, traduction, illustrations, dessins, notes, textes) est un simple *label* — une personne créditée sur un livre dans **n'importe quel** rôle est un auteur (décision actée 2026-07-24). `passiflore_get_product_author_ids()` et `passiflore_product_ids_by_auteur_terms()` ne filtrent donc **pas** sur `type` (élargi le 2026-07-24 : ces deux helpers partagés servent aussi recherche, recommandations, « ajouter par auteur » admin, page auteur — cohérent partout). Le filtrage par rôle spécifique reste possible via le param `role=` de `[passiflore_etagere]` (indépendant). Un terme `auteur` sans **aucune** contribution garde count=0 → reste noindex (correct : page vide).
- **Additif, idempotent, réversible** : ne touche pas au SCF (source de vérité), rejouable sans effet de bord.
- **Backfill** : `passiflore_backfill_auteur_terms()` (tous les livres), déclenché une fois par environnement au premier `admin_init` (flag option `pf_auteur_terms_synced`). Le compteur devient exact → Rank Math ré-indexe les fiches et les ajoute au sitemap (⚠️ purger le cache sitemap après, cf. section SEO événements). **Après une ré-importation d'auteurs/livres** : si l'import ne déclenche pas `save_post_product`, relancer la synchro (supprimer l'option `pf_auteur_terms_synced`, ou `wp eval 'passiflore_backfill_auteur_terms();'`).
- **Vérifié** (local) : backfill 229 livres → **77/80 termes count>0** (dont un contributeur au seul rôle photographe) ; page auteur `follow, noindex` → `index, follow` ; `auteur-sitemap.xml` apparaît avec **77 URLs**.

---

## Child theme structure

```
kadence-child/
├── functions.php                    — Enqueues styles conditionally; requires all inc/ files
├── style.css                        — Design system global : tokens (:root) + composants .pf-* + bases (h1-h6, inputs, shop_table) + bloc newsletter (site-wide) + neutralisation du fondu de placeholder au focus imposé par WooCommerce
├── screenshot.png                   — Theme screenshot
├── taxonomy-auteur.php              — Author single/archive page template
│
├── inc/
│   ├── a11y.php                     — Mentions d'accessibilité partagées (WCAG 3.2.5) : pf_new_window_note() (span .screen-reader-text « (nouvelle fenêtre) » à placer dans un lien target="_blank" à texte visible) / pf_new_window_label() (même mention suffixée à un aria-label : liens étirés .pf-card-link sans texte, et icônes sociales Kadence via un tampon de sortie sur l'action kadence_footer_social, faute de filtre natif sur leur aria-label). Pendant a11y de la flèche ➜ posée en CSS sur [target="_blank"] (cf. docs/design-system.md § marqueur « nouvel onglet »). ⚠️ L'espace séparateur vit DANS le span, avant la parenthèse — hors du span il appartient au flux visible et écarte la flèche du libellé
│   ├── admin.php                    — Removes color picker script
│   ├── auteurs.php                  — Author taxonomy hooks + [passiflore_auteurs] shortcode
│   ├── book-sheet.php               — Admin: removes auteur meta box from product, adds custom meta boxes
│   ├── book-single-tabs.php         — Fiche livre : sections sous le hero (nav sticky = composant partagé .pf-sectionnav via pf_render_sectionnav) ; avis lecteurs (SCF curés + avis WooCommerce + formulaire) avec anti-spam honeypot/timing ; toggle « Voir tout » inline (propre au livre)
│   ├── catalogues.php               — Catalogues PDF (repeater SCF `catalogues` sur la page Catalogue) : page d'admin (WooCommerce → Catalogues PDF, formulaire natif SCF `acf_form()`) + lecture front `passiflore_get_pdf_catalogues()`
│   ├── class-bookshelf.php          — Passiflore_Bookshelf — [passiflore_etagere] shortcode
│   ├── class-catalogue.php          — Passiflore_Catalogue — [passiflore_catalogue] shortcode (filtering, AJAX) ; panneau de filtres mobile/tablette (render_filter_panel : bottom-sheet)
│   ├── class-recherche-auteurs.php  — Passiflore_Recherche_Auteurs — [passiflore_recherche_auteurs] shortcode (AJAX search)
│   ├── book-groups-admin.php        — Produits → Groupes de livres : taxonomies pf_serie/pf_traduction + _pf_vous_aimerez ; page à 3 onglets, AJAX, sauvegardes
│   ├── event-admin.php              — Custom meta box for event books (_pf_event_books): replaces SCF field, AJAX search + add-by-author, drag reorder
│   ├── venue-admin.php              — Champs Département/Région sur la fiche lieu (tribe_venue) : listes officielles + combobox à choix contraint + validation + indexation recherche
│   ├── events.php                   — The Events Calendar: translation fixes, layout customizations, book rendering
│   ├── class-events-feed.php        — Passiflore_Events_Feed — vue liste TEC : scroll infini bidirectionnel (endpoint AJAX pf_events_feed via View::make_for_rest + extraction des <li>)
│   ├── class-events-search.php      — Passiflore_Events_Search — barre de recherche /evenements (vue liste) : moteur pf_search_events_ranked() partagé, endpoint AJAX pf_events_search, rendu de ligne sans passer par le pipeline Vue TEC
│   ├── class-events-map.php         — Passiflore_Events_Map + Passiflore_Map_View — vue Carte (3e onglet TEC V2) : Leaflet + OSM ; géocodage Nominatim côté écriture (save_post + backfill WP-Cron), cache post meta _pf_venue_lat/lng/geo_src ; recherche carte (render_search_bar + endpoint AJAX pf_events_map_search → IDs via pf_search_events_ranked) ; + mini-carte mono-lieu de la fiche événement (render_single_venue_map + enqueue_single)
│   ├── event-single.php             — Fiche événement (single) : passiflore_get_event_sections_parts() → {nav, top(Horaires/Lieu/Organisateur), bot(Présence/Livres)} pour le layout 2 zones ; méta hero (site événement, agenda). Squelette = override tribe-events/single-event.php
│   ├── seo-events.php               — SEO Rank Math : noindex + exclusion sitemap des événements passés > 6 mois (sauf evenement_marquant) ; filtres rank_math/frontend/robots + rank_math/sitemap/entry ; pf_event_seo_stale() ; constante PF_EVENT_SEO_STALE_AGE. + canonical des vues d'archive TEC sans slash final (rank_math/frontend/canonical)
│   ├── section-nav.php              — Composant PARTAGÉ (fiche livre + fiche événement), DÉCOUPLÉ : pf_sectionnav_bar() (barre <nav> + primer, ≥3 sections) / pf_sectionnav_sections() (blocs .pf-section) / pf_render_sectionnav() (livre : combine dans .pf-body). + body.no-anchor-scroll
│   ├── header-hooks.php             — Header customizations; [passiflore_account_btn] shortcode
│   ├── modifier-produit.php         — Product edit screen: format_groupe single-term constraint. Product list screen: hides native « Trier » button + default sort by date_de_parution DESC then title ASC
│   ├── numerique-offer.php          — Offre « version numérique en promo » à l'achat d'un livre papier : option globale pf_numerique_offer + admin (Boutique WooCommerce → Offre version numérique, entre Clients et Codes promo), helpers (pf_numerique_offer_for), ajout compagnon (woocommerce_add_to_cart + cart meta pf_numerique_companion), prix (before_calculate_totals), endpoints AJAX encart panier, rendu case fiche, prix barré mini-panier Kadence, numérique vendu à l'unité (sold_individually)
│   ├── newsletter.php               — Bloc d'abonnement site-wide + endpoints AJAX. Rendu via `kadence_top_footer` (prio 5) → À L'INTÉRIEUR de `<footer role="contentinfo">` ; PAS `kadence_before_footer`, qui le plaçait entre `</main>` et `<footer>`, donc dans aucun repère de navigation
│   ├── account-auth.php             — Connexion / création de compte : URL dédiées /connexion et /creer-un-compte (règle de réécriture vers le page_id de « Mon compte » + query var pf_auth, redirect_canonical neutralisé, flush auto-cicatrisant pf_auth_rw_version, garde-fous connecté/404) + enqueue de account-auth.js (page compte, déconnecté)
│   └── pageflip.php                 — Enqueues pageflip assets on single product pages
│
├── assets/
│   ├── css/
│   │   ├── account.css              — Mon compte (nav, panneau unique connexion/création de compte, zone suppression)
│   │   ├── auteur-single.css        — Single author page
│   │   ├── auteurs.css              — Authors archive page (also used on events pages)
│   │   ├── book-single.css          — Single book page sections (nav sticky, avis, formulaire d'avis)
│   │   ├── bookshelf.css            — 3D bookshelf
│   │   ├── cart.css                 — Page panier (table, totaux, actions secondaires)
│   │   ├── catalogue.css            — Catalogue page + filters (dont panneau de filtres mobile/tablette <1024px : bar réduite à 2 rangées + bottom-sheet)
│   │   ├── checkout.css             — Page commande (récap, paiement, confirmation)
│   │   ├── events.css               — Events Calendar pages
│   │   ├── events-infinite.css      — Vue liste : scroll infini (bouton rond passés au clic, loaders, top-bar masqué)
│   │   ├── events-map.css           — Vue carte : conteneur Leaflet, pin DivIcon rouge, infobulles (tokens --pf-*)
│   │   ├── event-single.css         — Fiche événement (single) : layout 2 zones (grille [nav | contenu | image]) — zone haute head/body/topsec + image collée (media-inner sticky borné), zone basse Présence/Livres pleine largeur ; nav longe les deux ; colonne image = --pf-event-media-col (JS). Sections Lieu (carte OSM)/Organisateur
│   │   ├── mes-avis.css             — Endpoint compte « Mes avis » (cartes, statuts)
│   │   ├── pageflip.css             — Book page flip viewer
│   │   ├── reading-list.css         — Bouton « liste de lecture » (fiche livre + compte)
│   │   ├── recherche-auteurs.css    — Author search widget
│   │   └── recherche-globale.css    — Recherche globale du header (overlay, résultats)
│   └── js/
│       ├── bookshelf.js             — Wheel-to-horizontal scroll (scroll mode)
│       ├── book-picker.js           — Composant admin réutilisable (window.pfBookPicker) : recherche AJAX + ajout par auteur + drag reorder, partagé par event-admin.js et book-groups-admin.js
│       ├── book-groups-admin.js     — Admin JS « Groupes de livres » (instancie pfBookPicker + sélecteur de livre source)
│       ├── catalogue.js             — Catalogue filter + AJAX ; mobile/tablette : relogement des contrôles tri/filtres dans le bottom-sheet « Filtres » (gate .pf-cat-js, panneau rendu hors du sticky mais dans .pf-catalogue → root.querySelector inchangé), badge de filtres actifs
│       ├── scroll-fade.js           — Composant global .pf-scroll-fade (style.css) : bascule .is-scroll-left/-right sur tout conteneur à scroll horizontal (tuiles événements/auteurs, nav de section mobile fiche livre)
│       ├── mobile-nav.js            — Tiroir mobile (site-wide) : déplie la chaîne de la catégorie produit courante (Catalogue → catégorie → sous-catégorie) au chargement en posant .show-drawer/aria-expanded ; corrige l'auto-expand Kadence, mis en échec par le « Catalogue » de 1er niveau marqué current-menu-item
│       ├── event-admin.js           — Admin JS for event books meta box (thin caller de pfBookPicker)
│       ├── venue-admin.js           — Combobox à choix contraint (Département/Région, fiche lieu) : liste préchargée, filtrée au focus/à la frappe, réutilise la normalisation de book-picker.js
│       ├── events-infinite.js       — Vue liste : scroll infini bidirectionnel (IO bas auto, bouton passés au clic uniquement en haut, ancrage scroll, dé-dup mois ; contrôleur ré-initialisable sur bascule de vue AJAX)
│       ├── events-month.js          — Vues liste+mois : header sticky « smart-hide » + --pf-ev-header-offset (décale le top des séparateurs ; re-query pour survivre à l'AJAX TEC)
│       ├── events-search.js         — Barre de recherche /evenements (vue liste) : masque la liste à scroll infini sans la détruire pendant la recherche, restaure sa position de scroll à la fermeture ; contrôleur ré-initialisable sur bascule de vue AJAX
│       ├── subscribe-calendar.js    — Bouton « S'abonner » (vues d'archive) : relabellé en action directe vers le calendrier détecté (Apple/Google), pill scindé en 2 (.pf-splitbtn, style.css — motif de la paire catalogue) avec kebab pour les 5 autres options (menu natif TEC inchangé) ; amélioration progressive, contrôleur ré-initialisable sur bascule de vue AJAX
│       ├── section-nav.js           — Composant PARTAGÉ (fiche livre + fiche événement) : nav sticky + scrollspy (anchor-pin, IntersectionObserver .is-active, visibilité/hauteur nav mobile), keyé .pf-sectionnav/.pf-section, inerte si absent ; URL de section posée en history.replaceState (jamais pushState) → naviguer par ancre n'empile PAS d'historique, « retour » ramène à la page précédente
│       ├── event-venue-map.js       — Fiche événement : mini-carte Leaflet mono-lieu (section Lieu), coords lues sur #pf-event-venue-map[data-lat/lng], même pin .pf-map-pin que la vue carte
│       ├── event-single-media.js    — Fiche événement (desktop ≥1024px) : largeur de la colonne de l'image collée = min(40%·conteneur, hauteurDispo·ratio, largeurNaturelle) → --pf-event-media-col ; inerte ≤1023px
│       ├── pageflip.js              — Book page flip viewer
│       ├── pf-tooltip.js            — Composant PARTAGÉ infobulle .pf-numerique-tip (window.pfTooltip.wire) : tap/clavier + recalage horizontal dans .site-container ; utilisé fiche livre + encart panier
│       ├── pf-toast.js              — Composant PARTAGÉ toast (window.pfToast) : file en bas à droite, opts icon/duration/actions/onClose(reason)/closeLabel, pause au survol/focus, barre de progression ; DOM 100 % JS (aucun HTML serveur). `actions` accepte {label, href} → vrai <a> qui NE ferme PAS le toast (ctrl-clic préservé), ou {label, onClick} → bouton. Utilisé par la liste de lecture et par pf-session-toast.js
│       ├── shelf-bookmarks.js       — Contrôleur UNIQUE de la « liste de lecture », pour ses deux affordances : signets sur couverture (.pf-book-bookmark, pages du compte) ET bouton du hero de la fiche livre (.bs-hero__readlist). setIcon() tient les deux vues en phase ; ajout = commit immédiat, retrait = différé à la fin du toast (« Annuler ») ; invité = toast d'invitation à se connecter (7 s, un seul à la fois) ; écritures sérialisées, flush sendBeacon au pagehide
│       ├── pf-session-toast.js      — Composant PARTAGÉ « session expirée » (window.pfSessionExpired), au-dessus de pf-toast : rattrape un 403 de nonce sur les endpoints AJAX à état. Deux modes — 'reload' (compte à rebours + rechargement, « Annuler » l'annule ; actions pures : panier, suppressions) et 'confirm' (reste affiché, bouton « Actualiser », JAMAIS d'auto-reload ; contextes avec saisie ou navigation : avis, newsletter, liste de lecture, signets). Idempotent. Enregistré dans functions.php, tiré par dépendance de script
│       ├── numerique-offer.js       — Fiche livre : case « ajouter la version numérique » → attribut data-pf_add_numerique sur le bouton d'ajout (transmis par le JS cœur WooCommerce) ; câble l'infobulle (pfTooltip.wire, preventClick)
│       ├── numerique-cart-nudge.js  — Panier (blocs) : encart de rappel « ajoutez la version numérique » (endpoints pf_numerique_cart_offers / pf_numerique_add_companion, ré-évalué sur wc/store/cart) ; chaque ligne porte l'infobulle (pfTooltip.wire)
│       ├── account-auth.js          — Page compte (déconnecté) : bascule connexion ↔ création de compte sans rechargement + history.replaceState (l'URL doit porter l'état, cf. section dédiée) ; mesure --pf-auth-top (hauteur plein écran, non exprimable en CSS). Amélioration progressive pure
│       └── recherche-auteurs.js     — Author search AJAX
│
├── redirections/
│   ├── auteurs.csv                  — 74 PrestaShop → WordPress author URL mappings
│   └── redirections_actualites.csv  — PrestaShop → WordPress news URL mappings
│
├── tribe/                           — The Events Calendar template overrides
│   └── events/v2/
│       ├── components/header.php     — Vues liste+mois+carte : header sticky « smart-hide » restructuré (top-bar + recherche événements — liste OU carte — à gauche, S'abonner+sélecteur droite) ; autres vues inchangées
│       ├── components/ical-link.php  — Vues liste+mois : « S'abonner » rendu une seule fois (dans le header), supprimé en bas de page
│       ├── components/events-bar/views.php  — Sélecteur de vue → switch Liste/Mois (pf-view-switch)
│       ├── day/event/description.php
│       ├── list/event.php            — Vue liste : copie du cœur + classe `pf-card` + lien étiré `.pf-card-link` (carte cliquable)
│       ├── list/event/date-tag.php   — Vue liste : date (jour complet + heures + planning par jour)
│       ├── list/event/title.php      — Vue liste : titre non-lien + classe `pf-card-title`
│       ├── list/event/description.php — Vue liste : excerpt (a11y-hidden) + participants en texte simple (mode 'text')
│       └── list/event/featured-image.php — Vue liste : copie fidèle du core (@6.14.2) + SEUL AJOUT `sizes` (perf : sans lui, srcset défaut 100vw → plein format pour une image ≤360px)
│
├── tribe-events/                    — TEC override CLASSIQUE (chemin distinct de tribe/events/v2/)
│   └── single-event.php             — Fiche événement (single) : hero + sections via pf_render_sectionnav (inc/event-single.php) ; prev/next retirés, bloc meta natif (modules/meta) remplacé par nos sections
│
└── woocommerce/
    ├── archive-product.php          — Overrides product archive to render [passiflore_catalogue]
    ├── content-single-product.php   — Single book page hero: pageflip viewer (cover + PDF), title, subtitle, price, authors
    │                                  (+ case « offre version numérique » après le prix, via inc/numerique-offer.php ;
    │                                   sections sous le hero — résumé, caractéristiques, auteurs, presse, vidéos, podcasts,
    │                                   avis, événements, livres associés — rendues par inc/book-single-tabs.php)
    ├── cart/
    │   └── mini-cart.php            — Mini-panier dropdown header (calqué sur le cœur WC 10.0.0) : <li> par article
    │                                   en petite .pf-card cliquable (lien étiré vers la fiche livre, titre pf-card-title)
    └── myaccount/
        ├── dashboard.php
        ├── form-edit-account.php
        ├── form-login.php           — Connexion / création de compte (calqué sur le cœur WC 9.9.0) : UN panneau à la
        │                              fois (connexion par défaut), titres en <h1>, libellés en placeholder, formulaire
        │                              en .pf-panel ; état porté par ?action=register (cf. inc/account-auth.php)
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
| `decouvrir` | — | `nouveautes`, `prix-litteraires`, `a-paraitre` |
| `disponibilite` | — | Slug SCF (`disponible`, `a-paraitre`, …) |
| `public` | — | Slug SCF (`tout-public`, `adulte`, `jeunesse`) |
| `type` | — | Slug SCF (`roman`, `nouvelles`, …) |
| `reliure` | — | Slug SCF (`broche`, `cousu`) |
| `langues` | — | Slugs SCF séparés par virgule |
| `auteur` | — | Slug(s) ou ID(s) du terme `auteur` — filtre sur les contributions |
| `role` | — | Types de contribution à restreindre avec `auteur` (`auteur`, `traduction`, …) |
| `nb_books_first_displayed` | `12` | Nombre de livres visibles au chargement (le reste est lazy-loaded) |

---

## Bookshelf feature (`Passiflore_Bookshelf`)

A 3D book-on-shelf display system.

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

**Calcul de l'épaisseur du dos :**
1. Si la photo de dos existe : largeur de l'image en mm (calculée depuis ses proportions)
2. Sinon si `nombre_de_pages` renseigné : `spine_mm = pages × 0.08`
3. Sinon : `spine_mm = MIN_SPINE_MM (10mm)`
4. Clamp : `max(10, min(60, spine_mm))`
5. Conversion : `spine_px = round(spine_mm × SCALE × mode_scale)` (mode_scale = 1.5 en mode `spines`, 1.0 en mode `covers`)

**Key CSS variables:**
- `--book-h`, `--book-w`, `--spine-w` — set inline per book
- `--shelf-inner` — set inline per shelf
- `--plank-color-top: #d4c4a8`, `--plank-color-front: #c0ad8e`, `--plank-color-dark: #a89474`
- `--wall-color: #f5f0e8`, `--frame-color: #e0d8cc`
- `--pf-obl` (`0.3249`, `0.2309` sous 768px) — **angle de fuite partagé par les deux modes**, voir ci-dessous
- `--pf-backcover` (`4px`) — épaisseur des plats, voir « Quatrième de couverture » plus bas

**Projection — les deux modes partagent une seule caméra (`--pf-obl`)**

Le mode **covers** est une projection **cavalière** : reculer d'une profondeur `d` décale de `(-d, -[--pf-obl]·d)` à l'écran. D'où le `skewY(18deg)` du dos et le parallélogramme du bandeau de pages (`tan 18° = 0,3249` ; `13°`/`0,2309` sous 768px). Anatomie 2D pure, aucun coût 3D — important, le catalogue en affiche ~300.

⚠️ `--pf-obl` et le `skewY` sont **la même grandeur écrite deux fois** (l'un est la tangente de l'autre) : les changer ensemble, aux deux points de rupture. L'angle est passé de 14°/10° à 18°/13° le 2026-07-25 — vue légèrement plus haute, donc bandeau de pages ~30 % plus épais, ce qui laisse la place au plat arrière (ci-dessous) sur les livres minces. Marge disponible vérifiée : `.pf-shelf-books` a 40 px de `padding-top` et le bandeau du livre le plus épais du catalogue (dos 41 px) en occupe 13,3.

Le mode **spines** est un vrai volume 3D (dos, couverture, face supérieure = les faces d'un même solide, `.pf-book-inner` en `preserve-3d`). La face supérieure `.pf-book-pages` est un plan horizontal (`rotateX(-90deg)`) : elle suit donc la rotation **par construction**, il n'y a rien à resynchroniser.

Le raccord entre les deux se fait par une **caméra mobile** (`perspective-origin` animée sur `.pf-book`, en phase avec la rotation) :
- **au repos** : `50% 0%` — l'œil est pile à hauteur du dessus du livre, la face supérieure est rasante donc invisible ; l'étagère au repos est strictement inchangée ;
- **livre posé** : l'œil monte et se décale à gauche jusqu'à `calc(--reveal-dx - (--persp + --spine-w))`, `calc(--reveal-dy - 0.1·--book-h - --pf-obl·(--persp + --spine-w))`.

Cette formule est **exacte, pas approchée** : un point à la profondeur `p` se projette avec le décalage `(px - x0)·p/(persp + p)` ; en posant `px = x0 - (persp + --spine-w)`, le décalage à `p = --spine-w` vaut exactement `-(--spine-w)`. Le livre posé retrouve donc exactement la **construction** d'un livre covers — même angle de fuite, même rapport bandeau/épaisseur (vérifié : 0,325 des deux côtés pour un `--pf-obl` de 0,3249, et 0,176 sous 768px avec l'ancienne valeur). ⚠️ **Construction, pas superposition** : les deux rendus n'ont ni la même taille ni le même habillage, cf. « Ce que les deux modes ne partagent pas » plus bas. Les termes `--reveal-dx`/`--reveal-dy` (recentrage JS) et `-0,1·--book-h` (le `scale(1.1)`, d'origine `left bottom`, remonte le haut du livre) repèrent le coin haut-gauche du volume posé — sans eux le bandeau perd ~10 % d'épaisseur.

**Ce que les deux modes ne partagent pas** (mesuré sur un même livre, le 2026-07-27)

Un livre saisi en spines n'est **pas** un livre covers au repos : seule la *construction* est commune. Écarts, du plus structurant au plus anecdotique :

| | covers (repos) | spines (dévoilé) |
|---|---|---|
| `--book-h` / `--cover-w` / `--spine-w` | 286 / 190 / 41 | 428 / 284 / 61 |
| couverture rendue | 190 × 286 | 312 × 471 |
| bandeau de pages | 13,31 px | 19,82 px |
| **bandeau / épaisseur** | **0,325** | **0,325** |
| dos : arête arrière / arête avant | 1,000 | 0,952 |
| image servie | `pf-shelf-cover` (398×600) | `medium_large` (768×1157) |
| ombre de volume (`.pf-book-inner`) | `-11px -11px 8px` | aucune |
| ombre du dos | `-1px 0 3px` | `inset -2px 0 4px` + `1px 1px 4px` |
| fond de `.pf-book-inside` | crème `--pf-cream-dark` | blanc |

- **Facteur 1,65 en taille** = `mode_scale` **1,5** (le mode spines dessine tout plus grand pour que les titres verticaux des dos restent lisibles, cf. `prepare_books()`) × **1,1** (le `scale` de la saisie). C'est de loin l'écart dominant, et il est **voulu** : les deux modes ne servent pas le même usage.
- **La couverture est donc servie en `medium_large`** en spines (et non en `pf-shelf-cover`), sinon l'agrandissement serait flou — sans coût, l'image n'étant chargée qu'au survol réel.
- **Seule la ligne en gras est un invariant** : le rapport bandeau/épaisseur vaut `--pf-obl` dans les deux modes, c'est ce que la caméra mobile garantit. Tout le reste diverge.
- **La projection, elle, n'est pas du même type** : covers est parallèle (cavalière → arêtes du dos strictement parallèles, pentes **-0,3249 en haut ET en bas**), spines est une vraie perspective (fuite derrière). D'où un **écart de pente haut/bas du dos** propre à spines, réglé par `--persp` : 0,3725 à 1200px — l'arête basse filait deux fois plus raide que la haute, visible à l'œil —, 0,1534 à 3000, **0,0775 à 6000 (valeur retenue le 2026-07-27)**, 0,0389 à 12000. L'arête HAUTE, elle, vaut `--pf-obl` quelle que soit la valeur : la formule de caméra la fixe (terme `-0,1·--book-h`), pas l'arête basse.
  - **`--persp` n'a aucun effet sur l'atterrissage** (les termes `--persp + --spine-w` s'annulent) : épaisseur du bandeau 19,82 px et largeur de dos projetée ~60 px identiques de 1200 à 40000, et étagère au repos **pixel pour pixel identique** (0 pixel d'écart). Il ne coûte que la profondeur ressentie pendant les ~150 premières ms de la saisie (rapprochement des bords de la couverture en rotation : 0,876 à 1200px → 0,973 à 6000) ; au-delà l'easing a fini de tourner le livre.
  - ⚠️ **On ne peut pas atteindre le parallèle exact en spines** : `perspective: none` ne rendrait pas la projection cavalière, il ferait *disparaître* le bandeau de pages — la face supérieure est un plan horizontal qui n'existe à l'écran que par la perspective. On ne peut que s'en approcher en éloignant l'œil.
- Les trois écarts d'habillage (ombre de volume, ombre du dos, fond intérieur) sont des **oublis**, pas des décisions : les règles correspondantes ont été écrites scopées `--covers` ou `--spines` sans que l'autre mode soit repassé dessus. Sans conséquence tant qu'on ne compare pas les deux côte à côte.

⚠️ **Ne pas réintroduire de « déco » plate.** Une réplique 2D de l'anatomie covers (`.pf-book-held-deco` / `.pf-spine-ghost` / `.pf-pages-ghost`, supprimée) ne peut pas suivre la perspective en vol : l'arête haute du volume plonge jusqu'à ~45 px sous un bandeau plat pour un livre de 306 px. Écart mesuré pendant le vol : **1,4 px** (antialiasing) avec le volume 3D, contre 7 à 25 px avec la déco plate. La suppression retire aussi 3 nœuds DOM par livre (10 → 7) et l'animation de `clip-path` : **0 layout par cycle contre 6**, à 60 fps dans les deux cas.

⚠️ En mode spines, `.pf-book-inside` ne doit **pas** être reculé en profondeur pour éviter le z-fighting (l'ancien `translateZ(-1.5px)`) : l'œil étant déporté à gauche, ce retrait se projette latéralement et laisse un liseré blanc le long du dos. Arbitrer par `z-index` (cover 2 / inside 1).

⚠️ **L'ouverture de couverture au curseur ne peut pas vivre dans l'espace 3D du volume.** La caméra y étant déportée de ~45°, la profondeur prise par la couverture qui bascule se traduit en décalage latéral : mesuré **+53 % de largeur à −25°**, la couverture s'étirait vers la droite au lieu de pivoter. D'où `.pf-cover-leaf` (émis par le PHP en spines non-héros non-liseuse) : `.pf-book-cover` reste la face rigide du volume et devient l'hôte d'une **scène 3D locale** (`perspective` propre + `transform-style` plat par défaut → le pivot est aplati dans le plan de la face avant que la caméra du volume n'intervienne). Perspective proportionnelle (`calc(var(--cover-w-base) * 5.2)`, ratio repris du 1500/288 de covers) plutôt que 1500px fixe, le livre saisi étant rendu plus grand qu'en covers. Vérifié : largeur à −25° = **0,942×** contre **0,943×** en mode covers.

**Quatrième de couverture — le plat arrière peint sur l'arête du fond du bandeau**

Le bandeau de pages ne montrait que du papier : le bloc semblait flotter sans reliure. On y peint donc le **plat arrière**, à la couleur dominante de la couverture (la même que celle des dos générés, `--cover-color` posée en inline sur chaque `.pf-book`), assombrie de 15 % — le carton est dans l'ombre du livre. C'est une **bordure de `.pf-book-pages`**, donc immobile par rapport au bandeau et sans nœud DOM supplémentaire.

- **Même arête physique dans les deux modes, deux côtés différents.** En covers le bandeau est un parallélogramme 2D et le fond du livre est son **arête haute** → `border-top`, du coin haut-gauche du dos au coin haut-droit du bandeau, parallèle au bord haut de la couverture. En spines c'est un plan couché dans l'espace, dont la largeur locale est l'épaisseur du livre (la couverture est charnée à droite, en `x = --spine-w`) → le fond est à **gauche**, `border-left`. Une fois le livre saisi et la caméra en place, ce bord se projette exactement là où tombe le `border-top` de covers.
- **D'où deux écritures de la même épaisseur** : `calc(var(--pf-backcover) * var(--pf-obl))` en covers (une profondeur projetée) contre `var(--pf-backcover)` brut en spines (une profondeur dans le plan).
- **`box-sizing: border-box` obligatoire** : le carton fait partie de l'épaisseur du livre, il ne s'y ajoute pas. Sans lui le bandeau grossirait de la valeur du plat.
- **`--pf-backcover: 4px`** = 3,3 mm à l'échelle du site, soit un carton de reliure plutôt qu'une couverture souple. Exagération assumée : au vrai ratio (0,3 mm) le plat se projetterait sur 0,1 px. Une **profondeur constante** (et non un pourcentage du dos) est le bon modèle — un carton ne s'épaissit pas avec le nombre de pages —, et la projection étant linéaire elle donne bien un trait d'épaisseur constante. À l'écran : **1 px** partout (Chrome rabote la largeur de bordure au pixel-appareil, et la **relève à 1 px minimum** — vérifié à 768px où la valeur calculée tombe à 0,92 px, donc le trait ne disparaît jamais).
- ⚠️ **`--pf-backcover-ink` est déclarée sur `.pf-book-pages`, pas sur `.pf-bookshelf`** : la valeur d'une custom property est substituée sur l'élément où elle est **déclarée**. Écrite sur le conteneur, le `var(--cover-color)` imbriqué se résoudrait contre le conteneur — qui ne la porte pas — et jamais contre le livre.
- **Dos à angles vifs, bandeau calé dessus au pixel** (2026-07-25) : les `border-radius` des dos ont été retirés — `2px 0 0 2px` en covers, `2px` en spines (la liseuse garde ses 4px, c'est un appareil, pas un livre). En conséquence `.pf-book-pages` épouse désormais **exactement** le livre (`left: 0`, largeur `--cover-w + --spine-w`) : les quatre sommets du `clip-path` tombent sur des points réels du volume. La boîte était auparavant rentrée de 1px à gauche pour se cacher derrière l'arrondi ; sans arrondi, ce décalage laissait un **liseré blanc diagonal d'1px sur toute l'arête haute du dos** (vu au zoom 24×, corrigé). Vérifié sur les 165 livres, aux deux points de rupture : écart nul entre le bord gauche du bandeau et celui du dos, entre son extrémité droite et le bord de la couverture, et entre son bas et le haut de la couverture.
- **Boîtier CD** : `border-color` ramené au plastique sombre — le dos d'un boîtier cristal n'est pas un plat cartonné.
- **Livres très minces** : le catalogue descend à un dos de 4 px (bandeau de 1,3 px), où le trait de 1 px occupe l'essentiel du bandeau. Laissé tel quel : à cette taille le bandeau est déjà un cheveu, et un opuscule de 3 mm est effectivement surtout de la couverture. Plafonner le plat à un pourcentage du dos ne changerait rien à l'écran (la bordure retomberait sur le même pixel unique).
- **Non-régression** : étagère spines **au repos** pixel pour pixel identique (0 pixel d'écart > 3/255) — au repos la caméra est rasante, la face supérieure reste invisible.

**Dos fictifs (pas d'image de `tranche`) — couleur, encre, logo, titre**

> ⚠️ Vocabulaire : le champ SCF s'appelle `tranche` mais désigne le **dos**. La vraie tranche (de tête) est la face supérieure, `.pf-book-pages`.

- **Couleur = dominante de la couverture**, calculée par `dominant_color()` : réduction en 64×64 puis **histogramme quantifié** sur 4 bits/canal (4096 casiers — sans quantification deux pixels n'ont presque jamais la même valeur et le comptage ne veut rien dire). Casier gagnant = max de `fréquence × (0,25 + saturation)`, en écartant les casiers quasi blancs (papier, L > 0,93) et quasi noirs (encre, L < 0,07). Repli en cascade sur `PASSIFLORE_RED`.
  - **Pourquoi pas la moyenne** (ancien resize 1×1) : elle régresse vers un gris-brun dès que la couverture est contrastée → **39 %** des dos avaient un titre blanc illisible. **Pourquoi pas un pixel du coin ni la moyenne du bord** : c'est souvent le blanc de la marge → 5 à 6 dos quasi blancs sur 33 (testé).
- **Extraite pour TOUTES les couvertures**, pas seulement les livres sans image de dos : le plat arrière du bandeau de pages (ci-dessous) en a besoin, et ce bandeau existe quel que soit le dos. Seule exception, les liseuses (pas de bloc pages, pas de couverture cartonnée). 165 couvertures distinctes sur ce catalogue contre 33 dos générés.
- **Cache** : post-meta `_pf_cover_color` sur la **pièce jointe** (donc mutualisée entre éditions partageant une couverture), calculée une seule fois à vie. **Changer l'algorithme = incrémenter `COLOR_ALGO_VER`** : `maybe_purge_cover_colors()` (hook `admin_init`, option `pf_cover_color_algo`) purge alors la meta une fois par environnement, cache objet compris. Rien à lancer à la main au déploiement — idiome maison, cf. `pf_auteur_terms_synced`.
  - **Préchauffage par lots** (`maybe_warm_cover_colors()`, `admin_init` prio 11, option `pf_cover_color_warm`, `COLOR_WARM_BATCH = 15`) : l'extraction coûte **~30 ms par couverture** (mesuré), soit **~3,8 s en local** si le premier rendu du catalogue devait les calculer toutes d'un bloc — et l'hébergement de prod est nettement plus lent. On draine donc 15 images par écran d'admin jusqu'à épuisement, puis l'option coupe court (une seule lecture ensuite). Le rendu reste capable de calculer à la volée : c'est une avance de phase, jamais une dépendance. La purge de version efface aussi ce drapeau.
- **Encre selon le fond, pas de correction de la couleur** : `spine_is_light()` teste le contraste WCAG du **blanc** sur le fond ; sous 4,5:1 le dos reçoit `.pf-spine-generated--light` → titre et logo en `--pf-heading`, ombre portée inversée. On garde la couleur exacte de la couverture (un livre pâle a un dos pâle, comme en vrai) plutôt que de l'assombrir pour sauver le texte blanc.
- **Logo** : c'est **le même macaron dans les deux cas**, seul le coloris change. Dos sombre → `assets/img/macaron_logo_blanc-96.png` (version blanche ; l'original fait 520 px pour un affichage à 24 px, d'où la copie 96 px générée — ce n'est **pas** un média WordPress, donc aucune sous-taille automatique, il faut la régénérer à la main si l'original change). Dos clair → site icon (rayons noirs + fleur rouge). Les deux sont **détourés** (fond transparent) ; le `img.pf-spine-icon { border-radius: 50% }` est donc sans effet visible, hérité d'un éventuel site icon carré.
  - ⚠️ Piège d'analyse : ouvrir ces PNG avec `PIL.Image.convert('RGB')` composite la transparence sur du **noir** et donne l'illusion d'un carré noir opaque. Vérifier le canal alpha (`im.mode`, coins) avant de conclure quoi que ce soit sur ces images.
- **Titre** (`spine_title_html()`) : le **suffixe entre parenthèses est retiré**. Sur ce catalogue il vaut toujours `(grands caractères)`, `(numérique)` ou `(deuxième édition)` — un marqueur de format, jamais du titre, et qu'aucun imprimeur ne mettrait sur un dos. C'était aussi la cause des débordements : les 3 seuls titres dépassant 42 caractères le devaient entièrement à ce suffixe.
- **Composition : auteurs en haut, titre au milieu, logo en bas** — trois enfants flex en `space-between`, donc le titre se centre tout seul. L'élément `.pf-spine-authors` est **émis même vide** pour que ce centrage tienne aussi sur les livres sans auteur. Les liseuses n'ont pas d'auteurs (un chant d'appareil n'est pas un dos de livre).
- **Auteurs** (`spine_meta_for()`) : **toutes** les contributions, dans l'ordre du repeater SCF, via `passiflore_get_product_author_ids()`. Format « A & B », « A, B & C ». Patronyme et prénom viennent des term meta `nom` / `prenom` de la fiche auteur (pas d'un découpage du nom du terme, qui casserait sur « Le Déodic » ou « Saüquère-Hubert »).
  - **Le prénom n'est conservé que pour un auteur SEUL.** À partir de deux, on passe directement aux patronymes : mesuré sur le catalogue, la forme complète fait 30 à 52 caractères contre 15 à 28 pour les patronymes — toujours au moins le double, et souvent plus longue que le titre lui-même (« Ironda à colorier » : 17 caractères de titre pour 30 d'auteurs). Les prénoms composés français (« Jean-Michel », « Marie-Laure ») y sont pour beaucoup. C'est aussi l'usage typographique sur un dos à plusieurs auteurs.
- **Polices** (`spine_meta_for()` → classe `.pf-spine-generated--serif`) : titre en **Newsreader 700** (serif) sous « Littérature », en **Inter 700** sous « Culture Sud-Ouest » ; auteurs toujours en **Inter 400**. Résolution **par livre** (on remonte l'arbre `product_cat` jusqu'à la racine), pas par étagère — `resolve_category_theme()` reste, lui, une propriété de l'étagère entière. ⚠️ Kadence ne charge **Newsreader qu'en 700** : ne pas demander d'autre graisse, elle serait synthétisée. Les deux familles sont déjà chargées, rien à ajouter.
- **Amorçage en lot obligatoire** : `spine_meta_for()` fait 1 requête pour l'arbre `product_cat`, 1 pour les catégories des livres, 1 `_prime_term_caches()` pour les termes auteur **et leurs métas**. Sans ça on retomberait sur le N+1 déjà corrigé pour les pièces jointes.
- **Corps du texte** (`spine_layout()`) — contraint par les **deux** dimensions, par pas de 0,5 px :
  - hauteur : hauteur interne (padding 8+6 déduit) **moins la place du logo** moins une respiration, ÷ le coût du texte. Coût = caractères × em/caractère, les auteurs comptant `SPINE_AUTHOR_RATIO` (0,85) de moins. Les em/caractère sont **mesurés au canvas** sur un corpus de vrais titres et noms : Newsreader 700 = 0,480, Inter 700 = 0,513, Inter 400 = 0,497 (constantes `SPINE_EM_*`, letter-spacing 0,02em inclus).
  - largeur : (`spine_w_px` − 4 px de padding) ÷ 1,15 (le `line-height`) — ce qui fait grossir le texte sur les livres épais et le contient sur les minces.
  - **Échelle de repli** quand tout ne rentre pas, le titre étant l'identifiant principal donc toujours protégé : `Prénom Nom` (**auteur seul uniquement**, cf. ci-dessus) → **patronymes** → `Nom & al.` (2 auteurs et plus) → **titre seul**. On descend d'un cran tant que le corps calculé passe sous `SPINE_FONT_OK` (9 px, seuil de *confort*) ; `SPINE_FONT_MIN` (7) n'est que le plancher absolu. Sur un dos très mince c'est la largeur qui plafonne et aucune forme d'auteur n'y changerait rien → pas de dégradation dans ce cas. Filet ultime : `overflow:hidden` + `text-overflow:ellipsis`, et le logo est `flex-shrink: 0` donc jamais chassé.
  - Simple arithmétique, donc calculé au rendu **sans cache** (contrairement à la couleur, qui fait du traitement d'image). Vérifié sur les 33 dos générés : 27 avec auteurs, 20 serif / 13 sans, **0 débordement, 0 troncature, logo jamais écrasé**.

**Chargement des couvertures — `decode()` obligatoire** (`bookshelf.js`, `startCoverLoad`). L'événement `load` ne signifie que « octets reçus » : le décodage en bitmap arrive plus tard, à la première peinture qui en a besoin — c'est-à-dire pile sur la première image de la rotation, d'où une saccade au tout **premier** dévoilement de chaque livre (les suivants sont fluides, l'image étant décodée en cache). Mesuré : 3 à 15 ms après `load` en local, davantage sur machine lente. `img.decode()` force ce travail en amont ; le délai est entièrement absorbé par les `REVEAL_DELAY_MS` (vérifié : rotation toujours déclenchée à ~702 ms après le survol, donc aucune latence ajoutée).

**Variable CSS globale partagée :**
- `--pf-sticky-offset` — hauteur cumulée du ou des headers sticky Kadence + barre d'admin, en pixels. Calculée et tenue à jour par `assets/js/recherche-globale.js` (chargé sur toutes les pages) au **DOMContentLoaded / resize / orientationchange / load — PAS au scroll**. Le header Kadence garde une hauteur/position constantes au scroll (vérifié headless : offset identique de scrollY=0 à 1600, desktop+mobile) ; recalculer au scroll ne faisait que réécrire la même valeur, sauf sur iOS où `getBoundingClientRect()` (relatif au viewport **visuel**) suit le repli de la barre Safari → les éléments calés dessus (hero en `svh`, barres sticky) sautaient. Seul écart assumé : barre d'admin WP en mobile (`position:absolute`, défile) → offset figé ~46px trop haut pour un admin connecté sur téléphone, sans effet visiteur. Les autres scripts (`catalogue.js`, `recherche-auteurs.js`, `accueil.js`) et les CSS (`catalogue.css`, `recherche-auteurs.css`, `accueil.css`) la consomment sans la recalculer. **Défaut CSS `:root` : `80px`** (« en attendant » le calcul JS ; le primer inline de `front-page.php`/`section-nav.php` pose la vraie valeur avant le premier paint sur ces pages). Le hero d'accueil fait `calc(100svh - var(--pf-sticky-offset))` : `svh` (≠ `dvh`) le rend stable face au repli de la barre iOS.

**Tailles d'image servies (perf) :** l'étagère **générique** (catalogue ~300 livres, accueil, livres associés…) sert deux sous-tailles dédiées, **non-crop** (ratio préservé), enregistrées par `register_image_sizes()` (`add_image_size`, hook `after_setup_theme`) et choisies dans `prepare_books()` selon `$is_hero` :

| Contexte | Couverture | Dos |
|---|---|---|
| Étagère générique | `pf-shelf-cover` (400×600) | `pf-shelf-spine` (300×760) |
| **Mode héros** (fiche livre, livre affiché bien plus grand) | `medium_large` (768) | `large` (1024) |

Les dos étant très étroits et hauts, c'est la **hauteur** qui contraint (760), la largeur suit le ratio (~30-90px) → poids minimal. Gain mesuré : couvertures d'étagère ≈ **17 % du poids** de `medium_large` (l'ancien `medium_large` retombait même sur l'original plein format quand l'upload faisait < 768px de large). ⚠️ **Gotcha** : `wp_get_attachment_image_url()` sur une sous-taille **non encore générée** retombe silencieusement sur l'**original plein format** (régression) → toute image déjà en médiathèque doit être régénérée après enregistrement d'une nouvelle taille (`wp_update_image_subsizes()` par pièce jointe, ou `wp media regenerate`). Les futurs imports les génèrent d'office. Le rendu (dimensions `width`/`height` dérivées des mm) est inchangé — seule la résolution source baisse.

The bookshelf design is functional but not final — further visual refinement is planned.

---

## PDF Catalog feature

The PDF catalogue list is fully admin-editable: a repeater field `catalogues` (SCF field group `group_6a4ba1e58c0bd`, "Menu déroulant Catalogues PDF", `lien` is a **text** field — not `url`, to allow root-relative paths like `/catalogue.pdf`) is attached to the Catalogue page (WordPress page ID 8 = `wc_get_page_id('shop')`). Each row has a label (`libelle`), an optional path/link (`lien`), and an optional uploaded PDF (`fichier`, attachment ID):
- **`fichier` + `lien` both set** → `lien` becomes the public virtual path that serves that exact file (dynamic rewrite rule, independent of the file's real media-library URL).
- **`lien` only** → used verbatim as the href (arbitrary external/absolute URL, no file served by this site).
- **`fichier` only** → href = the file's native media-library URL.

- **Admin UI:** WooCommerce → Catalogues PDF (`inc/catalogues.php`, slug `passiflore-catalogue`) renders the *native* SCF form for that field group via `acf_form()`/`acf_form_head()` (SCF's public form-rendering API — still `acf_`-prefixed for compatibility) instead of a hand-rolled form. `acf_form_head()` is hooked on `load-{$hook_suffix}` (must run before any HTML output, since it handles the POST save + redirect).
- **Réécriture dynamique :** pour chaque ligne fichier+lien, `passiflore_get_pdf_catalogue_rows()` calcule un chemin normalisé et `add_rewrite_rule()` (hook `init`, position `'top'`) le mappe vers `index.php?pf_catalogue_pdf=<attachment_id>` ; `template_redirect` sert le fichier via `readfile()`. ⚠️ Le cœur de WordPress (`WP_Rewrite::wp_rewrite_rules()`) ne relit **jamais en direct** les règles ajoutées par `add_rewrite_rule()` — il se contente du cache en base (option `rewrite_rules`) tant qu'il existe, sinon une URL modifiée/ajoutée redirige silencieusement vers l'accueil au lieu de 404 ou de servir le fichier. `acf/save_post` (scoping sur l'ID de la page Catalogue, priorité 20) appelle donc `flush_rewrite_rules()` à chaque enregistrement du repeater.
- **Front-end:** `passiflore_get_pdf_catalogues()` (`inc/catalogues.php`) reads the repeater and returns a normalized `[{libelle, url}, …]` list; `inc/class-catalogue.php` (`render_top_bar()`) loops over it to render the "Catalogues PDF" dropdown, hiding the whole block when the list is empty.

---

### Tarif d'expédition réduit au-delà d'un seuil (`inc/shipping.php`)

Réduit le forfait d'expédition d'une méthode « Forfait » (WooCommerce `flat_rate`) une fois un montant de commande atteint. **Réglé par instance de méthode**, directement dans la popup **« Configurer forfait »** d'une zone (WooCommerce → Réglages → Expédition → Zones d'expédition) — pas d'onglet global.

- **Deux champs ajoutés à la popup** via `woocommerce_shipping_instance_form_fields_flat_rate`, insérés juste après le champ natif « Coût » : `pf_seuil` (seuil € TTC) et `pf_cout_reduit` (forfait à partir du seuil ; vide = gratuit). Sauvegardés par WooCommerce dans l'option d'instance `woocommerce_flat_rate_{instance_id}_settings`.
- **Application** : `woocommerce_package_rates` (prio 100) parcourt les tarifs `flat_rate`, lit les réglages de **chaque** instance (`get_option`), et si `pf_seuil > 0` et `WC()->cart->get_displayed_subtotal()` (sous-total **TTC affiché**) ≥ seuil → `set_cost( pf_cout_reduit )` (+ recalcul TVA si la méthode est taxable). Seuil vide/absent → aucune réduction. Valeurs FR à virgule décimale acceptées.
- **Migration unique** (`admin_init`, flag `pf_shipping_seuil_migrated`) : reporte l'ancien réglage **global** (onglet « Tarif réduit » supprimé) dans les instances 1 (Point relais, 0,01 €) et 2 (Livraison à domicile, 2,00 €) de la zone National, seuil 35 € — préserve le comportement à l'identique et pré-remplit la popup. Défauts repris de l'ancien code si l'option globale n'avait jamais été enregistrée.

**Recalcul de la livraison au changement de pays — checkout en blocs** (`assets/js/checkout-shipping-country.js`, enqueué sur `is_checkout()` avec dép. `wp-data`) : contourne un **bug WooCommerce Blocks** (constaté en 10.6.1) — changer le `<select>` « Pays/Région » de l'adresse met à jour l'état interne (`wc/store/cart`) mais **ne déclenche pas** le push Store API qui recalcule les tarifs d'expédition (contrairement aux champs texte code postal/ville). Résultat sans correctif : le tarif reste celui du pays précédent tant qu'aucun champ texte n'est ré-édité. Le script écoute le `change` sur tout `<select …-country>` et, après un court debounce (300 ms, le temps que le bloc mette à jour le store avec le nouveau pays), rappelle `wp.data.dispatch('wc/store/cart').updateCustomerData({billing_address, shipping_address})` avec l'adresse complète relue du store → force le push et rafraîchit « Options de livraison » + total. Idempotent (un recalcul de plus si le bloc finit par pousser seul est inoffensif), try/catch silencieux (ne jamais casser le tunnel). Vérifié headless (puppeteer-core + Chrome système) : FR ↔ BE/DE bascule bien 0,01/2,00 € ↔ 8,00 €.

---

## Design

- **Brand color:** Rouge Passiflore `#c62836` (palette1 Kadence — voir section Development environment)
- **Color palette and typography:** defined in Kadence customizer (`/wp-admin/customize.php`)
- **Direction:** fresh design (not a copy of the PrestaShop site), but keeps the Passiflore red identity
- **Approach:** custom PHP templates + CSS; Gutenberg blocks for content where appropriate

---

## CSS — Design System (règles impératives)

> Référence : `docs/design-system.md` (système) et `docs/audit-css-2026-07-02.md` (audit + arbitrages actés).

1. **Jamais de nouvelle valeur en dur.** Couleur → token `--pf-*` (qui pointe sur `--global-palette*`) ; espacement structurel en rem → `--pf-space-*` ; radius → `--pf-radius`/`-md`/`-lg`/`-card` ; ombre → `--pf-shadow`/`-hover`/`-stuck`/`-float` ; focus → `--pf-focus-ring(-accent)` ; statuts → `--pf-success/info/warning/danger(-bg)`. Alpha dérivé d'un token → `color-mix(in srgb, var(--pf-…) N%, transparent)` (jamais un rgba figé). Exceptions tolérées : valeurs en `em` relatives au texte, micro-géométrie de composant **documentée par une var locale commentée** (ex. `--pf-hero-glass`).
2. **Avant d'écrire du CSS : lire les composants de `style.css`.** Existants : `.pf-btn(--primary/--outline/--neutral/--sm/--block)`, `.pf-card` (+ `--static/--compact`, `-title`, `-content`, `-text`), `.pf-panel(--alt/--danger)`, `.pf-badge--*` (dont `--attente`), `.pf-quote(--accent)`, `.pf-notice(--error/--success)`, `.pf-search(--sm)`, `.pf-switch(--solid)`, `.pf-splitbtn(--solid)` (libellé principal + kebab soudé), `.pf-dropdown`, `.pf-sticky-bar`/`.pf-sub-header`, `.pf-roundbtn`, `.pf-spinner`/`@keyframes pf-spin`, `.pf-hscroll`, `.pf-scroll-fade`, `.pf-section-titre`, `.pf-titre-1/2`, `.pf-label`, `.pf-avis-reponse`, bloc newsletter. **Un motif présent sur ≥ 2 pages appartient à `style.css`**, préfixe `.pf-` obligatoire (`.bs-*` de book-single = legacy gelé).
3. **Un CSS de page ne contient que** : le layout propre à la page, les comportements bespoke, et les *shims de spécificité* anti-plugin. Toute règle « apparence d'un composant » remonte dans `style.css`.
4. **Boutons** : markup custom → classes `.pf-btn .pf-btn--…` ; boutons WooCommerce/plugins → garder le sélecteur existant et pointer ses valeurs sur les tokens. États `:hover/:focus/:active/:disabled` gérés.
5. **Kadence/plugins** : couleur des boutons natifs via les variables `--global-palette-btn-*` (redéfinies en `:root`, `!important` sur la *définition*) — jamais par des règles de fond. `!important` **interdit entre fichiers du thème enfant** ; autorisé uniquement contre TEC/Woo/Kadence/Customizer. ⚠️ Le reset `.tribe-common *` (0,1,0, chargé après style.css) écrase les composants globaux (0,1,0) sur les pages TEC → re-imposer les valeurs via un shim (0,2,0) dans `events.css` en consommant les mêmes tokens (cf. switch Liste/Mois).
6. **Breakpoints** : 480 / 768 / 1024 (`max-width: 767px` ↔ `min-width: 768px`). Exceptions documentées : 600 (photo auteur), 781 (TEC medium), 540 (newsletter, seuil de composant).
7. **Hauteur de contrôle de barre** : `--pf-control-h` (défaut 38px, surchargée par barre — cf. catalogue 40/34px) ; géométrie du champ de recherche via les vars `--search-*` de `.pf-search`. Pas de nouvelles hauteurs en dur.
8. **Après tout ajout/modification de token ou composant** : mettre à jour `docs/design-system.md`, et vérification visuelle avant/après (captures headless, cf. mémoire `visual-testing`) sur les pages touchées.

---

## Site structure (pages created)

Pages WordPress publiées :
- Accueil (slug: `accueil`)
- Catalogue (slug: `catalogue`)
- Auteurs (slug: `auteurs`) — contient `[passiflore_auteurs]`
- Présentation (slug: `presentation`) — "Qui sommes-nous ?"
- Contact (slug: `contact`) — "Nous contacter"
- Mon compte (slug: `mon-compte`)
- Panier (slug: `panier`)
- Validation de la commande (slug: `commander`)

Pages en brouillon :
- Politique de confidentialité
- Politique en matière de remboursements et de retours

"Actualités" : remplacée par le post type `tribe_events` (The Events Calendar) — pas de page WP dédiée.

---

## Migration status

- **Books:** imported once; updated data received from publishing house — re-import pending
- **Authors:** previously imported then deleted; updated data received — re-import pending
- **Import scripts:** `import-auteurs.php`, `import-livres.php`, `import-livres-1-classiques.php`, `import-livres-2-grands-caracteres.php`, `import-livres-3-numeriques.php`, `import-livres-4-actualites.php`, `import-livres-common.php`, `import-actualites.php` (all in `app/public/`)
- **URL redirections:** CSVs in `kadence-child/redirections/` (`auteurs.csv`, `redirections_actualites.csv`) — pas encore chargés dans le plugin Redirection

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
| UpdraftPlus | Backups (installé, destination cloud non encore configurée) |
| The Events Calendar | Events management |
| Contact Form 7 | Formulaire de contact |
| WP Add Mime Types | Ajout de types MIME (ex. EPUB) |
| Child Theme Configurator | Génération initiale de `functions.php` — ne plus utiliser activement |

---

## Gotcha — guillemets typographiques dans les fichiers PHP

**Problème récurrent :** l'outil Edit introduit parfois des guillemets typographiques Unicode (`'` U+2018, `'` U+2019, `„` U+201E) à la place des apostrophes ASCII (`'` 0x27) dans le code PHP. PHP ne reconnaît pas ces caractères comme délimiteurs de chaîne, ce qui provoque une erreur de syntaxe fatale.

**Symptôme :** `Parse error: syntax error, unexpected identifier 'xxx'` sur une ligne qui contient une chaîne PHP `'...'` — PHP voit le contenu de la chaîne comme un identifiant nu parce que le délimiteur ouvrant n'est pas ASCII.

**Vérification :** après tout Edit sur un fichier PHP, lancer :
```
"/Users/loicrobin/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php" -l "chemin/vers/fichier.php"
```

**Correction si détectée :** utiliser Python pour remplacer les bytes exacts :
```python
import re
path = "chemin/vers/fichier.php"
data = open(path, 'rb').read()
data = data.replace(b'\xe2\x80\x98', b"'")           # U+2018 → ASCII '
data = re.sub(rb'\xe2\x80\x99(?=[;,)\].\'\\s])', b"'", data)  # U+2019 closing → ASCII '
open(path, 'wb').write(data)
```
Vérifier ensuite que les U+2019 légitimes (apostrophes françaises dans du contenu de chaîne, ex. `l'événement`) ne sont pas touchés.

**Règle :** après chaque Edit sur un fichier PHP, faire un `php -l` systématique.

---

## Gotcha — ne jamais tuer Chrome globalement pendant les tests visuels

Les vérifications visuelles lancent des instances **Chrome headless** (CDP / `--screenshot`). **Ne JAMAIS** faire `pkill`/`killall` sur « Google Chrome » (ni aucun motif large) : le **navigateur GUI de l'utilisateur** — où il regarde le site en local — tourne sous le même nom et serait tué, l'obligeant à tout relancer. Chaque instance de test doit utiliser un `--user-data-dir` **et** un `--remote-debugging-port` **uniques**, et n'être arrêtée que par son propre lanceur (le script node tue le seul process enfant qu'il a spawné, par son PID). Pas de nettoyage « au nom » ; les instances headless se terminent d'elles-mêmes (kill ciblé du PID par le lanceur, ou `--virtual-time-budget`). Un `--user-data-dir` unique par run suffit à éviter tout conflit de profil/port, donc aucun `pkill` préalable n'est nécessaire.

---

## Sécurité AJAX — politique de nonce

**Endpoints publics en lecture seule = pas de nonce** (choix acté). Un nonce WordPress n'a de valeur que pour la protection CSRF d'une action **qui modifie un état au nom d'un utilisateur connecté** ; sur un endpoint `nopriv` qui ne fait que **lire des données publiques**, il n'apporte aucune sécurité (pour un visiteur déconnecté, `wp_create_nonce` produit même un jeton partagé tournant toutes les 12 h) et provoque un **403 sur un onglet resté ouvert au-delà de la durée de vie du nonce** (12–24 h). Ces endpoints n'appellent donc **pas** `check_ajax_referer` et n'émettent pas de nonce : `pf_global_search`, `pf_recherche_auteurs`, `pf_catalogue(_filter)`, `pf_events_feed`, `pf_events_search`, `pf_events_map_search`. **Ne pas “réparer” en re-ajoutant un nonce.**

**Endpoints à état = nonce conservé + dégradation gracieuse.** Ceux qui écrivent (panier `pf_numerique_*`, newsletter, avis `pf_avis_*`, liste de lecture / signets `pf_reading_list_toggle`, suppressions, sauvegardes admin) **gardent** leur nonce. Pour éviter l'échec silencieux d'un 403, leur JS détecte `response.status === 403` **avant** `.json()` et appelle `window.pfSessionExpired({ mode })` (voir `assets/js/pf-session-toast.js`) : mode `'reload'` pour les actions pures (panier, suppressions), `'confirm'` là où un auto-reload perdrait une saisie ou une position de navigation (avis, newsletter, liste de lecture, signets). Le sondage de fond du panier, lui, avale le 403 silencieusement (pas d'action utilisateur → pas de toast).

---

## PHP conventions

Use **OOP classes** for complex, stateful features (e.g. `Passiflore_Bookshelf`, `Passiflore_Catalogue`). Use **procedural hooks** for lightweight, single-purpose functionality (e.g. `inc/auteurs.php`, `inc/admin.php`). Follow the existing patterns in the child theme. All custom code belongs in `kadence-child/`.

---

## Implementation plan

### Phase 1 — Core book experience
1. ✅ **Pageflip viewer** (`woocommerce/content-single-product.php`, `pageflip.php`) — cover image + PDF extract displayed as a flipbook
2. ✅ **Full single book page** — hero (pageflip + titre/sous-titre/prix/auteurs) puis sections sous le hero via `inc/book-single-tabs.php` : résumé, caractéristiques, auteurs, presse, vidéos, podcasts, **avis lecteurs/libraires curés + avis WooCommerce des visiteurs (formulaire + anti-spam)**, événements, livres associés. Reste à explorer : mini `[passiflore_etagere]` dans le hero.
3. ⬜ **Bookshelf display refinement** — visual design not final

### Phase 2 — Authors
4. ✅ **Author single page** (`taxonomy-auteur.php`) — photo, bio, book list
5. ✅ **Authors archive page** (`[passiflore_auteurs]` + `Passiflore_Recherche_Auteurs`) — grid with AJAX search

### Phase 3 — Catalog & navigation
6. ✅ **Catalogue pages** (`Passiflore_Catalogue`, `archive-product.php`) — filterable catalogue with AJAX, auto-detects current WC category
7. ✅ **Shop/catalog landing page** — handled by `archive-product.php` override

### Phase 4 — Import & content
8. ⬜ **Re-import authors** — data received from publishing house, ready to import
9. ⬜ **Re-import books** — data received; align SCF fields with `_book_pages`/`_book_width_mm`/`_book_height_mm` post meta
10. ⬜ **URL redirections** — load CSVs from `kadence-child/redirections/` into the Redirection plugin (not done yet)

### Phase 5 — Remaining pages
11. ⬜ **Home page** — 1–2 test bookshelves in place; full design and editorial build pending
12. ✅ **Événements** (ex-Actualités) — Events Calendar set up; associated books displayed via `[passiflore_etagere]` from `_pf_event_books` post meta; custom admin meta box with author quick-add, text search, drag reorder, date-sorted insertion
13. ⬜ **Qui sommes-nous ?** and **Nous contacter** — static content pages
14. ⬜ **WooCommerce pages** — Cart, Checkout, My Account — may need styling; currently virgin

### Phase 6 — Launch preparation
15. ⬜ **SEO** — configure Rank Math: meta descriptions, sitemaps, schema for books/authors
16. ⬜ **Performance** — image optimization, caching strategy
17. ⬜ **Production deployment** — choose and configure hosting (Ionos or alternative)
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