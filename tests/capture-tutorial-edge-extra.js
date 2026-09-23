const fs = require( 'node:fs' );
const h = require( './tutorial-helpers' );

(async function () {
	const session = await h.newBrowser();
	const page = session.page;
	try {
		await h.login( page );

		const classicOriginal = h.createPost( {
			title: 'Tutorial Classic Editor original',
		} );
		h.wp( [ 'option', 'update', 'wbfp_tutorial_force_classic', '1' ] );
		await page.goto(
			h.baseUrl + '/wp-admin/post.php?post=' + classicOriginal + '&action=edit',
			{ waitUntil: 'domcontentloaded' }
		);
		const classicBox = page.locator( '#submitdiv .wbfp-classic-action' );
		await classicBox.waitFor( { state: 'visible', timeout: 10000 } );
		await h.shot( page, '23-classic-original-create.png', classicBox );

		const classicBranch = h.createBranch( classicOriginal );
		h.updatePost( classicBranch, { post_content: 'Classic branch content.' } );
		await page.goto(
			h.baseUrl + '/wp-admin/post.php?post=' + classicBranch + '&action=edit',
			{ waitUntil: 'domcontentloaded' }
		);
		await page.getByText( 'Merge into original', { exact: true } ).waitFor( {
			state: 'visible',
			timeout: 10000,
		} );
		await h.shot(
			page,
			'24-classic-safe-merge.png',
			page.locator( '#submitdiv .wbfp-classic-action' )
		);

		h.updatePost( classicOriginal, { post_title: 'Classic original conflict' } );
		h.updatePost( classicBranch, { post_title: 'Classic branch conflict' } );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.getByText( 'Force merge after review', { exact: true } ).waitFor( {
			state: 'visible',
			timeout: 10000,
		} );
		await h.shot(
			page,
			'25-classic-conflict-force.png',
			page.locator( '#submitdiv .wbfp-classic-action' )
		);
		h.wp( [ 'option', 'delete', 'wbfp_tutorial_force_classic' ] );

		const legacyOriginal = h.createPost( { title: 'Tutorial legacy original' } );
		const legacyBranch = h.createBranch( legacyOriginal );
		h.deleteMeta( legacyBranch, '_wbfp_base_snapshot' );
		h.deleteMeta( legacyBranch, '_wbfp_base_snapshot_hash' );
		await h.gotoEditor( page, legacyBranch );
		await h.openReview( page );
		await page.locator( '.components-notice' ).getByText(
			'This branch predates three-way snapshots, so detailed change comparison is unavailable. Force merge only after manually reviewing the original.',
			{ exact: true }
		).waitFor( { state: 'visible', timeout: 10000 } );
		await h.shot(
			page,
			'26-legacy-unknown-state.png',
			page.getByText( 'Legacy branch', { exact: true } )
		);

		const missingOriginal = h.createPost( { title: 'Tutorial missing original' } );
		const missingBranch = h.createBranch( missingOriginal );
		h.wp( [ 'post', 'delete', String( missingOriginal ), '--force' ] );
		await h.gotoEditor( page, missingBranch );
		await page.locator( '.components-notice' ).getByText(
			'The original post is unavailable. This branch cannot be merged.',
			{ exact: true }
		).waitFor( {
			state: 'visible',
			timeout: 10000,
		} );
		await h.shot(
			page,
			'27-missing-original-state.png',
			page.getByText( 'Original unavailable', { exact: true } )
		);

		const privateId = h.createPost( {
			status: 'private',
			title: 'Tutorial private content',
		} );
		await h.gotoEditor( page, privateId );
		await page.getByRole( 'button', { name: 'Create branch', exact: true } ).waitFor( {
			state: 'visible',
			timeout: 10000,
		} );
		await h.shot(
			page,
			'28-private-post-create.png',
			page.getByText( 'Post Branch', { exact: true } )
		);

		const scheduledId = h.createPost( {
			status: 'future',
			title: 'Tutorial scheduled content',
			date: '2027-12-31 12:00:00',
		} );
		await h.gotoEditor( page, scheduledId );
		await page.getByRole( 'button', { name: 'Create branch', exact: true } ).waitFor( {
			state: 'visible',
			timeout: 10000,
		} );
		await h.shot(
			page,
			'29-scheduled-post-create.png',
			page.getByText( 'Post Branch', { exact: true } )
		);

		const draftId = h.createPost( {
			status: 'draft',
			title: 'Tutorial unsupported draft original',
		} );
		await h.gotoEditor( page, draftId );
		if ( await h.visible( page.getByRole( 'button', { name: 'Create branch', exact: true } ) ) ) {
			throw new Error( 'Draft fixture unexpectedly exposes Create branch.' );
		}
		await h.shot(
			page,
			'30-unsupported-draft-no-create.png',
			page.getByText( 'Post Branch', { exact: true } )
		);

		const mergeOriginal = h.createPost( {
			title: 'Tutorial successful merge original',
			content: 'Original before successful merge.',
		} );
		const mergeBranch = h.createBranch( mergeOriginal );
		h.updatePost( mergeBranch, { post_content: 'Merged tutorial branch content.' } );
		h.mergeBranch( mergeBranch, false );
		await h.gotoEditor( page, mergeOriginal );
		await page.waitForFunction(
			function () {
				const store = window.wp.data.select( 'core/editor' );
				return store.getEditedPostContent().indexOf( 'Merged tutorial branch content.' ) !== -1;
			},
			null,
			{ timeout: 10000 }
		);
		await h.shot(
			page,
			'31-merge-success-original.png',
			page.getByText( 'Post Branch', { exact: true } )
		);

		await page.goto(
			h.baseUrl + '/wp-admin/edit.php?post_status=trash&post_type=post',
			{ waitUntil: 'domcontentloaded' }
		);
		const mergedRow = page.locator( '#post-' + mergeBranch );
		await mergedRow.waitFor( { state: 'visible', timeout: 10000 } );
		await h.shot( page, '32-merged-branch-trash.png', mergedRow );

		const discardedOriginal = h.createPost( { title: 'Tutorial discard original' } );
		const discardedBranch = h.createBranch( discardedOriginal );
		h.discardBranch( discardedBranch );
		await page.goto(
			h.baseUrl + '/wp-admin/edit.php?post_status=trash&post_type=post',
			{ waitUntil: 'domcontentloaded' }
		);
		const discardedRow = page.locator( '#post-' + discardedBranch );
		await discardedRow.waitFor( { state: 'visible', timeout: 10000 } );
		await h.shot( page, '33-discarded-branch-trash.png', discardedRow );

		const cptId = h.createPost( {
			type: 'wbfp_demo',
			title: 'Tutorial custom post type',
			content: '<!-- wp:paragraph --><p>Custom post type tutorial content.</p><!-- /wp:paragraph -->',
		} );
		await h.gotoEditor( page, cptId );
		await page.getByRole( 'button', { name: 'Create branch', exact: true } ).waitFor( {
			state: 'visible',
			timeout: 10000,
		} );
		await h.shot(
			page,
			'34-custom-post-type.png',
			page.getByText( 'Post Branch', { exact: true } )
		);

		const dataOriginal = h.createPost( { title: 'Tutorial synchronized data original' } );
		const dataBranch = h.createBranch( dataOriginal );
		const categoryId = Number(
			h.wp( [
				'term',
				'create',
				'category',
				'Tutorial category',
				'--porcelain',
			] )
		);
		h.wp( [
			'post',
			'term',
			'set',
			String( dataBranch ),
			'category',
			String( categoryId ),
			'--by=id',
		] );
		h.wp( [
			'post',
			'meta',
			'update',
			String( dataBranch ),
			'tutorial_public_meta',
			'branch metadata value',
		] );
		const png = '/tmp/wbfp-tutorial-featured.png';
		fs.writeFileSync(
			png,
			Buffer.from(
				'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZJ5sAAAAASUVORK5CYII=',
				'base64'
			)
		);
		const mediaId = Number( h.wp( [ 'media', 'import', png, '--porcelain' ] ) );
		h.wp( [
			'post',
			'meta',
			'update',
			String( dataBranch ),
			'_thumbnail_id',
			String( mediaId ),
		] );
		await h.gotoEditor( page, dataBranch );
		await h.openReview( page );
		await page.getByText( 'Branch changes', { exact: true } ).waitFor( {
			state: 'visible',
			timeout: 10000,
		} );
		for ( const label of [
			'Taxonomy: category',
			'Metadata: tutorial_public_meta',
			'Metadata: _thumbnail_id',
		] ) {
			await page.getByText( label, { exact: true } ).waitFor( {
				state: 'visible',
				timeout: 10000,
			} );
		}
		await h.shot(
			page,
			'35-taxonomy-meta-featured-image.png',
			page.getByText( 'Branch changes', { exact: true } )
		);

		console.log( 'Tutorial compatibility and edge-state extras complete.' );
	} finally {
		await session.context.close();
		await session.browser.close();
	}
})().catch( function ( error ) {
	console.error( error );
	process.exit( 1 );
} );
