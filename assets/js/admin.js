/**
 * Manly WebP Converter — admin page script.
 *
 * Configuration is provided by wp_localize_script() as `manlyWebpConverter`:
 *   - ajaxUrl (string)
 *   - nonce   (string)
 *   - i18n    (object) translatable strings used by the UI
 *
 * @package ManlyWebpConverter
 * @since 1.0.2
 */

( function () {
	'use strict';

	var cfg     = window.manlyWebpConverter || {};
	var i18n    = cfg.i18n || {};
	var nonce   = cfg.nonce;
	var ajaxUrl = cfg.ajaxUrl;

	var btnAnalyze = document.getElementById( 'manly-webp-analyze' );
	if ( ! btnAnalyze ) {
		return; } // Not on our admin page.

	var analyzingSpn    = document.getElementById( 'manly-webp-analyzing' );
	var statsP          = document.getElementById( 'manly-webp-stats' );
	var btnStart        = document.getElementById( 'manly-webp-converter-start' );
	var modeWrap        = document.getElementById( 'manly-webp-converter-mode' );
	var modeRadios      = document.querySelectorAll( 'input[name="manly_webp_mode"]' );
	var modeSelect      = {
		get value() {
			var checked = modeWrap.querySelector( 'input[name="manly_webp_mode"]:checked' );
			return checked ? checked.value : 'all';
		},
		set value( v ) {
			modeRadios.forEach(
				function ( r ) {
					r.checked                             = ( r.value === v );
					r.nextElementSibling.style.background = r.checked ? '#0073aa' : '#f6f7f7';
					r.nextElementSibling.style.color      = r.checked ? '#fff' : '';
				}
			);
		}
	};
	var qualitySldr     = document.getElementById( 'manly-webp-converter-quality' );
	var qualityVal      = document.getElementById( 'manly-webp-converter-quality-val' );
	var keepChk         = document.getElementById( 'manly-webp-converter-keep' );
	var postRow         = document.getElementById( 'manly-webp-converter-post-row' );
	var postSel         = document.getElementById( 'manly-webp-converter-post' );
	var postLink        = document.getElementById( 'manly-webp-converter-post-link' );
	var noPosts         = document.getElementById( 'manly-webp-no-posts' );
	var stopNote        = document.getElementById( 'manly-webp-stop-note' );
	var progressDiv     = document.getElementById( 'manly-webp-converter-progress' );
	var barDiv          = document.getElementById( 'manly-webp-converter-bar' );
	var statusP2        = document.getElementById( 'manly-webp-converter-status' );
	var logArea         = document.getElementById( 'manly-webp-converter-log-area' );

	function postData( action, data ) {
		var formData = new FormData();
		formData.append( 'action', action );
		formData.append( 'nonce', nonce );
		for ( var k in data ) {
			if ( data.hasOwnProperty( k ) ) {
				formData.append( k, data[ k ] );
			}
		}
		return fetch( ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' } )
			.then(
				function ( r ) {
					return r.json(); }
			);
	}

	function updatePostLink() {
		if ( ! postSel.options.length ) {
			return; }
		var url       = postSel.options[ postSel.selectedIndex ].dataset.url;
		postLink.href = url || '#';
	}
	postSel.addEventListener( 'change', updatePostLink );

	modeRadios.forEach(
		function ( r ) {
			r.addEventListener(
				'change',
				function () {
					modeSelect.value      = this.value; // Update highlight.
					postRow.style.display = this.value === 'post' ? '' : 'none';
				}
			);
		}
	);
	// Click on the span also triggers the radio.
	modeWrap.querySelectorAll( '.manly-webp-mode-btn' ).forEach(
		function ( span ) {
			span.addEventListener(
				'click',
				function () {
					var radio     = this.previousElementSibling;
					radio.checked = true;
					radio.dispatchEvent( new Event( 'change' ) );
				}
			);
		}
	);

	qualitySldr.addEventListener(
		'input',
		function () {
			qualityVal.textContent = this.value;
		}
	);

	btnAnalyze.addEventListener(
		'click',
		function () {
			btnAnalyze.disabled        = true;
			analyzingSpn.style.display = '';
			statsP.style.display       = 'none';

			postData( 'manly_webp_converter_analyze', {} ).then(
				function ( resp ) {
					analyzingSpn.style.display = 'none';
					btnAnalyze.disabled        = false;
					btnAnalyze.textContent     = i18n.reanalyze;

					if ( ! resp.success ) {
							statsP.innerHTML     = '<span style="color:#d63638;">Error: ' + ( resp.data || 'Unknown' ) + '</span>';
							statsP.style.display = '';
							return;
					}

					var d = resp.data;

					if ( d.non_webp === 0 ) {
						statsP.innerHTML = '<span style="color:#46b450;">&#10003; All images are already WebP.</span>'
							+ ' &mdash; <strong>' + d.webp + '</strong> in library.';
					} else {
						statsP.innerHTML = '<strong>' + d.non_webp + '</strong> non-WebP in library'
						+ ' (<strong>' + d.webp + '</strong> already WebP)'
						+ ' \u2014 <strong>' + d.in_content + '</strong> used in pages/posts/products,'
						+ ' <strong>' + d.not_in_content + '</strong> in library but not on any page.';
					}
					statsP.style.display = '';

					postSel.innerHTML = '';
					if ( d.posts && d.posts.length ) {
						d.posts.forEach(
							function ( p ) {
								var opt         = document.createElement( 'option' );
								opt.value       = p.id;
								opt.dataset.url = p.url;
								opt.textContent = p.title + ' [' + p.type + '] \u2014 ' + p.count + ' non-WebP';
								postSel.appendChild( opt );
							}
						);
						postSel.style.display  = '';
						postLink.style.display = '';
						noPosts.style.display  = 'none';
						updatePostLink();
					} else {
						postSel.style.display  = 'none';
						postLink.style.display = 'none';
						noPosts.style.display  = '';
					}
				}
			).catch(
				function ( err ) {
					analyzingSpn.style.display = 'none';
					btnAnalyze.disabled        = false;
					statsP.innerHTML           = '<span style="color:#d63638;">Error: ' + err.message + '</span>';
					statsP.style.display       = '';
				}
			);
		}
	);

	function log( msg ) {
		var ts            = new Date().toLocaleTimeString();
		logArea.value    += '[' + ts + '] ' + msg + '\n';
		logArea.scrollTop = logArea.scrollHeight;
	}

	var stopRequested = false;

	btnStart.addEventListener(
		'click',
		function () {
			if ( btnStart.dataset.running === '1' ) {
				stopRequested        = true;
				btnStart.disabled    = true;
				btnStart.textContent = i18n.stopping;
				return;
			}

			var mode = modeSelect.value;

			// Guard: post mode requires the user to analyze first.
			if ( mode === 'post' && ( ! postSel.options.length || postSel.style.display === 'none' ) ) {
				window.alert( i18n.analyzeFirst );
				return;
			}

			stopRequested            = false;
			btnStart.disabled        = false;
			btnStart.dataset.running = '1';
			btnStart.textContent     = i18n.stop;
			btnStart.classList.replace( 'button-primary', 'button-secondary' );
			stopNote.style.display    = '';
			logArea.value             = '';
			progressDiv.style.display = 'block';
			barDiv.style.width        = '0%';

			var postId  = postSel.options.length ? postSel.value : 0;
			var quality = qualitySldr.value;
			var keep    = keepChk.checked;

			log( 'Fetching attachment list (mode: ' + mode + ')...' );

			function resetBtn() {
				btnStart.dataset.running = '';
				btnStart.disabled        = false;
				btnStart.textContent     = i18n.start;
				btnStart.classList.replace( 'button-secondary', 'button-primary' );
				stopNote.style.display = 'none';
			}

			postData(
				'manly_webp_converter_get_attachments',
				{
					mode: mode,
					post_id: postId
				}
			).then(
				function ( resp ) {
					if ( ! resp.success ) {
							log( 'ERROR: ' + ( resp.data || 'Unknown error' ) );
							resetBtn();
							return;
					}

					var ids   = resp.data.attachment_ids;
					var total = ids.length;
					log( 'Found ' + total + ' attachment(s) to convert.' );

					var done       = 0;
					var queued     = 0;
					var totalSaved = 0;
					var totalFiles = 0;

					function convertOne() {
						if ( stopRequested || queued >= total ) {
							if ( stopRequested ) {
								log( '--- STOPPED --- ' + totalFiles + ' files, ' + formatBytes( totalSaved ) + ' saved.' );
								statusP2.textContent = 'Stopped at ' + done + ' / ' + total + '.';
							}
							resetBtn();
							return;
						}

						var idx = queued;
						var id  = ids[ idx ];
						queued++;

						log( 'Converting attachment #' + id + ' (' + ( idx + 1 ) + '/' + total + ')...' );

						postData(
							'manly_webp_converter_convert_single',
							{
								attachment_id: id,
								quality: quality,
								keep_originals: keep ? '1' : '',
								mode: mode,
								post_id: postId
							}
						).then(
							function ( resp ) {
								done++;
								var pct            = Math.round( ( done / total ) * 100 );
								barDiv.style.width = pct + '%';

								if ( resp.success ) {
										totalSaved += resp.data.bytes_saved || 0;
										totalFiles += resp.data.files_converted || 0;
										log( '  OK #' + id + ': ' + resp.data.message );
								} else {
										var msg = resp.data && resp.data.message ? resp.data.message : ( resp.data || 'Unknown error' );
										log( '  SKIP #' + id + ': ' + msg );
								}

								statusP2.textContent = done + ' / ' + total + ' processed.';

								if ( done >= total ) {
										barDiv.style.width   = '100%';
										statusP2.textContent = 'Complete! ' + totalFiles + ' files converted, '
										+ formatBytes( totalSaved ) + ' saved.';
										log( '--- DONE --- ' + totalFiles + ' files, ' + formatBytes( totalSaved ) + ' saved.' );
										resetBtn();
								} else {
									convertOne();
								}
							}
						).catch(
							function ( err ) {
								done++;
								log( '  ERROR #' + id + ': ' + err.message );
								if ( done >= total ) {
										resetBtn();
								} else {
										convertOne();
								}
							}
						);
					}

					log( 'Processing sequentially...' );
					convertOne();
				}
			).catch(
				function ( err ) {
					log( 'ERROR: ' + err.message );
					resetBtn();
				}
			);
		}
	);

	function formatBytes( b ) {
		if ( b < 1024 ) {
			return b + ' B'; }
		if ( b < 1048576 ) {
			return ( b / 1024 ).toFixed( 1 ) + ' KB'; }
		return ( b / 1048576 ).toFixed( 2 ) + ' MB';
	}
}() );
