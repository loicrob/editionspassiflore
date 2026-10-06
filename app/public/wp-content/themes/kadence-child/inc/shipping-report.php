<?php
/**
 * Analytique → Frais de port : ventilation du port facturé sur une période, une
 * ligne par (mode de livraison, tarif TTC réellement facturé), + export CSV.
 *
 * Complète Analytique → Revenus, qui ne donne que le total du port. Pour que
 * les totaux concordent avec ce rapport, la sélection reprend exactement ses
 * règles : table wc_order_stats, colonne de date du réglage « Type de date »
 * (woocommerce_date_type, défaut date_paid), statuts exclus du réglage
 * (+ filtre woocommerce_analytics_excluded_order_statuses, qui écarte aussi
 * les brouillons de commande). Les remboursements y figurent comme des lignes
 * à tarif négatif, comme Analytics les déduit.
 *
 * ⚠️ wc_order_stats est alimentée en tâche de fond (Action Scheduler) : une
 * commande toute récente peut manquer quelques minutes, comme dans Analytics.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Rangé dans Analytique, juste après « Produits ». Le filtre natif
 * woocommerce_analytics_report_menu_items ne sait créer que des pages React :
 * on greffe donc une sous-page PHP classique sur le menu Analytique (enregistré
 * sur admin_menu @10 par Internal\Admin\Analytics), puis on la replace dans
 * $submenu.
 */
add_action( 'admin_menu', function () {
	$parent = 'wc-admin&path=/analytics/overview';
	$hook   = add_submenu_page(
		$parent,
		'Frais de port',
		'Frais de port',
		'view_woocommerce_reports',
		'pf-shipping-report',
		'pf_shipping_report_render'
	);
	if ( ! $hook ) {
		return;
	}
	add_action( 'load-' . $hook, 'pf_shipping_report_maybe_export' );

	global $submenu;
	$items = $submenu[ $parent ] ?? [];
	$ours  = array_pop( $items );
	$after = array_search( 'wc-admin&path=/analytics/products', array_column( $items, 2 ), true );
	if ( false !== $after ) {
		array_splice( $items, $after + 1, 0, [ $ours ] );
		$submenu[ $parent ] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}
}, 20 );

/** Période demandée [début, fin] en Y-m-d ; défaut = mois précédent. */
function pf_shipping_report_period() {
	$valid = function ( $key ) {
		$v = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		$d = DateTime::createFromFormat( '!Y-m-d', $v );
		return ( $d && $d->format( 'Y-m-d' ) === $v ) ? $v : '';
	};
	$from = $valid( 'from' );
	$to   = $valid( 'to' );
	if ( ! $from || ! $to ) {
		$prev = new DateTime( 'first day of last month', wp_timezone() );
		$from = $prev->format( 'Y-m-01' );
		$to   = $prev->format( 'Y-m-t' );
	}
	return $from <= $to ? [ $from, $to ] : [ $to, $from ];
}

/**
 * Lignes agrégées : [ ['name', 'tarif' (float TTC), 'nb' (int), 'total' (float)], … ].
 */
function pf_shipping_report_rows( $from, $to ) {
	global $wpdb;

	$col = get_option( 'woocommerce_date_type', 'date_paid' );
	if ( ! in_array( $col, [ 'date_created', 'date_paid', 'date_completed' ], true ) ) {
		$col = 'date_paid';
	}

	// Même liste que DataStore::get_excluded_report_order_statuses() d'Analytics.
	$excluded = (array) get_option( 'woocommerce_excluded_report_order_statuses', [ 'pending', 'failed', 'cancelled' ] );
	$excluded = apply_filters( 'woocommerce_analytics_excluded_order_statuses', array_merge( [ 'auto-draft', 'trash' ], $excluded ) );
	$excluded = array_map( function ( $s ) { return 'wc-' . $s; }, (array) $excluded );
	$in       = implode( ',', array_fill( 0, count( $excluded ), '%s' ) );

	$stats = $wpdb->prefix . 'wc_order_stats';
	$items = $wpdb->prefix . 'woocommerce_order_items';
	$meta  = $wpdb->prefix . 'woocommerce_order_itemmeta';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $col en liste blanche, tables internes.
	$sql = "SELECT name, tarif, COUNT(*) AS nb, SUM(tarif) AS total FROM (
			SELECT oi.order_item_name AS name,
				ROUND( CAST( COALESCE( c.meta_value, 0 ) AS DECIMAL(12,2) ) + CAST( COALESCE( t.meta_value, 0 ) AS DECIMAL(12,2) ), 2 ) AS tarif
			FROM {$stats} s
			JOIN {$items} oi ON oi.order_id = s.order_id AND oi.order_item_type = 'shipping'
			LEFT JOIN {$meta} c ON c.order_item_id = oi.order_item_id AND c.meta_key = 'cost'
			LEFT JOIN {$meta} t ON t.order_item_id = oi.order_item_id AND t.meta_key = 'total_tax'
			WHERE s.`{$col}` >= %s AND s.`{$col}` <= %s
			AND s.status NOT IN ({$in})
		) x
		GROUP BY name, tarif
		ORDER BY name, tarif DESC";

	$params = array_merge( [ $from . ' 00:00:00', $to . ' 23:59:59' ], $excluded );
	$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore

	return array_map( function ( $r ) {
		return [
			'name'  => $r['name'],
			'tarif' => (float) $r['tarif'],
			'nb'    => (int) $r['nb'],
			'total' => (float) $r['total'],
		];
	}, $rows ?: [] );
}

/** Montant au format français (CSV lu par Excel FR). */
function pf_shipping_report_amount( $v ) {
	return number_format( $v, 2, ',', '' );
}

function pf_shipping_report_maybe_export() {
	if ( ! isset( $_GET['pf_export'] ) ) {
		return;
	}
	check_admin_referer( 'pf_shipping_report_export' );

	[ $from, $to ] = pf_shipping_report_period();
	$rows          = pf_shipping_report_rows( $from, $to );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="frais-de-port_' . $from . '_' . $to . '.csv"' );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // BOM : Excel lit sinon les accents en Latin-1.
	fputcsv( $out, [ 'Nombre', 'Mode de livraison', 'Tarif TTC', 'Total TTC' ], ';' );
	$sum = 0;
	foreach ( $rows as $r ) {
		fputcsv( $out, [ $r['nb'], $r['name'], pf_shipping_report_amount( $r['tarif'] ), pf_shipping_report_amount( $r['total'] ) ], ';' );
		$sum += $r['total'];
	}
	fputcsv( $out, [ '', 'Total', '', pf_shipping_report_amount( $sum ) ], ';' );
	fclose( $out );
	exit;
}

function pf_shipping_report_render() {
	[ $from, $to ] = pf_shipping_report_period();
	$rows          = pf_shipping_report_rows( $from, $to );
	$sum           = array_sum( array_column( $rows, 'total' ) );
	$export_url    = wp_nonce_url(
		add_query_arg( [ 'page' => 'pf-shipping-report', 'from' => $from, 'to' => $to, 'pf_export' => 1 ], admin_url( 'admin.php' ) ),
		'pf_shipping_report_export'
	);
	?>
	<div class="wrap">
		<h1>Frais de port</h1>
		<p>Port facturé par mode de livraison et par tarif TTC. Mêmes commandes et même date de référence que <em>Analytique → Revenus</em> (réglages « Statuts exclus » et « Type de date » d'Analytique). Les remboursements de port apparaissent en tarif négatif.</p>

		<form method="get" style="margin: 1em 0;">
			<input type="hidden" name="page" value="pf-shipping-report">
			<label>Du <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>"></label>
			<label>au <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>"></label>
			<?php submit_button( 'Afficher', 'secondary', '', false ); ?>
			<a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>">Exporter en CSV</a>
		</form>

		<table class="widefat striped" style="max-width: 640px;">
			<thead>
				<tr><th>Nombre</th><th>Mode de livraison</th><th style="text-align:right">Tarif TTC</th><th style="text-align:right">Total TTC</th></tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="4">Aucune commande avec livraison sur cette période.</td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo (int) $r['nb']; ?></td>
						<td><?php echo esc_html( $r['name'] ); ?></td>
						<td style="text-align:right"><?php echo wp_kses_post( wc_price( $r['tarif'] ) ); ?></td>
						<td style="text-align:right"><?php echo wp_kses_post( wc_price( $r['total'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr><th colspan="3">Total</th><th style="text-align:right"><?php echo wp_kses_post( wc_price( $sum ) ); ?></th></tr>
			</tfoot>
		</table>
	</div>
	<?php
}
