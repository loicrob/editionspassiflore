( function () {
	'use strict';

	if ( typeof PassifloreNewsletter === 'undefined' ) {
		return;
	}

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form.classList || ! form.classList.contains( 'pf-newsletter__form' ) ) {
			return;
		}

		e.preventDefault();

		var message = form.querySelector( '.pf-newsletter__message' );
		var button  = form.querySelector( '.pf-newsletter__btn' );

		if ( ! form.reportValidity() ) {
			return;
		}

		var data = new FormData( form );
		data.append( 'action', 'pf_newsletter_subscribe' );
		data.append( 'nonce', PassifloreNewsletter.nonce );

		form.classList.add( 'is-loading' );
		if ( button ) {
			button.disabled = true;
		}
		if ( message ) {
			message.className = 'pf-newsletter__message';
			message.textContent = '';
		}

		fetch( PassifloreNewsletter.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} )
			.then( function ( r ) {
				if ( r.status === 403 ) {
					if ( window.pfSessionExpired ) { window.pfSessionExpired( { mode: 'confirm' } ); }
					return null;
				}
				return r.json();
			} )
			.then( function ( res ) {
				if ( ! res ) { return; } // 403 : géré par le toast de session
				var ok = res && res.success;
				var text = ( res && res.data && res.data.message ) ||
					( ok ? 'Inscription enregistrée.' : 'Une erreur est survenue.' );
				if ( message ) {
					message.className = 'pf-newsletter__message ' + ( ok ? 'is-success' : 'is-error' );
					message.textContent = text;
				}
				if ( ok ) {
					form.reset();
				}
			} )
			.catch( function () {
				if ( message ) {
					message.className = 'pf-newsletter__message is-error';
					message.textContent = 'Une erreur réseau est survenue. Merci de réessayer.';
				}
			} )
			.finally( function () {
				form.classList.remove( 'is-loading' );
				if ( button ) {
					button.disabled = false;
				}
			} );
	} );

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target;
		if ( ! btn.classList || ! btn.classList.contains( 'pf-newsletter__unsub-btn' ) ) {
			return;
		}

		e.preventDefault();
		btn.disabled = true;

		var data = new FormData();
		data.append( 'action', 'pf_newsletter_unsubscribe' );
		data.append( 'nonce', PassifloreNewsletter.nonce );

		fetch( PassifloreNewsletter.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} )
			.then( function ( r ) {
				if ( r.status === 403 ) {
					if ( window.pfSessionExpired ) { window.pfSessionExpired(); }
					return null;
				}
				return r.json();
			} )
			.then( function ( res ) {
				if ( ! res || ! res.success || ! res.data || ! res.data.html ) {
					btn.disabled = false;
					return;
				}

				var section = btn.closest( '.pf-newsletter' );
				var wrapper = document.createElement( 'div' );
				wrapper.innerHTML = res.data.html;
				var next = wrapper.firstElementChild;

				section.replaceWith( next );

				var message = next.querySelector( '.pf-newsletter__message' );
				if ( message ) {
					message.className = 'pf-newsletter__message is-success';
					message.textContent = res.data.message || '';
				}
			} )
			.catch( function () {
				btn.disabled = false;
			} );
	} );
} )();
