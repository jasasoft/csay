<?php
/**
 * Reuse Repair Admin View
 *
 * Detects entries with broken Reuse Response references and
 * offers per-row repair. Two cases:
 *
 * 1. Auto-repairable: exactly one current entry under the
 *    target keyword has a strong token-overlap with the
 *    orphaned pattern (same content stems, just rewritten).
 *    Most common case — recompile changed `class*` to
 *    `class*&credit*` and the orphan is just `class*`. The
 *    candidate is the same target, post-recompile.
 *
 * 2. Ambiguous: multiple candidates (or none with strong
 *    overlap). Admin picks from a dropdown of all entries
 *    under the target keyword.
 *
 * The page does its own scan on load (no cache) and renders
 * a single page of orphans. Each row has either an "Auto-fix"
 * button (high confidence) or a manual dropdown.
 *
 * @package CleverSay
 * @since 4.37.55
 */

if (!defined('ABSPATH')) exit;

global $wpdb;
$kb_table = $wpdb->prefix . 'cleversay_knowledge';

// Detect orphans. An orphan is a row where reuse_response is
// set, reuse_keyword + reuse_sub_keyword are non-empty, and no
// active entry under that keyword/pattern combination exists.
$orphans = $wpdb->get_results(
    "SELECT
        r.id,
        r.keyword AS source_keyword,
        r.sub_keyword AS source_pattern,
        r.question AS source_question,
        r.reuse_keyword,
        r.reuse_sub_keyword
     FROM {$kb_table} r
     WHERE r.reuse_response = 1
       AND r.reuse_keyword IS NOT NULL
       AND r.reuse_keyword != ''
       AND r.reuse_sub_keyword IS NOT NULL
       AND r.reuse_sub_keyword != ''
       AND r.status = 'active'
       AND NOT EXISTS (
         SELECT 1 FROM {$kb_table} t
         WHERE t.keyword = r.reuse_keyword
           AND t.sub_keyword = r.reuse_sub_keyword
           AND t.status = 'active'
       )
     ORDER BY r.reuse_keyword ASC, r.id ASC",
    ARRAY_A
);

// For each orphan, find candidates under the target keyword and
// score by token overlap with the orphaned pattern. Strong
// candidate (single match with ≥80% token overlap) is auto-
// repairable; otherwise admin picks manually.
$repair_rows = [];
$candidate_cache = []; // keyword => [candidates...]

foreach ($orphans as $o) {
    $target_keyword = (string) $o['reuse_keyword'];
    if (!isset($candidate_cache[$target_keyword])) {
        $candidate_cache[$target_keyword] = $wpdb->get_results($wpdb->prepare(
            "SELECT id, sub_keyword, question
             FROM {$kb_table}
             WHERE keyword = %s
               AND status = 'active'
               AND sub_keyword IS NOT NULL
               AND sub_keyword != ''
             ORDER BY id ASC",
            $target_keyword
        ), ARRAY_A);
    }
    $candidates = $candidate_cache[$target_keyword] ?: [];

    // Score by token-set overlap.
    $orphan_tokens = \CleverSay\Admin::pattern_to_token_set((string) $o['reuse_sub_keyword']);
    $orphan_count = count($orphan_tokens);

    $scored = [];
    foreach ($candidates as $c) {
        $cand_tokens = \CleverSay\Admin::pattern_to_token_set((string) $c['sub_keyword']);
        $overlap = array_intersect($orphan_tokens, $cand_tokens);
        $overlap_count = count($overlap);
        // Use Jaccard (intersection / union) for symmetric scoring.
        $union = count(array_unique(array_merge($orphan_tokens, $cand_tokens)));
        $score = $union > 0 ? round($overlap_count / $union, 2) : 0.0;
        $scored[] = [
            'id'          => (int) $c['id'],
            'sub_keyword' => (string) $c['sub_keyword'],
            'question'    => (string) $c['question'],
            'score'       => $score,
            'overlap'     => $overlap_count,
        ];
    }
    usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);

    // High-confidence auto-fix conditions:
    //   - top score >= 0.6 (more than half tokens shared)
    //   - top score is at least 0.2 higher than runner-up (clear winner)
    //   - orphan had at least 1 token (otherwise everything overlaps trivially)
    $auto_repair = null;
    if (!empty($scored) && $orphan_count >= 1) {
        $top = $scored[0];
        $second = isset($scored[1]) ? $scored[1]['score'] : 0.0;
        if ($top['score'] >= 0.6 && ($top['score'] - $second) >= 0.2) {
            $auto_repair = $top;
        }
    }

    $repair_rows[] = [
        'orphan'       => $o,
        'candidates'   => $scored,
        'auto_repair'  => $auto_repair,
    ];
}

$nonce = wp_create_nonce('cleversay_nonce');
$kb_url = admin_url('admin.php?page=cleversay-knowledge');
?>

<div class="wrap cleversay-admin">
    <h1>
        <?php echo \CleverSay\Icons::render('link', 22); ?>
        <?php esc_html_e('Reuse Repair', 'cleversay'); ?>
    </h1>

    <p style="font-size:13px; color:#555; max-width:900px; margin-top:8px;">
        <?php esc_html_e('Finds entries whose Reuse Response link points to a phrase pattern that no longer exists. Pre-v4.37.54, when an admin recompiled or edited a target entry\'s pattern, the references didn\'t auto-update — leaving silent broken links. v4.37.54 added cascade updates going forward; this page cleans up the legacy damage.', 'cleversay'); ?>
    </p>

    <hr class="wp-header-end">

    <?php if (empty($repair_rows)): ?>
        <div style="padding:18px; margin-top:16px; background:#dff6dd; border:1px solid #00a32a; border-radius:4px;">
            <strong style="font-size:14px;">
                <?php echo \CleverSay\Icons::render('check', 16); ?>
                <?php esc_html_e('No broken Reuse Response links found.', 'cleversay'); ?>
            </strong>
            <p style="margin:6px 0 0; font-size:13px; color:#444;">
                <?php esc_html_e('Every Reuse Response reference in your KB resolves to an active target.', 'cleversay'); ?>
            </p>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <!-- Summary -->
    <?php
    $auto_count = count(array_filter($repair_rows, static fn($r) => $r['auto_repair'] !== null));
    $manual_count = count($repair_rows) - $auto_count;
    ?>
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px; margin:16px 0;">
        <div style="padding:14px 18px; background:white; border:1px solid #ddd; border-radius:4px;">
            <div style="font-size:11px; color:#666; text-transform:uppercase; letter-spacing:.04em;">
                <?php esc_html_e('Total broken links', 'cleversay'); ?>
            </div>
            <div style="font-size:24px; font-weight:600;"><?php echo count($repair_rows); ?></div>
        </div>
        <div style="padding:14px 18px; background:white; border:1px solid #ddd; border-radius:4px; border-left:4px solid #00a32a;">
            <div style="font-size:11px; color:#666; text-transform:uppercase; letter-spacing:.04em;">
                <?php esc_html_e('Auto-fixable', 'cleversay'); ?>
            </div>
            <div style="font-size:24px; font-weight:600;"><?php echo $auto_count; ?></div>
            <div style="font-size:11px; color:#888;">
                <?php esc_html_e('clear winner detected', 'cleversay'); ?>
            </div>
        </div>
        <div style="padding:14px 18px; background:white; border:1px solid #ddd; border-radius:4px; border-left:4px solid #ffc107;">
            <div style="font-size:11px; color:#666; text-transform:uppercase; letter-spacing:.04em;">
                <?php esc_html_e('Need manual pick', 'cleversay'); ?>
            </div>
            <div style="font-size:24px; font-weight:600;"><?php echo $manual_count; ?></div>
            <div style="font-size:11px; color:#888;">
                <?php esc_html_e('ambiguous or no candidates', 'cleversay'); ?>
            </div>
        </div>
        <?php if ($auto_count > 0): ?>
        <div style="padding:14px 18px; background:#dff6dd; border:1px solid #00a32a; border-radius:4px; display:flex; align-items:center;">
            <button type="button" class="button button-primary" id="cs-fix-all-auto">
                <?php esc_html_e('Auto-fix all', 'cleversay'); ?>
                (<?php echo $auto_count; ?>)
            </button>
        </div>
        <?php endif; ?>
    </div>

    <table class="widefat striped" id="cs-repair-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Source entry', 'cleversay'); ?></th>
                <th><?php esc_html_e('Broken link', 'cleversay'); ?></th>
                <th style="width:280px;"><?php esc_html_e('Suggested target', 'cleversay'); ?></th>
                <th style="width:120px;"><?php esc_html_e('Action', 'cleversay'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($repair_rows as $row):
                $o = $row['orphan'];
                $auto = $row['auto_repair'];
                $candidates = $row['candidates'];
                $confidence_class = $auto !== null ? 'auto' : 'manual';
            ?>
            <tr data-entry-id="<?php echo (int) $o['id']; ?>" class="cs-repair-row cs-repair-<?php echo esc_attr($confidence_class); ?>">
                <td>
                    <strong><?php echo esc_html($o['source_keyword']); ?></strong>
                    <code style="display:inline-block; font-size:11px; margin-left:4px;"><?php echo esc_html($o['source_pattern']); ?></code><br>
                    <span style="color:#666; font-size:12px;"><?php echo esc_html(wp_trim_words((string) $o['source_question'], 12)); ?></span><br>
                    <a href="<?php echo esc_url(add_query_arg([
                        'action'  => 'edit-keyword',
                        'keyword' => $o['source_keyword'],
                    ], $kb_url)); ?>" style="font-size:11px;" target="_blank">
                        <?php esc_html_e('open editor →', 'cleversay'); ?>
                    </a>
                </td>
                <td>
                    <strong><?php echo esc_html($o['reuse_keyword']); ?></strong> /
                    <code style="font-size:11px; color:#d63638;"><?php echo esc_html($o['reuse_sub_keyword']); ?></code>
                </td>
                <td>
                    <?php if ($auto !== null): ?>
                        <div style="font-size:13px;">
                            <code style="font-size:11px; color:#00a32a;"><?php echo esc_html($auto['sub_keyword']); ?></code>
                            <span style="color:#888; font-size:11px;">
                                (<?php echo (int) ($auto['score'] * 100); ?>% match)
                            </span>
                        </div>
                        <div style="font-size:12px; color:#666; margin-top:2px;">
                            <?php echo esc_html(wp_trim_words((string) $auto['question'], 12)); ?>
                        </div>
                    <?php elseif (!empty($candidates)): ?>
                        <select class="cs-manual-pick" style="width:100%;">
                            <option value=""><?php esc_html_e('— choose target —', 'cleversay'); ?></option>
                            <?php foreach ($candidates as $c): ?>
                                <option value="<?php echo esc_attr($c['sub_keyword']); ?>">
                                    <?php
                                    $label = wp_trim_words($c['question'], 8);
                                    $pct = (int) ($c['score'] * 100);
                                    echo esc_html("[{$pct}%] {$c['sub_keyword']} — {$label}");
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <em style="color:#d63638; font-size:12px;">
                            <?php esc_html_e('No active entries under this keyword. Disable Reuse Response on the source entry instead.', 'cleversay'); ?>
                        </em>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($auto !== null): ?>
                        <button type="button" class="button button-primary cs-fix-btn"
                                data-mode="auto"
                                data-target="<?php echo esc_attr($auto['sub_keyword']); ?>">
                            <?php esc_html_e('Auto-fix', 'cleversay'); ?>
                        </button>
                    <?php elseif (!empty($candidates)): ?>
                        <button type="button" class="button cs-fix-btn"
                                data-mode="manual" disabled>
                            <?php esc_html_e('Apply', 'cleversay'); ?>
                        </button>
                    <?php endif; ?>
                    <span class="cs-row-status" style="display:block; margin-top:4px; font-size:11px;"></span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
jQuery(function($) {
    const ajaxNonce = <?php echo wp_json_encode($nonce); ?>;

    function escHtml(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

    // Enable Apply button when manual select changes
    $(document).on('change', '.cs-manual-pick', function() {
        const $row = $(this).closest('tr');
        const $btn = $row.find('.cs-fix-btn[data-mode="manual"]');
        $btn.prop('disabled', !$(this).val());
        $btn.data('target', $(this).val());
    });

    // Apply single fix
    function applyRowFix($row, target) {
        const entryId = $row.data('entry-id');
        const $status = $row.find('.cs-row-status');
        const $btn = $row.find('.cs-fix-btn');

        $btn.prop('disabled', true);
        $status.css('color', '#666').text('<?php echo esc_js(__('Saving…', 'cleversay')); ?>');

        return $.post(ajaxurl, {
            action:          'cleversay_reuse_repair_apply',
            nonce:           ajaxNonce,
            entry_id:        entryId,
            new_sub_keyword: target,
        }).done(function(resp) {
            if (resp && resp.success) {
                $row.css('background', '#dff6dd');
                $status.css('color', '#00a32a').text('✓ <?php echo esc_js(__('Reconnected', 'cleversay')); ?>');
                $btn.remove();
                $row.find('.cs-manual-pick').prop('disabled', true);
            } else {
                const msg = (resp && resp.data && resp.data.message) ? resp.data.message :
                            '<?php echo esc_js(__('Failed', 'cleversay')); ?>';
                $btn.prop('disabled', false);
                $status.css('color', '#d63638').text(msg);
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            $status.css('color', '#d63638').text('<?php echo esc_js(__('Network error', 'cleversay')); ?>');
        });
    }

    // Single-row Apply
    $(document).on('click', '.cs-fix-btn', function() {
        const $row = $(this).closest('tr');
        const target = $(this).data('target');
        if (!target) return;
        applyRowFix($row, target);
    });

    // Fix all auto-repairable in sequence
    $('#cs-fix-all-auto').on('click', async function() {
        const $btn = $(this);
        const $rows = $('.cs-repair-row.cs-repair-auto');
        if ($rows.length === 0) return;
        if (!confirm('<?php echo esc_js(__('Auto-fix', 'cleversay')); ?> ' + $rows.length + ' <?php echo esc_js(__('reuse links?', 'cleversay')); ?>')) return;

        $btn.prop('disabled', true).text('<?php echo esc_js(__('Fixing…', 'cleversay')); ?>');
        let success = 0, failed = 0;
        for (const el of $rows.toArray()) {
            const $row = $(el);
            const target = $row.find('.cs-fix-btn[data-mode="auto"]').data('target');
            if (!target) continue;
            try {
                await applyRowFix($row, target);
                success++;
            } catch (e) {
                failed++;
            }
        }
        $btn.prop('disabled', false).text(
            '<?php echo esc_js(__('Done', 'cleversay')); ?> — ' +
            success + ' <?php echo esc_js(__('fixed', 'cleversay')); ?>' +
            (failed > 0 ? ', ' + failed + ' <?php echo esc_js(__('failed', 'cleversay')); ?>' : '')
        );
    });
});
</script>
