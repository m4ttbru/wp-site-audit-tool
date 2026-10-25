/**
 * WP Site Audit Tool — admin report behavior.
 *
 * Data comes from window.wpAudit, printed by wp_add_inline_script() in PHP.
 * Loaded with `defer`, so the DOM is already parsed when this runs.
 */
( function () {
	'use strict';

	const data = window.wpAudit || {};
	const statusLine = document.querySelector( '.wp-audit-action-status' );

	const COLORS = {
		good: '#007017',
		warning: '#996800',
		bad: '#b32d2e',
		accent: getComputedStyle( document.documentElement ).getPropertyValue( '--wp-admin-theme-color' ).trim() || '#2271b1',
	};

	function announce( message ) {
		if ( statusLine ) {
			statusLine.textContent = message;
		}
	}

	function escapeHtml( text ) {
		const div = document.createElement( 'div' );
		div.textContent = String( text );
		return div.innerHTML;
	}

	/* ======================================================================
	   Charts
	   ====================================================================== */

	function initCharts() {
		const charts = data.charts;
		if ( typeof Chart === 'undefined' || ! charts ) {
			return;
		}

		Chart.defaults.color = '#1d2327';
		Chart.defaults.borderColor = '#dcdcde';
		Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';

		// Respect reduced-motion preferences; charts animate by default.
		if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			Chart.defaults.animation = false;
		}

		const pluginCanvas = document.getElementById( 'plugin-distribution-chart' );
		if ( pluginCanvas ) {
			new Chart( pluginCanvas, {
				type: 'doughnut',
				data: {
					labels: [ 'Active', 'Inactive' ],
					datasets: [ { data: charts.plugins, backgroundColor: [ COLORS.accent, '#c3c4c7' ], borderWidth: 0 } ],
				},
				options: { maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom' } } },
			} );
		}

		const typesCanvas = document.getElementById( 'post-types-chart' );
		if ( typesCanvas ) {
			new Chart( typesCanvas, {
				type: 'bar',
				data: {
					labels: charts.postTypes.labels,
					datasets: [ { label: 'Published', data: charts.postTypes.data, backgroundColor: COLORS.accent, borderRadius: 3 } ],
				},
				options: {
					maintainAspectRatio: false,
					indexAxis: 'y',
					plugins: { legend: { display: false } },
					scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
				},
			} );
		}

		const healthCanvas = document.getElementById( 'database-health-chart' );
		if ( healthCanvas ) {
			const score = charts.healthScore;
			const color = score >= 80 ? COLORS.good : ( score >= 60 ? COLORS.warning : COLORS.bad );
			new Chart( healthCanvas, {
				type: 'doughnut',
				data: { datasets: [ { data: [ score, 100 - score ], backgroundColor: [ color, '#f0f0f1' ], borderWidth: 0, circumference: 180, rotation: 270 } ] },
				options: { maintainAspectRatio: false, cutout: '70%', plugins: { legend: { display: false }, tooltip: { enabled: false } } },
				plugins: [ {
					id: 'gaugeText',
					afterDraw( chart ) {
						const { ctx, chartArea } = chart;
						const x = ( chartArea.left + chartArea.right ) / 2;
						const y = chartArea.bottom - 8;
						ctx.save();
						ctx.textAlign = 'center';
						ctx.fillStyle = color;
						ctx.font = '600 28px ' + Chart.defaults.font.family;
						ctx.fillText( String( score ), x, y - 14 );
						ctx.fillStyle = '#646970';
						ctx.font = '12px ' + Chart.defaults.font.family;
						ctx.fillText( 'out of 100', x, y + 4 );
						ctx.restore();
					},
				} ],
			} );
		}
	}

	/* ======================================================================
	   PageSpeed Insights (loaded after paint — each API call can take 20–40s)
	   ====================================================================== */

	function formatPsi( row, value, result ) {
		let text;
		if ( row.unit === 'score' ) {
			text = value + '/100';
		} else if ( row.unit === 's' ) {
			text = Number( value ).toFixed( 2 ) + ' s';
		} else if ( row.unit === 'cls' ) {
			text = Number( value ).toFixed( 3 );
		} else {
			text = Math.round( value ) + ' ms';
		}
		if ( row.key === 'inp' && result.inp_scope === 'origin' ) {
			text += ' (whole site)';
		}
		return text;
	}

	function psiStatus( row, value ) {
		if ( row.higher ) {
			return value >= row.good ? 'good' : ( value >= row.warn ? 'warning' : 'bad' );
		}
		return value <= row.good ? 'good' : ( value <= row.warn ? 'warning' : 'bad' );
	}

	function psiCell( row, result ) {
		if ( ! result || result.error ) {
			return '<td>—</td>';
		}
		const value = result[ row.key ];
		if ( value === null || value === undefined ) {
			// Only INP is routinely missing: CrUX has no real-user data for low-traffic sites.
			return '<td><em>' + ( row.key === 'inp' ? 'No real-user data' : 'n/a' ) + '</em></td>';
		}
		const status = psiStatus( row, value );
		const label = { good: 'OK', warning: 'Warning', bad: 'Issue' }[ status ];
		return '<td><span class="wp-audit-status is-' + status + '"><span class="screen-reader-text">' + label + ': </span>' + escapeHtml( formatPsi( row, value, result ) ) + '</span></td>';
	}

	function psiTable( rows, results ) {
		let html = '<div class="wp-audit-table-wrap"><table class="wp-audit-table"><thead><tr><th scope="col">Metric</th><th scope="col">Mobile</th><th scope="col">Desktop</th><th scope="col">Good</th></tr></thead><tbody>';
		rows.forEach( ( row ) => {
			html += '<tr><td>' + escapeHtml( row.label ) + '</td>' + psiCell( row, results.mobile ) + psiCell( row, results.desktop ) + '<td>' + escapeHtml( row.benchmark ) + '</td></tr>';
		} );
		return html + '</tbody></table></div>';
	}

	function fetchPagespeed( strategy ) {
		const form = new FormData();
		form.append( 'action', 'wp_audit_pagespeed' );
		form.append( 'nonce', data.pagespeedNonce );
		form.append( 'strategy', strategy );

		return fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form } )
			.then( ( response ) => response.json() )
			.then( ( json ) => ( json.success ? json.data : Object.assign( { error: true, strategy, message: 'Unknown error' }, json.data || {} ) ) )
			.catch( ( err ) => ( { error: true, strategy, message: err.message } ) );
	}

	function loadPagespeed() {
		const container = document.getElementById( 'wp-audit-pagespeed' );
		if ( ! container || ! data.psiRows ) {
			return Promise.resolve();
		}

		// Both strategies run in parallel; each is its own request so neither waits on the other's API call.
		return Promise.all( [ fetchPagespeed( 'mobile' ), fetchPagespeed( 'desktop' ) ] ).then( ( [ mobile, desktop ] ) => {
			container.setAttribute( 'aria-busy', 'false' );

			if ( mobile.error && desktop.error ) {
				const hint = mobile.local
					? ''
					: '<br>Without an API key Google\'s shared quota often fails. Add <code>define( \'WP_AUDIT_PSI_KEY\', \'…\' );</code> to wp-config.php, then use Refresh data.';
				container.innerHTML = '<div class="wp-audit-callout is-warning"><strong>PageSpeed unavailable.</strong> ' + escapeHtml( mobile.message ) + hint + '</div>';
				return;
			}

			const results = { mobile, desktop };
			let html = '<h4>Lighthouse scores</h4>' + psiTable( data.psiRows.scores, results );
			html += '<h4>Core Web Vitals &amp; timings</h4>' + psiTable( data.psiRows.vitals, results );
			[ mobile, desktop ].forEach( ( result ) => {
				if ( result.error ) {
					html += '<p class="wp-audit-note">' + escapeHtml( result.strategy ) + ': ' + escapeHtml( result.message ) + '</p>';
				}
			} );
			html += '<p class="wp-audit-note">Lab data from Lighthouse for the homepage, except INP which comes from real Chrome users. Mobile is tested on a throttled device, so it is usually lower.</p>';

			container.innerHTML = html;
			initTables( container );
		} );
	}

	const pagespeedReady = loadPagespeed();

	/**
	 * Exports include PageSpeed only once it's cached, so wait for the background fetch first.
	 */
	function whenPagespeedReady() {
		const container = document.getElementById( 'wp-audit-pagespeed' );
		if ( container && container.getAttribute( 'aria-busy' ) === 'true' ) {
			announce( 'Waiting for PageSpeed results to finish so they are included…' );
		}
		return pagespeedReady;
	}

	/* ======================================================================
	   Tables: accessible sorting and filtering
	   ====================================================================== */

	const EMPTY_VALUES = [ '', 'N/A', 'n/a', '—', 'None' ];

	function toNumber( text ) {
		return parseFloat( text.replace( /[,\s]/g, '' ).replace( /[^\d.-]/g, '' ) );
	}

	function sortTable( table, columnIndex, direction ) {
		const tbody = table.tBodies[ 0 ];
		const rows = Array.from( tbody.rows );
		const cellText = ( row ) => ( row.cells[ columnIndex ] ? row.cells[ columnIndex ].textContent.trim() : '' );
		const isNumeric = rows.every( ( row ) => EMPTY_VALUES.includes( cellText( row ) ) || ! isNaN( toNumber( cellText( row ) ) ) );

		rows.sort( ( a, b ) => {
			const textA = cellText( a );
			const textB = cellText( b );
			let comparison;
			if ( isNumeric ) {
				const numA = EMPTY_VALUES.includes( textA ) ? -Infinity : toNumber( textA );
				const numB = EMPTY_VALUES.includes( textB ) ? -Infinity : toNumber( textB );
				comparison = numA === numB ? 0 : ( numA < numB ? -1 : 1 );
			} else {
				comparison = textA.localeCompare( textB, undefined, { sensitivity: 'base', numeric: true } );
			}
			return direction === 'ascending' ? comparison : -comparison;
		} );

		rows.forEach( ( row ) => tbody.appendChild( row ) );
	}

	function initTables( root ) {
		root.querySelectorAll( '.wp-audit-table' ).forEach( ( table ) => {
			if ( table.dataset.ready || ! table.tBodies[ 0 ] || table.tBodies[ 0 ].rows.length < 3 ) {
				return;
			}
			table.dataset.ready = '1';

			const headers = Array.from( table.tHead.rows[ 0 ].cells );
			headers.forEach( ( th, index ) => {
				// A real button makes sorting reachable by keyboard and announced by screen readers.
				const button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'wp-audit-sort';
				button.innerHTML = '<span>' + th.innerHTML + '</span><span class="wp-audit-sort-icon" aria-hidden="true"></span>';
				th.textContent = '';
				th.appendChild( button );

				button.addEventListener( 'click', () => {
					const direction = th.getAttribute( 'aria-sort' ) === 'ascending' ? 'descending' : 'ascending';
					headers.forEach( ( other ) => other.removeAttribute( 'aria-sort' ) );
					th.setAttribute( 'aria-sort', direction );
					sortTable( table, index, direction );
				} );
			} );
		} );
	}

	function initFilters() {
		document.querySelectorAll( '.wp-audit-filter' ).forEach( ( input ) => {
			const table = input.closest( '.wp-audit-block' ).querySelector( '.wp-audit-table' );
			if ( ! table ) {
				return;
			}
			input.addEventListener( 'input', () => {
				const term = input.value.trim().toLowerCase();
				let shown = 0;
				Array.from( table.tBodies[ 0 ].rows ).forEach( ( row ) => {
					const match = ! term || row.textContent.toLowerCase().includes( term );
					row.hidden = ! match;
					shown += match ? 1 : 0;
				} );
				announce( term ? shown + ' matching rows' : '' );
			} );
		} );
	}

	/* ======================================================================
	   Navigation, expand/collapse, print
	   ====================================================================== */

	function initNavHighlight() {
		const links = document.querySelectorAll( '.wp-audit-nav a' );
		const observer = new IntersectionObserver( ( entries ) => {
			entries.forEach( ( entry ) => {
				if ( ! entry.isIntersecting ) {
					return;
				}
				links.forEach( ( link ) => {
					const active = link.getAttribute( 'href' ) === '#' + entry.target.id;
					link.classList.toggle( 'is-active', active );
					if ( active ) {
						link.setAttribute( 'aria-current', 'location' );
					} else {
						link.removeAttribute( 'aria-current' );
					}
				} );
			} );
		}, { rootMargin: '-80px 0px -70% 0px' } );

		document.querySelectorAll( '.wp-audit-section' ).forEach( ( section ) => observer.observe( section ) );
	}

	function setAllDetails( open ) {
		document.querySelectorAll( '.wp-audit-collapsible' ).forEach( ( details ) => {
			details.open = open;
		} );
	}

	function initExpandButtons() {
		document.querySelectorAll( '[data-wp-audit-expand]' ).forEach( ( button ) => {
			button.addEventListener( 'click', () => {
				const open = button.dataset.wpAuditExpand === 'open';
				setAllDetails( open );
				announce( open ? 'All collapsed lists expanded.' : 'All collapsed lists collapsed.' );
			} );
		} );
	}

	function initPrint() {
		// Closed <details> don't print, so expand everything for the printout and restore afterwards.
		let previouslyClosed = [];
		window.addEventListener( 'beforeprint', () => {
			previouslyClosed = Array.from( document.querySelectorAll( '.wp-audit details:not([open])' ) );
			previouslyClosed.forEach( ( details ) => {
				details.open = true;
			} );
		} );
		window.addEventListener( 'afterprint', () => {
			previouslyClosed.forEach( ( details ) => {
				details.open = false;
			} );
		} );

		const button = document.querySelector( '[data-wp-audit-print]' );
		if ( button ) {
			button.addEventListener( 'click', () => window.print() );
		}
	}

	/* ======================================================================
	   Exports
	   ====================================================================== */

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		// http:// admin (e.g. local dev) has no Clipboard API; fall back to a hidden textarea.
		return new Promise( ( resolve, reject ) => {
			const area = document.createElement( 'textarea' );
			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();
			const ok = document.execCommand( 'copy' );
			area.remove();
			if ( ok ) {
				resolve();
			} else {
				reject( new Error( 'Copy command was blocked' ) );
			}
		} );
	}

	function initCopy() {
		const button = document.querySelector( '[data-wp-audit-copy]' );
		if ( ! button ) {
			return;
		}
		const label = button.querySelector( '.wp-audit-copy-label' );
		const original = label.textContent;

		button.addEventListener( 'click', () => {
			button.disabled = true;
			label.textContent = 'Preparing…';

			whenPagespeedReady()
				.then( () => {
					const form = new FormData();
					form.append( 'action', 'wp_audit_markdown' );
					form.append( 'nonce', data.markdownNonce );
					return fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form } );
				} )
				.then( ( response ) => response.json() )
				.then( ( json ) => {
					if ( ! json.success ) {
						throw new Error( 'The server could not build the report.' );
					}
					const markdown = json.data.markdown;
					return copyText( markdown ).then( () => markdown );
				} )
				.then( ( markdown ) => {
					const kb = Math.round( markdown.length / 1024 );
					label.textContent = 'Copied';
					announce( 'Copied ' + kb + ' KB of Markdown (about ' + Math.round( markdown.length / 4000 ) + 'k tokens). Paste it into your AI tool.' );
				} )
				.catch( ( err ) => {
					label.textContent = original;
					announce( 'Copy failed: ' + err.message + ' Use the .md download instead.' );
				} )
				.finally( () => {
					button.disabled = false;
					setTimeout( () => {
						label.textContent = original;
					}, 3000 );
				} );
		} );
	}

	function initExportLinks() {
		document.querySelectorAll( '[data-wp-audit-export]' ).forEach( ( link ) => {
			link.addEventListener( 'click', ( event ) => {
				const container = document.getElementById( 'wp-audit-pagespeed' );
				if ( container && container.getAttribute( 'aria-busy' ) === 'true' ) {
					event.preventDefault();
					whenPagespeedReady().then( () => {
						announce( 'Downloading…' );
						window.location.href = link.href;
					} );
				}
			} );
		} );
	}

	initCharts();
	initTables( document );
	initFilters();
	initNavHighlight();
	initExpandButtons();
	initPrint();
	initCopy();
	initExportLinks();
}() );
