<?php
/**
 * CleverSay TenantHelper
 *
 * Multi-tenant utility code: deciding whether a blog is an "active tenant"
 * with real CleverSay content, and iteration helpers that skip non-tenant
 * blogs.
 *
 * Background: in a network multisite install, blog 1 is typically the
 * network's main landing page — not a CleverSay tenant. Earlier versions
 * of the embedding pipeline iterated every blog returned by get_sites(),
 * which caused stranded rows tagged with tenant_id='1' to be written to
 * Supabase from blog 1's empty MySQL context. (See ARCHITECTURE.md and
 * the v4.41.0 handoff brief, Bug 3.)
 *
 * Active-tenant rule: a blog is considered a tenant when its CleverSay
 * knowledge OR sources tables contain at least one row. The presence of
 * actual KB content is the single source of truth — option flags can
 * lie, but content can't. Empty subsites (the network main site, or a
 * provisioned-but-unused tenant) are skipped.
 *
 * @package CleverSay
 * @since   4.41.0
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class TenantHelper {

    /**
     * Per-request cache of activeness decisions, keyed by blog_id. Cleared
     * implicitly on the next request — these checks are cheap but we
     * still avoid running them repeatedly during a single page load (the
     * multi-site overview table can ask once per blog).
     *
     * @var array<int,bool>
     */
    private static array $cache = [];

    /**
     * Decide whether a given blog is an active CleverSay tenant.
     *
     * Definition: the blog's CleverSay tables exist AND contain at least
     * one knowledge entry OR one source. Provisioning creates the tables
     * empty on every subsite at activation time, so table existence alone
     * isn't enough — we need actual content.
     *
     * Runs in the calling blog's context. Callers from network admin
     * should switch_to_blog before calling, OR pass a $blog_id which we
     * handle internally.
     *
     * @param int|null $blog_id Blog to check. Null = current blog.
     * @return bool             True if the blog has CleverSay content.
     */
    public static function is_tenant_active(?int $blog_id = null): bool {
        $blog_id = $blog_id !== null ? (int) $blog_id : (int) get_current_blog_id();

        if (isset(self::$cache[$blog_id])) {
            return self::$cache[$blog_id];
        }

        $needs_switch = is_multisite() && $blog_id !== (int) get_current_blog_id();
        if ($needs_switch) {
            switch_to_blog($blog_id);
        }

        try {
            global $wpdb;
            $kb_table     = $wpdb->prefix . 'cleversay_knowledge';
            $src_table    = $wpdb->prefix . 'cleversay_sources';

            // Suppress errors from missing tables — for a freshly-created
            // subsite where activation hasn't run yet, the query will fail.
            // That counts as inactive.
            $suppress_prev = $wpdb->suppress_errors(true);

            $kb_count  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$kb_table}");
            $src_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$src_table}");

            $wpdb->suppress_errors($suppress_prev);

            $active = ($kb_count > 0 || $src_count > 0);
        } finally {
            if ($needs_switch) {
                restore_current_blog();
            }
        }

        self::$cache[$blog_id] = $active;
        return $active;
    }

    /**
     * Run a callable in the context of every active tenant blog. The
     * callable receives the blog_id as its only argument and runs after
     * switch_to_blog has been called for that blog. restore_current_blog
     * is called automatically afterward, even if the callable throws.
     *
     * Single-site installs: the callable runs once with the current blog.
     * Multisite: iterates every blog where is_tenant_active() returns true.
     * Inactive tenants are skipped silently.
     *
     * @param callable $fn  function(int $blog_id): void
     * @return int          Number of tenants the callable ran against.
     */
    public static function iterate_active_tenants(callable $fn): int {
        if (!is_multisite()) {
            $fn((int) get_current_blog_id());
            return 1;
        }

        $sites = get_sites(['number' => 0, 'fields' => 'ids']);
        $count = 0;
        foreach ($sites as $site_id) {
            $site_id = (int) $site_id;
            switch_to_blog($site_id);
            try {
                if (!self::is_tenant_active($site_id)) {
                    continue;
                }
                $fn($site_id);
                $count++;
            } finally {
                restore_current_blog();
            }
        }
        return $count;
    }

    /**
     * Return the list of blog_ids that are active tenants, sorted ascending.
     * Cheap helper for views that need to render a row per tenant.
     *
     * Single-site installs return [current_blog_id].
     *
     * @return int[]
     */
    public static function active_tenant_ids(): array {
        if (!is_multisite()) {
            return [(int) get_current_blog_id()];
        }

        $sites  = get_sites(['number' => 0, 'fields' => 'ids']);
        $active = [];
        foreach ($sites as $site_id) {
            if (self::is_tenant_active((int) $site_id)) {
                $active[] = (int) $site_id;
            }
        }
        sort($active);
        return $active;
    }

    /**
     * Clear the per-request activeness cache. Useful in tests or after
     * destructive operations that change tenant content (e.g. wiping a
     * subsite's KB). Most callers can ignore this.
     */
    public static function flush_cache(): void {
        self::$cache = [];
    }
}
