<?php
/**
 * Admin Dashboard View
 *
 * @package CleverSay
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

global $wpdb;

// Get statistics
$knowledge_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cleversay_knowledge");
$active_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cleversay_knowledge WHERE status = 'active'");

// Use WordPress current_time for consistent timezone handling
$today = current_time('Y-m-d');
$week_ago = date('Y-m-d', strtotime('-7 days', current_time('timestamp')));

$questions_today = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}cleversay_questions WHERE DATE(created_at) = %s",
    $today
));
$questions_week = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}cleversay_questions WHERE DATE(created_at) >= %s",
    $week_ago
));
$visitors_today = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}cleversay_visitors WHERE DATE(last_visit) = %s",
    $today
));
$unanswered_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cleversay_inquiries WHERE status = 'pending'");
$avg_match_score = $wpdb->get_var("SELECT AVG(match_score) FROM {$wpdb->prefix}cleversay_questions WHERE match_score > 0");

// Ensure we have numeric values
$questions_today = (int) $questions_today;
$questions_week = (int) $questions_week;
$visitors_today = (int) $visitors_today;
$unanswered_count = (int) $unanswered_count;
$avg_match_score = $avg_match_score ? round((float) $avg_match_score, 1) : 0;

// ── Conversation CSAT stats (last 30 days) ─────────────────────────────────
$csat_table = $wpdb->prefix . 'cleversay_conversation_ratings';
$csat_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $csat_table)) === $csat_table;
$csat_total = 0;
$csat_helpful = 0;
$csat_somewhat = 0;
$csat_not_helpful = 0;
$csat_pct_helpful = 0;
if ($csat_exists) {
    $cutoff = date('Y-m-d H:i:s', strtotime('-30 days', current_time('timestamp')));
    $csat_row = $wpdb->get_row($wpdb->prepare(
        "SELECT
            COUNT(*)                                               AS total,
            SUM(CASE WHEN rating='helpful'     THEN 1 ELSE 0 END) AS helpful,
            SUM(CASE WHEN rating='somewhat'    THEN 1 ELSE 0 END) AS somewhat,
            SUM(CASE WHEN rating='not_helpful' THEN 1 ELSE 0 END) AS not_helpful
         FROM {$csat_table}
         WHERE created_at >= %s",
        $cutoff
    ), ARRAY_A);
    if ($csat_row) {
        $csat_total       = (int) $csat_row['total'];
        $csat_helpful     = (int) $csat_row['helpful'];
        $csat_somewhat    = (int) $csat_row['somewhat'];
        $csat_not_helpful = (int) $csat_row['not_helpful'];
        $csat_pct_helpful = $csat_total > 0
            ? round(($csat_helpful / $csat_total) * 100)
            : 0;
    }
}

// ── Language breakdown (last 30 days) ───────────────────────────────────────
$lang_cutoff = date('Y-m-d H:i:s', strtotime('-30 days', current_time('timestamp')));
$lang_breakdown_rows = $wpdb->get_results($wpdb->prepare(
    "SELECT
        COALESCE(NULLIF(detected_language, ''), 'en') AS lang,
        COUNT(*) AS n
     FROM {$wpdb->prefix}cleversay_questions
     WHERE created_at >= %s
     GROUP BY COALESCE(NULLIF(detected_language, ''), 'en')
     ORDER BY n DESC",
    $lang_cutoff
), ARRAY_A) ?: [];
$lang_breakdown_total = array_sum(array_map(fn($r) => (int) $r['n'], $lang_breakdown_rows));
$lang_non_en_total = array_sum(array_map(
    fn($r) => $r['lang'] !== 'en' ? (int) $r['n'] : 0,
    $lang_breakdown_rows
));
$lang_non_en_pct = $lang_breakdown_total > 0
    ? round(($lang_non_en_total / $lang_breakdown_total) * 100)
    : 0;

// Get recent questions
$recent_questions = $wpdb->get_results(
    "SELECT q.*, k.keyword 
     FROM {$wpdb->prefix}cleversay_questions q
     LEFT JOIN {$wpdb->prefix}cleversay_knowledge k ON q.knowledge_id = k.id
     ORDER BY q.created_at DESC 
     LIMIT 10"
);

// Get top keywords by hits
$top_keywords = $wpdb->get_results(
    "SELECT keyword, hits,
            helpful_yes, helpful_no,
            CASE WHEN (helpful_yes + helpful_no) > 0
                 THEN ROUND(helpful_yes / (helpful_yes + helpful_no) * 100)
                 ELSE NULL END AS rate
     FROM {$wpdb->prefix}cleversay_knowledge
     WHERE status = 'active'
     ORDER BY hits DESC
     LIMIT 10"
);

// Get questions per day for chart (last 14 days)
$daily_questions = $wpdb->get_results(
    "SELECT DATE(created_at) as date, COUNT(*) as count 
     FROM {$wpdb->prefix}cleversay_questions 
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
     GROUP BY DATE(created_at) 
     ORDER BY date ASC"
);

// Get match type distribution
$match_types = $wpdb->get_results(
    "SELECT match_type, COUNT(*) as count 
     FROM {$wpdb->prefix}cleversay_questions 
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY match_type"
);
?>

<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline">
        <?php echo \CleverSay\Icons::render('message-circle', 16); ?>
        <?php esc_html_e('CleverSay Dashboard', 'cleversay'); ?>
    </h1>
    
    <hr class="wp-header-end">

    <!-- Quick Actions - Now at top -->
    <div class="cleversay-quick-actions-bar">
        <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-knowledge&action=new')); ?>" class="button button-primary">
            <?php echo \CleverSay\Icons::render('plus', 16); ?>
            <?php esc_html_e('Add Knowledge Entry', 'cleversay'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-synonyms')); ?>" class="button">
            <?php echo \CleverSay\Icons::render('check-circle', 16); ?>
            <?php esc_html_e('Manage Synonyms', 'cleversay'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-import')); ?>" class="button">
            <?php echo \CleverSay\Icons::render('upload', 16); ?>
            <?php esc_html_e('Import Data', 'cleversay'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-settings')); ?>" class="button">
            <?php echo \CleverSay\Icons::render('settings', 16); ?>
            <?php esc_html_e('Settings', 'cleversay'); ?>
        </a>
    </div>

    <!-- Stats Cards - 4 column grid -->
    <div class="cleversay-stats-row">
        <div class="cleversay-stat-box">
            <div class="stat-icon" style="background: #3b82f6;">
                <?php echo \CleverSay\Icons::render('book-open', 16); ?>
            </div>
            <div class="stat-info">
                <span class="stat-number"><?php echo esc_html(number_format_i18n($knowledge_count)); ?></span>
                <span class="stat-label"><?php esc_html_e('Knowledge Entries', 'cleversay'); ?></span>
                <span class="stat-meta"><?php echo esc_html(number_format_i18n($active_count)); ?> <?php esc_html_e('active', 'cleversay'); ?></span>
            </div>
        </div>

        <div class="cleversay-stat-box">
            <div class="stat-icon" style="background: #22c55e;">
                <?php echo \CleverSay\Icons::render('help-circle', 16); ?>
            </div>
            <div class="stat-info">
                <span class="stat-number"><?php echo esc_html(number_format_i18n($questions_today)); ?></span>
                <span class="stat-label"><?php esc_html_e('Questions Today', 'cleversay'); ?></span>
                <span class="stat-meta"><?php echo esc_html(number_format_i18n($questions_week)); ?> <?php esc_html_e('this week', 'cleversay'); ?></span>
            </div>
        </div>

        <div class="cleversay-stat-box">
            <div class="stat-icon" style="background: #8b5cf6;">
                <?php echo \CleverSay\Icons::render('users', 16); ?>
            </div>
            <div class="stat-info">
                <span class="stat-number"><?php echo esc_html(number_format_i18n($visitors_today)); ?></span>
                <span class="stat-label"><?php esc_html_e('Visitors Today', 'cleversay'); ?></span>
                <span class="stat-meta">
                    <?php 
                    if ($avg_match_score) {
                        printf(esc_html__('Avg match: %s%%', 'cleversay'), number_format_i18n($avg_match_score, 1));
                    } else {
                        echo '&nbsp;';
                    }
                    ?>
                </span>
            </div>
        </div>

        <div class="cleversay-stat-box <?php echo $unanswered_count > 0 ? 'has-alert' : ''; ?>">
            <div class="stat-icon" style="background: <?php echo $unanswered_count > 0 ? '#ef4444' : '#f59e0b'; ?>;">
                <?php echo \CleverSay\Icons::render('mail', 16); ?>
            </div>
            <div class="stat-info">
                <span class="stat-number"><?php echo esc_html(number_format_i18n($unanswered_count)); ?></span>
                <span class="stat-label"><?php esc_html_e('Pending Inquiries', 'cleversay'); ?></span>
                <span class="stat-meta">
                    <?php if ($unanswered_count > 0): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-inquiries')); ?>"><?php esc_html_e('View all', 'cleversay'); ?> →</a>
                    <?php else: ?>
                        &nbsp;
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <div class="cleversay-stat-box">
            <div class="stat-icon" style="background: #06B6D4;">
                <?php echo \CleverSay\Icons::render('thumbs-up', 16); ?>
            </div>
            <div class="stat-info">
                <span class="stat-number">
                    <?php if ($csat_total > 0): ?>
                        <?php echo esc_html($csat_pct_helpful); ?>%
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </span>
                <span class="stat-label"><?php esc_html_e('Conversation CSAT', 'cleversay'); ?></span>
                <span class="stat-meta">
                    <?php if ($csat_total > 0): ?>
                        <?php printf(
                            esc_html(_n('%s rating (30d)', '%s ratings (30d)', $csat_total, 'cleversay')),
                            number_format_i18n($csat_total)
                        ); ?>
                    <?php else: ?>
                        <?php esc_html_e('No ratings yet', 'cleversay'); ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <div class="cleversay-stat-box">
            <div class="stat-icon" style="background: #EC4899;">
                <?php echo \CleverSay\Icons::render('globe', 16); ?>
            </div>
            <div class="stat-info">
                <span class="stat-number">
                    <?php if ($lang_non_en_total > 0): ?>
                        <?php echo esc_html($lang_non_en_pct); ?>%
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </span>
                <span class="stat-label"><?php esc_html_e('Non-English Traffic', 'cleversay'); ?></span>
                <span class="stat-meta">
                    <?php if (!empty($lang_breakdown_rows) && $lang_non_en_total > 0):
                        // Build a compact "ES 42 · FR 7 · ZH 3" string, non-English only, top 3
                        $parts = [];
                        foreach ($lang_breakdown_rows as $row) {
                            if ($row['lang'] === 'en') continue;
                            $parts[] = strtoupper($row['lang']) . ' ' . number_format_i18n((int) $row['n']);
                            if (count($parts) >= 3) break;
                        }
                    ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-questions&lang=non_en')); ?>">
                            <?php echo esc_html(implode(' · ', $parts)); ?>
                        </a>
                    <?php else: ?>
                        <?php esc_html_e('All English (30d)', 'cleversay'); ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="cleversay-charts-row">
        <div class="cleversay-chart-card">
            <h3><?php echo \CleverSay\Icons::render('bar-chart', 16); ?> <?php esc_html_e('Questions - Last 14 Days', 'cleversay'); ?></h3>
            <div style="position: relative; height: 200px;">
                <canvas id="cleversay-questions-chart"></canvas>
            </div>
        </div>

        <div class="cleversay-chart-card">
            <h3><?php echo \CleverSay\Icons::render('pie-chart', 16); ?> <?php esc_html_e('Match Types - Last 30 Days', 'cleversay'); ?></h3>
            <div style="position: relative; height: 200px;">
                <canvas id="cleversay-match-chart"></canvas>
            </div>
        </div>
    </div>

    <!-- Data Tables Row -->
    <div class="cleversay-tables-row">
        <!-- Recent Questions -->
        <div class="cleversay-table-card">
            <h3>
                <?php esc_html_e('Recent Questions', 'cleversay'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-questions')); ?>" class="view-all">
                    <?php esc_html_e('View All', 'cleversay'); ?>
                </a>
            </h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Question', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Matched', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Score', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Time', 'cleversay'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_questions)): ?>
                        <tr>
                            <td colspan="4" class="no-data">
                                <?php esc_html_e('No questions yet', 'cleversay'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_questions as $question): ?>
                            <tr>
                                <td class="question-text" title="<?php echo esc_attr($question->question); ?>">
                                    <?php echo esc_html(wp_trim_words($question->question, 8)); ?>
                                </td>
                                <td>
                                    <?php if ($question->keyword): ?>
                                        <span class="matched"><?php echo esc_html($question->keyword); ?></span>
                                    <?php else: ?>
                                        <span class="no-match">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($question->match_score > 0): ?>
                                        <span class="score score-<?php echo $question->match_score >= 80 ? 'high' : ($question->match_score >= 60 ? 'medium' : 'low'); ?>">
                                            <?php echo esc_html($question->match_score); ?>%
                                        </span>
                                    <?php else: ?>
                                        <span class="no-match">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="time-ago">
                                    <?php echo esc_html(human_time_diff(strtotime($question->created_at), current_time('timestamp'))); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Top Keywords -->
        <div class="cleversay-table-card">
            <h3>
                <?php esc_html_e('Top Keywords', 'cleversay'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-knowledge')); ?>" class="view-all">
                    <?php esc_html_e('View All', 'cleversay'); ?>
                </a>
            </h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Keyword', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Hits', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Rating', 'cleversay'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($top_keywords)): ?>
                        <tr>
                            <td colspan="3" class="no-data">
                                <?php esc_html_e('No data yet', 'cleversay'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($top_keywords as $keyword): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($keyword->keyword); ?></strong>
                                </td>
                                <td><?php echo esc_html(number_format_i18n($keyword->hits)); ?></td>
                                <td>
                                    <?php
                                    $rating = floatval($keyword->rate);
                                    $stars = round($rating);
                                    for ($i = 1; $i <= 5; $i++) {
                                        echo $i <= $stars ? '★' : '☆';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Chart.js from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Questions Chart Data
    const questionsData = <?php 
        $labels = [];
        $values = [];
        // Fill in missing days
        for ($i = 13; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $labels[] = date('M j', strtotime($date));
            $found = false;
            foreach ($daily_questions as $dq) {
                if ($dq->date === $date) {
                    $values[] = intval($dq->count);
                    $found = true;
                    break;
                }
            }
            if (!$found) $values[] = 0;
        }
        echo json_encode(['labels' => $labels, 'values' => $values]);
    ?>;

    // Match Types Data
    const matchData = <?php
        $match_labels = [];
        $match_values = [];
        $colors = [];
        $color_map = [
            'exact' => '#22c55e',
            'partial' => '#3b82f6', 
            'fuzzy' => '#f59e0b',
            'none' => '#ef4444'
        ];
        foreach ($match_types as $mt) {
            $match_labels[] = ucfirst($mt->match_type ?: 'none');
            $match_values[] = intval($mt->count);
            $colors[] = $color_map[$mt->match_type] ?? '#94a3b8';
        }
        echo json_encode(['labels' => $match_labels, 'values' => $match_values, 'colors' => $colors]);
    ?>;

    // Questions Line Chart
    if (document.getElementById('cleversay-questions-chart')) {
        new Chart(document.getElementById('cleversay-questions-chart'), {
            type: 'line',
            data: {
                labels: questionsData.labels,
                datasets: [{
                    label: '<?php esc_html_e('Questions', 'cleversay'); ?>',
                    data: questionsData.values,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    }
                }
            }
        });
    }

    // Match Types Doughnut Chart
    if (document.getElementById('cleversay-match-chart') && matchData.values.length > 0) {
        new Chart(document.getElementById('cleversay-match-chart'), {
            type: 'doughnut',
            data: {
                labels: matchData.labels,
                datasets: [{
                    data: matchData.values,
                    backgroundColor: matchData.colors
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right' }
                }
            }
        });
    }
});
</script>
