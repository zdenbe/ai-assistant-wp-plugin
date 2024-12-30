jQuery(document).ready(function($) {
    var current_nonce = ekortn_ajax.nonce; // Store the current nonce

    // Function to send a message
    $('#chat-form').on('submit', function(e) {
        e.preventDefault();
        var message = $('#chat-message').val().trim(); // Trim whitespace

        // Log the message before sending
        console.log('Sending message:', message);

        if (!message) {
            console.log('Message is empty!'); // Log for empty message
            $('#chat-messages').append('<div class="message error">Please enter a message.</div>');
            return;
        }

        // Send the message via AJAX
        console.log('Sending message via AJAX:', message);

        $.ajax({
            url: ekortn_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'ekortn_process_chat_message',
                message: message,
                nonce: current_nonce // Use current nonce
            },
            success: function(response) {
                console.log('Server response:', response); // Log the server response

                if (response.success) {
                    $('#chat-messages').append('<div class="message user">' + message + '</div>');
                    $('#chat-messages').append('<div class="message assistant">Waiting for assistant response...</div>');

                    // Display debug info if available
                    if (response.data.debug) {
                        $('#chat-messages').append('<div class="message debug">Debug Info: ' + response.data.debug + '</div>');
                    }

                    // Update nonce and start checking the status
                    if (response.new_nonce) {
                        current_nonce = response.new_nonce;
                    }
                    // Ensure the correct IDs are passed to the status check
                    checkQueryStatus(response.data.thread_id, response.data.run_id);
                } else {
                    console.log('Backend error:', response); // Log backend error response
                    $('#chat-messages').append('<div class="message error">Error: ' + (response.data.message || 'Unknown error') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                console.log('AJAX Error:', error); // Log AJAX error
                $('#chat-messages').append('<div class="message error">AJAX Error: ' + error + '</div>');
            }
        });

        // Clear input field after sending
        $('#chat-message').val('');
    });

    // Function to check the status of the assistant's response
    function checkQueryStatus(thread_id, run_id) {
        console.log('Checking status for thread:', thread_id, 'and run:', run_id);
        $.ajax({
            url: ekortn_ajax.ajax_url,
            method: 'POST', // Use POST instead of GET
            data: {
                action: 'check_query_status',
                thread_id: thread_id,
                run_id: run_id, // Ensure run_id is passed
                nonce: current_nonce // Use current nonce
            },
            success: function(response) {
                console.log('Server response (status check):', response);

                if (response.success) {
                    if (response.data.status === 'completed') {
                        $('.waiting').remove(); // Remove the "Waiting for assistant response" message
                        $('#chat-messages').append('<div class="message assistant">' + response.data.response + '</div>');

                        // Display debug info if available
                        if (response.data.debug) {
                            $('#chat-messages').append('<div class="message debug">Debug Info: ' + response.data.debug + '</div>');
                        }
                    } else {
                        // If the response is not yet complete, continue checking every 3 seconds
                        setTimeout(function() {
                            checkQueryStatus(thread_id, run_id);
                        }, 3000);
                    }
                } else {
                    console.log('Backend error (status check):', response); // Log backend error during status check
                    $('#chat-messages').append('<div class="message error">Error: ' + (response.data.message || 'Unknown error') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                console.log('AJAX Error during status check:', error); // Log AJAX error during status check
                $('#chat-messages').append('<div class="message error">AJAX Error: ' + error + '</div>');
            }
        });
    }

    // Display debug info if enabled
    function displayDebugInfo(response) {
        if (response.debug) {
            $('#chat-messages').append('<div class="message debug">Debug Info: ' + response.debug + '</div>');
        }
    }
});