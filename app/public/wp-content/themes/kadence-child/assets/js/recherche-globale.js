/* Passiflore — Recherche globale (header) */
(function () {
	'use strict';

	// ── Sticky offset ────────────────────────────────────────────────────────
	// Ce script est chargé sur toutes les pages (via le header). Il est donc
	// la source unique de --pf-sticky-offset ; catalogue.js, recherche-auteurs.js
	// et accueil.js lisent la variable sans la recalculer.
	//
	// Mise à jour au resize / orientationchange / load — PAS au scroll. Le header
	// Kadence garde une position ET une hauteur constantes au scroll (vérifié en
	// headless : offset identique de scrollY=0 à 1600, desktop comme mobile), donc
	// recalculer au scroll ne faisait que réécrire la même valeur… sauf sur iOS, où
	// getBoundingClientRect() est relatif au viewport VISUEL : la mesure « bougeait »
	// quand la barre d'outils Safari se rétracte au scroll → les éléments calés dessus
	// (hero en svh, barres sticky) sautaient. En s'abstenant au scroll, la valeur reste
	// figée pendant le défilement. Seul écart connu et ASSUMÉ : la barre d'admin WP en
	// mobile (position:absolute, défile) → offset figé ~46px trop haut pour un admin
	// connecté prévisualisant sur téléphone ; sans effet pour un vrai visiteur.
	// #masthead est le header collant lui-même (position:sticky, cf. style.css) :
	// son bord bas vaut la même chose à l'arrêt et défilé, donc une seule mesure
	// suffit et elle est juste à tout moment. C'est ce qui a permis de retirer le
	// rattrapage « en-tête défilé au-dessus du viewport » que réclamait le sticky JS
	// de Kadence : après un rechargement avec scroll restauré, il n'avait pas encore
	// re-fixé le header, la mesure tombait à 0 et les barres sticky se collaient
	// derrière lui. Avec un vrai sticky, ce cas n'existe plus.
	function updateStickyOffset() {
		const ab = document.getElementById('wpadminbar');
		let abBottom = 0;
		if (ab) {
			const abRect = ab.getBoundingClientRect();
			if (abRect.bottom > 0) abBottom = abRect.bottom;
		}
		let best = abBottom;
		const header = document.getElementById('masthead');
		if (header && header.offsetHeight) {
			best = Math.max(best, header.getBoundingClientRect().bottom);
		}
		document.documentElement.style.setProperty('--pf-sticky-offset', Math.max(0, best) + 'px');
	}
	window.addEventListener('resize', updateStickyOffset, { passive: true });
	window.addEventListener('orientationchange', updateStickyOffset);
	// Filet : header définitif une fois tout chargé (utile après un reload avec scroll
	// restauré, où le sticky JS de Kadence n'a pas encore re-fixé le header au DOMContentLoaded).
	window.addEventListener('load', updateStickyOffset);

	document.addEventListener('DOMContentLoaded', function () {
		updateStickyOffset();
		document.querySelectorAll('.pf-gsearch').forEach(init);
	});

	function init(root) {
		let config;
		try { config = JSON.parse(root.dataset.config || '{}'); }
		catch (e) { console.error('pf-gsearch: bad config', e); return; }

		const btn      = root.querySelector('.pf-gsearch-btn');
		const panel    = root.querySelector('.pf-gsearch-panel');
		const input    = root.querySelector('.pf-gsearch-input');
		const clearBtn = root.querySelector('.pf-gsearch-clear');
		const results  = root.querySelector('.pf-gsearch-results');

		if (!btn || !panel || !input || !results) return;

		// Placeholder court sur mobile (≤768px) et long au-dessus. Le breakpoint
		// suit celui du CSS (#mobile-header @media max-width:768px). L'instance
		// desktop est masquée ≤768px, donc le swap n'y est jamais visible.
		const ph   = config.placeholders || {};
		const phMq = window.matchMedia('(max-width: 768px)');
		function applyPlaceholder() {
			if (!ph.long) return;
			input.placeholder = phMq.matches ? ph.short : ph.long;
		}
		applyPlaceholder();
		phMq.addEventListener('change', applyPlaceholder);

		// Déplace le panneau de résultats dans <body> (contexte d'empilement racine).
		document.body.appendChild(results);

		// ── Overlay ────────────────────────────────────────────────────────
		let overlay = null;

		function addOverlay() {
			if (overlay) return;
			overlay = document.createElement('div');
			overlay.className = 'pf-gsearch-overlay';
			document.body.appendChild(overlay);
			overlay.addEventListener('click', close);
		}

		function removeOverlay() {
			if (overlay) { overlay.remove(); overlay = null; }
		}

		// L'overlay accompagne le MODE recherche, pas les résultats : il est posé
		// dès le clic sur la loupe (open()) et ne tombe qu'à la fermeture du
		// panneau. Vider le champ ne doit donc pas le retirer… sauf sur tablette
		// (769–1024px), où la barre est permanente, sans bouton toggle : là
		// body.pf-search-open n'est jamais posée et c'est le FOCUS de la barre
		// qui délimite le mode recherche (voir les écouteurs focusin/focusout).
		function removeOverlayIfClosed() {
			if (document.body.classList.contains('pf-search-open')) return;
			if (holdsFocus(document.activeElement)) return; // barre encore active (tablette)
			removeOverlay();
		}

		// Le focus est-il encore DANS la recherche ? Le panneau de résultats est
		// déplacé dans <body> (contexte d'empilement racine) : il sort du root sans
		// sortir de la recherche — et il contient des éléments focusables (les
		// résultats eux-mêmes, et les boutons « + de résultats ») —, un simple
		// root.contains() le lirait à tort comme une sortie.
		function holdsFocus(el) {
			return !!el && (root.contains(el) || results.contains(el));
		}

		// ── Tablette : le focus de la barre tient lieu d'ouverture/fermeture ──
		// Entrer dans le champ = entrer en mode recherche, donc voile ; en sortir
		// le retire. Sur les deux autres largeurs le panneau ouvert prime et ces
		// écouteurs ne changent rien (le voile y est déjà posé par open() et ne
		// tombe qu'à la fermeture, cf. le premier test de removeOverlayIfClosed).
		root.addEventListener('focusin', addOverlay);
		root.addEventListener('focusout', function (e) {
			if (holdsFocus(e.relatedTarget)) return;
			if (document.body.classList.contains('pf-search-open')) return;
			// Résultats affichés = mode toujours actif : le panneau ne doit pas
			// se retrouver posé sur une page non assombrie. C'est aussi ce qui
			// protège le clic SUR un résultat, qui blure le champ avant de naviguer.
			if (results.innerHTML) return;
			removeOverlay();
		});

		// ── Ouvrir ────────────────────────────────────────────────────────
		function open() {
			if (document.body.classList.contains('pf-search-open')) return;
			updateStickyOffset();
			const menu = document.getElementById('primary-menu');
			if (menu) {
				document.documentElement.style.setProperty('--pf-gsearch-nav-w', menu.offsetWidth + 'px');
			}
			document.body.classList.add('pf-search-open');
			addOverlay();
			btn.setAttribute('aria-expanded', 'true');
			input.focus();
		}

		// ── Fermer ────────────────────────────────────────────────────────
		// Sur tablette (769–1024px) la barre est toujours visible, sans bouton
		// toggle (cf. recherche-globale.css) : body.pf-search-open n'est donc
		// jamais posée, seuls l'overlay et le panneau de résultats existent.
		// close() doit pouvoir s'exécuter dans ce cas (sinon un clic sur
		// l'overlay ne fait rien), mais sans l'animation de repli du bouton.
		// ⚠️ Garde de ré-entrance LOCALE à l'instance. Elle lisait la classe
		// `pf-search-closing` de <body> — or il y a DEUX instances .pf-gsearch
		// (header ordinateur + header mobile), chacune avec son close() et son
		// écouteur Escape. Sur mobile, l'instance ordinateur (masquée, mais
		// PREMIÈRE dans le DOM) s'exécutait d'abord, posait la classe et vidait
		// SON panneau — vide — pendant que l'instance mobile sortait sur ce garde :
		// son panneau, lui, gardait son contenu. Échapper puis rouvrir réaffichait
		// donc les résultats périmés. Invisible à 1440px (l'instance visible est la
		// première) et à 900px (barre permanente, pas de toggle → chemin finish()
		// immédiat, qui ne pose jamais la classe).
		let closing = false;

		function close() {
			if (closing) return;
			const wasOpen = document.body.classList.contains('pf-search-open');
			if (!wasOpen && !overlay) return;

			clearTimeout(timer);
			if (abortCtrl) { abortCtrl.abort(); abortCtrl = null; }
			abortMore();
			btn.blur(); // sinon la loupe garde l'état focus/actif après fermeture (le clic sur la croix laisse le focus sur le bouton)
			// Le champ aussi : replié, il resterait focusé derrière un panneau de
			// largeur nulle (desktop/mobile) ; et sur tablette, où le voile suit le
			// focus de la barre, un champ resté focusé après un clic sur l'overlay
			// laisserait le mode recherche « actif » sans voile — plus aucun
			// focusin pour le reposer, puisque le focus n'a jamais bougé.
			input.blur();

			var done = false;
			function finish() {
				if (done) return;
				done = true;
				closing = false;
				document.body.classList.remove('pf-search-open');
				document.body.classList.remove('pf-search-closing');
				btn.setAttribute('aria-expanded', 'false');
				removeOverlay();
				results.innerHTML = '';
				renderedQuery = ''; // invariant : vidée partout où le panneau est vidé
			}

			if (!wasOpen) {
				finish(); // pas de toggle animé sur tablette : fermeture immédiate
				return;
			}

			closing = true;
			document.body.classList.add('pf-search-closing');
			panel.addEventListener('transitionend', function onEnd(e) {
				if (e.propertyName === 'max-width') { panel.removeEventListener('transitionend', onEnd); finish(); }
			});
			setTimeout(finish, 400);
		}

		btn.addEventListener('click', function () {
			document.body.classList.contains('pf-search-open') ? close() : open();
		});
		if (clearBtn) clearBtn.addEventListener('click', function () {
			input.value = '';
			results.innerHTML = '';
			renderedQuery = '';
			abortMore();
			removeOverlayIfClosed();
			input.focus();
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && (document.body.classList.contains('pf-search-open') || overlay)) {
				close();
				btn.focus();
			}
		});

		// ── Recherche AJAX ─────────────────────────────────────────────────
		let timer     = null;
		let abortCtrl = null;
		let moreCtrl  = null;
		// Requête qui a produit les résultats AFFICHÉS. C'est elle que rejoue le
		// bouton « + de résultats », jamais input.value au moment du clic : le
		// champ peut avoir bougé depuis (frappe en attente de débounce, collage)
		// et le lot suivant ne se raccorderait plus au précédent.
		// Invariant : vidée partout où results.innerHTML est vidé.
		let renderedQuery = '';

		function abortMore() {
			if (moreCtrl) { moreCtrl.abort(); moreCtrl = null; }
		}

		function runSearch() {
			const q = input.value.trim();

			if (q.length < 2) {
				renderedQuery = '';
				abortMore();
				results.innerHTML = '';
				removeOverlayIfClosed();
				return;
			}

			addOverlay();
			if (abortCtrl) abortCtrl.abort();
			abortMore(); // le panneau va être remplacé : un lot en vol n'a plus où s'insérer
			abortCtrl = new AbortController();
			results.classList.add('is-loading');

			const fd = new FormData();
			fd.append('action', 'pf_global_search');
			fd.append('search', q);

			fetch(config.ajax_url, { method: 'POST', body: fd, signal: abortCtrl.signal })
				.then(function (r) { return r.json(); })
				.then(function (payload) {
					results.classList.remove('is-loading');
					if (!payload || !payload.success) return;
					renderedQuery     = q;
					results.innerHTML = payload.data.html;
				})
				.catch(function (err) {
					if (err.name !== 'AbortError') {
						console.error('pf-gsearch:', err);
						results.classList.remove('is-loading');
					}
				});
		}

		// ── « + de résultats » / « - de résultats » : lot suivant / repli d'UNE
		// section ─────────────────────────────────────────────────────────────
		// Écouteur DÉLÉGUÉ : results.innerHTML est remplacé en entier à chaque
		// recherche, aucun écouteur posé sur un bouton ne survivrait.
		results.addEventListener('click', function (e) {
			const btnMore = e.target.closest('.pf-gsearch-more');
			if (btnMore) { loadMore(btnMore); return; }
			const btnLess = e.target.closest('.pf-gsearch-less');
			if (btnLess) loadLess(btnLess);
		});

		// L'attente est rendue SUR LE BOUTON, jamais via results.is-loading : le
		// filet d'attente partagé (style.css) est collé au bord HAUT du panneau,
		// lequel défile avec son contenu (overflow-y:auto) — il serait invisible
		// dès qu'on a défilé jusqu'au bouton. Même arbitrage que la recherche
		// /evenements : l'attente se pose là où le résultat va apparaître.
		//
		// Pas d'attribut `disabled` pendant le vol : désactiver l'élément focusé le
		// blure (le focus retombe sur <body>), ce qui égare un utilisateur au
		// clavier en pleine requête. Le verrou est la classe .is-loading, doublée
		// d'aria-disabled pour l'annoncer.
		//
		// « - de résultats » n'a besoin d'aucun aller-retour serveur : il MASQUE
		// (display:none inline, jamais retiré du DOM) le dernier lot rendu
		// visible par « + » (cf. loadLess()). Symétriquement, « + » commence par
		// chercher un lot déjà chargé mais masqué avant d'interroger le serveur —
		// re-cliquer + après - doit réafficher, pas re-télécharger. État par
		// ligne .pf-gsearch-more-row (disparaît avec elle à la recherche
		// suivante, sans nettoyage à écrire) :
		//   pfBatches   — TOUS les lots jamais chargés pour cette section,
		//                 dans l'ordre, chacun { nodes, offsetBefore, offsetAfter }
		//   pfVisible   — nombre de lots, comptés depuis le début, actuellement
		//                 affichés (pfBatches[pfVisible..] sont masqués)
		//   pfExhausted — le DERNIER lot connu est-il la fin de la section ?
		//                 (déterminé une seule fois, au moment de son fetch)
		function loadMore(btnMore) {
			if (btnMore.classList.contains('is-loading') || !renderedQuery) return;

			const moreRow = btnMore.closest('.pf-gsearch-more-row');
			if (!moreRow) return;
			const btnLess = moreRow.querySelector('.pf-gsearch-less');
			moreRow.pfBatches = moreRow.pfBatches || [];
			moreRow.pfVisible  = moreRow.pfVisible  || 0;

			// Un lot déjà chargé attend, masqué, d'être révélé : aucune requête.
			if (moreRow.pfVisible < moreRow.pfBatches.length) {
				const batch = moreRow.pfBatches[moreRow.pfVisible];
				batch.nodes.forEach(function (node) { node.style.display = ''; });
				moreRow.pfVisible++;
				btnMore.dataset.offset = batch.offsetAfter;
				if (btnLess) btnLess.hidden = false;
				btnMore.hidden = moreRow.pfVisible === moreRow.pfBatches.length && !!moreRow.pfExhausted;
				return;
			}

			const prevOffset = btnMore.dataset.offset || '0';

			abortMore();
			moreCtrl = new AbortController();
			btnMore.classList.add('is-loading');
			btnMore.setAttribute('aria-disabled', 'true');

			const fd = new FormData();
			fd.append('action',  'pf_global_search');
			fd.append('search',  renderedQuery);
			fd.append('section', btnMore.dataset.section || '');
			fd.append('offset',  prevOffset);

			fetch(config.ajax_url, { method: 'POST', body: fd, signal: moreCtrl.signal })
				.then(function (r) { return r.json(); })
				.then(function (payload) {
					// Le panneau a pu être re-rendu entre-temps (nouvelle frappe) : ce
					// bouton n'est alors plus dans le document et son lot n'a plus lieu
					// d'être. Filet EN PLUS de l'abort, dont le rejet est asynchrone —
					// une réponse déjà reçue voit son .then() s'exécuter quand même.
					if (!btnMore.isConnected) return;
					btnMore.classList.remove('is-loading');
					btnMore.removeAttribute('aria-disabled');
					if (!payload || !payload.success) return;

					const data = payload.data || {};
					moreRow.pfExhausted = !data.has_more;

					if (data.html) {
						const anchor = moreRow.previousElementSibling; // dernier item déjà en place
						moreRow.insertAdjacentHTML('beforebegin', data.html);

						// Nœuds insérés entre `anchor` (exclu) et `moreRow` (exclu) : c'est
						// le lot que « - de résultats » devra pouvoir masquer.
						const nodes = [];
						let node = anchor ? anchor.nextElementSibling : moreRow.parentElement.firstElementChild;
						while (node && node !== moreRow) { nodes.push(node); node = node.nextElementSibling; }

						moreRow.pfBatches.push({ nodes: nodes, offsetBefore: prevOffset, offsetAfter: data.next_offset || prevOffset });
						moreRow.pfVisible = moreRow.pfBatches.length;
						if (btnLess) btnLess.hidden = false;
					}

					// L'offset du lot suivant vient du serveur : PAGE_SIZE ne vit qu'en PHP.
					if (data.has_more && data.next_offset) {
						btnMore.dataset.offset = data.next_offset;
						return;
					}

					// Section épuisée : le bouton « + » se masque (jamais retiré du DOM —
					// « - » doit pouvoir le faire réapparaître en masquant le dernier
					// lot, qui redonne alors du répondant à « + »). S'il avait le focus
					// (activation clavier), on le déplace sur « - » s'il est visible,
					// sinon sur <body> n'aurait plus de sens ici.
					const wasFocused = document.activeElement === btnMore;
					btnMore.hidden = true;
					if (wasFocused && btnLess && !btnLess.hidden) btnLess.focus();
				})
				.catch(function (err) {
					if (err.name === 'AbortError') return;
					console.error('pf-gsearch:', err);
					if (btnMore.isConnected) {
						btnMore.classList.remove('is-loading');
						btnMore.removeAttribute('aria-disabled');
					}
				});
		}

		// Masque le dernier lot rendu visible par « + de résultats » (ses nœuds
		// restent dans le DOM, cf. loadMore()) et restaure l'offset de « + » à sa
		// valeur d'avant ce lot, pour qu'un nouveau clic sur « + » le RÉVÈLE au
		// lieu de le re-télécharger. Ignoré si un chargement « + » est en vol sur
		// la même ligne : le laisser se terminer d'abord évite qu'il n'écrase
		// l'offset restauré ici avec le sien (calculé sur l'ancien état).
		function loadLess(btnLess) {
			const moreRow = btnLess.closest('.pf-gsearch-more-row');
			const btnMore = moreRow ? moreRow.querySelector('.pf-gsearch-more') : null;
			if (!moreRow || !btnMore || btnMore.classList.contains('is-loading')) return;

			if (!moreRow.pfVisible) return;

			moreRow.pfVisible--;
			const batch = moreRow.pfBatches[moreRow.pfVisible];
			batch.nodes.forEach(function (node) { node.style.display = 'none'; });

			btnMore.dataset.offset = batch.offsetBefore;
			btnMore.hidden = false; // il y a de nouveau du répondant : au moins ce lot masqué

			if (!moreRow.pfVisible) {
				btnLess.hidden = true;
				if (document.activeElement === btnLess) btnMore.focus();
			}
		}

		// Fermer ne vide pas l'input (cf. close()) : si une requête y est encore
		// saisie, on relance la recherche dès que le champ reprend le focus
		// (résultats vidés à la fermeture, mais pas le texte).
		input.addEventListener('focus', function () {
			if (input.value.trim().length >= 2 && !results.innerHTML) runSearch();
		});

		input.addEventListener('input', function () {
			clearTimeout(timer);
			timer = setTimeout(runSearch, 250);
		});

		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); runSearch(); }
		});
	}
})();
