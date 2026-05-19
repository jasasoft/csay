<?php
/**
 * CleverSay Updater
 *
 * Handles staging/production update workflow:
 * - Upload new version zip to staging
 * - Copy staging plugin files to production
 * - Daily automated snapshots (7-day retention)
 * - Manual snapshots
 * - Rollback to any snapshot
 * - Copy KB / AI Sources from a client site into staging
 *
 * @package CleverSay
 * @since   4.2.0
 */

declare(strict_types=1);

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class Updater {

    /** Path to the production plugin folder */
    private string $prod_dir;

    /** Path to the staging plugin folder */
    private string $staging_dir;

    /** Root backup directory */
    private string $backup_root;

    /** Max daily snapshots to keep */
    public const DAILY_KEEP = 7;

    /** Max manual snapshots to keep. v4.42.26+: user has local backups
     *  before each upload, so accumulating unlimited manual snapshots
     *  here just bloats the dashboard list. */
    public const MANUAL_KEEP = 7;

    public function __construct() {
        $upload_dir        = wp_upload_dir();
        $this->prod_dir    = WP_PLUGIN_DIR . '/cleversay';
        $this->staging_dir = WP_PLUGIN_DIR . '/cleversay-staging';
        $this->backup_root = $upload_dir['basedir'] . '/cleversay-backups';
    }

    // ── Version detection ────────────────────────────────────────────────────

    /**
     * Read the Version: header from a plugin's main file.
     */
    public function get_plugin_version(string $plugin_dir): string {
        $main_file = $plugin_dir . '/cleversay.php';
        if (!file_exists($main_file)) {
            return 'unknown';
        }
        $contents = file_get_contents($main_file, false, null, 0, 2048);
        if (preg_match('/^\s*\*\s*Version:\s*(.+)$/mi', $contents, $m)) {
            return trim($m[1]);
        }
        return 'unknown';
    }

    public function get_production_version(): string {
        return $this->get_plugin_version($this->prod_dir);
    }

    public function get_staging_version(): string {
        if (!is_dir($this->staging_dir)) {
            return 'not installed';
        }
        return $this->get_plugin_version($this->staging_dir);
    }

    public function staging_exists(): bool {
        return is_dir($this->staging_dir)
            && file_exists($this->staging_dir . '/cleversay.php');
    }

    // ── Upload new version to staging ────────────────────────────────────────

    /**
     * Upload and extract a plugin zip into the staging directory.
     *
     * @param array $file  $_FILES element
     * @return array{success:bool, message:string}
     */
    public function upload_to_staging(array $file): array {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'message' => 'No file uploaded.'];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            return ['success' => false, 'message' => 'File must be a .zip archive.'];
        }

        // Extract to a temp location first
        $tmp_extract = $this->backup_root . '/tmp-extract-' . time();
        wp_mkdir_p($tmp_extract);

        $zip = new \ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            return ['success' => false, 'message' => 'Could not open zip file.'];
        }
        $zip->extractTo($tmp_extract);
        $zip->close();

        // Expect cleversay/ folder inside the zip
        $extracted_plugin = $tmp_extract . '/cleversay';
        if (!is_dir($extracted_plugin)) {
            $this->rm_rf($tmp_extract);
            return ['success' => false, 'message' => 'Zip must contain a cleversay/ folder at its root.'];
        }

        // Validate it has the main plugin file
        if (!file_exists($extracted_plugin . '/cleversay.php')) {
            $this->rm_rf($tmp_extract);
            return ['success' => false, 'message' => 'cleversay.php not found inside zip.'];
        }

        // Read version from the extracted file
        $new_version = $this->get_plugin_version($extracted_plugin);

        // Replace staging directory
        if (is_dir($this->staging_dir)) {
            $this->rm_rf($this->staging_dir);
        }
        rename($extracted_plugin, $this->staging_dir);
        $this->rm_rf($tmp_extract);

        return [
            'success' => true,
            'message' => "Staging updated to v{$new_version}.",
            'version' => $new_version,
        ];
    }

    // ── Push staging → production ─────────────────────────────────────────────

    /**
     * Copy staging plugin files over production.
     * Creates a manual snapshot of current production before copying.
     *
     * @param bool $snapshot  Whether to snapshot production first
     * @return array{success:bool, message:string}
     */
    public function push_staging_to_production(bool $snapshot = true): array {
        if (!$this->staging_exists()) {
            return ['success' => false, 'message' => 'Staging plugin not found.'];
        }

        $staging_version = $this->get_staging_version();

        // Snapshot production first
        if ($snapshot) {
            $snap = $this->create_snapshot('manual', "before-v{$staging_version}");
            if (!$snap['success']) {
                return ['success' => false, 'message' => 'Snapshot failed: ' . $snap['message']];
            }
        }

        // Copy staging → temp, then replace production
        $tmp = $this->prod_dir . '-tmp-' . time();

        // Recursively copy staging to temp destination
        if (!$this->cp_r($this->staging_dir, $tmp)) {
            return ['success' => false, 'message' => 'Failed to copy staging files.'];
        }

        // Atomically swap: rename production to old, rename temp to production
        $old = $this->prod_dir . '-old-' . time();
        if (!rename($this->prod_dir, $old)) {
            $this->rm_rf($tmp);
            return ['success' => false, 'message' => 'Could not move production directory.'];
        }
        if (!rename($tmp, $this->prod_dir)) {
            // Try to restore
            rename($old, $this->prod_dir);
            return ['success' => false, 'message' => 'Could not move new files into production.'];
        }

        // Remove the old production dir
        $this->rm_rf($old);

        // Flush WordPress plugin cache
        wp_cache_delete('plugins', 'plugins');

        return [
            'success' => true,
            'message' => "Production updated to v{$staging_version}.",
            'version' => $staging_version,
        ];
    }

    // ── Snapshots ─────────────────────────────────────────────────────────────

    /**
     * Create a snapshot of the production plugin files.
     *
     * @param string $type   'manual' or 'daily'
     * @param string $label  Optional label for manual snapshots
     * @return array{success:bool, message:string, file?:string}
     */
    public function create_snapshot(string $type = 'manual', string $label = ''): array {
        $dir = $this->backup_root . '/snapshots/' . $type;
        wp_mkdir_p($dir);

        $version  = $this->get_production_version();
        $date     = date('Y-m-d');
        $time     = date('His');
        $filename = $type === 'daily'
            ? "{$date}.zip"
            : "{$date}-{$time}" . ($label ? "-{$label}" : '') . ".zip";

        $dest = $dir . '/' . $filename;

        // Don't duplicate daily snapshots for the same day
        if ($type === 'daily' && file_exists($dest)) {
            return ['success' => true, 'message' => 'Daily snapshot already exists for today.', 'file' => $dest];
        }

        $zip = new \ZipArchive();
        if ($zip->open($dest, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return ['success' => false, 'message' => "Could not create snapshot file at {$dest}"];
        }

        $this->zip_directory($zip, $this->prod_dir, 'cleversay');
        $zip->close();

        // Enforce retention.
        // - Daily: keep DAILY_KEEP newest
        // - Manual: v4.42.26+, keep MANUAL_KEEP newest. Previously
        //   manual snapshots accumulated indefinitely, which produced
        //   long lists on the network dashboard. Per-user policy:
        //   admins keep their own local backups before each upload,
        //   so server-side retention of the last 7 is plenty.
        if ($type === 'daily') {
            $this->enforce_retention($dir, self::DAILY_KEEP);
        } elseif ($type === 'manual') {
            $this->enforce_retention($dir, self::MANUAL_KEEP);
        }

        return [
            'success' => true,
            'message' => "Snapshot created: {$filename} (v{$version})",
            'file'    => $dest,
        ];
    }

    /**
     * Restore production from a snapshot file.
     *
     * @param string $snapshot_path Full path to the snapshot zip
     * @return array{success:bool, message:string}
     */
    public function restore_snapshot(string $snapshot_path): array {
        // Security: must be inside backup_root
        $real = realpath($snapshot_path);
        $root = realpath($this->backup_root);
        if (!$real || !$root || strpos($real, $root) !== 0) {
            return ['success' => false, 'message' => 'Invalid snapshot path.'];
        }

        if (!file_exists($snapshot_path)) {
            return ['success' => false, 'message' => 'Snapshot file not found.'];
        }

        // Snapshot current state before restoring
        $this->create_snapshot('manual', 'before-restore-' . date('His'));

        $tmp = $this->prod_dir . '-restore-tmp-' . time();
        wp_mkdir_p($tmp);

        $zip = new \ZipArchive();
        if ($zip->open($snapshot_path) !== true) {
            return ['success' => false, 'message' => 'Could not open snapshot zip.'];
        }
        $zip->extractTo($tmp);
        $zip->close();

        $extracted = $tmp . '/cleversay';
        if (!is_dir($extracted)) {
            $this->rm_rf($tmp);
            return ['success' => false, 'message' => 'Snapshot format invalid — no cleversay/ folder.'];
        }

        $old = $this->prod_dir . '-old-' . time();
        rename($this->prod_dir, $old);
        rename($extracted, $this->prod_dir);
        $this->rm_rf($old);
        $this->rm_rf($tmp);

        wp_cache_delete('plugins', 'plugins');
        $restored_version = $this->get_production_version();

        return [
            'success' => true,
            'message' => "Restored to v{$restored_version} from snapshot.",
        ];
    }

    /**
     * Delete a snapshot file.
     */
    public function delete_snapshot(string $snapshot_path): array {
        $real = realpath($snapshot_path);
        $root = realpath($this->backup_root);
        if (!$real || !$root || strpos($real, $root) !== 0) {
            return ['success' => false, 'message' => 'Invalid snapshot path.'];
        }
        if (!file_exists($snapshot_path)) {
            return ['success' => false, 'message' => 'Snapshot file not found.'];
        }
        if (@unlink($snapshot_path)) {
            return ['success' => true, 'message' => 'Snapshot deleted.'];
        }
        return ['success' => false, 'message' => 'Could not delete snapshot file.'];
    }

    /**
     * List snapshots of a given type, newest first.
     *
     * @param string $type 'manual' or 'daily'
     * @return array[]
     */
    public function list_snapshots(string $type = 'daily'): array {
        $dir = $this->backup_root . '/snapshots/' . $type;
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.zip') ?: [];
        rsort($files); // newest first

        return array_map(function(string $path) use ($type): array {
            $name    = basename($path);
            $size    = filesize($path);
            $size_kb = $size ? round($size / 1024) : 0;
            $mtime   = filemtime($path);
            return [
                'path'     => $path,
                'name'     => $name,
                'size_kb'  => $size_kb,
                'modified' => $mtime ? date('Y-m-d H:i', $mtime) : '',
                'type'     => $type,
            ];
        }, $files);
    }

    // ── Copy KB / AI Sources to staging ──────────────────────────────────────

    /**
     * Copy KB entries and/or AI sources from a client site into staging.
     *
     * @param int   $source_blog_id  Blog ID to copy from
     * @param bool  $copy_kb         Copy knowledge base entries
     * @param bool  $copy_sources    Copy AI sources and chunks
     * @param bool  $copy_synonyms   Copy synonyms
     * @param bool  $clear_first     Truncate staging tables before copying
     * @param int   $staging_blog_id Blog ID of the staging subsite
     * @return array{success:bool, message:string, counts:array}
     */
    public function copy_to_staging(
        int  $source_blog_id,
        bool $copy_kb       = true,
        bool $copy_sources  = true,
        bool $copy_synonyms = false,
        bool $clear_first   = true,
        int  $staging_blog_id = 0
    ): array {
        global $wpdb;

        // Auto-detect staging blog ID if not provided
        if (!$staging_blog_id) {
            $staging_blog_id = $this->get_staging_blog_id();
        }
        if (!$staging_blog_id) {
            return ['success' => false, 'message' => 'Could not find staging subsite.', 'counts' => []];
        }
        if ($source_blog_id === $staging_blog_id) {
            return ['success' => false, 'message' => 'Source and staging cannot be the same site.', 'counts' => []];
        }

        $counts = [];

        // Source table prefixes
        $src_prefix  = $wpdb->get_blog_prefix($source_blog_id);
        $dest_prefix = $wpdb->get_blog_prefix($staging_blog_id);

        if ($clear_first) {
            if ($copy_kb) {
                $wpdb->query("TRUNCATE TABLE {$dest_prefix}cleversay_knowledge");
            }
            if ($copy_sources) {
                $wpdb->query("TRUNCATE TABLE {$dest_prefix}cleversay_sources");
                $wpdb->query("TRUNCATE TABLE {$dest_prefix}cleversay_chunks");
            }
            if ($copy_synonyms) {
                $wpdb->query("TRUNCATE TABLE {$dest_prefix}cleversay_synonyms");
            }
        }

        // Copy Knowledge Base
        if ($copy_kb) {
            $rows = $wpdb->get_results(
                "SELECT * FROM {$src_prefix}cleversay_knowledge",
                ARRAY_A
            );
            $count = 0;
            foreach ($rows as $row) {
                unset($row['id']);
                if ($wpdb->insert("{$dest_prefix}cleversay_knowledge", $row)) {
                    $count++;
                }
            }
            $counts['knowledge'] = $count;
        }

        // Copy AI Sources
        if ($copy_sources) {
            $sources = $wpdb->get_results(
                "SELECT * FROM {$src_prefix}cleversay_sources",
                ARRAY_A
            );
            $src_count = 0;
            $id_map    = []; // old_id → new_id for chunks foreign key

            foreach ($sources as $source) {
                $old_id = (int) $source['id'];
                unset($source['id']);
                if ($wpdb->insert("{$dest_prefix}cleversay_sources", $source)) {
                    $id_map[$old_id] = (int) $wpdb->insert_id;
                    $src_count++;
                }
            }
            $counts['sources'] = $src_count;

            // Copy chunks with remapped source_id
            $chunks = $wpdb->get_results(
                "SELECT * FROM {$src_prefix}cleversay_chunks",
                ARRAY_A
            );
            $chunk_count = 0;
            foreach ($chunks as $chunk) {
                $old_src_id = (int) $chunk['source_id'];
                if (!isset($id_map[$old_src_id])) {
                    continue; // orphaned chunk — skip
                }
                unset($chunk['id']);
                $chunk['source_id'] = $id_map[$old_src_id];
                if ($wpdb->insert("{$dest_prefix}cleversay_chunks", $chunk)) {
                    $chunk_count++;
                }
            }
            $counts['chunks'] = $chunk_count;
        }

        // Copy Synonyms
        if ($copy_synonyms) {
            $rows = $wpdb->get_results(
                "SELECT * FROM {$src_prefix}cleversay_synonyms",
                ARRAY_A
            );
            $count = 0;
            foreach ($rows as $row) {
                unset($row['id']);
                if ($wpdb->insert("{$dest_prefix}cleversay_synonyms", $row)) {
                    $count++;
                }
            }
            $counts['synonyms'] = $count;
        }

        $summary = [];
        foreach ($counts as $table => $n) {
            $summary[] = "{$n} {$table}";
        }

        return [
            'success' => true,
            'message' => 'Copied ' . implode(', ', $summary) . ' to staging.',
            'counts'  => $counts,
        ];
    }

    // ── Daily snapshot cron ───────────────────────────────────────────────────

    /**
     * Run daily snapshot — called by WordPress cron.
     */
    public function run_daily_snapshot(): void {
        $this->create_snapshot('daily');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Find the blog ID for staging.jasa-server.com
     */
    private function get_staging_blog_id(): int {
        $sites = get_sites(['number' => 100]);
        foreach ($sites as $site) {
            if (str_starts_with($site->domain, 'staging.')) {
                return (int) $site->blog_id;
            }
        }
        return 0;
    }

    /**
     * Recursively delete a directory.
     */
    private function rm_rf(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /**
     * Recursively copy a directory.
     */
    private function cp_r(string $src, string $dest): bool {
        wp_mkdir_p($dest);
        $dir = opendir($src);
        if (!$dir) {
            return false;
        }
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            $s = $src  . '/' . $file;
            $d = $dest . '/' . $file;
            if (is_dir($s)) {
                if (!$this->cp_r($s, $d)) {
                    closedir($dir);
                    return false;
                }
            } else {
                if (!copy($s, $d)) {
                    closedir($dir);
                    return false;
                }
            }
        }
        closedir($dir);
        return true;
    }

    /**
     * Add a directory recursively to an open ZipArchive.
     */
    private function zip_directory(\ZipArchive $zip, string $dir, string $zip_path): void {
        $zip->addEmptyDir($zip_path);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $local = $zip_path . '/' . $items->getSubPathname();
            if ($item->isDir()) {
                $zip->addEmptyDir($local);
            } else {
                $zip->addFile($item->getPathname(), $local);
            }
        }
    }

    /**
     * Delete oldest snapshots keeping only $keep most recent.
     */
    private function enforce_retention(string $dir, int $keep): void {
        $files = glob($dir . '/*.zip') ?: [];
        sort($files); // oldest first
        $excess = count($files) - $keep;
        for ($i = 0; $i < $excess; $i++) {
            @unlink($files[$i]);
        }
    }

    /**
     * Get disk usage of backup directory in MB.
     */
    public function get_backup_size_mb(): float {
        if (!is_dir($this->backup_root)) {
            return 0.0;
        }
        $size  = 0;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->backup_root, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            if ($item->isFile()) {
                $size += $item->getSize();
            }
        }
        return round($size / 1024 / 1024, 1);
    }
}
