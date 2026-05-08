<?php
/**
 * Knowledge Base - Keyword Detail View
 * 
 * Shows the keyword settings and a list of phrase groups
 *
 * @package CleverSay
 * @since 2.0.40
 */

defined('ABSPATH') || exit;

global $wpdb;

$keyword = sanitize_text_field($_GET['keyword'] ?? '');

if (empty($keyword)) {
    wp_die(__('Keyword not specified', 'cleversay'));
}

// Get all entries for this keyword
$entries = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}cleversay_knowledge 
     WHERE keyword = %s 
     ORDER BY CASE WHEN sub_keyword = 'aadefault' THEN 0 ELSE 1 END, id ASC",
    $keyword
), ARRAY_A);

if (empty($entries)) {
    wp_die(__('Keyword not found', 'cleversay'));
}

// Get synonyms for this keyword
$keyword_synonyms = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}cleversay_synonyms WHERE canonical_word = %s",
    strtolower($keyword)
), ARRAY_A);

// Parse synonym data
$synonym_variants = '';
$synonym_misspellings = '';
if ($keyword_synonyms) {
    $synonym_variants = $keyword_synonyms['variant_words'] ?? '';
    $synonym_misspellings = $keyword_synonyms['misspellings'] ?? '';
}

// Each row is its own phrase group (corrected model from v4.34.0).
// The earlier code grouped rows by md5(response) — which incorrectly
// merged distinct phrase groups whose `reuse_response` pointer had
// been lost in the original WordPress refactor. Now: one card per
// row, full stop. Reuse rows show "Linked to ..." in the response
// preview to indicate where their actual response comes from.
$phrase_groups = [];
$reuse_phrases_cache = []; // Cache linked phrases to avoid repeated queries

foreach ($entries as $entry) {
    // Each row gets its own group entry, keyed by id.
    $group_key = 'row_' . (int) $entry['id'];

    $reuse_phrase = '';
    if (!empty($entry['reuse_response']) && !empty($entry['reuse_keyword'])) {
        $cache_key = $entry['reuse_keyword'] . '|' . $entry['reuse_sub_keyword'];
        if (!isset($reuse_phrases_cache[$cache_key])) {
            $linked_entry = $wpdb->get_row($wpdb->prepare(
                "SELECT question FROM {$wpdb->prefix}cleversay_knowledge 
                 WHERE keyword = %s AND (sub_keyword = %s OR (sub_keyword IS NULL AND %s = 'aadefault'))
                 LIMIT 1",
                $entry['reuse_keyword'],
                $entry['reuse_sub_keyword'],
                $entry['reuse_sub_keyword']
            ), ARRAY_A);
            $reuse_phrases_cache[$cache_key] = $linked_entry ? $linked_entry['question'] : '';
        }
        $reuse_phrase = $reuse_phrases_cache[$cache_key];
    }

    $is_default = (strtolower(trim($entry['sub_keyword'] ?? '')) === 'aadefault' || empty($entry['sub_keyword']));

    $phrase_groups[$group_key] = [
        'id' => $entry['id'],
        'response' => $entry['response'],
        'response_preview' => wp_trim_words(strip_tags($entry['response']), 15),
        'status' => $entry['status'],
        'expires_at' => $entry['expires_at'],
        'patterns' => [[
            'id' => $entry['id'],
            'pattern' => $entry['sub_keyword'],
            'phrase' => $entry['question'],
            'hits' => $entry['hits'] ?? 0,
        ]],
        'is_default' => $is_default,
        'total_hits' => $entry['hits'] ?? 0,
        'reuse_response' => $entry['reuse_response'] ?? 0,
        'reuse_keyword' => $entry['reuse_keyword'] ?? '',
        'reuse_sub_keyword' => $entry['reuse_sub_keyword'] ?? '',
        'reuse_phrase' => $reuse_phrase,
        // v4.37.69+: polish state for the listing badge. Mirrors the
        // editor's badge logic — true when stored hash matches the
        // hash of the current response. Reuse entries can't be
        // polished directly (they pull response from elsewhere) so
        // their flag is always false.
        'polished' => (function() use ($entry) {
            if (!empty($entry['reuse_response'])) return false;
            $stored_hash = (string) ($entry['polished_hash'] ?? '');
            if ($stored_hash === '' || !class_exists('\\CleverSay\\Admin')) return false;
            $current_hash = \CleverSay\Admin::compute_response_hash((string) ($entry['response'] ?? ''));
            return $stored_hash === $current_hash;
        })(),
    ];

    // For reuse entries, the response_preview shows the link target
    // since the row's own `response` column is just a placeholder.
    if (!empty($entry['reuse_response']) && !empty($entry['reuse_keyword'])) {
        $phrase_groups[$group_key]['response_preview'] = sprintf(
            __('Linked to: %s / %s', 'cleversay'),
            $entry['reuse_keyword'],
            wp_trim_words($reuse_phrase, 8) ?: $entry['reuse_sub_keyword']
        );
    }
}

// Reindex and sort (default first)
$phrase_groups = array_values($phrase_groups);
usort($phrase_groups, function($a, $b) {
    if ($a['is_default'] && !$b['is_default']) return -1;
    if (!$a['is_default'] && $b['is_default']) return 1;
    return 0;
});

// Bulk-load Question Variations for every group's canonical entry id.
// Variations live in cleversay_kb_variations keyed to the lowest-id row
// of each phrase group (per the editor's canonical-id resolution). One
// IN-list query keeps this O(1) per page render rather than O(groups).
$canonical_ids = array_column($phrase_groups, 'id');
$variations_by_id = [];
if (!empty($canonical_ids)) {
    $id_list = implode(',', array_map('intval', $canonical_ids));
    $variation_rows = $wpdb->get_results(
        "SELECT knowledge_id, variation_text
           FROM {$wpdb->prefix}cleversay_kb_variations
          WHERE knowledge_id IN ($id_list)
          ORDER BY knowledge_id ASC, id ASC",
        ARRAY_A
    );
    foreach ($variation_rows as $vr) {
        $variations_by_id[(int) $vr['knowledge_id']][] = $vr['variation_text'];
    }
}
foreach ($phrase_groups as &$g) {
    $g['variations'] = $variations_by_id[(int) $g['id']] ?? [];
}
unset($g);

// Get first entry for keyword-level settings
$keyword_settings = $entries[0];

// Get categories

$base_url = admin_url('admin.php?page=cleversay-knowledge');
?>

<div class="wrap cleversay-admin cleversay-keyword-detail">
    <h1>
        <a href="<?php echo esc_url($base_url); ?>" class="back-link" title="<?php esc_attr_e('Back to Keywords', 'cleversay'); ?>">
            <?php echo \CleverSay\Icons::render('arrow-left', 16); ?>
        </a>
        <?php echo \CleverSay\Icons::render('tag', 26); ?>
        <?php esc_html_e('Keyword:', 'cleversay'); ?>
        <span class="keyword-name"><?php echo esc_html($keyword); ?></span>
    </h1>
    
    <hr class="wp-header-end">

    <?php if (isset($_GET['message'])): ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                $messages = [
                    'saved' => __('Changes saved successfully.', 'cleversay'),
                    'group_saved' => __('Phrase group saved successfully.', 'cleversay'),
                    'group_deleted' => __('Phrase group deleted.', 'cleversay'),
                    'keyword_updated' => __('Keyword updated successfully.', 'cleversay'),
                ];
                echo esc_html($messages[$_GET['message']] ?? '');
                ?>
            </p>
        </div>
    <?php endif; ?>

    <!-- Keyword Settings Card -->
    <div class="keyword-settings-card">
        <div class="card-header">
            <h2>
                <?php echo \CleverSay\Icons::render('tag', 16); ?>
                <?php esc_html_e('Keyword Settings', 'cleversay'); ?>
            </h2>
            <button type="button" class="button button-small" id="edit-keyword-btn">
                <?php echo \CleverSay\Icons::render('edit', 16); ?>
                <?php esc_html_e('Edit Keyword', 'cleversay'); ?>
            </button>
        </div>
        <div class="card-body">
            <div class="keyword-display" id="keyword-display">
                <div class="keyword-info-grid">
                    <div class="info-item">
                        <label><?php esc_html_e('Keyword', 'cleversay'); ?></label>
                        <span class="keyword-value"><?php echo esc_html($keyword); ?></span>
                    </div>
                    <div class="info-item">
                        <label><?php esc_html_e('Phrase Groups', 'cleversay'); ?></label>
                        <span><?php echo count($phrase_groups); ?></span>
                    </div>
                    <div class="info-item">
                        <label><?php esc_html_e('Total Patterns', 'cleversay'); ?></label>
                        <span><?php echo count($entries); ?></span>
                    </div>
                    <div class="info-item">
                        <label><?php esc_html_e('Total Hits', 'cleversay'); ?></label>
                        <span><?php echo number_format(array_sum(array_column($entries, 'hits'))); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Edit Keyword Form (hidden by default) -->
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="keyword-edit-form" style="display: none;">
                <input type="hidden" name="action" value="cleversay_update_keyword">
                <input type="hidden" name="old_keyword" value="<?php echo esc_attr($keyword); ?>">
                <?php wp_nonce_field('cleversay_update_keyword', 'cleversay_nonce'); ?>
                
                <div class="keyword-edit-fields">
                    <div class="field-row">
                        <label for="new_keyword"><?php esc_html_e('Keyword', 'cleversay'); ?></label>
                        <input type="text" id="new_keyword" name="new_keyword" value="<?php echo esc_attr($keyword); ?>" class="regular-text" required>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Save Keyword', 'cleversay'); ?>
                    </button>
                    <button type="button" class="button" id="cancel-keyword-edit">
                        <?php esc_html_e('Cancel', 'cleversay'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Keyword Synonyms Card -->
    <div class="keyword-synonyms-card">
        <div class="card-header">
            <h2>
                <?php echo \CleverSay\Icons::render('refresh-cw', 16); ?>
                <?php esc_html_e('Keyword Synonyms', 'cleversay'); ?>
            </h2>
            <button type="button" class="button button-small" id="edit-synonyms-btn">
                <?php echo \CleverSay\Icons::render('edit', 16); ?>
                <?php esc_html_e('Edit Synonyms', 'cleversay'); ?>
            </button>
        </div>
        <div class="card-body">
            <p class="description" style="margin-bottom: 12px;">
                <?php esc_html_e('When users search using these words, they will match this keyword.', 'cleversay'); ?>
            </p>
            
            <!-- Display Mode -->
            <div class="synonyms-display" id="synonyms-display">
                <?php if (empty($synonym_variants) && empty($synonym_misspellings)): ?>
                    <p class="no-synonyms"><?php esc_html_e('No synonyms defined for this keyword.', 'cleversay'); ?></p>
                <?php else: ?>
                    <?php if (!empty($synonym_variants)): ?>
                        <div class="synonym-group">
                            <label><?php esc_html_e('Synonym Words:', 'cleversay'); ?></label>
                            <div class="synonym-tags">
                                <?php foreach (array_filter(array_map('trim', explode(',', $synonym_variants))) as $variant): ?>
                                    <span class="synonym-tag"><?php echo esc_html($variant); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($synonym_misspellings)): ?>
                        <div class="synonym-group">
                            <label><?php esc_html_e('Common Misspellings:', 'cleversay'); ?></label>
                            <div class="synonym-tags misspellings">
                                <?php foreach (array_filter(array_map('trim', explode(',', $synonym_misspellings))) as $misspelling): ?>
                                    <span class="synonym-tag"><?php echo esc_html($misspelling); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <!-- Edit Mode (hidden by default) -->
            <div class="synonyms-edit" id="synonyms-edit" style="display: none;">
                <div class="synonym-field">
                    <label for="synonym_variants"><?php esc_html_e('Synonym Words', 'cleversay'); ?></label>
                    <input type="text" id="synonym_variants" value="<?php echo esc_attr($synonym_variants); ?>" class="large-text" 
                           placeholder="<?php esc_attr_e('e.g., withdraw, remove, cancel', 'cleversay'); ?>">
                    <p class="description"><?php esc_html_e('Comma-separated words that mean the same as this keyword.', 'cleversay'); ?></p>
                </div>
                
                <div class="synonym-field">
                    <label for="synonym_misspellings"><?php esc_html_e('Common Misspellings', 'cleversay'); ?></label>
                    <input type="text" id="synonym_misspellings" value="<?php echo esc_attr($synonym_misspellings); ?>" class="large-text"
                           placeholder="<?php esc_attr_e('e.g., withdrawl, withdaw', 'cleversay'); ?>">
                    <p class="description"><?php esc_html_e('Comma-separated common misspellings of this keyword.', 'cleversay'); ?></p>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="button button-primary" id="save-synonyms-btn">
                        <?php esc_html_e('Save Synonyms', 'cleversay'); ?>
                    </button>
                    <button type="button" class="button" id="cancel-synonyms-edit">
                        <?php esc_html_e('Cancel', 'cleversay'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Phrase Groups List -->
    <div class="phrase-groups-section">
        <div class="section-header">
            <h2>
                <?php echo \CleverSay\Icons::render('message-circle', 16); ?>
                <?php esc_html_e('Phrase Groups', 'cleversay'); ?>
            </h2>
            <a href="<?php echo esc_url(add_query_arg([
                'action' => 'new-phrase-group',
                'keyword' => urlencode($keyword)
            ], $base_url)); ?>" class="button button-primary">
                <?php echo \CleverSay\Icons::render('plus', 16); ?>
                <?php esc_html_e('Add Phrase Group', 'cleversay'); ?>
            </a>
        </div>
        
        <p class="description">
            <?php esc_html_e('Each phrase group contains patterns that share the same response. Click a group to edit its patterns and response.', 'cleversay'); ?>
        </p>

        <div class="phrase-groups-list">
            <?php foreach ($phrase_groups as $index => $group): ?>
                <div class="phrase-group-card <?php echo $group['is_default'] ? 'is-default' : ''; ?>">
                    <div class="group-header">
                        <div class="group-title">
                            <?php if ($group['is_default']): ?>
                                <span class="default-badge">
                                    <?php echo \CleverSay\Icons::render('star', 16); ?>
                                    <?php esc_html_e('Default', 'cleversay'); ?>
                                </span>
                            <?php endif; ?>
                            <span class="pattern-count">
                                <?php echo count($group['patterns']); ?>
                                <?php echo esc_html(_n('pattern', 'patterns', count($group['patterns']), 'cleversay')); ?>
                            </span>
                            <span class="status-badge status-<?php echo esc_attr($group['status']); ?>">
                                <?php echo esc_html(ucfirst($group['status'])); ?>
                            </span>
                            <?php if (!empty($group['polished'])): ?>
                                <span class="polished-badge"
                                      title="<?php esc_attr_e('AI-polished and unchanged since. Runtime polish skips this entry.', 'cleversay'); ?>">
                                    ✨ <?php esc_html_e('Polished', 'cleversay'); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="group-hits">
                            <?php echo number_format($group['total_hits']); ?> <?php esc_html_e('hits', 'cleversay'); ?>
                        </div>
                    </div>
                    
                    <div class="group-patterns">
                        <?php
                        // Show the input variations (the human-readable
                        // "what this entry handles") instead of compiled
                        // patterns. The pattern is stored separately and
                        // remains editable from the Advanced section of
                        // the edit page; it's not useful at-a-glance here.
                        // Fallback: legacy entries (pre-variations) still
                        // have $group['patterns'][n]['phrase'] (the
                        // example question column) — show those.
                        $items_to_show = !empty($group['variations'])
                            ? $group['variations']
                            : array_filter(array_column($group['patterns'], 'phrase'));
                        ?>
                        <?php foreach ($items_to_show as $item): ?>
                            <div class="variation-row">
                                <span class="variation-text"><?php echo esc_html($item); ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($items_to_show)): ?>
                            <div class="variation-row variation-empty">
                                <em><?php esc_html_e('(No variations defined yet)', 'cleversay'); ?></em>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($group['reuse_response'])): ?>
                        <div class="group-response is-reused">
                            <span class="reuse-badge">
                                <?php echo \CleverSay\Icons::render('link', 16); ?>
                                <?php esc_html_e('Linked to:', 'cleversay'); ?>
                            </span>
                            <span class="linked-keyword"><?php echo esc_html($group['reuse_keyword']); ?></span>
                            <span class="linked-separator">/</span>
                            <span class="linked-phrase"><?php echo esc_html(wp_trim_words($group['reuse_phrase'] ?: $group['reuse_sub_keyword'], 10)); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="group-actions">
                        <a href="<?php echo esc_url(add_query_arg([
                            'action' => 'edit-phrase-group',
                            'keyword' => urlencode($keyword),
                            'group_id' => $group['id']
                        ], $base_url)); ?>" class="button button-primary">
                            <?php esc_html_e('Edit Group', 'cleversay'); ?>
                        </a>
                        <?php if (!$group['is_default']): ?>
                            <button type="button" class="button button-link-delete delete-group-btn" 
                                    data-group-id="<?php echo esc_attr($group['id']); ?>"
                                    data-keyword="<?php echo esc_attr($keyword); ?>">
                                <?php esc_html_e('Delete', 'cleversay'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Danger Zone -->
    <div class="danger-zone">
        <h3><?php echo \CleverSay\Icons::render('alert-triangle', 16); ?> <?php esc_html_e('Danger Zone', 'cleversay'); ?></h3>
        <p><?php esc_html_e('Deleting this keyword will remove ALL phrase groups and patterns associated with it.', 'cleversay'); ?></p>
        <button type="button" class="button button-link-delete" id="delete-keyword-btn" 
                data-keyword="<?php echo esc_attr($keyword); ?>">
            <?php echo \CleverSay\Icons::render('trash', 16); ?>
            <?php esc_html_e('Delete Entire Keyword', 'cleversay'); ?>
        </button>
    </div>
</div>

<style>
.cleversay-keyword-detail h1 {
    display: flex;
    align-items: center;
    gap: 10px;
}

.cleversay-keyword-detail .back-link {
    text-decoration: none;
    color: #646970;
}

.cleversay-keyword-detail .back-link:hover {
    color: #2271b1;
}

.cleversay-keyword-detail .keyword-name {
    color: #2271b1;
    font-weight: 600;
}

/* Keyword Settings Card */
.keyword-settings-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    margin: 20px 0;
}

.keyword-settings-card .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    background: #f6f7f7;
    border-bottom: 1px solid #c3c4c7;
}

.keyword-settings-card .card-header h2 {
    margin: 0;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.keyword-settings-card .card-body {
    padding: 16px;
}

.keyword-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 20px;
}

.keyword-info-grid .info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.keyword-info-grid .info-item label {
    font-size: 12px;
    color: #646970;
    font-weight: 500;
}

.keyword-info-grid .info-item span {
    font-size: 16px;
    font-weight: 600;
}

.keyword-info-grid .keyword-value {
    color: #2271b1;
}

.keyword-edit-fields {
    margin-bottom: 16px;
}

.keyword-edit-fields .field-row {
    margin-bottom: 12px;
}

.keyword-edit-fields label {
    display: block;
    margin-bottom: 4px;
    font-weight: 500;
}

.form-actions {
    display: flex;
    gap: 10px;
}

/* Keyword Synonyms Card */
.keyword-synonyms-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    margin: 20px 0;
}

.keyword-synonyms-card .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    background: #f0f6fc;
    border-bottom: 1px solid #c3c4c7;
}

.keyword-synonyms-card .card-header h2 {
    margin: 0;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #2271b1;
}

.keyword-synonyms-card .card-body {
    padding: 16px;
}

.synonym-group {
    margin-bottom: 12px;
}

.synonym-group:last-child {
    margin-bottom: 0;
}

.synonym-group label {
    display: block;
    font-size: 12px;
    color: #646970;
    font-weight: 500;
    margin-bottom: 6px;
}

.synonym-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.synonym-tag {
    display: inline-block;
    background: #e7f3ff;
    color: #2271b1;
    padding: 4px 10px;
    border-radius: 3px;
    font-size: 13px;
}

.synonym-tags.misspellings .synonym-tag {
    background: #fef7e7;
    color: #996800;
}

.no-synonyms {
    color: #646970;
    font-style: italic;
    margin: 0;
}

.synonyms-edit .synonym-field {
    margin-bottom: 16px;
}

.synonyms-edit .synonym-field label {
    display: block;
    font-weight: 500;
    margin-bottom: 4px;
}

.synonyms-edit .synonym-field .description {
    margin-top: 4px;
    color: #646970;
    font-size: 12px;
}

/* Phrase Groups Section */
.phrase-groups-section {
    margin: 30px 0;
}

.phrase-groups-section .section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.phrase-groups-section .section-header h2 {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.phrase-groups-section .description {
    color: #646970;
    margin-bottom: 20px;
}

.phrase-groups-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

/* Phrase Group Card */
.phrase-group-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 16px;
    transition: box-shadow 0.2s;
}

.phrase-group-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.phrase-group-card.is-default {
    border-color: #2271b1;
    border-width: 2px;
}

.phrase-group-card .group-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.phrase-group-card .group-title {
    display: flex;
    align-items: center;
    gap: 10px;
}

.phrase-group-card .default-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #2271b1;
    color: #fff;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
}

.phrase-group-card .default-badge .dashicons {
    font-size: 12px;
    width: 12px;
    height: 12px;
}

.phrase-group-card .pattern-count {
    color: #646970;
    font-size: 13px;
}

.phrase-group-card .status-badge {
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
}

.phrase-group-card .status-badge.status-active {
    background: #d4edda;
    color: #155724;
}

.phrase-group-card .status-badge.status-inactive {
    background: #f8d7da;
    color: #721c24;
}

.phrase-group-card .status-badge.status-hold {
    background: #fff3cd;
    color: #856404;
}

.phrase-group-card .polished-badge {
    padding: 2px 8px;
    border-radius: 11px;
    font-size: 11px;
    font-weight: 600;
    background: #dff6dd;
    color: #0a6b0a;
    border: 1px solid #a3d9a5;
}

.phrase-group-card .group-hits {
    color: #646970;
    font-size: 13px;
}

.phrase-group-card .group-patterns {
    margin-bottom: 12px;
}

/* One row per Question Variation, indented and prefixed with a subtle
   marker so a multi-variation list reads as a list at a glance. */
.phrase-group-card .variation-row {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-bottom: 4px;
    font-size: 13px;
    line-height: 1.4;
    color: #1d2327;
}

.phrase-group-card .variation-row::before {
    content: "•";
    color: #646970;
    flex-shrink: 0;
    line-height: 1.4;
}

.phrase-group-card .variation-text {
    flex: 1 1 auto;
    word-break: break-word;
}

.phrase-group-card .variation-empty {
    color: #646970;
    font-size: 12px;
}

.phrase-group-card .variation-empty::before {
    content: "";
}

.phrase-group-card .linked-keyword {
    background: #2271b1;
    color: #fff;
    padding: 2px 8px;
    border-radius: 3px;
    font-weight: 500;
}

.phrase-group-card .linked-separator {
    color: #646970;
    margin: 0 4px;
}

.phrase-group-card .linked-phrase {
    color: #1d2327;
    font-style: italic;
}

.phrase-group-card .group-response {
    background: #f9f9f9;
    padding: 10px 12px;
    border-radius: 4px;
    margin-bottom: 12px;
    font-size: 13px;
}

.phrase-group-card .response-label {
    color: #646970;
    margin-right: 6px;
}

.phrase-group-card .response-preview {
    color: #1d2327;
}

.phrase-group-card .group-response.is-reused {
    background: #f0f6fc;
    border: 1px solid #2271b1;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 4px;
}
}

.phrase-group-card .reuse-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #2271b1;
    color: #fff;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
    margin-right: 8px;
}

.phrase-group-card .reuse-badge .dashicons {
    font-size: 12px;
    width: 12px;
    height: 12px;
}

.phrase-group-card .group-actions {
    display: flex;
    gap: 10px;
}

/* Danger Zone */
.danger-zone {
    margin-top: 40px;
    padding: 20px;
    background: #fef7f7;
    border: 1px solid #f0c0c0;
    border-radius: 4px;
}

.danger-zone h3 {
    color: #d63638;
    margin: 0 0 10px;
}

.danger-zone p {
    margin: 0 0 15px;
    color: #646970;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Toggle keyword edit form
    $('#edit-keyword-btn').on('click', function() {
        $('#keyword-display').hide();
        $('#keyword-edit-form').show();
        $(this).hide();
    });
    
    $('#cancel-keyword-edit').on('click', function() {
        $('#keyword-edit-form').hide();
        $('#keyword-display').show();
        $('#edit-keyword-btn').show();
    });
    
    // Delete keyword
    $('#delete-keyword-btn').on('click', function() {
        const keyword = $(this).data('keyword');
        
        if (!confirm(cleversayAdmin.strings.confirmDeleteKeyword || 'Delete this keyword and ALL its patterns? This cannot be undone.')) {
            return;
        }
        
        $.ajax({
            url: cleversayAdmin.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cleversay_delete_keyword',
                nonce: cleversayAdmin.nonce,
                keyword: keyword
            },
            success: function(response) {
                if (response.success) {
                    window.location.href = '<?php echo esc_url($base_url); ?>&message=deleted';
                } else {
                    alert(response.data?.message || 'Error deleting keyword');
                }
            },
            error: function() {
                alert('Error deleting keyword');
            }
        });
    });
    
    // Delete phrase group
    $('.delete-group-btn').on('click', function() {
        const groupId = $(this).data('group-id');
        const keyword = $(this).data('keyword');
        
        if (!confirm(cleversayAdmin.strings.confirmDeleteGroup || 'Delete this phrase group?')) {
            return;
        }
        
        $.ajax({
            url: cleversayAdmin.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cleversay_delete_phrase_group',
                nonce: cleversayAdmin.nonce,
                group_id: groupId,
                keyword: keyword
            },
            success: function(response) {
                if (response.success) {
                    window.location.reload();
                } else {
                    alert(response.data?.message || 'Error deleting group');
                }
            },
            error: function() {
                alert('Error deleting group');
            }
        });
    });
    
    // Toggle synonyms edit form
    $('#edit-synonyms-btn').on('click', function() {
        $('#synonyms-display').hide();
        $('#synonyms-edit').show();
        $(this).hide();
    });
    
    $('#cancel-synonyms-edit').on('click', function() {
        $('#synonyms-edit').hide();
        $('#synonyms-display').show();
        $('#edit-synonyms-btn').show();
    });
    
    // Save synonyms
    $('#save-synonyms-btn').on('click', function() {
        const $btn = $(this);
        const originalText = $btn.text();
        $btn.text('<?php echo esc_js(__('Saving...', 'cleversay')); ?>').prop('disabled', true);
        
        $.ajax({
            url: cleversayAdmin.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cleversay_save_keyword_synonyms',
                nonce: cleversayAdmin.nonce,
                keyword: '<?php echo esc_js($keyword); ?>',
                variants: $('#synonym_variants').val(),
                misspellings: $('#synonym_misspellings').val()
            },
            success: function(response) {
                if (response.success) {
                    window.location.reload();
                } else {
                    alert(response.data?.message || 'Error saving synonyms');
                    $btn.text(originalText).prop('disabled', false);
                }
            },
            error: function() {
                alert('Error saving synonyms');
                $btn.text(originalText).prop('disabled', false);
            }
        });
    });
});
</script>
