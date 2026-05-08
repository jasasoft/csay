<?php
/**
 * Chatbot Widget Template — Mascot design
 *
 * @package CleverSay
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$options        = get_option('cleversay_options', []);
$bot_name       = !empty($options['bot_name'])         ? $options['bot_name']         : __('Assistant', 'cleversay');
$bot_label      = !empty($options['bot_agent_label'])  ? $options['bot_agent_label']  : __('AI Agent', 'cleversay');
$mascot_url     = !empty($options['mascot_image_url']) ? $options['mascot_image_url'] : '';
// Read welcome message from the options array (where settings saves it)
$welcome_msg    = !empty($options['widget_welcome_message'])
    ? $options['widget_welcome_message']
    : get_option('cleversay_welcome_message', __('Hello! How can I help you today?', 'cleversay'));
$placeholder    = !empty($options['widget_placeholder']) ? $options['widget_placeholder'] : __('Type a message...', 'cleversay');
$primary_color  = !empty($options['primary_color'])    ? $options['primary_color']    : '#2271b1';

$widget_id = 'cleversay-widget-' . wp_rand(1000, 9999);
?>

<div id="<?php echo esc_attr($widget_id); ?>"
     class="cleversay-widget position-<?php echo esc_attr($position); ?>"
     role="complementary"
     aria-label="<?php esc_attr_e('Help Chat', 'cleversay'); ?>">

    <!-- Floating toggle button -->
    <button type="button"
            class="cleversay-toggle"
            aria-expanded="false"
            aria-controls="<?php echo esc_attr($widget_id); ?>-container"
            aria-label="<?php esc_attr_e('Open help chat', 'cleversay'); ?>">
        <?php if ($mascot_url): ?>
            <img src="<?php echo esc_url($mascot_url); ?>"
                 alt="<?php echo esc_attr($bot_name); ?>"
                 class="cleversay-toggle-avatar">
        <?php else: ?>
            <svg class="chat-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
                <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/>
            </svg>
        <?php endif; ?>
        <svg class="close-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
        </svg>
    </button>

    <!-- Chat panel -->
    <div id="<?php echo esc_attr($widget_id); ?>-container"
         class="cleversay-container"
         role="dialog"
         aria-modal="false"
         aria-labelledby="<?php echo esc_attr($widget_id); ?>-title"
         aria-hidden="true">

        <!-- Header -->
        <div class="cleversay-header">
            <?php if ($mascot_url): ?>
                <img src="<?php echo esc_url($mascot_url); ?>"
                     alt=""
                     class="cleversay-header-avatar"
                     aria-hidden="true">
            <?php endif; ?>
            <h3 id="<?php echo esc_attr($widget_id); ?>-title"
                class="cleversay-header-title"><?php echo esc_html($bot_name); ?></h3>
            <button type="button"
                    class="cleversay-close"
                    aria-label="<?php esc_attr_e('Close chat', 'cleversay'); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
        </div>

        <!-- Messages -->
        <div class="cleversay-messages"
             role="log"
             aria-live="polite"
             aria-atomic="false"
             aria-label="<?php esc_attr_e('Chat messages', 'cleversay'); ?>"
             tabindex="0">

            <!-- Welcome message -->
            <div class="cleversay-message bot">
                <?php if ($mascot_url): ?>
                    <img src="<?php echo esc_url($mascot_url); ?>"
                         alt="" class="cleversay-msg-avatar" aria-hidden="true">
                <?php else: ?>
                    <div class="cleversay-msg-avatar cleversay-msg-avatar-placeholder" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>
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
            <label for="<?php echo esc_attr($widget_id); ?>-input" class="screen-reader-text">
                <?php esc_html_e('Type your question', 'cleversay'); ?>
            </label>
            <input type="text"
                   id="<?php echo esc_attr($widget_id); ?>-input"
                   class="cleversay-input"
                   placeholder="<?php echo esc_attr($placeholder); ?>"
                   autocomplete="off"
                   maxlength="500">
            <button type="button"
                    class="cleversay-submit"
                    aria-label="<?php esc_attr_e('Send', 'cleversay'); ?>">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
                    <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                </svg>
            </button>
        </div>
    </div>
</div>
