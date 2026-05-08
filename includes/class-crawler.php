<?php
/**
 * Web Crawler — discovers and indexes pages from a starting URL.
 *
 * Two-phase design to avoid HTTP timeouts:
 *   Phase 1 (ajax_crawl_discover)  — BFS link discovery, returns URL list
 *   Phase 2 (ajax_crawl_index_next) — caller loops, indexing one URL per call
 *
 * @package CleverSay
 * @since   2.5.2
 */

namespace CleverSay;

if (!defined('ABSPATH')) exit;

class Crawler {

    private const TRANSIENT_PREFIX = 'cleversay_crawl_';
    private const TRANSIENT_TTL    = HOUR_IN_SECONDS * 4;

    /** URL schemes / extensions we never follow */
    private const SKIP_EXTENSIONS = [
        'jpg','jpeg','png','gif','webp','svg','ico','pdf','zip','gz',
        'doc','xls','ppt','mp3','mp4','avi','mov','css','js','woff','woff2',
    ];

    // -------------------------------------------------------------------------

    /**
     * BFS discovery: collect all URLs reachable from $start_url within the
     * given depth / page-count constraints.
     *
     * @param string $start_url     Seed URL
     * @param int    $max_depth     How many link-hops to follow (1 = seed page only)
     * @param int    $max_pages     Hard cap on total URLs collected
     * @param string $restrict_path Only follow links whose path starts with this
     *                              string (e.g. "/admissions" restricts to that subtree).
     *                              Empty string means no path restriction.
     * @param int    $request_delay Seconds to wait between requests. Higher values
     *                              avoid WAF rate limits at the cost of crawl speed.
     *                              2-3 seconds is a safe default for most sites.
     * @return array{
     *   urls:   string[],  — ordered list of discovered URLs
     *   errors: string[],  — non-fatal warnings
     * }
     */
    public function discover(
        string $start_url,
        int    $max_depth    = 2,
        int    $max_pages    = 50,
        string $restrict_path = '',
        int    $request_delay = 2
    ): array {
        $start_url = esc_url_raw($start_url);
        if (empty($start_url)) {
            return ['urls' => [], 'errors' => [__('Invalid start URL.', 'cleversay')]];
        }

        $parsed_start = wp_parse_url($start_url);
        $base_domain  = strtolower($parsed_start['host'] ?? '');
        $restrict_path = rtrim($restrict_path, '/');

        // BFS state
        $queue    = [['url' => $start_url, 'depth' => 0]];
        $visited  = [];
        $found    = [];
        $html_cache = []; // save fetched HTML to avoid re-fetching during indexing
        $errors   = [];

        while (!empty($queue) && count($found) < $max_pages) {
            $item  = array_shift($queue);
            $url   = $item['url'];
            $depth = $item['depth'];

            $normalised = $this->normalise_url($url);
            if (isset($visited[$normalised])) continue;
            $visited[$normalised] = true;

            // Polite delay after the first page. WAFs (Cloudflare, Sucuri,
            // Akamai) will start issuing 403s if we hit pages back-to-back —
            // 2-3 seconds is the sweet spot between speed and not getting blocked.
            if (!empty($found) && $request_delay > 0) {
                sleep($request_delay);
            }

            // Fetch with automatic retry on transient WAF/rate-limit responses
            $response = $this->fetch_with_retry($url, $start_url);

            if (is_wp_error($response)) {
                $errors[] = sprintf(__('Could not fetch %s: %s', 'cleversay'), $url, $response->get_error_message());
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 400) {
                $errors[] = sprintf(__('HTTP %d for %s — skipped.', 'cleversay'), $code, $url);
                continue;
            }

            $content_type = wp_remote_retrieve_header($response, 'content-type');
            if (!empty($content_type) && !str_contains($content_type, 'text/html')) {
                continue;
            }

            $html = wp_remote_retrieve_body($response);
            $found[] = $url;

            // Cache the HTML so indexing doesn't need to re-fetch this URL
            // Limit cache size to avoid transient size issues — store up to 30 pages
            if (count($html_cache) < 30) {
                $html_cache[$url] = $html;
            }

            // Don't follow links beyond max_depth
            if ($depth >= $max_depth) continue;
            if (count($found) >= $max_pages) break;

            $links = $this->extract_links($html, $url, $base_domain, $restrict_path);

            foreach ($links as $link) {
                $ln = $this->normalise_url($link);
                if (!isset($visited[$ln])) {
                    $queue[] = ['url' => $link, 'depth' => $depth + 1];
                }
            }
        }

        return ['urls' => $found, 'errors' => $errors, 'html_cache' => $html_cache];
    }

    // -------------------------------------------------------------------------

    /**
     * Extract all valid same-domain (optionally same-path) links from HTML.
     */
    private function extract_links(
        string $html,
        string $base_url,
        string $base_domain,
        string $restrict_path,
        bool   $main_content_only = true
    ): array {
        $links = [];

        // Use DOMDocument for reliable link extraction
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        // Decide which DOM scope to extract links from. By default we restrict
        // to the main content area to avoid pulling navigation/header/footer
        // links — these explode the crawl into unrelated parts of the site.
        $scope = null;
        if ($main_content_only) {
            $scope = $this->find_main_content_node($dom);
        }
        $anchors = $scope
            ? $scope->getElementsByTagName('a')
            : $dom->getElementsByTagName('a');

        foreach ($anchors as $a) {
            $href = trim($a->getAttribute('href'));
            if (empty($href)) continue;

            // Resolve relative URLs
            $absolute = $this->resolve_url($href, $base_url);
            if ($absolute === null) continue;

            if (!$this->is_allowed($absolute, $base_domain, $restrict_path)) continue;

            $links[] = $absolute;
        }

        return array_unique($links);
    }

    /**
     * Find the most likely "main content" DOM node so we can ignore links
     * that appear in site navigation/header/footer.
     *
     * Priority order:
     *   1. <main> element  (HTML5 standard)
     *   2. element with [role="main"]
     *   3. <article> element
     *   4. Common content containers by id/class
     *   5. null → caller falls back to whole document
     */
    private function find_main_content_node(\DOMDocument $dom): ?\DOMNode {
        // 1. <main>
        $mains = $dom->getElementsByTagName('main');
        if ($mains->length > 0) return $mains->item(0);

        // 2. [role="main"]  (XPath needed for attribute selection)
        $xpath = new \DOMXPath($dom);
        $role_main = $xpath->query('//*[@role="main"]');
        if ($role_main && $role_main->length > 0) return $role_main->item(0);

        // 3. <article>
        $articles = $dom->getElementsByTagName('article');
        if ($articles->length > 0) return $articles->item(0);

        // 4. Common content container ids/classes — checked in priority order
        $candidates = [
            '//*[@id="content"]',
            '//*[@id="main"]',
            '//*[@id="main-content"]',
            '//*[@id="primary"]',
            '//*[@id="page-content"]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " main-content ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " article-body ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " content-area ")]',
        ];
        foreach ($candidates as $expr) {
            $nodes = $xpath->query($expr);
            if ($nodes && $nodes->length > 0) return $nodes->item(0);
        }

        // No clear main-content area found — return null so caller falls back
        return null;
    }

    /**
     * Check if a URL is crawlable given our constraints.
     */
    private function is_allowed(string $url, string $base_domain, string $restrict_path): bool {
        $parts = wp_parse_url($url);

        // Must be http/https
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'])) return false;

        // Must be same domain
        $host = strtolower($parts['host'] ?? '');
        if ($host !== $base_domain) return false;

        // Skip known non-content extensions
        $path = $parts['path'] ?? '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, self::SKIP_EXTENSIONS)) return false;

        // Path restriction
        if (!empty($restrict_path)) {
            if (!str_starts_with($path, $restrict_path)) return false;
        }

        return true;
    }

    /**
     * Resolve a potentially relative href into an absolute URL.
     */
    private function resolve_url(string $href, string $base): ?string {
        // Strip fragment and query for deduplication
        $href = strtok($href, '#');
        if (empty($href)) return null;

        // Already absolute
        if (preg_match('#^https?://#i', $href)) {
            return rtrim($href, '/');
        }

        // Protocol-relative
        if (str_starts_with($href, '//')) {
            $scheme = str_starts_with($base, 'https') ? 'https' : 'http';
            return rtrim($scheme . ':' . $href, '/');
        }

        // Skip non-http schemes
        if (str_contains($href, ':')) return null;

        $parts = wp_parse_url($base);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');

        if (str_starts_with($href, '/')) {
            return rtrim($origin . $href, '/');
        }

        // Relative to current path
        $base_path = dirname($parts['path'] ?? '/');
        return rtrim($origin . $base_path . '/' . $href, '/');
    }

    /**
     * Strip query string and trailing slash for visited-URL deduplication.
     */
    /**
     * Public, static URL normaliser. Used everywhere we need a canonical
     * form of a URL for storage or duplicate checks — keeps "...page" and
     * "...page/" from creating two separate sources.
     *
     * Rules:
     *   - lowercase scheme + host
     *   - strip trailing slash from path (keep "/" for root)
     *   - drop fragment
     *   - preserve query string as-is (different ?params = different page)
     */
    public static function normalise(string $url): string {
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host'])) return $url;
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host   = strtolower($parts['host']);
        $path   = rtrim($parts['path'] ?? '/', '/');
        $query  = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        return $scheme . '://' . $host . ($path ?: '/') . $query;
    }

    private function normalise_url(string $url): string {
        return self::normalise($url);
    }

    /** Public wrapper for incremental discovery in admin AJAX handler */
    public function normalise_url_public(string $url): string {
        return $this->normalise_url($url);
    }

    /** Public wrapper for incremental discovery in admin AJAX handler */
    public function extract_links_public(string $html, string $base_url, string $base_domain, string $restrict_path, bool $main_content_only = true): array {
        return $this->extract_links($html, $base_url, $base_domain, $restrict_path, $main_content_only);
    }

    // -------------------------------------------------------------------------
    // Transient helpers for storing crawl state between AJAX calls
    // -------------------------------------------------------------------------

    public function save_job(string $job_id, array $data): void {
        set_transient(self::TRANSIENT_PREFIX . $job_id, $data, self::TRANSIENT_TTL);
    }

    public function get_job(string $job_id): ?array {
        $data = get_transient(self::TRANSIENT_PREFIX . $job_id);
        return is_array($data) ? $data : null;
    }

    public function delete_job(string $job_id): void {
        delete_transient(self::TRANSIENT_PREFIX . $job_id);
    }

    /**
     * Public wrapper for fetch_with_retry — used by the per-page admin AJAX
     * handler that processes the crawl one URL at a time.
     */
    public function fetch_with_retry_public(string $url, string $start_url) {
        return $this->fetch_with_retry($url, $start_url);
    }

    /**
     * Fetch a URL with automatic retry on transient WAF / rate-limit responses.
     *
     * Cloudflare, Sucuri, Akamai, and similar protection layers issue 403/429/503
     * responses when they think traffic is bot-like. They typically clear after
     * a short cool-off period, so a single backed-off retry recovers most of
     * these cases without manual intervention.
     *
     * @return array|\WP_Error wp_remote_get response or WP_Error after final retry
     */
    private function fetch_with_retry(string $url, string $start_url) {
        $args = [
            'timeout'    => 15,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'sslverify'  => false,
            'headers'    => [
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
                'Referer'         => $start_url,
            ],
        ];

        // Codes that often clear after a cool-off — worth retrying once
        $retry_codes = [403, 429, 503];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return $response;  // Network-level failures don't benefit from instant retry
        }

        $code = wp_remote_retrieve_response_code($response);
        if (in_array($code, $retry_codes, true)) {
            // WAF rate-limit window is typically 30-60s. 8s is a reasonable
            // compromise — long enough for most rate-limiters to forget us,
            // short enough not to make a 50-page crawl take an extra hour.
            sleep(8);
            $response = wp_remote_get($url, $args);
        }

        return $response;
    }
}
