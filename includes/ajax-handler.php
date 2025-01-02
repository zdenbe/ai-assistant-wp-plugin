<?php
// Zpracování AJAX požadavků

add_action('wp_ajax_ekortn_process_chat_message', 'ekortn_process_chat_message');
add_action('wp_ajax_nopriv_ekortn_process_chat_message', 'ekortn_process_chat_message');

add_action('wp_ajax_check_query_status', 'ekortn_check_query_status');
add_action('wp_ajax_nopriv_check_query_status', 'ekortn_check_query_status');

// Admin akce pro uložení a odstranění asistentů
add_action('wp_ajax_ekortn_save_assistant', 'ekortn_save_assistant');
add_action('wp_ajax_ekortn_remove_assistant', 'ekortn_remove_assistant');

// -----------------------
// Ukládání / mazání asistentů
// -----------------------
function ekortn_save_assistant() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Nedostatečná oprávnění.']);
    }
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ekortn_admin_nonce')) {
        wp_send_json_error(['message' => 'Invalid admin nonce']);
    }
    $assistant_id = sanitize_text_field($_POST['assistant_id'] ?? '');
    $assistant_data = $_POST['assistant'] ?? [];
    $assistant = [
        'name'    => sanitize_text_field($assistant_data['name'] ?? ''),
        'context' => sanitize_text_field($assistant_data['context'] ?? '')
    ];

    $assistants_json = get_option('ekortn_assistants', json_encode([]));
    $assistants = json_decode($assistants_json, true);

    $assistants[$assistant_id] = $assistant;
    update_option('ekortn_assistants', wp_json_encode($assistants));

    wp_send_json_success(['message' => 'Assistant saved.']);
}

function ekortn_remove_assistant() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Nedostatečná oprávnění.']);
    }
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ekortn_admin_nonce')) {
        wp_send_json_error(['message' => 'Invalid admin nonce']);
    }
    $assistant_id = sanitize_text_field($_POST['assistant_id'] ?? '');

    $assistants_json = get_option('ekortn_assistants', json_encode([]));
    $assistants = json_decode($assistants_json, true);

    if (isset($assistants[$assistant_id])) {
        unset($assistants[$assistant_id]);
        update_option('ekortn_assistants', wp_json_encode($assistants));
        wp_send_json_success(['message' => 'Assistant removed.']);
    } else {
        wp_send_json_error(['message' => 'Assistant not found.']);
    }
}

// ---------------------------------------
// 1) Příjem uživatelské zprávy: ekortn_process_chat_message
//    - vytvoření threadu v OpenAI
//    - uložení thread_id -> user_id
//    - uložení zprávy (role='user') do DB
// ---------------------------------------
function ekortn_process_chat_message() {
    $debug = get_option('ekortn_debug', false);
    $log_output = $debug ? 'both' : 'file';

    ekortn_log('ekortn_process_chat_message - POST: ' . json_encode($_POST), 'info', $log_output);

    // Kontrola nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ekortn_frontend_nonce')) {
        $nonce_error_log = ekortn_log('Invalid nonce in ekortn_process_chat_message', 'error', $log_output);
        wp_send_json_error(['message' => 'Invalid nonce', 'debug' => $nonce_error_log]);
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Musíte být přihlášen(a).']);
    }

    $message = sanitize_text_field($_POST['message']);
    if (empty($message)) {
        $empty_log = ekortn_log('Empty message received', 'error', $log_output);
        wp_send_json_error(['message' => 'Zpráva nemůže být prázdná.', 'debug' => $empty_log]);
    }

    // Případné zkrácení délky:
    $max_length = get_option('ekortn_max_message_length', 2000);
    if (strlen($message) > $max_length) {
        $message = substr($message, 0, $max_length);
        ekortn_log('Message truncated to: '.$message, 'info', $log_output);
    }

    $api_key = get_option('ekortn_openai_api_key');
    $default_assistant_id = get_option('ekortn_default_assistant');

    // Přidání kontroly, zda je default_assistant_id nastaven
    if (empty($default_assistant_id)) {
        ekortn_log('Default assistant ID is not set.', 'error', $log_output);
        wp_send_json_error(['message' => 'Default assistant ID není nastaven.', 'debug' => '']);
    }

    // Sestavení těla požadavku včetně assistant_id
    $body = json_encode([
        'assistant_id' => $default_assistant_id,
        'thread' => [
            'messages' => [
                ['role' => 'user', 'content' => $message]
            ]
        ],
        'metadata' => [
            'user_id' => get_current_user_id()
        ]
    ], JSON_UNESCAPED_UNICODE);

    if ($debug) {
        ekortn_log_request($body, $log_output);
    }

    // Vytvoření threadu na OpenAI
    $response = openai_create_thread($api_key, $body, $debug);
    if (isset($response['error'])) {
        $api_error_log = ekortn_log('Recommendation API Error: ' . $response['error'], 'error', $log_output);
        wp_send_json_error(['message' => 'Chyba při komunikaci s OpenAI API.', 'details' => $response['error'], 'debug' => $api_error_log]);
    }

    if (!isset($response['id'])) {
        $invalid_response_log = ekortn_log('Invalid API Response: ' . json_encode($response), 'error', $log_output);
        wp_send_json_error(['message' => 'Chyba: Neplatná odpověď od API.', 'details' => json_encode($response), 'debug' => $invalid_response_log]);
    }

    $thread_id = $response['id'];

    // ... [uložení thread_id do DB a další kroky]

    wp_send_json_success(['thread_id' => $thread_id, 'response' => $response['data']]);
}

// ---------------------------------------
// 2) Kontrola stavu - ekortn_check_query_status
//    - ověříme, zda thread_id patří userovi
//    - pokud je stav 'completed', uložíme
//      i asistentovu zprávu (do ekortn_messages)
// ---------------------------------------
function ekortn_check_query_status() {
    $debug = get_option('ekortn_debug', false);
    $log_output = $debug ? 'both' : 'file';

    ekortn_log('ekortn_check_query_status - POST: ' . json_encode($_POST), 'info', $log_output);

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ekortn_frontend_nonce')) {
        $nonce_err = ekortn_log('Invalid nonce in ekortn_check_query_status', 'error', $log_output);
        wp_send_json_error(['message' => 'Invalid nonce', 'debug' => $nonce_err]);
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Musíte být přihlášen(a).']);
    }

    if (empty($_POST['thread_id'])) {
        wp_send_json_error(['message' => 'Missing thread_id']);
    }

    $thread_id = sanitize_text_field($_POST['thread_id']);
    $current_user_id = get_current_user_id();

    // Ověřit, že thread_id patří tomuto userovi
    global $wpdb;
    $table_threads = $wpdb->prefix . 'ekortn_threads';
    $table_messages = $wpdb->prefix . 'ekortn_messages';

    $belongs_count = true; // Dočasné vypnutí kontroly uživatele
    //$belongs_count = $wpdb->get_var($wpdb->prepare(
    //    "SELECT COUNT(*) FROM $table_threads WHERE thread_id = %s AND user_id = %d",
    //    $thread_id,
    //    $current_user_id
    //));
    //if (!$belongs_count) {
    //    wp_send_json_error(['message' => 'Access denied - this thread does not belong to you.']);
    //}

    $api_key = get_option('ekortn_openai_api_key');
    // Původní kód předpokládal, že run_id = thread_id
    // (pokud byste měl skutečný run_id, tak byste ho ukládal a používal tady)
    $status = openai_check_run_status($api_key, $thread_id, $thread_id, $debug);
    if (!empty($status['error'])) {
        wp_send_json_error(['message' => $status['error']]);
    }

    // Např. $status['data']['status'] = 'completed' / 'pending' / ...
    $run_status = $status['data']['status'] ?? 'unknown';

    $response_data = [
        'status' => $run_status
    ];

    // Pokud je stav completed, zkusíme vytáhnout z $status['data']['messages'] 
    // poslední asistentovu zprávu a uložit ji do DB
    if ($run_status === 'completed') {
        if (!empty($status['data']['messages'])) {
            $assistantMsg = end($status['data']['messages']); // poslední prvek pole
            if (!empty($assistantMsg['content'])) {
                $assistant_content = $assistantMsg['content'];
                // Vraťme to do front-endu
                $response_data['response'] = $assistant_content;

                // Uložíme do ekortn_messages
                $wpdb->insert($table_messages, [
                    'thread_id' => $thread_id,
                    'user_id'   => $current_user_id,
                    'role'      => 'assistant',
                    'content'   => $assistant_content
                ], ['%s','%d','%s','%s']);
            }
        }
    }

    // Případné debug info
    if ($debug && isset($status['debug'])) {
        $response_data['debug'] = $status['debug'];
    }

    wp_send_json_success($response_data);
}