<?php
/**
 * CleverSay Import/Export
 *
 * Handles data import from legacy system and export functionality
 *
 * @package CleverSay
 * @since 1.0.0
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import/Export Handler
 */
class ImportExport {
    
    private const EXPORT_VERSION = '1.0';
    private const CHUNK_SIZE = 100;

    /**
     * Maximum number of automatic-backup files retained in the per-site
     * uploads/cleversay-backups directory. Older files are deleted by
     * prune_backups() after each new backup is written.
     *
     * v4.42.26+: introduced alongside the daily KB backup cron event
     * that runs once per tenant per day. Prior to this, backups
     * accumulated indefinitely.
     */
    public const BACKUP_KEEP = 7;
    
    /**
     * Import from legacy database
     */
    public function import_legacy_database(array $credentials): array {
        $result = [
            'success' => false,
            'imported' => [
                'knowledge' => 0,
                'synonyms' => 0,
                'stopwords' => 0,
                'questions' => 0,
            ],
            'errors' => [],
            'warnings' => [],
        ];
        
        // Validate credentials
        $required = ['host', 'database', 'username', 'password'];
        foreach ($required as $field) {
            if (empty($credentials[$field])) {
                $result['errors'][] = sprintf(__('Missing required field: %s', 'cleversay'), $field);
            }
        }
        
        if (!empty($result['errors'])) {
            return $result;
        }
        
        // Connect to legacy database
        $legacy_db = new \mysqli(
            $credentials['host'],
            $credentials['username'],
            $credentials['password'],
            $credentials['database'],
            $credentials['port'] ?? 3306
        );
        
        if ($legacy_db->connect_error) {
            $result['errors'][] = sprintf(
                __('Database connection failed: %s', 'cleversay'),
                $legacy_db->connect_error
            );
            return $result;
        }
        
        $legacy_db->set_charset('utf8mb4');
        $table_prefix = $credentials['table_prefix'] ?? 'ailiza';
        
        try {
            // Import knowledge base
            $knowledge_result = $this->import_legacy_knowledge($legacy_db, $table_prefix);
            $result['imported']['knowledge'] = $knowledge_result['count'];
            $result['errors'] = array_merge($result['errors'], $knowledge_result['errors']);
            $result['warnings'] = array_merge($result['warnings'], $knowledge_result['warnings']);
            
            // Import synonyms/spellcheck
            $synonyms_result = $this->import_legacy_synonyms($legacy_db, $table_prefix);
            $result['imported']['synonyms'] = $synonyms_result['count'];
            $result['errors'] = array_merge($result['errors'], $synonyms_result['errors']);
            
            // Import stopwords
            $stopwords_result = $this->import_legacy_stopwords($legacy_db, $table_prefix);
            $result['imported']['stopwords'] = $stopwords_result['count'];
            
            // Import recent questions (last 90 days)
            $questions_result = $this->import_legacy_questions($legacy_db, $table_prefix, 90);
            $result['imported']['questions'] = $questions_result['count'];
            $result['warnings'] = array_merge($result['warnings'], $questions_result['warnings']);
            
            $result['success'] = true;
            
        } catch (\Exception $e) {
            $result['errors'][] = sprintf(__('Import error: %s', 'cleversay'), $e->getMessage());
        }
        
        $legacy_db->close();
        
        return $result;
    }
    
    /**
     * Import legacy knowledge base entries
     */
    private function import_legacy_knowledge(\mysqli $db, string $prefix): array {
        global $wpdb;
        $result = ['count' => 0, 'errors' => [], 'warnings' => []];
        
        // The main table is just the prefix (e.g., "ailiza")
        $legacy_table = $db->real_escape_string($prefix);
        $new_table = $wpdb->prefix . 'cleversay_knowledge';
        
        // Check if table exists
        $check = $db->query("SHOW TABLES LIKE '{$legacy_table}'");
        if (!$check || $check->num_rows === 0) {
            $result['errors'][] = sprintf(__('Legacy table "%s" not found', 'cleversay'), $legacy_table);
            return $result;
        }
        
        // Get columns to understand structure
        $columns_result = $db->query("SHOW COLUMNS FROM `{$legacy_table}`");
        $columns = [];
        while ($col = $columns_result->fetch_assoc()) {
            $columns[] = strtolower($col['Field']);
        }
        $result['warnings'][] = sprintf(__('Found columns: %s', 'cleversay'), implode(', ', $columns));
        
        // Get total count
        $count_result = $db->query("SELECT COUNT(*) as cnt FROM `{$legacy_table}`");
        if (!$count_result) {
            $result['errors'][] = sprintf(__('Could not count rows: %s', 'cleversay'), $db->error);
            return $result;
        }
        $total = $count_result->fetch_assoc()['cnt'];
        $result['warnings'][] = sprintf(__('Found %d rows to import', 'cleversay'), $total);
        
        // Process in chunks
        $offset = 0;
        while ($offset < $total) {
            $query = "SELECT * FROM `{$legacy_table}` LIMIT " . self::CHUNK_SIZE . " OFFSET {$offset}";
            $rows = $db->query($query);
            
            if (!$rows) {
                $result['errors'][] = sprintf(__('Query failed: %s', 'cleversay'), $db->error);
                break;
            }
            
            while ($row = $rows->fetch_assoc()) {
                // Map legacy status to new status
                $old_status = strtoupper(trim($row['status'] ?? 'A'));
                switch ($old_status) {
                    case 'A': case 'ACTIVE': case '1': $status = 'active';   break;
                    case 'I': case 'INACTIVE': case '0': $status = 'inactive'; break;
                    case 'H': case 'HOLD': $status = 'hold'; break;
                    case 'D': case 'DRAFT': $status = 'hold'; break;
                    default: $status = 'active'; break;
                }
                
                // Get keyword
                $keyword = trim((string) ($row['keyword'] ?? ''));
                if (empty($keyword)) {
                    continue;
                }
                
                // Determine search type from keyword patterns
                $search_type = 'keyword';
                if (str_starts_with($keyword, '*') || str_ends_with($keyword, '*')) {
                    $search_type = 'pattern';
                    // Keep the wildcards for pattern matching
                }
                
                // Get sub_keyword (could be subkeyword, subkey, sub_keyword)
                $sub_keyword = trim((string) ($row['subkeyword'] ?? $row['subkey'] ?? $row['sub_keyword'] ?? ''));
                
                // Get question (could be rq, question, q)
                $question = trim((string) ($row['rq'] ?? $row['question'] ?? $row['q'] ?? ''));
                if (empty($question)) {
                    $question = $keyword; // Fallback to keyword as question
                }
                
                // Get response (could be response, answer, ans, ra, respond)
                $response = (string) ($row['response'] ?? $row['answer'] ?? $row['ans'] ?? $row['ra'] ?? $row['respond'] ?? '');
                $response = $this->convert_legacy_html($response);
                
                // Parse expiration date
                $expires_at = null;
                $expdate = isset($row['expdate']) ? (string) $row['expdate'] : 
                          (isset($row['exp_date']) ? (string) $row['exp_date'] : 
                          (isset($row['expires']) ? (string) $row['expires'] : null));
                if (!empty($expdate) && $expdate !== '0000-00-00') {
                    $exp_date = \DateTime::createFromFormat('Y-m-d', $expdate);
                    if (!$exp_date) {
                        $exp_date = \DateTime::createFromFormat('Y-m-d H:i:s', $expdate);
                    }
                    if ($exp_date) {
                        $expires_at = $exp_date->format('Y-m-d');
                    }
                }
                
                // Get hits and ratings
                $hits = (int) ($row['hits'] ?? 0);
                $rate = (int) ($row['rate'] ?? 0);
                
                // Convert rate (1-5 scale) to helpful_yes percentage
                $helpful_yes = $rate > 0 ? (int) ($hits * ($rate / 5)) : 0;
                $helpful_no = $hits - $helpful_yes;
                if ($helpful_no < 0) $helpful_no = 0;
                
                // Reuse flag
                $reuse = (int) ($row['reuse'] ?? 0);
                
                // Check for duplicate
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$new_table} WHERE keyword = %s AND sub_keyword = %s",
                    $keyword,
                    $sub_keyword
                ));
                
                if ($existing) {
                    continue; // Skip duplicates silently
                }
                
                // Insert entry
                $insert_data = [
                    'keyword' => $keyword,
                    'sub_keyword' => $sub_keyword,
                    'question' => $question,
                    'response' => $response,
                    'status' => $status,
                    'search_type' => $search_type,
                    'hits' => $hits,
                    'helpful_yes' => $helpful_yes,
                    'helpful_no' => $helpful_no,
                    'show_rating' => 1,
                    'reuse_response' => $reuse ? 1 : 0,
                    'expires_at' => $expires_at,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ];
                
                $insert_result = $wpdb->insert($new_table, $insert_data);
                
                if ($insert_result) {
                    $result['count']++;
                } else {
                    $result['errors'][] = sprintf(
                        __('Failed to import "%s": %s', 'cleversay'),
                        $keyword,
                        $wpdb->last_error
                    );
                }
            }
            
            $offset += self::CHUNK_SIZE;
        }
        
        return $result;
    }
    
    /**
     * Import legacy synonyms/spellcheck entries
     */
    private function import_legacy_synonyms(\mysqli $db, string $prefix): array {
        global $wpdb;
        $result = ['count' => 0, 'errors' => [], 'warnings' => []];
        
        $legacy_table = $db->real_escape_string($prefix . '_spellcheck');
        $new_table = $wpdb->prefix . 'cleversay_synonyms';
        
        // Check if table exists
        $check = $db->query("SHOW TABLES LIKE '{$legacy_table}'");
        if (!$check || $check->num_rows === 0) {
            $result['warnings'][] = sprintf(__('Legacy spellcheck table "%s" not found', 'cleversay'), $legacy_table);
            return $result;
        }
        
        // Get columns
        $columns_result = $db->query("SHOW COLUMNS FROM `{$legacy_table}`");
        $columns = [];
        while ($col = $columns_result->fetch_assoc()) {
            $columns[] = strtolower($col['Field']);
        }
        $result['warnings'][] = sprintf(__('Spellcheck columns: %s', 'cleversay'), implode(', ', $columns));
        
        $rows = $db->query("SELECT * FROM `{$legacy_table}`");
        if (!$rows) {
            $result['errors'][] = sprintf(__('Query failed: %s', 'cleversay'), $db->error);
            return $result;
        }
        
        // Group misspellings by their canonical (correct) word
        $grouped = [];
        
        while ($row = $rows->fetch_assoc()) {
            // Legacy ailiza_spellcheck typically has: mispell, newvalsp (replacement), valsp
            // mispell = misspelled word, newvalsp = what to replace with
            $misspell = strtolower(trim((string) ($row['mispell'] ?? '')));
            $canonical = strtolower(trim((string) ($row['newvalsp'] ?? $row['valsp'] ?? '')));
            
            if (empty($misspell) || empty($canonical)) {
                continue;
            }
            
            // Skip if they're the same
            if ($misspell === $canonical) {
                continue;
            }
            
            // Group by canonical word (use string key to preserve type)
            $key = (string) $canonical;
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            if (!in_array($misspell, $grouped[$key])) {
                $grouped[$key][] = $misspell;
            }
        }
        
        // Insert grouped synonyms
        foreach ($grouped as $canonical => $misspellings) {
            // Ensure canonical is string
            $canonical = (string) $canonical;
            
            // Check for duplicate
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$new_table} WHERE canonical_word = %s",
                $canonical
            ));
            
            if ($existing) {
                // Append misspellings to existing
                $current = $wpdb->get_var($wpdb->prepare(
                    "SELECT misspellings FROM {$new_table} WHERE id = %d",
                    $existing
                ));
                $current_list = $current ? explode(',', $current) : [];
                $merged = array_unique(array_merge($current_list, $misspellings));
                $wpdb->update(
                    $new_table,
                    ['misspellings' => implode(',', $merged)],
                    ['id' => $existing]
                );
                continue;
            }
            
            $is_phrase = str_contains($canonical, ' ');
            
            $insert_result = $wpdb->insert($new_table, [
                'canonical_word' => $canonical,
                'variant_words' => '', // No variants from spellcheck, just misspellings
                'misspellings' => implode(',', $misspellings),
                'is_phrase' => $is_phrase ? 1 : 0,
                'is_active' => 1,
                'created_at' => current_time('mysql'),
            ]);
            
            if ($insert_result) {
                $result['count']++;
            }
        }
        
        return $result;
    }
    
    /**
     * Import legacy stopwords
     */
    private function import_legacy_stopwords(\mysqli $db, string $prefix): array {
        global $wpdb;
        $result = ['count' => 0, 'warnings' => []];
        
        $legacy_table = $db->real_escape_string($prefix . '_stopwords');
        $new_table = $wpdb->prefix . 'cleversay_stopwords';
        
        // Check if table exists
        $check = $db->query("SHOW TABLES LIKE '{$legacy_table}'");
        if (!$check || $check->num_rows === 0) {
            $result['warnings'][] = sprintf(__('Legacy stopwords table "%s" not found', 'cleversay'), $legacy_table);
            return $result;
        }
        
        $rows = $db->query("SELECT * FROM `{$legacy_table}`");
        if (!$rows) {
            return $result;
        }
        
        while ($row = $rows->fetch_assoc()) {
            $word = strtolower(trim((string) ($row['word'] ?? $row['stopword'] ?? $row['value'] ?? '')));
            if (empty($word)) {
                continue;
            }
            
            // Use REPLACE to handle duplicates
            $wpdb->replace($new_table, [
                'word' => $word,
                'is_active' => 1,
            ], ['%s', '%d']);
            
            $result['count']++;
        }
        
        return $result;
    }
    
    /**
     * Import legacy questions (for analytics)
     */
    private function import_legacy_questions(\mysqli $db, string $prefix, int $days = 90): array {
        global $wpdb;
        $result = ['count' => 0, 'warnings' => []];
        
        $legacy_table = $db->real_escape_string($prefix . '_questions');
        $new_table = $wpdb->prefix . 'cleversay_questions';
        
        // Check if table exists
        $check = $db->query("SHOW TABLES LIKE '{$legacy_table}'");
        if (!$check || $check->num_rows === 0) {
            $result['warnings'][] = sprintf(__('Legacy questions table "%s" not found', 'cleversay'), $legacy_table);
            return $result;
        }
        
        // Get columns
        $columns_result = $db->query("SHOW COLUMNS FROM `{$legacy_table}`");
        $columns = [];
        while ($col = $columns_result->fetch_assoc()) {
            $columns[] = strtolower($col['Field']);
        }
        $result['warnings'][] = sprintf(__('Questions columns: %s', 'cleversay'), implode(', ', $columns));
        
        // Determine date column
        $date_col = 'date';
        if (in_array('created_at', $columns)) {
            $date_col = 'created_at';
        } elseif (in_array('asked_at', $columns)) {
            $date_col = 'asked_at';
        } elseif (in_array('timestamp', $columns)) {
            $date_col = 'timestamp';
        }
        
        // Only import recent questions
        $date_limit = date('Y-m-d', strtotime("-{$days} days"));
        $query = "SELECT * FROM `{$legacy_table}` WHERE `{$date_col}` >= '{$date_limit}' ORDER BY `{$date_col}` ASC";
        $rows = $db->query($query);
        
        if (!$rows) {
            // Try without date filter
            $query = "SELECT * FROM `{$legacy_table}` ORDER BY `{$date_col}` DESC LIMIT 1000";
            $rows = $db->query($query);
            if (!$rows) {
                $result['warnings'][] = sprintf(__('Could not read legacy questions: %s', 'cleversay'), $db->error);
                return $result;
            }
        }
        
        while ($row = $rows->fetch_assoc()) {
            $question_text = trim((string) ($row['question'] ?? $row['q'] ?? ''));
            if (empty($question_text)) {
                continue;
            }
            
            $matched_keyword = isset($row['keyword']) ? (string) $row['keyword'] : (isset($row['matched']) ? (string) $row['matched'] : null);
            $matched_sub = isset($row['subkeyword']) ? (string) $row['subkeyword'] : (isset($row['sub_keyword']) ? (string) $row['sub_keyword'] : null);
            
            // Determine match type
            $match_type = 'none';
            if (!empty($matched_keyword)) {
                $match_type = 'partial'; // Default to partial for imported
            }
            
            $created_at = (string) ($row[$date_col] ?? $row['date'] ?? current_time('mysql'));
            $ip_address = (string) ($row['ip'] ?? $row['ip_address'] ?? '');
            
            $wpdb->insert($new_table, [
                'question' => $question_text,
                'matched_keyword' => $matched_keyword,
                'matched_sub_keyword' => $matched_sub,
                'match_type' => $match_type,
                'match_score' => !empty($matched_keyword) ? 100.00 : 0.00,
                'ip_address' => $ip_address,
                'created_at' => $created_at,
            ]);
            
            if ($wpdb->insert_id) {
                $result['count']++;
            }
        }
        
        return $result;
    }
    
    /**
     * Export all data to JSON
     */
    public function export_json(): array {
        global $wpdb;
        
        $export = [
            'version' => self::EXPORT_VERSION,
            'exported_at' => current_time('mysql'),
            'site_url' => get_site_url(),
            'data' => [],
        ];
        
        // Export knowledge base
        $knowledge_table = $wpdb->prefix . 'cleversay_knowledge';
        $export['data']['knowledge'] = $wpdb->get_results(
            "SELECT * FROM {$knowledge_table}",
            ARRAY_A
        );
        
        // Export categories
        $categories_table = $wpdb->prefix . 'cleversay_categories';
        $export['data']['categories'] = $wpdb->get_results(
            "SELECT * FROM {$categories_table}",
            ARRAY_A
        );
        
        // Export synonyms
        $synonyms_table = $wpdb->prefix . 'cleversay_synonyms';
        $export['data']['synonyms'] = $wpdb->get_results(
            "SELECT * FROM {$synonyms_table}",
            ARRAY_A
        );
        
        // Export stopwords
        $export['data']['stopwords'] = get_option('cleversay_stopwords', []);
        
        // Export options
        $export['data']['options'] = get_option('cleversay_options', []);

        // Export AI sources (metadata only — no binary file data)
        $sources_table = $wpdb->prefix . 'cleversay_sources';
        $export['data']['sources'] = $wpdb->get_results(
            "SELECT id, title, source_type, url, status, created_at FROM {$sources_table}",
            ARRAY_A
        );

        // Export AI chunks (the indexed text content)
        $chunks_table = $wpdb->prefix . 'cleversay_chunks';
        $export['data']['chunks'] = $wpdb->get_results(
            "SELECT source_id, chunk_index, content, word_count FROM {$chunks_table}",
            ARRAY_A
        );

        return $export;
    }
    
    /**
     * Export to CSV format
     */
    public function export_csv(string $type = 'knowledge'): string {
        global $wpdb;
        
        $output = '';
        
        switch ($type) {
            case 'knowledge':
                $table = $wpdb->prefix . 'cleversay_knowledge';
                $rows = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
                
                if (!empty($rows)) {
                    // Headers
                    $output .= implode(',', array_map([$this, 'csv_escape'], array_keys($rows[0]))) . "\n";
                    
                    // Data rows
                    foreach ($rows as $row) {
                        $output .= implode(',', array_map([$this, 'csv_escape'], $row)) . "\n";
                    }
                }
                break;
                
            case 'questions':
                $table = $wpdb->prefix . 'cleversay_questions';
                $rows = $wpdb->get_results(
                    "SELECT question, matched_keyword, match_type, ip_address, created_at FROM {$table} ORDER BY created_at DESC LIMIT 10000",
                    ARRAY_A
                );
                
                if (!empty($rows)) {
                    $output .= implode(',', array_map([$this, 'csv_escape'], array_keys($rows[0]))) . "\n";
                    foreach ($rows as $row) {
                        $output .= implode(',', array_map([$this, 'csv_escape'], $row)) . "\n";
                    }
                }
                break;
                
            case 'synonyms':
                $table = $wpdb->prefix . 'cleversay_synonyms';
                $rows = $wpdb->get_results("SELECT term, replacement, is_phrase FROM {$table}", ARRAY_A);
                
                if (!empty($rows)) {
                    $output .= implode(',', array_map([$this, 'csv_escape'], array_keys($rows[0]))) . "\n";
                    foreach ($rows as $row) {
                        $output .= implode(',', array_map([$this, 'csv_escape'], $row)) . "\n";
                    }
                }
                break;
        }
        
        return $output;
    }
    
    /**
     * Import from JSON file
     */

    /**
     * Preview a JSON import without writing anything to the database.
     *
     * Returns a summary of what would be imported/updated/skipped so the
     * admin can review before committing.
     *
     * @param array $data Decoded JSON export data.
     * @return array Preview summary.
     */
    public function preview_json_import(array $data): array {
        global $wpdb;
        $knowledge_table = $wpdb->prefix . 'cleversay_knowledge';
        $synonyms_table  = $wpdb->prefix . 'cleversay_synonyms';

        $preview = [
            'valid'      => true,
            'version'    => $data['version'] ?? null,
            'errors'     => [],
            'knowledge'  => [
                'total'      => 0,
                'new'        => 0,
                'duplicate'  => 0,
                'samples'    => [],
            ],
            'categories' => [
                'total' => 0,
                'new'   => 0,
            ],
            'synonyms'   => [
                'total'     => 0,
                'new'       => 0,
                'duplicate' => 0,
            ],
            'stopwords'  => [
                'total' => 0,
                'new'   => 0,
            ],
        ];

        if (empty($data['version'])) {
            $preview['valid']    = false;
            $preview['errors'][] = __('Invalid export file — missing version field.', 'cleversay');
            return $preview;
        }

        // ── Knowledge base ──────────────────────────────────────────────────
        if (!empty($data['data']['knowledge']) && is_array($data['data']['knowledge'])) {
            $entries = $data['data']['knowledge'];
            $preview['knowledge']['total'] = count($entries);
            $sample_limit = 5;

            foreach ($entries as $entry) {
                $keyword     = trim((string) ($entry['keyword'] ?? ''));
                $sub_keyword = trim((string) ($entry['sub_keyword'] ?? ''));

                if (empty($keyword)) continue;

                // Check for existing duplicate
                $exists = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$knowledge_table}
                         WHERE keyword = %s AND (sub_keyword = %s OR (sub_keyword IS NULL AND %s = ''))",
                        $keyword, $sub_keyword, $sub_keyword
                    )
                );

                if ($exists) {
                    $preview['knowledge']['duplicate']++;
                } else {
                    $preview['knowledge']['new']++;
                    if (count($preview['knowledge']['samples']) < $sample_limit) {
                        $preview['knowledge']['samples'][] = [
                            'keyword'     => $keyword,
                            'sub_keyword' => $sub_keyword,
                            'question'    => substr((string) ($entry['question'] ?? ''), 0, 80),
                        ];
                    }
                }
            }
        }

        // ── Categories ──────────────────────────────────────────────────────
        if (!empty($data['data']['categories']) && is_array($data['data']['categories'])) {
            $cats_table = $wpdb->prefix . 'cleversay_categories';
            $preview['categories']['total'] = count($data['data']['categories']);

            foreach ($data['data']['categories'] as $cat) {
                $name = trim((string) ($cat['name'] ?? ''));
                if (empty($name)) continue;

                $exists = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT COUNT(*) FROM {$cats_table} WHERE name = %s", $name)
                );
                if (!$exists) {
                    $preview['categories']['new']++;
                }
            }
        }

        // ── Synonyms ────────────────────────────────────────────────────────
        if (!empty($data['data']['synonyms']) && is_array($data['data']['synonyms'])) {
            $preview['synonyms']['total'] = count($data['data']['synonyms']);

            foreach ($data['data']['synonyms'] as $syn) {
                $canonical = trim((string) ($syn['canonical_word'] ?? ''));
                if (empty($canonical)) continue;

                $exists = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$synonyms_table} WHERE canonical_word = %s",
                        $canonical
                    )
                );
                if ($exists) {
                    $preview['synonyms']['duplicate']++;
                } else {
                    $preview['synonyms']['new']++;
                }
            }
        }

        // ── Stopwords ───────────────────────────────────────────────────────
        if (!empty($data['data']['stopwords']) && is_array($data['data']['stopwords'])) {
            $stopwords_table = $wpdb->prefix . 'cleversay_stopwords';
            $incoming = $data['data']['stopwords'];
            $preview['stopwords']['total'] = count($incoming);

            foreach ($incoming as $word) {
                $word = strtolower(trim((string) $word));
                if (empty($word)) continue;

                $exists = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$stopwords_table} WHERE word = %s",
                        $word
                    )
                );
                if (!$exists) {
                    $preview['stopwords']['new']++;
                }
            }
        }

        return $preview;
    }

    public function import_json(array $data): array {
        $result = [
            'success' => false,
            'imported' => [
                'knowledge' => 0,
                'categories' => 0,
                'synonyms' => 0,
                'sources'   => 0,
                'chunks'    => 0,
            ],
            'errors' => [],
        ];
        
        // Validate export version
        if (empty($data['version'])) {
            $result['errors'][] = __('Invalid export file format', 'cleversay');
            return $result;
        }
        
        global $wpdb;
        
        try {
            // Import categories first (for foreign key references)
            if (!empty($data['data']['categories'])) {
                $result['imported']['categories'] = $this->import_categories($data['data']['categories']);
            }
            
            // Import knowledge base
            if (!empty($data['data']['knowledge'])) {
                $result['imported']['knowledge'] = $this->import_knowledge($data['data']['knowledge']);
            }
            
            // Import synonyms
            if (!empty($data['data']['synonyms'])) {
                $result['imported']['synonyms'] = $this->import_synonyms($data['data']['synonyms']);
            }
            
            // Import stopwords
            if (!empty($data['data']['stopwords'])) {
                $existing = get_option('cleversay_stopwords', []);
                $merged = array_unique(array_merge($existing, $data['data']['stopwords']));
                update_option('cleversay_stopwords', $merged);
            }

            // Import AI sources + chunks
            $sources_in_file = $data['data']['sources'] ?? [];
            $chunks_in_file  = $data['data']['chunks']  ?? [];
            // Store counts of what was in the file for diagnostics
            $result['imported']['sources_in_file'] = count($sources_in_file);
            $result['imported']['chunks_in_file']  = count($chunks_in_file);

            if (!empty($sources_in_file)) {
                $counts = $this->import_sources_and_chunks($sources_in_file, $chunks_in_file);
                $result['imported']['sources'] = $counts['sources'];
                $result['imported']['chunks']  = $counts['chunks'];
                $result['imported']['skipped_sources'] = $counts['skipped_sources'];
                $result['imported']['skipped_chunks']  = $counts['skipped_chunks'];
                $result['imported']['failed_chunks']   = $counts['failed_chunks'];
            }

            $result['success'] = true;
            
        } catch (\Exception $e) {
            $result['errors'][] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Import from CSV file
     */
    public function import_csv(string $content, string $type = 'knowledge'): array {
        $result = [
            'success' => false,
            'imported' => 0,
            'errors' => [],
        ];
        
        $lines = explode("\n", trim($content));
        if (count($lines) < 2) {
            $result['errors'][] = __('CSV file is empty or has no data rows', 'cleversay');
            return $result;
        }
        
        // Parse header
        $headers = str_getcsv(array_shift($lines));
        $headers = array_map('trim', $headers);
        $headers = array_map('strtolower', $headers);
        
        global $wpdb;
        
        switch ($type) {
            case 'knowledge':
                $table = $wpdb->prefix . 'cleversay_knowledge';
                $required = ['keyword', 'response'];
                break;
                
            case 'synonyms':
                $table = $wpdb->prefix . 'cleversay_synonyms';
                $required = ['canonical_word', 'variant_words'];
                break;
                
            default:
                $result['errors'][] = __('Unsupported import type', 'cleversay');
                return $result;
        }
        
        // Validate required columns
        foreach ($required as $field) {
            if (!in_array($field, $headers)) {
                $result['errors'][] = sprintf(__('Missing required column: %s', 'cleversay'), $field);
            }
        }
        
        if (!empty($result['errors'])) {
            return $result;
        }
        
        // Process rows
        $line_num = 1;
        foreach ($lines as $line) {
            $line_num++;
            
            if (empty(trim($line))) {
                continue;
            }
            
            $values = str_getcsv($line);
            
            if (count($values) !== count($headers)) {
                $result['errors'][] = sprintf(__('Invalid column count on line %d', 'cleversay'), $line_num);
                continue;
            }
            
            $row = array_combine($headers, $values);
            
            if ($type === 'knowledge') {
                $insert_data = [
                    'keyword' => sanitize_text_field($row['keyword']),
                    'subkeyword' => sanitize_text_field($row['subkeyword'] ?? ''),
                    'response' => wp_kses_post($row['response']),
                    'status' => in_array($row['status'] ?? '', ['active', 'inactive', 'draft']) 
                        ? $row['status'] : 'active',
                    'search_type' => in_array($row['search_type'] ?? '', ['exact', 'prefix', 'suffix', 'contains'])
                        ? $row['search_type'] : 'contains',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ];
            } else {
                $insert_data = [
                    'canonical_word' => strtolower(sanitize_text_field($row['canonical_word'])),
                    'variant_words'  => sanitize_textarea_field($row['variant_words'] ?? ''),
                    'misspellings'   => sanitize_textarea_field($row['misspellings'] ?? ''),
                    'is_phrase'      => !empty($row['is_phrase']) ? 1 : 0,
                    'is_active'      => 1,
                ];
            }
            
            if ($wpdb->insert($table, $insert_data)) {
                $result['imported']++;
            } else {
                $result['errors'][] = sprintf(
                    __('Failed to import line %d: %s', 'cleversay'),
                    $line_num,
                    $wpdb->last_error
                );
            }
        }
        
        $result['success'] = $result['imported'] > 0;
        
        return $result;
    }
    
    /**
     * Import categories from export data
     */
    private function import_categories(array $categories): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_categories';
        $count = 0;
        
        // Create ID mapping for parent references
        $id_map = [];
        
        // First pass: insert all without parent references
        foreach ($categories as $cat) {
            $old_id = $cat['id'];
            unset($cat['id']);
            $cat['parent_id'] = null;
            $cat['created_at'] = current_time('mysql');
            
            if ($wpdb->insert($table, $cat)) {
                $id_map[$old_id] = $wpdb->insert_id;
                $count++;
            }
        }
        
        // Second pass: update parent references
        foreach ($categories as $cat) {
            if (!empty($cat['parent_id']) && isset($id_map[$cat['parent_id']])) {
                $wpdb->update(
                    $table,
                    ['parent_id' => $id_map[$cat['parent_id']]],
                    ['id' => $id_map[$cat['id']]]
                );
            }
        }
        
        return $count;
    }
    
    /**
     * Import knowledge entries from export data
     */
    private function import_knowledge(array $entries): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_knowledge';
        $count = 0;
        
        foreach ($entries as $entry) {
            unset($entry['id']);
            $entry['created_at'] = current_time('mysql');
            $entry['updated_at'] = current_time('mysql');
            $entry['created_by'] = get_current_user_id();
            
            // Clear user references that won't exist
            $entry['approved_by'] = null;
            
            if ($wpdb->insert($table, $entry)) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Import synonyms from export data
     */
    private function import_synonyms(array $synonyms): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_synonyms';
        $count = 0;
        
        foreach ($synonyms as $syn) {
            unset($syn['id']);

            // Support both old schema (term/replacement) and new schema (canonical_word/variant_words)
            if (!isset($syn['canonical_word']) && isset($syn['term'])) {
                $syn['canonical_word'] = $syn['term'];
                unset($syn['term']);
            }
            if (!isset($syn['variant_words']) && isset($syn['replacement'])) {
                $syn['variant_words'] = $syn['replacement'];
                unset($syn['replacement']);
            }

            // Skip if still missing the required field
            if (empty($syn['canonical_word'])) {
                continue;
            }

            // Check for duplicate
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE canonical_word = %s",
                $syn['canonical_word']
            ));
            
            if (!$existing && $wpdb->insert($table, $syn)) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Convert legacy HTML content
     */
    private function convert_legacy_html(string $html): string {
        // Remove FCKeditor artifacts
        $html = preg_replace('/<\?xml[^>]*\?>/', '', $html);
        $html = preg_replace('/<!--\[if[^\]]*\]>.*?<!\[endif\]-->/s', '', $html);
        
        // Convert deprecated tags
        $replacements = [
            '/<font[^>]*color=["\']([^"\']+)["\'][^>]*>/i' => '<span style="color:$1">',
            '/<\/font>/i' => '</span>',
            '/<center>/i' => '<div style="text-align:center">',
            '/<\/center>/i' => '</div>',
        ];
        
        foreach ($replacements as $pattern => $replacement) {
            $html = preg_replace($pattern, $replacement, $html);
        }
        
        // Clean up whitespace
        $html = preg_replace('/\s+/', ' ', $html);
        $html = trim($html);
        
        return $html;
    }
    
    /**
     * Escape value for CSV
     */
    private function csv_escape($value): string {
        $value = (string) $value;
        
        // If contains special characters, wrap in quotes and escape existing quotes
        if (preg_match('/[,"\n\r]/', $value)) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        
        return $value;
    }
    
    /**
     * Create backup before import
     */
    /**
     * Import AI sources and their chunks.
     * Uses the source URL as a unique key — existing sources with the same URL are skipped.
     */
    private function import_sources_and_chunks(array $sources, array $chunks): array {
        global $wpdb;
        $sources_table = $wpdb->prefix . 'cleversay_sources';
        $chunks_table  = $wpdb->prefix . 'cleversay_chunks';
        $source_count        = 0;
        $chunk_count         = 0;
        $skipped_source_count = 0;
        $skipped_chunk_count  = 0;
        $failed_chunk_count   = 0;

        // Build a map of old_id -> new_id for chunk re-linking
        $id_map = [];

        foreach ($sources as $source) {
            $old_id = (int) $source['id'];

            // Check if source already exists by URL
            if (!empty($source['url'])) {
                $existing_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$sources_table} WHERE url = %s LIMIT 1",
                    $source['url']
                ));
                if ($existing_id) {
                    $id_map[$old_id] = (int) $existing_id;
                    $skipped_source_count++;
                    continue;
                }
            }

            $wpdb->insert($sources_table, [
                'title'       => $source['title']       ?? '',
                'source_type' => $source['source_type'] ?? 'url',
                'url'         => $source['url']         ?? null,
                'status'      => 'indexed',
                'created_at'  => $source['created_at']  ?? current_time('mysql'),
            ]);

            $new_id = (int) $wpdb->insert_id;

            // If insert failed (e.g. race condition), try to find by URL again
            if (!$new_id && !empty($source['url'])) {
                $new_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$sources_table} WHERE url = %s LIMIT 1",
                    $source['url']
                ));
            }

            if ($new_id) {
                $id_map[$old_id] = $new_id;
                $source_count++;
            }
            // If still no ID, this source's chunks will be skipped
        }

        // Import chunks, re-linking to new source IDs
        foreach ($chunks as $chunk) {
            $old_source_id = (int) $chunk['source_id'];
            $new_source_id = $id_map[$old_source_id] ?? null;
            if (!$new_source_id) {
                $failed_chunk_count++;
                continue;
            }

            $chunk_content = $chunk['content'] ?? '';
            $chunk_words   = (int) ($chunk['word_count'] ?? $chunk['token_count'] ?? 0);

            // If chunk exists with real content, skip. If empty/broken, update it.
            $existing_chunk = $wpdb->get_row($wpdb->prepare(
                "SELECT id, word_count FROM {$chunks_table} WHERE source_id = %d AND chunk_index = %d LIMIT 1",
                $new_source_id,
                (int) $chunk['chunk_index']
            ));

            if ($existing_chunk) {
                // Update if existing chunk has no content (broken from previous import)
                if (empty($existing_chunk->word_count) && !empty($chunk_content)) {
                    $updated = $wpdb->update(
                        $chunks_table,
                        ['content' => $chunk_content, 'word_count' => $chunk_words],
                        ['id' => $existing_chunk->id]
                    );
                    if ($updated !== false) $chunk_count++;
                    else $failed_chunk_count++;
                } else {
                    $skipped_chunk_count++;
                }
                continue;
            }

            $wpdb->insert($chunks_table, [
                'source_id'   => $new_source_id,
                'chunk_index' => (int) $chunk['chunk_index'],
                'content'     => $chunk_content,
                'word_count'  => $chunk_words,
            ]);

            if ($wpdb->insert_id) $chunk_count++;
            else $failed_chunk_count++;
        }

        // Update chunk_count and word_count summary columns on each source row
        // These are denormalized counts stored on the sources table for display
        foreach ($id_map as $old_id => $new_source_id) {
            $counts = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) as chunk_count, COALESCE(SUM(word_count),0) as word_count
                 FROM {$chunks_table} WHERE source_id = %d",
                $new_source_id
            ));
            if ($counts && (int)$counts->chunk_count > 0) {
                $wpdb->update(
                    $sources_table,
                    [
                        'chunk_count' => (int) $counts->chunk_count,
                        'word_count'  => (int) $counts->word_count,
                        'status'      => 'indexed',
                    ],
                    ['id' => $new_source_id]
                );
            }
        }

        return [
            'sources'         => $source_count,
            'chunks'          => $chunk_count,
            'skipped_sources' => $skipped_source_count,
            'skipped_chunks'  => $skipped_chunk_count,
            'failed_chunks'   => $failed_chunk_count,
        ];
    }


    public function create_backup(): string {
        $export = $this->export_json();
        $filename = 'cleversay-backup-' . date('Y-m-d-His') . '.json';
        
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'] . '/cleversay-backups';
        
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
            
            // Add .htaccess to prevent direct access
            file_put_contents($backup_dir . '/.htaccess', 'deny from all');
        }
        
        $filepath = $backup_dir . '/' . $filename;
        file_put_contents($filepath, json_encode($export, JSON_PRETTY_PRINT));
        
        return $filepath;
    }
    
    /**
     * Get list of available backups
     */
    public function get_backups(): array {
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'] . '/cleversay-backups';
        
        if (!file_exists($backup_dir)) {
            return [];
        }
        
        $files = glob($backup_dir . '/cleversay-backup-*.json');
        $backups = [];
        
        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'date' => filemtime($file),
            ];
        }
        
        // Sort by date descending
        usort($backups, fn($a, $b) => $b['date'] <=> $a['date']);
        
        return $backups;
    }

    /**
     * Delete backup files beyond the retention limit. Keeps the
     * BACKUP_KEEP newest files; deletes everything older.
     *
     * Used by:
     *   - The daily KB backup cron handler (after creating a new backup)
     *   - Manual backup creation paths that want to enforce the same cap
     *
     * Returns the count of files deleted (zero if under the limit).
     * Safe to call when no backups exist or when count is already at
     * or below BACKUP_KEEP.
     *
     * @since 4.42.26
     */
    public function prune_backups(): int {
        $backups = $this->get_backups();  // already sorted newest first
        if (count($backups) <= self::BACKUP_KEEP) {
            return 0;
        }
        $to_delete = array_slice($backups, self::BACKUP_KEEP);
        $deleted = 0;
        foreach ($to_delete as $b) {
            // Defensive: only delete files in the backup dir, never
            // anything outside it. get_backups() builds paths from
            // the known backup dir, but belt-and-suspenders the check.
            $upload_dir = wp_upload_dir();
            $backup_dir = $upload_dir['basedir'] . '/cleversay-backups';
            if (strpos($b['path'], $backup_dir) !== 0) continue;
            if (@unlink($b['path'])) {
                $deleted++;
            }
        }
        return $deleted;
    }
    
    /**
     * Restore from backup
     */
    public function restore_backup(string $filename): array {
        $upload_dir = wp_upload_dir();
        $filepath = $upload_dir['basedir'] . '/cleversay-backups/' . basename($filename);
        
        if (!file_exists($filepath)) {
            return [
                'success' => false,
                'errors' => [__('Backup file not found', 'cleversay')],
            ];
        }
        
        $content = file_get_contents($filepath);
        $data = json_decode($content, true);
        
        if (!$data) {
            return [
                'success' => false,
                'errors' => [__('Invalid backup file format', 'cleversay')],
            ];
        }
        
        // Create backup of current data before restore
        $this->create_backup();
        
        // Clear existing data
        $this->clear_all_data();
        
        // Import backup data
        return $this->import_json($data);
    }
    
    /**
     * Clear all plugin data (use with caution!)
     */
    private function clear_all_data(): void {
        global $wpdb;
        
        $tables = [
            'cleversay_ratings',
            'cleversay_inquiries',
            'cleversay_questions',
            'cleversay_visitors',
            'cleversay_knowledge',
            'cleversay_synonyms',
            'cleversay_categories',
        ];
        
        foreach ($tables as $table) {
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}{$table}");
        }
    }
    
    /**
     * Delete old backups (keep last N)
     */
    public function cleanup_old_backups(int $keep = 10): int {
        $backups = $this->get_backups();
        $deleted = 0;
        
        if (count($backups) > $keep) {
            $to_delete = array_slice($backups, $keep);
            
            foreach ($to_delete as $backup) {
                if (unlink($backup['path'])) {
                    $deleted++;
                }
            }
        }
        
        return $deleted;
    }
}
