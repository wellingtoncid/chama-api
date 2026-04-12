<?php
namespace App\Core;

class Response {
    public static function json($data, $status = 200) {
        // Limpa qualquer output buffer pendente
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}