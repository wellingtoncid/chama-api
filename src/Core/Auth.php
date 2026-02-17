<?php
namespace App\Core;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class Auth {
    /**
     * Cache estático para não decodificar o token mais de uma vez por request
     */
    public static function getAuthenticatedUser() {
        static $user = null;
        if ($user !== null) return $user;

        $authHeader = self::getAuthorizationHeader();

        // Regex aceita 'Bearer' com case-insensitive
        if (!$authHeader || !preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
            return null;
        }

        try {
            $token = $matches[1];
            $secret = $_ENV['JWT_SECRET'] ?? 'chave_mestra_segura_2026';
            
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            
            // Converter objeto stdClass para Array de forma recursiva
            $decodedArray = json_decode(json_encode($decoded), true);
            
            // O payload do JWT costuma ter os dados em 'data' ou na raiz
            $userData = $decodedArray['data'] ?? $decodedArray;
            
            // Fallback para o ID (padrão 'sub' do JWT)
            if (!isset($userData['id']) && isset($decodedArray['sub'])) {
                $userData['id'] = $decodedArray['sub'];
            }

            // Garante que o ID seja sempre inteiro para evitar bugs de comparação
            if (isset($userData['id'])) {
                $userData['id'] = (int)$userData['id'];
            }

            $user = $userData;
            return $user;
            
        } catch (Exception $e) {
            // Log opcional para debug em desenvolvimento:
            // error_log("JWT Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Helper resiliente para capturar o Header
     */
    private static function getAuthorizationHeader() {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            // Verifica variações comuns de case
            foreach (['Authorization', 'authorization'] as $key) {
                if (!empty($headers[$key])) return $headers[$key];
            }
        }
        return $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    }

    public static function userId() {
        $user = self::getAuthenticatedUser();
        return isset($user['id']) ? (int)$user['id'] : null;
    }

    /**
     * Verifica se o usuário tem uma função específica
     * Ex: Auth::hasRole('driver') ou Auth::hasRole('company')
     */
    public static function hasRole($role) {
        $user = self::getAuthenticatedUser();
        
        // Verificamos se o usuário existe e se o campo role está preenchido
        if (!$user || empty($user['role'])) {
            return false;
        }

        // Comparamos o slug guardado no token com o solicitado (ex: 'admin')
        return strtolower(trim($user['role'])) === strtolower(trim($role));
    }

    public static function requireAuth() {
        $user = self::getAuthenticatedUser();
        if (!$user) {
            // Garante que a resposta seja JSON limpo
            if (!headers_sent()) {
                header('Content-Type: application/json');
                http_response_code(401);
            }
            echo json_encode([
                'success' => false, 
                'message' => 'Sessão inválida ou expirada.'
            ]);
            exit;
        }
        return $user;
    }
}