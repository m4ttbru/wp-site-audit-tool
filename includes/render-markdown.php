<?php
/**
 * Markdown and JSON renderers.
 *
 * The Markdown is the plugin's main deliverable: it is pasted into an LLM that
 * makes scoping and redesign decisions. So it is written to be read top-down by
 * a model: YAML front matter, a short reading guide, Key Findings first, every
 * section with a one-line "what this is", status words instead of colors, and
 * nothing collapsed or truncated that the HTML page shows.
 *
 * @package WP_Site_Audit_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status => Markdown marker.
 */
function wp_audit_md_marker( $status ) {
	$markers = array(
		'good'    => '[OK]',
		'warning' => '[WARN]',
		'bad'     => '[ISSUE]',
	);
	return isset( $markers[ $status ] ) ? $markers[ $status ] : '';
}

/**
 * Make text safe for a single Markdown table cell or list line.
 */
function wp_audit_md_escape( $text, $in_table = false ) {
	$text = trim( preg_replace( '/\s+/', ' ', wp_audit_plain( $text ) ) );
	return $in_table ? str_replace( '|', '\|', $text ) : $text;
}

/**
 * Render a table cell (string or wp_audit_cell()) as Markdown.
 * Status markers are only added for problems, to keep tables scannable.
 */
function wp_audit_md_cell( $cell ) {
	if ( ! is_array( $cell ) ) {
		return wp_audit_md_escape( $cell, true );
	}
	$text = $cell['value'];
	if ( ! empty( $cell['title'] ) ) {
		$text .= ' (' . $cell['title'] . ')';
	}
	if ( in_array( $cell['status'], array( 'warning', 'bad' ), true ) ) {
		$text .= ' ' . wp_audit_md_marker( $cell['status'] );
	}
	return wp_audit_md_escape( $text, true );
}

/**
 * PageSpeed metric definitions, shared with the JavaScript renderer so both
 * use the same thresholds. good/warn are upper bounds unless 'higher' is true.
 */
function wp_audit_pagespeed_rows() {
	return array(
		'scores' => array(
			array( 'key' => 'performance', 'label' => 'Performance score', 'good' => 90, 'warn' => 50, 'higher' => true, 'unit' => 'score', 'benchmark' => '90+ good, 50–89 needs work, under 50 poor' ),
			array( 'key' => 'accessibility', 'label' => 'Accessibility score', 'good' => 90, 'warn' => 50, 'higher' => true, 'unit' => 'score', 'benchmark' => '90+' ),
			array( 'key' => 'seo', 'label' => 'SEO score', 'good' => 90, 'warn' => 50, 'higher' => true, 'unit' => 'score', 'benchmark' => '90+' ),
			array( 'key' => 'best_practices', 'label' => 'Best practices score', 'good' => 90, 'warn' => 50, 'higher' => true, 'unit' => 'score', 'benchmark' => '90+' ),
		),
		'vitals' => array(
			array( 'key' => 'lcp', 'label' => 'LCP — Largest Contentful Paint', 'good' => 2.5, 'warn' => 4, 'higher' => false, 'unit' => 's', 'benchmark' => '≤ 2.5 s' ),
			array( 'key' => 'inp', 'label' => 'INP — Interaction to Next Paint (real users)', 'good' => 200, 'warn' => 500, 'higher' => false, 'unit' => 'ms', 'benchmark' => '≤ 200 ms' ),
			array( 'key' => 'cls', 'label' => 'CLS — Cumulative Layout Shift', 'good' => 0.1, 'warn' => 0.25, 'higher' => false, 'unit' => 'cls', 'benchmark' => '≤ 0.1' ),
			array( 'key' => 'tbt', 'label' => 'TBT — Total Blocking Time (lab stand-in for INP)', 'good' => 200, 'warn' => 600, 'higher' => false, 'unit' => 'ms', 'benchmark' => '≤ 200 ms' ),
			array( 'key' => 'fcp', 'label' => 'FCP — First Contentful Paint', 'good' => 1.8, 'warn' => 3, 'higher' => false, 'unit' => 's', 'benchmark' => '≤ 1.8 s' ),
			array( 'key' => 'ttfb', 'label' => 'TTFB — Server response time', 'good' => 800, 'warn' => 1800, 'higher' => false, 'unit' => 'ms', 'benchmark' => '≤ 800 ms' ),
		),
	);
}

/**
 * Format one PageSpeed value with its status marker.
 */
function wp_audit_md_pagespeed_value( array $row, $result ) {
	if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
		return '—';
	}
	$value = isset( $result[ $row['key'] ] ) ? $result[ $row['key'] ] : null;
	if ( null === $value ) {
		return 'inp' === $row['key'] ? 'No real-user data' : 'n/a';
	}

	switch ( $row['unit'] ) {
		case 'score':
			$text = $value . '/100';
			break;
		case 's':
			$text = number_format( $value, 2 ) . ' s';
			break;
		case 'cls':
			$text = number_format( $value, 3 );
			break;
		default:
			$text = round( $value ) . ' ms';
	}
	if ( 'inp' === $row['key'] && 'origin' === $result['inp_scope'] ) {
		$text .= ' (whole-site data)';
	}

	if ( $row['higher'] ) {
		$status = $value >= $row['good'] ? 'good' : ( $value >= $row['warn'] ? 'warning' : 'bad' );
	} else {
		$status = $value <= $row['good'] ? 'good' : ( $value <= $row['warn'] ? 'warning' : 'bad' );
	}
	return $text . ' ' . wp_audit_md_marker( $status );
}

/**
 * Markdown for the PageSpeed block, from cached results only (never blocks on the API).
 */
function wp_audit_md_pagespeed() {
	$results = array(
		'desktop' => wp_audit_get_cached_pagespeed( 'desktop' ),
		'mobile'  => wp_audit_get_cached_pagespeed( 'mobile' ),
	);

	if ( ! $results['desktop'] && ! $results['mobile'] ) {
		return array( 'PageSpeed Insights has not been measured yet. Open the report in wp-admin (it fetches PageSpeed in the background) or run `wp site-audit report --pagespeed`, then export again.' );
	}
	if ( ! empty( $results['desktop']['error'] ) && ! empty( $results['mobile']['error'] ) ) {
		return array( 'PageSpeed Insights unavailable: ' . $results['desktop']['message'] );
	}

	$lines = array();
	foreach ( wp_audit_pagespeed_rows() as $rows ) {
		$lines[] = '| Metric | Mobile | Desktop | Good threshold |';
		$lines[] = '| --- | --- | --- | --- |';
		foreach ( $rows as $row ) {
			$lines[] = '| ' . $row['label'] . ' | ' . wp_audit_md_pagespeed_value( $row, $results['mobile'] ) . ' | ' . wp_audit_md_pagespeed_value( $row, $results['desktop'] ) . ' | ' . $row['benchmark'] . ' |';
		}
		$lines[] = '';
	}
	$lines[] = '*Lab data from Google PageSpeed Insights (Lighthouse) for the homepage, except INP which comes from real Chrome users (CrUX). Google scores mobile with a throttled device, so mobile is usually lower.*';
	return $lines;
}

/**
 * Render one block as Markdown lines.
 */
function wp_audit_md_block( array $block ) {
	$lines = array();
	if ( ! empty( $block['title'] ) ) {
		$lines[] = '### ' . wp_audit_md_escape( $block['title'] );
		$lines[] = '';
	}

	switch ( $block['type'] ) {
		case 'facts':
			foreach ( $block['rows'] as $fact ) {
				$line = '- **' . wp_audit_md_escape( $fact['label'] ) . ':** ' . wp_audit_md_escape( '' !== $fact['value'] ? $fact['value'] : '—' );
				if ( $fact['status'] ) {
					$line .= ' ' . wp_audit_md_marker( $fact['status'] );
				}
				if ( $fact['note'] ) {
					$line .= ' — ' . wp_audit_md_escape( $fact['note'] );
				}
				$lines[] = $line;
			}
			break;

		case 'table':
			if ( empty( $block['rows'] ) ) {
				$lines[] = '*' . wp_audit_md_escape( isset( $block['empty'] ) ? $block['empty'] : 'None.' ) . '*';
				break;
			}
			$lines[] = '| ' . implode( ' | ', array_map( 'wp_audit_md_cell', $block['columns'] ) ) . ' |';
			$lines[] = '|' . str_repeat( ' --- |', count( $block['columns'] ) );
			foreach ( $block['rows'] as $row ) {
				$lines[] = '| ' . implode( ' | ', array_map( 'wp_audit_md_cell', $row ) ) . ' |';
			}
			break;

		case 'list':
			if ( empty( $block['items'] ) ) {
				$lines[] = '*' . wp_audit_md_escape( isset( $block['empty'] ) ? $block['empty'] : 'None.' ) . '*';
				break;
			}
			foreach ( $block['items'] as $item ) {
				$text    = is_array( $item ) ? $item['text'] : $item;
				$depth   = is_array( $item ) ? (int) $item['depth'] : 0;
				$lines[] = str_repeat( '  ', $depth ) . '- ' . wp_audit_md_escape( $text );
			}
			break;

		case 'text':
			$lines[] = wp_audit_md_escape( $block['text'] );
			break;

		case 'pagespeed':
			$lines = array_merge( $lines, wp_audit_md_pagespeed() );
			break;
	}

	if ( ! empty( $block['note'] ) ) {
		$lines[] = '';
		$lines[] = '*' . wp_audit_md_escape( $block['note'] ) . '*';
	}
	$lines[] = '';
	return $lines;
}

/**
 * Render the whole report as Markdown.
 */
function wp_audit_render_markdown( array $report ) {
	$meta  = $report['meta'];
	$lines = array(
		'---',
		'report: WordPress Site Audit',
		'site_name: ' . wp_json_encode( $meta['site_name'] ),
		'site_url: ' . $meta['site_url'],
		'generated: ' . $meta['generated'],
		'wordpress: ' . $meta['wp_version'],
		'php: ' . $meta['php_version'],
		'theme: ' . wp_json_encode( $meta['theme'] ),
		'generator: WP Site Audit Tool ' . $meta['plugin_version'],
		'---',
		'',
		'# Site Audit: ' . wp_audit_md_escape( $meta['site_name'] ),
		'',
		'> Automated, read-only inventory of ' . $meta['site_url'] . ', generated ' . $meta['generated_local'] . '. It is intended as complete context for scoping a redesign, rebuild, migration or RFQ.',
		'>',
		'> **How to read it:** Key Findings come first, then one section per area of the site. Each section starts with a one-line description in italics. Status markers: **[ISSUE]** needs attention, **[WARN]** worth reviewing, **[OK]** healthy. Counts are exact unless a note says they come from a sample. "—" means not applicable or not available.',
		'',
		'## Contents',
		'',
		'1. Key Findings',
	);

	foreach ( $report['sections'] as $i => $section ) {
		$lines[] = ( $i + 2 ) . '. ' . $section['title'];
	}
	$lines[] = '';

	// --- Key Findings ---
	$lines[] = '## Key Findings';
	$lines[] = '';
	$groups  = array(
		'bad'     => 'Issues (need attention)',
		'warning' => 'Warnings (worth reviewing)',
		'info'    => 'Notable for scoping',
	);
	$any = false;
	foreach ( $groups as $severity => $heading ) {
		$items = array_filter(
			$report['findings'],
			function ( $finding ) use ( $severity ) {
				return $finding['severity'] === $severity;
			}
		);
		if ( ! $items ) {
			continue;
		}
		$any     = true;
		$lines[] = '### ' . $heading;
		$lines[] = '';
		foreach ( $items as $finding ) {
			$lines[] = '- ' . ( 'info' === $severity ? '' : wp_audit_md_marker( $severity ) . ' ' ) . '**' . $finding['section_title'] . ':** ' . wp_audit_md_escape( $finding['text'] );
		}
		$lines[] = '';
	}
	if ( ! $any ) {
		$lines[] = '*No issues or warnings found.*';
		$lines[] = '';
	}

	// --- Sections ---
	foreach ( $report['sections'] as $section ) {
		$lines[] = '## ' . $section['title'];
		$lines[] = '';
		if ( $section['summary'] ) {
			$lines[] = '*' . wp_audit_md_escape( $section['summary'] ) . '*';
			$lines[] = '';
		}
		foreach ( $section['blocks'] as $block ) {
			$lines = array_merge( $lines, wp_audit_md_block( $block ) );
		}
	}

	$lines[] = '---';
	$lines[] = '*End of report.*';

	return implode( "\n", $lines ) . "\n";
}

/**
 * Render the report as JSON (adds cached PageSpeed results, which the HTML loads separately).
 */
function wp_audit_render_json( array $report ) {
	$report['pagespeed'] = array(
		'desktop' => wp_audit_get_cached_pagespeed( 'desktop' ),
		'mobile'  => wp_audit_get_cached_pagespeed( 'mobile' ),
	);
	return wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/**
 * File name for an export, e.g. "my-site-audit-2026-10-22.md".
 */
function wp_audit_export_filename( $extension ) {
	$slug = sanitize_title( get_bloginfo( 'name' ) );
	return ( $slug ? $slug : 'site' ) . '-audit-' . gmdate( 'Y-m-d' ) . '.' . $extension;
}
