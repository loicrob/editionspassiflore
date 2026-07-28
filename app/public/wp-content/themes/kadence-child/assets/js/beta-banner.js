/* Passiflore — Bandeau bêta (TEMPORAIRE)
 * Deux états, mémorisés en localStorage (clé pf_beta_state) :
 *   - déplié : bandeau sticky en haut + « Cliquez ici » ouvre le pavé ;
 *   - replié : bandeau masqué, pastille « β » sur le logo du header qui rouvre le pavé.
 * Fermer le pavé (croix, clic extérieur, Échap) → replie le bandeau sur le logo.
 * Le pavé a deux onglets (Bienvenue / Bugs connus). À retirer au lancement.
 * (Le masquage sans flash au chargement est assuré par le primer inline de
 *  inc/beta-banner.php, qui pose html.pf-beta-collapsed.)
 */
(function () {
	'use strict';

	var STORAGE_KEY = 'pf_beta_state';
	var COLLAPSED = 'collapsed';

	document.addEventListener('DOMContentLoaded', function () {
		var pop = document.querySelector('.pf-beta-pop');
		if (!pop) return;

		var root    = document.documentElement;
		var banner  = document.querySelector('.pf-beta-banner');
		var cta     = banner ? banner.querySelector('.pf-beta-cta') : null;
		var closeBtn = pop.querySelector('.pf-beta-close');
		var tabs    = Array.prototype.slice.call(pop.querySelectorAll('.pf-beta-tab'));
		var panels  = Array.prototype.slice.call(pop.querySelectorAll('.pf-beta-tabpanel'));

		// ── Pastille « β » injectée dans chaque logo (header desktop + mobile). ──
		document.querySelectorAll('.site-branding').forEach(function (brand) {
			if (brand.querySelector('.pf-beta-dot')) return;
			var dot = document.createElement('button');
			dot.type = 'button';
			dot.className = 'pf-beta-dot';
			dot.textContent = 'β';
			dot.setAttribute('aria-label', 'Version bêta — infos et bugs connus');
			brand.appendChild(dot);
			dot.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				pop.hidden ? openPanel(dot) : closePanel();
			});
		});

		// ── Positionnement du pavé (fixed) sous le déclencheur, borné au viewport. ──
		function positionPanel(trigger) {
			var pad = 8;
			var pw = pop.offsetWidth, ph = pop.offsetHeight;
			var r = trigger.getBoundingClientRect();
			var vw = document.documentElement.clientWidth;
			var vh = window.innerHeight;
			var left = r.left;
			if (left + pw > vw - pad) left = vw - pad - pw;
			if (left < pad) left = pad;
			var top = r.bottom + 6;
			if (top + ph > vh - pad) top = Math.max(pad, vh - pad - ph);
			pop.style.left = Math.round(left) + 'px';
			pop.style.top = Math.round(top) + 'px';
		}

		function openPanel(trigger) {
			pop.style.visibility = 'hidden';
			pop.hidden = false;
			positionPanel(trigger);       // mesure une fois rendu, sans flash (visibility)
			pop.style.visibility = '';
			pop._trigger = trigger;
			if (cta) cta.setAttribute('aria-expanded', 'true');
		}

		function closePanel() {
			pop.hidden = true;
			pop._trigger = null;
			if (cta) cta.setAttribute('aria-expanded', 'false');
		}

		// Fermer le pavé replie TOUJOURS le bandeau sur le logo (idempotent si déjà replié).
		function dismiss() {
			closePanel();
			root.classList.add('pf-beta-collapsed');
			try { localStorage.setItem(STORAGE_KEY, COLLAPSED); } catch (e) {}
		}

		if (cta) {
			cta.addEventListener('click', function (e) {
				e.stopPropagation();
				pop.hidden ? openPanel(cta) : closePanel();
			});
		}
		if (closeBtn) {
			closeBtn.addEventListener('click', function (e) { e.stopPropagation(); dismiss(); });
		}

		// Clic hors du pavé (et hors déclencheurs) → repli.
		document.addEventListener('click', function (e) {
			if (pop.hidden) return;
			if (pop.contains(e.target)) return;
			if (e.target.closest && (e.target.closest('.pf-beta-cta') || e.target.closest('.pf-beta-dot'))) return;
			dismiss();
		});
		// Échap → repli.
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !pop.hidden) dismiss();
		});

		// ── Onglets ──
		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				var name = tab.getAttribute('data-tab');
				tabs.forEach(function (t) {
					var on = t === tab;
					t.classList.toggle('is-active', on);
					t.setAttribute('aria-selected', on ? 'true' : 'false');
				});
				panels.forEach(function (p) {
					p.hidden = p.getAttribute('data-panel') !== name;
				});
			});
		});

		// Recale le pavé quand son déclencheur bouge (le logo replié suit le scroll).
		function reposIfOpen() { if (!pop.hidden && pop._trigger) positionPanel(pop._trigger); }
		window.addEventListener('resize', reposIfOpen, { passive: true });
		window.addEventListener('scroll', reposIfOpen, { passive: true });

		// ── --pf-banner-h : le header colle SOUS le bandeau (style.css, #masthead
		// en position:sticky). La valeur est posée avant le premier paint par le
		// primer inline de inc/beta-banner.php ; on la tient à jour ici pour les
		// trois cas qui la font bouger : repli du bandeau (display:none → 0, le
		// header remonte tout en haut), changement de largeur (le texte se replie),
		// arrivée des polices web. Un ResizeObserver couvre les trois d'un coup —
		// y compris display:none, pour lequel il notifie une taille nulle.
		if (banner && window.ResizeObserver) {
			new ResizeObserver(function () {
				root.style.setProperty('--pf-banner-h', banner.getBoundingClientRect().height + 'px');
				// La ligne de collage du header vient de bouger : --pf-sticky-offset a été
				// calculé sur `resize`, donc AVANT cette notification. On le refait.
				if (window.pfRefreshStickyOffset) window.pfRefreshStickyOffset();
			}).observe(banner);
		}
	});
})();
