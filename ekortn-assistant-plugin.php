<?php
/*
Plugin Name: multi-ai-assistant-plugin
Description: Plugin pro komunikaci s OpenAI API a zobrazení chatu na webových stránkách. Ukládá konverzace do DB a umožňuje zobrazení v administraci.
Version: 0.0.2
Author: SmartLab@Evymo.com
*/

define('EKORTN_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Načtení ostatních souborů
require_once(EKORTN_PLUGIN_DIR . 'includes/ajax-handler.php');
require_once(EKORTN_PLUGIN_DIR . 'includes/process-query.php');
require_once(EKORTN_PLUGIN_DIR . 'includes/logger.php');
require_once(EKORTN_PLUGIN_DIR . 'includes/helper-functions.php');
require_once(EKORTN_PLUGIN_DIR . 'admin/admin-settings.php');
require_once(EKORTN_PLUGIN_DIR . 'includes/openai-functions.php');

// Registrace skriptů a stylů
function ekortn_register_assets() {
    // Frontendové skripty a styly
    wp_enqueue_script('ekortn-frontend-js', plugins_url('frontend/frontend.js', __FILE__), array('jquery'), null, true);
    wp_enqueue_style('ekortn-frontend-css', plugins_url('frontend/frontend.css', __FILE__));

    // Admin skripty a styly
    if (is_admin()) {
        wp_enqueue_script('ekortn-admin-js', plugins_url('admin/admin.js', __FILE__), array('jquery'), null, true);
        wp_enqueue_style('ekortn-admin-css', plugins_url('admin/admin.css', __FILE__));
    }

    // Lokální proměnné pro AJAX - front-end
    wp_localize_script('ekortn-frontend-js', 'ekortn_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ekortn_frontend_nonce')
    ));

    // Lokální proměnné pro AJAX - admin
    wp_localize_script('ekortn-admin-js', 'ekortn_admin', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ekortn_admin_nonce')
    ));
}
add_action('wp_enqueue_scripts', 'ekortn_register_assets');
add_action('admin_enqueue_scripts', 'ekortn_register_assets');

// Aktivace pluginu (vytvoření tabulek)
function ekortn_activate_plugin() {
    global $wpdb;
    $table_threads = $wpdb->prefix . 'ekortn_threads';
    $table_messages = $wpdb->prefix . 'ekortn_messages';
    $charset_collate = $wpdb->get_charset_collate();

    // Tabulka pro mapování vlákna na usera
    $sql_threads = "CREATE TABLE IF NOT EXISTS $table_threads (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        thread_id VARCHAR(255) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY thread_id (thread_id)
    ) $charset_collate;";

    // Tabulka pro ukládání zpráv
    $sql_messages = "CREATE TABLE IF NOT EXISTS $table_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        thread_id VARCHAR(255) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        role VARCHAR(50) NOT NULL,
        content LONGTEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY thread_id (thread_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_threads);
    dbDelta($sql_messages);
}
register_activation_hook(__FILE__, 'ekortn_activate_plugin');

// Deaktivace pluginu
function ekortn_deactivate_plugin() {
    // Pokud byste chtěl tabulky smazat, můžete to udělat zde, ale
    // pozor na ztrátu dat.
}
register_deactivation_hook(__FILE__, 'ekortn_deactivate_plugin');

// Shortcode pro chat
function ekortn_form_shortcode() {
    if (is_user_logged_in()) {
        ob_start();
        include plugin_dir_path(__FILE__) . 'frontend/chat-template.php';
        return ob_get_clean();
    } else {
        return '<p>Pro použití tohoto formuláře se prosím <a href="' . esc_url(wp_login_url()) . '">přihlaste</a> 
                nebo <a href="https://forms.office.com/r/mUTAkhAcME">se zaregistrujte do studie</a>.</p>';
    }
}
add_shortcode('ekortn_form', 'ekortn_form_shortcode');