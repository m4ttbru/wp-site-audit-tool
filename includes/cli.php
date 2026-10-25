<?php
/**
 * WP-CLI command: `wp site-audit report`.
 *
 * Lets an audit run on a remote host (SSH, or a host API that runs WP-CLI)
 * without opening wp-admin, and pipes straight into a file for an LLM.
 *
 * @package WP_Site_Audit_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate WordPress site audit reports.
 */
class WP_Audit_CLI_Command {

	/**
	 * Generate the full site audit report.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: markdown
	 * options:
	 *   - markdown
	 *   - json
	 * ---
	 *
	 * [--output=<file>]
	 * : Write the report to a file instead of STDOUT.
	 *
	 * [--pagespeed]
	 * : Fetch Google PageSpeed Insights now (adds 20–60 seconds). Without it, only cached results are included.
	 *
	 * [--refresh]
	 * : Clear cached audit data first so every check re-runs.
	 *
	 * ## EXAMPLES
	 *
	 *     # Markdown report to a file, for pasting into an LLM
	 *     $ wp site-audit report --output=audit.md
	 *
	 *     # Fresh JSON including PageSpeed
	 *     $ wp site-audit report --format=json --refresh --pagespeed > audit.json
	 *
	 * @when after_wp_load
	 */
	public function report( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['refresh'] ) ) {
			wp_audit_clear_cache();
		}

		if ( ! empty( $assoc_args['pagespeed'] ) ) {
			foreach ( array( 'mobile', 'desktop' ) as $strategy ) {
				WP_CLI::log( "Fetching PageSpeed Insights ({$strategy})…" );
				$result = wp_audit_get_pagespeed_score( $strategy );
				if ( ! empty( $result['error'] ) ) {
					WP_CLI::warning( "PageSpeed {$strategy}: " . $result['message'] );
				}
			}
		}

		$report = wp_audit_build_report();
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'markdown';
		$output = 'json' === $format ? wp_audit_render_json( $report ) : wp_audit_render_markdown( $report );

		if ( ! empty( $assoc_args['output'] ) ) {
			if ( false === file_put_contents( $assoc_args['output'], $output ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- CLI writes a local file.
				WP_CLI::error( 'Could not write ' . $assoc_args['output'] );
			}
			WP_CLI::success( sprintf( 'Wrote %s (%s, %d findings).', $assoc_args['output'], size_format( strlen( $output ) ), count( $report['findings'] ) ) );
			return;
		}

		WP_CLI::line( $output );
	}
}

WP_CLI::add_command( 'site-audit', 'WP_Audit_CLI_Command' );
