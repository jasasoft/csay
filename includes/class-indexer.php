<?php
/**
 * CleverSay Indexer
 *
 * Extracts text from sources, splits it into chunks, and stores them.
 * Also handles chunk retrieval for AI context.
 *
 * @package CleverSay
 * @since 2.2.0
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class Indexer {

    private \wpdb  $wpdb;
    private string $sources_table;
    private string $chunks_table;
    private Logger $logger;

    /** Words per chunk (approx 500 tokens) */
    private const CHUNK_WORDS   = 350;
    /** Word overlap between adjacent chunks */
    private const OVERLAP_WORDS = 40;
    /** Max chunks returned for context */
    private const MAX_CHUNKS    = 6;

    public function __construct() {
        global $wpdb;
        $this->wpdb          = $wpdb;
        $this->sources_table = $wpdb->prefix . 'cleversay_sources';
        $this->chunks_table  = $wpdb->prefix . 'cleversay_chunks';
        $this->logger        = Logger::instance();
    }

    // ── Indexing ──────────────────────────────────────────────────────────────

    public function index_source(int $source_id, string $cached_html = '', bool $force_rechunk = false): void {
        $source = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->sources_table} WHERE id = %d", $source_id),
            ARRAY_A
        );

        if (!$source) {
            return;
        }

        $this->set_status($source_id, 'indexing');

        try {
            $text = $this->extract_text($source, $cached_html);

            if (empty(trim($text))) {
                $this->set_status($source_id, 'error', 'Could not extract any text from this source.');
                // Crawl attempt finished with no content — mark status
                $this->wpdb->update($this->sources_table, [
                    'last_crawled_at' => current_time('mysql'),
                    'crawl_status'    => 'error',
                    'crawl_error'     => 'No text extracted',
                ], ['id' => $source_id]);
                return;
            }

            // v4.37.97+: Auto-populate source title from page <title> tag
            // for URL sources. Only fires when current title equals the
            // URL itself (the auto-fallback from class-sources.php when
            // admin didn't enter a custom title). Respects admin's
            // manual title entry — never overwrites a curated label.
            // Best-effort: if extraction fails, leave title alone.
            if (($source['source_type'] ?? '') === 'url') {
                $current_title = (string) ($source['title'] ?? '');
                $url           = (string) ($source['url'] ?? '');
                if ($url !== '' && ($current_title === '' || $current_title === $url)) {
                    $page_title = $this->extract_html_title($url);
                    if ($page_title !== '' && $page_title !== $url) {
                        $this->wpdb->update(
                            $this->sources_table,
                            ['title' => $page_title],
                            ['id' => $source_id],
                            ['%s'],
                            ['%d']
                        );
                        $this->logger->info('Auto-populated source title from page', [
                            'source_id' => $source_id,
                            'title'     => $page_title,
                        ]);
                    }
                }
            }

            // Hash-based diff: compare new content against previous hash.
            // If unchanged AND not forcing re-chunk, skip the work to save
            // DB writes on big docs. The $force_rechunk flag is set by the
            // user-initiated Re-Index button — that button explicitly wants
            // a fresh re-extraction even when content hasn't changed,
            // because the user may have changed extraction settings or
            // chunking logic since the last index.
            $new_hash  = hash('sha256', trim($text));
            $prev_hash = $source['content_hash'] ?? null;
            $now       = current_time('mysql');
            $unchanged = !$force_rechunk && !empty($prev_hash) && $prev_hash === $new_hash;

            if ($unchanged) {
                // Same content as last crawl — just update crawl timestamp/status.
                // CRITICAL: also re-assert word_count and chunk_count from the
                // existing chunks table. The reindex() flow zeroes those before
                // calling here; if for any reason the unchanged branch runs
                // (e.g. concurrent refresh), we mustn't leave the row showing
                // 0 words despite chunks existing.
                $existing_chunks = (int) $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->chunks_table} WHERE source_id = %d",
                    $source_id
                ));

                $this->wpdb->update($this->sources_table, [
                    'status'          => 'indexed',
                    'word_count'      => str_word_count($text),
                    'chunk_count'     => $existing_chunks,
                    'last_crawled_at' => $now,
                    'crawl_status'    => 'unchanged',
                    'crawl_error'     => null,
                    'error_message'   => null,
                ], ['id' => $source_id]);
                $this->logger->info('Source crawl: unchanged (skipped re-chunk)', [
                    'source_id' => $source_id,
                ]);
                return;
            }

            // Content changed (or first crawl) — re-chunk
            $chunks = $this->chunk_text($text);
            $this->store_chunks($source_id, $chunks);

            // Determine the right crawl_status label. Three cases:
            //   - First-time index (no prior hash)             → 'new'
            //   - Forced re-index but content didn't change    → 'rechunked'
            //   - Auto-recrawl that detected a real diff       → 'changed'
            // We need this distinction because the badge text is shown to
            // admins, and labeling a forced re-index as "content changed"
            // is misleading.
            if (empty($prev_hash)) {
                $crawl_label   = 'new';
                $last_change   = $now;
            } elseif ($prev_hash === $new_hash) {
                // Same content — only here because force_rechunk was true
                $crawl_label   = 'rechunked';
                $last_change   = $source['last_change_at'] ?? $now; // preserve real last-change
            } else {
                $crawl_label   = 'changed';
                $last_change   = $now;
            }

            $word_count = str_word_count($text);
            $this->wpdb->update($this->sources_table, [
                'status'          => 'indexed',
                'chunk_count'     => count($chunks),
                'word_count'      => $word_count,
                'error_message'   => null,
                'content_hash'    => $new_hash,
                'last_crawled_at' => $now,
                'last_change_at'  => $last_change,
                'crawl_status'    => $crawl_label,
                'crawl_error'     => null,
            ], ['id' => $source_id]);

            $this->logger->info('Source indexed', [
                'source_id' => $source_id,
                'chunks'    => count($chunks),
                'words'     => $word_count,
                'status'    => !empty($prev_hash) ? 'changed' : 'new',
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Indexing failed', ['source_id' => $source_id, 'error' => $e->getMessage()]);
            $this->set_status($source_id, 'error', $e->getMessage());
            $this->wpdb->update($this->sources_table, [
                'last_crawled_at' => current_time('mysql'),
                'crawl_status'    => 'error',
                'crawl_error'     => substr($e->getMessage(), 0, 1000),
            ], ['id' => $source_id]);
        }
    }

    /** Used for plain text passed directly (e.g. from add_text()) */
    public function index_text_content(int $source_id, string $text, string $title): void {
        $this->set_status($source_id, 'indexing');

        $chunks     = $this->chunk_text($text);
        $this->store_chunks($source_id, $chunks);
        $word_count = str_word_count($text);

        $this->wpdb->update($this->sources_table, [
            'status'      => 'indexed',
            'chunk_count' => count($chunks),
            'word_count'  => $word_count,
            'error_message' => null,
        ], ['id' => $source_id]);
    }

    // ── Retrieval ─────────────────────────────────────────────────────────────

    /**
     * Find the most relevant chunks for a question.
     * Uses MySQL FULLTEXT search with keyword fallback.
     *
     * @param  string $question The user's question.
     * @param  int    $limit    Max chunks to return.
     * @return array            Array of chunk rows with source_title.
     */
    public function find_relevant_chunks(string $question, int $limit = 0): array {
        if ($limit === 0) {
            $limit = (int) get_option('cleversay_ai_max_chunks', self::MAX_CHUNKS);
        }

        // ── Phase 3 hybrid retrieval dispatch ───────────────────────────────
        // When the per-network `use_hybrid_retrieval` flag is on, ask the
        // Retriever (vector + FULLTEXT, RRF-merged) first. If it returns
        // results, run them through the contact-info appendix and return.
        //
        // When the Retriever returns empty (distance gate fired, or vector
        // path failed and FULLTEXT also came up empty), v4.40.5 falls
        // through to the legacy FULLTEXT path below instead of returning
        // empty. Reason: a low vector similarity (gate fire) means "vector
        // isn't confident" but doesn't mean "no useful chunk exists."
        // FULLTEXT plus the same-source contact-chunk heuristic frequently
        // surfaces a usable answer in those cases. Pre-v4.40.5 the dispatch
        // returned empty here, which suppressed otherwise-correct FULLTEXT
        // answers (e.g. "do you offer discount for seniors" → senior audit
        // page). Failing through is strictly better: worst case = pre-
        // Phase-3 behavior; best case = hybrid wins. The Retriever's gate
        // is still useful — it filters out *misleading* high-vector picks
        // before they reach FULLTEXT — but it should not block FULLTEXT
        // from running.
        $supabase_cfg = \CleverSay\Supabase::get_config();
        if (!empty($supabase_cfg['use_hybrid_retrieval'])) {
            $results = \CleverSay\Retriever::instance()->retrieve($question, $limit);
            if (!empty($results)) {
                return $this->append_contact_chunks($results);
            }
            // Fall through to FULLTEXT path below.
        }

        $question_clean = sanitize_text_field($question);

        // ── Query expansion via AI ──────────────────────────────────────
        //
        // REGRESSION GUARD — see ARCHITECTURE.md
        //
        // Pre-retrieval LLM query expansion was REMOVED in v4.37.142.
        // It is gated behind `cleversay_ai_expand_queries` (default false)
        // for testing purposes only; production should leave it off.
        //
        // Why it was removed: the model was being asked to guess KB
        // vocabulary it could not see. Its output was associative
        // expansion ("finish degree" → "remedial coursework, repeating
        // classes, GPA recovery..."), which dragged tangentially-related
        // chunks into retrieval. This produced inconsistent answers
        // across runs and misleading source citations.
        //
        // DO NOT re-enable expansion as a fix for vocabulary mismatch.
        // FULLTEXT NATURAL LANGUAGE MODE handles morphological variation;
        // the broad LIKE fallback (below) handles the remaining cases.
        // If a specific class of queries genuinely fails on vocabulary
        // bridging, build a deterministic synonym table for that class.
        // Do NOT reach for LLM expansion.
        //
        // See ARCHITECTURE.md → "LLM Placement Principle" for the full
        // reasoning and the principles this guard exists to enforce.
        $expanded = null;
        if (\CleverSay\NetworkSettings::ai_is_configured()
            && get_option('cleversay_ai_expand_queries', false)
        ) {
            try {
                $ai = new \CleverSay\AI();
                $expanded = $ai->expand_search_query($question_clean);
                if ($expanded && strtolower($expanded) === strtolower($question_clean)) {
                    $expanded = null; // no benefit if AI returned the same thing
                }
            } catch (\Throwable $e) {
                $expanded = null; // never fail retrieval over expansion errors
            }
        }

        // We need a slightly larger candidate pool because we'll merge two
        // result sets. Pull 2× limit from each, then trim after merge.
        $per_query_limit = $limit * 2;

        $primary   = $this->fulltext_search($question_clean, $per_query_limit);
        $secondary = $expanded
            ? $this->fulltext_search($expanded, $per_query_limit)
            : [];

        // Merge with deduplication — keep highest-scoring instance of each chunk.
        // Boost score slightly when a chunk matched on BOTH queries (it's
        // genuinely on-topic, not just a keyword echo).
        $merged = [];
        foreach ($primary as $row) {
            $merged[(int) $row['id']] = $row;
        }
        foreach ($secondary as $row) {
            $cid = (int) $row['id'];
            if (isset($merged[$cid])) {
                // Matched both queries — combine scores with a boost
                $merged[$cid]['relevance'] = (float) $merged[$cid]['relevance']
                                           + (float) $row['relevance'] * 1.2;
            } else {
                $merged[$cid] = $row;
            }
        }

        if (empty($merged)) {
            // FULLTEXT found nothing on either query — fall through to LIKE
            return $this->fallback_like_search($question_clean, $limit);
        }

        // Re-sort by combined relevance, take top $limit
        $results = array_values($merged);
        usort($results, fn($a, $b) => ($b['relevance'] <=> $a['relevance']));
        $results = array_slice($results, 0, $limit);

        if (empty($results)) {
            return [];
        }

        // ── Always append contact-info chunks from the same sources ──────────
        // Contact details (phone, email, address) are usually at the bottom of
        // a page and score low on relevance even though they're exactly what
        // students need. We detect them by pattern and add them to the context.
        $results = $this->append_contact_chunks($results);

        return $results;
    }

    /**
     * Run a single FULLTEXT NATURAL LANGUAGE search. Returns empty array
     * on error or when the FULLTEXT index doesn't exist.
     */
    private function fulltext_search(string $query, int $limit): array {
        if (!$this->has_fulltext_index()) {
            return [];
        }

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT c.*,
                        s.title as source_title,
                        s.url as source_url,
                        s.file_path as source_file_path,
                        s.file_name as source_file_name,
                        s.source_type as source_type,
                        MATCH(c.content) AGAINST (%s IN NATURAL LANGUAGE MODE) as relevance
                 FROM {$this->chunks_table} c
                 JOIN {$this->sources_table} s ON c.source_id = s.id
                 WHERE s.status = 'indexed'
                   AND MATCH(c.content) AGAINST (%s IN NATURAL LANGUAGE MODE)
                 ORDER BY relevance DESC
                 LIMIT %d",
                $query, $query, $limit
            ),
            ARRAY_A
        );

        if ($this->wpdb->last_error) {
            $this->logger->warning('FULLTEXT search failed', [
                'error' => $this->wpdb->last_error,
                'query' => substr($query, 0, 80),
            ]);
            return [];
        }

        return $results ?: [];
    }

    /**
     * Keyword LIKE fallback — used when FULLTEXT is unavailable or empty.
     */
    private function fallback_like_search(string $question_clean, int $limit): array {
        $words = array_filter(
            explode(' ', $question_clean),
            fn($w) => strlen($w) >= 4
        );

        if (empty($words)) {
            return [];
        }

        $conditions = [];
        foreach (array_slice($words, 0, 5) as $word) {
            $escaped      = $this->wpdb->esc_like($word);
            $conditions[] = $this->wpdb->prepare("c.content LIKE %s", '%' . $escaped . '%');
        }

        $where   = implode(' OR ', $conditions);
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT c.*,
                        s.title as source_title,
                        s.url as source_url,
                        s.file_path as source_file_path,
                        s.file_name as source_file_name,
                        s.source_type as source_type
                 FROM {$this->chunks_table} c
                 JOIN {$this->sources_table} s ON c.source_id = s.id
                 WHERE s.status = 'indexed' AND ({$where})
                 ORDER BY c.id ASC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Detect and append contact-information chunks from the same sources.
     *
     * After finding the relevance-matched chunks, we look in ALL chunks from
     * those same sources for anything that looks like contact info — phone
     * numbers, email addresses, office hours, building/room numbers, URLs.
     * These are appended (deduplicated) so Claude always has them available.
     *
     * @param  array $chunks Already-retrieved relevance chunks.
     * @return array         Original chunks plus any contact chunks found.
     */

    /**
     * Simple chunk retrieval — no contact detection, no exceptions.
     * Used as fallback when find_relevant_chunks throws.
     */
    public function find_relevant_chunks_simple(string $question, int $limit = 0): array {
        if ($limit === 0) {
            $limit = (int) get_option('cleversay_ai_max_chunks', self::MAX_CHUNKS);
        }

        $q = sanitize_text_field($question);

        // Direct LIKE search — always works
        $words = array_filter(explode(' ', $q), fn($w) => strlen($w) >= 4);

        if (empty($words)) {
            // Last resort: just grab the most recent indexed chunks
            return (array) $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT c.*,
                            s.title as source_title,
                            s.url as source_url,
                            s.file_path as source_file_path,
                            s.file_name as source_file_name,
                            s.source_type as source_type
                     FROM {$this->chunks_table} c
                     JOIN {$this->sources_table} s ON c.source_id = s.id
                     WHERE s.status = 'indexed'
                     ORDER BY c.id DESC
                     LIMIT %d",
                    $limit
                ),
                ARRAY_A
            );
        }

        $conditions = [];
        foreach (array_slice($words, 0, 5) as $word) {
            $esc          = $this->wpdb->esc_like($word);
            $conditions[] = $this->wpdb->prepare("c.content LIKE %s", '%' . $esc . '%');
        }

        $where = implode(' OR ', $conditions);

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT c.*,
                        s.title as source_title,
                        s.url as source_url,
                        s.file_path as source_file_path,
                        s.file_name as source_file_name,
                        s.source_type as source_type
                 FROM {$this->chunks_table} c
                 JOIN {$this->sources_table} s ON c.source_id = s.id
                 WHERE s.status = 'indexed' AND ({$where})
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return $results ?: [];
    }

    private function append_contact_chunks(array $chunks): array {
        try {
        if (empty($chunks)) {
            return $chunks;
        }

        $source_ids  = array_unique(array_column($chunks, 'source_id'));
        $already_ids = array_column($chunks, 'id');

        if (empty($source_ids)) {
            return $chunks;
        }

        // First: look in the same sources as matched chunks
        $placeholders = implode(',', array_fill(0, count($source_ids), '%d'));
        $same_source_chunks = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT c.*,
                        s.title as source_title,
                        s.url as source_url,
                        s.file_path as source_file_path,
                        s.file_name as source_file_name,
                        s.source_type as source_type
                 FROM {$this->chunks_table} c
                 JOIN {$this->sources_table} s ON c.source_id = s.id
                 WHERE c.source_id IN ($placeholders)
                 ORDER BY c.chunk_index ASC",
                ...$source_ids
            ),
            ARRAY_A
        ) ?? [];

        // Also search ALL sources for contact info related to topic —
        // contact details are often on a different page than content
        $all_contact_chunks = $this->wpdb->get_results(
            "SELECT c.*,
                    s.title as source_title,
                    s.url as source_url,
                    s.file_path as source_file_path,
                    s.file_name as source_file_name,
                    s.source_type as source_type
             FROM {$this->chunks_table} c
             JOIN {$this->sources_table} s ON c.source_id = s.id
             ORDER BY c.source_id ASC, c.chunk_index ASC
             LIMIT 500",
            ARRAY_A
        ) ?? [];

        $all_chunks = array_unique(
            array_merge($same_source_chunks, $all_contact_chunks),
            SORT_REGULAR
        );

        if (empty($all_chunks)) {
            return $chunks;
        }

        $topic_words = $this->extract_topic_words($chunks);

        $scored_contact_chunks = [];

        foreach ($all_chunks as $chunk) {
            if (in_array($chunk['id'], $already_ids)) {
                continue;
            }

            if (!$this->chunk_has_contact_info($chunk['content'])) {
                continue;
            }

            $relevance_score = $this->score_contact_relevance(
                $chunk['content'],
                $topic_words
            );

            // Include if topically relevant — use score 0 threshold for same-source,
            // require score >= 1 for cross-source to avoid unrelated contacts
            $is_same_source = in_array($chunk['source_id'], $source_ids);
            $min_score = $is_same_source ? 0 : 1;

            if ($relevance_score >= $min_score) {
                $chunk['contact_relevance'] = $relevance_score + ($is_same_source ? 10 : 0);
                $scored_contact_chunks[] = $chunk;
            }
        }

        if (empty($scored_contact_chunks)) {
            return $chunks;
        }

        // Sort by relevance score descending, take top 2
        usort($scored_contact_chunks, fn($a, $b) =>
            ($b['contact_relevance'] ?? 0) <=> ($a['contact_relevance'] ?? 0)
        );

        $appended = array_slice($scored_contact_chunks, 0, 2);

        return array_merge($chunks, $appended);
        } catch (\Throwable $e) {
            $this->logger->warning('append_contact_chunks threw exception — returning original chunks', [
                'error' => $e->getMessage(),
            ]);
            return $chunks;
        }
    }

    /**
     * Extract significant topic words from already-matched chunks.
     * These are used to check whether a contact chunk is on-topic.
     *
     * @param  array $chunks Already-retrieved relevance chunks.
     * @return array         Lowercase topic words (4+ chars, no common words).
     */
    private function extract_topic_words(array $chunks): array {
        // Common words to ignore when building topic vocabulary
        static $noise = [
            'this', 'that', 'with', 'from', 'have', 'will', 'your', 'also',
            'more', 'been', 'they', 'their', 'there', 'when', 'where', 'which',
            'what', 'some', 'such', 'than', 'then', 'into', 'each', 'other',
            'after', 'under', 'over', 'about', 'through', 'during', 'before',
            'please', 'contact', 'email', 'phone', 'office', 'information',
            'center', 'services', 'university', 'student', 'campus',
        ];

        $words = [];
        foreach ($chunks as $chunk) {
            $text  = strtolower($chunk['content'] ?? '');
            $found = preg_split('/\W+/', $text, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($found as $w) {
                if (strlen($w) >= 4 && !in_array($w, $noise)) {
                    $words[$w] = ($words[$w] ?? 0) + 1;
                }
            }
        }

        // Return words that appear at least twice — more likely to be genuinely
        // topic-specific rather than incidental.
        //
        // v4.37.82+: array_map((string)) defends against PHP's automatic
        // numeric-string → int key coercion. Words like "2026" get
        // stored as integer keys ($words[2026] not $words['2026']);
        // array_keys() returns those as ints. Downstream strpos()
        // calls then fail under PHP 8 strict typing because the
        // needle must be a string. Casting here closes the loophole
        // at its source rather than in every consumer.
        $topic = array_keys(array_filter($words, fn($count) => $count >= 2));
        return array_map('strval', $topic);
    }

    /**
     * Score how relevant a contact chunk is to the current topic.
     *
     * @param  string $contact_text  The contact chunk content.
     * @param  array  $topic_words   Topic vocabulary from matched chunks.
     * @return int                   Number of topic words found in this chunk.
     */
    private function score_contact_relevance(string $contact_text, array $topic_words): int {
        if (empty($topic_words)) {
            return 0;
        }

        $text  = strtolower($contact_text);
        $score = 0;

        foreach ($topic_words as $word) {
            if (strpos($text, $word) !== false) {
                $score++;
            }
        }

        return $score;
    }

    /**
     * Detect whether a text chunk contains contact information.
     * Looks for phone numbers, emails, office/room numbers, URLs, hours.
     */
    private function chunk_has_contact_info(string $text): bool {
        $patterns = [
            // Phone numbers: (715) 346-4771 / 715-346-4771 / 715.346.4771
            '/\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}/',
            // Email addresses
            '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
            // Room/building: "103 Student Services" / "Room 203" / "Building A"
            '/(?:room|building|suite|floor|hall|center)\s+\w+/i',
            // Office hours patterns
            '/(?:hours?|monday|tuesday|wednesday|thursday|friday|open|closed).*\d{1,2}:\d{2}/i',
            // URLs
            '/https?:\/\/[^\s]+/',
            // "Contact us" / "reach us" / "call us" / "email us" trigger words
            '/(?:contact|reach|call|email|phone|fax|location|address|visit us|stop by)/i',
            // P.O. Box
            '/P\.?O\.?\s*Box/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    // ── Text extraction ───────────────────────────────────────────────────────

    /**
     * Check whether the FULLTEXT index exists on the chunks table.
     * Cached in memory per request.
     */
    private function has_fulltext_index(): bool {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $indexes = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SHOW INDEX FROM {$this->chunks_table} WHERE Index_type = 'FULLTEXT'",
            ),
            ARRAY_A
        );

        // If the table doesn't exist at all, get_results returns null
        if ($indexes === null) {
            $cache = false;
            return false;
        }

        $cache = !empty($indexes);

        if (!$cache) {
            $this->logger->warning('FULLTEXT index missing on chunks table — attempting to add it');
            $this->wpdb->query("ALTER TABLE {$this->chunks_table} ADD FULLTEXT KEY ft_content (content)");
            // Re-check
            $indexes = $this->wpdb->get_results(
                "SHOW INDEX FROM {$this->chunks_table} WHERE Index_type = 'FULLTEXT'",
                ARRAY_A
            );
            $cache = !empty($indexes);
            if ($cache) {
                $this->logger->info('FULLTEXT index added successfully');
            }
        }

        return $cache;
    }

    public function extract_text(array $source, string $cached_html = ''): string {
        switch ($source['source_type']) {
            case 'url':  return $this->extract_url($source['url'] ?? '', $cached_html);
            case 'pdf':  return $this->extract_pdf($source['file_path'] ?? '');
            case 'docx': return $this->extract_docx($source['file_path'] ?? '');
            default:     return $this->extract_url($source['url'] ?? '', $cached_html);
        }
    }

    private function extract_url(string $url, string $cached_html = ''): string {
        if (!empty($cached_html)) {
            $html = $cached_html;
        } else {
            $response = wp_remote_get($url, [
                'timeout'    => 20,
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'sslverify'  => false,
                'headers'    => [
                    'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                ],
            ]);

            if (is_wp_error($response)) {
                throw new \RuntimeException('Could not fetch URL: ' . $response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                throw new \RuntimeException("URL returned HTTP {$code}");
            }

            $html = wp_remote_retrieve_body($response);
        }

        if (empty(trim($html))) {
            throw new \RuntimeException('Empty response body — site may be blocking the request.');
        }

        return $this->html_to_text($html);
    }

    /**
     * Extract the page title (`<title>` tag content) from a URL's HTML.
     *
     * Best-effort: returns empty string if the page can't be fetched
     * or has no usable title. Used by index_source to auto-populate
     * the source's title field when admin didn't enter one.
     *
     * Does its own fetch (separate from extract_url) so callers don't
     * need to thread cached HTML through. Lightweight regex-based
     * extraction — no need to spin up DOMDocument just for a single
     * tag.
     *
     * @since 4.37.97
     */
    public function extract_html_title(string $url): string {
        $response = wp_remote_get($url, [
            'timeout'    => 15,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'sslverify'  => false,
        ]);
        if (is_wp_error($response)) return '';
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) return '';
        $html = wp_remote_retrieve_body($response);
        if (empty($html)) return '';

        // Match the FIRST <title>...</title> in the document head.
        // Some pages have <title> inside SVG; we only want the head one,
        // which is always the first occurrence in well-formed HTML.
        if (!preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            return '';
        }
        $title = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = trim(preg_replace('/\s+/', ' ', $title));
        return $title;
    }

    /**
     * Diagnose URL extraction step by step. Returns a structured report
     * showing exactly what the indexer sees at each stage — the goal is to
     * make extraction failures explainable instead of mysterious.
     *
     * Does NOT persist anything to the database. Safe to call ad-hoc from
     * the admin UI on any URL.
     *
     * Returns: [
     *   'success'        => bool,
     *   'error'          => ?string,
     *   'http_code'      => int,
     *   'body_bytes'     => int,
     *   'content_type'   => string,
     *   'detection'      => string,        // which selector matched, or 'fallback'/'none'
     *   'extracted_text' => string,        // first 600 chars of extracted text
     *   'word_count'     => int,
     *   'char_count'     => int,
     *   'candidates_tried' => array,       // selectors checked, in order
     * ]
     */
    public function diagnose_url(string $url): array {
        $report = [
            'success'          => false,
            'error'            => null,
            'http_code'        => 0,
            'body_bytes'       => 0,
            'content_type'     => '',
            'detection'        => 'none',
            'extracted_text'   => '',
            'word_count'       => 0,
            'char_count'       => 0,
            'candidates_tried' => [],
        ];

        // ── Step 1: fetch ────────────────────────────────────────────
        $response = wp_remote_get($url, [
            'timeout'    => 20,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'sslverify'  => false,
            'headers'    => [
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ],
        ]);

        if (is_wp_error($response)) {
            $report['error'] = 'Fetch failed: ' . $response->get_error_message();
            return $report;
        }

        $report['http_code']    = (int) wp_remote_retrieve_response_code($response);
        $report['content_type'] = (string) wp_remote_retrieve_header($response, 'content-type');
        $html                   = (string) wp_remote_retrieve_body($response);
        $report['body_bytes']   = strlen($html);

        if ($report['http_code'] !== 200) {
            $report['error'] = "Server returned HTTP {$report['http_code']}";
            return $report;
        }

        if (empty(trim($html))) {
            $report['error'] = 'Empty response body — server may be blocking the request based on User-Agent or origin.';
            return $report;
        }

        // ── Step 2: parse + detect content node ─────────────────────
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            $report['error']      = 'DOMDocument could not parse the HTML at all.';
            $report['detection']  = 'parse_error';
            // Still try the fallback regex strip so we have *something*
            $stripped = preg_replace('/<(script|style|noscript)[^>]*>.*?<\/\1>/si', '', $html);
            $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
            $report['char_count']     = strlen($text);
            $report['word_count']     = str_word_count($text);
            $report['extracted_text'] = mb_substr($text, 0, 600);
            return $report;
        }

        $xpath = new \DOMXPath($dom);

        // Strip always-unwanted
        foreach ($xpath->query('//script | //style | //noscript') as $node) {
            $node->parentNode?->removeChild($node);
        }

        // Try each detector in order, recording results
        $report['candidates_tried'] = $this->probe_content_candidates($dom, $xpath);

        // Get the actual matched node (using the same logic as production)
        $scope = $this->find_main_content_node($dom, $xpath);

        if ($scope) {
            // Identify which selector matched (for the report)
            $report['detection'] = $this->identify_matched_node($scope);

            // Strip in-content nav before extracting (mirrors production)
            foreach ($xpath->query('.//nav | .//*[contains(concat(" ", normalize-space(@class), " "), " breadcrumbs ")] | .//*[contains(concat(" ", normalize-space(@class), " "), " share-buttons ")]', $scope) as $node) {
                $node->parentNode?->removeChild($node);
            }
            $text = $scope->textContent;
        } else {
            $report['detection'] = 'fallback_body';
            // Mirror the fallback path in html_to_text
            foreach ($xpath->query('//nav | //header | //footer | //aside') as $node) {
                if ($node->nodeName === 'header') {
                    $parent = $node->parentNode;
                    if ($parent && in_array($parent->nodeName, ['body', 'div'], true)) {
                        $parent_id    = $parent instanceof \DOMElement ? ($parent->getAttribute('id') ?? '') : '';
                        $parent_class = $parent instanceof \DOMElement ? ($parent->getAttribute('class') ?? '') : '';
                        $is_chrome = $parent->nodeName === 'body'
                                     || preg_match('/\b(site|page|main|wrapper|container)\b/i', $parent_id . ' ' . $parent_class);
                        if (!$is_chrome) continue;
                    }
                }
                $node->parentNode?->removeChild($node);
            }
            $body = $dom->getElementsByTagName('body')->item(0);
            $text = $body ? $body->textContent : ($dom->textContent ?? '');
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        $report['success']        = strlen($text) > 0;
        $report['char_count']     = strlen($text);
        $report['word_count']     = str_word_count($text);
        $report['extracted_text'] = mb_substr($text, 0, 600);

        return $report;
    }

    /**
     * For diagnostic reporting — try every known selector and report which
     * ones matched (and how big each match was). Doesn't change extraction
     * behavior; just gives the admin visibility.
     */
    private function probe_content_candidates(\DOMDocument $dom, \DOMXPath $xpath): array {
        $probes = [
            ['<main>',                   '//main'],
            ['[role="main"]',            '//*[@role="main"]'],
            ['<article>',                '//article'],
            ['#content',                 '//*[@id="content"]'],
            ['#main',                    '//*[@id="main"]'],
            ['#main-content',            '//*[@id="main-content"]'],
            ['#primary',                 '//*[@id="primary"]'],
            ['#page-content',            '//*[@id="page-content"]'],
            ['.et_pb_section (Divi)',    '//*[contains(concat(" ", normalize-space(@class), " "), " et_pb_section ")]'],
            ['.et_pb_post (Divi blog)',  '//*[contains(concat(" ", normalize-space(@class), " "), " et_pb_post ")]'],
            ['.et_pb_post_content',      '//*[contains(concat(" ", normalize-space(@class), " "), " et_pb_post_content ")]'],
            ['.entry-content',           '//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]'],
            ['.post-content',            '//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]'],
            ['.main-content',            '//*[contains(concat(" ", normalize-space(@class), " "), " main-content ")]'],
            ['.article-body',            '//*[contains(concat(" ", normalize-space(@class), " "), " article-body ")]'],
            ['.content-area',            '//*[contains(concat(" ", normalize-space(@class), " "), " content-area ")]'],
            ['.post',                    '//*[contains(concat(" ", normalize-space(@class), " "), " post ")]'],
        ];

        $results = [];
        foreach ($probes as [$label, $expr]) {
            $nodes = $xpath->query($expr);
            $count = $nodes ? $nodes->length : 0;
            $bytes = 0;
            if ($count > 0) {
                // Measure size of first match
                $first = $nodes->item(0);
                $bytes = strlen($first->textContent ?? '');
            }
            $results[] = [
                'selector'   => $label,
                'matches'    => $count,
                'first_size' => $bytes,
            ];
        }
        return $results;
    }

    /**
     * Identify which selector our find_main_content_node() actually matched,
     * so the diagnostic UI can show "we used <article>" vs "we used .et_pb_section".
     */
    private function identify_matched_node(\DOMNode $node): string {
        if ($node instanceof \DOMElement) {
            $tag   = $node->nodeName;
            $id    = $node->getAttribute('id');
            $class = $node->getAttribute('class');
            $parts = [];
            if ($tag) $parts[] = '<' . $tag . '>';
            if ($id) $parts[] = '#' . $id;
            if ($class) $parts[] = '.' . str_replace(' ', '.', $class);
            return implode(' ', $parts);
        }
        return $node->nodeName;
    }

    /**
     * Convert HTML to clean plain text using DOMDocument.
     *
     * Why DOMDocument and not regex: regex-based stripping fails on real-world
     * HTML where elements nest unexpectedly, lack proper closing tags, or
     * contain content the regex's non-greedy match consumes greedily across
     * unrelated elements. Divi/Elementor/Avada themes commonly trigger this
     * by wrapping content in semantic HTML5 elements (<header>, <aside>,
     * etc.) that a naïve regex would strip wholesale.
     *
     * Strategy:
     *   1. Try to find the main content area (<main>, <article>, [role=main],
     *      common content IDs/classes) and extract only its text — this
     *      avoids pulling navigation, footer, and sidebar noise into the
     *      knowledge base.
     *   2. If no main-content node is found (rare but possible on minimal
     *      HTML), fall back to whole-document text after removing only the
     *      always-safe-to-strip <script>, <style>, and <noscript> elements.
     *   3. Decode HTML entities, normalise whitespace.
     */
    private function html_to_text(string $html): string {
        $dom = new \DOMDocument();
        // Suppress libxml warnings for messy HTML — modern themes routinely
        // produce HTML with minor validation issues that don't affect parsing.
        $previous = libxml_use_internal_errors(true);
        // Hint UTF-8 to DOMDocument — without this it assumes ISO-8859-1 and
        // mangles smart quotes, em-dashes, and non-Latin characters.
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            // DOMDocument couldn't make sense of it at all — fall back to
            // a minimal regex strip just to get *something*.
            $stripped = preg_replace('/<(script|style|noscript)[^>]*>.*?<\/\1>/si', '', $html);
            return trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        }

        // Always remove always-unwanted elements regardless of scope
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//script | //style | //noscript') as $node) {
            $node->parentNode?->removeChild($node);
        }

        // Find the main content node
        $scope = $this->find_main_content_node($dom, $xpath);

        if ($scope) {
            // Inside main content, also strip in-content navigation hints
            // (breadcrumbs, share buttons, etc.) that don't add value to the
            // KB. We keep it conservative — strip only obvious chrome.
            foreach ($xpath->query('.//nav | .//*[contains(concat(" ", normalize-space(@class), " "), " breadcrumbs ")] | .//*[contains(concat(" ", normalize-space(@class), " "), " share-buttons ")]', $scope) as $node) {
                $node->parentNode?->removeChild($node);
            }
            // v4.37.106+: Inject paragraph breaks before textContent
            // extraction. Without this, DOMNode::textContent concatenates
            // sibling block elements with no separator: <h1>X</h1><p>Y</p>
            // becomes "XY". The chunker then can't find paragraph
            // boundaries (it splits on \n\n) so it produces one giant
            // chunk per page. Injecting "\n\n" after every block-level
            // element gives the chunker boundaries to work with.
            $this->inject_block_breaks($dom, $scope);
            $text = $scope->textContent;
        } else {
            // No clear main-content area — fall back to body text minus
            // navigation chrome. This is the path most pages will take only
            // when they have very minimal HTML structure.
            foreach ($xpath->query('//nav | //header | //footer | //aside') as $node) {
                // Be careful: don't strip <header> elements *inside* article
                // content (article headlines etc.). Only strip header if
                // it's a direct child of body or a top-level container.
                if ($node->nodeName === 'header') {
                    $parent = $node->parentNode;
                    if ($parent && in_array($parent->nodeName, ['body', 'div'], true)) {
                        // Only strip if the parent suggests page-level chrome
                        $parent_id    = $parent instanceof \DOMElement ? ($parent->getAttribute('id') ?? '') : '';
                        $parent_class = $parent instanceof \DOMElement ? ($parent->getAttribute('class') ?? '') : '';
                        $is_chrome = $parent->nodeName === 'body'
                                     || preg_match('/\b(site|page|main|wrapper|container)\b/i', $parent_id . ' ' . $parent_class);
                        if (!$is_chrome) continue;
                    }
                }
                $node->parentNode?->removeChild($node);
            }
            $body = $dom->getElementsByTagName('body')->item(0);
            if ($body) {
                $this->inject_block_breaks($dom, $body);
                $text = $body->textContent;
            } else {
                $text = $dom->textContent ?? '';
            }
        }

        // Decode entities + normalise whitespace
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse runs of whitespace, preserve paragraph-ish breaks
        $text = preg_replace('/[ \t]+/', ' ', $text);
        // Trim spaces that ended up at the start/end of a line
        $text = preg_replace('/[ \t]*\n[ \t]*/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Inject "\n\n" text nodes after block-level elements.
     *
     * DOMNode::textContent concatenates sibling text without respecting
     * structural boundaries — it has no idea that <h1>X</h1><p>Y</p>
     * means X and Y belong on separate lines. We work around that by
     * walking the DOM tree and adding text nodes containing "\n\n"
     * after each block-level element. textContent then preserves these
     * synthetic separators in its output.
     *
     * Block-level elements are HTML elements that browsers render as
     * separate vertical blocks (paragraphs, headings, list items, etc.)
     * vs inline elements (span, em, a) which flow within a line.
     *
     * <br> gets a single newline since it's a line-break, not a
     * paragraph break.
     *
     * @since 4.37.106
     */
    private function inject_block_breaks(\DOMDocument $dom, \DOMNode $scope): void {
        // Block-level elements that should produce a paragraph break
        // after their content. Includes structural containers (div,
        // section, article), text blocks (p, blockquote, pre),
        // headings, list items, and table cells/rows. <br> is treated
        // separately as a single newline.
        $block_tags = [
            'p', 'div', 'section', 'article', 'header', 'footer',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'li', 'tr', 'td', 'th', 'dt', 'dd',
            'blockquote', 'pre', 'figure', 'figcaption',
            'ul', 'ol', 'dl', 'table', 'tbody', 'thead', 'tfoot',
            'address',
        ];

        // Collect all block elements first; modifying the tree while
        // iterating an XPath result set is unsafe.
        $xpath = new \DOMXPath($dom);
        $selector = './/' . implode(' | .//', $block_tags);
        $nodes_to_break = [];
        foreach ($xpath->query($selector, $scope) as $node) {
            $nodes_to_break[] = $node;
        }
        foreach ($nodes_to_break as $node) {
            $break = $dom->createTextNode("\n\n");
            // Insert AFTER the block element. If it has a next sibling,
            // insert before that sibling. Otherwise append to parent.
            if ($node->nextSibling) {
                $node->parentNode?->insertBefore($break, $node->nextSibling);
            } else {
                $node->parentNode?->appendChild($break);
            }
        }

        // <br> tags get single newlines (line break within a paragraph)
        $br_nodes = [];
        foreach ($xpath->query('.//br', $scope) as $node) {
            $br_nodes[] = $node;
        }
        foreach ($br_nodes as $node) {
            $break = $dom->createTextNode("\n");
            if ($node->nextSibling) {
                $node->parentNode?->insertBefore($break, $node->nextSibling);
            } else {
                $node->parentNode?->appendChild($break);
            }
        }
    }

    /**
     * Locate the main content node in a parsed HTML document.
     * Mirrors Crawler::find_main_content_node — kept here as a private
     * method to avoid coupling the indexer to crawler internals.
     */
    private function find_main_content_node(\DOMDocument $dom, \DOMXPath $xpath): ?\DOMNode {
        // 1. <main>
        $mains = $dom->getElementsByTagName('main');
        if ($mains->length > 0) return $mains->item(0);

        // 2. [role="main"]
        $role_main = $xpath->query('//*[@role="main"]');
        if ($role_main && $role_main->length > 0) return $role_main->item(0);

        // 3. <article>
        $articles = $dom->getElementsByTagName('article');
        if ($articles->length > 0) return $articles->item(0);

        // 4. Common content container ids/classes
        $candidates = [
            '//*[@id="content"]',
            '//*[@id="main"]',
            '//*[@id="main-content"]',
            '//*[@id="primary"]',
            '//*[@id="page-content"]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " et_pb_section ")]',  // Divi sections wrapper
            '//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " main-content ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " article-body ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " content-area ")]',
        ];
        foreach ($candidates as $expr) {
            $nodes = $xpath->query($expr);
            if ($nodes && $nodes->length > 0) {
                // For Divi sections, return the parent that contains all sections,
                // not just the first one — otherwise we'd lose most of the page.
                $node = $nodes->item(0);
                if (str_contains($expr, 'et_pb_section')) {
                    return $node->parentNode ?? $node;
                }
                return $node;
            }
        }

        return null;
    }

    private function extract_pdf(string $file_path): string {
        if (!file_exists($file_path)) {
            throw new \RuntimeException('PDF file not found.');
        }

        // Method 1: pdftotext shell command (available on some Linux hosts)
        if ($this->command_exists('pdftotext')) {
            $safe = \escapeshellarg($file_path);
            $text = function_exists('shell_exec') ? \shell_exec("pdftotext -layout {$safe} -") : null;  // @phpcs:ignore
            if (!empty(trim($text ?? ''))) {
                return trim($text);
            }
        }

        $raw = file_get_contents($file_path);
        if ($raw === false) {
            throw new \RuntimeException('Could not read PDF file.');
        }

        // Method 2: Improved PHP parser — handles FlateDecode compressed streams
        $text = $this->extract_pdf_improved($raw);
        if (!empty(trim($text))) {
            return trim($text);
        }

        // Method 3: Basic stream extraction fallback
        $text = $this->extract_pdf_streams($raw);
        if (!empty(trim($text))) {
            return trim($text);
        }

        throw new \RuntimeException(
            'Could not extract text from this PDF. This usually means the PDF contains scanned images or uses an unsupported encoding. ' .
            'Try opening the PDF, selecting all text (Ctrl+A), copying it, and using the "Add Text" option instead.'
        );
    }

    /**
     * Improved PDF text extractor — handles FlateDecode compressed streams
     * and UTF-16BE encoded text (the most common modern PDF format).
     */
    private function extract_pdf_improved(string $content): string {
        $text = '';

        // Find all stream...endstream blocks
        if (!preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $stream_matches)) {
            return '';
        }

        foreach ($stream_matches[1] as $stream_data) {
            $decoded = $stream_data;

            // Try FlateDecode (zlib) decompression — most modern PDFs use this
            if (function_exists('gzuncompress')) {
                $decompressed = @gzuncompress($stream_data);
                if ($decompressed === false) {
                    // Some streams need gzinflate instead
                    $decompressed = @gzinflate(substr($stream_data, 2));
                }
                if ($decompressed !== false) {
                    $decoded = $decompressed;
                }
            }

            // Extract text operators from decoded stream
            $stream_text = $this->extract_text_from_stream($decoded);
            if (!empty($stream_text)) {
                $text .= $stream_text . "\n";
            }
        }

        // Clean up
        $text = preg_replace('/\s{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * Extract text from a decoded PDF content stream.
     * Handles Tj, TJ, Td, TD, T*, ' operators and UTF-16BE encoding.
     */
    private function extract_text_from_stream(string $stream): string {
        $text  = '';
        $in_bt = false;

        // Find BT...ET text blocks
        if (!preg_match_all('/BT\b(.*?)\bET/s', $stream, $bt_blocks)) {
            return '';
        }

        foreach ($bt_blocks[1] as $block) {
            // Handle Tj operator: (text) Tj
            if (preg_match_all('/\(([^)]*)\)\s*Tj/u', $block, $tj_matches)) {
                foreach ($tj_matches[1] as $t) {
                    $text .= $this->pdf_decode_string($t) . ' ';
                }
            }
            // Handle TJ operator: [(text) spacing (text)] TJ
            if (preg_match_all('/\[([^\]]*)\]\s*TJ/u', $block, $tj_array_matches)) {
                foreach ($tj_array_matches[1] as $arr) {
                    if (preg_match_all('/\(([^)]*)\)/u', $arr, $parts)) {
                        foreach ($parts[1] as $t) {
                            $text .= $this->pdf_decode_string($t);
                        }
                        $text .= ' ';
                    }
                }
            }
            // Handle ' operator (move to next line and show text): (text) '
            if (preg_match_all('/\(([^)]*)\)\s*\'/u', $block, $quote_matches)) {
                foreach ($quote_matches[1] as $t) {
                    $text .= "\n" . $this->pdf_decode_string($t) . ' ';
                }
            }
            // Add newline after each BT block
            $text .= "\n";
        }

        return $text;
    }

    /**
     * Decode a PDF string — handles octal escapes and UTF-16BE BOM.
     */
    private function pdf_decode_string(string $s): string {
        // Unescape PDF octal sequences like \123
        $s = preg_replace_callback('/\\\\([0-7]{1,3})/', function($m) {
            return chr(octdec($m[1]));
        }, $s);

        // Unescape common PDF escape sequences
        $s = str_replace(['\\n', '\\r', '\\t', '\\(', '\\)', '\\\\'],
                         ["\n",  "\r",  "\t",  '(',   ')',   '\\'], $s);

        // Detect and convert UTF-16BE (starts with BOM \xFE\xFF)
        if (strlen($s) >= 2 && $s[0] === "\xFE" && $s[1] === "\xFF") {
            $converted = @mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16BE');
            if ($converted !== false && !empty(trim($converted))) {
                return $converted;
            }
        }

        // Remove non-printable characters except whitespace
        $s = preg_replace('/[^\x20-\x7E\x80-\xFF\n\r\t]/', '', $s);

        return $s;
    }

    private function extract_pdf_streams(string $content): string {
        $text = '';

        // Extract text from PDF content streams
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $streams)) {
            foreach ($streams[1] as $stream) {
                // Decompress if gzip compressed
                if (str_starts_with($stream, "\x78")) {
                    $decompressed = @gzuncompress($stream);
                    if ($decompressed !== false) {
                        $stream = $decompressed;
                    }
                }

                // Extract text from BT...ET blocks
                if (preg_match_all('/BT\b(.*?)\bET/s', $stream, $blocks)) {
                    foreach ($blocks[1] as $block) {
                        // Tj / TJ operators
                        if (preg_match_all('/\(([^)]*)\)\s*(?:Tj|\'|")/u', $block, $strings)) {
                            $text .= implode(' ', $strings[1]) . ' ';
                        }
                        if (preg_match_all('/\[([^\]]*)\]\s*TJ/u', $block, $arrays)) {
                            foreach ($arrays[1] as $arr) {
                                preg_match_all('/\(([^)]*)\)/u', $arr, $parts);
                                $text .= implode('', $parts[1]) . ' ';
                            }
                        }
                    }
                }
            }
        }

        // Clean PDF escape sequences
        $text = preg_replace('/\\\\\d{3}/', ' ', $text);
        $text = str_replace(['\\n', '\\r', '\\t'], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[^\x20-\x7E\n]/', ' ', $text);
        $text = preg_replace('/\s{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function extract_docx(string $file_path): string {
        if (!file_exists($file_path)) {
            throw new \RuntimeException('File not found.');
        }

        // DOCX is a ZIP containing word/document.xml
        $zip = new \ZipArchive();
        if ($zip->open($file_path) !== true) {
            throw new \RuntimeException('Could not open DOCX file.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new \RuntimeException('Could not read DOCX content.');
        }

        // Strip XML tags, preserve paragraph breaks
        $xml  = preg_replace('/<w:p[ >]/', "\n<w:p>", $xml);
        $text = wp_strip_all_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s{3,}/', "\n\n", $text));
    }

    private function extract_file_text(string $file_path): string {
        if (empty($file_path) || !file_exists($file_path)) {
            return '';
        }
        return (string) file_get_contents($file_path);
    }

    // ── Chunking ──────────────────────────────────────────────────────────────

    public function chunk_text(string $text, int $chunk_words = self::CHUNK_WORDS, int $overlap = self::OVERLAP_WORDS): array {
        // Split on paragraph boundaries first, then overflow into word-count chunks
        $paragraphs = preg_split('/\n{2,}/', $text);
        $chunks     = [];
        $buffer     = '';
        $buf_words  = 0;

        foreach ($paragraphs as $para) {
            $para       = trim($para);
            $para_words = str_word_count($para);

            if ($buf_words + $para_words > $chunk_words && $buf_words > 0) {
                $chunks[] = trim($buffer);

                // Keep overlap from end of current buffer
                $buffer_words_arr = explode(' ', $buffer);
                $overlap_slice    = array_slice($buffer_words_arr, -$overlap);
                $buffer           = implode(' ', $overlap_slice) . "\n\n" . $para;
                $buf_words        = count($overlap_slice) + $para_words;
            } else {
                $buffer    .= ($buf_words > 0 ? "\n\n" : '') . $para;
                $buf_words += $para_words;
            }
        }

        if (!empty(trim($buffer))) {
            $chunks[] = trim($buffer);
        }

        return array_values(array_filter($chunks, fn($c) => str_word_count($c) >= 10));
    }

    private function store_chunks(int $source_id, array $chunks): void {
        // Delete existing chunks for this source
        $this->wpdb->delete($this->chunks_table, ['source_id' => $source_id]);

        foreach ($chunks as $index => $content) {
            $this->wpdb->insert($this->chunks_table, [
                'source_id'   => $source_id,
                'chunk_index' => $index,
                'content'     => $content,
                'word_count'  => str_word_count($content),
            ]);
        }

        // v4.39.0+: Phase 2 of embeddings migration. Queue chunks for
        // async embedding generation. No-op if Supabase is disabled.
        // See ARCHITECTURE.md → "Scaling Trajectory".
        if (class_exists('\\CleverSay\\Supabase') && \CleverSay\Supabase::is_enabled()) {
            try {
                (new \CleverSay\Embedder())->queue_source_chunks($source_id);
            } catch (\Throwable $e) {
                // Embedding queue failures NEVER block the indexing
                // operation. MySQL is the source of truth.
                $this->logger->warning('Embedding queue failed (non-fatal)', [
                    'source_id' => $source_id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function set_status(int $source_id, string $status, string $error = ''): void {
        $data = ['status' => $status];
        if (!empty($error)) {
            $data['error_message'] = $error;
        }
        $this->wpdb->update($this->sources_table, $data, ['id' => $source_id]);
    }

    private function command_exists(string $cmd): bool {
        $output = function_exists("shell_exec") ? \shell_exec("which {$cmd} 2>/dev/null") : null;
        return !empty(trim($output ?? ''));
    }
}
