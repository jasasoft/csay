<?php
/**
 * CleverSay KB Pattern Compiler
 *
 * Converts a list of "question variations" (natural phrasings an admin enters)
 * into a CleverSay pattern using the existing DSL: wildcards (*), AND (&),
 * OR (|), and phrase joining (+).
 *
 * The strategy:
 *
 *   1. Tokenize each variation, lowercase, strip stopwords and punctuation.
 *   2. Stem each token (simple suffix-strip — handles -ing, -ed, -s, -es).
 *   3. Identify SHARED stems (present in ≥ half of variations) — these are
 *      the topic anchors. Multiple shared stems combine with & (AND).
 *   4. Identify VARIANT stems (present in some variations but not all) —
 *      these are intent markers that combine with | (OR).
 *   5. Emit `(intent_or...)&(topic_and...)`.
 *   6. If a topic is multi-word (e.g. "gi bill"), emit it as a + phrase.
 *
 * Example:
 *   Variations:
 *     "Am I eligible for Montgomery GI Bill benefits?"
 *     "Do I qualify for the Montgomery GI Bill?"
 *     "What are the requirements for the GI Bill?"
 *
 *   After stopword removal & stemming:
 *     V1: [eligible, montgomery, gi, bill, benefit]
 *     V2: [qualify, montgomery, gi, bill]
 *     V3: [requirement, gi, bill]
 *
 *   Shared stems (in ≥ 2/3 of variations): gi, bill, montgomery
 *   Variant stems: eligible, qualify, requirement, benefit
 *
 *   Emitted pattern:
 *     (elig*|qualif*|requir*) & (mont*|gi+bill)
 *
 * Failure mode: if all stems are shared OR all stems are variant, fall back
 * to a simple OR-join of all unique stems with wildcards (`stem1*|stem2*|...`).
 *
 * @package CleverSay
 * @since 4.31.0
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class KBPatternCompiler {

    /**
     * Default stopwords used if the database stopwords table isn't available.
     * Keep this list short — it's a fallback for the most universal noise words.
     */
    // v4.37.2+: question words (what, when, where, who, why, how,
    // which) intentionally NOT in this list. See
    // Database::insert_default_stopwords for the rationale — short
    // version: legacy CleverSay treated them as content words, so
    // many legacy patterns (e.g., `where&office`) require them, and
    // stripping them silently breaks those patterns.
    //
    // v4.37.17+: negation words (no, not) likewise NOT in this
    // list. Negation inverts question meaning ("can I X" vs "can I
    // not X" are different questions); stripping it silently
    // collapses opposite questions into the same matcher input.
    private const FALLBACK_STOPWORDS = [
        'a','an','the','is','are','am','was','were','be','been','being',
        'i','me','my','you','your','we','us','our','they','their','it','its',
        'do','does','did','have','has','had','can','could','will','would','should',
        'may','might','must','shall',
        'and','or','but','if','then','for','to','of','in','on','at','by','with',
        'from','about','into','through','as','that','this','these','those',
        'any','all','some','more','most','few','many',
        'so','too','very','just','only',
        // v4.37.30+: subordinators
        'because','although','though',
    ];

    /**
     * Topic-significance threshold. A stem appearing in this fraction of
     * variations or more is treated as a "shared topic word."
     * 0.5 means: must appear in at least half of the variations.
     */
    private const TOPIC_SHARE_THRESHOLD = 0.5;

    /**
     * Maximum sibling-frequency for a stem to qualify as a shared
     * anchor in find_shared_anchor_stems. Stems appearing in more
     * than this many sibling variations are treated as generic
     * noise (e.g., `what`, `how`, `take`) and skipped — AND-
     * combining on them would over-constrain the resulting pattern
     * without adding topical specificity.
     *
     * @since 4.37.19
     */
    private const ANCHOR_NOISE_FREQ_LIMIT = 4;

    /**
     * Stems that are categorically too generic to act as binding
     * intent words, even when shared across all variations of an
     * entry. They commonly appear in questions across many entries
     * (interrogatives, generic verbs) so AND-combining on them
     * over-constrains alternatives without adding topical meaning.
     *
     * Note these stems CAN still be picked as discriminators by
     * pick_discriminator_for_variation when better choices aren't
     * available — they're only excluded from the shared-anchor
     * pass.
     *
     * @since 4.37.19
     */
    private const ANCHOR_NOISE_STEMS = [
        // Question words (post-v4.37.2 they are content words, not stopwords)
        'what','when','where','who','whom','why','how','which',
        // Generic verbs that appear in most questions
        'take','make','do','get','have','want','need','use','look','see','want','tell','say','know','think',
        // Pronouns/possessives that occasionally slip past stopword filters
        'one','any',
    ];

    /**
     * Stems that score high in WordNet POS but are content-light in
     * practice — common transitive verbs that show up in most
     * questions regardless of topic. The picker demotes their score
     * by POS_GENERIC_VERB_PENALTY so they don't beat domain-
     * specific verbs.
     *
     * @since 4.37.20
     */
    private const POS_NOISY_GENERIC_VERBS = [
        'take','make','do','get','have','want','need','use','work',
        'find','look','see','tell','say','know','think','give','put',
        'go','come','keep','let','call','try','ask','feel','show',
    ];

    /**
     * Interrogative words. These appear in the majority of student
     * questions ("how do I...", "what is...", "where can I...") and
     * almost never carry the discriminating intent that would make
     * them useful as pattern anchors. Filtered out as candidates.
     *
     * v4.37.2 made these non-stopwords at the runtime so they could
     * participate in matching when explicitly required (rare cases
     * where the interrogative IS the topic, like "what about X?").
     * But for compiler purposes, they're noise — including `how*`
     * or `what*` in a pattern means almost any question would match.
     *
     * @since 4.37.35
     */
    private const POS_INTERROGATIVES = [
        'how','what','where','when','who','why','which','whom','whose',
    ];

    /**
     * Words with high WordNet polysemy whose multiple POS entries
     * stack into an inflated score, even though they're content-
     * light in actual question phrasing. e.g., "mean" has noun,
     * adjective-satellite, AND verb senses; "so" has noun and
     * adverb senses. v4.37.20 capped POS contribution to fix the
     * stacking, but these specific stems still tend to score too
     * high relative to domain content nouns. Penalty applied.
     *
     * @since 4.37.22
     */
    private const POS_NOISY_POLYSEMOUS = [
        'mean','so','way','like','kind','well','sort','case','part',
        'point','place','side','bit','lot','set','order','time',
        // Note: 'time' is here but already filtered as a "weak noun"
        // in many real-world cases.
    ];

    /**
     * Negation words that invert the meaning of a question. WordNet
     * usually types these as adverbs or doesn't index them at all,
     * so plain POS scoring underweights them. They're content-
     * bearing for question routing and deserve a boost — "Can I
     * register?" and "Can I NOT register?" want different answers.
     *
     * Note: `no` is dual-use (negation AND determiner). Including
     * it here means questions like "is there no parking?" treat
     * `no` as content; that's actually what we want.
     *
     * @since 4.37.25
     */
    private const POS_NEGATION_WORDS = [
        'not','never','without','cannot','no','nor','neither','none',
    ];

    /**
     * Qualifying determiners — words that survive runtime stopword
     * removal AND meaningfully constrain the noun they modify.
     * Most determiners are stopwords (`a`, `the`, `this`, `each`,
     * `every`); the ones in this list are content-bearing because
     * they qualify the noun in a way that changes routing intent:
     *
     *   - "repeat a course" vs "repeat ANOTHER course"
     *   - "register for a class" vs "register for a DIFFERENT class"
     *   - "submit a transcript" vs "submit MULTIPLE transcripts"
     *
     * Without a boost these score low: WordNet doesn't index `another`
     * or `every`, so their POS contribution is 0; their only score
     * comes from rareness (5 max). Topic nouns score 8+ via POS plus
     * rareness, so qualifying determiners always lose. Adding +3
     * brings them up to ~8 — on par with topic nouns, so they can
     * legitimately compete for the discriminator slot when they're
     * the actually-distinguishing word.
     *
     * @since 4.37.36
     */
    private const POS_QUALIFYING_DETERMINERS = [
        'another','different','every','various','certain','several','multiple',
    ];

    /**
     * POS overrides: supplements WordNet's coverage for words it's
     * missing (modern technology terms, neologisms) and corrects
     * miscategorizations. Format: stem => POS letter string. Used
     * BEFORE the WordNet lookup, so this overrides WordNet when
     * present.
     *
     * Why these matter: words like `online`, `email`, `login`,
     * `website`, `app` are critical domain terms in a university
     * KB but are missing from WordNet 3.x (the snapshot we ship).
     * Without this override, they score 0 POS and lose to
     * polysemous junk like "mean" or "so."
     *
     * @since 4.37.22
     */
    private const POS_OVERRIDES = [
        // Modern web/computing nouns
        'online'    => 'a',  // typically used adjectivally ("online class")
        'offline'   => 'a',
        'website'   => 'n',
        'webpage'   => 'n',
        'app'       => 'n',
        'login'     => 'nv',
        'logout'    => 'nv',
        'signin'    => 'nv',
        'signup'    => 'nv',
        'logon'     => 'nv',
        'logoff'    => 'nv',
        'username'  => 'n',
        'smartphone'=> 'n',
        'wifi'      => 'n',
        'url'       => 'n',
        'pdf'       => 'n',
        // University-specific terms
        'fafsa'     => 'n',
        'sap'       => 'n',
        'gpa'       => 'n',
        'gi'        => 'n',     // GI as in "GI Bill"
        'rotc'      => 'n',
        'syllabus'  => 'n',
        'bursar'    => 'n',
        'registrar' => 'n',
        'transcript'=> 'n',
        'undergrad' => 'n',
        'undergraduate' => 'n',
        'postgrad'  => 'n',
        'pell'      => 'n',
        'subsidized'=> 'a',
        'unsubsidized' => 'a',
        // Demotions: these have inflated WordNet scores. Empty POS
        // means "treat as non-content" so they score 0.
        'thing'     => '',
        'something' => '',
        'someone'   => '',
        'somewhere' => '',
        'anything'  => '',
        'anyone'    => '',
    ];

    /**
     * Maximum POS contribution per candidate. Caps stacking so
     * polysemous common words don't outscore real domain content.
     * Set at 3 to match a single-POS noun or verb.
     *
     * @since 4.37.22
     */
    private const POS_MAX_CONTRIBUTION = 3.0;

    /**
     * Minimum composite score (from score_candidate) for a candidate
     * to qualify as a topical anchor — i.e. to be AND-combined with
     * the top discriminator. Below this, the picker falls through
     * to the collision-disambiguation or single-emit branch.
     *
     * Calibration: a typical noun in zero siblings scores roughly
     * 3 (POS) + 5 (rareness) = 8. A typical generic verb after
     * penalty in zero siblings scores roughly 3 - 2 + 5 = 6. An
     * interrogative (POS missing in WordNet) in zero siblings scores
     * 0 + 5 = 5. Setting the threshold at 6 means generic verbs and
     * better can anchor; interrogatives can't.
     *
     * @since 4.37.20
     */
    private const TOPICAL_ANCHOR_MIN_SCORE = 6.0;

    private array $stopwords;

    public function __construct(?array $stopwords = null) {
        $this->stopwords = $stopwords !== null
            ? array_map('strtolower', $stopwords)
            : self::FALLBACK_STOPWORDS;
    }

    /**
     * Build a stopwords-aware compiler from the live database.
     */
    public static function from_database(): self {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_stopwords';
        $words = $wpdb->get_col("SELECT word FROM $table WHERE is_active = 1");
        if (!is_array($words) || empty($words)) {
            return new self(); // fallback list
        }
        // Merge with fallback list — DB list might be sparse on fresh sites.
        $combined = array_unique(array_merge(self::FALLBACK_STOPWORDS, $words));
        return new self($combined);
    }

    /**
     * Compile variations → pattern.
     *
     * Algorithm (v4.35.0+):
     *
     * The compiler emits a discriminator pattern. The original
     * CleverSay matcher filters candidate entries by keyword first,
     * then applies the pattern. So the pattern only needs to
     * distinguish THIS entry from sibling entries under the same
     * keyword — not characterize the variations exhaustively.
     *
     * Per variation: tokenize, strip the keyword and its stems
     * (since every sibling has them too — zero discriminative value),
     * stem the rest, score each stem by sibling frequency (lower =
     * rarer = more discriminative), pick the top stem. If the top
     * stem still collides with siblings (frequency > 0), AND-combine
     * with the second-best stem from the same variation. The output
     * is the per-variation discriminators OR-joined.
     *
     * For the GI Bill example with 4 variations under keyword=bill:
     *   V1 stems (after keyword strip): elig, montgomery, gi, benefit
     *   V2 stems: requirement, montgomery
     *   V3 stems: requir, gi
     *   V4 stems: elig, montgomery, gi
     *   Sibling freq under keyword=bill: probably elig=0, montgomery=0,
     *     requirement=0, gi=0, benefit=0 (assuming no other bill
     *     entries mention these). So each V picks its top stem:
     *     V1→"elig*" (long, eligibility/eligible cluster), V2→
     *     "requirement", V3→"requir*", V4→"elig*". After dedup and
     *     prefix clustering: `elig*|requir*|montgomery|gi`. Bigger
     *     match surface than the old all-AND wall, more like the
     *     legacy hand-tuned style.
     *
     * @param string[] $variations Natural-language phrasings.
     * @param string $keyword The entry's keyword. Stripped from
     *     variation tokens before scoring. When empty (legacy
     *     callers, import paths without keyword context) the
     *     stripping step is skipped.
     * @param ?string[] $sibling_variations Variations belonging to
     *     OTHER entries under the same keyword. Used to score how
     *     rare each candidate stem is. When null or empty, falls
     *     back to length-based heuristic scoring.
     * @return string Pattern string, or '' if no usable content.
     */
    public function compile(array $variations, string $keyword = '', ?array $sibling_variations = null): string {
        $variations = array_values(array_filter(array_map('trim', $variations)));
        if (empty($variations)) {
            return '';
        }

        // Tokenize each variation. Pass keyword so we can strip it
        // and its stem out of the candidate pool.
        $tokenized = [];
        foreach ($variations as $v) {
            $tokens = $this->tokenize_for_compile($v, $keyword);
            if (!empty($tokens)) {
                $tokenized[] = $tokens;
            }
        }
        if (empty($tokenized)) {
            return '';
        }

        // Build a map: stem => # of sibling variations it appears in.
        // Used to score discriminator candidates by rareness.
        $sibling_freq = $this->build_sibling_frequency_map(
            is_array($sibling_variations) ? $sibling_variations : [],
            $keyword
        );

        // v4.37.19+: shared-anchor pass.
        //
        // Identify stems that appear in EVERY variation of this entry
        // (the "binding intent words" — e.g., for variations
        // ["What is the deadline to add a course?",
        //  "What is the last day to add a course?"]
        // the stem `add` is shared across both). When such words
        // exist AND have low sibling-frequency (i.e., they're
        // discriminative across other entries' variations, not
        // generic noise like `what`), we AND-combine them into every
        // emitted alternative. The legacy admin's hand-written rules
        // captured this intent (`deadline&add|last+day&add`); the
        // pre-4.37.19 per-variation discriminator picker missed it
        // because picking ran independently for each variation and
        // then OR-joined.
        //
        // Without this pass, the example above produced
        // `deadline*|day*` — too loose, matching any deadline-or-day
        // question under the same keyword. With this pass, it
        // produces `deadline*&add*|day*&add*` — properly bound to
        // the "add a class" intent.
        $shared_anchor_stems = $this->find_shared_anchor_stems($tokenized, $sibling_freq);

        // Per variation: pick the discriminator(s) and emit one
        // sub-pattern. OR-join them across variations.
        $sub_patterns = [];
        foreach ($tokenized as $tokens) {
            $sub = $this->pick_discriminator_for_variation($tokens, $sibling_freq, $shared_anchor_stems);
            if ($sub !== '') {
                $sub_patterns[] = $sub;
            }
        }
        if (empty($sub_patterns)) {
            return '';
        }

        // Deduplicate.
        $sub_patterns = array_values(array_unique($sub_patterns));

        // Prefix clustering applies to single-stem alternatives only
        // (so eligibility/eligible collapse to elig*, but compound
        // AND-groups stay verbatim). Single stems are isolated, run
        // through the cluster, and the compound alternatives are
        // appended unchanged.
        if (count($sub_patterns) > 1) {
            $single_stems = [];
            $compound     = [];
            foreach ($sub_patterns as $sp) {
                if (strpos($sp, '&') === false && strpos($sp, '+') === false) {
                    $single_stems[] = $sp;
                } else {
                    $compound[] = $sp;
                }
            }
            if (count($single_stems) > 1) {
                $single_stems = $this->cluster_surfaces_by_prefix($single_stems);
            }
            $sub_patterns = array_merge($single_stems, $compound);
        }

        // Absorption pass: if alternative A's required-word-set is a
        // strict subset of alternative B's, drop B (A | A&B = A).
        $sub_patterns = $this->absorb_redundant_alternatives($sub_patterns);

        return implode('|', $sub_patterns);
    }

    /**
     * Compile with diagnostic trace.
     *
     * Runs the deterministic compile() to get the actual pattern,
     * then reconstructs per-token scoring data for the admin debug
     * panel. The trace is informational only — the pattern is the
     * single source of truth for what the runtime matcher will see.
     *
     * Returned shape:
     * {
     *   'pattern'      => string,
     *   'sibling_count'=> int,            // # of sibling variations considered
     *   'tokens'       => array of [
     *      'token'      => string,        // surface form as it appeared
     *      'stem'       => string,        // stemmed form used by matcher
     *      'pos'        => string,        // n/v/a/r etc., '?' if unknown
     *      'score'      => float,         // composite score from score_candidate
     *      'sibling_freq' => int,         // how many sibling variations contained this stem
     *      'in_pattern' => bool,          // whether this token's stem appears in final pattern
     *      'category'   => string,        // 'negation', 'qualifier', 'interrogative', 'acronym', or ''
     *   ],
     *   'summary'      => string,         // one-line human description
     * }
     *
     * @since 4.37.51
     */
    public function compile_with_trace(array $variations, string $keyword = '', ?array $sibling_variations = null): array {
        $variations = array_values(array_filter(array_map('trim', $variations)));
        $pattern = $this->compile($variations, $keyword, $sibling_variations);

        // Tokenize all variations the same way compile() did, then
        // dedupe by stem (we want one row per unique stem across all
        // variations, not duplicates).
        $all_tokens   = [];
        $first_seen   = []; // stem => index in original tokens (for position scoring)
        foreach ($variations as $vidx => $v) {
            $tokens = $this->tokenize_for_compile($v, $keyword);
            foreach ($tokens as $idx => $t) {
                $stem = $this->stem($t);
                if ($stem === '' || strlen($stem) < 2) continue;
                if (isset($first_seen[$stem])) continue;
                $first_seen[$stem] = $idx;
                $all_tokens[$stem] = ['token' => $t, 'idx' => $idx, 'tokens' => $tokens];
            }
        }

        // Sibling frequency map — same input compile() used.
        $sibling_freq = $this->build_sibling_frequency_map(
            is_array($sibling_variations) ? $sibling_variations : [],
            $keyword
        );
        $sibling_count = is_array($sibling_variations) ? count($sibling_variations) : 0;

        // Categorize each stem so the panel can show which special
        // rules fired. Constants from class-kb-pattern-compiler.php.
        $category_of = function (string $stem): string {
            if (in_array($stem, self::POS_NEGATION_WORDS, true))           return 'negation';
            if (in_array($stem, self::POS_QUALIFYING_DETERMINERS, true))   return 'qualifier';
            if (in_array($stem, self::POS_INTERROGATIVES, true))           return 'interrogative';
            // Acronym detection mirrors compiler logic — 3-6 char
            // all-letters token missing from WordNet AND curated dict.
            if (preg_match('/^[a-z]{3,6}$/', $stem)
                && !$this->is_known_dictionary_word($stem)
                && $this->pos_for($stem) === '?'
            ) {
                return 'acronym';
            }
            return '';
        };

        // Parse the final pattern to know which stems made it. Strip
        // wildcards, ANDs, ORs, and `+phrase` markers. Each remaining
        // token is a stem the matcher looks for.
        $pattern_stems = [];
        foreach (preg_split('/[|&]/', $pattern) as $branch) {
            $branch = trim($branch);
            if ($branch === '') continue;
            // Handle +phrase — extract every word in the phrase.
            if (substr($branch, 0, 1) === '+') {
                foreach (preg_split('/\s+/', substr($branch, 1)) as $w) {
                    $w = $this->stem(trim($w));
                    if ($w !== '') $pattern_stems[$w] = true;
                }
                continue;
            }
            // Normal token — strip trailing *.
            $w = $this->stem(rtrim($branch, '*'));
            if ($w !== '') $pattern_stems[$w] = true;
        }

        // Build per-stem trace rows.
        $rows = [];
        foreach ($all_tokens as $stem => $info) {
            $score = $this->score_candidate(
                $stem,
                $info['token'],
                $info['idx'],
                $info['tokens'],
                $sibling_freq[$stem] ?? 0
            );
            $rows[] = [
                'token'        => $info['token'],
                'stem'         => $stem,
                'pos'          => $this->pos_for($stem) ?: '?',
                'score'        => round($score, 1),
                'sibling_freq' => (int) ($sibling_freq[$stem] ?? 0),
                'in_pattern'   => isset($pattern_stems[$stem]),
                'category'     => $category_of($stem),
            ];
        }

        // Sort by score descending so highest-scoring tokens come first.
        usort($rows, static fn($a, $b) => $b['score'] <=> $a['score']);

        // One-line summary for the panel header.
        $kept = array_filter($rows, static fn($r) => $r['in_pattern']);
        $summary = count($kept) === 0
            ? 'no tokens selected'
            : (count($kept) === 1
                ? sprintf('single-token wildcard: %s', $kept[array_key_first($kept)]['stem'])
                : sprintf('%d-token AND from %s',
                    count($kept),
                    implode(', ', array_map(static fn($r) => $r['stem'], $kept))
                ));

        return [
            'pattern'       => $pattern,
            'sibling_count' => $sibling_count,
            'tokens'        => $rows,
            'summary'       => $summary,
        ];
    }

    /**
     * Iterative compile — try the deterministic compile() first; if
     * the result doesn't rank this entry as #1 for every variation,
     * walk a ladder of progressively more specific patterns until
     * one wins or the ladder is exhausted.
     *
     * Why this exists: compile() is fast and predictable but
     * sometimes emits a pattern too generic for the live KB to
     * route correctly (the variation matches the pattern but a
     * sibling entry outranks it). Round-trip validation surfaces
     * this; iterative compile attempts to fix it automatically by
     * trying tighter patterns.
     *
     * The ladder:
     *   Tier 0: deterministic compile() output (baseline)
     *   Tier 1: add one more content word per alternative (the
     *           next-highest-scoring word from each source variation)
     *   Tier 2: try promoting different words to the top discriminator
     *           slot (per-variation; emit OR-joined permutations)
     *   Tier 3: kitchen sink — AND-combine ALL content words from
     *           each variation
     *
     * Bounded by:
     *   - max_attempts: hard cap on test calls (default 8)
     *   - time budget: wall-clock cap (default 800ms)
     *   - exhaustion: ladder runs out of strategies
     *
     * @param array    $variations         Variation strings.
     * @param string   $keyword            KB keyword for filtering.
     * @param array|null $sibling_variations Sibling entries' variations
     *                                      under same keyword (for
     *                                      sibling-frequency scoring).
     * @param callable $tester             Function: pattern => bool.
     *                                      Returns true if THIS entry
     *                                      ranks #1 for ALL variations
     *                                      with the given pattern.
     * @param int      $max_attempts       Hard cap on tester calls.
     * @return array {
     *   'pattern'    => string,  // best pattern found
     *   'status'     => string,  // 'matched' | 'partial' | 'no_improvement'
     *   'attempts'   => int,     // number of tester calls used
     *   'tried'      => string[], // patterns tried, in order
     * }
     *
     * @since 4.37.29
     */
    public function compile_iterative(
        array $variations,
        string $keyword,
        ?array $sibling_variations,
        callable $tester,
        int $max_attempts = 8
    ): array {
        $deadline = microtime(true) + 0.8; // 800ms wall-clock budget
        $tried    = [];
        $best     = '';
        $attempts = 0;

        // Tier 0: baseline deterministic compile.
        $base = $this->compile($variations, $keyword, $sibling_variations);
        if ($base === '') {
            return ['pattern' => '', 'status' => 'no_improvement', 'attempts' => 0, 'tried' => []];
        }
        $best = $base;
        $tried[] = $base;
        $attempts++;
        if ($tester($base)) {
            return ['pattern' => $base, 'status' => 'matched', 'attempts' => $attempts, 'tried' => $tried];
        }
        if (microtime(true) > $deadline || $attempts >= $max_attempts) {
            return ['pattern' => $best, 'status' => 'no_improvement', 'attempts' => $attempts, 'tried' => $tried];
        }

        // Tokenize each variation to enumerate content words.
        $tokenized = [];
        foreach ($variations as $v) {
            $t = $this->tokenize_for_compile($v, $keyword);
            if (!empty($t)) $tokenized[] = $t;
        }
        if (empty($tokenized)) {
            return ['pattern' => $best, 'status' => 'no_improvement', 'attempts' => $attempts, 'tried' => $tried];
        }

        $sibling_freq = $this->build_sibling_frequency_map(
            is_array($sibling_variations) ? $sibling_variations : [],
            $keyword
        );

        // Build per-variation candidate pools (stem + score), sorted by score desc.
        $per_var_candidates = [];
        foreach ($tokenized as $tokens) {
            $cands = [];
            $seen  = [];
            foreach ($tokens as $idx => $t) {
                $stem = $this->stem($t);
                if ($stem === '' || strlen($stem) < 2) continue;
                if (isset($seen[$stem])) continue;
                $seen[$stem] = true;
                $cands[] = [
                    'stem'    => $stem,
                    'surface' => $t,
                    'score'   => $this->score_candidate($stem, $t, $idx, $tokens, $sibling_freq[$stem] ?? 0),
                ];
            }
            usort($cands, static fn($a, $b) => $b['score'] <=> $a['score']);
            $per_var_candidates[] = $cands;
        }

        // Tier 1: add one more content word per alternative — for each
        // existing alternative in the baseline pattern, find which
        // variation produced it (by best stem-set match) and AND-combine
        // its next-highest-scoring word.
        //
        // Practical approach: we don't have a reliable way to map each
        // baseline alternative back to its source variation (the OR-join
        // erased that). Instead, we rebuild the pattern with an extra
        // term per variation: top_discriminator + topical_anchor +
        // next_highest_word. Then OR-join.
        $tier1 = $this->build_tier_pattern($tokenized, $sibling_freq, $per_var_candidates, 3 /* top N words */);
        if ($tier1 !== '' && !in_array($tier1, $tried, true)) {
            $tried[] = $tier1;
            $attempts++;
            if ($tester($tier1)) {
                return ['pattern' => $tier1, 'status' => 'matched', 'attempts' => $attempts, 'tried' => $tried];
            }
            if (microtime(true) > $deadline || $attempts >= $max_attempts) {
                return ['pattern' => $best, 'status' => 'no_improvement', 'attempts' => $attempts, 'tried' => $tried];
            }
        }

        // Tier 2: 4 words per alternative.
        $tier2 = $this->build_tier_pattern($tokenized, $sibling_freq, $per_var_candidates, 4);
        if ($tier2 !== '' && !in_array($tier2, $tried, true)) {
            $tried[] = $tier2;
            $attempts++;
            if ($tester($tier2)) {
                return ['pattern' => $tier2, 'status' => 'matched', 'attempts' => $attempts, 'tried' => $tried];
            }
            if (microtime(true) > $deadline || $attempts >= $max_attempts) {
                return ['pattern' => $best, 'status' => 'no_improvement', 'attempts' => $attempts, 'tried' => $tried];
            }
        }

        // Tier 3: kitchen sink — every content word in every variation.
        $kitchen_sink = $this->build_tier_pattern($tokenized, $sibling_freq, $per_var_candidates, PHP_INT_MAX);
        if ($kitchen_sink !== '' && !in_array($kitchen_sink, $tried, true)) {
            $tried[] = $kitchen_sink;
            $attempts++;
            if ($tester($kitchen_sink)) {
                return ['pattern' => $kitchen_sink, 'status' => 'matched', 'attempts' => $attempts, 'tried' => $tried];
            }
        }

        // Nothing won. Return the original baseline as the best
        // attempt (kitchen sink is too noisy if it didn't help —
        // patterns admin would never write by hand).
        return ['pattern' => $best, 'status' => 'no_improvement', 'attempts' => $attempts, 'tried' => $tried];
    }

    /**
     * Build a pattern by taking the top-N highest-scoring candidate
     * stems from each variation, AND-combining them, then OR-joining
     * across variations. Used by compile_iterative to construct
     * tier patterns.
     *
     * @since 4.37.29
     */
    private function build_tier_pattern(
        array $tokenized,
        array $sibling_freq,
        array $per_var_candidates,
        int $top_n
    ): string {
        $alternatives = [];
        foreach ($per_var_candidates as $cands) {
            if (empty($cands)) continue;
            $take = array_slice($cands, 0, $top_n);
            $stems = array_map(fn($c) => $this->as_term($c['stem']), $take);
            $stems = array_values(array_unique($stems));
            if (empty($stems)) continue;
            $alternatives[] = implode('&', $stems);
        }
        if (empty($alternatives)) return '';
        $alternatives = array_values(array_unique($alternatives));
        $alternatives = $this->absorb_redundant_alternatives($alternatives);
        return implode('|', $alternatives);
    }

    /**
     * Tokenize a variation for compilation. Lowercases, drops
     * punctuation, removes stopwords (loaded from the live DB by the
     * caller via from_database()), AND drops the entry's keyword and
     * its plural forms — since every sibling under the same keyword
     * has them too, they carry zero discriminative value.
     *
     * Keyword stripping handles simple English plural forms inline
     * because stem() is intentionally a no-op (the production matcher
     * uses a dictionary-validated stemmer in Search::apply_stemming
     * that we can't replicate at compile time without drift). For
     * keyword "class", we strip both "class" and "classes". For
     * keyword "classes", we strip both "classes" and "class". This
     * covers the common cases without trying to be a real stemmer.
     *
     * @return string[] Surface tokens (not yet stemmed).
     */
    private function tokenize_for_compile(string $text, string $keyword): array {
        // v4.37.13+: delegate to the runtime's compile_normalize so
        // tokens at compile time are byte-identical to what a live
        // query produces — same stopword list, same synonym table,
        // same stemmer. Pre-4.37.13 the compiler did its own
        // (parallel-but-not-identical) tokenize/stopword/stem path,
        // which produced patterns that wouldn't match the runtime's
        // version of the same words. Specifically: synonym
        // replacements (term -> semester) and word-level synonyms
        // weren't applied here, so a discriminator picked from
        // "term" would never match queries the runtime had
        // rewritten to "semester."
        //
        // We still strip the entry's keyword from the candidate
        // pool — every sibling under that keyword has it, so it
        // carries zero discriminative value. This is done after
        // normalization (so "classes" -> "class" via the runtime
        // stemmer matches keyword "class").
        $tokens = $this->normalize_via_search($text);

        if ($keyword === '') {
            return $tokens;
        }

        $kw_lower = strtolower(trim($keyword));
        if ($kw_lower === '') return $tokens;

        // Generate keyword forms. Since the tokens above are already
        // stemmed by Search, we generate matching stemmed-and-
        // unstemmed forms of the keyword to filter on.
        $kw_forms = [$kw_lower => true];
        $kw_stemmed = $this->normalize_via_search($kw_lower);
        foreach ($kw_stemmed as $kf) {
            $kw_forms[$kf] = true;
        }
        // Also handle simple plural inflections explicitly in case the
        // runtime stemmer doesn't reduce them (e.g. keyword "class" /
        // tokens "classes" — runtime produces "class", but if a
        // variation has "class" and the keyword is "classes" we still
        // want both stripped).
        $len = strlen($kw_lower);
        $kw_forms[$kw_lower . 's'] = true;
        if (strlen($kw_lower) >= 3) {
            $kw_forms[$kw_lower . 'es'] = true;
        }
        if ($len > 3 && substr($kw_lower, -1) === 's') {
            $kw_forms[substr($kw_lower, 0, -1)] = true;
        }
        if ($len > 4 && substr($kw_lower, -2) === 'es') {
            $kw_forms[substr($kw_lower, 0, -2)] = true;
        }
        if ($len > 4 && substr($kw_lower, -3) === 'ies') {
            $kw_forms[substr($kw_lower, 0, -3) . 'y'] = true;
        }

        $filtered = [];
        foreach ($tokens as $t) {
            if (isset($kw_forms[strtolower($t)])) continue;
            $filtered[] = $t;
        }
        return $filtered;
    }

    /**
     * Run text through the runtime Search pipeline and return tokens.
     * Wraps Search::compile_normalize with a defensive fallback if
     * Search isn't loadable for any reason (e.g. early activation).
     */
    private function normalize_via_search(string $text): array {
        if (class_exists('\\CleverSay\\Search')) {
            try {
                $search = new \CleverSay\Search();
                return $search->compile_normalize($text);
            } catch (\Throwable $e) {
                // Fall through to local tokenize on any failure.
            }
        }
        // Fallback: local tokenize + stopwords. Best-effort only.
        $tokens = $this->tokenize($text);
        $stopwords = array_flip($this->stopwords);
        return array_values(array_filter($tokens, fn($t) => !isset($stopwords[strtolower($t)])));
    }

    /**
     * Build the stem → sibling-count frequency map. Each unique stem
     * found in any sibling variation contributes 1 per sibling
     * variation it appears in. Stems found in zero sibling variations
     * are not in the map (caller treats absent stems as freq=0).
     */
    private function build_sibling_frequency_map(array $sibling_variations, string $keyword): array {
        $freq = [];
        foreach ($sibling_variations as $sv) {
            if (!is_string($sv) || trim($sv) === '') continue;
            $tokens = $this->tokenize_for_compile($sv, $keyword);
            $seen_stems = [];
            foreach ($tokens as $t) {
                $stem = $this->stem($t);
                if ($stem === '' || strlen($stem) < 2) continue;
                if (isset($seen_stems[$stem])) continue;
                $seen_stems[$stem] = true;
                $freq[$stem] = ($freq[$stem] ?? 0) + 1;
            }
        }
        return $freq;
    }

    /**
     * Identify stems that act as shared anchors across this entry's
     * variations. A stem qualifies if:
     *   1. It appears in EVERY variation of this entry (it's a true
     *      "binding" word — the intent that ties variations together).
     *   2. Its sibling-frequency is below the noise threshold so we
     *      don't AND-combine on words like `what` that appear in
     *      most entries' variations and would over-constrain
     *      otherwise-unrelated alternatives.
     *
     * Uses the absolute sibling threshold ANCHOR_NOISE_FREQ_LIMIT —
     * if a stem appears in more than that many sibling variations,
     * it's considered noise and skipped. The threshold is forgiving
     * (a content word can show up in a handful of siblings and still
     * qualify); the goal is to filter `what`/`how`/etc.
     *
     * @param array $tokenized   List of token-arrays, one per variation of THIS entry.
     * @param array $sibling_freq Stem -> count map across OTHER entries' variations.
     * @return array<string> Stems that qualify as shared anchors, in
     *     order of first appearance in the first variation.
     *
     * @since 4.37.19
     */
    private function find_shared_anchor_stems(array $tokenized, array $sibling_freq): array {
        if (count($tokenized) < 2) {
            // Single variation has nothing to be "shared" against —
            // every word is trivially in every variation. Defer to
            // the per-variation discriminator picker.
            return [];
        }

        // Per-variation seen-stem sets.
        $variation_stem_sets = [];
        foreach ($tokenized as $tokens) {
            $set = [];
            foreach ($tokens as $t) {
                $stem = $this->stem($t);
                if ($stem === '' || strlen($stem) < 2) continue;
                $set[$stem] = true;
            }
            $variation_stem_sets[] = $set;
        }

        // Intersect — stems that appear in every set.
        $first = $variation_stem_sets[0];
        $shared = [];
        $noise_set = array_flip(self::ANCHOR_NOISE_STEMS);
        foreach (array_keys($first) as $stem) {
            $in_all = true;
            for ($i = 1; $i < count($variation_stem_sets); $i++) {
                if (!isset($variation_stem_sets[$i][$stem])) {
                    $in_all = false;
                    break;
                }
            }
            if (!$in_all) continue;

            // Filter generic interrogatives and high-frequency verbs.
            // These can still be picked as discriminators when better
            // choices aren't available; they just don't AND-combine
            // into every alternative.
            if (isset($noise_set[$stem])) continue;

            // Filter out generic noise words by sibling frequency.
            // Noise threshold: absolute count, conservative.
            $sib = $sibling_freq[$stem] ?? 0;
            if ($sib > self::ANCHOR_NOISE_FREQ_LIMIT) continue;

            $shared[] = $stem;
        }

        return $shared;
    }

    /**
     * Pick the discriminator stem(s) for a single variation. Returns
     * a sub-pattern (one stem, or stem1&stem2 if a topical anchor or
     * collision-disambiguator is available).
     *
     * Scoring rules:
     *   - Lower sibling_freq scores higher (rarer = better
     *     discriminator).
     *   - When freq is tied (or both zero), longer surface form wins.
     *     Length is a reasonable proxy for content-noun-ness when
     *     siblings don't help differentiate, since interrogatives and
     *     auxiliaries are short and topic nouns are typically longer.
     *
     * Output rules (v4.35.1+):
     *   - If only one candidate exists → emit it alone.
     *   - Look for a "topical anchor" — the longest other content word
     *     in the variation (≥ 5 chars). Length 5 avoids generic short
     *     verbs (`take`, `work`, `find`, `look`) polluting the pattern,
     *     while still catching topical nouns (`deadline`, `montgomery`,
     *     `transcript`).
     *   - If a topical anchor exists and isn't already the top
     *     candidate → emit `top&anchor`. This matches the legacy
     *     hand-tuned style: discriminator AND topic.
     *   - Otherwise: emit top alone, OR if top collides with siblings
     *     (freq > 0) and a second candidate exists, emit
     *     `top&second` for collision disambiguation.
     *
     * v4.37.19+: shared anchors are excluded from the candidate
     * pool used for discriminator picking (they're not what
     * distinguishes this variation; they're the binding intent
     * shared across all of them). They are then AND-combined into
     * the final emitted sub-pattern.
     *
     * v4.37.20+: replaced the length-as-tiebreaker heuristic with
     * POS-aware scoring. Each candidate gets a composite score from
     * (a) part-of-speech via WordNet (nouns/verbs/adjectives are
     * high-value content words; adverbs are middling; words missing
     * from WordNet are typically interrogatives or function words
     * and get demoted), (b) sibling-frequency rareness, and (c) a
     * hand-curated demote list for "POS-noisy" generics like
     * `take`, `make`, `do`, `get`, `have`, `use` that score high in
     * WordNet but are content-light in practice. A "modifier-noun"
     * bonus boosts adjectives/modifiers immediately followed by a
     * noun ("last day", "first time", "only option"). The topical-
     * anchor branch now picks the highest-scoring non-top candidate
     * regardless of length, so short content words like `add` and
     * `pay` properly anchor patterns.
     */
    private function pick_discriminator_for_variation(array $tokens, array $sibling_freq, array $shared_anchor_stems = []): string {
        if (empty($tokens)) return '';

        $shared_set = array_flip($shared_anchor_stems);

        // Keep the original token order for the modifier-noun bonus.
        // The candidate list is built from order, but we sort it later.
        $token_order = [];
        foreach ($tokens as $idx => $t) {
            $stem = $this->stem($t);
            if ($stem === '' || strlen($stem) < 2) continue;
            $token_order[] = ['stem' => $stem, 'surface' => $t, 'idx' => $idx];
        }

        $candidates = [];
        $seen = [];
        foreach ($token_order as $entry) {
            $stem = $entry['stem'];
            if (isset($seen[$stem])) continue;
            $seen[$stem] = true;
            // Skip shared anchors — they go into the final sub-pattern
            // unconditionally (below), not as discriminator candidates.
            if (isset($shared_set[$stem])) continue;

            $score = $this->score_candidate(
                $stem,
                $entry['surface'],
                $entry['idx'],
                $tokens,
                $sibling_freq[$stem] ?? 0
            );

            $candidates[] = [
                'stem'    => $stem,
                'surface' => $entry['surface'],
                'freq'    => $sibling_freq[$stem] ?? 0,
                'score'   => $score,
            ];
        }

        // Helper: append shared anchors to a sub-pattern with `&`.
        $append_shared = function(string $sub) use ($shared_anchor_stems): string {
            if (empty($shared_anchor_stems)) return $sub;
            $parts = $sub === '' ? [] : [$sub];
            foreach ($shared_anchor_stems as $stem) {
                $parts[] = $this->as_term($stem);
            }
            return implode('&', $parts);
        };

        if (empty($candidates)) {
            // No discriminator candidates left after excluding shared
            // anchors. Emit shared anchors alone (rare — happens when
            // a variation is entirely composed of binding intent words).
            return $append_shared('');
        }

        // Sort by composite score descending. Ties: prefer rarer in
        // siblings; further ties: longer surface (the old heuristic
        // is still a reasonable secondary tiebreaker).
        usort($candidates, static function($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score']; // descending
            }
            if ($a['freq'] !== $b['freq']) {
                return $a['freq'] - $b['freq']; // ascending (rarer first)
            }
            return strlen($b['surface']) - strlen($a['surface']);
        });

        $top = $candidates[0];

        // Single candidate — emit alone (with shared anchors AND-combined).
        if (count($candidates) === 1) {
            return $append_shared($this->as_term($top['stem']));
        }

        // Topical anchor: highest-scoring non-top candidate, must be
        // a content word (POS_THRESHOLD or higher). Length restriction
        // removed in v4.37.20 — POS scoring naturally excludes function
        // words without needing a length floor.
        //
        // v4.37.20+: SKIP topical-anchor amplification when shared
        // anchors already exist. The shared anchors provide topical
        // grounding (they're words present in every variation —
        // i.e., the binding intent). Adding ANOTHER topical anchor
        // on top makes patterns over-constrained: every alternative
        // ends up requiring 4-5 words, and real student queries
        // missing any one of them fail to match.
        $topical_anchor = null;
        if (empty($shared_anchor_stems)) {
            foreach ($candidates as $c) {
                if ($c['stem'] === $top['stem']) continue;
                if ($c['score'] < self::TOPICAL_ANCHOR_MIN_SCORE) continue;
                $topical_anchor = $c;
                break; // candidates already sorted by score desc
            }
        }

        if ($topical_anchor !== null) {
            return $append_shared(
                $this->as_term($top['stem']) . '&' . $this->as_term($topical_anchor['stem'])
            );
        }

        // v4.37.35+: avoid emitting single-token wildcard patterns
        // when a reasonable second candidate exists. A bare `school*`
        // matches any query mentioning school-anything; pairing with
        // a softer second discriminator (`school*&take*`) tightens
        // the match without requiring the second word to be a
        // strong topical anchor.
        //
        // The strict topical-anchor threshold (6.0) above is right
        // when we're choosing whether to ADD a topical anchor on top
        // of an already-good pattern. But when the alternative is
        // emitting a single-token wildcard (which is structurally
        // too loose), a weaker second discriminator is the lesser
        // evil — it still adds an AND constraint that filters out
        // queries that don't share the second word.
        //
        // Softer threshold: 4.0. Picks up content words that have
        // some POS signal but didn't clear the strict bar (e.g.
        // common verbs that score 5-6 instead of 8+).
        $soft_anchor = null;
        $soft_threshold = 4.0;
        foreach ($candidates as $c) {
            if ($c['stem'] === $top['stem']) continue;
            if ($c['score'] < $soft_threshold) continue;
            $soft_anchor = $c;
            break; // candidates already sorted by score desc
        }
        if ($soft_anchor !== null) {
            return $append_shared(
                $this->as_term($top['stem']) . '&' . $this->as_term($soft_anchor['stem'])
            );
        }

        // No topical anchor available. If top is unique, emit alone.
        if ($top['freq'] === 0) {
            return $append_shared($this->as_term($top['stem']));
        }

        // Top collides with siblings; AND-combine with second-best for
        // disambiguation (the legacy collision-handling rule).
        return $append_shared(
            $this->as_term($top['stem']) . '&' . $this->as_term($candidates[1]['stem'])
        );
    }

    /**
     * Composite candidate score combining POS weight, rareness, and
     * generic-verb demotion. Higher is better.
     *
     * Score components:
     *   - POS weight: derived from WordNet (overridden by
     *     POS_OVERRIDES for words WordNet is missing or wrong on).
     *     Capped at POS_MAX_CONTRIBUTION so polysemous common words
     *     ("mean" — noun+adj+verb, "so" — noun+adverb) don't beat
     *     real domain content via stacking. Best single POS:
     *     noun/verb 3, adjective 2, adverb 1.
     *   - Modifier-noun bonus: +1.5 if this token is an
     *     adjective/adverb directly followed by a noun. Captures
     *     constraint phrases like "last day," "first time," "only
     *     option" — the modifier is often the deciding word.
     *   - Generic-verb penalty: -2 if stem is in the
     *     POS_NOISY_GENERIC_VERBS list (take, make, do, get, have,
     *     use, work, find, look, want, need, etc.). These score
     *     high in WordNet but rarely make good discriminators.
     *   - Polysemous-noise penalty: -2 if stem is in the
     *     POS_NOISY_POLYSEMOUS list (mean, so, way, like, kind,
     *     well, etc.). These have multiple WordNet entries that
     *     stack into a high POS score but are content-light.
     *   - Rareness factor: +max(0, 5 - sibling_freq). A word in
     *     zero siblings adds +5; a word in 5+ siblings adds 0.
     *
     * @since 4.37.20
     */
    private function score_candidate(string $stem, string $surface, int $idx, array $all_tokens, int $sibling_freq): float {
        $pos = $this->pos_for($stem);
        $score = 0.0;

        // POS weight — take the BEST single POS rather than stacking.
        // Stacking caused polysemous common words like `mean` (noun +
        // adj + verb = 8 pts) to outscore real domain nouns like
        // `authorization` (noun = 3 pts). Capped at POS_MAX_CONTRIBUTION.
        $pos_score = 0.0;
        if (str_contains($pos, 'n') || str_contains($pos, 'v')) $pos_score = 3.0;
        elseif (str_contains($pos, 'a') || str_contains($pos, 's')) $pos_score = 2.0;
        elseif (str_contains($pos, 'r')) $pos_score = 1.0;
        $score += min($pos_score, self::POS_MAX_CONTRIBUTION);

        // Modifier-noun bonus: this token is adj/adv AND next token is a noun.
        $is_modifier = (str_contains($pos, 'a') || str_contains($pos, 's') || str_contains($pos, 'r'))
                       && !str_contains($pos, 'n') && !str_contains($pos, 'v');
        if ($is_modifier && isset($all_tokens[$idx + 1])) {
            $next_stem = $this->stem($all_tokens[$idx + 1]);
            $next_pos  = $this->pos_for($next_stem);
            if (str_contains($next_pos, 'n')) {
                $score += 1.5;
            }
        }

        // Generic-verb demotion
        if (in_array($stem, self::POS_NOISY_GENERIC_VERBS, true)) {
            $score -= 2.0;
        }

        // Polysemous-noise demotion. Words with many WordNet entries
        // that stack into a high POS score but are content-light in
        // actual question phrasing.
        if (in_array($stem, self::POS_NOISY_POLYSEMOUS, true)) {
            $score -= 2.0;
        }

        // v4.37.35+: Interrogative demotion. Question words ("how",
        // "what", "where", "when") are non-stopwords at the runtime
        // (v4.37.2) so they participate in matching, but they
        // shouldn't anchor patterns — almost every student question
        // contains one, so they have ~zero discriminating power.
        // Heavy demote (-5) effectively disqualifies them from
        // candidate selection without removing them from the
        // matcher's vocabulary.
        if (in_array($stem, self::POS_INTERROGATIVES, true)) {
            $score -= 5.0;
        }

        // v4.37.25+: Negation boost. Words that invert question
        // meaning ("not", "never", "without") are content-bearing
        // for question routing and almost always discriminating
        // when present. WordNet types `not` as adverb (1pt POS),
        // `never` as adverb, `without` as preposition (often
        // missing) — all underscored without this boost. The
        // boost is conservative: it tips them above interrogatives
        // and weak verbs but doesn't unseat real domain nouns.
        if (in_array($stem, self::POS_NEGATION_WORDS, true)) {
            $score += 3.0;
        }

        // v4.37.36+: Qualifying-determiner boost. Determiners that
        // survived stopword removal AND constrain the noun they
        // modify in a routing-relevant way. See POS_QUALIFYING_
        // DETERMINERS for rationale. Boost magnitude (+3) brings
        // them on par with topic nouns when they're genuinely the
        // discriminating word — they can win the discriminator slot
        // when they ARE the discriminator.
        if (in_array($stem, self::POS_QUALIFYING_DETERMINERS, true)) {
            $score += 3.0;
        }

        // v4.37.25+: Acronym/proper-noun boost. Tokens that appear
        // to be acronyms — short (3-6 chars), all alphabetic, not
        // in WordNet, not in the curated stem dictionary — are
        // almost always the most-discriminating word in a
        // variation. UWSP, FAFSA, ROTC, GPA, FERPA, etc. The
        // pipeline lowercases tokens before compile_normalize
        // returns them so we can't use case as a signal; structural
        // heuristic is what's left.
        //
        // The combination of conditions excludes false positives:
        // very short tokens (< 3 chars rarely acronyms), long
        // tokens (likely real words missing from WordNet rather
        // than acronyms), and known dictionary words.
        $is_acronym = strlen($stem) >= 3
                      && strlen($stem) <= 6
                      && preg_match('/^[a-z]+$/u', $stem) === 1
                      && $pos === ''
                      && !in_array($stem, self::POS_NOISY_GENERIC_VERBS, true)
                      && !in_array($stem, self::POS_NEGATION_WORDS, true)
                      && !in_array($stem, self::POS_INTERROGATIVES, true)
                      && !in_array($stem, self::POS_QUALIFYING_DETERMINERS, true)
                      && !$this->is_known_dictionary_word($stem);
        if ($is_acronym) {
            $score += 4.0;
        }

        // Rareness factor (capped). A word in zero siblings is most
        // discriminative; word in many siblings is less so.
        $score += max(0, 5 - $sibling_freq);

        return $score;
    }

    /**
     * Look up POS tags for a stem. Consults POS_OVERRIDES first so
     * we can supplement WordNet's gaps (online, login, signin,
     * website, app, etc.) and correct WordNet's miscategorizations.
     * Returns a possibly-empty string of letters from {n,v,a,s,r}.
     */
    private function pos_for(string $stem): string {
        static $wordnet = null;
        if ($wordnet === null) {
            $wordnet = function_exists('CleverSay\\cleversay_wordnet_pos')
                ? \CleverSay\cleversay_wordnet_pos()
                : [];
        }
        if (isset(self::POS_OVERRIDES[$stem])) {
            return self::POS_OVERRIDES[$stem];
        }
        return $wordnet[$stem] ?? '';
    }

    /**
     * Check whether a stem is a "known" English word — present in
     * either the WordNet POS map (76K words) or the curated stem-
     * validation dictionary (the smaller list used for safe
     * stemming). Used by the acronym detector to distinguish
     * "weird short token that's probably an acronym" (UWSP, FAFSA)
     * from "weird short token that's actually a regular word"
     * (FAQ, sap, gpa — wait, those are in overrides as nouns).
     *
     * @since 4.37.25
     */
    private function is_known_dictionary_word(string $stem): bool {
        // Check WordNet POS map first (largest set).
        if ($this->pos_for($stem) !== '') {
            return true;
        }
        // Check stem-validation dictionary (curated).
        $dict = $this->stem_validation_dictionary();
        return isset($dict[$stem]);
    }

    /**
     * Drop alternatives whose required-word-set is a strict superset of
     * another alternative's. (Set-inclusion absorption: A | A&B = A.)
     */
    private function absorb_redundant_alternatives(array $alternatives): array {
        // Pre-compute word-set for each alternative.
        $sets = array_map(fn($a) => $this->term_words($a), $alternatives);
        $keep = array_fill(0, count($alternatives), true);
        for ($i = 0; $i < count($alternatives); $i++) {
            if (!$keep[$i]) continue;
            for ($j = 0; $j < count($alternatives); $j++) {
                if ($i === $j || !$keep[$j]) continue;
                // Drop $i if its word-set is a strict superset of $j's.
                // Strict: count($i) > count($j) and all $j words ⊂ $i.
                if (count($sets[$i]) > count($sets[$j])
                    && empty(array_diff($sets[$j], $sets[$i]))
                ) {
                    $keep[$i] = false;
                    break;
                }
            }
        }
        $out = [];
        foreach ($alternatives as $idx => $alt) {
            if ($keep[$idx]) $out[] = $alt;
        }
        return $out;
    }

    /**
     * Convert one stem→surface entry into its emitted term.
     * Phrase tokens (containing `+`) emit verbatim. Others get a wildcard.
     */
    private function as_term(string $surface): string {
        if (str_contains($surface, '+')) {
            return $surface;
        }
        return $surface . '*';
    }

    /**
     * Within a single AND-group, drop terms whose required words are a
     * subset of another term's required words. Keeps the most specific
     * terms; e.g. `[bill*, gi+bill, montgomery+gi+bill]` collapses to
     * `[montgomery+gi+bill]`. Order of input is otherwise preserved.
     */
    private function prune_subsumed_terms(array $terms): array {
        $kept = [];
        foreach ($terms as $candidate) {
            $candidate_words = $this->term_words($candidate);
            if (empty($candidate_words)) continue;
            $is_subsumed = false;
            foreach ($terms as $other) {
                if ($other === $candidate) continue;
                if ($this->subsumes($other, $candidate)) {
                    $is_subsumed = true;
                    break;
                }
            }
            if (!$is_subsumed) $kept[] = $candidate;
        }
        // De-dup while preserving order.
        return array_values(array_unique($kept));
    }

    /**
     * True if term A "covers" term B (so combining A AND B is redundant).
     * Both terms may contain phrase joiners (+) and/or wildcards (*).
     *
     * Example: "gi+bill" covers "bill*" because requiring "gi" AND "bill"
     * already requires "bill". Conversely, "bill*" does NOT cover "gi+bill"
     * because "gi" is not implied.
     */
    private function subsumes(string $covering, string $covered): bool {
        $covering_words = $this->term_words($covering);
        $covered_words  = $this->term_words($covered);
        if (empty($covering_words) || empty($covered_words)) return false;

        // Every word in $covered must appear in $covering.
        foreach ($covered_words as $w) {
            if (!in_array($w, $covering_words, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Extract the bare word(s) from a term, dropping wildcards and AND
     * separators. "gi+bill" → ['gi','bill']; "elig*" → ['elig'].
     */
    private function term_words(string $term): array {
        $term = str_replace('*', '', $term);
        $parts = preg_split('/[+&]/', $term);
        return array_values(array_filter(array_map('trim', $parts), 'strlen'));
    }

    /**
     * Group surfaces that share a long common prefix into a single
     * prefix-wildcard term, returning a possibly-shorter list.
     * Examples:
     *   ["eligible", "eligibility"]  → ["eligib"]   (then as_term → "eligib*")
     *   ["registration", "register"] → ["registration", "register"]
     *       (kept separate because "register" ends in production suffix "er")
     *   ["bill", "phone"]            → ["bill", "phone"]   (no shared prefix)
     *
     * Rules:
     *   - Phrase tokens (containing '+') are never clustered.
     *   - Members ending in a production-stemmer suffix (ing/ed/er/est/
     *     ly/ies/es/s) are unsafe to cluster — production rewrites them
     *     at query time and the cluster prefix could miss the actual
     *     token. Keep these as their own surface.
     *   - Common prefix must be ≥ 5 chars to be considered meaningful.
     *
     * Why this exists: when input variations include both "eligible" and
     * "eligibility", emitting them as two separate `eligible*` and
     * `eligibility*` alternatives produces a longer pattern AND fails to
     * match natural typos like "eligibal" (prefix-matches "eligib" but
     * not "eligible"). Clustering both into "eligib*" is what a human
     * pattern-author would write by hand and gives us free typo tolerance.
     */
    private function cluster_surfaces_by_prefix(array $surfaces): array {
        if (count($surfaces) < 2) return $surfaces;

        // Production stemmer's suffix list (`Search::apply_stemming`).
        // Surfaces ending in any of these may be rewritten at query
        // time, so a prefix cluster could miss the production token.
        $production_suffixes = ['ing', 'ed', 'er', 'est', 'ly', 'ies', 'es', 's'];

        $is_safe = function (string $s) use ($production_suffixes): bool {
            if (str_contains($s, '+')) return false;
            foreach ($production_suffixes as $suffix) {
                if (str_ends_with($s, $suffix)) return false;
            }
            return true;
        };

        // Sort alphabetically so surfaces sharing prefixes land adjacent.
        // ["eligibility", "benefits", "eligible"] → ["benefits", "eligibility", "eligible"]
        $sorted = $surfaces;
        sort($sorted);

        $groups = [];
        $current = [array_shift($sorted)];
        foreach ($sorted as $s) {
            $last = end($current);
            $can_cluster = $is_safe($s) && $is_safe($last);
            if ($can_cluster) {
                $prefix = $this->common_prefix($last, $s);
                if (strlen($prefix) >= 5) {
                    $current[] = $s;
                    continue;
                }
            }
            $groups[] = $current;
            $current = [$s];
        }
        $groups[] = $current;

        $out = [];
        foreach ($groups as $group) {
            if (count($group) === 1) {
                $out[] = $group[0];
                continue;
            }
            // LCP of every member of the group (not just adjacent pairs).
            $prefix = $group[0];
            foreach ($group as $m) {
                $prefix = $this->common_prefix($prefix, $m);
            }
            $out[] = $prefix;
        }
        return $out;
    }

    /**
     * Longest common prefix of two strings.
     */
    private function common_prefix(string $a, string $b): string {
        $len = min(strlen($a), strlen($b));
        $i = 0;
        while ($i < $len && $a[$i] === $b[$i]) $i++;
        return substr($a, 0, $i);
    }

    /**
     * Build a flat OR group like `stem1*|stem2*|phrase`. Used as the
     * fallback when topic/intent partitioning fails.
     */
    private function flat_or(array $stems_to_word): string {
        if (empty($stems_to_word)) return '';
        $alternatives = array_map([$this, 'as_term'], $stems_to_word);
        return implode('|', array_values($alternatives));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Split a string into lowercase tokens, dropping punctuation and stopwords.
     */
    private function tokenize(string $text): array {
        $text = mb_strtolower($text);
        // Keep word chars, replace everything else with space.
        $text = preg_replace('/[^a-z0-9\s]/u', ' ', $text);
        $words = preg_split('/\s+/', trim($text));
        $words = array_filter($words, function ($w) {
            return $w !== '' && !in_array($w, $this->stopwords, true);
        });
        return array_values($words);
    }

    /**
     * Find adjacent token pairs/triples that consistently appear together.
     * Returns an array of phrases like ['gi bill', 'financial aid'].
     *
     * Both trigrams and bigrams are collected as candidates, then ranked
     * jointly by COVERAGE (fraction of variations they appear in), then
     * length. Greedy non-overlapping selection follows. This replaces the
     * earlier "trigrams first, bigrams second" rule which preferred
     * length over coverage and could let a 75%-coverage trigram beat a
     * 100%-coverage bigram, claiming tokens the bigram needed.
     *
     * Concrete case this fixes: with variations
     *   1) "Am I eligible for Montgomery GI Bill benefits?"
     *   2) "What is the requirement for the Montgomery GI Bill?"
     *   3) "What is required for the GI Bill?"          ← no Montgomery
     *   4) "What is the eligibility for the Montgomery GI Bill?"
     * the trigram "montgomery gi bill" hits 3/4 = 75% (just), while the
     * bigram "gi bill" hits 4/4 = 100%. Old code accepted the trigram
     * first, which made V3 unmatchable by the resulting pattern. New code
     * picks "gi bill" — V3 is now representable as `<intent>&gi+bill`.
     */
    private function detect_phrases(array $tokenized): array {
        $variation_count = count($tokenized);
        if ($variation_count < 1) return [];

        $candidates = []; // each: ['phrase' => str, 'coverage' => float, 'length' => int]

        // Collect trigram candidates.
        $tri_in_var = [];
        foreach ($tokenized as $idx => $tokens) {
            $n = count($tokens);
            for ($i = 0; $i < $n - 2; $i++) {
                $tri = $tokens[$i] . ' ' . $tokens[$i + 1] . ' ' . $tokens[$i + 2];
                $tri_in_var[$tri][$idx] = true;
            }
        }
        foreach ($tri_in_var as $tri => $vars) {
            $vars_count = count($vars);
            $coverage = $vars_count / $variation_count;
            $appears_enough = $variation_count === 1 ? $vars_count >= 1 : $coverage >= 0.75;
            if (!$appears_enough) continue;
            $parts = explode(' ', $tri);
            if (min(array_map('strlen', $parts)) < 2) continue;
            $candidates[] = ['phrase' => $tri, 'coverage' => $coverage, 'length' => 3];
        }

        // Collect bigram candidates.
        $bi_in_var = [];
        foreach ($tokenized as $idx => $tokens) {
            $n = count($tokens);
            for ($i = 0; $i < $n - 1; $i++) {
                $bi = $tokens[$i] . ' ' . $tokens[$i + 1];
                $bi_in_var[$bi][$idx] = true;
            }
        }
        foreach ($bi_in_var as $bi => $vars) {
            $vars_count = count($vars);
            $coverage = $vars_count / $variation_count;
            // Bigrams need ≥ 50% coverage AND ≥ 2 variations (for N>1).
            $appears_enough = $variation_count === 1
                ? $vars_count >= 1
                : ($vars_count >= 2 && $coverage >= 0.5);
            if (!$appears_enough) continue;
            [$a, $b] = explode(' ', $bi);
            if (strlen($a) < 2 || strlen($b) < 2) continue;
            $candidates[] = ['phrase' => $bi, 'coverage' => $coverage, 'length' => 2];
        }

        // Rank: coverage desc, then length desc, then alpha asc for stability.
        usort($candidates, function ($a, $b) {
            if ($a['coverage'] !== $b['coverage']) return $b['coverage'] <=> $a['coverage'];
            if ($a['length']   !== $b['length'])   return $b['length']   <=> $a['length'];
            return strcmp($a['phrase'], $b['phrase']);
        });

        // Greedy: accept a phrase only if none of its tokens were already
        // claimed by a higher-ranked phrase. This stops "montgomery gi"
        // and "gi bill" from both being detected — whichever ranks higher
        // wins the shared "gi" token.
        $accepted = [];
        $claimed = [];
        foreach ($candidates as $c) {
            $tokens = explode(' ', $c['phrase']);
            $overlap = false;
            foreach ($tokens as $t) {
                if (isset($claimed[$t])) { $overlap = true; break; }
            }
            if ($overlap) continue;
            foreach ($tokens as $t) $claimed[$t] = true;
            $accepted[] = $c['phrase'];
        }

        return $accepted;
    }

    /**
     * Replace adjacent tokens that form a known phrase with a single
     * "joined" token using the + separator.
     */
    private function apply_phrases(array $tokenized, array $phrases): array {
        if (empty($phrases)) return $tokenized;

        // Sort phrases by length descending so longer phrases match first.
        usort($phrases, fn($a, $b) => strlen($b) <=> strlen($a));

        $out = [];
        foreach ($tokenized as $tokens) {
            $i = 0;
            $merged = [];
            while ($i < count($tokens)) {
                $matched = false;
                foreach ($phrases as $phrase) {
                    $parts = explode(' ', $phrase);
                    $len = count($parts);
                    if ($i + $len > count($tokens)) continue;
                    $slice = array_slice($tokens, $i, $len);
                    if (implode(' ', $slice) === $phrase) {
                        $merged[] = implode('+', $parts);
                        $i += $len;
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    $merged[] = $tokens[$i];
                    $i++;
                }
            }
            $out[] = $merged;
        }
        return $out;
    }

    /**
     * No-op stemmer. Earlier versions stripped suffixes aggressively
     * (-ility, -ement, -ed, -s, …) to cluster related surfaces under one
     * stem. That diverged from the production matcher's stemmer in
     * `Search::apply_stemming`, which is dictionary-validated and
     * conservative — e.g. "required" stays "required" because "requir"
     * isn't a dictionary word, while my old stemmer cut "required" AND
     * "requirement" both to "requir" and emitted "requirement*". Result:
     * the compiled pattern referenced "requirement*" but the production
     * pipeline produced the token "required" — pattern didn't match, the
     * input variation failed save validation.
     *
     * Replicating production's stemmer (with its dictionary) inside the
     * compiler would either couple the two paths or risk drift; for now
     * we just use the surface form as the stem. Words like "required"
     * and "requirement" become distinct intents, which makes patterns
     * slightly longer but guarantees that every input variation matches
     * the resulting pattern (the property save validation depends on).
     */
    /**
     * Stem a token using the same logic as Search::apply_stemming.
     *
     * v4.37.12+: Pre-4.37.12 this was a no-op (just lowercased) on
     * the theory that the runtime and compile-time stemmers might
     * drift. That caused a real bug: the compiler emitted patterns
     * like `grades*` (using the surface form), but the runtime
     * stemmed user tokens to `grade`, and `grades*` doesn't prefix-
     * match `grade`. Pattern validation then failed when the same
     * variations were re-checked, and live queries with stemmed forms
     * never matched.
     *
     * Now we replicate the runtime logic:
     *   1. Look up the irregular-forms table (WordNet exceptions).
     *   2. Otherwise, suffix-strip with doubled-consonant rules,
     *      validating the result against a small curated dictionary
     *      (NOT WordNet — that would re-introduce the legacy
     *      "being -> bee" over-stemming).
     *
     * Intentional drift risk mitigation: both the data file and the
     * dictionary used here are loaded by the same plugin as the
     * runtime, so they always travel together. The dictionary check
     * still keeps over-stemming in line ("process" stays "process",
     * not "proc").
     */
    private function stem(string $token): string {
        if (str_contains($token, '+')) return $token; // preserve phrases

        $word = strtolower(trim($token));
        if ($word === '') return '';

        // Tier 1: irregular-forms table (WordNet exceptions).
        if (function_exists('CleverSay\\cleversay_irregulars')) {
            $irreg = \CleverSay\cleversay_irregulars();
            if (isset($irreg[$word])) {
                return $irreg[$word];
            }
        }

        // Tier 2: rule-based suffix stripping with dictionary
        // validation. Mirrors Search::apply_stemming for matching
        // behavior at compile time.
        if (strlen($word) > 3) {
            $dict = $this->stem_validation_dictionary();

            $suffixes = ['ing', 'ed', 'er', 'est', 'ly', 'ies', 'es', 's'];
            foreach ($suffixes as $suffix) {
                if (!str_ends_with($word, $suffix)) continue;
                $base = substr($word, 0, -strlen($suffix));
                if ($base === '') break;

                if ($suffix === 'ies' && strlen($base) > 1) {
                    $candidate = $base . 'y';
                } elseif ($suffix === 'ing' && strlen($base) > 2) {
                    if (substr($base, -1) === substr($base, -2, 1)) {
                        // doubled consonant: dropp -> drop
                        $candidate = substr($base, 0, -1);
                    } else {
                        $candidate = $base . 'e'; // make/making
                    }
                } elseif ($suffix === 'ed' && strlen($base) > 2) {
                    if (substr($base, -1) === substr($base, -2, 1)) {
                        $candidate = substr($base, 0, -1); // admitt -> admit
                    } else {
                        $candidate = $base; // post(ed) -> post
                    }
                } elseif (strlen($base) > 2) {
                    $candidate = $base;
                    if ($suffix === 'es' && !isset($dict[$candidate])) {
                        $with_e = $base . 'e';
                        if (isset($dict[$with_e])) {
                            $candidate = $with_e;
                        }
                    }
                } else {
                    break;
                }

                if (isset($dict[$candidate])) {
                    return $candidate;
                }
                // v4.37.23+: also accept "re-" prefixed candidates
                // whose root is in the dict ("retake" -> re + "take").
                // Mirrors the same logic in Search::apply_stemming so
                // compile-time and runtime stemming stay aligned.
                if (strlen($candidate) > 3 && substr($candidate, 0, 2) === 're') {
                    $stripped = substr($candidate, 2);
                    if (isset($dict[$stripped])) {
                        return $candidate;
                    }
                }
                break; // matched a suffix but candidate not valid: keep original
            }
        }

        return $word;
    }

    /**
     * Small curated dictionary used to validate suffix-strip
     * candidates at compile time. Mirrors the validation pool used
     * by Search::apply_stemming, NOT the wider WordNet pool used
     * by the spell-corrector. Reason: WordNet contains both
     * "being" and "bee" so suffix-stripping `being -> bee` would
     * incorrectly pass validation. The curated list keeps the
     * stemmer narrow and avoids that legacy bug.
     *
     * Pulls KB keywords from the live DB so domain terms (admit,
     * withdraw, fafsa, etc.) stem cleanly. Static caching means
     * it's parsed once per request.
     */
    private function stem_validation_dictionary(): array {
        static $dict = null;
        if ($dict !== null) return $dict;

        // Hardcoded curated base — needs to recognize common
        // inflected forms' lemmas (so e.g. `grade` is recognized
        // when stemming `grades`). Keep narrow.
        $base = [
            'admit', 'advise', 'apply', 'appeal',
            'attend', 'audit',
            'begin', 'book',
            'call', 'cancel', 'card', 'change', 'check', 'class',
            'come', 'commit',
            'contact', 'copy', 'cost', 'count', 'course',
            'cover', 'create', 'credit', 'date',
            'declare', 'degree', 'department', 'deposit',
            'direct', 'drop', 'earn',
            'email', 'enroll', 'enter', 'event', 'exam',
            'fail', 'faq', 'fee', 'file', 'fill', 'find', 'finish',
            'fix', 'follow', 'form', 'forward',
            'go', 'grade', 'graduate',
            'have', 'help', 'hold', 'home', 'hour',
            'house', 'include', 'issue', 'join', 'know',
            'last', 'late', 'leave', 'list', 'live', 'load',
            'loan', 'log', 'look', 'major', 'make', 'manage',
            'meet', 'member', 'minor', 'miss', 'mobile', 'money',
            'need', 'note', 'office', 'open', 'order',
            'park', 'pass', 'past', 'pay', 'permit', 'phone',
            'pick', 'plan', 'play', 'post',
            'print', 'process', 'program', 'provide',
            'quit', 'read', 'receive', 'register',
            'repair', 'repeat', 'report', 'request', 'require',
            'reserve', 'return', 'review', 'rule',
            'save', 'schedule', 'search', 'send',
            'show', 'sign', 'start', 'stay', 'study',
            'submit', 'switch', 'take', 'talk',
            'test', 'thank', 'time', 'track',
            'transcript', 'transfer', 'tuition', 'turn', 'type',
            'update', 'use', 'verify', 'view', 'visit', 'wait',
            'walk', 'want', 'work', 'write', 'year',

            // v4.37.23+: re-prefixed and other action verbs that
            // suffix-strip rules produce as candidates. These need
            // to be in the validation dictionary or the rules fail
            // and tokens stay un-stemmed (e.g., "retaking" wouldn't
            // collapse to "retake" because "retake" wasn't in the
            // dict).
            'retake', 'reapply', 'resubmit', 'repay', 'restart',
            'reschedule', 'recheck',
            'accept', 'reject', 'edit', 'extend', 'lose',
            'upload', 'download', 'scan', 'waive', 'defer', 'postpone',
        ];

        $dict = array_fill_keys($base, true);

        // Add KB keywords from the live DB so domain organizing
        // terms stem cleanly.
        global $wpdb;
        if ($wpdb) {
            $rows = $wpdb->get_col("
                SELECT DISTINCT keyword
                  FROM {$wpdb->prefix}cleversay_knowledge
                 WHERE status = 'active'
            ");
            foreach ((array) $rows as $kw) {
                $kw = strtolower(trim((string) $kw));
                if ($kw !== '') $dict[$kw] = true;
            }
        }

        return $dict;
    }
}
