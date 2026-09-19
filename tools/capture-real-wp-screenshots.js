const { chromium } = require('playwright-core');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const baseURL = process.env.WP_BASE_URL || 'http://localhost:8888';
const postId = Number(process.env.WBFP_ORIGINAL_POST_ID || 0);
const outputDir = process.cwd();

if (!postId) {
	throw new Error('WBFP_ORIGINAL_POST_ID is required.');
}

function wpCli(args) {
	return execFileSync(
		'npx',
		['wp-env', 'run', 'cli', 'wp', ...args],
		{ encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }
	);
}

async function closeEditorWelcome(page) {
	const selectors = [
		'button[aria-label="Close"]',
		'button[aria-label="Close dialog"]',
		'button[aria-label="关闭"]',
		'button[aria-label="閉じる"]',
	];
	for (const selector of selectors) {
		const button = page.locator(selector).last();
		if (await button.count()) {
			try {
				await button.click({ timeout: 1200 });
				break;
			} catch (_) {}
		}
	}
}

async function ensureSettingsSidebar(page) {
	const panelText = page.getByText('Post Branch', { exact: true });
	if (await panelText.count()) {
		return;
	}

	const settingsButtons = [
		page.locator('button[aria-label="Settings"]'),
		page.locator('button[aria-label="Settings sidebar"]'),
		page.locator('button[aria-label="Preferences"]'),
	];
	for (const button of settingsButtons) {
		if (await button.count()) {
			try {
				await button.first().click({ timeout: 1500 });
				await page.waitForTimeout(500);
				if (await panelText.count()) {
					return;
				}
			} catch (_) {}
		}
	}
}

async function ensureBranchPanelExpanded(page) {
	const title = page.getByText('Post Branch', { exact: true }).last();
	await title.waitFor({ state: 'visible', timeout: 20000 });

	const toggle = title.locator('xpath=ancestor::button[1]');
	if (await toggle.count()) {
		const expanded = await toggle.getAttribute('aria-expanded');
		if (expanded === 'false') {
			await toggle.click();
			await page.waitForTimeout(400);
		}
	}
}

async function waitForEditor(page) {
	await page.waitForLoadState('domcontentloaded');
	await page.waitForTimeout(1500);
	await closeEditorWelcome(page);
	await ensureSettingsSidebar(page);
	await ensureBranchPanelExpanded(page);
	await page.waitForTimeout(500);
}

async function screenshot(page, name) {
	await page.screenshot({
		path: path.join(outputDir, name),
		fullPage: false,
		animations: 'disabled',
	});
}

let page;

(async () => {
	const executablePath =
		fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' :
		fs.existsSync('/usr/bin/google-chrome-stable') ? '/usr/bin/google-chrome-stable' :
		'/usr/bin/chromium';

	const browser = await chromium.launch({
		headless: true,
		executablePath,
		args: ['--no-sandbox', '--disable-dev-shm-usage'],
	});

	const context = await browser.newContext({
		viewport: { width: 1440, height: 900 },
		deviceScaleFactor: 1,
		locale: 'en-US',
	});

	page = await context.newPage();

	await page.goto(baseURL + '/wp-login.php', { waitUntil: 'domcontentloaded' });
	await page.locator('#user_login').fill('admin');
	await page.locator('#user_pass').fill('password');
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
		page.locator('#wp-submit').click(),
	]);

	// 1. Real Block Editor view on the original post.
	await page.goto(baseURL + '/wp-admin/post.php?post=' + postId + '&action=edit', { waitUntil: 'domcontentloaded' });
	await waitForEditor(page);

	// Wait for the authenticated REST status call to populate the panel.
	const createButton = page.getByText('Create branch', { exact: true }).last();
	await createButton.waitFor({ state: 'visible', timeout: 15000 });
	await screenshot(page, 'screenshot-1.png');

	// Create the branch through the real plugin UI.
	await createButton.click();
	await page.waitForURL(/post\.php\?post=\d+&action=edit/, { timeout: 20000 });
	await waitForEditor(page);
	await page.getByText('Merge into original', { exact: true }).last().waitFor({ state: 'visible', timeout: 15000 });

	const branchMatch = page.url().match(/[?&]post=(\d+)/);
	if (!branchMatch) {
		throw new Error('Could not determine the created branch ID.');
	}
	const branchId = Number(branchMatch[1]);

	// 2. Real branch editor with merge/discard controls.
	await screenshot(page, 'screenshot-2.png');

	// Modify the original outside the branch to create a genuine conflict.
	wpCli([
		'post', 'update', String(postId),
		'--post_title=Quarterly product update — revised original',
		'--post_excerpt=The original changed after the working branch was created.',
	]);

	await page.reload({ waitUntil: 'domcontentloaded' });
	await waitForEditor(page);
	await page.getByText('Original changed', { exact: true }).first().waitFor({ state: 'visible', timeout: 10000 });

	// 3. Real conflict protection state.
	await screenshot(page, 'screenshot-3.png');

	// 4. Real WordPress Posts list with the original and branch.
	await page.goto(baseURL + '/wp-admin/edit.php', { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('#the-list', { timeout: 15000 });
	const branchRow = page.locator('#post-' + branchId);
	await branchRow.waitFor({ state: 'visible', timeout: 10000 });
	await branchRow.hover();
	await page.waitForTimeout(400);
	await screenshot(page, 'screenshot-4.png');

	await browser.close();
	console.log(JSON.stringify({ postId, branchId }));
})().catch(async (error) => {
	console.error(error);
	try {
		const debugPath = path.join(outputDir, 'debug-browser.png');
		await page.screenshot({ path: debugPath, fullPage: true, animations: 'disabled' });
		fs.writeFileSync(path.join(outputDir, 'debug-body.txt'), await page.locator('body').innerText());
		console.error('Saved debug-browser.png and debug-body.txt');
	} catch (debugError) {
		console.error('Could not save browser debug output:', debugError);
	}
	process.exit(1);
});
