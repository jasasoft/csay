<?php
/**
 * Citation evidence selection — Stage 1 implementation.
 *
 * Decides which retrieved chunks should be cited in the answer's
 * Sources panel. Uses a two-tier router pattern:
 *
 *   1. Compute heuristic overlap scores per chunk (deterministic,
 *      cheap — phrase + content-word matching against the answer)
 *
 *   2. Route decision based on score distribution:
 *        - "heuristic" route: one chunk dominates by margin → trust
 *          the heuristic, skip the LLM call (fast path)
 *        - "llm" route: multiple chunks compete or all score low →
 *          run Step A LLM call to pick deterministically
 *
 *   3. Step A failure → fallback to heuristic selection (NOT
 *      "show all chunks" — that would silently regress to noise).
 *      Logged so we know when LLM mode is degrading.
 *
 * Output: per-chunk decision { used_in_answer, route, score, llm_score }
 * which the caller stores in cleversay_ai_answer_sources rows.
 *
 * Design references: see chat history v4.37.111 design discussion —
 * specifically the user's three observations about ranking-not-
 * classification, dangerous unbounded fallback, and gating discipline.
 *
 * @since 4.37.111
 */

namespace CleverSay;

class CitationSelector {

    /** Heuristic score threshold for being "cited" via heuristic route */
    private const HEURISTIC_THRESHOLD = 5;

    /**
     * Heuristic score above which we consider a chunk a "clear winner."
     * If only one chunk reaches this AND no other chunk is within the
     * margin (TOP_MARGIN below), we skip Step A and trust heuristics.
     */
    private const HEURISTIC_DOMINANT_SCORE = 10;

    /**
     * If a second-place chunk scores within this fraction of the top,
     * the result is "ambiguous" — multiple chunks compete. Run Step A
     * to discriminate.
     *
     * Example: top=12, second=11 → second/top = 0.92 → ambiguous
     *          top=12, second=4  → second/top = 0.33 → clear winner
     */
    private const AMBIGUITY_RATIO = 0.5;

    /** Step A LLM-confidence cutoff to consider a chunk cited */
    private const LLM_CITATION_THRESHOLD = 0.5;

    /** Maximum citations to keep in the panel, regardless of route */
    private const MAX_CITATIONS = 3;

    private AI $ai;
    private $logger;

    public function __construct() {
        $this->ai = new AI();
        $this->logger = function_exists('cleversay_logger')
            ? cleversay_logger()
            : null;
    }

    /**
     * Select citation evidence for an answer.
     *
     * @param array $chunks The retrieved chunks (from Indexer::retrieve)
     * @param string $question The user's original question
     * @param string $answer The LLM's synthesized answer text
     * @return array Per-chunk decision array, keyed by source_id (deduped),
     *               with shape:
     *                 [
     *                   source_id => [
     *                     'chunk_id'       => int,
     *                     'used_in_answer' => 0|1,
     *                     'overlap_score'  => int,
     *                     'route_used'     => 'heuristic'|'llm'|'fallback',
     *                     'llm_score'      => float|null,
     *                     'position'       => int (FULLTEXT order)
     *                   ],
     *                   ...
     *                 ]
     */
    public function select(array $chunks, string $question, string $answer): array {
        // Dedupe chunks to one per source_id (citation panel shows
        // one row per source, not per chunk). Keep the FIRST chunk
        // encountered (FULLTEXT-top by position).
        $deduped = [];
        $position = 0;
        foreach ($chunks as $chunk) {
            $sid = (int) ($chunk['source_id'] ?? 0);
            if ($sid <= 0 || isset($deduped[$sid])) continue;
            $deduped[$sid] = [
                'chunk'    => $chunk,
                'position' => $position++,
            ];
        }
        if (empty($deduped)) return [];

        // Phase 1: heuristic overlap scoring (separate phrase + word)
        $answer_phrases = $this->extract_phrases($answer);
        $answer_words   = $this->extract_words($answer);

        foreach ($deduped as $sid => &$entry) {
            $details = $this->compute_overlap_details(
                (string) ($entry['chunk']['content'] ?? ''),
                $answer_phrases,
                $answer_words
            );
            $entry['phrase_score'] = $details['phrase_score'];
            $entry['phrase_count'] = $details['phrase_count'];
            $entry['word_score']   = $details['word_score'];
            $entry['overlap_score'] = $details['total'];
        }
        unset($entry);

        // Phase 2: router decision — based on phrase scores, not totals.
        // Phrase matches are the discriminating signal; word-only
        // matches are too noisy to drive routing.
        $route = $this->decide_route($deduped);

        // Phase 3: apply selection per route
        if ($route === 'heuristic') {
            return $this->select_heuristic($deduped);
        }

        // LLM route
        $result = $this->select_via_llm($deduped, $question, $answer);
        if ($result !== null) {
            return $result;
        }

        // Step A failed → fallback to heuristic. NOT "use all" —
        // that silently regresses to the noise we're trying to filter.
        if ($this->logger) {
            $this->logger->warning('Citation Step A failed, falling back to heuristic', [
                'question' => substr($question, 0, 100),
                'chunk_count' => count($deduped),
            ]);
        }
        return $this->select_heuristic($deduped, 'fallback');
    }

    /**
     * Decide which route handles this query. Returns 'heuristic' or 'llm'.
     *
     * v4.37.113: stricter routing. Heuristic is reliable ONLY when
     * exactly one chunk has phrase matches. As soon as a second chunk
     * has any phrase match, ambiguity is real — that secondary phrase
     * match could be a genuine contribution OR coincidental phrase
     * overlap (e.g., a related-topic page using the same domain
     * phrasings as the answer). Heuristics can't distinguish; LLM
     * route discriminates.
     *
     * Examples:
     *   - chunk_1 has phrases, chunks 2-3 don't → heuristic (no
     *     ambiguity; only chunk_1 will be cited regardless)
     *   - chunks 1, 2 both have phrases → LLM (must discriminate)
     *   - no chunk has phrases (heavy paraphrase) → LLM
     */
    private function decide_route(array $deduped): string {
        // Count chunks with any phrase match
        $with_phrases = 0;
        foreach ($deduped as $entry) {
            if (($entry['phrase_score'] ?? 0) > 0) {
                $with_phrases++;
                if ($with_phrases >= 2) break;
            }
        }

        if ($with_phrases === 0) {
            // No phrase matches anywhere — heavy paraphrase. LLM
            // discriminates whether any chunk actually contributed.
            return 'llm';
        }
        if ($with_phrases >= 2) {
            // Multiple chunks have phrase matches. Heuristic cannot
            // tell which actually contributed vs which has incidental
            // domain-phrase overlap. LLM discriminates.
            return 'llm';
        }
        // Exactly one chunk has phrase matches → it's the unambiguous
        // answer source. Heuristic cites only it.
        return 'heuristic';
    }

    /**
     * Heuristic-only selection. Used when one chunk dominates clearly,
     * OR as fallback when Step A errors out.
     *
     * v4.37.112: cite condition tightened — requires at least one
     * phrase match (phrase_score >= 5). Word-only matches no longer
     * qualify for citation; they're too noisy (a chunk sharing
     * domain vocabulary will score on word matches without having
     * actually contributed).
     *
     * @param string $route_label 'heuristic' (normal) or 'fallback'
     */
    private function select_heuristic(array $deduped, string $route_label = 'heuristic'): array {
        $out = [];
        foreach ($deduped as $sid => $entry) {
            $phrase_score = (int) ($entry['phrase_score'] ?? 0);
            $total_score  = (int) ($entry['overlap_score'] ?? 0);
            // Phrase requirement: must have at least one phrase match
            // (worth +5) to be cited via heuristic. Pure word-overlap
            // chunks are domain-vocabulary noise.
            $is_used = $phrase_score >= 5 ? 1 : 0;
            $out[$sid] = [
                'chunk_id'       => (int) ($entry['chunk']['id'] ?? 0),
                'used_in_answer' => $is_used,
                'overlap_score'  => $total_score,
                'route_used'     => $route_label,
                'llm_score'      => null,
                'position'       => (int) $entry['position'],
            ];
        }
        return $this->cap_used_to_max($out);
    }

    /**
     * Step A: ask the LLM to score each chunk's relevance to the answer.
     *
     * Returns the per-source decision array on success, OR null on
     * any failure (parse error, LLM returned nothing, etc.) — caller
     * should fall back to heuristic.
     */
    private function select_via_llm(array $deduped, string $question, string $answer): ?array {
        if (!$this->ai->is_configured()) return null;

        // Build the prompt. Each chunk gets a numeric ID (1-based) so
        // the LLM can reference them in its JSON response without
        // having to handle our DB IDs.
        $numbered_chunks = [];
        $sid_by_num = [];
        $num = 1;
        foreach ($deduped as $sid => $entry) {
            $content = (string) ($entry['chunk']['content'] ?? '');
            // Truncate each chunk to keep the selection prompt focused
            // and within reasonable token budget. The LLM doesn't need
            // the whole chunk to judge relevance to a generated answer.
            if (strlen($content) > 1500) {
                $content = substr($content, 0, 1500) . '...';
            }
            $numbered_chunks[] = "[CHUNK $num]\n" . $content;
            $sid_by_num[$num] = $sid;
            $num++;
        }
        $chunks_text = implode("\n\n", $numbered_chunks);

        $system = "You are a citation evaluator. Given a question, an answer, and the candidate source chunks that were retrieved, your job is to decide which chunks the answer ACTUALLY drew content from.\n\n"
                . "Output strict JSON only, no preamble. Format:\n"
                . '{"selected": [{"chunk": <int>, "score": <0.0-1.0>}, ...]}' . "\n\n"
                . "Rules:\n"
                . "- 'score' is your confidence (0.0-1.0) that this chunk contributed to the answer\n"
                . "- Score >= 0.5 means the chunk's content appears in the answer (verbatim or paraphrased)\n"
                . "- Score < 0.5 means the chunk shares vocabulary but didn't contribute\n"
                . "- Only list chunks with score >= 0.3 (skip irrelevant ones entirely)\n"
                . "- Return at most 3 chunks; pick the most clearly contributing ones\n"
                . "- If NO chunk contributed (the answer is generic/deflective), return {\"selected\": []}";

        $user = "QUESTION: " . $question . "\n\n"
              . "ANSWER:\n" . $answer . "\n\n"
              . "CANDIDATE CHUNKS:\n" . $chunks_text;

        $start_time = microtime(true);
        $response = $this->ai->call_for_text($user, [
            'system'      => $system,
            'max_tokens'  => 500,
            'temperature' => 0.0,
        ]);
        $elapsed_ms = (int) round((microtime(true) - $start_time) * 1000);

        if ($this->logger) {
            $this->logger->info('Citation Step A completed', [
                'elapsed_ms'  => $elapsed_ms,
                'chunk_count' => count($deduped),
            ]);
        }

        if ($response === '') return null;

        // Parse JSON. Be lenient about wrapper text — some models
        // include stray prose despite instructions.
        $json_start = strpos($response, '{');
        $json_end   = strrpos($response, '}');
        if ($json_start === false || $json_end === false || $json_end <= $json_start) {
            return null;
        }
        $json_str = substr($response, $json_start, $json_end - $json_start + 1);
        $parsed = json_decode($json_str, true);
        if (!is_array($parsed) || !isset($parsed['selected']) || !is_array($parsed['selected'])) {
            return null;
        }

        // Build LLM scores by source_id (translating chunk numbers back)
        $llm_scores = [];
        foreach ($parsed['selected'] as $s) {
            $chunk_num = (int) ($s['chunk'] ?? 0);
            $score     = (float) ($s['score'] ?? 0);
            if (!isset($sid_by_num[$chunk_num])) continue;
            $llm_scores[$sid_by_num[$chunk_num]] = $score;
        }

        // Build the output: every dedup entry gets a row, used flag
        // depends on whether it was in the LLM's selection above
        // threshold.
        $out = [];
        foreach ($deduped as $sid => $entry) {
            $llm_score = $llm_scores[$sid] ?? null;
            $is_used = $llm_score !== null && $llm_score >= self::LLM_CITATION_THRESHOLD ? 1 : 0;
            $out[$sid] = [
                'chunk_id'       => (int) ($entry['chunk']['id'] ?? 0),
                'used_in_answer' => $is_used,
                'overlap_score'  => (int) $entry['overlap_score'],
                'route_used'     => 'llm',
                'llm_score'      => $llm_score,
                'position'       => (int) $entry['position'],
            ];
        }
        return $this->cap_used_to_max($out);
    }

    /**
     * Cap the number of "used" citations to MAX_CITATIONS.
     *
     * If more chunks are flagged used than the panel will display,
     * keep the highest-scoring ones (LLM score preferred, then
     * heuristic score, then position).
     */
    private function cap_used_to_max(array $out): array {
        $used_sids = array_filter($out, fn($r) => (int) $r['used_in_answer'] === 1);
        if (count($used_sids) <= self::MAX_CITATIONS) return $out;

        // Rank used entries by (llm_score DESC, overlap_score DESC, position ASC)
        uasort($used_sids, function($a, $b) {
            $a_llm = $a['llm_score'] ?? -1;
            $b_llm = $b['llm_score'] ?? -1;
            if ($a_llm !== $b_llm) return $b_llm <=> $a_llm;
            if ($a['overlap_score'] !== $b['overlap_score']) {
                return $b['overlap_score'] <=> $a['overlap_score'];
            }
            return $a['position'] <=> $b['position'];
        });

        $keepers = array_slice(array_keys($used_sids), 0, self::MAX_CITATIONS, true);
        $keepers_set = array_flip($keepers);

        // Demote all non-keepers to used=0
        foreach ($out as $sid => &$row) {
            if (!isset($keepers_set[$sid])) {
                $row['used_in_answer'] = 0;
            }
        }
        unset($row);
        return $out;
    }

    // ── Heuristic scoring helpers ─────────────────────────────────────────

    public function extract_phrases(string $answer): array {
        $text = wp_strip_all_tags($answer);
        $text = preg_replace('/[*_`]+/', '', $text);
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]+/', ' ', $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));
        $words = $text === '' ? [] : explode(' ', $text);
        $words = array_values(array_filter($words, 'strlen'));
        if (count($words) < 3) return [];

        $stopwords = [
            'a','an','the','and','or','but','if','then','than','that',
            'is','are','was','were','be','been','being',
            'do','does','did','have','has','had',
            'i','my','me','we','our','us','you','your',
            'to','of','in','on','for','with','at','by','from','up','out',
            'can','could','should','would','will','may','might','must',
            'this','these','those','it','its','as','also',
            'about','some','any','all','no','not','so','too',
        ];
        $stopwords_set = array_flip($stopwords);

        $phrases = [];
        for ($i = 0; $i <= count($words) - 3; $i++) {
            $a = $words[$i]; $b = $words[$i + 1]; $c = $words[$i + 2];
            $non_stop = (int) !isset($stopwords_set[$a])
                      + (int) !isset($stopwords_set[$b])
                      + (int) !isset($stopwords_set[$c]);
            if ($non_stop >= 2) {
                $phrases[] = "$a $b $c";
            }
        }
        return array_values(array_unique($phrases));
    }

    public function extract_words(string $answer): array {
        $text = wp_strip_all_tags($answer);
        $text = preg_replace('/[*_`]+/', '', $text);
        $text = strtolower($text);

        $stopwords = [
            'what','when','where','why','who','how','which','that',
            'is','are','was','were','be','been','being',
            'do','does','did','have','has','had',
            'a','an','the','and','or','but','if','then','than',
            'i','my','me','we','our','us','you','your',
            'to','of','in','on','for','with','at','by','from','up','out',
            'can','could','should','would','will','may','might','must',
            'this','these','those','it','its','as','also',
            'about','some','any','all','no','not','so','too',
            // Common LLM-output filler that creates false positives
            'will','need','your','student','students','course',
            'university','school','term','semester','year',
        ];
        $stopwords_set = array_flip($stopwords);

        $words = [];
        if (preg_match_all('/[a-z0-9]+/', $text, $matches)) {
            foreach ($matches[0] as $w) {
                if (strlen($w) < 4) continue;
                if (isset($stopwords_set[$w])) continue;
                $words[$w] = true;
            }
        }
        return array_keys($words);
    }

    /**
     * Compute overlap details between a chunk and the answer.
     *
     * Returns associative array with separated phrase / word scores
     * so callers can require phrase matches as a citation precondition.
     * Phrase matches are strong signal of contribution; word matches
     * are weak (just shared vocabulary).
     *
     * Returns:
     *   [
     *     'phrase_score' => int,   // phrases × 5
     *     'word_score'   => int,   // words × 1
     *     'total'        => int,   // phrase_score + word_score
     *     'phrase_count' => int,   // number of distinct matched phrases
     *   ]
     */
    public function compute_overlap_details(string $chunk_content, array $phrases, array $words): array {
        $out = [
            'phrase_score' => 0,
            'word_score'   => 0,
            'total'        => 0,
            'phrase_count' => 0,
        ];
        if (empty($phrases) && empty($words)) return $out;
        $haystack = strtolower(wp_strip_all_tags($chunk_content));
        if ($haystack === '') return $out;

        $phrase_count = 0;
        foreach ($phrases as $p) {
            $p = (string) $p;
            if (strpos($haystack, $p) !== false) $phrase_count++;
        }
        $out['phrase_count'] = $phrase_count;
        $out['phrase_score'] = $phrase_count * 5;

        // Cast word to string — array_keys returns numeric-looking
        // string keys (like "2026") as int, breaking strpos().
        foreach ($words as $w) {
            $w = (string) $w;
            if (strpos($haystack, $w) !== false) $out['word_score'] += 1;
        }
        $out['total'] = $out['phrase_score'] + $out['word_score'];
        return $out;
    }

    /** Backward-compat wrapper — returns just the total score. */
    public function compute_overlap(string $chunk_content, array $phrases, array $words): int {
        return $this->compute_overlap_details($chunk_content, $phrases, $words)['total'];
    }
}
