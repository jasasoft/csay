<?php
/**
 * CleverSay Bulk Test admin page
 *
 * v4.42.0+: per-tenant bulk question testing UI. Three modes:
 *   - List: shows recent runs and a "New Run" button.
 *   - Detail: shows results for a specific run, drives the AJAX loop
 *     for any pending rows.
 *   - Upload: form for starting a new run.
 *
 * Mode is determined by the 'mode' query param. Default: list.
 *
 * @package CleverSay
 * @since   4.42.0
 *
 * @var string $mode    'list' | 'detail' | 'upload'
 * @var ?int   $run_id  for detail mode
 * @var array  $runs    for list mode
 * @var ?array $run     for detail mode (run row)
 * @var array  $results for detail mode (results rows)
 * @var array  $pending_ids for detail mode (ids still to process)
 */

if (!defined('ABSPATH')) exit;

$nonce_action = 'cleversay_bulk_test';
$ajax_nonce   = wp_create_nonce($nonce_action);
$page_url     = admin_url('admin.php?page=cleversay-bulk-test');
?>
<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline">
        <?php echo \CleverSay\Icons::render('check-circle', 18); ?>
        <?php esc_html_e('Bulk Question Test', 'cleversay'); ?>
    </h1>
    <hr class="wp-header-end">

    <?php if ($mode === 'list'): ?>
        <p class="description" style="margin-bottom:18px;max-width:780px;">
            <?php esc_html_e(
                'Pre-deployment qualification tool. Upload a CSV of student questions; the runner processes each through the same search path as live traffic and dumps results for offline review. Use to evaluate readiness before enabling the AI-powered chatbot for real users.',
                'cleversay'
            ); ?>
        </p>

        <p>
            <a href="<?php echo esc_url(add_query_arg('mode', 'upload', $page_url)); ?>"
               class="button button-primary">
                <?php esc_html_e('New Run', 'cleversay'); ?>
            </a>
        </p>

        <?php if (empty($runs)): ?>
            <div class="cleversay-table-card" style="padding:20px;">
                <p style="margin:0;"><em>
                    <?php esc_html_e('No runs yet. Click "New Run" to upload a CSV.', 'cleversay'); ?>
                </em></p>
            </div>
        <?php else: ?>
            <div class="cleversay-table-card">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Run', 'cleversay'); ?></th>
                            <th><?php esc_html_e('Label', 'cleversay'); ?></th>
                            <th><?php esc_html_e('Status', 'cleversay'); ?></th>
                            <th><?php esc_html_e('Progress', 'cleversay'); ?></th>
                            <th><?php esc_html_e('Model', 'cleversay'); ?></th>
                            <th><?php esc_html_e('Cost', 'cleversay'); ?></th>
                            <th><?php esc_html_e('Created', 'cleversay'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($runs as $r): ?>
                        <tr>
                            <td>#<?php echo (int) $r['id']; ?></td>
                            <td><?php echo esc_html($r['label'] ?? ''); ?></td>
                            <td>
                                <code><?php echo esc_html($r['status']); ?></code>
                            </td>
                            <td>
                                <?php echo (int) $r['completed_questions']; ?>
                                / <?php echo (int) $r['total_questions']; ?>
                            </td>
                            <td>
                                <?php
                                $m = $r['synthesis_model'] ?? '';
                                $short = $m;
                                if (strpos($m, 'claude-haiku') === 0)         $short = 'Haiku 4.5';
                                elseif (strpos($m, 'claude-sonnet-4-6') === 0) $short = 'Sonnet 4.6';
                                elseif (strpos($m, 'claude-sonnet-4-5') === 0) $short = 'Sonnet 4.5';
                                elseif (strpos($m, 'claude-opus') === 0)      $short = 'Opus 4.6';
                                elseif (strpos($m, 'gemini') === 0)           $short = 'Gemini ' . substr($m, 7, 5);
                                ?>
                                <code title="<?php echo esc_attr($m); ?>"><?php echo esc_html($short); ?></code>
                            </td>
                            <td>$<?php echo number_format((float) $r['total_cost'], 4); ?></td>
                            <td><?php echo esc_html(mysql2date('M j, H:i', $r['created_at'])); ?></td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg(['mode' => 'detail', 'run_id' => $r['id']], $page_url)); ?>"
                                   class="button button-small">
                                    <?php esc_html_e('View', 'cleversay'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php elseif ($mode === 'upload'): ?>
        <p class="description" style="margin-bottom:18px;max-width:780px;">
            <?php esc_html_e(
                'Upload a CSV with one question per row. Optional columns: question (required), notes (optional). Header row required. Cost preview shows total estimated API cost based on the active synthesis model.',
                'cleversay'
            ); ?>
        </p>

        <form method="post" enctype="multipart/form-data" action="">
            <?php wp_nonce_field($nonce_action, 'cleversay_bulk_nonce'); ?>
            <input type="hidden" name="cleversay_bulk_action" value="create_run">

            <table class="form-table">
                <tr>
                    <th><label for="bulk_label"><?php esc_html_e('Label (optional)', 'cleversay'); ?></label></th>
                    <td>
                        <input type="text" id="bulk_label" name="bulk_label" class="regular-text"
                               placeholder="<?php esc_attr_e('e.g. UWSP historical Q4 2025', 'cleversay'); ?>">
                        <p class="description"><?php esc_html_e('A human-readable name for this run, shown on the runs list.', 'cleversay'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="bulk_csv"><?php esc_html_e('CSV file', 'cleversay'); ?></label></th>
                    <td>
                        <input type="file" id="bulk_csv" name="bulk_csv" accept=".csv,text/csv" required>
                        <p class="description">
                            <?php esc_html_e('Format: header row with "question" and optional "notes" columns. UTF-8 encoded. Max 5000 rows per upload.', 'cleversay'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Create Run', 'cleversay'); ?>
                </button>
                <a href="<?php echo esc_url($page_url); ?>" class="button button-secondary">
                    <?php esc_html_e('Cancel', 'cleversay'); ?>
                </a>
            </p>
        </form>

        <p class="description" style="margin-top:30px;font-size:11px;">
            <?php
            $synthesis_model = (string) \CleverSay\NetworkSettings::get_ai_value('synthesis_model', 'claude-sonnet-4-5-20250929');
            $est_per_q = 0.04; // rough average from observed UWSP traffic
            echo esc_html(sprintf(
                /* translators: %1$s = model id, %2$s = cost estimate */
                __('Estimated cost ≈ %2$s per question on the active model (%1$s). 100 questions ≈ $%3$s; 500 ≈ $%4$s.', 'cleversay'),
                $synthesis_model,
                '$' . number_format($est_per_q, 4),
                number_format($est_per_q * 100, 2),
                number_format($est_per_q * 500, 2)
            ));
            ?>
        </p>

    <?php elseif ($mode === 'detail' && $run): ?>
        <p>
            <a href="<?php echo esc_url($page_url); ?>" class="button">
                ← <?php esc_html_e('Back to runs', 'cleversay'); ?>
            </a>
        </p>

        <div class="cleversay-table-card" style="margin-bottom:20px;">
            <div style="padding:14px 18px;border-bottom:1px solid rgba(0,0,0,0.06);display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:14px;font-weight:600;">
                    <?php
                    $title = !empty($run['label'])
                        ? esc_html($run['label'])
                        : sprintf(esc_html__('Run #%d', 'cleversay'), (int) $run['id']);
                    echo $title; // already escaped
                    ?>
                </h3>
                <div>
                    <span id="cleversay-bulk-status">
                        <code><?php echo esc_html($run['status']); ?></code>
                    </span>
                </div>
            </div>
            <div style="padding:14px 18px;">
                <table class="widefat striped">
                    <tbody>
                        <tr>
                            <td style="width:30%;"><strong><?php esc_html_e('Total questions', 'cleversay'); ?></strong></td>
                            <td><?php echo (int) $run['total_questions']; ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Completed', 'cleversay'); ?></strong></td>
                            <td>
                                <span id="cleversay-bulk-completed"><?php echo (int) $run['completed_questions']; ?></span>
                                / <?php echo (int) $run['total_questions']; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Synthesis model', 'cleversay'); ?></strong></td>
                            <td><code><?php echo esc_html($run['synthesis_model'] ?? ''); ?></code></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Total cost so far', 'cleversay'); ?></strong></td>
                            <td>$<span id="cleversay-bulk-cost"><?php echo number_format((float) $run['total_cost'], 4); ?></span></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Started', 'cleversay'); ?></strong></td>
                            <td><?php echo esc_html($run['started_at'] ?? '—'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Finished', 'cleversay'); ?></strong></td>
                            <td><?php echo esc_html($run['finished_at'] ?? '—'); ?></td>
                        </tr>
                    </tbody>
                </table>

                <p style="margin-top:16px;">
                    <?php if (!empty($pending_ids) && $run['status'] !== 'aborted'): ?>
                        <button id="cleversay-bulk-start" class="button button-primary">
                            <?php
                            echo $run['status'] === 'pending'
                                ? esc_html__('Start Processing', 'cleversay')
                                : esc_html__('Resume Processing', 'cleversay');
                            ?>
                        </button>
                        <button id="cleversay-bulk-stop" class="button button-secondary" style="display:none;">
                            <?php esc_html_e('Stop', 'cleversay'); ?>
                        </button>
                        <span class="description" style="margin-left:12px;">
                            <?php esc_html_e('Closing this tab also stops the run.', 'cleversay'); ?>
                        </span>
                    <?php endif; ?>

                    <?php if ($run['status'] === 'completed' || (int) $run['completed_questions'] > 0): ?>
                        <a href="<?php echo esc_url(add_query_arg(['action' => 'cleversay_bulk_test_download', 'run_id' => (int) $run['id'], 'nonce' => $ajax_nonce], admin_url('admin-ajax.php'))); ?>"
                           class="button" style="margin-left:8px;">
                            <?php esc_html_e('Download CSV', 'cleversay'); ?>
                        </a>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <h3><?php esc_html_e('Results', 'cleversay'); ?></h3>
        <div class="cleversay-table-card">
            <table class="widefat striped" id="cleversay-bulk-results-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php esc_html_e('Question', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Status', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Layer', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Top vector', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Total ms', 'cleversay'); ?></th>
                        <th><?php esc_html_e('Cost', 'cleversay'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $row): ?>
                    <tr id="cleversay-bulk-row-<?php echo (int) $row['id']; ?>" data-id="<?php echo (int) $row['id']; ?>">
                        <td><?php echo (int) $row['row_index'] + 1; ?></td>
                        <td title="<?php echo esc_attr($row['question']); ?>">
                            <?php echo esc_html(mb_strimwidth($row['question'], 0, 70, '…')); ?>
                        </td>
                        <td class="cs-bulk-status">
                            <code><?php echo esc_html($row['status']); ?></code>
                        </td>
                        <td class="cs-bulk-layer">
                            <?php
                            $layer = $row['matched_layer'] ?? '';
                            echo $layer !== '' ? '<code>' . esc_html($layer) . '</code>' : '—';
                            ?>
                        </td>
                        <td class="cs-bulk-vec">
                            <?php
                            echo $row['top_vector_similarity'] !== null
                                ? number_format((float) $row['top_vector_similarity'], 3)
                                : '—';
                            ?>
                        </td>
                        <td class="cs-bulk-ms">
                            <?php echo $row['total_ms'] !== null ? (int) $row['total_ms'] . ' ms' : '—'; ?>
                        </td>
                        <td class="cs-bulk-cost">
                            <?php echo $row['cost'] !== null ? '$' . number_format((float) $row['cost'], 4) : '—'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
        (function() {
            var ajaxUrl    = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            var nonce      = '<?php echo esc_js($ajax_nonce); ?>';
            var runId      = <?php echo (int) $run['id']; ?>;
            var pendingIds = <?php echo wp_json_encode($pending_ids); ?>;
            var stopped    = false;

            var startBtn   = document.getElementById('cleversay-bulk-start');
            var stopBtn    = document.getElementById('cleversay-bulk-stop');
            var statusEl   = document.getElementById('cleversay-bulk-status');
            var doneEl     = document.getElementById('cleversay-bulk-completed');
            var costEl     = document.getElementById('cleversay-bulk-cost');

            function setRunStatus(status) {
                if (statusEl) statusEl.innerHTML = '<code>' + status + '</code>';
            }

            function updateRowFromResult(row) {
                var tr = document.getElementById('cleversay-bulk-row-' + row.id);
                if (!tr) return;
                tr.querySelector('.cs-bulk-status').innerHTML = '<code>' + row.status + '</code>';
                tr.querySelector('.cs-bulk-layer').innerHTML  = row.matched_layer
                    ? '<code>' + row.matched_layer + '</code>' : '—';
                tr.querySelector('.cs-bulk-vec').textContent  = row.top_vector_similarity !== null
                    ? Number(row.top_vector_similarity).toFixed(3) : '—';
                tr.querySelector('.cs-bulk-ms').textContent   = row.total_ms !== null
                    ? row.total_ms + ' ms' : '—';
                tr.querySelector('.cs-bulk-cost').textContent = row.cost !== null
                    ? '$' + Number(row.cost).toFixed(4) : '—';
            }

            function processNext() {
                if (stopped || pendingIds.length === 0) {
                    if (stopBtn) stopBtn.style.display = 'none';
                    if (startBtn) startBtn.style.display = pendingIds.length > 0 ? '' : 'none';
                    setRunStatus(pendingIds.length === 0 ? 'completed' : 'aborted');
                    return;
                }
                var resultId = pendingIds.shift();
                var data = new FormData();
                data.append('action', 'cleversay_bulk_test_process');
                data.append('nonce', nonce);
                data.append('result_id', resultId);

                fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        if (resp && resp.success && resp.data && resp.data.result) {
                            updateRowFromResult(resp.data.result);
                            if (resp.data.run) {
                                if (doneEl) doneEl.textContent = resp.data.run.completed_questions;
                                if (costEl) costEl.textContent = Number(resp.data.run.total_cost).toFixed(4);
                            }
                        }
                        setTimeout(processNext, 250);
                    })
                    .catch(function (err) {
                        console.error('[BulkTester] error', err);
                        // Don't infinite-loop on error; halt.
                        stopped = true;
                        setRunStatus('failed');
                    });
            }

            if (startBtn) {
                startBtn.addEventListener('click', function () {
                    stopped = false;
                    startBtn.style.display = 'none';
                    if (stopBtn) stopBtn.style.display = '';
                    setRunStatus('running');
                    processNext();
                });
            }
            if (stopBtn) {
                stopBtn.addEventListener('click', function () {
                    stopped = true;
                    stopBtn.style.display = 'none';
                    if (startBtn) startBtn.style.display = '';
                    setRunStatus('aborted');
                    var data = new FormData();
                    data.append('action', 'cleversay_bulk_test_abort');
                    data.append('nonce', nonce);
                    data.append('run_id', runId);
                    fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' });
                });
            }
        })();
        </script>

    <?php endif; ?>
</div>
