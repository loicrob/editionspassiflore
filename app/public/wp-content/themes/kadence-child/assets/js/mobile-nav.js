/**
 * Tiroir de navigation mobile — dépliage automatique de la catégorie courante.
 *
 * Kadence déplie déjà les parents de la première `.current-menu-item` du tiroir
 * (`initMobileToggleSub`), mais sur ce site le filtre `nav_menu_css_class`
 * (inc/header-hooks.php) marque aussi le lien de premier niveau « Catalogue »
 * (page boutique) comme `current-menu-item` sur toute page produit/catégorie.
 * Comme il apparaît AVANT la vraie sous-catégorie active dans le DOM,
 * `querySelector('.current-menu-item')` le retient en premier — or il n'a aucun
 * `<li>` ancêtre → Kadence ne déplie rien.
 *
 * On rétablit le comportement attendu : quand on est sur l'archive d'une
 * catégorie/sous-catégorie produit, on ouvre toute la chaîne (Catalogue →
 * catégorie → sous-catégorie) en posant la même classe `.show-drawer` et le
 * même `aria-expanded="true"` que le toggle natif de Kadence (état persistant
 * piloté par `.has-collapse-sub-nav ul.sub-menu { display:none }` /
 * `.sub-menu.show-drawer { display:block }`). Aucune animation → pas de flash.
 */
( function () {
	function reveal( li ) {
		var sub = li.querySelector( ':scope > ul.sub-menu' );
		if ( sub ) {
			sub.classList.add( 'show-drawer' );
		}
		var toggle = li.querySelector( ':scope > .drawer-nav-drop-wrap > .drawer-sub-toggle' );
		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', 'true' );
		}
	}

	function run() {
		var drawer = document.getElementById( 'mobile-drawer' );
		if ( ! drawer ) {
			return;
		}
		// Éléments « graine » : la catégorie produit active + ses ancêtres. On ne
		// se base pas sur « Catalogue » (page, current-menu-item sur toute fiche
		// produit) → sur une simple fiche produit, aucune graine, rien ne se déplie.
		var seeds = drawer.querySelectorAll(
			'li.current-product_cat-ancestor, li.menu-item-object-product_cat.current-menu-item'
		);
		seeds.forEach( function ( seed ) {
			reveal( seed ); // ouvre son propre sous-menu (si la catégorie en a un)
			var node = seed.parentNode;
			while ( node && node !== drawer ) {
				if ( 'LI' === node.nodeName ) {
					reveal( node ); // ouvre chaque ancêtre, jusqu'à « Catalogue »
				}
				node = node.parentNode;
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
} )();
