<?php
/**
 * Plugin Name: Cloud Auto Importer
 * Plugin URI: https://github.com/yourusername/cloud-media-importer
 * Description: Automatically import images from cloud storage to WordPress Media Library
 * Version: 1.0.0
 * Author: Michael Otieno Omondi
 * License: GPL v2 or later
 * Text Domain: cloud-auto-importer
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ============================================
// DEFINE CONSTANTS
// ============================================
define('CAI_VERSION', '1.0.0');
define('CAI_PLUGIN_FILE', __FILE__);
define('CAI_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CAI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CAI_DEBUG', true);

// ============================================
// ENHANCED LOGGING FUNCTION (FIXED)
// ============================================
if (!function_exists('cai_log')) {
    function cai_log($message, $level = 'info') {
        // Log to WordPress debug.log if enabled
        if (defined('WP_DEBUG') && WP_DEBUG === true && CAI_DEBUG) {
            $timestamp = current_time('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] [CAI] [{$level}] " . print_r($message, true) . "\n";
            error_log($log_entry);
        }
        
        // Only log to database if we're in WordPress context and $wpdb is available
        if (function_exists('is_admin') && class_exists('WP_Query')) {
            global $wpdb;
            
            $table_name = $wpdb->prefix . 'cai_import_logs';
            
            // Check if table exists
            $table_exists = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s", 
                $table_name
            )) === $table_name;
            
            if ($table_exists) {
                // Insert log entry
                $log_data = [
                    'log_level'   => sanitize_text_field($level),
                    'message'     => sanitize_text_field(is_string($message) ? $message : print_r($message, true)),
                    'created_at'  => current_time('mysql'),
                    'source'      => 'system'
                ];
                
                return $wpdb->insert($table_name, $log_data);
            }
        }
        
        return false;
    }
}

// ============================================
// ACTIVATION HOOK
// ============================================
register_activation_hook(__FILE__, 'cai_activate');
function cai_activate() {
    global $wpdb;
    
    // Create import logs table
    $table_name = $wpdb->prefix . 'cai_import_logs';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        import_type varchar(50),
        import_id varchar(100),
        status varchar(20),
        total_files int(11),
        processed_files int(11),
        successful_files int(11) DEFAULT 0,
        failed_files int(11) DEFAULT 0,
        skipped_files int(11) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        completed_at datetime,
        user_id bigint(20),
        PRIMARY KEY (id),
        KEY import_id (import_id),
        KEY status (status),
        KEY user_id (user_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Create user usage table for monthly limits
    $usage_table = $wpdb->prefix . 'cai_user_usage';
    $sql_usage = "CREATE TABLE IF NOT EXISTS $usage_table (
        id INT AUTO_INCREMENT,
        user_id BIGINT(20) NOT NULL,
        month_year VARCHAR(7) NOT NULL,
        image_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_month (user_id, month_year),
        KEY user_id (user_id),
        KEY month_year (month_year)
    ) $charset_collate;";
    
    dbDelta($sql_usage);
    
    update_option('cai_plugin_installed', time());
    update_option('cai_db_version', '2.0'); // Increased version
    
    // Log activation
    if (function_exists('cai_log')) {
        cai_log('Plugin activation completed', 'info');
    }
}

// ============================================
// DEACTIVATION HOOK
// ============================================
register_deactivation_hook(__FILE__, function() {
    // Clean up temporary options
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'cai_import_%'");
    
    if (function_exists('cai_log')) {
        cai_log('Plugin deactivated', 'info');
    }
});

// ============================================
// UNINSTALL HOOK
// ============================================
register_uninstall_hook(__FILE__, 'cai_uninstall');
function cai_uninstall() {
    global $wpdb;
    
    // Remove all plugin data
    $tables = [
        $wpdb->prefix . 'cai_import_logs',
        $wpdb->prefix . 'cai_user_usage'
    ];
    
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS $table");
    }
    
    // Remove options
    $options = [
        'cai_plugin_installed',
        'cai_db_version',
        'cai_import_stats',
        'cai_google_access_token',
        'cai_google_refresh_token',
        'cai_google_token_expiry',
        'cai_google_token_received'
    ];
    
    foreach ($options as $option) {
        delete_option($option);
    }
    
    // Clean up import options
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'cai_import_%'");
    
    // Clean up postmeta
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_cai_%'");
}

// ============================================
// SINGLETON PATTERN TO PREVENT DOUBLE LOADING
// ============================================

class CAI_Plugin {
    private static $instance = null;
    
    private function __construct() {
        $this->init();
    }
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function init() {
        // Check if we need to run activation
        if (get_option('cai_db_version') !== '2.0') {
            cai_activate();
        }
        
        // Load required files
        $this->load_dependencies();
        
        // Initialize classes
        $this->init_classes();
        
        // Log successful loading
        if (function_exists('cai_log')) {
            cai_log('Cloud Auto Importer plugin initialized successfully (Singleton)', 'info');
        }
    }
    
    private function load_dependencies() {
        require_once CAI_PLUGIN_PATH . 'includes/class-importer-core.php';
        
        if (is_admin()) {
            require_once CAI_PLUGIN_PATH . 'includes/class-admin.php';
            require_once CAI_PLUGIN_PATH . 'includes/class-google-drive.php';
        }
    }
    
    private function init_classes() {
        // Initialize Importer Core (always needed)
        if (class_exists('CAI_Importer_Core')) {
            new CAI_Importer_Core();
        }
        
        // Initialize admin classes only in admin area
        if (is_admin()) {
            if (class_exists('CAI_Admin')) {
                new CAI_Admin();
            }
            
            if (class_exists('CAI_Google_Drive')) {
                new CAI_Google_Drive();
            }
        }
    }
    
    // Prevent cloning
    private function __clone() {}
    
    // Prevent unserialization
    public function __wakeup() {}
}

// ============================================
// INITIALIZE PLUGIN USING SINGLETON
// ============================================
add_action('plugins_loaded', function() {
    CAI_Plugin::get_instance();
});

// ============================================
// ERROR HANDLING FOR DEBUGGING (OPTIONAL)
// ============================================
if (CAI_DEBUG) {
    add_action('admin_notices', function() {
        global $wpdb;
        
        // Check database tables
        $tables = [
            $wpdb->prefix . 'cai_import_logs' => false,
            $wpdb->prefix . 'cai_user_usage' => false
        ];
        
        foreach ($tables as $table_name => &$exists) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s", 
                $table_name
            )) === $table_name;
        }
        
        // Show debug info
        if (current_user_can('manage_options')) {
            echo '<div class="notice notice-info">';
            echo '<p><strong>Cloud Auto Importer Debug Info:</strong></p>';
            echo '<ul>';
            foreach ($tables as $table_name => $exists) {
                echo '<li>' . $table_name . ': ' . ($exists ? '✅ Exists' : '❌ Missing') . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }
    });
}
?>
