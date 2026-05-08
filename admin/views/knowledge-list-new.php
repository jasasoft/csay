<?php
/**
 * Knowledge Base List View - Grouped by Keyword
 *
 * @package CleverSay
 * @since 2.0.36
 */

defined('ABSPATH') || exit;

global $wpdb;

// Handle actions - route to appropriate view
$action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';

// Route to keyword detail page
if ($action === 'keyword-detail' || $action === 'edit-keyword') {
    include __DIR__ . '/knowledge-keyword-detail.php';
    return;
}

// Route to phrase group edit page
if ($action === 'edit-phrase-group' || $action === 'new-phrase-group') {
    include __DIR__ . '/knowledge-phrase-edit.php';
    return;
}

// Route to new keyword page
if ($action === 'new-keyword') {
    include __DIR__ . '/knowledge-keyword-new.php';
    return;
}

// Route to add-question page (question-first entry creation, v4.37.39+)
if ($action === 'add-question') {
    include __DIR__ . '/knowledge-add-question.php';
    return;
}

// Pagination
$per_page = 30;
$current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
$offset = ($current_page - 1) * $per_page;

// Filtering
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$letter_filter = isset($_GET['letter']) ? sanitize_text_field($_GET['letter']) : '';

// Build query for unique keywords with counts
$where = ['1=1'];
$params = [];

if ($search) {
    $where[] = "keyword LIKE %s";
    $search_like = '%' . $wpdb->esc_like($search) . '%';
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

// Get unique keywords with pattern counts
$query = "SELECT 
            keyword,
            COUNT(*) as pattern_count,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
            MAX(CASE WHEN sub_keyword = 'aadefault' THEN question ELSE NULL END) as default_phrase,
            MAX(updated_at) as last_updated,
            SUM(hits) as total_hits
          FROM {$wpdb->prefix}cleversay_knowledge
          WHERE $where_sql
          GROUP BY keyword
          ORDER BY keyword ASC
          LIMIT %d OFFSET %d";

$params[] = $per_page;
$params[] = $offset;

$keywords = empty($params) || count($params) <= 2
    ? $wpdb->get_results($wpdb->prepare($query, $per_page, $offset))
    : $wpdb->get_results($wpdb->prepare($query, ...$params));

// Get total unique keywords count
$count_query = "SELECT COUNT(DISTINCT keyword) FROM {$wpdb->prefix}cleversay_knowledge WHERE $where_sql";
$count_params = array_slice($params, 0, -2); // Remove LIMIT and OFFSET params
$total_items = empty($count_params)
    ? $wpdb->get_var($count_query)
    : $wpdb->get_var($wpdb->prepare($count_query, ...$count_params));

$total_pages = ceil($total_items / $per_page);

// Status counts
$status_counts = $wpdb->get_results(
    "SELECT status, COUNT(DISTINCT keyword) as count FROM {$wpdb->prefix}cleversay_knowledge GROUP BY status",
    OBJECT_K
);

// Get all unique keywords count
$all_keywords_count = $wpdb->get_var("SELECT COUNT(DISTINCT keyword) FROM {$wpdb->prefix}cleversay_knowledge");

// Get alphabet for filter
$alphabet = range('A', 'Z');

// Build base URL
$base_url = admin_url('admin.php?page=cleversay-knowledge');
?>

<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline"><?php echo \CleverSay\Icons::render('book-open', 16); ?> <?php esc_html_e('Knowledge Base', 'cleversay'); ?></h1>
    <a href="<?php echo esc_url(add_query_arg('action', 'add-question', $base_url)); ?>" class="page-title-action">
        <?php esc_html_e('Add Question', 'cleversay'); ?>
    </a>
    <a href="<?php echo esc_url(add_query_arg('action', 'new-keyword', $base_url)); ?>" class="page-title-action">
        <?php esc_html_e('Add New Keyword', 'cleversay'); ?>
    </a>
    
    <hr class="wp-header-end">

    <?php if (isset($_GET['message'])): ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                $messages = [
                    'saved' => __('Keyword saved successfully.', 'cleversay'),
                    'deleted' => __('Keyword deleted successfully.', 'cleversay'),
                    'validated' => __('All patterns validated successfully.', 'cleversay'),
                ];
                echo esc_html($messages[$_GET['message']] ?? '');
                ?>
            </p>
        </div>
    <?php endif; ?>

    <!-- Info Box -->
    <div class="cleversay-info-box">
        <p>
            <?php echo \CleverSay\Icons::render('info', 16); ?>
            <?php esc_html_e('Each keyword can have multiple match patterns that share responses. Click on a keyword to manage its patterns.', 'cleversay'); ?>
        </p>
    </div>

    <!-- Filters -->
    <div class="cleversay-filters">
        <!-- Status Tabs -->
        <ul class="subsubsub">
            <li>
                <a href="<?php echo esc_url($base_url); ?>" <?php echo !$status_filter ? 'class="current"' : ''; ?>>
                    <?php esc_html_e('All', 'cleversay'); ?>
                    <span class="count">(<?php echo esc_html($all_keywords_count); ?>)</span>
                </a> |
            </li>
            <li>
                <a href="<?php echo esc_url(add_query_arg('status', 'active', $base_url)); ?>" 
                   <?php echo $status_filter === 'active' ? 'class="current"' : ''; ?>>
                    <?php esc_html_e('With Active', 'cleversay'); ?>
                    <span class="count">(<?php echo esc_html($status_counts['active']->count ?? 0); ?>)</span>
                </a> |
            </li>
            <li>
                <a href="<?php echo esc_url(add_query_arg('status', 'inactive', $base_url)); ?>"
                   <?php echo $status_filter === 'inactive' ? 'class="current"' : ''; ?>>
                    <?php esc_html_e('With Inactive', 'cleversay'); ?>
                    <span class="count">(<?php echo esc_html($status_counts['inactive']->count ?? 0); ?>)</span>
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

    <!-- Alphabet Filter -->
    <div class="cleversay-alphabet-filter">
        <a href="<?php echo esc_url(remove_query_arg('letter', $base_url)); ?>" 
           class="<?php echo !$letter_filter ? 'current' : ''; ?>">
            <?php esc_html_e('All', 'cleversay'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('letter', '0-9', $base_url)); ?>"
           class="<?php echo $letter_filter === '0-9' ? 'current' : ''; ?>">0-9</a>
        <?php foreach ($alphabet as $letter): ?>
            <a href="<?php echo esc_url(add_query_arg('letter', $letter, $base_url)); ?>"
               class="<?php echo $letter_filter === $letter ? 'current' : ''; ?>">
                <?php echo esc_html($letter); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Keywords Table -->
    <div class="cleversay-table-card" style="padding:0;overflow:hidden;">
    <table class="wp-list-table widefat fixed striped cleversay-keywords-table">
        <thead>
            <tr>
                <th class="column-keyword" scope="col"><?php esc_html_e('Keyword', 'cleversay'); ?></th>
                <th class="column-patterns" scope="col"><?php esc_html_e('Patterns', 'cleversay'); ?></th>
                <th class="column-phrase" scope="col"><?php esc_html_e('Default Phrase', 'cleversay'); ?></th>
                <th class="column-hits" scope="col"><?php esc_html_e('Total Hits', 'cleversay'); ?></th>
                <th class="column-updated" scope="col"><?php esc_html_e('Last Updated', 'cleversay'); ?></th>
                <th class="column-actions" scope="col"><?php esc_html_e('Actions', 'cleversay'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($keywords)): ?>
                <tr>
                    <td colspan="6" class="no-items">
                        <?php esc_html_e('No keywords found.', 'cleversay'); ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($keywords as $kw): ?>
                    <tr>
                        <td class="column-keyword">
                            <strong>
                                <a href="<?php echo esc_url(add_query_arg([
                                    'action' => 'keyword-detail',
                                    'keyword' => urlencode($kw->keyword)
                                ], $base_url)); ?>">
                                    <?php echo esc_html($kw->keyword); ?>
                                </a>
                            </strong>
                        </td>
                        <td class="column-patterns">
                            <span class="pattern-count">
                                <?php echo esc_html($kw->pattern_count); ?>
                                <?php echo esc_html(_n('pattern', 'patterns', $kw->pattern_count, 'cleversay')); ?>
                            </span>
                            <?php if ($kw->active_count < $kw->pattern_count): ?>
                                <span class="inactive-indicator" title="<?php esc_attr_e('Some patterns are inactive', 'cleversay'); ?>">
                                    (<?php echo esc_html($kw->active_count); ?> <?php esc_html_e('active', 'cleversay'); ?>)
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="column-phrase">
                            <?php if ($kw->default_phrase): ?>
                                <?php echo esc_html(wp_trim_words($kw->default_phrase, 10)); ?>
                            <?php else: ?>
                                <span class="no-default"><?php esc_html_e('No default phrase', 'cleversay'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="column-hits">
                            <?php echo esc_html(number_format($kw->total_hits)); ?>
                        </td>
                        <td class="column-updated">
                            <?php echo esc_html(human_time_diff(strtotime($kw->last_updated))); ?>
                            <?php esc_html_e('ago', 'cleversay'); ?>
                        </td>
                        <td class="column-actions">
                            <a href="<?php echo esc_url(add_query_arg([
                                'action' => 'keyword-detail',
                                'keyword' => urlencode($kw->keyword)
                            ], $base_url)); ?>" class="button button-small">
                                <?php esc_html_e('View', 'cleversay'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div><!-- /.cleversay-table-card -->

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                echo paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => $current_page,
                    'total' => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ]);
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.cleversay-info-box {
    background: #f0f6fc;
    border-left: 4px solid #2271b1;
    padding: 12px 16px;
    margin: 20px 0;
}
.cleversay-info-box p {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.cleversay-info-box .dashicons {
    color: #2271b1;
}
.cleversay-alphabet-filter {
    margin: 15px 0;
    padding: 10px 0;
    border-bottom: 1px solid #c3c4c7;
}
.cleversay-alphabet-filter a {
    display: inline-block;
    padding: 4px 8px;
    margin: 2px;
    text-decoration: none;
    border-radius: 3px;
}
.cleversay-alphabet-filter a:hover {
    background: #f0f0f1;
}
.cleversay-alphabet-filter a.current {
    background: #2271b1;
    color: #fff;
}
.cleversay-keywords-table .column-keyword {
    width: 15%;
}
.cleversay-keywords-table .column-patterns {
    width: 15%;
}
.cleversay-keywords-table .column-phrase {
    width: 30%;
}
.cleversay-keywords-table .column-hits {
    width: 10%;
}
.cleversay-keywords-table .column-updated {
    width: 15%;
}
.cleversay-keywords-table .column-actions {
    width: 15%;
}
.pattern-count {
    font-weight: 500;
}
.inactive-indicator {
    color: #d63638;
    font-size: 12px;
}
.no-default {
    color: #646970;
    font-style: italic;
}
</style>
