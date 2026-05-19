<?php
/**
 * CleverSay Latency Dashboard
 *
 * v4.41.5+: read-only per-tenant view of request latency. Reads from
 * wp_X_cleversay_request_metrics joined to wp_X_cleversay_questions for
 * question text. Designed as an evidence base for upcoming optimization
 * work (Haiku swap, output limits, cache wire-up): see whether changes
 * actually moved the needle on p50/p95.
 *
 * Stays intentionally simple. No charts. No filters beyond a window
 * dropdown. Aggregation queries below scan small ranges (idx_created
 * narrows by time, then in-memory sort/percentile) so this stays
 * snappy up to the 90-day retention horizon.
 *
 * @package CleverSay
 * @since   4.41.5
 *
 * @var array $stats             Aggregated stats for the chosen window.
 * @var array $recent            Slowest N queries (rendered in "Slowest" table).
 * @var array $fastest_overall   Fastest N queries overall (any matched_layer).
 * @var array $fastest_synthesis Fastest N queries that actually ran AI synthesis.
 * @var int   $window_hours      Currently-selected window.
 */

if (!defined('ABSPATH')) exit;

$blog_id = (int) get_current_blog_id();
$site_label = is_multisite()
    ? sprintf(__('Site %d', 'cleversay'), $blog_id)
    : __('this site', 'cleversay');

// Helper: fmt ms with sensible units (ms below 1000, then s).
$fmt_ms = static function ($ms): string {
    if ($ms === null) return '—';
    $ms = (int) $ms;
    if ($ms < 1000) return $ms . ' ms';
    return number_format($ms / 1000, 2) . ' s';
};
$fmt_pct = static function ($num, $denom): string {
    if (!$denom) return '—';
    return number_format(($num / $denom) * 100, 1) . '%';
};
?>
<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline">
        <?php echo \CleverSay\Icons::render('activity', 18); ?>
        <?php esc_html_e('Latency', 'cleversay'); ?>
    </h1>
    <hr class="wp-header-end">

    <p class="description" style="margin-bottom:20px;max-width:780px;">
        <?php
        printf(
            /* translators: %s = "Site 4" or "this site" */
            esc_html__(
                'Per-request latency metrics for %s. One row per AJAX search that produced a logged question. Use this to set baselines before optimization work and to verify that changes actually reduced latency.',
                'cleversay'
            ),
            esc_html($site_label)
        );
        ?>
    </p>

    <!-- Window selector -->
    <form method="get" action="" style="margin-bottom:18px;">
        <input type="hidden" name="page" value="cleversay-latency">
        <label for="window">
            <?php esc_html_e('Show data from the last:', 'cleversay'); ?>
        </label>
        <select id="window" name="window" onchange="this.form.submit()">
            <?php foreach ([1 => '1 hour', 24 => '24 hours', 168 => '7 days', 720 => '30 days'] as $hours => $label): ?>
                <option value="<?php echo (int) $hours; ?>" <?php selected($window_hours, $hours); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript>
            <button type="submit" class="button"><?php esc_html_e('Apply', 'cleversay'); ?></button>
        </noscript>
    </form>

    <?php if (empty($stats['total_queries'])): ?>
        <div class="cleversay-table-card" style="padding:20px;">
            <p style="margin:0;">
                <em><?php esc_html_e('No requests logged in this window yet. The metrics table populates as queries hit the chat widget — give it a few minutes after deploy, then refresh.', 'cleversay'); ?></em>
            </p>
        </div>
    <?php else: ?>

    <!-- Headline grid -->
    <div class="cleversay-table-card" style="margin-bottom:20px;">
        <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
            <h3 style="margin:0;font-size:14px;font-weight:600;">
                <?php esc_html_e('Headline', 'cleversay'); ?>
            </h3>
        </div>
        <div style="padding:14px 18px;">
            <table class="widefat striped">
                <tbody>
                    <tr>
                        <td style="width:35%;"><strong><?php esc_html_e('Total queries', 'cleversay'); ?></strong></td>
                        <td><?php echo (int) $stats['total_queries']; ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Median total time', 'cleversay'); ?></strong></td>
                        <td><?php echo esc_html($fmt_ms($stats['p50_total'])); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('P95 total time', 'cleversay'); ?></strong></td>
                        <td><?php echo esc_html($fmt_ms($stats['p95_total'])); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Cache hit rate', 'cleversay'); ?></strong></td>
                        <td><?php echo esc_html($fmt_pct($stats['cache_hits'], $stats['total_queries'])); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('AI fallback fired', 'cleversay'); ?></strong></td>
                        <td><?php echo esc_html($fmt_pct($stats['ai_fired'], $stats['total_queries'])); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Gate fired (of hybrid runs)', 'cleversay'); ?></strong></td>
                        <td><?php echo esc_html($fmt_pct($stats['gate_fired'], $stats['gate_evaluated'])); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Total tokens (in / out)', 'cleversay'); ?></strong></td>
                        <td>
                            <?php echo number_format((int) ($stats['total_tokens_in'] ?? 0)); ?> /
                            <?php echo number_format((int) ($stats['total_tokens_out'] ?? 0)); ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Total cost', 'cleversay'); ?></strong></td>
                        <td>$<?php echo number_format((float) ($stats['total_cost'] ?? 0), 4); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Per-stage breakdown -->
    <div class="cleversay-table-card" style="margin-bottom:20px;">
        <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
            <h3 style="margin:0;font-size:14px;font-weight:600;">
                <?php esc_html_e('Distribution by stage', 'cleversay'); ?>
            </h3>
        </div>
        <div style="padding:14px 18px;">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Stage', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Median', 'cleversay'); ?></th>
                        <th><?php esc_html_e('P95', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Sample size', 'cleversay'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e('KB search (Layer 1)', 'cleversay'); ?></strong></td>
                        <td><?php echo esc_html($fmt_ms($stats['p50_kb'])); ?></td>
                        <td><?php echo esc_html($fmt_ms($stats['p95_kb'])); ?></td>
                        <td><?php echo (int) $stats['n_kb']; ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Retrieval (vector + FULLTEXT)', 'cleversay'); ?></strong></td>
                        <td><?php echo esc_html($fmt_ms($stats['p50_retrieval'])); ?></td>
                        <td><?php echo esc_html($fmt_ms($stats['p95_retrieval'])); ?></td>
                        <td><?php echo (int) $stats['n_retrieval']; ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Synthesis (LLM)', 'cleversay'); ?></strong></td>
                        <td><?php echo esc_html($fmt_ms($stats['p50_synthesis'])); ?></td>
                        <td><?php echo esc_html($fmt_ms($stats['p95_synthesis'])); ?></td>
                        <td><?php echo (int) $stats['n_synthesis']; ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Render (formatting + JSON)', 'cleversay'); ?></strong></td>
                        <td><?php echo esc_html($fmt_ms($stats['p50_render'])); ?></td>
                        <td><?php echo esc_html($fmt_ms($stats['p95_render'])); ?></td>
                        <td><?php echo (int) $stats['n_render']; ?></td>
                    </tr>
                </tbody>
            </table>
            <p class="description" style="margin-top:10px;margin-bottom:0;">
                <?php esc_html_e(
                    'Sample size = number of requests that ran that stage. KB and Render run on every request; Retrieval and Synthesis only when AI fallback fires.',
                    'cleversay'
                ); ?>
            </p>
        </div>
    </div>

    <!-- Routing breakdown -->
    <div class="cleversay-table-card" style="margin-bottom:20px;">
        <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
            <h3 style="margin:0;font-size:14px;font-weight:600;">
                <?php esc_html_e('Routing breakdown', 'cleversay'); ?>
            </h3>
        </div>
        <div style="padding:14px 18px;">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Matched layer', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Count', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Share', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Median total', 'cleversay'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['by_layer'] as $layer => $row): ?>
                    <tr>
                        <td><strong><?php echo esc_html($layer); ?></strong></td>
                        <td><?php echo (int) $row['count']; ?></td>
                        <td><?php echo esc_html($fmt_pct($row['count'], $stats['total_queries'])); ?></td>
                        <td><?php echo esc_html($fmt_ms($row['p50_total'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Synthesis by model — A/B comparison view -->
    <?php if (!empty($stats['by_synthesis_model'])): ?>
    <div class="cleversay-table-card" style="margin-bottom:20px;">
        <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
            <h3 style="margin:0;font-size:14px;font-weight:600;">
                <?php esc_html_e('Synthesis by model', 'cleversay'); ?>
            </h3>
        </div>
        <div style="padding:14px 18px;">
            <p class="description" style="margin-top:0;margin-bottom:14px;">
                <?php esc_html_e(
                    'Synthesis-only stats grouped by the model that produced each answer. Use this to A/B compare model swaps: take a baseline window on the current model, switch via Network Admin → CleverSay → AI Settings, run another window, then compare p50 / p95 / cost here.',
                    'cleversay'
                ); ?>
            </p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Model', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Synthesis runs', 'cleversay'); ?></th>
                        <th><?php esc_html_e('P50 synth', 'cleversay'); ?></th>
                        <th><?php esc_html_e('P95 synth', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Median tokens out', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Total cost', 'cleversay'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['by_synthesis_model'] as $model => $row): ?>
                    <tr>
                        <td>
                            <?php
                            // Mirror the friendly-name mapping from the
                            // slowest-queries table so the two views read
                            // consistently.
                            $short = $model;
                            if (strpos($model, 'claude-haiku') === 0)         $short = 'Haiku 4.5';
                            elseif (strpos($model, 'claude-sonnet-4-6') === 0) $short = 'Sonnet 4.6';
                            elseif (strpos($model, 'claude-sonnet-4-5') === 0) $short = 'Sonnet 4.5';
                            elseif (strpos($model, 'claude-opus') === 0)      $short = 'Opus 4.6';
                            elseif (strpos($model, 'gemini') === 0)           $short = 'Gemini ' . substr($model, 7, 5);
                            elseif ($model === 'unknown')                     $short = '(unrecorded — pre-v4.41.5.3)';
                            ?>
                            <strong title="<?php echo esc_attr($model); ?>"><?php echo esc_html($short); ?></strong>
                        </td>
                        <td><?php echo (int) $row['count']; ?></td>
                        <td><?php echo esc_html($fmt_ms($row['p50_synth'])); ?></td>
                        <td><?php echo esc_html($fmt_ms($row['p95_synth'])); ?></td>
                        <td>
                            <?php
                            echo $row['p50_tokens_out'] === null
                                ? '—'
                                : number_format((int) $row['p50_tokens_out']);
                            ?>
                        </td>
                        <td>$<?php echo number_format((float) $row['total_cost'], 4); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description" style="margin-top:10px;margin-bottom:0;">
                <?php esc_html_e(
                    'Tip: a clean A/B needs both windows to span similar time-of-day bands and similar query volumes — Anthropic prompt-caching, traffic patterns, and weekday-vs-weekend mix can all confound a quick comparison.',
                    'cleversay'
                ); ?>
            </p>
        </div>
    </div>
    <?php endif; ?>

    <?php
    // v4.41.5.6+: shared row-rendering closure for the three "ranked
    // queries" tables (slowest, fastest overall, fastest synthesis).
    // All three render identical column structure, so factor the body
    // out rather than copy-pasting it three times.
    $render_query_rows = static function (array $rows) use ($fmt_ms): void {
        if (empty($rows)) {
            echo '<p style="margin:0;"><em>'
                . esc_html__('No queries to show.', 'cleversay')
                . '</em></p>';
            return;
        }
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('When', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Question', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Total', 'cleversay'); ?></th>
                    <th><?php esc_html_e('KB', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Retr.', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Synth.', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Layer', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Model', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Cost', 'cleversay'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?php echo esc_html(mysql2date('M j, H:i', $row['created_at'])); ?></td>
                    <td title="<?php echo esc_attr($row['question_text'] ?? ''); ?>">
                        <?php echo esc_html(mb_strimwidth(($row['question_text'] ?? '(unknown)'), 0, 60, '…')); ?>
                    </td>
                    <td><?php echo esc_html($fmt_ms($row['total_ms'])); ?></td>
                    <td><?php echo esc_html($fmt_ms($row['kb_ms'])); ?></td>
                    <td><?php echo esc_html($fmt_ms($row['retrieval_ms'])); ?></td>
                    <td><?php echo esc_html($fmt_ms($row['synthesis_ms'])); ?></td>
                    <td><code><?php echo esc_html($row['matched_layer']); ?></code></td>
                    <td>
                        <?php
                        // Friendly short name for known model ids; raw id
                        // otherwise. Mirrors the per-model card above.
                        $m = $row['synthesis_model'] ?? null;
                        if ($m === null || $m === '') {
                            echo '—';
                        } else {
                            $short = $m;
                            if (strpos($m, 'claude-haiku') === 0)         $short = 'Haiku 4.5';
                            elseif (strpos($m, 'claude-sonnet-4-6') === 0) $short = 'Sonnet 4.6';
                            elseif (strpos($m, 'claude-sonnet-4-5') === 0) $short = 'Sonnet 4.5';
                            elseif (strpos($m, 'claude-opus') === 0)      $short = 'Opus 4.6';
                            elseif (strpos($m, 'gemini') === 0)           $short = 'Gemini ' . substr($m, 7, 5);
                            echo '<code title="' . esc_attr($m) . '">' . esc_html($short) . '</code>';
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        echo $row['cost'] !== null
                            ? '$' . number_format((float) $row['cost'], 4)
                            : '—';
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    };
    ?>

    <!-- Recent slowest -->
    <div class="cleversay-table-card" style="margin-bottom:20px;">
        <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
            <h3 style="margin:0;font-size:14px;font-weight:600;">
                <?php esc_html_e('Slowest queries in window', 'cleversay'); ?>
            </h3>
        </div>
        <div style="padding:14px 18px;">
            <?php $render_query_rows($recent); ?>
        </div>
    </div>

    <!-- Fastest overall -->
    <div class="cleversay-table-card" style="margin-bottom:20px;">
        <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
            <h3 style="margin:0;font-size:14px;font-weight:600;">
                <?php esc_html_e('Fastest queries in window', 'cleversay'); ?>
            </h3>
        </div>
        <div style="padding:14px 18px;">
            <p class="description" style="margin-top:0;margin-bottom:12px;">
                <?php esc_html_e(
                    'Includes every matched_layer. Most rows here are typically kb_strong — Layer 1 hit, no synthesis ran. A wall of low-latency kb_strong rows means Layer 1 is doing its job for the common questions.',
                    'cleversay'
                ); ?>
            </p>
            <?php $render_query_rows($fastest_overall); ?>
        </div>
    </div>

    <!-- Fastest synthesis -->
    <div class="cleversay-table-card" style="margin-bottom:20px;">
        <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);">
            <h3 style="margin:0;font-size:14px;font-weight:600;">
                <?php esc_html_e('Fastest queries that ran AI synthesis', 'cleversay'); ?>
            </h3>
        </div>
        <div style="padding:14px 18px;">
            <p class="description" style="margin-top:0;margin-bottom:12px;">
                <?php esc_html_e(
                    'Filtered to rows where AI fallback fired and synthesis actually ran. Useful for model A/B: this shows the lower bound a model can deliver under good conditions (warm cache, short context). Compare across models to see how much of the speed difference is best-case vs typical-case.',
                    'cleversay'
                ); ?>
            </p>
            <?php $render_query_rows($fastest_synthesis); ?>
        </div>
    </div>

    <?php endif; // total_queries > 0 ?>

    <p class="description" style="font-style:italic;margin-top:20px;">
        <?php esc_html_e(
            'Retention: the metrics table is auto-pruned daily to the last 90 days. To change the retention window, set the cleversay_metrics_retention_days option (network admin). Disable pruning by setting it to 0.',
            'cleversay'
        ); ?>
    </p>
</div>
