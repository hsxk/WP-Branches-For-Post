const fs = require( 'node:fs' );
const { chromium } = require( 'playwright-core' );

const baseUrl = process.env.WBFP_BASE_URL || 'http://127.0.0.1:8080';
const originalId = Number( process.env.WBFP_BROWSER_POST_ID || 0 );
const username = process.env.WBFP_BROWSER_USER || 'admin';
const password = process.env.WBFP_BROWSER_PASSWORD || 'test-password';

if ( ! originalId ) {
	throw new Error( 'WBFP_BROWSER_POST_ID is required.' );
}

const browserCandidates = [
	process.env.CHROME_BIN,
	'/usr/bin/google-chrome',
	'/usr/bin/google-chrome-stable',
	'/usr/bin/chromium',
	'/usr/bin/chromium-browser',
].filter( Boolean );
const executablePath = browserCandidates.find( ( candidate ) => fs.existsSync( candidate ) );
if ( ! executablePath ) {
	throw new Error( 'No system Chromium/Chrome executable found.' );
}

let checks = 0;
const check = ( condition, message ) => {
	if ( ! condition ) {
		throw new Error( `BROWSER FAIL: ${ message }` );
	}
	checks += 1;
	console.log( `BROWSER PASS ${ checks }: ${ message }` );
};

async function visible( locator ) {
	return ( await locator.count() ) > 0 && ( await locator.first().isVisible().catch( () => false ) );
}

async function ensureBranchPanel( page ) {
	const title = page.getByText( 'Post Branch', { exact: true } );
	if ( ! await visible( title ) ) {
		const settingsButtons = page.locator(
			'button[aria-label*="Settings"], button[aria-label*="settings"], button[aria-label*="Setting"], button[aria-label*="setting"]'
		);
		const count = await settingsButtons.count();
		if ( count > 0 ) {
			await settingsButtons.nth( count - 1 ).click();
		}
	}
	await title.first().waitFor( { state: 'visible', timeout: 20000 } );

	const panel = page.locator( '.components-panel__body' ).filter( {
		has: page.getByText( 'Post Branch', { exact: true } ),
	} ).first();
	if ( await panel.count() ) {
		const toggle = panel.locator( 'button.components-panel__body-toggle' ).first();
		if ( await toggle.count() ) {
			const expanded = await toggle.getAttribute( 'aria-expanded' );
			if ( 'true' !== expanded ) {
				await toggle.click();
			}
		}
	}
}

(async () => {
	const browser = await chromium.launch( {
		headless: true,
		executablePath,
		args: [ '--no-sandbox', '--disable-dev-shm-usage' ],
	} );
	const context = await browser.newContext();
	const page = await context.newPage();

	try {
		await page.goto( `${ baseUrl }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
		await page.locator( '#user_login' ).fill( username );
		await page.locator( '#user_pass' ).fill( password );
		await Promise.all( [
			page.waitForURL( /\/wp-admin\//, { timeout: 15000 } ),
			page.locator( '#wp-submit' ).click(),
		] );
		check( page.url().includes( '/wp-admin/' ), 'Logged into the real WordPress admin in Chromium' );

		await page.goto( `${ baseUrl }/wp-admin/post.php?post=${ originalId }&action=edit`, {
			waitUntil: 'domcontentloaded',
		} );
		await page.waitForFunction(
			( id ) =>
				window.wp &&
				window.wp.data &&
				window.wp.data.select( 'core/editor' ).getCurrentPostId() === id,
			originalId,
			{ timeout: 20000 }
		);
		check( await page.locator( 'body' ).evaluate( ( node ) => node.classList.contains( 'block-editor-page' ) ), 'Opened the original in the Gutenberg editor' );

		await ensureBranchPanel( page );
		check( await visible( page.getByText( 'Post Branch', { exact: true } ) ), 'Post Branch panel renders in Gutenberg' );
		const originalStatus = await page.evaluate( async ( id ) => {
			const fetcher = window.wp.apiFetch.default || window.wp.apiFetch;
			return fetcher( { path: `/wbfp/v1/posts/${ id }/status` } );
		}, originalId );
		console.log( 'BROWSER ORIGINAL STATUS:', JSON.stringify( originalStatus ) );
		check( originalStatus.type === 'original' && originalStatus.can_create === true, 'Browser REST status allows branch creation for the saved original' );
		const createButton = page.getByRole( 'button', { name: 'Create branch', exact: true } );
		await createButton.waitFor( { state: 'visible', timeout: 10000 } );
		check( ! await createButton.isDisabled(), 'Create branch action is enabled on a saved original' );

		await createButton.click();
		await page.waitForURL( /\/wp-admin\/post\.php\?post=\d+&action=edit/, { timeout: 20000 } );
		const branchMatch = page.url().match( /[?&]post=(\d+)/ );
		const branchId = branchMatch ? Number( branchMatch[ 1 ] ) : 0;
		check( branchId > 0 && branchId !== originalId, 'Create branch navigates to a distinct real branch post' );

		await page.waitForFunction(
			( id ) =>
				window.wp &&
				window.wp.data &&
				window.wp.data.select( 'core/editor' ).getCurrentPostId() === id,
			branchId,
			{ timeout: 20000 }
		);
		await ensureBranchPanel( page );
		check( await visible( page.getByRole( 'button', { name: 'Review changes', exact: true } ) ), 'Branch editor exposes Review changes' );
		check( await visible( page.getByRole( 'link', { name: 'Preview branch', exact: true } ) ), 'Branch preview link is available' );
		check( await visible( page.getByRole( 'link', { name: 'View original', exact: true } ) ), 'Original preview link is available' );

		await page.evaluate( () => {
			window.wp.data.dispatch( 'core/editor' ).editPost( {
				content: '<!-- wp:paragraph --><p>Browser E2E branch content 2.1</p><!-- /wp:paragraph -->',
			} );
		} );
		await page.waitForFunction(
			() => window.wp.data.select( 'core/editor' ).isEditedPostDirty(),
			null,
			{ timeout: 10000 }
		);
		check( true, 'Changed branch content inside the real Gutenberg data store without manually saving' );

		await page.getByRole( 'button', { name: 'Review changes', exact: true } ).click();
		await page.getByText( 'Merge review', { exact: true } ).waitFor( { state: 'visible', timeout: 20000 } );
		check( await visible( page.getByText( 'Branch changes', { exact: true } ) ), 'Review refreshes after autosaving and shows branch changes' );
		check( await visible( page.getByText( 'Three-way comparison', { exact: true } ) ), 'Three-way comparison renders in the real editor' );
		const mergeButton = page.getByRole( 'button', { name: 'Save & merge into original', exact: true } );
		await mergeButton.waitFor( { state: 'visible', timeout: 10000 } );
		check( ! await mergeButton.isDisabled(), 'Reviewed branch exposes the normal merge action' );

		await mergeButton.click();
		await page.waitForURL( new RegExp( `/wp-admin/post\\.php\\?post=${ originalId }&action=edit` ), {
			timeout: 20000,
		} );
		await page.waitForFunction(
			( id ) =>
				window.wp &&
				window.wp.data &&
				window.wp.data.select( 'core/editor' ).getCurrentPostId() === id,
			originalId,
			{ timeout: 20000 }
		);
		const mergedContent = await page.evaluate( () =>
			window.wp.data.select( 'core/editor' ).getEditedPostContent()
		);
		check( mergedContent.includes( 'Browser E2E branch content 2.1' ), 'Browser merge returns to the original with branch content applied' );

		console.log( `BROWSER WORDPRESS RESULT: ${ checks } / ${ checks } checks passed.` );
	} finally {
		await context.close();
		await browser.close();
	}
})().catch( ( error ) => {
	console.error( error );
	process.exit( 1 );
} );
