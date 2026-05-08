<?php
/**
 * Chatbot Embedded Template (for shortcode)
 *
 * @package CleverSay
 * @since 2.0.1
 */

if (!defined('ABSPATH')) {
    exit;
}

$title       = $atts['title'] ?? __('Chat with Us', 'cleversay');
$extra_class = $atts['class'] ?? '';
$style       = $atts['style'] ?? 'embedded';

// Get options
$options         = get_option('cleversay_options', []);
$mascot_url      = !empty($options['mascot_image_url'])  ? $options['mascot_image_url']  : '';
$bot_label       = !empty($options['bot_agent_label'])   ? $options['bot_agent_label']   : __('AI Agent', 'cleversay');
$bot_name        = !empty($options['bot_name'])          ? $options['bot_name']          : __('Assistant', 'cleversay');
$welcome_msg     = !empty($options['widget_welcome_message'])
    ? $options['widget_welcome_message']
    : get_option('cleversay_welcome_message', __('Hello! How can I help you today?', 'cleversay'));
$placeholder     = !empty($options['widget_placeholder']) ? $options['widget_placeholder'] : __('Type your question here...', 'cleversay');

// Top Questions
$show_top_questions  = $options['show_top_questions'] ?? false;
$top_questions_title = $options['top_questions_title'] ?? __('Popular Questions', 'cleversay');
$top_questions_count = (int) ($options['top_questions_count'] ?? 10);

$top_questions = [];
if ($show_top_questions) {
    $analytics     = new \CleverSay\Analytics();
    $top_questions = $analytics->get_top_questions($top_questions_count);
}

$has_top_questions = $show_top_questions && !empty($top_questions);
$layout_class      = $has_top_questions ? 'cleversay-two-column' : 'cleversay-single-column';
?>

<div class="cleversay-embedded-wrapper <?php echo esc_attr($layout_class); ?> <?php echo esc_attr($extra_class); ?>">

    <!-- Main Chatbot -->
    <div class="cleversay-embedded" data-style="<?php echo esc_attr($style); ?>">
        <div class="cleversay-embedded-container">

            <!-- Header -->
            <div class="cleversay-header">
                <?php if ($mascot_url): ?>
                    <img src="<?php echo esc_url($mascot_url); ?>"
                         alt="" class="cleversay-header-avatar" aria-hidden="true">
                <?php endif; ?>
                <h3 class="cleversay-header-title"><?php echo esc_html($bot_name ?: $title); ?></h3>
            </div>

            <!-- Messages -->
            <div class="cleversay-messages"
                 role="log"
                 aria-live="polite"
                 aria-atomic="false"
                 aria-label="<?php esc_attr_e('Chat messages', 'cleversay'); ?>">

                <!-- Welcome message with avatar -->
                <div class="cleversay-message bot">
                    <?php if ($mascot_url): ?>
                        <img src="<?php echo esc_url($mascot_url); ?>"
                             alt="" class="cleversay-msg-avatar" aria-hidden="true">
                    <?php else: ?>
                        <div class="cleversay-msg-avatar cleversay-msg-avatar-placeholder" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/>
                            </svg>
                        </div>
                    <?php endif; ?>
                    <div class="cleversay-msg-body">
                        <span class="cleversay-msg-label"><?php echo esc_html($bot_label); ?></span>
                        <div class="cleversay-bubble">
                            <?php echo wp_kses_post($welcome_msg); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Input -->
            <div class="cleversay-input-area">
                <div class="cleversay-input-wrapper">
                    <input type="text"
                           class="cleversay-input"
                           placeholder="<?php echo esc_attr($placeholder); ?>"
                           autocomplete="off"
                           maxlength="500"
                           aria-label="<?php esc_attr_e('Your question', 'cleversay'); ?>">
                    <button type="button" class="cleversay-submit"
                            aria-label="<?php esc_attr_e('Send', 'cleversay'); ?>">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
                            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                        </svg>
                    </button>
                </div>
            </div>

        </div>
    </div>

    <?php if ($has_top_questions): ?>
    <!-- Top Questions Panel -->
    <div class="cleversay-top-questions">
        <div class="cleversay-top-questions-container">
            <div class="cleversay-top-questions-header">
                <h3>
                    <span class="dashicons dashicons-star-filled"></span>
                    <?php echo esc_html($top_questions_title); ?>
                </h3>
            </div>
            <ul class="cleversay-top-questions-list">
                <?php foreach ($top_questions as $index => $q): ?>
                <li>
                    <a href="#" class="cleversay-top-question"
                       data-question="<?php echo esc_attr($q['question']); ?>">
                        <span class="cleversay-question-number"><?php echo esc_html($index + 1); ?></span>
                        <span class="cleversay-question-text"><?php echo esc_html($q['question']); ?></span>

                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

</div>
