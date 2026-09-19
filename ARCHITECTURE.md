# Architecture

WP Branches For Post 2.0 is intentionally small. The plugin separates WordPress integration from branch/merge domain logic so reviewers can follow privileged mutations without tracing a monolithic plugin file.

## Entry point

`post-branch.php`

Defines plugin metadata and constants, loads the service classes, and boots the plugin singleton.

## Bootstrap

`includes/class-plugin.php`

Creates the branch, merge, REST, and admin services and registers their WordPress hooks.

## Branch lifecycle

`includes/class-branch-service.php`

Owns:

- branch/original relationship metadata
- capability-aware branch eligibility
- branch creation
- branch non-public status enforcement
- baseline snapshot conflict state
- active branch queries
- compatibility with 1.x relationship metadata

### Create flow

1. Verify that the current user can edit the original.
2. Verify that the original is a supported post/status and is not already a branch.
3. Create a deterministic baseline snapshot.
4. Clone post data.
5. Run the documented extension filter.
6. Re-apply hard invariants: draft status, empty slug, same post type.
7. Insert the branch through WordPress APIs.
8. Copy syncable meta and taxonomies.
9. Re-snapshot the original.
10. If the original changed during creation, delete the inconsistent new branch and return a conflict.
11. Store branch relationship/baseline metadata.

## Synchronization

`includes/class-sync-service.php`

Defines exactly which parts of a post are synchronized.

Editorial fields can move from branch to original. Identity fields cannot.

The service also builds deterministic snapshot hashes used for conflict detection. Snapshot reads fail closed when WordPress cannot read taxonomy state or encode the snapshot payload.

## Merge lifecycle

`includes/class-merge-service.php`

Owns privileged merge/discard mutations.

### Normal merge flow

1. Verify branch relationship and lifecycle state.
2. Verify the original still exists.
3. Verify branch/original post types match.
4. Verify edit capability for both posts.
5. Verify the baseline conflict state is clean.
6. Run the pre-merge hook.
7. Re-fetch branch state and re-check the original conflict state.
8. Preflight taxonomy reads.
9. Update allowed original post fields through `wp_update_post()`.
10. Synchronize allowed meta and taxonomy assignments.
11. Record merge audit metadata on the branch.
12. Move the branch to Trash.
13. Fire the post-merge hook.

A force merge changes step 5 only. It never disables authorization or structural validation.

## REST API

`includes/class-rest-controller.php`

The Block Editor talks to a small REST namespace, `wbfp/v1`.

Every route has a `permission_callback`. Branch status requires access to both the branch and original because the response includes original-post information. Branch lists filter out branches the current user cannot edit.

## WordPress admin integration

`includes/class-admin.php`

Provides Classic Editor, post-list, admin-bar, notices, and Block Editor asset integration.

Classic/admin actions are nonce-protected for CSRF resistance. Domain services still repeat capability checks before mutation.

## Block Editor UI

`src/index.js`

Human-readable source using WordPress packages.

`build/index.js`

Production build shipped with the plugin.

The editor UI never performs privileged writes directly; it calls the authenticated REST routes.

## Compatibility

The 2.0 implementation reads legacy `_original_post_id` and creator metadata so existing 1.x branches remain reviewable. Legacy branches intentionally report an unknown baseline state because 1.x did not store conflict snapshots.
