# Security

WP Branches For Post treats branch creation and merging as privileged content mutations. The plugin follows WordPress capabilities and APIs rather than introducing a separate permission system.

## Authorization boundaries

- Creating a branch requires `current_user_can( 'edit_post', $original_id )` and the target post type's `create_posts` capability.
- Merging requires edit capability for both the branch and the original post.
- Discarding requires `delete_post` capability for the branch.
- REST routes define explicit `permission_callback` functions.
- Service-layer methods repeat capability checks before mutations. REST or admin checks are defense in depth, not the sole authorization boundary.
- A force merge bypasses conflict blocking only. It does **not** bypass capability, relationship, existence, or post-type validation.

## CSRF protection

Classic Editor, post-list, and admin-bar actions use WordPress nonces through `wp_nonce_url()` and `check_admin_referer()`.

Nonces are used only for request-intent / CSRF protection. They are never treated as proof of authorization; capability checks remain mandatory in the service layer.

The Block Editor uses authenticated WordPress REST requests through `@wordpress/api-fetch`, with route-level permission callbacks.

## Input and output handling

- Numeric IDs are normalized with `absint()` or REST argument schemas.
- REST route IDs are validated as positive integers.
- Boolean force-merge input is declared in the REST schema.
- Admin notice query values are reduced to known keys with `sanitize_key()`.
- Admin HTML escapes URLs, attributes, and plain text with WordPress escaping functions.
- The one notice that intentionally contains an edit link is rendered with `wp_kses_post()`.

## Branch visibility invariant

A branch must never become a public replacement for the original post.

- New branches are forced to `draft` after extension filters have run.
- The original post type is re-applied after extension filters.
- Branch slugs are cleared.
- Existing branches are normalized back to `draft` by the `wp_insert_post_data` filter if normal editing attempts to publish them.
- Trash and auto-draft remain valid internal lifecycle states.

## Conflict and stale-write protection

Each 2.0 branch stores a deterministic baseline hash of the original post's editorial state.

The hash includes relevant post fields (including post type), synchronized metadata, and taxonomy assignments. A normal merge is blocked when the current original differs from the stored baseline.

Conflict checks fail closed:

- If the original is missing, the merge is blocked.
- If taxonomy state cannot be read, snapshot creation fails rather than treating it as empty data.
- If JSON snapshot encoding fails, snapshot creation fails.
- A normal merge checks the original again immediately before merge writes.
- The branch and original are re-fetched after pre-merge hooks to avoid merging stale in-memory data.
- The branch must still point to the same original after hooks run and must not have moved to Trash.
- Taxonomy reads are preflighted before the original post is modified.

Legacy 1.x branches do not have a baseline hash and therefore require an explicit force merge after review.

## Data synchronization boundaries

A merge copies only editorial content fields, syncable post meta, and taxonomy assignments.

The original keeps its identity and publication state, including:

- post ID
- GUID
- slug
- author
- post status
- publication dates

Runtime and branch-control meta are explicitly excluded from synchronization, including edit locks, legacy branch metadata, trash metadata, and old-slug runtime metadata.

The plugin uses WordPress post/meta/taxonomy APIs and does not issue direct SQL writes.

## Recovery

Merged and discarded branches are moved to Trash rather than permanently deleted. This preserves a recovery path and avoids destructive cleanup during the merge operation.

## Reporting a security issue

Please report security issues privately to the maintainer through the contact information at:

https://time2log.com/about/

Do not publish an exploitable issue before the maintainer has had a reasonable opportunity to investigate it.
