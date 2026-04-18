<?php

if (! defined('ABSPATH')) {
    exit;
}

class WP_Source_Finder_SearchEngine {
    public function search($search_term, $filter) {
        $search_term = trim((string) $search_term);

        if ($search_term === '') {
            return array();
        }

        $allowed_extensions = $this->get_extensions_for_filter($filter);
        $results = array();

        foreach ($this->get_search_roots() as $root) {
            if (! is_dir($root['path']) || ! is_readable($root['path'])) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root['path'], FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file_info) {
                if (! $file_info instanceof SplFileInfo || ! $file_info->isFile() || ! $file_info->isReadable()) {
                    continue;
                }

                $extension = strtolower($file_info->getExtension());

                if (! in_array($extension, $allowed_extensions, true)) {
                    continue;
                }

                $file_results = $this->search_file($file_info->getPathname(), $search_term, $root);

                if (! empty($file_results)) {
                    $results = array_merge($results, $file_results);
                }
            }
        }

        return $results;
    }

    private function search_file($file_path, $search_term, $root) {
        $results = array();
        $file = new SplFileObject($file_path, 'r');
        $line_number = 0;

        while (! $file->eof()) {
            $line = $file->fgets();
            $line_number++;

            if (stripos($line, $search_term) === false) {
                continue;
            }

            $results[] = array(
                'file_path' => $this->get_relative_path($file_path, $root),
                'source_label' => $root['label'],
                'line_number' => $line_number,
                'snippet' => trim($line),
            );
        }

        return $results;
    }

    private function get_relative_path($file_path, $root) {
        $relative_path = ltrim(str_replace($root['path'], '', $file_path), '\\/');

        return $root['slug'] . '/' . str_replace('\\', '/', $relative_path);
    }

    private function get_search_roots() {
        $roots = array(
            array(
                'path' => untrailingslashit(WP_SOURCE_FINDER_PATH),
                'label' => __('WP Source Finder Plugin', 'wp-source-finder'),
                'slug' => 'wp-source-finder',
            ),
        );

        $stylesheet_directory = untrailingslashit((string) get_stylesheet_directory());
        $template_directory = untrailingslashit((string) get_template_directory());

        if ($stylesheet_directory !== '') {
            $roots[] = array(
                'path' => $stylesheet_directory,
                'label' => __('Active Theme', 'wp-source-finder'),
                'slug' => wp_basename($stylesheet_directory),
            );
        }

        if ($template_directory !== '' && $template_directory !== $stylesheet_directory) {
            $roots[] = array(
                'path' => $template_directory,
                'label' => __('Parent Theme', 'wp-source-finder'),
                'slug' => wp_basename($template_directory),
            );
        }

        foreach ($this->get_active_plugin_paths() as $plugin_path) {
            $plugin_directory = untrailingslashit(dirname($plugin_path));

            if ($plugin_directory === '' || ! is_dir($plugin_directory)) {
                continue;
            }

            $slug = wp_basename($plugin_directory);

            $roots[] = array(
                'path' => $plugin_directory,
                'label' => sprintf(
                    /* translators: %s: plugin directory name */
                    __('Active Plugin: %s', 'wp-source-finder'),
                    $slug
                ),
                'slug' => $slug,
            );
        }

        return $this->unique_roots($roots);
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

    private function get_extensions_for_filter($filter) {
        $filters = array(
            'all' => array('php', 'css', 'js', 'html', 'txt'),
            'php' => array('php'),
            'css' => array('css'),
            'js' => array('js'),
            'templates' => array('html', 'txt'),
        );

        if (! isset($filters[$filter])) {
            return $filters['all'];
        }

        return $filters[$filter];
    }
}
