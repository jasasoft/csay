<?php
/**
 * AI Knowledge Sources Admin View
 *
 * @package CleverSay
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

use CleverSay\Sources;
use CleverSay\AI;

$sources_obj = new Sources();
$ai          = new AI();
$all_sources = $sources_obj->get_all();
$usage       = $ai->get_monthly_usage();
$ai_cfg      = \CleverSay\NetworkSettings::get_ai_config();
$budget      = (float) ($ai_cfg['monthly_budget'] ?? get_option('cleversay_ai_monthly_budget', 0));
$ai_enabled  = \CleverSay\NetworkSettings::ai_is_configured();
$api_key_set = !empty($ai_cfg['api_key']);

$status_labels = [
    'pending'  => ['label' => 'Pending',  'class' => 'status-hold'],
    'indexing' => ['label' => 'Indexing', 'class' => 'status-draft'],
    'indexed'  => ['label' => 'Indexed',  'class' => 'status-active'],
    'error'    => ['label' => 'Error',    'class' => 'status-inactive'],
];

// ── Optional: per-source usage stats ──────────────────────────────────────
// Only queried if the admin has enabled source-usage tracking. Otherwise
// the columns render "—" and no extra DB work happens.
$track_usage_enabled = (bool) get_option('cleversay_track_source_usage', false);
$usage_stats         = [];   // source_id => ['retrievals' => N, 'helpful' => N, 'somewhat' => N, 'not_helpful' => N]
if ($track_usage_enabled) {
    global $wpdb;
    $usage_table  = $wpdb->prefix . 'cleversay_source_usage';
    $rating_table = $wpdb->prefix . 'cleversay_conversation_ratings';
    // Last 30 days, grouped by source
    $cutoff = date('Y-m-d H:i:s', strtotime('-30 days', current_time('timestamp')));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT u.source_id,
                COUNT(DISTINCT u.ai_answer_id) AS retrievals,
                SUM(CASE WHEN r.rating = 'helpful'     THEN 1 ELSE 0 END) AS helpful,
                SUM(CASE WHEN r.rating = 'somewhat'    THEN 1 ELSE 0 END) AS somewhat,
                SUM(CASE WHEN r.rating = 'not_helpful' THEN 1 ELSE 0 END) AS not_helpful
         FROM {$usage_table} u
         LEFT JOIN {$rating_table} r
           ON r.conversation_id = u.conversation_id
          AND r.conversation_id IS NOT NULL
         WHERE u.created_at >= %s
         GROUP BY u.source_id",
        $cutoff
    ), ARRAY_A) ?: [];
    foreach ($rows as $r) {
        $usage_stats[(int) $r['source_id']] = [
            'retrievals'  => (int) $r['retrievals'],
            'helpful'     => (int) $r['helpful'],
            'somewhat'    => (int) $r['somewhat'],
            'not_helpful' => (int) $r['not_helpful'],
        ];
    }
}
?>
<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline"><?php echo \CleverSay\Icons::render('sparkles', 16); ?> <?php esc_html_e('AI Knowledge Sources', 'cleversay'); ?></h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-settings#ai-settings')); ?>" class="page-title-action">
        <?php esc_html_e('AI Settings', 'cleversay'); ?>
    </a>
    <hr class="wp-header-end">

    <?php if (!$api_key_set || !$ai_enabled): ?>
    <div class="notice notice-warning">
        <p>
            <strong><?php esc_html_e('AI features are not active.', 'cleversay'); ?></strong>
            <?php esc_html_e('Add your Anthropic API key and enable AI in', 'cleversay'); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-settings#ai-settings')); ?>">
                <?php esc_html_e('AI Settings', 'cleversay'); ?>
            </a>.
            <?php esc_html_e('You can still add sources now and they will be ready when AI is enabled.', 'cleversay'); ?>
        </p>
    </div>
    <?php endif; ?>

    <!-- Usage Stats -->
    <div class="cleversay-stats-row" style="grid-template-columns: repeat(4,1fr); margin-bottom:20px;">
        <div class="stat-card">
            <div class="stat-icon" style="background:#e8f4fd;color:#0073aa;">
                <?php echo \CleverSay\Icons::render('file-text', 16); ?>
            </div>
            <div class="stat-info">
                <span class="stat-number"><?php echo count($all_sources); ?></span>
                <span class="stat-label"><?php esc_html_e('Sources', 'cleversay'); ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#e8f8ee;color:#00a32a;">
                <?php echo \CleverSay\Icons::render('sparkles', 16); ?>
            </div>
            <div class="stat-info">
                <span class="stat-number"><?php echo count(array_filter($all_sources, fn($s) => $s['status'] === 'indexed')); ?></span>
                <span class="stat-label"><?php esc_html_e('Indexed', 'cleversay'); ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef9e7;color:#dba617;">
                <?php echo \CleverSay\Icons::render('bar-chart', 16); ?>
            </div>
            <div class="stat-info">
                <span class="stat-number"><?php echo number_format($usage['calls'] ?? 0); ?></span>
                <span class="stat-label"><?php esc_html_e('AI calls this month', 'cleversay'); ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fce4e4;color:#d63638;">
                <?php echo \CleverSay\Icons::render('briefcase', 16); ?>
            </div>
            <div class="stat-info">
                <span class="stat-number">$<?php echo number_format($usage['cost'] ?? 0, 4); ?></span>
                <span class="stat-label">
                    <?php
                    if ($budget > 0) {
                        printf(
                            esc_html__('Cost this month (budget: $%s)', 'cleversay'),
                            number_format($budget, 2)
                        );
                    } else {
                        esc_html_e('Cost this month', 'cleversay');
                    }
                    ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Add Source Panels -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:20px;margin-bottom:25px;">

        <!-- Add URL -->
        <div class="postbox">
            <div class="postbox-header"><h2 class="hndle"><?php echo \CleverSay\Icons::render('link', 16); ?> <?php esc_html_e('Add URL', 'cleversay'); ?></h2></div>
            <div class="inside">
                <p class="description"><?php esc_html_e('Fetch and index a web page. The page text will be extracted automatically.', 'cleversay'); ?></p>
                <div id="cs-add-url-form">
                    <input type="url" id="cs-source-url" class="large-text" placeholder="https://example.com/faq" style="margin-bottom:8px;">
                    <input type="text" id="cs-source-url-title" class="large-text" placeholder="<?php esc_attr_e('Title (optional)', 'cleversay'); ?>" style="margin-bottom:10px;">
                    <button type="button" class="button button-primary" id="cs-btn-add-url">
                        <?php echo \CleverSay\Icons::render('plus', 16); ?>
                        <?php esc_html_e('Fetch &amp; Index', 'cleversay'); ?>
                    </button>
                    <span class="cs-spinner" style="display:none;margin-left:8px;"><span class="spinner is-active" style="float:none;"></span></span>
                </div>
            </div>
        </div>

        <!-- Crawl Website -->
        <div class="postbox">
            <div class="postbox-header"><h2 class="hndle"><?php echo \CleverSay\Icons::render('globe', 16); ?> <?php esc_html_e('Crawl Website', 'cleversay'); ?></h2></div>
            <div class="inside">
                <p class="description"><?php esc_html_e('Follow links from a starting URL and index multiple pages at once.', 'cleversay'); ?></p>
                <div id="cs-crawl-form">
                    <input type="url" id="cs-crawl-start-url" class="large-text"
                           placeholder="https://example.com/admissions"
                           style="margin-bottom:8px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:8px;">
                        <div>
                            <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:3px;">
                                <?php esc_html_e('Depth', 'cleversay'); ?>
                                <span title="<?php esc_attr_e('1 = start page only, 2 = follow links one level deep, etc.', 'cleversay'); ?>" style="cursor:help;color:#aaa;">&#9432;</span>
                            </label>
                            <select id="cs-crawl-depth" class="widefat">
                                <option value="1">1 — <?php esc_html_e('Start page only (ignores max pages)', 'cleversay'); ?></option>
                                <option value="2" selected>2 — <?php esc_html_e('Follow links once', 'cleversay'); ?></option>
                                <option value="3">3 — <?php esc_html_e('Two levels deep', 'cleversay'); ?></option>
                                <option value="4">4 — <?php esc_html_e('Three levels deep', 'cleversay'); ?></option>
                                <option value="5">5 — <?php esc_html_e('Full crawl', 'cleversay'); ?></option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:3px;">
                                <?php esc_html_e('Max pages', 'cleversay'); ?>
                            </label>
                            <select id="cs-crawl-max-pages" class="widefat">
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50" selected>50</option>
                                <option value="100">100</option>
                                <option value="200">200</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:3px;"
                                   title="<?php esc_attr_e('Pause between requests. Bigger delay = slower but less likely to be blocked by site security (Cloudflare, Sucuri, etc.)', 'cleversay'); ?>">
                                <?php esc_html_e('Delay (s)', 'cleversay'); ?>
                            </label>
                            <select id="cs-crawl-delay" class="widefat">
                                <option value="0"><?php esc_html_e('None', 'cleversay'); ?></option>
                                <option value="1">1</option>
                                <option value="2" selected>2</option>
                                <option value="3">3</option>
                                <option value="5">5</option>
                            </select>
                        </div>
                    </div>
                    <input type="text" id="cs-crawl-restrict-path" class="large-text"
                           placeholder="<?php esc_attr_e('/admissions-aid/  (optional path restriction)', 'cleversay'); ?>"
                           style="margin-bottom:8px;">
                    <label style="display:flex;align-items:center;gap:6px;margin-bottom:6px;font-size:13px;cursor:pointer;">
                        <input type="checkbox" id="cs-crawl-main-only" checked>
                        <?php esc_html_e('Only follow links inside the main content area', 'cleversay'); ?>
                        <span style="color:#aaa;font-size:12px;"><?php esc_html_e('(skips header / nav / footer / sidebar)', 'cleversay'); ?></span>
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;margin-bottom:10px;font-size:13px;cursor:pointer;">
                        <input type="checkbox" id="cs-crawl-skip-indexed" checked>
                        <?php esc_html_e('Skip pages already successfully indexed (errors are always retried)', 'cleversay'); ?>
                        <span style="color:#aaa;font-size:12px;"><?php esc_html_e('(uncheck to refresh existing content)', 'cleversay'); ?></span>
                    </label>
                    <button type="button" class="button button-primary" id="cs-btn-crawl">
                        <?php echo \CleverSay\Icons::render('search', 16); ?>
                        <?php esc_html_e('Discover &amp; Index', 'cleversay'); ?>
                    </button>
                </div>

                <!-- Crawl progress (hidden until crawl starts) -->
                <div id="cs-crawl-progress" style="display:none;margin-top:12px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                        <span class="spinner is-active" id="cs-crawl-spinner" style="float:none;margin:0;"></span>
                        <div id="cs-crawl-status" style="font-size:13px;color:#2271b1;font-weight:600;flex:1;"></div>
                        <div id="cs-crawl-elapsed" style="font-size:11px;color:#aaa;"></div>
                    </div>
                    <div style="background:#e5e5e5;border-radius:4px;overflow:hidden;height:12px;margin-bottom:8px;">
                        <div id="cs-crawl-bar" style="height:100%;width:0%;background:#2271b1;transition:width .3s;"></div>
                    </div>
                    <div id="cs-crawl-log" style="font-size:11px;color:#666;max-height:120px;overflow-y:auto;border:1px solid #ddd;padding:6px;border-radius:4px;background:#fafafa;"></div>
                </div>
            </div>
        </div>

        <!-- Upload File -->
        <div class="postbox">
            <div class="postbox-header"><h2 class="hndle"><?php echo \CleverSay\Icons::render('upload', 16); ?> <?php esc_html_e('Upload File', 'cleversay'); ?></h2></div>
            <div class="inside">
                <p class="description"><?php esc_html_e('Upload a PDF, Word document (.docx), or plain text file.', 'cleversay'); ?></p>
                <div id="cs-add-file-form">
                    <input type="file" id="cs-source-file" accept=".pdf,.txt,.docx" style="margin-bottom:10px;display:block;">
                    <button type="button" class="button button-primary" id="cs-btn-upload-file">
                        <?php echo \CleverSay\Icons::render('cloud-upload', 16); ?>
                        <?php esc_html_e('Upload &amp; Index', 'cleversay'); ?>
                    </button>
                    <span class="cs-spinner" style="display:none;margin-left:8px;"><span class="spinner is-active" style="float:none;"></span></span>
                </div>
            </div>
        </div>

        <!-- Add Text -->
        <div class="postbox">
            <div class="postbox-header"><h2 class="hndle"><?php echo \CleverSay\Icons::render('file-text', 16); ?> <?php esc_html_e('Paste Text', 'cleversay'); ?></h2></div>
            <div class="inside">
                <p class="description"><?php esc_html_e('Paste any text content directly — meeting notes, policy excerpts, etc.', 'cleversay'); ?></p>
                <div id="cs-add-text-form">
                    <input type="text" id="cs-source-text-title" class="large-text" placeholder="<?php esc_attr_e('Title (required)', 'cleversay'); ?>" style="margin-bottom:8px;">
                    <textarea id="cs-source-text" rows="5" class="large-text" placeholder="<?php esc_attr_e('Paste your content here...', 'cleversay'); ?>" style="margin-bottom:10px;"></textarea>
                    <button type="button" class="button button-primary" id="cs-btn-add-text">
                        <?php echo \CleverSay\Icons::render('plus', 16); ?>
                        <?php esc_html_e('Add &amp; Index', 'cleversay'); ?>
                    </button>
                    <span class="cs-spinner" style="display:none;margin-left:8px;"><span class="spinner is-active" style="float:none;"></span></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Test Extraction (diagnostic) ─────────────────────────────────── -->
    <details class="postbox" style="margin-bottom:20px;">
        <summary style="padding:10px 14px;cursor:pointer;font-weight:600;font-size:13px;list-style:none;">
            <?php echo \CleverSay\Icons::render('settings', 14); ?>
            <?php esc_html_e('Test extraction (diagnostic)', 'cleversay'); ?>
            <span style="font-weight:400;color:#646970;font-size:12px;margin-left:6px;">
                — <?php esc_html_e('see what the indexer extracts from a URL before adding it', 'cleversay'); ?>
            </span>
        </summary>
        <div class="inside" style="padding:14px;">
            <p class="description" style="margin-top:0;font-size:12px;">
                <?php esc_html_e('Useful when a URL imports as 0 words. Paste it here to see what the indexer actually receives and which content selector matched.', 'cleversay'); ?>
            </p>
            <div style="display:flex;gap:8px;align-items:flex-start;margin-bottom:14px;">
                <input type="url" id="cs-diag-url" class="large-text"
                       placeholder="https://example.com/page" style="flex:1;">
                <button type="button" class="button button-primary" id="cs-btn-diagnose">
                    <?php esc_html_e('Run Diagnostic', 'cleversay'); ?>
                </button>
            </div>
            <div id="cs-diag-results" style="display:none;"></div>
        </div>
    </details>

    <!-- Sources Table -->
    <div class="postbox">
        <div class="postbox-header" style="display:flex;align-items:center;gap:12px;">
            <h2 class="hndle" style="flex:1;"><?php esc_html_e('Source Library', 'cleversay'); ?></h2>
            <span style="font-size:12px;color:#646970;font-weight:400;padding-right:12px;">
                <?php echo \CleverSay\Icons::render('info', 12); ?>
                <?php esc_html_e('New URL sources auto-refresh twice a month by default.', 'cleversay'); ?>
            </span>
        </div>
        <div class="inside" style="padding:0;">
            <?php if (empty($all_sources)): ?>
                <p style="padding:20px;text-align:center;color:#666;"><?php esc_html_e('No sources added yet. Add a URL, upload a file, or paste text above.', 'cleversay'); ?></p>
            <?php else: ?>

            <!-- Bulk action bar -->
            <div id="cs-bulk-bar" style="display:none;align-items:center;gap:10px;padding:8px 12px;background:#f6f7f7;border-bottom:1px solid #dcdcde;">
                <span id="cs-bulk-count" style="font-size:13px;color:#646970;"></span>
                <button type="button" id="cs-bulk-delete-btn" class="button button-small" style="color:#d63638;border-color:#d63638;">
                    <?php echo \CleverSay\Icons::render('trash', 16); ?>
                    <?php esc_html_e('Delete Selected', 'cleversay'); ?>
                </button>
                <button type="button" id="cs-bulk-cancel-btn" class="button button-small">
                    <?php esc_html_e('Cancel', 'cleversay'); ?>
                </button>
            </div>

            <table class="widefat striped" id="cs-sources-table">
                <thead>
                    <tr>
                        <th style="width:32px;"><input type="checkbox" id="cs-select-all" title="<?php esc_attr_e('Select all', 'cleversay'); ?>"></th>
                        <th><?php esc_html_e('Source', 'cleversay'); ?></th>
                        <?php if ($track_usage_enabled): ?>
                            <th title="<?php esc_attr_e('Number of AI answers that used this source (last 30 days)', 'cleversay'); ?>" style="width:100px;">
                                <?php esc_html_e('Retrievals', 'cleversay'); ?>
                            </th>
                            <th title="<?php esc_attr_e('Ratings from conversations where this source was used', 'cleversay'); ?>" style="width:180px;">
                                <?php esc_html_e('Rated', 'cleversay'); ?>
                            </th>
                        <?php endif; ?>
                        <th style="width:160px;"><?php esc_html_e('Refresh', 'cleversay'); ?></th>
                        <th class="column-actions" style="width:100px;"><?php esc_html_e('Actions', 'cleversay'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($all_sources as $source): ?>
                    <?php
                    $st = $status_labels[$source['status']] ?? ['label' => $source['status'], 'class' => ''];
                    ?>
                    <tr id="cs-source-row-<?php echo (int) $source['id']; ?>">
                        <td><input type="checkbox" class="cs-source-cb" value="<?php echo (int) $source['id']; ?>"></td>
                        <td>
                            <div style="font-weight:600;font-size:14px;color:#1d2327;">
                                <?php echo esc_html($source['title']); ?>
                            </div>
                            <?php if (!empty($source['url'])): ?>
                                <div style="margin-top:3px;font-size:12px;">
                                    <a href="<?php echo esc_url($source['url']); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html(substr($source['url'], 0, 80)); ?>
                                        <?php echo strlen($source['url']) > 80 ? '…' : ''; ?>
                                    </a>
                                </div>
                            <?php elseif (!empty($source['file_name'])): ?>
                                <div style="margin-top:3px;font-size:12px;color:#50575e;">
                                    <?php echo esc_html($source['file_name']); ?>
                                </div>
                            <?php endif; ?>
                            <!-- Meta line: Added • Type • Status • Chunks • Words -->
                            <div style="margin-top:6px;font-size:12px;color:#646970;line-height:1.6;">
                                <span><?php esc_html_e('Added:', 'cleversay'); ?>
                                    <strong style="color:#1d2327;font-weight:500;">
                                        <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($source['created_at']))); ?>
                                    </strong>
                                </span>
                                <span style="margin:0 8px;color:#c3c4c7;">|</span>
                                <span><?php esc_html_e('Type:', 'cleversay'); ?>
                                    <strong style="color:#1d2327;font-weight:500;"><?php echo esc_html(strtoupper($source['source_type'])); ?></strong>
                                </span>
                                <span style="margin:0 8px;color:#c3c4c7;">|</span>
                                <span><?php esc_html_e('Status:', 'cleversay'); ?>
                                    <span id="cs-status-<?php echo (int) $source['id']; ?>">
                                        <strong class="cleversay-status <?php echo esc_attr($st['class']); ?>" style="padding:1px 8px;font-size:11px;"><?php echo esc_html($st['label']); ?></strong>
                                    </span>
                                </span>
                                <span style="margin:0 8px;color:#c3c4c7;">|</span>
                                <span><?php esc_html_e('Chunks:', 'cleversay'); ?>
                                    <strong id="cs-chunks-<?php echo (int) $source['id']; ?>" style="color:#1d2327;font-weight:500;"><?php echo (int) $source['chunk_count']; ?></strong>
                                </span>
                                <span style="margin:0 8px;color:#c3c4c7;">|</span>
                                <span><?php esc_html_e('Words:', 'cleversay'); ?>
                                    <strong id="cs-words-<?php echo (int) $source['id']; ?>" style="color:#1d2327;font-weight:500;"><?php echo number_format((int) $source['word_count']); ?></strong>
                                </span>
                            </div>
                            <?php if (!empty($source['error_message'])): ?>
                                <div class="cs-source-error" style="margin-top:4px;font-size:12px;color:#d63638;">
                                    <?php echo esc_html($source['error_message']); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <?php if ($track_usage_enabled):
                            $u = $usage_stats[(int) $source['id']] ?? null;
                            $retrievals = $u['retrievals'] ?? 0;
                            $rated_total = ($u['helpful'] ?? 0) + ($u['somewhat'] ?? 0) + ($u['not_helpful'] ?? 0);
                            $effectiveness = $rated_total > 0
                                ? round((($u['helpful'] ?? 0) / $rated_total) * 100)
                                : null;
                        ?>
                            <td style="font-size:13px;">
                                <?php if ($retrievals > 0): ?>
                                    <strong><?php echo number_format_i18n($retrievals); ?></strong>
                                    <div style="font-size:11px;color:#8c8f94;"><?php esc_html_e('30 days', 'cleversay'); ?></div>
                                <?php else: ?>
                                    <span style="color:#8c8f94;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:13px;">
                                <?php if ($rated_total > 0): ?>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <span style="font-weight:600;color:<?php echo $effectiveness >= 70 ? '#166534' : ($effectiveness >= 40 ? '#B45309' : '#991B1B'); ?>;">
                                            <?php echo esc_html($effectiveness); ?>%
                                        </span>
                                        <span style="font-size:11px;color:#8c8f94;">
                                            👍<?php echo (int) $u['helpful']; ?>
                                            &nbsp;🤔<?php echo (int) $u['somewhat']; ?>
                                            &nbsp;👎<?php echo (int) $u['not_helpful']; ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span style="color:#8c8f94;font-size:12px;"><?php esc_html_e('no ratings', 'cleversay'); ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td>
                            <?php if ($source['source_type'] === 'url'): ?>
                                <?php
                                $ri = $source['refresh_interval'] ?? 'never';
                                $cs = $source['crawl_status']     ?? '';
                                $lc = $source['last_crawled_at']  ?? '';
                                ?>
                                <select class="cs-refresh-interval" data-id="<?php echo (int) $source['id']; ?>"
                                        style="max-width:140px;font-size:12px;padding:2px 24px 2px 6px;">
                                    <option value="never"         <?php selected($ri, 'never'); ?>><?php esc_html_e('Never', 'cleversay'); ?></option>
                                    <option value="daily"         <?php selected($ri, 'daily'); ?>><?php esc_html_e('Daily', 'cleversay'); ?></option>
                                    <option value="weekly"        <?php selected($ri, 'weekly'); ?>><?php esc_html_e('Weekly', 'cleversay'); ?></option>
                                    <option value="twice_monthly" <?php selected($ri, 'twice_monthly'); ?>><?php esc_html_e('Twice a month', 'cleversay'); ?></option>
                                    <option value="monthly"       <?php selected($ri, 'monthly'); ?>><?php esc_html_e('Monthly', 'cleversay'); ?></option>
                                </select>
                                <div id="cs-crawl-info-<?php echo (int) $source['id']; ?>" style="font-size:11px;color:#50575e;margin-top:3px;<?php echo $lc ? '' : 'display:none;'; ?>">
                                    <?php if ($lc): ?>
                                        <?php
                                        $diff = human_time_diff(strtotime($lc), current_time('timestamp'));
                                        /* translators: %s = human-readable time like "2 hours" */
                                        printf(esc_html__('crawled %s ago', 'cleversay'), esc_html($diff));
                                        ?>
                                        <?php if ($cs === 'changed'): ?>
                                            &nbsp;<span style="color:#B45309;font-weight:600;" title="<?php esc_attr_e('Content changed on last crawl', 'cleversay'); ?>">●&nbsp;<?php esc_html_e('changed', 'cleversay'); ?></span>
                                        <?php elseif ($cs === 'unchanged'): ?>
                                            &nbsp;<span style="color:#166534;" title="<?php esc_attr_e('No content changes detected', 'cleversay'); ?>">●</span>
                                        <?php elseif ($cs === 'rechunked'): ?>
                                            &nbsp;<span style="color:#1e6091;" title="<?php esc_attr_e('Manually re-indexed (content unchanged)', 'cleversay'); ?>">●&nbsp;<?php esc_html_e('re-indexed', 'cleversay'); ?></span>
                                        <?php elseif ($cs === 'error'): ?>
                                            &nbsp;<span style="color:#d63638;font-weight:600;" title="<?php echo esc_attr($source['crawl_error'] ?? __('Crawl error', 'cleversay')); ?>">●&nbsp;<?php esc_html_e('error', 'cleversay'); ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span style="color:#8c8f94;font-size:12px;"><?php esc_html_e('—', 'cleversay'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="column-actions">
                            <button type="button" class="button button-small cs-reindex-btn"
                                    data-id="<?php echo (int) $source['id']; ?>"
                                    title="<?php esc_attr_e('Re-index this source', 'cleversay'); ?>">
                                <?php echo \CleverSay\Icons::render('refresh-cw', 16); ?>
                            </button>
                            <button type="button" class="button button-small cs-delete-source-btn"
                                    data-id="<?php echo (int) $source['id']; ?>"
                                    title="<?php esc_attr_e('Delete this source', 'cleversay'); ?>"
                                    style="color:#d63638;border-color:#d63638;">
                                <?php echo \CleverSay\Icons::render('trash', 16); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</div>

<style>
.cleversay-admin-wrap .postbox-header {
    padding: 0 12px;
    border-bottom: 1px solid #dcdcde;
}
.cleversay-admin-wrap .postbox-header h2.hndle {
    padding: 12px 0;
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}
.cleversay-admin-wrap .postbox .inside {
    padding: 12px;
    margin: 0;
}
.cleversay-admin-wrap .postbox {
    margin-bottom: 0;
}
</style>

<script>
(function($) {
    const nonce  = '<?php echo esc_js(wp_create_nonce('cleversay_admin_nonce')); ?>';
    const ajaxurl_cs = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
    const trackUsage = <?php echo $track_usage_enabled ? 'true' : 'false'; ?>;

    function csRequest(action, data, $spinner, callback) {
        if ($spinner) $spinner.show();
        $.post(ajaxurl_cs, Object.assign({ action, nonce }, data))
            .done(r => callback(r))
            .fail(() => callback({ success: false, data: { message: 'Request failed' } }))
            .always(() => { if ($spinner) $spinner.hide(); });
    }

    function showNotice(msg, type) {
        type = type || 'success';
        const $n = $('<div class="notice notice-' + type + ' is-dismissible" style="margin:10px 0;"><p>' + $('<div>').text(msg).html() + '</p></div>');
        // Target hr.wp-header-end (standard WP structure) or fall back to h1
        const $anchor = $('hr.wp-header-end').first();
        if ($anchor.length) {
            $anchor.after($n);
        } else {
            $('h1.wp-heading-inline').first().closest('.wrap').prepend($n);
        }
        setTimeout(() => $n.fadeOut(400, function() { $(this).remove(); }), 5000);
    }

    function addRow(source) {
        const types = { pdf: 'PDF', url: 'URL', text: 'TEXT', docx: 'DOCX' };
        const today = '<?php echo esc_js(date_i18n(get_option('date_format'))); ?>';
        const refreshCell = source.source_type === 'url'
            ? '<select class="cs-refresh-interval" data-id="' + source.id + '" style="max-width:140px;font-size:12px;padding:2px 24px 2px 6px;"><option value="never">Never</option><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="twice_monthly" selected>Twice a month</option><option value="monthly">Monthly</option></select>'
            : '<span style="color:#8c8f94;font-size:12px;">—</span>';
        const linkHtml = source.url
            ? '<div style="margin-top:3px;font-size:12px;"><a href="' + escHtml(source.url) + '" target="_blank" rel="noopener">' + escHtml(source.url.substring(0,80)) + (source.url.length > 80 ? '…' : '') + '</a></div>'
            : '';
        // When source-usage tracking is on, the table has two extra columns
        // (Retrievals and Rated). New rows haven't been used yet, so they
        // show "—" placeholders. Without these cells, new rows misalign and
        // appear under the wrong columns.
        const usageCells = trackUsage
            ? '<td style="color:#8c8f94;font-size:12px;">—</td><td style="color:#8c8f94;font-size:12px;">—</td>'
            : '';

        const row = `<tr id="cs-source-row-${source.id}">
            <td><input type="checkbox" class="cs-source-cb" value="${source.id}"></td>
            <td>
                <div style="font-weight:600;font-size:14px;color:#1d2327;">${escHtml(source.title)}</div>
                ${linkHtml}
                <div style="margin-top:6px;font-size:12px;color:#646970;line-height:1.6;">
                    <span>Added: <strong style="color:#1d2327;font-weight:500;">${today}</strong></span>
                    <span style="margin:0 8px;color:#c3c4c7;">|</span>
                    <span>Type: <strong style="color:#1d2327;font-weight:500;">${types[source.source_type] || source.source_type}</strong></span>
                    <span style="margin:0 8px;color:#c3c4c7;">|</span>
                    <span>Status: <span id="cs-status-${source.id}"><strong class="cleversay-status status-hold" style="padding:1px 8px;font-size:11px;">Pending</strong></span></span>
                    <span style="margin:0 8px;color:#c3c4c7;">|</span>
                    <span>Chunks: <strong id="cs-chunks-${source.id}" style="color:#1d2327;font-weight:500;">0</strong></span>
                    <span style="margin:0 8px;color:#c3c4c7;">|</span>
                    <span>Words: <strong id="cs-words-${source.id}" style="color:#1d2327;font-weight:500;">0</strong></span>
                </div>
            </td>
            ${usageCells}
            <td>${refreshCell}</td>
            <td>
                <button type="button" class="button button-small cs-reindex-btn" data-id="${source.id}" title="Re-index">
                    <?php echo \CleverSay\Icons::render('refresh-cw', 16); ?>
                </button>
                <button type="button" class="button button-small cs-delete-source-btn" data-id="${source.id}"
                        style="color:#d63638;border-color:#d63638;" title="Delete">
                    <?php echo \CleverSay\Icons::render('trash', 16); ?>
                </button>
            </td>
        </tr>`;

        const $tbody = $('#cs-sources-table tbody');
        if (!$tbody.length) {
            // Replace empty state — match the column count to whatever the
            // current page is configured to show, so the first added row
            // doesn't end up shifted under the wrong headers.
            const usageHeaders = trackUsage
                ? '<th style="width:100px;">Retrievals</th><th style="width:180px;">Rated</th>'
                : '';
            $('#cs-sources-table').remove();
            const $table = $('<table class="widefat striped" id="cs-sources-table"><thead><tr>' +
                '<th style="width:32px;"><input type="checkbox" id="cs-select-all"></th>' +
                '<th>Source</th>' + usageHeaders +
                '<th>Refresh</th><th>Actions</th>' +
                '</tr></thead><tbody></tbody></table>');
            $('.postbox .inside').last().html('').append($table);
        }
        $('#cs-sources-table tbody').prepend(row);
        pollStatus(source.id);
    }

    function pollStatus(id) {
        const $status = $('#cs-status-' + id);
        const $chunks = $('#cs-chunks-' + id);
        const $words  = $('#cs-words-' + id);
        const $row    = $('#cs-source-row-' + id);
        let attempts  = 0;

        function check() {
            if (attempts++ > 30) return;
            csRequest('cleversay_get_source_status', { source_id: id }, null, function(r) {
                if (!r.success) return;
                const s = r.data;
                // Remove any previous inline error message
                $row.find('.cs-source-error').remove();

                if (s.status === 'indexed') {
                    $status.html('<strong class="cleversay-status status-active" style="padding:1px 8px;font-size:11px;">Indexed</strong>');
                    $chunks.text(s.chunk_count);
                    $words.text(parseInt(s.word_count).toLocaleString());
                    // Update crawled-ago + status dot in the Refresh column
                    updateCrawlInfo(id, s);
                } else if (s.status === 'error') {
                    $status.html('<strong class="cleversay-status status-inactive" style="padding:1px 8px;font-size:11px;">Error</strong>');
                    if (s.error) {
                        // Error message goes under the meta line in the Source cell
                        $row.find('.cs-source-error').remove();
                        $row.find('td').eq(1).append('<div class="cs-source-error" style="margin-top:4px;font-size:12px;color:#d63638;">' + escHtml(s.error) + '</div>');
                    }
                    updateCrawlInfo(id, s);
                } else if (s.status === 'indexing' || s.status === 'pending') {
                    $status.html('<strong class="cleversay-status status-hold" style="padding:1px 8px;font-size:11px;">Indexing…</strong>');
                    setTimeout(check, 1000);
                }
            });
        }
        setTimeout(check, 1500);
    }

    /**
     * Refresh the "crawled X ago" line + status dot in the Refresh column
     * after an ajax reindex, without a full page reload.
     */
    function updateCrawlInfo(id, s) {
        const $box = $('#cs-crawl-info-' + id);
        if (!$box.length || !s.last_crawled_at) return;

        // "crawled X ago" or "just crawled" if crawled_ago is empty
        const agoText = s.crawled_ago ? ('crawled ' + s.crawled_ago + ' ago') : 'just crawled';
        let dot = '';
        if (s.crawl_status === 'changed') {
            dot = ' &nbsp;<span style="color:#B45309;font-weight:600;" title="Content changed on last crawl">●&nbsp;changed</span>';
        } else if (s.crawl_status === 'unchanged') {
            dot = ' &nbsp;<span style="color:#166534;" title="No content changes detected">●</span>';
        } else if (s.crawl_status === 'rechunked') {
            dot = ' &nbsp;<span style="color:#1e6091;" title="Manually re-indexed (content unchanged)">●&nbsp;re-indexed</span>';
        } else if (s.crawl_status === 'error') {
            const errTitle = (s.crawl_error || 'Crawl error').replace(/"/g, '&quot;');
            dot = ' &nbsp;<span style="color:#d63638;font-weight:600;" title="' + errTitle + '">●&nbsp;error</span>';
        } else if (s.crawl_status === 'new') {
            dot = ' &nbsp;<span style="color:#2271b1;" title="First successful crawl">●</span>';
        }
        $box.html(escHtml(agoText) + dot).show();
    }

    // Add URL
    $('#cs-btn-add-url').on('click', function() {
        const url   = $('#cs-source-url').val().trim();
        const title = $('#cs-source-url-title').val().trim();
        if (!url) { showNotice('Please enter a URL.', 'error'); return; }
        csRequest('cleversay_add_source_url', { url, title }, $(this).next('.cs-spinner'), function(r) {
            if (r.success) {
                showNotice(r.data.message || 'URL added and indexing started.');
                $('#cs-source-url, #cs-source-url-title').val('');
                addRow(r.data.source);
            } else {
                showNotice(r.data?.message || 'Error adding URL.', 'error');
            }
        });
    });

    // Upload file
    $('#cs-btn-upload-file').on('click', function() {
        const file = document.getElementById('cs-source-file').files[0];
        if (!file) { showNotice('Please select a file.', 'error'); return; }

        const formData = new FormData();
        formData.append('action', 'cleversay_add_source_file');
        formData.append('nonce', nonce);
        formData.append('file', file);

        const $spinner = $(this).next('.cs-spinner');
        $spinner.show();
        $(this).prop('disabled', true);

        $.ajax({
            url:         ajaxurl_cs,
            type:        'POST',
            data:        formData,
            processData: false,
            contentType: false,
        }).done(r => {
            if (r.success) {
                showNotice(r.data.message || 'File uploaded and indexing started.');
                document.getElementById('cs-source-file').value = '';
                addRow(r.data.source);
            } else {
                // v4.42.30+: When the server reports an indexing failure
                // (file uploaded successfully but text extraction broke),
                // it sends back the source row alongside the error
                // message. Add the row to the table so the admin can
                // see the failed entry at the top with its error
                // indicator — not just a toast that disappears.
                showNotice(r.data?.message || 'Upload error.', 'error');
                if (r.data?.source) {
                    document.getElementById('cs-source-file').value = '';
                    addRow(r.data.source);
                }
            }
        }).always(() => {
            $spinner.hide();
            $(this).prop('disabled', false);
        });
    });

    // Add text
    $('#cs-btn-add-text').on('click', function() {
        const title   = $('#cs-source-text-title').val().trim();
        const content = $('#cs-source-text').val().trim();
        if (!title)   { showNotice('Please enter a title.', 'error'); return; }
        if (!content) { showNotice('Please enter some content.', 'error'); return; }
        csRequest('cleversay_add_source_text', { title, content }, $(this).next('.cs-spinner'), function(r) {
            if (r.success) {
                showNotice(r.data.message || 'Text added and indexing started.');
                $('#cs-source-text-title, #cs-source-text').val('');
                addRow(r.data.source);
            } else {
                showNotice(r.data?.message || 'Error adding text.', 'error');
            }
        });
    });

    // Re-index
    $(document).on('click', '.cs-reindex-btn', function() {
        const id = $(this).data('id');
        const $btn = $(this);
        $btn.prop('disabled', true);
        $('#cs-status-' + id).html('<span class="cleversay-status status-hold">Indexing…</span>');
        csRequest('cleversay_reindex_source', { source_id: id }, null, function(r) {
            if (r.success) {
                pollStatus(id);
            } else {
                showNotice(r.data?.message || 'Re-index failed.', 'error');
            }
            $btn.prop('disabled', false);
        });
    });

    // Change refresh interval
    $(document).on('change', '.cs-refresh-interval', function() {
        const id       = $(this).data('id');
        const interval = $(this).val();
        const $sel     = $(this);
        $sel.prop('disabled', true);
        csRequest('cleversay_set_source_refresh', { source_id: id, interval: interval }, null, function(r) {
            if (r.success) {
                showNotice('Refresh schedule updated.', 'success');
            } else {
                showNotice(r.data?.message || 'Could not save schedule.', 'error');
            }
            $sel.prop('disabled', false);
        });
    });

    // Delete
    $(document).on('click', '.cs-delete-source-btn', function() {
        if (!confirm('<?php echo esc_js(__('Delete this source and all its indexed content? This cannot be undone.', 'cleversay')); ?>')) return;
        const id   = $(this).data('id');
        const $row = $('#cs-source-row-' + id);
        csRequest('cleversay_delete_source', { source_id: id }, null, function(r) {
            if (r.success) {
                $row.fadeOut(300, function() { $(this).remove(); });
                showNotice('Source deleted.');
            } else {
                showNotice(r.data?.message || 'Delete failed.', 'error');
            }
        });
    });


    // ── Bulk select / delete ──────────────────────────────────────────────────
    function updateBulkBar() {
        const count = $('.cs-source-cb:checked').length;
        if (count > 0) {
            $('#cs-bulk-bar').css('display', 'flex');
            $('#cs-bulk-count').text(count + ' selected');
        } else {
            $('#cs-bulk-bar').hide();
        }
        $('#cs-select-all').prop('indeterminate',
            count > 0 && count < $('.cs-source-cb').length
        );
    }

    $('#cs-select-all').on('change', function() {
        $('.cs-source-cb').prop('checked', this.checked);
        updateBulkBar();
    });

    $(document).on('change', '.cs-source-cb', function() {
        const all   = $('.cs-source-cb').length;
        const checked = $('.cs-source-cb:checked').length;
        $('#cs-select-all').prop('checked', all === checked);
        updateBulkBar();
    });

    $('#cs-bulk-cancel-btn').on('click', function() {
        $('.cs-source-cb, #cs-select-all').prop('checked', false);
        updateBulkBar();
    });

    $('#cs-bulk-delete-btn').on('click', function() {
        const ids = $('.cs-source-cb:checked').map(function() { return this.value; }).get();
        if (!ids.length) return;
        if (!confirm('<?php echo esc_js(sprintf(__('Delete %s sources? This cannot be undone.', 'cleversay'), '" + ids.length + "' )); ?>')) return;
        const $btn = $(this).prop('disabled', true);
        $.post(ajaxurl_cs, {
            action: 'cleversay_bulk_delete_sources',
            nonce:  nonce,
            ids:    ids,
        }).done(function(r) {
            if (r.success) {
                ids.forEach(id => $('#cs-source-row-' + id).fadeOut(200, function() { $(this).remove(); }));
                showNotice(r.data.message);
                setTimeout(updateBulkBar, 300);
            } else {
                showNotice(r.data?.message || 'Bulk delete failed.', 'error');
            }
        }).always(function() { $btn.prop('disabled', false); });
    });

    // ── Crawl Website ────────────────────────────────────────────────────────
    $('#cs-btn-crawl').on('click', function() {
        const startUrl      = $('#cs-crawl-start-url').val().trim();
        const maxDepth      = $('#cs-crawl-depth').val();
        const maxPages      = $('#cs-crawl-max-pages').val();
        const requestDelay  = $('#cs-crawl-delay').val();
        const restrictPath  = $('#cs-crawl-restrict-path').val().trim();
        const mainOnly      = $('#cs-crawl-main-only').is(':checked') ? 1 : 0;
        const skipIndexed   = $('#cs-crawl-skip-indexed').is(':checked') ? 1 : 0;

        if (!startUrl) { showNotice('<?php echo esc_js(__('Please enter a start URL.', 'cleversay')); ?>', 'error'); return; }

        const $btn        = $(this).prop('disabled', true);
        const btnOrigText = $btn.text();
        $btn.text('Starting…');
        const $prog   = $('#cs-crawl-progress').show();
        const $bar    = $('#cs-crawl-bar').css({width:'0%', background:'#2271b1'});
        const $log    = $('#cs-crawl-log').empty();
        const $status = $('#cs-crawl-status').text('Starting crawl…');
        const $spinner = $('#cs-crawl-spinner').show();

        const crawlStart = Date.now();
        const elapsedTimer = setInterval(() => {
            $('[id=cs-crawl-elapsed]').text(Math.floor((Date.now()-crawlStart)/1000) + 's');
        }, 1000);

        function stopAnim() {
            clearInterval(elapsedTimer);
            $('[id=cs-crawl-elapsed]').text('');
            $spinner.hide();
        }

        function fail(msg) {
            stopAnim();
            showNotice(msg || 'Crawl failed.', 'error');
            $btn.prop('disabled', false).text(btnOrigText);
        }

        // Step 1: initialise the job
        $.post(ajaxurl_cs, {
            action: 'cleversay_crawl_discover',
            nonce, start_url: startUrl, max_depth: maxDepth,
            max_pages: maxPages, request_delay: requestDelay, restrict_path: restrictPath,
            main_content_only: mainOnly,
        }).done(function(r) {
            if (!r.success) return fail(r.data?.message);
            const jobId = r.data.job_id;
            let discovered = 0;

            // Step 2: discover one page at a time
            function discoverNext() {
                $.post(ajaxurl_cs, {
                    action: 'cleversay_crawl_discover_next',
                    nonce, job_id: jobId,
                }).done(function(r2) {
                    if (!r2.success) return fail(r2.data?.message);
                    discovered = r2.data.found;
                    const pct = maxPages > 0 ? Math.min(50, Math.round(discovered / maxPages * 50)) : 0;
                    $bar.css('width', pct + '%');
                    $status.text('Discovering… ' + discovered + ' page(s) found');
                    if (r2.data.url) {
                        const short = r2.data.url.replace(/^https?:\/\/[^\/]+/, '') || r2.data.url;
                        $log.append('<div style="color:#2271b1;">🔍 ' + escHtml(short) + '</div>');
                        $log.scrollTop($log[0].scrollHeight);
                    }
                    if (r2.data.errors && r2.data.errors.length) {
                        r2.data.errors.slice(-1).forEach(e => $log.append('<div style="color:#d63638;">⚠ ' + escHtml(e) + '</div>'));
                    }
                    if (!r2.data.done) return discoverNext();

                    // Discovery done — move to indexing
                    const urls  = r2.data.urls || [];
                    const total = urls.length;
                    if (!total) return fail('No pages found. Check the URL and settings.');

                    $log.append('<div><strong>' + total + ' page(s) found. Indexing…</strong></div>');
                    $status.text('Indexing 0 / ' + total);
                    let indexed = 0;

                    // Step 3: index one page at a time
                    function indexNext() {
                        $.post(ajaxurl_cs, {
                            action: 'cleversay_crawl_index_next',
                            nonce, job_id: jobId, skip_indexed: skipIndexed,
                        }).done(function(r3) {
                            if (!r3.success) return fail(r3.data?.message || 'Index error.');
                            const pct = 50 + Math.round((r3.data.current / total) * 50);
                            $bar.css('width', pct + '%');
                            const short = (r3.data.url || '').replace(/^https?:\/\/[^\/]+/, '') || r3.data.url;
                            if (r3.data.skipped) {
                                $log.append('<div style="color:#aaa;">— ' + escHtml(short) + ' (skipped)</div>');
                            } else if (r3.data.error) {
                                $log.append('<div style="color:#d63638;">✗ ' + escHtml(short) + ' — ' + escHtml(r3.data.error) + '</div>');
                            } else {
                                indexed++;
                                $log.append('<div style="color:#00a32a;">✓ ' + escHtml(short) + '</div>');
                            }
                            $log.scrollTop($log[0].scrollHeight);
                            $status.text('Indexing ' + r3.data.current + ' / ' + total);
                            if (r3.data.done) {
                                stopAnim();
                                $bar.css({width:'100%', background:'#00a32a'});
                                $status.text('Done! ' + indexed + ' page(s) indexed.');
                                $btn.prop('disabled', false).text(btnOrigText);
                                showNotice(indexed + ' page(s) indexed successfully.');
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                indexNext();
                            }
                        }).fail(() => fail('AJAX error during indexing.'));
                    }
                    indexNext();

                }).fail(() => fail('AJAX error during discovery.'));
            }
            discoverNext();

        }).fail(() => fail('Failed to start crawl.'));
    });

    function escHtml(t) {
        const d = document.createElement('div');
        d.textContent = t || '';
        return d.innerHTML;
    }

    // ── Test extraction (diagnostic) ──────────────────────────────────
    $('#cs-btn-diagnose').on('click', function() {
        const $btn      = $(this);
        const url       = $.trim($('#cs-diag-url').val());
        const $results  = $('#cs-diag-results');

        if (!url) {
            $results.show().html(
                '<div class="notice notice-error inline"><p>Please paste a URL.</p></div>'
            );
            return;
        }

        $btn.prop('disabled', true).text('Running…');
        $results.show().html('<p style="color:#646970;font-style:italic;">Fetching and parsing…</p>');

        csRequest('cleversay_diagnose_url', { url }, null, function(r) {
            $btn.prop('disabled', false).text('Run Diagnostic');

            if (!r || !r.success) {
                const msg = (r && r.data && r.data.message) || 'Request failed';
                $results.html(
                    '<div class="notice notice-error inline"><p><strong>Error:</strong> ' +
                    escHtml(msg) + '</p></div>'
                );
                return;
            }

            const d = r.data;
            const ok    = d.success;
            const color = ok ? '#00a32a' : '#d63638';
            const icon  = ok ? '✓' : '✗';

            let html = '';
            html += '<div style="border:1px solid ' + color + ';border-radius:6px;padding:14px;background:' + (ok ? '#f0fdf4' : '#fef2f2') + ';">';
            html += '<div style="font-size:14px;font-weight:600;color:' + color + ';margin-bottom:10px;">';
            html += icon + ' ' + (ok ? 'Extraction succeeded' : 'Extraction problem');
            if (d.error) html += ' — ' + escHtml(d.error);
            html += '</div>';

            // Step-by-step facts
            html += '<table style="width:100%;font-size:13px;border-collapse:collapse;">';
            html += '<tr><td style="padding:4px 8px;color:#646970;width:160px;">HTTP status</td><td><strong>' + escHtml(d.http_code) + '</strong></td></tr>';
            html += '<tr><td style="padding:4px 8px;color:#646970;">Body received</td><td><strong>' + Number(d.body_bytes).toLocaleString() + ' bytes</strong></td></tr>';
            if (d.content_type) {
                html += '<tr><td style="padding:4px 8px;color:#646970;">Content-Type</td><td><code style="font-size:12px;">' + escHtml(d.content_type) + '</code></td></tr>';
            }
            html += '<tr><td style="padding:4px 8px;color:#646970;">Content node matched</td><td><code style="font-size:12px;">' + escHtml(d.detection) + '</code></td></tr>';
            html += '<tr><td style="padding:4px 8px;color:#646970;">Extracted</td><td><strong>' + Number(d.word_count).toLocaleString() + ' words</strong> (' + Number(d.char_count).toLocaleString() + ' chars)</td></tr>';
            html += '</table>';

            // Selector probe results — most useful when extraction failed
            if (Array.isArray(d.candidates_tried) && d.candidates_tried.length) {
                const matched = d.candidates_tried.filter(c => c.matches > 0);
                if (matched.length) {
                    html += '<details style="margin-top:12px;"><summary style="cursor:pointer;font-size:12px;color:#646970;">';
                    html += matched.length + ' selector(s) found content (click to expand)</summary>';
                    html += '<table style="width:100%;font-size:12px;margin-top:6px;border-collapse:collapse;">';
                    html += '<tr style="background:#f0f0f1;"><th style="padding:4px 8px;text-align:left;">Selector</th><th style="padding:4px 8px;text-align:left;">Matches</th><th style="padding:4px 8px;text-align:left;">First node text size</th></tr>';
                    matched.forEach(c => {
                        html += '<tr style="border-top:1px solid #dcdcde;"><td style="padding:4px 8px;font-family:monospace;">' + escHtml(c.selector) + '</td><td style="padding:4px 8px;">' + c.matches + '</td><td style="padding:4px 8px;">' + Number(c.first_size).toLocaleString() + ' chars</td></tr>';
                    });
                    html += '</table></details>';
                } else {
                    html += '<p style="margin-top:12px;color:#d63638;font-size:12px;"><strong>No content selectors matched.</strong> The page may use unusual markup or the response body is empty/blocked.</p>';
                }
            }

            // Text preview
            if (d.extracted_text) {
                html += '<details style="margin-top:12px;" open><summary style="cursor:pointer;font-size:12px;color:#646970;">Extracted text preview (first 600 chars)</summary>';
                html += '<pre style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:10px;margin-top:6px;white-space:pre-wrap;font-size:12px;line-height:1.5;max-height:240px;overflow-y:auto;">';
                html += escHtml(d.extracted_text);
                if (d.char_count > 600) html += '\n…\n[' + Number(d.char_count - 600).toLocaleString() + ' more chars]';
                html += '</pre></details>';
            }

            html += '</div>';
            $results.html(html);
        });
    });

})(jQuery);
</script>
