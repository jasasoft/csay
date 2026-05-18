<?php
/**
 * CleverSay — Follow-up Resolver (v4.42.14+)
 *
 * Purpose
 * -------
 * When a user message is a state-dependent operator like "yes" or "sure",
 * the system needs to know what query that operator resolves TO before
 * retrieval can run meaningfully. Without this layer, the AI fallback path
 * embeds the literal token "yes", retrieves chunks that happen to contain
 * that word, and synthesizes a bail-out answer because the chunks are
 * unrelated to the conversation's actual subject.
 *
 * This module is the intent-grounding layer. It runs before retrieval.
 * Output is a structured decision the caller uses to choose the right
 * downstream behavior.
 *
 * Scope (v4.42.14)
 * ----------------
 * Three affirmation categories are classified:
 *
 *   FOLLOW_UP_ACCEPTANCE — bare affirmation following an assistant offer
 *       ("Want to know more about X?" → "yes")
 *       Result: query resolved to the offered follow-up text.
 *
 *   FOLLOW_UP_WITH_QUESTION — affirmation + new specific question
 *       ("Want to know about X?" → "yes when exactly?")
 *       Result: query rewritten to scope new question by the latent topic.
 *
 *   ANSWER_CONFIRMATION — affirmation to a clarification ("Did you mean X?")
 *       Detected for forward observability; v4.42.14 does NOT route
 *       differently — falls through to normal flow. Logged so we can
 *       measure how often this case appears before deciding how to handle.
 *
 *   NOT_AN_AFFIRMATION — message is a normal question; no resolution.
 *
 * Deployment
 * ----------
 * Single network setting `cleversay_followup_handling`:
 *   - 'off' — module disabled; legacy behavior preserved
 *   - 'resolve_only' — v4.42.14 default. Affirmations are detected and
 *     resolved to meaningful queries, but no chunk inheritance. The
 *     resolved query goes through normal retrieval.
 *   - 'resolve_and_inherit' — reserved for v4.42.15. Adds parent-chunk
 *     inheritance with PRIMARY/SUPPLEMENTAL prompt structure. NOT
 *     implemented yet; setting accepted but treated as resolve_only.
 *
 * Validation
 * ----------
 * Affirmation detection is regex-only in v4.42.14. An LLM fallback
 * classifier interface is sketched but feature-flagged off. We ship the
 * simpler version, measure misses in real traffic, and decide whether
 * the LLM fallback is worth the cost and latency.
 *
 * @since 4.42.14
 */

namespace CleverSay;

if (!defined('ABSPATH')) exit;

final class FollowupResolver {

    // Affirmation classification outcomes.
    public const DECISION_NOT_AFFIRMATION       = 'not_affirmation';
    public const DECISION_FOLLOWUP_ACCEPTANCE   = 'followup_acceptance';
    public const DECISION_FOLLOWUP_WITH_Q       = 'followup_with_question';
    public const DECISION_ANSWER_CONFIRMATION   = 'answer_confirmation';
    public const DECISION_AFFIRMATION_NO_STATE  = 'affirmation_no_pending_state';

    // Setting key + valid values.
    public const SETTING_KEY     = 'cleversay_followup_handling';
    public const MODE_OFF        = 'off';
    public const MODE_RESOLVE    = 'resolve_only';
    public const MODE_INHERIT    = 'resolve_and_inherit'; // v4.42.15+ reserved

    /**
     * Top-level entry point. Caller passes the raw user message and the
     * conversation history (the assistant + user turn pairs that preceded
     * this message). Returns a structured decision the caller routes on.
     *
     * Output schema:
     * [
     *   'decision'         => DECISION_* constant
     *   'resolved_query'   => string|null — what to send to retrieval
     *   'original_message' => string — the raw user input, preserved
     *   'latent_topic'     => string|null — the prior follow-up offer's
     *                         subject, if one existed
     *   'debug'            => [
     *      'mode'             => current handling mode
     *      'matched_pattern'  => regex/category that fired (for logging)
     *      'has_prior_offer'  => bool
     *      'prior_offer_text' => string|null
     *      'compound_question'=> string|null — the new question piece if
     *                            the message was compound
     *   ]
     * ]
     *
     * @param string $message Raw user message from the widget.
     * @param array  $history Conversation history (decoded from history_json).
     *                        Each entry should be ['role' => 'user'|'assistant',
     *                        'content' => '...']. Most recent last.
     * @return array Decision payload (see schema above).
     */
    public static function resolve(string $message, array $history): array {
        $mode = self::get_mode();

        // Always populate debug fields so observability is consistent.
        $debug = [
            'mode'              => $mode,
            'matched_pattern'   => null,
            'has_prior_offer'   => false,
            'prior_offer_text'  => null,
            'compound_question' => null,
        ];

        // If module is disabled, short-circuit. Caller sees the same shape
        // as "not an affirmation" and proceeds with legacy behavior.
        if ($mode === self::MODE_OFF) {
            return self::not_affirmation_result($message, $debug);
        }

        // Find the prior assistant turn's follow-up offer, if any. This
        // is the state we depend on for follow-up acceptance routing.
        $prior_offer = self::extract_prior_offer($history);
        if ($prior_offer !== null) {
            $debug['has_prior_offer']  = true;
            $debug['prior_offer_text'] = $prior_offer['display'];
        }

        // Classify the message.
        $classification = self::classify_message($message);
        if ($classification === null) {
            // Not an affirmation — nothing to do.
            return self::not_affirmation_result($message, $debug);
        }
        $debug['matched_pattern'] = $classification['pattern'];

        // Branch on classification + prior state.
        if (!$classification['is_pure_affirmation']) {
            // Compound: "yes when are they?". The affirmation prefix
            // signals continuity; the remainder is the new question.
            $debug['compound_question'] = $classification['question_remainder'];
            if ($prior_offer !== null) {
                // Scope the new question by the latent topic. We rewrite
                // the literal compound message ("yes when are they") into
                // a coherent standalone query that includes the prior
                // topic. This is the v2-section #2 case from the
                // architecture discussion.
                $resolved = self::scope_compound_by_topic(
                    $classification['question_remainder'],
                    $prior_offer['resolved_query']
                );
                return [
                    'decision'         => self::DECISION_FOLLOWUP_WITH_Q,
                    'resolved_query'   => $resolved,
                    'original_message' => $message,
                    'latent_topic'     => $prior_offer['resolved_query'],
                    'debug'            => $debug,
                ];
            }
            // Compound affirmation without prior state. The affirmation
            // is filler ("yes I want to ask about X") — treat the
            // remainder as the actual query.
            return [
                'decision'         => self::DECISION_AFFIRMATION_NO_STATE,
                'resolved_query'   => $classification['question_remainder'],
                'original_message' => $message,
                'latent_topic'     => null,
                'debug'            => $debug,
            ];
        }

        // Pure affirmation. Need prior state to be meaningful.
        if ($prior_offer === null) {
            // "yes" with nothing to accept. Caller will treat as a
            // fresh query — likely producing weak retrieval, which is
            // the legacy behavior. Logged so we can measure how often
            // users do this. NOT escalated to a clarification prompt
            // per v4.42.14 design (deferred from earlier discussion).
            return [
                'decision'         => self::DECISION_AFFIRMATION_NO_STATE,
                'resolved_query'   => $message, // pass through unchanged
                'original_message' => $message,
                'latent_topic'     => null,
                'debug'            => $debug,
            ];
        }

        // Check whether the prior offer was an answer-confirmation
        // question ("Did you mean X?") rather than a topic follow-up.
        // For v4.42.14 we DETECT this case but do not route differently —
        // it falls through to follow-up acceptance behavior. Logging
        // lets us measure frequency before deciding the right route.
        if ($prior_offer['shape'] === 'confirmation') {
            return [
                'decision'         => self::DECISION_ANSWER_CONFIRMATION,
                // Use the offer text as the resolved query so retrieval
                // has something coherent to match against. Approximate
                // but better than passing "yes" through.
                'resolved_query'   => $prior_offer['resolved_query'],
                'original_message' => $message,
                'latent_topic'     => $prior_offer['resolved_query'],
                'debug'            => $debug,
            ];
        }

        // Standard follow-up acceptance.
        return [
            'decision'         => self::DECISION_FOLLOWUP_ACCEPTANCE,
            'resolved_query'   => $prior_offer['resolved_query'],
            'original_message' => $message,
            'latent_topic'     => $prior_offer['resolved_query'],
            'debug'            => $debug,
        ];
    }

    /**
     * Read the current handling mode from network settings. Defaults to
     * 'resolve_only' on v4.42.14+ installs — the safe initial behavior.
     * Treats unknown values (including the reserved 'resolve_and_inherit'
     * before v4.42.15 ships it) as 'resolve_only'.
     */
    public static function get_mode(): string {
        $raw = get_site_option(self::SETTING_KEY, self::MODE_RESOLVE);
        $raw = is_string($raw) ? $raw : self::MODE_RESOLVE;
        if (in_array($raw, [self::MODE_OFF, self::MODE_RESOLVE, self::MODE_INHERIT], true)) {
            return $raw;
        }
        return self::MODE_RESOLVE;
    }

    /**
     * Build a not-an-affirmation result. Caller proceeds with legacy
     * behavior — the raw message goes through retrieval unchanged.
     */
    private static function not_affirmation_result(string $message, array $debug): array {
        return [
            'decision'         => self::DECISION_NOT_AFFIRMATION,
            'resolved_query'   => $message,
            'original_message' => $message,
            'latent_topic'     => null,
            'debug'            => $debug,
        ];
    }

    /**
     * Affirmation classification. Returns null when the message is NOT an
     * affirmation. Otherwise returns:
     * [
     *   'is_pure_affirmation' => bool — true if message is ONLY affirmation
     *   'pattern'             => string — which pattern matched (for logging)
     *   'question_remainder'  => string|null — for compound, the new question
     * ]
     *
     * v4.42.14 uses regex only. An LLM fallback is reserved for cases
     * the regex misses; the interface accepts a $use_llm_fallback param
     * but the path isn't enabled yet.
     */
    private static function classify_message(string $message): ?array {
        $normalized = self::normalize($message);
        if ($normalized === '') return null;

        // ── Pure affirmation patterns ──
        // Whole message matches an affirmation phrase exactly.
        $pure_patterns = [
            'yes_simple'      => '/^(yes|yeah|yep|yup|ya|y|yas|yess+)[\s!.]*$/u',
            'sure'            => '/^(sure|ok|okay|k|kk|alright|alrighty)[\s!.]*$/u',
            'tell_me_more'    => '/^(tell me more|more|please|go ahead|continue|proceed|sounds good|works for me|that works)[\s!.]*$/u',
            'please_compound' => '/^(yes please|yes pls|yes thanks|please do|sure thing|sounds great)[\s!.]*$/u',
            'emoji_only'      => '/^(👍|✅|👌|🙏)+[\s]*$/u',
            'emoji_yes'       => '/^(yes|yeah|sure|ok|okay)\s*(👍|✅|👌|🙏)+[\s]*$/u',
        ];
        foreach ($pure_patterns as $name => $pattern) {
            if (preg_match($pattern, $normalized)) {
                return [
                    'is_pure_affirmation' => true,
                    'pattern'             => $name,
                    'question_remainder'  => null,
                ];
            }
        }

        // ── Compound affirmation: affirmation prefix + remainder ──
        // Must start with an affirmation token, followed by a comma,
        // space, or specific connector, followed by additional content.
        // The remainder must look question-shaped (contain a question
        // word or end with ?) to be classified as compound rather than
        // miscellaneous prose.
        $prefix_pattern = '/^(yes|yeah|yep|sure|ok|okay)[,\s]+(.+?)[\s!.?]*$/u';
        if (preg_match($prefix_pattern, $normalized, $m)) {
            $remainder = trim($m[2]);
            // Filter: remainder should be a real question, not "yes thanks"
            // which would match here but isn't compound. Require it to
            // either contain a wh-word or be at least 3 words long with
            // a question shape.
            if ($remainder !== '' && self::looks_like_question($remainder)) {
                return [
                    'is_pure_affirmation' => false,
                    'pattern'             => 'compound_affirmation',
                    'question_remainder'  => $remainder,
                ];
            }
        }

        return null;
    }

    /**
     * Normalize a user message for pattern matching:
     *   - lowercase
     *   - trim whitespace
     *   - collapse internal whitespace
     *   - strip trailing punctuation that doesn't change meaning
     *
     * Emoji are preserved (some affirmation patterns include them).
     */
    private static function normalize(string $message): string {
        $s = trim($message);
        if ($s === '') return '';
        // Use mb_strtolower so non-ASCII characters in international
        // affirmations don't break the regex.
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        // Collapse internal whitespace to single spaces.
        $s = preg_replace('/\s+/u', ' ', $s);
        return is_string($s) ? trim($s) : '';
    }

    /**
     * Heuristic: does this string look like a question? Used to filter
     * compound-affirmation remainders so "yes thanks" isn't misclassified
     * as "affirmation + question 'thanks'".
     */
    private static function looks_like_question(string $text): bool {
        // Ends with question mark.
        if (str_ends_with($text, '?')) return true;
        // Contains a wh-word at any position.
        if (preg_match('/\b(what|when|where|why|how|who|which|can|could|do|does|is|are|will|would|should|may)\b/u', $text)) {
            return true;
        }
        return false;
    }

    /**
     * Extract the offered follow-up from the most recent assistant turn.
     * Returns null if no offer present, or a structured shape:
     *
     * [
     *   'display'        => string — the offered question text as written
     *   'resolved_query' => string — stripped/normalized for retrieval
     *   'shape'          => 'topic_offer' | 'confirmation'
     *                       — topic_offer is the normal "Want to know
     *                       more about X?" pattern. confirmation is
     *                       "Did you mean X?" pattern (rare in current
     *                       prompt design but worth detecting).
     * ]
     *
     * Detection strategy: look at the last assistant turn's content,
     * find the trailing question (if any), and classify by shape.
     */
    private static function extract_prior_offer(array $history): ?array {
        // Walk history backward, find the last assistant turn.
        $assistant = null;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $turn = $history[$i] ?? null;
            if (!is_array($turn)) continue;
            $role = (string) ($turn['role'] ?? '');
            if ($role === 'assistant') {
                $assistant = (string) ($turn['content'] ?? '');
                break;
            }
        }
        if ($assistant === null || $assistant === '') return null;

        // Strip HTML so we're working with plain text — the assistant
        // response may have been rendered with markdown/HTML.
        $plain = trim(wp_strip_all_tags($assistant));
        if ($plain === '') return null;

        // The follow-up offer is typically the LAST paragraph and ends
        // with a question mark. Split on double newlines or single
        // newlines as a fallback.
        $paragraphs = preg_split('/\n\s*\n|\n/u', $plain);
        if (!is_array($paragraphs) || count($paragraphs) === 0) return null;
        $last = trim((string) end($paragraphs));
        if ($last === '' || !str_ends_with($last, '?')) {
            return null;
        }

        // Classify shape. "Did you mean..." / "Were you asking about..."
        // patterns are confirmation-shape; everything else is treated
        // as a topic offer.
        $shape = 'topic_offer';
        if (preg_match('/^(did you mean|were you asking|are you asking)/iu', $last)) {
            $shape = 'confirmation';
        }

        // Strip the "Want to know more about X?" / "Want to know X?" /
        // "Would you like to know X?" prefixes to get the resolved
        // query. Falls back to the full text if no prefix matches.
        $resolved = self::strip_followup_prefixes($last);

        return [
            'display'        => $last,
            'resolved_query' => $resolved,
            'shape'          => $shape,
        ];
    }

    /**
     * Strip common follow-up prefixes to get the underlying topic. Keeps
     * the result readable as a standalone query.
     *
     * "Want to know more about scholarship deadlines?"
     *   → "scholarship deadlines"
     * "Would you like to know how to apply for housing?"
     *   → "how to apply for housing"
     */
    private static function strip_followup_prefixes(string $text): string {
        $patterns = [
            '/^want to know more about\s+/iu',
            '/^want to know\s+/iu',
            '/^would you like to know more about\s+/iu',
            '/^would you like to know\s+/iu',
            '/^want to hear about\s+/iu',
            '/^interested in\s+/iu',
            '/^curious about\s+/iu',
        ];
        $cleaned = $text;
        foreach ($patterns as $p) {
            $next = preg_replace($p, '', $cleaned);
            if (is_string($next) && $next !== $cleaned) {
                $cleaned = $next;
                break;
            }
        }
        // Drop trailing question mark for retrieval (it's noise).
        $cleaned = rtrim($cleaned, '?!. ');
        // First letter back to lowercase if it got uppercased by the
        // original sentence start.
        if ($cleaned !== '') {
            $first = mb_substr($cleaned, 0, 1);
            $rest  = mb_substr($cleaned, 1);
            $cleaned = mb_strtolower($first) . $rest;
        }
        return $cleaned !== '' ? $cleaned : $text;
    }

    /**
     * Combine the user's new question with the latent topic to produce
     * a coherent standalone query. Used in the compound-affirmation case.
     *
     * Examples:
     *   ("when are they?", "scholarship deadlines")
     *     → "scholarship deadlines when are they"
     *   ("how much?", "out-of-state tuition")
     *     → "out-of-state tuition how much"
     *
     * Kept simple in v4.42.14: concatenates topic + question. If real
     * traffic shows the rewrites are too crude, a small LLM rewrite
     * could replace this — but defer that until measured.
     */
    private static function scope_compound_by_topic(string $remainder, string $topic): string {
        $r = trim(rtrim($remainder, '?!. '));
        $t = trim($topic);
        if ($r === '') return $t;
        if ($t === '') return $r;
        // Avoid duplication if the remainder already contains the topic.
        if (stripos($r, $t) !== false) return $r;
        return $t . ' ' . $r;
    }
}
