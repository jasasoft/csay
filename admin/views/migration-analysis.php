<?php
/**
 * Migration & Restoration page (v4.34.0+).
 *
 * Each row in `cleversay_knowledge` is its own phrase group. The
 * migration attaches variations as rows in `cleversay_kb_variations`
 * keyed to the row's id, and (when ailiza data is available)
 * restores reuse pointers and the variation arrays that were lost
 * in the original WordPress refactor.
 *
 * Two modes:
 *   - Restoration mode: ailiza/ailiza_rqs tables present in the WP
 *     database. Each cleversay_knowledge row is matched by
 *     (keyword, sub_keyword) and enriched with the original
 *     variations and reuse fields.
 *   - Basic mode: ailiza tables absent. Each row gets its existing
 *     `question` text attached as a single variation. Reuse stays
 *     as-is (whatever's on the row already).
 *
 * Pattern strings are NEVER modified. Live matching behavior is
 * preserved 1:1.
 *
 * @package CleverSay
 */

if (!defined('ABSPATH')) exit;

global $wpdb;

$kb_table   = $wpdb->prefix . 'cleversay_knowledge';
$vars_table = $wpdb->prefix . 'cleversay_kb_variations';

// Pull the success-banner stats written by the admin-post handler.
$migration_stats = null;
if (!empty($_GET['migrated'])) {
    $migration_stats = get_transient('cleversay_migration_stats');
    if ($migration_stats) {
        delete_transient('cleversay_migration_stats');
    }
}
$reuse_restore_stats = null;
if (!empty($_GET['reuse_restored'])) {
    $reuse_restore_stats = get_transient('cleversay_reuse_restore_stats');
    if ($reuse_restore_stats) {
        delete_transient('cleversay_reuse_restore_stats');
    }
}

// Detection: do we have ailiza data?
$ailiza_present = false;
$ailiza_count   = 0;
$rqs_count      = 0;
if ($wpdb->get_var("SHOW TABLES LIKE 'ailiza'") && $wpdb->get_var("SHOW TABLES LIKE 'ailiza_rqs'")) {
    $ailiza_present = true;
    $ailiza_count   = (int) $wpdb->get_var("SELECT COUNT(*) FROM ailiza");
    $rqs_count      = (int) $wpdb->get_var("SELECT COUNT(*) FROM ailiza_rqs");
}

// Survey current state.
$rows = $wpdb->get_results(
    "SELECT id, keyword, sub_keyword, question, reuse_response
       FROM {$kb_table}
      ORDER BY id ASC",
    ARRAY_A
);
$rows_with_vars = $wpdb->get_col("SELECT DISTINCT knowledge_id FROM {$vars_table}");
$has_variations = array_fill_keys(array_map('intval', $rows_with_vars), true);

// Build an in-memory ailiza index for the preview (read-only).
$ailiza_idx = [];
if ($ailiza_present) {
    $ailiza_rows = $wpdb->get_results("
        SELECT a.keyword, a.subkeyword, a.reuse, a.rkey, a.rsubkey, r.rq
          FROM ailiza a
     LEFT JOIN ailiza_rqs r ON a.id = r.rqid
    ", ARRAY_A);
    foreach ((array) $ailiza_rows as $a) {
        $ailiza_idx[$a['keyword'] . '|' . $a['subkeyword']] = $a;
    }
}

// Categorize each row for the preview.
$buckets = [
    'aadefault'         => [],
    'already_migrated'  => [],
    'matched_multi'     => [],  // ailiza match with 2+ variations — real recovery
    'matched_single'    => [],  // ailiza match with 1 variation — formalize only
    'matched_no_var'    => [],  // ailiza match but rq empty — fallback to question
    'unmatched'         => [],  // no ailiza match — fallback to question
    'no_question'       => [],  // not migratable — no variation source
];

$total_variations_to_insert = 0;
$total_reuse_to_restore     = 0;

foreach ($rows as $r) {
    $sub = strtolower(trim($r['sub_keyword']));
    if ($sub === 'aadefault' || $sub === '') {
        $buckets['aadefault'][] = $r;
        continue;
    }

    if (isset($has_variations[(int) $r['id']])) {
        $buckets['already_migrated'][] = $r;
        continue;
    }

    $key      = $r['keyword'] . '|' . $r['sub_keyword'];
    $matched  = $ailiza_present && isset($ailiza_idx[$key]);
    $a        = $matched ? $ailiza_idx[$key] : null;
    $vars     = [];

    if ($matched && $a['rq'] !== null && $a['rq'] !== '') {
        foreach (explode('|', (string) $a['rq']) as $p) {
            $p = trim($p);
            if ($p !== '' && strlen($p) >= 3 && !in_array($p, $vars, true)) {
                $vars[] = $p;
            }
        }
    }

    $fallback_q = trim((string) ($r['question'] ?? ''));
    $will_apply_reuse = false;

    $entry = [
        'row'        => $r,
        'ailiza'     => $a,
        'variations' => $vars,
        'fallback'   => '',
    ];

    if ($matched) {
        if (count($vars) >= 2) {
            $buckets['matched_multi'][] = $entry;
        } elseif (count($vars) === 1) {
            $buckets['matched_single'][] = $entry;
        } else {
            // Empty rq — fall back to question.
            if ($fallback_q !== '' && strlen($fallback_q) >= 3) {
                $entry['fallback'] = $fallback_q;
                $entry['variations'] = [$fallback_q];
                $buckets['matched_no_var'][] = $entry;
            } else {
                $buckets['no_question'][] = $entry;
                continue; // no variations to insert
            }
        }

        if ($a['reuse'] === 'yes' && (string) $a['rkey'] !== '') {
            $will_apply_reuse = true;
            $total_reuse_to_restore++;
        }
    } else {
        // Unmatched.
        if ($fallback_q !== '' && strlen($fallback_q) >= 3) {
            $entry['fallback']   = $fallback_q;
            $entry['variations'] = [$fallback_q];
            $buckets['unmatched'][] = $entry;
        } else {
            $buckets['no_question'][] = $entry;
            continue;
        }
    }

    $total_variations_to_insert += count($entry['variations']);
}

$counts = array_map('count', $buckets);
$total_to_migrate =
      $counts['matched_multi']
    + $counts['matched_single']
    + $counts['matched_no_var']
    + $counts['unmatched'];

// v4.36.1+: independent count of rows that have a missing reuse
// pointer — i.e., the ailiza row says reuse=yes with rkey set, but
// the cleversay_knowledge row has reuse_response=0. Pre-4.36.1 the
// corrected migration's idempotency check would skip these rows
// because they already had variations attached, so the reuse
// pointer never got restored. This count drives the "Restore reuse
// pointers" button below.
$reuse_pointers_missing = 0;
$reuse_pointers_set     = 0;
if ($ailiza_present) {
    foreach ($rows as $r) {
        $sub = strtolower(trim((string) $r['sub_keyword']));
        if ($sub === 'aadefault' || $sub === '') continue;
        $key = $r['keyword'] . '|' . $r['sub_keyword'];
        if (!isset($ailiza_idx[$key])) continue;
        $a = $ailiza_idx[$key];
        if ($a['reuse'] !== 'yes' || $a['rkey'] === '') continue;
        if (!empty($r['reuse_response'])) {
            $reuse_pointers_set++;
        } else {
            $reuse_pointers_missing++;
        }
    }
}
?>
<div class="wrap cleversay-migration">
    <h1><?php esc_html_e('Migration & Restoration', 'cleversay'); ?></h1>

    <?php if ($migration_stats): ?>
        <div class="notice notice-success">
            <p>
                <strong><?php esc_html_e('Migration complete.', 'cleversay'); ?></strong>
                <?php
                printf(
                    esc_html__(
                        'Restoration mode: %s. %d row(s) processed: %d already migrated, %d aadefault skipped, %d matched (with variations), %d matched (no variations in source), %d unmatched fallback. %d variation(s) inserted, %d reuse pointer(s) restored. %d failed.',
                        'cleversay'
                    ),
                    !empty($migration_stats['restoration_mode']) ? esc_html__('on', 'cleversay') : esc_html__('off', 'cleversay'),
                    (int) ($migration_stats['total_rows'] ?? 0),
                    (int) ($migration_stats['already_migrated'] ?? 0),
                    (int) ($migration_stats['aadefault_skipped'] ?? 0),
                    (int) ($migration_stats['matched_with_variations'] ?? 0),
                    (int) ($migration_stats['matched_no_variations'] ?? 0),
                    (int) ($migration_stats['unmatched_fallback'] ?? 0),
                    (int) ($migration_stats['variations_inserted'] ?? 0),
                    (int) ($migration_stats['reuse_pointers_restored'] ?? 0),
                    (int) ($migration_stats['failed'] ?? 0)
                );
                ?>
            </p>
            <?php if (!empty($migration_stats['errors'])): ?>
                <details>
                    <summary><?php esc_html_e('Errors', 'cleversay'); ?></summary>
                    <ul style="margin: 8px 0 8px 20px;">
                        <?php foreach ($migration_stats['errors'] as $e): ?>
                            <li><code><?php echo esc_html((string) $e); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <p class="description">
        <?php esc_html_e(
            'Each row in cleversay_knowledge is its own phrase group. This tool attaches Question Variations to each row and (when source data is available) restores the variation arrays and reuse pointers that were lost in the original WordPress refactor.',
            'cleversay'
        ); ?>
    </p>

    <!-- Restoration-mode banner -->
    <?php if ($ailiza_present): ?>
        <div class="notice notice-info inline cs-mig-mode-banner">
            <p>
                <strong><?php esc_html_e('Restoration mode: ON', 'cleversay'); ?></strong> —
                <?php
                printf(
                    esc_html__('detected ailiza (%d rows) and ailiza_rqs (%d rows). Migration will restore lost variations and reuse pointers from the source data.', 'cleversay'),
                    (int) $ailiza_count,
                    (int) $rqs_count
                );
                ?>
            </p>
        </div>
    <?php else: ?>
        <div class="notice notice-warning inline cs-mig-mode-banner">
            <p>
                <strong><?php esc_html_e('Restoration mode: OFF', 'cleversay'); ?></strong> —
                <?php esc_html_e(
                    'no ailiza/ailiza_rqs tables found in this database. Migration will run in basic mode: each row\'s existing question text gets attached as a single variation, and reuse pointers stay as-is.',
                    'cleversay'
                ); ?>
            </p>
            <p style="margin-top: 8px;">
                <?php esc_html_e(
                    'To enable Restoration mode: import the original ailiza database dump into this WP database via phpMyAdmin (the dump creates the tables as `ailiza` and `ailiza_rqs`). Once imported, refresh this page.',
                    'cleversay'
                ); ?>
            </p>
        </div>
    <?php endif; ?>

    <p class="description">
        <strong><?php esc_html_e('Backup recommended.', 'cleversay'); ?></strong>
        <?php esc_html_e(
            'This writes to the database (INSERT into cleversay_kb_variations and UPDATE on cleversay_knowledge for reuse fields). Pattern strings are never modified. Take a backup before clicking apply.',
            'cleversay'
        ); ?>
    </p>

    <!-- Summary -->
    <div class="cs-mig-summary">
        <div class="cs-mig-stat">
            <div class="cs-mig-stat-num"><?php echo (int) count($rows); ?></div>
            <div class="cs-mig-stat-label"><?php esc_html_e('Rows total', 'cleversay'); ?></div>
        </div>
        <div class="cs-mig-stat cs-mig-eligible">
            <div class="cs-mig-stat-num"><?php echo (int) $total_to_migrate; ?></div>
            <div class="cs-mig-stat-label"><?php esc_html_e('Rows to migrate', 'cleversay'); ?></div>
        </div>
        <div class="cs-mig-stat cs-mig-vars">
            <div class="cs-mig-stat-num"><?php echo (int) $total_variations_to_insert; ?></div>
            <div class="cs-mig-stat-label"><?php esc_html_e('Variations to insert', 'cleversay'); ?></div>
        </div>
        <?php if ($ailiza_present): ?>
        <div class="cs-mig-stat cs-mig-reuse">
            <div class="cs-mig-stat-num"><?php echo (int) $total_reuse_to_restore; ?></div>
            <div class="cs-mig-stat-label"><?php esc_html_e('Reuse pointers to restore', 'cleversay'); ?></div>
        </div>
        <?php endif; ?>
        <div class="cs-mig-stat cs-mig-migrated">
            <div class="cs-mig-stat-num"><?php echo (int) $counts['already_migrated']; ?></div>
            <div class="cs-mig-stat-label"><?php esc_html_e('Already migrated', 'cleversay'); ?></div>
        </div>
        <div class="cs-mig-stat cs-mig-default">
            <div class="cs-mig-stat-num"><?php echo (int) $counts['aadefault']; ?></div>
            <div class="cs-mig-stat-label"><?php esc_html_e('aadefault (skip)', 'cleversay'); ?></div>
        </div>
    </div>

    <?php if ($total_to_migrate > 0): ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
          class="cs-mig-apply-form"
          onsubmit="return confirm('<?php echo esc_js(sprintf(
            __('Apply migration to %d row(s)? This writes %d variation row(s)%s. You should have a backup before continuing.', 'cleversay'),
            $total_to_migrate,
            $total_variations_to_insert,
            $ailiza_present ? sprintf(__(' and restores %d reuse pointer(s)', 'cleversay'), $total_reuse_to_restore) : ''
          )); ?>');">
        <input type="hidden" name="action" value="cleversay_apply_migration">
        <?php wp_nonce_field('cleversay_apply_migration'); ?>
        <button type="submit" class="button button-primary button-hero">
            <?php
            printf(
                esc_html__('Apply migration to %d row(s)', 'cleversay'),
                (int) $total_to_migrate
            );
            ?>
        </button>
        <?php if (!$ailiza_present): ?>
            <span class="cs-mig-warn">
                <?php esc_html_e('(Restoration mode is off — basic migration only.)', 'cleversay'); ?>
            </span>
        <?php endif; ?>
    </form>
    <?php endif; ?>

    <?php if ($reuse_restore_stats): ?>
        <?php if (!empty($reuse_restore_stats['ailiza_present'])): ?>
        <div class="notice notice-success">
            <p>
                <strong><?php esc_html_e('Reuse pointers restored.', 'cleversay'); ?></strong>
                <?php
                printf(
                    esc_html__(
                        '%d row(s) checked. %d already had a pointer set, %d newly restored, %d had no ailiza match, %d ailiza rows said reuse=no, %d failed.',
                        'cleversay'
                    ),
                    (int) ($reuse_restore_stats['total_rows'] ?? 0),
                    (int) ($reuse_restore_stats['already_set'] ?? 0),
                    (int) ($reuse_restore_stats['restored'] ?? 0),
                    (int) ($reuse_restore_stats['no_ailiza_match'] ?? 0),
                    (int) ($reuse_restore_stats['ailiza_says_no_reuse'] ?? 0),
                    (int) ($reuse_restore_stats['failed'] ?? 0)
                );
                ?>
            </p>
            <?php if (!empty($reuse_restore_stats['errors'])): ?>
                <details>
                    <summary><?php esc_html_e('Errors', 'cleversay'); ?></summary>
                    <ul>
                        <?php foreach ((array) $reuse_restore_stats['errors'] as $err): ?>
                            <li><?php echo esc_html($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e('Reuse pointer restore did nothing.', 'cleversay'); ?></strong>
                <?php esc_html_e('The ailiza tables are not present in this database, so there is no source data to restore from. Import the legacy ailiza dump first, then come back to this page.', 'cleversay'); ?>
            </p>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($ailiza_present): ?>
    <div class="cs-mig-reuse-section" style="margin: 24px 0; padding: 16px 20px; background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; border-radius: 4px;">
        <h2 style="margin-top:0;"><?php esc_html_e('Reuse pointers (independent check)', 'cleversay'); ?></h2>
        <p>
            <?php
            printf(
                esc_html__(
                    'Of the %d ailiza rows marked reuse=yes, %d already have their pointer set in this database and %d are missing.',
                    'cleversay'
                ),
                (int) ($reuse_pointers_set + $reuse_pointers_missing),
                (int) $reuse_pointers_set,
                (int) $reuse_pointers_missing
            );
            ?>
        </p>
        <?php if ($reuse_pointers_missing > 0): ?>
            <p class="description">
                <?php esc_html_e('A reuse pointer tells the chat to serve the response from a DIFFERENT entry. Entries with a missing pointer will fall through to the AI fallback (or display empty content) instead of serving the real answer. This can happen when a v4.33.0 migration ran before v4.34.0 — the corrected migration short-circuited on the idempotency check and skipped the reuse-restoration step. v4.36.1+ fixed this for future runs, but the existing rows still need a one-shot restoration.', 'cleversay'); ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  class="cs-mig-apply-form"
                  onsubmit="return confirm('<?php echo esc_js(sprintf(
                    __('Restore %d missing reuse pointer(s)? This only touches the reuse_response / reuse_keyword / reuse_sub_keyword fields — variations, responses, and statuses are untouched. Idempotent, safe to re-run.', 'cleversay'),
                    $reuse_pointers_missing
                  )); ?>');">
                <input type="hidden" name="action" value="cleversay_restore_reuse_pointers">
                <?php wp_nonce_field('cleversay_restore_reuse_pointers'); ?>
                <button type="submit" class="button button-secondary">
                    <?php
                    printf(
                        esc_html__('Restore %d reuse pointer(s)', 'cleversay'),
                        (int) $reuse_pointers_missing
                    );
                    ?>
                </button>
            </form>
        <?php else: ?>
            <p style="color: #00a32a;">
                <?php esc_html_e('All reuse pointers are present. No action needed.', 'cleversay'); ?>
            </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Multi-variation matches: real data recovery -->
    <?php if ($counts['matched_multi'] > 0): ?>
    <details class="cs-mig-section" open>
        <summary>
            <span class="cs-mig-badge cs-mig-badge-restore"><?php esc_html_e('FULL RESTORE', 'cleversay'); ?></span>
            <?php
            printf(
                esc_html(_n(
                    '%d row gets multiple variations restored',
                    '%d rows get multiple variations restored',
                    $counts['matched_multi'],
                    'cleversay'
                )),
                (int) $counts['matched_multi']
            );
            ?>
        </summary>
        <p class="description">
            <?php esc_html_e(
                'These rows match an ailiza entry whose ailiza_rqs.rq contains 2 or more variations. Every variation gets attached to the row in cleversay_kb_variations.',
                'cleversay'
            ); ?>
        </p>
        <?php foreach (array_slice($buckets['matched_multi'], 0, 20) as $entry):
            $r = $entry['row'];
            $edit_url = admin_url(
                'admin.php?page=cleversay-knowledge&action=edit-phrase-group'
                . '&keyword=' . urlencode($r['keyword'])
                . '&group_id=' . (int) $r['id']
            );
        ?>
            <div class="cs-mig-card">
                <div class="cs-mig-card-head">
                    <div class="cs-mig-card-title">
                        <strong><?php echo esc_html($r['keyword']); ?></strong>
                        <code class="cs-mig-mini-pattern"><?php echo esc_html($r['sub_keyword']); ?></code>
                        <span class="cs-mig-meta">id #<?php echo (int) $r['id']; ?></span>
                        <span class="cs-mig-pill"><?php
                            printf(esc_html__('+%d variations', 'cleversay'), count($entry['variations']));
                        ?></span>
                        <?php if ($entry['ailiza']['reuse'] === 'yes' && $entry['ailiza']['rkey'] !== ''): ?>
                            <span class="cs-mig-pill cs-mig-pill-reuse"><?php
                                printf(
                                    esc_html__('reuse → %s/%s', 'cleversay'),
                                    esc_html($entry['ailiza']['rkey']),
                                    esc_html($entry['ailiza']['rsubkey'])
                                );
                            ?></span>
                        <?php endif; ?>
                    </div>
                    <a class="button button-small" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Open', 'cleversay'); ?></a>
                </div>
                <div class="cs-mig-card-row">
                    <div class="cs-mig-label"><?php esc_html_e('Variations:', 'cleversay'); ?></div>
                    <div>
                        <?php foreach ($entry['variations'] as $v): ?>
                            <div class="cs-mig-var-row">
                                <span class="cs-mig-var-icon">·</span>
                                <span class="cs-mig-var-text"><?php echo esc_html($v); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if ($counts['matched_multi'] > 20): ?>
            <p class="description" style="padding: 0 18px 12px;">
                <?php
                printf(
                    esc_html__('… and %d more.', 'cleversay'),
                    (int) ($counts['matched_multi'] - 20)
                );
                ?>
            </p>
        <?php endif; ?>
    </details>
    <?php endif; ?>

    <!-- Single-variation matches: schema formalization -->
    <?php if ($counts['matched_single'] > 0): ?>
    <details class="cs-mig-section">
        <summary>
            <span class="cs-mig-badge cs-mig-badge-formalize"><?php esc_html_e('FORMALIZE', 'cleversay'); ?></span>
            <?php
            printf(
                esc_html(_n(
                    '%d row has a single variation in source — schema update only',
                    '%d rows have single variations in source — schema update only',
                    $counts['matched_single'],
                    'cleversay'
                )),
                (int) $counts['matched_single']
            );
            ?>
        </summary>
        <p class="description">
            <?php esc_html_e(
                'These rows match an ailiza entry but its variation field has just one entry. They get the schema update without gaining new data.',
                'cleversay'
            ); ?>
        </p>
        <?php foreach (array_slice($buckets['matched_single'], 0, 8) as $entry):
            $r = $entry['row'];
        ?>
            <div class="cs-mig-card cs-mig-card-compact">
                <strong><?php echo esc_html($r['keyword']); ?></strong>
                <code class="cs-mig-mini-pattern"><?php echo esc_html($r['sub_keyword']); ?></code>
                <span class="cs-mig-meta">id #<?php echo (int) $r['id']; ?></span>
                <span class="cs-mig-var-text"><?php echo esc_html($entry['variations'][0]); ?></span>
                <?php if ($entry['ailiza']['reuse'] === 'yes' && $entry['ailiza']['rkey'] !== ''): ?>
                    <span class="cs-mig-pill cs-mig-pill-reuse"><?php
                        printf(
                            esc_html__('reuse → %s/%s', 'cleversay'),
                            esc_html($entry['ailiza']['rkey']),
                            esc_html($entry['ailiza']['rsubkey'])
                        );
                    ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if ($counts['matched_single'] > 8): ?>
            <p class="description" style="padding: 0 18px 12px;">
                <?php printf(esc_html__('… and %d more.', 'cleversay'), (int) ($counts['matched_single'] - 8)); ?>
            </p>
        <?php endif; ?>
    </details>
    <?php endif; ?>

    <!-- Matched but no rq, fall back to question -->
    <?php if ($counts['matched_no_var'] > 0): ?>
    <details class="cs-mig-section">
        <summary>
            <span class="cs-mig-badge cs-mig-badge-fallback"><?php esc_html_e('FALLBACK (matched)', 'cleversay'); ?></span>
            <?php
            printf(
                esc_html__('%d row matched but source had no variations — using question column', 'cleversay'),
                (int) $counts['matched_no_var']
            );
            ?>
        </summary>
        <?php foreach (array_slice($buckets['matched_no_var'], 0, 6) as $entry):
            $r = $entry['row'];
        ?>
            <div class="cs-mig-card cs-mig-card-compact">
                <strong><?php echo esc_html($r['keyword']); ?></strong>
                <code class="cs-mig-mini-pattern"><?php echo esc_html($r['sub_keyword']); ?></code>
                <span class="cs-mig-meta">id #<?php echo (int) $r['id']; ?></span>
                <span class="cs-mig-var-text"><?php echo esc_html($entry['fallback']); ?></span>
            </div>
        <?php endforeach; ?>
    </details>
    <?php endif; ?>

    <!-- Unmatched, fallback to question -->
    <?php if ($counts['unmatched'] > 0): ?>
    <details class="cs-mig-section">
        <summary>
            <span class="cs-mig-badge cs-mig-badge-fallback"><?php esc_html_e('FALLBACK (unmatched)', 'cleversay'); ?></span>
            <?php
            printf(
                esc_html__('%d row not in source data — using question column', 'cleversay'),
                (int) $counts['unmatched']
            );
            ?>
        </summary>
        <p class="description">
            <?php esc_html_e('These were added after the original WP refactor, so they have no ailiza counterpart. Their existing question becomes a single variation.', 'cleversay'); ?>
        </p>
        <?php foreach ($buckets['unmatched'] as $entry):
            $r = $entry['row'];
        ?>
            <div class="cs-mig-card cs-mig-card-compact">
                <strong><?php echo esc_html($r['keyword']); ?></strong>
                <code class="cs-mig-mini-pattern"><?php echo esc_html($r['sub_keyword']); ?></code>
                <span class="cs-mig-meta">id #<?php echo (int) $r['id']; ?></span>
                <span class="cs-mig-var-text"><?php echo esc_html($entry['fallback']); ?></span>
            </div>
        <?php endforeach; ?>
    </details>
    <?php endif; ?>

    <!-- No question text — can't migrate -->
    <?php if ($counts['no_question'] > 0): ?>
    <details class="cs-mig-section">
        <summary>
            <span class="cs-mig-badge cs-mig-badge-empty"><?php esc_html_e('CANNOT MIGRATE', 'cleversay'); ?></span>
            <?php
            printf(
                esc_html__('%d row has no usable variation source', 'cleversay'),
                (int) $counts['no_question']
            );
            ?>
        </summary>
        <p class="description">
            <?php esc_html_e('These have an empty question column AND no source variations. Open each entry and provide a phrase, or delete it if no longer needed.', 'cleversay'); ?>
        </p>
        <?php foreach ($buckets['no_question'] as $entry):
            $r = $entry['row'];
            $edit_url = admin_url(
                'admin.php?page=cleversay-knowledge&action=edit-phrase-group'
                . '&keyword=' . urlencode($r['keyword'])
                . '&group_id=' . (int) $r['id']
            );
        ?>
            <div class="cs-mig-card cs-mig-card-compact">
                <strong><?php echo esc_html($r['keyword']); ?></strong>
                <code class="cs-mig-mini-pattern"><?php echo esc_html($r['sub_keyword']); ?></code>
                <span class="cs-mig-meta">id #<?php echo (int) $r['id']; ?></span>
                <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Open →', 'cleversay'); ?></a>
            </div>
        <?php endforeach; ?>
    </details>
    <?php endif; ?>
</div>

<style>
.cleversay-migration h1 { display: flex; align-items: center; gap: 12px; }

.cs-mig-mode-banner { padding: 10px 14px; }

.cs-mig-summary {
    display: flex; flex-wrap: wrap; gap: 12px;
    margin: 20px 0 24px;
}
.cs-mig-stat {
    flex: 1 1 140px; min-width: 140px;
    background: #fff; border: 1px solid #dcdcde;
    border-radius: 6px; padding: 14px 16px;
    border-left: 4px solid #adb5bd;
}
.cs-mig-stat-num { font-size: 28px; font-weight: 700; line-height: 1; color: #1d2327; }
.cs-mig-stat-label { font-size: 12px; color: #646970; margin-top: 6px; }
.cs-mig-eligible { border-left-color: #00a32a; }
.cs-mig-vars     { border-left-color: #2271b1; }
.cs-mig-reuse    { border-left-color: #826eb4; }
.cs-mig-migrated { border-left-color: #2271b1; }
.cs-mig-default  { border-left-color: #8c8f94; }

.cs-mig-apply-form { margin: 0 0 28px; padding: 16px; background: #f7fcf8; border: 1px solid #c8e4cc; border-radius: 6px; }
.cs-mig-apply-form .button-hero { font-size: 14px; padding: 8px 18px; }
.cs-mig-warn { margin-left: 12px; color: #856404; font-size: 13px; }

.cs-mig-section {
    background: #fff; border: 1px solid #dcdcde;
    border-radius: 6px; margin-bottom: 16px;
}
.cs-mig-section > summary {
    cursor: pointer; padding: 14px 18px;
    font-weight: 500; user-select: none;
    list-style: none; display: flex; align-items: center; gap: 10px;
}
.cs-mig-section > summary::-webkit-details-marker { display: none; }
.cs-mig-section > summary::before {
    content: "▸"; color: #646970;
    transition: transform 0.18s ease;
}
.cs-mig-section[open] > summary::before { transform: rotate(90deg); }
.cs-mig-section > p.description { padding: 0 18px 12px; margin: 0 0 12px; border-bottom: 1px solid #f0f0f1; }

.cs-mig-badge {
    display: inline-block; padding: 2px 9px; border-radius: 12px;
    font-size: 11px; font-weight: 600; letter-spacing: 0.5px;
    text-transform: uppercase;
}
.cs-mig-badge-restore   { background: rgba(0,163,42,0.12);   color: #00733A; }
.cs-mig-badge-formalize { background: rgba(34,113,177,0.12); color: #135e96; }
.cs-mig-badge-fallback  { background: rgba(219,166,23,0.18); color: #7a5d05; }
.cs-mig-badge-empty     { background: rgba(140,143,148,0.18); color: #4f5359; }

.cs-mig-card {
    margin: 12px 18px; padding: 12px 14px;
    background: #f9f9f9; border: 1px solid #e0e0e0;
    border-radius: 4px;
}
.cs-mig-card-compact {
    padding: 6px 12px; margin: 4px 18px;
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    background: #fff; border-color: #f0f0f1;
}
.cs-mig-card-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; margin-bottom: 8px; flex-wrap: wrap;
}
.cs-mig-card-title { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.cs-mig-meta { color: #646970; font-size: 12px; }
.cs-mig-pill {
    background: #e7f5e9; color: #135e26;
    padding: 1px 7px; border-radius: 10px;
    font-size: 11px; font-weight: 500;
}
.cs-mig-pill-reuse { background: #efe9f7; color: #5b4992; }

.cs-mig-mini-pattern {
    font-family: monospace; font-size: 11px;
    background: #fff; padding: 1px 6px; border-radius: 3px;
    border: 1px solid #e0e0e0; word-break: break-all;
    max-width: 380px; display: inline-block;
}

.cs-mig-card-row {
    display: grid; grid-template-columns: 110px 1fr;
    gap: 8px; padding: 4px 0; align-items: start;
}
.cs-mig-label { color: #646970; font-size: 12px; padding-top: 2px; }
.cs-mig-var-row {
    display: flex; gap: 8px; align-items: flex-start;
    padding: 2px 0;
}
.cs-mig-var-icon { width: 14px; flex-shrink: 0; color: #646970; }
.cs-mig-var-text { word-break: break-word; }
</style>
