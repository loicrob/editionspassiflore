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
	// Percentile des empreintes de livres sur lequel se cale le facteur de
	// réduction COMMUN à toute l'étagère (cf. fitBookshelf). Plage utile
	// [0.95, 1.00] : à 1.00 le facteur est calé sur le plus grand livre (aucun
	// écrêtage, mais un seul très grand format impose sa réduction à tous) ;
	// en descendant, les livres restent plus grands et quelques-uns sont
	// écrêtés. Sous 0.95 le facteur sature à 1 et seul le nombre d'écrêtés
	// augmente — autrement dit on ne fait plus que réintroduire le défaut
	// qu'on corrige. Mesuré sur le catalogue à 390px : 1.00 → livre médian
	// 138px, 1.45 livre/rangée ; 0.95 → 172px, 1.04 livre/rangée, 7 écrêtés.
	var FIT_PERCENTILE  = 0.95;

	// Tactile : un seul livre ouvert à la fois (1er tap = saisie,
	// 2e tap = navigation). Référence du livre actuellement ouvert.
	var touchOpenBook = null;

	// Le retour au repos depuis la SAISIE doit emprunter la durée et la courbe
	// de l'aller (cf. .pf-book--releasing dans bookshelf.css) : le livre saisi
	// pouvant être bien plus petit que sur le rayon (--pf-reveal-scale), sa
	// course de retour est longue et les 0,3s du repos la font claquer. Le CSS
	// ne sachant pas distinguer ce cas de la simple fin de bascule — qui, elle,
	// doit rester à 0,3s — on le marque ici, le temps de la transition.
	// Appelé AVANT le retrait de la classe de saisie : il teste cet état.
	//
	// ⚠️ Ce minuteur NE RÈGLE PAS la durée de l'animation — celle-ci vit dans
	// --pf-release-dur (bookshelf.css), lue ci-dessous. Il ne fait que tenir la
	// classe le temps que la transition se joue. La ralentir se règle donc dans
	// le CSS ; changer ce nombre seul ne ferait qu'allonger la classe.
	//
	// Pourquoi une marge : la classe ne porte pas que la courbe, c'est aussi le
	// seul état qui tient le z-index du livre pendant le retour (cf.
	// bookshelf.css). La lâcher avant la fin de la transition ferait passer la
	// couverture encore ouverte derrière ses voisins. Elle doit donc vivre un
	// peu PLUS longtemps que la transition, jamais moins — et la transition ne
	// démarre pas à l'instant où le minuteur est armé, mais au recalcul de
	// style suivant. La marge reste petite : tant que la classe est là, le
	// livre est au-dessus de ses voisins alors qu'il est déjà au repos.
	var RELEASE_MARGIN_MS = 100;

	function cssDurationMs(el, name) {
		var v = getComputedStyle(el).getPropertyValue(name).trim();
		var n = parseFloat(v);
		if (!v || isNaN(n)) return 0;
		return /ms$/.test(v) ? n : n * 1000; // « 0.5s » ou « 500ms »
	}

	function markReleasing(book) {
		if (!book.classList.contains('pf-book--cover-revealed')) return;
		book.classList.add('pf-book--releasing');
		var hold = (cssDurationMs(book, '--pf-release-dur') || 500) + RELEASE_MARGIN_MS;
		clearTimeout(book._pfReleaseTimer);
		book._pfReleaseTimer = setTimeout(function () {
			book._pfReleaseTimer = null;
			book.classList.remove('pf-book--releasing');
		}, hold);
	}

	function cancelReleasing(book) {
		clearTimeout(book._pfReleaseTimer);
		book._pfReleaseTimer = null;
		book.classList.remove('pf-book--releasing');
	}

	function closeTouchBook() {
		if (!touchOpenBook) return;
		// Chemin TACTILE (tap à côté) : c'est par là que le livre se referme
		// sur téléphone, pas par onLeave.
		markReleasing(touchOpenBook);
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

	// Angle de fuite, lu sur l'étagère plutôt que recopié ici : il change au
	// point de rupture 768px (cf. --pf-obl dans bookshelf.css), et une copie
	// figée dans ce fichier a déjà sous-estimé la hauteur du bandeau de pages
	// après un changement d'angle — donc sous-corrigé les débordements.
	function oblique(book) {
		var shelf = book.closest('.pf-bookshelf');
		return (shelf && cssVarPx(shelf, '--pf-obl')) || 0.3249;
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

		// Toutes les largeurs d'abord, AVANT la moindre écriture (cf. les trois
		// phases plus bas) : une seule passe de layout pour tout le catalogue.
		var widths = books.map(function (b) { return b.offsetWidth; });

		var rows = [];
		var row  = [];
		var w    = 0;
		books.forEach(function (b, i) {
			var next = w + (row.length ? gap : 0) + widths[i];
			if (row.length && next > avail) {
				rows.push(row);
				row = [b];
				w   = widths[i];
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

		// ⚠️ Lectures et écritures strictement séparées (cf. la passe `widths`
		// plus haut, faite AVANT la moindre écriture). Lire une géométrie juste
		// après avoir touché au DOM force le navigateur à recalculer le layout
		// séance tenante ; intercalées dans la boucle de déplacement, ces
		// lectures coûtaient un layout PAR LIVRE. Mesuré sur le catalogue
		// (146 livres, 78 rangées) : ~950 ms de reconstruction, contre ~75 ms
		// une fois les passes séparées. Ne pas ré-intercaler de mesure ici.

		// Écritures seules : créer les rayons manquants, déplacer les livres.
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
			r.forEach(function (b) {
				target.appendChild(b); // appendChild déplace le nœud (handlers conservés)
			});
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
	// • Desktop deux colonnes : la taille épouse la HAUTEUR dispo de la carte
	//   .pf-bookshelf--hero (flex:1 dans .bs-hero__visual, qui s'étire lui-même
	//   sur toute la hauteur de la grille — voir book-single.css). Cette carte
	//   étant flex:1, sa propre offsetHeight ne dépend plus de son contenu (elle
	//   est imposée par flexbox) → c'est une cible fiable. Le chrome fixe
	//   (padding de la rangée + planche) est mesuré directement plutôt que
	//   déduit par soustraction (bookshelf.offsetHeight - book.offsetHeight) :
	//   cette soustraction ne mesurerait plus le chrome mais l'espace vide
	//   qu'on cherche justement à combler (formule qui s'annule elle-même).
	// • Empilé (mobile/tablette) : réduction seulement si le livre déborde, la
	//   largeur de référence étant le conteneur .bs-hero (pleine largeur, stable)
	//   et NON l'étagère fit-content — anti-blocage (idem raisonnement largeur).
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
			var shelf      = book.closest('.pf-shelf');
			var shelfBooks = shelf && shelf.querySelector('.pf-shelf-books');
			var plank      = shelf && shelf.querySelector('.pf-shelf-plank');
			var padV = 0, padH = 0;
			if (shelfBooks) {
				var sbCS = getComputedStyle(shelfBooks);
				padV = (parseFloat(sbCS.paddingTop) || 0) + (parseFloat(sbCS.paddingBottom) || 0);
				padH = (parseFloat(sbCS.paddingLeft) || 0) + (parseFloat(sbCS.paddingRight) || 0);
			}
			var chromeV  = padV + (plank ? plank.offsetHeight : 0);
			var desiredH = Math.max(raw.bh, bookshelf.offsetHeight - chromeV); // jamais sous la taille naturelle
			var fitH     = desiredH / raw.bh;

			// Plafond largeur : de l'origine de la colonne visuelle (stable, fixée
			// par la grille — cf. commentaire sideBySide plus haut) au bord droit
			// du hero (stable, bloc normal non rétréci par son contenu). Sans ce
			// plafond, une couverture au format panoramique (plus large que haute)
			// scale uniquement sur la hauteur dispo et déborde à droite.
			var heroRect   = hero.getBoundingClientRect();
			var visualRect = visual.getBoundingClientRect();
			var availW     = heroRect.right - visualRect.left - padH;
			var fitW       = ( availW > 0 ) ? availW / footprint : fitH;

			return Math.min(fitH, fitW);
		}

		// Empilé : largeur de référence = conteneur hero (jamais l'étagère,
		// fit-content donc circulaire). Le livre ÉPOUSE cette largeur, dans les
		// deux sens — comme la branche deux colonnes épouse la hauteur dispo.
		// Il ne faisait auparavant que rétrécir : sur téléphone il restait donc
		// à sa taille naturelle (172px de large pour 302 disponibles) et
		// réduire le padding ne faisait que resserrer le cadre autour de lui,
		// sans jamais l'agrandir.
		var booksRow = bookshelf.querySelector('.pf-shelf-books');
		var cs     = getComputedStyle(booksRow);
		var pad    = (parseFloat(cs.paddingLeft) || 0) + (parseFloat(cs.paddingRight) || 0);
		// Le cadre de l'étagère a sa propre bordure : sans la déduire, le livre
		// remplit la largeur du hero et l'étagère la dépasse de 2px.
		var border = bookshelf.offsetWidth - bookshelf.clientWidth;
		var availW = hero.clientWidth - pad - border;
		if (availW <= 0 || footprint <= 0) return 1;

		// Garde-fou : un livre très élancé rempli en largeur occuperait tout
		// l'écran en hauteur. Le catalogue plafonne vers 2:1, mais rien ne
		// l'impose.
		var fitW = availW / footprint;
		var maxH = 0.7 * window.innerHeight;
		return raw.bh > 0 ? Math.min(fitW, maxH / raw.bh) : fitW;
	}

	function fitBookshelf(bookshelf) {
		var booksRow = bookshelf.querySelector('.pf-shelf-books');
		if (!booksRow) return;
		var isSpines = bookshelf.classList.contains('pf-bookshelf--spines');
		var isHero   = bookshelf.classList.contains('pf-bookshelf--hero');
		var cs  = getComputedStyle(booksRow);
		var pad = (parseFloat(cs.paddingLeft) || 0) + (parseFloat(cs.paddingRight) || 0);
		var avail = availableWidth(bookshelf) - pad;

		var books = Array.prototype.slice.call(bookshelf.querySelectorAll('.pf-book'));

		// Correctif d'échelle du mode dos (cf. --pf-spines-scale) : le PHP y
		// dessine tout 1,5x plus grand, ramené à 1,2x sur mobile. Le seuil de
		// largeur reste en CSS — on ne fait que lire la valeur, pour ne pas
		// dupliquer le point de rupture ici.
		var modeScale = isSpines
			? (cssVarPx(bookshelf, '--pf-spines-scale') || 1)
			: 1;

		// Empreinte au repos (bord droit du livre) selon le mode : en spines
		// seul le dos est visible ; la liseuse n'a pas de dos papier ; sinon
		// couverture + dos. Valeurs NATIVES (avant correctif d'échelle).
		var footprints = books.map(function (book) {
			var raw = bookRawDims(book);
			if (!raw.cw && !raw.sw) return 0;
			return isSpines
				? raw.sw
				: (book.classList.contains('pf-book--ereader') ? raw.cw : raw.cw + raw.sw);
		});

		// UN SEUL facteur pour toute l'étagère : réduit livre par livre, chaque
		// livre trop large sortait exactement à la largeur disponible, donc un
		// album de 360mm et un roman de 220mm rendus IDENTIQUES (36 livres sur
		// 146 à 390px), à côté de leurs voisins restés à taille vraie. Les
		// tailles relatives — tout l'intérêt d'une étagère — disparaissaient.
		//
		// Le facteur se cale sur le p95 des empreintes et non sur la plus
		// grande : un unique très grand format imposerait sa réduction à tout
		// le catalogue. Les ~5% au-dessus restent écrêtés individuellement
		// (sans quoi ils déborderaient, #wrapper étant en overflow:clip) : ils
		// perdent leur taille relative, mais ils sont 10 au lieu de 36
		// (mesuré à 390px : les 7 au-dessus du p95, plus les 3 pile dessus).
		//
		// Rien de tout ceci ne s'applique au-dessus de 768px, où tous les
		// livres tiennent déjà : le facteur y vaut 1 et le code est inerte.
		var fit = 1;
		if (!isHero && avail > 0) {
			// Percentile sur les empreintes RÉELLEMENT occupées, correctif
			// d'échelle compris.
			var sorted = footprints.filter(Boolean)
				.map(function (f) { return f * modeScale; })
				.sort(function (a, b) { return a - b; });
			if (sorted.length) {
				var ref = sorted[Math.min(
					sorted.length - 1,
					Math.max(0, Math.ceil(FIT_PERCENTILE * sorted.length) - 1)
				)];
				if (ref > avail) fit = avail / ref;
			}
		}

		books.forEach(function (book, i) {
			var raw = bookRawDims(book);
			if (!raw.cw && !raw.sw) return;

			// Hero : pilotage par la hauteur du texte (mesures stables, pas de
			// dépendance à `avail` qui serait circulaire avec une étagère
			// fit-content). Les autres étagères gardent le shrink-to-width.
			// Facteur TOTAL appliqué aux dimensions natives : correctif
			// d'échelle du mode dos x réduction d'encombrement.
			var f;
			if (isHero) {
				f = heroFit(bookshelf, book, raw, footprints[i]);
			} else {
				if (avail <= 0) return;
				f = footprints[i] * modeScale * fit > avail
					? avail / footprints[i]          // écrêtage : facteur total direct
					: modeScale * fit;
			}

			setBookScale(book, raw, f);
		});

		// Révèle le(s) livre(s) hero une fois la bonne taille appliquée (voir
		// le visibility:hidden de repli dans book-single.css).
		if (isHero) {
			bookshelf.classList.add('is-sized');
		}
	}

	function relayoutAll() {
		// 1) Ajuster chaque livre à la largeur visible AVANT le re-packing,
		//    qui mesure ensuite des largeurs déjà réduites.
		document.querySelectorAll('.pf-bookshelf').forEach(fitBookshelf);
		// 2) Re-répartir les rangées (mode shelves uniquement), puis lever le
		//    voile qui masquait la répartition théorique du PHP (cf.
		//    .pf-shelves-js dans bookshelf.css). Posée inconditionnellement :
		//    repackShelves peut sortir tôt (data-fixed-rows, largeur nulle) et
		//    une étagère ne doit jamais rester invisible.
		document.querySelectorAll('.pf-bookshelf--shelves').forEach(function (bookshelf) {
			repackShelves(bookshelf);
			bookshelf.classList.add('is-packed');
		});
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

	// Spines : taille et position du livre saisi. Couverture centrée sur le dos
	// par défaut, recalée pour que l'empreinte totale (dos fantôme à
	// gauche incluse, scale d'origine left bottom) reste dans le
	// container (.pf-shelf = viewport du scroll en mode scroll).
	function computeRevealShift(book) {
		var shelf = book.closest('.pf-shelf');
		if (!shelf) return;
		var coverW = cssVarPx(book, '--cover-w-base');
		var spineW = cssVarPx(book, '--spine-w-base');
		if (!coverW) return;
		var rect  = book.getBoundingClientRect();
		var box   = shelf.getBoundingClientRect();

		// Le mode dos dessine tout 1,5x plus grand (mode_scale, pour que les
		// titres verticaux des dos restent lisibles). Une fois le livre
		// retourné, cette taille vaut pour sa COUVERTURE : sur un écran étroit
		// elle atteignait 693px pour 308px disponibles (mesuré sur le plus
		// grand format du catalogue, /catalogue en vue dos à 390px) et
		// débordait du rayon — rogné en mode scroll, déversé sur les rangées
		// voisines en mode shelves. On plafonne donc l'agrandissement de la
		// SAISIE à ce qui tient dans le rayon. Le dos au repos n'est pas
		// touché : c'est sa lisibilité qui justifie le 1,5x.
		// ⚠️ Ce facteur est aussi lu par le CSS (--pf-reveal-scale) pour la
		// transform ET pour le perspective-origin, dont le terme
		// (scale - 1) x --book-h repère le haut du volume agrandi. Les trois
		// doivent rester d'accord, sinon l'atterrissage ne retombe plus sur la
		// cavalière du mode couvertures.
		var room  = box.width - 2 * EDGE_MARGIN_PX;
		var scale = HOVER_SCALE;
		if (room > 0 && coverW + spineW > 0) {
			scale = Math.min(HOVER_SCALE, room / (coverW + spineW));
		}
		book.style.setProperty('--pf-reveal-scale', scale);

		var c     = coverW * scale; // empreinte couverture [0, c] (origine x=0)
		var s     = spineW * scale; // dos fantôme [-s, 0]
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
		// du dessus). Le scale (origine bas) pousse le haut du livre d'autant
		// + le bandeau de pages au-dessus : on descend le livre juste
		// assez, sans pousser son pied au-delà du bas du container.
		var dy = 0;
		if (book.closest('.pf-bookshelf--scroll')) {
			var bookH    = cssVarPx(book, '--book-h');
			var topAfter = rect.top
				- (scale - 1) * bookH
				- spineW * oblique(book) * scale;
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
		// Idempotent : init() peut être rappelée après une reconstruction
		// partielle (ex. étagère « Ma liste de lecture » du compte) et
		// balaie tous les .pf-book, y compris ceux déjà câblés.
		if (book._pfHoverWired) return;
		book._pfHoverWired = true;
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
					- spineW * oblique(book) * HOVER_SCALE;
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
		if (book._pfSpineWired) return;   // idempotent (cf. setupHoverShift)
		book._pfSpineWired = true;
		var coverReady  = false;   // image has loaded (or failed)
		var loadStarted = false;   // we've already triggered the fetch
		var hoverActive = false;   // pointer/focus currently on the book
		var hoverStart  = 0;       // ms timestamp of the latest enter
		var loadTimer   = null;    // pending fetch-start setTimeout id
		var revealTimer = null;    // pending reveal setTimeout id
		var touchIntent = false;   // last interaction began with a touch
		var overReco    = false;   // pointer currently on the reco badge/explanation

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
			if (!hoverActive || !coverReady || overReco) return;
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
			// `load` ne signifie que « octets reçus » : le DÉCODAGE en bitmap
			// arrive plus tard, à la première peinture qui en a besoin —
			// c'est-à-dire pile sur la première image de la rotation, d'où une
			// saccade au tout premier dévoilement de chaque livre (mesuré :
			// 3 à 15 ms après `load` en local, davantage sur une machine lente
			// ou une grande couverture). decode() force ce travail AVANT, hors
			// du chemin critique de l'animation. Le délai est absorbé par les
			// REVEAL_DELAY_MS d'attente, donc rien n'est retardé en pratique.
			img.src = img.dataset.src;
			img.removeAttribute('data-src');
			if (typeof img.decode === 'function') {
				// decode() rejette sur erreur de chargement : même issue, on
				// laisse la rotation se faire (couverture manquante = cadre
				// blanc, comme avant).
				img.decode().then(onDone, onDone);
			} else {
				img.addEventListener('load', onDone, { once: true });
				img.addEventListener('error', onDone, { once: true });
			}
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
			// On revient sur le livre pendant son retour : la course reprend
			// depuis là, plus rien à ralentir.
			cancelReleasing(book);
			computeRevealShift(book);
			scheduleCoverLoad();
			attemptReveal();
		}

		function onLeave() {
			hoverActive = false;
			overReco    = false;
			// Cancel any pending fetch that hasn't fired yet — the user
			// moved off before committing. Once `startCoverLoad` has
			// actually set `img.src`, browsers can't reliably abort the
			// in-flight request, but at least we never queued it.
			clearLoad();
			clearReveal();
			// Drop the class so the book closes back, and so the next
			// hover gets a fresh REVEAL_DELAY_MS countdown. Le marquage doit
			// précéder le retrait : il teste l'état saisi.
			markReleasing(book);
			book.classList.remove('pf-book--cover-revealed');
			if (touchOpenBook === book) touchOpenBook = null;
		}

		book.addEventListener('mouseenter', onEnter);
		book.addEventListener('focusin',    onEnter);
		book.addEventListener('mouseleave', onLeave);
		book.addEventListener('focusout',   onLeave);

		// Le badge de recommandation (« ? ») flotte au-dessus du dos mais reste un
		// descendant du livre → sans ça, s'en approcher révélerait la couverture
		// (et le fondu du badge le rendrait alors incliquable). On suspend la
		// révélation tant que le pointeur est sur le badge/l'explication, et on la
		// reprend dès qu'il revient sur le dos. `mouseover` bubble et précède
		// `mouseenter`, donc overReco est posé avant qu'onEnter ne tente de révéler.
		book.addEventListener('mouseover', function (e) {
			var onReco = !! (e.target.closest && e.target.closest('.pf-book-reco'));
			if (onReco === overReco) return;
			overReco = onReco;
			if (overReco) {
				clearReveal();
				book.classList.remove('pf-book--cover-revealed');
			} else if (hoverActive) {
				hoverStart = Date.now();
				scheduleCoverLoad();
				attemptReveal();
			}
		});

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
		if (book._pfCursorWired) return;   // idempotent (cf. setupHoverShift)
		var cover = book.querySelector('.pf-book-cover');
		if (!cover) return;
		book._pfCursorWired = true;

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
