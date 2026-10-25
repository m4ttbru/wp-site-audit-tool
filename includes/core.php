<?php
/**
 * Core helpers: settings, caching, source capture, detection and formatting.
 *
 * @package WP_Site_Audit_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tunable thresholds — override via add_filter() in a theme or mu-plugin.
 *
 * Read lazily rather than baked into constants when the plugin loads: plugins
 * load before the theme, so a filter in functions.php would otherwise never apply.
 *
 * wp_audit_inline_style_limit   Flag posts whose hand-written inline-style count meets or exceeds this.
 * wp_audit_content_scan_limit   Max published posts (pages first, then newest) read by the content scan.
 * wp_audit_page_inventory_limit Max pages listed in the page inventory.
 */
function wp_audit_setting( $name ) {
	$defaults = array(
		'inline_style_limit'   => 15,
		'content_scan_limit'   => 2000,
		'page_inventory_limit' => 1000,
	);
	return (int) apply_filters( 'wp_audit_' . $name, $defaults[ $name ] );
}

/* ==========================================================================
   Caching
   ========================================================================== */

/**
 * Build a transient key that includes the current cache "generation".
 *
 * The Refresh button bumps the generation, which invalidates every cached
 * result at once. That also works with a persistent object cache, where
 * transients never touch wp_options and can't be bulk-deleted by LIKE query.
 */
function wp_audit_cache_key( $key ) {
	return 'wp_audit_' . absint( get_option( 'wp_audit_cache_version', 1 ) ) . '_' . $key;
}

/**
 * Return a cached value, computing and storing it on a miss.
 *
 * Results carrying an 'error' key are cached for $error_ttl instead of $ttl,
 * so a failing remote check (rate limit, unreachable host) doesn't sit through
 * its full timeout again on every page load.
 */
function wp_audit_remember( $key, $ttl, $callback, $error_ttl = 15 * MINUTE_IN_SECONDS ) {
	$cached = wp_audit_cache_peek( $key );
	if ( null !== $cached ) {
		return $cached['value'];
	}

	$value    = call_user_func( $callback );
	$is_error = is_array( $value ) && ! empty( $value['error'] );
	set_transient( wp_audit_cache_key( $key ), array( 'value' => $value ), $is_error ? $error_ttl : $ttl );

	return $value;
}

/**
 * Read a cached entry without computing it. Returns [ 'value' => mixed ] or null on a miss.
 *
 * Values are wrapped so legitimately falsy results (0, null) still count as a hit.
 */
function wp_audit_cache_peek( $key ) {
	$cached = get_transient( wp_audit_cache_key( $key ) );
	return ( is_array( $cached ) && array_key_exists( 'value', $cached ) ) ? $cached : null;
}

/**
 * Invalidate every cached audit result.
 */
function wp_audit_clear_cache() {
	update_option( 'wp_audit_cache_version', absint( get_option( 'wp_audit_cache_version', 1 ) ) + 1, false );
}

/* ==========================================================================
   Post type / taxonomy source detection
   ========================================================================== */

/**
 * Whether this request will build the audit report (admin screen, our AJAX/export
 * actions, or `wp site-audit` on the command line).
 */
function wp_audit_is_report_request() {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return in_array( 'site-audit', isset( $GLOBALS['argv'] ) ? (array) $GLOBALS['argv'] : array(), true );
	}
	if ( ! is_admin() ) {
		return false;
	}

	// phpcs:disable WordPress.Security.NonceVerification -- read-only routing checks; handlers verify nonces.
	if ( isset( $_GET['page'] ) && WP_AUDIT_PAGE_SLUG === $_GET['page'] ) {
		return true;
	}
	return isset( $_REQUEST['action'] ) && 0 === strpos( (string) $_REQUEST['action'], 'wp_audit_' );
	// phpcs:enable
}

/**
 * Remember which file registered a post type or taxonomy.
 *
 * The call stack at registration time names the exact file, which replaces
 * reading every PHP file under wp-content/plugins once per post type. Only
 * hooked when the report is being built, so other requests pay nothing.
 */
function wp_audit_record_source( $kind, $name ) {
	global $wp_audit_registered_sources;

	$abspath   = untrailingslashit( wp_normalize_path( ABSPATH ) );
	$own_dir   = wp_normalize_path( WP_AUDIT_DIR );
	$core_dirs = array(
		wp_normalize_path( ABSPATH . WPINC ) . '/',
		wp_normalize_path( ABSPATH . 'wp-admin' ) . '/',
		$own_dir,
	);

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- intentional, see docblock.
	foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
		if ( empty( $frame['file'] ) ) {
			continue;
		}

		// Skip core and this plugin: the first frame left is the code that called register_*().
		$file = wp_normalize_path( $frame['file'] );
		if ( dirname( $file ) === $abspath ) {
			continue;
		}
		foreach ( $core_dirs as $dir ) {
			if ( 0 === strpos( $file, $dir ) ) {
				continue 2;
			}
		}

		$wp_audit_registered_sources[ $kind ][ $name ] = $file;
		return;
	}
}

/**
 * Describe where a post type or taxonomy came from.
 *
 * Returns [ 'type' => core|plugin|mu-plugin|theme|custom|unknown, 'label' => string, 'location' => string ].
 */
function wp_audit_get_source( $kind, $name, $is_builtin ) {
	global $wp_audit_registered_sources;

	if ( $is_builtin ) {
		return array( 'type' => 'core', 'label' => 'WordPress Core', 'location' => '' );
	}

	if ( empty( $wp_audit_registered_sources[ $kind ][ $name ] ) ) {
		// Registered before this plugin loaded, e.g. at include time by an earlier plugin or an mu-plugin.
		return array( 'type' => 'unknown', 'label' => 'Unknown (registered before audit loaded)', 'location' => '' );
	}

	$file         = $wp_audit_registered_sources[ $kind ][ $name ];
	$plugins_dir  = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
	$mu_dir       = wp_normalize_path( WPMU_PLUGIN_DIR ) . '/';
	$theme_dir    = wp_normalize_path( get_stylesheet_directory() ) . '/';
	$template_dir = wp_normalize_path( get_template_directory() ) . '/';

	if ( 0 === strpos( $file, $plugins_dir ) ) {
		$relative = substr( $file, strlen( $plugins_dir ) );
		$slug     = strtok( $relative, '/' );
		foreach ( wp_audit_get_plugins() as $plugin_file => $data ) {
			if ( $plugin_file === $relative || dirname( $plugin_file ) === $slug ) {
				return array( 'type' => 'plugin', 'label' => 'Plugin: ' . $data['Name'], 'location' => $relative );
			}
		}
		return array( 'type' => 'plugin', 'label' => 'Plugin: ' . $slug, 'location' => $relative );
	}

	if ( 0 === strpos( $file, $mu_dir ) ) {
		$relative = substr( $file, strlen( $mu_dir ) );
		return array( 'type' => 'mu-plugin', 'label' => 'MU Plugin: ' . strtok( $relative, '/' ), 'location' => 'mu-plugins/' . $relative );
	}

	if ( 0 === strpos( $file, $theme_dir ) ) {
		return array( 'type' => 'theme', 'label' => 'Theme: ' . wp_get_theme()->get( 'Name' ), 'location' => 'active-theme/' . substr( $file, strlen( $theme_dir ) ) );
	}

	if ( 0 === strpos( $file, $template_dir ) ) {
		return array( 'type' => 'theme', 'label' => 'Parent Theme: ' . wp_get_theme( get_template() )->get( 'Name' ), 'location' => 'parent-theme/' . substr( $file, strlen( $template_dir ) ) );
	}

	return array( 'type' => 'custom', 'label' => 'Custom: ' . basename( $file ), 'location' => str_replace( wp_normalize_path( ABSPATH ), '', $file ) );
}

/* ==========================================================================
   Detection helpers
   ========================================================================== */

/**
 * get_plugins() with its admin include loaded. Core caches the result per request.
 */
function wp_audit_get_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return get_plugins();
}

/**
 * Return the label of the first detected integration, or '' if none.
 *
 * Callers pass each plugin's own class/constant check rather than keyword-matching
 * plugin names, which misfired ("map" matched "XML Sitemaps", "event" matched GA event tracking).
 */
function wp_audit_first_detected( array $checks ) {
	foreach ( $checks as $label => $detected ) {
		if ( $detected ) {
			return $label;
		}
	}
	return '';
}

/**
 * Labels of every detected integration in $checks.
 */
function wp_audit_all_detected( array $checks ) {
	return array_keys( array_filter( $checks ) );
}

/**
 * Name of the first active plugin whose name matches $pattern, or ''.
 * Fallback for plugin families too varied to list by class/constant.
 */
function wp_audit_find_active_plugin_by_name( array $active_plugins, array $all_plugins, $pattern ) {
	foreach ( $active_plugins as $plugin ) {
		if ( isset( $all_plugins[ $plugin ]['Name'] ) && preg_match( $pattern, $all_plugins[ $plugin ]['Name'] ) ) {
			return $all_plugins[ $plugin ]['Name'];
		}
	}
	return '';
}

/**
 * Whether this site can't be reached from the public internet (Local, .test, etc.).
 * PageSpeed Insights can't fetch these, so there's no point spending an API call.
 */
function wp_audit_is_local_site() {
	if ( 'local' === wp_get_environment_type() ) {
		return true;
	}
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	return 'localhost' === $host || (bool) preg_match( '/\.(local|test|localhost)$/i', $host );
}

/**
 * Whether a database table exists. Cached per request.
 */
function wp_audit_table_exists( $table ) {
	static $known = array();
	global $wpdb;

	if ( ! isset( $known[ $table ] ) ) {
		$known[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
	return $known[ $table ];
}

/**
 * Hosting provider, from constants first and response headers as a fallback.
 */
function wp_audit_detect_hosting( array $headers = array() ) {
	$by_constant = wp_audit_first_detected(
		array(
			'WP Engine'                 => defined( 'WPE_APIKEY' ),
			'Flywheel'                  => defined( 'FLYWHEEL_CONFIG_DIR' ),
			'Kinsta'                    => defined( 'KINSTA_CACHE_ZONE' ),
			'Pressable'                 => defined( 'IS_PRESSABLE' ),
			'Pantheon'                  => (bool) getenv( 'PANTHEON_ENVIRONMENT' ),
			'WordPress VIP'             => defined( 'WPCOM_IS_VIP_ENV' ),
			'SiteGround'                => defined( 'SiteGround_Optimizer\VERSION' ),
			'GoDaddy Managed WordPress' => defined( 'GD_SYSTEM_PLUGIN_DIR' ),
		)
	);
	if ( $by_constant ) {
		return $by_constant;
	}

	$powered = strtolower( isset( $headers['x-powered-by'] ) ? $headers['x-powered-by'] : '' );
	$server  = strtolower( isset( $headers['server'] ) ? $headers['server'] : ( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '' ) );

	if ( false !== strpos( $powered, 'wp engine' ) ) {
		return 'WP Engine';
	}
	if ( isset( $headers['x-kinsta-cache'] ) ) {
		return 'Kinsta';
	}
	if ( false !== strpos( $server, 'litespeed' ) ) {
		return 'Unknown (LiteSpeed server, often shared hosting)';
	}
	return 'Unknown';
}

/* ==========================================================================
   Formatting
   ========================================================================== */

/**
 * Decode HTML entities. Titles from WordPress arrive as "Home &#8211; Site",
 * which would print literally in Markdown and double-encode in HTML.
 */
function wp_audit_plain( $text ) {
	return html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * esc_html() for report text, decoding entities first.
 */
function wp_audit_esc( $text ) {
	return esc_html( wp_audit_plain( $text ) );
}

/**
 * Locale-formatted integer.
 */
function wp_audit_num( $number ) {
	return number_format_i18n( (float) $number );
}

/**
 * Human-readable byte size.
 */
function wp_audit_bytes( $bytes ) {
	return $bytes ? size_format( (float) $bytes, 1 ) : '0 B';
}

/**
 * 'Yes' / 'No'.
 */
function wp_audit_yes_no( $value ) {
	return $value ? 'Yes' : 'No';
}

/**
 * Shorten plain text to $length characters on a word boundary.
 */
function wp_audit_excerpt( $text, $length = 160 ) {
	$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
	if ( strlen( $text ) <= $length ) {
		return $text;
	}
	return rtrim( substr( $text, 0, strrpos( substr( $text, 0, $length ), ' ' ) ?: $length ), ' ,.;:' ) . '…';
}

/* ==========================================================================
   Report model builders
   ========================================================================== */

/**
 * A key/value fact. $status is good|warning|bad|'' and drives color in HTML
 * and [WARN]/[ISSUE] markers in Markdown.
 */
function wp_audit_fact( $label, $value, $status = '', $note = '' ) {
	return array(
		'label'  => $label,
		'value'  => (string) $value,
		'status' => $status,
		'note'   => $note,
	);
}

/**
 * A table cell carrying a status and/or an admin link (links are HTML-only).
 */
function wp_audit_cell( $value, $status = '', $link = '' ) {
	return array(
		'value'  => (string) $value,
		'status' => $status,
		'link'   => $link,
	);
}

/**
 * A finding for the Key Findings summary. $severity is bad|warning|info.
 */
function wp_audit_finding( $severity, $text ) {
	return array(
		'severity' => $severity,
		'text'     => $text,
	);
}
