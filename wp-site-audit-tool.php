<?php
/**
 * Plugin Name:       WordPress Site Audit Tool
 * Description:       Exhaustive site inventory under Tools > Site Audit, exportable as LLM-ready Markdown for redesign scoping, RFQs and technical-debt review.
 * Version:           2.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Matt Brubaker
 * Author URI:        https://mattbru.me
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-site-audit
 *
 * @package WP_Site_Audit_Tool
 */

// Don't allow direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_AUDIT_VERSION', '2.0' );
define( 'WP_AUDIT_PAGE_SLUG', 'wp-site-audit-report' );
define( 'WP_AUDIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_AUDIT_URL', plugin_dir_url( __FILE__ ) );

require_once WP_AUDIT_DIR . 'includes/core.php';

// Source capture must hook in as early as possible, before other code registers
// its post types on init. Everything else only loads when it can be used.
if ( wp_audit_is_report_request() ) {
	add_action(
		'registered_post_type',
		function ( $post_type ) {
			wp_audit_record_source( 'post_type', $post_type );
		}
	);
	add_action(
		'registered_taxonomy',
		function ( $taxonomy ) {
			wp_audit_record_source( 'taxonomy', $taxonomy );
		}
	);
}

if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require_once WP_AUDIT_DIR . 'includes/checks.php';
	require_once WP_AUDIT_DIR . 'includes/collectors.php';
	require_once WP_AUDIT_DIR . 'includes/sections.php';
	require_once WP_AUDIT_DIR . 'includes/render-markdown.php';
}

if ( is_admin() ) {
	require_once WP_AUDIT_DIR . 'includes/render-html.php';
	require_once WP_AUDIT_DIR . 'includes/admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WP_AUDIT_DIR . 'includes/cli.php';
}
