/**
 * Bouton « S'abonner » du header (vues d'archive) : action directe vers le
 * calendrier probable du visiteur (iOS/Mac → Apple Calendar, sinon → Google
 * Calendar) — un seul clic suffit, plus besoin d'ouvrir un menu à 6 choix.
 *
 * Les 5 autres agendas restent joignables via le bouton kebab ajouté à côté :
 * il ne porte aucun handler dédié, un clic à l'intérieur de
 * `.tribe-events-c-subscribe-dropdown__button` suffit à déclencher le toggle
 * natif de TEC (délégué sur tout le sous-arbre, cf. ical-links.js) — on
 * réutilise sa plomberie (aria-expanded, animation, fermeture au clic
 * extérieur déjà réparée dans events-month.js) plutôt que d'en écrire une.
 *
 * Amélioration progressive : sans JS (ou si l'option détectée est absente du
 * menu), le bouton garde son comportement natif TEC — rien n'est modifié
 * côté serveur.
 *
 * Visuellement, le pill uni devient deux moitiés soudées via .pf-splitbtn/
 * .pf-splitbtn--solid (style.css) — même motif que la paire rayon/thématiques
 * du catalogue (Littérature ⋮ / Culture Sud-Ouest ⋮), même hauteur que le
 * sélecteur Liste/Mois/Carte (formule padding/font-size/line-height commune
 * avec .pf-switch__btn).
 *
 * Ré-init AJAX : même idiome que events-infinite.js/events-search.js
 * (MutationObserver sur le parent stable de [data-js="tribe-events-view"]).
 */
(function () {
	'use strict';

	var BUTTON_SEL    = '.tribe-events-c-subscribe-dropdown__button';
	var TEXT_SEL      = '.tribe-events-c-subscribe-dropdown__button-text';
	var ITEM_LINK_SEL = '.tribe-events-c-subscribe-dropdown__list-item-link';
	var ENHANCED_ATTR = 'data-pf-ical-enhanced';

	var LABELS = { gcal: 'Google Calendar', ical: 'Apple Calendar' };

	function detectPrimarySlug() {
		var ua = navigator.userAgent || '';
		var isIpad = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
		var isApple = /iPad|iPhone|iPod|Macintosh/.test(ua) || isIpad;
		return isApple ? 'ical' : 'gcal';
	}

	function enhance(button) {
		if (button.hasAttribute(ENHANCED_ATTR)) return;
		var textBtn = button.querySelector(TEXT_SEL);
		var container = button.closest('.tribe-events-c-subscribe-dropdown');
		if (!textBtn || !container) return;

		var slug = detectPrimarySlug();
		var item = container.querySelector(
			'.tribe-events-c-subscribe-dropdown__list-item--' + slug + ' ' + ITEM_LINK_SEL
		);
		if (!item) return; // Option absente du menu : on laisse le comportement natif intact.

		button.setAttribute(ENHANCED_ATTR, '1');
		button.classList.add('pf-splitbtn', 'pf-splitbtn--solid');
		textBtn.classList.add('pf-splitbtn__main');

		var label = LABELS[slug] || item.textContent.trim();
		textBtn.textContent = 'Ajouter à ' + label;
		textBtn.setAttribute('aria-label', 'Ajouter les événements à ' + label);

		textBtn.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation(); // N'ouvre plus le menu natif TEC (cf. commentaire d'en-tête).
			item.click();
		});

		var more = document.createElement('button');
		more.type = 'button';
		more.className = 'pf-splitbtn__more';
		more.setAttribute('aria-haspopup', 'true');
		more.setAttribute('aria-label', 'Autres agendas');
		more.textContent = '⋮';
		button.appendChild(more);
	}

	function activate() {
		var button = document.querySelector(BUTTON_SEL);
		if (button) enhance(button);
	}

	function watch() {
		if (!('MutationObserver' in window)) return;
		var container = document.querySelector('[data-js="tribe-events-view"]');
		var parent = (container && container.parentNode) || document.body;
		new MutationObserver(function () { activate(); }).observe(parent, { childList: true, subtree: true });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { activate(); watch(); });
	} else {
		activate();
		watch();
	}
})();
