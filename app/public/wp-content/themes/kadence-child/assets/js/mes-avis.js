/* Passiflore — Mon compte « Mes avis » : modifier / supprimer ses avis */
(function () {
	'use strict';

	if (typeof pfAvis === 'undefined') return;

	const list = document.querySelector('.pf-avis-list');
	if (!list) return;

	list.addEventListener('click', function (e) {
		const btn = e.target.closest('[data-action]');
		if (!btn) return;

		const card = btn.closest('.pf-avis-card');
		if (!card) return;

		const action = btn.dataset.action;
		if (action === 'edit')   { toggleEdit(card, true); }
		if (action === 'cancel') { toggleEdit(card, false); }
		if (action === 'delete') { remove(card); }
	});

	list.addEventListener('submit', function (e) {
		const form = e.target.closest('[data-role="edit-form"]');
		if (!form) return;
		e.preventDefault();
		save(form.closest('.pf-avis-card'), form);
	});

	function toggleEdit(card, on) {
		const form = card.querySelector('[data-role="edit-form"]');
		const text = card.querySelector('[data-role="text"]');
		if (!form) return;
		form.hidden = !on;
		if (text) text.style.display = on ? 'none' : '';
	}

	function save(card, form) {
		const textarea = form.querySelector('textarea[name="content"]');
		const content = textarea ? textarea.value.trim() : '';
		if (!content) return;

		setBusy(card, true);

		post('pf_avis_edit', { comment_id: card.dataset.commentId, content: content }, 'confirm')
			.then(payload => {
				if (!payload || !payload.success) return;
				const text = card.querySelector('[data-role="text"]');
				if (text) {
					text.innerHTML = payload.data.content;
					text.style.display = '';
				}
				form.hidden = true;
				updateBadge(card, payload.data);
			})
			.catch(err => { console.error('pf-mes-avis:', err); })
			.finally(() => setBusy(card, false));
	}

	function remove(card) {
		if (!window.confirm('Supprimer définitivement cet avis ?')) return;

		setBusy(card, true);

		post('pf_avis_delete', { comment_id: card.dataset.commentId }, 'reload')
			.then(payload => {
				if (!payload || !payload.success) return;
				card.remove();
			})
			.catch(err => { console.error('pf-mes-avis:', err); })
			.finally(() => setBusy(card, false));
	}

	function updateBadge(card, data) {
		const badge = card.querySelector('.pf-avis-badge');
		if (!badge || !data.badge_label) return;
		badge.className = 'pf-avis-badge pf-badge ' + data.badge_class;
		badge.textContent = data.badge_label;
		card.className = 'pf-avis-card pf-avis-card--' + data.status;
	}

	function post(action, fields, mode) {
		const fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', pfAvis.nonce);
		Object.keys(fields).forEach(k => fd.append(k, fields[k]));
		return fetch(pfAvis.ajax_url, { method: 'POST', body: fd }).then(r => {
			// Nonce périmé (onglet ancien) → toast de session ; mode selon le contexte.
			if (r.status === 403) {
				if (window.pfSessionExpired) window.pfSessionExpired({ mode: mode });
				return null;
			}
			return r.json();
		});
	}

	function setBusy(card, busy) {
		card.classList.toggle('is-busy', busy);
		card.querySelectorAll('button').forEach(b => { b.disabled = busy; });
	}
})();
