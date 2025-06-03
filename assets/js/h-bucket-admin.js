jQuery(document).ready(function($) {
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
});
