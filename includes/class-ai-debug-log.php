<?php
/**
 * CleverSay AI Debug Log
 *
 * Captures AI request/response data for diagnostic review.
 *
 * Logging is OFF by default. Two ways to turn it on:
 *
 *   1. Manual capture — admin enables a 50-question capture window from
 *      the AI Inspector page. After 50 entries, auto-disables.
 *
 *   2. Automatic capture on negative rating — when a visitor rates an
 *      answer thumbs-down, the most recent AI call gets captured even
 *      if manual capture is off. This lets admins review what went wrong
 *      without having to predict in advance which questions will fail.
 *
 * @package CleverSay
 * @since 4.30.0
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class AIDebugLog {

    /** Option key tracking whether manual capture is currently active. */
    const OPT_CAPTURE_ENABLED = 'cleversay_ai_debug_capture_enabled';

    /** Option key tracking how many slots remain in the current capture window. */
    const OPT_CAPTURE_REMAINING = 'cleversay_ai_debug_capture_remaining';

    /** Option key controlling auto-capture-on-negative-rating. Default ON. */
    const OPT_AUTO_ON_NEGATIVE = 'cleversay_ai_debug_auto_on_negative';

    /** How many days to retain debug log entries. */
    const RETENTION_DAYS = 30;

    /** How many entries to capture in a manual session. */
    const CAPTURE_SESSION_SIZE = 50;

    /**
     * Should we capture this AI call?
     */
    public static function should_capture(): bool {
        if (get_option(self::OPT_CAPTURE_ENABLED, false)) {
            $remaining = (int) get_option(self::OPT_CAPTURE_REMAINING, 0);
            if ($remaining > 0) {
                return true;
            }
            // Window exhausted — auto-disable.
            update_option(self::OPT_CAPTURE_ENABLED, false);
        }
        return false;
    }

    /**
     * Capture an entry. Pass the full diagnostic payload.
     *
     * @param array $data {
     *     @type string  $question
     *     @type string  $system_prompt
     *     @type array   $chunks         Each: ['source_title' => '', 'content' => '']
     *     @type array   $history        Each: ['role' => 'user|assistant', 'content' => '']
     *     @type string  $ai_response    Raw response from model
     *     @type string  $final_answer   What the user actually saw
     *     @type ?string $kb_match_keyword
     *     @type ?int    $kb_match_score
     *     @type ?string $validator_decision  'pass' | 'fail' | null
     *     @type bool    $polish_applied
     *     @type ?int    $latency_ms
     *     @type ?int    $question_id
     *     @type ?int    $ai_answer_id
     *     @type string  $trigger_reason  'manual' | 'negative_rating' | 'forced'
     * }
     */
    public static function capture(array $data): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_ai_debug_log';

        // Best-effort write — failure must NOT break the user-facing response.
        // Wrap in try/catch + suppress wpdb error display.
        $suppress = $wpdb->suppress_errors(true);

        try {
            $row = [
                'question_id'        => isset($data['question_id'])  ? (int)$data['question_id']  : null,
                'ai_answer_id'       => isset($data['ai_answer_id']) ? (int)$data['ai_answer_id'] : null,
                'question'           => mb_substr((string)($data['question'] ?? ''), 0, 5000),
                'system_prompt'      => mb_substr((string)($data['system_prompt'] ?? ''), 0, 200000),
                'chunks_json'        => wp_json_encode($data['chunks']  ?? []),
                'history_json'       => wp_json_encode($data['history'] ?? []),
                'ai_response'        => mb_substr((string)($data['ai_response']  ?? ''), 0, 100000),
                'final_answer'       => mb_substr((string)($data['final_answer'] ?? ''), 0, 100000),
                'kb_match_keyword'   => $data['kb_match_keyword']   ?? null,
                'kb_match_score'     => isset($data['kb_match_score']) ? (int)$data['kb_match_score'] : null,
                'validator_decision' => $data['validator_decision'] ?? null,
                'polish_applied'     => !empty($data['polish_applied']) ? 1 : 0,
                'latency_ms'         => isset($data['latency_ms']) ? (int)$data['latency_ms'] : null,
                'trigger_reason'     => (string)($data['trigger_reason'] ?? 'manual'),
                // Explicit UTC timestamp — don't rely on MySQL's DEFAULT
                // CURRENT_TIMESTAMP, which uses the session timezone (varies
                // by host config). gmdate guarantees UTC regardless of host.
                'created_at'         => gmdate('Y-m-d H:i:s'),
            ];

            $ok = $wpdb->insert($table, $row);

            // Decrement remaining-slot counter if this was a manual capture.
            if ($ok && $row['trigger_reason'] === 'manual') {
                $remaining = (int) get_option(self::OPT_CAPTURE_REMAINING, 0);
                if ($remaining > 0) {
                    update_option(self::OPT_CAPTURE_REMAINING, max(0, $remaining - 1));
                }
            }

            return $ok ? (int) $wpdb->insert_id : null;
        } catch (\Throwable $e) {
            // Swallow — debug logging must never affect production traffic.
            return null;
        } finally {
            $wpdb->suppress_errors($suppress);
        }
    }

    /**
     * Start a manual capture window. Captures the next N AI calls then
     * auto-disables.
     */
    public static function start_capture(int $size = self::CAPTURE_SESSION_SIZE): void {
        update_option(self::OPT_CAPTURE_ENABLED, true);
        update_option(self::OPT_CAPTURE_REMAINING, $size);
    }

    /**
     * Stop manual capture immediately.
     */
    public static function stop_capture(): void {
        update_option(self::OPT_CAPTURE_ENABLED, false);
        update_option(self::OPT_CAPTURE_REMAINING, 0);
    }

    /**
     * How many slots remain in the current capture window? 0 if not active.
     */
    public static function capture_status(): array {
        return [
            'enabled'   => (bool) get_option(self::OPT_CAPTURE_ENABLED, false),
            'remaining' => (int)  get_option(self::OPT_CAPTURE_REMAINING, 0),
        ];
    }

    /**
     * Capture the most recent AI call as a "negative rating" debug entry.
     * Called from the rating handler when a thumbs-down arrives.
     *
     * Strategy: we don't have the prompt/chunks/etc. at rating time, so
     * we look up the matching ai_answer row and capture what we can.
     * Most useful in combination with a callback that pre-stages the data.
     */
    public static function flag_for_negative_rating(int $ai_answer_id): void {
        if (!get_option(self::OPT_AUTO_ON_NEGATIVE, true)) {
            return;
        }

        global $wpdb;
        $debug_table = $wpdb->prefix . 'cleversay_ai_debug_log';

        // If a debug entry was already captured for this ai_answer (manual
        // capture was on), promote its trigger_reason to flag negative.
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $debug_table WHERE ai_answer_id = %d ORDER BY id DESC LIMIT 1",
            $ai_answer_id
        ));

        if ($existing_id) {
            $wpdb->update(
                $debug_table,
                ['trigger_reason' => 'negative_rating'],
                ['id' => (int) $existing_id]
            );
            return;
        }

        // No prior capture — write what we can from the ai_answer row.
        $ai_answers = $wpdb->prefix . 'cleversay_ai_answers';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT question, answer FROM $ai_answers WHERE id = %d",
            $ai_answer_id
        ));
        if (!$row) return;

        self::capture([
            'question'       => (string) $row->question,
            'system_prompt'  => '[Not captured — debug capture was off when this question was answered. Enable capture mode and ask the question again to see the full prompt.]',
            'final_answer'   => (string) $row->answer,
            'ai_answer_id'   => $ai_answer_id,
            'trigger_reason' => 'negative_rating',
        ]);
    }

    /**
     * Get recent debug entries. For the inspector list view.
     */
    public static function get_recent(int $limit = 50, int $offset = 0): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_ai_debug_log';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, question, kb_match_keyword, kb_match_score,
                    validator_decision, polish_applied, latency_ms,
                    trigger_reason, created_at
             FROM $table
             ORDER BY id DESC
             LIMIT %d OFFSET %d",
            $limit, $offset
        ), ARRAY_A);
        return $rows ?: [];
    }

    /**
     * Total count of entries (for pagination).
     */
    public static function count_all(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_ai_debug_log';
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    }

    /**
     * Get a single entry by id (full detail).
     */
    public static function get(int $id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_ai_debug_log';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d", $id
        ), ARRAY_A);
        if (!$row) return null;

        // Decode JSON fields for consumer convenience.
        $row['chunks']  = !empty($row['chunks_json'])  ? json_decode($row['chunks_json'],  true) : [];
        $row['history'] = !empty($row['history_json']) ? json_decode($row['history_json'], true) : [];
        return $row;
    }

    /**
     * Delete entries older than retention window. Run by daily cron.
     */
    public static function prune(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_ai_debug_log';
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400);
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE created_at < %s",
            $cutoff
        ));
    }

    /**
     * Empty all debug entries (admin action). Returns rows deleted.
     */
    public static function clear_all(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_ai_debug_log';
        return (int) $wpdb->query("DELETE FROM $table");
    }
}
