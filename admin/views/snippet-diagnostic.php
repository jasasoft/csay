<?php
/**
 * Snippet Diagnostic — admin debugging tool
 *
 * Investigate why a particular citation snippet looks wrong by walking
 * through the chunk selection + sentence picking step by step. Inputs
 * a source_id and a question; outputs:
 *
 *   1. All chunks for the source with leading text
 *   2. Each chunk's chunk-level score (which determines which chunk is
 *      picked for snippeting)
 *   3. The selected chunk's sentence-level breakdown, showing each
 *      sentence's score and which would win
 *   4. The final snippet that would render
 *
 * Use case: a citation snippet looks bad. Open this page, paste the
 * source_id and question. See exactly where the algorithm is making
 * a poor choice — wrong chunk picked, wrong sentence picked, or
 * fallback firing.
 *
 * Not linked from the main menu. URL:
 *   /wp-admin/admin.php?page=cleversay-snippet-diag
 *
 * @since 4.37.102
 */

if (!defined('ABSPATH') || !current_user_can('manage_options')) {
    return;
}

global $wpdb;
$db = new \CleverSay\Database();

$source_id = isset($_POST['source_id']) ? (int) $_POST['source_id'] : 0;
$question  = isset($_POST['question']) ? sanitize_text_field((string) $_POST['question']) : '';

// Verify nonce on POST
if (!empty($_POST) && (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'cleversay_snippet_diag'))) {
    wp_die('Nonce verification failed');
}

$public = new \CleverSay\PublicFacing();

// Pull source name for display, if source_id provided
$source_row = null;
if ($source_id > 0) {
    $source_row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, title, source_type, url FROM {$db->sources} WHERE id = %d",
        $source_id
    ), ARRAY_A);
}

?>
<div class="wrap">
    <h1>Citation Snippet Diagnostic</h1>
    <p style="color: #6b7280;">
        Internal debugging tool. Enter a source ID and a user question to see exactly
        which chunk gets picked and which sentence gets snippeted, with all scoring
        broken down at each step.
    </p>

    <form method="post" style="background: #f9fafb; padding: 16px; border: 1px solid #e5e7eb; margin-bottom: 24px;">
        <?php wp_nonce_field('cleversay_snippet_diag'); ?>
        <table class="form-table">
            <tr>
                <th><label for="source_id">Source ID</label></th>
                <td>
                    <input type="number" name="source_id" id="source_id" value="<?php echo esc_attr($source_id ?: ''); ?>" style="width: 100px;">
                    <span style="color: #6b7280; font-size: 13px;">
                        From the AI Sources page. Or check the AJAX response of a real query — `sources[N].id`.
                    </span>
                </td>
            </tr>
            <tr>
                <th><label for="question">Question</label></th>
                <td>
                    <input type="text" name="question" id="question" value="<?php echo esc_attr($question); ?>" style="width: 60%;" placeholder="what grade do i need to be in good standing">
                </td>
            </tr>
        </table>
        <p>
            <button type="submit" class="button button-primary">Diagnose</button>
        </p>
    </form>

<?php if ($source_id > 0 && $question !== '' && $source_row): ?>

    <h2>Source: #<?php echo (int) $source_row['id']; ?> — <?php echo esc_html($source_row['title']); ?></h2>
    <p style="color: #6b7280;">
        <?php echo esc_html($source_row['source_type']); ?>
        <?php if (!empty($source_row['url'])): ?>
            · <a href="<?php echo esc_url($source_row['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($source_row['url']); ?></a>
        <?php endif; ?>
    </p>

    <?php
    // Step 1: load ALL chunks for this source so we can see the full picture
    $all_chunks = $wpdb->get_results($wpdb->prepare(
        "SELECT id, chunk_index, content, word_count
         FROM {$db->chunks}
         WHERE source_id = %d
         ORDER BY chunk_index ASC",
        $source_id
    ), ARRAY_A);

    // Step 2: separately get the FULLTEXT-ranked candidates that
    // pick_best_chunk_for_question would actually consider
    $candidates = $wpdb->get_results($wpdb->prepare(
        "SELECT id, content,
                MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE) AS fulltext_score
         FROM {$db->chunks}
         WHERE source_id = %d
           AND MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE)
         ORDER BY fulltext_score DESC
         LIMIT 5",
        $question, $source_id, $question
    ), ARRAY_A);
    $candidate_ids = array_column($candidates, 'id');
    ?>

    <h3>All chunks (<?php echo count($all_chunks); ?>)</h3>
    <p style="color: #6b7280; font-size: 13px;">
        Every chunk for this source. <strong>Bold rows</strong> are FULLTEXT candidates that
        <code>pick_best_chunk_for_question</code> would consider (top 5). The "Chunk Score"
        column is the re-ranking score that determines which chunk gets selected for
        snippeting. The chunk with the highest score wins.
    </p>

    <table class="widefat">
        <thead>
            <tr>
                <th style="width:60px;">ID</th>
                <th style="width:50px;">Index</th>
                <th style="width:60px;">Words</th>
                <th style="width:90px;">FT Score</th>
                <th style="width:90px;">Chunk Score</th>
                <th>Leading text</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $best_chunk_id = 0;
            $best_chunk_score = -PHP_INT_MAX;
            $chunk_scores = [];
            foreach ($all_chunks as $ch) {
                $score = $public->score_chunk_for_question((string) $ch['content'], $question);
                $chunk_scores[(int) $ch['id']] = $score;
                if (in_array((int) $ch['id'], $candidate_ids, true) && $score > $best_chunk_score) {
                    $best_chunk_score = $score;
                    $best_chunk_id = (int) $ch['id'];
                }
            }
            foreach ($all_chunks as $ch) {
                $cid = (int) $ch['id'];
                $is_candidate = in_array($cid, $candidate_ids, true);
                $is_winner = ($cid === $best_chunk_id);
                $ft_score = '';
                foreach ($candidates as $c) {
                    if ((int) $c['id'] === $cid) {
                        $ft_score = number_format((float) $c['fulltext_score'], 3);
                        break;
                    }
                }
                $row_style = '';
                if ($is_winner)         $row_style = 'background:#dcfce7;'; // green
                elseif ($is_candidate)  $row_style = 'background:#f0f9ff;'; // light blue
                ?>
                <tr style="<?php echo $row_style; ?>">
                    <td><strong>#<?php echo $cid; ?></strong></td>
                    <td><?php echo (int) $ch['chunk_index']; ?></td>
                    <td><?php echo (int) $ch['word_count']; ?></td>
                    <td><?php echo esc_html($ft_score); ?></td>
                    <td>
                        <strong><?php echo (int) $chunk_scores[$cid]; ?></strong>
                        <?php if ($is_winner): ?>
                            <span style="color:#16a34a;">← WINS</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 12px; color: #4b5563; max-width: 600px;">
                        <?php echo esc_html(mb_substr((string) $ch['content'], 0, 220)); ?><?php if (mb_strlen((string) $ch['content']) > 220) echo '…'; ?>
                    </td>
                </tr>
                <?php
            }
            ?>
        </tbody>
    </table>

    <?php if ($best_chunk_id > 0):
        $winner = null;
        foreach ($all_chunks as $ch) {
            if ((int) $ch['id'] === $best_chunk_id) { $winner = $ch; break; }
        }
        if ($winner):
            $winner_content = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $winner['content'])));
            // Split into sentences to show the picker's view
            $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9])/', $winner_content);
            $sentences = array_values(array_filter($sentences, 'strlen'));

            // Replicate the sentence-level scoring logic to display per-sentence scores.
            // Mirrors build_relevant_snippet exactly.
            $stopwords = [
                'what','when','where','why','who','how','which',
                'is','are','was','were','be','been','being',
                'do','does','did','have','has','had',
                'a','an','the','and','or','but','if','then','than',
                'i','my','me','we','our','us','you','your',
                'to','of','in','on','for','with','at','by','from','up','out',
                'can','could','should','would','will','may','might','must',
                'this','that','these','those','it','its',
                'about','some','any','all','no','not',
            ];
            $stopwords_set = array_flip($stopwords);
            $terms = [];
            if (preg_match_all('/[a-zA-Z0-9]+/', strtolower($question), $matches)) {
                foreach ($matches[0] as $w) {
                    if (strlen($w) < 3) continue;
                    if (isset($stopwords_set[$w])) continue;
                    $terms[$w] = true;
                }
            }
            $terms = array_keys($terms);

            $answer_signals = [
                'must','required','requires','requirement',
                'need ','needs ','needed',
                'minimum','at least','no more than','up to ',
                'maximum','no fewer than','no later than',
                'eligible','ineligible','qualify','qualifies',
                'allowed','not allowed','permitted','not permitted',
                'in order to','to be eligible','to qualify',
                'deadline','cutoff','last day to','by the end of',
                'gpa of','grade of','average of',
            ];
            $negative_patterns = [
                'who do not meet','who fail to','who fails to',
                'if students do not','if you do not','if you fail',
                'who have not met','do not meet these','students who don\'t',
            ];

            $phrase = strtolower(implode(' ', $terms));
            $sent_scores = [];
            $best_sent_idx = 0;
            $best_sent_score = -1;
            foreach ($sentences as $i => $s) {
                $lower = strtolower($s);
                $sc = 0;
                foreach ($terms as $t) if (strpos($lower, $t) !== false) $sc += 2;
                if (count($terms) > 1 && strpos($lower, $phrase) !== false) $sc += 2;
                $sig = 0;
                foreach ($answer_signals as $signal) {
                    if (strpos($lower, $signal) !== false) {
                        $sig += 3;
                        if ($sig >= 6) break;
                    }
                }
                $sc += $sig;
                foreach ($negative_patterns as $neg) {
                    if (strpos($lower, $neg) !== false) {
                        $sc -= 4;
                        break;
                    }
                }
                $sent_scores[$i] = $sc;
                if ($sc > $best_sent_score) {
                    $best_sent_score = $sc;
                    $best_sent_idx = $i;
                }
            }
    ?>

    <h3>Selected chunk #<?php echo $best_chunk_id; ?> — sentence breakdown</h3>
    <p style="color: #6b7280; font-size: 13px;">
        Tokenized question terms: <code><?php echo esc_html(implode(', ', $terms)); ?></code>.
        Each sentence below shows its score under <code>build_relevant_snippet</code>.
        The highest-scoring sentence becomes the snippet.
    </p>

    <table class="widefat">
        <thead>
            <tr>
                <th style="width:50px;">#</th>
                <th style="width:80px;">Score</th>
                <th>Sentence</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sentences as $i => $s):
                $is_winning = ($i === $best_sent_idx && $best_sent_score > 0);
                $row_style = $is_winning ? 'background:#dcfce7;' : '';
            ?>
                <tr style="<?php echo $row_style; ?>">
                    <td><?php echo $i; ?></td>
                    <td>
                        <strong><?php echo $sent_scores[$i]; ?></strong>
                        <?php if ($is_winning): ?>
                            <span style="color:#16a34a;">← picked</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 13px;"><?php echo esc_html($s); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h3>Final snippet</h3>
    <?php
    $final_snippet = $public->build_relevant_snippet($winner_content, $question);
    ?>
    <div style="background: #f0f9ff; padding: 16px; border-left: 4px solid #2563eb; font-size: 14px; line-height: 1.6;">
        <?php echo esc_html($final_snippet); ?>
    </div>
    <?php if ($best_sent_score === 0): ?>
        <p style="color: #b91c1c; font-size: 13px;">
            ⚠ Note: best sentence scored 0 → fallback to leading-text (build_citation_snippet) fired.
        </p>
    <?php endif; ?>

    <?php endif; endif; ?>

<?php elseif ($source_id > 0 && !$source_row): ?>

    <p style="color: #b91c1c;">No source with ID <?php echo (int) $source_id; ?>.</p>

<?php endif; ?>

</div>
