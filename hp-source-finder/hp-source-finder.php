<?php
/**
 * Plugin Name: HP Source Finder
 * Plugin URI: https://tobi.holyprofweb.com/hp-source-finder
 * Description: Read-only finder for WordPress code, hooks, templates, settings, and some admin-page references.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Tobi Holyprof
 * Author URI: https://tobi.holyprofweb.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hp-source-finder
 * Domain Path: /languages
 */

if (! defined('ABSPATH')) {
    exit;
}

define('HP_SOURCE_FINDER_VERSION', '1.0.0');
define('HP_SOURCE_FINDER_PATH', plugin_dir_path(__FILE__));
define('HP_SOURCE_FINDER_URL', plugin_dir_url(__FILE__));

require_once HP_SOURCE_FINDER_PATH . 'includes/SearchEngine.php';
require_once HP_SOURCE_FINDER_PATH . 'admin/AdminPage.php';

function hp_source_finder_bootstrap() {
    if (! is_admin()) {
        return;
    }

    $search_engine = new HP_Source_Finder_SearchEngine();
    $search_engine->register_admin_page_capture();
    $admin_page = new HP_Source_Finder_AdminPage($search_engine);

    $admin_page->register();
}

add_action('plugins_loaded', 'hp_source_finder_bootstrap');
