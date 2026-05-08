<?php
/**
 * [cleversay] Shortcode — Inline chat panel
 *
 * Renders a self-contained chat widget embedded in page content.
 * Uses the Shadow DOM embed script so styles are fully isolated.
 *
 * @package CleverSay
 * @since   3.3.1
 */

if (!defined('ABSPATH')) {
    exit;
}

// $atts are available from shortcode_search_form()
$title       = esc_html($atts['title']       ?? __('Ask a Question', 'cleversay'));
$placeholder = esc_attr($atts['placeholder'] ?? __('Type your question here…', 'cleversay'));
$extra_class = sanitize_html_class($atts['class'] ?? '');

$widget_id = 'cs-inline-' . wp_rand(1000, 9999);
?>
<div id="<?php echo esc_attr($widget_id); ?>"
     class="cleversay-inline-widget <?php echo $extra_class; ?>"
     style="width:100%;max-width:680px;margin:0 auto;">

    <div class="cs-inline-header"
         style="background:var(--cs-primary,#0A84FF);color:#fff;padding:14px 18px;
                border-radius:12px 12px 0 0;display:flex;align-items:center;gap:10px;
                font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="#fff" aria-hidden="true">
            <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
        </svg>
        <span style="font-weight:600;font-size:15px;"><?php echo $title; ?></span>
    </div>

    <div class="cs-inline-messages"
         style="height:340px;overflow-y:auto;padding:16px;
                background:#fff;border-left:1px solid rgba(0,0,0,0.08);
                border-right:1px solid rgba(0,0,0,0.08);
                font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
        <?php
        $options     = get_option('cleversay_options', []);
        $welcome_msg = !empty($options['widget_welcome_message'])
            ? $options['widget_welcome_message']
            : get_option('cleversay_welcome_message', __('Hello! How can I help you today?', 'cleversay'));
        ?>
        <div class="cs-msg bot" style="display:flex;gap:8px;margin-bottom:12px;">
            <div style="background:rgba(10,132,255,0.1);color:#1D1D1F;padding:10px 14px;
                        border-radius:4px 14px 14px 14px;font-size:14px;line-height:1.5;max-width:85%;">
                <?php echo wp_kses_post($welcome_msg); ?>
            </div>
        </div>
    </div>

    <div class="cs-inline-input-area"
         style="display:flex;gap:0;border:1px solid rgba(0,0,0,0.08);border-top:none;
                border-radius:0 0 12px 12px;overflow:hidden;background:#fff;">
        <input type="text"
               class="cs-inline-input"
               placeholder="<?php echo $placeholder; ?>"
               style="flex:1;padding:12px 16px;border:none;outline:none;font-size:14px;
                      font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;"
               aria-label="<?php esc_attr_e('Your question', 'cleversay'); ?>">
        <button class="cs-inline-submit"
                style="background:var(--cs-primary,#0A84FF);color:#fff;border:none;
                       padding:12px 20px;cursor:pointer;font-size:14px;font-weight:600;
                       font-family:inherit;transition:background 0.15s;"
                aria-label="<?php esc_attr_e('Send', 'cleversay'); ?>">
            <?php esc_html_e('Send', 'cleversay'); ?>
        </button>
    </div>
</div>

<script>
(function() {
    var widget   = document.getElementById(<?php echo wp_json_encode($widget_id); ?>);
    if (!widget) return;

    var messages = widget.querySelector('.cs-inline-messages');
    var input    = widget.querySelector('.cs-inline-input');
    var submit   = widget.querySelector('.cs-inline-submit');
    var ajaxUrl  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var nonce    = <?php echo wp_json_encode(wp_create_nonce('cleversay_nonce')); ?>;
    var history  = [];

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function appendMsg(role, html) {
        var isBot = role === 'bot';
        var bubble = document.createElement('div');
        bubble.className = 'cs-msg ' + role;
        bubble.style.cssText = 'display:flex;gap:8px;margin-bottom:12px;' + (isBot ? '' : 'justify-content:flex-end;');
        bubble.innerHTML = '<div style="background:' +
            (isBot ? 'rgba(10,132,255,0.1);color:#1D1D1F;border-radius:4px 14px 14px 14px;'
                   : '#0A84FF;color:#fff;border-radius:14px 4px 14px 14px;') +
            'padding:10px 14px;font-size:14px;line-height:1.5;max-width:85%;">' + html + '</div>';
        messages.appendChild(bubble);
        messages.scrollTop = messages.scrollHeight;
        return bubble;
    }

    function showTyping() {
        return appendMsg('bot',
            '<span style="display:inline-flex;gap:4px;align-items:center;">' +
            '<span style="width:6px;height:6px;border-radius:50%;background:#999;animation:cs-blink 1.2s infinite 0s"></span>' +
            '<span style="width:6px;height:6px;border-radius:50%;background:#999;animation:cs-blink 1.2s infinite 0.2s"></span>' +
            '<span style="width:6px;height:6px;border-radius:50%;background:#999;animation:cs-blink 1.2s infinite 0.4s"></span>' +
            '</span>'
        );
    }

    function sendQuery() {
        var q = input.value.trim();
        if (!q) return;
        input.value = '';
        appendMsg('user', esc(q));
        var typing = showTyping();

        var body = 'action=cleversay_search&nonce=' + encodeURIComponent(nonce) +
                   '&question=' + encodeURIComponent(q) +
                   '&history=' + encodeURIComponent(JSON.stringify(history.slice(-6))) +
                   '&context=inline';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            typing.remove();
            try {
                var r = JSON.parse(xhr.responseText);
                if (r.success && r.data && r.data.found && r.data.answers && r.data.answers[0]) {
                    var ans = r.data.answers[0].answer || '';
                    var isHtml = /<[a-zA-Z][\s\S]*>/.test(ans);
                    appendMsg('bot', isHtml ? ans : esc(ans).replace(/\n/g, '<br>'));
                    history.push({type:'user', content:q});
                    history.push({type:'bot',  content:ans});
                } else {
                    var noAns = (r.data && r.data.no_answer_message) || <?php echo wp_json_encode(__("I couldn't find an answer to that. Please try rephrasing your question.", 'cleversay')); ?>;
                    appendMsg('bot', esc(noAns));
                }
            } catch(e) {
                appendMsg('bot', <?php echo wp_json_encode(__('Sorry, something went wrong. Please try again.', 'cleversay')); ?>);
            }
        };
        xhr.onerror = function() {
            typing.remove();
            appendMsg('bot', <?php echo wp_json_encode(__('Sorry, something went wrong. Please try again.', 'cleversay')); ?>);
        };
        xhr.send(body);
    }

    submit.addEventListener('click', sendQuery);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendQuery(); }
    });

    // Inject typing animation keyframes once
    if (!document.getElementById('cs-inline-style')) {
        var s = document.createElement('style');
        s.id = 'cs-inline-style';
        s.textContent = '@keyframes cs-blink{0%,80%,100%{opacity:.2}40%{opacity:1}}';
        document.head.appendChild(s);
    }
})();
</script>
