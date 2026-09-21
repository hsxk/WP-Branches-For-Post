<?php
/**
 * Plugin Name:       WP Branches For Post
 * Plugin URI:        https://github.com/hsxk/WP-Branches-For-Post/
 * Description:       Create safe working branches for published WordPress content and merge them back without changing the public post while editing.
 * Version:           2.0.1
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            Hao Kexin
 * Author URI:        https://time2log.com/about/
 * Text Domain:       wp-branches-for-post
 * Domain Path:       /languages
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package WPBranchesForPost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WBFP_VERSION', '2.0.1' );
define( 'WBFP_FILE', __FILE__ );
define( 'WBFP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WBFP_URL', plugin_dir_url( __FILE__ ) );

require_once WBFP_DIR . 'includes/class-sync-service.php';
require_once WBFP_DIR . 'includes/class-branch-service.php';
require_once WBFP_DIR . 'includes/class-merge-service.php';
require_once WBFP_DIR . 'includes/class-rest-controller.php';
require_once WBFP_DIR . 'includes/class-admin.php';
require_once WBFP_DIR . 'includes/class-plugin.php';

WP_Branches_For_Post\Plugin::instance()->boot();
