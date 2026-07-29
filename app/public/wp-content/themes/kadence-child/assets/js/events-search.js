/**
 * Recherche événements — barre dans le header sticky (vue liste uniquement).
 *
 * Résultats paginés par lots de PAGE_SIZE (même mécanisme que la liste
 * normale : sentinelle + IntersectionObserver en bas de la liste de
 * résultats, réutilisant les classes pf-ev-bottom/pf-ev-sentinel — donc le
 * même logo tournant et les mêmes styles, sans CSS supplémentaire). L'offset
 * est un simple compteur côté client (nombre de résultats déjà chargés pour la
 * requête courante) ; chaque nouvelle frappe (recherche différente) réinitialise
 * l'offset à 0 et remplace les résultats, un scroll vers le bas charge la suite.
 *
 * Attente d'une réponse : le retour se pose là où le résultat va apparaître —
 * filet accent au bas du sous-header pour une NOUVELLE recherche (composant
 * partagé avec la recherche globale du header, style.css), logo tournant au bas
 * des résultats pour le LOT SUIVANT. Cf. setLoading().
 *
 * Au lancement d'une recherche (1ers résultats affichés), la page remonte en
 * haut (window.scrollTo(0,0)) pour que la barre et les résultats soient
 * visibles d'emblée. Pendant la recherche, la liste à scroll infini
 * (events-infinite.js) et son échafaudage (bouton passés, sentinelle bas,
 * bouton retour) sont masqués SANS être détruits : lots déjà chargés et
 * position de scroll (mémorisée avant le saut en haut) sont préservés, puis
 * restaurés tels quels à la fermeture (vidage du champ ou bouton effacer) —
 * on revient exactement où on était, même dans les passés.
 *
 * Ré-initialisation AJAX : comme events-infinite.js/events-month.js, la
 * bascule Liste/Mois de TEC remplace tout le conteneur (donc le header et la
 * barre de recherche). Un MutationObserver détecte la nouvelle barre — ou son
 * absence en vue mois — et (ré)active le contrôleur en conséquence ; la
 * recherche en cours n'est pas conservée à travers un changement de vue.
 */
(function () {
	'use strict';

	var cfg = window.PassifloreEventsSearch;
	if (!cfg) return;

	var DEBOUNCE_MS = 250;

	var ctrl = null;

	function activate() {
		var input = document.querySelector('.pf-ev-search-input');
		if (ctrl && ctrl.input === input) return;
		if (ctrl) { ctrl.teardown(); ctrl = null; }
		if (input) ctrl = buildController(input);
	}

	function watch() {
		if (!('MutationObserver' in window)) return;
		var container = document.querySelector('[data-js="tribe-events-view"]');
		var parent = (container && container.parentNode) || document.body;
		new MutationObserver(function () { activate(); }).observe(parent, { childList: true });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { activate(); watch(); });
	} else {
		activate();
		watch();
	}

	/* ─── Contrôleur lié à une barre de recherche donnée ──────────── */
	function buildController(input) {
		var wrap     = input.closest('.pf-ev-search');
		var bar      = input.closest('.pf-sub-header');
		var clearBtn = wrap ? wrap.querySelector('.pf-ev-search-clear') : null;
		var timer     = null;
		var abortCtrl = null;
		var active    = false; // recherche actuellement affichée (liste masquée) ?
		var savedScrollY = 0;
		var resultsList  = null;
		var resultsBottomWrap = null; // pf-ev-bottom : sentinelle + logo du lot suivant
		var resultsIO    = null;
		var query    = '';
		var offset   = 0;
		var hasMore  = false;
		var pageBusy = false;
		var seq      = 0; // dernière requête gagne (cf. fetchPage)

		// Sélecteurs ':not' : excluent nos propres liste/échafaudage de résultats,
		// qui partagent leurs classes (tribe-events-calendar-list, pf-ev-bottom)
		// avec la liste réelle pour hériter du même style — sans l'exclusion,
		// une fois la nôtre créée, querySelector() la retrouverait EN PREMIER
		// (elle est insérée avant la liste réelle dans le DOM) au lieu de la
		// vraie, et toggleListVisibility() masquerait alors notre propre
		// sentinelle de résultats au lieu de celle de la liste réelle —
		// cassant silencieusement l'IntersectionObserver du scroll infini des
		// résultats après le 1er lot suivant (plus jamais « intersecting »
		// une fois masquée en display:none).
		function listEls() {
			return {
				list:   document.querySelector('.tribe-events-calendar-list:not(.pf-ev-search-results)'),
				top:    document.querySelector('.pf-ev-top'),
				bottom: document.querySelector('.pf-ev-bottom:not(.pf-ev-search-bottom)'),
				future: document.querySelector('.pf-ev-tofuture'),
			};
		}

		function toggleListVisibility(show) {
			var els = listEls();
			['list', 'top', 'bottom', 'future'].forEach(function (key) {
				if (els[key]) els[key].classList.toggle('pf-ev-search-hidden', !show);
			});
			return els;
		}

		// Échafaudage des résultats : liste + son propre déclencheur de lot suivant.
		// Mêmes classes pf-ev-bottom/pf-ev-sentinel que la liste normale → même
		// logo tournant et mêmes styles, aucun CSS propre à la recherche.
		// Créé une seule fois par session de recherche active (persiste d'une
		// requête à l'autre, comme resultsList lui-même).
		function ensureResultsScaffold() {
			if (resultsList) return;
			var els = listEls();
			if (!els.list) return;

			resultsList = document.createElement('ul');
			resultsList.className = 'tribe-events-calendar-list pf-ev-search-results';
			els.list.parentNode.insertBefore(resultsList, els.list);

			// pf-ev-search-bottom : marqueur pour listEls() (cf. commentaire plus haut),
			// pf-ev-bottom reste pour hériter du style/spinner partagés.
			resultsBottomWrap = el('div', 'pf-ev-bottom pf-ev-search-bottom');
			var sentinel = el('div', 'pf-ev-sentinel');
			sentinel.setAttribute('aria-hidden', 'true');
			resultsBottomWrap.appendChild(sentinel);
			resultsList.parentNode.insertBefore(resultsBottomWrap, resultsList.nextSibling);

			if ('IntersectionObserver' in window) {
				resultsIO = new IntersectionObserver(function (entries) {
					for (var i = 0; i < entries.length; i++) {
						if (entries[i].isIntersecting) { loadMore(); break; }
					}
				}, { rootMargin: '0px 0px 600px 0px' });
				resultsIO.observe(sentinel);
			}
		}

		function showResults(html, replace) {
			if (!active) {
				savedScrollY = window.scrollY;
				active = true;
				window.scrollTo(0, 0);
			}
			toggleListVisibility(false);
			ensureResultsScaffold();
			if (!resultsList) return;
			if (replace) resultsList.innerHTML = html;
			else resultsList.insertAdjacentHTML('beforeend', html);
		}

		// Attente d'une réponse — DEUX retours, chacun là où le résultat va
		// apparaître, jamais les deux à la fois :
		//  - nouvelle recherche → filet accent au bas du sous-header (composant
		//    partagé avec la recherche globale, cf. style.css). Le bas des
		//    résultats ne conviendrait pas : à la 1re frappe l'échafaudage
		//    n'existe pas encore, ensuite il est repoussé sous les résultats
		//    précédents — donc hors écran, l'attente ne se voyait pas.
		//  - lot suivant → logo tournant au bas des résultats (.pf-ev-bottom,
		//    events-infinite.css), comme le scroll infini de la liste normale.
		//    Le filet du sous-header y serait invisible : ce lot est déclenché
		//    par un scroll DESCENDANT, qui rétracte justement la barre
		//    (.pf-sticky-bar.is-hidden, opacity 0 — mesuré).
		function setLoading(bottom) {
			var target = bottom ? resultsBottomWrap : bar;
			if (target) target.classList.add('is-loading');
		}
		// Éteint les deux sans avoir à se souvenir duquel a été allumé.
		function clearLoading() {
			if (bar) bar.classList.remove('is-loading');
			if (resultsBottomWrap) resultsBottomWrap.classList.remove('is-loading');
		}

		function restoreList() {
			if (!active) return;
			active = false;
			seq++; // invalide la requête en vol : sa réponse ne rallumera rien
			clearLoading();
			if (abortCtrl) abortCtrl.abort();
			if (resultsIO) { resultsIO.disconnect(); resultsIO = null; }
			removeNode(resultsList); resultsList = null;
			removeNode(resultsBottomWrap); resultsBottomWrap = null;
			toggleListVisibility(true);
			window.scrollTo(0, savedScrollY);
			query = ''; offset = 0; hasMore = false; pageBusy = false;
		}

		// Garde de séquence (`mine`/`seq`) : abort() rejette la requête précédente
		// de façon ASYNCHRONE, donc son .catch() s'exécute APRÈS le setLoading()
		// de la nouvelle — sans garde, il éteindrait le retour d'une requête
		// encore en vol. Même motif que la recherche carte (events-map.js).
		function fetchPage(isNewSearch) {
			if (abortCtrl) abortCtrl.abort();
			abortCtrl = new AbortController();
			var mine = ++seq;
			pageBusy = true;
			setLoading(! isNewSearch);

			var fd = new FormData();
			fd.append('action', 'pf_events_search');
			fd.append('search', query);
			fd.append('offset', isNewSearch ? 0 : offset);

			fetch(cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin', signal: abortCtrl.signal })
				.then(function (r) { return r.json(); })
				.then(function (payload) {
					if (mine !== seq) return; // une requête plus récente est partie
					pageBusy = false;
					clearLoading();
					if (!payload || !payload.success) return;
					var data = payload.data || {};
					if (isNewSearch) { offset = 0; showResults(data.html || '', true); }
					else showResults(data.html || '', false);
					offset += data.count || 0;
					hasMore = !!data.has_more;
				})
				.catch(function (err) {
					if (mine !== seq) return;
					pageBusy = false;
					clearLoading();
					if (err.name !== 'AbortError') console.error('pf-events-search:', err);
				});
		}

		function loadMore() {
			if (pageBusy || !hasMore || !query) return;
			fetchPage(false);
		}

		function runSearch() {
			var q = input.value.trim();
			if (q.length < 2) { restoreList(); return; }
			query = q;
			fetchPage(true);
		}

		// Placeholder court sur mobile (≤768px), long au-dessus — mêmes textes et même
		// mécanisme que la recherche globale du header (recherche-globale.js) pour le
		// type « Événements », repris ici tels quels (barre sans sélecteur de type).
		// Sur mobile, le champ affiche un simple « Événement » au repos (la barre
		// resserrée, à côté de « S'abonner », n'a pas la place pour le placeholder
		// complet sans le tronquer) et ne bascule sur le texte détaillé qu'au focus.
		var phMq = null;
		function applyPlaceholder() {
			if (!phMq) return;
			if (!phMq.matches) { input.placeholder = phDefault; return; }
			input.placeholder = (document.activeElement === input) ? input.dataset.placeholderSm : cfg.placeholder_mobile;
		}
		var phDefault = input.getAttribute('placeholder');
		if (input.dataset.placeholderSm) {
			phMq = window.matchMedia('(max-width: 768px)');
			applyPlaceholder();
			phMq.addEventListener('change', applyPlaceholder);
		}

		// « is-searching » (focus ou texte saisi) — même mécanisme que
		// .pf-catalogue-bar côté /catalogue : sur mobile, ça réduit le bouton
		// « S'abonner » pendant que la recherche grandit dans l'espace libéré.
		function refreshSearchState() {
			applyPlaceholder();
			if (!bar) return;
			var hasText = input.value !== '';
			var focused = document.activeElement === input;
			bar.classList.toggle('is-searching', hasText || focused);
		}

		input.addEventListener('focus', refreshSearchState);
		input.addEventListener('blur', refreshSearchState);
		input.addEventListener('input', function () {
			refreshSearchState();
			clearTimeout(timer);
			timer = setTimeout(runSearch, DEBOUNCE_MS);
		});
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); runSearch(); }
		});
		if (clearBtn) {
			clearBtn.addEventListener('click', function () {
				input.value = '';
				refreshSearchState();
				restoreList();
				input.focus();
			});
		}

		function teardown() {
			clearTimeout(timer);
			seq++;
			if (abortCtrl) abortCtrl.abort();
			if (phMq) phMq.removeEventListener('change', applyPlaceholder);
			if (resultsIO) resultsIO.disconnect();
			// Pas de restauration de scroll ni de removeNode ici : la barre disparaît
			// parce que TEC remplace tout le conteneur (changement de vue) — resultsList
			// et resultsBottomWrap partent avec lui, un scrollTo serait sans objet et
			// risquerait de sauter au mauvais moment.
			active = false;
			resultsList = null;
			resultsBottomWrap = null;
			resultsIO = null;
		}

		return { input: input, teardown: teardown };
	}

	function el(tag, cls) { var n = document.createElement(tag); n.className = cls; return n; }
	function removeNode(n) { if (n && n.parentNode) n.parentNode.removeChild(n); }
})();
