<?php
/**
 * KB Variations data access.
 *
 * Light wrapper around the cleversay_kb_variations table — list/save/delete
 * operations for variations attached to a knowledge entry.
 *
 * @package CleverSay
 * @since 4.31.0
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class KBVariations {

    /**
     * Get all variations for a knowledge entry, in display order.
     *
     * @return array Each row: ['id' => int, 'variation_text' => string, 'sort_order' => int]
     */
    public static function get_for_entry(int $knowledge_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_kb_variations';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, variation_text, sort_order
                 FROM $table
                 WHERE knowledge_id = %d
                 ORDER BY sort_order ASC, id ASC",
                $knowledge_id
            ),
            ARRAY_A
        );
        return $rows ?: [];
    }

    /**
     * Get variations as a flat array of strings.
     *
     * @return string[]
     */
    public static function get_texts_for_entry(int $knowledge_id): array {
        $rows = self::get_for_entry($knowledge_id);
        return array_column($rows, 'variation_text');
    }

    /**
     * Replace all variations for an entry. Atomic-ish: deletes old, inserts new.
     *
     * @param int      $knowledge_id
     * @param string[] $variations  Plain text phrasings, in display order.
     */
    public static function replace_all(int $knowledge_id, array $variations): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_kb_variations';

        $wpdb->delete($table, ['knowledge_id' => $knowledge_id]);

        $variations = array_values(array_filter(array_map('trim', $variations)));
        $sort = 0;
        foreach ($variations as $v) {
            // Cap length to schema (500 chars).
            $v = mb_substr($v, 0, 500);
            if ($v === '') continue;
            $wpdb->insert($table, [
                'knowledge_id'   => $knowledge_id,
                'variation_text' => $v,
                'sort_order'     => $sort++,
            ]);
        }
    }

    /**
     * Delete all variations for an entry. Used when a knowledge row is deleted.
     */
    public static function delete_for_entry(int $knowledge_id): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_kb_variations';
        return (int) $wpdb->delete($table, ['knowledge_id' => $knowledge_id]);
    }

    /**
     * Delete variations for many knowledge entries at once. Used by bulk
     * delete and by the phrase-group save flow (which re-inserts entries
     * with new ids; the old ids' variations would otherwise orphan).
     *
     * @param int[] $knowledge_ids
     * @return int Number of variation rows deleted.
     */
    public static function delete_for_entries(array $knowledge_ids): int {
        if (empty($knowledge_ids)) return 0;
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_kb_variations';

        // Coerce to ints, drop zeros/negatives, dedupe. We're constructing
        // an IN-list manually because $wpdb->prepare doesn't natively
        // expand arrays into placeholder lists; with int-coerced values
        // this is safe.
        $ids = array_unique(array_filter(array_map('intval', $knowledge_ids), fn($i) => $i > 0));
        if (empty($ids)) return 0;
        $id_list = implode(',', $ids);
        return (int) $wpdb->query("DELETE FROM $table WHERE knowledge_id IN ($id_list)");
    }

    /**
     * Does this entry have any variations? Used to decide which editing UI
     * to show — entries with variations get the new variation-based editor;
     * entries without (legacy 480 entries) keep the pattern-based editor.
     */
    public static function has_variations(int $knowledge_id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'cleversay_kb_variations';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE knowledge_id = %d",
            $knowledge_id
        )) > 0;
    }
}
