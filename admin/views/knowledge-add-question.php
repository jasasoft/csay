<?php
/**
 * Knowledge Base — Add Question (question-first entry creation)
 *
 * The traditional flow is keyword-first: admin picks a keyword, then
 * adds entries under it. But the natural admin workflow is usually
 * question-first: "students keep asking X, where does it go?"
 *
 * This view lets admin paste a question, run the heuristic+LLM
 * suggester, and route to the appropriate creation flow:
 *   - Existing keyword → new-phrase-group with variation pre-filled
 *   - New keyword → new-keyword with both the keyword and the
 *     default match phrase pre-filled
 *
 * @package CleverSay
 * @since 4.37.39
 */

defined('ABSPATH') || exit;

global $wpdb;
$base_url = admin_url('admin.php?page=cleversay-knowledge');
?>

<div class="wrap cleversay-admin">
    <h1>
        <a href="<?php echo esc_url($base_url); ?>" class="back-link">
            <?php echo \CleverSay\Icons::render('arrow-left', 16); ?>
        </a>
        <?php echo \CleverSay\Icons::render('help-circle', 26); ?>
        <?php esc_html_e('Add Question', 'cleversay'); ?>
    </h1>

    <hr class="wp-header-end">

    <p style="font-size:14px; color:#444; max-width:760px; margin-top:14px;">
        <?php esc_html_e(
            'Paste a question students might ask. We\'ll suggest the best place for it — either an existing keyword bucket or a new one — and take you to the right form with the question pre-filled.',
            'cleversay'
        ); ?>
    </p>

    <div style="max-width:760px; margin-top:20px; padding:18px; background:#f6f7f7; border:1px solid #ddd; border-radius:4px;">
        <label for="cs-aq-input" style="display:block; font-weight:600; margin-bottom:8px; font-size:14px;">
            <?php esc_html_e('Question', 'cleversay'); ?>
        </label>
        <input type="text"
               id="cs-aq-input"
               class="regular-text"
               style="width:100%; font-size:14px;"
               placeholder="<?php esc_attr_e('e.g. Can I repeat a course I took at another school?', 'cleversay'); ?>">

        <div style="margin-top:12px;">
            <button type="button" class="button button-primary" id="cs-aq-suggest-btn">
                <?php echo \CleverSay\Icons::render('compass', 14); ?>
                <?php esc_html_e('Suggest best location', 'cleversay'); ?>
            </button>
            <span id="cs-aq-status" style="margin-left:10px; color:#666; font-size:13px;"></span>
        </div>

        <div id="cs-aq-results" style="margin-top:18px;"></div>
    </div>
</div>

<script>
jQuery(function($) {
    const ajaxNonce = <?php echo wp_json_encode(wp_create_nonce('cleversay_nonce')); ?>;
    const baseUrl   = <?php echo wp_json_encode($base_url); ?>;

    function escHtml(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

    $('#cs-aq-suggest-btn').on('click', function() {
        const $btn      = $(this);
        const $status   = $('#cs-aq-status');
        const $results  = $('#cs-aq-results');
        const variation = $('#cs-aq-input').val();

        if (!variation || !variation.trim()) {
            $status.css('color', '#d63638').text('<?php echo esc_js(__('Type a question first.', 'cleversay')); ?>');
            $results.empty();
            return;
        }

        $btn.prop('disabled', true);
        $status.css('color', '#666').text('<?php echo esc_js(__('Analyzing…', 'cleversay')); ?>');
        $results.empty();

        $.post(ajaxurl, {
            action:    'cleversay_suggest_keyword',
            nonce:     ajaxNonce,
            variation: variation
        }).done(function(resp) {
            $btn.prop('disabled', false);

            if (!resp || !resp.success || !resp.data) {
                $status.css('color', '#d63638').text('<?php echo esc_js(__('Suggestion failed.', 'cleversay')); ?>');
                return;
            }

            const suggestions = resp.data.suggestions || [];
            const source      = resp.data.source || 'heuristic';

            if (suggestions.length === 0) {
                $status.css('color', '#666').text('<?php echo esc_js(__('Couldn\'t find good candidates. Try a longer or more specific question.', 'cleversay')); ?>');
                return;
            }

            // Source badge
            let sourceLabel = '';
            if (source === 'llm') {
                sourceLabel = '<span style="background:#e6f0fa; color:#1d4ed8; font-size:11px; padding:2px 7px; border-radius:3px; margin-left:8px; font-weight:500;">' +
                              '<?php echo esc_js(__('AI-refined', 'cleversay')); ?></span>';
            } else if (source === 'llm-cached') {
                sourceLabel = '<span style="background:#e6f0fa; color:#1d4ed8; font-size:11px; padding:2px 7px; border-radius:3px; margin-left:8px; font-weight:500;">' +
                              '<?php echo esc_js(__('AI-refined (cached)', 'cleversay')); ?></span>';
            } else {
                sourceLabel = '<span style="background:#f0f0f0; color:#666; font-size:11px; padding:2px 7px; border-radius:3px; margin-left:8px;">' +
                              '<?php echo esc_js(__('heuristic', 'cleversay')); ?></span>';
            }

            $status.css('color', '#000').html('<strong><?php echo esc_js(__('Suggestions:', 'cleversay')); ?></strong>' + sourceLabel);

            let html = '<div style="display:flex; flex-direction:column; gap:8px; margin-top:6px;">';
            suggestions.forEach(function(s, i) {
                const isTop      = i === 0;
                const isExisting = s.kb_count > 0;

                html += '<div style="padding:12px; background:white; border:1px solid #ddd; border-radius:4px;' +
                        (isTop ? ' border-left:4px solid #00a32a;' : '') + '">';

                // Header row: keyword + badges
                html += '<div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">';
                html += '<strong style="font-size:15px;">' + escHtml(s.keyword) + '</strong>';
                if (isTop) {
                    html += '<span style="color:#00a32a; font-size:11px; font-weight:500;">' +
                            '<?php echo esc_js(__('★ best fit', 'cleversay')); ?></span>';
                }
                if (s.llm) {
                    html += '<span style="color:#1d4ed8; font-size:10px;">' +
                            '<?php echo esc_js(__('AI pick', 'cleversay')); ?></span>';
                }
                if (isExisting) {
                    html += '<span style="background:#dff6dd; color:#155724; font-size:11px; padding:2px 8px; border-radius:3px; font-weight:500;">' +
                            '<?php echo esc_js(__('existing bucket', 'cleversay')); ?>' +
                            ' · ' + s.kb_count + ' ' +
                            (s.kb_count === 1 ? '<?php echo esc_js(__('entry', 'cleversay')); ?>' : '<?php echo esc_js(__('entries', 'cleversay')); ?>') +
                            '</span>';
                } else {
                    html += '<span style="background:#fff3cd; color:#856404; font-size:11px; padding:2px 8px; border-radius:3px; font-weight:500;">' +
                            '<?php echo esc_js(__('new bucket', 'cleversay')); ?></span>';
                }
                html += '</div>';

                // Reasoning
                html += '<div style="color:#555; font-size:13px; margin-bottom:10px;">' + escHtml(s.reasoning) + '</div>';

                // Action button — routes differently based on whether bucket exists
                if (isExisting) {
                    const url = baseUrl +
                                '&action=new-phrase-group' +
                                '&keyword=' + encodeURIComponent(s.keyword) +
                                '&prefill_variation=' + encodeURIComponent(variation);
                    html += '<a href="' + url + '" class="button button-primary">' +
                            '<?php echo esc_js(__('Add this question to', 'cleversay')); ?> ' +
                            '<strong>' + escHtml(s.keyword) + '</strong>' +
                            ' →</a>';
                } else {
                    const url = baseUrl +
                                '&action=new-keyword' +
                                '&prefill_keyword=' + encodeURIComponent(s.keyword) +
                                '&prefill_phrase=' + encodeURIComponent(variation);
                    html += '<a href="' + url + '" class="button">' +
                            '<?php echo esc_js(__('Create new bucket', 'cleversay')); ?> ' +
                            '<strong>' + escHtml(s.keyword) + '</strong>' +
                            ' →</a>';
                }

                html += '</div>';
            });
            html += '</div>';

            $results.html(html);
        }).fail(function() {
            $btn.prop('disabled', false);
            $status.css('color', '#d63638').text('<?php echo esc_js(__('Network error.', 'cleversay')); ?>');
        });
    });

    // Enter key triggers suggest
    $('#cs-aq-input').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#cs-aq-suggest-btn').click();
        }
    });
});
</script>
