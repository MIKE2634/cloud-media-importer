<?php
/**
 * Cloud Auto Importer - Admin Class (Simplified)
 * Handles all admin functionality with simplified monthly usage tracking
 */

if (!defined('ABSPATH')) {
    exit;
}

class CAI_Admin {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_post_cai_disconnect_google', [$this, 'handle_disconnect_google']);
        add_action('admin_notices', [$this, 'display_admin_notices']);
        
        // AJAX handlers
        add_action('wp_ajax_cai_clear_logs', [$this, 'ajax_clear_logs']);
        add_action('wp_ajax_cai_get_import_stats', [$this, 'ajax_get_import_stats']);
        add_action('wp_ajax_cai_check_usage', [$this, 'ajax_check_usage']);
        add_action('wp_ajax_cai_get_usage_stats', [$this, 'ajax_get_usage_stats']);
    }
    
    /**
     * Get simplified monthly usage for a user
     */
    public function get_current_month_usage($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        $month_year = date('Y-m');
        $option_name = "cai_user_usage_{$user_id}_{$month_year}";
        
        return (int) get_option($option_name, 0);
    }
    
    /**
     * Get simplified stats - only essential metrics
     */
    public function get_enhanced_stats($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        $month_year = date('Y-m');
        $option_name = "cai_user_usage_{$user_id}_{$month_year}";
        $current_usage = (int) get_option($option_name, 0);
        
        // Simple stats - only what matters
        $stats = [
            'monthly_used' => $current_usage,
            'monthly_limit' => 25, // Free plan limit
            'remaining_images' => max(0, 25 - $current_usage),
            'usage_percent' => min(100, ($current_usage / 25) * 100),
            'total_imports' => 0,
            'total_images' => 0,
            'last_import' => 'Never'
        ];
        
        // Get total completed imports
        global $wpdb;
        $table_name = $wpdb->prefix . 'cai_import_logs';
        
        $total_imports = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name 
             WHERE status = 'completed' 
             AND user_id = %d",
            $user_id
        ));
        
        if ($total_imports) {
            $stats['total_imports'] = $total_imports;
        }
        
        // Get total images imported (all time)
        $total_images = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(successful_files) FROM $table_name 
             WHERE user_id = %d",
            $user_id
        ));
        
        if ($total_images) {
            $stats['total_images'] = $total_images;
        }
        
        // Get last import time
        $last_import = $wpdb->get_var($wpdb->prepare(
            "SELECT created_at FROM $table_name 
             WHERE status = 'completed'
             AND user_id = %d
             ORDER BY created_at DESC LIMIT 1",
            $user_id
        ));
        
        if ($last_import) {
            $stats['last_import'] = human_time_diff(strtotime($last_import), current_time('timestamp')) . ' ago';
        }
        
        return $stats;
    }
    
    /**
     * Render simplified quick stats bar with monthly usage focus
     */
    public function render_quick_stats_bar() {
        $stats = $this->get_enhanced_stats();
        $upgrade_url = admin_url('admin.php?page=cloud-auto-importer-upgrade');
        ?>
        <div class="cai-quick-stats-bar">
            <div class="cai-stats-grid">
                <!-- Monthly Usage - MAIN STAT -->
                <div class="cai-stat-card monthly-usage">
                    <h4>📅 Monthly Usage</h4>
                    <div class="cai-stat-value">
                        <?php echo esc_html($stats['monthly_used']); ?>/<?php echo esc_html($stats['monthly_limit']); ?>
                    </div>
                    <div class="cai-progress-container">
                        <div class="cai-progress-bar">
                            <div class="cai-progress-fill" style="width: <?php echo esc_attr($stats['usage_percent']); ?>%"></div>
                        </div>
                        <div class="cai-progress-text">
                            <span><?php echo esc_html($stats['remaining_images']); ?> images remaining</span>
                            <?php if ($stats['usage_percent'] >= 80): ?>
                                <span class="cai-usage-warning">⚠️ Almost full</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Total Images Imported -->
                <div class="cai-stat-card" data-stat="total-images">
                    <h4>📊 Total Images</h4>
                    <div class="cai-stat-value"><?php echo esc_html($stats['total_images']); ?></div>
                    <div class="cai-stat-label">All-time imports</div>
                </div>
                
                <!-- Total Imports -->
                <div class="cai-stat-card" data-stat="total-imports">
                    <h4>🔄 Total Imports</h4>
                    <div class="cai-stat-value"><?php echo esc_html($stats['total_imports']); ?></div>
                    <div class="cai-stat-label">Completed jobs</div>
                </div>
                
                <!-- Last Import -->
                <div class="cai-stat-card">
                    <h4>⏰ Last Import</h4>
                    <div class="cai-stat-value"><?php echo esc_html($stats['last_import']); ?></div>
                    <div class="cai-stat-label">Most recent</div>
                </div>
                
                <!-- Upgrade Card - Simple -->
                <div class="cai-stat-card upgrade-card">
                    <h4>🚀 Need More?</h4>
                    <p>Upgrade for 500+ images/month</p>
                    <a href="<?php echo esc_url($upgrade_url); ?>" class="button button-primary">
                        View Plans
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Check if user has basic filenames (no AI alt text)
     */
    private function check_if_has_basic_filenames($user_id) {
        global $wpdb;
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_author = %d
             AND p.post_type = 'attachment'
             AND pm.meta_key = '_wp_attachment_image_alt'
             AND pm.meta_value REGEXP '^[a-zA-Z0-9_-]+\.[a-zA-Z0-9]+$'",
            $user_id
        ));
        
        return $count > 0;
    }
    
    /**
     * Add admin menu pages
     */
    public function add_menu() {
        add_menu_page(
            'Cloud Auto Importer',
            'Cloud Importer',
            'manage_options',
            'cloud-auto-importer',
            [$this, 'render_main_page'],
            'dashicons-cloud-upload',
            30
        );
        
        // Add settings submenu
        add_submenu_page(
            'cloud-auto-importer',
            'Settings',
            'Settings',
            'manage_options',
            'cloud-auto-importer-settings',
            [$this, 'render_settings_page']
        );
        
        // Add import logs submenu
        add_submenu_page(
            'cloud-auto-importer',
            'Import Logs',
            'Import Logs',
            'manage_options',
            'cloud-auto-importer-logs',
            [$this, 'render_logs_page']
        );
        
        // Add upgrade page (hidden from menu)
        add_submenu_page(
            null,
            'Upgrade',
            'Upgrade',
            'manage_options',
            'cloud-auto-importer-upgrade',
            [$this, 'render_upgrade_page']
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'cloud-auto-importer') === false) {
            return;
        }
        
        // Enqueue jQuery UI for slider
        wp_enqueue_script('jquery-ui-slider');
        wp_enqueue_style('jquery-ui-slider', includes_url('css/jquery-ui.min.css'));
        
        // Enqueue CSS
        wp_enqueue_style(
            'cai-admin-style',
            CAI_PLUGIN_URL . 'assets/css/admin.css',
            [],
            CAI_VERSION
        );
        
        // Enqueue JavaScript
        wp_enqueue_script(
            'cai-admin-script',
            CAI_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'jquery-ui-slider'],
            CAI_VERSION,
            true
        );
        
        // Get simplified stats
        $stats = $this->get_enhanced_stats();
        
        // Localize script for AJAX
        wp_localize_script('cai-admin-script', 'cai_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cai_ajax_nonce'),
            'logs_url' => admin_url('admin.php?page=cloud-auto-importer-logs'),
            'upgrade_url' => admin_url('admin.php?page=cloud-auto-importer-upgrade'),
            'current_usage' => $stats['monthly_used'],
            'usage_limit' => $stats['monthly_limit'],
            'usage_percent' => $stats['usage_percent'],
            'i18n' => [
                'processing' => __('Processing...', 'cloud-auto-importer'),
                'import_complete' => __('Import complete!', 'cloud-auto-importer'),
                'error_occurred' => __('An error occurred', 'cloud-auto-importer'),
                'starting_import' => __('Starting import...', 'cloud-auto-importer'),
                'import_started' => __('Import started! Processing files...', 'cloud-auto-importer'),
                'limit_reached' => __('Monthly limit reached. Upgrade to continue.', 'cloud-auto-importer'),
                'limit_warning' => __('Monthly limit almost reached. Only %s images left.', 'cloud-auto-importer'),
            ]
        ]);
    }
    
    /**
     * Render main admin page
     */
    public function render_main_page() {
        // Include Google Drive class
        require_once CAI_PLUGIN_PATH . 'includes/class-google-drive.php';
        $google_drive = new CAI_Google_Drive();
        
        // Check if connected
        $is_connected = $google_drive->is_connected();
        $auth_url = $google_drive->get_auth_url();
        
        // Get simplified stats
        $stats = $this->get_enhanced_stats();
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-cloud-upload"></span> Cloud Auto Importer</h1>
            
            <!-- Display Simplified Quick Stats Bar -->
            <?php $this->render_quick_stats_bar(); ?>
            
            <!-- Progress Bar Container (Hidden by default) -->
            <div id="cai-progress-container" class="cai-progress-container" style="display: none;">
                <div class="cai-progress-header">
                    <h3><span class="dashicons dashicons-update"></span> Import Progress</h3>
                    <div class="cai-import-id" id="cai-import-id-display"></div>
                </div>
                
                <div class="cai-progress-bar">
                    <div class="cai-progress-fill" style="width: 0%;"></div>
                </div>
                <div class="cai-progress-text">
                    <span id="cai-progress-percentage">0%</span>
                    <span id="cai-progress-details">Initializing...</span>
                </div>
                <div class="cai-progress-stats">
                    <span id="cai-processed-files">0</span> of 
                    <span id="cai-total-files">0</span> files processed
                </div>
                
                <!-- Live Stats Grid -->
                <div class="cai-stats-grid" id="cai-live-stats">
                    <div class="cai-stat-card success">
                        <div class="cai-stat-number" id="cai-successful-count">0</div>
                        <div class="cai-stat-label">Successful</div>
                    </div>
                    <div class="cai-stat-card warning">
                        <div class="cai-stat-number" id="cai-failed-count">0</div>
                        <div class="cai-stat-label">Failed</div>
                    </div>
                    <div class="cai-stat-card info">
                        <div class="cai-stat-number" id="cai-skipped-count">0</div>
                        <div class="cai-stat-label">Skipped (Duplicates)</div>
                    </div>
                </div>
                
                <div class="cai-progress-actions">
                    <button id="cai-pause-import" class="button button-secondary" style="display: none;">
                        <span class="dashicons dashicons-controls-pause"></span> Pause
                    </button>
                    <button id="cai-resume-import" class="button button-secondary" style="display: none;">
                        <span class="dashicons dashicons-controls-play"></span> Resume
                    </button>
                    <button id="cai-cancel-import" class="button button-secondary">
                        <span class="dashicons dashicons-no"></span> Cancel
                    </button>
                    <button id="cai-view-results" class="button button-primary" style="display: none;">
                        <span class="dashicons dashicons-visibility"></span> View Results
                    </button>
                </div>
            </div>
            
            <div class="cai-dashboard">
                <!-- Status Card -->
                <div class="cai-card">
                    <h2><span class="dashicons dashicons-admin-links"></span> Cloud Connection Status</h2>
                    
                    <div class="cai-status <?php echo $is_connected ? 'connected' : 'disconnected'; ?>">
                        <span class="dashicons dashicons-<?php echo $is_connected ? 'yes-alt' : 'no'; ?>"></span>
                        <?php
                        if ($is_connected) {
                            _e('Google Drive: Connected', 'cloud-auto-importer');
                        } else {
                            _e('Google Drive: Not Connected', 'cloud-auto-importer');
                        }
                        ?>
                    </div>
                    
                    <div class="cai-connection-actions">
                        <?php if (!$is_connected): ?>
                            <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary">
                                <span class="dashicons dashicons-plus"></span>
                                <?php _e('Connect Google Drive', 'cloud-auto-importer'); ?>
                            </a>
                        <?php else: ?>
                            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                <input type="hidden" name="action" value="cai_disconnect_google">
                                <?php wp_nonce_field('cai_disconnect_nonce', 'cai_disconnect_nonce'); ?>
                                <button type="submit" class="button button-secondary" onclick="return confirm('Are you sure you want to disconnect?');">
                                    <span class="dashicons dashicons-no"></span>
                                    <?php _e('Disconnect Google Drive', 'cloud-auto-importer'); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Import Card -->
                <div class="cai-card">
                    <h2><span class="dashicons dashicons-upload"></span> Import Images</h2>
                    
                    <?php if (!$is_connected): ?>
                        <div class="notice notice-warning">
                            <p><?php _e('Please connect Google Drive first to import images.', 'cloud-auto-importer'); ?></p>
                        </div>
                    <?php else: ?>
                        <form id="cai-import-form" method="post">
                            <?php wp_nonce_field('cai_start_import', 'cai_import_nonce'); ?>
                            <input type="hidden" name="action" value="cai_start_import">
                            <input type="hidden" id="cai-current-import-id" name="current_import_id" value="">
                            
                            <!-- Usage Limit Warning -->
                            <?php if ($stats['monthly_used'] >= 25): ?>
                                <div id="cai-usage-warning" class="notice notice-warning">
                                    <p>
                                        <span class="dashicons dashicons-warning"></span>
                                        <strong>Monthly limit reached:</strong> You've used <?php echo $stats['monthly_used']; ?>/25 images this month.
                                        <a href="<?php echo admin_url('admin.php?page=cloud-auto-importer-upgrade'); ?>">Upgrade to continue</a>
                                    </p>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Import Form - Only show if limit not reached -->
                            <?php if ($stats['monthly_used'] < 25): ?>
                                <table class="form-table">
                                    <!-- Google Drive Folder URL -->
                                    <tr>
                                        <td colspan="2">
                                            <label for="cloud_folder_url" class="cai-tooltip" data-tip="Paste the shareable link from Google Drive">
                                                <strong><?php _e('Google Drive Folder URL', 'cloud-auto-importer'); ?></strong>
                                            </label>
                                            <br><br>
                                        
                                            <input type="url" 
                                                id="cloud_folder_url" 
                                                name="cloud_folder_url" 
                                                class="regular-text"
                                                placeholder="https://drive.google.com/drive/folders/..."
                                                required>
                                            <p class="description">
                                                <span class="dashicons dashicons-info"></span>
                                                <?php _e('Right-click folder → Get link → Copy link', 'cloud-auto-importer'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                    
                                    <!-- Skip Duplicates -->
                                    <tr>
                                        <td colspan="2">
                                            <label for="cai_skip_duplicates" class="cai-tooltip" data-tip="Detects duplicates using file hash and filename">
                                                <strong><?php _e('Skip Duplicates', 'cloud-auto-importer'); ?></strong>
                                            </label>
                                            <br><br>
                                        
                                            <label>
                                                <input type="checkbox" 
                                                    id="cai_skip_duplicates" 
                                                    name="skip_duplicates" 
                                                    value="1" 
                                                    checked>
                                                <?php _e('Skip files already in Media Library', 'cloud-auto-importer'); ?>
                                            </label>
                                            <p class="description">
                                                <span class="dashicons dashicons-filter"></span>
                                                <?php _e('Uses MD5 hash comparison for accurate detection', 'cloud-auto-importer'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                    
                                    <!-- Image Compression -->
                                    <tr>
                                        <td colspan="2">
                                            <label for="cai_compress_images" class="cai-tooltip" data-tip="Compress JPEG and PNG images to reduce file size">
                                               <strong> <?php _e('Image Compression', 'cloud-auto-importer'); ?></strong>
                                            </label>
                                            <br><br>
                                        
                                            <label>
                                                <input type="checkbox" 
                                                    id="cai_compress_images" 
                                                    name="compress_images" 
                                                    value="1" 
                                                    checked>
                                                <?php _e('Compress images', 'cloud-auto-importer'); ?>
                                            </label>
                                            <div id="cai-compression-options" style="margin-top: 15px; display: none;">
                                                <label for="cai_compression_quality">
                                                    <?php _e('Compression Quality:', 'cloud-auto-importer'); ?>
                                                    <span id="cai-quality-value">80%</span>
                                                </label>
                                                <div id="cai-quality-slider" style="width: 300px; margin: 10px 0;"></div>
                                                <input type="hidden" id="cai_compression_quality" name="compression_quality" value="80">
                                                <p class="description">
                                                    <span class="dashicons dashicons-image-rotate"></span>
                                                    <?php _e('Higher = better quality, larger files. Lower = smaller files, lower quality.', 'cloud-auto-importer'); ?>
                                                </p>
                                            </div>
                                        </td>
                                    </tr>
                                    
                                    <!-- Generate Alt Text -->
                                    <tr>
                                        <td colspan="2">
                                            <label for="cai_generate_alt_text" class="cai-tooltip" data-tip="Auto-generates alt text from filenames (cat-sleeping.jpg → Cat Sleeping)">
                                                <strong><?php _e('Generate Alt Text', 'cloud-auto-importer'); ?></strong>
                                            </label>
                                        
                                            <label>
                                                <input type="checkbox" 
                                                    id="cai_generate_alt_text" 
                                                    name="generate_alt_text" 
                                                    value="1" 
                                                    checked>
                                                <?php _e('Generate alt text from filenames', 'cloud-auto-importer'); ?>
                                            </label>
                                            <p class="description">
                                                <span class="dashicons dashicons-editor-textcolor"></span>
                                                <?php _e('Improves SEO and accessibility. Example: "my-cat-sleeping.jpg" → "My Cat Sleeping"', 'cloud-auto-importer'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                    
                                    <!-- Batch Size -->
                                    <tr>
                                        <td colspan="2">
                                            <label for="cai_batch_size" class="cai-tooltip" data-tip="Number of files processed in each batch">
                                                <strong><?php _e('Batch Size', 'cloud-auto-importer'); ?></strong>
                                            </label>
                                        
                                            <select id="cai_batch_size" name="batch_size">
                                                <option value="10">10 files per batch</option>
                                                <option value="25" selected>25 files per batch</option>
                                                <option value="50">50 files per batch</option>
                                                <option value="100">100 files per batch</option>
                                            </select>
                                            <p class="description">
                                                <span class="dashicons dashicons-backup"></span>
                                                <?php _e('Smaller batches = more reliable, larger batches = faster', 'cloud-auto-importer'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                                
                                <p class="submit">
                                    <button type="submit" class="button button-primary button-large" id="cai-start-import-btn">
                                        <span class="dashicons dashicons-cloud-upload"></span>
                                        <?php _e('Start Import', 'cloud-auto-importer'); ?>
                                    </button>
                                    <span class="spinner" id="cai-import-spinner" style="float: none; display: none;"></span>
                                </p>
                            <?php else: ?>
                                <!-- Show Upgrade Prompt when limit reached -->
                                <div class="cai-upgrade-prompt">
                                    <p>
                                        <span class="dashicons dashicons-lock"></span>
                                        <strong>Upgrade required:</strong> You've reached your monthly limit of 25 images.
                                        <a href="<?php echo admin_url('admin.php?page=cloud-auto-importer-upgrade'); ?>" class="button button-primary">
                                            <span class="dashicons dashicons-star-filled"></span> Upgrade Now
                                        </a>
                                    </p>
                                    <p class="description">Upgrade to Basic plan for 500 images/month and AI-generated alt text.</p>
                                </div>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
                
                <!-- Quick Guide Card -->
                <div class="cai-card">
                    <h2><span class="dashicons dashicons-book-alt"></span> Quick Guide</h2>
                    
                    <div class="cai-steps">
                        <div class="cai-step">
                            <div class="cai-step-number">1</div>
                            <div class="cai-step-content">
                                <h3>Connect Google Drive</h3>
                                <p>Click "Connect Google Drive" and authorize with your Google account.</p>
                            </div>
                        </div>
                        <div class="cai-step">
                            <div class="cai-step-number">2</div>
                            <div class="cai-step-content">
                                <h3>Get Folder Link</h3>
                                <p>Right-click a Google Drive folder → "Get shareable link" → Copy.</p>
                            </div>
                        </div>
                        <div class="cai-step">
                            <div class="cai-step-number">3</div>
                            <div class="cai-step-content">
                                <h3>Configure & Import</h3>
                                <p>Choose settings and click "Start Import". Watch the progress.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="cai-feature-list">
                        <h3>Features Included:</h3>
                        <ul>
                            <li><span class="dashicons dashicons-yes"></span> Smart duplicate detection</li>
                            <li><span class="dashicons dashicons-yes"></span> Automatic alt text generation</li>
                            <li><span class="dashicons dashicons-yes"></span> Image compression</li>
                            <li><span class="dashicons dashicons-yes"></span> Monthly usage tracking</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Hidden Scripts for UI -->
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Initialize quality slider
                $('#cai-quality-slider').slider({
                    range: "min",
                    value: 80,
                    min: 50,
                    max: 95,
                    slide: function(event, ui) {
                        $('#cai-quality-value').text(ui.value + '%');
                        $('#cai_compression_quality').val(ui.value);
                    }
                });
                
                // Toggle compression options
                $('#cai_compress_images').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('#cai-compression-options').slideDown(200);
                    } else {
                        $('#cai-compression-options').slideUp(200);
                    }
                }).trigger('change');
                
                // Tooltip hover effect
                $('.cai-tooltip').hover(function() {
                    var tip = $(this).data('tip');
                    if (tip) {
                        $(this).append('<span class="cai-tooltip-text">' + tip + '</span>');
                    }
                }, function() {
                    $(this).find('.cai-tooltip-text').remove();
                });
            });
            </script>
        </div>
        <?php
    }
    
    /**
     * Render upgrade page (simplified)
     */
    public function render_upgrade_page() {
        $stats = $this->get_enhanced_stats();
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-star-filled"></span> Upgrade Cloud Auto Importer</h1>
            
            <div class="cai-upgrade-container">
                <!-- Current Plan -->
                <div class="cai-card cai-current-plan">
                    <h2><span class="dashicons dashicons-admin-users"></span> Your Current Plan</h2>
                    <div class="cai-plan-badge free">Free Plan</div>
                    <div class="cai-plan-features">
                        <ul>
                            <li><span class="dashicons dashicons-yes"></span> Google Drive integration</li>
                            <li><span class="dashicons dashicons-yes"></span> 25 images/month</li>
                            <li><span class="dashicons dashicons-yes"></span> Basic image compression</li>
                            <li><span class="dashicons dashicons-yes"></span> Filename-based alt text</li>
                            <li><span class="dashicons dashicons-yes"></span> Duplicate detection</li>
                        </ul>
                    </div>
                    <div class="cai-usage-summary">
                        <p><strong>Current Monthly Usage:</strong> <?php echo $stats['monthly_used']; ?>/25 images</p>
                        <div class="cai-usage-progress">
                            <div class="cai-usage-fill" style="width: <?php echo $stats['usage_percent']; ?>%;"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Upgrade Options -->
                <div class="cai-upgrade-grid">
                    <!-- Basic Plan -->
                    <div class="cai-plan-card basic">
                        <div class="cai-plan-header">
                            <h3>Basic Plan</h3>
                            <div class="cai-plan-price">
                                <span class="cai-price">$4</span>
                                <span class="cai-period">/month</span>
                            </div>
                            <p class="cai-plan-save">Save 20% with annual billing</p>
                        </div>
                        <div class="cai-plan-features">
                            <ul>
                                <li><span class="dashicons dashicons-yes"></span> <strong>500 images/month</strong></li>
                                <li><span class="dashicons dashicons-yes"></span> Hugging Face AI alt text</li>
                                <li><span class="dashicons dashicons-yes"></span> Improved compression (75%)</li>
                                <li><span class="dashicons dashicons-yes"></span> 2-3 Google Drive folders</li>
                                <li><span class="dashicons dashicons-yes"></span> Better duplicate detection</li>
                            </ul>
                        </div>
                        <div class="cai-plan-action">
                            <button class="button button-primary cai-upgrade-btn" data-plan="basic">
                                Upgrade to Basic
                            </button>
                        </div>
                    </div>
                    
                    <!-- Pro Plan -->
                    <div class="cai-plan-card pro">
                        <div class="cai-plan-badge popular">MOST POPULAR</div>
                        <div class="cai-plan-header">
                            <h3>Pro Plan</h3>
                            <div class="cai-plan-price">
                                <span class="cai-price">$10</span>
                                <span class="cai-period">/month</span>
                            </div>
                            <p class="cai-plan-save">Save 25% with annual billing</p>
                        </div>
                        <div class="cai-plan-features">
                            <ul>
                                <li><span class="dashicons dashicons-yes"></span> <strong>2,000-5,000 images/month</strong></li>
                                <li><span class="dashicons dashicons-yes"></span> Gemini AI alt text (premium)</li>
                                <li><span class="dashicons dashicons-yes"></span> Advanced compression (90-100%)</li>
                                <li><span class="dashicons dashicons-yes"></span> Multiple cloud sources</li>
                                <li><span class="dashicons dashicons-yes"></span> Unlimited folders</li>
                            </ul>
                        </div>
                        <div class="cai-plan-action">
                            <button class="button button-primary cai-upgrade-btn" data-plan="pro">
                                Upgrade to Pro
                            </button>
                        </div>
                    </div>
                    
                    <!-- Lifetime Plan -->
                    <div class="cai-plan-card lifetime">
                        <div class="cai-plan-header">
                            <h3>Lifetime Plan</h3>
                            <div class="cai-plan-price">
                                <span class="cai-price">$199</span>
                                <span class="cai-period">one-time</span>
                            </div>
                            <p class="cai-plan-save">Limited time offer</p>
                        </div>
                        <div class="cai-plan-features">
                            <ul>
                                <li><span class="dashicons dashicons-yes"></span> <strong>Unlimited images</strong></li>
                                <li><span class="dashicons dashicons-yes"></span> All cloud sources</li>
                                <li><span class="dashicons dashicons-yes"></span> Gemini AI alt text</li>
                                <li><span class="dashicons dashicons-yes"></span> Advanced compression</li>
                                <li><span class="dashicons dashicons-yes"></span> Lifetime updates</li>
                            </ul>
                        </div>
                        <div class="cai-plan-action">
                            <button class="button button-primary cai-upgrade-btn" data-plan="lifetime">
                                Get Lifetime
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Back to Dashboard -->
                <div class="cai-upgrade-footer">
                    <a href="<?php echo admin_url('admin.php?page=cloud-auto-importer'); ?>" class="button">
                        <span class="dashicons dashicons-arrow-left-alt"></span> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        require_once CAI_PLUGIN_PATH . 'includes/class-google-drive.php';
        $google_drive = new CAI_Google_Drive();
        $status = $google_drive->get_connection_status();
        
        // Get system info
        $system_info = $this->get_system_info();
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-admin-settings"></span> Plugin Settings</h1>
            
            <div class="cai-settings-container">
                <!-- Configuration Card -->
                <div class="cai-card">
                    <h2><span class="dashicons dashicons-admin-plugins"></span> Plugin Status</h2>
                    
                    <div class="notice notice-success">
                        <p><strong>✅ Plugin is fully configured and ready!</strong></p>
                        <p>The plugin automatically connects to Google Drive using built-in credentials.</p>
                    </div>
                </div>
                
                <!-- Connection Status Card -->
                <div class="cai-card">
                    <h2><span class="dashicons dashicons-admin-users"></span> Connection Status</h2>
                    
                    <table class="widefat striped">
                        <tbody>
                            <tr>
                                <th>Google Drive Status</th>
                                <td>
                                    <?php if ($status['connected']): ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> Connected
                                    <?php else: ?>
                                        <span class="dashicons dashicons-no-alt" style="color: #dc3232;"></span> Not Connected
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Account Email</th>
                                <td><?php echo esc_html($status['user_email'] ?: 'Not connected'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render logs page (simplified)
     */
    public function render_logs_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cai_import_logs';
        
        // Get logs
        $logs = $wpdb->get_results(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 50"
        );
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-list-view"></span> Import Logs</h1>
            
            <div class="cai-card">
                <div class="cai-logs-header">
                    <h2>Recent Imports</h2>
                </div>
                
                <?php if (empty($logs)): ?>
                    <div class="notice notice-info">
                        <p>No import logs found. Import some images to see logs here.</p>
                    </div>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Import ID</th>
                                <th>Status</th>
                                <th>Files</th>
                                <th>Successful</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><code><?php echo esc_html(substr($log->import_id ?: 'N/A', 0, 8)); ?>...</code></td>
                                    <td>
                                        <span class="cai-status-badge cai-status-<?php echo esc_attr($log->status); ?>">
                                            <?php echo esc_html($log->status); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($log->processed_files . '/' . $log->total_files); ?></td>
                                    <td><?php echo esc_html($log->successful_files); ?></td>
                                    <td><?php echo esc_html(date('M j, Y', strtotime($log->created_at))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
                <div class="cai-logs-footer">
                    <a href="<?php echo admin_url('admin.php?page=cloud-auto-importer'); ?>" class="button">
                        <span class="dashicons dashicons-arrow-left-alt"></span> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: Check usage before import
     */
    public function ajax_check_usage() {
        check_ajax_referer('cai_ajax_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        $current_usage = $this->get_current_month_usage($user_id);
        $limit = 25;
        
        if ($current_usage >= $limit) {
            wp_send_json_error([
                'message' => __('You\'ve reached your free limit of 25 images this month.', 'cloud-auto-importer'),
                'upsell' => true,
                'upgrade_url' => admin_url('admin.php?page=cloud-auto-importer-upgrade')
            ]);
        }
        
        wp_send_json_success([
            'used' => $current_usage,
            'remaining' => $limit - $current_usage,
            'limit' => $limit
        ]);
    }
    
    /**
     * AJAX: Get usage stats for notifications
     */
    public function ajax_get_usage_stats() {
        check_ajax_referer('cai_ajax_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        $stats = $this->get_enhanced_stats($user_id);
        $has_basic_filenames = $this->check_if_has_basic_filenames($user_id);
        
        wp_send_json_success([
            'used' => $stats['monthly_used'],
            'limit' => $stats['monthly_limit'],
            'remaining' => $stats['remaining_images'],
            'total_imports' => $stats['total_imports'],
            'total_images' => $stats['total_images'],
            'has_basic_filenames' => $has_basic_filenames,
            'upgrade_url' => admin_url('admin.php?page=cloud-auto-importer-upgrade')
        ]);
    }
    
    /**
     * Handle Google Drive disconnect
     */
    public function handle_disconnect_google() {
        if (!wp_verify_nonce($_POST['cai_disconnect_nonce'], 'cai_disconnect_nonce')) {
            wp_die('Security check failed');
        }
        
        // Clear Google tokens
        delete_option('cai_google_access_token');
        delete_option('cai_google_refresh_token');
        delete_option('cai_google_token_expiry');
        delete_option('cai_google_token_received');
        
        wp_redirect(admin_url('admin.php?page=cloud-auto-importer&disconnected=1'));
        exit;
    }
    
    /**
     * AJAX: Clear logs
     */
    public function ajax_clear_logs() {
        check_ajax_referer('cai_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'cai_import_logs';
        $result = $wpdb->query("DELETE FROM $table_name");
        
        wp_send_json([
            'success' => $result !== false,
            'message' => $result !== false ? 'Logs cleared' : 'Failed to clear logs'
        ]);
    }
    
    /**
     * AJAX: Get import stats
     */
    public function ajax_get_import_stats() {
        check_ajax_referer('cai_ajax_nonce', 'nonce');
        
        $stats = $this->get_enhanced_stats();
        wp_send_json(['success' => true, 'stats' => $stats]);
    }
    
    /**
     * Get system information
     */
    private function get_system_info() {
        global $wpdb;
        
        return [
            'wp_version' => get_bloginfo('version'),
            'php_version' => phpversion(),
            'mysql_version' => $wpdb->db_version(),
            'upload_max' => ini_get('upload_max_filesize'),
            'memory_limit' => ini_get('memory_limit'),
        ];
    }
    
    /**
     * Display admin notices
     */
    public function display_admin_notices() {
        // Display connection success notice
        if (isset($_GET['connected']) && $_GET['connected'] == '1') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><span class="dashicons dashicons-yes"></span> <strong>Successfully connected to Google Drive!</strong> You can now import images.</p>
            </div>
            <?php
        }
        
        // Display disconnection notice
        if (isset($_GET['disconnected']) && $_GET['disconnected'] == '1') {
            ?>
            <div class="notice notice-info is-dismissible">
                <p><span class="dashicons dashicons-info"></span> <strong>Google Drive has been disconnected.</strong> You can reconnect anytime.</p>
            </div>
            <?php
        }
    }
}
?>
