# WP Branches For Post 2.1 — Tutorial Asset Library

This directory is the long-form documentation source for version 2.1. The WordPress.org plugin page intentionally uses a smaller set of twelve screenshots; this library keeps the broader visual record so future tutorials can explain every visible state without rebuilding the test environment.

## How to use this library

- `.wordpress-org/screenshot-1.png` through `screenshot-12.png` are the curated WordPress.org plugin-page set.
- `docs/tutorial-2.1/screenshots/` stores the larger tutorial set with semantic filenames.
- `tests/capture-docs-2.1.js` produces the curated set.
- `tests/capture-tutorial-2.1.js` produces the extended tutorial set.
- `TESTING.md` explains the reproducible WordPress/MySQL/Chromium environment.
- Backend safety guarantees that are not visible in screenshots are documented in `SECURITY.md` and verified in `tests/wp-real-2.1.php`.

## Visual coverage matrix

| File | What it documents | Suggested tutorial section |
| --- | --- | --- |
| `01-original-create-branch.png` | Original Gutenberg panel and Create branch | Getting started |
| `02-admin-bar-create-branch.png` | Front-end Admin Bar shortcut | Entry points |
| `03-post-list-create-branch.png` | Post-list Create branch row action | Entry points |
| `04-branch-editor-overview.png` | Working branch panel | Branch basics |
| `05-branch-relationship-notice.png` | Admin notice linking branch to original | Branch identity |
| `06-preview-links.png` | Preview branch / View original links | Comparing versions |
| `07-review-overview.png` | Merge review summary | Review |
| `08-three-way-comparison.png` | Base / Original / Branch comparison | Review |
| `09-branch-only-change.png` | Branch-only change classification | Three-way semantics |
| `10-original-only-change.png` | Original-only change preserved | Three-way semantics |
| `11-identity-change-preserved.png` | Slug/status/author/parent identity notice | Identity safety |
| `12-update-available.png` | Update branch from original | Rebase |
| `13-after-rebase.png` | Fresh review after rebase | Rebase |
| `14-normal-merge-ready.png` | Save & merge into original | Normal merge |
| `15-conflict-summary.png` | True overlapping conflict | Conflicts |
| `16-conflict-three-way-detail.png` | Conflicting path and three values | Conflicts |
| `17-force-merge-confirmation.png` | Destructive force-merge confirmation | Force merge |
| `18-stale-review-blocked.png` | State changed after review, merge stopped | Concurrent editing |
| `19-discard-confirmation.png` | Move branch to Trash confirmation | Discard |
| `20-existing-branches.png` | Active branches listed on original | Managing multiple branches |
| `21-post-list-branch-actions.png` | Branch state and actions in list table | Admin integration |
| `22-synced-pattern-warning.png` | Global synced-pattern warning | Synced patterns |
| `23-classic-original-create.png` | Classic Editor Create branch control | Classic Editor |
| `24-classic-safe-merge.png` | Classic Editor normal merge | Classic Editor |
| `25-classic-conflict-force.png` | Classic Editor force merge after review | Classic Editor |
| `26-legacy-unknown-state.png` | Older branch without 2.1 full baseline | Legacy compatibility |
| `27-missing-original-state.png` | Branch whose original is no longer resolvable | Failure states |
| `28-private-post-create.png` | Branching private content | Supported statuses |
| `29-scheduled-post-create.png` | Branching scheduled content | Supported statuses |
| `30-unsupported-draft-no-create.png` | Draft original does not offer branch creation | Eligibility |
| `31-merge-success-original.png` | Original after successful merge | Completion |
| `32-merged-branch-trash.png` | Merged branch in Trash | Recovery |
| `33-discarded-branch-trash.png` | Discarded branch in Trash | Recovery |
| `34-custom-post-type.png` | Supported custom post type workflow | Custom post types |
| `35-taxonomy-meta-featured-image.png` | Example content carrying taxonomy/meta/featured image | Synchronized data |

## Non-visual guarantees to explain in tutorials

These are important, but a screenshot cannot prove them. Link the tutorial text to the real integration tests instead:

- original post ID/GUID/publication identity is preserved;
- slug, author, publication dates and hierarchical parent are not overwritten by branch values;
- metadata removals and multi-value metadata are synchronized correctly;
- taxonomy clears and custom taxonomies are synchronized;
- featured-image metadata follows the branch without re-parenting media;
- stale original/branch writes are rejected before merge/rebase;
- failed post/meta/taxonomy writes roll back;
- failed baseline writes roll back;
- failed branch Trash cleanup rolls the original back;
- force merge never bypasses capabilities or relationship validation;
- legacy branches fail conservatively when exact three-way state is unavailable.

The definitive executable evidence for those guarantees lives in `tests/wp-real-2.1.php`.

## WordPress.org curated twelve

The plugin page should stay concise enough to scan. Its twelve screenshots are selected from the same real workflow:

1. Create branch
2. Working branch
3. Three-way review
4. Update available
5. After rebase
6. True conflict
7. Force confirmation
8. Stale-review protection
9. Discard confirmation
10. Existing branches
11. Post-list integration
12. Synced-pattern warning

The extended GitHub library is intentionally broader than the WordPress.org gallery.
