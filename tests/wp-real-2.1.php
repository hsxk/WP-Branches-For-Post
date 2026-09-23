<?php
/**
 * Real WordPress 7.1 integration checks for the 2.1 branch workflow.
 *
 * Run with:
 * wp eval-file wp-content/plugins/wp-branches-for-post/tests/wp-real-2.1.php
 */

use WP_Branches_For_Post\Admin;
use WP_Branches_For_Post\Branch_Service;
use WP_Branches_For_Post\Merge_Service;
use WP_Branches_For_Post\REST_Controller;
use WP_Branches_For_Post\Sync_Service;

$GLOBALS['wbfp_checks'] = 0;
$created_posts = array();
$created_users = array();
$created_terms = array();

function wbfp_check( $condition, string $message ): void {
	++$GLOBALS['wbfp_checks'];
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS {$GLOBALS['wbfp_checks']}: {$message}\n";
}

function wbfp_track_post( int $id ): int {
	global $created_posts;
	$created_posts[] = $id;
	return $id;
}

function wbfp_make_post( array $overrides = array() ): int {
	$data = array_merge(
		array(
			'post_title'   => 'Original title',
			'post_content' => 'Original content',
			'post_excerpt' => 'Original excerpt',
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_author'  => get_current_user_id(),
		),
		$overrides
	);
	$id = wp_insert_post( $data, true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	return wbfp_track_post( (int) $id );
}

function wbfp_snapshot_hash( int $post_id ): string {
	$snapshot = Sync_Service::snapshot( $post_id );
	return $snapshot ? Sync_Service::snapshot_hash_from_payload( $snapshot ) : '';
}

$admin_id = get_current_user_id();
if ( ! $admin_id ) {
	$admin_id = username_exists( 'wbfp-test-admin' );
	if ( ! $admin_id ) {
		$admin_id = wp_create_user( 'wbfp-test-admin', 'wbfp-test-password', 'wbfp-test@example.test' );
		$created_users[] = $admin_id;
	}
	$user = new WP_User( $admin_id );
	$user->set_role( 'administrator' );
}
wp_set_current_user( $admin_id );

$branches = new Branch_Service();
$merges   = new Merge_Service( $branches );
$rest     = new REST_Controller( $branches, $merges );

try {
	// 1-5: creation + baseline + branch visibility.
	$original_id = wbfp_make_post(
		array(
			'post_name' => 'wbfp-original',
		)
	);
	update_post_meta( $original_id, 'wbfp_test_meta', 'base-meta' );
	wp_set_object_terms( $original_id, array( 'Uncategorized' ), 'category', false );

	$branch_id = $branches->create( $original_id );
	wbfp_check( ! is_wp_error( $branch_id ), 'Create a branch through the real service layer' );
	$branch_id = (int) $branch_id;
	$created_posts[] = $branch_id;
	wbfp_check( 'draft' === get_post_status( $branch_id ), 'New branch is draft' );
	wbfp_check( $original_id === $branches->get_original_id( $branch_id ), 'Branch relationship points to original' );
	wbfp_check( is_array( get_post_meta( $branch_id, Branch_Service::META_BASE_SNAPSHOT, true ) ), '2.1 stores a full base snapshot' );
	wbfp_check( Branch_Service::CONFLICT_CLEAN === $branches->conflict_state( $branch_id ), 'Fresh branch starts clean' );

	wp_update_post( array( 'ID' => $branch_id, 'post_status' => 'publish' ) );
	wbfp_check( 'draft' === get_post_status( $branch_id ), 'Normal WordPress publish attempt is forced back to draft' );

	// 6-10: non-overlapping original + branch changes and selective merge.
	wp_update_post( array( 'ID' => $branch_id, 'post_content' => 'Branch content v2' ) );
	wp_update_post( array( 'ID' => $original_id, 'post_excerpt' => 'Original excerpt v2' ) );
	$analysis = $branches->analyze_branch( $branch_id );
	wbfp_check( Branch_Service::CONFLICT_REBASE === $analysis['state'], 'Non-overlapping original changes are rebase-available, not conflicts' );
	wbfp_check( empty( $analysis['analysis']['conflicts'] ), 'Non-overlapping edits produce zero conflicts' );

	$result = $merges->merge( $branch_id, false );
	wbfp_check( $original_id === $result, 'Normal three-way merge succeeds without forcing' );
	$original = get_post( $original_id );
	wbfp_check( 'Branch content v2' === $original->post_content, 'Branch-only content change is applied' );
	wbfp_check( 'Original excerpt v2' === $original->post_excerpt, 'Original-only excerpt change is preserved' );
	wbfp_check( 'trash' === get_post_status( $branch_id ), 'Merged branch moves to Trash' );

	// 11-15: automatic rebase preserves both sides.
	$rebase_original = wbfp_make_post();
	$rebase_branch   = $branches->create( $rebase_original );
	wbfp_check( ! is_wp_error( $rebase_branch ), 'Create branch for rebase scenario' );
	$rebase_branch = (int) $rebase_branch;
	$created_posts[] = $rebase_branch;
	wp_update_post( array( 'ID' => $rebase_branch, 'post_content' => 'Branch rebase content' ) );
	wp_update_post( array( 'ID' => $rebase_original, 'post_excerpt' => 'Original rebase excerpt' ) );
	wbfp_check( Branch_Service::CONFLICT_REBASE === $branches->conflict_state( $rebase_branch ), 'Rebase scenario detected' );
	$rebased = $branches->rebase( $rebase_branch );
	wbfp_check( $rebase_branch === $rebased, 'Update branch from original succeeds' );
	$rebased_post = get_post( $rebase_branch );
	wbfp_check( 'Branch rebase content' === $rebased_post->post_content, 'Rebase preserves branch content' );
	wbfp_check( 'Original rebase excerpt' === $rebased_post->post_excerpt, 'Rebase imports original-only excerpt' );
	wbfp_check( Branch_Service::CONFLICT_CLEAN === $branches->conflict_state( $rebase_branch ), 'Rebased branch gets a fresh clean baseline' );

	// 16-21: true conflict and force merge semantics.
	$conflict_original = wbfp_make_post();
	$conflict_branch   = $branches->create( $conflict_original );
	wbfp_check( ! is_wp_error( $conflict_branch ), 'Create branch for conflict scenario' );
	$conflict_branch = (int) $conflict_branch;
	$created_posts[] = $conflict_branch;
	wp_update_post( array( 'ID' => $conflict_original, 'post_title' => 'Original competing title' ) );
	wp_update_post( array( 'ID' => $conflict_branch, 'post_title' => 'Branch competing title' ) );
	$conflict_analysis = $branches->analyze_branch( $conflict_branch );
	wbfp_check( Branch_Service::CONFLICT_CONFLICT === $conflict_analysis['state'], 'Same-field divergent edits become a real conflict' );
	wbfp_check( in_array( 'post.post_title', $conflict_analysis['analysis']['conflicts'], true ), 'Conflict identifies the exact title path' );
	$blocked = $merges->merge( $conflict_branch, false );
	wbfp_check( is_wp_error( $blocked ) && 'wbfp_merge_conflict' === $blocked->get_error_code(), 'Normal merge is blocked on true conflict' );

	$deny_force = static function () {
		return false;
	};
	add_filter( 'wbfp_can_force_merge', $deny_force, 10, 4 );
	$denied = $merges->merge( $conflict_branch, true );
	wbfp_check( is_wp_error( $denied ) && 'wbfp_cannot_force_merge' === $denied->get_error_code(), 'Force merge permission filter is enforced' );
	remove_filter( 'wbfp_can_force_merge', $deny_force, 10 );

	$forced = $merges->merge( $conflict_branch, true );
	wbfp_check( $conflict_original === $forced, 'Authorized force merge succeeds' );
	wbfp_check( 'Branch competing title' === get_post( $conflict_original )->post_title, 'Force merge prefers branch value on the reviewed conflict' );

	// 22-26: hierarchical URL identity preservation.
	$parent_a = wbfp_make_post( array( 'post_type' => 'page', 'post_title' => 'Parent A', 'post_name' => 'parent-a' ) );
	$parent_b = wbfp_make_post( array( 'post_type' => 'page', 'post_title' => 'Parent B', 'post_name' => 'parent-b' ) );
	$page_id  = wbfp_make_post(
		array(
			'post_type'   => 'page',
			'post_title'  => 'Child page',
			'post_name'   => 'child-page',
			'post_parent' => $parent_a,
		)
	);
	$page_uri_before = get_page_uri( $page_id );
	$page_branch     = $branches->create( $page_id );
	wbfp_check( ! is_wp_error( $page_branch ), 'Create hierarchical page branch' );
	$page_branch = (int) $page_branch;
	$created_posts[] = $page_branch;
	wp_update_post(
		array(
			'ID'          => $page_branch,
			'post_parent' => $parent_b,
			'post_content'=> 'Changed child content',
		)
	);
	$page_merge = $merges->merge( $page_branch, false );
	wbfp_check( $page_id === $page_merge, 'Page branch merges successfully' );
	wbfp_check( $parent_a === (int) get_post( $page_id )->post_parent, 'Merge never applies branch post_parent' );
	wbfp_check( $page_uri_before === get_page_uri( $page_id ), 'Hierarchical page URI is preserved' );
	wbfp_check( 'Changed child content' === get_post( $page_id )->post_content, 'Page content still merges while parent stays fixed' );

	// 27-31: identity-only original changes are informational and preserved.
	$identity_original = wbfp_make_post( array( 'post_name' => 'identity-base' ) );
	$identity_branch   = $branches->create( $identity_original );
	wbfp_check( ! is_wp_error( $identity_branch ), 'Create branch for identity-only changes' );
	$identity_branch = (int) $identity_branch;
	$created_posts[] = $identity_branch;
	wp_update_post( array( 'ID' => $identity_original, 'post_name' => 'identity-new' ) );
	$identity_analysis = $branches->analyze_branch( $identity_branch );
	wbfp_check( Branch_Service::CONFLICT_INFORMATIONAL === $identity_analysis['state'], 'Slug-only original edit is informational, not a content conflict' );
	wp_update_post( array( 'ID' => $identity_branch, 'post_content' => 'Identity-safe branch content' ) );
	$identity_merge = $merges->merge( $identity_branch, false );
	wbfp_check( $identity_original === $identity_merge, 'Informational identity changes do not block normal merge' );
	wbfp_check( 'identity-new' === get_post( $identity_original )->post_name, 'Original slug is preserved' );
	wbfp_check( 'Identity-safe branch content' === get_post( $identity_original )->post_content, 'Branch content applies alongside preserved identity' );

	// 32-36: rollback after an injected meta write failure.
	$rollback_original = wbfp_make_post();
	update_post_meta( $rollback_original, 'wbfp_test_meta', 'base-value' );
	$rollback_branch = $branches->create( $rollback_original );
	wbfp_check( ! is_wp_error( $rollback_branch ), 'Create branch for merge rollback test' );
	$rollback_branch = (int) $rollback_branch;
	$created_posts[] = $rollback_branch;
	update_post_meta( $rollback_branch, 'wbfp_test_meta', 'branch-value' );
	wp_update_post( array( 'ID' => $rollback_branch, 'post_content' => 'Branch content that must roll back' ) );
	$before_hash = wbfp_snapshot_hash( $rollback_original );
	$failed_once = false;
	$fail_add = static function ( $check, $object_id, $meta_key ) use ( $rollback_original, &$failed_once ) {
		if ( ! $failed_once && (int) $object_id === $rollback_original && 'wbfp_test_meta' === $meta_key ) {
			$failed_once = true;
			return false;
		}
		return $check;
	};
	add_filter( 'add_post_metadata', $fail_add, 10, 3 );
	$rollback_result = $merges->merge( $rollback_branch, false );
	remove_filter( 'add_post_metadata', $fail_add, 10 );
	wbfp_check( is_wp_error( $rollback_result ) && 'wbfp_merge_rolled_back' === $rollback_result->get_error_code(), 'Injected merge failure reports a successful rollback' );
	wbfp_check( $before_hash === wbfp_snapshot_hash( $rollback_original ), 'Original mergeable state exactly matches pre-merge snapshot after rollback' );
	wbfp_check( 'draft' === get_post_status( $rollback_branch ), 'Failed merge keeps branch active instead of trashing it' );

	// 37-40: rollback after an injected rebase write failure.
	$rebase_rollback_original = wbfp_make_post();
	update_post_meta( $rebase_rollback_original, 'wbfp_test_meta', 'base-rebase' );
	$rebase_rollback_branch = $branches->create( $rebase_rollback_original );
	wbfp_check( ! is_wp_error( $rebase_rollback_branch ), 'Create branch for rebase rollback test' );
	$rebase_rollback_branch = (int) $rebase_rollback_branch;
	$created_posts[] = $rebase_rollback_branch;
	wp_update_post( array( 'ID' => $rebase_rollback_original, 'post_excerpt' => 'Original changed before failing rebase' ) );
	update_post_meta( $rebase_rollback_branch, 'wbfp_test_meta', 'branch-rebase' );
	$branch_before_hash = wbfp_snapshot_hash( $rebase_rollback_branch );
	$failed_rebase_once = false;
	$fail_rebase_add = static function ( $check, $object_id, $meta_key ) use ( $rebase_rollback_branch, &$failed_rebase_once ) {
		if ( ! $failed_rebase_once && (int) $object_id === $rebase_rollback_branch && 'wbfp_test_meta' === $meta_key ) {
			$failed_rebase_once = true;
			return false;
		}
		return $check;
	};
	add_filter( 'add_post_metadata', $fail_rebase_add, 10, 3 );
	$rebase_rollback_result = $branches->rebase( $rebase_rollback_branch );
	remove_filter( 'add_post_metadata', $fail_rebase_add, 10 );
	wbfp_check( is_wp_error( $rebase_rollback_result ) && 'wbfp_rebase_rolled_back' === $rebase_rollback_result->get_error_code(), 'Injected rebase failure reports rollback' );
	wbfp_check( $branch_before_hash === wbfp_snapshot_hash( $rebase_rollback_branch ), 'Branch mergeable state is restored after failed rebase' );



	// Rebase freshness: original changes after analysis must be detected before branch writes.
	$rebase_race_original = wbfp_make_post();
	$rebase_race_branch   = $branches->create( $rebase_race_original );
	wbfp_check( ! is_wp_error( $rebase_race_branch ), 'Create branch for rebase freshness race test' );
	$rebase_race_branch = (int) $rebase_race_branch;
	$created_posts[] = $rebase_race_branch;
	wp_update_post( array( 'ID' => $rebase_race_branch, 'post_content' => 'Branch content before rebase race' ) );
	wp_update_post( array( 'ID' => $rebase_race_original, 'post_excerpt' => 'Original change that makes rebase available' ) );
	$rebase_race_before = wbfp_snapshot_hash( $rebase_race_branch );
	$rebase_field_calls = 0;
	$inject_rebase_race = static function ( $fields ) use ( &$rebase_field_calls, $rebase_race_original ) {
		++$rebase_field_calls;
		if ( 3 === $rebase_field_calls ) {
			wp_update_post( array( 'ID' => $rebase_race_original, 'post_title' => 'Concurrent original change during rebase' ) );
		}
		return $fields;
	};
	add_filter( 'wbfp_mergeable_post_fields', $inject_rebase_race, 999 );
	$rebase_race_result = $branches->rebase( $rebase_race_branch );
	remove_filter( 'wbfp_mergeable_post_fields', $inject_rebase_race, 999 );
	wbfp_check( is_wp_error( $rebase_race_result ) && 'wbfp_rebase_state_changed' === $rebase_race_result->get_error_code(), 'Rebase rejects original changes that arrive after analysis' );
	wbfp_check( $rebase_race_before === wbfp_snapshot_hash( $rebase_race_branch ), 'Stale rebase rejection leaves branch state unchanged' );
	wbfp_check( 'Concurrent original change during rebase' === get_post( $rebase_race_original )->post_title, 'Concurrent original change remains untouched after rebase rejection' );
	wbfp_check( Branch_Service::CONFLICT_REBASE === $branches->conflict_state( $rebase_race_branch ), 'Rejected rebase remains available for a fresh review' );

	// Baseline metadata failure after a successful rebase write must roll everything back.
	$baseline_original = wbfp_make_post();
	$baseline_branch   = $branches->create( $baseline_original );
	wbfp_check( ! is_wp_error( $baseline_branch ), 'Create branch for baseline metadata rollback test' );
	$baseline_branch = (int) $baseline_branch;
	$created_posts[] = $baseline_branch;
	wp_update_post( array( 'ID' => $baseline_branch, 'post_content' => 'Branch content before baseline failure' ) );
	wp_update_post( array( 'ID' => $baseline_original, 'post_excerpt' => 'Original excerpt before baseline failure' ) );
	$baseline_branch_before = wbfp_snapshot_hash( $baseline_branch );
	$baseline_snapshot_before = $branches->get_base_snapshot( $baseline_branch );
	$baseline_hash_before = (string) get_post_meta( $baseline_branch, Branch_Service::META_BASE_HASH, true );
	$blocked_baseline_write = false;
	$fail_baseline_update = static function ( $check, $object_id, $meta_key ) use ( $baseline_branch, &$blocked_baseline_write ) {
		if ( ! $blocked_baseline_write && $baseline_branch === (int) $object_id && Branch_Service::META_BASE_SNAPSHOT === $meta_key ) {
			$blocked_baseline_write = true;
			return false;
		}
		return $check;
	};
	add_filter( 'update_post_metadata', $fail_baseline_update, 10, 3 );
	$baseline_rebase_result = $branches->rebase( $baseline_branch );
	remove_filter( 'update_post_metadata', $fail_baseline_update, 10 );
	wbfp_check( is_wp_error( $baseline_rebase_result ) && 'wbfp_rebase_baseline_rolled_back' === $baseline_rebase_result->get_error_code(), 'Failed baseline metadata write reports a fully rolled-back rebase' );
	wbfp_check( $baseline_branch_before === wbfp_snapshot_hash( $baseline_branch ), 'Branch content/meta/taxonomy state is restored after baseline write failure' );
	wbfp_check( $baseline_hash_before === (string) get_post_meta( $baseline_branch, Branch_Service::META_BASE_HASH, true ), 'Previous baseline hash is restored after failed rebase' );
	wbfp_check( Sync_Service::state_hash_from_payload( $baseline_snapshot_before ) === Sync_Service::state_hash_from_payload( $branches->get_base_snapshot( $baseline_branch ) ), 'Previous full baseline snapshot is restored after failed rebase' );
	wbfp_check( Branch_Service::CONFLICT_REBASE === $branches->conflict_state( $baseline_branch ), 'Rolled-back branch still reports the original non-conflicting update as available' );

	// 41-44: synced pattern detection.
	$pattern_id = wbfp_make_post(
		array(
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_title'   => 'Shared pattern',
			'post_content' => '<!-- wp:paragraph --><p>Shared</p><!-- /wp:paragraph -->',
		)
	);
	$pattern_original = wbfp_make_post();
	$pattern_branch   = $branches->create( $pattern_original );
	wbfp_check( ! is_wp_error( $pattern_branch ), 'Create branch for synced-pattern warning test' );
	$pattern_branch = (int) $pattern_branch;
	$created_posts[] = $pattern_branch;
	wp_update_post(
		array(
			'ID'           => $pattern_branch,
			'post_content' => '<!-- wp:block {"ref":' . $pattern_id . '} /-->',
		)
	);
	$refs = Sync_Service::synced_pattern_refs( $pattern_branch );
	wbfp_check( in_array( $pattern_id, $refs, true ), 'Synced pattern reference is detected from real block markup' );

	// 45-49: REST review payload.
	$request = new WP_REST_Request( 'GET', '/wbfp/v1/posts/' . $pattern_branch . '/status' );
	$request->set_param( 'id', $pattern_branch );
	wbfp_check( $rest->can_read_status( $request ), 'Authorized user may read branch status' );
	$response = $rest->status( $request );
	$data = $response->get_data();
	wbfp_check( 'branch' === $data['type'], 'REST status identifies branch' );
	wbfp_check( 1 === $data['synced_pattern_count'], 'REST status reports synced pattern count' );
	wbfp_check( isset( $data['review']['branch_changes'] ), 'REST status includes three-way review paths' );

	// 50-53: legacy compatibility.
	$legacy_original = wbfp_make_post();
	$legacy_branch = wbfp_make_post(
		array(
			'post_status'  => 'draft',
			'post_content' => 'Legacy branch content',
		)
	);
	update_post_meta( $legacy_branch, '_original_post_id', $legacy_original );
	update_post_meta( $legacy_branch, Branch_Service::META_BASE_HASH, Sync_Service::legacy_snapshot_hash( $legacy_original ) );
	wbfp_check( Branch_Service::CONFLICT_CLEAN === $branches->conflict_state( $legacy_branch ), '2.0-style branch remains clean when original is unchanged' );
	$legacy_merge = $merges->merge( $legacy_branch, false );
	wbfp_check( $legacy_original === $legacy_merge, 'Clean legacy branch still merges normally' );
	wbfp_check( 'Legacy branch content' === get_post( $legacy_original )->post_content, 'Legacy branch content is copied to original' );

	// 54-57: unauthorized create and discard behavior.
	$contributor_id = username_exists( 'wbfp-test-contributor' );
	if ( ! $contributor_id ) {
		$contributor_id = wp_create_user( 'wbfp-test-contributor', 'wbfp-test-password', 'wbfp-contributor@example.test' );
		$created_users[] = $contributor_id;
	}
	$contributor = new WP_User( $contributor_id );
	$contributor->set_role( 'contributor' );
	$private_original = wbfp_make_post( array( 'post_author' => $admin_id ) );
	wp_set_current_user( $contributor_id );
	wbfp_check( ! $branches->can_create( $private_original ), 'Contributor cannot branch another author\'s post without edit capability' );
	wp_set_current_user( $admin_id );

	$discard_original = wbfp_make_post();
	$discard_branch = $branches->create( $discard_original );
	wbfp_check( ! is_wp_error( $discard_branch ), 'Create branch for discard test' );
	$discard_branch = (int) $discard_branch;
	$created_posts[] = $discard_branch;
	$discarded = $merges->discard( $discard_branch );
	wbfp_check( true === $discarded, 'Discard succeeds for authorized user' );
	wbfp_check( 'trash' === get_post_status( $discard_branch ), 'Discard moves branch to Trash' );


	// Review-token freshness: a user cannot merge a state different from the one reviewed.
	$token_original = wbfp_make_post();
	$token_branch   = $branches->create( $token_original );
	wbfp_check( ! is_wp_error( $token_branch ), 'Create branch for reviewed-state token test' );
	$token_branch = (int) $token_branch;
	$created_posts[] = $token_branch;
	wp_update_post( array( 'ID' => $token_branch, 'post_content' => 'Reviewed token branch content' ) );

	$token_request = new WP_REST_Request( 'GET', '/wbfp/v1/posts/' . $token_branch . '/status' );
	$token_request->set_param( 'id', $token_branch );
	$token_status = $rest->status( $token_request )->get_data();
	wbfp_check( ! empty( $token_status['review_token'] ), 'REST status exposes a non-empty review token' );
	$stale_token = (string) $token_status['review_token'];

	wp_update_post( array( 'ID' => $token_branch, 'post_excerpt' => 'Changed after the prior review' ) );
	$fresh_status = $rest->status( $token_request )->get_data();
	wbfp_check( $stale_token !== (string) $fresh_status['review_token'], 'Review token changes when branch state changes' );

	$stale_merge_request = new WP_REST_Request( 'POST', '/wbfp/v1/branches/' . $token_branch . '/merge' );
	$stale_merge_request->set_param( 'id', $token_branch );
	$stale_merge_request->set_param( 'force', false );
	$stale_merge_request->set_param( 'review_token', $stale_token );
	$stale_merge = $rest->merge_branch( $stale_merge_request );
	wbfp_check( is_wp_error( $stale_merge ) && 'wbfp_review_state_changed' === $stale_merge->get_error_code(), 'REST merge rejects a stale reviewed-state token' );
	wbfp_check( 'draft' === get_post_status( $token_branch ), 'Stale-token rejection leaves the branch active' );

	// Stale-write race: change the original after the second analysis but before apply.
	$race_original = wbfp_make_post();
	$race_branch   = $branches->create( $race_original );
	wbfp_check( ! is_wp_error( $race_branch ), 'Create branch for merge race test' );
	$race_branch = (int) $race_branch;
	$created_posts[] = $race_branch;
	wp_update_post( array( 'ID' => $race_branch, 'post_content' => 'Race-test branch content' ) );
	$mergeable_calls = 0;
	$inject_race = static function ( $fields ) use ( &$mergeable_calls, $race_original ) {
		++$mergeable_calls;
		if ( 4 === $mergeable_calls ) {
			wp_update_post( array( 'ID' => $race_original, 'post_excerpt' => 'Concurrent original save during merge' ) );
		}
		return $fields;
	};
	add_filter( 'wbfp_mergeable_post_fields', $inject_race, 999 );
	$race_result = $merges->merge( $race_branch, false );
	remove_filter( 'wbfp_mergeable_post_fields', $inject_race, 999 );
	wbfp_check( is_wp_error( $race_result ) && 'wbfp_review_state_changed' === $race_result->get_error_code(), 'Service layer rejects an original that changes after review analysis' );
	wbfp_check( 'Concurrent original save during merge' === get_post( $race_original )->post_excerpt, 'Concurrent original change is preserved when stale merge is rejected' );
	wbfp_check( 'draft' === get_post_status( $race_branch ), 'Race rejection keeps the branch active for a fresh review' );

	// Branch cleanup failure: original must roll back instead of returning a partial success.
	$cleanup_original = wbfp_make_post();
	$cleanup_branch   = $branches->create( $cleanup_original );
	wbfp_check( ! is_wp_error( $cleanup_branch ), 'Create branch for post-merge cleanup rollback test' );
	$cleanup_branch = (int) $cleanup_branch;
	$created_posts[] = $cleanup_branch;
	wp_update_post( array( 'ID' => $cleanup_branch, 'post_content' => 'Content that must not remain after cleanup failure' ) );
	$cleanup_before = wbfp_snapshot_hash( $cleanup_original );
	$prevent_trash = static function ( $trash, $post ) use ( $cleanup_branch ) {
		if ( $post instanceof WP_Post && $cleanup_branch === (int) $post->ID ) {
			return false;
		}
		return $trash;
	};
	add_filter( 'pre_trash_post', $prevent_trash, 10, 2 );
	$cleanup_result = $merges->merge( $cleanup_branch, false );
	remove_filter( 'pre_trash_post', $prevent_trash, 10 );
	wbfp_check( is_wp_error( $cleanup_result ) && 'wbfp_merge_cleanup_rolled_back' === $cleanup_result->get_error_code(), 'Trash failure rolls the original back instead of returning success' );
	wbfp_check( $cleanup_before === wbfp_snapshot_hash( $cleanup_original ), 'Original state exactly matches its pre-merge snapshot after cleanup rollback' );
	wbfp_check( 'draft' === get_post_status( $cleanup_branch ), 'Cleanup rollback leaves the branch active' );

	// Classic Editor and list actions follow the same 2.1 state semantics.
	$admin_ui = new Admin( $branches, $merges );
	$ui_safe_original = wbfp_make_post();
	$ui_safe_branch   = $branches->create( $ui_safe_original );
	wbfp_check( ! is_wp_error( $ui_safe_branch ), 'Create branch for safe Classic Editor action test' );
	$ui_safe_branch = (int) $ui_safe_branch;
	$created_posts[] = $ui_safe_branch;
	wp_update_post( array( 'ID' => $ui_safe_original, 'post_excerpt' => 'Newer non-conflicting original work' ) );
	$safe_actions = $admin_ui->row_actions( array(), get_post( $ui_safe_branch ) );
	wbfp_check( isset( $safe_actions['wbfp_merge'] ) && ! isset( $safe_actions['wbfp_review'] ), 'List row keeps normal merge for rebase-available non-conflicting state' );

	$previous_post = $GLOBALS['post'] ?? null;
	$GLOBALS['post'] = get_post( $ui_safe_branch );
	ob_start();
	$admin_ui->classic_editor_actions();
	$safe_classic = (string) ob_get_clean();
	$GLOBALS['post'] = $previous_post;
	wbfp_check( false === strpos( $safe_classic, 'force=1' ) && false !== strpos( $safe_classic, 'Merge into original' ), 'Classic Editor does not force-merge a safe rebase-available state' );

	$ui_conflict_original = wbfp_make_post();
	$ui_conflict_branch   = $branches->create( $ui_conflict_original );
	wbfp_check( ! is_wp_error( $ui_conflict_branch ), 'Create branch for conflicting Classic Editor action test' );
	$ui_conflict_branch = (int) $ui_conflict_branch;
	$created_posts[] = $ui_conflict_branch;
	wp_update_post( array( 'ID' => $ui_conflict_original, 'post_title' => 'Original UI conflict' ) );
	wp_update_post( array( 'ID' => $ui_conflict_branch, 'post_title' => 'Branch UI conflict' ) );
	$conflict_actions = $admin_ui->row_actions( array(), get_post( $ui_conflict_branch ) );
	wbfp_check( isset( $conflict_actions['wbfp_review'] ) && ! isset( $conflict_actions['wbfp_merge'] ), 'List row routes true conflicts back to review instead of direct merge' );

	$previous_post = $GLOBALS['post'] ?? null;
	$GLOBALS['post'] = get_post( $ui_conflict_branch );
	ob_start();
	$admin_ui->classic_editor_actions();
	$conflict_classic = (string) ob_get_clean();
	$GLOBALS['post'] = $previous_post;
	wbfp_check( false !== strpos( $conflict_classic, 'force=1' ) && false !== strpos( $conflict_classic, 'Force merge after review' ), 'Classic Editor exposes force merge only for a hard reviewed conflict' );


	// Promised source statuses: private and scheduled originals are branchable.
	$private_original = wbfp_make_post( array( 'post_status' => 'private', 'post_title' => 'Private branch source' ) );
	$private_branch   = $branches->create( $private_original );
	wbfp_check( ! is_wp_error( $private_branch ), 'Private original can create a working branch' );
	$private_branch = (int) $private_branch;
	$created_posts[] = $private_branch;
	wbfp_check( 'draft' === get_post_status( $private_branch ), 'Branch created from a private original is still isolated as draft' );

	$future_original = wbfp_make_post(
		array(
			'post_status'   => 'future',
			'post_title'    => 'Scheduled branch source',
			'post_date'     => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
		)
	);
	$future_branch = $branches->create( $future_original );
	wbfp_check( ! is_wp_error( $future_branch ), 'Scheduled original can create a working branch' );
	$future_branch = (int) $future_branch;
	$created_posts[] = $future_branch;
	wbfp_check( 'draft' === get_post_status( $future_branch ), 'Branch created from a scheduled original is draft' );

	// Custom post type, custom taxonomy, custom meta and featured-image metadata.
	register_post_type(
		'wbfp_story',
		array(
			'public'       => true,
			'show_in_rest' => true,
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
			'capability_type' => 'post',
			'map_meta_cap' => true,
		)
	);
	register_taxonomy(
		'wbfp_topic',
		array( 'wbfp_story' ),
		array(
			'public'       => true,
			'show_in_rest' => true,
			'hierarchical' => false,
		)
	);
	$topic = wp_insert_term( 'Branch taxonomy topic', 'wbfp_topic' );
	if ( is_wp_error( $topic ) ) {
		throw new RuntimeException( $topic->get_error_message() );
	}
	$created_terms[] = array( 'taxonomy' => 'wbfp_topic', 'term_id' => (int) $topic['term_id'] );

	$thumb_a = wp_insert_attachment(
		array(
			'post_title'     => 'Featured image A',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
		)
	);
	$thumb_b = wp_insert_attachment(
		array(
			'post_title'     => 'Featured image B',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
		)
	);
	if ( is_wp_error( $thumb_a ) || is_wp_error( $thumb_b ) ) {
		throw new RuntimeException( 'Could not create attachment fixtures.' );
	}
	$thumb_a = wbfp_track_post( (int) $thumb_a );
	$thumb_b = wbfp_track_post( (int) $thumb_b );

	$cpt_original = wbfp_make_post(
		array(
			'post_type'    => 'wbfp_story',
			'post_title'   => 'Custom story',
			'post_content' => 'Custom story original content',
		)
	);
	update_post_meta( $cpt_original, 'wbfp_custom_meta', 'custom-meta-base' );
	update_post_meta( $cpt_original, 'wbfp_remove_meta', 'remove-me-on-branch' );
	add_post_meta( $cpt_original, 'wbfp_multi_meta', 'multi-one' );
	add_post_meta( $cpt_original, 'wbfp_multi_meta', 'multi-two' );
	update_post_meta( $cpt_original, '_thumbnail_id', $thumb_a );
	wp_set_object_terms( $cpt_original, array( (int) $topic['term_id'] ), 'wbfp_topic', false );

	$cpt_branch = $branches->create( $cpt_original );
	wbfp_check( ! is_wp_error( $cpt_branch ), 'Supported custom post type creates a branch through the normal service' );
	$cpt_branch = (int) $cpt_branch;
	$created_posts[] = $cpt_branch;
	wbfp_check( 'wbfp_story' === get_post_type( $cpt_branch ), 'Custom post type identity is retained on the branch' );
	wbfp_check( 'custom-meta-base' === get_post_meta( $cpt_branch, 'wbfp_custom_meta', true ), 'Custom post metadata is copied to the branch' );
	wbfp_check( 'remove-me-on-branch' === get_post_meta( $cpt_branch, 'wbfp_remove_meta', true ), 'Removable custom metadata is copied to the branch' );
	wbfp_check( array( 'multi-one', 'multi-two' ) === array_values( get_post_meta( $cpt_branch, 'wbfp_multi_meta', false ) ), 'Multi-value metadata is copied without collapsing values' );
	wbfp_check( $thumb_a === (int) get_post_meta( $cpt_branch, '_thumbnail_id', true ), 'Featured-image attachment ID is copied to the branch' );
	$cpt_branch_terms = wp_get_object_terms( $cpt_branch, 'wbfp_topic', array( 'fields' => 'ids' ) );
	wbfp_check( array( (int) $topic['term_id'] ) === array_map( 'intval', $cpt_branch_terms ), 'Custom taxonomy assignment is copied to the branch' );

	wp_update_post( array( 'ID' => $cpt_branch, 'post_content' => 'Custom story branch content' ) );
	update_post_meta( $cpt_branch, 'wbfp_custom_meta', 'custom-meta-branch' );
	delete_post_meta( $cpt_branch, 'wbfp_remove_meta' );
	delete_post_meta( $cpt_branch, 'wbfp_multi_meta' );
	add_post_meta( $cpt_branch, 'wbfp_multi_meta', 'multi-three' );
	add_post_meta( $cpt_branch, 'wbfp_multi_meta', 'multi-four' );
	update_post_meta( $cpt_branch, '_thumbnail_id', $thumb_b );
	wp_set_object_terms( $cpt_branch, array(), 'wbfp_topic', false );
	$cpt_merge = $merges->merge( $cpt_branch, false );
	wbfp_check( $cpt_original === $cpt_merge, 'Custom post type branch merges normally' );
	wbfp_check( 'Custom story branch content' === get_post( $cpt_original )->post_content, 'Custom post type editorial content merges' );
	wbfp_check( 'custom-meta-branch' === get_post_meta( $cpt_original, 'wbfp_custom_meta', true ), 'Custom metadata changes merge back to the original' );
	wbfp_check( ! metadata_exists( 'post', $cpt_original, 'wbfp_remove_meta' ), 'Removing custom metadata on the branch removes it from the original on merge' );
	wbfp_check( array( 'multi-three', 'multi-four' ) === array_values( get_post_meta( $cpt_original, 'wbfp_multi_meta', false ) ), 'Multi-value metadata is replaced exactly during merge' );
	wbfp_check( ! metadata_exists( 'post', $cpt_original, Branch_Service::META_ORIGINAL_ID ), 'Branch relationship metadata never leaks onto the original' );
	wbfp_check( $thumb_b === (int) get_post_meta( $cpt_original, '_thumbnail_id', true ), 'Featured-image metadata merges without changing attachment identity' );
	$cpt_original_terms = wp_get_object_terms( $cpt_original, 'wbfp_topic', array( 'fields' => 'ids' ) );
	wbfp_check( empty( $cpt_original_terms ), 'Clearing a custom taxonomy on the branch clears it on merge' );

	echo "\nREAL WORDPRESS RESULT: {$GLOBALS['wbfp_checks']} / {$GLOBALS['wbfp_checks']} checks passed.\n";
} finally {
	wp_set_current_user( $admin_id );
	foreach ( array_unique( array_map( 'intval', $created_posts ) ) as $post_id ) {
		if ( $post_id > 0 && get_post( $post_id ) ) {
			wp_delete_post( $post_id, true );
		}
	}
	foreach ( $created_terms as $term ) {
		if ( ! empty( $term['term_id'] ) && ! empty( $term['taxonomy'] ) ) {
			wp_delete_term( (int) $term['term_id'], (string) $term['taxonomy'] );
		}
	}
	foreach ( array_unique( array_map( 'intval', $created_users ) ) as $user_id ) {
		if ( $user_id > 0 && get_userdata( $user_id ) ) {
			wp_delete_user( $user_id );
		}
	}
}
