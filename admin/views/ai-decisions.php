<?php
/**
 * AI Decisions Observability Page
 *
 * Shows summary stats + drill-down for AI decisions made by the
 * matcher in a configurable date range:
 *   - Tiebreak resolutions: AI picked among multi-entry score ties
 *   - KB validation rejections: AI said the matched entry didn't
 *     answer the user's question, fell through to AI fallback
 *
 * Source: questions_log table. The v4.37.50 schema additions
 * (ai_tiebreak, ai_tiebreak_chosen_id, ai_tiebreak_tied_ids,
 * ai_provider) make these events queryable. Pre-v4.37.50 events
 * exist only in the unstructured debug log and aren't shown here.
 *
 * @package CleverSay
 * @since   4.37.50
 */

if (!defined('ABSPATH')) { exit; }

global $wpdb;

$db      = new \CleverSay\Database();
$ql      = $db->questions_log;
$kb      = $db->knowledge_base;

// ── Date range ────────────────────────────────────────────────────
//
// Default to last 7 days. Both start and end are inclusive. The
// fields use HTML5 date inputs so admin gets a native picker.
$today    = current_time('Y-m-d');
$week_ago = date('Y-m-d', strtotime('-6 days', current_time('timestamp')));

$start_date = sanitize_text_field($_GET['start_date'] ?? $week_ago);
$end_date   = sanitize_text_field($_GET['end_date']   ?? $today);

// Validate date format. Bad input → fall back to defaults rather
// than blowing up the page.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) $start_date = $week_ago;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date))   $end_date   = $today;
if ($start_date > $end_date) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

// Convert to datetime range for SQL. End date is inclusive — we go
// to 23:59:59 of that day.
$start_dt = $start_date . ' 00:00:00';
$end_dt   = $end_date   . ' 23:59:59';

// ── Summary stats ─────────────────────────────────────────────────
//
// Single round-trip via aggregate query. Cheaper than 5 separate
// COUNTs and the values render together at the top.
$summary = $wpdb->get_row($wpdb->prepare(
    "SELECT
        COUNT(*)                                              AS total_queries,
        SUM(CASE WHEN match_type != 'none' THEN 1 ELSE 0 END) AS matched,
        SUM(ai_tiebreak)                                      AS tiebreaks,
        SUM(ai_rejected)                                      AS rejections
     FROM {$ql}
     WHERE created_at BETWEEN %s AND %s",
    $start_dt, $end_dt
), ARRAY_A);

$total      = (int) ($summary['total_queries'] ?? 0);
$matched    = (int) ($summary['matched']       ?? 0);
$tiebreaks  = (int) ($summary['tiebreaks']     ?? 0);
$rejections = (int) ($summary['rejections']    ?? 0);

$match_rate     = $total > 0 ? round(($matched / $total) * 100, 1) : 0;
$rejection_rate = $matched > 0 ? round(($rejections / $matched) * 100, 1) : 0;

// Provider breakdown for AI-touching events. Helps admins running
// A/B tests between Anthropic and Gemini see which provider is
// doing the work in the observed window.
$provider_rows = $wpdb->get_results($wpdb->prepare(
    "SELECT ai_provider, COUNT(*) AS n
     FROM {$ql}
     WHERE created_at BETWEEN %s AND %s
       AND ai_provider IS NOT NULL
       AND ai_provider != ''
       AND (ai_tiebreak = 1 OR ai_rejected = 1)
     GROUP BY ai_provider",
    $start_dt, $end_dt
), ARRAY_A);

// Top entries by rejection rate. The most-flagged entries are
// where to look first — their pattern probably matches things they
// shouldn't, or their response doesn't fit the canonical question.
$top_rejected = $wpdb->get_results($wpdb->prepare(
    "SELECT
        knowledge_id,
        matched_keyword,
        matched_sub_keyword,
        COUNT(*) AS rejection_count
     FROM {$ql}
     WHERE created_at BETWEEN %s AND %s
       AND ai_rejected = 1
       AND knowledge_id IS NOT NULL
     GROUP BY knowledge_id, matched_keyword, matched_sub_keyword
     ORDER BY rejection_count DESC
     LIMIT 5",
    $start_dt, $end_dt
), ARRAY_A);

// ── Drill-down tables ─────────────────────────────────────────────
//
// Last 50 events per type. If there are more, admin can narrow
// the date range. Sorted newest-first since recent events are
// usually what admin is investigating.

$tiebreak_rows = $wpdb->get_results($wpdb->prepare(
    "SELECT
        id,
        question,
        matched_keyword,
        matched_sub_keyword,
        knowledge_id,
        ai_tiebreak_chosen_id,
        ai_tiebreak_tied_ids,
        ai_provider,
        match_score,
        created_at
     FROM {$ql}
     WHERE created_at BETWEEN %s AND %s
       AND ai_tiebreak = 1
     ORDER BY created_at DESC
     LIMIT 50",
    $start_dt, $end_dt
), ARRAY_A);

$rejection_rows = $wpdb->get_results($wpdb->prepare(
    "SELECT
        id,
        question,
        matched_keyword,
        matched_sub_keyword,
        knowledge_id,
        ai_rejection_reason,
        ai_provider,
        match_score,
        created_at
     FROM {$ql}
     WHERE created_at BETWEEN %s AND %s
       AND ai_rejected = 1
     ORDER BY created_at DESC
     LIMIT 50",
    $start_dt, $end_dt
), ARRAY_A);

// Resolve knowledge_id → canonical question for the drill-downs so
// admins can see WHICH entry was involved without having to click
// through. Single batched lookup keeps this cheap even at ~100 rows.
$ids_to_lookup = [];
foreach ($tiebreak_rows as $r) {
    if (!empty($r['knowledge_id']))           $ids_to_lookup[] = (int) $r['knowledge_id'];
    if (!empty($r['ai_tiebreak_chosen_id'])) $ids_to_lookup[] = (int) $r['ai_tiebreak_chosen_id'];
    foreach (explode(',', (string) ($r['ai_tiebreak_tied_ids'] ?? '')) as $tid) {
        $tid = (int) trim($tid);
        if ($tid > 0) $ids_to_lookup[] = $tid;
    }
}
foreach ($rejection_rows as $r) {
    if (!empty($r['knowledge_id'])) $ids_to_lookup[] = (int) $r['knowledge_id'];
}
$ids_to_lookup = array_values(array_unique($ids_to_lookup));

$kb_lookup = [];
if (!empty($ids_to_lookup)) {
    $placeholders = implode(',', array_fill(0, count($ids_to_lookup), '%d'));
    $kb_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, keyword, sub_keyword, question
         FROM {$kb}
         WHERE id IN ({$placeholders})",
        ...$ids_to_lookup
    ), ARRAY_A);
    foreach (($kb_rows ?: []) as $row) {
        $kb_lookup[(int) $row['id']] = $row;
    }
}

$kb_url = admin_url('admin.php?page=cleversay-knowledge');

/** Format a knowledge_id as a small clickable summary. */
function cs_render_kb_ref($id, array $kb_lookup, string $kb_url): string {
    $id = (int) $id;
    if ($id <= 0) return '<em style="color:#999;">none</em>';
    $row = $kb_lookup[$id] ?? null;
    if ($row === null) return '<code>#' . $id . '</code>';
    $label = esc_html($row['keyword']);
    if (!empty($row['sub_keyword']) && strtolower($row['sub_keyword']) !== 'aadefault') {
        $label .= ' / <code style="font-size:11px;">' . esc_html($row['sub_keyword']) . '</code>';
    } else {
        $label .= ' / <em style="color:#888; font-size:11px;">aadefault</em>';
    }
    $title = $row['question'] !== '' ? $row['question'] : 'no canonical question';
    return '<a href="' . esc_url(add_query_arg([
        'action'  => 'edit-keyword',
        'keyword' => $row['keyword'],
    ], $kb_url)) . '" title="' . esc_attr($title) . '">' . $label . '</a>';
}
?>

<div class="wrap cleversay-admin">
    <h1>
        <?php echo \CleverSay\Icons::render('activity', 22); ?>
        <?php esc_html_e('AI Decisions', 'cleversay'); ?>
    </h1>
    <p style="font-size:13px; color:#555; max-width:900px; margin-top:8px;">
        <?php esc_html_e('Observe how often AI is intervening in matcher decisions and which entries are getting flagged. Source: questions_log. Older events (before v4.37.50) aren\'t shown here because they predate structured logging.', 'cleversay'); ?>
    </p>

    <hr class="wp-header-end">

    <!-- ── Date range filter ──────────────────────────────────── -->
    <form method="get" style="margin:18px 0; padding:14px; background:#f6f7f7; border:1px solid #ddd; border-radius:4px; display:flex; gap:14px; align-items:flex-end;">
        <input type="hidden" name="page" value="cleversay-ai-decisions">
        <div>
            <label for="start_date" style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">
                <?php esc_html_e('From', 'cleversay'); ?>
            </label>
            <input type="date" id="start_date" name="start_date"
                   value="<?php echo esc_attr($start_date); ?>"
                   max="<?php echo esc_attr($today); ?>">
        </div>
        <div>
            <label for="end_date" style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">
                <?php esc_html_e('To', 'cleversay'); ?>
            </label>
            <input type="date" id="end_date" name="end_date"
                   value="<?php echo esc_attr($end_date); ?>"
                   max="<?php echo esc_attr($today); ?>">
        </div>
        <button type="submit" class="button button-primary">
            <?php esc_html_e('Apply', 'cleversay'); ?>
        </button>
        <div style="margin-left:auto; display:flex; gap:6px;">
            <a class="button button-small" href="<?php echo esc_url(add_query_arg([
                'page' => 'cleversay-ai-decisions',
                'start_date' => date('Y-m-d', strtotime('-1 day', current_time('timestamp'))),
                'end_date' => $today,
            ], admin_url('admin.php'))); ?>">24h</a>
            <a class="button button-small" href="<?php echo esc_url(add_query_arg([
                'page' => 'cleversay-ai-decisions',
                'start_date' => date('Y-m-d', strtotime('-6 days', current_time('timestamp'))),
                'end_date' => $today,
            ], admin_url('admin.php'))); ?>">7d</a>
            <a class="button button-small" href="<?php echo esc_url(add_query_arg([
                'page' => 'cleversay-ai-decisions',
                'start_date' => date('Y-m-d', strtotime('-29 days', current_time('timestamp'))),
                'end_date' => $today,
            ], admin_url('admin.php'))); ?>">30d</a>
        </div>
    </form>

    <!-- ── Summary tiles ──────────────────────────────────────── -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:24px;">
        <div style="padding:14px 18px; background:white; border:1px solid #ddd; border-radius:4px;">
            <div style="font-size:11px; color:#666; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px;">
                <?php esc_html_e('Total queries', 'cleversay'); ?>
            </div>
            <div style="font-size:24px; font-weight:600;"><?php echo number_format_i18n($total); ?></div>
        </div>

        <div style="padding:14px 18px; background:white; border:1px solid #ddd; border-radius:4px;">
            <div style="font-size:11px; color:#666; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px;">
                <?php esc_html_e('KB match rate', 'cleversay'); ?>
            </div>
            <div style="font-size:24px; font-weight:600;"><?php echo $match_rate; ?>%</div>
            <div style="font-size:11px; color:#888; margin-top:2px;">
                <?php echo number_format_i18n($matched); ?> / <?php echo number_format_i18n($total); ?>
            </div>
        </div>

        <div style="padding:14px 18px; background:white; border:1px solid #ddd; border-radius:4px;<?php echo $tiebreaks > 0 ? ' border-left:4px solid #2271b1;' : ''; ?>">
            <div style="font-size:11px; color:#666; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px;">
                <?php esc_html_e('AI tiebreaks', 'cleversay'); ?>
            </div>
            <div style="font-size:24px; font-weight:600;"><?php echo number_format_i18n($tiebreaks); ?></div>
            <div style="font-size:11px; color:#888; margin-top:2px;">
                <?php esc_html_e('high-score ties resolved by AI', 'cleversay'); ?>
            </div>
        </div>

        <div style="padding:14px 18px; background:white; border:1px solid #ddd; border-radius:4px;<?php echo $rejections > 0 ? ' border-left:4px solid #d63638;' : ''; ?>">
            <div style="font-size:11px; color:#666; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px;">
                <?php esc_html_e('KB rejections', 'cleversay'); ?>
            </div>
            <div style="font-size:24px; font-weight:600;"><?php echo number_format_i18n($rejections); ?></div>
            <div style="font-size:11px; color:#888; margin-top:2px;">
                <?php echo $rejection_rate; ?>% <?php esc_html_e('of matches', 'cleversay'); ?>
            </div>
        </div>

        <?php if (!empty($provider_rows)): ?>
        <div style="padding:14px 18px; background:white; border:1px solid #ddd; border-radius:4px;">
            <div style="font-size:11px; color:#666; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px;">
                <?php esc_html_e('Provider breakdown', 'cleversay'); ?>
            </div>
            <?php foreach ($provider_rows as $p): ?>
                <div style="font-size:13px; margin-top:2px;">
                    <strong><?php echo esc_html($p['ai_provider']); ?></strong>
                    <span style="color:#888;">· <?php echo number_format_i18n((int) $p['n']); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Top problematic entries ────────────────────────────── -->
    <?php if (!empty($top_rejected)): ?>
    <div style="margin-bottom:24px; padding:16px 18px; background:#fff3cd; border:1px solid #ffc107; border-radius:4px;">
        <h3 style="margin:0 0 10px; font-size:14px;">
            <?php echo \CleverSay\Icons::render('alert-triangle', 16); ?>
            <?php esc_html_e('Top entries by rejection count', 'cleversay'); ?>
        </h3>
        <p style="margin:0 0 10px; font-size:12px; color:#666;">
            <?php esc_html_e('These entries are matching queries that AI then judges as not actually answered. Worth reviewing — either the pattern is too broad, or the response doesn\'t fit the canonical question.', 'cleversay'); ?>
        </p>
        <table class="widefat striped" style="background:white;">
            <thead>
                <tr>
                    <th style="width:60%;"><?php esc_html_e('Entry', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Rejections', 'cleversay'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($top_rejected as $r): ?>
                    <tr>
                        <td><?php echo cs_render_kb_ref((int) $r['knowledge_id'], $kb_lookup, $kb_url); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — already escaped inside helper ?></td>
                        <td><strong><?php echo number_format_i18n((int) $r['rejection_count']); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ── Tiebreak drill-down ────────────────────────────────── -->
    <h2 style="margin-top:30px; font-size:16px;">
        <?php echo \CleverSay\Icons::render('git-merge', 18); ?>
        <?php esc_html_e('AI Tiebreak Resolutions', 'cleversay'); ?>
        <span style="font-size:12px; color:#888; font-weight:normal;">(<?php echo count($tiebreak_rows); ?>)</span>
    </h2>
    <?php if (empty($tiebreak_rows)): ?>
        <p style="color:#888; padding:14px; background:#f6f7f7; border-radius:4px;">
            <?php esc_html_e('No tiebreak events in this date range. Either no high-score ties occurred or AI tiebreak is disabled (Settings → AI Tiebreak Min Score = 0).', 'cleversay'); ?>
        </p>
    <?php else: ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('When', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Question', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Score', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Tied entries', 'cleversay'); ?></th>
                    <th><?php esc_html_e('AI picked', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Provider', 'cleversay'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tiebreak_rows as $r): ?>
                    <?php
                    $tied = array_filter(array_map('intval', explode(',', (string) $r['ai_tiebreak_tied_ids'])));
                    $tied_chunks = [];
                    foreach ($tied as $tid) {
                        $tied_chunks[] = cs_render_kb_ref($tid, $kb_lookup, $kb_url);
                    }
                    ?>
                    <tr>
                        <td style="white-space:nowrap; color:#666; font-size:12px;">
                            <?php echo esc_html(human_time_diff(strtotime($r['created_at']), current_time('timestamp')) . ' ago'); ?>
                        </td>
                        <td style="max-width:300px;">
                            <span style="font-size:13px;"><?php echo esc_html($r['question']); ?></span>
                        </td>
                        <td><?php echo (int) $r['match_score']; ?></td>
                        <td style="font-size:12px;">
                            <?php echo implode('<br>', $tied_chunks); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </td>
                        <td>
                            <?php echo cs_render_kb_ref((int) $r['ai_tiebreak_chosen_id'], $kb_lookup, $kb_url); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </td>
                        <td><code style="font-size:11px;"><?php echo esc_html($r['ai_provider'] ?: '—'); ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- ── Rejection drill-down ───────────────────────────────── -->
    <h2 style="margin-top:30px; font-size:16px;">
        <?php echo \CleverSay\Icons::render('alert-circle', 18); ?>
        <?php esc_html_e('KB Validation Rejections', 'cleversay'); ?>
        <span style="font-size:12px; color:#888; font-weight:normal;">(<?php echo count($rejection_rows); ?>)</span>
    </h2>
    <?php if (empty($rejection_rows)): ?>
        <p style="color:#888; padding:14px; background:#f6f7f7; border-radius:4px;">
            <?php esc_html_e('No KB rejections in this date range. Either no validation failures occurred, or the Validate KB Relevance setting is off.', 'cleversay'); ?>
        </p>
    <?php else: ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('When', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Question', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Rejected entry', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Score', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Reason', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Provider', 'cleversay'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rejection_rows as $r): ?>
                    <tr>
                        <td style="white-space:nowrap; color:#666; font-size:12px;">
                            <?php echo esc_html(human_time_diff(strtotime($r['created_at']), current_time('timestamp')) . ' ago'); ?>
                        </td>
                        <td style="max-width:300px;">
                            <span style="font-size:13px;"><?php echo esc_html($r['question']); ?></span>
                        </td>
                        <td>
                            <?php echo cs_render_kb_ref((int) $r['knowledge_id'], $kb_lookup, $kb_url); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </td>
                        <td><?php echo (int) $r['match_score']; ?></td>
                        <td><code style="font-size:11px;"><?php echo esc_html($r['ai_rejection_reason'] ?: '—'); ?></code></td>
                        <td><code style="font-size:11px;"><?php echo esc_html($r['ai_provider'] ?: '—'); ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
