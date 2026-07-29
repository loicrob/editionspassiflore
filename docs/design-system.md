# Système de design — Éditions Passiflore

> Document de travail créé le 2026-06-22, mis à jour le 2026-07-02.
> But : uniformiser les éléments globaux (titres, polices, tailles, boutons, couleurs) aujourd'hui dispersés.
> Deux parties : **A. Audit de l'existant** (la photo de départ, juin 2026 — historique) — **B. Système cible** (décidé, largement implémenté dans `style.css`).
> **État des lieux actuel et arbitrages de finalisation : voir `docs/audit-css-2026-07-02.md`** (approuvé le 2026-07-02).

---

## A. Audit de l'existant

### A.0 Les trois couches (et pourquoi c'était confus)

> ⚠️ **Photo de juin 2026, avant refactor.** Depuis, `style.css` porte les tokens + composants globaux (voir B) et la migration des CSS de composants est en cours (voir `audit-css-2026-07-02.md`).

L'identité visuelle est censée vivre dans des couches « globales », mais celles-ci étaient **vides** ; tout s'était accumulé dans les CSS de composants.

| Couche | Emplacement | État réel |
|---|---|---|
| 1. Customizer Kadence | base de données (`/wp-admin/customize.php`) | **Quasi vide** : seule la palette de couleurs est réglée. Typographie globale et boutons = **valeurs par défaut Kadence**, jamais touchées. |
| 2. `style.css` (racine) | thème enfant | **Quasi vide** : `line-height:1.1` sur les titres, quelques overrides header/menu/panier. |
| 3. 15 CSS de composants | `kadence-child/assets/css/` | **Toute l'identité visuelle**, avec valeurs en dur recopiées partout. |

**Conséquence :** changer « le » style global est impossible depuis un seul endroit → il faut éditer 15 fichiers.

### A.1 Incohérence bloquante — le rouge de marque

- Palette Kadence active (`second-palette`) : **`palette1 = #c8102e`** → c'est ce qui s'affiche réellement.
- `CLAUDE.md` + ~40 fallbacks CSS : **`#c62836`**.

**Décision actée : le vrai rouge est `#c62836`.**
→ ✅ **Fait** : `palette1 = #c62836` dans le customizer (vérifié en BD le 2026-07-02).

### A.2 Couleurs en dur répétées (au lieu de `var(--global-palette*)`)

| Hex en dur | Occurrences | Variable Kadence équivalente |
|---|---|---|
| `#c62836` | 40+ | `--global-palette1` |
| `#a0212c` | 12+ | `--global-palette2` |
| `#5e524d` | 30+ | `--global-palette3` |
| `#1a1615` | 20+ | `--global-palette4` |
| `#D9C8B0` | 8+ | `--global-palette10` |
| `#FAF6F0` | 10+ | `--global-palette9` |
| `#F5F0E6` | 5+ | `--global-palette8` |
| `#fcfbf7` | 8+ | (custom, hors palette) |

La plupart sont utilisées en *fallback* (`var(--global-palette1, #c62836)`) — donc inertes aujourd'hui, mais trompeuses (elles affichent le mauvais rouge dans l'éditeur) et multiplient les points de maintenance.

### A.3 Polices — 3 familles mélangées sans hiérarchie

| Famille | Où | Sens |
|---|---|---|
| `Georgia, 'Times New Roman', serif` | titres de fiche livre, avis, pageflip | accent « littéraire » |
| `system-ui, -apple-system, sans-serif` | bookshelf, distinctions, labels | UI |
| `'Inter', system-ui, sans-serif` | catalogue uniquement | UI (différent du reste !) |
| (défaut Kadence) | corps de texte partout ailleurs | jamais défini explicitement |

Problème : `Inter` n'apparaît que dans le catalogue ; ailleurs c'est `system-ui` → deux polices « sans » différentes selon la page. `--global-heading-font-family` n'est utilisé qu'une seule fois.

### A.4 Titres — pas de règle de base, weights au hasard

- Aucune règle `h1`–`h6` autre que `line-height:1.1`. Les vrais titres héritent du défaut Kadence (~400).
- Les classes de titre custom utilisent `600` **ou** `700` sans logique :
  - `600` : `.bs-hero__title`, `.pf-hero-cat-titre`, `.pf-actualite-titre`, `.pf-event-titre`, `.pf-info__title`
  - `700` : `.pf-section-titre`, `.pf-etagere-titre`, `.pf-auteur-*-titre`
- Tailles : chaque section invente son `clamp()` (`clamp(2.6rem,4.2vw,4.8rem)`, `clamp(1.15rem,2vw,1.6rem)`, `clamp(1.1rem,2.2vw,1.6rem)`…) → aucune échelle commune.

### A.5 UPPERCASE — un pattern cohérent qui n'est pas formalisé

Bonne nouvelle : c'est déjà cohérent **par convention**. L'uppercase ne s'applique qu'aux **petites étiquettes** (0.7–0.95rem, `letter-spacing` 0.06–0.22em) : `.pf-section-titre`, `.pf-etagere-titre`, `.pf-auteur-*-titre`, `.bs-section__title`, en-têtes de tableaux WooCommerce, dates d'événements. Les **vrais titres de contenu** (titre produit, h2 de section) ne sont jamais en uppercase. → Il « suffit » de nommer ce pattern (voir B).

### A.6 Boutons — chaque fichier réinvente

| Propriété | Valeurs trouvées | Problème |
|---|---|---|
| `border-radius` | `3px` (15×, boutons), `6px` (8×, contrôles catalogue/recherche), `2px`, `4px`, `8px` | incohérent |
| `padding` | `0.65em 1.2em`, `0.6em 1.4em`, `0.8em 1.2em`, `0.85em 1.2em`, `0.3em 0.7em`, `8px 12px`, `8px 14px` | mélange em/px, pas de hiérarchie |
| `hover` | tantôt `background`, tantôt `background`+`border-color` | inconsistant |
| variantes | primaire (rouge plein), outline (bordure rouge), petit (format) — existent mais **sans classe commune** | dupliqué partout |

Aucune règle `.button`/`button` de base au niveau enfant : tout vient de Kadence + WooCommerce, puis chaque composant redéfinit tout.

### A.7 Chargement des CSS (`functions.php`)

Chargement conditionnel par page (`is_product()`, `is_account_page()`, etc.). `bookshelf.css`, `pageflip.css`, `catalogue.css`, `recherche-*.css`, `reading-list.css` sont chargés via d'autres hooks/JS. → Un bloc de tokens dans `style.css` (toujours chargé) sera disponible partout.

---

## B. Système cible proposé (à valider)

Principe : **décider une fois, au bon endroit, puis tout fait consommer ces décisions.**
- Ce que Kadence sait gérer → **customizer** (les composants natifs héritent gratuitement).
- Le reste → **un seul bloc `:root` de design tokens** en haut de `style.css`.

### B.1 Couleurs — source unique

- Régler la palette dans le **customizer Kadence** (corriger `palette1` → `#c62836`).
- En CSS : **toujours** `var(--global-palette1)` etc., **sans fallback hex** (ou fallback = la vraie valeur). On supprime les `#c62836` en dur.

Aide-mémoire des rôles (palette active `second-palette`, vérifiée en BD le 2026-07-02) :

| Variable | Hex actuel | Rôle (token `--pf-*`) |
|---|---|---|
| `--global-palette1` | `#c62836` | Rouge Passiflore (`--pf-accent`) |
| `--global-palette2` | `#a0212c` | Rouge foncé hover (`--pf-accent-dark`) |
| `--global-palette3` | `#5e524d` | Texte courant / gris chaud (`--pf-text`) |
| `--global-palette4` | `#1a1615` | Titres (`--pf-heading`) |
| `--global-palette6` | `#666666` | Texte secondaire (`--pf-muted`) |
| `--global-palette7` | `#f5f0e8` | Crème soutenu (`--pf-cream-dark`) |
| `--global-palette8` | `#faf6f0` | Fond crème principal (`--pf-cream`) |
| `--global-palette9` | `#fcfbf7` | Fond crème clair (`--pf-surface-alt`) |
| `--global-palette10` | `#e0d8cc` | Bordures / filets (`--pf-border`) — valeur actée 2026-07-02 ; le sable historique `#D9C8B0` vit dans `--pf-sand` |

### B.2 Polices — 2 familles, un rôle clair *(décidé)*

| Token | Valeur retenue | Usage |
|---|---|---|
| `--pf-font-heading` | `Georgia, 'Times New Roman', serif` | titres principaux de contenu (`.pf-titre-1`) |
| `--pf-font-body` | `system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif` | corps de texte, UI, titres secondaires/étiquettes |

- **serif** pour les titres principaux (voix « maison d'édition »), **sans** pour l'UI et les étiquettes uppercase.
- `system-ui` = police native de l'OS (rapide, aucun téléchargement) ; choisie plutôt qu'Inter pour rester légère et homogène.
- À faire : définir `--pf-font-body` comme police de base **dans le customizer Kadence** (Typography → Base) ; remplacer le `Inter` du catalogue et les `system-ui` épars par `var(--pf-font-body)`.

### B.3 Échelle typographique — paliers communs

```css
--pf-text-xs:   0.75rem;   /* étiquettes uppercase */
--pf-text-sm:   0.875rem;  /* méta, légendes */
--pf-text-base: 1rem;      /* corps */
--pf-text-lg:   1.125rem;  /* chapô, sous-titres */
--pf-text-xl:   1.5rem;    /* titres de section */
--pf-text-2xl:  2rem;      /* titres de page */
--pf-text-hero: clamp(2.6rem, 4.2vw, 4.8rem); /* hero accueil */
```

### B.3bis Titres — classes découplées des balises *(décidé)*

**Principe : la balise `h1`–`h6` porte la structure (SEO/accessibilité, un seul `h1` par page) ; une classe porte l'apparence, indépendante du niveau.** Ainsi un `h2` peut avoir l'allure d'un titre principal sans rien casser si la hiérarchie change d'une page à l'autre.

**Défauts de base sur les balises** (dans `style.css`, pas dans le customizer — pour rester versionné dans git et lié aux tokens). Ils donnent une apparence correcte au contenu créé par les admins (Gutenberg) **sans** classe, et les classes `.pf-titre-*` / `.pf-label` (spécificité supérieure) surchargent au besoin :
```css
h1,h2,h3,h4,h5,h6 { line-height: 1.1; font-weight: 600; }
h1 { font-family: serif; font-size: 2rem; }      /* h1 = serif */
h2..h6 { font-family: sans; ... }                 /* h2–h6 = sans, tailles décroissantes sur l'échelle */
```
Note : l'aperçu dans l'éditeur de blocs nécessitera un `add_editor_style()` (suivi).

Trois classes de titre :

| Classe | Police | Casse | Taille | Weight | Usage |
|---|---|---|---|---|---|
| `.pf-titre-1` | serif | normale | `--pf-text-2xl` (ou hero) | 600 | titre de page, de livre, de section éditoriale |
| `.pf-titre-2` | sans | UPPERCASE (court) | `--pf-text-xl` | 600 | séparateurs de section |
| `.pf-label` | sans | UPPERCASE | `--pf-text-xs` | 400 (base) | **étiquette réutilisable** : en-têtes de section, nav de fiche, rôle d'auteur, dates, méta. **Couleur laissée au contexte**. Weight de base (neutralise le gras par défaut des `<h2>`). |

Rappel : l'uppercase ne s'applique qu'à des titres/étiquettes **courts** (lisibilité + accents capitales français).

`.pf-label` (ex-`pf-titre-3`) est le motif « petite étiquette capitales », volontairement **sans titre dans le nom** car il sert aussi à du non-titre (rôle d'auteur, méta). Il remplace `.pf-section-titre`, `.pf-etagere-titre`, `.pf-auteur-*-titre`, `.bs-section__title`, `.bs-auteur-role`, `.bs-hero__authors-line`, `.bs-lies-title`, `.bs-media-section h3`, `.bs-avis-section h3` (qui font tous la même chose avec des valeurs légèrement différentes). La couleur reste posée localement (palette3, palette6…).

### B.5 Boutons — 3 variantes, des tokens uniques

```css
--pf-radius:        3px;   /* boutons + contrôles */
--pf-btn-pad:       0.65em 1.2em;   /* primaire / secondaire */
--pf-btn-pad-sm:    0.3em 0.7em;    /* petit (format, filtres) */
--pf-btn-weight:    var(--pf-weight-normal); /* 400 — valeur implémentée (le 600 initial a été abandonné) */
--pf-btn-transition: background .2s, border-color .2s, color .2s;
```

| Variante | Fond | Texte | Bordure | Hover |
|---|---|---|---|---|
| **primaire** | `--global-palette1` | `#fff` | idem fond | fond `--global-palette2` |
| **secondaire (outline)** | transparent | `--global-palette1` | `1px --global-palette1` | fond `rgba(198,40,54,.08)` |
| **neutre** | `#fff` | `--global-palette3` | `1px --global-palette10` | bordure+texte `--global-palette1` |

→ Une base `.pf-btn` (padding, radius, weight, transition) + modificateurs `--primary` / `--outline` / `--neutral` / `--sm`. Les boutons WooCommerce (panier, checkout, compte) héritent via les sélecteurs existants mappés sur ces tokens.

**Décidé : radius `3px`** (sobre, déjà majoritaire sur les vrais boutons). On unifie aussi les contrôles de formulaire (catalogue/recherche, aujourd'hui en `6px`) sur ce `--pf-radius`.

**Système implémenté** (`style.css`) : base `.pf-btn` + modificateurs `.pf-btn--primary` / `--outline` / `--neutral` / `--sm` / `--block`. Token `--pf-red-soft` pour le survol translucide.

**`.pf-btn--sm` — calé sur les triggers de la barre principale du catalogue** (`.pf-cat-trigger`) : `padding: var(--pf-space-2) var(--pf-space-3)` (8px 12px), `font-size: 13px`, `line-height: 1.2`, `border-radius: var(--pf-radius-md)` (6px). La **couleur** reste portée par le modificateur (`--neutral/--primary/--outline`) ; les états propres au trigger (actif/ouvert, caret, jointures de radius) restent sur le trigger, pas sur `.pf-btn--sm`. Le token historique `--pf-btn-pad-sm` ne sert plus qu'au petit bouton **natif** Kadence (`.button.button-size-small`, ex. bouton compte du header), volontairement laissé à sa taille d'origine.

**Deux règles de refactor pour les boutons** (validées sur le pilote `book-single`) :
1. **Bouton custom** (nos `<a>`/`<button>`) → ajouter les classes `.pf-btn .pf-btn--…` dans le markup, et réduire le CSS local au contextuel (largeur, marge, layout flex, états `:disabled`/`.is-…`).
2. **Bouton WooCommerce/plugin** (`.button`, `comment_form` submit…) → garder le sélecteur existant (souvent composé, ex. `.bs-hero__cart.button`, pour battre la spécificité WC) et pointer ses valeurs sur les mêmes tokens (`--pf-btn-pad`, `--pf-radius`, `var(--global-palette1)`…).

Les deux consomment les mêmes tokens → rendu identique, source unique.

**Défauts boutons natifs** (Gutenberg core `.wp-block-button__link`/`.wp-element-button`, Kadence `.button`/`.button-style-fill`/`.button-style-outline`) :
- **Forme** (radius, weight, `box-shadow: none`, transition) : règles sur les classes natives dans `style.css`.
- **Couleur** : Kadence fait passer TOUTE la couleur des boutons par ses variables `--global-palette-btn-bg` / `--global-palette-btn` / `…-hover` / `--global-palette-btn-out` (par défaut l'or, **pas** le rouge). On les **redéfinit** dans notre `:root` avec `!important` sur la définition de variable → bat le CSS inline du personnalisateur, sans rendre le fond `!important` (les surcharges par page restent prioritaires). C'est plus robuste que réécrire les règles outline (spécificité (0,4,0)).
- **Volontairement laissés à Kadence/l'admin** : `padding` et `font-size` des boutons natifs (l'admin les règle par bloc dans Gutenberg ; tailles adaptées au contexte).

⚠️ À vérifier en navigateur (l'override de variable doit bien battre le CSS inline du personnalisateur).

### B.6 Espacement *(implémenté)*

Échelle d'espacement (base 0.25rem) dans `style.css` :
```css
--pf-space-1:.25 --pf-space-2:.5 --pf-space-3:.75 --pf-space-4:1 --pf-space-5:1.25
--pf-space-6:1.5 --pf-space-8:2 --pf-space-10:2.5 --pf-space-12:3 --pf-space-16:4  (rem)
```
**Règle de refactor** : ne tokeniser que les espacements **structurels en rem** (`margin`, `padding`, `gap`). **Laisser** : les valeurs en `em` (paddings de boutons/badges, relatifs au texte), les `calc()` de positionnement, et les micro-valeurs de composant (ex. `0.15rem`/`0.4rem` de la toolbar). **Ne pas** tokeniser `font-size` / `width` / `height` / `border-radius` avec l'échelle d'espacement (ce sont des dimensions, pas du rythme).

**Audit 2026-07-14** — écarts *entre éléments* des barres sticky /catalogue et /evenements uniformisés sur `--pf-space-2` (catalogue.css, events.css) : plusieurs `gap`/`margin-left` hardcodés à 8px tokenisés à valeur inchangée, et deux écarts à valeur différente ramenés à 8px (`.pf-catalogue-chips` 6→8px, `.pf-events-header-bar__inner` row-gap 12→8px, `.pf-catalogue-sticky` column-gap tablette 12→8px avec sa marge négative compensatoire en vis-à-vis). Volontairement laissés hors périmètre : les écarts icône↔libellé **internes** à un contrôle (ex. `.pf-cat-univers-btn` 5px, `.tribe-events-c-subscribe-dropdown__button` 6px) et le panneau de filtres mobile (`.pf-cat-filter-panel`, hors de la barre sticky proprement dite, déjà sur `--pf-space-3`/`-4`).

### B.7 Radius *(implémenté — 4 tokens)*
```css
--pf-radius: 3px;       /* boutons + champs de formulaire */
--pf-radius-md: 6px;    /* contrôles : déroulants, switches, barres (catalogue, recherche) */
--pf-radius-lg: 8px;    /* panneaux, tableaux (récap commande, paiement…) */
--pf-radius-card: 12px; /* tuiles : auteurs, événements, livres associés */
```
Hors échelle assumés : `999px` (pilules `.pf-badge`, chips), `50%` (boutons ronds), `5px` (polaroïd du héro — esthétique photo voulue).

### B.8 Tokens & composants ajoutés le 2026-07-02 *(exécution de l'audit)*

**Tokens (`:root` de style.css) :**
- `--pf-sand: #D9C8B0` — sable historique (badge « attente »…) ; distinct de `--pf-border` (= palette10 `#e0d8cc`, acté).
- `--pf-shadow-stuck` / `--pf-shadow-float` — ombres unifiées des éléments sticky collés et des menus flottants.
- `--pf-success/info/warning/danger` + variantes `-bg` — couleurs de statut (hors palette de marque).
- `--pf-red-soft(-strong)` et `--pf-accent-border-soft` — désormais en `color-mix()` sur `--pf-accent` → suivent la palette du Customizer.
- `--pf-control-h: 38px` — hauteur des contrôles de barre (surchargée par barre : catalogue 40/34px).
- `--pf-panel-pad` — padding des panneaux encadrés.

**Composants (style.css) :**
- `.pf-panel` (+ `--alt`, `--danger`) — panneau encadré (bordure claire, radius-lg, fond blanc/crème). Panneaux WooCommerce (totaux panier, récap commande, paiement…) : sélecteurs conservés, mêmes tokens.
- `.pf-quote` (+ `--accent`) — encart à filet gauche (avis, réponse de l'éditeur).
- `.pf-notice` (+ `--error`, `--success`) — bandeau de statut.
- `.pf-empty` — message « aucun résultat », voix commune aux **quatre** recherches du site : auteurs (`class-recherche-auteurs.php`), catalogue/étagère (`class-bookshelf.php`), recherche globale (`class-recherche-globale.php`), événements (`class-events-search.php`). Centré, `--pf-muted`, **italique** : c'est un état, pas un contenu — il ne doit pas se lire aussi noir que les résultats qu'il remplace. Avant unification (2026-07-28), auteurs et catalogue partageaient déjà cette voix, mais la recherche globale sortait en `--pf-heading` sans italique et les événements en 13,6px sans italique. ⚠️ **Shim (0,2,0) obligatoire dans `events.css`** (`.pf-empty.pf-empty`) : le reset `.tribe-common …, p, …` de `common-skeleton.css` pose `padding: 0` en (0,1,1) et bat la classe seule — le message se retrouvait collé à la liste. Réservé à l'**absence de résultat** : les repères de défilement du scroll infini événements (« Début des archives. ») gardent `.pf-ev-msg`, plus discret. La pastille flottante de la vue carte (`.pf-events-map-empty`) est un autre objet et reste à part.
- `.pf-roundbtn` — bouton rond rouge à icône crème (scroll infini événements, fermeture recherche globale).
- `.pf-spinner` + `@keyframes pf-spin` — anneau de chargement unifié.
- **Filet d'attente des recherches AJAX** + `@keyframes pf-loading-sweep` — trait de 2 px parcouru d'un bord à l'autre par une lueur `--pf-accent`. Retour d'attente commun aux recherches du site, à la place d'un spinner : celui-ci ferait un point de fixation isolé, loin du champ de saisie et hors écran dès que les résultats dépassent le pli. Indéterminé par construction — ni la durée de la requête ni le nombre de résultats ne sont connus.
  - **Quatre hôtes, qui ne posent QUE le bord auquel il se colle** : recherche globale du header → `.pf-gsearch-results.is-loading::before` + `top: 0` (`recherche-globale.css`) ; sous-header de `/evenements` → `.pf-sub-header.is-loading::after` + `bottom: 0` (`events.css`), pour les recherches **liste** (`events-search.js`) et **carte** (`events-map.js`) ; barre de `/auteurs` → même sélecteur + `bottom: 0` (`auteurs.css`, scopé `.pf-rech-sticky`), posé par `recherche-auteurs.js` ; barre de `/catalogue` → idem (`catalogue.css`, scopé `.pf-catalogue-sticky`), posé par `catalogue.js` — **sur les deux chemins** : filtrage AJAX *et* changement de rayon/catégorie (`goTo()`, rechargement complet où le filet reste à l'écran jusqu'au nouveau rendu).
  - Sur `/auteurs` et `/catalogue` le filet **s'ajoute** à l'estompage de la grille (`.pf-rech-grid` / `.pf-catalogue-grid` en `is-loading`, opacité 0,5) : deux messages différents — « ces résultats sont périmés » / « quelque chose arrive ». Utile surtout sur `/catalogue`, où `pf_catalogue_filter` prend **4 à 6 s en local** (mesuré).
  - **Gotcha — pleine largeur d'écran sans géométrie full-bleed** : le pseudo-élément est absolu et `.pf-sub-header` n'est **pas** positionné, donc son bloc conteneur est la barre sticky qui l'enveloppe (`<header>` sur `/evenements`, `.pf-rech-sticky` sur `/auteurs`, `.pf-catalogue-sticky` sur `/catalogue`), dont la boîte de padding est déjà full-bleed. **Ne jamais poser `position` sur `.pf-sub-header`** : le filet se rabattrait sur le contenu centré. Corollaire sur `/catalogue`, où la barre principale n'est **pas** le dernier enfant du sticky (top bar, barre, chips) : le filet se pose bien sous les chips, au bas de la barre entière — et non sous la seule rangée qui le porte.
  - La lueur sort de sa boîte de ±100 % ; le clip est assuré par l'hôte (`overflow-y:auto` du panneau global) ou par `#wrapper` (`overflow: clip`) — vérifié : `scrollWidth === innerWidth`, aucune barre de défilement horizontale.
  - ⚠️ **Le sous-header de `/evenements` est en « smart-hide »** (`.pf-sticky-bar.is-hidden`, `opacity: 0`) : un chargement déclenché par un scroll descendant se produit barre rétractée, donc filet invisible (mesuré). D'où le partage sur la recherche liste — **le retour se pose là où le résultat va apparaître** : filet du sous-header pour une **nouvelle recherche** (liste remplacée, visiteur les yeux sur le champ), logo tournant au bas des résultats (`.pf-ev-bottom.is-loading::before`, `events-infinite.css`) pour le **lot suivant**. Jamais les deux à la fois. La recherche carte n'a pas de pagination : filet seul.
- `.pf-switch--solid` — variante du switch à actif en aplat rouge (sélecteur de vue TEC).
- `.pf-section-titre` — ornements des titres de section (trait rouge / titre-lien flèche ➜), ex-doublon `.pf-etagere-titre` supprimé.
- Bloc newsletter fusionné dans style.css (ex-`newsletter.css`, chargé partout).
- **Marqueur « ouvre un nouvel onglet »** — flèche ➜ (U+279C) tournée de -45deg, automatique, aucune classe à poser : `a[target="_blank"]:not(.pf-card-link):not(.social-button)::after` (les liens saisis en back-office sont donc couverts d'office). Deux exclusions : les liens étirés vides `.pf-card-link` (même flèche, épinglée en haut à droite via `.pf-card:has(> .pf-card-link[target="_blank"])::after`) et les liens-icônes `.social-button` (aucun libellé auquel accoler la flèche). Le critère est **`target="_blank"`, pas « externe »** : ce qui compte pour le visiteur est le changement d'onglet, pas le domaine.
  - **Gotcha — flèche orpheline en début de ligne** : la rotation impose `display:inline-block`, ce qui fait de la flèche une *boîte atomique* (équivalente à U+FFFC pour l'algorithme de coupure, UAX #14) → une coupure de ligne reste possible juste avant elle, et une espace insécable placée dans le `content` n'y change rien (elle est enfermée dans la boîte). Sur un libellé qui remplit exactement sa ligne, la flèche part donc seule à la ligne suivante.
  - **Correctif = local, jamais global** : `white-space: nowrap` sur les libellés concernés (seul cas du site : items du menu « S'abonner » de TEC, `events.css` — leur markup se termine en plus par une tabulation, qui rouvre à elle seule un point de coupure). Ne pas changer le glyphe pour contourner : ➜ tourné est la convention visuelle du site.
  - **Pendant accessibilité obligatoire** : le contenu généré n'est pas restitué de façon fiable par les lecteurs d'écran → `pf_new_window_note()` / `pf_new_window_label()` (`inc/a11y.php`), WCAG 3.2.5.
  - **Gotcha — l'espace séparateur vit DANS le `.screen-reader-text`**, avant la parenthèse (`<span class="screen-reader-text"> (nouvelle fenêtre)</span>`), jamais avant le span. Placé dehors, il appartient au flux visible du lien : il rallonge le soulignement et écarte la flèche ➜ de son libellé (mesuré : +3,83 px sur « politique de confidentialité »). Dedans, il est emporté par le clip de `.screen-reader-text` — aucun rendu visuel — tout en restant dans le nom accessible, ce qui évite le « confidentialiténouvelle fenêtre » à la vocalisation. Même technique que la mention « opens in a new tab » du cœur de WordPress.

**Gotcha TEC** : le reset `.tribe-common *` (0,1,0, chargé après style.css) écrase les composants globaux (0,1,0) sur les pages événements → shims (0,2,0) dans events.css, mêmes tokens (cf. switch Liste/Mois).

### B.9 `.pf-scroll-fade` — ombres de bord (2026-07-09, globalisation)

Le composant existait déjà (tuiles événements/auteurs sur accueil, fiche auteur, fiche livre) mais vivait dans `events.css` sous le nom `.pf-event-tiles-wrap`, couplé aux classes JS `pf-scroll-left`/`pf-scroll-right`. Remonté dans `style.css` sous un nom générique car réutilisé sur un 4ᵉ contexte sans rapport avec les événements (nav de section mobile, fiche livre).

- **Composant** : `.pf-scroll-fade` — **`mask-image` sur le wrap** depuis le 2026-07-29 (auparavant deux `::before`/`::after` en dégradé peint). Longueur de la rampe = `--pf-fade-size` (défaut 60px), seule chose à surcharger par contexte ; les longueurs animées `--pf-fade-l`/`--pf-fade-r` sont **enregistrées via `@property … syntax:'<length>'`** — sans typage, une custom property ne s'interpole pas et le fondu apparaîtrait d'un bloc au lieu de suivre les 0,25 s hérités du dégradé. Navigateur sans `@property` : masque fonctionnel, seule la transition saute.
- **Pourquoi la bascule** : un dégradé peint doit **connaître le fond**, donc être redéclaré contexte par contexte (l'ancienne `--pf-scroll-fade-color`, supprimée), et il devient faux dès que ce fond n'est pas un aplat — cf. la nav de section, barre en verre, qui avait dû se doter d'un **masque local** dès le 2026-07-28 (B.10). Le site s'est ainsi retrouvé avec **trois** écritures du même fondu : dégradé peint (composant), masque + `@property` + 3,5rem (nav de section), masque sans transition + 32px (rangée de filtres du catalogue). C'est la variante de la nav de section — la plus complète — qui a été remontée dans le composant ; les deux autres en sont devenues clientes.
- **Sur fond opaque de la couleur attendue, les deux rendus sont indiscernables** (vérifié) : c'est ce qui a permis à la divergence de passer inaperçue. La différence n'apparaît que sur fond translucide/flouté, dégradé ou texturé, où le dégradé peint pose une bavure opaque là où le masque laisse voir ce qu'il y a derrière.
- **Coût du masque quand aucun fondu n'est actif** : mesuré sur les tuiles de l'accueil, même chargement de page, masque entièrement opaque vs `mask-image:none` → **186 px sur 1,9 M au-dessus de 3/255 (0,010 %), écart max 11/255** — la couche de composition change très légèrement l'antialiasing du texte, sans effet perceptible. Le masque est donc laissé en place en permanence plutôt que posé/retiré par les classes d'état, ce qui casserait la transition de sortie.
- **Surcharges de longueur en place** : nav de section mobile `3.5rem` (calibrage B.10), infobulle de la carte `36px`, rangée de filtres du catalogue `32px` (puces étroites : un fondu long en effacerait une entière), étagères en mode scroll `40px` ≤1023px / `28px` ≤767px (cf. ci-dessous). Toutes les surcharges de **couleur** ont disparu.
- **Hôte ajouté le 2026-07-29 — les étagères `[passiflore_etagere mode="scroll"]`** (accueil ×7, fiche auteur, fiche événement, livres associés d'une fiche livre). Posé par `Passiflore_Bookshelf::render_scroll()`, donc **acquis pour toute étagère scroll, où qu'elle soit** ; le mode `shelves` n'en a pas besoin (ses rangées sont re-réparties pour la largeur réelle, rien ne sort du champ). Trois points propres à cet hôte :
  - **Le wrap s'intercale entre le CADRE et le scroller**, pas autour de l'étagère : `.pf-bookshelf` porte fond, bordure, rayon et ombre portée — masqué à ce niveau, le cadre se serait dissous avec les livres (bordure coupée, coins arrondis effacés). Il enveloppe donc `.pf-shelf`, qui est déjà le scroller et dont la boîte est celle du wrap au pixel (vérifié). Les deux partageant `--wall-color`, le fond ne bouge pas sous le fondu : seuls les livres et la planche s'effacent.
  - **Le wrap doit être transparent à la mise en page** : l'accueil étire `.pf-shelf` sur la hauteur de son bloc (`.pf-etagere-bloc .pf-bookshelf` en flex column, `.pf-shelf` en `flex:1`). Un bloc intercalé romprait la chaîne → `.pf-bookshelf--scroll > .pf-scroll-fade` la relaie (`flex:1; min-height:0; display:flex; flex-direction:column`), inerte partout ailleurs. Vérifié : géométrie des 7 étagères de l'accueil **identique au pixel** avant/après (hauteurs 349/453/472/629/385/383/349).
  - **Rampe réduite avec la fenêtre** : 60px sur un rayon d'ordinateur (~1216px visibles) valent 5 %, mais 18 % à 390px — un tiers de couverture effacé de chaque côté. D'où 40px ≤1023px et 28px ≤767px.
  - **Câblage** : `bookshelf.js` rappelle `window.pfScrollFade(document)` en fin d'`init()` — **après `relayoutAll()`**, qui fixe la largeur des livres donc le `scrollWidth` comparé à la fenêtre — pour couvrir les étagères injectées par AJAX, que le scan `DOMContentLoaded` de `scroll-fade.js` ne voit pas. Le script est enregistré par `Passiflore_Bookshelf::register_assets()` et enqueué par `render_scroll()` (jusque-là il était enqueué page par page dans `functions.php`).
  - **Vérifié** (headless, 1400 / 900 / 390px, accueil + fiche auteur + fiche événement + fiche livre) : fondu droit au repos et fondu gauche seul en fin de course sur les seules étagères qui débordent, `--pf-fade-size` à 60/40/28px, boîte du wrap = boîte du scroller partout. Diff pixel de l'accueil avant/après : **tout le changement tient dans la bande des 60px de droite** des étagères qui débordent (hors 12 pixels d'antialiasing à ≤10/255 sur les arêtes de livres).
- **Exception documentée — `.pf-cat-row-scroll`** (rangée de filtres du catalogue) : seul hôte qui **scrolle lui-même** (pas de wrap) et dont l'état vient de son propre contrôleur (`catalogue.js`, classes `.has-fade-*`) et non de `scroll-fade.js`. Il est listé à côté de `.pf-scroll-fade` dans la règle du composant. Sans conséquence : contrairement à un pseudo-élément absolu (cf. le ⚠️ plus bas), **un masque se cale sur la boîte de bordure et ne défile pas avec le contenu**.
- **États** : `.is-scroll-left` / `.is-scroll-right`, bascules par `assets/js/scroll-fade.js` (ex-`event-tiles.js`, renommé) selon `scrollLeft`/`scrollWidth`/`clientWidth` de `wrap.firstElementChild`.
- ⚠️ **Un masque ROGNE ce qui déborde du wrap** — c'est la différence de nature avec le dégradé peint qu'il remplace, qui se contentait de peindre par-dessus (et restait à `opacity: 0` hors état). Le masque, lui, s'applique **en permanence et à toute largeur** : tout ce qui est peint hors de la boîte de bordure du wrap, par le wrap ou par n'importe quel descendant, disparaît. **Régression vécue (2026-07-29, corrigée)** : les **pastilles** de `.pf-sectionnav` en rail vertical (desktop ≥1024px) vivent à gauche du track (`a::before`, `left: -1.0625rem`), donc **hors** du wrap → effacées ; seule la ligne du rail (portée par `.pf-sectionnav`, hors wrap) restait visible, d'où un rail « nu ». Mesuré : wrap à `x=99`, pastilles peintes de `x=82` à `x=92` — 0 pixel de pastille à l'écran, 2 couleurs distinctes dans la bande contre 19 après correctif. **Correctif** : `mask-image: none` sur `.pf-sectionnav .pf-scroll-fade` en `@media (min-width: 1024px)` — le fondu ne sert qu'à la barre **horizontale** (≤1023px) ; en rail vertical le track ne défile jamais, `scroll-fade.js` ne pose donc aucune classe et `--pf-fade-l/-r` restent à 0. Vérifié : masque toujours actif à 390 et 900px (56px des deux côtés), `none` à 1400px ; pastilles restaurées sur fiche livre **et** fiche événement (la nav compte `--static`, même sélecteur, est couverte). **À vérifier avant d'ajouter un hôte** : rien d'utile ne doit déborder du wrap (les autres hôtes ont été balayés — seul un `<strong>` déjà rogné par le clamp de sa tuile dépasse).
- ⚠️ **`.pf-scroll-fade` (le wrap) ne doit JAMAIS être aussi l'élément qui scrolle** — piège rencontré en l'appliquant directement sur `.pf-sectionnav__track` (nav de section mobile, structure à un seul élément à l'origine) : un pseudo-élément `position:absolute` dont le *containing block* est l'élément `overflow:auto` lui-même fait partie de sa propre zone de scroll (le dégradé se déplaçait avec le contenu au lieu de rester épinglé). Le wrap doit rester `overflow:visible` (jamais scrollable), l'élément qui scrolle est toujours son **enfant direct unique** (`firstElementChild`) — structure à deux éléments dans les 4 usages actuels. Vérifié : `getComputedStyle(wrap).overflowX === 'visible'` + `getBoundingClientRect()` du wrap identique avant/après scroll du enfant (la valeur *déclarée* `left:0` ne suffit pas à le prouver, elle ne bouge jamais qu'on soit épinglé ou non — seule la position réellement rendue le montre).
- Script handle renommé `pf-event-tiles` → `pf-scroll-fade` (enqueue : `functions.php` ×2, `inc/accueil.php`).

### B.10 `.pf-sectionnav` — nav à sections partagée (2026-07-09, extraction)

Le layout « nav sticky + scrollspy » de la fiche livre (préfixe legacy `bs-*`) a été **extrait en composant partagé** `style.css` quand la fiche événement en a eu besoin (motif désormais sur ≥ 2 pages → règle #2 du design-system). La fiche livre a été migrée `bs-*` → `pf-*` dans la foulée (rendu identique, vérifié par captures).

- **Composant** : `.pf-body` (grille `160px | 1fr` sur desktop ≥1024px, empilée sur mobile+tablette) contenant `nav.pf-sectionnav` (verticale sticky à points + ligne de progression sur desktop ≥1024px ; barre horizontale sticky full-bleed « aéro » sur mobile+tablette ≤1023px, masquée jusqu'au scroll via `.is-visible`) + `.pf-sections` (`section.pf-section` à `scroll-margin-top` = header + nav). Point de bascule = breakpoint 1024 du design-system (relevé de 599 le 2026-07-10 pour inclure la tablette). Voir `style.css` (« Section-nav »).
- **Rendu PHP** (`inc/section-nav.php`, **découplé**) : `pf_sectionnav_bar($sections)` (la barre `<nav>` + primer inline `--pf-sticky-offset`/`--pf-sectionnav-h`, `''` si < 3 sections) et `pf_sectionnav_sections($sections)` (les blocs `<section class="pf-section">`), avec ancre/id = `sanitize_title($label)`. `pf_render_sectionnav($sections)` (fiche livre) les combine dans `.pf-body` (nav + sections) si ≥ 3, sinon les sections seules pleine largeur. La fiche événement les compose en **2 zones** (nav commune, sections top/bot) via `passiflore_get_event_sections_parts()` — cf. CLAUDE.md. Le découplage sert justement à ré-utiliser UNE barre qui liste toutes les sections tout en rendant celles-ci dans plusieurs conteneurs. Pose aussi `body.no-anchor-scroll` (neutralise le scroll d'ancre de Kadence).
- **Contrôleur JS** : `assets/js/section-nav.js` (anchor-pin au chargement + scrollspy `IntersectionObserver` `.is-active` + visibilité/hauteur de la nav mobile), keyé `.pf-sectionnav`/`.pf-section`, inerte si absentes. Enqueué sur fiche livre + fiche événement.
- **La mise en page ne doit pas dépendre de l'item ACTIF** (2026-07-29) : l'actif passe en `--pf-weight-semibold`, donc s'élargit. Là où la piste de la colonne nav est dimensionnée sur son **contenu** — fiche événement, `grid-template-columns: [nav] auto` dans `.pf-event-hero` —, c'est le **plus long** des libellés qui la fixe : devenu actif, il élargissait la colonne et **poussait tout le contenu vers la droite** (mesuré à 1400px : 121,69 → 125,59px, soit **3,9px** sur « Livres associés » ; même effet, 4px, sur la largeur du track de la barre horizontale ≤1023px, où le scrollspy fait bouger l'actif **en cours de défilement**). Fiche livre (`160px`) et compte (`200px`) : pistes fixes, jamais concernées.
  - **Correctif — double invisible en gras** : `.pf-sectionnav a::after { content: attr(data-label); font-weight: var(--pf-weight-semibold); height: 0; overflow: hidden; visibility: hidden }`, `data-label` recopié dans les deux hôtes du composant (`inc/section-nav.php`, `woocommerce/myaccount/navigation.php`). La largeur du lien est donc **toujours** celle du gras : elle ne dépend plus de l'état. `height:0` + `overflow:hidden` → seule la contribution en **largeur** compte, aucune hauteur prise ; `visibility:hidden` → hors de l'arbre d'accessibilité (le contenu généré y est exposé, le libellé serait annoncé deux fois). ⚠️ Le poids du double doit rester **celui de `.is-active`** (même token) — c'est ce qu'on réserve ; d'où le passage du `600` en dur au token dans les deux règles.
  - **Choisi plutôt que de figer la piste** (`[nav] auto` → largeur fixe, comme `.pf-body`) : le correctif traite la cause au niveau du **composant**, vaut pour tout hôte quelle que soit sa piste, et ne change aucune mise en page existante. Contrepartie assumée : la colonne nav de la fiche événement est désormais **en permanence** à la largeur du gras (125,59 au lieu de 121,69 au repos).
  - **Vérifié** (même chargement, géométrie déterministe, double neutralisé/rétabli) : fiche livre **strictement identique** avec et sans (160 × 293,81, hauteurs et positions de chaque lien inchangées) ; fiche événement amplitude du décalage **3,9 → 0px** en balayant l'actif sur les 5 sections ; barre mobile 390px amplitude du track **4 → 0px**, hauteur de barre inchangée (35,58px, donc `--pf-sectionnav-h` intact) ; opération idempotente. ⚠️ La comparaison **pixel** de la colonne nav n'est pas concluante ici (le contrôle avec/avec produit autant de bruit que avec/sans) — s'en tenir à la géométrie.
- Variable renommée `--pf-bs-sectionnav-h` → `--pf-sectionnav-h`. Le toggle « Voir tout » des sous-listes reste propre à la fiche livre (inline dans `book-single-tabs.php`, sélecteur `.bs-avis-section, .pf-section`). Classes de **contenu** livre `bs-*` (ex. `.bs-section__body`) conservées.
- **Variante `.pf-sectionnav--static` — menu de PAGES (nav « Mon compte »)** (2026-07-18) : réutilise le *look* sectionnav (rail à pastilles desktop / pilules horizontales sticky mobile) mais SANS scrollspy — les liens pointent vers des pages distinctes, l'item actif = la page courante posée côté serveur par WooCommerce (`.is-active` sur le `<a>`). Différences portées par le modificateur : barre mobile **toujours visible** (neutralise l'auto-masquage `.is-visible`, absent de scrollspy ici), **pas de ligne de progression** verticale (`::before` masqué → « pastilles sans ligne »), markup en `<ul>/<li>` (menu sémantique) avec reset liste + rétablissement de la géométrie `a:first-child` (chaque `<a>` étant l'unique enfant de son `<li>`), et dernier item « Déconnexion » **détaché + atténué + sans pastille**. Rendu : override `woocommerce/myaccount/navigation.php` ; recentrage de la pilule active mobile par `assets/js/account-nav.js` (`section-nav.js` non réutilisé — purement scrollspy). Voir `assets/css/account.css` (layout) + `style.css` (bloc `.pf-sectionnav--static`).
  - ⚠️ **Gotchas Kadence** (diagnostiqués via CDP sur la page réelle connectée — la page de test isolée ne charge PAS les CSS Kadence, donc ne les reproduit pas) : (1) `woocommerce-account.min.css` stylise `.account-navigation-wrap li a` (bord gauche 5px, padding, `color:inherit`) + impose un layout flottant 30/70 %, le tout à spécificité (0,2,2) qui **écrase le composant** (`.pf-sectionnav a`, 0,1,1). → On **supprime le conteneur Kadence `.account-navigation-wrap`** (`remove_action` des hooks `myaccount_nav_wrap_start`/`wrap_end`, cf. `pf_account_strip_kadence_nav_chrome()` dans `inc/recommendations.php`) : ces règles ne matchent plus, et la nav devient enfant flex direct de `.woocommerce` → son `position:sticky` desktop devient opérant sans hack (parent = colonne contenu, haute). (2) `woocommerce.min.css` force `.woocommerce-account .woocommerce-MyAccount-navigation { width:100% }` à ≤768px (0,2,0), écrasant le `width:100vw` full-bleed du composant (0,1,0) → barre sticky mobile rognée à la largeur de colonne (les marges négatives, elles, restaient correctes). → Shim (0,3,0) dans `account.css` ré-imposant `width:calc(100vw - var(--scrollbar-offset))` sur ≤1023px.
- **Espacement barre ↔ contenu (≤1023px) : idiome `.pf-sticky-bar`** (2026-07-28). Sur `/mon-compte`, la barre est le **premier enfant de la zone de contenu** : les 32px de `margin-top` de `#primary.content-area` (`--global-md-spacing`, défaut Kadence) tombaient donc **au-dessus** d'elle, et le contenu venait coller sous son bord — l'espace était du mauvais côté. Corrigé en reprenant ce que font les pages à barre sticky (`/catalogue`, `/auteurs`) : `.content-area { margin-top: 0 }` pour que la barre se cale **au ras du header**, et c'est la barre qui porte la respiration **en dessous** d'elle (`margin-bottom: var(--pf-space-6)`, comme `.pf-sticky-bar`). Scopé par `#primary:has(.pf-sectionnav--static)` — la page de connexion partage `.woocommerce-account` sans avoir de nav, et sa marge haute est comptée dans `--pf-auth-top` (même motif que le `:has(.pf-auth)` voisin, qui neutralise la marge basse). Desktop non concerné : le rail y est vertical, à côté du contenu. Mesuré : 0px au-dessus / 24px en dessous en 390 et 820px, au repos comme collé ; 1280px et `/connexion` inchangés.
- **Fondu de bord de la barre horizontale (≤1023px) : par MASQUE, plus par le dégradé peint** (2026-07-28, corrige les 4 pages à sectionnav — fiche livre, fiche événement, accueil du compte, liste de lecture). La barre est full-bleed (`width:100vw` + marges négatives) et son **padding compensatoire portait le rentrant du conteneur** : `.pf-scroll-fade` et le track étaient donc rentrés de 24px, et les dégradés `left:0`/`right:0` s'arrêtaient là. Mesuré sur `/mon-compte` à 390px : `x=365,5 → rgb(245,240,232)` (`--pf-cream-dark`, opaque) puis `x=366 → rgb(252,250,247)` (fond de la barre) — **couture franche + 24px de barre intacte au-delà**, aggravée par le fait que le fond réel est `color-mix(--pf-surface-alt 90%, transparent)` + `blur(6px)`, qu'un aplat opaque ne peut pas imiter.
  - **Correctif** : le rentrant passe de `.pf-sectionnav` à `.pf-sectionnav__track` → la zone de défilement couvre l'écran entier (les liens en sortent par le **bord du viewport**), `.pf-scroll-fade` aussi ; les `::before`/`::after` du composant global sont neutralisés (`content: none`) et remplacés par un `mask-image` sur le track, piloté par `--pf-sectionnav-fade-l/-r` que les classes `.is-scroll-*` font passer de `0` à **`3.5rem` (56px)**. *(⚠️ Depuis le 2026-07-29 ce masque local n'existe plus : il a été remonté dans le composant global — cf. B.9. Il ne reste ici que la longueur, `--pf-fade-size: 3.5rem`, dont le calibrage ci-dessous est inchangé.)* Cette longueur compense la disparition du padding latéral : le fondu part maintenant du **bord de l'écran**, alors que le dégradé peint commençait 24px plus loin — sa portée utile valait 24px de barre nue + ~30px de rampe = 54px depuis le bord, que 56px rétablit. Testé jusqu'à 5rem : au-delà, le fondu mord sur le libellé **actif** au lieu du seul voisin. Le fond de la barre n'est plus jamais touché → plus aucune couleur à tenir synchronisée. `section-nav.js` était déjà compatible (il soustrait `paddingLeft` du track pour caler le lien actif).
  - Le rentrant est exprimé par `var(--global-content-edge-padding, 1.5rem)` et **non** par `calc(50vw - 50%)` : un pourcentage se résout contre le bloc conteneur de l'élément qui l'**utilise** — ici `.pf-scroll-fade`, désormais large de `100vw`, où l'expression tomberait à 0. Valeur constante (24px) sur tout l'intervalle ≤1023px, la largeur max de contenu (1290px) n'y étant jamais atteinte (vérifié à 390/500/768/820/1023).
  - Les deux longueurs sont **enregistrées via `@property`** (`syntax: '<length>'`) : sans ça une custom property n'est pas typée et ne s'interpole pas — les stops du masque basculeraient d'un coup au lieu de suivre la transition de 0,25s héritée du dégradé. Navigateur sans `@property` : le masque fonctionne, seule la transition saute.
  - ⚠️ **Gotcha Kadence supplémentaire** : le reset `padding: 0` de `.pf-sectionnav--static` (0,2,0) masquait jusque-là que `content.min.css` pose `.single-content ul { padding-left: 2em }` (0,1,1 → 34px) sur le `<ul>` de la nav compte. Ce reset est désormais **scopé au desktop** (le track porte le rentrant en barre horizontale), donc la règle mobile doit être écrite `.pf-sectionnav .pf-sectionnav__track` (0,2,0) pour battre Kadence — sinon les deux pages compte se décalent de 10px par rapport au contenu.
  - **Vérifié** (CDP, 4 pages × 390/820/1280px) : nav/wrap/track tous à `x=0` et à la largeur du viewport en ≤1023px, `padding-left/right` 24px partout, fond uniforme d'un bord à l'autre (`rgb(252,251,247)` sur toute la ligne, couture disparue) ; en 1280px le rail vertical est inchangé (`mask: none`, `padding: 0`, `::before` intact, pas de défilement donc fondus jamais activés).

### B.11 `.pf-search--cat` — dimensions/couleurs unifiées /auteurs + /evenements (2026-07-14)

`.pf-search` expose désormais aussi `--search-font`/`--search-radius`/`--search-placeholder` (en plus de `--search-h`/`--search-icon`/`--search-inset`/`--search-clear`), pour permettre une nouvelle variante `.pf-search--cat` calée sur les dimensions ET couleurs de la barre `/catalogue` (`.pf-cat-search`, restée bespoke) : hauteur 34px, icône 16px, police 13px, radius `--pf-radius-md`, placeholder `--pf-text-dim` (au lieu du `--pf-muted` par défaut du composant — la couleur du texte saisi, `--pf-text`, était déjà commune aux deux). Utilisée par la recherche `/auteurs` (`Passiflore_Recherche_Auteurs`) et `/evenements` (liste `Passiflore_Events_Search` + carte `Passiflore_Events_Map`), pour une apparence de barre de recherche unifiée sur ces 3 pages. `.pf-search--sm` (recherche globale du header) reste inchangée — variante distincte, pas concernée par cet alignement.

⚠️ **Gotcha découvert en vérifiant** : sur `/evenements` (liste + carte), le reset TEC `.tribe-common input { font-size: inherit }` (spécificité (0,1,1)) bat `.pf-search-input { font-size: var(--search-font) }` (0,1,0) — le texte remontait au 17px de `body` au lieu des 13px voulus (radius/hauteur, eux, n'étaient pas touchés par ce reset). Corrigé par un shim (0,2,0) dans `events.css`/`events-map.css` (`.pf-ev-search .pf-search-input` / `.pf-map-search .pf-search-input { font-size: var(--search-font); }`), même famille de piège que le reset `.tribe-common *` déjà documenté (B.8). Vérifié via `getComputedStyle` + énumération des règles CSS correspondantes (iframes same-origin, cf. mémoire `visual-testing`) : les 4 barres (catalogue, auteurs, liste, carte) rendent bien 13px/34px/6px après correction.

### B.12 `.pf-map-pop` — shell d'infobulle d'événement partagé (2026-07-15, extraction)

Le SHELL de contenu de l'infobulle de la vue Carte (`.pf-map-pop` : en-tête + zone de scroll + rangée de tuiles `.pf-event-tile`) a été **remonté de `events-map.css` vers `style.css`** quand la vue **Mois sur mobile** en a eu besoin (motif désormais sur ≥ 2 pages → règle #2). Nom historique conservé (composant né avec la carte) pour éviter une réécriture de `events-map.js`.

- **Réparti** : le shell de contenu (`.pf-map-pop`, `__header`, `__scroll`, `__area-label/-count`, `__group`, `__venue-name/-city`, `__fade`, `__events`, tuile resserrée `zoom:0.9`) vit dans `style.css`. Le **carton extérieur** (fond/rayon/ombre/padding) + le positionnement restent **propres à chaque contexte** : wrapper Leaflet (`.leaflet-popup-content-wrapper`, `events-map.css`) côté carte, `.pf-month-pop-layer` (`events.css`) côté mois.
- **Vue Mois mobile** (`tribe/.../month/mobile-events/mobile-day.php` + `assets/js/events-month-mobile-pop.js`) : au tap sur un jour, un popup flottant s'affiche **au-dessus** du jour (repli en dessous si pas la place), en-tête = le jour, tuiles avec ligne « Lieu » (`render_tile` par défaut). Le panneau natif « événements en bas de grille » devient une **source de données cachée** (clonée dans le popup) : masqué par `.pf-month-pop-active …__mobile-day--show { display:none }` posée en JS sur `<html>` (repli sans JS = comportement natif). Un écouteur de **capture** sur `document` intercepte le tap et neutralise le handler natif de TEC (`stopPropagation`) — délégué, survit aux bascules de vue AJAX. Jour sans événement → rien.
  - **Largeur = contenu, plafonnée** (comme l'infobulle Leaflet `minWidth:0`/`maxWidth`) : le carton (`max-width` CSS = cap seul) reçoit sa largeur effective en JS (`fitWidth`) = largeur **naturelle** du contenu plafonnée au cap → un seul événement rétrécit le carton à la largeur d'une tuile + ses espacements, plusieurs atteignent le cap et défilent horizontalement. La mesure neutralise le temps d'un `width:max-content` les overflow scrollants (`.pf-hscroll` en X, `.pf-map-pop__scroll` en Y) qui ne contribuent sinon **aucune** largeur intrinsèque (le `max-content` s'effondrerait à l'en-tête seul).
- **Spécificité** : les règles doublées `.foo.foo` (0,2,0) restent nécessaires côté carte (popup rendu sous `.tribe-common` → reset (0,1,1)) ; côté mois le popup est ajouté à `<body>` (hors `.tribe-common`) donc non concerné, mais les mêmes règles s'appliquent sans dommage. Vérifié (CDP, iframe mobile 414px) : les 8 propriétés clés du shell (bord/padding en-tête, graisse label/titre, padding rangée…) gagnent bien sous `.tribe-common` après le déplacement — aucune régression de la vue Carte.
- **`window.pfScrollFade(root)`** : `scroll-fade.js` expose désormais une fonction de (re)câblage réutilisable (idempotente) pour le contenu injecté après chargement (le popup mois est cloné → invisible au scan `DOMContentLoaded`). Le scan initial l'appelle avec `document`.

### B.13 `.pf-toast` — notifications globales + token `--pf-z-toast` (2026-07-22)

Nouveau composant global réutilisable, né avec la fonctionnalité « signet liste de lecture sur les couvertures » (toasts « ajouté / retiré » de la liste, dont un retrait annulable). Composant transverse (pas lié à une page) → vit dans `style.css`.

- **API JS** : `window.pfToast.show( opts ) → handle` (`assets/js/pf-toast.js`, IIFE auto-enregistrante idempotente, modèle `pf-tooltip.js`). `opts` : `html` (markup de CONFIANCE — l'appelant échappe), `icon` (markup SVG de CONFIANCE → gouttière `.pf-toast__icon`), `duration` (ms, défaut 5000 ; **0 = illimité**), `actions` (`[{label, onClick} | {label, href}]` → `.pf-btn.pf-btn--primary.pf-btn--sm`), `onClose(reason)` (appelé une seule fois ; `reason ∈ 'timeout' | 'close' | 'action' | 'programmatic'`), `closeLabel`. `handle` : `{ el, dismiss(reason='programmatic') }`.
  - **`actions` — `href` produit un vrai `<a>`**, pas un `<button>` + `location.href` : clic milieu / « ouvrir dans un nouvel onglet » restent possibles. Ce lien **ne ferme pas** le toast (la navigation s'en charge ; un ctrl-clic doit laisser le toast en place). `onClick` reste la forme bouton, qui ferme avec `reason:'action'`.
  - ⚠️ **Le modificateur `--primary` est obligatoire, pas décoratif** : `.pf-btn` seul ne porte aucune couleur (cf. B.4 — « la couleur reste portée par le modificateur »). Un `<button>` nu paraissait correct **par coïncidence** (il héritait du bouton natif Kadence, de mêmes fond et couleur) ; le `<a>` ajouté ici, lui, retombait en simple lien rouge sans fond. Même convention que le lien des slides d'accueil (`.pf-actualite-lien.pf-btn.pf-btn--primary`, `front-page.php`).
- **Structure / style** : conteneur unique paresseux `.pf-toast-region` (`position:fixed` **bas-droite**, `z-index:var(--pf-z-toast)`, colonne flex, `pointer-events:none`) ajouté au `<body>` au 1er `show()` ; chaque `.pf-toast` (`role="status"`, verre plein `--pf-cream` + `--pf-shadow-float` + filet `--pf-border-light`, `--pf-radius-lg`) contient `.pf-toast__body` (colonne : `.pf-toast__main` + `.pf-toast__actions?` **sous le texte, centrées** via `align-self:center`) + `.pf-toast__close` (idiome `.pf-roundbtn`, `--pf-roundbtn-size:18px`, en haut à droite) + `.pf-toast__progress?`. `.pf-toast__main` est la **ligne icône + message** (`display:flex; align-items:center`) : `.pf-toast__icon?` (carré de 30 px en `--pf-accent`, `flex:none`, décoratif `aria-hidden`) + `.pf-toast__msg`.
  - **Emphase du message** : `.pf-toast__msg strong { font-weight: var(--pf-weight-semibold) }` — le 700 par défaut de `<strong>` sortait de l'échelle du système (titres de cartes, `<h1>`, `.pf-label` sont tous en semibold). Porté par le composant, donc valable pour tous ses appelants (titre d'ouvrage cité en `<strong><em>` par la liste de lecture et l'ajout au panier, phrase d'entête des toasts de session).
  - ⚠️ **Ce niveau `__main` n'est pas cosmétique** : l'icône doit se centrer sur le **seul bloc de texte**. Posée directement dans `.pf-toast` (sœur de `__body`), elle se centrerait sur *texte + boutons* et descendrait dès qu'une action est présente — d'autant plus visible que le message est court. `.pf-toast` reste en `align-items:flex-start` pour que le `×` tienne en haut à droite quelle que soit la hauteur. **Empilage** : nouveau toast `append` → il occupe le bas, les précédents remontent (bord bas épinglé + colonne, sans `column-reverse`). Entrée `@keyframes pf-toast-in` (2ᵉ keyframe du thème après `pf-spin`), sortie `.is-leaving` (transition opacité + collapse) ; `prefers-reduced-motion` neutralise l'animation. **Indicateur de temps** : `.pf-toast__progress` (barre 3 px en bas, `@keyframes pf-toast-progress` scaleX 1→0 avec `transform-origin:center` → se réduit symétriquement des deux côtés jusqu'à disparaître au centre, durée posée en inline par le JS) se vide sur la durée du toast et se met en **pause au survol/focus** via CSS (`animation-play-state`, mêmes conditions que le minuteur) ; purement visuel, le décompte réel reste le `setTimeout` JS (donc la fermeture marche aussi en reduced-motion, barre figée). **Pause du minuteur au survol/focus** (WCAG) ; `Échap` ferme ; non-modal (ne vole jamais le focus).
- **Variantes de statut `.pf-toast--success` / `--error` / `--warning` / `--info`** (opts.status, ajouté le 2026-07-27 avec le déport des notices WooCommerce — `assets/js/wc-notices-toast.js`, étendu le 2026-07-28 aux notices React des blocs Panier/Commande — `assets/js/wc-block-notices-toast.js`, d'où le statut `warning`, que leur conteneur sait produire) : la sévérité est portée par la **couleur de l'icône** (`--pf-success` / `--pf-danger` / `--pf-info`), le toast gardant son fond crème. Un aplat coloré pleine largeur en bas d'écran serait plus agressif que le bandeau de notice qu'il remplace, et le contraste du texte est déjà celui du composant. `status:'error'` passe en plus le toast en **`role="alert"`** (au lieu de `role="status"`) : un message bloquant ne doit pas attendre la fin de la lecture en cours — c'est aussi ce que portait le `<ul class="woocommerce-error">` d'origine. Une erreur est par ailleurs appelée avec `duration: 0` (fermeture manuelle, donc pas de barre de progression).
  - **Voile des notices déportées** (même bloc `style.css`) : `html.pf-notices-js` masque `.woocommerce-notices-wrapper`, `.wc-block-store-notices` (l'enveloppe que les blocs donnent au wrapper classique) et les conteneurs React `.wc-block-components-notices` / `.wc-block-components-notices__snackbar`. **Règle de périmètre : on ne reprend en toast que ce que cette règle masque** — donc jamais les erreurs de champ du tunnel (`.wc-block-components-validation-error`) ni les bannières composées à demeure dans une étape (`.wc-block-checkout__no-payment-methods-notice`, même classe de bannière mais hors conteneur). Classe posée par un primer inline (`inc/wc-notices-toast.php`, `wp_head` prio 1, **uniquement s'il y a une notice en file**) → masquage dès le premier paint, alors que le contrôleur vit en pied de page ; sans JavaScript la classe n'existe pas et les notices s'affichent normalement.
- **Token `--pf-z-toast: 10001`** (`:root`, `style.css`) — une couche au-dessus du max actuel du thème (10000, ex. mini-panier Kadence). Point d'entrée unique pour la couche « au-dessus de tout ».
- **Infobulle des signets `.pf-book-bookmark-tip`** (même bloc `style.css`) : élément flottant **global unique** en `position:fixed` (`z-index:calc(var(--pf-z-toast) - 1)`), positionné en JS depuis le rect écran du signet (`assets/js/shelf-bookmarks.js`) → **jamais rognée** par l'`overflow:hidden` de la couverture. Reprend le verre dépoli de `.pf-numerique-tip__bubble` (le composant infobulle existant reste couplé à un conteneur au `:hover` CSS, incompatible avec un flottant `fixed` + délai d'apparition de 1 s → on réutilise seulement la classe visuelle). Le **signet lui-même** (`.pf-book-bookmark`, `bookshelf.css`) est un enfant de `.pf-book-cover` (tourne avec la couverture au survol) ; deux tracés SVG superposés — silhouette pleine (`--pf-bm-fill`) avec un contour (`--pf-bm-stroke`, trait centré dessiné par-dessus le remplissage) qui trace tout le pourtour pour la détacher des couvertures + étoile par-dessus (`--pf-bm-star`) — inversés via `.is-in-list`. Couleurs : hors liste = corps `--pf-cream`, étoile + contour `--pf-bm-ink` (`#928369`, brun sourd propre au signet, var locale) ; en liste = corps `--pf-accent`, étoile + contour `--pf-cream`. **Au survol**, le signet prend les couleurs de l'état **inverse** (aperçu de ce que ferait le clic) à `opacity: 0.7`. Icône **version sharp** (coins droits, plus « réaliste »). ⚠️ Le `viewBox` (`175 -865 610 770`, `preserveAspectRatio="xMidYMin meet"`) est la bounding box du path **élargie d'une marge** : un viewBox collé pile au path rognait le contour (qui déborde du tracé) → il n'apparaissait pas. La hauteur contraint le rendu (élément 24×30) → haut du signet au bord haut de la couverture.

### B.14 Ajout au panier — chorégraphie en trois temps (2026-07-28)

Retour visuel à l'ajout au panier, complémentaire du toast (B.13) : le toast confirme *quoi*, le reste montre *où*. Règles dans `style.css` (bloc header/panier), orchestration dans `assets/js/add-to-cart-toast.js` — seul endroit du site où un ajout AJAX se produit (le bouton du hero de la fiche livre).

**Enchaînées, pas simultanées.** Trois retours lancés ensemble se marchent dessus ; lancés dans l'ordre, ils forment une phrase : `clic → le livre s'envole (560 ms) → le serveur confirme → l'icône pulse et le toast paraît`.

- **Le vol part au CLIC, pas à la réponse serveur** — c'est son vrai apport, pas la décoration : il couvre la latence de la requête, pendant laquelle il ne se passait rien. Optimiste ; si l'ajout échoue, `added_to_cart` ne se déclenche pas et WooCommerce remonte une notice, déportée en toast.
- **La pulsation attend le serveur** : elle accompagne un compteur dont seul le serveur connaît le total, elle part donc sur `added_to_cart` — au plus tard à l'atterrissage du dernier objet, `pendingFlight` étant un `Promise.all` des vols en cours.

**1. `.pf-fly-book` — le livre en vol.** Clone jetable `position:fixed` du **volume entier** du hero (dos, bandeau de pages, couverture), pas de la seule couverture ; une **copie**, le livre restant en rayon — on achète un exemplaire. `z-index: calc(var(--pf-z-toast) - 1)` : au-dessus du header sticky, où vit sa cible, sinon il disparaîtrait juste avant d'atterrir.

- **Origine adaptative** (`flightOrigin()`, commune à tous les objets envoyés) : le livre du hero **s'il est réellement visible**, sinon le bouton. `visibleEnough()` ne teste pas l'intersection avec le viewport mais la hauteur visible **sous `--pf-sticky-offset`** : sur mobile, quand le bouton d'achat est à l'écran, le rect du livre touche encore le viewport (bas à 41 px) alors qu'il est entièrement masqué par le header (148 px).
- **Taille MESURÉE après insertion, jamais supposée** : la source peut être le livre du hero (redimensionné en JS d'après la hauteur du texte) ou le contenu inerte d'un `<template>`, qui n'a aucune boîte tant qu'il n'est pas inséré. Le clone garde sa taille naturelle et c'est **l'échelle de départ** qui varie (`FLY_SEED_PX / W` depuis le bouton) — d'où un raisonnement en **centres**, invariants sous `scale`. Aucune dimension n'est imposée en CSS : les trois niveaux s'ajustent au livre, ce qui fait voler indifféremment le livre (271×391) et la liseuse (162×252).
- ⚠️ **Le clone a besoin de son contexte d'étagère.** Les 83 règles qui dessinent le livre sont scopées sous `.pf-bookshelf--covers` / `--hero`, et c'est sur `.pf-bookshelf` que sont déclarées les variables du dessin (`--pf-obl` l'angle de fuite, `--pf-backcover`, `--plank-color-*`…). Détaché, le clone perdrait dos, bandeau et projection. `__stage` recrée donc cet ancêtre — **un seul suffit**, aucune de ces règles n'utilisant de combinateur enfant (vérifié). Son habillage de panneau (fond crème, cadre, ombre) est neutralisé par `.pf-fly-book .pf-bookshelf` en (0,2,0), qui bat `.pf-bookshelf` (0,1,0) sans `!important` et quel que soit l'ordre de chargement des feuilles.
- ⚠️ **Ne pas cloner `.pf-shelf-books`** — c'est ce qui rend le clone invisible au contrôleur d'étagère : `relayoutAll()` (`bookshelf.js`) balaie `document.querySelectorAll('.pf-bookshelf')`, mais `fitBookshelf()` sort immédiatement sans cet enfant, et `repackShelves()` ne vise que `.pf-bookshelf--shelves` (classe que `__stage` ne porte pas). Vérifié : le livre du hero mesure 271×391 avant **et** après un ajout, aucun reflow.
- Le clone est **neutralisé** : `role`, `tabindex`, `aria-label` et `data-trigger-flipbook` retirés (le livre du hero est un bouton qui ouvre la liseuse), `aria-hidden`, et l'affordance de survol `.pf-hero-flip-hint` (« EXTRAIT → ») supprimée.
- ⚠️ **Trois niveaux imbriqués, un par courbe** (X sur le conteneur, Y sur `__y`, échelle/rotation/opacité sur `__stage`) : `transform` ne sait pas donner deux courbes aux deux axes d'un même nœud, et **c'est leur décalage qui fait l'arc**. Une première version à un seul nœud, avec une image-clé intermédiaire et deux easings enchaînés, produisait « élan, temps mort, ruée » — mesuré : 90 % de la montée en 25 % du temps, puis quasi immobile jusqu'à 75 %. Chaque axe étant monotone, aucun temps mort n'est possible. X en `ease-in`, Y en `ease-out` avec un dépassement de `FLY_ARC_PX` (24 px) au-dessus du panier avant d'y retomber. Arc mesuré : 84 → 105 → 89 → 55 px au-dessus de la ligne droite.
- L'**opacité** a son animation propre (rien pendant 78 %, puis extinction) : sans elle le livre disparaîtrait d'un coup à l'arrivée.
- **Fidélité vérifiée** : clone figé à l'échelle 1 sur la position du livre, original masqué → dos, bandeau, couverture et bloc intérieur aux **mêmes coordonnées au dixième de pixel**, `--pf-obl` identique. Le diff d'image ne montre que des liserés de contour, signature du ré-échantillonnage dû à la promotion en couche de composition — pas un écart de rendu.

**1 bis. Le vol ne sort jamais de l'écran.** Sans bornage le livre passait **64 px au-dessus du haut du viewport** (mesuré à 1280×900, au tiers du trajet) : il est encore grand quand il atteint la hauteur du panier, donc son bord haut dépasse. Deux mécanismes, complémentaires :

- **Trajectoires échantillonnées** (`FLY_STEPS` = 24 points, easing `linear` entre eux) au lieu d'un `easing` confié au navigateur : c'est la seule façon d'appliquer un bornage qui dépend de **l'échelle à l'instant t** — un livre déjà réduit peut monter plus haut sans sortir. D'où `bezier()`, qui évalue en JS les mêmes courbes que le CSS ; elles sont déclarées en **tableaux** (`EASE_X`, `EASE_Y1`…) et non en chaînes, pour qu'il n'existe qu'une source entre ce que le navigateur anime et ce que le calcul suppose.
- **Bornes élargies aux deux extrémités du vol** : le livre du hero peut lui-même être à moitié sous le header sticky (`visibleEnough` l'autorise), et le repousser au départ provoquerait un saut. La règle exacte est donc « le vol ne sort jamais de l'écran **plus que ses propres extrémités** ». Si les bornes se croisent (objet plus grand que l'écran), la valeur brute est conservée plutôt que de sauter d'un bord à l'autre. Vérifié à 1280×900, 1600×1000, 900×700 et 500×850, avec et sans liseuse : **0 px de débordement sur les quatre bords**.
- ⚠️ **`cartTarget()` exige une cible DANS le champ, pas seulement présente.** Le header n'est pas collant à toutes les largeurs : à 1024 px c'est le header **mobile** qui s'affiche et il défile avec la page — le panier se retrouve à −797 px après 900 px de défilement (mesuré). Un vol vers cette cible sortirait de l'écran pour atterrir là où personne ne regarde. Sans cible visible, **pas de vol du tout** : le toast confirme, ce qui suffit. *(La non-adhérence du header mobile à 1024 px est un comportement du site, antérieur à cette animation.)*

**1 ter. La liseuse suit le livre** (quand l'offre « version numérique » est retenue — deux produits partent alors au panier).

- **Markup dans un `<template>`** rendu par `pf_numerique_render_offer_checkbox()` (`inc/numerique-offer.php`), qui y injecte `[passiflore_etagere ids="<numerique_id>" hero="true"]`. Le contenu d'un `<template>` est **inerte** : rien n'est rendu, le script inline du shortcode ne s'exécute pas, les images ne sont pas téléchargées et `relayoutAll()` ne le voit pas. Coût page : le seul markup.
- **Réchauffage à la coche** : le contenu d'un `<template>` n'étant jamais téléchargé, l'écran de la liseuse serait blanc au décollage. Le `change` de la case précharge donc ses images (`new Image()`), et le vol part au clic suivant — quelques centaines de ms plus tard. Rien n'est chargé si l'offre n'est pas retenue.
- **`FLY_STAGGER` = 220 ms** : la liseuse décolle bien avant que le livre n'atterrisse (mesuré : livre à 592 ms, liseuse à 809 ms) — on lit une suite, pas deux animations séparées.
- ⚠️ **`fill: 'both'` et non `'forwards'`** sur toutes les animations du vol : avec un retard au départ, la première image-clé doit déjà s'appliquer **pendant** l'attente, sinon la liseuse resterait visible en haut à gauche de l'écran, à sa taille pleine, jusqu'à son décollage.

**2. `@keyframes pf-cart-bump` — l'icône pulse.** `.is-bumping` sur la `<span>` `.kadence-svg-iconset` : `scale` 1 → `var(--pf-cart-bump)` à 30 % → `.94` à 55 % → 1, sur 420 ms. Le creux évite un dégonflage linéaire (elle « retombe »). `transform` seule → **aucun reflow**.

- **Sur l'icône, jamais sur le bouton** (choix acté le 2026-07-28) : l'icône *est* le panier — la pastille n'apparaît qu'à partir d'un article et se contente de changer de chiffre. Posé sur `.header-cart-button`, l'effet emportait aussi la pastille. Vérifié : pendant la pulsation, la pastille se déplace de **0,00 px** en x comme en y.
- **`--pf-cart-bump: 1.5`** (var locale, déclarée sur l'élément animé — c'est là qu'un `var()` de `@keyframes` se résout). Mesuré : icône 19,2 px au repos → 28,8 px au sommet, qui déborde de 4,8 px sous la pastille. Celle-ci étant un frère suivant, elle est peinte par-dessus : le recouvrement ne se voit pas, et il ne dure qu'un instant.
- **Une animation d'encaissement** (l'icône qui s'enfonce à l'atterrissage) a existé puis été **abandonnée** le 2026-07-28 : elle chargeait le geste sans rien ajouter à la lecture. Elle avait révélé un piège à retenir si l'on veut un jour superposer deux animations sur le même élément : **`animation` est une propriété UNIQUE** — deux règles qui la déclarent ne s'additionnent pas, la cascade en garde une seule et l'autre ne tourne jamais (constaté : `scale` restait à `none`). Les lister ensemble ne suffit pas davantage, une animation dont l'index bouge dans la liste repart de zéro. Il faut deux éléments imbriqués.

**`playOnce( el, cls )`** — pose la classe, la retire à `animationend` :

- ⚠️ **`animationend` ne se déclenche pas si aucune animation ne tourne** : élément en `display:none` (l'icône de l'autre header) ou `prefers-reduced-motion` (`animation: none`). Sans le garde-fou `getAnimations().length`, la classe restait posée indéfiniment — inerte, mais trompeuse à l'inspection (constaté en test).
- Le `.then()` de la chaîne sert aussi de **report d'un tour de boucle** : une microtâche s'exécute après **tous** les gestionnaires synchrones du `trigger()` — donc après le remplacement des fragments WooCommerce, et indépendamment de l'ordre des gestionnaires (le script dépend de `jquery`, pas de `wc-add-to-cart`).
- `prefers-reduced-motion: reduce` : pas de vol du tout, `animation: none` sur la pulsation, toast immédiat.

### B.15 Étagère — mobilier responsive et facteur de réduction commun (2026-07-28)

Audit des espacements d'étagère sur 4 pages × 7 largeurs (390 → 1400). Deux défauts, un même point commun : **tout le mobilier de l'étagère était absolu**, aucune media query ne le touchait (la seule de `bookshelf.css` ne réglait que `--pf-obl` et le chevalet de prix). Le mobile héritait donc du mobilier du bureau sur un écran 3,6× plus étroit.

**`--pf-plank-inset` — un token pour trois nombres liés.** Le retrait latéral de la planche (`margin`), le retrait **arrière** de son dessus (`clip-path`) et le padding latéral de la rangée ne sont pas indépendants : mesuré à 1400px, l'arête arrière du bois tombe à 41px du bord et le premier livre commence à 41px — le pied du livre est **calé sur l'arête de sa planche**, par construction. Les désaccorder décolle l'un de l'autre. D'où un token unique, le padding s'exprimant en `calc(var(--pf-plank-inset) * 2)`. Le hero (`book-single.css`) impose un padding plus généreux : **l'invariant est un minimum (`padding >= 2 × retrait`), pas une égalité**.

**Valeurs mobiles (`max-width: 767px`)** — `--pf-plank-inset` 20 → 8px, `--plank-h` 44 → 32px (58 → 40 avec chevalet), `--plank-front` 8 → 6px, `gap` 18 → 12px, `padding-top` 40 → 18px. Effets mesurés à 390px : largeur disponible **262 → 310px**, et écart entre une planche et le livre du dessous **92 → 54px**, soit **34 % → ~20 % de la hauteur d'une rangée** (le ratio sur ordinateur est de 29 %). Les 92px se décomposaient en 52px de **zone morte** (la part de la boîte de la planche que remonte son `top: calc(-0.9 * var(--plank-h))`, que personne ne voit) et 40px de padding : réduire `--plank-h` agit sur les deux à la fois, sans casser la construction (le pied du livre reste à 85 % de la hauteur de la bande de bois).

⚠️ **Le `padding-top` ne se réduit qu'en shelves + couvertures**, trois exclusions chacune mesurée : en **scroll** le rayon rogne au padding-box et `computeRevealShift` ne peut redescendre le livre saisi que de la valeur du padding-bas (3px) — ce dégagement haut est le seul rempart ; en **dos** la bascule au survol fait monter l'encre de **55px** pour les livres les plus profonds (contre 17px en couvertures), il faut donc garder 69px de dégagement ; le **hero** (32px) et l'**étagère de recommandations** (64px, badge flottant) sont exclus explicitement plutôt qu'en pariant sur l'ordre de chargement des feuilles.

**Facteur de réduction commun à l'étagère** (`FIT_PERCENTILE`, `bookshelf.js`). `fitBookshelf()` réduisait **livre par livre** : chaque livre trop large sortait exactement à la largeur disponible, donc un album de 360 mm et un roman de 220 mm rendus **identiques** — 36 livres sur 146 à 390px —, à côté de leurs voisins restés à taille vraie. Les tailles relatives, tout l'intérêt d'une étagère, disparaissaient. Un facteur unique par étagère les rétablit.

Il se cale sur le **p95** des empreintes et non sur la plus grande : un unique très grand format (*Tabaco y oro*, 360 mm, 2,2× la médiane) imposerait sa réduction à tout le catalogue. Les ~5 % au-dessus restent écrêtés individuellement — sans quoi ils déborderaient, `#wrapper` étant en `overflow: clip`.

**Plage utile [0,95 ; 1,00] et rien d'autre** : sous 0,95 le facteur sature à 1 et seul le nombre d'écrêtés augmente, c'est-à-dire qu'on ne fait plus que réintroduire le défaut corrigé. Mesuré à 390px — 1,00 → livre médian 138px, 1,45 livre/rangée, 0 écrêté ; **0,95 (retenu) → 173px, 1,04 livre/rangée, 10 écrêtés** ; 0,90 → aucun gain. Le curseur arbitre entre « les livres restent grands » et « ça redevient une étagère ». Tout ceci est **inerte au-dessus de 768px**, où tous les livres tiennent déjà (facteur 1, vérifié : 1 235 livres comparés, 0 écart géométrique).

⚠️ Le percentile porte sur les livres **affichés** : sur /catalogue, filtrer peut donc changer la taille de tous les livres. C'est cohérent en interne (les proportions restent justes dans ce qui est montré) ; l'alternative serait de calculer la référence sur l'ensemble du catalogue et de la passer depuis le PHP.

**`--shelf-inner` supprimé** (CSS, JS **et** PHP). Le `min-height` qu'il alimentait valait `book-h + 52` pour une hauteur naturelle de `book-h + 73` : il était **inerte** (forcé à 0 sur 146 rangées → 0 changement de hauteur, total identique). Il fallait le retirer **avant** de réduire le `padding-top`, qui fait tomber la hauteur naturelle à `book-h + 51` et l'aurait réveillé — ajoutant de la hauteur exactement là où on en enlève. Bénéfice annexe : une passe complète de lecture de géométrie en moins par relayout, sur le chemin optimisé de 950 ms à 35 ms.

**Point de rupture corrigé 768 → 767px** (règle 6 du design system) : à 768px pile, l'ancien `max-width: 768px` appliquait encore l'angle de fuite mobile alors que tout le reste du site y est déjà en « tablette ».

**Deux tokens de plus, même logique de valeurs liées** (ajoutés dans la foulée) :

- **`--pf-chevalet-reserve`** (30px, 22px sous 767px) — la bande réservée **devant** le pied du livre pour le chevalet de prix. Étant absolue, elle ne suivait pas la planche mobile amincie : l'assise du livre dans le bois était tombée à 8 % de la profondeur à 390px contre 33 % sur ordinateur, le pied affleurant l'arête arrière. Réserve **et** `--plank-h` du cas chevalet (40 → 44px) corrigés ensemble → assise de nouveau à 33 %. L'étiquette « À paraître » s'en **dérive** (`+ 4px`) au lieu d'être un troisième nombre à resynchroniser.
- **`--pf-reveal-scale`** (1,1 par défaut, abaissé par livre en JS) — le mode dos dessine tout 1,5× plus grand pour la lisibilité des dos ; une fois le livre retourné cette taille vaut pour sa **couverture**, qui atteignait 693px pour 308px disponibles à 390px. `computeRevealShift()` plafonne l'agrandissement de la saisie à ce qui tient dans le rayon. ⚠️ Le facteur vit à **trois** endroits qui doivent rester d'accord (transform, `perspective-origin`, décalages JS) ; l'invariant d'atterrissage a été vérifié au millième après coup (bandeau ÷ épaisseur = `--pf-obl`).
- **`--pf-spines-scale`** (1, **0,8 sous 767px**, scopé `.pf-bookshelf--spines`) — ramène le rendu du mode dos de 1,5× à 1,2×, les dos étant trop hauts sur un téléphone. Le viewport étant inconnu du PHP, le seuil reste **en CSS** et le JS ne fait que lire la valeur. ⚠️ S'applique à **tout** l'intérieur du dos — dimensions, corps du texte (le PHP émet `--pf-spine-fs` au lieu d'un `font-size` en dur), marges de `.pf-spine-generated` (dont le total reste synchronisé avec `SPINE_PAD_Y`) et logo — pour que la mise en page de `spine_layout()` reste juste **par similitude**. Scopé au mode dos car les dos générés existent aussi en mode couvertures, où ils ne doivent pas rétrécir.

**Deux règles de mouvement qui en découlent :**

- **`.pf-book--releasing`** (classe transitoire, 520 ms, posée par `bookshelf.js`) — le retour au repos depuis la saisie emprunte la **durée et la courbe de l'aller** (0,5s) au lieu des 0,3s du repos. L'asymétrie était invisible tant que le livre saisi n'était que 1,1× plus grand ; avec `--pf-reveal-scale` le retour cumule un changement d'échelle de ~1,7× et une longue course, et se lisait comme un claquement. Le CSS ne sait pas distinguer « je quitte la saisie » de « je quitte la bascule » (qui doit rester à 0,3s), d'où la classe. ⚠️ Posée dans `onLeave()` **et** `closeTouchBook()` : sur téléphone le livre se referme par un tap à côté. ⚠️ Elle doit aussi figurer dans la règle **`z-index: 10`** : c'est le seul état qui couvre le retour, et sans elle le livre retombe à `z-index: 2` dès le premier pixel, couverture encore ouverte → celle-ci passe derrière ses voisins de droite et se lit comme **étirée vers la gauche**. **Repli ininterruptible** : `pointer-events: none` sur la classe (sous `@media (hover: hover)`) — revenir au curseur en plein vol annulait la classe et cassait le retour ; c'est le seul levier qui neutralise vraiment `:hover`, et il coupe aussi le `mouseenter`, donc rien à garder côté JS. Diagnostiquer par l'**ordre de peinture**, jamais par `elementFromPoint` (la couverture est `pointer-events: none` hors saisie, la sonde la traverse). **Durées = `--pf-reveal-dur` / `--pf-release-dur`** (0,5s chacune sur `.pf-bookshelf`, **0,8s sous 767px** — le coût fixe de démarrage du vol pèse d'autant moins que l'animation est longue) — une par sens, indépendantes, à mettre à `15s` pour observer l'animation au ralenti (y compris en direct depuis les devtools). La première pilote les six transitions du dévoilement ; la seconde, source unique lue par les règles CSS **et** par `bookshelf.js` (qui en dérive la durée de vie de la classe, marge comprise) — le minuteur JS ne règle donc pas la vitesse de l'animation. ⚠️ La règle de relâchement **redéclare `perspective: var(--persp)` et la commute sans délai**, en (0,3,0) placée après `:hover` : la formule de caméra n'est exacte que sous cette perspective, et si celle de la bascule (`--pf-tip-persp`) gagne ne serait-ce qu'une image, le dos fantôme passe de 22 à 254 px et le livre part vers la gauche.
- **`.pf-chevalet` centré** entre le pied du livre et le devant de planche (`top` + `translate(-50%, -50%)`, `bottom: auto` explicite) au lieu de `bottom: 0`, qui ne laissait qu'un cheveu entre le livre et le prix (1px à 390px) alors que le bois continuait dessous.

---

## C. Plan d'exécution

1. ✅ **Valider ce document** (polices titre serif/sans, radius boutons, échelle).
2. ✅ **Customizer Kadence** : `palette1` → `#c62836` fait.
3. ✅ **`style.css`** : bloc `:root` de tokens + règles de base `h1-h6` + composants (`.pf-btn`, `.pf-card`, `.pf-badge`, `.pf-search`, `.pf-switch`, `.pf-dropdown`, `.pf-sticky-bar`, `.pf-label`…).
4. ✅ **Refactor composant par composant** — exécuté le 2026-07-02 selon `docs/audit-css-2026-07-02.md` (newsletter fusionné → catalogue → events → accueil → account/cart/checkout/mes-avis → book-single), vérification visuelle par captures avant/après à chaque étape. Restes assumés : `bookshelf.css`/`pageflip.css` (modules 3D isolés), fusion éventuelle de `.pf-cat-search` sur `.pf-search` (reportée — comportement bespoke), préfixe `.bs-` gelé.
5. ✅ **Nettoyer** `CLAUDE.md` (rouge + palette10 + règles CSS impératives) — fait le 2026-07-02.
