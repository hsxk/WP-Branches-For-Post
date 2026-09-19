<?php
/**
 * REST API used by the block editor UI.
 *
 * @package WPBranchesForPost
 */

namespace WP_Branches_For_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the minimal authenticated REST surface used by the block editor.
 *
 * Every route defines a permission callback. Mutating service methods repeat
 * capability checks so REST permission checks are defense in depth rather than
 * the sole authorization boundary.
 */
final class REST_Controller {
	private const NAMESPACE = 'wbfp/v1';

	private Branch_Service $branches;
	private Merge_Service $merges;

	public function __construct( Branch_Service $branches, Merge_Service $merges ) {
		$this->branches = $branches;
		$this->merges   = $merges;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_read_status' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ) => (int) $value > 0,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/branches',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_branch' ),
				'permission_callback' => array( $this, 'can_create_branch' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ) => (int) $value > 0,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/branches/(?P<id>\d+)/merge',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'merge_branch' ),
				'permission_callback' => array( $this, 'can_merge_branch' ),
				'args'                => array(
					'id'    => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ) => (int) $value > 0,
					),
					'force' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/branches/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'discard_branch' ),
				'permission_callback' => array( $this, 'can_discard_branch' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ) => (int) $value > 0,
					),
				),
			)
		);
	}

	/**
	 * Authorize status reads.
	 *
	 * A branch status response contains information about its original post, so
	 * branch users must be able to edit both resources.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool
	 */
	public function can_read_status( \WP_REST_Request $request ): bool {
		$post_id = (int) $request['id'];
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		if ( ! $this->branches->is_branch( $post_id ) ) {
			return true;
		}

		$original_id = $this->branches->get_original_id( $post_id );

		return $original_id > 0 && current_user_can( 'edit_post', $original_id );
	}

	/**
	 * Authorize branch creation.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool
	 */
	public function can_create_branch( \WP_REST_Request $request ): bool {
		return $this->branches->can_create( (int) $request['id'] );
	}

	/**
	 * Authorize branch merge requests.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool
	 */
	public function can_merge_branch( \WP_REST_Request $request ): bool {
		$branch_id   = (int) $request['id'];
		$original_id = $this->branches->get_original_id( $branch_id );

		return $this->branches->is_branch( $branch_id )
			&& $original_id > 0
			&& current_user_can( 'edit_post', $branch_id )
			&& current_user_can( 'edit_post', $original_id );
	}

	/**
	 * Authorize moving a branch to Trash.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool
	 */
	public function can_discard_branch( \WP_REST_Request $request ): bool {
		$branch_id = (int) $request['id'];
		return $this->branches->is_branch( $branch_id ) && current_user_can( 'delete_post', $branch_id );
	}

	public function status( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->build_status( (int) $request['id'] ) );
	}

	public function create_branch( \WP_REST_Request $request ) {
		$branch_id = $this->branches->create( (int) $request['id'] );
		if ( is_wp_error( $branch_id ) ) {
			return $branch_id;
		}

		$response = array(
			'branch_id' => $branch_id,
			'edit_url'  => get_edit_post_link( $branch_id, 'raw' ),
			'status'    => $this->build_status( $branch_id ),
		);

		return new \WP_REST_Response( $response, 201 );
	}

	public function merge_branch( \WP_REST_Request $request ) {
		$original_id = $this->merges->merge( (int) $request['id'], (bool) $request->get_param( 'force' ) );
		if ( is_wp_error( $original_id ) ) {
			return $original_id;
		}

		return rest_ensure_response(
			array(
				'original_id' => $original_id,
				'edit_url'    => get_edit_post_link( $original_id, 'raw' ),
				'permalink'   => get_permalink( $original_id ),
			)
		);
	}

	public function discard_branch( \WP_REST_Request $request ) {
		$branch_id   = (int) $request['id'];
		$original_id = $this->branches->get_original_id( $branch_id );
		$result      = $this->merges->discard( $branch_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'discarded'   => true,
				'original_id' => $original_id,
				'edit_url'    => $original_id ? get_edit_post_link( $original_id, 'raw' ) : '',
			)
		);
	}

	private function build_status( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'type' => 'missing' );
		}

		if ( $this->branches->is_branch( $post_id ) ) {
			$original_id = $this->branches->get_original_id( $post_id );
			$original    = get_post( $original_id );
			$creator_id  = (int) get_post_meta( $post_id, Branch_Service::META_CREATOR_USER_ID, true );
			if ( ! $creator_id ) {
				$creator_id = (int) get_post_meta( $post_id, '_creator_user_id', true );
			}
			$creator = $creator_id ? get_userdata( $creator_id ) : false;

			return array(
				'type'              => 'branch',
				'post_id'           => $post_id,
				'original_id'       => $original_id,
				'original_title'    => $original ? get_the_title( $original_id ) : '',
				'original_url'      => $original ? get_permalink( $original_id ) : '',
				'original_edit_url' => $original ? get_edit_post_link( $original_id, 'raw' ) : '',
				'conflict'          => $this->branches->conflict_state( $post_id ),
				'legacy'            => '' === (string) get_post_meta( $post_id, Branch_Service::META_BASE_HASH, true ),
				'creator'           => $creator ? $creator->display_name : '',
				'created_gmt'       => (string) get_post_meta( $post_id, Branch_Service::META_CREATED_GMT, true ),
				'can_merge'         => $original && current_user_can( 'edit_post', $post_id ) && current_user_can( 'edit_post', $original_id ),
				'can_discard'       => current_user_can( 'delete_post', $post_id ),
			);
		}

		$branches = array();
		foreach ( $this->branches->get_branches( $post_id ) as $branch ) {
			// Do not expose branch details the current user cannot edit.
			if ( ! current_user_can( 'edit_post', $branch->ID ) ) {
				continue;
			}

			$branches[] = array(
				'id'       => $branch->ID,
				'title'    => get_the_title( $branch ),
				'edit_url' => get_edit_post_link( $branch->ID, 'raw' ),
				'modified' => $branch->post_modified_gmt,
				'conflict' => $this->branches->conflict_state( $branch->ID ),
			);
		}

		return array(
			'type'       => 'original',
			'post_id'    => $post_id,
			'can_create' => $this->branches->can_create( $post_id ),
			'branches'   => $branches,
		);
	}
}
