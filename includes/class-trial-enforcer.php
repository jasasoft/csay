<?php
/**
 * Trial Enforcement
 *
 * Reads trial_ends_at on each site's plan and acts on it:
 *   - 7 days before expiration → email warning (once)
 *   - 1 day before expiration  → email warning (once)
 *   - 0 days (expired)         → enter 3-day grace period
 *   - 3 days past expiration   → mark site as suspended
 *
 * Suspended sites still load the embed config and chatbot scripts but the
 * widget renders an "unavailable" message instead of activating. Reversible
 * — super-admin can extend trial_ends_at or flip status back to active at
 * any time.
 *
 * @package CleverSay
 */

namespace CleverSay;

defined('ABSPATH') || exit;

class TrialEnforcer {

    /** Days before expiration to send first warning email. */
    private const WARN_DAYS_FIRST = 7;
    /** Days before expiration to send final warning email. */
    private const WARN_DAYS_FINAL = 1;
    /** Days of grace after trial_ends_at before site is suspended. */
    private const GRACE_DAYS      = 3;

    /**
     * Register cron hook + boot. Called once from main plugin file.
     */
    public static function init(): void {
        add_action('cleversay_trial_check', [self::class, 'run_daily']);

        // Schedule daily cron if not already scheduled
        if (!wp_next_scheduled('cleversay_trial_check')) {
            // Run at ~3am site time — quiet hours, low traffic
            wp_schedule_event(
                strtotime('tomorrow 03:00'),
                'daily',
                'cleversay_trial_check'
            );
        }
    }

    /**
     * Unregister cron — called from plugin deactivation.
     */
    public static function deactivate(): void {
        wp_clear_scheduled_hook('cleversay_trial_check');
    }

    /**
     * Run by WP-Cron daily. Iterates all client sites and applies the trial
     * state machine to any site with status='trial'.
     *
     * Multisite-only — single-site installs don't have a trial concept.
     */
    public static function run_daily(): void {
        if (!is_multisite()) {
            return;
        }

        $sites = NetworkSettings::get_client_sites();
        $logger = Logger::instance();
        $logger->info('TrialEnforcer: daily run starting', ['site_count' => count($sites)]);

        foreach ($sites as $site) {
            try {
                self::process_site((int) $site['blog_id']);
            } catch (\Throwable $e) {
                // Don't let one bad site take down the whole cron run
                $logger->error('TrialEnforcer: site failed', [
                    'blog_id' => $site['blog_id'],
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $logger->info('TrialEnforcer: daily run complete');
    }

    /**
     * Apply trial state machine to a single site. Idempotent — safe to run
     * multiple times per day (notifications track sent state to prevent
     * duplicates).
     */
    public static function process_site(int $site_id): void {
        $plan = NetworkSettings::get_site_plan($site_id);

        // Only process sites currently in 'trial' status. Other statuses
        // (active, suspended) are managed manually by super-admins.
        if (($plan['status'] ?? '') !== 'trial') {
            return;
        }

        $trial_ends_at = trim((string) ($plan['trial_ends_at'] ?? ''));
        if ($trial_ends_at === '') {
            // Trial status without an end date — log but don't act
            return;
        }

        $now      = time();
        $end_ts   = strtotime($trial_ends_at);
        if (!$end_ts) {
            return; // unparseable date — skip rather than crash
        }

        $days_remaining = (int) floor(($end_ts - $now) / 86400);
        $changed        = false;

        // ── Phase 1: warning emails ──────────────────────────────────
        if ($days_remaining <= self::WARN_DAYS_FIRST && $days_remaining > self::WARN_DAYS_FINAL) {
            // 7-day warning window
            if (empty($plan['trial_warned_7d'])) {
                self::send_warning_email($site_id, $plan, $days_remaining);
                $plan['trial_warned_7d'] = current_time('mysql');
                $changed = true;
            }
        } elseif ($days_remaining <= self::WARN_DAYS_FINAL && $days_remaining >= 0) {
            // 1-day final warning window
            if (empty($plan['trial_warned_1d'])) {
                self::send_warning_email($site_id, $plan, $days_remaining);
                $plan['trial_warned_1d'] = current_time('mysql');
                $changed = true;
            }
        }

        // ── Phase 2: expiration + grace + suspension ────────────────
        if ($days_remaining < 0) {
            $days_past_expiration = abs($days_remaining);

            if ($days_past_expiration <= self::GRACE_DAYS) {
                // Grace period — widget still works, banner shown to admin.
                // No state change yet, but make sure the expired-notification
                // email goes out on day 0.
                if (empty($plan['trial_expired_at'])) {
                    self::send_expired_email($site_id, $plan);
                    $plan['trial_expired_at'] = current_time('mysql');
                    $changed = true;
                }
            } else {
                // Past grace period — flip to suspended.
                $plan['status'] = 'suspended';
                $changed = true;
                Logger::instance()->info('TrialEnforcer: site suspended after trial+grace', [
                    'blog_id' => $site_id,
                    'days_past_expiration' => $days_past_expiration,
                ]);
            }
        }

        if ($changed) {
            NetworkSettings::save_site_plan($site_id, $plan);
        }
    }

    /**
     * Send a "trial ending soon" warning email.
     */
    private static function send_warning_email(int $site_id, array $plan, int $days_remaining): void {
        $to = self::resolve_recipient($site_id, $plan);
        if (empty($to)) return;

        $site_name = get_blog_details($site_id)->blogname ?? 'your CleverSay site';

        if ($days_remaining > 1) {
            $subject = sprintf(__('Your CleverSay trial ends in %d days', 'cleversay'), $days_remaining);
        } elseif ($days_remaining === 1) {
            $subject = __('Your CleverSay trial ends tomorrow', 'cleversay');
        } else {
            $subject = __('Your CleverSay trial ends today', 'cleversay');
        }

        $body  = sprintf(__('Hi,

Your CleverSay trial for %s ends on %s (%d %s from now).

After the trial ends, you\'ll have a 3-day grace period during which your chatbot will continue working. After that, the chatbot widget will display an "unavailable" message until your account is activated.

If you\'d like to continue using CleverSay, please reply to this email and we\'ll set up your account.

Thanks,
The CleverSay Team',
            'cleversay'),
            $site_name,
            wp_date(get_option('date_format'), strtotime($plan['trial_ends_at'])),
            $days_remaining,
            $days_remaining === 1 ? __('day', 'cleversay') : __('days', 'cleversay')
        );

        wp_mail($to, $subject, $body);
        Logger::instance()->info('TrialEnforcer: warning email sent', [
            'blog_id' => $site_id,
            'to' => $to,
            'days_remaining' => $days_remaining,
        ]);
    }

    /**
     * Send a "trial has ended" notification email at expiration day 0.
     */
    private static function send_expired_email(int $site_id, array $plan): void {
        $to = self::resolve_recipient($site_id, $plan);
        if (empty($to)) return;

        $site_name = get_blog_details($site_id)->blogname ?? 'your CleverSay site';
        $subject   = __('Your CleverSay trial has ended', 'cleversay');

        $body = sprintf(__('Hi,

Your CleverSay trial for %1$s has ended today.

You have a 3-day grace period before the chatbot is suspended. The widget will continue to work normally during this time.

To continue using CleverSay, please reply to this email and we\'ll get your account activated.

Thanks,
The CleverSay Team',
            'cleversay'),
            $site_name
        );

        wp_mail($to, $subject, $body);
        Logger::instance()->info('TrialEnforcer: expired email sent', [
            'blog_id' => $site_id,
            'to' => $to,
        ]);
    }

    /**
     * Resolve who to email about trial events for a given site.
     * Priority:  client_email on plan → site admin email → network admin email.
     */
    private static function resolve_recipient(int $site_id, array $plan): string {
        if (!empty($plan['client_email']) && is_email($plan['client_email'])) {
            return $plan['client_email'];
        }
        $site_email = get_blog_option($site_id, 'admin_email', '');
        if (!empty($site_email) && is_email($site_email)) {
            return $site_email;
        }
        return (string) get_site_option('admin_email', '');
    }

    /**
     * Check if a site is currently suspended OR in the grace period after
     * trial expiration. Used at runtime to decide whether the chatbot
     * widget should activate.
     *
     * Returns: ['active' => bool, 'reason' => string, 'in_grace' => bool]
     *
     * - active=true:   site is fully operational
     * - active=false:  site is suspended; widget should show unavailable msg
     * - in_grace=true: trial has expired but within grace period — widget
     *                  still works, but admin should see a warning banner
     */
    public static function get_runtime_status(int $site_id): array {
        $plan = NetworkSettings::get_site_plan($site_id);
        $status = $plan['status'] ?? 'active';

        if ($status === 'suspended') {
            return ['active' => false, 'reason' => 'suspended', 'in_grace' => false];
        }

        if ($status !== 'trial') {
            return ['active' => true, 'reason' => 'active', 'in_grace' => false];
        }

        // status === 'trial' — check whether it should already be suspended.
        // We do this defensive runtime check in case cron hasn't run yet
        // (or the site was just provisioned with a past trial date for testing).
        $end_ts = strtotime((string) ($plan['trial_ends_at'] ?? ''));
        if (!$end_ts) {
            return ['active' => true, 'reason' => 'trial_no_date', 'in_grace' => false];
        }

        $days_past = (int) floor((time() - $end_ts) / 86400);

        if ($days_past < 0) {
            return ['active' => true, 'reason' => 'trial', 'in_grace' => false];
        }
        if ($days_past <= self::GRACE_DAYS) {
            return ['active' => true, 'reason' => 'trial_grace', 'in_grace' => true];
        }
        // Trial + grace exhausted — treat as suspended at runtime even if
        // the saved status hasn't been updated by cron yet.
        return ['active' => false, 'reason' => 'trial_expired', 'in_grace' => false];
    }
}
