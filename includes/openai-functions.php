<?php
function openai_create_thread_and_run($api_key, $assistant_id, $messages, $debug = false) {
    $url = "https://api.openai.com/v1/threads/runs";
    $headers = [
        "Authorization: Bearer $api_key",
        "Content-Type: application/json",
        "OpenAI-Beta: assistants=v2"
    ];

    $body = [
        "assistant_id" => $assistant_id,
        "thread" => [
            "messages" => $messages
        ]
    ];

    if ($debug) {
        error_log("Request Body: " . json_encode($body));
    }

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true
    ];

    $ch = curl_init();
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($debug) {
        error_log("Response: " . $response);
    }

    if ($http_code !== 200) {
        return [
            "error" => "HTTP Error $http_code",
            "details" => $response
        ];
    }

    $decoded_response = json_decode($response, true);

    if (isset($decoded_response['error'])) {
        return [
            "error" => $decoded_response['error']['message'],
            "details" => $decoded_response
        ];
    }

    return $decoded_response;
}


if (!function_exists('handle_api_response')) {
    function handle_api_response($response, $debug = false) {
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }
    
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
    
        if ($debug) {
            ekortn_log('API Response: ' . $body, 'info', 'file');
        }
    
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Invalid JSON response from API', 'debug' => $body];
        }
    
        return $data;
    }
}

if (!function_exists('openai_create_threadX')) {
    function openai_create_threadX($api_key, $body, $debug = false) {
        //$response = wp_remote_post('https://api.openai.com/v1/threads/', array(
        $response = wp_remote_post('https://api.openai.com/v1/threads/runs', array(
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



function openai_create_thread($api_key, $body, $debug = false) {
    $url = 'https://api.openai.com/v1/threads/runs';

    // Logování těla požadavku
    if ($debug) {
        ekortn_log_request("Request Body (pre-send): " . $body, 'info');
    }

    // Zajištění, že tělo je JSON řetězec
    if (!is_string($body)) {
        $body = json_encode($body, JSON_UNESCAPED_UNICODE);
    }

    $response = wp_remote_post($url, array(
        'method'    => 'POST',
        'headers'   => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
            'OpenAI-Beta'   => 'assistants=v2'
        ),
        'body'      => $body
    ));

    // Logování odpovědi
    if ($debug) {
        $response_body = wp_remote_retrieve_body($response);
        ekortn_log_request("Response Body: " . $response_body, 'info');
    }

    $response_data = handle_api_response($response, $debug);

    // Ověření, zda je přítomné ID vlákna
    if (isset($response_data['id'])) {
        return array('success' => true, 'id' => $response_data['id'], 'data' => $response_data);
    }

    // Vrácení chyby včetně debug informací
    return array(
        'error' => $response_data['error'] ?? 'Unknown error occurred during thread creation.',
        'debug' => $response_data['debug'] ?? null
    );
}


if (!function_exists('openai_create_and_run_thread')) {
    function openai_create_and_run_thread($api_key, $assistant_id, $messages, $metadata = [], $debug = false) {
        $url = 'https://api.openai.com/v1/threads/runs';
        
        $body = [
            'assistant_id' => $assistant_id,
            'thread' => [
                'messages' => $messages
            ],
            'metadata' => $metadata
        ];
        
        if ($debug) {
            ekortn_log_request(json_encode($body, JSON_UNESCAPED_UNICODE), 'info');
        }
        
        $response = wp_remote_post($url, [
            'method'    => 'POST',
            'headers'   => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'OpenAI-Beta'   => 'assistants=v2'
            ],
            'body'      => json_encode($body, JSON_UNESCAPED_UNICODE)
        ]);
        
        if ($debug) {
            $response_body = wp_remote_retrieve_body($response);
            ekortn_log_request("Response Body: " . $response_body, 'info');
        }
        
        $response_data = handle_api_response($response, $debug);
        
        // Opravený přístup k thread_id a run_id
        if (isset($response_data['thread_id']) && isset($response_data['id'])) {
            return [
                'success' => true,
                'thread_id' => $response_data['thread_id'],
                'run_id' => $response_data['id'],
                'data' => $response_data
            ];
        }
        
        return [
            'error' => $response_data['error'] ?? 'Unknown error occurred during thread creation.',
            'debug' => $response_data['debug'] ?? null
        ];
    }
}

if (!function_exists('openai_create_run')) {
    function openai_create_run($api_key, $thread_id, $body, $debug = false) {
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

function openai_check_run_status($api_key, $thread_id, $run_id, $debug = false) {
    if ($debug) {
        ekortn_log("Checking run status for thread_id: $thread_id, run_id: $run_id", 'info', 'file');
    }
    
    $url = "https://api.openai.com/v1/threads/$thread_id/runs/$run_id";
    
    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
            'OpenAI-Beta'   => 'assistants=v2'
        ]
    ]);
    
    $response_data = handle_api_response($response, $debug);
    
    if (isset($response_data['status'])) {
        return [
            'success' => true,
            'data' => $response_data
        ];
    }
    
    return [
        'error' => $response_data['error'] ?? 'Unknown error occurred while checking run status.',
        'debug' => $response_data['debug'] ?? null
    ];
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