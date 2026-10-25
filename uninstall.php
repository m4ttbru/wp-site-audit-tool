<?php
/**
 * Remove everything the audit tool stores.
 *
 * Cached results are transients whose keys include a "generation" number, so
 * they're deleted by prefix rather than by name. With a persistent object
 * cache they never reach wp_options and simply expire on their own.
 *
 * @package WP_Site_Audit_Tool
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'wp_audit_cache_version' );

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wp_audit_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wp_audit_' ) . '%'
	)
);
