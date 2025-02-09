document.getElementById('ekortn-chat-form').addEventListener('submit', function(e) {
    e.preventDefault();
    var query = document.getElementById('ekortn-chat-input').value.trim();

    // Kontrola, zda je zpráva prázdná
    if (!query) {
        document.getElementById('ekortn-chat-output').innerText += '\nError: Zpráva nemůže být prázdná.';
        return;
    }

    let currentNonce = ekortn_ajax.nonce;

    // Přidání nonce do těla požadavku
    fetch(ekortn_ajax.ajax_url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'action=ekortn_process_chat_message&message=' + encodeURIComponent(query) +
              '&nonce=' + encodeURIComponent(currentNonce) 
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Aktualizace nonce
            currentNonce = data.data.new_nonce || currentNonce;

            // Zobrazení zprávy uživatele v chatu
            document.getElementById('ekortn-chat-output').innerText += '\nUser: ' + query;

            // Zobrazení textu "Waiting for assistant response..."
            document.getElementById('ekortn-chat-output').innerText += '\nWaiting for assistant response...';

            // Zobrazení finální odpovědi
            document.getElementById('ekortn-chat-output').innerText += '\nAssistant: ' + data.data.response;

            // Aktualizace logu
            console.log('Assistant response:', data.data.response);

            // Debug informace, pokud jsou k dispozici
            if (data.data.debug) {
                console.log('Debug Info:', data.data.debug);
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
});