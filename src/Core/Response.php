<?php

namespace App\Core;

class Response
{
    /**
     * Envia resposta JSON padronizada.
     *
     * Convenção:
     * - Sucesso: { "success": true, "data": ..., "message": "..." }  (200)
     * - Erro:    { "success": false, "message": "..." }              (400+)
     * - Se $status omitido e success=false, assume 400 automaticamente.
     */
    public static function json($data, $status = null)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        if ($status === null) {
            $status = (isset($data['success']) && $data['success'] === false) ? 400 : 200;
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
