/**
 * Editor UI for everypage/document. The block stores uuid + shortId (plus
 * mode/height/text/buttonStyle); everything live comes through the
 * everypage/v1 REST proxy so the API key never reaches the browser.
 */
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Button,
	Notice,
	PanelBody,
	Placeholder,
	RangeControl,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

import DocumentPicker from './picker';
import ViewerPanel from './viewer-panel';

const GONE_STATUSES = [ 404, 410 ];

export default function Edit( { attributes, setAttributes, isSelected } ) {
	const { uuid, shortId, fileName, mode, height, text, buttonStyle } = attributes;
	const blockProps = useBlockProps();

	// Bootstrap: connection state, base URL, plan, capabilities.
	const [ boot, setBoot ] = useState( { loading: true, error: null, data: null } );
	useEffect( () => {
		let alive = true;
		apiFetch( { path: '/everypage/v1/user' } )
			.then( ( data ) => alive && setBoot( { loading: false, error: null, data } ) )
			.catch( ( error ) => alive && setBoot( { loading: false, error, data: null } ) );
		return () => {
			alive = false;
		};
	}, [] );

	// The bound file, refetched when the binding changes.
	const [ file, setFile ] = useState( null );
	const [ fileError, setFileError ] = useState( null );
	const boundId = uuid || shortId;
	useEffect( () => {
		setFile( null );
		setFileError( null );
		if ( ! boundId || ! boot.data || ! boot.data.connected ) {
			return;
		}
		let alive = true;
		apiFetch( { path: `/everypage/v1/files/${ encodeURIComponent( boundId ) }` } )
			.then( ( res ) => {
				if ( ! alive ) {
					return;
				}
				setFile( res );
				// Backfill/refresh stored attributes from the live file.
				const next = {};
				if ( res.uuid && res.uuid !== uuid ) {
					next.uuid = res.uuid;
				}
				if ( res.shortId && res.shortId !== shortId ) {
					next.shortId = res.shortId;
				}
				if ( res.originalName && res.originalName !== fileName ) {
					next.fileName = res.originalName;
				}
				if ( Object.keys( next ).length ) {
					setAttributes( next );
				}
			} )
			.catch( ( err ) => alive && setFileError( err ) );
		return () => {
			alive = false;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ boundId, boot.data && boot.data.connected ] );

	const [ swapping, setSwapping ] = useState( false );

	const selectFile = ( f ) => {
		setAttributes( {
			uuid: f.uuid || '',
			shortId: f.shortId || '',
			fileName: f.originalName || '',
		} );
		setSwapping( false );
	};

	if ( boot.loading ) {
		return (
			<div { ...blockProps }>
				<Placeholder
					icon="media-document"
					label={ __( 'EveryPage Document', 'everypage' ) }
				>
					<Spinner />
				</Placeholder>
			</div>
		);
	}

	const data = boot.data;
	if ( boot.error || ! data ) {
		return (
			<div { ...blockProps }>
				<Placeholder
					icon="media-document"
					label={ __( 'EveryPage Document', 'everypage' ) }
					instructions={
						( boot.error && boot.error.message ) ||
						__( 'Could not reach EveryPage.', 'everypage' )
					}
				/>
			</div>
		);
	}

	if ( ! data.connected ) {
		return (
			<div { ...blockProps }>
				<Placeholder
					icon="media-document"
					label={ __( 'EveryPage Document', 'everypage' ) }
					instructions={ __(
						'No EveryPage API key is configured. Add one to pick and embed your documents.',
						'everypage'
					) }
				>
					<Button variant="primary" href={ data.settingsUrl }>
						{ __( 'Open EveryPage settings', 'everypage' ) }
					</Button>
				</Placeholder>
			</div>
		);
	}

	if ( ! boundId || swapping ) {
		return (
			<div { ...blockProps }>
				<DocumentPicker
					onSelect={ selectFile }
					onCancel={ swapping ? () => setSwapping( false ) : null }
				/>
			</div>
		);
	}

	const fileGone =
		fileError && GONE_STATUSES.includes( fileError.data && fileError.data.status );
	const embedId = ( file && file.shortId ) || shortId || uuid;
	const embedSrc = `${ data.baseUrl }/embed/${ encodeURIComponent( embedId ) }`;
	const shareUrl = `${ data.baseUrl }/${ encodeURIComponent( embedId ) }`;
	const displayName =
		( file && file.originalName ) || fileName || __( 'EveryPage document', 'everypage' );
	const buttonText = text || __( 'View document', 'everypage' );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Document', 'everypage' ) } initialOpen>
					<p className="everypage-doc-name">{ displayName }</p>
					{ file && (
						<p className="everypage-doc-meta">
							{ sprintf(
								/* translators: %s: a number of views */
								_n(
									'%s view',
									'%s views',
									file.viewCount || 0,
									'everypage'
								),
								( file.viewCount || 0 ).toLocaleString()
							) }
						</p>
					) }
					{ fileGone && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'This document is no longer available on EveryPage (deleted or expired). Nothing will be shown on the front end.',
								'everypage'
							) }
						</Notice>
					) }
					<Button variant="secondary" onClick={ () => setSwapping( true ) }>
						{ __( 'Swap file', 'everypage' ) }
					</Button>
				</PanelBody>

				<PanelBody title={ __( 'Mode & height', 'everypage' ) } initialOpen>
					<SelectControl
						label={ __( 'Display as', 'everypage' ) }
						value={ mode }
						options={ [
							{ label: __( 'Embedded viewer', 'everypage' ), value: 'embed' },
							{ label: __( 'Button / link', 'everypage' ), value: 'button' },
						] }
						onChange={ ( v ) => setAttributes( { mode: v } ) }
						__nextHasNoMarginBottom
					/>
					{ 'embed' === mode && (
						<RangeControl
							label={ __( 'Height (px)', 'everypage' ) }
							value={ height }
							onChange={ ( v ) => setAttributes( { height: v } ) }
							min={ 240 }
							max={ 1200 }
							step={ 10 }
							__nextHasNoMarginBottom
						/>
					) }
					{ 'button' === mode && (
						<>
							<TextControl
								label={ __( 'Text', 'everypage' ) }
								value={ text }
								placeholder={ __( 'View document', 'everypage' ) }
								onChange={ ( v ) => setAttributes( { text: v } ) }
								__nextHasNoMarginBottom
							/>
							<SelectControl
								label={ __( 'Style', 'everypage' ) }
								value={ buttonStyle }
								options={ [
									{
										label: __( 'Styled button', 'everypage' ),
										value: 'button',
									},
									{ label: __( 'Plain link', 'everypage' ), value: 'link' },
								] }
								onChange={ ( v ) => setAttributes( { buttonStyle: v } ) }
								__nextHasNoMarginBottom
							/>
						</>
					) }
				</PanelBody>

				{ data.canManageSettings ? (
					<ViewerPanel
						file={ fileGone ? null : file }
						tier={ data.subscription }
						pricingUrl={ data.pricingUrl }
						onFileUpdated={ setFile }
					/>
				) : (
					<PanelBody title={ __( 'Viewer', 'everypage' ) } initialOpen={ false }>
						<p className="everypage-panel-note">
							{ __(
								'Viewer settings apply everywhere this document is shared and can only be changed by an administrator.',
								'everypage'
							) }
						</p>
					</PanelBody>
				) }
			</InspectorControls>

			<div { ...blockProps }>
				{ fileGone ? (
					<Placeholder
						icon="media-document"
						label={ __( 'EveryPage Document', 'everypage' ) }
						instructions={ __(
							'This document is no longer available on EveryPage.',
							'everypage'
						) }
					>
						<Button variant="primary" onClick={ () => setSwapping( true ) }>
							{ __( 'Pick another document', 'everypage' ) }
						</Button>
					</Placeholder>
				) : 'embed' === mode ? (
					<div className="everypage-embed everypage-embed--editor">
						<iframe
							src={ embedSrc }
							width="100%"
							height={ height }
							frameBorder="0"
							allowFullScreen
							title={ displayName }
							loading="lazy"
						/>
						{ ! isSelected && (
							<div
								className="everypage-embed__overlay"
								aria-hidden="true"
							/>
						) }
					</div>
				) : (
					<a
						className={
							'link' === buttonStyle
								? 'everypage-share-link'
								: 'everypage-share-button'
						}
						href={ shareUrl }
						onClick={ ( event ) => event.preventDefault() }
						aria-disabled="true"
					>
						{ buttonText }
					</a>
				) }
			</div>
		</>
	);
}
