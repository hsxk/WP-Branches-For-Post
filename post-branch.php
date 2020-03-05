<?php
/*
Plugin Name: WP-Branches-For-Post
Plugin URI: https://github.com/hsxk/WP-Branches-For-Post/
Description: Creating branches of publishing post to modify and publish them without affecting them in Public
Version: 1.0.1
Author: Haokexin
Author URI: hkx.monster
License: GNU General Public License v3.0
*/


/*
*** For Classic Editor ***
Show button when post is public, future, privacy status
But the button is not showed for branches that have already made future
*/
function wbfp_add_post_submitbox_button() {
	global $post;
	$show_button_which_post_status = array(	'publish', 
						'future', 
						'private'	
						);
	if ( in_array( $post->post_status, $show_button_which_post_status ) && $post->ID != 0 ) {
		if ( !get_post_meta( $post->ID, '_original_post_id', true ) ) {
			echo '<div><input type="submit" class="button-primary" id="create_branch" name="create_branch" value="Create Branch" /></div>';
		}
	}
}
add_action( 'post_submitbox_start', 'wbfp_add_post_submitbox_button' );

/*
Create a branch before an existing post is updated in the database
When $_POST including the [create_branch]
*/
function wbfp_create_post_branch( $id ) {
	if ( isset( $_POST['create_branch'] ) ) {
		$origin = get_post( $id, ARRAY_A );
		unset( $origin['ID'] );
		$origin['post_status'] = 'draft';
		$origin['post_name']   = $origin['post_name'] . '-branch';
		$branch_id = wp_insert_post( $origin );
		wbfp_copy_post_meta( $id, $branch_id );
		wbfp_copy_post_taxonomies( $id, $branch_id, $origin['post_type'] );
		add_post_meta($branch_id, '_original_post_id', $id);
		$user = wp_get_current_user();
		add_post_meta($branch_id, '_creator_name', $user->display_name, true );
		add_post_meta($branch_id, '_creator_user_id', $user->ID, true );
		if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
			wp_safe_redirect( admin_url( 'post.php?post=' . $branch_id . '&action=edit' ) );
			exit;
		}
	}
}
add_filter( 'pre_post_update', 'wbfp_create_post_branch' );
	
/*
Show info on branch post/pages editor
*/
function wbfp_post_branch_admin_notice() {
	global $pagenow;
	if ( $pagenow == 'post.php' ) {
		$branch_id = get_the_ID();
		if ( $original_id = get_post_meta( $branch_id, '_original_post_id', true ) ) {
			$creator_name = get_post_meta( $branch_id, '_creator_name', true );
			echo '<div class="updated fade" style="text-align:center; color:blue;"><p>' . sprintf( "The post is a branch of <a href='%s' target='__blank' >%s</a>.   Branch creator is %s ",  get_permalink($original_id), $original_id, $creator_name ) . '</p></div>';
		}
	}
}
add_action( 'admin_notices', 'wbfp_post_branch_admin_notice' );

/*
Add hooks for custom post_type
*/
function wbfp_add_custom_post_type_update_hooks() {
	$additional_post_types = get_post_types( array( '_builtin' => false, 'show_ui' => true ) );
	foreach ( $additional_post_types as $post_type ) {
		add_action( 'publish_' . $post_type, 'original_post_pages_update', 9999, 2 );
	}
}
add_action( 'init', 'wbfp_add_custom_post_type_update_hooks', 9999 );

/*
Update post/page when branch published
*/
function wbfp_original_post_pages_update( $id, $post ) {
	if ( $original_id = get_post_meta( $id, '_original_post_id', true ) ) {
		$post = $post->to_array();
		$post['ID'] = $original_id;
		$post['post_status'] = 'publish';
		unset(	$post['comment_count'], 
			$post['post_name'] 
			);
		$error_detection = wp_update_post( $post, true );
		if( is_wp_error( $error_detection ) ) {
			$error_info[] = '<div>';
			$error_info[] = 'Some errors occurred while updating the content.' . PHP_EOL;
			$error_info[] = 'Error message:' . PHP_EOL;
			$error_info[] = $error_detection->get_error_message() . PHP_EOL;
			$error_info[] = 'Please send this message to haokexin1214@gmail.com' . PHP_EOL;
			$error_info[] = 'I will do my best to solve the problem.' . PHP_EOL;
			$error_info[] = '</div>';
			print_r( $error_info );
			exit;
		}
		wbfp_copy_post_meta( $id, $original_id );
		wbfp_inherited_branch_attachments( $id, $original_id );
		wbfp_copy_post_taxonomies( $id, $original_id, $post['post_type'] );
		wbfp_inherited_branch_revision( $id, $original_id );
		wp_delete_post( $id, true );
		wp_safe_redirect( admin_url( '/post.php?post=' . $original_id . '&action=edit&message=1' ) );
		exit;
 	}
}	
add_action( 'publish_page', 'wbfp_original_post_pages_update', 9999, 2 );
add_action( 'publish_post', 'wbfp_original_post_pages_update', 9999, 2 );

/*
Show creator's info in the post list
*/
function wbfp_show_branch_info_in_list( $info ) {
	global $post;
	if ( 	$original_id = get_post_meta( $post->ID, '_original_post_id', true ) ) {
		$creator_name = get_post_meta( $post->ID, '_creator_name', true );
		$creator_id = get_post_meta( $post->ID, '_creator_user_id', true );
		$info[] = sprintf( '%d\'s branch  Creator: %s ID: %d', $original_id, $creator_name, $creator_id );
	}
	return $info;
}
add_filter( 'display_post_states', 'wbfp_show_branch_info_in_list' );

/*
Copy taxonomies
Return taxonomies object that copied post/page
*/
function wbfp_copy_post_taxonomies( $original_id, $target_id, $post_type ) {
	if ( isset( $original_id, $target_id, $post_type ) ) {
		$taxonomies = get_object_taxonomies( $post_type );
		$post_terms = wp_get_object_terms( $original_id, $taxonomies );
		$object = array();
		foreach ( $post_terms as $post_term ) {
			$object[$post_term->taxonomy][] = $post_term->slug;
		}
		foreach ( $object as $taxonomy => $terms ) {
			wp_set_object_terms($target_id, $terms, $taxonomy);
		}
		return $object;
	} else {
		return false;
	}
}

/*
Copy postmeta
*/
function wbfp_copy_post_meta( $original_id, $target_id ) {
	if ( isset( $original_id, $target_id ) ) {
		$custom_fields = get_post_custom( $original_id );
		unset(	$custom_fields['_original_post_id'], 
			$custom_fields['_creator_user_id'], 
			$custom_fields['_creator_name'],
			$custom_fields['_wp_old_slug'],
			$custom_fields['_wp_old_date'],
			$custom_fields['_edit_lock'],
			$custom_fields['_edit_last']
			);
		foreach ( $custom_fields as $key => $values ) {
			foreach ( $values as $value ) {
				$value = maybe_unserialize( $value );
				$value = wbfp_post_meta_addslashes( $value );
				update_post_meta( $target_id, $key, $value );
			}
		}
	}
}

/*
Postmeta values addslashes
*/
function wbfp_post_meta_addslashes( $value ) {
	if ( function_exists( 'map_deep' ) ) {
		return map_deep( $value, 'wbfp_map_deep_post_meta_addslashes' );
	} else {
		return wp_slash( $value );
	}
}

/*
Map_deep hook function for addslashes to value
*/
function wbfp_map_deep_post_meta_addslashes( $value ) {
	return is_string( $value ) ? addslashes( $value ) : $value;
}

/*
Origin inherited branch's revision
Change branch's revision postparent to origin
*/
function wbfp_inherited_branch_revision( $original_id, $target_id ) {
	if ( isset( $original_id, $target_id ) ) {
		if ( $revisions = wp_get_post_revisions( $original_id ) ) {
			global $wpdb;
			$revision_post_name = preg_replace( '/'.$original_id.'-revision/', $target_id.'-revision', $revisions[0]->post_name );
			$revision_guid = preg_replace( '/'.$original_id.'-revision/', $target_id.'-revision', $revisions[0]->guid );
			$wpdb->query(
				$wpdb->prepare("UPDATE 
							$wpdb->posts 
						SET 
							post_name = %s, 
							post_parent = %d,
							guid = %s
						WHERE
							post_parent = %d
							and post_type = %s",
							$revision_post_name,
							$target_id,
							$revision_guid,
							$original_id,
							'revision')
					);
		}
	}
}

/*
Origin inherited branch's attachments
Change branch's attachments postparent to origin
*/
function wbfp_inherited_branch_attachments( $original_id, $target_id ) {
	$args = array(	'post_type' => 'attachment', 
					'numberposts' => -1, 
					'post_status' => null, 
					'post_parent' => $original_id );
	$branch_attachments = get_posts( $args );
	if ( $branch_attachments ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare("UPDATE 
						$wpdb->posts 
					SET
						post_parent = %d
					WHERE
						post_parent = %d
					AND
						post_type = %s",
						$target_id,
						$original_id,
						'attachment' )
		);       
	}
}
