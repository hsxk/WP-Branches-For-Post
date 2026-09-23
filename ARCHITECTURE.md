# Architecture

WP Branches For Post 2.1 separates WordPress integration from branch, synchronization, review, and merge logic. Privileged mutations are concentrated in service classes so the safety boundaries can be audited and tested independently from the editor UI.

## Entry point

`post-branch.php`

Defines plugin metadata and constants, loads service classes, and boots the plugin singleton.

## Composition root

`includes/class-plugin.php`

Creates the branch, merge, REST, and admin services and registers WordPress hooks.

## Branch lifecycle

`includes/class-branch-service.php`

Owns:

- branch/original relationship metadata
- capability-aware branch eligibility
- branch creation
- branch non-public enforcement
- 2.1 baseline snapshots
- three-way analysis state
- Update branch from original (rebase)
- active branch queries
- legacy relationship/baseline compatibility

### Create flow

1. Verify edit/create capabilities and branchable post state.
2. Capture a full baseline snapshot of the original.
3. Clone the original through WordPress post APIs.
4. Re-apply hard invariants after extension filters: same post type, draft status, empty slug.
5. Copy syncable metadata and taxonomy assignments.
6. Re-snapshot the original and abort if it changed during creation.
7. Store relationship metadata.
8. Store the complete baseline snapshot and deterministic baseline hash.
9. Read the stored baseline back and verify it before returning success.

If synchronization or baseline storage fails, the inconsistent new branch is removed.

## Synchronization model

`includes/class-sync-service.php`

Defines the exact state that is mergeable and the identity state that is review-only.

### Mergeable state

- allowlisted core editorial fields
- syncable post metadata
- taxonomy assignments

### Preserved identity

- post type
- publication status
- slug
- author
- hierarchical parent
- resource identity such as post ID/GUID/publication dates, which are never copied from the branch

The service provides deterministic hashes for mergeable state and for the complete review state.

## Three-way analysis

The 2.1 comparison uses:

- Base — original state captured at branch creation or last successful refresh
- Original — current original
- Branch — current working branch

`Sync_Service::three_way_analysis()` classifies:

- original changes
- branch changes
- conflicts where both sides changed the same path differently
- informational identity changes
- whether a safe rebase is available

`Sync_Service::merged_snapshot()` starts from the current original and overlays branch-changed paths. This preserves newer original-only work. On an explicitly forced conflict, the branch value wins only for paths changed by the branch.

## Update branch from original

`Branch_Service::rebase()`

A refresh is available only when original changes do not conflict with branch changes.

1. Analyze Base / Original / Branch.
2. Build a rebased target that keeps branch-only work and imports original-only work.
3. Snapshot the current branch for rollback.
4. Save the old baseline-control metadata for rollback.
5. Apply and verify the rebased branch state.
6. Store and verify a fresh baseline from the original.
7. If either the content write or baseline write fails, restore the previous branch and previous baseline metadata.

## Merge lifecycle

`includes/class-merge-service.php`

### Normal/force merge flow

1. Validate that the branch is active and still points to an existing original of the same post type.
2. Verify edit capabilities.
3. Analyze the current three-way state.
4. Block unresolved hard conflicts for a normal merge.
5. Run `wbfp_before_merge`.
6. Re-fetch the relationship and re-run analysis.
7. Build the three-way target.
8. Capture fresh original/branch snapshots immediately before writes.
9. Compare those snapshots with the reviewed analysis; abort if either changed.
10. Apply post fields, metadata, and taxonomy state through WordPress APIs.
11. Verify the applied state.
12. Move the branch through the WordPress Trash API.
13. If Trash fails, restore the pre-merge original and keep the branch active.
14. Record merge audit metadata and fire `wbfp_after_merge`.

A force merge bypasses conflict blocking only. It does not bypass freshness, capability, relationship, post-type, or rollback checks.

## Reviewed-state token

`includes/class-rest-controller.php`

Block Editor branch status includes a `review_token` derived from:

- branch/original relationship
- baseline state
- current original complete state
- current branch complete state

The token contains no post content. The editor sends the reviewed token back with a merge request. A mismatched token returns `wbfp_review_state_changed`, requiring a new review.

The service layer independently repeats freshness checks so the token is defense in depth rather than the sole stale-write control.

## REST API

Namespace: `wbfp/v1`

Routes cover:

- post/branch status
- branch creation
- branch refresh/rebase
- merge/force merge
- discard

Every route has a `permission_callback`. Service classes repeat authoritative checks before mutations.

## WordPress admin integration

`includes/class-admin.php`

Provides:

- Block Editor asset integration
- Classic Editor controls
- post/page list row actions
- branch state labels
- admin-bar Create Branch shortcut
- result/relationship notices

Version 2.1 state semantics are shared across surfaces:

- clean / rebase-available / informational states can use normal merge;
- true conflicts and uncertain legacy states route users back through review before force merge;
- missing originals do not expose a merge action.

Classic/admin mutation links remain nonce-protected.

## Block Editor UI

Human-readable source: `src/index.js`

Production bundle: `build/index.js`

The editor:

1. reads branch status from REST;
2. saves dirty branch edits before refreshing review;
3. presents Base / Original / Branch review values;
4. keeps a reviewed-state token;
5. refreshes status immediately before merge;
6. refuses to merge when the token changed;
7. sends the fresh reviewed token with the merge request.

Force merge and discard use explicit WordPress component confirmation dialogs.

## Screenshots and browser validation

`tests/browser-2.1.js` drives a real Chromium session against a real WordPress installation.

`tests/capture-docs-2.1.js` captures the WordPress.org screenshots from the same kind of real Gutenberg environment rather than from mocked markup.

`tests/wp-real-2.1.php` exercises service, data, authorization, conflict, rollback, compatibility, and REST behavior against real WordPress/MySQL state.

See `TESTING.md` for the reproducible lab setup and complete validation process.

## Compatibility

- Minimum supported WordPress: 6.6
- Minimum supported PHP: 8.2
- 2.0 relationship/baseline-hash compatibility is retained.
- Older branches without 2.1 full snapshots are handled conservatively and cannot claim precise three-way review.
