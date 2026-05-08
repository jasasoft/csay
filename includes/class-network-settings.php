<?php
/**
 * CleverSay Network Settings
 *
 * Manages network-wide settings stored at the site_meta level.
 * These override per-site settings for AI and Advanced configuration.
 * Only accessible by the Network Admin (super admin).
 *
 * @package CleverSay
 * @since   4.0.0
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class NetworkSettings {

    // Network-level option keys (stored in wp_sitemeta)
    const OPTION_AI     = 'cleversay_network_ai';
    const OPTION_ADV    = 'cleversay_network_advanced';
    const OPTION_PLANS  = 'cleversay_network_plans';

    // Default AI settings applied to all sites
    private static array $ai_defaults = [
        'api_key'           => '',
        // v4.37.74+: per-provider API keys so admin doesn't have to
        // re-enter when switching between Claude and Gemini. The
        // legacy 'api_key' is retained for back-compat reads — when
        // a provider-specific key isn't set, we fall back to it.
        // Save handler writes to the matching provider field AND
        // mirrors to the legacy field for existing callers that
        // haven't migrated.
        'anthropic_api_key' => '',
        'gemini_api_key'    => '',
        'model'             => 'claude-haiku-4-5-20251001',
        'validator_model'   => 'claude-sonnet-4-5-20250929',
        'synthesis_model'   => 'claude-sonnet-4-5-20250929',
        'max_tokens'        => 450,
        'ai_enabled'        => false,
        'monthly_budget'    => 0,
        'fallback_threshold'=> 70,
        'validate_kb'       => true,
        'polish_kb'         => true,
        'aadefault_validate'=> false,
        'multilingual'      => false,
    ];

    // Default advanced settings applied to all sites
    private static array $adv_defaults = [
        'rate_limit_enabled'    => true,
        'rate_limit_requests'   => 30,
        'rate_limit_window'     => 60,
        'cache_duration'        => 300,
        'debug_mode'            => false,
        'min_match_score'       => 70,
        'max_results'           => 5,
        'embed_domains'         => '',
        'log_retention_days'    => 30,
    ];

    /**
     * Get network AI settings.
     * Returns merged defaults + saved values.
     */
    public static function get_ai(): array {
        $saved = get_site_option(self::OPTION_AI, []);
        return array_merge(self::$ai_defaults, is_array($saved) ? $saved : []);
    }

    /**
     * Get network Advanced settings.
     */
    public static function get_advanced(): array {
        $saved = get_site_option(self::OPTION_ADV, []);
        return array_merge(self::$adv_defaults, is_array($saved) ? $saved : []);
    }

    /**
     * Get a single AI setting value.
     */
    public static function get_ai_value(string $key, mixed $default = null): mixed {
        $settings = self::get_ai();
        return $settings[$key] ?? $default ?? (self::$ai_defaults[$key] ?? null);
    }

    /**
     * Get a single Advanced setting value.
     */
    public static function get_adv_value(string $key, mixed $default = null): mixed {
        $settings = self::get_advanced();
        return $settings[$key] ?? $default ?? (self::$adv_defaults[$key] ?? null);
    }

    /**
     * Save network AI settings.
     */
    public static function save_ai(array $data): bool {
        $clean = [];
        foreach (self::$ai_defaults as $key => $default) {
            if (!isset($data[$key])) {
                $clean[$key] = is_bool($default) ? false : $default;
                continue;
            }
            $clean[$key] = match(true) {
                is_bool($default)  => (bool) $data[$key],
                is_int($default)   => (int) $data[$key],
                is_float($default) => (float) $data[$key],
                default            => sanitize_text_field((string) $data[$key]),
            };
        }
        return update_site_option(self::OPTION_AI, $clean);
    }

    /**
     * Save network Advanced settings.
     */
    public static function save_advanced(array $data): bool {
        $clean = [];
        foreach (self::$adv_defaults as $key => $default) {
            if (!isset($data[$key])) {
                $clean[$key] = is_bool($default) ? false : $default;
                continue;
            }
            $clean[$key] = match(true) {
                is_bool($default)  => (bool) $data[$key],
                is_int($default)   => (int) $data[$key],
                is_float($default) => (float) $data[$key],
                default            => ($key === 'embed_domains')
                                        ? sanitize_textarea_field((string) $data[$key])
                                        : sanitize_text_field((string) $data[$key]),
            };
        }
        return update_site_option(self::OPTION_ADV, $clean);
    }

    /**
     * Get AI configuration — single source of truth for both Multisite and single-site.
     *
     * In Multisite: reads from network settings (wp_sitemeta).
     * Single site:  reads from per-site options (wp_options).
     *
     * Use this everywhere instead of calling get_option('cleversay_ai_*') directly.
     */
    public static function get_ai_config(): array {
        if (self::is_multisite()) {
            $net = self::get_ai();
            // v4.37.74+: resolve the active key from the per-provider
            // store, derived from the model's provider. Legacy
            // 'api_key' field used as fallback for installs that
            // haven't yet saved on this version.
            $model            = $net['model'] ?? 'claude-haiku-4-5-20251001';
            $active_provider  = self::provider_for_model($model);
            $anthropic_key    = (string) ($net['anthropic_api_key'] ?? '');
            $gemini_key       = (string) ($net['gemini_api_key']    ?? '');
            $legacy_key       = (string) ($net['api_key']           ?? '');
            $active_key = $active_provider === 'gemini'
                ? ($gemini_key    !== '' ? $gemini_key    : $legacy_key)
                : ($anthropic_key !== '' ? $anthropic_key : $legacy_key);

            return [
                'api_key'            => $active_key,
                'anthropic_api_key'  => $anthropic_key,
                'gemini_api_key'     => $gemini_key,
                'active_provider'    => $active_provider,
                'enabled'            => !empty($net['ai_enabled']),
                'model'              => $model,
                'validator_model'    => (string) ($net['validator_model'] ?? 'claude-sonnet-4-5-20250929'),
                'synthesis_model'    => (string) ($net['synthesis_model'] ?? 'claude-sonnet-4-5-20250929'),
                'max_tokens'         => (int)   ($net['max_tokens']         ?? 450),
                'monthly_budget'     => (float) ($net['monthly_budget']     ?? 0),
                'fallback_threshold' => (int)   ($net['fallback_threshold'] ?? 70),
                'validate_kb'        => !empty($net['validate_kb']),
                'polish_kb'          => !empty($net['polish_kb']),
                'aadefault_validate' => !empty($net['aadefault_validate']),
                'multilingual'       => !empty($net['multilingual']),
                // Per-site options that clients CAN configure
                'max_chunks'         => (int)    get_option('cleversay_ai_max_chunks', 4),
                'label'              => (string) get_option('cleversay_ai_label', 'AI-assisted answer'),
                'normalize_queries'  => (bool)   get_option('cleversay_ai_normalize_queries', false),
            ];
        }

        // Single site — read everything from wp_options.
        // v4.37.74+: also support per-provider keys here.
        $model           = (string) get_option('cleversay_ai_model', 'claude-haiku-4-5-20251001');
        $active_provider = self::provider_for_model($model);
        $anthropic_key   = (string) get_option('cleversay_anthropic_api_key', '');
        $gemini_key      = (string) get_option('cleversay_gemini_api_key',    '');
        $legacy_key      = (string) get_option('cleversay_ai_api_key',        '');
        $active_key = $active_provider === 'gemini'
            ? ($gemini_key    !== '' ? $gemini_key    : $legacy_key)
            : ($anthropic_key !== '' ? $anthropic_key : $legacy_key);

        return [
            'api_key'            => $active_key,
            'anthropic_api_key'  => $anthropic_key,
            'gemini_api_key'     => $gemini_key,
            'active_provider'    => $active_provider,
            'enabled'            => (bool)   get_option('cleversay_ai_enabled',           false),
            'model'              => $model,
            'validator_model'    => (string) get_option('cleversay_ai_validator_model',   'claude-sonnet-4-5-20250929'),
            'synthesis_model'    => (string) get_option('cleversay_ai_synthesis_model',   'claude-sonnet-4-5-20250929'),
            'max_tokens'         => (int)    get_option('cleversay_ai_max_tokens',        450),
            'monthly_budget'     => (float)  get_option('cleversay_ai_monthly_budget',    0),
            'fallback_threshold' => (int)    get_option('cleversay_ai_min_score',         70),
            'validate_kb'        => (bool)   get_option('cleversay_ai_validate_kb',       true),
            'polish_kb'          => (bool)   get_option('cleversay_ai_polish_kb',         true),
            'aadefault_validate' => (bool)   get_option('cleversay_ai_aadefault_validate',false),
            'multilingual'       => (bool)   get_option('cleversay_ai_multilingual',      false),
            'max_chunks'         => (int)    get_option('cleversay_ai_max_chunks',        4),
            'label'              => (string) get_option('cleversay_ai_label',             'AI-assisted answer'),
            'normalize_queries'  => (bool)   get_option('cleversay_ai_normalize_queries', false),
        ];
    }

    /**
     * Map a model string to its provider key ("anthropic" or "gemini").
     *
     * Lightweight version of \CleverSay\AI's PRICING-table lookup that
     * doesn't require the AI class to be loaded. Used by settings
     * resolution which runs on every admin page render.
     *
     * @since 4.37.74
     */
    public static function provider_for_model(string $model): string {
        if ($model === '' || stripos($model, 'gemini') === 0 || stripos($model, 'gemini-') !== false) {
            // gemini-3-flash-preview, gemini-2.5-flash, etc.
            return stripos($model, 'gemini') !== false ? 'gemini' : 'anthropic';
        }
        return 'anthropic';
    }

    /**
     * Quick check — is AI configured and enabled?
     */
    public static function ai_is_configured(): bool {
        $cfg = self::get_ai_config();
        return !empty($cfg['api_key']) && !empty($cfg['enabled']);
    }

    /**
     * Check if the current environment is WordPress Multisite.
     */
    public static function is_multisite(): bool {
        return function_exists('is_multisite') && is_multisite();
    }

    /**
     * Check if the current user is a network super admin.
     */
    public static function is_network_admin(): bool {
        return is_super_admin();
    }

    /**
     * Get per-site plan limits.
     * Stored in blog_meta for each site.
     */
    public static function get_site_plan(int $site_id): array {
        $defaults = [
            'plan'              => 'basic',
            'status'            => 'active',   // active | suspended | trial | expired
            'kb_limit'          => 500,
            'ai_calls_monthly'  => 1000,
            'ai_budget_monthly' => 10.00,
            'client_name'       => '',
            'client_logo_url'   => '',
            'client_email'      => '',
            'activated_date'    => '',
            'trial_ends_at'     => '',         // ISO date — only meaningful when status = trial
            // Track which trial-expiration emails we've already sent so the
            // daily cron doesn't spam the same client. Each is set to a
            // timestamp string when sent. Emptied when trial dates change.
            'trial_warned_7d'    => '',
            'trial_warned_1d'    => '',
            'trial_expired_at'   => '',  // when status flipped to suspended-after-trial
            'embed_domains'     => '',         // newline-separated allowed domains
        ];
        $saved = get_blog_option($site_id, 'cleversay_site_plan', []);
        return array_merge($defaults, is_array($saved) ? $saved : []);
    }

    /**
     * Save per-site plan settings.
     */
    public static function save_site_plan(int $site_id, array $data): bool {
        return update_blog_option($site_id, 'cleversay_site_plan', $data);
    }

    /**
     * Get all client sites (excludes main site, network, staging).
     */
    public static function get_client_sites(): array {
        if (!self::is_multisite()) {
            return [];
        }

        $sites = get_sites([
            'number'   => 100,
            'orderby'  => 'domain',
            'order'    => 'ASC',
        ]);

        $client_sites = [];
        foreach ($sites as $site) {
            // Skip main site and reserved subdomains
            $reserved = ['network', 'staging'];
            $subdomain = explode('.', $site->domain)[0];
            if ($site->blog_id == get_main_site_id() || in_array($subdomain, $reserved)) {
                continue;
            }
            $plan = self::get_site_plan((int) $site->blog_id);
            $client_sites[] = array_merge([
                'blog_id'   => (int) $site->blog_id,
                'domain'    => $site->domain,
                'path'      => $site->path,
                'registered'=> $site->registered,
                'last_updated' => $site->last_updated,
            ], $plan);
        }

        return $client_sites;
    }
}
