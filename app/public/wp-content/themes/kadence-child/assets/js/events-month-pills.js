/**
 * Vue mois — état « foncé » des pastilles d'événements (mono-jour + multi-jours).
 *
 * Contrôleur unique, par délégation sur `document` (résiste aux bascules de vue AJAX
 * de TEC : aucun écouteur par élément à ré-attacher). Il NE pilote PAS le popup —
 * l'ouverture/fermeture est entièrement native (TEC/tooltipster) :
 *   - mono-jour : toute la pastille `-details` est l'ancre-déclencheur (override
 *     calendar-event.php) → survol de la pastille = ouverture native ;
 *   - multi-jours : TEC pose un déclencheur par jour → le popup s'ouvre au-dessus du
 *     jour survolé, nativement.
 * (Une version antérieure forçait l'ouverture via l'API tooltipster ; pour les
 * multi-jours cela ouvrait EN PLUS le popup du 1er jour — les N déclencheurs d'un même
 * événement partagent UN seul contenu non cloné, que le popup natif du jour survolé
 * « volait », laissant un carré blanc vide sur le 1er jour. On laisse donc TEC gérer le
 * popup, un seul à la fois.)
 *
 * Rôle restant, que le CSS seul ne peut pas couvrir :
 *   1. multi-jours — noircir TOUTE la barre (son fond est porté par le 1er segment)
 *      quel que soit le jour survolé (les segments sont des éléments frères, dans des
 *      cellules distinctes → pas de sélecteur `:hover` commun) ;
 *   2. MAINTENIR le foncé tant que le popup est dévoilé, curseur parti sur le popup
 *      (le `:hover` de la pastille est alors perdu) — pour mono ET multi.
 * Il pose/retire pour cela la classe `.pf-event-hot` (→ --pf-accent-dark, events.css).
 *
 * Repli sans JS : `:hover` (events.css) noircit le survol DIRECT de la pastille ; le
 * popup fonctionne nativement. Seuls la persistance et le multi-segment sont perdus.
 */
(function () {
	'use strict';

	var HOT = 'pf-event-hot';
	var CLOSE_GRACE = 200;  // délai avant de retirer le foncé (laisse passer pastille ↔ popup)

	var SINGLE = '.tribe-events-calendar-month__calendar-event';
	var MULTI  = '.tribe-events-calendar-month__multiday-event[data-event-id]';
	var TRIGGER = '[data-js~="tribe-events-tooltip"]';

	var activeId = null;
	var closeTimer = null;

	// ── Résolution élément → ID d'événement (pastille, segment vide, couches
	//    invisibles empilées par TEC au-dessus de la barre, ou popup lui-même) ──
	function idForElement(el) {
		if (!el || !el.closest) return null;

		var art = el.closest(SINGLE);
		if (art) return idFromSingleArticle(art);

		var seg = el.closest(MULTI);
		if (seg) return seg.getAttribute('data-event-id');

		var base = el.closest('.tooltipster-base');
		if (base) {
			var content = base.querySelector('[id^="tribe-events-tooltip-content-"]');
			return content ? content.id.slice('tribe-events-tooltip-content-'.length) : null;
		}
		return null;
	}

	function idFromSingleArticle(art) {
		var trig = art.querySelector(TRIGGER);
		if (trig) {
			var dc = trig.getAttribute('data-tooltip-content') || '';
			var m = dc.match(/tribe-events-tooltip-content-(\d+)/);
			if (m) return m[1];
		}
		var pc = art.className.match(/\bpost-(\d+)\b/);
		return pc ? pc[1] : null;
	}

	// ── ID → éléments à noircir (mono : la pastille ; multi : la barre visible) ──
	function darkenTargets(id) {
		var art = document.querySelector(SINGLE + '.post-' + id);
		if (art) {
			var d = art.querySelector('.tribe-events-calendar-month__calendar-event-details');
			return d ? [ d ] : [];
		}
		var inner = document.querySelector(
			'.tribe-events-calendar-month__multiday-event[data-event-id="' + id + '"] .tribe-events-calendar-month__multiday-event-bar-inner'
		);
		return inner ? [ inner ] : [];
	}

	function addHot(id)    { darkenTargets(id).forEach(function (el) { el.classList.add(HOT); }); }
	function removeHot(id) { darkenTargets(id).forEach(function (el) { el.classList.remove(HOT); }); }

	function setActive(id) {
		clearTimeout(closeTimer);
		if (activeId === id) return;
		if (activeId !== null) removeHot(activeId);
		activeId = id;
		addHot(id);
	}

	function scheduleClose() {
		clearTimeout(closeTimer);
		closeTimer = setTimeout(function () {
			if (activeId !== null) { removeHot(activeId); activeId = null; }
		}, CLOSE_GRACE);
	}

	document.addEventListener('mouseover', function (e) {
		var id = idForElement(e.target);
		if (id !== null) setActive(id);
	});

	document.addEventListener('mouseout', function (e) {
		var toId = e.relatedTarget ? idForElement(e.relatedTarget) : null;
		if (toId !== null) setActive(toId);
		else scheduleClose();
	});
})();
