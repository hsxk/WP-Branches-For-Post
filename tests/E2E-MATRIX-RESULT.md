# WP Branches For Post 2.0 — Local Executable Matrix

Date: 2026-09-19
Execution environment: local sandbox, PHP 8.4.23
GitHub Actions used for this matrix: No

## Result

53 / 53 executable integration cases passed.

## Covered

- Branch creation from publish/private/future
- Unsupported draft rejection
- Edit/create capability checks
- Branch creation filter invariants (draft/same post type/empty slug)
- Creation race detection and rollback
- Branch publish guard
- Normal merge
- Multi-value meta replacement/removal
- Featured image meta (`_thumbnail_id`)
- Taxonomy synchronization and clearing
- Original identity preservation (ID-facing fields, slug, GUID, author, status, date)
- Protected meta exclusions
- Mergeable-field allowlist enforcement
- Conflict detection
- Force merge
- Force merge permission boundary
- Missing original
- Trashed branch
- Taxonomy read failures / fail-closed behavior
- Post-type mismatch
- Pre-merge hook concurrent original mutation
- Pre-merge hook relationship mutation
- Pre-merge hook branch trashing
- Branch creation audit metadata and baseline revision
- Merge audit metadata
- Deterministic nested metadata snapshot hashing
- Discard / Trash behavior
- Legacy 1.x branch normal/force behavior
- Custom post type and custom taxonomy
- CPT create capability
- Merge permission matrix
- REST route registration and permission callbacks
- REST status privacy boundary
- REST branch-list filtering
- REST create/merge/discard responses
- Post list Create/Merge actions
- Branch post-state label
- Classic Editor clean merge action
- Classic Editor conflict force-merge confirmation
- Admin-bar list-screen isolation
- Admin-bar single editor behavior
- Front-end singular admin-bar behavior
- Current + legacy branch queries

## Security verification — 2026-09-21

Date: 2026-09-21
Execution environment: real WordPress 7.1.1, PHP 8.2.12, live MySQL-backed install (not a mocked harness)
Method: `wp eval-file` running an ad hoc smoke suite directly against the active plugin's service/REST/admin classes, with real user accounts, real nonces, and real post rows.

18 / 18 checks passed.

Covered:

- Capability boundary: a contributor without `edit_post` on another author's post cannot create a branch, at both the `can_create()` check and the `Branch_Service::create()` service-layer re-check.
- Authorized branch creation and forced-draft status on the new branch.
- Branch visibility invariant: attempting to publish a branch through a normal `wp_update_post()` save is normalized back to `draft`.
- Row-action markup contains no unescaped `<script>` tag when the source post title itself contains one; WordPress core's own storage/escaping responsibility was not bypassed by this plugin.
- Conflict detection correctly reports `changed` after the original is edited post-branch, and a normal (non-forced) merge is blocked with `wbfp_merge_conflict`.
- Force merge still enforces `edit_post` capability on both branch and original for an unauthorized caller, and only succeeds for an authorized one.
- Merge preserves the original post's identity (same ID) and moves the merged branch to Trash rather than deleting it.
- REST `can_merge_branch` permission callback rejects a caller without edit capability on the original and allows the authorized owner.
- A forged/invalid nonce fails `wp_verify_nonce()` for the create-branch action; a correctly scoped nonce passes.

No vulnerabilities were found. This run exercises the same authorization/CSRF/conflict invariants documented in `SECURITY.md` against a real WordPress instance rather than a mocked harness.

## Important boundary

This file records a local executable integration matrix that runs the current plugin service/REST/admin logic against a deterministic WordPress API harness. It is not being represented as a full local WordPress browser E2E run.

The current sandbox does not contain Docker, MySQL/MariaDB, or a WordPress distribution, and outbound DNS is disabled, so a fresh local WordPress/Playground package cannot be installed here. No GitHub Actions were triggered to work around that limitation.

A prior real WordPress 7.1.1 + PHP 8.2 browser run already verified plugin activation, Gutenberg rendering, branch creation, conflict state, post-list integration, and real screenshots, but that run was not used to execute this local matrix.
