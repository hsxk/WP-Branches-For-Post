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
 * Exposes the authenticated REST surface used by the editor UI.
 */
final class REST_Controller {
	private const NAMESPACE = 'wbfp/v1';

	/**
	 * Branch lifecycle service.
	 *
	 * @var Branch_Service
	 */
	private Branch_Service $branches;

	/**
	 * Merge service.
	 *
	 * @var Merge_Service
	 */
	private Merge_Service $merges;

	/**
	 * @param Branch_Service $branches Branch lifecycle service.
	 * @param Merge_Service  $merges   Merge service.
	 */
	public function __construct( Branch_Service $branches, Merge_Service $merges ) {
		$this->branches = $branches;
		$this->merges   = $merges;
	}

	/**
	 * Register editor REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_read_status' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/branches',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_branch' ),
				'permission_callback' => array( $this, 'can_create_branch' ),
				'args'                => array( 'id' => $this->id_arg() ),
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
					'id'    => $this->id_arg(),
					'force' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/branches/(?P<id>\d+)/rebase',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rebase_branch' ),
				'permission_callback' => array( $this, 'can_merge_branch' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/branches/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'discard_branch' ),
				'permission_callback' => array( $this, 'can_discard_branch' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);
	}

	/**
	 * Authorize status reads.
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
		if ( $original_id < 1 ) {
			return false;
		}

		return ! get_post( $original_id ) || current_user_can( 'edit_post', $original_id );
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
	 * Authorize branch merge/rebase requests.
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
	 * Authorize branch discard requests.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool
	 */
	public function can_discard_branch( \WP_REST_Request $request ): bool {
		$branch_id = (int) $request['id'];
		return $this->branches->is_branch( $branch_id ) && current_user_can( 'delete_post', $branch_id );
	}

	/**
	 * Return editor status.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function status( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->build_status( (int) $request['id'] ) );
	}

	/**
	 * Create a branch.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_branch( \WP_REST_Request $request ) {
		$branch_id = $this->branches->create( (int) $request['id'] );
		if ( is_wp_error( $branch_id ) ) {
			return $branch_id;
		}

		return new \WP_REST_Response(
			array(
				'branch_id' => $branch_id,
				'edit_url'  => get_edit_post_link( $branch_id, 'raw' ),
				'status'    => $this->build_status( $branch_id ),
			),
			201
		);
	}

	/**
	 * Merge a branch.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
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

	/**
	 * Update a branch from non-conflicting original changes.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rebase_branch( \WP_REST_Request $request ) {
		$branch_id = $this->branches->rebase( (int) $request['id'] );
		if ( is_wp_error( $branch_id ) ) {
			return $branch_id;
		}

		return rest_ensure_response(
			array(
				'branch_id' => $branch_id,
				'status'    => $this->build_status( $branch_id ),
			)
		);
	}

	/**
	 * Discard a branch.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
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

	/**
	 * Build permission-filtered editor status.
	 *
	 * @param int $post_id Post or branch ID.
	 * @return array<string,mixed>
	 */
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
			$creator  = $creator_id ? get_userdata( $creator_id ) : false;
			$analysis = $this->branches->analyze_branch( $post_id );
			$review   = isset( $analysis['analysis'] ) && is_array( $analysis['analysis'] )
				? $analysis['analysis']
				: array(
					'original_changes'      => array(),
					'branch_changes'        => array(),
					'conflicts'             => array(),
					'informational_changes' => array(),
					'can_rebase'            => false,
				);

			$pattern_refs = Sync_Service::synced_pattern_refs( $post_id );
			$review_values = $this->review_values( $analysis );

			return array(
				'type'                 => 'branch',
				'post_id'              => $post_id,
				'original_id'          => $original_id,
				'original_title'       => $original ? get_the_title( $original_id ) : '',
				'original_url'         => $original ? get_permalink( $original_id ) : '',
				'original_edit_url'    => $original ? get_edit_post_link( $original_id, 'raw' ) : '',
				'original_preview_url' => $original ? get_permalink( $original_id ) : '',
				'branch_preview_url'   => get_preview_post_link( $post ),
				'conflict'             => $analysis['state'] ?? Branch_Service::CONFLICT_CHANGED,
				'legacy'               => ! empty( $analysis['legacy'] ),
				'creator'              => $creator ? $creator->display_name : '',
				'created_gmt'          => (string) get_post_meta( $post_id, Branch_Service::META_CREATED_GMT, true ),
				'can_merge'            => $original && current_user_can( 'edit_post', $post_id ) && current_user_can( 'edit_post', $original_id ),
				'can_force_merge'      => $original ? $this->branches->can_force_merge( $post_id, $original_id ) : false,
				'can_rebase'           => $original && Branch_Service::CONFLICT_REBASE === ( $analysis['state'] ?? '' ) && empty( $analysis['legacy'] ),
				'can_discard'          => current_user_can( 'delete_post', $post_id ),
				'review'               => $review,
				'review_values'        => $review_values,
				'synced_pattern_refs'  => $pattern_refs,
				'synced_pattern_count' => count( $pattern_refs ),
			);
		}

		$branches          = array();
		$original_snapshot = Sync_Service::snapshot( $post_id );
		foreach ( $this->branches->get_branches( $post_id ) as $branch ) {
			if ( ! current_user_can( 'edit_post', $branch->ID ) ) {
				continue;
			}

			$analysis   = $this->branches->analyze_branch( $branch->ID, $original_snapshot );
			$creator_id = (int) get_post_meta( $branch->ID, Branch_Service::META_CREATOR_USER_ID, true );
			$creator    = $creator_id ? get_userdata( $creator_id ) : false;
			$review     = isset( $analysis['analysis'] ) && is_array( $analysis['analysis'] ) ? $analysis['analysis'] : array();

			$branches[] = array(
				'id'             => $branch->ID,
				'title'          => get_the_title( $branch ),
				'edit_url'       => get_edit_post_link( $branch->ID, 'raw' ),
				'modified'       => $branch->post_modified_gmt,
				'creator'        => $creator ? $creator->display_name : '',
				'conflict'       => $analysis['state'] ?? Branch_Service::CONFLICT_CHANGED,
				'branch_changes' => count( $review['branch_changes'] ?? array() ),
				'conflicts'      => count( $review['conflicts'] ?? array() ),
			);
		}

		return array(
			'type'       => 'original',
			'post_id'    => $post_id,
			'can_create' => $this->branches->can_create( $post_id ),
			'branches'   => $branches,
		);
	}

	/**
	 * Return compact Base/Original/Branch values for human review.
	 *
	 * Only core editorial text fields are returned. Arbitrary post meta values
	 * are intentionally not exposed through this convenience payload.
	 *
	 * @param array<string,mixed> $analysis Branch analysis.
	 * @return array<string,array<string,string>>
	 */
	private function review_values( array $analysis ): array {
		if ( ! empty( $analysis['legacy'] ) || empty( $analysis['base'] ) || empty( $analysis['original'] ) || empty( $analysis['branch'] ) ) {
			return array();
		}

		$review = isset( $analysis['analysis'] ) && is_array( $analysis['analysis'] ) ? $analysis['analysis'] : array();
		$paths  = array_unique(
			array_merge(
				$review['branch_changes'] ?? array(),
				$review['original_changes'] ?? array(),
				$review['conflicts'] ?? array()
			)
		);
		$fields = array( 'post_title', 'post_excerpt', 'post_content' );
		$result = array();

		foreach ( $fields as $field ) {
			$path = 'post.' . $field;
			if ( ! in_array( $path, $paths, true ) ) {
				continue;
			}

			$result[ $field ] = array(
				'base'     => $this->compact_review_text( $analysis['base']['merge']['post'][ $field ] ?? '' ),
				'original' => $this->compact_review_text( $analysis['original']['merge']['post'][ $field ] ?? '' ),
				'branch'   => $this->compact_review_text( $analysis['branch']['merge']['post'][ $field ] ?? '' ),
			);
		}

		return $result;
	}

	/**
	 * Convert editorial text to a bounded plain-text review excerpt.
	 *
	 * @param mixed $value Raw field value.
	 * @return string
	 */
	private function compact_review_text( $value ): string {
		$text = trim( wp_strip_all_tags( (string) $value, true ) );
		return wp_html_excerpt( $text, 1200, strlen( $text ) > 1200 ? '…' : '' );
	}

	/**
	 * Shared positive-integer REST argument schema.
	 *
	 * @return array<string,mixed>
	 */
	private function id_arg(): array {
		return array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => static fn( $value ) => (int) $value > 0,
		);
	}
}
