<?php

if (!function_exists('handle_api_response')) {
    function handle_api_response($response, $debug = false) {
        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if ($debug) {
            error_log('API Response: ' . $response_body);
            $response_data['debug'] = $response_body; // Přidání celé odpovědi pro debug
        }

        // Pokud API vrátí chybu, vrátíme ji
        if (isset($response_data['error'])) {
            return array('error' => $response_data['error']['message'], 'debug' => $response_body);
        }

        return $response_data;
    }
}

if (!function_exists('openai_create_thread')) {
    function openai_create_thread($api_key, $body, $debug = false) {
        $response = wp_remote_post('https://api.openai.com/v1/threads', array(
            'method'    => 'POST',
            'headers'   => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'OpenAI-Beta'   => 'assistants=v2'
            ),
            'body'      => $body
        ));

        $response_data = handle_api_response($response, $debug);

        // Ověření, zda je přítomné ID vlákna
        if (isset($response_data['id'])) {
            return array('success' => true, 'id' => $response_data['id'], 'data' => $response_data);
        }

        // Vrácení chyby včetně debug informací
        return array('error' => isset($response_data['error']) ? $response_data['error'] : 'Unknown error occurred during thread creation.', 'debug' => isset($response_data['debug']) ? $response_data['debug'] : null);
    }
}

if (!function_exists('openai_run_assistant')) {
    function openai_run_assistant($api_key, $thread_id, $body, $debug = false) {
        $response = wp_remote_post("https://api.openai.com/v1/threads/$thread_id/runs", array(
            'method'    => 'POST',
            'headers'   => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'OpenAI-Beta'   => 'assistants=v2'
            ),
            'body'      => $body
        ));
    
        $response_data = handle_api_response($response, $debug);
    
        // Verify if run_id is present
        if (isset($response_data['id'])) {
            return array('success' => true, 'id' => $response_data['id'], 'data' => $response_data);
        }
    
        // Return error including debug information
        return array('error' => isset($response_data['error']) ? $response_data['error'] : 'Unknown error occurred during assistant run.', 'debug' => isset($response_data['debug']) ? $response_data['debug'] : null);
    }
}

if (!function_exists('openai_check_run_status')) {
    function openai_check_run_status($api_key, $thread_id, $run_id, $debug = false) {
        // Log the API call details for debugging
        if ($debug) {
            error_log("Checking run status for thread_id: $thread_id, run_id: $run_id");
        }

        // Make the API request to check the run status
        $response = wp_remote_get("https://api.openai.com/v1/threads/$thread_id/runs/$run_id", array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'OpenAI-Beta'   => 'assistants=v2'
            )
        ));

        // Handle the API response
        $response_data = handle_api_response($response, $debug);

        // Check if the response contains a status
        if (isset($response_data['status'])) {
            return array('success' => true, 'data' => $response_data);
        }

        // Return error with detailed debug information
        return array('error' => isset($response_data['error']) ? $response_data['error'] : 'Unknown error occurred while checking run status.', 'debug' => isset($response_data['debug']) ? $response_data['debug'] : json_encode($response_data));
    }
}

if (!function_exists('openai_get_run_steps')) {
    function openai_get_run_steps($api_key, $thread_id, $run_id, $debug = false) {
        $response = wp_remote_get("https://api.openai.com/v1/threads/$thread_id/runs/$run_id/steps", array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'OpenAI-Beta'   => 'assistants=v2'
            )
        ));

        $response_data = handle_api_response($response, $debug);

        // Vrácení kroků z běhu
        if (isset($response_data['data'])) {
            return array('success' => true, 'data' => $response_data['data']);
        }

        // Vrácení chyby včetně debug informací
        return array('error' => isset($response_data['error']) ? $response_data['error'] : 'Unknown error occurred while retrieving run steps.', 'debug' => isset($response_data['debug']) ? $response_data['debug'] : null);
    }
}

if (!function_exists('openai_get_step_details')) {
    function openai_get_step_details($api_key, $thread_id, $run_id, $step_id, $debug = false) {
        $response = wp_remote_get("https://api.openai.com/v1/threads/$thread_id/runs/$run_id/steps/$step_id", array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'OpenAI-Beta'   => 'assistants=v2'
            )
        ));

        $response_data = handle_api_response($response, $debug);

        // Vrácení detailů kroku
        if (isset($response_data['data'])) {
            return array('success' => true, 'data' => $response_data['data']);
        }

        // Vrácení chyby včetně debug informací
        return array('error' => isset($response_data['error']) ? $response_data['error'] : 'Unknown error occurred while retrieving step details.', 'debug' => isset($response_data['debug']) ? $response_data['debug'] : null);
    }
}