<?php
/**
 * Branch creation, relationship metadata, and conflict state.
 *
 * Security invariants:
 * - A branch is always created as a non-public draft.
 * - A branch always uses the same post type as its original.
 * - Creating a branch requires edit capability for the original post.
 * - Conflict detection fails closed when the original cannot be snapshotted.
 *
 * @package WPBranchesForPost
 */

namespace WP_Branches_For_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the lifecycle state of branch posts.
 */
final class Branch_Service {
	public const META_ORIGINAL_ID       = '_wbfp_original_post_id';
	public const META_CREATOR_USER_ID   = '_wbfp_creator_user_id';
	public const META_CREATED_GMT       = '_wbfp_created_gmt';
	public const META_BASE_MODIFIED_GMT = '_wbfp_base_modified_gmt';
	public const META_BASE_HASH         = '_wbfp_base_snapshot_hash';
	public const META_BASE_REVISION_ID  = '_wbfp_base_revision_id';

	public const CONFLICT_CLEAN   = 'clean';
	public const CONFLICT_CHANGED = 'changed';
	public const CONFLICT_UNKNOWN = 'unknown';
	public const CONFLICT_MISSING = 'missing';

	/**
	 * Return the original post ID for a branch.
	 *
	 * The legacy key is intentionally retained so branches created by 1.x can
	 * still be opened and reviewed after upgrading to 2.0.
	 *
	 * @param int $post_id Candidate branch post ID.
	 * @return int Original post ID, or 0 when the post is not a branch.
	 */
	public function get_original_id( int $post_id ): int {
		$original_id = (int) get_post_meta( $post_id, self::META_ORIGINAL_ID, true );
		if ( $original_id > 0 ) {
			return $original_id;
		}

		return (int) get_post_meta( $post_id, '_original_post_id', true );
	}

	/**
	 * Determine whether a post is a branch.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function is_branch( int $post_id ): bool {
		return $this->get_original_id( $post_id ) > 0;
	}

	/**
	 * Determine whether the current user may create a branch for a post.
	 *
	 * Capability checks are the authorization boundary. Nonces used by admin
	 * actions protect against CSRF but are never treated as authorization.
	 *
	 * @param int $post_id Original post ID.
	 * @return bool
	 */
	public function can_create( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post || $this->is_branch( $post_id ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type || empty( $post_type->cap->create_posts ) || ! current_user_can( $post_type->cap->create_posts ) ) {
			return false;
		}

		/**
		 * Filters post statuses that may be branched.
		 *
		 * Keep the default list non-public/safely editable. Implementations that
		 * extend this filter are still subject to the edit_post capability check.
		 *
		 * @param string[] $allowed_statuses Allowed post statuses.
		 * @param \WP_Post $post             Original post.
		 */
		$allowed_statuses = apply_filters( 'wbfp_branchable_post_statuses', array( 'publish', 'future', 'private' ), $post );
		if ( ! is_array( $allowed_statuses ) ) {
			return false;
		}

		return in_array( $post->post_status, $allowed_statuses, true );
	}

	/**
	 * Create an isolated draft branch from an original post.
	 *
	 * A baseline hash is captured before copying and verified again after the
	 * copy. If the original changes during creation, the new branch is removed
	 * and the caller receives a conflict response instead of an inconsistent
	 * branch.
	 *
	 * @param int $original_id Original post ID.
	 * @return int|\WP_Error New branch ID on success.
	 */
	public function create( int $original_id ) {
		if ( ! $this->can_create( $original_id ) ) {
			return new \WP_Error(
				'wbfp_cannot_create',
				__( 'You cannot create a branch for this post.', 'wp-branches-for-post' ),
				array( 'status' => 403 )
			);
		}

		$original = get_post( $original_id );
		if ( ! $original ) {
			return new \WP_Error(
				'wbfp_missing_original',
				__( 'The original post does not exist.', 'wp-branches-for-post' ),
				array( 'status' => 404 )
			);
		}

		$base_hash = Sync_Service::snapshot_hash( $original_id );
		if ( '' === $base_hash ) {
			return new \WP_Error(
				'wbfp_snapshot_failed',
				__( 'The original post could not be snapshotted.', 'wp-branches-for-post' ),
				array( 'status' => 500 )
			);
		}

		$data = $original->to_array();
		unset( $data['ID'], $data['guid'], $data['post_name'], $data['post_modified'], $data['post_modified_gmt'], $data['comment_count'] );

		$data['post_status'] = 'draft';
		$data['post_name']   = '';

		/**
		 * Filters data used to create a branch post.
		 *
		 * The plugin re-applies its non-public and same-post-type invariants
		 * after this filter so third-party customizations cannot accidentally
		 * publish a branch or move it to another post type.
		 *
		 * @param array<string,mixed> $data     Branch post data.
		 * @param \WP_Post           $original Original post.
		 */
		$data = apply_filters( 'wbfp_create_branch_post_data', $data, $original );

		// Security invariant: a newly created branch must never be public.
		$data['post_status'] = 'draft';
		$data['post_name']   = '';
		$data['post_type']   = $original->post_type;

		$branch_id = wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $branch_id ) ) {
			return $branch_id;
		}

		Sync_Service::sync_meta( $original_id, $branch_id );

		$taxonomy_error = Sync_Service::sync_taxonomies( $original_id, $branch_id, $original->post_type );
		if ( $taxonomy_error ) {
			wp_delete_post( $branch_id, true );
			return $taxonomy_error;
		}

		$current_hash = Sync_Service::snapshot_hash( $original_id );
		if ( '' === $current_hash || ! hash_equals( $base_hash, $current_hash ) ) {
			wp_delete_post( $branch_id, true );

			return new \WP_Error(
				'wbfp_original_changed_during_branch_creation',
				__( 'The original changed while the branch was being created. Please try again so the branch starts from a consistent version.', 'wp-branches-for-post' ),
				array( 'status' => 409 )
			);
		}

		$latest_revision = wp_get_post_revisions(
			$original_id,
			array(
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$revision_id     = $latest_revision ? (int) reset( $latest_revision ) : 0;

		update_post_meta( $branch_id, self::META_ORIGINAL_ID, $original_id );
		update_post_meta( $branch_id, self::META_CREATOR_USER_ID, get_current_user_id() );
		update_post_meta( $branch_id, self::META_CREATED_GMT, current_time( 'mysql', true ) );
		update_post_meta( $branch_id, self::META_BASE_MODIFIED_GMT, $original->post_modified_gmt );
		update_post_meta( $branch_id, self::META_BASE_HASH, $base_hash );
		update_post_meta( $branch_id, self::META_BASE_REVISION_ID, $revision_id );

		/**
		 * Fires after a branch has been created successfully.
		 *
		 * @param int $branch_id   New branch post ID.
		 * @param int $original_id Original post ID.
		 */
		do_action( 'wbfp_branch_created', $branch_id, $original_id );

		return $branch_id;
	}

	/**
	 * Prevent an existing branch from being made public through normal saves.
	 *
	 * Trash and auto-draft transitions are allowed because they are internal
	 * lifecycle states. Every other attempted status is normalized to draft.
	 *
	 * @param array<string,mixed> $data    Sanitized post data.
	 * @param array<string,mixed> $postarr Raw post array supplied to WordPress.
	 * @return array<string,mixed>
	 */
	public function keep_branch_non_public( array $data, array $postarr ): array {
		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $post_id < 1 || ! $this->is_branch( $post_id ) ) {
			return $data;
		}

		if ( in_array( $data['post_status'] ?? '', array( 'trash', 'auto-draft' ), true ) ) {
			return $data;
		}

		$data['post_status'] = 'draft';

		return $data;
	}

	/**
	 * Compare a branch baseline with the current original post.
	 *
	 * Unknown is reserved for legacy 1.x branches that never stored a baseline.
	 * Snapshot failures are treated as changed (fail closed), so a normal merge
	 * cannot silently proceed when the original state cannot be verified.
	 *
	 * @param int $branch_id Branch post ID.
	 * @return string One of the CONFLICT_* constants.
	 */
	public function conflict_state( int $branch_id ): string {
		$original_id = $this->get_original_id( $branch_id );
		if ( $original_id < 1 || ! get_post( $original_id ) ) {
			return self::CONFLICT_MISSING;
		}

		$base_hash = (string) get_post_meta( $branch_id, self::META_BASE_HASH, true );
		if ( '' === $base_hash ) {
			return self::CONFLICT_UNKNOWN;
		}

		$current_hash = Sync_Service::snapshot_hash( $original_id );
		if ( '' === $current_hash ) {
			return self::CONFLICT_CHANGED;
		}

		return hash_equals( $base_hash, $current_hash ) ? self::CONFLICT_CLEAN : self::CONFLICT_CHANGED;
	}

	/**
	 * Query active branches for an original post.
	 *
	 * Both current and 1.x relationship meta are queried for upgrade
	 * compatibility. Callers that expose results to users must apply their own
	 * per-branch capability checks before returning branch details.
	 *
	 * @param int $original_id Original post ID.
	 * @return \WP_Post[]
	 */
	public function get_branches( int $original_id ): array {
		$original = get_post( $original_id );
		if ( ! $original ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'      => $original->post_type,
				'post_status'    => array( 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 50,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- OR across current and legacy 1.x relationship keys is required for branch lookup; no non-meta index is available.
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => self::META_ORIGINAL_ID,
						'value'   => $original_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_original_post_id',
						'value'   => $original_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		return $query->posts;
	}
}
