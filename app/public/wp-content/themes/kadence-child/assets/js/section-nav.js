/**
 * Composant partagé « section-nav » — nav sticky + scrollspy pour les pages à
 * sections (fiche livre, fiche événement). Rendu PHP par pf_render_sectionnav()
 * (inc/section-nav.php) : <nav.pf-sectionnav> + <section.pf-section[id]>.
 *
 * Inerte si aucune .pf-sectionnav n'est présente (tous les querySelectorAll
 * reviennent vides → early return des blocs concernés). Chargé en footer sur
 * les pages qui rendent le composant.
 */
(function () {
	// ── Ancre : scroll géré ici, pas par Kadence ────────────────────────
	// Kadence (navigation.min.js) intercepte les liens d'ancre ET le hash au
	// chargement pour scroller avec son SEUL offset de header (getTopOffset),
	// ignorant scroll-margin-top ET la nav sticky de la page → la section se
	// cale trop haut, titre masqué sous la nav (surtout mobile). On neutralise
	// ce comportement via body.no-anchor-scroll (posé en PHP) et on gère le
	// scroll nous-mêmes : scrollIntoView respecte scroll-margin-top (= header +
	// nav + marge). Voir le clic des liens de nav plus bas.
	//
	// Re-calage au chargement : la zone au-dessus de la section (couverture,
	// pageflip CDN, iframes oEmbed) peut finaliser sa hauteur APRÈS le scroll
	// d'ancre natif, décalant la section. On re-cale tant que le layout bouge,
	// jusqu'à interaction du visiteur (ou 3 s de filet).
	var stopAnchorPin = function () {};
	(function () {
		if (location.hash.length < 2) return;
		var target;
		try { target = document.querySelector(location.hash); } catch (e) { return; }
		if (!target || !target.classList.contains('pf-section')) return;

		var done = false, ro = null;
		function settle() { if (!done) target.scrollIntoView(); } // instantané
		stopAnchorPin = function () {
			if (done) return;
			done = true;
			if (ro) ro.disconnect();
		};
		window.addEventListener('wheel', stopAnchorPin, { passive: true });
		window.addEventListener('touchmove', stopAnchorPin, { passive: true });
		window.addEventListener('keydown', function (e) {
			if (['ArrowUp', 'ArrowDown', 'PageUp', 'PageDown', 'Home', 'End', ' '].indexOf(e.key) !== -1) stopAnchorPin();
		});

		if ('ResizeObserver' in window) {
			ro = new ResizeObserver(settle);
			ro.observe(document.body);
			setTimeout(stopAnchorPin, 3000);
		} else {
			window.addEventListener('load', function () { settle(); setTimeout(settle, 250); });
		}
	})();

	// Nav sections : surlignage du lien actif via IntersectionObserver
	var navLinks = Array.from(document.querySelectorAll('.pf-sectionnav a[href^="#"]'));
	var pfSections = Array.from(document.querySelectorAll('.pf-section[id]'));
	if (navLinks.length && pfSections.length) {
		var current = '';
		// Coupé pendant/juste après un clic direct sur un lien de nav (voir plus
		// bas) : la nav ne doit pas se scroller elle-même en réaction à un clic,
		// seulement suivre le scroll naturel de la page (scrollspy).
		var suppressTrackScroll = false;
		function setActive(id) {
			if (id === current) return;
			current = id;
			var activeLink = null;
			navLinks.forEach(function (l) {
				var isActive = l.getAttribute('href') === '#' + id;
				l.classList.toggle('is-active', isActive);
				if (isActive) activeLink = l;
			});
			if (suppressTrackScroll) return;
			// Nav mobile (défilement horizontal) : ramène le lien actif au ras du
			// bord gauche visible (sans effet sur la nav desktop, non scrollable).
			var trackEl = activeLink && activeLink.closest('.pf-sectionnav__track');
			if (trackEl) {
				var padLeft = parseFloat(getComputedStyle(trackEl).paddingLeft) || 0;
				var target = Math.max(0, Math.min(activeLink.offsetLeft - padLeft, trackEl.scrollWidth - trackEl.clientWidth));
				trackEl.scrollTo({ left: target, behavior: 'smooth' });
			}
		}
		var observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) setActive(entry.target.id);
			});
		}, { rootMargin: '-20% 0px -70% 0px', threshold: 0 });
		pfSections.forEach(function (s) { observer.observe(s); });
		if (pfSections[0]) setActive(pfSections[0].id);

		// Clic sur un lien de nav : scroll fluide au bon offset (Kadence est
		// neutralisé via no-anchor-scroll). scrollIntoView respecte scroll-margin-top
		// (header + nav). On coupe d'abord le re-calage de l'ancre initiale pour
		// ne pas lutter avec lui si le clic survient dans les 1res secondes.
		var suppressTrackScrollTimer;
		function onSectionNavScrollEnd() {
			clearTimeout(suppressTrackScrollTimer);
			window.removeEventListener('scrollend', onSectionNavScrollEnd);
			suppressTrackScroll = false;
		}
		navLinks.forEach(function (l) {
			l.addEventListener('click', function (e) {
				var id = l.getAttribute('href').slice(1);
				var sec = document.getElementById(id);
				if (!sec) return;
				e.preventDefault();
				stopAnchorPin();
				suppressTrackScroll = true;
				sec.scrollIntoView({ behavior: 'smooth' });
				history.pushState(null, '', '#' + id);
				// Réactivé une fois le scroll (et le scrollspy qui suit) stabilisés :
				// 'scrollend' si dispo, mais TOUJOURS avec un filet par timeout (le
				// premier des deux qui arrive gagne) — 'scrollend' peut en théorie ne
				// jamais se déclencher (bug navigateur, scroll interrompu…), sans filet
				// le flag resterait bloqué à true indéfiniment.
				clearTimeout(suppressTrackScrollTimer);
				window.removeEventListener('scrollend', onSectionNavScrollEnd);
				window.addEventListener('scrollend', onSectionNavScrollEnd, { once: true });
				suppressTrackScrollTimer = setTimeout(onSectionNavScrollEnd, 1000);
			});
		});

		// Nav mobile : devient visible dès que le haut de la 1ère section atteint
		// le BAS de la nav (offset header + hauteur nav), pas seulement son haut.
		// Sinon, cliquer sur la 1ère section (qui cale son haut pile au bas de la
		// nav) ne franchit jamais le seuil et la nav resterait masquée. +4px de
		// tolérance pour l'arrondi sous-pixel (cf. sepGap 0/1 mesuré).
		var sectionNav = document.querySelector('.pf-sectionnav');
		var firstSection = pfSections[0];
		if (sectionNav && firstSection) {
			var navTicking = false;
			function updateNavVisibility() {
				navTicking = false;
				var cs = getComputedStyle(document.documentElement);
				var offset = parseFloat(cs.getPropertyValue('--pf-sticky-offset')) || 0;
				var navH = parseFloat(cs.getPropertyValue('--pf-sectionnav-h')) || 0;
				sectionNav.classList.toggle('is-visible', firstSection.getBoundingClientRect().top <= offset + navH + 4);
			}
			function onNavScroll() {
				if (!navTicking) {
					navTicking = true;
					requestAnimationFrame(updateNavVisibility);
				}
			}
			window.addEventListener('scroll', onNavScroll, { passive: true });
			window.addEventListener('resize', onNavScroll, { passive: true });
			updateNavVisibility();
		}

		// Hauteur réelle de la barre (mobile) : consommée par .pf-section
		// (scroll-margin-top) pour qu'un saut d'ancre ne s'arrête pas sous elle.
		// Sans effet sur desktop (nav verticale, ne recouvre pas les sections) —
		// la variable n'y est simplement pas utilisée.
		if (sectionNav) {
			function updateSectionNavHeight() {
				document.documentElement.style.setProperty('--pf-sectionnav-h', sectionNav.offsetHeight + 'px');
			}
			window.addEventListener('resize', updateSectionNavHeight, { passive: true });
			updateSectionNavHeight();
		}
	}
})();
