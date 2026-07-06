/* Passiflore Catalogue — UI controller (vanilla JS) */
(function () {
	'use strict';

	const URL_KEYS = {
		search: 'search', orderby: 'tri', order: 'sens', format: 'format',
		public: 'public', type: 'type', langues: 'langues', decouvrir: 'decouvrir', display: 'affichage',
	};
	// Internal state values → URL values (display only).
	const URL_VALUE_OUT = { display: { covers: 'couvertures', spines: 'tranches' } };
	const URL_VALUE_IN  = { display: { couvertures: 'covers', tranches: 'spines' } };

	const DEFAULTS = { orderby: 'date', order: 'DESC', display: 'covers' };
	const MULTI_FILTERS = new Set(['langues', 'type']);

	const UNIVERS_LABELS = { 'litterature': 'Littérature', 'culture-sud-ouest': 'Culture Sud-Ouest' };
	// Formats pouvant afficher des livres numériques (rendus en liseuse) :
	// l'affichage tranches n'a pas de sens pour eux → option spines grisée.
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

		// ── Sort placement: on desktop sort lives inside .pf-cat-search-sort
		// (next to search, so collapsing it on focus lets search grow). On
		// mobile sort is just another filter in the horizontal scroll line,
		// so we move it into .pf-cat-row-scroll at the start.
		const sortEl       = root.querySelector('.pf-cat-sort');
		const searchSort   = root.querySelector('.pf-cat-search-sort');
		const scrollRow    = root.querySelector('.pf-cat-row-scroll');
		const mqDesktop    = window.matchMedia('(min-width: 1024px)');
		function placeSort() {
			if (!sortEl) return;
			if (mqDesktop.matches) {
				if (searchSort && sortEl.parentElement !== searchSort) {
					searchSort.appendChild(sortEl);
				}
			} else {
				if (scrollRow && sortEl.parentElement !== scrollRow) {
					// Sort appears just after the PDF (which jumps to order:-1
					// via CSS), so practically it's the second visible item.
					scrollRow.insertBefore(sortEl, scrollRow.firstChild);
				}
			}
		}
		placeSort();
		mqDesktop.addEventListener('change', placeSort);

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

		document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAllDropdowns(); });
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

		// ── Rayon switch (univers)
		root.querySelectorAll('.pf-cat-univers-btn').forEach(btn => {
			btn.addEventListener('click', () => {
				const v    = btn.dataset.value;
				const next = state.univers === v ? '' : v;
				root.querySelectorAll('.pf-cat-dropdown[data-filter="category"]').forEach(d => setSelectedInDropdown(d, '', false));
				setFilters({ univers: next, category: '' });
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
				setSelectedInDropdown(dd, next, false);
				if (filterKey === 'category') {
					root.querySelectorAll('.pf-cat-dropdown[data-filter="category"]').forEach(d => {
						if (d !== dd) setSelectedInDropdown(d, '', false);
					});
				}
				closeAllDropdowns();
				// When selecting a subcategory, auto-activate its parent rayon.
				if (filterKey === 'category' && next !== '') {
					const parent = categoryParents[v];
					if (parent && state.univers !== parent) {
						setFilters({ category: next, univers: parent });
						return;
					}
				}
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
			searchInput.addEventListener('input', () => {
				refreshSearchState();
				clearTimeout(searchTimer);
				searchTimer = setTimeout(() => { setFilter('search', searchInput.value); updateFades(); }, 300);
			});
			searchClear.addEventListener('click', () => {
				searchInput.value = '';
				refreshSearchState();
				setFilter('search', '');
				searchInput.focus();
				updateFades();
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
						// Removing the rayon chip clears both univers and subcategory.
						root.querySelectorAll('.pf-cat-dropdown[data-filter="category"]').forEach(d => setSelectedInDropdown(d, '', false));
						setFilters({ univers: '', category: '' });
					} else if (MULTI_FILTERS.has(f)) {
						const cur = (state[f] || '').split(',').filter(Boolean);
						const next = cur.filter(x => x !== v).join(',');
						setFilter(f, next);
						const dd = root.querySelector('.pf-cat-dropdown[data-filter="' + f + '"]');
						if (dd) setSelectedInDropdown(dd, next, true);
					} else {
						setFilter(f, '');
						if (f === 'category') {
							root.querySelectorAll('.pf-cat-dropdown[data-filter="category"]').forEach(d => setSelectedInDropdown(d, '', false));
						} else {
							const dd = root.querySelector('.pf-cat-dropdown[data-filter="' + f + '"]');
							if (dd) setSelectedInDropdown(dd, '', false);
						}
					}
				});
			});
			const reset = root.querySelector('.pf-cat-reset-all');
			if (reset) reset.addEventListener('click', () => {
				const cleared = {};
				Object.keys(state).forEach(k => { cleared[k] = (DEFAULTS[k] !== undefined) ? DEFAULTS[k] : ''; });
				cleared.category = '';
				cleared.univers  = '';
				setFilters(cleared);
				// Reset every dropdown DOM state
				root.querySelectorAll('.pf-cat-dropdown').forEach(dd => {
					const f = dd.dataset.filter;
					if (f === '_pdf') return;
					setSelectedInDropdown(dd, '', dd.dataset.multi === 'true');
				});
				syncSortUI();
				if (searchInput) { searchInput.value = ''; refreshSearchState(); }
			});
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

		// ── Display switch : grise l'option tranches quand le format peut
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
		}

		// ── URL sync
		function syncUrl() {
			const url = new URL(window.location.href);
			if (state.univers && state.category) {
				url.pathname = '/catalogue/' + state.univers + '/' + state.category;
			} else if (state.univers) {
				url.pathname = '/catalogue/' + state.univers;
			} else {
				url.pathname = '/catalogue';
			}
			Object.entries(URL_KEYS).forEach(([attKey, urlKey]) => {
				const v    = state[attKey];
				const urlV = URL_VALUE_OUT[attKey] ? (URL_VALUE_OUT[attKey][v] ?? v) : v;
				const def  = DEFAULTS[attKey] !== undefined ? DEFAULTS[attKey] : '';
				const urlDef = URL_VALUE_OUT[attKey] ? (URL_VALUE_OUT[attKey][def] ?? def) : def;
				if (urlV === '' || urlV === urlDef || urlV == null) url.searchParams.delete(urlKey);
				else                                                url.searchParams.set(urlKey, urlV);
			});
			history.pushState({ pf: state }, '', url.toString());
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
			grid.classList.add('is-loading');

			const fd = new FormData();
			fd.append('action', 'pf_catalogue_filter');
			fd.append('nonce',  config.nonce);
			Object.entries(state).forEach(([k, v]) => fd.append(k, v == null ? '' : v));

			fetch(config.ajax_url, { method: 'POST', body: fd, signal: abortCtrl.signal })
				.then(r => r.json())
				.then(payload => {
					grid.classList.remove('is-loading');
					if (!payload || !payload.success) return;
					grid.innerHTML = payload.data.html;
					updateCounts(payload.data.counts || {});
					updateChips();
					scrollRows.forEach(updateFades);
					// Re-bind bookshelf behaviors (spine hover flip, etc.)
					// on the freshly inserted books.
					if (window.PassifloreBookshelf && typeof window.PassifloreBookshelf.init === 'function') {
						window.PassifloreBookshelf.init();
					}
				})
				.catch(err => { if (err.name !== 'AbortError') { console.error(err); grid.classList.remove('is-loading'); } });
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
