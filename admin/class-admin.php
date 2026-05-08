<?php
/**
 * CleverSay Admin Handler
 * 
 * Manages admin interface, menus, and settings
 * 
 * @package CleverSay
 * @since 2.0.0
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class Admin {
    
    private Database $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    /**
     * Initialize admin hooks
     */
    public function init(): void {
        // Defensive — ensure Icons class is loaded (used in all views)
        if (!class_exists('\\CleverSay\\Icons')) {
            $icons_path = CLEVERSAY_PLUGIN_DIR . 'includes/class-icons.php';
            if (file_exists($icons_path)) {
                require_once $icons_path;
            }
        }

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_filter('admin_body_class', [$this, 'add_body_class']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_settings_save']);
        add_action('admin_init', [$this, 'handle_token_regenerate']);
        add_action('admin_init', [$this, 'handle_import_upload']);
        add_action('admin_init', [$this, 'handle_synonym_form']);
        add_action('admin_init', [$this, 'handle_inquiry_actions']);
        add_action('admin_init', [$this, 'handle_export_download']);
        add_action('admin_init', [$this, 'handle_inspector_actions']);
        
        // Admin post handlers
        add_action('admin_post_cleversay_save_keyword', [$this, 'handle_save_keyword']);
        add_action('admin_post_cleversay_save_phrase_group', [$this, 'handle_save_phrase_group']);
        add_action('admin_post_cleversay_dismiss_roundtrip', [$this, 'handle_dismiss_roundtrip']);
        add_action('admin_post_cleversay_update_keyword', [$this, 'handle_update_keyword']);
        add_action('admin_post_cleversay_respond_inquiry', [$this, 'handle_respond_inquiry']);
        add_action('admin_post_cleversay_apply_migration', [$this, 'handle_apply_migration']);
        add_action('admin_post_cleversay_restore_reuse_pointers', [$this, 'handle_restore_reuse_pointers']);
        add_action('admin_post_cleversay_import_legacy_synonyms', [$this, 'handle_import_legacy_synonyms']);
        add_action('wp_ajax_cleversay_run_eval', [$this, 'ajax_run_eval']);
        add_action('wp_ajax_cleversay_save_eval_run', [$this, 'ajax_save_eval_run']);
        add_action('wp_ajax_cleversay_delete_eval_run', [$this, 'ajax_delete_eval_run']);
        
        // AJAX handlers for admin
        add_action('wp_ajax_cleversay_save_entry', [$this, 'ajax_save_entry']);
        add_action('wp_ajax_cleversay_delete_entry', [$this, 'ajax_delete_entry']);
        add_action('wp_ajax_cleversay_bulk_action', [$this, 'ajax_bulk_action']);
        add_action('wp_ajax_cleversay_validate_link', [$this, 'ajax_validate_link']);
        add_action('wp_ajax_cleversay_add_synonym', [$this, 'ajax_add_synonym']);
        add_action('wp_ajax_cleversay_delete_synonym', [$this, 'ajax_delete_synonym']);
        add_action('wp_ajax_cleversay_toggle_synonym', [$this, 'ajax_toggle_synonym']);
        add_action('wp_ajax_cleversay_resolve_inquiry', [$this, 'ajax_resolve_inquiry']);
        add_action('wp_ajax_cleversay_test_search', [$this, 'ajax_test_search']);
        add_action('wp_ajax_cleversay_export_data', [$this, 'ajax_export_data']);
        add_action('wp_ajax_cleversay_import_data', [$this, 'ajax_import_data']);
        add_action('wp_ajax_cleversay_preview_import',   [$this, 'ajax_preview_import']);
        // AI sources
        add_action('wp_ajax_cleversay_add_source_url',    [$this, 'ajax_add_source_url']);
        add_action('wp_ajax_cleversay_add_source_file',   [$this, 'ajax_add_source_file']);
        add_action('wp_ajax_cleversay_add_source_text',   [$this, 'ajax_add_source_text']);
        add_action('wp_ajax_cleversay_delete_source',          [$this, 'ajax_delete_source']);
        add_action('wp_ajax_cleversay_bulk_delete_sources',    [$this, 'ajax_bulk_delete_sources']);
        add_action('wp_ajax_cleversay_reindex_source',    [$this, 'ajax_reindex_source']);
        add_action('wp_ajax_cleversay_set_source_refresh', [$this, 'ajax_set_source_refresh_interval']);
        add_action('wp_ajax_cleversay_get_source_status', [$this, 'ajax_get_source_status']);
        add_action('wp_ajax_cleversay_test_api_key',         [$this, 'ajax_test_api_key']);
        add_action('wp_ajax_cleversay_test_stored_api_key',  [$this, 'ajax_test_stored_api_key']);
        add_action('wp_ajax_cleversay_ai_diagnostic',         [$this, 'ajax_ai_diagnostic']);
        // URL extraction diagnostic — surface what the indexer sees so admins
        // can debug "URL added but no words indexed" scenarios.
        add_action('wp_ajax_cleversay_diagnose_url',          [$this, 'ajax_diagnose_url']);
        // A/B test harness
        add_action('wp_ajax_cleversay_ab_run_question',  [$this, 'ajax_ab_run_question']);
        add_action('wp_ajax_cleversay_ab_save_questions', [$this, 'ajax_ab_save_questions']);
        add_action('wp_ajax_cleversay_promote_ai_answer',     [$this, 'ajax_promote_ai_answer']);
        add_action('wp_ajax_cleversay_reject_ai_answer',      [$this, 'ajax_reject_ai_answer']);
        add_action('wp_ajax_cleversay_get_kb_keywords',       [$this, 'ajax_get_kb_keywords']);
        add_action('wp_ajax_cleversay_get_kb_subkeywords',    [$this, 'ajax_get_kb_subkeywords']);
        add_action('wp_ajax_cleversay_ai_suggest_promote',   [$this, 'ajax_ai_suggest_promote']);
        add_action('wp_ajax_cleversay_crawl_discover',        [$this, 'ajax_crawl_discover']);
        add_action('wp_ajax_cleversay_crawl_discover_next',    [$this, 'ajax_crawl_discover_next']);
        add_action('wp_ajax_cleversay_crawl_index_next',       [$this, 'ajax_crawl_index_next']);
        add_action('wp_ajax_cleversay_get_logs', [$this, 'ajax_get_logs']);
        add_action('wp_ajax_cleversay_delete_keyword', [$this, 'ajax_delete_keyword']);
        add_action('wp_ajax_cleversay_validate_pattern', [$this, 'ajax_validate_pattern']);
        add_action('wp_ajax_cleversay_delete_phrase_group', [$this, 'ajax_delete_phrase_group']);
        add_action('wp_ajax_cleversay_get_response_preview', [$this, 'ajax_get_response_preview']);
        add_action('wp_ajax_cleversay_save_keyword_synonyms', [$this, 'ajax_save_keyword_synonyms']);

        // KB variation editor (v4.31.0+)
        add_action('wp_ajax_cleversay_compile_pattern',           [$this, 'ajax_compile_pattern']);
        add_action('wp_ajax_cleversay_suggest_keyword',           [$this, 'ajax_suggest_keyword']);
        add_action('wp_ajax_cleversay_pattern_trace',             [$this, 'ajax_pattern_trace']);
        add_action('wp_ajax_cleversay_recompile_dryrun_chunk',    [$this, 'ajax_recompile_dryrun_chunk']);
        add_action('wp_ajax_cleversay_recompile_apply',           [$this, 'ajax_recompile_apply']);
        add_action('wp_ajax_cleversay_modernize_response',        [$this, 'ajax_modernize_response']);
        add_action('wp_ajax_cleversay_polish_preview',            [$this, 'ajax_polish_preview']);
        add_action('wp_ajax_cleversay_polish_apply',              [$this, 'ajax_polish_apply']);
        add_action('wp_ajax_cleversay_polish_diagnose',           [$this, 'ajax_polish_diagnose']);
        add_action('wp_ajax_cleversay_reuse_repair_apply',        [$this, 'ajax_reuse_repair_apply']);
        add_action('wp_ajax_cleversay_ai_suggest_variations',     [$this, 'ajax_ai_suggest_variations']);
    }
    
    /**
     * Add admin menu items
     */
    /**
     * Add body class on all CleverSay admin pages so we can scope CSS
     * to our pages only (e.g. #wpcontent background, menu arrow colour).
     */
    public function add_body_class(string $classes): string {
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'cleversay') !== false) {
            $classes .= ' cleversay-page';
        }
        return $classes;
    }

    public function add_admin_menu(): void {
        // In Multisite, non-super-admins (clients) see a restricted menu.
        // Super admins and single-site installs see everything.
        $is_client = is_multisite() && !is_super_admin();

        // Main menu
        add_menu_page(
            __('CleverSay', 'cleversay'),
            __('CleverSay', 'cleversay'),
            'manage_options',
            'cleversay',
            [$this, 'render_dashboard'],
            'dashicons-format-chat',
            30
        );
        
        // Dashboard (must match the main menu slug)
        add_submenu_page(
            'cleversay',
            __('Dashboard', 'cleversay'),
            __('Dashboard', 'cleversay'),
            'manage_options',
            'cleversay',
            [$this, 'render_dashboard']
        );

        // Knowledge Base
        add_submenu_page(
            'cleversay',
            __('Knowledge Base', 'cleversay'),
            __('Knowledge Base', 'cleversay'),
            'manage_options',
            'cleversay-knowledge',
            [$this, 'render_knowledge_base']
        );

        // Ask Question — test tool used alongside the KB
        add_submenu_page(
            'cleversay',
            __('Ask Question', 'cleversay'),
            __('Ask Question', 'cleversay'),
            'manage_options',
            'cleversay-ask',
            [$this, 'render_ask_question']
        );

        // Synonyms
        add_submenu_page(
            'cleversay',
            __('Synonyms', 'cleversay'),
            __('Synonyms', 'cleversay'),
            'manage_options',
            'cleversay-synonyms',
            [$this, 'render_synonyms']
        );

        // Inquiries
        add_submenu_page(
            'cleversay',
            __('Inquiries', 'cleversay'),
            __('Inquiries', 'cleversay'),
            'manage_options',
            'cleversay-inquiries',
            [$this, 'render_inquiries']
        );

        // Settings — clients see a limited version (no AI tab, no Advanced tab)
        add_submenu_page(
            'cleversay',
            __('Settings', 'cleversay'),
            __('Settings', 'cleversay'),
            'manage_options',
            'cleversay-settings',
            [$this, 'render_settings']
        );

        // AI Answers review — clients can see this (review AI responses)
        add_submenu_page(
            'cleversay',
            __('AI Answers', 'cleversay'),
            $this->get_ai_answers_menu_label(),
            'manage_options',
            'cleversay-ai-answers',
            [$this, 'render_ai_answers']
        );

        // v4.37.50+: AI Decisions — observability for tiebreak and KB
        // validation events. Admins use this to see how often AI is
        // intervening, which entries are getting rejected, and which
        // ties are being resolved. Visible to clients (their own data).
        add_submenu_page(
            'cleversay',
            __('AI Decisions', 'cleversay'),
            __('AI Decisions', 'cleversay'),
            'manage_options',
            'cleversay-ai-decisions',
            [$this, 'render_ai_decisions']
        );

        // AI Sources — hidden from clients in Multisite (managed at network level)
        if (!$is_client) {
            add_submenu_page(
                'cleversay',
                __('AI Knowledge Sources', 'cleversay'),
                __('AI Sources', 'cleversay'),
                'manage_options',
                'cleversay-ai-sources',
                [$this, 'render_ai_sources']
            );
        }

        // v4.37.104+: Snippet diagnostic — registered for any admin
        // (manage_options capability), not gated to super-admins.
        // Earlier the !$is_client gate meant per-site admins on
        // multisite couldn't access the URL even though they have
        // the underlying capability. The diagnostic is internal-only
        // (no menu entry) so any admin who knows the URL can run it.
        // Keeping behind manage_options is sufficient — same level
        // as the rest of the plugin's admin views.
        add_submenu_page(
            'cleversay',
            __('Snippet Diagnostic', 'cleversay'),
            __('Snippet Diagnostic', 'cleversay'),
            'manage_options',
            'cleversay-snippet-diag',
            [$this, 'render_snippet_diagnostic']
        );

        // Reports
        add_submenu_page(
            'cleversay',
            __('Reports', 'cleversay'),
            __('Reports', 'cleversay'),
            'manage_options',
            'cleversay-reports',
            [$this, 'render_reports']
        );

        // Questions Log
        add_submenu_page(
            'cleversay',
            __('Questions Log', 'cleversay'),
            __('Questions Log', 'cleversay'),
            'manage_options',
            'cleversay-questions',
            [$this, 'render_questions_log']
        );

        // Client Handoff Document — auto-generates a delivery doc with embed
        // code, configuration summary, and login info to share with the client.
        add_submenu_page(
            'cleversay',
            __('Handoff Document', 'cleversay'),
            __('Handoff Document', 'cleversay'),
            'manage_options',
            'cleversay-handoff',
            [$this, 'render_handoff']
        );

        // Captured leads from pre-chat lead-capture form
        add_submenu_page(
            'cleversay',
            __('Leads', 'cleversay'),
            __('Leads', 'cleversay'),
            'manage_options',
            'cleversay-leads',
            [$this, 'render_leads']
        );

        // AI A/B Test harness — run a fixed question set against the current
        // model, save results, compare across model switches over time.
        add_submenu_page(
            'cleversay',
            __('A/B Test', 'cleversay'),
            __('A/B Test', 'cleversay'),
            'manage_options',
            'cleversay-ab-test',
            [$this, 'render_ab_test']
        );

        // Eval Harness — runs every variation in the KB through the
        // matcher to measure recall@1: did each variation match its
        // own entry? Surfaces specific failures so admins can fix
        // patterns or add variations. Different from A/B Test (which
        // compares AI models on a fixed question list with no ground
        // truth) — this has ground truth from the variations table.
        add_submenu_page(
            'cleversay',
            __('Eval', 'cleversay'),
            __('Eval', 'cleversay'),
            'manage_options',
            'cleversay-eval',
            [$this, 'render_eval']
        );

        // AI Inspector — diagnostic view of the exact prompt, retrieved
        // chunks, history, and raw response for AI calls. Super-admin only
        // on multisite (prompt internals are not for clients).
        if (!$is_client) {
            add_submenu_page(
                'cleversay',
                __('AI Inspector', 'cleversay'),
                __('AI Inspector', 'cleversay'),
                'manage_options',
                'cleversay-ai-inspector',
                [$this, 'render_ai_inspector']
            );
        }

        // Import/Export
        add_submenu_page(
            'cleversay',
            __('Import/Export', 'cleversay'),
            __('Import/Export', 'cleversay'),
            'manage_options',
            'cleversay-import-export',
            [$this, 'render_import_export']
        );

        // Migration Analysis — dry-run report showing which legacy phrase
        // groups would migrate cleanly to the variations system and which
        // need attention. Read-only; writes nothing. Phase A of the
        // legacy-removal project. Hidden from clients (operator tool).
        if (!$is_client) {
            add_submenu_page(
                'cleversay',
                __('Migration Analysis', 'cleversay'),
                __('Migration Analysis', 'cleversay'),
                'manage_options',
                'cleversay-migration',
                [$this, 'render_migration']
            );
        }

        // v4.37.51+: Recompile All — sweeps the KB and re-runs the
        // pattern compiler against current variations + siblings.
        // Catches entries whose stored patterns are stale because
        // they were compiled under earlier compiler logic (before
        // qualifying-determiner boost, soft-pair fallback, etc).
        // Dry-run-only by default; admin reviews report and
        // explicitly clicks Apply to write changes.
        if (!$is_client) {
            add_submenu_page(
                'cleversay',
                __('Recompile Patterns', 'cleversay'),
                __('Recompile Patterns', 'cleversay'),
                'manage_options',
                'cleversay-recompile',
                [$this, 'render_recompile']
            );
        }

        // v4.37.55+: Reuse Repair — finds Reuse Response references
        // whose target pattern no longer exists (orphaned by
        // pre-cascade pattern edits) and lets admin auto-repair
        // when a clear winner exists or manually pick a target
        // when ambiguous.
        if (!$is_client) {
            add_submenu_page(
                'cleversay',
                __('Reuse Repair', 'cleversay'),
                __('Reuse Repair', 'cleversay'),
                'manage_options',
                'cleversay-reuse-repair',
                [$this, 'render_reuse_repair']
            );
        }

        // Debug Log — hidden from clients
        if (!$is_client) {
            add_submenu_page(
                'cleversay',
                __('Debug Log', 'cleversay'),
                __('Debug Log', 'cleversay'),
                'manage_options',
                'cleversay-debug-log',
                [$this, 'render_debug_log']
            );
        }

        // v4.37.105+: Snippet diagnostic stays visible in the menu.
        // Earlier we tried to remove the menu entry via
        // remove_submenu_page after registration to hide it, but that
        // call ALSO unregisters the page in some WP versions, blocking
        // direct-URL access entirely. For an actively-used debug tool
        // it's fine for it to appear in the menu — admins can ignore
        // it. We can hide it when we're done debugging.
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_assets(string $hook): void {
        // Only load on our plugin pages
        if (strpos($hook, 'cleversay') === false) {
            return;
        }

        // Outfit font — for the bold flat design system
        $font_family = function_exists('is_multisite') && is_multisite()
            ? get_site_option('cleversay_admin_font_family', 'Outfit')
            : get_option('cleversay_admin_font_family', 'Outfit');
        $font_size = function_exists('is_multisite') && is_multisite()
            ? (int) get_site_option('cleversay_admin_font_size', 14)
            : (int) get_option('cleversay_admin_font_size', 14);
        $font_url = \CleverSay\NetworkAdmin::get_google_font_url($font_family);

        wp_enqueue_style(
            'cleversay-font-outfit',
            $font_url,
            [],
            null
        );

        // CSS
        wp_enqueue_style(
            'cleversay-admin',
            CLEVERSAY_PLUGIN_URL . 'admin/css/admin.css',
            ['cleversay-font-outfit'],
            CLEVERSAY_VERSION
        );

        // Apply font family + size as inline style. Specific selectors and !important
        // to override both admin.css defaults and WordPress admin styles.
        $fallback = ', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        $inline = "
            :root { --cs-font-family: '{$font_family}'{$fallback}; --cs-font-size-base: {$font_size}px; }
            .cleversay-admin, .wrap.cleversay-admin {
                font-family: var(--cs-font-family) !important;
                font-size: {$font_size}px !important;
            }
            .cleversay-admin p, .wrap.cleversay-admin p,
            .cleversay-admin td, .wrap.cleversay-admin td,
            .cleversay-admin th, .wrap.cleversay-admin th,
            .cleversay-admin li, .wrap.cleversay-admin li,
            .cleversay-admin label, .wrap.cleversay-admin label,
            .cleversay-admin span:not(.cs-icon):not(.stat-value):not(.stat-number):not(.stat-label):not(.cleversay-badge), 
            .wrap.cleversay-admin span:not(.cs-icon):not(.stat-value):not(.stat-number):not(.stat-label):not(.cleversay-badge) {
                font-size: {$font_size}px !important;
            }
            .cleversay-admin p.description, .wrap.cleversay-admin p.description {
                font-size: " . max(11, $font_size - 1) . "px !important;
            }
            .cleversay-admin input[type=\"text\"], .cleversay-admin input[type=\"email\"], .cleversay-admin input[type=\"url\"], 
            .cleversay-admin input[type=\"number\"], .cleversay-admin input[type=\"password\"], .cleversay-admin input[type=\"search\"], 
            .cleversay-admin textarea, .cleversay-admin select,
            .wrap.cleversay-admin input[type=\"text\"], .wrap.cleversay-admin input[type=\"email\"], .wrap.cleversay-admin input[type=\"url\"],
            .wrap.cleversay-admin input[type=\"number\"], .wrap.cleversay-admin input[type=\"password\"], .wrap.cleversay-admin input[type=\"search\"],
            .wrap.cleversay-admin textarea, .wrap.cleversay-admin select {
                font-family: var(--cs-font-family) !important;
                font-size: {$font_size}px !important;
            }
            .cleversay-admin .button, .wrap.cleversay-admin .button,
            .cleversay-admin .cleversay-btn, .wrap.cleversay-admin .cleversay-btn {
                font-family: var(--cs-font-family) !important;
                font-size: " . max(12, $font_size - 1) . "px !important;
            }
            .cleversay-admin h1, .wrap.cleversay-admin h1,
            .cleversay-admin h2, .wrap.cleversay-admin h2,
            .cleversay-admin h3, .wrap.cleversay-admin h3,
            .cleversay-admin h4, .wrap.cleversay-admin h4 {
                font-family: var(--cs-font-family) !important;
            }
        ";
        wp_add_inline_style('cleversay-admin', $inline);

        // Custom overrides (network-wide) — load after core CSS so they win without !important
        if (function_exists('is_multisite') && is_multisite()) {
            $custom_css = get_site_option('cleversay_custom_admin_css', '');
            if (!empty($custom_css)) {
                wp_add_inline_style('cleversay-admin', $custom_css);
            }
        }
        
        // Chart.js for reports
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );
        
        // Admin JS
        wp_enqueue_script(
            'cleversay-admin',
            CLEVERSAY_PLUGIN_URL . 'admin/js/admin.js',
            ['jquery', 'chartjs'],
            CLEVERSAY_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('cleversay-admin', 'cleversayAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'adminUrl' => admin_url(),
            'nonce' => wp_create_nonce('cleversay_nonce'),
            'strings' => [
                'confirmDelete' => __('Are you sure you want to delete this item?', 'cleversay'),
                'confirmBulkDelete' => __('Are you sure you want to delete the selected items?', 'cleversay'),
                'confirmDeleteGroup' => __('Delete this response group and all its patterns?', 'cleversay'),
                'confirmDeleteKeyword' => __('Delete this keyword and ALL its patterns and responses? This cannot be undone.', 'cleversay'),
                'saving' => __('Saving...', 'cleversay'),
                'saved' => __('Saved!', 'cleversay'),
                'error' => __('An error occurred. Please try again.', 'cleversay'),
                'validating' => __('Validating...', 'cleversay'),
                'patternRequired' => __('Pattern is required', 'cleversay'),
                'phraseRequired' => __('Phrase is required', 'cleversay'),
            ],
        ]);
        
        // WordPress media uploader
        wp_enqueue_media();
        
        // WordPress color picker
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }
    
    /**
     * Register settings
     */
    public function register_settings(): void {
        // Widget settings
        register_setting('cleversay_settings', 'cleversay_widget_enabled');
        register_setting('cleversay_settings', 'cleversay_widget_position');
        register_setting('cleversay_settings', 'cleversay_widget_title');
        register_setting('cleversay_settings', 'cleversay_widget_placeholder');
        
        // Appearance settings
        register_setting('cleversay_settings', 'cleversay_primary_color');
        register_setting('cleversay_settings', 'cleversay_secondary_color');
        
        // Search settings
        register_setting('cleversay_settings', 'cleversay_show_rating');
        register_setting('cleversay_settings', 'cleversay_enable_spellcheck');
        register_setting('cleversay_settings', 'cleversay_min_match_score');
        register_setting('cleversay_settings', 'cleversay_max_results');
        
        // Inquiry settings
        register_setting('cleversay_settings', 'cleversay_enable_inquiry_form');
        register_setting('cleversay_settings', 'cleversay_inquiry_email');
        register_setting('cleversay_settings', 'cleversay_no_answer_message');
        
        // Analytics settings
        register_setting('cleversay_settings', 'cleversay_enable_analytics');
        
        // Data settings
        register_setting('cleversay_settings', 'cleversay_delete_data_on_uninstall');
    }
    
    /**
     * Render dashboard page
     */
    public function render_dashboard(): void {
        global $wpdb;
        
        // Get stats
        $stats = [
            'total_entries' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->db->knowledge_base}"),
            'active_entries' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->db->knowledge_base} WHERE status = 'active'"),
            'total_questions' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->db->questions_log}"),
            'questions_today' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->db->questions_log} WHERE DATE(created_at) = CURDATE()"),
            'questions_week' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->db->questions_log} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
            'unanswered' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->db->questions_log} WHERE match_type = 'none'"),
            'pending_inquiries' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->db->inquiries} WHERE status = 'pending'"),
            'unique_visitors' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->db->visitors}"),
        ];
        
        // Get recent questions
        $recent_questions = $wpdb->get_results(
            "SELECT question, match_type, created_at FROM {$this->db->questions_log} 
             ORDER BY created_at DESC LIMIT 10",
            ARRAY_A
        );
        
        // Get top keywords
        $top_keywords = $wpdb->get_results(
            "SELECT keyword, hits, helpful_yes, helpful_no 
             FROM {$this->db->knowledge_base} 
             WHERE status = 'active' 
             ORDER BY hits DESC LIMIT 10",
            ARRAY_A
        );
        
        // Get questions by day for chart
        $questions_by_day = $wpdb->get_results(
            "SELECT DATE(created_at) as date, COUNT(*) as count 
             FROM {$this->db->questions_log} 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(created_at) 
             ORDER BY date",
            ARRAY_A
        );
        
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/dashboard.php';
    }
    
    /**
     * Render knowledge base page
     */
    public function render_knowledge_base(): void {
        // The new grouped list view handles all routing internally
        // (list, new-keyword, edit-keyword actions)
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/knowledge-list-new.php';
    }
    
    /**
     * Render categories page
     */
    /**
     * Render synonyms page
     */
    public function render_synonyms(): void {
        global $wpdb;
        
        $page = absint($_GET['paged'] ?? 1);
        $per_page = 25;
        $offset = ($page - 1) * $per_page;
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->db->synonyms}");
        $synonyms = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->db->synonyms} ORDER BY canonical_word LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A);
        
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/synonyms.php';
    }
    
    /**
     * Render questions log page
     */
    public function render_questions_log(): void {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/questions.php';
    }

    /**
     * Render the Client Handoff Document page — a clean delivery view that
     * super-admins can use to package up everything a client (and their IT
     * team) needs to embed and manage the bot. Also visible to per-site
     * admins so clients can pull it up themselves to share with their team.
     */
    public function render_handoff(): void {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/handoff.php';
    }

    /**
     * Render the AI A/B Test harness page. Lets admins run a fixed list of
     * test questions against the current AI model, save the results, and
     * compare answers across runs (useful when switching models to evaluate
     * quality differences on the same content).
     */
    public function render_ab_test(): void {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/ab-test.php';
    }

    /**
     * Render the Eval Harness page. Display-only; the actual run
     * happens via AJAX in batches (see ajax_run_eval).
     */
    public function render_eval(): void {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/eval-harness.php';
    }

    /**
     * Run a batch of the variations-based eval and return per-row
     * results plus running totals. The client (eval-harness.php JS)
     * calls this repeatedly with increasing offsets until done=true,
     * accumulating results client-side. The full run is also
     * persisted to an option on completion so the admin can see
     * "last run" stats on next page load.
     *
     * Why batched: 650+ variations × ~20-50ms per test_search() is
     * 15-30s of work, plus internal logging the matcher does on each
     * call. A single synchronous request risks PHP/proxy timeouts.
     * Batching also gives the admin live progress as it runs.
     *
     * Why test_search vs search: test_search bypasses the
     * cleversay_questions logging table and visitor stats, so eval
     * runs don't pollute analytics. Same matching logic otherwise.
     */
    public function ajax_run_eval(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }

        @set_time_limit(120);

        $offset    = max(0, (int) ($_POST['offset'] ?? 0));
        $batch     = max(1, min(100, (int) ($_POST['batch'] ?? 50)));
        $is_first  = $offset === 0;

        global $wpdb;
        $kb_table   = $wpdb->prefix . 'cleversay_knowledge';
        $vars_table = $wpdb->prefix . 'cleversay_kb_variations';

        // Pull total count once on the first batch — the client
        // shows it as the progress denominator.
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$vars_table} v
             INNER JOIN {$kb_table} k ON k.id = v.knowledge_id
             WHERE k.status = 'active'"
        );

        if ($total === 0) {
            wp_send_json_success([
                'done'    => true,
                'total'   => 0,
                'offset'  => 0,
                'results' => [],
                'message' => __('No active variations found. Make sure the KB has been migrated and entries are active.', 'cleversay'),
            ]);
        }

        // Pull this batch.
        $variations = $wpdb->get_results($wpdb->prepare(
            "SELECT v.id, v.knowledge_id, v.variation_text,
                    k.keyword, k.sub_keyword
               FROM {$vars_table} v
               INNER JOIN {$kb_table} k ON k.id = v.knowledge_id
              WHERE k.status = 'active'
              ORDER BY v.id ASC
              LIMIT %d OFFSET %d",
            $batch,
            $offset
        ), ARRAY_A);

        $search = new \CleverSay\Search();
        $rows   = [];

        foreach ($variations as $v) {
            $expected_id = (int) $v['knowledge_id'];
            $query       = (string) $v['variation_text'];

            $t0 = microtime(true);
            try {
                $r = $search->test_search($query);
            } catch (\Throwable $e) {
                $r = ['matches' => []];
            }
            $latency_ms = (int) round((microtime(true) - $t0) * 1000);

            $matches    = $r['matches'] ?? [];
            $top        = !empty($matches) ? $matches[0] : null;
            $matched_id = $top ? (int) ($top['id'] ?? 0) : 0;
            $matched_sub = $top ? strtolower(trim((string) ($top['sub_keyword'] ?? ''))) : '';

            if ($top === null) {
                $bucket = 'no_match';
            } elseif ($matched_id === $expected_id) {
                $bucket = 'correct';
            } elseif ($matched_sub === 'aadefault') {
                $bucket = 'aadefault_fallback';
            } else {
                $bucket = 'wrong_entry';
            }

            $rows[] = [
                'variation_id'         => (int) $v['id'],
                'query'                => $query,
                'expected_id'          => $expected_id,
                'expected_keyword'     => (string) $v['keyword'],
                'expected_sub_keyword' => (string) $v['sub_keyword'],
                'matched_id'           => $matched_id,
                'matched_keyword'      => $top ? (string) ($top['keyword'] ?? '') : '',
                'matched_sub_keyword'  => $top ? (string) ($top['sub_keyword'] ?? '') : '',
                'matched_score'        => $top ? (int) ($top['score'] ?? 0) : 0,
                'latency_ms'           => $latency_ms,
                'bucket'               => $bucket,
            ];
        }

        $next_offset = $offset + count($variations);
        $done        = $next_offset >= $total;

        wp_send_json_success([
            'done'      => $done,
            'total'     => $total,
            'offset'    => $next_offset,
            'results'   => $rows,
        ]);
    }

    /**
     * Persist the final aggregated stats from a completed eval run.
     * Called once by the JS after all batches finish. Stored as a
     * single option so the admin can see "last run" stats on
     * page load without recomputing.
     */
    public function ajax_save_eval_run(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }

        $stats_raw = $_POST['stats'] ?? '';
        if (is_string($stats_raw)) {
            $stats = json_decode(stripslashes($stats_raw), true);
        } else {
            $stats = null;
        }

        if (!is_array($stats)) {
            wp_send_json_error(['message' => __('Invalid stats payload', 'cleversay')], 400);
        }

        // Trim failures list — don't bloat the option with the full
        // failure dump. Keep top 200 for review; admin can re-run if
        // they want the full set.
        if (isset($stats['failures']) && is_array($stats['failures'])) {
            $stats['failures'] = array_slice($stats['failures'], 0, 200);
        }
        $note = isset($_POST['note']) ? sanitize_text_field(wp_unslash((string) $_POST['note'])) : '';
        $stats['saved_at']      = current_time('mysql');
        $stats['plugin_version'] = defined('CLEVERSAY_VERSION') ? CLEVERSAY_VERSION : '';
        $stats['note']          = $note;
        $stats['run_id']        = wp_generate_uuid4();

        // v4.37.5+: keep history rather than overwriting. The "last run"
        // option remains for the simple top-of-page summary on the
        // eval-harness view; the new history option holds up to 20
        // recent runs so admins can compare across changes. Limit
        // failures stored per historical entry to top 50 to keep the
        // option size manageable (option storage is a single row).
        update_option('cleversay_last_eval_run', $stats, false);

        $history = get_option('cleversay_eval_run_history', []);
        if (!is_array($history)) $history = [];

        $compact = $stats;
        if (isset($compact['failures']) && is_array($compact['failures'])) {
            $compact['failures'] = array_slice($compact['failures'], 0, 50);
        }
        array_unshift($history, $compact);
        $history = array_slice($history, 0, 20);

        update_option('cleversay_eval_run_history', $history, false);

        wp_send_json_success([
            'saved_at' => $stats['saved_at'],
            'run_id'   => $stats['run_id'],
        ]);
    }

    /**
     * Delete a single eval-run from history.
     */
    public function ajax_delete_eval_run(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }

        $run_id = sanitize_text_field(wp_unslash((string) ($_POST['run_id'] ?? '')));
        if ($run_id === '') {
            wp_send_json_error(['message' => __('Missing run_id', 'cleversay')], 400);
        }

        $history = get_option('cleversay_eval_run_history', []);
        if (!is_array($history)) $history = [];

        $history = array_values(array_filter($history, static function($r) use ($run_id) {
            return ($r['run_id'] ?? '') !== $run_id;
        }));

        update_option('cleversay_eval_run_history', $history, false);
        wp_send_json_success(['remaining' => count($history)]);
    }

    /**
     * Handle Inspector capture-toggle and clear-all form posts.
     * Runs on admin_init — before any output — so headers can be sent cleanly.
     * (Doing this inside render_ai_inspector caused "headers already sent"
     * because admin chrome had already started outputting by render time.)
     */
    public function handle_inspector_actions(): void {
        if (empty($_POST['cleversay_inspector_action'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!check_admin_referer('cleversay_inspector', 'cleversay_inspector_nonce')) {
            return;
        }

        if (!class_exists('\\CleverSay\\AIDebugLog')) {
            require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-ai-debug-log.php';
        }

        $action = sanitize_text_field(wp_unslash($_POST['cleversay_inspector_action']));
        if ($action === 'start_capture') {
            \CleverSay\AIDebugLog::start_capture();
        } elseif ($action === 'stop_capture') {
            \CleverSay\AIDebugLog::stop_capture();
        } elseif ($action === 'clear_all') {
            \CleverSay\AIDebugLog::clear_all();
        }

        wp_safe_redirect(add_query_arg(
            ['page' => 'cleversay-ai-inspector', 'updated' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * AI Inspector — diagnostic view showing exact prompts and chunks for
     * AI calls. Two main views:
     *   - List view: recent debug entries
     *   - Detail view: full prompt/chunks/response for one entry
     *
     * Capture is OFF by default. Admin clicks "Start Capture" to log the
     * next 50 AI calls. Negative ratings auto-flag any AI answer for
     * inspector review even when manual capture isn't on.
     */
    public function render_ai_inspector(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Forbidden', 'cleversay'));
        }

        // POST actions are handled by handle_inspector_actions on admin_init.
        // This method only renders.

        // Detail view if ?entry=NNN, else list view.
        $entry_id = isset($_GET['entry']) ? (int) $_GET['entry'] : 0;
        if ($entry_id > 0) {
            $entry = \CleverSay\AIDebugLog::get($entry_id);
            include CLEVERSAY_PLUGIN_DIR . 'admin/views/ai-inspector-detail.php';
            return;
        }

        $status      = \CleverSay\AIDebugLog::capture_status();
        $entries     = \CleverSay\AIDebugLog::get_recent(50);
        $total_count = \CleverSay\AIDebugLog::count_all();
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/ai-inspector.php';
    }

    /**
     * Run a single test question against the current AI model. Returns the
     * answer, model used, response time, and cost. The frontend calls this
     * once per question in the test set, then renders a side-by-side
     * comparison from accumulated runs in localStorage / saved option.
     */
    public function ajax_ab_run_question(): void {
        check_ajax_referer('cleversay_ab_test', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Forbidden', 'cleversay')], 403);
        }

        $question = sanitize_text_field(wp_unslash($_POST['question'] ?? ''));
        if (empty($question)) {
            wp_send_json_error(['message' => __('Empty question', 'cleversay')], 400);
        }

        if (!\CleverSay\NetworkSettings::ai_is_configured()) {
            wp_send_json_error(['message' => __('AI not configured', 'cleversay')], 400);
        }

        // Run the question through the same path the public widget uses for
        // AI fallback. We retrieve chunks, then ask the AI.
        $start = microtime(true);
        try {
            $indexer = new \CleverSay\Indexer();
            $chunks  = $indexer->find_relevant_chunks($question);
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => 'Chunk retrieval failed: ' . $e->getMessage(),
            ], 500);
        }

        try {
            $ai     = new \CleverSay\AI();
            $result = $ai->answer_with_context($question, $chunks);
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => 'AI call failed: ' . $e->getMessage(),
            ], 500);
        }
        $elapsed_ms = (int) round((microtime(true) - $start) * 1000);

        if (!empty($result['error'])) {
            wp_send_json_error(['message' => $result['error']], 500);
        }

        // Find which model was actually used — the AI class doesn't expose
        // it directly, but we can infer from per-site override OR network default.
        $override = (string) get_option('cleversay_ai_model_override', '');
        if (empty($override) && function_exists('is_multisite') && is_multisite()) {
            $override = (string) (\CleverSay\NetworkSettings::get_ai()['model'] ?? '');
        }
        if (empty($override)) {
            $override = (string) get_option('cleversay_ai_model', 'claude-haiku-4-5-20251001');
        }

        wp_send_json_success([
            'question'      => $question,
            'answer'        => $result['answer'] ?? '',
            'model'         => $override,
            'tokens_input'  => (int) ($result['tokens_input']  ?? 0),
            'tokens_output' => (int) ($result['tokens_output'] ?? 0),
            'cache_read'    => (int) ($result['cache_read']    ?? 0),
            'cache_create'  => (int) ($result['cache_create']  ?? 0),
            'cost'          => (float) ($result['cost'] ?? 0.0),
            'elapsed_ms'    => $elapsed_ms,
            'chunk_count'   => count($chunks),
            'chunk_sources' => array_unique(array_filter(array_column($chunks, 'source_title'))),
            'timestamp'     => current_time('mysql'),
        ]);
    }

    /**
     * Save the configurable test question list. Stored as a single option
     * containing array of question strings.
     */
    public function ajax_ab_save_questions(): void {
        check_ajax_referer('cleversay_ab_test', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Forbidden', 'cleversay')], 403);
        }

        $raw = (string) wp_unslash($_POST['questions'] ?? '');
        $questions = array_values(array_filter(
            array_map(function ($line) { return sanitize_text_field(trim($line)); },
                      explode("\n", $raw)),
            function ($q) { return $q !== ''; }
        ));

        // Cap at 30 to prevent runaway test runs
        $questions = array_slice($questions, 0, 30);
        update_option('cleversay_ab_test_questions', $questions, false);

        wp_send_json_success(['questions' => $questions]);
    }

    /**
     * Render Leads admin page (list view + filters + CSV export).
     */
    public function render_leads(): void {
        // Handle CSV export inline before any output (so we can set headers)
        if (isset($_GET['cs_export']) && $_GET['cs_export'] === 'csv'
            && current_user_can('manage_options')
            && check_admin_referer('cleversay_export_leads')) {
            $this->export_leads_csv();
            exit;
        }
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/leads.php';
    }

    /**
     * CSV export of captured leads. Streams the file directly without
     * rendering an admin page wrapper.
     */
    private function export_leads_csv(): void {
        global $wpdb;
        $db = new \CleverSay\Database();
        $rows = $wpdb->get_results("SELECT * FROM {$db->leads} ORDER BY created_at DESC", ARRAY_A);

        $fname = 'cleversay-leads-' . date('Y-m-d') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');

        $out = fopen('php://output', 'w');
        // BOM for Excel UTF-8 compatibility
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Date', 'First Name', 'Last Name', 'Email', 'Identity', 'Phone',
                       'Date of Birth', 'Conversation ID', 'IP', 'Referer']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['created_at'],
                $row['first_name'],
                $row['last_name'],
                $row['email'],
                $row['identity'],
                $row['phone'],
                $row['date_of_birth'],
                $row['conversation_id'],
                $row['ip_address'],
                $row['referer'],
            ]);
        }
        fclose($out);
    }
    
    /**
     * Render inquiries page
     */
    public function render_inquiries(): void {
        global $wpdb;
        
        $page = absint($_GET['paged'] ?? 1);
        $per_page = 25;
        $offset = ($page - 1) * $per_page;
        
        $status_filter = sanitize_text_field($_GET['status'] ?? '');
        
        $where = $status_filter ? $wpdb->prepare('status = %s', $status_filter) : '1=1';
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->db->inquiries} WHERE $where");
        $inquiries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->db->inquiries} WHERE $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A);
        
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/inquiries.php';
    }
    
    /**
     * Render reports page
     */
    public function render_reports(): void {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/reports.php';
    }
    
    /**
     * Render settings page
     */
    public function render_settings(): void {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/settings.php';
    }
    
    /**
     * Render import/export page
     */
    public function render_import_export(): void {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/import-export.php';
    }

    /**
     * Render the Migration Analysis page (Migration & Restoration in 4.34.0+).
     */
    public function render_migration(): void {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/migration-analysis.php';
    }

    /**
     * Render Recompile Patterns admin page.
     *
     * Scans the KB and runs the deterministic compiler against each
     * entry's variations + siblings. Produces a dry-run report
     * showing which entries would change and how. Apply is a
     * separate explicit action (re-computes on apply rather than
     * trusting stored dry-run results, in case variations changed).
     *
     * @since 4.37.51
     */
    public function render_recompile(): void {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/recompile-patterns.php';
    }

    /**
     * Render Reuse Repair admin page.
     *
     * Detects entries with broken Reuse Response references
     * (saved reuse_sub_keyword no longer matches any current
     * entry under reuse_keyword) and offers auto-repair when a
     * clear winner exists in the target keyword's current set.
     *
     * Pre-v4.37.54 pattern edits could break Reuse Response
     * silently because reuse_sub_keyword stores the target's
     * pattern as a soft FK; mutable patterns mean stale FKs.
     * v4.37.54 added cascade updates going forward; this page
     * cleans up the legacy damage.
     *
     * @since 4.37.55
     */
    public function render_reuse_repair(): void {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/reuse-repair.php';
    }

    /**
     * Detect whether the original ailiza tables are present in the
     * current WordPress database. They are *not* prefixed — the
     * jasasoft_uwsp_kb mysqldump creates them as bare `ailiza` and
     * `ailiza_rqs`. Operators run that dump through phpMyAdmin into
     * the same DB the plugin is installed in, then trigger the
     * migration once.
     *
     * Returns null if either table is missing.
     *
     * @return array{ailiza:string, rqs:string}|null
     */
    public function detect_ailiza_tables(): ?array {
        global $wpdb;
        $a = $wpdb->get_var("SHOW TABLES LIKE 'ailiza'");
        $r = $wpdb->get_var("SHOW TABLES LIKE 'ailiza_rqs'");
        if (!$a || !$r) return null;
        return ['ailiza' => $a, 'rqs' => $r];
    }

    /**
     * Build an in-memory index of ailiza data keyed by
     * (keyword, subkeyword). Each entry carries the joined `rq` text
     * and the reuse fields. Used by `apply_corrected_migration` to
     * enrich each cleversay_knowledge row with its lost variations
     * and reuse pointers.
     *
     * @return array<string, array{rq:string, reuse:string, rkey:string, rsubkey:string}>
     */
    private function build_ailiza_index(): array {
        global $wpdb;
        $rows = $wpdb->get_results("
            SELECT a.keyword, a.subkeyword, a.reuse, a.rkey, a.rsubkey, r.rq
              FROM ailiza a
         LEFT JOIN ailiza_rqs r ON a.id = r.rqid
        ", ARRAY_A);

        $idx = [];
        foreach ((array) $rows as $row) {
            // (keyword, subkeyword) is unique on the ailiza side per
            // the dump; if a duplicate ever appears, last write wins.
            $key = $row['keyword'] . '|' . $row['subkeyword'];
            $idx[$key] = [
                'rq'      => (string) ($row['rq'] ?? ''),
                'reuse'   => (string) ($row['reuse'] ?? 'no'),
                'rkey'    => (string) ($row['rkey'] ?? ''),
                'rsubkey' => (string) ($row['rsubkey'] ?? ''),
            ];
        }
        return $idx;
    }

    /**
     * Per-row migration with optional ailiza-sourced restoration
     * (4.34.0+).
     *
     * Each non-aadefault row in cleversay_knowledge IS its own phrase
     * group — full stop. Pipes inside `sub_keyword` are OR-pattern
     * syntax (matching rule), not group separators. The previous
     * v4.33.0 logic that grouped rows by shared response was wrong:
     * those rows were distinct phrase groups whose `reuse_response`
     * pointer had been lost in the original WordPress refactor years
     * ago, leaving them with placeholder responses that *looked* like
     * duplicates.
     *
     * What this method does, per row:
     *   1. Skip aadefault rows. They stay as catch-alls.
     *   2. If ailiza data is available and the row matches an ailiza
     *      row by (keyword, sub_keyword):
     *        - Pull `ailiza_rqs.rq`, split on `|`, attach each
     *          non-empty variant as a row in cleversay_kb_variations.
     *        - Copy `reuse`/`rkey`/`rsubkey` to set the row's
     *          `reuse_response`, `reuse_keyword`, `reuse_sub_keyword`.
     *   3. If no ailiza match (newer rows added post-refactor) OR the
     *      ailiza match has empty `rq`: fall back to attaching the
     *      row's existing `question` text as a single variation.
     *   4. Pattern (`sub_keyword`) is never modified. Live behavior
     *      is preserved 1:1.
     *
     * Idempotent: rows that already have entries in
     * cleversay_kb_variations are skipped — running this twice is a
     * no-op. To re-run with refreshed ailiza data, the operator must
     * first clear `cleversay_kb_variations` (rare).
     *
     * @return array{
     *   restoration_mode:bool,
     *   total_rows:int,
     *   aadefault_skipped:int,
     *   already_migrated:int,
     *   matched_with_variations:int,
     *   matched_no_variations:int,
     *   unmatched_fallback:int,
     *   variations_inserted:int,
     *   reuse_pointers_restored:int,
     *   failed:int,
     *   errors:array
     * }
     */
    public function apply_corrected_migration(): array {
        global $wpdb;
        $kb_table   = $wpdb->prefix . 'cleversay_knowledge';
        $vars_table = $wpdb->prefix . 'cleversay_kb_variations';

        $ailiza_present = $this->detect_ailiza_tables() !== null;
        $ailiza_idx     = $ailiza_present ? $this->build_ailiza_index() : [];

        $rows = $wpdb->get_results(
            "SELECT id, keyword, sub_keyword, question, reuse_response
               FROM {$kb_table}
              ORDER BY id ASC",
            ARRAY_A
        );

        $rows_with_vars = $wpdb->get_col("SELECT DISTINCT knowledge_id FROM {$vars_table}");
        $has_variations = array_fill_keys(array_map('intval', (array) $rows_with_vars), true);

        $stats = [
            'restoration_mode'        => $ailiza_present,
            'total_rows'              => count($rows),
            'aadefault_skipped'       => 0,
            'already_migrated'        => 0,
            'matched_with_variations' => 0,
            'matched_no_variations'   => 0,
            'unmatched_fallback'      => 0,
            'variations_inserted'     => 0,
            'reuse_pointers_restored' => 0,
            'failed'                  => 0,
            'errors'                  => [],
        ];

        foreach ($rows as $r) {
            $id  = (int) $r['id'];
            $sub = strtolower(trim((string) $r['sub_keyword']));

            // aadefault stays untouched.
            if ($sub === 'aadefault' || $sub === '') {
                $stats['aadefault_skipped']++;
                continue;
            }

            // v4.36.1+: Two independent concerns. A row may already have
            // variations attached (from an earlier migration run) and yet
            // still be missing its reuse pointer (because the earlier run
            // ran in fallback mode without ailiza tables, or because of
            // the v4.33.0→v4.34.0 transition where the corrected migration
            // short-circuited on rows that v4.33.0 had already populated).
            // Track the two states separately so we can do whichever
            // subset of work is still needed.
            $row_has_variations    = isset($has_variations[$id]);
            $row_has_reuse_pointer = !empty($r['reuse_response']);

            // Decide variation source and reuse-pointer intent from
            // ailiza. Always evaluated — the previous version short-
            // circuited here on $row_has_variations and missed reuse
            // pointers for already-migrated rows.
            $variations         = [];
            $apply_reuse        = false;
            $reuse_keyword      = '';
            $reuse_sub_keyword  = '';
            $is_matched         = false;

            if ($ailiza_present) {
                $key = $r['keyword'] . '|' . $r['sub_keyword'];
                if (isset($ailiza_idx[$key])) {
                    $is_matched = true;
                    $a = $ailiza_idx[$key];

                    // Variations from ailiza_rqs.rq (split on `|`).
                    if ($a['rq'] !== '') {
                        $parts = explode('|', $a['rq']);
                        $seen  = [];
                        foreach ($parts as $p) {
                            $p = trim($p);
                            if ($p !== '' && strlen($p) >= 3 && !isset($seen[$p])) {
                                $seen[$p] = true;
                                $variations[] = $p;
                            }
                        }
                    }

                    // Reuse fields. Only restore when reuse=yes AND
                    // rkey is non-empty (defensive — some ailiza rows
                    // have reuse=yes with empty rkey, which would
                    // produce broken pointers).
                    if ($a['reuse'] === 'yes' && $a['rkey'] !== '') {
                        $apply_reuse       = true;
                        $reuse_keyword     = $a['rkey'];
                        $reuse_sub_keyword = $a['rsubkey'] !== '' ? $a['rsubkey'] : 'aadefault';
                    }
                }
            }

            // Fallback: use the existing question column as a single
            // variation. This applies for unmatched rows AND for
            // matched rows whose ailiza_rqs entry was empty.
            if (empty($variations)) {
                $q = trim((string) ($r['question'] ?? ''));
                if ($q !== '' && strlen($q) >= 3) {
                    $variations[] = $q;
                }
            }

            // Bookkeeping (only count first-time touches).
            if (!$row_has_variations) {
                if ($is_matched) {
                    if (count($variations) > 0 && $ailiza_idx[$r['keyword'].'|'.$r['sub_keyword']]['rq'] !== '') {
                        $stats['matched_with_variations']++;
                    } else {
                        $stats['matched_no_variations']++;
                    }
                } else {
                    $stats['unmatched_fallback']++;
                }
            }

            // Decide what work to do for this row.
            $do_variations = !$row_has_variations && !empty($variations);
            $do_reuse      = $apply_reuse && !$row_has_reuse_pointer;

            if (!$do_variations && !$do_reuse) {
                $stats['already_migrated']++;
                continue;
            }

            // Per-row transaction: a single row failure shouldn't
            // sink the rest of the batch.
            $wpdb->query('START TRANSACTION');
            try {
                // Attach variations (only if not already attached).
                if ($do_variations) {
                    foreach ($variations as $v) {
                        $wpdb->insert(
                            $vars_table,
                            [
                                'knowledge_id'   => $id,
                                'variation_text' => $v,
                            ],
                            ['%d', '%s']
                        );
                        $stats['variations_inserted']++;
                    }
                }

                // Restore reuse fields (only if missing).
                if ($do_reuse) {
                    $wpdb->update(
                        $kb_table,
                        [
                            'reuse_response'    => 1,
                            'reuse_keyword'     => $reuse_keyword,
                            'reuse_sub_keyword' => $reuse_sub_keyword,
                        ],
                        ['id' => $id],
                        ['%d', '%s', '%s'],
                        ['%d']
                    );
                    $stats['reuse_pointers_restored']++;
                }

                $wpdb->query('COMMIT');
            } catch (\Throwable $e) {
                $wpdb->query('ROLLBACK');
                $stats['failed']++;
                $stats['errors'][] = sprintf(
                    'Row id=%d (%s/%s): %s',
                    $id, $r['keyword'], $r['sub_keyword'], $e->getMessage()
                );
            }
        }

        // Flush search cache so the live matcher picks up the new
        // reuse pointers immediately.
        $this->flush_search_cache();

        return $stats;
    }

    /**
     * Handler for "Apply Migration" form POST from the Migration page.
     * Runs `apply_corrected_migration` and redirects back with a
     * transient holding the result stats.
     */
    public function handle_apply_migration(): void {
        check_admin_referer('cleversay_apply_migration');
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'cleversay'), '', ['response' => 403]);
        }

        $stats = $this->apply_corrected_migration();
        set_transient('cleversay_migration_stats', $stats, 60);

        wp_redirect(admin_url('admin.php?page=cleversay-migration&migrated=1'));
        exit;
    }

    /**
     * Restore reuse pointers from ailiza without touching anything else.
     *
     * Use case: a v4.33.0→v4.34.0 user whose corrected migration
     * short-circuited on the idempotency check (variations were
     * already attached, so reuse-pointer restoration was skipped).
     * The 4.36.1 migration refactor fixed the logic going forward,
     * but existing data still needs a one-shot fix. This method does
     * exactly that — it re-evaluates every non-aadefault row against
     * ailiza and sets reuse_response / reuse_keyword /
     * reuse_sub_keyword for any row where ailiza says reuse=yes
     * but the cleversay_knowledge row is unset.
     *
     * Idempotent. Safe to re-run. Touches reuse fields only —
     * variations, response, status, etc. are never modified.
     *
     * Requires the ailiza tables to be present in the WP database
     * (same as the main restoration migration).
     *
     * @return array{
     *   ailiza_present: bool,
     *   total_rows: int,
     *   already_set: int,
     *   restored: int,
     *   no_ailiza_match: int,
     *   ailiza_says_no_reuse: int,
     *   failed: int,
     *   errors: string[],
     * }
     */
    public function restore_reuse_pointers_only(): array {
        global $wpdb;
        $kb_table = $wpdb->prefix . 'cleversay_knowledge';

        $stats = [
            'ailiza_present'        => false,
            'total_rows'            => 0,
            'already_set'           => 0,
            'restored'              => 0,
            'no_ailiza_match'       => 0,
            'ailiza_says_no_reuse'  => 0,
            'failed'                => 0,
            'errors'                => [],
        ];

        if ($this->detect_ailiza_tables() === null) {
            return $stats; // ailiza_present stays false
        }
        $stats['ailiza_present'] = true;

        $ailiza_idx = $this->build_ailiza_index();

        $rows = $wpdb->get_results(
            "SELECT id, keyword, sub_keyword, reuse_response
               FROM {$kb_table}
              ORDER BY id ASC",
            ARRAY_A
        );
        $stats['total_rows'] = count($rows);

        foreach ($rows as $r) {
            $id  = (int) $r['id'];
            $sub = strtolower(trim((string) $r['sub_keyword']));
            if ($sub === 'aadefault' || $sub === '') {
                continue;
            }

            // Skip rows that already have a pointer set — idempotent.
            if (!empty($r['reuse_response'])) {
                $stats['already_set']++;
                continue;
            }

            $key = $r['keyword'] . '|' . $r['sub_keyword'];
            if (!isset($ailiza_idx[$key])) {
                $stats['no_ailiza_match']++;
                continue;
            }

            $a = $ailiza_idx[$key];
            if ($a['reuse'] !== 'yes' || $a['rkey'] === '') {
                $stats['ailiza_says_no_reuse']++;
                continue;
            }

            $reuse_keyword     = $a['rkey'];
            $reuse_sub_keyword = $a['rsubkey'] !== '' ? $a['rsubkey'] : 'aadefault';

            try {
                $result = $wpdb->update(
                    $kb_table,
                    [
                        'reuse_response'    => 1,
                        'reuse_keyword'     => $reuse_keyword,
                        'reuse_sub_keyword' => $reuse_sub_keyword,
                    ],
                    ['id' => $id],
                    ['%d', '%s', '%s'],
                    ['%d']
                );
                if ($result === false) {
                    $stats['failed']++;
                    $stats['errors'][] = sprintf(
                        'Row id=%d (%s/%s): wpdb update returned false',
                        $id, $r['keyword'], $r['sub_keyword']
                    );
                } else {
                    $stats['restored']++;
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = sprintf(
                    'Row id=%d (%s/%s): %s',
                    $id, $r['keyword'], $r['sub_keyword'], $e->getMessage()
                );
            }
        }

        // Flush search cache so the live matcher picks up the restored
        // pointers immediately.
        $this->flush_search_cache();

        return $stats;
    }

    /**
     * Handler for "Restore reuse pointers" form POST from the
     * Migration page. Calls restore_reuse_pointers_only and stashes
     * the result in a transient for display on redirect.
     */
    public function handle_restore_reuse_pointers(): void {
        check_admin_referer('cleversay_restore_reuse_pointers');
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'cleversay'), '', ['response' => 403]);
        }

        $stats = $this->restore_reuse_pointers_only();
        set_transient('cleversay_reuse_restore_stats', $stats, 60);

        wp_redirect(admin_url('admin.php?page=cleversay-migration&reuse_restored=1'));
        exit;
    }

    /**
     * Manually re-run the legacy synonym import. Idempotent and
     * non-destructive — admin-curated rows are never overwritten.
     * Useful if the admin deleted a row and wants it back, or if the
     * 4.37.7 upgrade hook didn't fire for some reason.
     */
    public function handle_import_legacy_synonyms(): void {
        check_admin_referer('cleversay_import_legacy_synonyms');
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'cleversay'), '', ['response' => 403]);
        }

        $database = new \CleverSay\Database();
        $stats    = $database->import_legacy_synonyms();
        set_transient('cleversay_legacy_synonym_import_stats', $stats, 60);

        wp_redirect(admin_url('admin.php?page=cleversay-synonyms&legacy_imported=1'));
        exit;
    }
    
    /**
     * Render Ask Question page (admin test mode)
     */
    private function get_ai_answers_menu_label(): string {
        $label = __('AI Answers', 'cleversay');
        try {
            global $wpdb;
            $db      = new \CleverSay\Database();
            // Check table exists before querying
            $exists  = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $db->ai_answers)
            );
            if (!$exists) return $label;
            $pending = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$db->ai_answers} WHERE status = 'pending'"
            );
            if ($pending > 0) {
                $label .= ' <span class="update-plugins count-' . $pending . '">'
                        . '<span class="update-count">' . $pending . '</span></span>';
            }
        } catch (\Throwable $e) {
            // Fail silently — just return the plain label
        }
        return $label;
    }

    public function render_ai_answers(): void {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/ai-answers.php';
    }

    /**
     * Render the AI Decisions observability page.
     *
     * Shows summary stats and drill-down tables for AI tiebreak
     * resolutions and KB validation rejections within a configurable
     * date range. Source of truth is questions_log — the columns
     * added in v4.37.50 (ai_tiebreak, ai_tiebreak_chosen_id,
     * ai_tiebreak_tied_ids, ai_provider) make these events queryable.
     *
     * @since 4.37.50
     */
    public function render_ai_decisions(): void {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/ai-decisions.php';
    }

    public function render_ai_sources(): void {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/ai-sources.php';
    }

    /**
     * Render the snippet diagnostic admin page.
     *
     * Internal debugging tool — shows the chunk-and-snippet decision
     * process for a given (source, question) pair so we can see why
     * a particular snippet got picked vs another. Not linked from
     * the main menu; accessed via direct URL for ad-hoc diagnosis.
     *
     * @since 4.37.102
     */
    public function render_snippet_diagnostic(): void {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/snippet-diagnostic.php';
    }

    public function render_ask_question(): void {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/ask-question.php';
    }
    
    /**
     * Render debug log page
     */
    public function render_debug_log(): void {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/debug-log.php';
    }
    
    /**
     * AJAX: Get logs for refresh
     */
    public function ajax_get_logs(): void {
        check_ajax_referer('cleversay_get_logs', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }
        
        $logger = \CleverSay\Logger::instance();
        wp_send_json_success([
            'logs' => $logger->get_logs(500),
            'size' => $logger->get_log_size(),
        ]);
    }
    
    /**
     * AJAX: Admin test search (no logging)
     */
    public function ajax_test_search(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }
        
        $question = sanitize_textarea_field(wp_unslash($_POST['question'] ?? ''));
        
        if (empty($question)) {
            wp_send_json_error(['message' => __('Please enter a question', 'cleversay')]);
        }
        
        $old_error_reporting = error_reporting(E_ALL);
        
        try {
            $search = new Search();
            $result = $search->test_search($question);
            
            if (!isset($result['process'])) {
                $result['process'] = [['step' => 0, 'description' => 'Error', 'result' => 'Process array missing from result']];
            }

            // ── aadefault AI validation (if enabled) ─────────────────────────
            $cs_opts = get_option('cleversay_options', []);
            $validate_aadefault = !empty($cs_opts['ai_validate_aadefault'])
                && \CleverSay\NetworkSettings::ai_is_configured();

            $top_match = $result['primary_match'] ?? ($result['matches'][0] ?? null);

            if ($validate_aadefault && $top_match
                && strtolower($top_match['sub_keyword'] ?? '') === 'aadefault'
            ) {
                $ai          = new \CleverSay\AI();
                $kb_answer   = wp_strip_all_tags($top_match['response'] ?? '');
                $is_relevant = $ai->validate_kb_answer($question, $kb_answer);

                $step_num = count($result['process']) + 1;
                if ($is_relevant) {
                    $result['process'][] = [
                        'step'        => $step_num,
                        'description' => 'aadefault AI Validation',
                        'result'      => '✅ AI confirmed this answer is relevant to the question.',
                        'ai_status'   => 'not_needed',
                    ];
                } else {
                    $result['process'][] = [
                        'step'        => $step_num,
                        'description' => 'aadefault AI Validation',
                        'result'      => '⚠️ AI determined the KB answer does not fit this question — AI fallback would be used instead.',
                        'ai_status'   => 'would_fire',
                    ];
                    // Clear the primary match so the UI shows it was rejected
                    $result['primary_match']    = null;
                    $result['aadefault_rejected'] = true;
                }
            } elseif ($validate_aadefault && $top_match) {
                // Match found but not aadefault — note that validation only applies to aadefault
                $result['process'][] = [
                    'step'        => count($result['process']) + 1,
                    'description' => 'aadefault AI Validation',
                    'result'      => 'Not applicable — match used a specific pattern, not aadefault.',
                    'ai_status'   => 'not_needed',
                ];
            }
            
            wp_send_json_success($result);
        } catch (\Exception $e) {
            error_log('CleverSay Test Search Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error([
                'message' => __('Search error: ', 'cleversay') . $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);
        } catch (\Error $e) {
            error_log('CleverSay Test Search Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error([
                'message' => __('Search error: ', 'cleversay') . $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);
        } finally {
            error_reporting($old_error_reporting);
        }
    }

    /**
     * AJAX: Save knowledge base entry
     */
    public function ajax_save_entry(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }
        
        global $wpdb;
        
        $id = absint($_POST['id'] ?? 0);

        // ── Variations support (v4.31.0) ────────────────────────────────
        // If 'variations' array is provided, prefer the new variation-based
        // editing flow: variations are saved against the entry, and the
        // pattern field (sub_keyword) is auto-generated from them. Falls
        // back to the old pattern-as-typed flow when no variations sent.
        $raw_variations = $_POST['variations'] ?? [];
        if (!is_array($raw_variations)) $raw_variations = [];
        $variations = [];
        foreach ($raw_variations as $v) {
            $v = sanitize_textarea_field(wp_unslash((string) $v));
            $v = trim($v);
            if ($v !== '') $variations[] = $v;
        }

        $auto_pattern = null;
        $auto_keyword = null;
        if (!empty($variations)) {
            // v4.35.0+: discriminator compiler. If a keyword was
            // provided in the POST we can fetch siblings; if not, the
            // keyword is being auto-picked from variations below and
            // we compile without sibling context (acceptable for
            // imports where there's no editing-session context).
            $compiler = \CleverSay\KBPatternCompiler::from_database();
            $kw_for_compile = sanitize_text_field(wp_unslash((string) ($_POST['keyword'] ?? '')));
            if ($kw_for_compile !== '') {
                $siblings = $this->fetch_sibling_variations($kw_for_compile, 0);
                $auto_pattern = $compiler->compile($variations, $kw_for_compile, $siblings);
            } else {
                $auto_pattern = $compiler->compile($variations);
            }

            // Auto-pick a keyword if the admin hasn't provided one. The
            // primary keyword is used as the search-narrowing index — pick
            // the most common content word across variations.
            if (empty($_POST['keyword'])) {
                $auto_keyword = $this->pick_primary_keyword_from_variations($variations);
            }
        }

        $data = [
            'keyword' => sanitize_text_field(wp_unslash($_POST['keyword'] ?? '')) ?: ($auto_keyword ?: ''),
            // sub_keyword: prefer admin-provided pattern (advanced override),
            // otherwise use auto-generated, otherwise empty (fallback to
            // legacy keyword-only matching).
            'sub_keyword' => sanitize_text_field(wp_unslash($_POST['sub_keyword'] ?? '')) ?: ($auto_pattern ?? ''),
            'question' => sanitize_textarea_field(wp_unslash($_POST['question'] ?? '')),
            'response' => wp_kses_post(wp_unslash($_POST['response'] ?? '')),
            'search_type' => sanitize_text_field(wp_unslash($_POST['search_type'] ?? 'keyword')),
            'status' => sanitize_text_field(wp_unslash($_POST['status'] ?? 'hold')),
            'show_rating' => absint($_POST['show_rating'] ?? 1),
            'reuse_response' => absint($_POST['reuse_response'] ?? 0),
            'reuse_keyword' => sanitize_text_field(wp_unslash($_POST['reuse_keyword'] ?? '')),
            'reuse_sub_keyword' => sanitize_text_field(wp_unslash($_POST['reuse_sub_keyword'] ?? '')),
            'expires_at' => sanitize_text_field(wp_unslash($_POST['expires_at'] ?? '')) ?: null,
        ];
        
        // Validation
        if (empty($data['keyword'])) {
            wp_send_json_error(['message' => __('Keyword is required', 'cleversay')], 400);
        }
        
        if (empty($data['response']) && !$data['reuse_response']) {
            wp_send_json_error(['message' => __('Response is required', 'cleversay')], 400);
        }
        
        if ($id) {
            $data['updated_at'] = current_time('mysql');
            $result = $wpdb->update($this->db->knowledge_base, $data, ['id' => $id]);
        } else {
            $data['created_by'] = get_current_user_id();
            $data['created_at'] = current_time('mysql');
            $result = $wpdb->insert($this->db->knowledge_base, $data);
            $id = $wpdb->insert_id;
        }
        
        if ($result === false) {
            wp_send_json_error(['message' => __('Database error', 'cleversay')], 500);
        }

        // Save variations (replaces any existing). On new entries we use the
        // freshly-inserted id; on updates we use the provided id.
        if ($id > 0) {
            \CleverSay\KBVariations::replace_all($id, $variations);
        }

        // v4.39.0+: Phase 2 of embeddings migration. Generate embedding
        // synchronously (1-3 sec latency on save) so the admin sees
        // immediate effect. If embedding fails, the entry is queued
        // for cron retry — the save itself always succeeds.
        // See ARCHITECTURE.md → "Scaling Trajectory".
        if ($id > 0
            && class_exists('\\CleverSay\\Supabase')
            && \CleverSay\Supabase::is_enabled()
        ) {
            try {
                (new \CleverSay\Embedder())->embed_kb_entry_sync((int) $id);
            } catch (\Throwable $e) {
                // Non-fatal — the MySQL write succeeded.
                \CleverSay\Logger::instance()->warning('KB embedding failed (non-fatal)', [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->flush_search_cache();
        wp_send_json_success([
            'message' => __('Entry saved successfully', 'cleversay'),
            'id' => $id,
            'auto_pattern' => $auto_pattern,
        ]);
    }

    /**
     * Pick a primary keyword from variations — used when the admin hasn't
     * specified one explicitly. Strategy: stem all words, drop stopwords,
     * choose the stem that appears in the most variations (with longest
     * surface form as tiebreaker).
     *
     * The keyword field still exists in the legacy schema and is used as the
     * primary search-narrowing index. We default to a sensible auto-pick so
     * non-technical admins don't have to think about it.
     */
    private function pick_primary_keyword_from_variations(array $variations): string {
        $stopwords = [
            'a','an','the','is','are','am','was','were','be','been','being',
            'i','me','my','you','your','we','us','our','they','their','it','its',
            'do','does','did','have','has','had','can','could','will','would',
            'and','or','but','if','then','for','to','of','in','on','at','by','with',
            'from','about','into','through','as','that','this','these','those',
            'what','when','where','who','why','how','which','any','all','some','no',
            'not','more','most','few','many','so','too','very','just','only',
        ];

        $word_counts = [];
        $word_longest = [];
        foreach ($variations as $v) {
            $v = strtolower(preg_replace('/[^a-z0-9\s]/i', ' ', $v));
            $words = preg_split('/\s+/', trim($v));
            $seen = [];
            foreach ($words as $w) {
                if ($w === '' || in_array($w, $stopwords, true) || strlen($w) < 3) continue;
                if (isset($seen[$w])) continue;
                $seen[$w] = true;
                $word_counts[$w] = ($word_counts[$w] ?? 0) + 1;
                if (!isset($word_longest[$w]) || strlen($w) > strlen($word_longest[$w])) {
                    $word_longest[$w] = $w;
                }
            }
        }

        if (empty($word_counts)) return '';

        // Sort by count desc, then by length desc.
        arsort($word_counts);
        $top = array_keys($word_counts)[0];
        return $top;
    }
    
    /**
     * AJAX: Delete knowledge base entry
     */
    public function ajax_delete_entry(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }
        
        global $wpdb;
        
        $id = absint($_POST['id'] ?? 0);
        
        if (!$id) {
            wp_send_json_error(['message' => __('Invalid ID', 'cleversay')], 400);
        }
        
        $result = $wpdb->delete($this->db->knowledge_base, ['id' => $id]);
        
        if ($result === false) {
            wp_send_json_error(['message' => __('Database error', 'cleversay')], 500);
        }

        // Cascade: drop any variation rows attached to this id (only the
        // canonical entry of a phrase group has them, but it's a no-op for
        // sub-entries so we don't need to distinguish here).
        if (class_exists('\\CleverSay\\KBVariations')) {
            \CleverSay\KBVariations::delete_for_entry($id);
        }

        // v4.39.0+: Phase 2 — remove embedding from Supabase.
        // Non-fatal: KB delete proceeds even if Supabase cleanup fails.
        if (class_exists('\\CleverSay\\Supabase') && \CleverSay\Supabase::is_enabled()) {
            try {
                (new \CleverSay\Embedder())->delete_kb_entry((int) $id);
            } catch (\Throwable $e) {
                \CleverSay\Logger::instance()->warning('KB embedding cleanup failed on delete', [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->flush_search_cache();
        wp_send_json_success(['message' => __('Entry deleted successfully', 'cleversay')]);
    }
    
    /**
     * AJAX: Bulk action
     */
    public function ajax_bulk_action(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }
        
        global $wpdb;
        
        $action = sanitize_text_field(wp_unslash($_POST['bulk_action'] ?? ''));
        $ids = array_map('absint', $_POST['ids'] ?? []);
        
        if (empty($ids)) {
            wp_send_json_error(['message' => __('No items selected', 'cleversay')], 400);
        }
        
        $id_list = implode(',', $ids);
        
        switch ($action) {
            case 'delete':
                $wpdb->query("DELETE FROM {$this->db->knowledge_base} WHERE id IN ($id_list)");
                if (class_exists('\\CleverSay\\KBVariations')) {
                    \CleverSay\KBVariations::delete_for_entries($ids);
                }
                break;
                
            case 'activate':
                $wpdb->query("UPDATE {$this->db->knowledge_base} SET status = 'active' WHERE id IN ($id_list)");
                break;
                
            case 'deactivate':
                $wpdb->query("UPDATE {$this->db->knowledge_base} SET status = 'inactive' WHERE id IN ($id_list)");
                break;
                
            case 'hold':
                $wpdb->query("UPDATE {$this->db->knowledge_base} SET status = 'hold' WHERE id IN ($id_list)");
                break;
                
            default:
                wp_send_json_error(['message' => __('Invalid action', 'cleversay')], 400);
        }
        
        wp_send_json_success(['message' => __('Bulk action completed', 'cleversay')]);
    }
    
    /**
     * AJAX: Validate link
     */
    public function ajax_validate_link(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        
        $url = esc_url_raw($_POST['url'] ?? '');
        
        if (!$url) {
            wp_send_json_error(['message' => __('Invalid URL', 'cleversay')], 400);
        }
        
        $response = wp_remote_head($url, ['timeout' => 10]);
        
        if (is_wp_error($response)) {
            wp_send_json_error([
                'valid' => false,
                'message' => $response->get_error_message(),
            ]);
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $valid = $code >= 200 && $code < 400;
        
        wp_send_json_success([
            'valid' => $valid,
            'code' => $code,
            'message' => $valid ? __('Link is valid', 'cleversay') : __('Link returned error code: ', 'cleversay') . $code,
        ]);
    }
    
    /**
     * AJAX: Export data
     */
    public function ajax_export_data(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }
        
        require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-import-export.php';
        $exporter = new CleverSay_Import_Export();
        
        $type = sanitize_text_field(wp_unslash($_POST['type'] ?? 'all'));
        $format = sanitize_text_field(wp_unslash($_POST['format'] ?? 'json'));
        
        $result = $exporter->export($type, $format);
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Import data
     */
    public function ajax_import_data(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }
        
        require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-import-export.php';
        $importer = new ImportExport();
        
        $source = sanitize_text_field(wp_unslash($_POST['source'] ?? ''));
        
        if ($source === 'legacy') {
            // Import from legacy database
            $config = [
                'host' => sanitize_text_field(wp_unslash($_POST['host'] ?? 'localhost')),
                'user' => sanitize_text_field(wp_unslash($_POST['user'] ?? '')),
                'password' => $_POST['password'] ?? '',
                'database' => sanitize_text_field(wp_unslash($_POST['database'] ?? '')),
                'prefix' => sanitize_text_field(wp_unslash($_POST['prefix'] ?? '')),
            ];
            
            $result = $this->db->import_legacy_data($config);
        } else {
            // Import from uploaded file
            if (empty($_FILES['file'])) {
                wp_send_json_error(['message' => __('No file uploaded', 'cleversay')], 400);
            }
            
            $result = $importer->import($_FILES['file']);
        }
        
        if (!empty($result['errors'])) {
            wp_send_json_error([
                'message' => __('Import completed with errors', 'cleversay'),
                'errors' => $result['errors'],
                'counts' => $result,
            ]);
        }
        
        wp_send_json_success([
            'message' => __('Import completed successfully', 'cleversay'),
            'counts' => $result,
        ]);
    }


    /**
     * AJAX: Preview JSON import (dry-run, no DB writes)
     */
    public function ajax_preview_import(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }

        $raw = wp_unslash($_POST['data'] ?? '');
        if (empty($raw)) {
            wp_send_json_error(['message' => __('No data received.', 'cleversay')], 400);
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => __('Invalid JSON data.', 'cleversay')], 400);
        }

        $importer = new ImportExport();
        $preview  = $importer->preview_json_import($data);

        if (!$preview['valid']) {
            wp_send_json_error([
                'message' => implode(' ', $preview['errors']),
                'preview' => $preview,
            ]);
        }

        wp_send_json_success($preview);
    }
    
    /**
     * AJAX: Add synonym
     */
    public function ajax_add_synonym(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_synonyms';
        $knowledge_table = $wpdb->prefix . 'cleversay_knowledge';
        
        $canonical = sanitize_text_field(wp_unslash($_POST['canonical'] ?? ''));
        $variants = sanitize_textarea_field(wp_unslash($_POST['variants'] ?? ''));
        
        if (empty($canonical) || empty($variants)) {
            wp_send_json_error(['message' => __('Canonical word and variants are required', 'cleversay')]);
        }
        
        // Check if any variant is already a keyword
        $variant_array = array_filter(array_map('trim', explode(',', $variants)));
        if (!empty($variant_array)) {
            $existing_keywords = $wpdb->get_col(
                "SELECT DISTINCT LOWER(keyword) FROM {$knowledge_table}"
            );
            
            $canonical_lower = strtolower($canonical);
            $conflicts = [];
            foreach ($variant_array as $syn) {
                $syn_lower = strtolower($syn);
                if (in_array($syn_lower, $existing_keywords) && $syn_lower !== $canonical_lower) {
                    $conflicts[] = $syn;
                }
            }
            
            if (!empty($conflicts)) {
                wp_send_json_error([
                    'message' => sprintf(
                        __('Cannot use these words as synonyms (they are keywords): %s', 'cleversay'),
                        implode(', ', $conflicts)
                    )
                ]);
            }
        }
        
        $result = $wpdb->insert($table, [
            'canonical_word' => $canonical,
            'variant_words' => $variants,
            'is_active' => 1,
        ], ['%s', '%s', '%d']);
        
        if ($result) {
            wp_send_json_success([
                'message' => __('Synonym added successfully', 'cleversay'),
                'id' => $wpdb->insert_id,
            ]);
        } else {
            wp_send_json_error(['message' => __('Failed to add synonym', 'cleversay')]);
        }
    }
    
    /**
     * Handle synonym form submissions (non-AJAX)
     */
    public function handle_synonym_form(): void {
        // Handle save synonym form
        if (isset($_POST['action']) && $_POST['action'] === 'cleversay_save_synonym') {
            if (!wp_verify_nonce($_POST['cleversay_nonce'] ?? '', 'cleversay_save_synonym')) {
                return;
            }
            
            if (!current_user_can('manage_options')) {
                return;
            }
            
            global $wpdb;
            $table = $wpdb->prefix . 'cleversay_synonyms';
            $knowledge_table = $wpdb->prefix . 'cleversay_knowledge';
            
            $id = absint($_POST['synonym_id'] ?? 0);
            $canonical_word = sanitize_text_field(wp_unslash($_POST['canonical_word'] ?? ''));
            $variant_words = sanitize_text_field(wp_unslash($_POST['variant_words'] ?? ''));
            $misspellings = sanitize_text_field(wp_unslash($_POST['misspellings'] ?? ''));
            $is_phrase = isset($_POST['is_phrase']) ? 1 : 0;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // Require canonical word AND at least one of: variants or misspellings
            if (empty($canonical_word) || (empty($variant_words) && empty($misspellings))) {
                wp_redirect(add_query_arg(['page' => 'cleversay-synonyms', 'error' => 'missing_fields'], admin_url('admin.php')));
                exit;
            }
            
            // Check if any variant/misspelling is already a keyword
            $variant_array = array_filter(array_map('trim', explode(',', $variant_words)));
            $misspelling_array = array_filter(array_map('trim', explode(',', $misspellings)));
            $all_synonyms = array_merge($variant_array, $misspelling_array);
            
            if (!empty($all_synonyms)) {
                $existing_keywords = $wpdb->get_col(
                    "SELECT DISTINCT LOWER(keyword) FROM {$knowledge_table}"
                );
                
                $canonical_lower = strtolower($canonical_word);
                $conflicts = [];
                foreach ($all_synonyms as $syn) {
                    $syn_lower = strtolower($syn);
                    if (in_array($syn_lower, $existing_keywords) && $syn_lower !== $canonical_lower) {
                        $conflicts[] = $syn;
                    }
                }
                
                if (!empty($conflicts)) {
                    wp_redirect(add_query_arg([
                        'page' => 'cleversay-synonyms', 
                        'error' => 'keyword_conflict',
                        'conflicts' => urlencode(implode(', ', $conflicts))
                    ], admin_url('admin.php')));
                    exit;
                }
            }
            
            $data = [
                'canonical_word' => $canonical_word,
                'variant_words' => $variant_words,
                'misspellings' => $misspellings,
                'is_phrase' => $is_phrase,
                'is_active' => $is_active,
            ];
            
            if ($id) {
                // Update existing
                $result = $wpdb->update($table, $data, ['id' => $id]);
            } else {
                // Insert new
                $result = $wpdb->insert($table, $data);
            }
            
            wp_redirect(add_query_arg(['page' => 'cleversay-synonyms', 'message' => 'saved'], admin_url('admin.php')));
            exit;
        }
        
        // Handle bulk synonyms form
        if (isset($_POST['action']) && $_POST['action'] === 'cleversay_bulk_synonyms') {
            if (!wp_verify_nonce($_POST['cleversay_nonce'] ?? '', 'cleversay_bulk_synonyms')) {
                return;
            }
            
            if (!current_user_can('manage_options')) {
                return;
            }
            
            global $wpdb;
            $table = $wpdb->prefix . 'cleversay_synonyms';
            
            $bulk_text = sanitize_textarea_field(wp_unslash($_POST['bulk_synonyms'] ?? ''));
            $lines = explode("\n", $bulk_text);
            $count = 0;
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '=>') === false) {
                    continue;
                }
                
                list($variant, $canonical) = array_map('trim', explode('=>', $line, 2));
                
                if (empty($variant) || empty($canonical)) {
                    continue;
                }
                
                // Check if canonical word exists
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE canonical_word = %s",
                    $canonical
                ));
                
                if ($existing) {
                    // Append to existing variant_words
                    $wpdb->query($wpdb->prepare(
                        "UPDATE $table SET variant_words = CONCAT(variant_words, ', ', %s) WHERE id = %d",
                        $variant,
                        $existing
                    ));
                } else {
                    // Insert new
                    $wpdb->insert($table, [
                        'canonical_word' => $canonical,
                        'variant_words' => $variant,
                        'is_active' => 1,
                    ]);
                }
                $count++;
            }
            
            wp_redirect(add_query_arg(['page' => 'cleversay-synonyms', 'message' => 'imported'], admin_url('admin.php')));
            exit;
        }
        
        // Handle delete synonym
        if (isset($_GET['page']) && $_GET['page'] === 'cleversay-synonyms' 
            && isset($_GET['action']) && $_GET['action'] === 'delete'
            && isset($_GET['id'])) {
            
            $id = absint($_GET['id']);
            
            if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'delete_synonym_' . $id)) {
                return;
            }
            
            if (!current_user_can('manage_options')) {
                return;
            }
            
            global $wpdb;
            $table = $wpdb->prefix . 'cleversay_synonyms';
            $wpdb->delete($table, ['id' => $id]);
            
            wp_redirect(add_query_arg(['page' => 'cleversay-synonyms', 'message' => 'deleted'], admin_url('admin.php')));
            exit;
        }
    }
    
    /**
     * Handle inquiry page actions (delete, bulk)
     */
    public function handle_inquiry_actions(): void {
        // Only process on inquiries page
        if (!isset($_GET['page']) || $_GET['page'] !== 'cleversay-inquiries') {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_inquiries';
        
        // Handle individual delete
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['inquiry_id'])) {
            $inquiry_id = absint($_GET['inquiry_id']);
            
            if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'delete_inquiry_' . $inquiry_id)) {
                return;
            }
            
            if (!current_user_can('manage_options')) {
                return;
            }
            
            $wpdb->delete($table, ['id' => $inquiry_id], ['%d']);
            
            wp_redirect(add_query_arg(['page' => 'cleversay-inquiries', 'deleted' => '1'], admin_url('admin.php')));
            exit;
        }
        
        // Handle bulk actions
        if (isset($_POST['bulk_form_submitted']) && !empty($_POST['inquiry_ids']) && !empty($_POST['bulk_action'])) {
            if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'cleversay_bulk_inquiries')) {
                return;
            }
            
            if (!current_user_can('manage_options')) {
                return;
            }
            
            $bulk = sanitize_text_field(wp_unslash($_POST['bulk_action']));
            $ids = array_map('intval', $_POST['inquiry_ids']);
            
            if (empty($ids)) {
                return;
            }
            
            $ids_placeholder = implode(',', $ids);
            
            if ($bulk === 'resolve') {
                $wpdb->query("UPDATE {$table} SET status = 'answered', responded_at = NOW() WHERE id IN ({$ids_placeholder})");
            } elseif ($bulk === 'delete') {
                $wpdb->query("DELETE FROM {$table} WHERE id IN ({$ids_placeholder})");
            }
            
            wp_redirect(add_query_arg(['page' => 'cleversay-inquiries', 'updated' => 'bulk'], admin_url('admin.php')));
            exit;
        }
    }
    
    /**
     * AJAX: Delete synonym
     */
    public function ajax_delete_synonym(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_synonyms';
        
        $id = absint($_POST['id'] ?? 0);
        
        if (!$id) {
            wp_send_json_error(['message' => __('Invalid synonym ID', 'cleversay')]);
        }
        
        $result = $wpdb->delete($table, ['id' => $id], ['%d']);
        
        if ($result) {
            wp_send_json_success(['message' => __('Synonym deleted successfully', 'cleversay')]);
        } else {
            wp_send_json_error(['message' => __('Failed to delete synonym', 'cleversay')]);
        }
    }
    
    /**
     * AJAX: Toggle synonym active status
     */
    public function ajax_toggle_synonym(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_synonyms';
        
        $id = absint($_POST['id'] ?? 0);
        $active = absint($_POST['active'] ?? 0);
        
        if (!$id) {
            wp_send_json_error(['message' => __('Invalid synonym ID', 'cleversay')]);
        }
        
        $result = $wpdb->update($table, ['is_active' => $active], ['id' => $id], ['%d'], ['%d']);
        
        if ($result !== false) {
            wp_send_json_success(['message' => __('Synonym updated successfully', 'cleversay')]);
        } else {
            wp_send_json_error(['message' => __('Failed to update synonym', 'cleversay')]);
        }
    }
    
    /**
    /**
     * AJAX: Resolve inquiry
     */
    public function ajax_resolve_inquiry(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_inquiries';
        
        $id = absint($_POST['id'] ?? 0);
        $response = sanitize_textarea_field(wp_unslash($_POST['response'] ?? ''));
        
        if (!$id) {
            wp_send_json_error(['message' => __('Invalid inquiry ID', 'cleversay')]);
        }
        
        $result = $wpdb->update($table, [
            'status' => 'answered',
            'response' => $response,
            'responded_by' => get_current_user_id(),
            'responded_at' => current_time('mysql'),
        ], ['id' => $id], ['%s', '%s', '%d', '%s'], ['%d']);
        
        if ($result !== false) {
            // Optionally send email to user
            $inquiry = $wpdb->get_row($wpdb->prepare(
                "SELECT email, question FROM {$table} WHERE id = %d",
                $id
            ));
            
            if ($inquiry && $inquiry->email && !empty($response)) {
                $send_email = !empty($_POST['send_email']);
                if ($send_email) {
                    $subject = sprintf(__('Re: Your question on %s', 'cleversay'), get_bloginfo('name'));
                    $message = sprintf(
                        __("Hello,\n\nThank you for your question:\n\"%s\"\n\nHere is our response:\n\n%s\n\nBest regards,\n%s", 'cleversay'),
                        $inquiry->question,
                        $response,
                        get_bloginfo('name')
                    );
                    wp_mail($inquiry->email, $subject, $message);
                }
            }
            
            wp_send_json_success(['message' => __('Inquiry resolved successfully', 'cleversay')]);
        } else {
            wp_send_json_error(['message' => __('Failed to resolve inquiry', 'cleversay')]);
        }
    }
    
    /**
     * Handle saving keyword with response groups
     */
    public function handle_save_keyword(): void {
        if (!check_admin_referer('cleversay_save_keyword', 'cleversay_nonce')) {
            wp_die(__('Security check failed', 'cleversay'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'cleversay'));
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_knowledge';
        
        $keyword = sanitize_text_field(wp_unslash($_POST['keyword'] ?? ''));
        $is_new = !empty($_POST['is_new']);
        $groups = $_POST['groups'] ?? [];
        
        if (empty($keyword)) {
            wp_redirect(add_query_arg([
                'page' => 'cleversay-knowledge',
                'message' => 'error',
                'error' => 'empty_keyword'
            ], admin_url('admin.php')));
            exit;
        }
        
        // Validate all patterns match their phrases
        $validation_errors = [];
        $search = new \CleverSay\Search();
        
        foreach ($groups as $group_index => $group) {
            foreach ($group['patterns'] ?? [] as $pattern_index => $pattern_data) {
                $pattern = sanitize_text_field($pattern_data['pattern'] ?? '');
                $phrase = sanitize_text_field($pattern_data['phrase'] ?? '');
                
                if (empty($pattern) || empty($phrase)) continue;
                
                // Skip validation for aadefault - just needs the keyword
                if ($pattern === 'aadefault') {
                    // Check if phrase contains the keyword
                    if (stripos($phrase, $keyword) === false) {
                        $validation_errors[] = sprintf(
                            __('Group %d, Pattern "aadefault": Phrase must contain the keyword "%s"', 'cleversay'),
                            $group_index + 1,
                            $keyword
                        );
                    }
                    continue;
                }
                
                // For other patterns, test the full search logic
                $test_result = $search->test_pattern_match($keyword, $pattern, $phrase);
                
                if (!$test_result['matched']) {
                    $validation_errors[] = sprintf(
                        __('Group %d, Pattern "%s": Phrase "%s" does not match. %s', 'cleversay'),
                        $group_index + 1,
                        $pattern,
                        wp_trim_words($phrase, 10),
                        $test_result['reason'] ?? ''
                    );
                }
            }
        }
        
        if (!empty($validation_errors)) {
            // Store errors AND form input in transients so the redirect-back
            // can pre-fill fields. v4.37.24+: previously only errors were
            // persisted, causing the admin's typed work to be lost on
            // validation failure.
            set_transient('cleversay_validation_errors', $validation_errors, 60);
            set_transient('cleversay_form_repost', [
                'keyword' => $keyword,
                'groups'  => $_POST['groups'] ?? [],
            ], 60);

            $redirect_url = $is_new 
                ? add_query_arg(['action' => 'new-keyword', 'message' => 'validation_failed'], admin_url('admin.php?page=cleversay-knowledge'))
                : add_query_arg(['action' => 'edit-keyword', 'keyword' => urlencode($keyword), 'message' => 'validation_failed'], admin_url('admin.php?page=cleversay-knowledge'));
            
            wp_redirect($redirect_url);
            exit;
        }
        
        // Delete existing entries for this keyword (if editing)
        if (!$is_new) {
            // Preserve hits and helpful counts keyed by entry ID before deleting
            $existing = $wpdb->get_results(
                $wpdb->prepare("SELECT id, sub_keyword, hits, helpful_yes, helpful_no, polished_hash FROM {$table} WHERE keyword = %s", $keyword),
                ARRAY_A
            );
            $preserved_stats = [];
            // v4.37.54+: also capture old patterns so we can detect
            // pattern changes after insert and cascade updates to
            // any reuse_sub_keyword references pointing at the old
            // pattern. Patterns are mutable (recompile, manual edit)
            // but stored as the soft FK; without this cascade,
            // Reuse Response references silently break.
            $old_patterns_by_id = [];
            // v4.37.57+: capture polished_hashes BEFORE delete so the
            // preservation map is non-empty. (v4.37.52 had a bug here:
            // it queried after the delete, so the map was always
            // empty for existing entries.)
            $preserved_polished_hashes = [];
            foreach ($existing as $row) {
                $preserved_stats[(int)$row['id']] = [
                    'hits'        => (int)$row['hits'],
                    'helpful_yes' => (int)$row['helpful_yes'],
                    'helpful_no'  => (int)$row['helpful_no'],
                ];
                $old_patterns_by_id[(int)$row['id']] = (string) $row['sub_keyword'];
                $h = (string) ($row['polished_hash'] ?? '');
                if ($h !== '') $preserved_polished_hashes[$h] = true;
            }
            $wpdb->delete($table, ['keyword' => $keyword]);
        } else {
            $old_patterns_by_id = [];
            $preserved_polished_hashes = [];
        }

        // v4.37.57+: a "pending" polished hash submitted by the form
        // when admin polished mid-edit on a new entry. The polish
        // apply endpoint returned mode=pending and the JS stashed the
        // hash in this hidden field. Trust it only if the submitted
        // response actually hashes to it (defends against the field
        // being left stale after admin edited again).
        $pending_polished_hash = sanitize_text_field(wp_unslash((string) ($_POST['__pending_polished_hash'] ?? '')));
        if ($pending_polished_hash !== '' && preg_match('/^[a-f0-9]{40}$/i', $pending_polished_hash)) {
            $preserved_polished_hashes[$pending_polished_hash] = true;
        }
        // v4.37.52+: preserve polished_hash across the
        // delete-and-reinsert. Compute the hash of the response
        // being saved and check whether ANY of the deleted rows
        // had a matching polished_hash. If yes, the response is
        // unchanged from the previously-polished version and we
        // restore the hash so runtime Polish KB skip stays in
        // effect. If no match, polished_hash stays NULL (default)
        // — meaning admin edited the response and runtime Polish
        // will re-polish.

        // v4.37.54+: track which patterns changed so we can
        // cascade-update reuse_sub_keyword references after the
        // inserts complete. Without this, an entry pointed to via
        // Reuse Response silently breaks when its pattern changes.
        $pattern_changes = []; // ['old_pattern' => 'new_pattern']

        // Insert new entries
        foreach ($groups as $group) {
            $response = wp_kses_post($group['response'] ?? '');
            $status = sanitize_text_field($group['status'] ?? 'active');
            $expires_at = !empty($group['expires_at']) ? sanitize_text_field($group['expires_at']) : null;
            $show_rating = isset($group['show_rating']) ? 1 : 0;

            // Compute current response's hash; if it matches a
            // previously-stored polished_hash for this keyword,
            // preserve so runtime Polish KB still skips.
            $response_hash = self::compute_response_hash($response);
            $preserved_hash = isset($preserved_polished_hashes[$response_hash]) ? $response_hash : null;

            // v4.37.64+: capture diagnostic info per-save for the
            // polish-state debug tool. Lets admin see exactly what
            // happened: was a pending hash sent? Did it match the
            // submitted response's hash? If not, why?
            //
            // Stored as a per-keyword transient so the diagnose tool
            // can surface it on next page load. Cheap, ephemeral
            // (15 min TTL).
            if (function_exists('set_transient')) {
                $debug_payload = [
                    'timestamp'         => current_time('mysql'),
                    'keyword'           => $keyword,
                    'pending_from_form' => $pending_polished_hash,
                    'preserved_map'     => array_keys($preserved_polished_hashes),
                    'response_hash'     => $response_hash,
                    'preserved_chosen'  => $preserved_hash,
                    'response_length'   => strlen($response),
                    'response_preview'  => mb_substr($response, 0, 120),
                ];
                set_transient(
                    'cleversay_polish_save_debug_' . md5($keyword),
                    $debug_payload,
                    15 * MINUTE_IN_SECONDS
                );
            }

            foreach ($group['patterns'] ?? [] as $pattern_data) {
                $pattern = sanitize_text_field($pattern_data['pattern'] ?? '');
                $phrase = sanitize_text_field($pattern_data['phrase'] ?? '');
                $entry_id = !empty($pattern_data['id']) ? absint($pattern_data['id']) : null;
                
                if (empty($pattern) || empty($phrase)) continue;

                // Track pattern change: if this entry had a different
                // pattern before, record old→new for the cascade.
                if ($entry_id && isset($old_patterns_by_id[$entry_id])) {
                    $old_pattern = $old_patterns_by_id[$entry_id];
                    if ($old_pattern !== '' && $old_pattern !== $pattern) {
                        $pattern_changes[$old_pattern] = $pattern;
                    }
                }

                // Restore stats for this entry if we had them
                $stats = ($entry_id && isset($preserved_stats[$entry_id]))
                    ? $preserved_stats[$entry_id]
                    : ['hits' => 0, 'helpful_yes' => 0, 'helpful_no' => 0];
                
                $wpdb->insert($table, [
                    'keyword'     => $keyword,
                    'sub_keyword' => $pattern,
                    'question'    => $phrase,
                    'response'    => $response,
                    'polished_hash' => $preserved_hash,
                    'status'      => $status,
                    'expires_at'  => $expires_at,
                    'show_rating' => $show_rating,
                    'hits'        => $stats['hits'],
                    'helpful_yes' => $stats['helpful_yes'],
                    'helpful_no'  => $stats['helpful_no'],
                    'created_at'  => current_time('mysql'),
                    'updated_at'  => current_time('mysql'),
                ]);
            }
        }

        // v4.37.54+: cascade reuse_sub_keyword updates. For each
        // pattern that changed (old → new) under this keyword, find
        // any rows whose reuse pointer was (keyword, old_pattern)
        // and update them to (keyword, new_pattern). Without this,
        // Reuse Response references break silently when admin
        // recompiles or manually edits the target entry's pattern.
        foreach ($pattern_changes as $old_pat => $new_pat) {
            $updated = $wpdb->update(
                $table,
                ['reuse_sub_keyword' => $new_pat],
                [
                    'reuse_keyword'     => $keyword,
                    'reuse_sub_keyword' => $old_pat,
                ],
                ['%s'],
                ['%s', '%s']
            );
            if ($updated > 0) {
                error_log(sprintf(
                    '[CleverSay] Cascaded reuse_sub_keyword update: keyword=%s pattern %s -> %s (%d rows updated)',
                    $keyword,
                    $old_pat,
                    $new_pat,
                    (int) $updated
                ));
            }
        }
        
        // Save synonyms if provided (only for new keywords, editing uses AJAX)
        if ($is_new) {
            $synonym_variants = sanitize_text_field(wp_unslash($_POST['synonym_variants'] ?? ''));
            $synonym_misspellings = sanitize_text_field(wp_unslash($_POST['synonym_misspellings'] ?? ''));
            
            if (!empty($synonym_variants) || !empty($synonym_misspellings)) {
                $synonyms_table = $wpdb->prefix . 'cleversay_synonyms';
                $canonical_word = strtolower($keyword);
                
                // Clean up the comma-separated values
                $variant_array = array_filter(array_map('trim', explode(',', $synonym_variants)));
                $misspelling_array = array_filter(array_map('trim', explode(',', $synonym_misspellings)));
                
                // Check if any synonym word is already a keyword in the knowledge base
                $all_synonyms = array_merge($variant_array, $misspelling_array);
                if (!empty($all_synonyms)) {
                    $existing_keywords = $wpdb->get_col(
                        "SELECT DISTINCT LOWER(keyword) FROM {$table}"
                    );
                    
                    $conflicts = [];
                    foreach ($all_synonyms as $syn) {
                        $syn_lower = strtolower($syn);
                        if (in_array($syn_lower, $existing_keywords) && $syn_lower !== $canonical_word) {
                            $conflicts[] = $syn;
                        }
                    }
                    
                    if (!empty($conflicts)) {
                        // Store error and redirect back
                        set_transient('cleversay_validation_errors', [
                            sprintf(
                                __('Synonym conflict: "%s" are already keywords. Remove them from synonyms or the search will be confused.', 'cleversay'),
                                implode(', ', $conflicts)
                            )
                        ], 60);
                        
                        wp_redirect(add_query_arg([
                            'action' => 'new-keyword',
                            'message' => 'validation_failed'
                        ], admin_url('admin.php?page=cleversay-knowledge')));
                        exit;
                    }
                }
                
                $synonym_variants = implode(', ', $variant_array);
                $synonym_misspellings = implode(', ', $misspelling_array);
                
                // Check if synonym entry already exists
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$synonyms_table} WHERE canonical_word = %s",
                    $canonical_word
                ));
                
                if ($existing) {
                    $wpdb->update(
                        $synonyms_table,
                        [
                            'variant_words' => $synonym_variants ?: null,
                            'misspellings' => $synonym_misspellings ?: null,
                            'updated_at' => current_time('mysql'),
                        ],
                        ['id' => $existing]
                    );
                } else {
                    $wpdb->insert($synonyms_table, [
                        'canonical_word' => $canonical_word,
                        'variant_words' => $synonym_variants ?: null,
                        'misspellings' => $synonym_misspellings ?: null,
                        'is_phrase' => 0,
                        'is_active' => 1,
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql'),
                    ]);
                }
            }
        }
        
        wp_redirect(add_query_arg([
            'action' => 'edit-keyword',
            'keyword' => urlencode($keyword),
            'message' => 'saved'
        ], admin_url('admin.php?page=cleversay-knowledge')));
        exit;
    }
    
    /**
     * AJAX: Delete entire keyword
     */
    public function ajax_delete_keyword(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')]);
        }
        
        global $wpdb;
        $keyword = sanitize_text_field(wp_unslash($_POST['keyword'] ?? ''));
        
        if (empty($keyword)) {
            wp_send_json_error(['message' => __('No keyword specified', 'cleversay')]);
        }
        
        $result = $wpdb->delete(
            $wpdb->prefix . 'cleversay_knowledge',
            ['keyword' => $keyword]
        );
        
        if ($result !== false) {
            wp_send_json_success(['message' => __('Keyword deleted', 'cleversay')]);
        } else {
            wp_send_json_error(['message' => __('Failed to delete keyword', 'cleversay')]);
        }
    }
    
    /**
     * AJAX: Validate a pattern against a phrase
     */
    public function ajax_validate_pattern(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        
        global $wpdb;
        
        $keyword = sanitize_text_field(wp_unslash($_POST['keyword'] ?? ''));
        $pattern = sanitize_text_field(wp_unslash($_POST['pattern'] ?? ''));
        $phrase = sanitize_text_field(wp_unslash($_POST['phrase'] ?? ''));
        
        if (empty($keyword) || empty($phrase)) {
            wp_send_json_error(['message' => __('Missing required fields', 'cleversay')]);
        }
        
        $search = new \CleverSay\Search();
        $result = $search->test_pattern_match($keyword, $pattern, $phrase);
        
        wp_send_json_success($result);
    }

    /**
     * AJAX: Compile a CleverSay pattern from question variations.
     * Used by the KB editor for live pattern preview as the admin types/edits
     * variations. Lightweight — no LLM, just deterministic text processing.
     */
    public function ajax_compile_pattern(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }

        $raw = $_POST['variations'] ?? [];
        if (!is_array($raw)) $raw = [];
        $vars = [];
        foreach ($raw as $v) {
            $v = trim(sanitize_textarea_field(wp_unslash((string) $v)));
            if ($v !== '') $vars[] = $v;
        }

        if (empty($vars)) {
            wp_send_json_success(['pattern' => '', 'count' => 0]);
        }

        // v4.35.0+: discriminator compiler needs keyword + sibling
        // variations to score how rare each candidate stem is. Without
        // siblings the compiler falls back to length-based heuristic
        // and emits patterns that don't reflect what's actually
        // unique about this entry within its keyword neighborhood.
        $keyword     = sanitize_text_field(wp_unslash((string) ($_POST['keyword'] ?? '')));
        $exclude_id  = absint($_POST['group_id'] ?? 0);
        $siblings    = $keyword !== '' ? $this->fetch_sibling_variations($keyword, $exclude_id) : [];

        $compiler = \CleverSay\KBPatternCompiler::from_database();

        // v4.37.29+: iterative mode. When the caller asks for it (the
        // Recompile button posts iterative=1), the compiler walks a
        // ladder of progressively tighter patterns and tests each
        // against the live KB. Stops when it finds a pattern that
        // ranks this entry as #1 for every variation, or when the
        // ladder is exhausted / the budget is hit.
        $iterative = !empty($_POST['iterative']);
        if ($iterative && $exclude_id > 0) {
            $search = new \CleverSay\Search();
            // Tester callable: returns true iff every variation ranks
            // THIS entry as #1 with the given pattern. Implemented by
            // temporarily updating the entry's sub_keyword to the
            // candidate pattern, running test_search per variation,
            // then restoring. The temp-write isolates the test from
            // sibling collisions cleanly without us having to
            // reimplement the matching logic.
            global $wpdb;
            $kb_table = $wpdb->prefix . 'cleversay_knowledge';
            $original_pattern = (string) $wpdb->get_var(
                $wpdb->prepare("SELECT sub_keyword FROM {$kb_table} WHERE id = %d", $exclude_id)
            );

            // Defense against PHP timeout / fatal during iterative
            // testing. The tester temp-writes candidate patterns to
            // the entry; if the request dies between write and
            // restore, the row stays in a bogus state. A shutdown
            // function guarantees the restore happens even on
            // timeout / fatal. Sets a flag the tester clears after
            // its in-band restore so we don't double-write.
            //
            // v4.37.31: this fixes the reported "edit shows error,
            // refresh shows entry as default" symptom — the previous
            // try/finally protected against thrown exceptions but
            // not request-level aborts.
            $shutdown_state = (object) ['restored' => false, 'pattern' => $original_pattern];
            register_shutdown_function(function () use ($wpdb, $kb_table, $exclude_id, $shutdown_state) {
                if ($shutdown_state->restored) return;
                // Only restore if the row's sub_keyword is currently
                // something other than the original — avoids an
                // unnecessary write on the happy path where in-band
                // restore already succeeded.
                $current = (string) $wpdb->get_var(
                    $wpdb->prepare("SELECT sub_keyword FROM {$kb_table} WHERE id = %d", $exclude_id)
                );
                if ($current !== $shutdown_state->pattern) {
                    $wpdb->update($kb_table, ['sub_keyword' => $shutdown_state->pattern], ['id' => $exclude_id]);
                }
            });

            $tester = function (string $candidate_pattern) use ($wpdb, $kb_table, $exclude_id, $vars, $search, $original_pattern): bool {
                if ($candidate_pattern === '') return false;
                // Apply the candidate pattern to the entry temporarily.
                $wpdb->update($kb_table, ['sub_keyword' => $candidate_pattern], ['id' => $exclude_id]);
                try {
                    foreach ($vars as $vtext) {
                        $r = $search->test_search($vtext);
                        $matches = is_array($r['matches'] ?? null) ? $r['matches'] : [];
                        if (empty($matches)) return false;

                        $top_score = 0;
                        $top_ids = [];
                        foreach ($matches as $m) {
                            $score = (int) ($m['score'] ?? 0);
                            if ($score > $top_score) {
                                $top_score = $score;
                                $top_ids   = [(int) ($m['id'] ?? 0)];
                            } elseif ($score === $top_score && $top_score > 0) {
                                $top_ids[] = (int) ($m['id'] ?? 0);
                            }
                        }
                        // Strict #1 — not tied. Tied still feels like
                        // a coin flip at runtime; we want unambiguous.
                        if (count($top_ids) !== 1 || $top_ids[0] !== $exclude_id) {
                            return false;
                        }
                    }
                    return true;
                } finally {
                    // Always restore original pattern, even on early return.
                    $wpdb->update($kb_table, ['sub_keyword' => $original_pattern], ['id' => $exclude_id]);
                }
            };

            $result = $compiler->compile_iterative($vars, $keyword, $siblings, $tester);
            // Mark the shutdown handler as no-op — in-band restores
            // ran to completion.
            $shutdown_state->restored = true;

            wp_send_json_success([
                'pattern'        => $result['pattern'],
                'count'          => count($vars),
                'sibling_count'  => count($siblings),
                'iterative'      => true,
                'status'         => $result['status'],   // 'matched' | 'no_improvement'
                'attempts'       => $result['attempts'],
                'tried'          => $result['tried'],
            ]);
        }

        $pattern = $compiler->compile($vars, $keyword, $siblings);

        wp_send_json_success([
            'pattern'        => $pattern,
            'count'          => count($vars),
            'sibling_count'  => count($siblings),
        ]);
    }

    /**
     * Return per-token compiler scoring for the inline debug panel.
     *
     * Same input shape as ajax_compile_pattern (variations, keyword,
     * group_id), but returns the structured trace instead of just
     * the pattern string. Used by the "Why this pattern?" collapsible
     * panel on the phrase-edit page.
     *
     * @since 4.37.51
     */
    public function ajax_pattern_trace(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }

        $raw = $_POST['variations'] ?? [];
        if (!is_array($raw)) $raw = [];
        $vars = [];
        foreach ($raw as $v) {
            $v = trim(sanitize_textarea_field(wp_unslash((string) $v)));
            if ($v !== '') $vars[] = $v;
        }
        if (empty($vars)) {
            wp_send_json_success(['pattern' => '', 'tokens' => [], 'summary' => 'no variations']);
        }

        $keyword    = sanitize_text_field(wp_unslash((string) ($_POST['keyword'] ?? '')));
        $exclude_id = absint($_POST['group_id'] ?? 0);
        $siblings   = $keyword !== '' ? $this->fetch_sibling_variations($keyword, $exclude_id) : [];

        $compiler = \CleverSay\KBPatternCompiler::from_database();
        $trace    = $compiler->compile_with_trace($vars, $keyword, $siblings);

        wp_send_json_success($trace);
    }

    /**
     * Recompile dry-run chunk handler.
     *
     * The page submits chunks of entry IDs (from the result of an
     * earlier "list eligible IDs" call) and receives per-entry
     * compile results. Chunked because compiling N entries can take
     * seconds on larger KBs and we don't want to block the page.
     *
     * Input:
     *   ids   - comma-separated list of entry IDs to process
     *
     * Output:
     *   results - array of {id, keyword, sub_keyword, old_pattern,
     *             new_pattern, status, would_change, error}
     *   status: 'changed' | 'unchanged' | 'empty' | 'error'
     *
     * @since 4.37.51
     */
    public function ajax_recompile_dryrun_chunk(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }

        $ids_raw = wp_unslash($_POST['ids'] ?? '');
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $ids_raw)), static fn($i) => $i > 0));
        if (empty($ids)) {
            wp_send_json_success(['results' => []]);
        }

        global $wpdb;
        $compiler = \CleverSay\KBPatternCompiler::from_database();
        $kb_table = $wpdb->prefix . 'cleversay_knowledge';

        // Pull entry rows for this chunk in one query.
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, keyword, sub_keyword
             FROM {$kb_table}
             WHERE id IN ({$placeholders})
               AND status = 'active'",
            ...$ids
        ), ARRAY_A);

        $results = [];
        foreach ($rows as $row) {
            $entry_id = (int) $row['id'];
            $keyword  = (string) $row['keyword'];
            $stored   = (string) $row['sub_keyword'];

            // Skip aadefault entries — their pattern is fixed.
            if (strtolower($stored) === 'aadefault') {
                $results[] = [
                    'id'           => $entry_id,
                    'keyword'      => $keyword,
                    'sub_keyword'  => $stored,
                    'old_pattern'  => $stored,
                    'new_pattern'  => $stored,
                    'status'       => 'unchanged',
                    'would_change' => false,
                    'reason'       => 'aadefault — pattern is fixed',
                ];
                continue;
            }

            // Pull this entry's variations.
            $variations = [];
            if (class_exists('\\CleverSay\\KBVariations')) {
                $variations = \CleverSay\KBVariations::get_texts_for_entry($entry_id);
            }
            if (empty($variations)) {
                $results[] = [
                    'id'           => $entry_id,
                    'keyword'      => $keyword,
                    'sub_keyword'  => $stored,
                    'old_pattern'  => $stored,
                    'new_pattern'  => '',
                    'status'       => 'empty',
                    'would_change' => false,
                    'reason'       => 'no variations to compile against',
                ];
                continue;
            }

            // Pull siblings (other entries under the same keyword).
            $siblings = $this->fetch_sibling_variations($keyword, $entry_id);

            try {
                $new_pattern = $compiler->compile($variations, $keyword, $siblings);
            } catch (\Throwable $e) {
                $results[] = [
                    'id'           => $entry_id,
                    'keyword'      => $keyword,
                    'sub_keyword'  => $stored,
                    'old_pattern'  => $stored,
                    'new_pattern'  => '',
                    'status'       => 'error',
                    'would_change' => false,
                    'reason'       => $e->getMessage(),
                ];
                continue;
            }

            $would_change = ($new_pattern !== '' && $new_pattern !== $stored);
            $status = $would_change ? 'changed' : ($new_pattern === '' ? 'empty' : 'unchanged');

            $results[] = [
                'id'           => $entry_id,
                'keyword'      => $keyword,
                'sub_keyword'  => $stored,
                'old_pattern'  => $stored,
                'new_pattern'  => $new_pattern,
                'status'       => $status,
                'would_change' => $would_change,
                'reason'       => '',
            ];
        }

        wp_send_json_success(['results' => $results]);
    }

    /**
     * Recompile apply handler.
     *
     * Takes a list of entry IDs the admin reviewed in dry-run and
     * confirmed for application. Re-runs compile on each (rather
     * than trusting stored dry-run output) so concurrent edits
     * don't get clobbered by stale recompile results. Applies
     * pattern updates only when the freshly-compiled pattern
     * differs from the entry's current sub_keyword.
     *
     * Input:
     *   ids - comma-separated list of entry IDs to apply
     *
     * Output:
     *   applied      - count of rows updated
     *   skipped_same - count where re-compile produced same as current
     *                  (no update needed; might mean concurrent edit
     *                  brought the entry back to the canonical form)
     *   errors       - array of {id, message}
     *
     * @since 4.37.51
     */
    public function ajax_recompile_apply(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }

        $ids_raw = wp_unslash($_POST['ids'] ?? '');
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $ids_raw)), static fn($i) => $i > 0));
        if (empty($ids)) {
            wp_send_json_success(['applied' => 0, 'skipped_same' => 0, 'errors' => []]);
        }

        global $wpdb;
        $compiler = \CleverSay\KBPatternCompiler::from_database();
        $kb_table = $wpdb->prefix . 'cleversay_knowledge';

        $applied      = 0;
        $skipped_same = 0;
        $errors       = [];

        foreach ($ids as $entry_id) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, keyword, sub_keyword FROM {$kb_table}
                  WHERE id = %d AND status = 'active'",
                $entry_id
            ), ARRAY_A);
            if (!$row) {
                $errors[] = ['id' => $entry_id, 'message' => 'entry not found or inactive'];
                continue;
            }

            $keyword = (string) $row['keyword'];
            $stored  = (string) $row['sub_keyword'];
            if (strtolower($stored) === 'aadefault') {
                $skipped_same++;
                continue; // never recompile aadefault
            }

            $variations = class_exists('\\CleverSay\\KBVariations')
                ? \CleverSay\KBVariations::get_texts_for_entry($entry_id)
                : [];
            if (empty($variations)) {
                $errors[] = ['id' => $entry_id, 'message' => 'no variations'];
                continue;
            }

            $siblings = $this->fetch_sibling_variations($keyword, $entry_id);

            try {
                $new_pattern = $compiler->compile($variations, $keyword, $siblings);
            } catch (\Throwable $e) {
                $errors[] = ['id' => $entry_id, 'message' => $e->getMessage()];
                continue;
            }

            if ($new_pattern === '') {
                $errors[] = ['id' => $entry_id, 'message' => 'compile returned empty pattern'];
                continue;
            }
            if ($new_pattern === $stored) {
                $skipped_same++;
                continue;
            }

            $upd = $wpdb->update(
                $kb_table,
                ['sub_keyword' => $new_pattern],
                ['id' => $entry_id],
                ['%s'],
                ['%d']
            );
            if ($upd === false) {
                $errors[] = ['id' => $entry_id, 'message' => $wpdb->last_error ?: 'update failed'];
                continue;
            }
            $applied++;

            // v4.37.54+: cascade reuse_sub_keyword references that
            // pointed to the old pattern. Without this, Reuse Response
            // links break silently when an admin runs Recompile All
            // and the target entry's pattern gets rewritten.
            if ($stored !== '' && $stored !== $new_pattern) {
                $wpdb->update(
                    $kb_table,
                    ['reuse_sub_keyword' => $new_pattern],
                    [
                        'reuse_keyword'     => $keyword,
                        'reuse_sub_keyword' => $stored,
                    ],
                    ['%s'],
                    ['%s', '%s']
                );
            }
        }

        wp_send_json_success([
            'applied'      => $applied,
            'skipped_same' => $skipped_same,
            'errors'       => $errors,
        ]);
    }

    /**
     * Clean legacy/Word-pasted HTML in a response.
     *
     * Mirrors the runtime cleaning in PublicFacing::clean_response_html
     * but writes the cleaned version back to the DB rather than
     * just sanitizing on output. Used by the Modernize button on
     * the phrase editor to persist cleanup so the entry's source
     * matches what users see.
     *
     * Cleanups applied (each independently safe):
     *   - Strip Office XML tags (<o:p>, <w:*>, <m:*>)
     *   - Strip class="MsoNormal", "MsoListParagraph", etc.
     *   - Strip noisy style properties (background-*, font-size,
     *     line-height, font-family, mso-*)
     *   - Strip deprecated tags (font, center, big, small) preserving
     *     their inner content
     *   - Collapse runs of &nbsp; to a single space
     *   - Remove empty <p>, <span>, <div>
     *   - Strip empty attributes
     *   - Trim outer whitespace
     *
     * Idempotent — running it on already-clean HTML is a no-op.
     *
     * @since 4.37.51
     */
    public static function modernize_response_html(string $html): string {
        if ($html === '') return $html;

        // 1. Office XML / namespace tags entirely
        $html = preg_replace('/<\/?(o|w|m):[^>]*>/i', '', $html);

        // 2. Class attributes containing Mso* (Word noise)
        $html = preg_replace('/\s*class="[^"]*Mso[^"]*"/i', '', $html);

        // 3. Noisy CSS properties inside style attributes
        $html = preg_replace_callback('/\s*style="([^"]*)"/i', static function ($m) {
            $style = $m[1];
            $noisy = [
                '/background-image\s*:[^;]+;?/i',
                '/background-position\s*:[^;]+;?/i',
                '/background-size\s*:[^;]+;?/i',
                '/background-repeat\s*:[^;]+;?/i',
                '/background-attachment\s*:[^;]+;?/i',
                '/background-origin\s*:[^;]+;?/i',
                '/background-clip\s*:[^;]+;?/i',
                '/background\s*:[^;]+;?/i',
                '/font-size\s*:[^;]+;?/i',
                '/line-height\s*:[^;]+;?/i',
                '/font-family\s*:[^;]+;?/i',
                '/color\s*:[^;]+;?/i',
                '/mso-[^:]+:[^;]+;?/i',
            ];
            foreach ($noisy as $pattern) {
                $style = preg_replace($pattern, '', $style);
            }
            $style = trim($style, "; \t");
            return $style ? ' style="' . $style . '"' : '';
        }, $html);

        // 4. Deprecated wrapper tags — keep content, drop tags.
        // Order matters: replace inner-most first so nested cases
        // resolve cleanly.
        $deprecated = ['font', 'center', 'big', 'small'];
        foreach ($deprecated as $tag) {
            $html = preg_replace('/<' . $tag . '\b[^>]*>(.*?)<\/' . $tag . '>/is', '$1', $html);
            // Stray opening/closing without pair
            $html = preg_replace('/<\/?' . $tag . '\b[^>]*>/i', '', $html);
        }

        // 5. Collapse runs of &nbsp; to a single space
        $html = preg_replace('/(&nbsp;|\xC2\xA0){2,}/u', ' ', $html);

        // 6. Empty inline containers (after the above strips, many
        // tags may have lost all content). Run twice in case
        // emptying creates new emptiness from nesting.
        for ($pass = 0; $pass < 2; $pass++) {
            $html = preg_replace('/<span[^>]*>\s*<\/span>/i', '', $html);
            $html = preg_replace('/<p[^>]*>\s*<\/p>/i', '', $html);
            $html = preg_replace('/<div[^>]*>\s*<\/div>/i', '', $html);
        }

        // 7. Strip empty attributes (style="", class="", etc.)
        $html = preg_replace('/\s+(style|class|id|lang|dir)=""/i', '', $html);

        return trim($html);
    }

    /**
     * Modernize an entry's response HTML — clean and save.
     *
     * @since 4.37.51
     */
    public function ajax_modernize_response(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }

        $entry_id = absint($_POST['entry_id'] ?? 0);

        // v4.37.57+: accept response_html directly so this works for
        // new entries (no row in DB yet). Source of truth flow:
        //   - entry_id provided AND > 0 → operate on stored DB row,
        //     write cleaned version back. Existing entry workflow.
        //   - response_html provided → operate on submitted text,
        //     return cleaned without writing. New-entry workflow;
        //     the save will commit on form submit.
        // If both are sent (e.g., admin polishes mid-edit on an
        // existing entry), prefer response_html — it represents
        // the editor's current state, which is what admin sees.
        $submitted_html = isset($_POST['response_html'])
            ? wp_kses_post(wp_unslash((string) $_POST['response_html']))
            : null;

        if ($submitted_html !== null) {
            $original = $submitted_html;
            $cleaned  = self::modernize_response_html($original);
            wp_send_json_success([
                'changed'    => $cleaned !== $original,
                'old_length' => strlen($original),
                'new_length' => strlen($cleaned),
                'response'   => $cleaned,
                'mode'       => 'editor', // hint for client: did NOT write to DB
            ]);
        }

        if ($entry_id <= 0) {
            wp_send_json_error(['message' => __('Invalid entry id or missing response_html', 'cleversay')], 400);
        }

        global $wpdb;
        $kb_table = $wpdb->prefix . 'cleversay_knowledge';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, response FROM {$kb_table} WHERE id = %d",
            $entry_id
        ), ARRAY_A);
        if (!$row) {
            wp_send_json_error(['message' => __('Entry not found', 'cleversay')], 404);
        }

        $original = (string) $row['response'];
        $cleaned  = self::modernize_response_html($original);

        // Compare by length + content. If unchanged, return early
        // so the UI can show "already clean" rather than "saved".
        if ($cleaned === $original) {
            wp_send_json_success([
                'changed'    => false,
                'old_length' => strlen($original),
                'new_length' => strlen($cleaned),
                'response'   => $cleaned,
                'mode'       => 'db',
            ]);
        }

        $upd = $wpdb->update(
            $kb_table,
            ['response' => $cleaned],
            ['id' => $entry_id],
            ['%s'],
            ['%d']
        );
        if ($upd === false) {
            wp_send_json_error([
                'message' => __('Failed to save modernized response', 'cleversay'),
                'error'   => $wpdb->last_error,
            ], 500);
        }

        wp_send_json_success([
            'changed'    => true,
            'old_length' => strlen($original),
            'new_length' => strlen($cleaned),
            'response'   => $cleaned,
            'mode'       => 'db',
        ]);
    }

    /**
     * Generate hash used to track polished state.
     *
     * Hashes the response text consistently so the runtime Polish
     * KB step can compare against the stored polished_hash and skip
     * the redundant LLM call when the response hasn't changed since
     * approval.
     *
     * @since 4.37.52
     */
    public static function compute_response_hash(string $response): string {
        // Normalize before hashing so the runtime check isn't fooled
        // by trivial whitespace differences that don't affect
        // semantics. SHA-1 is fine here — we're not securing
        // anything, just detecting "did the content change."
        //
        // v4.37.65+: also collapse inter-tag whitespace (e.g.,
        // "<ul>\n\t<li>" → "<ul><li>") because TinyMCE strips that
        // on its parse-and-reserialize round-trip. Without this,
        // the hash from Apply (over the LLM's original output with
        // formatting whitespace) wouldn't match the hash on Save
        // (over the editor's stripped version). That mismatch was
        // exactly why polished entries weren't getting marked.
        $normalized = (string) $response;
        $normalized = preg_replace('/>\s+</', '><', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim((string) $normalized);
        return sha1($normalized);
    }

    /**
     * Generate a polish preview without writing.
     *
     * Returns both the polished HTML and the original so the editor
     * can render a diff for admin review. Apply is a separate
     * action; this just returns the LLM's output.
     *
     * @since 4.37.52
     */
    public function ajax_polish_preview(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }

        $entry_id = absint($_POST['entry_id'] ?? 0);

        // v4.37.57+: support polish on new (unsaved) entries by
        // accepting response_html and variations[] directly. Same
        // dual-mode as Modernize:
        //   - response_html supplied → operate on submitted text
        //   - else fall back to entry_id → operate on stored row
        $submitted_html = isset($_POST['response_html'])
            ? wp_kses_post(wp_unslash((string) $_POST['response_html']))
            : null;
        $submitted_variations_raw = $_POST['variations'] ?? null;
        $submitted_variations = [];
        if (is_array($submitted_variations_raw)) {
            foreach ($submitted_variations_raw as $v) {
                $v = trim(sanitize_textarea_field(wp_unslash((string) $v)));
                if ($v !== '') $submitted_variations[] = $v;
            }
        }

        if ($submitted_html !== null) {
            $original   = $submitted_html;
            $variations = $submitted_variations;
        } else {
            if ($entry_id <= 0) {
                wp_send_json_error(['message' => __('Invalid entry id or missing response_html', 'cleversay')], 400);
            }
            global $wpdb;
            $kb_table = $wpdb->prefix . 'cleversay_knowledge';
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, response FROM {$kb_table} WHERE id = %d",
                $entry_id
            ), ARRAY_A);
            if (!$row) {
                wp_send_json_error(['message' => __('Entry not found', 'cleversay')], 404);
            }
            $original   = (string) $row['response'];
            $variations = class_exists('\\CleverSay\\KBVariations')
                ? \CleverSay\KBVariations::get_texts_for_entry($entry_id)
                : [];
        }

        $plain = trim(wp_strip_all_tags($original));
        if (strlen($plain) < 20) {
            wp_send_json_error([
                'message' => __('Response is too short to polish meaningfully.', 'cleversay'),
            ], 400);
        }

        if (!class_exists('\\CleverSay\\AI')) {
            wp_send_json_error(['message' => __('AI class not available', 'cleversay')], 500);
        }
        $ai = new \CleverSay\AI();
        if (!$ai->is_configured()) {
            wp_send_json_error(['message' => __('AI is not configured (check API key).', 'cleversay')], 400);
        }

        $polished = $ai->polish_response_admin($original, $variations);
        if ($polished === null) {
            wp_send_json_error(['message' => __('Polish failed (LLM error or budget exhausted).', 'cleversay')], 500);
        }

        $changed = $polished !== $original;

        // v4.37.62+: when LLM returns identical output ("already
        // well-written"), the entry is functionally polished — it's
        // passed the LLM quality bar. Mark it as polished now,
        // without requiring an Apply round-trip or relying on JS
        // state to survive across user interactions.
        //
        // v4.37.68+: also handle editor-mode (response_html sent).
        // The editor's content is what admin will save; if LLM says
        // it's already good, write it (response + hash) to DB right
        // now. If admin then edits and saves, the save replaces both
        // and the runtime re-polishes — correct behavior.
        //
        // Without this, "no changes suggested" entries depend on JS
        // to stash the hash through to form save, and that chain is
        // fragile (TinyMCE events, editor focus changes, etc. can
        // wipe the hidden field).
        $auto_marked = false;
        if (!$changed) {
            $hash = self::compute_response_hash($original);
            if ($entry_id > 0) {
                global $wpdb;
                $kb_table = $wpdb->prefix . 'cleversay_knowledge';
                if ($submitted_html === null) {
                    // DB-mode: response unchanged from DB. Just write hash.
                    $upd = $wpdb->update(
                        $kb_table,
                        ['polished_hash' => $hash],
                        ['id' => $entry_id],
                        ['%s'],
                        ['%d']
                    );
                } else {
                    // Editor-mode: write the editor content as the
                    // response AND the hash. This commits the editor's
                    // current state directly so runtime check matches.
                    // (If admin then edits and saves, save handler
                    // overwrites both — runtime would re-polish, which
                    // is correct.)
                    $upd = $wpdb->update(
                        $kb_table,
                        [
                            'response'      => $original,
                            'polished_hash' => $hash,
                        ],
                        ['id' => $entry_id],
                        ['%s', '%s'],
                        ['%d']
                    );
                }
                if ($upd !== false) {
                    $auto_marked = true;
                }
            }
            wp_send_json_success([
                'changed'     => false,
                'auto_marked' => $auto_marked,
                'hash'        => $hash,
                'original'    => $original,
                'polished'    => $polished,
                'provider'    => method_exists($ai, 'get_provider') ? $ai->get_provider() : '',
            ]);
        }

        wp_send_json_success([
            'changed'  => $changed,
            'original' => $original,
            'polished' => $polished,
            'provider' => method_exists($ai, 'get_provider') ? $ai->get_provider() : '',
        ]);
    }

    /**
     * Apply a previously-previewed polish.
     *
     * Takes the polished HTML the admin reviewed and approved and
     * writes it to the DB along with the polished_hash for runtime
     * skip-detection. We don't trust the preview blindly — we
     * recompute the hash server-side based on the polished content
     * being applied. The preview is reference only; the applied
     * content is the source of truth for the hash.
     *
     * @since 4.37.52
     */
    public function ajax_polish_apply(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }

        $entry_id = absint($_POST['entry_id'] ?? 0);
        $polished = wp_kses_post(wp_unslash((string) ($_POST['polished'] ?? '')));
        if ($polished === '') {
            wp_send_json_error(['message' => __('Invalid input', 'cleversay')], 400);
        }

        $hash = self::compute_response_hash($polished);

        // v4.37.57+: for new entries (no entry_id), don't write to
        // DB — just return the hash so the client can stash it in
        // a hidden form field. The save handler reads that field
        // and stores polished_hash on the new row at insert time.
        // Avoids creating ghost rows mid-edit.
        if ($entry_id <= 0) {
            wp_send_json_success([
                'saved'    => false,  // not yet — will be saved with the form
                'mode'     => 'pending',
                'hash'     => $hash,
                'response' => $polished,
            ]);
        }

        global $wpdb;
        $kb_table = $wpdb->prefix . 'cleversay_knowledge';

        $upd = $wpdb->update(
            $kb_table,
            [
                'response'      => $polished,
                'polished_hash' => $hash,
            ],
            ['id' => $entry_id],
            ['%s', '%s'],
            ['%d']
        );
        if ($upd === false) {
            wp_send_json_error([
                'message' => __('Failed to save polished response', 'cleversay'),
                'error'   => $wpdb->last_error,
            ], 500);
        }

        wp_send_json_success([
            'saved'    => true,
            'mode'     => 'db',
            'hash'     => $hash,
            'response' => $polished,
        ]);
    }

    /**
     * Diagnose polish state for an entry.
     *
     * Returns the stored polished_hash, the current response's
     * computed hash, whether they match, and the raw + normalized
     * response strings. Used to debug "badge missing" cases — if
     * the hashes don't match, this surfaces exactly what differs.
     *
     * @since 4.37.61
     */
    public function ajax_polish_diagnose(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }

        $entry_id = absint($_POST['entry_id'] ?? 0);
        if ($entry_id <= 0) {
            wp_send_json_error(['message' => __('Invalid entry id', 'cleversay')], 400);
        }

        global $wpdb;
        $kb_table = $wpdb->prefix . 'cleversay_knowledge';

        // v4.37.63+: explicitly check that polished_hash column
        // exists. If migration hasn't run, every other diagnostic is
        // misleading because $row['polished_hash'] will be unset and
        // we'd report "no hash stored" when reality is "schema not
        // migrated."
        $col_exists_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'polished_hash'",
            $kb_table
        ));
        $column_exists = $col_exists_count > 0;

        // Also surface the installed db version vs current
        $installed_version = (string) get_option('cleversay_db_version', '0');

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$kb_table} WHERE id = %d",
            $entry_id
        ), ARRAY_A);
        $sql_error = $wpdb->last_error;
        if (!$row) {
            wp_send_json_error([
                'message'         => __('Entry not found', 'cleversay'),
                'sql_error'       => $sql_error,
                'column_exists'   => $column_exists,
                'installed_version' => $installed_version,
                'plugin_version'  => CLEVERSAY_VERSION,
            ], 404);
        }

        $stored_hash  = (string) ($row['polished_hash'] ?? '');
        $response     = (string) ($row['response'] ?? '');
        $current_hash = self::compute_response_hash($response);
        // Match the normalization actually used inside compute_response_hash
        // so the preview shown to admin matches what was hashed.
        $normalized   = preg_replace('/>\s+</', '><', $response);
        $normalized   = preg_replace('/\s+/', ' ', (string) $normalized);
        $normalized   = trim((string) $normalized);

        // v4.37.64+: surface the last save-time debug payload for
        // this keyword if available. This is captured by the form
        // save handler to expose the hash chain at save time.
        $save_debug = null;
        $keyword_for_debug = (string) ($row['keyword'] ?? '');
        if ($keyword_for_debug !== '' && function_exists('get_transient')) {
            $save_debug = get_transient('cleversay_polish_save_debug_' . md5($keyword_for_debug));
            if (!is_array($save_debug)) $save_debug = null;
        }

        wp_send_json_success([
            'entry_id'           => $entry_id,
            'plugin_version'     => CLEVERSAY_VERSION,
            'installed_version'  => $installed_version,
            'column_exists'      => $column_exists,
            'stored_hash'        => $stored_hash,
            'stored_hash_set'    => $stored_hash !== '',
            'current_hash'       => $current_hash,
            'hashes_match'       => ($stored_hash !== '' && $stored_hash === $current_hash),
            'response_length'    => strlen($response),
            'normalized_length'  => strlen($normalized),
            'response_preview'   => mb_substr($response, 0, 200),
            'normalized_preview' => mb_substr($normalized, 0, 200),
            'available_columns'  => array_keys($row),
            'last_save_debug'    => $save_debug,
        ]);
    }

    /**
     * Tokenize a pattern string into its content stems for similarity
     * comparison.
     *
     * Patterns look like `another*&school*` or `+phrase here&class*`
     * or `cost*|price*&class*`. We split on AND/OR/`+`, strip the
     * wildcards, and return a sorted set of stems. Used by reuse-
     * repair candidate scoring.
     *
     * @since 4.37.55
     */
    public static function pattern_to_token_set(string $pattern): array {
        $tokens = [];
        // Split on AND/OR boundaries, then strip + prefixes and
        // trailing wildcards.
        foreach (preg_split('/[|&]/', $pattern) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            // +phrase form — extract each whitespace-separated word
            if (substr($part, 0, 1) === '+') {
                foreach (preg_split('/\s+/', substr($part, 1)) as $w) {
                    $w = strtolower(trim($w));
                    if ($w !== '') $tokens[$w] = true;
                }
                continue;
            }
            $part = strtolower(rtrim($part, '*'));
            if ($part !== '') $tokens[$part] = true;
        }
        return array_keys($tokens);
    }

    /**
     * Apply a reuse-repair fix.
     *
     * Takes (entry_id, new_reuse_sub_keyword) and updates the row.
     * Validates that the target exists under the entry's
     * reuse_keyword before writing.
     *
     * @since 4.37.55
     */
    public function ajax_reuse_repair_apply(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }

        $entry_id = absint($_POST['entry_id'] ?? 0);
        $new_sub  = sanitize_text_field(wp_unslash((string) ($_POST['new_sub_keyword'] ?? '')));
        if ($entry_id <= 0 || $new_sub === '') {
            wp_send_json_error(['message' => __('Invalid input', 'cleversay')], 400);
        }

        global $wpdb;
        $kb_table = $wpdb->prefix . 'cleversay_knowledge';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, reuse_keyword FROM {$kb_table} WHERE id = %d",
            $entry_id
        ), ARRAY_A);
        if (!$row) {
            wp_send_json_error(['message' => __('Source entry not found', 'cleversay')], 404);
        }
        $target_keyword = (string) $row['reuse_keyword'];
        if ($target_keyword === '') {
            wp_send_json_error(['message' => __('Source entry has no reuse_keyword set', 'cleversay')], 400);
        }

        // Confirm the target exists under the source's reuse_keyword.
        // Defends against the admin (or a script) submitting a
        // pattern that wouldn't actually resolve.
        $target_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$kb_table}
              WHERE keyword = %s AND sub_keyword = %s AND status = 'active'",
            $target_keyword, $new_sub
        ));
        if ((int) $target_exists === 0) {
            wp_send_json_error(['message' => __('Chosen target does not exist or is inactive', 'cleversay')], 400);
        }

        $upd = $wpdb->update(
            $kb_table,
            ['reuse_sub_keyword' => $new_sub],
            ['id' => $entry_id],
            ['%s'],
            ['%d']
        );
        if ($upd === false) {
            wp_send_json_error([
                'message' => __('Failed to update', 'cleversay'),
                'error'   => $wpdb->last_error,
            ], 500);
        }

        wp_send_json_success(['saved' => true, 'new_sub_keyword' => $new_sub]);
    }

    /**
     * Suggest a keyword bucket for a given variation.
     *
     * Given the text of a variation (and optionally a current
     * keyword the admin's already considering), returns up to 3
     * keyword candidates ranked by suitability, with brief
     * reasoning per candidate.
     *
     * Heuristic ranking — no LLM call:
     *   1. Tokenize the variation through the runtime pipeline
     *      (synonyms, stopwords, stemming all applied).
     *   2. For each token, compute candidate signals:
     *      - Is it already a KB keyword? (reuse-bucket bonus)
     *      - How many existing entries are under that keyword?
     *        (smaller bucket = better discriminator pool)
     *      - POS quality (nouns/verbs > adverbs > unknown)
     *      - Word position bias (verbs near start, nouns toward
     *        end of question — student questions tend to put the
     *        action verb first)
     *   3. Return the top 3 sorted by composite score.
     *
     * Why this isn't called "AI" in any deep sense: it's
     * pattern-recognition over already-known signals (POS,
     * KB membership, bucket size). A future enhancement could
     * add an LLM tiebreak for the top 2-3 candidates if their
     * scores are close, but the heuristic alone covers most
     * cases — and it's deterministic, fast, and free.
     *
     * @since 4.37.37
     */
    public function ajax_suggest_keyword(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }

        $variation = trim(sanitize_textarea_field(wp_unslash((string) ($_POST['variation'] ?? ''))));
        if ($variation === '') {
            wp_send_json_success(['suggestions' => []]);
        }

        // Tokenize through the runtime pipeline so we operate on the
        // same vocabulary the matcher will see at search time.
        $search = new \CleverSay\Search();
        $tokens = [];
        if (method_exists($search, 'compile_normalize')) {
            try {
                $tokens = $search->compile_normalize($variation);
            } catch (\Throwable $e) {
                $tokens = [];
            }
        }
        if (empty($tokens)) {
            wp_send_json_success(['suggestions' => []]);
        }

        // Pull existing KB keywords + per-keyword entry counts.
        global $wpdb;
        $kb_table = $wpdb->prefix . 'cleversay_knowledge';
        $rows = $wpdb->get_results(
            "SELECT keyword, COUNT(*) AS n
               FROM {$kb_table}
              WHERE status = 'active'
              GROUP BY keyword",
            ARRAY_A
        );
        $kb_counts = [];
        foreach (($rows ?: []) as $r) {
            $kb_counts[strtolower((string) $r['keyword'])] = (int) $r['n'];
        }

        // Score each unique token as a keyword candidate.
        $compiler = \CleverSay\KBPatternCompiler::from_database();
        $rc = new \ReflectionClass($compiler);
        $score_method = $rc->getMethod('score_candidate');
        $score_method->setAccessible(true);
        $stem_method = $rc->getMethod('stem');
        $stem_method->setAccessible(true);
        $pos_method = $rc->getMethod('pos_for');
        $pos_method->setAccessible(true);

        $candidates = [];
        $seen = [];
        foreach ($tokens as $idx => $tok) {
            $stem = $stem_method->invoke($compiler, $tok);
            if ($stem === '' || strlen($stem) < 3) continue;
            if (isset($seen[$stem])) continue;
            $seen[$stem] = true;

            // Base score from the compiler's content-quality measure.
            $base = $score_method->invoke($compiler, $stem, $tok, $idx, $tokens, 0);

            // KB-membership signal. If this stem matches an existing
            // keyword, big bonus — reusing established buckets is
            // better than scattering content across new keywords.
            $kb_count = $kb_counts[$stem] ?? 0;
            $kb_bonus = 0.0;
            $reasoning = [];
            if ($kb_count > 0) {
                // Sweet-spot: 2-15 siblings is a focused topical bucket.
                // 0 (new keyword) is a fresh bucket — viable but loses
                // the reuse benefit. >20 is a generic bucket where
                // discrimination is hard.
                if ($kb_count >= 2 && $kb_count <= 15) {
                    $kb_bonus = 4.0;
                    $reasoning[] = sprintf(
                        /* translators: %d = entry count */
                        __('Existing keyword with %d focused entries', 'cleversay'),
                        $kb_count
                    );
                } elseif ($kb_count === 1) {
                    $kb_bonus = 2.0;
                    $reasoning[] = __('Existing keyword (1 entry — could become a topic bucket)', 'cleversay');
                } else {
                    // > 15 — generic bucket, slight bonus for reuse but watch out
                    $kb_bonus = 1.0;
                    $reasoning[] = sprintf(
                        __('Generic bucket with %d entries — discrimination may be weak', 'cleversay'),
                        $kb_count
                    );
                }
            } else {
                $reasoning[] = __('New keyword (no existing bucket)', 'cleversay');
            }

            // POS quality reasoning — surface what the heuristic is
            // weighing.
            $pos = $pos_method->invoke($compiler, $stem);
            if (str_contains($pos, 'n')) {
                $reasoning[] = __('Topic noun', 'cleversay');
            } elseif (str_contains($pos, 'v')) {
                $reasoning[] = __('Action verb', 'cleversay');
            }

            $candidates[] = [
                'keyword'   => $stem,
                'score'     => $base + $kb_bonus,
                'base'      => $base,         // intrinsic score (POS, position, content quality)
                'kb_bonus'  => $kb_bonus,     // bucket-reuse bonus, separate from intrinsic
                'kb_count'  => $kb_count,
                'reasoning' => implode(' · ', $reasoning),
            ];
        }

        if (empty($candidates)) {
            wp_send_json_success(['suggestions' => [], 'source' => 'heuristic']);
        }

        usort($candidates, static fn($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($candidates, 0, 3);

        // v4.37.49+: always invoke LLM when AI is configured.
        //
        // Earlier versions tried a layered approach: run heuristic first,
        // call LLM only when heuristic seemed "uncertain." The confidence
        // check kept missing the cases where it mattered most — situations
        // where the heuristic's bucket-reuse bonus put a less-relevant
        // keyword on top because that keyword happened to be an existing
        // bucket. The rank-flip detection in v4.37.48 was supposed to
        // catch this, but it depends on the intrinsic-score calculation
        // matching admin intuition, which doesn't always hold.
        //
        // Simpler model: this is an admin-side feature used 10-50 times
        // per day. At Gemini Flash-Lite pricing (~$0.0001 per call) the
        // total cost is fractions of a cent per day. The heuristic stays
        // as the safety net when AI is unconfigured / unbudgeted /
        // returns nonsense.
        //
        // The 24h cache means repeated lookups for the same question
        // don't re-invoke LLM, so the only real cost is when admin types
        // a genuinely new question.
        $source = 'heuristic';
        $llm_used = false;

        if (class_exists('\\CleverSay\\AI')) {
            $ai = new \CleverSay\AI();
            if ($ai->is_configured()) {
                // Cache by hash of (question, candidate stems). Same
                // input = same answer for 24h. Spares repeated LLM
                // calls when admin is iterating on similar questions
                // or hits the button twice.
                $cache_input = $variation . '|' . implode(',', array_map(
                    static fn($c) => $c['keyword'],
                    $top
                ));
                $cache_key = 'cs_ksugg_' . md5($cache_input);
                $cached = get_transient($cache_key);

                if (is_array($cached)) {
                    $top = $cached['suggestions'];
                    $source = 'llm-cached';
                    $llm_used = true;
                } else {
                    $llm_result = $this->llm_rerank_keyword_candidates(
                        $ai,
                        $variation,
                        $top,
                        $kb_table
                    );
                    if ($llm_result !== null) {
                        $top = $llm_result;
                        $source = 'llm';
                        $llm_used = true;
                        set_transient($cache_key, ['suggestions' => $top], DAY_IN_SECONDS);
                    }
                }
            }
        }

        wp_send_json_success([
            'suggestions' => array_map(static function ($c) {
                return [
                    'keyword'   => $c['keyword'],
                    'score'     => round((float) $c['score'], 1),
                    'kb_count'  => (int) ($c['kb_count'] ?? 0),
                    'reasoning' => $c['reasoning'],
                    'llm'       => !empty($c['llm']),
                ];
            }, $top),
            'source'   => $source,
            'llm_used' => $llm_used,
        ]);
    }

    /**
     * Ask the LLM to re-rank the heuristic's top keyword candidates.
     *
     * Builds a prompt with:
     *   - The question being routed
     *   - The candidate keywords + their existing-bucket entry counts
     *   - 2-3 sample sibling questions per existing-bucket candidate
     *     (so the LLM understands what each bucket "feels like")
     *
     * Asks for a JSON response with the candidates re-ranked. The
     * LLM picks from the supplied list — it can't invent new
     * candidates. This keeps the system deterministic in scope while
     * letting the LLM contribute judgment about semantic fit.
     *
     * Returns the re-ranked candidates with `llm` and `reasoning`
     * fields populated. Returns null on any failure (caller falls
     * back to heuristic ranking).
     *
     * @since 4.37.38
     */
    private function llm_rerank_keyword_candidates(
        \CleverSay\AI $ai,
        string $variation,
        array $candidates,
        string $kb_table
    ): ?array {
        global $wpdb;

        // Pull 2-3 sample questions per candidate bucket so the LLM
        // has concrete examples of what each bucket contains. Fast
        // single query.
        $samples = [];
        foreach ($candidates as $c) {
            if (($c['kb_count'] ?? 0) <= 0) continue;
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT question FROM {$kb_table}
                  WHERE keyword = %s AND status = 'active'
                    AND question != ''
                    AND sub_keyword != 'aadefault'
               ORDER BY id DESC LIMIT 3",
                $c['keyword']
            ));
            $samples[$c['keyword']] = is_array($rows) ? $rows : [];
        }

        // Build prompt. Keep it short — bigger prompts cost more and
        // don't help on this task. The LLM only needs the question
        // and the candidate options.
        $candidate_lines = [];
        foreach ($candidates as $c) {
            $line = "- {$c['keyword']}";
            if (($c['kb_count'] ?? 0) > 0) {
                $line .= " (existing bucket, {$c['kb_count']} entries";
                if (!empty($samples[$c['keyword']])) {
                    $line .= "; sample questions: \""
                        . implode('", "', array_slice($samples[$c['keyword']], 0, 3))
                        . "\"";
                }
                $line .= ")";
            } else {
                $line .= " (would be a new bucket)";
            }
            $candidate_lines[] = $line;
        }

        $prompt = "Question to route: \"" . $variation . "\"

Candidate keyword buckets:
" . implode("\n", $candidate_lines) . "

Pick the SINGLE keyword that best fits this question's intent. A student would search for this question by typing one of these words. Prefer existing buckets whose sample questions share the question's topical intent. Reply with valid JSON only, no other text:

{\"keyword\": \"<chosen_keyword>\", \"reason\": \"<one short sentence>\"}";

        $response = $ai->call_for_text($prompt, [
            'max_tokens'  => 150,
            'temperature' => 0.0, // deterministic — same input → same answer
        ]);

        if ($response === '') return null;

        // Parse — the LLM may wrap JSON in code fences or add prose.
        // Find the first {...} block.
        if (!preg_match('/\{.*\}/s', $response, $m)) return null;
        $parsed = json_decode($m[0], true);
        if (!is_array($parsed) || empty($parsed['keyword'])) return null;

        $chosen = strtolower(trim((string) $parsed['keyword']));
        $reason = trim((string) ($parsed['reason'] ?? ''));

        // Verify the chosen keyword is actually one of our candidates
        // (defense against the LLM hallucinating a new keyword).
        $kw_index = null;
        foreach ($candidates as $i => $c) {
            if (strtolower($c['keyword']) === $chosen) {
                $kw_index = $i;
                break;
            }
        }
        if ($kw_index === null) return null;

        // Reorder candidates: chosen first, others preserve original order.
        $reordered = [];
        $reordered[] = array_merge($candidates[$kw_index], [
            'llm'       => true,
            'reasoning' => $reason !== ''
                ? $reason
                : ($candidates[$kw_index]['reasoning'] ?? ''),
        ]);
        foreach ($candidates as $i => $c) {
            if ($i === $kw_index) continue;
            $reordered[] = $c;
        }

        return $reordered;
    }

    /**
     * Pull all variation_text rows belonging to OTHER entries under
     * the same keyword. Used by the discriminator compiler (v4.35.0+)
     * to score how rare each candidate stem is among siblings.
     *
     * Excludes:
     *   - The current entry being edited (`$exclude_id`).
     *   - Rows with `aadefault` or empty sub_keyword (their broad
     *     phrasing isn't useful as a sibling-discrimination signal —
     *     they're catch-alls, not specific topics).
     *
     * @param string $keyword The entry's keyword.
     * @param int $exclude_id The entry id to exclude (0 for new entries).
     * @return string[]
     */
    private function fetch_sibling_variations(string $keyword, int $exclude_id): array {
        global $wpdb;
        if ($keyword === '') return [];

        $kb_table   = $wpdb->prefix . 'cleversay_knowledge';
        $vars_table = $wpdb->prefix . 'cleversay_kb_variations';

        $sql = "
            SELECT v.variation_text
              FROM {$vars_table} v
        INNER JOIN {$kb_table} k ON v.knowledge_id = k.id
             WHERE k.keyword = %s
               AND k.id != %d
               AND COALESCE(k.sub_keyword, '') NOT IN ('aadefault', '')
        ";
        $rows = $wpdb->get_col($wpdb->prepare($sql, $keyword, $exclude_id));
        if (!is_array($rows)) return [];

        return array_values(array_filter(array_map('strval', $rows)));
    }

    /**
     * AJAX: Use AI to suggest 3-5 question variations given a topic and answer.
     *
     * Distinct from the older AI Suggest which generated *patterns* directly
     * (and produced topic-non-specific results because the model couldn't
     * reliably write the pattern DSL). This endpoint asks the AI to write
     * natural-language phrasings — what it's actually good at — and the
     * pattern compiler turns them into a pattern deterministically.
     */
    public function ajax_ai_suggest_variations(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }

        if (!\CleverSay\NetworkSettings::ai_is_configured()) {
            wp_send_json_error(['message' => __('AI is not configured for this site.', 'cleversay')], 400);
        }

        $topic    = trim(sanitize_textarea_field(wp_unslash($_POST['topic']    ?? '')));
        $question = trim(sanitize_textarea_field(wp_unslash($_POST['question'] ?? '')));
        $answer   = trim(wp_kses_post(wp_unslash($_POST['answer'] ?? '')));

        // Existing variations the admin has already added — passed in
        // so the AI knows what to AVOID duplicating. v4.35.3+: this
        // endpoint emits one suggestion per call instead of four, so
        // the admin can iterate (click → review → click again) rather
        // than being handed a wall of suggestions to triage.
        $existing_raw = $_POST['existing'] ?? [];
        if (!is_array($existing_raw)) $existing_raw = [];
        $existing = [];
        $seen = [];
        foreach ($existing_raw as $e) {
            $e = trim(sanitize_textarea_field(wp_unslash((string) $e)));
            $key = strtolower($e);
            if ($e !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $existing[] = $e;
            }
        }

        if ($topic === '' && $question === '' && $answer === '') {
            wp_send_json_error(['message' => __('Please provide a topic, question, or answer first so I have something to work with.', 'cleversay')], 400);
        }

        $answer_plain = trim(wp_strip_all_tags($answer));
        $answer_plain = mb_substr($answer_plain, 0, 2000);

        $user_msg = "You're helping an admin set up a knowledge base entry for a chatbot.\n\n";
        if ($topic !== '')    $user_msg .= "Topic: {$topic}\n";
        if ($question !== '') $user_msg .= "Canonical question: {$question}\n";
        if ($answer_plain !== '') $user_msg .= "\nThe answer to this entry is:\n---\n{$answer_plain}\n---\n";

        if (!empty($existing)) {
            $user_msg .= "\nThe admin has already added these variations:\n";
            foreach ($existing as $i => $e) {
                $user_msg .= ($i + 1) . ". {$e}\n";
            }
            $user_msg .= "\nWrite ONE additional way a real student might phrase a question that this answer would address — DIFFERENT from the variations above (vary the verbs, sentence structure, and vocabulary).";
        } else {
            $user_msg .= "\nWrite ONE way a real student might phrase a question that this answer would address.";
        }

        $user_msg .= " Use natural, conversational language — how students actually type, not formal grammar. The variation must be:\n";
        $user_msg .= "- A complete question\n";
        $user_msg .= "- Specific to this topic (don't make it so generic it could match unrelated questions)\n";
        $user_msg .= "- Under 15 words\n\n";
        $user_msg .= "Return ONLY the variation text on a single line. No numbering, no markdown, no commentary, no quotes.";

        try {
            $ai = new \CleverSay\AI();
            $raw = $ai->call_for_text($user_msg, [
                'max_tokens'  => 100, // single short variation; was 400 for four
                'temperature' => 0.7,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => __('AI request failed: ', 'cleversay') . $e->getMessage(),
            ], 500);
        }

        if (empty($raw) || !is_string($raw)) {
            wp_send_json_error(['message' => __('AI returned no suggestion.', 'cleversay')], 500);
        }

        // Take the first non-empty line, strip any list/numbering decoration.
        // We ask for a single line, but defend against the model emitting
        // a stray prefix like "1. " or wrapping in quotes.
        $variation = '';
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $line = preg_replace('/^[\s]*([0-9]+[\.\)]|[\-\*\•])\s*/', '', $line);
            $line = trim($line, " \t\"'");
            if ($line === '') continue;
            $variation = mb_substr($line, 0, 500);
            break;
        }

        if ($variation === '') {
            wp_send_json_error(['message' => __('AI response could not be parsed.', 'cleversay')], 500);
        }

        // Belt-and-suspenders: refuse a duplicate even if the model
        // ignored the instruction. The client also filters, but a
        // server-side refusal lets us show a clearer error.
        $variation_lower = strtolower($variation);
        foreach ($existing as $e) {
            if (strtolower($e) === $variation_lower) {
                wp_send_json_error(['message' => __('AI returned a variation that duplicates an existing one. Try again.', 'cleversay')], 200);
            }
        }

        // Compile the pattern using the new compiler with sibling
        // awareness — same path as the live preview, so the pattern
        // returned here matches what would compile if the admin
        // accepted the suggestion.
        $variations_for_compile = array_merge($existing, [$variation]);
        $keyword_for_compile = trim(sanitize_text_field(wp_unslash((string) ($_POST['keyword'] ?? ''))));
        if ($keyword_for_compile === '') $keyword_for_compile = $topic;
        $exclude_id = absint($_POST['group_id'] ?? 0);
        $siblings   = $keyword_for_compile !== ''
            ? $this->fetch_sibling_variations($keyword_for_compile, $exclude_id)
            : [];

        $compiler = \CleverSay\KBPatternCompiler::from_database();
        $pattern  = $compiler->compile($variations_for_compile, $keyword_for_compile, $siblings);

        wp_send_json_success([
            // Single-element array for backward compat with the JS
            // shape that previously expected `variations[]`.
            'variations' => [$variation],
            'variation'  => $variation,
            'pattern'    => $pattern,
        ]);
    }
    
    /**
     * Handle saving a single phrase group
     */
    public function handle_save_phrase_group(): void {
        if (!check_admin_referer('cleversay_save_phrase_group', 'cleversay_nonce')) {
            wp_die(__('Security check failed', 'cleversay'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'cleversay'));
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_knowledge';
        
        $keyword = sanitize_text_field(wp_unslash($_POST['keyword'] ?? ''));
        $group_id = absint($_POST['group_id'] ?? 0);
        $is_new = !empty($_POST['is_new']);
        $patterns = $_POST['patterns'] ?? [];
        $response = wp_kses_post(wp_unslash($_POST['response'] ?? ''));
        $status = sanitize_text_field(wp_unslash($_POST['status'] ?? 'active'));
        $expires_at = sanitize_text_field(wp_unslash($_POST['expires_at'] ?? ''));
        $show_rating = isset($_POST['show_rating']) ? 1 : 0;

        // Variations-driven flow (v4.31.1+): when the form posts a non-empty
        // `variations[]` array, the server is the source of truth for the
        // compiled pattern. The client also supplies pre-built patterns[]
        // rows, but we recompile here because:
        //   1. The client's compiled pattern can be stale (300ms debounce
        //      means a fast click after the last keystroke posts the
        //      previous pattern).
        //   2. The client preview is informational; defending against a
        //      crafted POST with mismatched pattern/phrase is cheap.
        //
        // ONE knowledge row per phrase group when using variations. The
        // compiled pattern handles all variations comprehensively, and
        // the variations themselves live in the kb_variations table —
        // there's no point creating N rows that all carry the same
        // pattern. Earlier (v4.31.0–4.31.3) this created one row per
        // variation, which surfaced as N identical Pattern blocks in
        // the Advanced section after save. The first variation is used
        // as the representative `question` field on the row.
        //
        // 4.33.0+ refinement: if the posted variations are identical to
        // what's already stored for this entry's canonical row, skip
        // recompile entirely and preserve the existing pattern verbatim.
        // Why: soft migration (Phase B) attached variations to legacy
        // entries while keeping their hand-tuned `sub_keyword` patterns.
        // An admin opening a migrated entry and saving (without changing
        // variations) would otherwise trigger an unwanted recompile that
        // could produce a worse pattern than the legacy one, and
        // validation would likely fail. The "skip when unchanged" rule
        // means recompile only fires when the admin actually edits
        // variations — which is the moment they're consciously choosing
        // to rebuild the matching rule.
        $variations_for_validation = []; // populated below if variations posted
        $raw_variations = $_POST['variations'] ?? [];
        if (is_array($raw_variations)) {
            $cleaned_variations = [];
            foreach ($raw_variations as $v) {
                $v = trim(sanitize_textarea_field(wp_unslash((string) $v)));
                if ($v !== '') $cleaned_variations[] = $v;
            }
            if (!empty($cleaned_variations)) {
                // Detect "unchanged" against stored variations. Under
                // the per-row model (v4.34.0+), each row is its own
                // phrase group, so the canonical id is just the
                // posted group_id itself — no aggregation across
                // rows sharing a response.
                //
                // v4.37.18+: an explicit `force_recompile` form flag
                // bypasses unchanged-detection. Used by the
                // "Recompile matching rule" button on the editor —
                // when admin wants to regenerate the pattern after a
                // config change (stopwords, synonyms, compiler
                // version) without editing the variations.
                $force_recompile = !empty($_POST['force_recompile']);
                $variations_unchanged = false;
                $preserved_pattern   = null;
                if (!$force_recompile && !$is_new && $group_id && class_exists('\\CleverSay\\KBVariations')) {
                    $canonical_id = (int) $group_id;
                    $stored = \CleverSay\KBVariations::get_texts_for_entry($canonical_id);
                    if (!empty($stored)) {
                        // Order-insensitive set comparison.
                        $a = $cleaned_variations; sort($a);
                        $b = $stored;             sort($b);
                        if ($a === $b) {
                            $variations_unchanged = true;
                            $preserved_pattern = (string) $wpdb->get_var($wpdb->prepare(
                                "SELECT sub_keyword FROM {$table} WHERE id = %d",
                                $canonical_id
                            ));
                        }
                    }
                }

                if ($variations_unchanged && $preserved_pattern !== '') {
                    // Preserve the existing pattern verbatim. We
                    // still validate it against the variations
                    // (v4.37.6+) — see the validation loop below.
                    $patterns = [[
                        'id'      => '',
                        'pattern' => $preserved_pattern,
                        'phrase'  => $cleaned_variations[0],
                    ]];
                    $server_compiled_pattern   = $preserved_pattern;
                    $variations_for_validation = $cleaned_variations;
                } else {
                    // Recompile + validate path. v4.35.0+ uses the
                    // discriminator algorithm — needs keyword and
                    // sibling variations for proper scoring.
                    //
                    // v4.37.32+: if the form posted a `verified_pattern`
                    // (set by the iterative Recompile button after it
                    // finds a pattern that ranks #1), use that instead
                    // of re-deriving from scratch. Without this, the
                    // save handler would compile deterministically and
                    // produce a different (worse) pattern, throwing
                    // away the iterative work the admin just verified.
                    // The `verified_pattern` is still validated below,
                    // so a stale or crafted value can't bypass safety.
                    $verified_pattern = trim((string) ($_POST['verified_pattern'] ?? ''));
                    if ($verified_pattern !== '') {
                        $server_compiled_pattern = $verified_pattern;
                    } else {
                        $compiler = \CleverSay\KBPatternCompiler::from_database();
                        $sibling_variations = $this->fetch_sibling_variations(
                            $keyword,
                            (int) ($group_id ?? 0)
                        );
                        $server_compiled_pattern = $compiler->compile(
                            $cleaned_variations,
                            $keyword,
                            $sibling_variations
                        );
                    }
                    $patterns = [[
                        'id'      => '',
                        'pattern' => $server_compiled_pattern,
                        'phrase'  => $cleaned_variations[0],
                    ]];
                    $variations_for_validation = $cleaned_variations;
                }
            }
        }
        
        // Reuse response settings
        $reuse_response = isset($_POST['reuse_response']) ? 1 : 0;
        $reuse_keyword = sanitize_text_field(wp_unslash($_POST['reuse_keyword'] ?? ''));
        $reuse_sub_keyword = sanitize_text_field(wp_unslash($_POST['reuse_sub_keyword'] ?? ''));
        
        // If reuse_response is enabled, clear the response and validate selections
        if ($reuse_response) {
            $response = ''; // Clear the response since we're reusing
            if (empty($reuse_keyword) || empty($reuse_sub_keyword)) {
                $reuse_response = 0; // Disable if not properly set
            }
        } else {
            // Clear reuse fields if not using reuse
            $reuse_keyword = '';
            $reuse_sub_keyword = '';
        }
        
        $base_url = admin_url('admin.php?page=cleversay-knowledge');
        $detail_url = add_query_arg(['action' => 'keyword-detail', 'keyword' => urlencode($keyword)], $base_url);
        
        if (empty($keyword)) {
            wp_redirect(add_query_arg('message', 'error', $detail_url));
            exit;
        }
        
        // Validate patterns
        $validation_errors = [];
        $search = new \CleverSay\Search();

        // v4.37.34+: every entry must have something to serve when
        // its pattern matches. Either:
        //   (a) a non-empty response of its own, or
        //   (b) reuse_response pointing to another entry's response.
        //
        // Without this check, the save handler accepted entries
        // with no response — they'd save successfully but the
        // runtime matcher silently filters them at line ~2036
        // (empty response → skip pattern entirely). Effect: the
        // entry "exists" in the admin list but never returns at
        // search time, AND its sibling-pattern competition is
        // bypassed, so unrelated patterns may win queries they
        // wouldn't have. Hidden behavior the admin can't see.
        //
        // We strip HTML and whitespace before checking so that an
        // editor that auto-inserts <p></p> or &nbsp; doesn't pass
        // as content (matches the runtime's own emptiness test in
        // class-search.php to keep save-side and search-side in
        // sync).
        $response_for_check = (string) $response;
        $stripped_response  = strip_tags(html_entity_decode(
            $response_for_check,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ));
        $stripped_response  = preg_replace('/[\s\x{00A0}]+/u', '', $stripped_response);
        $response_is_empty  = ($stripped_response === '');

        if ($reuse_response) {
            // Reuse path: must have both keyword and sub_keyword set,
            // and the target entry must exist (lightweight check —
            // the runtime resolver is more thorough, but catching
            // obvious typos here saves admin a round-trip).
            if (empty($reuse_keyword) || empty($reuse_sub_keyword)) {
                $validation_errors[] = __('Reuse response is enabled, but no target entry was selected. Pick a keyword AND a phrase from the dropdowns, or uncheck "Reuse Response from another Phrase Group" and provide a response below.', 'cleversay');
            }
        } elseif ($response_is_empty) {
            $validation_errors[] = __('Response is required. Either type a response below, or check "Reuse Response from another Phrase Group" and pick a target entry.', 'cleversay');
        }
        
        foreach ($patterns as $index => $pattern_data) {
            $pattern = sanitize_text_field($pattern_data['pattern'] ?? '');
            $phrase = sanitize_text_field($pattern_data['phrase'] ?? '');
            
            if (empty($pattern) && empty($phrase)) continue;
            
            if (empty($phrase)) {
                $validation_errors[] = sprintf(__('Pattern %d: Phrase is required', 'cleversay'), $index + 1);
                continue;
            }
            
            // Skip validation for aadefault
            if ($pattern === 'aadefault') {
                if (stripos($phrase, $keyword) === false) {
                    $validation_errors[] = sprintf(
                        __('Pattern %d (aadefault): Phrase must contain the keyword "%s"', 'cleversay'),
                        $index + 1,
                        $keyword
                    );
                }
                continue;
            }
            
            if (empty($pattern)) {
                $validation_errors[] = sprintf(__('Pattern %d: Pattern is required', 'cleversay'), $index + 1);
                continue;
            }

            // v4.37.6+: Always validate on Save. Pre-4.37.6 the
            // unchanged-edit path skipped pattern validation entirely
            // on the theory that "we're not changing anything, so no
            // need to re-check." That assumption is wrong: clicking
            // Save is an explicit attestation by the admin that this
            // entry should be valid as it stands today. Surrounding
            // context (sibling entries, stopwords, synonyms, the
            // matcher itself) may have changed since the entry was
            // last saved, and a legacy import may have left invalid
            // data in place that the admin should know about.
            //
            // Note on legacy stopword interaction: the original
            // reason for the skip was that legacy patterns containing
            // question stopwords (where, how, what) would spuriously
            // fail the test pipeline because process_query stripped
            // those words. v4.37.2 disabled question-word stopwords
            // by default, so this concern is largely moot now.
            // Anyone with custom stopwords who still hits this can
            // adjust their stopword list.

            // Test pattern match
            $test_result = $search->test_pattern_match($keyword, $pattern, $phrase);
            
            if (!$test_result['matched']) {
                $validation_errors[] = sprintf(
                    __('Pattern %d ("%s"): Phrase does not match. %s', 'cleversay'),
                    $index + 1,
                    $pattern,
                    $test_result['reason'] ?? ''
                );
            }
        }

        // When variations were posted, the loop above only validated the
        // compiled pattern against the FIRST variation (the representative
        // phrase on the single knowledge row). Validate it against every
        // variation here so we catch cases like the in-state-tuition / 
        // in-state-student mismatch where one variation talks about a 
        // genuinely different sub-topic.
        if (!empty($variations_for_validation) && !empty($server_compiled_pattern)) {
            foreach ($variations_for_validation as $vi => $vtext) {
                // Skip the first — already tested by the main loop above.
                if ($vi === 0) continue;
                $r = $search->test_pattern_match($keyword, $server_compiled_pattern, $vtext);
                if (!$r['matched']) {
                    $snippet = mb_substr($vtext, 0, 60) . (mb_strlen($vtext) > 60 ? '…' : '');
                    $validation_errors[] = sprintf(
                        __('Variation %d ("%s") doesn\'t match the compiled pattern. Try rephrasing it to share more words with the others, or move it to a separate KB entry. %s', 'cleversay'),
                        $vi + 1,
                        $snippet,
                        $r['reason'] ?? ''
                    );
                }
            }
        }
        
        if (!empty($validation_errors)) {
            // v4.37.24+: also persist the submitted form input so the
            // edit page can pre-fill on the redirect-back. Without
            // this, validation failure throws the admin's typed work
            // away — the redirect-back loads the original row from
            // the database, losing variations they just added,
            // response edits, etc.
            set_transient('cleversay_validation_errors', $validation_errors, 60);
            set_transient('cleversay_form_repost', [
                'group_id'    => $group_id,
                'keyword'     => $keyword,
                'patterns'    => $patterns,
                'response'    => $response,
                'status'      => $status,
                'expires_at'  => $expires_at,
                'show_rating' => $show_rating,
                'variations'  => $_POST['variations'] ?? [],
                'variation_pattern_map' => $_POST['variation_pattern_map'] ?? [],
                'force_recompile' => !empty($_POST['force_recompile']) ? 1 : 0,
            ], 60);

            $redirect_url = $is_new 
                ? add_query_arg(['action' => 'new-phrase-group', 'keyword' => urlencode($keyword), 'message' => 'validation_failed'], $base_url)
                : add_query_arg(['action' => 'edit-phrase-group', 'keyword' => urlencode($keyword), 'group_id' => $group_id, 'message' => 'validation_failed'], $base_url);
            
            wp_redirect($redirect_url);
            exit;
        }
        
        // v4.37.31+: pre-flight check — count how many rows we'd
        // actually insert. If zero, refuse the delete-and-reinsert
        // flow. Without this, an edit save with empty $patterns
        // (which happens when a non-default entry posts empty
        // variations[]) deletes the old row and inserts nothing,
        // silently destroying the entry. Symptom: "I edited, got an
        // error, refreshed, now the entry shows as default."
        $insertable_count = 0;
        foreach ($patterns as $pd) {
            $pat = sanitize_text_field($pd['pattern'] ?? '');
            $phr = sanitize_text_field($pd['phrase'] ?? '');
            // Count rows that have BOTH a non-empty phrase AND
            // (pattern OR aadefault implicit) — same condition the
            // insert loop below uses.
            if (!empty($phr) && !empty($pat)) {
                $insertable_count++;
            }
        }
        if ($insertable_count === 0) {
            // Form posted no usable rows. Bail out before deleting.
            $validation_errors = [
                __('Save aborted: no variations or patterns were submitted. The entry was NOT modified. Please add at least one variation (or, for an aadefault entry, ensure the canonical question has the keyword in it) and try again.', 'cleversay'),
            ];
            set_transient('cleversay_validation_errors', $validation_errors, 60);
            set_transient('cleversay_form_repost', [
                'group_id'    => $group_id,
                'keyword'     => $keyword,
                'patterns'    => $patterns,
                'response'    => $response,
                'status'      => $status,
                'expires_at'  => $expires_at,
                'show_rating' => $show_rating,
                'variations'  => $_POST['variations'] ?? [],
                'variation_pattern_map' => $_POST['variation_pattern_map'] ?? [],
                'force_recompile' => !empty($_POST['force_recompile']) ? 1 : 0,
            ], 60);
            $redirect_url = $is_new
                ? add_query_arg(['action' => 'new-phrase-group', 'keyword' => urlencode($keyword), 'message' => 'validation_failed'], $base_url)
                : add_query_arg(['action' => 'edit-phrase-group', 'keyword' => urlencode($keyword), 'group_id' => $group_id, 'message' => 'validation_failed'], $base_url);
            wp_redirect($redirect_url);
            exit;
        }

        // Get existing entry for this row (to delete it before
        // reinsert). Each row is its own phrase group as of v4.34.0;
        // the previous "delete by keyword+response" logic merged
        // distinct phrase groups whose response text happened to
        // coincide.
        $old_entry_ids = [];
        // v4.37.66+: hash preservation for this handler. (Earlier
        // versions of the polish-hash logic only patched
        // handle_save_keyword, which is the new-keyword form. The
        // edit-phrase-group form runs through THIS handler and was
        // wiping polished_hash on every save — that's why no badges
        // ever appeared in practice.)
        $preserved_polished_hashes = [];
        $existing_response = '';
        if (!$is_new && $group_id) {
            $first_entry = $wpdb->get_row($wpdb->prepare(
                "SELECT id, response, reuse_response, reuse_keyword, reuse_sub_keyword,
                        hits, helpful_yes, helpful_no, polished_hash
                   FROM {$table} WHERE id = %d",
                $group_id
            ), ARRAY_A);

            if ($first_entry) {
                // Preserve the row's stats (hits / helpful counters).
                // We delete-and-reinsert so the row gets a new id;
                // the stats columns are restored on the new row from
                // this snapshot. (Auxiliary tables — variations,
                // analytics — are handled separately further down.)
                $preserved_stats = [
                    (int) $group_id => [
                        'hits'        => (int) $first_entry['hits'],
                        'helpful_yes' => (int) $first_entry['helpful_yes'],
                        'helpful_no'  => (int) $first_entry['helpful_no'],
                    ],
                ];
                $old_entry_ids = [(int) $group_id];

                // Capture polished_hash from the existing row so the
                // delete-and-reinsert can preserve the marker when
                // the response is unchanged.
                $existing_hash = (string) ($first_entry['polished_hash'] ?? '');
                if ($existing_hash !== '') {
                    $preserved_polished_hashes[$existing_hash] = true;
                }
                // Capture old response too — used below for plain-text
                // semantic comparison. TinyMCE serialize-on-save can
                // alter byte-level HTML without changing the plain text;
                // we treat that as "unchanged for polish purposes."
                $existing_response = (string) ($first_entry['response'] ?? '');

                $wpdb->delete($table, ['id' => (int) $group_id]);
            }
        }

        // Pending polished hash from the form, if Apply just stashed
        // one. Validate as a SHA-1 hex string before trusting.
        $pending_polished_hash = sanitize_text_field(wp_unslash((string) ($_POST['__pending_polished_hash'] ?? '')));
        $pending_signal = ($pending_polished_hash !== '' && preg_match('/^[a-f0-9]{40}$/i', $pending_polished_hash));
        if ($pending_signal) {
            $preserved_polished_hashes[$pending_polished_hash] = true;
        }

        // Compute submitted response's hash; if it matches any
        // preserved hash, restore it on the new row.
        //
        // v4.37.67+: when the form sent a pending hash (admin clicked
        // Apply this session), trust the intent and store the live
        // response's hash even if it doesn't byte-equal the pending
        // value. Reason: TinyMCE's serialize-on-save can produce
        // slightly different HTML than setContent received, so the
        // pending hash and the live hash can diverge by a whitespace
        // or attribute-quote without any semantic change. Without
        // this, every save broke preservation and no marker ever
        // stuck. The input-handler hash-wipe (with the v4.37.67
        // suppression flag) still ensures admin edits clear the
        // pending signal, so we won't mark edited content.
        $response_hash  = self::compute_response_hash($response);
        $preserved_hash = isset($preserved_polished_hashes[$response_hash]) ? $response_hash : null;
        if ($preserved_hash === null && $pending_signal) {
            // Admin just polished. Mark as polished using the
            // live response hash so the runtime check matches.
            $preserved_hash = $response_hash;
        }
        // v4.37.68+: plain-text fallback. If we had a polished_hash
        // before AND the saved response's plain text is identical to
        // the prior plain text (only HTML serialization differs),
        // treat the entry as still-polished and store the live hash.
        // Catches the most common case admins hit: TinyMCE's
        // serialize-on-save reformats HTML between Apply and Save,
        // breaking byte-level hash equality without any semantic
        // change.
        if ($preserved_hash === null && $existing_hash !== '' && $existing_response !== '') {
            $old_plain = preg_replace('/\s+/', ' ', trim(wp_strip_all_tags($existing_response)));
            $new_plain = preg_replace('/\s+/', ' ', trim(wp_strip_all_tags($response)));
            if ($old_plain !== '' && $old_plain === $new_plain) {
                $preserved_hash = $response_hash;
            }
        }

        // Capture diagnostic info for the polish-state debug tool.
        // (Same shape as the new-keyword save handler, so the
        // diagnose tool can read either.)
        if (function_exists('set_transient')) {
            set_transient(
                'cleversay_polish_save_debug_' . md5($keyword),
                [
                    'timestamp'         => current_time('mysql'),
                    'keyword'           => $keyword,
                    'pending_from_form' => $pending_polished_hash,
                    'preserved_map'     => array_keys($preserved_polished_hashes),
                    'response_hash'     => $response_hash,
                    'preserved_chosen'  => $preserved_hash,
                    'response_length'   => strlen($response),
                    'response_preview'  => mb_substr($response, 0, 120),
                    'handler'           => 'handle_save_phrase_group',
                ],
                15 * MINUTE_IN_SECONDS
            );
        }
        
        // Insert new entries
        foreach ($patterns as $pattern_data) {
            $pattern = sanitize_text_field($pattern_data['pattern'] ?? '');
            $phrase = sanitize_text_field($pattern_data['phrase'] ?? '');
            $entry_id = !empty($pattern_data['id']) ? absint($pattern_data['id']) : null;

            if (empty($phrase)) continue;

            $stats = ($entry_id && isset($preserved_stats[$entry_id]))
                ? $preserved_stats[$entry_id]
                : ['hits' => 0, 'helpful_yes' => 0, 'helpful_no' => 0];
            
            $wpdb->insert($table, [
                'keyword'          => $keyword,
                'sub_keyword'      => $pattern ?: 'aadefault',
                'question'         => $phrase,
                'response'         => $response,
                'polished_hash'    => $preserved_hash,
                'status'           => $status,
                'expires_at'       => $expires_at ?: null,
                'show_rating'      => $show_rating,
                'reuse_response'   => $reuse_response,
                'reuse_keyword'    => $reuse_keyword ?: null,
                'reuse_sub_keyword'=> $reuse_sub_keyword ?: null,
                'hits'             => $stats['hits'],
                'helpful_yes'      => $stats['helpful_yes'],
                'helpful_no'       => $stats['helpful_no'],
                'created_at'       => current_time('mysql'),
                'updated_at'       => current_time('mysql'),
            ]);

            // Track inserted IDs so we can attach variations to the canonical
            // (first) entry of the group below.
            if (!isset($first_inserted_id)) {
                $first_inserted_id = (int) $wpdb->insert_id;
            }
        }

        // Persist variations against the canonical entry of the group, if
        // the form posted any. Variations are stored separately so they
        // round-trip into the editor on next load.
        if (isset($first_inserted_id) && $first_inserted_id > 0
            && class_exists('\\CleverSay\\KBVariations')
        ) {
            $raw_vars = $_POST['variations'] ?? [];
            if (!is_array($raw_vars)) $raw_vars = [];
            $cleaned = [];
            foreach ($raw_vars as $v) {
                $v = trim(sanitize_textarea_field(wp_unslash((string) $v)));
                if ($v !== '') $cleaned[] = $v;
            }
            \CleverSay\KBVariations::replace_all($first_inserted_id, $cleaned);

            // Cascade: drop variation rows attached to the OLD entry ids
            // (the ones we just removed from cleversay_knowledge above).
            // Exclude the new canonical id in case autoincrement happened
            // to recycle a removed id — we don't want to nuke what we
            // just wrote.
            if (!empty($old_entry_ids)) {
                $stale = array_filter(
                    $old_entry_ids,
                    fn($id) => (int) $id !== $first_inserted_id
                );
                if (!empty($stale)) {
                    \CleverSay\KBVariations::delete_for_entries($stale);
                }
            }
        }

        // v4.37.28+: round-trip ranking check.
        //
        // Pattern-level validation (above) confirms each variation
        // satisfies its compiled pattern — necessary but not
        // sufficient. It doesn't tell us whether the LIVE matcher
        // would actually rank THIS entry as the top result. Vocabulary
        // drift between variations and the runtime pipeline (synonym
        // mismatches, generic discriminators sibling entries also
        // satisfy, fallbacks to aadefault) can cause an entry to
        // validate locally but lose globally.
        //
        // Behavior:
        //   1. Test each variation through test_search() against the
        //      live KB.
        //   2. Bucket per-variation: top / tied / listed / missing.
        //   3. If anything's not 'top', set a transient and redirect
        //      to the editor with a banner showing the per-variation
        //      outcomes plus "Save anyway" / "Edit" buttons.
        //   4. The row IS already saved at this point. "Save anyway"
        //      dismisses the banner; "Edit" returns to the form for
        //      revision. Either way the admin keeps moving.
        //
        // Skipped if `skip_roundtrip` is posted (the form's "Save
        // anyway" action).
        $skip_roundtrip = !empty($_POST['skip_roundtrip']);
        if (!$skip_roundtrip
            && isset($first_inserted_id) && $first_inserted_id > 0
            && !empty($variations_for_validation)
        ) {
            $rt_results = [];
            foreach ($variations_for_validation as $vtext) {
                $vtext = trim((string) $vtext);
                if ($vtext === '') continue;

                $r = $search->test_search($vtext);
                $matches = is_array($r['matches'] ?? null) ? $r['matches'] : [];
                
                // Find this entry in the matches and record the bucket.
                $top_score = 0;
                $top_ids   = [];
                foreach ($matches as $m) {
                    $score = (int) ($m['score'] ?? 0);
                    if ($score > $top_score) {
                        $top_score = $score;
                        $top_ids   = [(int) ($m['id'] ?? 0)];
                    } elseif ($score === $top_score && $top_score > 0) {
                        $top_ids[] = (int) ($m['id'] ?? 0);
                    }
                }
                $entry_in_matches = false;
                foreach ($matches as $m) {
                    if ((int) ($m['id'] ?? 0) === $first_inserted_id) {
                        $entry_in_matches = true;
                        break;
                    }
                }

                if (in_array($first_inserted_id, $top_ids, true) && count($top_ids) === 1) {
                    $bucket = 'top';
                    $top_entry = null;
                } elseif (in_array($first_inserted_id, $top_ids, true)) {
                    $bucket = 'tied';
                    // Find which OTHER entries are tied at the top
                    $other_ids = array_values(array_filter($top_ids, fn($id) => $id !== $first_inserted_id));
                    $top_entry = $this->describe_entry_for_report($other_ids[0] ?? 0);
                } elseif ($entry_in_matches) {
                    $bucket = 'listed';
                    $top_entry = $this->describe_entry_for_report($top_ids[0] ?? 0);
                } else {
                    $bucket = 'missing';
                    $top_entry = $top_ids
                        ? $this->describe_entry_for_report($top_ids[0])
                        : null;
                }

                $rt_results[] = [
                    'variation'  => $vtext,
                    'bucket'     => $bucket,
                    'score'      => $top_score,
                    'top_entry'  => $top_entry, // null if THIS entry won outright
                ];
            }

            // If anything fell short of "top," show the report page.
            $needs_review = false;
            foreach ($rt_results as $r) {
                if ($r['bucket'] !== 'top') {
                    $needs_review = true;
                    break;
                }
            }
            if ($needs_review) {
                set_transient('cleversay_roundtrip_report', [
                    'entry_id'   => $first_inserted_id,
                    'keyword'    => $keyword,
                    'group_id'   => $first_inserted_id,
                    'results'    => $rt_results,
                ], 300); // 5 min — admin reads + decides
                wp_redirect(add_query_arg([
                    'action'   => 'edit-phrase-group',
                    'keyword'  => urlencode($keyword),
                    'group_id' => $first_inserted_id,
                    'message'  => 'roundtrip_review',
                ], $base_url));
                exit;
            }
        }

        wp_redirect(add_query_arg('message', 'group_saved', $detail_url));
        exit;
    }

    /**
     * Render a small descriptor of an entry id for the round-trip
     * report. Returns ['id', 'keyword', 'sub_keyword', 'question'].
     *
     * @since 4.37.27
     */
    private function describe_entry_for_report(int $entry_id): ?array {
        if ($entry_id <= 0) return null;
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, keyword, sub_keyword, question
                   FROM {$wpdb->prefix}cleversay_knowledge
                  WHERE id = %d",
                $entry_id
            ),
            ARRAY_A
        );
        if (!$row) return null;
        return [
            'id'          => (int) $row['id'],
            'keyword'     => (string) $row['keyword'],
            'sub_keyword' => (string) ($row['sub_keyword'] ?: 'aadefault'),
            'question'    => (string) ($row['question'] ?? ''),
        ];
    }

    /**
     * Handle "Save anyway" from the round-trip report banner.
     *
     * The row was already committed to the database by
     * handle_save_phrase_group — we just need to redirect the admin
     * back to the keyword detail page so they don't sit on an old
     * post-save URL. The transient is auto-consumed when the edit
     * page renders, so there's no extra cleanup required here.
     *
     * @since 4.37.28
     */
    public function handle_dismiss_roundtrip(): void {
        if (!check_admin_referer('cleversay_dismiss_roundtrip', 'cleversay_nonce')) {
            wp_die(__('Security check failed', 'cleversay'));
        }
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'cleversay'));
        }

        $keyword = sanitize_text_field(wp_unslash($_POST['keyword'] ?? ''));
        delete_transient('cleversay_roundtrip_report');

        $detail_url = add_query_arg([
            'page'    => 'cleversay-knowledge',
            'action'  => 'keyword-detail',
            'keyword' => urlencode($keyword),
            'message' => 'group_saved',
        ], admin_url('admin.php'));

        wp_redirect($detail_url);
        exit;
    }
    
    /**
     * Handle updating just the keyword name
     */
    public function handle_update_keyword(): void {
        if (!check_admin_referer('cleversay_update_keyword', 'cleversay_nonce')) {
            wp_die(__('Security check failed', 'cleversay'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'cleversay'));
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_knowledge';
        
        $old_keyword = sanitize_text_field(wp_unslash($_POST['old_keyword'] ?? ''));
        $new_keyword = sanitize_text_field(wp_unslash($_POST['new_keyword'] ?? ''));
        
        $base_url = admin_url('admin.php?page=cleversay-knowledge');
        
        if (empty($old_keyword) || empty($new_keyword)) {
            wp_redirect(add_query_arg('message', 'error', $base_url));
            exit;
        }
        
        // Update all entries with the old keyword to the new keyword
        $wpdb->update(
            $table,
            ['keyword' => $new_keyword, 'updated_at' => current_time('mysql')],
            ['keyword' => $old_keyword]
        );
        
        $detail_url = add_query_arg(['action' => 'keyword-detail', 'keyword' => urlencode($new_keyword), 'message' => 'keyword_updated'], $base_url);
        wp_redirect($detail_url);
        exit;
    }
    
    /**
     * Handle respond to inquiry form submission
     */
    public function handle_respond_inquiry(): void {
        if (!check_admin_referer('cleversay_respond_inquiry', 'cleversay_nonce')) {
            wp_die(__('Security check failed', 'cleversay'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'cleversay'));
        }
        
        global $wpdb;
        $inquiries_table = $wpdb->prefix . 'cleversay_inquiries';
        
        $inquiry_id = absint($_POST['inquiry_id'] ?? 0);
        $response = sanitize_textarea_field(wp_unslash($_POST['response'] ?? ''));
        $send_email = isset($_POST['send_email']) && $_POST['send_email'] === '1';
        
        $base_url = admin_url('admin.php?page=cleversay-inquiries');
        
        if (empty($inquiry_id) || empty($response)) {
            wp_redirect(add_query_arg('error', 'missing_data', $base_url));
            exit;
        }
        
        // Get the inquiry
        $inquiry = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$inquiries_table} WHERE id = %d",
            $inquiry_id
        ), ARRAY_A);
        
        if (!$inquiry) {
            wp_redirect(add_query_arg('error', 'not_found', $base_url));
            exit;
        }
        
        // Update the inquiry with the response
        $wpdb->update(
            $inquiries_table,
            [
                'response' => $response,
                'status' => 'answered',
                'responded_by' => get_current_user_id(),
                'responded_at' => current_time('mysql')
            ],
            ['id' => $inquiry_id],
            ['%s', '%s', '%d', '%s'],
            ['%d']
        );
        
        // Send email if requested and email exists
        if ($send_email && !empty($inquiry['email'])) {
            $this->send_inquiry_response_email($inquiry, $response);
        }
        
        wp_redirect(add_query_arg('message', 'responded', $base_url));
        exit;
    }
    
    /**
     * Send response email to inquiry submitter
     */
    private function send_inquiry_response_email(array $inquiry, string $response): bool {
        $to = $inquiry['email'];
        $subject = sprintf(
            __('Response to your question: %s', 'cleversay'),
            wp_trim_words($inquiry['question'], 10, '...')
        );
        
        $site_name = get_bloginfo('name');
        
        $message = sprintf(
            __("Hello %s,\n\nThank you for your question. Here is our response:\n\n---\n\nYour Question:\n%s\n\n---\n\nOur Response:\n%s\n\n---\n\nBest regards,\n%s", 'cleversay'),
            !empty($inquiry['name']) ? $inquiry['name'] : __('there', 'cleversay'),
            $inquiry['question'],
            $response,
            $site_name
        );
        
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>'
        ];
        
        return wp_mail($to, $subject, $message, $headers);
    }
    
    /**
     * AJAX handler for deleting a phrase group
     */
    public function ajax_delete_phrase_group(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')]);
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_knowledge';
        
        $group_id = absint($_POST['group_id'] ?? 0);
        $keyword = sanitize_text_field(wp_unslash($_POST['keyword'] ?? ''));
        
        if (empty($group_id) || empty($keyword)) {
            wp_send_json_error(['message' => __('Missing required fields', 'cleversay')]);
        }
        
        // Get the entry to find its response. Pull id explicitly so the
        // DELETE below has an id to target — pre-v4.37.16 this row
        // selected only `response, sub_keyword`, leaving $entry['id']
        // undefined and the subsequent DELETE running against id=0
        // (matching nothing). v4.37.15 then treated the 0-rows-affected
        // result as success, silently reporting "deleted" while the
        // row stayed in place.
        $entry = $wpdb->get_row($wpdb->prepare(
            "SELECT id, response, sub_keyword FROM {$table} WHERE id = %d",
            $group_id
        ), ARRAY_A);
        
        if (!$entry) {
            wp_send_json_error(['message' => __('Group not found', 'cleversay')]);
        }
        
        // Don't allow deleting the default group
        if (strtolower(trim($entry['sub_keyword'])) === 'aadefault') {
            wp_send_json_error(['message' => __('Cannot delete the default phrase group', 'cleversay')]);
        }

        // Per-row model (v4.34.0+): each row is its own phrase group.
        // Delete only this row, cascading variations cleanup.
        $deleted_ids = [(int) $entry['id']];

        $deleted = $wpdb->delete($table, ['id' => (int) $entry['id']]);

        // wpdb->delete returns:
        //   - int (rows affected) on success
        //   - false on SQL error.
        if ($deleted === false) {
            // True DB error — surface what wpdb captured for diagnosis.
            $err = trim((string) $wpdb->last_error);
            wp_send_json_error([
                'message' => __('Failed to delete phrase group', 'cleversay')
                          . ($err !== '' ? ': ' . $err : ''),
                'wpdb_error' => $err,
            ]);
        }

        // 0 rows affected SHOULD be impossible here because we just
        // SELECTed by id above and confirmed the row exists. If it
        // does happen, verify the row is actually gone — otherwise
        // we'd silently report success while the row remains (the
        // bug v4.37.15 introduced before v4.37.16 fixed the
        // missing-id-in-SELECT root cause).
        if ($deleted === 0) {
            $still_there = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE id = %d",
                (int) $entry['id']
            ));
            if ($still_there > 0) {
                $err = trim((string) $wpdb->last_error);
                wp_send_json_error([
                    'message'   => __('Phrase group still present after delete attempt — no rows affected and no SQL error reported. Possible permission issue or DB-level filter.', 'cleversay')
                              . ($err !== '' ? ' wpdb_error: ' . $err : ''),
                    'wpdb_error'=> $err,
                    'group_id'  => (int) $entry['id'],
                ]);
            }
            // else: row genuinely gone (concurrent delete) — fall through to success.
        }

        // Success path. Cascade variation cleanup.
        if (class_exists('\\CleverSay\\KBVariations') && !empty($deleted_ids)) {
            \CleverSay\KBVariations::delete_for_entries($deleted_ids);
        }
        wp_send_json_success(['message' => __('Phrase group deleted', 'cleversay')]);
    }
    
    /**
     * AJAX handler for getting response preview (for reuse response feature)
     */
    public function ajax_get_response_preview(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')]);
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_knowledge';
        
        $keyword = sanitize_text_field(wp_unslash($_POST['keyword'] ?? ''));
        $sub_keyword = sanitize_text_field(wp_unslash($_POST['sub_keyword'] ?? ''));
        
        if (empty($keyword)) {
            wp_send_json_error(['message' => __('Keyword required', 'cleversay')]);
        }
        
        // Get the response for this keyword/sub_keyword combination
        $entry = $wpdb->get_row($wpdb->prepare(
            "SELECT response, question FROM {$table} WHERE keyword = %s AND (sub_keyword = %s OR (sub_keyword IS NULL AND %s = 'aadefault')) LIMIT 1",
            $keyword,
            $sub_keyword,
            $sub_keyword
        ), ARRAY_A);
        
        if ($entry) {
            wp_send_json_success([
                'response' => wp_kses_post($entry['response']),
                'question' => $entry['question']
            ]);
        } else {
            wp_send_json_error(['message' => __('Entry not found', 'cleversay')]);
        }
    }
    
    /**
     * AJAX handler for saving keyword synonyms
     */
    public function ajax_save_keyword_synonyms(): void {
        check_ajax_referer('cleversay_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')]);
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_synonyms';
        $knowledge_table = $wpdb->prefix . 'cleversay_knowledge';
        
        $keyword = sanitize_text_field(wp_unslash($_POST['keyword'] ?? ''));
        $variants = sanitize_text_field(wp_unslash($_POST['variants'] ?? ''));
        $misspellings = sanitize_text_field(wp_unslash($_POST['misspellings'] ?? ''));
        
        if (empty($keyword)) {
            wp_send_json_error(['message' => __('Keyword is required', 'cleversay')]);
        }
        
        $canonical_word = strtolower($keyword);
        
        // Clean up the comma-separated values
        $variant_array = array_filter(array_map('trim', explode(',', $variants)));
        $misspelling_array = array_filter(array_map('trim', explode(',', $misspellings)));
        
        // Check if any synonym word is already a keyword in the knowledge base
        $all_synonyms = array_merge($variant_array, $misspelling_array);
        if (!empty($all_synonyms)) {
            // Get all existing keywords
            $existing_keywords = $wpdb->get_col(
                "SELECT DISTINCT LOWER(keyword) FROM {$knowledge_table}"
            );
            
            $conflicts = [];
            foreach ($all_synonyms as $syn) {
                $syn_lower = strtolower($syn);
                if (in_array($syn_lower, $existing_keywords) && $syn_lower !== $canonical_word) {
                    $conflicts[] = $syn;
                }
            }
            
            if (!empty($conflicts)) {
                wp_send_json_error([
                    'message' => sprintf(
                        __('The following words are already keywords and cannot be used as synonyms: %s. This would cause search conflicts.', 'cleversay'),
                        implode(', ', $conflicts)
                    )
                ]);
            }
        }
        
        $variants = implode(', ', $variant_array);
        $misspellings = implode(', ', $misspelling_array);
        
        // Check if synonym entry already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE canonical_word = %s",
            $canonical_word
        ));
        
        if ($existing) {
            // Update existing
            if (empty($variants) && empty($misspellings)) {
                // Delete if both are empty
                $wpdb->delete($table, ['id' => $existing]);
            } else {
                $wpdb->update(
                    $table,
                    [
                        'variant_words' => $variants ?: null,
                        'misspellings' => $misspellings ?: null,
                        'updated_at' => current_time('mysql'),
                    ],
                    ['id' => $existing]
                );
            }
        } else {
            // Insert new only if we have something to save
            if (!empty($variants) || !empty($misspellings)) {
                $wpdb->insert($table, [
                    'canonical_word' => $canonical_word,
                    'variant_words' => $variants ?: null,
                    'misspellings' => $misspellings ?: null,
                    'is_phrase' => 0,
                    'is_active' => 1,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]);
            }
        }
        
        $this->flush_search_cache();
        wp_send_json_success(['message' => __('Synonyms saved', 'cleversay')]);
    }

    // =========================================================================
    // AI Sources AJAX handlers
    // =========================================================================

    private function require_ai_sources(): \CleverSay\Sources {
        return new \CleverSay\Sources();
    }

    public function ajax_add_source_url(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied'], 403);

        $url   = esc_url_raw(wp_unslash($_POST['url']   ?? ''));
        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));

        if (empty($url)) {
            wp_send_json_error(['message' => __('Please enter a valid URL.', 'cleversay')]);
        }

        // Normalise the URL before checking for duplicates — "page" and
        // "page/" must be treated as the same, matching how add_url stores.
        $normalised = \CleverSay\Crawler::normalise($url);

        // Check for duplicate before adding
        global $wpdb;
        $db       = new \CleverSay\Database();
        $existing = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$db->sources} WHERE url = %s LIMIT 1", $normalised)
        );
        if ($existing) {
            wp_send_json_error([
                'code'    => 'already_exists',
                'message' => __('This URL is already in your sources. Use Re-index if you want to refresh it.', 'cleversay'),
            ]);
        }

        $id = $this->require_ai_sources()->add_url($url, $title);
        if ($id === false) {
            wp_send_json_error(['message' => __('Failed to add URL.', 'cleversay')]);
        }

        $source = $this->require_ai_sources()->get($id);
        wp_send_json_success([
            'message' => __('URL added. Indexing in progress…', 'cleversay'),
            'source'  => $source,
        ]);
    }

    public function ajax_add_source_file(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied'], 403);

        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => __('No file received.', 'cleversay')]);
        }

        $id = $this->require_ai_sources()->add_file($_FILES['file']);
        if ($id === false) {
            wp_send_json_error(['message' => __('File upload failed. Supported: PDF, DOCX, TXT.', 'cleversay')]);
        }

        $source = $this->require_ai_sources()->get($id);
        wp_send_json_success([
            'message' => __('File uploaded. Indexing in progress…', 'cleversay'),
            'source'  => $source,
        ]);
    }

    public function ajax_add_source_text(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied'], 403);

        $title   = sanitize_text_field(wp_unslash($_POST['title']   ?? ''));
        $content = sanitize_textarea_field(wp_unslash($_POST['content'] ?? ''));

        if (empty($title) || empty($content)) {
            wp_send_json_error(['message' => __('Title and content are required.', 'cleversay')]);
        }

        $id = $this->require_ai_sources()->add_text($content, $title);
        if ($id === false) {
            wp_send_json_error(['message' => __('Failed to add text.', 'cleversay')]);
        }

        $source = $this->require_ai_sources()->get($id);
        wp_send_json_success([
            'message' => __('Text added and indexed.', 'cleversay'),
            'source'  => $source,
        ]);
    }

    public function ajax_delete_source(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied'], 403);

        $id = absint($_POST['source_id'] ?? 0);
        if (!$id) wp_send_json_error(['message' => 'Invalid ID']);

        $ok = $this->require_ai_sources()->delete($id);
        if ($ok) {
            wp_send_json_success(['message' => __('Source deleted.', 'cleversay')]);
        } else {
            wp_send_json_error(['message' => __('Could not delete source.', 'cleversay')]);
        }
    }

    public function ajax_bulk_delete_sources(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied'], 403);

        $ids = array_filter(array_map('absint', (array) ($_POST['ids'] ?? [])));
        if (empty($ids)) wp_send_json_error(['message' => 'No IDs provided.']);

        $sources = $this->require_ai_sources();
        $deleted = 0;
        foreach ($ids as $id) {
            if ($sources->delete($id)) $deleted++;
        }
        wp_send_json_success([
            'deleted' => $deleted,
            'message' => sprintf(_n('%d source deleted.', '%d sources deleted.', $deleted, 'cleversay'), $deleted),
        ]);
    }

    public function ajax_reindex_source(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied'], 403);

        $id = absint($_POST['source_id'] ?? 0);
        if (!$id) wp_send_json_error(['message' => 'Invalid ID']);

        $ok = $this->require_ai_sources()->reindex($id);
        if ($ok) {
            wp_send_json_success(['message' => __('Re-indexing started.', 'cleversay')]);
        } else {
            wp_send_json_error(['message' => __('Could not re-index source.', 'cleversay')]);
        }
    }

    public function ajax_set_source_refresh_interval(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied'], 403);

        $id       = absint($_POST['source_id'] ?? 0);
        $interval = sanitize_text_field($_POST['interval'] ?? '');
        if (!$id) wp_send_json_error(['message' => 'Invalid ID']);

        $ok = $this->require_ai_sources()->set_refresh_interval($id, $interval);
        if ($ok) {
            wp_send_json_success(['message' => __('Refresh schedule updated.', 'cleversay'), 'interval' => $interval]);
        } else {
            wp_send_json_error(['message' => __('Invalid interval.', 'cleversay')]);
        }
    }

    public function ajax_get_source_status(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied'], 403);

        $id     = absint($_POST['source_id'] ?? 0);
        $source = $this->require_ai_sources()->get($id);
        if (!$source) wp_send_json_error(['message' => 'Not found']);

        // Format crawl meta for inline display (X ago + status dot)
        $crawled_ago = null;
        if (!empty($source['last_crawled_at'])) {
            $crawled_ago = human_time_diff(
                strtotime($source['last_crawled_at']),
                current_time('timestamp')
            );
        }

        wp_send_json_success([
            'status'          => $source['status'],
            'chunk_count'     => (int) $source['chunk_count'],
            'word_count'      => (int) $source['word_count'],
            'error'           => $source['error_message'],
            'last_crawled_at' => $source['last_crawled_at'] ?? null,
            'crawled_ago'     => $crawled_ago,
            'crawl_status'    => $source['crawl_status'] ?? null,
            'crawl_error'     => $source['crawl_error']  ?? null,
        ]);
    }

    public function ajax_test_api_key(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied'], 403);

        // Use strip_tags + trim only — sanitize_text_field can corrupt API key characters
        $key = trim(strip_tags(wp_unslash($_POST['api_key'] ?? '')));
        if (empty($key)) {
            wp_send_json_error(['message' => __('No API key provided.', 'cleversay')]);
        }

        // v4.37.74+: respect explicit provider param so admin can test
        // a Gemini key even when current model is a Claude one (and
        // vice versa). Falls back to current model's provider.
        $provider = sanitize_text_field(wp_unslash($_POST['provider'] ?? ''));
        $ai     = new \CleverSay\AI();
        $result = $ai->test_api_key_with_message($key, $provider);
        if ($result['success']) {
            wp_send_json_success(['message' => __('API key is valid!', 'cleversay')]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    /**
     * Test the API key already stored in the database — key never leaves the server.
     */
    public function ajax_test_stored_api_key(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Permission denied'], 403);

        $cfg = \CleverSay\NetworkSettings::get_ai_config();
        // v4.37.74+: pick the right key for the requested provider.
        // If no provider supplied, use the active one (legacy behavior).
        $provider = sanitize_text_field(wp_unslash($_POST['provider'] ?? ''));
        if ($provider === 'anthropic') {
            $key = (string) ($cfg['anthropic_api_key'] ?? '');
        } elseif ($provider === 'gemini') {
            $key = (string) ($cfg['gemini_api_key']    ?? '');
        } else {
            $key = (string) ($cfg['api_key'] ?? '');
        }
        if (empty($key)) {
            wp_send_json_error(['message' => __('No API key saved yet.', 'cleversay')]);
        }

        $ai     = new \CleverSay\AI();
        $result = $ai->test_api_key_with_message($key, $provider);
        if ($result['success']) {
            wp_send_json_success(['message' => __('API key is valid!', 'cleversay')]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }


    /**
     * AJAX: Full AI pipeline diagnostic
     * Tests each step so admin can see exactly where it fails
     */
    /**
     * Run the URL extraction diagnostic and return the structured report
     * for display in the admin UI. Used by the "Test Extraction" widget on
     * the AI Sources page.
     */
    public function ajax_diagnose_url(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }

        $url = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            wp_send_json_error(['message' => 'A valid URL is required'], 400);
        }

        try {
            $indexer = new \CleverSay\Indexer();
            $report  = $indexer->diagnose_url($url);
            wp_send_json_success($report);
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => 'Diagnostic threw an exception: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function ajax_ai_diagnostic(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }

        $steps = [];

        // Step 1: Check options
        $ai_cfg     = \CleverSay\NetworkSettings::get_ai_config();
        $api_key    = $ai_cfg['api_key'];
        $ai_enabled = $ai_cfg['enabled'];
        $steps[] = [
            'label'  => 'AI Enabled setting',
            'value'  => var_export($ai_enabled, true),
            'pass'   => !empty($ai_enabled) && $ai_enabled !== '0',
        ];
        $steps[] = [
            'label'  => 'API Key stored',
            'value'  => !empty($api_key) ? '••••' . substr($api_key, -4) : '(empty)',
            'pass'   => !empty($api_key),
        ];

        // Step 2: is_configured()
        $ai = new \CleverSay\AI();
        $configured = $ai->is_configured();
        $steps[] = [
            'label'  => 'AI::is_configured()',
            'value'  => $configured ? 'true' : 'false',
            'pass'   => $configured,
        ];

        // Step 3: Sources indexed
        global $wpdb;
        $sources_table = $wpdb->prefix . 'cleversay_sources';
        $chunks_table  = $wpdb->prefix . 'cleversay_chunks';

        $indexed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sources_table} WHERE status = 'indexed'");
        $steps[] = [
            'label'  => 'Indexed sources',
            'value'  => (string) $indexed,
            'pass'   => $indexed > 0,
        ];

        $chunk_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$chunks_table}");
        $steps[] = [
            'label'  => 'Total chunks stored',
            'value'  => (string) $chunk_count,
            'pass'   => $chunk_count > 0,
        ];

        // Step 4: FULLTEXT index
        $ft_indexes = $wpdb->get_results(
            "SHOW INDEX FROM {$chunks_table} WHERE Index_type = 'FULLTEXT'",
            ARRAY_A
        );
        $has_ft = !empty($ft_indexes);
        $steps[] = [
            'label'  => 'FULLTEXT index on chunks table',
            'value'  => $has_ft ? 'present' : 'missing',
            'pass'   => $has_ft,
        ];

        // Step 5: Sample chunk retrieval
        $indexer = new \CleverSay\Indexer();
        $test_question = sanitize_text_field(wp_unslash($_POST['test_question'] ?? 'What services do you offer'));
        $chunks = $indexer->find_relevant_chunks($test_question, 3);
        $steps[] = [
            'label'  => "Chunks found for \"" . esc_html($test_question) . "\"",
            'value'  => count($chunks) . ' chunk(s)',
            'pass'   => !empty($chunks),
            'detail' => !empty($chunks)
                ? array_map(fn($c) => substr($c['content'], 0, 80) . '…', array_slice($chunks, 0, 2))
                : [],
        ];

        // Step 6: API key test (live call)
        if ($configured) {
            $api_test = $ai->test_api_key_with_message($api_key);
            $steps[] = [
                'label'  => 'Live API key test',
                'value'  => $api_test['message'],
                'pass'   => $api_test['success'],
            ];
        }

        // Overall
        $all_pass = array_reduce($steps, fn($carry, $s) => $carry && ($s['pass'] ?? true), true);

        wp_send_json_success([
            'steps'    => $steps,
            'all_pass' => $all_pass,
            'summary'  => $all_pass
                ? 'All checks passed — AI should be working.'
                : 'One or more checks failed. Fix the failing steps above.',
        ]);
    }


    // =========================================================================
    // AI Answer promotion handlers
    // =========================================================================

    public function ajax_promote_ai_answer(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }

        global $wpdb;
        $db        = new \CleverSay\Database();
        $answer_id = absint($_POST['answer_id'] ?? 0);
        $keyword   = sanitize_text_field(wp_unslash($_POST['keyword']    ?? ''));
        $question  = sanitize_textarea_field(wp_unslash($_POST['question'] ?? ''));
        $answer    = wp_kses_post(wp_unslash($_POST['answer']   ?? ''));

        if (!$answer_id || empty($keyword) || empty($answer)) {
            wp_send_json_error(['message' => __('Missing required fields.', 'cleversay')]);
        }

        // Verify the ai_answer exists and is pending
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$db->ai_answers} WHERE id = %d AND status = 'pending'", $answer_id),
            ARRAY_A
        );
        if (!$row) {
            wp_send_json_error(['message' => __('Answer not found or already processed.', 'cleversay')]);
        }

        // Default to 'active' when admin checked "Activate immediately", else 'hold'
        $activate = !empty($_POST['activate']);
        $new_status = $activate ? 'active' : 'hold';

        $result = $wpdb->insert($db->knowledge_base, [
            'keyword'     => $keyword,
            'sub_keyword' => sanitize_text_field(wp_unslash($_POST['sub_keyword'] ?? '')),
            'question'    => $question ?: $row['question'],
            'response'    => $answer,
            'status'      => $new_status,
            'show_rating' => 1,
            'created_by'  => get_current_user_id(),
        ]);

        if ($result === false) {
            wp_send_json_error(['message' => __('Failed to save to knowledge base.', 'cleversay')]);
        }

        $knowledge_id = (int) $wpdb->insert_id;

        // Mark ALL duplicate rows (same normalized question text) as promoted.
        // Without this, only the latest row is updated and duplicates reappear.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$db->ai_answers}
             SET status = 'promoted', knowledge_id = %d,
                 reviewed_by = %d, reviewed_at = %s
             WHERE LOWER(TRIM(question)) = (
                SELECT q FROM (SELECT LOWER(TRIM(question)) AS q FROM {$db->ai_answers} WHERE id = %d) AS sub
             )
             AND status = 'pending'",
            $knowledge_id,
            get_current_user_id(),
            current_time('mysql'),
            $answer_id
        ));

        $this->flush_search_cache();

        $edit_url = add_query_arg(
            ['page' => 'cleversay-knowledge', 'action' => 'edit', 'id' => $knowledge_id],
            admin_url('admin.php')
        );

        $success_msg = $activate
            ? __('Added to knowledge base and activated. It is now live.', 'cleversay')
            : __('Added to knowledge base as "Hold". Review and set to Active when ready.', 'cleversay');

        wp_send_json_success([
            'message'      => $success_msg,
            'knowledge_id' => $knowledge_id,
            'edit_url'     => $edit_url,
            'activated'    => $activate,
        ]);
    }

    public function ajax_reject_ai_answer(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'cleversay')], 403);
        }

        global $wpdb;
        $db        = new \CleverSay\Database();
        $answer_id = absint($_POST['answer_id'] ?? 0);

        if (!$answer_id) {
            wp_send_json_error(['message' => __('Invalid ID.', 'cleversay')]);
        }

        // Reject ALL duplicate rows with the same normalized question
        $wpdb->query($wpdb->prepare(
            "UPDATE {$db->ai_answers}
             SET status = 'rejected', reviewed_by = %d, reviewed_at = %s
             WHERE LOWER(TRIM(question)) = (
                SELECT q FROM (SELECT LOWER(TRIM(question)) AS q FROM {$db->ai_answers} WHERE id = %d) AS sub
             )
             AND status = 'pending'",
            get_current_user_id(),
            current_time('mysql'),
            $answer_id
        ));

        wp_send_json_success(['message' => __('Answer rejected.', 'cleversay')]);
    }


    /** AJAX: return all distinct keywords for autocomplete */
    public function ajax_get_kb_keywords(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error([], 403);

        global $wpdb;
        $db = new \CleverSay\Database();

        $search  = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
        $escaped = $wpdb->esc_like($search);

        $keywords = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT keyword FROM {$db->knowledge_base}
                 WHERE keyword LIKE %s AND status != 'inactive'
                 ORDER BY keyword ASC
                 LIMIT 50",
                '%' . $escaped . '%'
            )
        );

        wp_send_json_success($keywords ?: []);
    }

    /** AJAX: return distinct sub-keywords for a given keyword */
    public function ajax_get_kb_subkeywords(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error([], 403);

        global $wpdb;
        $db      = new \CleverSay\Database();
        $keyword = sanitize_text_field(wp_unslash($_GET['keyword'] ?? ''));

        if (empty($keyword)) {
            wp_send_json_success([]);
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT sub_keyword, question
                 FROM {$db->knowledge_base}
                 WHERE keyword = %s AND sub_keyword IS NOT NULL AND sub_keyword != ''
                 AND status != 'inactive'
                 ORDER BY sub_keyword ASC
                 LIMIT 50",
                $keyword
            ),
            ARRAY_A
        );

        wp_send_json_success($rows ?: []);
    }


    // =========================================================================
    // Web Crawler AJAX endpoints
    // =========================================================================

    public function ajax_crawl_discover(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'cleversay')], 403);
        }
        $start_url     = esc_url_raw(wp_unslash($_POST['start_url']    ?? ''));
        $max_depth     = max(1, min(5,   (int) ($_POST['max_depth']   ?? 2)));
        $max_pages     = max(1, min(200, (int) ($_POST['max_pages']   ?? 50)));
        $restrict_path = sanitize_text_field(wp_unslash($_POST['restrict_path'] ?? ''));
        $request_delay = max(0, min(10,  (int) ($_POST['request_delay'] ?? 2)));
        // Default ON — most sites have a main content area and the crawl
        // makes far more sense restricted to it. Visitors of the form can
        // uncheck for cases where they need full-page link discovery.
        $main_content_only = !isset($_POST['main_content_only']) || !empty($_POST['main_content_only']);

        if ($max_depth === 1) { $max_pages = 1; }
        if (empty($start_url)) {
            wp_send_json_error(['message' => __('Please enter a start URL.', 'cleversay')]);
        }

        // Just initialise the job — discovery happens one page at a time
        $job_id  = wp_generate_uuid4();
        $crawler = new \CleverSay\Crawler();
        $crawler->save_job($job_id, [
            'phase'             => 'discover',
            'start_url'         => $start_url,
            'max_depth'         => $max_depth,
            'max_pages'         => $max_pages,
            'restrict_path'     => $restrict_path,
            'request_delay'     => $request_delay,
            'main_content_only' => $main_content_only,
            'base_domain'       => strtolower(wp_parse_url($start_url, PHP_URL_HOST) ?? ''),
            'queue'             => [['url' => $start_url, 'depth' => 0]],
            'visited'           => [],
            'found'             => [],
            'html_cache'        => [],
            'errors'            => [],
            'urls'              => [],   // filled when discover phase is complete
            'indexed'           => [],
            'current'           => 0,
        ]);

        wp_send_json_success([
            'job_id'  => $job_id,
            'message' => __('Starting discovery…', 'cleversay'),
        ]);
    }

    /**
     * Crawl one page during the discovery phase.
     * Called repeatedly by the browser until phase changes to 'index'.
     */
    public function ajax_crawl_discover_next(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'cleversay')], 403);
        }

        $job_id  = sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));
        $crawler = new \CleverSay\Crawler();
        $job     = $crawler->get_job($job_id);
        if (!$job) {
            wp_send_json_error(['message' => __('Crawl job not found.', 'cleversay')]);
        }

        $queue        = $job['queue']        ?? [];
        $visited      = $job['visited']      ?? [];
        $found        = $job['found']        ?? [];
        $html_cache   = $job['html_cache']   ?? [];
        $errors       = $job['errors']       ?? [];
        $max_pages    = (int) ($job['max_pages']    ?? 50);
        $max_depth    = (int) ($job['max_depth']    ?? 2);
        $base_domain  = $job['base_domain']  ?? '';
        $restrict_path= $job['restrict_path']?? '';

        // Pop next URL from queue
        while (!empty($queue)) {
            $item = array_shift($queue);
            $url  = $item['url'];
            $depth= $item['depth'];
            $norm = $crawler->normalise_url_public($url);
            if (isset($visited[$norm])) continue;
            $visited[$norm] = true;

            // Polite pacing — wait between requests after the first page to
            // avoid tripping WAF rate limits.
            $delay = (int) ($job['request_delay'] ?? 2);
            if (count($found) > 0 && $delay > 0) {
                sleep($delay);
            }

            // Fetch with automatic single retry on 403/429/503 (WAF rate limits)
            $response = $crawler->fetch_with_retry_public($url, $job['start_url'] ?? $url);

            if (is_wp_error($response)) {
                $errors[] = sprintf('%s: %s', $url, $response->get_error_message());
                break;
            }
            $code = wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 400) {
                $errors[] = sprintf('HTTP %d — %s', $code, $url);
                break;
            }
            $ct = wp_remote_retrieve_header($response, 'content-type');
            if ($ct && !str_contains($ct, 'text/html')) { break; }

            $html    = wp_remote_retrieve_body($response);
            $found[] = $url;
            if (count($html_cache) < 50) {
                $html_cache[$url] = $html;
            }

            // Queue child links if within depth
            if ($depth < $max_depth && count($found) < $max_pages) {
                $links = $crawler->extract_links_public(
                    $html,
                    $url,
                    $base_domain,
                    $restrict_path,
                    !empty($job['main_content_only'])
                );
                foreach ($links as $link) {
                    $ln = $crawler->normalise_url_public($link);
                    if (!isset($visited[$ln])) {
                        $queue[] = ['url' => $link, 'depth' => $depth + 1];
                    }
                }
            }
            break; // one page per call
        }

        $done = empty($queue) || count($found) >= $max_pages;

        if ($done) {
            // Transition to index phase
            $job['phase']      = 'index';
            $job['urls']       = $found;
            $job['html_cache'] = $html_cache;
            $job['errors']     = $errors;
            $job['total']      = count($found);
            $job['current']    = 0;
        }

        $job['queue']   = $queue;
        $job['visited'] = $visited;
        $job['found']   = $found;
        $job['html_cache'] = $html_cache;
        $job['errors']  = $errors;
        $crawler->save_job($job_id, $job);

        wp_send_json_success([
            'done'    => $done,
            'found'   => count($found),
            'total'   => $max_pages,
            'url'     => $found ? end($found) : '',
            'errors'  => $errors,
            'phase'   => $job['phase'],
            'urls'    => $done ? $found : [],
        ]);
    }

    public function ajax_crawl_index_next(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'cleversay')], 403);
        }
        $job_id  = sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));
        $crawler = new \CleverSay\Crawler();
        $job     = $crawler->get_job($job_id);
        if (!$job) {
            wp_send_json_error(['message' => __('Crawl job not found or expired.', 'cleversay')]);
        }
        $current = (int) $job['current'];
        $urls    = $job['urls'];
        $total   = count($urls);
        if ($current >= $total) {
            $crawler->delete_job($job_id);
            wp_send_json_success(['done' => true, 'total' => $total, 'indexed' => count($job['indexed'])]);
        }
        $url          = $urls[$current];
        $skip_indexed = !empty($_POST['skip_indexed']);
        $sources      = $this->require_ai_sources();
        $error        = null;
        $skipped      = false;
        $cached_html  = $job['html_cache'][$url] ?? '';

        // Canonicalise the URL before any DB lookup — "page" and "page/" must
        // map to the same source row (otherwise we get duplicate indexing).
        $url = \CleverSay\Crawler::normalise($url);

        // Skip only pages with status='indexed' when skip_indexed is checked
        // Error pages are ALWAYS retried regardless of skip setting
        if ($skip_indexed) {
            global $wpdb;
            $db     = new \CleverSay\Database();
            $status = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT status FROM {$db->sources} WHERE url = %s LIMIT 1",
                    $url
                )
            );
            if ($status === 'indexed') {
                $skipped = true;
            }
        }

        if (!$skipped) {
            $force = !$skip_indexed; // force reindex when not skipping
            $id = $sources->add_url($url, '', $cached_html, $force);
            if ($id === false) {
                global $wpdb;
                $db = new \CleverSay\Database();
                $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$db->sources} WHERE url = %s LIMIT 1", $url));
                if ($id) {
                    $sources->reindex($id);
                } else {
                    $error = sprintf(__('Failed to add %s', 'cleversay'), $url);
                }
            }

            // Check if indexing actually succeeded — fetch error_message from DB
            if ($id && !$error) {
                global $wpdb;
                $db     = new \CleverSay\Database();
                $result = $wpdb->get_row(
                    $wpdb->prepare("SELECT status, error_message FROM {$db->sources} WHERE id = %d", $id),
                    ARRAY_A
                );
                if ($result && $result['status'] === 'error') {
                    $error = $result['error_message'] ?: sprintf(__('Indexing failed for %s', 'cleversay'), $url);
                }
            }
        }

        $job['current']   = $current + 1;
        $job['indexed'][] = $url;
        if ($error) $job['errors'][] = $error;
        $crawler->save_job($job_id, $job);
        $done = ($job['current'] >= $total);
        if ($done) $crawler->delete_job($job_id);
        wp_send_json_success([
            'done'    => $done,
            'current' => $job['current'],
            'total'   => $total,
            'url'     => $url,
            'skipped' => $skipped,
            'error'   => $error,
            'errors'  => $job['errors'],
        ]);
    }



    /**
     * Strip generic/useless tokens from an AI-suggested pattern.
     *
     * Splits the pattern on | and & operators, removes any token that is:
     *   - a known question/filler word (how, much, does, get, etc.)
     *   - identical to the main keyword
     *   - fewer than 3 characters
     * Then reassembles remaining tokens preserving the original operators.
     *
     * Returns empty string if nothing meaningful remains (triggers a retry).
     */
    private function clean_ai_pattern(string $pattern, string $keyword): string {
        // Words that are ALWAYS meaningless as patterns — grammatical function
        // words that appear in virtually every question with no narrowing value.
        // NOTE: help/get/find/need/want are NOT banned here because they can
        // genuinely signal intent (e.g. "help" in "get help with advising").
        static $banned = [
            // Interrogatives & auxiliaries
            'how','much','many','does','did','can','could','would','should',
            'may','might','will','what','where','when','why','who','which',
            // Copulas
            'is','are','was','were','be','been','being',
            // Articles, prepositions, conjunctions
            'a','an','the','of','in','on','at','to','for','with','by','from',
            // Pronouns
            'i','my','me','we','our','you','your','it','its','they','their',
            'this','that','these','those','there','here',
            // Pure fillers (never add intent)
            'please','just','also','very','really','more','most','some','any',
            'like','about','than','then','now','too','so','up','out',
        ];

        $kw_lower = strtolower(trim($keyword));

        // Split preserving operators: tokenise by | and &
        // We rebuild by splitting on non-word chars but keep * for wildcards
        $or_groups = explode('|', $pattern);
        $clean_ors = [];

        foreach ($or_groups as $or_group) {
            $and_parts = preg_split('/[&+]/', $or_group);
            $clean_and = [];

            foreach ($and_parts as $part) {
                $t = strtolower(trim($part));
                if (strlen($t) < 3)                      continue; // too short
                if ($t === $kw_lower)                    continue; // is the keyword
                if (in_array($t, $banned, true))          continue; // generic word
                // Also strip wildcard-only tokens like just "*"
                if (preg_match('/^\*+$/', $t))          continue;
                $clean_and[] = trim($part);
            }

            if (!empty($clean_and)) {
                $clean_ors[] = implode('&', $clean_and);
            }
        }

        return implode('|', $clean_ors);
    }



    /**
     * AI-powered suggestion for the Promote to Knowledge Base modal.
     *
     * Suggests:
     *   - A cleaned-up, properly-formatted question (capitalised, ends with ?)
     *   - A keyword (either an existing one that matches, or a new one)
     *   - A sub-keyword pattern, self-validated via test_pattern_match
     *
     * $_POST[mode] controls which field(s) are regenerated:
     *   "all"       — first call, suggests everything
     *   "keyword"   — regenerate just the keyword
     *   "pattern"   — regenerate just the pattern (requires keyword in $_POST)
     *   "question"  — regenerate just the formatted question
     */
    public function ajax_ai_suggest_promote(): void {
        check_ajax_referer('cleversay_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'cleversay')], 403);
        }

        $raw_question = sanitize_text_field(wp_unslash($_POST['question'] ?? ''));
        $raw_answer   = sanitize_textarea_field(wp_unslash($_POST['answer']   ?? ''));
        $mode         = sanitize_key($_POST['mode'] ?? 'all');
        $given_kw     = sanitize_text_field(wp_unslash($_POST['keyword'] ?? ''));

        if (empty($raw_question)) {
            wp_send_json_error(['message' => __('No question provided.', 'cleversay')]);
        }

        if (!\CleverSay\NetworkSettings::ai_is_configured()) {
            wp_send_json_error(['message' => __('AI is not configured. Contact your administrator.', 'cleversay')]);
        }

        $ai_cfg  = \CleverSay\NetworkSettings::get_ai_config();
        $api_key = $ai_cfg['api_key'];

        global $wpdb;
        $db = new \CleverSay\Database();

        // Gather existing keywords so AI can match them when appropriate
        $existing_keywords = $wpdb->get_col("SELECT DISTINCT keyword FROM {$db->knowledge_base} WHERE keyword != '' AND keyword IS NOT NULL ORDER BY keyword");
        $existing_keywords = array_filter($existing_keywords);
        $keyword_list_str  = !empty($existing_keywords) ? implode(', ', $existing_keywords) : '(none — this will be the first keyword)';

        $search = new \CleverSay\Search();

        // ── System prompt ──────────────────────────────────────────────────
        $system = 'You are a knowledge base curator for a chatbot system.

Your job: given a user question and the AI answer that was generated, suggest:
  1. A cleanly formatted version of the question (capitalised, single ?, clear phrasing)
  2. The best KEYWORD to file this entry under
  3. A sub-keyword PATTERN that narrows which questions should match

KEYWORD RULES
- The keyword MUST be a word that actually appears in the question (or its stemmed root).
  The chatbot tokenises the user\'s question at runtime — if the keyword is not literally
  present in the question, the KB entry will never match.
- Use singular root form (not "grades" but "grade", not "parking" but "park" —
  the system stems automatically).
- Pick the most specific topic noun from the question. For "How can I get help with
  advising?", the topic word in the question is "advising" → keyword "advising".
  Do NOT suggest "advisor" just because it already exists — they are different words
  and the question does not contain "advisor".
- Use the existing keywords list ONLY as a consistency check: if the word you picked
  already exists in the list, set keyword_is_new=false. Otherwise keyword_is_new=true.
- Do NOT try to remap the question to an existing keyword. The admin can consolidate
  later if they want — your job is to reflect what the question actually says.

SUB-KEYWORD PATTERN RULES
- Use | for OR (cost|price|fee), & for AND (view&grade), * for wildcards.
- NEVER include the keyword itself in the pattern.
- 2-4 meaningful content words joined with |.
- Avoid generic question words (how, much, does, what, when, where) — they appear
  in every question and carry no narrowing power.
- Words like "help", "get", "find" CAN be pattern words when they reflect genuine intent
  (e.g. "get help with X" → pattern "help|assistance|support"). Use them when they
  meaningfully distinguish the question from other questions about the same keyword.

QUESTION FORMATTING RULES
- Start with a capital letter.
- End with exactly one question mark.
- Fix obvious typos and grammar without changing meaning.
- Keep it concise and natural.

RESPOND WITH JSON ONLY — no markdown:
{
  "question": "Properly formatted question?",
  "keyword": "keyword_root",
  "keyword_is_new": false,
  "pattern": "word1|word2|word3",
  "explanation": "one sentence why"
}

EXAMPLE (existing keyword — word is in the question)
Existing keywords: park, tuition, grade, advisor
User question: "how much is parking"
AI answer: "Parking permits cost $150 per semester for students."
→ {
    "question": "How much does parking cost?",
    "keyword": "park",
    "keyword_is_new": false,
    "pattern": "cost|price|fee|permit",
    "explanation": "The question contains \"parking\" which stems to park — already a keyword."
  }

EXAMPLE (new keyword — related word exists but is not in the question)
Existing keywords: park, tuition, grade, advisor
User question: "how can I get help with advising"
AI answer: "Academic advising appointments can be scheduled through the student portal."
→ {
    "question": "How can I get help with advising?",
    "keyword": "advising",
    "keyword_is_new": true,
    "pattern": "help|assistance|support",
    "explanation": "The question asks about advising (not advisor). Keyword reflects the actual word used; pattern captures the help-seeking intent."
  }

EXAMPLE (new keyword — no related word at all)
Existing keywords: park, tuition, grade
User question: "when do dorms open"
AI answer: "Residence halls open on August 20th for new students."
→ {
    "question": "When do dorms open?",
    "keyword": "dorm",
    "keyword_is_new": true,
    "pattern": "open|move|date",
    "explanation": "Dorm is the topic word in the question and does not exist yet."
  }
';

        // ── Build user message based on mode ────────────────────────────
        $user_msg  = "EXISTING KEYWORDS: {$keyword_list_str}\n\n";
        $user_msg .= "USER QUESTION: {$raw_question}\n\n";
        if (!empty($raw_answer)) {
            $answer_snippet = mb_substr($raw_answer, 0, 500);
            $user_msg .= "AI ANSWER (for context): {$answer_snippet}\n\n";
        }

        if ($mode === 'pattern' && !empty($given_kw)) {
            $user_msg .= "The keyword is already chosen as \"{$given_kw}\". Keep that keyword and only suggest the pattern that narrows which questions match.";
        } elseif ($mode === 'keyword') {
            $user_msg .= "Focus on choosing the best keyword. Still return all fields.";
        } elseif ($mode === 'question') {
            $user_msg .= "Focus on cleaning up the question formatting. Still return all fields.";
        } else {
            $user_msg .= "Suggest all fields.";
        }

        // ── Call AI with retry for pattern validation ──────────────────
        $history      = [];
        $attempt      = 0;
        $max_attempts = 3;
        $result       = null;
        $fail_reason  = '';

        while ($attempt < $max_attempts) {
            $attempt++;

            if ($attempt === 1) {
                $history[] = ['role' => 'user', 'content' => $user_msg];
            } else {
                $history[] = ['role' => 'user', 'content' =>
                    "The pattern \"{$result['pattern']}\" did not validate against the question \"{$result['question']}\" with keyword \"{$result['keyword']}\".\n"
                    . "Reason: {$fail_reason}\n"
                    . "Suggest a different pattern using words whose intent is present in the question. Return all fields."];
            }

            $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
                'timeout' => 20,
                'headers' => [
                    'Content-Type'      => 'application/json',
                    'x-api-key'         => $api_key,
                    'anthropic-version' => '2023-06-01',
                ],
                'body' => wp_json_encode([
                    'model'      => $ai_cfg['model'],
                    'max_tokens' => 400,
                    'system'     => $system,
                    'messages'   => $history,
                ]),
            ]);

            if (is_wp_error($response)) {
                wp_send_json_error(['message' => $response->get_error_message()]);
            }

            $http_code = wp_remote_retrieve_response_code($response);
            $body      = json_decode(wp_remote_retrieve_body($response), true);

            if ($http_code !== 200) {
                $api_error = $body['error']['message'] ?? "API returned HTTP {$http_code}";
                wp_send_json_error(['message' => $api_error]);
            }

            $text = trim($body['content'][0]['text'] ?? '');
            $text = preg_replace('/^```[a-z]*\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', trim($text));
            $data = json_decode($text, true);

            if (empty($data['pattern']) || empty($data['keyword'])) {
                break;
            }

            // Keep AI response in history for potential retry
            $history[] = ['role' => 'assistant', 'content' => $text];

            $result = [
                'question'       => sanitize_text_field($data['question']    ?? $raw_question),
                'keyword'        => sanitize_text_field($data['keyword']),
                'keyword_is_new' => !empty($data['keyword_is_new']),
                'pattern'        => sanitize_text_field($data['pattern']),
                'explanation'    => sanitize_text_field($data['explanation'] ?? ''),
            ];

            // Clean generic words from pattern
            $result['pattern'] = $this->clean_ai_pattern($result['pattern'], $result['keyword']);
            if (empty($result['pattern'])) {
                $fail_reason = 'Pattern contained only generic words after cleaning.';
                continue;
            }

            // Self-validate the pattern against the formatted question
            $test = $search->test_pattern_match($result['keyword'], $result['pattern'], $result['question']);
            if ($test['matched']) {
                $result['pattern_validated'] = true;
                $result['attempts']          = $attempt;

                // Verify keyword_is_new flag against actual DB (don't trust AI)
                $kw_lower = strtolower($result['keyword']);
                $existing_lower = array_map('strtolower', $existing_keywords);
                $result['keyword_is_new'] = !in_array($kw_lower, $existing_lower, true);

                wp_send_json_success($result);
            }

            $fail_reason = $test['reason'] ?? 'Pattern did not match.';
        }

        // Did not validate — return best attempt with warning
        if (empty($result)) {
            wp_send_json_error(['message' => __('AI returned an unexpected response. Please try again.', 'cleversay')]);
        }

        $result['pattern_validated'] = false;
        $result['attempts']          = $attempt;
        $result['fail_reason']       = $fail_reason;

        // Still verify keyword_is_new against DB
        $kw_lower       = strtolower($result['keyword']);
        $existing_lower = array_map('strtolower', $existing_keywords);
        $result['keyword_is_new'] = !in_array($kw_lower, $existing_lower, true);

        wp_send_json_success($result);
    }


    /**
     * Handle file download exports (JSON / CSV).
     *
     * Runs on admin_init — before any output — so headers can be sent cleanly.
     * Previously this was handled inside the view file, which meant WordPress
     * had already started outputting the page before header() was called.
     */
    public function handle_export_download(): void {
        if (!isset($_GET['export'])) return;
        if (!isset($_GET['page']) || $_GET['page'] !== 'cleversay-import-export') return;
        if (!current_user_can('manage_options')) return;

        // Verify nonce (wp_nonce_url was used on the link)
        if (!check_admin_referer('cleversay_export')) return;

        require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-import-export.php';
        $exporter    = new \CleverSay\ImportExport();
        $export_type = sanitize_text_field(wp_unslash($_GET['export']));

        if ($export_type === 'json') {
            $data = $exporter->export_json();
            $json = json_encode($data, JSON_PRETTY_PRINT);
            $filename = 'cleversay-backup-' . date('Y-m-d') . '.json';

            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($json));
            echo $json;
            exit;

        } elseif (in_array($export_type, ['knowledge', 'questions', 'synonyms'], true)) {
            $csv      = $exporter->export_csv($export_type);
            $filename = 'cleversay-' . $export_type . '-' . date('Y-m-d') . '.csv';

            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($csv));
            // BOM for Excel UTF-8 compatibility
            echo "\xEF\xBB\xBF" . $csv;
            exit;
        }
    }


    /**
     * Handle settings form save — runs on admin_init before any output
     * so the redirect after save works cleanly.
     */
    /**
     * Handle settings form save — registered on admin_init so it runs before output.
     */
    public function handle_settings_save(): void {
        if (!isset($_POST['cleversay_save_settings'])) return;
        if (!current_user_can('manage_options'))       return;

        // CSRF check: verify the request came from our own admin page.
        // We use referer + a custom hidden token rather than wp_verify_nonce
        // because nonces can fail when secret keys differ between environments.
        $token_field = $_POST['cleversay_csrf'] ?? '';
        $token_stored = get_user_meta(get_current_user_id(), 'cleversay_settings_token', true);
        if (empty($token_field) || empty($token_stored) || !hash_equals($token_stored, $token_field)) {
            return;
        }
        // Rotate the token after use
        delete_user_meta(get_current_user_id(), 'cleversay_settings_token');

        $options = [
            'widget_enabled'             => isset($_POST['widget_enabled']),
            'widget_position'            => sanitize_text_field($_POST['widget_position']            ?? 'bottom-right'),
            'widget_title'               => sanitize_text_field(wp_unslash($_POST['widget_title']    ?? '')),
            'widget_placeholder'         => sanitize_text_field(wp_unslash($_POST['widget_placeholder'] ?? '')),
            'widget_welcome_message'     => sanitize_textarea_field(wp_unslash($_POST['widget_welcome_message'] ?? '')),
            'bot_name'                   => sanitize_text_field(wp_unslash($_POST['bot_name']        ?? '')),
            'bot_agent_label'            => sanitize_text_field(wp_unslash($_POST['bot_agent_label'] ?? '')),
            'mascot_image_url'           => esc_url_raw(wp_unslash($_POST['mascot_image_url']        ?? '')),
            'show_ai_badge'              => isset($_POST['show_ai_badge']),
            'show_top_questions'         => isset($_POST['show_top_questions']),
            'top_questions_title'        => sanitize_text_field(wp_unslash($_POST['top_questions_title'] ?? '')),
            'top_questions_count'        => max(1, min(20, intval($_POST['top_questions_count']      ?? 10))),
            'primary_color'              => sanitize_hex_color($_POST['primary_color']               ?? '#2271b1'),
            'secondary_color'            => sanitize_hex_color($_POST['secondary_color']             ?? '#135e96'),
            'text_color'                 => sanitize_hex_color($_POST['text_color']                  ?? '#1d2327'),
            'background_color'           => sanitize_hex_color($_POST['background_color']            ?? '#ffffff'),
            'header_bg_color'            => sanitize_hex_color($_POST['header_bg_color']             ?? '#2271b1'),
            'header_text_color'          => sanitize_hex_color($_POST['header_text_color']           ?? '#ffffff'),
            'user_bubble_color'          => sanitize_hex_color($_POST['user_bubble_color']           ?? '#2271b1'),
            'user_bubble_text'           => sanitize_hex_color($_POST['user_bubble_text']            ?? '#ffffff'),
            'bot_bubble_color'           => sanitize_hex_color($_POST['bot_bubble_color']            ?? '#ffffff'),
            'bot_bubble_text'            => sanitize_hex_color($_POST['bot_bubble_text']             ?? '#1d2327'),
            'chat_bg_color'              => sanitize_hex_color($_POST['chat_bg_color']               ?? '#f5f5f7'),
            'toggle_bg_color'            => sanitize_hex_color($_POST['toggle_bg_color']             ?? '#2271b1'),
            'enable_spellcheck'          => isset($_POST['enable_spellcheck']),
            'min_match_score'            => max(0, min(100, intval($_POST['min_match_score']         ?? 70))),
            'max_results'                => max(1, min(20,  intval($_POST['max_results']             ?? 5))),
            'show_suggestions'           => isset($_POST['show_suggestions']),
            'spellcheck_threshold'       => max(50, min(100, intval($_POST['spellcheck_threshold']   ?? 75))),
            'show_rating'                => isset($_POST['show_rating']),
            'rating_feedback'            => isset($_POST['rating_feedback']),
            'enable_inquiry_form'        => isset($_POST['enable_inquiry_form']),
            'inquiry_notification_email' => sanitize_email($_POST['inquiry_notification_email']      ?? ''),
            'require_email_for_inquiry'  => isset($_POST['require_email_for_inquiry']),
            'no_answer_message'          => sanitize_textarea_field(wp_unslash($_POST['no_answer_message']      ?? '')),
            'inquiry_success_message'    => sanitize_textarea_field(wp_unslash($_POST['inquiry_success_message'] ?? '')),
            'inquiry_intro_message'      => sanitize_textarea_field(wp_unslash($_POST['inquiry_intro_message'] ?? '')),
            'enable_analytics'           => isset($_POST['enable_analytics']),
            'track_visitors'             => isset($_POST['track_visitors']),
            'anonymize_ip'               => isset($_POST['anonymize_ip']),
            'exclude_bot_traffic'        => isset($_POST['exclude_bot_traffic']),
            'delete_data_on_uninstall'   => isset($_POST['delete_data_on_uninstall']),
            'cache_duration'             => max(0, intval($_POST['cache_duration']                   ?? 300)),
            'rate_limit_searches'        => max(0, intval($_POST['rate_limit_searches']              ?? 0)),
            'widget_font'                => sanitize_text_field($_POST['widget_font']               ?? 'system'),
            'widget_font_custom_url'     => esc_url_raw(wp_unslash($_POST['widget_font_custom_url'] ?? '')),
            'widget_font_custom_family'  => sanitize_text_field(wp_unslash($_POST['widget_font_custom_family'] ?? '')),
            'widget_font_size'           => max(11, min(24, intval($_POST['widget_font_size'] ?? 15))),
            'ai_polish_kb'               => isset($_POST['ai_polish_kb']),
            'ai_validate_kb'             => isset($_POST['ai_validate_kb']),
            'ai_validate_aadefault'      => isset($_POST['ai_validate_aadefault']),
            'teaser_enabled'             => isset($_POST['teaser_enabled']),
            'teaser_message'             => sanitize_textarea_field(wp_unslash($_POST['teaser_message'] ?? '')),
            'teaser_delay'               => max(1, min(30, intval($_POST['teaser_delay'] ?? 3))),
            'persona_school_name'        => sanitize_text_field(wp_unslash($_POST['persona_school_name']  ?? '')),
            'persona_short_name'         => sanitize_text_field(wp_unslash($_POST['persona_short_name']   ?? '')),
            'persona_mascot_name'        => sanitize_text_field(wp_unslash($_POST['persona_mascot_name']  ?? '')),
            'persona_tone'               => sanitize_text_field($_POST['persona_tone']                    ?? 'friendly'),
            'persona_audience'           => sanitize_text_field(wp_unslash($_POST['persona_audience']     ?? '')),
            'persona_topics'             => sanitize_text_field(wp_unslash($_POST['persona_topics']       ?? '')),
            'persona_extra'              => sanitize_textarea_field(wp_unslash($_POST['persona_extra']    ?? '')),
        ];
        update_option('cleversay_options', $options);

        // Standalone toggles (stored as their own options, not in cleversay_options)
        update_option('cleversay_track_source_usage', isset($_POST['track_source_usage']));
        // v4.37.89+: Source citations add-on (per-site enable). Default off.
        update_option('cleversay_citations_enabled', isset($_POST['cleversay_citations_enabled']));

        // AI settings (field names match what the settings view actually sends)
        update_option('cleversay_ai_enabled',           isset($_POST['ai_enabled']) ? 1 : 0);
        update_option('cleversay_ai_normalize_queries', isset($_POST['ai_normalize_queries']) ? 1 : 0);
        // Follow-up suggestions toggle — defaults true on first save (engagement
        // is the typical preference). Off-by-default would mean existing sites
        // start without this on upgrade, but we want them to benefit immediately.
        update_option('cleversay_ai_followup_suggestions', isset($_POST['ai_followup_suggestions']) ? 1 : 0);
        update_option('cleversay_ai_model',             sanitize_text_field($_POST['ai_model']        ?? 'claude-haiku-4-5-20251001'));
        update_option('cleversay_ai_monthly_budget',    (float)($_POST['ai_monthly_budget']           ?? 0));
        update_option('cleversay_ai_max_chunks',        max(1, min(10, (int)($_POST['ai_max_chunks']  ?? 4))));
        update_option('cleversay_ai_max_tokens',        max(200, min(2000, (int)($_POST['ai_max_tokens'] ?? 800))));
        update_option('cleversay_ai_min_score',         max(0, min(100, (int)($_POST['ai_min_score']  ?? 0))));
        update_option('cleversay_ai_tiebreak_min_score',max(0, min(500, (int)($_POST['ai_tiebreak_min_score'] ?? 100))));
        update_option('cleversay_ai_label',             sanitize_text_field(wp_unslash($_POST['ai_label'] ?? 'AI-assisted answer')));

        // v4.37.74+: per-provider keys for single-site. Same approach as
        // network admin: read both, preserve existing on empty input,
        // mirror the active one to legacy 'api_key' for back-compat.
        // The "•" check defends against the masked-preview value being
        // submitted by a careless form (we never want to overwrite a
        // real key with a row of bullet characters).
        $current_model = (string) get_option('cleversay_ai_model', 'claude-haiku-4-5-20251001');
        $active_prov   = \CleverSay\NetworkSettings::provider_for_model($current_model);

        if (isset($_POST['anthropic_api_key'])) {
            $raw = trim(sanitize_text_field(wp_unslash($_POST['anthropic_api_key'])));
            if ($raw !== '' && strpos($raw, '•') === false) {
                update_option('cleversay_anthropic_api_key', $raw);
                if ($active_prov === 'anthropic') {
                    update_option('cleversay_ai_api_key', $raw); // legacy mirror
                }
            }
        }
        if (isset($_POST['gemini_api_key'])) {
            $raw = trim(sanitize_text_field(wp_unslash($_POST['gemini_api_key'])));
            if ($raw !== '' && strpos($raw, '•') === false) {
                update_option('cleversay_gemini_api_key', $raw);
                if ($active_prov === 'gemini') {
                    update_option('cleversay_ai_api_key', $raw); // legacy mirror
                }
            }
        }
        // Legacy single-field still accepted for back-compat; if posted,
        // route it to the active provider's per-provider field too.
        if (!empty($_POST['ai_api_key'])) {
            $raw_key = trim(sanitize_text_field(wp_unslash($_POST['ai_api_key'])));
            if (!empty($raw_key) && strpos($raw_key, '•') === false) {
                update_option('cleversay_ai_api_key', $raw_key);
                if ($active_prov === 'gemini') {
                    update_option('cleversay_gemini_api_key', $raw_key);
                } else {
                    update_option('cleversay_anthropic_api_key', $raw_key);
                }
            }
        }

        // Stopwords
        if (isset($_POST['stopwords'])) {
            $sw = array_filter(array_map('trim', explode("\n", wp_unslash($_POST['stopwords']))));
            update_option('cleversay_stopwords', array_values(array_unique(array_map('strtolower', $sw))));
        }

        // Embed domains
        // embed_domains is managed by network admin per-client — not saved here

        // ── Lead Capture options ─────────────────────────────────────
        update_option('cleversay_lead_capture_enabled',  isset($_POST['cleversay_lead_capture_enabled']) ? 1 : 0);
        update_option('cleversay_lead_welcome_message',
            sanitize_textarea_field(wp_unslash($_POST['cleversay_lead_welcome_message'] ?? '')));
        update_option('cleversay_lead_consent_text',
            sanitize_textarea_field(wp_unslash($_POST['cleversay_lead_consent_text'] ?? '')));
        update_option('cleversay_lead_cooldown_days', max(0, min(3650, (int) ($_POST['cleversay_lead_cooldown_days'] ?? 90))));
        update_option('cleversay_lead_hard_gate', !empty($_POST['cleversay_lead_hard_gate']) ? 1 : 0);
        update_option('cleversay_lead_notify_admin', isset($_POST['cleversay_lead_notify_admin']) ? 1 : 0);
        update_option('cleversay_lead_identity_label',
            sanitize_text_field(wp_unslash($_POST['cleversay_lead_identity_label'] ?? '')));

        // Identity dropdown options — split by newline, trim, dedupe, drop empties
        $raw_options = (string) wp_unslash($_POST['cleversay_lead_identity_options'] ?? '');
        $identity_options = array_values(array_filter(
            array_unique(array_map(function ($line) { return sanitize_text_field(trim($line)); },
                                   explode("\n", $raw_options))),
            function ($v) { return $v !== ''; }
        ));
        update_option('cleversay_lead_identity_options', $identity_options);

        // Per-field show/required configuration
        $raw_field_config = (array) ($_POST['cleversay_lead_field_config'] ?? []);
        $allowed_fields = ['first_name', 'last_name', 'email', 'identity', 'phone', 'date_of_birth'];
        $clean_field_config = [];
        foreach ($allowed_fields as $key) {
            $cfg = (array) ($raw_field_config[$key] ?? []);
            $clean_field_config[$key] = [
                'enabled'  => !empty($cfg['enabled']),
                'required' => !empty($cfg['required']),
            ];
        }
        update_option('cleversay_lead_field_config', $clean_field_config);

        $this->flush_search_cache();

        wp_safe_redirect(add_query_arg(['page' => 'cleversay-settings', 'saved' => '1'], admin_url('admin.php')));
        exit;
    }

    /**
     * Handle embed token regeneration — registered on admin_init.
     */
    public function handle_token_regenerate(): void {
        if (!isset($_POST['cleversay_regenerate_token'])) return;
        if (!current_user_can('manage_options'))          return;
        $token_field  = $_POST['cleversay_regen_csrf'] ?? '';
        $token_stored = get_user_meta(get_current_user_id(), 'cleversay_regen_token', true);
        if (empty($token_field) || empty($token_stored) || !hash_equals($token_stored, $token_field)) return;
        delete_user_meta(get_current_user_id(), 'cleversay_regen_token');

        $token = function_exists('random_bytes')
            ? bin2hex(random_bytes(24))
            : wp_generate_password(48, false, false);
        update_option('cleversay_embed_token', $token);

        wp_safe_redirect(add_query_arg(['page' => 'cleversay-settings', 'tab' => 'advanced', 'token_regenerated' => '1'], admin_url('admin.php')));
        exit;
    }


    /**
     * Strip Word/Office paste artifacts from response HTML before saving.
     * Removes MsoNormal classes, Office XML tags, massive background-* style chains,
     * and font-size/line-height/font-family inline styles.
     */
    private function clean_response_html(string $html): string {
        if (empty($html)) return $html;
        $html = preg_replace('/<\\\/?(o|w|m):[^>]*>/i', '', $html);
        $html = preg_replace('/\\s*class="[^"]*Mso[^"]*"/i', '', $html);
        $html = preg_replace_callback('/\\s*style="([^"]*)"/i', function($m) {
            $style = $m[1];
            $noisy = [
                '/background-image\\s*:[^;]+;?/i',
                '/background-position\\s*:[^;]+;?/i',
                '/background-size\\s*:[^;]+;?/i',
                '/background-repeat\\s*:[^;]+;?/i',
                '/background-attachment\\s*:[^;]+;?/i',
                '/background-origin\\s*:[^;]+;?/i',
                '/background-clip\\s*:[^;]+;?/i',
                '/background\\s*:[^;]+;?/i',
                '/font-size\\s*:[^;]+;?/i',
                '/line-height\\s*:[^;]+;?/i',
                '/font-family\\s*:[^;]+;?/i',
                '/mso-[^:]+:[^;]+;?/i',
            ];
            foreach ($noisy as $pattern) { $style = preg_replace($pattern, '', $style); }
            $style = trim($style, "; \\t");
            return $style ? ' style="' . $style . '"' : '';
        }, $html);
        $html = preg_replace('/(&nbsp;|\\xc2\\xa0){2,}/u', ' ', $html);
        $html = preg_replace('/<span[^>]*>\\s*<\\/span>/i', '', $html);
        $html = preg_replace('/<p[^>]*>\\s*<\\/p>/i', '', $html);
        return trim($html);
    }


    /**
     * Handle JSON/CSV import file upload — runs on admin_init before output
     * so the temp file is still available and we can set session notices.
     */
    public function handle_import_upload(): void {
        if (!isset($_POST['cleversay_import_file']))   return;
        if (!current_user_can('manage_options'))        return;
        if (!isset($_FILES['import_file']['tmp_name'])) return;
        if (!wp_verify_nonce(wp_unslash($_POST['_wpnonce'] ?? ''), 'cleversay_import_file')) return;

        $file_type = sanitize_text_field($_POST['import_type'] ?? 'json');
        $tmp       = $_FILES['import_file']['tmp_name'];

        if (empty($tmp) || !is_uploaded_file($tmp)) {
            set_transient('cleversay_import_result', ['success' => false, 'errors' => [__('No file uploaded.', 'cleversay')]], 60);
            wp_safe_redirect(add_query_arg('page', 'cleversay-import-export', admin_url('admin.php')));
            exit;
        }

        require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-import-export.php';
        $importer = new \CleverSay\ImportExport();

        // Backup before import
        $importer->create_backup();

        if ($file_type === 'json') {
            @ini_set('memory_limit', '256M');
            $content = file_get_contents($tmp);
            $data    = json_decode($content, true);
            if ($data === null) {
                $result = ['success' => false, 'errors' => ['Invalid JSON: ' . json_last_error_msg()]];
            } else {
                $result = $importer->import_json($data);
            }
        } else {
            $result = $importer->import_csv(file_get_contents($tmp), $file_type);
        }

        set_transient('cleversay_import_result_' . get_current_user_id(), $result, 60);
        wp_safe_redirect(add_query_arg(['page' => 'cleversay-import-export', 'imported' => '1'], admin_url('admin.php')));
        exit;
    }


    /**
     * Flush all CleverSay search-related object caches.
     * Call this whenever knowledge base, synonyms, or stopwords change.
     */
    private function flush_search_cache(): void {
        wp_cache_delete('db_keywords',       'cleversay_search');
        wp_cache_delete('kb_keywords_only',  'cleversay_search');
        wp_cache_delete('kb_pattern_tokens', 'cleversay_search');
        wp_cache_delete('stopwords',         'cleversay_search');
        wp_cache_delete('synonyms',          'cleversay_search');
        // Also clear analytics dashboard cache
        wp_cache_delete('dashboard_stats', 'cleversay_analytics');
    }

}
