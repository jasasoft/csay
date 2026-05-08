<?php
/**
 * Inquiries Admin View
 *
 * @package CleverSay
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$table = $wpdb->prefix . 'cleversay_inquiries';

// Handle actions
$action = sanitize_text_field($_GET['action'] ?? '');
$inquiry_id = intval($_GET['inquiry_id'] ?? 0);

// Note: Delete and bulk actions are handled by Admin::handle_inquiry_actions() in admin_init

if ($action === 'respond' && $inquiry_id && check_admin_referer('respond_inquiry_' . $inquiry_id)) {
    // Show response form
    $inquiry = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $inquiry_id), ARRAY_A);
    if ($inquiry):
?>
<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline"><?php echo \CleverSay\Icons::render('mail', 16); ?> <?php esc_html_e('Respond to Inquiry', 'cleversay'); ?></h1>
    
    <div class="cleversay-inquiry-detail">
        <div class="inquiry-question">
            <h3><?php echo \CleverSay\Icons::render('help-circle', 16); ?> <?php esc_html_e('Question:', 'cleversay'); ?></h3>
            <blockquote><?php echo esc_html($inquiry['question']); ?></blockquote>
            
            <?php if (!empty($inquiry['details'])): ?>
            <h4><?php esc_html_e('Additional Details:', 'cleversay'); ?></h4>
            <blockquote class="inquiry-details"><?php echo nl2br(esc_html($inquiry['details'])); ?></blockquote>
            <?php endif; ?>
            
            <p class="inquiry-meta">
                <?php if (!empty($inquiry['name'])): ?>
                    <strong><?php esc_html_e('From:', 'cleversay'); ?></strong> <?php echo esc_html($inquiry['name']); ?><br>
                <?php endif; ?>
                <?php if (!empty($inquiry['email'])): ?>
                    <strong><?php esc_html_e('Email:', 'cleversay'); ?></strong> 
                    <a href="mailto:<?php echo esc_attr($inquiry['email']); ?>"><?php echo esc_html($inquiry['email']); ?></a><br>
                <?php endif; ?>
                <strong><?php esc_html_e('Received:', 'cleversay'); ?></strong> <?php echo esc_html($inquiry['created_at']); ?>
            </p>
        </div>
        
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="inquiry-response-form">
            <input type="hidden" name="action" value="cleversay_respond_inquiry">
            <input type="hidden" name="inquiry_id" value="<?php echo esc_attr($inquiry_id); ?>">
            <?php wp_nonce_field('cleversay_respond_inquiry', 'cleversay_nonce'); ?>
            
            <h3><?php echo \CleverSay\Icons::render('message-square', 16); ?> <?php esc_html_e('Your Response:', 'cleversay'); ?></h3>
            
            <p>
                <textarea name="response" rows="8" class="large-text" required
                          placeholder="<?php esc_attr_e('Type your response here...', 'cleversay'); ?>"></textarea>
            </p>
            
            <p class="submit">
                <?php if (!empty($inquiry['email'])): ?>
                    <button type="submit" name="send_email" value="1" class="button button-primary">
                        <?php esc_html_e('Send Response via Email', 'cleversay'); ?>
                    </button>
                <?php endif; ?>
                <button type="submit" class="button">
                    <?php esc_html_e('Save Response (No Email)', 'cleversay'); ?>
                </button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-inquiries')); ?>" class="button">
                    <?php esc_html_e('Cancel', 'cleversay'); ?>
                </a>
            </p>
        </form>
    </div>
</div>

<style>
.cleversay-inquiry-detail {
    max-width: 800px;
    background: #fff;
    padding: 25px;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    margin-top: 20px;
}

.inquiry-question blockquote {
    background: #f6f7f7;
    border-left: 4px solid #2271b1;
    margin: 10px 0 20px;
    padding: 15px 20px;
    font-style: italic;
}

.inquiry-meta {
    color: #646970;
    font-size: 13px;
}

.inquiry-response-form textarea {
    font-size: 14px;
}
</style>

<?php
    return;
    endif;
}

// Note: Bulk actions are handled by Admin::handle_inquiry_actions() in admin_init

// Handle filters
$filter_status = sanitize_text_field($_GET['status'] ?? '');
$search = sanitize_text_field($_GET['s'] ?? '');
$paged = max(1, intval($_GET['paged'] ?? 1));
$per_page = 25;

// Build query
$where = ['1=1'];
$values = [];

if (!empty($filter_status)) {
    $where[] = 'status = %s';
    $values[] = $filter_status;
}

if (!empty($search)) {
    $where[] = '(question LIKE %s OR email LIKE %s OR name LIKE %s)';
    $like = '%' . $wpdb->esc_like($search) . '%';
    $values[] = $like;
    $values[] = $like;
    $values[] = $like;
}

$where_sql = implode(' AND ', $where);

// Get total count
$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
$total = $values ? $wpdb->get_var($wpdb->prepare($count_sql, $values)) : $wpdb->get_var($count_sql);
$total_pages = ceil($total / $per_page);

// Get inquiries
$offset = ($paged - 1) * $per_page;
$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY 
        CASE WHEN status = 'pending' THEN 0 ELSE 1 END,
        created_at DESC 
        LIMIT %d OFFSET %d";
$values[] = $per_page;
$values[] = $offset;
$inquiries = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);

// Get counts by status
$status_counts = $wpdb->get_results(
    "SELECT status, COUNT(*) as cnt FROM {$table} GROUP BY status",
    OBJECT_K
);
?>

<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline"><?php echo \CleverSay\Icons::render('mail', 16); ?> <?php esc_html_e('Inquiries', 'cleversay'); ?></h1>
    <hr class="wp-header-end">
    
    <?php if (isset($_GET['updated'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Inquiries updated successfully.', 'cleversay'); ?></p>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['deleted'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Inquiry deleted successfully.', 'cleversay'); ?></p>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['message']) && $_GET['message'] === 'responded'): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Response sent successfully!', 'cleversay'); ?></p>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['error'])): ?>
        <div class="notice notice-error is-dismissible">
            <?php if ($_GET['error'] === 'missing_data'): ?>
                <p><?php esc_html_e('Error: Missing required data.', 'cleversay'); ?></p>
            <?php elseif ($_GET['error'] === 'not_found'): ?>
                <p><?php esc_html_e('Error: Inquiry not found.', 'cleversay'); ?></p>
            <?php else: ?>
                <p><?php esc_html_e('An error occurred.', 'cleversay'); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <!-- Status Tabs -->
    <ul class="subsubsub">
        <li>
            <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-inquiries')); ?>" 
               class="<?php echo empty($filter_status) ? 'current' : ''; ?>">
                <?php esc_html_e('All', 'cleversay'); ?>
                <span class="count">(<?php echo array_sum(array_column((array)$status_counts, 'cnt')); ?>)</span>
            </a> |
        </li>
        <li>
            <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-inquiries&status=pending')); ?>"
               class="<?php echo $filter_status === 'pending' ? 'current' : ''; ?>">
                <?php esc_html_e('Pending', 'cleversay'); ?>
                <span class="count">(<?php echo $status_counts['pending']->cnt ?? 0; ?>)</span>
            </a> |
        </li>
        <li>
            <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-inquiries&status=answered')); ?>"
               class="<?php echo $filter_status === 'answered' ? 'current' : ''; ?>">
                <?php esc_html_e('Answered', 'cleversay'); ?>
                <span class="count">(<?php echo $status_counts['answered']->cnt ?? 0; ?>)</span>
            </a> |
        </li>
        <li>
            <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-inquiries&status=archived')); ?>"
               class="<?php echo $filter_status === 'archived' ? 'current' : ''; ?>">
                <?php esc_html_e('Archived', 'cleversay'); ?>
                <span class="count">(<?php echo $status_counts['archived']->cnt ?? 0; ?>)</span>
            </a>
        </li>
    </ul>
    
    <!-- Search Form -->
    <form method="get" style="margin-bottom:12px;">
        <input type="hidden" name="page" value="cleversay-inquiries">
        <?php if ($filter_status): ?>
            <input type="hidden" name="status" value="<?php echo esc_attr($filter_status); ?>">
        <?php endif; ?>
        <div style="display:flex;gap:8px;align-items:center;justify-content:flex-end;">
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
                   placeholder="<?php esc_attr_e('Search by question, email, or name...', 'cleversay'); ?>"
                   style="min-width:280px;">
            <input type="submit" class="button" value="<?php esc_attr_e('Search', 'cleversay'); ?>">
            <?php if (!empty($search)): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-inquiries')); ?>" class="button"><?php esc_html_e('Clear', 'cleversay'); ?></a>
            <?php endif; ?>
        </div>
    </form>
    
    <form method="post" id="inquiries-form" action="<?php echo esc_url(admin_url('admin.php?page=cleversay-inquiries')); ?>">
        <?php wp_nonce_field('cleversay_bulk_inquiries'); ?>
        <input type="hidden" name="bulk_form_submitted" value="1">
        
        <!-- Bulk Actions -->
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <select name="bulk_action">
                    <option value=""><?php esc_html_e('Bulk Actions', 'cleversay'); ?></option>
                    <option value="resolve"><?php esc_html_e('Mark as Answered', 'cleversay'); ?></option>
                    <option value="delete"><?php esc_html_e('Delete', 'cleversay'); ?></option>
                </select>
                <input type="submit" class="button action" value="<?php esc_attr_e('Apply', 'cleversay'); ?>">
            </div>
            
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php printf(esc_html__('%s items', 'cleversay'), number_format($total)); ?>
                </span>
            </div>
        </div>
        
        <!-- Inquiries Table -->
        <div class="cleversay-table-card" style="padding:0;overflow:hidden;">
        <table class="wp-list-table widefat fixed striped cleversay-table">
            <thead>
                <tr>
                    <td class="check-column">
                        <input type="checkbox" id="cb-select-all">
                    </td>
                    <th class="column-question"><?php esc_html_e('Question', 'cleversay'); ?></th>
                    <th class="column-contact"><?php esc_html_e('Contact', 'cleversay'); ?></th>
                    <th class="column-status"><?php esc_html_e('Status', 'cleversay'); ?></th>
                    <th class="column-date"><?php esc_html_e('Received', 'cleversay'); ?></th>
                    <th class="column-actions"><?php esc_html_e('Actions', 'cleversay'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($inquiries)): ?>
                    <tr>
                        <td colspan="6" class="no-items">
                            <?php esc_html_e('No inquiries found.', 'cleversay'); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($inquiries as $inquiry): ?>
                        <tr class="<?php echo $inquiry['status'] === 'pending' ? 'inquiry-pending' : ''; ?>">
                            <th class="check-column">
                                <input type="checkbox" name="inquiry_ids[]" value="<?php echo esc_attr($inquiry['id']); ?>">
                            </th>
                            <td class="column-question">
                                <?php if (!empty($inquiry['handoff_type'])):
                                    $ht = $inquiry['handoff_type'];
                                    $badge_bg    = $ht === 'auto_escalation' ? '#FEE2E2' : '#DBEAFE';
                                    $badge_color = $ht === 'auto_escalation' ? '#991B1B' : '#1E40AF';
                                    $badge_label = $ht === 'keyword_request'
                                        ? __('Agent requested', 'cleversay')
                                        : ($ht === 'auto_escalation'
                                            ? __('Auto-escalated', 'cleversay')
                                            : __('Handoff', 'cleversay'));
                                ?>
                                    <span style="display:inline-block;padding:2px 8px;background:<?php echo esc_attr($badge_bg); ?>;color:<?php echo esc_attr($badge_color); ?>;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">
                                        <?php echo \CleverSay\Icons::render('user', 10); ?>
                                        <?php echo esc_html($badge_label); ?>
                                    </span><br>
                                <?php endif; ?>
                                <strong><?php echo esc_html(wp_trim_words($inquiry['question'], 20)); ?></strong>
                                <?php if (strlen($inquiry['question']) > 150): ?>
                                    <button type="button" class="button-link toggle-full-question" 
                                            data-question="<?php echo esc_attr($inquiry['question']); ?>">
                                        <?php esc_html_e('Show more', 'cleversay'); ?>
                                    </button>
                                <?php endif; ?>
                                <?php if (!empty($inquiry['details'])): ?>
                                    <div class="inquiry-details-preview">
                                        <em><?php echo esc_html(wp_trim_words($inquiry['details'], 15)); ?></em>
                                        <?php if (strlen($inquiry['details']) > 100): ?>
                                            <button type="button" class="button-link toggle-full-details" 
                                                    data-details="<?php echo esc_attr($inquiry['details']); ?>">
                                                <?php esc_html_e('Show details', 'cleversay'); ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($inquiry['transcript'])): ?>
                                    <div style="margin-top:6px;">
                                        <button type="button" class="button-link toggle-transcript"
                                                data-transcript="<?php echo esc_attr($inquiry['transcript']); ?>"
                                                style="font-size:12px;color:#2271b1;">
                                            <?php echo \CleverSay\Icons::render('message-circle', 12); ?>
                                            <?php esc_html_e('View chat transcript', 'cleversay'); ?>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="column-contact">
                                <?php if (!empty($inquiry['name'])): ?>
                                    <?php echo esc_html($inquiry['name']); ?><br>
                                <?php endif; ?>
                                <?php if (!empty($inquiry['email'])): ?>
                                    <a href="mailto:<?php echo esc_attr($inquiry['email']); ?>">
                                        <?php echo esc_html($inquiry['email']); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted"><?php esc_html_e('No email', 'cleversay'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="column-status">
                                <?php if ($inquiry['status'] === 'pending'): ?>
                                    <span class="badge badge-pending"><?php esc_html_e('Pending', 'cleversay'); ?></span>
                                <?php elseif ($inquiry['status'] === 'answered'): ?>
                                    <span class="badge badge-answered"><?php esc_html_e('Answered', 'cleversay'); ?></span>
                                    <?php if (!empty($inquiry['responded_at'])): ?>
                                        <br><small><?php echo esc_html(human_time_diff(strtotime($inquiry['responded_at']))); ?> ago</small>
                                    <?php endif; ?>
                                <?php elseif ($inquiry['status'] === 'archived'): ?>
                                    <span class="badge badge-archived"><?php esc_html_e('Archived', 'cleversay'); ?></span>
                                <?php elseif ($inquiry['status'] === 'spam'): ?>
                                    <span class="badge badge-spam"><?php esc_html_e('Spam', 'cleversay'); ?></span>
                                <?php else: ?>
                                    <span class="badge"><?php echo esc_html(ucfirst($inquiry['status'] ?? 'unknown')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="column-date">
                                <abbr title="<?php echo esc_attr($inquiry['created_at']); ?>">
                                    <?php echo esc_html(human_time_diff(strtotime($inquiry['created_at']))); ?>
                                    <?php esc_html_e('ago', 'cleversay'); ?>
                                </abbr>
                            </td>
                            <td class="column-actions">
                                <?php if ($inquiry['status'] === 'pending'): ?>
                                    <a href="<?php echo esc_url(wp_nonce_url(
                                        admin_url('admin.php?page=cleversay-inquiries&action=respond&inquiry_id=' . $inquiry['id']),
                                        'respond_inquiry_' . $inquiry['id']
                                    )); ?>" class="button button-primary button-small">
                                        <?php esc_html_e('Respond', 'cleversay'); ?>
                                    </a>
                                <?php else: ?>
                                    <?php if (!empty($inquiry['response'])): ?>
                                    <button type="button" class="button button-small view-response"
                                            data-response="<?php echo esc_attr($inquiry['response']); ?>">
                                        <?php esc_html_e('View Response', 'cleversay'); ?>
                                    </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <a href="<?php echo esc_url(wp_nonce_url(
                                    admin_url('admin.php?page=cleversay-inquiries&action=delete&inquiry_id=' . $inquiry['id']),
                                    'delete_inquiry_' . $inquiry['id']
                                )); ?>" 
                                   class="button button-small button-link-delete"
                                   onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this inquiry?', 'cleversay'); ?>');"
                                   title="<?php esc_attr_e('Delete this inquiry', 'cleversay'); ?>">
                                    <?php esc_html_e('Delete', 'cleversay'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div><!-- /.cleversay-table-card -->
        <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    $pagination_args = [
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'current' => $paged,
                        'total' => $total_pages,
                        'prev_text' => '‹',
                        'next_text' => '›',
                    ];
                    echo paginate_links($pagination_args);
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </form>
</div>

<!-- Reusable Modal -->
<div id="cleversay-modal" class="cleversay-modal" style="display:none;">
    <div class="cleversay-modal-content">
        <span class="cleversay-modal-close">&times;</span>
        <h3 id="modal-title"><?php echo \CleverSay\Icons::render('send', 16); ?> <?php esc_html_e('Response', 'cleversay'); ?></h3>
        <div id="modal-content"></div>
    </div>
</div>

<style>
/* Inquiries page */
.column-question  { width: 35%; }
.column-contact   { width: 18%; }
.column-status    { width: 90px; }
.column-date      { width: 12%; }
.column-actions   { width: 22%; }
.text-muted       { color: var(--cs-text-tertiary, #86868B); }

/* Pending row — subtle warm tint */
.inquiry-pending > td { background-color: rgba(255,159,10,0.05) !important; }

/* Status badges */
.badge { display: inline-flex; align-items: center; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-pending  { background: rgba(255,159,10,0.12); color: #8A5400; }
.badge-answered { background: rgba(48,209,88,0.12);  color: #1A7A37; }
.badge-resolved { background: rgba(48,209,88,0.12);  color: #1A7A37; }
.badge-archived { background: rgba(120,120,128,0.12); color: #515154; }
.badge-spam     { background: rgba(255,59,48,0.1);    color: #C0392B; }

/* Delete button */
.button-link-delete { color: #FF3B30 !important; border-color: #FF3B30 !important; }
.button-link-delete:hover { background: #FF3B30 !important; color: #fff !important; }

/* Action buttons spacing */
.column-actions .button { margin-right: 4px; margin-bottom: 4px; }

/* Detail view */
.inquiry-question blockquote {
    background: rgba(10,132,255,0.04);
    border-left: 3px solid #0A84FF;
    margin: 10px 0 18px;
    padding: 14px 18px;
    font-style: italic;
    border-radius: 0 8px 8px 0;
}

.inquiry-meta { color: #86868B; font-size: 13px; margin-top: 12px; }
</style>

<script>
jQuery(function($) {
    // Select all checkbox
    $('#cb-select-all').on('change', function() {
        $('input[name="inquiry_ids[]"]').prop('checked', this.checked);
    });
    
    // Show full question
    $('.toggle-full-question').on('click', function() {
        var question = $(this).data('question');
        showModal('<?php echo esc_js(__('Full Question', 'cleversay')); ?>', question);
    });
    
    // Show full details
    $('.toggle-full-details').on('click', function() {
        var details = $(this).data('details');
        if (details) {
            showModal('<?php echo esc_js(__('Additional Details', 'cleversay')); ?>', details);
        }
    });

    // Show chat transcript
    $('.toggle-transcript').on('click', function() {
        var transcript = $(this).data('transcript');
        if (transcript) {
            showModal('<?php echo esc_js(__('Chat Transcript', 'cleversay')); ?>', transcript);
        }
    });
    
    // View response
    $('.view-response').on('click', function() {
        var response = $(this).data('response') || '<?php echo esc_js(__('No response recorded.', 'cleversay')); ?>';
        showModal('<?php echo esc_js(__('Response', 'cleversay')); ?>', response);
    });
    
    // Show modal helper function
    function showModal(title, content) {
        $('#modal-title').text(title);
        $('#modal-content').html('<p>' + $('<div>').text(content).html().replace(/\n/g, '<br>') + '</p>');
        $('#cleversay-modal').css('display', 'flex');
    }
    
    // Close modal
    $('.cleversay-modal-close').on('click', function() {
        $('#cleversay-modal').hide();
    });
    
    $(window).on('click', function(e) {
        if ($(e.target).is('#cleversay-modal')) {
            $('#cleversay-modal').hide();
        }
    });
    
    // Close on Escape key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            $('#cleversay-modal').hide();
        }
    });
});
</script>
