<?php
/**
 * Plugin Name: CleverSay Knowledge Base
 * Plugin URI: https://cleversay.com
 * Description: A modern, AI-powered knowledge base and FAQ chatbot system for WordPress.
 * Version: 4.39.0
 * Author: CleverSay Team
 * Author URI: https://cleversay.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cleversay
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// PHP 7.4 polyfills for functions introduced in PHP 8.0
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        if ($needle === '') return true;
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

// Define plugin constants
defined('CLEVERSAY_VERSION')        || define('CLEVERSAY_VERSION', '4.39.0');
defined('CLEVERSAY_PLUGIN_DIR')     || define('CLEVERSAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
defined('CLEVERSAY_PLUGIN_URL')     || define('CLEVERSAY_PLUGIN_URL', plugin_dir_url(__FILE__));
defined('CLEVERSAY_PLUGIN_BASENAME')|| define('CLEVERSAY_PLUGIN_BASENAME', plugin_basename(__FILE__));
defined('CLEVERSAY_DB_VERSION')     || define('CLEVERSAY_DB_VERSION', '4.39.0');

/**
 * Activation hook - creates database tables
 */
if (!function_exists('cleversay_activate')):
function cleversay_activate(): void {
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-database.php';
    $database = new \CleverSay\Database();
    $database->create_tables();
    $database->set_default_options();
    
    // Store the database version
    update_option('cleversay_db_version', CLEVERSAY_DB_VERSION);
    
    // Schedule cleanup cron
    if (!wp_next_scheduled('cleversay_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'cleversay_daily_cleanup');
    }
    if (!wp_next_scheduled('cleversay_daily_snapshot')) {
        wp_schedule_event(strtotime('02:00:00'), 'daily', 'cleversay_daily_snapshot');
    }
    if (!wp_next_scheduled('cleversay_source_refresh')) {
        wp_schedule_event(time() + 300, 'hourly', 'cleversay_source_refresh');
    }

    // v4.39.0+: Phase 2 of embeddings migration. Schedules the
    // embedding queue processor. With system cron pointed at
    // wp-cron.php, this runs every 5 minutes. Without it, runs
    // whenever traffic fires the cron check.
    // See ARCHITECTURE.md → "Scaling Trajectory".
    if (!wp_next_scheduled('cleversay_process_embeddings')) {
        wp_schedule_event(time() + 60, 'cleversay_5min', 'cleversay_process_embeddings');
    }
    
    // Generate embed token if not already set
    if (!get_option('cleversay_embed_token')) {
        $token = function_exists('random_bytes')
            ? bin2hex(random_bytes(24))
            : wp_generate_password(48, false, false);
        update_option('cleversay_embed_token', $token);
    }

    // Build embed.min.js on activation
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-minifier.php';
    \CleverSay\Minifier::rebuild_embed_min();

    flush_rewrite_rules();
}
endif;
register_activation_hook(__FILE__, 'cleversay_activate');

/**
 * Deactivation hook
 */
if (!function_exists('cleversay_deactivate')):
function cleversay_deactivate(): void {
    wp_clear_scheduled_hook('cleversay_daily_cleanup');
    wp_clear_scheduled_hook('cleversay_daily_snapshot');
    wp_clear_scheduled_hook('cleversay_link_validation');
    wp_clear_scheduled_hook('cleversay_source_refresh');
    wp_clear_scheduled_hook('cleversay_trial_check');
    wp_clear_scheduled_hook('cleversay_process_embeddings');
    flush_rewrite_rules();
}
endif;
register_deactivation_hook(__FILE__, 'cleversay_deactivate');

/**
 * Daily cleanup cron handler — purges old AI debug log entries.
 * Hook is scheduled in cleversay_activate(); this is its handler.
 */
add_action('cleversay_daily_cleanup', function () {
    if (!class_exists('\\CleverSay\\AIDebugLog')) {
        require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-ai-debug-log.php';
    }
    \CleverSay\AIDebugLog::prune();
});

/**
 * Multisite: auto-create CleverSay tables when a new subsite is added.
 */
if (!function_exists('cleversay_on_new_blog')):
function cleversay_on_new_blog(\WP_Site $new_site): void {
    if (is_plugin_active_for_network('cleversay/cleversay.php')) {
        switch_to_blog((int) $new_site->blog_id);
        require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-database.php';
        $database = new \CleverSay\Database();
        $database->create_tables();
        $database->set_default_options();
        update_option('cleversay_db_version', CLEVERSAY_DB_VERSION);
        // Generate unique embed token per site
        if (!get_option('cleversay_embed_token')) {
            $token = function_exists('random_bytes')
                ? bin2hex(random_bytes(24))
                : wp_generate_password(48, false, false);
            update_option('cleversay_embed_token', $token);
        }
        restore_current_blog();
    }
}
endif;
add_action('wp_initialize_site', 'cleversay_on_new_blog', 900);

/**
 * Network Admin init — only runs in network admin context.
 */
if (!function_exists('cleversay_network_admin_init')):
function cleversay_network_admin_init(): void {
    // NetworkAdmin handles network admin pages AND admin bar cleanup sitewide
    if (!is_multisite() || !is_super_admin()) return;
    if (!is_admin() && !is_network_admin()) return;
    cleversay_load_dependencies();
    $network_admin = new \CleverSay\NetworkAdmin();
    $network_admin->init();
}
endif;
add_action('plugins_loaded', 'cleversay_network_admin_init');

/**
 * Initialize TrialEnforcer cron handler.
 * Runs on every request because the cron callback needs to be registered
 * with WP-Cron. is_multisite check inside the class prevents work on
 * single-site installs.
 */
if (!function_exists('cleversay_init_trial_enforcer')):
function cleversay_init_trial_enforcer(): void {
    if (!is_multisite()) return;
    cleversay_load_dependencies();
    \CleverSay\TrialEnforcer::init();
}
endif;
add_action('plugins_loaded', 'cleversay_init_trial_enforcer');



/**
 * Load plugin text domain
 */
if (!function_exists('cleversay_load_textdomain')):
function cleversay_load_textdomain(): void {
    load_plugin_textdomain(
        'cleversay',
        false,
        dirname(CLEVERSAY_PLUGIN_BASENAME) . '/languages/'
    );
}
endif;
add_action('init', 'cleversay_load_textdomain');

/**
 * Load dependencies
 */
if (!function_exists('cleversay_load_dependencies')):
function cleversay_load_dependencies(): void {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-minifier.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-logger.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-database.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-icons.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/data-irregulars.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/data-legacy-synonyms.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/data-wordnet-dictionary.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/data-wordnet-pos.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-search.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-spellcheck.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-analytics.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-import-export.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-api.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-ai.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-citation-selector.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-ai-debug-log.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-kb-pattern-compiler.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-kb-variations.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-indexer.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-sources.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-crawler.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-network-settings.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-supabase.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-embeddings.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-embedder.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-starter-kb.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-provisioner.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-trial-enforcer.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'admin/class-admin.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'admin/class-network-admin.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'admin/class-updater.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'admin/class-client-portal.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'public/class-public.php';
}
endif;

/**
 * Initialize admin functionality
 */
if (!function_exists('cleversay_admin_init')):
function cleversay_admin_init(): void {
    if (!is_admin()) {
        return;
    }
    
    cleversay_load_dependencies();
    
    // Check if database needs updating (handles upgrades without re-activation)
    $installed_version = get_option('cleversay_db_version', '0');
    if (version_compare($installed_version, CLEVERSAY_DB_VERSION, '<')) {
        $database = new \CleverSay\Database();
        $database->create_tables();
        // Strip backslashes from records saved before wp_unslash was applied (pre-2.4.4)
        if (version_compare($installed_version, '4.31.0', '<')) {
            $database->strip_slashes_from_records();
        }
        // v4.37.2+: question words (what/when/where/who/why/how/which/whom)
        // are no longer seeded as stopwords. Existing installs likely
        // still have them set is_active=1 from earlier seeds — disable
        // them once on upgrade. Idempotent (admin can re-enable
        // individually via Settings if they want).
        if (version_compare($installed_version, '4.37.2', '<')) {
            $database->disable_question_word_stopwords();
        }
        // v4.37.7+: import legacy ailiza_spellcheck synonyms. Non-
        // destructive — never overwrites admin-curated rows. The
        // legacy data covers ~63 university-context synonyms (picture
        // -> photo, animal -> pet, money/aid -> financial, etc.) plus
        // common misspellings.
        if (version_compare($installed_version, '4.37.7', '<')) {
            $database->import_legacy_synonyms();
        }
        // v4.37.17+: disable negation/temporal stopwords (no, not,
        // before, after). They invert or frame question meaning and
        // were silently routing opposite questions to the same
        // matcher input. Soft-disable on existing installs so admins
        // can re-enable individually via Settings if needed.
        if (version_compare($installed_version, '4.37.17', '<')) {
            $database->disable_negation_stopwords();
        }
        // v4.37.30+: insert subordinator/connective stopwords (if, about,
        // because, although, though). Compile/runtime drift was visible
        // in the trace where "if" passed through to final search keywords
        // even though the compiler stripped it.
        if (version_compare($installed_version, '4.37.30', '<')) {
            $database->insert_subordinator_stopwords();
        }
        // v4.37.50+: add tiebreak observability columns to questions_log.
        // Required by the AI Decisions admin page so tiebreak events
        // can be queried by date range alongside validation rejections.
        if (version_compare($installed_version, '4.37.50', '<')) {
            $database->add_tiebreak_columns();
        }
        // v4.37.52+: add polished_hash column to knowledge_base for
        // admin-time Polish Answer feature. Tracks the hash of the
        // response text at the time admin approved an AI polish, so
        // the runtime can skip redundant Polish KB calls when the
        // response hasn't been edited since.
        if (version_compare($installed_version, '4.37.52', '<')) {
            $database->add_polished_hash_column();
        }
        update_option('cleversay_db_version', CLEVERSAY_DB_VERSION);
    }

    // Rebuild embed.min.js whenever the plugin version changes
    $built_version = get_option('cleversay_embed_built_version', '');
    if ($built_version !== CLEVERSAY_VERSION) {
        if (\CleverSay\Minifier::rebuild_embed_min()) {
            update_option('cleversay_embed_built_version', CLEVERSAY_VERSION);
        }
    }

    $admin = new \CleverSay\Admin();
    $admin->init();

    // Multisite client portal lockdown and branding
    if (is_multisite() && !is_network_admin()) {
        $portal = new \CleverSay\ClientPortal();
        $portal->init();
    }
}
endif;
add_action('plugins_loaded', 'cleversay_admin_init');

/**
 * Initialize public functionality
 */
if (!function_exists('cleversay_public_init')):
function cleversay_public_init(): void {
    // Allow AJAX requests (they go through admin-ajax.php so is_admin() is true)
    $is_ajax = defined('DOING_AJAX') && DOING_AJAX;
    
    // Skip for admin pages (but not for AJAX)
    if (is_admin() && !$is_ajax) {
        return;
    }
    
    cleversay_load_dependencies();
    $public = new \CleverSay\PublicFacing();
    $public->init();
}
endif;
add_action('plugins_loaded', 'cleversay_public_init');

/**
 * Initialize REST API
 */
if (!function_exists('cleversay_api_init')):
function cleversay_api_init(): void {
    cleversay_load_dependencies();
    $api = new \CleverSay\API();
    $api->init();
}
endif;
add_action('plugins_loaded', 'cleversay_api_init');

/**
 * Get a component instance
 * 
 * @param string $component Component name
 * @return object|null
 */
if (!function_exists('cleversay_get')):
function cleversay_get(string $component): ?object {
    static $instances = [];
    
    if (!isset($instances[$component])) {
        cleversay_load_dependencies();
        
        switch ($component) {
            case 'database':      $instances[$component] = new \CleverSay\Database();     break;
            case 'search':        $instances[$component] = new \CleverSay\Search();       break;
            case 'spellcheck':    $instances[$component] = new \CleverSay\Spellcheck();   break;
            case 'analytics':     $instances[$component] = new \CleverSay\Analytics();    break;
            case 'import_export': $instances[$component] = new \CleverSay\ImportExport(); break;
            default:              $instances[$component] = null;                           break;
        }
    }
    
    return $instances[$component];
}
endif;

/**
 * Login page branding — fires before authentication so works for all users.
 */
if (!function_exists('cleversay_login_branding')):
function cleversay_login_branding(): void {
    if (!is_multisite() || is_main_site()) return;
    // Skip reserved subsites (network, staging)
    $domain    = parse_url(home_url(), PHP_URL_HOST) ?? '';
    $subdomain = explode('.', $domain)[0] ?? '';
    if (in_array($subdomain, ['network', 'staging'], true)) return;

    cleversay_load_dependencies();
    $portal = new \CleverSay\ClientPortal();
    add_action('login_enqueue_scripts', [$portal, 'login_styles']);
    add_filter('login_headerurl',       [$portal, 'login_header_url']);
    add_filter('login_headertext',      [$portal, 'login_header_text']);
    add_filter('login_body_class',      [$portal, 'login_body_class']);
}
endif;
add_action('init', 'cleversay_login_branding', 5);

/**
 * Daily snapshot cron handler.
 */
if (!function_exists('cleversay_run_daily_snapshot')):
function cleversay_run_daily_snapshot(): void {
    cleversay_load_dependencies();
    $updater = new \CleverSay\Updater();
    $updater->run_daily_snapshot();
}
endif;
add_action('cleversay_daily_snapshot', 'cleversay_run_daily_snapshot');

/**
 * Hourly cron — re-crawl URL sources that are due based on their
 * refresh_interval. Hash-based diff detection in the indexer skips
 * re-chunking when content is unchanged.
 */
if (!function_exists('cleversay_run_source_refresh')):
function cleversay_run_source_refresh(): void {
    cleversay_load_dependencies();
    $sources = new \CleverSay\Sources();
    $sources->run_scheduled_crawls();
}
endif;
add_action('cleversay_source_refresh', 'cleversay_run_source_refresh');

/**
 * v4.39.0+: Embeddings queue processor (Phase 2 of embeddings migration).
 *
 * Runs every 5 minutes if cron is firing regularly (system cron or
 * sufficient site traffic). Processes a batch of pending embedding
 * jobs from the cleversay_embedding_queue table.
 *
 * In multisite, iterates every site that has the embedding feature
 * enabled. Each site's queue is processed independently.
 *
 * No-op if Supabase is not configured/enabled.
 */
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['cleversay_5min'])) {
        $schedules['cleversay_5min'] = [
            'interval' => 300,
            'display'  => __('Every 5 minutes (CleverSay)', 'cleversay'),
        ];
    }
    return $schedules;
});

if (!function_exists('cleversay_run_embedding_processor')):
function cleversay_run_embedding_processor(): void {
    if (!class_exists('\\CleverSay\\Supabase')) {
        return;
    }
    if (!\CleverSay\Supabase::is_enabled()) {
        return;
    }

    if (is_multisite()) {
        $sites = get_sites(['number' => 0, 'fields' => 'ids']);
        foreach ($sites as $site_id) {
            switch_to_blog((int) $site_id);
            try {
                (new \CleverSay\Embedder())->process_queue();
            } catch (\Throwable $e) {
                \CleverSay\Logger::instance()->error('Embedding processor failed', [
                    'site_id' => $site_id,
                    'error'   => $e->getMessage(),
                ]);
            }
            restore_current_blog();
        }
    } else {
        try {
            (new \CleverSay\Embedder())->process_queue();
        } catch (\Throwable $e) {
            \CleverSay\Logger::instance()->error('Embedding processor failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
endif;
add_action('cleversay_process_embeddings', 'cleversay_run_embedding_processor');

/**
 * When a multisite subsite is deleted via Network Admin, make sure CleverSay's
 * tables are included in the drop list.
 *
 * WP core tries to find tables with `SHOW TABLES LIKE 'wp_N_%'` but can miss
 * some depending on MySQL version, collation, and table creation timing
 * (Trac #43162). We append our tables explicitly to be safe.
 *
 * SAFETY: Refuses to operate when $site_id is 0 or 1 — those refer to the
 * network main site (or an unspecified site), and adding CleverSay tables
 * matching the bare "wp_" prefix would drop the main network's tables.
 */
if (!function_exists('cleversay_add_tables_to_drop_list')):
function cleversay_add_tables_to_drop_list(array $tables, int $site_id): array {
    // Hard guard — never touch the network main site's tables.
    // get_blog_prefix(0|1) returns just "wp_" which would match the network
    // main site's CleverSay tables. WP shouldn't normally pass us 0 or 1
    // (you can't delete the main site through the standard UI), but defending
    // against it explicitly is cheap and prevents catastrophic data loss
    // if something unusual happens.
    if ($site_id <= 1) {
        return $tables;
    }

    global $wpdb;
    $prefix = $wpdb->get_blog_prefix($site_id);  // e.g. "wp_7_"

    // Extra paranoia: confirm the prefix actually contains the site_id.
    // If for any reason it came back as just "wp_", refuse.
    if (strpos($prefix, (string) $site_id . '_') === false) {
        return $tables;
    }

    $cleversay_tables = [
        'cleversay_knowledge',
        'cleversay_questions',
        'cleversay_visitors',
        'cleversay_synonyms',
        'cleversay_stopwords',
        'cleversay_ratings',
        'cleversay_inquiries',
        'cleversay_categories',
        'cleversay_sources',
        'cleversay_chunks',
        'cleversay_ai_answers',
        'cleversay_conversation_ratings',
        'cleversay_source_usage',
        'cleversay_leads',
    ];

    foreach ($cleversay_tables as $t) {
        $full = $prefix . $t;
        if (!in_array($full, $tables, true)) {
            $tables[] = $full;
        }
    }
    return $tables;
}
endif;
add_filter('wpmu_drop_tables', 'cleversay_add_tables_to_drop_list', 10, 2);

