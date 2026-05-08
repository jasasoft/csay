<?php
/**
 * Knowledge Base - New Keyword View
 *
 * @package CleverSay
 * @since 2.0.36
 */

defined('ABSPATH') || exit;

global $wpdb;

// v4.37.39+: support prefill from the Add Question page. When admin
// arrives here from the routing flow, the URL carries the keyword and
// the canonical question they pasted.
$prefill_keyword = isset($_GET['prefill_keyword'])
    ? sanitize_text_field(wp_unslash((string) $_GET['prefill_keyword']))
    : '';
$prefill_phrase  = isset($_GET['prefill_phrase'])
    ? sanitize_textarea_field(wp_unslash((string) $_GET['prefill_phrase']))
    : '';

// Get categories

$base_url = admin_url('admin.php?page=cleversay-knowledge');
?>

<div class="wrap cleversay-admin cleversay-keyword-edit">
    <h1>
        <a href="<?php echo esc_url($base_url); ?>" class="back-link">
            <?php echo \CleverSay\Icons::render('arrow-left', 16); ?>
        </a>
        <?php echo \CleverSay\Icons::render('book-open', 26); ?>
        <?php esc_html_e('Add New Keyword', 'cleversay'); ?>
    </h1>
    
    <hr class="wp-header-end">

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="new-keyword-form">
        <input type="hidden" name="action" value="cleversay_save_keyword">
        <input type="hidden" name="is_new" value="1">
        <?php wp_nonce_field('cleversay_save_keyword', 'cleversay_nonce'); ?>

        <!-- Keyword Field -->
        <div class="keyword-input-section">
            <label for="keyword-input"><?php esc_html_e('Keyword', 'cleversay'); ?></label>
            <input type="text" 
                   id="keyword-input" 
                   name="keyword" 
                   class="regular-text" 
                   required
                   value="<?php echo esc_attr($prefill_keyword); ?>"
                   placeholder="<?php esc_attr_e('Enter the main keyword (e.g., Advisor, Admission, Parking)', 'cleversay'); ?>">
            <p class="description">
                <?php
                if ($prefill_keyword !== '') {
                    esc_html_e('Suggested from your question. You can edit it before saving.', 'cleversay');
                } else {
                    esc_html_e('The keyword is the main term users must include in their question to match this entry. Not sure which keyword to pick?', 'cleversay');
                    echo ' <a href="' . esc_url(add_query_arg('action', 'add-question', $base_url)) . '">';
                    esc_html_e('Try the Add Question flow →', 'cleversay');
                    echo '</a>';
                }
                ?>
            </p>
        </div>


        <!-- Response Groups Container -->
        <div id="response-groups-container">
            <!-- Default Response Group -->
            <div class="response-group default-group" data-group-index="0">
                <div class="group-header">
                    <h3>
                        <?php echo \CleverSay\Icons::render('star', 16); ?>
                        <?php esc_html_e('Default Response', 'cleversay'); ?>
                    </h3>
                </div>

                <!-- Patterns Section -->
                <div class="patterns-section">
                    <h4>
                        <?php echo \CleverSay\Icons::render('tag', 16); ?>
                        <?php esc_html_e('Match Patterns', 'cleversay'); ?>
                    </h4>
                    
                    <div class="patterns-list">
                        <div class="pattern-item" data-pattern-index="0">
                            <input type="hidden" name="groups[0][patterns][0][pattern]" value="aadefault">
                            
                            <div class="pattern-row">
                                <div class="pattern-field">
                                    <label><?php esc_html_e('Pattern', 'cleversay'); ?></label>
                                    <input type="text" value="aadefault" disabled class="pattern-display">
                                    <span class="pattern-note"><?php esc_html_e('Default fallback pattern', 'cleversay'); ?></span>
                                </div>
                                
                                <div class="phrase-field">
                                    <label><?php esc_html_e('Default Match Phrase', 'cleversay'); ?></label>
                                    <input type="text" 
                                           name="groups[0][patterns][0][phrase]" 
                                           value="<?php echo esc_attr($prefill_phrase); ?>"
                                           class="phrase-input regular-text"
                                           required
                                           placeholder="<?php esc_attr_e('Enter the default question (e.g., Who is my advisor?)', 'cleversay'); ?>">
                                    <span class="validation-status"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Response Section -->
                <div class="response-section">
                    <h4>
                        <?php echo \CleverSay\Icons::render('file-text', 16); ?>
                        <?php esc_html_e('Response', 'cleversay'); ?>
                    </h4>
                    
                    <?php 
                    wp_editor('', 'response_0', [
                        'textarea_name' => 'groups[0][response]',
                        'textarea_rows' => 8,
                        'media_buttons' => true,
                        'teeny' => false,
                        'quicktags' => true,
                    ]);
                    ?>

                    <?php
                    // v4.37.57+: Modernize + Polish for the new-keyword
                    // workflow. Same buttons as the phrase editor —
                    // operate on editor content (no DB row yet) and
                    // update editor in place. Polish stashes hash in
                    // hidden field so polished_hash is set on insert.
                    //
                    // Currently wired only to the first group (response_0
                    // / groups[0][response]). Multi-group polish is a
                    // future enhancement.
                    ?>
                    <div style="margin-top:10px; padding:10px 12px; background:#f6f7f7; border:1px solid #ddd; border-radius:3px;">
                        <button type="button" class="button" id="cs-nk-modernize"
                                title="<?php esc_attr_e('Strip Word/legacy HTML noise from the editor. Idempotent.', 'cleversay'); ?>">
                            <?php echo \CleverSay\Icons::render('zap', 14); ?>
                            <?php esc_html_e('Modernize HTML', 'cleversay'); ?>
                        </button>
                        <button type="button" class="button" id="cs-nk-polish"
                                style="margin-left:6px;"
                                title="<?php esc_attr_e('Use AI to improve flow and readability. Strict no-new-facts rules. Preview before applying.', 'cleversay'); ?>">
                            <?php echo \CleverSay\Icons::render('sparkles', 14); ?>
                            <?php esc_html_e('Polish Answer', 'cleversay'); ?>
                        </button>
                        <span id="cs-nk-status" style="margin-left:10px; color:#666; font-size:12px;"></span>
                    </div>

                    <!-- Polish preview (first group only). -->
                    <div id="cs-nk-polish-preview" style="display:none; margin-top:12px; padding:14px; background:white; border:2px solid #2271b1; border-radius:4px;">
                        <h3 style="margin:0 0 10px; font-size:14px;">
                            <?php echo \CleverSay\Icons::render('sparkles', 16); ?>
                            <?php esc_html_e('Polish preview', 'cleversay'); ?>
                            <span id="cs-nk-polish-provider" style="font-size:11px; color:#666; font-weight:normal; margin-left:8px;"></span>
                        </h3>
                        <p style="margin:0 0 10px; font-size:12px; color:#555;">
                            <?php esc_html_e('Review the AI\'s rewrite. Apply only if faithful to your original facts.', 'cleversay'); ?>
                        </p>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                            <div>
                                <div style="font-size:11px; font-weight:600; color:#666; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px;">
                                    <?php esc_html_e('Original', 'cleversay'); ?>
                                </div>
                                <div id="cs-nk-polish-original" style="padding:10px; background:#f6f7f7; border:1px solid #ddd; border-radius:3px; max-height:300px; overflow:auto; font-size:13px;"></div>
                            </div>
                            <div>
                                <div style="font-size:11px; font-weight:600; color:#2271b1; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px;">
                                    <?php esc_html_e('Polished', 'cleversay'); ?>
                                </div>
                                <div id="cs-nk-polish-polished" style="padding:10px; background:#f0f6fc; border:1px solid #2271b1; border-radius:3px; max-height:300px; overflow:auto; font-size:13px;"></div>
                            </div>
                        </div>
                        <div style="margin-top:12px;">
                            <button type="button" id="cs-nk-polish-apply" class="button button-primary">
                                <?php esc_html_e('Apply polished version', 'cleversay'); ?>
                            </button>
                            <button type="button" id="cs-nk-polish-cancel" class="button" style="margin-left:6px;">
                                <?php esc_html_e('Cancel', 'cleversay'); ?>
                            </button>
                        </div>
                    </div>

                    <input type="hidden" name="__pending_polished_hash" id="cs-nk-pending-hash" value="">
                </div>

                <!-- Settings Section -->
                <div class="settings-section">
                    <div class="settings-row">
                        <div class="setting-field">
                            <label><?php esc_html_e('Status', 'cleversay'); ?></label>
                        </div>
                        
                        <div class="setting-field">
                            <label>
                                <input type="checkbox" name="groups[0][show_rating]" value="1" checked>
                                <?php esc_html_e('Show Rating', 'cleversay'); ?>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <!-- Keyword Synonyms Section -->
        <div class="synonyms-section">
            <h3>
                <?php echo \CleverSay\Icons::render('refresh-cw', 16); ?>
                <?php esc_html_e('Keyword Synonyms (Optional)', 'cleversay'); ?>
            </h3>
            <p class="description">
                <?php esc_html_e('Add alternative words that users might use instead of the main keyword. These words will also match this keyword.', 'cleversay'); ?>
            </p>
            
            <div class="synonyms-fields">
                <div class="synonym-field">
                    <label for="synonym_variants"><?php esc_html_e('Synonym Words', 'cleversay'); ?></label>
                    <input type="text" id="synonym_variants" name="synonym_variants" class="large-text" 
                           placeholder="<?php esc_attr_e('e.g., withdraw, remove, cancel (comma-separated)', 'cleversay'); ?>">
                    <p class="description"><?php esc_html_e('Words that mean the same as this keyword.', 'cleversay'); ?></p>
                </div>
                
                <div class="synonym-field">
                    <label for="synonym_misspellings"><?php esc_html_e('Common Misspellings', 'cleversay'); ?></label>
                    <input type="text" id="synonym_misspellings" name="synonym_misspellings" class="large-text"
                           placeholder="<?php esc_attr_e('e.g., withdrawl, withdaw (comma-separated)', 'cleversay'); ?>">
                    <p class="description"><?php esc_html_e('Common misspellings of this keyword.', 'cleversay'); ?></p>
                </div>
            </div>
        </div>

        <!-- Save Actions -->
        <div class="submit-section">
            <button type="submit" name="save_action" value="validate_save" class="button button-primary button-large">
                <?php echo \CleverSay\Icons::render('check', 16); ?>
                <?php esc_html_e('Validate & Save', 'cleversay'); ?>
            </button>
            
            <a href="<?php echo esc_url($base_url); ?>" class="button button-secondary">
                <?php esc_html_e('Cancel', 'cleversay'); ?>
            </a>
        </div>
    </form>
</div>

<!-- Pattern Builder Modal (same as edit page) -->
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
                <?php esc_html_e('Response Group', 'cleversay'); ?>
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

<style>
.keyword-input-section {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}

.keyword-input-section label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    font-size: 14px;
}

.keyword-input-section input {
    width: 100%;
    max-width: 400px;
    font-size: 16px;
    padding: 10px 12px;
}

.keyword-input-section .description {
    margin-top: 8px;
    color: #646970;
}

/* Synonyms Section */
.synonyms-section {
    background: #f0f6fc;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    margin: 20px 0;
}

.synonyms-section h3 {
    margin: 0 0 8px;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #2271b1;
}

.synonyms-section > .description {
    margin: 0 0 16px;
    color: #646970;
}

.synonyms-fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

@media (max-width: 782px) {
    .synonyms-fields {
        grid-template-columns: 1fr;
    }
}

.synonyms-section .synonym-field {
    background: #fff;
    padding: 12px;
    border-radius: 4px;
    border: 1px solid #c3c4c7;
}

.synonyms-section .synonym-field label {
    display: block;
    font-weight: 500;
    margin-bottom: 6px;
}

.synonyms-section .synonym-field input {
    width: 100%;
}

.synonyms-section .synonym-field .description {
    margin-top: 4px;
    font-size: 12px;
    color: #646970;
}
</style>

<script>
jQuery(function($) {
    // v4.37.57+: Modernize/Polish for new-keyword form. Wired to
    // the first response group's editor (response_0). The same
    // server endpoints support response_html mode (no DB write
    // since the entry doesn't exist yet); the polish hash gets
    // stashed in the hidden field and consumed on form save.

    function getEditorContent() {
        if (typeof tinymce !== 'undefined' && tinymce.get('response_0')) {
            return tinymce.get('response_0').getContent();
        }
        return $('#response_0').val() || '';
    }
    function setEditorContent(html) {
        if (typeof tinymce !== 'undefined' && tinymce.get('response_0')) {
            tinymce.get('response_0').setContent(html);
        }
        $('#response_0').val(html);
    }
    function getVariations() {
        // Variations live as inputs under the patterns section.
        // The new-keyword form uses the same `variations[]` name
        // pattern as the phrase editor.
        return $('input[name="variations[]"], input[name^="groups[0][patterns]"][name$="[phrase]"]')
            .map(function() { return $(this).val(); }).get()
            .filter(function(v) { return v && v.trim() !== ''; });
    }

    let nkPolishPreview = null;

    $('#cs-nk-modernize').on('click', function() {
        const $btn = $(this);
        const $status = $('#cs-nk-status');
        const html = getEditorContent();
        if (!html || !html.trim()) {
            $status.text('<?php echo esc_js(__('Editor is empty.', 'cleversay')); ?>').css('color', '#666');
            return;
        }
        $btn.prop('disabled', true);
        $status.text('<?php echo esc_js(__('Cleaning…', 'cleversay')); ?>').css('color', '#666');

        $.post(ajaxurl, {
            action:        'cleversay_modernize_response',
            nonce:         cleversayAdmin && cleversayAdmin.nonce ? cleversayAdmin.nonce : '',
            response_html: html,
        }).done(function(resp) {
            $btn.prop('disabled', false);
            if (!resp || !resp.success || !resp.data) {
                $status.text('<?php echo esc_js(__('Failed.', 'cleversay')); ?>').css('color', '#d63638');
                return;
            }
            const d = resp.data;
            if (!d.changed) {
                $status.text('<?php echo esc_js(__('Already clean.', 'cleversay')); ?>').css('color', '#666');
                return;
            }
            setEditorContent(d.response);
            const saved = d.old_length - d.new_length;
            $status.html(
                '<span style="color:#00a32a;">✓ ' +
                '<?php echo esc_js(__('Cleaned —', 'cleversay')); ?> ' + saved + ' <?php echo esc_js(__('chars removed. Save to commit.', 'cleversay')); ?>' +
                '</span>'
            );
        }).fail(function() {
            $btn.prop('disabled', false);
            $status.text('<?php echo esc_js(__('Network error.', 'cleversay')); ?>').css('color', '#d63638');
        });
    });

    $('#cs-nk-polish').on('click', function() {
        const $btn = $(this);
        const $status = $('#cs-nk-status');
        const html = getEditorContent();
        if (!html || !html.trim()) {
            $status.text('<?php echo esc_js(__('Editor is empty.', 'cleversay')); ?>').css('color', '#666');
            return;
        }
        $btn.prop('disabled', true);
        $('#cs-nk-modernize').prop('disabled', true);
        $status.text('<?php echo esc_js(__('Polishing…', 'cleversay')); ?>').css('color', '#666');

        $.post(ajaxurl, {
            action:        'cleversay_polish_preview',
            nonce:         cleversayAdmin && cleversayAdmin.nonce ? cleversayAdmin.nonce : '',
            response_html: html,
            variations:    getVariations(),
        }).done(function(resp) {
            $btn.prop('disabled', false);
            $('#cs-nk-modernize').prop('disabled', false);
            if (!resp || !resp.success || !resp.data) {
                const msg = (resp && resp.data && resp.data.message) ? resp.data.message :
                            '<?php echo esc_js(__('Polish failed.', 'cleversay')); ?>';
                $status.text(msg).css('color', '#d63638');
                return;
            }
            const d = resp.data;
            if (!d.changed) {
                // v4.37.62+: stash hash so save commits the polished
                // marker — the entry is functionally polished even
                // though no rewrite was needed.
                if (d.hash) {
                    $('#cs-nk-pending-hash').val(d.hash);
                }
                $status.html(
                    '<span style="color:#00a32a;">✓ ' +
                    '<?php echo esc_js(__('Already well-written — click "Save Knowledge Entry" to mark as polished.', 'cleversay')); ?>' +
                    '</span>'
                );
                return;
            }
            nkPolishPreview = d;
            $status.text('');
            $('#cs-nk-polish-original').html(d.original);
            $('#cs-nk-polish-polished').html(d.polished);
            $('#cs-nk-polish-provider').text(d.provider ? '(' + d.provider + ')' : '');
            $('#cs-nk-polish-preview').show();
            $('html, body').animate({
                scrollTop: $('#cs-nk-polish-preview').offset().top - 60
            }, 300);
        }).fail(function() {
            $btn.prop('disabled', false);
            $('#cs-nk-modernize').prop('disabled', false);
            $status.text('<?php echo esc_js(__('Network error.', 'cleversay')); ?>').css('color', '#d63638');
        });
    });

    $('#cs-nk-polish-cancel').on('click', function() {
        $('#cs-nk-polish-preview').hide();
        nkPolishPreview = null;
    });

    $('#cs-nk-polish-apply').on('click', function() {
        if (!nkPolishPreview) return;
        const $btn = $(this);
        const $status = $('#cs-nk-status');
        $btn.prop('disabled', true);
        $status.text('<?php echo esc_js(__('Applying…', 'cleversay')); ?>').css('color', '#666');

        $.post(ajaxurl, {
            action:   'cleversay_polish_apply',
            nonce:    cleversayAdmin && cleversayAdmin.nonce ? cleversayAdmin.nonce : '',
            entry_id: 0,  // new entry — no DB write, just hash returned
            polished: nkPolishPreview.polished,
        }).done(function(resp) {
            $btn.prop('disabled', false);
            if (!resp || !resp.success || !resp.data) {
                $status.text('<?php echo esc_js(__('Apply failed.', 'cleversay')); ?>').css('color', '#d63638');
                return;
            }
            setEditorContent(nkPolishPreview.polished);
            if (resp.data.hash) {
                $('#cs-nk-pending-hash').val(resp.data.hash);
            }
            $('#cs-nk-polish-preview').hide();
            $status.html(
                '<span style="color:#00a32a;">✓ ' +
                '<?php echo esc_js(__('Polish applied. Save to commit (polished_hash will be stored).', 'cleversay')); ?>' +
                '</span>'
            );
            nkPolishPreview = null;
        }).fail(function() {
            $btn.prop('disabled', false);
            $status.text('<?php echo esc_js(__('Network error.', 'cleversay')); ?>').css('color', '#d63638');
        });
    });

    // Invalidate the pending hash if admin edits the editor after Apply.
    $('#response_0').on('input change', function() {
        $('#cs-nk-pending-hash').val('');
    });
});
</script>
