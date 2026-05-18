<?php
/**
 * CleverSay REST API
 *
 * Provides RESTful endpoints for external integrations
 *
 * @package CleverSay
 * @since 1.0.0
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API Handler
 */
class API {
    
    private const NAMESPACE = 'cleversay/v1';
    
    private Database $database;
    private Search $search;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->database = new Database();
        $this->search = new Search();
    }
    
    /**
     * Initialize REST API
     */
    public function init(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
        // CORS must be set at init priority 1 — before WordPress buffers any output
        add_action('init', [$this, 'maybe_send_cors_headers'], 1);
    }

    /**
     * Add CORS headers for CleverSay widget endpoints.
     * Runs at init priority 1 — the only reliable place to call header() for REST routes.
     * Covers both REST API endpoints and admin-ajax.php CleverSay actions.
     */
    public function maybe_send_cors_headers(): void {
        $uri    = $_SERVER['REQUEST_URI'] ?? '';
        $origin = trim($_SERVER['HTTP_ORIGIN'] ?? '');

        // Determine if this is a CleverSay request needing CORS headers
        $is_cleversay_rest = str_contains($uri, '/cleversay/v1/chat')
            || str_contains($uri, '/cleversay/v1/asset/mascot')
            || str_contains($uri, '/cleversay/v1/embed-config');

        // admin-ajax.php CleverSay actions used by embed.min.js
        $is_cleversay_ajax = str_contains($uri, 'admin-ajax.php')
            && !empty($_REQUEST['action'])
            && str_starts_with(sanitize_key($_REQUEST['action']), 'cleversay_');

        if (!$is_cleversay_rest && !$is_cleversay_ajax) {
            return;
        }

        $allowed = $this->get_allowed_origins();

        if ($origin) {
            $origin_allowed = ($allowed === '*')
                || empty($allowed)
                || in_array(rtrim($origin, '/'), (array) $allowed, true);

            if ($origin_allowed) {
                header('Access-Control-Allow-Origin: '  . $origin);
                header('Vary: Origin');
            }
        } else {
            header('Access-Control-Allow-Origin: *');
        }

        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        header('Access-Control-Allow-Credentials: false');

        // Handle OPTIONS preflight immediately
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            status_header(204);
            exit;
        }
    }

    /**
     * @deprecated Kept as no-op — CORS now handled by maybe_send_cors_headers().
     */
    public function add_cors_headers(bool $served, \WP_HTTP_Response $result, \WP_REST_Request $request, \WP_REST_Server $server): bool {
        return $served;
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes(): void {
        // Search endpoint
        // Public embed config — used by embed.js on external sites
        register_rest_route(self::NAMESPACE, '/embed-config', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_embed_config'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/search', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_search'],
            'permission_callback' => [$this, 'check_public_permission'],
            'args' => [
                'q' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function($param) {
                        return !empty($param) && strlen($param) >= 2;
                    },
                    'description' => __('Search query', 'cleversay'),
                ],
                'limit' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 5,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function($param) {
                        return $param >= 1 && $param <= 20;
                    },
                ],
            ],
        ]);
        
        // Knowledge base endpoints
        register_rest_route(self::NAMESPACE, '/knowledge', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_knowledge_entries'],
            'permission_callback' => [$this, 'check_read_permission'],
            'args' => $this->get_collection_params(),
        ]);
        
        register_rest_route(self::NAMESPACE, '/knowledge/(?P<id>\d+)', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_knowledge_entry'],
                'permission_callback' => [$this, 'check_read_permission'],
            ],
            [
                'methods' => \WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_knowledge_entry'],
                'permission_callback' => [$this, 'check_edit_permission'],
                'args' => $this->get_entry_params(),
            ],
            [
                'methods' => \WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_knowledge_entry'],
                'permission_callback' => [$this, 'check_edit_permission'],
            ],
        ]);
        
        register_rest_route(self::NAMESPACE, '/knowledge', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_knowledge_entry'],
            'permission_callback' => [$this, 'check_edit_permission'],
            'args' => $this->get_entry_params(true),
        ]);
        
        // Categories endpoints
        register_rest_route(self::NAMESPACE, '/categories', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_categories'],
            'permission_callback' => [$this, 'check_public_permission'],
        ]);
        
        // Rating endpoint
        register_rest_route(self::NAMESPACE, '/rate', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_rating'],
            'permission_callback' => [$this, 'check_public_permission'],
            'args' => [
                'entry_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'helpful' => [
                    'required' => true,
                    'type' => 'boolean',
                ],
                'feedback' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ]);
        
        // Inquiry endpoint
        register_rest_route(self::NAMESPACE, '/inquiry', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'submit_inquiry'],
                'permission_callback' => [$this, 'check_public_permission'],
                'args' => [
                    'question' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                        'validate_callback' => function($param) {
                            return strlen($param) >= 10 && strlen($param) <= 1000;
                        },
                    ],
                'email' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_email',
                    'validate_callback' => function($param) {
                        return empty($param) || is_email($param);
                    },
                ],
                'name' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'transcript' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
                'handoff_type' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'details' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
            ],
            // Handle OPTIONS preflight for CORS
            [
                'methods'             => 'OPTIONS',
                'callback'            => [$this, 'submit_inquiry'],
                'permission_callback' => [$this, 'check_public_permission'],
            ],
        ]);
        
        // ── Widget chat endpoint ─────────────────────────────────────────────
        // Replaces admin-ajax.php for the embed widget so the URL doesn't
        // expose the WordPress stack. Accepts the same POST body as the AJAX
        // handler and proxies to the same search logic.
        register_rest_route(self::NAMESPACE, '/chat', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handle_widget_chat'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'OPTIONS',
                'callback'            => [$this, 'handle_widget_chat'],
                'permission_callback' => '__return_true',
            ],
        ]);

        // ── Mascot image proxy ───────────────────────────────────────────────
        // Serves the mascot image through a neutral URL so the real storage
        // path (wp-content/uploads/...) is never exposed to the browser.
        register_rest_route(self::NAMESPACE, '/asset/mascot', [
            'methods'             => 'GET',
            'callback'            => [$this, 'serve_mascot_image'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/stats', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_stats'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args' => [
                'period' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'week',
                    'enum' => ['day', 'week', 'month', 'year'],
                ],
            ],
        ]);
        
        // Synonyms endpoints (admin only)
        register_rest_route(self::NAMESPACE, '/synonyms', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_synonyms'],
            'permission_callback' => [$this, 'check_edit_permission'],
        ]);
        
        register_rest_route(self::NAMESPACE, '/synonyms', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_synonym'],
            'permission_callback' => [$this, 'check_edit_permission'],
            'args' => [
                'term' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'replacement' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'is_phrase' => [
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false,
                ],
            ],
        ]);
    }
    
    /**
     * Permission callbacks
     */
    public function check_public_permission(): bool {
        return true; // Public endpoints
    }
    
    public function check_read_permission(): bool {
        // Allow read access with API key or for logged-in users
        $api_key = $this->get_api_key_from_request();
        if ($api_key && $this->validate_api_key($api_key)) {
            return true;
        }
        return is_user_logged_in();
    }
    
    public function check_edit_permission(): bool {
        $api_key = $this->get_api_key_from_request();
        if ($api_key && $this->validate_api_key($api_key, 'edit')) {
            return true;
        }
        return current_user_can('edit_posts');
    }
    
    public function check_admin_permission(): bool {
        $api_key = $this->get_api_key_from_request();
        if ($api_key && $this->validate_api_key($api_key, 'admin')) {
            return true;
        }
        return current_user_can('manage_options');
    }
    
    /**
     * Handle search request
     */
    public function handle_search(\WP_REST_Request $request): \WP_REST_Response {
        $query = $request->get_param('q');
        $limit = $request->get_param('limit') ?? 5;
        
        $results = $this->search->search($query, $limit);
        
        // Format results for API response
        $formatted = array_map(function($result) {
            return [
                'id' => (int) $result['id'],
                'keyword' => $result['keyword'],
                'response' => wp_kses_post($result['response']),
                'score' => (float) ($result['score'] ?? 100),
            ];
        }, $results);
        
        return new \WP_REST_Response([
            'success' => true,
            'query' => $query,
            'count' => count($formatted),
            'results' => $formatted,
        ], 200);
    }
    
    /**
     * Get knowledge entries collection
     */
    public function get_knowledge_entries(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_knowledge';
        
        $page = $request->get_param('page') ?? 1;
        $per_page = $request->get_param('per_page') ?? 10;
        $status = $request->get_param('status');
        $search = $request->get_param('search');
        $orderby = $request->get_param('orderby') ?? 'created_at';
        $order = strtoupper($request->get_param('order') ?? 'DESC');
        
        $where = ['1=1'];
        $values = [];
        
        if ($status) {
            $where[] = 'status = %s';
            $values[] = $status;
        }
        
        if ($search) {
            $where[] = '(keyword LIKE %s OR sub_keyword LIKE %s OR response LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }
        
        $where_sql = implode(' AND ', $where);
        
        // Get total count
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total = $values ? $wpdb->get_var($wpdb->prepare($count_sql, $values)) : $wpdb->get_var($count_sql);
        
        // Validate orderby
        $allowed_orderby = ['id', 'keyword', 'hits', 'rate', 'status', 'created_at', 'updated_at'];
        if (!in_array($orderby, $allowed_orderby)) {
            $orderby = 'created_at';
        }
        $order = $order === 'ASC' ? 'ASC' : 'DESC';
        
        // Get entries
        $offset = ($page - 1) * $per_page;
        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $values[] = $per_page;
        $values[] = $offset;
        
        $entries = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);
        
        // Format entries
        $formatted = array_map([$this, 'format_entry'], $entries);
        
        $response = new \WP_REST_Response($formatted, 200);
        $response->header('X-WP-Total', $total);
        $response->header('X-WP-TotalPages', ceil($total / $per_page));
        
        return $response;
    }
    
    /**
     * Get single knowledge entry
     */
    public function get_knowledge_entry(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_knowledge';
        
        $id = (int) $request->get_param('id');
        $entry = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );
        
        if (!$entry) {
            return new \WP_REST_Response([
                'code' => 'not_found',
                'message' => __('Knowledge entry not found.', 'cleversay'),
            ], 404);
        }
        
        return new \WP_REST_Response($this->format_entry($entry), 200);
    }
    
    /**
     * Create knowledge entry
     */
    public function create_knowledge_entry(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_knowledge';
        
        $data = [
            'keyword' => $request->get_param('keyword'),
            'sub_keyword' => $request->get_param('sub_keyword') ?? '',
            'response' => $request->get_param('response'),
            'status' => $request->get_param('status') ?? 'active',
            'search_type' => $request->get_param('search_type') ?? 'contains',
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];
        
        if ($request->get_param('expires_at')) {
            $data['expires_at'] = $request->get_param('expires_at');
        }
        
        $result = $wpdb->insert($table, $data);
        
        if ($result === false) {
            return new \WP_REST_Response([
                'code' => 'create_failed',
                'message' => __('Failed to create knowledge entry.', 'cleversay'),
            ], 500);
        }
        
        $entry = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $wpdb->insert_id),
            ARRAY_A
        );
        
        return new \WP_REST_Response($this->format_entry($entry), 201);
    }
    
    /**
     * Update knowledge entry
     */
    public function update_knowledge_entry(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_knowledge';
        
        $id = (int) $request->get_param('id');
        
        // Check if entry exists
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $id));
        if (!$existing) {
            return new \WP_REST_Response([
                'code' => 'not_found',
                'message' => __('Knowledge entry not found.', 'cleversay'),
            ], 404);
        }
        
        $data = ['updated_at' => current_time('mysql')];
        
        $fields = ['keyword', 'sub_keyword', 'response', 'status', 'search_type', 'expires_at'];
        foreach ($fields as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $data[$field] = $value;
            }
        }
        
        $result = $wpdb->update($table, $data, ['id' => $id]);
        
        if ($result === false) {
            return new \WP_REST_Response([
                'code' => 'update_failed',
                'message' => __('Failed to update knowledge entry.', 'cleversay'),
            ], 500);
        }
        
        $entry = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );
        
        return new \WP_REST_Response($this->format_entry($entry), 200);
    }
    
    /**
     * Delete knowledge entry
     */
    public function delete_knowledge_entry(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_knowledge';
        
        $id = (int) $request->get_param('id');
        
        $result = $wpdb->delete($table, ['id' => $id]);
        
        if ($result === false) {
            return new \WP_REST_Response([
                'code' => 'delete_failed',
                'message' => __('Failed to delete knowledge entry.', 'cleversay'),
            ], 500);
        }
        
        if ($result === 0) {
            return new \WP_REST_Response([
                'code' => 'not_found',
                'message' => __('Knowledge entry not found.', 'cleversay'),
            ], 404);
        }
        
        return new \WP_REST_Response(null, 204);
    }
    
    /**
     * Get categories
     */
    public function get_categories(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        
        $categories = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY parent_id ASC, name ASC",
            ARRAY_A
        );
        
        return new \WP_REST_Response($categories, 200);
    }
    
    /**
     * Handle rating
     */
    public function handle_rating(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $ratings_table = $wpdb->prefix . 'cleversay_ratings';
        $knowledge_table = $wpdb->prefix . 'cleversay_knowledge';
        
        $entry_id = (int) $request->get_param('entry_id');
        $helpful = (bool) $request->get_param('helpful');
        $feedback = $request->get_param('feedback') ?? '';
        
        // Check if entry exists
        $entry = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$knowledge_table} WHERE id = %d", $entry_id)
        );
        
        if (!$entry) {
            return new \WP_REST_Response([
                'code' => 'not_found',
                'message' => __('Knowledge entry not found.', 'cleversay'),
            ], 404);
        }
        
        $ip_address = $this->get_client_ip();
        
        // Check for existing rating from same IP
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$ratings_table} WHERE entry_id = %d AND ip_address = %s AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            $entry_id,
            $ip_address
        ));
        
        if ($existing) {
            return new \WP_REST_Response([
                'code' => 'already_rated',
                'message' => __('You have already rated this answer.', 'cleversay'),
            ], 429);
        }
        
        // Insert rating
        $wpdb->insert($ratings_table, [
            'entry_id' => $entry_id,
            'helpful' => $helpful ? 1 : 0,
            'not_helpful' => $helpful ? 0 : 1,
            'feedback' => $feedback,
            'ip_address' => $ip_address,
            'user_id' => get_current_user_id() ?: null,
            'created_at' => current_time('mysql'),
        ]);
        
        // Update entry rating counts on the knowledge table
        $helpful     = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$ratings_table} WHERE entry_id = %d AND rating = 'helpful'",
            $entry_id
        ));
        $not_helpful = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$ratings_table} WHERE entry_id = %d AND rating = 'not_helpful'",
            $entry_id
        ));

        $wpdb->update($knowledge_table, [
            'helpful_yes' => $helpful,
            'helpful_no'  => $not_helpful,
        ], ['id' => $entry_id]);
        
        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Thank you for your feedback!', 'cleversay'),
            'rating' => $rate,
        ], 200);
    }
    
    /**
     * Submit inquiry
     */
    public function submit_inquiry(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_inquiries';

        // Add CORS headers so external embed.js sites can post inquiries
        $allowed = $this->get_allowed_origins();
        $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin && ($allowed === '*' || in_array(rtrim($origin, '/'), (array)$allowed, true))) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Allow-Credentials: false');
            header('Vary: Origin');
        }
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            status_header(204);
            exit;
        }
        
        $question     = $request->get_param('question');
        $email        = $request->get_param('email') ?? '';
        $name         = $request->get_param('name') ?? '';
        $details      = $request->get_param('details') ?? '';
        $transcript   = $request->get_param('transcript') ?? '';
        $handoff_type = $request->get_param('handoff_type') ?? '';

        if (strlen($transcript) > 20000) {
            $transcript = substr($transcript, 0, 20000) . "\n… [truncated]";
        }
        $allowed_handoff = ['keyword_request', 'auto_escalation', 'user_initiated'];
        if (!in_array($handoff_type, $allowed_handoff, true)) {
            $handoff_type = null;
        }
        
        // Simple spam check
        if ($this->is_spam($question)) {
            return new \WP_REST_Response([
                'code' => 'spam_detected',
                'message' => __('Your submission was flagged as spam.', 'cleversay'),
            ], 403);
        }
        
        $ip_address = $this->get_client_ip();
        
        // Rate limit: max 5 inquiries per hour per IP
        $recent_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE ip_address = %s AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $ip_address
        ));
        
        if ($recent_count >= 5) {
            return new \WP_REST_Response([
                'code' => 'rate_limited',
                'message' => __('Too many submissions. Please try again later.', 'cleversay'),
            ], 429);
        }
        
        $wpdb->insert($table, [
            'question'     => $question,
            'details'      => $details,
            'email'        => $email,
            'name'         => $name,
            'ip_address'   => $ip_address,
            'status'       => 'pending',
            'transcript'   => $transcript !== '' ? $transcript : null,
            'handoff_type' => $handoff_type,
            'created_at'   => current_time('mysql'),
        ]);
        
        // Send notification email (with transcript if present)
        $this->send_inquiry_notification($question, $email, $name, $transcript, $handoff_type);
        
        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Your question has been submitted. We will respond soon!', 'cleversay'),
        ], 201);
    }
    
    /**
     * Get statistics
     */
    public function get_stats(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        
        $period = $request->get_param('period') ?? 'week';
        
        switch ($period) {
            case '7days':  $date_from = date('Y-m-d', strtotime('-7 days'));  break;
            case '30days': $date_from = date('Y-m-d', strtotime('-30 days')); break;
            case '90days': $date_from = date('Y-m-d', strtotime('-90 days')); break;
            case '1year':  $date_from = date('Y-m-d', strtotime('-1 year'));  break;
            default:       $date_from = date('Y-m-d', strtotime('-30 days')); break;
        }
        
        $knowledge_table = $wpdb->prefix . 'cleversay_knowledge';
        $questions_table = $wpdb->prefix . 'cleversay_questions';
        $visitors_table = $wpdb->prefix . 'cleversay_visitors';
        $inquiries_table = $wpdb->prefix . 'cleversay_inquiries';
        
        $stats = [
            'total_entries' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$knowledge_table}"),
            'active_entries' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$knowledge_table} WHERE status = 'active'"),
            'total_questions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$questions_table}"),
            'period_questions' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$questions_table} WHERE created_at > {$date_from}"
            ),
            'unique_visitors' => (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT ip_address) FROM {$visitors_table} WHERE last_visit > {$date_from}"
            ),
            'pending_inquiries' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$inquiries_table} WHERE status = 'pending'"
            ),
            'match_rate' => $this->calculate_match_rate($date_from),
            'top_keywords' => $this->get_top_keywords($date_from),
            'questions_by_day' => $this->get_questions_by_day($date_from),
        ];
        
        return new \WP_REST_Response($stats, 200);
    }
    
    /**
     * Get synonyms
     */
    public function get_synonyms(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_synonyms';
        
        $synonyms = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY term ASC",
            ARRAY_A
        );
        
        return new \WP_REST_Response($synonyms, 200);
    }
    
    /**
     * Create synonym
     */
    public function create_synonym(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_synonyms';
        
        $term = strtolower($request->get_param('term'));
        $replacement = strtolower($request->get_param('replacement'));
        $is_phrase = (bool) $request->get_param('is_phrase');
        
        // Check for duplicate
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE term = %s",
            $term
        ));
        
        if ($existing) {
            return new \WP_REST_Response([
                'code' => 'duplicate',
                'message' => __('This synonym already exists.', 'cleversay'),
            ], 409);
        }
        
        $wpdb->insert($table, [
            'term' => $term,
            'replacement' => $replacement,
            'is_phrase' => $is_phrase ? 1 : 0,
            'is_active' => 1,
        ]);
        
        $synonym = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $wpdb->insert_id),
            ARRAY_A
        );
        
        return new \WP_REST_Response($synonym, 201);
    }
    
    /**
     * Helper: Format entry for API response
     */
    private function format_entry(array $entry): array {
        return [
            'id' => (int) $entry['id'],
            'keyword' => $entry['keyword'],
            'sub_keyword' => $entry['sub_keyword'],
            'response' => $entry['response'],
            'status' => $entry['status'],
            'search_type' => $entry['search_type'],
            'hits' => (int) $entry['hits'],
            'rate' => (int) $entry['rate'],
            'reuse' => (bool) $entry['reuse'],
            'expires_at' => $entry['expires_at'],
            'created_at' => $entry['created_at'],
            'updated_at' => $entry['updated_at'],
            'created_by' => $entry['created_by'] ? (int) $entry['created_by'] : null,
        ];
    }
    
    /**
     * Helper: Get collection params
     */
    private function get_collection_params(): array {
        return [
            'page' => [
                'default' => 1,
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'default' => 10,
                'type' => 'integer',
                'sanitize_callback' => 'absint',
                'validate_callback' => function($param) {
                    return $param >= 1 && $param <= 100;
                },
            ],
            'status' => [
                'type' => 'string',
                'enum' => ['active', 'inactive', 'draft'],
            ],
            'search' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'orderby' => [
                'default' => 'created_at',
                'type' => 'string',
            ],
            'order' => [
                'default' => 'DESC',
                'type' => 'string',
                'enum' => ['ASC', 'DESC', 'asc', 'desc'],
            ],
        ];
    }
    
    /**
     * Helper: Get entry params
     */
    private function get_entry_params(bool $required = false): array {
        return [
            'keyword' => [
                'required' => $required,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'sub_keyword' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'response' => [
                'required' => $required,
                'type' => 'string',
                'sanitize_callback' => 'wp_kses_post',
            ],
            'status' => [
                'type' => 'string',
                'enum' => ['active', 'inactive', 'draft'],
            ],
            'search_type' => [
                'type' => 'string',
                'enum' => ['exact', 'prefix', 'suffix', 'contains'],
            ],
            'expires_at' => [
                'type' => 'string',
                'format' => 'date-time',
            ],
        ];
    }
    
    /**
     * Helper: Get API key from request
     */
    private function get_api_key_from_request(): ?string {
        // Check Authorization header
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
        
        if (str_starts_with($auth_header, 'Bearer ')) {
            return substr($auth_header, 7);
        }
        
        if (!empty($auth_header)) {
            return $auth_header;
        }
        
        // Check query param
        return $_GET['api_key'] ?? null;
    }
    
    /**
     * Helper: Validate API key
     */
    private function validate_api_key(string $key, string $level = 'read'): bool {
        $stored_keys = get_option('cleversay_api_keys', []);
        
        foreach ($stored_keys as $stored) {
            if (hash_equals($stored['key'], $key)) {
                $key_level = $stored['level'] ?? 'read';
                
                switch ($level) {
                    case 'error':   return 'error';
                    case 'warning': return 'warning';
                    case 'info':    return 'info';
                    default:        return 'debug';
                }
                return 'debug'; // unreachable but satisfies return type
            }
        }
        
        return false;
    }
    
    /**
     * Helper: Get client IP
     */
    private function get_client_ip(): string {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = explode(',', $_SERVER[$header])[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }
    
    /**
     * Helper: Check for spam
     */
    private function is_spam(string $text): bool {
        // Simple spam indicators
        $spam_patterns = [
            '/\b(viagra|cialis|casino|poker|lottery)\b/i',
            '/\b(buy now|click here|free money)\b/i',
            '/(http|https):\/\/[^\s]+/i', // URLs
            '/(.)\1{5,}/', // Repeated characters
        ];
        
        foreach ($spam_patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Helper: Send inquiry notification
     */
    private function send_inquiry_notification(string $question, string $email, string $name, string $transcript = '', ?string $handoff_type = null): void {
        $options = get_option('cleversay_options', []);
        $notify_email = $options['inquiry_notification_email'] ?? get_option('admin_email');
        
        if (!$notify_email || !($options['enable_inquiry_form'] ?? true)) {
            return;
        }
        
        if ($handoff_type === 'keyword_request') {
            $subject = sprintf(__('[%s] Visitor requested a human agent', 'cleversay'), get_bloginfo('name'));
        } elseif ($handoff_type === 'auto_escalation') {
            $subject = sprintf(__('[%s] Chatbot escalation — needs human help', 'cleversay'), get_bloginfo('name'));
        } else {
            $subject = sprintf(__('[%s] New CleverSay Inquiry', 'cleversay'), get_bloginfo('name'));
        }

        $transcript_section = '';
        if (!empty($transcript)) {
            $transcript_section = "\n\n---\nChat transcript:\n\n{$transcript}";
        }
        
        $message = sprintf(
            __("A new question has been submitted:\n\nQuestion: %s\nName: %s\nEmail: %s%s\n\nManage inquiries: %s", 'cleversay'),
            $question,
            $name ?: __('Not provided', 'cleversay'),
            $email ?: __('Not provided', 'cleversay'),
            $transcript_section,
            admin_url('admin.php?page=cleversay-inquiries')
        );
        
        wp_mail($notify_email, $subject, $message);
    }
    
    /**
     * Helper: Calculate match rate
     */
    private function calculate_match_rate(string $date_from): float {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_questions';
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE created_at > {$date_from}");
        $matched = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE match_type IS NOT NULL AND match_type != 'none' AND created_at > {$date_from}");
        
        return $total > 0 ? round(($matched / $total) * 100, 1) : 0;
    }
    
    /**
     * Helper: Get top keywords
     */
    private function get_top_keywords(string $date_from): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_questions';
        
        return $wpdb->get_results(
            "SELECT matched_keyword as keyword, COUNT(*) as count 
             FROM {$table} 
             WHERE matched_keyword IS NOT NULL AND created_at > {$date_from}
             GROUP BY matched_keyword 
             ORDER BY count DESC 
             LIMIT 10",
            ARRAY_A
        );
    }
    
    /**
     * Helper: Get questions by day
     */
    private function get_questions_by_day(string $date_from): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_questions';
        
        return $wpdb->get_results(
            "SELECT DATE(created_at) as date, COUNT(*) as count 
             FROM {$table} 
             WHERE created_at > {$date_from}
             GROUP BY DATE(created_at) 
             ORDER BY date ASC",
            ARRAY_A
        );
    }
    /**
     * REST: Widget chat — neutral URL wrapper around the public AJAX search handler.
     * POST /wp-json/cleversay/v1/chat
     * Accepts embed_token auth; adds CORS headers for cross-origin embeds.
     */
    public function handle_widget_chat(\WP_REST_Request $request): void {
        // CORS is handled by add_cors_headers() filter — no header() calls needed here.
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            status_header(204);
            exit;
        }

        // Validate embed token
        $token  = sanitize_text_field($request->get_param('embed_token') ?? '');
        $stored = get_option('cleversay_embed_token', '');
        if (empty($stored) || !hash_equals($stored, $token)) {
            wp_send_json_error(['message' => __('Invalid token.', 'cleversay')], 403);
            return;
        }

        // Populate $_POST so the existing public handler works unchanged
        $_POST['embed_token'] = $token;
        $_POST['question']    = $request->get_param('question')    ?? '';
        $_POST['query']       = $_POST['question'];
        $_POST['history']     = $request->get_param('history')     ?? '';
        $_POST['context']     = $request->get_param('context')     ?? 'embed';
        $_POST['force_ai']    = $request->get_param('force_ai')    ?? '';

        // Delegate to the public AJAX handler — it calls wp_send_json_* and exits
        $public = new \CleverSay\PublicFacing();
        $public->ajax_search();
    }

    /**
     * REST: Mascot image proxy — serves the mascot through a neutral URL.
     * GET /wp-json/cleversay/v1/asset/mascot
     * The actual wp-content/uploads/... path is never exposed to the browser.
     */
    public function serve_mascot_image(\WP_REST_Request $request): void {
        $options    = get_option('cleversay_options', []);
        $mascot_url = $options['mascot_image_url'] ?? '';

        if (empty($mascot_url)) {
            http_response_code(404);
            exit;
        }

        // Fetch the image server-side
        $response = wp_remote_get($mascot_url, [
            'timeout'   => 10,
            'sslverify' => true,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            http_response_code(404);
            exit;
        }

        $body         = wp_remote_retrieve_body($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type') ?: 'image/png';

        // Only allow image content types
        if (!str_starts_with($content_type, 'image/')) {
            http_response_code(403);
            exit;
        }

        // Cache aggressively — mascot rarely changes
        header('Content-Type: '    . $content_type);
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /**
     * REST: Return public widget config for the embed script.
     *
     * This endpoint is intentionally public (no authentication required) and
     * adds CORS headers so external domains can fetch it. It returns everything
     * the embed.js needs to bootstrap the widget, including a fresh nonce for
     * the AJAX calls that follow.
     */
    public function get_embed_config(\WP_REST_Request $request): \WP_REST_Response {
        // Add CORS header for the config fetch itself
        $allowed = $this->get_allowed_origins();
        $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ($origin && ($allowed === '*' || in_array(rtrim($origin, '/'), $allowed, true))) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: false');
            header('Vary: Origin');
        }

        $options = get_option('cleversay_options', []);

        // ── Trial / suspension check ─────────────────────────────────
        // If this site's plan is suspended (or trial expired past grace),
        // return a minimal config that tells the widget to display a
        // "service unavailable" message instead of activating. This
        // protects against the widget continuing to rack up AI costs on
        // sites that are no longer paying for service.
        $suspended_message = '';
        if (is_multisite() && class_exists('\CleverSay\TrialEnforcer')) {
            $runtime = \CleverSay\TrialEnforcer::get_runtime_status(get_current_blog_id());
            if (!$runtime['active']) {
                return new \WP_REST_Response([
                    'suspended' => true,
                    'reason'    => $runtime['reason'],
                    'message'   => __('This chatbot is currently unavailable. Please contact the site administrator.', 'cleversay'),
                ], 200);
            }
        }

        return new \WP_REST_Response([
            'ajaxUrl'              => admin_url('admin-ajax.php'),
            'nonce'                => wp_create_nonce('cleversay_nonce'),
            'embedToken'           => get_option('cleversay_embed_token', ''),
            'pluginUrl'            => CLEVERSAY_PLUGIN_URL,
            'botName'              => $options['bot_name']          ?? __('Assistant', 'cleversay'),
            'botLabel'             => $options['bot_agent_label']   ?? __('AI Agent', 'cleversay'),
            'mascotUrl'            => !empty($options['mascot_image_url'])
                                        ? rest_url(self::NAMESPACE . '/asset/mascot')
                                        : '',
            'welcomeMessage'       => $options['widget_welcome_message'] ?? __('Hello! How can I help you today?', 'cleversay'),
            'teaserEnabled'        => !isset($options['teaser_enabled']) || !empty($options['teaser_enabled']),
            'teaserMessage'        => !empty($options['teaser_message']) ? $options['teaser_message'] : ($options['widget_welcome_message'] ?? __('Hello! How can I help you today?', 'cleversay')),
            'teaserDelay'          => (int) ($options['teaser_delay'] ?? 3),
            'placeholder'          => $options['widget_placeholder']    ?? __('Type a message...', 'cleversay'),
            'position'             => $options['widget_position']        ?? 'bottom-right',
            'primaryColor'         => $options['primary_color']          ?? '#2271b1',
            'headerBgColor'        => $options['header_bg_color']        ?? '#2271b1',
            'headerTextColor'      => $options['header_text_color']      ?? '#ffffff',
            'userBubbleColor'      => $options['user_bubble_color']      ?? '#2271b1',
            'userBubbleText'       => $options['user_bubble_text']       ?? '#ffffff',
            'botBubbleColor'       => $options['bot_bubble_color']       ?? '#ffffff',
            'botBubbleText'        => $options['bot_bubble_text']        ?? '#1d2327',
            'chatBgColor'          => $options['chat_bg_color']          ?? '#f5f5f7',
            'toggleBgColor'        => $options['toggle_bg_color']        ?? '#2271b1',
            'showRating'           => !empty($options['show_rating']),
            'enableInquiry'        => !empty($options['enable_inquiry_form']),
            'requireEmail'         => !empty($options['require_email_for_inquiry']),
            'showAiBadge'          => !isset($options['show_ai_badge']) || !empty($options['show_ai_badge']),
            // v4.41.5.8+: per-site debug toggle exposing client + server
            // response timing below each bot message. Off by default;
            // operator opts in on testing/staging tenants. See per-site
            // Settings → Advanced → Debugging.
            'showTiming'           => (bool) get_option('cleversay_show_timing', false),
            'aiLabel'              => get_option('cleversay_ai_label', __('AI-assisted answer', 'cleversay')),
            // ── Lead capture (pre-chat gate) ──────────────────────────────
            'leadCapture' => [
                'enabled'        => (bool) get_option('cleversay_lead_capture_enabled', false),
                'welcomeMessage' => get_option('cleversay_lead_welcome_message',
                    __('Welcome! To get started, please share some info below.', 'cleversay')),
                'consentText'    => get_option('cleversay_lead_consent_text',
                    __('By continuing, you agree to be contacted about your inquiry.', 'cleversay')),
                'cooldownDays'   => (int) get_option('cleversay_lead_cooldown_days', 90),
                'hardGate'       => (bool) get_option('cleversay_lead_hard_gate', true),
                'identityOptions' => array_values((array) get_option('cleversay_lead_identity_options', [])),
                'identityLabel'  => get_option('cleversay_lead_identity_label', __('I am a…', 'cleversay')),
                'fields'         => (array) get_option('cleversay_lead_field_config', []),
                'submitLabel'    => __('Continue', 'cleversay'),
                'skipLabel'      => __('Skip and start chatting', 'cleversay'),
            ],
            'widgetFont'           => $options['widget_font']               ?? 'system',
            'widgetFontUrl'        => $options['widget_font_custom_url']    ?? '',
            'widgetFontFamily'     => $options['widget_font_custom_family'] ?? '',
            'widgetFontSize'       => (int) ($options['widget_font_size'] ?? 15),
            'strings' => [
                'searching'          => __('Searching...', 'cleversay'),
                'helpful'            => __('Was this helpful?', 'cleversay'),
                'yes'                => __('Yes', 'cleversay'),
                'no'                 => __('No', 'cleversay'),
                'thanks'             => __('Thanks for your feedback!', 'cleversay'),
                'noAnswer'           => get_option('cleversay_no_answer_message', ''),
                'submitInquiry'      => __('Send Message', 'cleversay'),
                'stillHelp'          => __('Still need help? Send us a message.', 'cleversay'),
                'inquiryName'        => __('Your name (optional)', 'cleversay'),
                'inquiryEmail'       => __('Your email (optional)', 'cleversay'),
                'detailsPlaceholder' => __('Add more details about your question (optional)', 'cleversay'),
                'inquiryPlaceholder' => __('Enter your message (optional)', 'cleversay'),
                'inquiryIntro'       => $options['inquiry_intro_message']
                    ?? __('Sure — fill out the form below and we\'ll get back to you.', 'cleversay'),
                'inquirySuccess'     => __('Your question has been submitted. We\'ll get back to you soon!', 'cleversay'),
                'inquiryError'       => __('There was an error submitting your question. Please try again.', 'cleversay'),
                'poweredBy'          => __('Powered by CleverSay', 'cleversay'),
                'send'               => __('Send', 'cleversay'),
                'close'              => __('Close chat', 'cleversay'),
            ],
        ], 200);
    }

    /**
     * Return the list of allowed CORS origins (or '*').
     * Reads from the cleversay_embed_domains option.
     */
    public function get_allowed_origins() {
        // In Multisite, embed domains are set per-client in the Network Admin
        // and stored in the site plan — not editable by clients.
        if (function_exists('is_multisite') && is_multisite()) {
            $plan = \CleverSay\NetworkSettings::get_site_plan(get_current_blog_id());
            $raw  = $plan['embed_domains'] ?? '';
        } else {
            $raw = get_option('cleversay_embed_domains', '');
        }

        if (trim($raw) === '*') return '*';
        $lines = array_filter(array_map('trim', explode("\n", $raw)));
        if (empty($lines)) return [];
        $domains = [];
        foreach ($lines as $line) {
            if (empty($line)) continue;
            $domains[] = rtrim($line, '/');
        }
        return $domains;
    }


}