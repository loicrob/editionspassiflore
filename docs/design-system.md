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
- `.pf-roundbtn` — bouton rond rouge à icône crème (scroll infini événements, fermeture recherche globale).
- `.pf-spinner` + `@keyframes pf-spin` — anneau de chargement unifié.
- `.pf-switch--solid` — variante du switch à actif en aplat rouge (sélecteur de vue TEC).
- `.pf-section-titre` — ornements des titres de section (trait rouge / titre-lien flèche ➜), ex-doublon `.pf-etagere-titre` supprimé.
- Bloc newsletter fusionné dans style.css (ex-`newsletter.css`, chargé partout).

**Gotcha TEC** : le reset `.tribe-common *` (0,1,0, chargé après style.css) écrase les composants globaux (0,1,0) sur les pages événements → shims (0,2,0) dans events.css, mêmes tokens (cf. switch Liste/Mois).

### B.9 `.pf-scroll-fade` — ombres de bord (2026-07-09, globalisation)

Le composant existait déjà (tuiles événements/auteurs sur accueil, fiche auteur, fiche livre) mais vivait dans `events.css` sous le nom `.pf-event-tiles-wrap`, couplé aux classes JS `pf-scroll-left`/`pf-scroll-right`. Remonté dans `style.css` sous un nom générique car réutilisé sur un 4ᵉ contexte sans rapport avec les événements (nav de section mobile, fiche livre).

- **Composant** : `.pf-scroll-fade` (`position:relative` + `::before`/`::after` en dégradé, 60px, épinglés aux bords) — voir `style.css`. Couleur via la variable locale `--pf-scroll-fade-color` (défaut `--pf-cream`), surchargeable en une ligne selon le fond du contexte (ex. `.bs-tab-auteurs .pf-scroll-fade`, `.pf-sectionnav .pf-scroll-fade`).
- **États** : `.is-scroll-left` / `.is-scroll-right`, bascules par `assets/js/scroll-fade.js` (ex-`event-tiles.js`, renommé) selon `scrollLeft`/`scrollWidth`/`clientWidth` de `wrap.firstElementChild`.
- ⚠️ **`.pf-scroll-fade` (le wrap) ne doit JAMAIS être aussi l'élément qui scrolle** — piège rencontré en l'appliquant directement sur `.pf-sectionnav__track` (nav de section mobile, structure à un seul élément à l'origine) : un pseudo-élément `position:absolute` dont le *containing block* est l'élément `overflow:auto` lui-même fait partie de sa propre zone de scroll (le dégradé se déplaçait avec le contenu au lieu de rester épinglé). Le wrap doit rester `overflow:visible` (jamais scrollable), l'élément qui scrolle est toujours son **enfant direct unique** (`firstElementChild`) — structure à deux éléments dans les 4 usages actuels. Vérifié : `getComputedStyle(wrap).overflowX === 'visible'` + `getBoundingClientRect()` du wrap identique avant/après scroll du enfant (la valeur *déclarée* `left:0` ne suffit pas à le prouver, elle ne bouge jamais qu'on soit épinglé ou non — seule la position réellement rendue le montre).
- Script handle renommé `pf-event-tiles` → `pf-scroll-fade` (enqueue : `functions.php` ×2, `inc/accueil.php`).

### B.10 `.pf-sectionnav` — nav à sections partagée (2026-07-09, extraction)

Le layout « nav sticky + scrollspy » de la fiche livre (préfixe legacy `bs-*`) a été **extrait en composant partagé** `style.css` quand la fiche événement en a eu besoin (motif désormais sur ≥ 2 pages → règle #2 du design-system). La fiche livre a été migrée `bs-*` → `pf-*` dans la foulée (rendu identique, vérifié par captures).

- **Composant** : `.pf-body` (grille `160px | 1fr` sur desktop ≥1024px, empilée sur mobile+tablette) contenant `nav.pf-sectionnav` (verticale sticky à points + ligne de progression sur desktop ≥1024px ; barre horizontale sticky full-bleed « aéro » sur mobile+tablette ≤1023px, masquée jusqu'au scroll via `.is-visible`) + `.pf-sections` (`section.pf-section` à `scroll-margin-top` = header + nav). Point de bascule = breakpoint 1024 du design-system (relevé de 599 le 2026-07-10 pour inclure la tablette). Voir `style.css` (« Section-nav »).
- **Rendu PHP** (`inc/section-nav.php`, **découplé**) : `pf_sectionnav_bar($sections)` (la barre `<nav>` + primer inline `--pf-sticky-offset`/`--pf-sectionnav-h`, `''` si < 3 sections) et `pf_sectionnav_sections($sections)` (les blocs `<section class="pf-section">`), avec ancre/id = `sanitize_title($label)`. `pf_render_sectionnav($sections)` (fiche livre) les combine dans `.pf-body` (nav + sections) si ≥ 3, sinon les sections seules pleine largeur. La fiche événement les compose en **2 zones** (nav commune, sections top/bot) via `passiflore_get_event_sections_parts()` — cf. CLAUDE.md. Le découplage sert justement à ré-utiliser UNE barre qui liste toutes les sections tout en rendant celles-ci dans plusieurs conteneurs. Pose aussi `body.no-anchor-scroll` (neutralise le scroll d'ancre de Kadence).
- **Contrôleur JS** : `assets/js/section-nav.js` (anchor-pin au chargement + scrollspy `IntersectionObserver` `.is-active` + visibilité/hauteur de la nav mobile), keyé `.pf-sectionnav`/`.pf-section`, inerte si absentes. Enqueué sur fiche livre + fiche événement.
- Variable renommée `--pf-bs-sectionnav-h` → `--pf-sectionnav-h`. Le toggle « Voir tout » des sous-listes reste propre à la fiche livre (inline dans `book-single-tabs.php`, sélecteur `.bs-avis-section, .pf-section`). Classes de **contenu** livre `bs-*` (ex. `.bs-section__body`) conservées.

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

---

## C. Plan d'exécution

1. ✅ **Valider ce document** (polices titre serif/sans, radius boutons, échelle).
2. ✅ **Customizer Kadence** : `palette1` → `#c62836` fait.
3. ✅ **`style.css`** : bloc `:root` de tokens + règles de base `h1-h6` + composants (`.pf-btn`, `.pf-card`, `.pf-badge`, `.pf-search`, `.pf-switch`, `.pf-dropdown`, `.pf-sticky-bar`, `.pf-label`…).
4. ✅ **Refactor composant par composant** — exécuté le 2026-07-02 selon `docs/audit-css-2026-07-02.md` (newsletter fusionné → catalogue → events → accueil → account/cart/checkout/mes-avis → book-single), vérification visuelle par captures avant/après à chaque étape. Restes assumés : `bookshelf.css`/`pageflip.css` (modules 3D isolés), fusion éventuelle de `.pf-cat-search` sur `.pf-search` (reportée — comportement bespoke), préfixe `.bs-` gelé.
5. ✅ **Nettoyer** `CLAUDE.md` (rouge + palette10 + règles CSS impératives) — fait le 2026-07-02.
