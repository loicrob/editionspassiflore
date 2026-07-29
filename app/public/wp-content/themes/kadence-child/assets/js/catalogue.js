/* Passiflore Catalogue — UI controller (vanilla JS) */
(function () {
	'use strict';

	const URL_KEYS = {
		search: 'search', orderby: 'tri', order: 'sens', format: 'format',
		public: 'public', type: 'type', langues: 'langues', decouvrir: 'decouvrir', display: 'affichage',
	};
	// Internal state values → URL values (display only).
	const URL_VALUE_OUT = { display: { covers: 'couvertures', spines: 'dos' } };
	// `tranches` reste accepté en entrée (alias legacy, anciens liens partagés/marque-pages).
	const URL_VALUE_IN  = { display: { couvertures: 'covers', dos: 'spines', tranches: 'spines' } };

	const DEFAULTS = { orderby: 'date', order: 'DESC', display: 'covers' };
	const MULTI_FILTERS = new Set(['langues', 'type']);

	const UNIVERS_LABELS = { 'litterature': 'Littérature', 'culture-sud-ouest': 'Culture Sud-Ouest' };
	// Formats pouvant afficher des livres numériques (rendus en liseuse) :
	// l'affichage dos n'a pas de sens pour eux → option spines grisée.
	const NO_SPINES_FORMATS = ['tous', 'numerique'];
	const FORMAT_SWITCH_SLUGS = ['grands-caracteres', 'numerique'];
	const FORMAT_LABELS = { 'grands-caracteres': 'Grands caractères', 'numerique': 'Numérique', 'tous': 'Tous les formats', 'classique': 'Classique' };

	document.querySelectorAll('.pf-catalogue').forEach(initCatalogue);

	function initCatalogue(root) {
		let config;
		try { config = JSON.parse(root.dataset.config || '{}'); }
		catch (e) { console.error('pf-catalogue: bad config', e); return; }

		const state           = Object.assign({}, config.state || {});
		const categoryParents = config.category_parents || {};
		const grid            = root.querySelector('.pf-catalogue-grid');
		const sticky          = root.querySelector('.pf-catalogue-sticky');
		const bar             = root.querySelector('.pf-catalogue-bar:not(.pf-catalogue-bar-top)');
		const dropdowns       = root.querySelectorAll('.pf-cat-dropdown');

		// Attente d'une réponse : filet accent au bas de la barre sticky
		// (composant partagé avec la recherche globale, /auteurs et /evenements,
		// cf. style.css) — la grille se contente de s'estomper, ce qui dit « ces
		// résultats sont périmés » mais pas « quelque chose arrive ».
		function setLoading(on) {
			if (grid) grid.classList.toggle('is-loading', on);
			if (bar)  bar.classList.toggle('is-loading', on);
		}

		// Gate progressif : la bascule vers le panneau de filtres mobile n'est
		// active que si le JS tourne (sans JS, repli sur la barre empilée). La
		// classe est normalement déjà posée AVANT le 1er paint par un script
		// inline (cf. render_shortcode) pour éviter tout clignotement ; on la
		// re-confirme ici (idempotent) au cas où ce script serait retiré.
		root.classList.add('pf-cat-js');

		// Portal menus to <body> so their `position:fixed` is viewport-relative,
		// regardless of any ancestor that creates a containing block (sticky
		// transform, transitions, theme wrappers with filter/transform, etc.).
		const menus = {};
		dropdowns.forEach((dd, i) => {
			const menu = dd.querySelector('.pf-cat-menu');
			if (!menu) return;
			const id = 'pf-cat-menu-' + i + '-' + Math.random().toString(36).slice(2, 8);
			menu.id = id;
			dd.dataset.menuId = id;
			menu.dataset.owner = dd.dataset.filter || '';
			document.body.appendChild(menu);
			menus[id] = menu;
		});

		syncFormatUI();
		syncDisplayUI();
		syncNavMenu();

		// ── Filtres / tri : sur desktop (≥1024px) les contrôles restent dans la
		// barre (tri près de la recherche, format/PDF dans le top bar, le reste
		// dans la rangée à scroll). Sur mobile/tablette ils sont relogés dans un
		// panneau bottom-sheet ouvert par le bouton « Filtres ». Le panneau est
		// rendu hors du conteneur sticky (transformé/flouté une fois collé) pour
		// que son position:fixed reste relatif au viewport, mais reste dans
		// .pf-catalogue (= root) → tous les root.querySelector le voient encore.
		const mqDesktop      = window.matchMedia('(min-width: 1024px)');
		const filterTrigger  = root.querySelector('.pf-cat-filter-trigger');
		const filterPanel    = root.querySelector('.pf-cat-filter-panel');
		const filterBackdrop = root.querySelector('.pf-cat-filter-backdrop');
		const panelBody      = filterPanel ? filterPanel.querySelector('.pf-cat-filter-panel__body') : null;
		const filterCount    = filterTrigger ? filterTrigger.querySelector('.pf-cat-filter-count') : null;
		const panelApply     = filterPanel ? filterPanel.querySelector('.pf-cat-panel-apply') : null;

		// Compteur « N résultats » sur le bouton du panneau (les filtres sont
		// appliqués en direct → il se met à jour à chaque réponse AJAX). Même règle
		// de pluriel FR que le rendu PHP (results_label).
		function updateResultCount(total) {
			if (!panelApply || total == null) return;
			const n = parseInt(total, 10) || 0;
			panelApply.textContent = n + (n >= 2 ? ' résultats' : ' résultat');
		}

		// Regroupement dans le panneau : chaque sous-tableau = une rangée
		// (contrôles côte à côte). L'emplacement d'origine de chaque contrôle est
		// capturé pour restauration au retour desktop. Un wrapper de groupe est
		// créé une fois dans panelBody ; les groupes vides sont masqués en CSS.
		const PANEL_GROUPS = [
			[ '.pf-cat-sort', '.pf-cat-dropdown[data-filter="_pdf"]' ],
			[ '.pf-cat-format-switch' ],
			[ '.pf-cat-dropdown[data-filter="public"]', '.pf-cat-dropdown[data-filter="type"]' ],
			[ '.pf-cat-dropdown[data-filter="langues"]', '.pf-cat-dropdown[data-filter="decouvrir"]' ],
			[ '.pf-cat-display' ],
		];
		const relocatable = [];
		const groupEls = [];
		PANEL_GROUPS.forEach((sels, gi) => {
			let g = null;
			if (panelBody) {
				g = document.createElement('div');
				g.className = 'pf-cat-panel-group';
				panelBody.appendChild(g);
			}
			groupEls.push(g);
			sels.forEach(sel => {
				const el = root.querySelector(sel);
				if (el) relocatable.push({ el: el, parent: el.parentNode, next: el.nextSibling, group: gi });
			});
		});

		function placeControls() {
			if (mqDesktop.matches) {
				relocatable.forEach(r => {
					if (r.next && r.next.parentNode === r.parent) r.parent.insertBefore(r.el, r.next);
					else r.parent.appendChild(r.el);
				});
			} else {
				relocatable.forEach(r => { if (groupEls[r.group]) groupEls[r.group].appendChild(r.el); });
			}
		}
		placeControls();
		mqDesktop.addEventListener('change', () => { placeControls(); closePanel(); });

		function openPanel() {
			if (!filterPanel) return;
			filterPanel.classList.add('is-open');
			if (filterBackdrop) filterBackdrop.classList.add('is-open');
			if (filterTrigger) filterTrigger.setAttribute('aria-expanded', 'true');
			document.body.style.overflow = 'hidden';
		}
		function closePanel() {
			if (!filterPanel || !filterPanel.classList.contains('is-open')) return;
			filterPanel.classList.remove('is-open');
			if (filterBackdrop) filterBackdrop.classList.remove('is-open');
			if (filterTrigger) filterTrigger.setAttribute('aria-expanded', 'false');
			document.body.style.overflow = '';
			closeAllDropdowns();
		}
		if (filterTrigger) {
			filterTrigger.addEventListener('click', () => {
				if (filterPanel && filterPanel.classList.contains('is-open')) closePanel();
				else openPanel();
			});
		}
		if (filterBackdrop) filterBackdrop.addEventListener('click', closePanel);
		if (filterPanel) {
			const closeBtn = filterPanel.querySelector('.pf-cat-filter-close');
			if (closeBtn) closeBtn.addEventListener('click', closePanel);
			if (panelApply) panelApply.addEventListener('click', closePanel);
			const panelReset = filterPanel.querySelector('.pf-cat-panel-reset');
			if (panelReset) panelReset.addEventListener('click', resetAll);
		}

		// Badge du bouton « Filtres » : nombre de filtres actifs dans le panneau
		// (format, public, type×n, langue×n, découvrir, tri non-défaut).
		function activeFilterCount() {
			let n = 0;
			if (state.format)    n++;
			if (state.public)    n++;
			['type', 'langues'].forEach(k => {
				if (state[k]) n += String(state[k]).split(',').filter(Boolean).length;
			});
			if (state.decouvrir) n++;
			if (state.orderby !== DEFAULTS.orderby || state.order !== DEFAULTS.order) n++;
			return n;
		}
		function updateFilterBadge() {
			if (!filterCount) return;
			const n = activeFilterCount();
			filterCount.textContent = String(n);
			filterCount.hidden = (n === 0);
			if (filterTrigger) filterTrigger.classList.toggle('has-active', n > 0);
		}

		// Réinitialisation complète — partagée par le chip « Tout réinitialiser »
		// (rebindé à chaque réponse AJAX) et le bouton du panneau.
		function resetAll() {
			const cleared = {};
			Object.keys(state).forEach(k => { cleared[k] = (DEFAULTS[k] !== undefined) ? DEFAULTS[k] : ''; });
			cleared.category = '';
			cleared.univers  = '';
			// Avec un rayon/une thématique actif, l'état réinitialisé est une AUTRE
			// page (/catalogue) : on y navigue (servie par le cache) plutôt que de
			// re-rendre l'étagère en AJAX.
			if (state.univers || state.category) {
				goTo(cleared);
				return;
			}
			setFilters(cleared);
			root.querySelectorAll('.pf-cat-dropdown').forEach(dd => {
				const f = dd.dataset.filter;
				if (f === '_pdf') return;
				setSelectedInDropdown(dd, '', dd.dataset.multi === 'true');
			});
			syncSortUI();
			if (searchInput) { searchInput.value = ''; refreshSearchState(); }
		}

		updateFilterBadge();

		// ── Dropdowns: open/close + positioning + outside-click
		dropdowns.forEach(dd => {
			const trigger = dd.querySelector('.pf-cat-trigger');
			trigger.addEventListener('click', e => {
				e.stopPropagation();
				const wasOpen = dd.classList.contains('is-open');
				closeAllDropdowns();
				if (!wasOpen) openDropdown(dd);
			});
		});

		// Outside click — listen at document level, capture phase so it fires
		// even if some inner element handled the click. Always close.
		document.addEventListener('click', (e) => {
			let openDd = null;
			dropdowns.forEach(dd => { if (dd.classList.contains('is-open')) openDd = dd; });
			if (!openDd) return;
			// Click was inside the (portaled) menu? leave open (for multi-select).
			const menu = menus[openDd.dataset.menuId];
			if (menu && menu.contains(e.target)) return;
			// Click was on the trigger? trigger handler will toggle.
			const trig = openDd.querySelector('.pf-cat-trigger');
			if (trig && trig.contains(e.target)) return;
			closeAllDropdowns();
		}, true);

		document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeAllDropdowns(); closePanel(); } });
		window.addEventListener('scroll', closeAllDropdowns, { passive: true });
		window.addEventListener('resize', closeAllDropdowns);

		function openDropdown(dd) {
			dd.classList.add('is-open');
			const trig = dd.querySelector('.pf-cat-trigger');
			const menu = menus[dd.dataset.menuId];
			if (trig) trig.setAttribute('aria-expanded', 'true');
			if (menu) {
				menu.classList.add('is-open');
				positionMenu(menu, trig);
			}
		}

		function positionMenu(menu, trigger) {
			const r = trigger.getBoundingClientRect();
			const menuW = Math.min(menu.scrollWidth, 320);
			let left = r.left;
			const maxLeft = window.innerWidth - menuW - 8;
			if (left > maxLeft) left = Math.max(8, maxLeft);
			menu.style.top  = (r.bottom + 4) + 'px';
			menu.style.left = left + 'px';
		}

		function closeAllDropdowns() {
			dropdowns.forEach(dd => {
				if (dd.classList.contains('is-open')) {
					dd.classList.remove('is-open');
					const menu = menus[dd.dataset.menuId];
					if (menu) menu.classList.remove('is-open');
					const t = dd.querySelector('.pf-cat-trigger');
					if (t) t.setAttribute('aria-expanded', 'false');
				}
			});
		}

		// ── Format switch buttons
		root.querySelectorAll('.pf-cat-format-btn').forEach(btn => {
			btn.addEventListener('click', () => {
				const v    = btn.dataset.value;
				const next = state.format === v ? '' : v;
				const fmtDd = root.querySelector('.pf-cat-dropdown[data-filter="format"]');
				if (fmtDd) setSelectedInDropdown(fmtDd, next, false);
				setFilter('format', next);
			});
		});

		// ── Rayon switch (univers) — navigation vers la page du rayon (cf. goTo).
		root.querySelectorAll('.pf-cat-univers-btn').forEach(btn => {
			btn.addEventListener('click', () => {
				const v    = btn.dataset.value;
				const next = state.univers === v ? '' : v;
				goToCategory(next, '');
			});
		});

		// ── Single-select dropdowns (event delegation on menu container)
		root.querySelectorAll('.pf-cat-dropdown[data-multi="false"]').forEach(dd => {
			const filterKey = dd.dataset.filter;
			if (!filterKey || filterKey === '_pdf') return;
			const menu = menus[dd.dataset.menuId];
			if (!menu) return;
			menu.addEventListener('click', e => {
				const btn = e.target.closest('.pf-cat-option');
				if (!btn || btn.classList.contains('is-disabled')) return;
				const v = btn.dataset.value;
				// Sort criterion cannot be deselected — you must pick another.
				// Other single-select filters toggle: re-click clears.
				let next;
				if (filterKey === 'orderby') {
					if (state.orderby === v) return; // no-op
					next = v;
				} else {
					next = (state[filterKey] === v) ? '' : v;
				}
				// Thématique = vraie page (cachée) → navigation directe, pas d'AJAX.
				// Sélectionner une thématique active son rayon parent ; la
				// désélectionner revient à la page du rayon courant.
				if (filterKey === 'category') {
					goToCategory(next !== '' ? (categoryParents[next] || state.univers) : state.univers, next);
					return;
				}
				setSelectedInDropdown(dd, next, false);
				closeAllDropdowns();
				setFilter(filterKey, next);
			});
		});

		// ── Multi-select dropdowns
		root.querySelectorAll('.pf-cat-dropdown[data-multi="true"]').forEach(dd => {
			const filterKey = dd.dataset.filter;
			const menu = menus[dd.dataset.menuId];
			if (!menu) return;
			menu.addEventListener('change', e => {
				if (!e.target.matches('input[type="checkbox"]')) return;
				const checked = Array.from(menu.querySelectorAll('input[type="checkbox"]:checked')).map(c => c.value);
				const csv = checked.join(',');
				setSelectedInDropdown(dd, csv, true);
				setFilter(filterKey, csv);
			});
		});

		function setSelectedInDropdown(dd, value, isMulti) {
			const menu = menus[dd.dataset.menuId];
			if (!menu) return;
			const set = isMulti ? new Set((value || '').split(',').filter(Boolean)) : new Set([value]);
			menu.querySelectorAll('.pf-cat-option').forEach(opt => {
				let v;
				if (isMulti) {
					const cb = opt.querySelector('input[type="checkbox"]');
					v = cb ? cb.value : null;
					if (cb) cb.checked = set.has(v);
				} else {
					v = opt.dataset ? opt.dataset.value : null;
				}
				if (v == null) return;
				opt.classList.toggle('is-selected', set.has(v));
			});
		}

		// ── Sort direction toggle
		const dirBtn = root.querySelector('.pf-cat-sort-dir');
		if (dirBtn) {
			dirBtn.addEventListener('click', () => {
				const next = state.order === 'ASC' ? 'DESC' : 'ASC';
				dirBtn.dataset.sens = next;
				dirBtn.setAttribute('aria-label', next === 'ASC' ? 'Ordre croissant' : 'Ordre décroissant');
				dirBtn.classList.toggle('is-active', next === 'ASC');
				setFilter('order', next);
			});
		}

		// ── Search (debounced)
		// "is-searching" = input is focused OR has text. Triggers the
		// expansion that hides the sort group.
		const searchInput = root.querySelector('.pf-cat-search-input');
		const searchClear = root.querySelector('.pf-cat-search-clear');
		let searchTimer = null;

		// Placeholder court sur mobile (≤768px), long au-dessus (même mécanisme
		// que la recherche globale du header, cf. recherche-globale.js).
		if (searchInput && searchInput.dataset.placeholderSm) {
			const phSm      = searchInput.dataset.placeholderSm;
			const phDefault = searchInput.getAttribute('placeholder');
			const mqSearch  = window.matchMedia('(max-width: 768px)');
			const applySearchPh = () => { searchInput.placeholder = mqSearch.matches ? phSm : phDefault; };
			applySearchPh();
			mqSearch.addEventListener('change', applySearchPh);
		}

		function refreshSearchState() {
			const hasText = searchInput.value !== '';
			const focused = document.activeElement === searchInput;
			bar.classList.toggle('is-searching', hasText || focused);
		}
		if (searchInput) {
			searchInput.addEventListener('focus', refreshSearchState);
			searchInput.addEventListener('blur',  refreshSearchState);
			// `is-searching` replie le tri et laisse la recherche s'étendre : la
			// largeur des rangées scrollables change, donc leurs fondus de bord
			// sont à recalculer. Sur TOUTES les rangées (`forEach`) — ici on ne
			// réagit pas au défilement d'une rangée précise, mais à un changement
			// de mise en page de la barre. `updateFades` exige un élément : sans
			// argument elle lève un TypeError et ne recalcule rien.
			searchInput.addEventListener('input', () => {
				refreshSearchState();
				clearTimeout(searchTimer);
				searchTimer = setTimeout(() => { setFilter('search', searchInput.value); scrollRows.forEach(updateFades); }, 300);
			});
			searchClear.addEventListener('click', () => {
				searchInput.value = '';
				refreshSearchState();
				setFilter('search', '');
				searchInput.focus();
				scrollRows.forEach(updateFades);
			});
		}

		// ── Display segmented control
		root.querySelectorAll('.pf-cat-display button').forEach(btn => {
			btn.addEventListener('click', () => {
				root.querySelectorAll('.pf-cat-display button').forEach(b => b.classList.toggle('is-active', b === btn));
				setFilter('display', btn.dataset.value);
			});
		});

		// ── Chip click handlers (rebuilt each AJAX response)
		bindChipHandlers();

		function bindChipHandlers() {
			root.querySelectorAll('.pf-cat-chip').forEach(chip => {
				chip.addEventListener('click', () => {
					const f = chip.dataset.filter;
					const v = chip.dataset.value;
					if (f === 'sort') {
						setFilters({ orderby: DEFAULTS.orderby, order: DEFAULTS.order });
						syncSortUI();
					} else if (f === 'univers') {
						// Retirer le chip « rayon » efface rayon ET thématique
						// → page /catalogue (cf. goTo).
						goToCategory('', '');
					} else if (f === 'category') {
						// Retirer la thématique → page du rayon courant.
						goToCategory(state.univers, '');
					} else if (MULTI_FILTERS.has(f)) {
						const cur = (state[f] || '').split(',').filter(Boolean);
						const next = cur.filter(x => x !== v).join(',');
						setFilter(f, next);
						const dd = root.querySelector('.pf-cat-dropdown[data-filter="' + f + '"]');
						if (dd) setSelectedInDropdown(dd, next, true);
					} else {
						setFilter(f, '');
						const dd = root.querySelector('.pf-cat-dropdown[data-filter="' + f + '"]');
						if (dd) setSelectedInDropdown(dd, '', false);
					}
				});
			});
			const reset = root.querySelector('.pf-cat-reset-all');
			if (reset) reset.addEventListener('click', resetAll);
		}

		function syncSortUI() {
			if (dirBtn) {
				dirBtn.dataset.sens = state.order;
				dirBtn.classList.toggle('is-active', state.order === 'ASC');
				dirBtn.setAttribute('aria-label', state.order === 'ASC' ? 'Ordre croissant' : 'Ordre décroissant');
			}
			const tri = root.querySelector('.pf-cat-dropdown[data-filter="orderby"]');
			if (tri) {
				setSelectedInDropdown(tri, state.orderby, false);
				tri.classList.toggle('is-active', state.orderby !== DEFAULTS.orderby);
			}
		}

		function syncFormatUI() {
			root.querySelectorAll('.pf-cat-format-btn').forEach(btn => {
				btn.classList.toggle('is-active', btn.dataset.value === state.format);
			});
			const fmtDd = root.querySelector('.pf-cat-dropdown[data-filter="format"]');
			if (fmtDd) fmtDd.classList.toggle('is-active', state.format !== '' && !FORMAT_SWITCH_SLUGS.includes(state.format));
		}

		function syncUniversUI() {
			root.querySelectorAll('.pf-cat-univers-btn').forEach(btn => {
				const isAll = btn.dataset.value === '';
				const active = isAll
					? state.univers === '' && state.category === ''
					: btn.dataset.value === state.univers;
				btn.classList.toggle('is-active', active);
			});
		}

		// ── Header nav sub-menu sync. Catalogue filtering never triggers a
		// full page reload (pushState only), so the server-rendered
		// current-menu-item classes on the category sub-menu (header-hooks.php)
		// go stale as soon as a filter changes in-page. Re-derive them from
		// the live state instead, on every filter change (and on init, which
		// also fills in the ancestor highlight the server-side render skips).
		function syncNavMenu() {
			document.querySelectorAll('a[data-pf-cat]').forEach(a => {
				const li = a.closest('li.menu-item');
				if (!li) return;
				li.classList.remove('current-menu-item', 'current_page_item', 'current-product_cat-ancestor');
				const slug = a.dataset.pfCat;
				if (state.category ? slug === state.category : slug === state.univers) {
					li.classList.add('current-menu-item', 'current_page_item');
				} else if (state.category && slug === state.univers) {
					li.classList.add('current-product_cat-ancestor');
				}
			});
		}

		// ── Display switch : grise l'option dos quand le format peut
		// afficher des liseuses, et rebascule sur couvertures si besoin.
		// Retourne true si le display a dû changer.
		function enforceDisplayRule() {
			if (NO_SPINES_FORMATS.includes(state.format) && state.display === 'spines') {
				state.display = 'covers';
				return true;
			}
			return false;
		}

		function syncDisplayUI() {
			const blocked = NO_SPINES_FORMATS.includes(state.format);
			root.querySelectorAll('.pf-cat-display button').forEach(b => {
				if (b.dataset.value === 'spines') b.disabled = blocked;
				b.classList.toggle('is-active', b.dataset.value === state.display);
			});
		}

		// ── State setters
		function setFilter(key, value) { const p = {}; p[key] = value; setFilters(p); }

		function setFilters(patch) {
			Object.assign(state, patch);
			enforceDisplayRule();
			syncDisplayUI();
			syncUrl();
			fetchAndUpdate();
			// Category dropdown is-active (per-collection: only mark the one owning the selected subcategory)
			root.querySelectorAll('.pf-cat-dropdown[data-filter="category"]').forEach(catDd => {
				const ddUnivers = catDd.dataset.univers;
				const active = state.category !== '' &&
				               (!ddUnivers || categoryParents[state.category] === ddUnivers);
				catDd.classList.toggle('is-active', active);
			});
			syncFormatUI();
			// Tri trigger: is-active when criterion non-default
			const triDd = root.querySelector('.pf-cat-dropdown[data-filter="orderby"]');
			if (triDd) triDd.classList.toggle('is-active', state.orderby !== DEFAULTS.orderby);
			// Other single-select dropdowns
			['public','decouvrir'].forEach(k => {
				const dd = root.querySelector('.pf-cat-dropdown[data-filter="' + k + '"]');
				if (dd) dd.classList.toggle('is-active', state[k] !== '');
			});
			['langues','type'].forEach(k => {
				const dd = root.querySelector('.pf-cat-dropdown[data-filter="' + k + '"]');
				if (dd) dd.classList.toggle('is-active', !!state[k]);
			});
			// Rayon switch
			syncUniversUI();
			syncNavMenu();
			updateFilterBadge();
		}

		// ── URL sync
		/**
		 * URL du catalogue pour l'état courant, éventuellement modifié par
		 * `overrides`. Chemin canonique SANS slash final : une URL à slash
		 * déclenche la redirection canonique de WordPress (301), soit un aller-retour
		 * PHP complet avant d'atteindre la page — qui, elle, est servie par le cache.
		 */
		function buildUrl(overrides) {
			const s   = Object.assign({}, state, overrides || {});
			const url = new URL(window.location.href);
			if (s.univers && s.category) {
				url.pathname = '/catalogue/' + s.univers + '/' + s.category;
			} else if (s.univers) {
				url.pathname = '/catalogue/' + s.univers;
			} else {
				url.pathname = '/catalogue';
			}
			Object.entries(URL_KEYS).forEach(([attKey, urlKey]) => {
				const v    = s[attKey];
				const urlV = URL_VALUE_OUT[attKey] ? (URL_VALUE_OUT[attKey][v] ?? v) : v;
				const def  = DEFAULTS[attKey] !== undefined ? DEFAULTS[attKey] : '';
				const urlDef = URL_VALUE_OUT[attKey] ? (URL_VALUE_OUT[attKey][def] ?? def) : def;
				if (urlV === '' || urlV === urlDef || urlV == null) url.searchParams.delete(urlKey);
				else                                                url.searchParams.set(urlKey, urlV);
			});
			return url.toString();
		}

		function syncUrl() {
			history.pushState({ pf: state }, '', buildUrl());
		}

		/**
		 * Navigation « rayon / thématique » : on charge la vraie page au lieu de
		 * re-rendre l'étagère en AJAX.
		 *
		 * Chaque rayon et chaque thématique a son URL (/catalogue/<univers>[/<thematique>])
		 * donc sa page mise en cache côté serveur : y naviguer est nettement plus
		 * rapide qu'un appel admin-ajax, qui contourne le cache de page et repaie le
		 * bootstrap WordPress + le rendu de l'étagère à chaque clic.
		 *
		 * location.replace() plutôt qu'un lien ou pushState : les vues de catalogue
		 * se REMPLACENT dans l'historique au lieu de s'y empiler → le bouton Retour
		 * ramène à la page d'où vient l'utilisateur, au lieu de le piéger dans la
		 * suite de ses propres clics. Les autres filtres, eux, restent en AJAX
		 * (combinatoires, pas d'URL de page dédiée).
		 */
		function goTo(overrides) {
			closeAllDropdowns();
			closePanel();
			setLoading(true); // rechargement complet : le retour reste à l'écran jusqu'au nouveau rendu
			window.location.replace(buildUrl(overrides));
		}

		function goToCategory(nextUnivers, nextCategory) {
			goTo({ univers: nextUnivers, category: nextCategory });
		}

		window.addEventListener('popstate', e => {
			if (e.state && e.state.pf) Object.assign(state, e.state.pf);
			else {
				const url   = new URL(window.location.href);
				const parts = url.pathname.replace(/^\/catalogue\//, '').split('/').filter(Boolean);
				state.univers  = parts[0] || '';
				state.category = parts[1] || '';
				Object.entries(URL_KEYS).forEach(([attKey, urlKey]) => {
					const raw = url.searchParams.get(urlKey) || (DEFAULTS[attKey] || '');
					state[attKey] = URL_VALUE_IN[attKey] ? (URL_VALUE_IN[attKey][raw] ?? raw) : raw;
				});
			}
			enforceDisplayRule();
			syncUniversUI();
			syncFormatUI();
			syncDisplayUI();
			syncNavMenu();
			fetchAndUpdate();
		});

		// ── Smart-hide (now active on all viewports per latest spec).
		// The site header + this bar both sticky takes too much room on a
		// scroll-down gesture; hide on scroll-down, show on scroll-up.
		let lastY = window.scrollY;
		let ticking = false;
		function onScroll() {
			if (ticking) return;
			window.requestAnimationFrame(() => {
				const y = window.scrollY;
				const dy = y - lastY;
				// Slide the whole sticky (background included) so nothing
				// stays behind. Z-index keeps it under the page header.
				if (dy > 8 && y > 80)        sticky.classList.add('is-hidden');
				else if (dy < -8 || y < 80)  sticky.classList.remove('is-hidden');
				sticky.classList.toggle('is-stuck', y > 4);
				lastY = y;
				ticking = false;
			});
			ticking = true;
		}
		window.addEventListener('scroll', onScroll, { passive: true });
		onScroll();

		// ── Fade indicators on each scrollable row
		const scrollRows = root.querySelectorAll('.pf-cat-row-scroll');
		scrollRows.forEach(row => {
			row.addEventListener('scroll', () => updateFades(row), { passive: true });
		});
		window.addEventListener('resize', () => scrollRows.forEach(updateFades));
		scrollRows.forEach(updateFades);
		function updateFades(row) {
			const sl  = row.scrollLeft;
			const max = row.scrollWidth - row.clientWidth;
			row.classList.toggle('has-fade-left',  sl > 4);
			row.classList.toggle('has-fade-right', max > 4 && sl < max - 4);
		}

		// ── AJAX
		let abortCtrl = null;
		function fetchAndUpdate() {
			if (abortCtrl) abortCtrl.abort();
			abortCtrl = new AbortController();
			setLoading(true);

			const fd = new FormData();
			fd.append('action', 'pf_catalogue_filter');
			Object.entries(state).forEach(([k, v]) => fd.append(k, v == null ? '' : v));

			fetch(config.ajax_url, { method: 'POST', body: fd, signal: abortCtrl.signal })
				.then(r => r.json())
				.then(payload => {
					setLoading(false);
					if (!payload || !payload.success) return;
					grid.innerHTML = payload.data.html;
					updateCounts(payload.data.counts || {});
					updateResultCount(payload.data.total);
					updateChips();
					scrollRows.forEach(updateFades);
					// Re-bind bookshelf behaviors (spine hover flip, etc.)
					// on the freshly inserted books.
					if (window.PassifloreBookshelf && typeof window.PassifloreBookshelf.init === 'function') {
						window.PassifloreBookshelf.init();
					}
				})
				// Pas d'extinction sur AbortError : la requête a été annulée par une
				// plus récente, qui vient d'allumer le retour pour elle-même.
				.catch(err => { if (err.name !== 'AbortError') { console.error(err); setLoading(false); } });
		}

		function updateCounts(counts) {
			Object.entries(counts).forEach(([group, map]) => {
				root.querySelectorAll('.pf-cat-dropdown[data-filter="' + group + '"]').forEach(dd => {
					const menu = menus[dd.dataset.menuId];
					if (!menu) return;
					menu.querySelectorAll('.pf-cat-cat-header[data-value]').forEach(header => {
						const val = header.dataset.value;
						if (!(val in map)) return;
						const span = header.querySelector('.pf-cat-opt-count');
						if (span) span.textContent = '(' + map[val] + ')';
					});
					menu.querySelectorAll('.pf-cat-option').forEach(opt => {
						const cb  = opt.querySelector('input[type="checkbox"]');
						const val = cb ? cb.value : (opt.dataset ? opt.dataset.value : null);
						if (val == null || val === '') return;
						if (!(val in map)) return;
						const cnt = map[val];
						let countSpan = opt.querySelector('.pf-cat-opt-count');
						if (countSpan) countSpan.textContent = '(' + cnt + ')';
						if (cnt === 0) {
							opt.classList.add('is-disabled');
							if (cb) cb.disabled = true;
							if (opt.tagName === 'BUTTON') opt.disabled = true;
						} else {
							opt.classList.remove('is-disabled');
							if (cb) cb.disabled = false;
							if (opt.tagName === 'BUTTON') opt.disabled = false;
						}
					});
				});
			});
		}

		function updateChips() {
			const container = root.querySelector('.pf-catalogue-chips');
			if (!container) return;
			const labels = collectChipLabels();
			container.innerHTML = labels.map(c =>
				'<button type="button" class="pf-cat-chip" data-filter="' + escAttr(c.filter) + '" data-value="' + escAttr(c.value) + '">' +
				'<span>' + escHtml(c.label) + '</span><span class="pf-cat-chip-x" aria-hidden="true">×</span></button>'
			).join('') + (labels.length >= 2 ? '<button type="button" class="pf-cat-reset-all">Tout réinitialiser</button>' : '');
			container.hidden = labels.length === 0;
			bindChipHandlers();
		}

		function ddLabel(group, value) {
			for (const dd of root.querySelectorAll('.pf-cat-dropdown[data-filter="' + group + '"]')) {
				const menu = menus[dd.dataset.menuId];
				if (!menu) continue;
				const opt = menu.querySelector('.pf-cat-option[data-value="' + cssEsc(value) + '"] .pf-cat-opt-label');
				if (opt) return opt.textContent;
				const cb = menu.querySelector('input[type="checkbox"][value="' + cssEsc(value) + '"]');
				if (cb) {
					const lab = cb.closest('.pf-cat-option').querySelector('.pf-cat-opt-label');
					if (lab) return lab.textContent;
				}
			}
			return value;
		}

		function collectChipLabels() {
			const out = [];
			if (state.univers) out.push({ filter: 'univers', value: state.univers, label: UNIVERS_LABELS[state.univers] || state.univers });
			if (state.category) out.push({ filter: 'category', value: state.category, label: 'Thématique : ' + ddLabel('category', state.category) });
			if (state.format)   out.push({ filter: 'format',   value: state.format,   label: 'Format : ' + (FORMAT_LABELS[state.format] || ddLabel('format', state.format)) });
			const titles = { public: 'Public', type: 'Type', langues: 'Langue' };
			if (state.public) out.push({ filter: 'public', value: state.public, label: 'Public : ' + ddLabel('public', state.public) });
			MULTI_FILTERS.forEach(k => {
				if (state[k]) (state[k] || '').split(',').filter(Boolean).forEach(v => {
					out.push({ filter: k, value: v, label: titles[k] + ' : ' + ddLabel(k, v) });
				});
			});
			if (state.decouvrir) {
				const labels = {
					'nouveautes': 'Nouveautés', 'prix-litteraires': 'Prix et distinctions', 'a-paraitre': 'À paraître',
				};
				out.push({ filter: 'decouvrir', value: state.decouvrir, label: 'Découvrir : ' + (labels[state.decouvrir] || state.decouvrir) });
			}
			if (!state.search && (state.orderby !== DEFAULTS.orderby || state.order !== DEFAULTS.order)) {
				const sortLabels = { date: 'Parution', titre: 'Titre', prix: 'Prix', pages: 'Nombre de pages' };
				const arrow = state.order === 'ASC' ? '↓' : '↑';
				out.push({ filter: 'sort', value: '', label: 'Tri : ' + (sortLabels[state.orderby] || state.orderby) + ' ' + arrow });
			}
			return out;
		}

		function escAttr(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
		function escHtml(s) { return escAttr(s); }
		function cssEsc(s)  { return String(s == null ? '' : s).replace(/(["\\])/g, '\\$1'); }
	}
})();
