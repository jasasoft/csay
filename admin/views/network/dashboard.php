<?php
/**
 * Network Dashboard View
 *
 * @package CleverSay
 * @since   4.0.0
 *
 * @var array $sites  Client sites from NetworkSettings::get_client_sites()
 */

if (!defined('ABSPATH')) exit;

// Gather aggregate stats across all client sites
$total_sites      = count($sites);
$active_sites     = count(array_filter($sites, fn($s) => ($s['status'] ?? 'active') === 'active'));
$suspended_sites  = count(array_filter($sites, fn($s) => ($s['status'] ?? '') === 'suspended'));
$trial_sites      = count(array_filter($sites, fn($s) => ($s['status'] ?? '') === 'trial'));

// Per-site stats — questions today and KB count
$site_stats = [];
foreach ($sites as $site) {
    switch_to_blog($site['blog_id']);
    global $wpdb;

    $questions_today = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}cleversay_questions
         WHERE DATE(created_at) = CURDATE()"
    );
    $questions_total = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}cleversay_questions"
    );
    $kb_count = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}cleversay_knowledge WHERE status = 'active'"
    );

    // AI usage this month
    $month     = date('Y-m');
    $ai_usage  = get_option('cleversay_ai_usage_' . $month, [
        'calls'        => 0,
        'cost'         => 0.0,
        'cache_create' => 0,
        'cache_read'   => 0,
        'cache_calls'  => 0,
        'cache_hits'   => 0,
    ]);

    $site_stats[$site['blog_id']] = [
        'questions_today' => $questions_today,
        'questions_total' => $questions_total,
        'kb_count'        => $kb_count,
        'ai_calls'        => (int)   ($ai_usage['calls'] ?? 0),
        'ai_cost'         => (float) ($ai_usage['cost']  ?? 0.0),
        'cache_read'      => (int)   ($ai_usage['cache_read']  ?? 0),
        'cache_create'    => (int)   ($ai_usage['cache_create'] ?? 0),
        'cache_calls'     => (int)   ($ai_usage['cache_calls']  ?? 0),
        'cache_hits'      => (int)   ($ai_usage['cache_hits']   ?? 0),
        'last_updated'    => $site['last_updated'],
    ];

    restore_current_blog();
}

$network_ai  = \CleverSay\NetworkSettings::get_ai();
$api_key_set = !empty($network_ai['api_key']);
$ai_enabled  = !empty($network_ai['ai_enabled']);
?>
<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline">
        <?php echo \CleverSay\Icons::render('message-circle', 28); ?>
        <?php esc_html_e('CleverSay Network Dashboard', 'cleversay'); ?>
    </h1>
    <hr class="wp-header-end">

    <?php if (!$api_key_set): ?>
    <div class="notice notice-warning">
        <p>
            <?php esc_html_e('No Anthropic API key configured.', 'cleversay'); ?>
            <a href="<?php echo esc_url(network_admin_url('admin.php?page=cleversay-network-ai')); ?>">
                <?php esc_html_e('Configure AI Settings →', 'cleversay'); ?>
            </a>
        </p>
    </div>
    <?php endif; ?>

    <!-- Summary Stats -->
    <div class="cleversay-stats-row" style="margin-bottom:24px;">
        <div class="cleversay-stat-card">
            <div class="stat-value"><?php echo esc_html($total_sites); ?></div>
            <div class="stat-label"><?php esc_html_e('Total Sites', 'cleversay'); ?></div>
        </div>
        <div class="cleversay-stat-card">
            <div class="stat-value"><?php echo esc_html($active_sites); ?></div>
            <div class="stat-label"><?php esc_html_e('Active', 'cleversay'); ?></div>
        </div>
        <div class="cleversay-stat-card">
            <div class="stat-value"><?php echo esc_html($trial_sites); ?></div>
            <div class="stat-label"><?php esc_html_e('Trial', 'cleversay'); ?></div>
        </div>
        <div class="cleversay-stat-card">
            <div class="stat-value"><?php echo esc_html($suspended_sites); ?></div>
            <div class="stat-label"><?php esc_html_e('Suspended', 'cleversay'); ?></div>
        </div>
        <div class="cleversay-stat-card">
            <div class="stat-value">
                <?php
                $total_questions_today = array_sum(array_column($site_stats, 'questions_today'));
                echo esc_html(number_format($total_questions_today));
                ?>
            </div>
            <div class="stat-label"><?php esc_html_e('Questions Today', 'cleversay'); ?></div>
        </div>
        <div class="cleversay-stat-card">
            <div class="stat-value">
                <?php
                $total_questions_all = array_sum(array_column($site_stats, 'questions_total'));
                echo esc_html(number_format($total_questions_all));
                ?>
            </div>
            <div class="stat-label"><?php esc_html_e('All Questions', 'cleversay'); ?></div>
        </div>
        <div class="cleversay-stat-card">
            <div class="stat-value">
                <?php
                $total_ai_cost = array_sum(array_column($site_stats, 'ai_cost'));
                echo '$' . esc_html(number_format($total_ai_cost, 2));
                ?>
            </div>
            <div class="stat-label"><?php esc_html_e('AI Cost This Month', 'cleversay'); ?></div>
        </div>
        <div class="cleversay-stat-card">
            <div class="stat-value">
                <?php
                // Cache savings = cache_read_tokens × 0.9 × $3/MTok (Sonnet input rate, the
                // realistic case where caching is active). Haiku is below the cache threshold
                // so cache_read should be 0 there. This is an honest estimate, not exact —
                // it assumes Sonnet pricing.
                $total_cache_read = array_sum(array_column($site_stats, 'cache_read'));
                $cache_savings    = $total_cache_read / 1_000_000 * 3.00 * 0.9;
                echo '$' . esc_html(number_format($cache_savings, 2));
                ?>
            </div>
            <div class="stat-label">
                <?php esc_html_e('Cache Savings (mo)', 'cleversay'); ?>
                <span title="<?php esc_attr_e('Estimated dollars saved by prompt caching this month, based on Sonnet input pricing. Caching is silently skipped on Haiku 4.5 because the cached prefix falls below its 4096-token minimum — switch to Sonnet to activate caching.', 'cleversay'); ?>"
                      style="cursor:help;color:#8c8f94;font-size:11px;">ⓘ</span>
            </div>
        </div>
    </div>

    <!-- Client Sites Table -->
    <div class="cleversay-table-card">
        <div class="tablenav top" style="padding:12px 16px;display:flex;align-items:center;justify-content:space-between;">
            <h3 style="margin:0;font-size:14px;font-weight:600;">
                <?php echo \CleverSay\Icons::render('globe', 16); ?>
                <?php esc_html_e('Client Sites', 'cleversay'); ?>
            </h3>
            <a href="<?php echo esc_url(network_admin_url('admin.php?page=cleversay-network-sites')); ?>"
               class="button button-primary">
                <?php esc_html_e('Manage Sites', 'cleversay'); ?>
            </a>
        </div>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Site', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Status', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Plan', 'cleversay'); ?></th>
                    <th><?php esc_html_e('KB Entries', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Questions Today', 'cleversay'); ?></th>
                    <th><?php esc_html_e('All Questions', 'cleversay'); ?></th>
                    <th><?php esc_html_e('AI Calls (mo)', 'cleversay'); ?></th>
                    <th><?php esc_html_e('AI Cost (mo)', 'cleversay'); ?></th>
                    <th title="<?php esc_attr_e('Percentage of AI calls that hit the prompt cache. 0% on Haiku is expected — the prefix is below Haiku\'s 4096-token caching threshold. Switch to Sonnet to activate caching.', 'cleversay'); ?>">
                        <?php esc_html_e('Cache Hit %', 'cleversay'); ?>
                    </th>
                    <th class="column-actions"><?php esc_html_e('Actions', 'cleversay'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sites)): ?>
                <tr>
                    <td colspan="10" style="text-align:center;padding:24px;color:#86868b;">
                        <?php esc_html_e('No client sites yet. Create subsites in Network Admin → Sites.', 'cleversay'); ?>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($sites as $site):
                    $stats        = $site_stats[$site['blog_id']] ?? [];
                    $status       = $site['status'] ?? 'active';
                    $status_colors = [
                        'active'    => '#34c759',
                        'trial'     => '#ff9f0a',
                        'suspended' => '#ff3b30',
                    ];
                    $status_color = $status_colors[$status] ?? '#86868b';
                    $admin_url    = get_admin_url($site['blog_id']);

                    // Cap calculations
                    $ai_calls      = (int)   ($stats['ai_calls'] ?? 0);
                    $ai_cost       = (float) ($stats['ai_cost']  ?? 0.0);
                    $call_limit    = (int)   ($site['ai_calls_monthly']  ?? 0);
                    $budget_limit  = (float) ($site['ai_budget_monthly'] ?? 0);
                    $calls_pct     = ($call_limit > 0)   ? min(100, round($ai_calls / $call_limit * 100)) : 0;
                    $budget_pct    = ($budget_limit > 0) ? min(100, round($ai_cost  / $budget_limit * 100)) : 0;
                    $calls_color   = $calls_pct  >= 100 ? '#ff3b30' : ($calls_pct  >= 80 ? '#ff9f0a' : '#34c759');
                    $budget_color  = $budget_pct >= 100 ? '#ff3b30' : ($budget_pct >= 80 ? '#ff9f0a' : '#34c759');
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($site['client_name'] ?: $site['domain']); ?></strong>
                        <?php
                        // Strip the main network domain to show just the subdomain slug.
                        // e.g. "uwo-adm.jasa-server.com" → "uwo-adm"
                        $subdomain = $site['domain'];
                        if (defined('DOMAIN_CURRENT_SITE')) {
                            $subdomain = preg_replace('/\.' . preg_quote(DOMAIN_CURRENT_SITE, '/') . '$/', '', $subdomain);
                        }
                        ?>
                        <br><small style="color:#86868b;"><?php echo esc_html($subdomain); ?></small>
                    </td>
                    <td>
                        <span style="display:inline-block;padding:2px 8px;border-radius:20px;
                                     background:<?php echo esc_attr($status_color); ?>20;
                                     color:<?php echo esc_attr($status_color); ?>;
                                     font-size:12px;font-weight:600;">
                            <?php echo esc_html(ucfirst($status)); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html(ucfirst($site['plan'] ?? 'basic')); ?></td>
                    <td><?php echo esc_html($stats['kb_count'] ?? 0); ?></td>
                    <td><?php echo esc_html(number_format($stats['questions_today'] ?? 0)); ?></td>
                    <td><strong><?php echo esc_html(number_format($stats['questions_total'] ?? 0)); ?></strong></td>
                    <td>
                        <?php if ($call_limit > 0): ?>
                        <span style="color:<?php echo esc_attr($calls_color); ?>;font-weight:600;">
                            <?php echo esc_html($ai_calls); ?>/<?php echo esc_html($call_limit); ?>
                        </span>
                        <span style="font-size:11px;color:#86868b;">(<?php echo esc_html($calls_pct); ?>%)</span>
                        <?php else: ?>
                        <?php echo esc_html($ai_calls); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($budget_limit > 0): ?>
                        <span style="color:<?php echo esc_attr($budget_color); ?>;font-weight:600;">
                            $<?php echo esc_html(number_format($ai_cost, 2)); ?>/$<?php echo esc_html(number_format($budget_limit, 0)); ?>
                        </span>
                        <span style="font-size:11px;color:#86868b;">(<?php echo esc_html($budget_pct); ?>%)</span>
                        <?php else: ?>
                        $<?php echo esc_html(number_format($ai_cost, 2)); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $cache_calls = (int) ($stats['cache_calls'] ?? 0);
                        $cache_hits  = (int) ($stats['cache_hits']  ?? 0);
                        if ($cache_calls > 0) {
                            $hit_pct = round($cache_hits / $cache_calls * 100);
                            $color   = $hit_pct >= 50 ? '#00a32a' : ($hit_pct > 0 ? '#dba617' : '#646970');
                            ?>
                            <span style="color:<?php echo esc_attr($color); ?>;font-weight:600;"><?php echo esc_html($hit_pct); ?>%</span>
                            <span style="font-size:11px;color:#86868b;">(<?php echo esc_html($cache_hits); ?>/<?php echo esc_html($cache_calls); ?>)</span>
                            <?php
                        } else {
                            ?>
                            <span style="color:#aaa;font-size:12px;">—</span>
                            <?php
                        }
                        ?>
                    </td>
                    <td class="column-actions">
                        <a href="<?php echo esc_url($admin_url . 'admin.php?page=cleversay'); ?>"
                           target="_blank" class="button button-small">
                            <?php esc_html_e('Open Admin', 'cleversay'); ?>
                        </a>
                        <a href="<?php echo esc_url(network_admin_url('admin.php?page=cleversay-network-sites&edit=' . $site['blog_id'])); ?>"
                           class="button button-small">
                            <?php esc_html_e('Edit Plan', 'cleversay'); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Quick Links -->
    <?php
    // Find staging blog ID
    $staging_blog_id = 0;
    foreach (get_sites(['number' => 100]) as $s) {
        if (str_starts_with($s->domain, 'staging.')) {
            $staging_blog_id = (int) $s->blog_id;
            break;
        }
    }
    ?>
    <div style="margin-top:24px;display:flex;gap:12px;flex-wrap:wrap;">
        <?php if ($staging_blog_id): ?>
        <a href="<?php echo esc_url(get_admin_url($staging_blog_id, 'admin.php?page=cleversay')); ?>"
           class="button button-primary">
            <?php echo \CleverSay\Icons::render('shield', 14); ?>
            <?php esc_html_e('Open Staging', 'cleversay'); ?>
        </a>
        <a href="<?php echo esc_url(network_admin_url('admin.php?page=cleversay-network-updates')); ?>"
           class="button">
            <?php echo \CleverSay\Icons::render('refresh-cw', 14); ?>
            <?php esc_html_e('Updates & Snapshots', 'cleversay'); ?>
        </a>
        <?php endif; ?>
        <a href="<?php echo esc_url(network_admin_url('admin.php?page=cleversay-network-ai')); ?>"
           class="button">
            <?php echo \CleverSay\Icons::render('sparkles', 14); ?>
            <?php esc_html_e('AI Settings', 'cleversay'); ?>
        </a>
        <a href="<?php echo esc_url(network_admin_url('admin.php?page=cleversay-network-advanced')); ?>"
           class="button">
            <?php echo \CleverSay\Icons::render('sliders', 14); ?>
            <?php esc_html_e('Advanced Settings', 'cleversay'); ?>
        </a>
        <a href="<?php echo esc_url(network_admin_url('sites.php')); ?>"
           class="button">
            <?php echo \CleverSay\Icons::render('globe', 14); ?>
            <?php esc_html_e('Manage WordPress Sites', 'cleversay'); ?>
        </a>
        <a href="<?php echo esc_url(network_admin_url('plugins.php')); ?>"
           class="button">
            <?php echo \CleverSay\Icons::render('package', 14); ?>
            <?php esc_html_e('Network Plugins', 'cleversay'); ?>
        </a>
        <a href="<?php echo esc_url(network_admin_url('settings.php')); ?>"
           class="button">
            <?php echo \CleverSay\Icons::render('settings', 14); ?>
            <?php esc_html_e('Network Settings', 'cleversay'); ?>
        </a>
    </div>
</div>
