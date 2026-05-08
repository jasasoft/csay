<?php
/**
 * AI Inspector — list view
 *
 * @var array $status      ['enabled' => bool, 'remaining' => int]
 * @var array $entries     Recent debug log rows
 * @var int   $total_count Total entries currently in log
 */
defined('ABSPATH') || exit;
?>
<div class="wrap">
    <h1><?php esc_html_e('AI Inspector', 'cleversay'); ?></h1>

    <?php if (isset($_GET['updated'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Updated.', 'cleversay'); ?></p>
        </div>
    <?php endif; ?>

    <p class="description" style="max-width:780px;">
        <?php esc_html_e('Capture the exact prompt, retrieved chunks, conversation history, and AI response for diagnostic review. Capture is off by default. Enable capture mode to log the next 50 AI calls, or wait for thumbs-down ratings to auto-flag bad answers.', 'cleversay'); ?>
    </p>

    <div class="cleversay-card" style="background:#fff; padding:16px 20px; border:1px solid #ccd0d4; border-radius:6px; margin:16px 0;">
        <h2 style="margin-top:0;"><?php esc_html_e('Capture status', 'cleversay'); ?></h2>

        <?php if ($status['enabled']): ?>
            <p>
                <strong style="color:#46b450;"><?php esc_html_e('● Active', 'cleversay'); ?></strong>
                <?php
                printf(
                    /* translators: %d = number of remaining slots */
                    esc_html__('— %d slots remaining in this capture window.', 'cleversay'),
                    (int) $status['remaining']
                );
                ?>
            </p>
            <p class="description"><?php esc_html_e('Capture will auto-disable when slots reach zero. Ask the bot questions now to fill the window.', 'cleversay'); ?></p>

            <form method="post" style="display:inline;">
                <?php wp_nonce_field('cleversay_inspector', 'cleversay_inspector_nonce'); ?>
                <input type="hidden" name="cleversay_inspector_action" value="stop_capture">
                <button type="submit" class="button"><?php esc_html_e('Stop capture now', 'cleversay'); ?></button>
            </form>
        <?php else: ?>
            <p>
                <strong style="color:#999;"><?php esc_html_e('○ Inactive', 'cleversay'); ?></strong>
                <?php esc_html_e('— manual capture is off. Negative ratings will still auto-flag AI answers.', 'cleversay'); ?>
            </p>

            <form method="post" style="display:inline;">
                <?php wp_nonce_field('cleversay_inspector', 'cleversay_inspector_nonce'); ?>
                <input type="hidden" name="cleversay_inspector_action" value="start_capture">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Start capture (next 50 questions)', 'cleversay'); ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <h2><?php esc_html_e('Recent entries', 'cleversay'); ?></h2>

    <?php if (empty($entries)): ?>
        <p class="description">
            <?php esc_html_e('No entries yet. Click "Start capture" above and ask the bot a question, or wait for a thumbs-down rating.', 'cleversay'); ?>
        </p>
    <?php else: ?>

        <p class="description">
            <?php
            printf(
                /* translators: %d = total entries in log */
                esc_html__('Showing 50 of %d total entries. Older entries auto-purge after 30 days.', 'cleversay'),
                (int) $total_count
            );
            ?>
        </p>

        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th style="width:140px;"><?php esc_html_e('When', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Question', 'cleversay'); ?></th>
                    <th style="width:120px;"><?php esc_html_e('Trigger', 'cleversay'); ?></th>
                    <th style="width:90px; text-align:right;"><?php esc_html_e('Latency', 'cleversay'); ?></th>
                    <th style="width:80px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry):
                    $trigger = $entry['trigger_reason'] ?? 'manual';
                    $trigger_label = [
                        'manual'           => __('Manual capture', 'cleversay'),
                        'negative_rating'  => __('👎 Negative rating', 'cleversay'),
                        'forced'           => __('Forced', 'cleversay'),
                    ][$trigger] ?? $trigger;

                    $detail_url = add_query_arg([
                        'page'  => 'cleversay-ai-inspector',
                        'entry' => (int) $entry['id'],
                    ], admin_url('admin.php'));
                ?>
                    <tr>
                        <td>
                            <?php
                            // DB stores created_at as UTC (CURRENT_TIMESTAMP).
                            // Parse as UTC explicitly so the diff is correct
                            // regardless of server timezone or WP timezone.
                            $created_utc = strtotime($entry['created_at'] . ' UTC');
                            echo esc_html(human_time_diff($created_utc, time()) . ' ' . __('ago', 'cleversay'));
                            ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($detail_url); ?>">
                                <?php echo esc_html(mb_substr($entry['question'] ?? '', 0, 100)); ?>
                            </a>
                        </td>
                        <td>
                            <?php echo esc_html($trigger_label); ?>
                        </td>
                        <td style="text-align:right;">
                            <?php
                            if (!empty($entry['latency_ms'])) {
                                echo esc_html(number_format((int) $entry['latency_ms']) . ' ms');
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($detail_url); ?>" class="button button-small">
                                <?php esc_html_e('View', 'cleversay'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:16px;">
            <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Delete all debug log entries? This cannot be undone.', 'cleversay')); ?>');">
                <?php wp_nonce_field('cleversay_inspector', 'cleversay_inspector_nonce'); ?>
                <input type="hidden" name="cleversay_inspector_action" value="clear_all">
                <button type="submit" class="button"><?php esc_html_e('Clear all entries', 'cleversay'); ?></button>
            </form>
        </p>
    <?php endif; ?>
</div>
