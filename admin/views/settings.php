<?php
/**
 * Settings Admin View
 *
 * @package CleverSay
 */

if (!defined('ABSPATH')) {
    exit;
}

// Show notices from admin_init redirects
if (!empty($_GET['saved'])) {
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved successfully.', 'cleversay') . '</p></div>';
}
if (!empty($_GET['token_regenerated'])) {
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Embed token regenerated. Update the snippet on all external sites.', 'cleversay') . '</p></div>';
}

// Get current options
$options = get_option('cleversay_options', []);
$stopwords = get_option('cleversay_stopwords', []);

// Defaults
$defaults = [
    'widget_enabled' => true,
    'widget_position' => 'bottom-right',
    'widget_title' => __('Need Help?', 'cleversay'),
    'widget_placeholder' => __('Type your question...', 'cleversay'),
    'widget_welcome_message' => __('Hi! How can I help you today?', 'cleversay'),
    'bot_name'           => __('Assistant', 'cleversay'),
    'bot_agent_label'    => __('AI Agent', 'cleversay'),
    'mascot_image_url'   => '',
    'show_ai_badge'      => true,
    'ai_polish_kb'           => false,
    'ai_validate_kb'         => true,    // core: validate every KB match against the question (defaults on)
    'ai_validate_aadefault'  => false,
    'show_top_questions' => false,
    'top_questions_title' => __('Popular Questions', 'cleversay'),
    'top_questions_count' => 10,
    'primary_color' => '#2271b1',
    'secondary_color' => '#135e96',
    'text_color' => '#1d2327',
    'background_color'      => '#ffffff',
    'header_bg_color'       => '#2271b1',
    'header_text_color'     => '#ffffff',
    'user_bubble_color'     => '#2271b1',
    'user_bubble_text'      => '#ffffff',
    'bot_bubble_color'      => '#ffffff',
    'bot_bubble_text'       => '#1d2327',
    'chat_bg_color'         => '#f5f5f7',
    'toggle_bg_color'       => '#2271b1',
    'widget_font'               => 'system',
    'widget_font_custom_url'    => '',
    'widget_font_custom_family' => '',
    'widget_font_size'          => 15,
    'teaser_enabled'            => true,
    'teaser_message'            => '',
    'teaser_delay'              => 3,
    'persona_school_name'       => '',
    'persona_short_name'        => '',
    'persona_mascot_name'       => '',
    'persona_tone'              => 'friendly',
    'persona_audience'          => 'students',
    'persona_topics'            => '',
    'persona_extra'             => '',
    'enable_spellcheck' => true,
    'min_match_score' => 70,
    'max_results' => 5,
    'show_suggestions' => true,
    'spellcheck_threshold' => 75,
    'show_rating' => true,
    'rating_feedback' => true,
    'enable_inquiry_form' => true,
    'inquiry_notification_email' => get_option('admin_email'),
    'require_email_for_inquiry' => false,
    'no_answer_message' => __("I couldn't find an answer to your question. Would you like to submit it for review?", 'cleversay'),
    'inquiry_success_message' => __('Thank you! Your question has been submitted and we will respond soon.', 'cleversay'),
    'inquiry_intro_message'   => __('Sure — fill out the form below and we\'ll get back to you.', 'cleversay'),
    'enable_analytics' => true,
    'track_visitors' => true,
    'anonymize_ip' => false,
    'exclude_bot_traffic' => true,
    'delete_data_on_uninstall' => false,
    'cache_duration' => 300,
    'rate_limit_searches' => 0,
];

$options = wp_parse_args($options, $defaults);
?>

<div class="wrap cleversay-admin cleversay-settings">
    <h1 class="wp-heading-inline"><?php echo \CleverSay\Icons::render('settings', 26); ?> <?php esc_html_e('CleverSay Settings', 'cleversay'); ?></h1>
    <hr class="wp-header-end">
    <form method="post" action="">
        <?php
        // Generate a per-user CSRF token stored in user meta
        $csrf_token = bin2hex(random_bytes(16));
        update_user_meta(get_current_user_id(), 'cleversay_settings_token', $csrf_token);
        ?>
        <input type="hidden" name="cleversay_csrf" value="<?php echo esc_attr($csrf_token); ?>">
        
        <div class="settings-tabs">
            <?php
            $is_client    = is_multisite() && !is_super_admin();
            $is_multisite = is_multisite();
            ?>
            <nav class="nav-tab-wrapper">
                <a href="#widget" class="nav-tab nav-tab-active" data-tab="widget">
                    <?php esc_html_e('Widget', 'cleversay'); ?>
                </a>
                <a href="#appearance" class="nav-tab" data-tab="appearance">
                    <?php esc_html_e('Appearance', 'cleversay'); ?>
                </a>
                <a href="#personality" class="nav-tab" data-tab="personality">
                    <?php esc_html_e('Bot Personality', 'cleversay'); ?>
                </a>
                <a href="#search" class="nav-tab" data-tab="search">
                    <?php esc_html_e('Search', 'cleversay'); ?>
                </a>
                <a href="#inquiries" class="nav-tab" data-tab="inquiries">
                    <?php esc_html_e('Inquiries', 'cleversay'); ?>
                </a>
                <a href="#lead-capture" class="nav-tab" data-tab="lead-capture">
                    <?php esc_html_e('Lead Capture', 'cleversay'); ?>
                </a>
                <a href="#analytics" class="nav-tab" data-tab="analytics">
                    <?php esc_html_e('Analytics', 'cleversay'); ?>
                </a>
                <?php if (!$is_multisite): // Single site only — Multisite uses Network Admin AI Settings ?>
                <a href="#ai-settings" class="nav-tab" data-tab="ai-settings">
                    <?php echo \CleverSay\Icons::render('sparkles', 18); ?> <?php esc_html_e('AI Settings', 'cleversay'); ?>
                </a>
                <?php endif; ?>
                <?php if (!$is_client): // Super admins see Advanced tab; clients do not ?>
                <a href="#advanced" class="nav-tab" data-tab="advanced">
                    <?php esc_html_e('Advanced', 'cleversay'); ?>
                </a>
                <?php endif; ?>
            </nav>
            
            <!-- Widget Tab -->
            <div class="tab-content active" id="tab-widget">
                <h3><?php echo \CleverSay\Icons::render('message-circle', 18); ?><?php esc_html_e('Floating Widget', 'cleversay'); ?></h3>
                <p class="description" style="margin-bottom: 15px;">
                    <?php esc_html_e('Configure the floating chat widget that appears on your website.', 'cleversay'); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Widget', 'cleversay'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="widget_enabled" value="1" 
                                       <?php checked($options['widget_enabled']); ?>>
                                <?php esc_html_e('Show floating chat widget on frontend', 'cleversay'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Widget Position', 'cleversay'); ?></th>
                        <td>
                            <select name="widget_position">
                                <option value="bottom-right" <?php selected($options['widget_position'], 'bottom-right'); ?>>
                                    <?php esc_html_e('Bottom Right', 'cleversay'); ?>
                                </option>
                                <option value="bottom-left" <?php selected($options['widget_position'], 'bottom-left'); ?>>
                                    <?php esc_html_e('Bottom Left', 'cleversay'); ?>
                                </option>
                                <option value="top-right" <?php selected($options['widget_position'], 'top-right'); ?>>
                                    <?php esc_html_e('Top Right', 'cleversay'); ?>
                                </option>
                                <option value="top-left" <?php selected($options['widget_position'], 'top-left'); ?>>
                                    <?php esc_html_e('Top Left', 'cleversay'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="widget_title"><?php esc_html_e('Widget Title', 'cleversay'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="widget_title" id="widget_title" class="regular-text"
                                   value="<?php echo esc_attr($options['widget_title']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="widget_placeholder"><?php esc_html_e('Input Placeholder', 'cleversay'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="widget_placeholder" id="widget_placeholder" class="regular-text"
                                   value="<?php echo esc_attr($options['widget_placeholder']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="widget_welcome_message"><?php esc_html_e('Welcome Message', 'cleversay'); ?></label>
                        </th>
                        <td>
                            <textarea name="widget_welcome_message" id="widget_welcome_message" class="large-text" rows="2"><?php 
                                echo esc_textarea($options['widget_welcome_message']); 
                            ?></textarea>
                            <p class="description"><?php esc_html_e('Displayed when the widget is first opened.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Teaser Bubble', 'cleversay'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="teaser_enabled" value="1" <?php checked(!isset($options['teaser_enabled']) || !empty($options['teaser_enabled'])); ?>>
                                <?php esc_html_e('Show a teaser bubble next to the toggle button after a delay', 'cleversay'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="teaser_message"><?php esc_html_e('Teaser Message', 'cleversay'); ?></label></th>
                        <td>
                            <textarea name="teaser_message" id="teaser_message" class="large-text" rows="2"
                                      placeholder="<?php esc_attr_e('Leave blank to use the Welcome Message', 'cleversay'); ?>"><?php echo esc_textarea($options['teaser_message'] ?? ''); ?></textarea>
                            <p class="description"><?php esc_html_e('Leave blank to reuse the Welcome Message text.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="teaser_delay"><?php esc_html_e('Teaser Delay (seconds)', 'cleversay'); ?></label></th>
                        <td>
                            <input type="number" name="teaser_delay" id="teaser_delay"
                                   value="<?php echo esc_attr($options['teaser_delay'] ?? 3); ?>"
                                   min="1" max="30" step="1" style="width:70px;">
                            <p class="description"><?php esc_html_e('How many seconds after page load before the teaser appears (1–30).', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Source Citations', 'cleversay'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="cleversay_citations_enabled" value="1" <?php checked((bool) get_option('cleversay_citations_enabled', false)); ?>>
                                <?php esc_html_e('Show sources for AI-generated answers', 'cleversay'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('When enabled, AI-generated responses include a "Sources" link below the answer. Visitors can tap it to see and verify the source documents the answer drew from. Off by default; only affects AI-fallback (RAG) answers in the floating widget — not direct KB matches and not the [cleversay] shortcode.', 'cleversay'); ?>
                            </p>
                        </td>
                    </tr>
                </table>


                
                <h3><?php echo \CleverSay\Icons::render('list', 18); ?><?php esc_html_e('Embedded Chatbot - Top Questions', 'cleversay'); ?></h3>
                <p class="description" style="margin-bottom: 15px;">
                    <?php esc_html_e('Display a list of popular questions alongside the embedded chatbot. Only works with the [cleversay] shortcode.', 'cleversay'); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Show Top Questions', 'cleversay'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_top_questions" value="1" 
                                       <?php checked($options['show_top_questions']); ?>>
                                <?php esc_html_e('Display popular questions panel next to embedded chatbot', 'cleversay'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="top_questions_title"><?php esc_html_e('Panel Title', 'cleversay'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="top_questions_title" id="top_questions_title" class="regular-text"
                                   value="<?php echo esc_attr($options['top_questions_title']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="top_questions_count"><?php esc_html_e('Number of Questions', 'cleversay'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="top_questions_count" id="top_questions_count" 
                                   min="1" max="20" class="small-text"
                                   value="<?php echo esc_attr($options['top_questions_count']); ?>">
                            <p class="description"><?php esc_html_e('How many popular questions to display (1-20).', 'cleversay'); ?></p>
                        </td>
                    </tr>
                </table>
                
                
                <h3><?php echo \CleverSay\Icons::render('shield', 18); ?><?php esc_html_e('AI Badge', 'cleversay'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Show AI Badge', 'cleversay'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_ai_badge" value="1"
                                       <?php checked(!empty($options['show_ai_badge'] ?? true)); ?>>
                                <?php esc_html_e('Show "AI-assisted answer" label below AI-generated responses', 'cleversay'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Uncheck to hide the AI badge from visitors.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                </table>

                <h3><?php echo \CleverSay\Icons::render('code', 18); ?><?php esc_html_e('Shortcode', 'cleversay'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Embedded Chatbot', 'cleversay'); ?></th>
                        <td>
                            <code>[cleversay]</code>
                            <p class="description"><?php esc_html_e('Embeds the full chatbot interface inline on any page or post.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                </table>

                <h3><?php echo \CleverSay\Icons::render('code', 18); ?><?php esc_html_e('Website Embed Snippet', 'cleversay'); ?></h3>
                <p class="description" style="margin-bottom:12px;">
                    <?php esc_html_e('Paste this snippet into your external website HTML, just before the closing </body> tag. The floating chat widget will appear automatically.', 'cleversay'); ?>
                </p>
                <?php
                $embed_url   = esc_url(CLEVERSAY_PLUGIN_URL . 'public/js/embed.min.js');
                $site_url    = esc_url(rtrim(home_url(), '/'));
                $embed_token = get_option('cleversay_embed_token', '');

                // In Multisite show allowed domains as a reminder
                $allowed_domains = '';
                if (is_multisite()) {
                    $plan = \CleverSay\NetworkSettings::get_site_plan(get_current_blog_id());
                    $allowed_domains = trim($plan['embed_domains'] ?? '');
                }

                $snippet = '<script>' . "\n"
                    . '(function(w,d,s){' . "\n"
                    . '  var j=d.createElement(s);j.async=true;' . "\n"
                    . "  j.src='" . $embed_url . "';" . "\n"
                    . "  j.setAttribute('data-site','" . $site_url . "');" . "\n"
                    . "  j.setAttribute('data-token','" . esc_attr($embed_token) . "');" . "\n"
                    . '  d.head.appendChild(j);' . "\n"
                    . "})(window,document,'script');" . "\n"
                    . '</script>';
                ?>
                <div style="position:relative;">
                    <textarea id="cs-embed-snippet" class="large-text" rows="7" readonly
                              style="font-family:monospace;font-size:12px;background:#f6f7f7;resize:none;border-radius:4px;"
                              onclick="this.select()"><?php echo esc_textarea($snippet); ?></textarea>
                    <button type="button" id="cs-copy-snippet" class="button"
                            style="position:absolute;top:8px;right:8px;"
                            onclick="
                                var ta = document.getElementById('cs-embed-snippet');
                                ta.select();
                                document.execCommand('copy');
                                this.textContent = '<?php echo esc_js(__('Copied!', 'cleversay')); ?>';
                                var btn = this;
                                setTimeout(function(){ btn.textContent = '<?php echo esc_js(__('Copy', 'cleversay')); ?>'; }, 2000);
                            ">
                        <?php esc_html_e('Copy', 'cleversay'); ?>
                    </button>
                </div>
                <?php if ($allowed_domains): ?>
                <p class="description" style="margin-top:8px;">
                    <?php echo \CleverSay\Icons::render('lock', 18); ?>
                    <?php esc_html_e('This widget is authorised to load on:', 'cleversay'); ?>
                    <strong><?php echo esc_html(str_replace("\n", ', ', trim($allowed_domains))); ?></strong>
                </p>
                <?php elseif (is_multisite()): ?>
                <p class="description" style="margin-top:8px;color:#d63638;">
                    <?php echo \CleverSay\Icons::render('alert-triangle', 18); ?>
                    <?php esc_html_e('No allowed domains set. Contact your administrator to authorise a domain before embedding.', 'cleversay'); ?>
                </p>
                <?php endif; ?>
            </div>

            <!-- Bot Personality Tab -->
            <div class="tab-content" id="tab-personality">
                <p style="color:#646970;margin-bottom:16px;"><?php esc_html_e('This information is injected into the AI\'s system prompt to make responses feel branded and personal. The more detail you provide, the more natural and on-brand the AI answers will be.', 'cleversay'); ?></p>

                <h3><?php echo \CleverSay\Icons::render('message-circle', 18); ?><?php esc_html_e('Mascot &amp; Branding', 'cleversay'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="bot_name"><?php esc_html_e('Bot Name', 'cleversay'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="bot_name" id="bot_name" class="regular-text"
                                   value="<?php echo esc_attr($options['bot_name'] ?? ''); ?>"
                                   placeholder="<?php esc_attr_e('Assistant', 'cleversay'); ?>">
                            <p class="description"><?php esc_html_e('Displayed in the chat header, e.g. "Stevie".', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="bot_agent_label"><?php esc_html_e('Bot Label', 'cleversay'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="bot_agent_label" id="bot_agent_label" class="regular-text"
                                   value="<?php echo esc_attr($options['bot_agent_label'] ?? ''); ?>"
                                   placeholder="<?php esc_attr_e('AI Agent', 'cleversay'); ?>">
                            <p class="description"><?php esc_html_e('Small label shown above each bot message, e.g. "AI Agent" or "Stevie".', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="mascot_image_url"><?php esc_html_e('Mascot / Avatar Image URL', 'cleversay'); ?></label>
                        </th>
                        <td>
                            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                                <?php if (!empty($options['mascot_image_url'])): ?>
                                <img src="<?php echo esc_url($options['mascot_image_url']); ?>"
                                     id="mascot-preview"
                                     alt="<?php esc_attr_e('Mascot preview', 'cleversay'); ?>"
                                     style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid #c3c4c7;">
                                <?php else: ?>
                                <div id="mascot-preview-placeholder"
                                     style="width:56px;height:56px;border-radius:50%;background:#f0f0f1;border:2px dashed #c3c4c7;display:flex;align-items:center;justify-content:center;">
                                    <?php echo \CleverSay\Icons::render('user', 18); ?>
                                </div>
                                <?php endif; ?>
                                <div style="flex:1;">
                                    <input type="url" name="mascot_image_url" id="mascot_image_url"
                                           class="large-text"
                                           value="<?php echo esc_attr($options['mascot_image_url'] ?? ''); ?>"
                                           placeholder="https://example.com/mascot.png">
                                    <p class="description" style="margin-top:4px;">
                                        <?php esc_html_e('Paste an image URL or use the button to pick from your media library. Recommended: square, at least 80×80px.', 'cleversay'); ?>
                                    </p>
                                    <button type="button" class="button" id="cs-pick-mascot" style="margin-top:6px;">
                                        <?php echo \CleverSay\Icons::render('image', 18); ?>
                                        <?php esc_html_e('Choose from Media Library', 'cleversay'); ?>
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                </table>

                <script>
                (function($) {
                    // Live preview when URL is typed
                    $('#mascot_image_url').on('input change', function() {
                        var url = $(this).val().trim();
                        var $preview = $('#mascot-preview, #mascot-preview-placeholder');
                        if (url) {
                            if ($('#mascot-preview').length) {
                                $('#mascot-preview').attr('src', url);
                            } else {
                                $('#mascot-preview-placeholder').replaceWith(
                                    '<img id="mascot-preview" src="' + url + '" alt="" style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid #c3c4c7;">'
                                );
                            }
                        }
                    });

                    // WordPress media picker
                    $('#cs-pick-mascot').on('click', function(e) {
                        e.preventDefault();
                        var frame = wp.media({
                            title: '<?php echo esc_js(__('Choose Mascot Image', 'cleversay')); ?>',
                            button: { text: '<?php echo esc_js(__('Use this image', 'cleversay')); ?>' },
                            multiple: false,
                            library: { type: 'image' }
                        });
                        frame.on('select', function() {
                            var attachment = frame.state().get('selection').first().toJSON();
                            $('#mascot_image_url').val(attachment.url).trigger('change');
                        });
                        frame.open();
                    });
                })(jQuery);
                </script>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="persona_school_name"><?php esc_html_e('School / Organization Name', 'cleversay'); ?></label></th>
                        <td>
                            <input type="text" name="persona_school_name" id="persona_school_name" class="regular-text"
                                   value="<?php echo esc_attr($options['persona_school_name'] ?? ''); ?>"
                                   placeholder="University of Wisconsin–Stevens Point">
                            <p class="description"><?php esc_html_e('Full official name. Used in AI responses when referring to the institution.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="persona_short_name"><?php esc_html_e('Short Name / Abbreviation', 'cleversay'); ?></label></th>
                        <td>
                            <input type="text" name="persona_short_name" id="persona_short_name" class="regular-text"
                                   value="<?php echo esc_attr($options['persona_short_name'] ?? ''); ?>"
                                   placeholder="UWSP">
                            <p class="description"><?php esc_html_e('Used for casual references, e.g. "UWSP" or "the Point".', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="persona_mascot_name"><?php esc_html_e('Mascot Name', 'cleversay'); ?></label></th>
                        <td>
                            <input type="text" name="persona_mascot_name" id="persona_mascot_name" class="regular-text"
                                   value="<?php echo esc_attr($options['persona_mascot_name'] ?? ''); ?>"
                                   placeholder="Stevie the Pointer">
                            <p class="description"><?php esc_html_e('Full mascot name. The AI will introduce itself using this name.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="persona_tone"><?php esc_html_e('Tone / Personality', 'cleversay'); ?></label></th>
                        <td>
                            <select name="persona_tone" id="persona_tone">
                                <?php
                                $tone = $options['persona_tone'] ?? 'friendly';
                                $tones = [
                                    'friendly'     => 'Friendly & Approachable',
                                    'professional' => 'Professional & Formal',
                                    'enthusiastic' => 'Enthusiastic & Spirited',
                                    'calm'         => 'Calm & Reassuring',
                                ];
                                foreach ($tones as $val => $label):
                                ?>
                                <option value="<?php echo esc_attr($val); ?>" <?php selected($tone, $val); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Sets the overall communication style of AI responses.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="persona_audience"><?php esc_html_e('Primary Audience', 'cleversay'); ?></label></th>
                        <td>
                            <input type="text" name="persona_audience" id="persona_audience" class="regular-text"
                                   value="<?php echo esc_attr($options['persona_audience'] ?? ''); ?>"
                                   placeholder="current and prospective students">
                            <p class="description"><?php esc_html_e('Who the bot is talking to. Used to calibrate language and suggestions.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="persona_topics"><?php esc_html_e('Main Topics / Purpose', 'cleversay'); ?></label></th>
                        <td>
                            <input type="text" name="persona_topics" id="persona_topics" class="large-text"
                                   value="<?php echo esc_attr($options['persona_topics'] ?? ''); ?>"
                                   placeholder="admissions, financial aid, campus services, academic programs">
                            <p class="description"><?php esc_html_e('Comma-separated list of what the bot helps with. Helps the AI stay on-topic.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="persona_extra"><?php esc_html_e('Additional Context', 'cleversay'); ?></label></th>
                        <td>
                            <textarea name="persona_extra" id="persona_extra" class="large-text" rows="4"
                                      placeholder="e.g. Always refer students to their academic advisor for course planning. The campus is located in Stevens Point, Wisconsin."><?php echo esc_textarea($options['persona_extra'] ?? ''); ?></textarea>
                            <p class="description"><?php esc_html_e('Any extra instructions, facts, or rules you want the AI to always follow.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                </table>

                <div style="background:#f0f6fc;border:1px solid #c3d9f0;border-radius:4px;padding:12px 16px;margin-top:8px;">
                    <strong><?php esc_html_e('Preview — AI will be introduced as:', 'cleversay'); ?></strong>
                    <p style="margin:8px 0 0;color:#2c3338;" id="persona-preview">
                        <?php
                        $prev_name    = $options['persona_mascot_name'] ?? ($options['bot_name'] ?? 'your support assistant');
                        $prev_school  = $options['persona_school_name'] ?? '';
                        $prev_short   = $options['persona_short_name'] ?? '';
                        $prev_topics  = $options['persona_topics'] ?? 'general questions';
                        echo esc_html(
                            $prev_name . ($prev_school ? ', the ' . ($prev_short ?: $prev_school) . ' assistant' : '') .
                            ', here to help with ' . ($prev_topics ?: 'your questions') . '.'
                        );
                        ?>
                    </p>
                </div>
            </div>

            <!-- Appearance Tab -->
            <div class="tab-content" id="tab-appearance">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start;">

                    <!-- Color controls -->
                    <div>
                        <!-- Font Family -->
                        <h3 style="margin-top:0;"><?php echo \CleverSay\Icons::render('book-open', 16); ?> <?php esc_html_e('Font', 'cleversay'); ?></h3>
                        <table class="form-table" style="margin-top:0;margin-bottom:24px;">
                            <tr>
                                <th><label for="widget_font"><?php esc_html_e('Font Family', 'cleversay'); ?></label></th>
                                <td>
                                    <select name="widget_font" id="widget_font" onchange="document.getElementById('widget_font_custom_row').style.display=this.value==='custom'?'':'none'">
                                        <?php
                                        $current_font = $options['widget_font'] ?? 'system';
                                        $fonts = [
                                            'system'  => 'System Default',
                                            'inter'   => 'Inter',
                                            'dm-sans' => 'DM Sans',
                                            'nunito'  => 'Nunito',
                                            'poppins' => 'Poppins',
                                            'lato'    => 'Lato',
                                            'custom'  => 'Custom…',
                                        ];
                                        foreach ($fonts as $val => $label):
                                        ?>
                                        <option value="<?php echo esc_attr($val); ?>" <?php selected($current_font, $val); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr id="widget_font_custom_row" style="<?php echo ($current_font === 'custom') ? '' : 'display:none'; ?>">
                                <th><label for="widget_font_custom_url"><?php esc_html_e('Google Fonts URL', 'cleversay'); ?></label></th>
                                <td>
                                    <input type="url" name="widget_font_custom_url" id="widget_font_custom_url"
                                           class="regular-text"
                                           value="<?php echo esc_attr($options['widget_font_custom_url'] ?? ''); ?>"
                                           placeholder="https://fonts.googleapis.com/css2?family=Roboto:wght@400;600&display=swap">
                                    <p class="description"><?php esc_html_e('Paste the Google Fonts @import URL.', 'cleversay'); ?></p>
                                </td>
                            </tr>
                            <tr id="widget_font_custom_family_row" style="<?php echo ($current_font === 'custom') ? '' : 'display:none'; ?>">
                                <th><label for="widget_font_custom_family"><?php esc_html_e('Font Family Name', 'cleversay'); ?></label></th>
                                <td>
                                    <input type="text" name="widget_font_custom_family" id="widget_font_custom_family"
                                           class="regular-text"
                                           value="<?php echo esc_attr($options['widget_font_custom_family'] ?? ''); ?>"
                                           placeholder="Roboto, sans-serif">
                                    <p class="description"><?php esc_html_e('CSS font-family value, e.g. "Roboto, sans-serif".', 'cleversay'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="widget_font_size"><?php esc_html_e('Font Size (px)', 'cleversay'); ?></label></th>
                                <td>
                                    <input type="number" name="widget_font_size" id="widget_font_size"
                                           value="<?php echo esc_attr($options['widget_font_size'] ?? 15); ?>"
                                           min="11" max="24" step="1" style="width:80px;">
                                    <span style="margin-left:6px;color:#646970;">px</span>
                                    <p class="description"><?php esc_html_e('Base font size for message bubbles. Default: 15px.', 'cleversay'); ?></p>
                                </td>
                            </tr>
                        </table>

                        <h3><?php echo \CleverSay\Icons::render('image', 16); ?> <?php esc_html_e('Widget Colors', 'cleversay'); ?></h3>
                        <table class="form-table" style="margin-top:0;">
                            <tr>
                                <th><?php esc_html_e('Header Background', 'cleversay'); ?></th>
                                <td>
                                    <input type="color" name="header_bg_color" id="header_bg_color"
                                           value="<?php echo esc_attr($options['header_bg_color']); ?>"
                                           data-preview="header-bg">
                                    <p class="description"><?php esc_html_e('Chat header bar background.', 'cleversay'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Header Text', 'cleversay'); ?></th>
                                <td>
                                    <input type="color" name="header_text_color" id="header_text_color"
                                           value="<?php echo esc_attr($options['header_text_color']); ?>"
                                           data-preview="header-text">
                                    <p class="description"><?php esc_html_e('Bot name and close button color.', 'cleversay'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Chat Background', 'cleversay'); ?></th>
                                <td>
                                    <input type="color" name="chat_bg_color" id="chat_bg_color"
                                           value="<?php echo esc_attr($options['chat_bg_color']); ?>"
                                           data-preview="chat-bg">
                                    <p class="description"><?php esc_html_e('Message area background.', 'cleversay'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Widget Background', 'cleversay'); ?></th>
                                <td>
                                    <input type="color" name="background_color" id="background_color"
                                           value="<?php echo esc_attr($options['background_color'] ?? '#ffffff'); ?>"
                                           data-preview="widget-bg">
                                    <p class="description"><?php esc_html_e('Outer widget container and input area background.', 'cleversay'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('User Bubble', 'cleversay'); ?></th>
                                <td>
                                    <input type="color" name="user_bubble_color" id="user_bubble_color"
                                           value="<?php echo esc_attr($options['user_bubble_color']); ?>"
                                           data-preview="user-bg">
                                    <p class="description"><?php esc_html_e('Visitor message bubble background.', 'cleversay'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('User Bubble Text', 'cleversay'); ?></th>
                                <td>
                                    <input type="color" name="user_bubble_text" id="user_bubble_text"
                                           value="<?php echo esc_attr($options['user_bubble_text']); ?>"
                                           data-preview="user-text">
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Bot Bubble', 'cleversay'); ?></th>
                                <td>
                                    <input type="color" name="bot_bubble_color" id="bot_bubble_color"
                                           value="<?php echo esc_attr($options['bot_bubble_color']); ?>"
                                           data-preview="bot-bg">
                                    <p class="description"><?php esc_html_e('Bot message bubble background.', 'cleversay'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Bot Bubble Text', 'cleversay'); ?></th>
                                <td>
                                    <input type="color" name="bot_bubble_text" id="bot_bubble_text"
                                           value="<?php echo esc_attr($options['bot_bubble_text']); ?>"
                                           data-preview="bot-text">
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Toggle Button', 'cleversay'); ?></th>
                                <td>
                                    <input type="color" name="toggle_bg_color" id="toggle_bg_color"
                                           value="<?php echo esc_attr($options['toggle_bg_color']); ?>"
                                           data-preview="toggle-bg">
                                    <p class="description"><?php esc_html_e('Floating open/close button color.', 'cleversay'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Primary / Accent', 'cleversay'); ?></th>
                                <td>
                                    <input type="color" name="primary_color" id="primary_color"
                                           value="<?php echo esc_attr($options['primary_color']); ?>">
                                    <p class="description"><?php esc_html_e('Send button and link/tag accents.', 'cleversay'); ?></p>
                                </td>
                            </tr>
                        </table>
                        <p>
                            <button type="button" class="button" id="cs-reset-colors">
                                <?php esc_html_e('Reset to defaults', 'cleversay'); ?>
                            </button>
                        </p>
                    </div>

                    <!-- Live preview -->
                    <div>
                        <h3 style="margin-top:0;"><?php echo \CleverSay\Icons::render('eye', 16); ?> <?php esc_html_e('Live Preview', 'cleversay'); ?></h3>
                        <div id="cs-color-preview" style="
                            width:280px;border-radius:12px;overflow:hidden;
                            box-shadow:0 4px 20px rgba(0,0,0,0.15);font-family:sans-serif;font-size:13px;">

                            <!-- Header -->
                            <div id="pv-header" style="
                                background:<?php echo esc_attr($options['header_bg_color']); ?>;
                                padding:12px 14px;display:flex;align-items:center;gap:10px;">
                                <?php if (!empty($options['mascot_image_url'])): ?>
                                <img src="<?php echo esc_url($options['mascot_image_url']); ?>"
                                     style="width:34px;height:34px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.4);" alt="">
                                <?php endif; ?>
                                <span id="pv-bot-name" style="color:<?php echo esc_attr($options['header_text_color']); ?>;font-weight:600;flex:1;">
                                    <?php echo esc_html(!empty($options['bot_name']) ? $options['bot_name'] : 'Assistant'); ?>
                                </span>
                                <span style="color:<?php echo esc_attr($options['header_text_color']); ?>;opacity:.8;font-size:18px;cursor:pointer;">✕</span>
                            </div>

                            <!-- Messages area -->
                            <div id="pv-messages" style="
                                background:<?php echo esc_attr($options['chat_bg_color']); ?>;
                                padding:14px 12px;display:flex;flex-direction:column;gap:12px;min-height:160px;">

                                <!-- Bot message -->
                                <div style="display:flex;gap:8px;align-items:flex-start;">
                                    <?php if (!empty($options['mascot_image_url'])): ?>
                                    <img src="<?php echo esc_url($options['mascot_image_url']); ?>"
                                         style="width:30px;height:30px;border-radius:50%;object-fit:cover;" alt="">
                                    <?php else: ?>
                                    <div style="width:30px;height:30px;border-radius:50%;background:<?php echo esc_attr($options['header_bg_color']); ?>;flex-shrink:0;"></div>
                                    <?php endif; ?>
                                    <div>
                                        <div style="font-size:10px;font-weight:600;color:#888;text-transform:uppercase;margin-bottom:3px;">
                                            <?php echo esc_html(!empty($options['bot_agent_label']) ? $options['bot_agent_label'] : 'AI Agent'); ?>
                                        </div>
                                        <div id="pv-bot-bubble" style="
                                            background:<?php echo esc_attr($options['bot_bubble_color']); ?>;
                                            color:<?php echo esc_attr($options['bot_bubble_text']); ?>;
                                            padding:9px 12px;border-radius:0 12px 12px 12px;
                                            max-width:180px;line-height:1.4;">
                                            <?php echo esc_html(!empty($options['widget_welcome_message']) ? $options['widget_welcome_message'] : 'Hello! How can I help?'); ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- User message -->
                                <div style="display:flex;justify-content:flex-end;">
                                    <div id="pv-user-bubble" style="
                                        background:<?php echo esc_attr($options['user_bubble_color']); ?>;
                                        color:<?php echo esc_attr($options['user_bubble_text']); ?>;
                                        padding:9px 14px;border-radius:12px 12px 0 12px;
                                        max-width:160px;line-height:1.4;">
                                        How much is tuition?
                                    </div>
                                </div>
                            </div>

                            <!-- Input bar -->
                            <div style="background:#fff;padding:10px 12px;display:flex;align-items:center;gap:8px;border-top:1px solid #eee;">
                                <span style="flex:1;color:#aaa;">
                                    <?php echo esc_html(!empty($options['widget_placeholder']) ? $options['widget_placeholder'] : 'Type a message...'); ?>
                                </span>
                                <div id="pv-send-btn" style="
                                    width:30px;height:30px;border-radius:50%;
                                    background:<?php echo esc_attr($options['primary_color']); ?>;
                                    display:flex;align-items:center;justify-content:center;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="#fff"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                                </div>
                            </div>
                        </div>

                        <!-- Toggle button preview -->
                        <div style="margin-top:16px;display:flex;align-items:center;gap:12px;">
                            <div id="pv-toggle-btn" style="
                                width:52px;height:52px;border-radius:50%;
                                background:<?php echo esc_attr($options['toggle_bg_color']); ?>;
                                display:flex;align-items:center;justify-content:center;
                                box-shadow:0 4px 12px rgba(0,0,0,0.2);cursor:pointer;">
                                <?php if (!empty($options['mascot_image_url'])): ?>
                                <img src="<?php echo esc_url($options['mascot_image_url']); ?>"
                                     style="width:100%;height:100%;border-radius:50%;object-fit:cover;" alt="">
                                <?php else: ?>
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="#fff"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>
                                <?php endif; ?>
                            </div>
                            <span style="font-size:12px;color:#666;"><?php esc_html_e('Toggle button', 'cleversay'); ?></span>
                        </div>
                    </div>
                </div>

                <script>
                (function() {
                    const defaults = {
                        header_bg_color:   '#2271b1',
                        header_text_color: '#ffffff',
                        chat_bg_color:     '#f5f5f7',
                        user_bubble_color: '#2271b1',
                        user_bubble_text:  '#ffffff',
                        bot_bubble_color:  '#ffffff',
                        bot_bubble_text:   '#1d2327',
                        toggle_bg_color:   '#2271b1',
                        primary_color:     '#2271b1',
                    };

                    const map = {
                        'header_bg_color':   (v) => { document.getElementById('pv-header').style.background = v; },
                        'header_text_color': (v) => { document.querySelectorAll('#pv-header span').forEach(el => el.style.color = v); },
                        'chat_bg_color':     (v) => { document.getElementById('pv-messages').style.background = v; },
                        'user_bubble_color': (v) => { document.getElementById('pv-user-bubble').style.background = v; },
                        'user_bubble_text':  (v) => { document.getElementById('pv-user-bubble').style.color = v; },
                        'bot_bubble_color':  (v) => { document.getElementById('pv-bot-bubble').style.background = v; },
                        'bot_bubble_text':   (v) => { document.getElementById('pv-bot-bubble').style.color = v; },
                        'toggle_bg_color':   (v) => { document.getElementById('pv-toggle-btn').style.background = v; },
                        'primary_color':     (v) => { document.getElementById('pv-send-btn').style.background = v; },
                    };

                    Object.keys(map).forEach(function(name) {
                        const input = document.getElementById(name);
                        if (input) {
                            input.addEventListener('input', function() {
                                map[name](this.value);
                            });
                        }
                    });

                    document.getElementById('cs-reset-colors').addEventListener('click', function() {
                        Object.keys(defaults).forEach(function(name) {
                            const input = document.getElementById(name);
                            if (input) {
                                input.value = defaults[name];
                                if (map[name]) map[name](defaults[name]);
                            }
                        });
                    });
                })();
                </script>
            </div>
            
            <!-- Search Tab -->
            <div class="tab-content" id="tab-search">
                <h3><?php echo \CleverSay\Icons::render('check-circle', 18); ?><?php esc_html_e('Spell Check Settings', 'cleversay'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Spell Check', 'cleversay'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_spellcheck" value="1" 
                                       <?php checked($options['enable_spellcheck']); ?>>
                                <?php esc_html_e('Enable automatic spell correction', 'cleversay'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="spellcheck_threshold"><?php esc_html_e('Spell Check Threshold', 'cleversay'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="spellcheck_threshold" id="spellcheck_threshold" 
                                   value="<?php echo esc_attr($options['spellcheck_threshold']); ?>"
                                   min="50" max="100" step="5" class="small-text">%
                            <p class="description"><?php esc_html_e('Minimum similarity for spell corrections (50-100%).', 'cleversay'); ?></p>
                        </td>
                    </tr>
                </table>
                
                
                <h3><?php echo \CleverSay\Icons::render('search', 18); ?><?php esc_html_e('Search Results', 'cleversay'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="min_match_score"><?php esc_html_e('Minimum Match Score', 'cleversay'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="min_match_score" id="min_match_score" 
                                   value="<?php echo esc_attr($options['min_match_score']); ?>"
                                   min="0" max="100" step="5" class="small-text">%
                            <p class="description"><?php esc_html_e('Results below this score will not be shown.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="max_results"><?php esc_html_e('Maximum Results', 'cleversay'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="max_results" id="max_results" 
                                   value="<?php echo esc_attr($options['max_results']); ?>"
                                   min="1" max="20" class="small-text">
                            <p class="description"><?php esc_html_e('Maximum number of results to return per search.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                </table>
                
                
                <h3><?php echo \CleverSay\Icons::render('star', 18); ?><?php esc_html_e('Rating System', 'cleversay'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Show Rating', 'cleversay'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_rating" value="1" 
                                       <?php checked($options['show_rating']); ?>>
                                <?php esc_html_e('Show "Was this helpful?" rating buttons', 'cleversay'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                
                <h3><?php echo \CleverSay\Icons::render('filter', 18); ?><?php esc_html_e('Stopwords', 'cleversay'); ?></h3>
                <p class="description"><?php esc_html_e('Words to ignore during search (one per line). Common examples: the, a, an, is, are, etc.', 'cleversay'); ?></p>
                <textarea name="stopwords" rows="8" class="large-text code"><?php 
                    echo esc_textarea(implode("\n", $stopwords)); 
                ?></textarea>
            </div>
            
            <!-- Inquiries Tab -->
            <div class="tab-content" id="tab-inquiries">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Inquiry Form', 'cleversay'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_inquiry_form" value="1" 
                                       <?php checked($options['enable_inquiry_form']); ?>>
                                <?php esc_html_e('Allow users to submit questions when no match is found', 'cleversay'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="inquiry_notification_email"><?php esc_html_e('Notification Email', 'cleversay'); ?></label>
                        </th>
                        <td>
                            <input type="email" name="inquiry_notification_email" id="inquiry_notification_email" 
                                   class="regular-text" value="<?php echo esc_attr($options['inquiry_notification_email']); ?>">
                            <p class="description"><?php esc_html_e('Email address to receive inquiry notifications.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Require Email', 'cleversay'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="require_email_for_inquiry" value="1" 
                                       <?php checked($options['require_email_for_inquiry']); ?>>
                                <?php esc_html_e('Require users to provide their email when submitting inquiries', 'cleversay'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="no_answer_message"><?php esc_html_e('No Answer Message', 'cleversay'); ?></label>
                        </th>
                        <td>
                            <textarea name="no_answer_message" id="no_answer_message" class="large-text" rows="2"><?php 
                                echo esc_textarea($options['no_answer_message']); 
                            ?></textarea>
                            <p class="description"><?php esc_html_e('Displayed when no matching answer is found.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="inquiry_intro_message"><?php esc_html_e('Form Intro Message', 'cleversay'); ?></label>
                        </th>
                        <td>
                            <textarea name="inquiry_intro_message" id="inquiry_intro_message" class="large-text" rows="2"><?php 
                                echo esc_textarea($options['inquiry_intro_message']); 
                            ?></textarea>
                            <p class="description"><?php esc_html_e('Shown above the contact form when the user opens it. Keeps the conversation feeling natural before the form appears.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="inquiry_success_message"><?php esc_html_e('Success Message', 'cleversay'); ?></label>
                        </th>
                        <td>
                            <textarea name="inquiry_success_message" id="inquiry_success_message" class="large-text" rows="2"><?php 
                                echo esc_textarea($options['inquiry_success_message']); 
                            ?></textarea>
                            <p class="description"><?php esc_html_e('Displayed after a user submits an inquiry.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ── Lead Capture Tab ──────────────────────────────────────── -->
            <div class="tab-content" id="tab-lead-capture">
                <?php
                // Pull all lead-capture options with sensible defaults
                $lead_enabled       = (bool) get_option('cleversay_lead_capture_enabled', false);
                $lead_welcome       = get_option('cleversay_lead_welcome_message',
                    __('Welcome! To get started, please share some info below.', 'cleversay'));
                $lead_consent       = get_option('cleversay_lead_consent_text',
                    __('By continuing, you agree to be contacted about your inquiry.', 'cleversay'));
                $lead_cooldown      = (int) get_option('cleversay_lead_cooldown_days', 90);
                $lead_hard_gate     = (bool) get_option('cleversay_lead_hard_gate', true);
                $lead_notify        = (bool) get_option('cleversay_lead_notify_admin', false);
                $lead_identity_lbl  = get_option('cleversay_lead_identity_label', __('I am a…', 'cleversay'));
                $lead_identity_opts = (array) get_option('cleversay_lead_identity_options', [
                    'High School Student',
                    'Prospective Graduate Student',
                    'Transfer Student',
                    'Certificate Seeker',
                    'Parent',
                    'Current Student',
                ]);
                $lead_field_config  = (array) get_option('cleversay_lead_field_config', []);
                $field_defaults = [
                    'first_name'    => ['enabled' => true, 'required' => true],
                    'last_name'     => ['enabled' => true, 'required' => true],
                    'email'         => ['enabled' => true, 'required' => true],
                    'identity'      => ['enabled' => true, 'required' => true],
                    'date_of_birth' => ['enabled' => false, 'required' => false],
                    'phone'         => ['enabled' => false, 'required' => false],
                ];
                foreach ($field_defaults as $k => $d) {
                    $lead_field_config[$k] = array_merge($d, $lead_field_config[$k] ?? []);
                }
                ?>

                <p class="description" style="max-width:760px;font-size:13px;margin-bottom:16px;">
                    <?php esc_html_e('Lead capture shows a form before the visitor can chat. Useful for admissions and prospect-funnel pages — captures who they are before they ask questions. Visitors only see the form once per cooldown period.', 'cleversay'); ?>
                </p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Lead Capture', 'cleversay'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="cleversay_lead_capture_enabled" value="1" <?php checked($lead_enabled); ?>>
                                <?php esc_html_e('Show the lead capture form before allowing the visitor to chat', 'cleversay'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="cleversay_lead_welcome_message"><?php esc_html_e('Welcome Message', 'cleversay'); ?></label></th>
                        <td>
                            <textarea name="cleversay_lead_welcome_message" id="cleversay_lead_welcome_message" class="large-text" rows="2"><?php echo esc_textarea($lead_welcome); ?></textarea>
                            <p class="description"><?php esc_html_e('Shown above the form. Replaces the regular bot greeting when lead capture is on.', 'cleversay'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="cleversay_lead_consent_text"><?php esc_html_e('Consent Text', 'cleversay'); ?></label></th>
                        <td>
                            <textarea name="cleversay_lead_consent_text" id="cleversay_lead_consent_text" class="large-text" rows="2"><?php echo esc_textarea($lead_consent); ?></textarea>
                            <p class="description"><?php esc_html_e('Shown below the form (small text). Use this to satisfy your privacy / data-collection notice requirements.', 'cleversay'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="cleversay_lead_cooldown_days"><?php esc_html_e('Cooldown (days)', 'cleversay'); ?></label></th>
                        <td>
                            <input type="number" name="cleversay_lead_cooldown_days" id="cleversay_lead_cooldown_days"
                                   value="<?php echo esc_attr($lead_cooldown); ?>" min="0" max="3650" step="1" class="small-text">
                            <p class="description"><?php esc_html_e('After submission, the visitor won\'t see the form again for this many days (in the same browser). Set to 0 to show on every visit. Default: 90.', 'cleversay'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Gate Type', 'cleversay'); ?></th>
                        <td>
                            <label style="display:block;margin-bottom:6px;">
                                <input type="radio" name="cleversay_lead_hard_gate" value="1" <?php checked($lead_hard_gate, true); ?>>
                                <strong><?php esc_html_e('Hard gate', 'cleversay'); ?></strong> — <?php esc_html_e('visitor must fill the form to chat', 'cleversay'); ?>
                            </label>
                            <label style="display:block;">
                                <input type="radio" name="cleversay_lead_hard_gate" value="0" <?php checked($lead_hard_gate, false); ?>>
                                <strong><?php esc_html_e('Soft gate', 'cleversay'); ?></strong> — <?php esc_html_e('show a "Skip" link below the form', 'cleversay'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Email Admin on Lead', 'cleversay'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="cleversay_lead_notify_admin" value="1" <?php checked($lead_notify); ?>>
                                <?php esc_html_e('Send an email notification when a lead is captured', 'cleversay'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Uses the same notification address configured for inquiries.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                </table>

                <h3 style="margin-top:28px;"><?php esc_html_e('Form Fields', 'cleversay'); ?></h3>
                <p class="description" style="margin-bottom:8px;font-size:12px;"><?php esc_html_e('Choose which fields to show and which are required.', 'cleversay'); ?></p>
                <table class="form-table cs-lead-fields-table">
                    <thead>
                        <tr>
                            <th style="text-align:left;font-size:12px;font-weight:600;color:#50575e;width:35%;"><?php esc_html_e('Field', 'cleversay'); ?></th>
                            <th style="text-align:left;font-size:12px;font-weight:600;color:#50575e;"><?php esc_html_e('Show', 'cleversay'); ?></th>
                            <th style="text-align:left;font-size:12px;font-weight:600;color:#50575e;"><?php esc_html_e('Required', 'cleversay'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $field_labels = [
                            'first_name'    => __('First name',     'cleversay'),
                            'last_name'     => __('Last name',      'cleversay'),
                            'email'         => __('Email',          'cleversay'),
                            'identity'      => __('Identity / Role (dropdown)', 'cleversay'),
                            'phone'         => __('Phone',          'cleversay'),
                            'date_of_birth' => __('Date of birth',  'cleversay'),
                        ];
                        foreach ($field_labels as $key => $label):
                            $cfg = $lead_field_config[$key];
                        ?>
                            <tr>
                                <td style="padding:6px 8px;">
                                    <label><strong><?php echo esc_html($label); ?></strong></label>
                                    <?php if ($key === 'date_of_birth'): ?>
                                        <p class="description" style="font-size:11px;margin-top:2px;">
                                            <?php esc_html_e('Be cautious — DOB collection from minors may be subject to FERPA / COPPA requirements depending on your context.', 'cleversay'); ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:6px 8px;">
                                    <input type="checkbox" name="cleversay_lead_field_config[<?php echo esc_attr($key); ?>][enabled]" value="1" <?php checked(!empty($cfg['enabled'])); ?>>
                                </td>
                                <td style="padding:6px 8px;">
                                    <input type="checkbox" name="cleversay_lead_field_config[<?php echo esc_attr($key); ?>][required]" value="1" <?php checked(!empty($cfg['required'])); ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <h3 style="margin-top:28px;"><?php esc_html_e('Identity Dropdown Options', 'cleversay'); ?></h3>
                <p class="description" style="margin-bottom:8px;font-size:12px;"><?php esc_html_e('Options shown in the "I am a…" dropdown. One per line.', 'cleversay'); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="cleversay_lead_identity_label"><?php esc_html_e('Dropdown Label', 'cleversay'); ?></label></th>
                        <td>
                            <input type="text" name="cleversay_lead_identity_label" id="cleversay_lead_identity_label"
                                   value="<?php echo esc_attr($lead_identity_lbl); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cleversay_lead_identity_options"><?php esc_html_e('Options (one per line)', 'cleversay'); ?></label></th>
                        <td>
                            <textarea name="cleversay_lead_identity_options" id="cleversay_lead_identity_options" class="large-text" rows="6"
                                      placeholder="High School Student&#10;Prospective Graduate Student&#10;Transfer Student&#10;Parent"><?php
                                echo esc_textarea(implode("\n", $lead_identity_opts));
                            ?></textarea>
                            <p class="description"><?php esc_html_e('Edit, add, or remove options. Each line becomes one dropdown choice.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                </table>

                <p style="margin-top:18px;font-size:12px;color:#646970;">
                    <strong><?php esc_html_e('Tip:', 'cleversay'); ?></strong>
                    <?php
                    printf(
                        /* translators: %s = link to Leads admin page */
                        esc_html__('View captured leads on the %s page.', 'cleversay'),
                        '<a href="' . esc_url(admin_url('admin.php?page=cleversay-leads')) . '">' . esc_html__('Leads', 'cleversay') . '</a>'
                    );
                    ?>
                </p>
            </div>
            
            <!-- Analytics Tab -->
            <div class="tab-content" id="tab-analytics">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Analytics', 'cleversay'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_analytics" value="1" 
                                       <?php checked($options['enable_analytics']); ?>>
                                <?php esc_html_e('Track questions and search patterns', 'cleversay'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Track Visitors', 'cleversay'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="track_visitors" value="1" 
                                       <?php checked($options['track_visitors']); ?>>
                                <?php esc_html_e('Track unique visitors by IP address', 'cleversay'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Anonymize IP', 'cleversay'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="anonymize_ip" value="1" 
                                       <?php checked($options['anonymize_ip']); ?>>
                                <?php esc_html_e('Anonymize IP addresses (recommended for GDPR compliance)', 'cleversay'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('The last octet of IP addresses will be replaced with 0.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Exclude Bot Traffic', 'cleversay'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="exclude_bot_traffic" value="1" 
                                       <?php checked($options['exclude_bot_traffic'] ?? true); ?>>
                                <?php esc_html_e('Do not log questions from search engine bots and crawlers', 'cleversay'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Filters out traffic from Googlebot, Bingbot, and other known bots to keep your analytics accurate.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php echo \CleverSay\Icons::render('bar-chart', 14); ?>
                            <?php esc_html_e('Track source usage', 'cleversay'); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="track_source_usage" value="1"
                                       <?php checked(get_option('cleversay_track_source_usage', false)); ?>>
                                <?php esc_html_e('Record which AI Sources contribute to each answer', 'cleversay'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('When enabled, the AI Sources page shows how often each source is retrieved and how visitors rated the conversations that used it. Adds 3–5 database rows per AI answer.', 'cleversay'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Advanced Tab -->
            <div class="tab-content" id="tab-advanced">
                <h3><?php echo \CleverSay\Icons::render('globe', 18); ?><?php esc_html_e('Embed &amp; Cross-Origin', 'cleversay'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Allowed Domains', 'cleversay'); ?>
                        </th>
                        <td>
                            <?php
                            $plan = is_multisite()
                                ? \CleverSay\NetworkSettings::get_site_plan(get_current_blog_id())
                                : [];
                            $domains = trim($plan['embed_domains'] ?? get_option('cleversay_embed_domains', ''));
                            ?>
                            <?php if ($domains): ?>
                                <code style="display:block;background:#f6f7f7;padding:8px 12px;border:1px solid #dcdcde;border-radius:3px;white-space:pre-wrap;"><?php echo esc_html($domains); ?></code>
                            <?php else: ?>
                                <span style="color:#86868b;"><?php esc_html_e('No domains configured.', 'cleversay'); ?></span>
                            <?php endif; ?>
                            <?php if (is_multisite()): ?>
                            <p class="description" style="margin-top:6px;">
                                <?php esc_html_e('Embed domains are managed by your administrator. Contact them to add or change allowed domains.', 'cleversay'); ?>
                            </p>
                            <?php endif; ?>
                            <p style="margin-top:16px;font-weight:600;"><?php esc_html_e('Embed Token', 'cleversay'); ?></p>
                            <p class="description" style="margin-bottom:6px;"><?php esc_html_e('This token authenticates requests from embed.js. It is included automatically in the snippet below.', 'cleversay'); ?></p>
                            <div style="display:flex;gap:8px;align-items:center;margin-bottom:16px;">
                                <code style="background:#f6f7f7;padding:6px 10px;border:1px solid #dcdcde;border-radius:3px;font-size:13px;flex:1;word-break:break-all;"><?php echo esc_html(get_option('cleversay_embed_token', '(not generated — re-save or re-activate)')); ?></code>
                                <form method="post" style="margin:0;">
                                    <?php
                                    $regen_token = bin2hex(random_bytes(16));
                                    update_user_meta(get_current_user_id(), 'cleversay_regen_token', $regen_token);
                                    ?>
                                    <input type="hidden" name="cleversay_regen_csrf" value="<?php echo esc_attr($regen_token); ?>">
                                    <button type="submit" name="cleversay_regenerate_token" value="1" class="button"
                                            onclick="return confirm('<?php esc_attr_e("Regenerate the token? Any existing embed.js installations will need the new snippet.", "cleversay"); ?>');">
                                        <?php esc_html_e('Regenerate', 'cleversay'); ?>
                                    </button>
                                </form>
                            </div>
                            <p style="margin-top:0;font-weight:600;"><?php esc_html_e('Embed Snippet', 'cleversay'); ?></p>
                            <p class="description" style="margin-bottom:6px;"><?php esc_html_e('Paste this into the external site HTML, just before &lt;/body&gt;:', 'cleversay'); ?></p>
                            <?php
// Use minified version in production snippet
$embed_url  = esc_url(CLEVERSAY_PLUGIN_URL . 'public/js/embed.min.js');
$site_url   = esc_url(rtrim(home_url(), '/'));
$embed_token = get_option('cleversay_embed_token', '');
$snippet    = "<script>\n(function(w,d,s){\n  var j=d.createElement(s);j.async=true;\n  j.src='" . $embed_url . "';\n  j.setAttribute('data-site','" . $site_url . "');\n  j.setAttribute('data-token','" . $embed_token . "');\n  d.head.appendChild(j);\n})(window,document,'script');\n</script>";
?>
                            <textarea class="large-text" rows="6" readonly onclick="this.select()"
                                      style="font-family:monospace;font-size:12px;background:#f6f7f7;"
><?php echo esc_textarea($snippet); ?></textarea>
                        </td>
                    </tr>
                </table>

                <h3><?php echo \CleverSay\Icons::render('settings', 18); ?><?php esc_html_e('Advanced Settings', 'cleversay'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="cache_duration"><?php esc_html_e('Cache Duration', 'cleversay'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="cache_duration" id="cache_duration" 
                                   value="<?php echo esc_attr($options['cache_duration']); ?>"
                                   min="0" step="60" class="small-text">
                            <?php esc_html_e('seconds', 'cleversay'); ?>
                            <p class="description"><?php esc_html_e('How long to cache search results. Set to 0 to disable.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="rate_limit_searches"><?php esc_html_e('Rate Limit', 'cleversay'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="rate_limit_searches" id="rate_limit_searches" 
                                   value="<?php echo esc_attr($options['rate_limit_searches']); ?>"
                                   min="0" class="small-text">
                            <?php esc_html_e('searches per minute per IP', 'cleversay'); ?>
                            <p class="description"><?php esc_html_e('Set to 0 to disable rate limiting.', 'cleversay'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Uninstall', 'cleversay'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="delete_data_on_uninstall" value="1" 
                                       <?php checked($options['delete_data_on_uninstall']); ?>>
                                <?php esc_html_e('Delete all plugin data when uninstalling', 'cleversay'); ?>
                            </label>
                            <p class="description warning">
                                <?php esc_html_e('Warning: This will permanently delete all knowledge base entries, questions, and settings.', 'cleversay'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php
                // v4.41.5.8+: per-site debugging toggle. When enabled, the
                // chat widget appends a small timing subtitle below each
                // bot answer showing client round-trip ms with server
                // total_ms in parentheses. Off by default. Per-site so a
                // staging tenant can enable it without affecting
                // production tenants on the same network.
                ?>
                <h3><?php echo \CleverSay\Icons::render('activity', 18); ?><?php esc_html_e('Debugging', 'cleversay'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Show Response Timing', 'cleversay'); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_timing" value="1"
                                       <?php checked(!empty(get_option('cleversay_show_timing', false))); ?>>
                                <?php esc_html_e('Display response time below each bot answer in the chat widget', 'cleversay'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e(
                                    'Useful for performance testing. Shows the client round-trip time (what the user actually waits for) with the server-side total in parentheses. Off by default — leave off in production. Toggle this on staging to compare model latencies live.',
                                    'cleversay'
                                ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h3><?php echo \CleverSay\Icons::render('info', 18); ?><?php esc_html_e('System Information', 'cleversay'); ?></h3>
                <table class="widefat striped">
                    <tr>
                        <td><?php esc_html_e('Plugin Version', 'cleversay'); ?></td>
                        <td><code><?php echo esc_html(CLEVERSAY_VERSION ?? '1.0.0'); ?></code></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('WordPress Version', 'cleversay'); ?></td>
                        <td><code><?php echo esc_html(get_bloginfo('version')); ?></code></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('PHP Version', 'cleversay'); ?></td>
                        <td><code><?php echo esc_html(PHP_VERSION); ?></code></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Database', 'cleversay'); ?></td>
                        <td><code><?php global $wpdb; echo esc_html($wpdb->db_version()); ?></code></td>
                    </tr>
                </table>
            </div>
        </div>
        
            <!-- AI Settings Tab -->
            <div class="tab-content" id="tab-ai-settings">
                <?php
                $ai_cfg      = \CleverSay\NetworkSettings::get_ai_config();
                $ai_enabled  = $ai_cfg['enabled'];
                $ai_api_key  = $ai_cfg['api_key'];
                $ai_model    = $ai_cfg['model'];
                $ai_budget   = $ai_cfg['monthly_budget'];
                $ai_chunks   = $ai_cfg['max_chunks'];
                $ai_tokens   = $ai_cfg['max_tokens'];
                $ai_min_score= $ai_cfg['fallback_threshold'];
                $ai_label    = $ai_cfg['label'];
                $ai_models   = \CleverSay\AI::get_available_models();
                $key_is_set  = !empty($ai_api_key);
                $key_preview = $key_is_set ? '••••••••••••' . substr($ai_api_key, -4) : '';
                ?>
                <div class="section-card">
                    <h2><?php echo \CleverSay\Icons::render('sparkles', 18); ?> <?php esc_html_e('AI Features', 'cleversay'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('When a user question does not match any entry in your knowledge base, CleverSay can use Claude AI to generate an answer from your uploaded documents and URLs.', 'cleversay'); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=cleversay-ai-sources')); ?>">
                            <?php esc_html_e('Manage AI Sources →', 'cleversay'); ?>
                        </a>
                    </p>

                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('Enable AI Fallback', 'cleversay'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ai_enabled" value="1" <?php checked($ai_enabled); ?>>
                                    <?php esc_html_e('Use AI to answer questions when no knowledge base match is found', 'cleversay'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Validate KB Relevance with AI', 'cleversay'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ai_validate_kb" value="1"
                                        <?php checked(!isset($options['ai_validate_kb']) || !empty($options['ai_validate_kb'])); ?>>
                                    <?php esc_html_e('Before showing a KB answer, ask AI whether it actually answers the question. If not, fall through to AI-generated answer instead.', 'cleversay'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Recommended ON. Catches the case where keyword matching returns a high-confidence KB entry that doesn\'t actually fit the question. Adds ~$0.0001/query and ~200ms latency. Requires API key and AI Fallback enabled.', 'cleversay'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ai_tiebreak_min_score"><?php esc_html_e('AI Tiebreak Min Score', 'cleversay'); ?></label></th>
                            <td>
                                <input type="number"
                                       id="ai_tiebreak_min_score"
                                       name="ai_tiebreak_min_score"
                                       value="<?php echo esc_attr((string) get_option('cleversay_ai_tiebreak_min_score', 100)); ?>"
                                       min="0" max="500" step="5"
                                       style="width:90px;">
                                <p class="description">
                                    <?php
                                    esc_html_e(
                                        'When two or more KB entries match a user query at the same score AND that score is at or above this threshold, ask AI to pick the better fit. Lower = more AI tiebreak calls. Reference points: 100 = base keyword match (aadefault or weak); 110 = single-token wildcard; 145 = 2-token AND; 165+ = 3-token AND. Set to 0 to disable AI tiebreak entirely.',
                                        'cleversay'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Polish KB Responses with AI', 'cleversay'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ai_polish_kb" value="1" <?php checked(!empty($options['ai_polish_kb'])); ?>>
                                    <?php esc_html_e('Have AI rewrite knowledge base matches in your configured tone before showing them. Makes KB and AI responses sound consistent.', 'cleversay'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Stylistic choice — distinct from validation above. Off by default because some sites prefer the exact KB voice. Adds ~$0.001/query and ~500ms when enabled. Requires API key.', 'cleversay'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Validate Broad KB Matches with AI', 'cleversay'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ai_validate_aadefault" value="1" <?php checked(!empty($options['ai_validate_aadefault'])); ?>>
                                    <?php esc_html_e('When a KB match uses the catch-all (aadefault) pattern, ask AI whether the answer actually fits the question. If not, AI generates a better answer instead.', 'cleversay'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Largely redundant if "Validate KB Relevance" above is enabled — that runs on every KB match including aadefault. Kept for granular control.', 'cleversay'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('AI Provider API Keys', 'cleversay'); ?></th>
                            <td>
                                <?php
                                // v4.37.74+: separate key fields per provider so admin
                                // doesn't have to remember and re-enter when switching
                                // models. The key matching the active provider (derived
                                // from the selected model) is the one actually used.
                                $anthropic_key = (string) ($ai_cfg['anthropic_api_key'] ?? '');
                                $gemini_key    = (string) ($ai_cfg['gemini_api_key']    ?? '');
                                // Legacy single-key fallback: if neither per-provider
                                // key is set but the legacy key exists, surface it
                                // under the active provider so admin sees their
                                // existing setting.
                                $legacy_key    = $ai_api_key;
                                $active_provider = (string) ($ai_cfg['active_provider'] ?? 'anthropic');
                                if ($anthropic_key === '' && $gemini_key === '' && $legacy_key !== '') {
                                    if ($active_provider === 'gemini') {
                                        $gemini_key = $legacy_key;
                                    } else {
                                        $anthropic_key = $legacy_key;
                                    }
                                }
                                $anthropic_set = $anthropic_key !== '';
                                $gemini_set    = $gemini_key    !== '';
                                $anthropic_preview = $anthropic_set ? '••••••••••••' . substr($anthropic_key, -4) : '';
                                $gemini_preview    = $gemini_set    ? '••••••••••••' . substr($gemini_key,    -4) : '';
                                ?>
                                <p class="description" style="margin-top:0; margin-bottom:14px;">
                                    <?php esc_html_e('Save keys for both providers so you can switch models freely. The key matching your selected model above will be used for AI calls.', 'cleversay'); ?>
                                </p>

                                <!-- Anthropic / Claude key row -->
                                <div style="margin-bottom:14px; padding:12px; background:#f6f7f7; border:1px solid #ddd; border-radius:4px;">
                                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                                        <strong><?php esc_html_e('Anthropic (Claude)', 'cleversay'); ?></strong>
                                        <?php if ($active_provider === 'anthropic'): ?>
                                            <span style="font-size:11px; padding:2px 8px; background:#dff6dd; color:#0a6b0a; border:1px solid #a3d9a5; border-radius:11px; font-weight:600;">
                                                <?php esc_html_e('Active', 'cleversay'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($anthropic_set): ?>
                                        <div class="cs-key-saved-row" data-provider="anthropic" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                            <code style="background:white; padding:6px 12px; border-radius:4px; font-size:13px; letter-spacing:2px;">
                                                <?php echo esc_html($anthropic_preview); ?>
                                            </code>
                                            <button type="button" class="button cs-test-saved-key" data-provider="anthropic">
                                                <?php esc_html_e('Test', 'cleversay'); ?>
                                            </button>
                                            <button type="button" class="button cs-change-key-btn" data-provider="anthropic">
                                                <?php esc_html_e('Change', 'cleversay'); ?>
                                            </button>
                                            <span class="cs-key-result" data-provider="anthropic" style="display:none;"></span>
                                        </div>
                                        <div class="cs-key-input-row" data-provider="anthropic" style="display:none; gap:10px; align-items:center; flex-wrap:wrap;">
                                    <?php else: ?>
                                        <div class="cs-key-saved-row" data-provider="anthropic" style="display:none;"></div>
                                        <div class="cs-key-input-row" data-provider="anthropic" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                    <?php endif; ?>
                                        <input type="text" name="anthropic_api_key" class="regular-text cs-key-input" data-provider="anthropic"
                                               value="" placeholder="sk-ant-api03-..." autocomplete="off"
                                               style="font-family:monospace;">
                                        <button type="button" class="button cs-test-new-key" data-provider="anthropic">
                                            <?php esc_html_e('Test', 'cleversay'); ?>
                                        </button>
                                        <span class="cs-key-result" data-provider="anthropic" style="display:none;"></span>
                                    </div>
                                    <p class="description" style="margin-top:6px;">
                                        <?php esc_html_e('Get your key at', 'cleversay'); ?>
                                        <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a>
                                    </p>
                                </div>

                                <!-- Google / Gemini key row -->
                                <div style="margin-bottom:8px; padding:12px; background:#f6f7f7; border:1px solid #ddd; border-radius:4px;">
                                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                                        <strong><?php esc_html_e('Google (Gemini)', 'cleversay'); ?></strong>
                                        <?php if ($active_provider === 'gemini'): ?>
                                            <span style="font-size:11px; padding:2px 8px; background:#dff6dd; color:#0a6b0a; border:1px solid #a3d9a5; border-radius:11px; font-weight:600;">
                                                <?php esc_html_e('Active', 'cleversay'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($gemini_set): ?>
                                        <div class="cs-key-saved-row" data-provider="gemini" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                            <code style="background:white; padding:6px 12px; border-radius:4px; font-size:13px; letter-spacing:2px;">
                                                <?php echo esc_html($gemini_preview); ?>
                                            </code>
                                            <button type="button" class="button cs-test-saved-key" data-provider="gemini">
                                                <?php esc_html_e('Test', 'cleversay'); ?>
                                            </button>
                                            <button type="button" class="button cs-change-key-btn" data-provider="gemini">
                                                <?php esc_html_e('Change', 'cleversay'); ?>
                                            </button>
                                            <span class="cs-key-result" data-provider="gemini" style="display:none;"></span>
                                        </div>
                                        <div class="cs-key-input-row" data-provider="gemini" style="display:none; gap:10px; align-items:center; flex-wrap:wrap;">
                                    <?php else: ?>
                                        <div class="cs-key-saved-row" data-provider="gemini" style="display:none;"></div>
                                        <div class="cs-key-input-row" data-provider="gemini" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                    <?php endif; ?>
                                        <input type="text" name="gemini_api_key" class="regular-text cs-key-input" data-provider="gemini"
                                               value="" placeholder="AIzaSy..." autocomplete="off"
                                               style="font-family:monospace;">
                                        <button type="button" class="button cs-test-new-key" data-provider="gemini">
                                            <?php esc_html_e('Test', 'cleversay'); ?>
                                        </button>
                                        <span class="cs-key-result" data-provider="gemini" style="display:none;"></span>
                                    </div>
                                    <p class="description" style="margin-top:6px;">
                                        <?php esc_html_e('Get your key at', 'cleversay'); ?>
                                        <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">aistudio.google.com/app/apikey</a>
                                    </p>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ai_model"><?php esc_html_e('Model', 'cleversay'); ?></label></th>
                            <td>
                                <select id="ai_model" name="ai_model">
                                    <?php foreach ($ai_models as $model_id => $model_label): ?>
                                        <option value="<?php echo esc_attr($model_id); ?>" <?php selected($ai_model, $model_id); ?>>
                                            <?php echo wp_kses_post($model_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Recommended: Claude Haiku 4.5 for balanced speed and quality, or Gemini 3 Flash for lower cost. The provider (Anthropic or Google) is inferred from your model selection — make sure your API key below matches.', 'cleversay'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ai_monthly_budget"><?php esc_html_e('Monthly Budget Cap (USD)', 'cleversay'); ?></label></th>
                            <td>
                                <input type="number" id="ai_monthly_budget" name="ai_monthly_budget"
                                       value="<?php echo esc_attr($ai_budget); ?>"
                                       min="0" step="0.50" style="width:100px;"> $
                                <p class="description"><?php esc_html_e('AI calls stop when this monthly limit is reached. Set to 0 for no limit.', 'cleversay'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ai_max_chunks"><?php esc_html_e('Context Chunks', 'cleversay'); ?></label></th>
                            <td>
                                <input type="number" id="ai_max_chunks" name="ai_max_chunks"
                                       value="<?php echo esc_attr($ai_chunks); ?>"
                                       min="1" max="8" style="width:60px;">
                                <p class="description"><?php esc_html_e('How many document sections to include as context per AI call. More = better answers, higher cost. Recommended: 3–4.', 'cleversay'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ai_max_tokens"><?php esc_html_e('Max Answer Length (tokens)', 'cleversay'); ?></label></th>
                            <td>
                                <input type="number" id="ai_max_tokens" name="ai_max_tokens"
                                       value="<?php echo esc_attr($ai_tokens); ?>"
                                       min="100" max="2000" style="width:80px;">
                                <p class="description"><?php esc_html_e('Maximum length of AI-generated answers. ~750 tokens ≈ 500 words. Recommended: 600–1000.', 'cleversay'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ai_min_score"><?php esc_html_e('AI Trigger Threshold', 'cleversay'); ?></label></th>
                            <td>
                                <input type="number" id="ai_min_score" name="ai_min_score"
                                       value="<?php echo esc_attr($ai_min_score); ?>"
                                       min="0" max="100" style="width:60px;">
                                <p class="description"><?php esc_html_e('Use AI when the best knowledge base match scores below this value. 0 = only when no match at all. 100 = always use AI.', 'cleversay'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ai_label"><?php esc_html_e('AI Answer Label', 'cleversay'); ?></label></th>
                            <td>
                                <input type="text" id="ai_label" name="ai_label"
                                       class="regular-text"
                                       value="<?php echo esc_attr($ai_label); ?>">
                                <p class="description"><?php esc_html_e('Text shown on the badge below AI-generated answers so users know the source.', 'cleversay'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('AI Query Normalization', 'cleversay'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ai_normalize_queries" value="1"
                                           <?php checked(get_option('cleversay_ai_normalize_queries', false)); ?>>
                                    <?php esc_html_e('Use AI to fix typos and garbled questions before searching (e.g. "whee can i buy booooks" → "where can I buy books")', 'cleversay'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Only fires when a query looks garbled — does not add an API call for normal well-typed questions. Uses Claude Haiku (very low cost).', 'cleversay'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Follow-up Suggestions', 'cleversay'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ai_followup_suggestions" value="1"
                                           <?php checked(get_option('cleversay_ai_followup_suggestions', true)); ?>>
                                    <?php esc_html_e('AI ends each answer with a relevant follow-up question to suggest what to ask next', 'cleversay'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Adds engagement by suggesting adjacent topics — e.g. asking about tuition prompts a follow-up about payment plans. Off-topic refusals never get a follow-up. Disable for compliance-heavy sites where strict, terse answers are preferred.', 'cleversay'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Query Rewriter', 'cleversay'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ai_query_rewriter" value="1"
                                           <?php checked(get_option('cleversay_ai_query_rewriter', true)); ?>>
                                    <?php esc_html_e('Use AI to resolve referential follow-up questions ("what about it?", "tell me more") into self-contained queries by reading conversation history', 'cleversay'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Helpful for chatty conversational use. Off for sites where users tend to ask self-contained questions — disabling avoids occasional false positives where the rewriter reframes a complete question into a narrower follow-up. Default on. v4.41.5.9 tightened the firing heuristics to reduce false positives, but the toggle remains for full operator control.', 'cleversay'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Usage summary -->
                <?php
                $ai_obj    = new \CleverSay\AI();
                $usage     = $ai_obj->get_monthly_usage();
                $cost      = $ai_obj->get_monthly_cost();
                ?>
                <div class="section-card" style="margin-top:20px;">
                    <h3><?php echo \CleverSay\Icons::render('trending-up', 16); ?> <?php printf(esc_html__('Usage this month (%s)', 'cleversay'), date('F Y')); ?></h3>
                    <table class="widefat" style="max-width:500px;">
                        <tr><th><?php esc_html_e('AI calls', 'cleversay'); ?></th><td><?php echo number_format((int)($usage['calls'] ?? 0)); ?></td></tr>
                        <tr><th><?php esc_html_e('Input tokens', 'cleversay'); ?></th><td><?php echo number_format((int)($usage['input_tokens'] ?? 0)); ?></td></tr>
                        <tr><th><?php esc_html_e('Output tokens', 'cleversay'); ?></th><td><?php echo number_format((int)($usage['output_tokens'] ?? 0)); ?></td></tr>
                        <tr><th><?php esc_html_e('Estimated cost', 'cleversay'); ?></th><td><strong>$<?php echo number_format($cost, 4); ?></strong><?php if ($ai_budget > 0): ?> / $<?php echo number_format($ai_budget, 2); ?><?php endif; ?></td></tr>
                    </table>
                </div>

                <script>
                (function($) {
                    // v4.37.74+: provider-aware key UI. All buttons and
                    // inputs carry data-provider so handlers can route
                    // to the correct row.

                    function selectorFor(provider, base) {
                        return base + '[data-provider="' + provider + '"]';
                    }

                    // "Change Key" — hide the saved display, show the input
                    $(document).on('click', '.cs-change-key-btn', function() {
                        var provider = $(this).data('provider');
                        $(selectorFor(provider, '.cs-key-saved-row')).hide();
                        $(selectorFor(provider, '.cs-key-input-row')).css('display', 'flex');
                        $(selectorFor(provider, '.cs-key-input')).trigger('focus');
                    });

                    // Test a SAVED key (already in DB). Server reads it
                    // server-side, never returns it to browser.
                    $(document).on('click', '.cs-test-saved-key', function() {
                        var provider = $(this).data('provider');
                        var $btn = $(this).prop('disabled', true).text('Testing…');
                        var $res = $(selectorFor(provider, '.cs-key-result')).show().text('');
                        $.post(ajaxurl, {
                            action:    'cleversay_test_stored_api_key',
                            nonce:     '<?php echo esc_js(wp_create_nonce('cleversay_admin_nonce')); ?>',
                            provider:  provider
                        }).done(function(r) {
                            $res.html(r.success
                                ? '<span style="color:#00a32a;">✓ ' + r.data.message + '</span>'
                                : '<span style="color:#d63638;">✗ ' + (r.data?.message || 'Failed') + '</span>');
                        }).fail(function() {
                            $res.html('<span style="color:#d63638;">✗ Request failed</span>');
                        }).always(function() {
                            $btn.prop('disabled', false).text('<?php echo esc_js(__('Test', 'cleversay')); ?>');
                        });
                    });

                    // Test a NEWLY typed key (not yet saved). Sent in
                    // request payload only; never persisted by this
                    // call — admin still has to click Save Settings.
                    $(document).on('click', '.cs-test-new-key', function() {
                        var provider = $(this).data('provider');
                        var key = $(selectorFor(provider, '.cs-key-input')).val().trim();
                        if (!key) {
                            alert('<?php echo esc_js(__('Please enter your API key first.', 'cleversay')); ?>');
                            return;
                        }
                        var $btn = $(this).prop('disabled', true).text('Testing…');
                        var $res = $(selectorFor(provider, '.cs-key-result')).show();
                        $.post(ajaxurl, {
                            action:   'cleversay_test_api_key',
                            nonce:    '<?php echo esc_js(wp_create_nonce('cleversay_admin_nonce')); ?>',
                            api_key:  key,
                            provider: provider
                        }).done(function(r) {
                            $res.html(r.success
                                ? '<span style="color:#00a32a;">✓ ' + r.data.message + '</span>'
                                : '<span style="color:#d63638;">✗ ' + (r.data?.message || 'Failed') + '</span>');
                        }).fail(function() {
                            $res.html('<span style="color:#d63638;">✗ Request failed</span>');
                        }).always(function() {
                            $btn.prop('disabled', false).text('<?php echo esc_js(__('Test', 'cleversay')); ?>');
                        });
                    });

                })(jQuery);
                </script>

                <!-- AI Diagnostic Tool -->
                <div class="section-card" style="margin-top:20px;">
                    <h3 style="margin-top:0;"><?php echo \CleverSay\Icons::render('activity', 16); ?> <?php esc_html_e('AI Diagnostic', 'cleversay'); ?></h3>
                    <p class="description"><?php esc_html_e('Run a full check of your AI setup to see exactly where a problem might be.', 'cleversay'); ?></p>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
                        <input type="text" id="cs-diag-question" class="regular-text"
                               value="What services do you offer for veterans"
                               placeholder="<?php esc_attr_e('Test question…', 'cleversay'); ?>">
                        <button type="button" class="button button-primary" id="cs-run-diagnostic">
                            <?php esc_html_e('Run Diagnostic', 'cleversay'); ?>
                        </button>
                    </div>
                    <div id="cs-diagnostic-results" style="display:none;"></div>
                    <script>
                    (function($) {
                        $('#cs-run-diagnostic').on('click', function() {
                            var $btn = $(this).prop('disabled', true).text('Running…');
                            var $res = $('#cs-diagnostic-results').show().html('<p><?php echo esc_js(__('Checking…', 'cleversay')); ?></p>');
                            $.post(ajaxurl, {
                                action:        'cleversay_ai_diagnostic',
                                nonce:         '<?php echo esc_js(wp_create_nonce('cleversay_admin_nonce')); ?>',
                                test_question: $('#cs-diag-question').val()
                            }).done(function(r) {
                                if (!r.success) {
                                    $res.html('<p style="color:#d63638;">Request failed: ' + (r.data?.message || 'Unknown error') + '</p>');
                                    return;
                                }
                                var html = '<table class="widefat striped" style="max-width:700px;"><thead><tr>'
                                    + '<th style="width:30px;"></th>'
                                    + '<th><?php echo esc_js(__('Check', 'cleversay')); ?></th>'
                                    + '<th><?php echo esc_js(__('Result', 'cleversay')); ?></th>'
                                    + '</tr></thead><tbody>';
                                r.data.steps.forEach(function(step) {
                                    var icon  = step.pass ? '✅' : '❌';
                                    var color = step.pass ? '' : 'color:#d63638;font-weight:600;';
                                    html += '<tr><td>' + icon + '</td><td>' + escHtml(step.label) + '</td>'
                                          + '<td style="' + color + '">' + escHtml(step.value);
                                    if (step.detail && step.detail.length) {
                                        html += '<br><small style="color:#666;">' + step.detail.map(escHtml).join('<br>') + '</small>';
                                    }
                                    html += '</td></tr>';
                                });
                                html += '</tbody></table>';
                                var summaryColor = r.data.all_pass ? '#00a32a' : '#d63638';
                                html += '<p style="margin-top:10px;font-weight:600;color:' + summaryColor + ';">'
                                      + escHtml(r.data.summary) + '</p>';
                                $res.html(html);
                            }).fail(function() {
                                $res.html('<p style="color:#d63638;">AJAX request failed.</p>');
                            }).always(function() {
                                $btn.prop('disabled', false).text('<?php echo esc_js(__('Run Diagnostic', 'cleversay')); ?>');
                            });
                        });
                        function escHtml(t) { var d = document.createElement('div'); d.textContent = t || ''; return d.innerHTML; }
                    })(jQuery);
                    </script>
                </div>

            </div>

        <p class="submit">
            <input type="submit" name="cleversay_save_settings" class="button button-primary" 
                   value="<?php esc_attr_e('Save Settings', 'cleversay'); ?>">
        </p>


    </form>
</div>

<style>
.cleversay-settings {
    max-width: 900px;
}

.settings-tabs {
    margin-top: 20px;
}

.tab-content {
    display: none;
    background: #fff;
    border: 1px solid #c3c4c7;
    border-top: none;
    padding: 20px;
}

/* AI Settings tab uses section-cards which have their own padding */
#tab-ai-settings {
    background: #f0f0f1;
    padding: 15px;
}

#tab-ai-settings .section-card {
    margin-bottom: 15px;
}

#tab-ai-settings .section-card:last-child {
    margin-bottom: 0;
}

.tab-content.active {
    display: block;
}

.nav-tab-wrapper {
    border-bottom: 1px solid #c3c4c7;
}

.nav-tab {
    cursor: pointer;
}

.color-preview {
    display: inline-block;
    width: 30px;
    height: 30px;
    vertical-align: middle;
    margin-left: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.widget-preview {
    margin-top: 30px;
    padding: 20px;
    background: #f6f7f7;
    border-radius: 4px;
}

.preview-widget {
    width: 320px;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
}

.preview-header {
    background: var(--cs-primary, #2271b1);
    color: #fff;
    padding: 15px;
    font-weight: 500;
}

.preview-body {
    background: #fff;
    padding: 15px;
    min-height: 100px;
}

.preview-message {
    background: #f0f0f1;
    padding: 10px 15px;
    border-radius: 12px;
    max-width: 80%;
    font-size: 14px;
}

.preview-input {
    border-top: 1px solid #ddd;
    padding: 10px;
    background: #fff;
}

.preview-input input {
    width: 100%;
    border: 1px solid #ddd;
    padding: 10px;
    border-radius: 20px;
}

.description.warning {
    color: #d63638;
}
</style>

<script>
jQuery(function($) {
    // Tab switching
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        var tab = $(this).data('tab');
        
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        $('.tab-content').removeClass('active');
        $('#tab-' + tab).addClass('active');
        
        // Update URL hash
        history.replaceState(null, null, '#' + tab);
    });
    
    // Check URL hash on load
    if (window.location.hash) {
        var tab = window.location.hash.substring(1);
        $('.nav-tab[data-tab="' + tab + '"]').click();
    }
    
    // Update preview colors
    $('input[type="color"]').on('input', function() {
        var id = $(this).attr('id');
        $(this).next('.color-preview').css('background', this.value);
        
        if (id === 'primary_color') {
            $('.preview-header').css('background', this.value);
        }
    });
});
</script>
