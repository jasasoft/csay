<?php
/**
 * Knowledge Entry Form View
 *
 * @package CleverSay
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

global $wpdb;

$entry_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
$is_edit = $entry_id > 0;

// Get entry data if editing
$entry = null;
if ($is_edit) {
    $entry = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}cleversay_knowledge WHERE id = %d",
        $entry_id
    ));
    
    if (!$entry) {
        wp_die(__('Entry not found.', 'cleversay'));
    }
}

// Get categories

// Default values
$defaults = [
    'keyword' => '',
    'sub_keyword' => '',
    'question' => '',
    'response' => '',
    'status' => 'active',
    'search_type' => 'keyword',
    'show_rating' => 1,
    'reuse_response' => 0,
    'expires_at' => '',
];

$data = $entry ? (array) $entry : $defaults;
?>

<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline"><?php echo \CleverSay\Icons::render('edit', 16); ?>
        <?php echo $is_edit 
            ? esc_html__('Edit Knowledge Entry', 'cleversay') 
            : esc_html__('Add Knowledge Entry', 'cleversay'); 
        ?>
    </h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-knowledge')); ?>" class="page-title-action">
        <?php esc_html_e('← Back to List', 'cleversay'); ?>
    </a>
    
    <hr class="wp-header-end">

    <?php if (isset($_GET['message']) && $_GET['message'] === 'saved'): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Entry saved successfully.', 'cleversay'); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" id="cleversay-entry-form" class="cleversay-form">
        <?php wp_nonce_field('cleversay_save_entry', 'cleversay_nonce'); ?>
        <input type="hidden" name="action" value="cleversay_save_entry">
        <input type="hidden" name="entry_id" value="<?php echo esc_attr($entry_id); ?>">

        <div class="cleversay-form-layout">
            <!-- Main Content -->
            <div class="cleversay-form-main">
                <div class="cleversay-form-section">
                    <h2><?php esc_html_e('Keywords', 'cleversay'); ?></h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="keyword"><?php esc_html_e('Primary Keyword', 'cleversay'); ?> <span class="required">*</span></label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="keyword" 
                                       name="keyword" 
                                       value="<?php echo esc_attr(wp_unslash($data['keyword'])); ?>" 
                                       class="regular-text"
                                       required>
                                <p class="description">
                                    <?php esc_html_e('Main keyword for matching. Use * for wildcards (e.g., *help* matches "can you help me").', 'cleversay'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="sub_keyword"><?php esc_html_e('Sub-keyword', 'cleversay'); ?></label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="sub_keyword" 
                                       name="sub_keyword" 
                                       value="<?php echo esc_attr(wp_unslash($data['sub_keyword'] ?? '')); ?>" 
                                       class="regular-text">
                                <p class="description">
                                    <?php esc_html_e('Secondary keyword for more specific matching.', 'cleversay'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="question"><?php esc_html_e('Required Phrase', 'cleversay'); ?></label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="question" 
                                       name="question" 
                                       value="<?php echo esc_attr(wp_unslash($data['question'] ?? '')); ?>" 
                                       class="regular-text">
                                <p class="description">
                                    <?php esc_html_e('Question must contain this phrase to match (optional).', 'cleversay'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="cleversay-form-section">
                    <h2><?php esc_html_e('Response', 'cleversay'); ?></h2>
                    
                    <div class="response-editor-wrapper">
                        <?php
                        wp_editor(wp_unslash($data['response']), 'response', [
                            'textarea_name' => 'response',
                            'textarea_rows' => 12,
                            'media_buttons' => true,
                            'teeny' => false,
                            'quicktags' => true,
                            'tinymce' => [
                                'toolbar1' => 'formatselect,bold,italic,underline,|,bullist,numlist,|,link,unlink,|,undo,redo',
                                'toolbar2' => '',
                            ],
                        ]);
                        ?>
                        <p class="description">
                            <?php esc_html_e('The response shown when this keyword matches. HTML and links are allowed.', 'cleversay'); ?>
                        </p>
                    </div>
                </div>

                <?php if ($is_edit): ?>
                <div class="cleversay-form-section">
                    <h2><?php esc_html_e('Statistics', 'cleversay'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('Hits', 'cleversay'); ?></th>
                            <td><strong><?php echo esc_html(number_format_i18n($entry->hits ?? 0)); ?></strong></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Feedback', 'cleversay'); ?></th>
                            <td>
                                <?php
                                $helpful_yes = intval($entry->helpful_yes ?? 0);
                                $helpful_no = intval($entry->helpful_no ?? 0);
                                $total_feedback = $helpful_yes + $helpful_no;
                                if ($total_feedback > 0) {
                                    $percentage = round(($helpful_yes / $total_feedback) * 100);
                                    echo \CleverSay\Icons::render('thumbs-up', 16) . ' ' . esc_html(number_format_i18n($helpful_yes));
                                    echo ' &nbsp; ';
                                    echo \CleverSay\Icons::render('thumbs-down', 16) . ' ' . esc_html(number_format_i18n($helpful_no));
                                    echo ' &nbsp; <em>(' . esc_html($percentage) . '% ' . esc_html__('helpful', 'cleversay') . ')</em>';
                                } else {
                                    esc_html_e('No feedback yet', 'cleversay');
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Created', 'cleversay'); ?></th>
                            <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->created_at))); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Last Updated', 'cleversay'); ?></th>
                            <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->updated_at))); ?></td>
                        </tr>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="cleversay-form-sidebar">
                <!-- Publish Box -->
                <div class="cleversay-metabox">
                    <h3><?php echo \CleverSay\Icons::render('send', 16); ?> <?php esc_html_e('Publish', 'cleversay'); ?></h3>
                    <div class="cleversay-metabox-content">
                        <div class="field-group">
                            <label for="status"><?php esc_html_e('Status', 'cleversay'); ?></label>
                            <select id="status" name="status">
                                <option value="active" <?php selected($data['status'], 'active'); ?>>
                                    <?php esc_html_e('Active', 'cleversay'); ?>
                                </option>
                                <option value="inactive" <?php selected($data['status'], 'inactive'); ?>>
                                    <?php esc_html_e('Inactive', 'cleversay'); ?>
                                </option>
                                <option value="pending" <?php selected($data['status'], 'pending'); ?>>
                                    <?php esc_html_e('Pending Review', 'cleversay'); ?>
                                </option>
                            </select>
                        </div>

                        <div class="field-group">
                            <label for="expires_at"><?php esc_html_e('Expiration Date', 'cleversay'); ?></label>
                            <input type="date" 
                                   id="expires_at" 
                                   name="expires_at" 
                                   value="<?php echo $data['expires_at'] ? esc_attr(date('Y-m-d', strtotime($data['expires_at']))) : ''; ?>">
                            <p class="description">
                                <?php esc_html_e('Leave empty for no expiration.', 'cleversay'); ?>
                            </p>
                        </div>

                        <div class="publish-actions">
                            <?php if ($is_edit): ?>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=cleversay-knowledge&action=delete&id=' . $entry_id), 'delete_entry_' . $entry_id)); ?>" 
                                   class="button button-link-delete"
                                   onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this entry?', 'cleversay'); ?>');">
                                    <?php esc_html_e('Delete', 'cleversay'); ?>
                                </a>
                            <?php endif; ?>
                            <button type="submit" class="button button-primary button-large">
                                <?php echo $is_edit 
                                    ? esc_html__('Update Entry', 'cleversay') 
                                    : esc_html__('Add Entry', 'cleversay'); 
                                ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Category Box -->
                <div class="cleversay-metabox">
                    <h3><?php echo \CleverSay\Icons::render('tag', 16); ?> <?php esc_html_e('Category', 'cleversay'); ?></h3>
                    <div class="cleversay-metabox-content">
                        <p class="description">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-categories')); ?>">
                                <?php esc_html_e('Manage Categories', 'cleversay'); ?>
                            </a>
                        </p>
                    </div>
                </div>

                <!-- Search Settings -->
                <div class="cleversay-metabox">
                    <h3><?php echo \CleverSay\Icons::render('search', 16); ?> <?php esc_html_e('Search Settings', 'cleversay'); ?></h3>
                    <div class="cleversay-metabox-content">
                        <div class="field-group">
                            <label for="search_type"><?php esc_html_e('Match Type', 'cleversay'); ?></label>
                            <select id="search_type" name="search_type">
                                <option value="exact" <?php selected($data['search_type'], 'exact'); ?>>
                                    <?php esc_html_e('Exact Match', 'cleversay'); ?>
                                </option>
                                <option value="partial" <?php selected($data['search_type'], 'partial'); ?>>
                                    <?php esc_html_e('Partial Match', 'cleversay'); ?>
                                </option>
                                <option value="fuzzy" <?php selected($data['search_type'], 'fuzzy'); ?>>
                                    <?php esc_html_e('Fuzzy Match', 'cleversay'); ?>
                                </option>
                            </select>
                        </div>

                        <div class="field-group">
                            <label>
                                <input type="checkbox" 
                                       name="reuse_response" 
                                       value="1" 
                                       <?php checked($data['reuse_response'] ?? 0, 1); ?>>
                                <?php esc_html_e('Allow reuse in conversation', 'cleversay'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('If unchecked, this response will only show once per session.', 'cleversay'); ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Link Validator -->
                <div class="cleversay-metabox">
                    <h3><?php echo \CleverSay\Icons::render('link', 16); ?> <?php esc_html_e('Link Validator', 'cleversay'); ?></h3>
                    <div class="cleversay-metabox-content">
                        <button type="button" id="validate-links" class="button">
                            <?php echo \CleverSay\Icons::render('link', 16); ?>
                            <?php esc_html_e('Check Links', 'cleversay'); ?>
                        </button>
                        <div id="link-validation-results"></div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<style>
.cleversay-form-layout {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 20px;
    margin-top: 20px;
}

.cleversay-form-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}

.cleversay-form-section h2 {
    margin: 0 0 15px;
    padding: 0 0 10px;
    border-bottom: 1px solid #eee;
    font-size: 16px;
}

.cleversay-metabox {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    margin-bottom: 15px;
}

.cleversay-metabox h3 {
    margin: 0;
    padding: 10px 15px;
    border-bottom: 1px solid #ccd0d4;
    background: #f9f9f9;
    font-size: 14px;
}

.cleversay-metabox-content {
    padding: 15px;
}

.cleversay-metabox .field-group {
    margin-bottom: 15px;
}

.cleversay-metabox .field-group:last-child {
    margin-bottom: 0;
}

.cleversay-metabox label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
}

.cleversay-metabox select.full-width,
.cleversay-metabox input[type="date"] {
    width: 100%;
}

.publish-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 15px;
    border-top: 1px solid #eee;
    margin-top: 15px;
}

.required {
    color: #dc3545;
}

.response-editor-wrapper {
    margin-top: 10px;
}

#link-validation-results {
    margin-top: 10px;
}

#link-validation-results .link-valid {
    color: #28a745;
}

#link-validation-results .link-invalid {
    color: #dc3545;
}

@media screen and (max-width: 782px) {
    .cleversay-form-layout {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Link validator
    $('#validate-links').on('click', function() {
        var $btn = $(this);
        var $results = $('#link-validation-results');
        var content = '';
        
        // Get content from TinyMCE or textarea
        if (typeof tinymce !== 'undefined' && tinymce.get('response')) {
            content = tinymce.get('response').getContent();
        } else {
            content = $('#response').val();
        }
        
        // Extract URLs
        var urlRegex = /href=["']([^"']+)["']/gi;
        var urls = [];
        var match;
        
        while ((match = urlRegex.exec(content)) !== null) {
            urls.push(match[1]);
        }
        
        if (urls.length === 0) {
            $results.html('<p><?php esc_html_e('No links found in response.', 'cleversay'); ?></p>');
            return;
        }
        
        $btn.prop('disabled', true);
        $results.html('<p><?php esc_html_e('Checking links...', 'cleversay'); ?></p>');
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'cleversay_validate_links',
                nonce: '<?php echo wp_create_nonce('cleversay_validate_links'); ?>',
                urls: urls
            },
            success: function(response) {
                if (response.success) {
                    var html = '<ul>';
                    response.data.forEach(function(item) {
                        var statusClass = item.valid ? 'link-valid' : 'link-invalid';
                        var statusIcon = item.valid ? '✓' : '✗';
                        html += '<li class="' + statusClass + '">' + statusIcon + ' ' + item.url + '</li>';
                    });
                    html += '</ul>';
                    $results.html(html);
                }
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });
});
</script>
