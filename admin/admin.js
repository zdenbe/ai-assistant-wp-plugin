jQuery(document).ready(function($) {
    let isEditing = false;
    let editingAssistantId = null;

    // Přidání nebo uložení asistenta
    $('#add-assistant').click(function(e) {
        e.preventDefault();

        var assistants = JSON.parse($('#ekortn_assistants').val());
        var newAssistant = {
            name: $('#new-assistant-name').val(),
            context: $('#new-assistant-context').val()
        };
        var newAssistantId = $('#new-assistant-id').val();

        if (!newAssistantId || !newAssistant.name || !newAssistant.context) {
            alert('Prosím, vyplňte všechna pole pro asistenta.');
            return;
        }

        // Zde přidáme AJAX volání pro uložení asistenta
        $.ajax({
            url: ajaxurl, // Použití globální proměnné ajaxurl pro AJAX volání
            method: 'POST',
            data: {
                action: 'ekortn_save_assistant', // Akce pro zpracování na straně serveru
                assistant_id: newAssistantId,
                assistant: newAssistant,
                nonce: ekortn_admin.nonce // Přidání nonce pro zabezpečení
            },
            success: function(response) {
                if (response.success) {
                    if (isEditing) {
                        // Aktualizace existujícího asistenta
                        assistants[editingAssistantId] = newAssistant;
                        $('tr[data-id="' + editingAssistantId + '"]').find('td:eq(0)').text(newAssistant.name);
                        $('tr[data-id="' + editingAssistantId + '"]').find('td:eq(2)').text(newAssistant.context);
                    } else {
                        // Přidání nového asistenta
                        assistants[newAssistantId] = newAssistant;
                        $('#assistants-list').append(
                            '<tr class="assistant-item" data-id="' + newAssistantId + '">' +
                            '<td><div class="assistant-title"><strong>' + newAssistant.name + '</strong><br>' +
                            '<span class="assistant-id">' + newAssistantId + '</span></div></td>' +
                            '<td>' + newAssistant.context + '</td>' +
                            '<td class="action-buttons">' +
                            '<button class="edit-assistant button" data-id="' + newAssistantId + '">Upravit</button> ' +
                            '<button class="remove-assistant button" data-id="' + newAssistantId + '">Odstranit</button>' +
                            '</td>' +
                            '</tr>'
                        );
                    }

                    // Aktualizace skryté hodnoty
                    $('#ekortn_assistants').val(JSON.stringify(assistants));

                    // Vyčištění formuláře a reset tlačítka
                    resetForm();
                } else {
                    alert('Chyba: ' + response.data.message);
                }
            },
            error: function(xhr, status, error) {
                alert('AJAX Error: ' + error);
            }
        });
    });

    // Odstranění asistenta
    $(document).on('click', '.remove-assistant', function(e) {
        e.preventDefault();
        var assistants = JSON.parse($('#ekortn_assistants').val());
        var assistantId = $(this).data('id');

        if (!confirm('Opravdu chcete odstranit tohoto asistenta?')) {
            return;
        }

        // Zde přidáme AJAX volání pro odstranění asistenta
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'ekortn_remove_assistant',
                assistant_id: assistantId,
                nonce: ekortn_admin.nonce // Přidání nonce pro zabezpečení
            },
            success: function(response) {
                if (response.success) {
                    // Odstranění asistenta z objektu
                    delete assistants[assistantId];

                    // Aktualizace skryté hodnoty
                    $('#ekortn_assistants').val(JSON.stringify(assistants));

                    // Odstranění asistenta z tabulky
                    $('tr[data-id="' + assistantId + '"]').remove();
                } else {
                    alert('Chyba: ' + response.data.message);
                }
            },
            error: function(xhr, status, error) {
                alert('AJAX Error: ' + error);
            }
        });
    });

    // Úprava asistenta
    $(document).on('click', '.edit-assistant', function(e) {
        e.preventDefault();
        var assistants = JSON.parse($('#ekortn_assistants').val());
        editingAssistantId = $(this).data('id');
        var assistant = assistants[editingAssistantId];

        // Načtení hodnot asistenta do formuláře
        $('#new-assistant-name').val(assistant.name);
        $('#new-assistant-id').val(editingAssistantId).prop('disabled', true);
        $('#new-assistant-context').val(assistant.context);

        // Změna tlačítka "Přidat" na "Uložit"
        $('#add-assistant').text('Uložit');
        isEditing = true;
    });

    // Funkce pro resetování formuláře
    function resetForm() {
        $('#new-assistant-name').val('');
        $('#new-assistant-id').val('').prop('disabled', false);
        $('#new-assistant-context').val('');
        $('#add-assistant').text('Přidat');
        isEditing = false;
        editingAssistantId = null;
    }
});