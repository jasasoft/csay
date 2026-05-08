<?php
/**
 * CleverSay Logger
 * 
 * Custom logging system with admin viewer
 * 
 * @package CleverSay
 * @since 2.0.3
 */

namespace CleverSay;

if (!defined('ABSPATH')) {
    exit;
}

class Logger {
    
    private static ?Logger $instance = null;
    private string $log_file;
    private string $log_dir;
    private bool $enabled = true;
    private bool $initialized = false;
    
    /**
     * Get singleton instance
     */
    public static function instance(): Logger {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Stored as 1/0 int rather than true/false bool to dodge a
        // WordPress quirk: when an option doesn't yet exist,
        // get_option() returns false as the not-found sentinel, and
        // update_option('foo', false) sees `false === false` and
        // returns early without saving. So the first-ever Disable
        // click would never persist. With 1/0 storage, 0 !== false,
        // and the save proceeds correctly. Default is 1 (logging on)
        // for fresh installs.
        $this->enabled = (bool) get_option('cleversay_debug_logging', 1);
        $this->setup_log_file();
    }
    
    /**
     * Setup log file and directory
     */
    private function setup_log_file(): void {
        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/cleversay-logs';
        $this->log_file = $this->log_dir . '/cleversay-debug.log';
        
        // Create log directory if it doesn't exist
        if (!file_exists($this->log_dir)) {
            $created = wp_mkdir_p($this->log_dir);
            if ($created) {
                // Add .htaccess to protect logs
                @file_put_contents($this->log_dir . '/.htaccess', 'deny from all');
                // Add index.php for extra protection
                @file_put_contents($this->log_dir . '/index.php', '<?php // Silence is golden');
            }
        }
        
        // Create log file if it doesn't exist
        if (!file_exists($this->log_file)) {
            @file_put_contents($this->log_file, "[" . date('Y-m-d H:i:s') . "] [INFO] CleverSay Logger initialized\n");
        }
        
        // Check if file is writable
        if (!is_writable($this->log_file) && file_exists($this->log_file)) {
            @chmod($this->log_file, 0664);
        }
        
        $this->initialized = true;
    }
    
    /**
     * Log a message
     */
    public function log(string $message, string $level = 'INFO', array $context = []): void {
        if (!$this->enabled) {
            return;
        }
        
        if (!$this->initialized) {
            $this->setup_log_file();
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $formatted = "[{$timestamp}] [{$level}] {$message}";
        
        if (!empty($context)) {
            $formatted .= " | " . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        
        $formatted .= "\n";
        
        // Try to write to log file
        $result = @file_put_contents($this->log_file, $formatted, FILE_APPEND | LOCK_EX);
        
        // If writing failed, try to recreate the file
        if ($result === false) {
            $this->setup_log_file();
            @file_put_contents($this->log_file, $formatted, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Log info level
     */
    public function info(string $message, array $context = []): void {
        $this->log($message, 'INFO', $context);
    }
    
    /**
     * Log debug level
     */
    public function debug(string $message, array $context = []): void {
        $this->log($message, 'DEBUG', $context);
    }
    
    /**
     * Log error level
     */
    public function error(string $message, array $context = []): void {
        $this->log($message, 'ERROR', $context);
    }
    
    /**
     * Log warning level
     */
    public function warning(string $message, array $context = []): void {
        $this->log($message, 'WARNING', $context);
    }
    
    /**
     * Get log contents
     */
    public function get_logs(int $lines = 200): string {
        if (!file_exists($this->log_file)) {
            return "No log file found at: {$this->log_file}\nLog directory: {$this->log_dir}\nDirectory exists: " . (file_exists($this->log_dir) ? 'yes' : 'no');
        }
        
        $content = @file_get_contents($this->log_file);
        
        if ($content === false) {
            return "Unable to read log file. Check file permissions.";
        }
        
        if (empty($content)) {
            return "Log file is empty. Perform a search to generate log entries.";
        }
        
        // Get last N lines
        $all_lines = explode("\n", $content);
        $last_lines = array_slice($all_lines, -$lines);
        
        return implode("\n", $last_lines);
    }
    
    /**
     * Clear logs
     */
    public function clear_logs(): bool {
        if (file_exists($this->log_file)) {
            $result = @file_put_contents($this->log_file, "[" . date('Y-m-d H:i:s') . "] [INFO] Logs cleared\n");
            return $result !== false;
        }
        return true;
    }
    
    /**
     * Get log file path
     */
    public function get_log_path(): string {
        return $this->log_file;
    }
    
    /**
     * Get log file size
     */
    public function get_log_size(): string {
        if (!file_exists($this->log_file)) {
            return '0 KB';
        }
        
        $size = @filesize($this->log_file);
        
        if ($size === false) {
            return 'Unknown';
        }
        
        if ($size < 1024) {
            return $size . ' bytes';
        } elseif ($size < 1048576) {
            return round($size / 1024, 2) . ' KB';
        } else {
            return round($size / 1048576, 2) . ' MB';
        }
    }
    
    /**
     * Enable/disable logging
     */
    public function set_enabled(bool $enabled): void {
        $this->enabled = $enabled;
        // Store as 1/0 int — see constructor for why bool false here
        // would silently fail to persist on first toggle.
        update_option('cleversay_debug_logging', $enabled ? 1 : 0);

        if ($enabled) {
            $this->log('Logging enabled');
        }
    }
    
    /**
     * Check if logging is enabled
     */
    public function is_enabled(): bool {
        return $this->enabled;
    }
    
    /**
     * Get diagnostic info
     */
    public function get_diagnostics(): array {
        return [
            'log_file' => $this->log_file,
            'log_dir' => $this->log_dir,
            'dir_exists' => file_exists($this->log_dir),
            'dir_writable' => is_writable($this->log_dir),
            'file_exists' => file_exists($this->log_file),
            'file_writable' => is_writable($this->log_file),
            'enabled' => $this->enabled,
        ];
    }
}

/**
 * Helper function to get logger instance
 */
function cleversay_log(): Logger {
    return Logger::instance();
}
