
        cai_log('Google Drive class initialized', 'info');
        cai_log('Redirect URI: ' . $this->redirect_uri, 'debug');
        
        // Initialize connection handlers
        add_action('admin_init', [$this, 'handle_oauth_callback']);
        add_action('admin_init', [$this, 'check_google_connection_status']);
        
        // Add debug hooks
        if (defined('CAI_DEBUG') && CAI_DEBUG) {
            add_action('admin_notices', [$this, 'debug_admin_notice']);
        }
    }
    
    /**
     * Get Google OAuth authorization URL
     */
    public function get_auth_url() {
        $params = [
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => wp_create_nonce('cai_google_oauth')
        ];
        
        $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
        cai_log('Generated OAuth URL', 'debug');
        
        return $auth_url;
    }
    
    /**
     * Handle OAuth callback from Google
     */
    public function handle_oauth_callback() {
        // Check if this is a Google OAuth callback
        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            return;
        }
        
        // Only process on our plugin page
        if (!isset($_GET['page']) || $_GET['page'] !== 'cloud-auto-importer') {
            return;
        }
        
        // Verify nonce
        if (!wp_verify_nonce($_GET['state'], 'cai_google_oauth')) {
            wp_die('<div style="padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin: 20px;">
                    <h2 style="color: #721c24;">⚠️ Security Verification Failed</h2>
                    <p>Please try connecting again from the plugin settings page.</p>
                    <p><a href="' . admin_url('admin.php?page=cloud-auto-importer') . '" class="button">Return to Plugin</a></p>
                </div>');
        }
        
        $code = sanitize_text_field($_GET['code']);
        cai_log('OAuth callback received from Google', 'info');
        
        // Exchange code for tokens
        $tokens = $this->exchange_code_for_tokens($code);
        
        if (is_wp_error($tokens)) {
            cai_log('Token exchange failed: ' . $tokens->get_error_message(), 'error');
            
            // Store error message for display
            update_option('cai_last_oauth_error', $tokens->get_error_message());
            
            wp_redirect(admin_url('admin.php?page=cloud-auto-importer&oauth_error=1'));
            exit;
        }
        
        // Store tokens
        update_option('cai_google_access_token', $tokens['access_token']);
        update_option('cai_google_refresh_token', $tokens['refresh_token']);
        update_option('cai_google_token_expiry', time() + $tokens['expires_in']);
        update_option('cai_google_token_received', current_time('mysql'));
        
        cai_log('Google OAuth successful! Tokens stored.', 'info');
        
        // Store the exact redirect URI that worked
        update_option('cai_working_redirect_uri', $this->redirect_uri);
        
        // Clear any previous error
        delete_option('cai_last_oauth_error');
        
        // Redirect with success
        wp_redirect(admin_url('admin.php?page=cloud-auto-importer&connected=1'));
        exit;
    }
    
    /**
     * Exchange authorization code for tokens
     */
    private function exchange_code_for_tokens($code) {
        $post_data = [
            'code' => $code,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'redirect_uri' => $this->redirect_uri,
            'grant_type' => 'authorization_code'
        ];
        
        cai_log('Requesting token exchange', 'debug');
        
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => $post_data,
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json'
            ]
        ]);
        
        if (is_wp_error($response)) {
            cai_log('HTTP request failed: ' . $response->get_error_message(), 'error');
            return new WP_Error('connection_failed', 'Failed to connect to Google: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        cai_log('Token exchange response - Status: ' . $status_code, 'debug');
        
        if ($status_code !== 200) {
            $error_msg = 'HTTP ' . $status_code . ' - ';
            if (isset($data['error'])) {
                $error_msg .= $data['error'];
                if (isset($data['error_description'])) {
                    $error_msg .= ': ' . $data['error_description'];
                }
            }
            return new WP_Error('http_error', $error_msg);
        }
        
        if (!isset($data['access_token'])) {
            $error_msg = 'Google did not return an access token. ';
            if (isset($data['error'])) {
                $error_msg .= 'Error: ' . $data['error'];
                if (isset($data['error_description'])) {
                    $error_msg .= ' - ' . $data['error_description'];
                }
            }
            return new WP_Error('no_token', $error_msg);
        }
        
        return [
            'access_token' => sanitize_text_field($data['access_token']),
            'refresh_token' => isset($data['refresh_token']) ? sanitize_text_field($data['refresh_token']) : '',
            'expires_in' => absint($data['expires_in'])
        ];
    }
    
    /**
     * Check and refresh token if expired
     */
    public function check_google_connection_status() {
        $access_token = get_option('cai_google_access_token', '');
        $expiry = get_option('cai_google_token_expiry', 0);
        
        if (!empty($access_token) && $expiry < time() + 300) {
            cai_log('Access token expiring soon, attempting refresh', 'debug');
            $this->refresh_access_token();
        }
    }
    
    /**
     * Refresh access token
     */
    private function refresh_access_token() {
        $refresh_token = get_option('cai_google_refresh_token', '');
        
        if (empty($refresh_token)) {
            cai_log('No refresh token available', 'warning');
            return false;
        }
        
        cai_log('Refreshing access token...', 'debug');
        
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'refresh_token' => $refresh_token,
                'grant_type' => 'refresh_token'
            ],
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json'
            ]
        ]);
        
        if (is_wp_error($response)) {
            cai_log('Token refresh failed: ' . $response->get_error_message(), 'error');
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['access_token'])) {
            update_option('cai_google_access_token', $data['access_token']);
            update_option('cai_google_token_expiry', time() + $data['expires_in']);
            cai_log('Access token refreshed successfully', 'info');
            return true;
        } else {
            cai_log('Token refresh response missing access_token', 'error');
            if (isset($data['error'])) {
                cai_log('Google error: ' . $data['error'], 'error');
            }
        }
        
        return false;
    }
    
    /**
     * Check if Google Drive is connected
     */
    public function is_connected() {
        $token = get_option('cai_google_access_token', '');
        $expiry = get_option('cai_google_token_expiry', 0);
        
        $connected = !empty($token) && $expiry > time();
        
        if (!$connected) {
            // Try to refresh if we have a refresh token
            $refresh_token = get_option('cai_google_refresh_token', '');
            if (!empty($refresh_token) && $expiry < time()) {
                $this->refresh_access_token();
                // Re-check after refresh attempt
                $token = get_option('cai_google_access_token', '');
                $expiry = get_option('cai_google_token_expiry', 0);
                $connected = !empty($token) && $expiry > time();
            }
        }
        
        return $connected;
    }
    
    /**
     * Get connection status
     */
    public function get_connection_status() {
        return [
            'connected' => $this->is_connected(),
            'redirect_uri' => $this->redirect_uri,
            'current_site' => site_url(),
            'token_expires' => get_option('cai_google_token_expiry', 0),
            'token_expires_in' => get_option('cai_google_token_expiry', 0) - time(),
            'last_oauth_error' => get_option('cai_last_oauth_error', ''),
            'working_redirect_uri' => get_option('cai_working_redirect_uri', '')
        ];
    }
    
    /**
     * Disconnect Google Drive
     */
    public function disconnect() {
        // Optional: Revoke token on Google's side
        $access_token = get_option('cai_google_access_token', '');
        if (!empty($access_token)) {
            wp_remote_post('https://oauth2.googleapis.com/revoke', [
                'body' => ['token' => $access_token],
                'timeout' => 10
            ]);
        }
        
        // Clear local tokens
        delete_option('cai_google_access_token');
        delete_option('cai_google_refresh_token');
        delete_option('cai_google_token_expiry');
        delete_option('cai_google_token_received');
        delete_option('cai_last_oauth_error');
        delete_option('cai_working_redirect_uri');
        
        cai_log('Google Drive disconnected', 'info');
        
        return ['success' => true, 'message' => 'Disconnected from Google Drive'];
    }
    
    /**
     * Get list of files from Google Drive folder - UPDATED WITH BETTER ERROR HANDLING
     */
    public function list_files($folder_id = 'root', $limit = 100) {
        if (!$this->is_connected()) {
            cai_log('Google Drive not connected for list_files', 'error');
            return ['success' => false, 'message' => 'Google Drive not connected'];
        }
        
        $access_token = get_option('cai_google_access_token', '');
        
        if (empty($access_token)) {
            cai_log('Access token missing for list_files', 'error');
            return ['success' => false, 'message' => 'Access token missing'];
        }
        
        $url = 'https://www.googleapis.com/drive/v3/files';
        $params = [
            'q' => "'" . $folder_id . "' in parents and trashed = false",
            'pageSize' => $limit,
            'fields' => 'files(id,name,mimeType,size,modifiedTime,webViewLink,thumbnailLink)',
            'orderBy' => 'name',
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true'
        ];
        
        cai_log('Fetching files from folder: ' . $folder_id, 'info');
        
        $request_url = $url . '?' . http_build_query($params);
        cai_log('API Request URL: ' . $request_url, 'debug');
        
        $response = wp_remote_get($request_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Accept' => 'application/json'
            ],
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            $error_msg = 'Google Drive API error: ' . $response->get_error_message();
            cai_log($error_msg, 'error');
            return ['success' => false, 'message' => $error_msg];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        cai_log('Google Drive API Response - Status: ' . $status_code, 'debug');
        
        if ($status_code !== 200) {
            $error_msg = 'HTTP ' . $status_code;
            if (isset($data['error']['message'])) {
                $error_msg .= ': ' . $data['error']['message'];
            }
            cai_log('Google Drive API returned error: ' . $error_msg, 'error');
            return ['success' => false, 'message' => 'Google API Error: ' . $error_msg];
        }
        
        $files = isset($data['files']) ? $data['files'] : [];
        
        cai_log('Retrieved ' . count($files) . ' files from Google Drive', 'info');
        
        // Log first few files for debugging
        if (CAI_DEBUG && count($files) > 0) {
            for ($i = 0; $i < min(3, count($files)); $i++) {
                cai_log('File ' . ($i + 1) . ': ' . $files[$i]['name'] . ' (' . $files[$i]['id'] . ') - ' . $files[$i]['mimeType'], 'debug');
            }
        }
        
        return [
            'success' => true,
            'files' => $files,
            'count' => count($files),
            'folder_id' => $folder_id
        ];
    }
    
    /**
     * Download file from Google Drive - COMPLETELY REWRITTEN FOR BETTER ERROR HANDLING
     */
    public function download_file($file_id, $file_name) {
        cai_log('Attempting to download file: ' . $file_name . ' (' . $file_id . ')', 'info');
        
        if (!$this->is_connected()) {
            cai_log('Google Drive not connected for download', 'error');
            return ['success' => false, 'message' => 'Google Drive not connected'];
        }
        
        $access_token = get_option('cai_google_access_token', '');
        
        if (empty($access_token)) {
            cai_log('Access token missing for download', 'error');
            return ['success' => false, 'message' => 'Access token missing'];
        }
        
        // Create temp directory within WordPress uploads
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/cai_temp/';
        
        cai_log('Temp directory path: ' . $temp_dir, 'debug');
        
        if (!file_exists($temp_dir)) {
            if (!wp_mkdir_p($temp_dir)) {
                cai_log('Failed to create temp directory: ' . $temp_dir, 'error');
                return ['success' => false, 'message' => 'Failed to create temp directory'];
            }
            cai_log('Created temp directory: ' . $temp_dir, 'debug');
        }
        
        // Check if directory is writable
        if (!is_writable($temp_dir)) {
            cai_log('Temp directory not writable: ' . $temp_dir, 'error');
            return ['success' => false, 'message' => 'Temp directory not writable'];
        }
        
        // Clean filename and create unique temp file
        $clean_name = sanitize_file_name($file_name);
        $temp_file = $temp_dir . $clean_name . '_' . time() . '_' . wp_generate_password(6, false) . '.tmp';
        
        cai_log('Target temp file: ' . $temp_file, 'debug');
        
        // Download file from Google Drive
        $download_url = 'https://www.googleapis.com/drive/v3/files/' . $file_id . '?alt=media';
        cai_log('Download URL: ' . $download_url, 'debug');
        
        $response = wp_remote_get($download_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Accept' => '*/*'
            ],
            'timeout' => 60,
            'stream' => true,
            'filename' => $temp_file,
            'redirection' => 5
        ]);
        
        if (is_wp_error($response)) {
            $error_msg = 'Download failed: ' . $response->get_error_message();
            cai_log($error_msg, 'error');
            return ['success' => false, 'message' => $error_msg];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        cai_log('Download response status: ' . $status_code, 'debug');
        
        if ($status_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            $error_msg = 'Download failed with HTTP ' . $status_code;
            if (isset($data['error']['message'])) {
                $error_msg .= ': ' . $data['error']['message'];
            }
            
            cai_log($error_msg, 'error');
            
            // Clean up failed download
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
            
            return ['success' => false, 'message' => $error_msg];
        }
        
        // Verify file was actually downloaded
        if (!file_exists($temp_file)) {
            cai_log('Downloaded file not found at: ' . $temp_file, 'error');
            return ['success' => false, 'message' => 'File not saved to disk'];
        }
        
        $file_size = filesize($temp_file);
        cai_log('Download successful: ' . $clean_name . ' (' . size_format($file_size, 2) . ')', 'info');
        
        // Verify file is not empty
        if ($file_size === 0) {
            cai_log('Downloaded file is empty (0 bytes)', 'error');
            unlink($temp_file);
            return ['success' => false, 'message' => 'Downloaded file is empty'];
        }
        
        // Check if it's a valid image file
        $mime_type = mime_content_type($temp_file);
        $valid_image_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/tiff'];
        
        if (!in_array($mime_type, $valid_image_types)) {
            cai_log('Downloaded file is not a valid image. MIME type: ' . $mime_type, 'warning');
            // Don't fail yet - let the importer handle it
        }
        
        return [
            'success' => true,
            'temp_file' => $temp_file,
            'file_name' => $clean_name,
            'file_size' => $file_size,
            'original_name' => $file_name,
            'mime_type' => $mime_type
        ];
    }
    
    /**
     * Test file download with detailed debugging
     */
    public function test_download($file_id, $file_name) {
        cai_log('=== STARTING DOWNLOAD TEST ===', 'debug');
        cai_log('File ID: ' . $file_id, 'debug');
        cai_log('File Name: ' . $file_name, 'debug');
        
        $result = $this->download_file($file_id, $file_name);
        
        cai_log('Download test result: ' . print_r($result, true), 'debug');
        cai_log('=== DOWNLOAD TEST COMPLETE ===', 'debug');
        
        return $result;
    }
    
    /**
     * Extract folder ID from Google Drive URL
     */
    public static function extract_folder_id($url) {
        if (empty($url)) {
            return false;
        }
        
        // Remove any query parameters
        $url = strtok($url, '?');
        
        // Try different patterns
        $patterns = [
            '/\/folders\/([a-zA-Z0-9_-]+)/',
            '/\/d\/([a-zA-Z0-9_-]+)/',
            '/id=([a-zA-Z0-9_-]+)/',
            '/\/([a-zA-Z0-9_-]{25,})/'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                $folder_id = $matches[1];
                cai_log('Extracted folder ID: ' . $folder_id . ' from URL: ' . substr($url, 0, 50) . '...', 'debug');
                return $folder_id;
            }
        }
        
        cai_log('Could not extract folder ID from URL: ' . $url, 'warning');
        return false;
    }
    
    /**
     * Debug admin notice
     */
    public function debug_admin_notice() {
        if (strpos($_SERVER['REQUEST_URI'], 'cloud-auto-importer') !== false) {
            $status = $this->get_connection_status();
            ?>
            <div class="notice notice-info">
                <p><strong>🔧 Cloud Auto Importer - Debug Mode Active</strong></p>
                <p><strong>Connection Status:</strong> <?php echo $status['connected'] ? '✅ Connected' : '❌ Disconnected'; ?></p>
                <p><strong>Token Expires In:</strong> <?php echo $status['token_expires_in'] > 0 ? $status['token_expires_in'] . ' seconds' : 'Expired'; ?></p>
                <p><strong>Site URL:</strong> <code><?php echo esc_html(site_url()); ?></code></p>
                <p><strong>Redirect URI:</strong> <code><?php echo esc_html($this->redirect_uri); ?></code></p>
            </div>
            <?php
        }
    }

}
