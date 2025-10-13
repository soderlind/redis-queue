/**
 * Redis Queue Admin JavaScript
 * 
 * Handles all admin interface interactions including:
 * - Dashboard statistics and monitoring
 * - Job management (view, cancel, purge)
 * - Test job submission
 * - Redis connection testing
 * - Worker triggering
 * - Diagnostics and debugging
 * 
 * @package RedisQueue
 * @since 2.0.0
 */
(function($) {
    'use strict';

    /**
     * Main admin object containing all admin interface functionality.
     */
    var RedisQueueAdmin = {
        /**
         * Initialize the admin interface.
         * Sets up event bindings and initializes page-specific features.
         */
        init: function() {
            this.bindEvents();
            this.initDashboard();
            this.initTestForms();
            this.initSettings();
        },

        /**
         * Bind event handlers to UI elements.
         * Uses event delegation for dynamically added elements.
         */
        bindEvents: function() {
            // Dashboard events
            $(document).on('click', '#trigger-worker', this.triggerWorker);
            $(document).on('click', '#refresh-stats', this.refreshStats);
            $(document).on('click', '#run-diagnostics', this.runDiagnostics);
            $(document).on('click', '#debug-test', this.runDebugTest);
            $(document).on('click', '#reset-stuck-jobs', this.resetStuckJobs);
            
            // Purge events
            $(document).on('click', '.purge-buttons button', this.purgeJobs);
            
            // Job management events
            $(document).on('click', '.view-job', this.viewJob);
            $(document).on('click', '.cancel-job', this.cancelJob);
            
            // Queue management events
            $(document).on('click', '.clear-queue', this.clearQueue);
            
            // Settings events
            $(document).on('click', '#test-redis-connection', this.testConnection);
        },

        /**
         * Initialize dashboard functionality.
         * Sets up auto-refresh for job statistics.
         */
        initDashboard: function() {
            // Auto-refresh stats every 30 seconds if on dashboard
            if ($('#queued-jobs').length) {
                this.refreshStats();
                setInterval(this.refreshStats, 30000);
            }
        },

        /**
         * Initialize test job forms.
         * Binds submit handlers to prevent default form submission.
         */
        initTestForms: function() {
            // Initialize test forms
            $('.test-job-form').each(function() {
                var $form = $(this);
                $form.on('submit', function(e) {
                    e.preventDefault();
                    RedisQueueAdmin.submitTestJob.call(this, e);
                });
            });
        },

        /**
         * Initialize settings page features.
         * Auto-tests Redis connection on page load.
         */
        initSettings: function() {
            // Initialize settings page
            if ($('#test-redis-connection').length) {
                // Auto-test connection on page load
                this.testConnection();
            }
        },

        /**
         * Trigger the worker to process queued jobs.
         * Sends AJAX request to process jobs and displays results.
         * 
         * @param {Event} e Click event
         */
        triggerWorker: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var originalText = $button.text();
            
            // Disable button during processing
            $button.prop('disabled', true).text(redisQueueAdmin.strings.processing);
            
            $.ajax({
                url: redisQueueAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'redis_queue_trigger_worker',
                    nonce: redisQueueAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        RedisQueueAdmin.showNotice(redisQueueAdmin.strings.workerTriggered, 'success');
                        RedisQueueAdmin.refreshStats();
                        
                        // Show processing details in console
                        if (response.data) {
                            var details = 'Processed: ' + (response.data.processed || 0) + ' jobs\n' +
                                         'Success: ' + (response.data.successful || 0) + '\n' +
                                         'Failed: ' + (response.data.failed || 0);
                            console.log('Worker Results:', details);
                        }
                    } else {
                        RedisQueueAdmin.showNotice(response.data || redisQueueAdmin.strings.error, 'error');
                    }
                },
                error: function() {
                    RedisQueueAdmin.showNotice(redisQueueAdmin.strings.error, 'error');
                },
                complete: function() {
                    // Re-enable button
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Refresh dashboard statistics.
         * Updates job counts (queued, processing, completed, failed).
         * 
         * @param {Event} [e] Optional click event
         */
        refreshStats: function(e) {
            if (e) e.preventDefault();
            
            $.ajax({
                url: redisQueueAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'redis_queue_get_stats',
                    nonce: redisQueueAdmin.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        // Update job count displays
                        $('#queued-jobs').text(response.data.queued || 0);
                        $('#processing-jobs').text(response.data.processing || 0);
                        $('#completed-jobs').text(response.data.completed || 0);
                        $('#failed-jobs').text(response.data.failed || 0);
                    }
                },
                error: function() {
                    console.log('Failed to refresh stats');
                }
            });
        },

        /**
         * Run Redis diagnostics.
         * Tests connection, read/write operations, and displays Redis state.
         * 
         * @param {Event} e Click event
         */
        runDiagnostics: function(e) {
            if (e) e.preventDefault();
            
            var $button = $(this);
            var $result = $('#diagnostics-result');
            
            $button.prop('disabled', true).text('Running...');
            $result.html('<div class="notice notice-info"><p>Running diagnostics...</p></div>');
            
            $.ajax({
                url: redisQueueAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'redis_queue_diagnostics',
                    nonce: redisQueueAdmin.nonce
                },
                success: function(response) {
                    $button.prop('disabled', false).text('Run Diagnostics');
                    
                    if (response.success && response.data) {
                        var diagnostics = response.data;
                        var html = '<div class="notice notice-success"><h3>Diagnostic Results:</h3>';
                        html += '<ul>';
                        html += '<li><strong>Redis Connected:</strong> ' + (diagnostics.connected ? 'Yes' : 'No') + '</li>';
                        html += '<li><strong>Test Write:</strong> ' + (diagnostics.test_write ? 'Success' : 'Failed') + '</li>';
                        html += '<li><strong>Test Read:</strong> ' + (diagnostics.test_read ? 'Success' : 'Failed') + '</li>';
                        html += '<li><strong>Queue Prefix:</strong> ' + diagnostics.queue_prefix + '</li>';
                        html += '<li><strong>Redis Keys Found:</strong> ' + (diagnostics.redis_keys ? diagnostics.redis_keys.length : 0) + '</li>';
                        
                        // Display Redis keys if found
                        if (diagnostics.redis_keys && diagnostics.redis_keys.length > 0) {
                            html += '<li><strong>Keys:</strong> ' + diagnostics.redis_keys.join(', ') + '</li>';
                        }
                        
                        // Display any errors
                        if (diagnostics.error) {
                            html += '<li><strong>Error:</strong> ' + diagnostics.error + '</li>';
                        }
                        html += '</ul></div>';
                        $result.html(html);
                    } else {
                        $result.html('<div class="notice notice-error"><p>Diagnostics failed: ' + (response.data || 'Unknown error') + '</p></div>');
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text('Run Diagnostics');
                    $result.html('<div class="notice notice-error"><p>Failed to run diagnostics</p></div>');
                }
            });
        },

        /**
         * Run comprehensive debug test.
         * Performs detailed analysis of plugin configuration and state.
         * 
         * @param {Event} e Click event
         */
        runDebugTest: function(e) {
            if (e) e.preventDefault();
            
            var $button = $(this);
            var $result = $('#debug-test-result');
            
            $button.prop('disabled', true).text('Running Debug Test...');
            $result.html('<div class="notice notice-info"><p>Running comprehensive debug test...</p></div>');
            
            $.ajax({
                url: redisQueueAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'redis_queue_debug_test',
                    nonce: redisQueueAdmin.nonce
                },
                success: function(response) {
                    $button.prop('disabled', false).text('Full Debug Test');
                    
                    if (response.success && response.data) {
                        var debug = response.data;
                        var html = '<div class="notice notice-info"><h3>Full Debug Test Results:</h3>';
                        
                        // Display all debug sections dynamically
                        for (var section in debug) {
                            if (debug.hasOwnProperty(section)) {
                                html += '<h4>' + section + ':</h4><ul>';
                                var sectionData = debug[section];
                                
                                if (typeof sectionData === 'object' && sectionData !== null) {
                                    if (Array.isArray(sectionData)) {
                                        // Handle arrays (like Recent Jobs)
                                        for (var i = 0; i < sectionData.length; i++) {
                                            html += '<li>' + sectionData[i] + '</li>';
                                        }
                                    } else {
                                        // Handle objects
                                        for (var key in sectionData) {
                                            if (sectionData.hasOwnProperty(key)) {
                                                var value = sectionData[key];
                                                if (Array.isArray(value)) {
                                                    html += '<li><strong>' + key + ':</strong></li>';
                                                    html += '<ul>';
                                                    for (var j = 0; j < value.length; j++) {
                                                        html += '<li>' + value[j] + '</li>';
                                                    }
                                                    html += '</ul>';
                                                } else {
                                                    html += '<li><strong>' + key + ':</strong> ' + value + '</li>';
                                                }
                                            }
                                        }
                                    }
                                } else {
                                    html += '<li>' + sectionData + '</li>';
                                }
                                html += '</ul>';
                            }
                        }


                        
                        html += '</div>';
                        $result.html(html);
                    } else {
                        $result.html('<div class="notice notice-error"><p>Debug test failed: ' + (response.data || 'Unknown error') + '</p></div>');
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text('Full Debug Test');
                    $result.html('<div class="notice notice-error"><p>Failed to run debug test</p></div>');
                }
            });
        },

        /**
         * Reset stuck jobs.
         * Resets jobs that are stuck in "processing" state back to "queued".
         * 
         * @param {Event} e Click event
         */
        resetStuckJobs: function(e) {
            if (e) e.preventDefault();
            
            var $button = $(this);
            var $result = $('#reset-result');
            
            $button.prop('disabled', true).text('Resetting...');
            $result.html('<div class="notice notice-info"><p>Resetting stuck jobs...</p></div>');
            
            $.ajax({
                url: redisQueueAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'redis_queue_reset_stuck_jobs',
                    nonce: redisQueueAdmin.nonce
                },
                success: function(response) {
                    $button.prop('disabled', false).text('Reset Stuck Jobs');
                    
                    if (response.success && response.data) {
                        $result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                        // Refresh stats to show updated counts
                        if (typeof RedisQueueAdmin.refreshStats === 'function') {
                            RedisQueueAdmin.refreshStats();
                        }
                    } else {
                        $result.html('<div class="notice notice-error"><p>Failed to reset stuck jobs: ' + (response.data || 'Unknown error') + '</p></div>');
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text('Reset Stuck Jobs');
                    $result.html('<div class="notice notice-error"><p>Failed to reset stuck jobs</p></div>');
                }
            });
        },

        /**
         * Purge jobs from the database.
         * Supports purging completed, failed, older, or all jobs.
         * 
         * @param {Event} e Click event
         */
        purgeJobs: function(e) {
            e.preventDefault();
            var $button = $(this);
            var scope = $button.data('purge-scope');
            var $result = $('#purge-result');

            if (!scope) return;

            // Build appropriate confirmation message based on scope
            var confirmMsg = 'Are you sure you want to purge ' + scope + ' jobs?';
            if (scope === 'all') {
                confirmMsg = 'DANGER: This will delete ALL jobs. Continue?';
            } else if (scope === 'older') {
                confirmMsg = 'Purge jobs older than 7 days?';
            }

            if (!confirm(confirmMsg)) return;

            var originalText = $button.text();
            $button.prop('disabled', true).text(redisQueueAdmin.strings.processing);
            $result.html('<div class="notice notice-info"><p>Purging jobs...</p></div>');

            $.ajax({
                url: redisQueueAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'redis_queue_purge_jobs',
                    nonce: redisQueueAdmin.nonce,
                    scope: scope,
                    days: parseInt($('#purge-days').val(), 10) || 7
                },
                success: function(response) {
                    if (response.success && response.data) {
                        $result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                        // Refresh stats to reflect purged jobs
                        if (typeof RedisQueueAdmin.refreshStats === 'function') {
                            RedisQueueAdmin.refreshStats();
                        }
                    } else {
                        $result.html('<div class="notice notice-error"><p>Purge failed: ' + (response.data || 'Unknown error') + '</p></div>');
                    }
                },
                error: function() {
                    $result.html('<div class="notice notice-error"><p>Purge request failed.</p></div>');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * View detailed information about a specific job.
         * Opens a modal with job details loaded via REST API.
         * 
         * @param {Event} e Click event
         */
        viewJob: function(e) {
            e.preventDefault();
            
            var jobId = $(this).data('job-id');
            
            // Create and display modal
            var modal = $('<div class="redis-queue-modal">' +
                '<div class="modal-content">' +
                '<span class="close">&times;</span>' +
                '<h2>Job Details</h2>' +
                '<div class="job-details-loading">Loading...</div>' +
                '</div>' +
                '</div>');
            
            $('body').append(modal);
            modal.show();
            
            // Close modal on X button click
            modal.find('.close').on('click', function() {
                modal.remove();
            });
            
            // Close modal on background click
            $(window).on('click', function(event) {
                if (event.target === modal[0]) {
                    modal.remove();
                }
            });
            
            // Load job details via REST API
            $.ajax({
                url: redisQueueAdmin.restUrl + 'jobs/' + jobId,
                type: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', redisQueueAdmin.restNonce);
                },
                success: function(response) {
                    // Build job details table
                    var html = '<table class="job-details-table">';
                    html += '<tr><td><strong>ID:</strong></td><td>' + response.id + '</td></tr>';
                    html += '<tr><td><strong>Type:</strong></td><td>' + response.type + '</td></tr>';
                    html += '<tr><td><strong>Queue:</strong></td><td>' + response.queue + '</td></tr>';
                    html += '<tr><td><strong>Status:</strong></td><td><span class="status-badge status-' + response.status + '">' + response.status + '</span></td></tr>';
                    html += '<tr><td><strong>Priority:</strong></td><td>' + response.priority + '</td></tr>';
                    html += '<tr><td><strong>Attempts:</strong></td><td>' + response.attempts + '/' + response.max_attempts + '</td></tr>';
                    html += '<tr><td><strong>Created:</strong></td><td>' + response.created_at + '</td></tr>';
                    
                    // Show payload if present
                    if (response.payload) {
                        html += '<tr><td><strong>Payload:</strong></td><td><pre>' + JSON.stringify(response.payload, null, 2) + '</pre></td></tr>';
                    }
                    
                    // Show result if present
                    if (response.result) {
                        html += '<tr><td><strong>Result:</strong></td><td><pre>' + JSON.stringify(response.result, null, 2) + '</pre></td></tr>';
                    }
                    
                    // Show error if present
                    if (response.error_message) {
                        html += '<tr><td><strong>Error:</strong></td><td class="error-message">' + response.error_message + '</td></tr>';
                    }
                    
                    html += '</table>';
                    
                    modal.find('.job-details-loading').html(html);
                },
                error: function() {
                    modal.find('.job-details-loading').html('<p class="error">Failed to load job details.</p>');
                }
            });
        },

        /**
         * Cancel a job.
         * Removes the job from the queue via REST API.
         * 
         * @param {Event} e Click event
         */
        cancelJob: function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to cancel this job?')) {
                return;
            }
            
            var $link = $(this);
            var jobId = $link.data('job-id');
            var $row = $link.closest('tr');
            
            $.ajax({
                url: redisQueueAdmin.restUrl + 'jobs/' + jobId,
                type: 'DELETE',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', redisQueueAdmin.restNonce);
                },
                success: function(response) {
                    if (response.success) {
                        // Update UI to reflect cancelled status
                        $row.find('.status-badge').removeClass().addClass('status-badge status-cancelled').text('Cancelled');
                        $link.remove();
                        RedisQueueAdmin.showNotice('Job cancelled successfully', 'success');
                    } else {
                        RedisQueueAdmin.showNotice(response.message || 'Failed to cancel job', 'error');
                    }
                },
                error: function() {
                    RedisQueueAdmin.showNotice('Failed to cancel job', 'error');
                }
            });
        },

        /**
         * Clear all jobs from a queue.
         * Removes all jobs from the specified queue (default: 'default').
         * 
         * @param {Event} e Click event
         */
        clearQueue: function(e) {
            e.preventDefault();
            
            var queueName = $(this).data('queue') || 'default';
            
            if (!confirm(redisQueueAdmin.strings.confirmClear)) {
                return;
            }
            
            var $button = $(this);
            var originalText = $button.text();
            
            $button.prop('disabled', true).text(redisQueueAdmin.strings.processing);
            
            $.ajax({
                url: redisQueueAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'redis_queue_clear_queue',
                    queue: queueName,
                    nonce: redisQueueAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        RedisQueueAdmin.showNotice(redisQueueAdmin.strings.queueCleared, 'success');
                        RedisQueueAdmin.refreshStats();
                        location.reload(); // Refresh to show empty queue
                    } else {
                        RedisQueueAdmin.showNotice(response.data || redisQueueAdmin.strings.error, 'error');
                    }
                },
                error: function() {
                    RedisQueueAdmin.showNotice(redisQueueAdmin.strings.error, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Submit a test job.
         * Creates a test job via REST API with form data.
         * Implements fade animation for user feedback.
         * 
         * @param {Event} e Submit event
         */
        submitTestJob: function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $submitButton = $form.find('button[type="submit"]');
            var originalText = $submitButton.text();
            var startTime = Date.now();
            var MIN_PROCESSING_MS = 700; // Ensure user perceives processing state
            
            // Prevent double submission
            if ($submitButton.prop('disabled')) {
                console.log('Form submission already in progress, ignoring duplicate submit');
                return;
            }
            
            // Update button state with fade animation
            $submitButton.prop('disabled', true).addClass('loading').text(redisQueueAdmin.strings.processing);
            // Provide immediate feedback
            RedisQueueAdmin.showTestResult('Submitting job request...', 'info');
            
            // Collect form data
            var formData = {};
            $form.find('input, select, textarea').each(function() {
                var $field = $(this);
                var name = $field.attr('name');
                if (name) {
                    formData[name] = $field.val();
                }
            });
            
            // Determine job type based on form ID
            var jobType = 'email';
            if ($form.attr('id') === 'test-image-job') {
                jobType = 'image_processing';
            } else if ($form.attr('id') === 'test-api-job') {
                jobType = 'api_sync';
            }
            
            // Log submission details for debugging
            console.log('Submitting job:', {
                type: jobType,
                payload: formData,
                url: redisQueueAdmin.restUrl + 'jobs',
                nonce: redisQueueAdmin.restNonce
            });

            // Prepare request data
            var requestData = {
                type: jobType,
                payload: formData,
                priority: 10,
                queue: 'default'
            };

            // Submit job via REST API
            $.ajax({
                url: redisQueueAdmin.restUrl + 'jobs',
                type: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', redisQueueAdmin.restNonce);
                },
                data: requestData,
                success: function(response) {
                    console.log('Job creation success:', response);
                    if (response.success) {
                        RedisQueueAdmin.showTestResult('Job created successfully with ID: ' + response.job_id, 'success');
                        RedisQueueAdmin.refreshStats();
                    } else {
                        RedisQueueAdmin.showTestResult('Failed to create job: ' + (response.message || 'Unknown error'), 'error');
                    }
                },
                error: function(xhr, textStatus, errorThrown) {
                    console.log('REST API job creation failed, trying admin-ajax fallback:', {
                        status: xhr.status,
                        statusText: xhr.statusText,
                        textStatus: textStatus,
                        errorThrown: errorThrown,
                        responseText: xhr.responseText,
                        responseJSON: xhr.responseJSON
                    });
                    
                    // Fallback to admin-ajax.php
                    RedisQueueAdmin.createJobViaAjax(jobType, formData, $submitButton, originalText);
                },
                complete: function() {
                    console.log('Job creation request completed');
                    var elapsed = Date.now() - startTime;
                    var remaining = MIN_PROCESSING_MS - elapsed;
                    if (remaining > 0) {
                        setTimeout(function() {
                            $submitButton.prop('disabled', false).removeClass('loading').text(originalText);
                        }, remaining);
                    } else {
                        $submitButton.prop('disabled', false).removeClass('loading').text(originalText);
                    }
                }
            });
        },

        /**
         * Test Redis connection.
         * Verifies Redis connectivity via the health endpoint.
         * 
         * @param {Event} [e] Optional click event
         */
        testConnection: function(e) {
            if (e) e.preventDefault();
            
            var $button = $(this);
            var $result = $('#connection-test-result');
            
            if ($button.length) {
                $button.prop('disabled', true).text('Testing...');
            }

            // Test REST API accessibility and Redis connection
            console.log('Testing REST API accessibility:', redisQueueAdmin.restUrl);
            
            $.ajax({
                url: redisQueueAdmin.restUrl + 'health',
                type: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', redisQueueAdmin.restNonce);
                },
                success: function(response) {
                    if (response.success && response.data.redis_connected) {
                        // Connection successful - show Redis info
                        $result.removeClass('error').addClass('success')
                               .html('<strong>Connection successful!</strong><br>' +
                                    'Redis Version: ' + (response.data.redis_info.redis_version || 'Unknown') + '<br>' +
                                    'Memory Usage: ' + (response.data.redis_info.used_memory || 'Unknown'));
                    } else {
                        // Connection failed
                        $result.removeClass('success').addClass('error')
                               .html('<strong>Connection failed!</strong><br>Please check your Redis settings.');
                    }
                },
                error: function() {
                    // Unable to reach server
                    $result.removeClass('success').addClass('error')
                           .html('<strong>Connection test failed!</strong><br>Unable to reach the server.');
                },
                complete: function() {
                    if ($button.length) {
                        $button.prop('disabled', false).text('Test Redis Connection');
                    }
                }
            });
        },

        /**
         * Create job via admin-ajax fallback.
         * Used when REST API creation fails.
         * 
         * @param {string} jobType Job type (email, image_processing, api_sync)
         * @param {Object} formData Form data payload
         * @param {jQuery} $submitButton Submit button element
         * @param {string} originalText Original button text
         */
        createJobViaAjax: function(jobType, formData, $submitButton, originalText) {
            console.log('Creating job via admin-ajax fallback');
            
            $.ajax({
                url: redisQueueAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'redis_queue_create_test_job',
                    nonce: redisQueueAdmin.nonce,
                    job_type: jobType,
                    payload: formData
                },
                success: function(response) {
                    console.log('Admin-ajax job creation success:', response);
                    if (response.success) {
                        RedisQueueAdmin.showTestResult('Job created successfully with ID: ' + response.data.job_id, 'success');
                        RedisQueueAdmin.refreshStats();
                    } else {
                        RedisQueueAdmin.showTestResult('Failed to create job: ' + (response.data || 'Unknown error'), 'error');
                    }
                },
                error: function(xhr, textStatus, errorThrown) {
                    console.log('Admin-ajax job creation also failed:', {
                        status: xhr.status,
                        textStatus: textStatus,
                        errorThrown: errorThrown,
                        responseText: xhr.responseText
                    });
                    RedisQueueAdmin.showTestResult('Failed to create job via both REST API and admin-ajax', 'error');
                },
                complete: function() {
                    console.log('Admin-ajax job creation request completed');
                    $submitButton.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Show admin notice.
         * Displays a WordPress-style notice message that auto-dismisses.
         * 
         * @param {string} message Notice message
         * @param {string} [type='info'] Notice type: info, success, warning, error
         */
        showNotice: function(message, type) {
            type = type || 'info';
            
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            
            // Insert notice after h1 or at body start
            if ($('.wrap h1').length) {
                $('.wrap h1').after($notice);
            } else {
                $('body').prepend($notice);
            }
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $notice.remove();
                });
            }, 5000);
        },

        /**
         * Show test result message.
         * Appends a timestamped message to the test results output area.
         * 
         * @param {string} message Result message
         * @param {string} type Message type: success, info, error
         */
        showTestResult: function(message, type) {
            var $results = $('#test-results');
            var $output = $('#test-output');
            
            var timestamp = new Date().toLocaleTimeString();
            var resultClass = (type === 'success') ? 'success' : (type === 'info' ? 'info' : 'error');
            
            var resultHtml = '[' + timestamp + '] ' + message + '\n';
            
            $output.append(resultHtml);
            $results.show();
            
            // Auto-scroll to bottom
            $output.scrollTop($output[0].scrollHeight);
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        RedisQueueAdmin.init();
    });

    // Add modal CSS dynamically
    var modalCSS = `
        .redis-queue-modal {
            display: none;
            position: fixed;
            z-index: 100000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .redis-queue-modal .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: none;
            width: 80%;
            max-width: 800px;
            border-radius: 4px;
            position: relative;
        }
        
        .redis-queue-modal .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 10px;
            right: 15px;
            cursor: pointer;
        }
        
        .redis-queue-modal .close:hover,
        .redis-queue-modal .close:focus {
            color: black;
        }
        
        .job-details-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .job-details-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        
        .job-details-table td:first-child {
            width: 120px;
            background-color: #f9f9f9;
        }
        
        .job-details-table pre {
            background: #f5f5f5;
            padding: 10px;
            border-radius: 3px;
            font-size: 12px;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .error-message {
            color: #dc3232;
            font-family: monospace;
        }
    `;
    
    $('<style>').text(modalCSS).appendTo('head');

})(jQuery);