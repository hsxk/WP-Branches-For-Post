<?php
/**
 * Shared post/meta/taxonomy synchronization helpers.
 *
 * @package WPBranchesForPost
 */

namespace WP_Branches_For_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronizes editorial post state while preserving the original identity.
 */
final class Sync_Service {
	/**
	 * Plugin and WordPress runtime meta that should never be copied/merged.
	 *
	 * @return string[]
	 */
	public static function excluded_meta_keys(): array {
		$required = array(
			'_wbfp_original_post_id',
			'_wbfp_creator_user_id',
			'_wbfp_created_gmt',
			'_wbfp_base_modified_gmt',
			'_wbfp_base_snapshot_hash',
			'_wbfp_base_revision_id',
			'_wbfp_base_snapshot',
			'_wbfp_merged_at_gmt',
			'_wbfp_merged_by_user_id',
			'_original_post_id',
			'_creator_name',
			'_creator_user_id',
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_old_date',
			'_wp_trash_meta_status',
			'_wp_trash_meta_time',
			'_wp_desired_post_slug',
		);

		/**
		 * Filters meta keys excluded from branch synchronization.
		 *
		 * @param string[] $required Excluded keys.
		 */
		$filtered = apply_filters( 'wbfp_excluded_meta_keys', $required );
		$filtered = is_array( $filtered ) ? array_filter( $filtered, 'is_string' ) : array();

		return array_values( array_unique( array_merge( $required, $filtered ) ) );
	}

	/**
	 * Core post fields managed by a branch merge.
	 *
	 * post_parent is intentionally excluded because changing the parent of a
	 * hierarchical post can change its public URL.
	 *
	 * @return string[]
	 */
	public static function mergeable_post_fields(): array {
		$allowed = array(
			'post_title',
			'post_content',
			'post_excerpt',
			'menu_order',
			'comment_status',
			'ping_status',
			'post_password',
		);

		/**
		 * Filters the core post fields copied from a branch into its original.
		 *
		 * Extensions may remove fields from the default set but cannot add fields
		 * that alter the original resource identity/publication state.
		 *
		 * @param string[] $allowed Allowed editorial field names.
		 */
		$filtered = apply_filters( 'wbfp_mergeable_post_fields', $allowed );
		$filtered = is_array( $filtered ) ? array_filter( $filtered, 'is_string' ) : array();

		return array_values( array_intersect( $allowed, $filtered ) );
	}

	/**
	 * Copy supported core post fields.
	 *
	 * @param \WP_Post $source    Source post.
	 * @param int      $target_id Target post ID.
	 * @return int|\WP_Error
	 */
	public static function copy_core_fields( \WP_Post $source, int $target_id ) {
		$update = array( 'ID' => $target_id );
		foreach ( self::mergeable_post_fields() as $field ) {
			if ( property_exists( $source, $field ) ) {
				$update[ $field ] = $source->{$field};
			}
		}

		return wp_update_post( wp_slash( $update ), true );
	}

	/**
	 * Read syncable post meta preserving multi-value keys.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,array<int,mixed>>
	 */
	public static function get_syncable_meta( int $post_id ): array {
		$all      = get_post_meta( $post_id );
		$excluded = array_flip( self::excluded_meta_keys() );
		$result   = array();

		foreach ( $all as $key => $values ) {
			if ( isset( $excluded[ $key ] ) ) {
				continue;
			}
			$result[ $key ] = array_map( 'maybe_unserialize', $values );
		}

		ksort( $result );
		return $result;
	}

	/**
	 * Make target meta exactly match source meta.
	 *
	 * @param int $source_id Source post ID.
	 * @param int $target_id Target post ID.
	 * @return \WP_Error|null
	 */
	public static function sync_meta( int $source_id, int $target_id ): ?\WP_Error {
		return self::set_syncable_meta( $target_id, self::get_syncable_meta( $source_id ) );
	}

	/**
	 * Replace target syncable meta with an explicit state.
	 *
	 * @param int                            $target_id Target post ID.
	 * @param array<string,array<int,mixed>> $desired   Desired meta state.
	 * @return \WP_Error|null
	 */
	public static function set_syncable_meta( int $target_id, array $desired ): ?\WP_Error {
		$current = self::get_syncable_meta( $target_id );
		$keys    = array_unique( array_merge( array_keys( $desired ), array_keys( $current ) ) );

		foreach ( $keys as $key ) {
			delete_post_meta( $target_id, $key );
			if ( ! isset( $desired[ $key ] ) ) {
				continue;
			}

			foreach ( $desired[ $key ] as $value ) {
				if ( false === add_post_meta( $target_id, $key, wp_slash( $value ) ) ) {
					return new \WP_Error(
						'wbfp_meta_sync_failed',
						__( 'A post metadata value could not be synchronized.', 'wp-branches-for-post' ),
						array( 'meta_key' => $key )
					);
				}
			}
		}

		if ( self::normalize_for_hash( self::get_syncable_meta( $target_id ) ) !== self::normalize_for_hash( $desired ) ) {
			return new \WP_Error(
				'wbfp_meta_sync_verification_failed',
				__( 'Post metadata synchronization could not be verified.', 'wp-branches-for-post' )
			);
		}

		return null;
	}

	/**
	 * Read all taxonomy assignments for a post.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @return array<string,int[]>|\WP_Error
	 */
	public static function get_taxonomy_state( int $post_id, string $post_type ) {
		$result = array();
		foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
			$term_ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $term_ids ) ) {
				return $term_ids;
			}

			$term_ids = array_map( 'intval', $term_ids );
			sort( $term_ids, SORT_NUMERIC );
			$result[ $taxonomy ] = $term_ids;
		}
		ksort( $result );

		return $result;
	}

	/**
	 * Verify that all source taxonomy assignments can be read.
	 *
	 * @param int    $source_id Source post ID.
	 * @param string $post_type Post type.
	 * @return \WP_Error|null
	 */
	public static function validate_taxonomies( int $source_id, string $post_type ): ?\WP_Error {
		$state = self::get_taxonomy_state( $source_id, $post_type );
		return is_wp_error( $state ) ? $state : null;
	}

	/**
	 * Make target taxonomy state exactly match source.
	 *
	 * @param int    $source_id Source post ID.
	 * @param int    $target_id Target post ID.
	 * @param string $post_type Post type.
	 * @return \WP_Error|null
	 */
	public static function sync_taxonomies( int $source_id, int $target_id, string $post_type ): ?\WP_Error {
		$desired = self::get_taxonomy_state( $source_id, $post_type );
		if ( is_wp_error( $desired ) ) {
			return $desired;
		}

		return self::set_taxonomy_state( $target_id, $post_type, $desired );
	}

	/**
	 * Replace taxonomy assignments with an explicit state.
	 *
	 * @param int                 $target_id Target post ID.
	 * @param string              $post_type Post type.
	 * @param array<string,int[]> $desired   Desired taxonomy state.
	 * @return \WP_Error|null
	 */
	public static function set_taxonomy_state( int $target_id, string $post_type, array $desired ): ?\WP_Error {
		foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
			$term_ids = isset( $desired[ $taxonomy ] ) && is_array( $desired[ $taxonomy ] )
				? array_map( 'intval', $desired[ $taxonomy ] )
				: array();
			$result   = wp_set_object_terms( $target_id, $term_ids, $taxonomy, false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$actual = self::get_taxonomy_state( $target_id, $post_type );
		if ( is_wp_error( $actual ) ) {
			return $actual;
		}
		if ( $actual !== $desired ) {
			return new \WP_Error(
				'wbfp_taxonomy_sync_verification_failed',
				__( 'Taxonomy synchronization could not be verified.', 'wp-branches-for-post' )
			);
		}

		return null;
	}

	/**
	 * Capture mergeable and identity state for review, merge, and rollback.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	public static function snapshot( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$post_data = array();
		foreach ( self::mergeable_post_fields() as $field ) {
			$post_data[ $field ] = property_exists( $post, $field ) ? $post->{$field} : null;
		}
		ksort( $post_data );

		$taxonomies = self::get_taxonomy_state( $post_id, $post->post_type );
		if ( is_wp_error( $taxonomies ) ) {
			return null;
		}

		return array(
			'version'  => 2,
			'merge'    => array(
				'post'       => $post_data,
				'meta'       => self::normalize_for_hash( self::get_syncable_meta( $post_id ) ),
				'taxonomies' => $taxonomies,
			),
			'identity' => array(
				'post_type'   => $post->post_type,
				'post_status' => $post->post_status,
				'post_name'   => $post->post_name,
				'post_author' => (int) $post->post_author,
				'post_parent' => (int) $post->post_parent,
			),
		);
	}

	/**
	 * Build a deterministic hash of mergeable state.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function snapshot_hash( int $post_id ): string {
		$snapshot = self::snapshot( $post_id );
		return $snapshot ? self::snapshot_hash_from_payload( $snapshot ) : '';
	}

	/**
	 * Hash a captured version-2 snapshot.
	 *
	 * @param array<string,mixed> $snapshot Snapshot payload.
	 * @return string
	 */
	public static function snapshot_hash_from_payload( array $snapshot ): string {
		if ( ! isset( $snapshot['merge'] ) || ! is_array( $snapshot['merge'] ) ) {
			return '';
		}

		$encoded = wp_json_encode( self::normalize_for_hash( $snapshot['merge'] ) );
		return false === $encoded ? '' : hash( 'sha256', $encoded );
	}

	/**
	 * Reproduce the version-2.0 hash format for pre-2.1 branches.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function legacy_snapshot_hash( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$post_data = array(
			'post_type'      => $post->post_type,
			'post_title'     => $post->post_title,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_status'    => $post->post_status,
			'post_name'      => $post->post_name,
			'post_author'    => (int) $post->post_author,
			'post_parent'    => (int) $post->post_parent,
			'menu_order'     => (int) $post->menu_order,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'post_password'  => $post->post_password,
		);

		$taxonomy_data = self::get_taxonomy_state( $post_id, $post->post_type );
		if ( is_wp_error( $taxonomy_data ) ) {
			return '';
		}

		$payload = array(
			'post'       => $post_data,
			'meta'       => self::normalize_for_hash( self::get_syncable_meta( $post_id ) ),
			'taxonomies' => $taxonomy_data,
		);
		$encoded = wp_json_encode( $payload );
		return false === $encoded ? '' : hash( 'sha256', $encoded );
	}

	/**
	 * Return changed merge-state paths between snapshots.
	 *
	 * @param array<string,mixed> $base      Base snapshot.
	 * @param array<string,mixed> $candidate Candidate snapshot.
	 * @return string[]
	 */
	public static function changed_paths( array $base, array $candidate ): array {
		$paths = array();
		foreach ( array( 'post', 'meta', 'taxonomies' ) as $section ) {
			$base_values      = isset( $base['merge'][ $section ] ) && is_array( $base['merge'][ $section ] ) ? $base['merge'][ $section ] : array();
			$candidate_values = isset( $candidate['merge'][ $section ] ) && is_array( $candidate['merge'][ $section ] ) ? $candidate['merge'][ $section ] : array();
			$keys             = array_unique( array_merge( array_keys( $base_values ), array_keys( $candidate_values ) ) );
			sort( $keys, SORT_STRING );
			foreach ( $keys as $key ) {
				$left  = $base_values[ $key ] ?? null;
				$right = $candidate_values[ $key ] ?? null;
				if ( self::normalize_for_hash( $left ) !== self::normalize_for_hash( $right ) ) {
					$paths[] = $section . '.' . $key;
				}
			}
		}

		return $paths;
	}

	/**
	 * Return identity fields changed since a base snapshot.
	 *
	 * @param array<string,mixed> $base      Base snapshot.
	 * @param array<string,mixed> $candidate Candidate snapshot.
	 * @return string[]
	 */
	public static function changed_identity_paths( array $base, array $candidate ): array {
		$left  = isset( $base['identity'] ) && is_array( $base['identity'] ) ? $base['identity'] : array();
		$right = isset( $candidate['identity'] ) && is_array( $candidate['identity'] ) ? $candidate['identity'] : array();
		$keys  = array_unique( array_merge( array_keys( $left ), array_keys( $right ) ) );
		$paths = array();
		sort( $keys, SORT_STRING );
		foreach ( $keys as $key ) {
			if ( ( $left[ $key ] ?? null ) !== ( $right[ $key ] ?? null ) ) {
				$paths[] = 'identity.' . $key;
			}
		}

		return $paths;
	}

	/**
	 * Analyze a base/original/branch three-way state.
	 *
	 * @param array<string,mixed> $base     Base snapshot.
	 * @param array<string,mixed> $original Current original snapshot.
	 * @param array<string,mixed> $branch   Current branch snapshot.
	 * @return array<string,mixed>
	 */
	public static function three_way_analysis( array $base, array $original, array $branch ): array {
		$original_changes = self::changed_paths( $base, $original );
		$branch_changes   = self::changed_paths( $base, $branch );
		$shared           = array_intersect( $original_changes, $branch_changes );
		$conflicts        = array();

		foreach ( $shared as $path ) {
			if ( self::normalize_for_hash( self::path_value( $original, $path ) ) !== self::normalize_for_hash( self::path_value( $branch, $path ) ) ) {
				$conflicts[] = $path;
			}
		}

		return array(
			'original_changes'      => array_values( $original_changes ),
			'branch_changes'        => array_values( $branch_changes ),
			'conflicts'             => array_values( $conflicts ),
			'informational_changes' => self::changed_identity_paths( $base, $original ),
			'can_rebase'            => ! empty( $original_changes ) && empty( $conflicts ),
		);
	}

	/**
	 * Build the post-merge state while preserving non-overlapping original work.
	 *
	 * @param array<string,mixed> $base     Base snapshot.
	 * @param array<string,mixed> $original Current original snapshot.
	 * @param array<string,mixed> $branch   Current branch snapshot.
	 * @param bool                $force    Whether conflicts should prefer branch values.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function merged_snapshot( array $base, array $original, array $branch, bool $force = false ) {
		$analysis = self::three_way_analysis( $base, $original, $branch );
		if ( ! $force && ! empty( $analysis['conflicts'] ) ) {
			return new \WP_Error(
				'wbfp_merge_conflict',
				__( 'The branch and original contain overlapping changes that require review.', 'wp-branches-for-post' ),
				array( 'conflicts' => $analysis['conflicts'] )
			);
		}

		$result = $original;
		foreach ( $analysis['branch_changes'] as $path ) {
			self::set_path_value( $result, $path, self::path_value( $branch, $path ) );
		}
		$result['identity'] = $original['identity'] ?? array();

		return $result;
	}

	/**
	 * Build a rebased branch state for non-conflicting changes.
	 *
	 * @param array<string,mixed> $base     Base snapshot.
	 * @param array<string,mixed> $original Current original snapshot.
	 * @param array<string,mixed> $branch   Current branch snapshot.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function rebased_snapshot( array $base, array $original, array $branch ) {
		$analysis = self::three_way_analysis( $base, $original, $branch );
		if ( ! empty( $analysis['conflicts'] ) ) {
			return new \WP_Error(
				'wbfp_rebase_conflict',
				__( 'The branch and original contain overlapping changes that require review.', 'wp-branches-for-post' ),
				array( 'conflicts' => $analysis['conflicts'] )
			);
		}

		$result = $branch;
		foreach ( $analysis['original_changes'] as $path ) {
			if ( in_array( $path, $analysis['branch_changes'], true ) ) {
				continue;
			}
			self::set_path_value( $result, $path, self::path_value( $original, $path ) );
		}

		return $result;
	}

	/**
	 * Apply mergeable state to a post and verify the result.
	 *
	 * @param array<string,mixed> $snapshot  Snapshot to apply.
	 * @param int                 $target_id Target post ID.
	 * @param string              $post_type Post type.
	 * @return \WP_Error|null
	 */
	public static function apply_merge_snapshot( array $snapshot, int $target_id, string $post_type ): ?\WP_Error {
		if ( ! isset( $snapshot['merge'] ) || ! is_array( $snapshot['merge'] ) ) {
			return new \WP_Error( 'wbfp_invalid_snapshot', __( 'The saved branch snapshot is invalid.', 'wp-branches-for-post' ) );
		}

		$post_fields = isset( $snapshot['merge']['post'] ) && is_array( $snapshot['merge']['post'] ) ? $snapshot['merge']['post'] : array();
		$update      = array( 'ID' => $target_id );
		foreach ( self::mergeable_post_fields() as $field ) {
			if ( array_key_exists( $field, $post_fields ) ) {
				$update[ $field ] = $post_fields[ $field ];
			}
		}

		$updated = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$meta_error = self::set_syncable_meta(
			$target_id,
			isset( $snapshot['merge']['meta'] ) && is_array( $snapshot['merge']['meta'] ) ? $snapshot['merge']['meta'] : array()
		);
		if ( $meta_error ) {
			return $meta_error;
		}

		$taxonomy_error = self::set_taxonomy_state(
			$target_id,
			$post_type,
			isset( $snapshot['merge']['taxonomies'] ) && is_array( $snapshot['merge']['taxonomies'] ) ? $snapshot['merge']['taxonomies'] : array()
		);
		if ( $taxonomy_error ) {
			return $taxonomy_error;
		}

		clean_post_cache( $target_id );
		$actual = self::snapshot( $target_id );
		if ( ! $actual || self::snapshot_hash_from_payload( $actual ) !== self::snapshot_hash_from_payload( $snapshot ) ) {
			return new \WP_Error(
				'wbfp_snapshot_apply_verification_failed',
				__( 'The merged post state could not be verified.', 'wp-branches-for-post' )
			);
		}

		return null;
	}

	/**
	 * Find synced-pattern references in post content.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	public static function synced_pattern_refs( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$refs = array();
		self::collect_synced_pattern_refs( parse_blocks( $post->post_content ), $refs );
		$refs = array_values( array_unique( array_filter( array_map( 'absint', $refs ) ) ) );
		sort( $refs, SORT_NUMERIC );
		return $refs;
	}

	/**
	 * Recursively collect core/block references.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param int[]                          $refs   Collected references.
	 * @return void
	 */
	private static function collect_synced_pattern_refs( array $blocks, array &$refs ): void {
		foreach ( $blocks as $block ) {
			if ( 'core/block' === ( $block['blockName'] ?? '' ) && isset( $block['attrs']['ref'] ) ) {
				$refs[] = (int) $block['attrs']['ref'];
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_synced_pattern_refs( $block['innerBlocks'], $refs );
			}
		}
	}

	/**
	 * Read a dotted merge path from a snapshot.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param string              $path     Dotted path.
	 * @return mixed
	 */
	private static function path_value( array $snapshot, string $path ) {
		$parts = explode( '.', $path, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		return $snapshot['merge'][ $parts[0] ][ $parts[1] ] ?? null;
	}

	/**
	 * Set a dotted merge path on a snapshot.
	 *
	 * @param array<string,mixed> $snapshot Snapshot by reference.
	 * @param string              $path     Dotted path.
	 * @param mixed               $value    Value.
	 * @return void
	 */
	private static function set_path_value( array &$snapshot, string $path, $value ): void {
		$parts = explode( '.', $path, 2 );
		if ( 2 !== count( $parts ) ) {
			return;
		}
		if ( ! isset( $snapshot['merge'][ $parts[0] ] ) || ! is_array( $snapshot['merge'][ $parts[0] ] ) ) {
			$snapshot['merge'][ $parts[0] ] = array();
		}
		if ( null === $value ) {
			unset( $snapshot['merge'][ $parts[0] ][ $parts[1] ] );
			return;
		}
		$snapshot['merge'][ $parts[0] ][ $parts[1] ] = $value;
	}

	/**
	 * Normalize nested data before hashing/comparison.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function normalize_for_hash( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( self::is_list( $value ) ) {
			return array_map( array( self::class, 'normalize_for_hash' ), $value );
		}

		ksort( $value );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::normalize_for_hash( $item );
		}
		return $value;
	}

	/**
	 * Determine whether an array is a list.
	 *
	 * @param array<mixed> $value Array to inspect.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		if ( array() === $value ) {
			return true;
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
