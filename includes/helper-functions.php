<?php
// Pomocné funkce, které mohou být sdílené napříč pluginem
function ekortn_sanitize_array($array) {
    foreach ($array as $key => $value) {
        $array[$key] = sanitize_text_field($value);
    }
    return $array;
}