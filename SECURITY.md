# Security

WP Branches For Post treats branch creation, branch refresh, merge, force merge, and discard as privileged content mutations. The plugin follows WordPress capabilities and APIs instead of introducing a separate permission system.

## Authorization boundaries

- Creating a branch requires `current_user_can( 'edit_post', $original_id )` and the post type's `create_posts` capability.
- Merging and updating a branch from the original require edit capability for both branch and original.
- Discarding requires `delete_post` capability for the branch.
- REST routes define explicit `permission_callback` functions.
- Service-layer methods repeat capability, relationship, existence, and post-type checks before mutations.
- A force merge bypasses conflict blocking only. It never bypasses authorization or relationship validation.
- The `wbfp_can_force_merge` filter can further restrict force merge; it cannot bypass the service's required edit capabilities.

## CSRF and request integrity

Classic Editor, post-list, and admin-bar mutation URLs use WordPress nonces through `wp_nonce_url()` and `check_admin_referer()`.

The Block Editor uses authenticated WordPress REST requests through `@wordpress/api-fetch`. Nonces/request authentication prove request intent; capability checks remain the authorization boundary.

Version 2.1 also returns a content-state `review_token` with branch status. The editor sends the token from the state the user reviewed. If branch, original, identity, baseline, or relationship state changes before the REST merge request, the request is rejected and the user must review again.

## Branch visibility invariant

A branch must not become a public replacement for the original post.

- New branches are forced to `draft`.
- The original post type is re-applied after extension filters.
- Branch slugs are cleared.
- Existing branches are normalized back to `draft` when normal WordPress editing attempts to publish them.
- Trash and auto-draft remain valid internal lifecycle states.

## Version 2.1 three-way review model

Every new 2.1 branch stores a full baseline snapshot of the original. The snapshot contains:

- allowed editorial post fields
- syncable post metadata
- taxonomy assignments
- identity fields used for review, such as post status, slug, author, parent, and post type

The plugin compares:

1. the baseline captured when the branch was created or last refreshed;
2. the current original;
3. the current branch.

This separates:

- branch-only changes, which can be applied;
- original-only changes, which are preserved;
- overlapping divergent changes, which are conflicts;
- identity changes on the original, which are informational and preserved.

The baseline snapshot and its deterministic hash are written together and read back after storage. If baseline metadata cannot be verified, branch creation fails or a branch refresh rolls back.

## Stale-write protection

Conflict review is not treated as a one-time UI decoration.

- The editor refreshes branch status immediately before merge.
- The editor refuses to merge if the state token differs from the state that was reviewed.
- The REST merge route rejects a mismatched supplied review token.
- The merge service re-fetches and re-analyzes the relationship after the pre-merge hook.
- Immediately before writes, the service captures fresh original and branch snapshots and compares them with the snapshots used for analysis.
- If either side changed after analysis, the merge is rejected with `wbfp_review_state_changed`.
- Normal merge still refuses unresolved conflicts.
- Force merge applies only after the current state has passed the same freshness checks.

These checks reduce the window in which another editor's save could be silently overwritten without using direct SQL row locks.

## Update branch from original

A branch can be refreshed only when original changes do not conflict with branch changes.

The service builds a rebased merge state, applies it through WordPress APIs, verifies the resulting state, and then writes a fresh baseline. If applying the rebased state fails, the previous branch state is restored. If the new baseline metadata cannot be stored and verified, both the branch state and its previous baseline metadata are restored.

## Merge and rollback

Before a merge modifies the original, the plugin captures a complete rollback snapshot.

- Allowed post fields are updated through `wp_update_post()`.
- Syncable metadata is replaced and verified.
- Taxonomy assignments are replaced and verified.
- The final mergeable state is re-snapshotted and verified.
- If any write or verification fails, the plugin attempts to restore the complete pre-merge original state.
- After a successful write, the branch is moved through WordPress's Trash API.
- If the branch cannot be moved to Trash, the plugin rolls the original back and leaves the branch active rather than returning a partial success.
- Merge audit metadata is written only after the branch has been successfully trashed.

The plugin uses WordPress's Trash behavior; site-level WordPress Trash configuration still applies.

## Data synchronization boundaries

A merge can change only the plugin's allowlisted editorial fields, syncable metadata, and taxonomy assignments.

The original keeps its identity and publication state, including:

- post ID
- GUID
- slug
- author
- post status
- publication dates
- hierarchical parent

Runtime and branch-control metadata are excluded from synchronization, including edit locks, branch relationship/baseline metadata, Trash metadata, and old-slug runtime metadata.

Extension filters cannot add arbitrary identity fields to the mergeable core-field allowlist.

## Synced patterns

Synced WordPress patterns are global entities. Referencing a synced pattern from a branch does not isolate edits made to the pattern itself. Version 2.1 detects synced-pattern references and warns the editor so a global pattern edit is not mistaken for branch-local work.

## Legacy branches

- 2.0 branches with the legacy baseline hash can still merge normally when the original matches that baseline.
- Older branches without an exact 2.1 snapshot cannot provide a precise three-way comparison.
- Uncertain legacy states require explicit review before force merge.
- Legacy compatibility never bypasses capability, relationship, or post-type checks.

## Input and output handling

- Numeric IDs are normalized with `absint()` or positive-integer REST schemas.
- Boolean force-merge input is declared in the REST schema.
- Admin notice query values are reduced to known keys with `sanitize_key()`.
- Admin HTML escapes URLs, attributes, and plain text with WordPress escaping functions.
- Review excerpts expose only bounded plain-text versions of core editorial text fields; arbitrary metadata values are not returned in the convenience review payload.

## Reporting a security issue

Please report security issues privately to the maintainer through the contact information at:

https://time2log.com/about/

Do not publish an exploitable issue before the maintainer has had a reasonable opportunity to investigate it.
