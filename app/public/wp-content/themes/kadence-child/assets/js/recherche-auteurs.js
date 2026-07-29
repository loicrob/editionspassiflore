/* Passiflore — Recherche d'auteurs (barre sticky + smart-hide + recherche AJAX) */
(function () {
	'use strict';

	document.querySelectorAll('.pf-rech-auteurs').forEach(init);

	function init(root) {
		let config;
		try { config = JSON.parse(root.dataset.config || '{}'); }
		catch (e) { console.error('pf-recherche-auteurs: bad config', e); return; }

		const sticky = root.querySelector('.pf-rech-sticky');
		const bar    = root.querySelector('.pf-sub-header');
		const grid   = root.querySelector('.pf-rech-grid');
		const input  = root.querySelector('.pf-search-input');
		const clear  = root.querySelector('.pf-search-clear');
		if (!sticky || !grid || !input) return;

		// Attente d'une réponse : filet accent au bas de la barre (composant
		// partagé avec la recherche globale et /evenements, cf. style.css) — la
		// grille se contente de s'estomper, ce qui dit « ces résultats sont
		// périmés » mais pas « quelque chose arrive ».
		function setLoading(on) {
			grid.classList.toggle('is-loading', on);
			if (bar) bar.classList.toggle('is-loading', on);
		}

		// ── Smart-hide : masque au scroll vers le bas, réaffiche vers le haut.
		let lastY = window.scrollY;
		let ticking = false;
		function onScroll() {
			if (ticking) return;
			window.requestAnimationFrame(() => {
				const y = window.scrollY;
				const dy = y - lastY;
				if (dy > 8 && y > 80)        sticky.classList.add('is-hidden');
				else if (dy < -8 || y < 80)  sticky.classList.remove('is-hidden');
				sticky.classList.toggle('is-stuck', y > 4);
				lastY = y;
				ticking = false;
			});
			ticking = true;
		}
		window.addEventListener('scroll', onScroll, { passive: true });
		onScroll();

		// ── Recherche (debounce + AJAX)
		let timer = null;
		let abortCtrl = null;

		function runSearch() {
			if (abortCtrl) abortCtrl.abort();
			abortCtrl = new AbortController();
			setLoading(true);

			const fd = new FormData();
			fd.append('action', 'pf_recherche_auteurs');
			fd.append('search', input.value);

			fetch(config.ajax_url, { method: 'POST', body: fd, signal: abortCtrl.signal })
				.then(r => r.json())
				.then(payload => {
					setLoading(false);
					if (!payload || !payload.success) return;
					grid.innerHTML = payload.data.html;
				})
				// Pas d'extinction sur AbortError : la requête a été annulée par une
				// frappe plus récente, qui vient d'allumer le retour pour elle-même.
				.catch(err => {
					if (err.name !== 'AbortError') { console.error(err); setLoading(false); }
				});

			syncUrl();
		}

		function syncUrl() {
			const url = new URL(window.location.href);
			if (input.value) url.searchParams.set('recherche', input.value);
			else             url.searchParams.delete('recherche');
			history.replaceState(null, '', url.toString());
		}

		input.addEventListener('input', () => {
			clearTimeout(timer);
			timer = setTimeout(runSearch, 250);
		});
		input.addEventListener('keydown', e => {
			if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); runSearch(); }
		});
		if (clear) {
			clear.addEventListener('click', () => {
				input.value = '';
				input.focus();
				clearTimeout(timer);
				runSearch();
			});
		}
	}
})();
