<?php
// admin-settings.php

// Registrace nastavení pluginu
function ekortn_register_settings() {
    add_option('ekortn_openai_api_key', '');
    register_setting('ekortn_options_group', 'ekortn_openai_api_key');

    add_option('ekortn_default_assistant', '');
    register_setting('ekortn_options_group', 'ekortn_default_assistant');

    add_option('ekortn_assistants', json_encode([]));
    register_setting('ekortn_options_group', 'ekortn_assistants');

    add_option('ekortn_debug', false);
    register_setting('ekortn_options_group', 'ekortn_debug');

    add_option('ekortn_token_limit', 1000); // Default value
    register_setting('ekortn_options_group', 'ekortn_token_limit');
}
add_action('admin_init', 'ekortn_register_settings');

// Registrace stránky nastavení pluginu v administraci
function ekortn_register_options_page() {
    // Hlavní stránka
    add_options_page('Nastavení EkoRTN Assistenta', 'EkoRTN Assistant', 'manage_options', 'ekortn', 'ekortn_options_page');
}
add_action('admin_menu', 'ekortn_register_options_page');

// Vykreslení stránky nastavení pluginu
function ekortn_options_page() {
    ?>
    <div class="wrap">
        <h2>Nastavení EkoRTN Assistenta</h2>
        <form method="post" action="options.php">
            <?php settings_fields('ekortn_options_group'); ?>
            <?php wp_nonce_field('ekortn_save_settings', 'ekortn_nonce'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="ekortn_openai_api_key">API Klíč</label></th>
                    <td><input type="text" id="ekortn_openai_api_key" name="ekortn_openai_api_key" 
                               value="<?php echo esc_attr(get_option('ekortn_openai_api_key')); ?>" class="regular-text" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="ekortn_default_assistant">Výchozí Asistent</label></th>
                    <td>
                        <select id="ekortn_default_assistant" name="ekortn_default_assistant" class="regular-text">
                            <?php
                            $assistants = json_decode(get_option('ekortn_assistants', json_encode([])), true);
                            foreach ($assistants as $id => $assistant) {
                                echo '<option value="' . esc_attr($id) . '" ' . selected(get_option('ekortn_default_assistant'), $id, false) . '>' . esc_html($assistant['name']) . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="ekortn_debug">Debug mód</label></th>
                    <td><input type="checkbox" id="ekortn_debug" name="ekortn_debug" 
                               value="1" <?php checked(1, get_option('ekortn_debug'), true); ?> /></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="ekortn_token_limit">Limit Tokenů</label></th>
                    <td><input type="number" id="ekortn_token_limit" name="ekortn_token_limit" 
                               value="<?php echo esc_attr(get_option('ekortn_token_limit')); ?>" class="regular-text" /></td>
                </tr>
            </table>

            <h3>Asistenti</h3>
            <table class="assistant-table">
                <thead>
                    <tr>
                        <th>Asistent</th>
                        <th>Popis</th>
                        <th>Akce</th>
                    </tr>
                </thead>
                <tbody id="assistants-list">
                    <?php
                    $assistants = json_decode(get_option('ekortn_assistants', json_encode([])), true);
                    foreach ($assistants as $id => $assistant) {
                        echo '<tr class="assistant-item" data-id="' . esc_attr($id) . '">';
                        echo '<td>';
                        echo '<div class="assistant-title"><strong>' . esc_html($assistant['name']) . '</strong><br>';
                        echo '<span class="assistant-id">' . esc_html($id) . '</span></div>';
                        echo '</td>';
                        echo '<td>' . esc_html($assistant['context']) . '</td>';
                        echo '<td class="action-buttons">';
                        echo '<button class="edit-assistant button" data-id="' . esc_attr($id) . '">Upravit</button>';
                        echo '<button class="remove-assistant button" data-id="' . esc_attr($id) . '">Odstranit</button>';
                        echo '</td>';
                        echo '</tr>';
                    }
                    ?>
                </tbody>
            </table>

            <h3>Přidat asistenta</h3>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="new-assistant-name">Název</label></th>
                    <td><input type="text" id="new-assistant-name" class="regular-text"></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="new-assistant-id">ID</label></th>
                    <td><input type="text" id="new-assistant-id" class="regular-text"></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="new-assistant-context">Kontext</label></th>
                    <td><textarea id="new-assistant-context" class="large-text"></textarea></td>
                </tr>
            </table>
            <p><button id="add-assistant" class="button button-primary">Přidat</button></p>
            <input type="hidden" id="ekortn_assistants" name="ekortn_assistants" 
                   value='<?php echo esc_attr(get_option('ekortn_assistants', json_encode([]))); ?>'>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// ===========================================
// Přidáme submenu pro "Zprávy konverzace"
// ===========================================
add_action('admin_menu', 'ekortn_register_messages_submenu');

function ekortn_register_messages_submenu() {
    // Rodičovská obrazovka je 'ekortn' (ze "Nastavení EkoRTN Assistenta")
    add_submenu_page(
        'ekortn',                      // parent slug
        'Zprávy konverzace',           // page title
        'Zprávy konverzace',           // menu title
        'manage_options',              // capability
        'ekortn_messages',             // menu slug
        'ekortn_render_messages_page'  // callback
    );
}

function ekortn_render_messages_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Nedostatečná oprávnění.');
    }

    global $wpdb;
    $table_messages = $wpdb->prefix . 'ekortn_messages';

    // Seznam všech uložených zpráv, seřazených od nejnovějších
    $results = $wpdb->get_results("SELECT * FROM $table_messages ORDER BY created_at DESC");

    ?>
    <div class="wrap">
        <h1>Zprávy konverzace</h1>
        <p>Zde vidíte všechny zprávy uložené v tabulce <code><?php echo esc_html($table_messages); ?></code>.</p>

        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th width="5%">ID</th>
                    <th width="15%">Thread ID</th>
                    <th width="10%">User ID</th>
                    <th width="10%">Role</th>
                    <th width="50%">Content</th>
                    <th width="10%">Vytvořeno</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($results)): ?>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row->id); ?></td>
                        <td><?php echo esc_html($row->thread_id); ?></td>
                        <td><?php echo esc_html($row->user_id); ?></td>
                        <td><?php echo esc_html($row->role); ?></td>
                        <td><?php echo esc_html($row->content); ?></td>
                        <td><?php echo esc_html($row->created_at); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6">Zatím zde nejsou žádné zprávy.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
?>