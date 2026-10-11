<?php
/**
 * Conservative three-way merge of independently edited top-level blocks.
 *
 * @package WPBranchesForPost
 */

namespace WP_Branches_For_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This is deliberately a conflict-reduction layer, not an HTML diff.
 * Unsupported, ambiguous or reordered content remains a normal conflict.
 */
final class Block_Merge {
	/**
	 * Return merged content only when both sides' edits are unambiguous.
	 *
	 * @param string $base     Content at branch creation.
	 * @param string $original Current public original.
	 * @param string $branch   Current working branch.
	 * @return string|null Null signals that the field must remain a conflict.
	 */
	public static function merge( string $base, string $original, string $branch ): ?string {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return null;
		}

		$base_blocks     = self::blocks( $base );
		$original_blocks = self::blocks( $original );
		$branch_blocks   = self::blocks( $branch );
		if ( null === $base_blocks || null === $original_blocks || null === $branch_blocks ) {
			return null;
		}

		$base_keys     = array_map( array( __CLASS__, 'block_hash' ), $base_blocks );
		$original_keys = array_map( array( __CLASS__, 'block_hash' ), $original_blocks );
		$branch_keys   = array_map( array( __CLASS__, 'block_hash' ), $branch_blocks );

		// Identical blocks cannot be reliably mapped to their original positions.
		foreach ( array( $base_keys, $original_keys, $branch_keys ) as $keys ) {
			if ( count( $keys ) !== count( array_unique( $keys ) ) ) {
				return null;
			}
		}
		// Moved existing blocks make positional edits ambiguous. Insertions are
		// acceptable; reordering existing blocks is not.
		if ( ! self::preserves_base_order( $base_keys, $original_keys ) || ! self::preserves_base_order( $base_keys, $branch_keys ) ) {
			return null;
		}

		$original_edits = self::edits( $base_keys, $original_keys, $original_blocks );
		$branch_edits   = self::edits( $base_keys, $branch_keys, $branch_blocks );
		$edits          = $original_edits;

		foreach ( $branch_edits as $incoming ) {
			$duplicate = false;
			foreach ( $original_edits as $existing ) {
				if ( $existing['start'] === $incoming['start'] && $existing['end'] === $incoming['end'] && $existing['tokens'] === $incoming['tokens'] ) {
					$duplicate = true;
					break;
				}
				if ( self::overlaps( $existing, $incoming ) ) {
					return null;
				}
			}
			if ( ! $duplicate ) {
				$edits[] = $incoming;
			}
		}

		usort(
			$edits,
			static function ( array $left, array $right ): int {
				return ( $left['start'] <=> $right['start'] ) ?: ( $left['end'] <=> $right['end'] );
			}
		);

		$output = array();
		$cursor = 0;
		foreach ( $edits as $edit ) {
			foreach ( array_slice( $base_blocks, $cursor, $edit['start'] - $cursor ) as $block ) {
				$output[] = $block;
			}
			foreach ( $edit['replacement'] as $block ) {
				$output[] = $block;
			}
			$cursor = $edit['end'];
		}
		foreach ( array_slice( $base_blocks, $cursor ) as $block ) {
			$output[] = $block;
		}

		return serialize_blocks( $output );
	}

	/**
	 * Avoid rewriting unusual freeform, malformed, huge or globally shared content.
	 *
	 * @param string $content Saved Gutenberg content.
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function blocks( string $content ): ?array {
		if ( strlen( $content ) > 400000 ) {
			return null;
		}
		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) || count( $blocks ) < 2 || count( $blocks ) > 128 ) {
			return null;
		}
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) || in_array( $block['blockName'], array( 'core/block', 'core/template-part' ), true ) ) {
				return null;
			}
		}
		// Decline if the parser/serializer cannot faithfully round-trip the input.
		if ( trim( serialize_blocks( $blocks ) ) !== trim( $content ) ) {
			return null;
		}
		return $blocks;
	}

	/**
	 * Reject variants that reorder any surviving baseline blocks.
	 *
	 * @param string[] $base Base block hashes.
	 * @param string[] $side Side block hashes.
	 */
	private static function preserves_base_order( array $base, array $side ): bool {
		$positions = array_flip( $base );
		$previous  = -1;
		foreach ( $side as $token ) {
			if ( ! array_key_exists( $token, $positions ) ) {
				continue;
			}
			if ( $positions[ $token ] <= $previous ) {
				return false;
			}
			$previous = $positions[ $token ];
		}
		return true;
	}

	/** @param array<string,mixed> $block Block. */
	private static function block_hash( array $block ): string {
		return hash( 'sha256', serialize_block( $block ) );
	}

	/**
	 * Find minimal changed ranges using longest common subsequence anchors.
	 *
	 * @param string[]                      $base Base block identities.
	 * @param string[]                      $side Side block identities.
	 * @param array<int,array<string,mixed>> $blocks Parsed side blocks.
	 * @return array<int,array<string,mixed>>
	 */
	private static function edits( array $base, array $side, array $blocks ): array {
		$n = count( $base );
		$m = count( $side );
		$dp = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; --$i ) {
			for ( $j = $m - 1; $j >= 0; --$j ) {
				$dp[ $i ][ $j ] = $base[ $i ] === $side[ $j ]
					? 1 + $dp[ $i + 1 ][ $j + 1 ]
					: max( $dp[ $i + 1 ][ $j ], $dp[ $i ][ $j + 1 ] );
			}
		}

		$anchors = array();
		$i       = 0;
		$j       = 0;
		while ( $i < $n && $j < $m ) {
			if ( $base[ $i ] === $side[ $j ] ) {
				$anchors[] = array( $i, $j );
				++$i;
				++$j;
			} elseif ( $dp[ $i + 1 ][ $j ] >= $dp[ $i ][ $j + 1 ] ) {
				++$i;
			} else {
				++$j;
			}
		}
		$anchors[] = array( $n, $m );
		$edits     = array();
		$base_pos  = 0;
		$side_pos  = 0;
		foreach ( $anchors as $anchor ) {
			if ( $anchor[0] > $base_pos || $anchor[1] > $side_pos ) {
				$edits[] = array(
					'start'       => $base_pos,
					'end'         => $anchor[0],
					'tokens'      => array_slice( $side, $side_pos, $anchor[1] - $side_pos ),
					'replacement' => array_slice( $blocks, $side_pos, $anchor[1] - $side_pos ),
				);
			}
			$base_pos = $anchor[0] + 1;
			$side_pos = $anchor[1] + 1;
		}

		return $edits;
	}

	/**
	 * Treat simultaneous inserts and inserts on an edited boundary as conflicts.
	 *
	 * @param array<string,mixed> $a Original edit.
	 * @param array<string,mixed> $b Branch edit.
	 */
	private static function overlaps( array $a, array $b ): bool {
		if ( $a['start'] === $a['end'] ) {
			return $a['start'] >= $b['start'] && $a['start'] <= $b['end'];
		}
		if ( $b['start'] === $b['end'] ) {
			return $b['start'] >= $a['start'] && $b['start'] <= $a['end'];
		}
		return max( $a['start'], $b['start'] ) < min( $a['end'], $b['end'] );
	}
}
