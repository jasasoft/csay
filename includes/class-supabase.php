<?php
/**
 * CleverSay Supabase Connection Layer
 *
 * Manages the Postgres connection from CleverSay (running on MySQL/WordPress)
 * to Supabase Postgres, where vector embeddings live for semantic retrieval.
 *
 * ARCHITECTURAL NOTE — see ARCHITECTURE.md
 *
 * MySQL remains the operational source of truth for KB entries, sources,
 * chunks (text), conversations, and admin data. Supabase is added ONLY
 * for vector storage and semantic similarity search. The two databases
 * are kept in sync by the indexing pipeline: when MySQL chunks are
 * created/updated, embeddings are generated and stored in Supabase.
 *
 * This class is a thin wrapper around PDO. It does NOT replace MySQL
 * access — CleverSay's existing $wpdb usage remains unchanged. This
 * connection is used only by the embedding indexing and vector search
 * code paths.
 *
 * Connection details are stored in network settings. The class is a
 * lazy-loaded singleton — connection isn't established until first use,
 * and isn't established at all on installs that haven't enabled the
 * embeddings feature.
 *
 * @package CleverSay
 * @since   4.38.0
 */

namespace CleverSay;

if (!defined('ABSPATH')) exit;

class Supabase {

    private static ?self $instance = null;

    private ?\PDO $pdo = null;

    private Logger $logger;

    /**
     * Get singleton instance.
     */
    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->logger = Logger::instance();
    }

    /**
     * Check whether Supabase is configured AND the embeddings feature
     * flag is enabled. Both must be true before any embedding-related
     * code path runs.
     */
    public static function is_enabled(): bool {
        $cfg = self::get_config();
        return !empty($cfg['enabled'])
            && !empty($cfg['host'])
            && !empty($cfg['password']);
    }

    /**
     * Read Supabase configuration from network settings.
     * Returns an array with keys: host, port, database, user, password,
     * enabled (bool), openai_api_key.
     */
    public static function get_config(): array {
        $defaults = [
            'host'                 => '',
            'port'                 => 5432,
            'database'             => 'postgres',
            'user'                 => 'postgres',
            'password'             => '',
            'enabled'              => false,
            'openai_api_key'       => '',
            'embedding_model'      => 'text-embedding-3-small',
            // Phase 3 (v4.40.0): gate for hybrid retrieval. Independent
            // from `enabled` (which controls the indexing pipeline).
            // When false, retrieval uses MySQL FULLTEXT only.
            'use_hybrid_retrieval' => false,
        ];

        // Reuse the same network settings infrastructure as AI config.
        // We store under a separate option key to keep concerns clean.
        $saved = get_site_option('cleversay_network_supabase', []);
        if (!is_array($saved)) {
            $saved = [];
        }
        return array_merge($defaults, $saved);
    }

    /**
     * Save Supabase configuration. Used by the admin UI save handler.
     */
    public static function save_config(array $data): bool {
        $defaults = self::get_config(); // gets current values + defaults
        $clean = [];
        foreach ($defaults as $key => $default) {
            if (!isset($data[$key])) {
                $clean[$key] = is_bool($default) ? false : $default;
                continue;
            }
            $clean[$key] = match(true) {
                is_bool($default) => (bool) $data[$key],
                is_int($default)  => (int) $data[$key],
                default           => sanitize_text_field((string) $data[$key]),
            };
        }
        // Reset singleton so the next instance() picks up new config.
        self::$instance = null;
        return update_site_option('cleversay_network_supabase', $clean);
    }

    /**
     * Establish the PDO connection. Lazy — only runs on first use.
     * Throws on failure; callers should catch and degrade gracefully
     * (fall back to MySQL-only retrieval).
     *
     * @throws \PDOException on connection failure.
     */
    public function connect(): \PDO {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $cfg = self::get_config();

        if (empty($cfg['host']) || empty($cfg['password'])) {
            throw new \PDOException('Supabase not configured (missing host or password).');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
            $cfg['host'],
            (int) $cfg['port'],
            $cfg['database']
        );

        try {
            $this->pdo = new \PDO($dsn, $cfg['user'], $cfg['password'], [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT            => 10,
                \PDO::ATTR_PERSISTENT         => false,
            ]);
            return $this->pdo;
        } catch (\PDOException $e) {
            $this->logger->error('Supabase connection failed', [
                'host'  => $cfg['host'],
                'port'  => $cfg['port'],
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Test the connection without throwing — returns a structured result
     * for the admin "Test Connection" button.
     *
     * @return array {success: bool, message: string, details: array}
     */
    public function test_connection(): array {
        $cfg = self::get_config();

        if (empty($cfg['host']) || empty($cfg['password'])) {
            return [
                'success' => false,
                'message' => 'Missing host or password in settings.',
                'details' => [],
            ];
        }

        try {
            $pdo = $this->connect();
        } catch (\PDOException $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
                'details' => [],
            ];
        }

        try {
            // Basic query to confirm connection works.
            $row = $pdo->query("SELECT version() AS pg_version, NOW() AS server_time")->fetch();

            // Check pgvector availability.
            $vec_row = $pdo->query("
                SELECT name, default_version, installed_version
                FROM pg_available_extensions
                WHERE name = 'vector'
            ")->fetch();

            $details = [
                'pg_version'         => $row['pg_version'] ?? 'unknown',
                'server_time'        => $row['server_time'] ?? 'unknown',
                'pgvector_available' => !empty($vec_row),
                'pgvector_version'   => $vec_row['default_version'] ?? 'n/a',
                'pgvector_enabled'   => !empty($vec_row['installed_version']),
            ];

            if (empty($vec_row)) {
                return [
                    'success' => false,
                    'message' => 'Connected, but pgvector extension is not available on this Supabase project.',
                    'details' => $details,
                ];
            }

            // Check whether our schema is set up.
            $schema_row = $pdo->query("
                SELECT EXISTS (
                    SELECT 1 FROM information_schema.tables
                    WHERE table_schema = 'public'
                    AND table_name = 'cleversay_chunks'
                ) AS schema_exists
            ")->fetch();

            $details['schema_installed'] = !empty($schema_row['schema_exists']);

            return [
                'success' => true,
                'message' => 'Connection successful.',
                'details' => $details,
            ];
        } catch (\PDOException $e) {
            return [
                'success' => false,
                'message' => 'Connected but query failed: ' . $e->getMessage(),
                'details' => [],
            ];
        }
    }

    /**
     * Run the schema setup SQL. Idempotent — safe to run multiple times;
     * uses CREATE ... IF NOT EXISTS throughout.
     *
     * @return array {success: bool, message: string, statements_run: int}
     */
    public function install_schema(): array {
        try {
            $pdo = $this->connect();
        } catch (\PDOException $e) {
            return [
                'success'         => false,
                'message'         => 'Cannot install schema — connection failed: ' . $e->getMessage(),
                'statements_run'  => 0,
            ];
        }

        $sql_path = CLEVERSAY_PLUGIN_DIR . 'includes/sql/supabase-schema.sql';
        if (!is_readable($sql_path)) {
            return [
                'success'         => false,
                'message'         => 'Schema file not found at ' . $sql_path,
                'statements_run'  => 0,
            ];
        }

        $sql = file_get_contents($sql_path);
        if ($sql === false) {
            return [
                'success'         => false,
                'message'         => 'Could not read schema file.',
                'statements_run'  => 0,
            ];
        }

        // Split on semicolons that end statements. Naive but works for our
        // schema file which doesn't contain string literals with semicolons.
        // Strip line comments (-- ...) before splitting.
        $sql_clean = preg_replace('/^\s*--.*$/m', '', $sql);
        $statements = array_filter(
            array_map('trim', explode(';', $sql_clean)),
            fn($s) => $s !== ''
        );

        $count = 0;
        try {
            foreach ($statements as $stmt) {
                $pdo->exec($stmt);
                $count++;
            }
            return [
                'success'         => true,
                'message'         => 'Schema installed successfully.',
                'statements_run'  => $count,
            ];
        } catch (\PDOException $e) {
            return [
                'success'         => false,
                'message'         => sprintf(
                    'Schema install failed at statement %d: %s',
                    $count + 1,
                    $e->getMessage()
                ),
                'statements_run'  => $count,
            ];
        }
    }

    /**
     * v4.41.0+: One-time migration that adds the source_namespace column
     * to cleversay_chunks and backfills it from content_type. Idempotent —
     * safe to run repeatedly. Tracks completion in a network option so
     * subsequent plugin loads skip the work.
     *
     * Best-effort: if Supabase isn't reachable (network outage during
     * activation), returns false without throwing. Retrieval still
     * works because the Retriever's vector_search continues to filter
     * by content_type='chunk' as a fallback for rows where
     * source_namespace might be NULL.
     *
     * Schema steps performed:
     *   1. ALTER TABLE ... ADD COLUMN IF NOT EXISTS source_namespace TEXT
     *   2. UPDATE rows where source_namespace IS NULL based on content_type
     *   3. CREATE INDEX IF NOT EXISTS cleversay_chunks_namespace_idx
     *
     * Mapping: content_type='chunk'    → source_namespace='source'
     *          content_type='kb_entry' → source_namespace='kb'
     *
     * @return array {success: bool, message: string, rows_backfilled: int}
     */
    public function run_namespace_migration(): array {
        if (!self::is_enabled()) {
            return [
                'success'         => false,
                'message'         => 'Skipped — Supabase is not enabled.',
                'rows_backfilled' => 0,
            ];
        }

        try {
            $pdo = $this->connect();
        } catch (\PDOException $e) {
            $this->logger->warning('Namespace migration could not connect; will retry next admin load', [
                'error' => $e->getMessage(),
            ]);
            return [
                'success'         => false,
                'message'         => 'Connection failed: ' . $e->getMessage(),
                'rows_backfilled' => 0,
            ];
        }

        try {
            // Step 1: add the column. Postgres has had IF NOT EXISTS on
            // ADD COLUMN since 9.6 so this is safe to run repeatedly.
            $pdo->exec(
                "ALTER TABLE cleversay_chunks
                 ADD COLUMN IF NOT EXISTS source_namespace TEXT"
            );

            // Step 2: backfill any NULL rows. Maps content_type to namespace.
            // Limited to rows where source_namespace IS NULL so re-runs
            // don't rewrite already-migrated data.
            $rows = (int) $pdo->exec(
                "UPDATE cleversay_chunks
                 SET source_namespace = CASE
                     WHEN content_type = 'chunk'    THEN 'source'
                     WHEN content_type = 'kb_entry' THEN 'kb'
                     ELSE source_namespace
                 END
                 WHERE source_namespace IS NULL"
            );

            // Step 3: create the namespace-scoped index. Same IF NOT EXISTS
            // semantics as the rest of the schema file.
            $pdo->exec(
                "CREATE INDEX IF NOT EXISTS cleversay_chunks_namespace_idx
                 ON cleversay_chunks (tenant_id, source_namespace, is_current)
                 WHERE is_current = TRUE"
            );

            $this->logger->info('Supabase namespace migration completed', [
                'rows_backfilled' => $rows,
            ]);

            return [
                'success'         => true,
                'message'         => sprintf('Migration complete. Backfilled %d rows.', $rows),
                'rows_backfilled' => $rows,
            ];
        } catch (\PDOException $e) {
            $this->logger->error('Supabase namespace migration failed', [
                'error' => $e->getMessage(),
            ]);
            return [
                'success'         => false,
                'message'         => 'Migration failed: ' . $e->getMessage(),
                'rows_backfilled' => 0,
            ];
        }
    }

    /**
     * Convenience: run a SELECT query and return all rows.
     *
     * @throws \PDOException on failure.
     */
    public function query(string $sql, array $params = []): array {
        $pdo = $this->connect();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Convenience: run an INSERT/UPDATE/DELETE and return rows affected.
     *
     * @throws \PDOException on failure.
     */
    public function execute(string $sql, array $params = []): int {
        $pdo = $this->connect();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Format a PHP array of floats as a pgvector literal string.
     * pgvector accepts the form '[1.0,2.0,3.0]' for vector values.
     */
    public static function vector_to_pg(array $vector): string {
        // Use sprintf with %.7g for compact-but-precise float formatting.
        // text-embedding-3-small produces float32-equivalent precision;
        // 7 significant digits is more than sufficient.
        $parts = array_map(fn($v) => sprintf('%.7g', (float) $v), $vector);
        return '[' . implode(',', $parts) . ']';
    }
}
