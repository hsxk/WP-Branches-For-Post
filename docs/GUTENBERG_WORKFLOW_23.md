# Gutenberg editorial workflow 2.3 development

This is **unreleased** 2.3 development work. The published WordPress.org
plugin remains v2.2.0 until a separately reviewed release.

## What changed

The existing `Post Branch` document sidebar is retained. A collapsible
**Review / schedule** section now uses the same authenticated
`/wbfp/v1/branches/{id}/workflow` routes as the WordPress Tools screen.

- Request independent review without leaving the editor.
- Authorized editors who are not the branch creator may approve or request
  changes, with an optional review note.
- Choose a **site-local** future merge date and time. Display the site's
  timezone returned by WordPress. The server rejects invalid dates and
  stale/conflicting/unauthorized merge requests.
- Cancel a scheduled merge without navigating to Tools.
- Display workflow approval state and UTC scheduled time.
- Mark field-level conflicts in the existing three-way comparison so the
  affected fields can be inspected before any force merge.

The UI does **not** implement arbitrary per-field merge mutation or override
backend approval checks. Where conflict resolution is ambiguous, the existing
conservative server behavior is preserved. The selected clock time is
interpreted in the configured WordPress site timezone, not the visitor's
browser timezone.

## Safety invariants

1. New controls never bypass the server's `Workflow_Service` and
   `Merge_Service`.
2. Review and schedule actions are disabled while Gutenberg has unsaved
   changes, to avoid requesting approval for unseen content.
3. The reviewer capability, author separation, state hash, approval policy,
   cron-event ID and due-time checks remain server-enforced.
4. Existing translations and catalog artifacts remain unchanged.
   Existing gettext messages are reused for controls wherever possible.
5. The normal 2.2 three-way review and force-merge confirmation remain
   available. No direct data mutations are performed by the new UI.

## Acceptance tests

- WordPress integration validates site-timezone REST data.
- Gutenberg browser E2E verifies inline review, creator self-approval UI
  guard, schedule, cancel and continued Classic Tools workflow.
- PHP 8.2/8.3/8.4 quality, WordPress 6.6/7.1 compatibility, Plugin Check,
  editor build reproducibility, tutorial screenshot manifest and packaging.
- Every generated build asset must match `src/index.js` before merging.
- No merge to `master` or version tag until exact PR head gates are green.

## Selective conflict handling (2.3 follow-up)

For conflicting `post_title`, `post_excerpt`, `post_content`,
`menu_order`, `comment_status` and `ping_status`, an authorized editor
with force-merge authority may explicitly select **Original now** or
**Branch now** for *every* conflict before a separate confirmation.
The supported choices are intentionally **unset by default**. Other
independent edits remain preserved by the normal three-way merge.

This is implemented in the merge snapshot builder, REST controller and
merge service, not only in the browser. The server rejects incomplete,
extra, malformed or unsupported paths; it also blocks missing/stale
review tokens, forced merge combined with choices, and changing data
between analysis and application. Existing snapshot verification and
rollback still apply. Arbitrary metadata, password state, and complex
unreviewable conflict types have **no selective controls** and are never
silently modified.

Sites with mandatory independent approval continue to reject merges
without valid approval; selective choices do not bypass that requirement.
A subsequent milestone must support a separately approved, non-conflicting
resolution proposal if such sites are to use selective conflict actions
rather than manual branch editing.

The browser test covers choosing the branch title and original excerpt,
and the WordPress integration suite covers missing/extra selections,
unknown values and stale tokens. This remains unreleased 2.3 development
until its PR checks pass and a later full release is prepared.

## Independently reviewed conflict resolutions

When independent approval is mandatory, a branch author chooses an explicit
Original/Branch winner for every currently conflicting supported field.
**Request review** stores the proposal on the private branch, never the public
original. The proposal is immutable from the Gutenberg review panel while
pending or approved. A separate authorized reviewer sees the chosen values
alongside the three-way comparison and approves or rejects them.

Approval is pinned to **both** the current snapshot hash and the SHA-256 hash
of the proposed choices. Altering either invalidates approval. At merge time
the service checks that the exact approved map is supplied both before and
after the merge hooks. An omitted, expanded or modified resolution map fails
closed, even for users with force-merge permission. Independently edited
original fields remain preserved.

Older approved conflict-free branches still merge without any proposal.
Sites with approval disabled retain the original selective-merge workflow.
Arbitrary metadata and unsupported conflicts cannot be submitted as reviewable
selection maps and require manual resolution.

## WordPress 7.1.3 compatibility

WordPress 7.1.3 was released October 6, 2026. The project runs a real
WordPress installation matrix on 6.6/PHP 8.2 and 7.1.3/PHP 8.2, 8.3, 8.4;
third-party plugin coexistence is exercised on 7.1.3/PHP 8.2. The real
Chromium Gutenberg E2E and tutorial screenshot workflow also install 7.1.3.
Version coverage must be assessed from successful tests at the exact PR head;
a YAML version bump alone is not evidence.

## Accessible/mobile verification

The browser contract additionally checks responsive workflow sizing in a
390px editor viewport, keyboard focus and Enter activation of the disclosure
button, and its updated `aria-expanded` state. The reviewed conflict choices
remain usable through labeled native select controls without drag-only UI.
Screen reader conformance beyond these behavior checks still requires
independent manual testing.

## Still open for a later 2.3 pass

- Full keyboard/screen-reader and mobile responsive E2E coverage.
- Multilingual review of the new workflow labels without altering the
  currently approved catalogs.
- A WordPress Playground interactive product demonstration.
