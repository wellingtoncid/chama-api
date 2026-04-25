<?php
namespace App\Core;

use PDO;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class Auth {
    private static ?array $user = null;
    private static ?PDO $db = null;

    private static function getDb(): PDO {
        if (self::$db === null) {
            self::$db = Database::getConnection();
        }
        return self::$db;
    }

    public static function getAuthenticatedUser() {
        if (self::$user !== null) return self::$user;

        $authHeader = self::getAuthorizationHeader();

        if (!$authHeader || !preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
            return null;
        }

        try {
            $token = $matches[1];
            $secret = $_ENV['JWT_SECRET'] ?? 'chave_mestra_segura_2026';
            
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            $decodedArray = json_decode(json_encode($decoded), true);
            $userData = $decodedArray['data'] ?? $decodedArray;
            
            if (!isset($userData['id']) && isset($decodedArray['sub'])) {
                $userData['id'] = $decodedArray['sub'];
            }

            if (isset($userData['id'])) {
                $userData['id'] = (int)$userData['id'];
            }

            self::$user = $userData;
            return self::$user;
            
        } catch (Exception $e) {
            error_log("JWT Error: " . $e->getMessage());
            return null;
        }
    }

    private static function getAuthorizationHeader() {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach (['Authorization', 'authorization'] as $key) {
                if (!empty($headers[$key])) return $headers[$key];
            }
        }
        return $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    }

    public static function userId(): ?int {
        $user = self::getAuthenticatedUser();
        return isset($user['id']) ? (int)$user['id'] : null;
    }

    public static function hasRole($role): bool {
        $user = self::getAuthenticatedUser();
        if (!$user || empty($user['role'])) {
            return false;
        }
        return strtolower(trim($user['role'])) === strtolower(trim($role));
    }

    public static function hasAnyRole(array $roles): bool {
        foreach ($roles as $role) {
            if (self::hasRole($role)) {
                return true;
            }
        }
        return false;
    }

    public static function hasPermission(string $permission, ?int $userId = null): bool {
        $user = self::getAuthenticatedUser();
        if (!$user) {
            return false;
        }

        $targetUserId = $userId ?? ($user['id'] ?? null);
        if (!$targetUserId) {
            return false;
        }

        try {
            $stmt = self::getDb()->prepare("
                SELECT u.role, u.permissions, r.id as role_id
                FROM users u
                LEFT JOIN roles r ON r.slug = u.role
                WHERE u.id = ?
            ");
            $stmt->execute([$targetUserId]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$userData) {
                return false;
            }

            if (strtolower($userData['role']) === 'admin') {
                return true;
            }

            $userPerms = $userData['permissions'] ? json_decode($userData['permissions'], true) : [];
            if (in_array($permission, $userPerms) || in_array('all', $userPerms)) {
                return true;
            }

            if ($userData['role_id']) {
                $stmt = self::getDb()->prepare("
                    SELECT 1 FROM role_permissions rp
                    JOIN permissions p ON p.id = rp.permission_id
                    WHERE rp.role_id = ? AND p.slug = ?
                    LIMIT 1
                ");
                $stmt->execute([$userData['role_id'], $permission]);
                if ($stmt->fetch()) {
                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            error_log("Permission check error: " . $e->getMessage());
            return false;
        }
    }

    public static function hasModule(string $moduleKey, ?int $userId = null): bool {
        $user = self::getAuthenticatedUser();
        if (!$user) {
            return false;
        }

        $targetUserId = $userId ?? ($user['id'] ?? null);
        if (!$targetUserId) {
            return false;
        }

        try {
            $db = self::getDb();
            
            $stmt = $db->prepare("
                SELECT status FROM user_modules
                WHERE user_id = ? AND module_key = ?
            ");
            $stmt->execute([$targetUserId, $moduleKey]);
            $module = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($module && $module['status'] === 'active') {
                return true;
            }

            $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$targetUserId]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$userData) {
                return false;
            }

            $role = strtolower($userData['role']);
            
            if ($role === 'admin') {
                return true;
            }
            
            $stmt = $db->prepare("
                SELECT is_required, default_for FROM modules 
                WHERE module_key = ?
            ");
            $stmt->execute([$moduleKey]);
            $moduleConfig = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($moduleConfig) {
                if ((int)$moduleConfig['is_required'] === 1) {
                    return true;
                }
                
                $defaults = $moduleConfig['default_for'] 
                    ? json_decode($moduleConfig['default_for'], true) 
                    : [];
                if (is_array($defaults) && in_array($role, $defaults)) {
                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            error_log("Module check error: " . $e->getMessage());
            return false;
        }
    }

    public static function requireAuth(): array {
        $user = self::getAuthenticatedUser();
        if (!$user) {
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

    public static function requireRole($roles): array {
        $user = self::requireAuth();
        $roles = is_array($roles) ? $roles : [$roles];
        
        if (!self::hasAnyRole($roles)) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
                http_response_code(403);
            }
            echo json_encode([
                'success' => false, 
                'message' => 'Acesso negado. Permissão insuficiente.'
            ]);
            exit;
        }
        return $user;
    }

    public static function requirePermission(string $permission): array {
        $user = self::requireAuth();
        
        if (!self::hasPermission($permission)) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
                http_response_code(403);
            }
            echo json_encode([
                'success' => false, 
                'message' => 'Acesso negado. Você não tem permissão para esta ação.'
            ]);
            exit;
        }
        return $user;
    }
}