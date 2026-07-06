/**
 * Badge de recommandation — bascule de l'explication au tap (tactile).
 * Le survol et le focus clavier sont gérés en CSS ; ce script ne sert qu'à
 * ouvrir/fermer l'overlay au clic et à éviter que le tap du badge ne suive le
 * lien de la fiche livre.
 */
(function () {
	'use strict';

	document.addEventListener('click', function (e) {
		var badge = e.target.closest ? e.target.closest('.pf-book-reco-badge') : null;

		if (!badge) {
			// Clic ailleurs : referme toute explication ouverte.
			document.querySelectorAll('.pf-book-reco.is-open').forEach(function (el) {
				el.classList.remove('is-open');
			});
			return;
		}

		// Tap sur le badge : ne pas naviguer vers la fiche, juste basculer.
		e.preventDefault();
		e.stopPropagation();

		var wrap = badge.closest('.pf-book-reco');
		if (!wrap) {
			return;
		}
		var isOpen = wrap.classList.contains('is-open');
		document.querySelectorAll('.pf-book-reco.is-open').forEach(function (el) {
			el.classList.remove('is-open');
		});
		if (!isOpen) {
			wrap.classList.add('is-open');
		}
	});
})();
