const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { execFileSync } = require( 'node:child_process' );
const { chromium } = require( 'playwright-core' );

const baseUrl = process.env.WBFP_BASE_URL || 'http://127.0.0.1:8080';
const originalId = Number( process.env.WBFP_SCREENSHOT_POST_ID || 0 );
const username = process.env.WBFP_BROWSER_USER || 'admin';
const password = process.env.WBFP_BROWSER_PASSWORD || 'test-password';
const wpPath = process.env.WBFP_WP_PATH || '/tmp/wordpress';
const outputDir = path.resolve( process.cwd(), '.wordpress-org' );

if ( ! originalId ) {
	throw new Error( 'WBFP_SCREENSHOT_POST_ID is required.' );
}

fs.mkdirSync( outputDir, { recursive: true } );

function wpCli( args ) {
	return execFileSync( 'wp', [ `--path=${ wpPath }`, ...args ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).trim();
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

async function closeEditorModal( page ) {
	const overlay = page.locator( '.components-modal__screen-overlay' );
	if ( ! await visible( overlay ) ) {
		return;
	}
	const closeButtons = [
		page.getByRole( 'button', { name: 'Close', exact: true } ),
		page.locator( 'button[aria-label="Close"]' ),
		page.locator( 'button[aria-label="Close dialog"]' ),
	];
	for ( const button of closeButtons ) {
		if ( await visible( button ) ) {
			await button.first().click();
			await overlay.waitFor( { state: 'hidden', timeout: 5000 } ).catch( () => {} );
			return;
		}
	}
	await page.keyboard.press( 'Escape' );
	await overlay.waitFor( { state: 'hidden', timeout: 5000 } ).catch( () => {} );
}

async function visible( locator ) {
	return ( await locator.count() ) > 0 && ( await locator.first().isVisible().catch( () => false ) );
}

async function ensureBranchPanel( page ) {
	await closeEditorModal( page );
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
		if ( await toggle.count() && 'true' !== await toggle.getAttribute( 'aria-expanded' ) ) {
			await toggle.click();
		}
	}
}

async function waitForEditor( page, postId ) {
	await page.waitForFunction(
		( id ) => {
			const editorStore =
				window.wp &&
				window.wp.data &&
				window.wp.data.select &&
				window.wp.data.select( 'core/editor' );
			return Boolean(
				editorStore &&
					editorStore.getCurrentPostId &&
					editorStore.getCurrentPostId() === id
			);
		},
		postId,
		{ timeout: 20000 }
	);
	await ensureBranchPanel( page );
	await page.waitForTimeout( 500 );
}

async function shot( page, number ) {
	await page.screenshot( {
		path: path.join( outputDir, `screenshot-${ number }.png` ),
		fullPage: false,
		animations: 'disabled',
	} );
}

(async () => {
	const browser = await chromium.launch( {
		headless: true,
		executablePath,
		args: [ '--no-sandbox', '--disable-dev-shm-usage' ],
	} );
	const context = await browser.newContext( {
		viewport: { width: 1440, height: 1000 },
		deviceScaleFactor: 1,
		locale: 'en-US',
	} );
	const page = await context.newPage();

	try {
		await page.goto( `${ baseUrl }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
		await page.locator( '#user_login' ).fill( username );
		await page.locator( '#user_pass' ).fill( password );
		await Promise.all( [
			page.waitForURL( /\/wp-admin\//, { timeout: 15000 } ),
			page.locator( '#wp-submit' ).click(),
		] );

		// Normalize the fixture so every validation workflow renders the same
		// WordPress.org screenshot set regardless of its caller-provided seed copy.
		wpCli( [
			'post', 'update', String( originalId ),
			'--post_title=WP Branch 2.1 documentation example',
			'--post_content=Original content used to demonstrate the 2.1 branch workflow.',
			'--post_excerpt=Original excerpt before branching.',
		] );

		// 1. Original post with Create branch.
		await page.goto( `${ baseUrl }/wp-admin/post.php?post=${ originalId }&action=edit`, {
			waitUntil: 'domcontentloaded',
		} );
		await waitForEditor( page, originalId );
		const createButton = page.getByRole( 'button', { name: 'Create branch', exact: true } );
		await createButton.waitFor( { state: 'visible', timeout: 10000 } );
		await createButton.scrollIntoViewIfNeeded();
		await shot( page, 1 );

		// Create one branch and use it for the remaining documentation states.
		await createButton.click();
		await page.waitForFunction(
			( id ) => {
				const current = Number(
					new URL( window.location.href ).searchParams.get( 'post' ) || 0
				);
				return current > 0 && current !== id;
			},
			originalId,
			{ timeout: 20000 }
		);
		const match = page.url().match( /[?&]post=(\d+)/ );
		const branchId = match ? Number( match[ 1 ] ) : 0;
		if ( ! branchId || branchId === originalId ) {
			throw new Error( 'Could not resolve documentation branch ID.' );
		}
		await waitForEditor( page, branchId );

		// 2. Working branch controls.
		await page.getByRole( 'button', { name: 'Review changes', exact: true } ).waitFor( {
			state: 'visible',
			timeout: 10000,
		} );
		await shot( page, 2 );

		// Create a branch-only edit, save it through the review action, and capture
		// the detailed three-way comparison.
		await page.evaluate( () => {
			window.wp.data.dispatch( 'core/editor' ).editPost( {
				content: '<!-- wp:paragraph --><p>Documentation branch content for the 2.1 merge review.</p><!-- /wp:paragraph -->',
			} );
		} );
		await page.getByRole( 'button', { name: 'Review changes', exact: true } ).click();
		await page.getByText( 'Merge review', { exact: true } ).waitFor( { state: 'visible', timeout: 20000 } );
		await page.getByText( 'Three-way comparison', { exact: true } ).waitFor( { state: 'visible', timeout: 10000 } );
		await shot( page, 3 );

		// 4. Newer original-only work: rebase/update is available.
		wpCli( [
			'post', 'update', String( originalId ),
			'--post_excerpt=A newer excerpt edited directly on the original while the branch is open.',
		] );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await waitForEditor( page, branchId );
		const updateButton = page.getByRole( 'button', { name: 'Update branch from original', exact: true } );
		await updateButton.waitFor( {
			state: 'visible',
			timeout: 15000,
		} );
		await shot( page, 4 );

		// 5. Complete the rebase and show the clean reviewed state.
		await updateButton.click();
		await page.waitForLoadState( 'domcontentloaded' );
		await waitForEditor( page, branchId );
		await page.getByRole( 'button', { name: 'Review changes', exact: true } ).click();
		await page.getByText( 'Merge review', { exact: true } ).waitFor( {
			state: 'visible',
			timeout: 15000,
		} );
		await page.getByRole( 'button', { name: 'Save & merge into original', exact: true } ).waitFor( {
			state: 'visible',
			timeout: 10000,
		} );
		await shot( page, 5 );

		// 6. Turn the same branch into a true overlapping conflict.
		wpCli( [
			'post', 'update', String( originalId ),
			'--post_content=Original competing content created after the branch.',
		] );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await waitForEditor( page, branchId );
		await page.getByRole( 'button', { name: 'Review changes', exact: true } ).click();
		const forceButton = page.getByRole( 'button', { name: 'Force merge reviewed conflicts', exact: true } );
		await forceButton.waitFor( { state: 'visible', timeout: 15000 } );
		await forceButton.scrollIntoViewIfNeeded();
		await shot( page, 6 );

		// 7. Explicit destructive confirmation before force merge.
		await forceButton.click();
		await page.getByText( 'Force merge conflicts?', { exact: true } ).waitFor( {
			state: 'visible',
			timeout: 10000,
		} );
		await shot( page, 7 );
		await page.getByRole( 'button', { name: 'Cancel', exact: true } ).click();

		// 8. A reviewed state that changed is rejected and must be reviewed again.
		wpCli( [
			'post', 'update', String( originalId ),
			'--post_title=Original changed again after the conflict review.',
		] );
		await forceButton.click();
		await page.getByText( 'Force merge conflicts?', { exact: true } ).waitFor( {
			state: 'visible',
			timeout: 10000,
		} );
		await page.getByRole( 'button', { name: 'Force merge', exact: true } ).click();
		await page.locator( '.components-notice' ).getByText(
			'The review state changed after saving. Review the latest conflicts before merging.',
			{ exact: true }
		).waitFor( {
			state: 'visible',
			timeout: 15000,
		} );
		await shot( page, 8 );

		// 9. Discard is also explicit and non-destructive to the public original.
		await page.getByRole( 'button', { name: 'Discard branch', exact: true } ).click();
		await page.getByText( 'Discard this branch?', { exact: true } ).waitFor( {
			state: 'visible',
			timeout: 10000,
		} );
		await shot( page, 9 );
		await page.getByRole( 'button', { name: 'Cancel', exact: true } ).click();

		// 10. Original post shows the active branch and its current review state.
		await page.goto( `${ baseUrl }/wp-admin/post.php?post=${ originalId }&action=edit`, {
			waitUntil: 'domcontentloaded',
		} );
		await waitForEditor( page, originalId );
		await page.getByText( 'Existing branches', { exact: true } ).waitFor( {
			state: 'visible',
			timeout: 10000,
		} );
		await shot( page, 10 );

		// 11. Admin post list integration keeps branch state/actions discoverable.
		await page.goto( `${ baseUrl }/wp-admin/edit.php`, { waitUntil: 'domcontentloaded' } );
		await page.locator( '#the-list' ).waitFor( { state: 'visible', timeout: 15000 } );
		const branchRow = page.locator( `#post-${ branchId }` );
		await branchRow.waitFor( { state: 'visible', timeout: 10000 } );
		await branchRow.hover();
		await page.waitForTimeout( 400 );
		await shot( page, 11 );

		// 12. Synced patterns are global, so the branch panel warns explicitly.
		await page.goto( `${ baseUrl }/wp-admin/post.php?post=${ originalId }&action=edit`, {
			waitUntil: 'domcontentloaded',
		} );
		await waitForEditor( page, originalId );
		const secondCreateButton = page.getByRole( 'button', { name: 'Create branch', exact: true } );
		await secondCreateButton.waitFor( { state: 'visible', timeout: 10000 } );
		await secondCreateButton.click();
		await page.waitForFunction(
			( ids ) => {
				const current = Number(
					new URL( window.location.href ).searchParams.get( 'post' ) || 0
				);
				return current > 0 && ! ids.includes( current );
			},
			[ originalId, branchId ],
			{ timeout: 20000 }
		);
		const secondMatch = page.url().match( /[?&]post=(\d+)/ );
		const patternBranchId = secondMatch ? Number( secondMatch[ 1 ] ) : 0;
		if ( ! patternBranchId || patternBranchId === originalId || patternBranchId === branchId ) {
			throw new Error( 'Could not resolve synced-pattern documentation branch ID.' );
		}
		await waitForEditor( page, patternBranchId );
		const patternId = Number( wpCli( [
			'post', 'create',
			'--post_type=wp_block',
			'--post_status=publish',
			'--post_title=Documentation synced pattern',
			'--post_content=<!-- wp:paragraph --><p>Global synced pattern content.</p><!-- /wp:paragraph -->',
			'--porcelain',
		] ) );
		if ( ! patternId ) {
			throw new Error( 'Could not create synced-pattern fixture.' );
		}
		wpCli( [
			'post', 'update', String( patternBranchId ),
			`--post_content=<!-- wp:block {"ref":${ patternId }} /-->`,
		] );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await waitForEditor( page, patternBranchId );
		await page.locator( '.components-notice' ).getByText( /This branch references 1 synced pattern/ ).waitFor( {
			state: 'visible',
			timeout: 15000,
		} );
		await shot( page, 12 );

		console.log( JSON.stringify( {
			originalId,
			branchId,
			screenshots: 12,
			outputDir,
		} ) );
	} finally {
		await context.close();
		await browser.close();
	}
})().catch( ( error ) => {
	console.error( error );
	process.exit( 1 );
} );
