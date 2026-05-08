<?php
/**
 * CleverSay Retriever — Phase 3 of the embeddings migration.
 *
 * Hybrid retrieval over source chunks: combines a pgvector ANN search
 * against Supabase with the existing MySQL FULLTEXT search, merging
 * the two ranked lists with Reciprocal Rank Fusion (k=60).
 *
 * Scope:
 *   - Source chunks only (content_type='chunk' on the Supabase side,
 *     cleversay_chunks on the MySQL side). KB entries are NOT touched
 *     here — Search::find_matches() continues to handle those.
 *
 * Failure handling:
 *   - Vector path is wrapped. On any exception, missing API key, or
 *     null query embedding we log a warning and return FULLTEXT-only
 *     results. The bot keeps working at pre-Phase-3 quality during
 *     Supabase outages.
 *
 * Distance gate:
 *   - If the top vector candidate has cosine similarity ≤ 0.5 we treat
 *     the query as having no good source-chunk match and return [].
 *   - The gate only fires when the vector path actually returned rows.
 *     A vector path that returned zero rows (e.g. tenant has no
 *     embeddings yet) falls open to FULLTEXT.
 *
 * Activation:
 *   - Controlled by cleversay_network_supabase['use_hybrid_retrieval'].
 *     Independent from the indexing 'enabled' flag.
 *
 * @package CleverSay
 * @since   4.40.0
 */

namespace CleverSay;

if (!defined('ABSPATH')) exit;

class Retriever {

    /** Top-N pulled from each retriever before RRF. */
    private const VECTOR_TOP_N   = 20;
    private const FULLTEXT_TOP_N = 20;

    /** Reciprocal Rank Fusion constant. */
    private const RRF_K = 60;

    /**
     * Minimum cosine similarity on the top vector candidate.
     *
     * Calibrated empirically against text-embedding-3-small. The model
     * produces lower absolute similarity scores than larger embedding
     * models — well-matched paraphrased queries (e.g. "finish my degree"
     * → "applying to graduate") land around 0.40-0.55, while clearly
     * off-topic queries fall below 0.30. The brief's original 0.5 was
     * too strict and rejected legitimate matches.
     */
    private const SIMILARITY_GATE = 0.35;

    private static ?self $instance = null;

    private \wpdb  $wpdb;
    private Logger $logger;
    private string $chunks_table;
    private string $sources_table;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->wpdb          = $wpdb;
        $this->logger        = Logger::instance();
        $this->chunks_table  = $wpdb->prefix . 'cleversay_chunks';
        $this->sources_table = $wpdb->prefix . 'cleversay_sources';
    }

    /**
     * Hybrid retrieval entry point. Returns rows shaped to match what
     * Indexer::find_relevant_chunks() consumers expect — i.e. `c.*`
     * columns from cleversay_chunks plus source_title, source_url,
     * source_file_path, source_file_name, source_type, and a numeric
     * `relevance` field (RRF score for transparency).
     *
     * @param string $query  User question.
     * @param int    $limit  Max rows to return after RRF.
     * @return array         Rows in RRF order, possibly empty.
     */
    public function retrieve(string $query, int $limit = 6): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $tenant_id = (string) get_current_blog_id();

        // Per-query observability scaffold.
        $log = [
            'query'                 => $query,
            'tenant_id'             => $tenant_id,
            'limit'                 => $limit,
            'top_vector_similarity' => null,
            'top_vector_chunk_id'   => null,
            'vector_count'          => 0,
            'fulltext_count'        => 0,
            'top_fulltext_chunk_id' => null,
            'gate_triggered'        => false,
            'vector_failed'         => false,
            'returned_count'        => 0,
            'sources_in_top'        => 0,
            'overlap_count'         => 0,
        ];

        // ── Vector path (fail-open) ─────────────────────────────────────────
        $vector_results = [];
        $vector_failed  = false;
        try {
            $embeddings = new Embeddings();
            if (!$embeddings->is_configured()) {
                throw new \RuntimeException('Embeddings client not configured');
            }
            $qvec = $embeddings->embed($query);
            if (!is_array($qvec) || empty($qvec)) {
                throw new \RuntimeException('Query embedding returned empty');
            }
            $vector_results = $this->vector_search($qvec, $tenant_id);
        } catch (\Throwable $e) {
            $vector_failed = true;
            $this->logger->warning('Retriever vector path failed; falling back to FULLTEXT', [
                'error' => $e->getMessage(),
                'query' => $query,
            ]);
        }
        $log['vector_failed'] = $vector_failed;
        $log['vector_count']  = count($vector_results);

        // ── FULLTEXT path ───────────────────────────────────────────────────
        // Run FULLTEXT unconditionally — even when the gate is about to
        // fire — so the log line carries useful diagnostic information
        // about both retrievers. The gate decision happens after this.
        $fulltext_ids = $this->fulltext_search($query);
        $log['fulltext_count'] = count($fulltext_ids);
        if (!empty($fulltext_ids)) {
            $log['top_fulltext_chunk_id'] = (int) $fulltext_ids[0];
        }

        // ── Distance gate ───────────────────────────────────────────────────
        // Only fires when the vector path actually produced ranked results.
        // A zero-row vector response falls through to FULLTEXT (likely
        // means the tenant hasn't finished indexing yet).
        if (!$vector_failed && !empty($vector_results)) {
            $top_sim = (float) ($vector_results[0]['similarity'] ?? 0.0);
            $log['top_vector_similarity'] = $top_sim;
            $log['top_vector_chunk_id']   = (int) $vector_results[0]['content_id'];

            if ($top_sim <= self::SIMILARITY_GATE) {
                $log['gate_triggered'] = true;
                $this->logger->info('Retriever distance gate fired — returning empty', $log);
                return [];
            }
        } elseif (!$vector_failed && empty($vector_results)) {
            $this->logger->warning('Retriever vector returned 0 rows; falling back to FULLTEXT', [
                'tenant_id' => $tenant_id,
                'query'     => $query,
            ]);
        }

        // ── RRF merge ───────────────────────────────────────────────────────
        $vector_ids = array_map(
            static fn($r) => (int) $r['content_id'],
            $vector_results
        );
        $log['overlap_count'] = count(array_intersect($vector_ids, $fulltext_ids));

        $merged_order = $this->rrf_merge($vector_ids, $fulltext_ids, self::RRF_K);
        $top_ids      = array_slice($merged_order, 0, $limit);

        // ── Hydrate to find_relevant_chunks() shape ────────────────────────
        $rows = $this->hydrate($top_ids, $merged_order);
        $log['returned_count'] = count($rows);
        $log['sources_in_top'] = count(array_unique(array_map(
            static fn($r) => (int) ($r['source_id'] ?? 0),
            $rows
        )));

        $this->logger->info('Retriever query', $log);
        return $rows;
    }

    /**
     * Top-N vector ANN search against Supabase. Returns rows with
     * content_id, source_id, chunk_text, similarity (descending sim).
     */
    private function vector_search(array $qvec, string $tenant_id): array {
        // Two distinct placeholders intentionally bound to the same value.
        // PDO with emulated prepares disabled doesn't reliably permit
        // reusing one named placeholder, so we use two.
        $sql = "SELECT content_id, source_id, chunk_text,
                       1 - (embedding <=> :qvec_sim::vector)::float AS similarity
                FROM cleversay_chunks
                WHERE tenant_id    = :tid
                  AND content_type = 'chunk'
                  AND is_current   = TRUE
                ORDER BY embedding <=> :qvec_sort::vector
                LIMIT " . (int) self::VECTOR_TOP_N;

        $pg_vec = Supabase::vector_to_pg($qvec);
        $rows = Supabase::instance()->query($sql, [
            ':qvec_sim'  => $pg_vec,
            ':qvec_sort' => $pg_vec,
            ':tid'       => $tenant_id,
        ]);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Top-N FULLTEXT search across MySQL chunks. Returns ordered chunk IDs.
     * Mirrors the existing find_relevant_chunks() FULLTEXT query but with
     * a larger LIMIT and selecting only the id (we hydrate later).
     */
    private function fulltext_search(string $query): array {
        $clean = sanitize_text_field($query);
        if ($clean === '') {
            return [];
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT c.id,
                        MATCH(c.content) AGAINST (%s IN NATURAL LANGUAGE MODE) AS relevance
                 FROM {$this->chunks_table} c
                 JOIN {$this->sources_table} s ON c.source_id = s.id
                 WHERE s.status = 'indexed'
                   AND MATCH(c.content) AGAINST (%s IN NATURAL LANGUAGE MODE)
                 ORDER BY relevance DESC
                 LIMIT %d",
                $clean, $clean, self::FULLTEXT_TOP_N
            ),
            ARRAY_A
        );

        if ($this->wpdb->last_error) {
            $this->logger->warning('Retriever FULLTEXT search failed', [
                'error' => $this->wpdb->last_error,
                'query' => substr($clean, 0, 80),
            ]);
            return [];
        }

        if (!$rows) {
            return [];
        }
        return array_map(static fn($r) => (int) $r['id'], $rows);
    }

    /**
     * Reciprocal Rank Fusion. Each list contributes 1/(k + rank) to the
     * score of every chunk that appears in it; chunks present in both
     * lists naturally accumulate. Returns chunk IDs in descending RRF
     * score (stable for ties — preserves first-encountered order).
     */
    private function rrf_merge(array $vector_ids, array $fulltext_ids, int $k): array {
        $scores = [];

        foreach ($vector_ids as $rank => $id) {
            $id = (int) $id;
            $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / ($k + $rank + 1);
        }
        foreach ($fulltext_ids as $rank => $id) {
            $id = (int) $id;
            $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / ($k + $rank + 1);
        }

        // arsort preserves keys (chunk IDs) and sorts by value desc, stable.
        arsort($scores, SORT_NUMERIC);
        return array_keys($scores);
    }

    /**
     * Hydrate the top-N chunk IDs from MySQL into the row shape that
     * find_relevant_chunks() consumers expect — same column set as
     * Indexer::fulltext_search() returns. Drops any IDs whose source
     * has since gone non-indexed. Preserves RRF order and tags each
     * row with its RRF score under 'relevance'.
     */
    private function hydrate(array $chunk_ids, array $merged_order): array {
        if (empty($chunk_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($chunk_ids), '%d'));

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT c.*,
                        s.title       AS source_title,
                        s.url         AS source_url,
                        s.file_path   AS source_file_path,
                        s.file_name   AS source_file_name,
                        s.source_type AS source_type
                 FROM {$this->chunks_table} c
                 JOIN {$this->sources_table} s ON c.source_id = s.id
                 WHERE s.status = 'indexed'
                   AND c.id IN ({$placeholders})",
                ...array_map('intval', $chunk_ids)
            ),
            ARRAY_A
        );

        if (!$rows) {
            return [];
        }

        // Index by id, then walk RRF order to preserve ranking.
        $by_id = [];
        foreach ($rows as $r) {
            $by_id[(int) $r['id']] = $r;
        }

        $rank_of = array_flip($merged_order); // chunk_id => rank index

        $ordered = [];
        foreach ($chunk_ids as $cid) {
            $cid = (int) $cid;
            if (!isset($by_id[$cid])) {
                continue; // dropped: source no longer indexed
            }
            $row  = $by_id[$cid];
            $rank = $rank_of[$cid] ?? null;
            $row['relevance'] = ($rank !== null)
                ? round(1.0 / (self::RRF_K + (int) $rank + 1), 6)
                : 0.0;
            $ordered[] = $row;
        }

        return $ordered;
    }
}
