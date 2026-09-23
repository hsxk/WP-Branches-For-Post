const h = require( './tutorial-helpers' );

(async function () {
	const session = await h.newBrowser();
	const page = session.page;
	try {
		await h.login( page );

		const originalId = h.createPost( {
			title: 'Tutorial block-editor original',
			content: '<!-- wp:paragraph --><p>Original tutorial content.</p><!-- /wp:paragraph -->',
			excerpt: 'Original tutorial excerpt.',
		} );

		await page.goto( h.baseUrl + '/?p=' + originalId, { waitUntil: 'domcontentloaded' } );
		const adminBar = page.locator( '#wpadminbar' );
		await adminBar.waitFor( { state: 'visible', timeout: 10000 } );
		await page.locator( '#wp-admin-bar-wbfp-create-branch' ).waitFor( {
			state: 'visible',
			timeout: 10000,
		} );
		await h.shot( page, '02-admin-bar-create-branch.png', adminBar );

		await page.goto( h.baseUrl + '/wp-admin/edit.php', { waitUntil: 'domcontentloaded' } );
		const originalRow = page.locator( '#post-' + originalId );
		await originalRow.waitFor( { state: 'visible', timeout: 10000 } );
		await originalRow.hover();
		await originalRow.getByText( 'Create branch', { exact: true } ).waitFor( {
			state: 'visible',
			timeout: 10000,
		} );
		await h.shot( page, '03-post-list-create-branch.png', originalRow );

		const branchId = h.createBranch( originalId );
		h.updatePost( branchId, {
			post_content: '<!-- wp:paragraph --><p>Branch-only tutorial content.</p><!-- /wp:paragraph -->',
		} );

		await h.gotoEditor( page, branchId );
		const branchPanel = page.locator( '.components-panel__body' ).filter( {
			has: page.getByText( 'Post Branch', { exact: true } ),
		} ).first();
		await h.shot(
			page,
			'05-branch-relationship-notice.png',
			branchPanel.getByText( /This draft is isolated from the public original\./ )
		);
		await h.shot(
			page,
			'06-preview-links.png',
			page.getByRole( 'link', { name: 'Preview branch', exact: true } )
		);

		await h.openReview( page );
		await h.shot(
			page,
			'07-review-overview.png',
			page.getByText( 'Merge review', { exact: true } )
		);
		await h.shot(
			page,
			'09-branch-only-change.png',
			page.getByText( 'Branch changes', { exact: true } )
		);

		h.updatePost( originalId, {
			post_excerpt: 'Original-only excerpt added after branch creation.',
		} );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await h.waitForBlockEditor( page, branchId );
		await h.openReview( page );
		await h.shot(
			page,
			'10-original-only-change.png',
			page.getByText( 'Original changes since branch creation', { exact: true } )
		);

		h.rebaseBranch( branchId );
		h.updatePost( originalId, { post_name: 'tutorial-identity-preserved' } );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await h.waitForBlockEditor( page, branchId );
		await h.openReview( page );
		await h.shot(
			page,
			'11-identity-change-preserved.png',
			page.getByText( 'Original identity changes preserved by merge', { exact: true } )
		);

		h.rebaseBranch( branchId );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await h.waitForBlockEditor( page, branchId );
		await h.openReview( page );
		await h.shot(
			page,
			'14-normal-merge-ready.png',
			page.getByRole( 'button', { name: 'Save & merge into original', exact: true } )
		);

		h.updatePost( originalId, {
			post_content: '<!-- wp:paragraph --><p>Original competing content after rebase.</p><!-- /wp:paragraph -->',
		} );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await h.waitForBlockEditor( page, branchId );
		await h.openReview( page );
		await page.getByRole( 'button', {
			name: 'Force merge reviewed conflicts',
			exact: true,
		} ).waitFor( { state: 'visible', timeout: 15000 } );
		await h.shot(
			page,
			'16-conflict-three-way-detail.png',
			page.getByText( 'Three-way comparison', { exact: true } )
		);

		console.log( 'Tutorial block-editor extras complete.' );
	} finally {
		await session.context.close();
		await session.browser.close();
	}
})().catch( function ( error ) {
	console.error( error );
	process.exit( 1 );
} );
