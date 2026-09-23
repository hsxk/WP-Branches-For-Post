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
const executablePath = browserCandidates.find( ( candidate ) =>
	fs.existsSync( candidate )
);
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
	return (
		( await locator.count() ) > 0 &&
		( await locator.first().isVisible().catch( () => false ) )
	);
}

async function closeEditorModal( page ) {
	const overlay = page.locator( '.components-modal__screen-overlay' );
	if ( ! ( await visible( overlay ) ) ) {
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
			await overlay
				.waitFor( { state: 'hidden', timeout: 5000 } )
				.catch( () => {} );
			return;
		}
	}
	await page.keyboard.press( 'Escape' );
	await overlay
		.waitFor( { state: 'hidden', timeout: 5000 } )
		.catch( () => {} );
}

async function ensureBranchPanel( page ) {
	await closeEditorModal( page );
	const title = page.getByText( 'Post Branch', { exact: true } );
	if ( ! ( await visible( title ) ) ) {
		const settingsButtons = page.locator(
			'button[aria-label*="Settings"], button[aria-label*="settings"], button[aria-label*="Setting"], button[aria-label*="setting"]'
		);
		const count = await settingsButtons.count();
		if ( count > 0 ) {
			await settingsButtons.nth( count - 1 ).click();
		}
	}
	await title.first().waitFor( { state: 'visible', timeout: 20000 } );

	const panel = page
		.locator( '.components-panel__body' )
		.filter( {
			has: page.getByText( 'Post Branch', { exact: true } ),
		} )
		.first();
	if ( await panel.count() ) {
		const toggle = panel
			.locator( 'button.components-panel__body-toggle' )
			.first();
		if ( await toggle.count() ) {
			const expanded = await toggle.getAttribute( 'aria-expanded' );
			if ( 'true' !== expanded ) {
				await toggle.click();
			}
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
}

async function editBranch( page, values ) {
	await page.evaluate( ( edits ) => {
		window.wp.data.dispatch( 'core/editor' ).editPost( edits );
	}, values );
	await page.waitForFunction(
		() => window.wp.data.select( 'core/editor' ).isEditedPostDirty(),
		null,
		{ timeout: 10000 }
	);
}

async function apiFetch( page, request ) {
	return page.evaluate( async ( args ) => {
		const fetcher = window.wp.apiFetch.default || window.wp.apiFetch;
		return fetcher( args );
	}, request );
}

async function branchStatus( page, postId ) {
	return apiFetch( page, {
		path: `/wbfp/v1/posts/${ postId }/status`,
	} );
}

async function updateOriginal( page, data ) {
	return apiFetch( page, {
		path: `/wp/v2/posts/${ originalId }`,
		method: 'POST',
		data,
	} );
}

async function createBranchFromCurrentOriginal( page ) {
	await ensureBranchPanel( page );
	const createButton = page.getByRole( 'button', {
		name: 'Create branch',
		exact: true,
	} );
	await createButton.waitFor( { state: 'visible', timeout: 10000 } );
	check(
		! ( await createButton.isDisabled() ),
		'Create branch action is enabled on the saved original'
	);
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
	const branchMatch = page.url().match( /[?&]post=(\d+)/ );
	const branchId = branchMatch ? Number( branchMatch[ 1 ] ) : 0;
	check(
		branchId > 0 && branchId !== originalId,
		'Create branch navigates to a distinct real branch post'
	);
	await waitForEditor( page, branchId );
	return branchId;
}

async function openOriginal( page ) {
	await page.goto(
		`${ baseUrl }/wp-admin/post.php?post=${ originalId }&action=edit`,
		{ waitUntil: 'domcontentloaded' }
	);
	await waitForEditor( page, originalId );
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
		await page.goto( `${ baseUrl }/wp-login.php`, {
			waitUntil: 'domcontentloaded',
		} );
		await page.locator( '#user_login' ).fill( username );
		await page.locator( '#user_pass' ).fill( password );
		await Promise.all( [
			page.waitForURL( /\/wp-admin\//, { timeout: 15000 } ),
			page.locator( '#wp-submit' ).click(),
		] );
		check(
			page.url().includes( '/wp-admin/' ),
			'Logged into the real WordPress admin in Chromium'
		);

		// Scenario 1: main Gutenberg workflow + stale reviewed-state protection.
		await openOriginal( page );
		check(
			await page
				.locator( 'body' )
				.evaluate( ( node ) =>
					node.classList.contains( 'block-editor-page' )
				),
			'Opened the original in the Gutenberg editor'
		);
		check(
			await visible(
				page.getByText( 'Post Branch', { exact: true } )
			),
			'Post Branch panel renders in Gutenberg'
		);
		const originalStatus = await branchStatus( page, originalId );
		check(
			originalStatus.type === 'original' &&
				originalStatus.can_create === true,
			'Browser REST status allows branch creation for the saved original'
		);

		const firstBranchId = await createBranchFromCurrentOriginal( page );
		check(
			await visible(
				page.getByRole( 'button', {
					name: 'Review changes',
					exact: true,
				} )
			),
			'Branch editor exposes Review changes'
		);
		check(
			await visible(
				page.getByRole( 'link', {
					name: 'Preview branch',
					exact: true,
				} )
			),
			'Branch preview link is available'
		);
		check(
			await visible(
				page.getByRole( 'link', {
					name: 'View original',
					exact: true,
				} )
			),
			'Original preview link is available'
		);

		await editBranch( page, {
			content:
				'<!-- wp:paragraph --><p>Browser E2E branch content 2.1</p><!-- /wp:paragraph -->',
		} );
		check(
			true,
			'Changed branch content inside the real Gutenberg data store without manually saving'
		);

		await page
			.getByRole( 'button', {
				name: 'Review changes',
				exact: true,
			} )
			.click();
		await page
			.getByText( 'Merge review', { exact: true } )
			.waitFor( { state: 'visible', timeout: 20000 } );
		check(
			await visible(
				page.getByText( 'Branch changes', { exact: true } )
			),
			'Review autosaves and shows branch changes'
		);
		check(
			await visible(
				page.getByText( 'Three-way comparison', { exact: true } )
			),
			'Three-way comparison renders in the real editor'
		);
		const reviewedStatus = await branchStatus( page, firstBranchId );
		check(
			Boolean( reviewedStatus.review_token ),
			'Real editor status includes a reviewed-state token'
		);

		// Change the branch after review. The first merge click must not merge.
		await editBranch( page, {
			excerpt: 'Changed in Gutenberg after the prior review',
		} );
		const mergeButton = page.getByRole( 'button', {
			name: 'Save & merge into original',
			exact: true,
		} );
		await mergeButton.waitFor( { state: 'visible', timeout: 10000 } );
		await mergeButton.click();
		await page.locator( '.components-notice__content' ).filter( { hasText: 'The review state changed after saving. Review the latest conflicts before merging.' } ).first()
			.waitFor( { state: 'visible', timeout: 20000 } );
		check(
			page.url().includes( `post=${ firstBranchId }` ),
			'Merge is blocked when branch state changes after review'
		);
		const refreshedStatus = await branchStatus( page, firstBranchId );
		check(
			reviewedStatus.review_token !== refreshedStatus.review_token,
			'Blocked stale merge refreshes to a different review token'
		);

		await mergeButton.click();
		await page.waitForURL(
			new RegExp(
				`/wp-admin/post\\.php\\?post=${ originalId }&action=edit`
			),
			{ timeout: 20000 }
		);
		await waitForEditor( page, originalId );
		const firstMerged = await page.evaluate( () => ( {
			content:
				window.wp.data
					.select( 'core/editor' )
					.getEditedPostContent(),
			excerpt:
				window.wp.data
					.select( 'core/editor' )
					.getEditedPostAttribute( 'excerpt' ),
		} ) );
		check(
			firstMerged.content.includes(
				'Browser E2E branch content 2.1'
			),
			'Freshly reviewed browser merge applies branch content'
		);
		check(
			String( firstMerged.excerpt ).includes(
				'Changed in Gutenberg after the prior review'
			),
			'Freshly reviewed browser merge applies the post-review branch edit'
		);

		// Scenario 2: safe rebase must reload Gutenberg with server state.
		const rebaseBranchId = await createBranchFromCurrentOriginal( page );
		await editBranch( page, {
			content:
				'<!-- wp:paragraph --><p>Browser E2E rebase branch content</p><!-- /wp:paragraph -->',
		} );
		await page
			.getByRole( 'button', {
				name: 'Review changes',
				exact: true,
			} )
			.click();
		await page
			.getByText( 'Merge review', { exact: true } )
			.waitFor( { state: 'visible', timeout: 20000 } );

		await updateOriginal( page, {
			excerpt: 'Browser E2E newer original excerpt',
		} );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await waitForEditor( page, rebaseBranchId );
		const updateButton = page.getByRole( 'button', {
			name: 'Update branch from original',
			exact: true,
		} );
		await updateButton.waitFor( { state: 'visible', timeout: 15000 } );
		check(
			true,
			'Real Gutenberg branch exposes Update branch from original for non-conflicting newer work'
		);

		await page.evaluate( () => {
			window.__wbfpRebaseDocumentMarker = 'before-reload';
		} );
		await updateButton.click();
		await page.waitForFunction(
			() => window.__wbfpRebaseDocumentMarker !== 'before-reload',
			null,
			{ timeout: 20000 }
		);
		await waitForEditor( page, rebaseBranchId );
		const rebasedEditorState = await page.evaluate( () => ( {
			content:
				window.wp.data
					.select( 'core/editor' )
					.getEditedPostContent(),
			excerpt:
				window.wp.data
					.select( 'core/editor' )
					.getEditedPostAttribute( 'excerpt' ),
			dirty:
				window.wp.data
					.select( 'core/editor' )
					.isEditedPostDirty(),
		} ) );
		check(
			rebasedEditorState.content.includes(
				'Browser E2E rebase branch content'
			),
			'Rebase reload preserves branch-only Gutenberg content'
		);
		check(
			String( rebasedEditorState.excerpt ).includes(
				'Browser E2E newer original excerpt'
			),
			'Rebase reload imports original-only content into the Gutenberg data store'
		);
		check(
			rebasedEditorState.dirty === false,
			'Rebased Gutenberg editor is synchronized with the saved server state'
		);

		// Scenario 3: true conflict + stale force review + successful force merge.
		await updateOriginal( page, {
			content:
				'<!-- wp:paragraph --><p>Original competing browser content</p><!-- /wp:paragraph -->',
		} );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await waitForEditor( page, rebaseBranchId );
		await page
			.getByRole( 'button', {
				name: 'Review changes',
				exact: true,
			} )
			.click();
		await page
			.getByText( 'Conflicts', { exact: true } )
			.waitFor( { state: 'visible', timeout: 15000 } );
		const forceButton = page.getByRole( 'button', {
			name: 'Force merge reviewed conflicts',
			exact: true,
		} );
		await forceButton.waitFor( { state: 'visible', timeout: 15000 } );
		check(
			true,
			'Real Gutenberg review blocks normal merge and exposes force merge for a true conflict'
		);

		await forceButton.click();
		await page
			.getByText( 'Force merge conflicts?', { exact: true } )
			.waitFor( { state: 'visible', timeout: 10000 } );
		check(
			true,
			'Force merge requires the explicit WordPress confirmation dialog'
		);

		// Make the reviewed force state stale before confirming.
		await updateOriginal( page, {
			title: 'Original title changed while force confirmation was open',
		} );
		await page
			.getByRole( 'button', { name: 'Force merge', exact: true } )
			.click();
		await page.locator( '.components-notice__content' ).filter( { hasText: 'The review state changed after saving. Review the latest conflicts before merging.' } ).first()
			.waitFor( { state: 'visible', timeout: 20000 } );
		check(
			page.url().includes( `post=${ rebaseBranchId }` ),
			'Force merge is blocked when original state changes after review'
		);

		await forceButton.click();
		await page
			.getByText( 'Force merge conflicts?', { exact: true } )
			.waitFor( { state: 'visible', timeout: 10000 } );
		await page
			.getByRole( 'button', { name: 'Force merge', exact: true } )
			.click();
		await page.waitForURL(
			new RegExp(
				`/wp-admin/post\\.php\\?post=${ originalId }&action=edit`
			),
			{ timeout: 20000 }
		);
		await waitForEditor( page, originalId );
		const forceMerged = await page.evaluate( () => ( {
			title:
				window.wp.data
					.select( 'core/editor' )
					.getEditedPostAttribute( 'title' ),
			content:
				window.wp.data
					.select( 'core/editor' )
					.getEditedPostContent(),
			excerpt:
				window.wp.data
					.select( 'core/editor' )
					.getEditedPostAttribute( 'excerpt' ),
		} ) );
		check(
			forceMerged.content.includes(
				'Browser E2E rebase branch content'
			),
			'Force merge prefers the branch value for the reviewed content conflict'
		);
		check(
			String( forceMerged.title ).includes(
				'Original title changed while force confirmation was open'
			),
			'Force merge preserves newer non-conflicting original title work'
		);
		check(
			String( forceMerged.excerpt ).includes(
				'Browser E2E newer original excerpt'
			),
			'Force merge preserves the earlier original-only excerpt'
		);

		// Scenario 4: explicit discard confirmation and Trash lifecycle.
		const discardBranchId = await createBranchFromCurrentOriginal( page );
		const discardButton = page.getByRole( 'button', {
			name: 'Discard branch',
			exact: true,
		} );
		await discardButton.waitFor( { state: 'visible', timeout: 10000 } );
		await discardButton.click();
		await page
			.getByText( 'Discard this branch?', { exact: true } )
			.waitFor( { state: 'visible', timeout: 10000 } );
		check(
			true,
			'Discard requires an explicit WordPress confirmation dialog'
		);
		await page
			.getByRole( 'button', { name: 'Move to Trash', exact: true } )
			.click();
		await page.waitForURL(
			new RegExp(
				`/wp-admin/post\\.php\\?post=${ originalId }&action=edit`
			),
			{ timeout: 20000 }
		);
		await waitForEditor( page, originalId );
		const afterDiscard = await branchStatus( page, originalId );
		check(
			! afterDiscard.branches.some(
				( branch ) => Number( branch.id ) === discardBranchId
			),
			'Discarded branch disappears from the active branch list'
		);

		console.log(
			`BROWSER WORDPRESS RESULT: ${ checks } / ${ checks } checks passed.`
		);
	} finally {
		await context.close();
		await browser.close();
	}
})().catch( ( error ) => {
	console.error( error );
	process.exit( 1 );
} );
