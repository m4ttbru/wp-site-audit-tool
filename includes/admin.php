<?php
/**
 * Admin wiring: menu page, assets, AJAX endpoints and export/refresh actions.
 *
 * @package WP_Site_Audit_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add the report page under Tools.
 */
function wp_audit_tool_add_admin_page() {
	add_management_page( 'Site Audit Report', 'Site Audit', 'manage_options', WP_AUDIT_PAGE_SLUG, 'wp_audit_tool_render_page' );
}
add_action( 'admin_menu', 'wp_audit_tool_add_admin_page' );

/**
 * Enqueue report assets on the report screen only.
 */
function wp_audit_enqueue_assets( $hook_suffix ) {
	if ( 'tools_page_' . WP_AUDIT_PAGE_SLUG !== $hook_suffix ) {
		return;
	}

	$url = WP_AUDIT_URL . 'assets/';

	wp_enqueue_style( 'wp-audit-admin', $url . 'admin.css', array( 'dashicons' ), WP_AUDIT_VERSION );

	// Chart.js is self-hosted and pinned, so no third-party CDN runs JavaScript inside wp-admin.
	wp_enqueue_script( 'wp-audit-chartjs', $url . 'vendor/chart.umd.min.js', array(), '4.4.0', array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_enqueue_script( 'wp-audit-admin', $url . 'admin.js', array( 'wp-audit-chartjs' ), WP_AUDIT_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
}
add_action( 'admin_enqueue_scripts', 'wp_audit_enqueue_assets' );

/**
 * Stop with a 403 unless the current user may run the audit.
 */
function wp_audit_require_capability() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized', '', array( 'response' => 403 ) );
	}
}

/**
 * AJAX: PageSpeed Insights for one strategy.
 *
 * Loaded after page paint because each API call can take 20–40s; running both
 * strategies inline used to block the whole report (and could hit max_execution_time).
 */
function wp_audit_ajax_pagespeed() {
	wp_audit_require_capability();
	check_ajax_referer( 'wp_audit_pagespeed', 'nonce' );

	$strategy = ( isset( $_POST['strategy'] ) && 'mobile' === $_POST['strategy'] ) ? 'mobile' : 'desktop';
	$result   = wp_audit_get_pagespeed_score( $strategy );

	if ( ! empty( $result['error'] ) ) {
		wp_send_json_error( $result );
	}
	wp_send_json_success( $result );
}
add_action( 'wp_ajax_wp_audit_pagespeed', 'wp_audit_ajax_pagespeed' );

/**
 * AJAX: the Markdown report, for "Copy Markdown for AI".
 */
function wp_audit_ajax_markdown() {
	wp_audit_require_capability();
	check_ajax_referer( 'wp_audit_markdown', 'nonce' );

	wp_send_json_success( array( 'markdown' => wp_audit_render_markdown( wp_audit_build_report() ) ) );
}
add_action( 'wp_ajax_wp_audit_markdown', 'wp_audit_ajax_markdown' );

/**
 * Download the report as .md or .json.
 */
function wp_audit_handle_export() {
	wp_audit_require_capability();
	check_admin_referer( 'wp_audit_export' );

	$format = ( isset( $_GET['format'] ) && 'json' === $_GET['format'] ) ? 'json' : 'md';
	$report = wp_audit_build_report();

	nocache_headers();
	header( 'Content-Type: ' . ( 'json' === $format ? 'application/json' : 'text/markdown' ) . '; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . wp_audit_export_filename( $format ) . '"' );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- file download, not HTML.
	echo 'json' === $format ? wp_audit_render_json( $report ) : wp_audit_render_markdown( $report );
	exit;
}
add_action( 'admin_post_wp_audit_export', 'wp_audit_handle_export' );

/**
 * "Refresh data" handler.
 */
function wp_audit_handle_clear_cache() {
	wp_audit_require_capability();
	check_admin_referer( 'wp_audit_clear_cache' );

	wp_audit_clear_cache();

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'               => WP_AUDIT_PAGE_SLUG,
				'wp_audit_refreshed' => 1,
			),
			admin_url( 'tools.php' )
		)
	);
	exit;
}
add_action( 'admin_post_wp_audit_clear_cache', 'wp_audit_handle_clear_cache' );
