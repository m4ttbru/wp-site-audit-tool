<?php
/**
 * Report model: turns collected data into sections of facts, tables and lists.
 *
 * Every section is plain data, so the HTML page, the Markdown/JSON exports and
 * WP-CLI all render the exact same content. The Markdown export is written for
 * an LLM making scoping and redesign decisions, so each section carries a short
 * "what this is" summary and findings are phrased as complete sentences.
 *
 * Section shape:
 *   [ 'id', 'title', 'icon' (dashicon), 'summary', 'blocks' => [ block... ], 'findings' => [ finding... ] ]
 * Block shapes:
 *   [ 'type' => 'facts', 'title'?, 'rows' => [ wp_audit_fact()... ] ]
 *   [ 'type' => 'table', 'title'?, 'columns' => [], 'rows' => [ [ cell... ] ], 'empty'?, 'note'?, 'collapsed'? ]
 *   [ 'type' => 'list',  'title'?, 'items' => [ string | [ 'text', 'depth' ] ], 'collapsed'? ]
 *   [ 'type' => 'text',  'text' ]
 *   [ 'type' => 'pagespeed' ]
 *
 * @package WP_Site_Audit_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the full report.
 */
function wp_audit_build_report() {
	$ctx = wp_audit_build_context();

	$builders = array( 'overview', 'content', 'taxonomies', 'pages', 'editors', 'navigation', 'custom_fields', 'forms', 'ecommerce', 'plugins', 'theme', 'users', 'media', 'seo', 'frontend', 'performance', 'security', 'database', 'rfq', 'cleanup' );

	$sections = array();
	foreach ( $builders as $builder ) {
		$section = call_user_func( 'wp_audit_section_' . $builder, $ctx );
		if ( $section ) {
			$sections[] = array_merge( array( 'summary' => '', 'blocks' => array(), 'findings' => array() ), $section );
		}
	}

	/**
	 * Add, remove or reorder report sections. Each section uses the shape documented at the top of sections.php.
	 */
	$sections = apply_filters( 'wp_audit_report_sections', $sections, $ctx );

	$findings = array();
	foreach ( $sections as $section ) {
		foreach ( $section['findings'] as $finding ) {
			$findings[] = array_merge( $finding, array( 'section' => $section['id'], 'section_title' => $section['title'] ) );
		}
	}
	$order = array( 'bad' => 0, 'warning' => 1, 'info' => 2 );
	usort(
		$findings,
		function ( $a, $b ) use ( $order ) {
			return $order[ $a['severity'] ] - $order[ $b['severity'] ];
		}
	);

	return array(
		'meta'     => array(
			'site_name'      => get_bloginfo( 'name' ),
			'site_url'       => home_url(),
			'generated'      => gmdate( 'c' ),
			'generated_local' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'theme'          => $ctx['theme']->get( 'Name' ),
			'plugin_version' => WP_AUDIT_VERSION,
		),
		'snapshot' => wp_audit_build_snapshot( $ctx ),
		'charts'   => wp_audit_build_chart_data( $ctx ),
		'findings' => $findings,
		'sections' => $sections,
	);
}

/**
 * Shared data gathered once and reused by every section builder.
 */
function wp_audit_build_context() {
	$all_plugins    = wp_audit_get_plugins();
	$active_plugins = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active_plugins = array_values( array_unique( array_merge( $active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) ) );
	}
	$active_plugins = array_values( array_filter( $active_plugins, function ( $file ) use ( $all_plugins ) {
		return isset( $all_plugins[ $file ] );
	} ) );

	$post_counts   = wp_audit_get_post_counts();
	$builtin_types = get_post_types( array( '_builtin' => true ) );

	// Published content in non-core post types, largest first. Includes types whose
	// plugin is gone, since that content still has to be migrated or dropped.
	$cpt_counts = array();
	foreach ( $post_counts as $type => $statuses ) {
		if ( ! isset( $builtin_types[ $type ] ) && ! empty( $statuses['publish'] ) ) {
			$cpt_counts[ $type ] = $statuses['publish'];
		}
	}
	arsort( $cpt_counts );

	$categories = array();
	foreach ( $active_plugins as $file ) {
		$categories[ $file ] = wp_audit_plugin_category( $all_plugins[ $file ]['Name'], $all_plugins[ $file ]['Description'] );
	}

	$homepage = wp_audit_probe_homepage();

	return array(
		'theme'             => wp_get_theme(),
		'all_plugins'       => $all_plugins,
		'active_plugins'    => $active_plugins,
		'inactive_plugins'  => array_diff_key( $all_plugins, array_flip( $active_plugins ) ),
		'plugin_updates'    => get_site_transient( 'update_plugins' ),
		'plugin_categories' => $categories,
		'post_counts'       => $post_counts,
		'term_counts'       => wp_audit_get_term_counts(),
		'builtin_types'     => $builtin_types,
		'cpt_counts'        => $cpt_counts,
		'page_count'        => wp_audit_count_status( $post_counts, 'page', 'publish' ),
		'blog_posts'        => wp_audit_count_status( $post_counts, 'post', 'publish' ),
		'total_media'       => wp_audit_count_status( $post_counts, 'attachment', 'inherit' ),
		'scan'              => wp_audit_scan_content(),
		'homepage'          => $homepage,
		'headers'           => empty( $homepage['error'] ) ? $homepage['headers'] : array(),
		'db'                => wp_audit_get_database_stats(),
		'forms'             => wp_audit_get_forms_inventory(),
		'users'             => wp_audit_get_user_counts(),
		'is_production'     => 'production' === wp_get_environment_type(),
	);
}

/**
 * Most-used editor across scanned content, plus every builder seen.
 */
function wp_audit_editor_summary( array $scan ) {
	$totals = array();
	foreach ( $scan['editors'] as $editors ) {
		foreach ( $editors as $editor => $count ) {
			$totals[ $editor ] = ( isset( $totals[ $editor ] ) ? $totals[ $editor ] : 0 ) + $count;
		}
	}
	unset( $totals['Empty'] );
	arsort( $totals );
	return $totals;
}

/**
 * Headline numbers for the cards at the top of the HTML report.
 */
function wp_audit_build_snapshot( array $ctx ) {
	$editors = wp_audit_editor_summary( $ctx['scan'] );
	return array(
		array( 'label' => 'Published Pages', 'value' => wp_audit_num( $ctx['page_count'] ), 'icon' => 'dashicons-admin-page', 'sub' => wp_audit_num( $ctx['blog_posts'] ) . ' blog posts' ),
		array( 'label' => 'Custom Content', 'value' => wp_audit_num( array_sum( $ctx['cpt_counts'] ) ), 'icon' => 'dashicons-admin-post', 'sub' => count( $ctx['cpt_counts'] ) . ' custom post types' ),
		array( 'label' => 'Active Plugins', 'value' => count( $ctx['active_plugins'] ), 'icon' => 'dashicons-admin-plugins', 'sub' => count( $ctx['inactive_plugins'] ) . ' inactive' ),
		array( 'label' => 'Forms', 'value' => count( $ctx['forms'] ), 'icon' => 'dashicons-feedback', 'sub' => $ctx['forms'] ? implode( ', ', array_unique( wp_list_pluck( $ctx['forms'], 'plugin' ) ) ) : 'No form plugin' ),
		array( 'label' => 'Media Files', 'value' => wp_audit_num( $ctx['total_media'] ), 'icon' => 'dashicons-format-image', 'sub' => wp_audit_num( $ctx['users']['total_users'] ) . ' users' ),
		array( 'label' => 'Database', 'value' => wp_audit_num( $ctx['db']['size_mb'] ) . ' MB', 'icon' => 'dashicons-database', 'sub' => $ctx['db']['table_count'] . ' tables' ),
		array( 'label' => 'Theme', 'value' => $ctx['theme']->get( 'Name' ), 'icon' => 'dashicons-admin-appearance', 'sub' => $ctx['theme']->is_block_theme() ? 'Block theme' : 'Classic theme' ),
		array( 'label' => 'Main Editor', 'value' => $editors ? key( $editors ) : '—', 'icon' => 'dashicons-edit-page', 'sub' => count( $editors ) > 1 ? ( count( $editors ) - 1 ) . ' other editor(s) in use' : 'Single editor' ),
	);
}

/**
 * Data for the overview charts (HTML only).
 */
function wp_audit_build_chart_data( array $ctx ) {
	$chart_types = array();
	foreach ( $ctx['post_counts'] as $type => $statuses ) {
		if ( ( ! isset( $ctx['builtin_types'][ $type ] ) || in_array( $type, array( 'post', 'page' ), true ) ) && ! empty( $statuses['publish'] ) ) {
			$chart_types[ $type ] = $statuses['publish'];
		}
	}
	arsort( $chart_types );
	$chart_types = array_slice( $chart_types, 0, 6, true );

	return array(
		'plugins'     => array( count( $ctx['active_plugins'] ), count( $ctx['inactive_plugins'] ) ),
		'postTypes'   => array(
			'labels' => array_keys( $chart_types ),
			'data'   => array_values( $chart_types ),
		),
		'healthScore' => wp_audit_database_health_score( $ctx['db'] ),
	);
}

/* ==========================================================================
   Sections
   ========================================================================== */

/**
 * Site identity, platform and environment.
 */
function wp_audit_section_overview( array $ctx ) {
	$findings = array();
	$version  = wp_audit_check_wp_version();
	$editors  = wp_audit_editor_summary( $ctx['scan'] );

	$version_status = $version['security_risk'] ? 'bad' : ( $version['outdated'] ? 'warning' : 'good' );
	if ( $version['security_risk'] ) {
		$findings[] = wp_audit_finding( 'bad', sprintf( 'WordPress %s is more than two major releases behind the latest (%s).', $version['current'], $version['latest'] ) );
	} elseif ( $version['outdated'] ) {
		$findings[] = wp_audit_finding( 'warning', sprintf( 'WordPress %s has an update available (%s).', $version['current'], $version['latest'] ) );
	}

	// PHP 8.1 reached end of life Dec 2025; 8.3+ is what current WordPress recommends.
	if ( version_compare( PHP_VERSION, '8.3', '>=' ) ) {
		$php_status = 'good';
	} elseif ( version_compare( PHP_VERSION, '8.1', '>=' ) ) {
		$php_status = 'warning';
		$findings[] = wp_audit_finding( 'warning', sprintf( 'PHP %s is behind the recommended 8.3+.', PHP_VERSION ) );
	} else {
		$php_status = 'bad';
		$findings[] = wp_audit_finding( 'bad', sprintf( 'PHP %s is end-of-life and no longer receives security fixes.', PHP_VERSION ) );
	}

	$editor_text = array();
	foreach ( array_slice( $editors, 0, 4, true ) as $editor => $count ) {
		$editor_text[] = $editor . ' (' . wp_audit_num( $count ) . ')';
	}

	$parent = $ctx['theme']->parent();
	$woo    = class_exists( 'WooCommerce' );

	return array(
		'id'       => 'overview',
		'title'    => 'Site Overview',
		'icon'     => 'dashicons-admin-site-alt3',
		'summary'  => 'Identity, platform and environment of the site, plus headline content totals.',
		'findings' => $findings,
		'blocks'   => array(
			array(
				'type'  => 'facts',
				'title' => 'Identity & platform',
				'rows'  => array(
					wp_audit_fact( 'Site title', get_bloginfo( 'name' ) ),
					wp_audit_fact( 'Tagline', get_bloginfo( 'description' ) ? get_bloginfo( 'description' ) : '—' ),
					wp_audit_fact( 'Home URL', home_url() ),
					wp_audit_fact( 'WordPress address', site_url(), '', home_url() !== site_url() ? 'WordPress core lives in a subdirectory' : '' ),
					wp_audit_fact( 'WordPress version', $version['current'], $version_status, $version['outdated'] ? 'Latest: ' . $version['latest'] : '' ),
					wp_audit_fact( 'PHP version', PHP_VERSION, $php_status, 'Recommended: 8.3+' ),
					wp_audit_fact( 'Database server', $ctx['db']['server'] ),
					wp_audit_fact( 'Web server', isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '—' ),
					wp_audit_fact( 'Hosting', wp_audit_detect_hosting( $ctx['headers'] ) ),
					wp_audit_fact( 'Environment type', wp_get_environment_type() ),
					wp_audit_fact( 'Multisite', is_multisite() ? 'Yes (' . get_blog_count() . ' sites)' : 'No' ),
					wp_audit_fact( 'Site language', get_locale() ),
					wp_audit_fact( 'Timezone', wp_timezone_string() ),
					wp_audit_fact( 'Date / time format', get_option( 'date_format' ) . ' / ' . get_option( 'time_format' ) ),
				),
			),
			array(
				'type'  => 'facts',
				'title' => 'Build & content totals',
				'rows'  => array(
					wp_audit_fact( 'Active theme', $ctx['theme']->get( 'Name' ) . ' ' . $ctx['theme']->get( 'Version' ) . ( $parent ? ' (child of ' . $parent->get( 'Name' ) . ')' : '' ) ),
					wp_audit_fact( 'Theme type', $ctx['theme']->is_block_theme() ? 'Block theme (Full Site Editing)' : 'Classic theme' ),
					wp_audit_fact( 'Editors in use', $editor_text ? implode( ', ', $editor_text ) : '—', '', 'Published items per editor, from the content scan' ),
					wp_audit_fact( 'Published pages', wp_audit_num( $ctx['page_count'] ) ),
					wp_audit_fact( 'Published blog posts', wp_audit_num( $ctx['blog_posts'] ) ),
					wp_audit_fact( 'Published custom content', wp_audit_num( array_sum( $ctx['cpt_counts'] ) ) . ' items across ' . count( $ctx['cpt_counts'] ) . ' custom post types' ),
					wp_audit_fact( 'Media files', wp_audit_num( $ctx['total_media'] ) ),
					wp_audit_fact( 'Users', wp_audit_num( $ctx['users']['total_users'] ) ),
					wp_audit_fact( 'Plugins', count( $ctx['active_plugins'] ) . ' active, ' . count( $ctx['inactive_plugins'] ) . ' inactive' ),
					wp_audit_fact( 'Forms', count( $ctx['forms'] ) ),
					wp_audit_fact( 'E-commerce', $woo ? 'WooCommerce ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '' ) : 'None' ),
					wp_audit_fact( 'Database size', wp_audit_num( $ctx['db']['size_mb'] ) . ' MB across ' . $ctx['db']['table_count'] . ' tables' ),
				),
			),
		),
	);
}

/**
 * Post types and content volume.
 */
function wp_audit_section_content( array $ctx ) {
	$findings = array();
	$rows     = array();
	$total    = 0;

	foreach ( get_post_types( array(), 'objects' ) as $type ) {
		$counts = isset( $ctx['post_counts'][ $type->name ] ) ? $ctx['post_counts'][ $type->name ] : array();
		$sum    = array_sum( array_intersect_key( $counts, array_flip( array( 'publish', 'draft', 'future', 'private', 'pending', 'trash' ) ) ) );
		$total += $sum;

		// Hide empty internal types (revisions, changesets…) so the table stays about real content.
		if ( 0 === $sum && ! $type->public && $type->_builtin ) {
			continue;
		}

		$source = wp_audit_get_source( 'post_type', $type->name, $type->_builtin );
		$slug   = ( $type->rewrite && isset( $type->rewrite['slug'] ) ) ? '/' . $type->rewrite['slug'] . '/' : '—';

		$rows[] = array(
			$type->name,
			$type->label,
			wp_audit_num( isset( $counts['publish'] ) ? $counts['publish'] : 0 ),
			wp_audit_num( isset( $counts['draft'] ) ? $counts['draft'] : 0 ),
			wp_audit_num( ( isset( $counts['future'] ) ? $counts['future'] : 0 ) + ( isset( $counts['private'] ) ? $counts['private'] : 0 ) + ( isset( $counts['pending'] ) ? $counts['pending'] : 0 ) ),
			wp_audit_num( isset( $counts['trash'] ) ? $counts['trash'] : 0 ),
			wp_audit_yes_no( $type->public ),
			$type->has_archive ? ( is_string( $type->has_archive ) ? '/' . $type->has_archive . '/' : 'Yes' ) : 'No',
			$slug,
			wp_audit_yes_no( $type->hierarchical ),
			wp_audit_cell( $source['label'], '', '' ) + array( 'title' => $source['location'] ),
		);
	}

	// Content in post types nothing registers anymore (usually a deactivated plugin).
	$orphan_rows = array();
	foreach ( $ctx['post_counts'] as $type => $counts ) {
		if ( ! post_type_exists( $type ) ) {
			$orphan_rows[] = array( $type, wp_audit_num( isset( $counts['publish'] ) ? $counts['publish'] : 0 ), wp_audit_num( array_sum( $counts ) ) );
		}
	}
	if ( $orphan_rows ) {
		$findings[] = wp_audit_finding( 'warning', sprintf( '%d post type(s) hold content but are not registered by any active code (%s) — usually left by a deactivated plugin. Decide whether to migrate or drop this content.', count( $orphan_rows ), implode( ', ', wp_list_pluck( $orphan_rows, 0 ) ) ) );
	}

	// Word counts from the content scan give a sense of migration effort.
	$word_rows = array();
	foreach ( $ctx['scan']['scanned_types'] as $type => $scanned ) {
		$words       = isset( $ctx['scan']['words'][ $type ] ) ? $ctx['scan']['words'][ $type ] : 0;
		$word_rows[] = array( $type, wp_audit_num( $scanned ), wp_audit_num( $words ), wp_audit_num( $scanned ? round( $words / $scanned ) : 0 ) );
	}

	$comments = wp_count_comments();

	return array(
		'id'       => 'content',
		'title'    => 'Content Types',
		'icon'     => 'dashicons-admin-post',
		'summary'  => 'Every registered post type: how much content it holds, how it is exposed publicly (archive, URL slug) and what code registered it.',
		'findings' => $findings,
		'blocks'   => array(
			array(
				'type'    => 'table',
				'columns' => array( 'Post Type', 'Label', 'Published', 'Draft', 'Scheduled/Private/Pending', 'Trash', 'Public', 'Archive', 'URL Slug', 'Hierarchical', 'Registered By' ),
				'rows'    => $rows,
				'note'    => 'Total items across all post types and statuses: ' . wp_audit_num( $total ) . '.',
			),
			array(
				'type'    => 'table',
				'title'   => 'Content in unregistered post types',
				'columns' => array( 'Post Type', 'Published', 'All Statuses' ),
				'rows'    => $orphan_rows,
				'empty'   => 'None — every post type with content is registered.',
			),
			array(
				'type'    => 'table',
				'title'   => 'Content volume (word counts)',
				'columns' => array( 'Post Type', 'Published Items Scanned', 'Total Words', 'Average Words' ),
				'rows'    => $word_rows,
				'note'    => sprintf( 'From the content scan of %s published items (pages first, then most recently modified; limit %s).', wp_audit_num( $ctx['scan']['scanned'] ), wp_audit_num( $ctx['scan']['limit'] ) ),
			),
			array(
				'type'  => 'facts',
				'title' => 'Comments',
				'rows'  => array(
					wp_audit_fact( 'Approved comments', wp_audit_num( $comments->approved ) ),
					wp_audit_fact( 'Pending moderation', wp_audit_num( $comments->moderated ), $comments->moderated > 50 ? 'warning' : '' ),
					wp_audit_fact( 'Spam', wp_audit_num( $comments->spam ) ),
					wp_audit_fact( 'Comments on new posts', 'open' === get_option( 'default_comment_status' ) ? 'Open by default' : 'Closed by default' ),
				),
			),
		),
	);
}

/**
 * Taxonomies.
 */
function wp_audit_section_taxonomies( array $ctx ) {
	$rows = array();
	foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
		$terms = isset( $ctx['term_counts'][ $taxonomy->name ] ) ? $ctx['term_counts'][ $taxonomy->name ] : 0;
		if ( 0 === $terms && ! $taxonomy->public && $taxonomy->_builtin ) {
			continue;
		}
		$source = wp_audit_get_source( 'taxonomy', $taxonomy->name, $taxonomy->_builtin );
		$rows[] = array(
			$taxonomy->name,
			$taxonomy->label,
			wp_audit_cell( wp_audit_num( $terms ), $terms ? '' : 'warning' ),
			wp_audit_yes_no( $taxonomy->hierarchical ),
			wp_audit_yes_no( $taxonomy->public ),
			implode( ', ', (array) $taxonomy->object_type ),
			( $taxonomy->rewrite && isset( $taxonomy->rewrite['slug'] ) ) ? '/' . $taxonomy->rewrite['slug'] . '/' : '—',
			wp_audit_cell( $source['label'] ) + array( 'title' => $source['location'] ),
		);
	}

	return array(
		'id'      => 'taxonomies',
		'title'   => 'Taxonomies',
		'icon'    => 'dashicons-tag',
		'summary' => 'How content is classified: every registered taxonomy, its terms, the post types it applies to and its URL base.',
		'blocks'  => array(
			array(
				'type'    => 'table',
				'columns' => array( 'Taxonomy', 'Label', 'Terms', 'Hierarchical', 'Public', 'Attached To', 'URL Slug', 'Registered By' ),
				'rows'    => $rows,
			),
		),
	);
}

/**
 * Pages, templates and the full page inventory.
 */
function wp_audit_section_pages( array $ctx ) {
	$findings  = array();
	$inventory = wp_audit_get_page_inventory();
	$templates = $ctx['theme']->get_page_templates();
	$usage     = wp_audit_get_template_usage();

	$template_rows = array();
	foreach ( $templates as $file => $name ) {
		$count           = isset( $usage[ $file ] ) ? $usage[ $file ] : 0;
		$template_rows[] = array( $name, $file, $count ? wp_audit_num( $count ) : wp_audit_cell( '0 (unused)', 'warning' ) );
	}
	$missing = array();
	foreach ( $usage as $file => $count ) {
		if ( 'default' !== $file && '' !== $file && ! isset( $templates[ $file ] ) ) {
			$missing[]       = $file;
			$template_rows[] = array( '(missing from theme)', $file, wp_audit_cell( wp_audit_num( $count ) . ' — fall back to default template', 'bad' ) );
		}
	}
	if ( isset( $usage['default'] ) ) {
		$template_rows[] = array( 'Default template', 'default', wp_audit_num( $usage['default'] ) );
	}
	if ( $missing ) {
		$findings[] = wp_audit_finding( 'bad', sprintf( 'Published pages are assigned page templates the theme no longer has (%s); they silently render with the default template.', implode( ', ', $missing ) ) );
	}

	$front_id = (int) get_option( 'page_on_front' );
	$posts_id = (int) get_option( 'page_for_posts' );
	$scan     = $ctx['scan']['pages'];

	$page_rows = array();
	foreach ( $inventory['pages'] as $page ) {
		$flags = array();
		if ( $page['id'] === $front_id ) {
			$flags[] = 'Front page';
		}
		if ( $page['id'] === $posts_id ) {
			$flags[] = 'Posts page';
		}
		$title       = str_repeat( '— ', $page['depth'] ) . $page['title'] . ( $flags ? ' [' . implode( ', ', $flags ) . ']' : '' );
		$page_rows[] = array(
			wp_audit_cell( $title, '', get_edit_post_link( $page['id'], 'raw' ) ),
			$page['path'],
			'publish' === $page['status'] ? 'Published' : ucfirst( $page['status'] ),
			isset( $templates[ $page['template'] ] ) ? $templates[ $page['template'] ] : $page['template'],
			isset( $scan[ $page['id'] ] ) ? $scan[ $page['id'] ]['editor'] : '—',
			isset( $scan[ $page['id'] ] ) ? wp_audit_num( $scan[ $page['id'] ]['words'] ) : '—',
			$page['modified'],
		);
	}

	$show_on_front = get_option( 'show_on_front' );

	return array(
		'id'       => 'pages',
		'title'    => 'Pages & Hierarchy',
		'icon'     => 'dashicons-admin-page',
		'summary'  => 'The page tree is the site\'s information architecture. The inventory lists every page with its URL path, template, editor and word count.',
		'findings' => $findings,
		'blocks'   => array(
			array(
				'type' => 'facts',
				'rows' => array(
					wp_audit_fact( 'Published pages', wp_audit_num( $ctx['page_count'] ) ),
					wp_audit_fact( 'Draft pages', wp_audit_num( wp_audit_count_status( $ctx['post_counts'], 'page', 'draft' ) ) ),
					wp_audit_fact( 'Private pages', wp_audit_num( wp_audit_count_status( $ctx['post_counts'], 'page', 'private' ) ) ),
					wp_audit_fact( 'Maximum nesting depth', $inventory['max_depth'] . ' (0 = top level only)' ),
					wp_audit_fact( 'Homepage displays', 'page' === $show_on_front && $front_id ? 'Static page: ' . get_the_title( $front_id ) : 'Latest posts' ),
					wp_audit_fact( 'Posts page', $posts_id ? get_the_title( $posts_id ) : '—' ),
					wp_audit_fact( 'Page templates in theme', count( $templates ) ),
				),
			),
			array(
				'type'    => 'table',
				'title'   => 'Page template usage (published pages)',
				'columns' => array( 'Template', 'File', 'Pages' ),
				'rows'    => $template_rows,
				'empty'   => 'The theme defines no custom page templates.',
			),
			array(
				'type'      => 'table',
				'title'     => 'Page inventory',
				'columns'   => array( 'Title', 'Path', 'Status', 'Template', 'Editor', 'Words', 'Modified' ),
				'rows'      => $page_rows,
				'collapsed' => count( $page_rows ) > 25,
				'note'      => $inventory['total'] > $inventory['limit'] ? sprintf( 'Showing the first %s of %s pages. Raise the limit with the wp_audit_page_inventory_limit filter.', wp_audit_num( $inventory['limit'] ), wp_audit_num( $inventory['total'] ) ) : sprintf( 'All %s pages (published, private, draft, pending, scheduled), in hierarchy order. "—" in the title marks nesting depth.', wp_audit_num( $inventory['total'] ) ),
			),
		),
	);
}

/**
 * Editors, page builders, blocks, shortcodes and embeds.
 */
function wp_audit_section_editors( array $ctx ) {
	$scan     = $ctx['scan'];
	$findings = array();
	$totals   = wp_audit_editor_summary( $scan );

	// "Empty" goes last: items with no body content are usually built entirely from custom fields.
	$editor_names = array_keys( $totals );
	$columns      = $editor_names;
	$has_empty    = false;
	foreach ( $scan['editors'] as $editors ) {
		$has_empty = $has_empty || isset( $editors['Empty'] );
	}
	if ( $has_empty ) {
		$columns[] = 'Empty';
	}

	$editor_rows = array();
	foreach ( $scan['editors'] as $type => $editors ) {
		$row = array( $type );
		foreach ( $columns as $editor ) {
			$row[] = isset( $editors[ $editor ] ) ? wp_audit_num( $editors[ $editor ] ) : '0';
		}
		$editor_rows[] = $row;
	}
	$column_labels = array_map(
		function ( $editor ) {
			return 'Empty' === $editor ? 'No body content (fields only)' : $editor;
		},
		$columns
	);

	$builders = array_diff( $editor_names, array( 'Block editor', 'Classic editor' ) );
	if ( $builders ) {
		foreach ( $builders as $builder ) {
			$findings[] = wp_audit_finding( 'info', sprintf( '%s published items are built with %s. A redesign must either keep %s or rebuild these layouts.', wp_audit_num( $totals[ $builder ] ), $builder, $builder ) );
		}
	}
	if ( count( $editor_names ) > 1 ) {
		$findings[] = wp_audit_finding( 'info', 'Content is split across multiple editors (' . implode( ', ', $editor_names ) . '), so content migration will need more than one approach.' );
	}

	$block_rows = array();
	foreach ( array_slice( $scan['blocks'], 0, 60, true ) as $name => $data ) {
		$block_rows[] = array( $name, wp_audit_num( $data['posts'] ), wp_audit_num( $data['occurrences'] ) );
	}

	$shortcode_rows = array();
	foreach ( $scan['shortcodes'] as $tag => $data ) {
		if ( $data['registered'] ) {
			$registered = 'Yes';
		} elseif ( $data['shortcode_like'] ) {
			$registered = wp_audit_cell( 'No — renders as raw text', 'bad' );
		} else {
			$registered = 'No — probably plain bracketed text';
		}
		$shortcode_rows[] = array(
			'[' . $tag . ']',
			$registered,
			wp_audit_num( $data['posts'] ),
			wp_audit_num( $data['occurrences'] ),
			$data['example_title'] . ' (ID ' . $data['example_id'] . ')',
		);
	}

	$embed_rows = array();
	foreach ( $scan['embeds'] as $host => $count ) {
		$embed_rows[] = array( $host, wp_audit_num( $count ) );
	}

	$builder_plugins = array();
	foreach ( $ctx['plugin_categories'] as $file => $category ) {
		if ( 'Page builder' === $category ) {
			$builder_plugins[] = $ctx['all_plugins'][ $file ]['Name'];
		}
	}

	return array(
		'id'       => 'editors',
		'title'    => 'Editors, Builders & Blocks',
		'icon'     => 'dashicons-edit-page',
		'summary'  => 'How content is authored: which editor or page builder built each item, which blocks and shortcodes the content depends on, and what third-party media is embedded.',
		'findings' => $findings,
		'blocks'   => array(
			array(
				'type' => 'facts',
				'rows' => array(
					wp_audit_fact( 'Page builder plugins active', $builder_plugins ? implode( ', ', $builder_plugins ) : 'None' ),
					wp_audit_fact( 'Content scanned', sprintf( '%s published items (limit %s, pages first)', wp_audit_num( $scan['scanned'] ), wp_audit_num( $scan['limit'] ) ) ),
					wp_audit_fact( 'Distinct blocks used', wp_audit_num( count( $scan['blocks'] ) ) ),
					wp_audit_fact( 'Distinct shortcodes used', wp_audit_num( count( $scan['shortcodes'] ) ) ),
				),
			),
			array(
				'type'    => 'table',
				'title'   => 'Editor used, by post type (published items)',
				'columns' => array_merge( array( 'Post Type' ), $column_labels ),
				'rows'    => $editor_rows,
			),
			array(
				'type'      => 'table',
				'title'     => 'Blocks used',
				'columns'   => array( 'Block', 'Items Using It', 'Occurrences' ),
				'rows'      => $block_rows,
				'empty'     => 'No block content found.',
				'collapsed' => count( $block_rows ) > 20,
				'note'      => count( $scan['blocks'] ) > 60 ? 'Top 60 of ' . count( $scan['blocks'] ) . ' blocks.' : '',
			),
			array(
				'type'      => 'table',
				'title'     => 'Shortcodes used',
				'columns'   => array( 'Shortcode', 'Registered', 'Items Using It', 'Occurrences', 'Example' ),
				'rows'      => $shortcode_rows,
				'empty'     => 'No shortcodes found in published content.',
				'collapsed' => count( $shortcode_rows ) > 20,
			),
			array(
				'type'    => 'table',
				'title'   => 'Embedded third-party content',
				'columns' => array( 'Source', 'Embeds' ),
				'rows'    => $embed_rows,
				'empty'   => 'No iframes or embed blocks found.',
			),
		),
	);
}

/**
 * Menus, widget areas and block-theme template parts.
 */
function wp_audit_section_navigation( array $ctx ) {
	$menus   = wp_audit_get_menus();
	$widgets = wp_audit_get_widget_areas();
	$blocks  = array();

	$location_rows = array();
	foreach ( $menus['locations'] as $slug => $label ) {
		$menu_id         = isset( $menus['assigned'][ $slug ] ) ? (int) $menus['assigned'][ $slug ] : 0;
		$menu            = $menu_id ? wp_get_nav_menu_object( $menu_id ) : null;
		$location_rows[] = array( $label, $slug, $menu ? $menu->name : wp_audit_cell( 'Not assigned', 'warning' ) );
	}

	$blocks[] = array(
		'type' => 'facts',
		'rows' => array(
			wp_audit_fact( 'Menu locations registered by theme', count( $menus['locations'] ) ),
			wp_audit_fact( 'Classic menus', count( $menus['menus'] ) ),
			wp_audit_fact( 'Block navigation menus (wp_navigation)', wp_audit_num( wp_audit_count_status( $ctx['post_counts'], 'wp_navigation', 'publish' ) ) ),
			wp_audit_fact( 'Widget areas', count( $widgets['areas'] ) ),
		),
	);
	$blocks[] = array(
		'type'    => 'table',
		'title'   => 'Menu locations',
		'columns' => array( 'Location', 'Slug', 'Assigned Menu' ),
		'rows'    => $location_rows,
		'empty'   => 'The theme registers no classic menu locations.',
	);

	foreach ( $menus['menus'] as $menu ) {
		$blocks[] = array(
			'type'      => 'list',
			'title'     => sprintf( 'Menu: %s (%d items%s)', $menu['name'], $menu['count'], $menu['locations'] ? '; ' . implode( ', ', $menu['locations'] ) : '; not assigned' ),
			'items'     => $menu['items'],
			'empty'     => 'This menu has no items.',
			'collapsed' => $menu['count'] > 15,
		);
	}

	$widget_rows = array();
	foreach ( $widgets['areas'] as $area ) {
		$types = array();
		foreach ( $area['types'] as $type => $count ) {
			$types[] = $type . ( $count > 1 ? ' ×' . $count : '' );
		}
		$widget_rows[] = array( $area['name'], $area['id'], $area['count'], $types ? implode( ', ', $types ) : '—' );
	}
	$blocks[] = array(
		'type'    => 'table',
		'title'   => 'Widget areas',
		'columns' => array( 'Area', 'ID', 'Widgets', 'Widget Types' ),
		'rows'    => $widget_rows,
		'empty'   => 'No widget areas registered.',
		'note'    => $widgets['inactive'] ? $widgets['inactive'] . ' widget(s) sit in Inactive Widgets.' : '',
	);

	return array(
		'id'      => 'navigation',
		'title'   => 'Navigation & Layout Areas',
		'icon'    => 'dashicons-menu',
		'summary' => 'Menus (with every item), where they appear, and widget areas. Together with the page tree, this describes the site\'s navigation structure.',
		'blocks'  => $blocks,
	);
}

/**
 * ACF field groups, other field frameworks and postmeta keys.
 */
function wp_audit_section_custom_fields( array $ctx ) {
	$acf      = wp_audit_get_acf_details();
	$blocks   = array();
	$findings = array();

	$frameworks = wp_audit_all_detected(
		array(
			'Advanced Custom Fields' . ( $acf && $acf['pro'] ? ' PRO' : '' ) . ( $acf ? ' ' . $acf['version'] : '' ) => (bool) $acf,
			'Meta Box'          => defined( 'RWMB_VER' ),
			'Pods'              => defined( 'PODS_VERSION' ),
			'Toolset Types'     => defined( 'TYPES_VERSION' ),
			'CMB2'              => defined( 'CMB2_LOADED' ),
			'Carbon Fields'     => class_exists( 'Carbon_Fields\Carbon_Fields' ),
			'Custom Post Type UI' => defined( 'CPTUI_VERSION' ),
		)
	);

	$facts = array( wp_audit_fact( 'Custom field / content-type frameworks', $frameworks ? implode( ', ', $frameworks ) : 'None detected' ) );

	if ( $acf ) {
		$total_fields = array_sum( wp_list_pluck( $acf['groups'], 'fields' ) );
		$facts[]      = wp_audit_fact( 'ACF field groups', count( $acf['groups'] ) );
		$facts[]      = wp_audit_fact( 'ACF fields (including sub fields)', wp_audit_num( $total_fields ) );
		$facts[]      = wp_audit_fact( 'ACF options pages', $acf['options_pages'] ? implode( ', ', $acf['options_pages'] ) : 'None' );

		if ( $total_fields > 0 ) {
			$findings[] = wp_audit_finding( 'info', sprintf( 'The data model relies on ACF: %d field groups with %s fields. These must be recreated or mapped in any rebuild.', count( $acf['groups'] ), wp_audit_num( $total_fields ) ) );
		}
		$db_groups = array_filter(
			$acf['groups'],
			function ( $group ) {
				return 'Database' === $group['storage'];
			}
		);
		if ( $db_groups && $acf['groups'] && count( $db_groups ) === count( $acf['groups'] ) ) {
			$findings[] = wp_audit_finding( 'warning', 'ACF field groups live only in the database (no Local JSON), so they are not version-controlled with the theme.' );
		}
	}

	$blocks[] = array(
		'type' => 'facts',
		'rows' => $facts,
	);

	if ( $acf ) {
		$group_rows = array();
		foreach ( $acf['groups'] as $group ) {
			$group_rows[] = array( $group['title'], wp_audit_num( $group['fields'] ), $group['types'], $group['location'], $group['storage'], $group['active'] ? 'Yes' : wp_audit_cell( 'No', 'warning' ) );
		}
		$blocks[] = array(
			'type'    => 'table',
			'title'   => 'ACF field groups',
			'columns' => array( 'Field Group', 'Fields', 'Field Types', 'Shown On (location rules)', 'Storage', 'Active' ),
			'rows'    => $group_rows,
			'empty'   => 'No ACF field groups.',
		);
		foreach ( $acf['groups'] as $group ) {
			$blocks[] = array(
				'type'      => 'list',
				'title'     => 'Fields in "' . $group['title'] . '" — label (name, type)',
				'items'     => $group['lines'],
				'empty'     => 'No fields.',
				'collapsed' => true,
			);
		}
	}

	$meta_rows = array();
	foreach ( wp_audit_get_postmeta_keys() as $meta ) {
		$meta_rows[] = array( $meta['meta_key'], wp_audit_num( $meta['usage_count'] ), wp_audit_num( $meta['total_entries'] ) );
	}
	$blocks[] = array(
		'type'      => 'table',
		'title'     => 'Most-used public post meta keys',
		'columns'   => array( 'Meta Key', 'Posts Using It', 'Total Entries' ),
		'rows'      => $meta_rows,
		'empty'     => 'No public post meta found.',
		'collapsed' => true,
		'note'      => 'Top 50 keys not starting with an underscore. Underscore-prefixed keys are internal (often ACF field references).',
	);

	return array(
		'id'       => 'custom-fields',
		'title'    => 'Custom Fields & Data Model',
		'icon'     => 'dashicons-database-view',
		'summary'  => 'Structured data attached to content. Field groups and their fields describe the data model a rebuild has to preserve.',
		'findings' => $findings,
		'blocks'   => $blocks,
	);
}

/**
 * Forms inventory.
 */
function wp_audit_section_forms( array $ctx ) {
	$forms    = $ctx['forms'];
	$findings = array();
	$rows     = array();
	$summary  = array();

	foreach ( $forms as $form ) {
		$plugin = $form['plugin'];
		if ( ! isset( $summary[ $plugin ] ) ) {
			$summary[ $plugin ] = array( 0, 0, 0 );
		}
		$summary[ $plugin ][ $form['active'] ? 0 : 1 ]++;
		$summary[ $plugin ][2] += (int) $form['entries'];

		$rows[] = array(
			$plugin,
			$form['title'] . ' (ID ' . $form['id'] . ')',
			$form['active'] ? 'Active' : wp_audit_cell( 'Inactive/Draft', 'warning' ),
			wp_audit_num( $form['fields'] ),
			null === $form['entries'] ? 'Not stored' : wp_audit_num( $form['entries'] ),
			null === $form['notifications'] ? '—' : $form['notifications'],
			$form['features'] ? implode( ', ', $form['features'] ) : '—',
			$form['integrations'] ? implode( ', ', $form['integrations'] ) : '—',
		);
	}

	$summary_rows = array();
	foreach ( $summary as $plugin => $counts ) {
		$summary_rows[] = array( $plugin, $counts[0] + $counts[1], $counts[0], $counts[1], wp_audit_num( $counts[2] ) );
	}

	if ( $forms ) {
		$complex = array_filter(
			$forms,
			function ( $form ) {
				return $form['active'] && ( $form['features'] || $form['integrations'] );
			}
		);
		if ( $complex ) {
			$findings[] = wp_audit_finding( 'info', sprintf( '%d active form(s) use conditional logic, multi-page steps, file uploads, payments or third-party integrations; these need individual review when scoping.', count( $complex ) ) );
		}
	}

	return array(
		'id'       => 'forms',
		'title'    => 'Forms',
		'icon'     => 'dashicons-feedback',
		'summary'  => 'Every form with its field count, stored entries, notable features (conditional logic, multi-page, uploads, payments) and integrations.',
		'findings' => $findings,
		'blocks'   => array(
			array(
				'type'    => 'table',
				'title'   => 'By plugin',
				'columns' => array( 'Form Plugin', 'Forms', 'Active', 'Inactive', 'Stored Entries' ),
				'rows'    => $summary_rows,
				'empty'   => 'No supported form plugin is active (Gravity Forms, Contact Form 7, WPForms, Formidable).',
			),
			array(
				'type'      => 'table',
				'title'     => 'Form inventory',
				'columns'   => array( 'Plugin', 'Form', 'Status', 'Fields', 'Entries', 'Notifications', 'Features', 'Integrations' ),
				'rows'      => $rows,
				'empty'     => 'No forms found.',
				'collapsed' => count( $rows ) > 25,
			),
		),
	);
}

/**
 * WooCommerce (only when active).
 */
function wp_audit_section_ecommerce( array $ctx ) {
	$woo = wp_audit_get_woocommerce_details( $ctx['post_counts'], $ctx['term_counts'] );
	if ( ! $woo ) {
		return null;
	}

	$types = array();
	foreach ( $woo['product_types'] as $type => $count ) {
		$types[] = $type . ': ' . wp_audit_num( $count );
	}
	$order_rows = array();
	foreach ( $woo['orders'] as $status => $count ) {
		$order_rows[] = array( $status, wp_audit_num( $count ) );
	}

	return array(
		'id'       => 'ecommerce',
		'title'    => 'E-commerce (WooCommerce)',
		'icon'     => 'dashicons-cart',
		'summary'  => 'Store catalogue size, order history, payments and shipping — the main drivers of e-commerce migration effort.',
		'findings' => array( wp_audit_finding( 'info', sprintf( 'WooCommerce store with %s published products and %s variations.', wp_audit_num( $woo['products'] ), wp_audit_num( $woo['variations'] ) ) ) ),
		'blocks'   => array(
			array(
				'type' => 'facts',
				'rows' => array(
					wp_audit_fact( 'WooCommerce version', $woo['version'] ),
					wp_audit_fact( 'Published products', wp_audit_num( $woo['products'] ) . ( $woo['drafts'] ? ' (+' . wp_audit_num( $woo['drafts'] ) . ' drafts)' : '' ) ),
					wp_audit_fact( 'Product types', $types ? implode( ', ', $types ) : '—' ),
					wp_audit_fact( 'Variations', wp_audit_num( $woo['variations'] ) ),
					wp_audit_fact( 'Product categories / tags / attributes', $woo['categories'] . ' / ' . $woo['tags'] . ' / ' . $woo['attributes'] ),
					wp_audit_fact( 'Active coupons', wp_audit_num( $woo['coupons'] ) ),
					wp_audit_fact( 'Currency', $woo['currency'] ),
					wp_audit_fact( 'Taxes enabled', wp_audit_yes_no( $woo['tax_enabled'] ) ),
					wp_audit_fact( 'Guest checkout', wp_audit_yes_no( $woo['guest_checkout'] ) ),
					wp_audit_fact( 'Payment gateways enabled', $woo['gateways'] ? implode( '; ', $woo['gateways'] ) : 'None', $woo['gateways'] ? '' : 'warning' ),
					wp_audit_fact( 'Shipping zones', $woo['zones'] ? implode( '; ', $woo['zones'] ) : 'None' ),
					wp_audit_fact( 'High-performance order storage (HPOS)', wp_audit_yes_no( $woo['hpos'] ) ),
					wp_audit_fact( 'Subscriptions', $woo['subscriptions'] ? 'WooCommerce Subscriptions active' : 'No' ),
				),
			),
			array(
				'type'    => 'table',
				'title'   => 'Orders by status',
				'columns' => array( 'Status', 'Orders' ),
				'rows'    => $order_rows,
				'empty'   => 'No orders.',
			),
		),
	);
}

/**
 * Plugins, functionality categories, MU plugins and drop-ins.
 */
function wp_audit_section_plugins( array $ctx ) {
	$findings     = array();
	$updates      = $ctx['plugin_updates'];
	$auto_updates = (array) get_site_option( 'auto_update_plugins', array() );

	$by_category = array();
	$active_rows = array();
	$needs_update = 0;
	foreach ( $ctx['active_plugins'] as $file ) {
		$data     = $ctx['all_plugins'][ $file ];
		$category = $ctx['plugin_categories'][ $file ];
		list( $status, $label ) = wp_audit_plugin_update_status( $file, $updates );
		if ( 'warning' === $status ) {
			$needs_update++;
		}
		$by_category[ $category ][] = $data['Name'];
		$active_rows[]              = array(
			$data['Name'],
			$data['Version'],
			$category,
			wp_audit_cell( $label, $status, 'warning' === $status ? self_admin_url( 'plugins.php?plugin_status=upgrade' ) : '' ),
			in_array( $file, $auto_updates, true ) ? 'On' : 'Off',
			wp_audit_excerpt( $data['Description'], 200 ),
		);
	}
	ksort( $by_category );

	$category_rows = array();
	foreach ( $by_category as $category => $names ) {
		$category_rows[] = array( $category, count( $names ), implode( ', ', $names ) );
	}

	$inactive_rows = array();
	foreach ( $ctx['inactive_plugins'] as $file => $data ) {
		list( $status, $label ) = wp_audit_plugin_update_status( $file, $updates );
		$inactive_rows[]        = array( $data['Name'], $data['Version'], wp_audit_plugin_category( $data['Name'], $data['Description'] ), wp_audit_cell( $label, $status ), wp_audit_excerpt( $data['Description'], 200 ) );
	}

	$mu_rows = array();
	foreach ( get_mu_plugins() as $file => $data ) {
		$mu_rows[] = array( $data['Name'] ? $data['Name'] : $file, $file, $data['Version'], wp_audit_excerpt( $data['Description'], 200 ) );
	}
	$dropin_rows = array();
	foreach ( get_dropins() as $file => $data ) {
		$dropin_rows[] = array( $file, $data['Name'] ? $data['Name'] : '—' );
	}

	if ( $needs_update ) {
		$findings[] = wp_audit_finding( 'warning', sprintf( '%d active plugin(s) have updates available.', $needs_update ) );
	}
	if ( count( $ctx['inactive_plugins'] ) ) {
		$findings[] = wp_audit_finding( 'warning', sprintf( '%d inactive plugin(s) are still installed. Unused plugins are attack surface; delete them if they are not needed.', count( $ctx['inactive_plugins'] ) ) );
	}

	return array(
		'id'       => 'plugins',
		'title'    => 'Plugins',
		'icon'     => 'dashicons-admin-plugins',
		'summary'  => 'Every installed plugin with a functional category and description, so the site\'s feature set can be understood without knowing each plugin by name.',
		'findings' => $findings,
		'blocks'   => array(
			array(
				'type' => 'facts',
				'rows' => array(
					wp_audit_fact( 'Installed plugins', count( $ctx['all_plugins'] ) ),
					wp_audit_fact( 'Active', count( $ctx['active_plugins'] ) ),
					wp_audit_fact( 'Inactive', count( $ctx['inactive_plugins'] ), count( $ctx['inactive_plugins'] ) ? 'warning' : 'good' ),
					wp_audit_fact( 'Active plugins with updates available', $needs_update, $needs_update ? 'warning' : 'good' ),
					wp_audit_fact( 'Active plugins with auto-updates on', count( array_intersect( $ctx['active_plugins'], $auto_updates ) ) ),
					wp_audit_fact( 'Must-use plugins', count( $mu_rows ) ),
					wp_audit_fact( 'Drop-ins', $dropin_rows ? implode( ', ', wp_list_pluck( $dropin_rows, 0 ) ) : 'None' ),
				),
			),
			array(
				'type'    => 'table',
				'title'   => 'Functionality by category (active plugins)',
				'columns' => array( 'Category', 'Plugins', 'Names' ),
				'rows'    => $category_rows,
				'note'    => 'Categories are inferred from plugin names and descriptions.',
			),
			array(
				'type'    => 'table',
				'title'   => 'Active plugins',
				'columns' => array( 'Plugin', 'Version', 'Category', 'Updates', 'Auto-updates', 'Description' ),
				'rows'    => $active_rows,
			),
			array(
				'type'    => 'table',
				'title'   => 'Inactive plugins',
				'columns' => array( 'Plugin', 'Version', 'Category', 'Updates', 'Description' ),
				'rows'    => $inactive_rows,
				'empty'   => 'No inactive plugins.',
			),
			array(
				'type'    => 'table',
				'title'   => 'Must-use plugins',
				'columns' => array( 'Plugin', 'File', 'Version', 'Description' ),
				'rows'    => $mu_rows,
				'empty'   => 'No must-use plugins.',
			),
		),
	);
}

/**
 * Theme details.
 */
function wp_audit_section_theme( array $ctx ) {
	$theme    = wp_audit_get_theme_details();
	$findings = array();
	$blocks   = array();

	if ( $theme['custom_css_bytes'] > 10 * KB_IN_BYTES ) {
		$findings[] = wp_audit_finding( 'warning', sprintf( 'Customizer "Additional CSS" holds %s of styles outside the theme files.', wp_audit_bytes( $theme['custom_css_bytes'] ) ) );
	}

	$blocks[] = array(
		'type' => 'facts',
		'rows' => array(
			wp_audit_fact( 'Theme', $theme['name'] . ' ' . $theme['version'] ),
			wp_audit_fact( 'Folder', $theme['stylesheet'] ),
			wp_audit_fact( 'Author', $theme['author'] ? $theme['author'] : '—' ),
			wp_audit_fact( 'Parent theme', $theme['parent'] ? $theme['parent'] : 'None (standalone theme)' ),
			wp_audit_fact( 'Theme type', $theme['is_block'] ? 'Block theme (Full Site Editing)' : 'Classic (PHP templates)' ),
			wp_audit_fact( 'theme.json', wp_audit_yes_no( $theme['theme_json'] ) ),
			wp_audit_fact( 'Requires WordPress / PHP', ( $theme['requires_wp'] ? $theme['requires_wp'] : '—' ) . ' / ' . ( $theme['requires_php'] ? $theme['requires_php'] : '—' ) ),
			wp_audit_fact( 'Theme supports', $theme['supports'] ? implode( ', ', $theme['supports'] ) : '—' ),
			wp_audit_fact( 'Customizer Additional CSS', wp_audit_bytes( $theme['custom_css_bytes'] ), $theme['custom_css_bytes'] > 10 * KB_IN_BYTES ? 'warning' : '' ),
			wp_audit_fact( 'Stored theme settings (theme_mods)', $theme['theme_mods'] ),
		),
	);

	if ( $theme['templates'] ) {
		$rows = array();
		foreach ( $theme['templates'] as $template ) {
			$rows[] = array( $template['title'], $template['slug'], 'custom' === $template['source'] ? wp_audit_cell( 'Edited in Site Editor', 'warning' ) : ucfirst( $template['source'] ) );
		}
		$blocks[] = array(
			'type'    => 'table',
			'title'   => 'Block templates',
			'columns' => array( 'Template', 'Slug', 'Source' ),
			'rows'    => $rows,
			'note'    => '"Edited in Site Editor" templates are stored in the database and override the theme files.',
		);
	}
	if ( $theme['parts'] ) {
		$rows = array();
		foreach ( $theme['parts'] as $part ) {
			$rows[] = array( $part['title'], $part['slug'], $part['area'], 'custom' === $part['source'] ? wp_audit_cell( 'Edited in Site Editor', 'warning' ) : ucfirst( $part['source'] ) );
		}
		$blocks[] = array(
			'type'    => 'table',
			'title'   => 'Template parts',
			'columns' => array( 'Part', 'Slug', 'Area', 'Source' ),
			'rows'    => $rows,
		);
	}
	if ( $theme['php_files'] ) {
		$blocks[] = array(
			'type'      => 'list',
			'title'     => sprintf( 'Theme PHP files (top two levels, %d files)', $theme['php_file_count'] ),
			'items'     => $theme['php_files'],
			'collapsed' => true,
		);
	}

	return array(
		'id'       => 'theme',
		'title'    => 'Theme',
		'icon'     => 'dashicons-admin-appearance',
		'summary'  => 'The active theme, how it is built (block vs. classic), what it supports, and customizations stored outside the theme files.',
		'findings' => $findings,
		'blocks'   => $blocks,
	);
}

/**
 * Users and roles.
 */
function wp_audit_section_users( array $ctx ) {
	$users         = $ctx['users'];
	$findings      = array();
	$default_roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
	$admins        = isset( $users['avail_roles']['administrator'] ) ? $users['avail_roles']['administrator'] : 0;
	$admin_login   = (bool) username_exists( 'admin' );

	if ( $admins > 3 ) {
		$findings[] = wp_audit_finding( 'warning', sprintf( '%d users have the Administrator role. Fewer admins means a smaller attack surface.', $admins ) );
	}
	if ( $admin_login ) {
		$findings[] = wp_audit_finding( 'bad', 'A user with the username "admin" exists — the first username brute-force attacks try.' );
	}

	$rows = array();
	foreach ( wp_roles()->roles as $role => $data ) {
		$rows[] = array(
			translate_user_role( $data['name'] ),
			$role,
			wp_audit_num( isset( $users['avail_roles'][ $role ] ) ? $users['avail_roles'][ $role ] : 0 ),
			count( array_filter( (array) $data['capabilities'] ) ),
			in_array( $role, $default_roles, true ) ? 'Default' : wp_audit_cell( 'Custom', 'warning' ),
		);
	}

	return array(
		'id'       => 'users',
		'title'    => 'Users & Roles',
		'icon'     => 'dashicons-admin-users',
		'summary'  => 'Who can log in and what they can do. Role counts also indicate CMS training needs.',
		'findings' => $findings,
		'blocks'   => array(
			array(
				'type' => 'facts',
				'rows' => array(
					wp_audit_fact( 'Total users', wp_audit_num( $users['total_users'] ) ),
					wp_audit_fact( 'Administrators', $admins, $admins > 3 ? 'warning' : 'good' ),
					wp_audit_fact( 'Username "admin" exists', wp_audit_yes_no( $admin_login ), $admin_login ? 'bad' : 'good' ),
					wp_audit_fact( 'Anyone can register', wp_audit_yes_no( get_option( 'users_can_register' ) ), get_option( 'users_can_register' ) ? 'warning' : '' ),
					wp_audit_fact( 'Default role for new users', get_option( 'default_role' ) ),
				),
			),
			array(
				'type'    => 'table',
				'title'   => 'Roles',
				'columns' => array( 'Role', 'Slug', 'Users', 'Capabilities', 'Type' ),
				'rows'    => $rows,
			),
		),
	);
}

/**
 * Media library.
 */
function wp_audit_section_media( array $ctx ) {
	$media    = wp_audit_get_media_stats();
	$findings = array();

	if ( $media['images'] && $media['missing_alt'] / max( 1, $media['images'] ) > 0.25 ) {
		$findings[] = wp_audit_finding( 'warning', sprintf( '%s of %s images (%d%%) have no alt text in the media library.', wp_audit_num( $media['missing_alt'] ), wp_audit_num( $media['images'] ), round( 100 * $media['missing_alt'] / $media['images'] ) ) );
	}

	$mime_rows = array();
	foreach ( array_slice( $media['mime_types'], 0, 20, true ) as $mime => $count ) {
		$mime_rows[] = array( $mime ? $mime : '(none)', wp_audit_num( $count ) );
	}

	return array(
		'id'       => 'media',
		'title'    => 'Media Library',
		'icon'     => 'dashicons-format-image',
		'summary'  => 'Size and make-up of the media library, and how well images are described for accessibility.',
		'findings' => $findings,
		'blocks'   => array(
			array(
				'type' => 'facts',
				'rows' => array(
					wp_audit_fact( 'Total media files', wp_audit_num( $ctx['total_media'] ) ),
					wp_audit_fact( 'Images', wp_audit_num( $media['images'] ) ),
					wp_audit_fact( 'Images without alt text', wp_audit_num( $media['missing_alt'] ), $media['missing_alt'] ? 'warning' : 'good' ),
					wp_audit_fact( 'Unattached files', wp_audit_num( $media['unattached'] ), '', 'Uploaded directly to the library or detached; not necessarily unused' ),
					wp_audit_fact( 'Attached to deleted posts', wp_audit_num( $media['orphaned'] ), $media['orphaned'] ? 'warning' : 'good' ),
					wp_audit_fact( 'Uploads organized by month/year', wp_audit_yes_no( get_option( 'uploads_use_yearmonth_folders' ) ) ),
				),
			),
			array(
				'type'    => 'table',
				'title'   => 'Files by type',
				'columns' => array( 'MIME Type', 'Files' ),
				'rows'    => $mime_rows,
				'empty'   => 'The media library is empty.',
			),
		),
	);
}

/**
 * SEO, URLs and redirects.
 */
function wp_audit_section_seo( array $ctx ) {
	$findings = array();
	$home     = $ctx['homepage'];
	$robots   = wp_audit_check_robots();
	$visible  = (bool) get_option( 'blog_public' );

	$seo_plugin = wp_audit_first_detected(
		array(
			'Yoast SEO'         => defined( 'WPSEO_VERSION' ),
			'Rank Math'         => defined( 'RANK_MATH_VERSION' ),
			'All in One SEO'    => defined( 'AIOSEO_VERSION' ),
			'SEOPress'          => defined( 'SEOPRESS_VERSION' ),
			'The SEO Framework' => defined( 'THE_SEO_FRAMEWORK_VERSION' ),
		)
	);

	$missing_titles = wp_audit_remember(
		'missing_titles',
		15 * MINUTE_IN_SECONDS,
		function () {
			global $wpdb;
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post', 'page') AND post_status = 'publish' AND (post_title = '' OR post_title IS NULL)" );
		}
	);

	if ( ! $visible ) {
		$findings[] = wp_audit_finding( $ctx['is_production'] ? 'bad' : 'warning', 'Search engines are discouraged from indexing this site (Settings > Reading).' );
	}
	if ( $robots['blocks_all'] ) {
		$findings[] = wp_audit_finding( $ctx['is_production'] ? 'bad' : 'warning', 'robots.txt contains "Disallow: /", blocking search engines from the whole site.' );
	}
	if ( ! $seo_plugin ) {
		$findings[] = wp_audit_finding( 'warning', 'No SEO plugin detected; titles, meta descriptions and schema rely on the theme.' );
	}

	$permalinks = get_option( 'permalink_structure' );
	$rules      = get_option( 'rewrite_rules' );
	$redirects  = wp_audit_get_redirect_counts( $ctx['post_counts'] );
	$redirect_text = array();
	foreach ( $redirects as $plugin => $count ) {
		$redirect_text[] = $plugin . ': ' . wp_audit_num( $count );
	}

	$facts = array(
		wp_audit_fact( 'SEO plugin', $seo_plugin ? $seo_plugin : 'None detected', $seo_plugin ? 'good' : 'warning' ),
		wp_audit_fact( 'Search engine visibility', $visible ? 'Public' : 'Discouraged', $visible ? 'good' : 'bad' ),
		wp_audit_fact( 'Permalink structure', $permalinks ? $permalinks : 'Plain (/?p=123)', $permalinks ? '' : 'warning' ),
		wp_audit_fact( 'Category / tag base', ( get_option( 'category_base' ) ? get_option( 'category_base' ) : 'category' ) . ' / ' . ( get_option( 'tag_base' ) ? get_option( 'tag_base' ) : 'tag' ) ),
		wp_audit_fact( 'Rewrite rules', is_array( $rules ) ? wp_audit_num( count( $rules ) ) : 'Not generated' ),
		wp_audit_fact( 'robots.txt', $robots['source'] . ( $robots['reachable'] ? '' : ' — could not fetch: ' . ( isset( $robots['error'] ) ? $robots['error'] : 'unknown error' ) ), $robots['blocks_all'] ? 'bad' : ( $robots['reachable'] ? 'good' : 'warning' ), $robots['blocks_all'] ? 'Contains "Disallow: /"' : '' ),
		wp_audit_fact( 'Sitemaps listed in robots.txt', $robots['sitemaps'] ? implode( ', ', $robots['sitemaps'] ) : 'None', $robots['sitemaps'] ? 'good' : 'warning' ),
		wp_audit_fact( 'WordPress core sitemap', function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled() ? 'Enabled (/wp-sitemap.xml)' : 'Disabled (usually replaced by an SEO plugin)' ),
		wp_audit_fact( 'Redirect rules', $redirect_text ? implode( ', ', $redirect_text ) : 'No redirect plugin data found' ),
		wp_audit_fact( 'Published posts/pages with no title', wp_audit_num( $missing_titles ), $missing_titles ? 'warning' : 'good' ),
	);

	$blocks = array( array( 'type' => 'facts', 'rows' => $facts ) );

	if ( empty( $home['error'] ) ) {
		$h1_count = count( $home['h1s'] );
		if ( 1 !== $h1_count ) {
			$findings[] = wp_audit_finding( 'warning', sprintf( 'The homepage has %d <h1> headings (expected exactly one).', $h1_count ) );
		}
		if ( '' === $home['description'] ) {
			$findings[] = wp_audit_finding( 'warning', 'The homepage has no meta description.' );
		}
		$blocks[] = array(
			'type'  => 'facts',
			'title' => 'Homepage SEO tags',
			'rows'  => array(
				wp_audit_fact( 'Title tag', $home['title'] ? $home['title'] : '(missing)', $home['title'] ? '' : 'bad', $home['title'] ? strlen( $home['title'] ) . ' characters' : '' ),
				wp_audit_fact( 'Meta description', $home['description'] ? $home['description'] : '(missing)', $home['description'] ? '' : 'warning', $home['description'] ? strlen( $home['description'] ) . ' characters' : '' ),
				wp_audit_fact( 'Canonical URL', $home['canonical'] ? $home['canonical'] : '(missing)', $home['canonical'] ? '' : 'warning' ),
				wp_audit_fact( 'Robots meta', $home['robots_meta'] ? $home['robots_meta'] : '(none — defaults to index, follow)', false !== stripos( $home['robots_meta'], 'noindex' ) ? 'bad' : '' ),
				wp_audit_fact( 'H1 headings', $h1_count ? implode( ' | ', $home['h1s'] ) : '(none)', 1 === $h1_count ? 'good' : 'warning' ),
				wp_audit_fact( 'HTML lang attribute', $home['lang'] ? $home['lang'] : '(missing)', $home['lang'] ? '' : 'warning' ),
				wp_audit_fact( 'Open Graph', ( $home['og_title'] ? 'og:title ✓' : 'og:title missing' ) . ', ' . ( $home['og_image'] ? 'og:image ✓' : 'og:image missing' ), $home['og_title'] && $home['og_image'] ? 'good' : 'warning' ),
				wp_audit_fact( 'Twitter card', $home['twitter_card'] ? $home['twitter_card'] : '(none)' ),
				wp_audit_fact( 'Structured data (schema.org types)', $home['schema_types'] ? implode( ', ', $home['schema_types'] ) : 'None found', $home['schema_types'] ? 'good' : 'warning' ),
			),
		);
	}

	return array(
		'id'       => 'seo',
		'title'    => 'SEO & URLs',
		'icon'     => 'dashicons-search',
		'summary'  => 'URL structure, indexing controls, sitemaps, redirects and the homepage\'s SEO tags. URL structure and redirects matter most when a redesign changes page paths.',
		'findings' => $findings,
		'blocks'   => $blocks,
	);
}

/**
 * Front-end analysis of the homepage HTML.
 */
function wp_audit_section_frontend( array $ctx ) {
	$home = $ctx['homepage'];

	if ( ! empty( $home['error'] ) ) {
		return array(
			'id'       => 'frontend',
			'title'    => 'Front-End (Homepage)',
			'icon'     => 'dashicons-desktop',
			'summary'  => 'Analysis of the homepage HTML as a visitor receives it.',
			'findings' => array( wp_audit_finding( 'warning', 'The homepage could not be fetched for analysis: ' . $home['message'] ) ),
			'blocks'   => array( array( 'type' => 'text', 'text' => 'Could not fetch the homepage: ' . $home['message'] ) ),
		);
	}

	$findings = array();
	$images   = $home['images'];
	if ( $images['missing_dimensions'] > 0 ) {
		$findings[] = wp_audit_finding( 'warning', sprintf( '%d of %d homepage images lack width/height attributes, which causes layout shift (CLS).', $images['missing_dimensions'], $images['total'] ) );
	}
	if ( $home['scripts']['total'] > 40 ) {
		$findings[] = wp_audit_finding( 'warning', sprintf( 'The homepage loads %d scripts; heavy JavaScript hurts interactivity (INP).', $home['scripts']['total'] ) );
	}
	if ( $home['html_bytes'] > 500 * KB_IN_BYTES ) {
		$findings[] = wp_audit_finding( 'warning', sprintf( 'The homepage HTML alone is %s.', wp_audit_bytes( $home['html_bytes'] ) ) );
	}
	if ( in_array( 'Universal Analytics (retired)', $home['services'], true ) ) {
		$findings[] = wp_audit_finding( 'warning', 'The homepage still loads Universal Analytics, which Google shut down in 2024.' );
	}
	if ( in_array( 'Accessibility overlay', $home['services'], true ) ) {
		$findings[] = wp_audit_finding( 'warning', 'An accessibility overlay widget is installed; overlays do not make a site WCAG-compliant on their own.' );
	}

	$host_rows = array();
	foreach ( $home['hosts'] as $host => $counts ) {
		$host_rows[] = array( $host, $counts['scripts'], $counts['styles'] );
	}

	return array(
		'id'       => 'frontend',
		'title'    => 'Front-End (Homepage)',
		'icon'     => 'dashicons-desktop',
		'summary'  => 'What the homepage actually delivers to visitors: page weight, scripts and styles, images, and every third-party service it loads (analytics, chat, fonts, consent tools).',
		'findings' => $findings,
		'blocks'   => array(
			array(
				'type' => 'facts',
				'rows' => array(
					wp_audit_fact( 'HTTP status', $home['status'], 200 === $home['status'] ? 'good' : 'warning' ),
					wp_audit_fact( 'Response time (server loopback)', wp_audit_num( $home['response_ms'] ) . ' ms', $home['response_ms'] > 1800 ? 'bad' : ( $home['response_ms'] > 800 ? 'warning' : 'good' ), 'Full HTML download measured from the server itself' ),
					wp_audit_fact( 'HTML size', wp_audit_bytes( $home['html_bytes'] ) ),
					wp_audit_fact( 'Scripts', sprintf( '%d total — %d external (%d deferred/async), %d inline', $home['scripts']['total'], $home['scripts']['external'], $home['scripts']['deferred'], $home['scripts']['inline'] ) ),
					wp_audit_fact( 'Stylesheets', sprintf( '%d linked, %d inline <style> blocks', $home['stylesheets'], $home['inline_style_blocks'] ) ),
					wp_audit_fact( 'Preload hints', $home['preloads'] ),
					wp_audit_fact( 'Images', sprintf( '%d total — %d lazy-loaded, %d WebP/AVIF', $images['total'], $images['lazy'], $images['webp_avif'] ) ),
					wp_audit_fact( 'Images missing width/height', $images['missing_dimensions'], $images['missing_dimensions'] ? 'warning' : 'good' ),
					wp_audit_fact( 'Images missing alt attribute', $images['missing_alt'], $images['missing_alt'] ? 'warning' : 'good' ),
					wp_audit_fact( 'Iframes', $home['iframes'] ),
					wp_audit_fact( 'Generator tag', $home['generator'] ? $home['generator'] : '(none)' ),
					wp_audit_fact( 'Viewport meta', $home['viewport'] ? $home['viewport'] : '(missing)', $home['viewport'] ? '' : 'bad' ),
				),
			),
			array(
				'type'  => 'list',
				'title' => 'Third-party services detected',
				'items' => $home['services'],
				'empty' => 'No known third-party services detected.',
			),
			array(
				'type'  => 'list',
				'title' => 'Tracking / tag IDs found',
				'items' => $home['tracking_ids'],
				'empty' => 'No Google Analytics, Tag Manager or Ads IDs found in the homepage HTML.',
			),
			array(
				'type'    => 'table',
				'title'   => 'External hosts loading scripts or styles',
				'columns' => array( 'Host', 'Scripts', 'Stylesheets' ),
				'rows'    => $host_rows,
				'empty'   => 'All scripts and styles are served from the site\'s own domain.',
			),
		),
	);
}

/**
 * Performance, hosting resources, caching, options and cron.
 */
function wp_audit_section_performance( array $ctx ) {
	$findings = array();
	$options  = wp_audit_get_options_stats();
	$cron     = wp_audit_get_cron_stats();
	$caching  = wp_audit_detect_caching( $ctx['active_plugins'], $ctx['headers'] );
	$headers  = $ctx['headers'];

	// 800 KB is the threshold core's Site Health uses for autoloaded options.
	$autoload_status = $options['autoload_bytes'] > 800 * KB_IN_BYTES ? 'bad' : ( $options['autoload_bytes'] > 500 * KB_IN_BYTES ? 'warning' : 'good' );
	if ( 'good' !== $autoload_status ) {
		$findings[] = wp_audit_finding( $autoload_status, sprintf( 'Autoloaded options total %s; every uncached request loads this data.', wp_audit_bytes( $options['autoload_bytes'] ) ) );
	}
	if ( ! $caching ) {
		$findings[] = wp_audit_finding( 'warning', 'No page caching or object caching was detected.' );
	}
	if ( $cron['overdue'] > 5 ) {
		$findings[] = wp_audit_finding( 'warning', sprintf( '%d scheduled cron events are more than an hour overdue; WP-Cron is not running reliably.', $cron['overdue'] ) );
	}
	if ( $options['expired'] > 500 ) {
		$findings[] = wp_audit_finding( 'warning', sprintf( '%s expired transients are sitting in wp_options.', wp_audit_num( $options['expired'] ) ) );
	}

	$cdn = wp_audit_first_detected(
		array(
			'Cloudflare' => isset( $headers['cf-ray'] ),
			'Sucuri'     => isset( $headers['x-sucuri-id'] ),
			'Fastly'     => isset( $headers['x-fastly-request-id'] ),
		)
	);

	$opcache = function_exists( 'opcache_get_status' ) && ini_get( 'opcache.enable' );
	$https   = is_ssl() || 0 === strpos( home_url(), 'https://' );

	$largest_rows = array();
	foreach ( $options['largest'] as $option ) {
		$largest_rows[] = array( $option['option_name'], wp_audit_bytes( $option['bytes'] ) );
	}

	$hook_rows = array();
	foreach ( $cron['hooks'] as $hook => $count ) {
		$hook_rows[] = array( $hook, $count );
	}

	return array(
		'id'       => 'performance',
		'title'    => 'Performance & Hosting',
		'icon'     => 'dashicons-performance',
		'summary'  => 'Server resources, caching layers, database load from options and cron, and Google PageSpeed / Core Web Vitals for the homepage.',
		'findings' => $findings,
		'blocks'   => array(
			array(
				'type'  => 'facts',
				'title' => 'Server',
				'rows'  => array(
					wp_audit_fact( 'PHP memory limit', ini_get( 'memory_limit' ) ),
					wp_audit_fact( 'WordPress memory limit (front / admin)', WP_MEMORY_LIMIT . ' / ' . WP_MAX_MEMORY_LIMIT ),
					wp_audit_fact( 'Max execution time', ini_get( 'max_execution_time' ) . ' s' ),
					wp_audit_fact( 'Max upload size', wp_audit_bytes( wp_max_upload_size() ) ),
					wp_audit_fact( 'OPcache', $opcache ? 'Enabled' : 'Disabled', $opcache ? 'good' : 'warning' ),
					wp_audit_fact( 'HTTPS', $https ? 'Yes' : 'No', $https ? 'good' : ( $ctx['is_production'] ? 'bad' : 'warning' ) ),
					wp_audit_fact( 'Compression', isset( $headers['content-encoding'] ) ? $headers['content-encoding'] : 'None detected', isset( $headers['content-encoding'] ) ? 'good' : 'warning' ),
				),
			),
			array(
				'type'  => 'facts',
				'title' => 'Caching & CDN',
				'rows'  => array(
					wp_audit_fact( 'Caching detected', $caching ? implode( '; ', $caching ) : 'None', $caching ? 'good' : 'warning' ),
					wp_audit_fact( 'CDN', $cdn ? $cdn : 'None detected from headers' ),
					wp_audit_fact( 'Cache-Control (homepage)', isset( $headers['cache-control'] ) ? $headers['cache-control'] : '(not set)' ),
				),
			),
			array(
				'type'  => 'facts',
				'title' => 'Options & cron',
				'rows'  => array(
					wp_audit_fact( 'Autoloaded options', wp_audit_num( $options['autoload_count'] ) . ' options, ' . wp_audit_bytes( $options['autoload_bytes'] ), $autoload_status, 'Under 800 KB recommended' ),
					wp_audit_fact( 'Total options', wp_audit_num( $options['total_options'] ) ),
					wp_audit_fact( 'Transients in database', wp_audit_num( $options['transients'] ) . ' (' . wp_audit_num( $options['expired'] ) . ' expired)', $options['expired'] > 500 ? 'warning' : '' ),
					wp_audit_fact( 'Scheduled cron events', wp_audit_num( $cron['events'] ) . ' across ' . count( $cron['hooks'] ) . ' hooks' ),
					wp_audit_fact( 'Overdue cron events (>1 hour)', $cron['overdue'], $cron['overdue'] > 5 ? 'warning' : 'good' ),
					wp_audit_fact( 'WP-Cron', $cron['disabled'] ? 'Disabled (DISABLE_WP_CRON) — needs a server cron job' : 'Runs on page visits' ),
				),
			),
			array(
				'type'    => 'table',
				'title'   => 'Largest autoloaded options',
				'columns' => array( 'Option', 'Size' ),
				'rows'    => $largest_rows,
			),
			array(
				'type'      => 'table',
				'title'     => 'Scheduled cron hooks',
				'columns'   => array( 'Hook', 'Events' ),
				'rows'      => $hook_rows,
				'collapsed' => true,
				'note'      => 'Hook names reveal background integrations (syncs, imports, emails).',
			),
			array(
				'type'  => 'pagespeed',
				'title' => 'PageSpeed Insights & Core Web Vitals (homepage)',
			),
		),
	);
}

/**
 * Security posture.
 */
function wp_audit_section_security( array $ctx ) {
	$findings = array();
	$version  = wp_audit_check_wp_version();
	$ssl      = wp_audit_check_ssl_certificate();
	$perms    = wp_audit_check_file_permissions();
	$exposure = wp_audit_probe_exposure();
	$headers  = $ctx['headers'];
	$prod_bad = $ctx['is_production'] ? 'bad' : 'warning';

	// --- Configuration constants (debug settings are normal on local/staging, so only "bad" on production) ---
	$debug_on      = defined( 'WP_DEBUG' ) && WP_DEBUG;
	$debug_display = $debug_on && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY );
	$debug_log     = defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false;
	$file_edit_off = ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
	$auto_core     = defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ? 'Disabled (AUTOMATIC_UPDATER_DISABLED)' : ( defined( 'WP_AUTO_UPDATE_CORE' ) ? 'WP_AUTO_UPDATE_CORE = ' . var_export( WP_AUTO_UPDATE_CORE, true ) : 'Default (minor/security releases)' );

	$security_plugins = array();
	foreach ( array_keys( $ctx['plugin_categories'], 'Security', true ) as $file ) {
		$security_plugins[] = $ctx['all_plugins'][ $file ]['Name'];
	}

	if ( $debug_display ) {
		$findings[] = wp_audit_finding( $prod_bad, 'PHP errors are displayed on screen (WP_DEBUG_DISPLAY), exposing file paths to visitors.' );
	}
	if ( ! $file_edit_off ) {
		$findings[] = wp_audit_finding( 'warning', 'The wp-admin theme/plugin file editor is enabled (set DISALLOW_FILE_EDIT).' );
	}

	$config_facts = array(
		wp_audit_fact( 'Environment type', wp_get_environment_type() ),
		wp_audit_fact( 'WP_DEBUG', $debug_on ? 'On' : 'Off', $debug_on ? $prod_bad : 'good' ),
		wp_audit_fact( 'Errors displayed on screen (WP_DEBUG_DISPLAY)', $debug_display ? 'Yes' : 'No', $debug_display ? $prod_bad : 'good' ),
		wp_audit_fact( 'Debug log (WP_DEBUG_LOG)', true === $debug_log || '1' === $debug_log ? 'On — wp-content/debug.log' : ( is_string( $debug_log ) && '' !== $debug_log ? 'On — ' . $debug_log : 'Off' ) ),
		wp_audit_fact( 'SCRIPT_DEBUG', defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? 'On (unminified core assets)' : 'Off', defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? 'warning' : 'good' ),
		wp_audit_fact( 'File editor in wp-admin', $file_edit_off ? 'Disabled' : 'Enabled', $file_edit_off ? 'good' : 'warning' ),
		wp_audit_fact( 'Core auto-updates', $auto_core ),
		wp_audit_fact( 'Database table prefix', $ctx['db']['prefix'], 'wp_' === $ctx['db']['prefix'] ? 'warning' : 'good', 'wp_' === $ctx['db']['prefix'] ? 'Default prefix makes automated SQL injection slightly easier' : '' ),
		wp_audit_fact( 'Security plugins', $security_plugins ? implode( ', ', $security_plugins ) : 'None' ),
	);

	// --- Exposure probes ---
	$exposure_facts = array();
	if ( isset( $exposure['debug_log'] ) ) {
		$exposed = $exposure['debug_log']['exposed'];
		$exposure_facts[] = wp_audit_fact( 'debug.log', ( $exposed ? 'Publicly downloadable' : 'Exists, not publicly reachable' ) . ' (' . wp_audit_bytes( $exposure['debug_log']['bytes'] ) . ')', $exposed ? 'bad' : ( $exposure['debug_log']['bytes'] > 50 * MB_IN_BYTES ? 'warning' : 'good' ) );
		if ( $exposed ) {
			$findings[] = wp_audit_finding( 'bad', 'wp-content/debug.log can be downloaded by anyone; it can leak paths, queries and personal data.' );
		}
	}
	$exposure_facts[] = wp_audit_fact( 'Directory listing in uploads', $exposure['uploads_listing'] ? 'Enabled — every uploaded file can be browsed' : 'Disabled', $exposure['uploads_listing'] ? 'bad' : 'good' );
	$exposure_facts[] = wp_audit_fact( 'Usernames via REST API (/wp-json/wp/v2/users)', $exposure['rest_users_exposed'] ? $exposure['rest_users_exposed'] . ' user(s) listed publicly' : 'Not exposed', $exposure['rest_users_exposed'] ? 'warning' : 'good' );
	$exposure_facts[] = wp_audit_fact( 'XML-RPC (xmlrpc.php)', $exposure['xmlrpc_open'] ? 'Enabled and reachable' : 'Disabled or blocked', $exposure['xmlrpc_open'] ? 'warning' : 'good', $exposure['xmlrpc_open'] ? 'Only needed for Jetpack or the mobile app; common brute-force target' : '' );
	if ( $exposure['uploads_listing'] ) {
		$findings[] = wp_audit_finding( 'bad', 'Directory listing is enabled for the uploads folder.' );
	}
	if ( $exposure['rest_users_exposed'] ) {
		$findings[] = wp_audit_finding( 'warning', 'Login usernames are publicly listed by the REST API users endpoint.' );
	}

	// --- Security headers ---
	$header_rows = array();
	$missing     = array();
	foreach ( array(
		'strict-transport-security' => 'Forces HTTPS on repeat visits',
		'content-security-policy'   => 'Restricts where scripts/styles may load from',
		'x-frame-options'           => 'Prevents clickjacking via iframes',
		'x-content-type-options'    => 'Stops MIME-type sniffing',
		'referrer-policy'           => 'Limits referrer data sent to other sites',
		'permissions-policy'        => 'Restricts browser features (camera, geolocation…)',
	) as $name => $purpose ) {
		$present = isset( $headers[ $name ] );
		if ( ! $present ) {
			$missing[] = $name;
		}
		$header_rows[] = array( $name, $present ? wp_audit_cell( wp_audit_excerpt( $headers[ $name ], 80 ), 'good' ) : wp_audit_cell( 'Missing', 'warning' ), $purpose );
	}
	if ( count( $missing ) >= 4 && empty( $ctx['homepage']['error'] ) ) {
		$findings[] = wp_audit_finding( 'warning', sprintf( '%d of 6 common security headers are missing (%s).', count( $missing ), implode( ', ', $missing ) ) );
	}

	// --- SSL ---
	$ssl_facts = array( wp_audit_fact( 'HTTPS', $ssl['enabled'] ? 'Yes' : 'No', $ssl['enabled'] ? 'good' : $prod_bad ) );
	if ( ! $ssl['enabled'] && $ctx['is_production'] ) {
		$findings[] = wp_audit_finding( 'bad', 'The site does not use HTTPS.' );
	}
	if ( $ssl['enabled'] && empty( $ssl['error'] ) ) {
		$ssl_facts[] = wp_audit_fact( 'Certificate issuer', $ssl['issuer'] );
		$ssl_facts[] = wp_audit_fact( 'Certificate subject', $ssl['subject'] );
		$ssl_facts[] = wp_audit_fact( 'Expires', $ssl['expires'] . ' (' . $ssl['days_until_expiry'] . ' days)', $ssl['expires_soon'] ? 'warning' : 'good' );
		if ( $ssl['expires_soon'] ) {
			$findings[] = wp_audit_finding( 'warning', sprintf( 'The SSL certificate expires in %d days.', $ssl['days_until_expiry'] ) );
		}
	} elseif ( ! empty( $ssl['error'] ) ) {
		$ssl_facts[] = wp_audit_fact( 'Certificate check', $ssl['error'] . ( ! empty( $ssl['message'] ) ? ': ' . $ssl['message'] : '' ), 'warning' );
	}

	$blocks = array(
		array(
			'type'  => 'facts',
			'title' => 'WordPress core',
			'rows'  => array(
				wp_audit_fact( 'Installed version', $version['current'] ),
				wp_audit_fact( 'Latest version', $version['latest'] ),
				wp_audit_fact( 'Status', $version['security_risk'] ? 'Critically outdated' : ( $version['outdated'] ? 'Update available' : 'Up to date' ), $version['security_risk'] ? 'bad' : ( $version['outdated'] ? 'warning' : 'good' ), isset( $version['error'] ) ? $version['error'] : '' ),
			),
		),
		array(
			'type'  => 'facts',
			'title' => 'SSL certificate',
			'rows'  => $ssl_facts,
		),
		array(
			'type'  => 'facts',
			'title' => 'Configuration',
			'rows'  => $config_facts,
		),
		array(
			'type'  => 'facts',
			'title' => 'Public exposure (tested with unauthenticated requests)',
			'rows'  => $exposure_facts,
		),
		array(
			'type'    => 'table',
			'title'   => 'Security headers (homepage response)',
			'columns' => array( 'Header', 'Value', 'Purpose' ),
			'rows'    => empty( $ctx['homepage']['error'] ) ? $header_rows : array(),
			'empty'   => 'Could not fetch the homepage to read response headers.',
		),
	);

	if ( null === $perms ) {
		$blocks[] = array( 'type' => 'text', 'title' => 'File permissions', 'text' => 'Skipped: this server runs Windows, where Unix file permissions don\'t apply. Check permissions on the production host.' );
	} else {
		$rows = array();
		foreach ( $perms as $perm ) {
			$rows[] = array( $perm['path'], wp_audit_cell( $perm['permissions'], $perm['status'] ), $perm['recommended'] );
			if ( 'bad' === $perm['status'] ) {
				$findings[] = wp_audit_finding( 'bad', sprintf( '%s has insecure permissions (%s; recommended %s).', $perm['path'], $perm['permissions'], $perm['recommended'] ) );
			}
		}
		$blocks[] = array(
			'type'    => 'table',
			'title'   => 'File permissions',
			'columns' => array( 'File/Directory', 'Current', 'Recommended' ),
			'rows'    => $rows,
		);
	}

	return array(
		'id'       => 'security',
		'title'    => 'Security',
		'icon'     => 'dashicons-shield',
		'summary'  => 'Core version, HTTPS, configuration hardening, information leaks that anyone on the internet can see, response headers and file permissions.',
		'findings' => $findings,
		'blocks'   => $blocks,
	);
}

/**
 * Database health and non-core tables.
 */
function wp_audit_section_database( array $ctx ) {
	$db       = $ctx['db'];
	$findings = array();
	$tables   = wp_audit_find_orphaned_tables();

	$revisions   = wp_audit_count_status( $ctx['post_counts'], 'revision', 'inherit' );
	$auto_drafts = wp_audit_count_status_all( $ctx['post_counts'], 'auto-draft' );
	$trashed     = wp_audit_count_status_all( $ctx['post_counts'], 'trash' );

	if ( $db['orphaned_postmeta'] > 100 ) {
		$findings[] = wp_audit_finding( 'warning', sprintf( '%s postmeta rows belong to posts that no longer exist.', wp_audit_num( $db['orphaned_postmeta'] ) ) );
	}
	if ( $revisions > 5000 ) {
		$findings[] = wp_audit_finding( 'warning', sprintf( '%s post revisions are stored; consider limiting WP_POST_REVISIONS.', wp_audit_num( $revisions ) ) );
	}

	$unowned = array_filter(
		$tables,
		function ( $table ) {
			return true !== $table['active'];
		}
	);
	if ( $unowned ) {
		$findings[] = wp_audit_finding( 'warning', sprintf( '%d database table(s) belong to inactive or unknown plugins and may be leftovers.', count( $unowned ) ) );
	}

	$table_rows = array();
	foreach ( $tables as $table ) {
		if ( true === $table['active'] ) {
			$status = wp_audit_cell( 'In use — keep', 'good' );
		} elseif ( false === $table['active'] ) {
			$status = wp_audit_cell( 'Owner not active — review for removal', 'warning' );
		} else {
			$status = wp_audit_cell( 'Unknown owner — verify before removing', 'warning' );
		}
		$table_rows[] = array( $table['table'], $table['owner'] ? $table['owner'] : 'Unknown', null === $table['rows'] ? '—' : wp_audit_num( $table['rows'] ), null === $table['size_mb'] ? '—' : $table['size_mb'] . ' MB', $status );
	}

	return array(
		'id'       => 'database',
		'title'    => 'Database',
		'icon'     => 'dashicons-database',
		'summary'  => 'Size, leftover and orphaned data, and every non-core table with the plugin it most likely belongs to.',
		'findings' => $findings,
		'blocks'   => array(
			array(
				'type' => 'facts',
				'rows' => array(
					wp_audit_fact( 'Database size', wp_audit_num( $db['size_mb'] ) . ' MB' ),
					wp_audit_fact( 'Tables', $db['table_count'] . ' (' . count( $tables ) . ' non-core)' ),
					wp_audit_fact( 'Server', $db['server'] ),
					wp_audit_fact( 'Charset', $db['charset'] ),
					wp_audit_fact( 'Orphaned postmeta rows', wp_audit_num( $db['orphaned_postmeta'] ), $db['orphaned_postmeta'] ? 'warning' : 'good' ),
					wp_audit_fact( 'Orphaned term relationships', wp_audit_num( $db['orphaned_terms'] ), $db['orphaned_terms'] ? 'warning' : 'good' ),
					wp_audit_fact( 'Posts with more than 50 meta rows', wp_audit_num( $db['excessive_meta'] ), $db['excessive_meta'] ? 'warning' : 'good', 'Common with ACF/page builders; large counts slow queries' ),
					wp_audit_fact( 'Post revisions', wp_audit_num( $revisions ), $revisions > 5000 ? 'warning' : '', defined( 'WP_POST_REVISIONS' ) ? 'WP_POST_REVISIONS = ' . var_export( WP_POST_REVISIONS, true ) : 'Unlimited (WP_POST_REVISIONS not set)' ),
					wp_audit_fact( 'Auto-drafts', wp_audit_num( $auto_drafts ) ),
					wp_audit_fact( 'Items in trash', wp_audit_num( $trashed ) ),
					wp_audit_fact( 'Health score', wp_audit_database_health_score( $db ) . ' / 100' ),
				),
			),
			array(
				'type'      => 'table',
				'title'     => 'Non-core tables',
				'columns'   => array( 'Table', 'Likely Owner', 'Rows (approx.)', 'Size', 'Recommendation' ),
				'rows'      => $table_rows,
				'empty'     => 'No tables beyond the WordPress core structure.',
				'collapsed' => count( $table_rows ) > 25,
				'note'      => 'Always take a backup before dropping tables.',
			),
		),
	);
}

/**
 * RFQ / scoping summary: the data a vendor needs to estimate a rebuild, plus open questions.
 */
function wp_audit_section_rfq( array $ctx ) {
	$categories = array_count_values( $ctx['plugin_categories'] );
	$features   = array();
	$map        = array(
		'E-commerce'            => 'E-commerce',
		'Membership & LMS'      => 'Memberships / courses',
		'Events & booking'      => 'Events or bookings',
		'Multilingual'          => 'Multiple languages',
		'Search & filtering'    => 'Advanced search / filtering',
		'Maps & location'       => 'Maps / locations',
		'Forms'                 => 'Forms',
		'Custom fields & content types' => 'Custom content model',
	);
	foreach ( $map as $category => $label ) {
		if ( ! empty( $categories[ $category ] ) ) {
			$names = array();
			foreach ( array_keys( $ctx['plugin_categories'], $category, true ) as $file ) {
				$names[] = $ctx['all_plugins'][ $file ]['Name'];
			}
			$features[] = $label . ' (' . implode( ', ', $names ) . ')';
		}
	}
	if ( $ctx['blog_posts'] ) {
		array_unshift( $features, 'Blog (' . wp_audit_num( $ctx['blog_posts'] ) . ' posts)' );
	}

	$integrations = array();
	foreach ( $ctx['forms'] as $form ) {
		foreach ( $form['integrations'] as $integration ) {
			$integrations[ 'Form integration: ' . $integration ] = true;
		}
	}
	if ( empty( $ctx['homepage']['error'] ) ) {
		foreach ( $ctx['homepage']['services'] as $service ) {
			$integrations[ $service ] = true;
		}
	}
	foreach ( array( 'Email & marketing', 'Analytics & tracking', 'Social' ) as $category ) {
		foreach ( array_keys( $ctx['plugin_categories'], $category, true ) as $file ) {
			$integrations[ $ctx['all_plugins'][ $file ]['Name'] ] = true;
		}
	}

	$editors = wp_audit_editor_summary( $ctx['scan'] );
	$words   = array_sum( $ctx['scan']['words'] );
	$cpts    = array();
	foreach ( $ctx['cpt_counts'] as $type => $count ) {
		$cpts[] = $type . ': ' . wp_audit_num( $count );
	}

	$users = array();
	foreach ( $ctx['users']['avail_roles'] as $role => $count ) {
		if ( 'none' !== $role ) {
			$users[] = $role . ': ' . wp_audit_num( $count );
		}
	}

	return array(
		'id'      => 'rfq',
		'title'   => 'Scoping Summary (RFQ)',
		'icon'    => 'dashicons-clipboard',
		'summary' => 'The facts a developer or agency needs to estimate a redesign or rebuild, followed by the questions this report cannot answer.',
		'blocks'  => array(
			array(
				'type' => 'facts',
				'rows' => array(
					wp_audit_fact( 'Functionality to carry over', $features ? implode( '; ', $features ) : 'Brochure site — no advanced features detected' ),
					wp_audit_fact( 'Content to migrate', sprintf( '%s pages, %s blog posts, %s custom items, %s media files', wp_audit_num( $ctx['page_count'] ), wp_audit_num( $ctx['blog_posts'] ), wp_audit_num( array_sum( $ctx['cpt_counts'] ) ), wp_audit_num( $ctx['total_media'] ) ) ),
					wp_audit_fact( 'Approximate word count', wp_audit_num( $words ) . ' words across ' . wp_audit_num( $ctx['scan']['scanned'] ) . ' scanned items' ),
					wp_audit_fact( 'Custom post types', $cpts ? implode( ', ', $cpts ) : 'None' ),
					wp_audit_fact( 'How content is built', $editors ? implode( ', ', array_keys( $editors ) ) : '—' ),
					wp_audit_fact( 'Page templates', count( $ctx['theme']->get_page_templates() ) . ' custom templates in theme' ),
					wp_audit_fact( 'Forms', count( $ctx['forms'] ) . ' forms' ),
					wp_audit_fact( 'Integrations & third-party services', $integrations ? implode( ', ', array_keys( $integrations ) ) : 'None detected' ),
					wp_audit_fact( 'Users to train', $users ? implode( ', ', $users ) : '—' ),
					wp_audit_fact( 'Active plugins', count( $ctx['active_plugins'] ) . ' (see Plugins section for categories)' ),
					wp_audit_fact( 'Hosting', wp_audit_detect_hosting( $ctx['headers'] ) . ', PHP ' . PHP_VERSION . ', ' . WP_MEMORY_LIMIT . ' memory, ' . wp_audit_bytes( wp_max_upload_size() ) . ' uploads' ),
				),
			),
			array(
				'type'  => 'list',
				'title' => 'Open questions for stakeholders (not answerable from the site itself)',
				'items' => array(
					'Budget range and target launch date.',
					'Design direction: full redesign, refresh, or keep the current look?',
					'Which existing features and plugins must be kept, replaced or dropped?',
					'Should all content migrate, or is this a chance to prune? (See word counts and the page inventory.)',
					'Will the URL structure change? If so, who owns the redirect map?',
					'Accessibility target (e.g. WCAG 2.2 AA) and any legal requirements.',
					'Languages required now and in future.',
					'Who will edit the site after launch, and what training do they need?',
					'Hosting: stay, or move? Who manages backups, updates and uptime monitoring?',
					'Analytics, tag management and consent requirements.',
					'Post-launch support: update cadence, response times, retainer.',
				),
			),
		),
	);
}

/**
 * Technical debt and cleanup tasks.
 */
function wp_audit_section_cleanup( array $ctx ) {
	$scan     = $ctx['scan'];
	$findings = array();
	$tasks    = array();

	$unregistered = array_filter(
		$scan['shortcodes'],
		function ( $data ) {
			return ! $data['registered'] && $data['shortcode_like'];
		}
	);
	$bracketed_text = array_keys(
		array_filter(
			$scan['shortcodes'],
			function ( $data ) {
				return ! $data['registered'] && ! $data['shortcode_like'];
			}
		)
	);
	$shortcode_rows = array();
	foreach ( $unregistered as $tag => $data ) {
		$shortcode_rows[] = array( '[' . $tag . ']', wp_audit_cell( wp_audit_num( $data['posts'] ), 'bad' ), wp_audit_num( $data['occurrences'] ), wp_audit_cell( $data['example_title'] . ' (ID ' . $data['example_id'] . ')', '', get_edit_post_link( $data['example_id'], 'raw' ) ) );
	}
	if ( $unregistered ) {
		$affected = array_sum( wp_list_pluck( $unregistered, 'posts' ) );
		$findings[] = wp_audit_finding( 'bad', sprintf( '%d shortcode tag(s) appear in published content but nothing registers them (%s), affecting about %s items. Visitors see the raw [tag] text.', count( $unregistered ), implode( ', ', array_map( function ( $tag ) {
			return '[' . $tag . ']';
		}, array_keys( $unregistered ) ) ), wp_audit_num( $affected ) ) );
		$tasks[] = 'Remove or replace unregistered shortcodes (' . implode( ', ', array_keys( $unregistered ) ) . ').';
	}

	$style_rows = array();
	foreach ( $scan['inline_styles'] as $post ) {
		$style_rows[] = array(
			wp_audit_cell( $post['title'] . ' (ID ' . $post['id'] . ')', '', get_edit_post_link( $post['id'], 'raw' ) ),
			$post['type'],
			wp_audit_cell( $post['style_count'], 'warning' ),
			$post['has_style_tag'] ? wp_audit_cell( 'Yes', 'bad' ) : 'No',
		);
	}
	if ( $style_rows ) {
		$findings[] = wp_audit_finding( 'warning', sprintf( '%d published items contain heavy hand-written inline styling (%d+ style attributes or an embedded <style> block).', count( $style_rows ), wp_audit_setting( 'inline_style_limit' ) ) );
		$tasks[]    = 'Move hard-coded inline styles from ' . count( $style_rows ) . ' items into theme CSS.';
	}

	if ( count( $ctx['inactive_plugins'] ) ) {
		$tasks[] = 'Delete ' . count( $ctx['inactive_plugins'] ) . ' inactive plugins.';
	}
	foreach ( $ctx['post_counts'] as $type => $counts ) {
		if ( ! post_type_exists( $type ) ) {
			$tasks[] = sprintf( 'Decide what to do with %s items in the unregistered "%s" post type.', wp_audit_num( array_sum( $counts ) ), $type );
		}
	}
	if ( $ctx['db']['orphaned_postmeta'] ) {
		$tasks[] = 'Delete ' . wp_audit_num( $ctx['db']['orphaned_postmeta'] ) . ' orphaned postmeta rows.';
	}
	$revisions = wp_audit_count_status( $ctx['post_counts'], 'revision', 'inherit' );
	if ( $revisions > 1000 ) {
		$tasks[] = 'Prune ' . wp_audit_num( $revisions ) . ' post revisions and set WP_POST_REVISIONS.';
	}

	return array(
		'id'       => 'cleanup',
		'title'    => 'Technical Debt & Cleanup',
		'icon'     => 'dashicons-trash',
		'summary'  => 'Broken or legacy content and leftover data that should be fixed before (or during) a migration.',
		'findings' => $findings,
		'blocks'   => array(
			array(
				'type'  => 'list',
				'title' => 'Suggested cleanup tasks',
				'items' => $tasks,
				'empty' => 'No cleanup tasks identified.',
			),
			array(
				'type'    => 'table',
				'title'   => 'Unregistered shortcodes (broken content)',
				'columns' => array( 'Shortcode', 'Items Affected', 'Occurrences', 'Example' ),
				'rows'    => $shortcode_rows,
				'empty'   => 'No unregistered shortcodes found in the published content scanned.',
				'note'    => 'Plugins that only register shortcodes on the front end can appear here as false positives.' . ( $bracketed_text ? ' Also seen, but probably plain bracketed text rather than shortcodes: ' . implode( ', ', array_map( function ( $tag ) {
					return '[' . $tag . ']';
				}, $bracketed_text ) ) . '.' : '' ),
			),
			array(
				'type'      => 'table',
				'title'     => 'Hand-written inline styling',
				'columns'   => array( 'Item', 'Type', 'style="" Attributes', '<style> Block' ),
				'rows'      => $style_rows,
				'empty'     => 'No items with heavy hand-written inline styling.',
				'collapsed' => count( $style_rows ) > 20,
				'note'      => 'Only classic content and Custom HTML blocks are counted; styles generated by block settings are ignored.',
			),
		),
	);
}
