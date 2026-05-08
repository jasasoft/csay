<?php
/**
 * AI A/B Test Harness
 *
 * Lets admins run a fixed list of representative questions through the
 * current AI model, then compare results across model switches over time.
 *
 * Workflow:
 *   1. Edit/save the question list (one per line).
 *   2. Run all questions → results saved to browser localStorage keyed by model.
 *   3. Switch to a different model in Network Admin → Edit Plan → AI Model Override.
 *   4. Return here, run all again → see side-by-side comparison.
 *
 * Storage: localStorage on this admin user's browser. No server-side history;
 * keeps the tool simple and avoids accumulating test data in the DB.
 *
 * @package CleverSay
 */
defined('ABSPATH') || exit;

// Default starter questions — representative of typical campus chatbot queries
$default_questions = [
    'How do I apply for financial aid?',
    'When is the application deadline?',
    'Are freshmen required to live on campus?',
    'What are the housing options?',
    'Who do I contact about disability accommodations?',
    'Where is the registrar\'s office?',
    'How do I order a transcript?',
    'What is the cost of tuition?',
    'How do I drop a class?',
    'Tell me a joke',  // Off-topic — tests CASE 2 deflection
];

$saved_questions = (array) get_option('cleversay_ab_test_questions', []);
$questions       = !empty($saved_questions) ? $saved_questions : $default_questions;

// Determine current model (matching the inference logic in ajax_ab_run_question)
$current_override = (string) get_option('cleversay_ai_model_override', '');
$current_network  = '';
if (function_exists('is_multisite') && is_multisite()) {
    $current_network = (string) (\CleverSay\NetworkSettings::get_ai()['model'] ?? '');
}
$current_model = !empty($current_override)
    ? $current_override
    : (!empty($current_network) ? $current_network : (string) get_option('cleversay_ai_model', 'claude-haiku-4-5-20251001'));

$model_labels = \CleverSay\AI::get_available_models();
?>
<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline" style="display:flex;align-items:center;gap:8px;">
        <?php echo \CleverSay\Icons::render('sparkles', 18); ?>
        <?php esc_html_e('AI A/B Test', 'cleversay'); ?>
    </h1>
    <hr class="wp-header-end">

    <p style="color:#646970;max-width:760px;margin:8px 0 16px;">
        <?php esc_html_e('Run a list of representative questions through the current AI model, save the results, then compare against runs on other models. Useful for deciding whether Sonnet quality justifies the cost over Haiku for your specific content.', 'cleversay'); ?>
    </p>

    <!-- Current model panel -->
    <div class="notice notice-info inline" style="padding:12px 16px;margin:0 0 18px;">
        <p style="margin:0;">
            <strong><?php esc_html_e('Currently using:', 'cleversay'); ?></strong>
            <code style="background:#fff;padding:2px 6px;border:1px solid #dcdcde;border-radius:3px;"><?php echo esc_html($current_model); ?></code>
            &nbsp;<span style="color:#646970;font-size:12px;"><?php echo esc_html($model_labels[$current_model] ?? ''); ?></span>
        </p>
        <p style="margin:8px 0 0;font-size:12px;color:#646970;">
            <?php
            if (function_exists('is_multisite') && is_multisite()) {
                printf(
                    /* translators: %s = link to network sites admin */
                    esc_html__('To change models for this site, edit the AI Model Override in %s.', 'cleversay'),
                    '<a href="' . esc_url(network_admin_url('admin.php?page=cleversay-network-sites')) . '">'
                    . esc_html__('Network Admin → Client Sites → Edit Plan', 'cleversay')
                    . '</a>'
                );
            } else {
                printf(
                    /* translators: %s = link to AI settings */
                    esc_html__('To change models, edit the AI Model in %s.', 'cleversay'),
                    '<a href="' . esc_url(admin_url('admin.php?page=cleversay-settings#ai-settings')) . '">'
                    . esc_html__('Settings → AI Settings', 'cleversay')
                    . '</a>'
                );
            }
            ?>
        </p>
    </div>

    <!-- Question editor -->
    <div class="postbox" style="margin-bottom:18px;">
        <h2 class="hndle" style="padding:10px 14px;font-size:14px;">
            <?php esc_html_e('Test Questions', 'cleversay'); ?>
            <button type="button" class="button-link" id="cs-ab-edit-toggle" style="float:right;font-weight:normal;">
                <?php esc_html_e('Edit', 'cleversay'); ?>
            </button>
        </h2>
        <div class="inside" style="padding:0 14px 14px;">
            <div id="cs-ab-questions-display">
                <ol style="margin:0;padding-left:24px;">
                    <?php foreach ($questions as $q): ?>
                        <li style="margin:4px 0;"><?php echo esc_html($q); ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>
            <div id="cs-ab-questions-edit" style="display:none;">
                <textarea id="cs-ab-questions-textarea" class="large-text" rows="12"
                          style="font-family:monospace;font-size:13px;"><?php
                    echo esc_textarea(implode("\n", $questions));
                ?></textarea>
                <p class="description"><?php esc_html_e('One question per line. Up to 30 questions. The last default question (a joke) tests off-topic refusal behavior.', 'cleversay'); ?></p>
                <p>
                    <button type="button" class="button button-primary" id="cs-ab-save-questions">
                        <?php esc_html_e('Save Questions', 'cleversay'); ?>
                    </button>
                    <button type="button" class="button" id="cs-ab-cancel-edit">
                        <?php esc_html_e('Cancel', 'cleversay'); ?>
                    </button>
                </p>
            </div>
        </div>
    </div>

    <!-- Run controls -->
    <div style="display:flex;gap:12px;align-items:center;margin-bottom:18px;">
        <button type="button" class="button button-primary button-large" id="cs-ab-run-all">
            <?php esc_html_e('▶ Run All Questions', 'cleversay'); ?>
        </button>
        <button type="button" class="button" id="cs-ab-clear-results">
            <?php esc_html_e('Clear Saved Results', 'cleversay'); ?>
        </button>
        <span id="cs-ab-progress" style="color:#646970;font-size:13px;"></span>
    </div>

    <!-- Results area -->
    <div id="cs-ab-results"></div>

    <!-- Embedded data for JS -->
    <script type="application/json" id="cs-ab-data"><?php
        echo wp_json_encode([
            'questions'    => $questions,
            'currentModel' => $current_model,
            'modelLabels'  => $model_labels,
            'nonce'        => wp_create_nonce('cleversay_ab_test'),
            'ajaxUrl'      => admin_url('admin-ajax.php'),
        ]);
    ?></script>
</div>

<style>
.cs-ab-question-block { background:#fff; border:1px solid #dcdcde; border-radius:6px; margin-bottom:14px; padding:14px 18px; }
.cs-ab-question-block h3 { margin:0 0 8px; font-size:14px; color:#1d2327; }
.cs-ab-question-block .cs-ab-q-text { color:#646970; font-style:italic; margin-bottom:10px; font-size:13px; }
.cs-ab-comparison { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:10px; }
.cs-ab-result-card { background:#f6f7f7; border:1px solid #dcdcde; border-radius:4px; padding:10px 12px; font-size:13px; }
.cs-ab-result-card.current { border-color:#2271b1; background:#f0f6fc; }
.cs-ab-model-tag { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; margin-bottom:6px; }
.cs-ab-model-haiku   { background:#fef9e7; color:#946800; }
.cs-ab-model-sonnet  { background:#e7f0fe; color:#0c4595; }
.cs-ab-model-opus    { background:#fbe7f5; color:#7d0470; }
.cs-ab-model-other   { background:#e8e8e8; color:#444; }
.cs-ab-answer { background:#fff; padding:8px 10px; border-radius:3px; border:1px solid #e5e5e7; line-height:1.5; max-height:240px; overflow-y:auto; }
.cs-ab-meta { margin-top:6px; font-size:11px; color:#646970; display:flex; gap:10px; flex-wrap:wrap; }
.cs-ab-meta strong { color:#1d2327; }
.cs-ab-loading { color:#dba617; font-style:italic; }
.cs-ab-error   { color:#d63638; font-weight:600; }
.cs-ab-timestamp { font-size:11px; color:#8c8f94; }
</style>

<script>
(function () {
    const data = JSON.parse(document.getElementById('cs-ab-data').textContent);
    const STORAGE_KEY = 'cleversay_ab_results';

    function loadResults() {
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); }
        catch(e) { return {}; }
    }
    function saveResults(r) {
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(r)); }
        catch(e) {}
    }
    function modelClass(model) {
        if (model.includes('haiku')) return 'cs-ab-model-haiku';
        if (model.includes('sonnet')) return 'cs-ab-model-sonnet';
        if (model.includes('opus')) return 'cs-ab-model-opus';
        return 'cs-ab-model-other';
    }
    function modelLabel(model) {
        return data.modelLabels[model] || model;
    }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
    }

    // Render the comparison view from saved results
    function renderResults() {
        const all = loadResults();
        const target = document.getElementById('cs-ab-results');

        // Group by question — for each question, show all model results
        const byQuestion = {};
        for (const q of data.questions) byQuestion[q] = {};
        for (const model in all) {
            for (const q in all[model]) {
                if (!byQuestion[q]) byQuestion[q] = {};
                byQuestion[q][model] = all[model][q];
            }
        }

        let html = '';
        let qIndex = 0;
        for (const question of data.questions) {
            qIndex++;
            const results = byQuestion[question] || {};
            const modelKeys = Object.keys(results);

            html += '<div class="cs-ab-question-block">';
            html += '<h3>' + qIndex + '. ' + escapeHtml(question) + '</h3>';

            if (modelKeys.length === 0) {
                html += '<p style="color:#8c8f94;font-size:12px;margin:0;">'
                      + 'No results yet — click "Run All Questions" to test against the current model.</p>';
            } else {
                html += '<div class="cs-ab-comparison">';
                for (const model of modelKeys) {
                    const r = results[model];
                    const isCurrent = (model === data.currentModel);
                    html += '<div class="cs-ab-result-card' + (isCurrent ? ' current' : '') + '">';
                    html += '<span class="cs-ab-model-tag ' + modelClass(model) + '">' + escapeHtml(modelLabel(model).split(' — ')[0]) + '</span>';
                    if (isCurrent) html += ' <span style="font-size:10px;color:#2271b1;">(current)</span>';
                    html += '<div class="cs-ab-answer">' + escapeHtml(r.answer || '') + '</div>';
                    html += '<div class="cs-ab-meta">';
                    html += '<span><strong>' + r.elapsed_ms + 'ms</strong></span>';
                    html += '<span><strong>$' + (r.cost || 0).toFixed(5) + '</strong></span>';
                    html += '<span>' + r.tokens_input + ' in / ' + r.tokens_output + ' out</span>';
                    if (r.cache_read > 0) html += '<span style="color:#00a32a;">cache hit (' + r.cache_read + ')</span>';
                    else if (r.cache_create > 0) html += '<span style="color:#dba617;">cache write</span>';
                    html += '</div>';
                    html += '<div class="cs-ab-timestamp">' + escapeHtml(r.timestamp || '') + '</div>';
                    html += '</div>';
                }
                html += '</div>';
            }
            html += '</div>';
        }

        target.innerHTML = html;
    }

    // Run a single question through the API
    async function runQuestion(question) {
        const fd = new FormData();
        fd.append('action', 'cleversay_ab_run_question');
        fd.append('nonce', data.nonce);
        fd.append('question', question);
        const res = await fetch(data.ajaxUrl, { method: 'POST', body: fd });
        const json = await res.json();
        if (!json.success) throw new Error(json.data?.message || 'Request failed');
        return json.data;
    }

    async function runAll() {
        const btn = document.getElementById('cs-ab-run-all');
        const progress = document.getElementById('cs-ab-progress');
        btn.disabled = true;
        const all = loadResults();

        for (let i = 0; i < data.questions.length; i++) {
            const q = data.questions[i];
            progress.textContent = 'Running ' + (i + 1) + ' of ' + data.questions.length + ': ' + q.substring(0, 60) + (q.length > 60 ? '…' : '');
            try {
                const result = await runQuestion(q);
                if (!all[result.model]) all[result.model] = {};
                all[result.model][q] = result;
                saveResults(all);
                renderResults();
            } catch (err) {
                console.error('Question failed:', q, err);
                progress.innerHTML += ' <span class="cs-ab-error">Error: ' + escapeHtml(err.message) + '</span>';
            }
        }

        progress.textContent = 'Done. Switch models and run again to compare.';
        btn.disabled = false;
    }

    // Edit toggle
    document.getElementById('cs-ab-edit-toggle').addEventListener('click', () => {
        document.getElementById('cs-ab-questions-display').style.display = 'none';
        document.getElementById('cs-ab-questions-edit').style.display = 'block';
    });
    document.getElementById('cs-ab-cancel-edit').addEventListener('click', () => {
        document.getElementById('cs-ab-questions-display').style.display = 'block';
        document.getElementById('cs-ab-questions-edit').style.display = 'none';
    });

    document.getElementById('cs-ab-save-questions').addEventListener('click', async () => {
        const text = document.getElementById('cs-ab-questions-textarea').value;
        const fd = new FormData();
        fd.append('action', 'cleversay_ab_save_questions');
        fd.append('nonce', data.nonce);
        fd.append('questions', text);
        try {
            const res = await fetch(data.ajaxUrl, { method: 'POST', body: fd });
            const json = await res.json();
            if (json.success) {
                location.reload();
            } else {
                alert('Save failed: ' + (json.data?.message || 'Unknown error'));
            }
        } catch (e) {
            alert('Save failed: ' + e.message);
        }
    });

    document.getElementById('cs-ab-run-all').addEventListener('click', runAll);
    document.getElementById('cs-ab-clear-results').addEventListener('click', () => {
        if (confirm('Clear all saved test results? This only affects this browser.')) {
            localStorage.removeItem(STORAGE_KEY);
            renderResults();
        }
    });

    renderResults();
})();
</script>
