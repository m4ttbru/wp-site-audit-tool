<?php
/**
 * HTML renderer for Tools > Site Audit.
 *
 * Renders the same report model as the Markdown export, so anything added to a
 * section builder shows up in both places without extra template work.
 *
 * @package WP_Site_Audit_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status => [ css modifier, screen-reader label ].
 */
function wp_audit_status_meta( $status ) {
	$map = array(
		'good'    => array( 'is-good', 'OK' ),
		'warning' => array( 'is-warning', 'Warning' ),
		'bad'     => array( 'is-bad', 'Issue' ),
		'info'    => array( 'is-info', 'Note' ),
	);
	return isset( $map[ $status ] ) ? $map[ $status ] : null;
}

/**
 * A value with its status: an icon shape plus hidden text, so status never relies on color alone.
 */
function wp_audit_html_status_value( $value, $status ) {
	$meta = wp_audit_status_meta( $status );
	if ( ! $meta ) {
		return wp_audit_esc( $value );
	}
	return sprintf(
		'<span class="wp-audit-status %1$s"><span class="screen-reader-text">%2$s: </span>%3$s</span>',
		esc_attr( $meta[0] ),
		wp_audit_esc( $meta[1] ),
		wp_audit_esc( $value )
	);
}

/**
 * Render a table cell.
 */
function wp_audit_html_cell( $cell ) {
	if ( ! is_array( $cell ) ) {
		return wp_audit_esc( $cell );
	}
	$html = wp_audit_html_status_value( $cell['value'], $cell['status'] );
	if ( ! empty( $cell['link'] ) ) {
		$html = '<a href="' . esc_url( $cell['link'] ) . '">' . $html . '</a>';
	}
	if ( ! empty( $cell['title'] ) ) {
		$html .= '<span class="wp-audit-cell-detail">' . wp_audit_esc( $cell['title'] ) . '</span>';
	}
	return $html;
}

/**
 * Render one block.
 */
function wp_audit_html_block( array $block ) {
	$title = ! empty( $block['title'] ) ? $block['title'] : '';
	$count = 'table' === $block['type'] ? count( $block['rows'] ) : ( 'list' === $block['type'] ? count( $block['items'] ) : 0 );
	$collapsed = ! empty( $block['collapsed'] ) && $count > 0;

	echo '<div class="wp-audit-block wp-audit-block--' . esc_attr( $block['type'] ) . '">';

	if ( $collapsed ) {
		printf(
			'<details class="wp-audit-collapsible"><summary><span class="wp-audit-collapsible-title">%s</span> <span class="wp-audit-count">%s %s</span></summary>',
			wp_audit_esc( $title ),
			wp_audit_esc( wp_audit_num( $count ) ),
			'table' === $block['type'] ? 'rows' : 'items'
		);
	} elseif ( $title ) {
		echo '<h3>' . wp_audit_esc( $title ) . '</h3>';
	}

	switch ( $block['type'] ) {
		case 'facts':
			echo '<table class="wp-audit-facts"><tbody>';
			foreach ( $block['rows'] as $fact ) {
				echo '<tr><th scope="row">' . wp_audit_esc( $fact['label'] ) . '</th><td>';
				echo wp_audit_html_status_value( '' !== $fact['value'] ? $fact['value'] : '—', $fact['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
				if ( $fact['note'] ) {
					echo '<span class="wp-audit-note-inline">' . wp_audit_esc( $fact['note'] ) . '</span>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			break;

		case 'table':
			if ( empty( $block['rows'] ) ) {
				echo '<p class="wp-audit-empty">' . wp_audit_esc( isset( $block['empty'] ) ? $block['empty'] : 'None.' ) . '</p>';
				break;
			}
			// A filter box only earns its space on longer tables.
			if ( $count > 12 ) {
				printf(
					'<div class="wp-audit-filter-wrap"><span class="dashicons dashicons-filter" aria-hidden="true"></span><input type="search" class="wp-audit-filter" placeholder="%1$s" aria-label="%2$s"></div>',
					esc_attr( sprintf( 'Filter %s rows…', wp_audit_num( $count ) ) ),
					esc_attr( 'Filter ' . ( $title ? $title : 'table' ) )
				);
			}
			echo '<div class="wp-audit-table-wrap"><table class="wp-audit-table"><thead><tr>';
			foreach ( $block['columns'] as $column ) {
				echo '<th scope="col">' . wp_audit_esc( $column ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $block['rows'] as $row ) {
				echo '<tr>';
				foreach ( $row as $cell ) {
					echo '<td>' . wp_audit_html_cell( $cell ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
				}
				echo '</tr>';
			}
			echo '</tbody></table></div>';
			break;

		case 'list':
			if ( empty( $block['items'] ) ) {
				echo '<p class="wp-audit-empty">' . wp_audit_esc( isset( $block['empty'] ) ? $block['empty'] : 'None.' ) . '</p>';
				break;
			}
			echo '<ul class="wp-audit-list">';
			foreach ( $block['items'] as $item ) {
				$text  = is_array( $item ) ? $item['text'] : $item;
				$depth = is_array( $item ) ? (int) $item['depth'] : 0;
				echo '<li style="--depth:' . (int) $depth . '">' . wp_audit_esc( $text ) . '</li>';
			}
			echo '</ul>';
			break;

		case 'text':
			echo '<p>' . wp_audit_esc( $block['text'] ) . '</p>';
			break;

		case 'pagespeed':
			echo '<div id="wp-audit-pagespeed" class="wp-audit-pagespeed" aria-live="polite" aria-busy="true">';
			echo '<p class="wp-audit-loading"><span class="spinner is-active"></span> Fetching Google PageSpeed Insights for mobile and desktop. This runs in the background and can take 20–40 seconds…</p>';
			echo '</div>';
			break;
	}

	if ( ! empty( $block['note'] ) ) {
		echo '<p class="wp-audit-note">' . wp_audit_esc( $block['note'] ) . '</p>';
	}
	if ( $collapsed ) {
		echo '</details>';
	}
	echo '</div>';
}

/**
 * Render a list of findings.
 */
function wp_audit_html_findings( array $findings ) {
	echo '<ul class="wp-audit-findings-list">';
	foreach ( $findings as $finding ) {
		$status = wp_audit_status_meta( $finding['severity'] );
		printf(
			'<li class="%1$s"><span class="wp-audit-pill %1$s">%2$s</span><span class="wp-audit-finding-text">%3$s</span><a class="wp-audit-finding-link" href="#section-%4$s">%5$s <span aria-hidden="true">→</span></a></li>',
			esc_attr( $status[0] ),
			wp_audit_esc( $status[1] ),
			wp_audit_esc( $finding['text'] ),
			esc_attr( $finding['section'] ),
			wp_audit_esc( $finding['section_title'] )
		);
	}
	echo '</ul>';
}

/**
 * Admin URL for an export download.
 */
function wp_audit_export_url( $format ) {
	return wp_nonce_url( admin_url( 'admin-post.php?action=wp_audit_export&format=' . $format ), 'wp_audit_export' );
}

/**
 * Render the admin page.
 */
function wp_audit_tool_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Only administrators can view this report.', 'wp-site-audit' ) );
	}

	$report   = wp_audit_build_report();
	$meta     = $report['meta'];
	$counts   = array( 'bad' => 0, 'warning' => 0, 'info' => 0 );
	$per_section = array();
	foreach ( $report['findings'] as $finding ) {
		$counts[ $finding['severity'] ]++;
		if ( 'info' !== $finding['severity'] ) {
			$per_section[ $finding['section'] ][ $finding['severity'] ] = ( isset( $per_section[ $finding['section'] ][ $finding['severity'] ] ) ? $per_section[ $finding['section'] ][ $finding['severity'] ] : 0 ) + 1;
		}
	}

	wp_add_inline_script(
		'wp-audit-admin',
		'window.wpAudit = ' . wp_json_encode(
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'pagespeedNonce' => wp_create_nonce( 'wp_audit_pagespeed' ),
				'markdownNonce'  => wp_create_nonce( 'wp_audit_markdown' ),
				'charts'         => $report['charts'],
				'psiRows'        => wp_audit_pagespeed_rows(),
			)
		) . ';',
		'before'
	);
	?>
	<div class="wrap wp-audit">
		<header class="wp-audit-header">
			<div class="wp-audit-header-text">
				<h1>Site Audit Report</h1>
				<p class="wp-audit-subtitle">
					<strong><?php echo wp_audit_esc( $meta['site_name'] ); ?></strong>
					<span aria-hidden="true">·</span> <?php echo wp_audit_esc( $meta['site_url'] ); ?>
					<span aria-hidden="true">·</span> Generated <?php echo wp_audit_esc( $meta['generated_local'] ); ?>
				</p>
			</div>
			<div class="wp-audit-actions">
				<button type="button" class="button button-primary wp-audit-copy" data-wp-audit-copy>
					<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
					<span class="wp-audit-copy-label">Copy Markdown for AI</span>
				</button>
				<a class="button" href="<?php echo esc_url( wp_audit_export_url( 'md' ) ); ?>" data-wp-audit-export>
					<span class="dashicons dashicons-download" aria-hidden="true"></span> .md
				</a>
				<a class="button" href="<?php echo esc_url( wp_audit_export_url( 'json' ) ); ?>" data-wp-audit-export>
					<span class="dashicons dashicons-media-code" aria-hidden="true"></span> .json
				</a>
				<button type="button" class="button" data-wp-audit-print>
					<span class="dashicons dashicons-printer" aria-hidden="true"></span> Print
				</button>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wp_audit_clear_cache">
					<?php wp_nonce_field( 'wp_audit_clear_cache' ); ?>
					<button type="submit" class="button" title="Database checks are cached for 15 minutes, remote checks for 1–6 hours and PageSpeed for 24 hours.">
						<span class="dashicons dashicons-update" aria-hidden="true"></span> Refresh data
					</button>
				</form>
			</div>
		</header>

		<p class="wp-audit-action-status" role="status" aria-live="polite"></p>

		<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect. ?>
		<?php if ( isset( $_GET['wp_audit_refreshed'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Cached audit data cleared. Every check on this page was re-run.</p></div>
		<?php endif; ?>

		<section class="wp-audit-snapshot" aria-label="Site snapshot">
			<?php foreach ( $report['snapshot'] as $card ) : ?>
				<div class="wp-audit-card">
					<span class="dashicons <?php echo esc_attr( $card['icon'] ); ?>" aria-hidden="true"></span>
					<span class="wp-audit-card-label"><?php echo wp_audit_esc( $card['label'] ); ?></span>
					<span class="wp-audit-card-value"><?php echo wp_audit_esc( $card['value'] ); ?></span>
					<span class="wp-audit-card-sub"><?php echo wp_audit_esc( $card['sub'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</section>

		<section id="section-findings" class="wp-audit-findings" aria-labelledby="wp-audit-findings-title">
			<div class="wp-audit-findings-head">
				<h2 id="wp-audit-findings-title">Key Findings</h2>
				<ul class="wp-audit-findings-counts">
					<li class="wp-audit-pill is-bad"><?php echo intval( $counts['bad'] ) . ( 1 === $counts['bad'] ? ' issue' : ' issues' ); ?></li>
					<li class="wp-audit-pill is-warning"><?php echo intval( $counts['warning'] ) . ( 1 === $counts['warning'] ? ' warning' : ' warnings' ); ?></li>
					<li class="wp-audit-pill is-info"><?php echo intval( $counts['info'] ) . ( 1 === $counts['info'] ? ' scoping note' : ' scoping notes' ); ?></li>
				</ul>
			</div>
			<?php if ( ! $report['findings'] ) : ?>
				<p class="wp-audit-empty">No issues or warnings found.</p>
			<?php else : ?>
				<?php
				// Issues always show; past that, keep the list short so the report itself stays above the fold.
				$visible = max( $counts['bad'], 8 );
				wp_audit_html_findings( array_slice( $report['findings'], 0, $visible ) );
				$more = array_slice( $report['findings'], $visible );
				?>
				<?php if ( $more ) : ?>
					<details class="wp-audit-more-findings">
						<summary>Show <?php echo intval( count( $more ) ); ?> more findings</summary>
						<?php wp_audit_html_findings( $more ); ?>
					</details>
				<?php endif; ?>
			<?php endif; ?>
		</section>

		<details class="wp-audit-charts" open>
			<summary>Charts</summary>
			<div class="wp-audit-charts-grid">
				<figure>
					<figcaption>Plugins</figcaption>
					<div class="wp-audit-chart-box"><canvas id="plugin-distribution-chart" role="img" aria-label="<?php echo esc_attr( sprintf( '%d active and %d inactive plugins', $report['charts']['plugins'][0], $report['charts']['plugins'][1] ) ); ?>"></canvas></div>
				</figure>
				<figure>
					<figcaption>Published content by type</figcaption>
					<div class="wp-audit-chart-box"><canvas id="post-types-chart" role="img" aria-label="Bar chart of published items for the largest content types"></canvas></div>
				</figure>
				<figure>
					<figcaption>Database health</figcaption>
					<div class="wp-audit-chart-box"><canvas id="database-health-chart" role="img" aria-label="<?php echo esc_attr( 'Database health score ' . $report['charts']['healthScore'] . ' out of 100' ); ?>"></canvas></div>
				</figure>
			</div>
		</details>

		<div class="wp-audit-layout">
			<nav class="wp-audit-nav" aria-label="Report sections">
				<ul>
					<?php foreach ( $report['sections'] as $section ) : ?>
						<li>
							<a href="#section-<?php echo esc_attr( $section['id'] ); ?>">
								<span class="dashicons <?php echo esc_attr( $section['icon'] ); ?>" aria-hidden="true"></span>
								<span class="wp-audit-nav-label"><?php echo wp_audit_esc( $section['title'] ); ?></span>
								<?php if ( ! empty( $per_section[ $section['id'] ]['bad'] ) ) : ?>
									<span class="wp-audit-badge is-bad" title="Issues"><span class="screen-reader-text">Issues: </span><?php echo intval( $per_section[ $section['id'] ]['bad'] ); ?></span>
								<?php elseif ( ! empty( $per_section[ $section['id'] ]['warning'] ) ) : ?>
									<span class="wp-audit-badge is-warning" title="Warnings"><span class="screen-reader-text">Warnings: </span><?php echo intval( $per_section[ $section['id'] ]['warning'] ); ?></span>
								<?php endif; ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
				<div class="wp-audit-nav-tools">
					<button type="button" class="button-link" data-wp-audit-expand="open">Expand all</button>
					<span aria-hidden="true">·</span>
					<button type="button" class="button-link" data-wp-audit-expand="close">Collapse all</button>
				</div>
			</nav>

			<div class="wp-audit-content">
				<?php foreach ( $report['sections'] as $section ) : ?>
					<section id="section-<?php echo esc_attr( $section['id'] ); ?>" class="wp-audit-section" aria-labelledby="title-<?php echo esc_attr( $section['id'] ); ?>">
						<h2 id="title-<?php echo esc_attr( $section['id'] ); ?>">
							<span class="dashicons <?php echo esc_attr( $section['icon'] ); ?>" aria-hidden="true"></span>
							<?php echo wp_audit_esc( $section['title'] ); ?>
						</h2>
						<?php if ( $section['summary'] ) : ?>
							<p class="wp-audit-section-summary"><?php echo wp_audit_esc( $section['summary'] ); ?></p>
						<?php endif; ?>
						<?php
						foreach ( $section['blocks'] as $block ) {
							wp_audit_html_block( $block );
						}
						?>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php
}
