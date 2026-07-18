<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ═══════════════════════════════════════════════════════════════
   Fiche livre — sections avec nav latérale sticky
   ═══════════════════════════════════════════════════════════════ */

add_filter( 'woocommerce_product_tabs', '__return_empty_array' );
add_action( 'woocommerce_after_single_product_summary', 'passiflore_render_sections_layout', 10 );

// Avis (commentaires produit) : anti-spam à la soumission (honeypot + piège temporel) + nom requis
add_filter( 'preprocess_comment', 'passiflore_avis_spam_check' );

// Tous les avis produit sont systématiquement mis en attente de modération,
// quel que soit le statut de l'auteur (contourne l'auto-approbation des comptes ayant déjà des avis approuvés).
add_filter( 'pre_comment_approved', function ( $approved, $commentdata ) {
	if ( 'spam' !== $approved
		&& ! empty( $commentdata['comment_post_ID'] )
		&& get_post_type( (int) $commentdata['comment_post_ID'] ) === 'product' ) {
		return '0';
	}
	return $approved;
}, 10, 2 );

// Après soumission, renvoyer vers la section avis (l'ancre #comment-XX par défaut n'existe pas dans notre rendu)
add_filter( 'comment_post_redirect', 'passiflore_avis_redirect', 10, 2 );

function passiflore_avis_redirect( $location, $comment ) {
	if ( $comment && get_post_type( $comment->comment_post_ID ) === 'product' ) {
		$location = preg_replace( '/#.*$/', '', $location ) . '#avis-des-lecteurs';
	}
	return $location;
}

// En cas d'échec de soumission d'un avis, renvoyer vers la section avis avec le motif
// (au lieu de la page wp_die brute). Couvre nos contrôles ET les erreurs du core (avis vide, flood, doublon…).
add_action( 'pre_comment_on_post', 'passiflore_avis_catch_errors' );

function passiflore_avis_catch_errors( $post_id ) {
	if ( get_post_type( $post_id ) !== 'product' ) {
		return;
	}
	add_filter( 'wp_die_handler', function () use ( $post_id ) {
		return function ( $message, $title = '', $args = [] ) use ( $post_id ) {
			if ( is_wp_error( $message ) ) {
				$message = $message->get_error_message();
			}
			$text = trim( wp_strip_all_tags( (string) $message ) );
			if ( $text === '' ) {
				$text = 'Votre avis n\'a pas pu être envoyé.';
			}
			$token = wp_generate_password( 20, false ); // alphanumérique
			set_transient( 'pf_avis_err_' . $token, [
				'msg'     => $text,
				'author'  => isset( $_POST['author'] ) ? (string) wp_unslash( $_POST['author'] ) : '',
				'comment' => isset( $_POST['comment'] ) ? (string) wp_unslash( $_POST['comment'] ) : '',
			], 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'avis_erreur', $token, get_permalink( $post_id ) ) . '#avis-des-lecteurs' );
			exit;
		};
	} );
}

function passiflore_avis_spam_check( $commentdata ) {
	$post_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
	if ( ! $post_id || get_post_type( $post_id ) !== 'product' ) {
		return $commentdata;
	}

	// Les modérateurs (réponses depuis l'admin, etc.) ne passent pas par les pièges
	if ( current_user_can( 'moderate_comments' ) ) {
		return $commentdata;
	}

	$rejet = function () {
		wp_die( 'Votre avis n\'a pas pu être envoyé.', 'Erreur', [ 'response' => 403, 'back_link' => true ] );
	};

	// 1. Honeypot : champ caché rempli => bot
	if ( ! empty( $_POST['pf_hp'] ) ) {
		$rejet();
	}

	// 2. Piège temporel : timestamp signé, soumission trop rapide après affichage => bot
	$min_seconds = 4;
	$raw           = isset( $_POST['pf_ts'] ) ? (string) wp_unslash( $_POST['pf_ts'] ) : '';
	[ $ts, $sig ]  = array_pad( explode( ':', $raw, 2 ), 2, '' );
	$signature_ok  = $ts !== '' && hash_equals( hash_hmac( 'sha256', $ts, wp_salt( 'auth' ) ), $sig );
	if ( ! $signature_ok || ( time() - (int) $ts ) < $min_seconds ) {
		$rejet();
	}

	// 3. Nom obligatoire (email retiré)
	if ( '' === trim( (string) ( $commentdata['comment_author'] ?? '' ) ) ) {
		wp_die(
			'Veuillez indiquer votre nom pour publier un avis.',
			'Nom requis',
			[ 'response' => 200, 'back_link' => true ]
		);
	}

	return $commentdata;
}


/* ─── Notifications par email au client (validation + réponse de l'éditeur) ─── */

// Email au client quand son avis passe en « publié ».
add_action( 'transition_comment_status', 'passiflore_avis_notify_approved', 10, 3 );

function passiflore_avis_notify_approved( $new_status, $old_status, $comment ) {
	if ( 'approved' !== $new_status || 'approved' === $old_status ) {
		return;
	}
	if ( get_post_type( $comment->comment_post_ID ) !== 'product' || (int) $comment->comment_parent !== 0 ) {
		return; // seuls les avis de premier niveau déclenchent ce message
	}
	if ( ! is_email( $comment->comment_author_email ) ) {
		return;
	}

	$titre = get_the_title( $comment->comment_post_ID );
	$lien  = get_permalink( $comment->comment_post_ID ) . '#avis-des-lecteurs';

	$sujet   = sprintf( 'Votre avis sur « %s » est publié', $titre );
	$message = "Bonjour,\n\n"
		. sprintf( "Votre avis sur « %s » vient d'être validé et est désormais publié sur notre site.\n\n", $titre )
		. sprintf( "Vous pouvez le consulter ici :\n%s\n\n", $lien )
		. "Merci pour votre contribution.\n\n"
		. "— Éditions Passiflore";

	wp_mail( $comment->comment_author_email, $sujet, $message );
}

// Email au client quand l'éditeur répond à son avis.
add_action( 'wp_insert_comment', 'passiflore_avis_notify_reply', 10, 2 );

function passiflore_avis_notify_reply( $comment_id, $comment ) {
	if ( (int) $comment->comment_parent === 0
		|| get_post_type( $comment->comment_post_ID ) !== 'product'
		|| (int) $comment->comment_approved !== 1 ) {
		return;
	}
	$parent = get_comment( $comment->comment_parent );
	if ( ! $parent || ! is_email( $parent->comment_author_email ) ) {
		return;
	}
	if ( get_comment_meta( $comment_id, '_pf_reply_notified', true ) ) {
		return;
	}
	update_comment_meta( $comment_id, '_pf_reply_notified', 1 );

	$titre = get_the_title( $comment->comment_post_ID );
	$lien  = get_permalink( $comment->comment_post_ID ) . '#avis-des-lecteurs';

	$sujet   = sprintf( "L'éditeur a répondu à votre avis sur « %s »", $titre );
	$message = "Bonjour,\n\n"
		. sprintf( "Les Éditions Passiflore ont répondu à l'avis que vous avez déposé sur « %s ».\n\n", $titre )
		. sprintf( "Vous pouvez consulter la réponse ici :\n%s\n\n", $lien )
		. "— Éditions Passiflore";

	wp_mail( $parent->comment_author_email, $sujet, $message );
}


/* ─── Événements liés à un produit ──────────────────────────── */

// Événements partagés entre tous les formats d'un même format_groupe : un
// événement lié à l'édition classique doit aussi apparaître sur la fiche
// grands-caractères / numérique du même titre.
function passiflore_get_product_events( int $product_id ): array {
	global $wpdb;

	$group_ids = passiflore_get_format_groupe_product_ids( $product_id );

	$rows = $wpdb->get_results(
		"SELECT p.ID, pm.meta_value
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_pf_event_books'
		 WHERE p.post_type = 'tribe_events' AND p.post_status = 'publish'",
		ARRAY_A
	);

	$event_ids = [];
	foreach ( $rows as $row ) {
		$books = maybe_unserialize( $row['meta_value'] );
		if ( is_array( $books ) && array_intersect( array_map( 'intval', $books ), $group_ids ) ) {
			$event_ids[] = (int) $row['ID'];
		}
	}

	if ( empty( $event_ids ) ) return [ 'past' => [], 'upcoming' => [] ];

	$now      = current_time( 'mysql' );
	$past     = [];
	$upcoming = [];

	foreach ( $event_ids as $eid ) {
		$start = get_post_meta( $eid, '_EventStartDate', true );
		$end   = get_post_meta( $eid, '_EventEndDate',   true );

		if ( $end && $end < $now ) {
			if ( get_field( 'evenement_marquant', $eid ) ) {
				$past[] = [ 'id' => $eid, 'start' => $start ];
			}
		} elseif ( $start && $start >= $now ) {
			$upcoming[] = [ 'id' => $eid, 'start' => $start ];
		}
	}

	usort( $past,     fn( $a, $b ) => strcmp( $b['start'], $a['start'] ) );
	usort( $upcoming, fn( $a, $b ) => strcmp( $a['start'], $b['start'] ) );

	return [
		'past'     => array_column( $past,     'id' ),
		'upcoming' => array_column( $upcoming, 'id' ),
	];
}


/* ─── Layout principal ───────────────────────────────────────── */

function passiflore_render_sections_layout() {
	if ( ! is_product() ) return;
	global $product;
	if ( ! $product ) return;
	$id = $product->get_id();

	$sections = [];

	// ── Distinctions ─────────────────────────────────────────────
	$distinctions = get_field( 'distinctions', $id ) ?: [];
	if ( $distinctions ) {
		$html  = '<ul class="bs-distinctions">';
		foreach ( $distinctions as $d ) {
			$html .= '<li><span aria-hidden="true">★</span>' . esc_html( $d['distinction'] ) . '</li>';
		}
		$html .= '</ul>';
		$sections[] = [ 'id' => 'bs-distinctions', 'label' => 'Distinctions', 'html' => $html ];
	}

	// ── Résumé ──────────────────────────────────────────────────
	$description = $product->get_description();
	if ( $description ) {
		$sections[] = [
			'id'    => 'bs-resume',
			'label' => 'Résumé',
			'html'  => '<div class="bs-section__body entry-content">' . apply_filters( 'the_content', $description ) . '</div>',
		];
	}

	// ── Caractéristiques ─────────────────────────────────────────
	ob_start();
	passiflore_tab_caracteristiques();
	$caract_html = trim( ob_get_clean() );
	if ( $caract_html ) {
		$sections[] = [ 'id' => 'bs-caract', 'label' => 'Caractéristiques', 'html' => $caract_html ];
	}

	// ── Auteurs ──────────────────────────────────────────────────
	ob_start();
	$auteurs_has = passiflore_render_auteurs_section( $id );
	$auteurs_html = ob_get_clean();
	if ( $auteurs_has ) {
		$contributions  = get_field( 'contributions', $id );
		$contrib_tids   = [];
		$contrib_total  = 0;
		if ( is_array( $contributions ) ) {
			foreach ( $contributions as $row ) {
				$contrib_total++;
				if ( ( $row['assignation'] ?? '' ) === 'fiche-auteur' ) {
					foreach ( (array) ( $row['fiche-auteur'] ?? [] ) as $item ) {
						$tid = is_object( $item ) ? (int) $item->term_id : absint( $item );
						if ( $tid ) $contrib_tids[] = $tid;
					}
				}
			}
		}
		$all_f = ! empty( $contrib_tids );
		foreach ( $contrib_tids as $tid ) {
			$g = get_field( 'genre', 'auteur_' . $tid );
			if ( ! in_array( $g, [ 'f', 'feminin', 'Féminin' ], true ) ) { $all_f = false; break; }
		}
		$label = $contrib_total <= 1
			? ( $all_f ? 'Autrice' : 'Auteur' )
			: ( $all_f ? 'Autrices' : 'Auteurs' );

		$sections[] = [ 'id' => 'bs-auteurs', 'label' => $label, 'html' => $auteurs_html ];
	}

	// Contenus partagés entre tous les formats d'un même format_groupe
	// (avis, presse, vidéos, podcasts, événements).
	$group_ids = passiflore_get_format_groupe_product_ids( $id );

	// ── Articles de presse ───────────────────────────────────────
	$presse = passiflore_collect_group_repeater( $group_ids, 'articles_de_presse', [ 'titre', 'lien', 'fichier' ] );
	if ( $presse ) {
		ob_start();
		passiflore_render_presse_section( $presse );
		$sections[] = [ 'id' => 'bs-presse', 'label' => 'Articles de presse', 'html' => ob_get_clean() ];
	}

	// ── Vidéos ──────────────────────────────────────────────────
	$videos = passiflore_collect_group_repeater( $group_ids, 'videos', [ 'titre', 'lien', 'fichier_video' ] );
	if ( $videos ) {
		ob_start();
		passiflore_render_videos_section( $videos );
		$sections[] = [ 'id' => 'bs-videos', 'label' => 'Vidéos', 'html' => ob_get_clean() ];
	}

	// ── Podcasts ─────────────────────────────────────────────────
	$podcasts = passiflore_collect_group_repeater( $group_ids, 'podcasts', [ 'titre', 'lien', 'fichier_audio' ] );
	if ( $podcasts ) {
		ob_start();
		passiflore_render_podcasts_section( $podcasts );
		$sections[] = [ 'id' => 'bs-podcasts', 'label' => 'Podcasts', 'html' => ob_get_clean() ];
	}

	// ── Avis des lecteurs (sélection éditeur SCF + avis du site, fusionnés) ──
	$avis_l       = passiflore_collect_avis_scf( $group_ids, 'avis_des_lecteurs' );
	$site_reviews = get_comments( [
		'post__in'     => $group_ids,
		'status'       => 'approve',
		'parent'       => 0, // exclut les réponses de l'éditeur (rattachées à leur avis)
		'type__not_in' => [ 'pingback', 'trackback' ],
	] );

	$avis_lecteurs = passiflore_merge_avis( $avis_l, $site_reviews );

	if ( $avis_lecteurs || comments_open( $id ) ) {
		ob_start();
		passiflore_render_avis_lecteurs( $id, $avis_lecteurs );
		$sections[] = [ 'id' => 'bs-avis-lecteurs', 'label' => 'Avis des lecteurs', 'html' => ob_get_clean() ];
	}

	// ── Avis des libraires ───────────────────────────────────────
	$avis_lb = passiflore_collect_avis_scf( $group_ids, 'avis_des_libraires' );
	if ( $avis_lb ) {
		ob_start();
		passiflore_render_avis_items( passiflore_normalize_avis_scf( $avis_lb ), 'libraires' );
		$sections[] = [ 'id' => 'bs-avis-libraires', 'label' => 'Avis des libraires', 'html' => ob_get_clean() ];
	}

	// ── Événements marquants / à venir ──────────────────────────
	$product_events = passiflore_get_product_events( $id );

	if ( ! empty( $product_events['upcoming'] ) ) {
		$tiles = array_map( [ Passiflore_Event_Tiles::class, 'normalize_event' ], $product_events['upcoming'] );
		$sections[] = [ 'id' => 'bs-evenements-a-venir', 'label' => 'Événements à venir', 'html' => Passiflore_Event_Tiles::render_row( $tiles ) ];
	}

	if ( ! empty( $product_events['past'] ) ) {
		$tiles = array_map( [ Passiflore_Event_Tiles::class, 'normalize_event' ], $product_events['past'] );
		$sections[] = [ 'id' => 'bs-evenements-marquants', 'label' => 'Événements marquants', 'html' => Passiflore_Event_Tiles::render_row( $tiles ) ];
	}

	// ── Livres associés ──────────────────────────────────────────
	$sections = array_merge( $sections, passiflore_get_livres_lies_sections( $id ) );

	if ( empty( $sections ) ) return;

	// ── Rendu (composant partagé) ────────────────────────────────
	echo pf_render_sectionnav( $sections );
}


/* ─── Section Auteurs ────────────────────────────────────────── */

function passiflore_render_auteur_card_texte( string $name ): string {
	$svg = '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="width:100%;height:100%;display:block"><rect width="100" height="100" fill="#f0ebe3"/><circle cx="50" cy="37" r="20" fill="#c0ad8e"/><ellipse cx="50" cy="88" rx="34" ry="24" fill="#c0ad8e"/></svg>';
	ob_start();
	?>
	<div class="pf-card pf-card--static pf-auteur-card pf-card--compact pf-auteur-card--texte">
		<div class="pf-auteur-photo"><?= $svg ?></div>
		<div class="pf-card-content">
			<span class="pf-card-title">
				<span><?= esc_html( $name ) ?></span>
			</span>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

function passiflore_render_auteurs_section( int $id ): bool {
	$contributions = get_field( 'contributions', $id );
	$nom_de_plume  = get_field( 'nom_de_plume', $id );
	$illus         = get_field( 'illustration_de_couverture', $id );

	$type_labels = [
		'traduction'   => 'Traduction',
		'illustration' => 'Illustration',
		'preface'      => 'Préface',
		'postface'     => 'Postface',
		'photographie' => 'Photographie',
	];

	$all_rows = [];

	if ( have_rows( 'contributions', $id ) ) {
		while ( have_rows( 'contributions', $id ) ) {
			the_row();
			$type        = get_sub_field( 'type' ) ?: '';
			$assignation = get_sub_field( 'assignation' ) ?: '';
			$type_label  = $type === 'auteur' ? '' : ( $type_labels[ $type ] ?? ucfirst( str_replace( '-', ' ', $type ) ) );

			if ( $assignation === 'fiche-auteur' ) {
				foreach ( (array) get_sub_field( 'fiche-auteur' ) as $item ) {
					$tid = is_object( $item ) ? (int) $item->term_id : absint( $item );
					if ( $tid ) $all_rows[] = [ 'kind' => 'fiche', 'tid' => $tid, 'role' => $type_label ];
				}
			} elseif ( $assignation === 'champ-texte' ) {
				$n = get_sub_field( 'field_69cd3251156af' ); // nom_de_l'auteur (curly apostrophe in DB)
				if ( $n ) $all_rows[] = [ 'kind' => 'texte', 'name' => $n, 'role' => $type_label ];
			}
		}
	}

	$has_content = $all_rows || $nom_de_plume || $illus;
	if ( ! $has_content ) return false;

	echo '<div class="bs-tab-auteurs">';

	if ( $all_rows ) {
		echo '<div class="pf-scroll-fade">';
		echo '<div class="pf-event-auteurs-scroll pf-hscroll">';
		foreach ( $all_rows as $r ) {
			echo '<div class="bs-auteur-wrapper">';
			if ( $r['kind'] === 'fiche' ) {
				echo passiflore_render_auteur_card( $r['tid'], [ 'variant' => 'compact', 'show_bio' => true ] );
			} else {
				echo passiflore_render_auteur_card_texte( $r['name'] );
			}
			if ( $r['role'] ) {
				echo '<p class="bs-auteur-role pf-label">' . esc_html( $r['role'] ) . '</p>';
			}
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';
	}

	if ( $nom_de_plume ) {
		echo '<p class="bs-auteur-meta">Publié sous le nom de ' . esc_html( $nom_de_plume ) . '</p>';
	}

	if ( $illus ) {
		echo '<p class="bs-auteur-meta">Illustration de couverture&nbsp;: ' . esc_html( $illus ) . '</p>';
	}

	echo '</div>';
	return true;
}


/* ─── Sections Presse / Vidéos / Podcasts ────────────────────── */

function passiflore_render_presse_section( array $items ): void {
	$seuil = 3;
	echo '<div class="bs-media-grid">';
	foreach ( $items as $i => $article ) {
		$hidden = $i >= $seuil ? ' bs-item--hidden' : '';
		$url    = '';
		if ( ( $article['type'] ?? '' ) === 'lien' && ! empty( $article['lien'] ) ) {
			$url = $article['lien'];
		} elseif ( ( $article['type'] ?? '' ) === 'fichier' && ! empty( $article['fichier'] ) ) {
			$url = (string) wp_get_attachment_url( $article['fichier'] );
		}
		echo '<div class="pf-card' . ( $url ? '' : ' pf-card--static' ) . $hidden . '">';
		if ( $url ) {
			echo '<a class="pf-card-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( $article['titre'] ?: 'Lire l’article' ) . '"></a>';
		}
		echo '<div class="pf-card-content">';
		if ( ! empty( $article['titre'] ) ) {
			echo '<strong class="pf-card-title">' . esc_html( $article['titre'] ) . '</strong>';
		}
		if ( ! empty( $article['description'] ) ) {
			echo '<p class="pf-card-text">' . nl2br( esc_html( $article['description'] ) ) . '</p>';
		}
		echo '</div></div>';
	}
	echo '</div>';
	if ( count( $items ) > $seuil ) {
		echo '<button class="bs-voir-tout pf-btn pf-btn--neutral pf-btn--sm" type="button">Voir tout (' . ( count( $items ) - $seuil ) . ')</button>';
	}
}

/**
 * Détecte si une URL pointe directement vers un fichier média lisible
 * nativement par le navigateur (d'après l'extension), pour les liens
 * que l'oEmbed ne sait pas embarquer. Retourne 'video', 'audio' ou ''.
 */
function passiflore_direct_media_type( string $url ): string {
	$path = (string) parse_url( $url, PHP_URL_PATH );
	$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	if ( in_array( $ext, [ 'mp4', 'webm', 'ogv', 'm4v', 'mov' ], true ) ) return 'video';
	if ( in_array( $ext, [ 'mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac' ], true ) ) return 'audio';
	return '';
}

/**
 * Dimensions [largeur, hauteur] d'un embed oEmbed depuis les attributs de
 * l'iframe, pour épouser le format réel de la vidéo (aspect-ratio + plafond
 * de hauteur via --bs-ar côté CSS). Retourne [w, h] (floats) ou [] si
 * indétectable.
 */
function passiflore_embed_dimensions( string $html ): array {
	if ( preg_match( '/\bwidth=["\'](\d+(?:\.\d+)?)["\']/', $html, $w )
		&& preg_match( '/\bheight=["\'](\d+(?:\.\d+)?)["\']/', $html, $h )
		&& (float) $h[1] > 0 ) {
		return [ (float) $w[1], (float) $h[1] ];
	}
	return [];
}

/**
 * wp_oembed_get() mis en cache (transient). wp_oembed_get() refait un appel
 * HTTP au fournisseur à chaque rendu : quand cette requête échoue ou expire
 * (réseau, lenteur, rate-limit), elle renvoie false et on retombe sur le lien
 * — d'où des embeds qui apparaissent « au hasard » selon les rechargements.
 * On met les succès en cache une semaine ; les échecs ne sont pas mis en cache
 * (nouvelle tentative au prochain chargement, jusqu'à ce que ça passe).
 */
function passiflore_cached_oembed( string $url ): string {
	$key  = 'pf_oembed_' . md5( $url );
	$html = get_transient( $key );
	if ( is_string( $html ) ) {
		return $html;
	}
	$html = wp_oembed_get( $url );
	if ( $html ) {
		set_transient( $key, $html, WEEK_IN_SECONDS );
		return $html;
	}
	return '';
}

function passiflore_render_videos_section( array $items ): void {
	$seuil = 3;
	echo '<div class="bs-media-grid">';
	foreach ( $items as $i => $video ) {
		$hidden = $i >= $seuil ? ' bs-item--hidden' : '';
		$url    = '';
		$media  = '';

		if ( ( $video['type'] ?? '' ) === 'fichier-video' && ! empty( $video['fichier_video'] ) ) {
			$file_url = wp_get_attachment_url( $video['fichier_video'] );
			if ( $file_url ) $media = '<div class="bs-media-figure"><video class="bs-media-video" controls preload="metadata" src="' . esc_url( $file_url ) . '"></video></div>';
		} elseif ( ! empty( $video['lien'] ) ) {
			$embed = passiflore_cached_oembed( $video['lien'] );
			if ( $embed ) {
				$dims  = passiflore_embed_dimensions( $embed );
				$style = '';
				if ( $dims ) {
					$style = ' style="aspect-ratio:' . esc_attr( $dims[0] . ' / ' . $dims[1] )
						. ';--bs-ar:' . esc_attr( round( $dims[0] / $dims[1], 4 ) ) . '"';
				}
				$media = '<div class="bs-media-figure"><div class="bs-media-embed"' . $style . '>' . $embed . '</div></div>';
			} elseif ( passiflore_direct_media_type( $video['lien'] ) === 'video' ) {
				$media = '<div class="bs-media-figure"><video class="bs-media-video" controls preload="metadata" src="' . esc_url( $video['lien'] ) . '"></video></div>';
			} else {
				$url = $video['lien'];
			}
		}

		echo '<div class="pf-card' . ( $url ? '' : ' pf-card--static' ) . $hidden . '">';
		if ( $url ) {
			echo '<a class="pf-card-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( $video['titre'] ?: 'Voir la vidéo' ) . '"></a>';
		}
		echo '<div class="pf-card-content">';
		if ( ! empty( $video['titre'] ) ) {
			echo '<strong class="pf-card-title">' . esc_html( $video['titre'] ) . '</strong>';
		}
		if ( ! empty( $video['description'] ) ) {
			echo '<p class="pf-card-text">' . nl2br( esc_html( $video['description'] ) ) . '</p>';
		}
		echo $media;
		echo '</div></div>';
	}
	echo '</div>';
	if ( count( $items ) > $seuil ) {
		echo '<button class="bs-voir-tout pf-btn pf-btn--neutral pf-btn--sm" type="button">Voir tout (' . ( count( $items ) - $seuil ) . ')</button>';
	}
}

function passiflore_render_podcasts_section( array $items ): void {
	$seuil = 3;
	echo '<div class="bs-media-grid">';
	foreach ( $items as $i => $podcast ) {
		$hidden = $i >= $seuil ? ' bs-item--hidden' : '';
		$url    = '';
		$media  = '';

		if ( ( $podcast['type'] ?? '' ) === 'fichier-audio' && ! empty( $podcast['fichier_audio'] ) ) {
			$file_url = wp_get_attachment_url( $podcast['fichier_audio'] );
			if ( $file_url ) $media = '<div class="bs-media-figure"><audio class="bs-media-audio" controls src="' . esc_url( $file_url ) . '"></audio></div>';
		} elseif ( ( $podcast['type'] ?? '' ) === 'lien' && ! empty( $podcast['lien'] ) ) {
			$embed = passiflore_cached_oembed( $podcast['lien'] );
			if ( $embed ) {
				$media = '<div class="bs-media-figure"><div class="bs-media-embed bs-media-embed--audio">' . $embed . '</div></div>';
			} elseif ( passiflore_direct_media_type( $podcast['lien'] ) === 'audio' ) {
				$media = '<div class="bs-media-figure"><audio class="bs-media-audio" controls preload="metadata" src="' . esc_url( $podcast['lien'] ) . '"></audio></div>';
			} else {
				$url = $podcast['lien'];
			}
		}

		echo '<div class="pf-card' . ( $url ? '' : ' pf-card--static' ) . $hidden . '">';
		if ( $url ) {
			echo '<a class="pf-card-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( $podcast['titre'] ?: 'Écouter' ) . '"></a>';
		}
		echo '<div class="pf-card-content">';
		if ( ! empty( $podcast['titre'] ) ) {
			echo '<strong class="pf-card-title">' . esc_html( $podcast['titre'] ) . '</strong>';
		}
		if ( ! empty( $podcast['description'] ) ) {
			echo '<p class="pf-card-text">' . nl2br( esc_html( $podcast['description'] ) ) . '</p>';
		}
		echo $media;
		echo '</div></div>';
	}
	echo '</div>';
	if ( count( $items ) > $seuil ) {
		echo '<button class="bs-voir-tout pf-btn pf-btn--neutral pf-btn--sm" type="button">Voir tout (' . ( count( $items ) - $seuil ) . ')</button>';
	}
}


/* ─── Caractéristiques ───────────────────────────────────────── */

// Formate un ISBN-13 brut (13 chiffres) en "978-2-37946-130-9". Retourne la
// valeur telle quelle si elle ne contient pas exactement 13 chiffres.
function passiflore_format_isbn13( string $isbn ): string {
	$digits = preg_replace( '/\D+/', '', $isbn );
	if ( strlen( $digits ) !== 13 ) return $isbn;
	return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 1 ) . '-' . substr( $digits, 4, 5 ) . '-' . substr( $digits, 9, 3 ) . '-' . substr( $digits, 12, 1 );
}

function passiflore_tab_caracteristiques() {
	global $product;
	$id = $product->get_id();

	$isbn       = $product->get_meta( '_global_unique_id' );
	$pages      = get_field( 'nombre_de_pages', $id );
	$date_raw   = get_field( 'date_de_parution', $id );
	$type       = get_field( 'type', $id );
	$reliure    = get_field( 'type_de_reliure', $id );
	$public_val = get_field( 'public', $id );
	$langues    = get_field( 'langues', $id );

	$type_labels = [
		'roman' => 'Roman', 'nouvelles' => 'Nouvelles', 'bande-dessinee' => 'Bande dessinée',
		'beau-livre' => 'Beau-livre', 'album-illustre' => 'Album illustré',
		'textes-courts' => 'Textes courts', 'chroniques' => 'Chroniques',
		'recit' => 'Récit', 'conte' => 'Conte', 'biographie' => 'Biographie',
		'documentaire' => 'Documentaire',
	];
	$reliure_labels = [ 'broche' => 'Broché', 'cousu' => 'Cousu' ];
	$public_labels  = [ 'tout-public' => 'Tout public', 'adulte' => 'Adulte', 'jeunesse' => 'Jeunesse' ];
	$langues_labels = [
		'francais' => 'Français', 'anglais' => 'Anglais', 'espagnol' => 'Espagnol',
		'allemand' => 'Allemand', 'occitan-gascon' => 'Occitan / Gascon', 'basque' => 'Basque',
	];

	echo '<div class="bs-tab-caract">';

	$rows = [];
	if ( $isbn ) {
		$rows[] = [ 'ISBN', passiflore_format_isbn13( $isbn ) ];
	}
	if ( $pages ) {
		$rows[] = [ 'Pages', $pages . ' pages' ];
	}
	if ( $date_raw ) {
		$date = DateTime::createFromFormat( 'Ymd', $date_raw );
		if ( $date ) {
			$rows[] = [ 'Date de parution', date_i18n( 'j F Y', $date->getTimestamp() ) ];
		}
	}
	if ( $type ) {
		$rows[] = [ 'Genre', $type_labels[ $type ] ?? ucfirst( $type ) ];
	}
	if ( $reliure ) {
		$rows[] = [ 'Reliure', $reliure_labels[ $reliure ] ?? ucfirst( $reliure ) ];
	}
	if ( $public_val ) {
		$rows[] = [ 'Public', $public_labels[ $public_val ] ?? ucfirst( $public_val ) ];
	}
	if ( $langues && is_array( $langues ) ) {
		$lang_labels = array_map( fn( $l ) => $langues_labels[ $l ] ?? ucfirst( $l ), $langues );
		$rows[] = [ 'Langue(s)', implode( ', ', $lang_labels ) ];
	}

	$weight = $product->get_weight();
	if ( $weight ) {
		$rows[] = [ 'Poids', wc_format_localized_decimal( $weight ) . ' ' . get_option( 'woocommerce_weight_unit' ) ];
	}
	$dimensions = $product->get_dimensions();
	if ( $dimensions ) {
		$rows[] = [ 'Dimensions', $dimensions ];
	}

	foreach ( $product->get_attributes() as $attr ) {
		if ( ! $attr->get_visible() ) continue;
		$label = wc_attribute_label( $attr->get_name() );
		if ( $attr->is_taxonomy() ) {
			$terms = wc_get_product_terms( $id, $attr->get_name(), [ 'fields' => 'names' ] );
			$value = implode( ', ', $terms );
		} else {
			$value = implode( ', ', $attr->get_options() );
		}
		if ( $value ) {
			$rows[] = [ $label, $value ];
		}
	}

	if ( $rows ) {
		echo '<table class="bs-caract-table">';
		foreach ( $rows as [ $label, $value ] ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</table>';
	}

	echo '</div>';
}


/* ─── Livres associés ───────────────────────────────────────── */

/**
 * Swap each product ID for its sibling sharing the same format_groupe term
 * and the requested pa_format_particulier slug. Falls back to the original ID
 * when no sibling exists (fallback = format principal / classique).
 * Returns $ids unchanged when $format_slug is '' (current book is classique).
 */
function passiflore_ids_in_format( array $ids, string $format_slug ): array {
	if ( $format_slug === '' || empty( $ids ) ) return $ids;

	$result = [];
	foreach ( $ids as $product_id ) {
		$fg = wp_get_object_terms( $product_id, 'format_groupe', [ 'fields' => 'ids' ] );
		if ( is_wp_error( $fg ) || empty( $fg ) ) {
			$result[] = $product_id;
			continue;
		}
		$sibling = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'tax_query'      => [
				[ 'taxonomy' => 'format_groupe',         'terms' => $fg ],
				[ 'taxonomy' => 'pa_format_particulier', 'field' => 'slug', 'terms' => $format_slug ],
			],
		] );
		if ( ! empty( $sibling ) ) {
			$result[] = (int) $sibling[0];
		} else {
			$result[] = Passiflore_Bookshelf::get_group_representative( $fg[0] ) ?? $product_id;
		}
	}
	return $result;
}

/**
 * Returns all format siblings of $id (same format_groupe, excluding $id itself)
 * in the canonical fallback order: classique → grands-caracteres → poche → numerique → audio.
 * Mirrors the priority used by Passiflore_Bookshelf::get_group_representative.
 */
function passiflore_get_format_siblings_ordered( int $id, bool $include_self = false ): array {
	$fg = wp_get_object_terms( $id, 'format_groupe', [ 'fields' => 'ids' ] );
	if ( is_wp_error( $fg ) || empty( $fg ) ) return [];

	$base = [
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	];
	if ( ! $include_self ) {
		$base['post__not_in'] = [ $id ];
	}

	$classique = get_posts( $base + [ 'tax_query' => [
		'relation' => 'AND',
		[ 'taxonomy' => 'format_groupe',         'terms'    => $fg ],
		[ 'taxonomy' => 'pa_format_particulier', 'operator' => 'NOT EXISTS' ],
	] ] );

	$result = array_map( 'intval', $classique );
	foreach ( [ 'grands-caracteres', 'poche', 'numerique', 'audio' ] as $slug ) {
		$others = get_posts( $base + [ 'tax_query' => [
			'relation' => 'AND',
			[ 'taxonomy' => 'format_groupe',         'terms' => $fg ],
			[ 'taxonomy' => 'pa_format_particulier', 'field' => 'slug', 'terms' => $slug ],
		] ] );
		$result = array_merge( $result, array_map( 'intval', $others ) );
	}
	return $result;
}

function passiflore_get_livres_lies_sections( int $id ): array {
	$fmt_terms      = wp_get_object_terms( $id, 'pa_format_particulier', [ 'fields' => 'slugs' ] );
	$current_format = ( ! is_wp_error( $fmt_terms ) && ! empty( $fmt_terms ) ) ? $fmt_terms[0] : '';

	$internal = [];

	$all_formats = passiflore_get_format_siblings_ordered( $id, true );
	if ( ! empty( $all_formats ) && count( $all_formats ) > 1 ) {
		$internal[] = [ 'title' => 'Formats du livre', 'ids' => $all_formats, 'sort_by_date' => false, 'raw_ids' => true, 'display_formats' => true ];
	}

	// Série & traductions : groupes globaux symétriques gérés via Produits →
	// Groupes de livres (taxonomies pf_serie / pf_traduction). La composition
	// vient de la taxonomie, l'ordre du term meta (drag-reorder). Les membres
	// incluent le livre courant ; on n'affiche la section qu'au-delà de 1.
	$serie_terms = wp_get_object_terms( $id, 'pf_serie', [ 'fields' => 'ids' ] );
	if ( ! is_wp_error( $serie_terms ) && ! empty( $serie_terms ) ) {
		$serie_reps = pf_bg_group_member_reps( 'pf_serie', '_pf_serie_order', (int) $serie_terms[0] );
		if ( count( $serie_reps ) > 1 ) {
			$internal[] = [ 'title' => 'De la même série', 'ids' => $serie_reps, 'sort_by_date' => false ];
		}
	}

	$trad_terms = wp_get_object_terms( $id, 'pf_traduction', [ 'fields' => 'ids' ] );
	if ( ! is_wp_error( $trad_terms ) && ! empty( $trad_terms ) ) {
		$trad_reps = pf_bg_group_member_reps( 'pf_traduction', '_pf_traduction_order', (int) $trad_terms[0] );
		if ( count( $trad_reps ) > 1 ) {
			$internal[] = [ 'title' => 'Traductions', 'ids' => $trad_reps, 'sort_by_date' => false ];
		}
	}

	// Vous aimerez aussi : reco orientée, post meta sur le représentant source.
	$aimerez_ids = (array) get_post_meta( pf_bg_representative( $id ), '_pf_vous_aimerez', true );
	$aimerez_ids = array_values( array_filter( array_map( 'intval', $aimerez_ids ) ) );
	if ( $aimerez_ids ) {
		$internal[] = [ 'title' => 'Vous aimerez aussi', 'ids' => $aimerez_ids, 'sort_by_date' => false ];
	}

	$auteur_term_ids = passiflore_get_product_author_ids( $id );
	if ( $auteur_term_ids ) {
		$exclude  = [ $id ];
		$fg_terms = wp_get_object_terms( $id, 'format_groupe', [ 'fields' => 'ids' ] );
		if ( ! is_wp_error( $fg_terms ) && ! empty( $fg_terms ) ) {
			$fg_siblings = get_posts( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post__not_in'   => [ $id ],
				'tax_query'      => [ [ 'taxonomy' => 'format_groupe', 'terms' => $fg_terms ] ],
			] );
			$exclude = array_merge( $exclude, array_map( 'intval', $fg_siblings ) );
		}
		$candidates = passiflore_dedup_by_format_groupe( array_values( array_diff(
			passiflore_product_ids_by_auteur_terms( $auteur_term_ids ),
			$exclude
		) ) );
		foreach ( passiflore_group_books_by_author_set( $auteur_term_ids, $candidates ) as $group ) {
			$internal[] = [
				'title'        => passiflore_author_group_title( $group, $auteur_term_ids, 'book' ),
				'ids'          => $group['product_ids'],
				'sort_by_date' => true,
			];
		}
	}

	$result = [];
	foreach ( $internal as $section ) {
		$ids = $section['ids'];
		if ( ! empty( $section['sort_by_date'] ) ) {
			usort( $ids, function ( $a, $b ) {
				$da = (string) get_post_meta( $a, 'date_de_parution', true );
				$db = (string) get_post_meta( $b, 'date_de_parution', true );
				return strcmp( $db, $da );
			} );
		}
		if ( empty( $section['raw_ids'] ) ) {
			$ids = passiflore_ids_in_format( $ids, $current_format );
		}
		$ids_str         = implode( ',', array_map( 'absint', $ids ) );
		$display_formats = ! empty( $section['display_formats'] ) ? ' display_formats="true"' : '';
		$result[]   = [
			'id'    => 'bs-lies-' . sanitize_title( $section['title'] ),
			'label' => $section['title'],
			'html'  => do_shortcode( '[passiflore_etagere ids="' . $ids_str . '" mode="scroll" display="covers" nb_books_first_displayed="20"' . $display_formats . ']' ),
		];
	}
	return $result;
}


/* ─── Avis ───────────────────────────────────────────────────── */

// Parse une date SCF `d/m/Y` en timestamp. Retourne 0 si vide ou illisible.
function passiflore_avis_parse_ts( string $dmy ): int {
	$dmy = trim( $dmy );
	if ( $dmy === '' ) return 0;
	$d = DateTime::createFromFormat( 'd/m/Y', $dmy );
	return $d ? $d->getTimestamp() : 0;
}

// Normalise un repeater d'avis SCF en entrées [ titre, text, auteur, date, ts ], triées du + récent au + ancien.
function passiflore_normalize_avis_scf( array $items ): array {
	$entries = [];
	foreach ( $items as $a ) {
		$raw = (string) ( $a['date_de_publication'] ?? '' );
		$ts  = passiflore_avis_parse_ts( $raw );
		$entries[] = [
			'titre'  => (string) ( $a['titre']  ?? '' ),
			'text'   => (string) ( $a['avis']   ?? '' ),
			'auteur' => (string) ( $a['auteur'] ?? '' ),
			'date'   => $ts ? date_i18n( 'j F Y', $ts ) : $raw,
			'ts'     => $ts,
			'reply'  => null,
		];
	}
	usort( $entries, fn( $a, $b ) => $b['ts'] <=> $a['ts'] );
	return $entries;
}

// Fusionne avis SCF (sélection éditeur) + commentaires WooCommerce en une liste unique triée du + récent au + ancien.
function passiflore_merge_avis( array $scf_items, array $comments ): array {
	$entries = passiflore_normalize_avis_scf( $scf_items );
	foreach ( $comments as $c ) {
		$entries[] = [
			'titre'  => '',
			'text'   => (string) $c->comment_content,
			'auteur' => (string) $c->comment_author,
			'date'   => get_comment_date( 'j F Y', $c ),
			'ts'     => (int) strtotime( $c->comment_date_gmt . ' GMT' ),
			'reply'  => passiflore_avis_public_reply( (int) $c->comment_ID ),
		];
	}
	usort( $entries, fn( $a, $b ) => $b['ts'] <=> $a['ts'] );
	return $entries;
}

// Réponse de l'éditeur à un avis, uniquement si marquée « publique » (meta _pf_reply_public). Sinon null.
function passiflore_avis_public_reply( int $comment_id ): ?array {
	$replies = get_comments( [
		'parent'     => $comment_id,
		'status'     => 'approve',
		'number'     => 1,
		'orderby'    => 'comment_date_gmt',
		'order'      => 'ASC',
		'meta_key'   => '_pf_reply_public',
		'meta_value' => '1',
	] );
	if ( empty( $replies ) ) {
		return null;
	}
	return [
		'text' => (string) $replies[0]->comment_content,
		'date' => get_comment_date( 'j F Y', $replies[0] ),
	];
}

// Boucle de rendu des blockquotes + bouton « Voir tout » (sans wrapper de section).
function passiflore_render_avis_list( array $entries ) {
	$seuil = 3;
	foreach ( $entries as $i => $avis ) {
		$hidden = $i >= $seuil ? ' bs-item--hidden' : '';
		echo '<blockquote class="pf-quote bs-avis-item' . $hidden . '">';
		if ( $avis['titre'] !== '' ) {
			echo '<h4 class="bs-avis-titre">' . esc_html( $avis['titre'] ) . '</h4>';
		}
		if ( $avis['text'] !== '' ) {
			echo '<p class="bs-avis-text">' . nl2br( esc_html( $avis['text'] ) ) . '</p>';
		}
		echo '<footer class="bs-avis-footer">';
		if ( $avis['auteur'] !== '' ) echo esc_html( $avis['auteur'] );
		echo '</footer>';
		if ( ! empty( $avis['reply'] ) ) {
			echo '<div class="pf-avis-reponse"><strong>Réponse de l\'éditeur</strong>';
			echo '<p class="pf-avis-reponse__text">' . nl2br( esc_html( $avis['reply']['text'] ) ) . '</p>';
			echo '</div>';
		}
		echo '</blockquote>';
	}
	if ( count( $entries ) > $seuil ) {
		echo '<button class="bs-voir-tout pf-btn pf-btn--neutral pf-btn--sm" type="button">Voir tout (' . ( count( $entries ) - $seuil ) . ')</button>';
	}
}

// Bloc d'avis autonome (wrapper + liste). Utilisé pour les avis des libraires.
function passiflore_render_avis_items( array $entries, string $section ) {
	if ( ! $entries ) return;
	echo '<div class="bs-tab-avis"><div class="bs-avis-section" data-section="' . esc_attr( $section ) . '">';
	passiflore_render_avis_list( $entries );
	echo '</div></div>';
}


/* ─── Avis des lecteurs (sélection éditeur + avis du site, fusionnés) + formulaire ──── */

function passiflore_render_avis_lecteurs( int $product_id, array $entries ) {
	$error = passiflore_avis_error_data();

	$pending = passiflore_get_pending_avis( $product_id );

	if ( ! empty( $error['msg'] ) ) {
		echo '<p class="pf-notice pf-notice--error bs-avis-erreur" role="alert">' . esc_html( $error['msg'] ) . '</p>';
	}

	if ( $pending || $entries ) {
		echo '<div class="bs-tab-avis"><div class="bs-avis-section" data-section="lecteurs">';

		if ( $pending ) {
			echo '<blockquote class="bs-avis-item bs-avis-item--pending" role="status">';
			if ( $pending->comment_content !== '' ) {
				echo '<p class="bs-avis-text">' . nl2br( esc_html( $pending->comment_content ) ) . '</p>';
			}
			echo '<footer class="bs-avis-footer">' . esc_html( $pending->comment_author ) . '</footer>';
			echo '<p class="bs-avis-pending-notice">En attente de validation par l\'éditeur.</p>';
			echo '</blockquote>';
		}

		if ( $entries ) {
			passiflore_render_avis_list( $entries );
		}

		echo '</div></div>';
	}

	// Formulaire de dépôt — ouvert à tous (nom), modéré avant publication
	if ( ! comments_open( $product_id ) ) return;

	$prefill_comment = $error['comment'] ?? '';

	if ( is_user_logged_in() ) {
		$prefill_author = $error['author'] ?? wp_get_current_user()->display_name;
	} else {
		$commenter      = wp_get_current_commenter();
		$prefill_author = $error['author'] ?? $commenter['comment_author'];
	}

	// Anti-spam : honeypot (champ caché) + piège temporel (timestamp signé, vérifié dans passiflore_avis_spam_check)
	$ts    = time();
	$traps = '<p class="pf-hp" aria-hidden="true" style="position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden">'
		. '<label for="pf_hp">Ne remplissez pas ce champ</label>'
		. '<input type="text" id="pf_hp" name="pf_hp" value="" tabindex="-1" autocomplete="off"></p>'
		. '<input type="hidden" name="pf_ts" value="' . esc_attr( $ts . ':' . hash_hmac( 'sha256', (string) $ts, wp_salt( 'auth' ) ) ) . '">';

	$disabled = is_user_logged_in() ? ' disabled' : '';

	// Ordre fixe : textarea → Nom (disabled si connecté) → note → bouton.
	// fields/logged_in_as vidés : tout passe par comment_field, rendu quel que soit l'état de connexion.
	$form_args = [
		'fields'               => [],
		'logged_in_as'         => '',
		'comment_field'        =>
			'<p class="comment-form-comment"><label for="comment">Votre avis <span class="required">*</span></label>'
			. '<textarea id="comment" name="comment" rows="5" required>' . esc_textarea( $prefill_comment ) . '</textarea></p>'
			. $traps
			. '<p class="comment-form-author"><label for="author">Nom <span class="required">*</span></label>'
			. '<input id="author" name="author" type="text" value="' . esc_attr( $prefill_author ) . '" maxlength="245" required aria-required="true"' . $disabled . '></p>'
			. '<p class="comment-notes-after">Votre avis sera publié après validation par l\'éditeur.</p>',
		'title_reply'          => '',
		'title_reply_to'       => '',
		'label_submit'         => 'Publier mon avis',
		'comment_notes_before' => '',
		'comment_notes_after'  => '',
	];

	echo '<div class="bs-avis-form">';
	add_filter( 'comment_form_fields', 'passiflore_avis_remove_cookies', 99 );
	comment_form( $form_args, $product_id );
	remove_filter( 'comment_form_fields', 'passiflore_avis_remove_cookies', 99 );
	echo '</div>';
}

// Retire la case de consentement aux cookies (réinjectée d'office par le core) du formulaire d'avis
function passiflore_avis_remove_cookies( $fields ) {
	unset( $fields['cookies'] );
	return $fields;
}

// Retourne le commentaire en attente si les query params de modération du core sont valides, sinon null.
function passiflore_get_pending_avis( int $product_id ): ?WP_Comment {
	if ( empty( $_GET['unapproved'] ) || empty( $_GET['moderation-hash'] ) ) {
		return null;
	}
	$pending = get_comment( (int) $_GET['unapproved'] );
	if ( ! $pending
		|| (int) $pending->comment_post_ID !== $product_id
		|| ! hash_equals( wp_hash( $pending->comment_date_gmt ), (string) wp_unslash( $_GET['moderation-hash'] ) ) ) {
		return null;
	}
	return $pending;
}

// Données d'un échec de soumission (motif + saisie conservée), à usage unique via transient
function passiflore_avis_error_data(): array {
	if ( empty( $_GET['avis_erreur'] ) ) {
		return [];
	}
	$token = (string) wp_unslash( $_GET['avis_erreur'] );
	if ( ! ctype_alnum( $token ) ) {
		return [];
	}
	$data = get_transient( 'pf_avis_err_' . $token );
	if ( ! is_array( $data ) ) {
		return [];
	}
	delete_transient( 'pf_avis_err_' . $token );
	return $data;
}


/* ─── JS inline : toggle « Voir tout » des sous-listes ────────── */
/* Nav sticky + scrollspy + neutralisation du scroll d'ancre Kadence : désormais
 * dans le composant partagé (assets/js/section-nav.js + inc/section-nav.php, qui
 * pose aussi body.no-anchor-scroll). Ne reste ici que le toggle « Voir tout ». */

add_action( 'wp_footer', 'passiflore_book_tabs_inline_js' );

function passiflore_book_tabs_inline_js() {
	if ( ! is_product() ) return;
	?>
	<script>
	(function () {
		// "Voir tout" / "Voir les n premiers" — toggle des items supplémentaires
		document.querySelectorAll('.bs-voir-tout').forEach(function (btn) {
			var section = btn.closest('.bs-avis-section, .pf-section');
			if (!section) return;

			var extraItems = Array.from(section.querySelectorAll('.bs-item--hidden'));
			if (!extraItems.length) return;

			btn.addEventListener('click', function () {
				var expanded = btn.dataset.expanded === '1';
				if (!expanded) {
					extraItems.forEach(function (el) { el.classList.remove('bs-item--hidden'); });
					btn.textContent = 'Masquer (' + extraItems.length + ')';
					btn.dataset.expanded = '1';
				} else {
					extraItems.forEach(function (el) { el.classList.add('bs-item--hidden'); });
					btn.textContent = 'Voir tout (' + extraItems.length + ')';
					btn.dataset.expanded = '0';
				}
			});
		});

		// Résumé (hero) : « Lire la suite » n'est révélé que si le texte
		// clampé (CSS -webkit-line-clamp) déborde réellement — line-clamp
		// ne permet pas de détecter ça en CSS pur.
		var resumeText = document.querySelector('.bs-hero__resume-text');
		var resumeMore = document.querySelector('.bs-hero__resume-more');
		if (resumeText && resumeMore) {
			var syncResumeMore = function () {
				resumeMore.hidden = resumeText.scrollHeight <= resumeText.clientHeight + 1;
			};
			syncResumeMore();
			window.addEventListener('resize', syncResumeMore);
		}
	})();
	</script>
	<?php
}
