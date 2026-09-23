<?php
/**
 * Branch creation, relationship metadata, review state, and rebase support.
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
	public const META_BASE_SNAPSHOT     = '_wbfp_base_snapshot';

	public const CONFLICT_CLEAN         = 'clean';
	public const CONFLICT_INFORMATIONAL = 'informational';
	public const CONFLICT_REBASE        = 'rebase_available';
	public const CONFLICT_CHANGED       = 'changed';
	public const CONFLICT_CONFLICT      = 'conflict';
	public const CONFLICT_UNKNOWN       = 'unknown';
	public const CONFLICT_MISSING       = 'missing';

	/**
	 * Return the original post ID for a branch.
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

		$base_snapshot = Sync_Service::snapshot( $original_id );
		$base_hash     = $base_snapshot ? Sync_Service::snapshot_hash_from_payload( $base_snapshot ) : '';
		if ( ! $base_snapshot || '' === $base_hash ) {
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
		 * @param array<string,mixed> $data     Branch post data.
		 * @param \WP_Post           $original Original post.
		 */
		$data = apply_filters( 'wbfp_create_branch_post_data', $data, $original );

		$data['post_status'] = 'draft';
		$data['post_name']   = '';
		$data['post_type']   = $original->post_type;

		$branch_id = wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $branch_id ) ) {
			return $branch_id;
		}

		$meta_error = Sync_Service::sync_meta( $original_id, $branch_id );
		if ( $meta_error ) {
			wp_delete_post( $branch_id, true );
			return $meta_error;
		}

		$taxonomy_error = Sync_Service::sync_taxonomies( $original_id, $branch_id, $original->post_type );
		if ( $taxonomy_error ) {
			wp_delete_post( $branch_id, true );
			return $taxonomy_error;
		}

		$current_snapshot = Sync_Service::snapshot( $original_id );
		$current_hash     = $current_snapshot ? Sync_Service::snapshot_hash_from_payload( $current_snapshot ) : '';
		if ( '' === $current_hash || ! hash_equals( $base_hash, $current_hash ) ) {
			wp_delete_post( $branch_id, true );
			return new \WP_Error(
				'wbfp_original_changed_during_branch_creation',
				__( 'The original changed while the branch was being created. Please try again so the branch starts from a consistent version.', 'wp-branches-for-post' ),
				array( 'status' => 409 )
			);
		}

		update_post_meta( $branch_id, self::META_ORIGINAL_ID, $original_id );
		update_post_meta( $branch_id, self::META_CREATOR_USER_ID, get_current_user_id() );
		update_post_meta( $branch_id, self::META_CREATED_GMT, current_time( 'mysql', true ) );
		if ( $original_id !== $this->get_original_id( $branch_id ) ) {
			wp_delete_post( $branch_id, true );
			return new \WP_Error(
				'wbfp_relationship_store_failed',
				__( 'The branch relationship metadata could not be stored.', 'wp-branches-for-post' ),
				array( 'status' => 500 )
			);
		}

		$base_error = $this->store_base_snapshot( $branch_id, $original_id, $base_snapshot );
		if ( $base_error ) {
			wp_delete_post( $branch_id, true );
			return $base_error;
		}

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
	 * Return a version-2.1 base snapshot, if available.
	 *
	 * @param int $branch_id Branch post ID.
	 * @return array<string,mixed>|null
	 */
	public function get_base_snapshot( int $branch_id ): ?array {
		$base = get_post_meta( $branch_id, self::META_BASE_SNAPSHOT, true );
		return is_array( $base ) && isset( $base['merge'] ) ? $base : null;
	}

	/**
	 * Analyze current branch state using a three-way comparison when possible.
	 *
	 * @param int                      $branch_id          Branch post ID.
	 * @param array<string,mixed>|null $original_snapshot Optional already-captured original snapshot.
	 * @return array<string,mixed>
	 */
	public function analyze_branch( int $branch_id, ?array $original_snapshot = null ): array {
		$branch = get_post( $branch_id );
		if ( ! $branch || ! $this->is_branch( $branch_id ) || in_array( $branch->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return array(
				'state'  => self::CONFLICT_MISSING,
				'legacy' => false,
			);
		}

		$original_id = $this->get_original_id( $branch_id );
		$original    = get_post( $original_id );
		if ( ! $original || $branch->post_type !== $original->post_type ) {
			return array(
				'state'  => self::CONFLICT_MISSING,
				'legacy' => false,
			);
		}

		$base = $this->get_base_snapshot( $branch_id );
		if ( ! $base ) {
			$base_hash = (string) get_post_meta( $branch_id, self::META_BASE_HASH, true );
			if ( '' === $base_hash ) {
				return array(
					'state'  => self::CONFLICT_UNKNOWN,
					'legacy' => true,
				);
			}
			$current_hash = Sync_Service::legacy_snapshot_hash( $original_id );
			return array(
				'state'  => '' !== $current_hash && hash_equals( $base_hash, $current_hash ) ? self::CONFLICT_CLEAN : self::CONFLICT_CHANGED,
				'legacy' => true,
			);
		}

		$original_snapshot = $original_snapshot ?? Sync_Service::snapshot( $original_id );
		$branch_snapshot   = Sync_Service::snapshot( $branch_id );
		if ( ! $original_snapshot || ! $branch_snapshot ) {
			return array(
				'state'  => self::CONFLICT_CHANGED,
				'legacy' => false,
			);
		}

		$analysis = Sync_Service::three_way_analysis( $base, $original_snapshot, $branch_snapshot );
		if ( ! empty( $analysis['conflicts'] ) ) {
			$state = self::CONFLICT_CONFLICT;
		} elseif ( ! empty( $analysis['original_changes'] ) ) {
			$state = self::CONFLICT_REBASE;
		} elseif ( ! empty( $analysis['informational_changes'] ) ) {
			$state = self::CONFLICT_INFORMATIONAL;
		} else {
			$state = self::CONFLICT_CLEAN;
		}

		return array(
			'state'    => $state,
			'legacy'   => false,
			'base'     => $base,
			'original' => $original_snapshot,
			'branch'   => $branch_snapshot,
			'analysis' => $analysis,
		);
	}

	/**
	 * Return the current conflict/review state label.
	 *
	 * @param int $branch_id Branch post ID.
	 * @return string
	 */
	public function conflict_state( int $branch_id ): string {
		$analysis = $this->analyze_branch( $branch_id );
		return (string) ( $analysis['state'] ?? self::CONFLICT_CHANGED );
	}

	/**
	 * Rebase non-conflicting original changes into a branch.
	 *
	 * @param int $branch_id Branch post ID.
	 * @return int|\WP_Error Branch post ID on success.
	 */
	public function rebase( int $branch_id ) {
		$branch = get_post( $branch_id );
		if ( ! $branch || ! $this->is_branch( $branch_id ) || in_array( $branch->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return new \WP_Error( 'wbfp_not_branch', __( 'This post is not an active branch.', 'wp-branches-for-post' ), array( 'status' => 400 ) );
		}

		$original_id = $this->get_original_id( $branch_id );
		$original    = get_post( $original_id );
		if ( ! $original || $original->post_type !== $branch->post_type ) {
			return new \WP_Error( 'wbfp_missing_original', __( 'The original post is unavailable.', 'wp-branches-for-post' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $branch_id ) || ! current_user_can( 'edit_post', $original_id ) ) {
			return new \WP_Error( 'wbfp_cannot_rebase', __( 'You do not have permission to update this branch.', 'wp-branches-for-post' ), array( 'status' => 403 ) );
		}

		$analysis = $this->analyze_branch( $branch_id );
		if ( ! empty( $analysis['legacy'] ) || empty( $analysis['base'] ) || empty( $analysis['original'] ) || empty( $analysis['branch'] ) ) {
			return new \WP_Error( 'wbfp_rebase_unavailable', __( 'This older branch cannot be automatically updated from the original.', 'wp-branches-for-post' ), array( 'status' => 409 ) );
		}

		$rebased = Sync_Service::rebased_snapshot( $analysis['base'], $analysis['original'], $analysis['branch'] );
		if ( is_wp_error( $rebased ) ) {
			$rebased->add_data( array( 'status' => 409 ) );
			return $rebased;
		}

		$rollback          = Sync_Service::snapshot( $branch_id );
		$baseline_rollback = $this->baseline_metadata_state( $branch_id );
		$current_original  = Sync_Service::snapshot( $original_id );
		if ( ! $rollback || ! $current_original ) {
			return new \WP_Error(
				'wbfp_rebase_snapshot_failed',
				__( 'The branch could not be snapshotted before updating it from the original.', 'wp-branches-for-post' ),
				array( 'status' => 500 )
			);
		}

		$reviewed_original_hash = Sync_Service::state_hash_from_payload( $analysis['original'] );
		$reviewed_branch_hash   = Sync_Service::state_hash_from_payload( $analysis['branch'] );
		$current_original_hash  = Sync_Service::state_hash_from_payload( $current_original );
		$current_branch_hash    = Sync_Service::state_hash_from_payload( $rollback );
		if (
			'' === $reviewed_original_hash
			|| '' === $reviewed_branch_hash
			|| '' === $current_original_hash
			|| '' === $current_branch_hash
			|| $original_id !== $this->get_original_id( $branch_id )
			|| ! hash_equals( $reviewed_original_hash, $current_original_hash )
			|| ! hash_equals( $reviewed_branch_hash, $current_branch_hash )
		) {
			return new \WP_Error(
				'wbfp_rebase_state_changed',
				__( 'The branch or original changed while the branch was being updated. Review the latest changes and try again.', 'wp-branches-for-post' ),
				array( 'status' => 409 )
			);
		}

		$apply_error = Sync_Service::apply_merge_snapshot( $rebased, $branch_id, $branch->post_type );
		if ( $apply_error ) {
			$rollback_error = Sync_Service::apply_merge_snapshot( $rollback, $branch_id, $branch->post_type );
			if ( $rollback_error ) {
				return new \WP_Error(
					'wbfp_rebase_rollback_failed',
					__( 'Updating the branch failed and its previous state could not be fully restored automatically.', 'wp-branches-for-post' ),
					array(
						'status'         => 500,
						'rebase_error'   => $apply_error->get_error_code(),
						'rollback_error' => $rollback_error->get_error_code(),
					)
				);
			}

			return new \WP_Error(
				'wbfp_rebase_rolled_back',
				__( 'The branch could not be updated. Its previous state was restored.', 'wp-branches-for-post' ),
				array(
					'status' => 500,
					'cause'  => $apply_error->get_error_code(),
				)
			);
		}

		$post_apply_original      = Sync_Service::snapshot( $original_id );
		$post_apply_original_hash = $post_apply_original ? Sync_Service::state_hash_from_payload( $post_apply_original ) : '';
		$post_apply_branch        = get_post( $branch_id );
		if (
			'' === $post_apply_original_hash
			|| $original_id !== $this->get_original_id( $branch_id )
			|| ! $post_apply_branch
			|| in_array( $post_apply_branch->post_status, array( 'trash', 'auto-draft' ), true )
			|| ! hash_equals( $reviewed_original_hash, $post_apply_original_hash )
		) {
			$rollback_error = Sync_Service::apply_merge_snapshot( $rollback, $branch_id, $branch->post_type );
			if ( $rollback_error ) {
				return new \WP_Error(
					'wbfp_rebase_rollback_failed',
					__( 'Updating the branch failed and its previous state could not be fully restored automatically.', 'wp-branches-for-post' ),
					array(
						'status'         => 500,
						'rebase_error'   => 'wbfp_rebase_state_changed',
						'rollback_error' => $rollback_error->get_error_code(),
					)
				);
			}

			return new \WP_Error(
				'wbfp_rebase_state_changed',
				__( 'The branch or original changed while the branch was being updated. Review the latest changes and try again.', 'wp-branches-for-post' ),
				array( 'status' => 409 )
			);
		}

		$base_error = $this->store_base_snapshot( $branch_id, $original_id, $analysis['original'] );
		if ( $base_error ) {
			$rollback_error    = Sync_Service::apply_merge_snapshot( $rollback, $branch_id, $branch->post_type );
			$baseline_restored = $this->restore_baseline_metadata( $branch_id, $baseline_rollback );
			if ( $rollback_error || ! $baseline_restored ) {
				return new \WP_Error(
					'wbfp_rebase_rollback_failed',
					__( 'Updating the branch failed and its previous state could not be fully restored automatically.', 'wp-branches-for-post' ),
					array(
						'status'           => 500,
						'rebase_error'     => $base_error->get_error_code(),
						'rollback_error'   => $rollback_error ? $rollback_error->get_error_code() : '',
						'baseline_restore' => $baseline_restored,
					)
				);
			}

			return new \WP_Error(
				'wbfp_rebase_baseline_rolled_back',
				__( 'The branch baseline could not be updated. Its previous state was restored.', 'wp-branches-for-post' ),
				array(
					'status' => 500,
					'cause'  => $base_error->get_error_code(),
				)
			);
		}

		return $branch_id;
	}

	/**
	 * Determine whether force merge is allowed for the current user.
	 *
	 * @param int $branch_id   Branch post ID.
	 * @param int $original_id Original post ID.
	 * @return bool
	 */
	public function can_force_merge( int $branch_id, int $original_id ): bool {
		$allowed = current_user_can( 'edit_post', $branch_id ) && current_user_can( 'edit_post', $original_id );
		/**
		 * Filters whether the current user may force a conflicting merge.
		 *
		 * @param bool $allowed     Default permission result.
		 * @param int  $branch_id   Branch post ID.
		 * @param int  $original_id Original post ID.
		 * @param int  $user_id     Current user ID.
		 */
		return (bool) apply_filters( 'wbfp_can_force_merge', $allowed, $branch_id, $original_id, get_current_user_id() );
	}

	/**
	 * Query active branches for an original post.
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
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for current + legacy branch relationships.
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

	/**
	 * Store baseline metadata after create/rebase.
	 *
	 * @param int                 $branch_id   Branch post ID.
	 * @param int                 $original_id Original post ID.
	 * @param array<string,mixed> $snapshot    Original snapshot.
	 * @return \WP_Error|null
	 */
	private function store_base_snapshot( int $branch_id, int $original_id, array $snapshot ): ?\WP_Error {
		$original  = get_post( $original_id );
		$base_hash = Sync_Service::snapshot_hash_from_payload( $snapshot );
		if ( '' === $base_hash ) {
			return new \WP_Error(
				'wbfp_base_snapshot_store_failed',
				__( 'The branch baseline snapshot could not be stored.', 'wp-branches-for-post' ),
				array( 'status' => 500 )
			);
		}

		update_post_meta( $branch_id, self::META_BASE_SNAPSHOT, $snapshot );
		update_post_meta( $branch_id, self::META_BASE_HASH, $base_hash );
		update_post_meta( $branch_id, self::META_BASE_MODIFIED_GMT, $original ? $original->post_modified_gmt : '' );

		$latest_revision = wp_get_post_revisions(
			$original_id,
			array(
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$revision_id     = $latest_revision ? (int) reset( $latest_revision ) : 0;
		update_post_meta( $branch_id, self::META_BASE_REVISION_ID, $revision_id );

		$stored_snapshot   = $this->get_base_snapshot( $branch_id );
		$stored_hash       = (string) get_post_meta( $branch_id, self::META_BASE_HASH, true );
		$stored_modified   = (string) get_post_meta( $branch_id, self::META_BASE_MODIFIED_GMT, true );
		$stored_revision   = (int) get_post_meta( $branch_id, self::META_BASE_REVISION_ID, true );
		$expected_modified = $original ? (string) $original->post_modified_gmt : '';

		if (
			! $stored_snapshot
			|| ! hash_equals( Sync_Service::state_hash_from_payload( $snapshot ), Sync_Service::state_hash_from_payload( $stored_snapshot ) )
			|| ! hash_equals( $base_hash, $stored_hash )
			|| $expected_modified !== $stored_modified
			|| $revision_id !== $stored_revision
		) {
			return new \WP_Error(
				'wbfp_base_snapshot_store_failed',
				__( 'The branch baseline snapshot could not be stored.', 'wp-branches-for-post' ),
				array( 'status' => 500 )
			);
		}

		return null;
	}

	/**
	 * Capture baseline-control metadata so a failed rebase can restore it.
	 *
	 * @param int $branch_id Branch post ID.
	 * @return array<string,array{exists:bool,value:mixed}>
	 */
	private function baseline_metadata_state( int $branch_id ): array {
		$result = array();
		foreach ( array( self::META_BASE_SNAPSHOT, self::META_BASE_HASH, self::META_BASE_MODIFIED_GMT, self::META_BASE_REVISION_ID ) as $key ) {
			$result[ $key ] = array(
				'exists' => metadata_exists( 'post', $branch_id, $key ),
				'value'  => get_post_meta( $branch_id, $key, true ),
			);
		}

		return $result;
	}

	/**
	 * Restore baseline-control metadata after a failed rebase.
	 *
	 * @param int                                          $branch_id Branch post ID.
	 * @param array<string,array{exists:bool,value:mixed}> $state     Previous metadata state.
	 * @return bool
	 */
	private function restore_baseline_metadata( int $branch_id, array $state ): bool {
		foreach ( $state as $key => $entry ) {
			if ( ! empty( $entry['exists'] ) ) {
				update_post_meta( $branch_id, $key, $entry['value'] );
			} else {
				delete_post_meta( $branch_id, $key );
			}
		}

		foreach ( $state as $key => $entry ) {
			$exists = metadata_exists( 'post', $branch_id, $key );
			if ( ! empty( $entry['exists'] ) ) {
				if ( ! $exists || get_post_meta( $branch_id, $key, true ) !== $entry['value'] ) {
					return false;
				}
			} elseif ( $exists ) {
				return false;
			}
		}

		return true;
	}
}
