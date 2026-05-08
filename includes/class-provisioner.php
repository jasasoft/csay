<?php
namespace CleverSay;

defined('ABSPATH') || exit;

/**
 * Provisions new client subsites in a WordPress Multisite install.
 *
 * Takes a single form submission and performs all the steps needed to spin up
 * a working CleverSay site:
 *   1. Create the subsite via wpmu_create_blog()
 *   2. Persist persona and branding options on the new site
 *   3. Save site plan (trial / basic) with limits + expiration
 *   4. Install the selected starter KB pack (or skip for "empty")
 *   5. Optionally create a WP admin user and send welcome email
 *
 * Returns the new site_id on success, or WP_Error on failure.
 */
class Provisioner {

    /**
     * @param array $data {
     *   subdomain      string   required — the subdomain slug (no protocol, no dots)
     *   title          string   required — human-readable site title
     *   client_name    string   required — organization name
     *   persona_short  string   optional — bot short name, defaults to client_name
     *   mascot         string   optional
     *   topics         string   optional — comma separated
     *   tone           string   optional — friendly | formal | casual (defaults friendly)
     *   audience       string   optional
     *   primary_color  string   optional — hex
     *   starter_kb     string   pack slug from StarterKB::packs()
     *   plan           string   required — 'trial' | 'basic'
     *   client_email   string   optional — where login info goes if send_credentials = true
     *   send_credentials bool   optional — create WP user + email login details
     * }
     *
     * @return int|\WP_Error site_id on success
     */
    public static function provision(array $data) {
        if (!is_multisite()) {
            return new \WP_Error('not_multisite', 'Provisioning requires WordPress Multisite.');
        }
        if (!current_user_can('manage_network_options')) {
            return new \WP_Error('forbidden', 'You do not have permission to provision sites.');
        }

        // Validate required fields
        $subdomain = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($data['subdomain'] ?? '')));
        if ($subdomain === '' || strlen($subdomain) < 2) {
            return new \WP_Error('invalid_subdomain', 'Subdomain must be at least 2 characters, letters/numbers/hyphens only.');
        }
        $title = trim($data['title'] ?? '');
        if ($title === '') {
            return new \WP_Error('missing_title', 'Site title is required.');
        }
        $client_name = trim($data['client_name'] ?? '');
        if ($client_name === '') {
            return new \WP_Error('missing_client_name', 'Client / organization name is required.');
        }

        $plan = in_array($data['plan'] ?? '', ['trial', 'basic'], true) ? $data['plan'] : 'trial';

        // Compose the target domain
        $main_domain = defined('DOMAIN_CURRENT_SITE') ? DOMAIN_CURRENT_SITE : get_network()->domain;
        $full_domain = $subdomain . '.' . $main_domain;

        // Check for collision
        if (domain_exists($full_domain, '/')) {
            return new \WP_Error('domain_exists', 'A site with this subdomain already exists.');
        }

        // Figure out who owns the new site
        $current_user = wp_get_current_user();
        $client_email = sanitize_email($data['client_email'] ?? '');
        $send_creds   = !empty($data['send_credentials']);

        // If sending credentials, create/lookup a user with that email
        $new_user_id = 0;
        if ($send_creds && $client_email !== '') {
            $existing = get_user_by('email', $client_email);
            if ($existing) {
                $new_user_id = (int) $existing->ID;
            } else {
                // Derive a username from email local-part
                $username = sanitize_user(
                    preg_replace('/[^a-z0-9_]/', '', strtolower(strtok($client_email, '@'))),
                    true
                );
                if ($username === '' || username_exists($username)) {
                    $username = $username . wp_generate_password(4, false);
                }
                $password = wp_generate_password(16, true);
                $new_user_id = wpmu_create_user($username, $password, $client_email);
                if (!$new_user_id) {
                    return new \WP_Error('user_create_failed', 'Could not create user account for ' . $client_email);
                }
            }
        }

        // Fall back to current super-admin as site owner when not creating a client user
        $owner_id = $new_user_id ?: $current_user->ID;

        // Create the subsite
        $site_id = wpmu_create_blog(
            $full_domain,
            '/',
            $title,
            $owner_id,
            ['public' => 1],
            get_current_network_id()
        );
        if (is_wp_error($site_id)) {
            return $site_id;
        }
        $site_id = (int) $site_id;

        // If we created a dedicated client user, make them admin on the new subsite
        if ($new_user_id && $new_user_id !== (int) $current_user->ID) {
            add_user_to_blog($site_id, $new_user_id, 'administrator');
        }

        // Persist all CleverSay config on the new site
        switch_to_blog($site_id);
        try {
            self::install_persona($data, $client_name);
            self::install_site_plan($data, $plan, $client_name, $client_email);
            self::install_starter_kb($data['starter_kb'] ?? 'empty');
        } finally {
            restore_current_blog();
        }

        // Send welcome email if requested
        if ($send_creds && $client_email !== '' && $new_user_id) {
            self::send_welcome_email($new_user_id, $site_id, $full_domain, $client_name);
        }

        return $site_id;
    }

    /**
     * Save persona settings on the currently-switched blog.
     *
     * Field names must match what admin/views/settings.php reads:
     *   - bot_name              (the main bot display name, line 362)
     *   - primary_color         (widget accent color, line 692)
     *   - persona_school_name   (organization name in the persona block)
     *   - persona_short_name    (short form used in conversation)
     *   - persona_mascot_name   (mascot/character name if used)
     *   - persona_tone, persona_audience, persona_topics
     */
    private static function install_persona(array $data, string $client_name): void {
        $existing = get_option('cleversay_options', []);
        if (!is_array($existing)) $existing = [];

        // Decide what to write into bot_name. User intent from the wizard is:
        //   - Mascot field (if filled) = the bot's character name, e.g. "Warhawk"
        //   - Short name field (if filled) = short organization name, e.g. "UWW"
        //   - Falls back to client organization name
        // Pick mascot first, then short, then client_name — whichever is filled.
        $mascot = trim($data['mascot'] ?? '');
        $short  = trim($data['persona_short'] ?? '');
        $bot_name = $mascot !== ''
                    ? $mascot
                    : ($short !== '' ? $short : $client_name);

        $persona = [
            'bot_name'            => $bot_name,                 // ← used by Settings UI + widget header
            'persona_school_name' => $client_name,
            'persona_short_name'  => $short ?: $client_name,
            'persona_mascot_name' => $mascot,
            'persona_tone'        => in_array(($data['tone'] ?? ''), ['friendly', 'formal', 'casual'], true)
                                     ? $data['tone']
                                     : 'friendly',
            'persona_audience'    => trim($data['audience'] ?? ''),
            'persona_topics'      => trim($data['topics'] ?? ''),
            'persona_extra'       => '',
        ];

        // Primary color lives inside cleversay_options (matches Settings UI at line 692)
        $color = trim($data['primary_color'] ?? '');
        if ($color !== '' && preg_match('/^#[0-9a-f]{3,8}$/i', $color)) {
            $persona['primary_color'] = $color;
        }

        // Preserve any already-set values; new provisioning writes fresh values
        update_option('cleversay_options', array_merge($existing, $persona));
    }

    /**
     * Save the site plan record. Trials get a 30-day expiration timestamp.
     */
    private static function install_site_plan(array $data, string $plan, string $client_name, string $client_email): void {
        $site_id = get_current_blog_id();
        $now_ts  = current_time('timestamp');

        $existing_plan = NetworkSettings::get_site_plan($site_id);
        $new = [
            'plan'           => $plan,
            'status'         => $plan === 'trial' ? 'trial' : 'active',
            'client_name'    => $client_name,
            'client_email'   => $client_email,
            'activated_date' => date('Y-m-d', $now_ts),
            'trial_ends_at'  => $plan === 'trial'
                                 ? date('Y-m-d', strtotime('+30 days', $now_ts))
                                 : '',
        ];
        NetworkSettings::save_site_plan($site_id, array_merge($existing_plan, $new));
    }

    /**
     * Bulk-insert the selected starter KB pack, if any. Skips entries that
     * already exist (matched on keyword + sub_keyword + question) so this
     * can be called safely against an existing site without clobbering
     * customizations or creating duplicates.
     *
     * @return array{added: int, skipped: int} count summary
     */
    public static function install_starter_kb(string $pack_slug): array {
        $summary = ['added' => 0, 'skipped' => 0];

        $packs = StarterKB::packs();
        if (!isset($packs[$pack_slug]) || empty($packs[$pack_slug]['entries'])) {
            return $summary;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_knowledge';
        $now   = current_time('mysql');

        foreach ($packs[$pack_slug]['entries'] as $entry) {
            // Skip if an identical entry already exists — protects against
            // duplicate runs and against re-installing on a site that already
            // has the pack (or the same Q/A from another source).
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table
                 WHERE keyword = %s AND sub_keyword = %s AND question = %s",
                sanitize_text_field($entry['keyword']),
                sanitize_text_field($entry['sub_keyword']),
                sanitize_text_field($entry['question'])
            ));
            if ($exists > 0) {
                $summary['skipped']++;
                continue;
            }

            $wpdb->insert($table, [
                'keyword'     => sanitize_text_field($entry['keyword']),
                'sub_keyword' => sanitize_text_field($entry['sub_keyword']),
                'question'    => sanitize_text_field($entry['question']),
                'response'    => wp_kses_post($entry['response']),
                'status'      => 'active',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $summary['added']++;
        }

        return $summary;
    }

    /**
     * Email the client their login + a link to set their password.
     */
    private static function send_welcome_email(int $user_id, int $site_id, string $domain, string $client_name): void {
        $user = get_user_by('id', $user_id);
        if (!$user) return;

        $reset_key  = get_password_reset_key($user);
        if (is_wp_error($reset_key)) return;

        $login_url  = network_site_url('wp-login.php?action=rp&key=' . $reset_key . '&login=' . rawurlencode($user->user_login));
        $site_url   = 'https://' . $domain;

        $subject = sprintf(
            /* translators: %s = client organization name */
            __('Welcome to CleverSay, %s', 'cleversay'),
            $client_name
        );

        $message =
            "Hi,\n\n" .
            "Your CleverSay chatbot is ready. Here's how to get started:\n\n" .
            "1. Set your password (link expires in 24 hours):\n" .
            "   {$login_url}\n\n" .
            "2. Log in to your dashboard:\n" .
            "   {$site_url}/wp-admin/\n\n" .
            "3. Your login username is: {$user->user_login}\n\n" .
            "From the dashboard you can customize your chatbot's knowledge, appearance, and behavior.\n\n" .
            "If you have questions, just reply to this email — we're here to help.";

        wp_mail($user->user_email, $subject, $message);
    }
}
