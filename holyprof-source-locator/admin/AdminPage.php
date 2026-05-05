<?php

if (! defined('ABSPATH')) {
    exit;
}

class Holyprof_Source_Locator_AdminPage {
    /**
     * @var Holyprof_Source_Locator_SearchEngine
     */
    private $search_engine;

    public function __construct(Holyprof_Source_Locator_SearchEngine $search_engine) {
        $this->search_engine = $search_engine;
    }

    public function register() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_holyprof_source_locator_search', array($this, 'handle_ajax_search'));
    }

    public function add_menu_page() {
        add_menu_page(
            __('Holyprof Source Locator', 'holyprof-source-locator'),
            __('Holyprof Source Locator', 'holyprof-source-locator'),
            'manage_options',
            'holyprof-source-locator',
            array($this, 'render_page'),
            'dashicons-search',
            80
        );
    }

    public function enqueue_assets($hook_suffix) {
        if ($hook_suffix !== 'toplevel_page_holyprof-source-locator') {
            return;
        }

        wp_enqueue_style(
            'holyprof-source-locator-admin',
            HOLYPROF_SOURCE_LOCATOR_URL . 'assets/admin.css',
            array(),
            HOLYPROF_SOURCE_LOCATOR_VERSION
        );

        wp_enqueue_script(
            'holyprof-source-locator-admin',
            HOLYPROF_SOURCE_LOCATOR_URL . 'assets/admin.js',
            array(),
            HOLYPROF_SOURCE_LOCATOR_VERSION,
            true
        );

        wp_localize_script(
            'holyprof-source-locator-admin',
            'holyprofSourceLocatorAdmin',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('holyprof_source_locator_search'),
                'action' => 'holyprof_source_locator_search',
                'messages' => array(
                    'loading' => __('Searching plugins, themes, hooks, and admin pages...', 'holyprof-source-locator'),
                    'error' => __('Something went wrong while searching. Please try again.', 'holyprof-source-locator'),
                ),
            )
        );
    }

    public function render_page() {
        $filters = $this->get_filters();
        $request_data = $this->get_render_request_data();
        $search_term = $request_data['search_term'];
        $filter = $request_data['filter'];
        $has_submitted = $request_data['has_submitted'];
        $has_valid_request = $request_data['has_valid_request'];

        if (! array_key_exists($filter, $filters)) {
            $filter = 'all';
        }

        $state = $this->build_search_state($search_term, $filter, $has_submitted && $has_valid_request);

        if ($has_submitted && ! $has_valid_request) {
            $state['results_message'] = __('Your search request has expired. Please try again.', 'holyprof-source-locator');
        }

        ?>
        <div class="wrap holyprof-source-locator-admin">
            <h1><?php esc_html_e('Holyprof Source Locator', 'holyprof-source-locator'); ?></h1>
            <p class="holyprof-source-locator-intro">
                <?php esc_html_e('Search for a feature, settings page, hook, template, CSS selector, JavaScript keyword, filename, or functions.php and trace where it lives in WordPress.', 'holyprof-source-locator'); ?>
            </p>
            <form method="get" class="holyprof-source-locator-form" id="holyprof-source-locator-form">
                <input type="hidden" name="page" value="holyprof-source-locator">
                <?php wp_nonce_field('holyprof_source_locator_search', 'holyprof_source_locator_nonce'); ?>

                <div class="holyprof-source-locator-toolbar">
                    <div class="holyprof-source-locator-field">
                        <label class="screen-reader-text" for="wsf-search">
                            <?php esc_html_e('Search term', 'holyprof-source-locator'); ?>
                        </label>
                        <input
                            id="wsf-search"
                            type="search"
                            name="wsf_search"
                            value="<?php echo esc_attr($search_term); ?>"
                            placeholder="<?php esc_attr_e('Search features, hooks, templates, functions.php, CSS, JS, or settings pages...', 'holyprof-source-locator'); ?>"
                            autofocus
                        >
                    </div>

                    <div class="holyprof-source-locator-field holyprof-source-locator-field-select">
                        <label class="screen-reader-text" for="wsf-filter">
                            <?php esc_html_e('Filter results', 'holyprof-source-locator'); ?>
                        </label>
                        <select id="wsf-filter" name="wsf_filter">
                            <?php foreach ($filters as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($filter, $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="holyprof-source-locator-actions">
                        <button type="submit" name="wsf_submit" value="1" class="button button-primary">
                            <?php esc_html_e('Search', 'holyprof-source-locator'); ?>
                        </button>
                    </div>
                </div>
            </form>
            <div class="holyprof-source-locator-search-examples">
                <strong><?php esc_html_e('Try searches like:', 'holyprof-source-locator'); ?></strong>
                <span><?php esc_html_e('sitemap', 'holyprof-source-locator'); ?></span>
                <span><?php esc_html_e('breadcrumb', 'holyprof-source-locator'); ?></span>
                <span><?php esc_html_e('wp-mail-smtp', 'holyprof-source-locator'); ?></span>
                <span><?php esc_html_e('add_action', 'holyprof-source-locator'); ?></span>
                <span><?php esc_html_e('functions.php', 'holyprof-source-locator'); ?></span>
            </div>

            <div id="holyprof-source-locator-results" aria-live="polite">
                <?php echo $this->render_results_html($state); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </div>
        <?php
    }

    public function handle_ajax_search() {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(
                array(
                    'message' => __('You do not have permission to run this search.', 'holyprof-source-locator'),
                ),
                403
            );
        }

        check_ajax_referer('holyprof_source_locator_search', 'nonce');

        $request_data = $this->get_ajax_request_data();
        $search_term = $request_data['search_term'];
        $filter = $request_data['filter'];
        $filters = $this->get_filters();

        if (! array_key_exists($filter, $filters)) {
            $filter = 'all';
        }

        $state = $this->build_search_state($search_term, $filter, true);

        wp_send_json_success(
            array(
                'html' => $this->render_results_html($state),
            )
        );
    }

    private function get_filters() {
        return array(
            'all' => __('All', 'holyprof-source-locator'),
            'best-matches' => __('Best Matches', 'holyprof-source-locator'),
            'settings-admin' => __('Settings/Admin Pages', 'holyprof-source-locator'),
            'plugin' => __('Plugins', 'holyprof-source-locator'),
            'theme' => __('Themes', 'holyprof-source-locator'),
            'php' => __('PHP', 'holyprof-source-locator'),
            'css' => __('CSS', 'holyprof-source-locator'),
            'js' => __('JS', 'holyprof-source-locator'),
            'templates' => __('Templates', 'holyprof-source-locator'),
            'hooks' => __('Hooks', 'holyprof-source-locator'),
        );
    }

    private function sanitize_search_term($value) {
        return sanitize_text_field((string) $value);
    }

    private function sanitize_filter($value) {
        return sanitize_key((string) $value);
    }

    private function is_valid_search_request() {
        $request = $this->get_request_params('GET');
        $nonce = isset($request['holyprof_source_locator_nonce']) ? $request['holyprof_source_locator_nonce'] : '';

        if ($nonce === '') {
            return false;
        }

        return (bool) wp_verify_nonce($nonce, 'holyprof_source_locator_search');
    }

    private function get_render_request_data() {
        $request = $this->get_request_params('GET');
        $has_submitted = isset($request['wsf_submit']) && '1' === $request['wsf_submit'];
        $has_valid_request = ! $has_submitted || $this->is_valid_search_request();

        return array(
            'search_term' => $has_valid_request && isset($request['wsf_search']) ? $this->sanitize_search_term($request['wsf_search']) : '',
            'filter' => $has_valid_request && isset($request['wsf_filter']) ? $this->sanitize_filter($request['wsf_filter']) : 'all',
            'has_submitted' => $has_submitted,
            'has_valid_request' => $has_valid_request,
        );
    }

    private function get_ajax_request_data() {
        $request = $this->get_request_params('POST');

        return array(
            'search_term' => isset($request['wsf_search']) ? $this->sanitize_search_term($request['wsf_search']) : '',
            'filter' => isset($request['wsf_filter']) ? $this->sanitize_filter($request['wsf_filter']) : '',
        );
    }

    private function get_request_params($method) {
        $source = 'POST' === strtoupper((string) $method) ? INPUT_POST : INPUT_GET;
        $params = filter_input_array($source, FILTER_UNSAFE_RAW);

        if (! is_array($params)) {
            return array();
        }

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                unset($params[$key]);
                continue;
            }

            $params[$key] = sanitize_text_field((string) $value);
        }

        return $params;
    }

    private function build_search_state($search_term, $filter, $has_submitted) {
        $state = array(
            'search_term' => $search_term,
            'filter' => $filter,
            'results' => array(),
            'file_results' => array(),
            'feature_results' => array(),
            'settings_results' => array(),
            'admin_page_results' => array(),
            'is_feature_search' => false,
            'is_settings_search' => false,
            'is_code_search' => false,
            'is_text_finder_search' => false,
            'results_message' => '',
            'truncated_message' => '',
        );

        if (! $has_submitted) {
            return $state;
        }

        $search_response = $this->search_engine->search($search_term, $filter);
        $state['filter'] = $filter;
        $state['results'] = isset($search_response['results']) ? $search_response['results'] : array();
        $state['file_results'] = isset($search_response['file_results']) ? $search_response['file_results'] : $state['results'];
        $state['feature_results'] = isset($search_response['feature_results']) ? $search_response['feature_results'] : array();
        $state['settings_results'] = isset($search_response['settings_results']) ? $search_response['settings_results'] : array();
        $state['admin_page_results'] = isset($search_response['admin_page_results']) ? $search_response['admin_page_results'] : array();
        $state['is_feature_search'] = ! empty($search_response['is_feature_search']);
        $state['is_settings_search'] = ! empty($search_response['is_settings_search']);
        $state['is_code_search'] = ! empty($search_response['is_code_search']);
        $state['is_text_finder_search'] = ! empty($search_response['is_text_finder_search']);

        if ($search_term === '') {
            $state['results_message'] = __('Enter a keyword to search.', 'holyprof-source-locator');
        } elseif ($filter === 'best-matches' && empty($state['feature_results'])) {
            $state['results_message'] = __('No best matches found', 'holyprof-source-locator');
        } elseif (empty($state['file_results']) && empty($state['feature_results']) && empty($state['admin_page_results'])) {
            $state['results_message'] = __('No results found', 'holyprof-source-locator');
        }

        if (! empty($search_response['truncated'])) {
            $state['truncated_message'] = sprintf(
                /* translators: %d: maximum number of results shown before the search is truncated. */
                __('Showing the first %d matches. Try a more specific keyword or switch to a narrower filter such as PHP, Hooks, or Settings/Admin Pages.', 'holyprof-source-locator'),
                absint($search_response['max_results'])
            );
        }

        return $state;
    }

    private function render_results_html($state) {
        $search_term = isset($state['search_term']) ? (string) $state['search_term'] : '';
        $filter = isset($state['filter']) ? (string) $state['filter'] : 'all';
        $file_results = isset($state['file_results']) ? $state['file_results'] : array();
        $feature_results = isset($state['feature_results']) ? $state['feature_results'] : array();
        $settings_results = isset($state['settings_results']) ? $state['settings_results'] : array();
        $admin_page_results = isset($state['admin_page_results']) ? $state['admin_page_results'] : array();
        $settings_admin_results = $this->merge_settings_admin_results($settings_results, $admin_page_results);
        $is_feature_search = ! empty($state['is_feature_search']);
        $is_code_search = ! empty($state['is_code_search']);
        $results_message = isset($state['results_message']) ? $state['results_message'] : '';
        $truncated_message = isset($state['truncated_message']) ? $state['truncated_message'] : '';
        $sections = $this->get_visible_sections($filter, ! empty($feature_results), ! empty($settings_admin_results), ! empty($file_results), $is_code_search, $is_feature_search);
        $has_visible_results = false;
        $total_results = 0;

        foreach ($sections as $section_type) {
            if ($section_type === 'best-matches' && ! empty($feature_results)) {
                $has_visible_results = true;
                $total_results += count($feature_results);
            }

            if ($section_type === 'settings-admin' && ! empty($settings_admin_results)) {
                $has_visible_results = true;
                $total_results += count($settings_admin_results);
            }

            if ($section_type === 'files' && ! empty($file_results)) {
                $has_visible_results = true;
                $total_results += count($file_results);
            }
        }

        ob_start();
        ?>
        <div class="holyprof-source-locator-results">
            <h2><?php esc_html_e('Results', 'holyprof-source-locator'); ?></h2>

            <?php if ($total_results > 0) : ?>
                <p class="holyprof-source-locator-results-count">
                    <?php
                    printf(
                        /* translators: %d: total number of search results found. */
                        esc_html(_n('%d result found', '%d results found', $total_results, 'holyprof-source-locator')),
                        absint($total_results)
                    );
                    ?>
                </p>
            <?php endif; ?>

            <?php if ($truncated_message) : ?>
                <div class="holyprof-source-locator-notice holyprof-source-locator-notice-warning">
                    <p><?php echo esc_html($truncated_message); ?></p>
                </div>
            <?php endif; ?>

            <?php foreach ($sections as $section_type) : ?>
                <?php if ($section_type === 'best-matches' && ! empty($feature_results)) : ?>
                    <?php echo $this->render_location_cards_section(__('Best Matches', 'holyprof-source-locator'), $feature_results, $search_term, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php endif; ?>

                <?php if ($section_type === 'settings-admin' && ! empty($settings_admin_results)) : ?>
                    <?php echo $this->render_location_cards_section(__('Settings/Admin Pages', 'holyprof-source-locator'), $settings_admin_results, $search_term, false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php endif; ?>

                <?php if ($section_type === 'files' && ! empty($file_results)) : ?>
                    <?php $grouped_results = $this->group_results_by_file($file_results); ?>
                    <div class="holyprof-source-locator-section">
                        <h3 class="holyprof-source-locator-section-title">
                            <?php esc_html_e('Source File Matches', 'holyprof-source-locator'); ?>
                        </h3>
                        <div class="holyprof-source-locator-results-list">
                            <?php foreach ($grouped_results as $file_path => $matches) : ?>
                                <?php $file_name = wp_basename($file_path); ?>
                                <?php $file_type = $this->get_file_type_label($file_path); ?>
                                <?php $source_label = isset($matches[0]['source_label']) ? $matches[0]['source_label'] : ''; ?>
                                <?php $source_type = isset($matches[0]['source_type']) ? $matches[0]['source_type'] : ''; ?>
                                <section class="holyprof-source-locator-result-group">
                                    <div class="holyprof-source-locator-result-header">
                                        <div class="holyprof-source-locator-result-heading">
                                            <h3><?php echo esc_html($file_name); ?></h3>
                                            <?php if ($source_label) : ?>
                                                <p class="holyprof-source-locator-result-source"><?php echo esc_html($source_label); ?></p>
                                            <?php endif; ?>
                                            <p class="holyprof-source-locator-result-path"><?php echo esc_html($file_path); ?></p>
                                        </div>

                                        <div class="holyprof-source-locator-result-summary">
                                            <?php if ($source_type) : ?>
                                                <span class="holyprof-source-locator-file-badge holyprof-source-locator-file-badge-source">
                                                    <?php echo esc_html($source_type); ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="holyprof-source-locator-file-badge">
                                                <?php echo esc_html($file_type); ?>
                                            </span>
                                            <span class="holyprof-source-locator-match-count">
                                                <?php
                                                printf(
                                                    /* translators: %d: number of matches found in this file. */
                                                    esc_html(_n('%d match', '%d matches', count($matches), 'holyprof-source-locator')),
                                                    absint(count($matches))
                                                );
                                                ?>
                                            </span>
                                            <button type="button" class="button button-secondary holyprof-source-locator-copy-button holyprof-source-locator-copy-path" data-copy-text="<?php echo esc_attr($file_path); ?>">
                                                <?php esc_html_e('Copy path', 'holyprof-source-locator'); ?>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="holyprof-source-locator-match-rows">
                                        <?php foreach ($matches as $match) : ?>
                                            <div class="holyprof-source-locator-match-row">
                                                <div class="holyprof-source-locator-line-number">
                                                    <?php if (! empty($match['line_number'])) : ?>
                                                        <?php
                                                        printf(
                                                            /* translators: %d: line number containing the file match. */
                                                            esc_html__('Line %d', 'holyprof-source-locator'),
                                                            absint($match['line_number'])
                                                        );
                                                        ?>
                                                    <?php else : ?>
                                                        <?php esc_html_e('Path match', 'holyprof-source-locator'); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <div class="holyprof-source-locator-match-row-actions">
                                                        <?php if (! empty($match['matched_keyword'])) : ?>
                                                            <span class="holyprof-source-locator-file-badge holyprof-source-locator-file-badge-match-type">
                                                                <?php
                                                                printf(
                                                                    /* translators: %s: matched keyword */
                                                                    esc_html__('Matched keyword: %s', 'holyprof-source-locator'),
                                                                    esc_html($match['matched_keyword'])
                                                                );
                                                                ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if (! empty($match['result_type'])) : ?>
                                                            <span class="holyprof-source-locator-file-badge holyprof-source-locator-file-badge-match-type"><?php echo esc_html($match['result_type']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (! empty($match['hook_type'])) : ?>
                                                            <span class="holyprof-source-locator-file-badge holyprof-source-locator-file-badge-menu"><?php echo esc_html($match['hook_type']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (! empty($match['hook_name'])) : ?>
                                                            <span class="holyprof-source-locator-file-badge holyprof-source-locator-file-badge-source"><?php echo esc_html($match['hook_name']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="holyprof-source-locator-match-row-actions">
                                                        <?php if (! empty($match['line_number'])) : ?>
                                                            <button type="button" class="button button-small holyprof-source-locator-copy-button" data-copy-text="<?php echo esc_attr((string) $match['line_number']); ?>">
                                                                <?php esc_html_e('Copy line', 'holyprof-source-locator'); ?>
                                                            </button>
                                                            <button type="button" class="button button-small holyprof-source-locator-copy-button" data-copy-text="<?php echo esc_attr($file_path . ':' . absint($match['line_number'])); ?>">
                                                                <?php esc_html_e('Copy path:line', 'holyprof-source-locator'); ?>
                                                            </button>
                                                        <?php endif; ?>
                                                        <button type="button" class="button button-small holyprof-source-locator-copy-button" data-copy-text="<?php echo esc_attr(isset($match['snippet']) ? (string) $match['snippet'] : ''); ?>">
                                                            <?php esc_html_e('Copy snippet', 'holyprof-source-locator'); ?>
                                                        </button>
                                                    </div>
                                                    <pre class="holyprof-source-locator-result-snippet"><?php echo wp_kses($this->highlight_match($match['snippet'], $search_term), array('mark' => array())); ?></pre>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if (! $has_visible_results && $results_message) : ?>
                <div class="holyprof-source-locator-notice">
                    <p><?php echo esc_html($results_message); ?></p>
                </div>
                <div class="holyprof-source-locator-helper-tips">
                    <h3 class="holyprof-source-locator-section-title"><?php esc_html_e('No results? Try...', 'holyprof-source-locator'); ?></h3>
                    <ul>
                        <li><?php esc_html_e('switching the filter to Best Matches, Settings/Admin Pages, Plugins, Themes, PHP, JS, Templates, or Hooks', 'holyprof-source-locator'); ?></li>
                        <li><?php esc_html_e('searching for a function name, hook name, class name, CSS selector, or JS keyword', 'holyprof-source-locator'); ?></li>
                        <li><?php esc_html_e('searching for a feature word such as sitemap, breadcrumb, smtp, cache, analytics, or checkout', 'holyprof-source-locator'); ?></li>
                        <li><?php esc_html_e('searching for a shorter text fragment from the source you want to trace', 'holyprof-source-locator'); ?></li>
                        <li><?php esc_html_e('trying simpler spacing or punctuation if the first search is too exact', 'holyprof-source-locator'); ?></li>
                    </ul>
                </div>
            <?php elseif (! empty($feature_results) && empty($file_results)) : ?>
                <div class="holyprof-source-locator-notice">
                    <p><?php esc_html_e('No file matches found for this search.', 'holyprof-source-locator'); ?></p>
                </div>
            <?php elseif (! $has_visible_results) : ?>
                <p class="description">
                    <?php esc_html_e('Enter a search term and choose a filter to begin.', 'holyprof-source-locator'); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private function get_visible_sections($filter, $has_best_matches, $has_settings_admin, $has_file_matches, $is_code_search, $is_feature_search) {
        if ($filter === 'best-matches') {
            return array('best-matches');
        }

        if ($filter === 'settings-admin') {
            return array('settings-admin');
        }

        if (in_array($filter, array('php', 'css', 'js', 'templates', 'hooks'), true)) {
            return array('files');
        }

        if ($is_code_search && ! $is_feature_search) {
            return array('files', 'best-matches', 'settings-admin');
        }

        return array('best-matches', 'settings-admin', 'files');
    }

    private function merge_settings_admin_results($settings_results, $admin_page_results) {
        $merged = array();

        foreach (array_merge((array) $admin_page_results, (array) $settings_results) as $result) {
            $key = strtolower(
                implode(
                    '|',
                    array(
                        isset($result['owner_slug']) ? (string) $result['owner_slug'] : '',
                        isset($result['slug']) ? (string) $result['slug'] : '',
                        isset($result['path']) ? (string) $result['path'] : '',
                        isset($result['page_title']) ? (string) $result['page_title'] : '',
                        isset($result['match_type']) ? (string) $result['match_type'] : '',
                    )
                )
            );

            if (! isset($merged[$key])) {
                $merged[$key] = $result;
                continue;
            }

            if (empty($merged[$key]['url']) && ! empty($result['url'])) {
                $merged[$key] = $result;
            }
        }

        $merged = array_values($merged);

        usort(
            $merged,
            function ($left, $right) {
                $left_score = $this->get_settings_admin_result_sort_score($left);
                $right_score = $this->get_settings_admin_result_sort_score($right);

                if ($left_score === $right_score) {
                    return strcasecmp(
                        isset($left['page_title']) ? (string) $left['page_title'] : '',
                        isset($right['page_title']) ? (string) $right['page_title'] : ''
                    );
                }

                return $right_score <=> $left_score;
            }
        );

        return $merged;
    }

    private function get_settings_admin_result_sort_score($result) {
        $score = 0;
        $owner = isset($result['source_owner']) ? strtolower((string) $result['source_owner']) : '';

        if (! empty($result['url']) && $this->is_valid_admin_result_url((string) $result['url'])) {
            $score += 120;
        }

        if ($owner !== '' && $owner !== strtolower(__('Unknown source', 'holyprof-source-locator'))) {
            $score += 70;
        }

        if (! empty($result['plugin_name'])) {
            $score += 60;
        }

        if (! empty($result['page_title'])) {
            $score += 30;
        }

        if (! empty($result['reason'])) {
            $score += 20;
        }

        return $score;
    }

    private function render_location_cards_section($title, $results, $search_term, $show_related_evidence) {
        ob_start();
        ?>
        <div class="holyprof-source-locator-section">
            <h3 class="holyprof-source-locator-section-title"><?php echo esc_html($title); ?></h3>
            <div class="holyprof-source-locator-settings-list">
                <?php foreach ((array) $results as $index => $result) : ?>
                    <section class="holyprof-source-locator-setting-item<?php echo ($show_related_evidence && $index === 0) ? ' is-best-match' : ''; ?>">
                        <div class="holyprof-source-locator-setting-header">
                            <div class="holyprof-source-locator-result-heading">
                                <h3><?php echo esc_html($this->get_location_result_title($result)); ?></h3>
                                <?php if ($this->get_location_result_owner($result) !== '') : ?>
                                    <p class="holyprof-source-locator-result-source"><?php echo esc_html($this->get_location_result_owner($result)); ?></p>
                                <?php endif; ?>
                                <?php if ($this->get_location_result_path($result) !== '') : ?>
                                    <p class="holyprof-source-locator-result-path"><?php echo esc_html($this->get_location_result_path($result)); ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="holyprof-source-locator-result-summary">
                                <?php if ($show_related_evidence && $index === 0) : ?>
                                    <span class="holyprof-source-locator-file-badge holyprof-source-locator-file-badge-best">
                                        <?php esc_html_e('Best match', 'holyprof-source-locator'); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (! empty($result['source_type'])) : ?>
                                    <span class="holyprof-source-locator-file-badge holyprof-source-locator-file-badge-source"><?php echo esc_html($result['source_type']); ?></span>
                                <?php endif; ?>
                                <span class="holyprof-source-locator-file-badge <?php echo esc_attr($this->get_location_result_badge_class($result)); ?>"><?php echo esc_html($this->get_location_result_match_badge($result)); ?></span>
                            </div>
                        </div>

                        <div class="holyprof-source-locator-setting-meta">
                            <p><strong><?php esc_html_e('Match Type:', 'holyprof-source-locator'); ?></strong> <?php echo esc_html($this->get_location_result_match_badge($result)); ?></p>
                            <?php if (! empty($result['page_title'])) : ?>
                                <p><strong><?php esc_html_e('Page Title:', 'holyprof-source-locator'); ?></strong> <?php echo esc_html($result['page_title']); ?></p>
                            <?php endif; ?>
                            <?php if ($this->get_location_result_owner($result) !== '') : ?>
                                <p><strong><?php esc_html_e('Source Name:', 'holyprof-source-locator'); ?></strong> <?php echo esc_html($this->get_location_result_owner($result)); ?></p>
                            <?php endif; ?>
                            <?php if (! empty($result['source_type'])) : ?>
                                <p><strong><?php esc_html_e('Source Type:', 'holyprof-source-locator'); ?></strong> <?php echo esc_html($result['source_type']); ?></p>
                            <?php endif; ?>
                            <?php if (! empty($result['menu_title'])) : ?>
                                <p><strong><?php esc_html_e('Menu Label:', 'holyprof-source-locator'); ?></strong> <?php echo esc_html($result['menu_title']); ?></p>
                            <?php endif; ?>
                            <?php if ($this->get_location_result_path($result) !== '') : ?>
                                <p><strong><?php echo esc_html($this->get_location_result_path_label($result)); ?></strong> <code><?php echo esc_html($this->get_location_result_path($result)); ?></code></p>
                            <?php endif; ?>
                            <?php if (! empty($result['slug'])) : ?>
                                <p><strong><?php esc_html_e('Page Slug:', 'holyprof-source-locator'); ?></strong> <code><?php echo esc_html($result['slug']); ?></code></p>
                            <?php endif; ?>
                            <?php if (! empty($result['url'])) : ?>
                                <p><strong><?php esc_html_e('Admin URL:', 'holyprof-source-locator'); ?></strong> <code><?php echo esc_html($result['url']); ?></code></p>
                            <?php endif; ?>
                            <?php if (! empty($result['reason'])) : ?>
                                <p class="holyprof-source-locator-match-reason"><strong><?php esc_html_e('Why this matched:', 'holyprof-source-locator'); ?></strong> <?php echo esc_html($result['reason']); ?></p>
                            <?php endif; ?>
                            <?php if (! empty($result['note'])) : ?>
                                <p class="holyprof-source-locator-setting-note"><?php echo esc_html($result['note']); ?></p>
                            <?php endif; ?>
                            <?php echo $this->render_location_actions($result); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php if ($show_related_evidence && ! empty($result['related_evidence'])) : ?>
                                <?php echo $this->render_related_evidence($result, $search_term); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private function render_location_actions($result) {
        $has_openable_url = ! empty($result['url'])
            && ! empty($result['is_clickable'])
            && $this->is_valid_admin_result_url((string) $result['url']);
        ob_start();
        ?>
        <div class="holyprof-source-locator-result-actions">
            <?php if ($this->can_current_user_open_admin_result($result)) : ?>
                <a class="button button-secondary" href="<?php echo esc_url($result['url']); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Open settings page', 'holyprof-source-locator'); ?>
                </a>
            <?php endif; ?>
            <?php if ($has_openable_url) : ?>
                <button type="button" class="button button-secondary holyprof-source-locator-copy-button" data-copy-text="<?php echo esc_attr($result['url']); ?>">
                    <?php esc_html_e('Copy admin URL', 'holyprof-source-locator'); ?>
                </button>
            <?php endif; ?>
            <?php if (! empty($result['slug'])) : ?>
                <button type="button" class="button button-secondary holyprof-source-locator-copy-button" data-copy-text="<?php echo esc_attr($result['slug']); ?>">
                    <?php esc_html_e('Copy page slug', 'holyprof-source-locator'); ?>
                </button>
            <?php endif; ?>
            <?php if (! empty($result['supporting_path']) && empty($result['url'])) : ?>
                <button type="button" class="button button-secondary holyprof-source-locator-copy-button" data-copy-text="<?php echo esc_attr($result['supporting_path']); ?>">
                    <?php esc_html_e('Copy path', 'holyprof-source-locator'); ?>
                </button>
            <?php endif; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private function render_related_evidence($result, $search_term) {
        $items = isset($result['related_evidence']) ? (array) $result['related_evidence'] : array();

        if (empty($items)) {
            return '';
        }

        ob_start();
        ?>
        <div class="holyprof-source-locator-related-evidence">
            <p><strong><?php esc_html_e('Related source evidence:', 'holyprof-source-locator'); ?></strong></p>
            <?php foreach ($items as $item) : ?>
                <div class="holyprof-source-locator-related-evidence-item">
                    <code class="holyprof-source-locator-related-evidence-path"><?php echo esc_html(isset($item['file_path']) ? $item['file_path'] : ''); ?></code>
                    <div class="holyprof-source-locator-match-row-actions">
                        <button type="button" class="button button-small holyprof-source-locator-copy-button" data-copy-text="<?php echo esc_attr(isset($item['file_path']) ? (string) $item['file_path'] : ''); ?>">
                            <?php esc_html_e('Copy path', 'holyprof-source-locator'); ?>
                        </button>
                        <?php if (! empty($item['line_number'])) : ?>
                            <button type="button" class="button button-small holyprof-source-locator-copy-button" data-copy-text="<?php echo esc_attr((string) $item['line_number']); ?>">
                                <?php esc_html_e('Copy line', 'holyprof-source-locator'); ?>
                            </button>
                            <button type="button" class="button button-small holyprof-source-locator-copy-button" data-copy-text="<?php echo esc_attr(((string) $item['file_path']) . ':' . absint($item['line_number'])); ?>">
                                <?php esc_html_e('Copy path:line', 'holyprof-source-locator'); ?>
                            </button>
                        <?php endif; ?>
                        <?php if (! empty($item['snippet'])) : ?>
                            <button type="button" class="button button-small holyprof-source-locator-copy-button" data-copy-text="<?php echo esc_attr((string) $item['snippet']); ?>">
                                <?php esc_html_e('Copy snippet', 'holyprof-source-locator'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php if (! empty($item['snippet'])) : ?>
                        <pre class="holyprof-source-locator-result-snippet"><?php echo wp_kses($this->highlight_match((string) $item['snippet'], $search_term), array('mark' => array())); ?></pre>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private function get_location_result_title($result) {
        if (! empty($result['title'])) {
            return (string) $result['title'];
        }

        if (! empty($result['page_title'])) {
            return (string) $result['page_title'];
        }

        if (! empty($result['menu_title'])) {
            return (string) $result['menu_title'];
        }

        return '';
    }

    private function get_location_result_owner($result) {
        if (! empty($result['source_owner'])) {
            return (string) $result['source_owner'];
        }

        if (! empty($result['plugin_name'])) {
            return sprintf(__('Active Plugin: %s', 'holyprof-source-locator'), (string) $result['plugin_name']);
        }

        return '';
    }

    private function get_location_result_path($result) {
        if (! empty($result['path'])) {
            return (string) $result['path'];
        }

        if (! empty($result['supporting_path'])) {
            return (string) $result['supporting_path'];
        }

        return '';
    }

    private function get_location_result_path_label($result) {
        if (isset($result['location_kind']) && $result['location_kind'] === 'file-source') {
            return __('Source Path:', 'holyprof-source-locator');
        }

        return __('Menu Path:', 'holyprof-source-locator');
    }

    private function get_location_result_match_badge($result) {
        if (! empty($result['confidence_label'])) {
            return (string) $result['confidence_label'];
        }

        if (! empty($result['match_type'])) {
            return (string) $result['match_type'];
        }

        return __('Source file match', 'holyprof-source-locator');
    }

    private function get_location_result_badge_class($result) {
        $level = isset($result['confidence_level']) ? (string) $result['confidence_level'] : '';

        if ($level === 'exact') {
            return 'holyprof-source-locator-file-badge-confidence-exact';
        }

        if ($level === 'likely') {
            return 'holyprof-source-locator-file-badge-confidence-likely';
        }

        if ($level === 'possible') {
            return 'holyprof-source-locator-file-badge-confidence-possible';
        }

        return 'holyprof-source-locator-file-badge-menu';
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
            'php' => __('PHP', 'holyprof-source-locator'),
            'css' => __('CSS', 'holyprof-source-locator'),
            'js' => __('JS', 'holyprof-source-locator'),
            'html' => __('Template', 'holyprof-source-locator'),
            'txt' => __('TXT', 'holyprof-source-locator'),
        );

        if (! isset($labels[$extension])) {
            return strtoupper($extension);
        }

        return $labels[$extension];
    }

    private function can_current_user_open_admin_result($result) {
        $capability = isset($result['capability']) ? (string) $result['capability'] : '';
        $url = isset($result['url']) ? (string) $result['url'] : '';

        if (empty($result['is_clickable']) || ! $this->is_valid_admin_result_url($url)) {
            return false;
        }

        if ($capability === '') {
            return current_user_can('manage_options');
        }

        return current_user_can($capability);
    }

    private function is_valid_admin_result_url($url) {
        $url = (string) $url;

        if ($url === '') {
            return false;
        }

        return strpos($url, admin_url()) === 0;
    }
}
