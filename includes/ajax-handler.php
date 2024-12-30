<?php
// Zpracování AJAX požadavků

add_action('wp_ajax_ekortn_process_chat_message', 'ekortn_process_chat_message');
add_action('wp_ajax_nopriv_ekortn_process_chat_message', 'ekortn_process_chat_message');
add_action('wp_ajax_check_query_status', 'ekortn_check_query_status');
add_action('wp_ajax_nopriv_check_query_status', 'ekortn_check_query_status');

function ekortn_process_chat_message() {
    $debug = get_option('ekortn_debug', false);
    $log_output = $debug ? 'both' : 'file';

    // Logování přijatých dat z frontendu
    ekortn_log('Received AJAX data: ' . json_encode($_POST), 'info', $log_output);

    // Kontrola nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ekortn_frontend_nonce')) {
        $nonce_error_log = ekortn_log('Invalid nonce received: ' . (isset($_POST['nonce']) ? $_POST['nonce'] : 'none'), 'error', $log_output);
        wp_send_json_error(array('message' => 'Invalid nonce.', 'debug' => $nonce_error_log));
        return;
    }

    // Kontrola, zda není zpráva prázdná
    $message = sanitize_text_field($_POST['message']);
    if (empty($message)) {
        $empty_message_log = ekortn_log('Empty message received.', 'error', $log_output);
        wp_send_json_error(array('message' => 'Zpráva nemůže být prázdná.', 'debug' => $empty_message_log));
        return;
    }

    // Logování validní zprávy
    ekortn_log('Valid message received: ' . $message, 'info', $log_output);

    // Oříznutí zprávy na maximální počet znaků z nastavení pluginu
    $max_length = get_option('ekortn_max_message_length', 2000);
    if (strlen($message) > $max_length) {
        $message = substr($message, 0, $max_length);
        ekortn_log('Message truncated to: ' . $message, 'info', $log_output);
    }

    $api_key = get_option('ekortn_openai_api_key');
    $body = json_encode(array(
        'messages' => array(
            array('role' => 'user', 'content' => $message)
        )
    ));

    // Debugging - Logování požadavku API
    if ($debug) {
        ekortn_log_request($body, $log_output);
    }

    // Volání funkce pro vytvoření vlákna
    $response = openai_create_thread($api_key, $body, $debug);

    // Kontrola odpovědi z API
    if (isset($response['id'])) {
        $response_data = array('response' => $response['data'], 'thread_id' => $response['id']);

        // Přidání debug informací do odpovědi, pokud jsou k dispozici
        if ($debug && isset($response['debug'])) {
            $response_data['debug'] = $response['debug'];
            ekortn_log('API Debug Info: ' . json_encode($response['debug']), 'debug', $log_output);
        }

        // Vytvoření nové nonce pro následné dotazy
        $new_nonce = wp_create_nonce('ekortn_frontend_nonce');
        $response_data['new_nonce'] = $new_nonce;

        wp_send_json_success($response_data);
    } else {
        wp_send_json_error($response['error']);
        if ($debug) {
            ekortn_log('API Error: ' . $response['error'], 'error', $log_output);
        }
    }
}

function ekortn_check_query_status() {
    $debug = get_option('ekortn_debug', false);
    $log_output = $debug ? 'both' : 'file';

    // Log received data from frontend
    ekortn_log('Received AJAX data: ' . json_encode($_POST), 'info', $log_output);

    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ekortn_frontend_nonce')) {
        $nonce_error_log = ekortn_log('Invalid nonce received: ' . (isset($_POST['nonce']) ? $_POST['nonce'] : 'none'), 'error', $log_output);
        wp_send_json_error(array('message' => 'Invalid nonce.', 'debug' => $nonce_error_log));
        return;
    }

    // Check if thread_id and run_id are present
    if (!isset($_POST['thread_id']) || !isset($_POST['run_id'])) {
        ekortn_log('Missing thread_id or run_id in the request.', 'error', $log_output);
        wp_send_json_error(array('message' => 'Thread ID or Run ID is missing.', 'debug' => 'Missing thread_id or run_id in the request.'));
        return;
    }

    $thread_id = sanitize_text_field($_POST['thread_id']);
    $run_id = sanitize_text_field($_POST['run_id']);

    // Call the function to check the status of the run
    $status = openai_check_run_status($thread_id, $run_id, $debug);

    // Process API response
    if (isset($status['error'])) {
        wp_send_json_error($status['error']);
        if ($debug) {
            ekortn_log('API Status Check Error: ' . $status['error'], 'error', $log_output);
        }
    } else {
        $response_data = array('status' => $status['data']);

        // Add debug info to response if available
        if ($debug && isset($status['debug'])) {
            $response_data['debug'] = $status['debug'];
            ekortn_log('API Status Check Debug Info: ' . json_encode($status['debug']), 'debug', $log_output);
        }

        wp_send_json_success($response_data);
    }
}