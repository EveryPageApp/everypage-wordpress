/**
 * Placeholder-state document picker: search/pick from the account's files, or
 * drop/select a PDF to upload it to EveryPage and bind the block to the result.
 */
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	DropZone,
	FormFileUpload,
	Notice,
	Placeholder,
	SearchControl,
	Spinner,
} from '@wordpress/components';

function expiryLabel( deleteAt ) {
	if ( ! deleteAt ) {
		return __( 'Never expires', 'everypage' );
	}
	const date = new Date( deleteAt );
	if ( isNaN( date.getTime() ) ) {
		return __( 'Expires', 'everypage' );
	}
	return sprintf(
		/* translators: %s: a localised date */
		__( 'Expires %s', 'everypage' ),
		date.toLocaleDateString()
	);
}

export default function DocumentPicker( { onSelect, onCancel } ) {
	const [ files, setFiles ] = useState( null );
	const [ listError, setListError ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ uploading, setUploading ] = useState( false );
	const [ uploadError, setUploadError ] = useState( null );

	useEffect( () => {
		let alive = true;
		apiFetch( { path: '/everypage/v1/files' } )
			.then( ( res ) => alive && setFiles( Array.isArray( res ) ? res : [] ) )
			.catch( ( err ) => alive && setListError( err ) );
		return () => {
			alive = false;
		};
	}, [] );

	const upload = ( file ) => {
		if ( ! file || uploading ) {
			return;
		}
		if ( ! /\.pdf$/i.test( file.name || '' ) ) {
			setUploadError( __( 'Only PDF files can be shared.', 'everypage' ) );
			return;
		}
		const body = new window.FormData();
		body.append( 'file', file, file.name );
		setUploading( true );
		setUploadError( null );
		apiFetch( { path: '/everypage/v1/files', method: 'POST', body } )
			.then( ( res ) =>
				onSelect( {
					uuid: res.uuid || '',
					shortId: res.shortId || '',
					originalName: file.name,
				} )
			)
			.catch( ( err ) =>
				setUploadError(
					err && err.message
						? err.message
						: __( 'Upload failed. Check your plan limits.', 'everypage' )
				)
			)
			.finally( () => setUploading( false ) );
	};

	const term = search.trim().toLowerCase();
	const shown = ( files || [] ).filter(
		( f ) => ! term || ( f.originalName || '' ).toLowerCase().includes( term )
	);

	return (
		<Placeholder
			icon="media-document"
			label={ __( 'EveryPage Document', 'everypage' ) }
			instructions={ __(
				'Pick one of your EveryPage documents, or upload a new PDF.',
				'everypage'
			) }
			className="everypage-picker"
		>
			<DropZone
				label={ __( 'Drop a PDF to upload it to EveryPage', 'everypage' ) }
				onFilesDrop={ ( dropped ) => upload( dropped && dropped[ 0 ] ) }
			/>

			{ uploadError && (
				<Notice status="error" isDismissible onRemove={ () => setUploadError( null ) }>
					{ uploadError }
				</Notice>
			) }

			<SearchControl
				label={ __( 'Search your documents', 'everypage' ) }
				value={ search }
				onChange={ setSearch }
				__nextHasNoMarginBottom
			/>

			{ listError && (
				<Notice status="error" isDismissible={ false }>
					{ listError.message ||
						__( 'Could not load your EveryPage files.', 'everypage' ) }
				</Notice>
			) }

			{ ! files && ! listError && <Spinner /> }

			{ files && ! shown.length && (
				<p className="everypage-picker__empty">
					{ term
						? __( 'No documents match your search.', 'everypage' )
						: __( 'No documents yet — upload your first PDF below.', 'everypage' ) }
				</p>
			) }

			{ shown.length > 0 && (
				<ul className="everypage-picker__list">
					{ shown.map( ( f ) => (
						<li key={ f.uuid }>
							<Button
								className="everypage-picker__item"
								onClick={ () => onSelect( f ) }
							>
								<span className="everypage-picker__name">
									{ f.originalName || f.uuid }
								</span>
								<span className="everypage-picker__meta">
									{ sprintf(
										/* translators: %s: a number of views */
										_n( '%s view', '%s views', f.viewCount || 0, 'everypage' ),
										( f.viewCount || 0 ).toLocaleString()
									) }
									{ ' · ' }
									{ expiryLabel( f.deleteAt ) }
								</span>
							</Button>
						</li>
					) ) }
				</ul>
			) }

			<div className="everypage-picker__actions">
				<FormFileUpload
					accept="application/pdf"
					variant="secondary"
					isBusy={ uploading }
					disabled={ uploading }
					onChange={ ( event ) =>
						upload( event.target.files && event.target.files[ 0 ] )
					}
				>
					{ uploading
						? __( 'Uploading…', 'everypage' )
						: __( 'Upload a PDF', 'everypage' ) }
				</FormFileUpload>
				{ onCancel && (
					<Button variant="tertiary" onClick={ onCancel }>
						{ __( 'Cancel', 'everypage' ) }
					</Button>
				) }
			</div>
		</Placeholder>
	);
}
