/**
 * Files-page behavior for the EveryPage admin: the QR-code modal, the
 * copy-link / copy-embed buttons, and the per-file settings drawer.
 *
 * Everything is vanilla JS. The QR PNG is proxied through admin-ajax and the
 * drawer talks to the everypage/v1 REST proxy (cookie + X-WP-Nonce), so the
 * API key never reaches the browser.
 */

/* ---- QR modal --------------------------------------------------------- */
( function () {
	var modal = document.getElementById( 'everypage-qr-modal' );
	if ( ! modal || ! window.EveryPageQR ) {
		return;
	}

	var cfg     = window.EveryPageQR;
	var frame   = modal.querySelector( '.everypage-qr-frame' );
	var img     = document.getElementById( 'ep-qr-img' );
	var nameEl  = document.getElementById( 'ep-qr-name' );
	var dlBtn   = document.getElementById( 'ep-qr-download' );
	var lastTrigger = null;

	function endpoint( uuid, download ) {
		var u = cfg.ajaxUrl +
			'?action=everypage_qr' +
			'&uuid=' + encodeURIComponent( uuid ) +
			'&_wpnonce=' + encodeURIComponent( cfg.nonce );
		return download ? u + '&download=1' : u;
	}

	function open( uuid, label, trigger ) {
		lastTrigger = trigger || null;
		nameEl.textContent = label || '';
		frame.classList.add( 'is-loading' );
		img.removeAttribute( 'src' );
		img.onload = function () {
			frame.classList.remove( 'is-loading' );
		};
		img.src = endpoint( uuid, false );
		dlBtn.href = endpoint( uuid, true );
		dlBtn.setAttribute( 'download', 'everypage-' + uuid + '-qr.png' );
		modal.hidden = false;
		document.body.classList.add( 'everypage-modal-open' );
		var x = modal.querySelector( '.everypage-modal-x' );
		if ( x ) {
			x.focus();
		}
	}

	function close() {
		modal.hidden = true;
		img.removeAttribute( 'src' );
		document.body.classList.remove( 'everypage-modal-open' );
		if ( lastTrigger ) {
			lastTrigger.focus();
		}
	}

	Array.prototype.forEach.call(
		document.querySelectorAll( '.everypage-qr-btn' ),
		function ( btn ) {
			btn.addEventListener( 'click', function () {
				open( btn.getAttribute( 'data-uuid' ), btn.getAttribute( 'data-name' ), btn );
			} );
		}
	);

	Array.prototype.forEach.call(
		modal.querySelectorAll( '[data-close]' ),
		function ( el ) {
			el.addEventListener( 'click', close );
		}
	);

	document.addEventListener( 'keydown', function ( e ) {
		if ( modal.hidden ) {
			return;
		}
		if ( 'Escape' === e.key ) {
			close();
			return;
		}
		// Keep focus inside the dialog while it is open.
		if ( 'Tab' === e.key ) {
			var focusable = modal.querySelectorAll( 'button, [href]' );
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
	} );
}() );

/* ---- Copy link / copy embed ------------------------------------------- */
( function () {
	var cfg  = window.EveryPageAdmin || {};
	var i18n = cfg.i18n || {};

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

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.everypage-copy-btn' ) : null;
		if ( ! btn ) {
			return;
		}
		var text = btn.getAttribute( 'data-copy' ) || '';
		copyText( text ).then(
			function () {
				btn.classList.add( 'is-copied' );
				var oldTitle = btn.getAttribute( 'title' );
				btn.setAttribute( 'title', i18n.copied || 'Copied!' );
				window.setTimeout( function () {
					btn.classList.remove( 'is-copied' );
					if ( oldTitle ) {
						btn.setAttribute( 'title', oldTitle );
					}
				}, 1400 );
			},
			function () {
				window.alert( i18n.copyFailed || 'Copy failed.' );
			}
		);
	} );
}() );

/* ---- Per-file settings drawer ----------------------------------------- */
( function () {
	var cfg   = window.EveryPageAdmin;
	var modal = document.getElementById( 'everypage-settings-modal' );
	if ( ! modal || ! cfg || ! cfg.restUrl ) {
		return;
	}

	var i18n  = cfg.i18n || {};
	var TIERS = { free: 0, basic: 1, pro: 2 };
	var EXPIRY_CAP_DAYS = { free: 7, basic: 365 };
	var plan  = cfg.plan || 'free';

	var state       = null;  // The freshest shaped file object.
	var initial     = {};    // control id -> value snapshot at populate time.
	var gf          = [];    // gateFields working copy [{key,label,type,required}].
	var gfInitial   = '[]';
	var dead        = false; // 410: expired/burned - read-only.
	var currentId   = '';
	var lastTrigger = null;

	var form      = document.getElementById( 'ep-settings-form' );
	var loading   = document.getElementById( 'ep-settings-loading' );
	var banner    = document.getElementById( 'ep-settings-banner' );
	var nameEl    = document.getElementById( 'ep-settings-name' );
	var errorEl   = document.getElementById( 'ep-settings-error' );
	var statusEl  = document.getElementById( 'ep-settings-status' );
	var saveBtn   = document.getElementById( 'ep-settings-save' );

	var modeEl        = document.getElementById( 'ep-s-mode' );
	var bgExtrasEl    = document.getElementById( 'ep-s-bgimage-extras' );
	var logoGroupEl   = document.getElementById( 'ep-s-logo-group' );
	var passwordEl    = document.getElementById( 'ep-s-password' );
	var passwordState = document.getElementById( 'ep-s-password-state' );
	var passwordRmWrap = document.getElementById( 'ep-s-password-remove-wrap' );
	var passwordRmEl  = document.getElementById( 'ep-s-password-remove' );
	var viewLimitMeta = document.getElementById( 'ep-s-viewlimit-meta' );
	var requireEmailEl = document.getElementById( 'ep-s-requireemail' );
	var gateDomainsEl = document.getElementById( 'ep-s-gatedomains' );
	var gateFormEl    = document.getElementById( 'ep-s-gateform' );
	var gateFieldsBox = document.getElementById( 'ep-s-gatefields' );
	var gfRowsEl      = document.getElementById( 'ep-s-gf-rows' );
	var deleteAtEl    = document.getElementById( 'ep-s-deleteat' );
	var expiryCapEl   = document.getElementById( 'ep-s-expiry-cap' );
	var neverExpireEl = document.getElementById( 'ep-s-neverexpire' );
	var slugEl        = document.getElementById( 'ep-s-slug' );
	var slugHintEl    = document.getElementById( 'ep-s-slug-hint' );
	var slugErrorEl   = document.getElementById( 'ep-s-slug-error' );
	var prFromEl      = document.getElementById( 'ep-s-pr-from' );
	var prToEl        = document.getElementById( 'ep-s-pr-to' );

	var fieldEls = Array.prototype.slice.call( modal.querySelectorAll( '[data-field]' ) );
	var vsEls    = Array.prototype.slice.call( modal.querySelectorAll( '[data-vs]' ) );
	var specialEls = [ passwordEl, passwordRmEl, gateDomainsEl, prFromEl, prToEl ];

	/* ---- Small helpers ---- */

	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( str ).replace( /%(\d+)\$s|%s/g, function ( m, n ) {
			return undefined !== ( n ? args[ n - 1 ] : args[ i ] ) ? ( n ? args[ n - 1 ] : args[ i++ ] ) : m;
		} );
	}

	function hasTier( need ) {
		return ( TIERS[ plan ] || 0 ) >= ( TIERS[ need ] || 0 );
	}

	function api( path, opts ) {
		opts = opts || {};
		opts.headers = opts.headers || {};
		opts.headers[ 'X-WP-Nonce' ] = cfg.restNonce;
		if ( opts.body ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
		}
		opts.credentials = 'same-origin';
		return fetch( cfg.restUrl + path, opts );
	}

	function read( el ) {
		if ( 'checkbox' === el.type ) {
			return !! el.checked;
		}
		if ( 'number' === el.type ) {
			var n = parseInt( el.value, 10 );
			return isNaN( n ) ? 0 : n;
		}
		if ( 'color' === el.type ) {
			return ( el.value || '' ).toUpperCase();
		}
		return el.value || '';
	}

	function getPath( obj, path ) {
		var parts = path.split( '.' );
		var cur = obj;
		for ( var i = 0; i < parts.length; i++ ) {
			if ( ! cur || 'object' !== typeof cur ) {
				return undefined;
			}
			cur = cur[ parts[ i ] ];
		}
		return cur;
	}

	function setPath( obj, path, value ) {
		var parts = path.split( '.' );
		var cur = obj;
		for ( var i = 0; i < parts.length - 1; i++ ) {
			if ( ! cur[ parts[ i ] ] || 'object' !== typeof cur[ parts[ i ] ] ) {
				cur[ parts[ i ] ] = {};
			}
			cur = cur[ parts[ i ] ];
		}
		cur[ parts[ parts.length - 1 ] ] = value;
	}

	function clone( obj ) {
		return JSON.parse( JSON.stringify( obj || {} ) );
	}

	function toLocalInput( iso ) {
		if ( ! iso ) {
			return '';
		}
		var d = new Date( iso );
		if ( isNaN( d.getTime() ) ) {
			return '';
		}
		var p = function ( n ) {
			return ( n < 10 ? '0' : '' ) + n;
		};
		return d.getFullYear() + '-' + p( d.getMonth() + 1 ) + '-' + p( d.getDate() ) +
			'T' + p( d.getHours() ) + ':' + p( d.getMinutes() );
	}

	function controlDefault( el ) {
		if ( 'checkbox' === el.type ) {
			return el.hasAttribute( 'data-default-checked' );
		}
		if ( 'color' === el.type ) {
			return ( el.getAttribute( 'data-default' ) || '#ffffff' ).toUpperCase();
		}
		if ( 'number' === el.type ) {
			return parseInt( el.getAttribute( 'data-default' ) || '0', 10 );
		}
		return el.getAttribute( 'data-default' ) || '';
	}

	/* ---- Values into the controls ---- */

	// Normalized "what the control should show" for a viewerSettings path,
	// mapping the server's store-only-deviations form back to UI defaults.
	function vsDisplayValue( file, path, el ) {
		var v = getPath( file.viewerSettings || {}, path );
		if ( 'background.color' === path ) {
			return ( v || '#FFFFFF' ).toUpperCase();
		}
		if ( 'brand.accentColor' === path ) {
			return ( v || '#000000' ).toUpperCase();
		}
		if ( 'flip.speedMs' === path ) {
			return v || 450;
		}
		if ( 'swipe.intervalMs' === path ) {
			return v || 6000;
		}
		if ( 'page.coverAlone' === path ) {
			return false !== v; // default true
		}
		if ( 'flip.layout' === path ) {
			// The select's default option is value "" (Adaptive); the server
			// stores the explicit word. Sending "" back is safe: the backend
			// drops it and adaptive is the serve-time default.
			return 'adaptive' === v ? '' : v || '';
		}
		if ( 'checkbox' === el.type ) {
			return !! v;
		}
		if ( 'number' === el.type ) {
			return v || 0;
		}
		return v || '';
	}

	function setControl( el, v ) {
		if ( 'checkbox' === el.type ) {
			el.checked = !! v;
		} else {
			el.value = String( v );
		}
	}

	function snapshot() {
		initial = {};
		fieldEls.concat( vsEls, specialEls ).forEach( function ( el ) {
			initial[ el.id ] = read( el );
		} );
		gfInitial = JSON.stringify( gf );
	}

	function populate( file ) {
		state = file;
		currentId = file.uuid;
		nameEl.textContent = file.originalName || '';

		// Top-level fields.
		setControl( modeEl, file.viewerMode || 'standard' );
		document.getElementById( 'ep-s-download' ).checked = !! file.allowDownload;
		document.getElementById( 'ep-s-watermark' ).checked = !! file.watermark;
		document.getElementById( 'ep-s-notify' ).checked = !! file.notifyOnView;
		document.getElementById( 'ep-s-receipt' ).checked = !! file.askReceipt;
		requireEmailEl.checked = !! file.requireEmail;
		document.getElementById( 'ep-s-viewlimit' ).value = String( file.viewLimit || 0 );
		deleteAtEl.value = toLocalInput( file.deleteAt );
		neverExpireEl.checked = ! file.deleteAt;
		slugEl.value = file.slug || '';
		prFromEl.value = String( ( file.pageRange && file.pageRange.from ) || 0 );
		prToEl.value = String( ( file.pageRange && file.pageRange.to ) || 0 );
		gateDomainsEl.value = ( file.gateDomains || [] ).join( ', ' );

		// Viewer settings paths.
		vsEls.forEach( function ( el ) {
			setControl( el, vsDisplayValue( file, el.getAttribute( 'data-vs' ), el ) );
		} );

		// Password: never prefilled - only the "is set" state.
		passwordEl.value = '';
		passwordRmEl.checked = false;
		passwordState.hidden = ! file.protected;
		passwordState.textContent = file.protected ? ( i18n.passwordSet || '' ) : '';
		passwordRmWrap.hidden = ! file.protected;
		passwordEl.placeholder = file.protected ? ( i18n.replacePassword || '' ) : ( i18n.setPassword || '' );

		// View limit consumption / burned state.
		if ( file.viewLimit > 0 ) {
			viewLimitMeta.hidden = false;
			viewLimitMeta.textContent = file.burnedAt
				? ( i18n.burned || '' )
				: fmt( i18n.viewsUsed || '%1$s / %2$s', String( file.viewsConsumed || 0 ), String( file.viewLimit ) );
		} else {
			viewLimitMeta.hidden = true;
			viewLimitMeta.textContent = '';
		}

		// Lead-capture form working copy.
		gf = clone( file.gateFields || [] );
		if ( ! Array.isArray( gf ) ) {
			gf = [];
		}
		gateFormEl.checked = gf.length > 0;
		gateFieldsBox.hidden = ! gf.length;
		renderGfRows();

		// Conditional groups.
		var vs = file.viewerSettings || {};
		bgExtrasEl.hidden = ! ( vs.background && 'image' === vs.background.type );
		logoGroupEl.hidden = ! ( vs.logo && vs.logo.assetId );
		applyModeVisibility();

		// Expiry cap hint (client-side guard; the backend re-checks).
		var capDays = EXPIRY_CAP_DAYS[ plan ];
		if ( capDays ) {
			expiryCapEl.textContent = fmt( i18n.expiryCap || '%s', String( capDays ) );
			deleteAtEl.max = toLocalInput( new Date( Date.now() + capDays * 86400000 ).toISOString() );
		} else {
			expiryCapEl.textContent = i18n.expiryNoCap || '';
			deleteAtEl.removeAttribute( 'max' );
		}

		// Dead links (expired but not yet swept, or burned) are read-only:
		// the upstream PUT answers 410, so offer nothing destructive.
		dead = !! file.burnedAt || ( !! file.deleteAt && new Date( file.deleteAt ).getTime() < Date.now() );
		banner.hidden = ! dead;
		banner.textContent = dead ? ( file.burnedAt ? ( i18n.burned || '' ) + ' ' + ( i18n.deadLink || '' ) : ( i18n.deadLink || '' ) ) : '';

		errorEl.hidden = true;
		errorEl.textContent = '';
		slugErrorEl.hidden = true;
		slugErrorEl.textContent = '';
		statusEl.textContent = '';

		applyTier();
		snapshot();
		updateSlugHint();
		refresh();
	}

	/* ---- Tier gating (visible but disabled, with an upgrade link) ---- */

	function applyTier() {
		Array.prototype.forEach.call( modal.querySelectorAll( '.ep-field[data-tier]' ), function ( w ) {
			var locked = ! hasTier( w.getAttribute( 'data-tier' ) );
			w.classList.toggle( 'is-locked', locked );
			Array.prototype.forEach.call( w.querySelectorAll( 'input, select, button' ), function ( c ) {
				c.disabled = locked;
			} );
			var up = w.querySelector( '.ep-upgrade' );
			if ( up ) {
				up.hidden = ! locked;
			}
		} );
		// Interaction locks that stack on top of the tier gate.
		syncInteractionLocks();
		renderGfRows();
	}

	function syncInteractionLocks() {
		if ( ! neverExpireEl.disabled && neverExpireEl.checked ) {
			deleteAtEl.disabled = true;
		}
		// A non-empty lead form forces the email gate on (server invariant);
		// mirror it so the UI can't promise otherwise.
		if ( gf.length && ! gateFormEl.disabled ) {
			requireEmailEl.checked = true;
			requireEmailEl.disabled = true;
		}
	}

	function applyModeVisibility() {
		var mode = modeEl.value;
		Array.prototype.forEach.call( modal.querySelectorAll( '[data-show-mode]' ), function ( w ) {
			w.hidden = w.getAttribute( 'data-show-mode' ) !== mode;
		} );
	}

	/* ---- Lead-capture form builder ---- */

	function deriveKey( label, type, skipIndex ) {
		var base = String( label ).toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' ).slice( 0, 40 );
		if ( ! /^[a-z]/.test( base ) ) {
			base = type;
		}
		var taken = {};
		gf.forEach( function ( f, i ) {
			if ( i !== skipIndex ) {
				taken[ f.key ] = true;
			}
		} );
		var key = base;
		for ( var n = 2; taken[ key ]; n++ ) {
			key = base.slice( 0, 37 ) + '_' + n;
		}
		return key;
	}

	function typeLabel( type ) {
		return {
			email: i18n.emailLabel || 'Email',
			text: i18n.textLabel || 'Text',
			phone: i18n.phoneLabel || 'Phone',
			company: i18n.companyLabel || 'Company'
		}[ type ] || type;
	}

	function renderGfRows() {
		if ( ! gfRowsEl ) {
			return;
		}
		var locked = gateFormEl.disabled;
		gfRowsEl.textContent = '';
		gf.forEach( function ( f, i ) {
			var row = document.createElement( 'div' );
			row.className = 'ep-gf-row';

			var label = document.createElement( 'input' );
			label.type = 'text';
			label.maxLength = 60;
			label.value = f.label || '';
			label.disabled = locked;
			label.addEventListener( 'input', function () {
				f.label = label.value;
				if ( 'email' !== f.type ) {
					f.key = deriveKey( label.value, f.type, i );
				}
				refresh();
			} );
			row.appendChild( label );

			var type = document.createElement( 'span' );
			type.className = 'ep-gf-type';
			type.textContent = typeLabel( f.type );
			row.appendChild( type );

			var reqWrap = document.createElement( 'label' );
			reqWrap.className = 'ep-gf-req';
			var req = document.createElement( 'input' );
			req.type = 'checkbox';
			req.checked = !! f.required;
			req.disabled = locked || 'email' === f.type; // Email always required.
			req.addEventListener( 'change', function () {
				f.required = req.checked;
				refresh();
			} );
			reqWrap.appendChild( req );
			reqWrap.appendChild( document.createTextNode( ' ' + ( i18n.requiredLabel || 'Required' ) ) );
			row.appendChild( reqWrap );

			if ( 'email' !== f.type ) {
				var rm = document.createElement( 'button' );
				rm.type = 'button';
				rm.className = 'button-link ep-gf-remove';
				rm.textContent = i18n.removeField || 'Remove';
				rm.disabled = locked;
				rm.addEventListener( 'click', function () {
					gf.splice( i, 1 );
					renderGfRows();
					refresh();
				} );
				row.appendChild( rm );
			}

			gfRowsEl.appendChild( row );
		} );

		Array.prototype.forEach.call( modal.querySelectorAll( '[data-gf-add]' ), function ( btn ) {
			btn.disabled = gateFormEl.disabled || gf.length >= 5;
		} );
	}

	gateFormEl.addEventListener( 'change', function () {
		if ( gateFormEl.checked ) {
			if ( ! gf.length ) {
				gf = [ { key: 'email', label: i18n.emailLabel || 'Email', type: 'email', required: true } ];
			}
			gateFieldsBox.hidden = false;
			requireEmailEl.checked = true;
			requireEmailEl.disabled = true;
		} else {
			gf = [];
			gateFieldsBox.hidden = true;
			if ( hasTier( 'pro' ) ) {
				requireEmailEl.disabled = false;
			}
		}
		renderGfRows();
		refresh();
	} );

	Array.prototype.forEach.call( modal.querySelectorAll( '[data-gf-add]' ), function ( btn ) {
		btn.addEventListener( 'click', function () {
			if ( gf.length >= 5 ) {
				return;
			}
			var type = btn.getAttribute( 'data-gf-add' );
			var label = typeLabel( type );
			gf.push( { key: deriveKey( label, type, -1 ), label: label, type: type, required: true } );
			renderGfRows();
			refresh();
		} );
	} );

	/* ---- Dirty tracking, counts, save ---- */

	function isDirty() {
		var d = false;
		fieldEls.concat( vsEls ).forEach( function ( el ) {
			if ( ! el.disabled && read( el ) !== initial[ el.id ] ) {
				d = true;
			}
		} );
		if ( passwordEl.value || passwordRmEl.checked ) {
			d = true;
		}
		[ gateDomainsEl, prFromEl, prToEl ].forEach( function ( el ) {
			if ( ! el.disabled && read( el ) !== initial[ el.id ] ) {
				d = true;
			}
		} );
		if ( JSON.stringify( gf ) !== gfInitial ) {
			d = true;
		}
		return d;
	}

	// Count of active (non-default) settings per section, shown on the
	// collapsed header so a folded section still tells you something is set.
	function countSection( section ) {
		var count = 0;
		Array.prototype.forEach.call( section.querySelectorAll( '.ep-field' ), function ( w ) {
			if ( w.contains( passwordEl ) ) {
				count += state && state.protected ? 1 : 0;
				return;
			}
			if ( w.contains( gateFormEl ) ) {
				count += gf.length ? 1 : 0;
				return;
			}
			if ( w.contains( deleteAtEl ) ) {
				return; // Expiry always exists below Pro; not a deviation.
			}
			if ( w.contains( neverExpireEl ) ) {
				count += state && ! state.deleteAt ? 1 : 0;
				return;
			}
			if ( w.contains( prFromEl ) ) {
				count += ( read( prFromEl ) || read( prToEl ) ) ? 1 : 0;
				return;
			}
			if ( w.contains( gateDomainsEl ) ) {
				count += gateDomainsEl.value.trim() ? 1 : 0;
				return;
			}
			var active = false;
			Array.prototype.forEach.call( w.querySelectorAll( '[data-field], [data-vs]' ), function ( el ) {
				if ( read( el ) !== controlDefault( el ) ) {
					active = true;
				}
			} );
			if ( active ) {
				count++;
			}
		} );
		return count;
	}

	function refreshCounts() {
		Array.prototype.forEach.call( modal.querySelectorAll( '.ep-section' ), function ( section ) {
			var badge = section.querySelector( '.ep-count' );
			if ( ! badge ) {
				return;
			}
			var n = countSection( section );
			badge.hidden = ! n;
			badge.textContent = n ? String( n ) : '';
		} );
	}

	function refresh() {
		var dirty = isDirty();
		saveBtn.disabled = dead || ! dirty;
		statusEl.textContent = dirty && ! dead ? ( i18n.unsaved || '' ) : statusEl.textContent === ( i18n.saved || 'Saved.' ) ? statusEl.textContent : '';
		refreshCounts();
	}

	function updateSlugHint() {
		var slug = slugEl.value.trim();
		if ( state && state.shareDomain && slug ) {
			slugHintEl.textContent = fmt( i18n.slugUrl || '%s', 'https://' + state.shareDomain + '/' + slug );
		} else {
			slugHintEl.textContent = i18n.slugHint || '';
		}
	}

	function showError( msg ) {
		errorEl.textContent = msg || i18n.genericError || 'Error.';
		errorEl.hidden = false;
	}

	var DOMAIN_RE = /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/;

	// Build the PUT payload from dirty controls only (omitted keys are
	// unchanged upstream). Returns null after showing an inline error.
	function buildPayload() {
		var p = {};

		fieldEls.forEach( function ( el ) {
			if ( el.disabled ) {
				return;
			}
			var f = el.getAttribute( 'data-field' );
			var v = read( el );
			if ( v === initial[ el.id ] ) {
				return;
			}
			if ( 'deleteAt' === f ) {
				if ( v ) {
					p.deleteAt = new Date( v ).toISOString();
				}
				// Emptying the field is "no change": clearing expiry is the
				// Pro neverExpire toggle, not a blank date.
			} else if ( 'viewLimit' === f ) {
				p.viewLimit = Math.max( 0, v );
			} else if ( 'slug' === f ) {
				p.slug = v.trim().toLowerCase();
			} else if ( 'neverExpire' === f ) {
				if ( v ) {
					p.neverExpire = true; // false is a no-op upstream.
				}
			} else {
				p[ f ] = v;
			}
		} );

		// Page range (Pro): {0,0} clears.
		if ( ! prFromEl.disabled && ( read( prFromEl ) !== initial[ prFromEl.id ] || read( prToEl ) !== initial[ prToEl.id ] ) ) {
			p.pageRange = { from: Math.max( 0, read( prFromEl ) ), to: Math.max( 0, read( prToEl ) ) };
		}

		// Password: set/replace, or clear - never both.
		if ( ! passwordEl.disabled ) {
			if ( passwordRmEl.checked ) {
				p.password = '';
			} else if ( passwordEl.value ) {
				p.password = passwordEl.value;
			}
		}

		// Gate domain allowlist: [] clears.
		if ( ! gateDomainsEl.disabled && read( gateDomainsEl ) !== initial[ gateDomainsEl.id ] ) {
			var domains = gateDomainsEl.value.toLowerCase().split( /[\s,]+/ ).filter( function ( d ) {
				return '' !== d;
			} );
			for ( var i = 0; i < domains.length; i++ ) {
				if ( ! DOMAIN_RE.test( domains[ i ] ) ) {
					showError( fmt( i18n.badDomain || '%s', domains[ i ] ) );
					return null;
				}
			}
			p.gateDomains = domains;
		}

		// Lead-capture form: [] clears; non-empty forces requireEmail upstream.
		if ( ! gateFormEl.disabled && JSON.stringify( gf ) !== gfInitial ) {
			for ( var j = 0; j < gf.length; j++ ) {
				if ( ! String( gf[ j ].label || '' ).trim() ) {
					showError( i18n.labelRequired );
					return null;
				}
			}
			p.gateFields = gf.map( function ( f ) {
				return { key: f.key, label: String( f.label ).trim(), type: f.type, required: !! f.required };
			} );
		}

		// Viewer settings: the upstream PUT replaces the WHOLE blob, so on
		// any change send the stored blob with the edits applied (asset
		// references and dashboard-only groups round-trip untouched).
		var anyVs = false;
		var vs = clone( state.viewerSettings || {} );
		vsEls.forEach( function ( el ) {
			if ( el.disabled ) {
				return;
			}
			var path = el.getAttribute( 'data-vs' );
			var v = read( el );
			if ( v === initial[ el.id ] ) {
				return;
			}
			anyVs = true;
			if ( 'background.color' === path ) {
				vs.background = { type: 'solid', color: v }; // Picking a colour switches to a solid background.
			} else {
				setPath( vs, path, v );
			}
		} );
		if ( anyVs ) {
			// Never send groups above the account's tier: the blob write is
			// tier-gated as a whole, and a stale stored group would 403 the
			// entire save.
			if ( ! hasTier( 'pro' ) ) {
				delete vs.brand;
				delete vs.cta;
				delete vs.ga4Id;
				delete vs.watermarkStyle;
				if ( vs.protect ) {
					delete vs.protect.blurOnLeave;
				}
			}
			p.viewerSettings = vs;
		}

		// Client-side guards for the two knowable 400s.
		var capDays = EXPIRY_CAP_DAYS[ plan ];
		if ( p.deleteAt && capDays && new Date( p.deleteAt ).getTime() > Date.now() + capDays * 86400000 + 3600000 ) {
			showError( fmt( i18n.expiryOverCap || '%s', String( capDays ) ) );
			return null;
		}
		var effWatermark = 'watermark' in p ? p.watermark : !! ( state && state.watermark );
		var effDownload  = 'allowDownload' in p ? p.allowDownload : !! ( state && state.allowDownload );
		if ( effWatermark && effDownload && state && state.size > 100 * 1024 * 1024 ) {
			showError( i18n.watermarkRule );
			return null;
		}

		return p;
	}

	function updateRow( file ) {
		var tr = document.querySelector( '.everypage-files tr[data-uuid="' + file.uuid + '"]' );
		if ( ! tr ) {
			return;
		}
		var expires = tr.querySelector( '.ep-expires' );
		if ( expires ) {
			expires.textContent = file.deleteAt ? new Date( file.deleteAt ).toLocaleDateString() : ( i18n.never || 'Never' );
		}
		// Human share URL preference: custom-domain slug > shortId > uuid.
		// The embed snippet is durable and never changes with the slug.
		var link = file.slug && file.shareDomain
			? 'https://' + file.shareDomain + '/' + file.slug
			: cfg.baseUrl + '/' + ( file.shortId || file.uuid );
		var input = tr.querySelector( '.everypage-link' );
		if ( input ) {
			input.value = link;
		}
		var open = tr.querySelector( '.everypage-open-link' );
		if ( open ) {
			open.href = link;
		}
		var copyLink = tr.querySelector( '.everypage-copy-btn' ); // First copy button = the link.
		if ( copyLink ) {
			copyLink.setAttribute( 'data-copy', link );
		}
	}

	function handleSaveError( status, body ) {
		var msg = ( body && body.message ) || i18n.genericError;
		if ( 409 === status ) {
			slugErrorEl.textContent = i18n.slugTaken || msg;
			slugErrorEl.hidden = false;
			slugEl.focus();
			return;
		}
		if ( 410 === status ) {
			dead = true;
			banner.textContent = i18n.deadLink || msg;
			banner.hidden = false;
			saveBtn.disabled = true;
			return;
		}
		showError( msg );
		if ( 403 === status ) {
			// A plan gate fired: the server flushed its cached /user, so
			// re-read the real subscription and re-gate the controls.
			api( 'user' ).then( function ( r ) {
				return r.ok ? r.json() : null;
			} ).then( function ( u ) {
				if ( u && u.subscription && u.subscription !== plan ) {
					plan = u.subscription;
					applyTier();
					statusEl.textContent = fmt( i18n.planNow || '%s', plan );
					refresh();
				}
			} ).catch( function () {} );
		}
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		if ( dead ) {
			return;
		}
		errorEl.hidden = true;
		slugErrorEl.hidden = true;
		var p = buildPayload();
		if ( ! p || ! Object.keys( p ).length ) {
			return;
		}
		saveBtn.disabled = true;
		statusEl.textContent = i18n.saving || '';
		api( 'files/' + encodeURIComponent( currentId ) + '/settings', {
			method: 'PUT',
			body: JSON.stringify( p )
		} ).then( function ( resp ) {
			return resp.json().then( function ( body ) {
				return { ok: resp.ok, status: resp.status, body: body };
			}, function () {
				return { ok: resp.ok, status: resp.status, body: null };
			} );
		} ).then( function ( r ) {
			if ( r.ok && r.body ) {
				// The proxy re-GETs after the empty 200, so this is the
				// clamped, tier-checked state actually stored.
				populate( r.body );
				updateRow( r.body );
				statusEl.textContent = i18n.saved || '';
			} else {
				handleSaveError( r.status, r.body );
				if ( ! dead ) {
					saveBtn.disabled = ! isDirty();
				}
			}
		} ).catch( function () {
			showError( i18n.genericError );
			saveBtn.disabled = ! isDirty();
		} );
	} );

	/* ---- Live interactions ---- */

	form.addEventListener( 'input', refresh );
	form.addEventListener( 'change', refresh );

	modeEl.addEventListener( 'change', applyModeVisibility );

	neverExpireEl.addEventListener( 'change', function () {
		deleteAtEl.disabled = neverExpireEl.checked || ! hasTier( 'free' );
	} );

	slugEl.addEventListener( 'input', function () {
		// Enforce the slug alphabet as you type: lowercase a-z0-9-hyphen.
		var cleaned = slugEl.value.toLowerCase().replace( /[^a-z0-9-]/g, '' );
		if ( cleaned !== slugEl.value ) {
			slugEl.value = cleaned;
		}
		slugErrorEl.hidden = true;
		updateSlugHint();
	} );

	/* ---- Open / close ---- */

	function openDrawer( uuid, name, trigger ) {
		lastTrigger = trigger || null;
		nameEl.textContent = name || '';
		form.hidden = true;
		loading.hidden = false;
		banner.hidden = true;
		errorEl.hidden = true;
		modal.hidden = false;
		document.body.classList.add( 'everypage-modal-open' );
		var x = modal.querySelector( '.everypage-modal-x' );
		if ( x ) {
			x.focus();
		}
		api( 'files/' + encodeURIComponent( uuid ) ).then( function ( resp ) {
			return resp.json().then( function ( body ) {
				return { ok: resp.ok, body: body };
			} );
		} ).then( function ( r ) {
			loading.hidden = true;
			if ( r.ok && r.body && r.body.uuid ) {
				form.hidden = false;
				populate( r.body );
			} else {
				banner.textContent = ( r.body && r.body.message ) || i18n.loadFailed;
				banner.hidden = false;
			}
		} ).catch( function () {
			loading.hidden = true;
			banner.textContent = i18n.loadFailed;
			banner.hidden = false;
		} );
	}

	function closeDrawer() {
		modal.hidden = true;
		document.body.classList.remove( 'everypage-modal-open' );
		if ( lastTrigger ) {
			lastTrigger.focus();
		}
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.everypage-settings-btn' ), function ( btn ) {
		btn.addEventListener( 'click', function () {
			openDrawer( btn.getAttribute( 'data-uuid' ), btn.getAttribute( 'data-name' ), btn );
		} );
	} );

	Array.prototype.forEach.call( modal.querySelectorAll( '[data-close]' ), function ( el ) {
		el.addEventListener( 'click', closeDrawer );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( modal.hidden ) {
			return;
		}
		if ( 'Escape' === e.key ) {
			closeDrawer();
			return;
		}
		if ( 'Tab' === e.key ) {
			var focusable = Array.prototype.filter.call(
				modal.querySelectorAll( 'button, [href], input, select, textarea, summary' ),
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
	} );
}() );
