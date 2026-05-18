<?php
/**
 * CleverSay BulkTester
 *
 * v4.42.0+: bulk-question qualification runner. Used during pre-deployment
 * to feed historical + speculative student questions through the same
 * search path as live traffic, capturing per-question signals (Layer 1
 * outcome, retrieval results, synthesis answer, latency, cost) for
 * offline review.
 *
 * Architecture: browser-driven AJAX loop.
 *   1. Operator uploads CSV via the admin page.
 *   2. Server creates one bulk_test_runs row + N bulk_test_results rows
 *      in pending status. Returns the run_id.
 *   3. Browser JS loops through the pending rows, calling a "process
 *      one row" AJAX endpoint per row. Each call processes a single
 *      question and returns immediately.
 *   4. Operator sees results render progressively. Closing the tab
 *      stops the run; a refresh shows progress so far. Run history is
 *      persisted so completed runs can be re-downloaded later.
 *
 * Why browser-driven instead of cron-based:
 *   - No cron coordination, no rescheduling, no batch state machine.
 *   - Progress and abort are free — they're just whether the tab is open.
 *   - One question per AJAX request fits comfortably under PHP timeouts
 *     without needing set_time_limit(0).
 *   - Operator feedback is immediate (each completed row appears in the
 *     table as it finishes).
 *
 * Each question runs in isolation — NO conversation history is passed
 * forward between questions. Each row evaluates the system's behavior
 * on that question alone.
 *
 * @package CleverSay
 * @since   4.42.0
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class BulkTester {

    /**
     * Create a new run and seed the results rows. Called from the admin
     * page after CSV upload + parse. Returns the run_id, which the
     * browser then uses to drive the per-row processing loop.
     *
     * @param array{question:string,notes?:string}[] $rows  Parsed CSV rows.
     * @param string|null                            $label Optional human label.
     * @return int run_id, or 0 on failure.
     */
    public static function create_run(array $rows, ?string $label = null): int {
        global $wpdb;
        $db = new Database();

        if (empty($rows)) {
            return 0;
        }

        // Capture the active synthesis model at run-create time so the
        // run's metadata reflects what produced the answers, even if
        // the operator changes the model setting mid-run.
        $synthesis_model = (string) NetworkSettings::get_ai_value(
            'synthesis_model',
            'claude-sonnet-4-5-20250929'
        );

        $ok = $wpdb->insert(
            $db->bulk_test_runs,
            [
                'label'             => $label,
                'status'            => 'pending',
                'total_questions'   => count($rows),
                'completed_questions' => 0,
                'synthesis_model'   => $synthesis_model,
                'total_cost'        => 0,
                'created_by'        => (int) get_current_user_id(),
                'created_at'        => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%d', '%s', '%f', '%d', '%s']
        );
        if (!$ok) {
            Logger::instance()->error('BulkTester: failed to create run', [
                'wpdb_error' => $wpdb->last_error,
            ]);
            return 0;
        }
        $run_id = (int) $wpdb->insert_id;

        foreach ($rows as $i => $row) {
            $question = trim((string) ($row['question'] ?? ''));
            if ($question === '') continue;
            $notes = trim((string) ($row['notes'] ?? ''));
            $wpdb->insert(
                $db->bulk_test_results,
                [
                    'run_id'     => $run_id,
                    'row_index'  => $i,
                    'question'   => $question,
                    'notes'      => $notes !== '' ? $notes : null,
                    'status'     => 'pending',
                    'created_at' => current_time('mysql'),
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s']
            );
        }

        return $run_id;
    }

    /**
     * Process a single question by id. Called once per AJAX request from
     * the browser-driven loop. Returns the captured result data so the
     * browser can render it inline without a second fetch.
     *
     * @return array{ok:bool,error?:string,result?:array,already_done?:bool}
     */
    public static function process_one(int $result_id): array {
        global $wpdb;
        $db = new Database();
        $logger = Logger::instance();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$db->bulk_test_results} WHERE id = %d",
            $result_id
        ), ARRAY_A);
        if (!$row) {
            return ['ok' => false, 'error' => 'Result row not found'];
        }
        if ($row['status'] !== 'pending') {
            // Already processed (or in flight). Return existing data
            // to defend against double-clicks and AJAX retries.
            return [
                'ok'           => true,
                'result'       => $row,
                'already_done' => true,
            ];
        }

        // Honor abort: if operator stopped the run, refuse to process
        // more rows. Their pending status remains so it's clear what
        // wasn't run.
        $run_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$db->bulk_test_runs} WHERE id = %d",
            (int) $row['run_id']
        ));
        if ($run_status === 'aborted') {
            return ['ok' => false, 'error' => 'Run aborted'];
        }

        // Mark run as running on first row.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$db->bulk_test_runs}
             SET status = 'running', started_at = COALESCE(started_at, %s)
             WHERE id = %d AND status = 'pending'",
            current_time('mysql'),
            (int) $row['run_id']
        ));

        // Mark this row as running so concurrent requests don't double-process.
        $wpdb->update(
            $db->bulk_test_results,
            ['status' => 'running'],
            ['id' => $result_id],
            ['%s'],
            ['%d']
        );

        $result = self::run_question((string) $row['question']);

        $update = [
            'status'                => $result['error'] === null ? 'done' : 'failed',
            'matched_layer'         => $result['matched_layer'],
            'ai_fallback_fired'     => $result['ai_fallback_fired'] ? 1 : 0,
            'top_vector_similarity' => $result['top_vector_similarity'],
            'top_vector_chunk_id'   => $result['top_vector_chunk_id'],
            'top_fulltext_chunk_id' => $result['top_fulltext_chunk_id'],
            'gate_fired'            => $result['gate_fired'] === null
                ? null
                : ($result['gate_fired'] ? 1 : 0),
            'synthesis_model'       => $result['synthesis_model'],
            'answer_text'           => $result['answer_text'],
            'tokens_in'             => $result['tokens_in'],
            'tokens_out'            => $result['tokens_out'],
            'cost'                  => $result['cost'],
            'total_ms'              => $result['total_ms'],
            'error'                 => $result['error'],
        ];
        $wpdb->update($db->bulk_test_results, $update, ['id' => $result_id]);

        // Update run-level totals.
        $completed = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$db->bulk_test_results}
             WHERE run_id = %d AND status IN ('done','failed')",
            (int) $row['run_id']
        ));
        $cost_increment = $result['cost'] !== null ? (float) $result['cost'] : 0.0;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$db->bulk_test_runs}
             SET completed_questions = %d,
                 total_cost = total_cost + %f
             WHERE id = %d",
            $completed,
            $cost_increment,
            (int) $row['run_id']
        ));

        // Mark run complete if no rows remain pending.
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$db->bulk_test_results}
             WHERE run_id = %d AND status IN ('pending','running')",
            (int) $row['run_id']
        ));
        if ($remaining === 0) {
            $wpdb->update(
                $db->bulk_test_runs,
                [
                    'status'      => 'completed',
                    'finished_at' => current_time('mysql'),
                ],
                ['id' => (int) $row['run_id'], 'status' => 'running'],
                ['%s', '%s'],
                ['%d', '%s']
            );
        }

        $persisted = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$db->bulk_test_results} WHERE id = %d",
            $result_id
        ), ARRAY_A);

        return ['ok' => true, 'result' => $persisted];
    }

    /**
     * Run a single question through the production search + AI path.
     * Returns a normalized result blob. Never throws — errors are
     * captured into the 'error' field.
     */
    private static function run_question(string $question): array {
        $logger = Logger::instance();
        $start = microtime(true);

        $out = [
            'matched_layer'         => null,
            'ai_fallback_fired'     => false,
            'top_vector_similarity' => null,
            'top_vector_chunk_id'   => null,
            'top_fulltext_chunk_id' => null,
            'gate_fired'            => null,
            'synthesis_model'       => null,
            'answer_text'           => '',
            'tokens_in'             => null,
            'tokens_out'            => null,
            'cost'                  => null,
            'total_ms'              => 0,
            'error'                 => null,
        ];

        // Reset RequestTimer between questions — it's a per-request
        // singleton and we're processing many questions in this PHP process.
        RequestTimer::reset();
        $timer = RequestTimer::instance();
        $timer->start_request();

        // Reset retrieval diagnostics so we capture this question's
        // results, not whatever was left from a prior call.
        if (method_exists('\\CleverSay\\Retriever', 'clear_last_diagnostics')) {
            Retriever::clear_last_diagnostics();
        }

        try {
            $search = new Search();
            $timer->start_stage('kb');
            $results = $search->search($question);
            $timer->end_stage('kb');

            $matches       = $results['matches'] ?? [];
            $best_score    = !empty($matches) ? (int) ($matches[0]['score'] ?? 0) : 0;
            $is_broad_only = !empty($matches) && ($matches[0]['match_type'] ?? '') === 'broad';
            $ai_min_score  = (int) NetworkSettings::get_adv_value('min_match_score', 70);
            $layer1_strong = !empty($matches) && !$is_broad_only && $best_score >= $ai_min_score;

            if ($layer1_strong) {
                // v4.42.0.2+: run the same Layer 1 pipeline production
                // uses — validator (if enabled) + polish (if accepted)
                // + AI reroute (if validator rejects). Without this, the
                // bulk runner shows pre-validator KB text, which can
                // differ substantially from what users actually see.
                //
                // matched_layer values produced here:
                //   kb_strong         — validator/polish disabled, raw KB served
                //   kb_strong_polished— accepted by validator + polished by AI
                //   kb_strong_raw     — accepted by validator, polish skipped (already polished)
                //   kb_ai_rerouted    — validator rejected, fell through to AI synthesis
                $public = new PublicFacing();
                if (method_exists($public, 'run_layer1_pipeline_for_test')) {
                    $first = $matches[0];
                    $pipeline = $public->run_layer1_pipeline_for_test($question, $first);
                    $out['answer_text'] = (string) $pipeline['answer'];

                    $layer_label = match ($pipeline['path']) {
                        'kb_polished'    => 'kb_strong_polished',
                        'kb_ai_rerouted' => 'kb_ai_rerouted',
                        default          => 'kb_strong_raw',
                    };
                    $timer->set('matched_layer', $layer_label);
                } else {
                    // Fallback for environments without the test seam:
                    // serve raw KB. Logged so it's visible if anyone
                    // wonders why bulk results don't match production.
                    $logger->warning('BulkTester: PublicFacing::run_layer1_pipeline_for_test missing; serving raw KB');
                    $first = $matches[0];
                    $out['answer_text'] = (string) ($first['response'] ?? $first['answer'] ?? '');
                    $timer->set('matched_layer', 'kb_strong');
                }
            } else {
                // Layer 2: AI fallback.
                $public = new PublicFacing();
                if (method_exists($public, 'run_ai_fallback_for_test')) {
                    $answer = $public->run_ai_fallback_for_test($question);
                } else {
                    // Fallback for environments where the test seam
                    // wasn't installed: use reflection on the private
                    // try_ai_fallback method.
                    $logger->warning('BulkTester: PublicFacing::run_ai_fallback_for_test missing; using reflection fallback');
                    $reflection = new \ReflectionClass($public);
                    if ($reflection->hasMethod('try_ai_fallback')) {
                        $method = $reflection->getMethod('try_ai_fallback');
                        $method->setAccessible(true);
                        $answer = $method->invoke($public, $question, '');
                    } else {
                        $answer = null;
                    }
                }

                if ($answer !== null && $answer !== '') {
                    $out['answer_text'] = (string) $answer;
                    $has_matches = !empty($matches);
                    $timer->set('matched_layer', $has_matches ? 'kb_weak_with_ai' : 'ai_only');
                } else {
                    $timer->set('matched_layer', 'no_answer');
                    $out['answer_text'] = '';
                }
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
            $logger->error('BulkTester: run_question threw', [
                'error' => $e->getMessage(),
            ]);
        }

        $out['total_ms'] = (int) round((microtime(true) - $start) * 1000);

        // Pull diagnostics from RequestTimer (synthesis side) and
        // Retriever's static accessor (retrieval side).
        $out['matched_layer']     = $timer->get('matched_layer');
        $out['ai_fallback_fired'] = (bool) $timer->get('ai_fallback_fired');
        $out['synthesis_model']   = $timer->get('synthesis_model');
        $out['tokens_in']         = $timer->get('tokens_in');
        $out['tokens_out']        = $timer->get('tokens_out');
        $out['cost']              = $timer->get('cost');

        if (method_exists('\\CleverSay\\Retriever', 'get_last_diagnostics')) {
            $diag = Retriever::get_last_diagnostics();
            if ($diag) {
                $out['top_vector_similarity'] = isset($diag['top_vector_similarity'])
                    ? (float) $diag['top_vector_similarity'] : null;
                $out['top_vector_chunk_id'] = isset($diag['top_vector_chunk_id'])
                    ? (int) $diag['top_vector_chunk_id'] : null;
                $out['top_fulltext_chunk_id'] = isset($diag['top_fulltext_chunk_id'])
                    ? (int) $diag['top_fulltext_chunk_id'] : null;
                $out['gate_fired'] = isset($diag['gate_triggered'])
                    ? (bool) $diag['gate_triggered'] : null;
            }
        }

        return $out;
    }

    public static function abort(int $run_id): void {
        global $wpdb;
        $db = new Database();
        $wpdb->update(
            $db->bulk_test_runs,
            [
                'status'      => 'aborted',
                'finished_at' => current_time('mysql'),
            ],
            ['id' => $run_id],
            ['%s', '%s'],
            ['%d']
        );
    }

    public static function get_run(int $run_id): ?array {
        global $wpdb;
        $db = new Database();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$db->bulk_test_runs} WHERE id = %d",
            $run_id
        ), ARRAY_A);
        return $row ?: null;
    }

    public static function list_runs(int $limit = 25): array {
        global $wpdb;
        $db = new Database();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$db->bulk_test_runs}
             ORDER BY created_at DESC
             LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];
    }

    public static function get_results(int $run_id): array {
        global $wpdb;
        $db = new Database();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$db->bulk_test_results}
             WHERE run_id = %d
             ORDER BY row_index ASC",
            $run_id
        ), ARRAY_A) ?: [];
    }

    public static function get_pending_ids(int $run_id): array {
        global $wpdb;
        $db = new Database();
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$db->bulk_test_results}
             WHERE run_id = %d AND status = 'pending'
             ORDER BY row_index ASC",
            $run_id
        ));
        return array_map('intval', $ids ?: []);
    }
}
