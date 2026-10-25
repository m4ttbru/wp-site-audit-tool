<?php
/**
 * Data collectors. Each returns plain arrays (cached where the query is heavy)
 * so the same data can feed the HTML report, Markdown/JSON export and WP-CLI.
 *
 * @package WP_Site_Audit_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ==========================================================================
   Content counts
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
 * Sum of one status across every post type.
 */
function wp_audit_count_status_all( array $counts, $status ) {
	$total = 0;
	foreach ( $counts as $statuses ) {
		$total += isset( $statuses[ $status ] ) ? $statuses[ $status ] : 0;
	}
	return $total;
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

/* ==========================================================================
   Content scan (editors, blocks, shortcodes, embeds, word counts, inline styles)
   ========================================================================== */

/**
 * Post meta that marks a post as built with a page builder: meta_key => [ label, required value or null for "any non-empty" ].
 */
function wp_audit_builder_meta_keys() {
	return apply_filters(
		'wp_audit_builder_meta_keys',
		array(
			'_elementor_edit_mode'   => array( 'Elementor', 'builder' ),
			'_fl_builder_enabled'    => array( 'Beaver Builder', '1' ),
			'_et_pb_use_builder'     => array( 'Divi', 'on' ),
			'_bricks_page_content_2' => array( 'Bricks', null ),
			'ct_builder_shortcodes'  => array( 'Oxygen', null ),
			'_ct_builder_json'       => array( 'Oxygen', null ),
		)
	);
}

/**
 * One pass over published content that powers several sections at once.
 *
 * Reading post_content is the expensive part, so everything that needs it is
 * gathered here in a single batched loop and cached for an hour. Pages are
 * read first so the page inventory is complete even on very large sites.
 */
function wp_audit_scan_content() {
	$limit       = wp_audit_setting( 'content_scan_limit' );
	$style_limit = wp_audit_setting( 'inline_style_limit' );

	return wp_audit_remember(
		'content_scan_' . $limit . '_' . $style_limit,
		HOUR_IN_SECONDS,
		function () use ( $limit, $style_limit ) {
			global $wpdb;

			$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
			if ( empty( $types ) ) {
				$types = array( 'post', 'page' );
			}

			// Builder flags come from post meta; LEFT() keeps huge builder JSON out of memory.
			$builder_keys = wp_audit_builder_meta_keys();
			$key_in       = implode( ',', array_fill( 0, count( $builder_keys ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders -- generated %s list.
			$meta_rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, LEFT(meta_value, 20) AS meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ($key_in)", array_keys( $builder_keys ) ) );
			$builders  = array();
			foreach ( $meta_rows as $row ) {
				list( $label, $required ) = $builder_keys[ $row->meta_key ];
				$matches = null === $required ? '' !== trim( (string) $row->meta_value ) : $required === $row->meta_value;
				if ( $matches ) {
					$builders[ (int) $row->post_id ] = $label;
				}
			}

			$result = array(
				'limit'         => $limit,
				'scanned'       => 0,
				'scanned_types' => array(),
				'editors'       => array(),
				'words'         => array(),
				'blocks'        => array(),
				'shortcodes'    => array(),
				'embeds'        => array(),
				'inline_styles' => array(),
				'pages'         => array(),
			);

			$type_in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$batch   = 200;
			$offset  = 0;

			while ( $offset < $limit ) {
				$args = array_merge( $types, array( min( $batch, $limit - $offset ), $offset ) );
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders -- generated %s list.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($type_in) ORDER BY (post_type = 'page') DESC, post_modified DESC LIMIT %d OFFSET %d", $args ) );
				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $post ) {
					wp_audit_scan_post( $post, $builders, $style_limit, $result );
				}

				$offset += count( $rows );
				if ( count( $rows ) < $batch ) {
					break;
				}
			}

			foreach ( $result['shortcodes'] as $tag => $data ) {
				$result['shortcodes'][ $tag ]['registered'] = shortcode_exists( $tag );
				// Seen as a real shortcode by more than a handful of items is evidence enough on its own.
				if ( $data['posts'] >= 5 && strtolower( $tag ) === $tag ) {
					$result['shortcodes'][ $tag ]['shortcode_like'] = true;
				}
			}

			$by_posts = function ( $a, $b ) {
				return $b['posts'] - $a['posts'];
			};
			uasort( $result['blocks'], $by_posts );
			uasort( $result['shortcodes'], $by_posts );
			arsort( $result['embeds'] );

			return $result;
		}
	);
}

/**
 * Accumulate one post into the content scan result.
 */
function wp_audit_scan_post( $post, array $builders, $style_limit, array &$result ) {
	$content = (string) $post->post_content;
	$type    = $post->post_type;
	$id      = (int) $post->ID;

	$result['scanned']++;
	$result['scanned_types'][ $type ] = ( isset( $result['scanned_types'][ $type ] ) ? $result['scanned_types'][ $type ] : 0 ) + 1;

	// --- Editor / builder ---
	if ( isset( $builders[ $id ] ) ) {
		$editor = $builders[ $id ];
	} elseif ( false !== strpos( $content, '[vc_row' ) ) {
		$editor = 'WPBakery';
	} elseif ( false !== strpos( $content, '[et_pb_section' ) ) {
		$editor = 'Divi';
	} elseif ( has_blocks( $content ) ) {
		$editor = 'Block editor';
	} elseif ( '' === trim( $content ) ) {
		$editor = 'Empty';
	} else {
		$editor = 'Classic editor';
	}
	$result['editors'][ $type ][ $editor ] = ( isset( $result['editors'][ $type ][ $editor ] ) ? $result['editors'][ $type ][ $editor ] : 0 ) + 1;

	// --- Word count (shortcode tags and markup removed) ---
	$text  = wp_strip_all_tags( preg_replace( '/\[\/?[a-zA-Z][^\]]*\]/', ' ', $content ) );
	$words = str_word_count( html_entity_decode( $text, ENT_QUOTES ) );
	$result['words'][ $type ] = ( isset( $result['words'][ $type ] ) ? $result['words'][ $type ] : 0 ) + $words;

	if ( 'page' === $type ) {
		$result['pages'][ $id ] = array(
			'editor' => $editor,
			'words'  => $words,
		);
	}

	// --- Blocks ---
	if ( preg_match_all( '/<!--\s+wp:([a-z][a-z0-9_-]*\/)?([a-z][a-z0-9_-]*)/', $content, $matches, PREG_SET_ORDER ) ) {
		$seen = array();
		foreach ( $matches as $match ) {
			$name = ( $match[1] ? $match[1] : 'core/' ) . $match[2];
			if ( ! isset( $result['blocks'][ $name ] ) ) {
				$result['blocks'][ $name ] = array( 'posts' => 0, 'occurrences' => 0 );
			}
			$result['blocks'][ $name ]['occurrences']++;
			if ( ! isset( $seen[ $name ] ) ) {
				$seen[ $name ] = true;
				$result['blocks'][ $name ]['posts']++;
			}
		}
	}

	// --- Shortcodes: "[tag]", "[tag attr=...]" or "[tag/]" — not closing tags or escaped "[[tag]]" ---
	if ( false !== strpos( $content, '[' ) && preg_match_all( '/(?<!\[)\[([a-zA-Z][\w-]*)(\s[^\]]*)?\/?\]/', $content, $matches, PREG_SET_ORDER ) ) {
		$tags = array();
		foreach ( $matches as $match ) {
			$tag = $match[1];
			if ( ! isset( $tags[ $tag ] ) ) {
				$tags[ $tag ] = array( 'count' => 0, 'attrs' => false );
			}
			$tags[ $tag ]['count']++;
			if ( isset( $match[2] ) && false !== strpos( $match[2], '=' ) ) {
				$tags[ $tag ]['attrs'] = true;
			}
		}

		foreach ( $tags as $tag => $data ) {
			if ( ! isset( $result['shortcodes'][ $tag ] ) ) {
				$result['shortcodes'][ $tag ] = array(
					'posts'         => 0,
					'occurrences'   => 0,
					'example_id'    => $id,
					'example_title' => $post->post_title,
					'shortcode_like' => (bool) preg_match( '/[_\-\d]/', $tag ),
				);
			}
			$result['shortcodes'][ $tag ]['posts']++;
			$result['shortcodes'][ $tag ]['occurrences'] += $data['count'];

			// Bracketed prose ("[Skip]", "[adults]") never has attributes or a closing tag; real shortcodes often do.
			if ( $data['attrs'] || false !== strpos( $content, '[/' . $tag . ']' ) ) {
				$result['shortcodes'][ $tag ]['shortcode_like'] = true;
			}
		}
	}

	// --- Embeds: iframe hosts and embed-block providers ---
	if ( preg_match_all( '/<iframe[^>]+src=["\']([^"\']+)/i', $content, $matches ) ) {
		foreach ( $matches[1] as $src ) {
			$host = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
			if ( $host ) {
				$host                       = preg_replace( '/^www\./', '', $host );
				$result['embeds'][ $host ] = ( isset( $result['embeds'][ $host ] ) ? $result['embeds'][ $host ] : 0 ) + 1;
			}
		}
	}
	if ( preg_match_all( '/"providerNameSlug":"([a-z0-9-]+)"/', $content, $matches ) ) {
		foreach ( $matches[1] as $provider ) {
			$key                       = $provider . ' (embed block)';
			$result['embeds'][ $key ] = ( isset( $result['embeds'][ $key ] ) ? $result['embeds'][ $key ] : 0 ) + 1;
		}
	}

	// --- Hand-written inline styles ---
	if ( false !== stripos( $content, 'style' ) && count( $result['inline_styles'] ) < 200 ) {
		$html          = wp_audit_get_hand_written_html( $content );
		$has_style_tag = false !== stripos( $html, '<style' );
		$style_count   = preg_match_all( '/\sstyle\s*=\s*[\'"]/i', $html );

		// An embedded <style> block is a much bigger smell than a few attributes, so it counts heavily.
		if ( $style_count + ( $has_style_tag ? 50 : 0 ) >= $style_limit ) {
			$result['inline_styles'][] = array(
				'id'            => $id,
				'title'         => $post->post_title,
				'type'          => $type,
				'style_count'   => $style_count,
				'has_style_tag' => $has_style_tag,
			);
		}
	}
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

/* ==========================================================================
   Pages
   ========================================================================== */

/**
 * Every page (published, private, draft, pending, scheduled) in hierarchy order with path and template.
 * Returns [ 'pages' => [ id, title, path, depth, status, template, modified ], 'total' => int, 'limit' => int, 'max_depth' => int ].
 */
function wp_audit_get_page_inventory() {
	$limit = wp_audit_setting( 'page_inventory_limit' );

	return wp_audit_remember(
		'page_inventory_' . $limit,
		15 * MINUTE_IN_SECONDS,
		function () use ( $limit ) {
			global $wpdb;

			$rows = $wpdb->get_results(
				"SELECT p.ID, p.post_title, p.post_name, p.post_parent, p.post_status, p.post_modified, pm.meta_value AS template
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_page_template'
				 WHERE p.post_type = 'page'
				   AND p.post_status IN ('publish', 'private', 'draft', 'pending', 'future')
				 ORDER BY p.menu_order ASC, p.post_title ASC"
			);

			$by_id    = array();
			$children = array();
			foreach ( $rows as $row ) {
				$by_id[ (int) $row->ID ] = $row;
			}
			foreach ( $rows as $row ) {
				// Pages whose parent is missing/trashed are treated as top level.
				$parent                = isset( $by_id[ (int) $row->post_parent ] ) ? (int) $row->post_parent : 0;
				$children[ $parent ][] = (int) $row->ID;
			}

			$pages     = array();
			$max_depth = 0;
			$visited   = array();

			// Depth-first walk so children appear directly under their parent.
			$walk = function ( $parent_id, $depth, $parent_path ) use ( &$walk, &$pages, &$max_depth, &$visited, $children, $by_id ) {
				if ( empty( $children[ $parent_id ] ) ) {
					return;
				}
				foreach ( $children[ $parent_id ] as $id ) {
					if ( isset( $visited[ $id ] ) ) {
						continue; // Guard against circular parent references.
					}
					$visited[ $id ] = true;

					$row       = $by_id[ $id ];
					$slug      = '' !== $row->post_name ? urldecode( $row->post_name ) : '(no slug)';
					$path      = $parent_path . '/' . $slug;
					$max_depth = max( $max_depth, $depth );

					$pages[] = array(
						'id'       => $id,
						'title'    => '' !== $row->post_title ? $row->post_title : '(no title)',
						'path'     => $path . '/',
						'depth'    => $depth,
						'status'   => $row->post_status,
						'template' => $row->template ? $row->template : 'default',
						'modified' => substr( $row->post_modified, 0, 10 ),
					);
					$walk( $id, $depth + 1, $path );
				}
			};
			$walk( 0, 0, '' );

			return array(
				'pages'     => array_slice( $pages, 0, $limit ),
				'total'     => count( $pages ),
				'limit'     => $limit,
				'max_depth' => $max_depth,
			);
		}
	);
}

/* ==========================================================================
   Navigation, widgets, theme
   ========================================================================== */

/**
 * Menus with their locations and full item trees.
 */
function wp_audit_get_menus() {
	$locations = get_registered_nav_menus();
	$assigned  = get_nav_menu_locations();
	$menus     = array();

	foreach ( wp_get_nav_menus() as $menu ) {
		$items  = (array) wp_get_nav_menu_items( $menu->term_id, array( 'update_post_term_cache' => false ) );
		$depths = array();
		$lines  = array();

		// Items come back in display order, so each parent is seen before its children.
		foreach ( $items as $item ) {
			$parent              = (int) $item->menu_item_parent;
			$depth               = ( $parent && isset( $depths[ $parent ] ) ) ? $depths[ $parent ] + 1 : 0;
			$depths[ $item->ID ] = $depth;

			$url = (string) $item->url;
			if ( 0 === strpos( $url, home_url() ) ) {
				$url = wp_make_link_relative( $url );
			}
			$lines[] = array(
				'text'  => sprintf( '%s — %s (%s)', wp_strip_all_tags( $item->title ), $url ? $url : '#', $item->type_label ),
				'depth' => $depth,
			);
		}

		$menu_locations = array();
		foreach ( $assigned as $location => $menu_id ) {
			if ( (int) $menu_id === (int) $menu->term_id ) {
				$menu_locations[] = isset( $locations[ $location ] ) ? $locations[ $location ] : $location;
			}
		}

		$menus[] = array(
			'name'      => $menu->name,
			'locations' => $menu_locations,
			'count'     => count( $items ),
			'items'     => $lines,
		);
	}

	return array(
		'locations' => $locations,
		'assigned'  => $assigned,
		'menus'     => $menus,
	);
}

/**
 * Registered widget areas and the widgets in each.
 */
function wp_audit_get_widget_areas() {
	global $wp_registered_sidebars;

	$sidebars = wp_get_sidebars_widgets();
	$areas    = array();

	foreach ( (array) $wp_registered_sidebars as $id => $sidebar ) {
		$widgets = isset( $sidebars[ $id ] ) ? (array) $sidebars[ $id ] : array();
		// Widget IDs look like "text-3" / "block-12"; the base names say what kind they are.
		$types   = array_count_values(
			array_map(
				function ( $widget_id ) {
					return preg_replace( '/-\d+$/', '', $widget_id );
				},
				$widgets
			)
		);
		$areas[] = array(
			'id'    => $id,
			'name'  => $sidebar['name'],
			'count' => count( $widgets ),
			'types' => $types,
		);
	}

	return array(
		'areas'    => $areas,
		'inactive' => isset( $sidebars['wp_inactive_widgets'] ) ? count( (array) $sidebars['wp_inactive_widgets'] ) : 0,
	);
}

/**
 * Theme details: identity, supports, templates and customizations.
 */
function wp_audit_get_theme_details() {
	$theme  = wp_get_theme();
	$parent = $theme->parent();

	$supports = array();
	foreach ( array( 'post-thumbnails', 'custom-logo', 'menus', 'widgets', 'title-tag', 'html5', 'editor-styles', 'wp-block-styles', 'align-wide', 'responsive-embeds', 'custom-header', 'custom-background', 'block-templates', 'block-template-parts', 'editor-color-palette', 'editor-font-sizes', 'appearance-tools', 'woocommerce' ) as $feature ) {
		if ( current_theme_supports( $feature ) ) {
			$supports[] = $feature;
		}
	}

	$templates = array();
	$parts     = array();
	if ( function_exists( 'get_block_templates' ) && ( $theme->is_block_theme() || current_theme_supports( 'block-templates' ) ) ) {
		foreach ( get_block_templates( array(), 'wp_template' ) as $template ) {
			$templates[] = array(
				'slug'   => $template->slug,
				'title'  => $template->title,
				'source' => $template->source,
				'custom' => (bool) $template->is_custom,
			);
		}
		foreach ( get_block_templates( array(), 'wp_template_part' ) as $part ) {
			$parts[] = array(
				'slug'   => $part->slug,
				'title'  => $part->title,
				'area'   => isset( $part->area ) ? $part->area : '',
				'source' => $part->source,
			);
		}
	}

	// Classic themes: the PHP files (root + one level) show the template hierarchy in use.
	$php_files = array();
	if ( ! $theme->is_block_theme() ) {
		$php_files = array_keys( $theme->get_files( 'php', 1, true ) );
		sort( $php_files );
	}

	return array(
		'name'             => $theme->get( 'Name' ),
		'version'          => $theme->get( 'Version' ),
		'author'           => wp_strip_all_tags( $theme->get( 'Author' ) ),
		'stylesheet'       => $theme->get_stylesheet(),
		'is_block'         => $theme->is_block_theme(),
		'parent'           => $parent ? $parent->get( 'Name' ) . ' ' . $parent->get( 'Version' ) : '',
		'theme_json'       => function_exists( 'wp_theme_has_theme_json' ) && wp_theme_has_theme_json(),
		'requires_wp'      => $theme->get( 'RequiresWP' ),
		'requires_php'     => $theme->get( 'RequiresPHP' ),
		'supports'         => $supports,
		'custom_css_bytes' => strlen( (string) wp_get_custom_css() ),
		'theme_mods'       => count( (array) get_theme_mods() ),
		'templates'        => $templates,
		'parts'            => $parts,
		'php_files'        => array_slice( $php_files, 0, 150 ),
		'php_file_count'   => count( $php_files ),
	);
}

/* ==========================================================================
   Custom fields (ACF and friends)
   ========================================================================== */

/**
 * ACF field groups with every field, location rules and storage. Null if ACF isn't active.
 */
function wp_audit_get_acf_details() {
	if ( ! function_exists( 'acf_get_field_groups' ) ) {
		return null;
	}

	return wp_audit_remember(
		'acf',
		15 * MINUTE_IN_SECONDS,
		function () {
			$groups = array();
			foreach ( acf_get_field_groups() as $group ) {
				$lines = array();
				$types = array();
				wp_audit_acf_field_lines( (array) acf_get_fields( $group ), 0, $lines, $types );
				arsort( $types );

				$type_summary = array();
				foreach ( $types as $type => $count ) {
					$type_summary[] = $type . ' ×' . $count;
				}

				$groups[] = array(
					'title'    => $group['title'],
					'key'      => $group['key'],
					'active'   => ! empty( $group['active'] ),
					'fields'   => count( $lines ),
					'types'    => implode( ', ', $type_summary ),
					'location' => wp_audit_acf_location_summary( isset( $group['location'] ) ? $group['location'] : array() ),
					'storage'  => ! empty( $group['local'] ) ? ( 'json' === $group['local'] ? 'Local JSON' : 'PHP' ) : 'Database',
					'lines'    => array_slice( $lines, 0, 300 ),
				);
			}

			$options_pages = array();
			if ( function_exists( 'acf_get_options_pages' ) ) {
				foreach ( (array) acf_get_options_pages() as $page ) {
					$options_pages[] = $page['page_title'] . ' (' . $page['menu_slug'] . ')';
				}
			}

			return array(
				'version'       => defined( 'ACF_VERSION' ) ? ACF_VERSION : '',
				'pro'           => defined( 'ACF_PRO' ) && ACF_PRO,
				'groups'        => $groups,
				'options_pages' => $options_pages,
			);
		}
	);
}

/**
 * Flatten ACF fields (including repeater/group sub fields and flexible content layouts) into indented lines.
 */
function wp_audit_acf_field_lines( array $fields, $depth, array &$lines, array &$types ) {
	foreach ( $fields as $field ) {
		$type           = isset( $field['type'] ) ? $field['type'] : 'unknown';
		$types[ $type ] = ( isset( $types[ $type ] ) ? $types[ $type ] : 0 ) + 1;
		$lines[]        = array(
			'text'  => sprintf( '%s (%s, %s)', ! empty( $field['label'] ) ? $field['label'] : '(no label)', isset( $field['name'] ) ? $field['name'] : '', $type ),
			'depth' => $depth,
		);

		if ( ! empty( $field['sub_fields'] ) ) {
			wp_audit_acf_field_lines( $field['sub_fields'], $depth + 1, $lines, $types );
		}
		if ( ! empty( $field['layouts'] ) ) {
			foreach ( $field['layouts'] as $layout ) {
				$lines[] = array(
					'text'  => 'Layout: ' . ( isset( $layout['label'] ) ? $layout['label'] : $layout['name'] ),
					'depth' => $depth + 1,
				);
				if ( ! empty( $layout['sub_fields'] ) ) {
					wp_audit_acf_field_lines( $layout['sub_fields'], $depth + 2, $lines, $types );
				}
			}
		}
	}
}

/**
 * Human-readable ACF location rules: OR-groups of AND-rules.
 */
function wp_audit_acf_location_summary( $location ) {
	$or = array();
	foreach ( (array) $location as $group ) {
		$and = array();
		foreach ( (array) $group as $rule ) {
			if ( isset( $rule['param'], $rule['operator'], $rule['value'] ) ) {
				$and[] = $rule['param'] . ' ' . $rule['operator'] . ' ' . $rule['value'];
			}
		}
		if ( $and ) {
			$or[] = implode( ' AND ', $and );
		}
	}
	return $or ? implode( ' OR ', $or ) : '—';
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
				 LIMIT 50",
				ARRAY_A
			);
		}
	);
}

/* ==========================================================================
   Forms
   ========================================================================== */

/**
 * Every form across supported form plugins, with fields, entries, features and integrations.
 * Returns a list of [ plugin, id, title, active, fields, entries, features[], integrations[] ].
 */
function wp_audit_get_forms_inventory() {
	return wp_audit_remember(
		'forms_inventory',
		15 * MINUTE_IN_SECONDS,
		function () {
			global $wpdb;
			$forms = array();

			// --- Gravity Forms ---
			if ( class_exists( 'GFAPI' ) && class_exists( 'GFFormsModel' ) ) {
				$entries = array();
				$entry_table = GFFormsModel::get_entry_table_name();
				if ( wp_audit_table_exists( $entry_table ) ) {
					$entries = array_map( 'intval', wp_list_pluck( $wpdb->get_results( "SELECT form_id, COUNT(*) AS total FROM {$entry_table} WHERE status = 'active' GROUP BY form_id", ARRAY_A ), 'total', 'form_id' ) );
				}

				// Add-on feeds are the form's integrations (Mailchimp, Stripe, HubSpot, Zapier...).
				$feeds      = array();
				$feed_table = $wpdb->prefix . 'gf_addon_feed';
				if ( wp_audit_table_exists( $feed_table ) ) {
					foreach ( $wpdb->get_results( "SELECT form_id, addon_slug FROM {$feed_table} WHERE is_active = 1", ARRAY_A ) as $feed ) {
						$feeds[ (int) $feed['form_id'] ][ $feed['addon_slug'] ] = true;
					}
				}

				$all = array_merge( (array) GFAPI::get_forms( true, false ), (array) GFAPI::get_forms( false, false ) );
				foreach ( $all as $form ) {
					$features = array();
					$types    = array();
					foreach ( (array) $form['fields'] as $field ) {
						$field_type = is_object( $field ) ? $field->type : ( isset( $field['type'] ) ? $field['type'] : '' );
						$types[]    = $field_type;
						$logic      = is_object( $field ) ? $field->conditionalLogic : ( isset( $field['conditionalLogic'] ) ? $field['conditionalLogic'] : null );
						if ( ! empty( $logic ) ) {
							$features['Conditional logic'] = true;
						}
					}
					if ( in_array( 'page', $types, true ) ) {
						$features['Multi-page'] = true;
					}
					if ( in_array( 'fileupload', $types, true ) ) {
						$features['File upload'] = true;
					}
					if ( array_intersect( array( 'product', 'total', 'creditcard', 'stripe_creditcard', 'square_creditcard', 'paypal_checkout' ), $types ) ) {
						$features['Payment'] = true;
					}
					if ( array_intersect( array( 'captcha', 'turnstile' ), $types ) ) {
						$features['CAPTCHA'] = true;
					}

					$id      = (int) $form['id'];
					$forms[] = array(
						'plugin'        => 'Gravity Forms',
						'id'            => $id,
						'title'         => $form['title'],
						'active'        => ! empty( $form['is_active'] ),
						'fields'        => count( (array) $form['fields'] ),
						'entries'       => isset( $entries[ $id ] ) ? $entries[ $id ] : 0,
						'notifications' => isset( $form['notifications'] ) ? count( (array) $form['notifications'] ) : null,
						'features'      => array_keys( $features ),
						'integrations'  => isset( $feeds[ $id ] ) ? array_keys( $feeds[ $id ] ) : array(),
					);
				}
			}

			// --- Contact Form 7 ---
			if ( class_exists( 'WPCF7_ContactForm' ) ) {
				foreach ( get_posts( array( 'post_type' => 'wpcf7_contact_form', 'post_status' => 'any', 'numberposts' => -1, 'no_found_rows' => true ) ) as $post ) {
					$markup = (string) get_post_meta( $post->ID, '_form', true );
					preg_match_all( '/\[([a-z_]+)\*?\s/', $markup, $tags );
					$types  = array_diff( $tags[1], array( 'submit' ) );
					$forms[] = array(
						'plugin'        => 'Contact Form 7',
						'id'            => $post->ID,
						'title'         => $post->post_title,
						'active'        => 'publish' === $post->post_status,
						'fields'        => count( $types ),
						'entries'       => null, // CF7 doesn't store submissions (Flamingo does, separately).
						'notifications' => null,
						'features'      => in_array( 'file', $types, true ) ? array( 'File upload' ) : array(),
						'integrations'  => array(),
					);
				}
			}

			// --- WPForms ---
			if ( function_exists( 'wpforms' ) ) {
				foreach ( get_posts( array( 'post_type' => 'wpforms', 'post_status' => 'any', 'numberposts' => -1, 'no_found_rows' => true ) ) as $post ) {
					$data   = json_decode( $post->post_content, true );
					$fields = isset( $data['fields'] ) ? (array) $data['fields'] : array();
					$types  = wp_list_pluck( $fields, 'type' );
					$features = array();
					if ( in_array( 'pagebreak', $types, true ) ) {
						$features[] = 'Multi-page';
					}
					if ( in_array( 'file-upload', $types, true ) ) {
						$features[] = 'File upload';
					}
					if ( array_intersect( array( 'payment-single', 'payment-total', 'stripe-credit-card', 'paypal-commerce' ), $types ) ) {
						$features[] = 'Payment';
					}
					$forms[] = array(
						'plugin'        => 'WPForms',
						'id'            => $post->ID,
						'title'         => $post->post_title,
						'active'        => 'publish' === $post->post_status,
						'fields'        => count( $fields ),
						'entries'       => null,
						'notifications' => null,
						'features'      => $features,
						'integrations'  => array(),
					);
				}
			}

			// --- Formidable (own tables, not posts) ---
			if ( class_exists( 'FrmForm' ) && wp_audit_table_exists( $wpdb->prefix . 'frm_forms' ) ) {
				$field_counts = array();
				if ( wp_audit_table_exists( $wpdb->prefix . 'frm_fields' ) ) {
					$field_counts = array_map( 'intval', wp_list_pluck( $wpdb->get_results( "SELECT form_id, COUNT(*) AS total FROM {$wpdb->prefix}frm_fields GROUP BY form_id", ARRAY_A ), 'total', 'form_id' ) );
				}
				$entry_counts = array();
				if ( wp_audit_table_exists( $wpdb->prefix . 'frm_items' ) ) {
					$entry_counts = array_map( 'intval', wp_list_pluck( $wpdb->get_results( "SELECT form_id, COUNT(*) AS total FROM {$wpdb->prefix}frm_items GROUP BY form_id", ARRAY_A ), 'total', 'form_id' ) );
				}
				$rows = $wpdb->get_results( "SELECT id, name, status FROM {$wpdb->prefix}frm_forms WHERE is_template = 0 AND ( parent_form_id = 0 OR parent_form_id IS NULL ) AND status != 'trash'", ARRAY_A );
				foreach ( $rows as $row ) {
					$id      = (int) $row['id'];
					$forms[] = array(
						'plugin'        => 'Formidable Forms',
						'id'            => $id,
						'title'         => $row['name'],
						'active'        => 'published' === $row['status'],
						'fields'        => isset( $field_counts[ $id ] ) ? $field_counts[ $id ] : 0,
						'entries'       => isset( $entry_counts[ $id ] ) ? $entry_counts[ $id ] : 0,
						'notifications' => null,
						'features'      => array(),
						'integrations'  => array(),
					);
				}
			}

			return $forms;
		}
	);
}

/* ==========================================================================
   Plugins
   ========================================================================== */

/**
 * Rough functional category for a plugin, from its name first and description second.
 * Lets a reader (or LLM) see what each plugin is for without knowing every plugin by name.
 */
function wp_audit_plugin_category( $name, $description = '' ) {
	$rules = apply_filters(
		'wp_audit_plugin_categories',
		array(
			'Page builder'                  => '/elementor|beaver builder|\bdivi\b|wpbakery|oxygen|bricks|brizy|kadence blocks|spectra|generateblocks|stackable|otter blocks|visual composer/i',
			'Custom fields & content types' => '/custom fields|\bacf\b|meta box|\bpods\b|custom post type|cpt ui|toolset|posts 2 posts|relationship/i',
			'Forms'                         => '/\bforms?\b|gravity|contact form|wpforms|formidable|ninja forms|fluent ?forms/i',
			'SEO'                           => '/\bseo\b|yoast|rank math|sitemap|schema/i',
			'E-commerce'                    => '/woocommerce|e-?commerce|\bshop\b|\bcart\b|stripe|paypal|checkout|easy digital downloads/i',
			'Membership & LMS'              => '/member|subscription|restrict content|learndash|lifterlms|tutor lms|\bcourses?\b|\blms\b/i',
			'Events & booking'              => '/\bevents?\b|calendar|booking|appointment|reservation/i',
			'Multilingual'                  => '/wpml|polylang|translatepress|weglot|multilingual/i',
			'Search & filtering'            => '/search|facet|relevanssi|filter everything/i',
			'Caching & performance'         => '/cache|caching|rocket|optimi[sz]|minif|lazy ?load|perfmatters|autoptimize|speed|imagify|smush|ewww|shortpixel/i',
			'Security'                      => '/security|firewall|wordfence|sucuri|solid security|two.?factor|\b2fa\b|limit login|captcha|spam|akismet/i',
			'Backup & migration'            => '/backup|migrat|duplicator|updraft|clone|staging/i',
			'Analytics & tracking'          => '/analytics|site kit|tag manager|monsterinsights|pixel|tracking|\bgtm\b/i',
			'Email & marketing'             => '/smtp|\bmail|newsletter|mailchimp|hubspot|klaviyo|constant contact|campaign|crm/i',
			'Redirects & links'             => '/redirect|broken link|\b404\b|link manager/i',
			'Media & galleries'             => '/gallery|slider|\bimages?\b|\bmedia\b|\bsvg\b|video|lightbox|carousel|thumbnail/i',
			'Social'                        => '/social|\bshare|instagram|facebook|twitter|\bfeeds?\b/i',
			'Maps & location'               => '/\bmaps?\b|store locator|geolocation/i',
			'Compliance & accessibility'    => '/cookie|gdpr|privacy|consent|accessib/i',
			'Admin & utilities'             => '/\badmin\b|duplicate|classic editor|classic widgets|widget|cleaner|database|string locator|regenerate|replace|code snippets|user role|import|export|cron/i',
		)
	);

	foreach ( array( $name, $description ) as $text ) {
		foreach ( $rules as $category => $pattern ) {
			if ( '' !== $text && preg_match( $pattern, $text ) ) {
				return $category;
			}
		}
	}
	return 'Other';
}

/**
 * [ status, label ] for a plugin's update status, from core's update_plugins transient.
 */
function wp_audit_plugin_update_status( $plugin_file, $updates ) {
	if ( isset( $updates->response[ $plugin_file ]->new_version ) ) {
		return array( 'warning', 'Update available: ' . $updates->response[ $plugin_file ]->new_version );
	}
	if ( isset( $updates->no_update[ $plugin_file ] ) ) {
		return array( 'good', 'Up to date' );
	}
	return array( '', 'Not tracked (premium or custom)' );
}

/* ==========================================================================
   Database
   ========================================================================== */

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
				'size_mb'           => isset( $schema['size_mb'] ) ? (float) $schema['size_mb'] : 0,
				'table_count'       => isset( $schema['table_count'] ) ? (int) $schema['table_count'] : 0,
				'server'            => $wpdb->db_server_info(),
				'charset'           => $wpdb->charset,
				'prefix'            => $wpdb->prefix,
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
		'pmxe_'            => array( 'WP All Export', class_exists( 'PMXE_Plugin' ) ),
		'ewwwio_'          => array( 'EWWW Image Optimizer', defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' ) ),
		'imagify_'         => array( 'Imagify', defined( 'IMAGIFY_VERSION' ) ),
		'monsterinsights_' => array( 'MonsterInsights', defined( 'MONSTERINSIGHTS_VERSION' ) ),
		'nextend2_'        => array( 'Smart Slider 3', defined( 'NEXTEND_SMARTSLIDER_3' ) || class_exists( 'Nextend\SmartSlider3\SmartSlider3Info' ) ),
		'bwg_'             => array( 'Photo Gallery (10Web)', defined( 'WD_BWG_DIR' ) ),
		'relevanssi'       => array( 'Relevanssi', function_exists( 'relevanssi_search' ) ),
		'p2p'              => array( 'Posts 2 Posts', function_exists( 'p2p_register_connection_type' ) ),
		'cn_'              => array( 'Cookie Notice', class_exists( 'Cookie_Notice' ) ),
	);
	return apply_filters( 'wp_audit_known_table_owners', $owners );
}

/**
 * Non-core tables for this site, each with its likely owner and size.
 * Returns a list of [ 'table', 'owner' (or ''), 'active' (true|false|null when owner unknown), 'rows', 'size_mb' ].
 */
function wp_audit_find_orphaned_tables() {
	global $wpdb;

	// esc_like() matters: "_" in the prefix is otherwise a single-character wildcard.
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
	$core   = array_values( $wpdb->tables( 'all' ) );

	// On a multisite main site, the other sites' tables (wp_2_posts…) share the base prefix; they aren't orphans.
	$other_sites = ( is_multisite() && $wpdb->prefix === $wpdb->base_prefix ) ? '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '\d+_/' : '';

	$sizes = wp_audit_remember(
		'table_sizes',
		15 * MINUTE_IN_SECONDS,
		function () {
			global $wpdb;
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT table_name AS name, table_rows AS row_estimate, ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb FROM information_schema.tables WHERE table_schema = %s', DB_NAME ), ARRAY_A );
			$map  = array();
			foreach ( $rows as $row ) {
				$map[ $row['name'] ] = array( (int) $row['row_estimate'], (float) $row['size_mb'] );
			}
			return $map;
		}
	);

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
			'table'   => $table,
			'owner'   => $owner,
			'active'  => $active,
			'rows'    => isset( $sizes[ $table ] ) ? $sizes[ $table ][0] : null,
			'size_mb' => isset( $sizes[ $table ] ) ? $sizes[ $table ][1] : null,
		);
	}

	return $results;
}

/* ==========================================================================
   Media, users, comments, e-commerce, redirects
   ========================================================================== */

/**
 * Media library breakdown.
 */
function wp_audit_get_media_stats() {
	return wp_audit_remember(
		'media_stats',
		15 * MINUTE_IN_SECONDS,
		function () {
			global $wpdb;
			return array(
				'images'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" ),
				'missing_alt' => (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$wpdb->posts} p
					 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt'
					 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
					   AND ( pm.meta_value IS NULL OR pm.meta_value = '' )"
				),
				'unattached'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent = 0" ),
				'orphaned'    => (int) $wpdb->get_var(
					"SELECT COUNT(p.ID)
					 FROM {$wpdb->posts} p
					 LEFT JOIN {$wpdb->posts} pp ON p.post_parent = pp.ID
					 WHERE p.post_type = 'attachment'
					   AND p.post_status = 'inherit'
					   AND p.post_parent != 0
					   AND pp.ID IS NULL"
				),
				'mime_types'  => array_map(
					'intval',
					wp_list_pluck(
						$wpdb->get_results( "SELECT post_mime_type, COUNT(*) AS total FROM {$wpdb->posts} WHERE post_type = 'attachment' GROUP BY post_mime_type ORDER BY total DESC", ARRAY_A ),
						'total',
						'post_mime_type'
					)
				),
			);
		}
	);
}

/**
 * count_users(), cached: it scans usermeta and is slow on sites with many users.
 */
function wp_audit_get_user_counts() {
	return wp_audit_remember(
		'user_counts',
		15 * MINUTE_IN_SECONDS,
		function () {
			return count_users();
		}
	);
}

/**
 * WooCommerce store details. Null if WooCommerce isn't active.
 */
function wp_audit_get_woocommerce_details( array $post_counts, array $term_counts ) {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return null;
	}

	return wp_audit_remember(
		'woocommerce',
		15 * MINUTE_IN_SECONDS,
		function () use ( $post_counts, $term_counts ) {
			global $wpdb;

			$product_types = array_map(
				'intval',
				wp_list_pluck(
					$wpdb->get_results( "SELECT t.slug, tt.count FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'product_type'", ARRAY_A ),
					'count',
					'slug'
				)
			);

			// wc_orders_count() is HPOS-aware, unlike counting shop_order posts.
			$orders = array();
			if ( function_exists( 'wc_get_order_statuses' ) && function_exists( 'wc_orders_count' ) ) {
				foreach ( wc_get_order_statuses() as $status => $label ) {
					$count = (int) wc_orders_count( substr( $status, 3 ) );
					if ( $count ) {
						$orders[ $label ] = $count;
					}
				}
			}

			$gateways = array();
			if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
				foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
					if ( 'yes' === $gateway->enabled ) {
						$gateways[] = $gateway->get_method_title() . ( $gateway->get_title() && $gateway->get_title() !== $gateway->get_method_title() ? ' ("' . $gateway->get_title() . '")' : '' );
					}
				}
			}

			$zones = array();
			if ( class_exists( 'WC_Shipping_Zones' ) ) {
				foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
					$zones[] = $zone['zone_name'] . ' (' . count( $zone['shipping_methods'] ) . ' methods)';
				}
			}

			$hpos = class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

			return array(
				'version'       => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'products'      => wp_audit_count_status( $post_counts, 'product', 'publish' ),
				'drafts'        => wp_audit_count_status( $post_counts, 'product', 'draft' ),
				'variations'    => wp_audit_count_status( $post_counts, 'product_variation', 'publish' ),
				'product_types' => $product_types,
				'categories'    => isset( $term_counts['product_cat'] ) ? $term_counts['product_cat'] : 0,
				'tags'          => isset( $term_counts['product_tag'] ) ? $term_counts['product_tag'] : 0,
				'attributes'    => function_exists( 'wc_get_attribute_taxonomies' ) ? count( wc_get_attribute_taxonomies() ) : 0,
				'coupons'       => wp_audit_count_status( $post_counts, 'shop_coupon', 'publish' ),
				'orders'        => $orders,
				'gateways'      => $gateways,
				'zones'         => $zones,
				'currency'      => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				'tax_enabled'   => function_exists( 'wc_tax_enabled' ) && wc_tax_enabled(),
				'guest_checkout' => 'yes' === get_option( 'woocommerce_enable_guest_checkout' ),
				'hpos'          => $hpos,
				'subscriptions' => class_exists( 'WC_Subscriptions' ),
			);
		}
	);
}

/**
 * Redirect rules stored by common redirect plugins: [ label => count ].
 */
function wp_audit_get_redirect_counts( array $post_counts ) {
	global $wpdb;
	$found = array();

	if ( wp_audit_table_exists( $wpdb->prefix . 'redirection_items' ) ) {
		$found['Redirection'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}redirection_items WHERE status = 'enabled'" );
	}
	if ( wp_audit_table_exists( $wpdb->prefix . 'rank_math_redirections' ) ) {
		$found['Rank Math'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rank_math_redirections WHERE status = 'active'" );
	}
	$yoast = get_option( 'wpseo-premium-redirects-base' );
	if ( is_array( $yoast ) ) {
		$found['Yoast SEO Premium'] = count( $yoast );
	}
	if ( isset( $post_counts['redirect_rule'] ) ) {
		$found['Safe Redirect Manager'] = wp_audit_count_status( $post_counts, 'redirect_rule', 'publish' );
	}

	return $found;
}
