/**
 * copy-vendor.js — Copia i file dist di Select2 in public/vendor/select2/
 *
 * Script Node.js cross-platform (Windows + Unix) richiamato da package.json.
 * Non richiede dipendenze esterne: usa solo il modulo 'fs' standard.
 *
 * Uso:
 *   node scripts/copy-vendor.js
 *   (oppure tramite: npm run copy-vendor)
 */

'use strict';

const { mkdirSync, copyFileSync } = require( 'fs' );
const { join }                    = require( 'path' );

const SRC = join( 'node_modules', 'select2', 'dist' );
const DST = join( 'public', 'vendor', 'select2' );

// Ensure destination directories exist.
mkdirSync( join( DST, 'i18n' ), { recursive: true } );

// Copy files.
const files = [
	{ from: join( SRC, 'css', 'select2.min.css' ), to: join( DST, 'select2.min.css' ) },
	{ from: join( SRC, 'js',  'select2.min.js'  ), to: join( DST, 'select2.min.js'  ) },
	{ from: join( SRC, 'js',  'i18n', 'it.js'   ), to: join( DST, 'i18n', 'it.js'   ) },
];

files.forEach( ( { from, to } ) => {
	copyFileSync( from, to );
	console.log( `  copied: ${ to }` );
} );

console.log( 'copy-vendor: done.' );
