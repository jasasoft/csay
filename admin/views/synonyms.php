<?php
/**
 * Synonyms Management View
 *
 * @package CleverSay
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

global $wpdb;

// Pagination
$per_page = 30;
$current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
$offset = ($current_page - 1) * $per_page;

// Filtering
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$type_filter = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';

// Build query
$where = ['1=1'];
$params = [];

if ($search) {
    $where[] = "(canonical_word LIKE %s OR variant_words LIKE %s OR misspellings LIKE %s)";
    $search_like = '%' . $wpdb->esc_like($search) . '%';
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
}

if ($type_filter === 'phrase') {
    $where[] = "is_phrase = 1";
} elseif ($type_filter === 'word') {
    $where[] = "is_phrase = 0";
}

$where_sql = implode(' AND ', $where);

// Get total count
$count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}cleversay_synonyms WHERE $where_sql";
$total_items = empty($params) 
    ? $wpdb->get_var($count_query)
    : $wpdb->get_var($wpdb->prepare($count_query, ...$params));

$total_pages = ceil($total_items / $per_page);

// Get entries
$query = "SELECT * FROM {$wpdb->prefix}cleversay_synonyms WHERE $where_sql ORDER BY canonical_word ASC LIMIT %d OFFSET %d";
$params[] = $per_page;
$params[] = $offset;

$synonyms = $wpdb->get_results($wpdb->prepare($query, ...$params));

// Counts by type
$phrase_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cleversay_synonyms WHERE is_phrase = 1");
$word_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cleversay_synonyms WHERE is_phrase = 0");

$edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
$edit_synonym = null;

if ($edit_id) {
    $edit_synonym = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}cleversay_synonyms WHERE id = %d",
        $edit_id
    ));
}

$base_url = admin_url('admin.php?page=cleversay-synonyms');
?>

<div class="wrap cleversay-admin">
    <h1 class="wp-heading-inline"><?php echo \CleverSay\Icons::render('check-circle', 16); ?> <?php esc_html_e('Synonyms & Spell Check', 'cleversay'); ?></h1>
    
    <hr class="wp-header-end">

    <?php
    // v4.37.7+: legacy import result banner
    if (!empty($_GET['legacy_imported'])) {
        $imp = get_transient('cleversay_legacy_synonym_import_stats');
        if ($imp) {
            delete_transient('cleversay_legacy_synonym_import_stats');
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong><?php esc_html_e('Legacy synonyms imported.', 'cleversay'); ?></strong>
                    <?php
                    printf(
                        esc_html__(
                            '%d total legacy rows. %d newly inserted, %d skipped (canonical already exists), %d failed.',
                            'cleversay'
                        ),
                        (int) ($imp['total_legacy_rows'] ?? 0),
                        (int) ($imp['inserted'] ?? 0),
                        (int) ($imp['skipped_existing'] ?? 0),
                        (int) ($imp['failed'] ?? 0)
                    );
                    ?>
                </p>
                <?php if (!empty($imp['errors'])): ?>
                <details>
                    <summary><?php esc_html_e('Errors', 'cleversay'); ?></summary>
                    <ul>
                        <?php foreach ((array) $imp['errors'] as $err): ?>
                            <li><?php echo esc_html($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
                <?php endif; ?>
            </div>
            <?php
        }
    }
    ?>

    <?php if (isset($_GET['message'])): ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                $messages = [
                    'saved' => __('Synonym saved successfully.', 'cleversay'),
                    'deleted' => __('Synonym deleted successfully.', 'cleversay'),
                    'imported' => __('Synonyms imported successfully.', 'cleversay'),
                ];
                echo esc_html($messages[$_GET['message']] ?? '');
                ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <?php
                $error = $_GET['error'];
                if ($error === 'missing_fields') {
                    esc_html_e('Please enter a Canonical Word and at least one of: Variant Words or Misspellings.', 'cleversay');
                } elseif ($error === 'keyword_conflict') {
                    $conflicts = isset($_GET['conflicts']) ? urldecode($_GET['conflicts']) : '';
                    echo esc_html(sprintf(
                        __('Cannot use these words as synonyms because they are already keywords: %s. This would cause search conflicts.', 'cleversay'),
                        $conflicts
                    ));
                } else {
                    esc_html_e('An error occurred.', 'cleversay');
                }
                ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="cleversay-synonyms-layout">
        <!-- Add/Edit Form -->
        <div class="cleversay-synonyms-form">
            <div class="section-card">
                <h2>
                    <?php echo $edit_synonym 
                        ? esc_html__('Edit Synonym', 'cleversay') 
                        : esc_html__('Add Synonym', 'cleversay'); 
                    ?>
                </h2>
                
                <form method="post" id="cleversay-synonym-form">
                    <?php wp_nonce_field('cleversay_save_synonym', 'cleversay_nonce'); ?>
                    <input type="hidden" name="action" value="cleversay_save_synonym">
                    <input type="hidden" name="synonym_id" value="<?php echo esc_attr($edit_id); ?>">

                    <div class="form-field">
                        <label for="canonical_word">
                            <?php esc_html_e('Canonical Word', 'cleversay'); ?> 
                            <span class="required">*</span>
                        </label>
                        <input type="text" 
                               id="canonical_word" 
                               name="canonical_word" 
                               value="<?php echo $edit_synonym ? esc_attr($edit_synonym->canonical_word) : ''; ?>" 
                               required>
                        <p class="description">
                            <?php esc_html_e('The standard/correct word to normalize to.', 'cleversay'); ?>
                        </p>
                    </div>

                    <div class="form-field">
                        <label for="variant_words">
                            <?php esc_html_e('Variant Words (synonyms)', 'cleversay'); ?>
                        </label>
                        <input type="text" 
                               id="variant_words" 
                               name="variant_words" 
                               value="<?php echo $edit_synonym ? esc_attr($edit_synonym->variant_words) : ''; ?>">
                        <p class="description">
                            <?php esc_html_e('Comma-separated list of synonyms/alternate words.', 'cleversay'); ?>
                        </p>
                    </div>

                    <div class="form-field">
                        <label for="misspellings">
                            <?php esc_html_e('Misspellings', 'cleversay'); ?>
                        </label>
                        <input type="text" 
                               id="misspellings" 
                               name="misspellings" 
                               value="<?php echo $edit_synonym ? esc_attr($edit_synonym->misspellings) : ''; ?>">
                        <p class="description">
                            <?php esc_html_e('Comma-separated list of common misspellings.', 'cleversay'); ?>
                        </p>
                    </div>
                    
                    <p class="form-note">
                        <em><?php esc_html_e('* At least one of Variant Words or Misspellings is required.', 'cleversay'); ?></em>
                    </p>

                    <div class="form-field">
                        <label>
                            <input type="checkbox" 
                                   name="is_phrase" 
                                   value="1" 
                                   <?php checked($edit_synonym->is_phrase ?? 0, 1); ?>>
                            <?php esc_html_e('This is a phrase (multi-word)', 'cleversay'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Phrases are matched before individual words.', 'cleversay'); ?>
                        </p>
                    </div>

                    <div class="form-field">
                        <label>
                            <input type="checkbox" 
                                   name="is_active" 
                                   value="1" 
                                   <?php checked($edit_synonym->is_active ?? 1, 1); ?>>
                            <?php esc_html_e('Active', 'cleversay'); ?>
                        </label>
                    </div>

                    <div class="form-actions">
                        <?php if ($edit_synonym): ?>
                            <a href="<?php echo esc_url($base_url); ?>" class="button">
                                <?php esc_html_e('Cancel', 'cleversay'); ?>
                            </a>
                        <?php endif; ?>
                        <button type="submit" class="button button-primary">
                            <?php echo $edit_synonym 
                                ? esc_html__('Update', 'cleversay') 
                                : esc_html__('Add Synonym', 'cleversay'); 
                            ?>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Quick Add -->
            <div class="section-card">
                <h2><?php esc_html_e('Quick Add Multiple', 'cleversay'); ?></h2>
                <form method="post" id="cleversay-bulk-synonym-form">
                    <?php wp_nonce_field('cleversay_bulk_synonyms', 'cleversay_nonce'); ?>
                    <input type="hidden" name="action" value="cleversay_bulk_synonyms">
                    
                    <div class="form-field">
                        <label for="bulk_synonyms"><?php esc_html_e('Synonyms (one per line)', 'cleversay'); ?></label>
                        <textarea id="bulk_synonyms" 
                                  name="bulk_synonyms" 
                                  rows="8" 
                                  placeholder="<?php esc_attr_e("misspeling => misspelling\ncolour => color\nwanna => want to", 'cleversay'); ?>"></textarea>
                        <p class="description">
                            <?php esc_html_e('Format: original => replacement', 'cleversay'); ?>
                        </p>
                    </div>

                    <button type="submit" class="button">
                        <?php esc_html_e('Add All', 'cleversay'); ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- List -->
        <div class="cleversay-synonyms-list cleversay-table-card" style="padding:0;overflow:hidden;">
            <!-- Filters -->
            <div class="cleversay-filters">
                <ul class="subsubsub">
                    <li>
                        <a href="<?php echo esc_url($base_url); ?>" 
                           <?php echo !$type_filter ? 'class="current"' : ''; ?>>
                            <?php esc_html_e('All', 'cleversay'); ?>
                            <span class="count">(<?php echo esc_html($total_items); ?>)</span>
                        </a> |
                    </li>
                    <li>
                        <a href="<?php echo esc_url(add_query_arg('type', 'word', $base_url)); ?>"
                           <?php echo $type_filter === 'word' ? 'class="current"' : ''; ?>>
                            <?php esc_html_e('Words', 'cleversay'); ?>
                            <span class="count">(<?php echo esc_html($word_count); ?>)</span>
                        </a> |
                    </li>
                    <li>
                        <a href="<?php echo esc_url(add_query_arg('type', 'phrase', $base_url)); ?>"
                           <?php echo $type_filter === 'phrase' ? 'class="current"' : ''; ?>>
                            <?php esc_html_e('Phrases', 'cleversay'); ?>
                            <span class="count">(<?php echo esc_html($phrase_count); ?>)</span>
                        </a>
                    </li>
                </ul>

                <form method="get" class="search-box">
                    <input type="hidden" name="page" value="cleversay-synonyms">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" 
                           placeholder="<?php esc_attr_e('Search...', 'cleversay'); ?>">
                    <button type="submit" class="button"><?php esc_html_e('Search', 'cleversay'); ?></button>
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="manage-column column-cb check-column">
                            <input type="checkbox" id="cb-select-all">
                        </td>
                        <th class="column-canonical"><?php esc_html_e('Canonical Word', 'cleversay'); ?></th>
                        <th class="column-variants"><?php esc_html_e('Variants / Misspellings', 'cleversay'); ?></th>
                        <th class="column-type"><?php esc_html_e('Type', 'cleversay'); ?></th>
                        <th class="column-status"><?php esc_html_e('Status', 'cleversay'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($synonyms)): ?>
                        <tr>
                            <td colspan="5" class="no-items">
                                <?php esc_html_e('No synonyms found.', 'cleversay'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($synonyms as $synonym): ?>
                            <tr <?php echo $edit_id === (int)$synonym->id ? 'class="editing"' : ''; ?>>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="synonym_ids[]" value="<?php echo esc_attr($synonym->id); ?>">
                                </th>
                                <td class="column-canonical has-row-actions">
                                    <strong><?php echo esc_html($synonym->canonical_word); ?></strong>
                                    <div class="row-actions">
                                        <span class="edit">
                                            <a href="<?php echo esc_url(add_query_arg('edit', $synonym->id, $base_url)); ?>">
                                                <?php esc_html_e('Edit', 'cleversay'); ?>
                                            </a> | 
                                        </span>
                                        <span class="trash">
                                            <a href="<?php echo esc_url(wp_nonce_url(
                                                add_query_arg(['action' => 'delete', 'id' => $synonym->id], $base_url),
                                                'delete_synonym_' . $synonym->id
                                            )); ?>" 
                                               class="submitdelete"
                                               onclick="return confirm('<?php esc_attr_e('Delete this synonym?', 'cleversay'); ?>');">
                                                <?php esc_html_e('Delete', 'cleversay'); ?>
                                            </a>
                                        </span>
                                    </div>
                                </td>
                                <td class="column-variants">
                                    <?php 
                                    $all_variants = [];
                                    if (!empty($synonym->variant_words)) {
                                        $all_variants = array_merge($all_variants, array_map('trim', explode(',', $synonym->variant_words)));
                                    }
                                    if (!empty($synonym->misspellings)) {
                                        $misspellings = array_map('trim', explode(',', $synonym->misspellings));
                                        $all_variants = array_merge($all_variants, array_map(fn($m) => "<em>{$m}</em>", $misspellings));
                                    }
                                    echo wp_kses(implode(', ', $all_variants), ['em' => []]);
                                    ?>
                                </td>
                                <td class="column-type">
                                    <span class="type-badge type-<?php echo $synonym->is_phrase ? 'phrase' : 'word'; ?>">
                                        <?php echo $synonym->is_phrase 
                                            ? esc_html__('Phrase', 'cleversay') 
                                            : esc_html__('Word', 'cleversay'); 
                                        ?>
                                    </span>
                                </td>
                                <td class="column-status">
                                    <?php if ($synonym->is_active): ?>
                                        <span class="status-active">●</span>
                                    <?php else: ?>
                                        <span class="status-inactive">○</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

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
                    ]);
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // v4.37.7+: tools section
    $legacy_count = function_exists('CleverSay\\cleversay_legacy_synonyms')
        ? count(\CleverSay\cleversay_legacy_synonyms())
        : 0;
    if ($legacy_count > 0):
    ?>
    <div style="margin-top: 32px; padding: 16px 20px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;">
        <h2 style="margin-top:0;"><?php esc_html_e('Re-import legacy synonyms', 'cleversay'); ?></h2>
        <p>
            <?php
            printf(
                esc_html__(
                    'A bundled list of %d legacy synonym entries (carried over from the original CleverSay site\'s spellcheck table) is available. Importing is non-destructive — any synonym whose canonical word already exists in your table is skipped, so your edits are never overwritten.',
                    'cleversay'
                ),
                $legacy_count
            );
            ?>
        </p>
        <p class="description">
            <?php esc_html_e('Most installations don\'t need this — the upgrade to 4.37.7 ran the import once automatically. Use this only if you\'ve deleted some legacy rows and want them back, or if the auto-import didn\'t fire on your install.', 'cleversay'); ?>
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="cleversay_import_legacy_synonyms">
            <?php wp_nonce_field('cleversay_import_legacy_synonyms'); ?>
            <button type="submit" class="button">
                <?php esc_html_e('Re-import legacy synonyms', 'cleversay'); ?>
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<style>
.cleversay-synonyms-layout {
    display: grid;
    grid-template-columns: 360px 1fr;
    gap: 20px;
    margin-top: 4px;
}

/* section-card handles .cleversay-form-card styles */
.cleversay-form-card h2 { font-size: 14.5px; }

.form-field { margin-bottom: 16px; }
.form-field label { display: block; margin-bottom: 5px; font-weight: 600; font-size: 13px; }
.form-field input[type="text"],
.form-field textarea { width: 100%; }
.form-actions { display: flex; gap: 10px; justify-content: flex-end; }

.column-canonical { width: 25%; }
.column-variants  { width: 40%; }
.column-type      { width: 15%; }
.column-status    { width: 10%; }
.column-variants em { color: var(--cs-accent, #0A84FF); font-style: italic; }

.type-badge { display: inline-flex; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.type-word   { background: rgba(10,132,255,0.1);  color: #0055AA; }
.type-phrase { background: rgba(175,82,222,0.1);  color: #7B1FA2; }

tr.editing { background: rgba(255,159,10,0.06) !important; }
.required  { color: var(--cs-danger, #FF3B30); }

.form-note {
    margin: 10px 0 0;
    padding: 10px 14px;
    background: var(--cs-accent-light, rgba(10,132,255,0.08));
    border-left: 3px solid var(--cs-accent, #0A84FF);
    border-radius: 0 6px 6px 0;
    font-size: 12px;
    color: var(--cs-text-secondary, #515154);
}

@media screen and (max-width: 900px) {
    .cleversay-synonyms-layout { grid-template-columns: 1fr; }
}
</style>
