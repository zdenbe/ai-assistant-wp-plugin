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
add_action('admin_menu', 'ekortn_register_options_page', 9); 

function ekortn_register_options_page() {
    // 1) Hlavní stránka - Nastavení EkoRTN Assistenta
    add_options_page(
        'Nastavení EkoRTN Assistenta',
        'EkoRTN Assistant',
        'manage_options',
        'ekortn',
        'ekortn_options_page'
    );

    // 2) Podmenu „Zprávy konverzace“
    add_submenu_page(
        'options-general.php?page=ekortn', // slug hlavní stránky (ne úplně standard, ale funguje)
        'Zprávy konverzace',               // page title
        'Zprávy konverzace',               // menu title
        'manage_options',                  // capability
        'ekortn_messages',                 // menu slug
        'ekortn_render_messages_page'      // callback
    );
}

function ekortn_options_page() {
    ?>
    <div class="wrap">
        <h2>Nastavení EkoRTN Assistenta</h2>
        <form method="post" action="options.php">
            <?php
            settings_fields('ekortn_options_group');
            wp_nonce_field('ekortn_save_settings', 'ekortn_nonce');
            ?>
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
                                echo '<option value="' . esc_attr($id) . '" ' . selected(get_option('ekortn_default_assistant'), $id, false) . '>'
                                     . esc_html($assistant['name']) . '</option>';
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

// ================================================
// Hlavní funkce vykreslující "Zprávy konverzace"
// ================================================
function ekortn_render_messages_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Nedostatečná oprávnění.');
    }

    global $wpdb;
    $table_messages = $wpdb->prefix . 'ekortn_messages';

    // Rozhodneme se, zda uživatel chce "detail" vlákna nebo "seznam" všech zpráv
    $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'list';

    // ============== DETAIL VLÁKNA ==============
    if ($view === 'detail' && !empty($_GET['thread_id'])) {
        $thread_id = sanitize_text_field($_GET['thread_id']);

        // Dotaz na všechny zprávy z daného threadu
        $sql = $wpdb->prepare("SELECT * FROM $table_messages WHERE thread_id = %s ORDER BY created_at ASC", $thread_id);
        $results = $wpdb->get_results($sql);

        ?>
        <div class="wrap">
            <h1>Detail konverzace pro Thread: <?php echo esc_html($thread_id); ?></h1>
            <p><a href="<?php echo esc_url(admin_url('options-general.php?page=ekortn_messages')); ?>">&laquo; Zpět na seznam všech zpráv</a></p>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th width="5%">ID</th>
                        <th width="10%">User ID</th>
                        <th width="10%">Role</th>
                        <th width="60%">Content</th>
                        <th width="15%">Vytvořeno</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($results)): ?>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row->id); ?></td>
                            <td><?php echo esc_html($row->user_id); ?></td>
                            <td><?php echo esc_html($row->role); ?></td>
                            <td><?php echo esc_html($row->content); ?></td>
                            <td><?php echo esc_html($row->created_at); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5">Toto vlákno je prázdné nebo neexistuje.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php

        return; // Ukončíme vykreslování -> detail se zobrazil
    }

    // ============== SEZNAM VŠECH ZPRÁV (S FILTRY A PAGINACÍ) ==============

    // 1) Zjistíme, zda je vyplněn filtr user_id a thread_id
    $filter_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $filter_thread_id = !empty($_GET['fthread']) ? sanitize_text_field($_GET['fthread']) : '';

    // 2) Paginace
    $per_page = 20; // Počet záznamů na stránku
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($paged - 1) * $per_page;

    // 3) Sestavíme WHERE podmínky podle filtrů
    $where_clauses = [];
    $params = [];
    if ($filter_user_id > 0) {
        $where_clauses[] = "user_id = %d";
        $params[] = $filter_user_id;
    }
    if ($filter_thread_id !== '') {
        $where_clauses[] = "thread_id = %s";
        $params[] = $filter_thread_id;
    }

    $where_sql = '';
    if (!empty($where_clauses)) {
        $where_sql = "WHERE " . implode(" AND ", $where_clauses);
    }

    // 4) Zjistíme CELKOVÝ počet pro paginaci
    $sql_count = "SELECT COUNT(*) FROM $table_messages $where_sql";
    $total_count = $wpdb->get_var($wpdb->prepare($sql_count, $params));

    // 5) Získáme reálné záznamy (s LIMITem a OFFSETem)
    $sql_main = "SELECT * FROM $table_messages $where_sql 
                 ORDER BY created_at DESC 
                 LIMIT %d OFFSET %d";
    // Musíme sloučit parametry + limit, offset:
    $params_main = array_merge($params, [ $per_page, $offset ]);

    $results = $wpdb->get_results($wpdb->prepare($sql_main, $params_main));

    // Vypočítáme počet stránek
    $total_pages = ceil($total_count / $per_page);

    // Vygenerujeme URL základ (bez paged param)
    $base_url = admin_url('options-general.php?page=ekortn_messages');
    // Zachováme filtry v URL
    if ($filter_user_id) {
        $base_url .= '&user_id=' . urlencode($filter_user_id);
    }
    if ($filter_thread_id) {
        $base_url .= '&fthread=' . urlencode($filter_thread_id);
    }

    ?>
    <div class="wrap">
        <h1>Zprávy konverzace</h1>

        <!-- FILTR -->
        <form method="get" action="">
            <input type="hidden" name="page" value="ekortn_messages" />
            <table class="form-table">
                <tr>
                    <th><label for="filter_user_id">User ID:</label></th>
                    <td><input type="number" name="user_id" id="filter_user_id" 
                               value="<?php echo esc_attr($filter_user_id); ?>" 
                               placeholder="Např. 1" /></td>

                    <th><label for="filter_thread_id">Thread ID:</label></th>
                    <td><input type="text" name="fthread" id="filter_thread_id"
                               value="<?php echo esc_attr($filter_thread_id); ?>"
                               placeholder="thread_XXX" /></td>

                    <td><button type="submit" class="button">Filtrovat</button></td>
                </tr>
            </table>
        </form>

        <p>Nalezeno <strong><?php echo intval($total_count); ?></strong> záznamů.</p>

        <!-- TABULKA SE ZPRÁVAMI -->
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
                        <td>
                          <?php echo esc_html($row->thread_id); ?>
                          <br />
                          <small>
                             <a href="<?php echo esc_url(add_query_arg(array(
                                 'view' => 'detail',
                                 'thread_id' => $row->thread_id
                             ), $base_url)); ?>">
                                Detail
                             </a>
                          </small>
                        </td>
                        <td><?php echo esc_html($row->user_id); ?></td>
                        <td><?php echo esc_html($row->role); ?></td>
                        <td><?php echo esc_html($row->content); ?></td>
                        <td><?php echo esc_html($row->created_at); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6">Nebyl nalezen žádný záznam.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <!-- PAGINACE -->
        <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom" style="margin-top:20px;">
                <div class="tablenav-pages">
                <?php
                // Jednoduchá stránkovací logika
                for ($i = 1; $i <= $total_pages; $i++) {
                    $url = add_query_arg('paged', $i, $base_url);
                    $class = ($i == $paged) ? 'page-numbers current' : 'page-numbers';
                    echo '<a class="'.esc_attr($class).'" href="'.esc_url($url).'">'.intval($i).'</a> ';
                }
                ?>
                </div>
            </div>
        <?php endif; ?>

    </div>
    <?php
}
?>