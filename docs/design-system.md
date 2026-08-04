# Système de design — Éditions Passiflore

> Référence des tokens CSS, composants `.pf-*` et règles de layout. Règles d'usage impératives : voir CLAUDE.md § « CSS — Design System ». Historique de l'audit ayant produit ce système (juin-juillet 2026, entièrement exécuté) : `docs/audit-css-2026-07-02.md`.

---

## Historique (résumé)

Avant juillet 2026, l'identité visuelle (couleurs, polices, boutons, radius) était dispersée en valeurs dupliquées dans ~15 fichiers CSS de composants, sans source unique (customizer Kadence et `style.css` racine quasi vides). Audit du 2026-07-02 : rouge de marque unifié sur `#c62836` (palette1), tokens et composants globaux consolidés dans `style.css` selon le système ci-dessous — entièrement exécuté (voir Plan d'exécution en fin de document). Détail historique de l'audit : `docs/audit-css-2026-07-02.md`.

---

## Système cible (implémenté dans `style.css`)

Principe : décider une fois, au bon endroit, puis tout fait consommer ces décisions. Ce que Kadence sait gérer → customizer. Le reste → un bloc `:root` de tokens dans `style.css`.

### Couleurs — source unique

Toujours `var(--global-paletteN)`, jamais de hex en dur, sans fallback (ou fallback = la vraie valeur).

| Variable | Hex | Rôle (token `--pf-*`) |
|---|---|---|
| `--global-palette1` | `#c62836` | Rouge Passiflore (`--pf-accent`) |
| `--global-palette2` | `#a0212c` | Rouge foncé hover (`--pf-accent-dark`) |
| `--global-palette3` | `#5e524d` | Texte courant (`--pf-text`) |
| `--global-palette4` | `#1a1615` | Titres (`--pf-heading`) |
| `--global-palette6` | `#666666` | Texte secondaire (`--pf-muted`) |
| `--global-palette7` | `#f5f0e8` | Crème soutenu (`--pf-cream-dark`) |
| `--global-palette8` | `#faf6f0` | Fond crème principal (`--pf-cream`) |
| `--global-palette9` | `#fcfbf7` | Fond crème clair (`--pf-surface-alt`) |
| `--global-palette10` | `#e0d8cc` | Bordures/filets (`--pf-border`) |

Sable historique `#D9C8B0` → `--pf-sand` (hors palette, distinct de `--pf-border`).

### Polices — 2 familles

| Token | Valeur | Usage |
|---|---|---|
| `--pf-font-heading` | `Georgia, 'Times New Roman', serif` | titres principaux de contenu |
| `--pf-font-body` | `system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif` | corps de texte, UI, étiquettes |

Serif pour les titres principaux (voix « maison d'édition »), sans pour l'UI et les étiquettes uppercase. `system-ui` préféré à Inter (aucun téléchargement, homogène).

### Échelle typographique

```css
--pf-text-xs:   0.75rem;   /* étiquettes uppercase */
--pf-text-sm:   0.875rem;  /* méta, légendes */
--pf-text-base: 1rem;      /* corps */
--pf-text-lg:   1.125rem;  /* chapô, sous-titres */
--pf-text-xl:   1.5rem;    /* titres de section */
--pf-text-2xl:  2rem;      /* titres de page */
--pf-text-hero: clamp(2.6rem, 4.2vw, 4.8rem); /* hero accueil */
```

### Titres — classes découplées des balises

La balise `h1`–`h6` porte la structure (SEO/a11y, un seul `h1`/page) ; une classe porte l'apparence, indépendante du niveau. Défauts de base dans `style.css` (pas le customizer, pour rester versionnés) :
```css
h1,h2,h3,h4,h5,h6 { line-height: 1.1; font-weight: 600; }
h1 { font-family: serif; font-size: 2rem; }
h2..h6 { font-family: sans; /* tailles décroissantes */ }
```
(Aperçu éditeur de blocs nécessite `add_editor_style()` — suivi non fait.)

| Classe | Police | Casse | Taille | Weight | Usage |
|---|---|---|---|---|---|
| `.pf-titre-1` | serif | normale | `--pf-text-2xl` (ou hero) | 600 | titre de page/livre/section éditoriale |
| `.pf-titre-2` | sans | UPPERCASE (court) | `--pf-text-xl` | 600 | séparateurs de section |
| `.pf-label` | sans | UPPERCASE | `--pf-text-xs` | 400 | étiquette réutilisable (nav, rôle d'auteur, dates, méta) — couleur laissée au contexte |

Règle : l'uppercase ne s'applique qu'à des titres/étiquettes courts (lisibilité + accents capitales français). `.pf-label` remplace `.pf-section-titre`, `.pf-etagere-titre`, `.pf-auteur-*-titre`, `.bs-section__title`, `.bs-auteur-role`, `.bs-hero__authors-line`, `.bs-lies-title`, `.bs-media-section h3`, `.bs-avis-section h3`.

### Boutons — 3 variantes, tokens uniques

```css
--pf-radius:        3px;
--pf-btn-pad:       0.65em 1.2em;
--pf-btn-pad-sm:    0.3em 0.7em;
--pf-btn-weight:    var(--pf-weight-normal); /* 400 */
--pf-btn-transition: background .2s, border-color .2s, color .2s;
```

| Variante | Fond | Texte | Bordure | Hover |
|---|---|---|---|---|
| primaire | `--global-palette1` | `#fff` | idem fond | fond `--global-palette2` |
| secondaire (outline) | transparent | `--global-palette1` | `1px --global-palette1` | fond `rgba(198,40,54,.08)` |
| neutre | `#fff` | `--global-palette3` | `1px --global-palette10` | bordure+texte `--global-palette1` |

Base `.pf-btn` + modificateurs `.pf-btn--primary/--outline/--neutral/--sm/--block`. Token `--pf-red-soft` pour le survol translucide.

`.pf-btn--sm` calé sur `.pf-cat-trigger` : padding `--pf-space-2 --pf-space-3` (8×12px), 13px, `line-height:1.2`, radius `--pf-radius-md`. La couleur reste portée par le modificateur ; les états propres au trigger (actif/ouvert, caret) restent sur le trigger. `--pf-btn-pad-sm` ne sert plus qu'au petit bouton natif Kadence (`.button.button-size-small`).

Deux règles de refactor :
1. Bouton custom (`<a>`/`<button>`) → classes `.pf-btn .pf-btn--…`, CSS local réduit au contextuel (largeur, marge, flex, `:disabled`/`.is-…`).
2. Bouton WooCommerce/plugin (`.button`…) → garder le sélecteur existant (souvent composé, pour battre la spécificité WC), pointer ses valeurs sur les mêmes tokens.

Défauts boutons natifs (Gutenberg `.wp-block-button__link`/`.wp-element-button`, Kadence `.button`/`.button-style-fill`/`.button-style-outline`) :
- Forme (radius, weight, `box-shadow:none`, transition) : règles sur les classes natives dans `style.css`.
- Couleur : Kadence route tout par `--global-palette-btn-bg`/`-btn`/`-hover`/`-out` (défaut or). Redéfinies dans `:root` avec `!important` **sur la définition de variable** (jamais sur le fond) → bat le CSS inline du customizer sans bloquer les surcharges par page.
- Padding/font-size natifs laissés à Kadence/l'admin (réglage par bloc Gutenberg).

### Espacement

```css
--pf-space-1:.25 --pf-space-2:.5 --pf-space-3:.75 --pf-space-4:1 --pf-space-5:1.25
--pf-space-6:1.5 --pf-space-8:2 --pf-space-10:2.5 --pf-space-12:3 --pf-space-16:4  (rem)
```
Règle : ne tokeniser que les espacements structurels en rem (margin/padding/gap). Laisser : valeurs `em` (padding de boutons/badges, relatifs au texte), `calc()` de positionnement, micro-valeurs de composant. Ne pas tokeniser `font-size`/`width`/`height`/`border-radius` avec cette échelle. Hors périmètre volontairement : écarts icône↔libellé internes à un contrôle, panneau de filtres mobile.

### Radius

```css
--pf-radius: 3px;       /* boutons + champs de formulaire */
--pf-radius-md: 6px;    /* contrôles : déroulants, switches, barres */
--pf-radius-lg: 8px;    /* panneaux, tableaux */
--pf-radius-card: 12px; /* tuiles : auteurs, événements, livres associés */
```
Hors échelle assumés : `999px` (pilules/chips), `50%` (boutons ronds), `5px` (polaroïd du hero).

### Tokens & composants globaux (2026-07-02)

**Tokens :** `--pf-sand` (#D9C8B0, sable historique, distinct de `--pf-border`) ; `--pf-shadow-stuck`/`--pf-shadow-float` (ombres sticky/flottants) ; `--pf-success/info/warning/danger(-bg)` (statuts, hors palette de marque) ; `--pf-red-soft(-strong)` et `--pf-accent-border-soft` (en `color-mix()` sur `--pf-accent`, suivent le customizer) ; `--pf-control-h: 38px` (hauteur contrôle de barre, surchargée par barre — catalogue 40/34px) ; `--pf-panel-pad`.

**Composants :**
- `.pf-panel` (+ `--alt`, `--danger`) — panneau encadré (bordure claire, radius-lg, fond blanc/crème). Panneaux WooCommerce : sélecteurs conservés, mêmes tokens.
- `.pf-quote` (+ `--accent`) — encart à filet gauche (avis, réponse éditeur).
- `.pf-notice` (+ `--error`, `--success`) — bandeau de statut.
- `.pf-empty` — message « aucun résultat », voix commune aux 4 recherches du site (auteurs, catalogue/étagère, recherche globale, événements). Centré, `--pf-muted`, italique (état, pas contenu). ⚠️ Shim `(0,2,0)` obligatoire dans `events.css` (`.pf-empty.pf-empty`) : le reset `.tribe-common …, p, …` pose `padding:0` en `(0,1,1)` et bat la classe seule. Réservé à l'absence de résultat ; les repères de défilement infini événements gardent `.pf-ev-msg`, plus discret.
- `.pf-roundbtn` — bouton rond rouge à icône crème (scroll infini événements, fermeture recherche globale).
- `.pf-spinner` + `@keyframes pf-spin` — anneau de chargement unifié.
- Filet d'attente des recherches AJAX + `@keyframes pf-loading-sweep` — trait de 2px parcouru d'une lueur `--pf-accent`, retour « ces résultats sont périmés » commun aux recherches, à la place d'un spinner (isolé, hors écran une fois les résultats longs). Quatre hôtes, chacun ne posant que le bord auquel il se colle : `.pf-gsearch-results.is-loading::before` (haut, recherche globale) ; `.pf-sub-header.is-loading::after` (bas, /evenements liste+carte, /auteurs, /catalogue — AJAX **et** rechargement complet). Sur /auteurs et /catalogue, s'ajoute à l'estompage de grille (`.is-loading`, opacité 0,5) — deux messages distincts. ⚠️ Ne jamais poser `position` sur `.pf-sub-header` : le filet doit hériter de la boîte full-bleed de son hôte sticky, pas du contenu centré. ⚠️ Le sous-header de /evenements est en « smart-hide » (`opacity:0` replié) : le retour se pose là où le résultat va apparaître (filet du sous-header pour une nouvelle recherche, spinner en bas des résultats pour le lot suivant) — jamais les deux à la fois.
- `.pf-switch--solid` — variante actif en aplat rouge (sélecteur de vue TEC).
- `.pf-section-titre` — ornements des titres de section (trait rouge / titre-lien flèche ➜).
- Bloc newsletter fusionné dans `style.css` (site-wide).
- Marqueur « nouvel onglet » — flèche ➜ (U+279C, -45°) automatique via `a[target="_blank"]:not(.pf-card-link):not(.social-button)::after` ; critère = `target="_blank"`, pas « externe ». Exclusions : liens étirés vides `.pf-card-link` (flèche épinglée en haut à droite via `:has()`) et liens-icônes `.social-button`. ⚠️ La rotation impose `inline-block` → boîte atomique pour la coupure de ligne (UAX #14), peut partir seule en début de ligne suivante : correctif = `white-space:nowrap` **local** sur le libellé concerné, jamais changer le glyphe. ⚠️ Mention d'accessibilité obligatoire (`pf_new_window_note()`/`pf_new_window_label()`, `inc/a11y.php`, WCAG 3.2.5). ⚠️ L'espace séparateur vit **dans** le `.screen-reader-text`, avant la parenthèse — hors du span il rallonge le flux visible.

⚠️ **Gotcha TEC récurrent** : `.tribe-common *` (0,1,0), chargé après `style.css`, écrase les composants globaux à spécificité égale sur les pages événements → shim `(0,2,0)` dans `events.css` avec les mêmes tokens (ex. switch Liste/Mois).

### `.pf-scroll-fade` — ombres de bord

Composant pour toute rangée à défilement horizontal (tuiles événements/auteurs, nav de section mobile, étagères en mode scroll). Acquis d'office pour toute étagère rendue par `Passiflore_Bookshelf::render_scroll()`.

- Mécanisme : `mask-image` sur le wrap (remplace un dégradé peint). Longueur = `--pf-fade-size`, en `clamp()` — croît avec la largeur d'écran, plafonné à une valeur calée sur la taille du contenu défilé (pas de rampe démesurée sur un petit item, pas de rampe étriquée sur une grande carte). Défaut `clamp(28px, 4.3vw, 60px)` (tuiles événement 250px) ; surcharges : tuiles auteur 200px `clamp(24px, 3.4vw, 48px)`, rangée de filtres catalogue `clamp(20px, 2.3vw, 32px)`, infobulle carte 36px (fixe, popup à largeur bornée par JS plutôt que par le viewport), nav de section mobile `clamp(40px, 5.5vw, 56px)` (plancher 40px = padding latéral 24px + rampe minimale, sous peine de coupure nette avant le premier libellé), étagères scroll 40px ≤1023px/28px ≤767px. Longueurs animées `--pf-fade-l`/`--pf-fade-r` enregistrées via `@property … syntax:'<length>'` (sinon pas d'interpolation, le fondu apparaît d'un bloc).
- Pourquoi un masque : un dégradé peint doit connaître le fond et devient faux sur fond translucide/flouté (barres sticky en verre) ; un masque laisse voir ce qu'il y a derrière, sans jamais connaître la couleur.
- Markup : le wrap s'intercale entre le CADRE et le scroller (`.pf-bookshelf → .pf-scroll-fade → .pf-shelf`) — masquer le cadre effacerait aussi sa bordure/son ombre. Le wrap doit rester transparent à la mise en page (`flex:1; min-height:0; display:flex; flex-direction:column` là où un ancêtre flex étire le scroller).
- Exception : `.pf-cat-row-scroll` (rangée de filtres catalogue) scrolle elle-même (pas de wrap), état géré par son propre contrôleur (`catalogue.js`, `.has-fade-*`).
- États : `.is-scroll-left`/`.is-scroll-right`, posés par `assets/js/scroll-fade.js`.
- ⚠️ **Un masque ROGNE ce qui déborde du wrap** (contrairement au dégradé peint, qui ne faisait que peindre par-dessus). Tout ce qu'un descendant peint hors de la boîte de bordure du wrap disparaît (ex. pastilles de `.pf-sectionnav` positionnées en `left` négatif, hors du wrap). Correctif : `mask-image:none` sur l'hôte concerné, au point de rupture où rien ne défile réellement. Avant d'ajouter un hôte : vérifier que rien d'utile ne déborde du wrap.
- ⚠️ **Le wrap ne doit JAMAIS être l'élément qui scrolle lui-même** — un pseudo-élément absolu dont le containing block est l'élément `overflow:auto` fait partie de sa propre zone de scroll (le fondu se déplace avec le contenu). Toujours deux éléments : wrap non-scrollable + son unique enfant scrollable (`firstElementChild`).
- Recâblage pour contenu injecté : `window.pfScrollFade(root)` (idempotent) — à appeler après insertion AJAX/clonage (`bookshelf.js` l'appelle en fin d'`init()`, après `relayoutAll()`).

### `.pf-sectionnav` — nav à sections partagée (fiche livre + fiche événement)

- Structure : `.pf-body` (grille `160px | 1fr` desktop ≥1024px, empilée en dessous) contenant `nav.pf-sectionnav` (rail vertical sticky à points + ligne de progression sur desktop ; barre horizontale sticky full-bleed « verre » ≤1023px, masquée jusqu'au scroll via `.is-visible`) + `.pf-sections` (`section.pf-section`, `scroll-margin-top` = header+nav). Point de bascule = 1024 (règle 6 du design system).
- PHP (`inc/section-nav.php`, découplé) : `pf_sectionnav_bar($sections)` (barre `<nav>` + primer inline `--pf-sticky-offset`/`--pf-sectionnav-h`, `''` si < 3 sections) et `pf_sectionnav_sections($sections)` (blocs `.pf-section`), ancre = `sanitize_title($label)`. `pf_render_sectionnav($sections)` (fiche livre) les combine dans `.pf-body` ; la fiche événement compose en 2 zones via `passiflore_get_event_sections_parts()`. Pose aussi `body.no-anchor-scroll`.
- JS : `assets/js/section-nav.js` — anchor-pin + scrollspy `IntersectionObserver` (`.is-active`) + visibilité/hauteur nav mobile, keyé `.pf-sectionnav`/`.pf-section`.
- ⚠️ **La mise en page ne doit pas dépendre de l'item ACTIF** (il passe en semibold, donc s'élargit). Là où la piste de la colonne nav est dimensionnée sur son contenu (`grid-template-columns:[nav] auto`), le libellé actif élargirait la colonne et décalerait le contenu. Correctif : un double invisible en gras (`.pf-sectionnav a::after { content:attr(data-label); font-weight:var(--pf-weight-semibold); height:0; overflow:hidden; visibility:hidden }`, `data-label` recopié côté PHP) — la largeur du lien est toujours celle du gras, indépendante de l'état. Pistes fixes (fiche livre 160px, compte 200px) non concernées.
- **Variante `.pf-sectionnav--static`** (nav « Mon compte ») : même look, SANS scrollspy — actif = page courante posée côté serveur. Différences : barre mobile toujours visible, pas de ligne de progression (pastilles sans ligne), markup `<ul>/<li>`, dernier item « Déconnexion » détaché/atténué/sans pastille. Override `woocommerce/myaccount/navigation.php` ; pilule active recentrée en mobile par `account-nav.js`.
  - ⚠️ Gotchas Kadence : (1) `woocommerce-account.min.css` stylise `.account-navigation-wrap li a` à (0,2,2), bat le composant (0,1,1) → on **supprime le conteneur Kadence** (`remove_action` sur `myaccount_nav_wrap_start`/`wrap_end`), ce qui rend aussi le sticky desktop opérant sans hack. (2) `woocommerce.min.css` force `width:100%` sur `.woocommerce-MyAccount-navigation` ≤768px (0,2,0), écrase le `width:100vw` du composant (0,1,0) → shim (0,3,0) dans `account.css`.
- Espacement barre↔contenu (≤1023px) : idiome `.pf-sticky-bar` — `#primary.content-area { margin-top:0 }` (scopé `:has(.pf-sectionnav--static)`), la barre porte sa propre `margin-bottom`. Desktop non concerné (rail vertical).
- Fondu de bord de la barre horizontale (≤1023px) : même masque `.pf-scroll-fade` (voir ci-dessus), `--pf-fade-size:3.5rem` sur cet hôte. Rentrant exprimé via `var(--global-content-edge-padding, 1.5rem)`, pas `calc(50vw - 50%)` (se résoudrait à 0 contre l'élément lui-même, désormais large de 100vw).
  - ⚠️ Gotcha Kadence : `content.min.css` pose `.single-content ul { padding-left:2em }` (0,1,1) sur le `<ul>` de la nav compte → le reset `padding:0` du composant doit être scopé desktop et re-déclaré à (0,2,0) via `.pf-sectionnav .pf-sectionnav__track` pour la barre mobile.

### `.pf-search--cat` — dimensions/couleurs unifiées /auteurs + /evenements

`.pf-search` expose `--search-font`/`--search-radius`/`--search-placeholder` (+ `--search-h`/`--search-icon`/`--search-inset`/`--search-clear`) pour la variante `.pf-search--cat`, calée sur `/catalogue` (`.pf-cat-search`, restée bespoke) : hauteur 34px, icône 16px, police 13px, radius `--pf-radius-md`, placeholder `--pf-text-dim`. Utilisée par `/auteurs` et `/evenements` (liste + carte). `.pf-search--sm` (recherche globale header) reste une variante distincte.

⚠️ **Gotcha TEC** : `.tribe-common input { font-size:inherit }` (0,1,1) bat `.pf-search-input { font-size:var(--search-font) }` (0,1,0) → shim (0,2,0) dans `events.css`/`events-map.css`.

### `.pf-map-pop` — shell d'infobulle événement partagé

Le SHELL de contenu (en-tête + zone de scroll + rangée de tuiles `.pf-event-tile`) vit dans `style.css` ; le carton extérieur (fond/rayon/ombre/padding) et le positionnement restent propres à chaque contexte (wrapper Leaflet dans `events-map.css` côté carte, `.pf-month-pop-layer` dans `events.css` côté mois mobile).

- **Vue Mois mobile** : au tap sur un jour, popup flottant au-dessus (repli en dessous si pas la place), tuiles avec ligne « Lieu » masquée par défaut. Le panneau natif TEC devient une source de données cachée, clonée dans le popup. Écouteur de capture sur `document` intercepte le tap, neutralise le handler natif.
- Largeur = contenu, plafonnée : JS (`fitWidth`) mesure la largeur naturelle (overflow scrollants neutralisés le temps de la mesure) puis applique un `max-width` CSS.
- Spécificité : règles doublées `.foo.foo` (0,2,0) nécessaires sous `.tribe-common` (carte) ; le popup mois, hors `.tribe-common`, n'en a pas besoin mais les tolère.
- `window.pfScrollFade(root)` (voir ci-dessus) recâble le fondu pour le contenu de popup injecté après coup.

### `.pf-toast` — notifications globales + `--pf-z-toast`

- API JS : `window.pfToast.show(opts) → handle` (`assets/js/pf-toast.js`). `opts` : `html` (markup de confiance), `icon` (SVG de confiance), `duration` (ms, défaut 5000, **0 = illimité**), `actions` (`[{label,onClick}|{label,href}]` → `.pf-btn.pf-btn--primary.pf-btn--sm`), `onClose(reason)` (`reason ∈ 'timeout'|'close'|'action'|'programmatic'`), `closeLabel`. `handle` : `{el, dismiss(reason)}`.
  - `href` produit un vrai `<a>` (préserve ctrl-clic), ne ferme **pas** le toast au clic (la navigation s'en charge) ; `onClick` = bouton qui ferme en `reason:'action'`.
  - ⚠️ Modificateur `--primary` **obligatoire** : `.pf-btn` seul ne porte aucune couleur (cf. section Boutons) — un `<a>` sans lui retombe en simple lien rouge sans fond.
- Structure : singleton paresseux `.pf-toast-region` (fixed bas-droite, `z-index:var(--pf-z-toast)`, colonne flex, `pointer-events:none`), ajouté au 1ᵉʳ `show()`. Chaque `.pf-toast` (`role="status"`, verre `--pf-cream` + `--pf-shadow-float` + `--pf-border-light`, `--pf-radius-lg`) = `.pf-toast__body` (`__main` icône+message, `__actions?` centrées dessous) + `.pf-toast__close` (idiome `.pf-roundbtn`, 18px) + `.pf-toast__progress?`.
  - `.pf-toast__msg strong` forcé à `--pf-weight-semibold` (le 700 natif sortait de l'échelle).
  - Barre de progression purement visuelle (le vrai minuteur est le `setTimeout` JS), pause au survol/focus (WCAG). `Échap` ferme ; non-modal.
- Variantes `.pf-toast--success/--error/--warning/--info` (`opts.status`) : icône reste `--pf-accent` quel que soit le statut (cohérence de marque > code couleur de sévérité). `status:'error'` → `role="alert"` + `duration:0` (fermeture manuelle).
- Voile des notices WooCommerce déportées : `html.pf-notices-js` (posée par primer inline `wp_head`, seulement s'il y a une notice en file) masque `.woocommerce-notices-wrapper`, `.wc-block-store-notices`, `.wc-block-components-notices`/`__snackbar`. Règle de périmètre : on ne toaste que ce que cette classe masque — jamais les erreurs de champ ni les bannières composées à demeure dans une étape.
- Token `--pf-z-toast: 10001` — une couche au-dessus du max précédent (10000, mini-panier Kadence). Point d'entrée unique pour « au-dessus de tout ».
- `.pf-book-bookmark-tip` (infobulle signet liste de lecture) : flottant global unique, `position:fixed`, `z-index:calc(var(--pf-z-toast) - 1)`, positionné en JS depuis le rect du signet (`shelf-bookmarks.js`) — jamais rogné par l'overflow d'une couverture. Réutilise seulement la classe visuelle de `.pf-numerique-tip__bubble`.
- `.pf-distinctions-tip` (infobulle distinctions, étagères filtrées) : même moule flottant, mais **interactive** — pas de `pointer-events:none`, `role="dialog"` + `tabindex="-1"`, `--pf-distinctions-tip__content` isolé (scroll + `padding-right` épinglé) pour que la croix reste fixe au coin. Deux régimes d'ouverture (survol + clic/clavier, comme `.pf-numerique-tip`) :
  - **survol** → aperçu transitoire (pas de focus volé), se referme ~200ms après le départ du pointeur du bouton **et** de la bulle (délai pour traverser le GAP, la liste étant scrollable) ;
  - **clic/clavier** → épinglée (`.is-pinned`) : ignore le survol, ne se ferme qu'au clic ailleurs/Échap/scroll/resize ; affiche la croix `.pf-distinctions-tip__close` (idiome `.pf-roundbtn` 18px, coin haut-droit) qui ferme et rend le focus au bouton. Un clic sur un aperçu déjà ouvert au survol l'épingle au lieu de le fermer.
  - ⚠️ Plafond haut = `--pf-sticky-offset` (jamais le bord du viewport). ⚠️ Le scroll de fermeture (capture) doit ignorer la bulle elle-même. Contenu transporté par livre dans un `<template>` inerte, cloné à l'ouverture. ⚠️ Contrôleur enqueué **sans condition** par le catalogue (grille rechargée en AJAX).

### Ajout au panier — chorégraphie en trois temps

Trois retours **enchaînés, pas simultanés** (`assets/js/add-to-cart-toast.js`, seul site AJAX-add : bouton hero fiche livre) : `clic → vol du livre (560ms) → confirmation serveur → icône pulse + toast`.

- Le vol part **au clic**, pas à la réponse serveur (couvre la latence de la requête ; optimiste — un échec saute juste `added_to_cart`, la notice WooCommerce est déportée en toast comme d'habitude).
- `.pf-fly-book` : clone jetable `position:fixed` du **volume entier** du hero (dos, bandeau, couverture) — une copie, l'original reste en rayon. `z-index:calc(var(--pf-z-toast) - 1)`.
  - Origine adaptative : le livre du hero s'il est réellement visible **sous** `--pf-sticky-offset` (pas seulement intersectant le viewport), sinon le bouton.
  - Taille **mesurée après insertion**, jamais supposée (source = livre redimensionné en JS ou contenu inerte de `<template>`) ; raisonnement en **centres** (invariant sous `scale`).
  - ⚠️ Le clone a besoin du contexte `.pf-bookshelf` (règles/variables scopées : angle de fuite, épaisseur de plats, couleurs) → un `__stage` recrée un seul ancêtre de ce type (suffisant, aucune règle n'utilise de combinateur enfant) ; son habillage de panneau propre est neutralisé à (0,2,0).
  - ⚠️ **Ne jamais cloner `.pf-shelf-books`** — rend le clone invisible au contrôleur d'étagère (`relayoutAll()`/`fitBookshelf()`/`repackShelves()`).
  - Neutralisé comme contrôle (`role`/`tabindex`/`aria-label`/`data-trigger-flipbook` retirés, `aria-hidden`, affordance de survol supprimée).
  - ⚠️ **Trois niveaux imbriqués, un axe par courbe** (X sur le conteneur, Y sur `__y`, échelle/rotation/opacité sur `__stage`) : `transform` ne peut pas donner deux courbes à deux axes d'un même nœud — c'est leur décalage qui fait l'arc. Un seul nœud avec image-clé intermédiaire produit un « élan, temps mort, ruée » ; chaque axe doit rester monotone.
  - L'opacité a sa propre animation tardive (sinon le livre disparaît d'un coup à l'arrivée).
- Le vol reste **borné au viewport** : trajectoires **échantillonnées** (24 points, easing évalué en JS avec les mêmes courbes que le CSS) plutôt qu'un easing natif — le bornage dépend de l'échelle à l'instant t (un livre réduit peut monter plus haut). Bornes élargies aux deux extrémités par l'étendue propre de la source/cible (jamais de saut si l'origine est déjà partiellement hors écran) ; si les bornes se croisent, la valeur brute est gardée.
  - ⚠️ **`cartTarget()` exige une cible réellement DANS LE CHAMP**, pas seulement présente (ex. à 1024px pile le header mobile non-collant peut avoir fait défiler l'icône panier hors écran). Pas de cible visible → pas de vol (le toast seul confirme).
- Offre numérique compagne (case cochée) : markup dans un `<template>` inerte, réchauffé (images préchargées) à la coche, décolle `FLY_STAGGER=220ms` après le livre. ⚠️ `fill:'both'` (pas `'forwards'`) sur toutes les animations du vol — avec un délai de départ, la première image-clé doit déjà s'appliquer pendant l'attente.
- `@keyframes pf-cart-bump` : `.is-bumping` sur la `<span class="kadence-svg-iconset">` **uniquement**, jamais le bouton (la pastille de compte est une sœur, ne doit pas bouger) — `scale` 1 → `var(--pf-cart-bump,1.5)` à 30% → 0.94 à 55% → 1, 420ms (`transform` seul, aucun reflow).
- `playOnce(el, cls)` : pose une classe, la retire à `animationend`. ⚠️ `animationend` ne se déclenche jamais si aucune animation ne tourne (`display:none`, `prefers-reduced-motion`) → garder un `getAnimations().length` sinon la classe reste posée indéfiniment (inerte mais trompeur).
- ⚠️ **`animation` est une propriété unique** : deux règles qui la déclarent sur le même élément ne s'additionnent pas, la cascade n'en garde qu'une. Deux effets superposés demandent deux éléments imbriqués, jamais deux règles sur un seul.
- `prefers-reduced-motion:reduce` : pas de vol, pas d'animation de pulsation, toast immédiat.

### Étagère — mobilier et facteurs de taille (voir aussi CLAUDE.md § Bookshelf)

- `--pf-plank-inset` — un seul token pour trois nombres liés (marge latérale de planche, retrait arrière `clip-path`, padding latéral de rangée = `calc(*2)`) ; le pied du livre est calé sur l'arête de la planche. Mobile (≤767px) : 20→8px (avec `--plank-h` 44→32/58→40px chevalet, `--plank-front` 8→6px, `gap` 18→12px, `padding-top` 40→18px). Invariant : `padding >= 2×inset` (minimum, pas égalité — le hero utilise un padding plus généreux).
  - ⚠️ `padding-top` ne se réduit qu'en shelves+couvertures : le mode scroll a besoin du dégagement pour la saisie, le mode dos pour la bascule (~69px), hero et étagère de recommandations gardent leurs valeurs propres.
- `FIT_PERCENTILE` (`bookshelf.js`) — un seul facteur de réduction par étagère, calé sur le **p95** des empreintes (pas le max, qui imposerait sa réduction à tout le catalogue pour un seul format hors norme) ; le top ~5% reste écrêté individuellement (`#wrapper` en `overflow:clip`). Plage utile [0,95 ; 1,00]. Inerte (facteur 1) au-dessus de 768px.
- `--shelf-inner` — supprimé (CSS+JS+PHP) : le `min-height` qu'il alimentait était forcé à 0 (inerte). Ne pas le réintroduire pour « corriger » un vide vertical perçu — `min-height` ne peut qu'agrandir une rangée, jamais la resserrer.
- `--pf-chevalet-reserve` (30px, 22px ≤767px) et le `--plank-h` du cas chevalet (40→44px mobile) doivent varier **ensemble** — l'assise du livre en dépend.
- `--pf-reveal-scale` (défaut 1,1, abaissé par livre en JS) — plafonne l'agrandissement de la couverture révélée en mode dos pour tenir dans le rayon ; vit à **trois** endroits qui doivent rester d'accord (transform de saisie, `perspective-origin`, décalages JS).
- `--pf-spines-scale` (1, **0,8 ≤767px**, scopé `.pf-bookshelf--spines`) — ramène le mode dos de 1,5× à 1,2× sur téléphone (seuil en CSS, JS ne fait que lire). ⚠️ S'applique à **tout** l'intérieur du dos (dimensions, `--pf-spine-fs`, marges de `.pf-spine-generated` synchronisées à `SPINE_PAD_Y`, logo) pour que `spine_layout()` reste juste par similitude.
- Caméra du vol repliée dans la `transform` (`--pf-cam-x`/`--pf-cam-y`, posés par `bookshelf.js`) : `perspective-origin` est la seule propriété du vol non compositable — animée directement elle tremble sur mobile (horloges fil principal/compositeur désynchronisées). Équivalence exacte via un cisaillement en z prépendu à la matrice.
- `.pf-book--releasing` (classe transitoire ~520ms, posée dans `onLeave()` **et** `closeTouchBook()`) : le retour au repos depuis la saisie emprunte la durée/courbe de l'aller (`--pf-reveal-dur`/`--pf-release-dur`, 0,5s / 0,8s ≤767px) plutôt que le 0,3s du repos — sinon le retour (échelle ~1,7×, longue course) claque. ⚠️ Doit figurer dans la règle `z-index:10` (sinon le livre retombe derrière ses voisins dès le premier pixel du retour, couverture encore ouverte = effet « étiré ») et poser `pointer-events:none` (sous `hover:hover`) pour empêcher un survol de l'annuler en vol. ⚠️ La règle de relâchement doit re-déclarer `perspective:var(--persp)` et commuter **sans délai**, à spécificité supérieure à `:hover` — sinon la perspective de bascule (`--pf-tip-persp`) peut gagner une image et fausser la géométrie d'un ordre de grandeur.
- `.pf-chevalet` centré via `top` + `translate(-50%,-50%)`, `bottom:auto` explicite (pas `bottom:0`, qui ne laissait presque aucun espace).

### `.pf-epub-*` — lecteur ePub

Un seul consommateur : `/mon-compte/livres-numeriques`.

- **Ne partage pas** `.bs-flipbook-overlay` (`book-single.css`) : legacy `.bs-*` gelé, `rgba()` en dur, géométrie couplée au canevas pixels fixes de StPageFlip (epub.js reflue en pourcentage). Ne promouvoir un `.pf-overlay` partagé que si un 3ᵉ overlay plein écran apparaît.
- Fond **OPAQUE** (`var(--pf-heading)`) — décision : à 94% d'opacité (valeur du flipbook), bandeau bêta et newsletter restaient lisibles au travers.
- Réutilise `.pf-roundbtn`/`--outline`, `--pf-adminbar`, tokens `--pf-*` — seule surcharge locale : encre de `--outline` (pensée pour fond clair, ici sur fond sombre).
- Tuiles du compte (`.pf-account-tiles`) : grille fluide `auto-fill, minmax(240px,1fr)` de `.pf-card` à lien étiré (motif de `.pf-hero-cat` accueil). ⚠️ Shim nécessaire sur `.pf-account-logout` : Kadence pose `.single-content p { margin-top:0 }` (0,1,1), bat un sélecteur à classe unique.

### `.pf-footer-legal` — liens légaux du footer

Rendu par `[pf_footer_legal]` (`inc/shortcodes.php`), inséré dans `footer_html_content` (Customizer) sous la ligne de copyright — mécanisme identique à `header_html_content` → `[passiflore_account_btn]`. Résolution par slug de page, silencieuse si absente/non publiée (ex. CGV libraires tant qu'en brouillon). Pas de token de couleur dédié : hérite du lien du footer (`footer_html_link_style`) ; seul `--pf-text-sm` distingue la ligne du copyright.

- **Séparateur « · »** (`.pf-footer-legal__sep`, `aria-hidden`) : item flex à part entière, pas du texte inline — le `gap` du conteneur (`--pf-space-2 --pf-space-3`) espace alors liens ET séparateurs uniformément. Masqué (`visibility:hidden`, jamais `display:none` — garde sa place dans le `gap`, pas de ré-mesure en cascade) par `assets/js/footer-legal.js` quand il tombe pile sur un retour à la ligne (comparaison `offsetTop` du lien précédent/suivant) : CSS seul ne sait pas détecter qu'un repli vient de se produire à cet endroit précis. Repli sans JS : tous les séparateurs restent visibles, y compris ceux en bord de ligne.
- Espaces **insécables** (`\u{00A0}`) à l'intérieur de chaque libellé à plusieurs mots (même ceinture que « Mon compte » dans `header-hooks.php`) : un lien ne doit jamais se couper lui-même en deux lignes, seul le `gap` entre liens/séparateurs est un point de repli valide.

---

## Plan d'exécution

Entièrement exécuté : palette customizer (`palette1`→`#c62836`), bloc `:root` de tokens + règles de base + composants dans `style.css`, refactor composant par composant (newsletter→catalogue→events→accueil→account/cart/checkout/mes-avis→book-single), nettoyage CLAUDE.md. Restes assumés : `bookshelf.css`/`pageflip.css` (modules 3D isolés), `.pf-cat-search` non fusionné sur `.pf-search` (bespoke), préfixe `.bs-` gelé. Détail de l'exécution : `docs/audit-css-2026-07-02.md`.
