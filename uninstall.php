<?php
/**
 * CleverSay Uninstall
 *
 * Fired when the plugin is uninstalled.
 *
 * @package CleverSay
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Get options to check if we should delete data
$options = get_option('cleversay_options', []);

if (!empty($options['delete_data_on_uninstall'])) {
    global $wpdb;
    
    // Drop all plugin tables
    $tables = [
        $wpdb->prefix . 'cleversay_knowledge',
        $wpdb->prefix . 'cleversay_kb_variations',
        $wpdb->prefix . 'cleversay_questions',
        $wpdb->prefix . 'cleversay_visitors',
        $wpdb->prefix . 'cleversay_synonyms',
        $wpdb->prefix . 'cleversay_ratings',
        $wpdb->prefix . 'cleversay_inquiries',
        $wpdb->prefix . 'cleversay_categories',
    ];
    
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }
    
    // Delete all plugin options
    delete_option('cleversay_options');
    delete_option('cleversay_stopwords');
    delete_option('cleversay_db_version');
    delete_option('cleversay_api_keys');
    
    // Delete transients
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_cleversay_%'"
    );
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_timeout_cleversay_%'"
    );
    
    // Delete backup files
    $upload_dir = wp_upload_dir();
    $backup_dir = $upload_dir['basedir'] . '/cleversay-backups';
    
    if (is_dir($backup_dir)) {
        $files = glob($backup_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($backup_dir);
    }
    
    // Clear any scheduled cron events
    wp_clear_scheduled_hook('cleversay_daily_cleanup');
    wp_clear_scheduled_hook('cleversay_link_validation');
}
