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
 * Synchronizes the subset of WordPress post state that belongs to editorial
 * content while deliberately preserving the original post identity.
 *
 * This class contains no authorization logic. Callers must perform capability
 * and CSRF/REST permission checks before invoking mutating methods.
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
		 * Extensions may add exclusions. Required branch/runtime exclusions are
		 * always merged back afterwards so they cannot be accidentally removed.
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
	 * Identity, status, slug, author, GUID, and publication dates intentionally
	 * stay owned by the original post. This is what lets a merge update content
	 * without changing the public resource identity.
	 *
	 * @return string[]
	 */
	public static function mergeable_post_fields(): array {
		$allowed = array(
			'post_title',
			'post_content',
			'post_excerpt',
			'post_parent',
			'menu_order',
			'comment_status',
			'ping_status',
			'post_password',
		);

		/**
		 * Filters the core post fields copied from a branch into its original.
		 *
		 * Extensions may remove fields from the default set, but cannot add
		 * identity/publication fields such as post_status, post_name, GUID,
		 * author, or dates.
		 *
		 * @param string[] $allowed Allowed editorial field names.
		 */
		$filtered = apply_filters( 'wbfp_mergeable_post_fields', $allowed );
		$filtered = is_array( $filtered ) ? array_filter( $filtered, 'is_string' ) : array();

		return array_values( array_intersect( $allowed, $filtered ) );
	}

	/**
	 * Copy the supported core post fields.
	 *
	 * @param \WP_Post $source Source post.
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
	 * Make target meta exactly match the source for syncable keys.
	 *
	 * @param int $source_id Source post ID.
	 * @param int $target_id Target post ID.
	 * @return void
	 */
	public static function sync_meta( int $source_id, int $target_id ): void {
		$source = self::get_syncable_meta( $source_id );
		$target = self::get_syncable_meta( $target_id );
		$keys   = array_unique( array_merge( array_keys( $source ), array_keys( $target ) ) );

		foreach ( $keys as $key ) {
			delete_post_meta( $target_id, $key );
			if ( ! isset( $source[ $key ] ) ) {
				continue;
			}
			foreach ( $source[ $key ] as $value ) {
				add_post_meta( $target_id, $key, wp_slash( $value ) );
			}
		}
	}

	/**
	 * Verify that all source taxonomy assignments can be read before a merge.
	 *
	 * This is a preflight check. It reduces the chance of a partial merge by
	 * detecting taxonomy read errors before the original post is modified.
	 *
	 * @param int    $source_id Source post ID.
	 * @param string $post_type Post type.
	 * @return \WP_Error|null
	 */
	public static function validate_taxonomies( int $source_id, string $post_type ): ?\WP_Error {
		foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
			$term_ids = wp_get_object_terms(
				$source_id,
				$taxonomy,
				array( 'fields' => 'ids' )
			);

			if ( is_wp_error( $term_ids ) ) {
				return $term_ids;
			}
		}

		return null;
	}

	/**
	 * Make target taxonomy terms exactly match the source, including empty sets.
	 *
	 * @param int    $source_id Source post ID.
	 * @param int    $target_id Target post ID.
	 * @param string $post_type Post type.
	 * @return \WP_Error|null
	 */
	public static function sync_taxonomies( int $source_id, int $target_id, string $post_type ): ?\WP_Error {
		$taxonomies = get_object_taxonomies( $post_type );

		foreach ( $taxonomies as $taxonomy ) {
			$term_ids = wp_get_object_terms(
				$source_id,
				$taxonomy,
				array( 'fields' => 'ids' )
			);
			if ( is_wp_error( $term_ids ) ) {
				return $term_ids;
			}

			$result = wp_set_object_terms( $target_id, array_map( 'intval', $term_ids ), $taxonomy, false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * Build a deterministic snapshot hash used for conflict detection.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function snapshot_hash( int $post_id ): string {
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

		$taxonomy_data = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $ids ) ) {
				// Conflict detection must fail closed when state cannot be read.
				return '';
			}
			$ids = array_map( 'intval', $ids );
			sort( $ids, SORT_NUMERIC );
			$taxonomy_data[ $taxonomy ] = $ids;
		}
		ksort( $taxonomy_data );

		$payload = array(
			'post'       => $post_data,
			'meta'       => self::normalize_for_hash( self::get_syncable_meta( $post_id ) ),
			'taxonomies' => $taxonomy_data,
		);

		$encoded = wp_json_encode( $payload );
		if ( false === $encoded ) {
			return '';
		}

		return hash( 'sha256', $encoded );
	}

	/**
	 * Normalize nested data before hashing.
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
	 * Determine whether an array is a list while remaining compatible with the supported WordPress versions.
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
