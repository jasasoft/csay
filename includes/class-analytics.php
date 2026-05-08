<?php
/**
 * CleverSay Analytics
 *
 * Handles analytics tracking and reporting
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
 * Analytics Handler
 */
class Analytics {
    
    private const CACHE_GROUP = 'cleversay_analytics';
    private const CACHE_EXPIRY = 300; // 5 minutes
    
    /**
     * Get dashboard statistics
     */
    public function get_dashboard_stats(): array {
        $cached = wp_cache_get('dashboard_stats', self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        
        $knowledge_table = $wpdb->prefix . 'cleversay_knowledge';
        $questions_table = $wpdb->prefix . 'cleversay_questions';
        $visitors_table = $wpdb->prefix . 'cleversay_visitors';
        $inquiries_table = $wpdb->prefix . 'cleversay_inquiries';
        $ratings_table = $wpdb->prefix . 'cleversay_ratings';
        
        $stats = [
            // Knowledge base stats
            'total_entries' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$knowledge_table}"),
            'active_entries' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$knowledge_table} WHERE status = 'active'"
            ),
            'inactive_entries' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$knowledge_table} WHERE status = 'inactive'"
            ),
            'draft_entries' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$knowledge_table} WHERE status = 'draft'"
            ),
            'expiring_soon' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$knowledge_table} 
                 WHERE expires_at IS NOT NULL 
                 AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)"
            ),
            
            // Question stats
            'total_questions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$questions_table}"),
            'questions_today' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$questions_table} WHERE DATE(created_at) = CURDATE()"
            ),
            'questions_this_week' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$questions_table} 
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            ),
            'questions_this_month' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$questions_table} 
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
            ),
            
            // Match statistics
            'matched_questions' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$questions_table} 
                 WHERE match_type IS NOT NULL AND match_type != 'none'"
            ),
            'unmatched_questions' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$questions_table} 
                 WHERE match_type IS NULL OR match_type = 'none'"
            ),
            
            // Visitor stats
            'unique_visitors_today' => (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT ip_address) FROM {$visitors_table} 
                 WHERE DATE(last_visit) = CURDATE()"
            ),
            'unique_visitors_week' => (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT ip_address) FROM {$visitors_table} 
                 WHERE last_visit >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            ),
            'total_visitors' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$visitors_table}"),
            
            // Inquiry stats
            'pending_inquiries' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$inquiries_table} WHERE status = 'pending'"
            ),
            'total_inquiries' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$inquiries_table}"),
            
            // Rating stats
            'total_ratings' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ratings_table}"),
            'helpful_ratings' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$ratings_table} WHERE rating = 'helpful'"
            ),
            'not_helpful_ratings' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$ratings_table} WHERE rating = 'not_helpful'"
            ),
        ];
        
        // Calculate derived metrics
        $stats['match_rate'] = $stats['total_questions'] > 0 
            ? round(($stats['matched_questions'] / $stats['total_questions']) * 100, 1)
            : 0;
            
        $stats['helpfulness_rate'] = ($stats['helpful_ratings'] + $stats['not_helpful_ratings']) > 0
            ? round(($stats['helpful_ratings'] / ($stats['helpful_ratings'] + $stats['not_helpful_ratings'])) * 100, 1)
            : 0;
        
        wp_cache_set('dashboard_stats', $stats, self::CACHE_GROUP, self::CACHE_EXPIRY);
        
        return $stats;
    }
    
    /**
     * Get questions trend over time
     */
    public function get_questions_trend(int $days = 30): array {
        $cache_key = 'questions_trend_' . $days;
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_questions';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                DATE(created_at) as date,
                COUNT(*) as total,
                SUM(CASE WHEN match_type IS NOT NULL AND match_type != 'none' THEN 1 ELSE 0 END) as matched,
                SUM(CASE WHEN match_type IS NULL OR match_type = 'none' THEN 1 ELSE 0 END) as unmatched
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            $days
        ), ARRAY_A);
        
        // Fill in missing dates with zeros
        $trend = [];
        $start = new \DateTime("-{$days} days");
        $end = new \DateTime();
        
        $date_map = [];
        foreach ($results as $row) {
            $date_map[$row['date']] = $row;
        }
        
        $interval = new \DateInterval('P1D');
        $period = new \DatePeriod($start, $interval, $end);
        
        foreach ($period as $date) {
            $date_str = $date->format('Y-m-d');
            $trend[] = [
                'date' => $date_str,
                'total' => (int) ($date_map[$date_str]['total'] ?? 0),
                'matched' => (int) ($date_map[$date_str]['matched'] ?? 0),
                'unmatched' => (int) ($date_map[$date_str]['unmatched'] ?? 0),
            ];
        }
        
        wp_cache_set($cache_key, $trend, self::CACHE_GROUP, self::CACHE_EXPIRY);
        
        return $trend;
    }
    
    /**
     * Get top performing keywords
     */
    public function get_top_keywords(int $limit = 10, int $days = 30): array {
        $cache_key = "top_keywords_{$limit}_{$days}";
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        $questions_table = $wpdb->prefix . 'cleversay_questions';
        $knowledge_table = $wpdb->prefix . 'cleversay_knowledge';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                q.matched_keyword as keyword,
                COUNT(*) as hits,
                CASE WHEN (MAX(k.helpful_yes) + MAX(k.helpful_no)) > 0
                     THEN ROUND(MAX(k.helpful_yes) / (MAX(k.helpful_yes) + MAX(k.helpful_no)) * 100)
                     ELSE NULL END AS rating,
                MAX(k.response) as response
             FROM {$questions_table} q
             LEFT JOIN {$knowledge_table} k ON k.keyword = q.matched_keyword
             WHERE q.matched_keyword IS NOT NULL 
                AND q.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY q.matched_keyword
             ORDER BY hits DESC
             LIMIT %d",
            $days,
            $limit
        ), ARRAY_A);
        
        wp_cache_set($cache_key, $results, self::CACHE_GROUP, self::CACHE_EXPIRY);
        
        return $results;
    }
    
    /**
     * Get top questions for public display
     * Returns questions from knowledge base ordered by hits
     */
    public function get_top_questions(int $limit = 10): array {
        $cache_key = "top_questions_{$limit}";
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        $knowledge_table = $wpdb->prefix . 'cleversay_knowledge';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                id,
                question,
                keyword,
                hits
             FROM {$knowledge_table}
             WHERE status = 'active'
                AND (expires_at IS NULL OR expires_at > CURDATE())
                AND question IS NOT NULL
                AND question != ''
             ORDER BY hits DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);
        
        wp_cache_set($cache_key, $results, self::CACHE_GROUP, self::CACHE_EXPIRY);
        
        return $results ?: [];
    }
    
    /**
     * Get most common unmatched questions
     */
    public function get_unmatched_questions(int $limit = 20, int $days = 30): array {
        $cache_key = "unmatched_{$limit}_{$days}";
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_questions';
        
        // Group similar questions using simplified text
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                LOWER(TRIM(question)) as normalized_question,
                question,
                COUNT(*) as count,
                MAX(created_at) as last_asked
             FROM {$table}
             WHERE (match_type IS NULL OR match_type = 'none')
                AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY LOWER(TRIM(question))
             ORDER BY count DESC
             LIMIT %d",
            $days,
            $limit
        ), ARRAY_A);
        
        wp_cache_set($cache_key, $results, self::CACHE_GROUP, self::CACHE_EXPIRY);
        
        return $results;
    }
    
    /**
     * Get visitor geography (if GeoIP data available)
     */
    public function get_visitor_geography(int $days = 30): array {
        $cache_key = "geography_{$days}";
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_visitors';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                country_code,
                country_name,
                COUNT(*) as visitors,
                SUM(visit_count) as total_visits
             FROM {$table}
             WHERE country_code IS NOT NULL 
                AND country_code != ''
                AND last_visit >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY country_code, country_name
             ORDER BY visitors DESC
             LIMIT 20",
            $days
        ), ARRAY_A);
        
        wp_cache_set($cache_key, $results, self::CACHE_GROUP, self::CACHE_EXPIRY);
        
        return $results;
    }
    
    /**
     * Get visitor breakdown by US state
     */
    public function get_us_state_breakdown(int $days = 30): array {
        $cache_key = "us_states_{$days}";
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) return $cached;

        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_visitors';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                region as state,
                COUNT(*) as visitors,
                SUM(visit_count) as total_visits
             FROM {$table}
             WHERE country_code = 'US'
                AND region IS NOT NULL
                AND region != ''
                AND last_visit >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY region
             ORDER BY visitors DESC
             LIMIT 25",
            $days
        ), ARRAY_A);

        wp_cache_set($cache_key, $results, self::CACHE_GROUP, self::CACHE_EXPIRY);
        return $results ?: [];
    }

    /**
     * Get visitor breakdown by US city
     */
    public function get_us_city_breakdown(int $days = 30, string $state = ''): array {
        $cache_key = "us_cities_{$days}_{$state}";
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) return $cached;

        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_visitors';

        if (!empty($state)) {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT
                    city,
                    region as state,
                    COUNT(*) as visitors,
                    SUM(visit_count) as total_visits
                 FROM {$table}
                 WHERE country_code = 'US'
                    AND city IS NOT NULL AND city != ''
                    AND region = %s
                    AND last_visit >= DATE_SUB(NOW(), INTERVAL %d DAY)
                 GROUP BY city, region
                 ORDER BY visitors DESC
                 LIMIT 25",
                $state, $days
            ), ARRAY_A);
        } else {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT
                    city,
                    region as state,
                    COUNT(*) as visitors,
                    SUM(visit_count) as total_visits
                 FROM {$table}
                 WHERE country_code = 'US'
                    AND city IS NOT NULL AND city != ''
                    AND last_visit >= DATE_SUB(NOW(), INTERVAL %d DAY)
                 GROUP BY city, region
                 ORDER BY visitors DESC
                 LIMIT 25",
                $days
            ), ARRAY_A);
        }

        wp_cache_set($cache_key, $results, self::CACHE_GROUP, self::CACHE_EXPIRY);
        return $results ?: [];
    }

    /**
     * Get hourly activity pattern
     */
    public function get_hourly_pattern(int $days = 7): array {
        $cache_key = "hourly_{$days}";
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_questions';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                HOUR(created_at) as hour,
                COUNT(*) as count
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY HOUR(created_at)
             ORDER BY hour ASC",
            $days
        ), ARRAY_A);
        
        // Fill in missing hours
        $hourly = array_fill(0, 24, 0);
        foreach ($results as $row) {
            $hourly[(int)$row['hour']] = (int)$row['count'];
        }
        
        $pattern = [];
        for ($h = 0; $h < 24; $h++) {
            $pattern[] = [
                'hour' => $h,
                'label' => sprintf('%02d:00', $h),
                'count' => $hourly[$h],
            ];
        }
        
        wp_cache_set($cache_key, $pattern, self::CACHE_GROUP, self::CACHE_EXPIRY);
        
        return $pattern;
    }
    
    /**
     * Get weekday activity pattern
     */
    public function get_weekday_pattern(int $days = 30): array {
        $cache_key = "weekday_{$days}";
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_questions';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                DAYOFWEEK(created_at) as day_num,
                COUNT(*) as count
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY DAYOFWEEK(created_at)
             ORDER BY day_num ASC",
            $days
        ), ARRAY_A);
        
        $days_map = [
            1 => 'Sunday',
            2 => 'Monday', 
            3 => 'Tuesday',
            4 => 'Wednesday',
            5 => 'Thursday',
            6 => 'Friday',
            7 => 'Saturday',
        ];
        
        $weekday = array_fill(1, 7, 0);
        foreach ($results as $row) {
            $weekday[(int)$row['day_num']] = (int)$row['count'];
        }
        
        $pattern = [];
        for ($d = 1; $d <= 7; $d++) {
            $pattern[] = [
                'day' => $d,
                'label' => $days_map[$d],
                'short' => substr($days_map[$d], 0, 3),
                'count' => $weekday[$d],
            ];
        }
        
        wp_cache_set($cache_key, $pattern, self::CACHE_GROUP, self::CACHE_EXPIRY);
        
        return $pattern;
    }
    
    /**
     * Get performance comparison (this period vs previous)
     */
    public function get_performance_comparison(int $days = 7): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_questions';
        
        // Current period
        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN match_type IS NOT NULL AND match_type != 'none' THEN 1 ELSE 0 END) as matched
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ), ARRAY_A);
        
        // Previous period
        $previous = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN match_type IS NOT NULL AND match_type != 'none' THEN 1 ELSE 0 END) as matched
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days * 2,
            $days
        ), ARRAY_A);
        
        $current_rate = $current['total'] > 0 
            ? ($current['matched'] / $current['total']) * 100 
            : 0;
        $previous_rate = $previous['total'] > 0 
            ? ($previous['matched'] / $previous['total']) * 100 
            : 0;
        
        return [
            'current' => [
                'total' => (int) $current['total'],
                'matched' => (int) $current['matched'],
                'match_rate' => round($current_rate, 1),
            ],
            'previous' => [
                'total' => (int) $previous['total'],
                'matched' => (int) $previous['matched'],
                'match_rate' => round($previous_rate, 1),
            ],
            'change' => [
                'total' => $previous['total'] > 0 
                    ? round((($current['total'] - $previous['total']) / $previous['total']) * 100, 1)
                    : 0,
                'matched' => $previous['matched'] > 0
                    ? round((($current['matched'] - $previous['matched']) / $previous['matched']) * 100, 1)
                    : 0,
                'match_rate' => round($current_rate - $previous_rate, 1),
            ],
        ];
    }
    
    /**
     * Get search term analysis
     */
    public function get_search_term_analysis(int $days = 30): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_questions';
        
        // Get all questions
        $questions = $wpdb->get_col($wpdb->prepare(
            "SELECT question FROM {$table} 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        // Analyze word frequency
        $word_counts = [];
        $stopwords = get_option('cleversay_stopwords', []);
        
        foreach ($questions as $question) {
            $words = preg_split('/\s+/', strtolower($question));
            $words = array_filter($words, function($w) use ($stopwords) {
                return strlen($w) > 2 && !in_array($w, $stopwords) && !is_numeric($w);
            });
            
            foreach ($words as $word) {
                $word = preg_replace('/[^a-z0-9]/', '', $word);
                if (!empty($word)) {
                    $word_counts[$word] = ($word_counts[$word] ?? 0) + 1;
                }
            }
        }
        
        arsort($word_counts);
        
        // Return top 50 words
        return array_slice($word_counts, 0, 50, true);
    }
    
    /**
     * Get entries needing attention
     */
    public function get_entries_needing_attention(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_knowledge';
        
        $attention = [];
        
        // Low-rated entries
        $attention['low_rated'] = $wpdb->get_results(
            "SELECT id, keyword, hits,
                    CASE WHEN (helpful_yes + helpful_no) > 0
                         THEN ROUND(helpful_yes / (helpful_yes + helpful_no) * 100)
                         ELSE NULL END AS rate
             FROM {$table}
             WHERE hits >= 10
               AND (helpful_yes + helpful_no) > 0
               AND ROUND(helpful_yes / (helpful_yes + helpful_no) * 100) < 50
             ORDER BY rate ASC, hits DESC
             LIMIT 10",
            ARRAY_A
        );
        
        // Expiring soon
        $attention['expiring'] = $wpdb->get_results(
            "SELECT id, keyword, expires_at
             FROM {$table}
             WHERE expires_at IS NOT NULL
                AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
             ORDER BY expires_at ASC
             LIMIT 10",
            ARRAY_A
        );
        
        // Never accessed (potential dead content)
        $attention['never_accessed'] = $wpdb->get_results(
            "SELECT id, keyword, created_at
             FROM {$table}
             WHERE hits = 0 
                AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND status = 'active'
             ORDER BY created_at ASC
             LIMIT 10",
            ARRAY_A
        );
        
        // High traffic but low rating
        $attention['high_traffic_low_rating'] = $wpdb->get_results(
            "SELECT id, keyword, hits,
                    CASE WHEN (helpful_yes + helpful_no) > 0
                         THEN ROUND(helpful_yes / (helpful_yes + helpful_no) * 100)
                         ELSE NULL END AS rate
             FROM {$table}
             WHERE hits >= 50
               AND (helpful_yes + helpful_no) > 0
               AND ROUND(helpful_yes / (helpful_yes + helpful_no) * 100) < 70
             ORDER BY hits DESC
             LIMIT 10",
            ARRAY_A
        );
        
        return $attention;
    }
    
    /**
     * Generate executive summary
     */
    public function get_executive_summary(int $days = 30): array {
        $stats = $this->get_dashboard_stats();
        $comparison = $this->get_performance_comparison($days);
        $top_keywords = $this->get_top_keywords(5, $days);
        $unmatched = $this->get_unmatched_questions(5, $days);
        
        return [
            'period' => $days . ' days',
            'total_interactions' => $stats['questions_this_month'],
            'match_rate' => $stats['match_rate'] . '%',
            'match_rate_change' => ($comparison['change']['match_rate'] >= 0 ? '+' : '') . 
                $comparison['change']['match_rate'] . '%',
            'satisfaction_rate' => $stats['helpfulness_rate'] . '%',
            'active_entries' => $stats['active_entries'],
            'pending_inquiries' => $stats['pending_inquiries'],
            'top_topics' => array_column($top_keywords, 'keyword'),
            'top_gaps' => array_column($unmatched, 'question'),
            'recommendations' => $this->generate_recommendations($stats, $unmatched),
        ];
    }
    
    /**
     * Generate automated recommendations
     */
    private function generate_recommendations(array $stats, array $unmatched): array {
        $recommendations = [];
        
        if ($stats['match_rate'] < 70) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => __('Match rate is below 70%. Consider adding more keywords or synonyms.', 'cleversay'),
            ];
        }
        
        if ($stats['pending_inquiries'] > 10) {
            $recommendations[] = [
                'type' => 'action',
                'message' => sprintf(
                    __('You have %d pending inquiries. Review and respond to them.', 'cleversay'),
                    $stats['pending_inquiries']
                ),
            ];
        }
        
        if (count($unmatched) > 0) {
            $recommendations[] = [
                'type' => 'suggestion',
                'message' => sprintf(
                    __('Consider adding content for frequently asked: "%s"', 'cleversay'),
                    $unmatched[0]['question']
                ),
            ];
        }
        
        if ($stats['expiring_soon'] > 0) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => sprintf(
                    __('%d entries are expiring within 7 days. Review and extend if needed.', 'cleversay'),
                    $stats['expiring_soon']
                ),
            ];
        }
        
        if ($stats['helpfulness_rate'] < 80) {
            $recommendations[] = [
                'type' => 'improvement',
                'message' => __('Helpfulness rate could be improved. Review low-rated answers.', 'cleversay'),
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Clear analytics cache
     */
    public function clear_cache(): void {
        wp_cache_delete('dashboard_stats', self::CACHE_GROUP);
        
        // Clear trend caches
        foreach ([7, 14, 30, 90] as $days) {
            wp_cache_delete("questions_trend_{$days}", self::CACHE_GROUP);
            wp_cache_delete("top_keywords_10_{$days}", self::CACHE_GROUP);
            wp_cache_delete("unmatched_20_{$days}", self::CACHE_GROUP);
            wp_cache_delete("geography_{$days}", self::CACHE_GROUP);
            wp_cache_delete("hourly_{$days}", self::CACHE_GROUP);
            wp_cache_delete("weekday_{$days}", self::CACHE_GROUP);
        }
    }
}
