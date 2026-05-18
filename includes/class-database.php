<?php
/**
 * CleverSay Database Handler
 * 
 * Handles database operations, table creation, and data migration
 * 
 * @package CleverSay
 * @since 2.0.0
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class Database {
    
    /**
     * WordPress database object
     */
    private \wpdb $wpdb;
    
    /**
     * Table names
     */
    public string $knowledge_base;
    public string $questions_log;
    public string $visitors;
    public string $synonyms;
    public string $stopwords;
    public string $ratings;
    public string $inquiries;
    public string $categories;
    public string $sources;
    public string $chunks;
    public string $ai_answers;
    public string $ai_answer_sources;
    public string $conversation_ratings;
    public string $source_usage;
    public string $leads;
    public string $embedding_queue;
    /**
     * v4.41.5+: per-request latency metrics. One row per AJAX search
     * request that produced a logged question. See class-request-timer.php
     * for the writer side and the latency dashboard view for the reader.
     */
    public string $request_metrics;
    /**
     * v4.42.0+: bulk question testing infrastructure. Used during
     * pre-deployment qualification — operator uploads a CSV of historical
     * + speculative student questions, the runner processes them through
     * the same path as live traffic (search → AI fallback → synthesis),
     * and dumps results for offline review. NOT for ongoing regression
     * testing; that's a separate concern. See class-bulk-tester.php.
     */
    public string $bulk_test_runs;
    public string $bulk_test_results;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        
        // Define table names with WordPress prefix
        $this->knowledge_base = $wpdb->prefix . 'cleversay_knowledge';
        $this->questions_log = $wpdb->prefix . 'cleversay_questions';
        $this->visitors = $wpdb->prefix . 'cleversay_visitors';
        $this->synonyms = $wpdb->prefix . 'cleversay_synonyms';
        $this->stopwords = $wpdb->prefix . 'cleversay_stopwords';
        $this->ratings = $wpdb->prefix . 'cleversay_ratings';
        $this->inquiries = $wpdb->prefix . 'cleversay_inquiries';
        $this->categories = $wpdb->prefix . 'cleversay_categories';
        $this->sources    = $wpdb->prefix . 'cleversay_sources';
        $this->chunks     = $wpdb->prefix . 'cleversay_chunks';
        $this->ai_answers = $wpdb->prefix . 'cleversay_ai_answers';
        $this->ai_answer_sources = $wpdb->prefix . 'cleversay_ai_answer_sources';
        $this->conversation_ratings = $wpdb->prefix . 'cleversay_conversation_ratings';
        $this->source_usage = $wpdb->prefix . 'cleversay_source_usage';
        $this->leads = $wpdb->prefix . 'cleversay_leads';
        // v4.39.0+: queue for chunks awaiting embedding generation.
        // See ARCHITECTURE.md → Phase 2 of the embeddings migration.
        $this->embedding_queue = $wpdb->prefix . 'cleversay_embedding_queue';
        // v4.41.5+: per-request latency metrics. One row per AJAX search
        // that produced a logged question, FK'd to cleversay_questions.id.
        $this->request_metrics = $wpdb->prefix . 'cleversay_request_metrics';
        // v4.42.0+: bulk question testing — CSV-driven qualification runs.
        $this->bulk_test_runs    = $wpdb->prefix . 'cleversay_bulk_test_runs';
        $this->bulk_test_results = $wpdb->prefix . 'cleversay_bulk_test_results';
    }
    
    /**
     * Create all required database tables
     */

    /**
     * Strip backslashes from text fields that were saved before wp_unslash was applied.
     * Runs once on upgrade to 2.4.4+
     */
    public function strip_slashes_from_records(): void {
        global $wpdb;

        // Knowledge base — keyword, sub_keyword, question, response
        $rows = $wpdb->get_results(
            "SELECT id, keyword, sub_keyword, question, response FROM {$this->knowledge_base}
             WHERE keyword LIKE '%\\\\%' OR question LIKE '%\\\\%' OR response LIKE '%\\\\%'",
            ARRAY_A
        );
        foreach ((array)$rows as $row) {
            $wpdb->update($this->knowledge_base, [
                'keyword'     => stripslashes($row['keyword']),
                'sub_keyword' => $row['sub_keyword'] ? stripslashes($row['sub_keyword']) : null,
                'question'    => stripslashes($row['question']),
                'response'    => stripslashes($row['response']),
            ], ['id' => $row['id']]);
        }

        // AI answers — question, answer
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->ai_answers))) {
            $ai_rows = $wpdb->get_results(
                "SELECT id, question, answer FROM {$this->ai_answers}
                 WHERE question LIKE '%\\\\%' OR answer LIKE '%\\\\%'",
                ARRAY_A
            );
            foreach ((array)$ai_rows as $row) {
                $wpdb->update($this->ai_answers, [
                    'question' => stripslashes($row['question']),
                    'answer'   => stripslashes($row['answer']),
                ], ['id' => $row['id']]);
            }
        }

        // Phrase patterns
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->knowledge_base . '_phrases'))) {
            return; // table may not exist on all installs
        }
    }

    public function create_tables(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        $charset_collate = $this->wpdb->get_charset_collate();
        
        // ── Knowledge entry variations (v4.31.0+) ─────────────────────
        // Each KB entry can have multiple "question variations" — natural
        // phrasings that students might use to ask the same question.
        // Variations are the new primary editing UX; the auto-generated
        // pattern in cleversay_knowledge.sub_keyword is derived from them.
        // Existing entries (no variations) continue to work via the old
        // pattern field unchanged.
        $sql_kb_variations = "CREATE TABLE {$wpdb->prefix}cleversay_kb_variations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            knowledge_id BIGINT UNSIGNED NOT NULL,
            variation_text VARCHAR(500) NOT NULL,
            sort_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_knowledge (knowledge_id),
            INDEX idx_sort (knowledge_id, sort_order)
        ) $charset_collate;";

        // Knowledge Base Table (Main FAQ/Response storage)
        $sql_knowledge = "CREATE TABLE {$this->knowledge_base} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(255) NOT NULL,
            sub_keyword VARCHAR(500) DEFAULT NULL,
            question TEXT NOT NULL,
            response LONGTEXT NOT NULL,
            category_id BIGINT(20) UNSIGNED DEFAULT NULL,
            search_type ENUM('keyword', 'pattern', 'exact') DEFAULT 'keyword',
            status ENUM('active', 'inactive', 'hold', 'expired') DEFAULT 'hold',
            show_rating TINYINT(1) DEFAULT 1,
            hits INT(11) DEFAULT 0,
            helpful_yes INT(11) DEFAULT 0,
            helpful_no INT(11) DEFAULT 0,
            reuse_response TINYINT(1) DEFAULT 0,
            reuse_keyword VARCHAR(255) DEFAULT NULL,
            reuse_sub_keyword VARCHAR(500) DEFAULT NULL,
            polished_hash VARCHAR(40) DEFAULT NULL,
            expires_at DATE DEFAULT NULL,
            created_by BIGINT(20) UNSIGNED DEFAULT NULL,
            approved_by BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_keyword (keyword),
            INDEX idx_sub_keyword (sub_keyword(191)),
            INDEX idx_status (status),
            INDEX idx_category (category_id),
            INDEX idx_search_type (search_type),
            FULLTEXT INDEX ft_search (keyword, sub_keyword, question, response)
        ) $charset_collate;";
        
        // Questions Log Table
        $sql_questions = "CREATE TABLE {$this->questions_log} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            question TEXT NOT NULL,
            original_question TEXT DEFAULT NULL,
            detected_language VARCHAR(10) DEFAULT NULL,
            matched_keyword VARCHAR(255) DEFAULT NULL,
            matched_sub_keyword VARCHAR(500) DEFAULT NULL,
            knowledge_id BIGINT(20) UNSIGNED DEFAULT NULL,
            match_type ENUM('exact', 'partial', 'pattern', 'none') DEFAULT 'none',
            match_score DECIMAL(5,2) DEFAULT 0.00,
            ai_rejected TINYINT(1) NOT NULL DEFAULT 0,
            ai_rejection_reason VARCHAR(255) DEFAULT NULL,
            ai_tiebreak TINYINT(1) NOT NULL DEFAULT 0,
            ai_tiebreak_chosen_id BIGINT(20) UNSIGNED DEFAULT NULL,
            ai_tiebreak_tied_ids VARCHAR(255) DEFAULT NULL,
            ai_provider VARCHAR(20) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            referer TEXT DEFAULT NULL,
            session_id VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_knowledge (knowledge_id),
            INDEX idx_created (created_at),
            INDEX idx_match_type (match_type),
            INDEX idx_ai_rejected (ai_rejected),
            INDEX idx_language (detected_language),
            FULLTEXT INDEX ft_question (question)
        ) $charset_collate;";
        
        // Visitors Table
        $sql_visitors = "CREATE TABLE {$this->visitors} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            ip_address VARCHAR(45) NOT NULL,
            country_code VARCHAR(2) DEFAULT NULL,
            country_name VARCHAR(100) DEFAULT NULL,
            region VARCHAR(100) DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            latitude DECIMAL(10,8) DEFAULT NULL,
            longitude DECIMAL(11,8) DEFAULT NULL,
            visit_count INT(11) DEFAULT 1,
            first_visit DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_visit DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_ip (ip_address),
            INDEX idx_country (country_code),
            INDEX idx_last_visit (last_visit)
        ) $charset_collate;";
        
        // Synonyms Table (for spell check and word variants)
        $sql_synonyms = "CREATE TABLE {$this->synonyms} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            canonical_word VARCHAR(255) NOT NULL,
            variant_words TEXT NOT NULL,
            misspellings TEXT DEFAULT NULL,
            is_phrase TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_canonical (canonical_word),
            INDEX idx_active (is_active)
        ) $charset_collate;";
        
        // Stopwords Table
        $sql_stopwords = "CREATE TABLE {$this->stopwords} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            word VARCHAR(50) NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY idx_word (word)
        ) $charset_collate;";
        
        // Ratings Table
        $sql_ratings = "CREATE TABLE {$this->ratings} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            knowledge_id BIGINT(20) UNSIGNED NOT NULL,
            question_log_id BIGINT(20) UNSIGNED DEFAULT NULL,
            rating ENUM('helpful', 'not_helpful') NOT NULL,
            feedback TEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_knowledge (knowledge_id),
            INDEX idx_rating (rating),
            INDEX idx_created (created_at)
        ) $charset_collate;";
        
        // Inquiries Table (for unanswered questions)
        $sql_inquiries = "CREATE TABLE {$this->inquiries} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            question TEXT NOT NULL,
            details TEXT DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            name VARCHAR(255) DEFAULT NULL,
            status ENUM('pending', 'answered', 'archived', 'spam') DEFAULT 'pending',
            response TEXT DEFAULT NULL,
            responded_by BIGINT(20) UNSIGNED DEFAULT NULL,
            responded_at DATETIME DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            transcript LONGTEXT DEFAULT NULL,
            handoff_type VARCHAR(32) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_status (status),
            INDEX idx_created (created_at),
            INDEX idx_handoff_type (handoff_type)
        ) $charset_collate;";
        
        // Categories Table
        $sql_categories = "CREATE TABLE {$this->categories} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            parent_id BIGINT(20) UNSIGNED DEFAULT NULL,
            sort_order INT(11) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_slug (slug),
            INDEX idx_parent (parent_id),
            INDEX idx_active (is_active)
        ) $charset_collate;";
        

        $sql_sources = "CREATE TABLE {$this->sources} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(500) NOT NULL DEFAULT '',
            source_type ENUM('pdf','url','text','docx') NOT NULL DEFAULT 'text',
            url VARCHAR(2000) DEFAULT NULL,
            file_path VARCHAR(1000) DEFAULT NULL,
            file_name VARCHAR(500) DEFAULT NULL,
            status ENUM('pending','indexing','indexed','error') NOT NULL DEFAULT 'pending',
            chunk_count INT(11) NOT NULL DEFAULT 0,
            word_count INT(11) NOT NULL DEFAULT 0,
            error_message TEXT DEFAULT NULL,
            refresh_interval VARCHAR(16) NOT NULL DEFAULT 'twice_monthly',
            content_hash VARCHAR(64) DEFAULT NULL,
            last_crawled_at DATETIME DEFAULT NULL,
            last_change_at DATETIME DEFAULT NULL,
            crawl_status VARCHAR(16) DEFAULT NULL,
            crawl_error TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_status (status),
            INDEX idx_refresh_interval (refresh_interval),
            INDEX idx_last_crawled (last_crawled_at)
        ) $charset_collate;";

        $sql_chunks = "CREATE TABLE {$this->chunks} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id BIGINT(20) UNSIGNED NOT NULL,
            chunk_index INT(11) NOT NULL DEFAULT 0,
            content LONGTEXT NOT NULL,
            word_count INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_source (source_id),
            FULLTEXT KEY ft_content (content)
        ) $charset_collate;";


        $sql_ai_answers = "CREATE TABLE {$this->ai_answers} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            question TEXT NOT NULL,
            answer LONGTEXT NOT NULL,
            source_titles TEXT DEFAULT NULL,
            status ENUM('pending','promoted','rejected') NOT NULL DEFAULT 'pending',
            knowledge_id BIGINT(20) UNSIGNED DEFAULT NULL,
            reviewed_by BIGINT(20) UNSIGNED DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            kb_rejected TINYINT(1) NOT NULL DEFAULT 0,
            rejected_keyword VARCHAR(255) DEFAULT NULL,
            rejected_reason VARCHAR(64) DEFAULT NULL,
            rejected_kb_answer LONGTEXT DEFAULT NULL,
            conversation_id VARCHAR(32) DEFAULT NULL,
            history_json LONGTEXT DEFAULT NULL,
            logged_question_id BIGINT(20) UNSIGNED DEFAULT NULL,
            rating TINYINT(1) DEFAULT NULL,
            rated_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_status (status),
            INDEX idx_created (created_at),
            INDEX idx_kb_rejected (kb_rejected),
            INDEX idx_conversation (conversation_id),
            INDEX idx_logged_question (logged_question_id),
            INDEX idx_rating (rating)
        ) $charset_collate;";

        $sql_conversation_ratings = "CREATE TABLE {$this->conversation_ratings} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id VARCHAR(32) DEFAULT NULL,
            rating ENUM('helpful','somewhat','not_helpful') NOT NULL,
            comment TEXT DEFAULT NULL,
            turn_count INT(11) UNSIGNED DEFAULT 0,
            resolved TINYINT(1) NOT NULL DEFAULT 0,
            ip_address VARCHAR(45) DEFAULT NULL,
            session_id VARCHAR(255) DEFAULT NULL,
            history_json LONGTEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_conversation (conversation_id),
            INDEX idx_rating (rating),
            INDEX idx_created (created_at)
        ) $charset_collate;";

        // v4.37.89+: Source citations for AI answers. Each row is one
        // citation linking an ai_answers row to a source. position
        // preserves the order chunks were synthesized in (mainly for
        // display consistency). source_id has no FK constraint —
        // matches the soft-FK pattern used elsewhere in the schema.
        // If a source is deleted later, citations become orphans that
        // resolve to "(removed source)" in the UI; safer than CASCADE.
        $sql_ai_answer_sources = "CREATE TABLE {$this->ai_answer_sources} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            answer_id BIGINT(20) UNSIGNED NOT NULL,
            source_id BIGINT(20) UNSIGNED NOT NULL,
            chunk_id BIGINT(20) UNSIGNED DEFAULT NULL,
            position INT(11) NOT NULL DEFAULT 0,
            snippet TEXT DEFAULT NULL,
            used_in_answer TINYINT(1) NOT NULL DEFAULT 1,
            overlap_score INT(11) NOT NULL DEFAULT 0,
            route_used VARCHAR(16) NOT NULL DEFAULT 'heuristic',
            llm_score DECIMAL(3,2) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_answer (answer_id),
            INDEX idx_source (source_id),
            INDEX idx_chunk (chunk_id),
            INDEX idx_used (used_in_answer),
            UNIQUE KEY uniq_answer_source (answer_id, source_id)
        ) $charset_collate;";

        $sql_source_usage = "CREATE TABLE {$this->source_usage} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id BIGINT(20) UNSIGNED NOT NULL,
            conversation_id VARCHAR(32) DEFAULT NULL,
            ai_answer_id BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_source (source_id),
            INDEX idx_conversation (conversation_id),
            INDEX idx_ai_answer (ai_answer_id),
            INDEX idx_created (created_at)
        ) $charset_collate;";

        // Captured leads from the pre-chat lead-capture form
        $sql_leads = "CREATE TABLE {$this->leads} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(100) DEFAULT NULL,
            last_name VARCHAR(100) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            identity VARCHAR(100) DEFAULT NULL,
            date_of_birth DATE DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            extra_json LONGTEXT DEFAULT NULL,
            conversation_id VARCHAR(32) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            referer TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_email (email),
            INDEX idx_identity (identity),
            INDEX idx_conversation (conversation_id),
            INDEX idx_created (created_at)
        ) $charset_collate;";

        // ── AI debug log ──────────────────────────────────────────────
        // Captures the full AI request/response for diagnostic review.
        // Only populated when admin enables debug capture mode OR when a
        // thumbs-down rating triggers automatic capture. Auto-purges to
        // avoid unbounded growth (see AIDebugLog::prune()).
        $sql_ai_debug = "CREATE TABLE {$wpdb->prefix}cleversay_ai_debug_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            question_id BIGINT UNSIGNED DEFAULT NULL,
            ai_answer_id BIGINT UNSIGNED DEFAULT NULL,
            question TEXT NOT NULL,
            system_prompt MEDIUMTEXT NOT NULL,
            chunks_json MEDIUMTEXT DEFAULT NULL,
            history_json TEXT DEFAULT NULL,
            ai_response MEDIUMTEXT DEFAULT NULL,
            final_answer MEDIUMTEXT DEFAULT NULL,
            kb_match_keyword VARCHAR(255) DEFAULT NULL,
            kb_match_score INT DEFAULT NULL,
            validator_decision VARCHAR(20) DEFAULT NULL,
            polish_applied TINYINT(1) DEFAULT 0,
            latency_ms INT DEFAULT NULL,
            trigger_reason VARCHAR(40) DEFAULT 'manual',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_created (created_at),
            INDEX idx_trigger (trigger_reason),
            INDEX idx_question_id (question_id)
        ) $charset_collate;";

        // v4.39.0+: Embedding queue table.
        // See ARCHITECTURE.md → Phase 2 of the embeddings migration.
        //
        // Tracks content that needs embeddings generated and pushed
        // to Supabase. Two content types: 'chunk' (rows from
        // cleversay_chunks, derived from sources) and 'kb_entry'
        // (rows from cleversay_knowledge, manually authored Q&A).
        //
        // Status flow: pending → processing → done (or failed).
        // Failed jobs retry up to retry_count times before being
        // marked permanently failed for admin review.
        //
        // The unique key (content_type, content_id) prevents duplicate
        // queue entries — re-queueing the same chunk just updates the
        // existing row to pending.
        $sql_embedding_queue = "CREATE TABLE {$this->embedding_queue} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            content_type ENUM('chunk','kb_entry') NOT NULL,
            content_id BIGINT(20) UNSIGNED NOT NULL,
            blog_id BIGINT(20) UNSIGNED NOT NULL,
            status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
            retry_count INT(11) NOT NULL DEFAULT 0,
            last_error TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_content (content_type, content_id),
            INDEX idx_status_retry (status, retry_count),
            INDEX idx_blog_status (blog_id, status)
        ) $charset_collate;";

        // v4.41.5+: per-request latency metrics. One row per AJAX search
        // that produced a logged question. RequestTimer writes the row
        // from the shutdown handler. The latency dashboard reads from
        // here for the headline numbers and recent-queries table.
        //
        // FK to cleversay_questions.id is logical (not a real FOREIGN
        // KEY constraint) — WordPress conventionally uses logical FKs
        // since not all storage engines support them, and orphan rows
        // are tolerated. The 90-day prune cron (in class-metrics-pruner)
        // keeps the table small without referential constraints.
        //
        // matched_layer enum is explicit so analytics queries can ask
        // "what fraction of requests went all the way through synthesis"
        // without deriving it from flag combinations.
        //
        // Indexes: question_id for "find metrics for this question",
        // created_at for time-range queries (last 24h),
        // total_ms for "find slowest queries" without scanning.
        $sql_request_metrics = "CREATE TABLE {$this->request_metrics} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            question_id BIGINT(20) UNSIGNED NOT NULL,
            total_ms INT(11) NOT NULL,
            kb_ms INT(11) NOT NULL,
            retrieval_ms INT(11) DEFAULT NULL,
            synthesis_ms INT(11) DEFAULT NULL,
            render_ms INT(11) NOT NULL,
            ai_fallback_fired TINYINT(1) NOT NULL DEFAULT 0,
            cache_hit TINYINT(1) NOT NULL DEFAULT 0,
            gate_fired TINYINT(1) DEFAULT NULL,
            matched_layer ENUM('kb_strong','kb_weak_with_ai','ai_only','no_answer') NOT NULL DEFAULT 'no_answer',
            tokens_in INT(11) DEFAULT NULL,
            tokens_out INT(11) DEFAULT NULL,
            cost DECIMAL(10,6) DEFAULT NULL,
            synthesis_model VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_question (question_id),
            INDEX idx_created (created_at),
            INDEX idx_total_ms (total_ms)
        ) $charset_collate;";

        // v4.42.0+: bulk testing tables. One run = one CSV import/execute
        // session. One result row per question in that run. Persisted so
        // operators can compare runs over time and re-download the result
        // CSV without re-running. Two-table design (runs + results) so we
        // can show run-level metadata (status, timings, summary) without
        // joining/aggregating every results row each time.
        $sql_bulk_test_runs = "CREATE TABLE {$this->bulk_test_runs} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            label VARCHAR(255) DEFAULT NULL,
            status ENUM('pending','running','completed','failed','aborted') NOT NULL DEFAULT 'pending',
            total_questions INT(11) NOT NULL DEFAULT 0,
            completed_questions INT(11) NOT NULL DEFAULT 0,
            synthesis_model VARCHAR(100) DEFAULT NULL,
            total_cost DECIMAL(10,6) DEFAULT 0.000000,
            started_at DATETIME DEFAULT NULL,
            finished_at DATETIME DEFAULT NULL,
            created_by BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) $charset_collate;";

        $sql_bulk_test_results = "CREATE TABLE {$this->bulk_test_results} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id BIGINT(20) UNSIGNED NOT NULL,
            row_index INT(11) NOT NULL,
            question TEXT NOT NULL,
            notes TEXT DEFAULT NULL,
            status ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
            matched_layer VARCHAR(40) DEFAULT NULL,
            ai_fallback_fired TINYINT(1) DEFAULT NULL,
            top_vector_similarity DECIMAL(6,4) DEFAULT NULL,
            top_vector_chunk_id BIGINT(20) UNSIGNED DEFAULT NULL,
            top_fulltext_chunk_id BIGINT(20) UNSIGNED DEFAULT NULL,
            returned_chunk_ids TEXT DEFAULT NULL,
            gate_fired TINYINT(1) DEFAULT NULL,
            synthesis_model VARCHAR(100) DEFAULT NULL,
            answer_text MEDIUMTEXT DEFAULT NULL,
            tokens_in INT(11) DEFAULT NULL,
            tokens_out INT(11) DEFAULT NULL,
            cost DECIMAL(10,6) DEFAULT NULL,
            total_ms INT(11) DEFAULT NULL,
            error TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_run (run_id),
            INDEX idx_run_index (run_id, row_index),
            INDEX idx_run_status (run_id, status)
        ) $charset_collate;";

        // Execute table creation
        dbDelta($sql_knowledge);
        dbDelta($sql_questions);
        dbDelta($sql_visitors);
        dbDelta($sql_synonyms);
        dbDelta($sql_stopwords);
        dbDelta($sql_ratings);
        dbDelta($sql_inquiries);
        dbDelta($sql_categories);
        dbDelta($sql_sources);
        dbDelta($sql_chunks);
        dbDelta($sql_embedding_queue);
        dbDelta($sql_request_metrics);
        dbDelta($sql_bulk_test_runs);
        dbDelta($sql_bulk_test_results);
        dbDelta($sql_ai_answers);
        dbDelta($sql_ai_answer_sources);
        dbDelta($sql_conversation_ratings);
        dbDelta($sql_source_usage);
        dbDelta($sql_leads);
        dbDelta($sql_ai_debug);
        dbDelta($sql_kb_variations);
        
        // Store database version
        update_option('cleversay_db_version', CLEVERSAY_DB_VERSION);
        
        // Insert default stopwords
        $this->insert_default_stopwords();
    }
    
    /**
     * Import legacy ailiza_spellcheck synonyms into the v4 synonyms
     * table. Non-destructive: rows whose canonical_word already
     * exists in the table are skipped (admin's existing data wins).
     * Idempotent — safe to re-run any number of times.
     *
     * Source data lives in includes/data-legacy-synonyms.php; see that
     * file for the schema mapping and is_phrase detection rules.
     *
     * @return array{
     *   total_legacy_rows: int,
     *   inserted: int,
     *   skipped_existing: int,
     *   failed: int,
     *   errors: string[],
     * }
     */
    public function import_legacy_synonyms(): array {
        $stats = [
            'total_legacy_rows' => 0,
            'inserted'          => 0,
            'skipped_existing'  => 0,
            'failed'            => 0,
            'errors'            => [],
        ];

        if (!function_exists('CleverSay\\cleversay_legacy_synonyms')) {
            $stats['errors'][] = 'Legacy synonyms data file not loaded.';
            return $stats;
        }

        $rows = \CleverSay\cleversay_legacy_synonyms();
        $stats['total_legacy_rows'] = count($rows);

        foreach ($rows as $r) {
            $canonical = strtolower(trim((string) ($r['canonical_word'] ?? '')));
            if ($canonical === '') continue;

            // Don't overwrite admin-curated rows.
            $exists = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->synonyms} WHERE canonical_word = %s",
                $canonical
            ));
            if ($exists > 0) {
                $stats['skipped_existing']++;
                continue;
            }

            $result = $this->wpdb->insert(
                $this->synonyms,
                [
                    'canonical_word' => $canonical,
                    'variant_words'  => (string) ($r['variant_words']  ?? ''),
                    'misspellings'   => (string) ($r['misspellings']   ?? ''),
                    'is_phrase'      => (int)    ($r['is_phrase']      ?? 0),
                    'is_active'      => 1,
                ],
                ['%s', '%s', '%s', '%d', '%d']
            );

            if ($result === false) {
                $stats['failed']++;
                $stats['errors'][] = sprintf('Insert failed for canonical=%s: %s', $canonical, $this->wpdb->last_error);
            } else {
                $stats['inserted']++;
            }
        }

        // Flush the live synonym cache so changes take effect on the next request.
        wp_cache_delete('synonyms', 'cleversay_search');

        return $stats;
    }

    /**
     * Disable question-word stopwords on existing installs.
     *
     * Question words (what, when, where, who, why, how, which, whom)
     * were seeded as active stopwords in pre-4.37.2 installs. They are
     * no longer seeded in fresh installs (see insert_default_stopwords)
     * because legacy CleverSay patterns frequently use them as content
     * words. This method runs once during the 4.37.2 upgrade to set
     * is_active=0 for those rows on existing installs — soft-disable
     * rather than delete, so admins can re-enable individually via
     * Settings if they have a reason. Idempotent.
     */
    public function disable_question_word_stopwords(): void {
        $words = ['what', 'when', 'where', 'who', 'whom', 'why', 'how', 'which'];
        foreach ($words as $w) {
            $this->wpdb->update(
                $this->stopwords,
                ['is_active' => 0],
                ['word' => $w],
                ['%d'],
                ['%s']
            );
        }
        // Flush any cached stopword list immediately so changes take
        // effect without waiting for the 5-min TTL.
        wp_cache_delete('stopwords', 'cleversay_search');
    }

    /**
     * Disable negation/temporal-meaning stopwords on existing installs.
     *
     * `no`, `not`, `before`, `after` were seeded as active stopwords
     * in pre-4.37.17 installs. They invert or frame the meaning of a
     * question and shouldn't be silently stripped at the matching
     * layer. This method runs once during the 4.37.17 upgrade to
     * set is_active=0 for these rows on existing installs.
     * Soft-disable so admins can re-enable individually via Settings
     * if they want. Idempotent.
     */
    public function disable_negation_stopwords(): void {
        $words = ['no', 'not', 'before', 'after'];
        foreach ($words as $w) {
            $this->wpdb->update(
                $this->stopwords,
                ['is_active' => 0],
                ['word' => $w],
                ['%d'],
                ['%s']
            );
        }
        wp_cache_delete('stopwords', 'cleversay_search');
    }

    /**
     * Add subordinator/connective stopwords on existing installs.
     *
     * `if`, `about`, `because`, `although`, `though` weren't in the
     * original v2.x seed list, so existing installs may not have
     * them. The compiler's fallback list has had `if` and `about`
     * for a while, but the runtime didn't strip them — leaving
     * compile/runtime drift visible in the debug trace ("if" passing
     * through to final search keywords).
     *
     * Inserts the rows with is_active=1. Idempotent — REPLACE INTO
     * prevents duplicate-key errors. Existing rows with the same
     * word are left as the admin set them (the REPLACE preserves
     * rows but resets is_active=1 — actually that's wrong;
     * REPLACE does delete-then-insert. Using INSERT IGNORE instead
     * to leave admin choices intact).
     *
     * @since 4.37.30
     */
    public function insert_subordinator_stopwords(): void {
        $words = ['if', 'about', 'because', 'although', 'though'];
        foreach ($words as $w) {
            // INSERT IGNORE preserves any existing row's is_active
            // setting (admin may have explicitly disabled one). New
            // rows default to is_active=1.
            $this->wpdb->query($this->wpdb->prepare(
                "INSERT IGNORE INTO {$this->stopwords} (word, is_active) VALUES (%s, 1)",
                $w
            ));
        }
        wp_cache_delete('stopwords', 'cleversay_search');
    }

    /**
     * Add structured-logging columns to questions_log so AI tiebreak
     * decisions and provider attribution can be queried by date range.
     *
     * Without these columns, the AI Decisions admin page would have
     * to grep the unstructured debug log to find tiebreak events —
     * not viable for an observation surface.
     *
     * Idempotent: each ALTER is wrapped in a column-existence check
     * so repeat calls (or running on a fresh schema where the
     * columns are already present from CREATE TABLE) is a no-op.
     *
     * @since 4.37.50
     */
    public function add_tiebreak_columns(): void {
        $table = $this->questions_log;
        $columns_to_add = [
            'ai_tiebreak'           => "TINYINT(1) NOT NULL DEFAULT 0",
            'ai_tiebreak_chosen_id' => "BIGINT(20) UNSIGNED DEFAULT NULL",
            'ai_tiebreak_tied_ids'  => "VARCHAR(255) DEFAULT NULL",
            'ai_provider'           => "VARCHAR(20) DEFAULT NULL",
        ];
        foreach ($columns_to_add as $col => $definition) {
            $exists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = %s
                    AND COLUMN_NAME = %s",
                $table,
                $col
            ));
            if ((int) $exists === 0) {
                $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$definition}");
            }
        }
        // Index on ai_tiebreak so the AI Decisions page can filter
        // efficiently. Same idempotence pattern.
        $idx_exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND INDEX_NAME = 'idx_ai_tiebreak'",
            $table
        ));
        if ((int) $idx_exists === 0) {
            $this->wpdb->query("ALTER TABLE {$table} ADD INDEX idx_ai_tiebreak (ai_tiebreak)");
        }
    }

    /**
     * Add polished_hash column to knowledge_base.
     *
     * Stores a hash of the response text at the time admin
     * approved an AI polish. The runtime Polish KB step compares
     * the current response's hash against this stored value: match
     * means the response hasn't been edited since polishing, so
     * the runtime polish call is redundant and can be skipped.
     *
     * Idempotent — column-existence check before the ALTER.
     *
     * @since 4.37.52
     */
    public function add_polished_hash_column(): void {
        $table = $this->knowledge_base;
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND COLUMN_NAME = 'polished_hash'",
            $table
        ));
        if ((int) $exists === 0) {
            $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN polished_hash VARCHAR(40) DEFAULT NULL");
        }
    }

    /**
     * v4.41.5+: idempotent install of the cleversay_request_metrics table
     * for sites already activated on a previous CleverSay version.
     *
     * Mirrors create_tables()' inline schema for this one table. Used by
     * the version-compare upgrade path in cleversay.php so existing
     * tenants pick up the table on next admin load without needing to
     * deactivate/reactivate the plugin. Safe to run repeatedly because
     * dbDelta is idempotent.
     */
    public function add_request_metrics_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->request_metrics} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            question_id BIGINT(20) UNSIGNED NOT NULL,
            total_ms INT(11) NOT NULL,
            kb_ms INT(11) NOT NULL,
            retrieval_ms INT(11) DEFAULT NULL,
            synthesis_ms INT(11) DEFAULT NULL,
            render_ms INT(11) NOT NULL,
            ai_fallback_fired TINYINT(1) NOT NULL DEFAULT 0,
            cache_hit TINYINT(1) NOT NULL DEFAULT 0,
            gate_fired TINYINT(1) DEFAULT NULL,
            matched_layer ENUM('kb_strong','kb_weak_with_ai','ai_only','no_answer') NOT NULL DEFAULT 'no_answer',
            tokens_in INT(11) DEFAULT NULL,
            tokens_out INT(11) DEFAULT NULL,
            cost DECIMAL(10,6) DEFAULT NULL,
            synthesis_model VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_question (question_id),
            INDEX idx_created (created_at),
            INDEX idx_total_ms (total_ms)
        ) $charset_collate;";
        dbDelta($sql);
    }

    /**
     * v4.41.5.3+: idempotent ALTER to add synthesis_model on tenants
     * already running v4.41.5/v4.41.5.1/v4.41.5.2. Existing rows stay
     * NULL (we don't know what model produced them retroactively); new
     * rows get the model recorded by RequestTimer.
     *
     * Safe to run repeatedly. The information_schema check avoids the
     * "Duplicate column name" warning that ALTER TABLE without
     * IF NOT EXISTS produces on MySQL versions older than 8.0.x.
     */
    public function add_synthesis_model_column(): void {
        $table = $this->request_metrics;
        // Skip silently if the table doesn't exist yet — a freshly
        // upgraded tenant might hit this before add_request_metrics_table
        // has run for them. The next admin load will run both in order.
        $table_exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $table
        ));
        if ((int) $table_exists === 0) {
            return;
        }
        $col_exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = %s
               AND COLUMN_NAME = 'synthesis_model'",
            $table
        ));
        if ((int) $col_exists === 0) {
            $this->wpdb->query(
                "ALTER TABLE {$table}
                 ADD COLUMN synthesis_model VARCHAR(100) DEFAULT NULL
                 AFTER cost"
            );
        }
    }

    /**
     * v4.42.0+: idempotent install of the bulk testing tables for tenants
     * already on a previous CleverSay version. Runs from the version-compare
     * upgrade path in cleversay.php so existing tenants pick up the tables
     * on next admin load without needing a deactivate/reactivate. dbDelta
     * is idempotent, so re-running on a tenant that already has the tables
     * is a no-op.
     */
    public function add_bulk_test_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $sql_runs = "CREATE TABLE {$this->bulk_test_runs} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            label VARCHAR(255) DEFAULT NULL,
            status ENUM('pending','running','completed','failed','aborted') NOT NULL DEFAULT 'pending',
            total_questions INT(11) NOT NULL DEFAULT 0,
            completed_questions INT(11) NOT NULL DEFAULT 0,
            synthesis_model VARCHAR(100) DEFAULT NULL,
            total_cost DECIMAL(10,6) DEFAULT 0.000000,
            started_at DATETIME DEFAULT NULL,
            finished_at DATETIME DEFAULT NULL,
            created_by BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) $charset_collate;";

        $sql_results = "CREATE TABLE {$this->bulk_test_results} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id BIGINT(20) UNSIGNED NOT NULL,
            row_index INT(11) NOT NULL,
            question TEXT NOT NULL,
            notes TEXT DEFAULT NULL,
            status ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
            matched_layer VARCHAR(40) DEFAULT NULL,
            ai_fallback_fired TINYINT(1) DEFAULT NULL,
            top_vector_similarity DECIMAL(6,4) DEFAULT NULL,
            top_vector_chunk_id BIGINT(20) UNSIGNED DEFAULT NULL,
            top_fulltext_chunk_id BIGINT(20) UNSIGNED DEFAULT NULL,
            returned_chunk_ids TEXT DEFAULT NULL,
            gate_fired TINYINT(1) DEFAULT NULL,
            synthesis_model VARCHAR(100) DEFAULT NULL,
            answer_text MEDIUMTEXT DEFAULT NULL,
            tokens_in INT(11) DEFAULT NULL,
            tokens_out INT(11) DEFAULT NULL,
            cost DECIMAL(10,6) DEFAULT NULL,
            total_ms INT(11) DEFAULT NULL,
            error TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_run (run_id),
            INDEX idx_run_index (run_id, row_index),
            INDEX idx_run_status (run_id, status)
        ) $charset_collate;";

        dbDelta($sql_runs);
        dbDelta($sql_results);
    }

    /**
     * Insert default stopwords
     */
    private function insert_default_stopwords(): void {
        // v4.37.2+: question words (when, where, why, how, what, which,
        // who, whom) are NO LONGER seeded as stopwords. The legacy
        // CleverSay never had them in its stopword list, which is why
        // many legacy patterns contain them as content words (e.g.,
        // `where&office`, `how&many&take`). Stripping question words
        // before pattern matching breaks those patterns silently.
        // Question type is also genuinely meaningful in a Q&A context
        // — "where is X" and "what is X" want different answers.
        // Admins who want them as stopwords can add them via Settings.
        // v4.37.17+: negation and temporal words (no, not, before,
        // after) are NO LONGER seeded as stopwords. Negation
        // inverts meaning ("can I take a class without being a
        // student?" vs "can I take a class as a student?") and
        // temporal words frame deadline/timing questions — both
        // categories are content-bearing for a Q&A chatbot, not
        // filler. Stripping them silently routes "I cannot
        // register" and "I can register" to the same matcher
        // input. Admins who want any of these as stopwords can
        // re-enable them via Settings.
        $stopwords = [
            'a', 'an', 'the', 'and', 'or', 'but', 'is', 'are', 'was', 'were',
            'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did',
            'will', 'would', 'could', 'should', 'may', 'might', 'must', 'shall',
            'can', 'to', 'of', 'in', 'for', 'on', 'with', 'at', 'by', 'from',
            'as', 'into', 'through', 'during', 'above', 'below',
            'between', 'under', 'again', 'further', 'then', 'once', 'here', 'there',
            'all', 'each', 'few', 'more', 'most',
            'other', 'some', 'such', 'only', 'own', 'same', 'so',
            'than', 'too', 'very', 'just', 'this',
            'that', 'these', 'those', 'am', 'i', 'me', 'my', 'myself', 'we', 'our',
            'ours', 'ourselves', 'you', 'your', 'yours', 'yourself', 'yourselves',
            'he', 'him', 'his', 'himself', 'she', 'her', 'hers', 'herself', 'it',
            'its', 'itself', 'they', 'them', 'their', 'theirs', 'themselves',
            // v4.37.30+: subordinators and connectives. Pure syntactic
            // glue with no content meaning. `unless` deliberately
            // excluded — it inverts meaning, same reasoning as `not`.
            'if', 'about', 'because', 'although', 'though',
        ];

        foreach ($stopwords as $word) {
            $this->wpdb->replace(
                $this->stopwords,
                ['word' => $word, 'is_active' => 1],
                ['%s', '%d']
            );
        }
    }
    
    /**
     * Set default plugin options
     */
    public function set_default_options(): void {
        $defaults = [
            'cleversay_widget_enabled' => true,
            'cleversay_widget_position' => 'bottom-right',
            'cleversay_widget_title' => __('Ask a Question', 'cleversay'),
            'cleversay_widget_placeholder' => __('Type your question here...', 'cleversay'),
            'cleversay_primary_color' => '#0073aa',
            'cleversay_secondary_color' => '#23282d',
            'cleversay_show_rating' => true,
            'cleversay_enable_analytics' => true,
            'cleversay_enable_spellcheck' => true,
            'cleversay_min_match_score' => 70,
            'cleversay_max_results' => 5,
            'cleversay_enable_inquiry_form' => true,
            'cleversay_inquiry_email' => get_option('admin_email'),
            'cleversay_no_answer_message' => __('Sorry, I could not find an answer to your question. Would you like to submit it to our team?', 'cleversay'),
            'cleversay_delete_data_on_uninstall' => false,
        ];
        
        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }
    
    /**
     * Drop all plugin tables
     */
    public function drop_tables(): void {
        $tables = [
            $this->knowledge_base,
            $this->questions_log,
            $this->visitors,
            $this->synonyms,
            $this->stopwords,
            $this->ratings,
            $this->inquiries,
            $this->categories,
            $this->sources,
            $this->chunks,
            $this->ai_answers,
            $this->conversation_ratings,
            $this->source_usage,
            $this->leads,
        ];
        
        foreach ($tables as $table) {
            $this->wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
        
        delete_option('cleversay_db_version');
    }
    
    /**
     * Delete all plugin options
     */
    public function delete_options(): void {
        global $wpdb;
        
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'cleversay_%'");
    }
    
    /**
     * Import data from legacy CleverSay database
     * 
     * @param array $config Legacy database configuration
     * @return array Import results with counts and errors
     */
    public function import_legacy_data(array $config): array {
        $results = [
            'knowledge' => 0,
            'synonyms' => 0,
            'stopwords' => 0,
            'questions' => 0,
            'errors' => [],
        ];
        
        try {
            // Create connection to legacy database
            $legacy_db = new mysqli(
                $config['host'],
                $config['user'],
                $config['password'],
                $config['database']
            );
            
            if ($legacy_db->connect_error) {
                throw new Exception('Legacy database connection failed: ' . $legacy_db->connect_error);
            }
            
            $legacy_db->set_charset('utf8mb4');
            
            // Import knowledge base (ailiza table)
            $results['knowledge'] = $this->import_knowledge_base($legacy_db, $config['prefix'] ?? '');
            
            // Import synonyms (ailiza_spellcheck table)
            $results['synonyms'] = $this->import_synonyms($legacy_db, $config['prefix'] ?? '');
            
            // Import stopwords (ailiza_stopwords table)
            $results['stopwords'] = $this->import_stopwords($legacy_db, $config['prefix'] ?? '');
            
            // Import questions log (ailiza_questions table)
            $results['questions'] = $this->import_questions_log($legacy_db, $config['prefix'] ?? '');
            
            $legacy_db->close();
            
        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Import knowledge base entries
     */
    private function import_knowledge_base(mysqli $legacy_db, string $prefix): int {
        $count = 0;
        $table = $prefix . 'ailiza';
        
        $query = "SELECT * FROM {$table} WHERE 1=1";
        $result = $legacy_db->query($query);
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                // Map old fields to new structure
                $data = [
                    'keyword' => sanitize_text_field($row['keyword'] ?? ''),
                    'sub_keyword' => sanitize_text_field($row['subkeyword'] ?? ''),
                    'question' => sanitize_textarea_field($row['rq'] ?? ''),
                    'response' => wp_kses_post($row['response'] ?? ''),
                    'search_type' => ($row['stype'] ?? 0) == 1 ? 'pattern' : 'keyword',
                    'status' => $this->map_status($row['status'] ?? 'hold'),
                    'show_rating' => ($row['rate'] ?? 'yes') === 'yes' ? 1 : 0,
                    'hits' => intval($row['hits'] ?? 0),
                    'helpful_yes' => intval($row['positive'] ?? 0),
                    'helpful_no' => intval($row['negative'] ?? 0),
                    'reuse_response' => ($row['reuse'] ?? 'no') === 'yes' ? 1 : 0,
                    'reuse_keyword' => sanitize_text_field($row['rkey'] ?? ''),
                    'reuse_sub_keyword' => sanitize_text_field($row['rsubkey'] ?? ''),
                    'expires_at' => $this->format_date($row['expdate'] ?? null),
                    'created_at' => $this->format_datetime($row['date'] ?? null),
                ];
                
                $inserted = $this->wpdb->insert(
                    $this->knowledge_base,
                    $data,
                    ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s']
                );
                
                if ($inserted) {
                    $count++;
                }
            }
            $result->free();
        }
        
        return $count;
    }
    
    /**
     * Import synonyms/spell check entries
     */
    private function import_synonyms(mysqli $legacy_db, string $prefix): int {
        $count = 0;
        $table = $prefix . 'ailiza_spellcheck';
        
        $query = "SELECT * FROM {$table} WHERE 1=1";
        $result = $legacy_db->query($query);
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $canonical = sanitize_text_field($row['newvalsp'] ?? '');
                $variants = sanitize_text_field($row['valsp'] ?? '');
                $misspellings = sanitize_text_field($row['mispell'] ?? '');
                
                // Check if this is a phrase (contains space)
                $is_phrase = strpos($canonical, ' ') !== false ? 1 : 0;
                
                $data = [
                    'canonical_word' => $canonical,
                    'variant_words' => $variants,
                    'misspellings' => $misspellings,
                    'is_phrase' => $is_phrase,
                    'is_active' => 1,
                ];
                
                $inserted = $this->wpdb->insert(
                    $this->synonyms,
                    $data,
                    ['%s', '%s', '%s', '%d', '%d']
                );
                
                if ($inserted) {
                    $count++;
                }
            }
            $result->free();
        }
        
        return $count;
    }
    
    /**
     * Import stopwords
     */
    private function import_stopwords(mysqli $legacy_db, string $prefix): int {
        $count = 0;
        $table = $prefix . 'ailiza_stopwords';
        
        $query = "SELECT * FROM {$table} WHERE 1=1";
        $result = $legacy_db->query($query);
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $word = sanitize_text_field($row['stopwords'] ?? '');
                
                if (!empty($word)) {
                    $this->wpdb->replace(
                        $this->stopwords,
                        ['word' => $word, 'is_active' => 1],
                        ['%s', '%d']
                    );
                    $count++;
                }
            }
            $result->free();
        }
        
        return $count;
    }
    
    /**
     * Import questions log
     */
    private function import_questions_log(mysqli $legacy_db, string $prefix): int {
        $count = 0;
        $table = $prefix . 'ailiza_questions';
        
        // Only import recent questions (last 90 days) to avoid bloat
        $query = "SELECT * FROM {$table} WHERE date >= DATE_SUB(NOW(), INTERVAL 90 DAY) ORDER BY id DESC LIMIT 10000";
        $result = $legacy_db->query($query);
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data = [
                    'question' => sanitize_textarea_field($row['question'] ?? ''),
                    'matched_keyword' => sanitize_text_field($row['keyword'] ?? ''),
                    'matched_sub_keyword' => sanitize_text_field($row['subkeyword'] ?? ''),
                    'match_type' => ($row['type'] ?? 'none') === 'auto' ? 'exact' : 'partial',
                    'ip_address' => sanitize_text_field($row['ip2'] ?? ''),
                    'created_at' => $this->format_datetime($row['date'] ?? null),
                ];
                
                $inserted = $this->wpdb->insert(
                    $this->questions_log,
                    $data,
                    ['%s', '%s', '%s', '%s', '%s', '%s']
                );
                
                if ($inserted) {
                    $count++;
                }
            }
            $result->free();
        }
        
        return $count;
    }
    
    /**
     * Map legacy status to new status
     */
    private function map_status(string $status): string {
        switch (strtolower($status)) {
            case 'active':   return 'active';
            case 'inactive': return 'inactive';
            case 'hold':     return 'hold';
            default:         return 'hold';
        }
    }
    
    /**
     * Format date string
     */
    private function format_date(?string $date): ?string {
        if (empty($date) || $date === '0000-00-00') {
            return null;
        }
        return date('Y-m-d', strtotime($date));
    }
    
    /**
     * Format datetime string
     */
    private function format_datetime(?string $datetime): string {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
            return current_time('mysql');
        }
        return date('Y-m-d H:i:s', strtotime($datetime));
    }
}
