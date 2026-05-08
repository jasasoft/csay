<?php
/**
 * Leads admin page — listing of captured leads from the pre-chat form.
 *
 * @package CleverSay
 */
defined('ABSPATH') || exit;

global $wpdb;
$db = new \CleverSay\Database();

// Filters
$identity_filter = sanitize_text_field($_GET['identity'] ?? '');
$search          = sanitize_text_field($_GET['s'] ?? '');

$where  = ['1=1'];
$params = [];
if ($identity_filter !== '') {
    $where[]  = 'identity = %s';
    $params[] = $identity_filter;
}
if ($search !== '') {
    $like = '%' . $wpdb->esc_like($search) . '%';
    $where[]  = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)';
    array_push($params, $like, $like, $like);
}

$page     = max(1, (int) ($_GET['paged'] ?? 1));
$per_page = 25;
$offset   = ($page - 1) * $per_page;

$where_sql = implode(' AND ', $where);

// Total count
$count_sql = "SELECT COUNT(*) FROM {$db->leads} WHERE $where_sql";
$total = $params
    ? (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params))
    : (int) $wpdb->get_var($count_sql);

// Page of rows
$list_sql = "SELECT * FROM {$db->leads} WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
$list_params = array_merge($params, [$per_page, $offset]);
$rows = $wpdb->get_results($wpdb->prepare($list_sql, ...$list_params), ARRAY_A);

// Distinct identities for filter dropdown
$identities = $wpdb->get_col(
    "SELECT DISTINCT identity FROM {$db->leads} WHERE identity IS NOT NULL AND identity <> '' ORDER BY identity"
);

// CSV export URL
$export_url = wp_nonce_url(
    add_query_arg(['page' => 'cleversay-leads', 'cs_export' => 'csv'], admin_url('admin.php')),
    'cleversay_export_leads'
);

$total_pages = max(1, (int) ceil($total / $per_page));
?>
<div class="wrap">
    <h1 class="wp-heading-inline" style="display:flex;align-items:center;gap:8px;">
        <?php echo \CleverSay\Icons::render('users', 18); ?>
        <?php esc_html_e('Captured Leads', 'cleversay'); ?>
    </h1>
    <a href="<?php echo esc_url($export_url); ?>" class="page-title-action">
        <?php esc_html_e('Export CSV', 'cleversay'); ?>
    </a>
    <hr class="wp-header-end">

    <p style="color:#646970;margin-top:8px;">
        <?php
        printf(
            esc_html(_n('%s lead captured', '%s leads captured', $total, 'cleversay')),
            number_format_i18n($total)
        );
        ?>
    </p>

    <?php if (!get_option('cleversay_lead_capture_enabled')): ?>
    <div class="notice notice-warning inline">
        <p>
            <?php
            printf(
                /* translators: %s = link to settings page */
                esc_html__('Lead capture is currently disabled. Enable it on the %s page to start collecting leads.', 'cleversay'),
                '<a href="' . esc_url(admin_url('admin.php?page=cleversay-settings#lead-capture')) . '">' . esc_html__('Settings → Lead Capture', 'cleversay') . '</a>'
            );
            ?>
        </p>
    </div>
    <?php endif; ?>

    <!-- Filter form -->
    <form method="get" style="margin:16px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="page" value="cleversay-leads">

        <?php if (!empty($identities)): ?>
        <select name="identity">
            <option value=""><?php esc_html_e('All identities', 'cleversay'); ?></option>
            <?php foreach ($identities as $i): ?>
                <option value="<?php echo esc_attr($i); ?>" <?php selected($identity_filter, $i); ?>>
                    <?php echo esc_html($i); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
               placeholder="<?php esc_attr_e('Search name or email…', 'cleversay'); ?>"
               style="width:240px;">

        <button type="submit" class="button"><?php esc_html_e('Filter', 'cleversay'); ?></button>

        <?php if ($identity_filter !== '' || $search !== ''): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-leads')); ?>" class="button-link">
                <?php esc_html_e('Clear filters', 'cleversay'); ?>
            </a>
        <?php endif; ?>
    </form>

    <!-- Leads table -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:140px;"><?php esc_html_e('Date', 'cleversay'); ?></th>
                <th><?php esc_html_e('Name', 'cleversay'); ?></th>
                <th><?php esc_html_e('Email', 'cleversay'); ?></th>
                <th><?php esc_html_e('Identity', 'cleversay'); ?></th>
                <th><?php esc_html_e('Phone', 'cleversay'); ?></th>
                <th style="width:110px;"><?php esc_html_e('Conversation', 'cleversay'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="6" style="text-align:center;padding:32px;color:#8c8f94;">
                    <?php esc_html_e('No leads captured yet.', 'cleversay'); ?>
                </td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row):
                    $name_parts = array_filter([$row['first_name'], $row['last_name']]);
                    $name = implode(' ', $name_parts);
                    if ($name === '') $name = '<em style="color:#8c8f94;">' . esc_html__('(no name)', 'cleversay') . '</em>';
                ?>
                    <tr>
                        <td>
                            <?php echo esc_html(
                                date_i18n(get_option('date_format') . ' ' . get_option('time_format'),
                                          strtotime($row['created_at']))
                            ); ?>
                        </td>
                        <td><?php echo $name; ?></td>
                        <td>
                            <?php if (!empty($row['email'])): ?>
                                <a href="mailto:<?php echo esc_attr($row['email']); ?>">
                                    <?php echo esc_html($row['email']); ?>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($row['identity'] ?? ''); ?></td>
                        <td><?php echo esc_html($row['phone'] ?? ''); ?></td>
                        <td>
                            <?php if (!empty($row['conversation_id'])): ?>
                                <code style="font-size:11px;color:#646970;" title="<?php echo esc_attr($row['conversation_id']); ?>">
                                    <?php echo esc_html(substr($row['conversation_id'], 0, 8)); ?>…
                                </code>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                echo paginate_links([
                    'base'    => add_query_arg('paged', '%#%'),
                    'format'  => '',
                    'current' => $page,
                    'total'   => $total_pages,
                ]);
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>
