/**
 * Unit tests for the block's shared helpers: tier gating and the
 * "Match my theme's colours" heuristics.
 */

import {
	hasTier,
	parseHex,
	toHex6,
	luminance,
	saturation,
	pickThemeColors,
} from '../utils';

describe( 'hasTier', () => {
	it( 'orders free < basic < pro', () => {
		expect( hasTier( 'pro', 'basic' ) ).toBe( true );
		expect( hasTier( 'basic', 'basic' ) ).toBe( true );
		expect( hasTier( 'free', 'basic' ) ).toBe( false );
		expect( hasTier( 'basic', 'pro' ) ).toBe( false );
	} );

	// An unknown plan string must fail closed: a gated control shown enabled
	// becomes a server error the user cannot explain.
	it( 'treats an unknown plan as free', () => {
		expect( hasTier( undefined, 'basic' ) ).toBe( false );
		expect( hasTier( 'enterprise', 'pro' ) ).toBe( false );
		expect( hasTier( 'enterprise', 'free' ) ).toBe( true );
	} );
} );

describe( 'parseHex / toHex6', () => {
	it( 'reads both shorthand and full hex', () => {
		expect( parseHex( '#fff' ) ).toEqual( [ 255, 255, 255 ] );
		expect( parseHex( '#1C1812' ) ).toEqual( [ 28, 24, 18 ] );
		expect( parseHex( '  #1c1812  ' ) ).toEqual( [ 28, 24, 18 ] );
	} );

	it( 'rejects anything that is not a hex colour', () => {
		expect( parseHex( 'rgb(0,0,0)' ) ).toBeNull();
		expect( parseHex( '#12345' ) ).toBeNull();
		expect( parseHex( '' ) ).toBeNull();
		expect( parseHex( null ) ).toBeNull();
		expect( parseHex( 123456 ) ).toBeNull();
	} );

	it( 'normalises to uppercase #RRGGBB', () => {
		expect( toHex6( '#fff' ) ).toBe( '#FFFFFF' );
		expect( toHex6( '#1c1812' ) ).toBe( '#1C1812' );
		expect( toHex6( 'nope' ) ).toBeNull();
	} );
} );

describe( 'luminance / saturation', () => {
	it( 'puts black at 0 and white at 1', () => {
		expect( luminance( [ 0, 0, 0 ] ) ).toBeCloseTo( 0, 5 );
		expect( luminance( [ 255, 255, 255 ] ) ).toBeCloseTo( 1, 5 );
	} );

	it( 'reports greys as unsaturated and primaries as fully saturated', () => {
		expect( saturation( [ 128, 128, 128 ] ) ).toBe( 0 );
		expect( saturation( [ 255, 0, 0 ] ) ).toBeCloseTo( 1, 5 );
	} );
} );

describe( 'pickThemeColors', () => {
	it( 'prefers a colour named "background" over the lightest one', () => {
		const picked = pickThemeColors( [
			{ slug: 'background', name: 'Background', color: '#F5F1E8' },
			{ slug: 'white', name: 'White', color: '#FFFFFF' },
			{ slug: 'accent', name: 'Accent', color: '#C1440E' },
		] );
		expect( picked.background ).toBe( '#F5F1E8' );
		expect( picked.accent ).toBe( '#C1440E' );
	} );

	it( 'falls back to the lightest colour when nothing is named', () => {
		const picked = pickThemeColors( [
			{ slug: 'ink', name: 'Ink', color: '#111111' },
			{ slug: 'paper', name: 'Paper', color: '#FAFAFA' },
			{ slug: 'brand', name: 'Brand', color: '#2E5AAC' },
		] );
		expect( picked.background ).toBe( '#FAFAFA' );
		expect( picked.accent ).toBe( '#2E5AAC' );
	} );

	// Themes registering 20+ near-duplicate greys were the reason this
	// heuristic exists: it must still choose the most saturated colour.
	it( 'picks the most saturated accent from a noisy palette', () => {
		const picked = pickThemeColors( [
			{ slug: 'base', name: 'Base', color: '#FFFFFF' },
			{ slug: 'grey-1', name: 'Grey 1', color: '#EEEEEE' },
			{ slug: 'grey-2', name: 'Grey 2', color: '#DDDDDD' },
			{ slug: 'muted', name: 'Muted', color: '#8899AA' },
			{ slug: 'vivid', name: 'Vivid', color: '#FF3300' },
		] );
		expect( picked.background ).toBe( '#FFFFFF' );
		expect( picked.accent ).toBe( '#FF3300' );
	} );

	it( 'returns an accent of null when every other colour is a neutral extreme', () => {
		const picked = pickThemeColors( [
			{ slug: 'base', name: 'Base', color: '#FFFFFF' },
		] );
		expect( picked.background ).toBe( '#FFFFFF' );
		expect( picked.accent ).toBeNull();
	} );

	it( 'ignores unparseable entries and gives up on an empty palette', () => {
		expect( pickThemeColors( [] ) ).toBeNull();
		expect( pickThemeColors( null ) ).toBeNull();
		expect(
			pickThemeColors( [ { slug: 'x', name: 'X', color: 'var(--wp--preset--color--x)' } ] )
		).toBeNull();
	} );
} );
