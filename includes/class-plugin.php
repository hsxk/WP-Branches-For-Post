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

/**
 * Lightweight composition root for the plugin.
 *
 * Domain mutations live in Branch_Service and Merge_Service; this class only
 * wires WordPress hooks to those services.
 */
final class Plugin {
	/**
	 * Singleton plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Return the singleton plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Force instantiation through instance().
	 */
	private function __construct() {}

	/**
	 * Register plugin services and WordPress hooks once.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$branches = new Branch_Service();
		$merges   = new Merge_Service( $branches );
		$rest     = new REST_Controller( $branches, $merges );
		$admin    = new Admin( $branches, $merges );

		add_filter( 'wp_insert_post_data', array( $branches, 'keep_branch_non_public' ), 20, 2 );
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );
		$admin->register_hooks();
	}
}
