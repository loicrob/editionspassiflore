/**
 * Aperçu du permalien en direct pendant la frappe du titre (produit / événement).
 *
 * Le slug de ces types de contenu est de toute façon réécrit depuis le titre à
 * l'enregistrement (inc/post-slug-sync.php) : la boîte « Permalien » perd donc son bouton
 * « Modifier » et affiche à la place ce que le lien deviendra, calculé avec le même
 * découpage que sanitize_title() côté serveur (wp.url.cleanForSlug, @wordpress/url).
 * Redevient un vrai lien (le permalien actuel, inchangé tant que rien n'est enregistré)
 * dès que le titre composé revient à sa valeur enregistrée.
 *
 * Écran produit : #title n'affiche que la RACINE du titre (le suffixe de format en est
 * retiré par edit_post_title) ; le titre réellement enregistré — et donc le slug — porte
 * racine + suffixe (inc/product-format-admin.php, pf_format_compose()). Le suffixe est lu
 * dans window.pfFmt (injecté par ce même écran) pour rester la seule source de vérité.
 */
( function () {
	function ready( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		var title = document.getElementById( 'title' );
		var permalink = document.getElementById( 'sample-permalink' );

		if ( ! title || ! permalink || ! window.wp || ! wp.url || 'function' !== typeof wp.url.cleanForSlug ) {
			return;
		}

		var template = permalink.getAttribute( 'data-pf-permalink-template' );
		if ( ! template ) {
			return;
		}

		var format = document.getElementById( 'pf-fmt-format' );

		function suffixFor( slug ) {
			if ( 'undefined' === typeof window.pfFmt ) {
				return '';
			}
			for ( var i = 0; i < pfFmt.formats.length; i++ ) {
				if ( pfFmt.formats[ i ].slug === slug ) {
					return pfFmt.formats[ i ].suffix;
				}
			}
			return '';
		}

		// Titre tel qu'il sera réellement enregistré (racine + suffixe de format, s'il y en a un).
		function futureTitle() {
			if ( ! format ) {
				return title.value;
			}
			var root = title.value.trim();
			return root ? root + suffixFor( format.value ) : '';
		}

		var originalLinkHTML = permalink.innerHTML;
		var originalText = permalink.textContent;
		var originalTitle = futureTitle();

		function updatePreview() {
			var current = futureTitle();

			// Revenu à la valeur enregistrée : le lien affiché est bien le vrai lien actuel.
			if ( current === originalTitle ) {
				permalink.innerHTML = originalLinkHTML;
				return;
			}

			var slug = wp.url.cleanForSlug( current );
			permalink.textContent = slug
				? template.replace( '%postname%', slug ).replace( '%pagename%', slug )
				: originalText;
		}

		title.addEventListener( 'input', updatePreview );

		if ( format ) {
			format.addEventListener( 'change', updatePreview );
		}
	} );
} )();
