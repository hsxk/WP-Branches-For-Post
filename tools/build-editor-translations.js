const fs = require( 'node:fs' );
const path = require( 'node:path' );

const root = path.resolve( __dirname, '..' );
const sourcePath = path.join( root, 'src', 'index.js' );
const languagesDir = path.join( root, 'languages' );
const domain = 'wp-branches-for-post';
const handle = 'wbfp-editor';
const locales = [ 'ja', 'zh_CN', 'zh_TW' ];

function unescapePo( value ) {
	return JSON.parse( `"${ value.replace( /"/g, '\\"' ).replace( /\\\"/g, '"' ) }"` );
}

function parseQuoted( line ) {
	const match = line.match( /^"(.*)"$/ );
	if ( ! match ) {
		return '';
	}
	return JSON.parse( `"${ match[ 1 ] }"` );
}

function parsePo( content ) {
	const messages = new Map();
	const lines = content.split( /\r?\n/ );
	let msgid = null;
	let msgstr = null;
	let target = null;

	function flush() {
		if ( msgid && msgstr !== null ) {
			messages.set( msgid, msgstr );
		}
		msgid = null;
		msgstr = null;
		target = null;
	}

	for ( const line of lines ) {
		if ( line.startsWith( 'msgid ' ) ) {
			flush();
			msgid = parseQuoted( line.slice( 6 ) );
			target = 'id';
		} else if ( line.startsWith( 'msgstr ' ) ) {
			msgstr = parseQuoted( line.slice( 7 ) );
			target = 'str';
		} else if ( line.startsWith( '"' ) ) {
			const part = parseQuoted( line );
			if ( 'id' === target ) {
				msgid += part;
			} else if ( 'str' === target ) {
				msgstr += part;
			}
		} else if ( '' === line.trim() ) {
			flush();
		}
	}
	flush();
	return messages;
}

function sourceMessages() {
	const source = fs.readFileSync( sourcePath, 'utf8' );
	const ids = new Set();
	const re = /\b__\(\s*'((?:\\'|[^'])*)'\s*,\s*'wp-branches-for-post'\s*\)/g;
	let match;
	while ( ( match = re.exec( source ) ) ) {
		ids.add( match[ 1 ].replace( /\\'/g, "'" ).replace( /\\n/g, '\n' ) );
	}
	return [ ...ids ].sort();
}

const potPath = path.join( languagesDir, 'wp-branches-for-post.pot' );
if ( fs.existsSync( potPath ) ) {
	const pot = fs.readFileSync( potPath, 'utf8' ).replace(
		/^"POT-Creation-Date:.*"$/m,
		'"POT-Creation-Date: \\n"'
	);
	fs.writeFileSync( potPath, pot );
	console.log( 'Normalized POT-Creation-Date for reproducible translation artifacts' );
}

const ids = sourceMessages();
for ( const locale of locales ) {
	const poPath = path.join( languagesDir, `wp-branches-for-post-${ locale }.po` );
	const messages = parsePo( fs.readFileSync( poPath, 'utf8' ) );
	const missing = ids.filter( ( id ) => ! messages.get( id ) );
	if ( missing.length ) {
		throw new Error( `${ locale } is missing editor translations:\n${ missing.join( '\n' ) }` );
	}

	const localeMessages = {
		'': {
			domain,
			lang: locale,
			'plural-forms': 'nplurals=1; plural=0;',
		},
	};
	for ( const id of ids ) {
		localeMessages[ id ] = [ messages.get( id ) ];
	}

	const output = {
		'translation-revision-date': '2026-09-23 03:00+0900',
		generator: 'WP Branches For Post 2.1 translation build',
		source: 'build/index.js',
		domain: 'messages',
		locale_data: {
			messages: localeMessages,
		},
	};
	fs.writeFileSync(
		path.join( languagesDir, `wp-branches-for-post-${ locale }-${ handle }.json` ),
		JSON.stringify( output, null, 2 ) + '\n'
	);
	console.log( `${ locale }: ${ ids.length } editor strings written` );
}
