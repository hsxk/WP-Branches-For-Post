<?php
/**
 * Optional team approval and fail-closed scheduled branch merges.
 *
 * @package WPBranchesForPost
 */

namespace WP_Branches_For_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Workflow metadata is kept on the working draft, never on the public original.
 */
final class Workflow_Service {
	public const META_STATE         = '_wbfp_workflow_state';
	public const META_REQUEST_BY    = '_wbfp_review_requested_by';
	public const META_REQUEST_TOKEN = '_wbfp_review_requested_state_token';
	public const META_REVIEW_BY     = '_wbfp_reviewed_by';
	public const META_REVIEWED_AT  = '_wbfp_reviewed_at_gmt';
	public const META_REQUESTED_AT = '_wbfp_review_requested_at_gmt';
	public const META_REVIEW_TOKEN  = '_wbfp_approved_state_token';
	public const META_REVIEW_NOTE   = '_wbfp_review_feedback';
	public const META_SCHEDULE_AT   = '_wbfp_schedule_at_gmt';
	public const META_SCHEDULE_BY   = '_wbfp_schedule_by';
	public const META_SCHEDULE_TOKEN = '_wbfp_schedule_state_token';
	public const META_SCHEDULE_ERROR = '_wbfp_schedule_error';
	public const CRON_HOOK           = 'wbfp_run_scheduled_merge';

	private Branch_Service $branches;
	private Merge_Service $merges;

	public function __construct( Branch_Service $branches, Merge_Service $merges ) {
		$this->branches = $branches;
		$this->merges   = $merges;
	}

	/** Register admin workflow and secure automated execution. */
	public function register_hooks(): void {
		add_action( self::CRON_HOOK, array( $this, 'execute_schedule' ), 10, 1 );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_post_wbfp_workflow', array( $this, 'admin_action' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wbfp_after_merge', array( $this, 'on_branch_merged' ), 10, 1 );
		add_action( 'wbfp_after_discard', array( $this, 'on_branch_merged' ), 10, 1 );
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 20, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 20, 2 );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
	}

	/** Optional site-wide enforcement; extensions may impose stricter policy. */
	public static function requires_approval( int $branch_id, int $original_id ): bool {
		return (bool) apply_filters( 'wbfp_require_approval', (bool) get_option( 'wbfp_require_approval', false ), $branch_id, $original_id );
	}

	/** Current fingerprint changes on content, identity, baseline, or relationship edits. */
	public function state_token( int $branch_id ): string {
		if ( ! $this->branches->is_branch( $branch_id ) || ! get_post( $branch_id ) ) {
			return '';
		}
		$original_id = $this->branches->get_original_id( $branch_id );
		$original    = get_post( $original_id );
		$branch      = get_post( $branch_id );
		$base        = get_post_meta( $branch_id, Branch_Service::META_BASE_SNAPSHOT, true );
		if ( ! $original || ! $branch || ! is_array( $base ) ) {
			return '';
		}
		$payload = array(
			'original_id' => $original_id,
			'base'        => Sync_Service::state_hash_from_payload( $base ),
			'original'    => Sync_Service::state_hash( $original_id ),
			'branch'      => Sync_Service::state_hash( $branch_id ),
		);
		if ( in_array( '', array( $payload['base'], $payload['original'], $payload['branch'] ), true ) ) {
			return '';
		}
		return hash( 'sha256', wp_json_encode( $payload ) );
	}

	/** Approval must match the *current* content, not just an old status flag. */
	public function is_approved( int $branch_id ): bool {
		$stored = (string) get_post_meta( $branch_id, self::META_REVIEW_TOKEN, true );
		$now    = $this->state_token( $branch_id );
		$reviewer = (int) get_post_meta( $branch_id, self::META_REVIEW_BY, true );
		$creator  = (int) get_post_meta( $branch_id, Branch_Service::META_CREATOR_USER_ID, true );
		$original = get_post( $this->branches->get_original_id( $branch_id ) );
		$type     = $original ? get_post_type_object( $original->post_type ) : null;
		return 'approved' === get_post_meta( $branch_id, self::META_STATE, true )
			&& $reviewer > 0 && $reviewer !== $creator
			&& $original && $type && ! empty( $type->cap->edit_others_posts )
			&& user_can( $reviewer, $type->cap->edit_others_posts )
			&& user_can( $reviewer, 'edit_post', $original->ID )
			&& '' !== $stored && '' !== $now && hash_equals( $stored, $now );
	}

	/** A direct merge must never silently bypass an enabled approval policy. */
	public function merge_gate( int $branch_id, int $original_id ): ?\WP_Error {
		if ( self::requires_approval( $branch_id, $original_id ) && ! $this->is_approved( $branch_id ) ) {
			return new \WP_Error( 'wbfp_approval_required', __( 'This branch needs approval for its current contents before it can be merged.', 'wp-branches-for-post' ), array( 'status' => 409 ) );
		}
		return null;
	}

	/** Only someone with independent editorial authority can approve. */
	public function can_review( int $branch_id ): bool {
		$original_id = $this->branches->get_original_id( $branch_id );
		$post        = get_post( $original_id );
		$type        = $post ? get_post_type_object( $post->post_type ) : null;
		$creator     = (int) get_post_meta( $branch_id, Branch_Service::META_CREATOR_USER_ID, true );
		return $post && $type && get_current_user_id() > 0 && get_current_user_id() !== $creator
			&& current_user_can( 'edit_post', $branch_id )
			&& current_user_can( 'edit_post', $original_id )
			&& ! empty( $type->cap->edit_others_posts )
			&& current_user_can( $type->cap->edit_others_posts );
	}

	/** Any branch editor can ask an independent reviewer to inspect changes. */
	public function request_review( int $branch_id ) {
		if ( ! $this->can_edit( $branch_id ) ) {
			return new \WP_Error( 'wbfp_review_denied', __( 'You cannot request review for this branch.', 'wp-branches-for-post' ), array( 'status' => 403 ) );
		}
		$token = $this->state_token( $branch_id );
		if ( '' === $token ) {
			return new \WP_Error( 'wbfp_review_unavailable', __( 'This branch cannot be reviewed until its baseline is valid.', 'wp-branches-for-post' ), array( 'status' => 409 ) );
		}
		$this->cancel_event( $branch_id );
		delete_post_meta( $branch_id, self::META_REVIEW_TOKEN );
		update_post_meta( $branch_id, self::META_STATE, 'pending' );
		update_post_meta( $branch_id, self::META_REQUEST_BY, get_current_user_id() );
		update_post_meta( $branch_id, self::META_REQUESTED_AT, current_time( 'mysql', true ) );
		update_post_meta( $branch_id, self::META_REQUEST_TOKEN, $token );
		update_post_meta( $branch_id, self::META_REVIEW_NOTE, '' );
		return true;
	}

	/** Approvals are scoped to the exact reviewed state. */
	public function decide( int $branch_id, bool $approve, string $note = '' ) {
		if ( ! $this->can_review( $branch_id ) ) {
			return new \WP_Error( 'wbfp_review_denied', __( 'An independent authorized editor must review this branch.', 'wp-branches-for-post' ), array( 'status' => 403 ) );
		}
		if ( 'pending' !== get_post_meta( $branch_id, self::META_STATE, true ) ) {
			return new \WP_Error( 'wbfp_review_not_pending', __( 'This branch has not been submitted for review.', 'wp-branches-for-post' ), array( 'status' => 409 ) );
		}
		$token = $this->state_token( $branch_id );
		if ( '' === $token ) {
			return new \WP_Error( 'wbfp_review_unavailable', __( 'The branch review state could not be verified.', 'wp-branches-for-post' ), array( 'status' => 409 ) );
		}
		$requested = (string) get_post_meta( $branch_id, self::META_REQUEST_TOKEN, true );
		if ( '' === $requested || ! hash_equals( $requested, $token ) ) {
			return new \WP_Error( 'wbfp_review_state_changed', __( 'The branch changed after review was requested. Submit it for review again.', 'wp-branches-for-post' ), array( 'status' => 409 ) );
		}
		if ( $approve ) {
			$analysis = $this->branches->analyze_branch( $branch_id );
			if ( ! in_array( $analysis['state'] ?? '', array( Branch_Service::CONFLICT_CLEAN, Branch_Service::CONFLICT_REBASE, Branch_Service::CONFLICT_INFORMATIONAL ), true ) ) {
				return new \WP_Error( 'wbfp_review_conflicts', __( 'Resolve conflicting or unknown branch changes before approval.', 'wp-branches-for-post' ), array( 'status' => 409 ) );
			}
			update_post_meta( $branch_id, self::META_REVIEW_TOKEN, $token );
		} else {
			delete_post_meta( $branch_id, self::META_REVIEW_TOKEN );
			$this->cancel_event( $branch_id );
		}
		update_post_meta( $branch_id, self::META_STATE, $approve ? 'approved' : 'rejected' );
		update_post_meta( $branch_id, self::META_REVIEW_BY, get_current_user_id() );
		update_post_meta( $branch_id, self::META_REVIEWED_AT, current_time( 'mysql', true ) );
		update_post_meta( $branch_id, self::META_REVIEW_NOTE, wp_html_excerpt( sanitize_textarea_field( $note ), 2000, '' ) );
		return true;
	}

	/**
	 * Schedule using an absolute Unix timestamp, never the branch post_status.
	 * The original remains published until a separately authorized cron merge.
	 */
	public function schedule( int $branch_id, int $timestamp ) {
		if ( ! $this->can_edit( $branch_id ) ) {
			return new \WP_Error( 'wbfp_schedule_denied', __( 'You cannot schedule a merge for this branch.', 'wp-branches-for-post' ), array( 'status' => 403 ) );
		}
		if ( $timestamp < time() + 60 || $timestamp > time() + YEAR_IN_SECONDS ) {
			return new \WP_Error( 'wbfp_schedule_invalid', __( 'Choose a future time within the next year.', 'wp-branches-for-post' ), array( 'status' => 400 ) );
		}
		$original_id = $this->branches->get_original_id( $branch_id );
		$gate        = $this->merge_gate( $branch_id, $original_id );
		if ( $gate ) {
			return $gate;
		}
		$analysis = $this->branches->analyze_branch( $branch_id );
		if ( ! in_array( $analysis['state'] ?? '', array( Branch_Service::CONFLICT_CLEAN, Branch_Service::CONFLICT_REBASE, Branch_Service::CONFLICT_INFORMATIONAL ), true ) ) {
			return new \WP_Error( 'wbfp_schedule_conflict', __( 'Review and resolve conflicts before scheduling a merge.', 'wp-branches-for-post' ), array( 'status' => 409 ) );
		}
		if ( empty( $analysis['analysis']['branch_changes'] ) ) {
			return new \WP_Error( 'wbfp_schedule_no_changes', __( 'Make changes to the branch before scheduling a merge.', 'wp-branches-for-post' ), array( 'status' => 409 ) );
		}
		$token = $this->state_token( $branch_id );
		if ( '' === $token ) {
			return new \WP_Error( 'wbfp_schedule_invalid_state', __( 'The current content state could not be verified.', 'wp-branches-for-post' ), array( 'status' => 409 ) );
		}
		$this->cancel_event( $branch_id );
		$result = wp_schedule_single_event( $timestamp, self::CRON_HOOK, array( $branch_id ), true );
		if ( is_wp_error( $result ) || ! $result ) {
			return new \WP_Error( 'wbfp_schedule_failed', __( 'WordPress could not schedule the merge.', 'wp-branches-for-post' ), array( 'status' => 500 ) );
		}
		update_post_meta( $branch_id, self::META_SCHEDULE_AT, gmdate( 'Y-m-d H:i:s', $timestamp ) );
		update_post_meta( $branch_id, self::META_SCHEDULE_BY, get_current_user_id() );
		update_post_meta( $branch_id, self::META_SCHEDULE_TOKEN, $token );
		delete_post_meta( $branch_id, self::META_SCHEDULE_ERROR );
		return true;
	}

	public function cancel( int $branch_id ) {
		if ( ! $this->can_edit( $branch_id ) ) {
			return new \WP_Error( 'wbfp_schedule_denied', __( 'You cannot cancel this scheduled merge.', 'wp-branches-for-post' ), array( 'status' => 403 ) );
		}
		$this->cancel_event( $branch_id );
		return true;
	}

	/** Cron never forces a conflict, never bypasses capabilities, and rejects changed content. */
	public function execute_schedule( int $branch_id ): void {
		$actor = (int) get_post_meta( $branch_id, self::META_SCHEDULE_BY, true );
		$token = (string) get_post_meta( $branch_id, self::META_SCHEDULE_TOKEN, true );
		$time  = (string) get_post_meta( $branch_id, self::META_SCHEDULE_AT, true );
		$prior = get_current_user_id();
		wp_set_current_user( $actor );
		try {
			$now = $this->state_token( $branch_id );
			if ( ! $actor || ! $time || '' === $token || '' === $now || ! hash_equals( $token, $now ) ) {
				$error = 'wbfp_schedule_stale';
			} elseif ( ! $this->can_edit( $branch_id ) ) {
				$error = 'wbfp_schedule_permission_changed';
			} else {
				$original_id = $this->branches->get_original_id( $branch_id );
				$gate        = $this->merge_gate( $branch_id, $original_id );
				$result      = $gate ? $gate : $this->merges->merge( $branch_id, false );
				$error       = is_wp_error( $result ) ? $result->get_error_code() : '';
			}
			if ( '' !== $error ) {
				update_post_meta( $branch_id, self::META_SCHEDULE_ERROR, $error );
			}
		} finally {
			$this->clear_schedule_meta( $branch_id );
			wp_clear_scheduled_hook( self::CRON_HOOK, array( $branch_id ) );
			wp_set_current_user( $prior );
		}
	}

	public function on_branch_merged( int $branch_id ): void {
		$this->cancel_event( $branch_id );
	}

	/** Editor capability on both sides is required even when a workflow URL is known. */
	private function can_edit( int $branch_id ): bool {
		$original_id = $this->branches->get_original_id( $branch_id );
		$branch      = get_post( $branch_id );
		$original    = get_post( $original_id );
		return $this->branches->is_branch( $branch_id )
			&& $branch && $original && 'draft' === $branch->post_status
			&& ! in_array( $original->post_status, array( 'trash', 'auto-draft' ), true )
			&& $branch->post_type === $original->post_type
			&& current_user_can( 'edit_post', $branch_id ) && current_user_can( 'edit_post', $original_id );
	}

	private function clear_schedule_meta( int $branch_id ): void {
		delete_post_meta( $branch_id, self::META_SCHEDULE_AT );
		delete_post_meta( $branch_id, self::META_SCHEDULE_BY );
		delete_post_meta( $branch_id, self::META_SCHEDULE_TOKEN );
	}

	private function cancel_event( int $branch_id ): void {
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $branch_id ) );
		$this->clear_schedule_meta( $branch_id );
	}

	/** Make the workflow discoverable on every supported post list. */
	public function row_action( array $actions, \WP_Post $post ): array {
		if ( $this->can_edit( $post->ID ) ) {
			$actions['wbfp_workflow'] = '<a href="' . esc_url( admin_url( 'tools.php?page=wbfp-workflow&branch_id=' . $post->ID ) ) . '">' . esc_html__( 'Review / schedule', 'wp-branches-for-post' ) . '</a>';
		}
		return $actions;
	}

	/** Add a direct entry to the workflow while editing a draft branch. */
	public function admin_bar( \WP_Admin_Bar $bar ): void {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		global $post;
		if ( ! $screen || 'post' !== $screen->base || ! $post instanceof \WP_Post || ! $this->can_edit( $post->ID ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'wbfp-workflow',
				'title' => __( 'Branch review & schedule', 'wp-branches-for-post' ),
				'href'  => admin_url( 'tools.php?page=wbfp-workflow&branch_id=' . $post->ID ),
			)
		);
	}

	/** Site owner can turn on independent approval without writing custom code. */
	public function register_settings(): void {
		register_setting( 'wbfp_workflow_settings', 'wbfp_require_approval', array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ) );
	}

	public function admin_menu(): void {
		add_submenu_page( 'tools.php', __( 'Post Branch Workflow', 'wp-branches-for-post' ), __( 'Branch Workflow', 'wp-branches-for-post' ), 'read', 'wbfp-workflow', array( $this, 'admin_page' ) );
	}

	public function admin_page(): void {
		$branch_id = isset( $_GET['branch_id'] ) ? absint( wp_unslash( $_GET['branch_id'] ) ) : 0;
		if ( ! $branch_id || ! $this->can_edit( $branch_id ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Branch Workflow', 'wp-branches-for-post' ) . '</h1><p>' . esc_html__( 'Open an editable working branch and follow its Workflow link to request review or schedule a merge.', 'wp-branches-for-post' ) . '</p>';
			$this->pending_review_queue();
			if ( current_user_can( 'manage_options' ) ) {
				$this->settings_form();
			}
			echo '</div>';
			return;
		}
		$original_id = $this->branches->get_original_id( $branch_id );
		$state       = (string) get_post_meta( $branch_id, self::META_STATE, true );
		$scheduled   = (string) get_post_meta( $branch_id, self::META_SCHEDULE_AT, true );
		$note        = (string) get_post_meta( $branch_id, self::META_REVIEW_NOTE, true );
		$edit_url    = get_edit_post_link( $branch_id, 'raw' );
		echo '<div class="wrap"><h1>' . esc_html__( 'Branch Workflow', 'wp-branches-for-post' ) . '</h1>';
		echo '<p><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Review changes in branch editor', 'wp-branches-for-post' ) . '</a></p>';
		echo '<p>' . esc_html( get_the_title( $branch_id ) ) . ' → ' . esc_html( get_the_title( $original_id ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Review status:', 'wp-branches-for-post' ) . '</strong> ' . esc_html( $this->is_approved( $branch_id ) ? 'approved' : ( $state === 'approved' ? 'stale approval' : ( $state ?: 'not requested' ) ) ) . '</p>';
		$creator_id = (int) get_post_meta( $branch_id, Branch_Service::META_CREATOR_USER_ID, true );
		$reviewer_id = (int) get_post_meta( $branch_id, self::META_REVIEW_BY, true );
		$creator = $creator_id ? get_userdata( $creator_id ) : false;
		$reviewer = $reviewer_id ? get_userdata( $reviewer_id ) : false;
		if ( $creator ) {
			echo '<p><strong>' . esc_html__( 'Branch creator:', 'wp-branches-for-post' ) . '</strong> ' . esc_html( $creator->display_name ) . '</p>';
		}
		if ( $reviewer ) {
			echo '<p><strong>' . esc_html__( 'Last reviewed by:', 'wp-branches-for-post' ) . '</strong> ' . esc_html( $reviewer->display_name ) . ' · ' . esc_html( (string) get_post_meta( $branch_id, self::META_REVIEWED_AT, true ) ) . ' UTC</p>';
		}
		$analysis = $this->branches->analyze_branch( $branch_id );
		$review   = $analysis['analysis'] ?? array();
		echo '<p>' . esc_html( sprintf( __( '%1$d branch changes · %2$d original changes · %3$d conflicts · %4$d auto-mergeable fields', 'wp-branches-for-post' ), count( $review['branch_changes'] ?? array() ), count( $review['original_changes'] ?? array() ), count( $review['conflicts'] ?? array() ), count( $review['auto_merged_fields'] ?? array() ) ) ) . '</p>';
		if ( $note ) {
			echo '<p>' . esc_html( $note ) . '</p>';
		}
		if ( $scheduled ) {
			echo '<p>' . esc_html__( 'Scheduled (UTC):', 'wp-branches-for-post' ) . ' ' . esc_html( $scheduled ) . '</p>';
		}
		$last_error = (string) get_post_meta( $branch_id, self::META_SCHEDULE_ERROR, true );
		if ( $last_error ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $last_error ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Changes to the original or branch after approval or scheduling invalidate the stored review. Scheduled merges use WP-Cron and require a working WordPress cron runner.', 'wp-branches-for-post' ) . '</p>';
		$this->form( $branch_id, 'request', __( 'Request review', 'wp-branches-for-post' ) );
		if ( $this->can_review( $branch_id ) && 'pending' === $state ) {
			echo '<p class="description">' . esc_html__( 'Open Review changes in the editor and inspect Base, Original and Branch before deciding. Approval applies only to the exact requested snapshot.', 'wp-branches-for-post' ) . '</p>';
			$this->form( $branch_id, 'approve', __( 'Approve current changes', 'wp-branches-for-post' ), true );
			$this->form( $branch_id, 'reject', __( 'Request changes', 'wp-branches-for-post' ), true );
		}
		echo '<h2>' . esc_html__( 'Schedule safe merge', 'wp-branches-for-post' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wbfp_workflow_' . $branch_id );
		echo '<input type="hidden" name="action" value="wbfp_workflow"><input type="hidden" name="branch_id" value="' . esc_attr( (string) $branch_id ) . '"><input type="hidden" name="operation" value="schedule">';
		echo '<label>' . esc_html__( 'Site-local date and time', 'wp-branches-for-post' ) . ' <input type="datetime-local" name="when" required></label> ';
		submit_button( __( 'Schedule merge', 'wp-branches-for-post' ), 'secondary', 'submit', false );
		echo '</form>';
		if ( $scheduled ) {
			$this->form( $branch_id, 'cancel', __( 'Cancel scheduled merge', 'wp-branches-for-post' ) );
		}
		if ( current_user_can( 'manage_options' ) ) {
			$this->settings_form();
		}
		echo '</div>';
	}

	/** Show a permission-filtered queue instead of requiring reviewers to know a branch ID. */
	private function pending_review_queue(): void {
		$query = new \WP_Query(
			array(
				'post_type'      => array_values( get_post_types( array( 'show_ui' => true ), 'names' ) ),
				'post_status'    => 'draft',
				'posts_per_page' => 50,
				'meta_key'       => self::META_REQUESTED_AT,
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A bounded editorial queue needs its workflow state.
				'meta_query'     => array(
					array(
						'key'     => self::META_STATE,
						'value'   => 'pending',
						'compare' => '=',
					),
				),
			)
		);
		echo '<h2>' . esc_html__( 'Pending editorial reviews', 'wp-branches-for-post' ) . '</h2><ul class="ul-disc">';
		$visible = 0;
		foreach ( $query->posts as $candidate ) {
			if ( ! $this->can_review( $candidate->ID ) ) {
				continue;
			}
			++$visible;
			$url = admin_url( 'tools.php?page=wbfp-workflow&branch_id=' . $candidate->ID );
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $candidate->ID ) ) . '</a> <span class="description">' . esc_html( $candidate->post_modified ) . '</span></li>';
		}
		if ( ! $visible ) {
			echo '<li>' . esc_html__( 'No reviews requiring your approval in the most recent 50 submissions.', 'wp-branches-for-post' ) . '</li>';
		}
		echo '</ul>';
	}

	/** Shared settings form, also accessible without a selected branch. */
	private function settings_form(): void {
		echo '<hr><h2>' . esc_html__( 'Site-wide approval policy', 'wp-branches-for-post' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( 'wbfp_workflow_settings' );
		echo '<input type="hidden" name="wbfp_require_approval" value="0">';
		echo '<label><input type="checkbox" name="wbfp_require_approval" value="1" ' . checked( (bool) get_option( 'wbfp_require_approval', false ), true, false ) . '> ' . esc_html__( 'Require independent approval before every branch merge', 'wp-branches-for-post' ) . '</label>';
		submit_button();
		echo '</form>';
	}

	/** @param int $branch_id Branch ID. @param string $operation Action. @param string $label Button. @param bool $feedback Whether to show a note input. */
	private function form( int $branch_id, string $operation, string $label, bool $feedback = false ): void {
		echo '<form method="post" style="margin:12px 0" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wbfp_workflow_' . $branch_id );
		echo '<input type="hidden" name="action" value="wbfp_workflow"><input type="hidden" name="branch_id" value="' . esc_attr( (string) $branch_id ) . '"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
		if ( $feedback ) {
			echo '<label>' . esc_html__( 'Review note', 'wp-branches-for-post' ) . ' <textarea name="note" rows="2" cols="45"></textarea></label> ';
		}
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}

	/** CSRF-protected action shared by Classic Editor and Block Editor. */
	public function admin_action(): void {
		$branch_id = isset( $_POST['branch_id'] ) ? absint( wp_unslash( $_POST['branch_id'] ) ) : 0;
		check_admin_referer( 'wbfp_workflow_' . $branch_id );
		$op   = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$note = isset( $_POST['note'] ) ? (string) wp_unslash( $_POST['note'] ) : '';
		$result = $this->operate( $branch_id, $op, $note, isset( $_POST['when'] ) ? (string) wp_unslash( $_POST['when'] ) : '' );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), esc_html__( 'Workflow action blocked', 'wp-branches-for-post' ), array( 'response' => (int) ( $result->get_error_data()['status'] ?? 400 ) ) );
		}
		wp_safe_redirect( admin_url( 'tools.php?page=wbfp-workflow&branch_id=' . $branch_id ) );
		exit;
	}

	/** Reuse the same service validation for forms and REST requests. */
	private function operate( int $branch_id, string $action, string $note = '', string $when = '' ) {
		switch ( $action ) {
			case 'request':
				return $this->request_review( $branch_id );
			case 'approve':
				return $this->decide( $branch_id, true, $note );
			case 'reject':
				return $this->decide( $branch_id, false, $note );
			case 'cancel':
				return $this->cancel( $branch_id );
			case 'schedule':
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\\TH:i', $when, wp_timezone() );
				if ( ! $date || $date->format( 'Y-m-d\\TH:i' ) !== $when ) {
					return new \WP_Error( 'wbfp_schedule_invalid', __( 'Enter a valid site-local date and time.', 'wp-branches-for-post' ), array( 'status' => 400 ) );
				}
				return $this->schedule( $branch_id, $date->getTimestamp() );
			default:
				return new \WP_Error( 'wbfp_workflow_unknown_action', __( 'Unknown workflow operation.', 'wp-branches-for-post' ), array( 'status' => 400 ) );
		}
	}

	/** Return compact workflow state to authenticated editors without exposing raw metadata. */
	public function rest_status( \WP_REST_Request $request ) {
		$branch_id = (int) $request['id'];
		if ( ! $this->can_edit( $branch_id ) ) {
			return new \WP_Error( 'wbfp_review_denied', __( 'You cannot view this workflow.', 'wp-branches-for-post' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response(
			array(
				'requires_approval' => self::requires_approval( $branch_id, $this->branches->get_original_id( $branch_id ) ),
				'state'             => (string) get_post_meta( $branch_id, self::META_STATE, true ),
				'is_approved'       => $this->is_approved( $branch_id ),
				'can_review'        => $this->can_review( $branch_id ),
				'requested_at_gmt'  => (string) get_post_meta( $branch_id, self::META_REQUESTED_AT, true ),
				'reviewed_at_gmt'   => (string) get_post_meta( $branch_id, self::META_REVIEWED_AT, true ),
				'scheduled_at_gmt'  => (string) get_post_meta( $branch_id, self::META_SCHEDULE_AT, true ),
				'schedule_error'    => (string) get_post_meta( $branch_id, self::META_SCHEDULE_ERROR, true ),
				'edit_url'          => admin_url( 'tools.php?page=wbfp-workflow&branch_id=' . $branch_id ),
			)
		);
	}

	public function register_routes(): void {
		register_rest_route(
			'wbfp/v1',
			'/branches/(?P<id>\\d+)/workflow',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => function ( \WP_REST_Request $request ): bool {
					return $this->can_edit( (int) $request['id'] );
				},
				'callback'            => array( $this, 'rest_status' ),
				'args'                => array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) ),
			)
		);
		register_rest_route(
			'wbfp/v1',
			'/branches/(?P<id>\\d+)/workflow',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => function ( \WP_REST_Request $request ): bool {
					return $this->can_edit( (int) $request['id'] );
				},
				'callback'            => function ( \WP_REST_Request $request ) {
					$result = $this->operate(
						(int) $request['id'],
						sanitize_key( (string) $request->get_param( 'operation' ) ),
						(string) $request->get_param( 'note' ),
						(string) $request->get_param( 'when' )
					);
					return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'success' => true, 'approved' => $this->is_approved( (int) $request['id'] ) ) );
				},
				'args'                => array(
					'id'        => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ),
					'operation' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'request', 'approve', 'reject', 'schedule', 'cancel' ) ),
					'note'      => array( 'type' => 'string', 'default' => '' ),
					'when'      => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
	}
}
