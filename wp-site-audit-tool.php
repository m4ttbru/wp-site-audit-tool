<?php
/**
 * Plugin Name:       WordPress Site Audit Tool
 * Description:       Adds a site audit report page to the "Tools" menu for scoping and analysis.
 * Description:       The WordPress Site Audit Tool is a comprehensive diagnostic and scoping plugin designed for developers, agencies, and site administrators who need to analyze WordPress installations for redesign projects, audits, or RFQ preparation. It automatically generates a detailed 15-section report covering content structure (post types, taxonomies, pages, forms), technical infrastructure (plugins, themes, database health, performance metrics), and technical debt indicators (orphaned data, unregistered shortcodes, inline styling issues). The plugin features a sticky navigation interface, source detection for custom post types and taxonomies, exportable markdown reports with automatic file naming, and an RFQ Requirements Summary section that consolidates key data points needed for accurate project estimation—all presented in an organized, easy-to-read format with color-coded status indicators and actionable links to problematic content.
 * Version:           1.7
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

/**
 * Tunable thresholds — override via add_filter() in a theme or mu-plugin.
 *
 * wp_audit_scan_timeout        Max seconds for a post-type/taxonomy file scan.
 * wp_audit_max_file_size       Skip PHP files larger than this (bytes) during scan.
 * wp_audit_inline_style_limit  Flag posts whose inline-style count meets or exceeds this.
 * wp_audit_shortcode_post_limit Max posts to inspect for unregistered shortcodes.
 * wp_audit_inline_style_post_limit Max posts to inspect for inline-style bloat.
 */
define( 'WP_AUDIT_SCAN_TIMEOUT',             apply_filters( 'wp_audit_scan_timeout',              60  ) );
define( 'WP_AUDIT_MAX_FILE_SIZE',            apply_filters( 'wp_audit_max_file_size',             500 * 1024 ) );
define( 'WP_AUDIT_INLINE_STYLE_LIMIT',       apply_filters( 'wp_audit_inline_style_limit',        15  ) );
define( 'WP_AUDIT_SHORTCODE_POST_LIMIT',     apply_filters( 'wp_audit_shortcode_post_limit',      100 ) );
define( 'WP_AUDIT_INLINE_STYLE_POST_LIMIT',  apply_filters( 'wp_audit_inline_style_post_limit',   100 ) );

/**
 * Detect origin of a post type or taxonomy.
 * Reworked to search files and determine source based on path (Plugin, Theme, Custom).
 * Returns an array: [ 'type' => 'core|plugin|theme|custom', 'label' => 'Name', 'plugin_file' => '', 'active' => bool, 'location' => 'path/file.php' ]
 */
function wp_audit_detect_origin( $name, $is_tax = false ) {
    // Check cache first (1 hour expiry)
    $cache_key = 'wp_audit_origin_' . md5( $name . ( $is_tax ? '_tax' : '_pt' ) );
    $cached = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $builtin = array('post','page','attachment','revision','nav_menu_item','custom_css','customize_changeset','oembed_cache','user_request','wp_block');
    if ( in_array( $name, $builtin, true ) ) {
        $result = array( 'type' => 'core', 'label' => 'WordPress Core', 'plugin_file' => '', 'active' => true );
        set_transient( $cache_key, $result, HOUR_IN_SECONDS );
        return $result;
    }

    $target_function = $is_tax ? 'register_taxonomy' : 'register_post_type';
    // Use both single and double quotes for robustness
    $search_patterns = array(
        $target_function . "( '" . $name . "'",
        $target_function . "( \"" . $name . "\"",
    );

    $search_dirs = array(
        WP_PLUGIN_DIR,
        get_template_directory(),     // Parent Theme
        get_stylesheet_directory()    // Child Theme / Active Theme
    );
    $search_dirs = array_unique( array_filter( $search_dirs ) );

    $found_file = false;

    // --- 1. Global File Scan (Optimized) ---
    foreach ( $search_dirs as $dir ) {
        if ( ! is_dir( $dir ) ) continue;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ( $iterator as $file ) {
                if ( $file->isFile() && strtolower( $file->getExtension() ) === 'php' ) {
                    $filepath = $file->getPathname();

                    // Skip very large files to improve performance.
                    if ( $file->getSize() > WP_AUDIT_MAX_FILE_SIZE ) {
                        continue;
                    }

                    $contents = @file_get_contents( $filepath );

                    if ( $contents !== false ) {
                        foreach ($search_patterns as $pattern) {
                            if ( strpos( $contents, $pattern ) !== false ) {
                                $found_file = $filepath;
                                break 3; // Exit all three loops
                            }
                        }
                    }
                }
            }
        } catch ( Exception $e ) {
            // Log but don't fatal — one unreadable directory shouldn't abort the whole scan.
            error_log( 'WP Site Audit: file scan error in ' . $dir . ' — ' . $e->getMessage() );
        }
    }

    // --- 2. Determine Source from Path ---
    if ( $found_file ) {
        $found_file = wp_normalize_path( $found_file );
        $plugins_dir = wp_normalize_path( WP_PLUGIN_DIR );
        $theme_dir = wp_normalize_path( get_stylesheet_directory() );
        $template_dir = wp_normalize_path( get_template_directory() );

        // Check Plugin
        if ( strpos( $found_file, $plugins_dir ) === 0 ) {
            $plugin_path = str_replace( $plugins_dir . '/', '', $found_file );
            $plugin_slug = explode( '/', $plugin_path )[0];
            $plugin_file_base = $plugin_path;

            if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $all_plugins = get_plugins();

            foreach ( $all_plugins as $file => $data ) {
                if ( dirname( $file ) === $plugin_slug || $file === $plugin_file_base ) {
                    $label = $data['Name'];
                    $plugin_file = $file;
                    $active = is_plugin_active( $plugin_file );
                    $result = array( 'type' => 'plugin', 'label' => $label, 'plugin_file' => $plugin_file, 'active' => $active, 'location' => $plugin_path );
                    set_transient( $cache_key, $result, HOUR_IN_SECONDS );
                    return $result;
                }
            }
        }

        // Check Theme (Child or Parent)
        if ( strpos( $found_file, $theme_dir ) === 0 || strpos( $found_file, $template_dir ) === 0 ) {
            $theme = wp_get_theme();
            // Provide path relative to theme root
            $location = str_replace( $template_dir . '/', 'parent-theme/', $found_file );
            $location = str_replace( $theme_dir . '/', 'active-theme/', $location );
            $result = array( 'type' => 'theme', 'label' => $theme->get( 'Name' ), 'plugin_file' => '', 'active' => true, 'location' => $location );
            set_transient( $cache_key, $result, HOUR_IN_SECONDS );
            return $result;
        }

        // Found, but path is outside known theme/plugin folders (e.g., mu-plugins, custom folder in wp-content)
        $result = array( 'type' => 'custom', 'label' => 'Custom (Found: ' . basename($found_file) . ')', 'plugin_file' => '', 'active' => false, 'location' => $found_file );
        set_transient( $cache_key, $result, HOUR_IN_SECONDS );
        return $result;
    }

    // Not found in any scanned file
    $result = array( 'type' => 'custom', 'label' => 'Custom / Unknown', 'plugin_file' => '', 'active' => false );
    set_transient( $cache_key, $result, HOUR_IN_SECONDS );
    return $result;
}

// AJAX handler for scanning sources
add_action( 'wp_ajax_wp_audit_scan_sources', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }
    // Security check
    check_ajax_referer( 'wp_audit_scan_sources', 'nonce' );

    // Sanitize POST inputs to prevent injection attacks
    $post_types = isset( $_POST['post_types'] )
        ? array_map( 'sanitize_text_field', (array) $_POST['post_types'] )
        : array();
    $taxonomies = isset( $_POST['taxonomies'] )
        ? array_map( 'sanitize_text_field', (array) $_POST['taxonomies'] )
        : array();

    // Set a reasonable time limit — Results are cached for 1 hour.
    $time_limit = WP_AUDIT_SCAN_TIMEOUT;
    $start_time = time();

    $results = array( 'post_types' => array(), 'taxonomies' => array() );
    $timed_out = false;
    
    foreach ( $post_types as $pt ) {
        // Check if we're approaching timeout
        if ( (time() - $start_time) > $time_limit ) {
            $timed_out = true;
            break;
        }
        $results['post_types'][ $pt ] = wp_audit_detect_origin( $pt, false );
    }
    
    if ( ! $timed_out ) {
        foreach ( $taxonomies as $tx ) {
            // Check if we're approaching timeout
            if ( (time() - $start_time) > $time_limit ) {
                $timed_out = true;
                break;
            }
            $results['taxonomies'][ $tx ] = wp_audit_detect_origin( $tx, true );
        }
    }

    // Sanitize output data to prevent XSS
    $sanitized_results = array(
        'post_types' => array(),
        'taxonomies' => array()
    );

    foreach ( $results['post_types'] as $key => $data ) {
        $sanitized_results['post_types'][ $key ] = array(
            'type' => sanitize_text_field( $data['type'] ),
            'label' => sanitize_text_field( $data['label'] ),
            'plugin_file' => sanitize_text_field( $data['plugin_file'] ),
            'active' => (bool) $data['active'],
            'location' => isset( $data['location'] ) ? sanitize_text_field( $data['location'] ) : ''
        );
    }

    foreach ( $results['taxonomies'] as $key => $data ) {
        $sanitized_results['taxonomies'][ $key ] = array(
            'type' => sanitize_text_field( $data['type'] ),
            'label' => sanitize_text_field( $data['label'] ),
            'plugin_file' => sanitize_text_field( $data['plugin_file'] ),
            'active' => (bool) $data['active'],
            'location' => isset( $data['location'] ) ? sanitize_text_field( $data['location'] ) : ''
        );
    }

    if ( $timed_out ) {
        wp_send_json_error( array(
            'message' => 'Scan timeout: Too many files to scan. Partial results returned.',
            'partial_results' => $sanitized_results
        ) );
    } else {
        wp_send_json_success( $sanitized_results );
    }
} );

/**
 * Check WordPress core version and compare against latest stable
 * Returns: array with 'current', 'latest', 'outdated', 'security_risk'
 */
function wp_audit_check_wp_version() {
    $cache_key = 'wp_audit_wp_version_check';
    $cached = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    global $wp_version;

    // Get latest WordPress version from API
    $response = wp_remote_get( 'https://api.wordpress.org/core/version-check/1.7/', array( 'timeout' => 10 ) );

    if ( is_wp_error( $response ) ) {
        return array(
            'current' => $wp_version,
            'latest' => 'Unknown',
            'outdated' => false,
            'security_risk' => false,
            'error' => $response->get_error_message()
        );
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( empty( $data['offers'] ) ) {
        $result = array(
            'current' => $wp_version,
            'latest' => $wp_version,
            'outdated' => false,
            'security_risk' => false
        );
    } else {
        $latest = $data['offers'][0]['version'];
        $outdated = version_compare( $wp_version, $latest, '<' );

        // Check if more than 2 minor versions behind (security risk)
        $current_parts = explode( '.', $wp_version );
        $latest_parts = explode( '.', $latest );
        $security_risk = false;

        if ( isset( $current_parts[0], $latest_parts[0], $current_parts[1], $latest_parts[1] ) ) {
            $major_diff = intval( $latest_parts[0] ) - intval( $current_parts[0] );
            $minor_diff = intval( $latest_parts[1] ) - intval( $current_parts[1] );
            $security_risk = ( $major_diff > 0 || $minor_diff > 2 );
        }

        $result = array(
            'current' => $wp_version,
            'latest' => $latest,
            'outdated' => $outdated,
            'security_risk' => $security_risk
        );
    }

    set_transient( $cache_key, $result, HOUR_IN_SECONDS );
    return $result;
}

/**
 * Check file permissions for security
 */
function wp_audit_check_file_permissions() {
    $results = array();

    // Check wp-config.php
    $wp_config_path = ABSPATH . 'wp-config.php';
    if ( file_exists( $wp_config_path ) ) {
        $perms = fileperms( $wp_config_path );
        $octal_perms = substr( sprintf( '%o', $perms ), -3 );
        $results['wp_config'] = array(
            'path' => 'wp-config.php',
            'permissions' => $octal_perms,
            'secure' => in_array( $octal_perms, array( '400', '440', '600', '640' ) ),
            'recommended' => '600'
        );
    }

    // Check .htaccess
    $htaccess_path = ABSPATH . '.htaccess';
    if ( file_exists( $htaccess_path ) ) {
        $perms = fileperms( $htaccess_path );
        $octal_perms = substr( sprintf( '%o', $perms ), -3 );
        $results['htaccess'] = array(
            'path' => '.htaccess',
            'permissions' => $octal_perms,
            'secure' => in_array( $octal_perms, array( '644', '640', '600' ) ),
            'recommended' => '644'
        );
    }

    // Check uploads directory
    $upload_dir = wp_upload_dir();
    if ( isset( $upload_dir['basedir'] ) && is_dir( $upload_dir['basedir'] ) ) {
        $perms = fileperms( $upload_dir['basedir'] );
        $octal_perms = substr( sprintf( '%o', $perms ), -3 );
        $results['uploads'] = array(
            'path' => 'wp-content/uploads',
            'permissions' => $octal_perms,
            'secure' => in_array( $octal_perms, array( '755', '750' ) ),
            'recommended' => '755'
        );
    }

    return $results;
}

/**
 * Check SSL certificate details
 */
function wp_audit_check_ssl_certificate() {
    if ( ! is_ssl() ) {
        return array(
            'enabled' => false,
            'message' => 'SSL is not enabled'
        );
    }

    $cache_key = 'wp_audit_ssl_cert_check';
    $cached = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $site_url = get_site_url();
    $parsed = parse_url( $site_url );
    $host = $parsed['host'];

    $context = stream_context_create( array(
        'ssl' => array(
            'capture_peer_cert' => true,
            'verify_peer'       => true,
            'verify_peer_name'  => true,
        )
    ) );

    $socket = @stream_socket_client(
        'ssl://' . $host . ':443',
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ( ! $socket ) {
        return array(
            'enabled' => true,
            'error' => 'Could not connect to SSL socket',
            'message' => $errstr
        );
    }

    $params = stream_context_get_params( $socket );
    fclose( $socket );

    if ( ! isset( $params['options']['ssl']['peer_certificate'] ) ) {
        return array(
            'enabled' => true,
            'error' => 'Could not retrieve certificate'
        );
    }

    $cert_info = openssl_x509_parse( $params['options']['ssl']['peer_certificate'] );

    if ( ! $cert_info ) {
        return array(
            'enabled' => true,
            'error' => 'Could not parse certificate'
        );
    }

    $expiry_date = $cert_info['validTo_time_t'];
    $days_until_expiry = ceil( ( $expiry_date - time() ) / 86400 );
    $expires_soon = $days_until_expiry < 30;

    $result = array(
        'enabled' => true,
        'issuer' => isset( $cert_info['issuer']['O'] ) ? $cert_info['issuer']['O'] : 'Unknown',
        'expires' => date( 'Y-m-d', $expiry_date ),
        'days_until_expiry' => $days_until_expiry,
        'expires_soon' => $expires_soon,
        'subject' => isset( $cert_info['subject']['CN'] ) ? $cert_info['subject']['CN'] : 'Unknown'
    );

    set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
    return $result;
}

/**
 * Get PageSpeed Insights score
 * Note: Requires Google PageSpeed API key (optional, uses free tier without key but has limits)
 */
function wp_audit_get_pagespeed_score( $url = null, $strategy = 'desktop' ) {
    if ( ! $url ) {
        $url = get_site_url();
    }

    $cache_key = 'wp_audit_pagespeed_' . md5( $url . $strategy );
    $cached = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    // API endpoint (no key for free tier, but has rate limits).
    // Build URL manually — add_query_arg() can't represent repeated keys,
    // but the PageSpeed API requires &category= repeated for each category.
    $api_base = add_query_arg(
        array(
            'url'      => rawurlencode( $url ),
            'strategy' => rawurlencode( $strategy ),
        ),
        'https://www.googleapis.com/pagespeedonline/v5/runPagespeed'
    );
    $api_url = $api_base . '&category=performance&category=accessibility&category=seo&category=best-practices';

    $response = wp_remote_get( $api_url, array( 'timeout' => 30 ) );

    if ( is_wp_error( $response ) ) {
        return array(
            'error' => true,
            'message' => $response->get_error_message()
        );
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( empty( $data['lighthouseResult'] ) ) {
        return array(
            'error' => true,
            'message' => isset( $data['error']['message'] ) ? $data['error']['message'] : 'Could not retrieve PageSpeed data'
        );
    }

    $lighthouse = $data['lighthouseResult'];
    $categories = $lighthouse['categories'];
    $audits = $lighthouse['audits'];

    $result = array(
        'performance' => isset( $categories['performance']['score'] ) ? round( $categories['performance']['score'] * 100 ) : null,
        'accessibility' => isset( $categories['accessibility']['score'] ) ? round( $categories['accessibility']['score'] * 100 ) : null,
        'seo' => isset( $categories['seo']['score'] ) ? round( $categories['seo']['score'] * 100 ) : null,
        'best_practices' => isset( $categories['best-practices']['score'] ) ? round( $categories['best-practices']['score'] * 100 ) : null,
        'lcp' => isset( $audits['largest-contentful-paint']['numericValue'] ) ? round( $audits['largest-contentful-paint']['numericValue'] / 1000, 2 ) : null,
        'fid' => isset( $audits['max-potential-fid']['numericValue'] ) ? round( $audits['max-potential-fid']['numericValue'], 2 ) : null,
        'cls' => isset( $audits['cumulative-layout-shift']['numericValue'] ) ? round( $audits['cumulative-layout-shift']['numericValue'], 3 ) : null,
        'fcp' => isset( $audits['first-contentful-paint']['numericValue'] ) ? round( $audits['first-contentful-paint']['numericValue'] / 1000, 2 ) : null,
        'ttfb' => isset( $audits['server-response-time']['numericValue'] ) ? round( $audits['server-response-time']['numericValue'], 2 ) : null,
        'strategy' => $strategy
    );

    // Cache for 1 day (PageSpeed data doesn't change frequently)
    set_transient( $cache_key, $result, DAY_IN_SECONDS );
    return $result;
}

/**
 * Add the admin page to the "Tools" menu.
 */
function wp_audit_tool_add_admin_page() {
    add_management_page(
        'Site Audit Report',       // Page Title
        'Site Audit',              // Menu Title
        'manage_options',          // Capability
        'wp-site-audit-report',    // Menu Slug
        'wp_audit_tool_render_page'  // Callback Function
    );
}
add_action( 'admin_menu', 'wp_audit_tool_add_admin_page' );

/**
 * Render the admin page content.
 */
function wp_audit_tool_render_page() {
    // Check if user is admin
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( '<p style="color: red;"><strong>Error:</strong> Only administrators can view this report.</p>' );
    }

    global $wpdb;
    
    // --- Pre-fetch data for the Overview section ---
    
    // 1. Theme Info
    $active_theme = wp_get_theme();
    $parent_theme = $active_theme->parent();

    // 2. Page Templates & Usage (Optimized - Single Query Instead of N+1)
    $theme_templates = array();
    $template_usage_counts = array();
    if ( function_exists('wp_get_theme') ) {
        $theme_templates = $active_theme->get_page_templates();
    }
    $theme_template_count = count($theme_templates);

    // Get all template usage counts in a single query (prevents N+1 problem)
    if ( ! empty( $theme_templates ) ) {
        $template_usage_raw = $wpdb->get_results(
            "SELECT pm.meta_value as template, COUNT(*) as count
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE p.post_type = 'page'
               AND p.post_status = 'publish'
               AND pm.meta_key = '_wp_page_template'
             GROUP BY pm.meta_value",
            ARRAY_A
        );

        // Map template filenames to template names for display
        foreach ( $template_usage_raw as $row ) {
            $filename = $row['template'];
            $count = intval( $row['count'] );

            // Find the matching template name
            foreach ( $theme_templates as $template_file => $template_name ) {
                if ( $template_file === $filename ) {
                    $template_usage_counts[$template_name] = $count;
                    break;
                }
            }
        }
    }

    // 3. Page Count (Cached for 15 minutes)
    $cache_key_pages = 'wp_audit_page_count';
    $page_count = get_transient( $cache_key_pages );
    if ( false === $page_count ) {
        $page_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish'"
        );
        set_transient( $cache_key_pages, $page_count, 15 * MINUTE_IN_SECONDS );
    }

    // 4. Post Type Counts (Cached for 15 minutes)
    $cache_key_cpt = 'wp_audit_cpt_counts';
    $cpt_query = get_transient( $cache_key_cpt );
    if ( false === $cpt_query ) {
        $cpt_query = $wpdb->get_results(
            "SELECT post_type, COUNT(*) as count
             FROM {$wpdb->posts}
             WHERE post_type NOT IN ('post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block')
             AND post_status = 'publish'
             GROUP BY post_type
             ORDER BY count DESC"
        );
        set_transient( $cache_key_cpt, $cpt_query, 15 * MINUTE_IN_SECONDS );
    }
    $total_cpt_count = count($cpt_query);
    $top_cpts = array_slice($cpt_query, 0, 3);
    
    // --- NEW: Cleanup Checks ---

    // 1. Orphaned Database Tables
    $core_tables = [
        'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts',
        'termmeta', 'terms', 'term_relationships', 'term_taxonomy', 'usermeta', 'users',
    ];
    // Add common core multisite tables if multisite is active
    if ( is_multisite() ) {
        $core_tables[] = 'blogs';
        $core_tables[] = 'registration_log';
        $core_tables[] = 'signups';
        $core_tables[] = 'site';
        $core_tables[] = 'sitemeta';
    }
    
    $all_db_tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");
    $prefixed_core_tables = array_map(function($t) use ($wpdb) { return $wpdb->prefix . $t; }, $core_tables);
    $orphaned_tables = array_diff($all_db_tables, $prefixed_core_tables);

    // 2. Unregistered Shortcodes in Content
    $posts_with_shortcodes = $wpdb->get_results(
        "SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts} 
         WHERE post_status = 'publish' 
         AND post_type IN ('post', 'page') 
         AND post_content LIKE '%[%%]%'
         LIMIT " . WP_AUDIT_SHORTCODE_POST_LIMIT . "" // Limit check for performance
    );

    $unregistered_shortcodes = [];
    $shortcode_regex = get_shortcode_regex();

    foreach ($posts_with_shortcodes as $post) {
        if (preg_match_all('/' . $shortcode_regex . '/s', $post->post_content, $matches)) {
            // matches[2] contains the shortcode tags
            foreach ($matches[2] as $tag) {
                // Ignore common tags like 'caption', 'gallery', which are often core
                if (in_array($tag, ['caption', 'gallery', 'wp-caption'])) continue;
                
                // Check if shortcode tag is NOT registered
                if (!shortcode_exists($tag)) {
                    if (!isset($unregistered_shortcodes[$tag])) {
                        $unregistered_shortcodes[$tag] = [
                            'count' => 0,
                            'example_post' => $post->post_title . ' (ID:' . $post->ID . ')'
                        ];
                    }
                    $unregistered_shortcodes[$tag]['count']++;
                }
            }
        }
    }
    arsort($unregistered_shortcodes);
    $top_10_unregistered_shortcodes = array_slice($unregistered_shortcodes, 0, 10);

    // --- 3. Inline Styling Bloat ---
    $posts_to_check = $wpdb->get_results(
        "SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts} 
         WHERE post_status = 'publish' 
         AND post_type IN ('post', 'page') 
         AND (post_content LIKE '% style=\"%' OR post_content LIKE '%<style>%')
         LIMIT " . WP_AUDIT_INLINE_STYLE_POST_LIMIT . " /* Limit for analysis speed */"
    );

    $excessive_inline_style_count = 0;
    $posts_with_excessive_styles = [];
    $style_attribute_regex = '/\s+style\s*=\s*[\'"][^\'"]*[\'"]/i'; // Match style="..." attribute

    foreach ($posts_to_check as $post) {
        $inline_style_hits = 0;
        $has_style_tag = false;

        // Check for explicit <style> tags (major hit)
        if (strpos($post->post_content, '<style') !== false) {
            $inline_style_hits += 50;
            $has_style_tag = true;
        }

        // Check for 'style="..."' attributes
        $num_style_attributes = preg_match_all($style_attribute_regex, $post->post_content, $matches);
        $inline_style_hits += $num_style_attributes;
        
        // Threshold for flagging excessive inline styling.
        if ( $inline_style_hits >= WP_AUDIT_INLINE_STYLE_LIMIT ) {
            $excessive_inline_style_count++;
            $posts_with_excessive_styles[] = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'type' => $post->post_type,
                'style_count' => $num_style_attributes,
                'has_style_tag' => $has_style_tag,
                'permalink' => get_permalink($post->ID),
                'edit_link' => get_edit_post_link($post->ID)
            );
        }
    }

    
    ?>
    <style>
        /* --- Layout & Navigation --- */
        .wp-audit-wrapper {
            display: flex;
            flex-direction: row;
            align-items: flex-start;
            gap: 20px;
            margin-top: 20px;
        }
        .wp-audit-nav {
            width: 240px;
            position: sticky;
            top: 40px; /* Aligns with WP admin bar */
            flex-shrink: 0;
            background: var(--wp-admin-background-color, #fff);
            border: 1px solid var(--wp-admin-border-color, #c3c4c7);
            border-radius: 4px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .wp-audit-nav h3 {
            font-size: 14px;
            font-weight: 600;
            padding: 12px 15px;
            margin: 0;
            border-bottom: 1px solid var(--wp-admin-border-color, #c3c4c7);
            color: var(--ch-color-2, #2c3338);
        }
        .wp-audit-nav ul {
            margin: 0;
            padding: 10px 0;
        }
        .wp-audit-nav li {
            margin: 0;
            padding: 0;
        }
        .wp-audit-nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
            text-decoration: none;
            color: var(--wp-admin-theme-color, #2271b1);
            font-size: 13px;
            border-left: 4px solid transparent;
            position: relative;
            transition: all 0.2s ease;
        }
        .wp-audit-nav a i {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            color: var(--wp-admin-theme-color, #2271b1);
        }
        .wp-audit-nav a span {
            flex: 1;
        }
        .wp-audit-nav a::after {
            content: '';
            width: 0;
            height: 0;
            border-top: 4px solid transparent;
            border-bottom: 4px solid transparent;
            border-left: 5px solid color-mix(in srgb, var(--wp-admin-theme-color, #2271b1) 15%, transparent);
            margin-left: auto;
            opacity: 1;
            transition: all 0.2s ease;
        }
        .wp-audit-nav a:hover {
            background: var(--ch-color-2, #f0f0f0);
            color: var(--ch-color-1, #1d2327);
        }
        .wp-audit-nav a:hover i {
            color: var(--ch-color-1, #1d2327);
        }
        .wp-audit-nav a:hover::after {
            border-left-color: var(--ch-color-1, #1d2327);
            opacity: 1;
        }
        .wp-audit-nav a.active {
            font-weight: 600;
            color: var(--ch-color-1, #1d2327);
            background: var(--wp-components-color-gray-200, #f0f0f0);
            border-left: 4px solid var(--wp-admin-theme-color, #2271b1);
        }
        .wp-audit-nav a.active i {
            color: var(--wp-admin-theme-color, #2271b1);
        }
        .wp-audit-nav a.active::after {
            border-left-color: var(--wp-admin-theme-color, #2271b1);
            opacity: 1;
        }

        /* --- Main Content Area --- */
        .wp-audit-content {
            flex-grow: 1;
            min-width: 0; /* Prevents flex overflow */
            background: var(--wp-components-color-gray-100, #f9f9f9);
            padding: 1px 20px 20px 20px; /* 1px padding to contain margins */
            border-radius: 4px;
            border: 1px solid var(--wp-admin-border-color, #c3c4c7);
            max-width: 1400px;
        }
        .wp-audit-content h2 {
            color: var(--ch-color-1, #1d2327);
            border-bottom: 3px solid var(--wp-admin-theme-color, #2271b1);
            padding-bottom: 10px;
            margin-top: 40px;
            margin-bottom: 15px;
            font-size: 20px;
            font-weight: 700;
            scroll-margin-top: 50px; /* Offset for sticky nav */
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .wp-audit-content h2 i {
            width: 22px;
            height: 22px;
            color: var(--wp-admin-theme-color, #2271b1);
            flex-shrink: 0;
        }
        .wp-audit-content h2:first-of-type {
            margin-top: 20px;
        }

        /* --- Overview Section --- */
        #section-overview {
            background: var(--wp-admin-background-color, #fff);
            border: 1px solid var(--wp-admin-border-color, #c3c4c7);
            border-left: 4px solid var(--wp-admin-theme-color, #2271b1);
            padding: 15px 20px;
            margin: 20px 0;
            border-radius: 4px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        #section-overview h3 {
            margin: 0 0 20px 0;
            font-size: 18px;
            color: var(--ch-color-1, #1d2327);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        #section-overview h3 i {
            width: 22px;
            height: 22px;
            color: var(--wp-admin-theme-color, #2271b1);
        }
        .wp-audit-overview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }
        .wp-audit-overview-item {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            font-size: 14px;
            background: var(--wp-components-color-gray-100, #f9fafb);
            border: 1px solid var(--wp-admin-border-color-translucent, #e5e7eb);
            border-radius: 8px;
            padding: 16px;
            transition: all 0.2s ease;
        }
        .wp-audit-overview-item:hover {
            border-color: var(--wp-admin-theme-color, #2271b1);
            box-shadow: 0 2px 8px color-mix(in srgb, var(--wp-admin-theme-color, #2271b1) 10%, transparent);
        }
        .wp-audit-overview-item-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }
        .wp-audit-overview-icon {
            width: 20px;
            height: 20px;
            color: var(--wp-admin-theme-color, #2271b1);
            flex-shrink: 0;
        }
        .wp-audit-overview-item strong {
            display: block;
            color: var(--ch-color-1, #1d2327);
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .wp-audit-overview-item .value {
            font-size: 24px;
            color: var(--wp-admin-theme-color, #2271b1);
            font-weight: 700;
            display: block;
            margin-bottom: 8px;
            line-height: 1.2;
        }
        .wp-audit-overview-item span,
        .wp-audit-overview-item ul {
            font-size: 1.2em;
            color: var(--wp-admin-theme-color, #2271b1);
            font-weight: 600;
            display: block;
            margin-top: 4px;
        }
        .wp-audit-overview-item ul {
            font-size: 1em;
            margin: 4px 0 0 0;
            list-style: none;
            padding: 0;
        }
        .wp-audit-overview-item li {
            font-weight: 400;
            color: var(--wp-components-color-foreground, #1e1e1e);
        }
        .wp-audit-overview-item li .count {
            font-weight: 600;
            color: var(--wp-admin-theme-color, #2271b1);
        }
        .wp-audit-overview-divider {
            margin: 12px 0;
            border: none;
            border-top: 1px solid var(--wp-admin-border-color-translucent, #e5e7eb);
        }
        .wp-audit-overview-secondary {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--wp-admin-border-color-translucent, #e5e7eb);
        }
        .wp-audit-overview-secondary strong {
            font-size: 12px;
            color: var(--wp-components-color-foreground-tertiary, #757575);
        }
        .wp-audit-overview-secondary span {
            font-size: 14px;
            font-weight: 500;
            color: var(--wp-components-color-foreground-secondary, #50575e);
        }

        /* --- Report Tables --- */
        .wp-audit-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background: var(--wp-admin-background-color, #fff);
            border: 1px solid var(--wp-admin-border-color, #c3c4c7);
            font-family: monospace;
            font-size: 13px;
        }
        .wp-audit-table th {
            background-color: var(--wp-admin-theme-color, #2271b1);
            color: var(--ch-color-4, #fff);
            padding: 12px;
            text-align: left;
            font-weight: bold;
            border: 1px solid var(--wp-admin-border-color, #c3c4c7);
            position: relative;
        }
        .wp-audit-table th.sortable {
            cursor: pointer;
            user-select: none;
            padding-right: 30px;
            transition: background-color 0.2s ease;
        }
        .wp-audit-table th.sortable:hover {
            background-color: var(--wp-admin-theme-color-darker-10, #135e96);
        }
        .wp-audit-table th.sortable::after {
            content: '';
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 0;
            height: 0;
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
            border-top: 5px solid rgba(255, 255, 255, 0.4);
            opacity: 0.5;
        }
        .wp-audit-table th.sortable:hover::after {
            opacity: 1;
        }
        .wp-audit-table th.sortable.sort-asc::after {
            border-top: none;
            border-bottom: 5px solid white;
            opacity: 1;
        }
        .wp-audit-table th.sortable.sort-desc::after {
            border-top: 5px solid white;
            border-bottom: none;
            opacity: 1;
        }
        .wp-audit-table td {
            padding: 10px 12px;
            border: 1px solid var(--wp-admin-border-color, #c3c4c7);
            border-bottom: 1px solid var(--wp-admin-border-color, #c3c4c7);
            vertical-align: top;
        }
        .wp-audit-table tr:nth-child(even) {
            background-color: var(--wp-components-color-gray-100, #f6f7f7);
        }
        .wp-audit-table tr:hover {
            background-color: var(--wp-components-color-gray-200, #f0f0f1);
        }

        /* Nested Table Style (for Templates) */
        .wp-audit-table .wp-audit-table {
            font-family: inherit;
            margin: 0;
            background: var(--wp-components-color-gray-50, #fafafa);
            border: 1px solid var(--wp-admin-border-color-translucent, #dcdcde);
            border-radius: 4px;
            width: 100%;
        }
        .wp-audit-table .wp-audit-table th {
            font-size: 11px;
            padding: 8px;
            background: var(--ch-color-2, #2c3338);
        }
        .wp-audit-table .wp-audit-table td {
            font-size: 12px;
            padding: 6px 8px;
        }

        /* --- Custom Badge for CPT/Taxonomies --- */
        .wp-audit-custom-badge {
            display: inline-block;
            line-height: 1;
        }
        .wp-audit-custom-badge i {
            animation: shimmer 2s ease-in-out infinite;
        }
        @keyframes shimmer {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }

        /* --- Status Indicators --- */
        .audit-status-good {
            color: #28a745;
            font-weight: bold;
        }
        .audit-status-warning {
            color: #ffb900; /* WP Yellow */
            font-weight: bold;
        }
        .audit-status-bad {
            color: #dc3545;
            font-weight: bold;
        }

        /* --- Misc Styles --- */
        .audit-summary {
            background: white;
            padding: 15px;
            border-left: 4px solid #0073aa;
            margin-bottom: 20px;
            border-radius: 3px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            font-size: 14px;
        }
        .audit-section-header {
            font-size: 16px;
            font-weight: 600;
            color: #2c3338;
            margin-top: 25px;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #ddd;
        }
        .wp-audit-tooltip {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 1px solid #ccc;
            border-radius: 50%;
            text-align: center;
            line-height: 14px;
            font-size: 12px;
            color: #999;
            background: #f9f9f9;
            cursor: help;
        }
        
        /* --- Progress Bar --- */
        #wp-audit-progress {
            width: 100%;
            max-width: 300px;
            max-width: 100%;
        }
        .wp-audit-progress-bar {
            width: 100%;
            height: 12px;
            background-color: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
        }
        .wp-audit-progress-inner {
            height: 100%;
            background-color: #0073aa;
            background-color: var(--wp-admin-theme-color, #2271b1);
            transition: width 0.3s ease-in-out;
        }

        /* ========================================
           MOBILE RESPONSIVENESS
           ======================================== */

        /* --- Tablet/Large Mobile (max-width: 1024px) --- */
        @media (max-width: 1024px) {
            .wp-audit-wrapper {
                gap: 15px;
            }
            .wp-audit-nav {
                width: 200px;
            }
            .wp-audit-content {
                padding: 1px 15px 15px 15px;
            }
            .wp-audit-overview-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 12px;
            }
        }

        /* --- Tablet (max-width: 768px) --- */
        @media (max-width: 768px) {
            .wp-audit-wrapper {
                flex-direction: column;
                gap: 20px;
            }

            /* Navigation becomes horizontal/stacked at top */
            .wp-audit-nav {
                width: 100%;
                position: relative;
                top: auto;
                margin-bottom: 0;
            }
            .wp-audit-nav h3 {
                font-size: 15px;
            }
            .wp-audit-nav ul {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 0;
                padding: 0;
            }
            .wp-audit-nav li {
                border-bottom: 1px solid var(--wp-admin-border-color, #c3c4c7);
            }
            .wp-audit-nav a {
                padding: 12px 15px;
                border-left: none;
                border-bottom: 3px solid transparent;
            }
            .wp-audit-nav a.active {
                border-left: none;
                border-bottom: 3px solid var(--wp-admin-theme-color, #2271b1);
            }

            /* Content area adjustments */
            .wp-audit-content {
                padding: 1px 15px 15px 15px;
                max-width: 100%;
            }
            .wp-audit-content h2 {
                font-size: 18px;
                margin-top: 30px;
                scroll-margin-top: 20px;
            }
            .wp-audit-content h2 i {
                width: 20px;
                height: 20px;
            }

            /* Overview grid becomes single column */
            .wp-audit-overview-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .wp-audit-overview-item {
                padding: 14px;
            }

            /* Tables become scrollable */
            .wp-audit-table-wrapper {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .wp-audit-table {
                min-width: 600px;
                font-size: 12px;
            }
            .wp-audit-table th,
            .wp-audit-table td {
                padding: 10px 8px;
            }

            /* Section overview */
            #section-overview {
                padding: 12px 15px;
            }
            #section-overview h3 {
                font-size: 16px;
            }
        }

        /* --- Mobile (max-width: 480px) --- */
        @media (max-width: 480px) {
            .wp-audit-wrapper {
                gap: 15px;
            }

            /* Navigation becomes fully stacked */
            .wp-audit-nav ul {
                grid-template-columns: 1fr;
            }
            .wp-audit-nav a {
                font-size: 12px;
                padding: 10px 12px;
            }
            .wp-audit-nav a i {
                width: 14px;
                height: 14px;
            }

            /* Content area more compact */
            .wp-audit-content {
                padding: 1px 10px 10px 10px;
            }
            .wp-audit-content h2 {
                font-size: 16px;
                margin-top: 25px;
                padding-bottom: 8px;
                gap: 8px;
            }
            .wp-audit-content h2 i {
                width: 18px;
                height: 18px;
            }

            /* Overview items more compact */
            .wp-audit-overview-item {
                padding: 12px;
            }
            .wp-audit-overview-item .value {
                font-size: 20px;
            }
            .wp-audit-overview-item strong {
                font-size: 12px;
            }
            .wp-audit-overview-icon {
                width: 18px;
                height: 18px;
            }

            /* Tables become more compact and scrollable */
            .wp-audit-table {
                display: block;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                font-size: 11px;
                min-width: 100%;
                white-space: nowrap;
            }
            .wp-audit-table th,
            .wp-audit-table td {
                padding: 8px 6px;
                font-size: 11px;
            }
            .wp-audit-table th {
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }

            /* Nested tables */
            .wp-audit-table .wp-audit-table {
                font-size: 10px;
            }
            .wp-audit-table .wp-audit-table th,
            .wp-audit-table .wp-audit-table td {
                padding: 5px 4px;
                font-size: 10px;
            }

            /* Section overview more compact */
            #section-overview {
                padding: 10px 12px;
                margin: 15px 0;
            }
            #section-overview h3 {
                font-size: 15px;
                margin-bottom: 15px;
            }
            #section-overview h3 i {
                width: 18px;
                height: 18px;
            }

            /* Buttons stack vertically */
            .button,
            .wp-core-ui .button {
                display: block;
                width: 100%;
                margin-bottom: 10px;
                text-align: center;
            }

            /* Progress bar */
            #wp-audit-progress {
                margin: 10px 0;
            }

            /* Misc adjustments */
            .audit-summary {
                padding: 12px;
                font-size: 13px;
            }
            .audit-section-header {
                font-size: 14px;
                margin-top: 20px;
            }
        }

        /* --- Small Mobile (max-width: 375px) --- */
        @media (max-width: 375px) {
            .wp-audit-content {
                padding: 1px 8px 8px 8px;
            }
            .wp-audit-content h2 {
                font-size: 15px;
                gap: 6px;
            }
            .wp-audit-content h2 i {
                width: 16px;
                height: 16px;
            }

            .wp-audit-nav a {
                font-size: 11px;
                padding: 8px 10px;
            }

            .wp-audit-overview-item {
                padding: 10px;
            }
            .wp-audit-overview-item .value {
                font-size: 18px;
            }
            .wp-audit-overview-item strong {
                font-size: 11px;
            }

            .wp-audit-table {
                font-size: 10px;
            }
            .wp-audit-table th,
            .wp-audit-table td {
                padding: 6px 4px;
                font-size: 10px;
            }

            #section-overview {
                padding: 8px 10px;
            }
            #section-overview h3 {
                font-size: 14px;
            }
        }

        /* --- Print Styles --- */
        @media print {
            .wp-audit-nav,
            .button,
            #wp-audit-progress {
                display: none !important;
            }
            .wp-audit-wrapper {
                flex-direction: column;
            }
            .wp-audit-content {
                max-width: 100%;
                border: none;
                box-shadow: none;
            }
            .wp-audit-table {
                page-break-inside: avoid;
            }
        }
    </style>

    <!-- Lucide Icons CDN -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <div class="wrap">
        <h1>Site Audit Report</h1>
        
       <!-- ===== OVERVIEW SECTION ===== -->
        <div id="section-overview">
            <h3>
                <i data-lucide="layout-template"></i>
                Website Overview
            </h3>
            <div class="wp-audit-overview-grid">
                <div class="wp-audit-overview-item">
                    <div class="wp-audit-overview-item-header">
                        <i data-lucide="palette" class="wp-audit-overview-icon"></i>
                        <strong>Active Theme</strong>
                    </div>
                    <span class="value"><?php echo esc_html( $active_theme->get( 'Name' ) ); ?></span>
                    <?php if ( $parent_theme ): ?>
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
                        <i data-lucide="file-text" class="wp-audit-overview-icon"></i>
                        <strong>Published Pages</strong>
                    </div>
                    <span class="value"><?php echo intval( $page_count ); ?></span>
                    <div class="wp-audit-overview-secondary">
                        <strong>Page Templates</strong>
                        <span><?php echo intval($theme_template_count); ?> in theme</span>
                    </div>
                </div>
                
                <div class="wp-audit-overview-item">
                    <div class="wp-audit-overview-item-header">
                        <i data-lucide="layers" class="wp-audit-overview-icon"></i>
                        <strong>Custom Post Types</strong>
                    </div>
                    <span class="value"><?php echo intval( $total_cpt_count ); ?></span>
                    <?php if ( ! empty( $top_cpts ) ) : ?>
                        <div class="wp-audit-overview-secondary">
                            <strong>Top 3 CPTs</strong>
                            <ul style="font-size: 0.85em; margin-top: 6px;">
                                <?php foreach ( $top_cpts as $cpt ) : ?>
                                    <li>
                                        <?php echo esc_html( $cpt->post_type ); ?>:
                                        <span class="count"><?php echo intval( $cpt->count ); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="wp-audit-overview-item">
                    <div class="wp-audit-overview-item-header">
                        <i data-lucide="puzzle" class="wp-audit-overview-icon"></i>
                        <strong>Active Plugins</strong>
                    </div>
                    <span class="value"><?php
                        if ( ! function_exists( 'get_plugins' ) ) {
                            require_once ABSPATH . 'wp-admin/includes/plugin.php';
                        }
                        $active_plugins_overview = get_option( 'active_plugins' );
                        echo intval( count( $active_plugins_overview ) );
                    ?></span>
                    <div class="wp-audit-overview-secondary">
                        <strong>WordPress Version</strong>
                        <span><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
                    </div>
                </div>
                
                <div class="wp-audit-overview-item">
                    <div class="wp-audit-overview-item-header">
                        <i data-lucide="clipboard-list" class="wp-audit-overview-icon"></i>
                        <strong>Total Forms</strong>
                    </div>
                    <span class="value"><?php
                        // Quick forms count - we'll calculate this before the overview section (Cached for 15 minutes)
                        $overview_forms_count = 0;

                        // Gravity Forms (Cached for 15 minutes)
                        if ( class_exists('GFForms') ) {
                            global $wpdb;
                            $cache_key_gf = 'wp_audit_gf_count';
                            $gf_count = get_transient( $cache_key_gf );
                            if ( false === $gf_count ) {
                                $gf_table = $wpdb->prefix . 'gf_form';
                                $gf_count = $wpdb->get_var(
                                    $wpdb->prepare("SELECT COUNT(*) FROM {$gf_table}")
                                );
                                set_transient( $cache_key_gf, $gf_count, 15 * MINUTE_IN_SECONDS );
                            }
                            $overview_forms_count += intval($gf_count);
                        }

                        // Contact Form 7 (Cached for 15 minutes)
                        if ( class_exists('WPCF7_ContactForm') ) {
                            $cache_key_cf7 = 'wp_audit_cf7_count';
                            $cf7_count = get_transient( $cache_key_cf7 );
                            if ( false === $cf7_count ) {
                                $cf7_count = $wpdb->get_var(
                                    $wpdb->prepare(
                                        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != %s",
                                        'wpcf7_contact_form',
                                        'trash'
                                    )
                                );
                                set_transient( $cache_key_cf7, $cf7_count, 15 * MINUTE_IN_SECONDS );
                            }
                            $overview_forms_count += intval($cf7_count);
                        }

                        // WPForms (Cached for 15 minutes)
                        if ( class_exists('WPForms') && function_exists('wpforms') ) {
                            $cache_key_wpf = 'wp_audit_wpf_count';
                            $wpf_count = get_transient( $cache_key_wpf );
                            if ( false === $wpf_count ) {
                                $wpf_count = $wpdb->get_var(
                                    $wpdb->prepare(
                                        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != %s",
                                        'wpforms',
                                        'trash'
                                    )
                                );
                                set_transient( $cache_key_wpf, $wpf_count, 15 * MINUTE_IN_SECONDS );
                            }
                            $overview_forms_count += intval($wpf_count);
                        }

                        // Formidable Forms (Cached for 15 minutes)
                        if ( class_exists('FrmForm') ) {
                            $cache_key_frm = 'wp_audit_frm_count';
                            $frm_count = get_transient( $cache_key_frm );
                            if ( false === $frm_count ) {
                                $frm_count = $wpdb->get_var(
                                    $wpdb->prepare(
                                        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != %s",
                                        'frm_form',
                                        'trash'
                                    )
                                );
                                set_transient( $cache_key_frm, $frm_count, 15 * MINUTE_IN_SECONDS );
                            }
                            $overview_forms_count += intval($frm_count);
                        }

                        echo $overview_forms_count > 0 ? intval($overview_forms_count) : '<span style="color: #999;">None</span>';
                    ?></span>
                    <div class="wp-audit-overview-secondary">
                        <strong>Media Files</strong>
                        <span><?php
                            // Media count (Cached for 15 minutes)
                            $cache_key_media = 'wp_audit_media_count';
                            $media_count_overview = get_transient( $cache_key_media );
                            if ( false === $media_count_overview ) {
                                $media_count_overview = $wpdb->get_var(
                                    $wpdb->prepare(
                                        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
                                        'attachment'
                                    )
                                );
                                set_transient( $cache_key_media, $media_count_overview, 15 * MINUTE_IN_SECONDS );
                            }
                            echo intval($media_count_overview);
                        ?></span>
                    </div>
                </div>
                
                <div class="wp-audit-overview-item">
                    <div class="wp-audit-overview-item-header">
                        <i data-lucide="database" class="wp-audit-overview-icon"></i>
                        <strong>Database Size</strong>
                    </div>
                    <span class="value"><?php
                        $db_size_overview = $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT ROUND(SUM((data_length + index_length) / 1024 / 1024), 2)
                                FROM information_schema.tables
                                WHERE table_schema = %s",
                                DB_NAME
                            )
                        );
                        echo esc_html( $db_size_overview ) . ' MB';
                    ?></span>
                </div>
            </div>

            <!-- Visualizations Section -->
            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
                <h4 style="margin: 0 0 20px 0; font-size: 16px; color: var(--ch-color-1, #1d2327); font-weight: 600;">
                    <i data-lucide="bar-chart-3" style="width: 18px; height: 18px; vertical-align: middle; margin-right: 8px;"></i>
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
                    <div style="flex: 1; min-width: 250px;">
                        <h4 style="margin: 0 0 10px 0; font-size: 13px; color: #666;">Source Detection Scan</h4>
                        <div id="wp-audit-progress">
                            <div class="wp-audit-progress-bar" aria-hidden="true">
                                <div class="wp-audit-progress-inner" style="width:0%"></div>
                            </div>
                            <div id="wp-audit-progress-label" style="margin-top:6px;font-size:13px;color:#666;">Ready to scan</div>
                        </div>
                        <div style="margin-top:10px;">
                            <button id="wp-audit-rescan-btn" class="button">
                                <span class="dashicons dashicons-search" style="margin-top: 3px;"></span> 
                                Scan Post Type & Taxonomy Sources
                            </button>
                        </div>
                        <p style="margin-top:8px;font-size:11px;color:#666; line-height: 1.4;">
                            <strong>Optional:</strong> Scans files to detect where custom post types and taxonomies originate (plugin/theme/core).
                            Results are cached for 1 hour. <span style="color: #d63638;">⚠️ First scan may take up to 60s on sites with many plugins.</span>
                        </p>
                    </div>
                    
                    <div style="flex: 1; min-width: 200px;">
                        <h4 style="margin: 0 0 10px 0; font-size: 13px; color: #666;">Export Report</h4>
                        <button id="wp-audit-export-btn" class="button button-primary" style="height: 36px;">
                            <span class="dashicons dashicons-download" style="margin-top: 6px;"></span>
                            Download Report as .md
                        </button>
                        <p style="margin-top:8px;font-size:11px;color:#666; line-height: 1.4;">
                            Generates a complete markdown report.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="wp-audit-wrapper">
            
            <!-- ===== STICKY NAVIGATION ===== -->
            <nav class="wp-audit-nav">
                <!-- <h3>Report Sections</h3> -->
                <ul>
                    <li><a href="#section-post-types" class="active"><i data-lucide="layers"></i><span>Post Types</span></a></li>
                    <li><a href="#section-taxonomies"><i data-lucide="tags"></i><span>Taxonomies</span></a></li>
                    <li><a href="#section-pages"><i data-lucide="files"></i><span>Pages & Hierarchy</span></a></li>
                    <li><a href="#section-forms"><i data-lucide="clipboard-list"></i><span>Forms & Form Plugins</span></a></li>
                    <li><a href="#section-database"><i data-lucide="database"></i><span>Database Health</span></a></li>
                    <li><a href="#section-postmeta"><i data-lucide="info"></i><span>Post Meta Analysis</span></a></li>
                    <li><a href="#section-plugins"><i data-lucide="puzzle"></i><span>Plugin Audit</span></a></li>
                    <li><a href="#section-theme"><i data-lucide="palette"></i><span>Theme & Front-End</span></a></li>
                    <li><a href="#section-users"><i data-lucide="users"></i><span>User Roles</span></a></li>
                    <li><a href="#section-media"><i data-lucide="image"></i><span>Media & Assets</span></a></li>
                    <li><a href="#section-urls"><i data-lucide="link"></i><span>URL Structure</span></a></li>
                    <li><a href="#section-security"><i data-lucide="shield"></i><span>Security Audit</span></a></li>
                    <li><a href="#section-performance"><i data-lucide="gauge"></i><span>Performance</span></a></li>
                    <li><a href="#section-seo"><i data-lucide="search"></i><span>SEO & Metadata</span></a></li>
                    <li><a href="#section-rfq"><i data-lucide="clipboard-check"></i><span>RFQ Requirements</span></a></li>
                    <li><a href="#section-cleanup"><i data-lucide="trash-2"></i><span>Cleanup & Technical Debt</span></a></li>
                </ul>
            </nav>

            <!-- ===== MAIN AUDIT CONTENT ===== -->
            <div class="wp-audit-content">
                

                <?php
                // ===== 1. POST TYPES & CONTENT STRUCTURE =====
                ?>
                <h2 id="section-post-types"><i data-lucide="layers"></i> Post Types & Content Structure</h2>
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
                                <th>Source <span class="wp-audit-tooltip" title="Heuristic: We search plugin and theme PHP files for register_post_type/register_taxonomy calls to guess the source. Dynamic registrations may not be detected.">?</span></th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $total_posts_all = 0;
                            foreach ( $post_types as $post_type ) {
                                $published = $wpdb->get_var( $wpdb->prepare(
                                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
                                    $post_type->name
                                ) );
                                $draft = $wpdb->get_var( $wpdb->prepare(
                                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'draft'",
                                    $post_type->name
                                ) );
                                $scheduled = $wpdb->get_var( $wpdb->prepare(
                                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'future'",
                                    $post_type->name
                                ) );
                                $trash = $wpdb->get_var( $wpdb->prepare(
                                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'trash'",
                                    $post_type->name
                                ) );
                                $total = $published + $draft + $scheduled + $trash;
                                $total_posts_all += $total;
                                $status_class = $total > 0 ? 'audit-status-good' : 'audit-status-warning';

                                // Determine if this is a custom post type
                                $is_custom_cpt = ! $post_type->_builtin && $post_type->show_in_menu;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $post_type->name ); ?></strong>
                                        <?php if ( $is_custom_cpt ) : ?>
                                            <span class="wp-audit-custom-badge" title="Custom Post Type">
                                                <i data-lucide="star" style="width: 14px; height: 14px; color: #f59e0b; vertical-align: middle; margin-left: 4px;"></i>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( $post_type->label ); ?></td>
                                    <td><?php echo intval( $published ); ?></td>
                                    <td><?php echo intval( $draft ); ?></td>
                                    <td><?php echo intval( $scheduled ); ?></td>
                                    <td><?php echo intval( $trash ); ?></td>
                                    <td><strong><?php echo intval( $total ); ?></strong></td>
                                    <td class="wp-audit-source-cell" data-name="<?php echo esc_attr( $post_type->name ); ?>" data-type="post_type">Scanning...</td>
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
                        <strong>Total Custom Posts (all statuses):</strong> <?php echo intval( $total_posts_all ); ?>
                    </div>
                    <?php
                } else {
                    echo '<p><em>No custom post types found.</em></p>';
                }
                ?>

                <?php
                // ===== 2. TAXONOMIES =====
                ?>
                <h2 id="section-taxonomies"><i data-lucide="tags"></i> Taxonomies & Classification System</h2>
                <?php
                $taxonomies = get_taxonomies( array(), 'objects' );
                if ( ! empty( $taxonomies ) ) {
                    ?>
                    <table class="wp-audit-table">
                        <thead>
                            <tr>
                                <th>Taxonomy</th>
                                <th>Label</th>
                                <th>Total Terms</th>
                                <th>Hierarchical</th>
                                <th>Source <span class="wp-audit-tooltip" title="Heuristic: We search plugin and theme PHP files for register_post_type/register_taxonomy calls to guess the source. Dynamic registrations may not be detected.">?</span></th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ( $taxonomies as $taxonomy ) {
                                $term_count = $wpdb->get_var( $wpdb->prepare(
                                    "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
                                    $taxonomy->name
                                ) );
                                $hierarchical = $taxonomy->hierarchical ? 'Yes' : 'No';
                                $status_class = $term_count > 0 ? 'audit-status-good' : 'audit-status-warning';

                                // Determine if this is a custom taxonomy
                                $is_custom_taxonomy = ! $taxonomy->_builtin && $taxonomy->show_in_menu;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $taxonomy->name ); ?></strong>
                                        <?php if ( $is_custom_taxonomy ) : ?>
                                            <span class="wp-audit-custom-badge" title="Custom Taxonomy">
                                                <i data-lucide="star" style="width: 14px; height: 14px; color: #f59e0b; vertical-align: middle; margin-left: 4px;"></i>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( $taxonomy->label ); ?></td>
                                    <td><?php echo intval( $term_count ); ?></td>
                                    <td><?php echo esc_html( $hierarchical ); ?></td>
                                    <td class="wp-audit-source-cell" data-name="<?php echo esc_attr( $taxonomy->name ); ?>" data-type="taxonomy">Scanning...</td>
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
                    echo '<p><em>No custom taxonomies found.</em></p>';
                }
                ?>

                <?php
                // ===== 3. PAGES & HIERARCHY =====
                ?>
                <h2 id="section-pages"><i data-lucide="files"></i> Pages & Hierarchy</h2>
                <?php
                // Page count already queried as $page_count
                $draft_pages = $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'draft'"
                );
                
                // Calculate max nesting depth with a single query + in-memory map.
                // The old approach called get_post() inside a while loop for every page
                // (O(n²) DB hits). This fetches only ID/parent in one query, builds a
                // lookup array, then resolves each page's depth without touching the DB.
                $page_rows = $wpdb->get_results(
                    "SELECT ID, post_parent FROM {$wpdb->posts}
                     WHERE post_type = 'page' AND post_status = 'publish'",
                    ARRAY_A
                );

                $parent_map = array();
                foreach ( $page_rows as $row ) {
                    $parent_map[ (int) $row['ID'] ] = (int) $row['post_parent'];
                }

                $max_depth = 0;
                foreach ( $parent_map as $page_id => $parent_id ) {
                    $depth   = 0;
                    $current = $parent_id;
                    $visited = array(); // Guard against circular references.
                    while ( $current > 0 && ! isset( $visited[ $current ] ) ) {
                        $visited[ $current ] = true;
                        $current = isset( $parent_map[ $current ] ) ? $parent_map[ $current ] : 0;
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
                                <?php echo intval($theme_template_count); ?> Templates
                                <?php if ( ! empty( $template_usage_counts ) ): ?>
                                    <table class="wp-audit-table" style="margin: 10px 0 0; font-size: 12px;">
                                        <thead>
                                            <tr><th>Template Name</th><th>Pages Using It</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $template_usage_counts as $name => $count): ?>
                                                <tr>
                                                    <td><?php echo esc_html($name); ?></td>
                                                    <td><span class="<?php echo $count > 0 ? 'audit-status-good' : 'audit-status-warning'; ?>"><?php echo intval($count); ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <p style="margin: 5px 0 0;">(No templates detected)</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php
                // ===== 4. FORMS (UPDATED FOR ACTIVE/INACTIVE COUNT) =====
                ?>
                <h2 id="section-forms"><i data-lucide="clipboard-list"></i> Forms & Form Plugins</h2>
                <?php
                $forms_summary = array();

                // Gravity Forms (Cached for 15 minutes)
                if ( class_exists('GFForms') ) {
                    $gf_table = $wpdb->prefix . 'gf_form';

                    $cache_key_gf_active = 'wp_audit_gf_active';
                    $gf_active = get_transient( $cache_key_gf_active );
                    if ( false === $gf_active ) {
                        $gf_active = $wpdb->get_var(
                            $wpdb->prepare("SELECT COUNT(*) FROM {$gf_table} WHERE is_active = %d", 1)
                        );
                        set_transient( $cache_key_gf_active, $gf_active, 15 * MINUTE_IN_SECONDS );
                    }

                    $cache_key_gf_inactive = 'wp_audit_gf_inactive';
                    $gf_inactive = get_transient( $cache_key_gf_inactive );
                    if ( false === $gf_inactive ) {
                        $gf_inactive = $wpdb->get_var(
                            $wpdb->prepare("SELECT COUNT(*) FROM {$gf_table} WHERE is_active = %d", 0)
                        );
                        set_transient( $cache_key_gf_inactive, $gf_inactive, 15 * MINUTE_IN_SECONDS );
                    }

                    $forms_summary['Gravity Forms'] = array('total' => intval($gf_active) + intval($gf_inactive), 'active' => intval($gf_active), 'inactive' => intval($gf_inactive));
                }

                // Contact Form 7 (Cached for 15 minutes)
                if ( class_exists('WPCF7_ContactForm') ) {
                    $cache_key_cf7_active = 'wp_audit_cf7_active';
                    $cf7_active = get_transient( $cache_key_cf7_active );
                    if ( false === $cf7_active ) {
                        $cf7_active = $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
                                'wpcf7_contact_form',
                                'publish'
                            )
                        );
                        set_transient( $cache_key_cf7_active, $cf7_active, 15 * MINUTE_IN_SECONDS );
                    }

                    // Draft, Pending, etc. (Anything not published or trashed)
                    $cache_key_cf7_inactive = 'wp_audit_cf7_inactive';
                    $cf7_inactive = get_transient( $cache_key_cf7_inactive );
                    if ( false === $cf7_inactive ) {
                        $cf7_inactive = $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN (%s, %s)",
                                'wpcf7_contact_form',
                                'publish',
                                'trash'
                            )
                        );
                        set_transient( $cache_key_cf7_inactive, $cf7_inactive, 15 * MINUTE_IN_SECONDS );
                    }

                    $forms_summary['Contact Form 7'] = array('total' => intval($cf7_active) + intval($cf7_inactive), 'active' => intval($cf7_active), 'inactive' => intval($cf7_inactive));
                }

                // WPForms (Uses wpforms post type) (Cached for 15 minutes)
                if ( class_exists('WPForms') && function_exists('wpforms') ) {
                    $cache_key_wpf_active = 'wp_audit_wpf_active';
                    $wpf_active = get_transient( $cache_key_wpf_active );
                    if ( false === $wpf_active ) {
                        $wpf_active = $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
                                'wpforms',
                                'publish'
                            )
                        );
                        set_transient( $cache_key_wpf_active, $wpf_active, 15 * MINUTE_IN_SECONDS );
                    }

                    $cache_key_wpf_inactive = 'wp_audit_wpf_inactive';
                    $wpf_inactive = get_transient( $cache_key_wpf_inactive );
                    if ( false === $wpf_inactive ) {
                        $wpf_inactive = $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN (%s, %s)",
                                'wpforms',
                                'publish',
                                'trash'
                            )
                        );
                        set_transient( $cache_key_wpf_inactive, $wpf_inactive, 15 * MINUTE_IN_SECONDS );
                    }

                    $forms_summary['WPForms'] = array('total' => intval($wpf_active) + intval($wpf_inactive), 'active' => intval($wpf_active), 'inactive' => intval($wpf_inactive));
                }

                // Formidable Forms (Uses frm_form post type) (Cached for 15 minutes)
                if ( class_exists('FrmForm') ) {
                    $cache_key_frm_active = 'wp_audit_frm_active';
                    $frm_active = get_transient( $cache_key_frm_active );
                    if ( false === $frm_active ) {
                        $frm_active = $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
                                'frm_form',
                                'publish'
                            )
                        );
                        set_transient( $cache_key_frm_active, $frm_active, 15 * MINUTE_IN_SECONDS );
                    }

                    $cache_key_frm_inactive = 'wp_audit_frm_inactive';
                    $frm_inactive = get_transient( $cache_key_frm_inactive );
                    if ( false === $frm_inactive ) {
                        $frm_inactive = $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN (%s, %s)",
                                'frm_form',
                                'publish',
                                'trash'
                            )
                        );
                        set_transient( $cache_key_frm_inactive, $frm_inactive, 15 * MINUTE_IN_SECONDS );
                    }

                    $forms_summary['Formidable Forms'] = array('total' => intval($frm_active) + intval($frm_inactive), 'active' => intval($frm_active), 'inactive' => intval($frm_inactive));
                }
                ?>
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
                        <?php if ( ! empty($forms_summary) ) : ?>
                            <?php foreach ($forms_summary as $plugin => $data): ?>
                                <tr>
                                    <td><?php echo esc_html($plugin); ?></td>
                                    <td><?php echo intval($data['total']); ?></td>
                                    <td><span class="audit-status-good"><?php echo intval($data['active']); ?></span></td>
                                    <td><span class="<?php echo $data['inactive'] > 0 ? 'audit-status-warning' : 'audit-status-good'; ?>"><?php echo intval($data['inactive']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4"><em>No supported form plugins detected (Gravity Forms, CF7, WPForms, Formidable).</em></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php
                // ===== 5. DATABASE HEALTH =====
                ?>
                <h2 id="section-database"><i data-lucide="database"></i> Database & Content Quality</h2>
                <?php
                // Orphaned postmeta (Cached for 15 minutes)
                $cache_key_orphaned_pm = 'wp_audit_orphaned_postmeta';
                $orphaned_postmeta = get_transient( $cache_key_orphaned_pm );
                if ( false === $orphaned_postmeta ) {
                    $orphaned_postmeta = $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                        LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                        WHERE p.ID IS NULL"
                    );
                    set_transient( $cache_key_orphaned_pm, $orphaned_postmeta, 15 * MINUTE_IN_SECONDS );
                }

                // Orphaned term relationships (Cached for 15 minutes)
                $cache_key_orphaned_terms = 'wp_audit_orphaned_terms';
                $orphaned_terms = get_transient( $cache_key_orphaned_terms );
                if ( false === $orphaned_terms ) {
                    $orphaned_terms = $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
                        LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID
                        WHERE p.ID IS NULL AND tr.object_id != 0"
                    );
                    set_transient( $cache_key_orphaned_terms, $orphaned_terms, 15 * MINUTE_IN_SECONDS );
                }

                // Posts with excessive meta (Optimized Query + Cached for 15 minutes)
                $cache_key_excessive_meta = 'wp_audit_excessive_meta';
                $posts_excessive_meta = get_transient( $cache_key_excessive_meta );
                if ( false === $posts_excessive_meta ) {
                    // Optimized: removed subquery wrapper
                    $posts_excessive_meta = $wpdb->get_var(
                        "SELECT COUNT(DISTINCT post_id)
                         FROM {$wpdb->postmeta}
                         GROUP BY post_id
                         HAVING COUNT(*) > 50"
                    );
                    set_transient( $cache_key_excessive_meta, $posts_excessive_meta, 15 * MINUTE_IN_SECONDS );
                }

                // Database size (Cached for 15 minutes)
                $cache_key_db_size = 'wp_audit_db_size';
                $db_size = get_transient( $cache_key_db_size );
                if ( false === $db_size ) {
                    $db_size = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT ROUND(SUM((data_length + index_length) / 1024 / 1024), 2)
                            FROM information_schema.tables
                            WHERE table_schema = %s",
                            DB_NAME
                        )
                    );
                    set_transient( $cache_key_db_size, $db_size, 15 * MINUTE_IN_SECONDS );
                }

                // Table count (Cached for 15 minutes)
                $cache_key_table_count = 'wp_audit_table_count';
                $table_count = get_transient( $cache_key_table_count );
                if ( false === $table_count ) {
                    $table_count = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s",
                             DB_NAME
                        )
                    );
                    set_transient( $cache_key_table_count, $table_count, 15 * MINUTE_IN_SECONDS );
                }
                ?>
                <table class="wp-audit-table">
                    <tbody>
                        <tr>
                            <td><strong>Orphaned Postmeta Entries</strong></td>
                            <td><span class="<?php echo intval( $orphaned_postmeta ) > 0 ? 'audit-status-bad' : 'audit-status-good'; ?>">
                                <?php echo intval( $orphaned_postmeta ); ?>
                            </span></td>
                        </tr>
                        <tr>
                            <td><strong>Orphaned Term Relationships</strong></td>
                            <td><span class="<?php echo intval( $orphaned_terms ) > 0 ? 'audit-status-bad' : 'audit-status-good'; ?>">
                                <?php echo intval( $orphaned_terms ); ?>
                            </span></td>
                        </tr>
                        <tr>
                            <td><strong>Posts with Excessive Meta (>50 entries)</strong></td>
                            <td><span class="<?php echo intval( $posts_excessive_meta ) > 0 ? 'audit-status-warning' : 'audit-status-good'; ?>">
                                <?php echo intval( $posts_excessive_meta ); ?>
                            </span></td>
                        </tr>
                        <tr>
                            <td><strong>Database Size (MB)</strong></td>
                            <td><?php echo esc_html( $db_size ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Total Tables</strong></td>
                            <td><?php echo intval( $table_count ); ?></td>
                        </tr>
                    </tbody>
                </table>

                <?php
                // ===== 6. POSTMETA ANALYSIS =====
                ?>
                <h2 id="section-postmeta"><i data-lucide="info"></i> Custom Post Meta Analysis</h2>
                <?php
                $postmeta_keys = $wpdb->get_results(
                    "SELECT meta_key, COUNT(DISTINCT post_id) as usage_count, COUNT(*) as total_entries
                    FROM {$wpdb->postmeta}
                    WHERE meta_key NOT LIKE '\_%'
                    GROUP BY meta_key
                    ORDER BY usage_count DESC
                    LIMIT 50"
                );

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
                <h2 id="section-plugins"><i data-lucide="puzzle"></i> Plugin & Dependency Audit</h2>
                <?php
                if ( ! function_exists( 'get_plugins' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $active_plugins = get_option( 'active_plugins' );
                $all_plugins = get_plugins();
                $inactive_plugins = array_diff_key( $all_plugins, array_flip( $active_plugins ) );
                ?>
                <div class="audit-section-header">Active Plugins (<?php echo count( $active_plugins ); ?>)</div>
                <?php
                if ( ! empty( $active_plugins ) ) {
                    ?>
                    <table class="wp-audit-table">
                        <thead>
                            <tr>
                                <th>Plugin Name</th>
                                <th>Version</th>
                                <th>Author</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ( $active_plugins as $plugin ) {
                                if( ! isset($all_plugins[ $plugin ]) ) continue;
                                $plugin_data = $all_plugins[ $plugin ];
                                $status_class = 'audit-status-good';
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $plugin_data['Name'] ); ?></strong></td>
                                    <td><?php echo esc_html( $plugin_data['Version'] ); ?></td>
                                    <td><?php echo isset($plugin_data['Author']) ? esc_html( $plugin_data['Author'] ) : 'N/A'; ?></td>
                                    <td><span class="<?php echo esc_attr( $status_class ); ?>">Active</span></td>
                                </tr>
                                <?php
                            }
                            ?>
                        </tbody>
                    </table>
                    <?php
                }

                echo '<div class="audit-section-header">Inactive Plugins (' . count( $inactive_plugins ) . ')</div>';
                if ( ! empty( $inactive_plugins ) ) {
                    ?>
                    <table class="wp-audit-table">
                        <thead>
                            <tr>
                                <th>Plugin Name</th>
                                <th>Version</th>
                                <th>Author</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ( $inactive_plugins as $plugin_file => $plugin_data ) {
                                $status_class = 'audit-status-warning';
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $plugin_data['Name'] ); ?></strong></td>
                                    <td><?php echo esc_html( $plugin_data['Version'] ); ?></td>
                                    <td><?php echo isset($plugin_data['Author']) ? esc_html( $plugin_data['Author'] ) : 'N/A'; ?></td>
                                    <td><span class="<?php echo esc_attr( $status_class ); ?>">Inactive</span></td>
                                </tr>
                                <?php
                            }
                            ?>
                        </tbody>
                    </table>
                    <?php
                } else {
                    echo '<p><em>No inactive plugins found.</em></p>';
                }
                ?>

                <?php
                // ===== 8. THEME =====
                ?>
                <h2 id="section-theme"><i data-lucide="palette"></i> Theme & Front-End</h2>
                <?php
                // Theme data already fetched as $active_theme and $parent_theme
                ?>
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
                    </tbody>
                </table>

                <?php
                // ===== 9. USERS & ROLES =====
                ?>
                <h2 id="section-users"><i data-lucide="users"></i> User Roles, Permissions & User Management</h2>
                <?php
                $total_users = count_users();
                $user_roles = wp_roles()->roles;
                ?>
                <table class="wp-audit-table">
                    <thead>
                        <tr>
                            <th>Role</th>
                            <th>Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Total Users</strong></td>
                            <td><strong><?php echo intval( $total_users['total_users'] ); ?></strong></td>
                        </tr>
                        <?php
                        foreach( $total_users['avail_roles'] as $role => $count ) {
                            ?>
                            <tr>
                                <td><?php echo esc_html( ucwords(str_replace('_', ' ', $role)) ); ?></td>
                                <td><?php echo intval( $count ); ?></td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>

                <?php
                // ===== 10. MEDIA =====
                ?>
                <h2 id="section-media"><i data-lucide="image"></i> Media & Assets</h2>
                <?php
                $total_media = $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
                );

                // Note: Orphaned media check is a heuristic. This checks for attachments whose parent post is NOT a post, page, or another attachment.
                $orphaned_media = $wpdb->get_var(
                    "SELECT COUNT(p.ID) 
                    FROM {$wpdb->posts} p 
                    LEFT JOIN {$wpdb->posts} pp ON p.post_parent = pp.ID
                    WHERE p.post_type = 'attachment' 
                    AND p.post_status = 'inherit' 
                    AND p.post_parent != 0
                    AND pp.ID IS NULL"
                );

                $image_count = $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
                );
                ?>
                <table class="wp-audit-table">
                    <tbody>
                        <tr>
                            <td><strong>Total Media Files</strong></td>
                            <td><?php echo intval( $total_media ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Images</strong></td>
                            <td><?php echo intval( $image_count ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Orphaned Media (Attached to non-existent posts)</strong></td>
                            <td><span class="<?php echo intval( $orphaned_media ) > 0 ? 'audit-status-warning' : 'audit-status-good'; ?>">
                                <?php echo intval( $orphaned_media ); ?>
                            </span></td>
                        </tr>
                    </tbody>
                </table>

                <?php
                // ===== 11. URL STRUCTURE & PERMALINKS =====
                ?>
                <h2 id="section-urls"><i data-lucide="link"></i> URL Structure & Permalinks</h2>
                <?php
                $permalink_structure = get_option( 'permalink_structure' );
                $home_url = home_url();
                $site_url = site_url();
                ?>
                <table class="wp-audit-table">
                    <tbody>
                        <tr>
                            <td><strong>Home URL</strong></td>
                            <td><?php echo esc_url( $home_url ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Site URL</strong></td>
                            <td><?php echo esc_url( $site_url ); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Permalink Structure</strong></td>
                            <td><code><?php echo esc_html( $permalink_structure ? $permalink_structure : 'Default (/?p=123)' ); ?></code></td>
                        </tr>
                    </tbody>
                </table>

                <?php
                // ===== 11. SECURITY AUDIT (NEW) =====
                ?>
                <h2 id="section-security"><i data-lucide="shield"></i> Security Audit</h2>

                <div class="audit-summary">
                    <strong>Purpose:</strong> This section checks for common security vulnerabilities and configuration issues that could expose your site to attacks.
                </div>

                <?php
                // WordPress Core Version Check
                $wp_version_check = wp_audit_check_wp_version();

                // SSL Certificate Check
                $ssl_check = wp_audit_check_ssl_certificate();

                // File Permissions Check
                $file_perms = wp_audit_check_file_permissions();
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
                                <?php if ( $wp_version_check['security_risk'] ): ?>
                                    <span class="audit-status-bad">⚠ Critically Outdated - Security Risk!</span>
                                <?php elseif ( $wp_version_check['outdated'] ): ?>
                                    <span class="audit-status-warning">⚠ Update Available</span>
                                <?php else: ?>
                                    <span class="audit-status-good">✓ Up to Date</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ( isset( $wp_version_check['error'] ) ): ?>
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
                        <?php if ( $ssl_check['enabled'] && !isset( $ssl_check['error'] ) ): ?>
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
                                    <?php if ( $ssl_check['expires_soon'] ): ?>
                                        (⚠ Expires in <?php echo intval( $ssl_check['days_until_expiry'] ); ?> days!)
                                    <?php else: ?>
                                        (<?php echo intval( $ssl_check['days_until_expiry'] ); ?> days remaining)
                                    <?php endif; ?>
                                </span>
                            </td>
                        </tr>
                        <?php elseif ( isset( $ssl_check['error'] ) ): ?>
                        <tr>
                            <td><strong>Note</strong></td>
                            <td><span class="audit-status-warning"><?php echo esc_html( $ssl_check['error'] ); ?><?php echo isset( $ssl_check['message'] ) ? ': ' . esc_html( $ssl_check['message'] ) : ''; ?></span></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <h3 style="font-size: 16px; margin-top: 25px; margin-bottom: 10px; color: var(--ch-color-1, #1d2327);">File Permissions</h3>
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
                        <?php foreach ( $file_perms as $key => $perm ): ?>
                        <tr>
                            <td><code><?php echo esc_html( $perm['path'] ); ?></code></td>
                            <td><code><?php echo esc_html( $perm['permissions'] ); ?></code></td>
                            <td><code><?php echo esc_html( $perm['recommended'] ); ?></code></td>
                            <td><span class="<?php echo $perm['secure'] ? 'audit-status-good' : 'audit-status-bad'; ?>">
                                <?php echo $perm['secure'] ? '✓ Secure' : '✗ Insecure'; ?>
                            </span></td>
                        </tr>
                        <?php endforeach; ?>
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
                // ===== 12. PERFORMANCE & TECHNICAL =====
                ?>
                <h2 id="section-performance"><i data-lucide="gauge"></i> Performance & Technical</h2>
                <?php
                $php_version = phpversion();
                $memory_limit = ini_get( 'memory_limit' );
                $max_upload = wp_convert_hr_to_bytes( wp_max_upload_size() );
                $max_upload_mb = round( $max_upload / 1024 / 1024, 2 );
                $wp_memory_limit = WP_MEMORY_LIMIT;
                $ssl_enabled = is_ssl() ? 'Yes' : 'No';
                $caching_plugin = false;

                foreach ( $active_plugins as $plugin ) {
                    if ( strpos( strtolower( $plugin ), 'cache' ) !== false || strpos( strtolower( $plugin ), 'wp-rocket' ) !== false || strpos( strtolower( $plugin ), 'litespeed' ) !== false ) {
                        $caching_plugin = true;
                        break;
                    }
                }
                ?>
                <table class="wp-audit-table">
                    <tbody>
                        <tr>
                            <td><strong>PHP Version</strong></td>
                            <td><span class="<?php echo version_compare($php_version, '7.4', '>=') ? 'audit-status-good' : 'audit-status-bad'; ?>">
                                <?php echo esc_html( $php_version ); ?> (Recommend 7.4+)
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
                            <td><span class="<?php echo $ssl_enabled === 'Yes' ? 'audit-status-good' : 'audit-status-bad'; ?>">
                                <?php echo esc_html( $ssl_enabled ); ?>
                            </span></td>
                        </tr>
                        <tr>
                            <td><strong>Caching Plugin Active</strong></td>
                            <td><span class="<?php echo $caching_plugin ? 'audit-status-good' : 'audit-status-warning'; ?>">
                                <?php echo $caching_plugin ? 'Yes' : 'No'; ?>
                            </span></td>
                        </tr>
                    </tbody>
                </table>

                <h3 style="font-size: 16px; margin-top: 25px; margin-bottom: 10px; color: var(--ch-color-1, #1d2327);">PageSpeed Insights & Core Web Vitals</h3>
                <div class="audit-summary">
                    <strong>Note:</strong> PageSpeed data is fetched from Google's API. This may take up to 30 seconds on first load. Results are cached for 24 hours.
                    <?php
                    $home_url = get_home_url();
                    $pagespeed_desktop = wp_audit_get_pagespeed_score( $home_url, 'desktop' );
                    $pagespeed_mobile = wp_audit_get_pagespeed_score( $home_url, 'mobile' );
                    ?>
                </div>

                <?php if ( isset( $pagespeed_desktop['error'] ) ): ?>
                <div class="audit-summary" style="background: #fff3cd; border-left-color: #ffb900;">
                    <strong>PageSpeed API Error:</strong> <?php echo esc_html( $pagespeed_desktop['message'] ); ?>
                    <br><small>This is usually due to API rate limits or connectivity issues. Try again later or consider providing a Google PageSpeed API key for higher limits.</small>
                </div>
                <?php else: ?>

                <table class="wp-audit-table">
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th>Desktop</th>
                            <th>Mobile</th>
                            <th>Benchmark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Performance Score</strong></td>
                            <td>
                                <span class="<?php
                                    if ( $pagespeed_desktop['performance'] >= 90 ) echo 'audit-status-good';
                                    elseif ( $pagespeed_desktop['performance'] >= 50 ) echo 'audit-status-warning';
                                    else echo 'audit-status-bad';
                                ?>">
                                    <?php echo intval( $pagespeed_desktop['performance'] ); ?>/100
                                </span>
                            </td>
                            <td>
                                <span class="<?php
                                    if ( $pagespeed_mobile['performance'] >= 90 ) echo 'audit-status-good';
                                    elseif ( $pagespeed_mobile['performance'] >= 50 ) echo 'audit-status-warning';
                                    else echo 'audit-status-bad';
                                ?>">
                                    <?php echo intval( $pagespeed_mobile['performance'] ); ?>/100
                                </span>
                            </td>
                            <td>90+ Good, 50-89 Needs Work, <50 Poor</td>
                        </tr>
                        <tr>
                            <td><strong>Accessibility Score</strong></td>
                            <td>
                                <span class="<?php
                                    if ( $pagespeed_desktop['accessibility'] >= 90 ) echo 'audit-status-good';
                                    elseif ( $pagespeed_desktop['accessibility'] >= 50 ) echo 'audit-status-warning';
                                    else echo 'audit-status-bad';
                                ?>">
                                    <?php echo intval( $pagespeed_desktop['accessibility'] ); ?>/100
                                </span>
                            </td>
                            <td>
                                <span class="<?php
                                    if ( $pagespeed_mobile['accessibility'] >= 90 ) echo 'audit-status-good';
                                    elseif ( $pagespeed_mobile['accessibility'] >= 50 ) echo 'audit-status-warning';
                                    else echo 'audit-status-bad';
                                ?>">
                                    <?php echo intval( $pagespeed_mobile['accessibility'] ); ?>/100
                                </span>
                            </td>
                            <td>90+ Accessible</td>
                        </tr>
                        <tr>
                            <td><strong>SEO Score</strong></td>
                            <td>
                                <span class="<?php
                                    if ( $pagespeed_desktop['seo'] >= 90 ) echo 'audit-status-good';
                                    elseif ( $pagespeed_desktop['seo'] >= 50 ) echo 'audit-status-warning';
                                    else echo 'audit-status-bad';
                                ?>">
                                    <?php echo intval( $pagespeed_desktop['seo'] ); ?>/100
                                </span>
                            </td>
                            <td>
                                <span class="<?php
                                    if ( $pagespeed_mobile['seo'] >= 90 ) echo 'audit-status-good';
                                    elseif ( $pagespeed_mobile['seo'] >= 50 ) echo 'audit-status-warning';
                                    else echo 'audit-status-bad';
                                ?>">
                                    <?php echo intval( $pagespeed_mobile['seo'] ); ?>/100
                                </span>
                            </td>
                            <td>90+ SEO Friendly</td>
                        </tr>
                        <tr>
                            <td><strong>Best Practices</strong></td>
                            <td>
                                <span class="<?php
                                    if ( $pagespeed_desktop['best_practices'] >= 90 ) echo 'audit-status-good';
                                    elseif ( $pagespeed_desktop['best_practices'] >= 50 ) echo 'audit-status-warning';
                                    else echo 'audit-status-bad';
                                ?>">
                                    <?php echo intval( $pagespeed_desktop['best_practices'] ); ?>/100
                                </span>
                            </td>
                            <td>
                                <span class="<?php
                                    if ( $pagespeed_mobile['best_practices'] >= 90 ) echo 'audit-status-good';
                                    elseif ( $pagespeed_mobile['best_practices'] >= 50 ) echo 'audit-status-warning';
                                    else echo 'audit-status-bad';
                                ?>">
                                    <?php echo intval( $pagespeed_mobile['best_practices'] ); ?>/100
                                </span>
                            </td>
                            <td>90+ Follows Best Practices</td>
                        </tr>
                    </tbody>
                </table>

                <h4 style="font-size: 14px; margin-top: 20px; margin-bottom: 10px; color: var(--ch-color-1, #1d2327);">Core Web Vitals</h4>
                <table class="wp-audit-table">
                    <thead>
                        <tr>
                            <th>Vital</th>
                            <th>Desktop</th>
                            <th>Mobile</th>
                            <th>Good Threshold</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>LCP (Largest Contentful Paint)</strong></td>
                            <td>
                                <span class="<?php
                                    if ( $pagespeed_desktop['lcp'] <= 2.5 ) echo 'audit-status-good';
                                    elseif ( $pagespeed_desktop['lcp'] <= 4.0 ) echo 'audit-status-warning';
                                    else echo 'audit-status-bad';
                                ?>">
                                    <?php echo number_format( $pagespeed_desktop['lcp'], 2 ); ?>s
                                </span>
                            </td>
                            <td>
                                <span class="<?php
                                    if ( $pagespeed_mobile['lcp'] <= 2.5 ) echo 'audit-status-good';
                                    elseif ( $pagespeed_mobile['lcp'] <= 4.0 ) echo 'audit-status-warning';
                                    else echo 'audit-status-bad';
                                ?>">
                                    <?php echo number_format( $pagespeed_mobile['lcp'], 2 ); ?>s
                                </span>
                            </td>
                            <td>≤ 2.5s</td>
                        </tr>
                        <tr>
                            <td><strong>FID (First Input Delay)</strong></td>
                            <td>
                                <span class="<?php
                                    if ( $pagespeed_desktop['fid'] <= 100 ) echo 'audit-status-good';
                                    elseif ( $pagespeed_desktop['fid'] <= 300 ) echo 'audit-status-warning';
                                    else echo 'audit-status-bad';
                                ?>">
                                    <?php echo number_format( $pagespeed_desktop['fid'], 0 ); ?>ms
                                </span>
                            </td>
                            <td>
                                <span class="<?php
                                    if ( $pagespeed_mobile['fid'] <= 100 ) echo 'audit-status-good';
                                    elseif ( $pagespeed_mobile['fid'] <= 300 ) echo 'audit-status-warning';
                                    else echo 'audit-status-bad';
                                ?>">
                                    <?php echo number_format( $pagespeed_mobile['fid'], 0 ); ?>ms
                                </span>
                            </td>
                            <td>≤ 100ms</td>
                        </tr>
                        <tr>
                            <td><strong>CLS (Cumulative Layout Shift)</strong></td>
                            <td>
                                <span class="<?php
                                    if ( $pagespeed_desktop['cls'] <= 0.1 ) echo 'audit-status-good';
                                    elseif ( $pagespeed_desktop['cls'] <= 0.25 ) echo 'audit-status-warning';
                                    else echo 'audit-status-bad';
                                ?>">
                                    <?php echo number_format( $pagespeed_desktop['cls'], 3 ); ?>
                                </span>
                            </td>
                            <td>
                                <span class="<?php
                                    if ( $pagespeed_mobile['cls'] <= 0.1 ) echo 'audit-status-good';
                                    elseif ( $pagespeed_mobile['cls'] <= 0.25 ) echo 'audit-status-warning';
                                    else echo 'audit-status-bad';
                                ?>">
                                    <?php echo number_format( $pagespeed_mobile['cls'], 3 ); ?>
                                </span>
                            </td>
                            <td>≤ 0.1</td>
                        </tr>
                        <tr>
                            <td><strong>FCP (First Contentful Paint)</strong></td>
                            <td><?php echo number_format( $pagespeed_desktop['fcp'], 2 ); ?>s</td>
                            <td><?php echo number_format( $pagespeed_mobile['fcp'], 2 ); ?>s</td>
                            <td>≤ 1.8s</td>
                        </tr>
                        <tr>
                            <td><strong>TTFB (Time to First Byte)</strong></td>
                            <td><?php echo number_format( $pagespeed_desktop['ttfb'], 0 ); ?>ms</td>
                            <td><?php echo number_format( $pagespeed_mobile['ttfb'], 0 ); ?>ms</td>
                            <td>≤ 600ms</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Performance Radar Chart -->
                <h4 style="font-size: 14px; margin-top: 30px; margin-bottom: 15px; color: var(--ch-color-1, #1d2327);">Performance Metrics Visualization</h4>
                <div style="background: var(--wp-admin-background-color, #fff); padding: 20px; border-radius: 8px; border: 1px solid var(--wp-admin-border-color, #c3c4c7); max-width: 600px; margin: 0 auto;">
                    <canvas id="performance-radar-chart" style="max-height: 400px;"></canvas>
                </div>

                <?php endif; ?>

                <?php
                // ===== 13. SEO & METADATA =====
                ?>
                <h2 id="section-seo"><i data-lucide="search"></i> SEO & Metadata</h2>
                <?php
                $posts_with_no_title = $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post', 'page') AND post_status = 'publish' AND (post_title = '' OR post_title IS NULL)"
                );

                $seo_plugin = false;
                $seo_plugin_name = '';
                foreach ( $active_plugins as $plugin ) {
                     if ( ! isset($all_plugins[ $plugin ]['Name']) ) continue;
                    $plugin_name_lower = strtolower( $all_plugins[ $plugin ]['Name'] );
                    if ( strpos( $plugin_name_lower, 'yoast' ) !== false || strpos( $plugin_name_lower, 'rank math' ) !== false || strpos( $plugin_name_lower, 'all in one seo' ) !== false ) {
                        $seo_plugin = true;
                        $seo_plugin_name = $all_plugins[ $plugin ]['Name'];
                        break;
                    }
                }

                $robots_txt_exists = file_exists( get_home_path() . 'robots.txt' );
                $search_visible = get_option( 'blog_public' );
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
                            <td><strong>robots.txt Exists</strong></td>
                            <td><span class="<?php echo $robots_txt_exists ? 'audit-status-good' : 'audit-status-warning'; ?>">
                                <?php echo $robots_txt_exists ? 'Yes' : 'No'; ?>
                            </span></td>
                        </tr>
                        <tr>
                            <td><strong>Posts with Missing Titles</strong></td>
                            <td><span class="<?php echo intval( $posts_with_no_title ) > 0 ? 'audit-status-bad' : 'audit-status-good'; ?>">
                                <?php echo intval( $posts_with_no_title ); ?>
                            </span></td>
                        </tr>
                    </tbody>
                </table>
                 <?php
                // ===== 14. RFQ REQUIREMENTS SUMMARY (NEW SECTION) =====
                ?>
                <h2 id="section-rfq"><i data-lucide="clipboard-check"></i> RFQ Requirements Summary</h2>
                <div class="audit-summary">
                    <strong>Purpose:</strong> This section consolidates key information needed for a Request for Quote (RFQ) when planning a website redesign or rebuild. Use this data to communicate requirements to developers and agencies.
                </div>

                <?php
                // Calculate additional RFQ-specific data
                
                // Count WooCommerce products if applicable
                $woo_products = 0;
                $has_woocommerce = false;
                if (class_exists('WooCommerce')) {
                    $has_woocommerce = true;
                    $woo_products = $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"
                    );
                }
                
                // Check for membership/subscription plugins
                $has_memberships = false;
                $membership_plugin = '';
                foreach ($active_plugins as $plugin) {
                    if (!isset($all_plugins[$plugin]['Name'])) continue;
                    $plugin_name = strtolower($all_plugins[$plugin]['Name']);
                    if (strpos($plugin_name, 'membership') !== false || 
                        strpos($plugin_name, 'subscription') !== false ||
                        strpos($plugin_name, 'woocommerce subscriptions') !== false ||
                        strpos($plugin_name, 'memberpress') !== false) {
                        $has_memberships = true;
                        $membership_plugin = $all_plugins[$plugin]['Name'];
                        break;
                    }
                }
                
                // Check for calendar/events plugins
                $has_events = false;
                $events_plugin = '';
                $events_count = 0;
                foreach ($active_plugins as $plugin) {
                    if (!isset($all_plugins[$plugin]['Name'])) continue;
                    $plugin_name = strtolower($all_plugins[$plugin]['Name']);
                    if (strpos($plugin_name, 'event') !== false || 
                        strpos($plugin_name, 'calendar') !== false ||
                        strpos($plugin_name, 'the events calendar') !== false) {
                        $has_events = true;
                        $events_plugin = $all_plugins[$plugin]['Name'];
                        // Try to count events
                        $events_count = $wpdb->get_var(
                            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('tribe_events', 'event', 'mec-events') AND post_status = 'publish'"
                        );
                        break;
                    }
                }
                
                // Check for multilingual
                $has_multilingual = false;
                $multilingual_plugin = '';
                foreach ($active_plugins as $plugin) {
                    if (!isset($all_plugins[$plugin]['Name'])) continue;
                    $plugin_name = strtolower($all_plugins[$plugin]['Name']);
                    if (strpos($plugin_name, 'wpml') !== false || 
                        strpos($plugin_name, 'polylang') !== false ||
                        strpos($plugin_name, 'translatepress') !== false) {
                        $has_multilingual = true;
                        $multilingual_plugin = $all_plugins[$plugin]['Name'];
                        break;
                    }
                }
                
                // Check for advanced search/filter
                $has_search_filter = false;
                $search_plugin = '';
                foreach ($active_plugins as $plugin) {
                    if (!isset($all_plugins[$plugin]['Name'])) continue;
                    $plugin_name = strtolower($all_plugins[$plugin]['Name']);
                    if (strpos($plugin_name, 'facet') !== false || 
                        strpos($plugin_name, 'searchwp') !== false ||
                        strpos($plugin_name, 'relevanssi') !== false ||
                        strpos($plugin_name, 'search & filter') !== false) {
                        $has_search_filter = true;
                        $search_plugin = $all_plugins[$plugin]['Name'];
                        break;
                    }
                }
                
                // Map integration check
                $has_maps = false;
                foreach ($active_plugins as $plugin) {
                    if (!isset($all_plugins[$plugin]['Name'])) continue;
                    $plugin_name = strtolower($all_plugins[$plugin]['Name']);
                    if (strpos($plugin_name, 'map') !== false || 
                        strpos($plugin_name, 'google maps') !== false) {
                        $has_maps = true;
                        break;
                    }
                }
                
                // Count total forms across all form plugins
                $total_forms = 0;
                if (!empty($forms_summary)) {
                    foreach ($forms_summary as $data) {
                        $total_forms += $data['total'];
                    }
                }
                
                // Get blog post count
                $blog_posts = $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'"
                );
                
                // Check hosting type (basic detection)
                $hosting_type = 'Unknown';
                if (defined('WPE_APIKEY')) {
                    $hosting_type = 'WP Engine';
                } elseif (defined('FLYWHEEL_CONFIG_DIR')) {
                    $hosting_type = 'Flywheel';
                } elseif (defined('KINSTA_CACHE_ZONE')) {
                    $hosting_type = 'Kinsta';
                } elseif (defined('IS_PRESSABLE')) {
                    $hosting_type = 'Pressable';
                } elseif (strpos($_SERVER['SERVER_SOFTWARE'] ?? '', 'LiteSpeed') !== false) {
                    $hosting_type = 'LiteSpeed (Possibly Shared)';
                } elseif (getenv('PANTHEON_ENVIRONMENT')) {
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
                                            <?php foreach ($template_usage_counts as $name => $count): ?>
                                                <li><?php echo esc_html($name); ?>: <?php echo intval($count); ?> pages</li>
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
                                <?php if (!empty($top_cpts)): ?>
                                    <ul style="margin: 5px 0; padding-left: 20px;">
                                        <?php foreach ($cpt_query as $cpt): ?>
                                            <li><?php echo esc_html($cpt->post_type); ?>: <strong><?php echo intval($cpt->count); ?></strong> published</li>
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
                                        <?php foreach ($top_cpts as $cpt): ?>
                                            <li><?php echo esc_html(ucwords(str_replace('_', ' ', $cpt->post_type))); ?>: <strong><?php echo intval($cpt->count); ?></strong></li>
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
                                Caching Plugin: <?php echo $caching_plugin ? '<span class="audit-status-good">Active</span>' : '<span class="audit-status-warning">None</span>'; ?>
                                <br>Database Size: <strong><?php echo esc_html($db_size); ?> MB</strong>
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
                                if ($has_maps) $integrations[] = 'Google Maps';
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
                                $has_ga = false;
                                foreach ($active_plugins as $plugin) {
                                    if (!isset($all_plugins[$plugin]['Name'])) continue;
                                    if (stripos($all_plugins[$plugin]['Name'], 'analytics') !== false) {
                                        $has_ga = true;
                                        echo 'Plugin: <strong>' . esc_html($all_plugins[$plugin]['Name']) . '</strong>';
                                        break;
                                    }
                                }
                                if (!$has_ga) {
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
                // ===== 15. CLEANUP & TECHNICAL DEBT (NEW SECTION) =====
                ?>
                <h2 id="section-cleanup"><i data-lucide="trash-2"></i> Cleanup & Technical Debt</h2>
                
                <div class="audit-section-header">Database Bloat Cleanup</div>
                <?php if ( ! empty($orphaned_tables) ) : ?>
                    <p>Found **<?php echo count($orphaned_tables); ?>** tables in your database that do not match WordPress core tables. These are often left behind by uninstalled plugins and should be reviewed for removal.</p>
                    <table class="wp-audit-table">
                        <thead>
                            <tr>
                                <th>Potential Orphaned Table Name</th>
                                <th>Recommendation</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orphaned_tables as $table): ?>
                                <tr>
                                    <td><code><?php echo esc_html($table); ?></code></td>
                                    <td><span class="audit-status-warning">Review for deletion.</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><span class="audit-status-good">No major potential orphaned database tables detected beyond core structure.</span></p>
                <?php endif; ?>

                <div class="audit-section-header">Broken Content (Shortcode Analysis)</div>
                <?php if ( ! empty($top_10_unregistered_shortcodes) ) : ?>
                    <p>Found **<?php echo count($unregistered_shortcodes); ?>** unique shortcode tags in published content that are not registered by any active plugin. These likely display as broken text on the front-end.</p>
                    <table class="wp-audit-table">
                        <thead>
                            <tr>
                                <th>Shortcode Tag</th>
                                <th>Total Posts Affected</th>
                                <th>Example Post</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_10_unregistered_shortcodes as $tag => $data): ?>
                                <tr>
                                    <td><code>[<?php echo esc_html($tag); ?>]</code></td>
                                    <td><span class="audit-status-bad"><?php echo intval($data['count']); ?>+</span></td>
                                    <td><?php echo esc_html($data['example_post']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><span class="audit-status-good">No unregistered shortcodes found in the sample of content posts checked.</span></p>
                <?php endif; ?>
                
<div class="audit-section-header">Inline Styling Technical Debt</div>
                <?php if ($excessive_inline_style_count > 0) : ?>
                    <p>Found <strong><?php echo intval($excessive_inline_style_count); ?></strong> published posts/pages with excessive inline <code>style="..."</code> attributes or embedded <code>&lt;style&gt;</code> blocks. This is a sign of hard-coded styling that should be migrated to CSS files.</p>
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
                            <?php foreach ($posts_with_excessive_styles as $post_data): ?>
                                <tr>
                                    <td><?php echo intval($post_data['id']); ?></td>
                                    <td><strong><?php echo esc_html($post_data['title']); ?></strong></td>
                                    <td><?php echo esc_html(ucfirst($post_data['type'])); ?></td>
                                    <td>
                                        <?php if ($post_data['has_style_tag']): ?>
                                            <span class="audit-status-bad" title="Contains embedded style blocks">🔴 &lt;style&gt; tag</span><br>
                                        <?php endif; ?>
                                        <span class="audit-status-warning"><?php echo intval($post_data['style_count']); ?> inline styles</span>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url($post_data['permalink']); ?>" target="_blank" rel="noopener noreferrer" class="button button-small">
                                            View Page ↗
                                        </a>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url($post_data['edit_link']); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary button-small">
                                            Edit Post ↗
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($excessive_inline_style_count > count($posts_with_excessive_styles)): ?>
                        <p style="font-style: italic; color: #666;">
                            <strong>Note:</strong> Showing all <?php echo count($posts_with_excessive_styles); ?> posts with excessive inline styling. 
                            (Query limited to 100 posts for performance)
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                     <p><span class="audit-status-good">Minimal excessive inline styling or style blocks found in published content.</span></p>
                <?php endif; ?>
                

            </div> <!-- .wp-audit-content -->
        </div> <!-- .wp-audit-wrapper -->
    </div> <!-- .wrap -->

    <?php $wp_audit_scan_nonce = wp_create_nonce( 'wp_audit_scan_sources' ); ?>

 <script>
    const WP_AUDIT = {
        nonce: '<?php echo esc_js( $wp_audit_scan_nonce ); ?>'
    };
    document.addEventListener('DOMContentLoaded', function() {

        // Initialize Lucide icons
        if (typeof lucide !== 'undefined' && lucide.createIcons) {
            lucide.createIcons();
        }

        // --- Chart.js Visualizations ---
        if (typeof Chart !== 'undefined') {
            // Set global Chart.js defaults for WordPress admin theme
            Chart.defaults.color = '#1d2327';
            Chart.defaults.borderColor = '#c3c4c7';
            Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';

            // 1. Plugin Distribution Pie Chart
            const pluginCanvas = document.getElementById('plugin-distribution-chart');
            if (pluginCanvas) {
                new Chart(pluginCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: ['Active Plugins', 'Inactive Plugins'],
                        datasets: [{
                            data: [<?php
                                if ( ! function_exists( 'get_plugins' ) ) {
                                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                                }
                                $all_plugins_chart = get_plugins();
                                $active_plugins_chart = get_option( 'active_plugins' );
                                $active_count = count( $active_plugins_chart );
                                $inactive_count = count( $all_plugins_chart ) - $active_count;
                                echo intval( $active_count ) . ', ' . intval( $inactive_count );
                            ?>],
                            backgroundColor: [
                                'rgba(34, 113, 177, 0.8)',  // WP Blue
                                'rgba(214, 54, 56, 0.8)'     // WP Red
                            ],
                            borderColor: [
                                'rgba(34, 113, 177, 1)',
                                'rgba(214, 54, 56, 1)'
                            ],
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 15,
                                    font: {
                                        size: 12
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.parsed || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = ((value / total) * 100).toFixed(1);
                                        return label + ': ' + value + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // 2. Post Types Distribution Bar Chart
            const postTypesCanvas = document.getElementById('post-types-chart');
            if (postTypesCanvas) {
                new Chart(postTypesCanvas, {
                    type: 'bar',
                    data: {
                        labels: [<?php
                            // Get top 5 post types
                            $cpt_chart_query = $wpdb->get_results(
                                "SELECT post_type, COUNT(*) as count
                                 FROM {$wpdb->posts}
                                 WHERE post_type NOT IN ('attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block')
                                 AND post_status = 'publish'
                                 GROUP BY post_type
                                 ORDER BY count DESC
                                 LIMIT 5",
                                ARRAY_A
                            );
                            $labels = array();
                            $data = array();
                            foreach ( $cpt_chart_query as $row ) {
                                $labels[] = "'" . esc_js( $row['post_type'] ) . "'";
                                $data[] = intval( $row['count'] );
                            }
                            echo implode( ', ', $labels );
                        ?>],
                        datasets: [{
                            label: 'Published Count',
                            data: [<?php echo implode( ', ', $data ); ?>],
                            backgroundColor: 'rgba(34, 113, 177, 0.8)',
                            borderColor: 'rgba(34, 113, 177, 1)',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0
                                }
                            }
                        }
                    }
                });
            }

            // 3. Database Health Doughnut Gauge
            const dbHealthCanvas = document.getElementById('database-health-chart');
            if (dbHealthCanvas) {
                <?php
                // Calculate simple database health score (0-100)
                $health_score = 100;

                // Get orphaned data
                $orphaned_pm = $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                    LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE p.ID IS NULL"
                );
                $orphaned_tr = $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
                    LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID
                    WHERE p.ID IS NULL"
                );

                // Deduct points for issues
                if ( $orphaned_pm > 100 ) $health_score -= 20;
                elseif ( $orphaned_pm > 50 ) $health_score -= 10;
                elseif ( $orphaned_pm > 10 ) $health_score -= 5;

                if ( $orphaned_tr > 100 ) $health_score -= 20;
                elseif ( $orphaned_tr > 50 ) $health_score -= 10;
                elseif ( $orphaned_tr > 10 ) $health_score -= 5;

                // Check for excessive meta
                $excessive_meta = $wpdb->get_var(
                    "SELECT COUNT(DISTINCT post_id)
                     FROM {$wpdb->postmeta}
                     GROUP BY post_id
                     HAVING COUNT(*) > 50"
                );
                if ( $excessive_meta > 10 ) $health_score -= 15;
                elseif ( $excessive_meta > 5 ) $health_score -= 10;

                $health_score = max( 0, min( 100, $health_score ) ); // Clamp between 0-100
                ?>

                new Chart(dbHealthCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: ['Health', 'Issues'],
                        datasets: [{
                            data: [<?php echo intval( $health_score ); ?>, <?php echo 100 - intval( $health_score ); ?>],
                            backgroundColor: [
                                <?php
                                if ( $health_score >= 80 ) {
                                    echo "'rgba(40, 167, 69, 0.8)', 'rgba(200, 200, 200, 0.2)'";
                                } elseif ( $health_score >= 60 ) {
                                    echo "'rgba(255, 185, 0, 0.8)', 'rgba(200, 200, 200, 0.2)'";
                                } else {
                                    echo "'rgba(214, 54, 56, 0.8)', 'rgba(200, 200, 200, 0.2)'";
                                }
                                ?>
                            ],
                            borderColor: [
                                <?php
                                if ( $health_score >= 80 ) {
                                    echo "'rgba(40, 167, 69, 1)', 'rgba(200, 200, 200, 1)'";
                                } elseif ( $health_score >= 60 ) {
                                    echo "'rgba(255, 185, 0, 1)', 'rgba(200, 200, 200, 1)'";
                                } else {
                                    echo "'rgba(214, 54, 56, 1)', 'rgba(200, 200, 200, 1)'";
                                }
                                ?>
                            ],
                            borderWidth: 2,
                            circumference: 180,
                            rotation: 270
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                enabled: false
                            }
                        }
                    },
                    plugins: [{
                        id: 'gaugeText',
                        afterDraw: function(chart) {
                            const ctx = chart.ctx;
                            const centerX = (chart.chartArea.left + chart.chartArea.right) / 2;
                            const centerY = (chart.chartArea.top + chart.chartArea.bottom) / 2 + 20;

                            ctx.save();
                            ctx.font = 'bold 32px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto';
                            ctx.fillStyle = '<?php
                                if ( $health_score >= 80 ) echo "#28a745";
                                elseif ( $health_score >= 60 ) echo "#ffb900";
                                else echo "#d63638";
                            ?>';
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'middle';
                            ctx.fillText('<?php echo intval( $health_score ); ?>', centerX, centerY);

                            ctx.font = '12px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto';
                            ctx.fillStyle = '#666';
                            ctx.fillText('Health Score', centerX, centerY + 25);
                            ctx.restore();
                        }
                    }]
                });
            }

            // 4. Performance Radar Chart (if PageSpeed data available)
            const performanceRadarCanvas = document.getElementById('performance-radar-chart');
            if (performanceRadarCanvas) {
                <?php if ( !isset( $pagespeed_desktop['error'] ) && !isset( $pagespeed_mobile['error'] ) ): ?>
                new Chart(performanceRadarCanvas, {
                    type: 'radar',
                    data: {
                        labels: ['Performance', 'Accessibility', 'SEO', 'Best Practices'],
                        datasets: [
                            {
                                label: 'Desktop',
                                data: [
                                    <?php echo intval( $pagespeed_desktop['performance'] ); ?>,
                                    <?php echo intval( $pagespeed_desktop['accessibility'] ); ?>,
                                    <?php echo intval( $pagespeed_desktop['seo'] ); ?>,
                                    <?php echo intval( $pagespeed_desktop['best_practices'] ); ?>
                                ],
                                backgroundColor: 'rgba(34, 113, 177, 0.2)',
                                borderColor: 'rgba(34, 113, 177, 1)',
                                borderWidth: 2,
                                pointBackgroundColor: 'rgba(34, 113, 177, 1)',
                                pointBorderColor: '#fff',
                                pointHoverBackgroundColor: '#fff',
                                pointHoverBorderColor: 'rgba(34, 113, 177, 1)'
                            },
                            {
                                label: 'Mobile',
                                data: [
                                    <?php echo intval( $pagespeed_mobile['performance'] ); ?>,
                                    <?php echo intval( $pagespeed_mobile['accessibility'] ); ?>,
                                    <?php echo intval( $pagespeed_mobile['seo'] ); ?>,
                                    <?php echo intval( $pagespeed_mobile['best_practices'] ); ?>
                                ],
                                backgroundColor: 'rgba(255, 185, 0, 0.2)',
                                borderColor: 'rgba(255, 185, 0, 1)',
                                borderWidth: 2,
                                pointBackgroundColor: 'rgba(255, 185, 0, 1)',
                                pointBorderColor: '#fff',
                                pointHoverBackgroundColor: '#fff',
                                pointHoverBorderColor: 'rgba(255, 185, 0, 1)'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        scales: {
                            r: {
                                beginAtZero: true,
                                max: 100,
                                ticks: {
                                    stepSize: 20
                                },
                                pointLabels: {
                                    font: {
                                        size: 13
                                    }
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 20,
                                    font: {
                                        size: 13
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.dataset.label + ': ' + context.parsed.r + '/100';
                                    }
                                }
                            }
                        }
                    }
                });
                <?php else: ?>
                // No PageSpeed data available
                const ctx = performanceRadarCanvas.getContext('2d');
                ctx.font = '14px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto';
                ctx.fillStyle = '#666';
                ctx.textAlign = 'center';
                ctx.fillText('PageSpeed data unavailable', performanceRadarCanvas.width / 2, performanceRadarCanvas.height / 2);
                <?php endif; ?>
            }
        }

        // --- Table Sorting Functionality ---
        function initTableSorting() {
            const tables = document.querySelectorAll('.wp-audit-table');

            tables.forEach(table => {
                const tbody = table.querySelector('tbody');
                if (!tbody || tbody.querySelectorAll('tr').length <= 1) {
                    return; // Skip tables with 0 or 1 rows
                }

                const headers = table.querySelectorAll('thead th');
                headers.forEach((header, index) => {
                    // Skip if already initialized
                    if (header.dataset.sortInitialized === 'true') {
                        return;
                    }

                    // Make header sortable
                    header.classList.add('sortable');
                    header.setAttribute('data-column', index);
                    header.dataset.sortInitialized = 'true';

                    header.addEventListener('click', function() {
                        const currentSort = this.classList.contains('sort-asc') ? 'asc' :
                                          this.classList.contains('sort-desc') ? 'desc' : 'none';

                        // Remove sort classes from all headers in this table
                        headers.forEach(h => {
                            h.classList.remove('sort-asc', 'sort-desc');
                        });

                        // Determine new sort direction
                        let newSort = 'asc';
                        if (currentSort === 'asc') {
                            newSort = 'desc';
                            this.classList.add('sort-desc');
                        } else {
                            this.classList.add('sort-asc');
                        }

                        // Sort the table
                        sortTable(table, index, newSort);
                    });
                });
            });
        }

        function sortTable(table, columnIndex, direction) {
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));

            // Determine if column contains numbers or text
            const isNumeric = rows.every(row => {
                const cell = row.cells[columnIndex];
                if (!cell) return false;
                const text = cell.textContent.trim();
                // Check if it's a number (including with commas, MB, etc.)
                const numValue = text.replace(/[,\s]/g, '').replace(/[^\d.-]/g, '');
                return text === '' || text === 'N/A' || text === '—' || text === 'None' || !isNaN(numValue) && numValue !== '';
            });

            rows.sort((rowA, rowB) => {
                const cellA = rowA.cells[columnIndex];
                const cellB = rowB.cells[columnIndex];

                if (!cellA || !cellB) return 0;

                let textA = cellA.textContent.trim();
                let textB = cellB.textContent.trim();

                // Handle empty or N/A values
                if (textA === '' || textA === 'N/A' || textA === '—' || textA === 'None') textA = isNumeric ? '-Infinity' : 'zzz';
                if (textB === '' || textB === 'N/A' || textB === '—' || textB === 'None') textB = isNumeric ? '-Infinity' : 'zzz';

                let comparison = 0;

                if (isNumeric) {
                    // Numeric sorting
                    const numA = parseFloat(textA.replace(/[,\s]/g, '').replace(/[^\d.-]/g, '')) || 0;
                    const numB = parseFloat(textB.replace(/[,\s]/g, '').replace(/[^\d.-]/g, '')) || 0;
                    comparison = numA - numB;
                } else {
                    // Alphabetic sorting (case-insensitive)
                    comparison = textA.toLowerCase().localeCompare(textB.toLowerCase());
                }

                return direction === 'asc' ? comparison : -comparison;
            });

            // Re-append sorted rows
            rows.forEach(row => tbody.appendChild(row));
        }

        // Initialize table sorting
        initTableSorting();

        // --- Sticky Nav Active State ---
        const sections = document.querySelectorAll('.wp-audit-content h2');
        const navLinks = document.querySelectorAll('.wp-audit-nav a');

        const observerOptions = {
            root: null,
            rootMargin: '-40px 0px -60% 0px',
            threshold: 0
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const id = entry.target.id;
                    navLinks.forEach(link => {
                        link.classList.remove('active');
                        if (link.getAttribute('href') === '#' + id) {
                            link.classList.add('active');
                        }
                    });
                }
            });
        }, observerOptions);

        sections.forEach(section => {
            observer.observe(section);
        });

        // UI controls
        const exportBtn = document.getElementById('wp-audit-export-btn');
        const rescanBtn = document.getElementById('wp-audit-rescan-btn');
        const progressInner = document.querySelector('.wp-audit-progress-inner');
        const progressLabel = document.getElementById('wp-audit-progress-label');

        // IMPORTANT: Enable export button immediately - it's independent of scanning
        if ( exportBtn ) {
            exportBtn.disabled = false;
            exportBtn.textContent = 'Download Report as .md';
        }

         // --- Source Scanning Logic ---
        function doScan() {
            const cells = Array.from(document.querySelectorAll('.wp-audit-source-cell'));
            if (cells.length === 0) {
                if ( progressLabel ) progressLabel.innerText = 'No sources to scan';
                if ( progressInner ) progressInner.style.width = '100%';
                return;
            }

            const ptNames = [];
            const txNames = [];
            cells.forEach(c => {
                const t = c.getAttribute('data-type');
                const n = c.getAttribute('data-name');
                if ( t === 'post_type' ) ptNames.push(n);
                if ( t === 'taxonomy' ) txNames.push(n);
            });

            const totalItems = ptNames.length + txNames.length;
            if ( progressLabel ) progressLabel.innerText = `Scanning ${totalItems} sources... (cached for 1hr, may take up to 60s on first run)`;
            if ( progressInner ) progressInner.style.width = '10%';

            // Call AJAX
            const form = new FormData();
            form.append('action', 'wp_audit_scan_sources');
            form.append('nonce', WP_AUDIT.nonce);
            ptNames.forEach(n => form.append('post_types[]', n));
            txNames.forEach(n => form.append('taxonomies[]', n));

            // Animate progress bar during scan
            let fakeProgress = 10;
            const progressInterval = setInterval(() => {
                fakeProgress += 5;
                if (fakeProgress <= 90) {
                    if ( progressInner ) progressInner.style.width = fakeProgress + '%';
                }
            }, 1000);

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: form
            }).then(r => r.json()).then(data => {
                clearInterval(progressInterval);
                
                // Handle both success and timeout error (which includes partial results)
                const results = data.success ? data.data : (data.data && data.data.partial_results ? data.data.partial_results : null);
                
                if ( results ) {
                    const updateCells = (type, name, info) => {
                        const nodes = document.querySelectorAll(`.wp-audit-source-cell[data-name="${CSS.escape(name)}"][data-type="${type}"]`);
                        nodes.forEach(n => {
                            let label = '';
                            let title = 'Detected by file content search.';
                            
                            if ( info.type === 'core' ) {
                                label = info.label;
                            } else if ( info.type === 'plugin' ) {
                                label = ( info.active ? 'Plugin: ' + info.label : 'Plugin: ' + info.label + ' (inactive)' );
                                title += ' Source file: ' + info.location;
                            } else if ( info.type === 'theme' ) {
                                label = 'Theme: ' + info.label;
                                title += ' Source file: ' + info.location;
                            } else {
                                label = info.label;
                                if (info.location) title += ' Location: ' + info.location;
                            }

                            n.innerText = label;
                            n.title = title;
                            n.style.fontStyle = 'normal';
                        });
                    };

                    // Update post type cells
                    if (results.post_types) {
                        Object.keys(results.post_types).forEach(name => {
                            updateCells('post_type', name, results.post_types[name]);
                        });
                    }

                    // Update taxonomy cells
                    if (results.taxonomies) {
                        Object.keys(results.taxonomies).forEach(name => {
                            updateCells('taxonomy', name, results.taxonomies[name]);
                        });
                    }
                    
                    // Mark any cells that weren't scanned (timeout)
                    if (!data.success && data.data && data.data.message) {
                        document.querySelectorAll('.wp-audit-source-cell').forEach(cell => {
                            if (cell.innerText === 'Scanning...') {
                                cell.innerText = 'Scan Timeout';
                                cell.style.color = '#d63638';
                                cell.title = 'Scan timed out before reaching this item. Too many files to scan.';
                            }
                        });
                    }

                    // Finish progress
                    if ( progressInner ) progressInner.style.width = '100%';

                    // Re-initialize table sorting after scan completes
                    setTimeout(() => {
                        initTableSorting();
                    }, 100);

                    if (data.success) {
                        if ( progressLabel ) progressLabel.innerText = 'Scan complete ✓';
                        if ( progressLabel ) progressLabel.style.color = '#2c3338';
                    } else {
                        if ( progressLabel ) {
                            progressLabel.innerHTML = '<span style="color: #d63638;">⚠️ Scan timeout</span> - Partial results shown. <a href="#" id="retry-scan-link" style="color: #0073aa;">Retry scan</a>';
                            
                            // Add retry functionality
                            setTimeout(() => {
                                const retryLink = document.getElementById('retry-scan-link');
                                if (retryLink) {
                                    retryLink.addEventListener('click', (e) => {
                                        e.preventDefault();
                                        const p = document.querySelectorAll('.wp-audit-source-cell');
                                        p.forEach(n => { n.innerText = 'Scanning...'; n.style.fontStyle = 'italic'; n.style.color = ''; n.title = ''; });
                                        if ( progressInner ) progressInner.style.width = '0%';
                                        if ( progressLabel ) {
                                            progressLabel.innerText = 'Ready to scan';
                                            progressLabel.style.color = '#666';
                                        }
                                        doScan();
                                    });
                                }
                            }, 100);
                        }
                    }
                } else {
                    if ( progressLabel ) {
                        progressLabel.innerText = 'Scan failed: ' + (data.data && data.data.message ? data.data.message : 'Unknown error');
                        progressLabel.style.color = '#d63638';
                    }
                    console.error('Scan error:', data);
                }
            }).catch(err => {
                clearInterval(progressInterval);
                if ( progressLabel ) {
                    progressLabel.innerText = 'Scan error - Check console';
                    progressLabel.style.color = '#d63638';
                }
                console.error('Scan error:', err);
            });
        }

        // Wire up rescan button
        if ( rescanBtn ) {
            rescanBtn.addEventListener('click', function() {
                // reset visuals
                const p = document.querySelectorAll('.wp-audit-source-cell');
                p.forEach(n => { n.innerText = 'Scanning...'; n.style.fontStyle = 'italic'; n.title = ''; });
                if ( progressInner ) progressInner.style.width = '0%';
                if ( progressLabel ) progressLabel.innerText = 'Scan not started';
                doScan();
            });
        }

        // Start initial scan
        doScan();

        // --- Markdown Export (INDEPENDENT OF SCANNING) ---
        if ( exportBtn ) exportBtn.addEventListener('click', function() {
            let md = '';
            const content = document.querySelector('.wp-audit-content');

            // Title
            md += '# WordPress Site Audit Report\n\n';
            md += `**Generated:** ${new Date().toLocaleDateString()}\n\n`;
            
            // Overview Section
            const overview = document.getElementById('section-overview');
            if (overview) {
                md += '## Website Overview\n\n';
                overview.querySelectorAll('.wp-audit-overview-item').forEach(item => {
                    const title = item.querySelector('strong').innerText.trim();
                    const valueEl = item.querySelector('span, ul');
                    let value = '';
                    if (valueEl && valueEl.tagName === 'UL') {
                        value = '\n';
                        valueEl.querySelectorAll('li').forEach(li => {
                            value += '  - ' + li.innerText.replace(/:\s+/g, ': ') + '\n';
                        });
                    } else if (valueEl) {
                        value = valueEl.innerText.trim();
                    } else {
                        value = '';
                    }
                    md += `**${title}:** ${value}\n\n`;
                });
                md += '---\n\n';
            }

            // Iterate over all sections
            content.querySelectorAll('h2, .wp-audit-table, p, .audit-summary, .audit-section-header').forEach(el => {
                if (el.tagName === 'H2') {
                    md += `## ${el.innerText.trim()}\n\n`;
                } else if (el.classList.contains('wp-audit-table')) {
                    // Check if it's the nested template table inside the main table
                    if (el.closest('.wp-audit-table') && el.closest('.wp-audit-table').parentElement.tagName === 'TD') {
                        return;
                    }
                    md += tableToMarkdown(el) + '\n\n';
                } else if (el.tagName === 'P' && el.innerText.trim() !== '') {
                    // Skip empty paragraphs
                    const text = el.innerText.trim();
                    if (text.length > 0) {
                        md += text + '\n\n';
                    }
                } else if (el.classList.contains('audit-summary')) {
                    md += `**${el.innerText.trim()}**\n\n`;
                } else if (el.classList.contains('audit-section-header')) {
                    md += `### ${el.innerText.trim()}\n\n`;
                }
            });

            // Create and trigger download using data URL (more secure than blob URL)
            const a = document.createElement('a');
            const siteName = '<?php echo esc_js(sanitize_title(get_bloginfo('name'))); ?>';
            const date = new Date().toISOString().split('T')[0];

            // Use data URL instead of blob URL to avoid "insecure download" warnings
            a.href = 'data:text/markdown;charset=utf-8,' + encodeURIComponent(md);
            a.download = `${siteName}-audit-report-${date}.md`;
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        });

        function tableToMarkdown(table) {
            let md = '';
            const headers = [];
            const rows = [];

            // Get headers
            table.querySelectorAll('thead th').forEach(th => {
                headers.push(th.innerText.trim().replace('?', '').trim());
            });
            
            if (headers.length > 0) {
                md += '| ' + headers.join(' | ') + ' |\n';
                md += '|' + headers.map(() => ' --- ').join('|') + '|\n';
            }

            // Get rows
            table.querySelectorAll('tbody tr').forEach(tr => {
                const row = [];
                tr.querySelectorAll('td').forEach(td => {
                    // Check for nested table (Page Templates)
                    const nestedTable = td.querySelector('.wp-audit-table');
                    if (nestedTable) {
                        row.push(nestedTableToMarkdown(nestedTable));
                    } else {
                        // Check for links and preserve them
                        const links = td.querySelectorAll('a');
                        let cellText = '';
                        if (links.length > 0) {
                            // If there are links, preserve them in markdown format
                            const linkTexts = Array.from(links).map(link => {
                                return `[${link.innerText}](${link.href})`;
                            });
                            cellText = linkTexts.join(' ');
                        } else {
                            // Clean up cell content
                            cellText = td.innerText.trim().replace(/(\r\n|\n|\r)/gm, " ").replace(/\s+/g, ' ');
                        }
                        row.push(cellText);
                    }
                });
                if (row.length > 0) {
                    md += '| ' + row.join(' | ') + ' |\n';
                }
            });
            return md;
        }

        function nestedTableToMarkdown(table) {
            let md = ' (';
            const rows = [];

            // Only get rows, nested tables don't need header formatting
            table.querySelectorAll('tbody tr').forEach(tr => {
                const row = [];
                tr.querySelectorAll('td').forEach(td => {
                    row.push(td.innerText.trim().replace(/(\r\n|\n|\r)/gm, " ").replace(/\s+/g, ' '));
                });
                if (row.length > 0) {
                    rows.push(row.join(': '));
                }
            });
            
            md += rows.join('; ') + ') ';
            return md;
        }

    });
    </script>
    <?php
}
