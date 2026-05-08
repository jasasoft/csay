<?php
/**
 * Questions Log Admin View
 *
 * @package CleverSay
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$table = $wpdb->prefix . 'cleversay_questions';

// Handle filters
$filter_type = sanitize_text_field($_GET['type'] ?? '');
$filter_date = sanitize_text_field($_GET['date'] ?? '');
$filter_lang = sanitize_text_field($_GET['lang'] ?? '');
$search = sanitize_text_field($_GET['s'] ?? '');
$paged = max(1, intval($_GET['paged'] ?? 1));
$per_page = 50;

// Build query
$where_clauses = ['1=1'];

if ($filter_type === 'matched') {
    $where_clauses[] = "match_type IS NOT NULL AND match_type != 'none'";
} elseif ($filter_type === 'unmatched') {
    $where_clauses[] = "(match_type IS NULL OR match_type = 'none')";
} elseif ($filter_type === 'ai_rejected') {
    $where_clauses[] = 'ai_rejected = 1';
} elseif ($filter_type === 'ai_rejected_aadefault') {
    $where_clauses[] = "ai_rejected = 1 AND ai_rejection_reason = 'aadefault'";
}

if ($filter_lang === 'non_en') {
    $where_clauses[] = "detected_language IS NOT NULL AND detected_language != '' AND detected_language != 'en'";
} elseif ($filter_lang !== '') {
    $where_clauses[] = $wpdb->prepare('detected_language = %s', $filter_lang);
}

if ($filter_date === 'today') {
    $where_clauses[] = 'DATE(created_at) = CURDATE()';
} elseif ($filter_date === 'week') {
    $where_clauses[] = 'created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ($filter_date === 'month') {
    $where_clauses[] = 'created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
}

if (!empty($search)) {
    $where_clauses[] = $wpdb->prepare(
        '(question LIKE %s OR original_question LIKE %s)',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%'
    );
}

$where_sql = implode(' AND ', $where_clauses);

// Collect distinct languages for the filter dropdown
$lang_counts = $wpdb->get_results(
    "SELECT detected_language AS lang, COUNT(*) AS n
     FROM {$table}
     WHERE detected_language IS NOT NULL AND detected_language != '' AND detected_language != 'en'
     GROUP BY detected_language
     ORDER BY n DESC, detected_language ASC",
    ARRAY_A
) ?: [];

// Get total count
$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
$total = (int) $wpdb->get_var($count_sql);
$total_pages = ceil($total / $per_page);

// Get entries
$offset = ($paged - 1) * $per_page;
$sql = $wpdb->prepare(
    "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
    $per_page,
    $offset
);
$questions = $wpdb->get_results($sql, ARRAY_A);

// Get stats
$stats = [
    'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
    'matched' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE match_type IS NOT NULL AND match_type != 'none'"),
    'today' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) = CURDATE()"),
];

// Fetch knowledge responses for matched questions
$knowledge_data = [];
$knowledge_ids = array_filter(array_column($questions, 'knowledge_id'));
if (!empty($knowledge_ids)) {
    $ids_placeholder = implode(',', array_map('intval', $knowledge_ids));
    $knowledge_results = $wpdb->get_results(
        "SELECT id, keyword, sub_keyword, response FROM {$wpdb->prefix}cleversay_knowledge WHERE id IN ({$ids_placeholder})",
        ARRAY_A
    );
    foreach ($knowledge_results as $k) {
        $knowledge_data[$k['id']] = $k;
    }
}

// Fetch served AI answers so View Details can show KB vs AI side-by-side.
// New (v4.15.2+) ai_answers rows are FK-linked via logged_question_id —
// that's the reliable path. For older rows from before the FK was added,
// fall back to matching on IP + tight created_at window.
$ai_served = []; // keyed by questions_log id
if (!empty($questions)) {
    $ai_table  = $wpdb->prefix . 'cleversay_ai_answers';
    $q_ids     = array_filter(array_map(fn($q) => (int) $q['id'], $questions));

    // 1) Batch FK lookup (covers new rows)
    if (!empty($q_ids)) {
        $placeholders = implode(',', array_fill(0, count($q_ids), '%d'));
        $fk_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT logged_question_id, answer, kb_rejected, rejected_kb_answer,
                    rejected_keyword, rejected_reason
             FROM {$ai_table}
             WHERE logged_question_id IN ({$placeholders})",
            ...$q_ids
        ), ARRAY_A) ?: [];
        foreach ($fk_rows as $r) {
            $ai_served[(int) $r['logged_question_id']] = $r;
        }
    }

    // 2) Legacy fallback — only for rows we couldn't match by FK
    foreach ($questions as $q) {
        $qid = (int) $q['id'];
        if (isset($ai_served[$qid]) || empty($q['created_at'])) continue;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT answer, kb_rejected, rejected_kb_answer, rejected_keyword, rejected_reason
             FROM {$ai_table}
             WHERE created_at BETWEEN DATE_SUB(%s, INTERVAL 30 SECOND)
                                  AND DATE_ADD(%s, INTERVAL 30 SECOND)
               AND (logged_question_id IS NULL OR logged_question_id = 0)
             ORDER BY ABS(TIMESTAMPDIFF(SECOND, created_at, %s)) ASC
             LIMIT 1",
            $q['created_at'],
            $q['created_at'],
            $q['created_at']
        ), ARRAY_A);
        if ($row) {
            $ai_served[$qid] = $row;
        }
    }
}
?>

<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline"><?php echo \CleverSay\Icons::render('list', 26); ?> <?php esc_html_e('Questions Log', 'cleversay'); ?></h1>
    
    <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-questions&export=csv')); ?>" class="page-title-action">
        <?php esc_html_e('Export CSV', 'cleversay'); ?>
    </a>
    
    <hr class="wp-header-end">
    
    <!-- Stats Cards - Match dashboard styling -->
    <div class="cleversay-stats-row">
        <div class="cleversay-stat-box">
            <div class="stat-icon" style="background: #3b82f6;">
                <?php echo \CleverSay\Icons::render('list', 16); ?>
            </div>
            <div class="stat-info">
                <span class="stat-number"><?php echo number_format($stats['total']); ?></span>
                <span class="stat-label"><?php esc_html_e('Total Questions', 'cleversay'); ?></span>
            </div>
        </div>
        <div class="cleversay-stat-box">
            <div class="stat-icon" style="background: #22c55e;">
                <?php echo \CleverSay\Icons::render('check-circle', 16); ?>
            </div>
            <div class="stat-info">
                <span class="stat-number"><?php echo number_format($stats['matched']); ?></span>
                <span class="stat-label"><?php esc_html_e('Matched', 'cleversay'); ?></span>
            </div>
        </div>
        <div class="cleversay-stat-box">
            <div class="stat-icon" style="background: #8b5cf6;">
                <?php echo \CleverSay\Icons::render('pie-chart', 16); ?>
            </div>
            <div class="stat-info">
                <span class="stat-number">
                    <?php echo $stats['total'] > 0 ? round(($stats['matched'] / $stats['total']) * 100, 1) : 0; ?>%
                </span>
                <span class="stat-label"><?php esc_html_e('Match Rate', 'cleversay'); ?></span>
            </div>
        </div>
        <div class="cleversay-stat-box">
            <div class="stat-icon" style="background: #f59e0b;">
                <?php echo \CleverSay\Icons::render('calendar', 16); ?>
            </div>
            <div class="stat-info">
                <span class="stat-number"><?php echo number_format($stats['today']); ?></span>
                <span class="stat-label"><?php esc_html_e('Today', 'cleversay'); ?></span>
            </div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="tablenav top">
        <form method="get" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <input type="hidden" name="page" value="cleversay-questions">
            <select name="type" id="filter-type">
                <option value=""><?php esc_html_e('All statuses', 'cleversay'); ?></option>
                <option value="matched" <?php selected($filter_type, 'matched'); ?>><?php esc_html_e('Matched', 'cleversay'); ?></option>
                <option value="unmatched" <?php selected($filter_type, 'unmatched'); ?>><?php esc_html_e('Unmatched', 'cleversay'); ?></option>
                <option value="ai_rejected" <?php selected($filter_type, 'ai_rejected'); ?>><?php esc_html_e('AI-rejected (all)', 'cleversay'); ?></option>
                <option value="ai_rejected_aadefault" <?php selected($filter_type, 'ai_rejected_aadefault'); ?>><?php esc_html_e('AI-rejected (aadefault)', 'cleversay'); ?></option>
            </select>
            <?php if (!empty($lang_counts)): ?>
            <select name="lang" id="filter-lang">
                <option value=""><?php esc_html_e('All languages', 'cleversay'); ?></option>
                <option value="non_en" <?php selected($filter_lang, 'non_en'); ?>><?php esc_html_e('Any non-English', 'cleversay'); ?></option>
                <?php foreach ($lang_counts as $lc): ?>
                    <option value="<?php echo esc_attr($lc['lang']); ?>" <?php selected($filter_lang, $lc['lang']); ?>>
                        <?php echo esc_html(strtoupper($lc['lang'])); ?>
                        (<?php echo esc_html(number_format_i18n((int) $lc['n'])); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select name="date" id="filter-date">
                <option value=""><?php esc_html_e('All time', 'cleversay'); ?></option>
                <option value="today" <?php selected($filter_date, 'today'); ?>><?php esc_html_e('Today', 'cleversay'); ?></option>
                <option value="week" <?php selected($filter_date, 'week'); ?>><?php esc_html_e('Last 7 days', 'cleversay'); ?></option>
                <option value="month" <?php selected($filter_date, 'month'); ?>><?php esc_html_e('Last 30 days', 'cleversay'); ?></option>
            </select>
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
                   placeholder="<?php esc_attr_e('Search questions...', 'cleversay'); ?>"
                   style="min-width:220px;">
            <?php submit_button(__('Filter', 'cleversay'), 'action', '', false); ?>
            <?php if (!empty($filter_type) || !empty($filter_date) || !empty($filter_lang) || !empty($search)): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-questions')); ?>" class="button"><?php esc_html_e('Clear', 'cleversay'); ?></a>
            <?php endif; ?>
        </form>
        <div class="tablenav-pages" style="float:right;margin-top:6px;">
            <span class="displaying-num"><?php printf(
                esc_html(_n('%s question', '%s questions', $total, 'cleversay')),
                number_format($total)
            ); ?></span>
        </div>
        <br class="clear">
    </div>
    
    <!-- Questions Table -->
    <div class="cleversay-table-card" style="padding:0;overflow:hidden;">
    <table class="wp-list-table widefat fixed striped cleversay-table">
        <thead>
            <tr>
                <th class="column-question"><?php esc_html_e('Question', 'cleversay'); ?></th>
                <th class="column-matched"><?php esc_html_e('Matched Keyword', 'cleversay'); ?></th>
                <th class="column-type"><?php esc_html_e('Match Type', 'cleversay'); ?></th>
                <th class="column-score"><?php esc_html_e('Score', 'cleversay'); ?></th>
                <th class="column-date"><?php esc_html_e('Date', 'cleversay'); ?></th>
                <th class="column-actions"><?php esc_html_e('Actions', 'cleversay'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($questions)): ?>
                <tr>
                    <td colspan="7" class="no-items">
                        <?php esc_html_e('No questions found.', 'cleversay'); ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($questions as $q): ?>
                    <tr data-id="<?php echo esc_attr($q['id']); ?>">
                        <td class="column-question">
                            <strong><?php echo esc_html($q['question']); ?></strong>
                            <?php if (!empty($q['detected_language']) && $q['detected_language'] !== 'en' && !empty($q['original_question'])): ?>
                                <div style="margin-top:4px;display:flex;align-items:flex-start;gap:6px;padding:6px 8px;background:#f0f6fc;border-left:3px solid #72aee6;border-radius:3px;font-size:12px;">
                                    <span style="font-weight:600;color:#2271b1;text-transform:uppercase;font-size:10px;letter-spacing:.04em;flex-shrink:0;padding-top:1px;">
                                        <?php echo esc_html(strtoupper($q['detected_language'])); ?>
                                    </span>
                                    <span style="color:#50575e;font-style:italic;">
                                        <?php echo esc_html($q['original_question']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="column-matched">
                            <?php if (!empty($q['matched_keyword'])): ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-knowledge&s=' . urlencode($q['matched_keyword']))); ?>">
                                    <?php echo esc_html($q['matched_keyword']); ?>
                                </a>
                                <?php if (!empty($q['matched_sub_keyword'])): ?>
                                    <br><small><?php echo esc_html($q['matched_sub_keyword']); ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="column-type">
                            <?php if (!empty($q['match_type']) && $q['match_type'] !== 'none'): ?>
                                <span class="cs-badge cs-badge-success"><?php echo esc_html(ucfirst($q['match_type'])); ?></span>
                            <?php else: ?>
                                <span class="cs-badge cs-badge-warning"><?php esc_html_e('No Match', 'cleversay'); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($q['ai_rejected'])): ?>
                                <?php
                                $is_aadefault = ($q['ai_rejection_reason'] ?? '') === 'aadefault';
                                $tip = $is_aadefault
                                    ? esc_attr__('AI rejected this aadefault KB answer — the generic fallback didn\'t fit the specific question.', 'cleversay')
                                    : esc_attr__('AI flagged the KB answer as not matching this question well.', 'cleversay');
                                ?>
                                <span class="cs-badge cs-badge-ai-rejected" title="<?php echo $tip; ?>"
                                      style="background:#FEE2E2;color:#991B1B;margin-left:4px;display:inline-flex;align-items:center;gap:3px;">
                                    <?php echo \CleverSay\Icons::render('alert-triangle', 11); ?>
                                    <?php if ($is_aadefault): ?>
                                        <?php esc_html_e('AI rejected (aadefault)', 'cleversay'); ?>
                                    <?php else: ?>
                                        <?php esc_html_e('AI rejected', 'cleversay'); ?>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="column-score">
                            <?php if (!empty($q['match_score'])): ?>
                                <?php echo esc_html($q['match_score']); ?>%
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="column-date">
                            <abbr title="<?php echo esc_attr($q['created_at']); ?>">
                                <?php echo esc_html(human_time_diff(strtotime($q['created_at']), current_time('timestamp'))); ?>
                                <?php esc_html_e('ago', 'cleversay'); ?>
                            </abbr>
                        </td>
                        <td class="column-actions">
                            <?php
                            $ai_row = $ai_served[(int) $q['id']] ?? null;
                            $kb_text = (isset($q['knowledge_id']) && isset($knowledge_data[$q['knowledge_id']]))
                                ? $knowledge_data[$q['knowledge_id']]['response']
                                : '';
                            ?>
                            <button type="button" 
                                class="button button-small cleversay-view-entry"
                                data-question="<?php echo esc_attr($q['question']); ?>"
                                data-original-question="<?php echo esc_attr($q['original_question'] ?? ''); ?>"
                                data-detected-language="<?php echo esc_attr($q['detected_language'] ?? ''); ?>"
                                data-matched-keyword="<?php echo esc_attr($q['matched_keyword'] ?? ''); ?>"
                                data-matched-sub-keyword="<?php echo esc_attr($q['matched_sub_keyword'] ?? ''); ?>"
                                data-match-type="<?php echo esc_attr($q['match_type'] ?? 'none'); ?>"
                                data-match-score="<?php echo esc_attr($q['match_score'] ?? 0); ?>"
                                data-date="<?php echo esc_attr($q['created_at']); ?>"
                                data-response="<?php echo esc_attr($kb_text); ?>"
                                data-ai-answer="<?php echo esc_attr($ai_row['answer'] ?? ''); ?>"
                                data-ai-rejected="<?php echo esc_attr($ai_row['kb_rejected'] ?? 0); ?>"
                                data-ai-rejected-kb="<?php echo esc_attr($ai_row['rejected_kb_answer'] ?? ''); ?>"
                                data-ai-rejected-keyword="<?php echo esc_attr($ai_row['rejected_keyword'] ?? ''); ?>"
                                data-ai-rejected-reason="<?php echo esc_attr($ai_row['rejected_reason'] ?? ''); ?>"
                                data-knowledge-id="<?php echo esc_attr($q['knowledge_id'] ?? ''); ?>">
                                <?php esc_html_e('View Details', 'cleversay'); ?>
                            </button>
                            <?php if (empty($q['matched_keyword'])): ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-knowledge&action=new&question=' . urlencode($q['question']))); ?>" 
                                   class="button button-small button-primary" 
                                   title="<?php esc_attr_e('Create answer for this question', 'cleversay'); ?>">
                                    <?php esc_html_e('Create Answer', 'cleversay'); ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div><!-- /.cleversay-table-card -->

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php printf(
                        esc_html__('Page %1$s of %2$s', 'cleversay'),
                        number_format($paged),
                        number_format($total_pages)
                    ); ?>
                </span>
                
                <span class="pagination-links">
                    <?php if ($paged > 1): ?>
                        <a class="first-page button" href="<?php echo esc_url(add_query_arg('paged', 1)); ?>">
                            <span class="screen-reader-text"><?php esc_html_e('First page', 'cleversay'); ?></span>
                            <span aria-hidden="true">«</span>
                        </a>
                        <a class="prev-page button" href="<?php echo esc_url(add_query_arg('paged', $paged - 1)); ?>">
                            <span class="screen-reader-text"><?php esc_html_e('Previous page', 'cleversay'); ?></span>
                            <span aria-hidden="true">‹</span>
                        </a>
                    <?php else: ?>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>
                    <?php endif; ?>
                    
                    <span class="paging-input">
                        <span class="tablenav-paging-text">
                            <?php echo esc_html($paged); ?> / <?php echo esc_html($total_pages); ?>
                        </span>
                    </span>
                    
                    <?php if ($paged < $total_pages): ?>
                        <a class="next-page button" href="<?php echo esc_url(add_query_arg('paged', $paged + 1)); ?>">
                            <span class="screen-reader-text"><?php esc_html_e('Next page', 'cleversay'); ?></span>
                            <span aria-hidden="true">›</span>
                        </a>
                        <a class="last-page button" href="<?php echo esc_url(add_query_arg('paged', $total_pages)); ?>">
                            <span class="screen-reader-text"><?php esc_html_e('Last page', 'cleversay'); ?></span>
                            <span aria-hidden="true">»</span>
                        </a>
                    <?php else: ?>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Common Unmatched Questions -->
    <?php
    $unmatched = $wpdb->get_results(
        "SELECT 
            LOWER(TRIM(question)) as q,
            question,
            COUNT(*) as cnt
         FROM {$table}
         WHERE match_type IS NULL OR match_type = 'none'
         GROUP BY LOWER(TRIM(question))
         HAVING cnt >= 2
         ORDER BY cnt DESC
         LIMIT 10",
        ARRAY_A
    );
    
    if (!empty($unmatched)):
    ?>
        <div class="cleversay-table-card" style="padding:20px 22px 0;">
            <h2><?php esc_html_e('Frequently Unmatched Questions', 'cleversay'); ?></h2>
            <p class="description"><?php esc_html_e('These questions were asked multiple times but found no match. Consider adding answers for them.', 'cleversay'); ?></p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Question', 'cleversay'); ?></th>
                        <th class="column-small"><?php esc_html_e('Times Asked', 'cleversay'); ?></th>
                        <th class="column-small"><?php esc_html_e('Action', 'cleversay'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($unmatched as $u): ?>
                        <tr>
                            <td><?php echo esc_html($u['question']); ?></td>
                            <td><?php echo esc_html($u['cnt']); ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-knowledge&action=new&question=' . urlencode($u['question']))); ?>" 
                                   class="button button-primary button-small">
                                    <?php esc_html_e('Create Answer', 'cleversay'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div><!-- /.cleversay-table-card -->
    <?php endif; ?>
</div>

<!-- Question Details Modal -->
<div id="cleversay-question-modal" class="cleversay-modal" style="display: none;">
    <div class="cleversay-modal-overlay"></div>
    <div class="cleversay-modal-content">
        <div class="cleversay-modal-header">
            <h2><?php esc_html_e('Question Details', 'cleversay'); ?></h2>
            <button type="button" class="cleversay-modal-close" aria-label="<?php esc_attr_e('Close', 'cleversay'); ?>">
                <?php echo \CleverSay\Icons::render('x-circle', 16); ?>
            </button>
        </div>
        <div class="cleversay-modal-body">
            <div class="cleversay-detail-section">
                <h3><?php echo \CleverSay\Icons::render('help-circle', 16); ?> <?php esc_html_e('Question Asked', 'cleversay'); ?></h3>
                <div id="modal-question" class="cleversay-detail-value question-text"></div>
            </div>
            
            <div class="cleversay-detail-section" id="modal-response-section">
                <h3><?php echo \CleverSay\Icons::render('message-circle', 16); ?> <?php esc_html_e('Response Given', 'cleversay'); ?></h3>

                <!-- Single-response mode (no AI involvement OR AI == KB) -->
                <div id="modal-single-response" style="display:none;">
                    <div id="modal-response" class="cleversay-detail-value response-text"></div>
                </div>

                <!-- Two-panel mode: KB left (what KB would say), AI right (what was served) -->
                <div id="modal-comparison" class="cs-compare-grid" style="display:none;">
                    <div class="cs-compare-kb">
                        <div class="cs-compare-header">
                            <?php echo \CleverSay\Icons::render('x-circle', 12); ?>
                            <span><?php esc_html_e('KB answer', 'cleversay'); ?></span>
                            <span id="modal-kb-tag" class="cs-compare-tag"></span>
                        </div>
                        <div id="modal-kb-text" class="cs-compare-text"></div>
                    </div>
                    <div class="cs-compare-ai">
                        <div class="cs-compare-header">
                            <?php echo \CleverSay\Icons::render('sparkles', 12); ?>
                            <span><?php esc_html_e('AI answer (served)', 'cleversay'); ?></span>
                            <span id="modal-ai-tag" class="cs-compare-tag"></span>
                        </div>
                        <div id="modal-ai-text" class="cs-compare-text"></div>
                    </div>
                </div>
            </div>
            
            <div class="cleversay-detail-grid">
                <div class="cleversay-detail-item">
                    <span class="detail-label"><?php esc_html_e('Matched Keyword', 'cleversay'); ?></span>
                    <span id="modal-keyword" class="detail-value"></span>
                </div>
                <div class="cleversay-detail-item">
                    <span class="detail-label"><?php esc_html_e('Sub-Keyword', 'cleversay'); ?></span>
                    <span id="modal-subkeyword" class="detail-value"></span>
                </div>
                <div class="cleversay-detail-item">
                    <span class="detail-label"><?php esc_html_e('Match Type', 'cleversay'); ?></span>
                    <span id="modal-matchtype" class="detail-value"></span>
                </div>
                <div class="cleversay-detail-item">
                    <span class="detail-label"><?php esc_html_e('Match Score', 'cleversay'); ?></span>
                    <span id="modal-score" class="detail-value"></span>
                </div>
                <div class="cleversay-detail-item">
                    <span class="detail-label"><?php esc_html_e('Date & Time', 'cleversay'); ?></span>
                    <span id="modal-date" class="detail-value"></span>
                </div>
                <div class="cleversay-detail-item">
                </div>
            </div>
        </div>
        <div class="cleversay-modal-footer">
            <span id="modal-knowledge-link"></span>
            <button type="button" class="button cleversay-modal-close-btn"><?php esc_html_e('Close', 'cleversay'); ?></button>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Open modal
    $('.cleversay-view-entry').on('click', function() {
        var $btn = $(this);
        var modal = $('#cleversay-question-modal');
        
        // Populate modal with data
        $('#modal-question').text($btn.data('question'));
        
        var kbText     = ($btn.data('response') || '').toString();
        var aiText     = ($btn.data('ai-answer') || '').toString();
        var aiRejected = parseInt($btn.data('ai-rejected'), 10) === 1;
        var rejectedKb = ($btn.data('ai-rejected-kb') || '').toString();
        var rejectedKeyword = ($btn.data('ai-rejected-keyword') || '').toString();
        var rejectedReason  = ($btn.data('ai-rejected-reason') || '').toString();

        // Decide: two-panel (comparison) vs single-response mode.
        // Show comparison when AI was involved AND produced different text
        // than the KB — this covers both rejection and replacement/polish.
        var showComparison = false;
        var leftKbText     = kbText;
        var leftKbTag      = '';
        var rightAiText    = aiText;

        if (aiRejected && rejectedKb) {
            // AI rejected the KB entirely — show what KB would have said vs. AI's replacement
            showComparison = true;
            leftKbText = rejectedKb;
            var tagBits = [];
            if (rejectedKeyword) tagBits.push(rejectedKeyword);
            if (rejectedReason)  tagBits.push(rejectedReason);
            leftKbTag = tagBits.join(' / ');
        } else if (aiText && kbText && aiText.replace(/\s+/g, ' ').trim() !== kbText.replace(/\s+/g, ' ').trim()) {
            // AI polished / reworded a KB answer — still worth comparing
            showComparison = true;
            leftKbText = kbText;
        }

        if (showComparison) {
            $('#modal-single-response').hide();
            $('#modal-comparison').show();
            $('#modal-kb-text').html(leftKbText || '<em><?php esc_html_e('No KB text', 'cleversay'); ?></em>');
            $('#modal-kb-tag').text(leftKbTag);
            $('#modal-ai-text').html(rightAiText);
            $('#modal-ai-tag').text('served');
            $('#modal-response-section').show();
        } else {
            $('#modal-comparison').hide();
            $('#modal-single-response').show();
            // Prefer the AI-served answer if present, else the KB text
            var served = aiText || kbText;
            if (served) {
                $('#modal-response').html(served);
            } else {
                $('#modal-response').html('<em><?php esc_html_e('No response - question was not matched', 'cleversay'); ?></em>');
            }
            $('#modal-response-section').show();
        }
        
        var keyword = $btn.data('matched-keyword');
        $('#modal-keyword').text(keyword || '—');
        $('#modal-subkeyword').text($btn.data('matched-sub-keyword') || '—');
        
        var matchType = $btn.data('match-type');
        if (matchType && matchType !== 'none') {
            $('#modal-matchtype').html('<span class="cs-badge cs-badge-success">' + matchType.charAt(0).toUpperCase() + matchType.slice(1) + '</span>');
        } else {
            $('#modal-matchtype').html('<span class="cs-badge cs-badge-warning"><?php esc_html_e('No Match', 'cleversay'); ?></span>');
        }
        
        var score = $btn.data('match-score');
        $('#modal-score').text(score ? score + '%' : '—');
        $('#modal-date').text($btn.data('date'));
        
        // Knowledge link
        var knowledgeId = $btn.data('knowledge-id');
        var matchedKeyword = $btn.data('matched-keyword');
        if (knowledgeId) {
            var editUrl;
            if (matchedKeyword) {
                editUrl = '<?php echo esc_url(admin_url('admin.php?page=cleversay-knowledge&action=edit-phrase-group')); ?>'
                        + '&keyword=' + encodeURIComponent(matchedKeyword)
                        + '&group_id=' + encodeURIComponent(knowledgeId);
            } else {
                editUrl = '<?php echo esc_url(admin_url('admin.php?page=cleversay-knowledge&action=edit&id=')); ?>' + knowledgeId;
            }
            $('#modal-knowledge-link').html('<a href="' + editUrl + '" class="button"><?php esc_html_e('Edit Knowledge Entry', 'cleversay'); ?></a>');
        } else {
            $('#modal-knowledge-link').html('<a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-knowledge&action=new&question=')); ?>' + encodeURIComponent($btn.data('question')) + '" class="button button-primary"><?php esc_html_e('Create Answer', 'cleversay'); ?></a>');
        }
        
        modal.fadeIn(200);
        $('body').addClass('cleversay-modal-open');
    });
    
    // Close modal
    $('.cleversay-modal-close, .cleversay-modal-close-btn, .cleversay-modal-overlay').on('click', function() {
        $('#cleversay-question-modal').fadeOut(200);
        $('body').removeClass('cleversay-modal-open');
    });
    
    // Close on escape key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('#cleversay-question-modal').is(':visible')) {
            $('#cleversay-question-modal').fadeOut(200);
            $('body').removeClass('cleversay-modal-open');
        }
    });
});
</script>

<style>
/* Column widths */
.column-question { width: 32%; }
.column-matched  { width: 16%; }
.column-type     { width: 10%; }
.column-score    { width: 8%; }
.column-date     { width: 10%; }
.column-actions  { width: 11%; }
.column-small    { width: 15%; }

/* Badges */
.cs-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
    line-height: 1.8;
}
.cs-badge-success { background:#d1fae5; color:#065f46; }
.cs-badge-warning { background:#fef3c7; color:#92400e; }

.text-muted { color: #a7aaad; }

/* Modal */
body.cleversay-modal-open { overflow: hidden; }
.cleversay-modal {
    position: fixed; inset: 0;
    z-index: 100100;
    background: rgba(0,0,0,.5);
    display: flex; align-items: center; justify-content: center;
}
.cleversay-modal-overlay { position: absolute; inset: 0; }
.cleversay-modal-content {
    position: relative;
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 4px 24px rgba(0,0,0,.18);
    width: 600px;
    max-width: calc(100% - 40px);
    max-height: calc(100vh - 80px);
    display: flex; flex-direction: column; overflow: hidden;
}
.cleversay-modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 14px 20px;
    border-bottom: 1px solid #dcdcde;
    flex-shrink: 0;
}
.cleversay-modal-header h2 { margin: 0; font-size: 15px; }
.cleversay-modal-close {
    background: none; border: none; cursor: pointer;
    padding: 4px; color: #646970; border-radius: 4px; line-height: 1;
}
.cleversay-modal-close:hover { background: #f0f0f1; color: #1d2327; }
.cleversay-modal-body { padding: 20px; overflow-y: auto; flex: 1; }
.cleversay-modal-footer {
    display: flex; justify-content: space-between; align-items: center;
    padding: 12px 20px;
    border-top: 1px solid #dcdcde;
    background: #f6f7f7;
    flex-shrink: 0;
}
.cleversay-detail-section { margin-bottom: 18px; }
.cleversay-detail-section:last-child { margin-bottom: 0; }
.cleversay-detail-section h3 {
    font-size: 12px; font-weight: 600; color: #646970;
    text-transform: uppercase; letter-spacing: .04em;
    margin: 0 0 6px 0;
}
.cleversay-detail-value {
    background: #f6f7f7; border: 1px solid #dcdcde;
    border-radius: 4px; padding: 10px 12px;
    line-height: 1.5; word-wrap: break-word; font-size: 13px;
}
.cleversay-detail-grid {
    display: grid; grid-template-columns: repeat(2,1fr); gap: 10px;
}
.cleversay-detail-item { display: flex; flex-direction: column; gap: 3px; }
.cleversay-detail-item .detail-label {
    font-size: 11px; font-weight: 600; color: #646970; text-transform: uppercase;
}
.cleversay-detail-item .detail-value { font-size: 13px; color: #1d2327; }
.no-items { text-align: center; color: #646970; padding: 20px !important; }
.cleversay-section {
    margin-top: 24px;
    background: #fff; border: 1px solid #c3c4c7;
    padding: 20px; border-radius: 4px;
}
.cleversay-section h2 { margin-top: 0; }

/* ── KB vs AI comparison grid ── */
.cs-compare-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    border: 1px solid #dcdcde;
    border-radius: 6px;
    overflow: hidden;
}
.cs-compare-kb {
    background: #FEF2F2;
    border-right: 1px solid #F5C2C2;
    padding: 12px 14px;
}
.cs-compare-ai {
    background: #F0FDF4;
    padding: 12px 14px;
}
.cs-compare-header {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    margin-bottom: 8px;
}
.cs-compare-kb .cs-compare-header { color: #991B1B; }
.cs-compare-ai .cs-compare-header { color: #166534; }
.cs-compare-tag {
    margin-left: auto;
    font-family: monospace;
    font-weight: 500;
    font-size: 10px;
    opacity: 0.8;
    text-transform: none;
    letter-spacing: 0;
}
.cs-compare-text {
    font-size: 13px;
    line-height: 1.55;
    color: #1d2327;
    max-height: 240px;
    overflow: auto;
    word-wrap: break-word;
}

@media (max-width: 600px) {
    .cleversay-detail-grid { grid-template-columns: 1fr; }
    .cleversay-modal-content { width: calc(100% - 20px); }
}

/* Stack KB/AI panels vertically in narrow modals */
@media (max-width: 780px) {
    .cs-compare-grid {
        grid-template-columns: 1fr;
    }
    .cs-compare-kb {
        border-right: none;
        border-bottom: 1px solid #F5C2C2;
    }
}
</style>
