// Cloud Auto Importer - Admin JavaScript (Simplified)
// Version: 3.0.0 - Simplified Monthly Usage System

(function($) {
    'use strict';
    
    // Global variables
    var CAI = {
        ajaxurl: '',
        nonce: '',
        currentImportId: null,
        isImporting: false,
        pollInterval: null,
        pollFrequency: 3000, // Poll every 3 seconds
        batchSize: 25,
        isPaused: false,
        batchInProgress: false,
        currentBatchNumber: 0,
        totalBatches: 0,
        importedStats: {
            successful: 0,
            failed: 0,
            skipped: 0
        },
        usageStats: {
            used: 0,
            limit: 25,
            remaining: 25,
            percent: 0
        },
        notificationManager: null
    };
    
    // Initialize the plugin
    function init() {
        console.log('Cloud Auto Importer JS v3.0.0 - Simplified monthly usage');
        
        // Initialize from localized script
        if (typeof cai_ajax !== 'undefined') {
            CAI.ajaxurl = cai_ajax.ajax_url;
            CAI.nonce = cai_ajax.nonce;
            CAI.usageStats = {
                used: cai_ajax.current_usage || 0,
                limit: cai_ajax.usage_limit || 25,
                remaining: Math.max(0, (cai_ajax.usage_limit || 25) - (cai_ajax.current_usage || 0)),
                percent: cai_ajax.usage_percent || 0
            };
            console.log('Usage stats initialized:', CAI.usageStats);
        } else {
            console.error('cai_ajax object not defined!');
            CAI.ajaxurl = ajaxurl || '/wp-admin/admin-ajax.php';
        }
        
        // Initialize notification manager
        CAI.notificationManager = new CAINotification();
        
        // Bind events
        bindEvents();
        
        // Check for existing imports on page load
        checkExistingImports();
        
        // Initialize UI components
        initUI();
        
        // Check usage and show notifications
        setTimeout(checkUsageAndShowNotifications, 1500);
    }
    
    // Notification Manager Class
    function CAINotification() {
        this.queue = [];
        this.init();
    }
    
    CAINotification.prototype.init = function() {
        console.log('Notification manager initialized');
        this.checkUsageAndNotify();
    };
    
    CAINotification.prototype.show = function(type, data) {
        var notifications = {
            'usage_warning': {
                title: '⚠️ Monthly Limit Almost Reached',
                message: 'You\'ve used ' + data.used + '/25 images. Only ' + data.remaining + ' left this month.',
                type: 'warning',
                actions: [
                    { text: 'Upgrade Now', url: data.upgrade_url, primary: true },
                    { text: 'Dismiss', action: 'dismiss' }
                ]
            },
            'limit_reached': {
                title: '🚫 Monthly Limit Reached',
                message: 'You\'ve reached your 25 image limit. Upgrade to continue importing.',
                type: 'error',
                actions: [
                    { text: 'Upgrade to Basic (500 images)', url: data.upgrade_url, primary: true },
                    { text: 'Maybe Later', action: 'dismiss' }
                ]
            },
            'success_upsell': {
                title: '🎉 Import Successful!',
                message: 'You imported ' + data.count + ' images. Imagine doing 20x more with AI alt text!',
                type: 'success',
                actions: [
                    { text: 'See Plans', url: data.upgrade_url, primary: true },
                    { text: 'Maybe Later', action: 'dismiss' }
                ]
            }
        };
        
        var config = notifications[type];
        if (!config) return;
        
        this.renderNotification(config);
    };
    
    CAINotification.prototype.renderNotification = function(config) {
        var toast = document.createElement('div');
        toast.className = 'cai-toast cai-toast-' + config.type;
        toast.innerHTML = `
            <div class="cai-toast-header">
                <h4>${config.title}</h4>
                <button class="cai-toast-close">&times;</button>
            </div>
            <div class="cai-toast-body">
                <p>${config.message}</p>
                <div class="cai-toast-actions">
                    ${config.actions.map(action => 
                        action.url ? 
                        `<a href="${action.url}" class="button ${action.primary ? 'button-primary' : ''}">${action.text}</a>` :
                        `<button class="button ${action.primary ? 'button-primary' : ''}" data-action="${action.action}">${action.text}</button>`
                    ).join('')}
                </div>
            </div>
        `;
        
        document.body.appendChild(toast);
        
        // Auto-remove after 10 seconds if not error
        if (config.type !== 'error') {
            setTimeout(() => {
                if (document.body.contains(toast)) {
                    toast.remove();
                }
            }, 10000);
        }
        
        // Add event listeners
        $(toast).find('.cai-toast-close').on('click', function() {
            $(toast).remove();
        });
        
        $(toast).find('[data-action]').on('click', function(e) {
            var action = $(this).data('action');
            this.handleAction(action);
            $(toast).remove();
        }.bind(this));
    };
    
    CAINotification.prototype.handleAction = function(action) {
        switch(action) {
            case 'dismiss':
                // Just close the notification
                break;
            case 'learn_more':
                // Open upgrade page
                window.open(cai_ajax.upgrade_url || '#', '_blank');
                break;
        }
    };
    
    CAINotification.prototype.checkUsageAndNotify = function() {
        var self = this;
        
        $.ajax({
            url: CAI.ajaxurl,
            type: 'POST',
            data: {
                action: 'cai_get_usage_stats',
                nonce: CAI.nonce
            },
            success: function(response) {
                if (response.success) {
                    var stats = response.data;
                    
                    // Show warning at 80% usage (20/25)
                    if (stats.used >= 20 && stats.used < 25) {
                        self.show('usage_warning', {
                            used: stats.used,
                            remaining: stats.remaining,
                            upgrade_url: stats.upgrade_url
                        });
                    }
                }
            }
        });
    };
    
    // Check usage and update UI
    function checkUsageAndShowNotifications() {
        updateUsageUI();
        updateImportButtonState();
    }
    
    // Update usage UI - Compatible with new quick stats
    function updateUsageUI() {
        // Try to update the OLD quick stats (if they exist)
        if ($('.cai-progress-fill').length) {
            $('.cai-progress-fill').css('width', CAI.usageStats.percent + '%');
            $('.cai-progress-text strong').text(CAI.usageStats.used + '/' + CAI.usageStats.limit);
            $('.cai-progress-text span').text(CAI.usageStats.remaining + ' left');
        }
        
        // Update the NEW quick stats
        updateNewQuickStatsUI();
    }
    
    // Update the new quick stats bar
    function updateNewQuickStatsUI() {
        if ($('.cai-quick-stats-bar .monthly-usage').length) {
            // Update the main monthly usage card
            $('.cai-quick-stats-bar .monthly-usage .cai-stat-value').text(
                CAI.usageStats.used + '/' + CAI.usageStats.limit
            );
            
            $('.cai-quick-stats-bar .monthly-usage .cai-progress-fill').css(
                'width', CAI.usageStats.percent + '%'
            );
            
            $('.cai-quick-stats-bar .monthly-usage .cai-progress-text span:first-child').text(
                CAI.usageStats.remaining + ' images remaining'
            );
            
            // Show/hide warning
            if (CAI.usageStats.percent >= 80) {
                $('.cai-quick-stats-bar .monthly-usage .cai-usage-warning').show();
            } else {
                $('.cai-quick-stats-bar .monthly-usage .cai-usage-warning').hide();
            }
        }
    }
    
    // Update import button state
    function updateImportButtonState() {
        var $importBtn = $('#cai-start-import-btn');
        var $usageWarning = $('#cai-usage-warning');
        
        if (CAI.usageStats.used >= CAI.usageStats.limit) {
            $importBtn.prop('disabled', true);
            $importBtn.html('<span class="dashicons dashicons-lock"></span> Limit Reached');
            if ($usageWarning.length) $usageWarning.show();
        } else {
            $importBtn.prop('disabled', false);
            $importBtn.html('<span class="dashicons dashicons-cloud-upload"></span> Start Import');
            if ($usageWarning.length) $usageWarning.hide();
        }
    }
    
    // Initialize UI components
    function initUI() {
        // Show/hide compression options based on checkbox
        $('#cai_compress_images').on('change', function() {
            if ($(this).is(':checked')) {
                $('#cai-compression-options').slideDown(200);
            } else {
                $('#cai-compression-options').slideUp(200);
            }
        });
        
        // Trigger initial state
        $('#cai_compress_images').trigger('change');
        
        // Tooltips
        $('.cai-tooltip').on('mouseenter', function() {
            var tip = $(this).data('tip');
            if (tip) {
                $(this).append('<span class="cai-tooltip-text">' + tip + '</span>');
            }
        }).on('mouseleave', function() {
            $(this).find('.cai-tooltip-text').remove();
        });
        
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
    }
    
    // Bind all event handlers
    function bindEvents() {
        // Import form submission - PREVENT DEFAULT
        $(document).on('submit', '#cai-import-form', handleImportStart);
        
        // Import controls
        $(document).on('click', '#cai-pause-import', pauseImport);
        $(document).on('click', '#cai-resume-import', resumeImport);
        $(document).on('click', '#cai-cancel-import', cancelImport);
        $(document).on('click', '#cai-view-results', viewResults);
        
        // Upgrade buttons
        $(document).on('click', '.cai-upgrade-btn', function(e) {
            e.preventDefault();
            var plan = $(this).data('plan');
            showUpgradeModal(plan);
        });
        
        // Close modal
        $(document).on('click', '.cai-modal-close, .cai-modal-overlay', function() {
            $('.cai-modal').remove();
        });
    }
    
    // Handle import form submission via AJAX
    function handleImportStart(e) {
        e.preventDefault(); // CRITICAL: Stop normal form submission
        
        var $form = $(this);
        var $submitBtn = $form.find('button[type="submit"]');
        var $spinner = $('#cai-import-spinner');
        var folderUrl = $form.find('#cloud_folder_url').val().trim();
        
        // Validation
        if (!folderUrl) {
            showNotification('Please enter a Google Drive folder URL', 'error');
            return false;
        }
        
        if (!folderUrl.includes('drive.google.com')) {
            showNotification('Please enter a valid Google Drive URL', 'error');
            return false;
        }
        
        // Check usage limit before starting
        if (CAI.usageStats.used >= CAI.usageStats.limit) {
            showLimitReachedModal();
            return false;
        }
        
        // Disable form and show loading
        $form.find('input, button, select').prop('disabled', true);
        $submitBtn.text('Starting Import...');
        $spinner.show();
        
        // Get form values
        var formData = {
            action: 'cai_start_import',
            nonce: CAI.nonce,
            cloud_folder_url: folderUrl,
            compress_images: $('#cai_compress_images').is(':checked') ? 1 : 0,
            skip_duplicates: $('#cai_skip_duplicates').is(':checked') ? 1 : 0,
            generate_alt_text: $('#cai_generate_alt_text').is(':checked') ? 1 : 0
        };
        
        // Add compression quality if compression is enabled
        if ($('#cai_compress_images').is(':checked')) {
            formData.compression_quality = $('#cai_compression_quality').val() || 80;
        }
        
        console.log('Sending import request with data:', formData);
        
        // Send AJAX request instead of form submission
        $.ajax({
            url: CAI.ajaxurl,
            type: 'POST',
            data: formData,
            success: function(response) {
                console.log('Start import response:', response);
                
                if (response.success) {
                    showNotification(response.message, 'success');
                    
                    // Show warning if partial import due to limit
                    if (response.is_partial_import) {
                        showNotification(
                            'Note: Only ' + response.total_files + ' files will be imported to stay within monthly limit. ' + 
                            response.files_skipped_due_to_limit + ' files skipped.',
                            'warning'
                        );
                    }
                    
                    // Start tracking with the import ID
                    if (response.import_id) {
                        startImportProgress(response.import_id, response.total_files);
                        
                        // Store import ID in form for potential resume
                        $form.find('#cai-current-import-id').val(response.import_id);
                    } else {
                        showNotification('Import started but no ID returned', 'warning');
                        // Re-enable form
                        resetFormUI($form, $submitBtn, $spinner);
                    }
                } else {
                    // Handle limit reached error
                    if (response.limit_reached) {
                        showLimitReachedModal(response.message);
                    } else if (response.limit_warning) {
                        showLimitWarningModal(response.remaining, response.total_files);
                    } else {
                        showNotification(response.message || 'Import failed', 'error');
                    }
                    // Re-enable form
                    resetFormUI($form, $submitBtn, $spinner);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error, xhr.responseText);
                showNotification('AJAX error: ' + error, 'error');
                // Re-enable form
                resetFormUI($form, $submitBtn, $spinner);
            }
        });
        
        return false;
    }
    
    // Reset form UI after error/success
    function resetFormUI($form, $submitBtn, $spinner) {
        $form.find('input, button, select').prop('disabled', false);
        $submitBtn.text('Start Import');
        $spinner.hide();
        updateImportButtonState();
    }
    
    // Show limit reached modal
    function showLimitReachedModal(message) {
        var modalHtml = `
            <div class="cai-modal-overlay"></div>
            <div class="cai-modal">
                <div class="cai-modal-content">
                    <button class="cai-modal-close">&times;</button>
                    <h3>🚫 Monthly Limit Reached</h3>
                    <p>${message || 'You\'ve reached your monthly limit of 25 images.'}</p>
                    <div class="cai-plan-comparison">
                        <div class="cai-plan-card">
                            <h4>Current (Free)</h4>
                            <ul>
                                <li>25 images/month</li>
                                <li>Basic compression</li>
                                <li>Filename alt text</li>
                            </ul>
                        </div>
                        <div class="cai-plan-card highlight">
                            <h4>Basic Plan</h4>
                            <ul>
                                <li><strong>500 images/month</strong></li>
                                <li>AI alt text</li>
                                <li>Better compression</li>
                                <li>More folders</li>
                            </ul>
                            <div class="cai-plan-price">$4/month</div>
                        </div>
                    </div>
                    <div class="cai-modal-actions">
                        <a href="${cai_ajax.upgrade_url || '#'}" class="button button-primary">Upgrade Now</a>
                        <button class="button cai-modal-close">Maybe Later</button>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(modalHtml);
    }
    
    // Show limit warning modal
    function showLimitWarningModal(remaining, totalFiles) {
        var modalHtml = `
            <div class="cai-modal-overlay"></div>
            <div class="cai-modal">
                <div class="cai-modal-content">
                    <button class="cai-modal-close">&times;</button>
                    <h3>⚠️ Limit Warning</h3>
                    <p>You have only ${remaining} images remaining this month, but this folder contains ${totalFiles} images.</p>
                    <p>You can import ${remaining} images now and upgrade to continue with the rest.</p>
                    <div class="cai-modal-actions">
                        <button class="button button-primary" onclick="importPartial(${remaining})">Import ${remaining} Images</button>
                        <a href="${cai_ajax.upgrade_url || '#'}" class="button">Upgrade to Import All</a>
                        <button class="button cai-modal-close">Cancel</button>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(modalHtml);
    }
    
    // Show upgrade modal for specific plan
    function showUpgradeModal(plan) {
        var plans = {
            'basic': {
                title: 'Basic Plan',
                features: ['500 images/month', 'AI alt text', 'Better compression', 'More folders'],
                price: '$4/month'
            },
            'pro': {
                title: 'Pro Plan',
                features: ['2,000-5,000 images/month', 'Gemini AI alt text', 'Advanced compression', 'Multiple cloud sources'],
                price: '$10/month'
            },
            'lifetime': {
                title: 'Lifetime Plan',
                features: ['Unlimited images', 'All cloud sources', 'Gemini AI', 'Lifetime updates'],
                price: '$199 one-time'
            }
        };
        
        var planInfo = plans[plan] || plans.basic;
        
        var modalHtml = `
            <div class="cai-modal-overlay"></div>
            <div class="cai-modal">
                <div class="cai-modal-content">
                    <button class="cai-modal-close">&times;</button>
                    <h3>🚀 Upgrade to ${planInfo.title}</h3>
                    <ul class="cai-plan-features-list">
                        ${planInfo.features.map(feature => `<li>${feature}</li>`).join('')}
                    </ul>
                    <div class="cai-plan-price-large">${planInfo.price}</div>
                    <div class="cai-modal-actions">
                        <a href="${cai_ajax.upgrade_url || '#'}" class="button button-primary">Get ${planInfo.title}</a>
                        <button class="button cai-modal-close">Not Now</button>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(modalHtml);
    }
    
    // Start tracking import progress
    function startImportProgress(importId, totalFiles) {
        if (!importId) {
            return;
        }
        
        // Reset stats
        CAI.importedStats = {
            successful: 0,
            failed: 0,
            skipped: 0
        };
        
        CAI.currentImportId = importId;
        CAI.isImporting = true;
        CAI.isPaused = false;
        CAI.batchInProgress = false;
        CAI.currentBatchNumber = 0;
        
        // Calculate total batches
        if (totalFiles) {
            CAI.totalBatches = Math.ceil(totalFiles / CAI.batchSize);
            console.log('Total batches to process:', CAI.totalBatches, 'for', totalFiles, 'files');
        }
        
        // Show progress container if hidden
        $('#cai-progress-container').show().removeClass('completed');
        
        // Update initial UI
        updateProgressUI(0, 'Initializing import...', 0, totalFiles);
        
        // Reset stats display
        updateStatsDisplay();
        
        // Start polling for status (with initial delay)
        setTimeout(function() {
            CAI.pollInterval = setInterval(pollImportStatus, CAI.pollFrequency);
        }, 1000);
        
        // Show pause button
        $('#cai-pause-import').show();
        $('#cai-resume-import').hide();
        $('#cai-cancel-import').show();
        
        console.log('Started tracking import:', importId);
        
        // Trigger first batch after a short delay
        setTimeout(function() {
            if (CAI.isImporting && !CAI.isPaused) {
                triggerNextBatch(importId);
            }
        }, 1500);
    }
    
    // Poll for import status
    function pollImportStatus() {
        if (!CAI.currentImportId || !CAI.isImporting || CAI.isPaused || CAI.batchInProgress) {
            return;
        }
        
        $.ajax({
            url: CAI.ajaxurl,
            type: 'POST',
            data: {
                action: 'cai_get_import_status',
                nonce: CAI.nonce,
                import_id: CAI.currentImportId
            },
            success: function(response) {
                console.log('Poll response:', response);
                
                if (response.success) {
                    // Update progress UI
                    updateProgressUI(
                        response.progress.percentage,
                        'Processed: ' + response.progress.processed + '/' + response.progress.total + ' files',
                        response.progress.processed,
                        response.progress.total
                    );
                    
                    // Update stats from progress
                    if (response.progress) {
                        CAI.importedStats.successful = response.progress.successful || 0;
                        CAI.importedStats.failed = response.progress.failed || 0;
                        CAI.importedStats.skipped = response.progress.skipped || 0;
                        updateStatsDisplay();
                    }
                    
                    // If not completed and batch not in progress, trigger next batch
                    if (!response.completed && 
                        response.progress.current < response.progress.total && 
                        !CAI.batchInProgress) {
                        
                        // Check if we need to trigger a batch
                        var filesRemaining = response.progress.total - response.progress.current;
                        var filesInCurrentBatch = Math.min(CAI.batchSize, filesRemaining);
                        
                        if (filesRemaining > 0 && filesInCurrentBatch > 0) {
                            triggerNextBatch(CAI.currentImportId);
                        }
                    }
                    
                    if (response.completed) {
                        completeImport(response);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.log('Poll error, will retry:', error);
            }
        });
    }
    
    // Trigger next batch when needed
    function triggerNextBatch(importId) {
        if (!importId || CAI.isPaused || CAI.batchInProgress) {
            return;
        }
        
        // Mark batch as in progress
        CAI.batchInProgress = true;
        CAI.currentBatchNumber++;
        
        console.log('Triggering batch #' + CAI.currentBatchNumber + ' for import:', importId);
        
        // Update UI to show batch processing
        updateBatchStatus('Processing batch ' + CAI.currentBatchNumber + '...');
        
        $.ajax({
            url: CAI.ajaxurl,
            type: 'POST',
            data: {
                action: 'cai_process_batch',
                nonce: CAI.nonce,
                import_id: importId,
                batch_size: CAI.batchSize
            },
            success: function(response) {
                console.log('Batch #' + CAI.currentBatchNumber + ' response:', response);
                
                if (response.success) {
                    // Update batch results if available
                    if (response.batch_results) {
                        var successful = response.batch_results.successful || 0;
                        var failed = response.batch_results.failed || 0;
                        var skipped = response.batch_results.skipped || 0;
                        var batchMessage = 'Batch ' + CAI.currentBatchNumber + ': ' + 
                                          successful + '✓, ' + failed + '✗, ' + skipped + '↻';
                        
                        updateBatchStatus(batchMessage);
                        
                        // Add to stats
                        CAI.importedStats.successful += successful;
                        CAI.importedStats.failed += failed;
                        CAI.importedStats.skipped += skipped;
                        updateStatsDisplay();
                        
                        if (failed > 0) {
                            showNotification(batchMessage, 'warning');
                        }
                    }
                    
                    // Update progress immediately from batch response
                    if (response.progress) {
                        updateProgressUI(
                            response.progress.percentage,
                            'Batch ' + CAI.currentBatchNumber + ' completed',
                            response.progress.processed,
                            response.progress.total
                        );
                    }
                    
                    // Check if import completed with this batch
                    if (response.completed) {
                        completeImport(response);
                    }
                } else {
                    // Handle limit reached during batch
                    if (response.limit_reached) {
                        showLimitReachedModal(response.message);
                        updateBatchStatus('Stopped: Monthly limit reached');
                        CAI.isImporting = false;
                        clearInterval(CAI.pollInterval);
                        return;
                    }
                    
                    showNotification('Batch ' + CAI.currentBatchNumber + ' failed: ' + response.message, 'error');
                    updateBatchStatus('Batch ' + CAI.currentBatchNumber + ' failed');
                }
                
                // Mark batch as completed (regardless of success)
                CAI.batchInProgress = false;
                
            },
            error: function(xhr, status, error) {
                console.error('Batch #' + CAI.currentBatchNumber + ' AJAX error:', error);
                showNotification('Batch ' + CAI.currentBatchNumber + ' error: ' + error, 'error');
                updateBatchStatus('Batch ' + CAI.currentBatchNumber + ' error');
                
                // Mark batch as completed even on error
                CAI.batchInProgress = false;
            }
        });
    }
    
    // Update progress UI
    function updateProgressUI(percentage, message, processed, total) {
        // Clamp percentage to 0-100
        percentage = Math.min(100, Math.max(0, percentage));
        
        // Update progress bar
        $('.cai-progress-fill').css('width', percentage + '%');
        $('#cai-progress-percentage').text(percentage + '%');
        
        // Update status message
        $('#cai-progress-details').text(message);
        
        // Update file counts if provided
        if (processed !== undefined && total !== undefined) {
            $('#cai-processed-files').text(processed);
            $('#cai-total-files').text(total);
        }
    }
    
    // Update stats display
    function updateStatsDisplay() {
        $('#cai-successful-count').text(CAI.importedStats.successful);
        $('#cai-failed-count').text(CAI.importedStats.failed);
        $('#cai-skipped-count').text(CAI.importedStats.skipped);
    }
    
    // Update batch status
    function updateBatchStatus(message) {
        $('#cai-batch-status').remove();
        
        var $batchStatus = $('<div id="cai-batch-status" class="cai-batch-status">' + message + '</div>');
        $('#cai-progress-container').append($batchStatus);
    }
    
    // Complete import process
    function completeImport(response) {
        clearInterval(CAI.pollInterval);
        CAI.isImporting = false;
        CAI.isPaused = false;
        CAI.batchInProgress = false;
        
        var successful = response.progress ? response.progress.successful : 0;
        var failed = response.progress ? response.progress.failed : 0;
        var skipped = response.progress ? response.progress.skipped : 0;
        
        updateProgressUI(100, 'Import completed!', 
                        response.progress ? response.progress.total : 0,
                        response.progress ? response.progress.total : 0);
        
        // Show comprehensive completion message
        var completionMessage = 'Import complete! ' + 
                               successful + ' successful, ' +
                               failed + ' failed, ' +
                               skipped + ' skipped.';
        
        showNotification(completionMessage, 'success');
        
        // Update usage stats after successful import
        CAI.usageStats.used += successful;
        CAI.usageStats.remaining = Math.max(0, CAI.usageStats.limit - CAI.usageStats.used);
        CAI.usageStats.percent = Math.min(100, (CAI.usageStats.used / CAI.usageStats.limit) * 100);
        updateUsageUI();
        updateImportButtonState();
        
        // Show success upsell if appropriate
        if (successful >= 5) {
            setTimeout(function() {
                CAI.notificationManager.show('success_upsell', {
                    count: successful,
                    upgrade_url: cai_ajax.upgrade_url
                });
            }, 2000);
        }
        
        // Update UI for completion
        $('#cai-progress-container').addClass('completed');
        $('#cai-pause-import, #cai-resume-import').hide();
        
        // Update batch status with timestamp
        updateBatchStatus('Import completed at ' + new Date().toLocaleTimeString());
        
        // Show view results button
        $('#cai-view-results').show();
        
        // Clear form and re-enable
        $('#cai-import-form')[0].reset();
        $('#cai-import-form').find('input, button, select').prop('disabled', false);
        $('#cai-import-form button[type="submit"]').text('Start Import');
        $('#cai-import-spinner').hide();
        
        // Update import button state
        updateImportButtonState();
        
        // Auto-refresh page after 8 seconds to show updated stats
        setTimeout(function() {
            window.location.reload();
        }, 8000);
    }
    
    // Pause import
    function pauseImport() {
        CAI.isPaused = true;
        clearInterval(CAI.pollInterval);
        showNotification('Import paused', 'warning');
        $('#cai-pause-import').hide();
        $('#cai-resume-import').show();
        updateBatchStatus('Import paused at ' + new Date().toLocaleTimeString());
    }
    
    // Resume import
    function resumeImport() {
        if (!CAI.currentImportId) {
            return;
        }
        
        CAI.isPaused = false;
        CAI.pollInterval = setInterval(pollImportStatus, CAI.pollFrequency);
        
        showNotification('Import resumed', 'info');
        $('#cai-resume-import').hide();
        $('#cai-pause-import').show();
        updateBatchStatus('Import resumed at ' + new Date().toLocaleTimeString());
    }
    
    // Cancel import
    function cancelImport() {
        if (confirm('Are you sure you want to cancel this import? Any partially imported files will remain in the Media Library.')) {
            clearInterval(CAI.pollInterval);
            CAI.isImporting = false;
            CAI.isPaused = false;
            CAI.batchInProgress = false;
            CAI.currentImportId = null;
            
            // Hide progress container
            $('#cai-progress-container').fadeOut(300);
            
            showNotification('Import cancelled', 'warning');
            
            // Enable import form
            $('#cai-import-form').find('input, button, select').prop('disabled', false);
            $('#cai-import-form button[type="submit"]').text('Start Import');
            $('#cai-import-spinner').hide();
            updateImportButtonState();
        }
    }
    
    // View results
    function viewResults() {
        // Redirect to Media Library
        window.location.href = '/wp-admin/upload.php';
    }
    
    // Show notification message
    function showNotification(message, type) {
        // Remove existing notifications
        $('.cai-notification').remove();
        
        var $notification = $(
            '<div class="cai-notification notice notice-' + type + ' is-dismissible">' +
            '<p>' + message + '</p>' +
            '<button type="button" class="notice-dismiss">' +
            '<span class="screen-reader-text">Dismiss this notice.</span>' +
            '</button>' +
            '</div>'
        );
        
        // Add to page
        $('.wrap h1').first().after($notification);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notification.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
        
        // Make dismissible
        $notification.on('click', '.notice-dismiss', function() {
            $(this).closest('.cai-notification').remove();
        });
    }
    
    // Check for existing imports on page load
    function checkExistingImports() {
        // Check if there's an import ID in the hidden field
        var importId = $('#cai-current-import-id').val();
        if (importId) {
            console.log('Found active import:', importId);
            startImportProgress(importId, 0);
        }
    }
    
    // Global function for partial import
    window.importPartial = function(count) {
        // This would be implemented with additional backend logic
        alert('Partial import of ' + count + ' images would start here.');
        $('.cai-modal').remove();
    };
    
    // Initialize when DOM is ready
    $(document).ready(init);
    
})(jQuery);