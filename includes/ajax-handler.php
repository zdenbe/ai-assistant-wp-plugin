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
    // Načteme nastavení
    $debug = get_option('ekortn_debug', false);
    $log_output = $debug ? 'both' : 'file';

    ekortn_log('ekortn_process_chat_message - POST: ' . json_encode($_POST), 'info', $log_output);

    // Kontrola nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ekortn_frontend_nonce')) {
        $nonce_error_log = ekortn_log('Invalid nonce in ekortn_process_chat_message', 'error', $log_output);
        wp_send_json_error(['message' => 'Invalid nonce', 'debug' => $nonce_error_log]);
    }
    // Kontrola, zda je uživatel přihlášen
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Musíte být přihlášen(a).']);
    }

    // Získání a sanitizace zprávy
    $message = sanitize_text_field($_POST['message']);
    if (empty($message)) {
        $empty_log = ekortn_log('Empty message received', 'error', $log_output);
        wp_send_json_error(['message' => 'Zpráva nemůže být prázdná.', 'debug' => $empty_log]);
    }
    // Případné oříznutí zprávy
    $max_length = get_option('ekortn_max_message_length', 2000);
    if (strlen($message) > $max_length) {
        $message = substr($message, 0, $max_length);
        ekortn_log('Message truncated to: ' . $message, 'info', $log_output);
    }

    // Získání nastavení API klíče, defaultního asistenta a seznamu asistentů
    $api_key = get_option('ekortn_openai_api_key');
    $default_assistant_id = get_option('ekortn_default_assistant');
    $assistants = json_decode(get_option('ekortn_assistants', json_encode([])), true);

    if (empty($api_key) || empty($default_assistant_id) || empty($assistants)) {
        wp_send_json_error(['message' => 'Nastavení API klíče nebo asistentů není kompletní.']);
    }

    // Přetypujeme user_id na string
    $current_user_id = (string) get_current_user_id();

    // Uložíme původní uživatelskou zprávu do DB (volitelně, zde jen ukázka)
    global $wpdb;
    $table_threads = $wpdb->prefix . 'ekortn_threads';
    $table_messages = $wpdb->prefix . 'ekortn_messages';

    // --- KROK 1: Doporučení specializovaných asistentů ---
    // Sestavíme tělo pro doporučovací požadavek – předáme dotaz a popisy všech asistentů.
    $recommendation_body = [
        'query'      => $message,
        'assistants' => array_map(function($assistant) {
            return [
                'assistant_id' => isset($assistant['assistant_id']) ? $assistant['assistant_id'] : '', // předpokládáme, že v záznamech asistentů je i pole "assistant_id"
                'name'         => $assistant['name'],
                'description'  => $assistant['context'] // pokud máte oddělené pole pro popis, můžete použít např. 'description'
            ];
        }, $assistants)
    ];

    if ($debug) {
        ekortn_log_request(json_encode($recommendation_body, JSON_UNESCAPED_UNICODE), 'info');
    }

    // Voláme speciálního "doporučovacího asistenta" – zde je jeho ID hardcodováno, případně lze toto nastavení přesunout do možností
    $recommendation_assistant_id = 'asst_shsSogNBkvwgHQMGoiy7EqNZ';

    $recommendation_response = openai_create_and_run_thread(
        $api_key,
        $recommendation_assistant_id,
        [
            ['role' => 'user', 'content' => json_encode($recommendation_body)]
        ],
        ['user_id' => $current_user_id],
        $debug
    );

    if (isset($recommendation_response['error'])) {
        $recommendation_error_log = ekortn_log('Recommendation API Error: ' . $recommendation_response['error'], 'error', $log_output);
        wp_send_json_error([
            'message' => 'Chyba při komunikaci s OpenAI API při doporučování asistentů.',
            'details' => $recommendation_response['error'],
            'debug'   => $recommendation_error_log
        ]);
    }
    if (!isset($recommendation_response['thread_id']) || !isset($recommendation_response['run_id'])) {
        $invalid_response_log = ekortn_log('Invalid Recommendation API Response: ' . json_encode($recommendation_response), 'error', $log_output);
        wp_send_json_error([
            'message' => 'Chyba: Neplatná odpověď od API při doporučování asistentů.',
            'details' => json_encode($recommendation_response),
            'debug'   => $invalid_response_log
        ]);
    }

    // Polling – čekáme, dokud běh doporučovacího asistenta neskončí (max. pokusů 20, s 3 sekundovým intervalem)
    $rec_thread_id = $recommendation_response['thread_id'];
    $rec_run_id    = $recommendation_response['run_id'];
    $max_attempts  = 20;
    $attempts      = 0;
    $rec_status    = openai_check_run_status($api_key, $rec_thread_id, $rec_run_id, $debug);
    while ((($rec_status['data']['status'] ?? '') !== 'completed') && ($attempts < $max_attempts)) {
        sleep(3);
        $rec_status = openai_check_run_status($api_key, $rec_thread_id, $rec_run_id, $debug);
        $attempts++;
    }
    if (($rec_status['data']['status'] ?? '') !== 'completed') {
        wp_send_json_error(['message' => 'Chyba: Doporučovací běh nebyl dokončen včas.']);
    }

    // Získáme výsledek – očekáváme, že doporučovací asistent vrátí pole doporučených asistentů
    $rec_message = end($rec_status['data']['messages']);
    $recommended_assistants = json_decode($rec_message['content'], true);
    if (!is_array($recommended_assistants) || empty($recommended_assistants)) {
        wp_send_json_error([
            'message' => 'Chyba: Neplatná odpověď od API při doporučování asistentů.',
            'details' => $rec_message['content']
        ]);
    }

    // --- KROK 2: Získání odpovědí od specializovaných asistentů ---
    $specialized_responses = [];
    foreach ($recommended_assistants as $rec_assistant) {
        // Ověříme, že máme nastavené assistant_id
        if (!isset($rec_assistant['assistant_id']) || empty($rec_assistant['assistant_id'])) {
            continue;
        }
        $special_assistant_id = $rec_assistant['assistant_id'];

        $assistant_response = openai_create_and_run_thread(
            $api_key,
            $special_assistant_id,
            [
                ['role' => 'user', 'content' => $message]
            ],
            ['user_id' => $current_user_id],
            $debug
        );
        if (isset($assistant_response['error'])) {
            $assistant_error_log = ekortn_log('Assistant API Error (' . $special_assistant_id . '): ' . $assistant_response['error'], 'error', $log_output);
            wp_send_json_error([
                'message' => 'Chyba při získávání odpovědi od specializovaného asistenta ' . $special_assistant_id,
                'details' => $assistant_response['error'],
                'debug'   => $assistant_error_log
            ]);
        }
        // Polling pro dokončení běhu každého specializovaného asistenta
        $spec_thread_id = $assistant_response['thread_id'];
        $spec_run_id    = $assistant_response['run_id'];
        $attempts = 0;
        $spec_status = openai_check_run_status($api_key, $spec_thread_id, $spec_run_id, $debug);
        while ((($spec_status['data']['status'] ?? '') !== 'completed') && ($attempts < $max_attempts)) {
            sleep(3);
            $spec_status = openai_check_run_status($api_key, $spec_thread_id, $spec_run_id, $debug);
            $attempts++;
        }
        if (($spec_status['data']['status'] ?? '') !== 'completed') {
            wp_send_json_error([
                'message' => 'Chyba: Běh specializovaného asistenta ' . $special_assistant_id . ' nebyl dokončen včas.'
            ]);
        }
        $spec_message = end($spec_status['data']['messages']);
        $specialized_responses[] = $spec_message['content'] ?? '';
    }

    // --- KROK 3: Syntéza finální odpovědi výchozím (defaultním) asistentem ---
    $combined_specialized_text = implode("\n\n", $specialized_responses);
    $final_input = "Na základě těchto odpovědí od specializovaných asistentů:\n"
                    . $combined_specialized_text .
                    "\n\nvytvoř prosím kompletní odpověď na následující dotaz:\n" . $message;

    $final_response_body = [
        'assistant_id' => $default_assistant_id,
        'thread' => [
            'messages' => [
                ['role' => 'user', 'content' => $final_input]
            ]
        ],
        'metadata' => [
            'user_id' => $current_user_id
        ]
    ];

    if ($debug) {
        ekortn_log_request(json_encode($final_response_body, JSON_UNESCAPED_UNICODE), 'info');
    }

    $final_response = openai_create_and_run_thread(
        $api_key,
        $default_assistant_id,
        $final_response_body['thread']['messages'],
        $final_response_body['metadata'],
        $debug
    );

    if (isset($final_response['error'])) {
        $final_error_log = ekortn_log('Final API Error: ' . $final_response['error'], 'error', $log_output);
        wp_send_json_error([
            'message' => 'Chyba při formulaci finální odpovědi.',
            'details' => $final_response['error'],
            'debug'   => $final_error_log
        ]);
    }
    if (!isset($final_response['data']['messages'][0]['content'])) {
        $invalid_final_response_log = ekortn_log('Invalid Final API Response: ' . json_encode($final_response), 'error', $log_output);
        wp_send_json_error([
            'message' => 'Chyba: Neplatná odpověď od API při formulaci finální odpovědi.',
            'details' => json_encode($final_response),
            'debug'   => $invalid_final_response_log
        ]);
    }

    $final_content = $final_response['data']['messages'][0]['content'];

    // --- Uložení konverzace do DB ---
    // Uložíme informace o vlákně (můžete si přizpůsobit, jak se ukládají thread_id a run_id)
    $wpdb->insert($table_threads, [
        'thread_id' => $final_response['data']['thread_id'] ?? $rec_thread_id,
        'run_id'    => $final_response['data']['id'] ?? '',
        'user_id'   => $current_user_id
    ], ['%s','%s','%s']);

    // Uložíme původní uživatelskou zprávu
    $wpdb->insert($table_messages, [
        'thread_id' => $final_response['data']['thread_id'] ?? $rec_thread_id,
        'user_id'   => $current_user_id,
        'role'      => 'user',
        'content'   => $message
    ], ['%s','%s','%s','%s']);

    // Uložíme finální odpověď asistenta
    $wpdb->insert($table_messages, [
        'thread_id' => $final_response['data']['thread_id'] ?? $rec_thread_id,
        'user_id'   => $current_user_id,
        'role'      => 'assistant',
        'content'   => $final_content
    ], ['%s','%s','%s','%s']);

    // Vygenerujeme nový nonce pro front-end a odešleme finální odpověď uživateli
    $new_nonce = wp_create_nonce('ekortn_frontend_nonce');
    wp_send_json_success([
        'response'  => $final_content,
        'new_nonce' => $new_nonce
    ]);
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