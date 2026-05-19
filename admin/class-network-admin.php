<?php
/**
 * CleverSay Network Admin
 *
 * Handles all network-level admin functionality:
 * - Network settings pages (AI + Advanced)
 * - Per-site plan management
 * - Client site overview
 *
 * Only loaded when is_network_admin() is true.
 *
 * @package CleverSay
 * @since   4.0.0
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class NetworkAdmin {

    public function init(): void {
        // Defensive — ensure Icons class is loaded (used in all views)
        if (!class_exists('\\CleverSay\\Icons')) {
            $icons_path = CLEVERSAY_PLUGIN_DIR . 'includes/class-icons.php';
            if (file_exists($icons_path)) {
                require_once $icons_path;
            }
        }

        add_action('network_admin_menu',    [$this, 'add_network_menu']);
        add_action('network_admin_notices', [$this, 'show_notices']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        // Handle all form saves on admin_init — before any output is sent
        add_action('admin_init',            [$this, 'handle_form_saves']);

        // Pre-flight reachability check for provisioning wizard
        add_action('wp_ajax_cleversay_check_subdomain', [$this, 'ajax_check_subdomain']);

        // ── Super admin Network Admin cleanup ──────────────────────────────
        // Only fires in the network admin context
        add_action('network_admin_menu',         [$this, 'clean_network_menu'], 999);
        add_action('admin_bar_menu',             [$this, 'clean_network_admin_bar'], 999);
        add_action('admin_init',                 [$this, 'redirect_network_dashboard']);
        add_action('admin_head',                 [$this, 'inject_network_hub_styles']);

        // ── Super admin on client subsites ─────────────────────────────────
        if (!is_network_admin()) {
            add_action('admin_menu', [$this, 'clean_subsite_menu'], 999);
        }
    }

    // ── Form Save Handler (runs on admin_init before any output) ─────────────

    public function handle_form_saves(): void {
        if (!is_network_admin() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        // Starter pack apply (from Client Sites row)
        if (isset($_POST['cleversay_apply_pack'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_cs_pack_nonce'] ?? '')), 'cleversay_apply_pack')
            && current_user_can('manage_network_options')
        ) {
            $this->save_apply_starter_pack();
            // save_apply_starter_pack handles its own redirect
            return;
        }

        // v4.41.5.4+: Test Synthesis Model — diagnostic one-shot call
        // against whatever model is currently saved as synthesis_model.
        // Result is stashed in a transient and rendered inline on the
        // AI settings view. Must be checked BEFORE the AI Settings save
        // because both forms POST to the same admin URL.
        if (isset($_POST['cleversay_test_synthesis_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cleversay_test_synthesis_nonce'])), 'cleversay_test_synthesis')
            && ($_POST['cleversay_ai_action'] ?? '') === 'test_synthesis'
        ) {
            try {
                $ai     = new \CleverSay\AI();
                $result = $ai->test_synthesis();
            } catch (\Throwable $e) {
                $result = [
                    'success'    => false,
                    'error'      => 'Exception during test: ' . $e->getMessage(),
                    'latency_ms' => 0,
                    'model'      => '',
                    'synthesis_provider' => 'unknown',
                ];
            }
            set_transient('cleversay_synthesis_test_result', $result, 60);
            wp_redirect(add_query_arg(
                ['page' => 'cleversay-network-ai', 'synthesis_tested' => '1'],
                network_admin_url('admin.php')
            ));
            exit;
        }

        // AI Settings save
        if (isset($_POST['cleversay_network_ai_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cleversay_network_ai_nonce'])), 'cleversay_network_ai')
        ) {
            $this->save_ai_settings();
            wp_redirect(add_query_arg(['page' => 'cleversay-network-ai', 'updated' => '1'], network_admin_url('admin.php')));
            exit;
        }

        // Advanced Settings save
        if (isset($_POST['cleversay_network_adv_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cleversay_network_adv_nonce'])), 'cleversay_network_adv')
        ) {
            $this->save_advanced_settings();
            wp_redirect(add_query_arg(['page' => 'cleversay-network-advanced', 'updated' => '1'], network_admin_url('admin.php')));
            exit;
        }

        // Embeddings/Supabase Settings save
        // v4.38.0+: Phase 1 of the embeddings migration. Stores Supabase
        // connection details and OpenAI API key for vector retrieval.
        if (isset($_POST['cleversay_network_supabase_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cleversay_network_supabase_nonce'])), 'cleversay_network_supabase')
        ) {
            $this->save_supabase_settings();
            wp_redirect(add_query_arg(['page' => 'cleversay-network-embeddings', 'updated' => '1'], network_admin_url('admin.php')));
            exit;
        }

        // Embeddings: Test Connection
        if (isset($_POST['cleversay_supabase_test_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cleversay_supabase_test_nonce'])), 'cleversay_supabase_test')
            && ($_POST['cleversay_supabase_action'] ?? '') === 'test_connection'
        ) {
            $result = \CleverSay\Supabase::instance()->test_connection();
            set_transient('cleversay_supabase_test_result', $result, 60);
            wp_redirect(add_query_arg(['page' => 'cleversay-network-embeddings', 'test_result' => '1'], network_admin_url('admin.php')));
            exit;
        }

        // Embeddings: Install Schema
        if (isset($_POST['cleversay_supabase_schema_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cleversay_supabase_schema_nonce'])), 'cleversay_supabase_schema')
            && ($_POST['cleversay_supabase_action'] ?? '') === 'install_schema'
        ) {
            $result = \CleverSay\Supabase::instance()->install_schema();
            set_transient('cleversay_supabase_schema_result', $result, 60);
            wp_redirect(add_query_arg(['page' => 'cleversay-network-embeddings', 'schema_result' => '1'], network_admin_url('admin.php')));
            exit;
        }

        // Embeddings: Test Embedding API
        if (isset($_POST['cleversay_supabase_test_embed_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cleversay_supabase_test_embed_nonce'])), 'cleversay_supabase_test_embed')
            && ($_POST['cleversay_supabase_action'] ?? '') === 'test_embedding'
        ) {
            $result = (new \CleverSay\Embeddings())->test();
            set_transient('cleversay_supabase_test_result', $result, 60);
            wp_redirect(add_query_arg(['page' => 'cleversay-network-embeddings', 'test_result' => '1'], network_admin_url('admin.php')));
            exit;
        }

        // v4.41.0+: The backfill / process_now / retry_failed handlers
        // that previously lived here have moved to the per-site admin
        // (handled in Admin::handle_site_embeddings_actions). They're
        // inherently per-tenant operations — running them in network
        // admin context iterated every blog (including blog 1 which is
        // not a CleverSay tenant on this network), producing stranded
        // rows in Supabase. See Bugs 1, 2, 3 in the v4.41.0 handoff brief.

        // Custom CSS save
        if (isset($_POST['cleversay_custom_css_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cleversay_custom_css_nonce'])), 'cleversay_custom_css')
        ) {
            // Don't sanitize too aggressively — valid CSS contains chars like < > etc.
            $css = wp_unslash($_POST['cleversay_custom_css'] ?? '');
            // Strip only dangerous content
            $css = wp_strip_all_tags($css);
            update_site_option('cleversay_custom_admin_css', $css);
            wp_redirect(add_query_arg(['page' => 'cleversay-network-custom-css', 'updated' => '1'], network_admin_url('admin.php')));
            exit;
        }

        // Admin font settings save
        if (isset($_POST['cleversay_admin_font_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cleversay_admin_font_nonce'])), 'cleversay_admin_font')
        ) {
            $font_family = sanitize_text_field(wp_unslash($_POST['admin_font_family'] ?? 'Outfit'));
            $font_size   = max(12, min(18, (int) ($_POST['admin_font_size'] ?? 14)));
            update_site_option('cleversay_admin_font_family', $font_family);
            update_site_option('cleversay_admin_font_size', $font_size);
            wp_redirect(add_query_arg(['page' => 'cleversay-network-custom-css', 'updated' => '1', 'tab' => 'font'], network_admin_url('admin.php')));
            exit;
        }

        // Site plan save
        if (isset($_POST['cleversay_site_plan_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cleversay_site_plan_nonce'])), 'cleversay_site_plan')
        ) {
            $site_id = (int) ($_POST['site_id'] ?? 0);
            if ($site_id > 0) {
                // Read prior plan to detect changes that should reset notification state
                $existing      = NetworkSettings::get_site_plan($site_id);
                $new_trial_end = sanitize_text_field(wp_unslash($_POST['trial_ends_at'] ?? ''));
                $new_status    = sanitize_text_field(wp_unslash($_POST['status'] ?? 'active'));

                $plan = [
                    'plan'              => sanitize_text_field(wp_unslash($_POST['plan'] ?? 'basic')),
                    'status'            => $new_status,
                    'kb_limit'          => (int) ($_POST['kb_limit'] ?? 500),
                    'ai_calls_monthly'  => (int) ($_POST['ai_calls_monthly'] ?? 1000),
                    'ai_budget_monthly' => (float) ($_POST['ai_budget_monthly'] ?? 10),
                    'client_name'       => sanitize_text_field(wp_unslash($_POST['client_name'] ?? '')),
                    'client_logo_url'   => esc_url_raw(wp_unslash($_POST['client_logo_url'] ?? '')),
                    'client_email'      => sanitize_email(wp_unslash($_POST['client_email'] ?? '')),
                    'activated_date'    => sanitize_text_field(wp_unslash($_POST['activated_date'] ?? '')),
                    'trial_ends_at'     => $new_trial_end,
                    'embed_domains'     => sanitize_textarea_field(wp_unslash($_POST['embed_domains'] ?? '')),
                ];

                // Reset trial notification flags whenever:
                //   - trial_ends_at changes (new deadline = new warning cycle)
                //   - status changes away from 'trial' and back later
                // Without these resets, an admin who extends a trial wouldn't
                // get the warning emails for the new deadline.
                $trial_date_changed   = (($existing['trial_ends_at'] ?? '') !== $new_trial_end);
                $status_left_trial    = (($existing['status'] ?? '') === 'trial' && $new_status !== 'trial');
                if ($trial_date_changed || $status_left_trial) {
                    $plan['trial_warned_7d']  = '';
                    $plan['trial_warned_1d']  = '';
                    $plan['trial_expired_at'] = '';
                }

                NetworkSettings::save_site_plan($site_id, $plan);

                // Per-site AI model override — stored on the target site, not in
                // the network plan blob, because it controls runtime model
                // selection inside that site's request handlers. Whitelist
                // against the canonical model list to prevent setting a
                // bogus value.
                $override_raw   = sanitize_text_field(wp_unslash($_POST['ai_model_override'] ?? ''));
                $valid_models   = array_keys(\CleverSay\AI::get_available_models());
                switch_to_blog($site_id);
                if ($override_raw === '' || $override_raw === 'default') {
                    delete_option('cleversay_ai_model_override');
                } elseif (in_array($override_raw, $valid_models, true)) {
                    update_option('cleversay_ai_model_override', $override_raw);
                }
                restore_current_blog();

                wp_redirect(add_query_arg(['page' => 'cleversay-network-sites', 'updated' => '1'], network_admin_url('admin.php')));
                exit;
            }
        }

        // Updater actions
        if (isset($_POST['cleversay_updater_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cleversay_updater_nonce'])), 'cleversay_updater')
        ) {
            $this->handle_updater_action();
        }
    }

    // ── Menu ─────────────────────────────────────────────────────────────────

    public function add_network_menu(): void {
        add_menu_page(
            __('CleverSay Network', 'cleversay'),
            __('CleverSay', 'cleversay'),
            'manage_network_options',
            'cleversay-network',
            [$this, 'render_dashboard'],
            'dashicons-format-chat',
            30
        );

        add_submenu_page(
            'cleversay-network',
            __('Network Dashboard', 'cleversay'),
            __('Dashboard', 'cleversay'),
            'manage_network_options',
            'cleversay-network',
            [$this, 'render_dashboard']
        );

        add_submenu_page(
            'cleversay-network',
            __('AI Settings', 'cleversay'),
            __('AI Settings', 'cleversay'),
            'manage_network_options',
            'cleversay-network-ai',
            [$this, 'render_ai_settings']
        );

        add_submenu_page(
            'cleversay-network',
            __('Advanced Settings', 'cleversay'),
            __('Advanced', 'cleversay'),
            'manage_network_options',
            'cleversay-network-advanced',
            [$this, 'render_advanced_settings']
        );

        // v4.38.0+: Embeddings/Vector Search settings.
        // Phase 1 of the embeddings migration — see ARCHITECTURE.md.
        add_submenu_page(
            'cleversay-network',
            __('Embeddings & Vector Search', 'cleversay'),
            __('Embeddings', 'cleversay'),
            'manage_network_options',
            'cleversay-network-embeddings',
            [$this, 'render_embeddings_settings']
        );

        add_submenu_page(
            'cleversay-network',
            __('Client Sites', 'cleversay'),
            __('Client Sites', 'cleversay'),
            'manage_network_options',
            'cleversay-network-sites',
            [$this, 'render_client_sites']
        );

        add_submenu_page(
            'cleversay-network',
            __('Provision New Client', 'cleversay'),
            __('New Client', 'cleversay'),
            'manage_network_options',
            'cleversay-network-provision',
            [$this, 'render_provision']
        );

        add_submenu_page(
            'cleversay-network',
            __('Updates', 'cleversay'),
            __('Updates', 'cleversay'),
            'manage_network_options',
            'cleversay-network-updates',
            [$this, 'render_updates']
        );

        add_submenu_page(
            'cleversay-network',
            __('Appearance', 'cleversay'),
            __('Appearance', 'cleversay'),
            'manage_network_options',
            'cleversay-network-custom-css',
            [$this, 'render_custom_css']
        );
    }

    public function render_custom_css(): void {
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/network/custom-css.php';
    }

    // ── Assets ───────────────────────────────────────────────────────────────

    public function enqueue_assets(string $hook): void {
        if (strpos($hook, 'cleversay-network') === false) {
            return;
        }

        // Load the configured admin font (default: Outfit)
        $font_family = get_site_option('cleversay_admin_font_family', 'Outfit');
        $font_size   = (int) get_site_option('cleversay_admin_font_size', 14);
        $font_url    = self::get_google_font_url($font_family);

        wp_enqueue_style(
            'cleversay-font-outfit',
            $font_url,
            [],
            null
        );
        wp_enqueue_style(
            'cleversay-network-admin',
            CLEVERSAY_PLUGIN_URL . 'admin/css/admin.css',
            ['cleversay-font-outfit'],
            CLEVERSAY_VERSION
        );

        // Apply font family + size as inline CSS. Uses !important and specific
        // child selectors to override both admin.css defaults and WordPress
        // admin styles.
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
        wp_add_inline_style('cleversay-network-admin', $inline);

        // Custom overrides — load after core CSS so they win without !important
        $custom_css = get_site_option('cleversay_custom_admin_css', '');
        if (!empty($custom_css)) {
            wp_add_inline_style('cleversay-network-admin', $custom_css);
        }

        // Media uploader for logo upload on Client Sites page
        if (isset($_GET['page']) && in_array(
            sanitize_text_field(wp_unslash($_GET['page'])),
            ['cleversay-network-sites'],
            true
        )) {
            wp_enqueue_media();
            wp_enqueue_script(
                'cleversay-network-media',
                CLEVERSAY_PLUGIN_URL . 'admin/js/network-media.js',
                ['jquery', 'media-upload'],
                CLEVERSAY_VERSION,
                true
            );
        }
    }

    /**
     * Build a Google Fonts URL for the given font family.
     * Returns a safe URL that always loads the 400/500/600/700/800 weights.
     */
    public static function get_google_font_url(string $family): string {
        $family = trim($family);
        if ($family === '' || $family === 'System') {
            // Fallback: still load Outfit as a graceful default
            $family = 'Outfit';
        }
        // Google Fonts wants spaces as `+`
        $family_param = str_replace(' ', '+', $family);
        return "https://fonts.googleapis.com/css2?family={$family_param}:wght@400;500;600;700;800&display=swap";
    }

    /**
     * Curated list of Google Fonts that work well for admin UI.
     * Used by the font picker view.
     */
    public static function get_font_options(): array {
        return [
            'Outfit'        => ['label' => 'Outfit',        'sample' => 'Geometric, modern (default)'],
            'Inter'         => ['label' => 'Inter',         'sample' => 'Clean, neutral, very readable'],
            'Plus Jakarta Sans' => ['label' => 'Plus Jakarta Sans', 'sample' => 'Friendly, rounded'],
            'DM Sans'       => ['label' => 'DM Sans',       'sample' => 'Compact geometric'],
            'Nunito'        => ['label' => 'Nunito',        'sample' => 'Soft, rounded corners'],
            'Poppins'       => ['label' => 'Poppins',       'sample' => 'Geometric, wide'],
            'Lato'          => ['label' => 'Lato',          'sample' => 'Warm, humanist'],
            'Manrope'       => ['label' => 'Manrope',       'sample' => 'Sophisticated, modern'],
            'Work Sans'     => ['label' => 'Work Sans',     'sample' => 'Optimized for screens'],
            'IBM Plex Sans' => ['label' => 'IBM Plex Sans', 'sample' => 'Technical, clear'],
            'Figtree'       => ['label' => 'Figtree',       'sample' => 'Corporate, clean'],
            'Sora'          => ['label' => 'Sora',          'sample' => 'Geometric, distinct'],
            'Space Grotesk' => ['label' => 'Space Grotesk', 'sample' => 'Bold, technical'],
            'Urbanist'      => ['label' => 'Urbanist',      'sample' => 'Low-contrast geometric'],
            'Rubik'         => ['label' => 'Rubik',         'sample' => 'Friendly, rounded geometric'],
        ];
    }

    // ── Notices ───────────────────────────────────────────────────────────────

    public function show_notices(): void {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'cleversay-network') === false) {
            return;
        }

        if (isset($_GET['updated']) && $_GET['updated'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Settings saved.', 'cleversay')
                . '</p></div>';
        }
        if (isset($_GET['error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__('Error saving settings. Please try again.', 'cleversay')
                . '</p></div>';
        }
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function render_dashboard(): void {
        $sites = NetworkSettings::get_client_sites();
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/network/dashboard.php';
    }

    // ── AI Settings ───────────────────────────────────────────────────────────

    public function render_ai_settings(): void {
        $settings = NetworkSettings::get_ai();
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/network/ai-settings.php';
    }

    private function save_ai_settings(): void {
        // v4.37.74+: per-provider keys. Read both from POST. The legacy
        // 'api_key' is kept in storage for back-compat with any caller
        // that hasn't migrated to get_ai_config — write it as the
        // currently-active provider's key so older code paths still
        // work transparently.
        //
        // v4.42.2+: three providers now. Same preserve-on-empty pattern
        // applies to all three keys.
        $anthropic_key = sanitize_text_field(wp_unslash($_POST['anthropic_api_key'] ?? ''));
        $gemini_key    = sanitize_text_field(wp_unslash($_POST['gemini_api_key']    ?? ''));
        $openai_key    = sanitize_text_field(wp_unslash($_POST['openai_api_key']    ?? ''));
        $model         = sanitize_text_field(wp_unslash($_POST['model'] ?? 'claude-haiku-4-5-20251001'));
        $active_prov   = NetworkSettings::provider_for_model($model);

        // Preserve any existing per-provider key when its field came in
        // empty — empty submission shouldn't wipe a saved key, only an
        // explicit change should. (Legacy single-field had the same
        // behavior: empty submit was treated as "don't change.")
        $existing = NetworkSettings::get_ai();
        if ($anthropic_key === '') $anthropic_key = (string) ($existing['anthropic_api_key'] ?? '');
        if ($gemini_key    === '') $gemini_key    = (string) ($existing['gemini_api_key']    ?? '');
        if ($openai_key    === '') $openai_key    = (string) ($existing['openai_api_key']    ?? '');

        // Resolve active_key from the (possibly preserved) per-provider
        // values, based on the current default-model's provider.
        $active_key = match ($active_prov) {
            'gemini' => $gemini_key,
            'openai' => $openai_key,
            default  => $anthropic_key,
        };

        $data = [
            'api_key'            => $active_key,
            'anthropic_api_key'  => $anthropic_key,
            'gemini_api_key'     => $gemini_key,
            'openai_api_key'     => $openai_key,
            'model'              => $model,
            // v4.41.5.2+: synthesis_model is the model used for the
            // chat-answer call (answer_with_context). Read from POST if
            // the form provided it (the new dropdown does), otherwise
            // preserve the existing saved value rather than letting
            // save_ai() reset it to the schema default. Without this
            // preservation, every save of the AI settings page would
            // silently flip synthesis_model back to default.
            'synthesis_model'    => isset($_POST['synthesis_model'])
                ? sanitize_text_field(wp_unslash($_POST['synthesis_model']))
                : (string) ($existing['synthesis_model'] ?? 'claude-sonnet-4-5-20250929'),
            // v4.41.5.7+: validator_model now has a UI dropdown (added
            // in this version). Read from POST when the form provided
            // it, fall back to the existing saved value when missing
            // (e.g., if a future form variant doesn't render this
            // field). Preserves the no-silent-reset guarantee from
            // v4.41.5.2.
            'validator_model'    => isset($_POST['validator_model'])
                ? sanitize_text_field(wp_unslash($_POST['validator_model']))
                : (string) ($existing['validator_model'] ?? 'claude-sonnet-4-5-20250929'),
            // v4.42.1+: polish_model selector. Polish rewrites accepted
            // KB answers in tenant tone — constrained transformation
            // that defaults to the smaller model. Same no-silent-reset
            // preservation pattern as the other model selectors above.
            'polish_model'       => isset($_POST['polish_model'])
                ? sanitize_text_field(wp_unslash($_POST['polish_model']))
                : (string) ($existing['polish_model'] ?? 'claude-haiku-4-5-20251001'),
            'max_tokens'         => (int) ($_POST['max_tokens'] ?? 1000),
            'ai_enabled'         => !empty($_POST['ai_enabled']),
            'monthly_budget'     => (float) ($_POST['monthly_budget'] ?? 0),
            'fallback_threshold' => (int) ($_POST['fallback_threshold'] ?? 70),
            'validate_kb'        => !empty($_POST['validate_kb']),
            'polish_kb'          => !empty($_POST['polish_kb']),
            'aadefault_validate' => !empty($_POST['aadefault_validate']),
            'multilingual'       => !empty($_POST['multilingual']),
        ];
        NetworkSettings::save_ai($data);
    }

    // ── Advanced Settings ─────────────────────────────────────────────────────

    public function render_advanced_settings(): void {
        $settings = NetworkSettings::get_advanced();
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/network/advanced-settings.php';
    }

    private function save_advanced_settings(): void {
        $data = [
            'rate_limit_enabled'  => !empty($_POST['rate_limit_enabled']),
            'rate_limit_requests' => (int) ($_POST['rate_limit_requests'] ?? 30),
            'rate_limit_window'   => (int) ($_POST['rate_limit_window'] ?? 60),
            'cache_duration'      => (int) ($_POST['cache_duration'] ?? 300),
            'debug_mode'          => !empty($_POST['debug_mode']),
            'min_match_score'     => (int) ($_POST['min_match_score'] ?? 70),
            'max_results'         => (int) ($_POST['max_results'] ?? 5),
            'log_retention_days'  => (int) ($_POST['log_retention_days'] ?? 30),
        ];
        NetworkSettings::save_advanced($data);
    }

    // ── Embeddings / Vector Search Settings ──────────────────────────────────
    //
    // v4.38.0+: Phase 1 of the embeddings migration — see ARCHITECTURE.md.
    // Stores Supabase Postgres connection details and OpenAI API key for
    // generating embeddings. The feature flag here gates whether vector
    // retrieval runs alongside FULLTEXT in the retrieval layer.

    public function render_embeddings_settings(): void {
        $settings = \CleverSay\Supabase::get_config();
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/network/embeddings-settings.php';
    }

    private function save_supabase_settings(): void {
        // Preserve existing password and API key if their fields came in
        // empty — empty submission shouldn't wipe a saved secret. This
        // matches the behavior of the AI Settings page for provider keys.
        $existing = \CleverSay\Supabase::get_config();
        $password = sanitize_text_field(wp_unslash($_POST['password'] ?? ''));
        $api_key  = sanitize_text_field(wp_unslash($_POST['openai_api_key'] ?? ''));
        if ($password === '') $password = (string) ($existing['password'] ?? '');
        if ($api_key  === '') $api_key  = (string) ($existing['openai_api_key'] ?? '');

        $data = [
            'host'                 => sanitize_text_field(wp_unslash($_POST['host'] ?? '')),
            'port'                 => (int) ($_POST['port'] ?? 5432),
            'database'             => sanitize_text_field(wp_unslash($_POST['database'] ?? 'postgres')),
            'user'                 => sanitize_text_field(wp_unslash($_POST['user'] ?? 'postgres')),
            'password'             => $password,
            'enabled'              => !empty($_POST['enabled']),
            'openai_api_key'       => $api_key,
            'embedding_model'      => sanitize_text_field(wp_unslash($_POST['embedding_model'] ?? 'text-embedding-3-small')),
            // Phase 3 (v4.40.0): hybrid retrieval flag. Independent from `enabled`.
            'use_hybrid_retrieval' => !empty($_POST['use_hybrid_retrieval']),
        ];
        \CleverSay\Supabase::save_config($data);
    }

    // ── Updates ───────────────────────────────────────────────────────────────

    public function render_updates(): void {
        $updater          = new \CleverSay\Updater();
        $client_sites     = NetworkSettings::get_client_sites();
        $daily_snapshots  = $updater->list_snapshots('daily');
        $manual_snapshots = $updater->list_snapshots('manual');
        $prod_version     = $updater->get_production_version();
        $staging_version  = $updater->get_staging_version();
        $staging_exists   = $updater->staging_exists();
        $action_result    = get_transient('cleversay_updater_result_' . get_current_user_id());
        $action_success   = get_transient('cleversay_updater_success_' . get_current_user_id());
        if ($action_result) {
            delete_transient('cleversay_updater_result_' . get_current_user_id());
            delete_transient('cleversay_updater_success_' . get_current_user_id());
        }
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/network/updates.php';
    }

    /**
     * Apply a starter KB pack to an existing client site. Fired from the
     * Client Sites row's "Install Pack" button. Switches to the target blog,
     * runs Provisioner::install_starter_kb (skip-on-duplicate), then redirects
     * back with a transient-stored success message.
     */
    private function save_apply_starter_pack(): void {
        $site_id   = (int) ($_POST['site_id']   ?? 0);
        $pack_slug = sanitize_text_field(wp_unslash($_POST['pack_slug'] ?? ''));

        if ($site_id <= 1 || $pack_slug === '' || $pack_slug === 'empty') {
            wp_redirect(add_query_arg([
                'page'      => 'cleversay-network-sites',
                'pack_err'  => 'invalid',
            ], network_admin_url('admin.php')));
            exit;
        }

        // Verify the site actually exists in this network
        if (!get_blog_details($site_id)) {
            wp_redirect(add_query_arg([
                'page'      => 'cleversay-network-sites',
                'pack_err'  => 'no_site',
            ], network_admin_url('admin.php')));
            exit;
        }

        // Verify the pack exists
        $packs = \CleverSay\StarterKB::packs();
        if (!isset($packs[$pack_slug])) {
            wp_redirect(add_query_arg([
                'page'      => 'cleversay-network-sites',
                'pack_err'  => 'no_pack',
            ], network_admin_url('admin.php')));
            exit;
        }

        switch_to_blog($site_id);
        $summary = \CleverSay\Provisioner::install_starter_kb($pack_slug);
        restore_current_blog();

        // Stash result for display on redirect (transients survive a redirect cycle)
        set_transient(
            'cleversay_pack_result_' . get_current_user_id(),
            [
                'site_id'  => $site_id,
                'pack'     => $pack_slug,
                'pack_label' => $packs[$pack_slug]['label'] ?? $pack_slug,
                'added'    => (int) ($summary['added']   ?? 0),
                'skipped'  => (int) ($summary['skipped'] ?? 0),
            ],
            60
        );

        wp_redirect(add_query_arg([
            'page'         => 'cleversay-network-sites',
            'pack_applied' => '1',
        ], network_admin_url('admin.php')));
        exit;
    }

    private function handle_updater_action(): void {
        $action  = sanitize_key($_POST['cleversay_updater_action'] ?? '');
        $updater = new \CleverSay\Updater();
        $result  = ['success' => false, 'message' => 'Unknown action.'];

        switch ($action) {
            case 'upload_staging':
                if (!empty($_FILES['plugin_zip'])) {
                    $result = $updater->upload_to_staging($_FILES['plugin_zip']);
                }
                break;

            case 'push_to_production':
                $result = $updater->push_staging_to_production(true);
                break;

            case 'create_snapshot':
                $label  = sanitize_text_field(wp_unslash($_POST['snapshot_label'] ?? ''));
                $result = $updater->create_snapshot('manual', $label);
                break;

            case 'restore_snapshot':
                $path   = sanitize_text_field(wp_unslash($_POST['snapshot_path'] ?? ''));
                $result = $updater->restore_snapshot($path);
                break;

            case 'delete_snapshot':
                $path   = sanitize_text_field(wp_unslash($_POST['snapshot_path'] ?? ''));
                $result = $updater->delete_snapshot($path);
                break;

            case 'copy_to_staging':
                $source_id = (int) ($_POST['source_blog_id'] ?? 0);
                if ($source_id > 0) {
                    $result = $updater->copy_to_staging(
                        $source_id,
                        !empty($_POST['copy_kb']),
                        !empty($_POST['copy_sources']),
                        !empty($_POST['copy_synonyms']),
                        !empty($_POST['clear_first'])
                    );
                }
                break;
        }

        $uid = get_current_user_id();
        set_transient('cleversay_updater_result_'  . $uid, $result['message'],     60);
        set_transient('cleversay_updater_success_' . $uid, $result['success'],     60);

        wp_redirect(add_query_arg(['page' => 'cleversay-network-updates'], network_admin_url('admin.php')));
        exit;
    }

    // ── Network Hub Cleanup ───────────────────────────────────────────────────

    /**
     * Hide noisy WordPress menu items when super admin is on a client subsite.
     * Keeps CleverSay menu and anything needed for admin tasks.
     */
    public function clean_subsite_menu(): void {
        remove_menu_page('edit.php');              // Posts
        remove_menu_page('edit-comments.php');     // Comments
        remove_menu_page('tools.php');             // Tools
        remove_menu_page('themes.php');            // Appearance
        remove_menu_page('index.php');             // Dashboard
        remove_menu_page('plugins.php');           // Plugins

        // Move CleverSay to position 0 (first item), and CleverSay Tools
        // immediately after at position 0.1. Done in one pass: identify
        // both menus, then reassign their array keys.
        //
        // v4.42.21+: Tools menu was previously left at its registered
        // position '30.1' when CleverSay got moved to 0. With other
        // WordPress menus (Media at 10, Pages at 20) sitting between
        // them, the two menus appeared split apart in the sidebar.
        // Relocating Tools alongside CleverSay keeps them together
        // regardless of what else is registered.
        global $menu;
        $cleversay_item = null;
        $tools_item     = null;
        foreach ($menu as $pos => $item) {
            if (!isset($item[2])) continue;
            if ($item[2] === 'cleversay') {
                $cleversay_item = $item;
                unset($menu[$pos]);
            } elseif ($item[2] === 'cs-tools') {
                $tools_item = $item;
                unset($menu[$pos]);
            }
        }
        if ($cleversay_item !== null) {
            $menu[0] = $cleversay_item;
        }
        if ($tools_item !== null) {
            // String key preserves the fractional sort position.
            $menu['0.1'] = $tools_item;
        }
        ksort($menu);
    }

    /**
     * Hide WordPress native network admin menus we don't need.
     * Everything is managed through CleverSay's own network menu.
     */
    public function clean_network_menu(): void {
        remove_menu_page('sites.php');
        remove_menu_page('users.php');
        remove_menu_page('themes.php');
        remove_menu_page('plugins.php');
        remove_menu_page('settings.php');
        remove_menu_page('update-core.php');
        remove_menu_page('network/settings.php');
    }

    /**
     * Redirect the WP network dashboard to CleverSay Network Dashboard.
     */
    public function redirect_network_dashboard(): void {
        if (!is_network_admin()) return;
        global $pagenow;
        if ($pagenow !== 'index.php') return;
        wp_redirect(network_admin_url('admin.php?page=cleversay-network'));
        exit;
    }

    /**
     * Clean the admin bar in Network Admin context.
     * Replace the noisy My Sites dropdown with a clean per-client list.
     */
    public function clean_network_admin_bar(\WP_Admin_Bar $wp_admin_bar): void {
        // Fires on all admin pages for super admins (network + client subsites)

        // Remove noisy items
        $remove = [
            'wp-logo',
            'about',
            'wporg',
            'documentation',
            'support-forums',
            'feedback',
            'updates',
            'comments',
            'new-content',
            'site-name',
            'search',
        ];
        foreach ($remove as $id) {
            $wp_admin_bar->remove_node($id);
        }

        // Replace My Sites with a clean CleverSay-branded dropdown
        $wp_admin_bar->remove_node('my-sites');

        // Determine context — network admin vs client subsite
        $site_label = '';
        if (!is_network_admin()) {
            $plan = NetworkSettings::get_site_plan(get_current_blog_id());
            $site_label = $plan['client_name'] ?? '';
            if (!$site_label) {
                $site_label = get_bloginfo('name');
            }
        }

        $title = is_network_admin()
            ? 'CleverSay'
            : 'CleverSay <span style="opacity:0.7;font-weight:400;">(' . esc_html($site_label) . ')</span>';

        $wp_admin_bar->add_node([
            'id'    => 'cs-sites',
            'title' => $title,
            'href'  => is_network_admin()
                        ? network_admin_url('admin.php?page=cleversay-network')
                        : admin_url('admin.php?page=cleversay'),
            'meta'  => ['class' => 'cs-adminbar-node'],
        ]);

        // Network hub link
        $wp_admin_bar->add_node([
            'id'     => 'cs-sites-hub',
            'parent' => 'cs-sites',
            'title'  => '⚙ ' . __('Network Dashboard', 'cleversay'),
            'href'   => network_admin_url('admin.php?page=cleversay-network'),
        ]);

        // Staging link
        $staging_blog_id = 0;
        $all_sites = get_sites(['number' => 100]);
        foreach ($all_sites as $s) {
            if (str_starts_with($s->domain, 'staging.')) {
                $staging_blog_id = (int) $s->blog_id;
                break;
            }
        }
        if ($staging_blog_id) {
            $wp_admin_bar->add_node([
                'id'     => 'cs-sites-staging',
                'parent' => 'cs-sites',
                'title'  => '🧪 ' . __('Staging', 'cleversay'),
                'href'   => get_admin_url($staging_blog_id, 'admin.php?page=cleversay'),
            ]);
        }

        $wp_admin_bar->add_node([
            'id'     => 'cs-sites-separator',
            'parent' => 'cs-sites',
            'title'  => '<hr style="margin:4px 0;border-color:rgba(255,255,255,0.15);">',
            'href'   => false,
        ]);

        // One link per client site → goes directly to their CleverSay admin
        $sites = NetworkSettings::get_client_sites();
        foreach ($sites as $site) {
            $label  = $site['client_name'] ?: $site['domain'];
            $status = $site['status'] ?? 'active';
            $dot    = match($status) {
                'active'    => '🟢',
                'trial'     => '🟡',
                'suspended' => '🔴',
                default     => '⚪',
            };
            $wp_admin_bar->add_node([
                'id'     => 'cs-site-' . $site['blog_id'],
                'parent' => 'cs-sites',
                'title'  => $dot . ' ' . esc_html($label),
                'href'   => get_admin_url($site['blog_id'], 'admin.php?page=cleversay'),
            ]);
        }
    }

    /**
     * Inject CSS to style the network hub cleanly.
     */
    public function inject_network_hub_styles(): void {
        ?>
        <style>
        /* ── CleverSay Network Hub Styles ── */

        /* Globe/site icon before CleverSay label using dashicons */
        #wpadminbar #wp-admin-bar-cs-sites > .ab-item::before {
            content: '\f319';
            font-family: dashicons;
            font-size: 18px;
            line-height: 1;
            vertical-align: middle;
            margin-right: 5px;
            display: inline-block;
            position: relative;
            top: -1px;
        }

        /* Style the CleverSay admin bar node */
        #wpadminbar #wp-admin-bar-cs-sites > .ab-item {
            font-weight: 600;
        }

        /* Separator in dropdown */
        #wpadminbar #wp-admin-bar-cs-sites-separator > .ab-item {
            padding: 0 !important;
            pointer-events: none;
        }
        </style>
        <?php
    }

    // ── Client Sites ──────────────────────────────────────────────────────────

    public function render_client_sites(): void {
        $sites        = NetworkSettings::get_client_sites();
        $edit_site_id = (int) ($_GET['edit'] ?? 0);
        $edit_plan    = $edit_site_id ? NetworkSettings::get_site_plan($edit_site_id) : [];
        include CLEVERSAY_PLUGIN_DIR . 'admin/views/network/client-sites.php';
    }

    /**
     * Render the "Provision New Client" wizard form.
     */
    public function render_provision(): void {
        $kb_packs = \CleverSay\StarterKB::packs();

        $notice = null;
        $form_values = [];

        if (isset($_POST['cleversay_provision_submit'])) {
            if (!check_admin_referer('cleversay_provision_new_client', '_cs_nonce')) {
                $notice = ['type' => 'error', 'msg' => __('Security check failed. Please retry.', 'cleversay')];
            } else {
                $data = [
                    'subdomain'        => sanitize_text_field($_POST['subdomain'] ?? ''),
                    'title'            => sanitize_text_field($_POST['title'] ?? ''),
                    'client_name'      => sanitize_text_field($_POST['client_name'] ?? ''),
                    'persona_short'    => sanitize_text_field($_POST['persona_short'] ?? ''),
                    'mascot'           => sanitize_text_field($_POST['mascot'] ?? ''),
                    'topics'           => sanitize_text_field($_POST['topics'] ?? ''),
                    'tone'             => sanitize_text_field($_POST['tone'] ?? 'friendly'),
                    'audience'         => sanitize_text_field($_POST['audience'] ?? ''),
                    'primary_color'    => sanitize_text_field($_POST['primary_color'] ?? ''),
                    'starter_kb'       => sanitize_text_field($_POST['starter_kb'] ?? 'empty'),
                    'plan'             => sanitize_text_field($_POST['plan'] ?? 'trial'),
                    'client_email'     => sanitize_email($_POST['client_email'] ?? ''),
                    'send_credentials' => !empty($_POST['send_credentials']),
                ];
                $form_values = $data;

                // Reachability gate — admin must explicitly acknowledge proceeding
                // with an unreachable or HTTP-only subdomain.
                $force_proceed = !empty($_POST['force_proceed']);
                if (!$force_proceed) {
                    $check_result = $this->reachability_gate($data['subdomain']);
                    if (is_wp_error($check_result)) {
                        $notice = [
                            'type' => 'error',
                            'msg'  => $check_result->get_error_message() . ' ' .
                                      __('If you\'re sure the subdomain will be ready shortly, check <em>"Proceed anyway"</em> below and resubmit.', 'cleversay'),
                        ];
                        include CLEVERSAY_PLUGIN_DIR . 'admin/views/network/provision.php';
                        return;
                    }
                }

                $result = \CleverSay\Provisioner::provision($data);

                if (is_wp_error($result)) {
                    $notice = ['type' => 'error', 'msg' => $result->get_error_message()];
                } else {
                    $new_site    = get_site((int) $result);
                    $site_url    = $new_site ? 'https://' . $new_site->domain : '';
                    $dashboard   = $site_url . '/wp-admin/';
                    $notice = [
                        'type' => 'success',
                        'msg'  => sprintf(
                            /* translators: 1: site URL, 2: admin dashboard URL */
                            __('New client site created successfully. Public URL: <a href="%1$s" target="_blank">%1$s</a> &nbsp;|&nbsp; <a href="%2$s" target="_blank">Open Dashboard →</a>', 'cleversay'),
                            esc_url($site_url),
                            esc_url($dashboard)
                        ),
                    ];
                    $form_values = []; // reset form
                }
            }
        }

        include CLEVERSAY_PLUGIN_DIR . 'admin/views/network/provision.php';
    }

    /**
     * Lightweight pre-flight check used by render_provision before calling
     * Provisioner::provision(). Returns null if reachable, WP_Error otherwise.
     * The admin can bypass with the "Proceed anyway" checkbox on the form.
     */
    private function reachability_gate(string $subdomain) {
        $subdomain = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($subdomain)));
        if (strlen($subdomain) < 2) {
            return null; // validation happens in Provisioner::provision anyway
        }
        $main_domain = defined('DOMAIN_CURRENT_SITE') ? DOMAIN_CURRENT_SITE : get_network()->domain;
        $full_domain = $subdomain . '.' . $main_domain;

        $resolved = gethostbyname($full_domain);
        if ($resolved === $full_domain) {
            return new \WP_Error('dns_fail', sprintf(
                __('%s does not resolve. Add the subdomain in cPanel first (and wait for DNS to propagate).', 'cleversay'),
                $full_domain
            ));
        }

        $https = wp_remote_head('https://' . $full_domain . '/', [
            'timeout'     => 8,
            'redirection' => 1,
            'sslverify'   => true,
        ]);
        if (is_wp_error($https)) {
            return new \WP_Error('https_fail', sprintf(
                __('%s resolves but HTTPS is not working — enable SSL in cPanel before creating the WordPress site.', 'cleversay'),
                $full_domain
            ));
        }

        return null;
    }

    /**
     * AJAX endpoint — ping a proposed subdomain to see if it's reachable
     * via HTTPS. Used by the provisioning wizard to warn the super-admin
     * when they haven't added the subdomain to cPanel / don't have SSL yet.
     *
     * Returns JSON with a 'state' field:
     *   ssl_ok       — resolves AND HTTPS works
     *   http_only    — resolves but SSL fails / not configured
     *   dns_fail     — doesn't resolve at all
     *   collision    — resolves AND a WordPress site already claims this domain
     */
    public function ajax_check_subdomain(): void {
        check_ajax_referer('cleversay_provision_check', 'nonce');
        if (!current_user_can('manage_network_options')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }

        $subdomain = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($_POST['subdomain'] ?? '')));
        if ($subdomain === '' || strlen($subdomain) < 2) {
            wp_send_json_error(['message' => __('Enter a valid subdomain.', 'cleversay')], 400);
        }

        $main_domain = defined('DOMAIN_CURRENT_SITE') ? DOMAIN_CURRENT_SITE : get_network()->domain;
        $full_domain = $subdomain . '.' . $main_domain;

        // 1. WordPress-level collision check (fastest)
        if (domain_exists($full_domain, '/')) {
            wp_send_json_success([
                'state'       => 'collision',
                'full_domain' => $full_domain,
                'message'     => __('A WordPress site already exists for this subdomain.', 'cleversay'),
            ]);
        }

        // 2. DNS resolution — cheap filter for nothing-configured
        $resolved_ip = gethostbyname($full_domain);
        if ($resolved_ip === $full_domain) {
            wp_send_json_success([
                'state'       => 'dns_fail',
                'full_domain' => $full_domain,
                'message'     => sprintf(
                    /* translators: %s = full subdomain */
                    __('%s does not resolve. Add the subdomain in your hosting control panel first.', 'cleversay'),
                    $full_domain
                ),
            ]);
        }

        // 3. HTTPS reachability — TLS handshake is what we care about.
        // 404 with valid SSL is fine: cPanel is ready, WordPress just hasn't
        // claimed the subdomain yet.
        $https_response = wp_remote_head('https://' . $full_domain . '/', [
            'timeout'     => 8,
            'redirection' => 1,
            'sslverify'   => true,
        ]);

        if (is_wp_error($https_response)) {
            $err = $https_response->get_error_message();

            // Fall back to HTTP to distinguish SSL problems from DNS/firewall
            $http_response = wp_remote_head('http://' . $full_domain . '/', [
                'timeout'     => 6,
                'redirection' => 1,
                'sslverify'   => false,
            ]);

            if (!is_wp_error($http_response)) {
                wp_send_json_success([
                    'state'       => 'http_only',
                    'full_domain' => $full_domain,
                    'message'     => __('Subdomain resolves over HTTP, but HTTPS failed. Enable SSL in your control panel before provisioning.', 'cleversay'),
                    'detail'      => $err,
                ]);
            }

            wp_send_json_success([
                'state'       => 'dns_fail',
                'full_domain' => $full_domain,
                'message'     => __('Subdomain is not reachable. Verify DNS + cPanel subdomain entry.', 'cleversay'),
                'detail'      => $err,
            ]);
        }

        wp_send_json_success([
            'state'       => 'ssl_ok',
            'full_domain' => $full_domain,
            'message'     => __('Subdomain is reachable over HTTPS and ready to provision.', 'cleversay'),
        ]);
    }
}
