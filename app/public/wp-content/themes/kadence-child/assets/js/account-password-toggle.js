/* Anime l'ouverture/fermeture du bloc « Changer de mot de passe »
 * (woocommerce/myaccount/form-edit-account.php) : un <details> natif ne sait
 * pas transitionner sa hauteur, donc le clic est intercepté pour piloter
 * l'animation à la main. La durée est lue depuis --pf-details-duration
 * (account.css), qui gouverne aussi la rotation de la flèche — une seule
 * source pour que les deux restent synchronisées.
 */
( function () {
	var details = document.querySelector( '.pf-account-password' );
	if ( ! details ) {
		return;
	}

	var summary = details.querySelector( 'summary' );
	var body = details.querySelector( '.pf-account-password__body' );
	if ( ! summary || ! body ) {
		return;
	}

	if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		return; // Repli sur le comportement natif, instantané.
	}

	var duration = parseFloat( getComputedStyle( details ).getPropertyValue( '--pf-details-duration' ) ) * 1000;
	if ( ! duration ) {
		duration = 250;
	}
	var animation = null;

	body.style.height = details.open ? 'auto' : '0px';

	summary.addEventListener( 'click', function ( e ) {
		e.preventDefault();
		if ( animation ) {
			animation.cancel();
		}
		if ( details.open ) {
			collapse();
		} else {
			expand();
		}
	} );

	function expand() {
		details.open = true;
		var target = body.scrollHeight;
		body.style.height = '0px';
		animation = body.animate(
			[ { height: '0px' }, { height: target + 'px' } ],
			{ duration: duration, easing: 'ease' }
		);
		animation.onfinish = function () {
			body.style.height = 'auto';
		};
	}

	function collapse() {
		var start = body.scrollHeight;
		body.style.height = start + 'px';
		animation = body.animate(
			[ { height: start + 'px' }, { height: '0px' } ],
			{ duration: duration, easing: 'ease' }
		);
		animation.onfinish = function () {
			details.open = false;
			body.style.height = '0px';
		};
	}
} )();
