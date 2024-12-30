<?php

if (!function_exists('ekortn_log')) {
    function ekortn_log($message, $type = 'info', $output = 'file') {
        $log_file = plugin_dir_path(__FILE__) . 'ekortn.log'; // Cesta k logovacímu souboru
        $date = date('Y-m-d H:i:s');
        $formatted_message = "[$date] [$type] $message" . PHP_EOL;

        // Zpracování výstupu logu
        switch ($output) {
            case 'file':
                // Zápis do souboru
                file_put_contents($log_file, $formatted_message, FILE_APPEND);
                break;

            case 'chat':
                // Vrácení logu do chatu jako součást debug informací
                return $formatted_message;

            case 'both':
                // Zápis do souboru i do chatu
                file_put_contents($log_file, $formatted_message, FILE_APPEND);
                return $formatted_message;

            case 'none':
                // Nepoužije žádný výstup
                break;

            default:
                file_put_contents($log_file, $formatted_message, FILE_APPEND);
                break;
        }
    }
}

if (!function_exists('ekortn_log_request')) {
    function ekortn_log_request($request, $output = 'file') {
        return ekortn_log('API Request: ' . json_encode($request), 'request', $output);
    }
}

if (!function_exists('ekortn_log_response')) {
    function ekortn_log_response($response, $output = 'file') {
        return ekortn_log('API Response: ' . json_encode($response), 'response', $output);
    }
}