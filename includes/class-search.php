<?php
/**
 * CleverSay Search Engine
 * 
 * Handles intelligent search, pattern matching, and response retrieval
 * 
 * @package CleverSay
 * @since 2.0.0
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class Search {
    
    private \wpdb $wpdb;
    private Database $db;
    private Logger $logger;
    private array $stopwords = [];
    private array $synonyms = [];
    private bool $debug = false;

    /**
     * Multilingual context (set by PublicFacing::ajax_search before calling search()).
     * When the incoming question was in a non-English language and got translated,
     * we want to log BOTH the original text and the detected language for admin review.
     */
    private ?string $original_question = null;
    private ?string $detected_language = null;

    /**
     * Pending tiebreak event for the current query, captured by
     * maybe_ai_tiebreak() so log_question() can persist it on the
     * questions_log row. Cleared after each search() call.
     *
     * Shape: ['chosen_id' => int, 'tied_ids' => int[], 'provider' => string]
     *
     * @since 4.37.50
     */
    private ?array $pending_tiebreak = null;

    /**
     * Set the original (pre-translation) question and detected language.
     * Called by PublicFacing::ajax_search when multilingual is enabled.
     */
    public function set_multilingual_context(?string $original, ?string $lang): void {
        $this->original_question = $original;
        $this->detected_language = $lang;
    }
    
    /**
     * Built-in common synonyms dictionary.
     *
     * v4.37.14+: heavily trimmed from the original list. The
     * removed entries (originally tagged "Common business terms"
     * and "Education/Academic") routinely conflated distinct KB
     * concepts:
     *   - `homework -> coursework` made "military coursework" match
     *     homework entries
     *   - `cost -> fee` made queries about costs match fee entries
     *     (and vice-versa) when both are typically distinct KB
     *     keywords with different responses
     *   - `register -> enroll` and `enroll -> register` directly
     *     contradicted each other and conflated separate workflows
     *   - `transcript -> grades`, `tuition -> fees`,
     *     `dormitory -> housing`, `financial aid -> scholarship`,
     *     etc. all conflated separate KB entries
     *
     * Removing them puts the responsibility for site-specific
     * synonyms on the admin (via the user-editable Synonyms table),
     * where decisions can be made with awareness of the actual KB
     * keywords. The remaining entries are pure verb/adjective
     * synonyms that don't conflate concepts in any reasonable KB.
     *
     * Format: canonical_word => [variants]
     */
    private array $builtin_synonyms = [
        // Verbs — pure synonyms, no concept conflation
        'start'       => ['begin', 'initiate', 'commence', 'launch'],
        'stop'        => ['end', 'finish', 'terminate', 'quit', 'discontinue'],
        'find'        => ['locate', 'search', 'discover'],
        'fix'         => ['repair', 'resolve', 'solve', 'correct', 'troubleshoot'],
        'change'      => ['modify', 'alter', 'revise', 'adjust'],
        'remove'      => ['delete', 'eliminate', 'erase'],
        'show'        => ['display', 'reveal'],
        'hide'        => ['conceal', 'mask'],

        // Adjectives — pure synonyms
        'quick'       => ['fast', 'rapid', 'speedy'],
        'slow'        => ['sluggish', 'lagging'],
        'big'         => ['large', 'huge', 'massive', 'enormous'],
        'small'       => ['little', 'tiny', 'mini', 'compact'],
        'many'        => ['multiple', 'numerous', 'several', 'various'],
        'good'        => ['great', 'excellent', 'fine', 'positive'],
        'bad'         => ['poor', 'terrible', 'negative'],
        'new'         => ['latest', 'recent', 'modern', 'fresh'],
        'old'         => ['previous', 'former', 'outdated', 'past'],

        // Generic descriptive — safe across domains
        'available'   => ['accessible', 'obtainable', 'offered'],
        'issue'       => ['problem', 'trouble', 'difficulty'],
        'broken'      => ['damaged', 'defective', 'malfunctioning'],
        'password'    => ['passcode', 'pin', 'passphrase'],
    ];
    
    /**
     * Common keyboard typo patterns (transposed/adjacent keys)
     */
    private array $keyboard_adjacents = [
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
        's' => ['a', 'w', 'e', 'd', 'z', 'x'],
        'd' => ['s', 'e', 'r', 'f', 'x', 'c'],
        'f' => ['d', 'r', 't', 'g', 'c', 'v'],
        'g' => ['f', 't', 'y', 'h', 'v', 'b'],
        'h' => ['g', 'y', 'u', 'j', 'b', 'n'],
        'j' => ['h', 'u', 'i', 'k', 'n', 'm'],
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
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->db = new Database();
        $this->logger = Logger::instance();
        
        try {
            $this->load_stopwords();
        } catch (\Exception $e) {
            $this->logger->error('Failed to load stopwords', ['error' => $e->getMessage()]);
            $this->stopwords = [];
        }
        
        try {
            $this->load_synonyms();
        } catch (\Exception $e) {
            $this->logger->error('Failed to load synonyms', ['error' => $e->getMessage()]);
            $this->synonyms = [];
        }
    }
    
    /**
     * Load stopwords into memory
     */
    private function load_stopwords(): void {
        $cached = wp_cache_get('stopwords', 'cleversay_search');
        if ($cached !== false) {
            $this->stopwords = $cached;
            return;
        }

        $results = $this->wpdb->get_results(
            "SELECT word FROM {$this->db->stopwords} WHERE is_active = 1",
            ARRAY_A
        );

        $this->stopwords = is_array($results) ? array_column($results, 'word') : [];
        wp_cache_set('stopwords', $this->stopwords, 'cleversay_search', 600);
    }
    
    /**
     * Load synonyms into memory
     */
    private function load_synonyms(): void {
        $cached = wp_cache_get('synonyms', 'cleversay_search');
        if ($cached !== false) {
            $this->synonyms = $cached;
            return;
        }

        $results = $this->wpdb->get_results(
            "SELECT canonical_word, variant_words, misspellings, is_phrase 
             FROM {$this->db->synonyms} 
             WHERE is_active = 1",
            ARRAY_A
        );

        $this->synonyms = is_array($results) ? $results : [];
        wp_cache_set('synonyms', $this->synonyms, 'cleversay_search', 600);
    }
    
    /**
     * Main search function
     * 
     * @param string $question The user's question
     * @return array Search results with matches
     */
    public function search(string $question): array {
        $original_question = $question;
        $debug_info = [];

        /**
         * Filters the raw question string before any processing begins.
         * @param string $original_question The user's raw question.
         */
        $question = (string) apply_filters('cleversay_before_search', $original_question);

        // Run the shared query pipeline (normalise → tokenise → stem → spell-correct …)
        $pipeline = $this->process_query($question);
        $words    = $pipeline['words'];
        $question = $pipeline['question']; // may have been AI-normalised

        $debug_info = $pipeline['debug_info'];

        $this->logger->info('Search started', [
            'original'    => $original_question,
            'final_words' => implode(',', $words),
        ]);
        
        // Step 8: Search for matches
        $results = $this->find_matches($words, $original_question);

        // v4.37.41+: AI tiebreak for high-score ties.
        //
        // When the top 2+ matches tie at a score >= TIEBREAK_MIN_SCORE
        // (currently 120 — strong matches with 2+ AND-combined tokens
        // each), invoke the LLM to read the user's question and pick
        // the better fit. Reorders the results with the AI's choice
        // first; downstream logic (related questions, response
        // selection) sees the AI-tiebroken ordering.
        //
        // The threshold matters: low-score ties (e.g., two patterns
        // both barely matching at 100) aren't really competing — they're
        // both wrong, and AI would just be picking the lesser-bad
        // option. Above 120, both candidates are legitimate matches
        // and AI judgment helps disambiguate.
        //
        // Bypassed when:
        //   - Fewer than 2 matches (no tie possible)
        //   - Top score below threshold
        //   - Top 2 not actually tied
        //   - AI unconfigured / over-budget / errors out
        //
        // Cached by (question, sorted-tied-ids) hash for 24h. Same
        // user query against the same tie returns the same tiebroken
        // result without re-asking the LLM.
        if (!empty($results['matches'])) {
            $results['matches'] = $this->maybe_ai_tiebreak(
                $original_question,
                $results['matches']
            );
        }

        $this->logger->info('Search completed', [
            'matches' => count($results['matches'])
        ]);
        
        // Step 9: Get related questions if we have a match
        $related = [];
        if (!empty($results['matches'])) {
            $exclude_id = (int)($results['matches'][0]['id'] ?? 0);
            $related = $this->get_related_questions($words, $exclude_id);
            $this->logger->debug('Related questions found', ['count' => count($related)]);
        }
        
        // Step 10: Log the question
        $logged_question_id = null;
        if (!empty($results['matches'])) {
            $logged_question_id = $this->log_question($original_question, $results['matches'][0] ?? null);
        } else {
            $logged_question_id = $this->log_question($original_question, null);
        }
        
        $return = [
            'success'  => !empty($results['matches']),
            'matches'  => $results['matches'],
            'related'  => $related,
            'suggested' => $results['suggested'] ?? [],
            'debug'    => $this->debug ? $debug_info : [],
            'logged_question_id' => $logged_question_id,
        ];

        /**
         * Filters the complete search results array.
         * @param array  $return            The results: success, matches, related, suggested.
         * @param string $original_question The user's original question.
         */
        return (array) apply_filters('cleversay_search_results', $return, $original_question);
    }
    
    /**
     * AJAX handler for search
     */
    public function ajax_search(): void {
        // Verify nonce
        if (!check_ajax_referer('cleversay_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'cleversay')], 403);
        }
        
        $question = sanitize_textarea_field($_POST['question'] ?? '');
        
        if (empty($question) || strlen($question) < 2) {
            wp_send_json_error([
                'message' => __('Please enter a valid question', 'cleversay')
            ], 400);
        }
        
        $results = $this->search($question);
        
        // Format response
        if ($results['success']) {
            $response = [
                'found' => true,
                'answers' => array_map(function($match) {
                    return [
                        'id' => (int)$match['id'],
                        'question' => $match['question'],
                        'answer' => $match['response'],
                        'score' => (int)$match['score'],
                        'show_rating' => (bool)$match['show_rating'],
                    ];
                }, $results['matches']),
            ];
        } else {
            $response = [
                'found' => false,
                'message' => get_option('cleversay_no_answer_message'),
                'suggestions' => $results['suggested'],
                'show_inquiry' => get_option('cleversay_enable_inquiry_form', true),
            ];
        }
        
        wp_send_json_success($response);
    }
    
    /**
     * Normalize question text
     */

    /**
     * Use Claude to fix severe typos, keyboard mashing, and garbled queries.
     * Returns the cleaned query string, or null if AI is not configured/enabled
     * or if the query looks clean enough that AI isn't needed.
     *
     * Uses the cheapest/fastest model (Haiku) with a tiny prompt.
     * Adds ~200-400ms latency when it fires, so we only call it when the query
     * has clear signals of significant errors.
     */
    // =========================================================================
    // Shared query processing pipeline
    // =========================================================================

    /**
     * Run the full text-processing pipeline on a raw question.
     *
     * Single source of truth used by both search() (live) and do_test_search()
     * (admin test tool). Any pipeline change only needs to happen here.
     *
     * @param  string $raw   Raw question string
     * @param  bool   $debug When true, populate 'steps' for the test tool display
     * @return array {
     *   'question'   => string — after AI normalisation & text normalisation
     *   'words'      => array  — final processed search words
     *   'debug_info' => array  — key/value pairs for internal logging
     *   'steps'      => array  — process steps for admin test tool (debug=true only)
     * }
     */
    /**
     * Apply the runtime's normalization pipeline to a piece of text and
     * return the post-pipeline tokens. This is what the KB pattern
     * compiler uses so the words it picks discriminators from are
     * exactly the words a live query would carry — no drift between
     * compile time and match time.
     *
     * Subset of process_query():
     *   - normalize_question (lowercase, contractions, punctuation)
     *   - apply_builtin_phrase_synonyms (multi-word -> canonical)
     *   - apply_synonyms (DB synonyms, phrase mode)
     *   - tokenize
     *   - remove_stopwords
     *   - apply_builtin_synonyms (single-word DB synonyms)
     *   - apply_stemming
     *
     * Skipped on purpose:
     *   - AI normalization (admin variations are deliberate; we don't
     *     want the AI rewriting them at compile time)
     *   - Spell correction (admin variations don't have typos, and
     *     auto-correcting them would make compilation feel
     *     unpredictable)
     *   - Repeated-character collapse (typo-y; doesn't apply to admin
     *     authoring)
     *
     * @param string $text Admin-authored variation.
     * @return string[] Stemmed, stopword-stripped tokens, in order.
     *
     * @since 4.37.13
     */
    public function compile_normalize(string $text): array {
        $text = $this->normalize_question($text);
        $text = $this->apply_builtin_phrase_synonyms($text);
        $text = $this->apply_synonyms($text);

        $words = $this->tokenize($text);
        $words = $this->remove_stopwords($words);
        $words = $this->apply_builtin_synonyms($words);
        $words = $this->apply_stemming($words);

        return array_values($words);
    }

    private function process_query(string $raw, bool $debug = false): array {
        $steps      = [];
        $debug_info = [];
        $question   = $raw;

        // ── Step 0b: AI Query Normalization ───────────────────────────────────
        $ai_norm_enabled    = (bool) get_option('cleversay_ai_normalize_queries', false);
        $ai_norm_obj        = new AI();
        $ai_norm_configured = $ai_norm_obj->is_configured();
        $ai_norm_clean      = $this->query_looks_clean($question);

        if ($ai_norm_enabled && $ai_norm_configured && !$ai_norm_clean) {
            $ai_result = null;
            try { $ai_result = $ai_norm_obj->normalize_query($question); } catch (\Throwable $e) {}
            $changed = ($ai_result !== null && $ai_result !== $question);
            if ($changed) {
                $this->logger->info('AI query normalization applied', ['original' => $question, 'normalized' => $ai_result]);
                $question = $ai_result;
            }
            if ($debug) {
                $steps[] = [
                    'step'         => '0b',
                    'description'  => __('AI Query Normalization', 'cleversay'),
                    'result'       => $changed
                        ? sprintf(__('Corrected to: "%s"', 'cleversay'), $ai_result)
                        : __('AI returned same query — already understood correctly', 'cleversay'),
                    'replacements' => $changed ? [['from' => $raw, 'to' => $ai_result, 'type' => 'AI corrected']] : [],
                ];
            }
        } elseif ($debug) {
            $skip = !$ai_norm_enabled
                ? __('Disabled — enable under Settings → AI Settings → AI Query Normalization', 'cleversay')
                : (!$ai_norm_configured
                    ? __('AI not configured (check API key)', 'cleversay')
                    : __('Query looks clean — no API call needed', 'cleversay'));
            $steps[] = ['step' => '0b', 'description' => __('AI Query Normalization', 'cleversay'), 'result' => $skip];
        }

        // ── Step 1: Original question ─────────────────────────────────────────
        if ($debug) {
            $steps[] = ['step' => 1, 'description' => __('Original question', 'cleversay'), 'result' => $raw];
        }

        // ── Step 2: Normalise ─────────────────────────────────────────────────
        $question = $this->normalize_question($question);
        $debug_info['normalized'] = $question;
        if ($debug) {
            $steps[] = ['step' => 2, 'description' => __('Normalized', 'cleversay'), 'result' => $question];
        }

        // ── Step 3: Built-in phrase synonyms ──────────────────────────────────
        if ($debug) {
            $phrase_replacements = [];
            $question = $this->apply_builtin_phrase_synonyms_with_tracking($question, $phrase_replacements);
            if (!empty($phrase_replacements)) {
                $steps[] = ['step' => count($steps) + 1, 'description' => __('Built-in phrase synonyms', 'cleversay'), 'result' => $question, 'replacements' => $phrase_replacements];
            }
        } else {
            $question = $this->apply_builtin_phrase_synonyms($question);
        }
        $debug_info['after_builtin_phrases'] = $question;

        // ── Step 4: User-defined synonym replacements ─────────────────────────
        if ($debug) {
            $syn_replacements = [];
            $question = $this->apply_synonyms_with_tracking($question, $syn_replacements);
            if (!empty($syn_replacements)) {
                $steps[] = ['step' => count($steps) + 1, 'description' => __('User-defined synonym replacements', 'cleversay'), 'result' => $question, 'replacements' => $syn_replacements];
            }
        } else {
            $question = $this->apply_synonyms($question);
        }
        $debug_info['after_synonyms'] = $question;

        // ── Step 5: Tokenize ──────────────────────────────────────────────────
        $words = $this->tokenize($question);
        $debug_info['tokens'] = $words;
        if ($debug) {
            $steps[] = ['step' => count($steps) + 1, 'description' => __('Tokenized words', 'cleversay'), 'result' => implode(', ', $words)];
        }

        // ── Step 6: Remove stopwords ──────────────────────────────────────────
        $words_before_sw = $words;
        $words = $this->remove_stopwords($words);
        $debug_info['after_stopwords'] = $words;
        if ($debug) {
            $removed = array_values(array_diff($words_before_sw, $words));
            if (!empty($removed)) {
                $steps[] = ['step' => count($steps) + 1, 'description' => __('Removed stopwords', 'cleversay'), 'result' => implode(', ', $removed)];
            }
            $steps[] = [
                'step'        => count($steps) + 1,
                'description' => __('Words after stopword removal', 'cleversay'),
                'result'      => !empty($words) ? implode(', ', $words) : __('(none - all words were stopwords)', 'cleversay'),
            ];
        }

        // ── Step 7: Built-in word synonyms ────────────────────────────────────
        if ($debug) {
            $word_syn = [];
            $words = $this->apply_builtin_synonyms_with_tracking($words, $word_syn);
            if (!empty($word_syn)) {
                $steps[] = ['step' => count($steps) + 1, 'description' => __('Built-in word synonyms', 'cleversay'), 'result' => implode(', ', $words), 'replacements' => $word_syn];
            }
        } else {
            $words = $this->apply_builtin_synonyms($words);
        }
        $debug_info['after_builtin_synonyms'] = $words;

        // ── Step 8: Stemming ──────────────────────────────────────────────────
        $words_before_stem = $words;
        $words = $this->apply_stemming($words);
        $debug_info['after_stemming'] = $words;
        if ($debug) {
            $stem_changes = [];
            foreach ($words_before_stem as $i => $before) {
                if (isset($words[$i]) && $before !== $words[$i]) {
                    $stem_changes[] = ['from' => $before, 'to' => $words[$i], 'type' => 'stemming'];
                }
            }
            if (!empty($stem_changes)) {
                $steps[] = ['step' => count($steps) + 1, 'description' => __('Stemming applied', 'cleversay'), 'result' => implode(', ', $words), 'replacements' => $stem_changes];
            }
        }

        // ── Step 8b: Collapse repeated characters ─────────────────────────────
        $words_before_collapse = $words;
        $words = $this->collapse_repeated_chars($words);
        if ($debug) {
            $collapse_changes = [];
            foreach ($words_before_collapse as $i => $before) {
                if (isset($words[$i]) && $before !== $words[$i]) {
                    $collapse_changes[] = ['from' => $before, 'to' => $words[$i], 'type' => 'repeated chars collapsed'];
                }
            }
            if (!empty($collapse_changes)) {
                $steps[] = ['step' => count($steps) + 1, 'description' => __('Repeated characters collapsed', 'cleversay'), 'result' => implode(', ', $words), 'replacements' => $collapse_changes];
            }
        }

        // ── Step 9: Spell correction ──────────────────────────────────────────
        $words_before_spell = $words;
        $words = $this->apply_spell_correction($words);
        $debug_info['after_spellcheck'] = $words;
        if ($debug) {
            $spell_changes = [];
            foreach ($words_before_spell as $i => $before) {
                if (isset($words[$i]) && $before !== $words[$i]) {
                    $spell_changes[] = ['from' => $before, 'to' => $words[$i], 'type' => 'typo correction'];
                }
            }
            if (!empty($spell_changes)) {
                $steps[] = ['step' => count($steps) + 1, 'description' => __('Typo corrections', 'cleversay'), 'result' => implode(', ', $words), 'replacements' => $spell_changes];
            }
            $steps[] = [
                'step'        => count($steps) + 1,
                'description' => __('Final search keywords', 'cleversay'),
                'result'      => !empty($words)
                    ? implode(', ', array_map(fn($w) => '__' . $w . '__', $words))
                    : __('(no keywords to search)', 'cleversay'),
            ];
        }

        return ['question' => $question, 'words' => $words, 'debug_info' => $debug_info, 'steps' => $steps];
    }

    private function ai_normalize_query(string $question): ?string {
        // Only run if the AI normalization setting is on
        if (!get_option('cleversay_ai_normalize_queries', false)) {
            return null;
        }

        // Quick heuristic: skip if the query looks clean
        // (avoids API calls for well-formed questions)
        if ($this->query_looks_clean($question)) {
            return null;
        }

        $ai = new AI();
        if (!$ai->is_configured()) {
            return null;
        }

        try {
            $result = $ai->normalize_query($question);
            if (!empty($result) && is_string($result)) {
                $cleaned = trim($result);
                // Sanity check: don't accept if AI hallucinated a completely different sentence
                // (longer than 3x the original is suspicious)
                if (strlen($cleaned) <= strlen($question) * 3 && strlen($cleaned) >= 2) {
                    return $cleaned;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AI query normalization failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Quick check: does the query look like it needs AI correction?
     * We only pay for an API call when there are clear signals of garbled input.
     */
    private function query_looks_clean(string $question): bool {
        $lower = strtolower($question);

        // Signal 1: any word with 3+ consecutive identical characters (booooks, hellllo)
        // Pure PHP loop avoids regex backreference escaping issues
        for ($i = 0, $len = strlen($lower) - 2; $i < $len; $i++) {
            if ($lower[$i] === $lower[$i + 1] && $lower[$i] === $lower[$i + 2]) {
                return false; // found 3+ identical consecutive characters
            }
        }

        // Signal 2: any word that's mostly consonants and > 4 chars (likely garbled: "whee", "mkae")
        $words = preg_split('/\s+/', $lower, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $word) {
            $word = preg_replace('/[^a-z]/', '', $word);
            if (strlen($word) >= 4) {
                $vowels = preg_match_all('/[aeiou]/', $word);
                $ratio  = $vowels / strlen($word);
                // Less than 15% vowels in a 5+ char word is suspicious
                if ($ratio < 0.15 && strlen($word) >= 5) {
                    return false;
                }
            }
        }

        return true; // Looks fine — no API call needed
    }

    private function normalize_question(string $question): string {
        // FIRST: Remove backslash escapes (WordPress magic quotes)
        // This converts don\'t back to don't
        $question = stripslashes($question);
        
        // Convert to lowercase
        $question = mb_strtolower($question, 'UTF-8');
        
        // Expand common contractions BEFORE removing special characters
        // Only include contractions with apostrophes to avoid false positives
        // (e.g., "ill" = sick, "id" = identification, "well" = adverb)
        $contractions = [
            "don't" => "do not",
            "won't" => "will not",
            "can't" => "cannot",
            "couldn't" => "could not",
            "wouldn't" => "would not",
            "shouldn't" => "should not",
            "didn't" => "did not",
            "doesn't" => "does not",
            "isn't" => "is not",
            "aren't" => "are not",
            "wasn't" => "was not",
            "weren't" => "were not",
            "hasn't" => "has not",
            "haven't" => "have not",
            "hadn't" => "had not",
            "i'm" => "i am",
            "you're" => "you are",
            "we're" => "we are",
            "they're" => "they are",
            "it's" => "it is",
            "that's" => "that is",
            "what's" => "what is",
            "where's" => "where is",
            "who's" => "who is",
            "how's" => "how is",
            "let's" => "let us",
            "i've" => "i have",
            "you've" => "you have",
            "we've" => "we have",
            "they've" => "they have",
            "i'll" => "i will",
            "you'll" => "you will",
            "we'll" => "we will",
            "they'll" => "they will",
            "i'd" => "i would",
            "you'd" => "you would",
            "we'd" => "we would",
            "they'd" => "they would",
            // Also handle without apostrophe for common ones that are unambiguous
            "dont" => "do not",
            "wont" => "will not",
            "cant" => "cannot",
            "couldnt" => "could not",
            "wouldnt" => "would not",
            "shouldnt" => "should not",
            "didnt" => "did not",
            "doesnt" => "does not",
            "isnt" => "is not",
            "arent" => "are not",
            "wasnt" => "was not",
            "werent" => "were not",
            "hasnt" => "has not",
            "havent" => "have not",
            "hadnt" => "had not",
            "youre" => "you are",
            "theyre" => "they are",
            "thats" => "that is",
            "whats" => "what is",
            "wheres" => "where is",
            "whos" => "who is",
            "hows" => "how is",
            "youve" => "you have",
            "weve" => "we have",
            "theyve" => "they have",
            "youll" => "you will",
            "theyll" => "they will",
            "youd" => "you would",
            "theyd" => "they would",
        ];
        
        // Apply contractions (with word boundaries to avoid partial matches)
        foreach ($contractions as $contraction => $expansion) {
            // Use word boundaries to avoid matching parts of other words
            $pattern = '/\b' . preg_quote($contraction, '/') . '\b/i';
            $question = preg_replace($pattern, $expansion, $question);
        }
        
        // Remove special characters except basic punctuation
        $question = preg_replace('/[^\p{L}\p{N}\s\'-]/u', ' ', $question);
        
        // Normalize whitespace
        $question = preg_replace('/\s+/', ' ', $question);
        
        return trim($question);
    }
    
    /**
     * Apply synonym replacements
     */
    private function apply_synonyms(string $question): string {
        if (empty($this->synonyms)) {
            return $question;
        }
        
        // First apply phrase-level synonyms (multi-word)
        foreach ($this->synonyms as $syn) {
            if ((int)($syn['is_phrase'] ?? 0) === 1) {
                $variants = array_filter(array_map('trim', explode(',', $syn['variant_words'] ?? '')));
                $misspellings = array_filter(array_map('trim', explode(',', $syn['misspellings'] ?? '')));
                $all_variants = array_merge($variants, $misspellings);
                
                foreach ($all_variants as $variant) {
                    if (!empty($variant) && stripos($question, $variant) !== false) {
                        // Handle wildcard patterns
                        if (strpos($variant, '*') !== false) {
                            $pattern = $this->wildcard_to_regex($variant);
                            $question = preg_replace($pattern, $syn['canonical_word'], $question);
                        } else {
                            $question = str_ireplace($variant, $syn['canonical_word'], $question);
                        }
                    }
                }
            }
        }
        
        return $question;
    }
    
    /**
     * Apply built-in synonym dictionary to words
     * This provides automatic synonym matching without admin configuration
     * 
     * IMPORTANT: Only applies synonyms if the word is NOT already a keyword in the database
     */
    private function apply_builtin_synonyms(array $words): array {
        $result = [];
        
        // Get all keywords from database to check against
        $db_keywords = $this->get_database_keywords();
        
        foreach ($words as $word) {
            $word_lower = strtolower($word);
            
            // FIRST: Check if this word already exists as a keyword in the database
            // If it does, keep it as-is - don't replace with a synonym
            if ($this->word_matches_database_keyword($word_lower, $db_keywords)) {
                $this->logger->debug('Word exists as keyword, skipping synonym replacement', [
                    'word' => $word
                ]);
                $result[] = $word;
                continue;
            }
            
            $matched = false;
            
            // Check if this word is a variant of any canonical word
            foreach ($this->builtin_synonyms as $canonical => $variants) {
                if ($word_lower === $canonical) {
                    // Already canonical, keep it
                    $result[] = $word;
                    $matched = true;
                    break;
                }
                
                foreach ($variants as $variant) {
                    // Skip multi-word variants (handled at phrase level)
                    if (strpos($variant, ' ') !== false) {
                        continue;
                    }
                    
                    if (strtolower($variant) === $word_lower) {
                        $this->logger->debug('Built-in synonym match', [
                            'original' => $word,
                            'canonical' => $canonical
                        ]);
                        $result[] = $canonical;
                        $matched = true;
                        break 2;
                    }
                }
            }
            
            if (!$matched) {
                $result[] = $word;
            }
        }
        
        return $result;
    }
    
    /**
     * Get all keywords from database (cached)
     */
    private function get_database_keywords(): array {
        static $runtime_cache = null;

        if ($runtime_cache !== null) {
            return $runtime_cache;
        }

        // Try object cache first (shared across requests on same server, fast)
        $cached = wp_cache_get('db_keywords', 'cleversay_search');
        if ($cached !== false) {
            $runtime_cache = $cached;
            return $runtime_cache;
        }

        $results = $this->wpdb->get_results(
            "SELECT DISTINCT keyword, sub_keyword FROM {$this->db->knowledge_base} WHERE status = 'active'",
            ARRAY_A
        );

        $runtime_cache = [];
        foreach ((array) $results as $row) {
            foreach (['keyword', 'sub_keyword'] as $field) {
                if (!empty($row[$field])) {
                    $words = preg_split('/[\s,+&]+/', strtolower($row[$field]));
                    foreach ($words as $w) {
                        $w = trim($w);
                        if (strlen($w) >= 2 && !in_array($w, $runtime_cache)) {
                            $runtime_cache[] = $w;
                        }
                    }
                }
            }
        }

        // Store in object cache for 5 minutes
        wp_cache_set('db_keywords', $runtime_cache, 'cleversay_search', 300);

        $this->logger->debug('Database keywords loaded', ['count' => count($runtime_cache)]);
        return $runtime_cache;
    }
    
    /**
     * Check if a word matches any keyword in the database
     */
    private function word_matches_database_keyword(string $word, array $db_keywords): bool {
        // Exact match
        if (in_array($word, $db_keywords)) {
            return true;
        }
        
        // Check if word is contained in any keyword or vice versa
        foreach ($db_keywords as $keyword) {
            // Word is a significant part of a keyword (at least 4 chars match)
            if (strlen($word) >= 4 && strlen($keyword) >= 4) {
                // Only allow prefix match if the word covers at least 80% of the keyword
                // This prevents "attend" (6) matching "attendance" (10): 6/10 = 60% — blocked
                // But allows "refund" (6) matching "refunds" (7): 6/7 = 86% — allowed
                if (strpos($keyword, $word) === 0) {
                    $coverage = strlen($word) / strlen($keyword);
                    if ($coverage >= 0.80) {
                        return true;
                    }
                }
                // Keyword is a prefix of the word (e.g. "enroll" matches "enrollment")
                if (strpos($word, $keyword) === 0) {
                    $coverage = strlen($keyword) / strlen($word);
                    if ($coverage >= 0.80) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Apply built-in synonyms to phrase (for multi-word synonyms)
     */
    private function apply_builtin_phrase_synonyms(string $question): string {
        foreach ($this->builtin_synonyms as $canonical => $variants) {
            foreach ($variants as $variant) {
                // Only process multi-word variants at phrase level
                if (strpos($variant, ' ') !== false) {
                    if (stripos($question, $variant) !== false) {
                        $question = str_ireplace($variant, $canonical, $question);
                        $this->logger->debug('Built-in phrase synonym match', [
                            'variant' => $variant,
                            'canonical' => $canonical
                        ]);
                    }
                }
            }
        }
        return $question;
    }
    
    /**
     * Convert wildcard pattern to regex
     */
    private function wildcard_to_regex(string $pattern): string {
        $pattern = preg_quote($pattern, '/');
        $pattern = str_replace('\*', '\\w*', $pattern);
        return '/\b' . $pattern . '\b/i';
    }
    
    /**
     * Tokenize question into words
     */
    private function tokenize(string $question): array {
        $words = preg_split('/\s+/', $question);
        return array_filter($words, fn($word) => strlen($word) > 0);
    }
    
    /**
     * Remove stopwords from word array
     */
    private function remove_stopwords(array $words): array {
        $filtered = array_values(array_filter($words, function($word) {
            return !in_array(strtolower($word), $this->stopwords) && strlen($word) > 1;
        }));
        
        // If ALL words were removed as stopwords, keep the longest words (3+ chars)
        if (empty($filtered) && !empty($words)) {
            $filtered = array_values(array_filter($words, fn($w) => strlen($w) >= 3));
        }
        
        return $filtered;
    }
    
    /**
     * Apply word stemming/morphology
     * Only stems words that are NOT in the common dictionary
     */
    private function apply_stemming(array $words): array {
        $stemmed = [];
        $dictionary = $this->get_common_words_dictionary();

        // v4.37.4+: WordNet morphological exception list. Loaded once
        // (the function caches in a static). Handles irregulars that
        // the rule-based suffix loop below cannot reduce — wrote->write,
        // ran->run, mice->mouse, taken->take, admitted->admit, etc.
        // Looked up first so successful hits skip the rule loop entirely.
        // Falls through to rules for regular forms. See data-irregulars.php
        // for source/license attribution.
        $irregulars = function_exists('CleverSay\\cleversay_irregulars')
            ? \CleverSay\cleversay_irregulars()
            : [];

        foreach ($words as $word) {
            $original = $word;
            $word_lower = strtolower($word);

            // Irregular-form lookup runs before the suffix loop.
            if (isset($irregulars[$word_lower])) {
                $word = $irregulars[$word_lower];
                // Fall through to synonym replacement below — skip the
                // rule-based suffix loop since we already have an
                // authoritative lemma.
            } elseif (strlen($word) > 3) {
                // Remove common suffixes
                $suffixes = ['ing', 'ed', 'er', 'est', 'ly', 'ies', 'es', 's'];

                foreach ($suffixes as $suffix) {
                    if (str_ends_with($word, $suffix)) {
                        $base = substr($word, 0, -strlen($suffix));

                        // Handle special cases
                        if ($suffix === 'ies' && strlen($base) > 1) {
                            $candidate = $base . 'y';
                        } elseif ($suffix === 'ing' && strlen($base) > 2) {
                            // Check for doubled consonant
                            if (substr($base, -1) === substr($base, -2, 1)) {
                                $candidate = substr($base, 0, -1);
                            } else {
                                $candidate = $base . 'e'; // Try with 'e'
                            }
                        } elseif ($suffix === 'ed' && strlen($base) > 2) {
                            if (substr($base, -1) === substr($base, -2, 1)) {
                                $candidate = substr($base, 0, -1);
                            } else {
                                $candidate = $base;
                            }
                        } elseif (strlen($base) > 2) {
                            $candidate = $base;
                            // For 'es' suffix also try base + 'e'
                            // e.g. "grades" → "grad" (not in dict) BUT "grade" IS
                            // e.g. "courses" → "cours" (not in dict) BUT "course" IS
                            if ($suffix === 'es' && !in_array(strtolower($candidate), $dictionary)) {
                                $with_e = $base . 'e';
                                if (in_array(strtolower($with_e), $dictionary)) {
                                    $candidate = $with_e;
                                }
                            }
                        } else {
                            $candidate = $word; // Keep original if base too short
                        }

                        // Apply stemming only when the candidate is a valid dictionary word.
                        // This handles both unknown words AND known inflections:
                        //   "grades" → "grade" ✓  (both in dictionary, shorter wins)
                        //   "classes" → "class" ✓  (both in dictionary)
                        //   "class" → no suffix match → stays "class" ✓
                        //   "clas" → not in dictionary → kept as-is by spell correction ✓
                        //
                        // v4.37.23+: also accept candidates that are
                        // a "re-" prefixed form of a known word, so
                        // "retaking" → "retake" → re + "take" ✓.
                        // This generalizes to retake, redo, rewrite,
                        // reread, reapply, recheck, etc., without
                        // hand-listing each in the curated dict.
                        $candidate_lower = strtolower($candidate);
                        $is_valid = in_array($candidate_lower, $dictionary);
                        if (!$is_valid && strlen($candidate_lower) > 3
                            && substr($candidate_lower, 0, 2) === 're') {
                            $stripped = substr($candidate_lower, 2);
                            if (in_array($stripped, $dictionary)) {
                                $is_valid = true;
                            }
                        }
                        if ($is_valid) {
                            $word = $candidate;
                        }
                        // If candidate not in dictionary, keep original word

                        break;
                    }
                }
            }
            
            // Apply word-level synonyms
            foreach ($this->synonyms as $syn) {
                if ((int)($syn['is_phrase'] ?? 0) !== 1) {
                    $variants = array_filter(array_map('trim', explode(',', $syn['variant_words'] ?? '')));
                    $misspellings = array_filter(array_map('trim', explode(',', $syn['misspellings'] ?? '')));
                    $all_variants = array_merge($variants, $misspellings);
                    
                    foreach ($all_variants as $variant) {
                        $variant = trim($variant);
                        if (empty($variant)) continue;
                        
                        // Handle wildcard patterns
                        if (strpos($variant, '*') !== false) {
                            if ($this->matches_wildcard($word, $variant)) {
                                $word = $syn['canonical_word'];
                                break 2;
                            }
                        } elseif (strtolower($word) === strtolower($variant)) {
                            $word = $syn['canonical_word'];
                            break 2;
                        }
                    }
                }
            }
            
            $stemmed[] = $word;
        }
        
        return array_unique($stemmed);
    }
    
    /**
     * Check if word matches wildcard pattern
     */
    private function matches_wildcard(string $word, string $pattern): bool {
        $pattern = strtolower($pattern);
        $word = strtolower($word);
        
        if (str_starts_with($pattern, '*')) {
            // *suffix - match ending
            $suffix = substr($pattern, 1);
            return str_ends_with($word, $suffix);
        } elseif (str_ends_with($pattern, '*')) {
            // prefix* - match beginning
            $prefix = substr($pattern, 0, -1);
            return str_starts_with($word, $prefix);
        }
        
        return false;
    }
    
    /**
     * Apply spell correction for ACTUAL typos only
     * 
     * Logic:
     * 1. If word is in our dictionary → keep it (it's a valid word)
     * 2. If word is NOT in our dictionary → try to find a close match
     * 
     * This catches typos like "colondar" → "calendar", "appointmnet" → "appointment"
     */

    /**
     * Collapse runs of 3+ identical characters to 2.
     * Handles enthusiastic typing: "booooks" → "books", "hellllo" → "hello".
     * We keep 2 (not 1) because legitimate double-letters exist: "book", "need", "tool".
     * After collapsing, normal spell correction handles the rest.
     */
    private function collapse_repeated_chars(array $words): array {
        $result = [];
        foreach ($words as $word) {
            // Replace any character repeated 3+ times with exactly 2 of it
            $collapsed = preg_replace('/(.){2,}/', '$1$1', $word);
            if ($collapsed !== $word) {
                $this->logger->debug('Collapsed repeated chars', [
                    'original'  => $word,
                    'collapsed' => $collapsed,
                ]);
            }
            $result[] = $collapsed;
        }
        return $result;
    }

    private function apply_spell_correction(array $words): array {
        $corrected = [];
        $dictionary = $this->get_common_words_dictionary();

        // v4.37.10+: WordNet 3.0 single-token dictionary (~77,500
        // words). Used as the PRIMARY validation pool for "is this a
        // real word, leave it alone." Replaces the previous
        // 600-word curated list at this step. The curated list is
        // still loaded above ($dictionary) — it's used by
        // find_typo_correction as the candidate pool for closest-
        // match suggestions, since searching all of WordNet for
        // closest-match would be both slow and noisy. So:
        //
        //   - WordNet says "social is a word" → kept as-is (the
        //     legacy bug "social → local" stops here).
        //   - WordNet says "aplly is not a word" → run typo
        //     correction against the curated list → "apply".
        //
        // The KB-derived whitelists (is_kb_keyword,
        // is_kb_pattern_token) still run for domain terms WordNet
        // doesn't know — fafsa, sevis, aoda, etc. — so those don't
        // get rewritten either.
        $wordnet = function_exists('CleverSay\\cleversay_wordnet_dictionary')
            ? \CleverSay\cleversay_wordnet_dictionary()
            : [];

        foreach ($words as $word) {
            if (strlen($word) <= 2) {
                $corrected[] = $word;
                continue;
            }

            $word_lower = strtolower($word);

            // Primary check: WordNet dictionary (v4.37.10+).
            if (isset($wordnet[$word_lower])) {
                $corrected[] = $word;
                continue;
            }

            // Fallback check: curated common-words dictionary (smaller,
            // includes domain extras + KB keywords folded in via
            // get_common_words_dictionary).
            if (in_array($word_lower, $dictionary)) {
                $corrected[] = $word;
                continue;
            }

            // If word exists in synonyms table (as canonical_word), it's explicitly whitelisted - keep it
            // This allows users to protect acronyms, proper nouns, and custom terms
            if ($this->is_synonym_canonical_word($word_lower)) {
                $this->logger->debug('Skipping spell correction for synonym canonical word', ['word' => $word]);
                $corrected[] = $word;
                continue;
            }

            // v4.37.1+: never spell-correct a word that exists as a KB
            // keyword. Reasoning: a KB keyword is a deliberate term the
            // admin chose to organize content around. If a student types
            // it, that's almost certainly the term they meant, even if
            // it isn't in our common-words dictionary. Without this
            // check, real student queries like "what is sap?" got
            // silently rewritten to "what is map?" because Levenshtein(
            // sap, map) = 1 and `map` happens to be in the common-words
            // list. Pulling from the live KB means the whitelist
            // updates automatically as admins add or remove entries —
            // no manual list maintenance needed.
            if ($this->is_kb_keyword($word_lower)) {
                $this->logger->debug('Skipping spell correction for KB keyword', ['word' => $word]);
                $corrected[] = $word;
                continue;
            }

            // v4.37.9+: also protect words that appear as content tokens
            // inside any KB sub_keyword pattern. The legacy KB has many
            // entries like `do+i+have&social` or `look+for|take&during`
            // where short content words ("social", "for", "during",
            // "during") are admin-authored matchers. The spell-corrector
            // was silently rewriting these to dictionary lookalikes —
            // e.g. "social" -> "local" (Levenshtein=2) — which broke
            // the corresponding patterns from ever matching. Like the
            // KB-keyword whitelist above, this list is derived from
            // live data and updates automatically.
            if ($this->is_kb_pattern_token($word_lower)) {
                $this->logger->debug('Skipping spell correction for KB pattern token', ['word' => $word]);
                $corrected[] = $word;
                continue;
            }

            // Check if this looks like an acronym - don't spell-correct acronyms
            // Acronyms typically have no vowels (e.g., "sql", "html", "php")
            if ($this->looks_like_acronym($word_lower)) {
                $this->logger->debug('Skipping spell correction for acronym-like word', ['word' => $word]);
                $corrected[] = $word;
                continue;
            }
            
            // v4.37.33+: morphological whitelist. Before treating a
            // word as unknown and running typo correction, check
            // whether it's an inflected form of a word the dictionary
            // DOES know. WordNet's single-token dictionary indexes
            // base forms but not all common inflections — `add` is
            // there but `adding`, `adds`, `added` are not. Without
            // this check, the typo corrector picks the closest
            // dictionary lookalike, producing semantic disasters
            // like `adding → doing` (Levenshtein=2, both words exist
            // in the curated dict, but the meanings are unrelated).
            //
            // The check strips common English inflectional suffixes
            // and looks up the base in the same WordNet/curated
            // pools we already use. If the base is known, the
            // inflected form is treated as known too — the user
            // typed a real word, leave it alone.
            //
            // Order chosen to avoid false strips: -ing, -ed, -ies,
            // -es, -s. Each strip is applied only if the result is
            // long enough to be a plausible base (>= 3 chars).
            if ($this->is_inflection_of_known_word($word_lower, $wordnet, $dictionary)) {
                $corrected[] = $word;
                continue;
            }

            // Word is NOT in dictionary - try to find a correction
            $correction = $this->find_typo_correction($word);
            
            if ($correction !== null) {
                $this->logger->debug('Typo correction applied', [
                    'original' => $word,
                    'corrected' => $correction['word'],
                    'method' => $correction['method']
                ]);
                $corrected[] = $correction['word'];
            } else {
                // Can't find a correction, keep original
                $corrected[] = $word;
            }
        }
        
        return $corrected;
    }
    
    /**
     * Check if a word is a canonical_word in the synonyms table
     * 
     * This allows users to explicitly whitelist words (acronyms, proper nouns, etc.)
     * by adding them to the synonyms table with themselves as the canonical word.
     * 
     * @param string $word Lowercase word to check
     * @return bool True if word is a canonical_word in synonyms table
     */
    private function is_synonym_canonical_word(string $word): bool {
        if (empty($this->synonyms)) {
            return false;
        }
        
        foreach ($this->synonyms as $syn) {
            $canonical = strtolower(trim($syn['canonical_word'] ?? ''));
            if ($canonical === $word) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Is this word a `keyword` value in cleversay_knowledge?
     *
     * Used as a spell-correction whitelist: KB keywords are admin-
     * chosen organizing terms and shouldn't be silently rewritten as
     * "typos" of common-dictionary words. Reads from a small static
     * cache of just the keyword column (NOT sub_keyword tokens —
     * including those would whitelist far too much, e.g. every word
     * inside every multi-word pattern).
     *
     * @param string $word Lowercased single word.
     */
    private function is_kb_keyword(string $word): bool {
        static $kb_keywords = null;

        if ($kb_keywords === null) {
            $cached = wp_cache_get('kb_keywords_only', 'cleversay_search');
            if ($cached !== false) {
                $kb_keywords = $cached;
            } else {
                $rows = $this->wpdb->get_col(
                    "SELECT DISTINCT keyword
                       FROM {$this->db->knowledge_base}
                      WHERE status = 'active'"
                );
                $kb_keywords = [];
                foreach ((array) $rows as $kw) {
                    $kw = strtolower(trim((string) $kw));
                    if ($kw !== '') $kb_keywords[$kw] = true;
                }
                // 5-min TTL same as get_database_keywords.
                wp_cache_set('kb_keywords_only', $kb_keywords, 'cleversay_search', 300);
            }
        }

        return isset($kb_keywords[$word]);
    }

    /**
     * Is this word a content token inside any active KB sub_keyword
     * pattern? Used as a spell-correction whitelist: admin-authored
     * pattern fragments shouldn't be rewritten to dictionary
     * lookalikes (e.g., "social" -> "local"). Distinct from
     * is_kb_keyword (which only protects the `keyword` column) —
     * this also covers the `sub_keyword` column.
     *
     * Pattern syntax tokens (`*`, `+`, `&`, `|`) are stripped so we
     * collect the bare words used. Tokens shorter than 3 chars are
     * dropped (single-letter glue like "i", "a" would over-broaden).
     *
     * Cached the same way as is_kb_keyword.
     */
    private function is_kb_pattern_token(string $word): bool {
        static $kb_pattern_tokens = null;

        if ($kb_pattern_tokens === null) {
            $cached = wp_cache_get('kb_pattern_tokens', 'cleversay_search');
            if ($cached !== false) {
                $kb_pattern_tokens = $cached;
            } else {
                $rows = $this->wpdb->get_col(
                    "SELECT DISTINCT sub_keyword
                       FROM {$this->db->knowledge_base}
                      WHERE status = 'active'
                        AND sub_keyword IS NOT NULL
                        AND sub_keyword != ''
                        AND sub_keyword != 'aadefault'"
                );
                $kb_pattern_tokens = [];
                foreach ((array) $rows as $sk) {
                    $sk = strtolower((string) $sk);
                    // Split on the pattern operators and strip wildcards.
                    $tokens = preg_split('/[|&+*]/', $sk);
                    foreach ((array) $tokens as $t) {
                        $t = trim($t);
                        if ($t === '' || strlen($t) < 3) continue;
                        $kb_pattern_tokens[$t] = true;
                    }
                }
                wp_cache_set('kb_pattern_tokens', $kb_pattern_tokens, 'cleversay_search', 300);
            }
        }

        return isset($kb_pattern_tokens[$word]);
    }
    
    /**
     * Check if a word looks like an acronym or abbreviation
     * 
     * Acronyms typically have no vowels (uwsp, sql, html, css, php, ftp)
     * We're conservative - only skip spell correction for words with NO vowels
     * 
     * @param string $word Lowercase word to check
     * @return bool True if word looks like an acronym
     */
    private function looks_like_acronym(string $word): bool {
        // Remove any numbers for vowel check
        $letters_only = preg_replace('/[0-9]/', '', $word);
        
        if (empty($letters_only)) {
            return true; // All numbers, treat as code/identifier
        }
        
        // Check if word has any vowels
        // Only flag as acronym if there are NO vowels at all
        $has_vowel = preg_match('/[aeiou]/i', $letters_only);
        
        return !$has_vowel;
    }

    /**
     * Is this word an inflected form of something the dictionary knows?
     *
     * Strips common English inflectional suffixes and checks the base
     * against the WordNet dictionary and curated common-words list.
     * If the base is known, the inflected form is treated as known
     * too — the user typed a real word, no correction needed.
     *
     * Suffixes handled (longest-first to avoid false strips):
     *   - "ing"   → add → adding         (drop ing)
     *   - "ying"  → study → studying     (handle y → ie not needed; study + ing)
     *   - "ied"   → studied → study       (ied → y)
     *   - "ies"   → studies → study       (ies → y)
     *   - "es"    → boxes → box           (drop es)
     *   - "ed"    → added → add           (drop ed)
     *   - "s"     → adds → add            (drop trailing s)
     *
     * Also handles consonant-doubling: "adding" → "add" (double-d
     * before -ing). Each rule has a minimum base length to avoid
     * matching short non-inflections (e.g. "as" stripped to "a").
     *
     * @since 4.37.33
     */
    private function is_inflection_of_known_word(string $word, array $wordnet, array $dictionary): bool {
        $candidates = [];

        // -ing
        if (str_ends_with($word, 'ing') && strlen($word) >= 5) {
            $stem = substr($word, 0, -3);
            $candidates[] = $stem;                  // adding → add (consonant doubled)
            // Try undoubling the last consonant: "adding" → "add"
            // is captured above; "running" → "run" needs strip-doubled.
            if (strlen($stem) >= 4 && $stem[strlen($stem) - 1] === $stem[strlen($stem) - 2]) {
                $candidates[] = substr($stem, 0, -1); // running → run
            }
            // Try restoring dropped 'e': "making" → "make"
            $candidates[] = $stem . 'e';            // making → make, baking → bake
            // y-form: "studying" → "study" (already handled because stem === 'study')
        }

        // -ied → -y
        if (str_ends_with($word, 'ied') && strlen($word) >= 5) {
            $candidates[] = substr($word, 0, -3) . 'y'; // studied → study
        }

        // -ies → -y
        if (str_ends_with($word, 'ies') && strlen($word) >= 5) {
            $candidates[] = substr($word, 0, -3) . 'y'; // studies → study
        }

        // -es
        if (str_ends_with($word, 'es') && strlen($word) >= 4) {
            $candidates[] = substr($word, 0, -2); // boxes → box, fixes → fix
        }

        // -ed
        if (str_ends_with($word, 'ed') && strlen($word) >= 4) {
            $stem = substr($word, 0, -2);
            $candidates[] = $stem;                       // added → add (e is silent here)
            // Doubled-consonant: "stopped" → "stop"
            if (strlen($stem) >= 3 && $stem[strlen($stem) - 1] === $stem[strlen($stem) - 2]) {
                $candidates[] = substr($stem, 0, -1);
            }
            // Restore dropped 'e': "moved" → "move"
            $candidates[] = $stem . 'e';
        }

        // -s (must come last; check that base length is reasonable)
        if (str_ends_with($word, 's') && !str_ends_with($word, 'ss')
            && strlen($word) >= 4
        ) {
            $candidates[] = substr($word, 0, -1); // adds → add, calls → call
        }

        foreach ($candidates as $base) {
            if ($base === '' || strlen($base) < 3) continue;
            if (isset($wordnet[$base])) return true;
            if (in_array($base, $dictionary, true)) return true;
        }

        return false;
    }

    /**
     * Find a correction for a misspelled word
     */
    private function find_typo_correction(string $word): ?array {
        $word_lower = strtolower($word);
        $dictionary = $this->get_common_words_dictionary();

        // Never "correct" a word that already exists in the curated
        // dictionary or in WordNet (v4.37.10+). The caller
        // (apply_spell_correction) already does these checks before
        // calling us, but be defensive in case something else calls
        // here directly.
        if (in_array($word_lower, $dictionary, true)) {
            return null;
        }
        if (function_exists('CleverSay\\cleversay_wordnet_dictionary')) {
            $wn = \CleverSay\cleversay_wordnet_dictionary();
            if (isset($wn[$word_lower])) {
                return null;
            }
        }

        $best_match = null;
        $lowest_distance = PHP_INT_MAX;
        
        // Scale max allowed edit distance by word length:
        // short words (<=5 chars) only allow 1 edit — prevents "duty" → "but"
        // longer words allow 2 edits for genuine typos like "recieve" → "receive"
        $max_distance = (strlen($word_lower) >= 6) ? 2 : 1;

        foreach ($dictionary as $correct_word) {
            // Skip if lengths are too different
            $len_diff = abs(strlen($word_lower) - strlen($correct_word));
            if ($len_diff > $max_distance) {
                continue;
            }

            // Calculate Levenshtein distance
            $distance = levenshtein($word_lower, $correct_word);

            if ($distance > 0 && $distance <= $max_distance && $distance < $lowest_distance) {
                $lowest_distance = $distance;
                $best_match = [
                    'word'   => $correct_word,
                    'method' => $distance == 1 ? 'single_typo' : 'double_typo',
                    'distance' => $distance,
                ];
            }
            
            // Also check for transposition (teh -> the)
            if ($distance > 2 && $this->is_transposition($word_lower, $correct_word)) {
                if ($lowest_distance > 1) {
                    $lowest_distance = 1;
                    $best_match = [
                        'word' => $correct_word,
                        'method' => 'transposition',
                        'distance' => 1
                    ];
                }
            }
        }
        
        return $best_match;
    }
    
    /**
     * Get dictionary of common words for spell checking
     */
    private function get_common_words_dictionary(): array {
        static $dictionary = null;

        if ($dictionary === null) {
            $base = [
                // Common question/action words
                'about', 'access', 'account', 'add', 'address', 'advisor', 'after', 
                'again', 'all', 'also', 'always', 'amount', 'and', 'another', 'answer',
                'any', 'anyone', 'anything', 'apply', 'appointment', 'are', 'area',
                'around', 'ask', 'available', 'back', 'balance', 'because', 'been',
                'before', 'being', 'best', 'better', 'between', 'bill', 'billing',
                'book', 'booking', 'both', 'bus', 'buses', 'business', 'but', 'buy', 'calendar', 'call', 'came',
                'campus', 'can', 'cancel', 'card', 'care', 'case', 'center', 'certificate',
                'change', 'charge', 'check', 'class', 'classes', 'close', 'college',
                'come', 'coming', 'commit', 'company', 'complete', 'computer', 'confirm', 
                'contact', 'continue', 'copy', 'cost', 'could', 'counselor', 'course',
                'courses', 'create', 'credit', 'current', 'customer', 'date', 'day',
                'days', 'degree', 'deliver', 'delivery', 'department', 'did', 'different',
                'discount', 'does', 'doing', 'done', 'down', 'download', 'during', 'each',
                'early', 'education', 'email', 'emergency', 'end', 'enroll', 'enrollment',
                'enter', 'error', 'even', 'event', 'every', 'everything', 'exam',
                'example', 'exchange', 'expect', 'experience', 'expire', 'explain',
                'extension', 'faculty', 'fail', 'family', 'far', 'fee', 'fees', 'few',
                'file', 'fill', 'final', 'find', 'finish', 'first', 'fix', 'follow',
                'for', 'form', 'found', 'free', 'friday', 'from', 'full', 'get', 'getting',
                'give', 'given', 'going', 'good', 'got', 'grade', 'grades', 'graduate',
                'graduation', 'great', 'group', 'had', 'happen', 'has', 'have', 'having',
                'health', 'help', 'here', 'high', 'history', 'hold', 'home', 'hour',
                'hours', 'house', 'housing', 'how', 'however', 'identify', 'important',
                'include', 'information', 'inside', 'install', 'instructor', 'insurance',
                'interest', 'into', 'issue', 'issues', 'item', 'its', 'job', 'jobs',
                'join', 'just', 'keep', 'know', 'knowing', 'known', 'last', 'late',
                'later', 'learn', 'leave', 'left', 'less', 'let', 'letter', 'level',
                'library', 'life', 'like', 'line', 'link', 'list', 'little', 'live',
                'loan', 'local', 'location', 'log', 'login', 'long', 'look', 'looking',
                'lost', 'lot', 'made', 'mail', 'main', 'major', 'make', 'making', 'man',
                'manager', 'many', 'map', 'may', 'maybe', 'meet', 'meeting', 'member',
                'membership', 'message', 'method', 'might', 'min', 'minimum', 'miss',
                'missing', 'mobile', 'monday', 'money', 'month', 'monthly', 'more',
                'morning', 'most', 'move', 'much', 'must', 'name', 'near', 'need',
                'never', 'new', 'next', 'night', 'nobody', 'none', 'normal', 'not',
                'note', 'nothing', 'notice', 'now', 'number', 'office', 'often', 'old',
                'once', 'one', 'online', 'only', 'open', 'option', 'options', 'order',
                'other', 'our', 'out', 'over', 'own', 'page', 'paid', 'paper', 'parent',
                'parking', 'part', 'pass', 'password', 'past', 'pay', 'payment', 'people',
                'per', 'period', 'permit', 'person', 'personal', 'phone', 'pick', 'place',
                'plan', 'please', 'point', 'policy', 'possible', 'post', 'prefer',
                'premium', 'prepare', 'present', 'president', 'price', 'print', 'prior',
                'privacy', 'problem', 'process', 'product', 'professor', 'profile',
                'program', 'project', 'proof', 'provide', 'purchase', 'put', 'question',
                'questions', 'quick', 'read', 'ready', 'real', 'really', 'reason',
                'receive', 'recent', 'recommend', 'record', 'records', 'refund', 'register',
                'registration', 'related', 'release', 'remember', 'remove', 'renew',
                'rental', 'repeat', 'replace', 'report', 'request', 'require', 'required',
                'research', 'reset', 'residence', 'resolve', 'response', 'result', 'return',
                'review', 'right', 'room', 'run', 'safe', 'safety', 'said', 'same', 'saturday',
                'save', 'say', 'schedule', 'scholarship', 'school', 'search', 'second',
                'section', 'security', 'see', 'seem', 'semester', 'send', 'sent', 'service',
                'services', 'session', 'set', 'setting', 'settings', 'several', 'share',
                'ship', 'shipping', 'should', 'show', 'side', 'sign', 'since', 'single',
                'site', 'size', 'small', 'software', 'some', 'someone', 'something',
                'soon', 'sorry', 'special', 'staff', 'standard', 'start', 'state',
                'statement', 'status', 'stay', 'step', 'still', 'stop', 'store', 'story',
                'student', 'students', 'study', 'subject', 'submit', 'subscription',
                'such', 'sunday', 'support', 'sure', 'system', 'take', 'taking', 'talk',
                'teacher', 'team', 'tell', 'term', 'test', 'text', 'than', 'thank', 'thanks',
                'that', 'the', 'their', 'them', 'then', 'there', 'these', 'they', 'thing',
                'things', 'think', 'this', 'those', 'though', 'thought', 'three', 'through',
                'thursday', 'ticket', 'time', 'title', 'today', 'together', 'told', 'too',
                'took', 'top', 'total', 'track', 'tracking', 'transcript', 'transfer',
                'try', 'trying', 'tuesday', 'tuition', 'turn', 'type', 'under', 'understand',
                'university', 'until', 'update', 'upgrade', 'upon', 'use', 'used', 'user',
                'using', 'usually', 'valid', 'value', 'various', 'verify', 'version', 'very',
                'via', 'view', 'visit', 'wait', 'waiting', 'walk', 'want', 'wanted', 'was',
                'way', 'website', 'wednesday', 'week', 'weekly', 'well', 'went', 'were',
                'what', 'whatever', 'when', 'where', 'whether', 'which', 'while', 'who',
                'whole', 'whose', 'why', 'will', 'window', 'wish', 'with', 'withdraw',
                'within', 'without', 'woman', 'work', 'working', 'world', 'would', 'write',
                'written', 'wrong', 'year', 'years', 'yes', 'yet', 'you', 'your', 'yourself'
            ];

            // v4.37.3+: small university-context vocabulary that is
            // commonly stemmed FROM but isn't on the general English
            // common-words list. Without these, "admitted" stayed
            // "admitted" because the candidate "admit" wasn't a known
            // word — so the stemmer's safety check (only stem if the
            // candidate is in the dictionary, to avoid over-stemming
            // like "process" → "proc") fell through. Keep this list
            // small and conservative — every word added here makes
            // the stemmer slightly more aggressive.
            $domain_extras = [
                'admit', 'admission', 'admissions', 'admitted',
                'matriculate', 'matriculation',
                'enroll', 'enrolled', 'enrolling', 'enrollment',
                'register', 'registered', 'registering', 'registration',
                'transfer', 'transferred', 'transferring',
                'withdraw', 'withdrawn', 'withdrawing', 'withdrawal',
                'graduate', 'graduated', 'graduating', 'graduation',
                'audit', 'audited', 'auditing',
                'major', 'majoring', 'minor', 'minoring',
                'declare', 'declared', 'declaring',
                'transcript', 'transcripts',
                'fafsa', 'sap', 'gpa',
                'dorm', 'dorms', 'dormitory',
                'bursar', 'registrar',

                // v4.37.23+: common action verbs the stemmer needs as
                // valid lemmas to accept suffix-strip results. Prior
                // to this, queries like "Will retaking a course remove
                // the F from my GPA?" left "retaking" un-stemmed
                // because the rule produced "retake" but the dict
                // didn't validate it (only "take" was present, and
                // the suffix-strip rule doesn't try prefix-stripped
                // forms). Same gap on a long tail: drop, repay,
                // reapply, resubmit, etc. We keep additions targeted
                // to action verbs admins commonly use in question
                // phrasing — over-broad additions risk reintroducing
                // over-stemming.
                'retake', 'retaken', 'retaking', 'retook',
                'reapply', 'reapplied', 'reapplying',
                'resubmit', 'resubmitted', 'resubmitting',
                'repay', 'repaid', 'repaying',
                'restart', 'restarted', 'restarting',
                'reschedule', 'rescheduled', 'rescheduling',
                'recheck', 'rechecked', 'rechecking',
                'attend', 'attended', 'attending', 'attendance',
                'submit', 'submitted', 'submitting', 'submission',
                'accept', 'accepted', 'accepting', 'acceptance',
                'reject', 'rejected', 'rejecting', 'rejection',
                'drop', 'dropped', 'dropping',
                'earn', 'earned', 'earning',
                'lose', 'lost', 'losing',
                'extend', 'extended', 'extending', 'extension',
                'deposit', 'deposited', 'depositing',
                'waive', 'waived', 'waiving', 'waiver',
                'defer', 'deferred', 'deferring', 'deferral',
                'postpone', 'postponed', 'postponing',
                'edit', 'edited', 'editing',
                'upload', 'uploaded', 'uploading',
                'download', 'downloaded', 'downloading',
                'forward', 'forwarded', 'forwarding',
                'scan', 'scanned', 'scanning',
                'pickup', 'pickedup',
                'logon', 'logoff', 'signin', 'signup',
            ];

            // v4.37.3+: fold KB keywords into the dictionary. A KB
            // keyword is an admin-chosen organizing term — it's by
            // definition a "real word" for this site. This means
            // "admitted" stems to "admit" if you have an `admit`
            // keyword, "withdrawing" stems to "withdraw" if you have
            // a `withdraw` keyword, etc. — automatic, no per-site
            // tuning. Pulled from the same source the spell-correction
            // whitelist uses (KB keywords table, status=active).
            $kb_keywords = [];
            try {
                $rows = $this->wpdb->get_col(
                    "SELECT DISTINCT keyword
                       FROM {$this->db->knowledge_base}
                      WHERE status = 'active'"
                );
                foreach ((array) $rows as $kw) {
                    $kw = strtolower(trim((string) $kw));
                    if ($kw !== '') $kb_keywords[] = $kw;
                }
            } catch (\Throwable $e) {
                // If DB lookup fails (e.g. activation race), fall
                // back to base + domain extras only. Better than
                // throwing a fatal during a search.
                $kb_keywords = [];
            }

            $dictionary = array_values(array_unique(array_merge(
                $base,
                $domain_extras,
                $kb_keywords
            )));
        }

        return $dictionary;
    }
    
    /**
     * Check if a word is a common English word
     */
    private function is_common_english_word(string $word): bool {
        return in_array(strtolower($word), $this->get_common_words_dictionary());
    }
    
    /**
     * Check if word2 could be a keyboard typo of word1
     */
    private function is_keyboard_typo(string $word1, string $word2): bool {
        if (abs(strlen($word1) - strlen($word2)) > 1) {
            return false;
        }
        
        $differences = 0;
        $len = min(strlen($word1), strlen($word2));
        
        for ($i = 0; $i < $len; $i++) {
            if ($word1[$i] !== $word2[$i]) {
                // Check if characters are keyboard adjacent
                $char1 = strtolower($word1[$i]);
                $char2 = strtolower($word2[$i]);
                
                if (isset($this->keyboard_adjacents[$char1]) && in_array($char2, $this->keyboard_adjacents[$char1])) {
                    $differences++;
                } elseif (isset($this->keyboard_adjacents[$char2]) && in_array($char1, $this->keyboard_adjacents[$char2])) {
                    $differences++;
                } else {
                    return false; // Non-adjacent difference
                }
            }
        }
        
        // Allow at most 2 keyboard-adjacent differences
        return $differences <= 2 && $differences > 0;
    }
    
    /**
     * Check if word2 is a transposition of word1 (swapped adjacent letters)
     */
    private function is_transposition(string $word1, string $word2): bool {
        if (strlen($word1) !== strlen($word2)) {
            return false;
        }
        
        $len = strlen($word1);
        $differences = 0;
        $diff_positions = [];
        
        for ($i = 0; $i < $len; $i++) {
            if ($word1[$i] !== $word2[$i]) {
                $differences++;
                $diff_positions[] = $i;
            }
        }
        
        // Exactly 2 differences
        if ($differences !== 2) {
            return false;
        }
        
        // Check if they're adjacent and swapped
        $pos1 = $diff_positions[0];
        $pos2 = $diff_positions[1];
        
        if ($pos2 - $pos1 === 1) {
            // Adjacent positions - check if swapped
            return $word1[$pos1] === $word2[$pos2] && $word1[$pos2] === $word2[$pos1];
        }
        
        return false;
    }
    
    /**
     * Default minimum score for AI tiebreak to engage. Configurable
     * via the `cleversay_ai_tiebreak_min_score` option. At this floor
     * and above, ties represent two competing matches with at least
     * a base keyword match (score 100) plus any token bonuses.
     *
     * Reference points from the matcher's scoring:
     *   - 100: bare keyword match, aadefault, or `+`-phrase with 0 token bonus
     *   - 110: single-token wildcard match (1 word × 10pt bonus)
     *   - 145: 2-token AND match (the most common tie level)
     *   - 165+: 3-token AND match
     *
     * Default 100 — the floor is "any pattern that matched at all
     * vs. another that matched at all." Lower = more AI calls but
     * AI is always a deterministic-vs-AI choice, never AI on noise.
     * Admin can raise to e.g. 145 to only engage AI on strong ties.
     *
     * @since 4.37.41 (default 120)
     * @since 4.37.42 (default 100, configurable via option)
     */
    private const TIEBREAK_MIN_SCORE_DEFAULT = 100;

    /**
     * If the top matches tie at a high score, ask the LLM to pick
     * the better fit. Reorders `$matches` with the AI's choice first.
     * Returns the input unchanged if no tie qualifies or AI is
     * unavailable.
     *
     * @since 4.37.41
     */
    private function maybe_ai_tiebreak(string $question, array $matches): array {
        if (count($matches) < 2) return $matches;

        $floor = (int) get_option(
            'cleversay_ai_tiebreak_min_score',
            self::TIEBREAK_MIN_SCORE_DEFAULT
        );
        // Floor of 0 = feature disabled (admin-controlled kill switch).
        if ($floor <= 0) return $matches;

        $top_score = (int) ($matches[0]['score'] ?? 0);
        if ($top_score < $floor) return $matches;

        // Find all matches tied at the top score.
        $tied_indices = [0];
        for ($i = 1; $i < count($matches); $i++) {
            if ((int) ($matches[$i]['score'] ?? 0) === $top_score) {
                $tied_indices[] = $i;
            } else {
                break; // matches are sorted by score; first non-equal ends the run
            }
        }
        if (count($tied_indices) < 2) return $matches;

        // AI gate.
        if (!class_exists('\\CleverSay\\AI')) return $matches;
        $ai = new \CleverSay\AI();
        if (!$ai->is_configured()) return $matches;

        // Cache lookup. Same question against same set of tied entry
        // ids should produce the same answer for 24h.
        $tied_ids = [];
        foreach ($tied_indices as $idx) {
            $tied_ids[] = (int) ($matches[$idx]['id'] ?? 0);
        }
        sort($tied_ids);
        $cache_key = 'cs_tiebreak_' . md5($question . '|' . implode(',', $tied_ids));
        $cached_id = get_transient($cache_key);

        $chosen_id = null;
        if ($cached_id !== false && in_array((int) $cached_id, $tied_ids, true)) {
            $chosen_id = (int) $cached_id;
            $this->logger->debug('AI tiebreak cache hit', ['id' => $chosen_id]);
        } else {
            $chosen_id = $this->ai_pick_among_tied(
                $ai,
                $question,
                $matches,
                $tied_indices
            );
            if ($chosen_id !== null) {
                set_transient($cache_key, $chosen_id, DAY_IN_SECONDS);
                $this->logger->info('AI tiebreak resolved', [
                    'question' => $question,
                    'tied_ids' => $tied_ids,
                    'chosen'   => $chosen_id,
                ]);
            }
        }

        if ($chosen_id === null) return $matches; // AI failed or returned junk

        // v4.37.50+: stash the tiebreak event so log_question() can
        // persist it on the questions_log row. We capture provider
        // info here too (Anthropic vs Gemini) so admin observability
        // pages can break tiebreak counts down by provider.
        $this->pending_tiebreak = [
            'chosen_id' => $chosen_id,
            'tied_ids'  => $tied_ids,
            'provider'  => method_exists($ai, 'get_provider') ? $ai->get_provider() : '',
        ];

        // Reorder matches: chosen tied entry first, others (tied and
        // non-tied) preserve their existing order after.
        $chosen_idx = null;
        foreach ($tied_indices as $idx) {
            if ((int) ($matches[$idx]['id'] ?? 0) === $chosen_id) {
                $chosen_idx = $idx;
                break;
            }
        }
        if ($chosen_idx === null || $chosen_idx === 0) return $matches;

        $chosen_match = $matches[$chosen_idx];
        // Mark it so downstream code / debugging can see the
        // reordering happened. (Stripped before sending to user.)
        $chosen_match['_ai_tiebreak'] = true;
        unset($matches[$chosen_idx]);
        array_unshift($matches, $chosen_match);
        return array_values($matches);
    }

    /**
     * Build a prompt and ask the LLM which tied entry best matches
     * the user's question. Returns the chosen entry's id, or null
     * on any failure.
     *
     * @since 4.37.41
     */
    private function ai_pick_among_tied(\CleverSay\AI $ai, string $question, array $matches, array $tied_indices): ?int {
        $candidates = [];
        foreach ($tied_indices as $idx) {
            $m = $matches[$idx];
            $candidates[] = [
                'id'       => (int) ($m['id'] ?? 0),
                'keyword'  => (string) ($m['keyword'] ?? ''),
                'question' => (string) ($m['question'] ?? ''),
            ];
        }

        $candidate_lines = [];
        foreach ($candidates as $c) {
            $candidate_lines[] = sprintf(
                "  id=%d (under keyword \"%s\"): \"%s\"",
                $c['id'],
                $c['keyword'],
                $c['question']
            );
        }

        $prompt = "A user asked: \"" . $question . "\"\n\n"
            . "Multiple knowledge-base entries matched their question equally well. Pick the SINGLE entry whose canonical question best aligns with what the user is asking — the one whose answer would most likely help them. Reply with valid JSON only, no other text:\n\n"
            . "Candidates:\n" . implode("\n", $candidate_lines) . "\n\n"
            . "{\"id\": <chosen_id>, \"reason\": \"<one short sentence>\"}";

        $response = $ai->call_for_text($prompt, [
            'max_tokens'  => 120,
            'temperature' => 0.0,
        ]);

        if ($response === '') return null;
        if (!preg_match('/\{.*\}/s', $response, $m)) return null;
        $parsed = json_decode($m[0], true);
        if (!is_array($parsed) || !isset($parsed['id'])) return null;

        $chosen_id = (int) $parsed['id'];
        // Verify the chosen id is actually one of our tied candidates
        // (defense against the LLM hallucinating an id).
        $tied_ids = array_map(fn($c) => $c['id'], $candidates);
        if (!in_array($chosen_id, $tied_ids, true)) return null;

        return $chosen_id;
    }

    /**
     * Find matches in knowledge base
     */
    private function find_matches(array $words, string $original_question): array {
        $max_results = (int) get_option('cleversay_max_results', 5);
        if ($max_results < 1) $max_results = 5;
        
        $min_score = (int) get_option('cleversay_min_match_score', 50);
        if ($min_score < 1) $min_score = 50;
        
        $this->logger->debug('find_matches started', [
            'words' => implode(',', $words),
            'max_results' => $max_results,
            'min_score' => $min_score
        ]);
        
        // Use the shared core search method
        $result = $this->do_core_search($words, $original_question, $max_results, $min_score);
        $matches = $result['matches'];
        
        $this->logger->debug('find_matches result', ['count' => count($matches)]);
        
        // Get suggested questions if no matches
        $suggested = [];
        if (empty($matches)) {
            $suggested = $this->get_suggestions($words);
        }
        
        // Update hit counts for matches
        if (!empty($matches)) {
            $ids = array_column($matches, 'id');
            $id_list = implode(',', array_map('intval', $ids));
            $this->wpdb->query(
                "UPDATE {$this->db->knowledge_base} SET hits = hits + 1 WHERE id IN ($id_list)"
            );
        }
        
        return [
            'matches' => array_values($matches),
            'suggested' => $suggested,
        ];
    }
    
    /**
     * Core search method - shared by both public and admin search
     * This is the SINGLE source of truth for search logic
     */
    private function do_core_search(array $words, string $original_question, int $max_results, int $min_score): array {
        $debug = '';
        $matches = [];
        
        // Try with processed words first
        if (!empty($words)) {
            $debug .= "Searching processed words: [" . implode(', ', $words) . "]. ";
            $matches = $this->do_keyword_search_core($words, $max_results, $min_score, $debug, $original_question);
            $this->logger->debug('Core search with processed words', ['count' => count($matches)]);
        } else {
            $debug .= "No processed words to search. ";
        }
        
        // If no matches, try with original question words — but still remove stopwords
        if (empty($matches)) {
            $original_words = $this->tokenize($this->normalize_question($original_question));
            $original_words = $this->remove_stopwords($original_words); // must strip stopwords here too
            // Only try if we actually have different/additional words
            if (!empty($original_words) && $original_words !== $words) {
                $debug .= "Trying original words (stopwords removed): [" . implode(', ', $original_words) . "]. ";
                $this->logger->debug('Trying original words', ['words' => implode(',', $original_words)]);
                $matches = $this->do_keyword_search_core($original_words, $max_results, $min_score, $debug, $original_question);
                $this->logger->debug('Original words search result', ['count' => count($matches)]);
            }
        }
        
        // If still no matches, try broad search
        // But only if min_score is low enough (broad search scores are 50)
        if (empty($matches) && $min_score <= 50) {
            $debug .= "Trying broad search on full question. ";
            $this->logger->debug('Trying broad search');
            $matches = $this->do_broad_search($original_question, $max_results);
            if (!empty($matches)) {
                $debug .= "Broad search found: " . count($matches) . " results. ";
            } else {
                $debug .= "Broad search found no results. ";
            }
            $this->logger->debug('Broad search result', ['count' => count($matches)]);
        } elseif (empty($matches) && $min_score > 50) {
            $debug .= "Skipping broad search (min_score {$min_score} > 50). ";
        }
        
        return [
            'matches' => $matches,
            'debug' => $debug,
        ];
    }
    
    /**
     * Core keyword search - SINGLE implementation used by all search paths
     * @param array $words Search words
     * @param int $max_results Maximum results to return
     * @param int $min_score Minimum score threshold
     * @param string &$debug Debug output string (passed by reference)
     * @return array Filtered search results
     */
    private function do_keyword_search_core(array $words, int $max_results, int $min_score, string &$debug, ?string $original_question = null): array {
        if (empty($words)) {
            $debug .= "No words to search. ";
            return [];
        }
        
        // Filter valid words (2+ chars)
        $valid_words = array_filter($words, fn($w) => !empty($w) && strlen($w) >= 2);
        if (empty($valid_words)) {
            $debug .= "No valid keywords (all words < 2 chars). ";
            return [];
        }
        
        $words_lower = array_map('strtolower', $valid_words);
        $debug .= "Search words: [" . implode(', ', $words_lower) . "]. ";
        
        // Step 1: Find all unique keywords that might match.
        //
        // IMPORTANT: We use TWO conditions per word to handle both directions:
        //
        //   a) keyword LIKE '%grades%'  — keyword contains the search word
        //      e.g. keyword="graduation" matches search word "grad"
        //
        //   b) keyword LIKE 'grade%'    — keyword is a PREFIX of the search word
        //      e.g. keyword="grade" matches search word "grades" or "graded"
        //      Without this, "grades" never retrieves the "grade" entry because
        //      "grade" does not CONTAIN "grades".
        //
        // We also add root forms for words ending in common suffixes (s, es, ed, ing)
        // so "viewing" finds "view", "grades" finds "grade", etc.
        $keyword_conditions = [];
        $all_search_variants = [];

        foreach ($valid_words as $word) {
            $all_search_variants[] = strtolower($word);
            // Generate root variants for common English inflections
            $lower = strtolower($word);
            $len   = strlen($lower);
            if ($len > 4) {
                if (str_ends_with($lower, 'ing') && $len > 5)  $all_search_variants[] = substr($lower, 0, -3);
                if (str_ends_with($lower, 'ing') && $len > 5)  $all_search_variants[] = substr($lower, 0, -3) . 'e';
                if (str_ends_with($lower, 'ies') && $len > 4)  $all_search_variants[] = substr($lower, 0, -3) . 'y';
                if (str_ends_with($lower, 'es')  && $len > 4)  $all_search_variants[] = substr($lower, 0, -2);
                if (str_ends_with($lower, 'ed')  && $len > 4)  $all_search_variants[] = substr($lower, 0, -2);
                if (str_ends_with($lower, 'ed')  && $len > 4)  $all_search_variants[] = substr($lower, 0, -1);
                if (str_ends_with($lower, 's')   && $len > 4)  $all_search_variants[] = substr($lower, 0, -1);
            }
        }

        $all_search_variants = array_unique(array_filter($all_search_variants, fn($v) => strlen($v) >= 2));

        foreach ($all_search_variants as $variant) {
            $escaped = $this->wpdb->esc_like($variant);
            // Exact keyword match
            $keyword_conditions[] = $this->wpdb->prepare("keyword = %s", $variant);
            // Allow common short suffixes: s, es, ed, ing, er — but NOT longer derivations
            // This lets "refund" match "refunds" but prevents "attend" matching "attendance"
            foreach (['s', 'es', 'ed', 'ing', 'er', 'rs'] as $suffix) {
                $keyword_conditions[] = $this->wpdb->prepare("keyword = %s", $variant . $suffix);
            }
        }

        $keyword_conditions = array_unique($keyword_conditions);
        $keyword_where = implode(' OR ', $keyword_conditions);
        
        // Get all entries where keyword might match (no limit - we need to evaluate all patterns)
        $query = "SELECT id, keyword, sub_keyword, question, response, show_rating, updated_at, status, hits,
                         reuse_response, reuse_keyword, reuse_sub_keyword, polished_hash
                  FROM {$this->db->knowledge_base}
                  WHERE status = 'active'
                  AND (expires_at IS NULL OR expires_at > CURDATE())
                  AND ({$keyword_where})";
        
        $this->logger->debug('SQL Query', ['query' => $query]);
        $results = $this->wpdb->get_results($query, ARRAY_A);
        
        if ($this->wpdb->last_error) {
            $debug .= "SQL Error: " . $this->wpdb->last_error . ". ";
            $this->logger->error('SQL Error', ['error' => $this->wpdb->last_error]);
        }
        
        $raw_count = is_array($results) ? count($results) : 0;
        $debug .= "Found {$raw_count} entries for potential keyword matches. ";
        
        if (empty($results)) {
            return [];
        }
        
        // Step 2: Group entries by keyword and evaluate ALL patterns
        $keyword_entries = [];
        foreach ($results as $r) {
            $keyword_lower = strtolower($r['keyword']);
            if (!isset($keyword_entries[$keyword_lower])) {
                $keyword_entries[$keyword_lower] = [];
            }
            $keyword_entries[$keyword_lower][] = $r;
        }
        
        $debug .= "Keywords to evaluate: [" . implode(', ', array_keys($keyword_entries)) . "]. ";
        
        // Step 3: For each keyword, check if it matches and find the best pattern
        $best_matches = [];
        
        foreach ($keyword_entries as $keyword => $entries) {
            // PHP casts numeric-string array keys to int when used as
            // dictionary keys (e.g., a KB keyword "911" becomes int 911).
            // Cast back to string so typed string parameters don't throw.
            $keyword = (string) $keyword;

            // First, verify the keyword actually matches the user's words
            if (!$this->matches_keyword_pattern($keyword, $words_lower)) {
                $debug .= "Keyword '{$keyword}' doesn't match search words. ";
                continue;
            }
            
            $debug .= "Evaluating patterns for '{$keyword}': ";
            
            // Evaluate ALL patterns for this keyword
            $best_pattern = null;
            $best_score = -1;
            $default_entry = null;
            
            foreach ($entries as $entry) {
                $sub_keyword = $entry['sub_keyword'] ?? '';
                $sub_keyword_lower = strtolower(trim($sub_keyword));
                
                // Check for empty response - but allow reuse entries (they have empty response but reference another entry)
                $response = $entry['response'] ?? '';
                $is_reuse = !empty($entry['reuse_response']);
                
                if (!$is_reuse) {
                    $stripped = strip_tags(html_entity_decode($response, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $stripped = preg_replace('/[\s\x{00A0}]+/u', '', $stripped);
                    if (empty($stripped)) {
                        continue;
                    }
                }
                
                // Is this the default/fallback?
                if ($sub_keyword_lower === 'aadefault' || empty($sub_keyword_lower)) {
                    $default_entry = $entry;
                    $debug .= "[aadefault=fallback] ";
                    continue;
                }
                
                // Try to match this pattern
                if (!$this->matches_sub_keyword($sub_keyword, $words_lower, $original_question)) {
                    $debug .= "[{$sub_keyword}=no] ";
                    continue;
                }
                
                // Pattern matches! Calculate score based on specificity
                $pattern_score = $this->calculate_pattern_match_score($sub_keyword, $words_lower);
                $debug .= "[{$sub_keyword}=yes,score:{$pattern_score}] ";
                
                if ($pattern_score > $best_score) {
                    $best_score = $pattern_score;
                    $best_pattern = $entry;
                    $best_pattern['pattern_score'] = $pattern_score;
                }
            }
            
            // Determine which entry to use
            if ($best_pattern !== null) {
                // Use the best matching specific pattern
                $best_pattern['score'] = 100 + $best_score; // Base 100 + pattern specificity
                $best_matches[] = $best_pattern;
                $debug .= "→ Best: '{$best_pattern['sub_keyword']}' (score: {$best_pattern['score']}). ";
            } elseif ($default_entry !== null) {
                // No specific pattern matched, use default
                $default_entry['score'] = 100;
                $default_entry['pattern_score'] = 0;
                $best_matches[] = $default_entry;
                $debug .= "→ Using aadefault. ";
            } else {
                $debug .= "→ No valid match. ";
            }
        }
        
        // Step 4: Sort by score (highest first) and limit results
        usort($best_matches, function($a, $b) {
            $score_diff = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            if ($score_diff !== 0) return $score_diff;
            // Tie-breaker: more hits = more popular
            return ($b['hits'] ?? 0) <=> ($a['hits'] ?? 0);
        });
        
        $best_matches = array_slice($best_matches, 0, $max_results);
        
        // Resolve any reused responses
        $best_matches = $this->resolve_reused_responses($best_matches);
        
        $debug .= "Final matches: " . count($best_matches) . ". ";
        
        return $best_matches;
    }
    
    /**
     * Resolve reused responses by looking up the actual response content
     * 
     * @param array $matches Array of matched entries
    /**
     * Resolve reused responses by looking up the actual response content
     *
     * v4.36.1+: when a row says reuse_response=1 and points to a target,
     * we follow the pointer. Three failure modes need handling:
     *
     *   1. Target not found (orphan pointer) — keyword/sub_keyword
     *      pair doesn't exist in the table, or only exists with a
     *      non-active status. The row keeps its (empty) own response,
     *      and we log a warning so admins can see which entries have
     *      broken pointers.
     *   2. Target found but its response is also effectively empty —
     *      same `<p>&#160;</p>` / nbsp pattern as the source. Don't
     *      "resolve" to another empty cell; leave the row's own
     *      response in place so the chat handler's empty-content
     *      check can detect it and fall through to AI fallback.
     *   3. Target found and has real content — normal case, copy
     *      the response over.
     *
     * @param array $matches Array of matched entries
     * @return array Matches with reused responses resolved
     */
    private function resolve_reused_responses(array $matches): array {
        foreach ($matches as &$match) {
            if (empty($match['reuse_response'])
                || empty($match['reuse_keyword'])
                || empty($match['reuse_sub_keyword'])
            ) {
                continue;
            }

            // Look up the actual response.
            $reused = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT response FROM {$this->db->knowledge_base}
                 WHERE keyword = %s AND (sub_keyword = %s OR (sub_keyword IS NULL AND %s = 'aadefault'))
                 AND status = 'active'
                 LIMIT 1",
                $match['reuse_keyword'],
                $match['reuse_sub_keyword'],
                $match['reuse_sub_keyword']
            ), ARRAY_A);

            if (empty($reused) || !isset($reused['response'])) {
                // Orphan pointer — target row doesn't exist or isn't
                // active. Leave the source row's response in place;
                // the empty-content check upstream will catch it and
                // fall through to AI.
                error_log(sprintf(
                    '[CleverSay] Orphan reuse pointer: id=%d keyword=%s sub_keyword=%s -> %s/%s (target not found or inactive)',
                    (int) ($match['id'] ?? 0),
                    (string) ($match['keyword'] ?? ''),
                    (string) ($match['sub_keyword'] ?? ''),
                    (string) $match['reuse_keyword'],
                    (string) $match['reuse_sub_keyword']
                ));
                continue;
            }

            // Detect effectively-empty targets (same logic as the
            // chat handler's placeholder check) so we don't "resolve"
            // to another empty cell.
            $target_plain = wp_strip_all_tags((string) $reused['response']);
            $target_plain = html_entity_decode($target_plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $target_plain = trim($target_plain, " \t\n\r\0\x0B\xc2\xa0");
            if ($target_plain === '') {
                error_log(sprintf(
                    '[CleverSay] Reuse target is effectively empty: id=%d -> %s/%s',
                    (int) ($match['id'] ?? 0),
                    (string) $match['reuse_keyword'],
                    (string) $match['reuse_sub_keyword']
                ));
                // Leave source response in place; empty-content check
                // upstream will fall through to AI.
                continue;
            }

            $match['response']    = $reused['response'];
            $match['reused_from'] = $match['reuse_keyword'] . '/' . $match['reuse_sub_keyword'];
        }

        return $matches;
    }
    
    /**
     * Calculate a pattern's match score based on specificity
     * 
     * Scoring Logic:
     * - Each matched word in an AND group: +10 points
     * - Complete AND group (all parts matched): +20 bonus
     * - More specific patterns (more AND words) score higher
     * 
     * Examples:
     * - "meet" matches "meet" → 10 points
     * - "require&meet" matches both → 20 (words) + 20 (complete AND) = 40 points
     * - "require&meet&prior" matches all 3 → 30 (words) + 20 (complete AND) = 50 points
     * - "appointment|meet" matches "meet" → 10 points (just one word in OR)
     * 
     * @param string $sub_keyword The pattern (e.g., "require&meet|contact")
     * @param array $words_lower User's search words (lowercase)
     * @return int Score (higher = better match)
     */
    private function calculate_pattern_match_score(string $sub_keyword, array $words_lower): int {
        $sub_keyword_lower = strtolower(trim($sub_keyword));
        
        if (empty($sub_keyword_lower) || $sub_keyword_lower === 'aadefault') {
            return 0;
        }
        
        // Split by OR (|) to get groups
        $or_groups = explode('|', $sub_keyword_lower);
        
        $best_group_score = 0;
        
        foreach ($or_groups as $group) {
            $group = trim($group);
            if (empty($group)) continue;
            
            // Split by AND (&) only. v4.37.26+: previously this also
            // split on `+`, which broke scoring for legacy phrase
            // patterns like `not+a+student&class`. The matcher
            // (matches_and_group, post-v4.37.8) treats `+` as a
            // phrase operator — `not+a+student` matches if the
            // literal phrase appears in the user's question. The
            // scorer was splitting on both `&` and `+`, then trying
            // to match each component (`not`, `a`, `student`) as a
            // separate word — which fails because `a` is a stopword
            // stripped from the user's tokens. Result: matcher said
            // YES, scorer said 0 points. Patterns containing `+`
            // got the 100 baseline but no per-token bonus, so they
            // lost ties to plain `&`-patterns of equal specificity.
            $and_parts = preg_split('/&/', $group);
            $and_parts = array_filter(array_map('trim', $and_parts));
            
            if (empty($and_parts)) continue;
            
            $matched_count = 0;
            $total_count = count($and_parts);
            // Track total "word weight" for the score — `+`-phrase
            // parts count for the number of words in the phrase
            // (not in stopwords) since they represent more specific
            // intent than a single bare word.
            $word_weight = 0;
            
            foreach ($and_parts as $part) {
                if ($this->matches_keyword_pattern($part, $words_lower)) {
                    $matched_count++;
                    // For `+` phrases, count the non-stopword
                    // components — each represents a real word the
                    // user had to type for the match to succeed.
                    if (strpos($part, '+') !== false) {
                        $components = preg_split('/\+/', $part);
                        foreach ($components as $c) {
                            $c = trim($c);
                            // strip trailing wildcard for length check
                            $c_check = rtrim($c, '*');
                            if ($c_check !== '' && strlen($c_check) > 1
                                && !in_array($c_check, $this->stopwords, true)) {
                                $word_weight++;
                            }
                        }
                    } else {
                        $word_weight++;
                    }
                }
            }
            
            // Only score if this OR group completely matches
            if ($matched_count === $total_count) {
                // Base: 10 points per matched word (or per `+`-phrase component).
                $group_score = $word_weight * 10;
                
                // Bonus for AND groups with multiple parts (more specific = better)
                if ($total_count > 1) {
                    $group_score += 20; // Bonus for complete AND match
                    $group_score += ($total_count - 1) * 5; // Extra for each additional required word
                }
                
                $best_group_score = max($best_group_score, $group_score);
            }
        }
        
        return $best_group_score;
    }
    
    /**
     * Validate keyword and sub_keyword matching
     * 
     * Matching Logic:
     * 1. Primary keyword must match user's search words
     * 2. If sub_keyword exists, it provides additional filtering:
     *    - | means OR (any group can match)
     *    - & means AND (all parts in group must match)
     *    - * means wildcard (word* matches word, words, wording, etc.)
     * 
     * Example: keyword="admission", sub_keyword="contact&admission*|email|phone|where"
     * User must match "admission" AND one of:
     *   - (contact AND admission*)
     *   - OR email
     *   - OR phone
     *   - OR where
     * 
     * @param string $keyword The primary keyword from DB
     * @param string|null $sub_keyword The sub-keyword from DB (with |, &, * syntax)
     * @param array $search_words The user's search words
     * @param string &$debug Debug output
     * @return bool True if match is valid
     */
    private function validate_compound_keyword_match(string $keyword, ?string $sub_keyword, array $search_words, string &$debug): bool {
        // Normalize search words to lowercase
        $search_words_lower = array_map('strtolower', $search_words);
        
        // Step 1: Check if primary keyword matches any search word
        if (!$this->matches_keyword_pattern($keyword, $search_words_lower)) {
            $debug .= "Excluded '{$keyword}' (primary keyword not matched). ";
            return false;
        }
        
        // Step 2: If sub_keyword exists, validate it matches
        if (!empty($sub_keyword)) {
            if (!$this->matches_sub_keyword($sub_keyword, $search_words_lower)) {
                $debug .= "Excluded '{$keyword}/{$sub_keyword}' (sub_keyword not matched). ";
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Check if search words match a sub_keyword pattern
     * 
     * Sub_keyword syntax:
     * - | separates OR groups (any can match)
     * - & separates AND parts (all must match within a group)
     * - * is wildcard (matches word prefix)
     * 
     * @param string $sub_keyword The sub_keyword pattern (e.g., "contact&admission*|email|phone")
     * @param array $search_words_lower Lowercase search words
     * @return bool True if at least one OR group matches
     */
    private function matches_sub_keyword(string $sub_keyword, array $search_words_lower, ?string $original_question = null): bool {
        $sub_keyword_lower = strtolower($sub_keyword);

        // Split by | to get OR groups
        $or_groups = explode('|', $sub_keyword_lower);

        // At least one OR group must match
        foreach ($or_groups as $or_group) {
            $or_group = trim($or_group);
            if (empty($or_group)) continue;

            // Check if this OR group matches
            if ($this->matches_and_group($or_group, $search_words_lower, $original_question)) {
                return true; // Found a matching OR group
            }
        }

        // No OR group matched
        return false;
    }

    /**
     * Check if search words match an AND group.
     *
     * An AND group may contain & separators - ALL parts must match.
     * Each part may have * wildcard. Each part may also contain `+`
     * which joins tokens into a CONSECUTIVE-WORD PHRASE (legacy
     * CleverSay semantics):
     *
     *   - `social+security` matches "...social security..." literally
     *     in the user's question, regardless of whether "i", "have",
     *     "to", "list" etc. would otherwise be filtered out as
     *     stopwords.
     *   - `do+i+have&social` is two AND parts: phrase "do i have"
     *     AND single word "social".
     *   - `i+earn*` matches "i earn", "i earned", "i earning" etc.
     *     (wildcard tail on the last word of the phrase).
     *
     * Phrase parts are matched against the lightly-normalized
     * original question (lowercased, punctuation stripped) rather
     * than the stopword-stripped tokens — that's the whole point of
     * authoring with `+`: bypass tokenization filters for cases
     * where the literal sequence is the intent.
     */
    private function matches_and_group(string $and_group, array $search_words_lower, ?string $original_question = null): bool {
        // Split on & ONLY. The legacy `+` operator joins tokens into
        // a single phrase (handled per-part below); pre-v4.37.8 we
        // split on both & and + and treated every token as
        // independent, which broke patterns like `do+i+have&social`
        // (where "do i have" is meant as one phrase, not three
        // independent words to find separately).
        $and_parts = explode('&', $and_group);

        // ALL parts must match
        foreach ($and_parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;

            if (strpos($part, '+') !== false) {
                // Phrase part — match consecutive words.
                if (!$this->matches_phrase_part($part, $search_words_lower, $original_question)) {
                    return false;
                }
            } else {
                // Single-word part — existing keyword match.
                if (!$this->matches_keyword_pattern($part, $search_words_lower)) {
                    return false;
                }
            }
        }

        // All AND parts matched
        return true;
    }

    /**
     * Match a + phrase ("social+security", "i+earn*", "the+last+day")
     * as a consecutive-word sequence.
     *
     * Uses the original question text when available so stopwords
     * inside the phrase ("do i have", "the last day") survive.
     * Falls back to a tokens-based scan if no original is provided
     * (e.g., older test paths).
     *
     * Wildcard semantics: any segment ending in `*` becomes a
     * prefix-match for that position. `social+security` requires
     * exact "social security"; `i+earn*` matches "i earn", "i
     * earned", etc.
     */
    private function matches_phrase_part(string $part, array $search_words_lower, ?string $original_question): bool {
        $segments = array_values(array_filter(array_map('trim', explode('+', $part))));
        if (empty($segments)) return true;

        if ($original_question !== null && $original_question !== '') {
            // Build a regex over the original question, with word
            // boundaries between segments. Treat `*` at the end of a
            // segment as a `\w*` continuation (prefix match).
            $re_parts = [];
            foreach ($segments as $seg) {
                $has_wildcard_tail = (substr($seg, -1) === '*');
                $core = $has_wildcard_tail ? rtrim($seg, '*') : $seg;
                if ($core === '') continue;
                $re_parts[] = preg_quote($core, '/') . ($has_wildcard_tail ? '\\w*' : '');
            }
            if (empty($re_parts)) return true;

            $regex = '/\\b' . implode('\\s+', $re_parts) . '\\b/iu';
            return (bool) preg_match($regex, $original_question);
        }

        // Fallback: scan the tokens array for the phrase consecutively.
        // Less precise (stopwords already stripped) but at least gives
        // a best-effort match when no original question is in scope.
        $n = count($search_words_lower);
        $m = count($segments);
        if ($m === 0 || $n < $m) return false;

        for ($i = 0; $i + $m <= $n; $i++) {
            $ok = true;
            for ($j = 0; $j < $m; $j++) {
                if (!$this->matches_keyword_pattern($segments[$j], [$search_words_lower[$i + $j]])) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) return true;
        }
        return false;
    }

    
    /**
     * Returns true if $suffix is a standard English grammatical inflection.
     * Used by matches_keyword_pattern to allow short root words like "park"
     * to match inflected forms like "parking", "parked", "parks" —
     * while blocking coincidental prefix overlaps like "park" in "parkinson".
     *
     * Valid:   park + ing  = parking ✓
     *          park + s    = parks   ✓
     *          add  + ed   = added   ✓
     * Invalid: park + inson = parkinson ✗
     *          for  + giveness = forgiveness ✗
     */
    /**
     * Returns true when $suffix is a grammatical inflection of the given $base.
     *
     * Split into two tiers to prevent coincidental prefix overlaps:
     *
     *   Short inflectional (base ≥ 3) — apply to any root word:
     *     s, es, ies, ed, d, ing, er, ers, est, ly
     *     e.g. park(4)+ing=parking ✓   add(3)+ed=added ✓
     *
     *   Long derivational (base ≥ 5) — only valid on longer roots:
     *     ion, tion, ation, ness, ment, ity, ance, ence, al, …
     *     e.g. admit(5)+ion=admission ✓   bill(4)+ion=billion ✗ (base too short)
     */
    private function is_inflection_suffix(string $base, string $suffix): bool {
        if ($suffix === '') return true;
        $s = strtolower($suffix);
        $base_len = strlen($base);

        // Short inflectional — fine for any base ≥ 3 chars
        static $short = ['s','es','ies','ed','d','ing','er','ers','est','ly'];
        if ($base_len >= 3 && in_array($s, $short, true)) return true;

        // Long derivational — require base ≥ 5 to avoid false positives
        static $long = [
            'al','ally','ful','less','ness',
            'ment','ments','ion','ions','tion','tions','ation','ations',
            'ity','ities','ance','ence','or','ors','ist','ists',
        ];
        if ($base_len >= 5 && in_array($s, $long, true)) return true;

        return false;
    }


    /**
     * Check if any search word matches a keyword pattern
     * 
     * Pattern may include * wildcard:
     * - "admission*" matches "admission", "admissions", etc.
     * - "*tion" matches "question", "admission", etc.
     * - "email" matches "email" exactly or as substring
     * 
     * @param string $pattern The keyword pattern (may include *)
     * @param array $search_words_lower Lowercase search words
     * @return bool True if any search word matches
     */
    private function matches_keyword_pattern(string $pattern, array $search_words_lower): bool {
        $pattern = strtolower(trim($pattern));
        if (empty($pattern)) return true; // Empty pattern always matches
        
        $has_wildcard = strpos($pattern, '*') !== false;
        
        foreach ($search_words_lower as $search_word) {
            if (empty($search_word)) continue;
            
            if ($has_wildcard) {
                // Handle wildcard matching
                if ($this->matches_wildcard_pattern($search_word, $pattern)) {
                    return true;
                }
            } else {
                // Exact or partial matching
                // Exact match
                if ($search_word === $pattern) {
                    return true;
                }
                // Word starts with pattern + grammatical suffix
                // e.g. park → parking ✓ (suffix "ing")
                //      park → parkinson ✗ (suffix "inson" not grammatical)
                //      for  → forgiveness ✗ (suffix "giveness" not grammatical)
                if (strlen($pattern) >= 3 && strpos($search_word, $pattern) === 0) {
                    $suffix = substr($search_word, strlen($pattern));
                    if ($this->is_inflection_suffix($pattern, $suffix)) {
                        return true;
                    }
                }
                // Reverse: search word is a prefix of the pattern + suffix
                // e.g. user types "park", keyword stored as "parking"
                if (strlen($search_word) >= 3 && strpos($pattern, $search_word) === 0) {
                    $suffix = substr($pattern, strlen($search_word));
                    if ($this->is_inflection_suffix($search_word, $suffix)) {
                        return true;
                    }
                }
                // Long-word substring fallback — both ≥ 6 chars, genuine shared root
                // e.g. "register" and "registration" share 8 common chars
                if (strlen($pattern) >= 6 && strlen($search_word) >= 6) {
                    if (strpos($search_word, $pattern) !== false ||
                        strpos($pattern, $search_word) !== false) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if a word matches a wildcard pattern
     * 
     * @param string $word The word to check
     * @param string $pattern The pattern with * wildcard
     * @return bool True if word matches pattern
     */
    private function matches_wildcard_pattern(string $word, string $pattern): bool {
        // Handle different wildcard positions
        if ($pattern === '*') {
            return true; // Match anything
        }
        
        if (str_starts_with($pattern, '*') && str_ends_with($pattern, '*')) {
            // *text* - contains
            $text = substr($pattern, 1, -1);
            return strpos($word, $text) !== false;
        }
        
        if (str_starts_with($pattern, '*')) {
            // *suffix - ends with
            $suffix = substr($pattern, 1);
            return str_ends_with($word, $suffix);
        }
        
        if (str_ends_with($pattern, '*')) {
            // prefix* - starts with
            $prefix = substr($pattern, 0, -1);
            return str_starts_with($word, $prefix);
        }
        
        // No wildcard at edges - treat * as literal? Or split?
        // For now, treat as contains
        $parts = explode('*', $pattern);
        $pos = 0;
        foreach ($parts as $part) {
            if (empty($part)) continue;
            $found_pos = strpos($word, $part, $pos);
            if ($found_pos === false) {
                return false;
            }
            $pos = $found_pos + strlen($part);
        }
        return true;
    }
    
    /**
     * Perform broad search using LIKE on original question
     */
    private function do_broad_search(string $question, int $max_results): array {
        // Extract meaningful words (3+ characters)
        $words = preg_split('/\s+/', strtolower($question));
        $words = array_filter($words, fn($w) => strlen($w) >= 3);
        
        if (empty($words)) {
            $this->logger->debug('do_broad_search: No words >= 3 chars');
            return [];
        }
        
        $this->logger->debug('do_broad_search words', ['words' => implode(',', $words)]);
        
        $conditions = [];
        foreach ($words as $word) {
            $escaped = $this->wpdb->esc_like($word);
            $conditions[] = $this->wpdb->prepare(
                "(keyword LIKE %s OR sub_keyword LIKE %s OR question LIKE %s OR response LIKE %s)",
                '%' . $escaped . '%',
                '%' . $escaped . '%',
                '%' . $escaped . '%',
                '%' . $escaped . '%'
            );
        }
        
        $where = implode(' OR ', $conditions);
        
        $query = "SELECT id, keyword, sub_keyword, question, response, show_rating, polished_hash, 50 as score
                  FROM {$this->db->knowledge_base}
                  WHERE status = 'active'
                  AND (expires_at IS NULL OR expires_at > CURDATE())
                  AND ({$where})
                  ORDER BY hits DESC
                  LIMIT %d";
        
        $prepared = $this->wpdb->prepare($query, $max_results);
        
        $this->logger->debug('do_broad_search SQL', ['query' => $prepared]);
        
        $results = $this->wpdb->get_results($prepared, ARRAY_A);
        
        $this->logger->debug('do_broad_search results', ['count' => count($results ?: [])]);
        
        // Filter out entries with empty responses
        if (!empty($results)) {
            $filtered = array_filter($results, function($r) {
                $response = $r['response'] ?? '';
                $stripped = strip_tags(html_entity_decode($response, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $stripped = preg_replace('/[\s\x{00A0}]+/u', '', $stripped);
                return !empty($stripped);
            });
            return array_values($filtered);
        }
        
        return [];
    }
    
    /**
     * Get question suggestions
     */
    private function get_suggestions(array $words): array {
        $suggested = [];
        
        foreach ($words as $word) {
            $results = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT DISTINCT question, keyword 
                 FROM {$this->db->knowledge_base}
                 WHERE status = 'active'
                 AND (keyword LIKE %s OR sub_keyword LIKE %s)
                 ORDER BY hits DESC
                 LIMIT 3",
                $word . '%',
                $word . '%'
            ), ARRAY_A);
            
            foreach ($results as $result) {
                $suggested[] = $result['question'];
            }
        }
        
        return array_slice(array_unique($suggested), 0, 5);
    }
    
    /**
     * Log question for analytics
     */
    private function log_question(string $question, ?array $match): ?int {
        $this->logger->info('log_question called', [
            'question' => substr($question, 0, 50),
            'has_match' => $match !== null
        ]);
        
        // Check if analytics is enabled - settings are stored in cleversay_options array
        $options = get_option('cleversay_options', []);
        $analytics_enabled = !isset($options['enable_analytics']) || $options['enable_analytics'];
        
        if (!$analytics_enabled) {
            $this->logger->debug('Analytics disabled, skipping log');
            return null;
        }
        
        // Check if we should exclude bot traffic
        $exclude_bots = !isset($options['exclude_bot_traffic']) || $options['exclude_bot_traffic'];
        $user_agent = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
        
        if ($exclude_bots && $this->is_bot($user_agent)) {
            $this->logger->debug('Bot traffic detected, skipping log', ['user_agent' => substr($user_agent, 0, 50)]);
            return null;
        }
        
        $data = [
            'question' => $question,
            'original_question' => $this->original_question,
            'detected_language' => $this->detected_language,
            'matched_keyword' => $match['keyword'] ?? null,
            'matched_sub_keyword' => $match['sub_keyword'] ?? null,
            'knowledge_id' => isset($match['id']) ? (int)$match['id'] : null,
            'match_type' => $match ? ((int)($match['score'] ?? 0) >= 90 ? 'exact' : 'partial') : 'none',
            'match_score' => (int)($match['score'] ?? 0),
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $user_agent,
            'referer' => sanitize_url($_SERVER['HTTP_REFERER'] ?? ''),
            'session_id' => session_id() ?: wp_generate_uuid4(),
            'created_at' => current_time('mysql'),
        ];

        // v4.37.50+: attach pending tiebreak event (set by
        // maybe_ai_tiebreak earlier in the same search() call).
        // Cleared after read so subsequent searches don't inherit.
        if ($this->pending_tiebreak !== null) {
            $data['ai_tiebreak']           = 1;
            $data['ai_tiebreak_chosen_id'] = (int) $this->pending_tiebreak['chosen_id'];
            $data['ai_tiebreak_tied_ids']  = substr(implode(',', $this->pending_tiebreak['tied_ids']), 0, 255);
            $data['ai_provider']           = substr((string) $this->pending_tiebreak['provider'], 0, 20);
            $this->pending_tiebreak = null;
        }
        
        $this->logger->debug('Inserting question log', [
            'table' => $this->db->questions_log,
            'data_keys' => array_keys($data)
        ]);
        
        $result = $this->wpdb->insert($this->db->questions_log, $data);
        $inserted_id = null;
        
        if ($result === false) {
            $this->logger->error('Failed to log question', [
                'error' => $this->wpdb->last_error,
                'question' => substr($question, 0, 50),
                'table' => $this->db->questions_log
            ]);
        } else {
            $inserted_id = (int) $this->wpdb->insert_id;
            $this->logger->info('Question logged successfully', ['id' => $inserted_id]);
        }
        
        // Track visitor
        $this->track_visitor();
        
        return $inserted_id;
    }
    
    /**
     * Mark a logged question as having been rejected by AI validation.
     * Called after the fact — Search logs questions during search() before
     * the caller (e.g. class-public.php) runs the aadefault AI validation.
     *
     * @param int    $question_id  The ID returned from search()['logged_question_id']
     * @param string $reason       Short reason tag: 'aadefault' or 'kb'
     */
    public function mark_question_ai_rejected(int $question_id, string $reason = 'kb', ?string $provider = null): void {
        if ($question_id <= 0) return;
        $update = [
            'ai_rejected'         => 1,
            'ai_rejection_reason' => substr($reason, 0, 255),
        ];
        $formats = ['%d', '%s'];
        if ($provider !== null && $provider !== '') {
            $update['ai_provider'] = substr($provider, 0, 20);
            $formats[] = '%s';
        }
        $this->wpdb->update(
            $this->db->questions_log,
            $update,
            ['id' => $question_id],
            $formats,
            ['%d']
        );
    }

    /**
     * Check if user agent is a known bot/crawler
     */
    private function is_bot(string $user_agent): bool {
        if (empty($user_agent)) {
            return true; // No user agent is suspicious
        }
        
        $user_agent_lower = strtolower($user_agent);
        
        // Common bot identifiers
        $bot_patterns = [
            // Search engine bots
            'googlebot',
            'bingbot',
            'slurp',           // Yahoo
            'duckduckbot',
            'baiduspider',
            'yandexbot',
            'sogou',
            'exabot',
            'facebot',
            'ia_archiver',     // Alexa
            
            // Social media bots
            'facebookexternalhit',
            'twitterbot',
            'linkedinbot',
            'pinterest',
            'slackbot',
            'telegrambot',
            'whatsapp',
            'discordbot',
            
            // SEO and monitoring tools
            'semrushbot',
            'ahrefsbot',
            'mj12bot',         // Majestic
            'dotbot',
            'rogerbot',        // Moz
            'screaming frog',
            'seokicks',
            'sistrix',
            'blexbot',
            'dataforseo',
            
            // Generic bot indicators
            'bot',
            'crawl',
            'spider',
            'scraper',
            'fetch',
            'curl',
            'wget',
            'python-requests',
            'python-urllib',
            'java/',
            'libwww',
            'httpclient',
            'go-http-client',
            'headlesschrome',
            'phantomjs',
            'selenium',
            'puppeteer',
            
            // Other known bots
            'applebot',
            'petalbot',
            'bytespider',
            'gptbot',
            'claudebot',
            'anthropic',
            'ccbot',
            'archive.org_bot',
            'mediapartners-google',
            'adsbot-google',
            'apis-google',
        ];
        
        foreach ($bot_patterns as $pattern) {
            if (strpos($user_agent_lower, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Track visitor for analytics
     */
    private function track_visitor(): void {
        // Skip bots — no geo or analytics value
        $user_agent = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($this->is_bot($user_agent)) {
            return;
        }

        $ip = $this->get_client_ip();
        
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$this->db->visitors} WHERE ip_address = %s",
            $ip
        ));
        
        if ($exists) {
            // Update visit count and last_visit (last_visit auto-updates via ON UPDATE CURRENT_TIMESTAMP)
            $result = $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->db->visitors} SET visit_count = visit_count + 1, last_visit = NOW() WHERE ip_address = %s",
                $ip
            ));
            $this->logger->debug('Visitor updated', ['ip' => $ip, 'result' => $result]);
        } else {
            // Get geo data if available
            $geo = $this->get_geo_data($ip);
            
            $result = $this->wpdb->insert($this->db->visitors, [
                'ip_address' => $ip,
                'country_code' => $geo['country_code'] ?? null,
                'country_name' => $geo['country_name'] ?? null,
                'region' => $geo['region'] ?? null,
                'city' => $geo['city'] ?? null,
                'latitude' => $geo['latitude'] ?? null,
                'longitude' => $geo['longitude'] ?? null,
                'first_visit' => current_time('mysql'),
                'last_visit' => current_time('mysql'),
            ]);
            
            if ($result === false) {
                $this->logger->error('Failed to insert visitor', [
                    'error' => $this->wpdb->last_error,
                    'ip' => $ip
                ]);
            } else {
                $this->logger->debug('New visitor tracked', ['ip' => $ip, 'id' => $this->wpdb->insert_id]);
            }
        }
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip(): string {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = explode(',', $_SERVER[$key])[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '127.0.0.1';
    }
    
    /**
     * Get geo location data for IP
     */
    private function get_geo_data(string $ip): array {
        $empty = [
            'country_code' => null, 'country_name' => null,
            'region' => null, 'city' => null,
            'latitude' => null, 'longitude' => null,
        ];

        // Skip private/reserved IP ranges — no geo data possible
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $empty;
        }

        // Cache result for 24h per IP to avoid hitting rate limits
        $cache_key = 'cs_geo_' . md5($ip);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // ip-api.com: free, no key required, 45 req/min limit
        // Fields: status,countryCode,country,regionName,city,lat,lon
        $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,countryCode,country,regionName,city,lat,lon';

        $response = wp_remote_get($url, [
            'timeout'   => 3,
            'sslverify' => false,
            'user-agent'=> 'CleverSay/' . CLEVERSAY_VERSION,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient($cache_key, $empty, HOUR_IN_SECONDS);
            return $empty;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body) || ($body['status'] ?? '') !== 'success') {
            set_transient($cache_key, $empty, HOUR_IN_SECONDS);
            return $empty;
        }

        $geo = [
            'country_code' => sanitize_text_field($body['countryCode'] ?? ''),
            'country_name' => sanitize_text_field($body['country']     ?? ''),
            'region'       => sanitize_text_field($body['regionName']  ?? ''),
            'city'         => sanitize_text_field($body['city']        ?? ''),
            'latitude'     => isset($body['lat']) ? (float) $body['lat'] : null,
            'longitude'    => isset($body['lon']) ? (float) $body['lon'] : null,
        ];

        // Cache for 24 hours — IP location rarely changes
        set_transient($cache_key, $geo, DAY_IN_SECONDS);
        return $geo;
    }
    
    /**
     * Enable debug mode
     */
    public function enable_debug(bool $debug = true): void {
        $this->debug = $debug;
    }
    
    /**
     * Test search (admin mode - no logging, full debug output)
     * 
     * @param string $question The question to test
     * @return array Detailed debug information and results
     */
    public function test_search(string $question): array {
        try {
            return $this->do_test_search($question);
        } catch (\Exception $e) {
            error_log('CleverSay test_search exception: ' . $e->getMessage());
            return [
                'success' => false,
                'process' => [
                    ['step' => 0, 'description' => 'Error', 'result' => 'Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()]
                ],
                'matches' => [],
                'suggested' => [],
            ];
        } catch (\Error $e) {
            error_log('CleverSay test_search error: ' . $e->getMessage());
            return [
                'success' => false,
                'process' => [
                    ['step' => 0, 'description' => 'Error', 'result' => 'Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()]
                ],
                'matches' => [],
                'suggested' => [],
            ];
        }
    }
    
    private function do_test_search(string $question): array {
        $original_question = $question;
        $process_steps = [];
        $synonym_replacements = [];
        
        // Step 0: Database diagnostic
        $total_entries = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->db->knowledge_base}");
        $active_entries = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->db->knowledge_base} WHERE status = 'active'");
        $hold_entries = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->db->knowledge_base} WHERE status = 'hold'");
        $inactive_entries = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->db->knowledge_base} WHERE status = 'inactive'");
        
        $db_info = "Total: {$total_entries}, Active: {$active_entries}, Hold: {$hold_entries}, Inactive: {$inactive_entries}";
        
        // Get sample keywords
        $sample = $this->wpdb->get_results("SELECT keyword, status FROM {$this->db->knowledge_base} LIMIT 5", ARRAY_A);
        if (!empty($sample)) {
            $sample_list = array_map(fn($r) => "{$r['keyword']} ({$r['status']})", $sample);
            $db_info .= ". Sample: " . implode(', ', $sample_list);
        }
        
        $process_steps[] = [
            'step' => 0,
            'description' => __('Database Status', 'cleversay'),
            'result' => $db_info,
        ];

        // Run the shared pipeline — with debug mode on so we get process_steps back
        $pipeline     = $this->process_query($question, true);
        $words        = $pipeline['words'];
        $question     = $pipeline['question'];
        $process_steps = array_merge($process_steps, $pipeline['steps']);
        
        // Step 10: Find matches (without updating hit counts)
        $matches_result = $this->find_matches_no_update($words, $original_question);
        
        // Add debug info about the search
        if (!empty($matches_result['debug'])) {
            $process_steps[] = [
                'step' => count($process_steps) + 1,
                'description' => __('Search debug', 'cleversay'),
                'result' => $matches_result['debug'],
            ];
        }
        
        // Format matched keywords with scores
        $matched_entries = [];
        foreach ($matches_result['matches'] as $match) {
            $matched_entries[] = [
                'id' => (int)$match['id'],
                'score' => (int)$match['score'],
                'keyword' => $match['keyword'],
                'sub_keyword' => $match['sub_keyword'] ?? '',
                'question' => $match['question'],
                'response' => $match['response'],
                'updated_at' => $match['updated_at'] ?? null,
            ];
        }
        
        if (!empty($matched_entries)) {
            $process_steps[] = [
                'step' => count($process_steps) + 1,
                'description' => sprintf(__('Matched %d keyword(s)', 'cleversay'), count($matched_entries)),
                'matches' => $matched_entries,
            ];
        }
        
        // Get related questions (other entries matching same keywords)
        $exclude_id = isset($matched_entries[0]['id']) ? (int)$matched_entries[0]['id'] : 0;
        $related = $this->get_related_questions($words, $exclude_id);
        
        // Build response
        $result = [
            'success' => !empty($matches_result['matches']),
            'original_question' => $original_question,
            'process' => $process_steps,
            'matched_keywords' => array_unique($words),
            'matches' => $matched_entries,
            'related' => $related,
            'suggested' => $matches_result['suggested'] ?? [],
        ];
        
        // If we have a match, include the primary response
        if (!empty($matched_entries)) {
            $result['primary_match'] = $matched_entries[0];
        }

        // ── AI Fallback diagnostic ────────────────────────────────────────────
        // When there are no KB matches, show whether AI would (or did) fire.
        $best_score   = (int) ($matches_result['matches'][0]['score'] ?? 0);
        $has_matches  = !empty($matched_entries);
        $is_broad_only = $has_matches && $best_score <= 50;
        $ai_would_fire = !$has_matches || $is_broad_only;

        $ai_status = [];
        $ai_obj    = new AI();
        $ai_enabled    = (bool) get_option('cleversay_ai_enabled', false);
        $ai_configured = $ai_obj->is_configured();

        if (!$ai_enabled) {
            $ai_status['status'] = 'disabled';
            $ai_status['message'] = __('AI is disabled in Settings → AI Settings.', 'cleversay');
        } elseif (!$ai_configured) {
            $ai_status['status'] = 'not_configured';
            $ai_status['message'] = __('AI is enabled but not fully configured (check API key).', 'cleversay');
        } elseif (!$ai_would_fire) {
            $ai_status['status'] = 'not_needed';
            $ai_status['message'] = sprintf(
                __('KB match found (score %d) — AI not needed.', 'cleversay'),
                $best_score
            );
        } else {
            // AI would fire — test chunk retrieval
            $indexer    = new Indexer();
            $chunks     = [];
            try {
                $chunks = $indexer->find_relevant_chunks($question);
            } catch (\Throwable $e) {
                $chunks = $indexer->find_relevant_chunks_simple($question);
            }

            if (empty($chunks)) {
                $ai_status['status']  = 'no_chunks';
                $ai_status['message'] = __('AI would fire but found no indexed source chunks for this question. Index your sources under AI Sources.', 'cleversay');
            } else {
                $ai_status['status']       = 'would_fire';
                $ai_status['chunk_count']  = count($chunks);
                $ai_status['source_titles'] = array_unique(array_filter(array_column($chunks, 'source_title')));
                $ai_status['message']      = sprintf(
                    __('AI fallback would fire using %d chunk(s) from: %s', 'cleversay'),
                    count($chunks),
                    implode(', ', $ai_status['source_titles']) ?: __('(untitled sources)', 'cleversay')
                );
            }
        }

        $process_steps[] = [
            'step'        => count($process_steps) + 1,
            'description' => __('AI Fallback Status', 'cleversay'),
            'result'      => $ai_status['message'],
            'ai_status'   => $ai_status['status'],
        ];

        $result['process']    = $process_steps;
        $result['ai_status']  = $ai_status;

        return $result;
    }
    
    /**
     * Apply built-in phrase synonyms with tracking
     */
    private function apply_builtin_phrase_synonyms_with_tracking(string $question, array &$replacements): string {
        foreach ($this->builtin_synonyms as $canonical => $variants) {
            foreach ($variants as $variant) {
                // Only process multi-word variants at phrase level
                if (strpos($variant, ' ') !== false) {
                    if (stripos($question, $variant) !== false) {
                        $replacements[] = [
                            'from' => $variant,
                            'to' => $canonical,
                            'type' => 'built-in phrase',
                        ];
                        $question = str_ireplace($variant, $canonical, $question);
                    }
                }
            }
        }
        return $question;
    }
    
    /**
     * Apply built-in word synonyms with tracking
     * 
     * IMPORTANT: Only applies synonyms if the word is NOT already a keyword in the database
     */
    private function apply_builtin_synonyms_with_tracking(array $words, array &$replacements): array {
        $result = [];
        
        // Get all keywords from database to check against
        $db_keywords = $this->get_database_keywords();
        
        foreach ($words as $word) {
            $word_lower = strtolower($word);
            
            // FIRST: Check if this word already exists as a keyword in the database
            // If it does, keep it as-is - don't replace with a synonym
            if ($this->word_matches_database_keyword($word_lower, $db_keywords)) {
                $result[] = $word;
                continue;
            }
            
            $matched = false;
            
            // Check if this word is a variant of any canonical word
            foreach ($this->builtin_synonyms as $canonical => $variants) {
                if ($word_lower === $canonical) {
                    // Already canonical, keep it
                    $result[] = $word;
                    $matched = true;
                    break;
                }
                
                foreach ($variants as $variant) {
                    // Skip multi-word variants (handled at phrase level)
                    if (strpos($variant, ' ') !== false) {
                        continue;
                    }
                    
                    if (strtolower($variant) === $word_lower) {
                        $replacements[] = [
                            'from' => $word,
                            'to' => $canonical,
                            'type' => 'built-in synonym',
                        ];
                        $result[] = $canonical;
                        $matched = true;
                        break 2;
                    }
                }
            }
            
            if (!$matched) {
                $result[] = $word;
            }
        }
        
        return $result;
    }
    
    /**
     * Apply synonyms with tracking of what was replaced
     */
    private function apply_synonyms_with_tracking(string $question, array &$replacements): string {
        if (empty($this->synonyms)) {
            return $question;
        }
        
        // Word-level synonyms
        $words = explode(' ', $question);
        $new_words = [];
        
        foreach ($words as $word) {
            if (empty($word)) {
                continue;
            }
            
            $replaced = false;
            
            foreach ($this->synonyms as $syn) {
                if ((int)($syn['is_phrase'] ?? 0) === 1) {
                    continue; // Handle phrases separately
                }
                
                $variants = array_filter(array_map('trim', explode(',', $syn['variant_words'] ?? '')));
                $misspellings = array_filter(array_map('trim', explode(',', $syn['misspellings'] ?? '')));
                $all_variants = array_merge($variants, $misspellings);
                
                foreach ($all_variants as $variant) {
                    if (!empty($variant) && strcasecmp($word, $variant) === 0) {
                        $replacements[] = [
                            'from' => $word,
                            'to' => $syn['canonical_word'],
                            'type' => 'synonym',
                        ];
                        $new_words[] = $syn['canonical_word'];
                        $replaced = true;
                        break 2;
                    }
                }
            }
            
            if (!$replaced) {
                $new_words[] = $word;
            }
        }
        
        $question = implode(' ', $new_words);
        
        // Phrase-level synonyms
        foreach ($this->synonyms as $syn) {
            if ((int)($syn['is_phrase'] ?? 0) !== 1) {
                continue;
            }
            
            $variants = array_filter(array_map('trim', explode(',', $syn['variant_words'] ?? '')));
            $misspellings = array_filter(array_map('trim', explode(',', $syn['misspellings'] ?? '')));
            $all_variants = array_merge($variants, $misspellings);
            
            foreach ($all_variants as $variant) {
                if (!empty($variant) && stripos($question, $variant) !== false) {
                    $replacements[] = [
                        'from' => $variant,
                        'to' => $syn['canonical_word'],
                        'type' => 'phrase',
                    ];
                    $question = str_ireplace($variant, $syn['canonical_word'], $question);
                }
            }
        }
        
        return $question;
    }
    
    /**
     * Find matches without updating hit counts (for testing/admin)
     * Uses the same core search as public to ensure consistent results
     */
    private function find_matches_no_update(array $words, string $original_question): array {
        $max_results = (int) get_option('cleversay_max_results', 5);
        if ($max_results < 1) $max_results = 5;
        
        $min_score = (int) get_option('cleversay_min_match_score', 50);
        if ($min_score < 1) $min_score = 50;
        
        // Database status info for debug
        $total_count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->db->knowledge_base}");
        $active_count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->db->knowledge_base} WHERE status = 'active'");
        $debug = "Database: {$active_count} active entries (of {$total_count} total). ";
        $debug .= "Min score threshold: {$min_score}. ";
        
        // Show sample of keywords in database
        $sample_keywords = $this->wpdb->get_col("SELECT DISTINCT keyword FROM {$this->db->knowledge_base} WHERE status = 'active' LIMIT 5");
        if (!empty($sample_keywords)) {
            $debug .= "Sample keywords in DB: " . implode(', ', $sample_keywords) . ". ";
        }
        
        // Use the SAME core search method as public search
        $result = $this->do_core_search($words, $original_question, $max_results, $min_score);
        $matches = $result['matches'];
        $debug .= $result['debug'];
        
        // Get suggested questions if no matches
        $suggested = [];
        if (empty($matches)) {
            $suggested = $this->get_suggestions($words ?: $this->tokenize($original_question));
            if (!empty($suggested)) {
                $debug .= "Found " . count($suggested) . " suggestions. ";
            }
        }
        
        return [
            'matches' => array_values($matches),
            'suggested' => $suggested,
            'debug' => $debug,
        ];
    }
    
    /**
     * Get related questions (for "You may also be interested in...")
     */
    /**
     * Get related questions ranked by relevance to the user\'s query.
     *
     * Improvements over simple LIKE matching:
     *   - Weighted scoring (exact keyword > keyword prefix > sub-keyword contents)
     *   - Log-scaled popularity so viral questions don\'t crowd out on-topic ones
     *   - Diversity enforcement — max 2 per keyword so results aren\'t all the
     *     same sub-topic
     *   - Minimum score threshold — drop loose/coincidental matches entirely
     *     rather than padding the list
     */
    private function get_related_questions(array $words, int $exclude_id = 0): array {
        if (empty($words)) return [];

        // Filter out very short words — they create too many false matches
        $words = array_values(array_filter($words, fn($w) => strlen(trim($w)) >= 3));
        if (empty($words)) return [];

        // ── Fetch a wider candidate pool than we\'ll ultimately return ──────
        // We over-fetch (30) so PHP scoring can be selective.
        $conditions = [];
        foreach ($words as $word) {
            $esc = $this->wpdb->esc_like($word);
            $conditions[] = $this->wpdb->prepare(
                "(keyword LIKE %s OR sub_keyword LIKE %s)",
                '%' . $esc . '%',
                '%' . $esc . '%'
            );
        }
        $where   = implode(' OR ', $conditions);
        $exclude = $exclude_id > 0 ? $this->wpdb->prepare(" AND id != %d", $exclude_id) : '';

        $candidates = $this->wpdb->get_results(
            "SELECT id, keyword, sub_keyword, question, response, hits
             FROM {$this->db->knowledge_base}
             WHERE status = 'active'
               AND (expires_at IS NULL OR expires_at > CURDATE())
               AND ({$where})
               {$exclude}
             LIMIT 30",
            ARRAY_A
        );

        if (empty($candidates)) return [];

        // ── Score each candidate ─────────────────────────────────────────
        $words_lower = array_map('strtolower', $words);
        $scored = [];

        foreach ($candidates as $c) {
            // Skip entries with empty responses
            $response_stripped = preg_replace(
                '/[\s\x{00A0}]+/u',
                '',
                strip_tags(html_entity_decode($c['response'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            );
            if (empty($response_stripped)) continue;

            $kw     = strtolower($c['keyword']     ?? '');
            $sub    = strtolower($c['sub_keyword'] ?? '');
            $score  = 0;

            foreach ($words_lower as $w) {
                // Exact keyword match — strongest signal of relevance
                if ($kw === $w) {
                    $score += 10;
                }
                // Query word appears inside keyword (e.g. query "park" → kw "parking")
                elseif ($kw !== '' && strpos($kw, $w) !== false) {
                    $score += 5;
                }
                // Query word appears in sub-keyword pattern
                if ($sub !== '' && strpos($sub, $w) !== false) {
                    $score += 2;
                }
            }

            // Reject candidates that only matched on trivial overlap
            if ($score < 3) continue;

            // Log-scaled popularity tiebreaker — a 1000-hit entry gets +3,
            // a 10-hit entry gets +1, rather than 1000× vs 10×
            $score += log10(max(1, (int)$c['hits']) + 1);

            $scored[] = [
                'id'       => (int)$c['id'],
                'keyword'  => $c['keyword'],
                'question' => $c['question'],
                'score'    => $score,
            ];
        }

        if (empty($scored)) return [];

        // Sort by score descending
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // ── Enforce variety: max 2 per keyword ───────────────────────────
        // Prevents 5 results all about "parking" when the question mentions parking.
        $per_keyword = [];
        $final       = [];
        foreach ($scored as $r) {
            $kw_key = strtolower($r['keyword']);
            if (($per_keyword[$kw_key] ?? 0) >= 2) continue;
            $per_keyword[$kw_key] = ($per_keyword[$kw_key] ?? 0) + 1;
            $final[] = [
                'id'       => $r['id'],
                'keyword'  => $r['keyword'],
                'question' => $r['question'],
            ];
            if (count($final) >= 5) break;
        }

        return $final;
    }
    
    /**
     * Test if a phrase matches a keyword + pattern combination
     * Used for validation when saving keywords
     * 
     * @param string $keyword The main keyword
     * @param string $pattern The sub_keyword pattern (e.g., "different" or "do+not+have|don+t")
     * @param string $phrase The phrase to test
     * @return array ['matched' => bool, 'reason' => string, 'details' => array]
     */
    public function test_pattern_match(string $keyword, string $pattern, string $phrase): array {
        $result = [
            'matched' => false,
            'reason'  => '',
            'details' => [],
        ];

        // Use the full shared pipeline so test results are identical to
        // what the live chatbot would do (stemming, spell-check, synonyms, etc.)
        $pipeline = $this->process_query($phrase);
        $words    = $pipeline['words'];

        $result['details']['normalized']      = $pipeline['question'];
        $result['details']['processed_words'] = $words;
        $words_lower   = array_map('strtolower', $words);
        $keyword_lower = strtolower($keyword);
        $pattern_lower = strtolower($pattern);

        // Also stem the keyword so "housing" → "house" matches the processed words
        // (the pipeline stems the phrase words, so we must stem the keyword too)
        $stemmed_keyword = $this->apply_stemming([$keyword_lower]);
        $stemmed_keyword = strtolower($stemmed_keyword[0] ?? $keyword_lower);

        // Step 1: Check if keyword matches — try both original and stemmed form
        $keyword_matched = $this->matches_keyword_pattern($keyword_lower, $words_lower)
                        || ($stemmed_keyword !== $keyword_lower
                            && $this->matches_keyword_pattern($stemmed_keyword, $words_lower));

        if (!$keyword_matched) {
            $result['reason'] = sprintf(
                __('Phrase does not contain the keyword "%s" (found: %s). For variations that use a synonym instead of the keyword, add an entry to the Synonyms table mapping the synonym to "%s".', 'cleversay'),
                $keyword,
                implode(', ', $words),
                $keyword
            );
            return $result;
        }
        
        $result['details']['keyword_matched'] = true;
        
        // Step 2: Check pattern (if not aadefault)
        if ($pattern_lower === 'aadefault' || empty($pattern)) {
            // For aadefault, just keyword match is enough
            $result['matched'] = true;
            $result['reason'] = __('Matched (default pattern)', 'cleversay');
            return $result;
        }
        
        // Check sub_keyword pattern
        if (!$this->matches_sub_keyword($pattern, $words_lower, $phrase)) {
            // Provide detailed reason
            $or_groups = explode('|', $pattern_lower);
            $group_results = [];
            
            foreach ($or_groups as $or_group) {
                $or_group = trim($or_group);
                if (empty($or_group)) continue;
                
                $and_parts = preg_split('/[&+]/', $or_group);
                $parts_status = [];
                
                foreach ($and_parts as $part) {
                    $part = trim($part);
                    if (empty($part)) continue;
                    
                    $part_matched = $this->matches_keyword_pattern($part, $words_lower);
                    $parts_status[] = $part . ': ' . ($part_matched ? '✓' : '✗');
                }
                
                $group_results[] = '(' . implode(' AND ', $parts_status) . ')';
            }
            
            $result['reason'] = sprintf(
                __('Pattern not matched. Required: %s. Status: %s', 'cleversay'),
                $pattern,
                implode(' OR ', $group_results)
            );
            return $result;
        }
        
        $result['matched'] = true;
        $result['reason'] = __('Pattern matched successfully', 'cleversay');
        return $result;
    }
}
