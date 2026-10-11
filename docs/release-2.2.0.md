# WP Branches For Post 2.2.0

Version 2.2.0 expands safe WordPress post branching into an optional team
editorial workflow without changing the published post ID, URL, slug or
publication identity.

### What's new

- **Conservative Gutenberg block-aware 3-way merge:** preserves independent
  top-level block changes and original markup/whitespace when they can be
  combined without ambiguity. Freeform markup, repeated or moved block
  identities, synced patterns, and overlapping edits still require review.
- **Independent editorial approval:** authors request review; another
  authorized editor approves or requests changes. Stale content or revoked
  reviewer capabilities invalidate approval. Site administrators can opt in
  to required approval for every merge, including direct or forced merges.
- **Safe scheduled merges:** future site-local scheduling backed by WP-Cron,
  per-event IDs, due-time checks, actor permission verification, approval
  freshness and non-forced conflict handling. Cancellations prevent old
  callbacks from publishing.
- **Workflow administration and REST endpoints:** pending review queue,
  branch-list and editor toolbar shortcuts, actionable errors and private
  reviewer metadata.
- **Expanded interoperability:** WordPress integration coverage with Yoast
  Duplicate Post, Yoast SEO, ACF and Elementor, plus metadata synchronization
  checks for independent edits.

### Validation

2.2.0 ships through a release gate that runs the PHP 8.2–8.4 quality suite,
WordPress 6.6/7.1.1 integration matrix, real Gutenberg browser regression and
35-case screenshot/tutorial contract, Plugin Check, deterministic editor
build, release ZIP auditing, and third-party plugin coexistence tests.

### Compatibility and behavior

- Requires WordPress 6.6+ and PHP 8.2+.
- Approval enforcement remains **off by default** to preserve existing sites.
- WP-Cron is traffic-dependent; production sites should configure reliable
  cron execution for time-sensitive schedules.
- No existing translation catalogs were modified or regenerated. Previously
  translated UI remains intact; new workflow strings fall back to English
  until separately approved.
- Ordinary version 2.1.x branches and pre-existing metadata remain supported.

For details, see
[editorial workflow documentation](https://github.com/hsxk/WP-Branches-For-Post/blob/master/docs/EDITORIAL_WORKFLOW_2026-10.md).
