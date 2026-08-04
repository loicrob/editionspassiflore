/**
 * Accueil du compte — mesure la distance réelle entre le haut du document et
 * .pf-account-dashboard (--pf-account-hub-top), pour que son min-height
 * (100svh - ce décalage) atteigne exactement le bas du viewport sans le
 * dépasser. Voir le commentaire sur .pf-account-dashboard dans account.css :
 * ce décalage traverse plusieurs conteneurs Kadence génériques, impossible à
 * exprimer en CSS pur sans les transformer tous en chaîne flex.
 */
(function () {
	var dash = document.querySelector('.pf-account-dashboard');
	if (!dash) return;

	function measure() {
		var top = dash.getBoundingClientRect().top + window.pageYOffset;
		document.documentElement.style.setProperty('--pf-account-hub-top', top + 'px');
	}

	measure();
	window.addEventListener('resize', measure);
	window.addEventListener('load', measure);
})();
