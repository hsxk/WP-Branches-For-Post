=== WP Branches For Post ===
Contributors: haokexin
Tags: post branch, editorial workflow, staging, revision, gutenberg, duplicate
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Create an isolated working branch for a published post, edit it safely, then explicitly merge it back into the original post.

== Description ==

WP Branches For Post provides a Git-like editorial workflow for WordPress content.

Instead of editing a published post in place, create a draft branch. The public original remains unchanged while the branch is edited. When the work is ready, explicitly merge the branch back into the same original post ID.

Version 2.0 modernizes the original 2020 plugin for the current WordPress block editor and REST API.

= Core workflow =

1. Open a published, private, or scheduled post/page/custom post type.
2. Choose "Create branch".
3. Edit the isolated draft branch.
4. Review the branch status in the Post Branch panel.
5. Merge into the original when ready.
6. The original keeps its URL, ID, publication status, author, date, comments, and external identity.
7. The merged branch is moved to Trash instead of being permanently deleted.

= Safety =

* Branches are kept non-public and cannot accidentally replace the original by pressing WordPress Publish.
* Every new branch stores a snapshot of the original state.
* If the original changes after the branch was created, a normal merge is blocked.
* A force merge is available only as an explicit action after review.
* REST endpoints use WordPress capability checks in permission callbacks.
* The plugin no longer updates revisions or attachment parents with direct SQL.
* WordPress creates the normal revision history when the original post is updated.

= What is synchronized =

During merge, the plugin synchronizes editable post content, post meta, and all taxonomies while preserving the original post identity and publication state.

Media IDs referenced by post content and featured-image meta remain the same. Attachments are not re-parented.

= Block Editor =

The Post Branch document panel shows:

* Create Branch on an original post.
* Existing active branches.
* Original post link from a branch.
* Conflict status.
* Merge into original.
* Force merge after review when a conflict exists.
* Discard branch.

Classic Editor, post-list row actions, and the admin bar retain lightweight compatibility controls.

= Compatibility with 1.x =

Version 2.0 recognizes branches created by the 1.x metadata format.

Legacy branches do not contain a baseline snapshot, so they are reported as "unknown" conflict state and require an explicit force merge after the original has been reviewed.

The old automatic "publish branch to overwrite original" behavior has intentionally been removed.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/wp-branches-for-post`, or install it through the WordPress Plugins screen.
2. Activate WP Branches For Post.
3. Open an existing published post and use the Post Branch panel or Create Branch action.

== Screenshots ==

1. Block Editor panel on an original post, with a safe Create branch action.
2. Editing an isolated branch with explicit Merge into original and Discard branch actions.
3. Conflict protection when the original post changes after branch creation.
4. Post list integration showing Create branch, Merge branch, and branch state.

== Frequently Asked Questions ==

= Does a branch get a public URL? =

No. A branch is an isolated working draft. Publishing a branch through the normal editor is prevented; use Merge into original instead.

= Does merging change the original URL or post ID? =

No. The merge updates the existing original post record and preserves its identity, slug, publication state, author, and original dates.

= What happens if someone edits the original while I am working on a branch? =

The stored baseline snapshot no longer matches. The plugin marks the branch as conflicted and blocks the normal merge so newer work is not silently overwritten.

= Can I still merge an old 1.x branch? =

Yes, but because 1.x did not store a baseline snapshot, the plugin requires an explicit force merge after review.

= Does it support custom post types? =

Yes, when the user can edit the post and the post type uses the normal WordPress editing APIs. Taxonomies and post meta are synchronized with the branch.

== Developer Notes ==

Useful filters and actions include:

* `wbfp_branchable_post_statuses`
* `wbfp_create_branch_post_data`
* `wbfp_excluded_meta_keys`
* `wbfp_mergeable_post_fields`
* `wbfp_branch_created`
* `wbfp_before_merge`
* `wbfp_after_merge`

The Block Editor source is in `src/index.js` and is built with `@wordpress/scripts`.

== Changelog ==

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
* Preserve original post ID, URL, status, author, slug, and publication dates during merge.
* Move merged/discarded branches to Trash instead of permanently deleting them.
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
