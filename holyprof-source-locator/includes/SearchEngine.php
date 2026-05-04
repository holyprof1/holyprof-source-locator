<?php

if (! defined('ABSPATH')) {
    exit;
}

class Holyprof_Source_Locator_SearchEngine {
    const MAX_FILE_SIZE = 262144;
    const MAX_RESULTS = 200;
    const MAX_FEATURE_RESULTS = 18;
    const MAX_SETTINGS_RESULTS = 25;
    const MAX_ADMIN_PAGE_RESULTS = 20;

    public function search($search_term, $filter) {
        $search_term = trim(sanitize_text_field((string) $search_term));
        $filter = sanitize_key((string) $filter);

        if ($search_term === '') {
            return $this->empty_response();
        }

        $search_variants = $this->get_search_variants($search_term);
        $is_feature_search = $this->is_feature_search($search_term, $search_variants);
        $is_settings_search = $this->is_likely_settings_search($search_term) || $is_feature_search;
        $is_code_search = $this->is_likely_code_search($search_term, $search_variants);
        $is_text_finder_search = $this->is_text_finder_search($search_term);
        $settings_results = $this->should_search_settings($filter)
            ? $this->search_settings_locations($search_term, $filter)
            : array();
        $admin_page_results = $this->should_search_admin_pages($filter)
            ? $this->search_admin_page_locations($filter, $search_variants, $is_settings_search, $is_text_finder_search)
            : array();
        $allowed_extensions = $this->get_extensions_for_scope($filter);
        $results = array();
        $total_matches = 0;
        $truncated = false;

        if ($this->should_search_files($filter)) {
            foreach ($this->get_search_roots($filter) as $root) {
                if ($root['type'] === 'file') {
                    if (! is_readable($root['path'])) {
                        continue;
                    }

                    $file_info = new SplFileInfo($root['path']);

                    if ($this->should_skip_file($file_info)) {
                        continue;
                    }

                    $extension = strtolower($file_info->getExtension());

                    if (! in_array($extension, $allowed_extensions, true)) {
                        continue;
                    }

                    $file_results = $this->search_file($file_info->getPathname(), $search_variants, $root, $filter);

                    if (! empty($file_results)) {
                        foreach ($file_results as $file_result) {
                            $results[] = $file_result;
                            $total_matches++;

                            if ($total_matches >= self::MAX_RESULTS) {
                                $truncated = true;
                                break 2;
                            }
                        }
                    }

                    continue;
                }

                if (! is_dir($root['path']) || ! is_readable($root['path'])) {
                    continue;
                }

                $directory_iterator = new RecursiveDirectoryIterator($root['path'], FilesystemIterator::SKIP_DOTS);
                $filtered_iterator = new RecursiveCallbackFilterIterator(
                    $directory_iterator,
                    array($this, 'filter_search_path')
                );
                $iterator = new RecursiveIteratorIterator(
                    $filtered_iterator
                );

                foreach ($iterator as $file_info) {
                    if (! $file_info instanceof SplFileInfo) {
                        continue;
                    }

                    if (! $file_info->isFile() || ! $file_info->isReadable() || $this->should_skip_file($file_info)) {
                        continue;
                    }

                    $extension = strtolower($file_info->getExtension());
                    if (! in_array($extension, $allowed_extensions, true)) {
                        continue;
                    }

                    $file_results = $this->search_file($file_info->getPathname(), $search_variants, $root, $filter);

                    if (! empty($file_results)) {
                        foreach ($file_results as $file_result) {
                            $results[] = $file_result;
                            $total_matches++;

                            if ($total_matches >= self::MAX_RESULTS) {
                                $truncated = true;
                                break 3;
                            }
                        }
                    }
                }
            }
        }

        $results = $this->filter_file_results_by_scope($results, $filter);
        $results = $this->rank_file_results($results, $search_variants, $is_settings_search, $is_text_finder_search, $is_code_search);
        $feature_results = $this->should_search_feature_locations($filter)
            ? $this->search_feature_locations(
                $filter,
                $search_variants,
                $settings_results,
                $admin_page_results,
                $results,
                $is_feature_search
            )
            : array();

        return array(
            'results' => $results,
            'file_results' => $results,
            'feature_results' => $feature_results,
            'settings_results' => $settings_results,
            'admin_page_results' => $admin_page_results,
            'is_feature_search' => $is_feature_search,
            'is_settings_search' => $is_settings_search,
            'is_code_search' => $is_code_search,
            'is_text_finder_search' => $is_text_finder_search,
            'truncated' => $truncated,
            'max_results' => self::MAX_RESULTS,
        );
    }

    private function search_file($file_path, $search_variants, $root, $scope = 'all') {
        $results = array();
        $relative_path = $this->get_relative_path($file_path, $root);
        $source_type = $this->get_file_source_type($file_path, $root);
        $source_category = isset($root['category']) ? (string) $root['category'] : '';
        $path_match_result = $this->build_file_path_match_result($file_path, $relative_path, $search_variants, $root, $source_type, $source_category);

        if (! empty($path_match_result)) {
            $results[] = $path_match_result;
        }

        $file = new SplFileObject($file_path, 'r');
        $line_number = 0;

        while (! $file->eof()) {
            $line = $file->fgets();
            $line_number++;
            $trimmed_line = trim((string) $line);

            if (! $this->matches_any_variant($trimmed_line, $search_variants)) {
                continue;
            }

            $result = array(
                'file_path' => $relative_path,
                'source_label' => $root['label'],
                'source_type' => $source_type,
                'source_category' => $source_category,
                'line_number' => $line_number,
                'snippet' => $trimmed_line,
                'match_kind' => 'content',
            );

            $results[] = array_merge(
                $result,
                $this->get_file_result_metadata($trimmed_line, $relative_path, $search_variants)
            );
        }

        return $results;
    }

    private function get_relative_path($file_path, $root) {
        if ($root['type'] === 'file') {
            return wp_basename($file_path);
        }

        $relative_path = ltrim(str_replace($root['path'], '', $file_path), '\\/');

        return $root['slug'] . '/' . str_replace('\\', '/', $relative_path);
    }

    private function build_file_path_match_result($file_path, $relative_path, $search_variants, $root, $source_type, $source_category) {
        $normalized_path = $this->normalize_loose_search_value($relative_path);
        $file_name = wp_basename($relative_path);
        $best_match_variant = '';
        $best_score = 0;

        foreach ($search_variants as $variant) {
            $variant = $this->normalize_loose_search_value($variant);

            if ($variant === '') {
                continue;
            }

            if ($normalized_path === $variant || strtolower($file_name) === strtolower($variant)) {
                $best_match_variant = $variant;
                $best_score = 3;
                break;
            }

            if ($this->contains_whole_phrase($normalized_path, $variant)) {
                $best_match_variant = $variant;
                $best_score = max($best_score, 2);
                continue;
            }

            if (strpos($normalized_path, $variant) !== false) {
                $best_match_variant = $variant;
                $best_score = max($best_score, 1);
            }
        }

        if ($best_score === 0) {
            return array();
        }

        return array(
            'file_path' => $relative_path,
            'source_label' => $root['label'],
            'source_type' => $source_type,
            'source_category' => $source_category,
            'line_number' => 0,
            'snippet' => $relative_path,
            'match_kind' => 'path',
            'result_type' => __('File path match', 'holyprof-source-locator'),
            'matched_keyword' => $best_match_variant,
        );
    }

    private function get_file_result_metadata($snippet, $file_path, $search_variants) {
        $metadata = array(
            'result_type' => __('Text match', 'holyprof-source-locator'),
            'matched_keyword' => $this->get_best_matched_variant($snippet, $search_variants),
        );
        $normalized_snippet = $this->normalize_search_value($snippet);
        $hook_match = $this->extract_hook_match_data($snippet);

        if (! empty($hook_match)) {
            $metadata = array_merge($metadata, $hook_match);
        } elseif (preg_match('/\bfunction\s+[a-z0-9_]+\s*\(/i', $snippet)) {
            $metadata['result_type'] = __('Function match', 'holyprof-source-locator');
        } elseif (preg_match('/\bclass\s+[a-z0-9_]+\b/i', $snippet)) {
            $metadata['result_type'] = __('Class match', 'holyprof-source-locator');
        } elseif (preg_match('/\b(?:public|protected|private)?\s*function\s+[a-z0-9_]+\s*\(/i', $snippet)) {
            $metadata['result_type'] = __('Method match', 'holyprof-source-locator');
        } elseif (preg_match('/(?:^|[\s(])\.[a-z0-9_-]+/i', $snippet) || preg_match('/(?:^|[\s(])#[a-z0-9_-]+/i', $snippet)) {
            $metadata['result_type'] = __('Selector match', 'holyprof-source-locator');
        } elseif (strpos($normalized_snippet, 'function') !== false && strpos($normalized_snippet, '.php') !== false) {
            $metadata['result_type'] = __('PHP match', 'holyprof-source-locator');
        }

        return $metadata;
    }

    private function get_best_matched_variant($value, $variants) {
        $value = $this->normalize_loose_search_value($value);

        foreach ($variants as $variant) {
            $variant = $this->normalize_loose_search_value($variant);

            if ($variant !== '' && strpos($value, $variant) !== false) {
                return $variant;
            }
        }

        return isset($variants[0]) ? (string) $variants[0] : '';
    }

    private function extract_hook_match_data($snippet) {
        $patterns = array(
            array('pattern' => '/\badd_action\s*\(\s*[\'"]([^\'"]+)[\'"]/i', 'label' => __('Action registration', 'holyprof-source-locator')),
            array('pattern' => '/\bdo_action\s*\(\s*[\'"]([^\'"]+)[\'"]/i', 'label' => __('Action trigger', 'holyprof-source-locator')),
            array('pattern' => '/\bremove_action\s*\(\s*[\'"]([^\'"]+)[\'"]/i', 'label' => __('Action removal', 'holyprof-source-locator')),
            array('pattern' => '/\bhas_action\s*\(\s*[\'"]([^\'"]+)[\'"]/i', 'label' => __('Action check', 'holyprof-source-locator')),
            array('pattern' => '/\badd_filter\s*\(\s*[\'"]([^\'"]+)[\'"]/i', 'label' => __('Filter registration', 'holyprof-source-locator')),
            array('pattern' => '/\bapply_filters\s*\(\s*[\'"]([^\'"]+)[\'"]/i', 'label' => __('Filter trigger', 'holyprof-source-locator')),
            array('pattern' => '/\bremove_filter\s*\(\s*[\'"]([^\'"]+)[\'"]/i', 'label' => __('Filter removal', 'holyprof-source-locator')),
            array('pattern' => '/\bhas_filter\s*\(\s*[\'"]([^\'"]+)[\'"]/i', 'label' => __('Filter check', 'holyprof-source-locator')),
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern['pattern'], $snippet, $matches)) {
                $hook_name = isset($matches[1]) ? sanitize_text_field((string) $matches[1]) : '';

                return array(
                    'result_type' => __('Hook match', 'holyprof-source-locator'),
                    'hook_type' => $pattern['label'],
                    'hook_name' => $hook_name,
                );
            }
        }

        return array();
    }

    private function should_skip_directory(SplFileInfo $directory_info) {
        return in_array(strtolower($directory_info->getFilename()), $this->get_skipped_directories(), true);
    }

    public function filter_search_path($current) {
        if (! $current instanceof SplFileInfo || ! $current->isReadable()) {
            return false;
        }

        if ($current->isDir()) {
            return ! $this->should_skip_directory($current);
        }

        return true;
    }

    private function should_skip_file(SplFileInfo $file_info) {
        if ($file_info->getSize() > self::MAX_FILE_SIZE) {
            return true;
        }

        $path_parts = preg_split('#[\\\\/]#', wp_normalize_path($file_info->getPath()));

        foreach ($path_parts as $path_part) {
            if (in_array(strtolower($path_part), $this->get_skipped_directories(), true)) {
                return true;
            }
        }

        return false;
    }

    private function get_skipped_directories() {
        return array(
            'vendor',
            'node_modules',
            'logs',
            'log',
            'cache',
            'caches',
        );
    }

    private function get_search_roots($scope) {
        $roots = array(
            array(
                'path' => untrailingslashit(HOLYPROF_SOURCE_LOCATOR_PATH),
                'label' => __('Holyprof Source Locator Plugin', 'holyprof-source-locator'),
                'slug' => 'holyprof-source-locator',
                'type' => 'directory',
                'category' => 'plugin',
                'source_type' => __('Plugin', 'holyprof-source-locator'),
            ),
        );

        $stylesheet_directory = untrailingslashit((string) get_stylesheet_directory());
        $template_directory = untrailingslashit((string) get_template_directory());

        if ($stylesheet_directory !== '') {
            $roots[] = array(
                'path' => $stylesheet_directory,
                'label' => __('Active Theme', 'holyprof-source-locator'),
                'slug' => wp_basename($stylesheet_directory),
                'type' => 'directory',
                'category' => 'theme',
                'source_type' => __('Theme', 'holyprof-source-locator'),
            );
        }

        if ($template_directory !== '' && $template_directory !== $stylesheet_directory) {
            $roots[] = array(
                'path' => $template_directory,
                'label' => __('Parent Theme', 'holyprof-source-locator'),
                'slug' => wp_basename($template_directory),
                'type' => 'directory',
                'category' => 'theme',
                'source_type' => __('Theme', 'holyprof-source-locator'),
            );
        }

        foreach ($this->get_active_plugin_paths() as $plugin_path) {
            $plugin_root = $this->get_plugin_search_root($plugin_path);

            if (empty($plugin_root)) {
                continue;
            }

            $roots[] = $plugin_root;
        }

        return $this->filter_roots_by_scope($this->unique_roots($roots), $scope);
    }

    private function get_active_plugin_paths() {
        $plugin_paths = array();

        if (function_exists('wp_get_active_and_valid_plugins')) {
            $plugin_paths = wp_get_active_and_valid_plugins();
        } else {
            $active_plugins = (array) get_option('active_plugins', array());

            foreach ($active_plugins as $plugin_file) {
                $plugin_paths[] = WP_PLUGIN_DIR . '/' . ltrim($plugin_file, '/');
            }
        }

        if (is_multisite()) {
            $network_plugins = array_keys((array) get_site_option('active_sitewide_plugins', array()));

            foreach ($network_plugins as $plugin_file) {
                $plugin_paths[] = WP_PLUGIN_DIR . '/' . ltrim($plugin_file, '/');
            }
        }

        return array_values(array_unique(array_filter($plugin_paths)));
    }

    private function get_plugin_search_root($plugin_path) {
        $plugin_path = wp_normalize_path((string) $plugin_path);
        $plugin_basename = plugin_basename($plugin_path);
        $plugin_parts = explode('/', $plugin_basename);
        $plugin_name = '';

        if (count($plugin_parts) > 1) {
            $plugin_directory = untrailingslashit(dirname($plugin_path));

            if ($plugin_directory === '' || ! is_dir($plugin_directory)) {
                return array();
            }

            $plugin_slug = $plugin_parts[0];
            $plugin_name = $this->get_plugin_name_by_slug($plugin_slug, $plugin_basename);

            return array(
                'path' => $plugin_directory,
                'label' => sprintf(
                    /* translators: %s: plugin name */
                    __('Active Plugin: %s', 'holyprof-source-locator'),
                    $plugin_name
                ),
                'slug' => $plugin_slug,
                'type' => 'directory',
                'category' => 'plugin',
                'source_type' => __('Plugin', 'holyprof-source-locator'),
            );
        }

        if (! is_file($plugin_path)) {
            return array();
        }

        $plugin_slug = basename($plugin_basename, '.php');
        $plugin_name = $this->get_plugin_name_by_slug($plugin_slug, $plugin_basename);

        return array(
            'path' => $plugin_path,
            'label' => sprintf(
                /* translators: %s: plugin name */
                __('Active Plugin: %s', 'holyprof-source-locator'),
                $plugin_name
            ),
            'slug' => $plugin_slug,
            'type' => 'file',
            'category' => 'plugin',
            'source_type' => __('Plugin', 'holyprof-source-locator'),
        );
    }

    private function unique_roots($roots) {
        $unique_roots = array();

        foreach ($roots as $root) {
            if (empty($root['path'])) {
                continue;
            }

            $normalized_path = wp_normalize_path($root['path']);

            if (isset($unique_roots[$normalized_path])) {
                continue;
            }

            $root['path'] = untrailingslashit($root['path']);
            $unique_roots[$normalized_path] = $root;
        }

        return array_values($unique_roots);
    }

    private function get_extensions_for_scope($filter) {
        $filters = array(
            'all' => array('php', 'css', 'js', 'html', 'txt'),
            'best-matches' => array('php', 'css', 'js', 'html', 'txt'),
            'plugin' => array('php', 'css', 'js', 'html', 'txt'),
            'theme' => array('php', 'css', 'js', 'html', 'txt'),
            'php' => array('php'),
            'css' => array('css'),
            'js' => array('js'),
            'templates' => array('php', 'html', 'txt'),
            'hooks' => array('php'),
            'settings-admin' => array(),
        );

        if (! isset($filters[$filter])) {
            return $filters['all'];
        }

        return $filters[$filter];
    }

    private function filter_file_results_by_scope($results, $scope) {
        if ($scope !== 'hooks') {
            return $results;
        }

        return array_values(
            array_filter(
                $results,
                function ($result) {
                    return ! empty($result['hook_name']) || ! empty($result['hook_type']);
                }
            )
        );
    }

    private function search_settings_locations($search_term, $scope) {
        $settings_results = array();
        $needle = $this->normalize_search_value($search_term);
        $variants = $this->get_search_variants($search_term);

        if ($needle === '') {
            return array();
        }

        foreach ($this->get_admin_menu_entries() as $entry) {
            if (! $this->menu_entry_matches($entry, $needle)) {
                continue;
            }

            $settings_results[] = $entry;

            if (count($settings_results) >= self::MAX_SETTINGS_RESULTS) {
                break;
            }
        }

        foreach ($this->get_registered_settings_entries() as $entry) {
            if (! $this->registered_setting_matches($entry, $needle)) {
                continue;
            }

            $settings_results[] = $entry;

            if (count($settings_results) >= self::MAX_SETTINGS_RESULTS) {
                break;
            }
        }

        $settings_results = array_slice(
            $this->rank_settings_results(
                $this->filter_settings_results_by_scope(
                    $this->unique_settings_results($settings_results),
                    $scope
                ),
                $variants
            ),
            0,
            self::MAX_SETTINGS_RESULTS
        );

        return array_map(array($this, 'decorate_location_entry'), $settings_results);
    }

    private function search_admin_page_locations($scope, $variants, $is_settings_search, $is_text_finder_search) {
        $results = array();

        foreach ($this->get_plugin_admin_page_entries() as $entry) {
            if (! $this->admin_page_entry_matches_scope($entry, $scope)) {
                continue;
            }

            $result = $this->build_admin_page_match_result($entry, $variants);

            if (! empty($result)) {
                $results[] = $result;
            }
        }

        $results = array_slice(
            $this->rank_admin_page_results(
                $this->unique_admin_page_results($results),
                $variants,
                $is_settings_search,
                $is_text_finder_search
            ),
            0,
            self::MAX_ADMIN_PAGE_RESULTS
        );

        return array_map(array($this, 'decorate_location_entry'), $results);
    }

    private function search_feature_locations($scope, $variants, $settings_results, $admin_page_results, $file_results, $is_feature_search) {
        $results = array();
        $evidence_index = $this->build_feature_evidence_index($file_results, $variants);

        if (! $is_feature_search && empty($settings_results) && empty($admin_page_results)) {
            return array();
        }

        foreach ((array) $settings_results as $settings_result) {
            $feature_result = $this->build_feature_location_result($settings_result, $variants, 'settings', $evidence_index);

            if (! empty($feature_result)) {
                $results[] = $feature_result;
            }
        }

        foreach ((array) $admin_page_results as $admin_page_result) {
            $feature_result = $this->build_feature_location_result($admin_page_result, $variants, 'admin-page', $evidence_index);

            if (! empty($feature_result)) {
                $results[] = $feature_result;
            }
        }

        if ($is_feature_search) {
            $page_owner_index = $this->get_feature_location_owner_index($results);

            foreach ($this->build_feature_file_source_results($evidence_index, $variants, $page_owner_index) as $file_source_result) {
                $results[] = $file_source_result;
            }
        }

        return array_slice(
            $this->rank_feature_location_results(
                $this->unique_feature_location_results(
                    $this->filter_promoted_feature_location_results($results)
                ),
                $variants,
                $is_feature_search,
                $scope
            ),
            0,
            self::MAX_FEATURE_RESULTS
        );
    }

    private function decorate_location_entry($entry) {
        $owner = $this->infer_location_owner($entry);

        $entry['owner_type'] = isset($owner['owner_type']) ? (string) $owner['owner_type'] : 'unknown';
        $entry['owner_slug'] = isset($owner['owner_slug']) ? (string) $owner['owner_slug'] : '';
        $entry['source_owner'] = isset($owner['display_name']) ? (string) $owner['display_name'] : __('Unknown source', 'holyprof-source-locator');

        if (empty($entry['plugin_name']) && isset($owner['name']) && $owner['name'] !== '' && $entry['owner_type'] === 'plugin') {
            $entry['plugin_name'] = $owner['name'];
        }

        if (empty($entry['plugin_slug']) && ! empty($entry['owner_slug']) && $entry['owner_type'] === 'plugin') {
            $entry['plugin_slug'] = $entry['owner_slug'];
        }

        if (empty($entry['source_type']) || $entry['source_type'] === __('Menu Page', 'holyprof-source-locator')) {
            $entry['source_type'] = $this->get_feature_location_source_type_label($entry, $owner);
        }

        return $entry;
    }

    private function build_feature_location_result($entry, $variants, $location_kind, $evidence_index = array()) {
        $analysis = $this->get_feature_location_match_analysis($entry, $variants, $location_kind);

        if (empty($analysis) || empty($analysis['score'])) {
            return array();
        }

        $owner = $this->infer_location_owner($entry);
        $page_title = isset($entry['page_title']) ? (string) $entry['page_title'] : '';
        $title = isset($entry['title']) ? (string) $entry['title'] : '';
        $menu_title = isset($entry['menu_title']) ? (string) $entry['menu_title'] : '';
        $path = isset($entry['path']) ? (string) $entry['path'] : '';
        $slug = isset($entry['slug']) ? (string) $entry['slug'] : '';
        $url = isset($entry['url']) ? (string) $entry['url'] : '';
        $source_type = $this->get_feature_location_source_type_label($entry, $owner);
        $resolved_title = $page_title !== '' ? $page_title : ($title !== '' ? $title : $menu_title);
        $reason = isset($analysis['reason']) ? (string) $analysis['reason'] : '';
        $real_support = $this->get_feature_location_support_flags($entry, $owner, $url, $location_kind);
        $related_evidence = $this->get_feature_related_evidence($entry, $owner, $evidence_index);
        $primary_evidence = isset($related_evidence[0]) ? $related_evidence[0] : array();
        $confidence = $this->build_feature_location_confidence($location_kind, $analysis, $real_support, $related_evidence, $owner);

        if ($resolved_title === '') {
            $resolved_title = $menu_title !== '' ? $menu_title : $this->slug_to_label($slug);
        }

        if ($reason === '' && ! empty($entry['note'])) {
            $reason = (string) $entry['note'];
        }

        return array(
            'feature_term' => isset($analysis['matched_term']) ? (string) $analysis['matched_term'] : $this->normalize_search_value(implode(' ', $variants)),
            'title' => $resolved_title,
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'path' => $path,
            'slug' => $slug,
            'url' => $url,
            'source' => isset($entry['source']) ? (string) $entry['source'] : '',
            'source_type' => $source_type,
            'source_owner' => isset($owner['display_name']) ? (string) $owner['display_name'] : '',
            'owner_type' => isset($owner['owner_type']) ? (string) $owner['owner_type'] : '',
            'owner_slug' => isset($owner['owner_slug']) ? (string) $owner['owner_slug'] : '',
            'reason' => $reason,
            'reason_key' => isset($analysis['reason_key']) ? (string) $analysis['reason_key'] : '',
            'confidence_label' => isset($confidence['label']) ? (string) $confidence['label'] : __('Possible settings page', 'holyprof-source-locator'),
            'confidence_level' => isset($confidence['level']) ? (string) $confidence['level'] : 'possible',
            'confidence_score' => isset($confidence['score']) ? (int) $confidence['score'] : 100,
            'match_score' => isset($analysis['score']) ? (int) $analysis['score'] : 0,
            'location_kind' => (string) $location_kind,
            'capability' => isset($entry['capability']) ? (string) $entry['capability'] : '',
            'is_clickable' => ! empty($real_support['has_real_url']),
            'match_type' => isset($entry['match_type']) ? (string) $entry['match_type'] : '',
            'note' => isset($entry['note']) ? (string) $entry['note'] : '',
            'supporting_path' => isset($primary_evidence['file_path']) ? (string) $primary_evidence['file_path'] : '',
            'supporting_line' => isset($primary_evidence['line_number']) ? (int) $primary_evidence['line_number'] : 0,
            'supporting_snippet' => isset($primary_evidence['snippet']) ? (string) $primary_evidence['snippet'] : '',
            'supporting_source_label' => isset($primary_evidence['source_label']) ? (string) $primary_evidence['source_label'] : '',
            'related_evidence' => $related_evidence,
            'has_real_match' => ! empty($real_support['has_real_match']),
            'has_real_url' => ! empty($real_support['has_real_url']),
            'has_known_owner' => ! empty($real_support['has_known_owner']),
            'has_supporting_evidence' => ! empty($real_support['has_supporting_evidence']) || ! empty($related_evidence),
            'is_search_hint' => false,
        );
    }

    private function get_feature_location_support_flags($entry, $owner, $url, $location_kind) {
        $owner_type = isset($owner['owner_type']) ? (string) $owner['owner_type'] : '';
        $has_known_owner = $owner_type !== '' && $owner_type !== 'unknown';
        $has_real_url = $url !== '' && $this->is_valid_admin_url($url);
        $has_registered_match = in_array($location_kind, array('settings', 'admin-page'), true)
            && (
                ! empty($entry['field_label'])
                || ! empty($entry['section_title'])
                || ! empty($entry['setting_name'])
                || ! empty($entry['page_title'])
                || ! empty($entry['menu_title'])
                || ! empty($entry['path'])
                || ! empty($entry['slug'])
            );
        $has_supporting_evidence = ! empty($entry['supporting_path']) || ! empty($entry['supporting_snippet']);

        return array(
            'has_real_match' => $has_registered_match || $has_known_owner || $has_real_url || $has_supporting_evidence,
            'has_real_url' => $has_real_url,
            'has_known_owner' => $has_known_owner,
            'has_supporting_evidence' => $has_supporting_evidence,
        );
    }

    private function get_feature_location_source_type_label($entry, $owner) {
        $owner_type = isset($owner['owner_type']) ? (string) $owner['owner_type'] : '';

        if ($owner_type === 'plugin') {
            return __('Plugin', 'holyprof-source-locator');
        }

        if ($owner_type === 'theme') {
            return __('Theme', 'holyprof-source-locator');
        }

        if ($owner_type === 'wordpress') {
            return __('WordPress admin', 'holyprof-source-locator');
        }

        if ($owner_type === 'unknown') {
            return __('Unknown', 'holyprof-source-locator');
        }

        return isset($entry['source_type']) ? (string) $entry['source_type'] : __('Unknown', 'holyprof-source-locator');
    }

    private function rank_feature_location_results($results, $variants, $is_feature_search, $scope) {
        usort(
            $results,
            function ($left, $right) use ($variants, $is_feature_search, $scope) {
                $left_score = $this->get_feature_location_score($left, $variants, $is_feature_search, $scope);
                $right_score = $this->get_feature_location_score($right, $variants, $is_feature_search, $scope);

                if ($left_score === $right_score) {
                    return strcasecmp(
                        isset($left['title']) ? (string) $left['title'] : '',
                        isset($right['title']) ? (string) $right['title'] : ''
                    );
                }

                return $right_score <=> $left_score;
            }
        );

        return $results;
    }

    private function get_feature_location_score($result, $variants, $is_feature_search, $scope) {
        $score = (int) (isset($result['match_score']) ? $result['match_score'] : 0);
        $score += (int) (isset($result['confidence_score']) ? $result['confidence_score'] : 0);

        if (! empty($result['is_clickable'])) {
            $score += 70;
        }

        if ($is_feature_search) {
            $score += 40;
        }

        if ($scope === 'plugin' && isset($result['owner_type']) && $result['owner_type'] === 'plugin') {
            $score += 40;
        }

        if ($scope === 'theme' && isset($result['owner_type']) && $result['owner_type'] === 'theme') {
            $score += 40;
        }

        $title = $this->normalize_search_value(isset($result['title']) ? $result['title'] : '');
        $slug = $this->normalize_search_value(isset($result['slug']) ? $result['slug'] : '');

        foreach ($variants as $variant) {
            if ($variant === '') {
                continue;
            }

            if ($title === $variant || $slug === $variant) {
                $score += 80;
            }
        }

        if (! empty($result['has_known_owner'])) {
            $score += 45;
        }

        if (! empty($result['has_supporting_evidence'])) {
            $score += 30;
        }

        if (isset($result['location_kind']) && $result['location_kind'] === 'file-source') {
            $score += 20;
        }

        return $score;
    }

    private function unique_feature_location_results($results) {
        $unique = array();

        foreach ($results as $result) {
            $key = $this->get_feature_location_key($result);

            if (isset($unique[$key])) {
                $unique[$key] = $this->merge_feature_location_result($unique[$key], $result);
                continue;
            }

            $unique[$key] = $result;
        }

        return array_values($unique);
    }

    private function get_feature_location_key($result) {
        $url = isset($result['url']) ? $this->normalize_search_value($result['url']) : '';
        $slug = isset($result['slug']) ? $this->normalize_search_value($result['slug']) : '';
        $path = isset($result['path']) ? $this->normalize_search_value($result['path']) : '';
        $owner = isset($result['owner_slug']) ? $this->normalize_search_value($result['owner_slug']) : '';
        $kind = isset($result['location_kind']) ? $this->normalize_search_value($result['location_kind']) : '';

        return implode('|', array($url, $slug, $path, $owner, $kind));
    }

    private function merge_feature_location_result($existing, $incoming) {
        $existing_score = (int) (isset($existing['match_score']) ? $existing['match_score'] : 0)
            + (int) (isset($existing['confidence_score']) ? $existing['confidence_score'] : 0);
        $incoming_score = (int) (isset($incoming['match_score']) ? $incoming['match_score'] : 0)
            + (int) (isset($incoming['confidence_score']) ? $incoming['confidence_score'] : 0);

        if ($incoming_score > $existing_score) {
            $primary = $incoming;
            $secondary = $existing;
        } else {
            $primary = $existing;
            $secondary = $incoming;
        }

        if (! empty($primary['reason']) && ! empty($secondary['reason']) && $primary['reason'] !== $secondary['reason']) {
            $primary['reason'] .= ' ' . $secondary['reason'];
        } elseif (empty($primary['reason']) && ! empty($secondary['reason'])) {
            $primary['reason'] = $secondary['reason'];
        }

        if (empty($primary['source_owner']) && ! empty($secondary['source_owner'])) {
            $primary['source_owner'] = $secondary['source_owner'];
        }

        if (empty($primary['path']) && ! empty($secondary['path'])) {
            $primary['path'] = $secondary['path'];
        }

        if (empty($primary['url']) && ! empty($secondary['url'])) {
            $primary['url'] = $secondary['url'];
            $primary['is_clickable'] = ! empty($secondary['is_clickable']);
        }

        if (empty($primary['related_evidence']) && ! empty($secondary['related_evidence'])) {
            $primary['related_evidence'] = $secondary['related_evidence'];
        }

        return $primary;
    }

    private function filter_promoted_feature_location_results($results) {
        return array_values(
            array_filter(
                (array) $results,
                function ($result) {
                    $has_real_match = ! empty($result['has_real_match']);
                    $has_real_url = ! empty($result['has_real_url']);
                    $has_known_owner = ! empty($result['has_known_owner']);
                    $has_supporting_evidence = ! empty($result['has_supporting_evidence']);

                    if ($has_real_url || $has_known_owner || $has_supporting_evidence) {
                        return true;
                    }

                    return $has_real_match;
                }
            )
        );
    }

    private function get_feature_location_owner_index($results) {
        $owners = array();

        foreach ((array) $results as $result) {
            $owner_slug = isset($result['owner_slug']) ? (string) $result['owner_slug'] : '';

            if ($owner_slug === '') {
                continue;
            }

            if (
                isset($result['location_kind'])
                && $result['location_kind'] !== 'file-source'
                && in_array(isset($result['confidence_level']) ? (string) $result['confidence_level'] : '', array('exact', 'likely'), true)
            ) {
                $owners[$owner_slug] = true;
            }
        }

        return $owners;
    }

    private function build_feature_evidence_index($file_results, $variants) {
        $index = array();

        foreach ((array) $file_results as $file_result) {
            $owner = $this->infer_owner_from_file_result($file_result);
            $owner_slug = isset($owner['owner_slug']) ? (string) $owner['owner_slug'] : '';

            if ($owner_slug === '' || empty($owner['owner_type'])) {
                continue;
            }

            if (! isset($index[$owner_slug])) {
                $index[$owner_slug] = array(
                    'owner' => $owner,
                    'match_count' => 0,
                    'path_matches' => 0,
                    'results' => array(),
                );
            }

            $index[$owner_slug]['match_count']++;

            if (isset($file_result['match_kind']) && $file_result['match_kind'] === 'path') {
                $index[$owner_slug]['path_matches']++;
            }

            $score = $this->get_feature_file_source_score($file_result, $variants);
            $file_key = isset($file_result['file_path']) ? (string) $file_result['file_path'] : md5(wp_json_encode($file_result));

            if (
                ! isset($index[$owner_slug]['results'][$file_key])
                || $score > $index[$owner_slug]['results'][$file_key]['_score']
            ) {
                $file_result['_score'] = $score;
                $index[$owner_slug]['results'][$file_key] = $file_result;
            }
        }

        foreach ($index as $owner_slug => $entry) {
            $results = array_values($entry['results']);

            usort(
                $results,
                function ($left, $right) {
                    $left_score = isset($left['_score']) ? (int) $left['_score'] : 0;
                    $right_score = isset($right['_score']) ? (int) $right['_score'] : 0;

                    if ($left_score === $right_score) {
                        return strcasecmp(
                            isset($left['file_path']) ? (string) $left['file_path'] : '',
                            isset($right['file_path']) ? (string) $right['file_path'] : ''
                        );
                    }

                    return $right_score <=> $left_score;
                }
            );

            $index[$owner_slug]['results'] = array_slice($results, 0, 3);
        }

        return $index;
    }

    private function get_feature_related_evidence($entry, $owner, $evidence_index) {
        $owner_slug = isset($owner['owner_slug']) ? (string) $owner['owner_slug'] : '';

        if ($owner_slug !== '' && isset($evidence_index[$owner_slug]['results'])) {
            return $this->sanitize_feature_evidence_items($evidence_index[$owner_slug]['results']);
        }

        return array();
    }

    private function sanitize_feature_evidence_items($items) {
        $evidence = array();

        foreach ((array) $items as $item) {
            $evidence[] = array(
                'file_path' => isset($item['file_path']) ? (string) $item['file_path'] : '',
                'line_number' => isset($item['line_number']) ? (int) $item['line_number'] : 0,
                'snippet' => isset($item['snippet']) ? (string) $item['snippet'] : '',
                'source_label' => isset($item['source_label']) ? (string) $item['source_label'] : '',
                'result_type' => isset($item['result_type']) ? (string) $item['result_type'] : __('Source file match', 'holyprof-source-locator'),
            );
        }

        return $evidence;
    }

    private function build_feature_file_source_results($evidence_index, $variants, $page_owner_index = array()) {
        $results = array();
        $query_label = isset($variants[0]) ? (string) $variants[0] : __('feature', 'holyprof-source-locator');

        foreach ($evidence_index as $owner_slug => $grouped_source) {
            if (isset($page_owner_index[$owner_slug])) {
                continue;
            }

            $owner = isset($grouped_source['owner']) ? $grouped_source['owner'] : array();
            $evidence = isset($grouped_source['results']) ? (array) $grouped_source['results'] : array();
            $best_result = isset($evidence[0]) ? $evidence[0] : array();
            $match_count = isset($grouped_source['match_count']) ? (int) $grouped_source['match_count'] : 0;
            $path_matches = isset($grouped_source['path_matches']) ? (int) $grouped_source['path_matches'] : 0;

            if ($match_count <= 0 || empty($best_result)) {
                continue;
            }

            if ($match_count < 2 && $path_matches === 0) {
                continue;
            }

            $supporting_path = isset($best_result['file_path']) ? (string) $best_result['file_path'] : '';
            $supporting_line = isset($best_result['line_number']) ? (int) $best_result['line_number'] : 0;
            $supporting_snippet = isset($best_result['snippet']) ? (string) $best_result['snippet'] : '';
            $owner_name = isset($owner['name']) ? (string) $owner['name'] : __('Unknown source', 'holyprof-source-locator');
            $owner_label = isset($owner['display_name']) ? (string) $owner['display_name'] : $owner_name;
            $match_label = isset($owner['owner_type']) && $owner['owner_type'] === 'theme'
                ? __('Likely source theme', 'holyprof-source-locator')
                : __('Likely source plugin', 'holyprof-source-locator');

            $results[] = array(
                'feature_term' => $query_label,
                'title' => sprintf(
                    /* translators: %s: search term */
                    __('%s source files', 'holyprof-source-locator'),
                    $this->slug_to_label($query_label)
                ),
                'page_title' => '',
                'menu_title' => '',
                'path' => $supporting_path,
                'slug' => '',
                'url' => '',
                'source' => $match_label,
                'source_type' => isset($owner['owner_type']) && $owner['owner_type'] === 'theme'
                    ? __('Theme', 'holyprof-source-locator')
                    : __('Plugin', 'holyprof-source-locator'),
                'source_owner' => $owner_label,
                'owner_type' => isset($owner['owner_type']) ? (string) $owner['owner_type'] : '',
                'owner_slug' => isset($owner['owner_slug']) ? (string) $owner['owner_slug'] : '',
                'reason' => sprintf(
                    /* translators: 1: search term, 2: owner name */
                    __('Multiple %1$s matches found in %2$s files.', 'holyprof-source-locator'),
                    $query_label,
                    $owner_name
                ),
                'reason_key' => 'file_support',
                'confidence_label' => $match_label,
                'confidence_level' => 'likely',
                'confidence_score' => 220,
                'match_score' => 220 + min(160, $match_count * 40),
                'location_kind' => 'file-source',
                'capability' => '',
                'is_clickable' => false,
                'match_type' => __('Source file match', 'holyprof-source-locator'),
                'note' => __('No exact registered settings page was found. Matching files are shown below.', 'holyprof-source-locator'),
                'supporting_path' => $supporting_path,
                'supporting_line' => $supporting_line,
                'supporting_snippet' => $supporting_snippet,
                'supporting_source_label' => isset($best_result['source_label']) ? (string) $best_result['source_label'] : '',
                'related_evidence' => $this->sanitize_feature_evidence_items($evidence),
                'has_real_match' => true,
                'has_real_url' => false,
                'has_known_owner' => true,
                'has_supporting_evidence' => true,
                'is_search_hint' => false,
            );
        }

        return $results;
    }

    private function infer_owner_from_file_result($file_result) {
        $source_category = isset($file_result['source_category']) ? (string) $file_result['source_category'] : '';
        $source_label = isset($file_result['source_label']) ? (string) $file_result['source_label'] : '';

        if ($source_category === 'plugin') {
            $owner_slug = $this->get_file_result_owner_slug($file_result);
            $owner_name = $this->slug_to_label($owner_slug);

            if ($owner_slug !== '') {
                $plugin_name = $this->get_plugin_name_by_slug($owner_slug, $owner_slug . '/' . $owner_slug . '.php');
                if ($plugin_name !== '') {
                    $owner_name = $plugin_name;
                }
            }

            return array(
                'owner_type' => 'plugin',
                'owner_slug' => $owner_slug,
                'name' => $owner_name,
                'display_name' => sprintf(__('Active Plugin: %s', 'holyprof-source-locator'), $owner_name),
            );
        }

        if ($source_category === 'theme') {
            $theme_name = $this->extract_theme_name_from_source_label($source_label);
            $theme_slug = $this->get_file_result_owner_slug($file_result);

            return array(
                'owner_type' => 'theme',
                'owner_slug' => $theme_slug !== '' ? $theme_slug : sanitize_key($theme_name),
                'name' => $theme_name,
                'display_name' => $source_label !== '' ? $source_label : sprintf(__('Theme: %s', 'holyprof-source-locator'), $theme_name),
            );
        }

        return array(
            'owner_type' => 'unknown',
            'owner_slug' => '',
            'name' => '',
            'display_name' => '',
        );
    }

    private function get_file_result_owner_slug($file_result) {
        $file_path = isset($file_result['file_path']) ? (string) $file_result['file_path'] : '';

        if ($file_path === '') {
            return '';
        }

        $segments = explode('/', str_replace('\\', '/', $file_path));

        return isset($segments[0]) ? sanitize_key((string) $segments[0]) : '';
    }

    private function extract_theme_name_from_source_label($source_label) {
        $source_label = (string) $source_label;

        if ($source_label === '') {
            return __('Theme', 'holyprof-source-locator');
        }

        if (strpos($source_label, ':') !== false) {
            $parts = explode(':', $source_label, 2);
            $candidate = trim((string) $parts[1]);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return $source_label;
    }

    private function get_feature_file_source_score($file_result, $variants) {
        $score = 0;
        $file_path = $this->normalize_search_value(isset($file_result['file_path']) ? $file_result['file_path'] : '');
        $snippet = $this->normalize_search_value(isset($file_result['snippet']) ? $file_result['snippet'] : '');

        foreach ($variants as $variant) {
            if ($variant === '') {
                continue;
            }

            if (strpos($file_path, $variant) !== false) {
                $score += 220;
            }

            if ($this->contains_whole_phrase($file_path, $variant)) {
                $score += 140;
            }

            if (strpos($snippet, $variant) !== false) {
                $score += 80;
            }
        }

        if (isset($file_result['match_kind']) && $file_result['match_kind'] === 'path') {
            $score += 180;
        }

        return $score;
    }

    private function get_feature_location_match_analysis($entry, $variants, $location_kind) {
        $best_match = array(
            'score' => 0,
            'reason' => '',
            'reason_key' => '',
            'matched_term' => '',
            'reason_matches' => array(),
        );
        $haystacks = array(
            'page_title' => array('value' => isset($entry['page_title']) ? (string) $entry['page_title'] : '', 'label' => __('Page title', 'holyprof-source-locator'), 'exact' => 360, 'whole' => 310, 'contains' => 260),
            'menu_title' => array('value' => isset($entry['menu_title']) ? (string) $entry['menu_title'] : '', 'label' => __('Menu title', 'holyprof-source-locator'), 'exact' => 330, 'whole' => 270, 'contains' => 220),
            'path' => array('value' => isset($entry['path']) ? (string) $entry['path'] : '', 'label' => __('Menu path', 'holyprof-source-locator'), 'exact' => 320, 'whole' => 250, 'contains' => 210),
            'field_label' => array('value' => isset($entry['field_label']) ? (string) $entry['field_label'] : '', 'label' => __('Registered setting field', 'holyprof-source-locator'), 'exact' => 350, 'whole' => 300, 'contains' => 250),
            'section_title' => array('value' => isset($entry['section_title']) ? (string) $entry['section_title'] : '', 'label' => __('Registered settings section', 'holyprof-source-locator'), 'exact' => 320, 'whole' => 260, 'contains' => 220),
            'setting_name' => array('value' => isset($entry['setting_name']) ? (string) $entry['setting_name'] : '', 'label' => __('Registered setting name', 'holyprof-source-locator'), 'exact' => 320, 'whole' => 250, 'contains' => 220),
            'slug' => array('value' => isset($entry['slug']) ? (string) $entry['slug'] : '', 'label' => __('Admin page slug', 'holyprof-source-locator'), 'exact' => 340, 'whole' => 280, 'contains' => 240),
        );

        foreach ($haystacks as $reason_key => $haystack) {
            foreach ($variants as $variant) {
                $match = $this->score_feature_haystack_match($haystack['value'], $variant);

                if ($match['score'] <= 0) {
                    continue;
                }

                $score = $match['kind'] === 'exact' ? $haystack['exact'] : ($match['kind'] === 'whole' ? $haystack['whole'] : $haystack['contains']);

                $best_match['reason_matches'][] = array(
                    'score' => $score,
                    'reason' => sprintf(
                        /* translators: 1: field that matched, 2: search term */
                        __('Matched %1$s for "%2$s".', 'holyprof-source-locator'),
                        $haystack['label'],
                        $variant
                    ),
                    'reason_key' => $reason_key,
                    'matched_term' => $variant,
                );

                if ($score > $best_match['score']) {
                    $best_match['score'] = $score;
                    $best_match['reason_key'] = $reason_key;
                    $best_match['matched_term'] = $variant;
                }
            }
        }

        if ($best_match['score'] > 0 && ! empty($best_match['reason_matches'])) {
            usort(
                $best_match['reason_matches'],
                function ($left, $right) {
                    return ((int) $right['score']) <=> ((int) $left['score']);
                }
            );

            $best_match['reason_matches'] = array_slice($best_match['reason_matches'], 0, 2);
            $best_match['reason'] = implode(' ', wp_list_pluck($best_match['reason_matches'], 'reason'));
        }

        if ($best_match['score'] === 0 && $location_kind === 'settings' && (! empty($entry['field_label']) || ! empty($entry['section_title']) || ! empty($entry['setting_name']))) {
            $best_match = array(
                'score' => 150,
                'reason' => __('Matched registered settings metadata.', 'holyprof-source-locator'),
                'reason_key' => 'registered_metadata',
                'matched_term' => isset($variants[0]) ? (string) $variants[0] : '',
                'reason_matches' => array(),
            );
        }

        return $best_match;
    }

    private function score_feature_haystack_match($haystack, $variant) {
        $haystack = $this->normalize_loose_search_value($haystack);
        $variant = $this->normalize_loose_search_value($variant);

        if ($haystack === '' || $variant === '') {
            return array('score' => 0, 'kind' => '');
        }

        if ($haystack === $variant) {
            return array('score' => 3, 'kind' => 'exact');
        }

        if ($this->contains_whole_phrase($haystack, $variant)) {
            return array('score' => 2, 'kind' => 'whole');
        }

        if (strpos($haystack, $variant) !== false || $this->contains_partial_phrase($haystack, $variant) || $this->contains_word_fragment_match($haystack, $variant)) {
            return array('score' => 1, 'kind' => 'contains');
        }

        return array('score' => 0, 'kind' => '');
    }

    private function build_feature_location_confidence($location_kind, $analysis, $support_flags, $related_evidence, $owner) {
        $match_score = isset($analysis['score']) ? (int) $analysis['score'] : 0;
        $owner_type = isset($owner['owner_type']) ? (string) $owner['owner_type'] : 'unknown';
        $has_real_url = ! empty($support_flags['has_real_url']);
        $has_known_owner = ! empty($support_flags['has_known_owner']);
        $has_evidence = ! empty($related_evidence);

        if ($location_kind === 'file-source') {
            return array(
                'level' => 'likely',
                'label' => $owner_type === 'theme'
                    ? __('Likely source theme', 'holyprof-source-locator')
                    : __('Likely source plugin', 'holyprof-source-locator'),
                'score' => 220,
            );
        }

        if ($has_real_url && $has_known_owner && $match_score >= 300) {
            return array(
                'level' => 'exact',
                'label' => __('Exact settings page', 'holyprof-source-locator'),
                'score' => 320,
            );
        }

        if (($has_real_url || $has_known_owner || $has_evidence) && $match_score >= 220) {
            return array(
                'level' => 'likely',
                'label' => __('Likely settings page', 'holyprof-source-locator'),
                'score' => 220,
            );
        }

        return array(
            'level' => 'possible',
            'label' => __('Possible settings page', 'holyprof-source-locator'),
            'score' => 120,
        );
    }

    private function is_valid_admin_url($url) {
        $url = (string) $url;

        if ($url === '') {
            return false;
        }

        return strpos($url, admin_url()) === 0;
    }

    private function rank_settings_results($results, $variants) {
        usort(
            $results,
            function ($left, $right) use ($variants) {
                $left_score = $this->get_settings_result_score($left, $variants);
                $right_score = $this->get_settings_result_score($right, $variants);

                if ($left_score === $right_score) {
                    return strcasecmp(
                        isset($left['title']) ? (string) $left['title'] : '',
                        isset($right['title']) ? (string) $right['title'] : ''
                    );
                }

                return $right_score <=> $left_score;
            }
        );

        return $results;
    }

    private function get_settings_result_score($result, $variants) {
        $score = 0;
        $title = $this->normalize_search_value(isset($result['title']) ? $result['title'] : '');
        $menu_title = $this->normalize_search_value(isset($result['menu_title']) ? $result['menu_title'] : '');
        $page_title = $this->normalize_search_value(isset($result['page_title']) ? $result['page_title'] : '');
        $section_title = $this->normalize_search_value(isset($result['section_title']) ? $result['section_title'] : '');
        $field_label = $this->normalize_search_value(isset($result['field_label']) ? $result['field_label'] : '');
        $slug = $this->normalize_search_value(isset($result['slug']) ? $result['slug'] : '');
        $source = $this->normalize_search_value(isset($result['source']) ? $result['source'] : '');

        foreach ($variants as $needle) {
            if ($title === $needle || $menu_title === $needle || $page_title === $needle) {
                $score += 120;
            }

            if (strpos($title, $needle) !== false) {
                $score += 70;
            }

            if (strpos($menu_title, $needle) !== false) {
                $score += 60;
            }

            if (strpos($page_title, $needle) !== false) {
                $score += 55;
            }

            if (strpos($section_title, $needle) !== false) {
                $score += 40;
            }

            if (strpos($field_label, $needle) !== false) {
                $score += 35;
            }

            if (strpos($slug, $needle) !== false) {
                $score += 25;
            }
        }

        if (strpos($source, 'likely wordpress setting') !== false) {
            $score += 100;
        } elseif (strpos($source, 'admin submenu') !== false) {
            $score += 30;
        } elseif (strpos($source, 'admin menu') !== false) {
            $score += 20;
        }

        if ($this->is_core_settings_slug($slug)) {
            $score += 30;
        }

        return $score;
    }

    private function rank_file_results($results, $search_variants, $is_settings_search, $is_text_finder_search, $is_code_search) {
        usort(
            $results,
            function ($left, $right) use ($search_variants, $is_settings_search, $is_text_finder_search, $is_code_search) {
                $left_score = $this->get_file_result_score($left, $search_variants, $is_settings_search, $is_text_finder_search, $is_code_search);
                $right_score = $this->get_file_result_score($right, $search_variants, $is_settings_search, $is_text_finder_search, $is_code_search);

                if ($left_score === $right_score) {
                    return ($left['line_number'] ?? 0) <=> ($right['line_number'] ?? 0);
                }

                return $right_score <=> $left_score;
            }
        );

        return $results;
    }

    private function rank_admin_page_results($results, $variants, $is_settings_search, $is_text_finder_search) {
        usort(
            $results,
            function ($left, $right) use ($variants, $is_settings_search, $is_text_finder_search) {
                $left_score = $this->get_admin_page_result_score($left, $variants, $is_settings_search, $is_text_finder_search);
                $right_score = $this->get_admin_page_result_score($right, $variants, $is_settings_search, $is_text_finder_search);

                if ($left_score === $right_score) {
                    return strcasecmp(
                        isset($left['page_title']) ? (string) $left['page_title'] : '',
                        isset($right['page_title']) ? (string) $right['page_title'] : ''
                    );
                }

                return $right_score <=> $left_score;
            }
        );

        return $results;
    }

    private function get_file_result_score($result, $search_variants, $is_settings_search, $is_text_finder_search, $is_code_search) {
        $score = 0;
        $snippet = $this->normalize_search_value(isset($result['snippet']) ? $result['snippet'] : '');
        $file_path = $this->normalize_search_value(isset($result['file_path']) ? $result['file_path'] : '');
        $source_type = $this->normalize_search_value(isset($result['source_type']) ? $result['source_type'] : '');
        $source_label = $this->normalize_search_value(isset($result['source_label']) ? $result['source_label'] : '');
        $source_category = $this->normalize_search_value(isset($result['source_category']) ? $result['source_category'] : '');
        $match_kind = isset($result['match_kind']) ? (string) $result['match_kind'] : 'content';

        foreach ($search_variants as $variant) {
            if ($variant === '') {
                continue;
            }

            if ($match_kind === 'path' && ($file_path === $variant || wp_basename($file_path) === $variant)) {
                $score += 320;
            } elseif ($snippet === $variant) {
                $score += 180;
            }

            if (strpos($snippet, $variant) !== false) {
                $score += 90;
            }

            if (strpos($file_path, $variant) !== false) {
                $score += 12;
            }

            if ($this->contains_whole_phrase($snippet, $variant)) {
                $score += 45;
            }

            if ($this->contains_whole_phrase($file_path, $variant)) {
                $score += 10;
            }
        }

        $score += $this->get_file_location_boost($file_path, $source_label, $source_type, $source_category, $is_code_search);
        $score += $this->get_php_code_pattern_score($snippet, $file_path, $search_variants, $is_code_search);

        if ($match_kind === 'path') {
            $score += 220;
        }

        if (! empty($result['hook_name'])) {
            $score += 180;
        }

        if ($is_text_finder_search) {
            $score += 20;

            if ($source_type === $this->normalize_search_value(__('Template', 'holyprof-source-locator'))) {
                $score += 60;
            } elseif ($source_type === $this->normalize_search_value(__('Theme', 'holyprof-source-locator'))) {
                $score += 35;
            } elseif ($source_type === $this->normalize_search_value(__('Plugin', 'holyprof-source-locator'))) {
                $score += 25;
            }
        }

        if ($is_settings_search) {
            $score -= 20;
        }

        if ($is_code_search && ! $is_settings_search) {
            $score += 25;
        }

        return $score;
    }

    private function get_admin_page_result_score($result, $variants, $is_settings_search, $is_text_finder_search) {
        $score = 0;
        $page_title = $this->normalize_search_value(isset($result['page_title']) ? $result['page_title'] : '');
        $menu_title = $this->normalize_search_value(isset($result['menu_title']) ? $result['menu_title'] : '');
        $plugin_name = $this->normalize_search_value(isset($result['plugin_name']) ? $result['plugin_name'] : '');
        $slug = $this->normalize_search_value(isset($result['slug']) ? $result['slug'] : '');
        $path = $this->normalize_search_value(isset($result['path']) ? $result['path'] : '');

        foreach ($variants as $variant) {
            if ($variant === '') {
                continue;
            }

            if ($page_title === $variant || $menu_title === $variant) {
                $score += 320;
            }

            if (strpos($page_title, $variant) !== false) {
                $score += 140;
            }

            if (strpos($menu_title, $variant) !== false) {
                $score += 120;
            }

            if (strpos($plugin_name, $variant) !== false) {
                $score += 45;
            }

            if (strpos($slug, $variant) !== false || strpos($path, $variant) !== false) {
                $score += 35;
            }
        }

        if (strpos($path, 'settings') !== false || strpos($page_title, 'settings') !== false) {
            $score += 60;
        }

        if (! empty($result['url'])) {
            $score += 25;
        }

        if ($is_text_finder_search) {
            $score += 35;
        }

        if ($is_settings_search) {
            $score += 30;
        }

        if ($this->is_likely_code_search(implode(' ', $variants), $variants)) {
            $score -= 160;
        }

        return $score;
    }

    private function get_admin_menu_entries() {
        global $menu, $submenu;

        $entries = array();
        $top_level_lookup = array();

        foreach ((array) $menu as $menu_item) {
            if (! is_array($menu_item) || empty($menu_item[2])) {
                continue;
            }

            $menu_title = $this->clean_menu_label(isset($menu_item[0]) ? $menu_item[0] : '');
            $capability = isset($menu_item[1]) ? sanitize_text_field((string) $menu_item[1]) : 'manage_options';
            $slug = sanitize_text_field((string) $menu_item[2]);

            if ($this->is_ignored_admin_slug($slug)) {
                continue;
            }

            $page_title = $menu_title;
            $path = $menu_title;

            $entry = array(
                'title' => $menu_title,
                'menu_title' => $menu_title,
                'page_title' => $page_title,
                'slug' => $slug,
                'parent_slug' => '',
                'capability' => $capability,
                'hookname' => $this->get_admin_page_hookname($slug, ''),
                'path' => $path,
                'url' => $this->build_admin_url($slug, ''),
                'source' => __('Admin Menu', 'holyprof-source-locator'),
                'match_type' => __('Menu page', 'holyprof-source-locator'),
                'source_type' => $this->get_settings_source_type($slug, $path, __('Admin Menu', 'holyprof-source-locator')),
                'is_clickable' => true,
            );

            $entries[] = $entry;
            $top_level_lookup[$slug] = $menu_title;
        }

        foreach ((array) $submenu as $parent_slug => $submenu_items) {
            $parent_title = isset($top_level_lookup[$parent_slug]) ? $top_level_lookup[$parent_slug] : $this->slug_to_label($parent_slug);

            foreach ((array) $submenu_items as $submenu_item) {
                if (! is_array($submenu_item) || empty($submenu_item[2])) {
                    continue;
                }

                $menu_title = $this->clean_menu_label(isset($submenu_item[0]) ? $submenu_item[0] : '');
                $capability = isset($submenu_item[1]) ? sanitize_text_field((string) $submenu_item[1]) : 'manage_options';
                $page_title = $this->clean_menu_label(isset($submenu_item[3]) ? $submenu_item[3] : $menu_title);
                $slug = sanitize_text_field((string) $submenu_item[2]);

                if ($this->is_ignored_admin_slug($slug)) {
                    continue;
                }

                $entries[] = array(
                    'title' => $page_title !== '' ? $page_title : $menu_title,
                    'menu_title' => $menu_title,
                    'page_title' => $page_title !== '' ? $page_title : $menu_title,
                    'slug' => $slug,
                    'parent_slug' => (string) $parent_slug,
                    'capability' => $capability,
                    'hookname' => $this->get_admin_page_hookname($slug, (string) $parent_slug),
                    'path' => trim($parent_title . ' > ' . $menu_title),
                    'url' => $this->build_admin_url($slug, (string) $parent_slug),
                    'source' => __('Admin Submenu', 'holyprof-source-locator'),
                    'match_type' => __('Submenu page', 'holyprof-source-locator'),
                    'source_type' => $this->get_settings_source_type($slug, trim($parent_title . ' > ' . $menu_title), __('Admin Submenu', 'holyprof-source-locator')),
                    'is_clickable' => true,
                );
            }
        }

        return $entries;
    }

    private function get_plugin_admin_page_entries() {
        $entries = array();

        foreach ($this->get_admin_menu_entries() as $entry) {
            $callback_files = $this->get_admin_page_callback_files(isset($entry['hookname']) ? (string) $entry['hookname'] : '');
            $plugin_details = ! empty($callback_files)
                ? $this->get_plugin_details_from_files($callback_files)
                : $this->guess_plugin_details_from_admin_entry($entry);

            if (empty($plugin_details['plugin_slug']) && ! empty($callback_files)) {
                $plugin_details = $this->guess_plugin_details_from_admin_entry($entry);
            }

            if (empty($plugin_details['plugin_slug'])) {
                continue;
            }

            $entry['plugin_name'] = $plugin_details['plugin_name'];
            $entry['plugin_slug'] = $plugin_details['plugin_slug'];
            $entry['callback_files'] = array_values($callback_files);
            $entries[] = $entry;
        }

        return $entries;
    }

    private function get_registered_settings_entries() {
        global $wp_registered_settings, $wp_settings_sections, $wp_settings_fields;

        $entries = array();
        $page_map = $this->get_settings_page_map();

        foreach ((array) $wp_settings_sections as $page_slug => $sections) {
            foreach ((array) $sections as $section_id => $section) {
                $page_slug = sanitize_text_field((string) $page_slug);
                $section_id = sanitize_key((string) $section_id);
                $section_title = $this->clean_menu_label(isset($section['title']) ? $section['title'] : '');
                $page_data = isset($page_map[$page_slug]) ? $page_map[$page_slug] : $this->build_settings_page_data($page_slug);

                if (isset($wp_settings_fields[$page_slug][$section_id])) {
                    foreach ((array) $wp_settings_fields[$page_slug][$section_id] as $field_id => $field) {
                        $field_title = $this->clean_menu_label(isset($field['title']) ? $field['title'] : '');
                        $field_id = sanitize_key((string) $field_id);

                        $entries[] = array(
                            'title' => $field_title !== '' ? $field_title : $section_title,
                            'menu_title' => isset($page_data['menu_title']) ? $page_data['menu_title'] : '',
                            'page_title' => isset($page_data['page_title']) ? $page_data['page_title'] : '',
                            'section_title' => $section_title,
                            'field_label' => $field_title,
                            'setting_name' => $field_id,
                            'slug' => $page_slug,
                            'capability' => isset($page_data['capability']) ? $page_data['capability'] : '',
                            'path' => $this->build_registered_setting_path($page_data, $section_title, $field_title),
                            'url' => isset($page_data['url']) ? $page_data['url'] : $this->build_admin_url($page_slug, isset($page_data['parent_slug']) ? (string) $page_data['parent_slug'] : ''),
                            'source' => __('Registered Settings Field', 'holyprof-source-locator'),
                            'match_type' => __('Settings field', 'holyprof-source-locator'),
                            'source_type' => $this->get_registered_settings_source_type($page_slug, $page_data),
                            'is_clickable' => ! empty($page_data['url']) || $this->build_admin_url($page_slug, isset($page_data['parent_slug']) ? (string) $page_data['parent_slug'] : '') !== '',
                        );
                    }
                } else {
                    $entries[] = array(
                        'title' => $section_title !== '' ? $section_title : (isset($page_data['page_title']) ? $page_data['page_title'] : $page_slug),
                        'menu_title' => isset($page_data['menu_title']) ? $page_data['menu_title'] : '',
                        'page_title' => isset($page_data['page_title']) ? $page_data['page_title'] : '',
                        'section_title' => $section_title,
                        'field_label' => '',
                        'setting_name' => $section_id,
                        'slug' => $page_slug,
                        'capability' => isset($page_data['capability']) ? $page_data['capability'] : '',
                        'path' => $this->build_registered_setting_path($page_data, $section_title, ''),
                        'url' => isset($page_data['url']) ? $page_data['url'] : $this->build_admin_url($page_slug, isset($page_data['parent_slug']) ? (string) $page_data['parent_slug'] : ''),
                        'source' => __('Registered Settings Section', 'holyprof-source-locator'),
                        'match_type' => __('Settings section', 'holyprof-source-locator'),
                        'source_type' => $this->get_registered_settings_source_type($page_slug, $page_data),
                        'is_clickable' => ! empty($page_data['url']) || $this->build_admin_url($page_slug, isset($page_data['parent_slug']) ? (string) $page_data['parent_slug'] : '') !== '',
                    );
                }
            }
        }

        foreach ((array) $wp_registered_settings as $setting_name => $setting_args) {
            $setting_name = sanitize_key((string) $setting_name);
            $group = isset($setting_args['group']) ? sanitize_text_field((string) $setting_args['group']) : '';
            $page_data = isset($page_map[$group]) ? $page_map[$group] : $this->build_settings_page_data($group);

            $entries[] = array(
                'title' => $setting_name,
                'menu_title' => isset($page_data['menu_title']) ? $page_data['menu_title'] : '',
                'page_title' => isset($page_data['page_title']) ? $page_data['page_title'] : '',
                'section_title' => '',
                'field_label' => '',
                'setting_name' => $setting_name,
                'slug' => $group,
                'capability' => isset($page_data['capability']) ? $page_data['capability'] : '',
                'path' => $this->build_registered_setting_path($page_data, '', $setting_name),
                'url' => isset($page_data['url']) ? $page_data['url'] : $this->build_admin_url($group, isset($page_data['parent_slug']) ? (string) $page_data['parent_slug'] : ''),
                'source' => __('Registered Setting', 'holyprof-source-locator'),
                'match_type' => __('Setting name', 'holyprof-source-locator'),
                'source_type' => $this->get_registered_settings_source_type($group, $page_data),
                'is_clickable' => ! empty($page_data['url']) || $this->build_admin_url($group, isset($page_data['parent_slug']) ? (string) $page_data['parent_slug'] : '') !== '',
            );
        }

        return $entries;
    }

    private function menu_entry_matches($entry, $needle) {
        return $this->matches_entry_variants(
            array(
                isset($entry['menu_title']) ? $entry['menu_title'] : '',
                isset($entry['page_title']) ? $entry['page_title'] : '',
                isset($entry['slug']) ? $entry['slug'] : '',
                isset($entry['path']) ? $entry['path'] : '',
            ),
            $this->get_search_variants($needle)
        );
    }

    private function registered_setting_matches($entry, $needle) {
        return $this->matches_entry_variants(
            array(
                isset($entry['title']) ? $entry['title'] : '',
                isset($entry['menu_title']) ? $entry['menu_title'] : '',
                isset($entry['page_title']) ? $entry['page_title'] : '',
                isset($entry['section_title']) ? $entry['section_title'] : '',
                isset($entry['field_label']) ? $entry['field_label'] : '',
                isset($entry['setting_name']) ? $entry['setting_name'] : '',
                isset($entry['slug']) ? $entry['slug'] : '',
                isset($entry['path']) ? $entry['path'] : '',
            ),
            $this->get_search_variants($needle)
        );
    }

    private function build_admin_page_match_result($entry, $variants) {
        $field_match = $this->matches_entry_variants(
            array(
                isset($entry['title']) ? $entry['title'] : '',
                isset($entry['menu_title']) ? $entry['menu_title'] : '',
                isset($entry['page_title']) ? $entry['page_title'] : '',
                isset($entry['slug']) ? $entry['slug'] : '',
                isset($entry['path']) ? $entry['path'] : '',
                isset($entry['plugin_name']) ? $entry['plugin_name'] : '',
            ),
            $variants
        );

        if (! $field_match) {
            return array();
        }

        $result = array(
            'title' => isset($entry['page_title']) && $entry['page_title'] !== '' ? $entry['page_title'] : (isset($entry['title']) ? $entry['title'] : ''),
            'menu_title' => isset($entry['menu_title']) ? $entry['menu_title'] : '',
            'page_title' => isset($entry['page_title']) ? $entry['page_title'] : '',
            'slug' => isset($entry['slug']) ? $entry['slug'] : '',
            'path' => isset($entry['path']) ? $entry['path'] : '',
            'url' => isset($entry['url']) ? $entry['url'] : '',
            'capability' => isset($entry['capability']) ? $entry['capability'] : '',
            'source' => __('Plugin Admin Page', 'holyprof-source-locator'),
            'match_type' => __('Admin page match', 'holyprof-source-locator'),
            'source_type' => __('Plugin', 'holyprof-source-locator'),
            'plugin_name' => isset($entry['plugin_name']) ? $entry['plugin_name'] : '',
            'plugin_slug' => isset($entry['plugin_slug']) ? $entry['plugin_slug'] : '',
            'is_clickable' => ! empty($entry['url']),
            'reason' => $this->get_admin_page_match_reason($entry, $variants),
        );

        if (empty($result['page_title']) && ! empty($result['menu_title'])) {
            $result['page_title'] = $result['menu_title'];
        }

        return $result;
    }

    private function get_admin_page_match_reason($entry, $variants) {
        $fields = array(
            'page_title' => __('page title', 'holyprof-source-locator'),
            'menu_title' => __('menu title', 'holyprof-source-locator'),
            'slug' => __('admin page slug', 'holyprof-source-locator'),
            'path' => __('menu path', 'holyprof-source-locator'),
            'plugin_name' => __('plugin name', 'holyprof-source-locator'),
        );

        foreach ($fields as $field_key => $field_label) {
            $value = isset($entry[$field_key]) ? (string) $entry[$field_key] : '';

            foreach ($variants as $variant) {
                if ($variant !== '' && $this->matches_any_variant($value, array($variant))) {
                    return sprintf(
                        /* translators: 1: admin page field label, 2: search term */
                        __('Matched %1$s for "%2$s".', 'holyprof-source-locator'),
                        $field_label,
                        $variant
                    );
                }
            }
        }

        return __('Matched registered admin page data.', 'holyprof-source-locator');
    }

    private function get_likely_settings_hints($variants) {
        $hints = array();

        foreach ($this->get_settings_hint_map() as $keywords => $items) {
            $keyword_list = explode('|', $keywords);

            foreach ($keyword_list as $keyword) {
                $keyword_match = false;

                foreach ($variants as $variant) {
                    if (strpos($variant, $this->normalize_search_value($keyword)) !== false) {
                        $keyword_match = true;
                        break;
                    }
                }

                if (! $keyword_match) {
                    continue;
                }

                foreach ($items as $item) {
                    $hints[] = $item;
                }

                break;
            }
        }

        return $hints;
    }

    private function get_admin_page_callback_files($hookname) {
        $files = array();

        foreach ($this->get_hook_callbacks($hookname) as $callback) {
            $file = $this->get_callback_source_file($callback);

            if ($file === '' || ! $this->is_plugin_file($file)) {
                continue;
            }

            $files[wp_normalize_path($file)] = wp_normalize_path($file);
        }

        return $files;
    }

    private function get_hook_callbacks($hookname) {
        global $wp_filter;

        if ($hookname === '' || empty($wp_filter[$hookname]) || ! is_object($wp_filter[$hookname]) || empty($wp_filter[$hookname]->callbacks)) {
            return array();
        }

        $callbacks = array();

        foreach ((array) $wp_filter[$hookname]->callbacks as $priority_callbacks) {
            foreach ((array) $priority_callbacks as $priority_callback) {
                if (isset($priority_callback['function'])) {
                    $callbacks[] = $priority_callback['function'];
                }
            }
        }

        return $callbacks;
    }

    private function get_callback_source_file($callback) {
        try {
            if (is_array($callback) && isset($callback[0], $callback[1])) {
                $reflection = new ReflectionMethod($callback[0], $callback[1]);
            } elseif (is_string($callback) && strpos($callback, '::') !== false) {
                $reflection = new ReflectionMethod($callback);
            } else {
                $reflection = new ReflectionFunction($callback);
            }
        } catch (ReflectionException $exception) {
            return '';
        }

        $file_name = $reflection->getFileName();

        if (! is_string($file_name) || $file_name === '') {
            return '';
        }

        return wp_normalize_path($file_name);
    }

    private function is_plugin_file($file_path) {
        $file_path = wp_normalize_path((string) $file_path);
        $plugin_dir = wp_normalize_path(WP_PLUGIN_DIR);

        return $file_path !== '' && strpos($file_path, trailingslashit($plugin_dir)) === 0;
    }

    private function get_plugin_details_from_files($files) {
        foreach ((array) $files as $file) {
            $details = $this->get_plugin_details_from_file($file);

            if (! empty($details['plugin_slug'])) {
                return $details;
            }
        }

        return array(
            'plugin_name' => '',
            'plugin_slug' => '',
        );
    }

    private function get_plugin_details_from_file($file_path) {
        $plugin_basename = plugin_basename(wp_normalize_path((string) $file_path));
        $parts = explode('/', $plugin_basename);
        $plugin_slug = count($parts) > 1 ? $parts[0] : basename($parts[0], '.php');
        $plugin_name = $this->get_plugin_name_by_slug($plugin_slug, $plugin_basename);

        return array(
            'plugin_name' => $plugin_name,
            'plugin_slug' => $plugin_slug,
        );
    }

    private function get_plugin_name_by_slug($plugin_slug, $plugin_basename) {
        $plugin_slug = sanitize_key((string) $plugin_slug);
        $plugin_basename = (string) $plugin_basename;

        if ($plugin_slug === '') {
            return '';
        }

        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach ((array) get_plugins() as $plugin_file => $plugin_data) {
            if ($plugin_file === $plugin_basename || strpos($plugin_file, $plugin_slug . '/') === 0) {
                if (! empty($plugin_data['Name'])) {
                    return sanitize_text_field((string) $plugin_data['Name']);
                }
            }
        }

        return $this->slug_to_label($plugin_slug);
    }

    private function get_active_plugin_catalog() {
        $catalog = array();

        foreach ($this->get_active_plugin_paths() as $plugin_path) {
            $plugin_path = wp_normalize_path((string) $plugin_path);
            $plugin_basename = plugin_basename($plugin_path);
            $parts = explode('/', $plugin_basename);
            $plugin_slug = count($parts) > 1 ? $parts[0] : basename($parts[0], '.php');

            if ($plugin_slug === '') {
                continue;
            }

            $catalog[$plugin_slug] = array(
                'plugin_slug' => $plugin_slug,
                'plugin_basename' => $plugin_basename,
                'plugin_name' => $this->get_plugin_name_by_slug($plugin_slug, $plugin_basename),
                'tokens' => $this->get_plugin_identity_tokens($plugin_slug, $plugin_basename, $this->get_plugin_name_by_slug($plugin_slug, $plugin_basename)),
            );
        }

        return array_values($catalog);
    }

    private function get_plugin_identity_tokens($plugin_slug, $plugin_basename, $plugin_name) {
        $tokens = array($plugin_slug, basename((string) $plugin_basename, '.php'), $plugin_name);

        foreach (array($plugin_slug, $plugin_name) as $value) {
            foreach (preg_split('/[\s\-_\.\/]+/', (string) $value) as $token) {
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        $tokens = array_map(array($this, 'normalize_loose_search_value'), $tokens);
        $tokens = array_values(array_unique(array_filter($tokens)));

        return array_values(
            array_filter(
                $tokens,
                function ($token) {
                    return strlen((string) $token) >= 3;
                }
            )
        );
    }

    private function guess_plugin_details_from_admin_entry($entry) {
        $haystack = $this->normalize_loose_search_value(
            implode(
                ' ',
                array_filter(
                    array(
                        isset($entry['slug']) ? (string) $entry['slug'] : '',
                        isset($entry['parent_slug']) ? (string) $entry['parent_slug'] : '',
                        isset($entry['path']) ? (string) $entry['path'] : '',
                        isset($entry['menu_title']) ? (string) $entry['menu_title'] : '',
                        isset($entry['page_title']) ? (string) $entry['page_title'] : '',
                        isset($entry['url']) ? (string) $entry['url'] : '',
                        ! empty($entry['callback_files']) && is_array($entry['callback_files']) ? implode(' ', $entry['callback_files']) : '',
                    )
                )
            )
        );
        $best_match = array(
            'score' => 0,
            'plugin_slug' => '',
            'plugin_name' => '',
        );

        foreach ($this->get_active_plugin_catalog() as $plugin) {
            $plugin_slug = isset($plugin['plugin_slug']) ? (string) $plugin['plugin_slug'] : '';
            $plugin_name = isset($plugin['plugin_name']) ? (string) $plugin['plugin_name'] : '';
            $plugin_basename = isset($plugin['plugin_basename']) ? (string) $plugin['plugin_basename'] : '';
            $plugin_tokens = isset($plugin['tokens']) ? (array) $plugin['tokens'] : array();
            $score = 0;

            if ($plugin_slug !== '' && strpos($haystack, $this->normalize_loose_search_value($plugin_slug)) !== false) {
                $score += 220;
            }

            if ($plugin_name !== '' && strpos($haystack, $this->normalize_loose_search_value($plugin_name)) !== false) {
                $score += 180;
            }

            if ($plugin_basename !== '' && strpos($haystack, $this->normalize_loose_search_value($plugin_basename)) !== false) {
                $score += 160;
            }

            foreach ($plugin_tokens as $token) {
                if ($token !== '' && strpos($haystack, $token) !== false) {
                    $score += strlen($token) >= 6 ? 45 : 25;
                }
            }

            if ($score > $best_match['score']) {
                $best_match = array(
                    'score' => $score,
                    'plugin_slug' => $plugin_slug,
                    'plugin_name' => $plugin_name,
                );
            }
        }

        if ($best_match['score'] > 0) {
            return array(
                'plugin_slug' => $best_match['plugin_slug'],
                'plugin_name' => $best_match['plugin_name'],
            );
        }

        return array(
            'plugin_slug' => '',
            'plugin_name' => '',
        );
    }

    private function infer_location_owner($entry) {
        if (! empty($entry['plugin_name']) || ! empty($entry['plugin_slug'])) {
            $plugin_name = isset($entry['plugin_name']) ? (string) $entry['plugin_name'] : $this->slug_to_label(isset($entry['plugin_slug']) ? (string) $entry['plugin_slug'] : '');
            $plugin_slug = isset($entry['plugin_slug']) ? sanitize_key((string) $entry['plugin_slug']) : sanitize_key($plugin_name);

            return array(
                'owner_type' => 'plugin',
                'owner_slug' => $plugin_slug,
                'name' => $plugin_name,
                'display_name' => sprintf(
                    /* translators: %s: plugin name */
                    __('Active Plugin: %s', 'holyprof-source-locator'),
                    $plugin_name
                ),
            );
        }

        if (! empty($entry['callback_files']) && is_array($entry['callback_files'])) {
            $details = $this->get_plugin_details_from_files($entry['callback_files']);

            if (! empty($details['plugin_slug'])) {
                return array(
                    'owner_type' => 'plugin',
                    'owner_slug' => $details['plugin_slug'],
                    'name' => $details['plugin_name'],
                    'display_name' => sprintf(
                        /* translators: %s: plugin name */
                        __('Active Plugin: %s', 'holyprof-source-locator'),
                        $details['plugin_name']
                    ),
                );
            }
        }

        $source_type = $this->normalize_search_value(isset($entry['source_type']) ? $entry['source_type'] : '');
        $slug = isset($entry['slug']) ? (string) $entry['slug'] : '';

        if ($this->is_core_settings_slug($slug) || $source_type === $this->normalize_search_value(__('WordPress Setting', 'holyprof-source-locator'))) {
            return array(
                'owner_type' => 'wordpress',
                'owner_slug' => 'wordpress',
                'name' => __('WordPress', 'holyprof-source-locator'),
                'display_name' => __('WordPress', 'holyprof-source-locator'),
            );
        }

        if ($source_type === $this->normalize_search_value(__('Theme', 'holyprof-source-locator'))) {
            $theme = wp_get_theme();
            $theme_name = $theme instanceof WP_Theme ? (string) $theme->get('Name') : __('Active Theme', 'holyprof-source-locator');
            $theme_slug = $theme instanceof WP_Theme ? sanitize_key((string) $theme->get_stylesheet()) : 'theme';

            return array(
                'owner_type' => 'theme',
                'owner_slug' => $theme_slug,
                'name' => $theme_name,
                'display_name' => sprintf(
                    /* translators: %s: theme name */
                    __('Active Theme: %s', 'holyprof-source-locator'),
                    $theme_name
                ),
            );
        }

        $guessed_plugin = $this->guess_plugin_details_from_admin_entry($entry);

        if (! empty($guessed_plugin['plugin_slug'])) {
            return array(
                'owner_type' => 'plugin',
                'owner_slug' => $guessed_plugin['plugin_slug'],
                'name' => $guessed_plugin['plugin_name'],
                'display_name' => sprintf(
                    /* translators: %s: plugin name */
                    __('Active Plugin: %s', 'holyprof-source-locator'),
                    $guessed_plugin['plugin_name']
                ),
            );
        }

        return array(
            'owner_type' => 'unknown',
            'owner_slug' => '',
            'name' => '',
            'display_name' => __('Unknown source', 'holyprof-source-locator'),
        );
    }

    private function unique_admin_page_results($results) {
        $unique = array();

        foreach ($results as $result) {
            $key = strtolower(
                (isset($result['slug']) ? $result['slug'] : '') . '|' .
                (isset($result['plugin_name']) ? $result['plugin_name'] : '') . '|' .
                (isset($result['path']) ? $result['path'] : '')
            );

            if (isset($unique[$key])) {
                continue;
            }

            $unique[$key] = $result;
        }

        return array_values($unique);
    }

    private function get_settings_hint_map() {
        return array(
            'discussion|comments|default post settings' => array(
                array(
                    'title' => __('Discussion Settings', 'holyprof-source-locator'),
                    'menu_title' => __('Discussion', 'holyprof-source-locator'),
                    'page_title' => __('Discussion Settings', 'holyprof-source-locator'),
                    'slug' => 'options-discussion.php',
                    'path' => __('Settings > Discussion', 'holyprof-source-locator'),
                    'url' => admin_url('options-discussion.php'),
                    'source' => __('Likely WordPress Setting', 'holyprof-source-locator'),
                    'match_type' => __('Suggested location', 'holyprof-source-locator'),
                    'note' => __('Includes comment defaults and default post settings.', 'holyprof-source-locator'),
                    'source_type' => __('WordPress Setting', 'holyprof-source-locator'),
                    'is_clickable' => true,
                ),
            ),
            'reading' => array(
                array(
                    'title' => __('Reading Settings', 'holyprof-source-locator'),
                    'menu_title' => __('Reading', 'holyprof-source-locator'),
                    'page_title' => __('Reading Settings', 'holyprof-source-locator'),
                    'slug' => 'options-reading.php',
                    'path' => __('Settings > Reading', 'holyprof-source-locator'),
                    'url' => admin_url('options-reading.php'),
                    'source' => __('Likely WordPress Setting', 'holyprof-source-locator'),
                    'match_type' => __('Suggested location', 'holyprof-source-locator'),
                    'source_type' => __('WordPress Setting', 'holyprof-source-locator'),
                    'is_clickable' => true,
                ),
            ),
            'permalink|permalinks' => array(
                array(
                    'title' => __('Permalink Settings', 'holyprof-source-locator'),
                    'menu_title' => __('Permalinks', 'holyprof-source-locator'),
                    'page_title' => __('Permalink Settings', 'holyprof-source-locator'),
                    'slug' => 'options-permalink.php',
                    'path' => __('Settings > Permalinks', 'holyprof-source-locator'),
                    'url' => admin_url('options-permalink.php'),
                    'source' => __('Likely WordPress Setting', 'holyprof-source-locator'),
                    'match_type' => __('Suggested location', 'holyprof-source-locator'),
                    'source_type' => __('WordPress Setting', 'holyprof-source-locator'),
                    'is_clickable' => true,
                ),
            ),
            'breadcrumb|breadcrumbs' => array(
                array(
                    'title' => __('Breadcrumb Settings', 'holyprof-source-locator'),
                    'menu_title' => __('Breadcrumbs', 'holyprof-source-locator'),
                    'page_title' => __('Breadcrumb Settings', 'holyprof-source-locator'),
                    'slug' => 'breadcrumb',
                    'path' => __('Usually provided by an SEO or theme settings page', 'holyprof-source-locator'),
                    'url' => '',
                    'source' => __('Likely Plugin or Theme Setting', 'holyprof-source-locator'),
                    'match_type' => __('Suggested location', 'holyprof-source-locator'),
                    'note' => __('Check SEO, theme options, or customizer-related plugin settings.', 'holyprof-source-locator'),
                    'source_type' => __('Menu Page', 'holyprof-source-locator'),
                    'is_clickable' => false,
                ),
            ),
            'sitemap|sitemaps|seo|robots|schema|canonical|meta description|open graph|og image' => array(
                array(
                    'title' => __('SEO Feature Settings', 'holyprof-source-locator'),
                    'menu_title' => __('SEO Settings', 'holyprof-source-locator'),
                    'page_title' => __('SEO Feature Settings', 'holyprof-source-locator'),
                    'slug' => 'seo',
                    'path' => __('Often located inside an SEO plugin settings page', 'holyprof-source-locator'),
                    'url' => '',
                    'source' => __('Likely Plugin Setting', 'holyprof-source-locator'),
                    'match_type' => __('Suggested location', 'holyprof-source-locator'),
                    'note' => __('Check active SEO plugins such as Rank Math, Yoast SEO, Jetpack, or similar plugin admin pages.', 'holyprof-source-locator'),
                    'source_type' => __('Menu Page', 'holyprof-source-locator'),
                    'is_clickable' => false,
                ),
            ),
            'smtp|email|emails' => array(
                array(
                    'title' => __('Email Delivery Settings', 'holyprof-source-locator'),
                    'menu_title' => __('SMTP / Email', 'holyprof-source-locator'),
                    'page_title' => __('Email Delivery Settings', 'holyprof-source-locator'),
                    'slug' => 'smtp',
                    'path' => __('Usually located inside a mail or SMTP plugin settings page', 'holyprof-source-locator'),
                    'url' => '',
                    'source' => __('Likely Plugin Setting', 'holyprof-source-locator'),
                    'match_type' => __('Suggested location', 'holyprof-source-locator'),
                    'note' => __('Check active SMTP, mailer, or notifications plugins for the exact settings page.', 'holyprof-source-locator'),
                    'source_type' => __('Menu Page', 'holyprof-source-locator'),
                    'is_clickable' => false,
                ),
            ),
            'cache|caching|analytics|checkout|forms|redirect|redirects|security|login|payment|shipping|tax|cookies|consent' => array(
                array(
                    'title' => __('Plugin Feature Settings', 'holyprof-source-locator'),
                    'menu_title' => __('Feature Settings', 'holyprof-source-locator'),
                    'page_title' => __('Plugin Feature Settings', 'holyprof-source-locator'),
                    'slug' => 'feature-settings',
                    'path' => __('Usually located inside the related plugin admin page', 'holyprof-source-locator'),
                    'url' => '',
                    'source' => __('Likely Plugin Setting', 'holyprof-source-locator'),
                    'match_type' => __('Suggested location', 'holyprof-source-locator'),
                    'note' => __('Check the active plugin that owns this feature for matching menu pages, settings sections, and option labels.', 'holyprof-source-locator'),
                    'source_type' => __('Menu Page', 'holyprof-source-locator'),
                    'is_clickable' => false,
                ),
            ),
        );
    }

    private function is_likely_settings_search($search_term) {
        $variants = $this->get_search_variants($search_term);

        foreach ($this->get_settings_search_terms() as $term) {
            foreach ($variants as $variant) {
                if (strpos($variant, $term) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function is_feature_search($search_term, $variants = array()) {
        $variants = ! empty($variants) ? $variants : $this->get_search_variants($search_term);

        if ($this->is_likely_settings_search($search_term)) {
            return true;
        }

        foreach ($this->get_feature_search_terms() as $term) {
            $term = $this->normalize_search_value($term);

            foreach ($variants as $variant) {
                if ($variant !== '' && strpos($variant, $term) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function is_likely_code_search($search_term, $variants = array()) {
        $normalized = $this->normalize_search_value($search_term);
        $variants = ! empty($variants) ? $variants : $this->get_search_variants($search_term);

        if ($normalized === '') {
            return false;
        }

        $code_fragments = array(
            'functions.php',
            'function ',
            'class ',
            'add_action',
            'add_filter',
            'do_action',
            'apply_filters',
            'add_shortcode',
            'shortcode',
            'hook',
            'callback',
            'template',
            'functions',
            'js',
            'css',
            'php',
            '::',
            '->',
            '$',
            '__(',
            'esc_html',
        );

        foreach ($code_fragments as $fragment) {
            if (strpos($normalized, $this->normalize_search_value($fragment)) !== false) {
                return true;
            }
        }

        if (preg_match('/[a-z0-9_:-]+\/[a-z0-9_.-]+/i', $search_term)) {
            return true;
        }

        if (preg_match('/[a-z0-9_]+(?:_[a-z0-9_]+)+/i', $search_term)) {
            return true;
        }

        if (preg_match('/(?:^|[\s(])\.[a-z0-9_-]+/i', $search_term) || preg_match('/(?:^|[\s(])#[a-z0-9_-]+/i', $search_term)) {
            return true;
        }

        foreach ($variants as $variant) {
            if (
                $variant !== ''
                && strpos($variant, ' ') === false
                && preg_match('/^[a-z0-9_:\.-]{3,}$/i', $variant)
                && (
                    strpos($variant, '_') !== false
                    || strpos($variant, '-') !== false
                    || strpos($variant, ':') !== false
                    || strpos($variant, '.') !== false
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function get_settings_search_terms() {
        return array(
            'discussion',
            'reading',
            'permalink',
            'permalinks',
            'comments',
            'comment',
            'default post settings',
            'breadcrumb',
            'breadcrumbs',
            'settings',
            'options',
        );
    }

    private function get_feature_search_terms() {
        return array(
            'sitemap',
            'sitemaps',
            'breadcrumb',
            'breadcrumbs',
            'smtp',
            'cache',
            'caching',
            'analytics',
            'analytic',
            'seo',
            'robots',
            'redirect',
            'redirects',
            'checkout',
            'email',
            'emails',
            'forms',
            'form',
            'security',
            'login',
            'payment',
            'shipping',
            'tax',
            'schema',
            'open graph',
            'og image',
            'canonical',
            'meta description',
            'cookies',
            'consent',
            'settings',
            'options',
        );
    }

    private function is_core_settings_slug($slug) {
        $core_slugs = array(
            'options-discussion.php',
            'options-reading.php',
            'options-permalink.php',
            'options-general.php',
            'options-writing.php',
            'options-media.php',
            'options-privacy.php',
        );

        return in_array($slug, $core_slugs, true);
    }

    private function should_search_files($scope) {
        return in_array($scope, array('all', 'best-matches', 'plugin', 'theme', 'php', 'css', 'js', 'templates', 'hooks'), true);
    }

    private function should_search_settings($scope) {
        return in_array($scope, array('all', 'best-matches', 'plugin', 'theme', 'settings-admin'), true);
    }

    private function should_search_admin_pages($scope) {
        return in_array($scope, array('all', 'best-matches', 'plugin', 'settings-admin'), true);
    }

    private function should_search_feature_locations($scope) {
        return in_array($scope, array('all', 'best-matches', 'plugin', 'theme', 'settings-admin'), true);
    }

    private function admin_page_entry_matches_scope($entry, $scope) {
        if ($scope === 'all' || $scope === 'best-matches' || $scope === 'plugin' || $scope === 'settings-admin') {
            return true;
        }

        return false;
    }

    private function filter_roots_by_scope($roots, $scope) {
        if (! in_array($scope, array('plugin', 'theme'), true)) {
            return $roots;
        }

        return array_values(
            array_filter(
                $roots,
                function ($root) use ($scope) {
                    return isset($root['category']) && $root['category'] === $scope;
                }
            )
        );
    }

    private function filter_settings_results_by_scope($results, $scope) {
        if ($scope === 'all') {
            return $results;
        }

        return array_values(
            array_filter(
                $results,
                function ($result) use ($scope) {
                    $source_type = isset($result['source_type']) ? $this->normalize_search_value($result['source_type']) : '';
                    $source = isset($result['source']) ? $this->normalize_search_value($result['source']) : '';

                    if ($scope === 'plugin') {
                        return $source_type === $this->normalize_search_value(__('Plugin', 'holyprof-source-locator'))
                            || strpos($source, 'plugin') !== false
                            || $source_type === $this->normalize_search_value(__('Menu Page', 'holyprof-source-locator'));
                    }

                    if ($scope === 'theme') {
                        return $source_type === $this->normalize_search_value(__('Theme', 'holyprof-source-locator'))
                            || strpos($source, 'theme') !== false;
                    }

                    if ($scope === 'settings-admin') {
                        return true;
                    }

                    if ($scope === 'best-matches') {
                        return true;
                    }

                    return true;
                }
            )
        );
    }

    private function get_search_variants($search_term) {
        $normalized = $this->normalize_search_value($search_term);
        $loose = $this->normalize_loose_search_value($search_term);
        $variants = array($normalized, $loose);
        $pairs = array(
            'default post setting' => 'default post settings',
            'comment' => 'comments',
            'breadcrumb' => 'breadcrumbs',
        );

        foreach ($pairs as $singular => $plural) {
            if (strpos($normalized, $singular) !== false) {
                $variants[] = str_replace($singular, $plural, $normalized);
            }

            if (strpos($normalized, $plural) !== false) {
                $variants[] = str_replace($plural, $singular, $normalized);
            }
        }

        foreach ($this->expand_inflected_phrase_variants($normalized) as $variant) {
            $variants[] = $variant;
        }

        foreach ($this->expand_separator_variants($loose) as $variant) {
            $variants[] = $variant;
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function matches_any_variant($value, $variants) {
        $normalized = $this->normalize_search_value($value);

        foreach ($variants as $variant) {
            if ($variant !== '' && strpos($normalized, $variant) !== false) {
                return true;
            }
        }

        return false;
    }

    private function matches_entry_variants($haystacks, $variants) {
        foreach ($haystacks as $haystack) {
            if ($this->matches_any_variant($haystack, $variants)) {
                return true;
            }
        }

        return false;
    }

    private function is_text_finder_search($search_term) {
        $normalized = $this->normalize_search_value($search_term);

        if (strpos($normalized, ' ') !== false) {
            return true;
        }

        return strlen($normalized) >= 12;
    }

    private function get_file_location_boost($file_path, $source_label, $source_type, $source_category, $is_code_search) {
        $score = 0;
        $file_name = wp_basename($file_path);
        $normalized_source_label = $this->normalize_search_value($source_label);
        $normalized_source_type = $this->normalize_search_value($source_type);

        if ($file_name === 'functions.php') {
            $score += 260;
        }

        if ($this->is_template_file($file_path)) {
            $score += 120;
        }

        if ($normalized_source_label === $this->normalize_search_value(__('Active Theme', 'holyprof-source-locator'))) {
            $score += 120;
        } elseif ($normalized_source_label === $this->normalize_search_value(__('Parent Theme', 'holyprof-source-locator'))) {
            $score += 70;
        }

        if ($normalized_source_type === $this->normalize_search_value(__('Plugin', 'holyprof-source-locator')) || $source_category === 'plugin') {
            $score += 80;
        }

        if ($normalized_source_type === $this->normalize_search_value(__('Theme', 'holyprof-source-locator')) || $source_category === 'theme') {
            $score += 90;
        }

        if ($is_code_search) {
            $score += 40;
        }

        return $score;
    }

    private function get_php_code_pattern_score($snippet, $file_path, $variants, $is_code_search) {
        $score = 0;
        $file_extension = strtolower((string) pathinfo($file_path, PATHINFO_EXTENSION));

        if ($file_extension !== 'php' && ! $is_code_search) {
            return $score;
        }

        foreach ($variants as $variant) {
            $identifier = sanitize_key(str_replace(array(':', '-'), '_', (string) $variant));

            if ($identifier === '' || strlen($identifier) < 2) {
                continue;
            }

            $quoted_variant = preg_quote((string) $variant, '/');
            $quoted_identifier = preg_quote($identifier, '/');

            if (preg_match('/\bfunction\s+' . $quoted_identifier . '\s*\(/i', $snippet)) {
                $score += 320;
            }

            if (preg_match('/\bclass\s+' . $quoted_identifier . '\b/i', $snippet)) {
                $score += 300;
            }

            if (preg_match('/\badd_(?:action|filter)\s*\(\s*[\'"]' . $quoted_variant . '[\'"]/i', $snippet)) {
                $score += 280;
            }

            if (preg_match('/\b(?:do_action|apply_filters)\s*\(\s*[\'"]' . $quoted_variant . '[\'"]/i', $snippet)) {
                $score += 280;
            }

            if (preg_match('/\b(?:remove_action|remove_filter|has_action|has_filter)\s*\(\s*[\'"]' . $quoted_variant . '[\'"]/i', $snippet)) {
                $score += 260;
            }

            if (preg_match('/\badd_(?:action|filter)\s*\([^,\n]+,\s*(?:array\s*\([^)]*[\'"]' . $quoted_identifier . '[\'"]|[\'"]' . $quoted_identifier . '[\'"])/i', $snippet)) {
                $score += 260;
            }

            if (preg_match('/\badd_shortcode\s*\(\s*[\'"]' . $quoted_variant . '[\'"]/i', $snippet)) {
                $score += 280;
            }

            if (preg_match('/[\'"]' . $quoted_variant . '[\'"]\s*=>/i', $snippet)) {
                $score += 80;
            }
        }

        return $score;
    }

    private function get_file_source_type($file_path, $root) {
        if ($this->is_template_file($file_path)) {
            return __('Template', 'holyprof-source-locator');
        }

        return isset($root['source_type']) ? $root['source_type'] : __('Plugin', 'holyprof-source-locator');
    }

    private function is_template_file($file_path) {
        $normalized = wp_normalize_path($file_path);
        $extension = strtolower((string) pathinfo($normalized, PATHINFO_EXTENSION));

        if (in_array($extension, array('html', 'txt'), true)) {
            return true;
        }

        if ($extension === 'php' && preg_match('#/(templates?|template-parts?)/#', $normalized)) {
            return true;
        }

        return false;
    }

    private function get_settings_source_type($slug, $path, $source) {
        $slug = (string) $slug;
        $path = $this->normalize_search_value($path);
        $source = $this->normalize_search_value($source);

        if ($this->is_core_settings_slug($slug)) {
            return __('WordPress Setting', 'holyprof-source-locator');
        }

        if (strpos($path, 'appearance') !== false || strpos($path, 'theme') !== false) {
            return __('Theme', 'holyprof-source-locator');
        }

        if (strpos($source, 'menu') !== false) {
            return __('Menu Page', 'holyprof-source-locator');
        }

        return __('Menu Page', 'holyprof-source-locator');
    }

    private function get_registered_settings_source_type($slug, $page_data) {
        $slug = (string) $slug;
        $path = isset($page_data['path']) ? (string) $page_data['path'] : '';

        if ($this->is_core_settings_slug($slug) || in_array($slug, array('general', 'writing', 'reading', 'discussion', 'media', 'permalink', 'privacy'), true)) {
            return __('WordPress Setting', 'holyprof-source-locator');
        }

        if (strpos($this->normalize_search_value($path), 'appearance') !== false || strpos($this->normalize_search_value($path), 'theme') !== false) {
            return __('Theme', 'holyprof-source-locator');
        }

        return __('Plugin', 'holyprof-source-locator');
    }

    private function unique_settings_results($results) {
        $unique = array();

        foreach ($results as $result) {
            $key = strtolower(
                (isset($result['path']) ? $result['path'] : '') . '|' .
                (isset($result['slug']) ? $result['slug'] : '') . '|' .
                (isset($result['source']) ? $result['source'] : '') . '|' .
                (isset($result['field_label']) ? $result['field_label'] : '') . '|' .
                (isset($result['setting_name']) ? $result['setting_name'] : '')
            );

            if (isset($unique[$key])) {
                continue;
            }

            $unique[$key] = $result;
        }

        return array_values($unique);
    }

    private function build_admin_url($slug, $parent_slug = '') {
        $slug = sanitize_text_field((string) $slug);
        $parent_slug = sanitize_text_field((string) $parent_slug);

        if ($slug === '' || $this->is_ignored_admin_slug($slug)) {
            return '';
        }

        if (preg_match('#^(?:[a-z0-9_-]+\.php)(?:\?.*)?$#i', $slug)) {
            return admin_url($slug);
        }

        if ($parent_slug !== '' && preg_match('#^(?:[a-z0-9_-]+\.php)(?:\?.*)?$#i', $parent_slug)) {
            return add_query_arg('page', $slug, admin_url($parent_slug));
        }

        return add_query_arg('page', $slug, admin_url('admin.php'));
    }

    private function is_ignored_admin_slug($slug) {
        $slug = sanitize_text_field((string) $slug);

        if ($slug === '') {
            return true;
        }

        return (bool) preg_match('/^separator\d*$/i', $slug);
    }

    private function get_admin_page_hookname($slug, $parent_slug) {
        $slug = (string) $slug;
        $parent_slug = (string) $parent_slug;

        if ($slug === '' || ! function_exists('get_plugin_page_hookname')) {
            return '';
        }

        return (string) get_plugin_page_hookname($slug, $parent_slug);
    }

    private function get_settings_page_map() {
        $page_map = array();

        foreach ($this->get_admin_menu_entries() as $entry) {
            if (empty($entry['slug'])) {
                continue;
            }

            $page_map[$entry['slug']] = array(
                'menu_title' => isset($entry['menu_title']) ? $entry['menu_title'] : '',
                'page_title' => isset($entry['page_title']) ? $entry['page_title'] : '',
                'path' => isset($entry['path']) ? $entry['path'] : '',
                'url' => isset($entry['url']) ? $entry['url'] : '',
                'capability' => isset($entry['capability']) ? $entry['capability'] : '',
                'parent_slug' => isset($entry['parent_slug']) ? $entry['parent_slug'] : '',
            );
        }

        return $page_map;
    }

    private function build_settings_page_data($slug) {
        $slug = sanitize_text_field((string) $slug);

        $core_page = $this->get_core_settings_page_data($slug);

        if (! empty($core_page)) {
            return $core_page;
        }

        return array(
            'menu_title' => $this->slug_to_label($slug),
            'page_title' => $this->slug_to_label($slug),
            'path' => $this->slug_to_label($slug),
            'url' => '',
            'capability' => '',
            'parent_slug' => '',
        );
    }

    private function get_core_settings_page_data($slug) {
        $slug = sanitize_text_field((string) $slug);
        $pages = array(
            'general' => array('title' => __('General Settings', 'holyprof-source-locator'), 'menu' => __('General', 'holyprof-source-locator'), 'path' => __('Settings > General', 'holyprof-source-locator'), 'url' => admin_url('options-general.php')),
            'writing' => array('title' => __('Writing Settings', 'holyprof-source-locator'), 'menu' => __('Writing', 'holyprof-source-locator'), 'path' => __('Settings > Writing', 'holyprof-source-locator'), 'url' => admin_url('options-writing.php')),
            'reading' => array('title' => __('Reading Settings', 'holyprof-source-locator'), 'menu' => __('Reading', 'holyprof-source-locator'), 'path' => __('Settings > Reading', 'holyprof-source-locator'), 'url' => admin_url('options-reading.php')),
            'discussion' => array('title' => __('Discussion Settings', 'holyprof-source-locator'), 'menu' => __('Discussion', 'holyprof-source-locator'), 'path' => __('Settings > Discussion', 'holyprof-source-locator'), 'url' => admin_url('options-discussion.php')),
            'media' => array('title' => __('Media Settings', 'holyprof-source-locator'), 'menu' => __('Media', 'holyprof-source-locator'), 'path' => __('Settings > Media', 'holyprof-source-locator'), 'url' => admin_url('options-media.php')),
            'permalink' => array('title' => __('Permalink Settings', 'holyprof-source-locator'), 'menu' => __('Permalinks', 'holyprof-source-locator'), 'path' => __('Settings > Permalinks', 'holyprof-source-locator'), 'url' => admin_url('options-permalink.php')),
            'privacy' => array('title' => __('Privacy Settings', 'holyprof-source-locator'), 'menu' => __('Privacy', 'holyprof-source-locator'), 'path' => __('Settings > Privacy', 'holyprof-source-locator'), 'url' => admin_url('options-privacy.php')),
        );

        if (! isset($pages[$slug])) {
            return array();
        }

        return array(
            'menu_title' => $pages[$slug]['menu'],
            'page_title' => $pages[$slug]['title'],
            'path' => $pages[$slug]['path'],
            'url' => $pages[$slug]['url'],
            'capability' => 'manage_options',
            'parent_slug' => 'options-general.php',
        );
    }

    private function build_registered_setting_path($page_data, $section_title, $field_label) {
        $parts = array();

        if (! empty($page_data['path'])) {
            $parts[] = $page_data['path'];
        } elseif (! empty($page_data['page_title'])) {
            $parts[] = $page_data['page_title'];
        }

        if ($section_title !== '') {
            $parts[] = $section_title;
        }

        if ($field_label !== '') {
            $parts[] = $field_label;
        }

        return implode(' > ', $parts);
    }

    private function clean_menu_label($label) {
        return trim(wp_strip_all_tags((string) $label));
    }

    private function slug_to_label($slug) {
        return ucwords(str_replace(array('-', '_', '.php'), ' ', (string) $slug));
    }

    private function normalize_search_value($value) {
        $value = html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES, 'UTF-8');
        $value = strtr(
            $value,
            array(
                "\xE2\x80\x90" => '-',
                "\xE2\x80\x91" => '-',
                "\xE2\x80\x92" => '-',
                "\xE2\x80\x93" => '-',
                "\xE2\x80\x94" => '-',
                "\xE2\x80\x95" => '-',
                "\xE2\x80\x98" => "'",
                "\xE2\x80\x99" => "'",
                "\xE2\x80\x9B" => "'",
                "\xE2\x80\x9C" => '"',
                "\xE2\x80\x9D" => '"',
                "\xE2\x80\x9F" => '"',
                "\xC2\xA0" => ' ',
            )
        );
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value);

        return is_string($value) ? $value : '';
    }

    private function normalize_loose_search_value($value) {
        $value = $this->normalize_search_value($value);

        if ($value === '') {
            return '';
        }

        $value = str_replace(array('-', '_', '/', '\\'), ' ', $value);
        $value = preg_replace('/["\'.,:;!?()[\]{}]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return is_string($value) ? trim($value) : '';
    }

    private function expand_inflected_phrase_variants($value) {
        if ($value === '' || strpos($value, ' ') === false) {
            return array();
        }

        $variants = array();
        $tokens = preg_split('/\s+/', $value);

        if (! is_array($tokens) || empty($tokens)) {
            return array();
        }

        foreach ($tokens as $index => $token) {
            foreach ($this->get_reasonable_plural_variants($token) as $variant_token) {
                if ($variant_token === $token) {
                    continue;
                }

                $variant_tokens = $tokens;
                $variant_tokens[$index] = $variant_token;
                $variants[] = implode(' ', $variant_tokens);
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function expand_separator_variants($value) {
        $value = (string) $value;

        if ($value === '') {
            return array();
        }

        $variants = array(
            $value,
            str_replace('-', ' ', $value),
            str_replace(' ', '-', $value),
            str_replace(' ', '', $value),
            str_replace('-', '', $value),
        );

        if (strpos($value, 'front end') !== false) {
            $variants[] = str_replace('front end', 'frontend', $value);
        }

        if (strpos($value, 'frontend') !== false) {
            $variants[] = str_replace('frontend', 'front end', $value);
            $variants[] = str_replace('frontend', 'front-end', $value);
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function get_reasonable_plural_variants($token) {
        $token = (string) $token;

        if ($token === '' || strlen($token) < 4 || preg_match('/[^a-z]/', $token)) {
            return array($token);
        }

        $variants = array($token);

        if (substr($token, -3) === 'ies' && strlen($token) > 4) {
            $variants[] = substr($token, 0, -3) . 'y';
        } elseif (substr($token, -1) === 'y' && ! preg_match('/[aeiou]y$/', $token)) {
            $variants[] = substr($token, 0, -1) . 'ies';
        }

        if (preg_match('/(ses|xes|zes|ches|shes)$/', $token)) {
            $variants[] = preg_replace('/es$/', '', $token);
        } elseif (substr($token, -1) === 's' && substr($token, -2) !== 'ss') {
            $variants[] = substr($token, 0, -1);
        } elseif (preg_match('/(s|x|z|ch|sh)$/', $token)) {
            $variants[] = $token . 'es';
        } else {
            $variants[] = $token . 's';
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function contains_whole_phrase($haystack, $needle) {
        $haystack = (string) $haystack;
        $needle = (string) $needle;

        if ($haystack === '' || $needle === '') {
            return false;
        }

        return (bool) preg_match('/(^|[^a-z0-9])' . preg_quote($needle, '/') . '([^a-z0-9]|$)/i', $haystack);
    }

    private function contains_partial_phrase($haystack, $needle) {
        $haystack_tokens = array_values(array_filter(explode(' ', (string) $haystack)));
        $needle_tokens = array_values(array_filter(explode(' ', (string) $needle)));

        if (count($haystack_tokens) < 2 || count($needle_tokens) < 2) {
            return false;
        }

        $matched_tokens = 0;

        foreach ($needle_tokens as $needle_token) {
            if (strlen($needle_token) < 3) {
                continue;
            }

            if (in_array($needle_token, $haystack_tokens, true)) {
                $matched_tokens++;
            }
        }

        $required_matches = max(2, count($needle_tokens) - 1);

        return $matched_tokens >= $required_matches;
    }

    private function contains_word_fragment_match($haystack, $needle) {
        $haystack_tokens = array_values(array_filter(explode(' ', (string) $haystack)));
        $needle_tokens = array_values(array_filter(explode(' ', (string) $needle)));

        if (empty($haystack_tokens) || empty($needle_tokens)) {
            return false;
        }

        $matched_tokens = 0;

        foreach ($needle_tokens as $needle_token) {
            if (strlen($needle_token) < 3) {
                continue;
            }

            foreach ($haystack_tokens as $haystack_token) {
                if (strpos($haystack_token, $needle_token) !== false || strpos($needle_token, $haystack_token) !== false) {
                    $matched_tokens++;
                    break;
                }
            }
        }

        if ($matched_tokens === 0) {
            return false;
        }

        $required_matches = count($needle_tokens) >= 3 ? 2 : 1;

        return $matched_tokens >= $required_matches;
    }

    private function empty_response() {
        return array(
            'results' => array(),
            'file_results' => array(),
            'feature_results' => array(),
            'settings_results' => array(),
            'admin_page_results' => array(),
            'is_feature_search' => false,
            'is_settings_search' => false,
            'is_code_search' => false,
            'is_text_finder_search' => false,
            'truncated' => false,
            'max_results' => self::MAX_RESULTS,
        );
    }
}
