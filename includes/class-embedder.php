<?php
/**
 * CleverSay Embedder — Phase 2 of the embeddings migration.
 *
 * Owns the lifecycle of getting MySQL content into Supabase as
 * vector embeddings. Two trigger types:
 *
 *   - SYNCHRONOUS: admin-driven save of a single KB entry. Embeds
 *     immediately during the save request. Adds 1-3 seconds latency
 *     but gives admins immediate feedback.
 *
 *   - ASYNCHRONOUS: bulk operations (source crawls, backfill). Queue
 *     content into the embedding_queue table; a WP-Cron job processes
 *     batches periodically. With system cron pointed at wp-cron.php,
 *     this runs on a schedule. Without it, runs whenever traffic
 *     fires the cron check.
 *
 * Key invariants:
 *
 *   1. MySQL is the source of truth. Embedding failures NEVER block
 *      MySQL writes. The original save/index always succeeds; embedding
 *      is best-effort eventually-consistent.
 *
 *   2. The feature flag (Supabase::is_enabled()) gates ALL behavior.
 *      When off, no queueing, no embedding calls, no Supabase writes.
 *
 *   3. Re-indexing soft-deletes old embedding rows (is_current=FALSE)
 *      rather than hard-deleting. This preserves audit trail and
 *      enables future rollback features.
 *
 *   4. The chunks table in Supabase has a unique-by-content-key
 *      semantic: (tenant_id, content_type, content_id) identifies
 *      one piece of content. UPSERT semantics: if a row exists for
 *      that key, update it; otherwise insert. is_current is reset
 *      to TRUE on update.
 *
 * @package CleverSay
 * @since   4.39.0
 */

namespace CleverSay;

if (!defined('ABSPATH')) exit;

class Embedder {

    /** Maximum retry attempts for failed embedding jobs */
    const MAX_RETRIES = 3;

    /** Number of jobs to process per cron run */
    const BATCH_SIZE = 20;

    /** Source title prefix format. See README in docblock at top of file. */
    const PREFIX_FORMAT = '%s: %s';

    private \wpdb $wpdb;
    private string $queue_table;
    private string $chunks_table;
    private string $knowledge_table;
    private string $sources_table;
    private Logger $logger;

    public function __construct() {
        global $wpdb;
        $this->wpdb            = $wpdb;
        $this->queue_table     = $wpdb->prefix . 'cleversay_embedding_queue';
        $this->chunks_table    = $wpdb->prefix . 'cleversay_chunks';
        $this->knowledge_table = $wpdb->prefix . 'cleversay_knowledge';
        $this->sources_table   = $wpdb->prefix . 'cleversay_sources';
        $this->logger          = Logger::instance();
    }

    // =========================================================================
    // PUBLIC API — called by hooks in indexer / admin
    // =========================================================================

    /**
     * Embed a KB entry synchronously. Called from the admin save path.
     * Returns true on success, false on failure (without throwing).
     *
     * On failure, the row is left in pending state so cron can retry.
     */
    public function embed_kb_entry_sync(int $knowledge_id): bool {
        if (!Supabase::is_enabled()) {
            return false;
        }

        $entry = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, question, response, status FROM {$this->knowledge_table} WHERE id = %d",
                $knowledge_id
            ),
            ARRAY_A
        );

        if (!$entry) {
            $this->logger->warning('embed_kb_entry_sync: entry not found', ['id' => $knowledge_id]);
            return false;
        }

        if (($entry['status'] ?? '') !== 'active') {
            // Inactive entries shouldn't be retrievable. Soft-delete any
            // existing embedding row in Supabase, but don't generate new ones.
            $this->mark_stale_in_supabase('kb_entry', $knowledge_id);
            $this->dequeue('kb_entry', $knowledge_id);
            return true;
        }

        $text  = $this->build_kb_entry_text($entry);
        $title = 'Q&A: ' . wp_trim_words((string) $entry['question'], 8, '');
        $prefixed = $this->prefix_text($title, $text);

        $embeddings = new Embeddings();
        $vector = $embeddings->embed($prefixed);

        if ($vector === null) {
            // Queue for later retry. The save itself succeeded (we're
            // running after the MySQL write). Embedding is best-effort.
            $this->enqueue('kb_entry', $knowledge_id);
            return false;
        }

        $ok = $this->upsert_supabase_row(
            content_type: 'kb_entry',
            content_id:   $knowledge_id,
            source_id:    $knowledge_id, // for KB entries, source_id == content_id
            chunk_text:   $text,
            metadata:     [
                'source_type'  => 'kb_entry',
                'source_title' => 'Q&A: ' . $entry['question'],
                'authority'    => 'high',
            ],
            vector: $vector
        );

        if ($ok) {
            $this->dequeue('kb_entry', $knowledge_id);
            return true;
        }

        $this->enqueue('kb_entry', $knowledge_id);
        return false;
    }

    /**
     * Mark a KB entry's embedding as stale (called when entry is deleted
     * or its status flips to inactive). Soft-delete in Supabase; remove
     * any pending queue entry.
     */
    public function delete_kb_entry(int $knowledge_id): void {
        if (!Supabase::is_enabled()) {
            return;
        }
        $this->mark_stale_in_supabase('kb_entry', $knowledge_id);
        $this->dequeue('kb_entry', $knowledge_id);
    }

    /**
     * Queue all chunks for a source for asynchronous embedding.
     * Called after Indexer::store_chunks completes. Returns number queued.
     */
    public function queue_source_chunks(int $source_id): int {
        if (!Supabase::is_enabled()) {
            return 0;
        }

        // Mark any old embedding rows for this source as stale before
        // queueing fresh chunks. This handles re-indexing cleanly.
        $this->mark_stale_in_supabase_by_source($source_id);

        // Fetch new chunk IDs
        $chunk_ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT id FROM {$this->chunks_table} WHERE source_id = %d ORDER BY chunk_index ASC",
            $source_id
        ));

        if (empty($chunk_ids)) {
            return 0;
        }

        $count = 0;
        foreach ($chunk_ids as $chunk_id) {
            $this->enqueue('chunk', (int) $chunk_id);
            $count++;
        }

        return $count;
    }

    /**
     * Mark all chunks for a source as stale and remove from queue.
     * Called when a source is deleted.
     */
    public function delete_source_chunks(int $source_id): void {
        if (!Supabase::is_enabled()) {
            return;
        }

        $this->mark_stale_in_supabase_by_source($source_id);

        // Remove any pending queue entries for chunks of this source.
        // We need chunk IDs that belonged to this source. Since chunks
        // may already be deleted by the indexer's store_chunks(), we
        // can't query them. Instead clear queue rows by content_id list
        // recovered from source_usage or just clear by joining in the
        // queue. Simpler: drop all 'chunk' queue entries for this blog
        // whose chunk no longer exists in MySQL.
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE q FROM {$this->queue_table} q
             LEFT JOIN {$this->chunks_table} c ON q.content_id = c.id
             WHERE q.content_type = 'chunk' AND c.id IS NULL"
        ));
    }

    // =========================================================================
    // QUEUE MANAGEMENT
    // =========================================================================

    /**
     * Add or update a queue entry. Idempotent: re-queueing existing
     * content resets it to 'pending' state.
     */
    private function enqueue(string $content_type, int $content_id): void {
        $blog_id = (int) get_current_blog_id();
        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$this->queue_table}
             (content_type, content_id, blog_id, status, retry_count, last_error, created_at, updated_at)
             VALUES (%s, %d, %d, 'pending', 0, NULL, %s, %s)
             ON DUPLICATE KEY UPDATE
                status = 'pending',
                retry_count = 0,
                last_error = NULL,
                blog_id = VALUES(blog_id),
                updated_at = VALUES(updated_at)",
            $content_type,
            $content_id,
            $blog_id,
            current_time('mysql'),
            current_time('mysql')
        ));
    }

    /**
     * Remove a queue entry (called after successful embedding).
     */
    private function dequeue(string $content_type, int $content_id): void {
        $this->wpdb->delete($this->queue_table, [
            'content_type' => $content_type,
            'content_id'   => $content_id,
        ]);
    }

    /**
     * Process a batch of queue entries. Called by WP-Cron.
     * Returns array with stats: processed, succeeded, failed.
     */
    public function process_queue(int $batch_size = self::BATCH_SIZE): array {
        $stats = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];

        if (!Supabase::is_enabled()) {
            return $stats;
        }

        // Pull pending or retryable failed jobs. Order by oldest first.
        $jobs = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, content_type, content_id, blog_id, retry_count
             FROM {$this->queue_table}
             WHERE status IN ('pending', 'failed')
               AND retry_count < %d
             ORDER BY updated_at ASC
             LIMIT %d",
            self::MAX_RETRIES,
            $batch_size
        ), ARRAY_A);

        if (empty($jobs)) {
            return $stats;
        }

        foreach ($jobs as $job) {
            $stats['processed']++;

            // Mark as processing so a concurrent cron run wouldn't pick it up.
            $this->wpdb->update(
                $this->queue_table,
                ['status' => 'processing', 'updated_at' => current_time('mysql')],
                ['id' => (int) $job['id']]
            );

            $ok = false;
            $err = null;
            try {
                if ($job['content_type'] === 'chunk') {
                    $ok = $this->process_chunk_job((int) $job['content_id']);
                } elseif ($job['content_type'] === 'kb_entry') {
                    $ok = $this->process_kb_entry_job((int) $job['content_id']);
                }
            } catch (\Throwable $e) {
                $err = $e->getMessage();
                $this->logger->error('Embedder job threw', [
                    'job_id' => $job['id'],
                    'type'   => $job['content_type'],
                    'id'     => $job['content_id'],
                    'error'  => $err,
                ]);
            }

            if ($ok) {
                // Success — remove from queue.
                $this->wpdb->delete($this->queue_table, ['id' => (int) $job['id']]);
                $stats['succeeded']++;
            } else {
                // Failed. Increment retry count; mark failed if exhausted.
                $new_retry = (int) $job['retry_count'] + 1;
                $new_status = $new_retry >= self::MAX_RETRIES ? 'failed' : 'pending';
                $this->wpdb->update(
                    $this->queue_table,
                    [
                        'status'      => $new_status,
                        'retry_count' => $new_retry,
                        'last_error'  => $err ?? 'embedding generation failed',
                        'updated_at'  => current_time('mysql'),
                    ],
                    ['id' => (int) $job['id']]
                );
                $stats['failed']++;
            }
        }

        $this->logger->info('Embedder batch processed', $stats);
        return $stats;
    }

    /**
     * Process a single chunk job. Returns true on success.
     */
    private function process_chunk_job(int $chunk_id): bool {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT c.id, c.source_id, c.chunk_index, c.content,
                    s.title AS source_title, s.url AS source_url, s.source_type
             FROM {$this->chunks_table} c
             LEFT JOIN {$this->sources_table} s ON c.source_id = s.id
             WHERE c.id = %d",
            $chunk_id
        ), ARRAY_A);

        if (!$row) {
            // Chunk no longer exists in MySQL. Soft-delete any Supabase
            // row tied to it and consider the job done.
            $this->mark_stale_in_supabase('chunk', $chunk_id);
            return true;
        }

        $title = (string) ($row['source_title'] ?? '');
        $text  = (string) $row['content'];
        $prefixed = $this->prefix_text($title, $text);

        $embeddings = new Embeddings();
        $vector = $embeddings->embed($prefixed);

        if ($vector === null) {
            return false;
        }

        return $this->upsert_supabase_row(
            content_type: 'chunk',
            content_id:   (int) $row['id'],
            source_id:    (int) $row['source_id'],
            chunk_text:   $text,
            metadata:     [
                'source_type'  => $row['source_type'] ?? 'crawled_page',
                'source_title' => $title,
                'source_url'   => (string) ($row['source_url'] ?? ''),
                'chunk_index'  => (int) $row['chunk_index'],
                'authority'    => 'medium',
            ],
            vector: $vector
        );
    }

    /**
     * Process a single KB entry job. Returns true on success.
     */
    private function process_kb_entry_job(int $knowledge_id): bool {
        $entry = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, question, response, status FROM {$this->knowledge_table} WHERE id = %d",
            $knowledge_id
        ), ARRAY_A);

        if (!$entry) {
            $this->mark_stale_in_supabase('kb_entry', $knowledge_id);
            return true;
        }

        if (($entry['status'] ?? '') !== 'active') {
            $this->mark_stale_in_supabase('kb_entry', $knowledge_id);
            return true;
        }

        $text  = $this->build_kb_entry_text($entry);
        $title = 'Q&A: ' . wp_trim_words((string) $entry['question'], 8, '');
        $prefixed = $this->prefix_text($title, $text);

        $embeddings = new Embeddings();
        $vector = $embeddings->embed($prefixed);

        if ($vector === null) {
            return false;
        }

        return $this->upsert_supabase_row(
            content_type: 'kb_entry',
            content_id:   $knowledge_id,
            source_id:    $knowledge_id,
            chunk_text:   $text,
            metadata:     [
                'source_type'  => 'kb_entry',
                'source_title' => 'Q&A: ' . $entry['question'],
                'authority'    => 'high',
            ],
            vector: $vector
        );
    }

    // =========================================================================
    // BACKFILL — admin-triggered bulk operation
    // =========================================================================

    /**
     * Queue all existing content (KB entries + source chunks) for embedding.
     * Idempotent: re-running just re-queues. Returns counts.
     *
     * For the current site only. To backfill across multisite, call once
     * per site via switch_to_blog().
     */
    public function backfill_all(): array {
        if (!Supabase::is_enabled()) {
            return ['kb_entries' => 0, 'chunks' => 0];
        }

        // Active KB entries
        $kb_ids = $this->wpdb->get_col(
            "SELECT id FROM {$this->knowledge_table} WHERE status = 'active'"
        );
        foreach ($kb_ids as $id) {
            $this->enqueue('kb_entry', (int) $id);
        }

        // All source chunks
        $chunk_ids = $this->wpdb->get_col(
            "SELECT id FROM {$this->chunks_table}"
        );
        foreach ($chunk_ids as $id) {
            $this->enqueue('chunk', (int) $id);
        }

        $this->logger->info('Backfill queued', [
            'kb_entries' => count($kb_ids),
            'chunks'     => count($chunk_ids),
        ]);

        return [
            'kb_entries' => count($kb_ids),
            'chunks'     => count($chunk_ids),
        ];
    }

    /**
     * Return queue statistics for the admin status panel.
     *
     * v4.41.0+: "embedded" counts are now integrity-checked. Previously
     * we counted Supabase rows by content_type and reported that as the
     * "done" number — but that count included rows whose content_id no
     * longer maps to any current MySQL row (e.g. chunks deleted during
     * a re-index, or the famous tenant_id='1' stranded rows on
     * jasa-server.com). The new counter intersects Supabase rows with
     * the live MySQL ID set, so what's reported is "rows that actually
     * back current MySQL content" — matching what an operator's mental
     * model expects. Stranded rows surface as `stranded_rows` instead.
     *
     * Returned keys:
     *   - pending, processing, failed: queue counts for this blog
     *   - total_kb_entries:   COUNT FROM cleversay_knowledge WHERE active
     *   - total_chunks:       COUNT FROM cleversay_chunks
     *   - embedded_kb_entries: Supabase kb_entry rows whose content_id
     *                          maps to a currently-active MySQL KB entry
     *   - embedded_chunks:    Supabase chunk rows whose content_id maps
     *                          to a currently-existing MySQL chunk
     *   - stranded_rows:      total Supabase current-rows for this tenant
     *                          minus the embedded counts. Non-zero indicates
     *                          stale or misfiled rows that should be cleaned.
     *
     * See Bug 8 in the v4.41.0 handoff brief.
     */
    public function get_queue_stats(): array {
        $blog_id = (int) get_current_blog_id();

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT status, COUNT(*) AS n
             FROM {$this->queue_table}
             WHERE blog_id = %d
             GROUP BY status",
            $blog_id
        ), ARRAY_A);

        $stats = ['pending' => 0, 'processing' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $stats[$row['status']] = (int) $row['n'];
        }

        // Total content count for context (the denominators in the UI).
        $total_kb = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->knowledge_table} WHERE status = 'active'"
        );
        $total_chunks = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->chunks_table}"
        );
        $stats['total_kb_entries'] = $total_kb;
        $stats['total_chunks']     = $total_chunks;

        // Embedded counts — integrity-checked against live MySQL rows.
        try {
            $tenant_id = (string) $blog_id;

            // Pull the set of (content_type, content_id) pairs that currently
            // exist in Supabase for this tenant, scoped to is_current rows.
            $sb_rows = Supabase::instance()->query(
                "SELECT content_type, content_id
                 FROM cleversay_chunks
                 WHERE tenant_id = :tid AND is_current = TRUE",
                ['tid' => $tenant_id]
            );

            $sb_kb_ids    = [];
            $sb_chunk_ids = [];
            foreach ($sb_rows as $r) {
                $cid = (int) $r['content_id'];
                if ($r['content_type'] === 'kb_entry') {
                    $sb_kb_ids[$cid] = true;
                } elseif ($r['content_type'] === 'chunk') {
                    $sb_chunk_ids[$cid] = true;
                }
            }

            $stats['embedded_kb_entries'] = $this->count_intersection_with_mysql(
                array_keys($sb_kb_ids),
                $this->knowledge_table,
                "status = 'active'"
            );
            $stats['embedded_chunks'] = $this->count_intersection_with_mysql(
                array_keys($sb_chunk_ids),
                $this->chunks_table
            );

            // Stranded = Supabase rows that didn't match a live MySQL row.
            // Useful for surfacing the "673 leftover blog 1 rows" scenario.
            $supabase_total = count($sb_kb_ids) + count($sb_chunk_ids);
            $matched_total  = $stats['embedded_kb_entries'] + $stats['embedded_chunks'];
            $stats['stranded_rows'] = max(0, $supabase_total - $matched_total);
        } catch (\Throwable $e) {
            $stats['embedded_kb_entries'] = null;
            $stats['embedded_chunks']     = null;
            $stats['stranded_rows']       = null;
            $stats['supabase_query_error'] = $e->getMessage();
        }

        return $stats;
    }

    /**
     * Helper: count how many of the given content IDs still exist in the
     * given MySQL table, optionally with an extra WHERE clause. Used by
     * get_queue_stats() to compute the integrity-checked embedded counts.
     *
     * Empty input → 0 (no need to query).
     *
     * @param int[]       $ids
     * @param string      $table       Fully-qualified table name (with prefix)
     * @param string|null $extra_where Optional additional condition (no leading AND)
     */
    private function count_intersection_with_mysql(array $ids, string $table, ?string $extra_where = null): int {
        if (empty($ids)) {
            return 0;
        }
        // Build a placeholder list and bind the IDs as integers.
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT COUNT(*) FROM {$table} WHERE id IN ({$placeholders})";
        if ($extra_where !== null && $extra_where !== '') {
            $sql .= " AND ({$extra_where})";
        }
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare($sql, ...array_map('intval', $ids))
        );
    }

    /**
     * Maximum number of strands the auto-cleanup will delete in one run
     * without admin confirmation. If a tenant has more than this many
     * strands, the cleanup refuses to proceed and surfaces a louder
     * warning instead.
     *
     * The reasoning: a few dozen strands is normal background noise
     * from intermittent network blips during indexing. A thousand-plus
     * strands suggests something systemically broken (e.g., MySQL was
     * wiped without going through the app's delete handlers) where
     * mass-deletion in Supabase might not be the right response —
     * an admin should investigate first.
     *
     * @since 4.42.31
     */
    public const STRAND_CLEANUP_SAFETY_MAX = 1000;

    /**
     * Hard-delete Supabase rows whose content_id no longer maps to a
     * current MySQL row for the current tenant. This is the operation
     * the "Clean Up Stranded Rows" button on the Embeddings page and
     * the daily cleanup cron invoke.
     *
     * Why hard delete vs soft delete:
     *   The system uses soft-delete (is_current=FALSE) for active
     *   content that's being replaced — the historical embedding is
     *   kept in case it's useful for audit. Strands are different:
     *   the MySQL row they pointed to no longer exists, so there's
     *   nothing to "come back." Soft-deleting just keeps dead data
     *   around indefinitely. Hard delete reclaims storage.
     *
     * Safety brake:
     *   If strands exceed STRAND_CLEANUP_SAFETY_MAX, the method
     *   returns an error result without deleting anything. The caller
     *   should surface this to admin for investigation. Set
     *   $force=true to bypass the threshold; admins use this from the
     *   manual button after reviewing the count.
     *
     * Scope:
     *   Strict per-tenant. Reads tenant_id from get_current_blog_id().
     *   Multisite cron iterates each blog with proper switch_to_blog
     *   boundaries — see cleversay_run_daily_stranded_cleanup() in
     *   cleversay.php.
     *
     * Return shape (always an array, never throws):
     *   - success:        bool — true if the operation completed
     *   - deleted:        int  — rows actually deleted (zero on failure
     *                            or no-op)
     *   - found:          int  — strands detected before deletion
     *   - reason:         string — set only on failure
     *                              ('safety_threshold' | 'db_error' |
     *                               'supabase_disabled')
     *   - safety_threshold: int — included when reason='safety_threshold'
     *
     * @since 4.42.31
     * @param bool $force If true, skip the STRAND_CLEANUP_SAFETY_MAX check.
     * @return array{success: bool, deleted: int, found: int, reason?: string}
     */
    public function cleanup_stranded_rows(bool $force = false): array {
        if (!Supabase::is_enabled()) {
            return [
                'success' => false,
                'deleted' => 0,
                'found'   => 0,
                'reason'  => 'supabase_disabled',
            ];
        }

        $tenant_id = (string) get_current_blog_id();

        try {
            // Step 1: Enumerate all current Supabase rows for this tenant.
            // Crucially, we fetch both content_type AND content_id together
            // so we don't conflate KB entries with chunks — they share the
            // content_id integer space but have different MySQL backing
            // tables.
            $sb_rows = Supabase::instance()->query(
                "SELECT content_type, content_id
                 FROM cleversay_chunks
                 WHERE tenant_id = :tid AND is_current = TRUE",
                ['tid' => $tenant_id]
            );

            $sb_kb_ids    = [];
            $sb_chunk_ids = [];
            foreach ($sb_rows as $r) {
                $cid = (int) $r['content_id'];
                if ($r['content_type'] === 'kb_entry') {
                    $sb_kb_ids[$cid] = true;
                } elseif ($r['content_type'] === 'chunk') {
                    $sb_chunk_ids[$cid] = true;
                }
            }

            // Step 2: For each set, identify which IDs are present in
            // Supabase but missing from MySQL. Those are the strands.
            $stranded_kb = $this->find_missing_in_mysql(
                array_keys($sb_kb_ids),
                $this->knowledge_table,
                "status = 'active'"
            );
            $stranded_chunks = $this->find_missing_in_mysql(
                array_keys($sb_chunk_ids),
                $this->chunks_table
            );

            $total_stranded = count($stranded_kb) + count($stranded_chunks);

            // No strands → success, nothing to do.
            if ($total_stranded === 0) {
                return [
                    'success' => true,
                    'deleted' => 0,
                    'found'   => 0,
                ];
            }

            // Step 3: Apply safety brake unless forced.
            if (!$force && $total_stranded > self::STRAND_CLEANUP_SAFETY_MAX) {
                $this->logger->warning('Strand cleanup refused: count exceeds safety threshold', [
                    'tenant_id'        => $tenant_id,
                    'stranded_total'   => $total_stranded,
                    'safety_threshold' => self::STRAND_CLEANUP_SAFETY_MAX,
                ]);
                return [
                    'success'          => false,
                    'deleted'          => 0,
                    'found'            => $total_stranded,
                    'reason'           => 'safety_threshold',
                    'safety_threshold' => self::STRAND_CLEANUP_SAFETY_MAX,
                ];
            }

            // Step 4: Hard delete. Done as two batched DELETEs (one per
            // content_type) using IN clauses. Each batch is wrapped in
            // a transaction so a mid-operation failure doesn't leave
            // half-deleted state.
            $deleted = 0;
            $pdo = Supabase::instance()->connect();

            if (!empty($stranded_kb)) {
                $deleted += $this->delete_stranded_batch($pdo, $tenant_id, 'kb_entry', $stranded_kb);
            }
            if (!empty($stranded_chunks)) {
                $deleted += $this->delete_stranded_batch($pdo, $tenant_id, 'chunk', $stranded_chunks);
            }

            $this->logger->info('Strand cleanup completed', [
                'tenant_id' => $tenant_id,
                'found'     => $total_stranded,
                'deleted'   => $deleted,
                'forced'    => $force,
            ]);

            return [
                'success' => true,
                'deleted' => $deleted,
                'found'   => $total_stranded,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Strand cleanup failed', [
                'tenant_id' => $tenant_id,
                'error'     => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'deleted' => 0,
                'found'   => 0,
                'reason'  => 'db_error',
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Helper: return the subset of $ids that do NOT exist in $table
     * (optionally filtered by $extra_where). The inverse of
     * count_intersection_with_mysql.
     *
     * Empty input → empty result.
     *
     * @param int[]       $ids
     * @param string      $table
     * @param string|null $extra_where
     * @return int[]      The IDs that are missing from MySQL.
     */
    private function find_missing_in_mysql(array $ids, string $table, ?string $extra_where = null): array {
        if (empty($ids)) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT id FROM {$table} WHERE id IN ({$placeholders})";
        if ($extra_where !== null && $extra_where !== '') {
            $sql .= " AND ({$extra_where})";
        }
        $present = $this->wpdb->get_col($this->wpdb->prepare($sql, ...$ids));
        $present_set = array_flip(array_map('intval', $present));
        $missing = [];
        foreach ($ids as $id) {
            if (!isset($present_set[$id])) {
                $missing[] = $id;
            }
        }
        return $missing;
    }

    /**
     * Helper: execute a transactioned DELETE on Supabase for the given
     * content_type and list of content_ids. Returns count of rows
     * deleted (0 on failure or empty input).
     *
     * Why batch with IN: a single DELETE with IN is dramatically faster
     * than N round-trips, and Postgres handles IN clauses of thousands
     * of values comfortably. For very large strand counts, the safety
     * brake fires before we get here.
     *
     * @since 4.42.31
     */
    private function delete_stranded_batch(\PDO $pdo, string $tenant_id, string $content_type, array $ids): int {
        if (empty($ids)) {
            return 0;
        }
        $placeholders = [];
        $params = [
            'tid'   => $tenant_id,
            'ctype' => $content_type,
        ];
        foreach ($ids as $i => $id) {
            $key = 'cid' . $i;
            $placeholders[] = ':' . $key;
            $params[$key]   = (int) $id;
        }
        $sql = "DELETE FROM cleversay_chunks
                WHERE tenant_id = :tid
                  AND content_type = :ctype
                  AND content_id IN (" . implode(',', $placeholders) . ")";

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rowcount = $stmt->rowCount();
            $pdo->commit();
            return (int) $rowcount;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->logger->error('Stranded batch delete failed', [
                'tenant_id'    => $tenant_id,
                'content_type' => $content_type,
                'batch_size'   => count($ids),
                'error'        => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Maximum number of items the auto-backfill (cron path) will enqueue
     * in a single run without explicit admin action. The reasoning
     * matches STRAND_CLEANUP_SAFETY_MAX: an unusually large gap (say,
     * 5000 KB entries with no embeddings) is likely a bulk import or
     * a multisite import situation where automatic enqueueing of
     * everything would consume real API credits and many hours of
     * cron processing. Better to surface the count for admin
     * attention than silently start a multi-hour run.
     *
     * Manual button passes $force=true to bypass this.
     *
     * @since 4.42.32
     */
    public const BACKFILL_AUTO_MAX = 200;

    /**
     * Find content that exists in MySQL but has no current Supabase
     * embedding, and enqueue it for embedding generation. The inverse
     * of cleanup_stranded_rows. Together they form a complete
     * bidirectional integrity loop.
     *
     * Detection scope:
     *   - Active KB entries in cleversay_knowledge (status='active')
     *   - All chunks in cleversay_chunks
     *
     * For each set, compares against the tenant's current Supabase
     * rows (is_current=TRUE). MySQL IDs absent from Supabase are
     * "missing" — they need embeddings created.
     *
     * Safety brake:
     *   Non-forced runs cap at BACKFILL_AUTO_MAX items per run. Above
     *   the cap, the run refuses and surfaces the count for admin
     *   attention. Manual button passes $force=true.
     *
     * Idempotency:
     *   Uses the existing enqueue() which has ON DUPLICATE KEY UPDATE
     *   semantics on (content_type, content_id, blog_id). Items
     *   already in the queue get reset to pending; new items get
     *   inserted. Safe to run repeatedly.
     *
     * Return shape (always an array, never throws):
     *   - success:    bool
     *   - enqueued:   int — items added to the queue this run
     *   - found:      int — missing items detected
     *   - reason:     string — set only on failure
     *                  ('safety_threshold' | 'db_error' | 'supabase_disabled')
     *   - safety_threshold: int — included when reason='safety_threshold'
     *
     * @since 4.42.32
     * @param bool $force If true, skip the BACKFILL_AUTO_MAX check.
     * @return array{success: bool, enqueued: int, found: int, reason?: string}
     */
    public function backfill_missing_embeddings(bool $force = false): array {
        if (!Supabase::is_enabled()) {
            return [
                'success'  => false,
                'enqueued' => 0,
                'found'    => 0,
                'reason'   => 'supabase_disabled',
            ];
        }

        $tenant_id = (string) get_current_blog_id();

        try {
            // Step 1: Get current Supabase (content_type, content_id) pairs.
            // Same query the strand-cleanup uses, so the work the system
            // does for these two integrity operations is symmetric.
            $sb_rows = Supabase::instance()->query(
                "SELECT content_type, content_id
                 FROM cleversay_chunks
                 WHERE tenant_id = :tid AND is_current = TRUE",
                ['tid' => $tenant_id]
            );

            $sb_kb_ids    = [];
            $sb_chunk_ids = [];
            foreach ($sb_rows as $r) {
                $cid = (int) $r['content_id'];
                if ($r['content_type'] === 'kb_entry') {
                    $sb_kb_ids[$cid] = true;
                } elseif ($r['content_type'] === 'chunk') {
                    $sb_chunk_ids[$cid] = true;
                }
            }

            // Step 2: Get MySQL IDs we expect to find in Supabase.
            $mysql_kb_ids = array_map('intval', $this->wpdb->get_col(
                "SELECT id FROM {$this->knowledge_table} WHERE status = 'active'"
            ));
            $mysql_chunk_ids = array_map('intval', $this->wpdb->get_col(
                "SELECT id FROM {$this->chunks_table}"
            ));

            // Step 3: Compute the diff. MySQL IDs not present in the
            // Supabase set are the ones that need embeddings created.
            $missing_kb = [];
            foreach ($mysql_kb_ids as $id) {
                if (!isset($sb_kb_ids[$id])) {
                    $missing_kb[] = $id;
                }
            }
            $missing_chunks = [];
            foreach ($mysql_chunk_ids as $id) {
                if (!isset($sb_chunk_ids[$id])) {
                    $missing_chunks[] = $id;
                }
            }

            $total_missing = count($missing_kb) + count($missing_chunks);

            if ($total_missing === 0) {
                return [
                    'success'  => true,
                    'enqueued' => 0,
                    'found'    => 0,
                ];
            }

            // Step 4: Safety brake unless forced.
            if (!$force && $total_missing > self::BACKFILL_AUTO_MAX) {
                $this->logger->warning('Auto-backfill refused: count exceeds safety threshold', [
                    'tenant_id'        => $tenant_id,
                    'missing_total'    => $total_missing,
                    'safety_threshold' => self::BACKFILL_AUTO_MAX,
                ]);
                return [
                    'success'          => false,
                    'enqueued'         => 0,
                    'found'            => $total_missing,
                    'reason'           => 'safety_threshold',
                    'safety_threshold' => self::BACKFILL_AUTO_MAX,
                ];
            }

            // Step 5: Enqueue. The existing enqueue() uses ON DUPLICATE
            // KEY UPDATE so any items already in the queue just get
            // their status reset to pending without duplicate inserts.
            $enqueued = 0;
            foreach ($missing_kb as $id) {
                $this->enqueue('kb_entry', (int) $id);
                $enqueued++;
            }
            foreach ($missing_chunks as $id) {
                $this->enqueue('chunk', (int) $id);
                $enqueued++;
            }

            $this->logger->info('Auto-backfill enqueued missing embeddings', [
                'tenant_id'      => $tenant_id,
                'found'          => $total_missing,
                'enqueued'       => $enqueued,
                'kb_entries'     => count($missing_kb),
                'chunks'         => count($missing_chunks),
                'forced'         => $force,
            ]);

            return [
                'success'  => true,
                'enqueued' => $enqueued,
                'found'    => $total_missing,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Auto-backfill failed', [
                'tenant_id' => $tenant_id,
                'error'     => $e->getMessage(),
            ]);
            return [
                'success'  => false,
                'enqueued' => 0,
                'found'    => 0,
                'reason'   => 'db_error',
                'error'    => $e->getMessage(),
            ];
        }
    }

    /**
     * Reset all 'failed' jobs back to 'pending' for a manual retry pass.
     */
    public function retry_failed_jobs(): int {
        $blog_id = (int) get_current_blog_id();
        $this->wpdb->update(
            $this->queue_table,
            ['status' => 'pending', 'retry_count' => 0, 'last_error' => null, 'updated_at' => current_time('mysql')],
            ['blog_id' => $blog_id, 'status' => 'failed']
        );
        return (int) $this->wpdb->rows_affected;
    }

    // =========================================================================
    // SUPABASE WRITE OPERATIONS
    // =========================================================================

    /**
     * Insert or update an embedding row in Supabase.
     * Uses ON CONFLICT semantics keyed on (tenant_id, content_type, content_id).
     *
     * v4.41.0+: every row also writes source_namespace — 'source' for
     * chunks, 'kb' for KB entries — so diagnostic and retrieval queries
     * can scope by namespace without overloading content_type. See
     * Bug 4 in the v4.41.0 handoff brief.
     */
    private function upsert_supabase_row(
        string $content_type,
        int $content_id,
        int $source_id,
        string $chunk_text,
        array $metadata,
        array $vector
    ): bool {
        $tenant_id  = (string) get_current_blog_id();
        $chunk_hash = hash('sha256', $chunk_text);
        $vector_pg  = Supabase::vector_to_pg($vector);
        $namespace  = self::namespace_for_content_type($content_type);

        try {
            // We use a "delete then insert" strategy for upsert. The
            // alternative — Postgres ON CONFLICT — requires a unique
            // constraint we'd need to add to the schema. Keeping it
            // simple: clear any existing row for this content key,
            // then insert the new one. Wrapped in a transaction.
            $pdo = Supabase::instance()->connect();
            $pdo->beginTransaction();
            try {
                $del = $pdo->prepare(
                    "DELETE FROM cleversay_chunks
                     WHERE tenant_id = :tid
                       AND content_type = :ctype
                       AND content_id = :cid"
                );
                $del->execute([
                    'tid'   => $tenant_id,
                    'ctype' => $content_type,
                    'cid'   => $content_id,
                ]);

                $ins = $pdo->prepare(
                    "INSERT INTO cleversay_chunks
                     (tenant_id, content_type, content_id, source_id,
                      source_namespace,
                      chunk_hash, chunk_index, chunk_text, metadata,
                      embedding, embedding_model, embedding_version,
                      is_current, updated_at, created_at)
                     VALUES
                     (:tid, :ctype, :cid, :sid,
                      :ns,
                      :hash, :cidx, :text, :meta::jsonb,
                      :vec::vector, :model, 1,
                      TRUE, NOW(), NOW())"
                );
                $ins->execute([
                    'tid'   => $tenant_id,
                    'ctype' => $content_type,
                    'cid'   => $content_id,
                    'sid'   => $source_id,
                    'ns'    => $namespace,
                    'hash'  => $chunk_hash,
                    'cidx'  => (int) ($metadata['chunk_index'] ?? 0),
                    'text'  => $chunk_text,
                    'meta'  => wp_json_encode($metadata),
                    'vec'   => $vector_pg,
                    'model' => 'text-embedding-3-small',
                ]);

                $pdo->commit();
                return true;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Supabase upsert failed', [
                'content_type' => $content_type,
                'content_id'   => $content_id,
                'error'        => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * v4.41.0+: Map content_type to source_namespace.
     * 'chunk'    → 'source'  (cleversay_sources is the underlying source)
     * 'kb_entry' → 'kb'      (cleversay_knowledge entries are self-sourced)
     *
     * Centralized so the migration backfill and runtime writes stay in
     * sync. Unknown types default to 'source' as the conservative choice.
     */
    private static function namespace_for_content_type(string $content_type): string {
        return $content_type === 'kb_entry' ? 'kb' : 'source';
    }

    /**
     * Soft-delete: mark all rows for (tenant, content_type, content_id)
     * as is_current=FALSE. Used when content is removed.
     */
    private function mark_stale_in_supabase(string $content_type, int $content_id): void {
        $tenant_id = (string) get_current_blog_id();
        try {
            Supabase::instance()->execute(
                "UPDATE cleversay_chunks
                 SET is_current = FALSE, updated_at = NOW()
                 WHERE tenant_id = :tid
                   AND content_type = :ctype
                   AND content_id = :cid
                   AND is_current = TRUE",
                ['tid' => $tenant_id, 'ctype' => $content_type, 'cid' => $content_id]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Supabase mark-stale failed', [
                'content_type' => $content_type,
                'content_id'   => $content_id,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Soft-delete all rows tied to a particular source. Used during
     * source re-indexing before new chunks are queued.
     */
    private function mark_stale_in_supabase_by_source(int $source_id): void {
        $tenant_id = (string) get_current_blog_id();
        try {
            Supabase::instance()->execute(
                "UPDATE cleversay_chunks
                 SET is_current = FALSE, updated_at = NOW()
                 WHERE tenant_id = :tid
                   AND content_type = 'chunk'
                   AND source_id = :sid
                   AND is_current = TRUE",
                ['tid' => $tenant_id, 'sid' => $source_id]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Supabase mark-source-stale failed', [
                'source_id' => $source_id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // TEXT BUILDERS
    // =========================================================================

    /**
     * Compose the text representation of a KB entry for embedding.
     * Combines question + response so the embedding captures both
     * what the entry is about (question) and what it says (response).
     */
    private function build_kb_entry_text(array $entry): string {
        $question = trim((string) ($entry['question'] ?? ''));
        $response = trim(wp_strip_all_tags((string) ($entry['response'] ?? '')));
        if ($question !== '' && $response !== '') {
            return $question . "\n\n" . $response;
        }
        return $response !== '' ? $response : $question;
    }

    /**
     * Prefix chunk text with source title for embedding context.
     * A bare chunk like "must be paid by the deadline" embeds poorly;
     * "Graduation page: must be paid by the deadline" anchors meaning.
     */
    private function prefix_text(string $title, string $text): string {
        $title = trim($title);
        $text = trim($text);
        if ($title === '') {
            return $text;
        }
        if ($text === '') {
            return $title;
        }
        return sprintf(self::PREFIX_FORMAT, $title, $text);
    }
}
