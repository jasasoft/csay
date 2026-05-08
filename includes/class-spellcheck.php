<?php
/**
 * CleverSay Spellcheck
 *
 * Handles spelling corrections and synonym replacements
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
 * Spellcheck Handler
 */
class Spellcheck {
    
    private const CACHE_KEY = 'cleversay_spellcheck_data';
    private const CACHE_EXPIRY = 3600; // 1 hour
    
    /**
     * Minimum similarity threshold for Levenshtein matching (0-100)
     */
    private int $similarity_threshold = 75;
    
    /**
     * Cached synonyms and corrections
     */
    private ?array $cache = null;
    
    /**
     * Constructor
     */
    public function __construct() {
        $options = get_option('cleversay_options', []);
        $this->similarity_threshold = (int) ($options['spellcheck_threshold'] ?? 75);
    }
    
    /**
     * Process text with spell checking and synonym replacement
     */
    public function process(string $text): array {
        $original = $text;
        $corrections = [];
        $synonyms_applied = [];
        
        // Load cached data
        $this->load_cache();
        
        // Step 1: Apply phrase-level synonyms first (before tokenization)
        $text = $this->apply_phrase_synonyms($text, $synonyms_applied);
        
        // Step 2: Tokenize
        $words = $this->tokenize($text);
        
        // Step 3: Process each word
        $processed_words = [];
        foreach ($words as $word) {
            $result = $this->process_word($word);
            $processed_words[] = $result['word'];
            
            if ($result['corrected']) {
                $corrections[$word] = $result['word'];
            }
            if ($result['synonym']) {
                $synonyms_applied[$word] = $result['word'];
            }
        }
        
        return [
            'original' => $original,
            'processed' => implode(' ', $processed_words),
            'words' => $processed_words,
            'corrections' => $corrections,
            'synonyms' => $synonyms_applied,
        ];
    }
    
    /**
     * Apply phrase-level synonym replacements
     */
    private function apply_phrase_synonyms(string $text, array &$synonyms_applied): string {
        if (empty($this->cache['phrases'])) {
            return $text;
        }
        
        // Sort phrases by length (longest first) to avoid partial replacements
        $phrases = $this->cache['phrases'];
        usort($phrases, fn($a, $b) => strlen($b['term']) <=> strlen($a['term']));
        
        $text_lower = strtolower($text);
        
        foreach ($phrases as $phrase) {
            $term = $phrase['term'];
            $replacement = $phrase['replacement'];
            
            // Check if phrase exists in text
            if (str_contains($text_lower, $term)) {
                // Replace (case-insensitive)
                $text = preg_replace(
                    '/\b' . preg_quote($term, '/') . '\b/i',
                    $replacement,
                    $text
                );
                $text_lower = strtolower($text);
                $synonyms_applied[$term] = $replacement;
            }
        }
        
        return $text;
    }
    
    /**
     * Process a single word
     */
    private function process_word(string $word): array {
        $result = [
            'word' => $word,
            'corrected' => false,
            'synonym' => false,
        ];
        
        $word_lower = strtolower($word);
        
        // Check for exact synonym match first
        if (isset($this->cache['words'][$word_lower])) {
            $result['word'] = $this->cache['words'][$word_lower];
            $result['synonym'] = true;
            return $result;
        }
        
        // Check for wildcard synonyms
        $wildcard_match = $this->check_wildcard_synonyms($word_lower);
        if ($wildcard_match) {
            $result['word'] = $wildcard_match;
            $result['synonym'] = true;
            return $result;
        }
        
        // Try spelling correction
        $correction = $this->find_spelling_correction($word_lower);
        if ($correction && $correction !== $word_lower) {
            $result['word'] = $correction;
            $result['corrected'] = true;
            
            // After correction, check if there's a synonym
            if (isset($this->cache['words'][$correction])) {
                $result['word'] = $this->cache['words'][$correction];
                $result['synonym'] = true;
            }
        }
        
        return $result;
    }
    
    /**
     * Check wildcard synonym patterns
     */
    private function check_wildcard_synonyms(string $word): ?string {
        if (empty($this->cache['wildcards'])) {
            return null;
        }
        
        foreach ($this->cache['wildcards'] as $pattern) {
            $term = $pattern['term'];
            $replacement = $pattern['replacement'];
            
            // Convert wildcard pattern to regex
            if (str_starts_with($term, '*') && str_ends_with($term, '*')) {
                // Contains match
                $search = trim($term, '*');
                if (str_contains($word, $search)) {
                    return str_replace($search, $replacement, $word);
                }
            } elseif (str_starts_with($term, '*')) {
                // Suffix match
                $suffix = ltrim($term, '*');
                if (str_ends_with($word, $suffix)) {
                    return substr($word, 0, -strlen($suffix)) . $replacement;
                }
            } elseif (str_ends_with($term, '*')) {
                // Prefix match
                $prefix = rtrim($term, '*');
                if (str_starts_with($word, $prefix)) {
                    return $replacement . substr($word, strlen($prefix));
                }
            }
        }
        
        return null;
    }
    
    /**
     * Find spelling correction using Levenshtein distance
     */
    private function find_spelling_correction(string $word): ?string {
        if (strlen($word) < 3) {
            return null;
        }
        
        // Get potential candidates starting with same letter
        $first_letter = $word[0];
        $candidates = $this->cache['by_letter'][$first_letter] ?? [];
        
        // Also check words starting with adjacent letters (for first-letter typos)
        $adjacent_letters = $this->get_adjacent_letters($first_letter);
        foreach ($adjacent_letters as $letter) {
            $candidates = array_merge($candidates, $this->cache['by_letter'][$letter] ?? []);
        }
        
        // Remove duplicates
        $candidates = array_unique($candidates);
        
        if (empty($candidates)) {
            return null;
        }
        
        $best_match = null;
        $best_similarity = 0;
        
        foreach ($candidates as $candidate) {
            // Skip if length difference is too great
            if (abs(strlen($word) - strlen($candidate)) > 2) {
                continue;
            }
            
            $similarity = $this->calculate_similarity($word, $candidate);
            
            if ($similarity >= $this->similarity_threshold && $similarity > $best_similarity) {
                $best_similarity = $similarity;
                $best_match = $candidate;
            }
        }
        
        return $best_match;
    }
    
    /**
     * Calculate similarity between two strings (0-100)
     */
    private function calculate_similarity(string $str1, string $str2): float {
        if ($str1 === $str2) {
            return 100.0;
        }
        
        $len1 = strlen($str1);
        $len2 = strlen($str2);
        $max_len = max($len1, $len2);
        
        if ($max_len === 0) {
            return 100.0;
        }
        
        $distance = levenshtein($str1, $str2);
        
        return (1 - ($distance / $max_len)) * 100;
    }
    
    /**
     * Get adjacent keyboard letters
     */
    private function get_adjacent_letters(string $letter): array {
        $keyboard_layout = [
            'q' => ['w', 'a'],
            'w' => ['q', 'e', 's', 'a'],
            'e' => ['w', 'r', 'd', 's'],
            'r' => ['e', 't', 'f', 'd'],
            't' => ['r', 'y', 'g', 'f'],
            'y' => ['t', 'u', 'h', 'g'],
            'u' => ['y', 'i', 'j', 'h'],
            'i' => ['u', 'o', 'k', 'j'],
            'o' => ['i', 'p', 'l', 'k'],
            'p' => ['o', 'l'],
            'a' => ['q', 'w', 's', 'z'],
            's' => ['a', 'w', 'e', 'd', 'x', 'z'],
            'd' => ['s', 'e', 'r', 'f', 'c', 'x'],
            'f' => ['d', 'r', 't', 'g', 'v', 'c'],
            'g' => ['f', 't', 'y', 'h', 'b', 'v'],
            'h' => ['g', 'y', 'u', 'j', 'n', 'b'],
            'j' => ['h', 'u', 'i', 'k', 'm', 'n'],
            'k' => ['j', 'i', 'o', 'l', 'm'],
            'l' => ['k', 'o', 'p'],
            'z' => ['a', 's', 'x'],
            'x' => ['z', 's', 'd', 'c'],
            'c' => ['x', 'd', 'f', 'v'],
            'v' => ['c', 'f', 'g', 'b'],
            'b' => ['v', 'g', 'h', 'n'],
            'n' => ['b', 'h', 'j', 'm'],
            'm' => ['n', 'j', 'k'],
        ];
        
        return $keyboard_layout[strtolower($letter)] ?? [];
    }
    
    /**
     * Tokenize text into words
     */
    private function tokenize(string $text): array {
        // Remove special characters but keep apostrophes
        $text = preg_replace("/[^\w\s']/u", ' ', $text);
        
        // Split on whitespace
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        // Normalize
        return array_map('strtolower', $words);
    }
    
    /**
     * Load cached synonym and correction data
     */
    private function load_cache(): void {
        if ($this->cache !== null) {
            return;
        }
        
        $cached = wp_cache_get(self::CACHE_KEY, 'cleversay');
        if ($cached !== false) {
            $this->cache = $cached;
            return;
        }
        
        $this->cache = $this->build_cache();
        wp_cache_set(self::CACHE_KEY, $this->cache, 'cleversay', self::CACHE_EXPIRY);
    }
    
    /**
     * Build the synonym/correction cache
     */
    private function build_cache(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_synonyms';
        
        $cache = [
            'phrases' => [],
            'words' => [],
            'wildcards' => [],
            'by_letter' => [],
        ];
        
        $synonyms = $wpdb->get_results(
            "SELECT term, replacement, is_phrase FROM {$table} WHERE is_active = 1",
            ARRAY_A
        );
        
        foreach ($synonyms as $syn) {
            $term = strtolower($syn['term']);
            $replacement = strtolower($syn['replacement']);
            
            if ($syn['is_phrase']) {
                $cache['phrases'][] = [
                    'term' => $term,
                    'replacement' => $replacement,
                ];
            } elseif (str_contains($term, '*')) {
                $cache['wildcards'][] = [
                    'term' => $term,
                    'replacement' => $replacement,
                ];
            } else {
                $cache['words'][$term] = $replacement;
                
                // Build by-letter index for spelling correction
                $first = $replacement[0] ?? '';
                if (!isset($cache['by_letter'][$first])) {
                    $cache['by_letter'][$first] = [];
                }
                if (!in_array($replacement, $cache['by_letter'][$first])) {
                    $cache['by_letter'][$first][] = $replacement;
                }
            }
        }
        
        // Add all knowledge base keywords to the by-letter index
        $knowledge_table = $wpdb->prefix . 'cleversay_knowledge';
        $keywords = $wpdb->get_col(
            "SELECT DISTINCT LOWER(keyword) FROM {$knowledge_table} WHERE status = 'active'"
        );
        
        foreach ($keywords as $keyword) {
            // Split multi-word keywords
            $words = preg_split('/\s+/', $keyword);
            foreach ($words as $word) {
                $word = preg_replace('/[^a-z0-9]/', '', $word);
                if (strlen($word) >= 3) {
                    $first = $word[0];
                    if (!isset($cache['by_letter'][$first])) {
                        $cache['by_letter'][$first] = [];
                    }
                    if (!in_array($word, $cache['by_letter'][$first])) {
                        $cache['by_letter'][$first][] = $word;
                    }
                }
            }
        }
        
        return $cache;
    }
    
    /**
     * Add a synonym
     */
    public function add_synonym(string $term, string $replacement, bool $is_phrase = false): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_synonyms';
        
        $term = strtolower(trim($term));
        $replacement = strtolower(trim($replacement));
        
        if (empty($term) || empty($replacement)) {
            return false;
        }
        
        // Check for existing
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE term = %s",
            $term
        ));
        
        if ($existing) {
            // Update existing
            $result = $wpdb->update(
                $table,
                [
                    'replacement' => $replacement,
                    'is_phrase' => $is_phrase ? 1 : 0,
                    'is_active' => 1,
                ],
                ['id' => $existing]
            );
        } else {
            // Insert new
            $result = $wpdb->insert($table, [
                'term' => $term,
                'replacement' => $replacement,
                'is_phrase' => $is_phrase ? 1 : 0,
                'is_active' => 1,
            ]);
        }
        
        $this->clear_cache();
        
        return $result !== false;
    }
    
    /**
     * Remove a synonym
     */
    public function remove_synonym(int $id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_synonyms';
        
        $result = $wpdb->delete($table, ['id' => $id]);
        
        if ($result) {
            $this->clear_cache();
        }
        
        return $result !== false;
    }
    
    /**
     * Toggle synonym active status
     */
    public function toggle_synonym(int $id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_synonyms';
        
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET is_active = NOT is_active WHERE id = %d",
            $id
        ));
        
        if ($result) {
            $this->clear_cache();
        }
        
        return $result !== false;
    }
    
    /**
     * Get all synonyms
     */
    public function get_synonyms(bool $active_only = false): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_synonyms';
        
        $where = $active_only ? 'WHERE is_active = 1' : '';
        
        return $wpdb->get_results(
            "SELECT * FROM {$table} {$where} ORDER BY term ASC",
            ARRAY_A
        );
    }
    
    /**
     * Suggest similar words (for "Did you mean?" functionality)
     */
    public function suggest_similar(string $word, int $limit = 3): array {
        $this->load_cache();
        
        $word_lower = strtolower($word);
        $suggestions = [];
        
        // Check all cached words
        foreach ($this->cache['by_letter'] as $words) {
            foreach ($words as $candidate) {
                $similarity = $this->calculate_similarity($word_lower, $candidate);
                if ($similarity >= 60 && $similarity < 100) {
                    $suggestions[$candidate] = $similarity;
                }
            }
        }
        
        // Sort by similarity descending
        arsort($suggestions);
        
        return array_slice(array_keys($suggestions), 0, $limit);
    }
    
    /**
     * Check if a word is in our vocabulary
     */
    public function is_known_word(string $word): bool {
        $this->load_cache();
        
        $word_lower = strtolower($word);
        
        // Check synonyms
        if (isset($this->cache['words'][$word_lower])) {
            return true;
        }
        
        // Check by-letter index
        $first = $word_lower[0] ?? '';
        return in_array($word_lower, $this->cache['by_letter'][$first] ?? []);
    }
    
    /**
     * Clear the synonym cache
     */
    public function clear_cache(): void {
        $this->cache = null;
        wp_cache_delete(self::CACHE_KEY, 'cleversay');
    }
    
    /**
     * Bulk import synonyms
     */
    public function bulk_import(array $synonyms): array {
        $result = [
            'imported' => 0,
            'updated' => 0,
            'errors' => [],
        ];
        
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_synonyms';
        
        foreach ($synonyms as $syn) {
            $term = strtolower(trim($syn['term'] ?? ''));
            $replacement = strtolower(trim($syn['replacement'] ?? ''));
            $is_phrase = !empty($syn['is_phrase']);
            
            if (empty($term) || empty($replacement)) {
                $result['errors'][] = sprintf(
                    __('Skipped invalid entry: %s', 'cleversay'),
                    $term ?: '(empty)'
                );
                continue;
            }
            
            // Check for existing
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE term = %s",
                $term
            ));
            
            if ($existing) {
                $wpdb->update(
                    $table,
                    ['replacement' => $replacement, 'is_phrase' => $is_phrase ? 1 : 0],
                    ['id' => $existing]
                );
                $result['updated']++;
            } else {
                $wpdb->insert($table, [
                    'term' => $term,
                    'replacement' => $replacement,
                    'is_phrase' => $is_phrase ? 1 : 0,
                    'is_active' => 1,
                ]);
                $result['imported']++;
            }
        }
        
        $this->clear_cache();
        
        return $result;
    }
    
    /**
     * Get statistics about synonyms
     */
    public function get_stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_synonyms';
        
        return [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'active' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_active = 1"),
            'phrases' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_phrase = 1"),
            'wildcards' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE term LIKE '%*%'"),
        ];
    }
}
