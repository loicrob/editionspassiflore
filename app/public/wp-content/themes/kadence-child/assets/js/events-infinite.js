/**
 * Scroll infini bidirectionnel — vue liste The Events Calendar.
 *
 * Bas : IntersectionObserver classique → ajoute les pages à venir.
 * Haut : le 1er lot d'événements passés ne se charge qu'au clic sur le bouton rond
 *        (flèche vers le haut) — aucun geste de scroll/molette/tactile ne le déclenche.
 *        Une fois ce 1er lot chargé, un IntersectionObserver prend le relais pour les
 *        lots suivants au fil du scroll vers le haut. Ancrage du scroll au préfixage
 *        (anti-saut) + dé-dup des séparateurs de mois aux frontières de lots.
 * Retour : une fois descendu dans les passés, un bouton rond fixe en bas (« Événements
 *        à venir », flèche bas) ramène au premier événement à venir.
 *
 * Les curseurs sont les URLs « préc. » / « suiv. » que TEC rend dans sa nav native :
 * on les lit, on masque la nav, et on demande à l'endpoint pf_events_feed le lot suivant.
 *
 * Filet anti-saut (checkScrollSkip, sur onScroll) : un scroll rapide peut franchir la
 * marge de déclenchement d'un IntersectionObserver en un seul frame sans jamais le
 * traverser en position « intersecting » (repro : navigation par saut, ex. touche Fin,
 * plutôt qu'un scroll continu) — sans ce filet, le dernier lot (bas ou haut) ne se
 * charge alors jamais, même une fois arrivé en bout de liste.
 *
 * Ré-initialisation AJAX : la bascule de vue (Liste/Mois) de TEC remplace tout le
 * conteneur. Un MutationObserver sur le parent stable ré-active le contrôleur dès
 * qu'une nouvelle liste apparaît (et le démonte sinon).
 */
(function () {
	'use strict';

	var cfg = window.PassifloreEventsFeed;
	if (!cfg) return;
	// (La restauration auto du scroll est désactivée très tôt, dans le <head>, via
	//  Passiflore_Events_Feed::print_scroll_restoration — sinon trop tard au rechargement.)

	var SEP_SEL        = '.tribe-events-calendar-list__month-separator';
	var SEP_TEXT_SEL   = '.tribe-events-calendar-list__month-separator-text';
	var EVENT_ROW_SEL  = '.tribe-events-calendar-list__event-row';
	var SCROLL_MARGIN_PX = 600; // Doit rester égal à la marge utilisée par les rootMargin ci-dessous.
	var BOTTOM_MARGIN  = '0px 0px ' + SCROLL_MARGIN_PX + 'px 0px';
	var TOP_MARGIN     = SCROLL_MARGIN_PX + 'px 0px 0px 0px';
	var EVENT_ROW_CLS  = 'tribe-events-calendar-list__event-row';
	var ARROW_UP   = 'M12 19V5M12 5L5 12M12 5l7 7';
	var ARROW_DOWN = 'M12 5v14M12 19l-7-7M12 19l7-7';

	var ctrl = null;       // contrôleur courant, lié à une <ul> de liste précise.
	var initial = true;    // première activation = chargement de page (vs ré-init AJAX).

	/* ─── (Ré)activation selon la présence d'une liste ────────────── */
	function activate() {
		var list = document.querySelector('.tribe-events-calendar-list');
		if (ctrl && ctrl.list === list) return; // déjà actif sur cette liste.
		if (ctrl) { ctrl.teardown(); ctrl = null; }
		if (list) {
			ctrl = buildController(list);
			// Au tout 1er chargement (pas une bascule AJAX), on force le haut : ceinture
			// et bretelles avec le `scrollRestoration=manual` du <head> (certains
			// navigateurs restaurent quand même un scroll partiel pendant le rendu).
			if (initial) forceTop();
		}
		initial = false;
	}

	function forceTop() {
		var go = function () { window.scrollTo(0, 0); };
		if (document.readyState === 'complete') { requestAnimationFrame(go); }
		else { window.addEventListener('load', function () { requestAnimationFrame(go); }, { once: true }); }
	}

	function watch() {
		if (!('MutationObserver' in window)) return;
		var container = document.querySelector('[data-js="tribe-events-view"]');
		var parent = (container && container.parentNode) || document.body;
		new MutationObserver(function () { activate(); })
			.observe(parent, { childList: true });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { activate(); watch(); });
	} else {
		activate();
		watch();
	}

	/* ─── Contrôleur lié à une liste donnée ───────────────────────── */
	function buildController(list) {
		var down = { cursor: '', busy: false, done: false };
		var up   = { cursor: '', busy: false, done: false };
		var ioDown = null, ioUp = null;
		var topWrap = null, pastBtn = null, bottomWrap = null;
		var futureWrap = null, futureBtn = null;
		var scrollTick = false, settleTimer = null;

		// Premier événement à venir (cible du bouton retour). Référence figée : les rows
		// ne sont jamais supprimées (seuls les séparateurs le sont par la dé-dup), et les
		// passés sont préfixés AU-DESSUS → cette row reste le 1er « à venir ».
		var upcomingAnchor = list.querySelector(EVENT_ROW_SEL);

		// IDs déjà rendus (page initiale + lots déjà ajoutés), pour dé-dupliquer les
		// lots entrants : la pagination de TEC est un simple LIMIT/OFFSET sans tri
		// secondaire déterministe (event_date, event_duration) — deux événements à
		// égalité stricte peuvent, selon l'état de l'index/cache MySQL, se retrouver
		// tous les deux en bord de page consécutives. On ne peut pas empêcher ça côté
		// serveur sans changer le tri global du site, donc on filtre côté client.
		var insertedIds = collectEventIds(list);

		/* Curseurs initiaux depuis la nav native, puis on la masque. */
		var nav   = list.parentNode ? list.parentNode.querySelector('.tribe-events-calendar-list-nav') : null;
		if (!nav) nav = document.querySelector('.tribe-events-calendar-list-nav');
		var nextA = nav ? nav.querySelector('a.tribe-events-c-nav__next') : null;
		var prevA = nav ? nav.querySelector('a.tribe-events-c-nav__prev') : null;
		down.cursor = nextA ? nextA.href : '';
		up.cursor   = prevA ? prevA.href : '';
		if (nav) nav.setAttribute('hidden', ''); // JS seulement → repli no-JS intact.

		/* Échafaudage DOM (bas). */
		bottomWrap = el('div', 'pf-ev-bottom');
		var bottomSentinel = el('div', 'pf-ev-sentinel');
		bottomSentinel.setAttribute('aria-hidden', 'true');
		bottomWrap.appendChild(bottomSentinel);
		list.parentNode.insertBefore(bottomWrap, list.nextSibling);

		if (up.cursor) buildPastButton();
		if (up.cursor && upcomingAnchor) buildFutureBtn();

		/* Bas : infinite scroll automatique. */
		if (down.cursor && 'IntersectionObserver' in window) {
			ioDown = new IntersectionObserver(function (entries) {
				for (var i = 0; i < entries.length; i++) {
					if (entries[i].isIntersecting) { load('next'); break; }
				}
			}, { rootMargin: BOTTOM_MARGIN });
			ioDown.observe(bottomSentinel);
		}

		/* Suivi du scroll → visibilité du bouton « retour vers les à-venir ». */
		window.addEventListener('scroll', onScroll, { passive: true });
		window.addEventListener('resize', onScroll, { passive: true });

		// Relais automatique (scroll) pour les lots passés SUIVANTS, armé seulement après
		// le 1er lot chargé au clic — le 1er lot, lui, ne se charge jamais tout seul.
		function armTopObserver() {
			if (ioUp || !topWrap || !('IntersectionObserver' in window)) return;
			ioUp = new IntersectionObserver(function (entries) {
				for (var i = 0; i < entries.length; i++) {
					if (entries[i].isIntersecting) { load('prev'); break; }
				}
			}, { rootMargin: TOP_MARGIN });
			ioUp.observe(topWrap);
		}

		/* Chargement d'un lot. */
		function load(direction) {
			var st = direction === 'prev' ? up : down;
			if (st.busy || st.done || !st.cursor) return;
			st.busy = true;
			setLoading(direction, true);

			var fd = new FormData();
			fd.append('action', 'pf_events_feed');
			fd.append('nonce', cfg.nonce);
			fd.append('direction', direction);
			fd.append('url', st.cursor);

			fetch(cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (p) {
					st.busy = false;
					setLoading(direction, false);
					if (!p || !p.success || !p.data || !p.data.html) { finish(direction); return; }

					if (direction === 'next') appendBatch(p.data.html);
					else                      prependBatch(p.data.html);

					st.cursor = p.data.has_more ? p.data.next_url : '';

					if (!p.data.has_more) finish(direction);
					else if (direction === 'prev') armTopObserver();
				})
				.catch(function () {
					st.busy = false;
					setLoading(direction, false);
				});
		}

		function finish(direction) {
			var st = direction === 'prev' ? up : down;
			st.done = true;
			st.cursor = '';
			if (direction === 'next') {
				if (ioDown) ioDown.disconnect();
			} else {
				if (ioUp) ioUp.disconnect();
				if (topWrap) {
					topWrap.classList.add('is-done');
					topWrap.innerHTML = '<div class="pf-ev-msg is-visible">Début des archives.</div>';
				}
			}
		}

		/* Insertion + dé-dup des séparateurs de mois. */
		function appendBatch(html) {
			var tmp = parse(html);
			dedupeEvents(tmp);
			var seps = tmp.querySelectorAll(SEP_SEL);
			var liveLast = lastSeparator();
			if (seps.length && liveLast && monthKey(seps[0]) === monthKey(liveLast)) {
				seps[0].parentNode.removeChild(seps[0]);
			}
			while (tmp.firstChild) list.appendChild(tmp.firstChild);
		}

		function prependBatch(html) {
			var tmp = parse(html);
			dedupeEvents(tmp);
			var seps = tmp.querySelectorAll(SEP_SEL);
			var liveFirst = firstSeparator();
			var dropLiveFirst = seps.length && liveFirst &&
				monthKey(seps[seps.length - 1]) === monthKey(liveFirst);

			var frag = document.createDocumentFragment();
			while (tmp.firstChild) frag.appendChild(tmp.firstChild);

			// Ancrage : mesurer avant les deux mutations, restaurer après.
			var prevH = document.documentElement.scrollHeight;
			var prevT = window.scrollY;
			if (dropLiveFirst) liveFirst.parentNode.removeChild(liveFirst);
			list.insertBefore(frag, list.firstChild);
			var added = document.documentElement.scrollHeight - prevH;
			window.scrollTo(0, Math.round(prevT + added));
			updateFutureBtn();
		}

		/* ─── Déclencheur du 1er lot d'événements passés (haut) — clic uniquement ── */
		function buildPastButton() {
			topWrap = el('div', 'pf-ev-top');
			pastBtn = roundBtn('pf-ev-pastbtn', 'Charger les événements passés', ARROW_UP, true);
			topWrap.appendChild(pastBtn);
			topWrap.appendChild(btnLabel('Événements précédents'));
			list.parentNode.insertBefore(topWrap, list);

			pastBtn.addEventListener('click', onPastClick);
		}

		function onPastClick() {
			if (!up.busy && !up.done && up.cursor) load('prev');
		}

		function setLoading(direction, on) {
			var wrap = direction === 'prev' ? topWrap : bottomWrap;
			if (wrap) wrap.classList.toggle('is-loading', !!on);
		}

		/* ─── Bouton fixe « retour vers les à-venir » (bas) ───────── */
		function buildFutureBtn() {
			futureWrap = el('div', 'pf-ev-tofuture');
			futureBtn = roundBtn('pf-ev-futurebtn', 'Aller aux événements à venir', ARROW_DOWN, false);
			futureWrap.appendChild(btnLabel('Événements à venir'));
			futureWrap.appendChild(futureBtn);
			document.body.appendChild(futureWrap);
			futureBtn.addEventListener('click', onFutureClick);
		}

		function onFutureClick() {
			if (!upcomingAnchor) return;
			var off = stickyOffset() + 56;
			var y = upcomingAnchor.getBoundingClientRect().top + window.scrollY - off;
			window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
		}

		// Dernier événement passé = la row d'événement juste avant le 1er à-venir (en
		// sautant un éventuel séparateur de mois). null tant qu'aucun passé n'est chargé.
		function lastPastEvent() {
			var e = upcomingAnchor ? upcomingAnchor.previousElementSibling : null;
			while (e && !(e.classList && e.classList.contains(EVENT_ROW_CLS))) {
				e = e.previousElementSibling;
			}
			return e;
		}

		// Le bouton « retour vers les à-venir » n'apparaît que lorsque le bas du dernier
		// événement passé atteint (ou dépasse) le bas de l'écran — donc quand plus aucun
		// événement à venir n'est visible.
		function updateFutureBtn() {
			if (!futureWrap) return;
			var lpe = lastPastEvent();
			var show = !!lpe && lpe.getBoundingClientRect().bottom >= window.innerHeight - 1;
			futureWrap.classList.toggle('is-visible', show);
		}

		// Marque le séparateur de mois collé en haut (.is-stuck → ombre) et le « pousse »
		// vers le haut quand le séparateur du mois suivant arrive, pour éviter qu'ils se
		// chevauchent (sinon plusieurs séparateurs s'empilent à la même ligne sticky et
		// leurs ombres se superposent). Tous les <li> sont frères d'un même <ul>, donc le
		// containing block du sticky s'étend jusqu'au bas → pas de « push » CSS natif : on
		// le simule par un translateY sur le séparateur sortant.
		// Haut sticky effectif = --pf-sticky-offset + --pf-ev-header-offset (header).
		function updateStuck() {
			var cs = getComputedStyle(document.documentElement);
			var off = (parseFloat(cs.getPropertyValue('--pf-sticky-offset')) || 0)
					+ (parseFloat(cs.getPropertyValue('--pf-ev-header-offset')) || 0);
			var seps = list.querySelectorAll(SEP_SEL);
			// 1) Remettre tous les transforms à zéro AVANT toute mesure : un translateY
			//    résiduel fausserait le rect du séparateur concerné.
			for (var i = 0; i < seps.length; i++) seps[i].style.transform = '';
			// 2) Mesurer en position naturelle, marquer l'état collé et appliquer le push.
			for (var j = 0; j < seps.length; j++) {
				var rect   = seps[j].getBoundingClientRect();
				var stuck  = rect.top <= off + 1; // rect.top d'un collé == la ligne sticky
				var pushed = false;
				seps[j].classList.toggle('is-stuck', stuck);
				if (stuck && j + 1 < seps.length) {
					// Le mois suivant empiète-t-il sur le séparateur collé ?
					var nextTop = seps[j + 1].getBoundingClientRect().top;
					var overlap = (off + rect.height) - nextTop;
					if (overlap > 0) {
						// Pousse le sortant vers le haut : son bas reste collé au haut du
						// suivant (qui, plus bas dans le DOM, le recouvre → une seule ombre).
						seps[j].style.transform = 'translateY(' + (-overlap) + 'px)';
						pushed = true; // → perd son z-index (is-pushed : 11 → 10)
					}
				}
				seps[j].classList.toggle('is-pushed', pushed);
			}
		}

		function onScroll() {
			if (!scrollTick) {
				scrollTick = true;
				window.requestAnimationFrame(function () { updateFutureBtn(); updateStuck(); checkScrollSkip(); scrollTick = false; });
			}
			// Re-calcul après stabilisation : un reflow tardif (transition du header,
			// images) peut décaler la position des séparateurs après le rAF du scroll.
			clearTimeout(settleTimer);
			settleTimer = setTimeout(function () { updateStuck(); updateFutureBtn(); }, 220);
		}

		// Filet de sécurité : un scroll rapide (molette véloce, ascenseur glissé, touche Fin)
		// peut franchir la marge de déclenchement d'un IntersectionObserver en un seul frame
		// sans jamais passer par un état « intersecting » — le lot suivant ne se charge alors
		// jamais tout seul, même si l'utilisateur est bien arrivé au bas/haut de la liste. On
		// revérifie ici, à chaque scroll, la position réelle des sentinelles pour rattraper ce cas.
		function checkScrollSkip() {
			if (down.cursor && !down.busy && !down.done) {
				if (bottomSentinel.getBoundingClientRect().top <= window.innerHeight + SCROLL_MARGIN_PX) load('next');
			}
			// Uniquement une fois le 1er lot passé chargé (ioUp armé) — avant ça, le
			// déclenchement reste volontairement réservé au clic (cf. armTopObserver).
			if (ioUp && topWrap && up.cursor && !up.busy && !up.done) {
				if (topWrap.getBoundingClientRect().bottom >= -SCROLL_MARGIN_PX) load('prev');
			}
		}

		/* Helpers liés à la liste. */
		function monthKey(li) {
			if (!li || !li.querySelector) return '';
			var t = li.querySelector(SEP_TEXT_SEL);
			return t ? t.textContent.trim().toLowerCase() : '';
		}
		function firstSeparator() { return list.querySelector(SEP_SEL); }
		function lastSeparator() { var s = list.querySelectorAll(SEP_SEL); return s.length ? s[s.length - 1] : null; }

		// Retire du lot entrant (tmp) tout événement déjà rendu (même ID), et
		// enregistre les nouveaux. N'affecte jamais les rows déjà en direct dans `list`.
		function dedupeEvents(tmp) {
			var rows = tmp.querySelectorAll(EVENT_ROW_SEL);
			for (var i = 0; i < rows.length; i++) {
				var id = eventId(rows[i]);
				if (!id) continue;
				if (insertedIds[id]) { rows[i].parentNode.removeChild(rows[i]); }
				else { insertedIds[id] = true; }
			}
		}

		/* Démontage (avant ré-activation sur une nouvelle liste). */
		function teardown() {
			if (ioDown) ioDown.disconnect();
			if (ioUp) ioUp.disconnect();
			window.removeEventListener('scroll', onScroll);
			window.removeEventListener('resize', onScroll);
			if (pastBtn) pastBtn.removeEventListener('click', onPastClick);
			if (futureBtn) futureBtn.removeEventListener('click', onFutureClick);
			clearTimeout(settleTimer);
			// Efface tout « push » laissé sur les séparateurs (transform + is-pushed).
			var seps = list.querySelectorAll(SEP_SEL);
			for (var i = 0; i < seps.length; i++) {
				seps[i].style.transform = '';
				seps[i].classList.remove('is-pushed');
			}
			removeNode(topWrap);
			removeNode(bottomWrap);
			removeNode(futureWrap);
		}

		return { list: list, teardown: teardown };
	}

	/* ─── Helpers globaux ─────────────────────────────────────────── */
	function roundBtn(cls, label, path, withSpinner) {
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'pf-roundbtn pf-ev-roundbtn ' + cls;
		b.setAttribute('aria-label', label);
		b.innerHTML =
			'<svg class="pf-ev-arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">' +
			'<path d="' + path + '" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
			(withSpinner ? '<span class="pf-ev-spinner" aria-hidden="true"></span>' : '');
		return b;
	}
	function stickyOffset() {
		return parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--pf-sticky-offset')) || 0;
	}
	function btnLabel(text) {
		var s = document.createElement('span');
		s.className = 'pf-ev-btn-label';
		s.setAttribute('aria-hidden', 'true'); // doublon décoratif de l'aria-label du bouton.
		s.textContent = text;
		return s;
	}
	function el(tag, cls) { var n = document.createElement(tag); n.className = cls; return n; }
	function parse(html) { var u = document.createElement('ul'); u.innerHTML = String(html).trim(); return u; }
	function removeNode(n) { if (n && n.parentNode) n.parentNode.removeChild(n); }

	// ID de l'événement porté par une row (classe `post-{ID}` posée par get_post_class()
	// sur le date-tag et sur l'<article>, cf. tribe/events/v2/list/event.php).
	function eventId(li) {
		var marker = li.querySelector('[class*="post-"]');
		if (!marker) return null;
		var m = /(?:^|\s)post-(\d+)(?:\s|$)/.exec(marker.className);
		return m ? m[1] : null;
	}
	function collectEventIds(container) {
		var ids = {};
		var rows = container.querySelectorAll(EVENT_ROW_SEL);
		for (var i = 0; i < rows.length; i++) {
			var id = eventId(rows[i]);
			if (id) ids[id] = true;
		}
		return ids;
	}
})();
