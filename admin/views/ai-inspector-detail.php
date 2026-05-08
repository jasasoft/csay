<?php
/**
 * AI Inspector — detail view for a single debug entry.
 *
 * @var array|null $entry  Full debug log row with chunks/history decoded
 */
defined('ABSPATH') || exit;

$back_url = remove_query_arg('entry');

if (!$entry):
?>
    <div class="wrap">
        <h1><?php esc_html_e('Entry not found', 'cleversay'); ?></h1>
        <p>
            <a href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to AI Inspector', 'cleversay'); ?></a>
        </p>
    </div>
<?php
    return;
endif;

$trigger = $entry['trigger_reason'] ?? 'manual';
$trigger_label = [
    'manual'           => __('Manual capture', 'cleversay'),
    'negative_rating'  => __('Negative rating (👎)', 'cleversay'),
    'forced'           => __('Forced', 'cleversay'),
][$trigger] ?? $trigger;
?>
<div class="wrap">
    <p>
        <a href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to AI Inspector', 'cleversay'); ?></a>
    </p>

    <h1>
        <?php esc_html_e('AI Inspector — Entry', 'cleversay'); ?>
        <span style="color:#999; font-size:14px; font-weight:normal;">#<?php echo (int) $entry['id']; ?></span>
    </h1>

    <p class="description">
        <?php
        printf(
            /* translators: 1 = trigger label, 2 = relative time */
            esc_html__('Captured by: %1$s — %2$s ago', 'cleversay'),
            esc_html($trigger_label),
            esc_html(human_time_diff(strtotime($entry['created_at'] . ' UTC'), time()))
        );
        ?>
        <?php if (!empty($entry['latency_ms'])): ?>
            · <?php echo esc_html(number_format((int) $entry['latency_ms']) . ' ms'); ?>
        <?php endif; ?>
    </p>

    <style>
        .csi-section {
            background:#fff; padding:14px 18px; border:1px solid #ccd0d4;
            border-radius:6px; margin:14px 0;
        }
        .csi-section h2 {
            margin-top:0; padding-bottom:6px; border-bottom:1px solid #eee;
            font-size:15px; color:#333;
        }
        .csi-pre {
            background:#f6f7f7; border:1px solid #e0e0e0; border-radius:4px;
            padding:12px; max-height:480px; overflow:auto;
            font-family:Consolas, Monaco, monospace; font-size:12px;
            white-space:pre-wrap; word-wrap:break-word; margin:0;
        }
        .csi-chunk {
            background:#f6f7f7; border:1px solid #e0e0e0; border-radius:4px;
            padding:10px 12px; margin:8px 0;
        }
        .csi-chunk-source {
            font-size:11px; color:#666; text-transform:uppercase; margin-bottom:4px;
            letter-spacing:0.5px; font-weight:600;
        }
        .csi-chunk-content {
            font-size:13px; color:#222; white-space:pre-wrap;
            max-height:160px; overflow-y:auto;
        }
        .csi-history-msg {
            margin:6px 0; padding:8px 12px; border-radius:4px;
        }
        .csi-history-msg.user {
            background:#e7f3ff; border-left:3px solid #2271b1;
        }
        .csi-history-msg.assistant {
            background:#f5f5f5; border-left:3px solid #999;
        }
        .csi-history-role {
            font-size:11px; font-weight:600; text-transform:uppercase;
            letter-spacing:0.5px; color:#666; margin-bottom:2px;
        }
    </style>

    <!-- Question -->
    <div class="csi-section">
        <h2><?php esc_html_e('Question', 'cleversay'); ?></h2>
        <p style="font-size:15px; margin:6px 0;"><?php echo esc_html($entry['question']); ?></p>
    </div>

    <!-- Final answer -->
    <div class="csi-section">
        <h2><?php esc_html_e('Answer shown to visitor', 'cleversay'); ?></h2>
        <div class="csi-pre"><?php echo esc_html($entry['final_answer'] ?? ''); ?></div>
    </div>

    <!-- Conversation history -->
    <?php if (!empty($entry['history'])): ?>
        <div class="csi-section">
            <h2>
                <?php
                printf(
                    /* translators: %d = number of messages */
                    esc_html__('Conversation history (%d messages)', 'cleversay'),
                    count($entry['history'])
                );
                ?>
            </h2>
            <?php foreach ($entry['history'] as $msg):
                $role = ($msg['role'] ?? 'user') === 'user' ? 'user' : 'assistant';
            ?>
                <div class="csi-history-msg <?php echo esc_attr($role); ?>">
                    <div class="csi-history-role">
                        <?php echo $role === 'user' ? esc_html__('User', 'cleversay') : esc_html__('Assistant', 'cleversay'); ?>
                    </div>
                    <div><?php echo esc_html($msg['content'] ?? ''); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Retrieved chunks -->
    <?php if (!empty($entry['chunks'])): ?>
        <div class="csi-section">
            <h2>
                <?php
                printf(
                    /* translators: %d = number of chunks */
                    esc_html__('Retrieved chunks (%d)', 'cleversay'),
                    count($entry['chunks'])
                );
                ?>
            </h2>
            <p class="description">
                <?php esc_html_e('These are the source content snippets the AI was given as context. If the answer contains facts not present here, that suggests either hallucination or a missing chunk.', 'cleversay'); ?>
            </p>
            <?php foreach ($entry['chunks'] as $i => $chunk): ?>
                <div class="csi-chunk">
                    <div class="csi-chunk-source">
                        #<?php echo (int) ($i + 1); ?> ·
                        <?php echo esc_html($chunk['source_title'] ?? __('Untitled', 'cleversay')); ?>
                    </div>
                    <div class="csi-chunk-content"><?php echo esc_html($chunk['content'] ?? ''); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- System prompt -->
    <div class="csi-section">
        <h2><?php esc_html_e('System prompt sent to AI', 'cleversay'); ?></h2>
        <p class="description">
            <?php esc_html_e('Full prompt including persona, rules, conversation block, and context. This is exactly what the model received.', 'cleversay'); ?>
        </p>
        <div class="csi-pre"><?php echo esc_html($entry['system_prompt'] ?? ''); ?></div>
    </div>

</div>
