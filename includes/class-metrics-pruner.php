<?php
/**
 * CleverSay MetricsPruner
 *
 * v4.41.5+: daily cron that drops cleversay_request_metrics rows older
 * than the configured retention window. Without pruning, the table
 * grows linearly with traffic — at ~150 queries/day (UWSP scale) that's
 * 50K rows/year per tenant, manageable but not free; at higher traffic
 * it becomes a real concern.
 *
 * Retention is read from the network option `cleversay_metrics_retention_days`,
 * defaulting to 90 days. Setting it to 0 disables pruning entirely
 * (operator opts in to unbounded growth).
 *
 * Iterates active tenants only via TenantHelper, so non-tenant subsites
 * (e.g., the network main site) are skipped.
 *
 * @package CleverSay
 * @since   4.41.5
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class MetricsPruner {

    public const CRON_HOOK    = 'cleversay_prune_metrics';
    public const OPTION_DAYS  = 'cleversay_metrics_retention_days';
    public const DEFAULT_DAYS = 90;

    /**
     * Schedule the daily cron if not already scheduled. Called from
     * cleversay.php's bootstrap so a fresh activation gets the schedule
     * without the operator needing to do anything. Idempotent.
     */
    public static function ensure_scheduled(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // Run at a quiet time UTC-wise. Daily is plenty — pruning
            // is bulk DELETE on an indexed column, finishes in tens of
            // milliseconds even at six-figure row counts.
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Tear-down: clear the cron on plugin deactivation. Mirrors the
     * pattern used elsewhere in the codebase for cron-using features.
     */
    public static function clear_schedule(): void {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
    }

    /**
     * The cron handler. Drops rows older than the retention window from
     * every active tenant's request_metrics table.
     *
     * Best-effort. Failures on one tenant don't block the others; each
     * is wrapped in try/catch and logged. Deliberately not transactional
     * — a partial prune is fine, the next run will catch the rest.
     */
    public static function run(): void {
        $days = (int) get_site_option(self::OPTION_DAYS, self::DEFAULT_DAYS);
        if ($days <= 0) {
            // Operator-disabled. No log line — this is intentional.
            return;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $logger = Logger::instance();

        if (!class_exists('\\CleverSay\\TenantHelper')) {
            // Shouldn't happen — TenantHelper is loaded in
            // cleversay_load_dependencies — but degrade gracefully.
            self::prune_current_blog($cutoff, $logger);
            return;
        }

        $total_deleted = 0;
        TenantHelper::iterate_active_tenants(function (int $blog_id) use ($cutoff, $logger, &$total_deleted): void {
            try {
                $deleted = self::prune_current_blog($cutoff, $logger);
                $total_deleted += $deleted;
            } catch (\Throwable $e) {
                $logger->error('Metrics prune failed for site', [
                    'site_id' => $blog_id,
                    'error'   => $e->getMessage(),
                ]);
            }
        });

        $logger->info('Metrics prune complete', [
            'total_deleted' => $total_deleted,
            'cutoff'        => $cutoff,
            'retention_days'=> $days,
        ]);
    }

    /**
     * Prune the current blog's request_metrics table. Returns the number
     * of rows deleted. Caller is responsible for switch_to_blog if
     * needed; we just operate on the current $wpdb->prefix.
     */
    private static function prune_current_blog(string $cutoff, Logger $logger): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_request_metrics';

        // Verify the table exists — it won't on subsites that activated
        // before v4.41.5 and haven't hit the version-compare upgrade
        // path yet. Skip silently rather than raising an error.
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $table
        ));
        if ((int) $exists === 0) {
            return 0;
        }

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $cutoff
        ));
        return is_numeric($deleted) ? (int) $deleted : 0;
    }
}
