# Editorial workflow extensions (development branch)

This document describes the non-released work on `feat/editorial-workflow-hardening-20261012`.
It does **not** mean that the branch is ready to tag, ship or merge.

## 1. Block-aware content merging

`Block_Merge::merge()` attempts an automatic 3-way merge only for a small,
well-formed Gutenberg document with unambiguous top-level block identities.

- Both branches must contain normal serializable block markup.
- The baseline must have at least two and at most 128 top-level blocks.
- Documents above 400 KiB, duplicated block identities, reordered blocks,
  nested or top-level shared-pattern/template-part references and freeform HTML fail closed.
- Non-overlapping edits can be merged, including independent insertions;
  simultaneous changes to the same range and ambiguous boundaries conflict.
- It does **not** claim a general semantic or character-level diff.
- A successful auto-merge appears as `auto_merged_fields` in the review data.
  The original snapshot and review-token protections still apply.

When the algorithm cannot prove independence, the existing conflict/force-merge
flow applies. Do not replace this safeguard with lossy text concatenation.

## 2. Independent editorial approval

Open a working branch in WordPress and choose **Branch review & schedule** from
the editor's admin bar, or **Review / schedule** in its post-list row action.
The same screen is accessible under **Tools → Branch Workflow**.

- Request review as a branch editor.
- Independent reviewers can find pending requests in the permission-filtered
  **Tools → Branch Workflow** queue (up to the 50 latest submissions).
- A *different* editor with the original post's edit permission and the post
  type's `edit_others_posts` capability may approve or reject.
- Reviewers should open the branch editor's existing **Review changes** panel
  to inspect the Base / Original / Branch diff before approving.
- The approved token binds to the requested content state. Any change to the
  original, branch, identity or baseline invalidates that approval.
- Approvals are also invalidated when the reviewer loses editorial permission.
- Conflicting, unknown and legacy states cannot be approved until resolved.
- Review comments and timestamps remain private branch metadata.

Independent approval enforcement is **OFF by default** for backwards
compatibility. A site administrator can turn it on at Tools → Branch Workflow,
or use the `wbfp_require_approval` filter. When on, direct service calls,
Classic Editor actions and REST merges all pass through the same gate.

## 3. Scheduled safe merge

From Tools → Branch Workflow for an editable branch, choose a future site-local
date and time (from one minute to one year ahead).

- A WP-Cron *single* event stores the current state fingerprint and actor.
- The original stays published; the branch stays a draft.
- On execution, the original and branch must still match the scheduled state.
  The actor's original/branch edit capabilities and any configured approval
  requirement are checked again.
- Normal 3-way merge is used, **never force merge**.
- Empty branches, conflicting changes and trashed originals cannot be scheduled.
- On mismatch or failure, no scheduled overwrite occurs, and the branch
  retains a private `_wbfp_schedule_error` code.
- Resubmission, cancellation, a successful merge or branch discard clears the
  pending scheduled event and its metadata.

WP-Cron is traffic-dependent unless the site runs a real cron job invoking
`wp-cron.php`. This feature cannot promise publication at an exact wall-clock
second on a site without reliable cron execution.

An authenticated `GET /wbfp/v1/branches/{id}/workflow` returns compact review,
approval and scheduling status without exposing internal snapshot tokens.

A REST action is also available: authenticated `POST
/wbfp/v1/branches/{id}/workflow` with `operation=request|approve|reject|schedule|cancel`;
`schedule` accepts `when` as a `YYYY-MM-DDTHH:mm` **site-local** value.
The service validates the caller, not merely the request schema.

## 4. Compatibility and UX work

- Ordinary Yoast SEO custom fields, Elementor JSON post meta and ACF
  references/field values participate in the existing per-meta-key
  synchronization unless explicitly excluded.
- The new tests exercise these storage cases without requiring those plugins
  to be installed. **This is not yet a full real-plugin compatibility claim.**
- The tools screen groups request/approve/reject, scheduling and site policy,
  and links back to the existing detailed editor review UI.
- The editor toolbar and list rows expose workflow entry points without
  rebuilding the Gutenberg production bundle.

## Validation still required before release

Run all of the following against a disposable WordPress installation,
preferably in the repository's quota-isolated test lab:

1. `php -l` for all changed PHP files, then PHPCS.
2. `wp eval-file .../tests/wp-real-2.1.php` against real WP/MySQL.
3. Browser tests for block merger, editor navigation, approval and scheduling.
4. Simulated WP-Cron execution, stale approval and revoked reviewer rights.
5. Real Yoast SEO, Elementor and ACF integration testing, including serialized
   builder data, canonical/SEO metadata, custom post types and nested blocks.
6. WordPress plugin-check, permissions, translation POT/PO/MO regeneration,
   release ZIP audit, screenshots, and full compatibility matrix.

**This branch has not yet passed these gates in this chat.** Do not tag,
publish or merge it based only on the code changes and added test cases.

## Pending UX/quality work

- Inline approval/scheduling controls inside the *existing* Gutenberg Post
  Branch panel (rather than requiring a separate Tools screen).
- Block-by-block diff visualization and fine-grained conflict resolution.
- Real-plugin and multilingual browser regression coverage.
- Reviewed REST responses for workflow-state presentation in Gutenberg.
