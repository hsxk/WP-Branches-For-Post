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

## Important boundary

This file records a local executable integration matrix that runs the current plugin service/REST/admin logic against a deterministic WordPress API harness. It is not being represented as a full local WordPress browser E2E run.

The current sandbox does not contain Docker, MySQL/MariaDB, or a WordPress distribution, and outbound DNS is disabled, so a fresh local WordPress/Playground package cannot be installed here. No GitHub Actions were triggered to work around that limitation.

A prior real WordPress 7.1.1 + PHP 8.2 browser run already verified plugin activation, Gutenberg rendering, branch creation, conflict state, post-list integration, and real screenshots, but that run was not used to execute this local matrix.
