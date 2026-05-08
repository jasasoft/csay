<?php
/**
 * CleverSay Client Portal
 *
 * Handles client admin lockdown and branding for Multisite installs.
 * - Hides WordPress native menus clients don't need
 * - Hides CleverSay AI Settings and Advanced pages from clients
 * - Applies client branding (logo, name, Powered by CleverSay)
 * - Redirects clients away from restricted pages
 *
 * Only loaded on non-network admin pages when Multisite is active.
 *
 * @package CleverSay
 * @since   4.0.0
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class ClientPortal {

    /**
     * CleverSay menu pages clients CAN access.
     */
    private const ALLOWED_PAGES = [
        'cleversay',
        'cleversay-knowledge',
        'cleversay-ask',
        'cleversay-synonyms',
        'cleversay-inquiries',
        'cleversay-settings',
        'cleversay-reports',
        'cleversay-questions',
        'cleversay-import-export',
        'cleversay-ai-answers',
    ];

    /**
     * WordPress native menu slugs to hide from clients.
     */
    private const HIDDEN_WP_MENUS = [
        'index.php',           // Dashboard (we keep CleverSay dashboard)
        'edit.php',            // Posts
        'upload.php',          // Media
        'edit.php?post_type=page', // Pages
        'edit-comments.php',   // Comments
        'themes.php',          // Appearance
        'plugins.php',         // Plugins
        'users.php',           // Users
        'tools.php',           // Tools
        'options-general.php', // Settings (WordPress native)
        'profile.php',         // Profile (keep accessible but hidden from menu)
    ];

    public function init(): void {
        // Only run lockdown in Multisite for non-super-admins
        if (!is_multisite() || is_super_admin()) {
            return;
        }

        add_action('admin_menu',              [$this, 'lock_admin_menu'], 999);
        add_action('admin_init',              [$this, 'redirect_restricted_pages']);
        add_action('admin_bar_menu',          [$this, 'clean_admin_bar'], 999);
        add_filter('admin_body_class',        [$this, 'add_portal_body_class']);
        add_action('admin_head',              [$this, 'inject_portal_styles']);
        add_action('admin_footer',            [$this, 'inject_portal_footer']);
        add_action('wp_before_admin_bar_render', [$this, 'remove_admin_bar_items']);

        // Hide admin bar on front end — clients never visit the front end
        add_filter('show_admin_bar', '__return_false');

        // Custom login redirect — send clients straight to CleverSay dashboard
        add_filter('login_redirect', [$this, 'login_redirect'], 10, 3);

        // Hide update notifications from clients
        add_action('admin_init', [$this, 'hide_update_notices']);

        // Login page branding
        add_action('login_enqueue_scripts', [$this, 'login_styles']);
        add_filter('login_headerurl',       [$this, 'login_header_url']);
        add_filter('login_headertext',      [$this, 'login_header_text']);
        add_filter('login_body_class',      [$this, 'login_body_class']);
    }

    // ── Menu Lockdown ─────────────────────────────────────────────────────────

    /**
     * Remove WordPress native menus and restricted CleverSay pages.
     */
    public function lock_admin_menu(): void {
        // Remove WordPress native menus
        foreach (self::HIDDEN_WP_MENUS as $slug) {
            remove_menu_page($slug);
        }

        // Remove the WordPress Dashboard — clients use CleverSay dashboard instead
        remove_menu_page('index.php');

        // Remove restricted CleverSay submenus
        remove_submenu_page('cleversay', 'cleversay-ai-sources');
        remove_submenu_page('cleversay', 'cleversay-debug-log');
    }

    // ── Redirect Restricted Pages ─────────────────────────────────────────────

    /**
     * Redirect clients away from pages they shouldn't see.
     */
    public function redirect_restricted_pages(): void {
        global $pagenow;

        // Redirect WordPress Dashboard (index.php) → CleverSay Dashboard
        if ($pagenow === 'index.php' && !isset($_GET['page'])) {
            wp_redirect(admin_url('admin.php?page=cleversay'));
            exit;
        }

        // Redirect profile.php → CleverSay Dashboard
        // (profile is accessible directly but not via menu)
        if ($pagenow === 'profile.php') {
            wp_redirect(admin_url('admin.php?page=cleversay'));
            exit;
        }

        // Redirect any restricted CleverSay pages
        if (isset($_GET['page'])) {
            $page = sanitize_text_field(wp_unslash($_GET['page']));
            $restricted = [
                'cleversay-ai-sources',
                'cleversay-debug-log',
            ];
            if (in_array($page, $restricted, true)) {
                wp_redirect(admin_url('admin.php?page=cleversay'));
                exit;
            }
        }
    }

    // ── Admin Bar ─────────────────────────────────────────────────────────────

    /**
     * Clean the admin bar for clients.
     */
    public function clean_admin_bar(\WP_Admin_Bar $wp_admin_bar): void {
        // Remove everything except the user account menu
        $remove = [
            'wp-logo',
            'about',
            'wporg',
            'documentation',
            'support-forums',
            'feedback',
            'updates',
            'comments',
            'new-content',
            'site-name',
            'my-sites',         // ← My Sites dropdown completely gone
            'my-account',       // We rebuild this below without WP references
            'search',
            'customize',
            'view-site',
            'themes',
            'widgets',
            'menus',
        ];

        foreach ($remove as $id) {
            $wp_admin_bar->remove_node($id);
        }

        // Add a clean user menu with just logout
        $user         = wp_get_current_user();
        $display_name = $user->display_name ?: $user->user_login;

        $wp_admin_bar->add_node([
            'id'    => 'cleversay-user',
            'title' => '<span class="ab-icon dashicons dashicons-admin-users"></span>'
                     . '<span class="ab-label">' . esc_html($display_name) . '</span>',
            'href'  => '#',
        ]);

        $wp_admin_bar->add_node([
            'id'     => 'cleversay-logout',
            'parent' => 'cleversay-user',
            'title'  => __('Log Out', 'cleversay'),
            'href'   => wp_logout_url(wp_login_url()),
        ]);
    }

    public function remove_admin_bar_items(): void {
        global $wp_admin_bar;
        if (!$wp_admin_bar) return;
        // Belt and suspenders — remove My Sites via the global object too
        $wp_admin_bar->remove_menu('my-sites');
        $wp_admin_bar->remove_menu('site-name');
        $wp_admin_bar->remove_menu('search');
    }

    // ── Login Redirect ────────────────────────────────────────────────────────

    /**
     * Send clients directly to CleverSay dashboard after login.
     */
    public function login_redirect(string $redirect_to, string $requested_redirect_to, \WP_User|\WP_Error $user): string {
        if (is_wp_error($user) || is_super_admin($user->ID)) {
            return $redirect_to;
        }
        return admin_url('admin.php?page=cleversay');
    }

    // ── Notices ───────────────────────────────────────────────────────────────

    /**
     * Hide WordPress update and plugin notices from clients.
     */
    public function hide_update_notices(): void {
        remove_action('admin_notices', 'update_nag', 3);
        remove_action('admin_notices', 'maintenance_nag', 10);
        add_filter('site_transient_update_plugins', '__return_false');
        add_filter('site_transient_update_themes',  '__return_false');
        add_filter('site_transient_update_core',    '__return_false');
    }

    // ── Branding ──────────────────────────────────────────────────────────────

    /**
     * Add portal body class for CSS scoping.
     */
    public function add_portal_body_class(string $classes): string {
        return $classes . ' cleversay-client-portal';
    }

    /**
     * Inject branding CSS into admin head.
     * Uses client logo and name from NetworkSettings site plan.
     */
    public function inject_portal_styles(): void {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'cleversay') === false) {
            return;
        }

        $blog_id  = get_current_blog_id();
        $plan     = NetworkSettings::get_site_plan($blog_id);
        $logo_url = esc_url($plan['client_logo_url'] ?? '');
        $options  = get_option('cleversay_options', []);
        $color    = esc_attr($options['primary_color'] ?? '#0A84FF');

        ?>
        <style>
        /* ── CleverSay Client Portal Branding ── */

        /* Hide WP logo in admin bar */
        #wpadminbar #wp-admin-bar-wp-logo,
        #wpadminbar #wp-admin-bar-my-sites,
        #wpadminbar #wp-admin-bar-site-name,
        #wpadminbar #wp-admin-bar-my-account,
        #wpadminbar .ab-top-secondary { display: none !important; }

        /* Show only our clean user menu on the right */
        #wpadminbar #wp-admin-bar-cleversay-user { display: block !important; }

        /* Admin bar brand label — show client name */
        #wpadminbar #wp-admin-bar-cleversay-user > .ab-item {
            color: rgba(255,255,255,0.85) !important;
            font-size: 13px !important;
        }

        /* Make the admin bar less prominent */
        #wpadminbar {
            background: #1D1D1F !important;
        }

        /* Custom logo in admin menu header */
        #adminmenuheader {
            padding: 16px 12px 12px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            margin-bottom: 8px;
        }

        <?php if ($logo_url): ?>
        #cleversay-client-logo {
            max-width: 140px;
            max-height: 50px;
            width: auto;
            height: auto;
            display: block;
            margin: 0 auto 6px;
        }
        <?php endif; ?>

        /* Portal name below logo */
        #cleversay-portal-name {
            font-size: 11px;
            color: rgba(255,255,255,0.6);
            font-weight: 500;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Collapse logo area when menu is folded */
        .folded #cleversay-client-logo { max-width: 32px; }
        .folded #cleversay-portal-name { display: none; }

        /* Override admin bar site name with client name */
        #wpadminbar .ab-item[href*="cleversay"],
        #wp-admin-bar-site-name > .ab-item {
            color: rgba(255,255,255,0.9) !important;
        }

        /* Powered by footer */
        #cleversay-portal-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 160px;
            padding: 8px 12px;
            font-size: 10px;
            color: rgba(255,255,255,0.35);
            text-align: center;
            letter-spacing: 0.02em;
            z-index: 100;
            transition: opacity 0.2s;
            white-space: nowrap;
        }
        #cleversay-portal-footer a {
            color: rgba(255,255,255,0.45);
            text-decoration: none;
        }
        #cleversay-portal-footer a:hover {
            color: rgba(255,255,255,0.7);
        }
        .folded #cleversay-portal-footer {
            width: 36px;
            overflow: hidden;
            font-size: 0;
        }
        </style>
        <?php
    }

    /**
     * Inject branding elements into the admin menu header and footer.
     */
    public function inject_portal_footer(): void {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'cleversay') === false) {
            return;
        }

        $blog_id     = get_current_blog_id();
        $plan        = NetworkSettings::get_site_plan($blog_id);
        $logo_url    = esc_url($plan['client_logo_url'] ?? '');
        $client_name = esc_html($plan['client_name'] ?? get_bloginfo('name'));

        // Inject logo + client name into admin menu header via JS
        // (WordPress doesn't have a hook for the menu header itself)
        ?>
        <script>
        (function() {
            var adminMenu = document.getElementById('adminmenuback');
            if (!adminMenu) return;

            var header = document.createElement('div');
            header.id = 'adminmenuheader';

            <?php if ($logo_url): ?>
            var logo = document.createElement('img');
            logo.id  = 'cleversay-client-logo';
            logo.src = <?php echo wp_json_encode($logo_url); ?>;
            logo.alt = <?php echo wp_json_encode($client_name); ?>;
            header.appendChild(logo);
            <?php endif; ?>

            var name = document.createElement('div');
            name.id          = 'cleversay-portal-name';
            name.textContent = <?php echo wp_json_encode($client_name); ?>;
            header.appendChild(name);

            // Insert before the first menu item
            var wrap = document.getElementById('adminmenuwrap');
            if (wrap) {
                wrap.insertBefore(header, wrap.firstChild);
            }
        })();
        </script>

        <!-- Powered by CleverSay footer -->
        <div id="cleversay-portal-footer">
            <?php esc_html_e('Powered by', 'cleversay'); ?>
            <a href="https://cleversay.com" target="_blank" rel="noopener">CleverSay</a>
        </div>
        <?php
    }

    // ── Login Page Branding ───────────────────────────────────────────────────

    /**
     * Get current site plan helper — used by login methods.
     */
    private function get_current_plan(): array {
        return NetworkSettings::get_site_plan(get_current_blog_id());
    }

    /**
     * Inject custom CSS on the login page.
     */
    public function login_styles(): void {
        $plan        = $this->get_current_plan();
        $logo_url    = esc_url($plan['client_logo_url'] ?? '');
        $client_name = esc_html($plan['client_name'] ?? get_bloginfo('name'));
        $options     = get_option('cleversay_options', []);
        $color       = esc_attr($options['primary_color'] ?? '#0A84FF');

        // Lighten the primary color for the gradient background
        ?>
        <style>
        /* ── CleverSay Client Login Page ── */

        body.login {
            background: #F5F5F7;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        body.login div#login {
            padding: 0;
            width: 340px;
        }

        /* Login box card */
        body.login div#login form {
            background: #ffffff;
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.10);
            padding: 32px;
            margin-top: 0;
        }

        /* Logo area */
        body.login h1 a {
            <?php if ($logo_url): ?>
            background-image: url('<?php echo $logo_url; ?>') !important;
            background-size: contain !important;
            background-repeat: no-repeat !important;
            background-position: center !important;
            width: 220px !important;
            height: 70px !important;
            <?php else: ?>
            /* No logo — show CleverSay text */
            background-image: none !important;
            font-size: 22px;
            font-weight: 700;
            color: <?php echo $color; ?>;
            text-indent: 0 !important;
            display: flex;
            align-items: center;
            justify-content: center;
            width: auto !important;
            height: auto !important;
            <?php endif; ?>
        }

        /* Input fields */
        body.login input[type="text"],
        body.login input[type="password"] {
            border-radius: 8px !important;
            border: 1px solid rgba(0,0,0,0.15) !important;
            padding: 10px 14px !important;
            font-size: 14px !important;
            box-shadow: none !important;
            transition: border-color 0.15s !important;
        }

        body.login input[type="text"]:focus,
        body.login input[type="password"]:focus {
            border-color: <?php echo $color; ?> !important;
            box-shadow: 0 0 0 2px <?php echo $color; ?>20 !important;
            outline: none !important;
        }

        /* Submit button */
        body.login .button-primary {
            background: <?php echo $color; ?> !important;
            border: none !important;
            border-radius: 8px !important;
            box-shadow: none !important;
            font-size: 14px !important;
            font-weight: 600 !important;
            height: 40px !important;
            letter-spacing: 0.01em !important;
            transition: opacity 0.15s !important;
        }

        body.login .button-primary:hover {
            opacity: 0.85 !important;
        }

        /* Labels */
        body.login label {
            font-size: 13px !important;
            font-weight: 500 !important;
            color: #1D1D1F !important;
        }

        /* Remember me and lost password */
        body.login #nav a,
        body.login #backtoblog a {
            color: <?php echo $color; ?> !important;
            font-size: 13px !important;
        }

        /* Remove WordPress back-to-blog link */
        body.login #backtoblog {
            display: none !important;
        }

        /* Powered by footer */
        body.login #cleversay-login-footer {
            text-align: center;
            margin-top: 20px;
            font-size: 11px;
            color: #86868B;
        }

        body.login #cleversay-login-footer a {
            color: #86868B !important;
            text-decoration: none !important;
        }

        body.login #cleversay-login-footer a:hover {
            color: <?php echo $color; ?> !important;
        }

        /* Error messages */
        body.login #login_error {
            border-left-color: #ff3b30 !important;
            border-radius: 8px !important;
        }

        /* Success messages */
        body.login .message {
            border-left-color: <?php echo $color; ?> !important;
            border-radius: 8px !important;
        }
        </style>

        <script>
        // Inject "Powered by CleverSay" footer below the login form
        document.addEventListener('DOMContentLoaded', function () {
            var loginDiv = document.getElementById('login');
            if (!loginDiv) return;
            var footer = document.createElement('div');
            footer.id = 'cleversay-login-footer';
            footer.innerHTML = 'Powered by <a href="https://cleversay.com" target="_blank" rel="noopener">CleverSay</a>';
            loginDiv.appendChild(footer);
        });
        </script>
        <?php
    }

    /**
     * Replace logo link URL — point to the subsite home instead of wordpress.org.
     */
    public function login_header_url(string $url): string {
        return home_url();
    }

    /**
     * Replace logo title text with client name.
     */
    public function login_header_text(string $text): string {
        $plan = $this->get_current_plan();
        return esc_html($plan['client_name'] ?? get_bloginfo('name'));
    }

    /**
     * Add custom class to login body for additional CSS targeting.
     */
    public function login_body_class(array $classes): array {
        $classes[] = 'cleversay-login';
        return $classes;
    }
}
