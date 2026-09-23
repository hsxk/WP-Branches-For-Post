const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { execFileSync } = require( 'node:child_process' );
const { chromium } = require( 'playwright-core' );

const baseUrl = process.env.WBFP_BASE_URL || 'http://127.0.0.1:8080';
const username = process.env.WBFP_BROWSER_USER || 'admin';
const password = process.env.WBFP_BROWSER_PASSWORD || 'test-password';
const wpPath = process.env.WBFP_WP_PATH || '/tmp/wordpress';
const outputDir = path.resolve( process.cwd(), 'docs/tutorial-2.1/screenshots' );

fs.mkdirSync( outputDir, { recursive: true } );

function wp( args ) {
	return execFileSync( 'wp', [ '--path=' + wpPath ].concat( args ), {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).trim();
}

function createPost( options ) {
	const opts = Object.assign(
		{
			type: 'post',
			status: 'publish',
			content: 'Tutorial fixture content.',
			excerpt: '',
			date: '',
		},
		options
	);
	const args = [
		'post',
		'create',
		'--post_type=' + opts.type,
		'--post_status=' + opts.status,
		'--post_title=' + opts.title,
		'--post_content=' + opts.content,
		'--post_excerpt=' + opts.excerpt,
	];
	if ( opts.date ) {
		args.push( '--post_date=' + opts.date );
	}
	args.push( '--porcelain' );
	return Number( wp( args ) );
}

function updatePost( postId, fields ) {
	const args = [ 'post', 'update', String( postId ) ];
	Object.entries( fields ).forEach( function ( entry ) {
		args.push( '--' + entry[ 0 ] + '=' + entry[ 1 ] );
	} );
	wp( args );
}

function deleteMeta( postId, key ) {
	try {
		wp( [ 'post', 'meta', 'delete', String( postId ), key ] );
	} catch ( error ) {
		// Missing metadata is already the desired state.
	}
}

function createBranch( originalId ) {
	const code = [
		'wp_set_current_user(1);',
		'$service=new \\WP_Branches_For_Post\\Branch_Service();',
		'$result=$service->create(' + Number( originalId ) + ');',
		'if(is_wp_error($result)){fwrite(STDERR,$result->get_error_code().": ".$result->get_error_message());exit(1);}',
		'echo (int)$result;',
	].join( '' );
	return Number( wp( [ 'eval', code ] ) );
}

function rebaseBranch( branchId ) {
	const code = [
		'wp_set_current_user(1);',
		'$service=new \\WP_Branches_For_Post\\Branch_Service();',
		'$result=$service->rebase(' + Number( branchId ) + ');',
		'if(is_wp_error($result)){fwrite(STDERR,$result->get_error_code().": ".$result->get_error_message());exit(1);}',
	].join( '' );
	wp( [ 'eval', code ] );
}

function mergeBranch( branchId, force ) {
	const code = [
		'wp_set_current_user(1);',
		'$branches=new \\WP_Branches_For_Post\\Branch_Service();',
		'$service=new \\WP_Branches_For_Post\\Merge_Service($branches);',
		'$result=$service->merge(' + Number( branchId ) + ',' + ( force ? 'true' : 'false' ) + ');',
		'if(is_wp_error($result)){fwrite(STDERR,$result->get_error_code().": ".$result->get_error_message());exit(1);}',
	].join( '' );
	wp( [ 'eval', code ] );
}

function discardBranch( branchId ) {
	const code = [
		'wp_set_current_user(1);',
		'$branches=new \\WP_Branches_For_Post\\Branch_Service();',
		'$service=new \\WP_Branches_For_Post\\Merge_Service($branches);',
		'$result=$service->discard(' + Number( branchId ) + ');',
		'if(is_wp_error($result)){fwrite(STDERR,$result->get_error_code().": ".$result->get_error_message());exit(1);}',
	].join( '' );
	wp( [ 'eval', code ] );
}

function chromePath() {
	const candidates = [
		process.env.CHROME_BIN,
		'/usr/bin/google-chrome',
		'/usr/bin/google-chrome-stable',
		'/usr/bin/chromium',
		'/usr/bin/chromium-browser',
	].filter( Boolean );
	const found = candidates.find( function ( candidate ) {
		return fs.existsSync( candidate );
	} );
	if ( ! found ) {
		throw new Error( 'No system Chromium/Chrome executable found.' );
	}
	return found;
}

async function newBrowser() {
	const browser = await chromium.launch( {
		headless: true,
		executablePath: chromePath(),
		args: [ '--no-sandbox', '--disable-dev-shm-usage' ],
	} );
	const context = await browser.newContext( {
		viewport: { width: 1440, height: 1000 },
		deviceScaleFactor: 1,
		locale: 'en-US',
	} );
	const page = await context.newPage();
	return { browser: browser, context: context, page: page };
}

async function visible( locator ) {
	return (
		( await locator.count() ) > 0 &&
		( await locator.first().isVisible().catch( function () { return false; } ) )
	);
}

async function login( page ) {
	await page.goto( baseUrl + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await Promise.all( [
		page.waitForURL( /\/wp-admin\//, { timeout: 15000 } ),
		page.locator( '#wp-submit' ).click(),
	] );
}

async function closeEditorModal( page ) {
	const overlay = page.locator( '.components-modal__screen-overlay' );
	if ( ! ( await visible( overlay ) ) ) {
		return;
	}
	const buttons = [
		page.getByRole( 'button', { name: 'Close', exact: true } ),
		page.locator( 'button[aria-label="Close"]' ),
		page.locator( 'button[aria-label="Close dialog"]' ),
	];
	for ( const button of buttons ) {
		if ( await visible( button ) ) {
			await button.first().click();
			await overlay.waitFor( { state: 'hidden', timeout: 5000 } ).catch( function () {} );
			return;
		}
	}
	await page.keyboard.press( 'Escape' );
}

async function ensurePanel( page ) {
	await closeEditorModal( page );
	const title = page.getByText( 'Post Branch', { exact: true } );
	if ( ! ( await visible( title ) ) ) {
		const settings = page.locator(
			'button[aria-label*="Settings"], button[aria-label*="settings"], button[aria-label*="Setting"], button[aria-label*="setting"]'
		);
		const count = await settings.count();
		if ( count > 0 ) {
			await settings.nth( count - 1 ).click();
		}
	}
	await title.first().waitFor( { state: 'visible', timeout: 20000 } );
	const panel = page.locator( '.components-panel__body' ).filter( { has: title.first() } ).first();
	const toggle = panel.locator( 'button.components-panel__body-toggle' ).first();
	if (
		( await toggle.count() ) &&
		'true' !== ( await toggle.getAttribute( 'aria-expanded' ) )
	) {
		await toggle.click();
	}
	return panel;
}

async function waitForBlockEditor( page, postId ) {
	await page.waitForFunction(
		function ( id ) {
			const store =
				window.wp &&
				window.wp.data &&
				window.wp.data.select &&
				window.wp.data.select( 'core/editor' );
			return Boolean(
				store &&
					store.getCurrentPostId &&
					store.getCurrentPostId() === id
			);
		},
		postId,
		{ timeout: 20000 }
	);
	await ensurePanel( page );
	await page.waitForTimeout( 300 );
}

async function gotoEditor( page, postId ) {
	await page.goto(
		baseUrl + '/wp-admin/post.php?post=' + postId + '&action=edit',
		{ waitUntil: 'domcontentloaded' }
	);
	await waitForBlockEditor( page, postId );
}

async function openReview( page ) {
	const button = page.getByRole( 'button', { name: 'Review changes', exact: true } );
	await button.waitFor( { state: 'visible', timeout: 10000 } );
	await button.click();
	await page.getByText( 'Merge review', { exact: true } ).waitFor( {
		state: 'visible',
		timeout: 15000,
	} );
}

async function shot( page, file, locator ) {
	if ( locator ) {
		if ( ! ( await visible( locator ) ) ) {
			throw new Error( 'Tutorial case target is not visible: ' + file );
		}
		await locator.first().scrollIntoViewIfNeeded();
		await page.waitForTimeout( 250 );
	}
	await page.screenshot( {
		path: path.join( outputDir, file ),
		fullPage: false,
		animations: 'disabled',
	} );
	console.log( 'TUTORIAL SHOT: ' + file );
}

module.exports = {
	baseUrl,
	outputDir,
	wp,
	createPost,
	updatePost,
	deleteMeta,
	createBranch,
	rebaseBranch,
	mergeBranch,
	discardBranch,
	newBrowser,
	visible,
	login,
	gotoEditor,
	waitForBlockEditor,
	openReview,
	shot,
};
