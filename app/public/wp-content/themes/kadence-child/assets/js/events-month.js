/**
 * Header sticky « smart-hide » des vues d'archive (liste + mois).
 *
 * Bascule .is-hidden (glisse vers le haut au scroll descendant) et .is-stuck
 * (ombre/verre une fois collé) sur la barre .pf-sticky-bar du header, comme
 * /auteurs et /catalogue (mécanisme commun .pf-sticky-bar dans style.css).
 *
 * Maintient aussi --pf-ev-header-offset = hauteur du header quand il est apparent
 * (0 quand masqué) : en vue liste, les séparateurs de mois du scroll infini collent
 * sous le header tant qu'il est visible, et remontent quand il se masque.
 *
 * La barre est re-cherchée à chaque frame : la bascule de vue de TEC (Liste/Mois)
 * se fait en AJAX et remplace le conteneur — donc le header — ce qui invaliderait
 * une référence mise en cache. Le re-query rend le comportement résilient sans
 * dépendre d'un hook AJAX de TEC.
 */
(function () {
	'use strict';

	var lastY = window.scrollY;
	var ticking = false;
	var root = document.documentElement;

	function onScroll() {
		if (ticking) return;
		ticking = true;
		window.requestAnimationFrame(function () {
			var bar = document.querySelector('header.tribe-events-header.pf-sticky-bar');
			var offset = 0;
			if (bar) {
				var y = window.scrollY;
				var dy = y - lastY;
				if (dy > 8 && y > 80)       bar.classList.add('is-hidden');
				else if (dy < -8 || y < 80) bar.classList.remove('is-hidden');
				bar.classList.toggle('is-stuck', y > 4);
				offset = bar.classList.contains('is-hidden') ? 0 : bar.offsetHeight;
			}
			root.style.setProperty('--pf-ev-header-offset', offset + 'px');
			lastY = window.scrollY;
			ticking = false;
		});
	}

	window.addEventListener('scroll', onScroll, { passive: true });
	window.addEventListener('resize', onScroll, { passive: true });
	onScroll();
})();

/**
 * Fermeture au clic extérieur du menu « S'abonner ».
 *
 * Le script natif de TEC (ical-links.js) tente déjà ce comportement, mais son
 * binding est cassé : `jQuery(document).on("click, focusin", ...)` — jQuery ne
 * découpe les types d'événements que sur les espaces, pas les virgules, donc
 * "click," (avec la virgule) ne correspond à aucun événement réel. Seul
 * "focusin" se déclenche (clic sur un élément focusable) ; un clic sur une
 * zone non focusable ne ferme jamais le menu. On simule un clic sur le
 * déclencheur pour réutiliser le toggle natif de TEC (aria-expanded, icône,
 * affichage) plutôt que de dupliquer sa logique de fermeture.
 */
(function () {
	'use strict';

	document.addEventListener('click', function (e) {
		var openTrigger = document.querySelector('.tribe-events-c-subscribe-dropdown__button-text[aria-expanded="true"]');
		if (!openTrigger || e.target.closest('.tribe-events-c-subscribe-dropdown')) return;
		var button = openTrigger.closest('.tribe-events-c-subscribe-dropdown__button');
		if (button) button.click();
	});
})();
