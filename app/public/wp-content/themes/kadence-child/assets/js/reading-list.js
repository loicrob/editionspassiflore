/* Passiflore — Liste de lecture (bouton toggle sur la fiche livre) */
(function () {
	'use strict';

	if (typeof pfRL === 'undefined') return;

	document.querySelectorAll('.bs-hero__readlist[data-product-id]').forEach(init);

	function init(btn) {
		const label = btn.querySelector('.bs-hero__readlist-label');
		let busy = false;

		btn.addEventListener('click', function () {
			if (busy) return;
			busy = true;
			btn.classList.add('is-busy');

			const fd = new FormData();
			fd.append('action', 'pf_reading_list_toggle');
			fd.append('nonce', pfRL.nonce);
			fd.append('product_id', btn.dataset.productId);

			fetch(pfRL.ajax_url, { method: 'POST', body: fd })
				.then(r => r.json())
				.then(payload => {
					if (!payload || !payload.success) return;
					const inList = !!payload.data.in_list;
					btn.dataset.inList = inList ? '1' : '0';
					btn.classList.toggle('is-in-list', inList);
					btn.setAttribute('aria-pressed', inList ? 'true' : 'false');
					if (label) {
						label.textContent = inList ? btn.dataset.labelIn : btn.dataset.labelAdd;
					}
				})
				.catch(err => { console.error('pf-reading-list:', err); })
				.finally(() => {
					busy = false;
					btn.classList.remove('is-busy');
				});
		});
	}
})();
