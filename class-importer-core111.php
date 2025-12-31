<?php
/**
 * Cloud Auto Importer - Core Import Engine (Simplified)
 * Handles file processing with simplified monthly usage tracking
 */

if (!defined('ABSPATH')) {
    exit;
}

class CAI_Importer_Core {
    
    private $batch_size = 25;
    private $google_drive;
    
    public function __construct() {
        // Load Google Drive class if available
        if (class_exists('CAI_Google_Drive')) {
            $this->google_drive = new CAI_Google_Drive();
        }
        
        // Add AJAX handlers for background processing
        add_action('wp_ajax_cai_process_batch', [$this, 'ajax_process_batch']);
        add_action('wp_ajax_cai_get_import_status', [$this, 'ajax_get_import_status']);
        add_action('wp_ajax_cai_start_import', [$this, 'ajax_start_import']);
    }
    
    /**
     * AJAX: Start a new import
     */
    public function ajax_start_import() {
        // Security check
        if (!check_ajax_referer('cai_ajax_nonce', 'nonce', false)) {
            wp_send_json([
                'success' => false,
                'message' => 'Security check failed'
            ]);
        }
        
        if (!current_user_can('upload_files')) {
            wp_send_json([
                'success' => false,
                'message' => 'Permission denied'
            ]);
        }
        
        if (empty($_POST['cloud_folder_url'])) {
            wp_send_json([
                'success' => false,
                'message' => 'Please enter a Google Drive folder URL.'
            ]);
        }
        
        // CHECK MONTHLY USAGE LIMIT BEFORE STARTING
        $user_id = get_current_user_id();
        $current_usage = $this->get_current_month_usage($user_id);
        $monthly_limit = 25; // Free plan limit
        
        if ($current_usage >= $monthly_limit) {
            wp_send_json([
                'success' => false,
                'message' => 'Monthly limit reached. You have used ' . $current_usage . '/25 images this month.',
                'limit_reached' => true,
                'current_usage' => $current_usage,
                'monthly_limit' => $monthly_limit,
                'upgrade_url' => admin_url('admin.php?page=cloud-auto-importer-upgrade')
            ]);
        }
        
        $folder_url = sanitize_text_field($_POST['cloud_folder_url']);
        
        // Generate unique import ID
        $import_id = 'cai_' . time() . '_' . mt_rand(1000, 9999);
        
        // Prepare settings
        $settings = [
            'compress' => isset($_POST['compress_images']) && $_POST['compress_images'] == '1',
            'skip_duplicates' => isset($_POST['skip_duplicates']) && $_POST['skip_duplicates'] == '1',
            'generate_alt_text' => isset($_POST['generate_alt_text']) && $_POST['generate_alt_text'] == '1',
            'compression_quality' => 80,
            'max_width' => 1920,
            'max_height' => 1080
        ];
        
        // Check if this import would exceed the limit
        $files_result = $this->get_file_count_from_url($folder_url);
        
        if ($files_result['success']) {
            $total_files = $files_result['count'];
            
            if ($current_usage + $total_files > $monthly_limit) {
                $remaining = $monthly_limit - $current_usage;
                wp_send_json([
                    'success' => false,
                    'message' => sprintf(
                        'Import would exceed your monthly limit. You have %d images remaining. This folder contains %d images.',
                        $remaining,
                        $total_files
                    ),
                    'limit_warning' => true,
                    'remaining' => $remaining,
                    'total_files' => $total_files,
                    'upgrade_url' => admin_url('admin.php?page=cloud-auto-importer-upgrade')
                ]);
            }
        }
        
        // Start the import
        $result = $this->start_import($folder_url, $import_id, $settings);
        
        // Ensure response has import_id
        if ($result['success'] && !isset($result['import_id'])) {
            $result['import_id'] = $import_id;
        }
        
        wp_send_json($result);
    }
    
    /**
     * Get file count from URL without downloading
     */
    private function get_file_count_from_url($folder_url) {
        if (!$this->google_drive || !method_exists($this->google_drive, 'is_connected')) {
            return ['success' => false, 'message' => 'Google Drive not available'];
        }
        
        if (!$this->google_drive->is_connected()) {
            return ['success' => false, 'message' => 'Google Drive not connected'];
        }
        
        $folder_id = $this->extract_folder_id($folder_url);
        if (!$folder_id) {
            return ['success' => false, 'message' => 'Invalid folder URL'];
        }
        
        if (!method_exists($this->google_drive, 'get_file_count')) {
            return ['success' => false, 'message' => 'Method not available'];
        }
        
        return $this->google_drive->get_file_count($folder_id);
    }
    
    /**
     * Start a new import job
     */
    public function start_import($folder_url, $import_id, $settings = []) {
        // Validate Google Drive connection
        if (!$this->google_drive || !method_exists($this->google_drive, 'is_connected')) {
            return [
                'success' => false,
                'message' => 'Google Drive not properly connected'
            ];
        }
        
        if (!$this->google_drive->is_connected()) {
            return [
                'success' => false,
                'message' => 'Google Drive not connected'
            ];
        }
        
        // Extract folder ID from URL
        $folder_id = $this->extract_folder_id($folder_url);
        if (!$folder_id) {
            return [
                'success' => false,
                'message' => 'Invalid Google Drive URL'
            ];
        }
        
        // Get files from Google Drive
        if (!method_exists($this->google_drive, 'list_files')) {
            return [
                'success' => false,
                'message' => 'Google Drive method not available'
            ];
        }
        
        $files_result = $this->google_drive->list_files($folder_id, 1000);
        
        if (!$files_result['success']) {
            return [
                'success' => false,
                'message' => 'Failed to list files: ' . $files_result['message']
            ];
        }
        
        $files = $files_result['files'];
        $total_files = count($files);
        
        if ($total_files === 0) {
            return [
                'success' => true,
                'import_id' => $import_id,
                'total_files' => 0,
                'message' => 'No files found in folder'
            ];
        }
        
        // Check monthly limit again with actual file count
        $user_id = get_current_user_id();
        $current_usage = $this->get_current_month_usage($user_id);
        $monthly_limit = 25;
        
        if ($current_usage + $total_files > $monthly_limit) {
            $remaining = $monthly_limit - $current_usage;
            
            // Check how many images we can import without exceeding limit
            $files_to_import = min($total_files, $remaining);
            
            if ($files_to_import <= 0) {
                return [
                    'success' => false,
                    'message' => 'Monthly limit reached. You have used ' . $current_usage . '/25 images.',
                    'limit_reached' => true,
                    'current_usage' => $current_usage,
                    'upgrade_url' => admin_url('admin.php?page=cloud-auto-importer-upgrade')
                ];
            }
            
            // Only take as many files as we can import
            $files = array_slice($files, 0, $files_to_import);
            $total_files = $files_to_import;
        }
        
        // Store import data for processing
        $import_data = [
            'import_id' => $import_id,
            'folder_id' => $folder_id,
            'files' => $files,
            'total_files' => $total_files,
            'processed_files' => 0,
            'successful_files' => 0,
            'failed_files' => 0,
            'skipped_files' => 0,
            'settings' => wp_parse_args($settings, [
                'compress' => true,
                'compression_quality' => 80,
                'skip_duplicates' => true,
                'generate_alt_text' => true,
                'max_width' => 1920,
                'max_height' => 1080
            ]),
            'current_index' => 0,
            'attachment_ids' => [],
            'started_at' => current_time('mysql'),
            'last_update' => current_time('mysql'),
            'status' => 'processing',
            'user_id' => $user_id,
            'is_partial_import' => $files_to_import ?? false
        ];
        
        // Save import data to options table
        update_option("cai_import_{$import_id}", $import_data, false);
        
        // Create initial log entry
        $this->log_import_start($import_id, $total_files, $user_id);
        
        $message = "Found {$total_files} files. Starting import...";
        
        if (isset($files_to_import) && $files_to_import < $files_result['total']) {
            $message .= " Note: Only importing {$files_to_import} files to stay within monthly limit.";
        }
        
        return [
            'success' => true,
            'import_id' => $import_id,
            'total_files' => $total_files,
            'message' => $message,
            'is_partial_import' => isset($files_to_import) && $files_to_import < $files_result['total'],
            'files_skipped_due_to_limit' => isset($files_to_import) ? $files_result['total'] - $files_to_import : 0
        ];
    }
    
    /**
     * Log import start
     */
    private function log_import_start($import_id, $total_files, $user_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cai_import_logs';
        
        $wpdb->insert($table_name, [
            'import_type' => 'google_drive',
            'import_id' => $import_id,
            'user_id' => $user_id,
            'status' => 'processing',
            'total_files' => $total_files,
            'processed_files' => 0,
            'successful_files' => 0,
            'failed_files' => 0,
            'skipped_files' => 0,
            'created_at' => current_time('mysql')
        ]);
    }
    
    /**
     * Process a batch of files (called via AJAX)
     */
    public function ajax_process_batch() {
        // Security check
        check_ajax_referer('cai_ajax_nonce', 'nonce');
        
        if (!current_user_can('upload_files')) {
            wp_send_json([
                'success' => false,
                'message' => 'Permission denied'
            ]);
        }
        
        $import_id = sanitize_text_field($_POST['import_id']);
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : $this->batch_size;
        
        $result = $this->process_batch($import_id, $batch_size);
        
        wp_send_json($result);
    }
    
    /**
     * Process a batch of files
     */
    private function process_batch($import_id, $batch_size = 25) {
        // Get import data
        $import_data = get_option("cai_import_{$import_id}");
        
        if (!$import_data) {
            return [
                'success' => false,
                'message' => 'Import job not found'
            ];
        }
        
        $files = $import_data['files'];
        $total_files = $import_data['total_files'];
        $current_index = $import_data['current_index'];
        $processed_files = $import_data['processed_files'];
        $successful_files = $import_data['successful_files'];
        $failed_files = $import_data['failed_files'];
        $skipped_files = $import_data['skipped_files'];
        $settings = $import_data['settings'];
        $attachment_ids = $import_data['attachment_ids'];
        $user_id = $import_data['user_id'];
        
        // Check monthly limit before processing batch
        $current_usage = $this->get_current_month_usage($user_id);
        $monthly_limit = 25;
        
        // Calculate remaining capacity
        $remaining_capacity = $monthly_limit - $current_usage;
        
        if ($remaining_capacity <= 0) {
            return [
                'success' => false,
                'message' => 'Monthly limit reached. Cannot process more images.',
                'limit_reached' => true,
                'current_usage' => $current_usage,
                'upgrade_url' => admin_url('admin.php?page=cloud-auto-importer-upgrade')
            ];
        }
        
        // Adjust batch size to not exceed remaining capacity
        $remaining_files = $total_files - $current_index;
        $files_to_process = min($batch_size, $remaining_files, $remaining_capacity);
        
        if ($files_to_process <= 0) {
            return [
                'success' => false,
                'message' => 'Cannot process files - monthly limit reached.',
                'limit_reached' => true
            ];
        }
        
        $batch_results = [
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => []
        ];
        
        // Process each file in the batch
        for ($i = 0; $i < $files_to_process; $i++) {
            $file_index = $current_index + $i;
            
            if (!isset($files[$file_index])) {
                continue;
            }
            
            $file = $files[$file_index];
            $result = $this->process_single_file($file, $settings, $import_id, $user_id);
            
            $batch_results['processed']++;
            
            if ($result['success']) {
                $batch_results['successful']++;
                $attachment_ids[] = $result['attachment_id'];
            } elseif ($result['status'] === 'skipped') {
                $batch_results['skipped']++;
                $batch_results['errors'][] = $file['name'] . ': ' . $result['message'] . ' (Skipped)';
            } else {
                $batch_results['failed']++;
                $batch_results['errors'][] = $file['name'] . ': ' . $result['message'];
            }
        }
        
        // Update import data
        $new_current_index = $current_index + $files_to_process;
        $new_processed_files = $processed_files + $batch_results['processed'];
        $new_successful_files = $successful_files + $batch_results['successful'];
        $new_failed_files = $failed_files + $batch_results['failed'];
        $new_skipped_files = $skipped_files + $batch_results['skipped'];
        
        $import_data['current_index'] = $new_current_index;
        $import_data['processed_files'] = $new_processed_files;
        $import_data['successful_files'] = $new_successful_files;
        $import_data['failed_files'] = $new_failed_files;
        $import_data['skipped_files'] = $new_skipped_files;
        $import_data['attachment_ids'] = $attachment_ids;
        $import_data['last_update'] = current_time('mysql');
        $import_data['status'] = $new_current_index >= $total_files ? 'completed' : 'processing';
        
        update_option("cai_import_{$import_id}", $import_data, false);
        
        // Update database log
        $this->update_import_log(
            $import_id, 
            $total_files, 
            $new_processed_files,
            $new_successful_files,
            $new_failed_files,
            $new_skipped_files,
            $new_current_index >= $total_files ? 'completed' : 'processing'
        );
        
        // Update monthly usage
        if ($batch_results['successful'] > 0) {
            $this->increment_usage($user_id, $batch_results['successful']);
        }
        
        // Prepare response
        $response = [
            'success' => true,
            'import_id' => $import_id,
            'batch_results' => $batch_results,
            'progress' => [
                'current' => $new_current_index,
                'total' => $total_files,
                'percentage' => round(($new_current_index / $total_files) * 100),
                'processed' => $new_processed_files,
                'successful' => $new_successful_files,
                'failed' => $new_failed_files,
                'skipped' => $new_skipped_files
            ],
            'completed' => $new_current_index >= $total_files
        ];
        
        if ($response['completed']) {
            $response['message'] = sprintf(
                "Import completed! %d successful, %d failed, %d skipped.",
                $new_successful_files,
                $new_failed_files,
                $new_skipped_files
            );
            $response['attachment_ids'] = $attachment_ids;
            
            // Clean up import data after completion
            update_option("cai_import_{$import_id}_completed", $import_data, false);
            delete_option("cai_import_{$import_id}");
            
            // Store user who imported these files
            foreach ($attachment_ids as $attachment_id) {
                update_post_meta($attachment_id, '_cai_imported_by', $user_id);
            }
        }
        
        return $response;
    }
    
    /**
     * Update import log
     */
    private function update_import_log($import_id, $total_files, $processed_files, $successful_files, $failed_files, $skipped_files, $status) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cai_import_logs';
        
        // Find the log entry for this import
        $log_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name 
             WHERE import_id = %s 
             ORDER BY id DESC LIMIT 1",
            $import_id
        ));
        
        if ($log_id) {
            $wpdb->update($table_name, [
                'processed_files' => $processed_files,
                'successful_files' => $successful_files,
                'failed_files' => $failed_files,
                'skipped_files' => $skipped_files,
                'status' => $status,
                'completed_at' => $status === 'completed' ? current_time('mysql') : null
            ], ['id' => $log_id]);
        }
    }
    
    /**
     * Simple monthly usage tracking using WordPress options
     */
    private function increment_usage($user_id, $count) {
        if ($count <= 0) return;
        
        $month_year = date('Y-m');
        $option_name = "cai_user_usage_{$user_id}_{$month_year}";
        
        $current_usage = get_option($option_name, 0);
        $new_usage = $current_usage + $count;
        
        update_option($option_name, $new_usage, false);
        
        cai_log("📊 Updated monthly usage for user {$user_id}: +{$count} images (Total: {$new_usage})", 'info');
    }
    
    /**
     * Get current month usage for a user (simplified)
     */
    private function get_current_month_usage($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        $month_year = date('Y-m');
        $option_name = "cai_user_usage_{$user_id}_{$month_year}";
        
        return (int) get_option($option_name, 0);
    }
    
    /**
     * Process a single file
     */
    private function process_single_file($file_data, $settings, $import_id, $user_id) {
        $file_name = $file_data['name'];
        $file_id = $file_data['id'];
        $mime_type = $file_data['mimeType'];
        
        cai_log("🚀 Starting processing for: {$file_name}", 'debug');
        
        // Skip non-image files
        if (!$this->is_image_file($file_name, $mime_type)) {
            cai_log("⏭️ Skipping non-image file: {$file_name}", 'warning');
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'Not an image file'
            ];
        }
        
        // Download file from Google Drive
        $download_result = $this->google_drive->download_file($file_id, $file_name);
        
        if (!$download_result['success']) {
            cai_log("❌ Download failed for {$file_name}: " . $download_result['message'], 'error');
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'Download failed: ' . $download_result['message']
            ];
        }
        
        $temp_file = $download_result['temp_file'];
        cai_log("✅ Downloaded {$file_name} to temp file", 'debug');
        
        // Generate file hash for duplicate detection
        $file_hash = md5_file($temp_file);
        cai_log("🔑 File hash generated for {$file_name}: {$file_hash}", 'debug');
        
        // Check for duplicates if enabled
        if ($settings['skip_duplicates']) {
            $duplicate_check = $this->check_for_duplicate($file_name, $file_hash, $temp_file);
            
            if ($duplicate_check['is_duplicate']) {
                cai_log("🔄 Duplicate detected for {$file_name} - Skipping. Existing attachment ID: " . $duplicate_check['existing_id'], 'info');
                
                // Clean up temp file
                if (file_exists($temp_file)) {
                    unlink($temp_file);
                }
                
                return [
                    'success' => false,
                    'status' => 'skipped',
                    'message' => 'Duplicate file skipped'
                ];
            }
        }
        
        // Apply compression if enabled
        $compression_applied = false;
        $compression_saved = 0;
        
        if ($settings['compress'] && $this->is_compressible_image($temp_file)) {
            cai_log("⚙️ Starting compression for {$file_name}", 'debug');
            $compression_result = $this->compress_image($temp_file, $settings);
            
            if ($compression_result['success']) {
                // Delete old temp file
                if (file_exists($temp_file)) {
                    unlink($temp_file);
                }
                
                $temp_file = $compression_result['file_path'];
                $compression_applied = true;
                $compression_saved = $compression_result['savings_percent'];
                
                cai_log("✅ Compression successful for {$file_name}: Saved {$compression_saved}%", 'success');
            } else {
                cai_log("⚠️ Compression failed for {$file_name}: " . $compression_result['message'], 'warning');
            }
        }
        
        // GENERATE ALT TEXT FROM FILENAME
        $alt_text = '';
        if ($settings['generate_alt_text']) {
            $alt_text = $this->generate_alt_text($file_name);
            cai_log("🏷️ Generated alt text for {$file_name}: {$alt_text}", 'debug');
        }
        
        // Save to WordPress Media Library WITH ALT TEXT
        $attachment_id = $this->save_to_media_library($temp_file, $file_name, $import_id, $file_hash, $alt_text, $user_id);
        
        // Clean up temp file
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }
        
        if (is_wp_error($attachment_id)) {
            cai_log("❌ Media Library error for {$file_name}: " . $attachment_id->get_error_message(), 'error');
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'Media Library error: ' . $attachment_id->get_error_message()
            ];
        }
        
        if (!$attachment_id) {
            cai_log("❌ Failed to save {$file_name} to Media Library", 'error');
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'Failed to save to Media Library'
            ];
        }
        
        // Add compression info to attachment meta
        if ($compression_applied) {
            update_post_meta($attachment_id, '_cai_compression_saved', $compression_saved);
            update_post_meta($attachment_id, '_cai_compression_applied', true);
        }
        
        cai_log("🎉 Successfully imported {$file_name} as attachment ID: {$attachment_id} with alt: {$alt_text}", 'success');
        
        return [
            'success' => true,
            'status' => 'success',
            'attachment_id' => $attachment_id,
            'file_name' => $file_name,
            'file_hash' => $file_hash,
            'alt_text' => $alt_text,
            'compression_applied' => $compression_applied,
            'compression_saved' => $compression_saved,
            'message' => 'Successfully imported' . ($compression_applied ? ' (compressed)' : '')
        ];
    }
    
    /**
     * Check for duplicate files
     */
    private function check_for_duplicate($file_name, $file_hash, $temp_file) {
        global $wpdb;
        
        // Check by file hash (most reliable)
        $existing_by_hash = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_cai_file_hash' 
             AND meta_value = %s 
             LIMIT 1",
            $file_hash
        ));
        
        if ($existing_by_hash) {
            return [
                'is_duplicate' => true,
                'existing_id' => $existing_by_hash,
                'method' => 'hash'
            ];
        }
        
        return [
            'is_duplicate' => false,
            'existing_id' => null,
            'method' => 'none'
        ];
    }
    
    /**
     * Generate SEO-friendly alt text from filename
     */
    private function generate_alt_text($filename) {
        // Remove file extension
        $name_without_ext = preg_replace('/\\.[^.\\s]{3,4}$/', '', $filename);
        
        // Common patterns to clean
        $patterns = [
            '/\d{4}-\d{2}-\d{2}_/',    // Remove date prefixes: 2023-12-25_
            '/\d{8}_/',                 // Remove date prefixes: 20231225_
            '/\d+_/',                   // Remove number prefixes: 001_
            '/^DSC_/',                  // Remove camera prefixes: DSC_
            '/^IMG_/',                  // Remove IMG_ prefixes
            '/^PIC_/',                  // Remove PIC_ prefixes
            '/^Screenshot_/',           // Remove Screenshot_ prefixes
            '/^photo_/',                // Remove photo_ prefixes
            '/^image_/',                // Remove image_ prefixes
        ];
        
        $clean_name = preg_replace($patterns, '', $name_without_ext);
        
        // Replace separators with spaces
        $separators = ['-', '_', '.'];
        $clean_name = str_replace($separators, ' ', $clean_name);
        
        // Remove special characters
        $clean_name = preg_replace('/[^a-zA-Z0-9\s]/', '', $clean_name);
        
        // Remove extra spaces
        $clean_name = preg_replace('/\s+/', ' ', $clean_name);
        $clean_name = trim($clean_name);
        
        // If empty, use the original name
        if (empty($clean_name) || strlen($clean_name) < 2) {
            $clean_name = $name_without_ext;
            $clean_name = str_replace(['-', '_'], ' ', $clean_name);
            $clean_name = preg_replace('/[^a-zA-Z0-9\s]/', '', $clean_name);
            $clean_name = trim($clean_name);
        }
        
        // Convert to lowercase then capitalize
        $clean_name = strtolower($clean_name);
        $clean_name = ucwords($clean_name);
        
        // Add context if too generic
        if (strlen($clean_name) < 5) {
            $clean_name = "Image of " . $clean_name;
        }
        
        // Limit length
        if (strlen($clean_name) > 125) {
            $clean_name = substr($clean_name, 0, 122) . '...';
        }
        
        // If still empty, create descriptive alt text
        if (empty($clean_name)) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $clean_name = "Uploaded " . strtoupper($extension) . " image: " . $filename;
        }
        
        return $clean_name;
    }
    
    /**
     * Compress an image file
     */
    private function compress_image($file_path, $settings) {
        if (!file_exists($file_path)) {
            return ['success' => false, 'message' => 'File not found'];
        }
        
        // Get original file size
        $original_size = filesize($file_path);
        
        if (!function_exists('wp_get_image_editor')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        
        $editor = wp_get_image_editor($file_path);
        
        if (is_wp_error($editor)) {
            return ['success' => false, 'message' => $editor->get_error_message()];
        }
        
        // Resize if needed
        $size = $editor->get_size();
        $max_width = $settings['max_width'] ?? 1920;
        $max_height = $settings['max_height'] ?? 1080;
        
        if ($size['width'] > $max_width || $size['height'] > $max_height) {
            $editor->resize($max_width, $max_height, false);
        }
        
        // Generate new filename
        $pathinfo = pathinfo($file_path);
        $compressed_path = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '-compressed.' . $pathinfo['extension'];
        
        // Save with compression
        $quality = $settings['compression_quality'] ?? 80;
        $result = $editor->save($compressed_path, null, $quality);
        
        if (is_wp_error($result)) {
            return ['success' => false, 'message' => $result->get_error_message()];
        }
        
        // Check if compression actually reduced size
        $compressed_size = filesize($result['path']);
        
        if ($compressed_size >= $original_size) {
            // Compression didn't help, use original
            unlink($result['path']);
            return [
                'success' => false, 
                'message' => 'Compression did not reduce file size'
            ];
        }
        
        $savings_percent = round((($original_size - $compressed_size) / $original_size) * 100, 2);
        
        return [
            'success' => true,
            'file_path' => $result['path'],
            'original_size' => $original_size,
            'compressed_size' => $compressed_size,
            'savings_percent' => $savings_percent
        ];
    }
    
    /**
     * Save file to WordPress Media Library
     */
    private function save_to_media_library($file_path, $file_name, $import_id, $file_hash, $alt_text = '', $user_id = null) {
        if (!file_exists($file_path)) {
            return new WP_Error('file_missing', 'Temporary file not found');
        }
        
        // Prepare file array for media_handle_sideload
        $file_array = [
            'name' => sanitize_file_name($file_name),
            'tmp_name' => $file_path
        ];
        
        // Include required WordPress files
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Upload to Media Library
        $attachment_id = media_handle_sideload($file_array, 0);
        
        // Add import metadata
        if (!is_wp_error($attachment_id)) {
            // Store file hash for duplicate detection
            update_post_meta($attachment_id, '_cai_import_id', $import_id);
            update_post_meta($attachment_id, '_cai_imported_at', current_time('mysql'));
            update_post_meta($attachment_id, '_cai_file_hash', $file_hash);
            
            // Store user who imported this file
            if ($user_id) {
                update_post_meta($attachment_id, '_cai_imported_by', $user_id);
            }
            
            // SET ALT TEXT
            if (!empty($alt_text)) {
                update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
            }
            
            // Also set the post title
            wp_update_post([
                'ID' => $attachment_id,
                'post_title' => !empty($alt_text) ? $alt_text : sanitize_file_name($file_name)
            ]);
            
            cai_log("💾 Stored metadata for attachment {$attachment_id}: Hash={$file_hash} | Alt={$alt_text}", 'debug');
        }
        
        return $attachment_id;
    }
    
    /**
     * Check if file is an image
     */
    private function is_image_file($file_name, $mime_type) {
        $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif'];
        $image_mimes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 
            'image/bmp', 'image/tiff', 'image/x-tiff', 'image/svg+xml'
        ];
        
        $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        return in_array($extension, $image_extensions) || in_array($mime_type, $image_mimes);
    }
    
    /**
     * Check if image can be compressed
     */
    private function is_compressible_image($file_path) {
        $compressible_types = ['image/jpeg', 'image/png', 'image/webp'];
        
        if (!function_exists('mime_content_type')) {
            // Fallback to extension check
            $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            return in_array($extension, ['jpg', 'jpeg', 'png', 'webp']);
        }
        
        $mime_type = mime_content_type($file_path);
        return in_array($mime_type, $compressible_types);
    }
    
    /**
     * Extract folder ID from Google Drive URL
     */
    private function extract_folder_id($url) {
        if (preg_match('/\/folders\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return $matches[1];
        }
        
        if (preg_match('/id=([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return $matches[1];
        }
        
        // Handle short URL format
        if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return $matches[1];
        }
        
        return false;
    }
    
    /**
     * AJAX: Get import status
     */
    public function ajax_get_import_status() {
        check_ajax_referer('cai_ajax_nonce', 'nonce');
        
        if (!current_user_can('upload_files')) {
            wp_send_json([
                'success' => false,
                'message' => 'Permission denied'
            ]);
        }
        
        $import_id = sanitize_text_field($_POST['import_id']);
        $import_data = get_option("cai_import_{$import_id}");
        
        if (!$import_data) {
            // Check if completed
            $import_data = get_option("cai_import_{$import_id}_completed");
            
            if (!$import_data) {
                wp_send_json([
                    'success' => false,
                    'message' => 'Import not found'
                ]);
            }
            
            wp_send_json([
                'success' => true,
                'completed' => true,
                'progress' => [
                    'current' => $import_data['total_files'],
                    'total' => $import_data['total_files'],
                    'percentage' => 100,
                    'processed' => $import_data['processed_files'],
                    'successful' => $import_data['successful_files'],
                    'failed' => $import_data['failed_files'],
                    'skipped' => $import_data['skipped_files']
                ],
                'attachment_ids' => $import_data['attachment_ids']
            ]);
        }
        
        $current_index = $import_data['current_index'];
        $total_files = $import_data['total_files'];
        
        wp_send_json([
            'success' => true,
            'completed' => $current_index >= $total_files,
            'progress' => [
                'current' => $current_index,
                'total' => $total_files,
                'percentage' => round(($current_index / $total_files) * 100),
                'processed' => $import_data['processed_files'],
                'successful' => $import_data['successful_files'],
                'failed' => $import_data['failed_files'],
                'skipped' => $import_data['skipped_files']
            ]
        ]);
    }
}
?>
