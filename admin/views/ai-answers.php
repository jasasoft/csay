<?php
/**
 * AI Answers Review Page
 * Allows admins to review AI-generated answers and promote them to the knowledge base.
 *
 * @package CleverSay
 * @since 2.4.0
 */

if (!defined('ABSPATH')) { exit; }

global $wpdb;
$db      = new \CleverSay\Database();
$table   = $db->ai_answers;
$kb      = $db->knowledge_base;

$filter  = sanitize_text_field($_GET['filter'] ?? 'pending');
$allowed = ['pending', 'promoted', 'rejected', 'all', 'unhelpful'];
if (!in_array($filter, $allowed)) $filter = 'pending';

if ($filter === 'unhelpful') {
    // Cross-cutting filter — show only AI answers visitors marked 👎,
    // regardless of admin status. Helpful for prioritizing problem answers.
    $where = "WHERE rating = 0";
} elseif ($filter !== 'all') {
    $where = $wpdb->prepare("WHERE status = %s", $filter);
} else {
    $where = '';
}

// Deduplicate by question text (case-insensitive, trimmed).
// For each distinct question, keep the MOST RECENT row and count duplicates.
// The 'ask_count' field lets the UI show "asked N times" for repeated questions.
$answers = $wpdb->get_results(
    "SELECT t.*, d.ask_count
     FROM {$table} t
     INNER JOIN (
        SELECT LOWER(TRIM(question)) AS q_norm,
               MAX(id)              AS latest_id,
               COUNT(*)             AS ask_count
        FROM {$table}
        {$where}
        GROUP BY LOWER(TRIM(question))
     ) d ON d.latest_id = t.id
     ORDER BY t.created_at DESC
     LIMIT 100",
    ARRAY_A
);

// Count distinct questions per status (not raw rows) so tab counts match the
// deduplicated list the admin actually sees.
$counts = $wpdb->get_results(
    "SELECT status, COUNT(DISTINCT LOWER(TRIM(question))) AS cnt
     FROM {$table}
     GROUP BY status",
    ARRAY_A
);
$count_map = ['pending' => 0, 'promoted' => 0, 'rejected' => 0];
foreach ((array)$counts as $row) $count_map[$row['status']] = (int)$row['cnt'];
$count_map['all'] = array_sum($count_map);

// Separate count for negative-rating filter — counts distinct questions
// that have received at least one 👎 rating.
$count_map['unhelpful'] = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT LOWER(TRIM(question))) FROM {$table} WHERE rating = 0"
);
?>
<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline"><?php echo \CleverSay\Icons::render('shield', 16); ?> <?php esc_html_e('AI Answers', 'cleversay'); ?></h1>
    <hr class="wp-header-end">

    <!-- Filter tabs -->
    <ul class="subsubsub" style="margin-bottom:16px;">
        <?php foreach (['pending' => __('Pending','cleversay'), 'promoted' => __('Promoted','cleversay'), 'rejected' => __('Rejected','cleversay'), 'unhelpful' => '👎 ' . __('Marked unhelpful','cleversay'), 'all' => __('All','cleversay')] as $key => $label):
            $active = $filter === $key ? 'current' : '';
            $url    = add_query_arg(['page' => 'cleversay-ai-answers', 'filter' => $key], admin_url('admin.php'));
        ?>
        <li><a href="<?php echo esc_url($url); ?>" class="<?php echo esc_attr($active); ?>">
            <?php echo esc_html($label); ?> <span class="count">(<?php echo (int)($count_map[$key] ?? 0); ?>)</span>
        </a> | </li>
        <?php endforeach; ?>
    </ul>

    <?php if (empty($answers)): ?>
    <div class="cleversay-table-card" style="padding:32px;text-align:center;color:var(--cs-text-tertiary);">
        <?php echo \CleverSay\Icons::render('info', 16); ?>
        <?php if ($filter === 'pending'): ?>
            <?php esc_html_e('No AI answers pending review. They will appear here when the chatbot generates AI-assisted responses.', 'cleversay'); ?>
        <?php else: ?>
            <?php esc_html_e('No answers in this category.', 'cleversay'); ?>
        <?php endif; ?>
    </div>
    <?php else: ?>

    <div style="clear:both;"></div>
    <style>
        @media (max-width: 900px) {
            .cs-compare-grid {
                grid-template-columns: 1fr !important;
            }
            .cs-compare-grid > div:first-child {
                border-right: none !important;
                border-bottom: 1px solid #F5C2C2;
            }
        }
    </style>
    <div id="cs-ai-answers-list">
    <?php foreach ($answers as $row): ?>
    <div class="cs-ai-answer-card cleversay-panel" id="cs-answer-<?php echo (int)$row['id']; ?>">

            <!-- Header row -->
            <div class="cleversay-panel-header" style="align-items:flex-start;gap:16px;flex-wrap:wrap;">
                <div style="flex:1;min-width:0;">
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#999;margin-bottom:3px;"><?php esc_html_e('User asked:', 'cleversay'); ?></div>
                    <div style="font-size:15px;font-weight:600;color:#1d2327;">
                        <?php echo esc_html(wp_unslash($row['question'])); ?>
                        <?php if (!empty($row['ask_count']) && (int)$row['ask_count'] > 1): ?>
                            <span style="display:inline-block;margin-left:6px;padding:2px 8px;background:#2271b1;color:#fff;border-radius:10px;font-size:11px;font-weight:600;vertical-align:middle;" title="<?php esc_attr_e('This question has been asked multiple times', 'cleversay'); ?>">
                                <?php printf(esc_html__('asked %d times', 'cleversay'), (int)$row['ask_count']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($row['kb_rejected'])): ?>
                        <?php
                        $rej_reason   = $row['rejected_reason'] ?? '';
                        $rej_keyword  = $row['rejected_keyword'] ?? '';
                        $is_aadefault = ($rej_reason === 'aadefault');
                        ?>
                        <div class="cs-kb-rejected-notice"
                             style="margin-top:8px;padding:8px 12px;background:#FEE2E2;border-left:4px solid #EF4444;border-radius:4px;font-size:12px;color:#991B1B;display:flex;align-items:flex-start;gap:6px;">
                            <?php echo \CleverSay\Icons::render('alert-triangle', 14); ?>
                            <div style="flex:1;">
                                <?php if ($is_aadefault): ?>
                                    <strong><?php esc_html_e('KB match intercepted by AI (aadefault).', 'cleversay'); ?></strong>
                                    <?php if ($rej_keyword): ?>
                                        <?php printf(
                                            esc_html__('A KB aadefault answer for keyword "%s" was found but AI validation rejected it. Compare below.', 'cleversay'),
                                            esc_html($rej_keyword)
                                        ); ?>
                                    <?php else: ?>
                                        <?php esc_html_e('An aadefault KB answer was found but AI validation rejected it. Compare below.', 'cleversay'); ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <strong><?php esc_html_e('KB match intercepted by AI.', 'cleversay'); ?></strong>
                                    <?php esc_html_e('A KB answer was found but AI flagged it as not fitting the question. Compare below.', 'cleversay'); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php
                    // ── Conversation context: show prior exchanges if this was a follow-up ──
                    $history = null;
                    if (!empty($row['history_json'])) {
                        $history = json_decode(wp_unslash($row['history_json']), true);
                        if (!is_array($history)) $history = null;
                    }
                    // Only show if history has more than the greeting + current question
                    // (i.e. at least one prior user/bot exchange before the current question)
                    $show_history = false;
                    if ($history && count($history) >= 2) {
                        // Strip the final user message since that's the one this AI answer is responding to
                        $show_history = true;
                    }
                    ?>
                    <?php if ($show_history):
                        $history_id = 'cs-history-' . (int) $row['id'];
                    ?>
                        <div style="margin-top:8px;">
                            <a href="#" class="cs-toggle-history"
                               data-target="<?php echo esc_attr($history_id); ?>"
                               style="font-size:12px;color:#2271b1;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                                <?php echo \CleverSay\Icons::render('message-circle', 12); ?>
                                <?php esc_html_e('Show conversation context', 'cleversay'); ?>
                            </a>
                            <div id="<?php echo esc_attr($history_id); ?>"
                                 style="display:none;margin-top:8px;padding:12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;">
                                <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#50575e;margin-bottom:8px;">
                                    <?php esc_html_e('Chat history', 'cleversay'); ?>
                                </div>
                                <?php
                                // Show all history except the last user message (which is the current question)
                                $history_to_show = $history;
                                // Drop trailing user message that matches this question
                                $last = end($history_to_show);
                                if ($last && ($last['type'] ?? '') === 'user'
                                    && trim(strtolower($last['content'] ?? '')) === trim(strtolower($row['question']))) {
                                    array_pop($history_to_show);
                                }
                                foreach ($history_to_show as $msg):
                                    $is_user = ($msg['type'] ?? '') === 'user';
                                    $content = wp_strip_all_tags($msg['content'] ?? '');
                                    if (empty($content)) continue;
                                ?>
                                    <div style="margin-bottom:10px;display:flex;gap:10px;align-items:flex-start;">
                                        <span style="display:inline-block;min-width:60px;flex-shrink:0;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:<?php echo $is_user ? '#2271b1' : '#50575e'; ?>;padding-top:2px;">
                                            <?php echo $is_user ? esc_html__('User', 'cleversay') : esc_html__('Bot', 'cleversay'); ?>
                                        </span>
                                        <span style="flex:1;font-size:13px;line-height:1.5;color:#1d2327;">
                                            <?php echo esc_html($content); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                                <div style="margin-top:6px;padding-top:10px;border-top:1px dashed #dcdcde;font-size:11px;color:#50575e;font-style:italic;">
                                    <?php esc_html_e('↓ Follow-up question below generated this AI answer', 'cleversay'); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div style="margin-top:6px;font-size:12px;color:#aaa;">
                        <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($row['created_at']))); ?>
                        <?php
                        // v4.37.89+: Show clickable source list when citation
                        // rows exist for this answer. Falls back to the legacy
                        // comma-separated source_titles text for old answers
                        // (pre-citation rows) so they remain visible.
                        $citations = $wpdb->get_results($wpdb->prepare(
                            "SELECT s.id, s.title, s.source_type, s.url, s.file_name
                             FROM {$db->ai_answer_sources} a
                             JOIN {$db->sources} s ON s.id = a.source_id
                             WHERE a.answer_id = %d
                             ORDER BY a.position ASC",
                            (int) $row['id']
                        ), ARRAY_A);
                        ?>
                        <?php if (!empty($citations)): ?>
                            &nbsp;·&nbsp; <?php esc_html_e('Sources:', 'cleversay'); ?>
                            <?php foreach ($citations as $i => $cite):
                                $url = (string) ($cite['url'] ?? '');
                                if ($url === '' && in_array($cite['source_type'], ['pdf', 'docx', 'text'], true)) {
                                    $url = add_query_arg(['cleversay_source' => (int) $cite['id']], home_url('/'));
                                }
                                $icon = match ($cite['source_type']) {
                                    'pdf'  => '📄',
                                    'docx' => '📝',
                                    'url'  => '🔗',
                                    'text' => '📑',
                                    default => '•',
                                };
                                ?>
                                <?php if ($i > 0): ?>, <?php endif; ?>
                                <?php if ($url !== ''): ?>
                                    <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener" style="color:#2271b1;text-decoration:none;">
                                        <?php echo esc_html($icon); ?> <em><?php echo esc_html($cite['title']); ?></em>
                                    </a>
                                <?php else: ?>
                                    <em><?php echo esc_html($icon . ' ' . $cite['title']); ?></em>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php elseif (!empty($row['source_titles'])): ?>
                            &nbsp;·&nbsp; <?php esc_html_e('Sources:', 'cleversay'); ?> <em><?php echo esc_html(wp_unslash($row['source_titles'])); ?></em>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <?php
                    switch ($row['status']) {
                        case 'promoted': $badge_style = 'background:#e8f8ee;color:#00a32a;border:1px solid #b3dfbf;'; break;
                        case 'rejected': $badge_style = 'background:#fce4e4;color:#d63638;border:1px solid #f5aca6;'; break;
                        default:         $badge_style = 'background:#fef9e7;color:#dba617;border:1px solid #f5d875;'; break;
                    }
                    ?>
                    <span class="cleversay-badge <?php echo esc_attr($badge_class); ?>" style="text-transform:capitalize;"><?php echo esc_html($row['status']); ?></span>
                    <?php
                    // User-rating badge — appears only when a visitor has rated
                    // this specific AI answer (👍 or 👎). Helps surface answers
                    // visitors found unhelpful at a glance.
                    if (isset($row['rating']) && $row['rating'] !== null && $row['rating'] !== ''):
                        if ((int) $row['rating'] === 1) {
                            $rating_label = '👍 ' . __('Helpful', 'cleversay');
                            $rating_style = 'background:#e8f8ee;color:#00a32a;border:1px solid #b3dfbf;';
                        } else {
                            $rating_label = '👎 ' . __('Not helpful', 'cleversay');
                            $rating_style = 'background:#fce4e4;color:#d63638;border:1px solid #f5aca6;';
                        }
                    ?>
                        <span class="cleversay-badge"
                              style="<?php echo esc_attr($rating_style); ?>;margin-top:4px;display:block;"
                              title="<?php
                              echo !empty($row['rated_at'])
                                ? esc_attr(sprintf(
                                    __('Rated by visitor %s', 'cleversay'),
                                    date_i18n(get_option('date_format'), strtotime($row['rated_at']))))
                                : esc_attr__('Rated by visitor', 'cleversay');
                              ?>">
                            <?php echo esc_html($rating_label); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Answer -->
            <?php
            $has_comparison = !empty($row['kb_rejected']) && !empty($row['rejected_kb_answer']);
            ?>
            <?php if ($has_comparison): ?>
                <!-- Side-by-side: KB vs AI -->
                <div class="cleversay-panel-body cs-compare-grid" style="border-bottom:1px solid var(--cs-separator);display:grid;grid-template-columns:1fr 1fr;gap:0;padding:0;">
                    <!-- KB side (rejected) -->
                    <div style="padding:14px 16px;background:#FEF2F2;border-right:1px solid #F5C2C2;position:relative;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:8px;">
                            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#991B1B;display:flex;align-items:center;gap:4px;">
                                <?php echo \CleverSay\Icons::render('x-circle', 12); ?>
                                <?php esc_html_e('KB answer (rejected)', 'cleversay'); ?>
                            </div>
                            <?php if (!empty($row['rejected_keyword'])): ?>
                                <span style="font-size:10px;color:#991B1B;font-family:monospace;">
                                    <?php echo esc_html($row['rejected_keyword']); ?>
                                    <?php if (($row['rejected_reason'] ?? '') === 'aadefault'): ?>
                                        / aadefault
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="cs-kb-text" style="font-size:13px;line-height:1.55;color:#1d2327;max-height:240px;overflow:auto;">
                            <?php echo nl2br(esc_html(trim(wp_unslash($row['rejected_kb_answer'])))); ?>
                        </div>
                        <?php if (!empty($row['rejected_keyword'])): ?>
                            <div style="margin-top:10px;padding-top:10px;border-top:1px dashed #F5C2C2;">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-knowledge&s=' . urlencode($row['rejected_keyword']))); ?>"
                                   class="button button-small" style="font-size:12px;">
                                    <?php echo \CleverSay\Icons::render('edit', 12); ?>
                                    <?php esc_html_e('Edit KB entry', 'cleversay'); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- AI side (generated) -->
                    <div style="padding:14px 16px;background:#F0FDF4;">
                        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#166534;margin-bottom:8px;display:flex;align-items:center;gap:4px;">
                            <?php echo \CleverSay\Icons::render('sparkles', 12); ?>
                            <?php esc_html_e('AI answer (served)', 'cleversay'); ?>
                        </div>
                        <div class="cs-ai-text" style="font-size:13px;line-height:1.55;color:#1d2327;max-height:240px;overflow:auto;">
                            <?php echo nl2br(esc_html(trim(wp_unslash($row['answer'])))); ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Single column: no KB rejection context -->
                <div class="cleversay-panel-body" style="border-bottom:1px solid var(--cs-separator);">
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#999;margin-bottom:8px;"><?php esc_html_e('AI Answer:', 'cleversay'); ?></div>
                    <div class="cs-answer-text" style="font-size:14px;line-height:1.65;color:#1d2327;max-height:200px;overflow:hidden;position:relative;">
                        <?php echo nl2br(esc_html(trim(wp_unslash($row['answer'])))); ?>
                        <div style="position:absolute;bottom:0;left:0;right:0;height:40px;background:linear-gradient(transparent,#fff);pointer-events:none;"></div>
                    </div>
                    <button type="button" class="button-link cs-expand-answer" style="font-size:12px;margin-top:4px;">
                        <?php esc_html_e('Show full answer ▼', 'cleversay'); ?>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Actions -->
            <?php if ($row['status'] === 'pending'): ?>
            <div class="cs-ai-answer-actions" style="padding:14px 20px 0 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#fff;">
                <button type="button"
                        class="button button-primary cs-promote-btn"
                        data-id="<?php echo (int)$row['id']; ?>"
                        data-question="<?php echo esc_attr(wp_unslash($row['question'])); ?>"
                        data-answer="<?php echo esc_attr(wp_unslash($row['answer'])); ?>">
                    <?php echo \CleverSay\Icons::render('plus', 16); ?>
                    <?php esc_html_e('Promote to Knowledge Base', 'cleversay'); ?>
                </button>
                <button type="button"
                        class="button cs-reject-btn"
                        data-id="<?php echo (int)$row['id']; ?>"
                        style="color:#d63638;border-color:#d63638;">
                    <?php esc_html_e('Reject', 'cleversay'); ?>
                </button>
            </div>
            <?php elseif ($row['status'] === 'promoted' && $row['knowledge_id']): ?>
            <div style="padding:12px 20px;background:#f9fff9;font-size:13px;color:#00a32a;">
                <?php echo \CleverSay\Icons::render('check-circle', 16); ?>
                <?php
                $edit_url = add_query_arg(['page' => 'cleversay-knowledge', 'action' => 'edit', 'id' => $row['knowledge_id']], admin_url('admin.php'));
                printf(
                    wp_kses(__('Added to knowledge base. <a href="%s">Edit entry →</a>', 'cleversay'), ['a' => ['href' => []]]),
                    esc_url($edit_url)
                );
                ?>
            </div>
            <?php endif; ?>

    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<!-- Promote Modal -->
<div id="cs-promote-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.5);overflow:auto;">
    <div style="background:#fff;border-radius:8px;width:700px;max-width:95vw;margin:40px auto;padding:0;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.2);">

        <div style="background:#2271b1;color:#fff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;">
            <h2 style="margin:0;font-size:16px;color:#fff;"><?php esc_html_e('Add to Knowledge Base', 'cleversay'); ?></h2>
            <button type="button" id="cs-modal-close" style="background:none;border:none;color:#fff;cursor:pointer;font-size:20px;line-height:1;padding:0;">✕</button>
        </div>

        <div style="padding:20px;">
            <div id="cs-ai-modal-status" style="display:none;margin-bottom:14px;padding:10px 14px;border-radius:4px;font-size:13px;"></div>
            <p class="description" style="margin-bottom:16px;">
                <?php esc_html_e('Review and edit the details below before adding this to your knowledge base. It will be saved as "Hold" status so you can review it before making it active.', 'cleversay'); ?>
            </p>

            <table class="form-table" style="margin:0;">
                <tr>
                    <th style="width:140px;"><label for="cs-kb-keyword"><?php esc_html_e('Keyword *', 'cleversay'); ?></label></th>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <!-- Keyword autocomplete -->
                            <div style="position:relative;flex:1;">
                                <input type="text" id="cs-kb-keyword" class="regular-text"
                                       placeholder="<?php esc_attr_e('Type to search or enter new keyword…', 'cleversay'); ?>"
                                       autocomplete="off" style="width:100%;box-sizing:border-box;">
                                <div id="cs-keyword-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #ccc;border-top:none;border-radius:0 0 4px 4px;max-height:200px;overflow-y:auto;z-index:10000;box-shadow:0 4px 8px rgba(0,0,0,.1);"></div>
                            </div>
                            <button type="button" class="button cs-ai-regen-btn" data-field="keyword" title="<?php esc_attr_e('Regenerate keyword with AI', 'cleversay'); ?>">
                                <?php echo \CleverSay\Icons::render('shield', 16); ?>
                            </button>
                        </div>
                        <p class="description" style="margin-top:4px;">
                            <span id="cs-keyword-new-warn" style="display:none;color:#dba617;font-weight:600;">
                                <?php echo \CleverSay\Icons::render('alert-triangle', 16); ?>
                                <?php esc_html_e('This is a new keyword — it will be created when you save.', 'cleversay'); ?>
                            </span>
                            <span id="cs-keyword-exists-ok" style="display:none;color:#00a32a;">
                                <?php echo \CleverSay\Icons::render('check-circle', 16); ?>
                                <?php esc_html_e('Matches existing keyword.', 'cleversay'); ?>
                            </span>
                            <span id="cs-keyword-default-hint">
                                <?php esc_html_e('Choose an existing keyword or type a new one.', 'cleversay'); ?>
                            </span>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cs-kb-sub-keyword"><?php esc_html_e('Sub-keyword', 'cleversay'); ?></label></th>
                    <td>
                        <!-- Sub-keyword with existing list -->
                        <div id="cs-subkeyword-existing" style="display:none;margin-bottom:8px;">
                            <div style="font-size:12px;font-weight:600;color:#666;margin-bottom:6px;text-transform:uppercase;letter-spacing:.03em;">
                                <?php esc_html_e('Existing sub-keywords for this keyword:', 'cleversay'); ?>
                            </div>
                            <div id="cs-subkeyword-list" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
                        </div>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="text" id="cs-kb-sub-keyword" class="regular-text"
                                   placeholder="<?php esc_attr_e('e.g. view|online|check  (optional)', 'cleversay'); ?>"
                                   style="flex:1;box-sizing:border-box;">
                            <button type="button" class="button cs-ai-regen-btn" data-field="pattern" title="<?php esc_attr_e('Regenerate pattern with AI (validates against question)', 'cleversay'); ?>">
                                <?php echo \CleverSay\Icons::render('shield', 16); ?>
                            </button>
                        </div>
                        <p class="description" style="margin-top:4px;">
                            <span id="cs-pattern-status"></span>
                            <span id="cs-pattern-default-hint"><?php esc_html_e('Click a sub-keyword above or type a new one. Use | for OR, & for AND.', 'cleversay'); ?></span>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cs-kb-question"><?php esc_html_e('Question', 'cleversay'); ?></label></th>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="text" id="cs-kb-question" class="large-text" style="flex:1;box-sizing:border-box;">
                            <button type="button" class="button cs-ai-regen-btn" data-field="question" title="<?php esc_attr_e('Regenerate question with AI', 'cleversay'); ?>">
                                <?php echo \CleverSay\Icons::render('shield', 16); ?>
                            </button>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th><label for="cs-kb-answer"><?php esc_html_e('Answer', 'cleversay'); ?></label></th>
                    <td>
                        <textarea id="cs-kb-answer" class="large-text" rows="8" style="font-size:13px;line-height:1.6;width:100%;box-sizing:border-box;"></textarea>
                        <p class="description"><?php esc_html_e('Edit the AI answer if needed before saving.', 'cleversay'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div style="padding:16px 20px;background:#f9f9f9;border-top:1px solid #e5e5e5;display:flex;gap:10px;justify-content:space-between;align-items:center;">
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;user-select:none;">
                <input type="checkbox" id="cs-modal-activate" checked>
                <?php esc_html_e('Activate immediately', 'cleversay'); ?>
                <span title="<?php esc_attr_e('When checked, the entry is live right after saving. Uncheck to save as Hold for manual review first.', 'cleversay'); ?>" style="cursor:help;"><?php echo \CleverSay\Icons::render('info', 14); ?></span>
            </label>
            <div style="display:flex;gap:10px;">
                <button type="button" id="cs-modal-cancel" class="button"><?php esc_html_e('Cancel', 'cleversay'); ?></button>
                <button type="button" id="cs-modal-save" class="button button-primary">
                    <?php echo \CleverSay\Icons::render('check-circle', 16); ?>
                    <?php esc_html_e('Add to Knowledge Base', 'cleversay'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function($) {
    const nonce = '<?php echo esc_js(wp_create_nonce('cleversay_admin_nonce')); ?>';
    let currentAnswerId = null;

    // Expand/collapse answer
    $(document).on('click', '.cs-expand-answer', function() {
        const $text = $(this).prev('.cs-answer-text');
        if ($text.css('max-height') === 'none') {
            $text.css('max-height', '200px');
            $(this).text('<?php echo esc_js(__('Show full answer ▼', 'cleversay')); ?>');
        } else {
            $text.css('max-height', 'none').find('div').hide();
            $(this).text('<?php echo esc_js(__('Collapse ▲', 'cleversay')); ?>');
        }
    });

    // Toggle conversation history display
    $(document).on('click', '.cs-toggle-history', function(e) {
        e.preventDefault();
        const target = $(this).data('target');
        $('#' + target).slideToggle(150);
    });

    // Keyword autocomplete
    let keywordTimer = null;

    function loadKeywords(q) {
        clearTimeout(keywordTimer);
        keywordTimer = setTimeout(function() {
            $.get(ajaxurl, {
                action:  'cleversay_get_kb_keywords',
                nonce:   nonce,
                q:       q
            }).done(function(r) {
                const $dd = $('#cs-keyword-dropdown');
                if (!r.success || !r.data.length) { $dd.hide(); return; }
                $dd.empty();
                r.data.forEach(function(kw) {
                    $('<div>')
                        .text(kw)
                        .css({padding:'8px 12px',cursor:'pointer','font-size':'13px','border-bottom':'1px solid #f0f0f1'})
                        .on('mouseenter', function() { $(this).css('background','#f0f6ff'); })
                        .on('mouseleave', function() { $(this).css('background',''); })
                        .on('mousedown', function(e) {
                            e.preventDefault();
                            $('#cs-kb-keyword').val(kw);
                            $dd.hide();
                            loadSubKeywords(kw);
                        })
                        .appendTo($dd);
                });
                $dd.show();
            });
        }, 200);
    }

    function loadSubKeywords(keyword) {
        if (!keyword) { $('#cs-subkeyword-existing').hide(); return; }
        $.get(ajaxurl, {
            action:  'cleversay_get_kb_subkeywords',
            nonce:   nonce,
            keyword: keyword
        }).done(function(r) {
            const $wrap = $('#cs-subkeyword-existing');
            const $list = $('#cs-subkeyword-list').empty();
            if (!r.success || !r.data.length) { $wrap.hide(); return; }
            r.data.forEach(function(row) {
                const label = row.sub_keyword || '(default)';
                const $tag = $('<button type="button">')
                    .text(label)
                    .attr('title', row.question || '')
                    .css({
                        padding:'3px 10px', background:'#f0f4ff',
                        border:'1px solid #c5d0f5', borderRadius:'12px',
                        cursor:'pointer', 'font-size':'12px', color:'#2271b1'
                    })
                    .on('click', function() {
                        $('#cs-kb-sub-keyword').val(row.sub_keyword);
                        $list.find('button').css({'background':'#f0f4ff','font-weight':'normal'});
                        $(this).css({'background':'#2271b1','color':'#fff','font-weight':'600'});
                    });
                $tag.appendTo($list);
            });
            $wrap.show();
        });
    }

    $('#cs-kb-keyword')
        .on('input', function() {
            loadKeywords($(this).val().trim());
            loadSubKeywords($(this).val().trim());
        })
        .on('blur', function() {
            setTimeout(function() { $('#cs-keyword-dropdown').hide(); }, 150);
        })
        .on('focus', function() {
            if ($(this).val().trim()) loadKeywords($(this).val().trim());
        });

    // Open promote modal
    // Keep a reference to the original AI question and answer for retries
    let modalOriginalQuestion = '';
    let modalOriginalAnswer   = '';

    $(document).on('click', '.cs-promote-btn', function() {
        currentAnswerId = $(this).data('id');
        const question  = $(this).data('question');
        const answer    = $(this).data('answer');

        modalOriginalQuestion = question;
        modalOriginalAnswer   = answer;

        // Show modal with original values — AI will fill in the rest
        $('#cs-kb-keyword').val('');
        $('#cs-kb-sub-keyword').val('');
        $('#cs-kb-question').val(question);
        $('#cs-kb-answer').val(answer);
        $('#cs-subkeyword-existing').hide();
        $('#cs-keyword-dropdown').hide();
        $('#cs-keyword-new-warn, #cs-keyword-exists-ok').hide();
        $('#cs-keyword-default-hint, #cs-pattern-default-hint').show();
        $('#cs-pattern-status').empty();
        $('#cs-promote-modal').show();

        // Auto-run AI suggestion for all fields
        runAiSuggest('all');
    });

    // ── AI suggest runner ──────────────────────────────────────────────────
    function runAiSuggest(mode) {
        const $status = $('#cs-ai-modal-status');
        $status
            .removeClass()
            .css({background:'#f0f6ff',border:'1px solid #c5d0f5',color:'#2271b1'})
            .html('<span class="spinner is-active" style="float:none;vertical-align:middle;margin:0 6px 0 0;"></span> <?php echo esc_js(__('AI is analysing the question…', 'cleversay')); ?>')
            .show();

        // Disable regen buttons during request
        $('.cs-ai-regen-btn').prop('disabled', true);

        $.post(ajaxurl, {
            action:   'cleversay_ai_suggest_promote',
            nonce:    nonce,
            question: modalOriginalQuestion,
            answer:   modalOriginalAnswer,
            mode:     mode,
            keyword:  $('#cs-kb-keyword').val().trim(),
        }).done(function(r) {
            $('.cs-ai-regen-btn').prop('disabled', false);
            if (!r.success) {
                $status.css({background:'#fce4e4',border:'1px solid #f5aca6',color:'#d63638'})
                       .text(r.data?.message || '<?php echo esc_js(__('AI suggestion failed', 'cleversay')); ?>');
                return;
            }
            applyAiSuggestion(r.data, mode);
        }).fail(function() {
            $('.cs-ai-regen-btn').prop('disabled', false);
            $status.css({background:'#fce4e4',border:'1px solid #f5aca6',color:'#d63638'})
                   .text('<?php echo esc_js(__('Network error. Please try again.', 'cleversay')); ?>');
        });
    }

    function applyAiSuggestion(d, mode) {
        // Fill fields based on mode
        if (mode === 'all' || mode === 'question') {
            $('#cs-kb-question').val(d.question);
        }
        if (mode === 'all' || mode === 'keyword') {
            $('#cs-kb-keyword').val(d.keyword);
            loadSubKeywords(d.keyword);
            updateKeywordStatus(d.keyword_is_new);
        }
        if (mode === 'all' || mode === 'pattern') {
            $('#cs-kb-sub-keyword').val(d.pattern);
        }

        // Status bar — reflect validation outcome
        const $status = $('#cs-ai-modal-status');
        const $pstat  = $('#cs-pattern-status');
        const pHint   = $('#cs-pattern-default-hint');

        if (d.pattern_validated) {
            $status.css({background:'#f0faf0',border:'1px solid #8dbc8d',color:'#1d5e1d'})
                   .html('<?php echo \CleverSay\Icons::render('check-circle', 16); ?> '
                       + '<strong><?php echo esc_js(__('AI suggestion verified.', 'cleversay')); ?></strong> '
                       + escHtml(d.explanation || ''));
            $pstat.css('color','#00a32a').html('<?php echo \CleverSay\Icons::render('check-circle', 16); ?> <?php echo esc_js(__('Pattern validates against the question', 'cleversay')); ?>');
            pHint.hide();
        } else {
            $status.css({background:'#fdf8ee',border:'1px solid #dba617',color:'#6b5100'})
                   .html('<?php echo \CleverSay\Icons::render('alert-triangle', 16); ?> '
                       + '<strong><?php echo esc_js(__('AI suggestion unverified:', 'cleversay')); ?></strong> '
                       + escHtml(d.fail_reason || '')
                       + ' <?php echo esc_js(__('You may edit the fields or click regenerate.', 'cleversay')); ?>');
            $pstat.css('color','#a36200').html('<?php echo \CleverSay\Icons::render('alert-triangle', 16); ?> <?php echo esc_js(__('Pattern did not validate — review carefully', 'cleversay')); ?>');
            pHint.hide();
        }
    }

    function updateKeywordStatus(isNew) {
        $('#cs-keyword-default-hint').hide();
        if (isNew) {
            $('#cs-keyword-new-warn').show();
            $('#cs-keyword-exists-ok').hide();
        } else {
            $('#cs-keyword-new-warn').hide();
            $('#cs-keyword-exists-ok').show();
        }
    }

    // Regenerate buttons
    $(document).on('click', '.cs-ai-regen-btn', function() {
        const field = $(this).data('field');
        runAiSuggest(field);
    });

    // If admin manually changes the keyword, re-check status against existing list
    $('#cs-kb-keyword').on('change blur', function() {
        const kw = $(this).val().trim();
        if (!kw) {
            $('#cs-keyword-new-warn, #cs-keyword-exists-ok').hide();
            $('#cs-keyword-default-hint').show();
            return;
        }
        $.get(ajaxurl, {
            action: 'cleversay_get_kb_keywords',
            nonce:  nonce,
            q:      kw,
        }).done(function(r) {
            if (r.success && r.data && r.data.some(function(k) { return k.toLowerCase() === kw.toLowerCase(); })) {
                updateKeywordStatus(false);
            } else {
                updateKeywordStatus(true);
            }
        });
    });

    function escHtml(t) {
        const d = document.createElement('div');
        d.textContent = String(t || '');
        return d.innerHTML;
    }

    // Close modal
    $('#cs-modal-close, #cs-modal-cancel').on('click', function() {
        $('#cs-promote-modal').hide();
        currentAnswerId = null;
    });

    $('#cs-promote-modal').on('click', function(e) {
        if ($(e.target).is('#cs-promote-modal')) {
            $('#cs-promote-modal').hide();
            currentAnswerId = null;
        }
    });

    // Save to KB
    $('#cs-modal-save').on('click', function() {
        const keyword = $('#cs-kb-keyword').val().trim();
        if (!keyword) {
            alert('<?php echo esc_js(__('Please enter a keyword.', 'cleversay')); ?>');
            $('#cs-kb-keyword').trigger('focus');
            return;
        }

        const $btn = $(this).prop('disabled', true).text('Saving…');

        $.post(ajaxurl, {
            action:      'cleversay_promote_ai_answer',
            nonce:       nonce,
            answer_id:   currentAnswerId,
            keyword:     keyword,
            sub_keyword: $('#cs-kb-sub-keyword').val().trim(),
            question:    $('#cs-kb-question').val().trim(),
            answer:      $('#cs-kb-answer').val().trim(),
            activate:    $('#cs-modal-activate').is(':checked') ? 1 : 0,
        }).done(function(r) {
            if (r.success) {
                $('#cs-promote-modal').hide();
                // Update the card status
                const $card = $('#cs-answer-' + currentAnswerId);
                $card.find('.cs-promote-btn, .cs-reject-btn').closest('div').html(
                    '<div style="padding:12px 20px;background:#f9fff9;font-size:13px;color:#00a32a;">' +
                    '<?php echo \CleverSay\Icons::render('check-circle', 16); ?>' +
                    '<?php echo esc_js(__('Added to knowledge base.', 'cleversay')); ?>' +
                    (r.data.edit_url ? ' <a href="' + r.data.edit_url + '"><?php echo esc_js(__('Edit entry →', 'cleversay')); ?></a>' : '') +
                    '</div>'
                );
                $card.find('[style*="background:#fef9e7"]').replaceWith(
                    '<span style="background:#e8f8ee;color:#00a32a;border:1px solid #b3dfbf;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;">Promoted</span>'
                );
                currentAnswerId = null;
            } else {
                alert(r.data?.message || 'Error saving.');
            }
        }).fail(function() {
            alert('Request failed.');
        }).always(function() {
            $btn.prop('disabled', false).html(
                '<?php echo \CleverSay\Icons::render('check-circle', 16); ?>' +
                '<?php echo esc_js(__('Add to Knowledge Base', 'cleversay')); ?>'
            );
        });
    });

    // Reject
    $(document).on('click', '.cs-reject-btn', function() {
        if (!confirm('<?php echo esc_js(__('Reject this answer? It will be hidden from the review queue.', 'cleversay')); ?>')) return;
        const id   = $(this).data('id');
        const $btn = $(this).prop('disabled', true);

        $.post(ajaxurl, {
            action:    'cleversay_reject_ai_answer',
            nonce:     nonce,
            answer_id: id,
        }).done(function(r) {
            if (r.success) {
                $('#cs-answer-' + id).fadeOut(300, function() { $(this).remove(); });
            } else {
                alert(r.data?.message || 'Error.');
                $btn.prop('disabled', false);
            }
        });
    });

})(jQuery);
</script>
