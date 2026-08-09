/**
 * Media Library behavior for EveryPage: the "Share via EveryPage" triggers
 * (row action, attachment details field), the share modal (copy link / QR /
 * copy embed), and the dry-run "Replace links in content" tool.
 *
 * Vanilla JS, same idioms as admin.js. Share triggers are real links to the
 * attachment edit screen (#everypage-share); where this script is loaded the
 * click is intercepted and handled in place. All server work goes through
 * admin-ajax with nonces; the API key never reaches the browser.
 */
( function () {
	var cfg = window.EveryPageMedia;
	var shareModal = document.getElementById( 'everypage-media-share-modal' );
	var replaceModal = document.getElementById( 'everypage-media-replace-modal' );
	if ( ! cfg || ! shareModal || ! replaceModal ) {
		return;
	}

	var i18n = cfg.i18n || {};

	/* ---- Share modal elements ---- */
	var shareName   = document.getElementById( 'ep-m-share-name' );
	var shareBusy   = document.getElementById( 'ep-m-share-busy' );
	var shareError  = document.getElementById( 'ep-m-share-error' );
	var shareBody   = document.getElementById( 'ep-m-share-body' );
	var shareStatus = document.getElementById( 'ep-m-share-status' );
	var linkInput   = document.getElementById( 'ep-m-link' );
	var copyLinkBtn = document.getElementById( 'ep-m-copy-link' );
	var copyEmbedBtn = document.getElementById( 'ep-m-copy-embed' );
	var qrImg       = document.getElementById( 'ep-m-qr-img' );
	var qrFrame     = qrImg ? qrImg.parentNode : null;
	var qrDownload  = document.getElementById( 'ep-m-qr-download' );
	var filesLink   = document.getElementById( 'ep-m-files-link' );
	var replaceOpen = document.getElementById( 'ep-m-replace-open' );

	/* ---- Replace modal elements ---- */
	var repName    = document.getElementById( 'ep-m-replace-name' );
	var repUrls    = document.getElementById( 'ep-m-replace-urls' );
	var repBusy    = document.getElementById( 'ep-m-replace-busy' );
	var repError   = document.getElementById( 'ep-m-replace-error' );
	var repEmpty   = document.getElementById( 'ep-m-replace-empty' );
	var repWrap    = document.getElementById( 'ep-m-replace-tablewrap' );
	var repRows    = document.getElementById( 'ep-m-replace-rows' );
	var repNote    = document.getElementById( 'ep-m-replace-note' );
	var repAdmin   = document.getElementById( 'ep-m-replace-adminonly' );
	var repConfirm = document.getElementById( 'ep-m-replace-confirm' );
	var repThPost  = document.getElementById( 'ep-m-replace-th-post' );
	var repThCount = document.getElementById( 'ep-m-replace-th-count' );

	var current = null; // The last successful share payload.
	var scanned = null; // The last dry-run result (post ids awaiting confirm).

	/* ---- Small helpers (admin.js idioms) ---- */

	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( str ).replace( /%(\d+)\$s|%s/g, function ( m, n ) {
			return undefined !== ( n ? args[ n - 1 ] : args[ i ] ) ? ( n ? args[ n - 1 ] : args[ i++ ] ) : m;
		} );
	}

	function legacyCopy( text ) {
		return new Promise( function ( resolve, reject ) {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'fixed';
			ta.style.left = '-9999px';
			document.body.appendChild( ta );
			ta.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( err ) {
				ok = false;
			}
			document.body.removeChild( ta );
			if ( ok ) {
				resolve();
			} else {
				reject();
			}
		} );
	}

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text ).catch( function () {
				return legacyCopy( text );
			} );
		}
		return legacyCopy( text );
	}

	function post( action, nonce, data ) {
		var body = new window.FormData();
		body.append( 'action', action );
		body.append( '_wpnonce', nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			if ( Array.isArray( data[ k ] ) ) {
				data[ k ].forEach( function ( v ) {
					body.append( k + '[]', v );
				} );
			} else {
				body.append( k, data[ k ] );
			}
		} );
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( resp ) {
			return resp.json().catch( function () {
				return { success: false };
			} );
		} );
	}

	function qrEndpoint( uuid, download ) {
		// The QR proxy keys on the canonical UUID (hex/hyphen filter upstream).
		var u = cfg.ajaxUrl +
			'?action=everypage_qr' +
			'&uuid=' + encodeURIComponent( uuid ) +
			'&_wpnonce=' + encodeURIComponent( cfg.qrNonce );
		return download ? u + '&download=1' : u;
	}

	/* ---- Modal manager (open stack: replace opens above share) ---- */

	var openStack = [];

	function openModal( modal, trigger ) {
		openStack.push( { modal: modal, trigger: trigger || null } );
		modal.hidden = false;
		document.body.classList.add( 'everypage-modal-open' );
		var x = modal.querySelector( '.everypage-modal-x' );
		if ( x ) {
			x.focus();
		}
	}

	function closeModal( modal ) {
		for ( var i = openStack.length - 1; i >= 0; i-- ) {
			if ( openStack[ i ].modal === modal ) {
				var entry = openStack.splice( i, 1 )[ 0 ];
				modal.hidden = true;
				if ( entry.trigger ) {
					entry.trigger.focus();
				}
				break;
			}
		}
		if ( ! openStack.length ) {
			document.body.classList.remove( 'everypage-modal-open' );
		}
	}

	[ shareModal, replaceModal ].forEach( function ( modal ) {
		Array.prototype.forEach.call( modal.querySelectorAll( '[data-close]' ), function ( el ) {
			el.addEventListener( 'click', function () {
				closeModal( modal );
			} );
		} );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( ! openStack.length ) {
			return;
		}
		var top = openStack[ openStack.length - 1 ].modal;
		if ( 'Escape' === e.key ) {
			e.stopPropagation(); // Keep the WP media modal open underneath.
			closeModal( top );
			return;
		}
		// Keep focus inside the top dialog while it is open.
		if ( 'Tab' === e.key ) {
			var focusable = Array.prototype.filter.call(
				top.querySelectorAll( 'button, [href], input, select, textarea' ),
				function ( el ) {
					return ! el.disabled && null !== el.offsetParent;
				}
			);
			if ( ! focusable.length ) {
				return;
			}
			var first = focusable[ 0 ];
			var last  = focusable[ focusable.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		}
	}, true );

	/* ---- Share flow ---- */

	function showShareError( msg ) {
		shareError.textContent = msg || i18n.genericError || 'Error.';
		shareError.hidden = false;
	}

	function updateListRow( d ) {
		// Reflect the new share in the Media list column without a reload.
		var cell = document.querySelector( '#post-' + d.id + ' .column-everypage' );
		if ( cell ) {
			cell.textContent = i18n.sharedCell || 'Shared';
		}
	}

	function populateShare( d ) {
		current = d;
		scanned = null;
		shareName.textContent = d.name || '';
		shareStatus.textContent = d.existing ? ( i18n.alreadyShared || '' ) : ( i18n.justShared || '' );
		linkInput.value = d.shareUrl;
		copyLinkBtn.setAttribute( 'data-copy', d.shareUrl );
		copyEmbedBtn.setAttribute( 'data-copy', d.embed );
		if ( qrFrame ) {
			qrFrame.classList.add( 'is-loading' );
		}
		qrImg.onload = function () {
			if ( qrFrame ) {
				qrFrame.classList.remove( 'is-loading' );
			}
		};
		qrImg.src = qrEndpoint( d.uuid, false );
		qrDownload.href = qrEndpoint( d.uuid, true );
		qrDownload.setAttribute( 'download', 'everypage-' + d.uuid + '-qr.png' );
		filesLink.href = d.filesUrl;
		filesLink.parentNode.hidden = ! d.canFiles;
		shareBody.hidden = false;
		if ( ! d.existing ) {
			updateListRow( d );
		}
	}

	function openShare( id, trigger ) {
		current = null;
		shareName.textContent = '';
		shareStatus.textContent = '';
		shareBody.hidden = true;
		shareError.hidden = true;
		shareError.textContent = '';
		shareBusy.textContent = i18n.sharing || 'Sharing…';
		shareBusy.hidden = false;
		qrImg.removeAttribute( 'src' );
		openModal( shareModal, trigger );
		post( 'everypage_media_share', cfg.nonce, { attachment: id } ).then( function ( res ) {
			shareBusy.hidden = true;
			if ( res && res.success && res.data && res.data.uuid ) {
				populateShare( res.data );
			} else {
				showShareError( res && res.data && res.data.message );
			}
		} ).catch( function () {
			shareBusy.hidden = true;
			showShareError();
		} );
	}

	// Delegated: triggers are rendered by PHP in list rows and (dynamically,
	// via Backbone) in the media modal's details pane.
	document.addEventListener( 'click', function ( e ) {
		var t = e.target.closest ? e.target.closest( '.everypage-media-share' ) : null;
		if ( t ) {
			e.preventDefault();
			openShare( parseInt( t.getAttribute( 'data-id' ), 10 ) || 0, t );
			return;
		}
		var c = e.target.closest ? e.target.closest( '.everypage-media-modal [data-copy]' ) : null;
		if ( c ) {
			copyText( c.getAttribute( 'data-copy' ) || '' ).then(
				function () {
					c.classList.add( 'is-copied' );
					var isText = 'ep-m-copy-embed' === c.id;
					var old = isText ? c.textContent : c.getAttribute( 'title' );
					if ( isText ) {
						c.textContent = i18n.copied || 'Copied!';
					} else {
						c.setAttribute( 'title', i18n.copied || 'Copied!' );
					}
					window.setTimeout( function () {
						c.classList.remove( 'is-copied' );
						if ( isText ) {
							c.textContent = i18n.copyEmbed || old;
						} else if ( old ) {
							c.setAttribute( 'title', old );
						}
					}, 1400 );
				},
				function () {
					window.alert( i18n.copyFailed || 'Copy failed.' );
				}
			);
		}
	} );

	/* ---- Replace-links flow (dry run, then explicit confirm) ---- */

	function resetReplaceModal() {
		scanned = null;
		repName.textContent = current ? current.name || '' : '';
		repUrls.hidden = true;
		repUrls.textContent = '';
		repError.hidden = true;
		repError.textContent = '';
		repEmpty.hidden = true;
		repEmpty.textContent = '';
		repWrap.hidden = true;
		repRows.textContent = '';
		repNote.hidden = true;
		repAdmin.hidden = true;
		repAdmin.textContent = '';
		repConfirm.disabled = true;
		repConfirm.hidden = false;
		repConfirm.textContent = '…';
		repThPost.textContent = i18n.linksHeading || 'Post or page';
		repThCount.textContent = i18n.countHeading || 'Links';
	}

	function renderScan( d ) {
		scanned = d;
		repUrls.textContent = d.oldUrl + ' → ' + d.newUrl;
		repUrls.hidden = false;
		if ( ! d.posts.length ) {
			repEmpty.textContent = i18n.noLinks || 'No posts or pages link to this PDF.';
			repEmpty.hidden = false;
			repConfirm.hidden = true;
			return;
		}
		d.posts.forEach( function ( p ) {
			var tr = document.createElement( 'tr' );
			tr.setAttribute( 'data-post', String( p.id ) );

			var tdTitle = document.createElement( 'td' );
			var a = document.createElement( 'a' );
			a.href = p.editUrl || '#';
			a.target = '_blank';
			a.rel = 'noopener';
			a.textContent = p.title || '#' + p.id;
			tdTitle.appendChild( a );
			tr.appendChild( tdTitle );

			var tdCount = document.createElement( 'td' );
			tdCount.textContent = String( p.matches );
			tr.appendChild( tdCount );

			var tdStatus = document.createElement( 'td' );
			tdStatus.className = 'ep-replace-status';
			tr.appendChild( tdStatus );

			repRows.appendChild( tr );
		} );
		repWrap.hidden = false;
		repNote.hidden = false;
		repConfirm.textContent = fmt(
			i18n.replaceConfirm || 'Replace %1$s links across %2$s posts',
			String( d.totalMatches ),
			String( d.posts.length )
		);
		if ( cfg.canReplace && d.canReplace ) {
			repConfirm.disabled = false;
		} else {
			repAdmin.textContent = i18n.adminOnly || '';
			repAdmin.hidden = false;
		}
	}

	replaceOpen.addEventListener( 'click', function () {
		if ( ! current ) {
			return;
		}
		resetReplaceModal();
		repBusy.textContent = i18n.scanning || 'Scanning…';
		repBusy.hidden = false;
		openModal( replaceModal, replaceOpen );
		post( 'everypage_media_scan', cfg.nonce, { attachment: current.id } ).then( function ( res ) {
			repBusy.hidden = true;
			if ( res && res.success && res.data ) {
				renderScan( res.data );
			} else {
				repError.textContent = ( res && res.data && res.data.message ) || i18n.genericError;
				repError.hidden = false;
				repConfirm.hidden = true;
			}
		} ).catch( function () {
			repBusy.hidden = true;
			repError.textContent = i18n.genericError;
			repError.hidden = false;
			repConfirm.hidden = true;
		} );
	} );

	function renderResults( results ) {
		( results || [] ).forEach( function ( r ) {
			var tr = repRows.querySelector( 'tr[data-post="' + r.id + '"]' );
			if ( ! tr ) {
				return;
			}
			var cell = tr.querySelector( '.ep-replace-status' );
			if ( ! cell ) {
				return;
			}
			if ( r.ok && r.replaced > 0 ) {
				cell.textContent = '✓ ' + fmt( i18n.replacedIn || '%s replaced', String( r.replaced ) );
				cell.classList.add( 'is-ok' );
			} else if ( r.ok ) {
				cell.textContent = r.message || ( i18n.noChanges || '' );
			} else {
				cell.textContent = r.message || i18n.genericError;
				cell.classList.add( 'is-bad' );
			}
		} );
	}

	repConfirm.addEventListener( 'click', function () {
		if ( ! current || ! scanned || ! scanned.posts.length ) {
			return;
		}
		repConfirm.disabled = true;
		repConfirm.textContent = i18n.replacing || 'Replacing…';
		repError.hidden = true;
		var ids = scanned.posts.map( function ( p ) {
			return p.id;
		} );
		post( 'everypage_media_replace', cfg.replaceNonce, {
			attachment: current.id,
			posts: ids
		} ).then( function ( res ) {
			if ( res && res.success && res.data ) {
				renderResults( res.data.results );
				repConfirm.textContent = i18n.replaceDone || 'Done';
			} else {
				repError.textContent = ( res && res.data && res.data.message ) || i18n.genericError;
				repError.hidden = false;
				repConfirm.disabled = false;
				repConfirm.textContent = fmt(
					i18n.replaceConfirm || 'Replace %1$s links across %2$s posts',
					String( scanned.totalMatches ),
					String( scanned.posts.length )
				);
			}
		} ).catch( function () {
			repError.textContent = i18n.genericError;
			repError.hidden = false;
			repConfirm.disabled = false;
		} );
	} );

	/* ---- Auto-open from the #everypage-share fragment (attachment edit) ---- */

	if ( '#everypage-share' === window.location.hash ) {
		var first = document.querySelector( '.everypage-media-share' );
		if ( first ) {
			openShare( parseInt( first.getAttribute( 'data-id' ), 10 ) || 0, first );
		}
	}
}() );
