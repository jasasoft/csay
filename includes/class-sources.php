<?php
/**
 * CleverSay Source Library
 *
 * Manages the AI knowledge sources: URLs, file uploads, and plain text.
 *
 * @package CleverSay
 * @since 2.2.0
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class Sources {

    private \wpdb    $wpdb;
    private string   $sources_table;
    private string   $chunks_table;
    private Logger   $logger;
    private Indexer  $indexer;

    public function __construct() {
        global $wpdb;
        $this->wpdb          = $wpdb;
        $this->sources_table = $wpdb->prefix . 'cleversay_sources';
        $this->chunks_table  = $wpdb->prefix . 'cleversay_chunks';
        $this->logger        = Logger::instance();
        $this->indexer       = new Indexer();
    }

    // ── Adding sources ────────────────────────────────────────────────────────

    public function add_url(string $url, string $title = '', string $cached_html = '', bool $force = false) {
        $url = esc_url_raw($url);
        if (empty($url)) {
            return false;
        }
        // Canonicalise — "page" and "page/" should be treated as the same URL
        $url = \CleverSay\Crawler::normalise($url);

        // Prevent duplicates — reindex if errored/pending, or if forced
        $existing = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT id, status FROM {$this->sources_table} WHERE url = %s", $url),
            ARRAY_A
        );
        if ($existing) {
            $id = (int) $existing['id'];
            if ($force || $existing['status'] === 'error' || $existing['status'] === 'pending') {
                $this->index($id, $cached_html);
            }
            return $id;
        }

        if (empty($title)) {
            $title = $url;
        }

        $result = $this->wpdb->insert($this->sources_table, [
            'title'            => sanitize_text_field($title),
            'source_type'      => 'url',
            'url'              => $url,
            'status'            => 'pending',
            // New URL sources default to a twice-monthly refresh schedule.
            // Files/text sources are left as 'never' (no upstream to poll).
            'refresh_interval' => 'twice_monthly',
        ]);

        if ($result === false) {
            return false;
        }

        $id = (int) $this->wpdb->insert_id;
        $this->index($id, $cached_html);
        return $id;
    }

    public function add_file(array $file) {
        $allowed_types = ['application/pdf', 'text/plain', 'application/msword',
                          'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

        if (!in_array($file['type'], $allowed_types, true)) {
            $this->logger->warning('Unsupported file type for AI source', ['type' => $file['type']]);
            return false;
        }

        $upload_dir = wp_upload_dir();
        $dest_dir   = $upload_dir['basedir'] . '/cleversay-sources/';
        wp_mkdir_p($dest_dir);

        // Write .htaccess to protect the folder from direct web access
        if (!file_exists($dest_dir . '.htaccess')) {
            file_put_contents($dest_dir . '.htaccess', "Deny from all\n");
        }

        $safe_name = sanitize_file_name($file['name']);
        $dest_path = $dest_dir . wp_unique_filename($dest_dir, $safe_name);

        if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
            $this->logger->error('Could not move uploaded file', ['dest' => $dest_path]);
            return false;
        }

        $ext = strtolower(pathinfo($safe_name, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'pdf':  $source_type = 'pdf';  break;
            case 'txt':  $source_type = 'text'; break;
            case 'docx': $source_type = 'docx'; break;
            default:     $source_type = 'text'; break;
        }

        $result = $this->wpdb->insert($this->sources_table, [
            'title'       => pathinfo($safe_name, PATHINFO_FILENAME),
            'source_type' => $source_type,
            'file_path'   => $dest_path,
            'file_name'   => $safe_name,
            'status'      => 'pending',
        ]);

        if ($result === false) {
            @unlink($dest_path);
            return false;
        }

        $id = (int) $this->wpdb->insert_id;
        $this->index($id);
        return $id;
    }

    public function add_text(string $content, string $title) {
        $content = sanitize_textarea_field($content);
        $title   = sanitize_text_field($title);

        if (empty($content) || empty($title)) {
            return false;
        }

        $result = $this->wpdb->insert($this->sources_table, [
            'title'       => $title,
            'source_type' => 'text',
            'status'      => 'pending',
        ]);

        if ($result === false) {
            return false;
        }

        $id = (int) $this->wpdb->insert_id;

        // For plain text, store directly as a single chunk payload via the indexer
        $this->indexer->index_text_content($id, $content, $title);
        return $id;
    }

    // ── CRUD ──────────────────────────────────────────────────────────────────

    public function get_all(): array {
        return (array) $this->wpdb->get_results(
            "SELECT * FROM {$this->sources_table} ORDER BY created_at DESC",
            ARRAY_A
        );
    }

    public function get(int $id): ?array {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->sources_table} WHERE id = %d", $id),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function delete(int $id): bool {
        $source = $this->get($id);
        if (!$source) {
            return false;
        }

        // Delete physical file if it was an upload
        if (!empty($source['file_path']) && file_exists($source['file_path'])) {
            @unlink($source['file_path']);
        }

        // Delete chunks
        $this->wpdb->delete($this->chunks_table, ['source_id' => $id]);

        // v4.39.0+: Phase 2 — clean up embeddings tied to this source.
        // Soft-deletes Supabase rows; removes pending queue entries.
        // Non-fatal: source deletion proceeds even if Supabase fails.
        if (class_exists('\\CleverSay\\Supabase') && \CleverSay\Supabase::is_enabled()) {
            try {
                (new \CleverSay\Embedder())->delete_source_chunks($id);
            } catch (\Throwable $e) {
                Logger::instance()->warning('Embedding cleanup failed on source delete', [
                    'source_id' => $id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        // Delete source record
        return (bool) $this->wpdb->delete($this->sources_table, ['id' => $id]);
    }

    public function reindex(int $id): bool {
        $source = $this->get($id);
        if (!$source) {
            return false;
        }

        // Clear existing chunks
        $this->wpdb->delete($this->chunks_table, ['source_id' => $id]);

        // Reset status. We deliberately do NOT clear content_hash here —
        // index_source() handles that via the force_rechunk flag below.
        // (Clearing the hash would also work, but the explicit flag makes
        // the intent of "re-do everything regardless of content diff" clear
        // at the call site.)
        $this->wpdb->update(
            $this->sources_table,
            ['status' => 'pending', 'error_message' => null, 'chunk_count' => 0, 'word_count' => 0],
            ['id' => $id]
        );

        // force_rechunk = true — bypasses the unchanged-content shortcut.
        // The user clicked Re-Index because they want it re-done; if we
        // skipped re-chunking when the content matched the prior hash,
        // we'd leave the source with 0 chunks and 0 words after reindex.
        $this->index($id, '', true);
        return true;
    }

    public function get_chunks(int $source_id): array {
        return (array) $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->chunks_table} WHERE source_id = %d ORDER BY chunk_index ASC",
                $source_id
            ),
            ARRAY_A
        );
    }

    // ── Scheduled re-crawl (WP-Cron hourly) ───────────────────────────────────

    /**
     * Set how often a source should be auto-recrawled.
     * Valid intervals: never | daily | weekly | twice_monthly | monthly
     */
    public function set_refresh_interval(int $id, string $interval): bool {
        $valid = ['never', 'daily', 'weekly', 'twice_monthly', 'monthly'];
        if (!in_array($interval, $valid, true)) return false;
        $result = $this->wpdb->update(
            $this->sources_table,
            ['refresh_interval' => $interval],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
        return $result !== false;
    }

    /**
     * Find URL sources that are due for re-crawl based on their interval.
     * Runs via WP-Cron hourly. File/text sources don't auto-recrawl because
     * there's no URL to poll. Caps at 10 sources per tick to keep runs cheap.
     */
    public function run_scheduled_crawls(): void {
        $interval_sql = [
            'daily'         => 'DATE_SUB(NOW(), INTERVAL 1 DAY)',
            'weekly'        => 'DATE_SUB(NOW(), INTERVAL 7 DAY)',
            'twice_monthly' => 'DATE_SUB(NOW(), INTERVAL 15 DAY)',
            'monthly'       => 'DATE_SUB(NOW(), INTERVAL 30 DAY)',
        ];

        foreach ($interval_sql as $interval => $cutoff) {
            $due = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT id FROM {$this->sources_table}
                 WHERE source_type = 'url'
                   AND refresh_interval = %s
                   AND status = 'indexed'
                   AND (last_crawled_at IS NULL OR last_crawled_at < {$cutoff})
                 LIMIT 10",
                $interval
            ), ARRAY_A);

            foreach ($due as $row) {
                $this->logger->info('Scheduled re-crawl', ['source_id' => (int) $row['id'], 'interval' => $interval]);
                $this->index((int) $row['id']);
            }
        }
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function index(int $id, string $cached_html = '', bool $force_rechunk = false): void {
        $this->indexer->index_source($id, $cached_html, $force_rechunk);
    }
}
