<?php
/**
 * Branch creation and branch state.
 *
 * @package WPBranchesForPost
 */

namespace WP_Branches_For_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Branch_Service {
	public const META_ORIGINAL_ID       = '_wbfp_original_post_id';
	public const META_CREATOR_USER_ID   = '_wbfp_creator_user_id';
	public const META_CREATED_GMT       = '_wbfp_created_gmt';
	public const META_BASE_MODIFIED_GMT = '_wbfp_base_modified_gmt';
	public const META_BASE_HASH         = '_wbfp_base_snapshot_hash';
	public const META_BASE_REVISION_ID  = '_wbfp_base_revision_id';

	public function get_original_id( int $post_id ): int {
		$original_id = (int) get_post_meta( $post_id, self::META_ORIGINAL_ID, true );
		if ( $original_id > 0 ) {
			return $original_id;
		}
		return (int) get_post_meta( $post_id, '_original_post_id', true );
	}

	public function is_branch( int $post_id ): bool {
		return $this->get_original_id( $post_id ) > 0;
	}

	public function can_create( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post || $this->is_branch( $post_id ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return false;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		$allowed_statuses = apply_filters( 'wbfp_branchable_post_statuses', array( 'publish', 'future', 'private' ), $post );
		return in_array( $post->post_status, $allowed_statuses, true );
	}

	public function create( int $original_id ) {
		if ( ! $this->can_create( $original_id ) ) {
			return new \WP_Error( 'wbfp_cannot_create', __( 'You cannot create a branch for this post.', 'wp-branches-for-post' ), array( 'status' => 403 ) );
		}

		$original = get_post( $original_id );
		if ( ! $original ) {
			return new \WP_Error( 'wbfp_missing_original', __( 'The original post does not exist.', 'wp-branches-for-post' ), array( 'status' => 404 ) );
		}

		$data = $original->to_array();
		unset( $data['ID'], $data['guid'], $data['post_name'], $data['post_modified'], $data['post_modified_gmt'], $data['comment_count'] );
		$data['post_status'] = 'draft';
		$data['post_name']   = '';
		$data                = apply_filters( 'wbfp_create_branch_post_data', $data, $original );

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

		$latest_revision = wp_get_post_revisions( $original_id, array( 'posts_per_page' => 1, 'fields' => 'ids' ) );
		$revision_id     = $latest_revision ? (int) reset( $latest_revision ) : 0;

		update_post_meta( $branch_id, self::META_ORIGINAL_ID, $original_id );
		update_post_meta( $branch_id, self::META_CREATOR_USER_ID, get_current_user_id() );
		update_post_meta( $branch_id, self::META_CREATED_GMT, current_time( 'mysql', true ) );
		update_post_meta( $branch_id, self::META_BASE_MODIFIED_GMT, $original->post_modified_gmt );
		update_post_meta( $branch_id, self::META_BASE_HASH, Sync_Service::snapshot_hash( $original_id ) );
		update_post_meta( $branch_id, self::META_BASE_REVISION_ID, $revision_id );

		do_action( 'wbfp_branch_created', $branch_id, $original_id );

		return $branch_id;
	}

	public function keep_branch_non_public( array $data, array $postarr ): array {
		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $post_id < 1 || ! $this->is_branch( $post_id ) ) {
			return $data;
		}

		if ( in_array( $data['post_status'] ?? '', array( 'trash', 'auto-draft' ), true ) ) {
			return $data;
		}

		if ( 'draft' !== ( $data['post_status'] ?? '' ) ) {
			$data['post_status'] = 'draft';
		}
		return $data;
	}

	public function conflict_state( int $branch_id ): string {
		$original_id = $this->get_original_id( $branch_id );
		if ( $original_id < 1 || ! get_post( $original_id ) ) {
			return 'missing';
		}

		$base_hash = (string) get_post_meta( $branch_id, self::META_BASE_HASH, true );
		if ( '' === $base_hash ) {
			return 'unknown';
		}

		return hash_equals( $base_hash, Sync_Service::snapshot_hash( $original_id ) ) ? 'clean' : 'changed';
	}

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
