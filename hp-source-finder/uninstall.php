<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('hp_source_finder_settings');
delete_site_option('hp_source_finder_settings');

delete_transient('hp_source_finder_last_search');
delete_site_transient('hp_source_finder_last_search');
