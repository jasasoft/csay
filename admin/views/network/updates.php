<?php
/**
 * Network Updates View
 *
 * @package CleverSay
 * @since   4.2.0
 *
 * @var \CleverSay\Updater $updater
 * @var array  $client_sites
 * @var array  $daily_snapshots
 * @var array  $manual_snapshots
 * @var string $prod_version
 * @var string $staging_version
 * @var bool   $staging_exists
 * @var string $action_result   Optional result message from a just-completed action
 * @var bool   $action_success
 */

if (!defined('ABSPATH')) exit;

$backup_mb = $updater->get_backup_size_mb();
?>
<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline">
        <?php echo \CleverSay\Icons::render('refresh-cw', 18); ?>
        <?php esc_html_e('CleverSay Updates', 'cleversay'); ?>
    </h1>
    <hr class="wp-header-end">

    <?php if (!empty($action_result)): ?>
    <div class="notice <?php echo $action_success ? 'notice-success' : 'notice-error'; ?> is-dismissible">
        <p><?php echo esc_html($action_result); ?></p>
    </div>
    <?php endif; ?>

    <!-- Version Status Bar -->
    <div class="cleversay-stats-row" style="margin-bottom:24px;">
        <div class="cleversay-stat-card">
            <div class="stat-value" style="font-size:20px;"><?php echo esc_html($prod_version); ?></div>
            <div class="stat-label"><?php esc_html_e('Production Version', 'cleversay'); ?></div>
        </div>
        <div class="cleversay-stat-card">
            <div class="stat-value" style="font-size:20px;color:<?php echo $staging_exists ? '#ff9f0a' : '#86868b'; ?>;">
                <?php echo esc_html($staging_version); ?>
            </div>
            <div class="stat-label"><?php esc_html_e('Staging Version', 'cleversay'); ?></div>
        </div>
        <div class="cleversay-stat-card">
            <div class="stat-value"><?php echo esc_html(count($client_sites)); ?></div>
            <div class="stat-label"><?php esc_html_e('Client Sites', 'cleversay'); ?></div>
        </div>
        <div class="cleversay-stat-card">
            <div class="stat-value"><?php echo esc_html($backup_mb); ?> MB</div>
            <div class="stat-label"><?php esc_html_e('Backup Storage Used', 'cleversay'); ?></div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

        <!-- LEFT COLUMN -->
        <div>

            <!-- Upload New Version -->
            <div class="cleversay-table-card" style="margin-bottom:20px;">
                <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
                    <h3 style="margin:0;font-size:14px;font-weight:600;">
                        <?php echo \CleverSay\Icons::render('upload', 18); ?>
                        <?php esc_html_e('Upload New Version to Staging', 'cleversay'); ?>
                    </h3>
                </div>
                <div style="padding:18px;">
                    <p class="description" style="margin:0 0 14px;">
                        <?php esc_html_e('Upload a CleverSay zip to replace the staging plugin. Production is not affected until you push.', 'cleversay'); ?>
                    </p>
                    <form method="post" enctype="multipart/form-data" action="">
                        <?php wp_nonce_field('cleversay_updater', 'cleversay_updater_nonce'); ?>
                        <input type="hidden" name="cleversay_updater_action" value="upload_staging">
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <input type="file" name="plugin_zip" accept=".zip" required
                                   style="flex:1;min-width:200px;">
                            <button type="submit" class="button button-primary">
                                <?php echo \CleverSay\Icons::render('upload', 18); ?>
                                <?php esc_html_e('Upload to Staging', 'cleversay'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Push to Production -->
            <div class="cleversay-table-card" style="margin-bottom:20px;">
                <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
                    <h3 style="margin:0;font-size:14px;font-weight:600;">
                        <?php echo \CleverSay\Icons::render('arrow-right', 18); ?>
                        <?php esc_html_e('Push Staging → Production', 'cleversay'); ?>
                    </h3>
                </div>
                <div style="padding:18px;">
                    <?php if (!$staging_exists): ?>
                    <p class="description" style="color:#86868b;">
                        <?php esc_html_e('No staging version installed. Upload a zip above first.', 'cleversay'); ?>
                    </p>
                    <?php else: ?>
                    <p class="description" style="margin:0 0 14px;">
                        <?php printf(
                            esc_html__('This will replace production (v%s) with staging (v%s) across ALL client sites. A snapshot is created automatically before pushing.', 'cleversay'),
                            esc_html($prod_version),
                            esc_html($staging_version)
                        ); ?>
                    </p>
                    <form method="post" action=""
                          onsubmit="return confirm('<?php esc_attr_e('Push staging to ALL production sites? A snapshot will be created first.', 'cleversay'); ?>')">
                        <?php wp_nonce_field('cleversay_updater', 'cleversay_updater_nonce'); ?>
                        <input type="hidden" name="cleversay_updater_action" value="push_to_production">
                        <button type="submit" class="button button-primary"
                                style="background:#34c759;border-color:#34c759;">
                            <?php echo \CleverSay\Icons::render('check-circle', 18); ?>
                            <?php printf(
                                esc_html__('Push v%s to All Production Sites', 'cleversay'),
                                esc_html($staging_version)
                            ); ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Copy KB to Staging -->
            <div class="cleversay-table-card" style="margin-bottom:20px;">
                <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
                    <h3 style="margin:0;font-size:14px;font-weight:600;">
                        <?php echo \CleverSay\Icons::render('database', 18); ?>
                        <?php esc_html_e('Copy Data to Staging', 'cleversay'); ?>
                    </h3>
                </div>
                <div style="padding:18px;">
                    <p class="description" style="margin:0 0 14px;">
                        <?php esc_html_e('Copy a client\'s KB and AI Sources into staging so you can test against real data.', 'cleversay'); ?>
                    </p>
                    <form method="post" action="">
                        <?php wp_nonce_field('cleversay_updater', 'cleversay_updater_nonce'); ?>
                        <input type="hidden" name="cleversay_updater_action" value="copy_to_staging">

                        <table class="form-table" style="margin:0 0 14px;">
                            <tr>
                                <th style="width:130px;padding:8px 0;">
                                    <?php esc_html_e('Copy From', 'cleversay'); ?>
                                </th>
                                <td style="padding:8px 0;">
                                    <select name="source_blog_id" style="width:100%;max-width:280px;">
                                        <?php foreach ($client_sites as $site): ?>
                                        <option value="<?php echo esc_attr($site['blog_id']); ?>">
                                            <?php echo esc_html($site['client_name'] ?: $site['domain']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:8px 0;"><?php esc_html_e('Include', 'cleversay'); ?></th>
                                <td style="padding:8px 0;">
                                    <label style="display:block;margin-bottom:6px;">
                                        <input type="checkbox" name="copy_kb" value="1" checked>
                                        <?php esc_html_e('Knowledge Base entries', 'cleversay'); ?>
                                    </label>
                                    <label style="display:block;margin-bottom:6px;">
                                        <input type="checkbox" name="copy_sources" value="1" checked>
                                        <?php esc_html_e('AI Sources and chunks', 'cleversay'); ?>
                                    </label>
                                    <label style="display:block;">
                                        <input type="checkbox" name="copy_synonyms" value="1">
                                        <?php esc_html_e('Synonyms', 'cleversay'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:8px 0;"><?php esc_html_e('Options', 'cleversay'); ?></th>
                                <td style="padding:8px 0;">
                                    <label>
                                        <input type="checkbox" name="clear_first" value="1" checked>
                                        <?php esc_html_e('Clear staging data first (recommended)', 'cleversay'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <button type="submit" class="button button-primary"
                                onclick="return confirm('<?php esc_attr_e('Copy selected data to staging? This will overwrite existing staging data if Clear is checked.', 'cleversay'); ?>')">
                            <?php echo \CleverSay\Icons::render('database', 18); ?>
                            <?php esc_html_e('Copy to Staging', 'cleversay'); ?>
                        </button>
                    </form>
                </div>
            </div>

        </div><!-- /left column -->

        <!-- RIGHT COLUMN -->
        <div>

            <!-- Manual Snapshot -->
            <div class="cleversay-table-card" style="margin-bottom:20px;">
                <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
                    <h3 style="margin:0;font-size:14px;font-weight:600;">
                        <?php echo \CleverSay\Icons::render('camera', 18); ?>
                        <?php esc_html_e('Create Manual Snapshot', 'cleversay'); ?>
                    </h3>
                </div>
                <div style="padding:18px;">
                    <p class="description" style="margin:0 0 14px;">
                        <?php esc_html_e('Snapshot the current production plugin files. Use before making any significant changes.', 'cleversay'); ?>
                    </p>
                    <form method="post" action="">
                        <?php wp_nonce_field('cleversay_updater', 'cleversay_updater_nonce'); ?>
                        <input type="hidden" name="cleversay_updater_action" value="create_snapshot">
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input type="text" name="snapshot_label" class="regular-text"
                                   placeholder="<?php esc_attr_e('Label (optional, e.g. before-feature-x)', 'cleversay'); ?>"
                                   style="flex:1;">
                            <button type="submit" class="button">
                                <?php echo \CleverSay\Icons::render('camera', 18); ?>
                                <?php esc_html_e('Snapshot Now', 'cleversay'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Daily Snapshots -->
            <div class="cleversay-table-card" style="margin-bottom:20px;">
                <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="margin:0;font-size:14px;font-weight:600;">
                        <?php echo \CleverSay\Icons::render('calendar', 18); ?>
                        <?php esc_html_e('Daily Snapshots', 'cleversay'); ?>
                    </h3>
                    <span style="font-size:12px;color:#86868b;">
                        <?php printf(esc_html__('Auto — keeps %d days', 'cleversay'), \CleverSay\Updater::DAILY_KEEP); ?>
                    </span>
                </div>
                <div style="padding:8px 0;">
                    <?php if (empty($daily_snapshots)): ?>
                    <p style="padding:12px 18px;color:#86868b;font-size:13px;margin:0;">
                        <?php esc_html_e('No daily snapshots yet. The first will be created tonight.', 'cleversay'); ?>
                    </p>
                    <?php else: ?>
                    <table class="wp-list-table widefat" style="border:none;">
                        <tbody>
                        <?php foreach ($daily_snapshots as $snap): ?>
                        <tr>
                            <td style="padding:8px 18px;">
                                <strong><?php echo esc_html($snap['name']); ?></strong>
                                <span style="font-size:11px;color:#86868b;margin-left:6px;">
                                    <?php echo esc_html($snap['size_kb']); ?> KB
                                </span>
                            </td>
                            <td style="padding:8px 18px;text-align:right;white-space:nowrap;">
                                <form method="post" action="" style="display:inline;"
                                      onsubmit="return confirm('<?php esc_attr_e('Restore production from this snapshot? Current state will be backed up first.', 'cleversay'); ?>')">
                                    <?php wp_nonce_field('cleversay_updater', 'cleversay_updater_nonce'); ?>
                                    <input type="hidden" name="cleversay_updater_action" value="restore_snapshot">
                                    <input type="hidden" name="snapshot_path" value="<?php echo esc_attr($snap['path']); ?>">
                                    <button type="submit" class="button button-small"
                                            style="color:#d63638;border-color:#d63638;">
                                        <?php esc_html_e('Restore', 'cleversay'); ?>
                                    </button>
                                </form>
                                <form method="post" action="" style="display:inline;margin-left:4px;"
                                      onsubmit="return confirm('<?php esc_attr_e('Delete this snapshot? This cannot be undone.', 'cleversay'); ?>')">
                                    <?php wp_nonce_field('cleversay_updater', 'cleversay_updater_nonce'); ?>
                                    <input type="hidden" name="cleversay_updater_action" value="delete_snapshot">
                                    <input type="hidden" name="snapshot_path" value="<?php echo esc_attr($snap['path']); ?>">
                                    <button type="submit" class="button button-small">
                                        <?php esc_html_e('Delete', 'cleversay'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Manual Snapshots -->
            <div class="cleversay-table-card">
                <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
                    <h3 style="margin:0;font-size:14px;font-weight:600;">
                        <?php echo \CleverSay\Icons::render('save', 18); ?>
                        <?php esc_html_e('Manual Snapshots', 'cleversay'); ?>
                    </h3>
                </div>
                <div style="padding:8px 0;">
                    <?php if (empty($manual_snapshots)): ?>
                    <p style="padding:12px 18px;color:#86868b;font-size:13px;margin:0;">
                        <?php esc_html_e('No manual snapshots yet.', 'cleversay'); ?>
                    </p>
                    <?php else: ?>
                    <table class="wp-list-table widefat" style="border:none;">
                        <tbody>
                        <?php foreach ($manual_snapshots as $snap): ?>
                        <tr>
                            <td style="padding:8px 18px;">
                                <strong><?php echo esc_html($snap['name']); ?></strong>
                                <br>
                                <span style="font-size:11px;color:#86868b;">
                                    <?php echo esc_html($snap['modified']); ?>
                                    · <?php echo esc_html($snap['size_kb']); ?> KB
                                </span>
                            </td>
                            <td style="padding:8px 18px;text-align:right;white-space:nowrap;">
                                <form method="post" action="" style="display:inline;"
                                      onsubmit="return confirm('<?php esc_attr_e('Restore production from this snapshot? Current state will be backed up first.', 'cleversay'); ?>')">
                                    <?php wp_nonce_field('cleversay_updater', 'cleversay_updater_nonce'); ?>
                                    <input type="hidden" name="cleversay_updater_action" value="restore_snapshot">
                                    <input type="hidden" name="snapshot_path" value="<?php echo esc_attr($snap['path']); ?>">
                                    <button type="submit" class="button button-small"
                                            style="color:#d63638;border-color:#d63638;">
                                        <?php esc_html_e('Restore', 'cleversay'); ?>
                                    </button>
                                </form>
                                <form method="post" action="" style="display:inline;margin-left:4px;"
                                      onsubmit="return confirm('<?php esc_attr_e('Delete this snapshot? This cannot be undone.', 'cleversay'); ?>')">
                                    <?php wp_nonce_field('cleversay_updater', 'cleversay_updater_nonce'); ?>
                                    <input type="hidden" name="cleversay_updater_action" value="delete_snapshot">
                                    <input type="hidden" name="snapshot_path" value="<?php echo esc_attr($snap['path']); ?>">
                                    <button type="submit" class="button button-small">
                                        <?php esc_html_e('Delete', 'cleversay'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /right column -->
    </div>
</div>
