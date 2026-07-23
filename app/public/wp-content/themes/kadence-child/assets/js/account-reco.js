/**
 * Recommandations du compte (accueil) :
 *  - Badge par livre (au-dessus de la couverture) : clic = ouvre/ferme
 *    l'explication (le logo Passiflore devient une croix). Modèle CLIC, pas
 *    survol. Le clic ne doit jamais suivre le lien de la fiche livre.
 *  - Infobulle du titre « Suggestions de Passiflore » : câblée via le composant
 *    partagé window.pfTooltip.wire (pf-tooltip.js, dépendance déclarée).
 */
(function () {
	'use strict';

	// Infobulle du titre (composant partagé). Inerte si absente ou si pf-tooltip
	// n'est pas chargé.
	if (window.pfTooltip && window.pfTooltip.wire) {
		document.querySelectorAll('.pf-account-reco .pf-numerique-tip').forEach(function (tip) {
			window.pfTooltip.wire(tip);
		});
	}

	function closeAll(except) {
		document.querySelectorAll('.pf-book-reco.is-open').forEach(function (el) {
			if (el !== except) el.classList.remove('is-open');
		});
	}

	function toggle(wrap) {
		var isOpen = wrap.classList.contains('is-open');
		closeAll(wrap);
		wrap.classList.toggle('is-open', !isOpen);
	}

	document.addEventListener('click', function (e) {
		var badge = e.target.closest ? e.target.closest('.pf-book-reco-badge') : null;
		if (badge) {
			// Clic sur le badge (logo → croix) : bascule, sans suivre le lien.
			e.preventDefault();
			e.stopPropagation();
			var wrap = badge.closest('.pf-book-reco');
			if (wrap) toggle(wrap);
			return;
		}

		// Clic sur l'explication ouverte : la refermer sans naviguer vers la fiche.
		var tip = e.target.closest ? e.target.closest('.pf-book-reco') : null;
		if (tip) {
			e.preventDefault();
			e.stopPropagation();
		}
		closeAll();
	});

	// Clavier : Entrée/Espace basculent le badge focalisé (span role=button, donc
	// pas de clic natif) ; Échap referme toute explication ouverte.
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') {
			closeAll();
			return;
		}
		if (e.key !== 'Enter' && e.key !== ' ') return;
		var badge = e.target.closest ? e.target.closest('.pf-book-reco-badge') : null;
		if (!badge) return;
		e.preventDefault();
		var wrap = badge.closest('.pf-book-reco');
		if (wrap) toggle(wrap);
	});
})();
