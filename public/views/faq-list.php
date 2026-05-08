<?php
/**
 * [cleversay_faq] Shortcode — FAQ accordion list
 *
 * @package CleverSay
 * @since   3.3.1
 */

if (!defined('ABSPATH')) {
    exit;
}

// $faqs and $atts available from shortcode_faq_list()
$extra_class = sanitize_html_class($atts['class'] ?? '');
$faq_id      = 'cs-faq-' . wp_rand(1000, 9999);
?>
<div id="<?php echo esc_attr($faq_id); ?>"
     class="cleversay-faq-list <?php echo $extra_class; ?>"
     style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;max-width:720px;margin:0 auto;">

    <?php if (empty($faqs)): ?>
        <p style="color:#86868B;font-size:14px;"><?php esc_html_e('No FAQ entries found.', 'cleversay'); ?></p>
    <?php else: ?>
        <?php foreach ($faqs as $i => $faq): ?>
        <div class="cs-faq-item"
             style="border:1px solid rgba(0,0,0,0.08);border-radius:10px;margin-bottom:8px;overflow:hidden;">
            <button class="cs-faq-q"
                    style="width:100%;text-align:left;padding:14px 18px;background:#fff;border:none;
                           cursor:pointer;font-size:15px;font-weight:600;color:#1D1D1F;
                           display:flex;justify-content:space-between;align-items:center;gap:12px;
                           font-family:inherit;"
                    aria-expanded="false"
                    aria-controls="cs-faq-ans-<?php echo esc_attr($faq_id . '-' . $i); ?>">
                <span><?php echo esc_html($faq['question']); ?></span>
                <svg class="cs-faq-icon" width="16" height="16" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2.5"
                     style="flex-shrink:0;transition:transform 0.2s;color:#0A84FF;">
                    <path d="M6 9l6 6 6-6"/>
                </svg>
            </button>
            <div class="cs-faq-a"
                 id="cs-faq-ans-<?php echo esc_attr($faq_id . '-' . $i); ?>"
                 style="display:none;padding:0 18px 16px;font-size:14px;color:#515154;line-height:1.6;background:#fff;">
                <?php echo wp_kses_post($faq['response']); ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
(function() {
    var list = document.getElementById(<?php echo wp_json_encode($faq_id); ?>);
    if (!list) return;
    list.querySelectorAll('.cs-faq-q').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var ans     = document.getElementById(btn.getAttribute('aria-controls'));
            var icon    = btn.querySelector('.cs-faq-icon');
            var open    = btn.getAttribute('aria-expanded') === 'true';

            // Close all others
            list.querySelectorAll('.cs-faq-q').forEach(function(b) {
                b.setAttribute('aria-expanded', 'false');
                b.querySelector('.cs-faq-icon').style.transform = '';
                var a = document.getElementById(b.getAttribute('aria-controls'));
                if (a) a.style.display = 'none';
            });

            if (!open) {
                btn.setAttribute('aria-expanded', 'true');
                icon.style.transform = 'rotate(180deg)';
                ans.style.display = 'block';
            }
        });
    });
})();
</script>
