<?php
/**
 * Plugin Name:       WordPress Site Audit Tool
 * Description:       Adds a site audit report under Tools > Site Audit for redesign scoping, RFQ preparation and technical-debt review.
 * Version:           1.8
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Matt Brubaker
 * Author URI:        https://mattbru.me
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-site-audit
 */

// Don't allow direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_AUDIT_VERSION', '1.8' );
define( 'WP_AUDIT_PAGE_SLUG', 'wp-site-audit-report' );

/**
 * Tunable thresholds — override via add_filter() in a theme or mu-plugin.
 *
 * Read lazily rather than baked into constants when this file loads: plugins
 * load before the theme, so a filter in functions.php would otherwise never apply.
 *
 * wp_audit_inline_style_limit       Flag posts whose inline-style count meets or exceeds this.
 * wp_audit_shortcode_post_limit     Max posts to inspect for unregistered shortcodes.
 * wp_audit_inline_style_post_limit  Max posts to inspect for inline-style bloat.
 */
function wp_audit_setting( $name ) {
	$defaults = array(
		'inline_style_limit'      => 15,
		'shortcode_post_limit'    => 100,
		'inline_style_post_limit' => 100,
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
	$cache_key = wp_audit_cache_key( $key );
	$cached    = get_transient( $cache_key );

	// Wrapped in an array so legitimately falsy results (0, null) still count as a cache hit.
	if ( is_array( $cached ) && array_key_exists( 'value', $cached ) ) {
		return $cached['value'];
	}

	$value    = call_user_func( $callback );
	$is_error = is_array( $value ) && ! empty( $value['error'] );
	set_transient( $cache_key, array( 'value' => $value ), $is_error ? $error_ttl : $ttl );

	return $value;
}

/**
 * "Refresh cached data" handler.
 */
function wp_audit_handle_clear_cache() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'wp_audit_clear_cache' );

	update_option( 'wp_audit_cache_version', absint( get_option( 'wp_audit_cache_version', 1 ) ) + 1, false );

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

/* ==========================================================================
   Post type / taxonomy source detection
   ========================================================================== */

/**
 * Whether the current request is the audit report screen.
 */
function wp_audit_is_report_request() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
	return is_admin() && isset( $_GET['page'] ) && WP_AUDIT_PAGE_SLUG === $_GET['page'];
}

/**
 * Remember which file registered a post type or taxonomy.
 *
 * The call stack at registration time names the exact file, which replaces
 * the old approach of reading every PHP file under wp-content/plugins once per
 * post type. Only hooked on the report screen, so other requests pay nothing.
 */
function wp_audit_record_source( $kind, $name ) {
	global $wp_audit_registered_sources;

	$abspath   = untrailingslashit( wp_normalize_path( ABSPATH ) );
	$this_file = wp_normalize_path( __FILE__ );
	$core_dirs = array(
		wp_normalize_path( ABSPATH . WPINC ) . '/',
		wp_normalize_path( ABSPATH . 'wp-admin' ) . '/',
	);

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- intentional, see docblock.
	foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
		if ( empty( $frame['file'] ) ) {
			continue;
		}

		// Skip core and this plugin: the first frame left is the code that called register_*().
		$file = wp_normalize_path( $frame['file'] );
		if ( $file === $this_file || dirname( $file ) === $abspath ) {
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

/* ==========================================================================
   Remote / environment checks
   ========================================================================== */

/**
 * Check WordPress core version against the latest stable release.
 * Returns: array with 'current', 'latest', 'outdated', 'security_risk' (+ 'error' on failure).
 */
function wp_audit_check_wp_version() {
	return wp_audit_remember(
		'wp_version',
		HOUR_IN_SECONDS,
		function () {
			$current = get_bloginfo( 'version' );

			// Reuse core's own update check instead of calling api.wordpress.org ourselves.
			$updates = get_site_transient( 'update_core' );
			if ( empty( $updates->updates ) && function_exists( 'wp_version_check' ) ) {
				wp_version_check();
				$updates = get_site_transient( 'update_core' );
			}

			if ( empty( $updates->updates ) ) {
				return array(
					'current'       => $current,
					'latest'        => 'Unknown',
					'outdated'      => false,
					'security_risk' => false,
					'error'         => 'Could not reach the WordPress.org update service.',
				);
			}

			$latest = $current;
			foreach ( $updates->updates as $offer ) {
				// Ignore beta/nightly offers for sites on a development channel.
				if ( isset( $offer->response ) && 'development' === $offer->response ) {
					continue;
				}
				if ( isset( $offer->current ) && version_compare( $offer->current, $latest, '>' ) ) {
					$latest = $offer->current;
				}
			}

			// WordPress major releases are X.Y (6.9 -> 7.0 is one release), so compare on a single X.Y scale.
			$branch = function ( $version ) {
				$parts = array_map( 'intval', explode( '.', $version ) );
				return $parts[0] * 10 + ( isset( $parts[1] ) ? $parts[1] : 0 );
			};

			return array(
				'current'       => $current,
				'latest'        => $latest,
				'outdated'      => version_compare( $current, $latest, '<' ),
				'security_risk' => ( $branch( $latest ) - $branch( $current ) ) > 2,
			);
		}
	);
}

/**
 * Check file permissions for security.
 *
 * Each entry has a 'status' of good|warning|bad. Returns null on Windows,
 * where POSIX permission bits don't mean anything.
 */
function wp_audit_check_file_permissions() {
	if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
		return null;
	}

	// wp-config.php may live one level above the WordPress root (same lookup wp-load.php does).
	$config_path  = ABSPATH . 'wp-config.php';
	$config_label = 'wp-config.php';
	if ( ! file_exists( $config_path ) && @file_exists( dirname( ABSPATH ) . '/wp-config.php' ) && ! @file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
		$config_path  = dirname( ABSPATH ) . '/wp-config.php';
		$config_label = '../wp-config.php';
	}

	$upload_dir = wp_upload_dir( null, false );

	$checks = array(
		array(
			'label'       => $config_label,
			'path'        => $config_path,
			'good'        => array( '400', '440', '600', '640' ),
			'warning'     => array( '444', '644' ),
			'recommended' => '600 or 640',
		),
		array(
			'label'       => '.htaccess',
			'path'        => ABSPATH . '.htaccess',
			'good'        => array( '444', '600', '640', '644' ),
			'warning'     => array( '664' ),
			'recommended' => '644',
		),
		array(
			'label'       => 'wp-content/uploads',
			'path'        => $upload_dir['basedir'],
			'good'        => array( '750', '755' ),
			// Group-writable uploads are normal on many shared hosts; worth a look, not a red flag.
			'warning'     => array( '770', '775' ),
			'recommended' => '755',
		),
	);

	$results = array();
	foreach ( $checks as $check ) {
		if ( ! file_exists( $check['path'] ) ) {
			continue;
		}

		$perms = substr( sprintf( '%o', fileperms( $check['path'] ) ), -3 );
		if ( in_array( $perms, $check['good'], true ) ) {
			$status = 'good';
		} elseif ( in_array( $perms, $check['warning'], true ) ) {
			$status = 'warning';
		} else {
			$status = 'bad';
		}

		$results[] = array(
			'path'        => $check['label'],
			'permissions' => $perms,
			'recommended' => $check['recommended'],
			'status'      => $status,
		);
	}

	return $results;
}

/**
 * Check SSL certificate details.
 */
function wp_audit_check_ssl_certificate() {
	if ( ! is_ssl() ) {
		return array(
			'enabled' => false,
			'message' => 'SSL is not enabled',
		);
	}

	return wp_audit_remember(
		'ssl_cert',
		6 * HOUR_IN_SECONDS,
		function () {
			$host = wp_parse_url( get_site_url(), PHP_URL_HOST );

			$context = stream_context_create(
				array(
					'ssl' => array(
						'capture_peer_cert' => true,
						'verify_peer'       => true,
						'verify_peer_name'  => true,
					),
				)
			);

			$socket = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context );

			if ( ! $socket ) {
				return array(
					'enabled' => true,
					'error'   => 'Could not connect to SSL socket',
					'message' => $errstr,
				);
			}

			$params = stream_context_get_params( $socket );
			fclose( $socket );

			if ( ! isset( $params['options']['ssl']['peer_certificate'] ) ) {
				return array(
					'enabled' => true,
					'error'   => 'Could not retrieve certificate',
				);
			}

			$cert_info = openssl_x509_parse( $params['options']['ssl']['peer_certificate'] );
			if ( ! $cert_info ) {
				return array(
					'enabled' => true,
					'error'   => 'Could not parse certificate',
				);
			}

			$expiry_date       = $cert_info['validTo_time_t'];
			$days_until_expiry = (int) ceil( ( $expiry_date - time() ) / DAY_IN_SECONDS );

			return array(
				'enabled'           => true,
				'issuer'            => isset( $cert_info['issuer']['O'] ) ? $cert_info['issuer']['O'] : 'Unknown',
				'expires'           => gmdate( 'Y-m-d', $expiry_date ),
				'days_until_expiry' => $days_until_expiry,
				'expires_soon'      => $days_until_expiry < 30,
				'subject'           => isset( $cert_info['subject']['CN'] ) ? $cert_info['subject']['CN'] : 'Unknown',
			);
		}
	);
}

/**
 * Get PageSpeed Insights scores for one strategy (desktop|mobile).
 *
 * Without an API key, Google's shared quota frequently returns HTTP 429.
 * Add a key with define( 'WP_AUDIT_PSI_KEY', '...' ) or the wp_audit_pagespeed_api_key filter.
 */
function wp_audit_get_pagespeed_score( $url, $strategy = 'desktop' ) {
	return wp_audit_remember(
		'pagespeed_' . md5( $url . $strategy ),
		DAY_IN_SECONDS,
		function () use ( $url, $strategy ) {
			$args = array(
				'url'      => rawurlencode( $url ),
				'strategy' => $strategy,
			);

			$api_key = apply_filters( 'wp_audit_pagespeed_api_key', defined( 'WP_AUDIT_PSI_KEY' ) ? WP_AUDIT_PSI_KEY : '' );
			if ( $api_key ) {
				$args['key'] = rawurlencode( $api_key );
			}

			// add_query_arg() can't represent repeated keys, but the API wants &category= once per category.
			$api_url = add_query_arg( $args, 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed' )
				. '&category=performance&category=accessibility&category=seo&category=best-practices';

			$response = wp_remote_get( $api_url, array( 'timeout' => 45 ) );
			if ( is_wp_error( $response ) ) {
				return array(
					'error'   => true,
					'message' => $response->get_error_message(),
				);
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $data['lighthouseResult'] ) ) {
				return array(
					'error'   => true,
					'message' => isset( $data['error']['message'] ) ? $data['error']['message'] : 'Could not retrieve PageSpeed data',
				);
			}

			$categories = isset( $data['lighthouseResult']['categories'] ) ? $data['lighthouseResult']['categories'] : array();
			$audits     = isset( $data['lighthouseResult']['audits'] ) ? $data['lighthouseResult']['audits'] : array();

			$score = function ( $key ) use ( $categories ) {
				return isset( $categories[ $key ]['score'] ) ? (int) round( $categories[ $key ]['score'] * 100 ) : null;
			};
			$audit = function ( $key, $divisor = 1, $precision = 0 ) use ( $audits ) {
				return isset( $audits[ $key ]['numericValue'] ) ? round( $audits[ $key ]['numericValue'] / $divisor, $precision ) : null;
			};

			// INP replaced FID as a Core Web Vital (March 2024). It only exists in real-user (CrUX)
			// field data, which low-traffic sites often lack, so fall back to origin-level data.
			$inp       = null;
			$inp_scope = '';
			foreach ( array( 'loadingExperience', 'originLoadingExperience' ) as $key ) {
				if ( isset( $data[ $key ]['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile'] ) ) {
					$inp       = (int) $data[ $key ]['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile'];
					$inp_scope = ( 'loadingExperience' === $key && empty( $data[ $key ]['origin_fallback'] ) ) ? 'page' : 'origin';
					break;
				}
			}

			return array(
				'performance'    => $score( 'performance' ),
				'accessibility'  => $score( 'accessibility' ),
				'seo'            => $score( 'seo' ),
				'best_practices' => $score( 'best-practices' ),
				'lcp'            => $audit( 'largest-contentful-paint', 1000, 2 ),
				'fcp'            => $audit( 'first-contentful-paint', 1000, 2 ),
				'cls'            => $audit( 'cumulative-layout-shift', 1, 3 ),
				'tbt'            => $audit( 'total-blocking-time' ),
				'ttfb'           => $audit( 'server-response-time' ),
				'inp'            => $inp,
				'inp_scope'      => $inp_scope,
				'strategy'       => $strategy,
			);
		}
	);
}

/**
 * AJAX: PageSpeed Insights for one strategy.
 *
 * Loaded after page paint because each API call can take 20–40s; running both
 * strategies inline used to block the whole report (and could hit max_execution_time).
 */
function wp_audit_ajax_pagespeed() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
	}
	check_ajax_referer( 'wp_audit_pagespeed', 'nonce' );

	if ( wp_audit_is_local_site() ) {
		wp_send_json_error( array( 'message' => 'PageSpeed Insights can\'t reach local development sites. Run the audit on staging or production for these scores.' ) );
	}

	$strategy = ( isset( $_POST['strategy'] ) && 'mobile' === $_POST['strategy'] ) ? 'mobile' : 'desktop';
	$result   = wp_audit_get_pagespeed_score( home_url( '/' ), $strategy );

	if ( ! empty( $result['error'] ) ) {
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}
	wp_send_json_success( $result );
}
add_action( 'wp_ajax_wp_audit_pagespeed', 'wp_audit_ajax_pagespeed' );

/**
 * Fetch and inspect the live robots.txt.
 *
 * WordPress serves a virtual robots.txt when no physical file exists, so a
 * file_exists() check alone reported "missing" on perfectly healthy sites.
 */
function wp_audit_check_robots() {
	return wp_audit_remember(
		'robots',
		HOUR_IN_SECONDS,
		function () {
			$home_path = function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH;
			$result    = array(
				'source'     => file_exists( $home_path . 'robots.txt' ) ? 'Physical file' : 'Virtual (generated by WordPress)',
				'reachable'  => false,
				'blocks_all' => false,
				'sitemaps'   => array(),
			);

			$response = wp_remote_get(
				home_url( '/robots.txt' ),
				array(
					'timeout'   => 10,
					// Same loopback convention core's Site Health uses: don't fail on a self-signed local cert.
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				)
			);

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				$result['error'] = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response );
				return $result;
			}

			$body                 = wp_remote_retrieve_body( $response );
			$result['reachable']  = true;
			// A bare "Disallow: /" blocks the whole site for that user-agent group — a common staging leftover.
			$result['blocks_all'] = (bool) preg_match( '/^\s*Disallow:\s*\/\s*$/mi', $body );
			if ( preg_match_all( '/^\s*Sitemap:\s*(\S+)/mi', $body, $matches ) ) {
				$result['sitemaps'] = $matches[1];
			}

			return $result;
		}
	);
}

/**
 * Detect page caching / object caching, regardless of which plugin or host provides it.
 * Returns a list of human-readable findings (empty if nothing detected).
 */
function wp_audit_detect_caching( array $active_plugins ) {
	$found = array();

	// Hosts like Anchor, Kinsta and WP Engine cache at the server, with no caching plugin installed.
	if ( defined( 'WP_CACHE' ) && WP_CACHE && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
		$found[] = 'Page cache drop-in (advanced-cache.php)';
	}
	if ( wp_using_ext_object_cache() ) {
		$found[] = 'Persistent object cache';
	}

	$plugin = wp_audit_first_detected(
		array(
			'WP Rocket'        => defined( 'WP_ROCKET_VERSION' ),
			'LiteSpeed Cache'  => defined( 'LSCWP_V' ),
			'W3 Total Cache'   => defined( 'W3TC' ),
			'WP Super Cache'   => defined( 'WPCACHEHOME' ),
			'WP Fastest Cache' => class_exists( 'WpFastestCache' ),
		)
	);
	if ( ! $plugin ) {
		foreach ( $active_plugins as $file ) {
			if ( false !== stripos( $file, 'cache' ) ) {
				$plugin = dirname( $file );
				break;
			}
		}
	}
	if ( $plugin ) {
		$found[] = 'Plugin: ' . $plugin;
	}

	return $found;
}

/* ==========================================================================
   Data collectors (cached)
   ========================================================================== */

/**
 * Post counts for every post type and status, from one query.
 * Returns [ post_type => [ status => count ] ].
 */
function wp_audit_get_post_counts() {
	return wp_audit_remember(
		'post_counts',
		15 * MINUTE_IN_SECONDS,
		function () {
			global $wpdb;
			$rows = $wpdb->get_results( "SELECT post_type, post_status, COUNT(*) AS total FROM {$wpdb->posts} GROUP BY post_type, post_status" );

			$counts = array();
			foreach ( $rows as $row ) {
				$counts[ $row->post_type ][ $row->post_status ] = (int) $row->total;
			}
			return $counts;
		}
	);
}

/**
 * Read one cell out of wp_audit_get_post_counts(), defaulting to 0.
 */
function wp_audit_count_status( array $counts, $post_type, $status ) {
	return isset( $counts[ $post_type ][ $status ] ) ? $counts[ $post_type ][ $status ] : 0;
}

/**
 * Term counts per taxonomy, from one query. Returns [ taxonomy => count ].
 */
function wp_audit_get_term_counts() {
	return wp_audit_remember(
		'term_counts',
		15 * MINUTE_IN_SECONDS,
		function () {
			global $wpdb;
			$rows = $wpdb->get_results( "SELECT taxonomy, COUNT(*) AS total FROM {$wpdb->term_taxonomy} GROUP BY taxonomy", ARRAY_A );
			return array_map( 'intval', wp_list_pluck( $rows, 'total', 'taxonomy' ) );
		}
	);
}

/**
 * Published-page counts per _wp_page_template value. Returns [ template_file => count ].
 */
function wp_audit_get_template_usage() {
	return wp_audit_remember(
		'template_usage',
		15 * MINUTE_IN_SECONDS,
		function () {
			global $wpdb;
			$rows = $wpdb->get_results(
				"SELECT pm.meta_value AS template, COUNT(*) AS total
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				 WHERE p.post_type = 'page'
				   AND p.post_status = 'publish'
				   AND pm.meta_key = '_wp_page_template'
				 GROUP BY pm.meta_value",
				ARRAY_A
			);
			return array_map( 'intval', wp_list_pluck( $rows, 'total', 'template' ) );
		}
	);
}

/**
 * Form counts per supported form plugin: [ label => [ 'total', 'active', 'inactive' ] ].
 */
function wp_audit_get_form_counts( array $post_counts ) {
	$forms = array();

	if ( class_exists( 'GFFormsModel' ) ) {
		$rows = wp_audit_remember(
			'gf_forms',
			15 * MINUTE_IN_SECONDS,
			function () {
				global $wpdb;
				$table = GFFormsModel::get_form_table_name();
				return $wpdb->get_results( "SELECT is_active, COUNT(*) AS total FROM {$table} WHERE is_trash = 0 GROUP BY is_active", ARRAY_A );
			}
		);

		$forms['Gravity Forms'] = array( 'active' => 0, 'inactive' => 0 );
		foreach ( (array) $rows as $row ) {
			$forms['Gravity Forms'][ $row['is_active'] ? 'active' : 'inactive' ] += (int) $row['total'];
		}
	}

	// CF7 and WPForms store forms as posts, so the shared post-count map already has them.
	$post_based = array(
		'Contact Form 7' => array( class_exists( 'WPCF7_ContactForm' ), 'wpcf7_contact_form' ),
		'WPForms'        => array( function_exists( 'wpforms' ), 'wpforms' ),
	);
	foreach ( $post_based as $label => $info ) {
		if ( ! $info[0] ) {
			continue;
		}
		$statuses = isset( $post_counts[ $info[1] ] ) ? $post_counts[ $info[1] ] : array();
		$active   = isset( $statuses['publish'] ) ? $statuses['publish'] : 0;
		unset( $statuses['publish'], $statuses['trash'], $statuses['auto-draft'] );

		$forms[ $label ] = array( 'active' => $active, 'inactive' => array_sum( $statuses ) );
	}

	// Formidable keeps forms in its own table; there is no frm_form post type to count.
	if ( class_exists( 'FrmForm' ) ) {
		$rows = wp_audit_remember(
			'frm_forms',
			15 * MINUTE_IN_SECONDS,
			function () {
				global $wpdb;
				return $wpdb->get_results(
					"SELECT status, COUNT(*) AS total FROM {$wpdb->prefix}frm_forms
					 WHERE is_template = 0 AND ( parent_form_id = 0 OR parent_form_id IS NULL )
					 GROUP BY status",
					ARRAY_A
				);
			}
		);

		$forms['Formidable Forms'] = array( 'active' => 0, 'inactive' => 0 );
		foreach ( (array) $rows as $row ) {
			if ( 'published' === $row['status'] ) {
				$forms['Formidable Forms']['active'] += (int) $row['total'];
			} elseif ( 'trash' !== $row['status'] ) {
				$forms['Formidable Forms']['inactive'] += (int) $row['total'];
			}
		}
	}

	foreach ( $forms as $label => $counts ) {
		$forms[ $label ]['total'] = $counts['active'] + $counts['inactive'];
	}

	return $forms;
}

/**
 * Database size, table count and orphaned/bloated data counts.
 */
function wp_audit_get_database_stats() {
	return wp_audit_remember(
		'db_stats',
		15 * MINUTE_IN_SECONDS,
		function () {
			global $wpdb;

			$schema = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS table_count, ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
					 FROM information_schema.tables
					 WHERE table_schema = %s",
					DB_NAME
				),
				ARRAY_A
			);

			return array(
				'size_mb'           => isset( $schema['size_mb'] ) ? $schema['size_mb'] : 0,
				'table_count'       => isset( $schema['table_count'] ) ? (int) $schema['table_count'] : 0,
				'orphaned_postmeta' => (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
					 LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
					 WHERE p.ID IS NULL"
				),
				'orphaned_terms'    => (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
					 LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID
					 WHERE p.ID IS NULL AND tr.object_id != 0"
				),
				// The subquery matters: with a bare GROUP BY, get_var() only reads the first group's row (always 1).
				'excessive_meta'    => (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM (
						SELECT post_id FROM {$wpdb->postmeta} GROUP BY post_id HAVING COUNT(*) > 50
					 ) AS heavy_posts"
				),
			);
		}
	);
}

/**
 * Simple 0–100 database health score derived from wp_audit_get_database_stats().
 */
function wp_audit_database_health_score( array $db ) {
	$score = 100;

	foreach ( array( 'orphaned_postmeta', 'orphaned_terms' ) as $key ) {
		if ( $db[ $key ] > 100 ) {
			$score -= 20;
		} elseif ( $db[ $key ] > 50 ) {
			$score -= 10;
		} elseif ( $db[ $key ] > 10 ) {
			$score -= 5;
		}
	}

	if ( $db['excessive_meta'] > 10 ) {
		$score -= 15;
	} elseif ( $db['excessive_meta'] > 5 ) {
		$score -= 10;
	}

	return max( 0, min( 100, $score ) );
}

/**
 * Image count and attachments whose parent post no longer exists.
 */
function wp_audit_get_media_stats() {
	return wp_audit_remember(
		'media_stats',
		15 * MINUTE_IN_SECONDS,
		function () {
			global $wpdb;
			return array(
				'images'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" ),
				'orphaned' => (int) $wpdb->get_var(
					"SELECT COUNT(p.ID)
					 FROM {$wpdb->posts} p
					 LEFT JOIN {$wpdb->posts} pp ON p.post_parent = pp.ID
					 WHERE p.post_type = 'attachment'
					   AND p.post_status = 'inherit'
					   AND p.post_parent != 0
					   AND pp.ID IS NULL"
				),
			);
		}
	);
}

/**
 * Top 50 public (non-underscore) postmeta keys by number of posts using them.
 */
function wp_audit_get_postmeta_keys() {
	return wp_audit_remember(
		'postmeta_keys',
		15 * MINUTE_IN_SECONDS,
		function () {
			global $wpdb;
			return $wpdb->get_results(
				"SELECT meta_key, COUNT(DISTINCT post_id) AS usage_count, COUNT(*) AS total_entries
				 FROM {$wpdb->postmeta}
				 WHERE meta_key NOT LIKE '\\_%'
				 GROUP BY meta_key
				 ORDER BY usage_count DESC
				 LIMIT 50"
			);
		}
	);
}

/**
 * Known plugin table prefixes (after $wpdb->prefix) => [ owner label, owner active? ].
 *
 * Without this, every non-core table — including ones an active plugin depends
 * on — was labeled "Review for deletion". Extend via the wp_audit_known_table_owners filter.
 */
function wp_audit_known_table_owners() {
	$owners = array(
		'actionscheduler_' => array( 'Action Scheduler (WooCommerce, Rank Math, WP Mail SMTP, etc.)', function_exists( 'as_schedule_single_action' ) ),
		'gf_'              => array( 'Gravity Forms', class_exists( 'GFForms' ) ),
		'rg_'              => array( 'Gravity Forms (legacy tables)', class_exists( 'GFForms' ) ),
		'wc_'              => array( 'WooCommerce', class_exists( 'WooCommerce' ) ),
		'woocommerce_'     => array( 'WooCommerce', class_exists( 'WooCommerce' ) ),
		'yoast_'           => array( 'Yoast SEO', defined( 'WPSEO_VERSION' ) ),
		'rank_math_'       => array( 'Rank Math', defined( 'RANK_MATH_VERSION' ) ),
		'aioseo_'          => array( 'All in One SEO', defined( 'AIOSEO_VERSION' ) ),
		'e_'               => array( 'Elementor', defined( 'ELEMENTOR_VERSION' ) ),
		'wpforms_'         => array( 'WPForms', function_exists( 'wpforms' ) ),
		'frm_'             => array( 'Formidable Forms', class_exists( 'FrmForm' ) ),
		'nf3_'             => array( 'Ninja Forms', class_exists( 'Ninja_Forms' ) ),
		'redirection_'     => array( 'Redirection', defined( 'REDIRECTION_VERSION' ) ),
		'wpmailsmtp_'      => array( 'WP Mail SMTP', defined( 'WPMS_PLUGIN_VER' ) ),
		'wf'               => array( 'Wordfence', class_exists( 'wordfence' ) ),
		'icl_'             => array( 'WPML', defined( 'ICL_SITEPRESS_VERSION' ) ),
		'litespeed_'       => array( 'LiteSpeed Cache', defined( 'LSCWP_V' ) ),
		'tec_'             => array( 'The Events Calendar', class_exists( 'Tribe__Events__Main' ) ),
		'wpr_'             => array( 'WP Rocket', defined( 'WP_ROCKET_VERSION' ) ),
		'snippets'         => array( 'Code Snippets', defined( 'CODE_SNIPPETS_VERSION' ) ),
		'pmxi_'            => array( 'WP All Import', class_exists( 'PMXI_Plugin' ) ),
		'ewwwio_'          => array( 'EWWW Image Optimizer', defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' ) ),
	);
	return apply_filters( 'wp_audit_known_table_owners', $owners );
}

/**
 * Non-core tables for this site, each with its likely owner.
 * Returns a list of [ 'table', 'owner' (or ''), 'active' (true|false|null when owner unknown) ].
 */
function wp_audit_find_orphaned_tables() {
	global $wpdb;

	// esc_like() matters: "_" in the prefix is otherwise a single-character wildcard.
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
	$core   = array_values( $wpdb->tables( 'all' ) );

	// On a multisite main site, the other sites' tables (wp_2_posts…) share the base prefix; they aren't orphans.
	$other_sites = ( is_multisite() && $wpdb->prefix === $wpdb->base_prefix ) ? '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '\d+_/' : '';

	// Longest prefix first so "woocommerce_" wins over a shorter overlapping prefix.
	$owners = wp_audit_known_table_owners();
	uksort(
		$owners,
		function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		}
	);

	$results = array();
	foreach ( array_diff( $tables, $core ) as $table ) {
		if ( $other_sites && preg_match( $other_sites, $table ) ) {
			continue;
		}

		$suffix = substr( $table, strlen( $wpdb->prefix ) );
		$owner  = '';
		$active = null;
		foreach ( $owners as $prefix => $info ) {
			if ( 0 === strpos( $suffix, $prefix ) ) {
				list( $owner, $active ) = $info;
				break;
			}
		}

		$results[] = array(
			'table'  => $table,
			'owner'  => $owner,
			'active' => $active,
		);
	}

	return $results;
}

/**
 * Shortcode tags used in published posts/pages that no active code registers.
 *
 * Note get_shortcode_regex() can't be used here: with no arguments it only
 * matches tags that ARE registered, so unregistered ones were never found.
 *
 * Returns [ 'tags' => [ tag => [ posts, occurrences, example_id, example_title ] ], 'checked' => int, 'limit' => int ].
 */
function wp_audit_find_unregistered_shortcodes() {
	$limit = wp_audit_setting( 'shortcode_post_limit' );

	return wp_audit_remember(
		'shortcodes_' . $limit,
		15 * MINUTE_IN_SECONDS,
		function () use ( $limit ) {
			global $wpdb;

			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_content FROM {$wpdb->posts}
					 WHERE post_status = 'publish'
					   AND post_type IN ('post', 'page')
					   AND post_content LIKE %s
					 ORDER BY post_modified DESC
					 LIMIT %d",
					'%' . $wpdb->esc_like( '[' ) . '%',
					$limit
				)
			);

			$tags = array();
			foreach ( $posts as $post ) {
				// "[tag", "[tag attr", "[tag]" or "[tag/]" — not closing tags or escaped "[[tag]]".
				if ( ! preg_match_all( '/(?<!\[)\[([a-zA-Z][\w-]*)(?=[\s\]\/])/', $post->post_content, $matches ) ) {
					continue;
				}

				$occurrences = array_count_values( $matches[1] );
				foreach ( $occurrences as $tag => $count ) {
					if ( shortcode_exists( $tag ) ) {
						continue;
					}
					if ( ! isset( $tags[ $tag ] ) ) {
						$tags[ $tag ] = array(
							'posts'         => 0,
							'occurrences'   => 0,
							'example_id'    => (int) $post->ID,
							'example_title' => $post->post_title,
						);
					}
					$tags[ $tag ]['posts']++;
					$tags[ $tag ]['occurrences'] += $count;
				}
			}

			uasort(
				$tags,
				function ( $a, $b ) {
					return $b['posts'] - $a['posts'];
				}
			);

			return array(
				'tags'    => $tags,
				'checked' => count( $posts ),
				'limit'   => $limit,
			);
		}
	);
}

/**
 * The parts of post content that someone typed by hand.
 *
 * Block markup carries style="" attributes generated from the block's own
 * settings (colors, spacing), so counting them flagged nearly every block-built
 * page. Only classic content and Custom HTML blocks reflect hand-written styles.
 */
function wp_audit_get_hand_written_html( $content ) {
	if ( ! has_blocks( $content ) ) {
		return $content;
	}

	$html = '';
	foreach ( parse_blocks( $content ) as $block ) {
		$html .= wp_audit_collect_raw_html( $block );
	}
	return $html;
}

/**
 * Recursively gather innerHTML from freeform (classic) and Custom HTML blocks.
 */
function wp_audit_collect_raw_html( array $block ) {
	if ( in_array( $block['blockName'], array( null, 'core/freeform', 'core/html' ), true ) ) {
		return $block['innerHTML'];
	}

	$html = '';
	foreach ( $block['innerBlocks'] as $inner ) {
		$html .= wp_audit_collect_raw_html( $inner );
	}
	return $html;
}

/**
 * Published posts/pages with excessive hand-written inline styling.
 * Returns [ 'posts' => [ id, title, type, style_count, has_style_tag ], 'checked' => int, 'limit' => int ].
 */
function wp_audit_find_inline_style_bloat() {
	$post_limit  = wp_audit_setting( 'inline_style_post_limit' );
	$style_limit = wp_audit_setting( 'inline_style_limit' );

	return wp_audit_remember(
		'inline_styles_' . $post_limit . '_' . $style_limit,
		15 * MINUTE_IN_SECONDS,
		function () use ( $post_limit, $style_limit ) {
			global $wpdb;

			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts}
					 WHERE post_status = 'publish'
					   AND post_type IN ('post', 'page')
					   AND ( post_content LIKE %s OR post_content LIKE %s )
					 ORDER BY post_modified DESC
					 LIMIT %d",
					'%' . $wpdb->esc_like( 'style=' ) . '%',
					'%' . $wpdb->esc_like( '<style' ) . '%',
					$post_limit
				)
			);

			$flagged = array();
			foreach ( $posts as $post ) {
				$html          = wp_audit_get_hand_written_html( $post->post_content );
				$has_style_tag = false !== stripos( $html, '<style' );
				$style_count   = preg_match_all( '/\sstyle\s*=\s*[\'"]/i', $html );

				// An embedded <style> block is a much bigger smell than a few attributes, so it counts heavily.
				$hits = $style_count + ( $has_style_tag ? 50 : 0 );
				if ( $hits >= $style_limit ) {
					$flagged[] = array(
						'id'            => (int) $post->ID,
						'title'         => $post->post_title,
						'type'          => $post->post_type,
						'style_count'   => $style_count,
						'has_style_tag' => $has_style_tag,
					);
				}
			}

			return array(
				'posts'   => $flagged,
				'checked' => count( $posts ),
				'limit'   => $post_limit,
			);
		}
	);
}

/**
 * [ css class, label ] for a plugin's update status, from core's update_plugins transient.
 */
function wp_audit_plugin_update_status( $plugin_file, $updates ) {
	if ( isset( $updates->response[ $plugin_file ]->new_version ) ) {
		return array( 'audit-status-warning', 'Update available: ' . $updates->response[ $plugin_file ]->new_version );
	}
	if ( isset( $updates->no_update[ $plugin_file ] ) ) {
		return array( 'audit-status-good', 'Up to date' );
	}
	return array( '', 'Not tracked (premium or custom)' );
}

/* ==========================================================================
   Admin page
   ========================================================================== */

/**
 * Add the admin page to the "Tools" menu.
 */
function wp_audit_tool_add_admin_page() {
	add_management_page(
		'Site Audit Report',          // Page Title
		'Site Audit',                 // Menu Title
		'manage_options',             // Capability
		WP_AUDIT_PAGE_SLUG,           // Menu Slug
		'wp_audit_tool_render_page'   // Callback Function
	);
}
add_action( 'admin_menu', 'wp_audit_tool_add_admin_page' );

/**
 * Enqueue report assets on the report screen only.
 */
function wp_audit_enqueue_assets( $hook_suffix ) {
	if ( 'tools_page_' . WP_AUDIT_PAGE_SLUG !== $hook_suffix ) {
		return;
	}

	$url = plugin_dir_url( __FILE__ ) . 'assets/';

	wp_enqueue_style( 'wp-audit-admin', $url . 'admin.css', array( 'dashicons' ), WP_AUDIT_VERSION );

	// Chart.js is self-hosted and pinned, so no third-party CDN runs JavaScript inside wp-admin.
	wp_enqueue_script( 'wp-audit-chartjs', $url . 'vendor/chart.umd.min.js', array(), '4.4.0', array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_enqueue_script( 'wp-audit-admin', $url . 'admin.js', array( 'wp-audit-chartjs' ), WP_AUDIT_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
}
add_action( 'admin_enqueue_scripts', 'wp_audit_enqueue_assets' );

/**
 * Render the admin page content.
 */
function wp_audit_tool_render_page() {
	// Check if user is admin
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '<p style="color: red;"><strong>Error:</strong> Only administrators can view this report.</p>' );
	}

	// --- Shared data, gathered once and reused by every section below ---

	$active_theme = wp_get_theme();
	$parent_theme = $active_theme->parent();

	$all_plugins      = wp_audit_get_plugins();
	$active_plugins   = (array) get_option( 'active_plugins', array() );
	$inactive_plugins = array_diff_key( $all_plugins, array_flip( $active_plugins ) );
	$plugin_updates   = get_site_transient( 'update_plugins' );

	$post_counts   = wp_audit_get_post_counts();
	$builtin_types = get_post_types( array( '_builtin' => true ) );

	$page_count  = wp_audit_count_status( $post_counts, 'page', 'publish' );
	$draft_pages = wp_audit_count_status( $post_counts, 'page', 'draft' );
	$blog_posts  = wp_audit_count_status( $post_counts, 'post', 'publish' );
	$total_media = wp_audit_count_status( $post_counts, 'attachment', 'inherit' );

	// Published content in non-core post types, largest first. Derived from the
	// built-in list so block-theme internals (wp_template, wp_navigation…) aren't counted.
	$cpt_counts = array();
	foreach ( $post_counts as $type => $statuses ) {
		if ( ! isset( $builtin_types[ $type ] ) && ! empty( $statuses['publish'] ) ) {
			$cpt_counts[ $type ] = $statuses['publish'];
		}
	}
	arsort( $cpt_counts );
	$total_cpt_count = count( $cpt_counts );
	$top_cpts        = array_slice( $cpt_counts, 0, 3, true );

	// Page templates: every template in the theme with its usage, including unused ones (cleanup candidates).
	$theme_templates      = $active_theme->get_page_templates();
	$theme_template_count = count( $theme_templates );
	$template_usage_raw   = wp_audit_get_template_usage();
	$template_usage       = array();
	foreach ( $theme_templates as $file => $name ) {
		$template_usage[ $file ] = array(
			'name'  => $name,
			'count' => isset( $template_usage_raw[ $file ] ) ? $template_usage_raw[ $file ] : 0,
		);
	}
	// Pages pointing at a template the theme no longer has silently fall back to the default template.
	$missing_templates = array();
	foreach ( $template_usage_raw as $file => $count ) {
		if ( 'default' !== $file && '' !== $file && ! isset( $theme_templates[ $file ] ) ) {
			$missing_templates[ $file ] = $count;
		}
	}

	$forms_summary = wp_audit_get_form_counts( $post_counts );
	$total_forms   = array_sum( wp_list_pluck( $forms_summary, 'total' ) );

	$db           = wp_audit_get_database_stats();
	$health_score = wp_audit_database_health_score( $db );

	// Chart data: top 5 published content types (posts, pages and CPTs).
	$chart_types = array();
	foreach ( $post_counts as $type => $statuses ) {
		if ( ( ! isset( $builtin_types[ $type ] ) || in_array( $type, array( 'post', 'page' ), true ) ) && ! empty( $statuses['publish'] ) ) {
			$chart_types[ $type ] = $statuses['publish'];
		}
	}
	arsort( $chart_types );
	$chart_types = array_slice( $chart_types, 0, 5, true );

	wp_add_inline_script(
		'wp-audit-admin',
		'window.wpAudit = ' . wp_json_encode(
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'pagespeedNonce' => wp_create_nonce( 'wp_audit_pagespeed' ),
				'siteName'      => sanitize_title( get_bloginfo( 'name' ) ),
				'plugins'       => array( count( $active_plugins ), count( $inactive_plugins ) ),
				'postTypes'     => array(
					'labels' => array_keys( $chart_types ),
					'data'   => array_values( $chart_types ),
				),
				'healthScore'   => $health_score,
			)
		) . ';',
		'before'
	);
	?>
	<div class="wrap">
		<h1>Site Audit Report</h1>

		<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect. ?>
		<?php if ( isset( $_GET['wp_audit_refreshed'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Cached audit data cleared. All checks below were re-run.</p></div>
		<?php endif; ?>

		<!-- ===== OVERVIEW SECTION ===== -->
		<div id="section-overview">
			<h3>
				<span class="dashicons dashicons-layout" aria-hidden="true"></span>
				Website Overview
			</h3>
			<div class="wp-audit-overview-grid">
				<div class="wp-audit-overview-item">
					<div class="wp-audit-overview-item-header">
						<span class="dashicons dashicons-admin-appearance wp-audit-overview-icon" aria-hidden="true"></span>
						<strong>Active Theme</strong>
					</div>
					<span class="value"><?php echo esc_html( $active_theme->get( 'Name' ) ); ?></span>
					<?php if ( $parent_theme ) : ?>
						<div class="wp-audit-overview-secondary">
							<strong>Parent Theme</strong>
							<span><?php echo esc_html( $parent_theme->get( 'Name' ) ); ?></span>
						</div>
					<?php endif; ?>
					<hr>
					<p><strong>Site URL:</strong> <?php echo esc_url( home_url() ); ?></p>
					<p><strong>WordPress Version:</strong> <?php echo esc_html( get_bloginfo( 'version' ) ); ?></p>
				</div>

				<div class="wp-audit-overview-item">
					<div class="wp-audit-overview-item-header">
						<span class="dashicons dashicons-media-document wp-audit-overview-icon" aria-hidden="true"></span>
						<strong>Published Pages</strong>
					</div>
					<span class="value"><?php echo intval( $page_count ); ?></span>
					<div class="wp-audit-overview-secondary">
						<strong>Page Templates</strong>
						<span><?php echo intval( $theme_template_count ); ?> in theme</span>
					</div>
				</div>

				<div class="wp-audit-overview-item">
					<div class="wp-audit-overview-item-header">
						<span class="dashicons dashicons-admin-post wp-audit-overview-icon" aria-hidden="true"></span>
						<strong>Custom Post Types</strong>
					</div>
					<span class="value"><?php echo intval( $total_cpt_count ); ?></span>
					<?php if ( ! empty( $top_cpts ) ) : ?>
						<div class="wp-audit-overview-secondary">
							<strong>Top 3 CPTs</strong>
							<ul style="font-size: 0.85em; margin-top: 6px;">
								<?php foreach ( $top_cpts as $type => $count ) : ?>
									<li>
										<?php echo esc_html( $type ); ?>:
										<span class="count"><?php echo intval( $count ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				</div>

				<div class="wp-audit-overview-item">
					<div class="wp-audit-overview-item-header">
						<span class="dashicons dashicons-admin-plugins wp-audit-overview-icon" aria-hidden="true"></span>
						<strong>Active Plugins</strong>
					</div>
					<span class="value"><?php echo intval( count( $active_plugins ) ); ?></span>
					<div class="wp-audit-overview-secondary">
						<strong>WordPress Version</strong>
						<span><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
					</div>
				</div>

				<div class="wp-audit-overview-item">
					<div class="wp-audit-overview-item-header">
						<span class="dashicons dashicons-feedback wp-audit-overview-icon" aria-hidden="true"></span>
						<strong>Total Forms</strong>
					</div>
					<span class="value">
						<?php if ( $total_forms > 0 ) : ?>
							<?php echo intval( $total_forms ); ?>
						<?php else : ?>
							<span style="color: #999;">None</span>
						<?php endif; ?>
					</span>
					<div class="wp-audit-overview-secondary">
						<strong>Media Files</strong>
						<span><?php echo intval( $total_media ); ?></span>
					</div>
				</div>

				<div class="wp-audit-overview-item">
					<div class="wp-audit-overview-item-header">
						<span class="dashicons dashicons-database wp-audit-overview-icon" aria-hidden="true"></span>
						<strong>Database Size</strong>
					</div>
					<span class="value"><?php echo esc_html( $db['size_mb'] ); ?> MB</span>
				</div>
			</div>

			<!-- Visualizations Section -->
			<div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
				<h4 style="margin: 0 0 20px 0; font-size: 16px; color: var(--ch-color-1, #1d2327); font-weight: 600;">
					<span class="dashicons dashicons-chart-bar" aria-hidden="true" style="vertical-align: middle; margin-right: 8px;"></span>
					Data Visualizations
				</h4>

				<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 20px;">
					<!-- Plugin Distribution Chart -->
					<div style="background: var(--wp-admin-background-color, #fff); padding: 20px; border-radius: 8px; border: 1px solid var(--wp-admin-border-color, #c3c4c7);">
						<h5 style="margin: 0 0 15px 0; font-size: 14px; font-weight: 600; color: var(--ch-color-1, #1d2327);">
							Plugin Distribution
						</h5>
						<canvas id="plugin-distribution-chart" style="max-height: 250px;"></canvas>
					</div>

					<!-- Post Types Distribution Chart -->
					<div style="background: var(--wp-admin-background-color, #fff); padding: 20px; border-radius: 8px; border: 1px solid var(--wp-admin-border-color, #c3c4c7);">
						<h5 style="margin: 0 0 15px 0; font-size: 14px; font-weight: 600; color: var(--ch-color-1, #1d2327);">
							Top Post Types
						</h5>
						<canvas id="post-types-chart" style="max-height: 250px;"></canvas>
					</div>

					<!-- Database Health Gauge -->
					<div style="background: var(--wp-admin-background-color, #fff); padding: 20px; border-radius: 8px; border: 1px solid var(--wp-admin-border-color, #c3c4c7);">
						<h5 style="margin: 0 0 15px 0; font-size: 14px; font-weight: 600; color: var(--ch-color-1, #1d2327);">
							Database Health
						</h5>
						<canvas id="database-health-chart" style="max-height: 250px;"></canvas>
					</div>
				</div>
			</div>

			<!-- Tools section -->
			<div class="wp-audit-tools" style="margin-top:18px; padding-top: 18px; border-top: 1px solid #ddd;">
				<div style="display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap;">
					<div style="flex: 1; min-width: 200px;">
						<h4 style="margin: 0 0 10px 0; font-size: 13px; color: #666;">Export Report</h4>
						<button id="wp-audit-export-btn" type="button" class="button button-primary" style="height: 36px;">
							<span class="dashicons dashicons-download" style="margin-top: 6px;"></span>
							Download Report as .md
						</button>
						<p style="margin-top:8px;font-size:11px;color:#666; line-height: 1.4;">
							Generates a complete markdown report.
						</p>
					</div>

					<div style="flex: 1; min-width: 250px;">
						<h4 style="margin: 0 0 10px 0; font-size: 13px; color: #666;">Cached Data</h4>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="wp_audit_clear_cache">
							<?php wp_nonce_field( 'wp_audit_clear_cache' ); ?>
							<button type="submit" class="button" style="height: 36px;">
								<span class="dashicons dashicons-update" style="margin-top: 6px;"></span>
								Refresh Cached Data
							</button>
						</form>
						<p style="margin-top:8px;font-size:11px;color:#666; line-height: 1.4;">
							Database checks are cached for 15 minutes, remote checks (SSL, robots.txt, WordPress version) for 1–6 hours, and PageSpeed for 24 hours. Refresh to re-run them all now.
						</p>
					</div>
				</div>
			</div>
		</div>

		<div class="wp-audit-wrapper">

			<!-- ===== STICKY NAVIGATION ===== -->
			<nav class="wp-audit-nav">
				<ul>
					<li><a href="#section-post-types" class="active"><span class="dashicons dashicons-admin-post" aria-hidden="true"></span><span>Post Types</span></a></li>
					<li><a href="#section-taxonomies"><span class="dashicons dashicons-tag" aria-hidden="true"></span><span>Taxonomies</span></a></li>
					<li><a href="#section-pages"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span><span>Pages &amp; Hierarchy</span></a></li>
					<li><a href="#section-forms"><span class="dashicons dashicons-feedback" aria-hidden="true"></span><span>Forms &amp; Form Plugins</span></a></li>
					<li><a href="#section-database"><span class="dashicons dashicons-database" aria-hidden="true"></span><span>Database Health</span></a></li>
					<li><a href="#section-postmeta"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><span>Post Meta Analysis</span></a></li>
					<li><a href="#section-plugins"><span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span><span>Plugin Audit</span></a></li>
					<li><a href="#section-theme"><span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span><span>Theme &amp; Front-End</span></a></li>
					<li><a href="#section-users"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><span>User Roles</span></a></li>
					<li><a href="#section-media"><span class="dashicons dashicons-format-image" aria-hidden="true"></span><span>Media &amp; Assets</span></a></li>
					<li><a href="#section-urls"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><span>URL Structure</span></a></li>
					<li><a href="#section-security"><span class="dashicons dashicons-shield" aria-hidden="true"></span><span>Security Audit</span></a></li>
					<li><a href="#section-performance"><span class="dashicons dashicons-performance" aria-hidden="true"></span><span>Performance</span></a></li>
					<li><a href="#section-seo"><span class="dashicons dashicons-search" aria-hidden="true"></span><span>SEO &amp; Metadata</span></a></li>
					<li><a href="#section-rfq"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><span>RFQ Requirements</span></a></li>
					<li><a href="#section-cleanup"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span>Cleanup &amp; Technical Debt</span></a></li>
				</ul>
			</nav>

			<!-- ===== MAIN AUDIT CONTENT ===== -->
			<div class="wp-audit-content">

				<?php
				// ===== 1. POST TYPES & CONTENT STRUCTURE =====
				?>
				<h2 id="section-post-types"><span class="dashicons dashicons-admin-post" aria-hidden="true"></span> Post Types &amp; Content Structure</h2>
				<?php
				$post_types = get_post_types( array(), 'objects' );
				if ( ! empty( $post_types ) ) {
					?>
					<table class="wp-audit-table">
						<thead>
							<tr>
								<th>Post Type</th>
								<th>Label</th>
								<th>Published</th>
								<th>Draft</th>
								<th>Scheduled</th>
								<th>Trash</th>
								<th>Total</th>
								<th>Source <span class="wp-audit-tooltip" title="Captured from the call stack when the post type was registered on this page load. Hover a source for the exact file.">?</span></th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
							<?php
							$total_posts_all = 0;
							foreach ( $post_types as $post_type ) {
								$published = wp_audit_count_status( $post_counts, $post_type->name, 'publish' );
								$draft     = wp_audit_count_status( $post_counts, $post_type->name, 'draft' );
								$scheduled = wp_audit_count_status( $post_counts, $post_type->name, 'future' );
								$trash     = wp_audit_count_status( $post_counts, $post_type->name, 'trash' );
								$total     = $published + $draft + $scheduled + $trash;

								$total_posts_all += $total;
								$status_class     = $total > 0 ? 'audit-status-good' : 'audit-status-warning';
								$source           = wp_audit_get_source( 'post_type', $post_type->name, $post_type->_builtin );

								// Determine if this is a custom post type
								$is_custom_cpt = ! $post_type->_builtin && $post_type->show_in_menu;
								?>
								<tr>
									<td>
										<strong><?php echo esc_html( $post_type->name ); ?></strong>
										<?php if ( $is_custom_cpt ) : ?>
											<span class="wp-audit-custom-badge" title="Custom Post Type">
												<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
											</span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $post_type->label ); ?></td>
									<td><?php echo intval( $published ); ?></td>
									<td><?php echo intval( $draft ); ?></td>
									<td><?php echo intval( $scheduled ); ?></td>
									<td><?php echo intval( $trash ); ?></td>
									<td><strong><?php echo intval( $total ); ?></strong></td>
									<td title="<?php echo esc_attr( $source['location'] ); ?>"><?php echo esc_html( $source['label'] ); ?></td>
									<td><span class="<?php echo esc_attr( $status_class ); ?>">
										<?php echo $total > 0 ? 'Active' : 'Empty'; ?>
									</span></td>
								</tr>
								<?php
							}
							?>
						</tbody>
					</table>
					<div class="audit-summary">
						<strong>Total Posts, All Types (published, draft, scheduled, trash):</strong> <?php echo intval( $total_posts_all ); ?>
					</div>
					<?php
				} else {
					echo '<p><em>No post types found.</em></p>';
				}
				?>

				<?php
				// ===== 2. TAXONOMIES =====
				?>
				<h2 id="section-taxonomies"><span class="dashicons dashicons-tag" aria-hidden="true"></span> Taxonomies &amp; Classification System</h2>
				<?php
				$taxonomies  = get_taxonomies( array(), 'objects' );
				$term_counts = wp_audit_get_term_counts();
				if ( ! empty( $taxonomies ) ) {
					?>
					<table class="wp-audit-table">
						<thead>
							<tr>
								<th>Taxonomy</th>
								<th>Label</th>
								<th>Total Terms</th>
								<th>Hierarchical</th>
								<th>Source <span class="wp-audit-tooltip" title="Captured from the call stack when the taxonomy was registered on this page load. Hover a source for the exact file.">?</span></th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $taxonomies as $taxonomy ) {
								$term_count   = isset( $term_counts[ $taxonomy->name ] ) ? $term_counts[ $taxonomy->name ] : 0;
								$hierarchical = $taxonomy->hierarchical ? 'Yes' : 'No';
								$status_class = $term_count > 0 ? 'audit-status-good' : 'audit-status-warning';
								$source       = wp_audit_get_source( 'taxonomy', $taxonomy->name, $taxonomy->_builtin );

								// Determine if this is a custom taxonomy
								$is_custom_taxonomy = ! $taxonomy->_builtin && $taxonomy->show_in_menu;
								?>
								<tr>
									<td>
										<strong><?php echo esc_html( $taxonomy->name ); ?></strong>
										<?php if ( $is_custom_taxonomy ) : ?>
											<span class="wp-audit-custom-badge" title="Custom Taxonomy">
												<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
											</span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $taxonomy->label ); ?></td>
									<td><?php echo intval( $term_count ); ?></td>
									<td><?php echo esc_html( $hierarchical ); ?></td>
									<td title="<?php echo esc_attr( $source['location'] ); ?>"><?php echo esc_html( $source['label'] ); ?></td>
									<td><span class="<?php echo esc_attr( $status_class ); ?>">
										<?php echo $term_count > 0 ? 'In Use' : 'Empty'; ?>
									</span></td>
								</tr>
								<?php
							}
							?>
						</tbody>
					</table>
					<?php
				} else {
					echo '<p><em>No taxonomies found.</em></p>';
				}
				?>

				<?php
				// ===== 3. PAGES & HIERARCHY =====
				?>
				<h2 id="section-pages"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span> Pages &amp; Hierarchy</h2>
				<?php
				// Max nesting depth from one ID/parent query resolved in memory (no per-page get_post() calls).
				$parent_map = wp_audit_remember(
					'page_parents',
					15 * MINUTE_IN_SECONDS,
					function () {
						global $wpdb;
						$rows = $wpdb->get_results(
							"SELECT ID, post_parent FROM {$wpdb->posts}
							 WHERE post_type = 'page' AND post_status = 'publish'",
							ARRAY_A
						);
						return array_map( 'intval', wp_list_pluck( $rows, 'post_parent', 'ID' ) );
					}
				);

				$max_depth = 0;
				foreach ( $parent_map as $page_id => $parent_id ) {
					$depth   = 0;
					$current = $parent_id;
					$visited = array(); // Guard against circular references.
					while ( $current > 0 && ! isset( $visited[ $current ] ) ) {
						$visited[ $current ] = true;
						$current             = isset( $parent_map[ $current ] ) ? $parent_map[ $current ] : 0;
						$depth++;
					}
					if ( $depth > $max_depth ) {
						$max_depth = $depth;
					}
				}
				?>
				<table class="wp-audit-table">
					<tbody>
						<tr>
							<td><strong>Total Published Pages</strong></td>
							<td><?php echo intval( $page_count ); ?></td>
						</tr>
						<tr>
							<td><strong>Draft Pages</strong></td>
							<td><?php echo intval( $draft_pages ); ?></td>
						</tr>
						<tr>
							<td><strong>Maximum Page Nesting Depth</strong></td>
							<td><?php echo intval( $max_depth ); ?> (0 = no nesting)</td>
						</tr>
						<tr>
							<td><strong>Page Templates in Theme</strong></td>
							<td>
								<?php echo intval( $theme_template_count ); ?> Templates
								<?php if ( ! empty( $template_usage ) || ! empty( $missing_templates ) ) : ?>
									<table class="wp-audit-table" style="margin: 10px 0 0; font-size: 12px;">
										<thead>
											<tr><th>Template Name</th><th>Pages Using It</th></tr>
										</thead>
										<tbody>
											<?php foreach ( $template_usage as $file => $template ) : ?>
												<tr>
													<td title="<?php echo esc_attr( $file ); ?>"><?php echo esc_html( $template['name'] ); ?></td>
													<td>
														<?php if ( $template['count'] > 0 ) : ?>
															<span class="audit-status-good"><?php echo intval( $template['count'] ); ?></span>
														<?php else : ?>
															<span class="audit-status-warning">0 (unused)</span>
														<?php endif; ?>
													</td>
												</tr>
											<?php endforeach; ?>
											<?php foreach ( $missing_templates as $file => $count ) : ?>
												<tr>
													<td><code><?php echo esc_html( $file ); ?></code></td>
													<td><span class="audit-status-bad" title="Assigned to pages, but the theme no longer has this template; those pages fall back to the default template."><?php echo intval( $count ); ?> (template missing from theme)</span></td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								<?php else : ?>
									<p style="margin: 5px 0 0;">(No templates detected)</p>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>

				<?php
				// ===== 4. FORMS =====
				?>
				<h2 id="section-forms"><span class="dashicons dashicons-feedback" aria-hidden="true"></span> Forms &amp; Form Plugins</h2>
				<table class="wp-audit-table">
					<thead>
						<tr>
							<th>Form Plugin</th>
							<th>Total Forms</th>
							<th>Active/Published</th>
							<th>Inactive/Draft</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! empty( $forms_summary ) ) : ?>
							<?php foreach ( $forms_summary as $plugin => $data ) : ?>
								<tr>
									<td><?php echo esc_html( $plugin ); ?></td>
									<td><?php echo intval( $data['total'] ); ?></td>
									<td><span class="audit-status-good"><?php echo intval( $data['active'] ); ?></span></td>
									<td><span class="<?php echo $data['inactive'] > 0 ? 'audit-status-warning' : 'audit-status-good'; ?>"><?php echo intval( $data['inactive'] ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr><td colspan="4"><em>No supported form plugins detected (Gravity Forms, CF7, WPForms, Formidable).</em></td></tr>
						<?php endif; ?>
					</tbody>
				</table>

				<?php
				// ===== 5. DATABASE HEALTH =====
				?>
				<h2 id="section-database"><span class="dashicons dashicons-database" aria-hidden="true"></span> Database &amp; Content Quality</h2>
				<table class="wp-audit-table">
					<tbody>
						<tr>
							<td><strong>Orphaned Postmeta Entries</strong></td>
							<td><span class="<?php echo $db['orphaned_postmeta'] > 0 ? 'audit-status-bad' : 'audit-status-good'; ?>">
								<?php echo intval( $db['orphaned_postmeta'] ); ?>
							</span></td>
						</tr>
						<tr>
							<td><strong>Orphaned Term Relationships</strong></td>
							<td><span class="<?php echo $db['orphaned_terms'] > 0 ? 'audit-status-bad' : 'audit-status-good'; ?>">
								<?php echo intval( $db['orphaned_terms'] ); ?>
							</span></td>
						</tr>
						<tr>
							<td><strong>Posts with Excessive Meta (&gt;50 entries)</strong></td>
							<td><span class="<?php echo $db['excessive_meta'] > 0 ? 'audit-status-warning' : 'audit-status-good'; ?>">
								<?php echo intval( $db['excessive_meta'] ); ?>
							</span></td>
						</tr>
						<tr>
							<td><strong>Database Size (MB)</strong></td>
							<td><?php echo esc_html( $db['size_mb'] ); ?></td>
						</tr>
						<tr>
							<td><strong>Total Tables</strong></td>
							<td><?php echo intval( $db['table_count'] ); ?></td>
						</tr>
					</tbody>
				</table>

				<?php
				// ===== 6. POSTMETA ANALYSIS =====
				?>
				<h2 id="section-postmeta"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span> Custom Post Meta Analysis</h2>
				<?php
				$postmeta_keys = wp_audit_get_postmeta_keys();

				if ( ! empty( $postmeta_keys ) ) {
					?>
					<table class="wp-audit-table">
						<thead>
							<tr>
								<th>Meta Key</th>
								<th>Posts Using It</th>
								<th>Total Entries</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $postmeta_keys as $meta ) {
								?>
								<tr>
									<td><strong><?php echo esc_html( $meta->meta_key ); ?></strong></td>
									<td><?php echo intval( $meta->usage_count ); ?></td>
									<td><?php echo intval( $meta->total_entries ); ?></td>
									<td><span class="audit-status-good">In Use</span></td>
								</tr>
								<?php
							}
							?>
						</tbody>
					</table>
					<?php
				} else {
					echo '<p><em>No public-facing custom postmeta found.</em></p>';
				}
				?>

				<?php
				// ===== 7. PLUGINS =====
				?>
				<h2 id="section-plugins"><span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span> Plugin &amp; Dependency Audit</h2>
				<?php
				$plugins_needing_updates = 0;
				foreach ( array_keys( $all_plugins ) as $plugin_file ) {
					if ( isset( $plugin_updates->response[ $plugin_file ] ) ) {
						$plugins_needing_updates++;
					}
				}
				?>
				<div class="audit-summary">
					<strong>Updates Available:</strong>
					<span class="<?php echo $plugins_needing_updates > 0 ? 'audit-status-warning' : 'audit-status-good'; ?>"><?php echo intval( $plugins_needing_updates ); ?></span>
					of <?php echo intval( count( $all_plugins ) ); ?> installed plugins (from WordPress's own update check)
				</div>

				<?php
				$plugin_groups = array(
					'Active'   => array_intersect_key( $all_plugins, array_flip( $active_plugins ) ),
					'Inactive' => $inactive_plugins,
				);
				foreach ( $plugin_groups as $group_label => $group_plugins ) :
					?>
					<div class="audit-section-header"><?php echo esc_html( $group_label ); ?> Plugins (<?php echo intval( count( $group_plugins ) ); ?>)</div>
					<?php if ( ! empty( $group_plugins ) ) : ?>
						<table class="wp-audit-table">
							<thead>
								<tr>
									<th>Plugin Name</th>
									<th>Version</th>
									<th>Author</th>
									<th>Updates</th>
									<th>Status</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $group_plugins as $plugin_file => $plugin_data ) : ?>
									<?php list( $update_class, $update_label ) = wp_audit_plugin_update_status( $plugin_file, $plugin_updates ); ?>
									<tr>
										<td><strong><?php echo esc_html( $plugin_data['Name'] ); ?></strong></td>
										<td><?php echo esc_html( $plugin_data['Version'] ); ?></td>
										<td><?php echo ! empty( $plugin_data['Author'] ) ? esc_html( wp_strip_all_tags( $plugin_data['Author'] ) ) : 'N/A'; ?></td>
										<td><span class="<?php echo esc_attr( $update_class ); ?>"><?php echo esc_html( $update_label ); ?></span></td>
										<td><span class="<?php echo 'Active' === $group_label ? 'audit-status-good' : 'audit-status-warning'; ?>"><?php echo esc_html( $group_label ); ?></span></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><em>No <?php echo esc_html( strtolower( $group_label ) ); ?> plugins found.</em></p>
					<?php endif; ?>
				<?php endforeach; ?>

				<?php
				// ===== 8. THEME =====
				?>
				<h2 id="section-theme"><span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span> Theme &amp; Front-End</h2>
				<table class="wp-audit-table">
					<tbody>
						<tr>
							<td><strong>Active Theme</strong></td>
							<td><?php echo esc_html( $active_theme->get( 'Name' ) ); ?></td>
						</tr>
						<tr>
							<td><strong>Theme Version</strong></td>
							<td><?php echo esc_html( $active_theme->get( 'Version' ) ); ?></td>
						</tr>
						<tr>
							<td><strong>Theme Author</strong></td>
							<td><?php echo esc_html( $active_theme->get( 'Author' ) ); ?></td>
						</tr>
						<tr>
							<td><strong>Parent Theme</strong></td>
							<td><?php echo $parent_theme ? esc_html( $parent_theme->get( 'Name' ) ) : 'None (Custom Theme)'; ?></td>
						</tr>
						<tr>
							<td><strong>Theme Type</strong></td>
							<td><?php echo $active_theme->is_block_theme() ? 'Block theme (Full Site Editing)' : 'Classic theme'; ?></td>
						</tr>
					</tbody>
				</table>

				<?php
				// ===== 9. USERS & ROLES =====
				?>
				<h2 id="section-users"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span> User Roles, Permissions &amp; User Management</h2>
				<?php
				$total_users   = count_users();
				$user_roles    = wp_roles()->roles;
				$default_roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
				?>
				<table class="wp-audit-table">
					<thead>
						<tr>
							<th>Role</th>
							<th>Users</th>
							<th>Capabilities</th>
							<th>Type</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><strong>Total Users</strong></td>
							<td><strong><?php echo intval( $total_users['total_users'] ); ?></strong></td>
							<td></td>
							<td></td>
						</tr>
						<?php
						foreach ( $user_roles as $role => $role_data ) {
							$role_user_count = isset( $total_users['avail_roles'][ $role ] ) ? $total_users['avail_roles'][ $role ] : 0;
							$role_caps       = count( array_filter( (array) $role_data['capabilities'] ) );
							?>
							<tr>
								<td><?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?> <code><?php echo esc_html( $role ); ?></code></td>
								<td><?php echo intval( $role_user_count ); ?></td>
								<td><?php echo intval( $role_caps ); ?></td>
								<td><?php echo in_array( $role, $default_roles, true ) ? 'Default' : '<span class="audit-status-warning">Custom</span>'; ?></td>
							</tr>
							<?php
						}
						?>
					</tbody>
				</table>

				<?php
				// ===== 10. MEDIA =====
				?>
				<h2 id="section-media"><span class="dashicons dashicons-format-image" aria-hidden="true"></span> Media &amp; Assets</h2>
				<?php
				// Orphaned media is a heuristic: attachments whose parent post no longer exists.
				$media_stats = wp_audit_get_media_stats();
				?>
				<table class="wp-audit-table">
					<tbody>
						<tr>
							<td><strong>Total Media Files</strong></td>
							<td><?php echo intval( $total_media ); ?></td>
						</tr>
						<tr>
							<td><strong>Images</strong></td>
							<td><?php echo intval( $media_stats['images'] ); ?></td>
						</tr>
						<tr>
							<td><strong>Orphaned Media (Attached to non-existent posts)</strong></td>
							<td><span class="<?php echo $media_stats['orphaned'] > 0 ? 'audit-status-warning' : 'audit-status-good'; ?>">
								<?php echo intval( $media_stats['orphaned'] ); ?>
							</span></td>
						</tr>
					</tbody>
				</table>

				<?php
				// ===== 11. URL STRUCTURE & PERMALINKS =====
				?>
				<h2 id="section-urls"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> URL Structure &amp; Permalinks</h2>
				<?php
				$permalink_structure = get_option( 'permalink_structure' );
				$rewrite_rules       = get_option( 'rewrite_rules' );
				?>
				<table class="wp-audit-table">
					<tbody>
						<tr>
							<td><strong>Home URL</strong></td>
							<td><?php echo esc_url( home_url() ); ?></td>
						</tr>
						<tr>
							<td><strong>Site URL</strong></td>
							<td><?php echo esc_url( site_url() ); ?></td>
						</tr>
						<tr>
							<td><strong>Permalink Structure</strong></td>
							<td><code><?php echo esc_html( $permalink_structure ? $permalink_structure : 'Default (/?p=123)' ); ?></code></td>
						</tr>
						<tr>
							<td><strong>Category Base</strong></td>
							<td><code><?php echo esc_html( get_option( 'category_base' ) ? get_option( 'category_base' ) : 'category (default)' ); ?></code></td>
						</tr>
						<tr>
							<td><strong>Tag Base</strong></td>
							<td><code><?php echo esc_html( get_option( 'tag_base' ) ? get_option( 'tag_base' ) : 'tag (default)' ); ?></code></td>
						</tr>
						<tr>
							<td><strong>Rewrite Rules</strong></td>
							<td><?php echo is_array( $rewrite_rules ) ? intval( count( $rewrite_rules ) ) : 'Not generated (plain permalinks)'; ?></td>
						</tr>
					</tbody>
				</table>

				<?php
				// ===== 12. SECURITY AUDIT =====
				?>
				<h2 id="section-security"><span class="dashicons dashicons-shield" aria-hidden="true"></span> Security Audit</h2>

				<div class="audit-summary">
					<strong>Purpose:</strong> This section checks for common security vulnerabilities and configuration issues that could expose your site to attacks.
				</div>

				<?php
				$wp_version_check = wp_audit_check_wp_version();
				$ssl_check        = wp_audit_check_ssl_certificate();
				$file_perms       = wp_audit_check_file_permissions();
				$environment      = wp_get_environment_type();
				$is_production    = 'production' === $environment;
				?>

				<h3 style="font-size: 16px; margin-top: 25px; margin-bottom: 10px; color: var(--ch-color-1, #1d2327);">WordPress Core Version</h3>
				<table class="wp-audit-table">
					<tbody>
						<tr>
							<td><strong>Current Version</strong></td>
							<td><?php echo esc_html( $wp_version_check['current'] ); ?></td>
						</tr>
						<tr>
							<td><strong>Latest Version</strong></td>
							<td><?php echo esc_html( $wp_version_check['latest'] ); ?></td>
						</tr>
						<tr>
							<td><strong>Status</strong></td>
							<td>
								<?php if ( $wp_version_check['security_risk'] ) : ?>
									<span class="audit-status-bad">⚠ Critically Outdated - Security Risk!</span>
								<?php elseif ( $wp_version_check['outdated'] ) : ?>
									<span class="audit-status-warning">⚠ Update Available</span>
								<?php else : ?>
									<span class="audit-status-good">✓ Up to Date</span>
								<?php endif; ?>
							</td>
						</tr>
						<?php if ( isset( $wp_version_check['error'] ) ) : ?>
						<tr>
							<td><strong>Note</strong></td>
							<td><span class="audit-status-warning">Could not check latest version: <?php echo esc_html( $wp_version_check['error'] ); ?></span></td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>

				<h3 style="font-size: 16px; margin-top: 25px; margin-bottom: 10px; color: var(--ch-color-1, #1d2327);">SSL Certificate</h3>
				<table class="wp-audit-table">
					<tbody>
						<tr>
							<td><strong>SSL Enabled</strong></td>
							<td><span class="<?php echo $ssl_check['enabled'] ? 'audit-status-good' : 'audit-status-bad'; ?>">
								<?php echo $ssl_check['enabled'] ? '✓ Yes' : '✗ No'; ?>
							</span></td>
						</tr>
						<?php if ( $ssl_check['enabled'] && ! isset( $ssl_check['error'] ) ) : ?>
						<tr>
							<td><strong>Certificate Issuer</strong></td>
							<td><?php echo esc_html( $ssl_check['issuer'] ); ?></td>
						</tr>
						<tr>
							<td><strong>Certificate Subject</strong></td>
							<td><?php echo esc_html( $ssl_check['subject'] ); ?></td>
						</tr>
						<tr>
							<td><strong>Expiry Date</strong></td>
							<td>
								<span class="<?php echo $ssl_check['expires_soon'] ? 'audit-status-warning' : 'audit-status-good'; ?>">
									<?php echo esc_html( $ssl_check['expires'] ); ?>
									<?php if ( $ssl_check['expires_soon'] ) : ?>
										(⚠ Expires in <?php echo intval( $ssl_check['days_until_expiry'] ); ?> days!)
									<?php else : ?>
										(<?php echo intval( $ssl_check['days_until_expiry'] ); ?> days remaining)
									<?php endif; ?>
								</span>
							</td>
						</tr>
						<?php elseif ( isset( $ssl_check['error'] ) ) : ?>
						<tr>
							<td><strong>Note</strong></td>
							<td><span class="audit-status-warning"><?php echo esc_html( $ssl_check['error'] ); ?><?php echo isset( $ssl_check['message'] ) ? ': ' . esc_html( $ssl_check['message'] ) : ''; ?></span></td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>

				<h3 style="font-size: 16px; margin-top: 25px; margin-bottom: 10px; color: var(--ch-color-1, #1d2327);">File Permissions</h3>
				<?php if ( null === $file_perms ) : ?>
					<p><em>Skipped: this server runs Windows, where Unix file permissions don't apply. Check permissions on the production host.</em></p>
				<?php else : ?>
					<?php
					$perm_labels = array(
						'good'    => '✓ Secure',
						'warning' => '⚠ Review',
						'bad'     => '✗ Insecure',
					);
					?>
					<table class="wp-audit-table">
						<thead>
							<tr>
								<th>File/Directory</th>
								<th>Current Permissions</th>
								<th>Recommended</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $file_perms as $perm ) : ?>
							<tr>
								<td><code><?php echo esc_html( $perm['path'] ); ?></code></td>
								<td><code><?php echo esc_html( $perm['permissions'] ); ?></code></td>
								<td><code><?php echo esc_html( $perm['recommended'] ); ?></code></td>
								<td><span class="audit-status-<?php echo esc_attr( $perm['status'] ); ?>">
									<?php echo esc_html( $perm_labels[ $perm['status'] ] ); ?>
								</span></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<h3 style="font-size: 16px; margin-top: 25px; margin-bottom: 10px; color: var(--ch-color-1, #1d2327);">WordPress Configuration</h3>
				<?php
				// Debug settings are normal on local/staging, so only escalate them to "bad" on production.
				$debug_on      = defined( 'WP_DEBUG' ) && WP_DEBUG;
				$debug_display = $debug_on && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY );
				$debug_log     = defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false;
				$script_debug  = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
				$file_edit_off = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
				$prod_issue    = $is_production ? 'audit-status-bad' : 'audit-status-warning';
				?>
				<table class="wp-audit-table">
					<tbody>
						<tr>
							<td><strong>Environment Type</strong></td>
							<td><code><?php echo esc_html( $environment ); ?></code></td>
						</tr>
						<tr>
							<td><strong>WP_DEBUG</strong></td>
							<td><span class="<?php echo $debug_on ? esc_attr( $prod_issue ) : 'audit-status-good'; ?>"><?php echo $debug_on ? 'On' : 'Off'; ?></span></td>
						</tr>
						<tr>
							<td><strong>Errors Displayed On Screen</strong> <small>(WP_DEBUG_DISPLAY)</small></td>
							<td><span class="<?php echo $debug_display ? esc_attr( $prod_issue ) : 'audit-status-good'; ?>"><?php echo $debug_display ? 'Yes — visitors can see PHP errors and file paths' : 'No'; ?></span></td>
						</tr>
						<tr>
							<td><strong>Debug Log</strong> <small>(WP_DEBUG_LOG)</small></td>
							<td>
								<?php if ( true === $debug_log || '1' === $debug_log ) : ?>
									<span class="audit-status-warning">On — writes to <code>wp-content/debug.log</code>, which is publicly downloadable unless the server blocks it</span>
								<?php elseif ( is_string( $debug_log ) && '' !== $debug_log ) : ?>
									<span class="audit-status-good">On — custom path <code><?php echo esc_html( $debug_log ); ?></code></span>
								<?php else : ?>
									<span class="audit-status-good">Off</span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td><strong>SCRIPT_DEBUG</strong></td>
							<td><span class="<?php echo $script_debug ? 'audit-status-warning' : 'audit-status-good'; ?>"><?php echo $script_debug ? 'On — loads unminified core JS/CSS' : 'Off'; ?></span></td>
						</tr>
						<tr>
							<td><strong>Admin File Editor</strong> <small>(DISALLOW_FILE_EDIT)</small></td>
							<td><span class="<?php echo $file_edit_off ? 'audit-status-good' : 'audit-status-warning'; ?>"><?php echo $file_edit_off ? 'Disabled' : 'Enabled — admins can edit theme/plugin PHP from wp-admin'; ?></span></td>
						</tr>
					</tbody>
				</table>

				<h3 style="font-size: 16px; margin-top: 25px; margin-bottom: 10px; color: var(--ch-color-1, #1d2327);">Security Recommendations</h3>
				<div class="audit-summary">
					<ul style="margin: 10px 0 10px 20px; list-style: disc;">
						<li><strong>Keep WordPress Updated:</strong> Always update to the latest stable version to patch security vulnerabilities.</li>
						<li><strong>Use Strong Passwords:</strong> Ensure all user accounts use strong, unique passwords.</li>
						<li><strong>Limit Login Attempts:</strong> Install a plugin like Wordfence or Limit Login Attempts to prevent brute force attacks.</li>
						<li><strong>Two-Factor Authentication:</strong> Enable 2FA for admin accounts for an extra layer of security.</li>
						<li><strong>Regular Backups:</strong> Maintain regular backups stored offsite (e.g., UpdraftPlus, BackupBuddy).</li>
						<li><strong>Disable File Editing:</strong> Add <code>define('DISALLOW_FILE_EDIT', true);</code> to wp-config.php to prevent theme/plugin editing from admin.</li>
						<li><strong>Security Headers:</strong> Implement security headers like X-Frame-Options, X-Content-Type-Options, and Content-Security-Policy.</li>
					</ul>
				</div>

				<?php
				// ===== 13. PERFORMANCE & TECHNICAL =====
				?>
				<h2 id="section-performance"><span class="dashicons dashicons-performance" aria-hidden="true"></span> Performance &amp; Technical</h2>
				<?php
				$php_version     = phpversion();
				$memory_limit    = ini_get( 'memory_limit' );
				$max_upload_mb   = round( wp_max_upload_size() / 1024 / 1024, 2 );
				$wp_memory_limit = WP_MEMORY_LIMIT;
				$ssl_enabled     = is_ssl() ? 'Yes' : 'No';
				$caching_found   = wp_audit_detect_caching( $active_plugins );
				$caching_plugin  = ! empty( $caching_found );

				// PHP 8.1 reached end of life Dec 2025; 8.3+ is what current WordPress recommends.
				if ( version_compare( $php_version, '8.3', '>=' ) ) {
					$php_class = 'audit-status-good';
				} elseif ( version_compare( $php_version, '8.1', '>=' ) ) {
					$php_class = 'audit-status-warning';
				} else {
					$php_class = 'audit-status-bad';
				}
				?>
				<table class="wp-audit-table">
					<tbody>
						<tr>
							<td><strong>PHP Version</strong></td>
							<td><span class="<?php echo esc_attr( $php_class ); ?>">
								<?php echo esc_html( $php_version ); ?> (Recommend 8.3+)
							</span></td>
						</tr>
						<tr>
							<td><strong>Memory Limit</strong></td>
							<td><?php echo esc_html( $memory_limit ); ?></td>
						</tr>
						<tr>
							<td><strong>WordPress Memory Limit</strong></td>
							<td><?php echo esc_html( $wp_memory_limit ); ?></td>
						</tr>
						<tr>
							<td><strong>Max Upload Size</strong></td>
							<td><?php echo esc_html( $max_upload_mb ); ?> MB</td>
						</tr>
						<tr>
							<td><strong>SSL Enabled</strong></td>
							<td><span class="<?php echo 'Yes' === $ssl_enabled ? 'audit-status-good' : 'audit-status-bad'; ?>">
								<?php echo esc_html( $ssl_enabled ); ?>
							</span></td>
						</tr>
						<tr>
							<td><strong>Caching</strong></td>
							<td><span class="<?php echo $caching_plugin ? 'audit-status-good' : 'audit-status-warning'; ?>">
								<?php echo $caching_plugin ? esc_html( implode( ', ', $caching_found ) ) : 'None detected'; ?>
							</span></td>
						</tr>
					</tbody>
				</table>

				<h3 style="font-size: 16px; margin-top: 25px; margin-bottom: 10px; color: var(--ch-color-1, #1d2327);">PageSpeed Insights &amp; Core Web Vitals</h3>
				<div class="audit-summary">
					<strong>Note:</strong> PageSpeed data loads from Google's API after the rest of the report, and can take 20–40 seconds. Results are cached for 24 hours.
				</div>
				<div id="wp-audit-pagespeed" aria-live="polite">
					<p class="wp-audit-loading"><span class="spinner is-active"></span> Fetching PageSpeed Insights (desktop and mobile)…</p>
				</div>

				<?php
				// ===== 14. SEO & METADATA =====
				?>
				<h2 id="section-seo"><span class="dashicons dashicons-search" aria-hidden="true"></span> SEO &amp; Metadata</h2>
				<?php
				$posts_with_no_title = wp_audit_remember(
					'missing_titles',
					15 * MINUTE_IN_SECONDS,
					function () {
						global $wpdb;
						return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post', 'page') AND post_status = 'publish' AND (post_title = '' OR post_title IS NULL)" );
					}
				);

				$seo_plugin_name = wp_audit_first_detected(
					array(
						'Yoast SEO'         => defined( 'WPSEO_VERSION' ),
						'Rank Math'         => defined( 'RANK_MATH_VERSION' ),
						'All in One SEO'    => defined( 'AIOSEO_VERSION' ),
						'SEOPress'          => defined( 'SEOPRESS_VERSION' ),
						'The SEO Framework' => defined( 'THE_SEO_FRAMEWORK_VERSION' ),
					)
				);
				$seo_plugin     = '' !== $seo_plugin_name;
				$search_visible = get_option( 'blog_public' );
				$robots         = wp_audit_check_robots();
				?>
				<table class="wp-audit-table">
					<tbody>
						<tr>
							<td><strong>SEO Plugin Installed</strong></td>
							<td><span class="<?php echo $seo_plugin ? 'audit-status-good' : 'audit-status-warning'; ?>">
								<?php echo $seo_plugin ? esc_html( $seo_plugin_name ) : 'None Detected'; ?>
							</span></td>
						</tr>
						<tr>
							<td><strong>Search Engine Visibility</strong></td>
							<td><span class="<?php echo $search_visible ? 'audit-status-good' : 'audit-status-bad'; ?>">
								<?php echo $search_visible ? 'Public' : 'Discouraged'; ?>
							</span></td>
						</tr>
						<tr>
							<td><strong>robots.txt</strong></td>
							<td>
								<?php echo esc_html( $robots['source'] ); ?>
								<?php if ( ! $robots['reachable'] ) : ?>
									<br><span class="audit-status-warning">Could not fetch: <?php echo esc_html( isset( $robots['error'] ) ? $robots['error'] : 'unknown error' ); ?></span>
								<?php elseif ( $robots['blocks_all'] ) : ?>
									<br><span class="audit-status-bad">Contains "Disallow: /" — blocks search engines from the whole site</span>
								<?php else : ?>
									<br><span class="audit-status-good">Does not block the whole site</span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td><strong>Sitemap in robots.txt</strong></td>
							<td>
								<?php if ( ! empty( $robots['sitemaps'] ) ) : ?>
									<?php foreach ( $robots['sitemaps'] as $sitemap ) : ?>
										<code><?php echo esc_html( $sitemap ); ?></code><br>
									<?php endforeach; ?>
								<?php elseif ( $robots['reachable'] ) : ?>
									<span class="audit-status-warning">No Sitemap: directive</span>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td><strong>Posts with Missing Titles</strong></td>
							<td><span class="<?php echo $posts_with_no_title > 0 ? 'audit-status-bad' : 'audit-status-good'; ?>">
								<?php echo intval( $posts_with_no_title ); ?>
							</span></td>
						</tr>
					</tbody>
				</table>
				<?php
				// ===== 15. RFQ REQUIREMENTS SUMMARY =====
				?>
				<h2 id="section-rfq"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> RFQ Requirements Summary</h2>
				<div class="audit-summary">
					<strong>Purpose:</strong> This section consolidates key information needed for a Request for Quote (RFQ) when planning a website redesign or rebuild. Use this data to communicate requirements to developers and agencies.
				</div>

				<?php
				// Calculate additional RFQ-specific data

				$has_woocommerce = class_exists( 'WooCommerce' );
				$woo_products    = wp_audit_count_status( $post_counts, 'product', 'publish' );

				$membership_plugin = wp_audit_first_detected(
					array(
						'MemberPress'               => defined( 'MEPR_VERSION' ),
						'Paid Memberships Pro'      => defined( 'PMPRO_VERSION' ),
						'WooCommerce Subscriptions' => class_exists( 'WC_Subscriptions' ),
						'WooCommerce Memberships'   => function_exists( 'wc_memberships' ),
						'Restrict Content Pro'      => defined( 'RCP_PLUGIN_VERSION' ),
					)
				);
				if ( ! $membership_plugin ) {
					$membership_plugin = wp_audit_find_active_plugin_by_name( $active_plugins, $all_plugins, '/\bmembership/i' );
				}
				$has_memberships = '' !== $membership_plugin;

				$events_plugin = wp_audit_first_detected(
					array(
						'The Events Calendar'    => class_exists( 'Tribe__Events__Main' ),
						'Events Manager'         => defined( 'EM_VERSION' ),
						'Modern Events Calendar' => class_exists( 'MEC' ),
						'EventON'                => class_exists( 'EventON' ),
					)
				);
				if ( ! $events_plugin ) {
					$events_plugin = wp_audit_find_active_plugin_by_name( $active_plugins, $all_plugins, '/\b(events?\s+calendar|calendar)\b/i' );
				}
				$has_events   = '' !== $events_plugin;
				$events_count = 0;
				foreach ( array( 'tribe_events', 'event', 'mec-events', 'ajde_events' ) as $event_type ) {
					$events_count += wp_audit_count_status( $post_counts, $event_type, 'publish' );
				}

				$multilingual_plugin = wp_audit_find_active_plugin_by_name( $active_plugins, $all_plugins, '/\b(wpml|polylang|translatepress|weglot)\b/i' );
				$has_multilingual    = '' !== $multilingual_plugin;

				$search_plugin     = wp_audit_find_active_plugin_by_name( $active_plugins, $all_plugins, '/facet|searchwp|relevanssi|search\s*(&|&amp;|and)\s*filter/i' );
				$has_search_filter = '' !== $search_plugin;

				// Word boundary keeps "XML Sitemaps" from counting as a map integration.
				$has_maps = '' !== wp_audit_find_active_plugin_by_name( $active_plugins, $all_plugins, '/\bmaps?\b/i' );

				// Check hosting type (basic detection)
				$hosting_type = 'Unknown';
				if ( defined( 'WPE_APIKEY' ) ) {
					$hosting_type = 'WP Engine';
				} elseif ( defined( 'FLYWHEEL_CONFIG_DIR' ) ) {
					$hosting_type = 'Flywheel';
				} elseif ( defined( 'KINSTA_CACHE_ZONE' ) ) {
					$hosting_type = 'Kinsta';
				} elseif ( defined( 'IS_PRESSABLE' ) ) {
					$hosting_type = 'Pressable';
				} elseif ( strpos( $_SERVER['SERVER_SOFTWARE'] ?? '', 'LiteSpeed' ) !== false ) {
					$hosting_type = 'LiteSpeed (Possibly Shared)';
				} elseif ( getenv( 'PANTHEON_ENVIRONMENT' ) ) {
					$hosting_type = 'Pantheon';
				}
				?>

				<div class="audit-section-header">HIGH PRIORITY Requirements</div>
				
				<table class="wp-audit-table">
					<thead>
						<tr>
							<th style="width: 40%;">RFQ Requirement</th>
							<th style="width: 35%;">Current Site Data</th>
							<th style="width: 25%;">Notes for RFQ</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><strong>Site Functionality Requirements</strong></td>
							<td>
								<?php
								$functionality = [];
								if ($blog_posts > 0) $functionality[] = 'Blog (' . $blog_posts . ' posts)';
								if ($has_woocommerce) $functionality[] = 'E-commerce (' . $woo_products . ' products)';
								if ($has_events) $functionality[] = 'Events Calendar (' . $events_count . ' events)';
								if ($has_search_filter) $functionality[] = 'Advanced Search/Filter';
								if ($has_maps) $functionality[] = 'Map Integration';
								if ($has_memberships) $functionality[] = 'Membership/Subscriptions';
								if (empty($functionality)) {
									echo '<span class="audit-status-warning">Basic site (no advanced features detected)</span>';
								} else {
									echo '<ul style="margin: 5px 0; padding-left: 20px;">';
									foreach ($functionality as $func) {
										echo '<li>' . esc_html($func) . '</li>';
									}
									echo '</ul>';
								}
								?>
							</td>
							<td><em>Specify if all features should be retained or modified</em></td>
						</tr>
						
						<tr>
							<td><strong>Number of Page Templates</strong></td>
							<td>
								<strong><?php echo intval($theme_template_count); ?></strong> templates currently in theme
								<?php if ($theme_template_count > 0): ?>
									<details style="margin-top: 8px;">
										<summary style="cursor: pointer; color: #0073aa;">View template usage</summary>
										<ul style="margin: 5px 0; padding-left: 20px; font-size: 12px;">
											<?php foreach ( $template_usage as $template ) : ?>
												<li><?php echo esc_html( $template['name'] ); ?>: <?php echo intval( $template['count'] ); ?> pages</li>
											<?php endforeach; ?>
										</ul>
									</details>
								<?php endif; ?>
							</td>
							<td><em>Standard templates: Homepage, Internal, Archive, Single</em></td>
						</tr>
						
						<tr>
							<td><strong>Custom Post Types Required</strong></td>
							<td>
								<strong><?php echo intval($total_cpt_count); ?></strong> custom post types detected
								<?php if ( ! empty( $cpt_counts ) ) : ?>
									<ul style="margin: 5px 0; padding-left: 20px;">
										<?php foreach ( $cpt_counts as $type => $count ) : ?>
											<li><?php echo esc_html( $type ); ?>: <strong><?php echo intval( $count ); ?></strong> published</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</td>
							<td><em>Identify which to migrate vs. rebuild</em></td>
						</tr>
						
						<tr>
							<td><strong>Number of Forms & Requirements</strong></td>
							<td>
								<?php if (!empty($forms_summary)): ?>
									<strong><?php echo intval($total_forms); ?></strong> total forms
									<ul style="margin: 5px 0; padding-left: 20px;">
										<?php foreach ($forms_summary as $plugin => $data): ?>
											<li><?php echo esc_html($plugin); ?>: <?php echo intval($data['total']); ?> forms (<?php echo intval($data['active']); ?> active)</li>
										<?php endforeach; ?>
									</ul>
								<?php else: ?>
									<span class="audit-status-warning">No form plugins detected</span>
								<?php endif; ?>
							</td>
							<td><em>Review each form for: validation, conditional logic, integrations, file uploads</em></td>
						</tr>
						
						<tr>
							<td><strong>Content Migration Volume</strong></td>
							<td>
								<ul style="margin: 5px 0; padding-left: 20px;">
									<li>Pages: <strong><?php echo intval($page_count); ?></strong></li>
									<li>Blog Posts: <strong><?php echo intval($blog_posts); ?></strong></li>
									<?php if ($has_woocommerce): ?>
										<li>Products: <strong><?php echo intval($woo_products); ?></strong></li>
									<?php endif; ?>
									<li>Media Files: <strong><?php echo intval($total_media); ?></strong></li>
									<?php if (!empty($top_cpts)): ?>
										<?php foreach ( $top_cpts as $type => $count ) : ?>
											<li><?php echo esc_html( ucwords( str_replace( '_', ' ', $type ) ) ); ?>: <strong><?php echo intval( $count ); ?></strong></li>
										<?php endforeach; ?>
									<?php endif; ?>
								</ul>
							</td>
							<td><em>Consider automated migration vs. manual content recreation</em></td>
						</tr>
						
						<tr>
							<td><strong>Project Budget & Timeline</strong></td>
							<td colspan="2" style="background: #fffbcc;">
								<em>⚠️ To be defined by stakeholders - Document budget range and desired completion date</em>
							</td>
						</tr>
					</tbody>
				</table>

				<div class="audit-section-header">MEDIUM PRIORITY Requirements</div>
				
				<table class="wp-audit-table">
					<tbody>
						<tr>
							<td style="width: 40%;"><strong>Necessary Plugins</strong></td>
							<td style="width: 35%;">
								<strong><?php echo count($active_plugins); ?></strong> active plugins
								<details style="margin-top: 8px;">
									<summary style="cursor: pointer; color: #0073aa;">View critical plugins list</summary>
									<ul style="margin: 5px 0; padding-left: 20px; font-size: 12px;">
										<?php
										$critical_keywords = ['form', 'seo', 'cache', 'security', 'woocommerce', 'backup', 'migration'];
										foreach ($active_plugins as $plugin) {
											if (!isset($all_plugins[$plugin]['Name'])) continue;
											$plugin_name = $all_plugins[$plugin]['Name'];
											$is_critical = false;
											foreach ($critical_keywords as $keyword) {
												if (stripos($plugin_name, $keyword) !== false) {
													$is_critical = true;
													break;
												}
											}
											if ($is_critical) {
												echo '<li>' . esc_html($plugin_name) . '</li>';
											}
										}
										?>
									</ul>
								</details>
							</td>
							<td style="width: 25%;"><em>Identify must-have vs. nice-to-have plugins</em></td>
						</tr>
						
						<tr>
							<td><strong>Current Hosting Environment</strong></td>
							<td>
								<strong><?php echo esc_html($hosting_type); ?></strong>
								<ul style="margin: 5px 0; padding-left: 20px;">
									<li>PHP: <?php echo esc_html($php_version); ?></li>
									<li>WP Memory: <?php echo esc_html($wp_memory_limit); ?></li>
									<li>Max Upload: <?php echo esc_html($max_upload_mb); ?> MB</li>
									<li>SSL: <?php echo $ssl_enabled === 'Yes' ? '<span class="audit-status-good">Enabled</span>' : '<span class="audit-status-bad">Disabled</span>'; ?></li>
								</ul>
							</td>
							<td><em>New host should match or exceed these specs</em></td>
						</tr>
						
						<tr>
							<td><strong>CMS Training Needs</strong></td>
							<td>
								<strong><?php echo intval($total_users['total_users']); ?></strong> total users
								<ul style="margin: 5px 0; padding-left: 20px;">
									<?php foreach ($total_users['avail_roles'] as $role => $count): ?>
										<li><?php echo esc_html(ucwords(str_replace('_', ' ', $role))); ?>: <?php echo intval($count); ?></li>
									<?php endforeach; ?>
								</ul>
							</td>
							<td><em>Plan training for editors and admins</em></td>
						</tr>
						
						<tr>
							<td><strong>Design Requirements</strong></td>
							<td>
								Current Theme: <strong><?php echo esc_html($active_theme->get('Name')); ?></strong>
								<?php if ($parent_theme): ?>
									<br><small>(Child of: <?php echo esc_html($parent_theme->get('Name')); ?>)</small>
								<?php endif; ?>
							</td>
							<td><em>Specify: Full redesign, modernization, or maintain existing aesthetic</em></td>
						</tr>
					</tbody>
				</table>

				<div class="audit-section-header">MEDIUM-LOW PRIORITY Requirements</div>
				
				<table class="wp-audit-table">
					<tbody>
						<tr>
							<td style="width: 40%;"><strong>SEO Optimization Status</strong></td>
							<td style="width: 35%;">
								SEO Plugin: <?php echo $seo_plugin ? '<strong>' . esc_html($seo_plugin_name) . '</strong>' : '<span class="audit-status-warning">None</span>'; ?>
								<br>Search Visibility: <?php echo $search_visible ? '<span class="audit-status-good">Public</span>' : '<span class="audit-status-bad">Hidden</span>'; ?>
								<br>Posts Missing Titles: <strong><?php echo intval($posts_with_no_title); ?></strong>
							</td>
							<td style="width: 25%;"><em>Plan for meta tags, schema markup, redirects</em></td>
						</tr>
						
						<tr>
							<td><strong>Performance Requirements</strong></td>
							<td>
								Caching: <?php echo $caching_plugin ? '<span class="audit-status-good">' . esc_html( implode( ', ', $caching_found ) ) . '</span>' : '<span class="audit-status-warning">None detected</span>'; ?>
								<br>Database Size: <strong><?php echo esc_html( $db['size_mb'] ); ?> MB</strong>
								<br>Total Media: <strong><?php echo intval($total_media); ?></strong> files
							</td>
							<td><em>Set page load time targets and image optimization requirements</em></td>
						</tr>
						
						<tr>
							<td><strong>Third-Party Integrations</strong></td>
							<td>
								<?php
								$integrations = [];
								if ($has_woocommerce) $integrations[] = 'Payment Gateway (WooCommerce)';
								if ($seo_plugin) $integrations[] = 'SEO Tools';
								if ($has_maps) $integrations[] = 'Maps';
								if ($has_events) $integrations[] = 'Events/Calendar';
								if ($has_memberships) $integrations[] = 'Membership System';
								
								// Check for common integrations
								foreach ($active_plugins as $plugin) {
									if (!isset($all_plugins[$plugin]['Name'])) continue;
									$plugin_name = strtolower($all_plugins[$plugin]['Name']);
									if (strpos($plugin_name, 'mailchimp') !== false) $integrations[] = 'Mailchimp';
									if (strpos($plugin_name, 'salesforce') !== false) $integrations[] = 'Salesforce';
									if (strpos($plugin_name, 'zapier') !== false) $integrations[] = 'Zapier';
									if (strpos($plugin_name, 'hubspot') !== false) $integrations[] = 'HubSpot';
								}
								
								$integrations = array_unique($integrations);
								
								if (!empty($integrations)) {
									echo '<ul style="margin: 5px 0; padding-left: 20px;">';
									foreach ($integrations as $integration) {
										echo '<li>' . esc_html($integration) . '</li>';
									}
									echo '</ul>';
								} else {
									echo '<span class="audit-status-warning">No major integrations detected</span>';
								}
								?>
							</td>
							<td><em>Document API keys and integration requirements</em></td>
						</tr>
						
						<tr>
							<td><strong>Analytics & Tracking</strong></td>
							<td>
								<?php
								// "Site Kit by Google" and GTM plugins don't have "analytics" in their names.
								$analytics_plugin = wp_audit_find_active_plugin_by_name( $active_plugins, $all_plugins, '/analytics|site kit|tag manager|monsterinsights|\bgtm\b/i' );
								if ( $analytics_plugin ) {
									echo 'Plugin: <strong>' . esc_html( $analytics_plugin ) . '</strong>';
								} else {
									echo '<span class="audit-status-warning">No analytics plugin detected</span><br><small>Check for code in header/footer</small>';
								}
								?>
							</td>
							<td><em>Ensure GA4 or analytics tool setup</em></td>
						</tr>
					</tbody>
				</table>

				<div class="audit-section-header">LOW PRIORITY / Additional Considerations</div>
				
				<table class="wp-audit-table">
					<tbody>
						<tr>
							<td style="width: 40%;"><strong>Maintenance & Support Plan</strong></td>
							<td colspan="2" style="background: #fffbcc;">
								<em>⚠️ Define post-launch support: Updates frequency, backup schedule, monitoring, emergency support SLA</em>
							</td>
						</tr>
						
						<tr>
							<td><strong>Multi-Language Requirements</strong></td>
							<td style="width: 35%;">
								<?php if ($has_multilingual): ?>
									<span class="audit-status-good">Active: <?php echo esc_html($multilingual_plugin); ?></span>
									<br><em>Migration of translations will be required</em>
								<?php else: ?>
									<span class="audit-status-warning">Not currently multilingual</span>
								<?php endif; ?>
							</td>
							<td style="width: 25%;"><em>Specify languages needed</em></td>
						</tr>
						
						<tr>
							<td><strong>User Roles & Permissions</strong></td>
							<td>
								<?php echo intval(count($total_users['avail_roles'])); ?> role types in use
								<br><small>Review if custom roles/capabilities are needed</small>
							</td>
							<td><em>Document permission requirements per role</em></td>
						</tr>
						
						<tr>
							<td><strong>Accessibility Compliance</strong></td>
							<td style="background: #fffbcc;" colspan="2">
								<em>⚠️ Specify if WCAG 2.1 AA or other accessibility standards are required</em>
							</td>
						</tr>
					</tbody>
				</table>

				<div class="audit-summary" style="margin-top: 20px; background: #e7f5fe; border-left-color: #0073aa;">
					<strong>📋 RFQ Export Tip:</strong> Use the "Download as Markdown" button at the top of this report to generate a complete document for your RFQ. This data provides vendors with concrete numbers to base their estimates on.
				</div>
				<?php
				// ===== 16. CLEANUP & TECHNICAL DEBT =====
				$orphaned_tables = wp_audit_find_orphaned_tables();
				$shortcode_scan  = wp_audit_find_unregistered_shortcodes();
				$inline_scan     = wp_audit_find_inline_style_bloat();
				?>
				<h2 id="section-cleanup"><span class="dashicons dashicons-trash" aria-hidden="true"></span> Cleanup &amp; Technical Debt</h2>

				<div class="audit-section-header">Database Bloat Cleanup</div>
				<?php if ( ! empty( $orphaned_tables ) ) : ?>
					<p>Found <strong><?php echo intval( count( $orphaned_tables ) ); ?></strong> non-core tables. Tables belonging to an active plugin are in use; tables from inactive or uninstalled plugins are cleanup candidates. Always take a backup before dropping anything.</p>
					<table class="wp-audit-table">
						<thead>
							<tr>
								<th>Table Name</th>
								<th>Likely Owner</th>
								<th>Recommendation</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $orphaned_tables as $table ) : ?>
								<tr>
									<td><code><?php echo esc_html( $table['table'] ); ?></code></td>
									<td><?php echo $table['owner'] ? esc_html( $table['owner'] ) : '<em>Unknown</em>'; ?></td>
									<td>
										<?php if ( true === $table['active'] ) : ?>
											<span class="audit-status-good">In use — keep</span>
										<?php elseif ( false === $table['active'] ) : ?>
											<span class="audit-status-warning">Owner not active — review for removal</span>
										<?php else : ?>
											<span class="audit-status-warning">Unknown owner — verify before removing</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p><span class="audit-status-good">No tables found beyond the WordPress core structure.</span></p>
				<?php endif; ?>

				<div class="audit-section-header">Broken Content (Shortcode Analysis)</div>
				<?php if ( ! empty( $shortcode_scan['tags'] ) ) : ?>
					<p>Found <strong><?php echo intval( count( $shortcode_scan['tags'] ) ); ?></strong> shortcode tags in published content that nothing registers. These likely display as raw <code>[tag]</code> text on the front end. (Plugins that only register shortcodes on the front end can show up here as false positives.)</p>
					<table class="wp-audit-table">
						<thead>
							<tr>
								<th>Shortcode Tag</th>
								<th>Posts Affected</th>
								<th>Occurrences</th>
								<th>Example Post</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_slice( $shortcode_scan['tags'], 0, 10, true ) as $tag => $data ) : ?>
								<tr>
									<td><code>[<?php echo esc_html( $tag ); ?>]</code></td>
									<td><span class="audit-status-bad"><?php echo intval( $data['posts'] ); ?></span></td>
									<td><?php echo intval( $data['occurrences'] ); ?></td>
									<td><?php echo esc_html( $data['example_title'] . ' (ID:' . $data['example_id'] . ')' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p><span class="audit-status-good">No unregistered shortcodes found in the published posts and pages checked.</span></p>
				<?php endif; ?>
				<?php if ( $shortcode_scan['checked'] >= $shortcode_scan['limit'] ) : ?>
					<p style="font-style: italic; color: #666;">
						<strong>Note:</strong> Checked the <?php echo intval( $shortcode_scan['limit'] ); ?> most recently modified posts/pages containing <code>[</code>. Raise the limit with the <code>wp_audit_shortcode_post_limit</code> filter.
					</p>
				<?php endif; ?>

				<div class="audit-section-header">Inline Styling Technical Debt</div>
				<?php if ( ! empty( $inline_scan['posts'] ) ) : ?>
					<p>Found <strong><?php echo intval( count( $inline_scan['posts'] ) ); ?></strong> published posts/pages with excessive hand-written inline <code>style="..."</code> attributes or embedded <code>&lt;style&gt;</code> blocks (classic content and Custom HTML blocks only — styles generated by block settings are ignored). This is hard-coded styling that should move to CSS.</p>
					<table class="wp-audit-table">
						<thead>
							<tr>
								<th style="width: 5%;">ID</th>
								<th style="width: 30%;">Title</th>
								<th style="width: 10%;">Type</th>
								<th style="width: 15%;">Issue Details</th>
								<th style="width: 20%;">View Page</th>
								<th style="width: 20%;">Edit Post</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $inline_scan['posts'] as $post_data ) : ?>
								<tr>
									<td><?php echo intval( $post_data['id'] ); ?></td>
									<td><strong><?php echo esc_html( $post_data['title'] ); ?></strong></td>
									<td><?php echo esc_html( ucfirst( $post_data['type'] ) ); ?></td>
									<td>
										<?php if ( $post_data['has_style_tag'] ) : ?>
											<span class="audit-status-bad" title="Contains embedded style blocks">🔴 &lt;style&gt; tag</span><br>
										<?php endif; ?>
										<span class="audit-status-warning"><?php echo intval( $post_data['style_count'] ); ?> inline styles</span>
									</td>
									<td>
										<a href="<?php echo esc_url( get_permalink( $post_data['id'] ) ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small">
											View Page ↗
										</a>
									</td>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $post_data['id'] ) ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary button-small">
											Edit Post ↗
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p><span class="audit-status-good">Minimal excessive inline styling or style blocks found in published content.</span></p>
				<?php endif; ?>
				<?php if ( $inline_scan['checked'] >= $inline_scan['limit'] ) : ?>
					<p style="font-style: italic; color: #666;">
						<strong>Note:</strong> Checked the <?php echo intval( $inline_scan['limit'] ); ?> most recently modified posts/pages containing inline styles. Raise the limit with the <code>wp_audit_inline_style_post_limit</code> filter.
					</p>
				<?php endif; ?>

			</div> <!-- .wp-audit-content -->
		</div> <!-- .wp-audit-wrapper -->
	</div> <!-- .wrap -->
	<?php
}
