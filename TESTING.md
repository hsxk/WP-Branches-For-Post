# WP Branches For Post — Reproducible 2.1 Validation Environment

This document records the full validation environment used for WP Branches For Post 2.1 so the same checks can be repeated later without rebuilding the process from memory.

## Test laboratory

The disposable CI laboratory is:

- Repository: `time2analyze/wp-branch`
- Branch: `main`
- Source repository: `hsxk/WP-Branches-For-Post`
- 2.1 development source: `feat/2.1-merge-review-safety`
- Lab workflow: `.github/workflows/lab-full-validation.yml`

The lab repository uses the second GitHub account so validation does not consume the Actions quota of the formal plugin repository.

The source repository's own `.github/workflows/` directory is intentionally excluded when source files are synchronized into the lab. The lab keeps its own validation workflow instead. This also avoids GitHub's restriction that prevents a normal Actions token from creating or replacing workflow files without the special workflows permission.

## What the validation covers

The full lab is deliberately broader than the release workflow in the formal repository.

### Static PHP validation

The plugin is checked on:

- PHP 8.2
- PHP 8.3
- PHP 8.4

For each supported runtime the workflow runs:

1. PHP syntax checks for every PHP source file.
2. Composer development dependency installation.
3. WordPress Coding Standards through PHPCS.

### Version consistency

The release candidate must report the same version in all public package metadata:

- Plugin header in `post-branch.php`
- `WBFP_VERSION`
- `README.txt` Stable tag
- `package.json`

For the 2.1 release candidate these must all be `2.1.0`.

### JavaScript and CSS

The workflow uses Node.js 22 and runs:

- `npm install --no-audit --no-fund`
- `npm run lint:js`
- `npm run lint:css`
- `npm run build`
- A Git diff check against `build/`

The last check guarantees that the committed Block Editor bundle was actually rebuilt from the current `src/` source.

### WordPress Plugin Check

A release-style plugin tree is assembled without development-only files and passed to the official WordPress Plugin Check action for:

- General checks
- Security
- Performance
- Accessibility
- WordPress.org plugin repository requirements

### Real WordPress integration test

The CI environment creates a real database-backed WordPress installation rather than only mocking WordPress APIs.

Environment:

- Ubuntu GitHub-hosted runner
- MySQL 8.0 service
- PHP 8.2 with mysqli
- WordPress 7.1.1
- WP-CLI
- The checked-out plugin symlinked into `wp-content/plugins/wp-branches-for-post`

The integration suite is:

`tests/wp-real-2.1.php`

Run command inside an equivalent WordPress installation:

```bash
wp eval-file wp-content/plugins/wp-branches-for-post/tests/wp-real-2.1.php
```

The suite exercises real WordPress posts, metadata, taxonomies, users, capabilities, REST objects and the plugin's service classes. Test data is deleted at the end.

Important covered scenarios include branch creation, forced-draft protection, three-way merge, non-conflicting original changes, rebase, real conflicts, force-merge permission checks, hierarchical URL identity preservation, rollback after injected write failures, synced-pattern detection, REST review payloads, legacy compatibility and discard behavior.

## Real Gutenberg browser test

The same CI job starts the installed WordPress site on:

`http://127.0.0.1:8080`

A real published fixture post is created with WP-CLI. Chromium is then driven with Playwright through:

`tests/browser-2.1.js`

The browser test verifies the real admin/editor experience:

1. Log into WordPress.
2. Open the saved original in Gutenberg.
3. Find the Post Branch panel.
4. Verify Create branch is available.
5. Create an actual branch from the UI.
6. Verify branch/original preview links.
7. Edit branch content through the Gutenberg data store.
8. Open Review changes.
9. Confirm the three-way review is rendered.
10. Perform Save & merge into original.
11. Return to the original.
12. Verify the merged branch content is present.

This is a real browser + WordPress + MySQL test, not a DOM fixture or mocked REST test.

## Screenshots

Documentation screenshots are captured from the same real WordPress/Gutenberg environment used by browser E2E. They belong in `.wordpress-org/` as:

- `screenshot-1.png` — Create branch on the original.
- `screenshot-2.png` — Working branch controls.
- `screenshot-3.png` — Three-way merge review.
- `screenshot-4.png` — Update branch from original / non-conflicting newer work.
- `screenshot-5.png` — True conflict protection.
- `screenshot-6.png` — Force merge confirmation.
- `screenshot-7.png` — Existing branches and states on the original.
- `screenshot-8.png` — Post-list integration.

The screenshot descriptions in `README.txt` must match the numbered image files because WordPress.org renders them together on the plugin detail page.

Do not reuse screenshots from an older UI after button labels or workflow behavior change.

## Re-running validation

Normally any commit to `time2analyze/wp-branch/main` starts the lab workflow.

To intentionally import a fresh snapshot from the formal 2.1 development branch, run the lab workflow with the `resync` input enabled. The synchronization marker is stored in `.lab/source-synced` so later test-lab fixes are not overwritten on every CI run.

After resync, confirm the source reference documented in `.lab/SOURCE.md`.

## Debugging failures

Use the failed job, not only the overall workflow status.

Recommended order:

1. Fix PHP syntax/PHPCS failures first.
2. Fix version consistency before release packaging work.
3. Fix JS/CSS lint and rebuild mismatches.
4. Review Plugin Check findings.
5. Read the output of `tests/wp-real-2.1.php` for service/data behavior failures.
6. Read the browser E2E output for editor/UI failures.
7. If the HTTP/browser portion fails, inspect the uploaded WordPress server log artifact.

A green static check does not replace a green real WordPress test, and a green service integration test does not replace the browser test.

## Moving validated work back to the formal repository

Only after the lab is green:

1. Reproduce the validated source/docs/assets changes on a branch in `hsxk/WP-Branches-For-Post`.
2. Keep lab-only CI mechanics out of the production plugin unless they are intentionally being adopted.
3. Open a pull request to `master`.
4. Review the PR diff for generated build files, screenshots, version metadata and documentation.
5. Merge the pull request.
6. Do not create a release tag merely to test the code. Tagging belongs to the later release decision.

## Why this environment exists

The goal is to make "test 2.1 completely" reproducible:

- No dependence on remembered local setup.
- No need to consume the formal repository's Actions quota.
- Real WordPress and database behavior is exercised.
- Real Gutenberg browser behavior is exercised.
- WordPress.org packaging requirements are checked.
- Screenshots come from the tested UI rather than a hand-built mock.
