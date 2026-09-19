<?php
/**
 * Branch merge and discard operations.
 *
 * @package WPBranchesForPost
 */

namespace WP_Branches_For_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Merge_Service {
	private Branch_Service $branches;

	public function __construct( Branch_Service $branches ) {
		$this->branches = $branches;
	}

	public function merge( int $branch_id, bool $force = false ) {
		$branch = get_post( $branch_id );
		if ( ! $branch || ! $this->branches->is_branch( $branch_id ) ) {
			return new \WP_Error( 'wbfp_not_branch', __( 'This post is not a branch.', 'wp-branches-for-post' ), array( 'status' => 400 ) );
		}

		$original_id = $this->branches->get_original_id( $branch_id );
		$original    = get_post( $original_id );
		if ( ! $original ) {
			return new \WP_Error( 'wbfp_missing_original', __( 'The original post no longer exists.', 'wp-branches-for-post' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $branch_id ) || ! current_user_can( 'edit_post', $original_id ) ) {
			return new \WP_Error( 'wbfp_cannot_merge', __( 'You do not have permission to merge this branch.', 'wp-branches-for-post' ), array( 'status' => 403 ) );
		}

		$conflict = $this->branches->conflict_state( $branch_id );
		if ( ! $force && 'clean' !== $conflict ) {
			return new \WP_Error(
				'wbfp_merge_conflict',
				'unknown' === $conflict
					? __( 'This legacy branch has no baseline snapshot. Review the original before forcing the merge.', 'wp-branches-for-post' )
					: __( 'The original post changed after this branch was created. Review the changes before forcing the merge.', 'wp-branches-for-post' ),
				array( 'status' => 409, 'conflict' => $conflict )
			);
		}

		do_action( 'wbfp_before_merge', $branch_id, $original_id, $force );

		// Re-check immediately before the write so hooks or another editor cannot silently stale the initial check.
		if ( ! $force && 'clean' !== $this->branches->conflict_state( $branch_id ) ) {
			return new \WP_Error(
				'wbfp_merge_conflict',
				__( 'The original post changed before the merge could be applied. Review the latest version and try again.', 'wp-branches-for-post' ),
				array( 'status' => 409, 'conflict' => 'changed' )
			);
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
		wp_trash_post( $branch_id );

		do_action( 'wbfp_after_merge', $branch_id, $original_id, $force );

		return $original_id;
	}

	public function discard( int $branch_id ) {
		if ( ! $this->branches->is_branch( $branch_id ) ) {
			return new \WP_Error( 'wbfp_not_branch', __( 'This post is not a branch.', 'wp-branches-for-post' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'delete_post', $branch_id ) ) {
			return new \WP_Error( 'wbfp_cannot_discard', __( 'You do not have permission to discard this branch.', 'wp-branches-for-post' ), array( 'status' => 403 ) );
		}

		$result = wp_trash_post( $branch_id );
		if ( ! $result ) {
			return new \WP_Error( 'wbfp_discard_failed', __( 'The branch could not be moved to Trash.', 'wp-branches-for-post' ), array( 'status' => 500 ) );
		}
		return true;
	}
}
