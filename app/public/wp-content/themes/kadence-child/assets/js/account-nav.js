/**
 * « Mon compte » — nav sectionnav (.pf-sectionnav--static). Sur mobile/tablette
 * (barre horizontale sticky à pilules), amène la pilule de la page courante
 * (.is-active) dans le champ visible au chargement — sinon, sur une page dont
 * l'item est loin à droite (« Adresses », « Détails du compte »…), le visiteur
 * ne voit que « Tableau de bord » et ignore où il se trouve. Desktop (rail
 * vertical, non défilant) : sans effet (scrollWidth <= clientWidth → early return).
 */
(function () {
	var track  = document.querySelector('.pf-sectionnav--static .pf-sectionnav__track');
	var active = document.querySelector('.pf-sectionnav--static a.is-active');
	if (!track || !active) return;

	function center() {
		if (track.scrollWidth <= track.clientWidth) return; // pas de défilement horizontal
		var item    = active.closest('li') || active;
		var padLeft = parseFloat(getComputedStyle(track).paddingLeft) || 0;
		var target  = Math.max(0, Math.min(item.offsetLeft - padLeft, track.scrollWidth - track.clientWidth));
		track.scrollTo({ left: target });
	}

	center();
	// Filet : relayout tardif (polices web, barre d'admin…) peut changer les largeurs.
	window.addEventListener('load', center);
})();
