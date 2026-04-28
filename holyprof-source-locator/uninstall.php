<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('holyprof_source_locator_settings');
delete_site_option('holyprof_source_locator_settings');

delete_transient('holyprof_source_locator_last_search');
delete_site_transient('holyprof_source_locator_last_search');
