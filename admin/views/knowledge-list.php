<?php
/**
 * Knowledge Base List View
 *
 * @package CleverSay
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

global $wpdb;

// Handle actions
$action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
$entry_id = isset($_GET['id']) ? absint($_GET['id']) : 0;

// If editing or adding, include the form view
if ($action === 'new' || $action === 'edit') {
    include __DIR__ . '/knowledge-form.php';
    return;
}

// Pagination
$per_page = 20;
$current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
$offset = ($current_page - 1) * $per_page;

// Filtering
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$letter_filter = isset($_GET['letter']) ? sanitize_text_field($_GET['letter']) : '';

// Build query
$where = ['1=1'];
$params = [];

if ($search) {
    $where[] = "(keyword LIKE %s OR sub_keyword LIKE %s OR response LIKE %s)";
    $search_like = '%' . $wpdb->esc_like($search) . '%';
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
}

if ($status_filter) {
    $where[] = "status = %s";
    $params[] = $status_filter;
}


if ($letter_filter) {
    if ($letter_filter === '0-9') {
        $where[] = "keyword REGEXP '^[0-9]'";
    } else {
        $where[] = "keyword LIKE %s";
        $params[] = $letter_filter . '%';
    }
}

$where_sql = implode(' AND ', $where);

// Get total count
$count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}cleversay_knowledge WHERE $where_sql";
$total_items = empty($params) 
    ? $wpdb->get_var($count_query)
    : $wpdb->get_var($wpdb->prepare($count_query, ...$params));

$total_pages = ceil($total_items / $per_page);

// Get entries
$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'keyword';
$order = isset($_GET['order']) && strtoupper($_GET['order']) === 'DESC' ? 'DESC' : 'ASC';

$allowed_orderby = ['keyword', 'hits', 'rate', 'status', 'updated_at'];
if (!in_array($orderby, $allowed_orderby)) {
    $orderby = 'keyword';
}

$query = "SELECT k.* c.name as category_name 
          FROM {$wpdb->prefix}cleversay_knowledge k
          WHERE $where_sql 
          ORDER BY $orderby $order 
          LIMIT %d OFFSET %d";

$params[] = $per_page;
$params[] = $offset;

$entries = $wpdb->get_results($wpdb->prepare($query, ...$params));

// Get categories for filter

// Status counts
$status_counts = $wpdb->get_results(
    "SELECT status, COUNT(*) as count FROM {$wpdb->prefix}cleversay_knowledge GROUP BY status",
    OBJECT_K
);

// Build base URL for links
$base_url = admin_url('admin.php?page=cleversay-knowledge');
?>

<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline"><?php echo \CleverSay\Icons::render('book-open', 16); ?> <?php esc_html_e('Knowledge Base', 'cleversay'); ?></h1>
    <a href="<?php echo esc_url(add_query_arg('action', 'new', $base_url)); ?>" class="page-title-action">
        <?php esc_html_e('Add New', 'cleversay'); ?>
    </a>
    
    <hr class="wp-header-end">

    <?php if (isset($_GET['message'])): ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                $messages = [
                    'saved' => __('Entry saved successfully.', 'cleversay'),
                    'deleted' => __('Entry deleted successfully.', 'cleversay'),
                    'bulk_deleted' => __('Selected entries deleted.', 'cleversay'),
                    'bulk_activated' => __('Selected entries activated.', 'cleversay'),
                    'bulk_deactivated' => __('Selected entries deactivated.', 'cleversay'),
                ];
                echo esc_html($messages[$_GET['message']] ?? '');
                ?>
            </p>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="cleversay-filters">
        <!-- Status Tabs -->
        <ul class="subsubsub">
            <li>
                <a href="<?php echo esc_url($base_url); ?>" <?php echo !$status_filter ? 'class="current"' : ''; ?>>
                    <?php esc_html_e('All', 'cleversay'); ?>
                    <span class="count">(<?php echo esc_html($total_items); ?>)</span>
                </a> |
            </li>
            <li>
                <a href="<?php echo esc_url(add_query_arg('status', 'active', $base_url)); ?>" 
                   <?php echo $status_filter === 'active' ? 'class="current"' : ''; ?>>
                    <?php esc_html_e('Active', 'cleversay'); ?>
                    <span class="count">(<?php echo esc_html($status_counts['active']->count ?? 0); ?>)</span>
                </a> |
            </li>
            <li>
                <a href="<?php echo esc_url(add_query_arg('status', 'inactive', $base_url)); ?>"
                   <?php echo $status_filter === 'inactive' ? 'class="current"' : ''; ?>>
                    <?php esc_html_e('Inactive', 'cleversay'); ?>
                    <span class="count">(<?php echo esc_html($status_counts['inactive']->count ?? 0); ?>)</span>
                </a> |
            </li>
            <li>
                <a href="<?php echo esc_url(add_query_arg('status', 'pending', $base_url)); ?>"
                   <?php echo $status_filter === 'pending' ? 'class="current"' : ''; ?>>
                    <?php esc_html_e('Pending', 'cleversay'); ?>
                    <span class="count">(<?php echo esc_html($status_counts['pending']->count ?? 0); ?>)</span>
                </a>
            </li>
        </ul>

        <!-- Search Form -->
        <form method="get" class="search-box">
            <input type="hidden" name="page" value="cleversay-knowledge">
            <?php if ($status_filter): ?>
                <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
            <?php endif; ?>
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" 
                   placeholder="<?php esc_attr_e('Search keywords...', 'cleversay'); ?>">
            <button type="submit" class="button"><?php esc_html_e('Search', 'cleversay'); ?></button>
        </form>
    </div>

    <!-- Alphabet Navigation -->
    <div class="cleversay-alphabet-nav">
        <a href="<?php echo esc_url(remove_query_arg('letter', $base_url)); ?>" 
           class="<?php echo !$letter_filter ? 'current' : ''; ?>">
            <?php esc_html_e('All', 'cleversay'); ?>
        </a>
        <?php
        foreach (range('A', 'Z') as $letter) {
            $url = add_query_arg('letter', $letter, $base_url);
            $class = $letter_filter === $letter ? 'current' : '';
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($letter) . '</a>';
        }
        ?>
        <a href="<?php echo esc_url(add_query_arg('letter', '0-9', $base_url)); ?>"
           class="<?php echo $letter_filter === '0-9' ? 'current' : ''; ?>">0-9</a>
    </div>

    <!-- Bulk Actions Form -->
    <form method="post" id="cleversay-bulk-form">
        <?php wp_nonce_field('cleversay_bulk_action', 'cleversay_nonce'); ?>
        
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <select name="bulk_action" id="bulk-action-selector">
                    <option value=""><?php esc_html_e('Bulk Actions', 'cleversay'); ?></option>
                    <option value="activate"><?php esc_html_e('Activate', 'cleversay'); ?></option>
                    <option value="deactivate"><?php esc_html_e('Deactivate', 'cleversay'); ?></option>
                    <option value="delete"><?php esc_html_e('Delete', 'cleversay'); ?></option>
                </select>
                <button type="submit" class="button action" id="doaction">
                    <?php esc_html_e('Apply', 'cleversay'); ?>
                </button>
            </div>

            <!-- Category Filter -->
            <?php if (!empty($categories)): ?>
            <div class="alignleft actions">
            </div>
            <?php endif; ?>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php printf(
                        esc_html(_n('%s item', '%s items', $total_items, 'cleversay')),
                        number_format_i18n($total_items)
                    ); ?>
                </span>
                <span class="pagination-links">
                    <?php
                    $pagination_args = [
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'current' => $current_page,
                        'total' => $total_pages,
                        'prev_text' => '&lsaquo;',
                        'next_text' => '&rsaquo;',
                    ];
                    echo paginate_links($pagination_args);
                    ?>
                </span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Table -->
    <div class="cleversay-table-card" style="padding:0;overflow:hidden;">
        <table class="wp-list-table widefat fixed striped cleversay-knowledge-table">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all">
                    </td>
                    <th class="column-keyword sortable <?php echo $orderby === 'keyword' ? 'sorted ' . strtolower($order) : 'asc'; ?>">
                        <a href="<?php echo esc_url(add_query_arg(['orderby' => 'keyword', 'order' => ($orderby === 'keyword' && $order === 'ASC') ? 'desc' : 'asc'])); ?>">
                            <span><?php esc_html_e('Keyword', 'cleversay'); ?></span>
                            <span class="sorting-indicators">
                                <span class="sorting-indicator asc" aria-hidden="true"></span>
                                <span class="sorting-indicator desc" aria-hidden="true"></span>
                            </span>
                        </a>
                    </th>
                    <th class="column-sub_keyword"><?php esc_html_e('Sub-keyword', 'cleversay'); ?></th>
                    <th class="column-response"><?php esc_html_e('Response', 'cleversay'); ?></th>
                    <th class="column-hits sortable <?php echo $orderby === 'hits' ? 'sorted ' . strtolower($order) : 'desc'; ?>">
                        <a href="<?php echo esc_url(add_query_arg(['orderby' => 'hits', 'order' => ($orderby === 'hits' && $order === 'DESC') ? 'asc' : 'desc'])); ?>">
                            <span><?php esc_html_e('Hits', 'cleversay'); ?></span>
                            <span class="sorting-indicators">
                                <span class="sorting-indicator asc" aria-hidden="true"></span>
                                <span class="sorting-indicator desc" aria-hidden="true"></span>
                            </span>
                        </a>
                    </th>
                    <th class="column-status sortable <?php echo $orderby === 'status' ? 'sorted ' . strtolower($order) : 'asc'; ?>">
                        <a href="<?php echo esc_url(add_query_arg(['orderby' => 'status', 'order' => ($orderby === 'status' && $order === 'ASC') ? 'desc' : 'asc'])); ?>">
                            <span><?php esc_html_e('Status', 'cleversay'); ?></span>
                            <span class="sorting-indicators">
                                <span class="sorting-indicator asc" aria-hidden="true"></span>
                                <span class="sorting-indicator desc" aria-hidden="true"></span>
                            </span>
                        </a>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($entries)): ?>
                    <tr>
                        <td colspan="7" class="no-items">
                            <?php esc_html_e('No knowledge entries found.', 'cleversay'); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="entry_ids[]" value="<?php echo esc_attr($entry->id); ?>">
                            </th>
                            <td class="column-keyword has-row-actions">
                                <strong>
                                    <a href="<?php echo esc_url(add_query_arg(['action' => 'edit', 'id' => $entry->id], $base_url)); ?>">
                                        <?php echo esc_html(wp_unslash($entry->keyword)); ?>
                                    </a>
                                </strong>
                                <div class="row-actions">
                                    <span class="edit">
                                        <a href="<?php echo esc_url(add_query_arg(['action' => 'edit', 'id' => $entry->id], $base_url)); ?>">
                                            <?php esc_html_e('Edit', 'cleversay'); ?>
                                        </a> | 
                                    </span>
                                    <span class="duplicate">
                                        <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'duplicate', 'id' => $entry->id], $base_url), 'duplicate_entry_' . $entry->id)); ?>">
                                            <?php esc_html_e('Duplicate', 'cleversay'); ?>
                                        </a> | 
                                    </span>
                                    <span class="trash">
                                        <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'delete', 'id' => $entry->id], $base_url), 'delete_entry_' . $entry->id)); ?>" 
                                           class="submitdelete" 
                                           onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this entry?', 'cleversay'); ?>');">
                                            <?php esc_html_e('Delete', 'cleversay'); ?>
                                        </a>
                                    </span>
                                </div>
                            </td>
                            <td class="column-sub_keyword">
                                <?php echo esc_html(wp_unslash($entry->sub_keyword) ?: '—'); ?>
                            </td>
                            <td class="column-response">
                                <span class="response-preview" title="<?php echo esc_attr(wp_unslash(wp_strip_all_tags($entry->response))); ?>">
                                    <?php echo esc_html(wp_trim_words(wp_strip_all_tags(wp_unslash($entry->response)), 12)); ?>
                                </span>
                            </td>
                            <td class="column-hits">
                                <?php echo esc_html(number_format_i18n($entry->hits)); ?>
                            </td>
                            <td class="column-status">
                                <span class="status-badge status-<?php echo esc_attr($entry->status); ?>">
                                    <?php echo esc_html(ucfirst($entry->status)); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox">
                    </td>
                    <th><?php esc_html_e('Keyword', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Sub-keyword', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Response', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Category', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Hits', 'cleversay'); ?></th>
                    <th><?php esc_html_e('Status', 'cleversay'); ?></th>
                </tr>
            </tfoot>
        </table>
    </div><!-- /.cleversay-table-card -->

        <!-- Bottom Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php echo paginate_links($pagination_args); ?>
            </div>
        </div>
        <?php endif; ?>
    </form>
</div>

<style>
.cleversay-alphabet-nav {
    margin: 15px 0;
    padding: 10px;
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}
.cleversay-alphabet-nav a {
    display: inline-block;
    padding: 5px 10px;
    margin: 2px;
    text-decoration: none;
    border-radius: 3px;
}
.cleversay-alphabet-nav a:hover,
.cleversay-alphabet-nav a.current {
    background: #2271b1;
    color: #fff;
}
.column-keyword { width: 15%; }
.column-sub_keyword { width: 12%; }
.column-response { width: 30%; }
.column-hits { width: 8%; }
.column-status { width: 10%; }
.response-preview {
    display: block;
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
}
.status-active { background: #d4edda; color: #155724; }
.status-inactive { background: #f8d7da; color: #721c24; }
.status-pending { background: #fff3cd; color: #856404; }
</style>
