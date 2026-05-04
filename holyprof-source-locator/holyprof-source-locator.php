<?php
/**
 * Plugin Name: Holyprof Source Locator
 * Plugin URI: https://tobi.holyprofweb.com/holyprof-source-locator
 * Description: Find where WordPress features are defined in code and where they are configured in admin, plugin, or theme settings pages.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Tobi Holyprof
 * Author URI: https://tobi.holyprofweb.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: holyprof-source-locator
 * Domain Path: /languages
 */

if (! defined('ABSPATH')) {
    exit;
}

define('HOLYPROF_SOURCE_LOCATOR_VERSION', '1.0.0');
define('HOLYPROF_SOURCE_LOCATOR_PATH', plugin_dir_path(__FILE__));
define('HOLYPROF_SOURCE_LOCATOR_URL', plugin_dir_url(__FILE__));

require_once HOLYPROF_SOURCE_LOCATOR_PATH . 'includes/SearchEngine.php';
require_once HOLYPROF_SOURCE_LOCATOR_PATH . 'admin/AdminPage.php';

function holyprof_source_locator_bootstrap() {
    if (! is_admin()) {
        return;
    }

    $search_engine = new Holyprof_Source_Locator_SearchEngine();
    $admin_page = new Holyprof_Source_Locator_AdminPage($search_engine);

    $admin_page->register();
}

add_action('plugins_loaded', 'holyprof_source_locator_bootstrap');
