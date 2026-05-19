<?php
/**
 * Per-Site Embeddings Admin View
 *
 * v4.41.0+: split out from the network admin Embeddings settings page.
 *
 * The status panel and action buttons (Backfill, Process Queue Now,
 * Retry Failed) live on this per-site page because they are inherently
 * per-tenant operations. Putting them on the network admin Embeddings
 * page caused them to run in the network main blog's context (always
 * blog 1), which produced misleading status panels and stranded rows
 * tagged with the wrong tenant_id. (See Bugs 1, 2, 3 in the v4.41.0
 * handoff brief.)
 *
 * @package CleverSay
 * @since   4.41.0
 *
 * @var array $stats        From Embedder::get_queue_stats() for this site.
 * @var int   $max_chunks   Current cleversay_ai_max_chunks for this site.
 * @var int   $blog_id      Current blog id for display.
 * @var array $notice       Optional ['type' => 'success'|'error', 'message' => '...'].
 */

if (!defined('ABSPATH')) exit;

$site_label = is_multisite()
    ? sprintf(__('Site %d', 'cleversay'), (int) $blog_id)
    : __('this site', 'cleversay');
?>
<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline">
        <?php echo \CleverSay\Icons::render('database', 18); ?>
        <?php esc_html_e('Embeddings', 'cleversay'); ?>
    </h1>
    <hr class="wp-header-end">

    <p class="description" style="margin-bottom:20px;max-width:780px;">
        <?php
        printf(
            /* translators: %s = "Site 4" or "this site" */
            esc_html__(
                'Embedding status and operations for %s. Network-level configuration (Supabase credentials, OpenAI API key, feature flags) lives in Network Admin → CleverSay → Embeddings.',
                'cleversay'
            ),
            esc_html($site_label)
        );
        ?>
    </p>

    <?php if (!empty($notice) && is_array($notice)):
        $cls = ($notice['type'] ?? '') === 'success' ? 'notice-success' : 'notice-error';
    ?>
        <div class="notice <?php echo esc_attr($cls); ?> is-dismissible">
            <p><?php echo esc_html($notice['message'] ?? ''); ?></p>
        </div>
    <?php endif; ?>

    <!-- Status panel -->
    <div class="cleversay-table-card" style="margin-bottom:20px;">
        <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
            <h3 style="margin:0;font-size:14px;font-weight:600;">
                <?php echo \CleverSay\Icons::render('activity', 16); ?>
                <?php
                printf(
                    /* translators: %s = "Site 4" or "this site" */
                    esc_html__('Embedding Status — %s', 'cleversay'),
                    esc_html($site_label)
                );
                ?>
            </h3>
        </div>
        <div style="padding:14px 18px;">
            <?php if (isset($stats['error'])): ?>
                <p class="notice notice-error" style="margin:0;padding:10px;">
                    <?php esc_html_e('Could not load stats: ', 'cleversay'); ?>
                    <code><?php echo esc_html($stats['error']); ?></code>
                </p>
            <?php else: ?>
                <table class="widefat striped" style="margin-bottom:12px;">
                    <tbody>
                        <tr>
                            <td style="width:40%;"><strong><?php esc_html_e('KB Entries', 'cleversay'); ?></strong></td>
                            <td>
                                <?php echo (int) ($stats['embedded_kb_entries'] ?? 0); ?> /
                                <?php echo (int) ($stats['total_kb_entries'] ?? 0); ?>
                                <?php esc_html_e('embedded (matched against current MySQL rows)', 'cleversay'); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Source Chunks', 'cleversay'); ?></strong></td>
                            <td>
                                <?php echo (int) ($stats['embedded_chunks'] ?? 0); ?> /
                                <?php echo (int) ($stats['total_chunks'] ?? 0); ?>
                                <?php esc_html_e('embedded (matched against current MySQL rows)', 'cleversay'); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Queue: Pending', 'cleversay'); ?></strong></td>
                            <td><?php echo (int) ($stats['pending'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Queue: Processing', 'cleversay'); ?></strong></td>
                            <td><?php echo (int) ($stats['processing'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Queue: Failed', 'cleversay'); ?></strong></td>
                            <td>
                                <?php echo (int) ($stats['failed'] ?? 0); ?>
                                <?php if (!empty($stats['failed'])): ?>
                                    <em><?php esc_html_e('(retried max times — use Retry Failed below)', 'cleversay'); ?></em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php if (!empty($stats['stranded_rows'])): ?>
                    <div class="notice notice-warning" style="margin:10px 0;padding:10px;">
                        <p style="margin:0 0 8px;">
                            <strong><?php esc_html_e('Integrity warning:', 'cleversay'); ?></strong>
                            <?php
                            printf(
                                /* translators: %d = count */
                                esc_html__(
                                    'Supabase has %d row(s) for this tenant whose content_id no longer maps to a current MySQL row. These are likely stranded from earlier indexing runs and can be cleaned up safely.',
                                    'cleversay'
                                ),
                                (int) $stats['stranded_rows']
                            );
                            ?>
                        </p>
                        <form method="post" action="" style="display:inline-block;margin:4px 0 0;"
                              onsubmit="return confirm('<?php echo esc_js(__('Hard-delete the stranded Supabase rows? This is safe — they point to MySQL rows that no longer exist.', 'cleversay')); ?>');">
                            <?php wp_nonce_field('cleversay_site_supabase_cleanup_stranded', 'cleversay_site_supabase_cleanup_stranded_nonce'); ?>
                            <button type="submit" name="cleversay_site_supabase_action" value="cleanup_stranded"
                                    class="button">
                                <?php esc_html_e('Clean Up Stranded Rows', 'cleversay'); ?>
                            </button>
                            <span class="description" style="margin-left:8px;">
                                <?php esc_html_e('Also runs automatically once a day.', 'cleversay'); ?>
                            </span>
                        </form>
                    </div>
                <?php endif; ?>

                <p class="description" style="margin-bottom:12px;">
                    <?php esc_html_e(
                        'The processor runs every 5 minutes via WP-Cron. With cPanel system cron pointing at wp-cron.php, processing is reliable. Without it, processing depends on site traffic.',
                        'cleversay'
                    ); ?>
                </p>

                <?php
                // v4.42.32+: Compute the gap to decide whether to show
                // the surgical "Backfill Missing Only" button.
                $kb_gap = max(0, (int) ($stats['total_kb_entries'] ?? 0) - (int) ($stats['embedded_kb_entries'] ?? 0));
                $ch_gap = max(0, (int) ($stats['total_chunks'] ?? 0) - (int) ($stats['embedded_chunks'] ?? 0));
                $total_gap = $kb_gap + $ch_gap;
                ?>

                <?php if ($total_gap > 0): ?>
                <form method="post" action="" style="display:inline-block;margin-right:8px;"
                      onsubmit="return confirm('<?php echo esc_js(__('Enqueue ONLY the missing embeddings (items that exist in MySQL but have no current Supabase row). Already-embedded items are not touched. Proceed?', 'cleversay')); ?>');">
                    <?php wp_nonce_field('cleversay_site_supabase_backfill_missing', 'cleversay_site_supabase_backfill_missing_nonce'); ?>
                    <button type="submit" name="cleversay_site_supabase_action" value="backfill_missing"
                            class="button button-primary">
                        <?php
                        printf(
                            /* translators: %d = number of items missing embeddings */
                            esc_html__('Backfill Missing Only (%d)', 'cleversay'),
                            $total_gap
                        );
                        ?>
                    </button>
                </form>
                <?php endif; ?>

                <form method="post" action="" style="display:inline-block;margin-right:8px;"
                      onsubmit="return confirm('<?php echo esc_js(__('Queue ALL existing KB entries and source chunks for embedding generation. This re-embeds everything — use Backfill Missing Only for normal gap-filling. Proceed?', 'cleversay')); ?>');">
                    <?php wp_nonce_field('cleversay_site_supabase_backfill', 'cleversay_site_supabase_backfill_nonce'); ?>
                    <button type="submit" name="cleversay_site_supabase_action" value="backfill"
                            class="button">
                        <?php esc_html_e('Backfill All Existing Content', 'cleversay'); ?>
                    </button>
                </form>

                <form method="post" action="" style="display:inline-block;margin-right:8px;">
                    <?php wp_nonce_field('cleversay_site_supabase_process_now', 'cleversay_site_supabase_process_now_nonce'); ?>
                    <button type="submit" name="cleversay_site_supabase_action" value="process_now"
                            class="button">
                        <?php esc_html_e('Process Queue Now', 'cleversay'); ?>
                    </button>
                </form>

                <?php if (!empty($stats['failed'])): ?>
                <form method="post" action="" style="display:inline-block;">
                    <?php wp_nonce_field('cleversay_site_supabase_retry_failed', 'cleversay_site_supabase_retry_failed_nonce'); ?>
                    <button type="submit" name="cleversay_site_supabase_action" value="retry_failed"
                            class="button">
                        <?php esc_html_e('Retry Failed Jobs', 'cleversay'); ?>
                    </button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- AI Settings (per-tenant) -->
    <div class="cleversay-table-card" style="margin-bottom:20px;">
        <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
            <h3 style="margin:0;font-size:14px;font-weight:600;">
                <?php echo \CleverSay\Icons::render('sparkles', 16); ?>
                <?php esc_html_e('AI Settings (this site)', 'cleversay'); ?>
            </h3>
        </div>
        <div style="padding:14px 18px;">
            <p class="description" style="margin-top:0;margin-bottom:14px;">
                <?php esc_html_e(
                    'Per-tenant retrieval setting. Other AI settings (provider, model, API key, validation, polish) are configured at the network level.',
                    'cleversay'
                ); ?>
            </p>
            <form method="post" action="">
                <?php wp_nonce_field('cleversay_site_embeddings_settings', 'cleversay_site_embeddings_settings_nonce'); ?>
                <table class="form-table" style="margin:0;">
                    <tr>
                        <th><label for="ai_max_chunks"><?php esc_html_e('Context Chunks', 'cleversay'); ?></label></th>
                        <td>
                            <input type="number"
                                   id="ai_max_chunks"
                                   name="ai_max_chunks"
                                   class="small-text"
                                   min="1" max="10" step="1"
                                   value="<?php echo esc_attr((string) (int) $max_chunks); ?>">
                            <p class="description" style="max-width:600px;">
                                <?php esc_html_e(
                                    'How many of the top-ranked KB chunks to send to the synthesizer as context. Higher values give the model more raw material but cost more tokens per answer. Default 6.',
                                    'cleversay'
                                ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <p class="submit" style="margin-top:8px;">
                    <button type="submit" name="cleversay_site_supabase_action" value="save_settings"
                            class="button button-primary">
                        <?php esc_html_e('Save Settings', 'cleversay'); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>
</div>
