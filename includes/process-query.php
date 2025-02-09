<?php

if (!function_exists('process_query_function')) {
    function process_query_function()
    {
        error_reporting(E_ALL);
        ini_set('display_errors', 1);

        // Získání nastavení pro debug mód a výstup logů
        $debug = get_option('ekortn_debug', false);
        $log_output = $debug ? 'both' : 'file';

        // Validace nonce pro zabezpečení AJAX požadavku
        ekortn_log('Validating nonce...', 'info', $log_output);
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ekortn_frontend_nonce')) {
            $nonce_log = ekortn_log('Received nonce: ' . (isset($_POST['nonce']) ? $_POST['nonce'] : 'none'), 'error', $log_output);
            wp_send_json_error(['message' => 'Invalid nonce', 'debug' => $nonce_log]);
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Pro použití tohoto formuláře se musíte přihlásit.']);
            return;
        }

        // Logování přijatého dotazu
        $query = sanitize_text_field($_POST['query']);
        $query_log = ekortn_log('Received query: ' . $query, 'info', $log_output);

        $current_user_id = (string) get_current_user_id();
        $api_key = get_option('ekortn_openai_api_key');
        $default_assistant_id = get_option('ekortn_default_assistant');
        $assistants = json_decode(get_option('ekortn_assistants', json_encode([])), true);

        if (empty($api_key) || empty($default_assistant_id) || empty($assistants)) {
            wp_send_json_error(['message' => 'Nastavení API klíče nebo asistentů není kompletní.']);
            return;
        }

        // Zjistíme doporučené asistenty od výchozího asistenta
        $recommendation_body = [
            'assistant_id' => $default_assistant_id, // Musí být na nejvyšší úrovni
            'thread' => [
                'messages' => [
                    ['role' => 'user', 'content' => $query]
                ]
            ],
            'metadata' => [
                'user_id' => $current_user_id
            ]
        ];

        $recommendation_body_json = json_encode($recommendation_body, JSON_UNESCAPED_UNICODE);

        if ($debug) {
            ekortn_log_request($recommendation_body_json, $log_output);
        }

        $recommendation_data = openai_create_and_run_thread($api_key, $default_assistant_id, $recommendation_body['thread']['messages'], $recommendation_body['metadata'], $debug);

        if (isset($recommendation_data['error'])) {
            $api_error_log = ekortn_log('Recommendation API Error: ' . $recommendation_data['error'], 'error', $log_output);
            wp_send_json_error(['message' => 'Chyba při komunikaci s OpenAI API.', 'details' => $recommendation_data['error'], 'debug' => $api_error_log]);
            return;
        }

        if (!isset($recommendation_data['thread_id']) || !isset($recommendation_data['run_id'])) {
            $invalid_response_log = ekortn_log('Invalid API Response: ' . json_encode($recommendation_data), 'error', $log_output);
            wp_send_json_error(['message' => 'Chyba: Neplatná odpověď od API.', 'details' => json_encode($recommendation_data), 'debug' => $invalid_response_log]);
            return;
        }

        $thread_id = $recommendation_data['thread_id'];
        $run_id = $recommendation_data['run_id'];

        // Kontrola stavu běhu výchozího asistenta
        $status = openai_check_run_status($api_key, $thread_id, $run_id, $debug);

        if (isset($status['error'])) {
            $status_error_log = ekortn_log('Check Run Status Error: ' . $status['error'], 'error', $log_output);
            wp_send_json_error(['message' => 'Chyba při kontrolování stavu běhu.', 'details' => $status['error'], 'debug' => $status_error_log]);
            return;
        }

        if (!isset($status['data']['status'])) {
            $invalid_status_response_log = ekortn_log('Invalid Run Status Response: ' . json_encode($status), 'error', $log_output);
            wp_send_json_error(['message' => 'Chyba: Neplatná odpověď od API při kontrolování stavu.', 'details' => json_encode($status), 'debug' => $invalid_status_response_log]);
            return;
        }

        $status = $status['data']['status'];

        if ($status !== 'completed') {
            // Čekání na dokončení
            $max_attempts = 20;
            $attempts = 0;
            while ($status !== 'completed' && $attempts < $max_attempts) {
                sleep(3);
                $status_data = openai_check_run_status($api_key, $thread_id, $run_id, $debug);

                if (isset($status_data['error'])) {
                    $status_error_log = ekortn_log('Check Run Status Error: ' . $status_data['error'], 'error', $log_output);
                    wp_send_json_error(['message' => 'Chyba při kontrolování stavu běhu.', 'details' => $status_data['error'], 'debug' => $status_error_log]);
                    return;
                }

                if (!isset($status_data['data']['status'])) {
                    $invalid_status_response_log = ekortn_log('Invalid Run Status Response: ' . json_encode($status_data), 'error', $log_output);
                    wp_send_json_error(['message' => 'Chyba: Neplatná odpověď od API při kontrolování stavu.', 'details' => json_encode($status_data), 'debug' => $invalid_status_response_log]);
                    return;
                }

                $status = $status_data['data']['status'];
                $attempts++;
            }

            if ($status !== 'completed') {
                wp_send_json_error(['message' => 'Chyba: Běh nebyl dokončen včas.']);
                return;
            }
        }

        // Zpracování doporučených asistentů
        if (isset($status_data['data']['messages'])) {
            $assistantMsg = end($status_data['data']['messages']);
            if (!empty($assistantMsg['content'])) {
                $recommended_assistants_text = $assistantMsg['content'];
                if ($debug) {
                    ekortn_log('Recommended assistants: ' . $recommended_assistants_text, 'info', $log_output);
                }

                $recommended_assistants = json_decode($recommended_assistants_text, true);
                if (!is_array($recommended_assistants)) {
                    wp_send_json_error(['message' => 'Chyba: Neplatná odpověď od API.', 'details' => $recommended_assistants_text]);
                    return;
                }
            } else {
                wp_send_json_error(['message' => 'Chyba: Doporučení asistenti nebyli nalezeni.', 'details' => $status_data]);
                return;
            }
        } else {
            wp_send_json_error(['message' => 'Chyba: Doporučení asistenti nebyli nalezeni.', 'details' => $status_data]);
            return;
        }

        // Získání odpovědí od doporučených asistentů
        $responses = [];
        foreach ($recommended_assistants as $assistant_id => $assistant_query) {
            if (isset($assistants[$assistant_id])) {
                $assistant_run_request_body = [
                    'assistant_id' => $assistant_id,
                    'thread' => [
                        'messages' => [
                            ['role' => 'user', 'content' => $assistant_query]
                        ]
                    ],
                    'metadata' => [
                        'user_id' => $current_user_id
                    ]
                ];

                if ($debug) {
                    ekortn_log_request(json_encode($assistant_run_request_body, JSON_UNESCAPED_UNICODE), 'info');
                }

                // Vytvoření threadu a runu pro asistenta
                $assistant_run_data = openai_create_and_run_thread($api_key, $assistant_id, $assistant_run_request_body['thread']['messages'], $assistant_run_request_body['metadata'], $debug);

                if (isset($assistant_run_data['error'])) {
                    $assistant_run_error_log = ekortn_log('Assistant Run API Error: ' . $assistant_run_data['error'], 'error', $log_output);
                    wp_send_json_error(['message' => 'Chyba při komunikaci s OpenAI API.', 'details' => $assistant_run_data['error'], 'debug' => $assistant_run_error_log]);
                    return;
                }

                if (!isset($assistant_run_data['thread_id']) || !isset($assistant_run_data['run_id'])) {
                    $invalid_assistant_run_response_log = ekortn_log('Invalid Assistant Run Response: ' . json_encode($assistant_run_data), 'error', $log_output);
                    wp_send_json_error(['message' => 'Chyba: Neplatná odpověď od API.', 'details' => json_encode($assistant_run_data), 'debug' => $invalid_assistant_run_response_log]);
                    return;
                }

                $assistant_thread_id = $assistant_run_data['thread_id'];
                $assistant_run_id = $assistant_run_data['run_id'];

                // Kontrola stavu běhu asistenta
                $assistant_status = openai_check_run_status($api_key, $assistant_thread_id, $assistant_run_id, $debug);

                if (isset($assistant_status['error'])) {
                    $check_assistant_status_error_log = ekortn_log('Check Assistant Run Status Error: ' . $assistant_status['error'], 'error', $log_output);
                    wp_send_json_error(['message' => 'Chyba při kontrolování stavu běhu asistenta.', 'details' => $assistant_status['error'], 'debug' => $check_assistant_status_error_log]);
                    return;
                }

                if (!isset($assistant_status['data']['status'])) {
                    $invalid_assistant_status_response_log = ekortn_log('Invalid Assistant Run Status Response: ' . json_encode($assistant_status), 'error', $log_output);
                    wp_send_json_error(['message' => 'Chyba: Neplatná odpověď od API při kontrolování stavu asistenta.', 'details' => json_encode($assistant_status), 'debug' => $invalid_assistant_status_response_log]);
                    return;
                }

                $assistant_status = $assistant_status['data']['status'];

                if ($assistant_status !== 'completed') {
                    // Čekání na dokončení
                    $max_attempts = 20;
                    $assistant_attempts = 0;
                    while ($assistant_status !== 'completed' && $assistant_attempts < $max_attempts) {
                        sleep(3);
                        $assistant_status_data = openai_check_run_status($api_key, $assistant_thread_id, $assistant_run_id, $debug);

                        if (isset($assistant_status_data['error'])) {
                            $check_assistant_status_error_log = ekortn_log('Check Assistant Run Status Error: ' . $assistant_status_data['error'], 'error', $log_output);
                            wp_send_json_error(['message' => 'Chyba při kontrolování stavu běhu asistenta.', 'details' => $assistant_status_data['error'], 'debug' => $check_assistant_status_error_log]);
                            return;
                        }

                        if (!isset($assistant_status_data['data']['status'])) {
                            $invalid_assistant_status_response_log = ekortn_log('Invalid Assistant Run Status Response: ' . json_encode($assistant_status_data), 'error', $log_output);
                            wp_send_json_error(['message' => 'Chyba: Neplatná odpověď od API při kontrolování stavu asistenta.', 'details' => json_encode($assistant_status_data), 'debug' => $invalid_assistant_status_response_log]);
                            return;
                        }

                        $assistant_status = $assistant_status_data['data']['status'];
                        $assistant_attempts++;
                    }

                    if ($assistant_status !== 'completed') {
                        wp_send_json_error(['message' => 'Chyba: Běh asistenta nebyl dokončen včas.']);
                        return;
                    }
                }

                if (isset($assistant_status['data']['messages'])) {
                    $assistantMsg = end($assistant_status['data']['messages']);
                    if (!empty($assistantMsg['content'])) {
                        $assistant_content = $assistantMsg['content'];
                        $responses[] = $assistant_content;
                    } else {
                        wp_send_json_error(['message' => 'Chyba: Odpověď asistenta nebyla nalezena.', 'details' => $assistant_status]);
                        return;
                    }
                } else {
                    wp_send_json_error(['message' => 'Chyba: Odpověď asistenta nebyla nalezena.', 'details' => $assistant_status]);
                    return;
                }
            }
        }

        // Složení konečné odpovědi
        $combined_responses = implode("\n\n", $responses);

        $final_response_data = ['response' => $combined_responses];
        if ($debug) {
            $final_response_data['debug'] = [
                'recommendation_body' => json_encode($recommendation_body, JSON_UNESCAPED_UNICODE),
                'recommendation_response_body' => $recommendation_data,
                'responses' => $responses
            ];
            ekortn_log_response(json_encode($final_response_data, JSON_UNESCAPED_UNICODE), $log_output);
        }

        wp_send_json_success($final_response_data);
    }
}
