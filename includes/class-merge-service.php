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

/**
 * Applies reviewed branch content back to the original post.
 */
final class Merge_Service {
	/**
	 * Branch relationship service.
	 *
	 * @var Branch_Service
	 */
	private Branch_Service $branches;

	/**
	 * @param Branch_Service $branches Branch lifecycle service.
	 */
	public function __construct( Branch_Service $branches ) {
		$this->branches = $branches;
	}

	/**
	 * Merge a branch into its original post.
	 *
	 * Version-2.1 branches use a three-way merge: branch-only changes are applied,
	 * original-only changes are preserved, and overlapping changes are blocked
	 * unless the user explicitly force-merges. Any write failure attempts to
	 * restore the complete pre-merge original state.
	 *
	 * @param int  $branch_id Branch post ID.
	 * @param bool $force     Whether to explicitly prefer branch values on conflicts.
	 * @return int|\WP_Error Original post ID on success.
	 */
	public function merge( int $branch_id, bool $force = false ) {
		$validated = $this->validate_relationship( $branch_id );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$branch      = $validated['branch'];
		$original    = $validated['original'];
		$original_id = $validated['original_id'];

		if ( $force && ! $this->branches->can_force_merge( $branch_id, $original_id ) ) {
			return new \WP_Error(
				'wbfp_cannot_force_merge',
				__( 'You do not have permission to force this merge.', 'wp-branches-for-post' ),
				array( 'status' => 403 )
			);
		}

		$analysis = $this->branches->analyze_branch( $branch_id );
		if ( ! $force && in_array( $analysis['state'] ?? '', array( Branch_Service::CONFLICT_CONFLICT, Branch_Service::CONFLICT_CHANGED, Branch_Service::CONFLICT_UNKNOWN, Branch_Service::CONFLICT_MISSING ), true ) ) {
			return $this->conflict_error( $analysis );
		}

		/**
		 * Fires before a branch merge is applied.
		 *
		 * @param int  $branch_id   Branch post ID.
		 * @param int  $original_id Original post ID.
		 * @param bool $force       Whether this is an explicit force merge.
		 */
		do_action( 'wbfp_before_merge', $branch_id, $original_id, $force );

		$validated = $this->validate_relationship( $branch_id, $original_id );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$branch   = $validated['branch'];
		$original = $validated['original'];

		$analysis = $this->branches->analyze_branch( $branch_id );
		if ( ! $force && in_array( $analysis['state'] ?? '', array( Branch_Service::CONFLICT_CONFLICT, Branch_Service::CONFLICT_CHANGED, Branch_Service::CONFLICT_UNKNOWN, Branch_Service::CONFLICT_MISSING ), true ) ) {
			return $this->conflict_error( $analysis );
		}

		$rollback        = Sync_Service::snapshot( $original_id );
		$branch_snapshot = Sync_Service::snapshot( $branch_id );
		if ( ! $rollback || ! $branch_snapshot ) {
			return new \WP_Error(
				'wbfp_snapshot_failed',
				__( 'The branch or original could not be snapshotted before merging.', 'wp-branches-for-post' ),
				array( 'status' => 500 )
			);
		}

		if ( empty( $analysis['legacy'] ) && ! empty( $analysis['base'] ) && ! empty( $analysis['original'] ) && ! empty( $analysis['branch'] ) ) {
			$target = Sync_Service::merged_snapshot( $analysis['base'], $analysis['original'], $analysis['branch'], $force );
			if ( is_wp_error( $target ) ) {
				$target->add_data( array( 'status' => 409 ) );
				return $target;
			}
		} else {
			$target          = $rollback;
			$target['merge'] = $branch_snapshot['merge'];
		}

		$apply_error = Sync_Service::apply_merge_snapshot( $target, $original_id, $original->post_type );
		if ( $apply_error ) {
			$rollback_error = Sync_Service::apply_merge_snapshot( $rollback, $original_id, $original->post_type );
			if ( $rollback_error ) {
				return new \WP_Error(
					'wbfp_merge_rollback_failed',
					__( 'The merge failed and the original could not be fully restored automatically.', 'wp-branches-for-post' ),
					array(
						'status'         => 500,
						'merge_error'    => $apply_error->get_error_code(),
						'rollback_error' => $rollback_error->get_error_code(),
					)
				);
			}
			return new \WP_Error(
				'wbfp_merge_rolled_back',
				__( 'The merge could not be completed. The original post was restored to its pre-merge state.', 'wp-branches-for-post' ),
				array(
					'status'     => 500,
					'cause'      => $apply_error->get_error_code(),
					'cause_data' => $apply_error->get_error_data(),
				)
			);
		}

		update_post_meta( $branch_id, '_wbfp_merged_at_gmt', current_time( 'mysql', true ) );
		update_post_meta( $branch_id, '_wbfp_merged_by_user_id', get_current_user_id() );
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

	/**
	 * Validate branch/original relationship and authorization.
	 *
	 * @param int      $branch_id            Branch post ID.
	 * @param int|null $expected_original_id Optional expected original ID after hooks.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function validate_relationship( int $branch_id, ?int $expected_original_id = null ) {
		$branch = get_post( $branch_id );
		if ( ! $branch || ! $this->branches->is_branch( $branch_id ) || in_array( $branch->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return new \WP_Error( 'wbfp_not_branch', __( 'This post is not an active branch.', 'wp-branches-for-post' ), array( 'status' => 400 ) );
		}

		$original_id = $this->branches->get_original_id( $branch_id );
		if ( null !== $expected_original_id && $expected_original_id !== $original_id ) {
			return new \WP_Error( 'wbfp_relationship_changed', __( 'The branch relationship changed before the merge could be applied.', 'wp-branches-for-post' ), array( 'status' => 409 ) );
		}

		$original = get_post( $original_id );
		if ( ! $original ) {
			return new \WP_Error( 'wbfp_missing_original', __( 'The original post no longer exists.', 'wp-branches-for-post' ), array( 'status' => 404 ) );
		}
		if ( $branch->post_type !== $original->post_type ) {
			return new \WP_Error( 'wbfp_post_type_mismatch', __( 'The branch and original no longer use the same post type.', 'wp-branches-for-post' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'edit_post', $branch_id ) || ! current_user_can( 'edit_post', $original_id ) ) {
			return new \WP_Error( 'wbfp_cannot_merge', __( 'You do not have permission to merge this branch.', 'wp-branches-for-post' ), array( 'status' => 403 ) );
		}

		return array(
			'branch'      => $branch,
			'original'    => $original,
			'original_id' => $original_id,
		);
	}

	/**
	 * Convert analysis state to an actionable merge error.
	 *
	 * @param array<string,mixed> $analysis Analysis result.
	 * @return \WP_Error
	 */
	private function conflict_error( array $analysis ): \WP_Error {
		$state = (string) ( $analysis['state'] ?? Branch_Service::CONFLICT_CHANGED );
		if ( Branch_Service::CONFLICT_UNKNOWN === $state ) {
			$message = __( 'This older branch has no three-way baseline. Review the original before forcing the merge.', 'wp-branches-for-post' );
		} elseif ( Branch_Service::CONFLICT_MISSING === $state ) {
			$message = __( 'The original post is unavailable, so this branch cannot be merged.', 'wp-branches-for-post' );
		} else {
			$message = __( 'The branch and original contain overlapping changes. Review the detected conflicts before forcing the merge.', 'wp-branches-for-post' );
		}

		return new \WP_Error(
			'wbfp_merge_conflict',
			$message,
			array(
				'status'    => 409,
				'conflict'  => $state,
				'conflicts' => $analysis['analysis']['conflicts'] ?? array(),
			)
		);
	}
}
