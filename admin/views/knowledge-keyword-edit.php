<?php
/**
 * Knowledge Base - Edit Keyword View
 * 
 * Shows all response groups for a keyword with their patterns
 *
 * @package CleverSay
 * @since 2.0.36
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
     ORDER BY CASE WHEN sub_keyword = 'aadefault' THEN 0 ELSE 1 END, sub_keyword ASC",
    $keyword
), ARRAY_A);

if (empty($entries)) {
    wp_die(__('Keyword not found', 'cleversay'));
}

// Group entries by response (entries with same response are grouped)
$response_groups = [];
foreach ($entries as $entry) {
    $response_hash = md5($entry['response']);
    if (!isset($response_groups[$response_hash])) {
        $response_groups[$response_hash] = [
            'response' => $entry['response'],
            'status' => $entry['status'],
            'expires_at' => $entry['expires_at'],
            'show_rating' => $entry['show_rating'],
            'patterns' => [],
        ];
    }
    $response_groups[$response_hash]['patterns'][] = [
        'id' => $entry['id'],
        'pattern' => $entry['sub_keyword'],
        'phrase' => $entry['question'],
    ];
}

// Reindex groups
$response_groups = array_values($response_groups);

// Make sure aadefault group is first
usort($response_groups, function($a, $b) {
    $a_has_default = false;
    $b_has_default = false;
    foreach ($a['patterns'] as $p) {
        if ($p['pattern'] === 'aadefault') $a_has_default = true;
    }
    foreach ($b['patterns'] as $p) {
        if ($p['pattern'] === 'aadefault') $b_has_default = true;
    }
    if ($a_has_default && !$b_has_default) return -1;
    if (!$a_has_default && $b_has_default) return 1;
    return 0;
});

// Get categories

$base_url = admin_url('admin.php?page=cleversay-knowledge');
?>

<div class="wrap cleversay-admin cleversay-keyword-edit">
    <h1>
        <a href="<?php echo esc_url($base_url); ?>" class="back-link">
            <?php echo \CleverSay\Icons::render('arrow-left', 16); ?>
        </a>
        <?php echo \CleverSay\Icons::render('edit', 26); ?>
        <?php printf(esc_html__('Edit Keyword: %s', 'cleversay'), '<span class="keyword-name">' . esc_html($keyword) . '</span>'); ?>
    </h1>
    
    <hr class="wp-header-end">

    <?php if (isset($_GET['message'])): ?>
        <div class="notice notice-<?php echo $_GET['message'] === 'validation_failed' ? 'error' : 'success'; ?> is-dismissible">
            <p>
                <?php
                $messages = [
                    'saved' => __('All changes saved successfully.', 'cleversay'),
                    'validation_failed' => __('Validation failed. Please check the errors below.', 'cleversay'),
                ];
                echo esc_html($messages[$_GET['message']] ?? '');
                ?>
            </p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="keyword-edit-form">
        <input type="hidden" name="action" value="cleversay_save_keyword">
        <input type="hidden" name="keyword" value="<?php echo esc_attr($keyword); ?>">
        <?php wp_nonce_field('cleversay_save_keyword', 'cleversay_nonce'); ?>

        <!-- Response Groups Container -->
        <div id="response-groups-container">
            <?php foreach ($response_groups as $group_index => $group): ?>
                <?php 
                $is_default = false;
                foreach ($group['patterns'] as $p) {
                    if ($p['pattern'] === 'aadefault') $is_default = true;
                }
                ?>
                <div class="response-group <?php echo $is_default ? 'default-group' : ''; ?>" data-group-index="<?php echo $group_index; ?>">
                    <div class="group-header">
                        <h3>
                            <?php if ($is_default): ?>
                                <?php echo \CleverSay\Icons::render('star', 16); ?>
                                <?php esc_html_e('Default Response', 'cleversay'); ?>
                            <?php else: ?>
                                <?php printf(esc_html__('Response Group %d', 'cleversay'), $group_index + 1); ?>
                            <?php endif; ?>
                        </h3>
                        <?php if (!$is_default): ?>
                            <button type="button" class="button-link delete-group" title="<?php esc_attr_e('Delete this response group', 'cleversay'); ?>">
                                <?php echo \CleverSay\Icons::render('trash', 16); ?>
                            </button>
                        <?php endif; ?>
                    </div>

                    <!-- Patterns Section -->
                    <div class="patterns-section">
                        <h4>
                            <?php echo \CleverSay\Icons::render('tag', 16); ?>
                            <?php esc_html_e('Match Patterns', 'cleversay'); ?>
                        </h4>
                        
                        <div class="patterns-list">
                            <?php foreach ($group['patterns'] as $pattern_index => $pattern): ?>
                                <div class="pattern-item" data-pattern-index="<?php echo $pattern_index; ?>">
                                    <input type="hidden" name="groups[<?php echo $group_index; ?>][patterns][<?php echo $pattern_index; ?>][id]" 
                                           value="<?php echo esc_attr($pattern['id']); ?>">
                                    
                                    <div class="pattern-row">
                                        <div class="pattern-field">
                                            <label><?php esc_html_e('Pattern', 'cleversay'); ?></label>
                                            <?php if ($pattern['pattern'] === 'aadefault'): ?>
                                                <input type="text" value="aadefault" disabled class="pattern-display">
                                                <input type="hidden" name="groups[<?php echo $group_index; ?>][patterns][<?php echo $pattern_index; ?>][pattern]" value="aadefault">
                                                <span class="pattern-note"><?php esc_html_e('Default fallback pattern', 'cleversay'); ?></span>
                                            <?php else: ?>
                                                <div class="pattern-builder" data-group="<?php echo $group_index; ?>" data-pattern="<?php echo $pattern_index; ?>">
                                                    <input type="hidden" 
                                                           name="groups[<?php echo $group_index; ?>][patterns][<?php echo $pattern_index; ?>][pattern]" 
                                                           value="<?php echo esc_attr($pattern['pattern']); ?>"
                                                           class="pattern-value">
                                                    <div class="pattern-builder-ui">
                                                        <!-- Will be populated by JS -->
                                                    </div>
                                                    <button type="button" class="button button-small edit-pattern-btn">
                                                        <?php echo \CleverSay\Icons::render('edit', 16); ?>
                                                        <?php esc_html_e('Edit Pattern', 'cleversay'); ?>
                                                    </button>
                                                    <span class="pattern-preview"><?php echo esc_html($pattern['pattern']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="phrase-field">
                                            <label><?php esc_html_e('Match Phrase', 'cleversay'); ?></label>
                                            <input type="text" 
                                                   name="groups[<?php echo $group_index; ?>][patterns][<?php echo $pattern_index; ?>][phrase]" 
                                                   value="<?php echo esc_attr($pattern['phrase']); ?>"
                                                   class="phrase-input regular-text"
                                                   placeholder="<?php esc_attr_e('Enter a phrase that should match this pattern', 'cleversay'); ?>">
                                            <span class="validation-status"></span>
                                        </div>
                                        
                                        <?php if ($pattern['pattern'] !== 'aadefault'): ?>
                                            <button type="button" class="button-link delete-pattern" title="<?php esc_attr_e('Delete pattern', 'cleversay'); ?>">
                                                <?php echo \CleverSay\Icons::render('x-circle', 16); ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="button" class="button add-pattern-btn">
                            <?php echo \CleverSay\Icons::render('plus', 16); ?>
                            <?php esc_html_e('Add Pattern', 'cleversay'); ?>
                        </button>
                    </div>

                    <!-- Response Section -->
                    <div class="response-section">
                        <h4>
                            <?php echo \CleverSay\Icons::render('file-text', 16); ?>
                            <?php esc_html_e('Response', 'cleversay'); ?>
                        </h4>
                        
                        <?php 
                        $editor_id = 'response_' . $group_index;
                        wp_editor($group['response'], $editor_id, [
                            'textarea_name' => "groups[{$group_index}][response]",
                            'textarea_rows' => 8,
                            'media_buttons' => true,
                            'teeny' => false,
                            'quicktags' => true,
                        ]);
                        ?>
                    </div>

                    <!-- Settings Section -->
                    <div class="settings-section">
                        <div class="settings-row">
                            <div class="setting-field">
                                <label><?php esc_html_e('Status', 'cleversay'); ?></label>
                            </div>
                            
                            <div class="setting-field">
                                <label>
                                    <input type="checkbox" 
                                           name="groups[<?php echo $group_index; ?>][show_rating]" 
                                           value="1" 
                                           <?php checked($group['show_rating'], 1); ?>>
                                    <?php esc_html_e('Show Rating', 'cleversay'); ?>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Add Response Group Button -->
        <button type="button" class="button button-secondary" id="add-response-group">
            <?php echo \CleverSay\Icons::render('plus', 16); ?>
            <?php esc_html_e('Add Response Group', 'cleversay'); ?>
        </button>

        <!-- Save Actions -->
        <div class="submit-section">
            <button type="submit" name="save_action" value="validate_save" class="button button-primary button-large">
                <?php echo \CleverSay\Icons::render('check', 16); ?>
                <?php esc_html_e('Validate & Save', 'cleversay'); ?>
            </button>
            
            <a href="<?php echo esc_url($base_url); ?>" class="button button-secondary">
                <?php esc_html_e('Cancel', 'cleversay'); ?>
            </a>
            
            <button type="button" class="button button-link-delete" id="delete-keyword">
                <?php esc_html_e('Delete Keyword', 'cleversay'); ?>
            </button>
        </div>
    </form>
</div>

<!-- Pattern Builder Modal -->
<div id="pattern-builder-modal" class="cleversay-modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2><?php esc_html_e('Build Match Pattern', 'cleversay'); ?></h2>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <p class="description">
                <?php esc_html_e('Build your pattern using groups. Words within a group use AND logic (all must match). Groups are combined with OR logic (any group can match).', 'cleversay'); ?>
            </p>
            
            <div id="pattern-groups-builder">
                <!-- Groups will be added here dynamically -->
            </div>
            
            <button type="button" class="button" id="add-or-group">
                <?php echo \CleverSay\Icons::render('plus', 16); ?>
                <?php esc_html_e('Add OR Group', 'cleversay'); ?>
            </button>
            
            <div class="pattern-preview-box">
                <label><?php esc_html_e('Generated Pattern:', 'cleversay'); ?></label>
                <code id="generated-pattern"></code>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="button button-primary" id="apply-pattern">
                <?php esc_html_e('Apply Pattern', 'cleversay'); ?>
            </button>
            <button type="button" class="button modal-cancel">
                <?php esc_html_e('Cancel', 'cleversay'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Templates -->
<script type="text/template" id="pattern-group-template">
    <div class="pattern-builder-group" data-group-num="{groupNum}">
        <div class="group-label">
            <span class="group-title"><?php esc_html_e('Group', 'cleversay'); ?> {groupNum}</span>
            <button type="button" class="button-link remove-group-btn" title="<?php esc_attr_e('Remove group', 'cleversay'); ?>">
                <?php echo \CleverSay\Icons::render('x-circle', 16); ?>
            </button>
        </div>
        <div class="group-words">
            <!-- Words will be added here -->
        </div>
        <button type="button" class="button button-small add-word-btn">
            <?php echo \CleverSay\Icons::render('plus', 16); ?>
            <?php esc_html_e('Add Word (AND)', 'cleversay'); ?>
        </button>
        <div class="or-separator"><?php esc_html_e('— OR —', 'cleversay'); ?></div>
    </div>
</script>

<script type="text/template" id="pattern-word-template">
    <div class="pattern-word-item">
        <input type="text" class="word-input" placeholder="<?php esc_attr_e('Enter word', 'cleversay'); ?>" value="{word}">
        <select class="word-type">
            <option value="exact" {exactSelected}><?php esc_html_e('Exact Match', 'cleversay'); ?></option>
            <option value="prefix" {prefixSelected}><?php esc_html_e('Starts With (word*)', 'cleversay'); ?></option>
            <option value="suffix" {suffixSelected}><?php esc_html_e('Ends With (*word)', 'cleversay'); ?></option>
            <option value="contains" {containsSelected}><?php esc_html_e('Contains (*word*)', 'cleversay'); ?></option>
        </select>
        <button type="button" class="button-link remove-word-btn">
            <?php echo \CleverSay\Icons::render('x-circle', 16); ?>
        </button>
        <span class="and-connector"><?php esc_html_e('AND', 'cleversay'); ?></span>
    </div>
</script>

<script type="text/template" id="new-pattern-template">
    <div class="pattern-item" data-pattern-index="{patternIndex}">
        <div class="pattern-row">
            <div class="pattern-field">
                <label><?php esc_html_e('Pattern', 'cleversay'); ?></label>
                <div class="pattern-builder" data-group="{groupIndex}" data-pattern="{patternIndex}">
                    <input type="hidden" 
                           name="groups[{groupIndex}][patterns][{patternIndex}][pattern]" 
                           value=""
                           class="pattern-value">
                    <button type="button" class="button button-small edit-pattern-btn">
                        <?php echo \CleverSay\Icons::render('edit', 16); ?>
                        <?php esc_html_e('Build Pattern', 'cleversay'); ?>
                    </button>
                    <span class="pattern-preview"><?php esc_html_e('(not set)', 'cleversay'); ?></span>
                </div>
            </div>
            
            <div class="phrase-field">
                <label><?php esc_html_e('Match Phrase', 'cleversay'); ?></label>
                <input type="text" 
                       name="groups[{groupIndex}][patterns][{patternIndex}][phrase]" 
                       value=""
                       class="phrase-input regular-text"
                       placeholder="<?php esc_attr_e('Enter a phrase that should match this pattern', 'cleversay'); ?>">
                <span class="validation-status"></span>
            </div>
            
            <button type="button" class="button-link delete-pattern" title="<?php esc_attr_e('Delete pattern', 'cleversay'); ?>">
                <?php echo \CleverSay\Icons::render('x-circle', 16); ?>
            </button>
        </div>
    </div>
</script>

<script type="text/template" id="new-response-group-template">
    <div class="response-group" data-group-index="{groupIndex}">
        <div class="group-header">
            <h3>
                <?php esc_html_e('New Response Group', 'cleversay'); ?>
            </h3>
            <button type="button" class="button-link delete-group" title="<?php esc_attr_e('Delete this response group', 'cleversay'); ?>">
                <?php echo \CleverSay\Icons::render('trash', 16); ?>
            </button>
        </div>

        <div class="patterns-section">
            <h4>
                <?php echo \CleverSay\Icons::render('tag', 16); ?>
                <?php esc_html_e('Match Patterns', 'cleversay'); ?>
            </h4>
            
            <div class="patterns-list">
                <!-- Patterns will be added here -->
            </div>

            <button type="button" class="button add-pattern-btn">
                <?php echo \CleverSay\Icons::render('plus', 16); ?>
                <?php esc_html_e('Add Pattern', 'cleversay'); ?>
            </button>
        </div>

        <div class="response-section">
            <h4>
                <?php echo \CleverSay\Icons::render('file-text', 16); ?>
                <?php esc_html_e('Response', 'cleversay'); ?>
            </h4>
            
            <textarea name="groups[{groupIndex}][response]" rows="8" class="large-text" placeholder="<?php esc_attr_e('Enter the response for this group...', 'cleversay'); ?>"></textarea>
        </div>

        <div class="settings-section">
            <div class="settings-row">
                <div class="setting-field">
                    <label><?php esc_html_e('Status', 'cleversay'); ?></label>
                </div>
                
                <div class="setting-field">
                    <label>
                        <input type="checkbox" name="groups[{groupIndex}][show_rating]" value="1" checked>
                        <?php esc_html_e('Show Rating', 'cleversay'); ?>
                    </label>
                </div>
            </div>
        </div>
    </div>
</script>
