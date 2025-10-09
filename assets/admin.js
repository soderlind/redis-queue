/* Redis Queue Demo Admin JavaScript */
(function($) {
    'use strict';

    // Main admin object
    var RedisQueueAdmin = {
        init: function() {
            this.bindEvents();
            this.initDashboard();
            this.initTestForms();
            this.initSettings();
        },

        bindEvents: function() {
            // Dashboard events
            $(document).on('click', '#trigger-worker', this.triggerWorker);
            $(document).on('click', '#refresh-stats', this.refreshStats);
            $(document).on('click', '#run-diagnostics', this.runDiagnostics);
            $(document).on('click', '#debug-test', this.runDebugTest);
            $(document).on('click', '#reset-stuck-jobs', this.resetStuckJobs);
            
            // Job management events
            $(document).on('click', '.view-job', this.viewJob);
            $(document).on('click', '.cancel-job', this.cancelJob);
            
            // Queue management events
            $(document).on('click', '.clear-queue', this.clearQueue);
            
            // Settings events
            $(document).on('click', '#test-redis-connection', this.testConnection);
        },

        initDashboard: function() {
            // Auto-refresh stats every 30 seconds if on dashboard
            if ($('#queued-jobs').length) {
                this.refreshStats();
                setInterval(this.refreshStats, 30000);
            }
        },

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

        initSettings: function() {
            // Initialize settings page
            if ($('#test-redis-connection').length) {
                // Auto-test connection on page load
                this.testConnection();
            }
        },

        triggerWorker: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var originalText = $button.text();
            
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
                        
                        // Show processing details
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
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

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
                        if (diagnostics.redis_keys && diagnostics.redis_keys.length > 0) {
                            html += '<li><strong>Keys:</strong> ' + diagnostics.redis_keys.join(', ') + '</li>';
                        }
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
                        
                        // Plugin Init
                        html += '<h4>1. Plugin Initialization:</h4><ul>';
                        html += '<li>Queue Manager: ' + (debug.plugin_init.queue_manager ? 'OK' : 'FAILED') + '</li>';
                        html += '<li>Job Processor: ' + (debug.plugin_init.job_processor ? 'OK' : 'FAILED') + '</li>';
                        html += '</ul>';
                        
                        // Redis Connection
                        if (debug.redis_connection) {
                            html += '<h4>2. Redis Connection:</h4><ul>';
                            html += '<li>Connected: ' + (debug.redis_connection.connected ? 'YES' : 'NO') + '</li>';
                            html += '</ul>';
                        }
                        
                        // Redis Diagnostics
                        if (debug.redis_diagnostics) {
                            var diag = debug.redis_diagnostics;
                            html += '<h4>3. Redis Diagnostics:</h4><ul>';
                            html += '<li>Test Write: ' + (diag.test_write ? 'OK' : 'FAILED') + '</li>';
                            html += '<li>Test Read: ' + (diag.test_read ? 'OK' : 'FAILED') + '</li>';
                            html += '<li>Queue Prefix: ' + diag.queue_prefix + '</li>';
                            html += '<li>Redis Keys Found: ' + (diag.redis_keys ? diag.redis_keys.length : 0) + '</li>';
                            if (diag.redis_keys && diag.redis_keys.length > 0) {
                                html += '<li>Keys: ' + diag.redis_keys.join(', ') + '</li>';
                            }
                            if (diag.error) {
                                html += '<li><strong>Error:</strong> ' + diag.error + '</li>';
                            }
                            html += '</ul>';
                        }
                        
                        // Job Creation Test
                        if (debug.job_creation) {
                            var job = debug.job_creation;
                            html += '<h4>4. Job Creation Test:</h4><ul>';
                            if (job.created) {
                                html += '<li>Job Created: YES (ID: ' + job.job_id + ')</li>';
                                html += '<li>Redis Keys After Creation: ' + (job.redis_keys_after ? job.redis_keys_after.length : 0) + '</li>';
                                if (job.redis_keys_after && job.redis_keys_after.length > 0) {
                                    html += '<li>Keys: ' + job.redis_keys_after.join(', ') + '</li>';
                                }
                                html += '<li>Job Dequeued: ' + (job.dequeued ? 'YES' : 'NO') + '</li>';
                                if (job.dequeued) {
                                    html += '<li>Dequeued Job ID: ' + job.dequeued_job_id + '</li>';
                                    html += '<li>Dequeued Job Type: ' + job.dequeued_type + '</li>';
                                    html += '<li>Payload Keys: ' + Object.keys(job.dequeued_payload || {}).join(', ') + '</li>';
                                } else if (job.dequeue_error) {
                                    html += '<li><strong>Dequeue Error:</strong> ' + job.dequeue_error + '</li>';
                                }
                            } else {
                                html += '<li>Job Created: NO</li>';
                                if (job.error) {
                                    html += '<li><strong>Error:</strong> ' + job.error + '</li>';
                                }
                            }
                            if (job.exception) {
                                html += '<li><strong>Exception:</strong> ' + job.exception + '</li>';
                            }
                            html += '</ul>';
                        }
                        
                        // Database Check
                        if (debug.database) {
                            var db = debug.database;
                            html += '<h4>5. Database Check:</h4><ul>';
                            html += '<li>Table Exists: ' + (db.table_exists ? 'YES' : 'NO') + '</li>';
                            html += '<li>Job Count: ' + db.job_count + '</li>';
                            if (db.recent_jobs && db.recent_jobs.length > 0) {
                                html += '<li>Recent Jobs:<ul>';
                                db.recent_jobs.forEach(function(job) {
                                    html += '<li>' + job.job_id + ' (' + job.job_type + ') - ' + job.status + ' - ' + job.created_at + '</li>';
                                });
                                html += '</ul></li>';
                            }
                            html += '</ul>';
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
                        // Refresh stats if available
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

        viewJob: function(e) {
            e.preventDefault();
            
            var jobId = $(this).data('job-id');
            
            // Create modal or use WordPress media modal
            var modal = $('<div class="redis-queue-modal">' +
                '<div class="modal-content">' +
                '<span class="close">&times;</span>' +
                '<h2>Job Details</h2>' +
                '<div class="job-details-loading">Loading...</div>' +
                '</div>' +
                '</div>');
            
            $('body').append(modal);
            modal.show();
            
            // Close modal events
            modal.find('.close').on('click', function() {
                modal.remove();
            });
            
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
                    var html = '<table class="job-details-table">';
                    html += '<tr><td><strong>ID:</strong></td><td>' + response.id + '</td></tr>';
                    html += '<tr><td><strong>Type:</strong></td><td>' + response.type + '</td></tr>';
                    html += '<tr><td><strong>Queue:</strong></td><td>' + response.queue + '</td></tr>';
                    html += '<tr><td><strong>Status:</strong></td><td><span class="status-badge status-' + response.status + '">' + response.status + '</span></td></tr>';
                    html += '<tr><td><strong>Priority:</strong></td><td>' + response.priority + '</td></tr>';
                    html += '<tr><td><strong>Attempts:</strong></td><td>' + response.attempts + '/' + response.max_attempts + '</td></tr>';
                    html += '<tr><td><strong>Created:</strong></td><td>' + response.created_at + '</td></tr>';
                    
                    if (response.payload) {
                        html += '<tr><td><strong>Payload:</strong></td><td><pre>' + JSON.stringify(response.payload, null, 2) + '</pre></td></tr>';
                    }
                    
                    if (response.result) {
                        html += '<tr><td><strong>Result:</strong></td><td><pre>' + JSON.stringify(response.result, null, 2) + '</pre></td></tr>';
                    }
                    
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
                        location.reload(); // Refresh the jobs list
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

        submitTestJob: function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $submitButton = $form.find('button[type="submit"]');
            var originalText = $submitButton.text();
            
            // Prevent double submission
            if ($submitButton.prop('disabled')) {
                console.log('Form submission already in progress, ignoring duplicate submit');
                return;
            }
            
            $submitButton.prop('disabled', true).text(redisQueueAdmin.strings.processing);
            
            // Get form data
            var formData = {};
            $form.find('input, select, textarea').each(function() {
                var $field = $(this);
                var name = $field.attr('name');
                if (name) {
                    formData[name] = $field.val();
                }
            });
            
            // Determine job type based on form
            var jobType = 'email';
            if ($form.attr('id') === 'test-image-job') {
                jobType = 'image_processing';
            } else if ($form.attr('id') === 'test-api-job') {
                jobType = 'api_sync';
            }
            
            // Create job via REST API
            console.log('Submitting job:', {
                type: jobType,
                payload: formData,
                url: redisQueueAdmin.restUrl + 'jobs',
                nonce: redisQueueAdmin.restNonce
            });

            // Use form data instead of JSON to avoid potential content-type issues
            var requestData = {
                type: jobType,
                payload: formData,
                priority: 10,
                queue: 'default'
            };

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
                    $submitButton.prop('disabled', false).text(originalText);
                }
            });
        },

        testConnection: function(e) {
            if (e) e.preventDefault();
            
            var $button = $(this);
            var $result = $('#connection-test-result');
            
            if ($button.length) {
                $button.prop('disabled', true).text('Testing...');
            }

            // First test if REST API is accessible
            console.log('Testing REST API accessibility:', redisQueueAdmin.restUrl);
            
            // Test Redis connection
            $.ajax({
                url: redisQueueAdmin.restUrl + 'health',
                type: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', redisQueueAdmin.restNonce);
                },
                success: function(response) {
                    if (response.success && response.data.redis_connected) {
                        $result.removeClass('error').addClass('success')
                               .html('<strong>Connection successful!</strong><br>' +
                                    'Redis Version: ' + (response.data.redis_info.redis_version || 'Unknown') + '<br>' +
                                    'Memory Usage: ' + (response.data.redis_info.used_memory || 'Unknown'));
                    } else {
                        $result.removeClass('success').addClass('error')
                               .html('<strong>Connection failed!</strong><br>Please check your Redis settings.');
                    }
                },
                error: function() {
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

        showNotice: function(message, type) {
            type = type || 'info';
            
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            
            // Insert after h1 if on admin page
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

        showTestResult: function(message, type) {
            var $results = $('#test-results');
            var $output = $('#test-output');
            
            var timestamp = new Date().toLocaleTimeString();
            var resultClass = type === 'success' ? 'success' : 'error';
            
            var resultHtml = '[' + timestamp + '] ' + message + '\n';
            
            $output.append(resultHtml);
            $results.show();
            
            // Scroll to bottom
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