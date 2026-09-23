=== WP Branches For Post ===
Contributors: haokexin, alkesh7
Tags: post branch, editorial workflow, staging, revision, gutenberg
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Create a safe working branch for published WordPress content, review exactly what changed, and merge it back without replacing the public post.

== Description ==

WP Branches For Post adds a Git-like editorial workflow to WordPress.

When a published page or post needs a larger edit, you do not have to change the live content directly. Create a private working branch, edit it in the normal WordPress editor, review the differences, and merge only when you are ready. The public original keeps its post ID, URL and publication identity throughout the workflow.

Version 2.1 adds a three-way merge review. The plugin remembers the original state at branch creation, compares the current original with the current branch, separates non-conflicting work from real conflicts, and helps you update or merge without silently overwriting newer changes.

= The workflow at a glance =

1. Open a published, private or scheduled post, page, or supported custom post type.
2. Open the Post Branch panel and choose "Create branch".
3. WordPress opens an isolated draft branch. The public original is unchanged.
4. Edit the branch normally. Preview the branch whenever you need to.
5. Choose "Review changes".
6. Review branch changes, newer original changes, conflicts, and identity changes.
7. If the original has newer non-conflicting edits, choose "Update branch from original" when you want the branch to include them before continuing.
8. When the review is clean, choose "Save & merge into original".
9. If both sides changed the same value differently, review the conflict and use force merge only when you intentionally want the branch value for the listed conflict.
10. After a successful merge, the original remains the public post and the working branch is moved to Trash.

= Feature guide with screenshots =

The screenshots on the plugin page follow the same workflow described below, so you can see what each control looks like before using it:

* Screenshot 1 — Start safely: open the Post Branch panel on the original and choose "Create branch". The live post is not edited.
* Screenshot 2 — Work in isolation: the branch editor shows its relationship to the original, preview links, review state, and the actions available for that branch.
* Screenshot 3 — Review before merging: "Review changes" opens the three-way comparison so you can inspect the baseline, current original, and branch values together.
* Screenshot 4 — Bring in safe upstream work: when the original has newer non-conflicting changes, "Update branch from original" becomes available.
* Screenshot 5 — Continue after updating: after the branch is refreshed from the original, the editor reloads with the rebased state and a fresh merge review.
* Screenshot 6 — Stop on real conflicts: when both the original and branch changed the same mergeable value differently, normal merge is blocked and the conflicting paths are shown.
* Screenshot 7 — Force only after review: force merge uses a separate destructive confirmation and prefers the branch value only for reviewed conflicts.
* Screenshot 8 — Refuse stale reviews: if the branch or original changes after review, the merge is stopped and the current state must be reviewed again.
* Screenshot 9 — Discard explicitly: abandoning a branch requires confirmation and moves only the branch to Trash; the public original is untouched.
* Screenshot 10 — See active work from the original: the original post lists existing branches and their current review states.
* Screenshot 11 — Keep normal WordPress navigation: branch state and quick actions remain available from the post list.
* Screenshot 12 — Understand synced-pattern scope: branches that reference synced patterns display a warning because editing the synced pattern itself is global in WordPress.

= What the Post Branch panel tells you =

On an original post, the panel shows whether a branch can be created and lists active branches you can edit.

On a branch, the panel shows:

* A link back to the original.
* Branch and original preview links.
* The branch creator.
* The current review state.
* How many branch changes and conflicts were detected.
* "Review changes" to open the detailed merge review.
* "Update branch from original" when newer original changes can be brought into the branch safely.
* "Save & merge into original" when the branch can be merged normally.
* An explicit force-merge path for reviewed conflicts.
* "Discard branch" when the working branch should be abandoned.

= Understanding the review states =

* Clean: no incompatible newer change prevents a normal merge.
* Update available: the original contains newer non-conflicting work. The plugin can update the branch while keeping branch-only edits.
* Informational: the original changed identity-related data that the branch is not allowed to overwrite, such as slug or publication identity.
* Conflict: the original and the branch changed the same mergeable data differently. Normal merge is blocked until you review the situation.
* Legacy/unknown: an older branch does not have the full 2.1 baseline needed for an exact three-way review. Review it manually before any force merge.
* Missing: the original can no longer be safely resolved, so merge is unavailable.

= What the merge review contains =

The review separates changes into four groups:

* Branch changes: mergeable values changed in the working branch.
* Original changes since branch creation: newer changes made directly to the original.
* Conflicts: paths changed differently on both sides.
* Original identity changes preserved by merge: changes the plugin deliberately does not overwrite.

For reviewable fields, the panel also shows the baseline, current original and branch values so you can understand why an item is listed.

= What is synchronized =

A successful merge can synchronize normal editorial content, supported post meta, and taxonomies.

The plugin intentionally preserves the original post's identity and publication state. In particular, the merge does not replace the original post ID, GUID, slug, author or publication dates. For hierarchical content, the branch cannot change the original parent relationship because that can change a public URL.

Media IDs already referenced by content and featured-image metadata remain usable. Attachments are not re-parented.

= Editing while someone changes the original =

Version 2.1 uses the baseline captured at branch creation to distinguish three situations:

* Only the branch changed a value: the branch value can be applied.
* Only the original changed a value: the newer original value is preserved.
* Both changed the same value differently: the value is a conflict and normal merge is blocked.

This is why a branch can often merge safely even when the original changed after branch creation.

= Update branch from original =

When the original has newer non-conflicting changes, "Update branch from original" rebases the working branch onto the newer original state.

Branch-only work is preserved, original-only work is imported into the branch, and a fresh baseline is recorded. If the operation cannot complete safely, the plugin attempts to restore the branch to its previous state instead of leaving a partial update.

= Force merge =

Force merge is intentionally not the default path.

It is offered only after a conflict is detected and reviewed. For listed merge conflicts, force merge prefers the branch value. Non-conflicting newer work on the original is still preserved.

Extensions can further restrict who is allowed to force merge through the plugin's capability/filter boundary.

= Synced patterns =

A WordPress synced pattern is global content. Editing the synced pattern itself changes that pattern everywhere it is used and is not isolated by a post branch.

When a branch references synced patterns, WP Branches For Post warns you in the branch panel so that global pattern edits are not mistaken for isolated branch edits.

= Safety and failure handling =

* Branches stay non-public and normal WordPress publishing is forced back to draft.
* Mutating operations repeat WordPress capability checks at the service layer.
* Browser actions and REST routes use the appropriate nonce/permission protections.
* Every 2.1 branch stores a full baseline snapshot for three-way review.
* Normal merge refuses unresolved conflicts.
* Merge and rebase operations verify synchronized state, reject stale reviewed data, and attempt rollback after write failures.
* If a merge cannot finish its branch cleanup, the original is restored instead of reporting a partial success.
* Successful merges and discarded branches go to Trash instead of being permanently deleted.
* WordPress creates the normal revision history when the original is updated.
* The plugin does not directly rewrite revisions or attachment parents with SQL.

= Block Editor, Classic Editor and list screens =

The complete review experience is available in the Block Editor document sidebar.

Classic Editor, post-list row actions and the admin bar retain compatibility controls for creating/opening branches and performing supported branch actions. For the clearest conflict review, use the Block Editor panel.

= Troubleshooting the branch controls =

If an action is unavailable or the plugin stops a merge, that is normally a safety check rather than a hidden setting:

* Create branch is disabled while the original has unsaved Block Editor changes. Save or discard those edits first so the branch baseline matches the saved original.
* Create branch is available only for supported saved statuses such as published, private and scheduled content, and only when your WordPress capabilities allow the operation.
* Update branch from original appears only when the original has newer changes that do not conflict with the branch. You do not have to run it before every merge.
* Save & merge into original is not offered for an unresolved true conflict. Open Review changes and inspect the listed paths first.
* If the branch or original changes after you reviewed it, the merge is stopped and the review is refreshed. Review the current state before trying again.
* Force merge is shown only for a state that requires explicit conflict/legacy review and only when your account is allowed to force the merge.
* If the original is missing or no longer matches the branch post type, merge is unavailable.
* The Block Editor provides the complete three-way review. Classic Editor and list screens keep compatibility actions, but conflict-heavy work is clearest in the Block Editor.

= Compatibility with older branches =

Branches created by older plugin versions remain recognizable.

A 2.0-style branch with a compatible stored baseline hash can still merge normally when the original has not changed. Older branches without a full 2.1 snapshot cannot show the detailed three-way comparison, so the plugin treats them conservatively and requires explicit review when the state is uncertain.

== Installation ==

1. Install WP Branches For Post from the WordPress Plugins screen, or upload it to `/wp-content/plugins/wp-branches-for-post`.
2. Activate the plugin.
3. Open an existing published, private or scheduled post/page in the editor.
4. Open the Post Branch panel in the editor settings sidebar.
5. Choose "Create branch" to begin a safe working copy.

No separate settings page is required for the normal workflow.

== Screenshots ==

1. Original post: the Post Branch panel explains the isolated workflow and provides the Create branch action.
2. Working branch: branch status, original/branch preview links, Review changes and Discard branch controls.
3. Merge review: a three-way comparison of baseline, current original and branch values before merge.
4. Update available: newer non-conflicting original changes can be brought into the branch with Update branch from original.
5. Updated branch: the rebased branch reloads with the newest safe original changes and a fresh review state.
6. Conflict protection: normal merge is blocked when both sides changed the same value differently.
7. Force merge confirmation: an explicit destructive confirmation is required before reviewed conflicts can prefer branch values.
8. Stale review protection: a merge is stopped when the branch or original changed after the state was reviewed.
9. Discard confirmation: the branch can be moved to Trash without modifying the public original.
10. Existing branches on the original: active branches and their review states are visible before creating more work.
11. Post list integration: branch state and quick branch actions remain available from WordPress admin lists.
12. Synced pattern warning: the editor explains that editing a referenced synced pattern is global and not isolated by the branch.

== Frequently Asked Questions ==

= Is a branch public? =

No. A branch is an isolated working draft. A normal WordPress publish attempt is forced back to draft. Use the plugin's merge action when the work is ready.

= Does merging change the original URL or post ID? =

No. The existing original post remains the public resource. Its post ID and identity are preserved, and identity-related values such as slug, author and publication dates are not taken from the branch.

= What happens if someone edits the original while I am working? =

The plugin compares the original, the branch and the saved baseline. Original-only work can be preserved, non-conflicting work can be rebased, and overlapping divergent edits are reported as conflicts instead of being silently overwritten.

= Do I have to update the branch before every merge? =

No. If newer original changes do not conflict with the branch, the three-way merge can preserve them. Updating the branch first is useful when you want to continue editing on top of the newest original state.

= What does force merge overwrite? =

For conflicts you explicitly review, force merge prefers the branch value. Newer original work that does not conflict with the branch is still preserved.

= Can I preview the branch before merging? =

Yes. The branch panel provides a branch preview link and a link to the current original so you can compare them outside the editor.

= What happens when I discard a branch? =

The branch is moved to Trash. The original post is not changed.

= What happens after a successful merge? =

The original is updated through WordPress, keeping its identity, and the merged branch is moved to Trash.

= Are categories, tags and custom taxonomies included? =

Supported taxonomies are synchronized as part of the branch workflow, including clearing a taxonomy when that is the reviewed branch state.

= Is post metadata included? =

Supported post metadata is synchronized. Runtime/plugin bookkeeping keys are excluded, and extensions can add their own exclusions.

= What about featured images? =

Featured-image metadata is part of normal synchronized metadata. The referenced media item keeps its existing attachment identity.

= Are synced patterns isolated? =

No. Synced patterns are global WordPress entities. The plugin detects references and warns you, but editing a synced pattern itself affects every place that pattern is used.

= Does it support custom post types? =

Yes, when the post type uses the normal WordPress editing APIs and the current user has the required capabilities.

= Can I use branches created by older plugin versions? =

Yes. Compatibility paths remain, but older branches may not have the full 2.1 baseline required for detailed three-way review and are therefore handled more conservatively.

== For Developers ==

Useful filters and actions include:

* `wbfp_branchable_post_statuses`
* `wbfp_create_branch_post_data`
* `wbfp_excluded_meta_keys`
* `wbfp_mergeable_post_fields`
* `wbfp_can_force_merge`
* `wbfp_branch_created`
* `wbfp_before_merge`
* `wbfp_after_merge`

Security-critical invariants remain enforced after extension filters run. A branch remains a draft of the same post type, protected runtime metadata remains excluded, mergeable core fields stay inside the plugin's allowlist, and force merge still passes through the plugin's authorization boundary.

See `ARCHITECTURE.md` for the data flow, `SECURITY.md` for authorization and synchronization boundaries, and `TESTING.md` for the reproducible full-validation environment used for 2.1.

The Block Editor source is in `src/index.js` and is built with `@wordpress/scripts`.

Human-readable source code and build tooling are maintained at https://github.com/hsxk/WP-Branches-For-Post/.

== Changelog ==

= 2.1.0 =
* Added a three-way branch/original/baseline review instead of treating every newer original edit as the same kind of conflict.
* Added detailed branch changes, original changes, exact conflicts and preserved identity changes to the Block Editor merge review.
* Added safe "Update branch from original" behavior for newer non-conflicting original work.
* Added selective three-way merge behavior that preserves original-only changes while applying branch-only changes.
* Added explicit reviewed force-merge handling for true overlapping conflicts.
* Added rollback and post-write verification for merge and branch-update failures.
* Added reviewed-state tokens and service-layer freshness checks so a merge is rejected if the branch or original changes after review.
* Reloaded Gutenberg after Update branch from original so the editor always reflects the rebased server state.
* Added rollback when a successfully written merge cannot move its branch to Trash, preventing partial-success states.
* Aligned Classic Editor and post-list actions with 2.1 review states so safe non-conflicting updates do not require force merge.
* Added verified baseline-metadata writes and rollback when a branch refresh cannot persist its new baseline.
* Added stale-state checks before and after branch refresh writes so concurrent original/branch saves require a fresh review.
* Preserved hierarchical post parents during merge to avoid unexpected public URL changes.
* Added branch/original preview links and synced-pattern warnings.
* Added real WordPress 7.1 integration coverage and real Gutenberg browser end-to-end coverage for the 2.1 workflow.
* Expanded the WordPress.org documentation and screenshots to explain the complete workflow.

= 2.0.1 =
* Raised the minimum supported WordPress version to 6.6 for the current block-editor JSX runtime.
* Added missing translator comments and refreshed the shipped block-editor build.
* Improved Plugin Check and PHPCS coverage and aligned the release build tree.
* Moved WordPress.org artwork into the standard .wordpress-org directory and added tag-driven SVN deployment.
* Added additional real-WordPress security verification coverage.
* Special thanks to @miyanialkesh7 for the work contributed through PRs #2–#6: real-WordPress security verification, Plugin Check and PHPCS improvements, i18n fixes, and WordPress.org release tooling.

= 2.0.0 =
* Rebuilt the branch workflow for modern WordPress.
* Added Block Editor document sidebar integration.
* Added REST endpoints with capability-based permission callbacks.
* Added baseline snapshot conflict detection.
* Replaced publish-triggered merge with explicit merge actions.
* Prevented branch posts from becoming public.
* Reworked post meta synchronization to preserve multi-value keys and removals.
* Reworked taxonomy synchronization so cleared taxonomies are also propagated.
* Removed direct SQL revision and attachment-parent manipulation.
* Preserved original post ID, URL, status, author, slug, and publication dates during merge.
* Moved merged/discarded branches to Trash instead of permanently deleting them.
* Added compatibility handling for branches created by version 1.x.
* Added modern development tooling and CI syntax/build checks.

= 1.3.0 =
* Added developer filters and code cleanup.

= 1.2.0 =
* Added zh_CN, zh_TW, and ja translations.

= 1.1.0 =
* Added admin-bar/list actions and initial block-editor support.

= 1.0.0 =
* Initial release.
