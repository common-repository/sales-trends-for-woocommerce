<?php
/**
 * Plugin Name: Sales Trends for WooCommerce
 * Description: View sales trend indicators for individual items in the WooCommerce Products list.
 * Version: 1.0.1
 * Author: Potent Plugins
 * Author URI: http://potentplugins.com/?utm_source=sales-trends-for-woocommerce&utm_medium=link&utm_campaign=wp-plugin-author-uri
 * License: GNU General Public License version 2 or later
 * License URI: http://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html
 */

add_filter('plugin_action_links_'.plugin_basename(__FILE__), 'hm_wcst_action_links');
function hm_wcst_action_links($links) {
	array_unshift($links, '<a href="'.esc_url(get_admin_url(null, 'admin.php?page=hm_wcst')).'">Settings</a>');
	return $links;
}
 
add_filter('manage_product_posts_columns', 'hm_wcst_product_columns');
function hm_wcst_product_columns($cols) {
	$cols['hm_wcst_product'] = 'Sales Trend';
	return $cols;
}

add_filter('manage_edit-product_sortable_columns', 'hm_wcst_product_sortable_columns');
function hm_wcst_product_sortable_columns($cols) {
	$cols['hm_wcst_product'] = 'hm_wcst_product';
	return $cols;
}

add_action('load-edit.php', 'hm_wcst_edit_load');
function hm_wcst_edit_load() {
	add_filter('request', 'hm_wcst_request_filter');
}

function hm_wcst_request_filter($queryVars) {
	if (isset($queryVars['post_type']) && $queryVars['post_type'] == 'product' && isset($queryVars['orderby']) && $queryVars['orderby'] == 'hm_wcst_product') {
		$queryVars['meta_key'] = 'hm_wcst_trend';
		$queryVars['orderby'] = 'meta_value_num';
	}
	return $queryVars;
}

add_action('manage_product_posts_custom_column', 'hm_wcst_column', 10, 2);
function hm_wcst_column($col, $postId) {
	if ($col == 'hm_wcst_product') {
		/*
		$currentSales = get_post_meta($postId, 'hm_wcst_sales_current', true);
		$previousSales = get_post_meta($postId, 'hm_wcst_sales_previous', true);
		echo("$currentSales/$previousSales");
		*/
		
		$now = current_time('timestamp');
		$trend = get_post_meta($postId, 'hm_wcst_trend', true);
		echo('<a href="admin.php?page=wc-reports&amp;tab=orders&amp;report=sales_by_product&amp;product_ids='.$postId.'&amp;range=custom&amp;start_date='.date('Y-m-d', get_option('hm_wcst_previous_period_start', $now - (86400 * 14))).'&amp;end_date='.date('Y-m-d', get_option('hm_wcst_previous_period_end', $now)).'" target="_blank" class="wcst-trend wcst-trend-');
		if ($trend == 99999999) {
			echo('up">&infin;</a>');
		} else if ($trend > 0) {
			echo('up">'.round($trend).'%</a>');
		} else if ($trend < 0) {
			echo('down">'.round($trend*-1).'%</a>');
		} else {
			echo('none"></a>');
		}
	}
}

add_action('admin_enqueue_scripts', 'hm_wcst_admin_enqueue_scripts');
function hm_wcst_admin_enqueue_scripts() {
	wp_enqueue_style('hm_wcst', plugins_url('css/sales-trends-for-woocommerce.css', __FILE__));
}

add_action('hm_wcst_calculate', 'hm_wcst_calculate');
function hm_wcst_calculate() {
	
	$wp_query = new WP_Query(array(
		'post_type' => 'product',
		'fields' => 'ids',
		'nopaging' => true
	));
	$productIds = $wp_query->get_posts();
	
	$periodDays = get_option('hm_wcst_period_days', 7);
	$periodEnd = current_time('timestamp');
	$periodStart = $periodEnd - ($periodDays * 86400);
	update_option('hm_wcst_current_period_start', $periodStart);
	update_option('hm_wcst_current_period_end', $periodEnd);
	$currentSales = hm_wcst_get_product_sales($productIds, 'hm_wcst_sales_current', $periodStart, $periodEnd);
	
	$periodEnd = $periodStart - 1;
	$periodStart = $periodEnd - ($periodDays * 86400);
	update_option('hm_wcst_previous_period_start', $periodStart);
	update_option('hm_wcst_previous_period_end', $periodEnd);
	$previousSales = hm_wcst_get_product_sales($productIds, 'hm_wcst_sales_previous', $periodStart, $periodEnd);
	
	foreach ($productIds as $productId) {
		
		if ($currentSales[$productId] > $previousSales[$productId]) {
			if ($previousSales[$productId] > 0) {
				update_post_meta($productId, 'hm_wcst_trend', (($currentSales[$productId] - $previousSales[$productId]) / $previousSales[$productId]) * 100);
			} else {
				update_post_meta($productId, 'hm_wcst_trend', 99999999);
			}
		} else if ($currentSales[$productId] < $previousSales[$productId]) {
			update_post_meta($productId, 'hm_wcst_trend', (($previousSales[$productId] - $currentSales[$productId]) / $previousSales[$productId]) * -100);
		} else {
			update_post_meta($productId, 'hm_wcst_trend', 0);
		}
	}
	
	update_option('hm_wcst_last_update', current_time('timestamp'));
}

function hm_wcst_get_product_sales($productIds, $metaKey, $periodStart, $periodEnd) {
	global $woocommerce;
	
	$salesQuantities = array_combine($productIds, array_fill(0, count($productIds), 0));
	
	include_once($woocommerce->plugin_path().'/includes/admin/reports/class-wc-admin-report.php');
	$wc_report = new WC_Admin_Report();
	$wc_report->start_date = $periodStart;
	$wc_report->end_date = $periodEnd;

	// Based on woocoommerce/includes/admin/reports/class-wc-report-sales-by-product.php
	$soldProducts = $wc_report->get_order_report_data(array(
		'data' => array(
			'_product_id' => array(
				'type' => 'order_item_meta',
				'order_item_type' => 'line_item',
				'function' => '',
				'name' => 'product_id'
			),
			'_qty' => array(
				'type' => 'order_item_meta',
				'order_item_type' => 'line_item',
				'function' => 'SUM',
				'name' => 'quantity'
			),
		),
		'query_type' => 'get_results',
		'group_by' => 'product_id',
		'limit' => '',
		'filter_range' => true,
		'order_types' => wc_get_order_types('order_count')
	));
	
	foreach ($soldProducts as $product) {
		if (isset($salesQuantities[$product->product_id])) {
			$salesQuantities[$product->product_id] = $product->quantity;
		}
	}
	
	/*
	foreach ($salesQuantities as $productId => $quantity) {
		update_post_meta($productId, $metaKey, $quantity);
	}
	*/
	
	return $salesQuantities;
	
}


add_action('admin_menu', 'hm_wcst_admin_menu');
function hm_wcst_admin_menu() {
	add_submenu_page('woocommerce', 'Sales Trends', 'Sales Trends', 'manage_woocommerce', 'hm_wcst', 'hm_wcst_page');
}


function hm_wcst_page() {
	
	// Print header
	echo('
		<div class="wrap">
			<h2>Sales Trends</h2>
	');
	
	// Check for WooCommerce
	if (!class_exists('WooCommerce')) {
		echo('<div class="error"><p>This plugin requires that WooCommerce is installed and activated.</p></div></div>');
		return;
	} else if (!function_exists('wc_get_order_types')) {
		echo('<div class="error"><p>The Sales Trends plugin requires WooCommerce 2.2 or higher. Please update your WooCommerce install.</p></div></div>');
		return;
	}
	
	if (isset($_POST['hm_wcst_period_days']) && is_numeric($_POST['hm_wcst_period_days']) && $_POST['hm_wcst_period_days'] > 0 && check_admin_referer('hm_wcst_save_settings')) {
		update_option('hm_wcst_period_days', $_POST['hm_wcst_period_days']);
		hm_wcst_calculate();
	}
	
	$lastUpdated = get_option('hm_wcst_last_update');
	echo('<form action="" method="post">');
	wp_nonce_field('hm_wcst_save_settings');
	echo('
		<table class="form-table">
			<tr valign="top">
				<th scope="row">
					<label for="hm_wcst_field_period">Trend Period:</label>
				</th>
				<td>
					<input name="hm_wcst_period_days" type="number" min="0" step="1" value="'.get_option('hm_wcst_period_days', 7).'" /> days
					<p class="description">Example: If you enter 7 days, the plugin will compare the last week\'s sales quantities with the sales quantities in the week before.</p>
				</td>
			</tr>
		</table>
		<button type="submit" class="button-primary">Save &amp; Recalculate</button>
		</form>
		<p style="margin-bottom: 30px;"><strong>Trends last updated:</strong> '.(empty($lastUpdated) ? 'Never' : date('F j, Y g:i:s A', $lastUpdated)).'</p>
	');
	$potent_slug = 'sales-trends-for-woocommerce';
	include(__DIR__.'/plugin-credit.php');
}

register_activation_hook(__FILE__, 'hm_wcst_activate');
function hm_wcst_activate() {
	// Schedule calculation
	wp_schedule_event(time(), 'daily', 'hm_wcst_calculate');
	hm_wcst_calculate();
}

register_deactivation_hook(__FILE__, 'hm_wcst_deactivate');
function hm_wcst_deactivate() {
	// Unschedule calculation
	wp_clear_scheduled_hook('hm_wcst_calculate');
}
?>