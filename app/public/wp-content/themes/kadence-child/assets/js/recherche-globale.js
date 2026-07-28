/* Passiflore — Recherche globale (header) */
(function () {
	'use strict';

	// ── Sticky offset ────────────────────────────────────────────────────────
	// Ce script est chargé sur toutes les pages (via le header). Il est donc
	// la source unique de --pf-sticky-offset ; catalogue.js, recherche-auteurs.js
	// et accueil.js lisent la variable sans la recalculer.
	//
	// Mise à jour au resize / orientationchange / load — PAS au scroll. Le header
	// Kadence garde une position ET une hauteur constantes au scroll (vérifié en
	// headless : offset identique de scrollY=0 à 1600, desktop comme mobile), donc
	// recalculer au scroll ne faisait que réécrire la même valeur… sauf sur iOS, où
	// getBoundingClientRect() est relatif au viewport VISUEL : la mesure « bougeait »
	// quand la barre d'outils Safari se rétracte au scroll → les éléments calés dessus
	// (hero en svh, barres sticky) sautaient. En s'abstenant au scroll, la valeur reste
	// figée pendant le défilement. Seul écart connu et ASSUMÉ : la barre d'admin WP en
	// mobile (position:absolute, défile) → offset figé ~46px trop haut pour un admin
	// connecté prévisualisant sur téléphone ; sans effet pour un vrai visiteur.
	// #masthead est le header collant lui-même (position:sticky, cf. style.css) :
	// son bord bas vaut la même chose à l'arrêt et défilé, donc une seule mesure
	// suffit et elle est juste à tout moment. C'est ce qui a permis de retirer le
	// rattrapage « en-tête défilé au-dessus du viewport » que réclamait le sticky JS
	// de Kadence : après un rechargement avec scroll restauré, il n'avait pas encore
	// re-fixé le header, la mesure tombait à 0 et les barres sticky se collaient
	// derrière lui. Avec un vrai sticky, ce cas n'existe plus.
	function updateStickyOffset() {
		const ab = document.getElementById('wpadminbar');
		let abBottom = 0;
		if (ab) {
			const abRect = ab.getBoundingClientRect();
			if (abRect.bottom > 0) abBottom = abRect.bottom;
		}
		let best = abBottom;
		const header = document.getElementById('masthead');
		if (header && header.offsetHeight) {
			best = Math.max(best, header.getBoundingClientRect().bottom);
		}
		document.documentElement.style.setProperty('--pf-sticky-offset', Math.max(0, best) + 'px');
	}
	window.addEventListener('resize', updateStickyOffset, { passive: true });
	window.addEventListener('orientationchange', updateStickyOffset);
	// Exposée pour qui déplace la ligne de collage du header APRÈS le resize.
	// Cas actuel unique : le bandeau bêta, dont la hauteur (donc --pf-banner-h, donc
	// le `top` du header) est mesurée par un ResizeObserver — or ceux-ci sont livrés
	// après l'événement `resize`. Sans ce rappel, une rotation de téléphone laissait
	// l'offset une mesure en retard (17px d'écart entre 390 et 500px de large), et
	// les barres collantes calées dessus autant à côté. TEMPORAIRE avec le bandeau.
	window.pfRefreshStickyOffset = updateStickyOffset;
	// Filet : header définitif une fois tout chargé (utile après un reload avec scroll
	// restauré, où le sticky JS de Kadence n'a pas encore re-fixé le header au DOMContentLoaded).
	window.addEventListener('load', updateStickyOffset);

	document.addEventListener('DOMContentLoaded', function () {
		updateStickyOffset();
		document.querySelectorAll('.pf-gsearch').forEach(init);
	});

	function init(root) {
		let config;
		try { config = JSON.parse(root.dataset.config || '{}'); }
		catch (e) { console.error('pf-gsearch: bad config', e); return; }

		const btn         = root.querySelector('.pf-gsearch-btn');
		const panel       = root.querySelector('.pf-gsearch-panel');
		const input       = root.querySelector('.pf-gsearch-input');
		const typeEl      = root.querySelector('.pf-gsearch-type');       // hidden input
		const typeWrap    = root.querySelector('.pf-gsearch-type-wrap');
		const typeBtn     = root.querySelector('.pf-gsearch-type-btn');
		const typeMenu    = root.querySelector('.pf-gsearch-type-menu');
		const typeLabel   = root.querySelector('.pf-gsearch-type-label');
		const typeOptions = root.querySelectorAll('.pf-gsearch-type-option');
		const closeBtn    = root.querySelector('.pf-gsearch-close');
		const clearBtn    = root.querySelector('.pf-gsearch-clear');
		const results     = root.querySelector('.pf-gsearch-results');

		if (!btn || !panel || !input || !results) return;

		// Placeholder dépendant du type sélectionné (Tout/Livres/Auteurs/Événements),
		// court sur mobile (≤768px) et long au-dessus. Le breakpoint suit celui du
		// CSS (#mobile-header @media max-width:768px). L'instance desktop est
		// masquée ≤768px, donc le swap n'y est jamais visible.
		const placeholders = config.placeholders || {};
		const phMq = window.matchMedia('(max-width: 768px)');
		function applyPlaceholder() {
			const p = placeholders[typeEl ? typeEl.value : 'all'] || placeholders.all;
			if (!p) return;
			input.placeholder = phMq.matches ? p.short : p.long;
		}
		applyPlaceholder();
		phMq.addEventListener('change', applyPlaceholder);

		// Déplace results et typeMenu dans <body> (contexte d'empilement racine).
		document.body.appendChild(results);
		if (typeMenu) document.body.appendChild(typeMenu);

		// ── Overlay ────────────────────────────────────────────────────────
		let overlay = null;

		function addOverlay() {
			if (overlay) return;
			overlay = document.createElement('div');
			overlay.className = 'pf-gsearch-overlay';
			document.body.appendChild(overlay);
			overlay.addEventListener('click', close);
		}

		function removeOverlay() {
			if (overlay) { overlay.remove(); overlay = null; }
		}

		// ── Ouvrir ────────────────────────────────────────────────────────
		function open() {
			if (document.body.classList.contains('pf-search-open')) return;
			updateStickyOffset();
			const menu = document.getElementById('primary-menu');
			if (menu) {
				document.documentElement.style.setProperty('--pf-gsearch-nav-w', menu.offsetWidth + 'px');
			}
			document.body.classList.add('pf-search-open');
			btn.setAttribute('aria-expanded', 'true');
			input.focus();
		}

		// ── Fermer ────────────────────────────────────────────────────────
		// Sur tablette (769–1024px) la barre est toujours visible, sans bouton
		// toggle (cf. recherche-globale.css) : body.pf-search-open n'est donc
		// jamais posée, seuls l'overlay et le panneau de résultats existent.
		// close() doit pouvoir s'exécuter dans ce cas (sinon un clic sur
		// l'overlay ne fait rien), mais sans l'animation de repli du bouton.
		function close() {
			if (document.body.classList.contains('pf-search-closing')) return;
			const wasOpen = document.body.classList.contains('pf-search-open');
			if (!wasOpen && !overlay) return;

			clearTimeout(timer);
			if (abortCtrl) { abortCtrl.abort(); abortCtrl = null; }
			closeTypeMenu();
			btn.blur(); // sinon la loupe garde l'état focus/actif après fermeture (le clic sur la croix laisse le focus sur le bouton)

			var done = false;
			function finish() {
				if (done) return;
				done = true;
				document.body.classList.remove('pf-search-open');
				document.body.classList.remove('pf-search-closing');
				btn.setAttribute('aria-expanded', 'false');
				removeOverlay();
				results.innerHTML = '';
			}

			if (!wasOpen) {
				finish(); // pas de toggle animé sur tablette : fermeture immédiate
				return;
			}

			document.body.classList.add('pf-search-closing');
			panel.addEventListener('transitionend', function onEnd(e) {
				if (e.propertyName === 'max-width') { panel.removeEventListener('transitionend', onEnd); finish(); }
			});
			setTimeout(finish, 400);
		}

		btn.addEventListener('click', function () {
			document.body.classList.contains('pf-search-open') ? close() : open();
		});
		if (clearBtn) clearBtn.addEventListener('click', function () {
			input.value = '';
			results.innerHTML = '';
			removeOverlay();
			input.focus();
		});

		// ── Dropdown type ──────────────────────────────────────────────────────
		function openTypeMenu() {
			if (!typeMenu || !typeWrap || !typeBtn) return;
			const rect = typeBtn.getBoundingClientRect();
			typeMenu.style.top  = (rect.bottom + 4) + 'px';
			typeMenu.style.left = rect.left + 'px';
			typeMenu.classList.add('is-open');
			typeWrap.classList.add('is-open');
			typeBtn.setAttribute('aria-expanded', 'true');
		}

		function closeTypeMenu() {
			if (!typeMenu || !typeWrap || !typeBtn) return;
			typeMenu.classList.remove('is-open');
			typeWrap.classList.remove('is-open');
			typeBtn.setAttribute('aria-expanded', 'false');
		}

		if (typeBtn) {
			typeBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				typeMenu && typeMenu.classList.contains('is-open') ? closeTypeMenu() : openTypeMenu();
			});
		}

		typeOptions.forEach(function (opt) {
			opt.addEventListener('click', function () {
				typeOptions.forEach(function (o) { o.classList.remove('is-selected'); });
				opt.classList.add('is-selected');
				if (typeEl)    typeEl.value = opt.dataset.value;
				if (typeLabel) typeLabel.textContent = opt.textContent;
				applyPlaceholder();
				closeTypeMenu();
				clearTimeout(timer);
				runSearch();
			});
		});

		document.addEventListener('click', function (e) {
			if (typeMenu && typeMenu.classList.contains('is-open')) {
				if (!typeWrap.contains(e.target) && !typeMenu.contains(e.target)) {
					closeTypeMenu();
				}
			}
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && (document.body.classList.contains('pf-search-open') || overlay)) {
				close();
				btn.focus();
			}
		});

		// ── Recherche AJAX ─────────────────────────────────────────────────
		let timer     = null;
		let abortCtrl = null;

		function runSearch() {
			const q = input.value.trim();

			if (q.length < 2) {
				results.innerHTML = '';
				removeOverlay();
				return;
			}

			addOverlay();
			if (abortCtrl) abortCtrl.abort();
			abortCtrl = new AbortController();
			results.classList.add('is-loading');

			const fd = new FormData();
			fd.append('action', 'pf_global_search');
			fd.append('search', q);
			fd.append('type',   typeEl ? typeEl.value : 'all');

			fetch(config.ajax_url, { method: 'POST', body: fd, signal: abortCtrl.signal })
				.then(function (r) { return r.json(); })
				.then(function (payload) {
					results.classList.remove('is-loading');
					if (!payload || !payload.success) return;
					results.innerHTML = payload.data.html;
				})
				.catch(function (err) {
					if (err.name !== 'AbortError') {
						console.error('pf-gsearch:', err);
						results.classList.remove('is-loading');
					}
				});
		}

		// Fermer ne vide pas l'input (cf. close()) : si une requête y est encore
		// saisie, on relance la recherche dès que le champ reprend le focus
		// (résultats vidés à la fermeture, mais pas le texte).
		input.addEventListener('focus', function () {
			if (input.value.trim().length >= 2 && !results.innerHTML) runSearch();
		});

		input.addEventListener('input', function () {
			clearTimeout(timer);
			timer = setTimeout(runSearch, 250);
		});

		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); runSearch(); }
		});

		// Le déclenchement de la recherche sur changement de type est géré
		// directement dans le click handler des options ci-dessus.
	}
})();
