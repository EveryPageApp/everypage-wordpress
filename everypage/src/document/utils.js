/**
 * Small shared helpers: plan-tier gating and the "Match my theme's colours"
 * palette heuristics.
 */

const TIER_ORDER = { free: 0, basic: 1, pro: 2 };

/** True when `subscription` (free|basic|pro) is at least `min`. */
export function hasTier( subscription, min ) {
	const have = TIER_ORDER[ subscription ] ?? 0;
	const need = TIER_ORDER[ min ] ?? 0;
	return have >= need;
}

/** Parse #rgb or #rrggbb to [r, g, b] (0-255), or null for anything else. */
export function parseHex( value ) {
	if ( typeof value !== 'string' ) {
		return null;
	}
	const hex = value.trim();
	let m = /^#([0-9a-f]{6})$/i.exec( hex );
	if ( m ) {
		return [
			parseInt( m[ 1 ].slice( 0, 2 ), 16 ),
			parseInt( m[ 1 ].slice( 2, 4 ), 16 ),
			parseInt( m[ 1 ].slice( 4, 6 ), 16 ),
		];
	}
	m = /^#([0-9a-f]{3})$/i.exec( hex );
	if ( m ) {
		return [
			parseInt( m[ 1 ][ 0 ] + m[ 1 ][ 0 ], 16 ),
			parseInt( m[ 1 ][ 1 ] + m[ 1 ][ 1 ], 16 ),
			parseInt( m[ 1 ][ 2 ] + m[ 1 ][ 2 ], 16 ),
		];
	}
	return null;
}

/** Normalise a parseable colour to #RRGGBB (uppercase), or null. */
export function toHex6( value ) {
	const rgb = parseHex( value );
	if ( ! rgb ) {
		return null;
	}
	return (
		'#' +
		rgb.map( ( c ) => c.toString( 16 ).padStart( 2, '0' ) ).join( '' ).toUpperCase()
	);
}

/** WCAG-ish relative luminance, 0 (black) to 1 (white). */
export function luminance( rgb ) {
	const [ r, g, b ] = rgb.map( ( c ) => {
		const s = c / 255;
		return s <= 0.03928 ? s / 12.92 : Math.pow( ( s + 0.055 ) / 1.055, 2.4 );
	} );
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** HSL saturation, 0 to 1. */
export function saturation( rgb ) {
	const r = rgb[ 0 ] / 255;
	const g = rgb[ 1 ] / 255;
	const b = rgb[ 2 ] / 255;
	const max = Math.max( r, g, b );
	const min = Math.min( r, g, b );
	if ( max === min ) {
		return 0;
	}
	const l = ( max + min ) / 2;
	const d = max - min;
	return l > 0.5 ? d / ( 2 - max - min ) : d / ( max + min );
}

/**
 * Pick a background and an accent from a theme palette
 * (array of { name, slug, color }).
 *
 * Background: a colour named/slugged like "background"/"base" if present,
 * otherwise the highest-luminance colour. Accent: the most saturated
 * non-neutral colour (excluding the background); null when the palette has
 * no usable candidate.
 *
 * Returns { background, accent } as #RRGGBB strings, or null when the
 * palette yields no parseable colour at all.
 */
export function pickThemeColors( palette ) {
	const entries = ( Array.isArray( palette ) ? palette : [] )
		.map( ( item ) => {
			const rgb = parseHex( item && item.color );
			return rgb
				? {
						hex: toHex6( item.color ),
						rgb,
						lum: luminance( rgb ),
						sat: saturation( rgb ),
						label: `${ item.slug || '' } ${ item.name || '' }`.toLowerCase(),
				  }
				: null;
		} )
		.filter( Boolean );

	if ( ! entries.length ) {
		return null;
	}

	const named = entries.filter( ( e ) => /\b(background|base)\b/.test( e.label ) );
	const bgPool = named.length ? named : entries;
	const background = bgPool.reduce( ( a, b ) => ( b.lum > a.lum ? b : a ) );

	const accentPool = entries.filter(
		( e ) =>
			e.hex !== background.hex && e.sat >= 0.15 && e.lum > 0.03 && e.lum < 0.92
	);
	const fallbackPool = entries.filter( ( e ) => e.hex !== background.hex );
	const pool = accentPool.length ? accentPool : fallbackPool;
	const accent = pool.length
		? pool.reduce( ( a, b ) => ( b.sat > a.sat ? b : a ) ).hex
		: null;

	return { background: background.hex, accent };
}
