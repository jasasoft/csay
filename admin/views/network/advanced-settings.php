<?php
/**
 * Network Advanced Settings View
 *
 * @package CleverSay
 * @since   4.0.0
 * @var array $settings  Current advanced settings
 */

if (!defined('ABSPATH')) exit;
?>
<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline">
        <?php echo \CleverSay\Icons::render('settings', 18); ?>
        <?php esc_html_e('Network Advanced Settings', 'cleversay'); ?>
    </h1>
    <hr class="wp-header-end">

    <p class="description" style="margin-bottom:20px;max-width:700px;">
        <?php esc_html_e('Advanced configuration applied across all client sites. Clients cannot access these settings.', 'cleversay'); ?>
    </p>

    <form method="post" action="">
        <?php wp_nonce_field('cleversay_network_adv', 'cleversay_network_adv_nonce'); ?>

        <div class="cleversay-table-card" style="margin-bottom:20px;">
            <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
                <h3 style="margin:0;font-size:14px;font-weight:600;">
                    <?php echo \CleverSay\Icons::render('zap', 16); ?>
                    <?php esc_html_e('Rate Limiting', 'cleversay'); ?>
                </h3>
            </div>
            <table class="form-table" style="margin:0;">
                <tr>
                    <th><?php esc_html_e('Enable Rate Limiting', 'cleversay'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="rate_limit_enabled" value="1"
                                   <?php checked(!empty($settings['rate_limit_enabled'])); ?>>
                            <?php esc_html_e('Limit requests per IP to prevent abuse', 'cleversay'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="rate_limit_requests"><?php esc_html_e('Max Requests', 'cleversay'); ?></label></th>
                    <td>
                        <input type="number" name="rate_limit_requests" id="rate_limit_requests"
                               class="small-text" min="1" max="200"
                               value="<?php echo esc_attr($settings['rate_limit_requests'] ?? 30); ?>">
                        <p class="description"><?php esc_html_e('Max requests per IP per time window.', 'cleversay'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="rate_limit_window"><?php esc_html_e('Time Window (seconds)', 'cleversay'); ?></label></th>
                    <td>
                        <input type="number" name="rate_limit_window" id="rate_limit_window"
                               class="small-text" min="10" max="3600"
                               value="<?php echo esc_attr($settings['rate_limit_window'] ?? 60); ?>">
                    </td>
                </tr>
            </table>
        </div>

        <div class="cleversay-table-card" style="margin-bottom:20px;">
            <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
                <h3 style="margin:0;font-size:14px;font-weight:600;">
                    <?php echo \CleverSay\Icons::render('search', 16); ?>
                    <?php esc_html_e('Search & Matching', 'cleversay'); ?>
                </h3>
            </div>
            <table class="form-table" style="margin:0;">
                <tr>
                    <th><label for="min_match_score"><?php esc_html_e('Min Match Score', 'cleversay'); ?></label></th>
                    <td>
                        <input type="number" name="min_match_score" id="min_match_score"
                               class="small-text" min="0" max="100"
                               value="<?php echo esc_attr($settings['min_match_score'] ?? 70); ?>">
                        <p class="description"><?php esc_html_e('Minimum score for a KB match to be returned. 70 recommended.', 'cleversay'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="max_results"><?php esc_html_e('Max Results', 'cleversay'); ?></label></th>
                    <td>
                        <input type="number" name="max_results" id="max_results"
                               class="small-text" min="1" max="10"
                               value="<?php echo esc_attr($settings['max_results'] ?? 5); ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="cache_duration"><?php esc_html_e('Cache Duration (seconds)', 'cleversay'); ?></label></th>
                    <td>
                        <input type="number" name="cache_duration" id="cache_duration"
                               class="small-text" min="0" step="60"
                               value="<?php echo esc_attr($settings['cache_duration'] ?? 300); ?>">
                        <p class="description"><?php esc_html_e('How long to cache search results. 0 to disable.', 'cleversay'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="cleversay-table-card" style="margin-bottom:20px;">
            <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
                <h3 style="margin:0;font-size:14px;font-weight:600;">
                    <?php echo \CleverSay\Icons::render('file-text', 16); ?>
                    <?php esc_html_e('Logging & Debug', 'cleversay'); ?>
                </h3>
            </div>
            <table class="form-table" style="margin:0;">
                <tr>
                    <th><label for="log_retention_days"><?php esc_html_e('Log Retention (days)', 'cleversay'); ?></label></th>
                    <td>
                        <input type="number" name="log_retention_days" id="log_retention_days"
                               class="small-text" min="1" max="365"
                               value="<?php echo esc_attr($settings['log_retention_days'] ?? 30); ?>">
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Debug Mode', 'cleversay'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="debug_mode" value="1"
                                   <?php checked(!empty($settings['debug_mode'])); ?>>
                            <?php esc_html_e('Enable debug logging across all sites', 'cleversay'); ?>
                        </label>
                        <p class="description" style="color:#ff3b30;">
                            <?php esc_html_e('Only enable for troubleshooting. Disable in production.', 'cleversay'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button(__('Save Network Advanced Settings', 'cleversay')); ?>
    </form>
</div>
