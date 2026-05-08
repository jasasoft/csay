<?php
/**
 * Import/Export Admin View
 *
 * @package CleverSay
 */

if (!defined('ABSPATH')) {
    exit;
}

$import_export = new \CleverSay\ImportExport();

// Handle legacy import
if (isset($_POST['cleversay_import_legacy']) && check_admin_referer('cleversay_import_legacy')) {
    $credentials = [
        'host' => sanitize_text_field($_POST['legacy_host'] ?? 'localhost'),
        'database' => sanitize_text_field($_POST['legacy_database'] ?? ''),
        'username' => sanitize_text_field($_POST['legacy_username'] ?? ''),
        'password' => $_POST['legacy_password'] ?? '',
        'port' => intval($_POST['legacy_port'] ?? 3306),
        'table_prefix' => sanitize_text_field($_POST['legacy_prefix'] ?? 'ailiza'),
    ];
    
    // Create backup first
    $import_export->create_backup();
    
    $result = $import_export->import_legacy_database($credentials);
    
    if ($result['success']) {
        echo '<div class="notice notice-success"><p>';
        printf(
            esc_html__('Import completed successfully! Imported: %d knowledge entries, %d synonyms, %d AI sources, %d AI chunks.', 'cleversay'),
            $result['imported']['knowledge'] ?? 0,
            $result['imported']['synonyms']  ?? 0,
            $result['imported']['sources']   ?? 0,
            $result['imported']['chunks']    ?? 0
        );
        echo '</p></div>';
        
        if (!empty($result['warnings'])) {
            echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Warnings:', 'cleversay') . '</strong></p><ul>';
            foreach (array_slice($result['warnings'], 0, 10) as $warning) {
                echo '<li>' . esc_html($warning) . '</li>';
            }
            if (count($result['warnings']) > 10) {
                echo '<li>... and ' . (count($result['warnings']) - 10) . ' more</li>';
            }
            echo '</ul></div>';
        }
    } else {
        echo '<div class="notice notice-error"><p><strong>' . esc_html__('Import failed:', 'cleversay') . '</strong></p><ul>';
        foreach ($result['errors'] as $error) {
            echo '<li>' . esc_html($error) . '</li>';
        }
        echo '</ul></div>';
    }
}

// Show import result from admin_init handler (via transient)
$_import_result = get_transient('cleversay_import_result_' . get_current_user_id());
if ($_import_result !== false) {
    delete_transient('cleversay_import_result_' . get_current_user_id());
    if ($_import_result['success']) {
        $imported = $_import_result['imported'] ?? [];
        echo '<div class="notice notice-success is-dismissible"><p>';
        printf(
            esc_html__('Import completed: %d knowledge entries, %d synonyms, %d AI sources inserted, %d skipped (already exist), %d AI chunks inserted, %d updated, %d skipped, %d failed. (File contained %d sources, %d chunks)', 'cleversay'),
            $imported['knowledge']        ?? 0,
            $imported['synonyms']         ?? 0,
            $imported['sources']          ?? 0,
            $imported['skipped_sources']  ?? 0,
            $imported['chunks']           ?? 0,
            $imported['chunks']           ?? 0,
            $imported['skipped_chunks']   ?? 0,
            $imported['failed_chunks']    ?? 0,
            $imported['sources_in_file']  ?? 0,
            $imported['chunks_in_file']   ?? 0
        );
        echo '</p></div>';
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Import failed:', 'cleversay') . ' ';
        echo esc_html(implode(', ', $_import_result['errors'] ?? ['Unknown error']));
        echo '</p></div>';
    }
}

// Handle backup restore
if (isset($_POST['cleversay_restore_backup']) && check_admin_referer('cleversay_restore_backup')) {
    $filename = sanitize_file_name($_POST['backup_file'] ?? '');
    if ($filename) {
        $result = $import_export->restore_backup($filename);
        if ($result['success']) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Backup restored successfully!', 'cleversay') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html(implode(', ', $result['errors'])) . '</p></div>';
        }
    }
}

// Handle backup deletion
if (isset($_GET['delete_backup']) && check_admin_referer('cleversay_delete_backup')) {
    $filename = sanitize_file_name($_GET['delete_backup']);
    $upload_dir = wp_upload_dir();
    $filepath = $upload_dir['basedir'] . '/cleversay-backups/' . $filename;
    if (file_exists($filepath) && unlink($filepath)) {
        echo '<div class="notice notice-success"><p>' . esc_html__('Backup deleted.', 'cleversay') . '</p></div>';
    }
}

// Get available backups
$backups = $import_export->get_backups();
?>

<div class="wrap cleversay-admin cleversay-import-export">
    <h1 class="wp-heading-inline"><?php echo \CleverSay\Icons::render('upload', 16); ?> <?php esc_html_e('Import / Export', 'cleversay'); ?></h1>
    
    <div class="import-export-grid">
        <!-- Export Section -->
        <div class="section-card">
            <h2><?php esc_html_e('Export Data', 'cleversay'); ?></h2>
            <p class="description"><?php esc_html_e('Download your CleverSay data for backup or migration.', 'cleversay'); ?></p>
            
            <div class="export-options">
                <div class="export-option">
                    <h4><?php esc_html_e('Full Export (JSON)', 'cleversay'); ?></h4>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=cleversay-import-export&export=json'), 'cleversay_export')); ?>" 
                       class="button button-primary">
                        <?php esc_html_e('Download Full Backup', 'cleversay'); ?>
                    </a>
                </div>
                
                <div class="export-option">
                    <h4><?php esc_html_e('Knowledge Base (CSV)', 'cleversay'); ?></h4>
                    <p><?php esc_html_e('Export knowledge base entries only.', 'cleversay'); ?></p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-import-export&export=knowledge')); ?>" 
                       class="button">
                        <?php esc_html_e('Download CSV', 'cleversay'); ?>
                    </a>
                </div>
                
                <div class="export-option">
                    <h4><?php esc_html_e('Questions Log (CSV)', 'cleversay'); ?></h4>
                    <p><?php esc_html_e('Export search analytics data.', 'cleversay'); ?></p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-import-export&export=questions')); ?>" 
                       class="button">
                        <?php esc_html_e('Download CSV', 'cleversay'); ?>
                    </a>
                </div>
                
                <div class="export-option">
                    <h4><?php esc_html_e('Synonyms (CSV)', 'cleversay'); ?></h4>
                    <p><?php esc_html_e('Export synonym definitions.', 'cleversay'); ?></p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-import-export&export=synonyms')); ?>" 
                       class="button">
                        <?php esc_html_e('Download CSV', 'cleversay'); ?>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Import File Section -->
        <div class="section-card">
            <h2><?php esc_html_e('Import from File', 'cleversay'); ?></h2>
            <p class="description"><?php esc_html_e('Import data from a backup file or CSV.', 'cleversay'); ?></p>
            
            <form method="post" enctype="multipart/form-data" id="cleversay-import-form">
                <?php wp_nonce_field('cleversay_import_file'); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="import_type"><?php esc_html_e('Import Type', 'cleversay'); ?></label></th>
                        <td>
                            <select name="import_type" id="import_type">
                                <option value="json"><?php esc_html_e('Full Backup (JSON)', 'cleversay'); ?></option>
                                <option value="knowledge"><?php esc_html_e('Knowledge Base (CSV)', 'cleversay'); ?></option>
                                <option value="synonyms"><?php esc_html_e('Synonyms (CSV)', 'cleversay'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="import_file"><?php esc_html_e('Select File', 'cleversay'); ?></label></th>
                        <td>
                            <input type="file" name="import_file" id="import_file" accept=".json,.csv" required>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="button" id="cleversay-preview-btn" class="button button-secondary">
                        <?php esc_html_e('Preview Import', 'cleversay'); ?>
                    </button>
                    <input type="submit" name="cleversay_import_file" id="cleversay-import-submit"
                           class="button button-primary"
                           value="<?php esc_attr_e('Import Now', 'cleversay'); ?>">
                </p>

                <p class="description">
                    <strong><?php esc_html_e('Tip:', 'cleversay'); ?></strong>
                    <?php esc_html_e('Click "Preview Import" first to review what will be added before committing. A backup is created automatically before any import.', 'cleversay'); ?>
                </p>
            </form>

            <!-- Import Preview Panel -->
            <div id="cleversay-import-preview" style="display:none; margin-top:20px;">
                <div class="card" style="max-width:100%; padding:16px;">
                    <h3 style="margin-top:0;"><?php echo \CleverSay\Icons::render('eye', 16); ?> <?php esc_html_e('Import Preview', 'cleversay'); ?></h3>
                    <div id="cleversay-preview-content"></div>
                    <p>
                        <button type="button" id="cleversay-confirm-import" class="button button-primary">
                            <?php esc_html_e('Confirm &amp; Import', 'cleversay'); ?>
                        </button>
                        <button type="button" id="cleversay-cancel-preview" class="button button-secondary" style="margin-left:8px;">
                            <?php esc_html_e('Cancel', 'cleversay'); ?>
                        </button>
                    </p>
                </div>
            </div>

            <script>
            (function($) {
                var previewData = null;

                // Show preview panel when "Preview Import" is clicked
                $('#cleversay-preview-btn').on('click', function() {
                    var file = document.getElementById('import_file').files[0];
                    var type = $('#import_type').val();

                    if (!file) {
                        alert('<?php echo esc_js(__('Please select a file first.', 'cleversay')); ?>');
                        return;
                    }
                    if (type !== 'json') {
                        // CSV preview not yet supported — go straight to import
                        $('#cleversay-import-form').submit();
                        return;
                    }

                    var reader = new FileReader();
                    reader.onload = function(e) {
                        try {
                            previewData = JSON.parse(e.target.result);
                        } catch (err) {
                            alert('<?php echo esc_js(__('Invalid JSON file.', 'cleversay')); ?>');
                            return;
                        }

                        $.ajax({
                            url:  ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'cleversay_preview_import',
                                nonce:  '<?php echo esc_js(wp_create_nonce('cleversay_admin_nonce')); ?>',
                                data:   JSON.stringify(previewData)
                            },
                            beforeSend: function() {
                                $('#cleversay-preview-content').html('<p><?php echo esc_js(__('Analysing…', 'cleversay')); ?></p>');
                                $('#cleversay-import-preview').show();
                            },
                            success: function(response) {
                                if (response.success) {
                                    renderPreview(response.data);
                                } else {
                                    $('#cleversay-preview-content').html('<p class="error">' + (response.data.message || 'Error') + '</p>');
                                }
                            },
                            error: function() {
                                $('#cleversay-preview-content').html('<p class="error"><?php echo esc_js(__('Preview request failed.', 'cleversay')); ?></p>');
                            }
                        });
                    };
                    reader.readAsText(file);
                });

                function renderPreview(p) {
                    var html = '<table class="widefat striped" style="width:auto;min-width:420px;">';
                    html += '<thead><tr><th><?php echo esc_js(__('Section', 'cleversay')); ?></th>'
                          + '<th><?php echo esc_js(__('Total in file', 'cleversay')); ?></th>'
                          + '<th style="color:#00a32a;"><?php echo esc_js(__('New (will be added)', 'cleversay')); ?></th>'
                          + '<th style="color:#dba617;"><?php echo esc_js(__('Duplicates (will be skipped)', 'cleversay')); ?></th></tr></thead><tbody>';

                    var sections = [
                        { key: 'knowledge',  label: '<?php echo esc_js(__('Knowledge entries', 'cleversay')); ?>' },
                        { key: 'synonyms',   label: '<?php echo esc_js(__('Synonyms', 'cleversay')); ?>' },
                        { key: 'stopwords',  label: '<?php echo esc_js(__('Stopwords', 'cleversay')); ?>' },
                    ];

                    sections.forEach(function(s) {
                        var sec = p[s.key] || {};
                        var total = sec.total || 0;
                        if (total === 0) return;
                        html += '<tr><td><strong>' + s.label + '</strong></td>'
                              + '<td>' + total + '</td>'
                              + '<td style="color:#00a32a;font-weight:bold;">+' + (sec.new || 0) + '</td>'
                              + '<td style="color:#dba617;">' + (sec.duplicate || 0) + '</td></tr>';
                    });

                    html += '</tbody></table>';

                    // Sample new knowledge entries
                    if (p.knowledge && p.knowledge.samples && p.knowledge.samples.length) {
                        html += '<h4 style="margin-top:14px;"><?php echo esc_js(__('Sample new entries:', 'cleversay')); ?></h4><ul>';
                        p.knowledge.samples.forEach(function(s) {
                            html += '<li><strong>' + escHtml(s.keyword) + '</strong>'
                                  + (s.sub_keyword ? ' / ' + escHtml(s.sub_keyword) : '')
                                  + (s.question ? ' — <em>' + escHtml(s.question) + '</em>' : '') + '</li>';
                        });
                        html += '</ul>';
                    }

                    if (p.errors && p.errors.length) {
                        html += '<div class="notice notice-error"><ul>';
                        p.errors.forEach(function(e) { html += '<li>' + escHtml(e) + '</li>'; });
                        html += '</ul></div>';
                    }

                    $('#cleversay-preview-content').html(html);
                }

                function escHtml(text) {
                    var d = document.createElement('div');
                    d.textContent = text || '';
                    return d.innerHTML;
                }

                // Confirm import — submit the real form
                $('#cleversay-confirm-import').on('click', function() {
                    $('#cleversay-import-preview').hide();
                    $('#cleversay-import-form').submit();
                });

                // Cancel preview
                $('#cleversay-cancel-preview').on('click', function() {
                    $('#cleversay-import-preview').hide();
                    previewData = null;
                });

            })(jQuery);
            </script>
        </div>
        
        <!-- Legacy Import Section -->
        <div class="section-card">
            <h2><?php esc_html_e('Import from Legacy System', 'cleversay'); ?></h2>
            <p class="description"><?php esc_html_e('Import data from the old CleverSay/Ailiza database directly.', 'cleversay'); ?></p>
            
            <form method="post">
                <?php wp_nonce_field('cleversay_import_legacy'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="legacy_host"><?php esc_html_e('Database Host', 'cleversay'); ?></label></th>
                        <td>
                            <input type="text" name="legacy_host" id="legacy_host" class="regular-text" 
                                   value="localhost" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="legacy_port"><?php esc_html_e('Port', 'cleversay'); ?></label></th>
                        <td>
                            <input type="number" name="legacy_port" id="legacy_port" class="small-text" 
                                   value="3306">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="legacy_database"><?php esc_html_e('Database Name', 'cleversay'); ?></label></th>
                        <td>
                            <input type="text" name="legacy_database" id="legacy_database" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="legacy_username"><?php esc_html_e('Username', 'cleversay'); ?></label></th>
                        <td>
                            <input type="text" name="legacy_username" id="legacy_username" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="legacy_password"><?php esc_html_e('Password', 'cleversay'); ?></label></th>
                        <td>
                            <input type="password" name="legacy_password" id="legacy_password" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="legacy_prefix"><?php esc_html_e('Table Prefix', 'cleversay'); ?></label></th>
                        <td>
                            <input type="text" name="legacy_prefix" id="legacy_prefix" class="regular-text" 
                                   value="ailiza">
                            <p class="description"><?php esc_html_e('The main table name (e.g., "ailiza" for ailiza, ailiza_questions, etc.)', 'cleversay'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="cleversay_import_legacy" class="button button-primary" 
                           value="<?php esc_attr_e('Import from Legacy Database', 'cleversay'); ?>">
                </p>
                
                <div class="legacy-import-info">
                    <h4><?php esc_html_e('What gets imported:', 'cleversay'); ?></h4>
                    <ul>
                        <li><?php esc_html_e('Knowledge base entries (keyword, subkeyword, response, status, hits, rating)', 'cleversay'); ?></li>
                        <li><?php esc_html_e('Synonyms and spell check entries', 'cleversay'); ?></li>
                        <li><?php esc_html_e('Stopwords', 'cleversay'); ?></li>
                        <li><?php esc_html_e('Questions from the last 90 days', 'cleversay'); ?></li>
                    </ul>
                </div>
            </form>
        </div>
        
        <!-- Backups Section -->
        <div class="section-card">
            <h2><?php esc_html_e('Automatic Backups', 'cleversay'); ?></h2>
            <p class="description"><?php esc_html_e('Backups are created automatically before each import.', 'cleversay'); ?></p>
            
            <?php if (!empty($backups)): ?>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Backup', 'cleversay'); ?></th>
                            <th><?php esc_html_e('Date', 'cleversay'); ?></th>
                            <th><?php esc_html_e('Size', 'cleversay'); ?></th>
                            <th class="column-actions"><?php esc_html_e('Actions', 'cleversay'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup): ?>
                            <tr>
                                <td><code><?php echo esc_html($backup['filename']); ?></code></td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $backup['date'])); ?></td>
                                <td><?php echo esc_html(size_format($backup['size'])); ?></td>
                                <td class="column-actions">
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('cleversay_restore_backup'); ?>
                                        <input type="hidden" name="backup_file" value="<?php echo esc_attr($backup['filename']); ?>">
                                        <button type="submit" name="cleversay_restore_backup" class="button button-small"
                                                onclick="return confirm('<?php esc_attr_e('Are you sure? This will replace all current data.', 'cleversay'); ?>')">
                                            <?php esc_html_e('Restore', 'cleversay'); ?>
                                        </button>
                                    </form>
                                    
                                    <a href="<?php echo esc_url(wp_nonce_url(
                                        admin_url('admin.php?page=cleversay-import-export&delete_backup=' . urlencode($backup['filename'])),
                                        'cleversay_delete_backup'
                                    )); ?>" class="button button-small button-link-delete"
                                       onclick="return confirm('<?php esc_attr_e('Delete this backup?', 'cleversay'); ?>')">
                                        <?php esc_html_e('Delete', 'cleversay'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="no-backups"><?php esc_html_e('No backups available yet.', 'cleversay'); ?></p>
            <?php endif; ?>
            
            <p style="margin-top: 15px;">
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=cleversay-import-export&export=json'), 'cleversay_export')); ?>" 
                   class="button">
                    <?php esc_html_e('Create Manual Backup', 'cleversay'); ?>
                </a>
            </p>
        </div>
    </div>
</div>

<style>
.cleversay-import-export {
    max-width: 1200px;
}

.import-export-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.section-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
}

.section-card h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #f0f0f1;
}

.export-options {
    display: grid;
    gap: 20px;
}

.export-option {
    padding: 15px;
    background: #f6f7f7;
    border-radius: 4px;
}

.export-option h4 {
    margin: 0 0 8px;
}

.export-option p {
    margin: 0 0 12px;
    color: #646970;
    font-size: 13px;
}

.legacy-import-info {
    margin-top: 20px;
    padding: 15px;
    background: #f0f6fc;
    border-left: 4px solid #2271b1;
    border-radius: 4px;
}

.legacy-import-info h4 {
    margin: 0 0 10px;
}

.legacy-import-info ul {
    margin: 0;
    padding-left: 20px;
}

.legacy-import-info li {
    margin-bottom: 5px;
}

.no-backups {
    text-align: center;
    color: #646970;
    padding: 30px;
}

@media (max-width: 782px) {
    .import-export-grid {
        grid-template-columns: 1fr;
    }
}
</style>
