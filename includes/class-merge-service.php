<?php
/**
 * Branch merge and discard operations.
 *
 * Security model:
 * - Capabilities are checked in this service even when the caller already
 *   checked them. This keeps authorization close to the mutation.
 * - Force merge bypasses conflict blocking only; it never bypasses capability,
 *   relationship, post-type, or existence checks.
 * - A normal merge fails closed when the original cannot be verified.
 *
 * @package WPBranchesForPost
 */

namespace WP_Branches_For_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies reviewed branch content back to the original post.
 */
final class Merge_Service {
	private Branch_Service $branches;

	/**
	 * @param Branch_Service $branches Branch relationship service.
	 */
	public function __construct( Branch_Service $branches ) {
		$this->branches = $branches;
	}

	/**
	 * Merge a branch into its original post.
	 *
	 * Only editorial fields, syncable meta, and taxonomy assignments are copied.
	 * The original post ID, slug, GUID, author, status, and publication dates are
	 * deliberately preserved.
	 *
	 * @param int  $branch_id Branch post ID.
	 * @param bool $force     Whether to explicitly bypass conflict blocking.
	 * @return int|\WP_Error Original post ID on success.
	 */
	public function merge( int $branch_id, bool $force = false ) {
		$branch = get_post( $branch_id );
		if ( ! $branch || ! $this->branches->is_branch( $branch_id ) || in_array( $branch->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return new \WP_Error(
				'wbfp_not_branch',
				__( 'This post is not a branch.', 'wp-branches-for-post' ),
				array( 'status' => 400 )
			);
		}

		$original_id = $this->branches->get_original_id( $branch_id );
		$original    = get_post( $original_id );
		if ( ! $original ) {
			return new \WP_Error(
				'wbfp_missing_original',
				__( 'The original post no longer exists.', 'wp-branches-for-post' ),
				array( 'status' => 404 )
			);
		}

		// Relationship corruption must never allow cross-post-type writes.
		if ( $branch->post_type !== $original->post_type ) {
			return new \WP_Error(
				'wbfp_post_type_mismatch',
				__( 'This post is not a branch.', 'wp-branches-for-post' ),
				array( 'status' => 400 )
			);
		}

		// Authorization is checked here even when REST/admin callers checked it.
		if ( ! current_user_can( 'edit_post', $branch_id ) || ! current_user_can( 'edit_post', $original_id ) ) {
			return new \WP_Error(
				'wbfp_cannot_merge',
				__( 'You do not have permission to merge this branch.', 'wp-branches-for-post' ),
				array( 'status' => 403 )
			);
		}

		$conflict = $this->branches->conflict_state( $branch_id );
		if ( ! $force && Branch_Service::CONFLICT_CLEAN !== $conflict ) {
			return new \WP_Error(
				'wbfp_merge_conflict',
				Branch_Service::CONFLICT_UNKNOWN === $conflict
					? __( 'This legacy branch has no baseline snapshot. Review the original before forcing the merge.', 'wp-branches-for-post' )
					: __( 'The original post changed after this branch was created. Review the changes before forcing the merge.', 'wp-branches-for-post' ),
				array(
					'status'   => 409,
					'conflict' => $conflict,
				)
			);
		}

		/**
		 * Fires before a branch merge is applied.
		 *
		 * Hooks may inspect the merge but should avoid mutating either post. The
		 * plugin re-fetches the branch and re-checks the original immediately
		 * afterwards to reduce stale-write risk.
		 *
		 * @param int  $branch_id   Branch post ID.
		 * @param int  $original_id Original post ID.
		 * @param bool $force       Whether this is an explicit force merge.
		 */
		do_action( 'wbfp_before_merge', $branch_id, $original_id, $force );

		// Re-fetch after hooks so stale in-memory post/original data is not merged.
		$branch              = get_post( $branch_id );
		$original            = get_post( $original_id );
		$current_original_id = $this->branches->get_original_id( $branch_id );
		if (
			! $branch
			|| ! $original
			|| $current_original_id !== $original_id
			|| in_array( $branch->post_status, array( 'trash', 'auto-draft' ), true )
			|| $branch->post_type !== $original->post_type
		) {
			return new \WP_Error(
				'wbfp_not_branch',
				__( 'This post is not a branch.', 'wp-branches-for-post' ),
				array( 'status' => 400 )
			);
		}

		// Re-check immediately before writes to catch concurrent original edits.
		if ( ! $force && Branch_Service::CONFLICT_CLEAN !== $this->branches->conflict_state( $branch_id ) ) {
			return new \WP_Error(
				'wbfp_merge_conflict',
				__( 'The original post changed before the merge could be applied. Review the latest version and try again.', 'wp-branches-for-post' ),
				array(
					'status'   => 409,
					'conflict' => Branch_Service::CONFLICT_CHANGED,
				)
			);
		}

		// Preflight taxonomy reads before modifying the original post.
		$taxonomy_error = Sync_Service::validate_taxonomies( $branch_id, $original->post_type );
		if ( $taxonomy_error ) {
			return $taxonomy_error;
		}

		$updated = Sync_Service::copy_core_fields( $branch, $original_id );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		Sync_Service::sync_meta( $branch_id, $original_id );

		$taxonomy_error = Sync_Service::sync_taxonomies( $branch_id, $original_id, $original->post_type );
		if ( $taxonomy_error ) {
			return $taxonomy_error;
		}

		clean_post_cache( $original_id );

		update_post_meta( $branch_id, '_wbfp_merged_at_gmt', current_time( 'mysql', true ) );
		update_post_meta( $branch_id, '_wbfp_merged_by_user_id', get_current_user_id() );

		// Keep the branch recoverable instead of permanently deleting it.
		wp_trash_post( $branch_id );

		/**
		 * Fires after a branch has been merged successfully.
		 *
		 * @param int  $branch_id   Branch post ID, now in Trash.
		 * @param int  $original_id Original post ID.
		 * @param bool $force       Whether this was an explicit force merge.
		 */
		do_action( 'wbfp_after_merge', $branch_id, $original_id, $force );

		return $original_id;
	}

	/**
	 * Move a branch to Trash without changing its original post.
	 *
	 * @param int $branch_id Branch post ID.
	 * @return true|\WP_Error
	 */
	public function discard( int $branch_id ) {
		if ( ! $this->branches->is_branch( $branch_id ) ) {
			return new \WP_Error(
				'wbfp_not_branch',
				__( 'This post is not a branch.', 'wp-branches-for-post' ),
				array( 'status' => 400 )
			);
		}

		if ( ! current_user_can( 'delete_post', $branch_id ) ) {
			return new \WP_Error(
				'wbfp_cannot_discard',
				__( 'You do not have permission to discard this branch.', 'wp-branches-for-post' ),
				array( 'status' => 403 )
			);
		}

		$result = wp_trash_post( $branch_id );
		if ( ! $result ) {
			return new \WP_Error(
				'wbfp_discard_failed',
				__( 'The branch could not be moved to Trash.', 'wp-branches-for-post' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}
}
