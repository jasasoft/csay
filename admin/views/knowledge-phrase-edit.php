<?php
/**
 * Knowledge Base - Phrase Group Edit View
 * 
 * Edit patterns and response for a single phrase group
 *
 * @package CleverSay
 * @since 2.0.40
 */

defined('ABSPATH') || exit;

global $wpdb;

$keyword = sanitize_text_field($_GET['keyword'] ?? '');
$group_id = absint($_GET['group_id'] ?? 0);
$is_new = (isset($_GET['action']) && $_GET['action'] === 'new-phrase-group');

if (empty($keyword)) {
    wp_die(__('Keyword not specified', 'cleversay'));
}

// Verify keyword exists
$keyword_exists = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}cleversay_knowledge WHERE keyword = %s",
    $keyword
));

if (!$keyword_exists && !$is_new) {
    wp_die(__('Keyword not found', 'cleversay'));
}

$group_patterns = [];
$group_response = '';
$group_status = 'active';
$group_expires = '';
$group_show_rating = 1;
$is_default = false;
$reuse_response = 0;
$reuse_keyword = '';
$reuse_sub_keyword = '';

if (!$is_new && $group_id) {
    // Load one row by id. Each row is its own phrase group as of
    // v4.34.0 — we no longer aggregate rows that happen to share
    // a response.
    $first_entry = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}cleversay_knowledge WHERE id = %d",
        $group_id
    ), ARRAY_A);

    if (!$first_entry) {
        wp_die(__('Phrase group not found', 'cleversay'));
    }

    $is_default = (strtolower(trim($first_entry['sub_keyword'] ?? '')) === 'aadefault' || empty($first_entry['sub_keyword']));

    $group_patterns[] = [
        'id' => $first_entry['id'],
        'pattern' => $first_entry['sub_keyword'] ?: 'aadefault',
        'phrase' => $first_entry['question'],
    ];

    $group_response = $first_entry['response'];
    $group_status = $first_entry['status'];
    $group_expires = $first_entry['expires_at'] ? date('Y-m-d', strtotime($first_entry['expires_at'])) : '';
    $group_show_rating = $first_entry['show_rating'];

    // Reuse response settings
    $reuse_response = (int)($first_entry['reuse_response'] ?? 0);
    $reuse_keyword = $first_entry['reuse_keyword'] ?? '';
    $reuse_sub_keyword = $first_entry['reuse_sub_keyword'] ?? '';
}

// If this is a reuse entry, fetch the actual response content for preview
$reuse_preview_content = '';
if ($reuse_response && $reuse_keyword && $reuse_sub_keyword) {
    $reused_entry = $wpdb->get_row($wpdb->prepare(
        "SELECT response FROM {$wpdb->prefix}cleversay_knowledge 
         WHERE keyword = %s AND (sub_keyword = %s OR (sub_keyword IS NULL AND %s = 'aadefault'))
         AND status = 'active'
         LIMIT 1",
        $reuse_keyword,
        $reuse_sub_keyword,
        $reuse_sub_keyword
    ), ARRAY_A);
    
    if ($reused_entry) {
        $reuse_preview_content = $reused_entry['response'];
    }
}

// Get all keywords for reuse dropdown (only those with active, non-reusing entries)
$all_keywords = $wpdb->get_col(
    "SELECT DISTINCT keyword FROM {$wpdb->prefix}cleversay_knowledge 
     WHERE status = 'active' AND (reuse_response = 0 OR reuse_response IS NULL)
     ORDER BY keyword ASC"
);

// Get all entries grouped by keyword for JavaScript
$all_entries_raw = $wpdb->get_results(
    "SELECT keyword, sub_keyword, question FROM {$wpdb->prefix}cleversay_knowledge 
     WHERE status = 'active' AND (reuse_response = 0 OR reuse_response IS NULL)
     ORDER BY keyword, question",
    ARRAY_A
);

// Group entries by keyword for the JavaScript dropdown population
$entries_by_keyword = [];
foreach ($all_entries_raw as $entry) {
    $kw = $entry['keyword'];
    if (!isset($entries_by_keyword[$kw])) {
        $entries_by_keyword[$kw] = [];
    }
    $entries_by_keyword[$kw][] = [
        'sub_keyword' => $entry['sub_keyword'] ?: 'aadefault',
        'question' => $entry['question']
    ];
}

// Get categories

// Load any existing question variations for this row (v4.34.0+).
// Under the per-row model, the canonical id IS the row's id —
// `$entries` always contains exactly one element. Earlier versions
// resolved canonical via `min(id)` across rows sharing keyword +
// response; that aggregation no longer happens.
$existing_variations = [];
$canonical_group_id = (int) $group_id;
if ($canonical_group_id > 0 && class_exists('\\CleverSay\\KBVariations')) {
    $existing_variations = \CleverSay\KBVariations::get_texts_for_entry($canonical_group_id);
}

// v4.36.0+: Seed variations from the canonical question for any
// non-aadefault, non-new entry that has no variations attached. After
// the v4.34.0 migration this should always be populated, but legacy
// entries imported by other code paths (or entries created before
// 4.34.0 if any survived) might still have empty variations. Seeding
// from the stored question lets the variations editor work for them
// — admin can refine, save, and the entry joins the new flow without
// the legacy multi-pattern editor.
if (!$is_new
    && !empty($first_entry)
    && !$is_default
    && empty($existing_variations)
    && !empty(trim((string) ($first_entry['question'] ?? '')))
) {
    $existing_variations = [trim((string) $first_entry['question'])];
}

// v4.37.39+: when arriving from the Add Question page, the URL
// carries `prefill_variation` — the question the admin pasted.
// Pre-fill it as the first variation so they only need to write
// the response and save. Only applies for new entries (existing
// entries already have stored variations to load).
if ($is_new && !empty($_GET['prefill_variation'])) {
    $prefill = trim(sanitize_textarea_field(wp_unslash((string) $_GET['prefill_variation'])));
    if ($prefill !== '') {
        $existing_variations = [$prefill];
    }
}

// v4.37.24+: when validation failed on the previous POST, the save
// handler stashes the submitted form data in a transient so we can
// pre-fill the form here. Without this, the redirect-back loads the
// stored row from the database — losing any variations or response
// edits the admin had just typed. The transient is consumed once
// (deleted after read) so a refresh of this page after the
// validation error doesn't repeatedly re-apply stale repost data.
$repost = get_transient('cleversay_form_repost');
if (is_array($repost) && !$is_new
    && (int) ($repost['group_id'] ?? 0) === $canonical_group_id
) {
    delete_transient('cleversay_form_repost');

    if (isset($repost['response']))    $group_response    = (string) $repost['response'];
    if (isset($repost['status']))      $group_status      = (string) $repost['status'];
    if (isset($repost['expires_at']))  $group_expires     = (string) $repost['expires_at'];
    if (isset($repost['show_rating'])) $group_show_rating = (int) $repost['show_rating'];

    if (!empty($repost['variations']) && is_array($repost['variations'])) {
        $repost_variations = array_values(array_filter(
            array_map(fn($v) => trim((string) $v), $repost['variations']),
            fn($v) => $v !== ''
        ));
        if (!empty($repost_variations)) {
            $existing_variations = $repost_variations;
        }
    }
} elseif (is_array($repost) && $is_new) {
    // New-entry path — there's no group_id to match on; just consume
    // and apply if the keyword matches.
    if ((string) ($repost['keyword'] ?? '') === $keyword) {
        delete_transient('cleversay_form_repost');
        if (isset($repost['response']))    $group_response    = (string) $repost['response'];
        if (isset($repost['status']))      $group_status      = (string) $repost['status'];
        if (isset($repost['expires_at']))  $group_expires     = (string) $repost['expires_at'];
        if (isset($repost['show_rating'])) $group_show_rating = (int) $repost['show_rating'];
        if (!empty($repost['variations']) && is_array($repost['variations'])) {
            $repost_variations = array_values(array_filter(
                array_map(fn($v) => trim((string) $v), $repost['variations']),
                fn($v) => $v !== ''
            ));
            if (!empty($repost_variations)) {
                $existing_variations = $repost_variations;
            }
        }
    }
}

$base_url = admin_url('admin.php?page=cleversay-knowledge');
$detail_url = add_query_arg(['action' => 'keyword-detail', 'keyword' => urlencode($keyword)], $base_url);
?>

<div class="wrap cleversay-admin cleversay-phrase-edit">
    <h1>
        <a href="<?php echo esc_url($detail_url); ?>" class="back-link" title="<?php esc_attr_e('Back to Keyword', 'cleversay'); ?>">
            <?php echo \CleverSay\Icons::render('arrow-left', 16); ?>
        </a>
        <?php echo \CleverSay\Icons::render('message-square', 26); ?>
        <?php if ($is_new): ?>
            <?php esc_html_e('New Phrase Group', 'cleversay'); ?>
        <?php else: ?>
            <?php esc_html_e('Edit Phrase Group', 'cleversay'); ?>
        <?php endif; ?>
        <span class="keyword-badge"><?php echo esc_html($keyword); ?></span>
    </h1>
    
    <hr class="wp-header-end">

    <?php 
    // Show validation errors if any
    $validation_errors = get_transient('cleversay_validation_errors');
    if ($validation_errors): 
        delete_transient('cleversay_validation_errors');
    ?>
        <div class="notice notice-error">
            <p><strong><?php esc_html_e('Validation Failed:', 'cleversay'); ?></strong></p>
            <ul>
                <?php foreach ($validation_errors as $error): ?>
                    <li><?php echo esc_html($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php
    // v4.37.28+: round-trip ranking report.
    //
    // After save, the server tested each variation against the live
    // KB and bucketed them by ranking outcome. If any didn't rank
    // this entry as #1, we land here with a transient describing
    // each variation's result. The row is ALREADY saved; this banner
    // is informational + offers the admin a path forward:
    //
    //   - Save anyway: dismiss the banner, accept the current state.
    //     The row stays exactly as it was just saved.
    //   - Edit: scroll down to the form (which already shows the
    //     just-saved variations), make changes, save again.
    //
    // The transient is consumed on read so a manual refresh after
    // dismissal doesn't re-show the banner.
    $rt_report = get_transient('cleversay_roundtrip_report');
    if (is_array($rt_report)
        && (int) ($rt_report['entry_id'] ?? 0) === $canonical_group_id
    ):
        delete_transient('cleversay_roundtrip_report');

        $rt_results = is_array($rt_report['results'] ?? null) ? $rt_report['results'] : [];
        $top_count = 0;
        foreach ($rt_results as $r) {
            if (($r['bucket'] ?? '') === 'top') $top_count++;
        }
        $total = count($rt_results);

        $bucket_meta = [
            'top'     => ['label' => __('#1', 'cleversay'),               'class' => 'cs-rt-top'],
            'tied'    => ['label' => __('Tied at #1', 'cleversay'),       'class' => 'cs-rt-tied'],
            'listed'  => ['label' => __('Listed (not #1)', 'cleversay'),  'class' => 'cs-rt-listed'],
            'missing' => ['label' => __('Not in matches', 'cleversay'),   'class' => 'cs-rt-missing'],
        ];
    ?>
        <div class="notice notice-warning cs-roundtrip-banner" style="padding:14px 18px;">
            <p style="margin:0 0 8px 0; font-size:14px;">
                <strong><?php esc_html_e('Saved — but some variations don\'t rank this entry as the top result.', 'cleversay'); ?></strong>
            </p>
            <p style="margin:0 0 12px 0;">
                <?php
                printf(
                    /* translators: 1=count ranked top, 2=total */
                    esc_html__('%1$d of %2$d variations rank this entry as #1. The rest tie or lose to a sibling. Pattern-level validation passed (every variation matches the compiled rule), but at runtime another entry wins. Decide whether that\'s acceptable for your KB or revise.', 'cleversay'),
                    (int) $top_count,
                    (int) $total
                );
                ?>
            </p>

            <table class="widefat striped" style="margin-bottom:14px;">
                <thead>
                    <tr>
                        <th style="width:46%;"><?php esc_html_e('Variation', 'cleversay'); ?></th>
                        <th style="width:14%;"><?php esc_html_e('Outcome', 'cleversay'); ?></th>
                        <th><?php esc_html_e('What ranked first instead', 'cleversay'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rt_results as $r):
                        $bucket = $r['bucket'] ?? 'missing';
                        $meta   = $bucket_meta[$bucket] ?? $bucket_meta['missing'];
                        $top_entry = $r['top_entry'] ?? null;
                    ?>
                        <tr>
                            <td><?php echo esc_html($r['variation'] ?? ''); ?></td>
                            <td>
                                <span class="cs-rt-bucket <?php echo esc_attr($meta['class']); ?>">
                                    <?php echo esc_html($meta['label']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($bucket === 'top'): ?>
                                    <span style="color:#5d8c3e;"><?php esc_html_e('— this entry —', 'cleversay'); ?></span>
                                <?php elseif ($top_entry): ?>
                                    <strong><?php echo esc_html($top_entry['keyword'] ?? ''); ?></strong>
                                    <span style="color:#666;"> / <?php echo esc_html($top_entry['sub_keyword'] ?? 'aadefault'); ?></span>
                                    <br>
                                    <span style="color:#666; font-size:12px;">
                                        <?php echo esc_html(wp_trim_words((string) ($top_entry['question'] ?? ''), 14)); ?>
                                    </span>
                                    <?php if (!empty($top_entry['id'])): ?>
                                        <br>
                                        <a href="<?php echo esc_url(add_query_arg([
                                            'page'     => 'cleversay-knowledge',
                                            'action'   => 'edit-phrase-group',
                                            'keyword'  => urlencode($top_entry['keyword'] ?? ''),
                                            'group_id' => (int) $top_entry['id'],
                                        ], admin_url('admin.php'))); ?>" target="_blank" rel="noopener" style="font-size:12px;">
                                            <?php esc_html_e('open winner ↗', 'cleversay'); ?>
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#999;"><?php esc_html_e('(no match)', 'cleversay'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="display:flex; gap:10px; align-items:center;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                    <input type="hidden" name="action" value="cleversay_dismiss_roundtrip">
                    <input type="hidden" name="keyword" value="<?php echo esc_attr($keyword); ?>">
                    <input type="hidden" name="group_id" value="<?php echo esc_attr($canonical_group_id); ?>">
                    <?php wp_nonce_field('cleversay_dismiss_roundtrip', 'cleversay_nonce'); ?>
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Save anyway', 'cleversay'); ?>
                    </button>
                </form>
                <button type="button" class="button" onclick="document.getElementById('cs-variations-container').scrollIntoView({behavior:'smooth',block:'start'})">
                    <?php esc_html_e('Edit variations / pattern', 'cleversay'); ?>
                </button>
                <span style="color:#666; font-size:12px; margin-left:auto;">
                    <?php esc_html_e('Tip: try AI Suggest variations to add diverse phrasings, or click "Recompile rule" after editing.', 'cleversay'); ?>
                </span>
            </div>
        </div>
        <style>
            .cs-rt-bucket {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
                white-space: nowrap;
            }
            .cs-rt-top     { background: #d4edda; color: #155724; }
            .cs-rt-tied    { background: #fff3cd; color: #856404; }
            .cs-rt-listed  { background: #ffe5d9; color: #8a4a1f; }
            .cs-rt-missing { background: #f8d7da; color: #721c24; }
        </style>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="phrase-group-form">
        <input type="hidden" name="action" value="cleversay_save_phrase_group">
        <input type="hidden" name="keyword" value="<?php echo esc_attr($keyword); ?>">
        <input type="hidden" name="group_id" value="<?php echo esc_attr($group_id); ?>">
        <input type="hidden" name="is_new" value="<?php echo $is_new ? '1' : '0'; ?>">
        <?php wp_nonce_field('cleversay_save_phrase_group', 'cleversay_nonce'); ?>

        <!-- Question Variations Section (v4.31.0) -->
        <!-- This is the recommended way for non-technical admins to set up
             matching: list a few different ways students might ask the same
             question, and the pattern builds itself. v4.36.0+: aadefault
             entries get a different, simpler editor (see the block below). -->
        <?php if (!$is_default): ?>
        <div class="edit-section variations-section">
            <div class="section-header">
                <h2>
                    <?php echo \CleverSay\Icons::render('message-square', 16); ?>
                    <?php esc_html_e('Question Variations', 'cleversay'); ?>
                    <span class="cs-badge cs-badge-recommended"><?php esc_html_e('Recommended', 'cleversay'); ?></span>
                </h2>
            </div>

            <p class="description">
                <?php esc_html_e('List a few different ways students might ask this question. The matching rules will be built for you. One variation is required, three or more is recommended for best results.', 'cleversay'); ?>
            </p>

            <div id="cs-variations-container">
                <?php
                // Show existing variations on edit, or one empty row on new entry.
                $rows_to_render = !empty($existing_variations) ? $existing_variations : [''];
                foreach ($rows_to_render as $idx => $variation):
                ?>
                    <div class="cs-variation-row" data-index="<?php echo $idx; ?>">
                        <span class="cs-variation-num"><?php echo $idx + 1; ?>.</span>
                        <input type="text"
                               name="variations[]"
                               class="cs-variation-input regular-text"
                               value="<?php echo esc_attr($variation); ?>"
                               placeholder="<?php esc_attr_e('e.g. Am I eligible for the GI Bill?', 'cleversay'); ?>">
                        <button type="button" class="button-link cs-variation-delete" title="<?php esc_attr_e('Remove this variation', 'cleversay'); ?>">
                            <?php echo \CleverSay\Icons::render('trash', 16); ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="cs-variations-actions">
                <button type="button" class="button" id="cs-add-variation">
                    <?php echo \CleverSay\Icons::render('plus', 16); ?>
                    <?php esc_html_e('Add another variation', 'cleversay'); ?>
                </button>

                <button type="button" class="button" id="cs-suggest-variations" title="<?php esc_attr_e('Use AI to suggest variations from your response and topic', 'cleversay'); ?>">
                    <?php echo \CleverSay\Icons::render('shield', 16); ?>
                    <?php esc_html_e('AI Suggest variations', 'cleversay'); ?>
                </button>

                <span id="cs-variations-status" class="cs-status-msg"></span>
            </div>

            <!-- Matching rule preview. Pre-populated server-side
                 with the row's stored sub_keyword so editing an
                 unchanged entry doesn't surprise the admin with a
                 newly-compiled pattern that differs from what's
                 actually saved. The preview is recompiled live only
                 when the admin edits a variation. -->
            <?php
            $initial_stored_pattern = '';
            if (!empty($group_patterns) && !empty($group_patterns[0]['pattern'])) {
                $p = trim((string) $group_patterns[0]['pattern']);
                if ($p !== '' && strtolower($p) !== 'aadefault') {
                    $initial_stored_pattern = $p;
                }
            }

            // v4.37.40+: for new entries arriving from the Add Question
            // flow (or any path that pre-fills variations into a
            // never-saved entry), the preview would otherwise stay
            // hidden because there's no stored row yet AND the JS
            // compile-on-change handler never fires (no change event).
            // Server-compile the pattern eagerly so admin sees the
            // proposed rule as soon as the page loads.
            if ($initial_stored_pattern === ''
                && $is_new
                && !empty($existing_variations)
                && class_exists('\\CleverSay\\KBPatternCompiler')
            ) {
                try {
                    $sib = method_exists($this ?? null, 'fetch_sibling_variations')
                        ? null  // can't reach the admin instance from a view; use the helper below
                        : null;

                    // Fetch sibling variations directly (same logic as
                    // the AJAX compile endpoint uses, inlined here so
                    // we don't have to plumb through the Admin
                    // instance).
                    $sibling_variations = [];
                    if ($keyword !== '') {
                        global $wpdb;
                        $kb_table = $wpdb->prefix . 'cleversay_knowledge';
                        $sibling_ids = $wpdb->get_col($wpdb->prepare(
                            "SELECT id FROM {$kb_table}
                              WHERE keyword = %s
                                AND status = 'active'
                                AND sub_keyword != ''
                                AND sub_keyword != 'aadefault'",
                            $keyword
                        ));
                        if (!empty($sibling_ids) && class_exists('\\CleverSay\\KBVariations')) {
                            foreach ($sibling_ids as $sid) {
                                $vars = \CleverSay\KBVariations::get_texts_for_entry((int) $sid);
                                foreach ($vars as $v) {
                                    $v = trim((string) $v);
                                    if ($v !== '') $sibling_variations[] = $v;
                                }
                            }
                        }
                    }

                    $compiler = \CleverSay\KBPatternCompiler::from_database();
                    $compiled = $compiler->compile(
                        $existing_variations,
                        $keyword,
                        $sibling_variations
                    );
                    if ($compiled !== '' && strtolower($compiled) !== 'aadefault') {
                        $initial_stored_pattern = $compiled;
                    }
                } catch (\Throwable $e) {
                    // Compile failure is non-fatal — admin can click
                    // Recompile rule manually.
                }
            }

            $preview_visible = $initial_stored_pattern !== '';
            ?>
            <div class="cs-pattern-preview-box" id="cs-pattern-preview-box"
                 style="<?php echo $preview_visible ? '' : 'display:none;'; ?>">
                <div class="cs-pattern-preview-label">
                    <?php echo \CleverSay\Icons::render('check', 14); ?>
                    <?php esc_html_e('Matching rule:', 'cleversay'); ?>
                </div>
                <code id="cs-pattern-preview-text"><?php echo esc_html($initial_stored_pattern); ?></code>
                <p class="description" style="margin-top:6px;">
                    <?php esc_html_e('Built from your variations above. To change how this entry matches, edit the variations.', 'cleversay'); ?>
                </p>
                <p style="margin: 8px 0 0;">
                    <button type="button" class="button" id="cs-recompile-rule"
                            title="<?php esc_attr_e('Regenerate the matching rule from current variations using the latest stopwords, synonyms, and compiler logic. Useful after a plugin update or stopword/synonym change.', 'cleversay'); ?>">
                        <?php echo \CleverSay\Icons::render('refresh-cw', 14); ?>
                        <?php esc_html_e('Recompile rule', 'cleversay'); ?>
                    </button>
                    <span id="cs-recompile-status" class="cs-status-msg" style="margin-left:8px;"></span>
                </p>

                <!-- v4.37.51+: inline debug panel. Collapsed by default
                     so it doesn't add visual noise for the common case
                     of trusting the compiler. When opened, fetches the
                     compiler's per-token scoring trace and renders a
                     small breakdown table — what each candidate token
                     scored, why, and which made it into the pattern.
                     For free-form deep-dive debugging (paste any
                     question, see what the matcher does), use the
                     "Test in Ask Question" link below — Ask Question
                     already shows pipeline steps and scored matches. -->
                <details id="cs-pattern-trace-details" style="margin-top:10px;">
                    <summary style="cursor:pointer; font-size:12px; color:#2271b1; user-select:none;">
                        <?php esc_html_e('Why this pattern? (debug)', 'cleversay'); ?>
                    </summary>
                    <div id="cs-pattern-trace-body" style="margin-top:8px; padding:10px; background:white; border:1px solid #ddd; border-radius:3px; font-size:12px;">
                        <em style="color:#888;"><?php esc_html_e('Click to load token-level scoring…', 'cleversay'); ?></em>
                    </div>
                    <p style="margin:8px 0 0; font-size:11px; color:#666;">
                        <?php
                        $ask_url = admin_url('admin.php?page=cleversay-ask');
                        printf(
                            /* translators: %s opening anchor tag */
                            esc_html__('Want to test how the matcher handles an arbitrary question? %sOpen Ask Question →%s', 'cleversay'),
                            '<a href="' . esc_url($ask_url) . '" target="_blank" id="cs-test-in-ask-question">',
                            '</a>'
                        );
                        ?>
                    </p>
                </details>

                <input type="hidden" name="force_recompile" id="cs-force-recompile" value="">
                <input type="hidden" name="verified_pattern" id="cs-verified-pattern" value="">
            </div>
        </div>
        <?php endif; // !$is_default ?>

        <?php if ($is_default): ?>
        <!-- aadefault entries: simple editor, no pattern (the pattern
             is fixed at "aadefault"). The admin only edits the canonical
             question text and the response. v4.36.0+ replaced the
             multi-pattern Advanced section with this minimal block. -->
        <div class="edit-section variations-section">
            <div class="section-header">
                <h2>
                    <?php echo \CleverSay\Icons::render('star', 16); ?>
                    <?php esc_html_e('Default Response', 'cleversay'); ?>
                </h2>
            </div>
            <p class="description">
                <?php
                printf(
                    esc_html__('This is the default fallback response for the keyword %s. It runs when no other entry under this keyword matches the user\'s question. There is no matching rule to set — the canonical question below is shown to the user as the suggested phrasing.', 'cleversay'),
                    '<strong>' . esc_html($keyword) . '</strong>'
                );
                ?>
            </p>
            <div style="padding: 0 16px 16px;">
                <label for="cs-aadefault-question" style="display:block;font-weight:600;margin-bottom:6px;">
                    <?php esc_html_e('Canonical question', 'cleversay'); ?>
                </label>
                <input type="text" id="cs-aadefault-question" name="aadefault_question"
                       value="<?php echo esc_attr($group_patterns[0]['phrase'] ?? ''); ?>"
                       class="regular-text" style="width:100%;max-width:600px;"
                       placeholder="<?php esc_attr_e('e.g. How do I add a class?', 'cleversay'); ?>">
                <p class="description" style="margin-top:6px;">
                    <?php esc_html_e('Must contain the keyword.', 'cleversay'); ?>
                </p>
            </div>

            <!-- Hidden patterns[] payload for save handler compatibility.
                 The save handler still expects patterns[0][pattern] and
                 patterns[0][phrase] for aadefault rows. The phrase is
                 synced from #cs-aadefault-question on submit. -->
            <input type="hidden" name="patterns[0][id]"      value="<?php echo esc_attr($group_patterns[0]['id'] ?? ''); ?>">
            <input type="hidden" name="patterns[0][pattern]" value="aadefault">
            <input type="hidden" name="patterns[0][phrase]"  id="cs-aadefault-phrase-mirror" value="<?php echo esc_attr($group_patterns[0]['phrase'] ?? ''); ?>">
        </div>
        <?php endif; ?>


        <!-- Response Section -->
        <div class="edit-section response-section">
            <div class="section-header">
                <h2>
                    <?php echo \CleverSay\Icons::render('message-square', 16); ?>
                    <?php esc_html_e('Response', 'cleversay'); ?>

                    <?php
                    // v4.37.58+: polished badge. Surfaces the entry's
                    // current polish state visibly so admins can verify
                    // that their workflow (Polish → Apply → Save) left
                    // the marker intact, and so they can spot stale
                    // markers (response edited without re-polishing).
                    //
                    // States:
                    //  - clean+marker: hash present AND matches current
                    //    response → "✨ Polished" green badge.
                    //    Runtime Polish KB will skip this entry.
                    //  - stale marker: hash present but doesn't match
                    //    (admin edited without re-polishing) → not
                    //    shown; we treat this same as "not polished"
                    //    because the runtime check uses the same
                    //    comparison and won't skip.
                    //  - no marker: hash is null → no badge.
                    //
                    // Only shown for existing entries with a non-reuse
                    // response (reuse entries don't have their own
                    // response to polish — they pull from the target).
                    $polished_marker_active = false;
                    if (!$is_new && !empty($first_entry) && empty($first_entry['reuse_response'])) {
                        $stored_hash = (string) ($first_entry['polished_hash'] ?? '');
                        if ($stored_hash !== '' && class_exists('\\CleverSay\\Admin')) {
                            $current_hash = \CleverSay\Admin::compute_response_hash(
                                (string) ($first_entry['response'] ?? '')
                            );
                            $polished_marker_active = ($stored_hash === $current_hash);
                        }
                    }
                    ?>
                    <?php if ($polished_marker_active): ?>
                        <span id="cs-polished-badge"
                              style="display:inline-block; margin-left:10px; padding:2px 8px; background:#dff6dd; color:#0a6b0a; border:1px solid #a3d9a5; border-radius:11px; font-size:11px; font-weight:600; vertical-align:middle;"
                              title="<?php esc_attr_e('This response was AI-polished and has not been edited since. Runtime Polish KB will skip this entry, saving cost and latency on every query.', 'cleversay'); ?>">
                            ✨ <?php esc_html_e('Polished', 'cleversay'); ?>
                        </span>
                    <?php else: ?>
                        <!-- Hidden placeholder so JS can swap visibility on
                             apply/edit without needing to inject markup. -->
                        <span id="cs-polished-badge"
                              style="display:none; margin-left:10px; padding:2px 8px; background:#dff6dd; color:#0a6b0a; border:1px solid #a3d9a5; border-radius:11px; font-size:11px; font-weight:600; vertical-align:middle;"
                              title="<?php esc_attr_e('This response was AI-polished and has not been edited since. Runtime Polish KB will skip this entry, saving cost and latency on every query.', 'cleversay'); ?>">
                            ✨ <?php esc_html_e('Polished', 'cleversay'); ?>
                        </span>
                    <?php endif; ?>
                </h2>
            </div>
            
            <!-- Reuse Response Option -->
            <div class="reuse-response-option">
                <label class="reuse-checkbox-label">
                    <input type="checkbox" name="reuse_response" id="reuse_response" value="1" <?php checked($reuse_response, 1); ?>>
                    <strong><?php esc_html_e('Reuse Response from another Phrase Group?', 'cleversay'); ?></strong>
                </label>
                <p class="description reuse-description">
                    <?php esc_html_e('When enabled, this phrase group will use the response from another keyword/phrase combination. This allows you to maintain the response in one place.', 'cleversay'); ?>
                </p>
            </div>
            
            <!-- Reuse Response Selectors (shown when checkbox is checked) -->
            <div id="reuse-selectors" class="reuse-selectors" style="<?php echo $reuse_response ? '' : 'display: none;'; ?>">
                <div class="reuse-selector-grid">
                    <div class="selector-field">
                        <label for="reuse_keyword"><?php esc_html_e('Select Keyword', 'cleversay'); ?></label>
                        <select name="reuse_keyword" id="reuse_keyword" class="regular-text">
                            <option value=""><?php esc_html_e('— Select Keyword —', 'cleversay'); ?></option>
                            <?php foreach ($all_keywords as $kw): ?>
                                <option value="<?php echo esc_attr($kw); ?>" <?php selected($reuse_keyword, $kw); ?>>
                                    <?php echo esc_html($kw); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="selector-field">
                        <label for="reuse_sub_keyword"><?php esc_html_e('Select Phrase', 'cleversay'); ?></label>
                        <?php
                        // v4.37.54+: detect orphaned reuse pointers.
                        // The saved reuse_sub_keyword is a pattern
                        // string (legacy schema choice — patterns are
                        // mutable, IDs would be safer). If the saved
                        // pattern no longer matches any current entry
                        // under the chosen keyword, the dropdown
                        // would render with no selection — looking
                        // empty/broken to admin. Surface the orphaned
                        // state explicitly with a warning + a
                        // disabled placeholder option so admin sees
                        // exactly what's stored.
                        $reuse_orphaned = false;
                        if ($reuse_response && $reuse_keyword && $reuse_sub_keyword) {
                            $found = false;
                            if (isset($entries_by_keyword[$reuse_keyword])) {
                                foreach ($entries_by_keyword[$reuse_keyword] as $e) {
                                    if (($e['sub_keyword'] ?? '') === $reuse_sub_keyword) {
                                        $found = true;
                                        break;
                                    }
                                }
                            }
                            $reuse_orphaned = !$found;
                        }
                        ?>
                        <?php if ($reuse_orphaned): ?>
                            <div style="padding:8px 10px; margin-bottom:6px; background:#fff3cd; border:1px solid #ffc107; border-radius:3px; font-size:12px;">
                                <strong>⚠ <?php esc_html_e('This Reuse link is broken.', 'cleversay'); ?></strong>
                                <?php
                                printf(
                                    /* translators: %s = saved pattern that no longer exists */
                                    esc_html__('It points to phrase pattern %s under "%s", but no entry with that pattern exists there anymore. The pattern was probably changed (recompile, manual edit) before v4.37.54, when changes did not auto-update reuse links. Pick the correct phrase from the dropdown and save to reconnect.', 'cleversay'),
                                    '<code>' . esc_html($reuse_sub_keyword) . '</code>',
                                    esc_html($reuse_keyword)
                                );
                                ?>
                            </div>
                        <?php endif; ?>
                        <select name="reuse_sub_keyword" id="reuse_sub_keyword" class="regular-text">
                            <option value=""><?php esc_html_e('— Select Phrase —', 'cleversay'); ?></option>
                            <?php if ($reuse_keyword && isset($entries_by_keyword[$reuse_keyword])): ?>
                                <?php foreach ($entries_by_keyword[$reuse_keyword] as $entry): ?>
                                    <option value="<?php echo esc_attr($entry['sub_keyword']); ?>" <?php selected($reuse_sub_keyword, $entry['sub_keyword']); ?>>
                                        <?php echo esc_html(wp_trim_words($entry['question'], 15)); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($reuse_orphaned): ?>
                                <option value="<?php echo esc_attr($reuse_sub_keyword); ?>" selected disabled>
                                    <?php
                                    printf(
                                        /* translators: %s = orphaned pattern */
                                        esc_html__('[orphaned] %s', 'cleversay'),
                                        esc_html($reuse_sub_keyword)
                                    );
                                    ?>
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <div class="reuse-preview" id="reuse-preview" style="<?php echo ($reuse_response && $reuse_preview_content) ? '' : 'display: none;'; ?>">
                    <label><?php esc_html_e('Response Preview:', 'cleversay'); ?></label>
                    <div class="preview-content" id="reuse-preview-content"><?php echo wp_kses_post($reuse_preview_content); ?></div>
                </div>
            </div>
            
            <!-- Standard Response Editor (shown when checkbox is NOT checked) -->
            <div id="response-editor" class="response-editor-wrap" style="<?php echo $reuse_response ? 'display: none;' : ''; ?>">
                <p class="description">
                    <?php esc_html_e('This response will be shown for all patterns in this group.', 'cleversay'); ?>
                </p>
                
                <?php 
                wp_editor($group_response, 'group_response', [
                    'textarea_name' => 'response',
                    'textarea_rows' => 10,
                    'media_buttons' => true,
                    'teeny' => false,
                    'quicktags' => true,
                ]);
                ?>

                <?php
                // v4.37.51+: Modernize + Polish buttons.
                // v4.37.57+: also available on new (unsaved) entries —
                // operate on the editor's current content rather than
                // a DB row. For new entries, Polish stashes the hash
                // in a hidden field which the form save reads on
                // commit so polished_hash is set from day one.
                //
                // Modernize: deterministic legacy-HTML cleanup. No AI.
                // Polish:    AI-driven flow/grammar improvement. Two-step
                //            (preview → admin reviews diff → apply). Records
                //            polished_hash so runtime Polish KB skips
                //            already-polished entries on every future query.
                $entry_id_attr = (!$is_new && !empty($first_entry['id'])) ? (int) $first_entry['id'] : 0;
                ?>
                <div style="margin-top:10px; padding:10px 12px; background:#f6f7f7; border:1px solid #ddd; border-radius:3px;">
                    <button type="button" class="button" id="cs-modernize-response"
                            data-entry-id="<?php echo $entry_id_attr; ?>"
                            title="<?php esc_attr_e('Strip Word/legacy HTML noise (font tags, MSO classes, empty paragraphs, padding nbsp runs) from the editor. Idempotent.', 'cleversay'); ?>">
                        <?php echo \CleverSay\Icons::render('zap', 14); ?>
                        <?php esc_html_e('Modernize HTML', 'cleversay'); ?>
                    </button>
                    <button type="button" class="button" id="cs-polish-response"
                            data-entry-id="<?php echo $entry_id_attr; ?>"
                            style="margin-left:6px;"
                            title="<?php esc_attr_e('Use AI to improve the flow and readability of this response. Strict no-new-facts rules. Shows a diff for your review before applying.', 'cleversay'); ?>">
                        <?php echo \CleverSay\Icons::render('sparkles', 14); ?>
                        <?php esc_html_e('Polish Answer', 'cleversay'); ?>
                    </button>
                    <span id="cs-modernize-status" style="margin-left:10px; color:#666; font-size:12px;"></span>
                    <p class="description" style="margin:8px 0 0; font-size:12px;">
                        <?php esc_html_e('Modernize: strips legacy markup deterministically — no AI. Polish: AI rewrites for clarity without adding facts (preview before apply). Polish saves cost on every future query because runtime Polish KB skips already-polished entries.', 'cleversay'); ?>
                    </p>
                </div>

                <!-- Polish preview / diff modal — populated when Polish button is clicked. -->
                <div id="cs-polish-preview" style="display:none; margin-top:12px; padding:14px; background:white; border:2px solid #2271b1; border-radius:4px;">
                    <h3 style="margin:0 0 10px; font-size:14px;">
                        <?php echo \CleverSay\Icons::render('sparkles', 16); ?>
                        <?php esc_html_e('Polish preview', 'cleversay'); ?>
                        <span id="cs-polish-provider" style="font-size:11px; color:#666; font-weight:normal; margin-left:8px;"></span>
                    </h3>
                    <p style="margin:0 0 10px; font-size:12px; color:#555;">
                        <?php esc_html_e('Review the AI\'s rewrite below. The original is on the left, the polished version on the right. Apply only if the polished version is faithful to your original facts.', 'cleversay'); ?>
                    </p>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <div>
                            <div style="font-size:11px; font-weight:600; color:#666; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px;">
                                <?php esc_html_e('Original', 'cleversay'); ?>
                            </div>
                            <div id="cs-polish-original" style="padding:10px; background:#f6f7f7; border:1px solid #ddd; border-radius:3px; max-height:300px; overflow:auto; font-size:13px;"></div>
                        </div>
                        <div>
                            <div style="font-size:11px; font-weight:600; color:#2271b1; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px;">
                                <?php esc_html_e('Polished', 'cleversay'); ?>
                            </div>
                            <div id="cs-polish-polished" style="padding:10px; background:#f0f6fc; border:1px solid #2271b1; border-radius:3px; max-height:300px; overflow:auto; font-size:13px;"></div>
                        </div>
                    </div>
                    <div style="margin-top:12px;">
                        <button type="button" id="cs-polish-apply" class="button button-primary">
                            <?php esc_html_e('Apply polished version', 'cleversay'); ?>
                        </button>
                        <button type="button" id="cs-polish-cancel" class="button" style="margin-left:6px;">
                            <?php esc_html_e('Cancel', 'cleversay'); ?>
                        </button>
                        <span id="cs-polish-apply-status" style="margin-left:10px; color:#666; font-size:12px;"></span>
                    </div>
                </div>

                <!-- v4.37.57+: stash the polished_hash for new-entry case.
                     The save handler reads this and stores it on insert
                     so polished_hash is set from day one for entries
                     polished before they were saved. -->
                <input type="hidden" name="__pending_polished_hash" id="cs-pending-polished-hash" value="">

                <?php
                // v4.37.61+: small diagnostic button to verify polish state
                // when the badge isn't appearing as expected. Shows stored
                // hash vs current hash, normalized response preview, and
                // whether they match. Only useful for debugging — sits
                // unobtrusively in the corner.
                if (!$is_new && !empty($first_entry['id'])):
                ?>
                <p style="margin-top:6px; font-size:11px;">
                    <a href="#" id="cs-diagnose-polish"
                       data-entry-id="<?php echo (int) $first_entry['id']; ?>"
                       style="color:#888;">
                        <?php esc_html_e('Diagnose polish state', 'cleversay'); ?>
                    </a>
                </p>
                <div id="cs-diagnose-output" style="display:none; margin-top:8px; padding:10px; background:#fffbe5; border:1px solid #f0c33c; border-radius:3px; font-family:monospace; font-size:11px; white-space:pre-wrap; word-break:break-all;"></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Settings Section -->
        <div class="edit-section settings-section">
            <div class="section-header">
                <h2>
                    <?php echo \CleverSay\Icons::render('sliders', 16); ?>
                    <?php esc_html_e('Settings', 'cleversay'); ?>
                </h2>
            </div>
            
            <div class="settings-grid">
                <div class="setting-field">
                    <label for="status"><?php esc_html_e('Status', 'cleversay'); ?></label>
                    <select name="status" id="status">
                        <option value="active" <?php selected($group_status, 'active'); ?>><?php esc_html_e('Active', 'cleversay'); ?></option>
                        <option value="inactive" <?php selected($group_status, 'inactive'); ?>><?php esc_html_e('Inactive', 'cleversay'); ?></option>
                        <option value="hold" <?php selected($group_status, 'hold'); ?>><?php esc_html_e('Hold', 'cleversay'); ?></option>
                    </select>
                </div>
                
                <div class="setting-field">
                    <label for="expires_at"><?php esc_html_e('Expires On', 'cleversay'); ?></label>
                    <input type="date" name="expires_at" id="expires_at" value="<?php echo esc_attr($group_expires); ?>">
                </div>
                
                
                <div class="setting-field">
                    <label class="checkbox-label">
                        <input type="checkbox" name="show_rating" value="1" <?php checked($group_show_rating, 1); ?>>
                        <?php esc_html_e('Show Rating', 'cleversay'); ?>
                    </label>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="submit-actions">
            <button type="submit" class="button button-primary button-large">
                <?php echo \CleverSay\Icons::render('check', 16); ?>
                <?php esc_html_e('Validate & Save', 'cleversay'); ?>
            </button>
            
            <a href="<?php echo esc_url($detail_url); ?>" class="button button-secondary">
                <?php esc_html_e('Cancel', 'cleversay'); ?>
            </a>
        </div>
    </form>
</div>

<style>
.cleversay-phrase-edit h1 {
    display: flex;
    align-items: center;
    gap: 10px;
}

.cleversay-phrase-edit .back-link {
    text-decoration: none;
    color: #646970;
}

.cleversay-phrase-edit .keyword-badge {
    background: #2271b1;
    color: #fff;
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 14px;
    font-weight: 500;
}

/* Edit Sections */
.cleversay-phrase-edit .edit-section {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    margin-bottom: 20px;
}

.edit-section .section-header {
    padding: 12px 16px;
    background: #f6f7f7;
    border-bottom: 1px solid #c3c4c7;
}

.edit-section .section-header h2 {
    margin: 0;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.edit-section .description {
    padding: 12px 16px 0;
    margin: 0;
    color: #646970;
}

/* Question Variations Section */
.variations-section {
    border-left: 3px solid var(--cleversay-primary, #2271b1);
}
.variations-section .description {
    color: #555;
}

#cs-variations-container {
    padding: 12px 16px;
}
.cs-variation-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
}
.cs-variation-num {
    color: #888;
    font-size: 13px;
    min-width: 22px;
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.cs-variation-input {
    flex: 1;
    min-width: 0;
}
.cs-variation-delete {
    color: #b32d2e !important;
    text-decoration: none !important;
    padding: 4px;
    cursor: pointer;
}
.cs-variation-delete:hover {
    color: #d63638 !important;
}
.cs-variations-actions {
    padding: 0 16px 14px;
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
.cs-status-msg {
    font-size: 13px;
    color: #646970;
}
.cs-status-msg.cs-status-success { color: #00713c; }
.cs-status-msg.cs-status-error   { color: #b32d2e; }

.cs-pattern-preview-box {
    margin: 0 16px 16px;
    padding: 10px 14px;
    background: #f0f6fc;
    border: 1px solid #c5d9ed;
    border-radius: 4px;
}
.cs-pattern-preview-label {
    font-size: 12px;
    font-weight: 600;
    color: #1d2327;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}
#cs-pattern-preview-text {
    display: block;
    background: white;
    padding: 6px 10px;
    border-radius: 3px;
    border: 1px solid #dcdcde;
    font-family: Consolas, Monaco, monospace;
    font-size: 13px;
    word-break: break-all;
    color: #1d2327;
}

/* Recommended / Advanced badges */
.cs-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    margin-left: 8px;
    vertical-align: middle;
}
.cs-badge-recommended {
    background: #e7f5ec;
    color: #00713c;
}
.cs-badge-advanced {
    background: #f3f4f6;
    color: #646970;
}

/* Response Section */
.response-section .wp-editor-wrap {
    margin: 16px;
}

/* Reuse Response Feature */
.reuse-response-option {
    padding: 16px;
    border-bottom: 1px solid #e0e0e0;
    background: #f9f9f9;
}

.reuse-checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.reuse-checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
}

.reuse-description {
    margin: 8px 0 0 26px !important;
    padding: 0 !important;
    font-size: 12px;
}

.reuse-selectors {
    padding: 16px;
    background: #f0f6fc;
    border-bottom: 1px solid #2271b1;
}

.reuse-selector-grid {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 20px;
    margin-bottom: 16px;
}

.reuse-selector-grid .selector-field label {
    display: block;
    font-weight: 500;
    margin-bottom: 6px;
    color: #1d2327;
}

.reuse-selector-grid .selector-field select {
    width: 100%;
    padding: 8px;
    font-size: 14px;
}

.reuse-preview {
    margin-top: 16px;
    padding: 12px;
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
}

.reuse-preview label {
    display: block;
    font-weight: 600;
    font-size: 12px;
    color: #646970;
    margin-bottom: 8px;
}

.reuse-preview .preview-content {
    font-size: 13px;
    line-height: 1.6;
    color: #1d2327;
    max-height: 250px;
    overflow-y: auto;
    padding: 12px;
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 4px;
}

.reuse-preview .preview-content p {
    margin: 0 0 10px;
}

.reuse-preview .preview-content p:last-child {
    margin-bottom: 0;
}

.response-editor-wrap .description {
    padding: 12px 16px 0 !important;
}

/* Settings Section */
.settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 20px;
    padding: 16px;
}

.setting-field label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: #646970;
    margin-bottom: 4px;
}

.setting-field select,
.setting-field input[type="date"] {
    width: 100%;
}

.checkbox-label {
    display: flex !important;
    align-items: center;
    gap: 6px;
    cursor: pointer;
}

/* Submit Actions */
.submit-actions {
    display: flex;
    gap: 12px;
    padding: 20px;
    background: #f6f7f7;
    border-radius: 4px;
}

.submit-actions .button-large {
    display: flex;
    align-items: center;
    gap: 6px;
}
</style>

<script>
jQuery(document).ready(function($) {
    /* ─── Question Variations editor ───────────────────────────────────
     * Behavior:
     *   - Admin types variations in simple inputs.
     *   - As they type, AJAX-compile a pattern preview from the variations.
     *   - On form submit, the server reads variations[] and recompiles
     *     the pattern itself — the client doesn't need to build patterns[].
     *   - "AI Suggest" hits an endpoint that returns one variation +
     *     a compiled pattern based on topic/answer + existing variations.
     */
    const $varContainer  = $('#cs-variations-container');
    const $previewBox    = $('#cs-pattern-preview-box');
    const $previewText   = $('#cs-pattern-preview-text');

    function renumberVariations() {
        $varContainer.find('.cs-variation-row').each(function(i) {
            $(this).attr('data-index', i);
            $(this).find('.cs-variation-num').text((i + 1) + '.');
        });
    }

    function getVariations() {
        const out = [];
        $varContainer.find('.cs-variation-input').each(function() {
            const v = ($(this).val() || '').trim();
            if (v !== '') out.push(v);
        });
        return out;
    }

    // Snapshot the variations as they appeared on page load. Used to
    // detect whether the admin has actually edited anything before
    // we fire an AJAX recompile. Without this, browser autofill,
    // password managers, accessibility tools, and other event sources
    // can trigger the `input` handler without the admin actually
    // changing values — and the resulting recompile produces a
    // different-looking preview pattern than what's actually saved
    // (since the server's "skip recompile when unchanged" path will
    // preserve the legacy pattern at save time).
    const originalVariationsSorted = (function() {
        return getVariations().slice().sort();
    })();
    // wp_json_encode emits a valid JS string literal without
    // HTML-escaping. We can NOT use esc_js here: esc_js runs
    // _wp_specialchars which converts `&` → `&amp;`, and this string
    // gets pushed through `$previewText.text(...)` which displays
    // the literal `&amp;`. The user-visible result was patterns like
    // `past&amp;deadline|over&amp;deadline` showing up the moment
    // any input event fired. wp_json_encode handles JS escaping
    // correctly without touching ampersands.
    const storedPattern = <?php echo wp_json_encode($initial_stored_pattern ?? ''); ?>;

    function variationsMatchOriginal() {
        const cur = getVariations().slice().sort();
        if (cur.length !== originalVariationsSorted.length) return false;
        for (let i = 0; i < cur.length; i++) {
            if (cur[i] !== originalVariationsSorted[i]) return false;
        }
        return true;
    }

    function setStatus(msg, kind) {
        const $s = $('#cs-variations-status');
        $s.removeClass('cs-status-success cs-status-error');
        if (kind === 'success') $s.addClass('cs-status-success');
        if (kind === 'error')   $s.addClass('cs-status-error');
        $s.text(msg || '');
    }

    /* Compile pattern preview live as variations change (debounced). */
    let compileTimer = null;
    function compilePatternPreview() {
        clearTimeout(compileTimer);
        compileTimer = setTimeout(function() {
            const variations = getVariations();
            if (variations.length === 0) {
                $previewBox.hide();
                return;
            }

            // If the admin hasn't actually changed anything from
            // page load, leave the preview showing the stored
            // pattern (server-populated). This prevents inadvertent
            // input events (autofill, password managers, screen
            // readers) from making the preview disagree with what's
            // actually saved.
            if (variationsMatchOriginal()) {
                if (storedPattern !== '') {
                    $previewText.text(storedPattern);
                    $previewBox.show();
                } else {
                    $previewBox.hide();
                }
                return;
            }

            $.post(ajaxurl, {
                action:     'cleversay_compile_pattern',
                nonce:      cleversayAdmin && cleversayAdmin.nonce ? cleversayAdmin.nonce : '',
                variations: variations,
                // v4.35.0+: discriminator compiler needs keyword and
                // current group id (to exclude self from the sibling
                // pool). Both are baked into the page — keyword from
                // the URL query string, group_id from the hidden
                // field on the form.
                keyword:    <?php echo wp_json_encode($keyword); ?>,
                group_id:   <?php echo (int) $group_id; ?>
            }).done(function(resp) {
                if (resp && resp.success && resp.data && resp.data.pattern) {
                    $previewText.text(resp.data.pattern);
                    $previewBox.show();
                } else {
                    $previewBox.hide();
                }
            }).fail(function() { $previewBox.hide(); });
        }, 300);
    }

    /* v4.37.18: explicit "Recompile rule" button.
     *
     * Lets the admin regenerate the matching rule from the current
     * variations without editing them — useful after a config change
     * (stopword update, synonym addition, plugin upgrade with new
     * compiler logic) where the variations are still correct but the
     * compiled pattern is now stale. Without this, the only way to
     * trigger a recompile was to edit a variation, save, then
     * re-edit it back.
     *
     * Sets the hidden #cs-force-recompile field so the save handler
     * skips the unchanged-detection path and uses the freshly-
     * compiled pattern. Pairs with the live preview update below so
     * admin can see what the new rule will be before clicking Save.
     */
    $(document).on('click', '#cs-recompile-rule', function(e) {
        e.preventDefault();
        const $btn   = $(this);
        const $stat  = $('#cs-recompile-status');
        const variations = getVariations();

        if (variations.length === 0) {
            $stat.removeClass('cs-status-success').addClass('cs-status-error')
                 .text('<?php echo esc_js(__('Add at least one variation first.', 'cleversay')); ?>');
            return;
        }

        $btn.prop('disabled', true);
        // Iterative mode (v4.37.29+) tests candidate patterns against the
        // live KB and walks a ladder until one ranks this entry as #1
        // for every variation. Slower (~100-500ms vs ~30ms for plain
        // compile) but produces patterns that actually win at runtime.
        // Only enabled for existing entries — new entries don't have a
        // db row to test against.
        const useIterative = <?php echo $is_new ? 'false' : 'true'; ?>;
        $stat.removeClass('cs-status-success cs-status-error')
             .text(useIterative
                ? '<?php echo esc_js(__('Searching for a rule that ranks #1…', 'cleversay')); ?>'
                : '<?php echo esc_js(__('Recompiling…', 'cleversay')); ?>');

        $.post(ajaxurl, {
            action:     'cleversay_compile_pattern',
            nonce:      cleversayAdmin && cleversayAdmin.nonce ? cleversayAdmin.nonce : '',
            variations: variations,
            keyword:    <?php echo wp_json_encode($keyword); ?>,
            group_id:   <?php echo (int) $group_id; ?>,
            iterative:  useIterative ? 1 : 0
        }).done(function(resp) {
            $btn.prop('disabled', false);
            if (resp && resp.success && resp.data && resp.data.pattern) {
                $previewText.text(resp.data.pattern);
                $previewBox.show();
                $('#cs-force-recompile').val('1');

                // v4.37.32+: when iterative compile finds a verified
                // pattern that ranks #1, post that exact pattern back
                // on save so the server doesn't re-derive a different
                // (worse) pattern from variations and discard our
                // verified work. Only set when status === 'matched';
                // otherwise the deterministic compile is the right
                // choice on save.
                if (resp.data.iterative && resp.data.status === 'matched') {
                    $('#cs-verified-pattern').val(resp.data.pattern || '');
                } else {
                    $('#cs-verified-pattern').val('');
                }

                // Surface iterative status when present.
                if (resp.data.iterative) {
                    if (resp.data.status === 'matched') {
                        $stat.removeClass('cs-status-error').addClass('cs-status-success')
                             .text(
                                resp.data.attempts > 1
                                    ? '<?php echo esc_js(__('Found a rule that ranks #1 (after %d tries) — click Save to apply.', 'cleversay')); ?>'.replace('%d', resp.data.attempts)
                                    : '<?php echo esc_js(__('New rule ready — click Save to apply.', 'cleversay')); ?>'
                             );
                    } else {
                        // no_improvement: tried the ladder, didn't find a #1 winner.
                        $stat.removeClass('cs-status-success').addClass('cs-status-error')
                             .text('<?php echo esc_js(__('Couldn\'t find a rule that ranks #1. Best attempt shown — try adding a variation, a synonym, or rewording.', 'cleversay')); ?>');
                    }
                } else {
                    $stat.removeClass('cs-status-error').addClass('cs-status-success')
                         .text('<?php echo esc_js(__('New rule ready — click Save to apply.', 'cleversay')); ?>');
                }
            } else {
                $stat.removeClass('cs-status-success').addClass('cs-status-error')
                     .text((resp && resp.data && resp.data.message)
                           || '<?php echo esc_js(__('Recompile failed.', 'cleversay')); ?>');
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            $stat.removeClass('cs-status-success').addClass('cs-status-error')
                 .text('<?php echo esc_js(__('Network error during recompile.', 'cleversay')); ?>');
        });
    });

    /* v4.37.51+: pattern trace panel. Lazy-loads the per-token
       scoring breakdown the first time the admin expands the
       <details> element. Re-fetches if the variations change after
       a previous load (so the panel doesn't show stale scoring).
    */
    let traceLoadedFor = null; // signature of variations the trace was loaded for

    function getVariationsSignature() {
        const vars = $('.cs-variation-input').map(function() { return $.trim($(this).val()); }).get()
            .filter(function(v) { return v !== ''; });
        return vars.join('||');
    }

    function loadPatternTrace() {
        const sig = getVariationsSignature();
        if (sig === '') {
            $('#cs-pattern-trace-body').html(
                '<em style="color:#888;"><?php echo esc_js(__('Add at least one variation first.', 'cleversay')); ?></em>'
            );
            return;
        }
        if (traceLoadedFor === sig) return; // already loaded for current variations

        $('#cs-pattern-trace-body').html(
            '<em style="color:#888;"><?php echo esc_js(__('Loading scoring breakdown…', 'cleversay')); ?></em>'
        );

        const variations = $('.cs-variation-input').map(function() { return $(this).val(); }).get()
            .filter(function(v) { return $.trim(v) !== ''; });

        $.post(ajaxurl, {
            action:     'cleversay_pattern_trace',
            nonce:      cleversayAdmin && cleversayAdmin.nonce ? cleversayAdmin.nonce : '',
            variations: variations,
            keyword:    <?php echo wp_json_encode($keyword); ?>,
            group_id:   <?php echo (int) $group_id; ?>,
        }).done(function(resp) {
            if (!resp || !resp.success || !resp.data) {
                $('#cs-pattern-trace-body').html(
                    '<span style="color:#d63638;"><?php echo esc_js(__('Failed to load scoring data.', 'cleversay')); ?></span>'
                );
                return;
            }
            traceLoadedFor = sig;
            renderPatternTrace(resp.data);
        }).fail(function() {
            $('#cs-pattern-trace-body').html(
                '<span style="color:#d63638;"><?php echo esc_js(__('Network error.', 'cleversay')); ?></span>'
            );
        });
    }

    function renderPatternTrace(data) {
        const tokens = data.tokens || [];
        const summary = data.summary || '';
        const siblingCount = data.sibling_count || 0;
        const escHtml = function(s) { return $('<div>').text(s == null ? '' : String(s)).html(); };

        let html = '';
        html += '<div style="margin-bottom:8px;">';
        html += '<strong><?php echo esc_js(__('Summary:', 'cleversay')); ?></strong> ' + escHtml(summary);
        html += ' &nbsp;·&nbsp; <span style="color:#666;"><?php echo esc_js(__('siblings:', 'cleversay')); ?> ' + siblingCount + '</span>';
        html += '</div>';

        if (tokens.length === 0) {
            html += '<em style="color:#888;"><?php echo esc_js(__('No tokens to score.', 'cleversay')); ?></em>';
            $('#cs-pattern-trace-body').html(html);
            return;
        }

        html += '<table style="width:100%; border-collapse:collapse; font-size:12px;">';
        html += '<thead><tr style="background:#f6f7f7; border-bottom:1px solid #ddd;">';
        html += '<th style="text-align:left; padding:4px 6px;"><?php echo esc_js(__('Token', 'cleversay')); ?></th>';
        html += '<th style="text-align:left; padding:4px 6px;"><?php echo esc_js(__('Stem', 'cleversay')); ?></th>';
        html += '<th style="text-align:left; padding:4px 6px;"><?php echo esc_js(__('POS', 'cleversay')); ?></th>';
        html += '<th style="text-align:right; padding:4px 6px;"><?php echo esc_js(__('Score', 'cleversay')); ?></th>';
        html += '<th style="text-align:right; padding:4px 6px;"><?php echo esc_js(__('In siblings', 'cleversay')); ?></th>';
        html += '<th style="text-align:left; padding:4px 6px;"><?php echo esc_js(__('Notes', 'cleversay')); ?></th>';
        html += '</tr></thead><tbody>';
        tokens.forEach(function(t) {
            const inPattern = !!t.in_pattern;
            const rowStyle = inPattern
                ? 'background:#e6f4ea; font-weight:600;'
                : 'color:#888;';
            const tokenStyle = inPattern ? '' : 'text-decoration:line-through;';
            html += '<tr style="' + rowStyle + ' border-bottom:1px solid #eee;">';
            html += '<td style="padding:4px 6px;' + tokenStyle + '">' + escHtml(t.token) + '</td>';
            html += '<td style="padding:4px 6px;"><code style="font-size:11px;">' + escHtml(t.stem) + '</code></td>';
            html += '<td style="padding:4px 6px;">' + escHtml(t.pos) + '</td>';
            html += '<td style="padding:4px 6px; text-align:right;">' + (Number(t.score).toFixed(1)) + '</td>';
            html += '<td style="padding:4px 6px; text-align:right;">' + (t.sibling_freq || 0) + '</td>';
            html += '<td style="padding:4px 6px; font-size:11px;">';
            const notes = [];
            if (inPattern) notes.push('<span style="color:#00a32a;">★ in pattern</span>');
            if (t.category) notes.push('<span style="color:#1d4ed8;">' + escHtml(t.category) + '</span>');
            html += notes.join(' · ');
            html += '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        html += '<p style="margin-top:8px; color:#888; font-size:11px;">';
        html += '<?php echo esc_js(__('Highlighted rows are tokens included in the pattern. Strikethrough rows were filtered (low score, common in siblings, or filtered category).', 'cleversay')); ?>';
        html += '</p>';

        $('#cs-pattern-trace-body').html(html);
    }

    /* Lazy-load when the <details> opens.
     *
     * The `toggle` event on <details> does NOT bubble in most
     * browsers (it's emitted on the element directly), so a
     * delegated $(document).on('toggle', ...) handler doesn't
     * fire reliably. Bind directly to the element AND hook the
     * <summary> click as a safety net. */
    function handlePatternTraceToggle() {
        const $details = $('#cs-pattern-trace-details');
        if (!$details.length || !$details.prop('open')) return;
        loadPatternTrace();
        // Hydrate the "Test in Ask Question" link with the first
        // variation as a query string so admin lands on Ask
        // Question with the question pre-filled.
        const $link = $('#cs-test-in-ask-question');
        if (!$link.length) return;
        const baseHref = $link.data('base') || $link.attr('href');
        if (!$link.data('base')) $link.data('base', baseHref);
        const firstVar = $('.cs-variation-input').first().val() || '';
        if (firstVar.trim() !== '') {
            $link.attr('href', baseHref + '&prefill=' + encodeURIComponent(firstVar));
        } else {
            $link.attr('href', baseHref);
        }
    }

    // Direct binding (toggle event doesn't bubble through document)
    const detailsEl = document.getElementById('cs-pattern-trace-details');
    if (detailsEl) {
        detailsEl.addEventListener('toggle', handlePatternTraceToggle);
    }
    // Fallback: also fire on summary click after the browser has
    // updated the open state. setTimeout 0 lets the click finish
    // toggling before we read .open.
    $(document).on('click', '#cs-pattern-trace-details > summary', function() {
        setTimeout(handlePatternTraceToggle, 0);
    });

    /* Invalidate the loaded trace when variations change so next
       expansion re-fetches with fresh data. */
    $(document).on('input change', '.cs-variation-input', function() {
        traceLoadedFor = null;
        if ($('#cs-pattern-trace-details').prop('open')) {
            // If panel is currently open, reload immediately.
            loadPatternTrace();
        }
    });

    /* Clear the verified-pattern hidden field whenever the admin
       edits variations. The verified pattern was tied to a specific
       set of variations; if those change, the verification no longer
       applies and we should fall back to deterministic compile on
       save (or the admin can re-click Recompile). */
    $(document).on('input change', '.cs-variation-input', function() {
        $('#cs-verified-pattern').val('');
    });

    /* Helper: read the current editor content. Prefers TinyMCE
       (visual mode) and falls back to textarea (text mode or
       editor not yet initialized). */
    function getCurrentResponseHtml() {
        if (typeof tinymce !== 'undefined' && tinymce.get('group_response')) {
            return tinymce.get('group_response').getContent();
        }
        return $('#group_response').val() || '';
    }

    /* Helper: read variations from the form. Used as context for
       Polish so the LLM keeps polish on-topic. */
    function getCurrentVariations() {
        return $('.cs-variation-input').map(function() { return $(this).val(); }).get()
            .filter(function(v) { return $.trim(v) !== ''; });
    }

    /* v4.37.51+ / 4.37.57+: Modernize HTML button. Operates on the
       editor's current content (not the DB row), so it works for
       both new and existing entries. Updates the editor in place
       on success — admin still has to click Save to commit. */
    $(document).on('click', '#cs-modernize-response', function() {
        const $btn = $(this);
        const $status = $('#cs-modernize-status');
        const currentHtml = getCurrentResponseHtml();

        if (!currentHtml || !currentHtml.trim()) {
            $status.text('<?php echo esc_js(__('Editor is empty — nothing to clean.', 'cleversay')); ?>').css('color', '#666');
            return;
        }

        $btn.prop('disabled', true);
        $status.text('<?php echo esc_js(__('Cleaning…', 'cleversay')); ?>').css('color', '#666');

        $.post(ajaxurl, {
            action:        'cleversay_modernize_response',
            nonce:         cleversayAdmin && cleversayAdmin.nonce ? cleversayAdmin.nonce : '',
            response_html: currentHtml,
        }).done(function(resp) {
            $btn.prop('disabled', false);
            if (!resp || !resp.success || !resp.data) {
                $status.text('<?php echo esc_js(__('Failed.', 'cleversay')); ?>').css('color', '#d63638');
                return;
            }
            const d = resp.data;
            if (!d.changed) {
                $status.text('<?php echo esc_js(__('Already clean — no changes.', 'cleversay')); ?>').css('color', '#666');
                return;
            }

            // Update editor in place so a subsequent Save commits
            // the cleaned content rather than the original.
            if (typeof tinymce !== 'undefined' && tinymce.get('group_response')) {
                tinymce.get('group_response').setContent(d.response);
            }
            $('#group_response').val(d.response);

            // v4.37.58+: Modernize changes the response text, so any
            // existing polished marker is now stale. Hide the badge
            // and clear the pending hash so admin can see the state
            // is "needs re-polish if you want the marker back."
            $('#cs-polished-badge').hide();
            $('#cs-pending-polished-hash').val('');

            const saved = d.old_length - d.new_length;
            $status.html(
                '<span style="color:#00a32a;">✓ ' +
                '<?php echo esc_js(__('Cleaned and applied to editor —', 'cleversay')); ?> ' + saved + ' <?php echo esc_js(__('characters removed. Click "Validate and Save" to commit.', 'cleversay')); ?>' +
                '</span>'
            );
        }).fail(function() {
            $btn.prop('disabled', false);
            $status.text('<?php echo esc_js(__('Network error.', 'cleversay')); ?>').css('color', '#d63638');
        });
    });

    /* v4.37.52+: Polish Answer button. Two-step:
       1. Click → POST polish_preview → server returns original +
          polished. UI shows side-by-side diff for review.
       2. Admin clicks Apply → POST polish_apply → server writes
          polished + polished_hash. Or admin clicks Cancel →
          preview hides without applying.

       Polish saves cost on every future query for this entry by
       letting runtime Polish KB skip — runtime compares the
       response's hash against polished_hash and skips if equal. */
    let polishPreviewData = null;

    $(document).on('click', '#cs-polish-response', function() {
        const $btn = $(this);
        const $status = $('#cs-modernize-status');
        const currentHtml = getCurrentResponseHtml();
        const variations = getCurrentVariations();

        if (!currentHtml || !currentHtml.trim()) {
            $status.text('<?php echo esc_js(__('Editor is empty — nothing to polish.', 'cleversay')); ?>').css('color', '#666');
            return;
        }

        $btn.prop('disabled', true);
        $('#cs-modernize-response').prop('disabled', true);
        $status.text('<?php echo esc_js(__('Polishing… (this can take a few seconds)', 'cleversay')); ?>').css('color', '#666');

        $.post(ajaxurl, {
            action:        'cleversay_polish_preview',
            nonce:         cleversayAdmin && cleversayAdmin.nonce ? cleversayAdmin.nonce : '',
            // v4.37.68+: send entry_id alongside response_html. The
            // server uses it to auto-mark the entry as polished when
            // LLM judges the editor content already well-written —
            // direct DB write avoids the fragile JS-stash chain.
            entry_id:      $btn.data('entry-id') || 0,
            response_html: currentHtml,
            variations:    variations,
        }).done(function(resp) {
            $btn.prop('disabled', false);
            $('#cs-modernize-response').prop('disabled', false);
            if (!resp || !resp.success || !resp.data) {
                const msg = (resp && resp.data && resp.data.message) ? resp.data.message :
                            '<?php echo esc_js(__('Polish failed.', 'cleversay')); ?>';
                $status.text(msg).css('color', '#d63638');
                return;
            }
            const d = resp.data;
            if (!d.changed) {
                // v4.37.62+: LLM judged response already well-written.
                // The entry has functionally passed polish — record
                // that so runtime can skip redundant re-polishing.
                //
                // Server side, for existing entries, polished_hash
                // already got written (auto_marked=true). For editor-
                // content mode, stash the hash so save commits it.
                if (d.hash) {
                    $('#cs-pending-polished-hash').val(d.hash);
                }
                $('#cs-polished-badge').show();

                const message = d.auto_marked
                    ? '<?php echo esc_js(__('Already well-written — marked as polished, no further action needed.', 'cleversay')); ?>'
                    : '<?php echo esc_js(__('Already well-written — click "Validate and Save" to mark as polished.', 'cleversay')); ?>';
                $status.html('<span style="color:#00a32a;">✓ ' + message + '</span>');
                return;
            }
            polishPreviewData = d;
            $status.text('');
            $('#cs-polish-original').html(d.original);
            $('#cs-polish-polished').html(d.polished);
            $('#cs-polish-provider').text(d.provider ? '(' + d.provider + ')' : '');
            $('#cs-polish-preview').show();
            // Scroll the preview into view so admin sees it.
            $('html, body').animate({
                scrollTop: $('#cs-polish-preview').offset().top - 60
            }, 300);
        }).fail(function() {
            $btn.prop('disabled', false);
            $('#cs-modernize-response').prop('disabled', false);
            $status.text('<?php echo esc_js(__('Network error.', 'cleversay')); ?>').css('color', '#d63638');
        });
    });

    $(document).on('click', '#cs-polish-cancel', function() {
        $('#cs-polish-preview').hide();
        polishPreviewData = null;
    });

    $(document).on('click', '#cs-polish-apply', function() {
        if (!polishPreviewData) return;
        const $btn = $(this);
        const $status = $('#cs-polish-apply-status');
        const entryId = $('#cs-polish-response').data('entry-id');

        $btn.prop('disabled', true);
        $status.text('<?php echo esc_js(__('Applying…', 'cleversay')); ?>').css('color', '#666');

        $.post(ajaxurl, {
            action:   'cleversay_polish_apply',
            nonce:    cleversayAdmin && cleversayAdmin.nonce ? cleversayAdmin.nonce : '',
            // entry_id 0 means "new entry" — server returns hash but
            // doesn't write to DB. The hash gets stored when the form
            // saves via the __pending_polished_hash hidden field.
            entry_id: entryId || 0,
            polished: polishPreviewData.polished,
        }).done(function(resp) {
            $btn.prop('disabled', false);
            if (!resp || !resp.success || !resp.data) {
                const msg = (resp && resp.data && resp.data.message) ? resp.data.message :
                            '<?php echo esc_js(__('Apply failed.', 'cleversay')); ?>';
                $status.text(msg).css('color', '#d63638');
                return;
            }

            // Update editor in place so subsequent Save commits the
            // polished version (not the original).
            //
            // v4.37.67+: suppress the input-handler hash-wipe during
            // setContent. Without this, TinyMCE's input event fires
            // *during* or *just after* setContent, and the input
            // handler clears #cs-pending-polished-hash — racing with
            // our val(hash) below. With suppressionFlag set, the
            // handler skips the clear; we re-arm it after we set the
            // hash so subsequent admin edits still invalidate as
            // intended.
            window.__csSuppressHashWipe = true;
            const polishedContent = polishPreviewData.polished;
            if (typeof tinymce !== 'undefined' && tinymce.get('group_response')) {
                tinymce.get('group_response').setContent(polishedContent);
            }
            $('#group_response').val(polishedContent);

            // v4.37.57+: stash the hash in the hidden field so the
            // form save handler can write polished_hash on the new
            // row. Works for both modes — for existing entries the
            // server already saved, but stashing the hash is a
            // harmless duplicate (save-handler would re-read DB).
            if (resp.data.hash) {
                $('#cs-pending-polished-hash').val(resp.data.hash);
            }

            // Re-arm the input-handler-driven hash wipe AFTER any
            // async events from setContent have settled. setTimeout
            // 0 pushes this past the current task, so any TinyMCE
            // input/change events from setContent run with the
            // suppression flag still set.
            setTimeout(function() { window.__csSuppressHashWipe = false; }, 50);

            // v4.37.58+: light up the polished badge. Apply just
            // wrote the polished version to the editor AND server
            // either saved (existing) or stashed the hash for save
            // (new). Either way, the editor's current content now
            // hashes to the marker — admin can verify visually.
            $('#cs-polished-badge').show();

            // Hide the preview — admin can immediately click Save.
            $('#cs-polish-preview').hide();

            const message = resp.data.mode === 'pending'
                ? '<?php echo esc_js(__('Polish applied to editor. Click "Validate and Save" to commit (the polished_hash will be saved with the entry).', 'cleversay')); ?>'
                : '<?php echo esc_js(__('Polish applied to editor. Click "Validate and Save" to commit, or keep editing.', 'cleversay')); ?>';

            $status.html('<span style="color:#00a32a;">✓ ' + message + '</span>');
            $('#cs-modernize-status').html($status.html());

            polishPreviewData = null;
        }).fail(function() {
            $btn.prop('disabled', false);
            $status.text('<?php echo esc_js(__('Network error.', 'cleversay')); ?>').css('color', '#d63638');
        });
    });

    /* v4.37.57+: invalidate the pending polished hash whenever the
       editor content changes after an Apply. The stored hash is for
       a specific polished string; if admin keeps editing, the form
       submit's response no longer matches that hash, so save handler
       would skip preservation anyway — but clearing here makes the
       intent explicit and prevents subtle race conditions.
       
       v4.37.58+: also dim the polished badge when editor content
       changes. The stored DB marker is still there until save, but
       it no longer reflects the editor's content — showing the
       badge would be misleading.

       v4.37.67+: skip the wipe when window.__csSuppressHashWipe is
       set. Polish Apply sets that flag right before calling
       setContent, which fires synthetic input/change events on the
       editor — without the flag, those synthetic events would clear
       the hash we're trying to set. */
    $(document).on('input change', '#group_response', function() {
        if (window.__csSuppressHashWipe) return;
        $('#cs-pending-polished-hash').val('');
        $('#cs-polished-badge').hide();
    });

    // TinyMCE events don't fire on the underlying textarea until tab
    // switch/save, so also hook the editor's own change events when
    // it initializes. Fire on keypress/input inside the iframe body.
    if (typeof tinymce !== 'undefined') {
        // Hook all editors that finish initializing (handles late init).
        $(document).on('tinymce-editor-init', function(event, editor) {
            if (editor.id === 'group_response') {
                editor.on('input change keyup', function() {
                    if (window.__csSuppressHashWipe) return;
                    $('#cs-pending-polished-hash').val('');
                    $('#cs-polished-badge').hide();
                });
            }
        });
        // For editors already initialized when this script runs.
        if (tinymce.get('group_response')) {
            tinymce.get('group_response').on('input change keyup', function() {
                if (window.__csSuppressHashWipe) return;
                $('#cs-pending-polished-hash').val('');
                $('#cs-polished-badge').hide();
            });
        }
    }

    /* v4.37.61+: diagnostic button — fetch polish state and render
       the stored hash, current hash, match status, and normalized
       response preview. Used to debug "badge missing" cases by
       showing exactly what's stored vs computed. */
    $(document).on('click', '#cs-diagnose-polish', function(e) {
        e.preventDefault();
        const $out = $('#cs-diagnose-output');
        const entryId = $(this).data('entry-id');
        $out.text('<?php echo esc_js(__('Loading…', 'cleversay')); ?>').show();

        $.post(ajaxurl, {
            action:   'cleversay_polish_diagnose',
            nonce:    cleversayAdmin && cleversayAdmin.nonce ? cleversayAdmin.nonce : '',
            entry_id: entryId,
        }).done(function(resp) {
            if (!resp || !resp.success || !resp.data) {
                $out.text('<?php echo esc_js(__('Diagnose failed.', 'cleversay')); ?>');
                return;
            }
            const d = resp.data;
            const lines = [
                '── Polish State ──',
                'plugin_version:     ' + (d.plugin_version || '?'),
                'installed_version:  ' + (d.installed_version || '?'),
                'column_exists:      ' + (d.column_exists ? 'yes' : 'NO — migration did NOT run'),
                'entry_id:           ' + d.entry_id,
                'stored_hash:        ' + (d.stored_hash || '(empty / NULL)'),
                'stored_hash_set:    ' + (d.stored_hash_set ? 'yes' : 'no'),
                'current_hash:       ' + d.current_hash,
                'hashes_match:       ' + (d.hashes_match ? 'YES — badge should show, runtime polish skips' : 'NO — badge hidden, runtime polish runs'),
                '',
                'response length:    ' + d.response_length + ' chars',
                'normalized length:  ' + d.normalized_length + ' chars',
                'available_columns:  ' + (d.available_columns || []).join(', '),
                '',
                '── Response preview (first 200 chars) ──',
                d.response_preview,
                '',
                '── Normalized preview (first 200 chars) ──',
                d.normalized_preview,
            ];

            if (d.last_save_debug) {
                const sd = d.last_save_debug;
                lines.push('');
                lines.push('── Last Save (within 15 min) ──');
                lines.push('timestamp:           ' + sd.timestamp);
                lines.push('keyword:             ' + sd.keyword);
                lines.push('pending_from_form:   ' + (sd.pending_from_form || '(empty)'));
                lines.push('preserved_map:       ' + (sd.preserved_map || []).join(', '));
                lines.push('response_hash:       ' + sd.response_hash);
                lines.push('preserved_chosen:    ' + (sd.preserved_chosen || '(none — set NULL)'));
                lines.push('response_length:     ' + sd.response_length);
                lines.push('response_preview:    ' + sd.response_preview);
                lines.push('');
                if (sd.preserved_chosen) {
                    lines.push('Save preserved the hash. If badge still missing, there\'s a different issue.');
                } else {
                    if (sd.pending_from_form && sd.pending_from_form !== sd.response_hash) {
                        lines.push('Mismatch: the form sent a pending hash, but the submitted response');
                        lines.push('hashes to a different value. Save round-trip altered the content.');
                    } else if (!sd.pending_from_form) {
                        lines.push('No pending hash sent with the form. Polish JS didn\'t stash it,');
                        lines.push('OR the editor "input" handler cleared it after Apply (admin edited).');
                    } else {
                        lines.push('Hash sent and matched. (Then how did preserved_chosen end up null?)');
                    }
                }
            }

            if (!d.column_exists) {
                lines.push('');
                lines.push('── DIAGNOSIS ──');
                lines.push('CRITICAL: polished_hash column is MISSING from the database.');
                lines.push('The migration that adds this column did not run. This is why no');
                lines.push('badges show anywhere — every polish-apply silently fails.');
                lines.push('');
                lines.push('Fix: deactivate and reactivate the plugin from WP Admin → Plugins.');
                lines.push('That re-runs the migration. After reactivation, re-polish your');
                lines.push('entries and the badges will start appearing.');
            } else if (!d.hashes_match && d.stored_hash_set) {
                lines.push('');
                lines.push('── DIAGNOSIS ──');
                lines.push('Hash stored, but does not match current response.');
                lines.push('Most likely cause: the response was edited after polish was applied,');
                lines.push('OR the form save round-trip altered HTML in some way (rare).');
                lines.push('Fix: re-polish to refresh the hash.');
            } else if (!d.stored_hash_set) {
                lines.push('');
                lines.push('── DIAGNOSIS ──');
                lines.push('No polished_hash stored. Either:');
                lines.push('  1. Polish was never applied to this entry, OR');
                lines.push('  2. Polish was applied but a subsequent Save wiped the hash.');
                lines.push('     If "Last Save" is shown above, look at it for specifics.');
                lines.push('Fix: click Polish Answer, Apply, then Save.');
            }
            $out.text(lines.join('\n'));
        }).fail(function() {
            $out.text('<?php echo esc_js(__('Network error.', 'cleversay')); ?>');
        });
    });

    /* Add a new empty variation row. */
    $(document).on('click', '#cs-add-variation', function(e) {
        e.preventDefault();
        const idx = $varContainer.find('.cs-variation-row').length;
        const $row = $(
            '<div class="cs-variation-row" data-index="' + idx + '">' +
                '<span class="cs-variation-num">' + (idx + 1) + '.</span>' +
                '<input type="text" name="variations[]" class="cs-variation-input regular-text" placeholder="<?php echo esc_js(__('Add another way students might ask this question', 'cleversay')); ?>">' +
                '<button type="button" class="button-link cs-variation-delete">×</button>' +
            '</div>'
        );
        $varContainer.append($row);
        $row.find('.cs-variation-input').focus();
    });

    /* Remove a variation. */
    $(document).on('click', '.cs-variation-delete', function(e) {
        e.preventDefault();
        const $row = $(this).closest('.cs-variation-row');
        if ($varContainer.find('.cs-variation-row').length <= 1) {
            $row.find('.cs-variation-input').val('').trigger('input');
            return;
        }
        $row.remove();
        renumberVariations();
        compilePatternPreview();
    });

    /* Live preview as admin types/edits. */
    $(document).on('input', '.cs-variation-input', function() {
        compilePatternPreview();
    });

    /* AI Suggest variations from topic + answer.
     * v4.35.3+: One suggestion per click instead of four. Sends the
     * existing variations to the AI as context so it knows what to
     * avoid duplicating. Click again for another. The server-side
     * counterpart enforces the same single-suggestion contract. */
    $(document).on('click', '#cs-suggest-variations', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const keyword = <?php echo wp_json_encode($keyword); ?>;

        // Use the first variation as the canonical question if any
        // are filled in. Pre-Phase-C this came from the legacy
        // patterns[].phrase-input field; now there's no separate
        // canonical field — the first variation IS the canonical.
        const filled = getVariations();
        const canonical = filled.length > 0 ? filled[0] : '';

        // Pull response (TinyMCE-aware). The wp_editor() instance was
        // registered with id 'group_response' (textarea_name is 'response',
        // but the DOM id and TinyMCE instance id both use the editor id arg).
        let answer = '';
        if (typeof tinymce !== 'undefined' && tinymce.get('group_response')) {
            answer = tinymce.get('group_response').getContent({ format: 'text' });
        } else {
            answer = $('#group_response').val() || '';
        }

        if (!keyword && !canonical && !answer.trim()) {
            setStatus('<?php echo esc_js(__('Add a response first, then click AI Suggest.', 'cleversay')); ?>', 'error');
            return;
        }

        // Snapshot existing variations BEFORE the request so we can
        // (a) send them to the AI as context, and (b) post-filter
        // any duplicate the AI returns despite being told.
        const existingBefore = getVariations();

        $btn.prop('disabled', true);
        setStatus('<?php echo esc_js(__('Asking AI for a suggestion…', 'cleversay')); ?>');

        $.post(ajaxurl, {
            action:   'cleversay_ai_suggest_variations',
            nonce:    cleversayAdmin && cleversayAdmin.nonce ? cleversayAdmin.nonce : '',
            topic:    keyword,
            question: canonical,
            answer:   answer,
            existing: existingBefore,
            keyword:  keyword,
            group_id: <?php echo (int) $group_id; ?>
        }).done(function(resp) {
            $btn.prop('disabled', false);
            if (!resp || !resp.success || !resp.data) {
                setStatus(
                    (resp && resp.data && resp.data.message) || '<?php echo esc_js(__('AI request failed.', 'cleversay')); ?>',
                    'error'
                );
                return;
            }

            // The server returns `variation` as a single string; for
            // backward-compat it also returns `variations` as a 1-elem
            // array. Take whichever is present.
            const suggestion = (resp.data.variation || (resp.data.variations && resp.data.variations[0]) || '').trim();
            if (suggestion === '') {
                setStatus('<?php echo esc_js(__('AI returned no suggestion. Try again.', 'cleversay')); ?>', 'error');
                return;
            }

            // Defensive: if the AI duplicated an existing variation
            // despite the prompt instruction, refuse silently and ask
            // the admin to retry.
            const lowerExisting = existingBefore.map(v => v.toLowerCase().trim());
            if (lowerExisting.includes(suggestion.toLowerCase())) {
                setStatus('<?php echo esc_js(__('AI suggested a duplicate. Click again for a different one.', 'cleversay')); ?>', 'error');
                return;
            }

            // If the only existing rows are empty placeholders, replace
            // them. Otherwise append (one new row).
            const allEmpty = existingBefore.length === 0;
            if (allEmpty) {
                $varContainer.empty();
            }

            const idx = $varContainer.find('.cs-variation-row').length;
            $varContainer.append(
                '<div class="cs-variation-row" data-index="' + idx + '">' +
                    '<span class="cs-variation-num">' + (idx + 1) + '.</span>' +
                    '<input type="text" name="variations[]" class="cs-variation-input regular-text" value="' + $('<div>').text(suggestion).html().replace(/"/g, '&quot;') + '">' +
                    '<button type="button" class="button-link cs-variation-delete">×</button>' +
                '</div>'
            );

            renumberVariations();
            compilePatternPreview();
            setStatus('<?php echo esc_js(__('Suggestion added. Click again for another.', 'cleversay')); ?>', 'success');
        }).fail(function(xhr) {
            $btn.prop('disabled', false);
            const msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                ? xhr.responseJSON.data.message
                : '<?php echo esc_js(__('AI request failed.', 'cleversay')); ?>';
            setStatus(msg, 'error');
        });
    });

    /* Before submit:
     *   - For non-aadefault entries: nothing to do. The variations[]
     *     array is already in the form, and the server recompiles
     *     the pattern from variations[] on save (see
     *     handle_save_phrase_group in admin/class-admin.php). We used
     *     to also build patterns[] client-side, but the server is the
     *     source of truth — the client build was redundant and could
     *     be stale (300ms compile debounce vs fast click).
     *   - For aadefault entries: sync the visible canonical-question
     *     input into the hidden patterns[0][phrase] mirror so the
     *     save handler validates the right value.
     */
    $('#phrase-group-form').on('submit', function(e) {
        const $aaQ = $('#cs-aadefault-question');
        if ($aaQ.length) {
            $('#cs-aadefault-phrase-mirror').val(($aaQ.val() || '').trim());
        }
    });

    // No auto-compile on page load. The preview is pre-populated
    // server-side with the row's stored pattern. Recompilation fires
    // only when the admin actually edits a variation — see the
    // input/delete/add handlers above. This avoids legacy hand-tuned
    // patterns being silently overwritten by a newly-compiled preview
    // the admin didn't ask for.

    /* ─── End Question Variations editor ───────────────────────────── */

    // Entries by keyword for reuse response dropdowns
    const entriesByKeyword = <?php echo json_encode($entries_by_keyword); ?>;
    
    // Reuse Response Toggle
    $('#reuse_response').on('change', function() {
        if ($(this).is(':checked')) {
            $('#reuse-selectors').show();
            $('#response-editor').hide();
        } else {
            $('#reuse-selectors').hide();
            $('#response-editor').show();
            // Clear the reuse selections
            $('#reuse_keyword').val('');
            $('#reuse_sub_keyword').empty().append('<option value=""><?php echo esc_js(__('— Select Phrase —', 'cleversay')); ?></option>');
            $('#reuse-preview').hide();
        }
    });
    
    // Populate phrases when keyword is selected
    $('#reuse_keyword').on('change', function() {
        const keyword = $(this).val();
        const $phraseSelect = $('#reuse_sub_keyword');
        
        $phraseSelect.empty().append('<option value=""><?php echo esc_js(__('— Select Phrase —', 'cleversay')); ?></option>');
        $('#reuse-preview').hide();
        
        if (keyword && entriesByKeyword[keyword]) {
            entriesByKeyword[keyword].forEach(function(entry) {
                const label = entry.question.substring(0, 80) + (entry.question.length > 80 ? '...' : '');
                $phraseSelect.append(
                    $('<option></option>')
                        .val(entry.sub_keyword)
                        .text(label)
                );
            });
        }
    });
    
    // Show preview when phrase is selected
    $('#reuse_sub_keyword').on('change', function() {
        const keyword = $('#reuse_keyword').val();
        const subKeyword = $(this).val();
        
        if (keyword && subKeyword) {
            // Fetch the response via AJAX
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'cleversay_get_response_preview',
                    nonce: cleversayAdmin.nonce,
                    keyword: keyword,
                    sub_keyword: subKeyword
                },
                success: function(response) {
                    if (response.success && response.data.response) {
                        $('#reuse-preview-content').html(response.data.response);
                        $('#reuse-preview').show();
                    } else {
                        $('#reuse-preview').hide();
                    }
                },
                error: function() {
                    $('#reuse-preview').hide();
                }
            });
        } else {
            $('#reuse-preview').hide();
        }
    });
    
});
</script>
