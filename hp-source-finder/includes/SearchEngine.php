<?php

if (! defined('ABSPATH')) {
    exit;
}

class HP_Source_Finder_SearchEngine {
    const MAX_FILE_SIZE = 262144;
    const MAX_RESULTS = 200;
    const MAX_SETTINGS_RESULTS = 25;
    const MAX_ADMIN_PAGE_RESULTS = 20;
    const MAX_VISIBLE_TEXT_MATCHES = 6;
    const ADMIN_PAGE_INDEX_TTL = 604800;

    /**
     * @var array<string,mixed>|null
     */
    private $active_admin_page_capture = null;

    /**
     * @var string
     */
    private $captured_admin_page_html = '';

    public function register_admin_page_capture() {
        add_action('current_screen', array($this, 'maybe_begin_admin_page_capture'));
        add_action('shutdown', array($this, 'finish_admin_page_capture'), 0);
    }

    public function search($search_term, $filter) {
        $search_term = trim(sanitize_text_field((string) $search_term));
        $filter = sanitize_key((string) $filter);
        $debug_info = array();

        if ($search_term === '') {
            return $this->empty_response();
        }

        $search_variants = $this->get_search_variants($search_term);
        $is_settings_search = $this->is_likely_settings_search($search_term);
        $is_text_finder_search = $this->is_text_finder_search($search_term);
        $settings_results = $this->should_search_settings($filter)
            ? $this->search_settings_locations($search_term, $filter)
            : array();
        $admin_page_results = $this->should_search_admin_pages($filter)
            ? $this->search_admin_page_locations($filter, $search_variants, $is_settings_search, $is_text_finder_search, $debug_info)
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

                    $file_results = $this->search_file($file_info->getPathname(), $search_variants, $root);

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

                    $file_results = $this->search_file($file_info->getPathname(), $search_variants, $root);

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

        $results = $this->rank_file_results($results, $search_variants, $is_settings_search, $is_text_finder_search);

        return array(
            'results' => $results,
            'file_results' => $results,
            'settings_results' => $settings_results,
            'admin_page_results' => $admin_page_results,
            'is_settings_search' => $is_settings_search,
            'is_text_finder_search' => $is_text_finder_search,
            'debug_info' => $debug_info,
            'truncated' => $truncated,
            'max_results' => self::MAX_RESULTS,
        );
    }

    public function maybe_begin_admin_page_capture($screen) {
        if (! is_admin() || wp_doing_ajax() || ! current_user_can('manage_options')) {
            return;
        }

        if ($this->get_request_method() !== 'GET') {
            return;
        }

        $entry = $this->find_current_plugin_admin_page_entry($screen);

        if (empty($entry)) {
            return;
        }

        $this->active_admin_page_capture = $entry;
        $this->captured_admin_page_html = '';
        ob_start(array($this, 'capture_admin_page_output'));
    }

    public function capture_admin_page_output($buffer) {
        if ($this->active_admin_page_capture !== null && is_string($buffer)) {
            $this->captured_admin_page_html .= $buffer;
        }

        return $buffer;
    }

    public function finish_admin_page_capture() {
        if ($this->active_admin_page_capture === null || $this->captured_admin_page_html === '') {
            return;
        }

        $visible_text_index = $this->extract_visible_admin_page_index($this->captured_admin_page_html);

        if (! empty($visible_text_index)) {
            set_transient(
                $this->get_admin_page_index_transient_key($this->active_admin_page_capture),
                array(
                    'plugin_name' => isset($this->active_admin_page_capture['plugin_name']) ? $this->active_admin_page_capture['plugin_name'] : '',
                    'page_title' => isset($this->active_admin_page_capture['page_title']) ? $this->active_admin_page_capture['page_title'] : '',
                    'slug' => isset($this->active_admin_page_capture['slug']) ? $this->active_admin_page_capture['slug'] : '',
                    'path' => isset($this->active_admin_page_capture['path']) ? $this->active_admin_page_capture['path'] : '',
                    'url' => isset($this->active_admin_page_capture['url']) ? $this->active_admin_page_capture['url'] : '',
                    'captured_at' => time(),
                    'visible_text' => $visible_text_index,
                ),
                self::ADMIN_PAGE_INDEX_TTL
            );
        }

        $this->active_admin_page_capture = null;
        $this->captured_admin_page_html = '';
    }

    private function search_file($file_path, $search_variants, $root) {
        $results = array();
        $file = new SplFileObject($file_path, 'r');
        $line_number = 0;

        while (! $file->eof()) {
            $line = $file->fgets();
            $line_number++;

            if (! $this->matches_any_variant($line, $search_variants)) {
                continue;
            }

            $results[] = array(
                'file_path' => $this->get_relative_path($file_path, $root),
                'source_label' => $root['label'],
                'source_type' => $this->get_file_source_type($file_path, $root),
                'line_number' => $line_number,
                'snippet' => trim($line),
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
                'path' => untrailingslashit(HP_SOURCE_FINDER_PATH),
                'label' => __('HP Source Finder Plugin', 'hp-source-finder'),
                'slug' => 'hp-source-finder',
                'type' => 'directory',
                'category' => 'plugin',
                'source_type' => __('Plugin', 'hp-source-finder'),
            ),
        );

        $stylesheet_directory = untrailingslashit((string) get_stylesheet_directory());
        $template_directory = untrailingslashit((string) get_template_directory());

        if ($stylesheet_directory !== '') {
            $roots[] = array(
                'path' => $stylesheet_directory,
                'label' => __('Active Theme', 'hp-source-finder'),
                'slug' => wp_basename($stylesheet_directory),
                'type' => 'directory',
                'category' => 'theme',
                'source_type' => __('Theme', 'hp-source-finder'),
            );
        }

        if ($template_directory !== '' && $template_directory !== $stylesheet_directory) {
            $roots[] = array(
                'path' => $template_directory,
                'label' => __('Parent Theme', 'hp-source-finder'),
                'slug' => wp_basename($template_directory),
                'type' => 'directory',
                'category' => 'theme',
                'source_type' => __('Theme', 'hp-source-finder'),
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

            $plugin_name = $plugin_parts[0];

            return array(
                'path' => $plugin_directory,
                'label' => sprintf(
                    /* translators: %s: plugin directory name */
                    __('Active Plugin: %s', 'hp-source-finder'),
                    $plugin_name
                ),
                'slug' => $plugin_name,
                'type' => 'directory',
                'category' => 'plugin',
                'source_type' => __('Plugin', 'hp-source-finder'),
            );
        }

        if (! is_file($plugin_path)) {
            return array();
        }

        $plugin_name = basename($plugin_basename, '.php');

        return array(
            'path' => $plugin_path,
            'label' => sprintf(
                /* translators: %s: plugin name */
                __('Active Plugin: %s', 'hp-source-finder'),
                $plugin_name
            ),
            'slug' => $plugin_name,
            'type' => 'file',
            'category' => 'plugin',
            'source_type' => __('Plugin', 'hp-source-finder'),
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
            'code' => array('php', 'css', 'js', 'html', 'txt'),
            'plugins' => array('php', 'css', 'js', 'html', 'txt'),
            'themes' => array('php', 'css', 'js', 'html', 'txt'),
            'templates' => array('php', 'html', 'txt'),
            'wordpress' => array(),
            'settings' => array(),
            'menu-pages' => array(),
        );

        if (! isset($filters[$filter])) {
            return $filters['all'];
        }

        return $filters[$filter];
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

        foreach ($this->get_likely_settings_hints($variants) as $hint) {
            $settings_results[] = $hint;
        }

        return array_slice(
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
    }

    private function search_admin_page_locations($scope, $variants, $is_settings_search, $is_text_finder_search, &$debug_info = array()) {
        $results = array();
        $checked_pages = 0;

        foreach ($this->get_plugin_admin_page_entries() as $entry) {
            if (! $this->admin_page_entry_matches_scope($entry, $scope)) {
                continue;
            }

            $checked_pages++;
            $result = $this->build_admin_page_match_result($entry, $variants, $debug_info);

            if (empty($result)) {
                $debug_info[] = sprintf(
                    /* translators: 1: plugin name, 2: admin page title */
                    __('No admin-page match found yet in %1$s -> %2$s.', 'hp-source-finder'),
                    isset($entry['plugin_name']) ? $entry['plugin_name'] : __('Plugin page', 'hp-source-finder'),
                    isset($entry['page_title']) ? $entry['page_title'] : (isset($entry['slug']) ? $entry['slug'] : __('Unknown page', 'hp-source-finder'))
                );
                continue;
            }

            $results[] = $result;
        }

        if ($checked_pages > 0) {
            $debug_info[] = sprintf(
                /* translators: %d: number of plugin admin pages checked */
                __('Checked %d plugin admin pages for rendered or callback text.', 'hp-source-finder'),
                $checked_pages
            );
        }

        return array_slice(
            $this->rank_admin_page_results(
                $this->unique_admin_page_results($results),
                $variants,
                $is_settings_search,
                $is_text_finder_search
            ),
            0,
            self::MAX_ADMIN_PAGE_RESULTS
        );
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

    private function rank_file_results($results, $search_variants, $is_settings_search, $is_text_finder_search) {
        usort(
            $results,
            function ($left, $right) use ($search_variants, $is_settings_search, $is_text_finder_search) {
                $left_score = $this->get_file_result_score($left, $search_variants, $is_settings_search, $is_text_finder_search);
                $right_score = $this->get_file_result_score($right, $search_variants, $is_settings_search, $is_text_finder_search);

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

    private function get_file_result_score($result, $search_variants, $is_settings_search, $is_text_finder_search) {
        $score = 0;
        $snippet = $this->normalize_search_value(isset($result['snippet']) ? $result['snippet'] : '');
        $file_path = $this->normalize_search_value(isset($result['file_path']) ? $result['file_path'] : '');
        $source_type = $this->normalize_search_value(isset($result['source_type']) ? $result['source_type'] : '');

        foreach ($search_variants as $variant) {
            if ($variant === '') {
                continue;
            }

            if ($snippet === $variant) {
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

        if ($is_text_finder_search) {
            $score += 20;

            if ($source_type === $this->normalize_search_value(__('Template', 'hp-source-finder'))) {
                $score += 60;
            } elseif ($source_type === $this->normalize_search_value(__('Theme', 'hp-source-finder'))) {
                $score += 35;
            } elseif ($source_type === $this->normalize_search_value(__('Plugin', 'hp-source-finder'))) {
                $score += 25;
            }
        }

        if ($is_settings_search) {
            $score -= 20;
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
        $match_origin = $this->normalize_search_value(isset($result['match_origin']) ? $result['match_origin'] : '');
        $callback_matches = isset($result['callback_matches']) && is_array($result['callback_matches']) ? $result['callback_matches'] : array();
        $visible_text_matches = isset($result['visible_text_matches']) && is_array($result['visible_text_matches']) ? $result['visible_text_matches'] : array();

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

            foreach ($callback_matches as $callback_match) {
                $snippet = $this->normalize_search_value(isset($callback_match['snippet']) ? $callback_match['snippet'] : '');

                if ($snippet === $variant) {
                    $score += 340;
                }

                if (strpos($snippet, $variant) !== false) {
                    $score += 220;
                }

                if ($this->contains_whole_phrase($snippet, $variant)) {
                    $score += 120;
                }
            }

            foreach ($visible_text_matches as $visible_text_match) {
                $snippet = $this->normalize_search_value(isset($visible_text_match['snippet']) ? $visible_text_match['snippet'] : '');

                if ($snippet === $variant) {
                    $score += 420;
                }

                if (strpos($snippet, $variant) !== false) {
                    $score += 260;
                }

                if ($this->contains_whole_phrase($snippet, $variant)) {
                    $score += 160;
                }
            }
        }

        if (! empty($visible_text_matches)) {
            $score += 180;
        }

        if ($match_origin === 'visible_text') {
            $score += 220;
        }

        if ($match_origin === 'callback') {
            $score += 140;
        }

        if (strpos($path, 'settings') !== false || strpos($page_title, 'settings') !== false) {
            $score += 60;
        }

        if ($is_text_finder_search) {
            $score += 35;
        }

        if ($is_settings_search) {
            $score += 30;
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
            $slug = sanitize_text_field((string) $menu_item[2]);
            $page_title = $menu_title;
            $path = $menu_title;

            $entry = array(
                'title' => $menu_title,
                'menu_title' => $menu_title,
                'page_title' => $page_title,
                'slug' => $slug,
                'parent_slug' => '',
                'hookname' => $this->get_admin_page_hookname($slug, ''),
                'path' => $path,
                'url' => $this->build_admin_url($slug),
                'source' => __('Admin Menu', 'hp-source-finder'),
                'match_type' => __('Menu page', 'hp-source-finder'),
                'source_type' => $this->get_settings_source_type($slug, $path, __('Admin Menu', 'hp-source-finder')),
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
                $page_title = $this->clean_menu_label(isset($submenu_item[3]) ? $submenu_item[3] : $menu_title);
                $slug = sanitize_text_field((string) $submenu_item[2]);

                $entries[] = array(
                    'title' => $page_title !== '' ? $page_title : $menu_title,
                    'menu_title' => $menu_title,
                    'page_title' => $page_title !== '' ? $page_title : $menu_title,
                    'slug' => $slug,
                    'parent_slug' => (string) $parent_slug,
                    'hookname' => $this->get_admin_page_hookname($slug, (string) $parent_slug),
                    'path' => trim($parent_title . ' > ' . $menu_title),
                    'url' => $this->build_admin_url($slug),
                    'source' => __('Admin Submenu', 'hp-source-finder'),
                    'match_type' => __('Submenu page', 'hp-source-finder'),
                    'source_type' => $this->get_settings_source_type($slug, trim($parent_title . ' > ' . $menu_title), __('Admin Submenu', 'hp-source-finder')),
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

            if (empty($callback_files)) {
                continue;
            }

            $plugin_details = $this->get_plugin_details_from_files($callback_files);

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
                            'path' => $this->build_registered_setting_path($page_data, $section_title, $field_title),
                            'url' => isset($page_data['url']) ? $page_data['url'] : $this->build_admin_url($page_slug),
                            'source' => __('Registered Settings Field', 'hp-source-finder'),
                            'match_type' => __('Settings field', 'hp-source-finder'),
                            'source_type' => $this->get_registered_settings_source_type($page_slug, $page_data),
                            'is_clickable' => ! empty($page_data['url']) || $this->build_admin_url($page_slug) !== '',
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
                        'path' => $this->build_registered_setting_path($page_data, $section_title, ''),
                        'url' => isset($page_data['url']) ? $page_data['url'] : $this->build_admin_url($page_slug),
                        'source' => __('Registered Settings Section', 'hp-source-finder'),
                        'match_type' => __('Settings section', 'hp-source-finder'),
                        'source_type' => $this->get_registered_settings_source_type($page_slug, $page_data),
                        'is_clickable' => ! empty($page_data['url']) || $this->build_admin_url($page_slug) !== '',
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
                'path' => $this->build_registered_setting_path($page_data, '', $setting_name),
                'url' => isset($page_data['url']) ? $page_data['url'] : $this->build_admin_url($group),
                'source' => __('Registered Setting', 'hp-source-finder'),
                'match_type' => __('Setting name', 'hp-source-finder'),
                'source_type' => $this->get_registered_settings_source_type($group, $page_data),
                'is_clickable' => ! empty($page_data['url']) || $this->build_admin_url($group) !== '',
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

    private function build_admin_page_match_result($entry, $variants, &$debug_info = array()) {
        $callback_matches = $this->get_callback_file_matches(
            isset($entry['callback_files']) ? $entry['callback_files'] : array(),
            $variants,
            $entry,
            $debug_info
        );
        $visible_text_matches = $this->get_visible_admin_page_matches($entry, $variants, $debug_info);
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

        if (! $field_match && empty($callback_matches) && empty($visible_text_matches)) {
            return array();
        }

        $result = array(
            'title' => isset($entry['page_title']) && $entry['page_title'] !== '' ? $entry['page_title'] : (isset($entry['title']) ? $entry['title'] : ''),
            'menu_title' => isset($entry['menu_title']) ? $entry['menu_title'] : '',
            'page_title' => isset($entry['page_title']) ? $entry['page_title'] : '',
            'slug' => isset($entry['slug']) ? $entry['slug'] : '',
            'path' => isset($entry['path']) ? $entry['path'] : '',
            'url' => isset($entry['url']) ? $entry['url'] : '',
            'source' => __('Plugin Admin Page', 'hp-source-finder'),
            'match_type' => __('Admin page match', 'hp-source-finder'),
            'source_type' => __('Plugin', 'hp-source-finder'),
            'plugin_name' => isset($entry['plugin_name']) ? $entry['plugin_name'] : '',
            'plugin_slug' => isset($entry['plugin_slug']) ? $entry['plugin_slug'] : '',
            'visible_text_matches' => $visible_text_matches,
            'callback_matches' => $callback_matches,
            'callback_file' => ! empty($callback_matches[0]['file']) ? $callback_matches[0]['file'] : (isset($entry['callback_files'][0]) ? $this->get_plugin_relative_path($entry['callback_files'][0]) : ''),
            'is_clickable' => ! empty($entry['url']),
            'match_origin' => ! empty($visible_text_matches) ? 'visible_text' : (! empty($callback_matches) ? 'callback' : 'label'),
        );

        if (empty($result['page_title']) && ! empty($result['menu_title'])) {
            $result['page_title'] = $result['menu_title'];
        }

        return $result;
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

    private function get_callback_file_matches($files, $variants, $entry = array(), &$debug_info = array()) {
        $matches = array();
        $max_window_lines = 4;

        foreach ((array) $files as $file_path) {
            if (! is_readable($file_path)) {
                continue;
            }

            $file_info = new SplFileInfo($file_path);

            if ($this->should_skip_file($file_info)) {
                continue;
            }

            $file = new SplFileObject($file_path, 'r');
            $line_number = 0;
            $line_window = array();

            while (! $file->eof()) {
                $line = $file->fgets();
                $line_number++;
                $trimmed_line = trim((string) $line);

                $line_window[] = array(
                    'line_number' => $line_number,
                    'text' => $trimmed_line,
                );

                if (count($line_window) > $max_window_lines) {
                    array_shift($line_window);
                }

                if ($trimmed_line !== '' && $this->matches_visible_text_variants($trimmed_line, $variants)) {
                    $matches[] = array(
                        'file' => $this->get_plugin_relative_path($file_path),
                        'line_number' => $line_number,
                        'snippet' => $trimmed_line,
                    );
                } else {
                    $window_text = implode(
                        ' ',
                        array_filter(
                            wp_list_pluck($line_window, 'text'),
                            static function ($value) {
                                return $value !== '';
                            }
                        )
                    );

                    if ($window_text === '' || ! $this->matches_visible_text_variants($window_text, $variants)) {
                        continue;
                    }

                    $matches[] = array(
                        'file' => $this->get_plugin_relative_path($file_path),
                        'line_number' => isset($line_window[0]['line_number']) ? (int) $line_window[0]['line_number'] : $line_number,
                        'snippet' => $this->clean_visible_text_snippet($window_text),
                    );
                }

                if (count($matches) >= 3) {
                    break 2;
                }
            }
        }

        if (empty($matches) && ! empty($entry)) {
            $debug_info[] = sprintf(
                /* translators: 1: plugin name, 2: admin page title */
                __('Checked callback files for %1$s -> %2$s but found no fragment match.', 'hp-source-finder'),
                isset($entry['plugin_name']) ? $entry['plugin_name'] : __('Plugin page', 'hp-source-finder'),
                isset($entry['page_title']) ? $entry['page_title'] : (isset($entry['slug']) ? $entry['slug'] : __('Unknown page', 'hp-source-finder'))
            );
        }

        return $matches;
    }

    private function get_plugin_relative_path($file_path) {
        return str_replace('\\', '/', plugin_basename(wp_normalize_path((string) $file_path)));
    }

    private function get_visible_admin_page_index($entry) {
        $index = get_transient($this->get_admin_page_index_transient_key($entry));

        return is_array($index) ? $index : array();
    }

    private function get_visible_admin_page_matches($entry, $variants, &$debug_info = array()) {
        $index = $this->get_or_build_visible_admin_page_index($entry, $debug_info);

        if (empty($index['visible_text']) || ! is_array($index['visible_text'])) {
            return array();
        }

        $matches = array();

        foreach ($index['visible_text'] as $item) {
            $snippet = isset($item['text']) ? (string) $item['text'] : '';

            if (! $this->matches_visible_text_variants($snippet, $variants)) {
                continue;
            }

            $matches[] = array(
                'kind' => isset($item['kind']) ? (string) $item['kind'] : __('Visible text', 'hp-source-finder'),
                'snippet' => $snippet,
            );

            if (count($matches) >= self::MAX_VISIBLE_TEXT_MATCHES) {
                break;
            }
        }

        if (empty($matches)) {
            $debug_info[] = sprintf(
                /* translators: 1: plugin name, 2: admin page title */
                __('Rendered admin-page text was indexed for %1$s -> %2$s, but nothing matched this search phrase.', 'hp-source-finder'),
                isset($entry['plugin_name']) ? $entry['plugin_name'] : __('Plugin page', 'hp-source-finder'),
                isset($entry['page_title']) ? $entry['page_title'] : (isset($entry['slug']) ? $entry['slug'] : __('Unknown page', 'hp-source-finder'))
            );
        }

        return $matches;
    }

    private function get_or_build_visible_admin_page_index($entry, &$debug_info = array()) {
        $index = $this->get_visible_admin_page_index($entry);

        if (! empty($index['visible_text']) && is_array($index['visible_text'])) {
            $debug_info[] = sprintf(
                /* translators: 1: plugin name, 2: admin page title */
                __('Used cached rendered text index for %1$s -> %2$s.', 'hp-source-finder'),
                isset($entry['plugin_name']) ? $entry['plugin_name'] : __('Plugin page', 'hp-source-finder'),
                isset($entry['page_title']) ? $entry['page_title'] : (isset($entry['slug']) ? $entry['slug'] : __('Unknown page', 'hp-source-finder'))
            );
            return $index;
        }

        $debug_info[] = sprintf(
            /* translators: 1: plugin name, 2: admin page title */
            __('No cached rendered text index for %1$s -> %2$s, attempting live callback capture.', 'hp-source-finder'),
            isset($entry['plugin_name']) ? $entry['plugin_name'] : __('Plugin page', 'hp-source-finder'),
            isset($entry['page_title']) ? $entry['page_title'] : (isset($entry['slug']) ? $entry['slug'] : __('Unknown page', 'hp-source-finder'))
        );

        $built_index = $this->build_visible_admin_page_index_from_callbacks($entry, $debug_info);

        if (empty($built_index['visible_text']) || ! is_array($built_index['visible_text'])) {
            $debug_info[] = sprintf(
                /* translators: 1: plugin name, 2: admin page title */
                __('Live callback capture did not yield rendered text for %1$s -> %2$s.', 'hp-source-finder'),
                isset($entry['plugin_name']) ? $entry['plugin_name'] : __('Plugin page', 'hp-source-finder'),
                isset($entry['page_title']) ? $entry['page_title'] : (isset($entry['slug']) ? $entry['slug'] : __('Unknown page', 'hp-source-finder'))
            );
            return array();
        }

        set_transient(
            $this->get_admin_page_index_transient_key($entry),
            $built_index,
            self::ADMIN_PAGE_INDEX_TTL
        );

        return $built_index;
    }

    private function build_visible_admin_page_index_from_callbacks($entry, &$debug_info = array()) {
        $hookname = isset($entry['hookname']) ? (string) $entry['hookname'] : '';
        $callbacks = $this->get_hook_callbacks($hookname);
        $html = '';

        if (empty($callbacks)) {
            $debug_info[] = sprintf(
                /* translators: %s: admin page hook name */
                __('No callable admin-page hooks were found for %s.', 'hp-source-finder'),
                $hookname !== '' ? $hookname : __('this page', 'hp-source-finder')
            );
            return array();
        }

        $previous_plugin_page = isset($GLOBALS['plugin_page']) ? $GLOBALS['plugin_page'] : null;
        $previous_get_page = isset($_GET['page']) ? $_GET['page'] : null;
        $has_get_page = isset($_GET['page']);

        $GLOBALS['plugin_page'] = isset($entry['slug']) ? (string) $entry['slug'] : '';
        $_GET['page'] = isset($entry['slug']) ? (string) $entry['slug'] : '';

        if ($hookname !== '') {
            /**
             * Prime plugin admin pages that attach setup logic to the page load hook
             * before we capture callback output for search indexing.
             */
            do_action('load-' . $hookname);
            $debug_info[] = sprintf(
                /* translators: %s: admin page hook name */
                __('Triggered the load hook for %s before live callback capture.', 'hp-source-finder'),
                $hookname
            );
        }

        foreach ($callbacks as $callback) {
            if (! is_callable($callback)) {
                continue;
            }

            ob_start();

            try {
                call_user_func($callback);
            } catch (Throwable $throwable) {
                $debug_info[] = sprintf(
                    /* translators: %s: callback error message */
                    __('A callback could not be rendered during live capture: %s', 'hp-source-finder'),
                    $throwable->getMessage()
                );
                ob_end_clean();
                continue;
            }

            $html .= (string) ob_get_clean();
        }

        if ($previous_plugin_page === null) {
            unset($GLOBALS['plugin_page']);
        } else {
            $GLOBALS['plugin_page'] = $previous_plugin_page;
        }

        if ($has_get_page) {
            $_GET['page'] = $previous_get_page;
        } else {
            unset($_GET['page']);
        }

        if ($html === '') {
            $debug_info[] = sprintf(
                /* translators: 1: plugin name, 2: admin page title */
                __('Live callback capture produced no HTML output for %1$s -> %2$s.', 'hp-source-finder'),
                isset($entry['plugin_name']) ? $entry['plugin_name'] : __('Plugin page', 'hp-source-finder'),
                isset($entry['page_title']) ? $entry['page_title'] : (isset($entry['slug']) ? $entry['slug'] : __('Unknown page', 'hp-source-finder'))
            );
        }

        $visible_text = $this->extract_visible_admin_page_index($html);

        if (empty($visible_text)) {
            $debug_info[] = sprintf(
                /* translators: 1: plugin name, 2: admin page title */
                __('Rendered HTML was captured for %1$s -> %2$s, but no visible text nodes were extracted.', 'hp-source-finder'),
                isset($entry['plugin_name']) ? $entry['plugin_name'] : __('Plugin page', 'hp-source-finder'),
                isset($entry['page_title']) ? $entry['page_title'] : (isset($entry['slug']) ? $entry['slug'] : __('Unknown page', 'hp-source-finder'))
            );
            return array();
        }

        return array(
            'plugin_name' => isset($entry['plugin_name']) ? $entry['plugin_name'] : '',
            'page_title' => isset($entry['page_title']) ? $entry['page_title'] : '',
            'slug' => isset($entry['slug']) ? $entry['slug'] : '',
            'path' => isset($entry['path']) ? $entry['path'] : '',
            'url' => isset($entry['url']) ? $entry['url'] : '',
            'captured_at' => time(),
            'visible_text' => $visible_text,
        );
    }

    private function get_admin_page_index_transient_key($entry) {
        $slug = isset($entry['slug']) ? (string) $entry['slug'] : '';
        $plugin_slug = isset($entry['plugin_slug']) ? (string) $entry['plugin_slug'] : '';

        return 'hp_sf_admin_idx_' . md5($plugin_slug . '|' . $slug);
    }

    private function find_current_plugin_admin_page_entry($screen) {
        if (empty($screen) || ! is_object($screen)) {
            return array();
        }

        $current_slug = $this->get_current_admin_page_slug();
        $screen_id = isset($screen->id) ? (string) $screen->id : '';
        $screen_base = isset($screen->base) ? (string) $screen->base : '';

        if ($current_slug === '' && $screen_id === '' && $screen_base === '') {
            return array();
        }

        foreach ($this->get_plugin_admin_page_entries() as $entry) {
            $entry_slug = isset($entry['slug']) ? (string) $entry['slug'] : '';
            $entry_hookname = isset($entry['hookname']) ? (string) $entry['hookname'] : '';

            if ($entry_slug !== '' && $current_slug !== '' && $entry_slug === $current_slug) {
                return $entry;
            }

            if ($entry_hookname !== '' && ($entry_hookname === $screen_id || $entry_hookname === $screen_base)) {
                return $entry;
            }
        }

        return array();
    }

    private function get_current_admin_page_slug() {
        global $pagenow, $plugin_page;

        if (isset($plugin_page) && is_string($plugin_page) && $plugin_page !== '') {
            return sanitize_text_field(wp_unslash($plugin_page));
        }

        return isset($pagenow) ? sanitize_text_field((string) $pagenow) : '';
    }

    private function get_request_method() {
        if (! isset($_SERVER['REQUEST_METHOD'])) {
            return '';
        }

        return strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])));
    }

    private function extract_visible_admin_page_index($html) {
        $html = (string) $html;

        if ($html === '') {
            return array();
        }

        if (class_exists('DOMDocument')) {
            $nodes = $this->extract_visible_admin_page_index_with_dom($html);

            if (! empty($nodes)) {
                return $nodes;
            }
        }

        return $this->extract_visible_admin_page_index_with_regex($html);
    }

    private function extract_visible_admin_page_index_with_dom($html) {
        $document = new DOMDocument();
        $internal_errors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($internal_errors);

        if (! $loaded) {
            return array();
        }

        $xpath = new DOMXPath($document);
        $query = '//*['
            . 'not(self::script or self::style or self::noscript or self::svg or self::template)'
            . ' and ('
            . 'self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6'
            . ' or self::label or self::legend or self::th or self::td'
            . ' or self::button or self::a or self::option'
            . ' or self::li or self::p or self::span or self::div'
            . ' or @aria-label or @title or @placeholder or @value'
            . ' or contains(concat(" ", normalize-space(@class), " "), " description ")'
            . ' or contains(concat(" ", normalize-space(@class), " "), " notice ")'
            . ' or contains(concat(" ", normalize-space(@class), " "), " help ")'
            . ' or contains(concat(" ", normalize-space(@class), " "), " subtitle ")'
            . ' or contains(concat(" ", normalize-space(@class), " "), " label ")'
            . ' or contains(concat(" ", normalize-space(@class), " "), " title ")'
            . ' or contains(concat(" ", normalize-space(@class), " "), " heading ")'
            . ' or contains(concat(" ", normalize-space(@class), " "), " components-base-control__label ")'
            . ' or contains(concat(" ", normalize-space(@class), " "), " components-input-control__label ")'
            . ' or contains(concat(" ", normalize-space(@class), " "), " components-toggle-control__label ")'
            . ' or contains(concat(" ", normalize-space(@class), " "), " components-checkbox-control__label ")'
            . ')'
            . ']';
        $node_list = $xpath->query($query);

        if (! $node_list) {
            return array();
        }

        $items = array();
        $seen = array();

        foreach ($node_list as $node) {
            if (! $node instanceof DOMElement || $this->dom_node_is_hidden($node)) {
                continue;
            }

            foreach ($this->get_dom_node_visible_text_candidates($node) as $candidate_text) {
                $text = $this->clean_visible_text_snippet($candidate_text);

                if ($text === '') {
                    continue;
                }

                $this->push_visible_text_item($items, $seen, $text, $this->get_dom_node_match_kind($node));
            }

            if (count($items) >= 250) {
                break;
            }
        }

        return $items;
    }

    private function extract_visible_admin_page_index_with_regex($html) {
        $html = preg_replace('#<(script|style|noscript|svg)\b[^>]*>.*?</\1>#is', ' ', $html);
        $items = array();
        $seen = array();

        if (! preg_match_all('#<(h[1-6]|label|legend|th|td|p|div|span|button|a|li|option)[^>]*>(.*?)</\1>#is', (string) $html, $matches, PREG_SET_ORDER)) {
            return array();
        }

        foreach ($matches as $match) {
            $text = $this->clean_visible_text_snippet(isset($match[2]) ? $match[2] : '');

            if ($text === '') {
                continue;
            }

            $tag = isset($match[1]) ? strtolower((string) $match[1]) : '';
            $this->push_visible_text_item(
                $items,
                $seen,
                $text,
                in_array($tag, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'), true)
                    ? __('Heading', 'hp-source-finder')
                    : __('Visible text', 'hp-source-finder')
            );

            if (count($items) >= 120) {
                break;
            }
        }

        return $items;
    }

    private function dom_node_is_hidden($node) {
        $style = strtolower((string) $node->getAttribute('style'));
        $class_name = strtolower((string) $node->getAttribute('class'));
        $aria_hidden = strtolower((string) $node->getAttribute('aria-hidden'));

        if (strpos($style, 'display:none') !== false || strpos($style, 'visibility:hidden') !== false) {
            return true;
        }

        if (strpos($class_name, 'screen-reader-text') !== false || strpos($class_name, 'hidden') !== false) {
            return true;
        }

        return $aria_hidden === 'true';
    }

    private function get_dom_node_visible_text_candidates($node) {
        $candidates = array();
        $attribute_names = array('aria-label', 'title', 'placeholder', 'value');

        $text_content = trim((string) $node->textContent);

        if ($text_content !== '') {
            $candidates[] = $text_content;
        }

        foreach ($attribute_names as $attribute_name) {
            $attribute_value = trim((string) $node->getAttribute($attribute_name));

            if ($attribute_value !== '') {
                $candidates[] = $attribute_value;
            }
        }

        return array_values(array_unique($candidates));
    }

    private function push_visible_text_item(&$items, &$seen, $text, $kind) {
        $seen_key = $this->normalize_loose_search_value($text);

        if ($seen_key === '' || isset($seen[$seen_key])) {
            return;
        }

        $seen[$seen_key] = true;
        $items[] = array(
            'kind' => $kind,
            'text' => $text,
        );
    }

    private function get_dom_node_match_kind($node) {
        $tag = strtolower((string) $node->tagName);
        $class_name = strtolower((string) $node->getAttribute('class'));

        if (in_array($tag, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'), true)) {
            return __('Heading', 'hp-source-finder');
        }

        if (in_array($tag, array('label', 'legend', 'th'), true) || strpos($class_name, 'label') !== false) {
            return __('Label', 'hp-source-finder');
        }

        if (strpos($class_name, 'description') !== false || strpos($class_name, 'notice') !== false || strpos($class_name, 'help') !== false) {
            return __('Help text', 'hp-source-finder');
        }

        return __('Visible text', 'hp-source-finder');
    }

    private function clean_visible_text_snippet($text) {
        $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES, 'UTF-8');
        $text = strtr(
            $text,
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
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim((string) $text);

        if ($text === '' || strlen($text) < 2) {
            return '';
        }

        if (strlen($text) > 280) {
            $text = substr($text, 0, 277) . '...';
        }

        return $text;
    }

    private function matches_visible_text_variants($snippet, $variants) {
        $normalized_snippet = $this->normalize_search_value($snippet);
        $loose_snippet = $this->normalize_loose_search_value($snippet);

        foreach ($variants as $variant) {
            $variant = (string) $variant;

            if ($variant === '') {
                continue;
            }

            $normalized_variant = $this->normalize_search_value($variant);
            $loose_variant = $this->normalize_loose_search_value($variant);

            if ($normalized_variant !== '' && strpos($normalized_snippet, $normalized_variant) !== false) {
                return true;
            }

            if ($loose_variant !== '' && strpos($loose_snippet, $loose_variant) !== false) {
                return true;
            }

            if ($loose_variant !== '' && $this->contains_whole_phrase($loose_snippet, $loose_variant)) {
                return true;
            }

            if ($loose_variant !== '' && $this->contains_partial_phrase($loose_snippet, $loose_variant)) {
                return true;
            }

            if ($loose_variant !== '' && $this->contains_word_fragment_match($loose_snippet, $loose_variant)) {
                return true;
            }
        }

        return false;
    }

    private function unique_admin_page_results($results) {
        $unique = array();

        foreach ($results as $result) {
            $key = strtolower(
                (isset($result['slug']) ? $result['slug'] : '') . '|' .
                (isset($result['plugin_name']) ? $result['plugin_name'] : '') . '|' .
                (isset($result['callback_file']) ? $result['callback_file'] : '')
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
                    'title' => __('Discussion Settings', 'hp-source-finder'),
                    'menu_title' => __('Discussion', 'hp-source-finder'),
                    'page_title' => __('Discussion Settings', 'hp-source-finder'),
                    'slug' => 'options-discussion.php',
                    'path' => __('Settings > Discussion', 'hp-source-finder'),
                    'url' => admin_url('options-discussion.php'),
                    'source' => __('Likely WordPress Setting', 'hp-source-finder'),
                    'match_type' => __('Suggested location', 'hp-source-finder'),
                    'note' => __('Includes comment defaults and default post settings.', 'hp-source-finder'),
                    'source_type' => __('WordPress Setting', 'hp-source-finder'),
                    'is_clickable' => true,
                ),
            ),
            'reading' => array(
                array(
                    'title' => __('Reading Settings', 'hp-source-finder'),
                    'menu_title' => __('Reading', 'hp-source-finder'),
                    'page_title' => __('Reading Settings', 'hp-source-finder'),
                    'slug' => 'options-reading.php',
                    'path' => __('Settings > Reading', 'hp-source-finder'),
                    'url' => admin_url('options-reading.php'),
                    'source' => __('Likely WordPress Setting', 'hp-source-finder'),
                    'match_type' => __('Suggested location', 'hp-source-finder'),
                    'source_type' => __('WordPress Setting', 'hp-source-finder'),
                    'is_clickable' => true,
                ),
            ),
            'permalink|permalinks' => array(
                array(
                    'title' => __('Permalink Settings', 'hp-source-finder'),
                    'menu_title' => __('Permalinks', 'hp-source-finder'),
                    'page_title' => __('Permalink Settings', 'hp-source-finder'),
                    'slug' => 'options-permalink.php',
                    'path' => __('Settings > Permalinks', 'hp-source-finder'),
                    'url' => admin_url('options-permalink.php'),
                    'source' => __('Likely WordPress Setting', 'hp-source-finder'),
                    'match_type' => __('Suggested location', 'hp-source-finder'),
                    'source_type' => __('WordPress Setting', 'hp-source-finder'),
                    'is_clickable' => true,
                ),
            ),
            'breadcrumb|breadcrumbs' => array(
                array(
                    'title' => __('Breadcrumb Settings', 'hp-source-finder'),
                    'menu_title' => __('Breadcrumbs', 'hp-source-finder'),
                    'page_title' => __('Breadcrumb Settings', 'hp-source-finder'),
                    'slug' => 'breadcrumb',
                    'path' => __('Usually provided by an SEO or theme settings page', 'hp-source-finder'),
                    'url' => '',
                    'source' => __('Likely Plugin or Theme Setting', 'hp-source-finder'),
                    'match_type' => __('Suggested location', 'hp-source-finder'),
                    'note' => __('Check SEO, theme options, or customizer-related plugin settings.', 'hp-source-finder'),
                    'source_type' => __('Menu Page', 'hp-source-finder'),
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
        return in_array($scope, array('all', 'code', 'plugins', 'themes', 'templates'), true);
    }

    private function should_search_settings($scope) {
        return in_array($scope, array('all', 'wordpress', 'settings', 'menu-pages'), true);
    }

    private function should_search_admin_pages($scope) {
        return in_array($scope, array('all', 'plugins', 'settings', 'menu-pages'), true);
    }

    private function admin_page_entry_matches_scope($entry, $scope) {
        if ($scope === 'all' || $scope === 'plugins' || $scope === 'menu-pages') {
            return true;
        }

        if ($scope === 'settings') {
            $page_title = $this->normalize_search_value(isset($entry['page_title']) ? $entry['page_title'] : '');
            $path = $this->normalize_search_value(isset($entry['path']) ? $entry['path'] : '');

            return strpos($page_title, 'settings') !== false || strpos($path, 'settings') !== false;
        }

        return false;
    }

    private function filter_roots_by_scope($roots, $scope) {
        if (! in_array($scope, array('plugins', 'themes'), true)) {
            return $roots;
        }

        return array_values(
            array_filter(
                $roots,
                function ($root) use ($scope) {
                    return isset($root['category']) && $root['category'] === substr($scope, 0, -1);
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

                    if ($scope === 'wordpress') {
                        return $source_type === $this->normalize_search_value(__('WordPress Setting', 'hp-source-finder'))
                            || $this->is_core_settings_slug(isset($result['slug']) ? (string) $result['slug'] : '');
                    }

                    if ($scope === 'settings') {
                        return strpos($source, 'setting') !== false
                            || $source_type === $this->normalize_search_value(__('WordPress Setting', 'hp-source-finder'));
                    }

                    if ($scope === 'menu-pages') {
                        return $source_type === $this->normalize_search_value(__('Menu Page', 'hp-source-finder'));
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

    private function get_file_source_type($file_path, $root) {
        if ($this->is_template_file($file_path)) {
            return __('Template', 'hp-source-finder');
        }

        return isset($root['source_type']) ? $root['source_type'] : __('Plugin', 'hp-source-finder');
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
            return __('WordPress Setting', 'hp-source-finder');
        }

        if (strpos($source, 'menu') !== false) {
            return __('Menu Page', 'hp-source-finder');
        }

        if (strpos($path, 'appearance') !== false || strpos($path, 'theme') !== false) {
            return __('Theme', 'hp-source-finder');
        }

        return __('Menu Page', 'hp-source-finder');
    }

    private function get_registered_settings_source_type($slug, $page_data) {
        $slug = (string) $slug;
        $path = isset($page_data['path']) ? (string) $page_data['path'] : '';

        if ($this->is_core_settings_slug($slug) || in_array($slug, array('general', 'writing', 'reading', 'discussion', 'media', 'permalink', 'privacy'), true)) {
            return __('WordPress Setting', 'hp-source-finder');
        }

        if (strpos($this->normalize_search_value($path), 'appearance') !== false || strpos($this->normalize_search_value($path), 'theme') !== false) {
            return __('Theme', 'hp-source-finder');
        }

        return __('Plugin', 'hp-source-finder');
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

    private function build_admin_url($slug) {
        if ($slug === '') {
            return '';
        }

        if (strpos($slug, '.php') !== false) {
            return admin_url($slug);
        }

        return admin_url('admin.php?page=' . rawurlencode($slug));
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
            );
        }

        return $page_map;
    }

    private function build_settings_page_data($slug) {
        $slug = sanitize_text_field((string) $slug);

        return array(
            'menu_title' => $this->slug_to_label($slug),
            'page_title' => $this->slug_to_label($slug),
            'path' => $this->slug_to_label($slug),
            'url' => $this->build_admin_url($slug),
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
            'settings_results' => array(),
            'admin_page_results' => array(),
            'is_settings_search' => false,
            'is_text_finder_search' => false,
            'debug_info' => array(),
            'truncated' => false,
            'max_results' => self::MAX_RESULTS,
        );
    }
}
