<?php
/**
 * Plugin Name: CleverSay Knowledge Base
 * Plugin URI: https://cleversay.com
 * Description: A modern, AI-powered knowledge base and FAQ chatbot system for WordPress.
 * Version: 4.42.35
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
defined('CLEVERSAY_VERSION')        || define('CLEVERSAY_VERSION', '4.42.35');
defined('CLEVERSAY_PLUGIN_DIR')     || define('CLEVERSAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
defined('CLEVERSAY_PLUGIN_URL')     || define('CLEVERSAY_PLUGIN_URL', plugin_dir_url(__FILE__));
defined('CLEVERSAY_PLUGIN_BASENAME')|| define('CLEVERSAY_PLUGIN_BASENAME', plugin_basename(__FILE__));
defined('CLEVERSAY_DB_VERSION')     || define('CLEVERSAY_DB_VERSION', '4.42.35');

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

    // v4.41.5+: latency observability — daily prune of the
    // request_metrics table. MetricsPruner::ensure_scheduled() handles
    // the wp_next_scheduled check internally and is idempotent.
    if (class_exists('\\CleverSay\\MetricsPruner')) {
        \CleverSay\MetricsPruner::ensure_scheduled();
    }

    // v4.42.26+: Daily per-tenant KB backup. Runs once a day at 03:30
    // (offset from the 02:00 snapshot to avoid concurrent disk writes)
    // and rotates to keep the 7 most recent backups per tenant. Stores
    // backup JSON in wp-content/uploads/sites/N/cleversay-backups/
    // on multisite, which is per-blog-isolated automatically.
    //
    // Activation-time registration; existing tenants get a backfill
    // pass on upgrade (see plugins_loaded handler below).
    if (!wp_next_scheduled('cleversay_daily_kb_backup')) {
        wp_schedule_event(strtotime('03:30:00'), 'daily', 'cleversay_daily_kb_backup');
    }

    // v4.42.31+: Daily stranded-row cleanup for Supabase. Detects rows
    // in Supabase whose content_id no longer maps to a live MySQL row
    // and hard-deletes them. Bounded by a safety threshold — if any
    // tenant has more than Embedder::STRAND_CLEANUP_SAFETY_MAX strands,
    // the run refuses for that tenant and surfaces the count for admin
    // investigation rather than mass-deleting. Scheduled at 04:00,
    // offset from the 03:30 KB backup so disk + Supabase work don't
    // pile up at the same minute.
    if (!wp_next_scheduled('cleversay_daily_stranded_cleanup')) {
        wp_schedule_event(strtotime('04:00:00'), 'daily', 'cleversay_daily_stranded_cleanup');
    }

    // v4.42.32+: Daily integrity backfill. The symmetric counterpart to
    // the strand cleanup above. Where strand cleanup handles "Supabase
    // has rows MySQL doesn't," integrity backfill handles "MySQL has
    // rows Supabase doesn't" — enqueuing missing embeddings so the
    // next cron tick of the queue processor generates them. Bounded
    // by Embedder::BACKFILL_AUTO_MAX (200 by default); larger gaps
    // surface for admin attention rather than starting a multi-hour
    // automatic run. Scheduled at 04:15, 15 minutes after strand
    // cleanup, so the two operations don't compete for the same
    // Supabase connection at the same minute.
    if (!wp_next_scheduled('cleversay_daily_integrity_backfill')) {
        wp_schedule_event(strtotime('04:15:00'), 'daily', 'cleversay_daily_integrity_backfill');
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
    // v4.42.26+: clear daily KB backup cron on deactivation.
    wp_clear_scheduled_hook('cleversay_daily_kb_backup');
    // v4.42.31+: clear stranded cleanup cron on deactivation.
    wp_clear_scheduled_hook('cleversay_daily_stranded_cleanup');
    // v4.42.32+: clear integrity backfill cron on deactivation.
    wp_clear_scheduled_hook('cleversay_daily_integrity_backfill');
    // v4.41.5+: clear the metrics retention cron so a deactivated
    // plugin doesn't leave a phantom cron entry behind. Use a literal
    // hook name in the fallback path so we don't fatal on missing class.
    if (class_exists('\\CleverSay\\MetricsPruner')) {
        \CleverSay\MetricsPruner::clear_schedule();
    } else {
        wp_clear_scheduled_hook('cleversay_prune_metrics');
    }
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
 * v4.41.5+: Daily metrics-retention cron handler. Drops cleversay_request_metrics
 * rows older than the configured retention window from every active tenant.
 * The schedule is established in cleversay_admin_init() so a fresh activation
 * picks it up without needing the operator to do anything.
 *
 * Hook name and callable are string literals here, NOT class-constant
 * references — this code runs at file parse time before
 * cleversay_load_dependencies() has loaded the class. WordPress resolves
 * the array callable only when the cron fires, by which time the class
 * exists. (v4.41.5.1 — earlier code used MetricsPruner::CRON_HOOK and
 * MetricsPruner::class at parse time, which fatals on plugin load.)
 */
add_action('cleversay_prune_metrics', ['\\CleverSay\\MetricsPruner', 'run']);

/**
 * v4.41.5+: Request-timer shutdown safety net. The timer is started at
 * the top of ajax_search() and populated as the request runs; explicit
 * flush() calls at exit points would mean editing every wp_send_json_*
 * site (there are many, and they're scattered). Instead, register a
 * single shutdown handler that flushes if the timer was started.
 *
 * RequestTimer::flush() is idempotent — if anyone DID call it explicitly
 * earlier, this is a no-op. If the timer was never started (e.g., for
 * non-search admin AJAX endpoints), flush() also no-ops.
 *
 * Wrapped in a class_exists check so a partial-load failure during
 * shutdown doesn't cascade into a second fatal.
 */
register_shutdown_function(function (): void {
    if (class_exists('\\CleverSay\\RequestTimer')) {
        \CleverSay\RequestTimer::instance()->flush();
    }
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
    // v4.41.0+: TenantHelper is used by the cron processor, the per-site
    // and network admin views, and any code path that needs to iterate
    // active tenants. Loaded early so it's available everywhere.
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-tenant-helper.php';
    // v4.41.5+: latency observability layer.
    //   - RequestTimer: per-request stage timer + log/DB writer
    //   - MetricsPruner: daily cron to enforce request_metrics retention
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-request-timer.php';
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-metrics-pruner.php';
    // v4.42.0+: bulk question testing infrastructure (super-admin only;
    // pre-deployment qualification).
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-bulk-tester.php';
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
    // v4.42.14+: stateful affirmation resolution for the AI fallback path.
    // Sits between input and retrieval — when user message is a state-
    // dependent operator like "yes", resolves to a meaningful query
    // based on the prior assistant turn's offered follow-up.
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-followup-resolver.php';
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
    require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-retriever.php';
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
        // v4.41.0+: Supabase namespace migration. Adds the
        // source_namespace column to cleversay_chunks (Postgres side),
        // backfills it from content_type, and creates a namespace-scoped
        // index. Best-effort — if Supabase isn't reachable during this
        // admin load (network outage, credentials not yet entered),
        // logs a warning and continues. The migration is idempotent so
        // it can run again on the next admin load. Retrieval keeps
        // working either way because the Retriever's vector_search
        // tolerates NULL source_namespace as a fallback. See Bug 4 in
        // the v4.41.0 handoff brief.
        if (version_compare($installed_version, '4.41.0', '<')
            && class_exists('\\CleverSay\\Supabase')
            && \CleverSay\Supabase::is_enabled()
        ) {
            try {
                \CleverSay\Supabase::instance()->run_namespace_migration();
            } catch (\Throwable $e) {
                \CleverSay\Logger::instance()->warning('v4.41.0 namespace migration error', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        // v4.41.5+: install the cleversay_request_metrics table on
        // existing tenants without requiring a deactivate/reactivate.
        // dbDelta inside add_request_metrics_table() is idempotent so
        // re-running on a tenant that already has the table is a no-op.
        // Also ensure the daily prune cron is scheduled for sites that
        // were activated before v4.41.5 — activation hooks don't fire
        // on plugin updates.
        if (version_compare($installed_version, '4.41.5', '<')) {
            try {
                $database->add_request_metrics_table();
            } catch (\Throwable $e) {
                \CleverSay\Logger::instance()->warning('v4.41.5 metrics table install error', [
                    'error' => $e->getMessage(),
                ]);
            }
            if (class_exists('\\CleverSay\\MetricsPruner')) {
                \CleverSay\MetricsPruner::ensure_scheduled();
            }
        }
        // v4.41.5.3+: add synthesis_model column for tenants already on
        // v4.41.5/.1/.2. Idempotent ALTER inside add_synthesis_model_column;
        // safe if the column already exists or the table is missing.
        if (version_compare($installed_version, '4.41.5.3', '<')) {
            try {
                $database->add_synthesis_model_column();
            } catch (\Throwable $e) {
                \CleverSay\Logger::instance()->warning('v4.41.5.3 synthesis_model column add error', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        // v4.42.0+: install bulk_test_runs and bulk_test_results tables
        // for tenants upgrading from v4.41.x. Idempotent dbDelta inside
        // add_bulk_test_tables — safe if the tables already exist.
        if (version_compare($installed_version, '4.42.0', '<')) {
            try {
                $database->add_bulk_test_tables();
            } catch (\Throwable $e) {
                \CleverSay\Logger::instance()->warning('v4.42.0 bulk test tables install error', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        // v4.42.8+: backfill the cleversay_process_embeddings cron event
        // for tenants that activated CleverSay BEFORE v4.39.0 added the
        // event. The original scheduling block in cleversay_activate()
        // only runs on plugin activation, so tenants installed pre-v4.39
        // never had the event scheduled even after upgrading to a version
        // that defines it. Result: their embedding queue silently never
        // processes via cron, accumulating pending entries until somebody
        // notices retrieval is degraded (we just did, on UWO blog 5,
        // after weeks).
        //
        // The fix is straightforward — schedule the event if missing.
        // Guarded by wp_next_scheduled() so this is idempotent. Runs on
        // EVERY upgrade past 4.42.8, not gated to "from < 4.42.8",
        // because the symptom we're guarding against affects ANY tenant
        // whose original activation predated 4.39, regardless of how
        // many upgrades they've gone through since.
        //
        // No version-compare check is used because the cost of the
        // wp_next_scheduled() probe is negligible (one option-array
        // lookup) and the win is guaranteed coverage for the silent-
        // failure class.
        if (!wp_next_scheduled('cleversay_process_embeddings')) {
            $scheduled = wp_schedule_event(time() + 60, 'cleversay_5min', 'cleversay_process_embeddings');
            // wp_schedule_event returns true on success, false (or a
            // WP_Error on newer cores) on failure. Log either outcome
            // so we have a forensic record if anyone investigates a
            // future silently-stuck-queue case.
            if ($scheduled === false || is_wp_error($scheduled)) {
                \CleverSay\Logger::instance()->warning('v4.42.8 cron backfill: wp_schedule_event returned failure', [
                    'blog_id' => get_current_blog_id(),
                    'result'  => is_wp_error($scheduled) ? $scheduled->get_error_message() : 'false',
                ]);
            } else {
                \CleverSay\Logger::instance()->info('v4.42.8 cron backfill: scheduled cleversay_process_embeddings', [
                    'blog_id' => get_current_blog_id(),
                ]);
            }
        }
        // v4.42.26+: backfill the cleversay_daily_kb_backup cron event
        // for existing tenants. Same pattern as the v4.42.8 backfill —
        // activation-time scheduling alone misses tenants that activated
        // before this feature existed. Idempotent via wp_next_scheduled.
        if (!wp_next_scheduled('cleversay_daily_kb_backup')) {
            $scheduled = wp_schedule_event(strtotime('03:30:00'), 'daily', 'cleversay_daily_kb_backup');
            if ($scheduled === false || is_wp_error($scheduled)) {
                \CleverSay\Logger::instance()->warning('v4.42.26 cron backfill: cleversay_daily_kb_backup failed to schedule', [
                    'blog_id' => get_current_blog_id(),
                    'result'  => is_wp_error($scheduled) ? $scheduled->get_error_message() : 'false',
                ]);
            } else {
                \CleverSay\Logger::instance()->info('v4.42.26 cron backfill: scheduled cleversay_daily_kb_backup', [
                    'blog_id' => get_current_blog_id(),
                ]);
            }
        }
        // v4.42.31+: backfill cleversay_daily_stranded_cleanup for
        // tenants that activated before this feature existed. Idempotent.
        if (!wp_next_scheduled('cleversay_daily_stranded_cleanup')) {
            $scheduled = wp_schedule_event(strtotime('04:00:00'), 'daily', 'cleversay_daily_stranded_cleanup');
            if ($scheduled === false || is_wp_error($scheduled)) {
                \CleverSay\Logger::instance()->warning('v4.42.31 cron backfill: cleversay_daily_stranded_cleanup failed to schedule', [
                    'blog_id' => get_current_blog_id(),
                    'result'  => is_wp_error($scheduled) ? $scheduled->get_error_message() : 'false',
                ]);
            } else {
                \CleverSay\Logger::instance()->info('v4.42.31 cron backfill: scheduled cleversay_daily_stranded_cleanup', [
                    'blog_id' => get_current_blog_id(),
                ]);
            }
        }
        // v4.42.32+: backfill cleversay_daily_integrity_backfill for
        // tenants that activated before this feature existed. Idempotent.
        if (!wp_next_scheduled('cleversay_daily_integrity_backfill')) {
            $scheduled = wp_schedule_event(strtotime('04:15:00'), 'daily', 'cleversay_daily_integrity_backfill');
            if ($scheduled === false || is_wp_error($scheduled)) {
                \CleverSay\Logger::instance()->warning('v4.42.32 cron backfill: cleversay_daily_integrity_backfill failed to schedule', [
                    'blog_id' => get_current_blog_id(),
                    'result'  => is_wp_error($scheduled) ? $scheduled->get_error_message() : 'false',
                ]);
            } else {
                \CleverSay\Logger::instance()->info('v4.42.32 cron backfill: scheduled cleversay_daily_integrity_backfill', [
                    'blog_id' => get_current_blog_id(),
                ]);
            }
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
 * v4.42.26+: Daily per-tenant KB backup.
 *
 * Creates a JSON export of the current tenant's KB content (entries,
 * variations, categories, synonyms) and writes it to the per-blog
 * uploads folder. After writing, prunes the backup directory to keep
 * only the most recent IMPORT_EXPORT_BACKUP_KEEP files.
 *
 * Multisite-aware: iterates every active site so each tenant gets its
 * own backup created in its own uploads scope. Skips blog 1 (the
 * network main site is not a CleverSay tenant).
 *
 * Storage: wp-content/uploads/sites/{blog_id}/cleversay-backups/
 *   cleversay-backup-YYYY-MM-DD-HHMMSS.json
 *
 * Retention: ImportExport::BACKUP_KEEP (default 7). Older files in the
 * same directory are deleted after each successful backup.
 *
 * Failure mode: per-tenant try/catch; one site's backup failing does
 * not abort the others. Failures get logged but do not retry — the
 * next day's run is the recovery path.
 */
if (!function_exists('cleversay_run_daily_kb_backup')):
function cleversay_run_daily_kb_backup(): void {
    cleversay_load_dependencies();

    $is_multisite = function_exists('is_multisite') && is_multisite();
    if (!$is_multisite) {
        // Single-site install — just back up this site.
        cleversay_run_kb_backup_for_current_site();
        return;
    }

    // Multisite: iterate active sites, skip blog 1 (network main).
    $sites = get_sites([
        'archived' => 0,
        'spam'     => 0,
        'deleted'  => 0,
        'number'   => 200,  // sane upper bound
    ]);
    foreach ($sites as $site) {
        $blog_id = (int) $site->blog_id;
        if ($blog_id === 1) continue;  // network main site, not a tenant
        switch_to_blog($blog_id);
        try {
            cleversay_run_kb_backup_for_current_site();
        } catch (\Throwable $e) {
            \CleverSay\Logger::instance()->error('Daily KB backup failed for tenant', [
                'blog_id' => $blog_id,
                'error'   => $e->getMessage(),
            ]);
        }
        restore_current_blog();
    }
}
endif;

if (!function_exists('cleversay_run_kb_backup_for_current_site')):
function cleversay_run_kb_backup_for_current_site(): void {
    // The ImportExport class handles export + write. The retention
    // pruning lives there too so manual exports via Import/Export UI
    // benefit from the same rotation.
    if (!class_exists('\\CleverSay\\ImportExport')) return;
    $ie = new \CleverSay\ImportExport();
    $path = $ie->create_backup();
    $ie->prune_backups();  // keep BACKUP_KEEP newest, delete older
    \CleverSay\Logger::instance()->info('Daily KB backup completed', [
        'blog_id' => function_exists('get_current_blog_id') ? get_current_blog_id() : 1,
        'file'    => basename($path),
    ]);
}
endif;
add_action('cleversay_daily_kb_backup', 'cleversay_run_daily_kb_backup');

/**
 * v4.42.31+: Daily Supabase strand cleanup.
 *
 * Detects rows in Supabase whose content_id no longer maps to a live
 * MySQL row for the current tenant, and hard-deletes them. This is the
 * cron-driven counterpart to the manual "Clean Up Stranded Rows" button
 * on the Embeddings page.
 *
 * Multisite-aware: iterates active sites, skips blog 1 (network main,
 * not a tenant), runs cleanup per-tenant inside switch_to_blog. Each
 * tenant's run is wrapped in try/catch so one failure doesn't abort
 * the others.
 *
 * Safety: Embedder::cleanup_stranded_rows(false) — non-forced. If a
 * tenant has more than STRAND_CLEANUP_SAFETY_MAX strands, the run
 * refuses and surfaces the count for admin investigation. Manual
 * button passes force=true to override.
 */
if (!function_exists('cleversay_run_daily_stranded_cleanup')):
function cleversay_run_daily_stranded_cleanup(): void {
    cleversay_load_dependencies();

    $is_multisite = function_exists('is_multisite') && is_multisite();
    if (!$is_multisite) {
        cleversay_run_stranded_cleanup_for_current_site();
        return;
    }

    $sites = get_sites([
        'archived' => 0,
        'spam'     => 0,
        'deleted'  => 0,
        'number'   => 200,
    ]);
    foreach ($sites as $site) {
        $blog_id = (int) $site->blog_id;
        if ($blog_id === 1) continue;  // network main, not a tenant
        switch_to_blog($blog_id);
        try {
            cleversay_run_stranded_cleanup_for_current_site();
        } catch (\Throwable $e) {
            \CleverSay\Logger::instance()->error('Daily strand cleanup failed for tenant', [
                'blog_id' => $blog_id,
                'error'   => $e->getMessage(),
            ]);
        }
        restore_current_blog();
    }
}
endif;

if (!function_exists('cleversay_run_stranded_cleanup_for_current_site')):
function cleversay_run_stranded_cleanup_for_current_site(): void {
    if (!class_exists('\\CleverSay\\Embedder')) return;
    $result = (new \CleverSay\Embedder())->cleanup_stranded_rows(false);
    // Embedder logs success/failure internally with full context. We
    // log a short summary here too so the daily-cron timeline shows
    // when this ran on which tenant — useful when correlating logs
    // across multiple operations.
    \CleverSay\Logger::instance()->info('Daily strand cleanup tick', [
        'blog_id' => function_exists('get_current_blog_id') ? get_current_blog_id() : 1,
        'success' => !empty($result['success']),
        'found'   => (int) ($result['found'] ?? 0),
        'deleted' => (int) ($result['deleted'] ?? 0),
        'reason'  => $result['reason'] ?? null,
    ]);
}
endif;
add_action('cleversay_daily_stranded_cleanup', 'cleversay_run_daily_stranded_cleanup');

/**
 * v4.42.32+: Daily integrity backfill.
 *
 * The symmetric counterpart to the strand cleanup. Detects MySQL
 * content that has no current Supabase embedding (the inverse of
 * strands) and enqueues it for the queue processor to embed on the
 * next cron tick.
 *
 * Together with the strand cleanup, this closes the integrity loop:
 *   - strand cleanup deletes "Supabase has, MySQL doesn't"
 *   - integrity backfill enqueues "MySQL has, Supabase doesn't"
 *
 * The system self-heals from both directions on a daily cadence
 * without admin intervention.
 *
 * Multisite-aware: iterates active sites, skips blog 1, runs
 * per-tenant inside switch_to_blog. Per-tenant try/catch isolates
 * failures.
 *
 * Safety: Embedder::backfill_missing_embeddings(false) — non-forced.
 * If a tenant has more than BACKFILL_AUTO_MAX missing items (e.g.,
 * after a bulk import) the run refuses and surfaces the count for
 * admin attention. Manual button passes force=true.
 */
if (!function_exists('cleversay_run_daily_integrity_backfill')):
function cleversay_run_daily_integrity_backfill(): void {
    cleversay_load_dependencies();

    $is_multisite = function_exists('is_multisite') && is_multisite();
    if (!$is_multisite) {
        cleversay_run_integrity_backfill_for_current_site();
        return;
    }

    $sites = get_sites([
        'archived' => 0,
        'spam'     => 0,
        'deleted'  => 0,
        'number'   => 200,
    ]);
    foreach ($sites as $site) {
        $blog_id = (int) $site->blog_id;
        if ($blog_id === 1) continue;  // network main, not a tenant
        switch_to_blog($blog_id);
        try {
            cleversay_run_integrity_backfill_for_current_site();
        } catch (\Throwable $e) {
            \CleverSay\Logger::instance()->error('Daily integrity backfill failed for tenant', [
                'blog_id' => $blog_id,
                'error'   => $e->getMessage(),
            ]);
        }
        restore_current_blog();
    }
}
endif;

if (!function_exists('cleversay_run_integrity_backfill_for_current_site')):
function cleversay_run_integrity_backfill_for_current_site(): void {
    if (!class_exists('\\CleverSay\\Embedder')) return;
    $result = (new \CleverSay\Embedder())->backfill_missing_embeddings(false);
    \CleverSay\Logger::instance()->info('Daily integrity backfill tick', [
        'blog_id'  => function_exists('get_current_blog_id') ? get_current_blog_id() : 1,
        'success'  => !empty($result['success']),
        'found'    => (int) ($result['found'] ?? 0),
        'enqueued' => (int) ($result['enqueued'] ?? 0),
        'reason'   => $result['reason'] ?? null,
    ]);
}
endif;
add_action('cleversay_daily_integrity_backfill', 'cleversay_run_daily_integrity_backfill');

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
        // v4.41.0+: only iterate active tenants so blog 1 (the network
        // landing page on jasa-server.com) and other empty subsites are
        // skipped. Earlier versions iterated every blog returned by
        // get_sites(), which caused queue work to run from inside
        // empty-tenant contexts. See Bug 3 in the v4.41.0 handoff brief.
        \CleverSay\TenantHelper::iterate_active_tenants(function (int $site_id): void {
            try {
                (new \CleverSay\Embedder())->process_queue();
            } catch (\Throwable $e) {
                \CleverSay\Logger::instance()->error('Embedding processor failed', [
                    'site_id' => $site_id,
                    'error'   => $e->getMessage(),
                ]);
            }
        });
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

