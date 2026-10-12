/**
 * Real WordPress 7.1.3 Gutenberg locale regression for the 2.3 workflow.
 * Loads shipped .mo and editor JSON; never relies on a mock i18n dictionary.
 */
const fs = require( 'node:fs' );
const { execFileSync } = require( 'node:child_process' );
const { chromium } = require( 'playwright-core' );

const baseUrl = process.env.WBFP_BASE_URL || 'http://127.0.0.1:8080';
const wpPath = process.env.WBFP_WP_PATH || '/tmp/wordpress';
const username = process.env.WBFP_BROWSER_USER || 'admin';
const password = process.env.WBFP_BROWSER_PASSWORD || 'test-password';

const localeCases = [
	{
		locale: 'ja',
		panel: '投稿ブランチ',
		workflow: 'ブランチワークフロー',
		request: 'レビューを依頼',
		merge: '選択した値でマージ',
		review: 'レビュー／予約',
	},
	{
		locale: 'zh_CN',
		panel: '文章分支',
		workflow: '分支工作流',
		request: '提交审核',
		merge: '按所选值合并',
		review: '审核／定时',
	},
	{
		locale: 'zh_TW',
		panel: '文章分支',
		workflow: '分支工作流程',
		request: '提交審核',
		merge: '依所選值合併',
		review: '審核／排程',
	},
];

const browserCandidates = [
	process.env.CHROME_BIN,
	'/usr/bin/google-chrome',
	'/usr/bin/google-chrome-stable',
	'/usr/bin/chromium',
	'/usr/bin/chromium-browser',
].filter( Boolean );
const executablePath = browserCandidates.find( ( p ) => fs.existsSync( p ) );
if ( ! executablePath ) {
	throw new Error( 'No system Chromium/Chrome executable found.' );
}

function wp( args ) {
	return execFileSync( 'wp', [ ...args, '--path=' + wpPath ], {
		encoding: 'utf8',
		timeout: 45000,
	} ).trim();
}

async function checkLocale( browser, selected ) {
	wp( [ 'user', 'meta', 'update', username, 'locale', selected.locale ] );
	const fixture = [
		'$id=wp_insert_post(array(',
		' "post_type"=>"post",',
		' "post_status"=>"publish",',
		' "post_title"=>"Localized Gutenberg branch fixture",',
		' "post_content"=>"Locale check original content"));',
		'$branch=(new \\WP_Branches_For_Post\\Branch_Service())->create($id);',
		'if(is_wp_error($branch)){fwrite(STDERR,$branch->get_error_message());exit(1);}',
		'echo (int)$branch;',
	].join( '' );
	const branchId = Number( wp( [ 'eval', fixture, '--user=' + username ] ) );
	if ( ! branchId ) {
		throw new Error( selected.locale + ': branch fixture creation failed' );
	}

	const context = await browser.newContext( {
		viewport: { width: 390, height: 844 },
	} );
	const page = await context.newPage();
	try {
		await page.goto( baseUrl + '/wp-login.php', {
			waitUntil: 'domcontentloaded',
		} );
		await page.locator( '#user_login' ).fill( username );
		await page.locator( '#user_pass' ).fill( password );
		await Promise.all( [
			page.waitForURL( /\\/wp-admin\\//, { timeout: 15000 } ),
			page.locator( '#wp-submit' ).click(),
		] );
		await page.goto(
			baseUrl + '/wp-admin/post.php?post=' + branchId + '&action=edit',
			{ waitUntil: 'domcontentloaded' }
		);
		await page.waitForFunction(
			( post ) => {
				const editor = window.wp?.data?.select?.( 'core/editor' );
				return editor?.getCurrentPostId?.() === post;
			},
			branchId,
			{ timeout: 20000 }
		);
		await page.waitForFunction(
			( expected ) =>
				window.wp?.i18n?.__( 'Request review', 'wp-branches-for-post' ) ===
				expected,
			selected.request,
			{ timeout: 20000 }
		);
		const values = await page.evaluate( () => {
			const translate = window.wp.i18n.__;
			const domain = 'wp-branches-for-post';
			return {
				workflow: translate( 'Branch Workflow', domain ),
				request: translate( 'Request review', domain ),
				merge: translate( 'Merge selected values', domain ),
				panel: translate( 'Post Branch', domain ),
				review: translate( 'Review / schedule', domain ),
			};
		} );
		for ( const key of [ 'workflow', 'request', 'merge', 'panel', 'review' ] ) {
			if ( values[ key ] !== selected[ key ] ) {
				throw new Error(
					selected.locale + ': ' + key + ' expected ' +
						selected[ key ] + ', got ' + values[ key ]
				);
			}
		}
		let panel = page.locator( '.wbfp-workflow' );
		if ( ! ( await panel.isVisible().catch( () => false ) ) ) {
			const settings = page.locator(
				'button[aria-label*="Settings"],button[aria-label*="settings"],button[aria-label*="Setting"],button[aria-label*="setting"]'
			);
			if ( await settings.count() ) {
				await settings.last().click();
			}
		}
		const mainPanel = page.locator( '.components-panel__body' ).filter( {
			hasText: selected.panel,
		} ).first();
		if ( await mainPanel.count() ) {
			const toggle = mainPanel.locator( 'button.components-panel__body-toggle' ).first();
			if (
				( await toggle.count() ) &&
				( await toggle.getAttribute( 'aria-expanded' ) ) !== 'true'
			) {
				await toggle.click();
			}
		}
		panel = page.locator( '.wbfp-workflow' );
		await panel.waitFor( { state: 'visible', timeout: 20000 } );
		const disclosure = panel.locator(
			'button[aria-controls="wbfp-workflow-details"]'
		);
		await disclosure.waitFor( { state: 'visible' } );
		if ( ! ( await disclosure.innerText() ).includes( selected.review ) ) {
			throw new Error( selected.locale + ': workflow disclosure not translated' );
		}
		await disclosure.focus();
		await disclosure.press( 'Enter' );
		if ( ( await disclosure.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
			throw new Error( selected.locale + ': keyboard disclosure did not expand' );
		}
		const request = panel.getByRole( 'button', {
			name: selected.request,
			exact: true,
		} );
		await request.waitFor( { state: 'visible', timeout: 12000 } );
		const box = await panel.boundingBox();
		if ( ! box || box.x < -1 || box.width > 390 ) {
			throw new Error( selected.locale + ': workflow overflows mobile viewport' );
		}
		const filename = '/tmp/wbfp-i18n-' + selected.locale + '.png';
		await page.screenshot( { path: filename } );
		console.log( 'LOCALE UI PASS ' + selected.locale + ': ' + filename );
	} finally {
		await context.close();
	}
}

(async () => {
	const browser = await chromium.launch( {
		headless: true,
		executablePath,
		args: [ '--no-sandbox', '--disable-dev-shm-usage' ],
	} );
	try {
		for ( const selected of localeCases ) {
			await checkLocale( browser, selected );
		}
	} finally {
		wp( [ 'user', 'meta', 'delete', username, 'locale' ] );
		await browser.close();
	}
} )().catch( ( error ) => {
	console.error( error );
	process.exitCode = 1;
} );
