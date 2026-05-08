<?php
/**
 * Reports Admin View
 *
 * @package CleverSay
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get analytics data
$analytics = new \CleverSay\Analytics();
$stats = $analytics->get_dashboard_stats();
$period = sanitize_text_field($_GET['period'] ?? '30');
$trend = $analytics->get_questions_trend((int) $period);
$top_keywords = $analytics->get_top_keywords(10, (int) $period);
$unmatched = $analytics->get_unmatched_questions(10, (int) $period);
$hourly = $analytics->get_hourly_pattern(7);
$weekday = $analytics->get_weekday_pattern((int) $period);
$comparison = $analytics->get_performance_comparison((int) $period);

// ── AI Performance data ───────────────────────────────────────────────────────
global $wpdb;
$db = new \CleverSay\Database();
$days = (int) $period;

// AI vs KB vs No-answer split for the period
$ai_count = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$db->ai_answers}
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
    $days
));
$kb_count = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$db->questions_log}
     WHERE match_type != 'none'
     AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
    $days
));
$no_answer_count = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$db->questions_log}
     WHERE match_type = 'none'
     AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
    $days
)) - $ai_count; // subtract AI-answered ones
$no_answer_count = max(0, $no_answer_count);
$total_split = $kb_count + $ai_count + $no_answer_count;

// Coverage gap score: % of questions that got a real answer (KB or AI)
$coverage_score = $total_split > 0
    ? round((($kb_count + $ai_count) / $total_split) * 100)
    : 0;
$kb_pct     = $total_split > 0 ? round(($kb_count    / $total_split) * 100) : 0;
$ai_pct     = $total_split > 0 ? round(($ai_count     / $total_split) * 100) : 0;
$no_ans_pct = $total_split > 0 ? round(($no_answer_count / $total_split) * 100) : 0;

// ── Geographic data ───────────────────────────────────────────────────────────
$geo_data    = $analytics->get_visitor_geography($days);
$has_geo     = !empty($geo_data);
$us_states   = $analytics->get_us_state_breakdown($days);
$us_cities   = $analytics->get_us_city_breakdown($days);
$filter_state = sanitize_text_field($_GET['state'] ?? '');
$us_cities_filtered = !empty($filter_state)
    ? $analytics->get_us_city_breakdown($days, $filter_state)
    : $us_cities;

?>

<div class="wrap cleversay-admin cleversay-reports">
    <h1 class="wp-heading-inline"><?php echo \CleverSay\Icons::render('bar-chart', 16); ?> <?php esc_html_e('Reports & Analytics', 'cleversay'); ?></h1>
    
    <!-- Period Selector -->
    <div class="period-selector">
        <label for="period-select"><?php esc_html_e('Time Period:', 'cleversay'); ?></label>
        <select id="period-select" onchange="window.location.href='<?php echo esc_url(admin_url('admin.php?page=cleversay-reports&period=')); ?>'+this.value">
            <option value="7" <?php selected($period, '7'); ?>><?php esc_html_e('Last 7 Days', 'cleversay'); ?></option>
            <option value="14" <?php selected($period, '14'); ?>><?php esc_html_e('Last 14 Days', 'cleversay'); ?></option>
            <option value="30" <?php selected($period, '30'); ?>><?php esc_html_e('Last 30 Days', 'cleversay'); ?></option>
            <option value="90" <?php selected($period, '90'); ?>><?php esc_html_e('Last 90 Days', 'cleversay'); ?></option>
        </select>
        
        <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-questions&export=csv')); ?>" class="button">
            <?php esc_html_e('Export Data', 'cleversay'); ?>
        </a>
    </div>
    
    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-value"><?php echo number_format($stats['questions_this_month']); ?></div>
            <div class="kpi-label"><?php esc_html_e('Questions', 'cleversay'); ?></div>
            <div class="kpi-change <?php echo $comparison['change']['total'] >= 0 ? 'positive' : 'negative'; ?>">
                <?php echo ($comparison['change']['total'] >= 0 ? '+' : '') . $comparison['change']['total']; ?>%
                <span><?php esc_html_e('vs previous period', 'cleversay'); ?></span>
            </div>
        </div>
        
        <div class="kpi-card">
            <div class="kpi-value"><?php echo $stats['match_rate']; ?>%</div>
            <div class="kpi-label"><?php esc_html_e('Match Rate', 'cleversay'); ?></div>
            <div class="kpi-change <?php echo $comparison['change']['match_rate'] >= 0 ? 'positive' : 'negative'; ?>">
                <?php echo ($comparison['change']['match_rate'] >= 0 ? '+' : '') . $comparison['change']['match_rate']; ?>%
                <span><?php esc_html_e('vs previous period', 'cleversay'); ?></span>
            </div>
        </div>
        
        <div class="kpi-card">
            <div class="kpi-value"><?php echo $stats['helpfulness_rate']; ?>%</div>
            <div class="kpi-label"><?php esc_html_e('Helpfulness Rate', 'cleversay'); ?></div>
            <div class="kpi-sublabel">
                <?php printf(
                    esc_html__('%d helpful / %d not helpful', 'cleversay'),
                    $stats['helpful_ratings'],
                    $stats['not_helpful_ratings']
                ); ?>
            </div>
        </div>
        
        <div class="kpi-card">
            <div class="kpi-value"><?php echo number_format($stats['unique_visitors_week']); ?></div>
            <div class="kpi-label"><?php esc_html_e('Unique Visitors', 'cleversay'); ?></div>
            <div class="kpi-sublabel"><?php esc_html_e('Last 7 days', 'cleversay'); ?></div>
        </div>

        <div class="kpi-card" style="border-top: 4px solid <?php echo $coverage_score >= 90 ? '#00a32a' : ($coverage_score >= 70 ? '#dba617' : '#d63638'); ?>;">
            <div class="kpi-value" style="color:<?php echo $coverage_score >= 90 ? '#00a32a' : ($coverage_score >= 70 ? '#dba617' : '#d63638'); ?>;">
                <?php echo $coverage_score; ?>%
            </div>
            <div class="kpi-label"><?php esc_html_e('Coverage Score', 'cleversay'); ?></div>
            <div class="kpi-sublabel">
                <?php
                if ($coverage_score >= 90) esc_html_e('Excellent coverage', 'cleversay');
                elseif ($coverage_score >= 70) esc_html_e('Good — room to improve', 'cleversay');
                else esc_html_e('Needs attention', 'cleversay');
                ?>
            </div>
        </div>
    </div>
    
    <!-- Charts Row -->
    <div class="charts-row">
        <!-- Questions Trend Chart -->
        <div class="chart-card chart-large">
            <h3><?php echo \CleverSay\Icons::render('trending-up', 16); ?> <?php esc_html_e('Questions Over Time', 'cleversay'); ?></h3>
            <canvas id="trend-chart"></canvas>
        </div>
        
        <!-- Match Rate Pie -->
        <div class="chart-card chart-small">
            <h3><?php echo \CleverSay\Icons::render('pie-chart', 16); ?> <?php esc_html_e('Match Distribution', 'cleversay'); ?></h3>
            <canvas id="match-pie"></canvas>
        </div>
    </div>
    
    <!-- Second Charts Row -->
    <div class="charts-row">
        <!-- Hourly Pattern -->
        <div class="chart-card chart-medium">
            <h3><?php echo \CleverSay\Icons::render('clock', 16); ?> <?php esc_html_e('Activity by Hour', 'cleversay'); ?></h3>
            <canvas id="hourly-chart"></canvas>
        </div>
        
        <!-- Weekday Pattern -->
        <div class="chart-card chart-medium">
            <h3><?php echo \CleverSay\Icons::render('calendar', 16); ?> <?php esc_html_e('Activity by Day', 'cleversay'); ?></h3>
            <canvas id="weekday-chart"></canvas>
        </div>
    </div>
    
    <!-- Tables Row -->
    <div class="tables-row">
        <!-- Top Keywords -->
        <div class="table-card">
            <h3><?php echo \CleverSay\Icons::render('star', 16); ?> <?php esc_html_e('Top Performing Keywords', 'cleversay'); ?></h3>
            <?php if (!empty($top_keywords)): ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Keyword', 'cleversay'); ?></th>
                            <th class="num"><?php esc_html_e('Hits', 'cleversay'); ?></th>
                            <th class="num"><?php esc_html_e('Rating', 'cleversay'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_keywords as $kw): ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-knowledge&s=' . urlencode($kw['keyword']))); ?>">
                                        <?php echo esc_html($kw['keyword']); ?>
                                    </a>
                                </td>
                                <td class="num"><?php echo number_format($kw['hits']); ?></td>
                                <td class="num">
                                    <span class="rating-bar">
                                        <span class="rating-fill" style="width: <?php echo esc_attr($kw['rating'] ?? 0); ?>%"></span>
                                    </span>
                                    <?php echo esc_html($kw['rating'] ?? 0); ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="no-data"><?php esc_html_e('No data available for this period.', 'cleversay'); ?></p>
            <?php endif; ?>
        </div>
        
        <!-- Unmatched Questions -->
        <div class="table-card">
            <h3><?php echo \CleverSay\Icons::render('help-circle', 16); ?> <?php esc_html_e('Common Unmatched Questions', 'cleversay'); ?></h3>
            <?php if (!empty($unmatched)): ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Question', 'cleversay'); ?></th>
                            <th class="num"><?php esc_html_e('Count', 'cleversay'); ?></th>
                            <th><?php esc_html_e('Action', 'cleversay'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unmatched as $q): ?>
                            <tr>
                                <td><?php echo esc_html(wp_trim_words($q['question'], 10)); ?></td>
                                <td class="num"><?php echo esc_html($q['count']); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-knowledge&action=new&question=' . urlencode($q['question']))); ?>" 
                                       class="button button-small">
                                        <?php esc_html_e('Create', 'cleversay'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="no-data"><?php esc_html_e('All questions are being matched!', 'cleversay'); ?></p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Category Performance -->
    <?php if (!empty($category_stats)): ?>
    <div class="category-section">
        <h3><?php echo \CleverSay\Icons::render('bar-chart', 16); ?> <?php esc_html_e('Category Performance', 'cleversay'); ?></h3>
        <div class="category-grid">
            <?php foreach ($category_stats as $cat): ?>
                <div class="category-card">
                    <h4><?php echo esc_html($cat['name']); ?></h4>
                    <div class="category-stats">
                        <div class="cat-stat">
                            <span class="cat-num"><?php echo number_format($cat['entry_count']); ?></span>
                            <span class="cat-label"><?php esc_html_e('Entries', 'cleversay'); ?></span>
                        </div>
                        <div class="cat-stat">
                            <span class="cat-num"><?php echo number_format($cat['total_hits'] ?? 0); ?></span>
                            <span class="cat-label"><?php esc_html_e('Hits', 'cleversay'); ?></span>
                        </div>
                        <div class="cat-stat">
                            <span class="cat-num"><?php echo round($cat['avg_rating'] ?? 0); ?>%</span>
                            <span class="cat-label"><?php esc_html_e('Rating', 'cleversay'); ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── AI Performance Section ─────────────────────────────────────── -->
    <div style="margin-top:32px;">
        <h2 style="font-size:18px;font-weight:600;margin-bottom:16px;padding-bottom:8px;border-bottom:1px solid #dcdcde;">
            <?php esc_html_e('AI Performance', 'cleversay'); ?>
        </h2>

        <div class="charts-row">
            <!-- AI vs KB Donut -->
            <div class="chart-card chart-small">
                <h3><?php echo \CleverSay\Icons::render('pie-chart', 16); ?> <?php esc_html_e('Answer Source Split', 'cleversay'); ?></h3>
                <?php if ($total_split > 0): ?>
                    <div style="position:relative;height:200px;display:flex;align-items:center;justify-content:center;">
                        <canvas id="ai-split-donut"></canvas>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:12px;font-size:13px;">
                        <span style="display:flex;align-items:center;gap:5px;">
                            <span style="width:12px;height:12px;border-radius:2px;background:#2271b1;display:inline-block;"></span>
                            <?php esc_html_e('KB Match', 'cleversay'); ?> <?php echo $kb_pct; ?>%
                        </span>
                        <span style="display:flex;align-items:center;gap:5px;">
                            <span style="width:12px;height:12px;border-radius:2px;background:#8B5CF6;display:inline-block;"></span>
                            <?php esc_html_e('AI Answered', 'cleversay'); ?> <?php echo $ai_pct; ?>%
                        </span>
                        <span style="display:flex;align-items:center;gap:5px;">
                            <span style="width:12px;height:12px;border-radius:2px;background:#d63638;display:inline-block;"></span>
                            <?php esc_html_e('No Answer', 'cleversay'); ?> <?php echo $no_ans_pct; ?>%
                        </span>
                    </div>
                    <p style="margin-top:12px;font-size:12px;color:#646970;">
                        <?php printf(
                            esc_html__('%d KB matches, %d AI answers, %d unanswered in this period.', 'cleversay'),
                            $kb_count, $ai_count, $no_answer_count
                        ); ?>
                    </p>
                <?php else: ?>
                    <p class="no-data"><?php esc_html_e('No data for this period.', 'cleversay'); ?></p>
                <?php endif; ?>
            </div>

            <!-- Coverage score explanation -->
            <div class="chart-card chart-small" style="display:flex;flex-direction:column;justify-content:center;">
                <h3><?php echo \CleverSay\Icons::render('alert-circle', 16); ?> <?php esc_html_e('Coverage Gap Analysis', 'cleversay'); ?></h3>
                <div style="font-size:48px;font-weight:700;text-align:center;color:<?php echo $coverage_score >= 90 ? '#00a32a' : ($coverage_score >= 70 ? '#dba617' : '#d63638'); ?>;">
                    <?php echo $coverage_score; ?>%
                </div>
                <p style="text-align:center;color:#646970;margin-top:4px;">
                    <?php esc_html_e('of questions got a real answer', 'cleversay'); ?>
                </p>
                <div style="margin-top:16px;font-size:13px;color:#3c434a;">
                    <?php if ($no_answer_count > 0): ?>
                    <p>⚠️ <?php printf(
                        esc_html__('%d questions went unanswered. Review the unmatched questions below and consider adding KB entries or AI sources to cover these topics.', 'cleversay'),
                        $no_answer_count
                    ); ?></p>
                    <?php else: ?>
                    <p>✅ <?php esc_html_e('All questions received an answer in this period.', 'cleversay'); ?></p>
                    <?php endif; ?>
                    <?php if ($ai_pct > 50): ?>
                    <p style="margin-top:8px;">💡 <?php esc_html_e('AI is answering more than half of questions. Consider promoting frequently AI-answered questions to your Knowledge Base.', 'cleversay'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <!-- ── Geographic Data ────────────────────────────────────────── -->
    <div style="margin-top:32px;">
        <h2 style="font-size:18px;font-weight:600;margin-bottom:16px;padding-bottom:8px;border-bottom:1px solid var(--cs-separator);">
            <?php esc_html_e('Visitor Geography', 'cleversay'); ?>
        </h2>

        <?php if ($has_geo): ?>
        <div class="charts-row">
            <!-- Bar chart of top countries -->
            <div class="chart-card chart-large">
                <h3><?php esc_html_e('Top Countries by Unique Visitors', 'cleversay'); ?>
                    <span style="font-size:11px;font-weight:400;color:var(--cs-text-tertiary);">
                        <?php esc_html_e('Bots and private IPs excluded', 'cleversay'); ?>
                    </span>
                </h3>
                <div style="position:relative;height:260px;">
                    <canvas id="geo-chart"></canvas>
                </div>
            </div>

            <!-- Top countries table -->
            <div class="chart-card chart-small">
                <h3><?php echo \CleverSay\Icons::render('list', 16); ?> <?php esc_html_e('Breakdown', 'cleversay'); ?></h3>
                <table class="widefat striped" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Country', 'cleversay'); ?></th>
                            <th style="text-align:right;"><?php esc_html_e('Visitors', 'cleversay'); ?></th>
                            <th style="text-align:right;"><?php esc_html_e('Visits', 'cleversay'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($geo_data, 0, 10) as $row): ?>
                        <tr>
                            <td>
                                <?php if (!empty($row['country_code'])): ?>
                                    <span style="font-size:18px;line-height:1;vertical-align:middle;margin-right:5px;">
                                        <?php
                                        // Convert country code to flag emoji
                                        $code = strtoupper($row['country_code']);
                                        $flag = implode('', array_map(fn($c) => mb_chr(0x1F1E0 + ord($c) - ord('A')), str_split($code)));
                                        echo esc_html($flag);
                                        ?>
                                    </span>
                                <?php endif; ?>
                                <?php echo esc_html($row['country_name'] ?: $row['country_code']); ?>
                            </td>
                            <td style="text-align:right;font-weight:600;"><?php echo number_format((int)$row['visitors']); ?></td>
                            <td style="text-align:right;color:var(--cs-text-tertiary);"><?php echo number_format((int)$row['total_visits']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="chart-card" style="padding:32px;text-align:center;color:var(--cs-text-tertiary);">
            <?php echo \CleverSay\Icons::render('map-pin', 16); ?>
            <p style="margin:0;font-size:13px;">
                <?php esc_html_e('No geographic data yet. Visitor locations are looked up automatically when users interact with the chat widget. Data will appear here once visitors have been recorded.', 'cleversay'); ?>
            </p>
        </div>
        <?php endif; ?>

        <?php if (!empty($us_states) || !empty($us_cities)): ?>
        <!-- US Breakdown Section -->
        <div style="margin-top:24px;">
            <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">
                <span style="margin-right:6px;">🇺🇸</span>
                <?php esc_html_e('United States Breakdown', 'cleversay'); ?>
            </h3>

            <div class="charts-row">

                <!-- US States -->
                <?php if (!empty($us_states)): ?>
                <div class="chart-card chart-small">
                    <h3><?php esc_html_e('By State', 'cleversay'); ?></h3>
                    <table class="widefat striped" style="font-size:13px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('State', 'cleversay'); ?></th>
                                <th style="text-align:right;"><?php esc_html_e('Visitors', 'cleversay'); ?></th>
                                <th style="text-align:right;"><?php esc_html_e('Visits', 'cleversay'); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($us_states as $row): ?>
                            <tr>
                                <td><?php echo esc_html($row['state']); ?></td>
                                <td style="text-align:right;font-weight:600;"><?php echo number_format((int)$row['visitors']); ?></td>
                                <td style="text-align:right;color:var(--cs-text-tertiary);"><?php echo number_format((int)$row['total_visits']); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(add_query_arg(['state' => urlencode($row['state'])])); ?>"
                                       style="font-size:11px;white-space:nowrap;">
                                        <?php esc_html_e('Cities →', 'cleversay'); ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- US Cities -->
                <?php if (!empty($us_cities_filtered)): ?>
                <div class="chart-card chart-small">
                    <h3>
                        <?php if ($filter_state): ?>
                            <?php echo esc_html(sprintf(__('Cities in %s', 'cleversay'), $filter_state)); ?>
                            <a href="<?php echo esc_url(remove_query_arg('state')); ?>"
                               style="font-size:11px;font-weight:400;margin-left:8px;">
                                ← <?php esc_html_e('All cities', 'cleversay'); ?>
                            </a>
                        <?php else: ?>
                            <?php esc_html_e('Top Cities', 'cleversay'); ?>
                        <?php endif; ?>
                    </h3>
                    <table class="widefat striped" style="font-size:13px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('City', 'cleversay'); ?></th>
                                <?php if (!$filter_state): ?>
                                <th><?php esc_html_e('State', 'cleversay'); ?></th>
                                <?php endif; ?>
                                <th style="text-align:right;"><?php esc_html_e('Visitors', 'cleversay'); ?></th>
                                <th style="text-align:right;"><?php esc_html_e('Visits', 'cleversay'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($us_cities_filtered as $row): ?>
                            <tr>
                                <td><?php echo esc_html($row['city']); ?></td>
                                <?php if (!$filter_state): ?>
                                <td style="color:var(--cs-text-tertiary);font-size:12px;"><?php echo esc_html($row['state']); ?></td>
                                <?php endif; ?>
                                <td style="text-align:right;font-weight:600;"><?php echo number_format((int)$row['visitors']); ?></td>
                                <td style="text-align:right;color:var(--cs-text-tertiary);"><?php echo number_format((int)$row['total_visits']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php endif; ?>
    </div>

<!-- Chart.js from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Trend Chart
    var trendData = <?php echo json_encode($trend); ?>;
    new Chart(document.getElementById('trend-chart'), {
        type: 'line',
        data: {
            labels: trendData.map(d => d.date),
            datasets: [
                {
                    label: '<?php esc_html_e('Total', 'cleversay'); ?>',
                    data: trendData.map(d => d.total),
                    borderColor: '#2271b1',
                    backgroundColor: 'rgba(34, 113, 177, 0.1)',
                    fill: true,
                    tension: 0.3
                },
                {
                    label: '<?php esc_html_e('Matched', 'cleversay'); ?>',
                    data: trendData.map(d => d.matched),
                    borderColor: '#00a32a',
                    backgroundColor: 'transparent',
                    tension: 0.3
                },
                {
                    label: '<?php esc_html_e('Unmatched', 'cleversay'); ?>',
                    data: trendData.map(d => d.unmatched),
                    borderColor: '#d63638',
                    backgroundColor: 'transparent',
                    tension: 0.3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
    
    // Match Pie Chart
    new Chart(document.getElementById('match-pie'), {
        type: 'doughnut',
        data: {
            labels: ['<?php esc_html_e('Matched', 'cleversay'); ?>', '<?php esc_html_e('Unmatched', 'cleversay'); ?>'],
            datasets: [{
                data: [<?php echo $stats['matched_questions']; ?>, <?php echo $stats['unmatched_questions']; ?>],
                backgroundColor: ['#00a32a', '#d63638'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
    
    // Hourly Chart
    var hourlyData = <?php echo json_encode($hourly); ?>;
    new Chart(document.getElementById('hourly-chart'), {
        type: 'bar',
        data: {
            labels: hourlyData.map(d => d.label),
            datasets: [{
                label: '<?php esc_html_e('Questions', 'cleversay'); ?>',
                data: hourlyData.map(d => d.count),
                backgroundColor: '#2271b1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
    
    // Weekday Chart
    var weekdayData = <?php echo json_encode($weekday); ?>;
    new Chart(document.getElementById('weekday-chart'), {
        type: 'bar',
        data: {
            labels: weekdayData.map(d => d.short),
            datasets: [{
                label: '<?php esc_html_e('Questions', 'cleversay'); ?>',
                data: weekdayData.map(d => d.count),
                backgroundColor: '#135e96'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });

    // AI vs KB Split Donut
    var aiSplitCanvas = document.getElementById('ai-split-donut');
    if (aiSplitCanvas) {
        new Chart(aiSplitCanvas, {
            type: 'doughnut',
            data: {
                labels: [
                    '<?php esc_html_e('KB Match', 'cleversay'); ?>',
                    '<?php esc_html_e('AI Answered', 'cleversay'); ?>',
                    '<?php esc_html_e('No Answer', 'cleversay'); ?>'
                ],
                datasets: [{
                    data: [<?php echo (int)$kb_count; ?>, <?php echo (int)$ai_count; ?>, <?php echo (int)$no_answer_count; ?>],
                    backgroundColor: ['#2271b1', '#8B5CF6', '#d63638'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var total = ctx.dataset.data.reduce((a,b) => a+b, 0);
                                var pct = total > 0 ? Math.round(ctx.parsed / total * 100) : 0;
                                return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // Geographic bar chart
    var geoCanvas = document.getElementById('geo-chart');
    if (geoCanvas) {
        var geoData = <?php echo wp_json_encode(array_slice((array)$geo_data, 0, 10)); ?>;
        new Chart(geoCanvas, {
            type: 'bar',
            data: {
                labels: geoData.map(function(d) { return d.country_name || d.country_code; }),
                datasets: [{
                    label: '<?php esc_html_e('Unique Visitors', 'cleversay'); ?>',
                    data: geoData.map(function(d) { return parseInt(d.visitors); }),
                    backgroundColor: 'rgba(10,132,255,0.75)',
                    borderColor: 'rgba(10,132,255,1)',
                    borderWidth: 0,
                    borderRadius: 5,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var row = geoData[ctx.dataIndex];
                                return ' ' + ctx.parsed.x + ' visitors, ' + parseInt(row.total_visits) + ' total visits';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,0.04)' },
                        ticks: { color: '#86868B', font: { size: 12 } }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { color: '#1D1D1F', font: { size: 13, weight: '500' } }
                    }
                }
            }
        });
    }
});
</script>

<style>
.cleversay-reports {
    max-width: 1400px;
}

.period-selector {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 20px 0;
    padding: 15px;
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
}

.period-selector select {
    min-width: 150px;
}

.period-selector .button {
    margin-left: auto;
}

/* KPI Cards */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.kpi-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    text-align: center;
}

.kpi-value {
    font-size: 2.5em;
    font-weight: 600;
    color: #1d2327;
    line-height: 1.2;
}

.kpi-label {
    font-size: 14px;
    color: #646970;
    margin-top: 5px;
}

.kpi-change {
    font-size: 13px;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #f0f0f1;
}

.kpi-change.positive { color: #00a32a; }
.kpi-change.negative { color: #d63638; }

.kpi-change span {
    display: block;
    font-size: 11px;
    color: #a7aaad;
}

.kpi-sublabel {
    font-size: 12px;
    color: #a7aaad;
    margin-top: 5px;
}

/* Charts */
.charts-row {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
}

.chart-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
}

.chart-card h3 {
    margin: 0 0 15px;
    font-size: 14px;
    color: #1d2327;
}

.chart-large {
    flex: 2;
    min-height: 300px;
}

.chart-small {
    flex: 1;
    min-height: 300px;
}

.chart-medium {
    flex: 1;
    min-height: 250px;
}

.chart-card canvas {
    max-height: 250px;
}

/* Tables */
.tables-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.table-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
}

.table-card h3 {
    margin: 0 0 15px;
    font-size: 14px;
}

.table-card table {
    margin: 0;
}

.table-card .num {
    text-align: right;
    width: 80px;
}

.rating-bar {
    display: inline-block;
    width: 50px;
    height: 8px;
    background: #f0f0f1;
    border-radius: 4px;
    margin-right: 5px;
    vertical-align: middle;
}

.rating-fill {
    display: block;
    height: 100%;
    background: #00a32a;
    border-radius: 4px;
}

.no-data {
    text-align: center;
    color: #646970;
    padding: 30px;
}

/* Categories */






.cat-stat {
    text-align: center;
}

.cat-num {
    display: block;
    font-size: 1.2em;
    font-weight: 600;
}

.cat-label {
    font-size: 11px;
    color: #646970;
}

/* Responsive */
@media (max-width: 782px) {
    .charts-row {
        flex-direction: column;
    }
    
    .tables-row {
        grid-template-columns: 1fr;
    }
}
</style>
