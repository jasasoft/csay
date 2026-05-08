<?php
/**
 * Client Handoff Document
 *
 * One-page delivery document showing everything a client (and their IT team)
 * needs to embed and use the bot. Auto-generated from current site config.
 *
 * Accessible from:
 *   - Per-site admin: CleverSay → Handoff Document (any admin of the site)
 *   - Network admin: Client Sites → Handoff button (super-admin)
 *
 * @package CleverSay
 */
defined('ABSPATH') || exit;

// ── Gather everything we need from existing site config ───────────────────
$opts        = get_option('cleversay_options', []);
$bot_name    = $opts['bot_name'] ?? __('Your CleverSay Bot', 'cleversay');
$tone        = $opts['persona_tone'] ?? '';
$audience    = $opts['persona_audience'] ?? '';
$topics      = trim((string) ($opts['persona_topics'] ?? ''));
$primary_color = $opts['primary_color'] ?? '#3B82F6';

// Embed snippet — same construction as Settings page
$embed_url   = esc_url(CLEVERSAY_PLUGIN_URL . 'public/js/embed.min.js');
$site_url    = esc_url(rtrim(home_url(), '/'));
$embed_token = get_option('cleversay_embed_token', '');
$snippet = '<script>' . "\n"
    . '(function(w,d,s){' . "\n"
    . '  var j=d.createElement(s);j.async=true;' . "\n"
    . "  j.src='" . $embed_url . "';" . "\n"
    . "  j.setAttribute('data-site','" . $site_url . "');" . "\n"
    . "  j.setAttribute('data-token','" . esc_attr($embed_token) . "');" . "\n"
    . '  d.head.appendChild(j);' . "\n"
    . "})(window,document,'script');" . "\n"
    . '</script>';

// Knowledge base size
global $wpdb;
$kb_count      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cleversay_knowledge");
$source_count  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cleversay_sources WHERE status = 'indexed'");

// Plan + client info from network settings (Multisite only)
$plan = ['client_name' => '', 'client_email' => '', 'plan' => 'basic', 'embed_domains' => ''];
if (is_multisite()) {
    $plan = \CleverSay\NetworkSettings::get_site_plan(get_current_blog_id());
}

// Inquiry notification email
$inquiry_email = $opts['inquiry_notification_email'] ?? get_option('admin_email');

// Multilingual?
$ai_config       = is_multisite() ? \CleverSay\NetworkSettings::get_ai_config() : ['multilingual' => false];
$is_multilingual = !empty($ai_config['multilingual']);

// Login info
$admin_url    = admin_url();
$current_user = wp_get_current_user();

// Allowed embed domains (for IT to know where the snippet is permitted)
$allowed_domains = trim($plan['embed_domains'] ?? '');

?>
<style>
.cs-handoff-wrap { max-width: 820px; }
.cs-handoff-section {
    background: #fff; border: 1px solid #dcdcde; border-radius: 6px;
    padding: 20px 24px; margin-bottom: 16px;
}
.cs-handoff-section h2 {
    margin: 0 0 14px 0; font-size: 16px; color: #1d2327;
    display: flex; align-items: center; gap: 8px;
}
.cs-handoff-kv { font-size: 13px; line-height: 1.7; }
.cs-handoff-kv strong { display: inline-block; min-width: 130px; color: #50575e; font-weight: 500; }
.cs-handoff-section pre {
    background: #f6f7f7; border: 1px solid #e0e0e0; border-radius: 4px;
    padding: 14px; font-family: monospace; font-size: 12px; line-height: 1.6;
    overflow-x: auto; margin: 0;
}
.cs-handoff-actions { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
.cs-handoff-checklist { font-size: 13px; line-height: 1.8; padding-left: 22px; }
.cs-handoff-checklist li { margin-bottom: 4px; }
.cs-handoff-meta {
    margin-top: 4px; font-size: 11px; color: #8c8f94; font-family: monospace;
}
.cs-handoff-success-banner {
    background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%);
    border: 1px solid #10B981; border-radius: 8px;
    padding: 18px 22px; margin-bottom: 20px;
}
.cs-handoff-success-banner h2 { margin: 0; color: #065F46; font-size: 18px; }
.cs-handoff-success-banner p { margin: 6px 0 0; color: #047857; font-size: 13px; }
@media print {
    .cs-handoff-actions, .wp-header-end, #adminmenuwrap, #wpadminbar,
    #wpfooter, #screen-meta, #screen-meta-links, .cs-handoff-print-hide {
        display: none !important;
    }
    .cs-handoff-wrap { max-width: none; }
    body { background: #fff; }
    #wpcontent { margin-left: 0 !important; padding-left: 20px !important; }
}
</style>

<div class="wrap cs-handoff-wrap">
    <h1 class="wp-heading-inline" style="display:flex;align-items:center;gap:8px;">
        <?php echo \CleverSay\Icons::render('package', 18); ?>
        <?php esc_html_e('Client Handoff Document', 'cleversay'); ?>
    </h1>
    <hr class="wp-header-end">

    <div class="cs-handoff-print-hide" style="margin:8px 0 16px;color:#646970;font-size:13px;">
        <?php esc_html_e('A clean delivery document for the client and their IT team. Print, save as PDF, or share the URL.', 'cleversay'); ?>
        <button type="button" onclick="window.print()" class="button" style="margin-left:8px;">
            <?php esc_html_e('Print / Save as PDF', 'cleversay'); ?>
        </button>
    </div>

    <!-- ── Header banner ────────────────────────────────────────────── -->
    <div class="cs-handoff-success-banner">
        <h2>
            🎉 <?php echo esc_html($bot_name); ?> <?php esc_html_e('is ready to deploy', 'cleversay'); ?>
        </h2>
        <p>
            <?php
            if (!empty($plan['client_name'])) {
                printf(
                    /* translators: %s = client organization name */
                    esc_html__('Configured for %s. Ready for embedding on your website.', 'cleversay'),
                    '<strong>' . esc_html($plan['client_name']) . '</strong>'
                );
            } else {
                esc_html_e('Configured and ready for embedding on your website.', 'cleversay');
            }
            ?>
        </p>
    </div>

    <!-- ── Embed snippet ─────────────────────────────────────────────── -->
    <div class="cs-handoff-section">
        <h2>
            <?php echo \CleverSay\Icons::render('code', 16); ?>
            <?php esc_html_e('1. Embed the chatbot', 'cleversay'); ?>
        </h2>
        <p style="font-size:13px;color:#3c434a;margin:0 0 12px;">
            <?php esc_html_e('Paste this snippet into your website\'s HTML, ideally just before the closing', 'cleversay'); ?>
            <code>&lt;/body&gt;</code>
            <?php esc_html_e('tag. Works on WordPress, Squarespace, custom HTML, and most other platforms.', 'cleversay'); ?>
        </p>

        <pre id="cs-handoff-snippet"><?php echo esc_html($snippet); ?></pre>

        <div class="cs-handoff-actions cs-handoff-print-hide">
            <button type="button" id="cs-handoff-copy" class="button button-primary">
                <?php echo \CleverSay\Icons::render('copy', 14); ?>
                <?php esc_html_e('Copy snippet', 'cleversay'); ?>
            </button>
            <span id="cs-handoff-copy-status" style="font-size:12px;color:#10B981;align-self:center;display:none;">
                ✓ <?php esc_html_e('Copied to clipboard', 'cleversay'); ?>
            </span>
        </div>

        <?php if ($allowed_domains): ?>
            <p class="cs-handoff-meta" style="margin-top:14px;">
                <?php esc_html_e('Allowed embed domains:', 'cleversay'); ?>
                <?php echo esc_html(str_replace("\n", ', ', $allowed_domains)); ?>
            </p>
        <?php endif; ?>
    </div>

    <!-- ── Test it ───────────────────────────────────────────────────── -->
    <div class="cs-handoff-section">
        <h2>
            <?php echo \CleverSay\Icons::render('check-circle', 16); ?>
            <?php esc_html_e('2. Test the chatbot', 'cleversay'); ?>
        </h2>
        <ol class="cs-handoff-checklist">
            <li><?php esc_html_e('Paste the snippet above into a staging or test page.', 'cleversay'); ?></li>
            <li><?php esc_html_e('Open that page in your browser.', 'cleversay'); ?></li>
            <li><?php esc_html_e('Look for the floating chat bubble in the bottom corner.', 'cleversay'); ?></li>
            <li>
                <?php
                if (!empty($topics)) {
                    $first_topic = trim(explode(',', $topics)[0] ?? '');
                    if ($first_topic) {
                        printf(
                            /* translators: %s = topic example like "admissions" */
                            esc_html__('Click the bubble and ask about %s — the bot should respond.', 'cleversay'),
                            '<strong>' . esc_html($first_topic) . '</strong>'
                        );
                    } else {
                        esc_html_e('Click the bubble and ask a question — the bot should respond.', 'cleversay');
                    }
                } else {
                    esc_html_e('Click the bubble and ask a question — the bot should respond.', 'cleversay');
                }
                ?>
            </li>
        </ol>
    </div>

    <!-- ── What's already configured ─────────────────────────────────── -->
    <div class="cs-handoff-section">
        <h2>
            <?php echo \CleverSay\Icons::render('settings', 16); ?>
            <?php esc_html_e('What\'s already configured', 'cleversay'); ?>
        </h2>
        <div class="cs-handoff-kv">
            <div><strong><?php esc_html_e('Bot name:', 'cleversay'); ?></strong> <?php echo esc_html($bot_name); ?></div>
            <?php if ($tone): ?>
                <div><strong><?php esc_html_e('Tone:', 'cleversay'); ?></strong> <?php echo esc_html(ucfirst($tone)); ?></div>
            <?php endif; ?>
            <?php if ($audience): ?>
                <div><strong><?php esc_html_e('Audience:', 'cleversay'); ?></strong> <?php echo esc_html($audience); ?></div>
            <?php endif; ?>
            <?php if ($topics): ?>
                <div><strong><?php esc_html_e('Topics covered:', 'cleversay'); ?></strong> <?php echo esc_html($topics); ?></div>
            <?php endif; ?>
            <div>
                <strong><?php esc_html_e('Primary color:', 'cleversay'); ?></strong>
                <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:<?php echo esc_attr($primary_color); ?>;vertical-align:middle;border:1px solid #dcdcde;"></span>
                <code style="font-size:12px;"><?php echo esc_html($primary_color); ?></code>
            </div>
            <div><strong><?php esc_html_e('Knowledge base:', 'cleversay'); ?></strong>
                <?php
                printf(
                    /* translators: 1: KB entry count, 2: indexed source count */
                    esc_html(_n('%1$s entry', '%1$s entries', $kb_count, 'cleversay')) . ', ' .
                    esc_html(_n('%2$s indexed source', '%2$s indexed sources', $source_count, 'cleversay')),
                    number_format_i18n($kb_count),
                    number_format_i18n($source_count)
                );
                ?>
            </div>
            <div><strong><?php esc_html_e('Languages:', 'cleversay'); ?></strong>
                <?php echo $is_multilingual
                    ? esc_html__('Multilingual (auto-detect & translate)', 'cleversay')
                    : esc_html__('English', 'cleversay'); ?>
            </div>
            <div><strong><?php esc_html_e('Inquiries route to:', 'cleversay'); ?></strong>
                <code style="font-size:12px;"><?php echo esc_html($inquiry_email); ?></code>
            </div>
            <?php if (!empty($plan['plan'])): ?>
                <div><strong><?php esc_html_e('Plan:', 'cleversay'); ?></strong>
                    <?php echo esc_html(ucfirst($plan['plan'])); ?>
                    <?php if (!empty($plan['trial_ends_at'])): ?>
                        <span style="color:#8c8f94;font-size:12px;">
                            (<?php
                            printf(
                                esc_html__('trial ends %s', 'cleversay'),
                                esc_html(date_i18n(get_option('date_format'), strtotime($plan['trial_ends_at'])))
                            );
                            ?>)
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Manage the bot ────────────────────────────────────────────── -->
    <div class="cs-handoff-section">
        <h2>
            <?php echo \CleverSay\Icons::render('user', 16); ?>
            <?php esc_html_e('Managing the bot', 'cleversay'); ?>
        </h2>
        <p style="font-size:13px;color:#3c434a;margin:0 0 12px;">
            <?php esc_html_e('Log in to update bot personality, add knowledge entries, view incoming questions, and respond to inquiries.', 'cleversay'); ?>
        </p>
        <div class="cs-handoff-kv">
            <div><strong><?php esc_html_e('Admin URL:', 'cleversay'); ?></strong>
                <a href="<?php echo esc_url($admin_url); ?>" target="_blank">
                    <?php echo esc_html($admin_url); ?>
                </a>
            </div>
            <?php if (!empty($plan['client_email'])): ?>
                <div><strong><?php esc_html_e('Client login email:', 'cleversay'); ?></strong>
                    <code style="font-size:12px;"><?php echo esc_html($plan['client_email']); ?></code>
                </div>
            <?php endif; ?>
        </div>

        <h3 style="font-size:13px;margin:18px 0 8px;color:#1d2327;"><?php esc_html_e('What you can do from the dashboard:', 'cleversay'); ?></h3>
        <ul class="cs-handoff-checklist" style="list-style:disc;">
            <li>
                <strong><?php esc_html_e('Settings:', 'cleversay'); ?></strong>
                <?php esc_html_e('Change the bot\'s name, mascot, color, tone, and personality.', 'cleversay'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('Knowledge Base:', 'cleversay'); ?></strong>
                <?php esc_html_e('Add or edit pre-written question-and-answer entries the bot uses to respond.', 'cleversay'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('AI Sources:', 'cleversay'); ?></strong>
                <?php esc_html_e('Add web pages, PDFs, or text content the bot reads from. The AI will use this to answer questions not directly in the KB.', 'cleversay'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('Questions Log:', 'cleversay'); ?></strong>
                <?php esc_html_e('See every question visitors have asked. Use this to spot common questions and add them to your KB.', 'cleversay'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('AI Answers:', 'cleversay'); ?></strong>
                <?php esc_html_e('Review answers the AI generated. Promote good ones to permanent KB entries with one click.', 'cleversay'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('Inquiries:', 'cleversay'); ?></strong>
                <?php esc_html_e('Visitor messages submitted when the bot couldn\'t help. Respond directly or forward to your team.', 'cleversay'); ?>
            </li>
        </ul>
    </div>

    <!-- ── Print-friendly footer ─────────────────────────────────────── -->
    <div class="cs-handoff-section">
        <h2>
            <?php echo \CleverSay\Icons::render('help-circle', 16); ?>
            <?php esc_html_e('Need help?', 'cleversay'); ?>
        </h2>
        <p style="font-size:13px;color:#3c434a;margin:0;">
            <?php
            $support_email = get_option('cleversay_support_email', get_network_option(null, 'admin_email'));
            printf(
                /* translators: %s = support email link */
                esc_html__('Contact %s for assistance with embedding, customization, or content updates.', 'cleversay'),
                '<a href="mailto:' . esc_attr($support_email) . '">' . esc_html($support_email) . '</a>'
            );
            ?>
        </p>
    </div>

    <p class="cs-handoff-meta cs-handoff-print-hide" style="text-align:right;">
        <?php
        printf(
            esc_html__('Document generated %s', 'cleversay'),
            esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format')))
        );
        ?>
    </p>
</div>

<script>
document.getElementById('cs-handoff-copy')?.addEventListener('click', function () {
    var snippet = document.getElementById('cs-handoff-snippet').textContent;
    var status  = document.getElementById('cs-handoff-copy-status');
    var btn     = this;

    function showCopied() {
        if (status) {
            status.style.display = 'inline-block';
            setTimeout(function () { status.style.display = 'none'; }, 2400);
        }
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(snippet).then(showCopied, function (err) {
            // Fallback for older browsers / permission issues
            fallbackCopy();
        });
    } else {
        fallbackCopy();
    }

    function fallbackCopy() {
        var ta = document.createElement('textarea');
        ta.value = snippet;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            showCopied();
        } catch (e) {
            alert('Could not copy automatically. Please select and copy the snippet manually.');
        }
        document.body.removeChild(ta);
    }
});
</script>
