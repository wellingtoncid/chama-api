<?php
namespace App\Services;

use PDO;
use Exception;

class AccessControlService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Verifica se usuário pode publicar no módulo especificado
     * Retorna: ['allowed' => bool, 'reason' => string, 'limit' => int, 'used' => int, 'remaining' => int]
     */
    public function canPublish(int $userId, string $moduleKey): array {
        $moduleToCategory = [
            'freights' => 'freight_subscription',
            'marketplace' => 'marketplace_subscription'
        ];
        
        $category = $moduleToCategory[$moduleKey] ?? $moduleKey;
        
        // 1. Buscar plano ativo do usuário para este módulo
        $plan = $this->getActivePlan($userId, $category);
        
        // 2. Obter limite do plano ou fallback do pricing_rules
        $limit = 0;
        $hasPlan = false;
        
        if ($plan) {
            $hasPlan = true;
            $limit = (int)($plan['limit_monthly'] ?? 0);
        } else {
            // Fallback: busca free_limit das pricing_rules
            $limit = $this->getPricingRuleFreeLimit($moduleKey);
        }
        
        // 3. Se não tem plano E não tem free_limit → precisa de plano
        if (!$hasPlan && $limit === 0) {
            return [
                'allowed' => false,
                'reason' => 'Você precisa de um plano ativo para publicar neste módulo.',
                'requires_plan' => true,
                'limit' => 0,
                'used' => 0,
                'remaining' => 0,
                'module_key' => $moduleKey,
                'category' => $category
            ];
        }
        
        // 4. Contar uso atual no mês
        $used = $this->getCurrentMonthUsage($userId, $moduleKey);
        
        // 5. Verificar se excedeu
        if ($used >= $limit && $limit > 0) {
            return [
                'allowed' => false,
                'reason' => "Limite de {$limit} publicações/mês atingido. Upgrade seu plano para continuar publicando.",
                'requires_plan' => false,
                'requires_upgrade' => true,
                'limit' => $limit,
                'used' => $used,
                'remaining' => 0,
                'plan_name' => $plan['name'] ?? null,
                'module_key' => $moduleKey,
                'category' => $category
            ];
        }
        
        // 6. Permite publicação
        return [
            'allowed' => true,
            'reason' => 'Publicação permitida',
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit > 0 ? $limit - $used : -1,
            'plan_name' => $plan['name'] ?? null,
            'module_key' => $moduleKey,
            'category' => $category
        ];
    }

    /**
     * Registra uso de publicação
     */
    public function recordUsage(int $userId, string $moduleKey, int $referenceId, string $referenceType): void {
        $usageMonth = (int)date('n');
        $usageYear = (int)date('Y');
        
        $stmt = $this->db->prepare("
            INSERT INTO user_usage (user_id, module_key, reference_id, reference_type, usage_month, usage_year, usage_count, created_at, updated_at)
            VALUES (:user_id, :module_key, :reference_id, :reference_type, :usage_month, :usage_year, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE usage_count = usage_count + 1, updated_at = NOW()
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':module_key' => $moduleKey,
            ':reference_id' => $referenceId,
            ':reference_type' => $referenceType,
            ':usage_month' => $usageMonth,
            ':usage_year' => $usageYear
        ]);
    }

    /**
     * Obtém estatísticas de uso do usuário
     */
    public function getUsageStats(int $userId, string $moduleKey): array {
        $used = $this->getCurrentMonthUsage($userId, $moduleKey);
        
        $moduleToCategory = [
            'freights' => 'freight_subscription',
            'marketplace' => 'marketplace_subscription'
        ];
        
        $category = $moduleToCategory[$moduleKey] ?? $moduleKey;
        $plan = $this->getActivePlan($userId, $category);
        
        // Se tem plano, usa limite do plano; senão, usa pricing_rules.free_limit como fallback
        $limit = 0;
        if ($plan) {
            $limit = (int)($plan['limit_monthly'] ?? 0);
        } else {
            // Fallback: busca free_limit das pricing_rules
            $limit = $this->getPricingRuleFreeLimit($moduleKey);
        }
        
        return [
            'module_key' => $moduleKey,
            'month' => (int)date('n'),
            'year' => (int)date('Y'),
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit > 0 ? $limit - $used : -1,
            'plan_name' => $plan['name'] ?? null
        ];
    }

    /**
     * Busca free_limit das pricing_rules
     */
    private function getPricingRuleFreeLimit(string $moduleKey): int {
        // Mapear para feature_key padrão
        $featureKey = $moduleKey === 'freights' ? 'publish' : 'publish_listing';
        
        $stmt = $this->db->prepare("
            SELECT free_limit FROM pricing_rules 
            WHERE module_key = :module_key 
            AND feature_key = :feature_key 
            AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([
            ':module_key' => $moduleKey,
            ':feature_key' => $featureKey
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['free_limit'] ?? 0);
    }

    /**
     * Busca plano ativo do usuário
     */
    private function getActivePlan(int $userId, string $category): ?array {
        $stmt = $this->db->prepare("
            SELECT p.*, um.expires_at as plan_expires_at
            FROM user_modules um
            JOIN plans p ON p.id = um.plan_id
            WHERE um.user_id = :user_id
            AND p.category = :category
            AND p.active = 1
            AND (um.expires_at IS NULL OR um.expires_at >= NOW())
            ORDER BY um.id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':category' => $category
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Conta publicações do mês atual
     */
    private function getCurrentMonthUsage(int $userId, string $moduleKey): int {
        $usageMonth = (int)date('n');
        $usageYear = (int)date('Y');
        
        // Mapear module_key para tabela
        $tableMap = [
            'freights' => 'freights',
            'marketplace' => 'listings'
        ];
        
        $table = $tableMap[$moduleKey] ?? $moduleKey;
        $userColumn = 'user_id';
        
        // Verificar se é freight ou listing
        if ($moduleKey === 'freights') {
            $sql = "SELECT COUNT(*) as total FROM freights 
                   WHERE user_id = :user_id 
                   AND MONTH(created_at) = :month 
                   AND YEAR(created_at) = :year
                   AND deleted_at IS NULL";
        } else {
            $sql = "SELECT COUNT(*) as total FROM listings 
                   WHERE user_id = :user_id 
                   AND MONTH(created_at) = :month 
                   AND YEAR(created_at) = :year
                   AND status != 'rejected'";
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':month' => $usageMonth,
            ':year' => $usageYear
        ]);
        
        return (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    }
}