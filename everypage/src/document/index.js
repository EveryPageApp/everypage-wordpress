/**
 * everypage/document — embed a tracked EveryPage PDF inline (iframe on the
 * CSP-safe /embed/ path) or render a share link/button. Dynamic block: PHP
 * renders the front end, so save() emits nothing but attributes.
 */
import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';
import './style.scss';
import './editor.scss';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
