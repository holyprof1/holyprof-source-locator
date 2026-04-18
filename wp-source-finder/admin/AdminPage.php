<?php

if (! defined('ABSPATH')) {
    exit;
}

class WP_Source_Finder_AdminPage {
    /**
     * @var WP_Source_Finder_SearchEngine
     */
    private $search_engine;

    public function __construct(WP_Source_Finder_SearchEngine $search_engine) {
        $this->search_engine = $search_engine;
    }

    public function register() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function add_menu_page() {
        add_menu_page(
            __('WP Source Finder', 'wp-source-finder'),
            __('WP Source Finder', 'wp-source-finder'),
            'manage_options',
            'wp-source-finder',
            array($this, 'render_page'),
            'dashicons-search',
            80
        );
    }

    public function enqueue_assets($hook_suffix) {
        if ($hook_suffix !== 'toplevel_page_wp-source-finder') {
            return;
        }

        wp_enqueue_style(
            'wp-source-finder-admin',
            WP_SOURCE_FINDER_URL . 'assets/admin.css',
            array(),
            WP_SOURCE_FINDER_VERSION
        );

        wp_enqueue_script(
            'wp-source-finder-admin',
            WP_SOURCE_FINDER_URL . 'assets/admin.js',
            array(),
            WP_SOURCE_FINDER_VERSION,
            true
        );
    }

    public function render_page() {
        $search_term = '';
        $filter = 'all';
        $results_message = '';
        $results = array();

        if (isset($_GET['wsf_search'])) {
            $search_term = sanitize_text_field(wp_unslash($_GET['wsf_search']));
        }

        if (isset($_GET['wsf_filter'])) {
            $filter = sanitize_key(wp_unslash($_GET['wsf_filter']));
        }

        $filters = $this->get_filters();

        if (! array_key_exists($filter, $filters)) {
            $filter = 'all';
        }

        if (isset($_GET['wsf_submit'])) {
            $results = $this->search_engine->search($search_term, $filter);

            if ($search_term === '') {
                $results_message = __('Enter a keyword to search.', 'wp-source-finder');
            } elseif (empty($results)) {
                $results_message = __('No results found', 'wp-source-finder');
            }
        }

        ?>
        <div class="wrap wp-source-finder-admin">
            <h1><?php esc_html_e('WP Source Finder', 'wp-source-finder'); ?></h1>
            <form method="get" class="wp-source-finder-form">
                <input type="hidden" name="page" value="wp-source-finder">

                <div class="wp-source-finder-toolbar">
                    <div class="wp-source-finder-field">
                        <label class="screen-reader-text" for="wsf-search">
                            <?php esc_html_e('Search term', 'wp-source-finder'); ?>
                        </label>
                        <input
                            id="wsf-search"
                            type="search"
                            name="wsf_search"
                            value="<?php echo esc_attr($search_term); ?>"
                            placeholder="<?php esc_attr_e('Search source files...', 'wp-source-finder'); ?>"
                        >
                    </div>

                    <div class="wp-source-finder-field wp-source-finder-field-select">
                        <label class="screen-reader-text" for="wsf-filter">
                            <?php esc_html_e('Filter by file type', 'wp-source-finder'); ?>
                        </label>
                        <select id="wsf-filter" name="wsf_filter">
                            <?php foreach ($filters as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($filter, $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="wp-source-finder-actions">
                        <button type="submit" name="wsf_submit" value="1" class="button button-primary">
                            <?php esc_html_e('Search', 'wp-source-finder'); ?>
                        </button>
                    </div>
                </div>
            </form>

            <div class="wp-source-finder-results">
                <h2><?php esc_html_e('Results', 'wp-source-finder'); ?></h2>

                <?php if (! empty($results)) : ?>
                    <?php $grouped_results = $this->group_results_by_file($results); ?>
                    <div class="wp-source-finder-results-list">
                        <?php foreach ($grouped_results as $file_path => $matches) : ?>
                            <?php $file_name = wp_basename($file_path); ?>
                            <?php $file_type = $this->get_file_type_label($file_path); ?>
                            <?php $source_label = isset($matches[0]['source_label']) ? $matches[0]['source_label'] : ''; ?>
                            <section class="wp-source-finder-result-group">
                                <div class="wp-source-finder-result-header">
                                    <div class="wp-source-finder-result-heading">
                                        <h3><?php echo esc_html($file_name); ?></h3>
                                        <?php if ($source_label) : ?>
                                            <p class="wp-source-finder-result-source"><?php echo esc_html($source_label); ?></p>
                                        <?php endif; ?>
                                        <p class="wp-source-finder-result-path"><?php echo esc_html($file_path); ?></p>
                                    </div>

                                    <div class="wp-source-finder-result-summary">
                                        <span class="wp-source-finder-file-badge">
                                            <?php echo esc_html($file_type); ?>
                                        </span>
                                        <span class="wp-source-finder-match-count">
                                            <?php
                                            printf(
                                                esc_html(_n('%d match', '%d matches', count($matches), 'wp-source-finder')),
                                                count($matches)
                                            );
                                            ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="wp-source-finder-match-rows">
                                    <?php foreach ($matches as $match) : ?>
                                        <div class="wp-source-finder-match-row">
                                            <div class="wp-source-finder-line-number">
                                                <?php
                                                printf(
                                                    esc_html__('Line %d', 'wp-source-finder'),
                                                    absint($match['line_number'])
                                                );
                                                ?>
                                            </div>
                                            <pre class="wp-source-finder-result-snippet"><?php echo wp_kses($this->highlight_match($match['snippet'], $search_term), array('mark' => array())); ?></pre>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($results_message) : ?>
                    <div class="wp-source-finder-notice">
                        <p><?php echo esc_html($results_message); ?></p>
                    </div>
                <?php else : ?>
                    <p class="description">
                        <?php esc_html_e('Enter a search term and choose a filter to begin.', 'wp-source-finder'); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function get_filters() {
        return array(
            'all' => __('All', 'wp-source-finder'),
            'php' => __('PHP', 'wp-source-finder'),
            'css' => __('CSS', 'wp-source-finder'),
            'js' => __('JS', 'wp-source-finder'),
            'templates' => __('Templates', 'wp-source-finder'),
        );
    }

    private function highlight_match($snippet, $search_term) {
        $escaped_snippet = esc_html($snippet);
        $escaped_search_term = esc_html($search_term);

        if ($escaped_search_term === '') {
            return $escaped_snippet;
        }

        return (string) preg_replace(
            '/' . preg_quote($escaped_search_term, '/') . '/i',
            '<mark>$0</mark>',
            $escaped_snippet
        );
    }

    private function group_results_by_file($results) {
        $grouped_results = array();

        foreach ($results as $result) {
            if (! isset($grouped_results[$result['file_path']])) {
                $grouped_results[$result['file_path']] = array();
            }

            $grouped_results[$result['file_path']][] = $result;
        }

        return $grouped_results;
    }

    private function get_file_type_label($file_path) {
        $extension = strtolower((string) pathinfo($file_path, PATHINFO_EXTENSION));

        $labels = array(
            'php' => __('PHP', 'wp-source-finder'),
            'css' => __('CSS', 'wp-source-finder'),
            'js' => __('JS', 'wp-source-finder'),
            'html' => __('Template', 'wp-source-finder'),
            'txt' => __('TXT', 'wp-source-finder'),
        );

        if (! isset($labels[$extension])) {
            return strtoupper($extension);
        }

        return $labels[$extension];
    }
}
