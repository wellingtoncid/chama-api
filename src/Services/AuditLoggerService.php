<?php
namespace App\Services;

use PDO;

class AuditLoggerService {
    private PDO $db;
    
    private const IGNORED_PATTERNS = [
        '/api/login',
        '/api/register',
        '/api/reset-password',
        '/api/change-password',
        '/api/refresh-token',
        '/api/health',
        '/api/ping',
        
        // Leituras (GET)
        '/api/user/modules',
        '/api/plans',
        '/api/pricing/rules',
        '/api/ad-positions',
        '/api/site-settings',
        '/api/public/',
        '/api/user/usage',
        '/api/company/summary',
        '/api/get-my-profile',
        '/api/freights/available',
        '/api/freights/search',
        '/api/marketplace/',
        '/api/vendas',
        '/api/notifications',
        '/api/chat/',
        
        // Admin - Listagens (GET)
        '/api/admin-dashboard-data',
        '/api/admin/home-stats',
        '/api/admin/bi-stats',
        '/api/admin-user-details',
        '/api/admin-company-members',
        '/api/admin-portal-requests',
        '/api/admin-list-freights',
        '/api/admin/quotes',
        '/api/admin-lead-history',
        '/api/ad/check-eligibility',
        
        // Listagens públicas
        '/api/freights',
        '/api/list-freights',
        '/api/my-active-freight',
        '/api/top-ads-freight',
        '/api/freight-tracking',
        '/api/freight/:id',
        '/api/ads',
        '/api/my-ads',
        '/api/ads/report/:id',
        '/api/ads/my-report',
        '/api/listings',
        '/api/my-listings',
        '/api/listing/:id',
        '/api/listing-categories',
        '/api/listing-category/:id',
        '/api/articles',
        '/api/articles/admin/all',
        '/api/articles/admin/pending',
        '/api/article-author-requests',
        '/api/article-author-requests/pending',
        '/api/unread-count',
        '/api/user-notes',
    ];
    
    private const TARGET_TYPE_MAPPING = [
        // Freight / Cargas
        'freights' => 'FREIGHT',
        'freight' => 'FREIGHT',
        'create-freight' => 'FREIGHT',
        'update-freight' => 'FREIGHT',
        'delete-freight' => 'FREIGHT',
        'finish-freight' => 'FREIGHT',
        'promote-freight' => 'FREIGHT',
        'manage-freights' => 'FREIGHT',
        'admin-list-freights' => 'FREIGHT',
        'admin-update-freight' => 'FREIGHT',
        
        // Users
        'users' => 'USER',
        'user' => 'USER',
        'register' => 'USER',
        'update-profile' => 'USER',
        'upload-avatar' => 'USER',
        'upload-banner' => 'USER',
        'upload-image' => 'USER',
        'admin-manage-user' => 'USER',
        'admin-create-user' => 'USER',
        'admin-create-internal-user' => 'USER',
        'admin-update-user' => 'USER',
        'admin-delete-user' => 'USER',
        'admin-verify-user' => 'USER',
        
        // Accounts
        'accounts' => 'ACCOUNT',
        
        // Transactions
        'transactions' => 'TRANSACTION',
        'transaction' => 'TRANSACTION',
        'checkout' => 'TRANSACTION',
        
        // Quotes / Cotações
        'quotes' => 'QUOTE',
        'cotacoes' => 'QUOTE',
        'create-quote' => 'QUOTE',
        'respond-quote' => 'QUOTE',
        
        // Leads
        'leads' => 'LEAD',
        'lead' => 'LEAD',
        'admin-portal-requests' => 'LEAD',
        'admin-update-lead' => 'LEAD',
        
        // Ads / Anúncios
        'ads' => 'AD',
        'ad' => 'AD',
        'upload-ad' => 'AD',
        'ads-save' => 'AD',
        
        // Marketplace / Listings
        'listings' => 'LISTING',
        'listing' => 'LISTING',
        'create-listing' => 'LISTING',
        'update-listing' => 'LISTING',
        'delete-listing' => 'LISTING',
        'listing-boost' => 'LISTING',
        'listing-extend' => 'LISTING',
        
        // Groups
        'groups' => 'GROUP',
        'group' => 'GROUP',
        'create-group' => 'GROUP',
        'upload-group-image' => 'GROUP',
        
        // Messages
        'messages' => 'MESSAGE',
        
        // Reviews
        'reviews' => 'REVIEW',
        
        // Articles
        'articles' => 'ARTICLE',
        'article' => 'ARTICLE',
        'submit-article' => 'ARTICLE',
        
        // Authors
        'authors' => 'AUTHOR',
        'author' => 'AUTHOR',
        'article-author-requests' => 'AUTHOR_REQUEST',
        
        // Categories
        'categories' => 'CATEGORY',
        'listing-categories' => 'CATEGORY',
        'listing-category' => 'CATEGORY',
        
        // Modules
        'modules' => 'MODULE',
        'user-modules' => 'MODULE',
        'request-module-access' => 'MODULE',
        
        // Roles
        'roles' => 'ROLE',
        
        // Permissions
        'permissions' => 'PERMISSION',
        
        // Plans
        'plans' => 'PLAN',
        
        // Settings
        'settings' => 'SETTING',
        'site-settings' => 'SETTING',
        
        // Verifications
        'verifications' => 'VERIFICATION',
        
        // Support
        'support' => 'TICKET',
        'tickets' => 'TICKET',
        'my-tickets' => 'TICKET',
        
        // Access
        'access' => 'ACCESS',
        
        // Notifications
        'notifications' => 'NOTIFICATION',
        'mark-as-read' => 'NOTIFICATION',
        'mark-all-read' => 'NOTIFICATION',
    ];

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function shouldAudit(string $method, ?array $user, string $uri): bool {
        if (!$user || !isset($user['id'])) {
            return false;
        }
        
        if (!in_array($method, ['POST', 'PUT', 'DELETE'])) {
            return false;
        }
        
        // Normalizar URI para buscar sem /api/ e sem IDs
        $normalizedUri = preg_replace('#/api/[a-zA-Z0-9_-]+/[0-9]+#', '/api/id', $uri);
        $normalizedUri = rtrim($normalizedUri, '/');
        
        foreach (self::IGNORED_PATTERNS as $pattern) {
            $patternNorm = rtrim($pattern, '/:');
            $patternNorm = preg_replace('#/api/[a-zA-Z0-9_-]+#', '/api', $patternNorm);
            if ($normalizedUri === $patternNorm || strpos($normalizedUri, $patternNorm) !== false) {
                return false;
            }
        }
        
        return true;
    }

    public function extractTargetType(string $uri): string {
        $uri = str_replace('/api/', '', $uri);
        $parts = explode('/', $uri);
        
        // Tentar encontrar no mapping primeiro com URI completo
        $fullUri = str_replace('/', '-', $uri);
        if (isset(self::TARGET_TYPE_MAPPING[$fullUri])) {
            return self::TARGET_TYPE_MAPPING[$fullUri];
        }
        
        // Pegar primeiro segmento
        $resource = $parts[0] ?? '';
        
        // Se tiver 2+ partes, tentar combinar (ex: "manage-freights")
        if (count($parts) >= 2) {
            $combined = $parts[0] . '-' . $parts[1];
            if (isset(self::TARGET_TYPE_MAPPING[$combined])) {
                return self::TARGET_TYPE_MAPPING[$combined];
            }
        }
        
        return self::TARGET_TYPE_MAPPING[strtolower($resource)] ?? strtoupper($resource);
    }

    public function extractTargetId(string $uri): ?int {
        $uri = str_replace('/api/', '', $uri);
        $parts = explode('/', $uri);
        
        // Iterar partes de trás para frente para encontrar primeiro número
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $part = $parts[$i];
            // Ignorar IDs em rotas como :id
            if (strpos($part, ':') === 0) {
                continue;
            }
            if (is_numeric($part) && (int)$part > 0) {
                return (int)$part;
            }
        }
        
        return null;
    }

    public function prepareAuditData(string $method, string $uri, ?array $user, array $data): ?array {
        if (!$this->shouldAudit($method, $user, $uri)) {
            return null;
        }
        
        $targetType = $this->extractTargetType($uri);
        $targetId = $this->extractTargetId($uri);
        $actionType = $method . '_' . strtoupper($targetType);
        
        $safeData = $this->filterSensitiveData($data);
        
        return [
            'user_id' => $user['id'],
            'user_name' => $user['name'] ?? ($user['email'] ?? 'Unknown'),
            'action_type' => $actionType,
            'description' => "{$method} {$uri}",
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'action_url' => $uri,
            'new_values' => json_encode($safeData),
        ];
    }

    private function filterSensitiveData(array $data): array {
        $sensitiveFields = ['password', 'token', 'secret', 'key', 'authorization', 'api_key', 'credit_card', 'new_password', 'old_password'];
        
        foreach ($sensitiveFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = '***FILTERED***';
            }
        }
        
        return $data;
    }

    public function log(array $auditData): bool {
        if (!$auditData) {
            return false;
        }
        
        error_log("AUDIT_INSERIR: action=" . ($auditData['action_type'] ?? 'none') . " user=" . ($auditData['user_id'] ?? 'none'));
        
        try {
            $sql = "INSERT INTO logs_auditoria 
                (user_id, user_name, action_type, description, target_id, target_type, ip_address, user_agent, action_url, new_values, created_at) 
                VALUES (:user_id, :user_name, :action_type, :description, :target_id, :target_type, :ip_address, :user_agent, :action_url, :new_values, NOW())";
            
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                ':user_id' => $auditData['user_id'],
                ':user_name' => $auditData['user_name'],
                ':action_type' => $auditData['action_type'],
                ':description' => $auditData['description'],
                ':target_id' => $auditData['target_id'],
                ':target_type' => $auditData['target_type'],
                ':ip_address' => $auditData['ip_address'],
                ':user_agent' => $auditData['user_agent'],
                ':action_url' => $auditData['action_url'],
                ':new_values' => $auditData['new_values'],
            ]);
            
            error_log("AUDIT_RESULT: " . ($result ? 'OK' : 'FALHA'));
            return $result;
        } catch (\Exception $e) {
            error_log("AUDIT_ERRO: " . $e->getMessage());
            return false;
        }
    }
}
