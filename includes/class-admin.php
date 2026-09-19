<?php
/**
 * WordPress admin integrations and compatibility actions.
 *
 * @package WPBranchesForPost
 */

namespace WP_Branches_For_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connects the domain services to WordPress admin/editor surfaces.
 *
 * Admin nonces protect browser actions from CSRF. The Branch_Service and
 * Merge_Service repeat capability checks because nonces are not authorization.
 */
final class Admin {
	private Branch_Service $branches;
	private Merge_Service $merges;

	/**
	 * @param Branch_Service $branches Branch lifecycle service.
	 * @param Merge_Service  $merges   Merge/discard service.
	 */
	public function __construct( Branch_Service $branches, Merge_Service $merges ) {
		$this->branches = $branches;
		$this->merges   = $merges;
	}

	/**
	 * Register admin, editor, and compatibility hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'display_post_states', array( $this, 'post_states' ), 10, 2 );
		add_action( 'post_submitbox_misc_actions', array( $this, 'classic_editor_actions' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_style' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_admin_bar_style' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );

		add_action( 'admin_post_wbfp_create_branch', array( $this, 'handle_create' ) );
		add_action( 'admin_action_wbfp_create_post_branch', array( $this, 'handle_legacy_create' ) );
		add_action( 'admin_post_wbfp_merge_branch', array( $this, 'handle_merge' ) );
	}

	/**
	 * Add branch actions to post/page list rows when permitted.
	 *
	 * @param array<string,string> $actions Existing row actions.
	 * @param \WP_Post             $post    Row post.
	 * @return array<string,string>
	 */
	public function row_actions( array $actions, \WP_Post $post ): array {
		if ( $this->branches->is_branch( $post->ID ) ) {
			$original_id = $this->branches->get_original_id( $post->ID );
			if ( current_user_can( 'edit_post', $post->ID ) && current_user_can( 'edit_post', $original_id ) ) {
				$actions['wbfp_merge'] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( $this->merge_url( $post->ID ) ),
					esc_html__( 'Merge branch', 'wp-branches-for-post' )
				);
			}
			return $actions;
		}

		if ( $this->branches->can_create( $post->ID ) ) {
			$actions['wbfp_create'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->create_url( $post->ID ) ),
				esc_html__( 'Create branch', 'wp-branches-for-post' )
			);
		}
		return $actions;
	}

	/**
	 * Label branch posts in list tables.
	 *
	 * @param string[] $states Existing post states.
	 * @param \WP_Post $post   Row post.
	 * @return string[]
	 */
	public function post_states( array $states, \WP_Post $post ): array {
		if ( $this->branches->is_branch( $post->ID ) ) {
			$states['wbfp_branch'] = sprintf(
				/* translators: %d: original post ID. */
				esc_html__( 'Branch of #%d', 'wp-branches-for-post' ),
				$this->branches->get_original_id( $post->ID )
			);
		}
		return $states;
	}

	/**
	 * Render branch controls in the Classic Editor publish box.
	 *
	 * Conflicted force merges require an additional browser confirmation.
	 *
	 * @return void
	 */
	public function classic_editor_actions(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( $this->branches->is_branch( $post->ID ) ) {
			$original_id = $this->branches->get_original_id( $post->ID );
			if ( current_user_can( 'edit_post', $post->ID ) && current_user_can( 'edit_post', $original_id ) ) {
				$conflict = $this->branches->conflict_state( $post->ID );
				$force        = Branch_Service::CONFLICT_CLEAN !== $conflict;
				$confirm_attr = '';
				if ( $force ) {
					$confirm_message = __( 'The original changed after this branch was created. Review both versions and use the block editor panel to force the merge only if appropriate.', 'wp-branches-for-post' );
					$confirm_attr    = sprintf(
						' onclick="return window.confirm(%s);"',
						esc_attr( (string) wp_json_encode( $confirm_message ) )
					);
				}

				printf(
					'<div class="misc-pub-section wbfp-classic-action"><strong>%1$s</strong><p><a class="button %2$s" href="%3$s"%5$s>%4$s</a></p></div>',
					esc_html__( 'Post Branch', 'wp-branches-for-post' ),
					$force ? '' : 'button-primary',
					esc_url( $this->merge_url( $post->ID, $force ) ),
					$force ? esc_html__( 'Force merge after review', 'wp-branches-for-post' ) : esc_html__( 'Merge into original', 'wp-branches-for-post' ),
					$confirm_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped JSON above.
				);
			}
			return;
		}

		if ( $this->branches->can_create( $post->ID ) ) {
			printf(
				'<div class="misc-pub-section wbfp-classic-action"><strong>%s</strong><p><a class="button" href="%s">%s</a></p></div>',
				esc_html__( 'Post Branch', 'wp-branches-for-post' ),
				esc_url( $this->create_url( $post->ID ) ),
				esc_html__( 'Create branch', 'wp-branches-for-post' )
			);
		}
	}

	/**
	 * Add a Create Branch shortcut to the admin bar when applicable.
	 *
	 * @param \WP_Admin_Bar $admin_bar WordPress admin bar.
	 * @return void
	 */
	public function admin_bar( \WP_Admin_Bar $admin_bar ): void {
		$post_id = 0;

		if ( is_admin() ) {
			$screen = get_current_screen();
			if ( ! $screen || 'post' !== $screen->base ) {
				return;
			}

			global $post;
			$post_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		} elseif ( is_singular() ) {
			$post_id = (int) get_queried_object_id();
		}

		if ( $post_id < 1 || ! $this->branches->can_create( $post_id ) ) {
			return;
		}

		$admin_bar->add_node(
			array(
				'id'    => 'wbfp_create_branch',
				'title' => esc_html__( 'Create Branch', 'wp-branches-for-post' ),
				'href'  => $this->create_url( $post_id ),
			)
		);
	}

	/**
	 * Show branch relationship and operation-result notices.
	 *
	 * @return void
	 */
	public function admin_notices(): void {
		$screen  = get_current_screen();
		$post_id = 0;

		if ( $screen && 'post' === $screen->base ) {
			global $post;
			$post_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		}

		if ( $post_id && $this->branches->is_branch( $post_id ) ) {
			$original_id = $this->branches->get_original_id( $post_id );
			$conflict    = $this->branches->conflict_state( $post_id );
			$class       = 'changed' === $conflict || 'unknown' === $conflict ? 'notice-warning' : 'notice-info';
			$message     = sprintf(
				/* translators: 1: original post ID, 2: edit URL for the original post. */
				__( 'This is a working branch of post #%1$d. The public original stays unchanged until you explicitly merge this branch. <a href="%2$s">Open original</a>.', 'wp-branches-for-post' ),
				$original_id,
				esc_url( get_edit_post_link( $original_id, 'raw' ) )
			);
			printf( '<div class="notice %1$s"><p>%2$s</p></div>', esc_attr( $class ), wp_kses_post( $message ) );
		}

		$notice_input = filter_input( INPUT_GET, 'wbfp_notice', FILTER_UNSAFE_RAW );
		$notice       = is_string( $notice_input ) ? sanitize_key( $notice_input ) : '';
		if ( 'merge_conflict' === $notice ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'The original changed after this branch was created. Review both versions and use the block editor panel to force the merge only if appropriate.', 'wp-branches-for-post' ) . '</p></div>';
		} elseif ( 'operation_failed' === $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'WP Branches For Post could not complete the requested operation.', 'wp-branches-for-post' ) . '</p></div>';
		}
	}

	/**
	 * Load admin CSS only on post edit/list screens.
	 *
	 * @return void
	 */
	public function enqueue_admin_style(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->base, array( 'post', 'edit' ), true ) ) {
			return;
		}

		wp_enqueue_style( 'wbfp-admin', WBFP_URL . 'assets/css/wbfp.css', array(), WBFP_VERSION );
	}

	/**
	 * Load front-end admin-bar CSS only where Create Branch can be shown.
	 *
	 * @return void
	 */
	public function enqueue_front_admin_bar_style(): void {
		if ( ! is_admin_bar_showing() || ! is_singular() ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		if ( $post_id > 0 && $this->branches->can_create( $post_id ) ) {
			wp_enqueue_style( 'wbfp-admin', WBFP_URL . 'assets/css/wbfp.css', array(), WBFP_VERSION );
		}
	}

	/**
	 * Load the Block Editor panel and its translations on post editor screens.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		$asset_file = WBFP_DIR . 'build/index.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array( 'dependencies' => array(), 'version' => WBFP_VERSION );

		wp_enqueue_script(
			'wbfp-editor',
			WBFP_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_enqueue_style( 'wbfp-editor', WBFP_URL . 'assets/css/editor.css', array( 'wp-components' ), WBFP_VERSION );
		wp_set_script_translations( 'wbfp-editor', 'wp-branches-for-post', WBFP_DIR . 'languages' );
	}

	/**
	 * Handle the nonce-protected classic/admin-list branch creation action.
	 *
	 * Authorization is repeated by Branch_Service::create().
	 *
	 * @return void
	 */
	public function handle_create(): void {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		check_admin_referer( 'wbfp_create_branch_' . $post_id );
		$this->create_and_redirect( $post_id );
	}

	/**
	 * Handle the 1.x nonce/action name for bookmarked legacy create links.
	 *
	 * @return void
	 */
	public function handle_legacy_create(): void {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		check_admin_referer( 'wbfp_branch_' . $post_id );
		$this->create_and_redirect( $post_id );
	}

	/**
	 * Handle the nonce-protected admin merge action.
	 *
	 * A valid nonce proves request intent only. Merge_Service::merge() performs
	 * the authoritative capability, relationship, and conflict checks.
	 *
	 * @return void
	 */
	public function handle_merge(): void {
		$branch_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		check_admin_referer( 'wbfp_merge_branch_' . $branch_id );
		$original_id = $this->branches->get_original_id( $branch_id );
		$force       = isset( $_GET['force'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['force'] ) );
		$result      = $this->merges->merge( $branch_id, $force );
		if ( is_wp_error( $result ) ) {
			$notice = 'wbfp_merge_conflict' === $result->get_error_code() ? 'merge_conflict' : 'operation_failed';
			$url    = add_query_arg( 'wbfp_notice', $notice, get_edit_post_link( $branch_id, 'raw' ) );
			wp_safe_redirect( $url );
			exit;
		}
		wp_safe_redirect( get_edit_post_link( $original_id, 'raw' ) );
		exit;
	}

	/**
	 * Create a branch and redirect to the branch editor or back with an error.
	 *
	 * @param int $post_id Original post ID.
	 * @return void
	 */
	private function create_and_redirect( int $post_id ): void {
		$branch_id = $this->branches->create( $post_id );
		if ( is_wp_error( $branch_id ) ) {
			wp_safe_redirect( add_query_arg( 'wbfp_notice', 'operation_failed', get_edit_post_link( $post_id, 'raw' ) ) );
			exit;
		}
		wp_safe_redirect( get_edit_post_link( $branch_id, 'raw' ) );
		exit;
	}

	/**
	 * Build a nonce-protected admin URL for branch creation.
	 *
	 * @param int $post_id Original post ID.
	 * @return string
	 */
	private function create_url( int $post_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=wbfp_create_branch&post=' . $post_id ),
			'wbfp_create_branch_' . $post_id
		);
	}

	/**
	 * Build a nonce-protected admin URL for merge.
	 *
	 * @param int  $branch_id Branch post ID.
	 * @param bool $force     Whether this is an explicit force merge.
	 * @return string
	 */
	private function merge_url( int $branch_id, bool $force = false ): string {
		$url = admin_url( 'admin-post.php?action=wbfp_merge_branch&post=' . $branch_id );
		if ( $force ) {
			$url = add_query_arg( 'force', '1', $url );
		}
		return wp_nonce_url(
			$url,
			'wbfp_merge_branch_' . $branch_id
		);
	}
}
