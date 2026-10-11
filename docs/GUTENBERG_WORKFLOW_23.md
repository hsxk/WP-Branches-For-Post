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

## Still open for a later 2.3 pass

- Secure, genuinely selective per-field conflict application (not merely a
  decorative choice selector).
- Full keyboard/screen-reader and mobile responsive E2E coverage.
- Multilingual review of the new workflow labels without altering the
  currently approved catalogs.
- A WordPress Playground interactive product demonstration.
