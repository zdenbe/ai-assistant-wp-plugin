<div id="ekortn-chat-container">
    <div id="ekortn-chat-output"></div>
    <form id="ekortn-chat-form">
        <textarea id="ekortn-chat-input" placeholder="Zadejte svou zprávu..."></textarea>
        <button type="submit">Odeslat</button>
    </form>
</div>

<script>
document.getElementById('ekortn-chat-form').addEventListener('submit', function(e) {
    e.preventDefault();
    var query = document.getElementById('ekortn-chat-input').value.trim();

    // Kontrola, zda je zpráva prázdná
    if (!query) {
        document.getElementById('ekortn-chat-output').innerText += '\nError: Zpráva nemůže být prázdná.';
        return;
    }

    let currentNonce = '<?php echo wp_create_nonce('ekortn_frontend_nonce'); ?>';
    
    // Přidání nonce do těla požadavku
    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'action=ekortn_process_chat_message&message=' + encodeURIComponent(query) +
              '&nonce=' + encodeURIComponent(currentNonce) // Přidání nonce
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Aktualizace nonce pro následné požadavky
            currentNonce = data.new_nonce || currentNonce;

            // Zobrazení zprávy uživatele v chatu
            document.getElementById('ekortn-chat-output').innerText += '\nUser: ' + query;

            const thread_id = data.data.thread_id;

            // Zobrazení textu "Čekání na odpověď asistenta..."
            document.getElementById('ekortn-chat-output').innerText += '\nThread: ' + thread_id;
            document.getElementById('ekortn-chat-output').innerText += '\nWaiting for assistant response...';

            // Zahájení kontroly stavu zpracování asistenta
            checkAssistantResponse(thread_id, currentNonce);
            console.log('Thread:', thread_id);

            // Debug informace, pokud jsou k dispozici
            if (data.data.debug) {
                document.getElementById('ekortn-chat-output').innerText += '\nDebug Info: ' + data.data.debug;
            }
        } else {
            // Zpracování chybové zprávy
            let errorMessage = typeof data.message === 'string' ? data.message : JSON.stringify(data);
            document.getElementById('ekortn-chat-output').innerText += '\nError: ' + errorMessage;
            console.log('Detail chyby:', data.data);
        }
    })
    .catch(error => {
        document.getElementById('ekortn-chat-output').innerText += '\nError: ' + error.message;
        console.log('Error:', error);
    });
});

// Funkce pro kontrolu odpovědi asistenta
function checkAssistantResponse(thread_id, nonce) {
    console.log('nonce:', nonce);
    setTimeout(function() {
        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'action=check_query_status&thread_id=' + encodeURIComponent(thread_id) +
                  '&nonce=' + encodeURIComponent(nonce) // Použití aktualizované nonce
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.status === 'completed') {
                // Zobrazení odpovědi asistenta
                document.getElementById('ekortn-chat-output').innerText += '\nAssistant: ' + data.data.response;
                console.log('updatednonce:', nonce);

                // Debug informace, pokud jsou k dispozici
                if (data.data.debug) {
                    document.getElementById('ekortn-chat-output').innerText += '\nDebug Info: ' + JSON.stringify(data.data.debug);
                }

                // Vytvoření souhrnné odpovědi
                compileFinalResponse(thread_id, nonce);
            } else if (data.success && data.data.status === 'pending') {
                // Pokud odpověď ještě není hotová, zopakujeme kontrolu za pár sekund
                checkAssistantResponse(thread_id, nonce);
            } else {
                // Zpracování chybové zprávy
                let errorMessage = typeof data.message === 'string' ? data.message : JSON.stringify(data);
                document.getElementById('ekortn-chat-output').innerText += '\nError: ' + errorMessage;
                console.log('Detail chyby:', data.data);
            }
        })
        .catch(error => {
            document.getElementById('ekortn-chat-output').innerText += '\nError: ' + error.message;
            console.log('Error:', error);
        });
    }, 3000); // Kontrola každé 3 sekundy
}

// Funkce pro vytvoření souhrnné odpovědi
function compileFinalResponse(thread_id, nonce) {
    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'action=compile_final_response&thread_id=' + encodeURIComponent(thread_id) +
              '&nonce=' + encodeURIComponent(nonce) // Použití aktualizované nonce
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Zobrazení souhrnné odpovědi asistenta
            document.getElementById('ekortn-chat-output').innerText += '\nFinal Response: ' + data.data.final_response;

            // Debug informace, pokud jsou k dispozici
            if (data.data.debug) {
                document.getElementById('ekortn-chat-output').innerText += '\nDebug Info: ' + JSON.stringify(data.data.debug);
            }
        } else {
            // Zpracování chybové zprávy
            let errorMessage = typeof data.message === 'string' ? data.message : JSON.stringify(data);
            document.getElementById('ekortn-chat-output').innerText += '\nError: ' + errorMessage;
            console.log('Detail chyby:', data.data);
        }
    })
    .catch(error => {
        document.getElementById('ekortn-chat-output').innerText += '\nError: ' + error.message;
        console.log('Error:', error);
    });
}
</script>