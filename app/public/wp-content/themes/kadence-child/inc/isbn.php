<?php
/**
 * Formatage ISBN-13 selon les tranches officielles de l'Agence Internationale
 * de l'ISBN (RangeMessage.xml, groupes 978-2 et 979-10 — les deux seuls
 * utilisés par Passiflore, éditeur francophone via l'AFNIL).
 *
 * La longueur du préfixe éditeur (2 à 7 chiffres) n'est PAS déductible d'une
 * règle arithmétique : c'est une tranche attribuée, propre à chaque bloc
 * d'ISBN. Le catalogue Passiflore utilise plusieurs blocs de longueurs
 * différentes (ex. préfixe éditeur "37946" sur 5 chiffres, mais "918471" sur
 * 6 chiffres) — un découpage à largeur fixe placerait le tiret au mauvais
 * endroit selon le bloc. Ces deux tables sont donc nécessaires.
 *
 * @package kadence-child
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const PF_ISBN_GROUP_2_RULES = [
	[ 0, 1999999, 2 ], [ 2000000, 3499999, 3 ], [ 3500000, 3999999, 5 ],
	[ 4000000, 4869999, 3 ], [ 4870000, 4949999, 6 ], [ 4950000, 4959999, 3 ],
	[ 4960000, 4966999, 4 ], [ 4967000, 4969999, 5 ], [ 4970000, 5279999, 3 ],
	[ 5280000, 5299999, 4 ], [ 5300000, 6999999, 3 ], [ 7000000, 8399999, 4 ],
	[ 8400000, 8999999, 5 ], [ 9000000, 9197999, 6 ], [ 9198000, 9198099, 5 ],
	[ 9198100, 9199429, 6 ], [ 9199430, 9199689, 7 ], [ 9199690, 9499999, 6 ],
	[ 9500000, 9999999, 7 ],
];

const PF_ISBN_GROUP_10_RULES = [
	[ 0, 1999999, 2 ], [ 2000000, 6999999, 3 ], [ 7000000, 8999999, 4 ],
	[ 9000000, 9759999, 5 ], [ 9760000, 9999999, 6 ],
];

// Formate un ISBN-13 brut (13 chiffres) en "978-2-37946-000-5". Retourne la
// valeur telle quelle si elle ne contient pas 13 chiffres ou ne relève pas
// des groupes 978-2 / 979-10.
function pf_format_isbn( string $isbn ): string {
	$digits = preg_replace( '/\D+/', '', $isbn );
	if ( strlen( $digits ) !== 13 ) return $isbn;

	$prefix = substr( $digits, 0, 3 );
	if ( $prefix === '978' ) {
		$group = '2';
		$rules = PF_ISBN_GROUP_2_RULES;
	} elseif ( $prefix === '979' && substr( $digits, 3, 2 ) === '10' ) {
		$group = '10';
		$rules = PF_ISBN_GROUP_10_RULES;
	} else {
		return $isbn;
	}

	$rest   = substr( $digits, 3 + strlen( $group ), 9 - strlen( $group ) );
	$window = (int) substr( $rest, 0, 7 );

	$pub_len = null;
	foreach ( $rules as [ $start, $end, $len ] ) {
		if ( $window >= $start && $window <= $end ) { $pub_len = $len; break; }
	}
	if ( $pub_len === null ) return $isbn;

	$publisher = substr( $rest, 0, $pub_len );
	$title     = substr( $rest, $pub_len );
	$check     = substr( $digits, 12, 1 );

	return "{$prefix}-{$group}-{$publisher}-{$title}-{$check}";
}
