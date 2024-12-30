<?php
/*
Plugin Name: multi-ai-assistant-plugin
Description: Plugin pro komunikaci s OpenAI API a zobrazení chatu na webových stránkách.
Version: 0.0.1
Author: SmartLab@Evymo.com
*/

// Definice konstant
define('EKORTN_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Načtení potřebných souborů
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

    // Lokální proměnné pro AJAX
    wp_localize_script('ekortn-frontend-js', 'ekortn_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ekortn_frontend_nonce') // Unikátní nonce pro frontend
    ));

    wp_localize_script('ekortn-admin-js', 'ekortn_admin', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ekortn_admin_nonce') // Unikátní nonce pro administraci
    ));
}
add_action('wp_enqueue_scripts', 'ekortn_register_assets');
add_action('admin_enqueue_scripts', 'ekortn_register_assets');

// Aktivace pluginu
function ekortn_activate_plugin() {
    // Inicializace nastavení, databázových tabulek atd.
}
register_activation_hook(__FILE__, 'ekortn_activate_plugin');

// Deaktivace pluginu
function ekortn_deactivate_plugin() {
    // Čištění po pluginu, odstranění nastavení atd.
}
register_deactivation_hook(__FILE__, 'ekortn_deactivate_plugin');

// Registrace shortcode pro zobrazení chatu na stránkách
function ekortn_form_shortcode() {
    if (is_user_logged_in()) {
        // Uživatel je přihlášen, zobrazí se chat
        ob_start();
        include plugin_dir_path(__FILE__) . 'frontend/chat-template.php';
        return ob_get_clean();
    } else {
        // Uživatel není přihlášen, zobrazí se výzva k přihlášení nebo registraci
        return '<p>Pro použití tohoto formuláře se prosím <a href="' . esc_url(wp_login_url()) . '">přihlaste</a> nebo <a href="https://forms.office.com/r/mUTAkhAcME">se zaregistrujte do studie</a>.</p>';
    }
}
add_shortcode('ekortn_form', 'ekortn_form_shortcode');