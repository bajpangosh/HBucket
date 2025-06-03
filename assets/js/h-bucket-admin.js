jQuery(document).ready(function($) {
    // Test Connection Code (existing)
    $('#h_bucket_test_connection').on('click', function() {
        var $statusSpan = $('#h-bucket-test-connection-status');
        var $button = $(this);
        $statusSpan.text('Testing...').css('color', '');
        $button.prop('disabled', true);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'h_bucket_test_connection',
                nonce: $('#h_bucket_test_connection_nonce').val(),
            },
            success: function(response) {
                if (response.success) {
                    $statusSpan.text(response.data).css('color', 'green');
                } else {
                    $statusSpan.text(response.data).css('color', 'red');
                }
            },
            error: function(xhr, status, error) {
                $statusSpan.text('AJAX error: ' + error).css('color', 'red');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    // Migration Code
    var $migrationButton = $('#h-bucket-start-migration-button');
    var $progressBarDiv = $('#h-bucket-migration-progress-bar');
    var $progressBar = $progressBarDiv.find('div');
    var $statusLog = $('#h-bucket-migration-status-log');
    var totalItemsToMigrate = 0;
    var itemsMigratedSuccessfully = 0;
    var itemsFailedToMigrate = 0;
    var totalItemsProcessedThisRun = 0;

    $migrationButton.on('click', function() {
        if ( $(this).hasClass('disabled') ) {
            return;
        }
        $(this).addClass('disabled').text('Migration in Progress...');
        $progressBarDiv.show();
        $progressBar.css('width', '0%').text('0%');
        $statusLog.show().html(''); // Clear log
        itemsMigratedSuccessfully = 0;
        itemsFailedToMigrate = 0;
        totalItemsProcessedThisRun = 0;

        // First, get status (total items to migrate)
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'h_bucket_migration_status',
                nonce: $('#h_bucket_migration_nonce_field').val()
            },
            success: function(response) {
                if (response.success) {
                    totalItemsToMigrate = parseInt(response.data.total_items, 10);
                    if (totalItemsToMigrate > 0) {
                        logStatus('Starting migration for ' + totalItemsToMigrate + ' items.');
                        processMigrationBatch();
                    } else {
                        logStatus('No items found that need migration.');
                        resetMigrationUI(true, 'No items to migrate or already migrated.');
                    }
                } else {
                    logStatus('Error getting migration status: ' + response.data.message);
                    resetMigrationUI(false, 'Error starting migration.');
                }
            },
            error: function(xhr, status, error) {
                logStatus('AJAX Error getting status: ' + error);
                resetMigrationUI(false, 'Error starting migration.');
            }
        });
    });

    function processMigrationBatch() {
        if (totalItemsToMigrate === 0 || totalItemsProcessedThisRun >= totalItemsToMigrate) {
            logStatus('Current migration run complete. Processed: ' + totalItemsProcessedThisRun);
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: { action: 'h_bucket_migration_status', nonce: $('#h_bucket_migration_nonce_field').val()},
                success: function(response) {
                    if (response.success && parseInt(response.data.total_items, 10) === 0) {
                         resetMigrationUI(true, 'All items successfully migrated!');
                    } else {
                         resetMigrationUI(true, 'Migration run finished. Some items may still need processing or new items were added. Please refresh or check status.');
                    }
                }
            });
            return;
        }

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'h_bucket_migrate_batch',
                nonce: $('#h_bucket_migration_nonce_field').val(),
                batch_size: 10 // Or make this configurable in UI
            },
            success: function(response) {
                if (response.success) {
                    var batchData = response.data;
                    itemsMigratedSuccessfully += batchData.items_succeeded_in_batch;
                    itemsFailedToMigrate += batchData.items_failed_in_batch;
                    totalItemsProcessedThisRun += batchData.items_processed_in_batch;
                    
                    if (batchData.log_messages && batchData.log_messages.length > 0) {
                        batchData.log_messages.forEach(function(msg) {
                            logStatus(msg);
                        });
                    }

                    var percent = (totalItemsToMigrate > 0) ? ( (totalItemsProcessedThisRun / totalItemsToMigrate) * 100 ) : 0;
                    $progressBar.css('width', percent + '%').text(Math.round(percent) + '%');

                    if (batchData.items_processed_in_batch === 0 && batchData.remaining_to_process === 0) {
                        logStatus('All items have been processed.');
                        resetMigrationUI(true, 'Migration complete! Successfully migrated: ' + itemsMigratedSuccessfully + '. Failed: ' + itemsFailedToMigrate);
                    } else if (batchData.items_processed_in_batch === 0 && batchData.remaining_to_process > 0) {
                        logStatus('No items processed in this batch, but server indicates more items exist. May indicate an issue or unprocessable items.');
                        resetMigrationUI(false, 'Migration stalled or encountered unprocessable items.');
                    }
                    else {
                        processMigrationBatch();
                    }

                } else {
                    logStatus('Error processing batch: ' + response.data.message);
                    resetMigrationUI(false, 'Error during migration.');
                }
            },
            error: function(xhr, status, error) {
                logStatus('AJAX Error processing batch: ' + error);
                resetMigrationUI(false, 'Error during migration.');
            }
        });
    }

    function logStatus(message) {
        var currentdate = new Date();
        var timestamp = "[" + currentdate.getHours() + ":" + ("0" + currentdate.getMinutes()).slice(-2) + ":" + ("0" + currentdate.getSeconds()).slice(-2) + "] ";
        $statusLog.append('<div>' + timestamp + message + '</div>');
        $statusLog.scrollTop($statusLog[0].scrollHeight); // Scroll to bottom
    }

    function resetMigrationUI(isSuccess, finalMessage) {
        $migrationButton.removeClass('disabled').text('Start Bulk Migration');
        if (isSuccess) {
            $progressBar.css('width', '100%').text('100% - Done');
            logStatus('<strong>' + finalMessage + '</strong>');
        } else {
             logStatus('<strong>Migration stopped: ' + finalMessage + '</strong>');
        }
        // Refresh counts after migration attempt
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { action: 'h_bucket_migration_status', nonce: $('#h_bucket_migration_nonce_field').val()},
            success: function(response) {
                if (response.success) {
                    logStatus("Counts refreshed: Needs offload - " + response.data.total_items + ", Offloaded overall - " + response.data.offloaded_overall);
                    // TODO: Update the actual count numbers on the page if they have specific IDs
                    // e.g., $('#h-bucket-display-not-offloaded').text(response.data.total_items);
                    // For now, logging is sufficient, user might need to refresh for visual count update on settings page itself.
                }
            }
        });
    }
});
