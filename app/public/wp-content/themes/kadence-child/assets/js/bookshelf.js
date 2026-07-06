/**
 * Passiflore Bookshelf — re-packing adaptatif des rangées, reveal spines,
 * angle de couverture piloté au curseur, clamps de bord.
 */
(function () {
	'use strict';

	// Minimum hover time before the cover starts downloading. A quick
	// pass-over a row of spines should not trigger any GETs.
	var LOAD_DELAY_MS   = 400;
	// Minimum hover time before the book actually rotates. The real
	// rotation moment is max(this, cover load completion).
	var REVEAL_DELAY_MS = 700;
	// Marge conservée entre le livre saisi/survolé et les bords de l'étagère.
	var EDGE_MARGIN_PX  = 14;
	// Doit refléter le scale(1.1) des règles CSS hover/reveal.
	var HOVER_SCALE     = 1.1;

	// Tactile : un seul livre ouvert à la fois (1er tap = saisie,
	// 2e tap = navigation). Référence du livre actuellement ouvert.
	var touchOpenBook = null;

	function closeTouchBook() {
		if (!touchOpenBook) return;
		touchOpenBook.classList.remove('pf-book--cover-revealed');
		touchOpenBook = null;
	}

	document.addEventListener('touchstart', function (e) {
		if (touchOpenBook && !touchOpenBook.contains(e.target)) {
			closeTouchBook();
		}
	}, { passive: true });

	function cssVarPx(el, name) {
		return parseFloat(getComputedStyle(el).getPropertyValue(name)) || 0;
	}

	/* ─── Re-packing adaptatif (mode shelves) ─────────────────────
	   Le PHP répartit les livres pour une étagère de 1100px ; ici on
	   re-répartit pour la largeur réelle du container, au chargement,
	   après chaque swap AJAX et au resize. Les étagères à per_shelf
	   explicite (data-fixed-rows) sont laissées telles quelles. */

	function repackShelves(bookshelf) {
		if (bookshelf.dataset.fixedRows === '1') return;
		var shelves = Array.prototype.slice.call(
			bookshelf.querySelectorAll('.pf-shelf')
		);
		var firstBooks = bookshelf.querySelector('.pf-shelf-books');
		if (!shelves.length || !firstBooks) return;

		var cs    = getComputedStyle(firstBooks);
		var gap   = parseFloat(cs.columnGap) || 0;
		var avail = bookshelf.clientWidth
			- (parseFloat(cs.paddingLeft) || 0)
			- (parseFloat(cs.paddingRight) || 0);
		if (avail <= 0) return;

		var books = Array.prototype.slice.call(
			bookshelf.querySelectorAll('.pf-book')
		);
		if (!books.length) return;

		var rows = [];
		var row  = [];
		var w    = 0;
		books.forEach(function (b) {
			var bw   = b.offsetWidth;
			var next = w + (row.length ? gap : 0) + bw;
			if (row.length && next > avail) {
				rows.push(row);
				row = [b];
				w   = bw;
			} else {
				row.push(b);
				w = next;
			}
		});
		if (row.length) rows.push(row);

		// Répartition inchangée → ne pas toucher au DOM.
		var same = shelves.length === rows.length && shelves.every(function (s, i) {
			return s.querySelectorAll('.pf-book').length === rows[i].length;
		});
		if (same) return;

		rows.forEach(function (r, i) {
			var shelf = shelves[i];
			if (!shelf) {
				shelf = document.createElement('div');
				shelf.className = shelves[0].className;
				var booksDiv = document.createElement('div');
				booksDiv.className = firstBooks.className;
				var plank = document.createElement('div');
				plank.className = 'pf-shelf-plank';
				shelf.appendChild(booksDiv);
				shelf.appendChild(plank);
				bookshelf.appendChild(shelf);
			}
			var target = shelf.querySelector('.pf-shelf-books');
			var maxH   = 0;
			r.forEach(function (b) {
				target.appendChild(b); // appendChild déplace le nœud (handlers conservés)
				if (b.offsetHeight > maxH) maxH = b.offsetHeight;
			});
			shelf.style.setProperty('--shelf-inner', (maxH + 20) + 'px');
		});

		for (var i = rows.length; i < shelves.length; i++) {
			shelves[i].remove();
		}
	}

	/* ─── Ajustement à la largeur visible (anti-débordement mobile) ───
	   Les dimensions d'un livre sont figées en pixels par le PHP (mm × SCALE) ;
	   sur un écran étroit, un livre large débordait à droite. On réduit ici
	   UNIFORMÉMENT sa géométrie — toutes les faces 3D dérivent de --cover-w /
	   --spine-w / --book-h (et leurs variantes -base) — pour qu'il tienne dans
	   la largeur visible, sans déformer la couverture (object-fit:fill). Les
	   livres qui tiennent déjà gardent leur taille. Réversible : on recalcule
	   au resize depuis les valeurs brutes mémorisées, donc réélargir l'écran
	   restaure la taille d'origine. */

	var rawDims = new WeakMap();

	function bookRawDims(book) {
		var d = rawDims.get(book);
		if (!d) {
			d = {
				cw: cssVarPx(book, '--cover-w'),
				sw: cssVarPx(book, '--spine-w'),
				bh: cssVarPx(book, '--book-h')
			};
			rawDims.set(book, d);
		}
		return d;
	}

	function setBookScale(book, raw, fit) {
		var cw = Math.round(raw.cw * fit);
		var sw = Math.round(raw.sw * fit);
		var bh = Math.round(raw.bh * fit);
		var s  = book.style;
		s.setProperty('--cover-w', cw + 'px');
		s.setProperty('--spine-w', sw + 'px');
		s.setProperty('--book-h',  bh + 'px');
		// Variantes -base (repos), lues par les modes spines / reveal.
		s.setProperty('--cover-w-base', cw + 'px');
		s.setProperty('--spine-w-base', sw + 'px');
	}

	// Largeur réellement disponible pour un rayon. On NE peut PAS se fier à
	// bookshelf.clientWidth : en mode shelves/hero le rayon ne rogne pas son
	// contenu (pas d'overflow), donc un livre trop large le fait grandir et sa
	// clientWidth reflète alors le livre, pas l'espace dispo (→ aucun ajustement).
	// On prend donc la plus petite largeur de contenu en remontant la chaîne
	// d'ancêtres, plafonnée au viewport : c'est la contrainte réelle.
	function availableWidth(bookshelf) {
		var w = document.documentElement.clientWidth;
		for (var anc = bookshelf.parentElement; anc && anc !== document.documentElement; anc = anc.parentElement) {
			var cw = anc.clientWidth;
			if (cw > 0 && cw < w) w = cw;
		}
		return w;
	}

	// Hero (fiche livre) : le livre est dimensionné À PARTIR de l'espace
	// disponible (et non de sa taille propre), proportions conservées —
	// setBookScale applique le même facteur à --cover-w / --spine-w / --book-h.
	//
	// • Desktop deux colonnes : la taille épouse la HAUTEUR de la colonne de
	//   texte voisine. Aucune dépendance à la largeur, ce qui est essentiel :
	//   l'étagère étant fit-content, mesurer sa largeur renverrait celle du
	//   livre lui-même (circularité) — une fois rétréci il resterait bloqué
	//   petit au ré-agrandissement de la fenêtre.
	// • Empilé (mobile/tablette) : réduction seulement si le livre déborde, la
	//   largeur de référence étant le conteneur .bs-hero (pleine largeur, stable)
	//   et NON l'étagère fit-content — même raison anti-blocage.
	// Périmètre strictement limité au cas hero : les autres étagères ne passent
	// jamais ici (branche else de fitBookshelf, inchangée).
	function heroFit(bookshelf, book, raw, footprint) {
		var hero = bookshelf.closest('.bs-hero');
		if (!hero) return 1;
		var heading = hero.querySelector('.bs-hero__heading');
		var info    = hero.querySelector('.bs-hero__info');
		var visual  = bookshelf.closest('.bs-hero__visual');

		// Deux colonnes = la colonne de texte est entièrement à gauche du visuel.
		var sideBySide = heading && visual &&
			heading.getBoundingClientRect().right <= visual.getBoundingClientRect().left + 1;

		if (sideBySide && info) {
			// Chrome vertical de l'étagère (paddings + planche), constant sous
			// l'échelle : retranché pour que l'étagère entière — et non le seul
			// livre — épouse la hauteur de la colonne de texte.
			var chromeV  = bookshelf.offsetHeight - book.offsetHeight;
			var textH    = heading.offsetHeight + info.offsetHeight;
			var desiredH = Math.max(raw.bh, textH - chromeV); // jamais sous la taille naturelle
			return desiredH / raw.bh;
		}

		// Empilé : largeur de référence = conteneur hero (jamais l'étagère).
		var booksRow = bookshelf.querySelector('.pf-shelf-books');
		var cs     = getComputedStyle(booksRow);
		var pad    = (parseFloat(cs.paddingLeft) || 0) + (parseFloat(cs.paddingRight) || 0);
		var availW = hero.clientWidth - pad;
		return (availW > 0 && footprint > availW) ? availW / footprint : 1;
	}

	function fitBookshelf(bookshelf) {
		var booksRow = bookshelf.querySelector('.pf-shelf-books');
		if (!booksRow) return;
		var isSpines = bookshelf.classList.contains('pf-bookshelf--spines');
		var isHero   = bookshelf.classList.contains('pf-bookshelf--hero');
		var cs  = getComputedStyle(booksRow);
		var pad = (parseFloat(cs.paddingLeft) || 0) + (parseFloat(cs.paddingRight) || 0);
		var avail = availableWidth(bookshelf) - pad;

		bookshelf.querySelectorAll('.pf-book').forEach(function (book) {
			var raw = bookRawDims(book);
			if (!raw.cw && !raw.sw) return;

			// Empreinte au repos (bord droit du livre) selon le mode : en
			// spines seule la tranche est visible ; la liseuse n'a pas de
			// tranche papier ; sinon couverture + tranche.
			var footprint = isSpines
				? raw.sw
				: (book.classList.contains('pf-book--ereader') ? raw.cw : raw.cw + raw.sw);

			// Hero : pilotage par la hauteur du texte (mesures stables, pas de
			// dépendance à `avail` qui serait circulaire avec une étagère
			// fit-content). Les autres étagères gardent le shrink-to-width.
			var fit;
			if (isHero) {
				fit = heroFit(bookshelf, book, raw, footprint);
			} else {
				if (avail <= 0) return;
				fit = footprint > avail ? avail / footprint : 1;
			}

			setBookScale(book, raw, fit);
		});

		// Recaler la hauteur de chaque rayon sur le plus grand livre (après
		// réduction éventuelle), même méthode que repackShelves : sans ça un
		// livre réduit laisserait un vide au-dessus de lui (livre calé en bas).
		bookshelf.querySelectorAll('.pf-shelf').forEach(function (shelf) {
			var maxH = 0;
			shelf.querySelectorAll('.pf-book').forEach(function (b) {
				if (b.offsetHeight > maxH) maxH = b.offsetHeight;
			});
			if (maxH) shelf.style.setProperty('--shelf-inner', (maxH + 20) + 'px');
		});
	}

	function relayoutAll() {
		// 1) Ajuster chaque livre à la largeur visible AVANT le re-packing,
		//    qui mesure ensuite des largeurs déjà réduites.
		document.querySelectorAll('.pf-bookshelf').forEach(fitBookshelf);
		// 2) Re-répartir les rangées (mode shelves uniquement).
		document.querySelectorAll('.pf-bookshelf--shelves').forEach(repackShelves);
	}

	var resizeTimer = null;
	window.addEventListener('resize', function () {
		clearTimeout(resizeTimer);
		resizeTimer = setTimeout(relayoutAll, 120);
	});

	/* ─── Clamps de bord ──────────────────────────────────────────
	   Les wrappers .pf-book ne bougent jamais (sinon le hover perdrait
	   sa cible et oscillerait) : seuls l'inner et la déco reçoivent les
	   décalages calculés ici. */

	// Spines : position du livre saisi. Couverture centrée sur la tranche
	// par défaut, recalée pour que l'empreinte totale (tranche fantôme à
	// gauche incluse, scale 1.1 d'origine left bottom) reste dans le
	// container (.pf-shelf = viewport du scroll en mode scroll).
	function computeRevealShift(book) {
		var shelf = book.closest('.pf-shelf');
		if (!shelf) return;
		var coverW = cssVarPx(book, '--cover-w-base');
		var spineW = cssVarPx(book, '--spine-w-base');
		if (!coverW) return;
		var c     = coverW * HOVER_SCALE; // empreinte couverture [0, c] (origine x=0)
		var s     = spineW * HOVER_SCALE; // tranche fantôme [-s, 0]
		var rect  = book.getBoundingClientRect();
		var box   = shelf.getBoundingClientRect();
		var dx    = (rect.width - c) / 2;
		var minDx = (box.left + EDGE_MARGIN_PX) - rect.left + s;
		var maxDx = (box.right - EDGE_MARGIN_PX) - rect.left - c;
		if (minDx > maxDx) {
			// Étagère plus étroite que le livre ouvert : centrer l'empreinte.
			dx = (box.left + box.right) / 2 - rect.left - (c - s) / 2;
		} else if (dx < minDx) {
			dx = minDx;
		} else if (dx > maxDx) {
			dx = maxDx;
		}
		book.style.setProperty('--reveal-dx', Math.round(dx) + 'px');

		// Vertical : uniquement en mode scroll, où le scroller rogne au
		// padding-box (en shelves le livre déborde librement sur la rangée
		// du dessus). Le scale 1.1 (origine bas) pousse le haut du livre de
		// 10 % + le bandeau de pages au-dessus : on descend le livre juste
		// assez, sans pousser son pied au-delà du bas du container.
		var dy = 0;
		if (book.closest('.pf-bookshelf--scroll')) {
			var bookH    = cssVarPx(book, '--book-h');
			var topAfter = rect.top
				- (HOVER_SCALE - 1) * bookH
				- spineW * 0.249 * HOVER_SCALE;
			var overshoot = (box.top + EDGE_MARGIN_PX) - topAfter;
			if (overshoot > 0) {
				dy = Math.min(overshoot, Math.max(0, box.bottom - 2 - rect.bottom));
			}
		}
		book.style.setProperty('--reveal-dy', Math.round(dy) + 'px');
	}

	// Covers : au hover le livre est agrandi (scale 1.1, origine centre)
	// et sa couverture s'ouvre vers le lecteur (légère sur-projection à
	// droite). Près d'un bord — typiquement le scroll horizontal — on le
	// rentre via --hover-dx / --hover-dy pour que rien ne soit coupé.
	function setupHoverShift(book) {
		book.addEventListener('mouseenter', function () {
			var shelf = book.closest('.pf-shelf');
			if (!shelf) return;
			var rect = book.getBoundingClientRect();
			var box  = shelf.getBoundingClientRect();
			var L    = rect.left - rect.width * 0.06;
			var R    = rect.right + rect.width * 0.12;
			var dx   = 0;
			if (L < box.left + EDGE_MARGIN_PX) {
				dx = (box.left + EDGE_MARGIN_PX) - L;
			} else if (R > box.right - EDGE_MARGIN_PX) {
				dx = (box.right - EDGE_MARGIN_PX) - R;
			}
			book.style.setProperty('--hover-dx', Math.round(dx) + 'px');

			// Vertical (mode scroll uniquement) : scale 1.1 origine centre
			// → le haut monte de 5 % + le bandeau de pages (agrandi) qui
			// dépasse déjà du haut du livre.
			var dy = 0;
			if (book.closest('.pf-bookshelf--scroll')) {
				var bookH    = cssVarPx(book, '--book-h');
				var spineW   = cssVarPx(book, '--spine-w');
				var topAfter = rect.top
					- (HOVER_SCALE - 1) * 0.5 * bookH
					- spineW * 0.249 * HOVER_SCALE;
				var overshoot = (box.top + EDGE_MARGIN_PX) - topAfter;
				if (overshoot > 0) {
					var maxDy = box.bottom - 2
						- (rect.bottom + (HOVER_SCALE - 1) * 0.5 * bookH);
					dy = Math.min(overshoot, Math.max(0, maxDy));
				}
			}
			book.style.setProperty('--hover-dy', Math.round(dy) + 'px');
		});
	}

	/* ─── Reveal spines ───────────────────────────────────────────── */

	function setupSpineBook(book) {
		var coverReady  = false;   // image has loaded (or failed)
		var loadStarted = false;   // we've already triggered the fetch
		var hoverActive = false;   // pointer/focus currently on the book
		var hoverStart  = 0;       // ms timestamp of the latest enter
		var loadTimer   = null;    // pending fetch-start setTimeout id
		var revealTimer = null;    // pending reveal setTimeout id
		var touchIntent = false;   // last interaction began with a touch

		function clearLoad() {
			if (loadTimer !== null) {
				clearTimeout(loadTimer);
				loadTimer = null;
			}
		}

		function clearReveal() {
			if (revealTimer !== null) {
				clearTimeout(revealTimer);
				revealTimer = null;
			}
		}

		// Schedule the rotation as soon as both conditions are met: the
		// cover has finished loading AND at least REVEAL_DELAY_MS have
		// elapsed since the hover started. If either condition fails (or
		// the user leaves before the timer fires) nothing happens.
		function attemptReveal() {
			clearReveal();
			if (!hoverActive || !coverReady) return;
			var elapsed   = Date.now() - hoverStart;
			var remaining = Math.max(0, REVEAL_DELAY_MS - elapsed);
			revealTimer = setTimeout(function () {
				revealTimer = null;
				if (hoverActive) {
					book.classList.add('pf-book--cover-revealed');
				}
			}, remaining);
		}

		function startCoverLoad() {
			if (loadStarted) {
				attemptReveal();
				return;
			}
			loadStarted = true;
			var img = book.querySelector('.pf-book-cover img[data-src]');
			if (!img) {
				coverReady = true;
				attemptReveal();
				return;
			}
			var onDone = function () {
				coverReady = true;
				attemptReveal();
			};
			img.addEventListener('load', onDone, { once: true });
			img.addEventListener('error', onDone, { once: true });
			img.src = img.dataset.src;
			img.removeAttribute('data-src');
		}

		function scheduleCoverLoad() {
			// Already loading or loaded → nothing to schedule.
			if (loadStarted || loadTimer !== null) return;
			loadTimer = setTimeout(function () {
				loadTimer = null;
				if (hoverActive) startCoverLoad();
			}, LOAD_DELAY_MS);
		}

		function onEnter() {
			hoverActive = true;
			hoverStart  = Date.now();
			computeRevealShift(book);
			scheduleCoverLoad();
			attemptReveal();
		}

		function onLeave() {
			hoverActive = false;
			// Cancel any pending fetch that hasn't fired yet — the user
			// moved off before committing. Once `startCoverLoad` has
			// actually set `img.src`, browsers can't reliably abort the
			// in-flight request, but at least we never queued it.
			clearLoad();
			clearReveal();
			// Drop the class so the book closes back, and so the next
			// hover gets a fresh REVEAL_DELAY_MS countdown.
			book.classList.remove('pf-book--cover-revealed');
			if (touchOpenBook === book) touchOpenBook = null;
		}

		book.addEventListener('mouseenter', onEnter);
		book.addEventListener('focusin',    onEnter);
		book.addEventListener('mouseleave', onLeave);
		book.addEventListener('focusout',   onLeave);

		// Tactile : le 1er tap saisit le livre (et annule la navigation),
		// le 2e tap — livre déjà ouvert — suit le lien.
		book.addEventListener('touchstart', function () {
			touchIntent = true;
		}, { passive: true });

		book.addEventListener('click', function (e) {
			if (!touchIntent) return;
			touchIntent = false;
			if (book.classList.contains('pf-book--cover-revealed')) return;
			e.preventDefault();
			closeTouchBook();
			touchOpenBook = book;
			hoverActive   = true;
			// Antidate le début du hover : au tap la saisie part dès que
			// la couverture est chargée, sans délai artificiel.
			hoverStart = Date.now() - REVEAL_DELAY_MS;
			computeRevealShift(book);
			startCoverLoad();
		});
	}

	// Cursor-driven cover opening: the rotateY of `.pf-book-cover` is
	// piloted by the horizontal cursor position over the cover itself.
	// Cursor on the cover's left edge (against the spine) → 0deg (fully
	// face-on), cursor on the right edge → -25deg (the default open
	// angle). Centre → -12.5deg, linearly. Gives the user the feeling
	// they're physically pushing the cover open with the cursor.
	//
	// Active in covers mode whenever the book is hovered, and in spines
	// mode only once the cover has been revealed (so the angle doesn't
	// jump around during the initial pull-out).
	function setupCursorReveal(book) {
		var cover = book.querySelector('.pf-book-cover');
		if (!cover) return;

		function onMove(e) {
			var rect = cover.getBoundingClientRect();
			var t;
			if (rect.width < 2) {
				// Spines mode at rest: the cover is folded edge-on
				// (rotateY 90°) so its projected width is ~0. The cursor
				// is over the spine → t = 0 (cover will open fully
				// face-on). We still set --cover-angle now so that when
				// the reveal class lands the CSS transition starts at
				// this angle rather than the fallback.
				t = 0;
			} else {
				t = (e.clientX - rect.left) / rect.width;
				if (t < 0) t = 0;
				else if (t > 1) t = 1;
			}
			book.style.setProperty('--cover-angle', (-25 * t) + 'deg');
		}

		function onClear() {
			book.style.removeProperty('--cover-angle');
		}

		book.addEventListener('mousemove',  onMove);
		book.addEventListener('mouseleave', onClear);
		book.addEventListener('focusout',   onClear);
	}

	function init() {
		relayoutAll();

		// In spines mode the cover face is hidden until hover, so its <img>
		// ships with `data-src` and is only fetched on first interaction.
		// Keeps the initial render light when many books are on screen.
		document.querySelectorAll('.pf-bookshelf--spines .pf-book')
			.forEach(setupSpineBook);

		document.querySelectorAll('.pf-bookshelf--covers .pf-book')
			.forEach(setupHoverShift);

		document.querySelectorAll('.pf-bookshelf:not(.pf-bookshelf--hero) .pf-book')
			.forEach(setupCursorReveal);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	// Re-initialise after AJAX content swaps (e.g. catalogue filters).
	// Safe to call multiple times — handlers are attached to fresh DOM
	// nodes only, and old nodes are garbage-collected with the previous HTML.
	window.PassifloreBookshelf = window.PassifloreBookshelf || {};
	window.PassifloreBookshelf.init = init;
})();
