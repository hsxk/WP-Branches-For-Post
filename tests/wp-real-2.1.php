<?php
/**
 * Real WordPress 7.1 integration checks for the 2.1 branch workflow.
 *
 * Run with:
 * wp eval-file wp-content/plugins/wp-branches-for-post/tests/wp-real-2.1.php
 */

use WP_Branches_For_Post\Branch_Service;
use WP_Branches_For_Post\Merge_Service;
use WP_Branches_For_Post\REST_Controller;
use WP_Branches_For_Post\Sync_Service;

$GLOBALS['wbfp_checks'] = 0;
$created_posts = array();
$created_users = array();

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

	echo "\nREAL WORDPRESS RESULT: {$GLOBALS['wbfp_checks']} / {$GLOBALS['wbfp_checks']} checks passed.\n";
} finally {
	wp_set_current_user( $admin_id );
	foreach ( array_unique( array_map( 'intval', $created_posts ) ) as $post_id ) {
		if ( $post_id > 0 && get_post( $post_id ) ) {
			wp_delete_post( $post_id, true );
		}
	}
	foreach ( array_unique( array_map( 'intval', $created_users ) ) as $user_id ) {
		if ( $user_id > 0 && get_userdata( $user_id ) ) {
			wp_delete_user( $user_id );
		}
	}
}
