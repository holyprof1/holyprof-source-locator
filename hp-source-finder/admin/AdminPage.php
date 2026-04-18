<?php

if (! defined('ABSPATH')) {
    exit;
}

class HP_Source_Finder_AdminPage {
    /**
     * @var HP_Source_Finder_SearchEngine
     */
    private $search_engine;

    public function __construct(HP_Source_Finder_SearchEngine $search_engine) {
        $this->search_engine = $search_engine;
    }

    public function register() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_hp_source_finder_search', array($this, 'handle_ajax_search'));
    }

    public function add_menu_page() {
        add_menu_page(
            __('HP Source Finder', 'hp-source-finder'),
            __('HP Source Finder', 'hp-source-finder'),
            'manage_options',
            'hp-source-finder',
            array($this, 'render_page'),
            'dashicons-search',
            80
        );
    }

    public function enqueue_assets($hook_suffix) {
        if ($hook_suffix !== 'toplevel_page_hp-source-finder') {
            return;
        }

        wp_enqueue_style(
            'hp-source-finder-admin',
            HP_SOURCE_FINDER_URL . 'assets/admin.css',
            array(),
            HP_SOURCE_FINDER_VERSION
        );

        wp_enqueue_script(
            'hp-source-finder-admin',
            HP_SOURCE_FINDER_URL . 'assets/admin.js',
            array(),
            HP_SOURCE_FINDER_VERSION,
            true
        );

        wp_localize_script(
            'hp-source-finder-admin',
            'hpSourceFinderAdmin',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('hp_source_finder_search'),
                'action' => 'hp_source_finder_search',
                'messages' => array(
                    'loading' => __('Searching sources...', 'hp-source-finder'),
                    'error' => __('Something went wrong while searching. Please try again.', 'hp-source-finder'),
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
            $state['results_message'] = __('Your search request has expired. Please try again.', 'hp-source-finder');
        }

        ?>
        <div class="wrap hp-source-finder-admin">
            <h1><?php esc_html_e('HP Source Finder', 'hp-source-finder'); ?></h1>
            <form method="get" class="hp-source-finder-form" id="hp-source-finder-form">
                <input type="hidden" name="page" value="hp-source-finder">
                <?php wp_nonce_field('hp_source_finder_search', 'hp_source_finder_nonce'); ?>

                <div class="hp-source-finder-toolbar">
                    <div class="hp-source-finder-field">
                        <label class="screen-reader-text" for="wsf-search">
                            <?php esc_html_e('Search term', 'hp-source-finder'); ?>
                        </label>
                        <input
                            id="wsf-search"
                            type="search"
                            name="wsf_search"
                            value="<?php echo esc_attr($search_term); ?>"
                            placeholder="<?php esc_attr_e('Search code, settings, menu pages, or labels...', 'hp-source-finder'); ?>"
                            autofocus
                        >
                    </div>

                    <div class="hp-source-finder-field hp-source-finder-field-select">
                        <label class="screen-reader-text" for="wsf-filter">
                            <?php esc_html_e('Search scope', 'hp-source-finder'); ?>
                        </label>
                        <select id="wsf-filter" name="wsf_filter">
                            <?php foreach ($filters as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($filter, $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="hp-source-finder-actions">
                        <button type="submit" name="wsf_submit" value="1" class="button button-primary">
                            <?php esc_html_e('Search', 'hp-source-finder'); ?>
                        </button>
                    </div>
                </div>
            </form>

            <div id="hp-source-finder-results" aria-live="polite">
                <?php echo $this->render_results_html($state); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </div>
        <?php
    }

    public function handle_ajax_search() {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(
                array(
                    'message' => __('You do not have permission to run this search.', 'hp-source-finder'),
                ),
                403
            );
        }

        check_ajax_referer('hp_source_finder_search', 'nonce');

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
            'all' => __('All', 'hp-source-finder'),
            'code' => __('Code', 'hp-source-finder'),
            'wordpress' => __('WordPress', 'hp-source-finder'),
            'plugins' => __('Plugins', 'hp-source-finder'),
            'themes' => __('Themes', 'hp-source-finder'),
            'settings' => __('Settings', 'hp-source-finder'),
            'menu-pages' => __('Menu Pages', 'hp-source-finder'),
            'templates' => __('Templates', 'hp-source-finder'),
        );
    }

    private function sanitize_search_term($value) {
        return sanitize_text_field((string) $value);
    }

    private function sanitize_filter($value) {
        return sanitize_key((string) $value);
    }

    private function is_valid_search_request() {
        $nonce = $this->get_get_param('hp_source_finder_nonce');

        if ($nonce === '') {
            return false;
        }

        return (bool) wp_verify_nonce($nonce, 'hp_source_finder_search');
    }

    private function get_render_request_data() {
        $has_submitted = '1' === $this->get_get_param('wsf_submit');
        $has_valid_request = ! $has_submitted || $this->is_valid_search_request();

        return array(
            'search_term' => $has_valid_request ? $this->sanitize_search_term($this->get_get_param('wsf_search')) : '',
            'filter' => $has_valid_request ? $this->sanitize_filter($this->get_get_param('wsf_filter')) : 'all',
            'has_submitted' => $has_submitted,
            'has_valid_request' => $has_valid_request,
        );
    }

    private function get_ajax_request_data() {
        return array(
            'search_term' => $this->sanitize_search_term($this->get_post_param('wsf_search')),
            'filter' => $this->sanitize_filter($this->get_post_param('wsf_filter')),
        );
    }

    private function get_get_param($key) {
        if (! isset($_GET[$key])) {
            return '';
        }

        return sanitize_text_field(wp_unslash($_GET[$key]));
    }

    private function get_post_param($key) {
        if (! isset($_POST[$key])) {
            return '';
        }

        return sanitize_text_field(wp_unslash($_POST[$key]));
    }

    private function build_search_state($search_term, $filter, $has_submitted) {
        $state = array(
            'search_term' => $search_term,
            'results' => array(),
            'file_results' => array(),
            'settings_results' => array(),
            'admin_page_results' => array(),
            'is_settings_search' => false,
            'is_text_finder_search' => false,
            'results_message' => '',
            'truncated_message' => '',
            'debug_info' => array(),
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
        $state['is_text_finder_search'] = ! empty($search_response['is_text_finder_search']);
        $state['debug_info'] = isset($search_response['debug_info']) && is_array($search_response['debug_info']) ? $search_response['debug_info'] : array();

        if ($search_term === '') {
            $state['results_message'] = __('Enter a keyword to search.', 'hp-source-finder');
        } elseif (empty($state['file_results']) && empty($state['settings_results']) && empty($state['admin_page_results'])) {
            $state['results_message'] = __('No results found', 'hp-source-finder');
        }

        if (! empty($search_response['truncated'])) {
            $state['truncated_message'] = sprintf(
                /* translators: %d: maximum number of results shown */
                __('Showing the first %d matches. Refine your search to see more specific results.', 'hp-source-finder'),
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
        $is_text_finder_search = ! empty($state['is_text_finder_search']);
        $results_message = isset($state['results_message']) ? $state['results_message'] : '';
        $truncated_message = isset($state['truncated_message']) ? $state['truncated_message'] : '';
        $debug_info = isset($state['debug_info']) && is_array($state['debug_info']) ? $state['debug_info'] : array();
        $total_results = count($file_results) + count($settings_results) + count($admin_page_results);

        ob_start();
        ?>
        <div class="hp-source-finder-results">
            <h2><?php esc_html_e('Results', 'hp-source-finder'); ?></h2>

            <?php if ($total_results > 0) : ?>
                <p class="hp-source-finder-results-count">
                    <?php
                    /* translators: %d: total number of search results found */
                    /* translators: %d: total number of search results found. */
                    printf(
                        esc_html(_n('%d result found', '%d results found', $total_results, 'hp-source-finder')),
                        absint($total_results)
                    );
                    ?>
                </p>
            <?php endif; ?>

            <?php if ($truncated_message) : ?>
                <div class="hp-source-finder-notice hp-source-finder-notice-warning">
                    <p><?php echo esc_html($truncated_message); ?></p>
                </div>
            <?php endif; ?>

            <?php if (! empty($debug_info)) : ?>
                <div class="hp-source-finder-notice">
                    <p><strong><?php esc_html_e('Search debug', 'hp-source-finder'); ?></strong></p>
                    <ul>
                        <?php foreach ($debug_info as $debug_line) : ?>
                            <li><?php echo esc_html($debug_line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php
            if ($is_settings_search) {
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
                    <div class="hp-source-finder-section">
                        <h3 class="hp-source-finder-section-title">
                            <?php echo esc_html($is_text_finder_search ? __('Text Finder: Admin Page Matches', 'hp-source-finder') : __('Admin Page Matches', 'hp-source-finder')); ?>
                        </h3>
                        <div class="hp-source-finder-settings-list">
                            <?php foreach ($admin_page_results as $admin_page_index => $admin_page_result) : ?>
                                <?php $is_best_match = ! $best_match_assigned && $admin_page_index === 0; ?>
                                <?php if ($is_best_match) { $best_match_assigned = true; } ?>
                                <section class="hp-source-finder-setting-item<?php echo $is_best_match ? ' is-best-match' : ''; ?>">
                                    <div class="hp-source-finder-setting-header">
                                        <div class="hp-source-finder-result-heading">
                                            <h3><?php echo esc_html(isset($admin_page_result['page_title']) ? $admin_page_result['page_title'] : ''); ?></h3>
                                            <p class="hp-source-finder-result-source"><?php echo esc_html(isset($admin_page_result['plugin_name']) ? $admin_page_result['plugin_name'] : ''); ?></p>
                                            <p class="hp-source-finder-result-path"><?php echo esc_html(isset($admin_page_result['path']) ? $admin_page_result['path'] : ''); ?></p>
                                        </div>
                                        <div class="hp-source-finder-result-summary">
                                            <?php if ($is_best_match) : ?>
                                                <span class="hp-source-finder-file-badge hp-source-finder-file-badge-best">
                                                    <?php esc_html_e('Best match', 'hp-source-finder'); ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="hp-source-finder-file-badge hp-source-finder-file-badge-source">
                                                <?php
                                                echo esc_html(
                                                    ! empty($admin_page_result['plugin_name'])
                                                        ? $admin_page_result['plugin_name']
                                                        : __('Plugin', 'hp-source-finder')
                                                );
                                                ?>
                                            </span>
                                            <span class="hp-source-finder-file-badge hp-source-finder-file-badge-menu">
                                                <?php echo esc_html(isset($admin_page_result['match_type']) ? $admin_page_result['match_type'] : __('Admin page match', 'hp-source-finder')); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="hp-source-finder-setting-meta">
                                        <?php if (! empty($admin_page_result['plugin_name'])) : ?>
                                            <p><strong><?php esc_html_e('Plugin Name:', 'hp-source-finder'); ?></strong> <?php echo esc_html($admin_page_result['plugin_name']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($admin_page_result['page_title'])) : ?>
                                            <p><strong><?php esc_html_e('Admin Page Title:', 'hp-source-finder'); ?></strong> <?php echo esc_html($admin_page_result['page_title']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($admin_page_result['menu_title'])) : ?>
                                            <p><strong><?php esc_html_e('Menu Label:', 'hp-source-finder'); ?></strong> <?php echo esc_html($admin_page_result['menu_title']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($admin_page_result['slug'])) : ?>
                                            <p><strong><?php esc_html_e('Page Slug:', 'hp-source-finder'); ?></strong> <?php echo esc_html($admin_page_result['slug']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($admin_page_result['path'])) : ?>
                                            <p><strong><?php esc_html_e('Page Path:', 'hp-source-finder'); ?></strong> <code><?php echo esc_html($admin_page_result['path']); ?></code></p>
                                        <?php endif; ?>
                                        <?php if (! empty($admin_page_result['url'])) : ?>
                                            <p><strong><?php esc_html_e('Admin URL:', 'hp-source-finder'); ?></strong> <code><?php echo esc_html($admin_page_result['url']); ?></code></p>
                                        <?php endif; ?>
                                        <?php if (! empty($admin_page_result['callback_file'])) : ?>
                                            <p><strong><?php esc_html_e('Callback File:', 'hp-source-finder'); ?></strong> <?php echo esc_html($admin_page_result['callback_file']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($admin_page_result['is_clickable']) && ! empty($admin_page_result['url']) && $this->is_valid_admin_result_url($admin_page_result['url'])) : ?>
                                            <p class="hp-source-finder-setting-action">
                                                <a class="button button-secondary" href="<?php echo esc_url($admin_page_result['url']); ?>" target="_blank" rel="noopener noreferrer">
                                                    <?php esc_html_e('Open admin page', 'hp-source-finder'); ?>
                                                </a>
                                            </p>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (! empty($admin_page_result['visible_text_matches']) && is_array($admin_page_result['visible_text_matches'])) : ?>
                                        <div class="hp-source-finder-match-rows">
                                            <?php foreach ($admin_page_result['visible_text_matches'] as $visible_text_match) : ?>
                                                <div class="hp-source-finder-match-row">
                                                    <div class="hp-source-finder-line-number">
                                                        <?php echo esc_html(isset($visible_text_match['kind']) ? $visible_text_match['kind'] : __('Visible text', 'hp-source-finder')); ?>
                                                    </div>
                                                    <div>
                                                        <p class="hp-source-finder-callback-file"><?php esc_html_e('Rendered admin page text', 'hp-source-finder'); ?></p>
                                                        <pre class="hp-source-finder-result-snippet"><?php echo wp_kses($this->highlight_match($visible_text_match['snippet'], $search_term), array('mark' => array())); ?></pre>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (! empty($admin_page_result['callback_matches']) && is_array($admin_page_result['callback_matches'])) : ?>
                                        <div class="hp-source-finder-match-rows">
                                            <?php foreach ($admin_page_result['callback_matches'] as $callback_match) : ?>
                                                <div class="hp-source-finder-match-row">
                                                    <div class="hp-source-finder-line-number">
                                                    <?php
                                                    /* translators: %d: line number where the match was found */
                                                    /* translators: %d: line number containing the admin page callback match. */
                                                    printf(
                                                        esc_html__('Line %d', 'hp-source-finder'),
                                                        absint($callback_match['line_number'])
                                                        );
                                                        ?>
                                                    </div>
                                                    <div>
                                                        <?php if (! empty($callback_match['file'])) : ?>
                                                            <p class="hp-source-finder-callback-file"><?php echo esc_html($callback_match['file']); ?></p>
                                                        <?php endif; ?>
                                                        <pre class="hp-source-finder-result-snippet"><?php echo wp_kses($this->highlight_match($callback_match['snippet'], $search_term), array('mark' => array())); ?></pre>
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
                    <div class="hp-source-finder-section">
                        <h3 class="hp-source-finder-section-title">
                            <?php echo esc_html($is_text_finder_search ? __('Text Finder: Settings & Menu Paths', 'hp-source-finder') : __('Settings & Menu Paths', 'hp-source-finder')); ?>
                        </h3>
                        <div class="hp-source-finder-settings-list">
                            <?php foreach ($settings_results as $setting_index => $setting_result) : ?>
                                <?php $is_best_match = ! $best_match_assigned && $setting_index === 0; ?>
                                <?php if ($is_best_match) { $best_match_assigned = true; } ?>
                                <section class="hp-source-finder-setting-item<?php echo $is_best_match ? ' is-best-match' : ''; ?>">
                                    <div class="hp-source-finder-setting-header">
                                        <div class="hp-source-finder-result-heading">
                                            <h3><?php echo esc_html(isset($setting_result['title']) ? $setting_result['title'] : ''); ?></h3>
                                            <p class="hp-source-finder-result-source"><?php echo esc_html(isset($setting_result['source']) ? $setting_result['source'] : ''); ?></p>
                                            <p class="hp-source-finder-result-path"><?php echo esc_html(isset($setting_result['path']) ? $setting_result['path'] : ''); ?></p>
                                        </div>
                                        <div class="hp-source-finder-result-summary">
                                            <?php if ($is_best_match) : ?>
                                                <span class="hp-source-finder-file-badge hp-source-finder-file-badge-best">
                                                    <?php esc_html_e('Best match', 'hp-source-finder'); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (! empty($setting_result['source_type'])) : ?>
                                                <span class="hp-source-finder-file-badge hp-source-finder-file-badge-source">
                                                    <?php echo esc_html($setting_result['source_type']); ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="hp-source-finder-file-badge hp-source-finder-file-badge-menu">
                                                <?php echo esc_html(isset($setting_result['match_type']) ? $setting_result['match_type'] : __('Settings', 'hp-source-finder')); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="hp-source-finder-setting-meta">
                                        <?php if (! empty($setting_result['menu_title'])) : ?>
                                            <p><strong><?php esc_html_e('Menu Title:', 'hp-source-finder'); ?></strong> <?php echo esc_html($setting_result['menu_title']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($setting_result['page_title'])) : ?>
                                            <p><strong><?php esc_html_e('Page Title:', 'hp-source-finder'); ?></strong> <?php echo esc_html($setting_result['page_title']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($setting_result['section_title'])) : ?>
                                            <p><strong><?php esc_html_e('Section Title:', 'hp-source-finder'); ?></strong> <?php echo esc_html($setting_result['section_title']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($setting_result['field_label'])) : ?>
                                            <p><strong><?php esc_html_e('Field Label:', 'hp-source-finder'); ?></strong> <?php echo esc_html($setting_result['field_label']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($setting_result['setting_name'])) : ?>
                                            <p><strong><?php esc_html_e('Setting Name:', 'hp-source-finder'); ?></strong> <?php echo esc_html($setting_result['setting_name']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($setting_result['slug'])) : ?>
                                            <p><strong><?php esc_html_e('Slug:', 'hp-source-finder'); ?></strong> <?php echo esc_html($setting_result['slug']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($setting_result['url'])) : ?>
                                            <p><strong><?php esc_html_e('Admin URL:', 'hp-source-finder'); ?></strong> <code><?php echo esc_html($setting_result['url']); ?></code></p>
                                        <?php endif; ?>
                                        <?php if (! empty($setting_result['note'])) : ?>
                                            <p class="hp-source-finder-setting-note"><?php echo esc_html($setting_result['note']); ?></p>
                                        <?php endif; ?>
                                        <?php if (! empty($setting_result['is_clickable']) && ! empty($setting_result['url']) && $this->is_valid_admin_result_url($setting_result['url'])) : ?>
                                            <p class="hp-source-finder-setting-action">
                                                <a class="button button-secondary" href="<?php echo esc_url($setting_result['url']); ?>" target="_blank" rel="noopener noreferrer">
                                                    <?php esc_html_e('Open settings page', 'hp-source-finder'); ?>
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
                    <div class="hp-source-finder-section">
                        <h3 class="hp-source-finder-section-title">
                            <?php echo esc_html($is_text_finder_search ? __('Text Finder: File Matches', 'hp-source-finder') : __('File Matches', 'hp-source-finder')); ?>
                        </h3>
                        <div class="hp-source-finder-results-list">
                            <?php $file_group_index = 0; ?>
                            <?php foreach ($grouped_results as $file_path => $matches) : ?>
                                <?php $file_name = wp_basename($file_path); ?>
                                <?php $file_type = $this->get_file_type_label($file_path); ?>
                                <?php $source_label = isset($matches[0]['source_label']) ? $matches[0]['source_label'] : ''; ?>
                                <?php $source_type = isset($matches[0]['source_type']) ? $matches[0]['source_type'] : ''; ?>
                                <?php $is_best_match = ! $best_match_assigned && $file_group_index === 0; ?>
                                <?php if ($is_best_match) { $best_match_assigned = true; } ?>
                                <section class="hp-source-finder-result-group<?php echo $is_best_match ? ' is-best-match' : ''; ?>">
                                    <div class="hp-source-finder-result-header">
                                        <div class="hp-source-finder-result-heading">
                                            <h3><?php echo esc_html($file_name); ?></h3>
                                            <?php if ($source_label) : ?>
                                                <p class="hp-source-finder-result-source"><?php echo esc_html($source_label); ?></p>
                                            <?php endif; ?>
                                            <p class="hp-source-finder-result-path"><?php echo esc_html($file_path); ?></p>
                                        </div>

                                        <div class="hp-source-finder-result-summary">
                                            <?php if ($is_best_match) : ?>
                                                <span class="hp-source-finder-file-badge hp-source-finder-file-badge-best">
                                                    <?php esc_html_e('Best match', 'hp-source-finder'); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($source_type) : ?>
                                                <span class="hp-source-finder-file-badge hp-source-finder-file-badge-source">
                                                    <?php echo esc_html($source_type); ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="hp-source-finder-file-badge">
                                                <?php echo esc_html($file_type); ?>
                                            </span>
                                            <span class="hp-source-finder-match-count">
                                                <?php
                                                /* translators: %d: number of matches found in the file */
                                                /* translators: %d: number of matches found in this file. */
                                                printf(
                                                    esc_html(_n('%d match', '%d matches', count($matches), 'hp-source-finder')),
                                                    absint(count($matches))
                                                );
                                                ?>
                                            </span>
                                            <button type="button" class="button button-secondary hp-source-finder-copy-path" data-copy-text="<?php echo esc_attr($file_path); ?>">
                                                <?php esc_html_e('Copy path', 'hp-source-finder'); ?>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="hp-source-finder-match-rows">
                                        <?php foreach ($matches as $match) : ?>
                                            <div class="hp-source-finder-match-row">
                                                <div class="hp-source-finder-line-number">
                                                    <?php
                                                    /* translators: %d: line number where the match was found */
                                                    /* translators: %d: line number containing the file match. */
                                                    printf(
                                                        esc_html__('Line %d', 'hp-source-finder'),
                                                        absint($match['line_number'])
                                                    );
                                                    ?>
                                                </div>
                                                <pre class="hp-source-finder-result-snippet"><?php echo wp_kses($this->highlight_match($match['snippet'], $search_term), array('mark' => array())); ?></pre>
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
                <div class="hp-source-finder-notice">
                    <p><?php echo esc_html($results_message); ?></p>
                </div>
                <div class="hp-source-finder-helper-tips">
                    <h3 class="hp-source-finder-section-title"><?php esc_html_e('No results? Try...', 'hp-source-finder'); ?></h3>
                    <ul>
                        <li><?php esc_html_e('switching the search scope to WordPress, Settings, or Menu Pages', 'hp-source-finder'); ?></li>
                        <li><?php esc_html_e('searching for singular and plural terms like comment/comments or breadcrumb/breadcrumbs', 'hp-source-finder'); ?></li>
                        <li><?php esc_html_e('pasting the exact visible text you want to locate', 'hp-source-finder'); ?></li>
                        <li><?php esc_html_e('trying a shorter phrase without punctuation', 'hp-source-finder'); ?></li>
                    </ul>
                </div>
            <?php elseif (! empty($settings_results) && empty($file_results)) : ?>
                <div class="hp-source-finder-notice">
                    <p><?php esc_html_e('No file matches found for this search.', 'hp-source-finder'); ?></p>
                </div>
            <?php elseif (empty($file_results) && empty($settings_results) && empty($admin_page_results)) : ?>
                <p class="description">
                    <?php esc_html_e('Enter a search term and choose a filter to begin.', 'hp-source-finder'); ?>
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
            'php' => __('PHP', 'hp-source-finder'),
            'css' => __('CSS', 'hp-source-finder'),
            'js' => __('JS', 'hp-source-finder'),
            'html' => __('Template', 'hp-source-finder'),
            'txt' => __('TXT', 'hp-source-finder'),
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
