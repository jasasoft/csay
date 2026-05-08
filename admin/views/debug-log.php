<?php
/**
 * Debug Log Viewer Admin View
 * 
 * @package CleverSay
 * @since 2.0.3
 */

if (!defined('ABSPATH')) {
    exit;
}

use function CleverSay\cleversay_log;

$logger = cleversay_log();
$logs = $logger->get_logs(500);
$log_size = $logger->get_log_size();
$log_path = $logger->get_log_path();
$logging_enabled = $logger->is_enabled();
$diagnostics = $logger->get_diagnostics();

// Handle clear logs action
if (isset($_POST['cleversay_clear_logs']) && wp_verify_nonce($_POST['_wpnonce'], 'cleversay_clear_logs')) {
    $logger->clear_logs();
    $logs = $logger->get_logs(500);
    echo '<div class="notice notice-success"><p>' . esc_html__('Logs cleared successfully.', 'cleversay') . '</p></div>';
}

// Handle toggle logging
if (isset($_POST['cleversay_toggle_logging']) && wp_verify_nonce($_POST['_wpnonce'], 'cleversay_toggle_logging')) {
    $new_state = !$logging_enabled;
    $logger->set_enabled($new_state);
    $logging_enabled = $new_state;
    echo '<div class="notice notice-success"><p>' . ($new_state ? esc_html__('Logging enabled.', 'cleversay') : esc_html__('Logging disabled.', 'cleversay')) . '</p></div>';
}

// Handle test log
if (isset($_POST['cleversay_test_log']) && wp_verify_nonce($_POST['_wpnonce'], 'cleversay_test_log')) {
    $logger->info('Test log entry from admin', ['user' => wp_get_current_user()->user_login, 'time' => current_time('mysql')]);
    $logs = $logger->get_logs(500);
    echo '<div class="notice notice-success"><p>' . esc_html__('Test log entry added.', 'cleversay') . '</p></div>';
}

// Handle test search
if (isset($_POST['cleversay_test_search_log']) && wp_verify_nonce($_POST['_wpnonce'], 'cleversay_test_search_log')) {
    $logger->info('=== TEST SEARCH FROM DEBUG LOG ===');
    $search = new \CleverSay\Search();
    $result = $search->search('test tuition question');
    $logger->info('Test search completed', ['success' => $result['success'], 'matches' => count($result['matches'])]);
    $logs = $logger->get_logs(500);
    echo '<div class="notice notice-success"><p>' . esc_html__('Test search completed. Check logs below.', 'cleversay') . '</p></div>';
}
?>

<div class="wrap cleversay-admin cleversay-debug-log">
    <h1 class="wp-heading-inline">
        <?php echo \CleverSay\Icons::render('file-text', 16); ?>
        <?php esc_html_e('Debug Log', 'cleversay'); ?>
    </h1>
    
    <hr class="wp-header-end">
    
    <!-- Diagnostics -->
    <div class="cleversay-diagnostics" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;">
        <h3 style="margin-top: 0;"><?php echo \CleverSay\Icons::render('activity', 16); ?> <?php esc_html_e('System Diagnostics', 'cleversay'); ?></h3>
        <div class="cleversay-table-card" style="padding:0;overflow:hidden;">
        <table class="widefat">
            <tr>
                <td><strong>Log Directory</strong></td>
                <td><code><?php echo esc_html($diagnostics['log_dir']); ?></code></td>
                <td><?php echo $diagnostics['dir_exists'] ? '<span style="color:green;">✓ Exists</span>' : '<span style="color:red;">✗ Missing</span>'; ?></td>
                <td><?php echo $diagnostics['dir_writable'] ? '<span style="color:green;">✓ Writable</span>' : '<span style="color:red;">✗ Not Writable</span>'; ?></td>
            </tr>
            <tr>
                <td><strong>Log File</strong></td>
                <td><code><?php echo esc_html(basename($diagnostics['log_file'])); ?></code></td>
                <td><?php echo $diagnostics['file_exists'] ? '<span style="color:green;">✓ Exists</span>' : '<span style="color:red;">✗ Missing</span>'; ?></td>
                <td><?php echo $diagnostics['file_writable'] ? '<span style="color:green;">✓ Writable</span>' : '<span style="color:red;">✗ Not Writable</span>'; ?></td>
            </tr>
            <tr>
                <td><strong>Logging</strong></td>
                <td colspan="3"><?php echo $diagnostics['enabled'] ? '<span style="color:green;">✓ Enabled</span>' : '<span style="color:red;">✗ Disabled</span>'; ?></td>
            </tr>
            <tr>
                <td><strong>File Size</strong></td>
                <td colspan="3"><?php echo esc_html($log_size); ?></td>
            </tr>
        </table>
        </div>
    </div>
    
    <!-- Controls -->
    <div class="cleversay-log-controls" style="margin: 20px 0; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <form method="post" style="display: inline;">
            <?php wp_nonce_field('cleversay_toggle_logging'); ?>
            <button type="submit" name="cleversay_toggle_logging" class="button <?php echo $logging_enabled ? 'button-secondary' : 'button-primary'; ?>">
                <?php echo \CleverSay\Icons::render($logging_enabled ? 'x' : 'check', 14); ?>
                <?php echo $logging_enabled ? esc_html__('Disable Logging', 'cleversay') : esc_html__('Enable Logging', 'cleversay'); ?>
            </button>
        </form>
        
        <form method="post" style="display: inline;">
            <?php wp_nonce_field('cleversay_test_log'); ?>
            <button type="submit" name="cleversay_test_log" class="button">
                <?php echo \CleverSay\Icons::render('edit', 16); ?>
                <?php esc_html_e('Add Test Entry', 'cleversay'); ?>
            </button>
        </form>
        
        <form method="post" style="display: inline;">
            <?php wp_nonce_field('cleversay_test_search_log'); ?>
            <button type="submit" name="cleversay_test_search_log" class="button button-primary">
                <?php echo \CleverSay\Icons::render('search', 16); ?>
                <?php esc_html_e('Run Test Search', 'cleversay'); ?>
            </button>
        </form>
        
        <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to clear all logs?', 'cleversay')); ?>');">
            <?php wp_nonce_field('cleversay_clear_logs'); ?>
            <button type="submit" name="cleversay_clear_logs" class="button">
                <?php echo \CleverSay\Icons::render('trash', 16); ?>
                <?php esc_html_e('Clear Logs', 'cleversay'); ?>
            </button>
        </form>
        
        <button type="button" id="cleversay-refresh-logs" class="button">
            <?php echo \CleverSay\Icons::render('refresh-cw', 16); ?>
            <?php esc_html_e('Refresh', 'cleversay'); ?>
        </button>
    </div>
    
    <!-- Log Viewer -->
    <div class="cleversay-log-viewer" style="position: relative;">
        <textarea id="cleversay-log-content" readonly style="
            width: 100%;
            height: 500px;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.5;
            padding: 15px;
            background: #1d2327;
            color: #c3c4c7;
            border: 1px solid #2c3338;
            border-radius: 4px;
            resize: vertical;
            white-space: pre;
            overflow-x: auto;
        "><?php echo esc_textarea($logs); ?></textarea>
        
        <div style="position: absolute; top: 10px; right: 25px;">
            <button type="button" id="cleversay-scroll-bottom" class="button button-small" title="<?php esc_attr_e('Scroll to bottom', 'cleversay'); ?>">
                <?php echo \CleverSay\Icons::render('arrow-down', 16); ?>
            </button>
            <button type="button" id="cleversay-scroll-top" class="button button-small" title="<?php esc_attr_e('Scroll to top', 'cleversay'); ?>">
                <?php echo \CleverSay\Icons::render('arrow-up', 16); ?>
            </button>
        </div>
    </div>
    
    <!-- Legend -->
    <div class="cleversay-log-legend" style="margin-top: 15px; padding: 10px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;">
        <strong><?php esc_html_e('Log Levels:', 'cleversay'); ?></strong>
        <span style="margin-left: 15px; color: #00a32a;">[INFO]</span> - <?php esc_html_e('General information', 'cleversay'); ?>
        <span style="margin-left: 15px; color: #2271b1;">[DEBUG]</span> - <?php esc_html_e('Detailed debug data', 'cleversay'); ?>
        <span style="margin-left: 15px; color: #dba617;">[WARNING]</span> - <?php esc_html_e('Warnings', 'cleversay'); ?>
        <span style="margin-left: 15px; color: #d63638;">[ERROR]</span> - <?php esc_html_e('Errors', 'cleversay'); ?>
    </div>
</div><!-- /.wrap.cleversay-admin -->

<script>
jQuery(document).ready(function($) {
    const logContent = $('#cleversay-log-content');
    
    // Scroll to bottom on load
    logContent.scrollTop(logContent[0].scrollHeight);
    
    // Scroll buttons
    $('#cleversay-scroll-bottom').on('click', function() {
        logContent.scrollTop(logContent[0].scrollHeight);
    });
    
    $('#cleversay-scroll-top').on('click', function() {
        logContent.scrollTop(0);
    });
    
    // Refresh logs via AJAX
    $('#cleversay-refresh-logs').on('click', function() {
        const btn = $(this);
        btn.prop('disabled', true);
        btn.find('.dashicons').addClass('spin');
        
        $.ajax({
            url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
            type: 'POST',
            data: {
                action: 'cleversay_get_logs',
                nonce: '<?php echo wp_create_nonce('cleversay_get_logs'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    logContent.val(response.data.logs);
                    logContent.scrollTop(logContent[0].scrollHeight);
                }
            },
            complete: function() {
                btn.prop('disabled', false);
                btn.find('.dashicons').removeClass('spin');
            }
        });
    });
});
</script>

<style>
.dashicons.spin {
    animation: dashicons-spin 1s infinite linear;
}
@keyframes dashicons-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>
