# WP Branches For Post 2.3.0

**Safer Gutenberg conflict resolution, an independently reviewed decision
workflow, and real WordPress 7.1.3 compatibility coverage.**

This release builds on 2.2.0's original-preserving post branches, Gutenberg
block-aware three-way merge, approval queue and secure scheduled merges.

## New in 2.3.0

- **Per-field conflict choices:** supported title, excerpt, body, menu order
  and comment/ping status conflicts can be resolved explicitly to the current
  **Original** or **Branch** version. No choice is preselected and all
  conflicting supported fields require an explicit decision.
- **Reviewer-approved decisions:** sites enforcing independent review can
  submit a choice map for approval. A reviewer with independent editorial
  permissions inspects the proposed values and approves or rejects the
  proposal. Both content and selected decisions are included in the approval
  contract; omissions or substitutions are blocked.
- **Clearer Gutenberg workflow:** request review, approve or reject,
  schedule/cancel and inspect conflicts inside the existing Post Branch panel.
  The WordPress Tools screen also displays proposed conflict decisions.
- **Responsive and keyboard use:** native labeled controls, mobile workflow
  layout checks, focusable disclosure toggles and keyboard activation tests.
- **Release validation:** real integration testing on WordPress **7.1.3**
  with **PHP 8.2, 8.3 and 8.4**, WordPress 6.6 baseline tests, WordPress
  Plugin Check, actual Gutenberg/Chromium E2E, third-party co-installation
  and 35-case screenshot tutorial contract.

## Safety

The plugin does not change the published post ID, slug, URL, publication
identity or independently changed original fields. Merge operations remain
subject to capabilities, state freshness, selected-choice validation and
original-snapshot rollback. Unsupported and arbitrary metadata conflicts
must still be resolved manually.

Independent approval is optional and remains disabled by default on sites
that have not enabled it. Scheduled merges remain non-forced and depend on
WP-Cron timing.

## Localization

Previously approved Japanese, Simplified Chinese and Traditional Chinese
translation catalogs were **not modified or regenerated**. Some new 2.3
workflow labels fall back to English pending separately approved translation
updates.

## Upgrade

Install 2.3.0 over your existing version through the normal WordPress Plugins
screen. Existing 2.1/2.2 branches and existing article URLs remain intact.
See [the editorial workflow documentation](https://github.com/hsxk/WP-Branches-For-Post/blob/master/docs/GUTENBERG_WORKFLOW_23.md).
