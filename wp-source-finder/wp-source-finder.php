<?php
/**
 * Plugin Name: WP Source Finder
 * Plugin URI: https://example.com/
 * Description: Admin-only, read-only starter plugin boilerplate for WP Source Finder.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-source-finder
 * Domain Path: /languages
 */

if (! defined('ABSPATH')) {
    exit;
}

define('WP_SOURCE_FINDER_VERSION', '1.0.0');
define('WP_SOURCE_FINDER_PATH', plugin_dir_path(__FILE__));
define('WP_SOURCE_FINDER_URL', plugin_dir_url(__FILE__));

require_once WP_SOURCE_FINDER_PATH . 'includes/SearchEngine.php';
require_once WP_SOURCE_FINDER_PATH . 'admin/AdminPage.php';

function wp_source_finder_bootstrap() {
    if (! is_admin()) {
        return;
    }

    $search_engine = new WP_Source_Finder_SearchEngine();
    $admin_page = new WP_Source_Finder_AdminPage($search_engine);

    $admin_page->register();
}

add_action('plugins_loaded', 'wp_source_finder_bootstrap');
