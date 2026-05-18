<?php
/**
 * CleverSay RequestTimer
 *
 * Per-request latency instrumentation. Singleton because PHP processes
 * one HTTP request per execution, so the singleton naturally resets
 * between requests with no cross-contamination risk.
 *
 * Usage:
 *
 *     RequestTimer::instance()->start_request();
 *     RequestTimer::instance()->start_stage('kb');
 *     // ... Layer 1 work ...
 *     RequestTimer::instance()->end_stage('kb');
 *     RequestTimer::instance()->set('matched_layer', 'kb_strong');
 *     RequestTimer::instance()->set('question_id', 1234);
 *     // wp_send_json_*() — shutdown function flushes
 *
 * `flush()` writes one structured log line and one row to
 * cleversay_request_metrics. Idempotent — safe for multiple call paths
 * to invoke and for the shutdown safety-net to run after explicit calls.
 *
 * v4.41.5+: introduced as part of the latency observability layer.
 *
 * @package CleverSay
 * @since   4.41.5
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class RequestTimer {

    private static ?RequestTimer $instance = null;

    /** Wall-clock start of the request, in seconds (microtime(true)). */
    private ?float $request_start = null;

    /** Per-stage start times, in seconds. Cleared from this map on end_stage(). */
    private array $stage_starts = [];

    /**
     * Per-stage durations, in milliseconds (int). One entry per completed
     * stage. Stages that never started have no entry; consumers that need
     * a NULL-able column should pass null when missing.
     *
     * @var array<string,int>
     */
    private array $stage_durations = [];

    /**
     * Context data (flags, IDs, token counts, cost). Populated as the
     * request runs. Defaults match the schema's NOT NULL / nullable
     * convention so we never crash on a missing setter call.
     */
    private array $context = [
        'question_id'       => null,
        'cache_hit'         => false,
        'ai_fallback_fired' => false,
        'gate_fired'        => null,
        'matched_layer'     => 'no_answer',
        'tokens_in'         => null,
        'tokens_out'        => null,
        'cost'              => null,
        // v4.41.5.3+: synthesizer model id for the row's AI call. NULL
        // when synthesis didn't run (kb_strong without validate_kb,
        // no_answer paths). Recorded per-row so a mid-window model
        // change produces a clean A/B in the dashboard rather than
        // smearing the two together.
        'synthesis_model'   => null,
    ];

    /** Once flush() succeeds, subsequent calls are no-ops. */
    private bool $flushed = false;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset the singleton state. Mostly useful in tests; production
     * doesn't need it because each PHP request gets a fresh process.
     */
    public static function reset(): void {
        self::$instance = null;
    }

    /**
     * Mark the request as started. Idempotent — calling twice doesn't
     * restart the clock. Required before any stage timing or flush.
     */
    public function start_request(): void {
        if ($this->request_start === null) {
            $this->request_start = microtime(true);
        }
    }

    /** Has start_request() been called? */
    public function is_started(): bool {
        return $this->request_start !== null;
    }

    /**
     * Begin a named stage. Stage names are free-form strings; the
     * conventional ones are 'kb', 'retrieval', 'synthesis', 'render'.
     * Calling start_stage() twice for the same name silently overwrites
     * the start — useful for retries, harmful only if you forget to
     * end the prior occurrence (which shows up as a missing duration,
     * not a fatal).
     */
    public function start_stage(string $name): void {
        $this->stage_starts[$name] = microtime(true);
    }

    /**
     * End a named stage. Records the elapsed time in milliseconds.
     * If the stage was never started (e.g. an early-return path), this
     * is a no-op rather than an error — the column ends up NULL.
     */
    public function end_stage(string $name): void {
        if (!isset($this->stage_starts[$name])) {
            return;
        }
        $ms = (int) round((microtime(true) - $this->stage_starts[$name]) * 1000);
        $this->stage_durations[$name] = $ms;
        unset($this->stage_starts[$name]);
    }

    /**
     * Set a context field. Whitelisted to known fields so a typo doesn't
     * silently disappear into a junk array. Unknown keys are ignored
     * with a logger warning, since failing here would defeat the whole
     * "instrumentation must never affect the request" property.
     *
     * @param mixed $value
     */
    public function set(string $key, $value): void {
        if (!array_key_exists($key, $this->context)) {
            Logger::instance()->warning('RequestTimer::set called with unknown key', [
                'key' => $key,
            ]);
            return;
        }
        $this->context[$key] = $value;
    }

    /**
     * Read a context field — handy for debugging or for code that needs
     * to check a flag (e.g. "did we already mark ai_fallback_fired?").
     *
     * @return mixed
     */
    public function get(string $key) {
        return $this->context[$key] ?? null;
    }

    /**
     * Get a stage duration in ms, or null if the stage never ran.
     */
    public function stage_ms(string $name): ?int {
        return $this->stage_durations[$name] ?? null;
    }

    /**
     * Total request wall-clock time in ms. Returns null if start_request()
     * was never called.
     */
    public function total_ms(): ?int {
        if ($this->request_start === null) {
            return null;
        }
        return (int) round((microtime(true) - $this->request_start) * 1000);
    }

    /**
     * Write the structured log line and insert the metrics row. Safe to
     * call multiple times — only the first call does work.
     *
     * Logging is best-effort — failures must never bubble up to the user
     * request. Wraps everything in try/catch and logs internally on
     * failure.
     */
    public function flush(): void {
        if ($this->flushed) {
            return;
        }
        if ($this->request_start === null) {
            // start_request() was never called. Nothing to flush. This
            // happens for non-search admin AJAX, REST endpoints, etc.
            $this->flushed = true;
            return;
        }
        $this->flushed = true; // set early so a throw inside doesn't loop on shutdown

        try {
            $total_ms     = $this->total_ms();
            $kb_ms        = $this->stage_ms('kb');
            $retrieval_ms = $this->stage_ms('retrieval');
            $synthesis_ms = $this->stage_ms('synthesis');
            $render_ms    = $this->stage_ms('render');

            // v4.41.5+: if 'render' wasn't explicitly instrumented, derive
            // it as the leftover wall-clock time after the other stages.
            // Conceptually "render" is everything from when the answer text
            // is decided to when JSON is sent — formatting, citation
            // assembly, translation, JSON encode. There's no single
            // function call to wrap, but the leftover-time math is
            // accurate as long as no other significant work happens
            // outside the named stages. Floor at 0 for safety against
            // clock weirdness.
            if ($render_ms === null && $total_ms !== null) {
                $accounted = ($kb_ms ?? 0) + ($retrieval_ms ?? 0) + ($synthesis_ms ?? 0);
                $render_ms = max(0, $total_ms - $accounted);
            }

            // Structured log line — pipe-delimited single line for easy
            // grep/awk on the log file. Field order is stable so downstream
            // tools can rely on positional parsing if they need to.
            $line = sprintf(
                'Request timing | total_ms:%s, kb_ms:%s, retrieval_ms:%s, synthesis_ms:%s, render_ms:%s'
                . ', cache_hit:%s, ai_fallback_fired:%s, gate_fired:%s, matched_layer:%s'
                . ', tokens_in:%s, tokens_out:%s, cost:%s, synthesis_model:%s, question_id:%s',
                $this->fmt_int($total_ms),
                $this->fmt_int($kb_ms),
                $this->fmt_int($retrieval_ms),
                $this->fmt_int($synthesis_ms),
                $this->fmt_int($render_ms),
                $this->context['cache_hit'] ? 'true' : 'false',
                $this->context['ai_fallback_fired'] ? 'true' : 'false',
                $this->context['gate_fired'] === null
                    ? 'null'
                    : ($this->context['gate_fired'] ? 'true' : 'false'),
                $this->context['matched_layer'],
                $this->fmt_int($this->context['tokens_in']),
                $this->fmt_int($this->context['tokens_out']),
                $this->fmt_cost($this->context['cost']),
                $this->context['synthesis_model'] === null ? 'null' : (string) $this->context['synthesis_model'],
                $this->fmt_int($this->context['question_id'])
            );
            Logger::instance()->info($line);

            $this->insert_db_row(
                $total_ms,
                $kb_ms,
                $retrieval_ms,
                $synthesis_ms,
                $render_ms
            );
        } catch (\Throwable $e) {
            // Instrumentation must never break the request. Log and move on.
            try {
                Logger::instance()->error('RequestTimer flush failed', [
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $inner) {
                // Truly unrecoverable — give up silently.
            }
        }
    }

    /**
     * Insert one row into wp_X_cleversay_request_metrics for this site.
     * Per-tenant table because the existing CleverSay table convention
     * uses the wp_X_ prefix; metrics rows for site 4 live in
     * wp_4_cleversay_request_metrics.
     *
     * Bypass when question_id is null — the schema requires NOT NULL FK.
     * We still emit the log line in that case (for forensic value), but
     * we don't insert a row that can't be FK'd back to a question.
     */
    private function insert_db_row(
        ?int $total_ms,
        ?int $kb_ms,
        ?int $retrieval_ms,
        ?int $synthesis_ms,
        ?int $render_ms
    ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_request_metrics';

        $question_id = $this->context['question_id'];
        if (!is_int($question_id) || $question_id <= 0) {
            // No FK target. Skip DB row; the log line is still recorded.
            return;
        }
        // total_ms is NOT NULL in schema; if somehow null (extremely
        // unlikely after start_request was called), default to 0.
        $total_ms = $total_ms ?? 0;
        $kb_ms    = $kb_ms    ?? 0;  // NOT NULL — Layer 1 always runs
        $render_ms = $render_ms ?? 0; // NOT NULL — assemble always runs

        $data = [
            'question_id'       => $question_id,
            'total_ms'          => $total_ms,
            'kb_ms'             => $kb_ms,
            'retrieval_ms'      => $retrieval_ms,   // nullable
            'synthesis_ms'      => $synthesis_ms,   // nullable
            'render_ms'         => $render_ms,
            'ai_fallback_fired' => $this->context['ai_fallback_fired'] ? 1 : 0,
            'cache_hit'         => $this->context['cache_hit'] ? 1 : 0,
            'gate_fired'        => $this->context['gate_fired'] === null
                ? null
                : ($this->context['gate_fired'] ? 1 : 0),
            'matched_layer'     => (string) $this->context['matched_layer'],
            'tokens_in'         => $this->context['tokens_in'],
            'tokens_out'        => $this->context['tokens_out'],
            'cost'              => $this->context['cost'],
            'synthesis_model'   => $this->context['synthesis_model'], // nullable string
            'created_at'        => current_time('mysql'),
        ];

        // Format hints — wpdb needs them parallel to $data, in the same
        // key order, so iterate $data to keep them aligned.
        $formats = [];
        foreach ($data as $k => $v) {
            if ($v === null) {
                $formats[] = '%s'; // wpdb writes NULL when value is null regardless of format
            } elseif (in_array($k, ['cost'], true)) {
                $formats[] = '%f';
            } elseif (in_array($k, ['matched_layer', 'created_at', 'synthesis_model'], true)) {
                $formats[] = '%s';
            } else {
                $formats[] = '%d';
            }
        }

        $wpdb->insert($table, $data, $formats);
    }

    private function fmt_int(?int $v): string {
        return $v === null ? 'null' : (string) $v;
    }

    private function fmt_cost($v): string {
        if ($v === null) {
            return 'null';
        }
        return number_format((float) $v, 6, '.', '');
    }
}
