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
		'Temps de chargement parfois assez longs',
		'Si le menu du haut (celui avec le logo Passiflore) ne reste pas collé au haut de l’écran, merci de m’envoyer un SMS, c’est déjà arrivé et je n’ai pas réussi à reproduire le bug',
		'Accueil : alignements et interactions logo rond, "Editions Passiflore" et "En toute indépendance…"',
		'Accueil : bordures latérales slides actualités',
		'Accueil : titres "En ce moment…", "Événements à venir" etc. un peu moches',
		'Sur mobile, pour les étagères en général, pas assez d’espaces autour des livres au sein des étagères',
		'Événements : bordure rouge quand on clique sur un événement',
		'"Mon compte" : design pas top pour afficher les explications de pourquoi les livres sont suggérés (dans Liste de lecture) ; cf. "Choses prévues"',
		'"Mon compte" : design pas top des adresses',
		'Sur mobile, design pas top des "petits" sous-menus dans la page d’un livre, d’un événement et de "Mon compte"',
		'Design pas top pages de connexion, de panier, commande et commande passée',
		'Il peut y avoir des marges blanches en haut et en bas de couverture des formats numériques',
		'Zoom quand on clique sur une zone de texte sur mobile',
		'Marges latérales page d’un auteur sur mobile',
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
		'"Mon compte" : enlever le tableau de bord, arrivée directement sur "Liste de lecture"',
		'"Mon compte" : expliquer suggestions (dans Liste de lecture) -> par défaut livres du plus récent au plus ancien, infos croisées de livres dans liste de lecture et/ou commandés remontent d’autres livres en rapport ; il est prévu d’ajouter les suggestions dans le sous-menu de "Découvrir" de la page Catalogue',
		'CGU/CGV/Politique confidentialité/Mentions légales',
		'Adapter les claviers mobiles et tablettes en fonction des champs textes'
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

	// Bandeau sticky (état déplié).
	echo <<<'HTML'
<div class="pf-beta-banner" role="region" aria-label="Version bêta du site">
	<div class="pf-beta-banner__inner">
		<p class="pf-beta-banner__text">Bienvenue sur la version bêta du futur site de Passiflore ! <button type="button" class="pf-beta-cta" aria-expanded="false" aria-controls="pf-beta-pop">Cliquez ici</button></p>
	</div>
</div>
HTML;

	// Pavé à deux onglets (fixed, positionné en JS ; commun aux deux états).
	echo <<<HTML
<div class="pf-beta-pop" id="pf-beta-pop" role="dialog" aria-label="À propos de la version bêta" hidden>
	<button type="button" class="pf-beta-close" aria-label="Fermer">&times;</button>
	<div class="pf-beta-tabs" role="tablist" aria-label="Sections">
		<button type="button" class="pf-beta-tab is-active" id="pf-beta-tab-welcome" role="tab" aria-selected="true" aria-controls="pf-beta-panel-welcome" data-tab="welcome">Bienvenue</button>
		<button type="button" class="pf-beta-tab" id="pf-beta-tab-bugs" role="tab" aria-selected="false" aria-controls="pf-beta-panel-bugs" data-tab="bugs">Problèmes connus</button>
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
	</div><!-- /.pf-beta-scroll -->
</div><!-- /.pf-beta-pop -->
HTML;
}
