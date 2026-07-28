<?php
/**
 * Bandeau bêta (TEMPORAIRE) — phase de test invité.
 *
 * Bandeau sticky site-wide tout en haut du body (au-dessus du header). Le texte
 * « Cliquez ici » (arc-en-ciel) ouvre un pavé à deux onglets : « Bienvenue » et
 * « Bugs connus » (liste éditable ci-dessous). Fermer le pavé (croix, clic
 * extérieur, Échap) replie le bandeau en une pastille « β » sur le logo du
 * header ; un clic dessus rouvre le pavé. État mémorisé en localStorage.
 *
 * Tout est self-contained (ce fichier + assets/css|js/beta-banner.*) pour un
 * retrait trivial au lancement : supprimer les 3 fichiers + le require dans
 * functions.php.
 *
 * ▶ Pour éditer la liste des bugs connus : voir pf_beta_bugs_connus() plus bas.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Liste des points à vérifier en particulier (textes temporaires — à modifier librement).
 * Chaque entrée = une puce. Le HTML simple (<strong>, <em>, <a>) est autorisé.
 *
 * @return string[]
 */
function pf_beta_a_verifier() {
	return [
		'Événements : en particulier pour les événements sur plusieurs jours, ajout d’un événement au calendrier, abonnement à tous les événements',
		'Pertinence des résultats des différentes barres de recherche (barre principale et locales -> Auteurs, Catalogue et Événements)',
		'Passage de commande',
		'Et d’autres trucs auxquels on n’aurait pas pensé !'
	];
}

/**
 * Liste des bugs connus (textes temporaires — à modifier librement).
 * Chaque entrée = une puce. Le HTML simple (<strong>, <em>, <a>) est autorisé.
 *
 * @return string[]
 */
function pf_beta_bugs_connus() {
	return [
		'Sur mobile, pour les étagères en général, pas assez d’espaces autour des livres au sein des étagères',
		'Événements : bordure rouge quand on clique sur un événement',
		'"Mon compte" : design pas top des adresses',
		'Sur mobile, design pas top des "petits" sous-menus dans la page d’un livre, d’un événement et de "Mon compte"',
		'Design pas top pages de connexion, de panier, commande et commande passée',
	];
}

/**
 * Liste des choses prévues (textes temporaires — à modifier librement).
 * Chaque entrée = une puce. Le HTML simple (<strong>, <em>, <a>) est autorisé.
 *
 * @return string[]
 */
function pf_beta_choses_prevues() {
	return [
		'CGU/CGV/Politique confidentialité/Mentions légales',
		'Adapter les claviers mobiles et tablettes en fonction des champs textes'
	];
}

/**
 * Journal des mises à jour (textes temporaires — à modifier librement).
 * Un bloc par date : 'date' (affichée au-dessus) + 'items' (liste des changements).
 * Le HTML simple (<strong>, <em>, <a>) est autorisé dans les items.
 * L'ordre d'affichage suit l'ordre du tableau (mettre la date la plus récente en premier).
 *
 * @return array<int, array{date:string, items:string[]}>
 */
function pf_beta_mises_a_jour() {
	return [
		[
			'date'  => '23 juillet 2026 - 16h',
			'items' => [
				'"Mon compte" : suggestions en page d’accueil, possibilité d’ajouter directement des livres dans la liste de lecture, affichage du catalogue sous la liste de lecture',
				'Amélioration de l’affichage du haut de la page d’accueil',
				'Optimisation des images, devrait donner un gain de vitesse de chargement des pages',
			],
		],
		[
			'date'  => '27 juillet 2026 - 16h',
			'items' => [
				'Baisse significative des temps de chargement',
				'Amélioration de l’affichage des livres dans les étagères',
				'Séparation de Connexion / Création de compte en deux pages',
			],
		],
		[
			'date'  => '28 juillet 2026 - 17h',
			'items' => [
				'Accueil : meilleur affichage du contenu (après la vue d’arrivée) ; ajout de petits textes synthétiques et d’une étagère "Prix et distinctions"',
				'Menu du haut "collant" plus fiable',
				'Amélioration de l’affichage des livres dans les étagères',
				'Admin : on peut désormais dupliquer un événement',
			],
		],
	];
}

/**
 * Primer inline (avant le rendu du body) : masque le bandeau sans flash et
 * prépare l'affichage de la pastille si l'état mémorisé est « replié ».
 */
add_action( 'wp_head', 'pf_beta_banner_head', 1 );
function pf_beta_banner_head() {
	echo "<script>try{if(localStorage.getItem('pf_beta_state')==='collapsed'){document.documentElement.classList.add('pf-beta-collapsed');}}catch(e){}</script>\n";
}

/** Assets (chargés site-wide). */
add_action( 'wp_enqueue_scripts', 'pf_beta_banner_assets' );
function pf_beta_banner_assets() {
	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();
	wp_enqueue_style(
		'pf-beta-banner',
		$uri . '/assets/css/beta-banner.css',
		[ 'chld_thm_cfg_child' ],
		filemtime( $dir . '/assets/css/beta-banner.css' )
	);
	wp_enqueue_script(
		'pf-beta-banner',
		$uri . '/assets/js/beta-banner.js',
		[],
		filemtime( $dir . '/assets/js/beta-banner.js' ),
		true
	);
}

/** Rendu du bandeau + du pavé, tout en haut du body (avant le header Kadence). */
add_action( 'wp_body_open', 'pf_beta_banner_render', 1 );
function pf_beta_banner_render() {
	// Liste des points à vérifier en particulier → puces (HTML simple autorisé).
	$verifier = '';
	foreach ( pf_beta_a_verifier() as $v ) {
		$verifier .= '<li>' . wp_kses( $v, [ 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ) . '</li>';
	}

	// Liste des bugs connus → puces (HTML simple autorisé).
	$bugs = '';
	foreach ( pf_beta_bugs_connus() as $bug ) {
		$bugs .= '<li>' . wp_kses( $bug, [ 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ) . '</li>';
	}

	// Liste des choses prévues → puces (HTML simple autorisé).
	$planned = '';
	foreach ( pf_beta_choses_prevues() as $plan ) {
		$planned .= '<li>' . wp_kses( $plan, [ 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ) . '</li>';
	}

	// Mises à jour → blocs « date + liste » (HTML simple autorisé dans les items).
	$updates = '';
	foreach ( pf_beta_mises_a_jour() as $maj ) {
		$date  = isset( $maj['date'] ) ? trim( $maj['date'] ) : '';
		$items = ( isset( $maj['items'] ) && is_array( $maj['items'] ) ) ? $maj['items'] : [];
		if ( '' === $date && ! $items ) {
			continue;
		}
		if ( '' !== $date ) {
			$updates .= '<p class="pf-beta-bugs-intro pf-beta-date">' . esc_html( $date ) . '</p>';
		}
		$updates .= '<ul class="pf-beta-bugs">';
		foreach ( $items as $it ) {
			$updates .= '<li>' . wp_kses( $it, [ 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ) . '</li>';
		}
		$updates .= '</ul>';
	}

	// Bandeau sticky (état déplié).
	echo <<<'HTML'
<div class="pf-beta-banner" role="region" aria-label="Version bêta du site">
	<div class="pf-beta-banner__inner">
		<p class="pf-beta-banner__text">Bienvenue sur la version bêta du futur site de Passiflore ! <button type="button" class="pf-beta-cta" aria-expanded="false" aria-controls="pf-beta-pop">Cliquez ici</button></p>
	</div>
</div>
HTML;

	// Primer --pf-banner-h : le header colle SOUS ce bandeau (style.css, #masthead
	// en position:sticky avec top = adminbar + hauteur du bandeau). La hauteur
	// dépend de la largeur (le texte se replie) → seule une mesure la donne.
	// Ici, juste après le bandeau : il est dans le DOM, le premier paint n'a pas eu
	// lieu. Sans ce primer, un rechargement AVEC scroll restauré peindrait une image
	// avec le header 1 bandeau trop haut, à cheval dessus.
	// beta-banner.js (ResizeObserver) prend le relais ensuite : repli du bandeau,
	// changement de largeur, arrivée des polices web.
	// TEMPORAIRE, part avec le bandeau : le repli `var(--pf-banner-h, 0px)` de
	// style.css redevient alors exact tout seul.
	echo "<script>(function(){var b=document.querySelector('.pf-beta-banner');"
		. "document.documentElement.style.setProperty('--pf-banner-h',(b?b.getBoundingClientRect().height:0)+'px');})();</script>\n";

	// Pavé à deux onglets (fixed, positionné en JS ; commun aux deux états).
	echo <<<HTML
<div class="pf-beta-pop" id="pf-beta-pop" role="dialog" aria-label="À propos de la version bêta" hidden>
	<button type="button" class="pf-beta-close" aria-label="Fermer">&times;</button>
	<div class="pf-beta-tabs" role="tablist" aria-label="Sections">
		<button type="button" class="pf-beta-tab is-active" id="pf-beta-tab-welcome" role="tab" aria-selected="true" aria-controls="pf-beta-panel-welcome" data-tab="welcome">Bienvenue</button>
		<button type="button" class="pf-beta-tab" id="pf-beta-tab-bugs" role="tab" aria-selected="false" aria-controls="pf-beta-panel-bugs" data-tab="bugs">Problèmes connus</button>
		<button type="button" class="pf-beta-tab" id="pf-beta-tab-updates" role="tab" aria-selected="false" aria-controls="pf-beta-panel-updates" data-tab="updates">Mises à jour</button>
	</div>
	<div class="pf-beta-scroll">
	<div class="pf-beta-tabpanel is-active" id="pf-beta-panel-welcome" role="tabpanel" aria-labelledby="pf-beta-tab-welcome" data-panel="welcome">
		<p>Si vous êtes là, c'est parce qu'on vous a invité à le tester. Alors éprouvez-le ! Baladez-vous, créez un compte, passez commande (vous pouvez aller au bout, promis le paiement est fictif), bref, fouinez…</p>
		<p>Vous trouverez des défauts visuels (les plus grossiers sont probablement en cours de correction, voir "Problèmes connus") et peut-être des bugs 🐞.</p>
		<p>Nous vous saurions gré de les faire remonter (même si ce n’est qu’un détail, si il n’est pas dans la liste il n’est probablement pas déjà pris en compte), ainsi que votre impression générale ou suggestions, à Patricia ou directement à son fils-développeur (c’est lui qui vous parle) → <a href="mailto:loicrobin@icloud.com">loicrobin@icloud.com</a> ou <a href="tel:+33636524161">06 36 52 41 61</a>.</p>
		<p>Bonne visite !</p>
	</div>
	<div class="pf-beta-tabpanel" id="pf-beta-panel-bugs" role="tabpanel" aria-labelledby="pf-beta-tab-bugs" data-panel="bugs" hidden>
		<p class="pf-beta-bugs-intro">Si vous le souhaitez, vous pouvez vérifier en particulier :</p>
		<ul class="pf-beta-bugs">$verifier</ul>
		<p class="pf-beta-bugs-intro">Problèmes déjà repérés (inutile de les signaler) :</p>
		<ul class="pf-beta-bugs">$bugs</ul>
		<p class="pf-beta-bugs-intro">Choses prévues :</p>
		<ul class="pf-beta-bugs">$planned</ul>
	</div>
	<div class="pf-beta-tabpanel" id="pf-beta-panel-updates" role="tabpanel" aria-labelledby="pf-beta-tab-updates" data-panel="updates" hidden>
		$updates
	</div>
	</div><!-- /.pf-beta-scroll -->
</div><!-- /.pf-beta-pop -->
HTML;
}
