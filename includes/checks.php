<?php
/**
 * Environment checks and remote/loopback probes.
 *
 * @package WP_Site_Audit_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET a URL on this site (or Google's API) with loopback-friendly defaults.
 */
function wp_audit_http_get( $url, array $args = array() ) {
	return wp_remote_get(
		$url,
		array_merge(
			array(
				'timeout'     => 10,
				'redirection' => 3,
				// Same loopback convention core's Site Health uses: don't fail on a self-signed local cert.
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			),
			$args
		)
	);
}

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
	if ( ! is_ssl() && 0 !== strpos( home_url(), 'https://' ) ) {
		return array(
			'enabled' => false,
			'message' => 'Site URL does not use HTTPS',
		);
	}

	return wp_audit_remember(
		'ssl_cert',
		6 * HOUR_IN_SECONDS,
		function () {
			$host = wp_parse_url( home_url(), PHP_URL_HOST );

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
 * Cache key for one PageSpeed strategy.
 */
function wp_audit_pagespeed_cache_key( $strategy ) {
	return 'pagespeed_' . md5( home_url( '/' ) . $strategy );
}

/**
 * Get PageSpeed Insights scores for one strategy (desktop|mobile), calling Google on a cache miss.
 *
 * Without an API key, Google's shared quota frequently returns HTTP 429.
 * Add a key with define( 'WP_AUDIT_PSI_KEY', '...' ) or the wp_audit_pagespeed_api_key filter.
 */
function wp_audit_get_pagespeed_score( $strategy = 'desktop' ) {
	if ( wp_audit_is_local_site() ) {
		return array(
			'error'    => true,
			'local'    => true,
			'strategy' => $strategy,
			'message'  => 'PageSpeed Insights can\'t reach local development sites. Run the audit on staging or production for these scores.',
		);
	}

	$url = home_url( '/' );

	return wp_audit_remember(
		wp_audit_pagespeed_cache_key( $strategy ),
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
					'error'    => true,
					'strategy' => $strategy,
					'message'  => $response->get_error_message(),
				);
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $data['lighthouseResult'] ) ) {
				return array(
					'error'    => true,
					'strategy' => $strategy,
					'message'  => isset( $data['error']['message'] ) ? $data['error']['message'] : 'Could not retrieve PageSpeed data',
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
 * PageSpeed results for one strategy only if already cached, else null.
 * Used by exports so they never block on a 20–40s API call.
 */
function wp_audit_get_cached_pagespeed( $strategy ) {
	if ( wp_audit_is_local_site() ) {
		return wp_audit_get_pagespeed_score( $strategy );
	}
	$cached = wp_audit_cache_peek( wp_audit_pagespeed_cache_key( $strategy ) );
	return $cached ? $cached['value'] : null;
}

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
			if ( ! function_exists( 'get_home_path' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$result = array(
				'source'     => file_exists( get_home_path() . 'robots.txt' ) ? 'Physical file' : 'Virtual (generated by WordPress)',
				'reachable'  => false,
				'blocks_all' => false,
				'sitemaps'   => array(),
			);

			$response = wp_audit_http_get( home_url( '/robots.txt' ) );
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
function wp_audit_detect_caching( array $active_plugins, array $headers = array() ) {
	$found = array();

	// Hosts like Anchor, Kinsta and WP Engine cache at the server, with no caching plugin installed.
	if ( defined( 'WP_CACHE' ) && WP_CACHE && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
		$found[] = 'Page cache drop-in (advanced-cache.php)';
	}
	if ( wp_using_ext_object_cache() ) {
		$found[] = 'Persistent object cache';
	}
	foreach ( array( 'x-litespeed-cache', 'x-kinsta-cache', 'x-cache', 'cf-cache-status', 'x-proxy-cache', 'x-varnish' ) as $header ) {
		if ( ! empty( $headers[ $header ] ) ) {
			$found[] = 'Server/CDN cache header: ' . $header . ': ' . $headers[ $header ];
			break;
		}
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

/**
 * Parse the attributes of every <$tag> in $html.
 * Regex rather than DOMDocument: we only need a handful of attributes and it
 * tolerates any markup without libxml warnings.
 */
function wp_audit_html_tags( $html, $tag ) {
	$tags = array();
	if ( ! preg_match_all( '/<' . $tag . '\b([^>]*)>/i', $html, $matches ) ) {
		return $tags;
	}
	foreach ( $matches[1] as $raw ) {
		$attrs = array();
		if ( preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', $raw, $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $pair ) {
				$value = '';
				foreach ( array( 2, 3, 4 ) as $i ) {
					if ( isset( $pair[ $i ] ) && '' !== $pair[ $i ] ) {
						$value = $pair[ $i ];
						break;
					}
				}
				$attrs[ strtolower( $pair[1] ) ] = html_entity_decode( $value, ENT_QUOTES );
			}
		}
		$tags[] = $attrs;
	}
	return $tags;
}

/**
 * Third-party services recognisable from homepage HTML: label => regex.
 */
function wp_audit_known_services() {
	return apply_filters(
		'wp_audit_known_services',
		array(
			'Google Analytics 4'       => '/gtag\/js\?id=G-|["\']G-[A-Z0-9]{6,}["\']/',
			'Universal Analytics (retired)' => '/["\']UA-\d{4,}-\d+["\']|google-analytics\.com\/analytics\.js/',
			'Google Tag Manager'       => '/googletagmanager\.com\/gtm\.js|GTM-[A-Z0-9]{4,}/',
			'Google Ads / conversion'  => '/googleadservices\.com|AW-\d{6,}/',
			'Meta (Facebook) Pixel'    => '/connect\.facebook\.net\/[^"\']*fbevents\.js|fbq\(/',
			'LinkedIn Insight'         => '/snap\.licdn\.com/',
			'TikTok Pixel'             => '/analytics\.tiktok\.com/',
			'Microsoft Clarity'        => '/clarity\.ms/',
			'Hotjar'                   => '/static\.hotjar\.com|hotjar\.com\/c\/hotjar/',
			'HubSpot'                  => '/js\.hs-scripts\.com|js\.hsforms\.net|js\.hs-analytics\.net/',
			'Mailchimp'                => '/chimpstatic\.com|list-manage\.com/',
			'Klaviyo'                  => '/static\.klaviyo\.com/',
			'Google reCAPTCHA'         => '/google\.com\/recaptcha|recaptcha\/api\.js/',
			'hCaptcha'                 => '/hcaptcha\.com/',
			'Cloudflare Turnstile'     => '/challenges\.cloudflare\.com\/turnstile/',
			'Google Fonts'             => '/fonts\.googleapis\.com|fonts\.gstatic\.com/',
			'Adobe Fonts (Typekit)'    => '/use\.typekit\.net|p\.typekit\.net/',
			'Font Awesome'             => '/font-?awesome|kit\.fontawesome\.com/i',
			'jQuery'                   => '/jquery(\.min)?\.js/',
			'Google Maps'              => '/maps\.googleapis\.com|maps\.google\.com|google\.com\/maps\/embed/',
			'YouTube embed'            => '/youtube(-nocookie)?\.com\/embed/',
			'Vimeo embed'              => '/player\.vimeo\.com/',
			'Stripe'                   => '/js\.stripe\.com/',
			'PayPal'                   => '/paypal\.com\/sdk|paypalobjects\.com/',
			'Intercom'                 => '/widget\.intercom\.io|js\.intercomcdn\.com/',
			'Drift'                    => '/js\.driftt\.com/',
			'Tidio'                    => '/code\.tidio\.co/',
			'Zendesk'                  => '/static\.zdassets\.com/',
			'Calendly'                 => '/assets\.calendly\.com/',
			'Cookiebot'                => '/consent\.cookiebot\.com/',
			'OneTrust'                 => '/cdn\.cookielaw\.org|onetrust/i',
			'CookieYes'                => '/cdn-cookieyes\.com/',
			'Cookie Notice (plugin)'   => '/cookie-notice/',
			'Elfsight widgets'         => '/elfsightcdn\.com|apps\.elfsight\.com/',
			'AddToAny / ShareThis'     => '/static\.addtoany\.com|sharethis\.com/',
			'Cloudflare'               => '/cdnjs\.cloudflare\.com|\/cdn-cgi\//',
			'Accessibility overlay'    => '/userway\.org|accessibe\.com|acsbapp\.com|equalweb\.com/',
		)
	);
}

/**
 * Fetch the homepage and inventory what's on it: meta tags, assets, third-party
 * services, images, and response headers (security, caching, CDN).
 *
 * A single loopback request feeds the SEO, front-end, performance and security sections.
 */
function wp_audit_probe_homepage() {
	return wp_audit_remember(
		'homepage_probe',
		HOUR_IN_SECONDS,
		function () {
			$start    = microtime( true );
			$response = wp_audit_http_get( home_url( '/' ), array( 'timeout' => 20 ) );
			$elapsed  = (int) round( ( microtime( true ) - $start ) * 1000 );

			if ( is_wp_error( $response ) ) {
				return array(
					'error'   => true,
					'message' => $response->get_error_message(),
				);
			}

			$html    = (string) wp_remote_retrieve_body( $response );
			$headers = array();
			foreach ( array( 'server', 'x-powered-by', 'cache-control', 'x-cache', 'cf-cache-status', 'cf-ray', 'x-litespeed-cache', 'x-kinsta-cache', 'x-proxy-cache', 'x-varnish', 'x-sucuri-id', 'x-fastly-request-id', 'strict-transport-security', 'content-security-policy', 'x-frame-options', 'x-content-type-options', 'referrer-policy', 'permissions-policy', 'content-encoding' ) as $name ) {
				$value = wp_remote_retrieve_header( $response, $name );
				if ( '' !== $value && array() !== $value ) {
					$headers[ $name ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
				}
			}

			$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			$hosts     = array();
			$count_host = function ( $url, $kind ) use ( &$hosts, $site_host ) {
				$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				if ( $host && $host !== $site_host ) {
					if ( ! isset( $hosts[ $host ] ) ) {
						$hosts[ $host ] = array( 'scripts' => 0, 'styles' => 0 );
					}
					$hosts[ $host ][ $kind ]++;
				}
			};

			// Meta tags keyed by name/property.
			$meta = array();
			foreach ( wp_audit_html_tags( $html, 'meta' ) as $tag ) {
				$key = isset( $tag['name'] ) ? $tag['name'] : ( isset( $tag['property'] ) ? $tag['property'] : '' );
				if ( $key && isset( $tag['content'] ) && ! isset( $meta[ strtolower( $key ) ] ) ) {
					$meta[ strtolower( $key ) ] = $tag['content'];
				}
			}

			$canonical   = '';
			$stylesheets = 0;
			$preloads    = 0;
			foreach ( wp_audit_html_tags( $html, 'link' ) as $tag ) {
				$rel = isset( $tag['rel'] ) ? strtolower( $tag['rel'] ) : '';
				if ( 'canonical' === $rel && isset( $tag['href'] ) ) {
					$canonical = $tag['href'];
				} elseif ( 'stylesheet' === $rel && isset( $tag['href'] ) ) {
					$stylesheets++;
					$count_host( $tag['href'], 'styles' );
				} elseif ( 'preload' === $rel ) {
					$preloads++;
				}
			}

			$scripts = array( 'total' => 0, 'external' => 0, 'inline' => 0, 'deferred' => 0 );
			foreach ( wp_audit_html_tags( $html, 'script' ) as $tag ) {
				$type = isset( $tag['type'] ) ? strtolower( $tag['type'] ) : '';
				if ( in_array( $type, array( 'application/ld+json', 'application/json', 'text/template', 'text/html' ), true ) ) {
					continue;
				}
				$scripts['total']++;
				if ( ! empty( $tag['src'] ) ) {
					$scripts['external']++;
					if ( isset( $tag['defer'] ) || isset( $tag['async'] ) || 'module' === $type ) {
						$scripts['deferred']++;
					}
					$count_host( $tag['src'], 'scripts' );
				} else {
					$scripts['inline']++;
				}
			}

			$images = array( 'total' => 0, 'lazy' => 0, 'missing_alt' => 0, 'missing_dimensions' => 0, 'webp_avif' => 0 );
			foreach ( wp_audit_html_tags( $html, 'img' ) as $tag ) {
				$images['total']++;
				if ( isset( $tag['loading'] ) && 'lazy' === strtolower( $tag['loading'] ) ) {
					$images['lazy']++;
				}
				if ( ! isset( $tag['alt'] ) ) {
					$images['missing_alt']++;
				}
				if ( ! isset( $tag['width'] ) || ! isset( $tag['height'] ) ) {
					$images['missing_dimensions']++;
				}
				if ( isset( $tag['src'] ) && preg_match( '/\.(webp|avif)(\?|$)/i', $tag['src'] ) ) {
					$images['webp_avif']++;
				}
			}

			$services = array();
			foreach ( wp_audit_known_services() as $label => $pattern ) {
				if ( preg_match( $pattern, $html ) ) {
					$services[] = $label;
				}
			}

			$ga_ids = array();
			if ( preg_match_all( '/\b(G-[A-Z0-9]{6,12}|GTM-[A-Z0-9]{4,10}|UA-\d{4,10}-\d{1,3})\b/', $html, $id_matches ) ) {
				$ga_ids = array_values( array_unique( $id_matches[1] ) );
			}

			uasort(
				$hosts,
				function ( $a, $b ) {
					return ( $b['scripts'] + $b['styles'] ) - ( $a['scripts'] + $a['styles'] );
				}
			);

			$title = preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ? trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES ) ) : '';
			$h1s   = array();
			if ( preg_match_all( '/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $h1_matches ) ) {
				$h1s = array_map(
					function ( $h ) {
						return trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $h ), ENT_QUOTES ) ) );
					},
					$h1_matches[1]
				);
			}

			return array(
				'status'       => (int) wp_remote_retrieve_response_code( $response ),
				'response_ms'  => $elapsed,
				'html_bytes'   => strlen( $html ),
				'headers'      => $headers,
				'title'        => $title,
				'description'  => isset( $meta['description'] ) ? $meta['description'] : '',
				'robots_meta'  => isset( $meta['robots'] ) ? $meta['robots'] : '',
				'viewport'     => isset( $meta['viewport'] ) ? $meta['viewport'] : '',
				'generator'    => isset( $meta['generator'] ) ? $meta['generator'] : '',
				'og_title'     => isset( $meta['og:title'] ) ? $meta['og:title'] : '',
				'og_image'     => isset( $meta['og:image'] ) ? $meta['og:image'] : '',
				'twitter_card' => isset( $meta['twitter:card'] ) ? $meta['twitter:card'] : '',
				'canonical'    => $canonical,
				'lang'         => preg_match( '/<html[^>]*\slang=["\']([^"\']+)/i', $html, $lang ) ? $lang[1] : '',
				'h1s'          => $h1s,
				'schema_types' => preg_match_all( '/"@type"\s*:\s*"([^"]+)"/', $html, $schema ) ? array_values( array_unique( $schema[1] ) ) : array(),
				'scripts'      => $scripts,
				'stylesheets'  => $stylesheets,
				'inline_style_blocks' => preg_match_all( '/<style\b/i', $html ),
				'preloads'     => $preloads,
				'iframes'      => preg_match_all( '/<iframe\b/i', $html ),
				'images'       => $images,
				'services'     => $services,
				'tracking_ids' => $ga_ids,
				'hosts'        => array_slice( $hosts, 0, 25, true ),
			);
		}
	);
}

/**
 * Probe for common information leaks with unauthenticated loopback requests.
 */
function wp_audit_probe_exposure() {
	return wp_audit_remember(
		'exposure_probe',
		HOUR_IN_SECONDS,
		function () {
			$result = array();

			// debug.log: only worth requesting when the file exists on disk.
			$log = WP_CONTENT_DIR . '/debug.log';
			if ( file_exists( $log ) ) {
				$response              = wp_audit_http_get( content_url( '/debug.log' ), array( 'limit_response_size' => 512 ) );
				$result['debug_log']   = array(
					'bytes'   => (int) filesize( $log ),
					'exposed' => ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ),
				);
			}

			// Directory listing in uploads exposes every file ever uploaded.
			$uploads                   = wp_upload_dir( null, false );
			$response                  = wp_audit_http_get( trailingslashit( $uploads['baseurl'] ), array( 'limit_response_size' => 8192 ) );
			$result['uploads_listing'] = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) && false !== stripos( wp_remote_retrieve_body( $response ), 'Index of' );

			// The REST users endpoint reveals login usernames to anyone unless restricted.
			$response = wp_audit_http_get( rest_url( 'wp/v2/users' ), array( 'limit_response_size' => 65536 ) );
			$users    = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
			$result['rest_users_exposed'] = is_array( $users ) && isset( $users[0]['slug'] ) ? count( $users ) : 0;

			// XML-RPC answers GET with this exact message when enabled.
			$response              = wp_audit_http_get( site_url( '/xmlrpc.php' ), array( 'limit_response_size' => 1024 ) );
			$result['xmlrpc_open'] = ! is_wp_error( $response ) && false !== strpos( wp_remote_retrieve_body( $response ), 'XML-RPC server accepts POST requests only' );

			return $result;
		}
	);
}

/**
 * wp_options health: autoloaded data size, largest autoloaded options, transients.
 */
function wp_audit_get_options_stats() {
	return wp_audit_remember(
		'options_stats',
		15 * MINUTE_IN_SECONDS,
		function () {
			global $wpdb;

			// WordPress 6.6 introduced new autoload values ('on', 'auto-on', 'auto').
			$values = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes' );
			$in     = implode( ',', array_fill( 0, count( $values ), '%s' ) );

			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders -- $in is a generated list of %s placeholders.
			$autoload = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM(LENGTH(option_value)) AS bytes FROM {$wpdb->options} WHERE autoload IN ($in)", $values ), ARRAY_A );
			$largest  = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ($in) ORDER BY bytes DESC LIMIT 10", $values ), ARRAY_A );
			// phpcs:enable

			$transients = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
					$wpdb->esc_like( '_transient_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_' ) . '%'
				)
			);
			$expired    = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
					$wpdb->esc_like( '_transient_timeout_' ) . '%',
					time()
				)
			);

			return array(
				'autoload_count' => (int) $autoload['total'],
				'autoload_bytes' => (int) $autoload['bytes'],
				'largest'        => $largest,
				'total_options'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ),
				'transients'     => $transients,
				'expired'        => $expired,
			);
		}
	);
}

/**
 * WP-Cron overview: scheduled events, overdue events and the hooks they run.
 */
function wp_audit_get_cron_stats() {
	$crons  = function_exists( '_get_cron_array' ) ? (array) _get_cron_array() : array();
	$hooks  = array();
	$events = 0;
	$overdue = 0;

	foreach ( $crons as $timestamp => $cron_hooks ) {
		foreach ( (array) $cron_hooks as $hook => $instances ) {
			$count           = count( (array) $instances );
			$events         += $count;
			$hooks[ $hook ]  = ( isset( $hooks[ $hook ] ) ? $hooks[ $hook ] : 0 ) + $count;
			// An hour late means cron isn't running reliably (low traffic, or DISABLE_WP_CRON without a real cron).
			if ( $timestamp < time() - HOUR_IN_SECONDS ) {
				$overdue += $count;
			}
		}
	}
	ksort( $hooks );

	return array(
		'events'    => $events,
		'overdue'   => $overdue,
		'hooks'     => $hooks,
		'disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		'alternate' => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
	);
}
