<?php
/**
 * Network Client Sites View
 *
 * @package CleverSay
 * @since   4.0.0
 * @var array $sites        All client sites
 * @var int   $edit_site_id Site being edited (0 = list view)
 * @var array $edit_plan    Plan data for site being edited
 */

if (!defined('ABSPATH')) exit;

// ── Trial warning banner data ─────────────────────────────────────────
// Walk the visible sites, group them by trial state for a top-of-page
// summary banner. Helps super-admins notice expiring trials before they
// become support emails.
$trial_warnings = [
    'expiring_soon' => [],  // ≤7 days remaining
    'in_grace'      => [],  // expired but within grace
    'suspended'     => [],  // past grace
];
foreach ($sites as $idx => $s) {
    $st     = $s['status'] ?? 'active';
    $ends   = trim((string) ($s['trial_ends_at'] ?? ''));
    $days   = $ends ? (int) floor((strtotime($ends) - time()) / 86400) : null;

    if ($st === 'suspended') {
        $trial_warnings['suspended'][] = $s;
    } elseif ($st === 'trial' && $days !== null) {
        if ($days < 0 && abs($days) <= 3) {
            $trial_warnings['in_grace'][] = $s + ['days_past' => abs($days)];
        } elseif ($days >= 0 && $days <= 7) {
            $trial_warnings['expiring_soon'][] = $s + ['days_remaining' => $days];
        }
    }
}
?>
<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline">
        <?php echo \CleverSay\Icons::render('globe', 18); ?>
        <?php esc_html_e('Client Sites', 'cleversay'); ?>
    </h1>
    <hr class="wp-header-end">

    <?php
    // ── Pack apply result banner ─────────────────────────────────────────
    if (!empty($_GET['pack_applied'])) {
        $result = get_transient('cleversay_pack_result_' . get_current_user_id());
        if ($result) {
            delete_transient('cleversay_pack_result_' . get_current_user_id());
            $site = get_blog_details((int) $result['site_id']);
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong><?php esc_html_e('Starter pack applied.', 'cleversay'); ?></strong>
                    <?php
                    printf(
                        /* translators: 1 = pack label, 2 = site domain, 3 = entries added, 4 = entries skipped */
                        esc_html__('Installed %1$s on %2$s — %3$s added, %4$s skipped (already existed).', 'cleversay'),
                        '<em>' . esc_html($result['pack_label']) . '</em>',
                        '<code>' . esc_html($site->domain ?? '') . '</code>',
                        '<strong>' . number_format_i18n($result['added']) . '</strong>',
                        '<strong>' . number_format_i18n($result['skipped']) . '</strong>'
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    } elseif (!empty($_GET['pack_err'])) {
        $errs = [
            'invalid' => __('Invalid request — pack and site are required.', 'cleversay'),
            'no_site' => __('Site not found.', 'cleversay'),
            'no_pack' => __('Selected starter pack does not exist.', 'cleversay'),
        ];
        $msg = $errs[ sanitize_key($_GET['pack_err']) ] ?? __('Could not apply pack.', 'cleversay');
        ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($msg); ?></p></div>
        <?php
    }

    // ── Trial expiration banners ─────────────────────────────────────
    if (!empty($trial_warnings['suspended'])):
        $count = count($trial_warnings['suspended']);
    ?>
        <div class="notice notice-error" style="border-left-color:#d63638;">
            <p>
                <strong>
                    <?php
                    printf(
                        esc_html(_n(
                            '%d site is currently suspended.',
                            '%d sites are currently suspended.',
                            $count,
                            'cleversay'
                        )),
                        $count
                    );
                    ?>
                </strong>
                <?php esc_html_e('The chatbot widget displays an "unavailable" message on these sites.', 'cleversay'); ?>
            </p>
            <p style="margin-top:6px;font-size:12px;color:#646970;">
                <?php
                $names = array_map(fn($s) => $s['client_name'] ?: $s['domain'], $trial_warnings['suspended']);
                echo esc_html(implode(', ', $names));
                ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if (!empty($trial_warnings['in_grace'])):
        $count = count($trial_warnings['in_grace']);
    ?>
        <div class="notice notice-warning">
            <p>
                <strong>
                    <?php
                    printf(
                        esc_html(_n(
                            '%d site is in the post-trial grace period.',
                            '%d sites are in the post-trial grace period.',
                            $count,
                            'cleversay'
                        )),
                        $count
                    );
                    ?>
                </strong>
                <?php esc_html_e('Their chatbot still works, but will be suspended after 3 days past the trial end date.', 'cleversay'); ?>
            </p>
            <p style="margin-top:6px;font-size:12px;color:#646970;">
                <?php foreach ($trial_warnings['in_grace'] as $s): ?>
                    <?php echo esc_html($s['client_name'] ?: $s['domain']); ?>
                    (<?php
                    printf(
                        esc_html(_n('%d day past expiration', '%d days past expiration', $s['days_past'], 'cleversay')),
                        $s['days_past']
                    );
                    ?>)<?php if (next($trial_warnings['in_grace']) !== false) echo ', '; ?>
                <?php endforeach; ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if (!empty($trial_warnings['expiring_soon'])):
        $count = count($trial_warnings['expiring_soon']);
    ?>
        <div class="notice notice-info">
            <p>
                <strong>
                    <?php
                    printf(
                        esc_html(_n(
                            '%d site has a trial ending within 7 days.',
                            '%d sites have trials ending within 7 days.',
                            $count,
                            'cleversay'
                        )),
                        $count
                    );
                    ?>
                </strong>
            </p>
            <p style="margin-top:6px;font-size:12px;color:#646970;">
                <?php foreach ($trial_warnings['expiring_soon'] as $s): ?>
                    <?php echo esc_html($s['client_name'] ?: $s['domain']); ?>
                    (<?php
                    if ($s['days_remaining'] === 0) {
                        esc_html_e('today', 'cleversay');
                    } else {
                        printf(
                            esc_html(_n('%d day left', '%d days left', $s['days_remaining'], 'cleversay')),
                            $s['days_remaining']
                        );
                    }
                    ?>)<?php if (next($trial_warnings['expiring_soon']) !== false) echo ', '; ?>
                <?php endforeach; ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($edit_site_id > 0):
        $site = get_site($edit_site_id);
    ?>
    <!-- Edit Plan Form -->
    <h2><?php echo esc_html(sprintf(__('Edit Plan: %s', 'cleversay'), $site->domain ?? '')); ?></h2>
    <a href="<?php echo esc_url(network_admin_url('admin.php?page=cleversay-network-sites')); ?>"
       class="button" style="margin-bottom:16px;">
        ← <?php esc_html_e('Back to Sites', 'cleversay'); ?>
    </a>

    <form method="post" action="">
        <?php wp_nonce_field('cleversay_site_plan', 'cleversay_site_plan_nonce'); ?>
        <input type="hidden" name="site_id" value="<?php echo esc_attr($edit_site_id); ?>">

        <div class="cleversay-table-card" style="margin-bottom:20px;">
            <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
                <h3 style="margin:0;font-size:14px;font-weight:600;">
                    <?php echo \CleverSay\Icons::render('user', 16); ?>
                    <?php esc_html_e('Client Information', 'cleversay'); ?>
                </h3>
            </div>
            <table class="form-table" style="margin:0;">
                <tr>
                    <th><label for="client_name"><?php esc_html_e('Client Name', 'cleversay'); ?></label></th>
                    <td>
                        <input type="text" name="client_name" id="client_name"
                               class="regular-text"
                               value="<?php echo esc_attr($edit_plan['client_name'] ?? ''); ?>">
                        <p class="description"><?php esc_html_e('Displayed in the client admin portal header.', 'cleversay'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="client_logo_url"><?php esc_html_e('Client Logo', 'cleversay'); ?></label></th>
                    <td>
                        <?php $logo = esc_url($edit_plan['client_logo_url'] ?? ''); ?>
                        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                            <?php if ($logo): ?>
                            <img id="cleversay-logo-preview"
                                 src="<?php echo $logo; ?>"
                                 alt=""
                                 style="max-width:160px;max-height:60px;border:1px solid rgba(0,0,0,0.1);
                                        border-radius:6px;padding:4px;background:#fff;">
                            <?php else: ?>
                            <img id="cleversay-logo-preview"
                                 src="" alt=""
                                 style="display:none;max-width:160px;max-height:60px;
                                        border:1px solid rgba(0,0,0,0.1);border-radius:6px;
                                        padding:4px;background:#fff;">
                            <?php endif; ?>

                            <div style="display:flex;flex-direction:column;gap:6px;">
                                <button type="button" id="cleversay-upload-logo-btn" class="button">
                                    <?php echo \CleverSay\Icons::render('upload', 18); ?>
                                    <?php esc_html_e($logo ? 'Change Logo' : 'Upload Logo', 'cleversay'); ?>
                                </button>
                                <button type="button" id="cleversay-remove-logo-btn" class="button"
                                        style="<?php echo $logo ? '' : 'display:none;'; ?>color:#cc1818;">
                                    <?php echo \CleverSay\Icons::render('trash', 18); ?>
                                    <?php esc_html_e('Remove', 'cleversay'); ?>
                                </button>
                            </div>
                        </div>

                        <input type="hidden" name="client_logo_url" id="client_logo_url"
                               value="<?php echo $logo; ?>">
                        <p class="description" style="margin-top:8px;">
                            <?php esc_html_e('Displayed in the client admin portal header. Recommended size: 200×60px, transparent PNG.', 'cleversay'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="client_email"><?php esc_html_e('Client Email', 'cleversay'); ?></label></th>
                    <td>
                        <input type="email" name="client_email" id="client_email"
                               class="regular-text"
                               value="<?php echo esc_attr($edit_plan['client_email'] ?? ''); ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="activated_date"><?php esc_html_e('Activated Date', 'cleversay'); ?></label></th>
                    <td>
                        <input type="date" name="activated_date" id="activated_date"
                               value="<?php echo esc_attr($edit_plan['activated_date'] ?? ''); ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="embed_domains"><?php esc_html_e('Allowed Embed Domains', 'cleversay'); ?></label></th>
                    <td>
                        <textarea name="embed_domains" id="embed_domains"
                                  class="large-text" rows="4"><?php echo esc_textarea($edit_plan['embed_domains'] ?? ''); ?></textarea>
                        <p class="description">
                            <?php esc_html_e('One domain per line. The widget will only load on these domains. Use * to allow all. Example: https://uwosh.edu', 'cleversay'); ?>
                        </p>
                        <p class="description" style="color:#d63638;margin-top:4px;">
                            <?php esc_html_e('Only you can change this — clients cannot see or modify their allowed domains.', 'cleversay'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="cleversay-table-card" style="margin-bottom:20px;">
            <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
                <h3 style="margin:0;font-size:14px;font-weight:600;">
                    <?php echo \CleverSay\Icons::render('briefcase', 16); ?>
                    <?php esc_html_e('Plan & Status', 'cleversay'); ?>
                </h3>
            </div>
            <table class="form-table" style="margin:0;">
                <tr>
                    <th><label for="plan"><?php esc_html_e('Plan', 'cleversay'); ?></label></th>
                    <td>
                        <select name="plan" id="plan">
                            <option value="trial"      <?php selected($edit_plan['plan'] ?? '', 'trial'); ?>><?php esc_html_e('Trial', 'cleversay'); ?></option>
                            <option value="basic"      <?php selected($edit_plan['plan'] ?? 'basic', 'basic'); ?>><?php esc_html_e('Basic', 'cleversay'); ?></option>
                            <option value="pro"        <?php selected($edit_plan['plan'] ?? '', 'pro'); ?>><?php esc_html_e('Pro', 'cleversay'); ?></option>
                            <option value="enterprise" <?php selected($edit_plan['plan'] ?? '', 'enterprise'); ?>><?php esc_html_e('Enterprise', 'cleversay'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="status"><?php esc_html_e('Status', 'cleversay'); ?></label></th>
                    <td>
                        <select name="status" id="status">
                            <option value="active"    <?php selected($edit_plan['status'] ?? 'active', 'active'); ?>><?php esc_html_e('Active', 'cleversay'); ?></option>
                            <option value="trial"     <?php selected($edit_plan['status'] ?? '', 'trial'); ?>><?php esc_html_e('Trial', 'cleversay'); ?></option>
                            <option value="suspended" <?php selected($edit_plan['status'] ?? '', 'suspended'); ?>><?php esc_html_e('Suspended', 'cleversay'); ?></option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Suspended sites display an "unavailable" message instead of activating the chatbot.', 'cleversay'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="trial_ends_at"><?php esc_html_e('Trial End Date', 'cleversay'); ?></label></th>
                    <td>
                        <input type="date" name="trial_ends_at" id="trial_ends_at"
                               value="<?php echo esc_attr($edit_plan['trial_ends_at'] ?? ''); ?>">
                        <p class="description">
                            <?php esc_html_e('Only meaningful when Status is "Trial". Site is suspended 3 days after this date if not flipped to Active. Leave blank for indefinite trials.', 'cleversay'); ?>
                            <?php
                            // Show clear context when there's a current value
                            $ends_at = trim((string) ($edit_plan['trial_ends_at'] ?? ''));
                            if ($ends_at && ($edit_plan['status'] ?? '') === 'trial') {
                                $end_ts   = strtotime($ends_at);
                                $days_rem = (int) floor(($end_ts - time()) / 86400);
                                if ($days_rem < 0) {
                                    $hint = sprintf(__('Trial ended %d days ago.', 'cleversay'), abs($days_rem));
                                } elseif ($days_rem === 0) {
                                    $hint = __('Trial ends today.', 'cleversay');
                                } else {
                                    $hint = sprintf(_n('%d day remaining.', '%d days remaining.', $days_rem, 'cleversay'), $days_rem);
                                }
                                echo '<br><strong>' . esc_html($hint) . '</strong>';
                            }
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="kb_limit"><?php esc_html_e('KB Entry Limit', 'cleversay'); ?></label></th>
                    <td>
                        <input type="number" name="kb_limit" id="kb_limit"
                               class="small-text" min="0"
                               value="<?php echo esc_attr($edit_plan['kb_limit'] ?? 500); ?>">
                        <p class="description"><?php esc_html_e('Max KB entries for this site. 0 = unlimited.', 'cleversay'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="ai_calls_monthly"><?php esc_html_e('AI Calls / Month', 'cleversay'); ?></label></th>
                    <td>
                        <input type="number" name="ai_calls_monthly" id="ai_calls_monthly"
                               class="small-text" min="0"
                               value="<?php echo esc_attr($edit_plan['ai_calls_monthly'] ?? 1000); ?>">
                        <p class="description"><?php esc_html_e('0 = unlimited.', 'cleversay'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="ai_budget_monthly"><?php esc_html_e('AI Budget / Month (USD)', 'cleversay'); ?></label></th>
                    <td>
                        <input type="number" name="ai_budget_monthly" id="ai_budget_monthly"
                               class="small-text" min="0" step="1"
                               value="<?php echo esc_attr($edit_plan['ai_budget_monthly'] ?? 10); ?>">
                        <p class="description"><?php esc_html_e('Per-site AI spend cap. 0 = no limit.', 'cleversay'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="ai_model_override"><?php esc_html_e('AI Model Override', 'cleversay'); ?></label></th>
                    <td>
                        <?php
                        // Pull current override from the per-site option (we have to switch_to_blog
                        // to read it, since this is in network admin viewing a different blog).
                        switch_to_blog((int) $edit_site_id);
                        $current_override = (string) get_option('cleversay_ai_model_override', '');
                        restore_current_blog();
                        $available_models = \CleverSay\AI::get_available_models();
                        ?>
                        <select name="ai_model_override" id="ai_model_override">
                            <option value=""><?php esc_html_e('— Use network default —', 'cleversay'); ?></option>
                            <?php foreach ($available_models as $model_id => $label): ?>
                                <option value="<?php echo esc_attr($model_id); ?>" <?php selected($current_override, $model_id); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Override the network-default AI model for this client only. Useful for A/B testing answer quality between models on the same content. Empty = use network default.', 'cleversay'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button(__('Save Site Plan', 'cleversay')); ?>
    </form>

    <?php else: ?>
    <!-- Sites List -->
    <div class="cleversay-table-card">
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Client', 'cleversay'); ?></th>
                    <th class="column-actions"><?php esc_html_e('Actions', 'cleversay'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sites)): ?>
                <tr>
                    <td colspan="2" style="text-align:center;padding:24px;color:#86868b;">
                        <?php esc_html_e('No client sites found.', 'cleversay'); ?>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($sites as $site):
                    $status = $site['status'] ?? 'active';
                    $status_colors = ['active' => '#34c759', 'trial' => '#ff9f0a', 'suspended' => '#ff3b30'];
                    $status_color  = $status_colors[$status] ?? '#86868b';
                    $admin_url     = get_admin_url($site['blog_id']);
                    $subdomain     = $site['domain'];
                    if (defined('DOMAIN_CURRENT_SITE')) {
                        $subdomain = preg_replace('/\.' . preg_quote(DOMAIN_CURRENT_SITE, '/') . '$/', '', $subdomain);
                    }
                ?>
                <tr>
                    <td>
                        <div style="font-weight:600;font-size:14px;color:#1d2327;margin-bottom:4px;">
                            <?php echo esc_html($site['client_name'] ?: '—'); ?>
                        </div>
                        <div style="font-size:12px;color:#646970;line-height:1.6;">
                            <span style="color:#1d2327;font-family:monospace;"><?php echo esc_html($subdomain); ?></span>
                            <span style="color:#c3c4c7;margin:0 6px;">|</span>
                            <span><?php esc_html_e('Status:', 'cleversay'); ?></span>
                            <span style="display:inline-block;padding:1px 7px;border-radius:10px;
                                         background:<?php echo esc_attr($status_color); ?>20;
                                         color:<?php echo esc_attr($status_color); ?>;
                                         font-size:11px;font-weight:600;">
                                <?php echo esc_html(ucfirst($status)); ?>
                            </span>
                            <span style="color:#c3c4c7;margin:0 6px;">|</span>
                            <span><?php esc_html_e('Plan:', 'cleversay'); ?></span>
                            <strong style="color:#1d2327;"><?php echo esc_html(ucfirst($site['plan'] ?? 'basic')); ?></strong>
                            <span style="color:#c3c4c7;margin:0 6px;">|</span>
                            <span><?php esc_html_e('KB Limit:', 'cleversay'); ?></span>
                            <strong style="color:#1d2327;"><?php echo esc_html($site['kb_limit'] ?? 500); ?></strong>
                            <span style="color:#c3c4c7;margin:0 6px;">|</span>
                            <span><?php esc_html_e('AI Budget:', 'cleversay'); ?></span>
                            <strong style="color:#1d2327;">$<?php echo esc_html(number_format((float)($site['ai_budget_monthly'] ?? 10), 0)); ?>/mo</strong>
                        </div>
                    </td>
                    <td class="column-actions" style="vertical-align:top;">
                        <div style="display:flex;flex-wrap:wrap;gap:4px;justify-content:flex-end;max-width:340px;">
                            <a href="<?php echo esc_url(network_admin_url('admin.php?page=cleversay-network-sites&edit=' . $site['blog_id'])); ?>"
                               class="button button-small">
                                <?php esc_html_e('Edit Plan', 'cleversay'); ?>
                            </a>
                            <a href="<?php echo esc_url($admin_url . 'admin.php?page=cleversay'); ?>"
                               target="_blank" class="button button-small">
                                <?php esc_html_e('Open Admin', 'cleversay'); ?>
                            </a>
                            <a href="<?php echo esc_url($admin_url . 'admin.php?page=cleversay-handoff'); ?>"
                               target="_blank" class="button button-small"
                               title="<?php esc_attr_e('Open the client handoff document for this site', 'cleversay'); ?>">
                                <?php esc_html_e('Handoff', 'cleversay'); ?>
                            </a>
                            <a href="<?php echo esc_url('https://' . $site['domain']); ?>"
                               target="_blank" class="button button-small">
                                <?php esc_html_e('Visit Site', 'cleversay'); ?>
                            </a>
                            <button type="button" class="button button-small cs-pack-trigger"
                                    data-site-id="<?php echo (int) $site['blog_id']; ?>"
                                    data-site-domain="<?php echo esc_attr($site['domain']); ?>"
                                    title="<?php esc_attr_e('Install a starter knowledge-base pack on this site', 'cleversay'); ?>">
                                <?php esc_html_e('Install Pack', 'cleversay'); ?>
                            </button>
                            <?php
                            // WP core (wp-admin/network/sites.php) verifies the delete
                            // request with: check_admin_referer( $site_action . '_' . $id )
                            // → so for deleteblog on site 7, the action string is "deleteblog_7".
                            $delete_url = wp_nonce_url(
                                network_admin_url('sites.php?action=confirm&action2=deleteblog&id=' . $site['blog_id']),
                                'deleteblog_' . $site['blog_id']
                            );
                            ?>
                            <a href="<?php echo esc_url($delete_url); ?>"
                               class="button button-small"
                               style="color:#d63638;border-color:#d63638;"
                               onclick="return confirm('<?php echo esc_js(sprintf(__('Delete client site %s? This permanently removes all data for this site.', 'cleversay'), $site['client_name'] ?: $subdomain)); ?>');"
                               title="<?php esc_attr_e('Permanently delete this client site and all its data', 'cleversay'); ?>">
                                <?php esc_html_e('Delete', 'cleversay'); ?>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ── Install Starter Pack Modal ──────────────────────────────────── -->
    <div id="cs-pack-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99999;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:8px;padding:24px;max-width:560px;width:90%;max-height:85vh;overflow-y:auto;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
            <h2 style="margin:0 0 12px;font-size:18px;"><?php esc_html_e('Install Starter Pack', 'cleversay'); ?></h2>
            <p style="margin:0 0 16px;color:#50575e;font-size:13px;">
                <?php esc_html_e('Adds pre-written question-and-answer entries to', 'cleversay'); ?>
                <code id="cs-pack-domain"></code>.
                <?php esc_html_e('Existing entries with the same question are skipped — your customizations are safe.', 'cleversay'); ?>
            </p>

            <form method="post">
                <?php wp_nonce_field('cleversay_apply_pack', '_cs_pack_nonce'); ?>
                <input type="hidden" name="cleversay_apply_pack" value="1">
                <input type="hidden" name="site_id" id="cs-pack-site-id" value="">

                <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:18px;">
                    <?php
                    $available_packs = \CleverSay\StarterKB::packs();
                    $first = true;
                    foreach ($available_packs as $slug => $info):
                        if ($slug === 'empty') continue;
                        $count = count($info['entries'] ?? []);
                    ?>
                        <label style="display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid #dcdcde;border-radius:6px;cursor:pointer;">
                            <input type="radio" name="pack_slug" value="<?php echo esc_attr($slug); ?>"
                                   <?php checked($first); ?> style="margin-top:3px;">
                            <span>
                                <strong style="font-size:14px;"><?php echo esc_html($info['label'] ?? $slug); ?></strong>
                                <span style="color:#646970;font-size:12px;">
                                    <?php
                                    printf(
                                        esc_html(_n('(%s entry)', '(%s entries)', $count, 'cleversay')),
                                        number_format_i18n($count)
                                    );
                                    ?>
                                </span>
                                <?php if (!empty($info['description'])): ?>
                                    <div style="font-size:12px;color:#646970;margin-top:4px;line-height:1.4;">
                                        <?php echo esc_html($info['description']); ?>
                                    </div>
                                <?php endif; ?>
                            </span>
                        </label>
                    <?php $first = false; endforeach; ?>
                </div>

                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button type="button" class="button" id="cs-pack-cancel">
                        <?php esc_html_e('Cancel', 'cleversay'); ?>
                    </button>
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Install Pack', 'cleversay'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function () {
        var modal     = document.getElementById('cs-pack-modal');
        var cancelBtn = document.getElementById('cs-pack-cancel');
        var siteIdIn  = document.getElementById('cs-pack-site-id');
        var domainEl  = document.getElementById('cs-pack-domain');
        if (!modal) return;

        document.querySelectorAll('.cs-pack-trigger').forEach(function (btn) {
            btn.addEventListener('click', function () {
                siteIdIn.value      = btn.getAttribute('data-site-id');
                domainEl.textContent = btn.getAttribute('data-site-domain');
                modal.style.display = 'flex';
            });
        });
        function close() { modal.style.display = 'none'; }
        cancelBtn && cancelBtn.addEventListener('click', close);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.style.display !== 'none') close();
        });
    })();
    </script>
</div>
