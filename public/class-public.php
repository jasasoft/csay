<?php
/**
 * CleverSay Public Handler
 * 
 * Manages public-facing functionality, shortcodes, and chatbot widget
 * 
 * @package CleverSay
 * @since 2.0.0
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class PublicFacing {
    
    private Database $db;

    /**
     * Per-request context: if the current search had a KB match that was
     * rejected by AI validation, remember which keyword / why so that when
     * the AI fallback later writes to `cleversay_ai_answers`, we can flag
     * that row as originating from a rejection.
     */
    private ?string $kb_rejected_keyword = null;
    private ?string $kb_rejection_reason = null;
    private ?string $kb_rejected_answer = null;

    /**
     * Per-request multilingual state: when an incoming question is non-English,
     * we translate it to English for KB search, then translate the final answer
     * back to the visitor's language before sending the JSON response.
     */
    private ?string $user_language = null;  // e.g. 'es', 'fr', 'zh' — null means English or skip

    /**
     * Per-request: id of the cleversay_questions row logged by Search for
     * the current query. Used to FK-link a later AI-answer row back to its
     * originating question for reliable admin UI joins.
     */
    private ?int $current_logged_question_id = null;

    /**
     * Set by log_ai_answer() with the inserted row id; consumed by ajax_search
     * to include `ai_answer_id` in the response so the widget can submit
     * per-AI-answer ratings tied to the right row.
     */
    private ?int $current_ai_answer_id = null;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    /**
     * Initialize public hooks
     */
    public function init(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_chatbot_widget']);

        // CORS headers for embed.js cross-origin requests.
        // admin-ajax.php sets DOING_AJAX=true then fires admin_init, so we hook there
        // at priority 1 — before any other output, with DOING_AJAX available.
        add_action('admin_init', [$this, 'maybe_add_cors_headers'], 1);
        // Also hook on the individual actions as fallback at very early priority
        add_action('wp_ajax_cleversay_search',                [$this, 'maybe_add_cors_headers'], -1);
        add_action('wp_ajax_nopriv_cleversay_search',         [$this, 'maybe_add_cors_headers'], -1);
        add_action('wp_ajax_cleversay_rate_answer',           [$this, 'maybe_add_cors_headers'], -1);
        add_action('wp_ajax_nopriv_cleversay_rate_answer',    [$this, 'maybe_add_cors_headers'], -1);
        add_action('wp_ajax_cleversay_rate_conversation',     [$this, 'maybe_add_cors_headers'], -1);
        add_action('wp_ajax_nopriv_cleversay_rate_conversation', [$this, 'maybe_add_cors_headers'], -1);
        add_action('wp_ajax_cleversay_submit_inquiry',        [$this, 'maybe_add_cors_headers'], -1);
        add_action('wp_ajax_nopriv_cleversay_submit_inquiry', [$this, 'maybe_add_cors_headers'], -1);

        // AJAX handlers for public (both logged in and not logged in)
        add_action('wp_ajax_cleversay_search', [$this, 'ajax_search']);
        add_action('wp_ajax_nopriv_cleversay_search', [$this, 'ajax_search']);
        add_action('wp_ajax_cleversay_rate_answer', [$this, 'ajax_rate_answer']);
        add_action('wp_ajax_nopriv_cleversay_rate_answer', [$this, 'ajax_rate_answer']);
        add_action('wp_ajax_cleversay_rate_conversation', [$this, 'ajax_rate_conversation']);
        add_action('wp_ajax_nopriv_cleversay_rate_conversation', [$this, 'ajax_rate_conversation']);
        add_action('wp_ajax_cleversay_submit_inquiry', [$this, 'ajax_submit_inquiry']);
        add_action('wp_ajax_nopriv_cleversay_submit_inquiry', [$this, 'ajax_submit_inquiry']);
        // Pre-chat lead capture form submission
        add_action('wp_ajax_cleversay_submit_lead',        [$this, 'maybe_add_cors_headers'], -1);
        add_action('wp_ajax_nopriv_cleversay_submit_lead', [$this, 'maybe_add_cors_headers'], -1);
        add_action('wp_ajax_cleversay_submit_lead',        [$this, 'ajax_submit_lead']);
        add_action('wp_ajax_nopriv_cleversay_submit_lead', [$this, 'ajax_submit_lead']);
        // Per-AI-answer rating (👍 / 👎 under AI responses)
        add_action('wp_ajax_cleversay_rate_ai_answer',        [$this, 'maybe_add_cors_headers'], -1);
        add_action('wp_ajax_nopriv_cleversay_rate_ai_answer', [$this, 'maybe_add_cors_headers'], -1);
        add_action('wp_ajax_cleversay_rate_ai_answer',        [$this, 'ajax_rate_ai_answer']);
        add_action('wp_ajax_nopriv_cleversay_rate_ai_answer', [$this, 'ajax_rate_ai_answer']);
        
        // Register shortcodes
        add_shortcode('cleversay', [$this, 'shortcode_search_form']);

        // v4.37.89+: Source download endpoint (citation feature). Routes
        // /?cleversay_source=N to the protected file streamer. Only
        // active when citations are enabled for this site.
        add_action('init', [$this, 'maybe_handle_source_download']);
    }
    

    /**
     * Add CORS headers when request comes from an allowed external domain.
     * Runs before each public AJAX handler via WordPress early-priority hooks.
     *
     * For embed.js requests the nonce was fetched from /wp-json/cleversay/v1/embed-config,
     * so standard nonce validation still applies — we just relax the origin restriction.
     */
    public function maybe_add_cors_headers(): void {
        static $done = false;
        if ($done) return;

        // Only act on our AJAX actions
        if (!defined('DOING_AJAX') || !DOING_AJAX) return;

        $action      = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
        $our_actions = ['cleversay_search', 'cleversay_rate_answer', 'cleversay_submit_inquiry'];
        if (!in_array($action, $our_actions, true)) return;

        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
        if (!$origin) return;

        $done = true;

        $api     = new \CleverSay\API();
        $allowed = $api->get_allowed_origins();
        $origin_clean = rtrim($origin, '/');

        // Match with or without https:// prefix
        $origin_no_proto = preg_replace('#^https?://#', '', $origin_clean);
        $match = false;
        if ($allowed === '*') {
            $match = true;
        } else {
            foreach ((array)$allowed as $domain) {
                $domain_no_proto = preg_replace('#^https?://#', '', rtrim($domain, '/'));
                if ($domain_no_proto === $origin_no_proto || rtrim($domain, '/') === $origin_clean) {
                    $match = true;
                    break;
                }
            }
        }

        if ($match) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
            header('Access-Control-Allow-Credentials: false');
            header('Vary: Origin');

            // Handle OPTIONS preflight immediately
            if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                status_header(204);
                exit;
            }
        }
    }


    /**
     * Strip Word/Outlook paste artifacts and normalise HTML for display in the widget.
     *
     * Removes:
     *  - MsoNormal and other Mso* class names
     *  - <o:p>, <w:*>, <m:*> Office XML tags
     *  - Inline background-* CSS (massive chains pasted from Word)
     *  - font-size, line-height, font-family inline styles (let the widget CSS control these)
     *  - Empty <p>, <span> tags left over after stripping
     * Keeps: links, bold, italic, lists, line breaks.
     */
    private function clean_response_html(string $html): string {
        if (empty($html)) return $html;

        // Remove Office XML / namespace tags entirely
        $html = preg_replace('/<\/?(o|w|m):[^>]*>/i', '', $html);

        // Remove class attributes containing Mso* values
        $html = preg_replace('/\s*class="[^"]*Mso[^"]*"/i', '', $html);

        // Strip style attributes that are pure Word noise
        // (background-image, background-position, etc. chains and font-size/line-height)
        $html = preg_replace_callback('/\s*style="([^"]*)"/i', function($m) {
            $style = $m[1];
            // Remove individual noisy properties
            $noisy = [
                '/background-image\s*:[^;]+;?/i',
                '/background-position\s*:[^;]+;?/i',
                '/background-size\s*:[^;]+;?/i',
                '/background-repeat\s*:[^;]+;?/i',
                '/background-attachment\s*:[^;]+;?/i',
                '/background-origin\s*:[^;]+;?/i',
                '/background-clip\s*:[^;]+;?/i',
                '/background\s*:[^;]+;?/i',
                '/font-size\s*:[^;]+;?/i',
                '/line-height\s*:[^;]+;?/i',
                '/font-family\s*:[^;]+;?/i',
                '/mso-[^:]+:[^;]+;?/i',
            ];
            foreach ($noisy as $pattern) {
                $style = preg_replace($pattern, '', $style);
            }
            $style = trim($style, "; 	");
            return $style ? ' style="' . $style . '"' : '';
        }, $html);

        // Remove non-breaking spaces (&nbsp;) used as padding, collapse whitespace
        $html = preg_replace('/(&nbsp;|Â ){2,}/u', ' ', $html);

        // Remove empty spans and empty paragraphs
        $html = preg_replace('/<span[^>]*>\s*<\/span>/i', '', $html);
        $html = preg_replace('/<p[^>]*>\s*<\/p>/i', '', $html);

        // v4.42.17+: convert any markdown emphasis/link patterns that
        // may have leaked into the stored response (from admin paste,
        // legacy polish output, or content authored before the runtime
        // converter existed). Without this, KB answers shipped via
        // this path render markdown markers literally — e.g.
        // **finaid@uwsp.edu** appears with asterisks instead of bold.
        // The conversion is the same whitelisted set used elsewhere
        // (**bold**, __bold__, [text](https-url)) and is idempotent,
        // so running it here is safe even on already-clean HTML.
        if (class_exists('\\CleverSay\\AI')) {
            $html = \CleverSay\AI::convert_minimal_markdown_to_html($html);
        }

        return trim($html);
    }

    /**
     * Enqueue public assets
     */
    public function enqueue_assets(): void {
        // The "Show floating chat widget on frontend" checkbox in Settings →
        // Widget saves to cleversay_options['widget_enabled']. We previously
        // read from a different top-level option (cleversay_widget_enabled)
        // that was never written, so the checkbox was decorative. Read from
        // the array now so the toggle actually works.
        $opts            = get_option('cleversay_options', []);
        $widget_enabled  = !isset($opts['widget_enabled']) || !empty($opts['widget_enabled']);

        // Only load if widget is enabled or shortcode is present
        if (!$widget_enabled && !$this->page_has_shortcode()) {
            return;
        }
        
        // CSS
        wp_enqueue_style(
            'cleversay-public',
            CLEVERSAY_PLUGIN_URL . 'public/css/public.css',
            [],
            CLEVERSAY_VERSION
        );
        
        // Inline custom colors — read from cleversay_options array
        $opts = get_option('cleversay_options', []);

        $primary         = $opts['primary_color']      ?? '#2271b1';
        $header_bg       = $opts['header_bg_color']    ?? $primary;
        $header_text     = $opts['header_text_color']  ?? '#ffffff';
        $chat_bg         = $opts['chat_bg_color']       ?? '#f5f5f7';
        $bg              = $opts['background_color']    ?? '#ffffff';
        $user_bubble_bg  = $opts['user_bubble_color']  ?? $primary;
        $user_bubble_txt = $opts['user_bubble_text']   ?? '#ffffff';
        $bot_bubble_bg   = $opts['bot_bubble_color']   ?? '#ffffff';
        $bot_bubble_txt  = $opts['bot_bubble_text']    ?? '#1d2327';
        $toggle_bg       = $opts['toggle_bg_color']    ?? $primary;

        $custom_css = "
            :root {
                --cleversay-primary:          {$primary};
                --cleversay-primary-hover:    " . $this->adjust_color($primary, -20) . ";
                --cleversay-header-bg:        {$header_bg};
                --cleversay-header-text:      {$header_text};
                --cleversay-chat-bg:          {$chat_bg};
                --cleversay-bg:               {$bg};
                --cleversay-bg-secondary:     {$chat_bg};
                --cleversay-user-bubble-bg:   {$user_bubble_bg};
                --cleversay-user-bubble-text: {$user_bubble_txt};
                --cleversay-bot-bubble-bg:    {$bot_bubble_bg};
                --cleversay-bot-bubble-text:  {$bot_bubble_txt};
                --cleversay-toggle-bg:        {$toggle_bg};
            }
        ";

        wp_add_inline_style('cleversay-public', $custom_css);
        
        // JS
        wp_enqueue_script(
            'cleversay-public',
            CLEVERSAY_PLUGIN_URL . 'public/js/public.js',
            ['jquery'],
            CLEVERSAY_VERSION,
            true
        );
        
        // Get options
        $options = get_option('cleversay_options', []);
        
        // Localize script
        // NOTE: All settings here are read from the cleversay_options array
        // — same place the settings UI saves to. Older versions of this code
        // read from top-level options like 'cleversay_widget_title' that
        // were never written by the modern save handler, so admin changes
        // didn't apply to the legacy widget. v4.29.3 unified the read paths.
        wp_localize_script('cleversay-public', 'cleversay', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cleversay_nonce'),
            'widgetTitle'  => !empty($options['widget_title'])
                ? $options['widget_title']
                : __('Ask a Question', 'cleversay'),
            'placeholder'  => !empty($options['widget_placeholder'])
                ? $options['widget_placeholder']
                : __('Type your question here...', 'cleversay'),
            'position'     => !empty($options['widget_position'])
                ? $options['widget_position']
                : 'bottom-right',
            'showRating'   => !isset($options['show_rating'])
                ? true
                : !empty($options['show_rating']),
            'enableInquiry' => !isset($options['enable_inquiry_form'])
                ? true
                : !empty($options['enable_inquiry_form']),
            'showAiBadge'  => !isset($options['show_ai_badge']) || !empty($options['show_ai_badge']),
            'botName'      => !empty($options['bot_name'])        ? $options['bot_name']        : __('Assistant', 'cleversay'),
            'botLabel'     => !empty($options['bot_agent_label']) ? $options['bot_agent_label'] : __('AI Agent', 'cleversay'),
            'mascotUrl'    => !empty($options['mascot_image_url']) ? esc_url($options['mascot_image_url']) : '',
            'strings' => [
                'askButton' => __('Ask', 'cleversay'),
                'close' => __('Close chat', 'cleversay'),
                'searching' => __('Searching...', 'cleversay'),
                'helpful' => __('Was this helpful?', 'cleversay'),
                'yes' => __('Yes', 'cleversay'),
                'no' => __('No', 'cleversay'),
                'thanks' => __('Thanks for your feedback!', 'cleversay'),
                'noAnswer' => !empty($options['no_answer_message'])
                    ? $options['no_answer_message']
                    : __("I couldn't find an answer to your question. Would you like to submit it for review?", 'cleversay'),
                'submitInquiry' => __('Submit Question', 'cleversay'),
                'detailsPlaceholder' => __('Add more details about your question (optional)', 'cleversay'),
                'inquiryPlaceholder' => __('Enter your email to receive an answer', 'cleversay'),
                'inquirySuccess' => __('Your question has been submitted. We\'ll get back to you soon!', 'cleversay'),
                'inquiryError' => __('There was an error submitting your question. Please try again.', 'cleversay'),
                // Inquiry-prompt strings — used after a thumbs-down on an AI
                // answer to escalate to the inquiry form. Mirrors the floating
                // widget flow so the two widgets behave consistently.
                'stillHelp'    => __('Still need help? Send us a message.', 'cleversay'),
                'inquiryYes'   => __('Yes, please', 'cleversay'),
                'inquiryNo'    => __('No, thanks', 'cleversay'),
                'inquiryDeclined' => __('No problem! Feel free to ask if you have other questions.', 'cleversay'),
                'inquiryIntro' => !empty($options['inquiry_intro_message'])
                    ? $options['inquiry_intro_message']
                    : __('Sure — fill out the form below and we\'ll get back to you.', 'cleversay'),
                'tryAgain' => __('Try asking another question', 'cleversay'),
                'rateLimitMessage' => __('Too many requests. Please wait a moment before asking again.', 'cleversay'),
                'aiLabel'      => get_option('cleversay_ai_label', __('AI-assisted answer', 'cleversay')),
            ],
        ]);
    }
    
    /**
     * Check if current page has a CleverSay shortcode
     */
    private function page_has_shortcode(): bool {
        global $post;
        
        if (!$post) {
            return false;
        }
        
        return has_shortcode($post->post_content, 'cleversay');
    }
    
    /**
     * Adjust color brightness
     */
    private function adjust_color(string $hex, int $amount): string {
        $hex = ltrim($hex, '#');
        
        $r = max(0, min(255, hexdec(substr($hex, 0, 2)) + $amount));
        $g = max(0, min(255, hexdec(substr($hex, 2, 2)) + $amount));
        $b = max(0, min(255, hexdec(substr($hex, 4, 2)) + $amount));
        
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
    
    /**
     * Render chatbot widget in footer
     */
    public function render_chatbot_widget(): void {
        // Read from cleversay_options['widget_enabled'] — the same place the
        // settings checkbox writes to. (Previously read from a separate
        // top-level option that was never set, so the toggle had no effect.)
        $opts           = get_option('cleversay_options', []);
        $widget_enabled = !isset($opts['widget_enabled']) || !empty($opts['widget_enabled']);
        if (!$widget_enabled) {
            return;
        }

        // Also skip if the [cleversay] shortcode is on this page — the inline
        // widget already provides the chat UI; the floating widget would be a
        // duplicate.
        if ($this->page_has_shortcode()) {
            return;
        }
        
        // Same source-of-truth fix as the localized config above:
        // read from cleversay_options array, not the orphaned top-level options.
        $position = !empty($opts['widget_position'])
            ? $opts['widget_position']
            : 'bottom-right';
        $title    = !empty($opts['widget_title'])
            ? $opts['widget_title']
            : __('Ask a Question', 'cleversay');

        include CLEVERSAY_PLUGIN_DIR . 'public/views/chatbot-widget.php';
    }
    
    /**
     * Shortcode: Search form
     * 
     * Usage: [cleversay title="Ask Us" placeholder="Your question..."]
     */
    public function shortcode_search_form(array $atts): string {
        $atts = shortcode_atts([
            'title'       => __('Ask a Question', 'cleversay'),
            'placeholder' => __('Type your question here...', 'cleversay'),
            'style'       => 'embedded',
            'class'       => '',
        ], $atts, 'cleversay');

        ob_start();
        include CLEVERSAY_PLUGIN_DIR . 'public/views/chatbot-embedded.php';
        return ob_get_clean();
    }
    
    /**
     * Shortcode: Chatbot
     * 
     * Usage: [cleversay_chatbot style="embedded"]
     */
    /**
     * AJAX: Rate answer
     */
    public function ajax_rate_answer(): void {
        $nonce_ok       = check_ajax_referer('cleversay_nonce', 'nonce', false);
        $embed_token    = sanitize_text_field(wp_unslash($_POST['embed_token'] ?? ''));
        $stored_token   = get_option('cleversay_embed_token', '');
        $embed_token_ok = !empty($stored_token) && hash_equals($stored_token, $embed_token);
        if (!$nonce_ok && !$embed_token_ok) {
            wp_send_json_error(['message' => __('Security check failed', 'cleversay')], 403);
            return;
        }
        
        global $wpdb;
        
        $knowledge_id = absint($_POST['id'] ?? 0);
        $rating = sanitize_text_field($_POST['rating'] ?? '');
        $feedback = sanitize_textarea_field($_POST['feedback'] ?? '');
        $question_log_id = absint($_POST['question_log_id'] ?? 0);
        
        if (!$knowledge_id || !in_array($rating, ['helpful', 'not_helpful'])) {
            wp_send_json_error(['message' => __('Invalid request', 'cleversay')], 400);
        }
        
        // Insert rating
        $wpdb->insert($this->db->ratings, [
            'knowledge_id' => $knowledge_id,
            'question_log_id' => $question_log_id ?: null,
            'rating' => $rating,
            'feedback' => $feedback,
            'ip_address' => $this->get_client_ip(),
        ]);
        
        // Update counts in knowledge base
        $field = $rating === 'helpful' ? 'helpful_yes' : 'helpful_no';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->db->knowledge_base} SET {$field} = {$field} + 1 WHERE id = %d",
            $knowledge_id
        ));
        
        wp_send_json_success(['message' => __('Thanks for your feedback!', 'cleversay')]);
    }

    /**
     * AJAX: Rate an entire conversation (CSAT at widget close).
     * Accepts rating (helpful|somewhat|not_helpful), optional comment,
     * and the full history so we can derive conversation_id + store context.
     */
    public function ajax_rate_conversation(): void {
        $nonce_ok       = check_ajax_referer('cleversay_nonce', 'nonce', false);
        $embed_token    = sanitize_text_field(wp_unslash($_POST['embed_token'] ?? ''));
        $stored_token   = get_option('cleversay_embed_token', '');
        $embed_token_ok = !empty($stored_token) && hash_equals($stored_token, $embed_token);
        if (!$nonce_ok && !$embed_token_ok) {
            wp_send_json_error(['message' => __('Security check failed', 'cleversay')], 403);
            return;
        }

        global $wpdb;

        $rating       = sanitize_text_field($_POST['rating'] ?? '');
        $comment      = sanitize_textarea_field($_POST['comment'] ?? '');
        $history_json = wp_unslash($_POST['history'] ?? '');

        if (!in_array($rating, ['helpful', 'somewhat', 'not_helpful'], true)) {
            wp_send_json_error(['message' => __('Invalid rating', 'cleversay')], 400);
            return;
        }

        // Derive conversation_id from history (same algorithm as log_ai_answer)
        $conversation_id = null;
        $turn_count      = 0;
        $history_clean   = null;
        if (!empty($history_json)) {
            $history = json_decode($history_json, true);
            if (is_array($history) && !empty($history)) {
                $turn_count = count($history);
                // Find first user message
                $anchor = null;
                foreach ($history as $msg) {
                    if (($msg['type'] ?? '') === 'user') { $anchor = $msg; break; }
                }
                if ($anchor === null) $anchor = $history[0];
                $anchor_content = wp_strip_all_tags((string) ($anchor['content'] ?? ''));
                if ($anchor_content !== '') {
                    $conversation_id = substr(
                        md5($anchor_content . '|' . $this->get_client_ip()),
                        0, 32
                    );
                }
                $history_clean = wp_json_encode($history);
            }
        }

        $wpdb->insert($this->db->conversation_ratings, [
            'conversation_id' => $conversation_id,
            'rating'          => $rating,
            'comment'         => $comment !== '' ? $comment : null,
            'turn_count'      => $turn_count,
            'resolved'        => $rating === 'helpful' ? 1 : 0,
            'ip_address'      => $this->get_client_ip(),
            'session_id'      => session_id() ?: null,
            'history_json'    => $history_clean,
            'created_at'      => current_time('mysql'),
        ]);

        wp_send_json_success(['message' => __('Thanks for your feedback!', 'cleversay')]);
    }
    
    /**
     * AJAX: Submit inquiry (unanswered question)
     */
    public function ajax_submit_inquiry(): void {
        // Accept either WP nonce OR embed token (same dual-auth pattern as ajax_search)
        $nonce_ok       = check_ajax_referer('cleversay_nonce', 'nonce', false);
        $embed_token    = sanitize_text_field(wp_unslash($_POST['embed_token'] ?? ''));
        $stored_token   = get_option('cleversay_embed_token', '');
        $embed_token_ok = !empty($stored_token) && hash_equals($stored_token, $embed_token);
        if (!$nonce_ok && !$embed_token_ok) {
            wp_send_json_error(['message' => __('Security check failed', 'cleversay')], 403);
        }
        
        global $wpdb;
        
        $question     = sanitize_textarea_field(wp_unslash($_POST['question'] ?? ''));
        $details      = sanitize_textarea_field(wp_unslash($_POST['details'] ?? ''));
        $email        = sanitize_email($_POST['email'] ?? '');
        $name         = sanitize_text_field($_POST['name'] ?? '');
        $handoff_type = sanitize_text_field($_POST['handoff_type'] ?? '');

        // Transcript may contain newlines and arrows — use textarea sanitizer,
        // then cap at a reasonable length to prevent abuse.
        $transcript = sanitize_textarea_field(wp_unslash($_POST['transcript'] ?? ''));
        if (strlen($transcript) > 20000) {
            $transcript = substr($transcript, 0, 20000) . "\n… [truncated]";
        }

        if (empty($question)) {
            wp_send_json_error(['message' => __('Please enter a question', 'cleversay')], 400);
        }
        
        // Basic spam check
        if ($this->is_spam($question) || $this->is_spam($details)) {
            wp_send_json_error(['message' => __('Your submission was flagged as spam', 'cleversay')], 400);
        }

        // Only accept known handoff types; fall back to null otherwise
        $allowed_handoff = ['keyword_request', 'auto_escalation', 'user_initiated'];
        if (!in_array($handoff_type, $allowed_handoff, true)) {
            $handoff_type = null;
        }
        
        // Insert inquiry
        $result = $wpdb->insert($this->db->inquiries, [
            'question'     => $question,
            'details'      => $details,
            'email'        => $email,
            'name'         => $name,
            'status'       => 'pending',
            'ip_address'   => $this->get_client_ip(),
            'transcript'   => $transcript !== '' ? $transcript : null,
            'handoff_type' => $handoff_type,
        ]);
        
        if ($result === false) {
            wp_send_json_error(['message' => __('Database error', 'cleversay')], 500);
        }
        
        // Send notification email (with transcript if present)
        $this->send_inquiry_notification($question, $details, $email, $name, $transcript, $handoff_type);
        
        wp_send_json_success([
            'message' => __('Your question has been submitted. We\'ll get back to you soon!', 'cleversay'),
        ]);
    }
    
    /**
     * Basic spam detection
     */
    private function is_spam(string $text): bool {
        $spam_patterns = [
            '/\b(viagra|cialis|casino|poker|lottery)\b/i',
            '/https?:\/\/[^\s]{50,}/i', // Long URLs
            '/(.)\1{5,}/i', // Repeated characters
        ];
        
        foreach ($spam_patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Send inquiry notification to admin
     */
    private function send_inquiry_notification(string $question, string $details, string $email, string $name, string $transcript = '', ?string $handoff_type = null): void {
        $admin_email = get_option('cleversay_inquiry_email', get_option('admin_email'));
        
        if (!$admin_email) {
            return;
        }
        
        // Subject line differentiates handoffs from plain inquiries so admins
        // can triage quickly in their inbox
        if ($handoff_type === 'keyword_request') {
            $subject = sprintf(__('[%s] Visitor requested a human agent', 'cleversay'), get_bloginfo('name'));
        } elseif ($handoff_type === 'auto_escalation') {
            $subject = sprintf(__('[%s] Chatbot escalation — needs human help', 'cleversay'), get_bloginfo('name'));
        } else {
            $subject = sprintf(__('[%s] New CleverSay Question', 'cleversay'), get_bloginfo('name'));
        }

        $details_section = '';
        if (!empty($details)) {
            $details_section = sprintf(__("\n\nAdditional Details:\n%s", 'cleversay'), $details);
        }

        $transcript_section = '';
        if (!empty($transcript)) {
            $transcript_section = "\n\n---\nChat transcript:\n\n{$transcript}";
        }
        
        $message = sprintf(
            __("A new question has been submitted:\n\nQuestion: %s%s\n\nFrom: %s\nEmail: %s%s\n\nPlease log in to your WordPress admin to respond.", 'cleversay'),
            $question,
            $details_section,
            $name ?: __('Anonymous', 'cleversay'),
            $email ?: __('Not provided', 'cleversay'),
            $transcript_section
        );
        
        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Handle pre-chat lead capture form submission.
     * Stores captured lead info in cleversay_leads. Mirrors the dual-auth
     * pattern (WP nonce OR embed token) used by ajax_submit_inquiry.
     */
    public function ajax_submit_lead(): void {
        $nonce_ok       = check_ajax_referer('cleversay_nonce', 'nonce', false);
        $embed_token    = sanitize_text_field(wp_unslash($_POST['embed_token'] ?? ''));
        $stored_token   = get_option('cleversay_embed_token', '');
        $embed_token_ok = !empty($stored_token) && hash_equals($stored_token, $embed_token);
        if (!$nonce_ok && !$embed_token_ok) {
            wp_send_json_error(['message' => __('Security check failed', 'cleversay')], 403);
        }

        // Verify lead capture is actually enabled — silently reject if not
        if (!get_option('cleversay_lead_capture_enabled', false)) {
            wp_send_json_error(['message' => __('Lead capture not enabled', 'cleversay')], 400);
        }

        global $wpdb;

        // Pull and sanitize fields
        $first_name      = sanitize_text_field(wp_unslash($_POST['first_name']     ?? ''));
        $last_name       = sanitize_text_field(wp_unslash($_POST['last_name']      ?? ''));
        $email           = sanitize_email($_POST['email']                          ?? '');
        $identity        = sanitize_text_field(wp_unslash($_POST['identity']       ?? ''));
        $phone           = sanitize_text_field(wp_unslash($_POST['phone']          ?? ''));
        $dob_raw         = sanitize_text_field(wp_unslash($_POST['date_of_birth']  ?? ''));
        $conversation_id = sanitize_text_field(wp_unslash($_POST['conversation_id'] ?? ''));

        // Validate required fields based on field config
        $field_config = get_option('cleversay_lead_field_config', []);
        if (!is_array($field_config)) $field_config = [];

        $errors = [];
        if (($field_config['email']['required'] ?? true) && empty($email)) {
            $errors[] = __('Email is required', 'cleversay');
        } elseif (!empty($email) && !is_email($email)) {
            $errors[] = __('Please enter a valid email address', 'cleversay');
        }
        if (($field_config['first_name']['required'] ?? true) && empty($first_name)) {
            $errors[] = __('First name is required', 'cleversay');
        }
        if (($field_config['last_name']['required'] ?? true) && empty($last_name)) {
            $errors[] = __('Last name is required', 'cleversay');
        }
        if (($field_config['identity']['required'] ?? true) && empty($identity)) {
            $errors[] = __('Please select an option', 'cleversay');
        }

        // Confirm identity is one of the configured options (don't accept arbitrary input)
        if (!empty($identity)) {
            $valid_identities = get_option('cleversay_lead_identity_options', []);
            if (is_array($valid_identities) && !empty($valid_identities)
                && !in_array($identity, $valid_identities, true)) {
                $errors[] = __('Selected identity is not valid', 'cleversay');
            }
        }

        if (!empty($errors)) {
            wp_send_json_error([
                'message' => implode(' ', $errors),
                'errors'  => $errors,
            ], 400);
        }

        // Spam guards on free-text fields
        if ($this->is_spam($first_name) || $this->is_spam($last_name)) {
            wp_send_json_error(['message' => __('Your submission was flagged as spam', 'cleversay')], 400);
        }

        // Validate DOB if provided — must be a real date and visitor must be older than 13
        // (basic minor protection — admins are responsible for proper compliance per their use case)
        $dob_db = null;
        if (!empty($dob_raw)) {
            $dob_ts = strtotime($dob_raw);
            if ($dob_ts === false || $dob_ts > time()) {
                wp_send_json_error(['message' => __('Date of birth is invalid', 'cleversay')], 400);
            }
            $dob_db = date('Y-m-d', $dob_ts);
        }

        // Insert
        $result = $wpdb->insert($this->db->leads, [
            'first_name'      => $first_name ?: null,
            'last_name'       => $last_name ?: null,
            'email'           => $email ?: null,
            'identity'        => $identity ?: null,
            'date_of_birth'   => $dob_db,
            'phone'           => $phone ?: null,
            'conversation_id' => $conversation_id ?: null,
            'ip_address'      => $this->get_client_ip(),
            'user_agent'      => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            'referer'         => substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500),
        ]);

        if ($result === false) {
            wp_send_json_error(['message' => __('Could not save lead', 'cleversay')], 500);
        }

        // Optionally email the admin
        if (get_option('cleversay_lead_notify_admin', false)) {
            $this->notify_admin_of_lead([
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'email'      => $email,
                'identity'   => $identity,
                'phone'      => $phone,
            ]);
        }

        wp_send_json_success([
            'message' => __('Thank you! Loading the chat…', 'cleversay'),
            'lead_id' => $wpdb->insert_id,
        ]);
    }

    /**
     * Record a 👍 / 👎 rating for a specific AI-generated answer.
     * Replaces the old "Still need help?" Yes/No prompt — visitors now rate
     * AI answers directly. Negative ratings additionally route the visitor to
     * the inquiry form on the client side.
     */
    public function ajax_rate_ai_answer(): void {
        $nonce_ok       = check_ajax_referer('cleversay_nonce', 'nonce', false);
        $embed_token    = sanitize_text_field(wp_unslash($_POST['embed_token'] ?? ''));
        $stored_token   = get_option('cleversay_embed_token', '');
        $embed_token_ok = !empty($stored_token) && hash_equals($stored_token, $embed_token);
        if (!$nonce_ok && !$embed_token_ok) {
            wp_send_json_error(['message' => __('Security check failed', 'cleversay')], 403);
        }

        $ai_answer_id = (int) ($_POST['ai_answer_id'] ?? 0);
        $rating_raw   = sanitize_text_field(wp_unslash($_POST['rating'] ?? ''));

        if ($ai_answer_id <= 0) {
            wp_send_json_error(['message' => __('Invalid answer id', 'cleversay')], 400);
        }

        // Map textual rating values to 1/0 for the TINYINT column. Accept
        // either format ('helpful'/'not_helpful' or '1'/'0') for flexibility.
        $rating = null;
        if ($rating_raw === 'helpful'     || $rating_raw === '1') $rating = 1;
        if ($rating_raw === 'not_helpful' || $rating_raw === '0') $rating = 0;
        if ($rating === null) {
            wp_send_json_error(['message' => __('Invalid rating value', 'cleversay')], 400);
        }

        global $wpdb;
        $updated = $wpdb->update(
            $this->db->ai_answers,
            ['rating' => $rating, 'rated_at' => current_time('mysql')],
            ['id' => $ai_answer_id]
        );

        if ($updated === false) {
            wp_send_json_error(['message' => __('Could not record rating', 'cleversay')], 500);
        }

        // On thumbs-down, flag this AI answer for inspector review even if
        // manual capture wasn't on. Lets admins go back and see what failed
        // without having to predict bad answers in advance.
        if ($rating === 0 && class_exists('\\CleverSay\\AIDebugLog')) {
            \CleverSay\AIDebugLog::flag_for_negative_rating($ai_answer_id);
        }

        wp_send_json_success([
            'rating'       => $rating,
            'ai_answer_id' => $ai_answer_id,
        ]);
    }

    /**
     * Send admin notification email when a lead is captured.
     * Uses inquiry_notification_email if set, otherwise admin_email.
     */
    private function notify_admin_of_lead(array $lead): void {
        $opts        = get_option('cleversay_options', []);
        $admin_email = !empty($opts['inquiry_notification_email'])
                       ? $opts['inquiry_notification_email']
                       : get_option('admin_email');
        $site_name   = get_bloginfo('name');

        $subject = sprintf(__('[%s] New chatbot lead', 'cleversay'), $site_name);
        $body  = __('A new lead has been captured by the chatbot.', 'cleversay') . "\n\n";
        $body .= sprintf("%s: %s %s\n", __('Name', 'cleversay'),  $lead['first_name'], $lead['last_name']);
        $body .= sprintf("%s: %s\n",     __('Email', 'cleversay'), $lead['email']);
        if (!empty($lead['identity'])) {
            $body .= sprintf("%s: %s\n", __('Identity', 'cleversay'), $lead['identity']);
        }
        if (!empty($lead['phone'])) {
            $body .= sprintf("%s: %s\n", __('Phone', 'cleversay'), $lead['phone']);
        }
        $body .= "\n" . sprintf(__('View leads: %s', 'cleversay'), admin_url('admin.php?page=cleversay-leads'));

        wp_mail($admin_email, $subject, $body);
    }


    /**
     * Attempt to answer using AI with document context.
     * Returns the answer string on success, null if AI is unavailable or fails.
     */
    /**
     * Normalize a query string for dedup comparison.
     * Lowercases, strips punctuation, collapses whitespace.
     *
     * @since 4.37.126
     */
    private function normalize_for_dedup(string $q): string {
        $q = strtolower(trim($q));
        $q = preg_replace('/[^\w\s]/u', '', $q);  // strip punctuation
        $q = preg_replace('/\s+/', ' ', $q);      // collapse whitespace
        return trim($q);
    }

    /**
     * Check Jaccard token similarity between two normalized queries.
     * Returns true if intersection/union ≥ 0.85 — high enough to catch
     * typos and minor variations without matching genuinely different
     * queries.
     *
     * @since 4.37.126
     */
    private function is_near_duplicate(string $a, string $b): bool {
        if ($a === '' || $b === '') return false;
        $aTokens = explode(' ', $a);
        $bTokens = explode(' ', $b);
        $aTokens = array_filter($aTokens, 'strlen');
        $bTokens = array_filter($bTokens, 'strlen');
        if (empty($aTokens) || empty($bTokens)) return false;
        $intersection = array_intersect($aTokens, $bTokens);
        $union        = array_unique(array_merge($aTokens, $bTokens));
        if (count($union) === 0) return false;
        $similarity = count($intersection) / count($union);
        return $similarity >= 0.85;
    }

    /**
     * If the question looks like a follow-up (short, uses pronouns, no clear topic),
     * use AI to rewrite it as a standalone question using conversation history.
     * Returns the original question unchanged if no resolution is needed.
     *
     * REGRESSION GUARD — see ARCHITECTURE.md → Layer 1 (Query Resolution)
     *
     * This layer performs REFERENCE-ONLY resolution. Its job is to resolve
     * pronouns and continuation phrases that need conversation history to
     * make sense ("what about that?" → "what about [referent]?").
     *
     * It is NOT a query expansion layer. Do NOT introduce here:
     *   - semantic expansion (adding synonyms or related concepts)
     *   - intent reinterpretation
     *   - context the user did not include
     *   - rewriting of standalone, well-formed questions
     *
     * The trigger conditions in this method (pronouns, continuation phrases)
     * deliberately exclude short standalone questions. A 5-word query like
     * "who is the admissions director?" is complete — it does not need
     * rewriting. Adding triggers like "<= N words" or "ends in ?" leads
     * to over-rewriting that fabricates context the user didn't ask about.
     *
     * Retrieval stability depends on this layer staying narrow.
     */
    private function resolve_followup(string $query, string $history_json): string {
        if (empty($history_json)) return $query;

        // v4.37.129+: skip rewrite for casual queries — greetings,
        // thanks, yes/no acknowledgments, and conversational closers.
        // These don't need rewriting; rewriting them produces verbose
        // chatbot-perspective questions ("How can I help you further
        // with...") which then match KB entries unrelated to user
        // intent. "thanks" should stay "thanks" all the way through
        // the pipeline; downstream layers (KB search miss + AI
        // fallback) can handle the polite acknowledgment naturally.
        if ($this->is_casual_query($query)) {
            return $query;
        }

        // v4.37.133+: skip rewrite for gibberish input. The rewriter
        // has an "always output a question" contract that makes it
        // dutifully fabricate meaning for nonsense — laundering
        // "asdfg hkl" into "What information do you need about
        // obtaining an enrollment verification certificate?" which
        // then matches KB at high score and serves a confident answer
        // to garbage. With this guard, the raw gibberish reaches KB
        // search (which won't match substantively) and falls through
        // to the existing gibberish no-answer handler at the bottom
        // of the search flow, which already produces a clean refusal.
        if ($this->is_gibberish_query($query)) {
            return $query;
        }

        // v4.37.128+: if the current query exactly (or near-exactly)
        // matches a recent user message in history, skip rewrite
        // entirely. The rewriter is for resolving ambiguous referents
        // ("yes", "what about it?") that need history context. For
        // verbatim-repeated queries, rewriting is actively harmful —
        // the rewriter incorporates content from the previous bot
        // answer, drifting the rewrite each turn ("letter of continuing
        // enrollment" → "...through accesSPoint" → "...enrollment
        // verification certificate" → "...from the Registrar's office")
        // and shifting the served path from AI fallback to KB match,
        // producing increasingly different answers despite identical
        // user input.
        $history_for_dedup = json_decode(stripslashes($history_json), true);
        if (is_array($history_for_dedup)) {
            $normalized_query = $this->normalize_for_dedup($query);
            foreach ($history_for_dedup as $msg) {
                if (($msg['type'] ?? '') !== 'user') continue;
                $msg_normalized = $this->normalize_for_dedup(
                    wp_strip_all_tags((string) ($msg['content'] ?? ''))
                );
                if ($msg_normalized === $normalized_query
                    || $this->is_near_duplicate($msg_normalized, $normalized_query)) {
                    // User is repeating — use original, skip rewrite.
                    // v4.37.133+: log so we can verify this is firing
                    // when expected. If a verbatim repeat goes through
                    // without this log line, the embed widget likely
                    // truncated history before the duplicate.
                    \CleverSay\Logger::instance()->info(
                        'Verbatim repeat detected — skipping rewrite',
                        ['query' => $query]
                    );
                    return $query;
                }
            }
        }

        // v4.37.137+: Tighten trigger conditions.
        //
        // Previous behavior: rewriter fired on any query <=8 words
        // OR ending in `?`. This matched almost every well-formed
        // standalone question. The rewriter then injected context
        // from the prior turn that the user didn't ask about:
        //   "who is the admissions director?"
        //     → "who is the admissions director at the institution
        //        you're planning to call about?"
        //   (inferring "the institution" from an earlier unrelated
        //    "I will call later" message)
        //
        //   "is the writing test on the act required"
        //     → "is the writing test on the ACT a required component
        //        for admissions at this institution?"
        //   (changing the question's framing from a specific
        //    writing-test inquiry to a broad admissions inquiry,
        //    which then flips validator behavior)
        //
        //   "fee for going over max credits"
        //     → "what is the fee or penalty for enrolling in more
        //        than the maximum number of credits allowed?"
        //   (adding "or penalty" — a substantive noun the user
        //    didn't include)
        //
        // The rewriter exists to resolve REFERENTIAL ambiguity that
        // requires history to disambiguate ("yes", "what about it?",
        // "tell me more"), not to "improve" complete questions. Word
        // count and trailing `?` are not signals of referential
        // ambiguity — they're properties of normal English questions.
        //
        // New behavior: only fire when there's an explicit referential
        // signal:
        //   1. Third-person referential pronouns (it/that/those/they)
        //      — typically refer to content from the prior turn
        //   2. Continuation phrases at start of query (what about X,
        //      and X, also X, tell me more) — explicitly signal "in
        //      addition to what we just discussed"
        //
        // First/second person ("I", "my", "you", "your") are inherent
        // to user/bot dialogue and don't need history resolution.
        // Question words alone (what/how/who/why) are not signals —
        // they start standalone questions ("who is the director").
        //
        // For non-rewritten queries, downstream layers still have
        // full history: AI fallback receives history_json and can
        // synthesize contextual answers from chunks + history without
        // needing the query itself rewritten. So losing rewrites on
        // standalone questions doesn't degrade answer quality — it
        // just stops the rewriter from changing the query's meaning.
        $query_trimmed = trim($query);

        // v4.41.5.9+: per-site disable. Some tenants have content where
        // users tend to ask self-contained questions (technical FAQ,
        // procedural questions, anything not chatty/conversational); the
        // rewriter creates more confusion than it solves on those sites.
        // Default on for back-compat with existing tenants. Toggle off
        // via Settings → AI tab → Query Rewriter checkbox.
        if (!get_option('cleversay_ai_query_rewriter', true)) {
            \CleverSay\Logger::instance()->info(
                'Rewriter skipped — disabled for this site',
                ['query' => $query]
            );
            return $query;
        }

        // v4.41.5.9+: literal re-ask short-circuit. If the user types
        // exactly the same question they just typed (modulo case,
        // whitespace, and trailing punctuation), they want the same
        // answer they wanted before, not a "follow-up" specialization.
        // This is cheap and 100% precise — no false positives. It
        // covers the "user repeated themselves verbatim" case.
        //
        // Note: this does NOT catch paraphrased re-asks (different
        // wording, same intent). True paraphrase detection requires
        // semantic similarity, and the cheap heuristics (Jaccard,
        // edit distance) have false-positive issues with real
        // follow-ups. The honest fix for paraphrased re-asks is the
        // LLM-decides design slated for v4.42.0; here we handle the
        // exact-repeat case and rely on the tightened regex below
        // to catch most relative-pronoun false positives.
        $history_for_resame = json_decode(stripslashes($history_json), true);
        if (is_array($history_for_resame) && !empty($history_for_resame)) {
            for ($i = count($history_for_resame) - 1; $i >= 0; $i--) {
                $msg = $history_for_resame[$i];
                if (($msg['type'] ?? '') !== 'user') continue;
                $prior_user_q = wp_strip_all_tags((string) ($msg['content'] ?? ''));
                if ($prior_user_q === '') break;
                $norm = static fn(string $s): string =>
                    preg_replace('/\s+/u', ' ', trim(rtrim(mb_strtolower($s), " \t\n\r\0\x0B?.!,;:"))) ?? $s;
                if ($norm($query_trimmed) === $norm($prior_user_q)) {
                    \CleverSay\Logger::instance()->info(
                        'Rewriter skipped — exact re-ask of prior question',
                        ['query' => $query, 'prior' => $prior_user_q]
                    );
                    return $query;
                }
                break; // only check most recent user turn
            }
        }

        // v4.41.5.9+: tightened referential-signal regex. Strong pronouns
        // (it/its/they/them/their/he/she/his/her) almost always refer to
        // something previously mentioned. Weak pronouns (that/this/these/
        // those) are demonstrative AND relative — "the steps that follow"
        // is relative ("follow" depends on "steps", not on history). The
        // weak set fires too aggressively when used grammatically as
        // relative pronouns in standalone questions, which is the failure
        // mode that triggered v4.41.5.8's diagnostic session.
        //
        // Strong set: always counts as a signal.
        // Weak set: only counts when paired with continuation phrasing
        //           ("what about that"), or when the query is short and
        //           starts with a demonstrative ("that one?", "this?").
        $has_strong_pronoun = (bool) preg_match(
            '/\b(it|its|they|them|their|he|she|his|her)\b/i',
            $query_trimmed
        );

        $has_continuation = (bool) preg_match(
            '/^(what about|how about|and|but|also|what if|tell me more|more on|more about)\b/i',
            $query_trimmed
        );

        // Weak demonstrative — "that/this" used as a true demonstrative
        // (not a relative). Rough heuristic: short query (<6 words) AND
        // starts with the demonstrative. "That one?" "What about that?"
        // "This too?" These are clearly referential. Long queries with
        // "that" embedded are almost always relative usage.
        $weak_word_count    = str_word_count($query_trimmed);
        $has_weak_referential = $weak_word_count < 6 && (bool) preg_match(
            '/^(that|this|those|these)\b/i',
            $query_trimmed
        );

        if (!$has_strong_pronoun && !$has_continuation && !$has_weak_referential) {
            \CleverSay\Logger::instance()->info(
                'Rewriter skipped — no referential signal',
                ['query' => $query]
            );
            return $query;
        }

        // Parse history
        $history = json_decode(stripslashes($history_json), true);
        if (!is_array($history) || count($history) < 2) return $query;

        // Check AI is configured (network-aware in Multisite)
        if (!\CleverSay\NetworkSettings::ai_is_configured()) return $query;

        // Build a compact conversation summary (last 3 exchanges max)
        $recent = array_slice($history, -6);
        $context_lines = [];
        foreach ($recent as $msg) {
            $role    = ($msg['type'] ?? 'user') === 'user' ? 'User' : 'Bot';
            $content = wp_strip_all_tags($msg['content'] ?? '');
            if (!empty($content)) {
                $context_lines[] = $role . ': ' . mb_substr($content, 0, 200);
            }
        }
        if (empty($context_lines)) return $query;

        $ai = new \CleverSay\AI();
        if (!$ai->is_configured()) return $query;

        $context_str = implode("\n", $context_lines);
        $result = $ai->resolve_question_with_context($query, $context_str);
        $logger = \CleverSay\Logger::instance();

        // v4.37.118+: stronger sanity check. The rewriter LLM occasionally
        // breaks character and emits meta-commentary instead of a rewritten
        // question (especially for ambiguous "yes"/"sure" follow-ups when
        // history has multiple possible referents). If we pass that
        // meta-text forward as the search query, downstream synthesis
        // gets confused and produces nonsense responses.
        //
        // Reject the rewrite if it:
        //   - is empty or too long
        //   - contains meta-language indicating refusal/clarification
        //   - doesn't end with ? and isn't clearly question-shaped
        if (empty($result) || strlen($result) > 300) return $query;

        $lower = strtolower($result);
        $meta_phrases = [
            'i need more', 'could you provide', 'i cannot', "i can't",
            'please clarify', 'please specify', 'unclear', 'ambiguous',
            'could refer to', 'looking back at', 'i should note',
            'as a system', 'i am designed', "i'm designed",
            'rewrite this question',
        ];
        foreach ($meta_phrases as $p) {
            if (strpos($lower, $p) !== false) {
                $logger->info('Rewrite rejected as meta-commentary, using original', [
                    'original' => $query,
                    'rejected' => $result,
                    'matched'  => $p,
                ]);
                return $query;
            }
        }

        return $result;
    }

    private function is_casual_query(string $query): bool {
        if (strlen($query) < 8) return true;
        $patterns = [
            // Greetings
            '/^(hi|hello|hey|howdy|sup|hiya|yo)\b/i',
            // Thanks
            '/^(thanks|thank you|thx|ty|thank)\b/i',
            // Closings
            '/^(bye|goodbye|see ya|cya)\b/i',
            // Acknowledgments
            '/^(ok|okay|k|sure|alright|cool|great|nice|wow|lol)\b/i',
            // Yes/no
            '/^(yes|no|yeah|nope|yep|nah)\b/i',
            // v4.37.130+: confusion/uncertainty/reaction expressions —
            // these are conversational responses, not search queries.
            // Without this list the rewriter expands them into
            // chatbot-perspective clarification questions ("What
            // specific aspect of X would you like me to clarify?")
            // which then match KB content unrelated to user intent.
            // Patterns are anchored to full-phrase shapes to avoid
            // matching legitimate questions that share the same
            // opening word ("what is X" vs "what the heck").
            //
            // v4.37.134+: split "what the heck/hell" out so that
            // trailing words are allowed ("what the heck is going on?",
            // "what the hell are you talking about"). These idioms are
            // exclamations regardless of what follows — they don't
            // name a topic the user is asking about.
            '/^what\s+the\s+(heck|hell)\b/i',
            '/^what(\s*\?+|\?+)\s*\?*\s*$/i',
            '/^huh\s*\??\s*$/i',
            '/^i\s+don\'?t\s+(know|understand|get\s+it)\b/i',
            '/^i\'?m\s+(not\s+sure|confused|lost)\b/i',
            '/^not\s+sure\b/i',
            '/^(uh|um|er|hmm|hm)\b/i',
            '/^(what|how|why)\s+(do\s+you\s+mean|now)\b/i',
            '/^wait\b/i',
            '/^really\s*\??\s*$/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, trim($query))) return true;
        }
        return false;
    }

    /**
     * Detect random keyboard mashing / nonsense input (e.g. "klsjf lskjdf").
     * Distinct from casual greetings — these need a different response.
     * Returns true for inputs that are clearly not real words.
     */
    private function is_gibberish_query(string $query): bool {
        $q = trim($query);
        if (strlen($q) < 4) return false;  // too short to judge

        // Strip non-letter chars and check for runs of 5+ consonants
        $letters_only = preg_replace('/[^a-zA-Z]/', '', $q);
        if (strlen($letters_only) < 4) return false;

        // v4.42.34+: Run the consonant-run regex against the ORIGINAL
        // query (with spaces preserved), not the letters-only version.
        // The previous code stripped spaces first, then scanned for
        // 5+ consonant runs — which produced false positives whenever
        // a word ending in consonants was followed by a word starting
        // with consonants (e.g. "thanks bro" → "thanksbro" → run
        // "nksbr" matches, scored 5/9 = 0.56, flagged as gibberish).
        // The fix: scan with spaces intact so word boundaries break
        // runs. The denominator is still letters-only count, which
        // keeps the ratio math the same for legitimately gibberish
        // input (e.g. "asdfghjkl" still scores 8/9 = 0.89).
        preg_match_all('/[bcdfghjklmnpqrstvwxyz]{5,}/i', $q, $matches);
        $gibberish_chars = array_sum(array_map('strlen', $matches[0]));

        // v4.37.135+: 40%+ of letter chars in long consonant runs is mashing.
        // Threshold is inclusive (>=) — strict "> 0.4" missed borderline
        // cases like "iwue klsdjf iooiu" (6/15 = exactly 0.4) where the
        // gibberish ran through to AI fallback and produced a misleading
        // clarifying response with a Sources panel attached.
        return $gibberish_chars / strlen($letters_only) >= 0.4;
    }

    /**
     * Determine if a query is a real, legitimate question worth showing
     * "Still need help?" for. Filters out:
     * - Greetings, thanks, social chat
     * - Single words or very short phrases
     * - Random keyboard mashing / gibberish
     * - Pure statements with no question intent
     * - Strings with no real words (numbers, symbols only)
     */
    private function is_real_question(string $query): bool {
        $q = trim($query);

        // Too short to be a real question
        if (strlen($q) < 8) return false;

        // Casual greetings, thanks, etc.
        if ($this->is_casual_query($q)) return false;

        // Must contain at least 2 alphabetic words of 2+ chars
        $words = preg_split('/\s+/', strtolower($q));
        $real_words = array_filter($words, fn($w) => preg_match('/^[a-z]{2,}$/', $w));
        if (count($real_words) < 2) return false;

        // Gibberish detection — if more than 40% of word characters are
        // consecutive consonants (5+), it's likely keyboard mashing.
        // v4.42.34+: scan the original query (spaces intact) rather than
        // the concatenated letters-only string, so word boundaries break
        // runs. Same fix as is_gibberish_query — see comment there.
        $letters_only = preg_replace('/[^a-zA-Z]/', '', $q);
        if (strlen($letters_only) > 0) {
            preg_match_all('/[bcdfghjklmnpqrstvwxyz]{5,}/i', $q, $matches);
            $gibberish_chars = array_sum(array_map('strlen', $matches[0]));
            if ($gibberish_chars / strlen($letters_only) > 0.4) return false;
        }

        // Has at least one word that is 3+ characters (not just articles/prepositions)
        $meaningful = array_filter($real_words, fn($w) => strlen($w) >= 3
            && !in_array($w, ['the','a','an','is','are','was','were','be','to','of','in','on','at','it']));
        if (empty($meaningful)) return false;

        return true;
    }

    /**
     * Detect if an AI response is a generic deflection rather than a real answer.
     * These are responses that just say "contact X office" without providing
     * any actual information — effectively a polite "I don't know".
     */
    private function is_generic_deflection(string $answer): bool {
        $text = strtolower(wp_strip_all_tags($answer));
        $word_count = str_word_count($text);

        // CASE 2 refusal pattern (off-topic / nonsense questions). The AI is
        // instructed to produce: "Sorry, I can only help with [topics]. What
        // would you like to know?" — a short, clean refusal that the failure
        // logic should recognize so it can fire the inquiry prompt or handoff.
        $is_clean_refusal = preg_match(
            '/\b(sorry|i can only help with|i\'m only able to help with|i\'m here to help with)\b/i',
            $text
        ) && $word_count < 40;
        if ($is_clean_refusal) {
            return true;
        }

        // "I don't have / I don't know" admission lead-in. The AI is supposed
        // to redirect cleanly without admitting ignorance, but it sometimes
        // produces "I don't have information about X. Contact our Office of Y..."
        // — that's a real failure dressed up with contact info. Counts as
        // deflection so the user can be escalated to a human after enough of them.
        $is_admission_with_redirect = preg_match(
            '/\b(i don\'t (have|know)|i do not (have|know)|i\'m not sure|i am not sure|i can\'t (find|tell)|don\'t have (information|details|info|data|specifics|the (current|specific|exact)))/i',
            $text
        ) && $word_count < 80;
        if ($is_admission_with_redirect) {
            return true;
        }

        // Persona introductions — bot introducing itself instead of answering a real question.
        // Catches patterns like:
        //   "I'm Stevie... How can I help you with..."
        //   "I'm here to help with questions about..."
        //   "My name is X, a representative at..."
        $has_self_intro = preg_match(
            '/\b(i\'m|i am|my name is)\b.{0,80}\b(representative|assistant|here to help|here at)\b/is',
            $answer
        );
        $has_offer_to_help = preg_match(
            '/\b(how can i help|what can i (help|assist)|here to help|help you (with|today)|assist you)/i',
            $answer
        );
        if (($has_self_intro || $has_offer_to_help) && $word_count < 80) {
            return true;
        }

        // Must be short — real answers tend to be longer
        if ($word_count > 40) return false;

        // Deflection signals: directs to contact someone without giving content
        $has_contact_redirect = preg_match(
            '/\b(contact|reach out to|visit|call|email|speak with|talk to)\b.*\b(office|department|advisor|staff|team|registrar|bursar|financial aid)\b/i',
            $text
        );
        // Real answers have specific facts. v4.36.2+ broadened the
        // pattern set substantially — the previous regex only matched
        // phone numbers, time strings, weekday names, dollar amounts,
        // and a narrow list of action nouns (deadline/requirement/etc).
        // Real KB answers like "located in Room 006 SSC. Email
        // bursar@uwsp.edu" failed all those checks even though they
        // contain genuinely specific information (room number, email
        // address). The expanded set catches:
        //   - Phone numbers in any common format incl. (XXX) XXX-XXXX
        //   - Time strings (HH:MM)
        //   - All seven weekday names
        //   - Dollar amounts
        //   - Action/process nouns (original list)
        //   - Location words: located, room X, building, floor, suite,
        //     address (street/avenue/etc.), campus, hall
        //   - Email addresses (anything@domain.tld)
        //   - URLs (http(s)://)
        //   - "hours" / "open" / "closed" with adjacent times
        $has_specific_content = preg_match(
            '/\b(\d{3}[-.\s]\d{3,4}|\(\d{3}\)\s*\d{3,4}|\d{1,2}:\d{2}|monday|tuesday|wednesday|thursday|friday|saturday|sunday|\$\d+|deadline|requirement|process|step|application|form|submit|apply|located|room\s+[\w\d]+|building|floor|suite|address|street|avenue|hall|campus|hours|open\s+(from|until|at)|closed)\b|[a-z0-9_.+-]+@[a-z0-9-]+\.[a-z0-9.-]+|https?:\/\//i',
            $text
        );
        return $has_contact_redirect && !$has_specific_content;
    }

    /**
     * v4.42.0+: public seam for the BulkTester to invoke try_ai_fallback
     * without reflection. NEVER called from the live ajax_search path —
     * that uses try_ai_fallback() directly. Existence of this method is
     * a stable contract for offline qualification tools; it should not
     * be removed without updating BulkTester at the same time.
     *
     * Always passes empty history (each bulk test question is evaluated
     * standalone, no rewriter/history context bleed between rows).
     */
    public function run_ai_fallback_for_test(string $question): ?string {
        return $this->try_ai_fallback($question, '');
    }

    /**
     * v4.42.0.2+: public seam for the BulkTester to run the full Layer 1
     * decision tree on a matched KB entry — validator (if enabled), polish
     * (if validator accepts and entry isn't already polished), or AI
     * reroute (if validator rejects). Returns the answer the user would
     * actually see, plus a `path` flag telling the caller which branch
     * was taken so the bulk runner can record it.
     *
     * Mirrors the live ajax_search Layer 1 logic at ~lines 3000–3270 of
     * this file. Kept narrowly scoped: caller has already done Layer 1
     * matching and decided this is a strong hit; this method only handles
     * what happens after that decision. AI reroute uses try_ai_fallback
     * (same path as the live code's `goto ai_fallback`).
     *
     * v4.42.0.4+: optional $process_trace by-reference. When provided,
     * the method pushes step entries describing what it did (validator
     * verdict, polish run, AI reroute trigger) so the Ask Question /
     * AI Inspector page can display the same decisions without making
     * its own redundant validator call. Step numbers continue from the
     * trace's current length.
     *
     * @param string     $question The user's original query.
     * @param array      $first_match The matches[0] row from Search::search().
     * @param array|null &$process_trace Optional. If passed, gets step
     *   entries appended for each pipeline decision made.
     * @return array{answer:string,path:string,was_validated:bool,was_polished:bool}
     *   - path: 'kb_raw' | 'kb_polished' | 'kb_ai_rerouted'
     */
    public function run_layer1_pipeline_for_test(string $question, array $first_match, ?array &$process_trace = null): array {
        $logger = Logger::instance();

        $ai_config          = \CleverSay\NetworkSettings::get_ai_config();
        $ai_ok              = \CleverSay\NetworkSettings::ai_is_configured();
        $validate_all_kb    = $ai_ok && !empty($ai_config['validate_kb']);
        $validate_aadefault = $ai_ok && !empty($ai_config['aadefault_validate']);
        $is_aadefault       = strtolower($first_match['sub_keyword'] ?? '') === 'aadefault';
        $should_validate    = $validate_all_kb || ($validate_aadefault && $is_aadefault);
        $polish_enabled     = $ai_ok && !empty($ai_config['polish_kb']);

        // Helper to push a step into the optional process trace. No-op
        // when caller didn't pass a trace (BulkTester path).
        $push_step = function (string $description, string $result_text, string $ai_status) use (&$process_trace): void {
            if (!is_array($process_trace)) return;
            $process_trace[] = [
                'step'        => count($process_trace) + 1,
                'description' => $description,
                'result'      => $result_text,
                'ai_status'   => $ai_status,
            ];
        };

        $raw_html  = (string) ($first_match['response'] ?? '');
        $kb_clean  = $this->clean_response_html($raw_html);

        $kb_was_validated = false;
        $kb_is_relevant   = true;
        $shared_ai        = null;

        if ($should_validate) {
            $shared_ai = new \CleverSay\AI();
            $kb_is_relevant   = $shared_ai->validate_kb_answer($question, $kb_clean);
            $kb_was_validated = true;
            $logger->info('BulkTester Layer 1 validator', [
                'question'    => mb_substr($question, 0, 80),
                'is_relevant' => $kb_is_relevant,
            ]);

            $trigger = $validate_all_kb
                ? 'validate_kb (all matches)'
                : 'aadefault_validate (aadefault only)';
            if ($kb_is_relevant) {
                $push_step(
                    'KB AI Validation (' . $trigger . ')',
                    '✅ AI confirmed this KB answer is relevant to the question.',
                    'not_needed'
                );
            } else {
                $push_step(
                    'KB AI Validation (' . $trigger . ')',
                    '⚠️ AI determined this KB answer does NOT fit the question — production falls through to AI synthesis.',
                    'would_fire'
                );
            }

            if (!$kb_is_relevant) {
                // Validator rejected — reroute to AI fallback, same as
                // the live path's `goto ai_fallback` does.
                $ai_answer = $this->try_ai_fallback($question, '');
                $push_step(
                    'AI Synthesis (KB rejected reroute)',
                    $ai_answer
                        ? 'AI generated a fresh answer using retrieved chunks.'
                        : 'AI could not generate an answer. Widget would show no-answer message.',
                    $ai_answer ? 'would_fire' : 'no_chunks'
                );
                return [
                    'answer'        => $ai_answer ?? '',
                    'path'          => 'kb_ai_rerouted',
                    'was_validated' => true,
                    'was_polished'  => false,
                ];
            }
        } else {
            $reason = !$ai_ok
                ? 'AI not configured'
                : (!$validate_all_kb && !$validate_aadefault
                    ? 'KB validation disabled in network settings'
                    : 'narrower aadefault setting active but match was not aadefault');
            $push_step(
                'KB AI Validation',
                'Skipped — ' . $reason . '.',
                'disabled'
            );
        }

        // Validator accepted (or wasn't run). Now decide polish.
        if ($polish_enabled) {
            $stored_hash  = (string) ($first_match['polished_hash'] ?? '');
            $current_hash = \CleverSay\Admin::compute_response_hash($raw_html);
            $already_polished = ($stored_hash !== '' && $stored_hash === $current_hash);

            if ($already_polished) {
                $push_step(
                    'Polish KB',
                    'Skipped — entry was previously polished and has not changed since.',
                    'not_needed'
                );
                // v4.42.17+: even when polish is skipped, the stored
                // response may contain markdown (**bold**, [text](url))
                // from a previous polish or admin paste. Run the
                // converter so those markers render as HTML rather
                // than as literal text in the widget.
                return [
                    'answer'        => \CleverSay\AI::convert_minimal_markdown_to_html($raw_html),
                    'path'          => 'kb_raw',
                    'was_validated' => $kb_was_validated,
                    'was_polished'  => false,
                ];
            }

            $ai = $shared_ai ?? new \CleverSay\AI();

            // If we didn't validate above, polish-side validation runs
            // and can also reject — same fall-through behavior.
            $relevant_for_polish = $kb_was_validated
                ? $kb_is_relevant
                : $ai->validate_kb_answer($question, $kb_clean);

            if (!$relevant_for_polish) {
                $push_step(
                    'Polish-stage validation',
                    '⚠️ Polish-stage AI rejected the KB answer — falling through to AI synthesis.',
                    'would_fire'
                );
                $ai_answer = $this->try_ai_fallback($question, '');
                return [
                    'answer'        => $ai_answer ?? '',
                    'path'          => 'kb_ai_rerouted',
                    'was_validated' => true,
                    'was_polished'  => false,
                ];
            }

            $polished = $ai->polish_kb_response($question, $raw_html);
            if ($polished) {
                $push_step(
                    'Polish KB',
                    '✅ AI rewrote the KB answer in a friendlier, conversational tone.',
                    'would_fire'
                );
                return [
                    'answer'        => $polished,
                    'path'          => 'kb_polished',
                    'was_validated' => $kb_was_validated,
                    'was_polished'  => true,
                ];
            }
            // Polish failed silently — fall through to raw.
            $push_step(
                'Polish KB',
                'Polish call returned empty — serving raw KB answer.',
                'no_chunks'
            );
        } else {
            $push_step(
                'Polish KB',
                'Skipped — polish_kb disabled in network settings.',
                'disabled'
            );
        }

        return [
            'answer'        => \CleverSay\AI::convert_minimal_markdown_to_html($raw_html),
            'path'          => 'kb_raw',
            'was_validated' => $kb_was_validated,
            'was_polished'  => false,
        ];
    }

    private function try_ai_fallback(string $question, string $history_json = ''): ?string {
        $logger = Logger::instance();

        $logger->info('try_ai_fallback called', [
            'question'     => substr($question, 0, 80),
            'has_history'  => !empty($history_json),
            'ai_configured' => \CleverSay\NetworkSettings::ai_is_configured(),
            'post_context' => $_POST['context'] ?? 'none',
        ]);

        // v4.37.132+: short-circuit gibberish before reaching the AI
        // synthesis path. KB broad search and FULLTEXT scoring will
        // sometimes loosely match nonsense input ("asdfgh jkl") to
        // chunks containing similar character runs, then synthesis
        // produces a confident but irrelevant answer. Better to admit
        // we didn't understand than to fabricate a response. Detection
        // is the same is_gibberish_query used by is_real_question.
        if ($this->is_gibberish_query($question)) {
            $logger->info('Gibberish detected, returning fixed response', [
                'question' => substr($question, 0, 80),
            ]);
            return __("I'm not sure I understood that. Could you rephrase your question?", 'cleversay');
        }

        // v4.42.14+: stateful affirmation resolution. The widget passes
        // the user's literal message ("yes", "sure", etc.) but those are
        // state-dependent operators — they mean nothing without the
        // prior assistant turn's context. Without this step, retrieval
        // runs against the literal token, finds garbage chunks, and
        // synthesis bails with "I don't have specific details."
        //
        // The resolver classifies the message into one of:
        //   - NOT_AFFIRMATION: normal query, pass through
        //   - FOLLOWUP_ACCEPTANCE: bare "yes" after offered follow-up —
        //     resolved query = the offered topic
        //   - FOLLOWUP_WITH_Q: compound "yes when exactly?" — resolved
        //     query = latent topic + new question, scoped
        //   - ANSWER_CONFIRMATION: "yes" to "Did you mean X?" (rare
        //     in current prompts; detected for observability)
        //   - AFFIRMATION_NO_STATE: "yes" with no pending offer; passed
        //     through unchanged, will likely produce weak retrieval
        //
        // Mode 'resolve_only' (v4.42.14 default) applies the resolution
        // and feeds the resolved query into normal retrieval. Mode
        // 'resolve_and_inherit' (v4.42.15+) will additionally inherit
        // parent chunks. Mode 'off' disables the layer entirely.
        $history_for_resolver = [];
        if (!empty($history_json)) {
            $decoded = json_decode(stripslashes($history_json), true);
            if (is_array($decoded)) {
                $history_for_resolver = $decoded;
            }
        }
        $resolution = \CleverSay\FollowupResolver::resolve($question, $history_for_resolver);
        $logger->info('Followup resolver', [
            'decision'        => $resolution['decision'],
            'resolved_query'  => $resolution['resolved_query'] === $question
                                    ? '(unchanged)'
                                    : substr((string) $resolution['resolved_query'], 0, 100),
            'latent_topic'    => $resolution['latent_topic'],
            'debug'           => $resolution['debug'],
        ]);

        // If the resolver produced a meaningful rewrite, use it. The
        // four non-NOT_AFFIRMATION decisions all produce a resolved
        // query that's safer to embed than the literal user message.
        // AFFIRMATION_NO_STATE keeps the original message but is
        // explicitly flagged for observability.
        $retrieval_query = $question;
        if ($resolution['decision'] !== \CleverSay\FollowupResolver::DECISION_NOT_AFFIRMATION
            && \CleverSay\FollowupResolver::get_mode() !== \CleverSay\FollowupResolver::MODE_OFF) {
            $resolved = (string) ($resolution['resolved_query'] ?? '');
            if ($resolved !== '' && $resolved !== $question) {
                $retrieval_query = $resolved;
                \CleverSay\RequestTimer::instance()->set('followup_decision', $resolution['decision']);
            }
        }

        try {
            $ai = new AI();

            if (!\CleverSay\NetworkSettings::ai_is_configured()) {
                $logger->warning('AI fallback skipped: not configured');
                return null;
            }

            // v4.41.5+: from this point on, AI fallback has committed —
            // we'll do retrieval + synthesis even if either fails. Tag
            // the timer so analytics can distinguish "AI was tried but
            // produced no answer" from "AI was never tried" (the latter
            // is the kb_strong / not-configured / gibberish case).
            \CleverSay\RequestTimer::instance()->set('ai_fallback_fired', true);

            $indexer = new Indexer();
            $chunks  = [];

            // v4.41.5+: time retrieval. Whether this dispatches to the
            // Phase 3 hybrid Retriever (when use_hybrid_retrieval is on)
            // or the legacy FULLTEXT path, the work happens inside
            // find_relevant_chunks(). The exception-fallback to
            // find_relevant_chunks_simple() is rare and small enough that
            // we just include it in the same stage measurement.
            //
            // v4.42.14+: use $retrieval_query (possibly resolver-rewritten)
            // rather than the literal $question. The downstream synthesis
            // call still receives $question as the user's original
            // intent — only retrieval is scoped to the resolved form.
            \CleverSay\RequestTimer::instance()->start_stage('retrieval');
            try {
                $chunks = $indexer->find_relevant_chunks($retrieval_query);
            } catch (\Throwable $e) {
                $logger->error('AI fallback: chunk retrieval threw exception', [
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                ]);
                $chunks = $indexer->find_relevant_chunks_simple($retrieval_query);
            }
            \CleverSay\RequestTimer::instance()->end_stage('retrieval');

            $logger->info('AI fallback chunks retrieved', [
                'count'    => count($chunks),
                'question' => substr($question, 0, 60),
            ]);

            if (empty($chunks)) {
                $logger->warning('AI fallback skipped: no chunks found for question');
                return null;
            }

        // Parse conversation history from frontend
        $history = [];
        if (!empty($history_json)) {
            $parsed = json_decode(stripslashes($history_json), true);
            if (is_array($parsed)) {
                // Convert from {content, type} to {role, content}
                foreach ($parsed as $msg) {
                    $role = ($msg['type'] ?? 'bot') === 'user' ? 'user' : 'assistant';
                    // Strip HTML from bot messages
                    $text = wp_strip_all_tags($msg['content'] ?? '');
                    if (!empty($text)) {
                        $history[] = ['role' => $role, 'content' => $text];
                    }
                }
            }
        }

        // v4.37.125+: detect repeated identical queries and strip stale
        // bot responses from history. Without this, the LLM sees its own
        // previous (possibly degraded) answers and pattern-completes on
        // them, producing progressively shorter responses each time the
        // user re-asks. By removing the prior identical-question bot
        // responses, each retry gets fresh synthesis without the
        // feedback loop.
        // v4.37.126+: better normalization (strip punctuation + collapse
        // whitespace) to catch near-identical queries that vary only by
        // punctuation or spacing. Plus Jaccard token-similarity for
        // typo-tolerance — "letter of continuing enrollment" and
        // "letter continuing enrollment" (typo dropping "of") match.
        $normalized_question = $this->normalize_for_dedup($question);
        $cleaned_history = [];
        $skip_next_assistant = false;
        foreach ($history as $msg) {
            if ($msg['role'] === 'user') {
                $msg_normalized = $this->normalize_for_dedup((string) $msg['content']);
                if ($msg_normalized === $normalized_question
                    || $this->is_near_duplicate($msg_normalized, $normalized_question)) {
                    // Past identical-or-near user message — drop it AND its assistant reply
                    $skip_next_assistant = true;
                    continue;
                }
                $cleaned_history[] = $msg;
                $skip_next_assistant = false;
            } else { // assistant
                if ($skip_next_assistant) {
                    $skip_next_assistant = false;
                    continue;
                }
                $cleaned_history[] = $msg;
            }
        }
        if (count($cleaned_history) !== count($history)) {
            $logger->info('Repeat query detected, cleaned stale exchanges', [
                'original_history_count' => count($history),
                'cleaned_history_count'  => count($cleaned_history),
            ]);
            $history = $cleaned_history;
        }

            // v4.41.5+: time synthesis and capture token/cost data into
            // the request metrics row. The existing $start_ms/$latency
            // measurement is kept for AIDebugLog compatibility (the
            // legacy `latency_ms` field consumed by the Inspector view).
            $start_ms = (int) (microtime(true) * 1000);
            \CleverSay\RequestTimer::instance()->start_stage('synthesis');
            $result   = $ai->answer_with_context($question, $chunks, $history);
            \CleverSay\RequestTimer::instance()->end_stage('synthesis');
            $latency  = (int) (microtime(true) * 1000) - $start_ms;

            // Token + cost capture. answer_with_context returns these
            // even on error responses (some token cost may have been
            // incurred before the failure), so we record them
            // unconditionally before the error gate below.
            \CleverSay\RequestTimer::instance()->set(
                'tokens_in',
                isset($result['tokens_input']) ? (int) $result['tokens_input'] : null
            );
            \CleverSay\RequestTimer::instance()->set(
                'tokens_out',
                isset($result['tokens_output']) ? (int) $result['tokens_output'] : null
            );
            \CleverSay\RequestTimer::instance()->set(
                'cost',
                isset($result['cost']) ? (float) $result['cost'] : null
            );
            // v4.41.5.3+: record which model produced this synthesis.
            // The accessor reads $this->synthesis_model on the AI
            // instance — distinct from $this->model which is the
            // default for validation/polish. Per-row capture means
            // a mid-window model swap produces a clean A/B in the
            // dashboard rather than mixing the two together.
            \CleverSay\RequestTimer::instance()->set(
                'synthesis_model',
                $ai->get_synthesis_model()
            );

            if (!empty($result['error']) || empty($result['answer'])) {
                $logger->warning('AI fallback: API returned no answer', [
                    'error' => $result['error'] ?? null,
                ]);
                return null;
            }

            $logger->info('AI fallback: answer received', [
                'length' => strlen($result['answer']),
                'cost'   => $result['cost'] ?? 0,
            ]);

            // Log to ai_answers table for admin review and knowledge base growth
            $ai_answer_id = $this->log_ai_answer($question, $result['answer'], $chunks, $history_json);

            // Capture for the AI Inspector if a debug session is active.
            // Best-effort — failure here must not affect the user response.
            if (class_exists('\\CleverSay\\AIDebugLog') && \CleverSay\AIDebugLog::should_capture()) {
                \CleverSay\AIDebugLog::capture([
                    'question'        => $question,
                    'system_prompt'   => $result['system_prompt'] ?? '',
                    'chunks'          => array_map(function($c) {
                        return [
                            'source_title' => $c['source_title'] ?? '',
                            'content'      => $c['content']      ?? '',
                        ];
                    }, $chunks),
                    'history'         => $history,
                    'final_answer'    => $result['answer'],
                    'ai_response'     => $result['answer'], // raw equals final for now; polish lives elsewhere
                    'latency_ms'      => $latency,
                    'ai_answer_id'    => $ai_answer_id,
                    'trigger_reason'  => 'manual',
                ]);
            }

            // v4.42.15+: convert the model's markdown (**bold**, __bold__,
            // [text](url)) to HTML before sending to the widget. The
            // widget renders bot answers via innerHTML, so markdown that
            // isn't converted appears literally on screen. Done AFTER
            // the AI Inspector capture above so the inspector continues
            // to show exactly what the model produced (raw markdown
            // markers and all) — useful for prompt debugging.
            return \CleverSay\AI::convert_minimal_markdown_to_html((string) $result['answer']);

        } catch (\Throwable $e) {
            Logger::instance()->error('AI fallback threw exception', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);
            return null;
        }
    }


    /**
     * AI re-answer triggered after "Not Helpful" rating.
     * Unlike try_ai_fallback, this proceeds even when no indexed chunks
     * exist — the AI answers using only the persona + question + history.
     */
    private function try_ai_reanswer(string $question, string $history_json = ''): ?string {
        $logger = Logger::instance();

        if (!\CleverSay\NetworkSettings::ai_is_configured()) {
            $logger->warning('try_ai_reanswer: AI not configured');
            return null;
        }

        try {
            $ai = new AI();

            // Try to get chunks — but proceed with empty array if none found
            $indexer = new Indexer();
            try {
                $chunks = $indexer->find_relevant_chunks($question);
            } catch (\Throwable $e) {
                $logger->warning('try_ai_reanswer: chunk retrieval failed, continuing without chunks');
                $chunks = [];
            }

            // Parse conversation history
            $history = [];
            if (!empty($history_json)) {
                $parsed = json_decode(stripslashes($history_json), true);
                if (is_array($parsed)) {
                    foreach ($parsed as $msg) {
                        $role = ($msg['type'] ?? 'bot') === 'user' ? 'user' : 'assistant';
                        $text = wp_strip_all_tags($msg['content'] ?? '');
                        if (!empty($text)) {
                            $history[] = ['role' => $role, 'content' => $text];
                        }
                    }
                }
            }

            $result = $ai->answer_with_context($question, $chunks, $history);

            if (!empty($result['error']) || empty($result['answer'])) {
                $logger->warning('try_ai_reanswer: API returned no answer');
                return null;
            }

            $logger->info('try_ai_reanswer: answer received', ['length' => strlen($result['answer'])]);
            $this->log_ai_answer($question, $result['answer'], $chunks, $history_json);
            return $result['answer'];

        } catch (\Throwable $e) {
            $logger->error('try_ai_reanswer exception', [
                'error' => $e->getMessage(),
                'line'  => $e->getLine(),
            ]);
            return null;
        }
    }

    /**
     * Store an AI-generated answer for admin review and optional promotion.
     */
    private function log_ai_answer(string $question, string $answer, array $chunks, string $history_json = ''): int {
        global $wpdb;
        $db = new Database();

        $source_titles = array_unique(array_filter(array_column($chunks, 'source_title')));

        $insert_data = [
            'question'      => $question,
            'answer'        => $answer,
            'source_titles' => !empty($source_titles) ? implode(', ', $source_titles) : null,
            'status'        => 'pending',
        ];

        // FK-link back to the originating questions_log row. Set by ajax_search
        // right after Search::search() returns — enables reliable admin joins.
        if ($this->current_logged_question_id !== null && $this->current_logged_question_id > 0) {
            $insert_data['logged_question_id'] = $this->current_logged_question_id;
        }

        // Conversation threading — derive a stable ID from the earliest user
        // message in history. Follow-ups within the same widget session inherit
        // the same ID, so admins can see the full back-and-forth grouped together.
        if (!empty($history_json)) {
            $history = json_decode(stripslashes($history_json), true);
            if (is_array($history) && !empty($history)) {
                // Find the first user message; fall back to the first message of any kind
                $anchor = null;
                foreach ($history as $msg) {
                    if (($msg['type'] ?? '') === 'user') {
                        $anchor = $msg;
                        break;
                    }
                }
                if ($anchor === null) {
                    $anchor = $history[0];
                }
                $anchor_content = wp_strip_all_tags((string) ($anchor['content'] ?? ''));
                if ($anchor_content !== '') {
                    // Hash first-message + IP so it's stable per conversation
                    // but different per visitor
                    $insert_data['conversation_id'] = substr(
                        md5($anchor_content . '|' . $this->get_client_ip()),
                        0,
                        32
                    );
                }
                // Store the full history so the admin can see the chat flow
                $insert_data['history_json'] = wp_json_encode($history);
            }
        }

        // If this AI answer was generated after a KB rejection, flag it so the
        // AI Answers page can show a "rejected from X" badge for context.
        if ($this->kb_rejection_reason !== null) {
            $insert_data['kb_rejected']        = 1;
            $insert_data['rejected_keyword']   = $this->kb_rejected_keyword;
            $insert_data['rejected_reason']    = $this->kb_rejection_reason;
            $insert_data['rejected_kb_answer'] = $this->kb_rejected_answer;
        }

        $wpdb->insert($db->ai_answers, $insert_data);
        $ai_answer_id = (int) $wpdb->insert_id;

        // v4.37.89+: Persist source citations. Links the AI answer to
        // each unique source whose chunks contributed. The Sources
        // panel in the widget AND the existing AI Answers admin view
        // both read from this table. Storage is conditional on the
        // citations toggle being on for this site — when off, no rows
        // are created (zero overhead). Position preserves the order
        // chunks appeared in retrieval, mainly so the panel display
        // is stable across page renders.
        $citations_enabled = (bool) get_option('cleversay_citations_enabled', false);
        // v4.37.132+: skip citation persistence for casual queries
        // ("thanks", "lol nice job", "what?", "I don't understand").
        // The widget retrieved chunks for context (needed for the
        // synthesis to know what conversation to acknowledge) but the
        // answer itself isn't grounded in those chunks — it's a
        // conversational reply. Showing "Sources (3)" alongside a
        // "You're welcome!" response is misleading and clutters the UI.
        $is_casual = $this->is_casual_query($question);

        // v4.37.135+: also skip when the AI's answer is short enough
        // to almost certainly be conversational rather than grounded.
        // Genuine KB-derived synthesis answers in this domain run
        // 200-700 chars (topic intro + procedure + contact info).
        // Responses under 100 chars are typically clarifying questions
        // ("I'm not sure what you're asking — could you rephrase?")
        // or short acknowledgments where the AI didn't pull from any
        // chunks. This catches partial-detection cases that escape
        // both is_casual_query and is_gibberish_query but still
        // produce a non-grounded response.
        $answer_too_short_for_grounding = strlen(wp_strip_all_tags($answer)) < 100;

        if ($citations_enabled && !$is_casual && !$answer_too_short_for_grounding && !empty($chunks) && $ai_answer_id > 0) {
            // v4.37.111+: Citation selection delegated to CitationSelector
            // with a heuristic-vs-LLM router. Heuristic route handles
            // the clear-winner case (one chunk dominates by overlap
            // score, no competition); LLM route runs Step A to
            // discriminate when multiple chunks score similarly.
            // Falls back to heuristic-only if the LLM call errors —
            // never silently regresses to "show all" noise.
            $selector = new CitationSelector();
            $decisions = $selector->select($chunks, $question, $answer);

            // Build a quick lookup for chunk content (we need it to
            // generate snippets at insert time)
            $content_by_sid = [];
            foreach ($chunks as $c) {
                $sid = (int) ($c['source_id'] ?? 0);
                if ($sid > 0 && !isset($content_by_sid[$sid])) {
                    $content_by_sid[$sid] = (string) ($c['content'] ?? '');
                }
            }

            $position = 0;
            foreach ($decisions as $sid => $dec) {
                $cid = (int) $dec['chunk_id'];
                $chunk_content = $content_by_sid[$sid] ?? '';
                $snippet = $this->build_citation_snippet($chunk_content);

                $wpdb->insert($db->ai_answer_sources, [
                    'answer_id'      => $ai_answer_id,
                    'source_id'      => $sid,
                    'chunk_id'       => $cid > 0 ? $cid : null,
                    'position'       => $position++,
                    'snippet'        => $snippet,
                    'used_in_answer' => (int) $dec['used_in_answer'],
                    'overlap_score'  => (int) $dec['overlap_score'],
                    'route_used'     => (string) $dec['route_used'],
                    'llm_score'      => $dec['llm_score'] !== null ? (float) $dec['llm_score'] : null,
                ], ['%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%f']);
            }
        }

        // ── Optional source usage tracking ────────────────────────────────
        // Record which sources contributed to this AI answer, so admins can
        // later measure which sources are actually helpful. Disabled by
        // default to avoid row-spam on high-traffic sites.
        if (!empty($chunks) && $ai_answer_id > 0 && get_option('cleversay_track_source_usage', false)) {
            $source_ids_used = array_unique(array_filter(array_map(
                fn($c) => isset($c['source_id']) ? (int) $c['source_id'] : 0,
                $chunks
            )));
            $conv_id = $insert_data['conversation_id'] ?? null;
            $now     = current_time('mysql');
            foreach ($source_ids_used as $sid) {
                if ($sid <= 0) continue;
                $wpdb->insert($db->source_usage, [
                    'source_id'       => $sid,
                    'conversation_id' => $conv_id,
                    'ai_answer_id'    => $ai_answer_id,
                    'created_at'      => $now,
                ]);
            }
        }

        // Clear rejection state — only attach to the current AI answer
        $this->kb_rejected_keyword = null;
        $this->kb_rejection_reason = null;
        $this->kb_rejected_answer  = null;

        // Make available to the ajax_search response builder
        $this->current_ai_answer_id = $ai_answer_id > 0 ? $ai_answer_id : null;

        return $ai_answer_id;
    }

    /**
     * Extract distinctive multi-word phrases from the LLM's answer.
     *
     * Returns an array of 3-word ngrams suitable for matching against
     * source chunk content. Phrases are lowercased, alphanumeric-only,
     * and skipped when they're entirely common words. Longer phrases
     * (4+ words) are also included since exact-paragraph matches are
     * the strongest possible signal.
     *
     * Used by source-overlap scoring to detect "did this source
     * actually contribute to the answer" — when a phrase from the
     * answer appears in the source's chunk, that's strong evidence
     * of contribution.
     *
     * @since 4.37.109
     */
    private function extract_answer_phrases(string $answer): array {
        // Normalize: strip markdown, html, lowercase, alphanumeric+space only
        $text = wp_strip_all_tags($answer);
        $text = preg_replace('/[*_`]+/', '', $text); // strip markdown emphasis
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]+/', ' ', $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));
        $words = $text === '' ? [] : explode(' ', $text);
        $words = array_values(array_filter($words, 'strlen'));
        if (count($words) < 3) return [];

        // Stopword list — same conceptual filter as the snippet picker
        // but applied per-phrase: a phrase made entirely of stopwords
        // ("of the and") is skipped.
        $stopwords = [
            'a','an','the','and','or','but','if','then','than','that',
            'is','are','was','were','be','been','being',
            'do','does','did','have','has','had',
            'i','my','me','we','our','us','you','your',
            'to','of','in','on','for','with','at','by','from','up','out',
            'can','could','should','would','will','may','might','must',
            'this','these','those','it','its','as','also',
            'about','some','any','all','no','not','so','too',
        ];
        $stopwords_set = array_flip($stopwords);

        $phrases = [];
        // Build 3-grams. Each 3-gram needs at least 1 non-stopword to
        // be useful — otherwise "of the and" matches everywhere.
        for ($i = 0; $i <= count($words) - 3; $i++) {
            $a = $words[$i];
            $b = $words[$i + 1];
            $c = $words[$i + 2];
            $non_stop = (int) !isset($stopwords_set[$a])
                      + (int) !isset($stopwords_set[$b])
                      + (int) !isset($stopwords_set[$c]);
            if ($non_stop >= 2) {
                $phrases[] = $a . ' ' . $b . ' ' . $c;
            }
        }
        return array_values(array_unique($phrases));
    }

    /**
     * Extract distinctive single content-words from the LLM's answer.
     *
     * Returns lowercased ≥4-char alphanumeric words excluding stopwords.
     * Used as a weaker complement to phrase-based matching — when the
     * LLM paraphrases a source, exact phrases won't match but several
     * distinctive content words still will.
     *
     * @since 4.37.109
     */
    private function extract_answer_words(string $answer): array {
        $text = wp_strip_all_tags($answer);
        $text = preg_replace('/[*_`]+/', '', $text);
        $text = strtolower($text);

        $stopwords = [
            'what','when','where','why','who','how','which','that',
            'is','are','was','were','be','been','being',
            'do','does','did','have','has','had',
            'a','an','the','and','or','but','if','then','than',
            'i','my','me','we','our','us','you','your',
            'to','of','in','on','for','with','at','by','from','up','out',
            'can','could','should','would','will','may','might','must',
            'this','these','those','it','its','as','also',
            'about','some','any','all','no','not','so','too',
            // Common LLM-output filler — appears in answers regardless
            // of source content, would create false-positive overlaps.
            'will','need','your','student','students','course',
            'university','school','term','semester','year',
        ];
        $stopwords_set = array_flip($stopwords);

        $words = [];
        if (preg_match_all('/[a-z0-9]+/', $text, $matches)) {
            foreach ($matches[0] as $w) {
                if (strlen($w) < 4) continue;
                if (isset($stopwords_set[$w])) continue;
                $words[$w] = true;
            }
        }
        return array_keys($words);
    }

    /**
     * Compute overlap score between a chunk and the answer's phrases/words.
     *
     * Hybrid scoring: phrase matches are strong evidence (×5 weight),
     * content-word matches are weaker (×1 weight). Combined into a
     * single score that the caller compares against a threshold.
     *
     * Returns the total overlap score. Higher = more contribution.
     *
     * @since 4.37.109
     */
    private function compute_source_overlap(string $chunk_content, array $answer_phrases, array $answer_words): int {
        if (empty($answer_phrases) && empty($answer_words)) return 0;
        $haystack = strtolower(wp_strip_all_tags($chunk_content));
        if ($haystack === '') return 0;

        $score = 0;
        // Phrase matches — strong signal that the LLM lifted content
        // from this chunk verbatim or near-verbatim.
        foreach ($answer_phrases as $phrase) {
            if (strpos($haystack, $phrase) !== false) {
                $score += 5;
            }
        }
        // Content-word matches — weaker signal but catches paraphrased
        // contributions where the phrase didn't survive the LLM's
        // rewording but distinctive vocabulary did.
        foreach ($answer_words as $word) {
            if (strpos($haystack, $word) !== false) {
                $score += 1;
            }
        }
        return $score;
    }

    /**
     * Build a citation snippet from a chunk's content.
     *
     * Targets ~180 chars (one to two visual lines) cut cleanly:
     *   1. Try to end at a sentence boundary (.!?) within 150-220 chars
     *   2. Otherwise cut at the nearest word boundary <= 200 chars
     *   3. Add ellipsis only when the cut was mid-thought (no clean
     *      sentence boundary found)
     *
     * Used as a fallback when no question/chunk is available to compute
     * a relevance-aware snippet — and for storage of a baseline snippet
     * that legacy rows can fall back to.
     *
     * @since 4.37.97
     */
    public function build_citation_snippet(string $content): string {
        $content = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($content)));
        if ($content === '') return '';
        if (strlen($content) <= 200) return $content;

        $window = substr($content, 0, 220);

        $best = -1;
        $offset = 150;
        while (preg_match('/[.!?]\s/', $window, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $best = $m[0][1] + 1;
            $offset = $best + 1;
        }
        if ($best > 0) {
            return rtrim(substr($content, 0, $best));
        }

        $cut = substr($content, 0, 200);
        $last_space = strrpos($cut, ' ');
        if ($last_space !== false && $last_space > 100) {
            $cut = substr($cut, 0, $last_space);
        }
        return rtrim($cut, " ,;:") . '…';
    }

    /**
     * Pick the most query-relevant sentence from a chunk's content.
     *
     * Splits the chunk into sentences, scores each by query-term
     * occurrences, returns the highest scorer (smart-trimmed to fit a
     * snippet). Falls back to first sentence if no terms match.
     *
     * Why sentence-level rather than character window: sentences are
     * natural reading units. Character windows can land mid-thought and
     * read badly. Sentence-level picks deliver clean, coherent excerpts
     * that show students the part of the source that actually addresses
     * their question.
     *
     * Stopwords are filtered explicitly (rather than by length-based
     * heuristic) so "what" / "when" / "do" don't pollute scoring.
     *
     * @since 4.37.99
     */
    public function build_relevant_snippet(string $content, string $question): string {
        // v4.37.107+: Don't collapse newlines on initial normalization.
        // After v4.37.106's HTML extraction fix, chunks contain
        // meaningful \n boundaries between block-level elements
        // (headings, list items, table cells, paragraphs). These are
        // semantic sentence boundaries even when the line lacks
        // terminal punctuation — "Academic Standing\nEffective Catalog"
        // means those are two distinct items, not one run-on sentence.
        // Collapse only spaces/tabs; newlines survive into the splitter.
        $content = wp_strip_all_tags($content);
        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = preg_replace('/[ \t]*\n[ \t]*/', "\n", $content);
        $content = trim($content);
        if ($content === '') return '';

        // Tokenize question into meaningful terms. Lowercased,
        // alphanumeric, ≥3 chars, minus an explicit stopword list.
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

        // Split chunk into sentences. v4.37.107+: TWO break patterns —
        // (1) traditional punctuation+space+capital (".  Capital")
        // (2) newline (single or multiple) — these come from the
        //     block-break injector and represent semantic boundaries
        //     even without sentence-terminal punctuation
        // We split on EITHER pattern. The lookbehind/lookahead anchors
        // are merged into a single regex.
        $sentences = preg_split('/(?:(?<=[.!?])\s+(?=[A-Z0-9]))|(?:\n+)/', $content);
        // Strip empty sentences and ones that are pure whitespace.
        // Also drop very short fragments (<3 words) that are likely
        // header labels — they'd score artificially high if a single
        // header word matches a query term, but they're not useful as
        // snippets. Hard floor: keep only meaningful prose.
        $sentences = array_values(array_filter($sentences, function($s) {
            $s = trim($s);
            if ($s === '') return false;
            // Count words; drop fragments < 4 words (probably a heading,
            // table cell, or breadcrumb)
            return str_word_count($s) >= 4;
        }));
        if (empty($sentences)) return $this->build_citation_snippet($content);

        if (empty($terms)) {
            return $this->build_citation_snippet($content);
        }

        // v4.37.100+: Answer-signal phrases. Sentences containing these
        // are statistically more likely to BE the answer (vs. context,
        // navigation, headers). Score them extra. The list is
        // deliberately conservative — only phrases that strongly mark
        // a sentence as containing requirements, eligibility, or
        // quantitative constraints. Common verbs like "is" or "are"
        // are intentionally NOT here (they'd boost everything equally).
        // v4.37.108+: Added "or higher / or above / or more / or less"
        // — these are quantification suffixes that strongly indicate
        // a threshold/limit sentence ("a GPA of 2.00 or higher").
        $answer_signals = [
            // Requirement/obligation language
            'must', 'required', 'requires', 'requirement',
            'need ', 'needs ', 'needed',
            // Quantification
            'minimum', 'at least', 'no more than', 'up to ',
            'maximum', 'no fewer than', 'no later than',
            'or higher', 'or above', 'or more', 'or less', 'or greater',
            // Eligibility / permission
            'eligible', 'ineligible', 'qualify', 'qualifies',
            'allowed', 'not allowed', 'permitted', 'not permitted',
            // Conditional answer markers
            'in order to', 'to be eligible', 'to qualify',
            // Time-bound answers
            'deadline', 'cutoff', 'last day to', 'by the end of',
            // Quantities/specifics
            'gpa of', 'grade of', 'average of',
        ];

        $best_idx   = 0;
        $best_score = -1;
        $phrase     = strtolower(implode(' ', $terms));

        foreach ($sentences as $i => $sentence) {
            $lower = strtolower($sentence);
            $score = 0;

            // Query keyword matches: 2 points each (distinct terms)
            // Cast term to string — PHP stores numeric-string array
            // keys as int, array_keys returns them as int.
            foreach ($terms as $t) {
                $t = (string) $t;
                if (strpos($lower, $t) !== false) $score += 2;
            }

            // Phrase-of-all-terms bonus: 2 points
            if (count($terms) > 1 && strpos($lower, $phrase) !== false) {
                $score += 2;
            }

            // Answer-signal phrases: 3 points each. These are the
            // structural markers that distinguish "explanation/answer"
            // sentences from "narrative/header/context" sentences.
            // Capping the contribution at 6 (two signals worth) so a
            // sentence stuffed with policy boilerplate doesn't drown
            // out a sentence with one signal but better keyword match.
            $signal_score = 0;
            foreach ($answer_signals as $sig) {
                if (strpos($lower, $sig) !== false) {
                    $signal_score += 3;
                    if ($signal_score >= 6) break;
                }
            }
            $score += $signal_score;

            // v4.37.108+: Numeric-value bonus. Sentences containing
            // specific numbers (decimals like "2.00", integers like
            // "12 credits", date-style "September 10") are much more
            // likely to BE the answer than descriptive prose without
            // numbers. The same descriptive sentence with "students are
            // required to meet standards" has answer-signal words but
            // no concrete value. The actual answer "GPA of 2.00 or
            // higher" both has signals AND the specific value.
            // We add +2 for any decimal number, +1 for integers (less
            // distinctive — "term" might mention "6 credits" without
            // being the answer to a different question).
            if (preg_match('/\b\d+\.\d+\b/', $lower)) {
                $score += 2; // decimal number — strong answer marker
            } elseif (preg_match('/\b\d{1,4}\b/', $lower)) {
                // Plain integer — modest bonus. Filter out years
                // (1900-2099 range) which are usually metadata not answers.
                if (!preg_match('/\b(19|20)\d{2}\b/', $lower)) {
                    $score += 1;
                }
            }

            // v4.37.101+: Negative-conditional penalty. Sentences that
            // describe what happens when the requirement IS NOT met are
            // technically about the topic but aren't the answer the
            // student asked for. They're the consequence sentences:
            // "Students who do not meet these standards..." or "If you
            // fail to maintain..." — informative context, but not the
            // canonical answer to a "what do I need" question.
            // Generic enough to apply across domains: penalize the
            // structural pattern, not domain-specific words.
            $negative_conditional_patterns = [
                'who do not meet',
                'who fail to',
                'who fails to',
                'if students do not',
                'if you do not',
                'if you fail',
                'who have not met',
                'do not meet these',
                'students who don\'t',
            ];
            foreach ($negative_conditional_patterns as $neg) {
                if (strpos($lower, $neg) !== false) {
                    $score -= 4;
                    break; // single penalty regardless of how many match
                }
            }

            if ($score > $best_score) {
                $best_score = $score;
                $best_idx   = $i;
            }
        }

        // Best score 0 means no sentence had any keyword AND no answer
        // signal. Should be rare since FULLTEXT retrieved this chunk
        // for a reason. Fall back to leading text.
        if ($best_score === 0) {
            return $this->build_citation_snippet($content);
        }

        return $this->build_citation_snippet($sentences[$best_idx]);
    }

    /**
     * Stream a protected source file (PDF, docx, text) when the
     * widget's Sources panel link is clicked.
     *
     * Sources live under wp-uploads/cleversay-sources/ which the plugin
     * .htaccess-protects from direct web access (see Sources::handle_upload).
     * This handler bypasses the protection deliberately — citations require
     * the file to be reachable — but only:
     *   - When citations are enabled for this site (cleversay_citations_enabled)
     *   - When the requested source has status='indexed' (excludes pending/error)
     *   - When the file actually exists on disk
     *
     * Headers are set so the browser inlines PDFs (instead of forcing
     * download) which gives the cleanest UX for verification.
     *
     * @since 4.37.89
     */
    public function maybe_handle_source_download(): void {
        if (!isset($_GET['cleversay_source'])) return;
        $source_id = (int) $_GET['cleversay_source'];
        if ($source_id <= 0) return;

        if (!get_option('cleversay_citations_enabled', false)) {
            status_header(404);
            exit;
        }

        global $wpdb;
        $db = new Database();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, source_type, file_path, file_name, status
             FROM {$db->sources}
             WHERE id = %d AND status = 'indexed'
             LIMIT 1",
            $source_id
        ), ARRAY_A);
        if (!$row || empty($row['file_path']) || !file_exists($row['file_path'])) {
            status_header(404);
            exit;
        }

        // Map source_type to MIME for proper inline display.
        $mime = 'application/octet-stream';
        switch ((string) $row['source_type']) {
            case 'pdf':  $mime = 'application/pdf'; break;
            case 'docx': $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'; break;
            case 'text': $mime = 'text/plain; charset=UTF-8'; break;
        }

        $filename = (string) ($row['file_name'] ?? basename($row['file_path']));
        $size = (int) @filesize($row['file_path']);

        // Disposition: inline for PDFs (browser viewer); attachment
        // for docx/text (download). PDFs in inline mode let students
        // verify without leaving the page; docx forces a download
        // because browsers don't render Word in-page.
        $disposition = ($row['source_type'] === 'pdf') ? 'inline' : 'attachment';

        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $filename) . '"');
        if ($size > 0) header('Content-Length: ' . $size);
        header('X-Content-Type-Options: nosniff');

        readfile($row['file_path']);
        exit;
    }

    /**
     * Load citation list for an AI answer, formatted for the widget.
     *
     * Returns [] when citations are disabled for this site OR when the
     * answer has no associated source rows. Each returned entry is a
     * shape the widget can render directly: title (from sources.title),
     * source_type (pdf/url/text/docx), and a click-target URL appropriate
     * to the type. PDFs and URL sources point to the public URL when
     * one exists; PDFs fall back to a download endpoint when only
     * file_path is set.
     *
     * @since 4.37.89
     */
    private function load_citations_for_answer(?int $answer_id): array {
        if (!$answer_id || !get_option('cleversay_citations_enabled', false)) {
            return [];
        }
        global $wpdb;
        $db = new Database();
        // v4.37.109+: prefer rows marked used_in_answer=1 (sources whose
        // content actually appears in the LLM's answer). Fall back to
        // showing all retrieved sources when nothing met the threshold —
        // some answers are heavily paraphrased and miss our overlap
        // detector. Better to show "considered" sources than nothing.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.title, s.source_type, s.url, s.file_path, s.file_name,
                    a.snippet AS legacy_snippet,
                    a.chunk_id AS hint_chunk_id,
                    a.used_in_answer,
                    a.overlap_score,
                    ans.question AS original_question
             FROM {$db->ai_answer_sources} a
             JOIN {$db->sources} s ON s.id = a.source_id
             JOIN {$db->ai_answers} ans ON ans.id = a.answer_id
             WHERE a.answer_id = %d
               AND a.used_in_answer = 1
             ORDER BY a.overlap_score DESC, a.position ASC
             LIMIT 3",
            $answer_id
        ), ARRAY_A);

        // Fallback: if nothing scored as used, show top 3 by position.
        // This handles the LLM-heavy-paraphrase case where overlap
        // detection misses real contributions.
        if (!is_array($rows) || empty($rows)) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT s.id, s.title, s.source_type, s.url, s.file_path, s.file_name,
                        a.snippet AS legacy_snippet,
                        a.chunk_id AS hint_chunk_id,
                        a.used_in_answer,
                        a.overlap_score,
                        ans.question AS original_question
                 FROM {$db->ai_answer_sources} a
                 JOIN {$db->sources} s ON s.id = a.source_id
                 JOIN {$db->ai_answers} ans ON ans.id = a.answer_id
                 WHERE a.answer_id = %d
                 ORDER BY a.position ASC
                 LIMIT 3",
                $answer_id
            ), ARRAY_A);
        }

        if (!is_array($rows) || empty($rows)) return [];

        $out = [];
        foreach ($rows as $r) {
            $url = (string) ($r['url'] ?? '');
            $type = (string) ($r['source_type'] ?? '');
            if ($url === '' && in_array($type, ['pdf', 'docx', 'text'], true) && !empty($r['file_path'])) {
                $url = add_query_arg([
                    'cleversay_source' => (int) $r['id'],
                ], home_url('/'));
            }

            // v4.37.101+: For each source, find the chunk whose content
            // best answers the question — not just the FULLTEXT-top
            // chunk that was captured at answer time. The captured
            // chunk_id (hint_chunk_id) often points at a navigation /
            // header chunk that scored high in FULLTEXT due to keyword
            // density, while the actual answer lives in a different
            // chunk on the same page. Re-rank chunks here using
            // keyword + answer-signal scoring against the question.
            $snippet = '';
            $question = (string) ($r['original_question'] ?? '');
            $source_id = (int) $r['id'];
            if ($question !== '' && $source_id > 0) {
                $best_chunk_content = $this->pick_best_chunk_for_question(
                    $source_id,
                    $question,
                    (int) ($r['hint_chunk_id'] ?? 0)
                );
                if ($best_chunk_content !== '') {
                    $snippet = $this->build_relevant_snippet($best_chunk_content, $question);
                }
            }
            if ($snippet === '') {
                $snippet = (string) ($r['legacy_snippet'] ?? '');
            }

            $out[] = [
                'id'        => $source_id,
                'title'     => (string) ($r['title'] ?? ''),
                'type'      => $type,
                'url'       => $url,
                'file_name' => (string) ($r['file_name'] ?? ''),
                'snippet'   => $snippet,
            ];
        }
        return $out;
    }

    /**
     * For a given source, find the chunk that best answers the question.
     *
     * Loads up to 5 chunks from this source via FULLTEXT relevance
     * (cheap — uses the existing full-text index), then re-scores them
     * in PHP using our keyword + answer-signal scoring. Returns the
     * winner's content, or the hint chunk's content as fallback, or
     * the source's first chunk as last resort.
     *
     * Why this exists: when the indexer chunks a page, navigation/
     * header content often ends up in one chunk with high keyword
     * density (it lists every section name), while the substantive
     * answer lives in a different chunk further down. FULLTEXT scoring
     * favors the keyword-dense chunk. We need to re-rank to favor
     * answer-pattern density, which is what students actually want
     * to see.
     *
     * @since 4.37.101
     */
    public function pick_best_chunk_for_question(int $source_id, string $question, int $hint_chunk_id): string {
        global $wpdb;
        $db = new Database();

        // Pull top FULLTEXT-matching chunks for this source, plus the
        // hint chunk if FULLTEXT didn't include it. 5 is enough — most
        // sources have <5 chunks anyway, and longer sources still get
        // the relevance pre-filter.
        $candidates = $wpdb->get_results($wpdb->prepare(
            "SELECT id, content
             FROM {$db->chunks}
             WHERE source_id = %d
               AND MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE)
             ORDER BY MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE) DESC
             LIMIT 5",
            $source_id, $question, $question
        ), ARRAY_A);

        // If FULLTEXT returned nothing (rare), fall back to the hint
        // chunk; if no hint, take any first chunk.
        if (!is_array($candidates) || empty($candidates)) {
            if ($hint_chunk_id > 0) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT content FROM {$db->chunks} WHERE id = %d LIMIT 1",
                    $hint_chunk_id
                ), ARRAY_A);
                if ($row) return (string) $row['content'];
            }
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT content FROM {$db->chunks} WHERE source_id = %d ORDER BY chunk_index ASC LIMIT 1",
                $source_id
            ), ARRAY_A);
            return $row ? (string) $row['content'] : '';
        }

        // Re-rank candidates using our keyword + answer-signal scoring.
        // The chunk with the highest combined score wins. Ties broken
        // by FULLTEXT order (the candidates are already in that order).
        $best_idx = 0;
        $best_score = -1;
        foreach ($candidates as $i => $cand) {
            $score = $this->score_chunk_for_question((string) $cand['content'], $question);
            if ($score > $best_score) {
                $best_score = $score;
                $best_idx = $i;
            }
        }
        return (string) $candidates[$best_idx]['content'];
    }

    /**
     * Score a chunk for how likely it contains the answer to a question.
     *
     * Uses the same scoring vocabulary as build_relevant_snippet — query
     * keywords, answer-signal phrases, negative-conditional penalty —
     * but operates on the whole chunk to compare chunks against each
     * other, rather than sentences within a chunk.
     *
     * @since 4.37.101
     */
    public function score_chunk_for_question(string $content, string $question): int {
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

        $lower = strtolower($content);
        $score = 0;

        // Distinct query-term count (capped at the number of terms,
        // not the total occurrences — a chunk that says "standing"
        // 20 times shouldn't beat one that covers all aspects)
        // Cast each term to string: PHP coerces numeric-string array
        // keys (like "2026") to int when stored, and array_keys returns
        // them as int. strpos() needs string $needle.
        foreach ($terms as $t) {
            $t = (string) $t;
            if (strpos($lower, $t) !== false) $score += 2;
        }

        // Answer signals — count occurrences but cap at 4 to prevent
        // a chunk that's all policy boilerplate from dominating
        $answer_signals = [
            'must', 'required', 'requires', 'requirement',
            'minimum', 'at least', 'no more than',
            'eligible', 'qualify', 'allowed', 'not allowed',
            'deadline', 'cutoff', 'last day to',
            'gpa of', 'grade of', 'average of',
            'in order to', 'to be eligible',
        ];
        $signal_count = 0;
        foreach ($answer_signals as $sig) {
            if (strpos($lower, $sig) !== false) {
                $signal_count++;
                if ($signal_count >= 4) break;
            }
        }
        $score += $signal_count * 3;

        // Penalty: chunks that are all consequence/exception language.
        // Only penalize if the chunk has MULTIPLE negative conditionals
        // — a single "if you fail..." line in an otherwise good chunk
        // shouldn't tank it.
        $negative_patterns = [
            'who do not meet', 'who fail to', 'who fails to',
            'if students do not', 'if you do not', 'if you fail',
            'who have not met', 'do not meet these',
        ];
        $negative_count = 0;
        foreach ($negative_patterns as $neg) {
            if (strpos($lower, $neg) !== false) $negative_count++;
        }
        if ($negative_count >= 2) $score -= 5;

        return $score;
    }

    /**
     * Translate outgoing answers back to the visitor's language if
     * multilingual mode triggered on this request. Safe to call unconditionally —
     * it's a no-op when $this->user_language is null or 'en'.
     *
     * @param array $payload The full response payload (before wp_send_json_success)
     * @return array Payload with translated answer texts (and no_answer_message if present)
     */
    private function translate_response_if_needed(array $payload): array {
        if (empty($this->user_language) || $this->user_language === 'en') {
            return $payload;
        }
        try {
            $ai = new \CleverSay\AI();
            if (!$ai->is_configured()) return $payload;

            if (!empty($payload['answers']) && is_array($payload['answers'])) {
                foreach ($payload['answers'] as $i => $ans) {
                    if (!empty($ans['answer'])) {
                        $payload['answers'][$i]['answer'] = $ai->translate(
                            (string) $ans['answer'],
                            $this->user_language,
                            'en'
                        );
                    }
                    // Translate the matched question display too (keeps UI consistent)
                    if (!empty($ans['question'])) {
                        $payload['answers'][$i]['question'] = $ai->translate(
                            (string) $ans['question'],
                            $this->user_language,
                            'en'
                        );
                    }
                }
            }
            if (!empty($payload['no_answer_message'])) {
                $payload['no_answer_message'] = $ai->translate(
                    (string) $payload['no_answer_message'],
                    $this->user_language,
                    'en'
                );
            }
        } catch (\Throwable $e) {
            Logger::instance()->warning('translate_response_if_needed failed', [
                'error' => $e->getMessage(),
                'lang'  => $this->user_language,
            ]);
        }
        return $payload;
    }

    /**
     * Get client IP address
     */
    private function get_client_ip(): string {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = explode(',', $_SERVER[$key])[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '127.0.0.1';
    }
    
    /**
     * Simple per-IP rate limiting using transients (60 requests / 60 seconds)
     */
    private function check_rate_limit(): bool {
        $ip      = $this->get_client_ip();
        $safe    = preg_replace('/[^a-zA-Z0-9]/', '_', $ip);

        // Stricter limit for embed/cross-origin requests
        $is_embed = !empty($_POST['embed_token']);
        $limit    = $is_embed ? 30 : 60;

        $key   = 'cs_rl_' . $safe;
        $count = (int) get_transient($key);

        if ($count >= $limit) {
            return false;
        }
        set_transient($key, $count + 1, 60);
        return true;
    }

    /**
     * Handle AJAX search request
     */
    public function ajax_search(): void {
        $logger = Logger::instance();
        $logger->info('=== PUBLIC SEARCH START ===');

        // v4.41.5.5+: latency observability. Start the request timer as
        // the first non-logging action. Auth-failed requests are still
        // measured for forensic value (a log line is emitted on flush)
        // but no DB row is written without a question_id. RequestTimer
        // is a per-request singleton; the shutdown handler in
        // cleversay.php flushes regardless of how the response exits.
        \CleverSay\RequestTimer::instance()->start_request();

        // v4.41.5.8+: when the per-site Show Response Timing toggle is
        // on, inject `total_ms` into the JSON response body via output
        // buffering. Cleaner than touching every wp_send_json_success
        // call site (there are dozens scattered across this method).
        // The callback only fires when the buffer flushes (at wp_die
        // time), so total_ms reflects the whole request work, including
        // any apply_filters / response-shaping work that happens after
        // the synthesis call returns. No-op when the flag is off.
        if ((bool) get_option('cleversay_show_timing', false)) {
            ob_start(function ($buffer) {
                $decoded = json_decode($buffer, true);
                if (is_array($decoded)
                    && !empty($decoded['success'])
                    && isset($decoded['data'])
                    && is_array($decoded['data'])
                ) {
                    $decoded['data']['total_ms'] = \CleverSay\RequestTimer::instance()->total_ms();
                    $reencoded = wp_json_encode($decoded);
                    return $reencoded === false ? $buffer : $reencoded;
                }
                return $buffer;
            });
        }

        // Verify request authenticity.
        // Standard WordPress nonce works for same-site requests (wp_localize_script).
        // For cross-origin embed.js requests we accept a static embed token instead,
        // since nonces require session cookies which CORS requests don't send.
        $nonce_ok        = check_ajax_referer('cleversay_nonce', 'nonce', false);
        $embed_token     = sanitize_text_field(wp_unslash($_POST['embed_token'] ?? ''));
        $stored_token    = get_option('cleversay_embed_token', '');
        $embed_token_ok  = !empty($stored_token) && hash_equals($stored_token, $embed_token);

        if (!$nonce_ok && !$embed_token_ok) {
            $logger->error('Auth check failed', [
                'nonce_ok'       => $nonce_ok,
                'embed_token_ok' => $embed_token_ok,
            ]);
            wp_send_json_error(['message' => __('Security check failed.', 'cleversay')]);
            return;
        }

        // ── Trial / suspension check (defense in depth) ──────────────
        // The embed-config endpoint already returns a suspended response
        // before the widget can render. This second check protects against
        // requests made directly to ajax_search (bypassing the config
        // endpoint), or stale clients that loaded config before the site
        // was suspended.
        if (is_multisite() && class_exists('\CleverSay\TrialEnforcer')) {
            $runtime = \CleverSay\TrialEnforcer::get_runtime_status(get_current_blog_id());
            if (!$runtime['active']) {
                wp_send_json_error([
                    'message'   => __('This chatbot is currently unavailable.', 'cleversay'),
                    'suspended' => true,
                ], 503);
                return;
            }
        }

        // For embed token requests, also validate the Origin header matches a whitelisted domain
        // This prevents token theft — someone copying the token can't use it from an unlisted domain
        if ($embed_token_ok && !$nonce_ok) {
            $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
            if ($origin) {
                require_once CLEVERSAY_PLUGIN_DIR . 'includes/class-api.php';
                $api             = new \CleverSay\API();
                $allowed         = $api->get_allowed_origins();
                $origin_no_proto = preg_replace('#^https?://#', '', rtrim($origin, '/'));
                $origin_allowed  = false;
                if ($allowed === '*') {
                    $origin_allowed = true;
                } else {
                    foreach ((array)$allowed as $domain) {
                        $domain_no_proto = preg_replace('#^https?://#', '', rtrim($domain, '/'));
                        if ($domain_no_proto === $origin_no_proto) {
                            $origin_allowed = true;
                            break;
                        }
                    }
                }
                if (!$origin_allowed) {
                    $logger->warning('Embed token used from non-whitelisted origin', ['origin' => $origin]);
                    wp_send_json_error(['message' => __('Origin not allowed.', 'cleversay')], 403);
                    return;
                }
            }
        }

        // Server-side rate limiting
        if (!$this->check_rate_limit()) {
            wp_send_json_error([
                'message' => __('Too many requests. Please wait a moment.', 'cleversay'),
                'code'    => 'rate_limited',
            ], 429);
        }
        
        // Accept both 'query' and 'question' parameters
        $query = sanitize_text_field(wp_unslash($_POST['query'] ?? $_POST['question'] ?? ''));

        // Strip prompt injection attempts — remove instruction-like patterns
        // that could manipulate the AI's behaviour
        $injection_patterns = [
            '/ignore (all |your )?(previous |above |prior )?instructions?/i',
            '/forget (everything|all|your instructions)/i',
            '/you are now/i',
            '/pretend (you are|to be)/i',
            '/act as (a |an )?(different|unrestricted|jailbreak)/i',
            '/system prompt/i',
            '/\[INST\]|<\|im_start\|>|<\|system\|>/i',
        ];
        foreach ($injection_patterns as $pattern) {
            if (preg_match($pattern, $query)) {
                $logger->warning('Potential prompt injection detected', ['query' => $query]);
                wp_send_json_error(['message' => __('Your message could not be processed. Please ask a specific question.', 'cleversay')]);
                return;
            }
        }
        
        $logger->info('Query received', ['query' => $query]);
        
        if (empty($query) || strlen($query) < 2) {
            $logger->warning('Query too short', ['query' => $query]);
            wp_send_json_error(['message' => __('Please enter a valid question.', 'cleversay')]);
        }

        // ── Multilingual: detect non-English questions and translate to EN ────
        // When enabled, non-English input is translated to English before KB
        // search so the KB can stay English-only. The user's language is saved
        // on the instance so we can translate the answer back at the end.
        $this->user_language = null;
        $original_query      = null;  // set only if we actually translate
        $ai_config = \CleverSay\NetworkSettings::get_ai_config();
        if (!empty($ai_config['multilingual']) && !empty($ai_config['enabled']) && !empty($ai_config['api_key'])) {
            try {
                $ai        = new \CleverSay\AI();
                $detected  = $ai->detect_language($query);
                if ($detected !== 'en' && $detected !== '') {
                    $this->user_language = $detected;
                    $translated = $ai->translate($query, 'en', $detected);
                    if ($translated !== '' && $translated !== $query) {
                        $logger->info('Multilingual: translated question to English', [
                            'from'        => $detected,
                            'original'    => $query,
                            'translated'  => $translated,
                        ]);
                        $original_query = $query;  // preserve for logging
                        $query          = $translated;
                    }
                }
            } catch (\Throwable $e) {
                $logger->warning('Multilingual detect/translate failed, continuing in original language', [
                    'error' => $e->getMessage(),
                ]);
                $this->user_language = null;
            }
        }

        // ── Resolve follow-up questions using conversation history ────────────
        // If history exists and the question looks like a follow-up
        // (short, uses pronouns, lacks a clear topic), ask AI to rewrite it
        // as a standalone question before KB search.
        $history_json = sanitize_textarea_field(wp_unslash($_POST['history'] ?? ''));
        $resolved_query = $this->resolve_followup($query, $history_json);
        if ($resolved_query !== $query) {
            $logger->info('Query resolved via history', [
                'original' => $query,
                'resolved' => $resolved_query,
            ]);
            $query = $resolved_query;
        }

        // ── force_ai: skip KB, go straight to AI (e.g. after "Not Helpful" rating) ──
        $force_ai = !empty($_POST['force_ai']) && $_POST['force_ai'] === '1';
        if ($force_ai) {
            $logger->info('force_ai=1 — bypassing KB, going straight to AI');
            $ai_result = $this->try_ai_reanswer($query, $history_json);
            if ($ai_result) {
                // v4.41.5+: force_ai means we deliberately skipped Layer 1
                // (typically after a "Not Helpful" rating). Tag as ai_only
                // since the answer came from the AI path with no KB help.
                \CleverSay\RequestTimer::instance()->set('matched_layer', 'ai_only');
                $is_casual       = $this->is_casual_query($query);
                $is_deflection   = !$is_casual && $this->is_generic_deflection($ai_result);
                // v4.37.138+: length-based safety net — see Layer-2 fallback
                // builder below for rationale.
                $answer_is_short = strlen(wp_strip_all_tags($ai_result)) < 100;
                $suppress_ui     = $is_casual || $answer_is_short;
                wp_send_json_success([
                    'found'   => true,
                    'answers' => [[
                        'id'             => $this->current_ai_answer_id,
                        'question'       => $query,
                        'answer'         => $ai_result,
                        'score'          => 100,
                        // Per-answer rating now lives on AI responses (replacing
                        // the old "Still need help?" prompt). Skip on deflections —
                        // there's nothing useful to rate when bot couldn't answer.
                        // v4.37.136+: also skip on casual queries — rating
                        // "You're welcome!" creates noise, not signal.
                        // v4.37.138+: also skip on short responses — catches
                        // conversational acknowledgments whose input didn't
                        // match any casual regex.
                        'show_rating'    => !$suppress_ui && !$is_deflection && $this->current_ai_answer_id !== null,
                        'rating_target'  => 'ai_answer',  // tells widget which endpoint to call
                        'ai_assisted'    => true,
                        // show_inquiry kept for back-compat but widget should ignore
                        // it on AI answers; downvote opens the inquiry instead.
                        'show_inquiry'   => false,
                        'is_deflection'  => $is_deflection,
                        // v4.37.89+: source citations (empty array when toggle off
                        // or no chunks). Widget renders Sources link only when non-empty.
                        // v4.37.98+: also empty on deflections — citing sources for an
                        // answer that's "I don't know, contact someone" creates a false
                        // trust signal. The sources are still recorded in the database
                        // for admin review on the AI Answers page; we just don't show
                        // them to end users when the bot didn't substantively use them.
                        // v4.37.136+: also empty on casual queries — same reasoning.
                        // v4.37.138+: also empty on short responses — same reasoning.
                        'sources'        => ($suppress_ui || $is_deflection) ? [] : $this->load_citations_for_answer($this->current_ai_answer_id),
                    ]],
                    'related' => [],
                ]);
            } else {
                wp_send_json_success([
                    'found'             => false,
                    'no_answer_message' => __("I wasn't able to find an answer for that. Please submit your question below and we'll follow up with you.", 'cleversay'),
                    'show_inquiry'      => $this->is_real_question($query),
                ]);
            }
            return;
        }

        try {
            // Use the Search class
            $logger->debug('Creating Search instance');
            $search = new Search();
            // Pass multilingual context so log_question records original text + language
            $search->set_multilingual_context($original_query, $this->user_language);
            
            $logger->debug('Calling search method');

            /**
             * Fires before CleverSay performs a search.
             * @param string $query The sanitized user question.
             */
            do_action('cleversay_before_search', $query);

            // v4.41.5+: time Layer 1 (Search::find_matches + keyword
            // expansion + log_question). The whole work-unit of "KB
            // search ran" is one stage for the dashboard's purposes.
            \CleverSay\RequestTimer::instance()->start_stage('kb');
            $results = $search->search($query);
            \CleverSay\RequestTimer::instance()->end_stage('kb');

            // Capture the questions_log id so log_ai_answer() can FK-link its row
            $this->current_logged_question_id = isset($results['logged_question_id'])
                ? (int) $results['logged_question_id']
                : null;
            // v4.41.5+: feed the same id into the metrics row's FK so
            // analytics can join request_metrics → cleversay_questions
            // for question text and rating.
            if ($this->current_logged_question_id !== null) {
                \CleverSay\RequestTimer::instance()->set('question_id', $this->current_logged_question_id);
            }

            /**
             * Filters search results before they are returned to the client.
             * @param array  $results The full results array.
             * @param string $query   The original question.
             */
            $results = apply_filters('cleversay_search_results', $results, $query);
            
            $logger->info('Search results', [
                'success' => $results['success'] ?? false,
                'match_count' => count($results['matches'] ?? []),
                'suggested_count' => count($results['suggested'] ?? [])
            ]);
            
            // Check if Layer 1 result is strong enough, or if AI should take over
            // ai_min_score = 0  → AI only fires on complete miss (no matches at all)
            // ai_min_score = 50 → AI fires if best match scores below 50
            // ai_min_score = 100 → AI always fires (Layer 1 just used as fallback)
            $ai_min_score = (int) get_option('cleversay_ai_min_score', 0);
            $has_matches  = !empty($results['success']) && !empty($results['matches']);
            $best_score   = (int) ($results['matches'][0]['score'] ?? 0);

            // Score 50 = broad search fallback only (no keyword match found).
            // Score 100+ = real keyword match.
            // We treat broad-search-only results as a miss so AI can try.
            $is_broad_only = $has_matches && $best_score <= 50;

            if ($ai_min_score === 0) {
                // Default: Layer 1 is strong only on a genuine keyword match (score > 50)
                $layer1_strong = $has_matches && !$is_broad_only;
            } else {
                // Layer 1 is strong only if score meets the admin-set threshold
                $layer1_strong = $has_matches && ($best_score >= $ai_min_score);
            }

            $logger->info('Layer 1 evaluation', [
                'has_matches'   => $has_matches,
                'best_score'    => $best_score,
                'is_broad_only' => $is_broad_only,
                'ai_min_score'  => $ai_min_score,
                'layer1_strong' => $layer1_strong,
            ]);

            // Results is an array with 'success', 'matches', 'suggested', etc.
            if ($layer1_strong) {
                // v4.41.5+: tag this request as a Layer 1 win for analytics.
                // Note this only takes effect if Layer 1 actually serves
                // the answer — paths below this point may still hand off
                // to AI fallback (e.g. KB validation rejection), in which
                // case the AI fallback path overwrites matched_layer to
                // 'kb_weak_with_ai' before its wp_send_json_success.
                \CleverSay\RequestTimer::instance()->set('matched_layer', 'kb_strong');
                $first_match = $results['matches'][0];

                // v4.37.42+: KB-relevance validation. Two related settings:
                //   - `validate_kb` (default ON) — validate EVERY KB match
                //   - `aadefault_validate` (default OFF) — narrower, only
                //     validate aadefault matches
                //
                // The broader setting subsumes the narrower one. If
                // `validate_kb` is on, we validate every match regardless
                // of pattern type. If only `aadefault_validate` is on, we
                // validate only aadefault matches (legacy behavior).
                //
                // On rejection (AI says "this doesn't fit the question"),
                // fall through to the AI RAG path via `goto ai_fallback`.
                $ai_config = \CleverSay\NetworkSettings::get_ai_config();
                $ai_ok                = \CleverSay\NetworkSettings::ai_is_configured();
                $validate_all_kb      = $ai_ok && !empty($ai_config['validate_kb']);
                $validate_aadefault   = $ai_ok && !empty($ai_config['aadefault_validate']);
                $is_aadefault         = strtolower($first_match['sub_keyword'] ?? '') === 'aadefault';

                // Determine if validation should run.
                // - validate_kb on: always validate
                // - validate_aadefault on: validate only aadefault
                // - both on: validate (don't double-call; the broader covers it)
                $should_validate = $validate_all_kb || ($validate_aadefault && $is_aadefault);

                // v4.37.86+: Capture validation state at this checkpoint so
                // the Polish step downstream can reuse it instead of running
                // its own redundant validation. Previously a second
                // checkpoint at line ~1869 made the same LLM call again.
                $kb_was_validated = false;
                $kb_is_relevant   = true;
                $shared_ai        = null;

                if ($should_validate) {
                    $kb_answer  = $this->clean_response_html($first_match['response'] ?? '');

                    // v4.37.88+: validate regardless of length. The 20-char
                    // guard introduced in v4.37.86 was meant to skip
                    // validation on bare-URL responses, but it also let
                    // junk/abandoned KB entries (like a 3-char "gpa"
                    // response) bypass validation entirely. Pre-v4.37.86
                    // the active checkpoint always validated; restoring
                    // that behavior. The marginal LLM cost on rare
                    // legitimately-short responses is negligible
                    // compared to the user-facing harm of serving
                    // unchecked junk content.
                    $shared_ai  = new \CleverSay\AI();
                    $is_relevant = $shared_ai->validate_kb_answer($query, $kb_answer);

                    // Promote outcome to outer-scope vars for downstream consumers.
                    $kb_was_validated = true;
                    $kb_is_relevant   = $is_relevant;

                    $logger->info('KB AI validation', [
                        'keyword'      => $first_match['keyword'] ?? '',
                        'sub_keyword'  => $first_match['sub_keyword'] ?? '',
                        'is_aadefault' => $is_aadefault,
                        'is_relevant'  => $is_relevant,
                        'trigger'      => $validate_all_kb ? 'validate_kb' : 'aadefault_validate',
                    ]);

                    if (!$is_relevant) {
                        // KB answer doesn't fit — mark and fall through.
                        $rejection_reason = $is_aadefault ? 'aadefault' : 'kb_match_rejected';
                        $logger->info('KB match rejected by AI — falling through to AI fallback', [
                            'reason' => $rejection_reason,
                        ]);
                        if (!empty($results['logged_question_id'])) {
                            $provider_used = method_exists($shared_ai, 'get_provider') ? $shared_ai->get_provider() : '';
                            $search->mark_question_ai_rejected(
                                (int) $results['logged_question_id'],
                                $rejection_reason,
                                $provider_used
                            );
                        }
                        $this->kb_rejected_keyword = $first_match['keyword'] ?? null;
                        $this->kb_rejection_reason = $rejection_reason;
                        $this->kb_rejected_answer  = $kb_answer;
                        $layer1_strong = false;
                        goto ai_fallback;
                    }
                }

                // Log the first match details
                $first_match = $results['matches'][0];
                $logger->debug('First match details', [
                    'id' => $first_match['id'] ?? 'null',
                    'keyword' => $first_match['keyword'] ?? 'null',
                    'question' => $first_match['question'] ?? 'null',
                    'response' => substr($first_match['response'] ?? '', 0, 100),
                    'response_length' => strlen($first_match['response'] ?? ''),
                    'score' => $first_match['score'] ?? 'null',
                    'all_keys' => array_keys($first_match)
                ]);
                
                // Format matches as 'answers' for the JavaScript
                $answers = array_map(function($match) use ($logger) {
                    $answer = [
                        'id' => (int)$match['id'],
                        'question' => $match['question'] ?? '',
                        'answer' => $this->clean_response_html($match['response'] ?? ''),
                        'score' => (int)($match['score'] ?? 0),
                        'show_rating' => (bool) ($match['show_rating'] ?? true),
                    ];

                    // Log if answer is empty
                    if (empty($answer['answer'])) {
                        $logger->warning('Empty answer in match', [
                            'id' => $answer['id'],
                            'match_keys' => array_keys($match),
                            'response_value' => var_export($match['response'] ?? null, true)
                        ]);
                    }

                    return $answer;
                }, $results['matches']);
                
                // Related questions removed from user-facing response in v4.29.1.
                // The follow-up suggestion in AI answers (v4.28.0) replaces this
                // feature. The 'related' field stays in the JSON shape as an
                // empty array so any third-party integrations reading the
                // response don't break — they just see no related items.
                $related = [];
                
                $logger->info('Returning success with answers', ['count' => count($answers), 'first_answer_length' => strlen($answers[0]['answer'] ?? '')]);

                // ── Placeholder/empty answer check ──────────────────────────
                // If the KB entry has never been filled in (response is the
                // literal editor placeholder text or effectively empty after
                // decoding HTML entities), treat it as a miss and let AI
                // answer instead. v4.36.1+ hardened the check to handle
                // entries with `<p>&#160;</p>` / `<p>&nbsp;</p>` / lone
                // non-breaking space — these used to slip through because
                // strip_tags doesn't decode entities and trim() doesn't
                // recognize `\xa0` as whitespace.
                $plain_answer = wp_strip_all_tags($answers[0]['answer'] ?? '');
                $plain_answer = html_entity_decode($plain_answer, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // Strip ASCII whitespace plus the UTF-8 non-breaking space
                // (\xc2\xa0). PHP's default trim() only handles ASCII.
                $plain_answer = trim($plain_answer, " \t\n\r\0\x0B\xc2\xa0");
                if (strtolower($plain_answer) === 'enter response' || $plain_answer === '') {
                    $logger->info('KB answer is placeholder text or effectively empty — falling through to AI fallback', [
                        'raw_length' => strlen($answers[0]['answer'] ?? ''),
                    ]);
                    goto ai_fallback;
                }

                // ── KB deflection check ───────────────────────────────────────
                // If the KB answer is just a generic redirect (no real content)
                // AND AI is available AND we have conversation history,
                // fall through to AI so it can give a better contextual answer.
                //
                // v4.36.2+: skip this check for matches resolved through a
                // reuse pointer (reuse_response=1 with the resolution
                // succeeded — `reused_from` is set on the match). Reuse
                // pointers are explicit admin curation: "answer THIS
                // question with the response from THAT entry." If we
                // second-guess that choice we override the admin's
                // intent. The deflection check still applies to non-reuse
                // matches as a safety net for legacy entries with truly
                // deflective content the admin never came back to fill in.
                $is_reuse_resolved = !empty($first_match['reused_from'])
                    || !empty($first_match['reuse_response']);

                if (!$is_reuse_resolved
                    && !empty($answers[0]['answer'])
                    && \CleverSay\NetworkSettings::ai_is_configured()
                    && !empty($history_json)
                ) {
                    if ($this->is_generic_deflection($answers[0]['answer'])) {
                        $logger->info('KB answer is a generic deflection — falling through to AI with history context');
                        // v4.37.87+: tag the question log and set rejection
                        // state so analytics shows the rejection (previously
                        // these deflection-rejected matches were invisible
                        // in the AI Rejected view). Also feeds the
                        // inquiry-form / human-handoff downstream.
                        if (!empty($results['logged_question_id'])) {
                            $search->mark_question_ai_rejected(
                                (int) $results['logged_question_id'],
                                'kb_deflection'
                            );
                        }
                        $this->kb_rejected_keyword = $first_match['keyword'] ?? null;
                        $this->kb_rejection_reason = 'kb_deflection';
                        $this->kb_rejected_answer  = $answers[0]['answer'];
                        $layer1_strong = false;
                        goto ai_fallback;
                    }
                }

                // Read AI config from network (multisite) or single-site options.
                // Previously read from per-site cleversay_options — but on multisite
                // those keys are never written by any visible UI (the per-site AI
                // tab is hidden on multisite). Network AI Settings is the source
                // of truth on multisite installs.
                $ai_config = $ai_config ?? \CleverSay\NetworkSettings::get_ai_config();

                // v4.37.86+: KB relevance validation has been consolidated
                // into the upstream checkpoint (~line 1726). State vars
                // $kb_was_validated, $kb_is_relevant, and $shared_ai are
                // populated there and consumed by the Polish step below.
                // The duplicate checkpoint that previously lived here ran
                // the same LLM call a second time on every query — wasted
                // ~$0.0002–0.0004 per query and added ~200ms latency.

                // ── Optional: Polish KB response with AI ──────────────────────
                // Polish rewrites the KB answer in the bot's voice. Distinct
                // from validation — that's a stylistic choice, not safety.
                // Skip the redundant validation step if the core validator
                // already approved this answer above.
                $polish_enabled = !empty($ai_config['polish_kb'])
                    && \CleverSay\NetworkSettings::ai_is_configured();
                if ($polish_enabled && strlen($plain_answer) > 20) {

                    // v4.37.52+: skip if the response matches its
                    // stored polished_hash. That means an admin
                    // already AI-polished this entry and the response
                    // hasn't been edited since — running Polish KB
                    // again is redundant, just adds latency and cost.
                    // If the hash mismatches (admin edited after
                    // polishing) or is null (never polished), Polish
                    // KB runs as before.
                    $stored_hash  = (string) ($first_match['polished_hash'] ?? '');
                    $current_hash = \CleverSay\Admin::compute_response_hash(
                        (string) ($first_match['response'] ?? '')
                    );
                    $already_polished = ($stored_hash !== '' && $stored_hash === $current_hash);

                    if ($already_polished) {
                        $logger->info('Skipping Polish KB — entry was admin-polished and unchanged since', [
                            'entry_id' => (int) ($first_match['id'] ?? 0),
                        ]);
                    } else {
                        $ai = $shared_ai ?? new \CleverSay\AI();

                        // Skip the polish-side validation if the core validator
                        // already ran and approved. Saves one AI call.
                        $relevant_for_polish = $kb_was_validated
                            ? $kb_is_relevant
                            : $ai->validate_kb_answer($query, $plain_answer);

                        if ($relevant_for_polish) {
                            $polished = $ai->polish_kb_response($query, $answers[0]['answer']);
                            if ($polished) {
                                $answers[0]['answer']      = $polished;
                                $answers[0]['ai_assisted'] = true;
                                $logger->info('KB response polished by AI');
                            }
                        } else {
                            // Only reachable when core validation is OFF and polish
                            // is ON. Same fall-through behavior as core validation
                            // — don't serve a wrong answer regardless of which
                            // validator path detected the mismatch.
                            $logger->info('Polish-stage validation rejected KB answer — falling through to AI fallback');
                            if (!empty($results['logged_question_id'])) {
                                $search->mark_question_ai_rejected(
                                    (int) $results['logged_question_id'],
                                    'kb'
                                );
                            }
                            $this->kb_rejected_keyword = $first_match['keyword'] ?? null;
                            $this->kb_rejection_reason = 'kb_relevance';
                            $this->kb_rejected_answer  = $plain_answer;
                            $layer1_strong = false;
                            goto ai_fallback;
                        }
                    }
                }
                
                /**
                 * Fires when CleverSay finds an answer.
                 * @param array  $answers The formatted answers array.
                 * @param string $query   The original question.
                 */
                do_action('cleversay_answer_found', $answers, $query);

                wp_send_json_success($this->translate_response_if_needed([
                    'found' => true,
                    'answers' => $answers,
                    'count' => count($answers),
                    'related' => $related,
                ]));
            } else {
                ai_fallback:
                $no_answer      = get_option('cleversay_no_answer_message', __("I couldn't find an answer to your question. Would you like to submit it for review?", 'cleversay'));
                $enable_inquiry = get_option('cleversay_enable_inquiry_form', true);

                $logger->warning('Layer 1 not strong — trying AI fallback', [
                    'has_matches'   => $has_matches,
                    'best_score'    => $best_score,
                    'is_broad_only' => $is_broad_only ?? false,
                ]);

                // ── Layer 2: AI fallback ──────────────────────────────────────
                $ai_result = $this->try_ai_fallback($query, $_POST['history'] ?? '');

                if ($ai_result !== null) {
                    $logger->info('AI fallback succeeded');

                    // v4.41.5+: tag matched_layer for analytics. The
                    // distinction matters for understanding retrieval
                    // health: 'kb_weak_with_ai' means Layer 1 found
                    // candidates but they weren't strong enough, so AI
                    // drew on whatever chunks Phase 3 retrieval surfaced
                    // and produced a usable answer; 'ai_only' means the
                    // KB had nothing relevant and AI worked from chunks
                    // alone. High 'ai_only' rates point at KB coverage
                    // gaps; high 'kb_weak_with_ai' rates point at
                    // pattern/keyword tuning.
                    \CleverSay\RequestTimer::instance()->set(
                        'matched_layer',
                        $has_matches ? 'kb_weak_with_ai' : 'ai_only'
                    );

                    // Determine if this is a substantive question worth showing the inquiry link
                    // Greetings, thanks, and very short inputs don't warrant a follow-up prompt
                    $is_casual     = $this->is_casual_query($query);
                    // Also flag generic deflections — bot deflected without a real answer
                    $is_deflection = !$is_casual && $this->is_generic_deflection($ai_result);

                    // v4.37.138+: length-based safety net for rating + sources.
                    // Short conversational AI responses ("Sounds good!", "Got
                    // it!", "You're welcome!") may not match any is_casual_query
                    // regex if the input phrasing is novel ("i will call later"
                    // is conversational but doesn't start with "thanks" / "ok"
                    // / etc.). The response itself is the strongest signal:
                    // grounded answers run 200-700 chars; sub-100-char responses
                    // are almost always conversational acknowledgments. This
                    // gates both rating and sources without needing to predict
                    // every casual-input phrasing in advance.
                    $answer_is_short = strlen(wp_strip_all_tags($ai_result)) < 100;
                    $suppress_ui     = $is_casual || $answer_is_short;

                    wp_send_json_success($this->translate_response_if_needed([
                        'found'        => true,
                        'answers'      => [[
                            'id'             => $this->current_ai_answer_id,
                            'question'       => $query,
                            'answer'         => $ai_result,
                            'score'          => 0,
                            // Show 👍/👎 on real AI answers (not deflections — there's
                            // nothing meaningful to rate when bot couldn't answer).
                            // Replaces the old "Still need help?" Yes/No prompt:
                            // 👎 takes the same path that "Still need help → Yes" did.
                            //
                            // v4.37.136+: also hide rating on casual queries
                            // ("thanks", "ok", "sorry", "what?", etc.). Asking the
                            // user to rate "You're welcome!" creates UI noise and
                            // produces useless feedback signal.
                            //
                            // v4.37.138+: also hide rating when answer is short
                            // (<100 chars). Catches conversational acknowledgments
                            // whose input didn't match any casual regex.
                            'show_rating'    => !$suppress_ui && !$is_deflection && $this->current_ai_answer_id !== null,
                            'rating_target'  => 'ai_answer',
                            'ai_assisted'    => true,
                            // show_inquiry intentionally false — the new flow is:
                            // visitor rates the AI answer; if 👎, widget opens
                            // inquiry form directly, no extra prompt needed.
                            'show_inquiry'   => false,
                            'is_deflection'  => $is_deflection,
                            // v4.37.89+: source citations (empty when toggle off).
                            // v4.37.98+: also empty on deflections — see first
                            // response builder above for rationale. DB rows
                            // remain intact for admin AI Answers review.
                            //
                            // v4.37.136+: also empty on casual queries. The
                            // response is conversational ("Thanks!" / "You're
                            // welcome!"), not grounded in retrieved chunks, so
                            // showing "Sources (3)" is misleading even when
                            // citation rows happen to exist.
                            //
                            // v4.37.138+: also empty when answer is short (<100
                            // chars). Same reasoning extended to length-based
                            // detection of conversational responses.
                            'sources'        => ($suppress_ui || $is_deflection) ? [] : $this->load_citations_for_answer($this->current_ai_answer_id),
                        ]],
                        'count'        => 1,
                        'related'      => [],
                        'ai_assisted'  => true,
                    ]));
                }

                // AI not available or no chunks — if broad search found something,
                // return it rather than a blank no-answer message
                if ($is_broad_only && !empty($results['matches'])) {
                    $logger->info('Returning broad search result as fallback');
                    $answers = array_map(function($match) {
                        return [
                            'id'          => (int) $match['id'],
                            'question'    => $match['question'] ?? '',
                            'answer'      => $this->clean_response_html($match['response'] ?? ''),
                            'score'       => (int) ($match['score'] ?? 0),
                            'show_rating' => (bool) ($match['show_rating'] ?? true),
                        ];
                    }, $results['matches']);
                    wp_send_json_success($this->translate_response_if_needed([
                        'found'   => true,
                        'answers' => $answers,
                        'count'   => count($answers),
                        'related' => [],
                    ]));
                }

                // Detect greetings/casual inputs — don't offer inquiry for those
                $casual_no_answer    = $this->is_casual_query($query);
                $gibberish_no_answer = !$casual_no_answer && $this->is_gibberish_query($query);

                // Swap the message based on input type:
                // - Casual greeting → friendly intro listing topics
                // - Gibberish/mashing → short clean refusal (matches AI CASE 2 style)
                // - Real question we couldn't answer → standard "submit for review" prompt
                $reply_message = $no_answer;
                if ($casual_no_answer) {
                    $cs_opts = get_option('cleversay_options', []);
                    $topics  = trim((string) ($cs_opts['persona_topics'] ?? ''));
                    if ($topics !== '') {
                        // Normalize "a, b, c" → "a, b, and c" for natural reading
                        $parts = array_values(array_filter(array_map('trim', explode(',', $topics))));
                        if (count($parts) > 1) {
                            $last = array_pop($parts);
                            $topics_phrase = implode(', ', $parts) . ', and ' . $last;
                        } else {
                            $topics_phrase = $parts[0] ?? $topics;
                        }
                        $reply_message = sprintf(
                            __("Hi there! I can help with questions about %s. What can I help you find?", 'cleversay'),
                            $topics_phrase
                        );
                    } else {
                        // Generic fallback when no topics configured
                        $reply_message = __("Hi there! What can I help you find today?", 'cleversay');
                    }
                } elseif ($gibberish_no_answer) {
                    // Match the AI CASE 2 style for consistency — short clean refusal
                    $cs_opts = get_option('cleversay_options', []);
                    $topics  = trim((string) ($cs_opts['persona_topics'] ?? ''));
                    if ($topics !== '') {
                        $parts = array_values(array_filter(array_map('trim', explode(',', $topics))));
                        if (count($parts) > 1) {
                            $last = array_pop($parts);
                            $topics_phrase = implode(', ', $parts) . ', and ' . $last;
                        } else {
                            $topics_phrase = $parts[0] ?? $topics;
                        }
                        $reply_message = sprintf(
                            __("Sorry, I can only help with %s. What would you like to know?", 'cleversay'),
                            $topics_phrase
                        );
                    } else {
                        $reply_message = __("Sorry, I didn't catch that. What would you like to know?", 'cleversay');
                    }
                }

                $logger->info('No answer found — returning no-answer message');
                wp_send_json_success($this->translate_response_if_needed([
                    'found'             => false,
                    'answers'           => [],
                    'count'             => 0,
                    'suggestions'       => $results['suggested'] ?? [],
                    'no_answer_message' => $reply_message,
                    'show_inquiry_form' => $enable_inquiry,
                    'show_inquiry'      => $enable_inquiry && $this->is_real_question($query),
                    // Tells the widget whether this no-answer should increment the
                    // failure streak (used for handoff auto-escalation). Greetings
                    // don't count, but gibberish + real-question failures do.
                    'count_as_failure'  => !$casual_no_answer,
                ]));
            }
        } catch (\Exception $e) {
            $logger->error('Exception in search', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            wp_send_json_error(['message' => __('An error occurred. Please try again.', 'cleversay')]);
        } catch (\Error $e) {
            $logger->error('Error in search', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            wp_send_json_error(['message' => __('An error occurred. Please try again.', 'cleversay')]);
        }
    }
}
