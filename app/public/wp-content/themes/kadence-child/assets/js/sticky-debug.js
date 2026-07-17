/**
 * Passiflore — Détecteur de « header non-sticky » (DIAGNOSTIC TEMPORAIRE).
 *
 * Objectif : capturer l'instant EXACT et la CAUSE quand le header global Kadence
 * perd son collage (« pas sticky »), un bug intermittent non reproductible en
 * automatique. Le header Kadence n'est pas `position:sticky` : son JS bascule la
 * classe `.item-is-fixed` (→ `position:fixed`) au scroll. Un `position:fixed`
 * décroche silencieusement si un ANCÊTRE acquiert transform / filter /
 * will-change / backdrop-filter / contain / perspective (il devient alors le
 * bloc conteneur). Ce script surveille tout ça image par image.
 *
 * ── Utilisation ─────────────────────────────────────────────────────────────
 *   Ouvrir la console (F12) sur le site, puis UNE fois :
 *       pfStickyDebug(true)      → active + recharge ; navigue normalement
 *   Quand le header décroche, une bannière rouge apparaît en bas et un rapport
 *   détaillé est loggué (console.error) ET stocké (persistant entre les pages).
 *   Pour me transmettre le diagnostic, en console :
 *       copy(pfStickyReport())   → le JSON est dans le presse-papier
 *   Pour arrêter :
 *       pfStickyDebug(false)
 *
 * Chargé uniquement pour les administrateurs (cf. inc/sticky-debug.php), et
 * TOTALEMENT inerte tant que le drapeau localStorage n'est pas posé.
 * À SUPPRIMER une fois la cause trouvée (ce fichier + inc/sticky-debug.php + la
 * ligne require dans functions.php).
 */
(function () {
	'use strict';

	var FLAG = 'pf_sticky_debug';
	var LOG_KEY = 'pf_sticky_log';
	var MAX_LOG = 30;      // épisodes conservés
	var RING = 120;        // frames d'historique gardées avant une casse
	var TRAP_RE = /transform|filter|perspective|sticky|fixed/i; // pour will-change

	// ── Toggles globaux (toujours définis, même détecteur inactif) ──────────────
	window.pfStickyDebug = function (on) {
		if (on === false) { try { localStorage.removeItem(FLAG); } catch (e) {} console.log('%cpf-sticky-debug OFF', 'color:#c62836'); location.reload(); return; }
		try { localStorage.setItem(FLAG, '1'); } catch (e) {}
		console.log('%cpf-sticky-debug ON — navigue normalement. Rapport auto quand le header décroche.', 'color:#0a0;font-weight:bold');
		location.reload();
	};
	window.pfStickyReport = function () {
		var log = readLog();
		if (!log.length) { console.log('pf-sticky-debug : aucun incident enregistré (bien !).'); return '[]'; }
		var json = JSON.stringify(log, null, 2);
		console.log('%cpf-sticky-debug : ' + log.length + ' incident(s). JSON ci-dessous (copy(pfStickyReport()) pour le presse-papier).', 'color:#c62836;font-weight:bold');
		console.log(json);
		return json;
	};
	window.pfStickyClear = function () { try { localStorage.removeItem(LOG_KEY); } catch (e) {} console.log('pf-sticky-debug : journal effacé.'); };

	// Inerte si non activé.
	var active = false;
	try { active = !!localStorage.getItem(FLAG); } catch (e) {}
	if (!active) return;

	// ── Utilitaires ─────────────────────────────────────────────────────────────
	function readLog() { try { return JSON.parse(localStorage.getItem(LOG_KEY) || '[]'); } catch (e) { return []; } }
	function writeLog(arr) { try { localStorage.setItem(LOG_KEY, JSON.stringify(arr.slice(-MAX_LOG))); } catch (e) {} }
	function round(n) { return Math.round(n * 10) / 10; }
	function desc(el) {
		if (!el || el === document.documentElement) return 'html';
		if (el === document.body) return 'body';
		var s = el.tagName.toLowerCase();
		if (el.id) s += '#' + el.id;
		if (el.className && typeof el.className === 'string') s += '.' + el.className.trim().split(/\s+/).slice(0, 4).join('.');
		return s.slice(0, 80);
	}

	// Un ancêtre « piège » établit un bloc conteneur pour position:fixed.
	function trapProps(el) {
		var cs = getComputedStyle(el);
		var wc = cs.willChange || '';
		var contain = cs.contain || '';
		var isTrap =
			(cs.transform && cs.transform !== 'none') ||
			(cs.perspective && cs.perspective !== 'none') ||
			(cs.filter && cs.filter !== 'none') ||
			(cs.backdropFilter && cs.backdropFilter !== 'none') ||
			(cs.webkitBackdropFilter && cs.webkitBackdropFilter !== 'none') ||
			(wc && TRAP_RE.test(wc)) ||
			(contain && /paint|layout|strict|content/.test(contain));
		return {
			el: el, isTrap: isTrap,
			transform: cs.transform, perspective: cs.perspective, filter: cs.filter,
			backdropFilter: cs.backdropFilter || cs.webkitBackdropFilter || 'none',
			willChange: wc, contain: contain, position: cs.position, overflow: cs.overflow
		};
	}

	function getHeader() {
		var list = document.querySelectorAll('.kadence-sticky-header');
		for (var i = 0; i < list.length; i++) { if (list[i].offsetHeight > 0) return list[i]; }
		return list[0] || null;
	}

	function adminBarBottom() {
		var ab = document.getElementById('wpadminbar');
		if (!ab) return 0;
		var b = ab.getBoundingClientRect().bottom;
		return b > 0 ? b : 0;
	}

	// ── Boucle de surveillance ───────────────────────────────────────────────────
	var ring = [];         // derniers échantillons
	var brokenSince = null; // timestamp de début d'épisode (perf.now)
	var brokenStreak = 0;  // frames consécutives cassées (anti-blip transitoire)
	var CONFIRM = 2;       // exige N frames cassées avant d'enregistrer
	var banner = null;
	var incidentCount = 0;

	function sample() {
		var hdr = getHeader();
		if (!hdr) { schedule(); return; }
		var rect = hdr.getBoundingClientRect();
		var cs = getComputedStyle(hdr);
		var fixedClass = hdr.classList.contains('item-is-fixed');
		var abB = adminBarBottom();
		var sy = window.scrollY || window.pageYOffset || 0;

		// Cherche l'ancêtre piège le plus proche (bloc conteneur du fixed).
		var trapAncestor = null;
		for (var el = hdr.parentElement; el && el !== document.documentElement; el = el.parentElement) {
			var tp = trapProps(el);
			if (tp.isTrap) { trapAncestor = tp; break; }
		}

		// Signature « cassé » :
		//  A) header fixé (Kadence le croit collé) mais son sommet a dérivé hors
		//     de la zone attendue [-3 ; abB+80] → il défile avec la page (piégé).
		//  B) on a bien défilé mais le header n'est PAS fixé alors qu'il devrait
		//     (seuil Kadence ≈ 0 ici) → il n'a pas collé.
		var driftLow = -3, driftHigh = abB + 80;
		var positional = fixedClass && (rect.top < driftLow || rect.top > driftHigh);
		var notFixing = !fixedClass && sy > (rect.height + 140);
		var broken = positional || notFixing;

		var snap = {
			t: round(performance.now()), sy: Math.round(sy),
			top: round(rect.top), pos: cs.position, fixed: fixedClass,
			trap: trapAncestor ? desc(trapAncestor.el) : null
		};
		ring.push(snap);
		if (ring.length > RING) ring.shift();

		if (broken) {
			brokenStreak++;
			// Confirmé sur CONFIRM frames (ignore un blip d'une frame pendant un
			// re-rendu AJAX / une transition de layout) et une seule fois par épisode.
			if (brokenStreak >= CONFIRM && brokenSince === null) {
				brokenSince = performance.now();
				incidentCount++;
				captureIncident(hdr, rect, cs, fixedClass, sy, abB, trapAncestor, positional ? 'positional-drift' : 'not-fixing');
				showBanner();
			}
		} else {
			brokenStreak = 0;
			if (brokenSince !== null) {
				var dur = Math.round(performance.now() - brokenSince);
				brokenSince = null;
				console.log('%cpf-sticky-debug : header RECOLLÉ après ' + dur + 'ms.', 'color:#0a0');
				appendToLast({ recoveredAfterMs: dur });
			}
		}
		schedule();
	}

	function captureIncident(hdr, rect, cs, fixedClass, sy, abB, trapAncestor, kind) {
		// Chaîne d'ancêtres complète.
		var chain = [];
		for (var el = hdr.parentElement; el; el = el.parentElement) {
			var tp = trapProps(el);
			var row = { node: desc(el), position: tp.position, overflow: tp.overflow };
			if (tp.isTrap) {
				row.TRAP = true;
				row.transform = tp.transform; row.perspective = tp.perspective;
				row.filter = tp.filter; row.backdropFilter = tp.backdropFilter;
				row.willChange = tp.willChange; row.contain = tp.contain;
			}
			chain.push(row);
			if (el === document.documentElement) break;
		}

		var incident = {
			kind: kind,
			url: location.pathname + location.search,
			time: new Date().toISOString(),
			ua: navigator.userAgent,
			viewport: { w: window.innerWidth, h: window.innerHeight, dpr: window.devicePixelRatio },
			scrollY: Math.round(sy),
			header: {
				node: desc(hdr),
				itemIsFixed: fixedClass,
				itemIsStuck: hdr.classList.contains('item-is-stuck'),
				itemAtStart: hdr.classList.contains('item-at-start'),
				computedPosition: cs.position,
				computedTop: cs.top,
				rect: { top: round(rect.top), left: round(rect.left), width: round(rect.width), height: round(rect.height) },
				offsetHeight: hdr.offsetHeight,
				dataStartHeight: hdr.getAttribute('data-start-height'),
				dataShrink: hdr.getAttribute('data-shrink'),
				dataReveal: hdr.getAttribute('data-reveal-scroll-up'),
				parentInlineHeight: hdr.parentElement ? (hdr.parentElement.style.height || '(none)') : null
			},
			bodyHeaderIsFixedClass: document.body.classList.contains('header-is-fixed'),
			bodyClasses: document.body.className,
			adminBarBottom: round(abB),
			pfStickyOffsetVar: getComputedStyle(document.documentElement).getPropertyValue('--pf-sticky-offset').trim(),
			containingBlock: trapAncestor ? desc(trapAncestor.el) : 'viewport (aucun ancêtre piège)',
			trapAncestor: trapAncestor ? {
				node: desc(trapAncestor.el), transform: trapAncestor.transform, perspective: trapAncestor.perspective,
				filter: trapAncestor.filter, backdropFilter: trapAncestor.backdropFilter,
				willChange: trapAncestor.willChange, contain: trapAncestor.contain
			} : null,
			ancestorChain: chain,
			ringLeadingUp: ring.slice(-40)
		};

		var log = readLog();
		log.push(incident);
		writeLog(log);

		console.error('%c⚠ pf-sticky-debug : HEADER DÉCROCHÉ (' + kind + ')', 'color:#fff;background:#c62836;padding:2px 6px;font-weight:bold');
		console.error('Cause probable (bloc conteneur) :', incident.containingBlock);
		if (incident.trapAncestor) console.error('Ancêtre piège :', incident.trapAncestor);
		console.error('Incident complet :', incident);
		console.log('%c→ copy(pfStickyReport()) pour tout envoyer.', 'color:#c62836');
	}

	function appendToLast(extra) {
		var log = readLog();
		if (!log.length) return;
		Object.assign(log[log.length - 1], extra);
		writeLog(log);
	}

	function showBanner() {
		if (!banner) {
			banner = document.createElement('div');
			banner.style.cssText = 'position:fixed;left:8px;bottom:8px;z-index:2147483647;background:#c62836;color:#fff;' +
				'font:600 12px/1.3 -apple-system,BlinkMacSystemFont,sans-serif;padding:8px 12px;border-radius:8px;' +
				'box-shadow:0 4px 16px rgba(0,0,0,.35);max-width:320px;cursor:pointer';
			banner.addEventListener('click', function () { window.pfStickyReport(); });
			// Ajouté à <html> (jamais transformé) pour ne pas se faire piéger lui-même.
			document.documentElement.appendChild(banner);
		}
		banner.textContent = '⚠ Header sticky décroché ×' + incidentCount + ' — clic pour le rapport (copy(pfStickyReport()))';
	}

	var scheduled = false;
	function schedule() { if (!scheduled) { scheduled = true; requestAnimationFrame(function () { scheduled = false; sample(); }); } }

	function start() {
		console.log('%cpf-sticky-debug ACTIF — surveillance du header. pfStickyDebug(false) pour arrêter.', 'color:#0a0;font-weight:bold');
		schedule();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
	else start();
})();
