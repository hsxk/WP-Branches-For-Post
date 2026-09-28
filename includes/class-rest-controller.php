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
	 * Initialize the REST controller.
	 *
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
					'id'           => $this->id_arg(),
					'force'        => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'review_token' => array(
						'type'    => 'string',
						'default' => '',
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
		$branch_id    = (int) $request['id'];
		$review_token = (string) $request->get_param( 'review_token' );
		if ( '' !== $review_token ) {
			$current_token = $this->review_token( $branch_id );
			if ( '' === $current_token || ! hash_equals( $review_token, $current_token ) ) {
				return new \WP_Error(
					'wbfp_review_state_changed',
					__( 'The review state changed. Review the latest changes before merging.', 'wp-branches-for-post' ),
					array( 'status' => 409 )
				);
			}
		}

		$original_id = $this->merges->merge( $branch_id, (bool) $request->get_param( 'force' ) );
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

			$pattern_refs  = Sync_Service::synced_pattern_refs( $post_id );
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
				'review_token'         => $this->review_token( $post_id ),
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
				'modified_human' => $this->modified_display_label( $branch ),
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
	 * Build a stable token for the branch state the user is reviewing.
	 *
	 * The token changes for mergeable data, identity data, baseline changes, or
	 * relationship changes. It contains no post content itself.
	 *
	 * @param int $branch_id Branch post ID.
	 * @return string
	 */
	private function review_token( int $branch_id ): string {
		if ( ! $this->branches->is_branch( $branch_id ) ) {
			return '';
		}

		$original_id = $this->branches->get_original_id( $branch_id );
		$base        = $this->branches->get_base_snapshot( $branch_id );
		$payload     = array(
			'original_id' => $original_id,
			'base'        => $base
				? Sync_Service::state_hash_from_payload( $base )
				: (string) get_post_meta( $branch_id, Branch_Service::META_BASE_HASH, true ),
			'original'    => $original_id > 0 ? Sync_Service::state_hash( $original_id ) : '',
			'branch'      => Sync_Service::state_hash( $branch_id ),
		);
		$encoded     = wp_json_encode( $payload );

		return false === $encoded ? '' : hash( 'sha256', $encoded );
	}

	/**
	 * Return compact Base/Original/Branch values for human review.
	 *
	 * Core editorial fields, taxonomy term names, and featured-image identity
	 * are exposed to the authorized editor. Arbitrary post-meta values remain
	 * intentionally hidden even when their keys are listed as changed.
	 *
	 * @param array<string,mixed> $analysis Branch analysis.
	 * @return array<string,array<string,string>>
	 */
	private function review_values( array $analysis ): array {
		if ( ! empty( $analysis['legacy'] ) || empty( $analysis['base'] ) || empty( $analysis['original'] ) || empty( $analysis['branch'] ) ) {
			return array();
		}

		$review = isset( $analysis['analysis'] ) && is_array( $analysis['analysis'] ) ? $analysis['analysis'] : array();
		$paths  = array_values(
			array_unique(
				array_merge(
					$review['branch_changes'] ?? array(),
					$review['original_changes'] ?? array(),
					$review['conflicts'] ?? array()
				)
			)
		);
		$result = array();

		foreach ( $paths as $path ) {
			if ( str_starts_with( $path, 'post.' ) ) {
				$field = substr( $path, 5 );
				if ( ! in_array( $field, array( 'post_title', 'post_excerpt', 'post_content', 'menu_order', 'comment_status', 'ping_status', 'post_password' ), true ) ) {
					continue;
				}

				$result[ $path ] = array(
					'base'     => $this->review_post_value( $field, $analysis['base']['merge']['post'][ $field ] ?? '' ),
					'original' => $this->review_post_value( $field, $analysis['original']['merge']['post'][ $field ] ?? '' ),
					'branch'   => $this->review_post_value( $field, $analysis['branch']['merge']['post'][ $field ] ?? '' ),
				);
				continue;
			}

			if ( str_starts_with( $path, 'taxonomies.' ) ) {
				$taxonomy = substr( $path, 11 );
				$object   = get_taxonomy( $taxonomy );
				$result[ $path ] = array(
					'label'    => $object && ! empty( $object->labels->name ) ? (string) $object->labels->name : $taxonomy,
					'base'     => $this->review_taxonomy_value( $taxonomy, $analysis['base']['merge']['taxonomies'][ $taxonomy ] ?? array() ),
					'original' => $this->review_taxonomy_value( $taxonomy, $analysis['original']['merge']['taxonomies'][ $taxonomy ] ?? array() ),
					'branch'   => $this->review_taxonomy_value( $taxonomy, $analysis['branch']['merge']['taxonomies'][ $taxonomy ] ?? array() ),
				);
				continue;
			}

			if ( 'meta._thumbnail_id' === $path ) {
				$base       = $this->review_featured_image_value( $analysis['base']['merge']['meta']['_thumbnail_id'] ?? array() );
				$original   = $this->review_featured_image_value( $analysis['original']['merge']['meta']['_thumbnail_id'] ?? array() );
				$branch     = $this->review_featured_image_value( $analysis['branch']['merge']['meta']['_thumbnail_id'] ?? array() );
				$post_type  = (string) ( $analysis['branch']['identity']['post_type'] ?? '' );
				$type_obj   = '' !== $post_type ? get_post_type_object( $post_type ) : null;
				$image_label = $type_obj && ! empty( $type_obj->labels->featured_image ) ? (string) $type_obj->labels->featured_image : 'Featured image';
				$result[ $path ] = array(
					'label'                => $image_label,
					'base'                 => $base['label'],
					'original'             => $original['label'],
					'branch'               => $branch['label'],
					'base_preview_url'     => $base['url'],
					'original_preview_url' => $original['url'],
					'branch_preview_url'   => $branch['url'],
				);
			}
		}

		return $result;
	}

	/**
	 * Format one core post field for the compact review.
	 *
	 * Passwords are deliberately masked rather than returned through REST.
	 *
	 * @param string $field Core post field.
	 * @param mixed  $value Raw snapshot value.
	 * @return string
	 */
	private function review_post_value( string $field, $value ): string {
		if ( 'post_password' === $field ) {
			return '' === (string) $value ? '—' : '••••••';
		}

		if ( 'menu_order' === $field ) {
			return (string) (int) $value;
		}

		if ( in_array( $field, array( 'comment_status', 'ping_status' ), true ) ) {
			$statuses = get_comment_statuses();
			if ( isset( $statuses[ (string) $value ] ) ) {
				return (string) $statuses[ (string) $value ];
			}
		}

		$text = $this->compact_review_text( $value );
		return '' === $text ? '—' : $text;
	}

	/**
	 * Convert taxonomy term IDs into readable localized term names.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param mixed  $value    Snapshot taxonomy value.
	 * @return string
	 */
	private function review_taxonomy_value( string $taxonomy, $value ): string {
		$ids = is_array( $value ) ? array_values( array_filter( array_map( 'absint', $value ) ) ) : array();
		if ( empty( $ids ) ) {
			return '—';
		}

		$names = array();
		foreach ( $ids as $term_id ) {
			$term = get_term( $term_id, $taxonomy );
			$names[] = $term && ! is_wp_error( $term ) ? (string) $term->name : '#' . $term_id;
		}

		return implode( ', ', $names );
	}

	/**
	 * Return a safe featured-image label and optional thumbnail URL.
	 *
	 * @param mixed $value Snapshot meta value.
	 * @return array{label:string,url:string}
	 */
	private function review_featured_image_value( $value ): array {
		$raw = is_array( $value ) ? reset( $value ) : $value;
		$id  = absint( $raw );
		if ( $id < 1 ) {
			return array(
				'label' => '—',
				'url'   => '',
			);
		}

		$attachment = get_post( $id );
		$title      = $attachment ? trim( wp_strip_all_tags( get_the_title( $attachment ) ) ) : '';
		$url        = wp_get_attachment_image_url( $id, 'thumbnail' );

		return array(
			'label' => '' !== $title ? sprintf( '%1$s (#%2$d)', $title, $id ) : '#' . $id,
			'url'   => $url ? (string) $url : '',
		);
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
	 * Build a localized modified-time display for branch cards.
	 *
	 * @param \WP_Post $post Branch post.
	 * @return string
	 */
	private function modified_display_label( \WP_Post $post ): string {
		$format = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		return (string) get_post_modified_time( $format, false, $post );
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
