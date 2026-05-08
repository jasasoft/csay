<?php
/**
 * Recompile Patterns Admin View
 *
 * Bulk recompilation tool for KB sub_keyword patterns. Workflow:
 *   1. Admin selects scope (whole KB or single keyword bucket).
 *   2. Click "Run dry-run" — page chunks through eligible entries
 *      via AJAX, calling the deterministic compiler against current
 *      variations + siblings, accumulating a report client-side.
 *   3. Report shows entries that WOULD change, with old vs new
 *      patterns side by side. Unchanged/empty/error entries roll
 *      up into summary counts.
 *   4. Admin reviews. Selects which entries to apply (default: all
 *      changed). Clicks "Apply X entries".
 *   5. Server re-compiles fresh on apply (in case variations changed
 *      between dry-run and apply) and writes patterns.
 *
 * Why dry-run-only by default: pattern changes affect runtime
 * matching for live users. A bad bulk recompile would silently
 * route students to the wrong answers. Dry-run forces deliberate
 * review.
 *
 * @package CleverSay
 * @since   4.37.51
 */

if (!defined('ABSPATH')) { exit; }

global $wpdb;
$kb_table = $wpdb->prefix . 'cleversay_knowledge';

// Build the keyword dropdown — every distinct active keyword with
// entry counts. Sorted alphabetically.
$keyword_rows = $wpdb->get_results(
    "SELECT keyword, COUNT(*) AS n
     FROM {$kb_table}
     WHERE status = 'active'
       AND sub_keyword != ''
       AND LOWER(sub_keyword) != 'aadefault'
     GROUP BY keyword
     ORDER BY keyword ASC",
    ARRAY_A
);

// Total eligible entry count for whole-KB scope. Excludes
// aadefault and empty-pattern rows since those don't get
// recompiled.
$total_eligible = (int) $wpdb->get_var(
    "SELECT COUNT(*)
     FROM {$kb_table}
     WHERE status = 'active'
       AND sub_keyword != ''
       AND LOWER(sub_keyword) != 'aadefault'"
);

$nonce = wp_create_nonce('cleversay_nonce');
?>

<div class="wrap cleversay-admin">
    <h1>
        <?php echo \CleverSay\Icons::render('refresh-cw', 22); ?>
        <?php esc_html_e('Recompile Patterns', 'cleversay'); ?>
    </h1>

    <p style="font-size:13px; color:#555; max-width:900px; margin-top:8px;">
        <?php esc_html_e('Sweep the KB and re-run the deterministic compiler against current variations + siblings. Useful after compiler logic changes (new stopwords, new POS rules, new boost categories) so existing entries pick up the improvements without manual per-entry recompile clicks.', 'cleversay'); ?>
    </p>
    <p style="font-size:13px; color:#555; max-width:900px;">
        <?php esc_html_e('Dry-run only by default — review the report before applying. Apply re-computes fresh so concurrent edits between dry-run and apply don\'t get clobbered.', 'cleversay'); ?>
    </p>

    <hr class="wp-header-end">

    <!-- Scope picker -->
    <div style="margin:18px 0; padding:16px; background:#f6f7f7; border:1px solid #ddd; border-radius:4px;">
        <h2 style="margin:0 0 12px; font-size:14px;"><?php esc_html_e('Scope', 'cleversay'); ?></h2>
        <div style="display:flex; gap:24px; align-items:flex-start;">
            <label style="cursor:pointer;">
                <input type="radio" name="cs-scope" value="all" checked>
                <strong><?php esc_html_e('Whole KB', 'cleversay'); ?></strong>
                <span style="color:#666; font-size:12px; margin-left:6px;">
                    (<?php echo number_format_i18n($total_eligible); ?> <?php esc_html_e('eligible entries', 'cleversay'); ?>)
                </span>
            </label>
            <label style="cursor:pointer; display:flex; gap:8px; align-items:center;">
                <input type="radio" name="cs-scope" value="keyword">
                <strong><?php esc_html_e('Single keyword:', 'cleversay'); ?></strong>
                <select id="cs-scope-keyword" disabled style="min-width:220px;">
                    <option value=""><?php esc_html_e('— choose a keyword —', 'cleversay'); ?></option>
                    <?php foreach ($keyword_rows as $kw): ?>
                        <option value="<?php echo esc_attr($kw['keyword']); ?>"
                                data-count="<?php echo (int) $kw['n']; ?>">
                            <?php echo esc_html($kw['keyword']); ?>
                            (<?php echo (int) $kw['n']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div style="margin-top:14px;">
            <button type="button" id="cs-run-dryrun" class="button button-primary">
                <?php echo \CleverSay\Icons::render('play', 14); ?>
                <?php esc_html_e('Run dry-run', 'cleversay'); ?>
            </button>
            <span id="cs-dryrun-progress" style="margin-left:12px; color:#666; font-size:13px;"></span>
        </div>
    </div>

    <!-- Summary tiles (hidden until dry-run starts) -->
    <div id="cs-summary" style="display:none; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; margin-bottom:18px;">
        <div class="cs-tile" data-bucket="changed">
            <div class="cs-tile-label"><?php esc_html_e('Would change', 'cleversay'); ?></div>
            <div class="cs-tile-value" id="cs-count-changed">0</div>
        </div>
        <div class="cs-tile" data-bucket="unchanged">
            <div class="cs-tile-label"><?php esc_html_e('Unchanged', 'cleversay'); ?></div>
            <div class="cs-tile-value" id="cs-count-unchanged">0</div>
        </div>
        <div class="cs-tile" data-bucket="empty">
            <div class="cs-tile-label"><?php esc_html_e('Empty / no variations', 'cleversay'); ?></div>
            <div class="cs-tile-value" id="cs-count-empty">0</div>
        </div>
        <div class="cs-tile" data-bucket="error">
            <div class="cs-tile-label"><?php esc_html_e('Errors', 'cleversay'); ?></div>
            <div class="cs-tile-value" id="cs-count-error">0</div>
        </div>
    </div>

    <!-- Apply controls (hidden until dry-run completes) -->
    <div id="cs-apply-bar" style="display:none; margin-bottom:14px; padding:12px 16px; background:#fff3cd; border:1px solid #ffc107; border-radius:4px;">
        <strong style="font-size:14px;">
            <?php echo \CleverSay\Icons::render('alert-triangle', 14); ?>
            <?php esc_html_e('Review the changes below before applying.', 'cleversay'); ?>
        </strong>
        <div style="margin-top:8px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <button type="button" id="cs-apply-btn" class="button button-primary" disabled>
                <?php esc_html_e('Apply selected', 'cleversay'); ?>
                (<span id="cs-apply-count">0</span>)
            </button>
            <button type="button" id="cs-toggle-all" class="button button-small">
                <?php esc_html_e('Toggle all', 'cleversay'); ?>
            </button>
            <span id="cs-apply-status" style="color:#666; font-size:13px;"></span>
        </div>
    </div>

    <!-- Results table (hidden until dry-run starts) -->
    <div id="cs-results-wrap" style="display:none;">
        <h2 style="font-size:15px;"><?php esc_html_e('Entries that would change', 'cleversay'); ?></h2>
        <table class="widefat striped" id="cs-results-table" style="margin-top:8px;">
            <thead>
                <tr>
                    <th style="width:40px;"><input type="checkbox" id="cs-select-all" checked></th>
                    <th><?php esc_html_e('Keyword', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Old pattern', 'cleversay'); ?></th>
                    <th><?php esc_html_e('New pattern', 'cleversay'); ?></th>
                </tr>
            </thead>
            <tbody id="cs-results-body"></tbody>
        </table>
        <p id="cs-results-empty" style="display:none; padding:14px; color:#666; background:#f6f7f7; border-radius:4px;">
            <?php esc_html_e('No entries would change. Stored patterns already match what the compiler would produce.', 'cleversay'); ?>
        </p>
    </div>
</div>

<style>
.cs-tile {
    padding:14px 16px;
    background:white;
    border:1px solid #ddd;
    border-radius:4px;
}
.cs-tile-label {
    font-size:11px;
    color:#666;
    text-transform:uppercase;
    letter-spacing:.04em;
    margin-bottom:4px;
}
.cs-tile-value { font-size:24px; font-weight:600; }
.cs-tile[data-bucket="changed"]  { border-left:4px solid #2271b1; }
.cs-tile[data-bucket="error"]    { border-left:4px solid #d63638; }
#cs-results-table code {
    font-size:12px;
    background:#f6f7f7;
    padding:2px 5px;
    border-radius:3px;
    word-break:break-all;
}
#cs-results-table tr.applied {
    background: #d4edda !important;
}
#cs-results-table tr.error {
    background: #f8d7da !important;
}
</style>

<script>
jQuery(function($) {
    const ajaxNonce  = <?php echo wp_json_encode($nonce); ?>;
    const totalAll   = <?php echo (int) $total_eligible; ?>;
    const CHUNK_SIZE = 25;

    const $scopeRadios = $('input[name="cs-scope"]');
    const $kwSelect    = $('#cs-scope-keyword');
    const $runBtn      = $('#cs-run-dryrun');
    const $progress    = $('#cs-dryrun-progress');
    const $summary     = $('#cs-summary');
    const $applyBar    = $('#cs-apply-bar');
    const $resultsWrap = $('#cs-results-wrap');
    const $resultsBody = $('#cs-results-body');
    const $resultsEmpty = $('#cs-results-empty');
    const counts = { changed:0, unchanged:0, empty:0, error:0 };

    // Enable keyword select only when scope=keyword
    $scopeRadios.on('change', function() {
        $kwSelect.prop('disabled', $('input[name="cs-scope"]:checked').val() !== 'keyword');
    });

    function escHtml(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

    // Reset state for a fresh run
    function resetState() {
        Object.keys(counts).forEach(k => counts[k] = 0);
        Object.keys(counts).forEach(k => $('#cs-count-' + k).text('0'));
        $resultsBody.empty();
        $resultsEmpty.hide();
        $resultsWrap.hide();
        $applyBar.hide();
        $('#cs-apply-status').text('');
    }

    // Append a result row to the table
    function appendRow(r) {
        if (r.status === 'changed') {
            const tr = $('<tr>').attr('data-id', r.id);
            tr.append('<td><input type="checkbox" class="cs-row-pick" checked></td>');
            tr.append('<td><strong>' + escHtml(r.keyword) + '</strong><br><span style="color:#888;font-size:11px;">id ' + r.id + '</span></td>');
            tr.append('<td><code>' + escHtml(r.old_pattern) + '</code></td>');
            tr.append('<td><code style="color:#00a32a;">' + escHtml(r.new_pattern) + '</code></td>');
            $resultsBody.append(tr);
        }
        counts[r.status] = (counts[r.status] || 0) + 1;
        $('#cs-count-' + r.status).text(counts[r.status]);
    }

    function updateApplyButton() {
        const n = $('.cs-row-pick:checked').length;
        $('#cs-apply-count').text(n);
        $('#cs-apply-btn').prop('disabled', n === 0);
    }

    // Render IDs per keyword at page-load so we can chunk locally
    // without round-trips just to enumerate entries. Cheap — only
    // IDs (a few KB even for large KBs).
    const idsByKeyword = <?php
        $bykw = [];
        $rows = $wpdb->get_results(
            "SELECT id, keyword
             FROM {$kb_table}
             WHERE status = 'active'
               AND sub_keyword != ''
               AND LOWER(sub_keyword) != 'aadefault'
             ORDER BY id ASC",
            ARRAY_A
        );
        foreach (($rows ?: []) as $r) {
            $kw = (string) $r['keyword'];
            if (!isset($bykw[$kw])) $bykw[$kw] = [];
            $bykw[$kw][] = (int) $r['id'];
        }
        echo wp_json_encode($bykw);
    ?>;

    // Run the dry-run sweep. Walks eligible keywords, chunks each
    // keyword's IDs, sends each chunk to the server for compile,
    // appends results to the table incrementally so admin sees
    // progress.
    async function runDryRun() {
        const scope = $('input[name="cs-scope"]:checked').val();
        const kw    = $kwSelect.val();

        if (scope === 'keyword' && !kw) {
            alert('<?php echo esc_js(__('Choose a keyword first.', 'cleversay')); ?>');
            return;
        }

        $runBtn.prop('disabled', true);
        $progress.text('<?php echo esc_js(__('Loading eligible entries…', 'cleversay')); ?>');
        resetState();

        const targetKeywords = scope === 'keyword'
            ? [kw]
            : Object.keys(idsByKeyword);

        $resultsWrap.show();
        $summary.css('display', 'grid');

        let processed = 0;

        for (const targetKw of targetKeywords) {
            const ids = idsByKeyword[targetKw] || [];
            for (let i = 0; i < ids.length; i += CHUNK_SIZE) {
                const chunk = ids.slice(i, i + CHUNK_SIZE);
                $progress.text('<?php echo esc_js(__('Processing', 'cleversay')); ?> ' + (processed + 1) + '–' + (processed + chunk.length) + '…');
                try {
                    const resp = await $.post(ajaxurl, {
                        action: 'cleversay_recompile_dryrun_chunk',
                        nonce:  ajaxNonce,
                        ids:    chunk.join(','),
                    });
                    if (resp && resp.success && resp.data && resp.data.results) {
                        resp.data.results.forEach(appendRow);
                    }
                } catch (e) {
                    // Non-fatal — continue with the next chunk.
                }
                processed += chunk.length;
            }
        }

        $progress.text('<?php echo esc_js(__('Done — ', 'cleversay')); ?>' + processed + ' <?php echo esc_js(__('entries scanned.', 'cleversay')); ?>');
        $runBtn.prop('disabled', false);
        if (counts.changed === 0) {
            $resultsEmpty.show();
        } else {
            $applyBar.show();
            updateApplyButton();
        }
    }

    // Wire up
    $runBtn.on('click', runDryRun);

    // Select-all + per-row checkboxes
    $('#cs-select-all').on('change', function() {
        $('.cs-row-pick').prop('checked', this.checked);
        updateApplyButton();
    });
    $('#cs-toggle-all').on('click', function() {
        const allChecked = $('.cs-row-pick:not(:checked)').length === 0;
        $('.cs-row-pick, #cs-select-all').prop('checked', !allChecked);
        updateApplyButton();
    });
    $(document).on('change', '.cs-row-pick', updateApplyButton);

    // Apply
    $('#cs-apply-btn').on('click', async function() {
        const ids = $('.cs-row-pick:checked').map(function() {
            return $(this).closest('tr').data('id');
        }).get();
        if (ids.length === 0) return;

        if (!confirm('<?php echo esc_js(__('Apply pattern updates to', 'cleversay')); ?> ' + ids.length + ' <?php echo esc_js(__('entries? Patterns are re-computed fresh on apply.', 'cleversay')); ?>')) {
            return;
        }

        $(this).prop('disabled', true);
        $('#cs-apply-status').text('<?php echo esc_js(__('Applying…', 'cleversay')); ?>');

        // Chunk apply too — same chunk size
        let totalApplied = 0;
        let totalSkipped = 0;
        const errors = [];
        for (let i = 0; i < ids.length; i += CHUNK_SIZE) {
            const chunk = ids.slice(i, i + CHUNK_SIZE);
            try {
                const resp = await $.post(ajaxurl, {
                    action: 'cleversay_recompile_apply',
                    nonce:  ajaxNonce,
                    ids:    chunk.join(','),
                });
                if (resp && resp.success && resp.data) {
                    totalApplied += (resp.data.applied || 0);
                    totalSkipped += (resp.data.skipped_same || 0);
                    if (resp.data.errors) errors.push(...resp.data.errors);
                    // Mark rows as applied
                    chunk.forEach(function(id) {
                        const errForId = (resp.data.errors || []).find(e => e.id === id);
                        const $row = $('tr[data-id="' + id + '"]');
                        if (errForId) {
                            $row.addClass('error');
                            $row.find('td:last').append(
                                '<br><small style="color:#d63638;">' + escHtml(errForId.message) + '</small>'
                            );
                        } else {
                            $row.addClass('applied');
                        }
                    });
                }
            } catch (e) {
                errors.push({ id: 0, message: 'network error in chunk' });
            }
        }

        $('#cs-apply-status').html(
            '<?php echo esc_js(__('Applied', 'cleversay')); ?> <strong>' + totalApplied + '</strong>, ' +
            '<?php echo esc_js(__('skipped', 'cleversay')); ?> ' + totalSkipped +
            (errors.length > 0 ? ', <span style="color:#d63638;">' + errors.length + ' <?php echo esc_js(__('errors', 'cleversay')); ?></span>' : '')
        );
        $(this).prop('disabled', false);
    });
});
</script>
