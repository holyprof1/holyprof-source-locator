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
                    'loading' => __('Searching sources...', 'holyprof-source-locator'),
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
                            placeholder="<?php esc_attr_e('Search theme files, plugin files, hooks, templates, CSS, or JS...', 'holyprof-source-locator'); ?>"
                            autofocus
                        >
                    </div>

                    <div class="holyprof-source-locator-field holyprof-source-locator-field-select">
                        <label class="screen-reader-text" for="wsf-filter">
                            <?php esc_html_e('Search scope', 'holyprof-source-locator'); ?>
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
            'code' => __('Code', 'holyprof-source-locator'),
            'wordpress' => __('WordPress', 'holyprof-source-locator'),
            'plugins' => __('Plugins', 'holyprof-source-locator'),
            'themes' => __('Themes', 'holyprof-source-locator'),
            'settings' => __('Settings', 'holyprof-source-locator'),
            'menu-pages' => __('Menu Pages', 'holyprof-source-locator'),
            'templates' => __('Templates', 'holyprof-source-locator'),
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
            'results' => array(),
            'file_results' => array(),
            'settings_results' => array(),
            'admin_page_results' => array(),
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
        $state['results'] = isset($search_response['results']) ? $search_response['results'] : array();
        $state['file_results'] = isset($search_response['file_results']) ? $search_response['file_results'] : $state['results'];
        $state['settings_results'] = isset($search_response['settings_results']) ? $search_response['settings_results'] : array();
        $state['admin_page_results'] = isset($search_response['admin_page_results']) ? $search_response['admin_page_results'] : array();
        $state['is_settings_search'] = ! empty($search_response['is_settings_search']);
        $state['is_code_search'] = ! empty($search_response['is_code_search']);
        $state['is_text_finder_search'] = ! empty($search_response['is_text_finder_search']);

        if ($search_term === '') {
            $state['results_message'] = __('Enter a keyword to search.', 'holyprof-source-locator');
        } elseif (empty($state['file_results']) && empty($state['settings_results']) && empty($state['admin_page_results'])) {
            $state['results_message'] = __('No results found', 'holyprof-source-locator');
        }

        if (! empty($search_response['truncated'])) {
            $state['truncated_message'] = sprintf(
                /* translators: %d: maximum number of results shown before the search is truncated. */
                __('Showing the first %d matches. Refine your search to see more specific results.', 'holyprof-source-locator'),
                absint($search_response['max_results'])
            );
        }

        return $state;
    }

    private function render_results_html($state) {
        $search_term = isset($state['search_term']) ? (string) $state['search_term'] : '';
        $file_results = isset($state['file_results']) ? $state['file_results'] : array();
        $settings_results = isset($state['settings_results']) ? $state['settings_results'] : array();
        $admin_page_results = isset($state['admin_page_results']) ? $state['admin_page_results'] : array();
        $is_settings_search = ! empty($state['is_settings_search']);
        $is_code_search = ! empty($state['is_code_search']);
        $is_text_finder_search = ! empty($state['is_text_finder_search']);
        $results_message = isset($state['results_message']) ? $state['results_message'] : '';
        $truncated_message = isset($state['truncated_message']) ? $state['truncated_message'] : '';
        $total_results = count($file_results) + count($settings_results) + count($admin_page_results);

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

            <?php
            if ($is_code_search) {
                $sections = array('files', 'admin-pages', 'settings');
            } elseif ($is_settings_search) {
                $sections = array('settings', 'admin-pages', 'files');
            } elseif (! empty($admin_page_results)) {
                $sections = array('admin-pages', 'files', 'settings');
            } else {
                $sections = array('files', 'settings', 'admin-pages');
            }
            $best_match_assigned = false;
            ?>

            <?php foreach ($sections as $section_type) : ?>
                <?php if ($section_type === 'admin-pages' && ! empty($admin_page_results)) : ?>
                    <div class="holyprof-source-locator-section">
                        <h3 class="holyprof-source-locator-section-title">
                            <?php echo esc_html($is_text_finder_search ? __('Related Admin Page References', 'holyprof-source-locator') : __('Admin Page References', 'holyprof-source-locator')); ?>
                        </h3>
                        <div class="holyprof-source-locator-settings-list">
                            <?php foreach ($admin_page_results as $admin_page_index => $admin_page_result) : ?>
                                <?php $is_best_match = ! $best_match_assigned && $admin_page_index === 0; ?>
                                <?php if ($is_best_match) { $best_match_assigned = true; } ?>
                                <section class="holyprof-source-locator-setting-item<?php echo $is_best_match ? ' is-best-match' : ''; ?>">
                                    <div class="holyprof-source-locator-setting-header">
                                        <div class="holyprof-source-locator-result-heading">
                                            <h3><?php echo esc_html(isset($admin_page_result['page_title']) ? $admin_page_result['page_title'] : ''); ?></h3>
                                            <p class="holyprof-source-locator-result-source"><?php echo esc_html(isset($admin_page_result['plugin_name']) ? $admin_page_result['plugin_name'] : ''); ?></p>
                                            <p class="holyprof-source-locator-result-path"><?php echo esc_html(isset($admin_page_result['path']) ? $admin_page_result['path'] : ''); ?></p>
                                        </div>
                                        <div class="holyprof-source-locator-result-summary">
                                            <?php if ($is_best_match) : ?>
                                                <span class="holyprof-source-locator-file-badge holyprof-source-locator-file-badge-best">
                                                    <?php esc_html_e('Best match', 'holyprof-source-locator'); ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="holyprof-source-locator-file-badge holyprof-source-locator-file-badge-source">
                                                <?php
                                                echo esc_html(
                                                    ! empty($admin_page_result['plugin_name'])
                                                        ? $admin_page_result['plugin_name']
                                                        : __('Plugin', 'holyprof-source-locator')
                                                );
                                                ?>
                                            </span>
                                            <span class="holyprof-source-locator-file-badge holyprof-source-locator-file-badge-menu">
                                                <?php echo esc_html(isset($admin_page_result['match_type']) ? $admin_page_result['match_type'] : __('Admin page match', 'holyprof-source-locator')); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="holyprof-source-locator-setting-meta">
                                        <?php if (! empty($admin_page_result['plugin_name'])) : ?>
                                            <p><strong><?php esc_html_e('Plugin Name:', 'holyprof-source-locator'); ?></strong> <?php echo esc_html($admin_page_result['plugin_name']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($admin_page_result['page_title'])) : ?>
                                            <p><strong><?php esc_html_e('Admin Page Title:', 'holyprof-source-locator'); ?></strong> <?php echo esc_html($admin_page_result['page_title']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($admin_page_result['menu_title'])) : ?>
                                            <p><strong><?php esc_html_e('Menu Label:', 'holyprof-source-locator'); ?></strong> <?php echo esc_html($admin_page_result['menu_title']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($admin_page_result['slug'])) : ?>
                                            <p><strong><?php esc_html_e('Page Slug:', 'holyprof-source-locator'); ?></strong> <?php echo esc_html($admin_page_result['slug']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($admin_page_result['path'])) : ?>
                                            <p><strong><?php esc_html_e('Page Path:', 'holyprof-source-locator'); ?></strong> <code><?php echo esc_html($admin_page_result['path']); ?></code></p>
                                        <?php endif; ?>
                                        <?php if (! empty($admin_page_result['url'])) : ?>
                                            <p><strong><?php esc_html_e('Admin URL:', 'holyprof-source-locator'); ?></strong> <code><?php echo esc_html($admin_page_result['url']); ?></code></p>
                                        <?php endif; ?>
                                        <?php if (! empty($admin_page_result['callback_file'])) : ?>
                                            <p><strong><?php esc_html_e('Callback File:', 'holyprof-source-locator'); ?></strong> <?php echo esc_html($admin_page_result['callback_file']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($admin_page_result['is_clickable']) && ! empty($admin_page_result['url']) && $this->is_valid_admin_result_url($admin_page_result['url'])) : ?>
                                            <p class="holyprof-source-locator-setting-action">
                                                <a class="button button-secondary" href="<?php echo esc_url($admin_page_result['url']); ?>" target="_blank" rel="noopener noreferrer">
                                                    <?php esc_html_e('Open admin page', 'holyprof-source-locator'); ?>
                                                </a>
                                            </p>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (! empty($admin_page_result['visible_text_matches']) && is_array($admin_page_result['visible_text_matches'])) : ?>
                                        <div class="holyprof-source-locator-match-rows">
                                            <?php foreach ($admin_page_result['visible_text_matches'] as $visible_text_match) : ?>
                                                <div class="holyprof-source-locator-match-row">
                                                    <div class="holyprof-source-locator-line-number">
                                                        <?php echo esc_html(isset($visible_text_match['kind']) ? $visible_text_match['kind'] : __('Visible text', 'holyprof-source-locator')); ?>
                                                    </div>
                                                    <div>
                                                        <p class="holyprof-source-locator-callback-file"><?php esc_html_e('Related admin page text', 'holyprof-source-locator'); ?></p>
                                                        <pre class="holyprof-source-locator-result-snippet"><?php echo wp_kses($this->highlight_match($visible_text_match['snippet'], $search_term), array('mark' => array())); ?></pre>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (! empty($admin_page_result['callback_matches']) && is_array($admin_page_result['callback_matches'])) : ?>
                                        <div class="holyprof-source-locator-match-rows">
                                            <?php foreach ($admin_page_result['callback_matches'] as $callback_match) : ?>
                                                <div class="holyprof-source-locator-match-row">
                                                    <div class="holyprof-source-locator-line-number">
                                                    <?php
                                                    printf(
                                                        /* translators: %d: line number containing the admin page callback match. */
                                                        esc_html__('Line %d', 'holyprof-source-locator'),
                                                        absint($callback_match['line_number'])
                                                        );
                                                        ?>
                                                    </div>
                                                    <div>
                                                        <?php if (! empty($callback_match['file'])) : ?>
                                                            <p class="holyprof-source-locator-callback-file"><?php echo esc_html($callback_match['file']); ?></p>
                                                        <?php endif; ?>
                                                        <pre class="holyprof-source-locator-result-snippet"><?php echo wp_kses($this->highlight_match($callback_match['snippet'], $search_term), array('mark' => array())); ?></pre>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($section_type === 'settings' && ! empty($settings_results)) : ?>
                    <div class="holyprof-source-locator-section">
                        <h3 class="holyprof-source-locator-section-title">
                            <?php echo esc_html($is_text_finder_search ? __('Related Settings & Menu References', 'holyprof-source-locator') : __('Settings & Menu References', 'holyprof-source-locator')); ?>
                        </h3>
                        <div class="holyprof-source-locator-settings-list">
                            <?php foreach ($settings_results as $setting_index => $setting_result) : ?>
                                <?php $is_best_match = ! $best_match_assigned && $setting_index === 0; ?>
                                <?php if ($is_best_match) { $best_match_assigned = true; } ?>
                                <section class="holyprof-source-locator-setting-item<?php echo $is_best_match ? ' is-best-match' : ''; ?>">
                                    <div class="holyprof-source-locator-setting-header">
                                        <div class="holyprof-source-locator-result-heading">
                                            <h3><?php echo esc_html(isset($setting_result['title']) ? $setting_result['title'] : ''); ?></h3>
                                            <p class="holyprof-source-locator-result-source"><?php echo esc_html(isset($setting_result['source']) ? $setting_result['source'] : ''); ?></p>
                                            <p class="holyprof-source-locator-result-path"><?php echo esc_html(isset($setting_result['path']) ? $setting_result['path'] : ''); ?></p>
                                        </div>
                                        <div class="holyprof-source-locator-result-summary">
                                            <?php if ($is_best_match) : ?>
                                                <span class="holyprof-source-locator-file-badge holyprof-source-locator-file-badge-best">
                                                    <?php esc_html_e('Best match', 'holyprof-source-locator'); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (! empty($setting_result['source_type'])) : ?>
                                                <span class="holyprof-source-locator-file-badge holyprof-source-locator-file-badge-source">
                                                    <?php echo esc_html($setting_result['source_type']); ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="holyprof-source-locator-file-badge holyprof-source-locator-file-badge-menu">
                                                <?php echo esc_html(isset($setting_result['match_type']) ? $setting_result['match_type'] : __('Settings', 'holyprof-source-locator')); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="holyprof-source-locator-setting-meta">
                                        <?php if (! empty($setting_result['menu_title'])) : ?>
                                            <p><strong><?php esc_html_e('Menu Title:', 'holyprof-source-locator'); ?></strong> <?php echo esc_html($setting_result['menu_title']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($setting_result['page_title'])) : ?>
                                            <p><strong><?php esc_html_e('Page Title:', 'holyprof-source-locator'); ?></strong> <?php echo esc_html($setting_result['page_title']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($setting_result['section_title'])) : ?>
                                            <p><strong><?php esc_html_e('Section Title:', 'holyprof-source-locator'); ?></strong> <?php echo esc_html($setting_result['section_title']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($setting_result['field_label'])) : ?>
                                            <p><strong><?php esc_html_e('Field Label:', 'holyprof-source-locator'); ?></strong> <?php echo esc_html($setting_result['field_label']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($setting_result['setting_name'])) : ?>
                                            <p><strong><?php esc_html_e('Setting Name:', 'holyprof-source-locator'); ?></strong> <?php echo esc_html($setting_result['setting_name']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($setting_result['slug'])) : ?>
                                            <p><strong><?php esc_html_e('Slug:', 'holyprof-source-locator'); ?></strong> <?php echo esc_html($setting_result['slug']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($setting_result['url'])) : ?>
                                            <p><strong><?php esc_html_e('Admin URL:', 'holyprof-source-locator'); ?></strong> <code><?php echo esc_html($setting_result['url']); ?></code></p>
                                        <?php endif; ?>
                                        <?php if (! empty($setting_result['note'])) : ?>
                                            <p class="holyprof-source-locator-setting-note"><?php echo esc_html($setting_result['note']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($setting_result['is_clickable']) && ! empty($setting_result['url']) && $this->is_valid_admin_result_url($setting_result['url'])) : ?>
                                            <p class="holyprof-source-locator-setting-action">
                                                <a class="button button-secondary" href="<?php echo esc_url($setting_result['url']); ?>" target="_blank" rel="noopener noreferrer">
                                                    <?php esc_html_e('Open settings page', 'holyprof-source-locator'); ?>
                                                </a>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($section_type === 'files' && ! empty($file_results)) : ?>
                    <?php $grouped_results = $this->group_results_by_file($file_results); ?>
                    <div class="holyprof-source-locator-section">
                        <h3 class="holyprof-source-locator-section-title">
                            <?php echo esc_html($is_text_finder_search ? __('Source File Matches', 'holyprof-source-locator') : __('File Matches', 'holyprof-source-locator')); ?>
                        </h3>
                        <div class="holyprof-source-locator-results-list">
                            <?php $file_group_index = 0; ?>
                            <?php foreach ($grouped_results as $file_path => $matches) : ?>
                                <?php $file_name = wp_basename($file_path); ?>
                                <?php $file_type = $this->get_file_type_label($file_path); ?>
                                <?php $source_label = isset($matches[0]['source_label']) ? $matches[0]['source_label'] : ''; ?>
                                <?php $source_type = isset($matches[0]['source_type']) ? $matches[0]['source_type'] : ''; ?>
                                <?php $is_best_match = ! $best_match_assigned && $file_group_index === 0; ?>
                                <?php if ($is_best_match) { $best_match_assigned = true; } ?>
                                <section class="holyprof-source-locator-result-group<?php echo $is_best_match ? ' is-best-match' : ''; ?>">
                                    <div class="holyprof-source-locator-result-header">
                                        <div class="holyprof-source-locator-result-heading">
                                            <h3><?php echo esc_html($file_name); ?></h3>
                                            <?php if ($source_label) : ?>
                                                <p class="holyprof-source-locator-result-source"><?php echo esc_html($source_label); ?></p>
                                            <?php endif; ?>
                                            <p class="holyprof-source-locator-result-path"><?php echo esc_html($file_path); ?></p>
                                        </div>

                                        <div class="holyprof-source-locator-result-summary">
                                            <?php if ($is_best_match) : ?>
                                                <span class="holyprof-source-locator-file-badge holyprof-source-locator-file-badge-best">
                                                    <?php esc_html_e('Best match', 'holyprof-source-locator'); ?>
                                                </span>
                                            <?php endif; ?>
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
                                            <button type="button" class="button button-secondary holyprof-source-locator-copy-path" data-copy-text="<?php echo esc_attr($file_path); ?>">
                                                <?php esc_html_e('Copy path', 'holyprof-source-locator'); ?>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="holyprof-source-locator-match-rows">
                                        <?php foreach ($matches as $match) : ?>
                                            <div class="holyprof-source-locator-match-row">
                                                <div class="holyprof-source-locator-line-number">
                                                    <?php
                                                    printf(
                                                        /* translators: %d: line number containing the file match. */
                                                        esc_html__('Line %d', 'holyprof-source-locator'),
                                                        absint($match['line_number'])
                                                    );
                                                    ?>
                                                </div>
                                                <pre class="holyprof-source-locator-result-snippet"><?php echo wp_kses($this->highlight_match($match['snippet'], $search_term), array('mark' => array())); ?></pre>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                                <?php $file_group_index++; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if (empty($file_results) && empty($settings_results) && empty($admin_page_results) && $results_message) : ?>
                <div class="holyprof-source-locator-notice">
                    <p><?php echo esc_html($results_message); ?></p>
                </div>
                <div class="holyprof-source-locator-helper-tips">
                    <h3 class="holyprof-source-locator-section-title"><?php esc_html_e('No results? Try...', 'holyprof-source-locator'); ?></h3>
                    <ul>
                        <li><?php esc_html_e('switching the search scope to Code, Plugins, Themes, or Templates', 'holyprof-source-locator'); ?></li>
                        <li><?php esc_html_e('searching for a function name, hook name, class name, CSS selector, or JS keyword', 'holyprof-source-locator'); ?></li>
                        <li><?php esc_html_e('searching for a shorter text fragment from the source you want to trace', 'holyprof-source-locator'); ?></li>
                        <li><?php esc_html_e('trying simpler spacing or punctuation if the first search is too exact', 'holyprof-source-locator'); ?></li>
                    </ul>
                </div>
            <?php elseif (! empty($settings_results) && empty($file_results)) : ?>
                <div class="holyprof-source-locator-notice">
                    <p><?php esc_html_e('No file matches found for this search.', 'holyprof-source-locator'); ?></p>
                </div>
            <?php elseif (empty($file_results) && empty($settings_results) && empty($admin_page_results)) : ?>
                <p class="description">
                    <?php esc_html_e('Enter a search term and choose a scope to begin.', 'holyprof-source-locator'); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php

        return (string) ob_get_clean();
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

    private function is_valid_admin_result_url($url) {
        $url = (string) $url;

        if ($url === '') {
            return false;
        }

        return strpos($url, admin_url()) === 0;
    }
}
