<?php
/**
 * Plugin bootstrap.
 *
 * @package WPBranchesForPost
 */

namespace WP_Branches_For_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	private static ?Plugin $instance = null;
	private bool $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$branches = new Branch_Service();
		$merges   = new Merge_Service( $branches );
		$rest     = new REST_Controller( $branches, $merges );
		$admin    = new Admin( $branches, $merges );

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'wp_insert_post_data', array( $branches, 'keep_branch_non_public' ), 20, 2 );
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );
		$admin->register_hooks();
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'wp-branches-for-post', false, dirname( plugin_basename( WBFP_FILE ) ) . '/languages' );
	}
}
