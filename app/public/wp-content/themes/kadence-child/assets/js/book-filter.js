/**
 * Recherche floue locale sur une liste de livres préchargée (admin).
 *
 * Extrait de book-picker.js, où il vivait en double avec book-groups-admin.js.
 * Trois consommateurs aujourd'hui : le picker multi-sélection (book-picker.js),
 * le sélecteur de livre source des « Groupes de livres », et le sélecteur
 * d'œuvre de l'écran produit (product-format-admin.js).
 *
 * window.pfBookFilter( books, query, max ) → sous-ensemble de `books`
 *   books : [ { id, title, … } ] — un champ `_n` (titre normalisé) est mémoïsé
 *           sur chaque entrée au premier passage
 *   max   : plafond de résultats ; 0 / omis = pas de plafond
 */
( function () {

	// Miroir JS de pf_search_normalize() (inc/search.php) : minuscules, sans
	// accents NI ligatures (œ→oe, æ→ae), ponctuation → séparateurs.
	function normalize( s ) {
		return ( s == null ? '' : '' + s ).toLowerCase()
			.replace( /\u0153/g, 'oe' ).replace( /\u00e6/g, 'ae' )
			.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
			.replace( /[^a-z0-9]+/g, ' ' ).trim();
	}

	// Distance de Levenshtein (DP sur une ligne).
	function lev( a, b ) {
		var al = a.length, bl = b.length;
		if ( ! al ) return bl;
		if ( ! bl ) return al;
		var prev = [], i, j;
		for ( j = 0; j <= bl; j++ ) prev[ j ] = j;
		for ( i = 1; i <= al; i++ ) {
			var cur = [ i ];
			for ( j = 1; j <= bl; j++ ) {
				var cost = a.charAt( i - 1 ) === b.charAt( j - 1 ) ? 0 : 1;
				cur[ j ] = Math.min( prev[ j ] + 1, cur[ j - 1 ] + 1, prev[ j - 1 ] + cost );
			}
			prev = cur;
		}
		return prev[ bl ];
	}

	function threshold( tok ) {
		var n = tok.length;
		if ( n < 4 ) return 0;
		if ( n < 7 ) return 1;
		return 2;
	}

	// Un token de requête matche si sous-chaîne d'un token du texte, ou
	// Levenshtein <= seuil (tolérance aux fautes de frappe).
	function tokenMatches( qt, ttoks ) {
		var t, thr = threshold( qt );
		for ( t = 0; t < ttoks.length; t++ ) {
			if ( ttoks[ t ].indexOf( qt ) !== -1 ) return true;
		}
		if ( thr === 0 ) return false;
		for ( t = 0; t < ttoks.length; t++ ) {
			if ( Math.abs( ttoks[ t ].length - qt.length ) > thr ) continue;
			if ( lev( qt, ttoks[ t ] ) <= thr ) return true;
		}
		return false;
	}

	window.pfBookFilter = function ( books, query, max ) {
		if ( ! Array.isArray( books ) ) return [];

		var i, t;
		for ( i = 0; i < books.length; i++ ) {
			if ( books[ i ]._n === undefined ) books[ i ]._n = normalize( books[ i ].title );
		}

		var qn     = normalize( query );
		var tokens = qn ? qn.split( ' ' ) : [];
		var qjoin  = qn.replace( / /g, '' );
		var out    = [];

		for ( i = 0; i < books.length; i++ ) {
			if ( max && out.length >= max ) break;

			var n = books[ i ]._n, ok = true;
			// Sous-chaîne « collée » (gère les caractères spéciaux), sinon tous
			// les tokens doivent matcher (préfixe / sous-chaîne / faute de frappe).
			if ( qjoin && n.replace( / /g, '' ).indexOf( qjoin ) !== -1 ) {
				out.push( books[ i ] );
				continue;
			}
			var ttoks = n ? n.split( ' ' ) : [];
			for ( t = 0; t < tokens.length; t++ ) {
				if ( ! tokenMatches( tokens[ t ], ttoks ) ) { ok = false; break; }
			}
			if ( ok ) out.push( books[ i ] );
		}

		return out;
	};

} )();
