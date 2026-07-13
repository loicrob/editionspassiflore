/**
 * Fiche événement (desktop ≥1024px) : largeur de la colonne de l'image collée.
 *
 * L'image est contrainte en CSS (max 40% du conteneur, hauteur visible dispo,
 * ratio naturel, centrée verticalement). Une grille CSS ne sait pas dériver la
 * largeur d'une image contrainte en HAUTEUR (les pistes se dimensionnent sur la
 * largeur intrinsèque) → on la CALCULE ici depuis les dimensions naturelles de
 * l'image + la hauteur disponible, et on la pose en --pf-event-media-col.
 * Résultat : une image fine/haute rétrécit sa colonne → le contenu à gauche
 * récupère l'espace ; une image large est plafonnée à 40% et centrée verticalement.
 *
 * Pas de mesure du DOM (getBoundingClientRect) → aucune boucle de rétroaction
 * avec la largeur de colonne qu'on est en train de poser. Inerte < 1024px
 * (layout empilé, l'image reprend toute la largeur).
 */
(function () {
	var layout = document.querySelector('.pf-event-hero');
	if (!layout) return;
	var img = layout.querySelector('.pf-event-hero__img');
	if (!img) return;

	// Résout une longueur --pf-* (souvent en rem) en pixels. getComputedStyle sur une
	// custom property renvoie la valeur BRUTE (ex. "1.5rem"), pas la valeur résolue —
	// contrairement à une propriété standard. Un élément jetable la fait résoudre.
	function pxVar( name ) {
		var probe = document.createElement( 'div' );
		probe.style.cssText = 'position:absolute;visibility:hidden;height:var(' + name + ');width:0;';
		document.body.appendChild( probe );
		var px = parseFloat( getComputedStyle( probe ).height ) || 0;
		document.body.removeChild( probe );
		return px;
	}

	function size() {
		if (window.innerWidth < 1024) {
			layout.style.removeProperty('--pf-event-media-col');
			return;
		}
		var natW = img.naturalWidth, natH = img.naturalHeight;
		if (!natW || !natH) return; // image pas encore chargée

		var offset  = parseFloat(
			getComputedStyle(document.documentElement).getPropertyValue('--pf-sticky-offset')
		) || 0; // posé en JS (recherche-globale.js) → déjà en px, pas besoin de pxVar()
		var gutter  = pxVar( '--pf-space-6' ); // margin haut+bas de .pf-event-hero__media-inner (event-single.css)
		var availH  = window.innerHeight - offset - gutter * 2; // hauteur de la boîte image collée
		var maxW   = layout.clientWidth * 0.4;         // plafond : 40% du conteneur
		var byH    = availH * (natW / natH);           // largeur si l'image remplit la hauteur

		// Largeur rendue = la plus contraignante ; jamais au-delà de la taille naturelle.
		var w = Math.min(maxW, byH, natW);
		layout.style.setProperty('--pf-event-media-col', Math.round(w) + 'px');
	}

	if (img.complete) size();
	else img.addEventListener('load', size);
	window.addEventListener('load', size);   // filet : --pf-sticky-offset finalisé
	window.addEventListener('resize', size);
})();
