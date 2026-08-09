/**
 * The "Viewer" inspector panel. These are PER-FILE settings saved to
 * EveryPage — they apply everywhere the document is shared, not just in this
 * block. Controls above the account's plan tier are visible but disabled,
 * with an inline upgrade link (never a surprise error after the fact).
 * Writes go through the manage_options-gated REST proxy.
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useSetting } from '@wordpress/block-editor';
import {
	BaseControl,
	Button,
	ColorIndicator,
	ColorPalette,
	ExternalLink,
	Notice,
	PanelBody,
	RangeControl,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';

import { hasTier, pickThemeColors } from './utils';

const PLAN_LABELS = {
	basic: __( 'Basic', 'everypage' ),
	pro: __( 'Pro', 'everypage' ),
};

function UpgradeNote( { plan, pricingUrl } ) {
	return (
		<p className="everypage-upgrade-note">
			{ sprintf(
				/* translators: %s: plan name, e.g. "Pro" */
				__( 'Available on the %s plan.', 'everypage' ),
				PLAN_LABELS[ plan ] || plan
			) }{ ' ' }
			<ExternalLink href={ pricingUrl }>
				{ __( 'Upgrade', 'everypage' ) }
			</ExternalLink>
		</p>
	);
}

/** Build the PUT payload from the draft, sending only tier-allowed groups. */
function buildPayload( draft, tier ) {
	const vs = draft.viewerSettings || {};
	const settings = {};
	const copy = ( key ) => {
		if ( vs[ key ] && Object.keys( vs[ key ] ).length ) {
			settings[ key ] = { ...vs[ key ] };
		}
	};
	if ( hasTier( tier, 'basic' ) ) {
		[ 'background', 'logo', 'page', 'flip', 'swipe', 'protect' ].forEach( copy );
		if ( settings.protect && ! hasTier( tier, 'pro' ) ) {
			delete settings.protect.blurOnLeave;
			if ( ! Object.keys( settings.protect ).length ) {
				delete settings.protect;
			}
		}
	}
	if ( hasTier( tier, 'pro' ) ) {
		copy( 'brand' );
	}
	const payload = { viewerMode: draft.viewerMode };
	if ( Object.keys( settings ).length ) {
		payload.viewerSettings = settings;
	}
	return payload;
}

export default function ViewerPanel( { file, tier, pricingUrl, onFileUpdated } ) {
	const [ draft, setDraft ] = useState( null );
	const [ dirty, setDirty ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ saved, setSaved ] = useState( false );
	const themePalette = useSetting( 'color.palette' );

	// Re-seed the draft whenever the bound file (or its server state) changes.
	useEffect( () => {
		if ( file ) {
			setDraft( {
				viewerMode: file.viewerMode || 'standard',
				viewerSettings: file.viewerSettings || {},
			} );
			setDirty( false );
			setError( null );
		}
	}, [ file ] );

	if ( ! file || ! draft ) {
		return (
			<PanelBody title={ __( 'Viewer', 'everypage' ) } initialOpen={ false }>
				<Spinner />
			</PanelBody>
		);
	}

	const canBasic = hasTier( tier, 'basic' );
	const canPro = hasTier( tier, 'pro' );

	const vs = draft.viewerSettings || {};
	const group = ( key ) => vs[ key ] || {};
	const setMode = ( viewerMode ) => {
		setDraft( ( d ) => ( { ...d, viewerMode } ) );
		setDirty( true );
		setSaved( false );
	};
	const setField = ( key, field, value ) => {
		setDraft( ( d ) => ( {
			...d,
			viewerSettings: {
				...( d.viewerSettings || {} ),
				[ key ]: { ...( ( d.viewerSettings || {} )[ key ] || {} ), [ field ]: value },
			},
		} ) );
		setDirty( true );
		setSaved( false );
	};

	const put = ( payload, after ) => {
		setSaving( true );
		setError( null );
		apiFetch( {
			path: `/everypage/v1/files/${ encodeURIComponent( file.uuid ) }/settings`,
			method: 'PUT',
			data: payload,
		} )
			.then( ( fresh ) => {
				onFileUpdated( fresh );
				setDirty( false );
				setSaved( true );
				if ( after ) {
					after( fresh );
				}
			} )
			.catch( ( err ) =>
				setError(
					err && err.message
						? err.message
						: __( 'Could not save viewer settings.', 'everypage' )
				)
			)
			.finally( () => setSaving( false ) );
	};

	const save = () => put( buildPayload( draft, tier ) );

	// "Match my theme's colours" — author-triggered, shows the chosen
	// swatches before anything is written to the file.
	const suggestion = pickThemeColors( themePalette );
	const applyThemeColors = () => {
		if ( ! suggestion || ! canBasic ) {
			return;
		}
		const viewerSettings = {
			background: { type: 'solid', color: suggestion.background },
		};
		if ( canPro && suggestion.accent ) {
			viewerSettings.brand = { accentColor: suggestion.accent };
		}
		put( { viewerSettings } );
	};

	const paletteColors = Array.isArray( themePalette ) ? themePalette : [];

	return (
		<PanelBody title={ __( 'Viewer', 'everypage' ) } initialOpen={ false }>
			<p className="everypage-panel-note">
				{ __(
					'Applies everywhere this document is shared — not just in this block.',
					'everypage'
				) }
			</p>

			{ error && (
				<Notice status="error" isDismissible onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			{ saved && ! dirty && (
				<Notice status="success" isDismissible onRemove={ () => setSaved( false ) }>
					{ __( 'Viewer settings saved.', 'everypage' ) }
				</Notice>
			) }

			<SelectControl
				label={ __( 'Viewer mode', 'everypage' ) }
				value={ draft.viewerMode }
				options={ [
					{ label: __( 'Standard', 'everypage' ), value: 'standard' },
					{ label: __( 'Flipbook', 'everypage' ), value: 'flipbook' },
					{ label: __( 'Swipe', 'everypage' ), value: 'swipe' },
					{ label: __( 'Magazine', 'everypage' ), value: 'magazine' },
				] }
				onChange={ setMode }
				__nextHasNoMarginBottom
			/>

			<div className="everypage-viewer-group">
				<BaseControl.VisualLabel>
					{ __( 'Match my theme’s colours', 'everypage' ) }
				</BaseControl.VisualLabel>
				{ suggestion ? (
					<>
						<div className="everypage-theme-swatches">
							<span>
								<ColorIndicator colorValue={ suggestion.background } />{ ' ' }
								{ sprintf(
									/* translators: %s: a hex colour */
									__( 'Background %s', 'everypage' ),
									suggestion.background
								) }
							</span>
							<span>
								{ suggestion.accent ? (
									<>
										<ColorIndicator colorValue={ suggestion.accent } />{ ' ' }
										{ sprintf(
											/* translators: %s: a hex colour */
											__( 'Accent %s', 'everypage' ),
											suggestion.accent
										) }
									</>
								) : (
									__( 'No accent candidate found.', 'everypage' )
								) }
							</span>
						</div>
						<Button
							variant="secondary"
							onClick={ applyThemeColors }
							disabled={ ! canBasic || saving }
							isBusy={ saving }
						>
							{ __( 'Apply to document', 'everypage' ) }
						</Button>
						{ ! canBasic && (
							<UpgradeNote plan="basic" pricingUrl={ pricingUrl } />
						) }
						{ canBasic && ! canPro && suggestion.accent && (
							<p className="everypage-upgrade-note">
								{ __(
									'The accent colour needs the Pro plan; only the background will be applied.',
									'everypage'
								) }{ ' ' }
								<ExternalLink href={ pricingUrl }>
									{ __( 'Upgrade', 'everypage' ) }
								</ExternalLink>
							</p>
						) }
					</>
				) : (
					<p className="everypage-panel-note">
						{ __(
							'Your theme does not expose a colour palette.',
							'everypage'
						) }
					</p>
				) }
			</div>

			<div className="everypage-viewer-group">
				<BaseControl.VisualLabel>
					{ __( 'Background', 'everypage' ) }
				</BaseControl.VisualLabel>
				{ ! canBasic && <UpgradeNote plan="basic" pricingUrl={ pricingUrl } /> }
				<ColorPalette
					colors={ paletteColors }
					value={ group( 'background' ).color }
					onChange={ ( color ) => {
						if ( color ) {
							setField( 'background', 'type', 'solid' );
							setField( 'background', 'color', color );
						}
					} }
					disableCustomColors={ ! canBasic }
					clearable={ false }
				/>
				<RangeControl
					label={ __( 'Blur', 'everypage' ) }
					value={ group( 'background' ).blur ?? 0 }
					onChange={ ( v ) => setField( 'background', 'blur', v ) }
					min={ 0 }
					max={ 20 }
					disabled={ ! canBasic }
					__nextHasNoMarginBottom
				/>
				<RangeControl
					label={ __( 'Dim', 'everypage' ) }
					value={ group( 'background' ).dim ?? 0 }
					onChange={ ( v ) => setField( 'background', 'dim', v ) }
					min={ 0 }
					max={ 80 }
					disabled={ ! canBasic }
					__nextHasNoMarginBottom
				/>
			</div>

			<div className="everypage-viewer-group">
				<BaseControl.VisualLabel>
					{ __( 'Page', 'everypage' ) }
				</BaseControl.VisualLabel>
				{ ! canBasic && <UpgradeNote plan="basic" pricingUrl={ pricingUrl } /> }
				<RangeControl
					label={ __( 'Shadow', 'everypage' ) }
					value={ group( 'page' ).shadow ?? 1 }
					onChange={ ( v ) => setField( 'page', 'shadow', v ) }
					min={ 0 }
					max={ 3 }
					disabled={ ! canBasic }
					__nextHasNoMarginBottom
				/>
				<RangeControl
					label={ __( 'Rounded corners', 'everypage' ) }
					value={ group( 'page' ).rounded ?? 0 }
					onChange={ ( v ) => setField( 'page', 'rounded', v ) }
					min={ 0 }
					max={ 3 }
					disabled={ ! canBasic }
					__nextHasNoMarginBottom
				/>
				<ToggleControl
					label={ __( 'Page edge effect', 'everypage' ) }
					checked={ !! group( 'page' ).edges }
					onChange={ ( v ) => setField( 'page', 'edges', v ) }
					disabled={ ! canBasic }
					__nextHasNoMarginBottom
				/>
				<ToggleControl
					label={ __( 'Show cover alone', 'everypage' ) }
					checked={ !! group( 'page' ).coverAlone }
					onChange={ ( v ) => setField( 'page', 'coverAlone', v ) }
					disabled={ ! canBasic }
					__nextHasNoMarginBottom
				/>
			</div>

			{ 'flipbook' === draft.viewerMode && (
				<div className="everypage-viewer-group">
					<BaseControl.VisualLabel>
						{ __( 'Flipbook', 'everypage' ) }
					</BaseControl.VisualLabel>
					{ ! canBasic && <UpgradeNote plan="basic" pricingUrl={ pricingUrl } /> }
					<RangeControl
						label={ __( 'Flip speed (ms)', 'everypage' ) }
						value={ group( 'flip' ).speedMs ?? 600 }
						onChange={ ( v ) => setField( 'flip', 'speedMs', v ) }
						min={ 200 }
						max={ 1200 }
						step={ 50 }
						disabled={ ! canBasic }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Layout', 'everypage' ) }
						value={ group( 'flip' ).layout || 'adaptive' }
						options={ [
							{ label: __( 'Adaptive', 'everypage' ), value: 'adaptive' },
							{ label: __( 'Single page', 'everypage' ), value: 'single' },
							{ label: __( 'Double page', 'everypage' ), value: 'double' },
						] }
						onChange={ ( v ) => setField( 'flip', 'layout', v ) }
						disabled={ ! canBasic }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Page-turn sound', 'everypage' ) }
						checked={ !! group( 'flip' ).sound }
						onChange={ ( v ) => setField( 'flip', 'sound', v ) }
						disabled={ ! canBasic }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Right-to-left', 'everypage' ) }
						checked={ !! group( 'flip' ).rtl }
						onChange={ ( v ) => setField( 'flip', 'rtl', v ) }
						disabled={ ! canBasic }
						__nextHasNoMarginBottom
					/>
				</div>
			) }

			{ 'swipe' === draft.viewerMode && (
				<div className="everypage-viewer-group">
					<BaseControl.VisualLabel>
						{ __( 'Swipe', 'everypage' ) }
					</BaseControl.VisualLabel>
					{ ! canBasic && <UpgradeNote plan="basic" pricingUrl={ pricingUrl } /> }
					<ToggleControl
						label={ __( 'Auto-advance', 'everypage' ) }
						checked={ !! group( 'swipe' ).autoAdvance }
						onChange={ ( v ) => setField( 'swipe', 'autoAdvance', v ) }
						disabled={ ! canBasic }
						__nextHasNoMarginBottom
					/>
					<RangeControl
						label={ __( 'Interval (ms)', 'everypage' ) }
						value={ group( 'swipe' ).intervalMs ?? 5000 }
						onChange={ ( v ) => setField( 'swipe', 'intervalMs', v ) }
						min={ 3000 }
						max={ 30000 }
						step={ 500 }
						disabled={ ! canBasic || ! group( 'swipe' ).autoAdvance }
						__nextHasNoMarginBottom
					/>
				</div>
			) }

			{ group( 'logo' ) && Object.keys( group( 'logo' ) ).length > 0 && (
				<div className="everypage-viewer-group">
					<BaseControl.VisualLabel>
						{ __( 'Logo', 'everypage' ) }
					</BaseControl.VisualLabel>
					{ ! canBasic && <UpgradeNote plan="basic" pricingUrl={ pricingUrl } /> }
					<SelectControl
						label={ __( 'Position', 'everypage' ) }
						value={ group( 'logo' ).position || 'tl' }
						options={ [
							{ label: __( 'Top left', 'everypage' ), value: 'tl' },
							{ label: __( 'Top right', 'everypage' ), value: 'tr' },
							{ label: __( 'Bottom left', 'everypage' ), value: 'bl' },
							{ label: __( 'Bottom right', 'everypage' ), value: 'br' },
						] }
						onChange={ ( v ) => setField( 'logo', 'position', v ) }
						disabled={ ! canBasic }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Size', 'everypage' ) }
						value={ group( 'logo' ).size || 'm' }
						options={ [
							{ label: __( 'Small', 'everypage' ), value: 's' },
							{ label: __( 'Medium', 'everypage' ), value: 'm' },
							{ label: __( 'Large', 'everypage' ), value: 'l' },
							{ label: __( 'Extra large', 'everypage' ), value: 'xl' },
						] }
						onChange={ ( v ) => setField( 'logo', 'size', v ) }
						disabled={ ! canBasic }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Hide EveryPage badge', 'everypage' ) }
						checked={ !! group( 'logo' ).hideBadge }
						onChange={ ( v ) => setField( 'logo', 'hideBadge', v ) }
						disabled={ ! canBasic }
						__nextHasNoMarginBottom
					/>
				</div>
			) }

			<div className="everypage-viewer-group">
				<BaseControl.VisualLabel>
					{ __( 'Protection', 'everypage' ) }
				</BaseControl.VisualLabel>
				{ ! canBasic && <UpgradeNote plan="basic" pricingUrl={ pricingUrl } /> }
				<ToggleControl
					label={ __( 'Block right-click menu', 'everypage' ) }
					checked={ !! group( 'protect' ).contextMenu }
					onChange={ ( v ) => setField( 'protect', 'contextMenu', v ) }
					disabled={ ! canBasic }
					__nextHasNoMarginBottom
				/>
				<ToggleControl
					label={ __( 'Block printing', 'everypage' ) }
					checked={ !! group( 'protect' ).print }
					onChange={ ( v ) => setField( 'protect', 'print', v ) }
					disabled={ ! canBasic }
					__nextHasNoMarginBottom
				/>
				<ToggleControl
					label={ __( 'Block text selection', 'everypage' ) }
					checked={ !! group( 'protect' ).select }
					onChange={ ( v ) => setField( 'protect', 'select', v ) }
					disabled={ ! canBasic }
					__nextHasNoMarginBottom
				/>
				<ToggleControl
					label={ __( 'Blur when window loses focus', 'everypage' ) }
					checked={ !! group( 'protect' ).blurOnLeave }
					onChange={ ( v ) => setField( 'protect', 'blurOnLeave', v ) }
					disabled={ ! canPro }
					__nextHasNoMarginBottom
				/>
				{ canBasic && ! canPro && (
					<UpgradeNote plan="pro" pricingUrl={ pricingUrl } />
				) }
			</div>

			<div className="everypage-viewer-group">
				<BaseControl.VisualLabel>
					{ __( 'Branding', 'everypage' ) }
				</BaseControl.VisualLabel>
				{ ! canPro && <UpgradeNote plan="pro" pricingUrl={ pricingUrl } /> }
				<ColorPalette
					colors={ paletteColors }
					value={ group( 'brand' ).accentColor }
					onChange={ ( color ) => {
						if ( color ) {
							setField( 'brand', 'accentColor', color );
						}
					} }
					disableCustomColors={ ! canPro }
					clearable={ false }
				/>
				<SelectControl
					label={ __( 'Toolbar theme', 'everypage' ) }
					value={ group( 'brand' ).toolbarTheme || 'auto' }
					options={ [
						{ label: __( 'Auto', 'everypage' ), value: 'auto' },
						{ label: __( 'Light', 'everypage' ), value: 'light' },
						{ label: __( 'Dark', 'everypage' ), value: 'dark' },
					] }
					onChange={ ( v ) => setField( 'brand', 'toolbarTheme', v ) }
					disabled={ ! canPro }
					__nextHasNoMarginBottom
				/>
			</div>

			<Button
				variant="primary"
				onClick={ save }
				disabled={ ! dirty || saving }
				isBusy={ saving }
			>
				{ saving
					? __( 'Saving…', 'everypage' )
					: __( 'Save viewer settings', 'everypage' ) }
			</Button>
		</PanelBody>
	);
}
