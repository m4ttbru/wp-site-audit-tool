/**
 * WP Site Audit Tool — admin report behavior.
 *
 * Data comes from window.wpAudit, printed by wp_add_inline_script() in PHP.
 * Loaded with `defer`, so the DOM is already parsed when this runs.
 */
( function () {
	'use strict';

	const data = window.wpAudit || {};

	const STATUS_COLORS = {
		good: '#28a745',
		warning: '#ffb900',
		bad: '#d63638',
	};

	/* ======================================================================
	   Charts
	   ====================================================================== */

	function initCharts() {
		if ( typeof Chart === 'undefined' ) {
			return;
		}

		Chart.defaults.color = '#1d2327';
		Chart.defaults.borderColor = '#c3c4c7';
		Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';

		// 1. Plugin Distribution
		const pluginCanvas = document.getElementById( 'plugin-distribution-chart' );
		if ( pluginCanvas && data.plugins ) {
			new Chart( pluginCanvas, {
				type: 'doughnut',
				data: {
					labels: [ 'Active Plugins', 'Inactive Plugins' ],
					datasets: [ {
						data: data.plugins,
						backgroundColor: [ 'rgba(34, 113, 177, 0.8)', 'rgba(214, 54, 56, 0.8)' ],
						borderColor: [ 'rgba(34, 113, 177, 1)', 'rgba(214, 54, 56, 1)' ],
						borderWidth: 2,
					} ],
				},
				options: {
					responsive: true,
					maintainAspectRatio: true,
					plugins: {
						legend: { position: 'bottom', labels: { padding: 15, font: { size: 12 } } },
						tooltip: {
							callbacks: {
								label( context ) {
									const value = context.parsed || 0;
									const total = context.dataset.data.reduce( ( a, b ) => a + b, 0 );
									const percentage = total ? ( ( value / total ) * 100 ).toFixed( 1 ) : 0;
									return ( context.label || '' ) + ': ' + value + ' (' + percentage + '%)';
								},
							},
						},
					},
				},
			} );
		}

		// 2. Top Post Types
		const postTypesCanvas = document.getElementById( 'post-types-chart' );
		if ( postTypesCanvas && data.postTypes ) {
			new Chart( postTypesCanvas, {
				type: 'bar',
				data: {
					labels: data.postTypes.labels,
					datasets: [ {
						label: 'Published Count',
						data: data.postTypes.data,
						backgroundColor: 'rgba(34, 113, 177, 0.8)',
						borderColor: 'rgba(34, 113, 177, 1)',
						borderWidth: 2,
					} ],
				},
				options: {
					responsive: true,
					maintainAspectRatio: true,
					plugins: { legend: { display: false } },
					scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
				},
			} );
		}

		// 3. Database Health gauge
		const dbHealthCanvas = document.getElementById( 'database-health-chart' );
		if ( dbHealthCanvas && typeof data.healthScore === 'number' ) {
			const score = data.healthScore;
			let color = STATUS_COLORS.bad;
			if ( score >= 80 ) {
				color = STATUS_COLORS.good;
			} else if ( score >= 60 ) {
				color = STATUS_COLORS.warning;
			}

			new Chart( dbHealthCanvas, {
				type: 'doughnut',
				data: {
					labels: [ 'Health', 'Issues' ],
					datasets: [ {
						data: [ score, 100 - score ],
						backgroundColor: [ color, 'rgba(200, 200, 200, 0.2)' ],
						borderColor: [ color, 'rgba(200, 200, 200, 1)' ],
						borderWidth: 2,
						circumference: 180,
						rotation: 270,
					} ],
				},
				options: {
					responsive: true,
					maintainAspectRatio: true,
					plugins: { legend: { display: false }, tooltip: { enabled: false } },
				},
				plugins: [ {
					id: 'gaugeText',
					afterDraw( chart ) {
						const ctx = chart.ctx;
						const centerX = ( chart.chartArea.left + chart.chartArea.right ) / 2;
						const centerY = ( chart.chartArea.top + chart.chartArea.bottom ) / 2 + 20;

						ctx.save();
						ctx.font = 'bold 32px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto';
						ctx.fillStyle = color;
						ctx.textAlign = 'center';
						ctx.textBaseline = 'middle';
						ctx.fillText( String( score ), centerX, centerY );

						ctx.font = '12px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto';
						ctx.fillStyle = '#666';
						ctx.fillText( 'Health Score', centerX, centerY + 25 );
						ctx.restore();
					},
				} ],
			} );
		}
	}

	/* ======================================================================
	   PageSpeed Insights (loaded after paint — each API call can take 20–40s)
	   ====================================================================== */

	// Thresholds: [ good at or below / at or above, warning boundary, higher-is-better ].
	const SCORE = [ 90, 50, true ];
	const PSI_ROWS = {
		scores: [
			{ key: 'performance', label: 'Performance Score', rule: SCORE, format: scoreFormat, benchmark: '90+ Good, 50-89 Needs Work, <50 Poor' },
			{ key: 'accessibility', label: 'Accessibility Score', rule: SCORE, format: scoreFormat, benchmark: '90+ Accessible' },
			{ key: 'seo', label: 'SEO Score', rule: SCORE, format: scoreFormat, benchmark: '90+ SEO Friendly' },
			{ key: 'best_practices', label: 'Best Practices', rule: SCORE, format: scoreFormat, benchmark: '90+ Follows Best Practices' },
		],
		vitals: [
			{ key: 'lcp', label: 'LCP (Largest Contentful Paint)', rule: [ 2.5, 4, false ], format: ( v ) => v.toFixed( 2 ) + 's', benchmark: '≤ 2.5s' },
			{ key: 'inp', label: 'INP (Interaction to Next Paint) — real users', rule: [ 200, 500, false ], format: inpFormat, benchmark: '≤ 200ms' },
			{ key: 'cls', label: 'CLS (Cumulative Layout Shift)', rule: [ 0.1, 0.25, false ], format: ( v ) => v.toFixed( 3 ), benchmark: '≤ 0.1' },
			{ key: 'tbt', label: 'TBT (Total Blocking Time) — lab stand-in for INP', rule: [ 200, 600, false ], format: ( v ) => Math.round( v ) + 'ms', benchmark: '≤ 200ms' },
			{ key: 'fcp', label: 'FCP (First Contentful Paint)', rule: [ 1.8, 3, false ], format: ( v ) => v.toFixed( 2 ) + 's', benchmark: '≤ 1.8s' },
			{ key: 'ttfb', label: 'TTFB (Server Response Time)', rule: [ 800, 1800, false ], format: ( v ) => Math.round( v ) + 'ms', benchmark: '≤ 800ms' },
		],
	};

	function scoreFormat( value ) {
		return value + '/100';
	}

	function inpFormat( value, result ) {
		return value + 'ms' + ( result.inp_scope === 'origin' ? ' (whole site)' : '' );
	}

	function statusClass( value, rule ) {
		const [ good, warning, higherIsBetter ] = rule;
		if ( higherIsBetter ) {
			if ( value >= good ) {
				return 'audit-status-good';
			}
			return value >= warning ? 'audit-status-warning' : 'audit-status-bad';
		}
		if ( value <= good ) {
			return 'audit-status-good';
		}
		return value <= warning ? 'audit-status-warning' : 'audit-status-bad';
	}

	function escapeHtml( text ) {
		const div = document.createElement( 'div' );
		div.textContent = String( text );
		return div.innerHTML;
	}

	function psiCell( row, result ) {
		if ( ! result || result.error ) {
			return '<td>—</td>';
		}
		const value = result[ row.key ];
		if ( value === null || value === undefined ) {
			// Only INP is routinely missing: CrUX has no real-user data for low-traffic sites.
			return '<td><em>' + ( row.key === 'inp' ? 'No field data' : 'n/a' ) + '</em></td>';
		}
		return '<td><span class="' + statusClass( value, row.rule ) + '">' + escapeHtml( row.format( value, result ) ) + '</span></td>';
	}

	function psiTable( rows, firstHeader, results ) {
		let html = '<table class="wp-audit-table"><thead><tr><th>' + firstHeader + '</th><th>Desktop</th><th>Mobile</th><th>Benchmark</th></tr></thead><tbody>';
		rows.forEach( ( row ) => {
			html += '<tr><td><strong>' + escapeHtml( row.label ) + '</strong></td>'
				+ psiCell( row, results.desktop )
				+ psiCell( row, results.mobile )
				+ '<td>' + escapeHtml( row.benchmark ) + '</td></tr>';
		} );
		return html + '</tbody></table>';
	}

	function fetchPagespeed( strategy ) {
		const form = new FormData();
		form.append( 'action', 'wp_audit_pagespeed' );
		form.append( 'nonce', data.pagespeedNonce );
		form.append( 'strategy', strategy );

		return fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form } )
			.then( ( response ) => response.json() )
			.then( ( json ) => ( json.success ? json.data : { error: true, strategy, message: json.data && json.data.message ? json.data.message : 'Unknown error' } ) )
			.catch( ( err ) => ( { error: true, strategy, message: err.message } ) );
	}

	function loadPagespeed() {
		const container = document.getElementById( 'wp-audit-pagespeed' );
		if ( ! container ) {
			return;
		}

		// Both strategies run in parallel; each is its own request so neither waits on the other's API call.
		Promise.all( [ fetchPagespeed( 'desktop' ), fetchPagespeed( 'mobile' ) ] ).then( ( [ desktop, mobile ] ) => {
			if ( desktop.error && mobile.error ) {
				container.innerHTML = '<div class="audit-summary wp-audit-psi-error"><strong>PageSpeed API Error:</strong> '
					+ escapeHtml( desktop.message )
					+ '<br><small>Usually an API rate limit or a site Google can\'t reach. Without an API key the shared quota often fails; add <code>define( \'WP_AUDIT_PSI_KEY\', \'...\' );</code> to wp-config.php, then use Refresh Cached Data.</small></div>';
				return;
			}

			const results = { desktop, mobile };
			let html = psiTable( PSI_ROWS.scores, 'Metric', results );
			html += '<h4 class="wp-audit-subheading">Core Web Vitals</h4>';
			html += psiTable( PSI_ROWS.vitals, 'Vital', results );

			[ desktop, mobile ].forEach( ( result ) => {
				if ( result.error ) {
					html += '<p class="audit-status-warning">' + escapeHtml( result.strategy || '' ) + ' PageSpeed error: ' + escapeHtml( result.message ) + '</p>';
				}
			} );

			html += '<h4 class="wp-audit-subheading">Performance Metrics Visualization</h4>'
				+ '<div class="wp-audit-chart-box"><canvas id="performance-radar-chart" style="max-height: 400px;"></canvas></div>';

			container.innerHTML = html;
			initTableSorting();
			drawRadarChart( results );
		} );
	}

	function drawRadarChart( results ) {
		const canvas = document.getElementById( 'performance-radar-chart' );
		if ( ! canvas || typeof Chart === 'undefined' ) {
			return;
		}

		const keys = [ 'performance', 'accessibility', 'seo', 'best_practices' ];
		const datasets = [];
		const series = [
			[ 'Desktop', results.desktop, '34, 113, 177' ],
			[ 'Mobile', results.mobile, '255, 185, 0' ],
		];
		series.forEach( ( [ label, result, rgb ] ) => {
			if ( ! result || result.error ) {
				return;
			}
			datasets.push( {
				label,
				data: keys.map( ( key ) => result[ key ] || 0 ),
				backgroundColor: 'rgba(' + rgb + ', 0.2)',
				borderColor: 'rgba(' + rgb + ', 1)',
				borderWidth: 2,
				pointBackgroundColor: 'rgba(' + rgb + ', 1)',
				pointBorderColor: '#fff',
				pointHoverBackgroundColor: '#fff',
				pointHoverBorderColor: 'rgba(' + rgb + ', 1)',
			} );
		} );

		new Chart( canvas, {
			type: 'radar',
			data: { labels: [ 'Performance', 'Accessibility', 'SEO', 'Best Practices' ], datasets },
			options: {
				responsive: true,
				maintainAspectRatio: true,
				scales: { r: { beginAtZero: true, max: 100, ticks: { stepSize: 20 }, pointLabels: { font: { size: 13 } } } },
				plugins: {
					legend: { position: 'bottom', labels: { padding: 20, font: { size: 13 } } },
					tooltip: { callbacks: { label: ( context ) => context.dataset.label + ': ' + context.parsed.r + '/100' } },
				},
			},
		} );
	}

	/* ======================================================================
	   Table sorting
	   ====================================================================== */

	const EMPTY_VALUES = [ '', 'N/A', '—', 'None' ];

	function initTableSorting() {
		document.querySelectorAll( '.wp-audit-table' ).forEach( ( table ) => {
			const tbody = table.querySelector( 'tbody' );
			if ( ! tbody || tbody.querySelectorAll( 'tr' ).length <= 1 ) {
				return; // Skip tables with 0 or 1 rows
			}

			const headers = table.querySelectorAll( 'thead th' );
			headers.forEach( ( header, index ) => {
				if ( header.dataset.sortInitialized === 'true' ) {
					return;
				}
				header.classList.add( 'sortable' );
				header.dataset.sortInitialized = 'true';

				header.addEventListener( 'click', function () {
					const newSort = this.classList.contains( 'sort-asc' ) ? 'desc' : 'asc';
					headers.forEach( ( h ) => h.classList.remove( 'sort-asc', 'sort-desc' ) );
					this.classList.add( 'sort-' + newSort );
					sortTable( table, index, newSort );
				} );
			} );
		} );
	}

	function toNumber( text ) {
		return parseFloat( text.replace( /[,\s]/g, '' ).replace( /[^\d.-]/g, '' ) );
	}

	function sortTable( table, columnIndex, direction ) {
		const tbody = table.querySelector( 'tbody' );
		const rows = Array.from( tbody.querySelectorAll( ':scope > tr' ) );
		const cellText = ( row ) => ( row.cells[ columnIndex ] ? row.cells[ columnIndex ].textContent.trim() : '' );

		const isNumeric = rows.every( ( row ) => {
			const text = cellText( row );
			return EMPTY_VALUES.includes( text ) || ! isNaN( toNumber( text ) );
		} );

		rows.sort( ( rowA, rowB ) => {
			const textA = cellText( rowA );
			const textB = cellText( rowB );
			let comparison;

			if ( isNumeric ) {
				const numA = EMPTY_VALUES.includes( textA ) ? -Infinity : toNumber( textA );
				const numB = EMPTY_VALUES.includes( textB ) ? -Infinity : toNumber( textB );
				comparison = numA === numB ? 0 : ( numA < numB ? -1 : 1 );
			} else {
				comparison = textA.toLowerCase().localeCompare( textB.toLowerCase() );
			}

			return direction === 'asc' ? comparison : -comparison;
		} );

		rows.forEach( ( row ) => tbody.appendChild( row ) );
	}

	/* ======================================================================
	   Sticky nav active state
	   ====================================================================== */

	function initNavHighlight() {
		const navLinks = document.querySelectorAll( '.wp-audit-nav a' );
		const observer = new IntersectionObserver( ( entries ) => {
			entries.forEach( ( entry ) => {
				if ( ! entry.isIntersecting ) {
					return;
				}
				navLinks.forEach( ( link ) => {
					link.classList.toggle( 'active', link.getAttribute( 'href' ) === '#' + entry.target.id );
				} );
			} );
		}, { root: null, rootMargin: '-40px 0px -60% 0px', threshold: 0 } );

		document.querySelectorAll( '.wp-audit-content h2' ).forEach( ( section ) => observer.observe( section ) );
	}

	/* ======================================================================
	   Markdown export
	   ====================================================================== */

	function cleanText( text ) {
		return text.trim().replace( /\s+/g, ' ' );
	}

	function nestedTableToMarkdown( table ) {
		const rows = [];
		table.querySelectorAll( 'tbody tr' ).forEach( ( tr ) => {
			const cells = Array.from( tr.querySelectorAll( 'td' ) ).map( ( td ) => cleanText( td.textContent ) );
			if ( cells.length ) {
				rows.push( cells.join( ': ' ) );
			}
		} );
		return ' (' + rows.join( '; ' ) + ') ';
	}

	function tableToMarkdown( table ) {
		let md = '';
		const headers = Array.from( table.querySelectorAll( ':scope > thead th' ) ).map( ( th ) => cleanText( th.textContent ).replace( /\s*\?$/, '' ) );

		if ( headers.length ) {
			md += '| ' + headers.join( ' | ' ) + ' |\n';
			md += '|' + headers.map( () => ' --- ' ).join( '|' ) + '|\n';
		}

		table.querySelectorAll( ':scope > tbody > tr' ).forEach( ( tr ) => {
			const row = Array.from( tr.cells ).map( ( td ) => {
				const nested = td.querySelector( '.wp-audit-table' );
				if ( nested ) {
					const own = td.cloneNode( true );
					own.querySelector( '.wp-audit-table' ).remove();
					return cleanText( own.textContent ) + nestedTableToMarkdown( nested );
				}
				const links = td.querySelectorAll( 'a' );
				if ( links.length ) {
					return Array.from( links ).map( ( link ) => '[' + cleanText( link.textContent ) + '](' + link.href + ')' ).join( ' ' );
				}
				// textContent (not innerText) so collapsed <details> lists are still exported.
				return cleanText( td.textContent ).replace( /\|/g, '\\|' );
			} );
			if ( row.length ) {
				md += '| ' + row.join( ' | ' ) + ' |\n';
			}
		} );

		return md;
	}

	function buildMarkdown() {
		let md = '# WordPress Site Audit Report\n\n';
		md += '**Generated:** ' + new Date().toLocaleDateString() + '\n\n';

		const overview = document.getElementById( 'section-overview' );
		if ( overview ) {
			md += '## Website Overview\n\n';
			overview.querySelectorAll( '.wp-audit-overview-item' ).forEach( ( item ) => {
				const title = cleanText( item.querySelector( '.wp-audit-overview-item-header strong' ).textContent );
				const value = item.querySelector( '.value' );
				md += '**' + title + ':** ' + ( value ? cleanText( value.textContent ) : '' ) + '\n\n';
			} );
			md += '---\n\n';
		}

		const content = document.querySelector( '.wp-audit-content' );
		content.querySelectorAll( 'h2, h3, h4, .wp-audit-table, p, .audit-summary, .audit-section-header' ).forEach( ( el ) => {
			// Nested tables and paragraphs inside tables/summaries are exported by their parent.
			if ( el.parentElement.closest( '.wp-audit-table, .audit-summary' ) ) {
				return;
			}

			if ( el.tagName === 'H2' ) {
				md += '## ' + cleanText( el.textContent ) + '\n\n';
			} else if ( el.tagName === 'H3' || el.classList.contains( 'audit-section-header' ) ) {
				md += '### ' + cleanText( el.textContent ) + '\n\n';
			} else if ( el.tagName === 'H4' ) {
				md += '#### ' + cleanText( el.textContent ) + '\n\n';
			} else if ( el.classList.contains( 'wp-audit-table' ) ) {
				md += tableToMarkdown( el ) + '\n';
			} else if ( el.classList.contains( 'audit-summary' ) ) {
				md += '> ' + cleanText( el.textContent ) + '\n\n';
			} else if ( el.tagName === 'P' && cleanText( el.textContent ) ) {
				md += cleanText( el.textContent ) + '\n\n';
			}
		} );

		return md;
	}

	function initExport() {
		const exportBtn = document.getElementById( 'wp-audit-export-btn' );
		if ( ! exportBtn ) {
			return;
		}

		exportBtn.addEventListener( 'click', () => {
			const blob = new Blob( [ buildMarkdown() ], { type: 'text/markdown;charset=utf-8' } );
			const url = URL.createObjectURL( blob );
			const date = new Date().toISOString().split( 'T' )[ 0 ];
			const a = document.createElement( 'a' );

			a.href = url;
			a.download = ( data.siteName || 'site' ) + '-audit-report-' + date + '.md';
			document.body.appendChild( a );
			a.click();
			a.remove();
			URL.revokeObjectURL( url );
		} );
	}

	initCharts();
	initTableSorting();
	initNavHighlight();
	initExport();
	loadPagespeed();
}() );
