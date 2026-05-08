<?php
/**
 * Eval Harness Admin View
 *
 * Variations-based recall@1 evaluation. Runs every variation in the
 * KB through the matcher and reports whether each one matched its
 * own entry. Surfaces the failures so admins can investigate why a
 * given variation isn't matching.
 *
 * The actual run happens in batches via AJAX (Admin::ajax_run_eval).
 * This view is just the UI shell + last-run summary.
 *
 * @package CleverSay
 * @since 4.37.0
 */

defined('ABSPATH') || exit;

$last = get_option('cleversay_last_eval_run', null);
$has_last = is_array($last) && !empty($last);

$history = get_option('cleversay_eval_run_history', []);
if (!is_array($history)) $history = [];

// v4.37.5+: if a run exists in cleversay_last_eval_run but history is
// empty (i.e. the admin ran the eval at least once before this version
// landed), seed history with that run so they don't lose their baseline.
// Idempotent — only seeds when history is genuinely empty.
if ($has_last && empty($history)) {
    $seed = $last;
    if (empty($seed['run_id'])) {
        $seed['run_id'] = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('run_', true);
    }
    if (empty($seed['plugin_version'])) {
        $seed['plugin_version'] = '';
    }
    if (!isset($seed['note'])) {
        $seed['note'] = __('(seeded from prior run)', 'cleversay');
    }
    if (isset($seed['failures']) && is_array($seed['failures'])) {
        $seed['failures'] = array_slice($seed['failures'], 0, 50);
    }
    $history = [$seed];
    update_option('cleversay_eval_run_history', $history, false);
}

global $wpdb;
$kb_table   = $wpdb->prefix . 'cleversay_knowledge';
$vars_table = $wpdb->prefix . 'cleversay_kb_variations';
$variation_count = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$vars_table} v
     INNER JOIN {$kb_table} k ON k.id = v.knowledge_id
     WHERE k.status = 'active'"
);
?>

<div class="wrap cleversay-admin cleversay-eval">
    <h1>
        <?php echo \CleverSay\Icons::render('check', 20); ?>
        <?php esc_html_e('Eval', 'cleversay'); ?>
    </h1>

    <p class="description" style="max-width: 720px; margin: 12px 0 24px;">
        <?php
        printf(
            esc_html__(
                'Runs every variation in your knowledge base through the matcher and checks whether each variation matches its own entry. The result is a single number — recall@1, the percentage of variations that correctly matched. Below that, you get a per-keyword breakdown and a list of every failure so you can see exactly what\'s missing. There are %d active variations to evaluate.',
                'cleversay'
            ),
            $variation_count
        );
        ?>
    </p>

    <p>
        <label for="cs-eval-note" style="display:block; font-weight:600; margin-bottom:4px;">
            <?php esc_html_e('Note for this run (optional)', 'cleversay'); ?>
        </label>
        <input type="text" id="cs-eval-note" class="regular-text"
               placeholder="<?php esc_attr_e('e.g. after v4.37.4 install, with stopwords disabled, etc.', 'cleversay'); ?>"
               style="max-width: 480px;">
    </p>

    <p>
        <button type="button" class="button button-primary button-large" id="cs-eval-run-btn">
            <?php esc_html_e('Run evaluation', 'cleversay'); ?>
        </button>
        <span id="cs-eval-progress-wrap" style="display:none; margin-left: 16px;">
            <span id="cs-eval-progress-text"></span>
            <progress id="cs-eval-progress" max="100" value="0" style="width: 240px; vertical-align: middle;"></progress>
        </span>
    </p>

    <div id="cs-eval-results" style="display:none; margin-top: 24px;"></div>

    <?php if (!empty($history)): ?>
    <hr style="margin: 32px 0 24px;">
    <h2 style="margin: 0 0 6px;"><?php esc_html_e('Run history', 'cleversay'); ?></h2>
    <p class="description" style="margin: 0 0 12px;">
        <?php
        printf(
            esc_html__('Most recent %d run(s). Click any row to see its full breakdown.', 'cleversay'),
            count($history)
        );
        ?>
    </p>
    <table class="cs-eval-table cs-eval-history-table">
        <thead>
            <tr>
                <th style="width: 170px;"><?php esc_html_e('When', 'cleversay'); ?></th>
                <th style="width: 90px;"><?php esc_html_e('Version', 'cleversay'); ?></th>
                <th style="width: 90px; text-align:right;"><?php esc_html_e('Recall', 'cleversay'); ?></th>
                <th style="text-align:right;">Δ</th>
                <th style="width: 80px; text-align:right;"><?php esc_html_e('Correct', 'cleversay'); ?></th>
                <th style="width: 80px; text-align:right;"><?php esc_html_e('Wrong', 'cleversay'); ?></th>
                <th style="width: 80px; text-align:right;"><?php esc_html_e('aadefault', 'cleversay'); ?></th>
                <th style="width: 80px; text-align:right;"><?php esc_html_e('No match', 'cleversay'); ?></th>
                <th><?php esc_html_e('Note', 'cleversay'); ?></th>
                <th style="width: 50px;"></th>
            </tr>
        </thead>
        <tbody>
            <?php
            // Pre-compute pcts so deltas can compare adjacent rows.
            $rendered = [];
            foreach ($history as $idx => $r) {
                $tot = max(1, (int) ($r['total']   ?? 0));
                $cor = (int) ($r['correct'] ?? 0);
                $rendered[$idx] = [
                    'pct' => $tot > 0 ? round($cor * 100 / $tot, 1) : 0.0,
                    'r'   => $r,
                ];
            }
            foreach ($rendered as $idx => $row):
                $r   = $row['r'];
                $pct = $row['pct'];
                // Delta vs the next-newer entry (previous run; idx+1
                // since history is newest-first).
                $delta_str = '';
                $delta_cls = '';
                if (isset($rendered[$idx + 1])) {
                    $prev = $rendered[$idx + 1]['pct'];
                    $d = round($pct - $prev, 1);
                    if ($d > 0)      { $delta_str = '+' . $d . '%'; $delta_cls = 'cs-eval-delta-pos'; }
                    elseif ($d < 0)  { $delta_str = $d . '%';       $delta_cls = 'cs-eval-delta-neg'; }
                    else             { $delta_str = '·';            $delta_cls = 'cs-eval-delta-eq'; }
                }
                $tot = max(1, (int) ($r['total']   ?? 0));
                $cor = (int) ($r['correct'] ?? 0);
                $wro = (int) ($r['wrong_entry'] ?? 0);
                $aad = (int) ($r['aadefault_fallback'] ?? 0);
                $nom = (int) ($r['no_match'] ?? 0);
                $pct_cls = $pct < 80 ? 'cs-eval-pct--low' : ($pct < 95 ? 'cs-eval-pct--mid' : 'cs-eval-pct--high');
            ?>
            <tr class="cs-eval-history-row" data-run-id="<?php echo esc_attr($r['run_id'] ?? ''); ?>">
                <td><?php echo esc_html($r['saved_at'] ?? ''); ?></td>
                <td><?php echo esc_html($r['plugin_version'] ?? ''); ?></td>
                <td style="text-align:right;" class="cs-eval-pct <?php echo esc_attr($pct_cls); ?>">
                    <?php echo esc_html($pct); ?>%
                </td>
                <td style="text-align:right;" class="<?php echo esc_attr($delta_cls); ?>">
                    <?php echo esc_html($delta_str); ?>
                </td>
                <td style="text-align:right;"><?php echo (int) $cor; ?></td>
                <td style="text-align:right;"><?php echo (int) $wro; ?></td>
                <td style="text-align:right;"><?php echo (int) $aad; ?></td>
                <td style="text-align:right;"><?php echo (int) $nom; ?></td>
                <td><?php echo esc_html($r['note'] ?? ''); ?></td>
                <td style="text-align:right;">
                    <button type="button" class="button-link cs-eval-delete-run"
                            data-run-id="<?php echo esc_attr($r['run_id'] ?? ''); ?>"
                            title="<?php esc_attr_e('Delete this run from history', 'cleversay'); ?>"
                            style="color:#a00; text-decoration: none;">×</button>
                </td>
            </tr>
            <tr class="cs-eval-history-detail" data-run-id="<?php echo esc_attr($r['run_id'] ?? ''); ?>"
                style="display:none; background:#fafafb;">
                <td colspan="10" style="padding: 16px 24px;">
                    <?php
                    $per_kw = is_array($r['per_keyword'] ?? null) ? $r['per_keyword'] : [];
                    $failures = is_array($r['failures'] ?? null) ? $r['failures'] : [];
                    if (!empty($per_kw)):
                    ?>
                    <h3 style="margin:0 0 6px;"><?php esc_html_e('Per-keyword (worst first)', 'cleversay'); ?></h3>
                    <?php
                    $kw_rows = [];
                    foreach ($per_kw as $kw => $k) {
                        $kt = (int) ($k['total'] ?? 0);
                        $kc = (int) ($k['correct'] ?? 0);
                        $kw_rows[] = [
                            'kw'      => $kw,
                            'total'   => $kt,
                            'correct' => $kc,
                            'pct'     => $kt > 0 ? round($kc / $kt * 100, 1) : 0,
                        ];
                    }
                    usort($kw_rows, function($a, $b) {
                        if ($a['pct'] !== $b['pct']) return $a['pct'] <=> $b['pct'];
                        return $b['total'] <=> $a['total'];
                    });
                    $kw_rows = array_slice($kw_rows, 0, 10);
                    ?>
                    <table class="cs-eval-table" style="margin-bottom: 16px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Keyword', 'cleversay'); ?></th>
                                <th style="text-align:right;"><?php esc_html_e('Total', 'cleversay'); ?></th>
                                <th style="text-align:right;"><?php esc_html_e('Correct', 'cleversay'); ?></th>
                                <th style="text-align:right;">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($kw_rows as $kr):
                                $cls = $kr['pct'] < 80 ? 'cs-eval-pct--low' : ($kr['pct'] < 95 ? 'cs-eval-pct--mid' : 'cs-eval-pct--high');
                            ?>
                            <tr>
                                <td><?php echo esc_html($kr['kw']); ?></td>
                                <td style="text-align:right;"><?php echo (int) $kr['total']; ?></td>
                                <td style="text-align:right;"><?php echo (int) $kr['correct']; ?></td>
                                <td style="text-align:right;" class="cs-eval-pct <?php echo esc_attr($cls); ?>">
                                    <?php echo esc_html($kr['pct']); ?>%
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>

                    <?php if (!empty($failures)): ?>
                    <h3 style="margin:0 0 6px;">
                        <?php
                        printf(
                            esc_html__('Failures (showing %d, sample stored)', 'cleversay'),
                            count($failures)
                        );
                        ?>
                    </h3>
                    <div class="cs-eval-failures">
                        <?php foreach (array_slice($failures, 0, 50) as $f):
                            $expected_kw = (string) ($f['expected_keyword'] ?? '');
                            $expected_id = (int)    ($f['expected_id'] ?? 0);
                            $edit_url    = $expected_id > 0 && $expected_kw !== ''
                                ? admin_url(
                                    'admin.php?page=cleversay-knowledge&action=edit-phrase-group'
                                    . '&keyword='  . urlencode($expected_kw)
                                    . '&group_id=' . $expected_id
                                )
                                : '';
                        ?>
                        <div class="cs-eval-failure">
                            <div class="cs-eval-failure-query">
                                <?php echo esc_html((string) ($f['query'] ?? '')); ?>
                                <?php if ($edit_url !== ''): ?>
                                    <a href="<?php echo esc_url($edit_url); ?>" target="_blank" rel="noopener"
                                       class="cs-eval-edit-link"
                                       title="<?php esc_attr_e('Open this entry in a new tab', 'cleversay'); ?>">
                                        <?php esc_html_e('edit ↗', 'cleversay'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="cs-eval-failure-detail">
                                <?php esc_html_e('Expected:', 'cleversay'); ?>
                                <code><?php
                                    echo esc_html(($f['expected_keyword'] ?? '?') . ' / ' . ($f['expected_sub_keyword'] ?: 'aadefault'));
                                ?></code>
                                <?php if (!empty($f['matched_id'])): ?>
                                    &middot; <?php esc_html_e('Got:', 'cleversay'); ?>
                                    <code><?php
                                        echo esc_html(($f['matched_keyword'] ?? '?') . ' / ' . ($f['matched_sub_keyword'] ?: 'aadefault'));
                                    ?></code>
                                    <span style="color:#888;">(<?php
                                        printf(esc_html__('score %d', 'cleversay'), (int) ($f['matched_score'] ?? 0));
                                    ?>)</span>
                                <?php else: ?>
                                    &middot; <em><?php esc_html_e('no match', 'cleversay'); ?></em>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<style>
.cs-eval-last-run {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-left: 4px solid #2271b1;
    border-radius: 4px;
    padding: 14px 18px;
    margin: 16px 0 24px;
    max-width: 720px;
}

.cs-eval-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px;
    margin: 0 0 24px;
}
.cs-eval-stat {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 14px 18px;
}
.cs-eval-stat-num {
    font-size: 28px;
    font-weight: 600;
    line-height: 1.1;
}
.cs-eval-stat-label {
    color: #646970;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    margin-top: 4px;
}
.cs-eval-stat--correct .cs-eval-stat-num { color: #00a32a; }
.cs-eval-stat--wrong   .cs-eval-stat-num { color: #d63638; }
.cs-eval-stat--aadefault .cs-eval-stat-num { color: #dba617; }
.cs-eval-stat--no_match  .cs-eval-stat-num { color: #646970; }

.cs-eval-table {
    width: 100%;
    background: #fff;
    border-collapse: collapse;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    overflow: hidden;
    margin: 16px 0 24px;
}
.cs-eval-table th, .cs-eval-table td {
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid #f0f0f1;
    font-size: 13px;
    vertical-align: top;
}
.cs-eval-table th {
    background: #f6f7f7;
    font-weight: 600;
    color: #1d2327;
    border-bottom: 1px solid #c3c4c7;
}
.cs-eval-table tr:last-child td {
    border-bottom: none;
}
.cs-eval-table .cs-eval-pct {
    font-weight: 600;
}
.cs-eval-table .cs-eval-pct--low  { color: #d63638; }
.cs-eval-table .cs-eval-pct--mid  { color: #dba617; }
.cs-eval-table .cs-eval-pct--high { color: #00a32a; }

.cs-eval-history-table tbody tr.cs-eval-history-row {
    cursor: pointer;
}
.cs-eval-history-table tbody tr.cs-eval-history-row:hover {
    background: #f6f7f7;
}
.cs-eval-history-table tbody tr.cs-eval-history-row.cs-eval-row-open {
    background: #f0f6fc;
}
.cs-eval-delta-pos { color: #00a32a; font-weight: 600; }
.cs-eval-delta-neg { color: #d63638; font-weight: 600; }
.cs-eval-delta-eq  { color: #c3c4c7; }

.cs-eval-failures details {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    margin: 12px 0;
    padding: 0;
}
.cs-eval-failures summary {
    cursor: pointer;
    padding: 12px 18px;
    background: #f6f7f7;
    font-weight: 600;
    user-select: none;
}
.cs-eval-failures details[open] summary {
    border-bottom: 1px solid #c3c4c7;
}
.cs-eval-failures .cs-eval-failure {
    padding: 12px 18px;
    border-bottom: 1px solid #f0f0f1;
    font-size: 13px;
}
.cs-eval-failures .cs-eval-failure:last-child {
    border-bottom: none;
}
.cs-eval-failures .cs-eval-failure-query {
    font-weight: 500;
    color: #1d2327;
    margin-bottom: 4px;
}
.cs-eval-failures .cs-eval-edit-link {
    margin-left: 8px;
    font-weight: normal;
    font-size: 12px;
    color: #2271b1;
    text-decoration: none;
    opacity: 0.65;
}
.cs-eval-failures .cs-eval-failure:hover .cs-eval-edit-link {
    opacity: 1;
}
.cs-eval-failures .cs-eval-edit-link:hover {
    text-decoration: underline;
}
.cs-eval-failures .cs-eval-failure-detail {
    color: #646970;
    margin: 2px 0;
}
.cs-eval-failures .cs-eval-failure-detail code {
    background: #f0f0f1;
    padding: 1px 6px;
    border-radius: 2px;
    font-size: 12px;
}
.cs-eval-failures .cs-eval-bucket-no_match  summary { background: #f0f0f1; }
.cs-eval-failures .cs-eval-bucket-aadefault_fallback summary { background: #fdf8ee; }
.cs-eval-failures .cs-eval-bucket-wrong_entry summary { background: #fcefef; }
</style>

<script>
jQuery(function($) {
    'use strict';

    const $btn      = $('#cs-eval-run-btn');
    const $progress = $('#cs-eval-progress');
    const $progText = $('#cs-eval-progress-text');
    const $progWrap = $('#cs-eval-progress-wrap');
    const $results  = $('#cs-eval-results');

    // Base URL for the per-entry editor — failure rows append
    // &keyword=...&group_id=... to open the failing entry in a
    // new tab.
    const csEvalEditBase = '<?php echo esc_js(admin_url('admin.php?page=cleversay-knowledge&action=edit-phrase-group')); ?>';

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    function pctClass(p) {
        if (p < 80) return 'cs-eval-pct--low';
        if (p < 95) return 'cs-eval-pct--mid';
        return 'cs-eval-pct--high';
    }

    function bucketLabel(b) {
        return {
            correct:             '<?php echo esc_js(__('Correct', 'cleversay')); ?>',
            wrong_entry:         '<?php echo esc_js(__('Wrong entry', 'cleversay')); ?>',
            aadefault_fallback:  '<?php echo esc_js(__('aadefault fallback', 'cleversay')); ?>',
            no_match:            '<?php echo esc_js(__('No match', 'cleversay')); ?>'
        }[b] || b;
    }

    $btn.on('click', function() {
        $btn.prop('disabled', true);
        $progWrap.show();
        $progText.text('<?php echo esc_js(__('Starting…', 'cleversay')); ?>');
        $progress.attr('value', 0);
        $results.hide().empty();
        runBatched();
    });

    // Accumulators across batches.
    let stats;

    function resetStats() {
        stats = {
            total:              0,
            correct:            0,
            wrong_entry:        0,
            aadefault_fallback: 0,
            no_match:           0,
            duration_ms:        0,
            per_keyword:        {},   // keyword → { total, correct }
            failures:           [],
            started_at_ms:      Date.now(),
        };
    }

    function runBatched() {
        resetStats();
        nextBatch(0);
    }

    function nextBatch(offset) {
        $.post(ajaxurl, {
            action: 'cleversay_run_eval',
            nonce:  cleversayAdmin && cleversayAdmin.nonce ? cleversayAdmin.nonce : '',
            offset: offset,
            batch:  50
        }).done(function(resp) {
            if (!resp || !resp.success || !resp.data) {
                $progText.text('<?php echo esc_js(__('Error during eval — check the browser console.', 'cleversay')); ?>');
                $btn.prop('disabled', false);
                return;
            }
            const d = resp.data;

            (d.results || []).forEach(function(row) {
                stats.total++;
                if (row.bucket === 'correct')             stats.correct++;
                else if (row.bucket === 'wrong_entry')    stats.wrong_entry++;
                else if (row.bucket === 'aadefault_fallback') stats.aadefault_fallback++;
                else if (row.bucket === 'no_match')       stats.no_match++;

                const kw = row.expected_keyword || '(no keyword)';
                if (!stats.per_keyword[kw]) {
                    stats.per_keyword[kw] = { total: 0, correct: 0 };
                }
                stats.per_keyword[kw].total++;
                if (row.bucket === 'correct') {
                    stats.per_keyword[kw].correct++;
                } else {
                    stats.failures.push(row);
                }
            });

            const total = d.total || 1;
            const offsetNow = d.offset || 0;
            const pct = Math.min(100, Math.round((offsetNow / total) * 100));
            $progress.attr('value', pct);
            $progText.text(offsetNow + ' / ' + total + ' (' + pct + '%)');

            if (d.done) {
                stats.duration_ms = Date.now() - stats.started_at_ms;
                renderResults();
                saveRun();
                $btn.prop('disabled', false);
                $progText.text('<?php echo esc_js(__('Done.', 'cleversay')); ?>');
            } else {
                nextBatch(offsetNow);
            }
        }).fail(function() {
            $progText.text('<?php echo esc_js(__('Network error during eval.', 'cleversay')); ?>');
            $btn.prop('disabled', false);
        });
    }

    function saveRun() {
        const note = $('#cs-eval-note').val() || '';
        $.post(ajaxurl, {
            action: 'cleversay_save_eval_run',
            nonce:  cleversayAdmin && cleversayAdmin.nonce ? cleversayAdmin.nonce : '',
            note:   note,
            stats:  JSON.stringify({
                total:              stats.total,
                correct:            stats.correct,
                wrong_entry:        stats.wrong_entry,
                aadefault_fallback: stats.aadefault_fallback,
                no_match:           stats.no_match,
                duration_ms:        stats.duration_ms,
                per_keyword:        stats.per_keyword,
                failures:           stats.failures
            })
        }).done(function() {
            // Reload so the new entry appears in the history table
            // below. The in-page results stay visible thanks to the
            // hash anchor — also clears the note so admin can write
            // a new one for the next run.
            window.setTimeout(function() {
                window.location.href = window.location.pathname + '?page=cleversay-eval&saved=1';
            }, 800);
        });
    }

    // Toggle history-row detail.
    $(document).on('click', '.cs-eval-history-row', function(e) {
        if ($(e.target).hasClass('cs-eval-delete-run')) return; // delete handler
        const runId = $(this).data('run-id');
        const $detail = $('.cs-eval-history-detail[data-run-id="' + runId + '"]');
        if ($detail.is(':visible')) {
            $detail.hide();
            $(this).removeClass('cs-eval-row-open');
        } else {
            // Close any other open ones first.
            $('.cs-eval-history-detail').hide();
            $('.cs-eval-history-row').removeClass('cs-eval-row-open');
            $detail.show();
            $(this).addClass('cs-eval-row-open');
        }
    });

    // Delete a single history entry.
    $(document).on('click', '.cs-eval-delete-run', function(e) {
        e.stopPropagation(); // don't toggle the row
        if (!confirm('<?php echo esc_js(__('Delete this run from history?', 'cleversay')); ?>')) return;
        const runId = $(this).data('run-id');
        const $btn = $(this);
        $btn.prop('disabled', true);
        $.post(ajaxurl, {
            action: 'cleversay_delete_eval_run',
            nonce:  cleversayAdmin && cleversayAdmin.nonce ? cleversayAdmin.nonce : '',
            run_id: runId
        }).done(function() {
            $('.cs-eval-history-row[data-run-id="' + runId + '"]').remove();
            $('.cs-eval-history-detail[data-run-id="' + runId + '"]').remove();
        }).fail(function() {
            $btn.prop('disabled', false);
        });
    });

    function renderResults() {
        const total = stats.total || 1;
        const pct = (n) => total ? Math.round((n / total) * 1000) / 10 : 0;

        let html = '';

        // Top stats grid.
        html += '<div class="cs-eval-stats">';
        html += statCard('correct',
            stats.correct + ' (' + pct(stats.correct) + '%)',
            '<?php echo esc_js(__('Correct', 'cleversay')); ?>');
        html += statCard('wrong',
            stats.wrong_entry + ' (' + pct(stats.wrong_entry) + '%)',
            '<?php echo esc_js(__('Wrong entry', 'cleversay')); ?>');
        html += statCard('aadefault',
            stats.aadefault_fallback + ' (' + pct(stats.aadefault_fallback) + '%)',
            '<?php echo esc_js(__('aadefault fallback', 'cleversay')); ?>');
        html += statCard('no_match',
            stats.no_match + ' (' + pct(stats.no_match) + '%)',
            '<?php echo esc_js(__('No match', 'cleversay')); ?>');
        html += statCard('',
            stats.total,
            '<?php echo esc_js(__('Total', 'cleversay')); ?>');
        html += statCard('',
            (stats.duration_ms / 1000).toFixed(1) + 's',
            '<?php echo esc_js(__('Duration', 'cleversay')); ?>');
        html += '</div>';

        // Per-keyword breakdown — sorted by % ascending (worst first).
        const kwRows = Object.keys(stats.per_keyword).map(function(kw) {
            const k = stats.per_keyword[kw];
            return {
                keyword: kw,
                total:   k.total,
                correct: k.correct,
                pct:     k.total > 0 ? Math.round((k.correct / k.total) * 1000) / 10 : 0
            };
        }).sort(function(a, b) {
            // Worst-performing keywords first (most actionable). Tie-break by total desc.
            if (a.pct !== b.pct) return a.pct - b.pct;
            return b.total - a.total;
        });

        html += '<h2><?php echo esc_js(__('Per-keyword breakdown', 'cleversay')); ?></h2>';
        html += '<p class="description"><?php echo esc_js(__('Worst-performing keywords first.', 'cleversay')); ?></p>';
        html += '<table class="cs-eval-table"><thead><tr>'
              + '<th><?php echo esc_js(__('Keyword', 'cleversay')); ?></th>'
              + '<th style="text-align:right;"><?php echo esc_js(__('Total', 'cleversay')); ?></th>'
              + '<th style="text-align:right;"><?php echo esc_js(__('Correct', 'cleversay')); ?></th>'
              + '<th style="text-align:right;">%</th>'
              + '</tr></thead><tbody>';
        kwRows.forEach(function(r) {
            html += '<tr>'
                  + '<td>' + escHtml(r.keyword) + '</td>'
                  + '<td style="text-align:right;">' + r.total + '</td>'
                  + '<td style="text-align:right;">' + r.correct + '</td>'
                  + '<td style="text-align:right;" class="cs-eval-pct ' + pctClass(r.pct) + '">'
                  + r.pct + '%</td>'
                  + '</tr>';
        });
        html += '</tbody></table>';

        // Failures grouped by bucket.
        const byBucket = { wrong_entry: [], aadefault_fallback: [], no_match: [] };
        stats.failures.forEach(function(f) {
            if (byBucket[f.bucket]) byBucket[f.bucket].push(f);
        });

        if (stats.failures.length === 0) {
            html += '<p style="margin-top:24px;color:#00a32a;">'
                  + '<?php echo esc_js(__('All variations matched their own entry. Nothing to investigate.', 'cleversay')); ?>'
                  + '</p>';
        } else {
            html += '<h2><?php echo esc_js(__('Failures', 'cleversay')); ?></h2>';
            html += '<div class="cs-eval-failures">';
            ['wrong_entry', 'aadefault_fallback', 'no_match'].forEach(function(bucket) {
                const list = byBucket[bucket];
                if (!list.length) return;
                html += '<details class="cs-eval-bucket-' + bucket + '"' + (bucket === 'wrong_entry' ? ' open' : '') + '>';
                html += '<summary>' + bucketLabel(bucket) + ' (' + list.length + ')</summary>';
                list.forEach(function(f) {
                    html += '<div class="cs-eval-failure">';
                    let queryLine = escHtml(f.query);
                    if (f.expected_id && f.expected_keyword) {
                        const editUrl = csEvalEditBase
                                      + '&keyword='  + encodeURIComponent(f.expected_keyword)
                                      + '&group_id=' + parseInt(f.expected_id, 10);
                        queryLine += ' <a href="' + editUrl + '" target="_blank" rel="noopener" class="cs-eval-edit-link" '
                                  +  'title="<?php echo esc_js(__('Open this entry in a new tab', 'cleversay')); ?>">'
                                  +  '<?php echo esc_js(__('edit ↗', 'cleversay')); ?></a>';
                    }
                    html += '<div class="cs-eval-failure-query">' + queryLine + '</div>';
                    html += '<div class="cs-eval-failure-detail">'
                          + '<?php echo esc_js(__('Expected:', 'cleversay')); ?> '
                          + '<code>' + escHtml(f.expected_keyword + ' / ' + (f.expected_sub_keyword || 'aadefault'))
                          + '</code> '
                          + '<span style="color:#888;">(id=' + f.expected_id + ')</span>'
                          + '</div>';
                    if (f.matched_id) {
                        html += '<div class="cs-eval-failure-detail">'
                              + '<?php echo esc_js(__('Got:', 'cleversay')); ?> '
                              + '<code>' + escHtml((f.matched_keyword || '?') + ' / ' + (f.matched_sub_keyword || 'aadefault'))
                              + '</code> '
                              + '<span style="color:#888;">(id=' + f.matched_id
                              + ', score ' + f.matched_score + ')</span>'
                              + '</div>';
                    } else {
                        html += '<div class="cs-eval-failure-detail">'
                              + '<?php echo esc_js(__('Got:', 'cleversay')); ?> '
                              + '<em><?php echo esc_js(__('no match', 'cleversay')); ?></em>'
                              + '</div>';
                    }
                    html += '</div>';
                });
                html += '</details>';
            });
            html += '</div>';
        }

        $results.html(html).show();
    }

    function statCard(modifier, num, label) {
        const cls = modifier ? ' cs-eval-stat--' + modifier : '';
        return '<div class="cs-eval-stat' + cls + '">'
             + '<div class="cs-eval-stat-num">' + escHtml(num) + '</div>'
             + '<div class="cs-eval-stat-label">' + escHtml(label) + '</div>'
             + '</div>';
    }
});
</script>
