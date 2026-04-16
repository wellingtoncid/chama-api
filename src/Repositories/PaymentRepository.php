<?php
namespace App\Repositories;

use PDO;

class PaymentRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    // ============================================
    // PLANOS
    // ============================================

    /**
     * Busca plano por ID
     */
    public function findPlanById(int $planId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM plans WHERE id = ? AND active = 1");
        $stmt->execute([$planId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Busca todos os planos
     */
    public function findAllPlans(bool $activeOnly = true): array {
        $sql = "SELECT * FROM plans";
        if ($activeOnly) {
            $sql .= " WHERE active = 1";
        }
        $sql .= " ORDER BY price ASC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca planos por categoria
     */
    public function findPlansByCategory(string $category): array {
        $stmt = $this->db->prepare("SELECT * FROM plans WHERE category = ? AND active = 1 ORDER BY price ASC");
        $stmt->execute([$category]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ============================================
    // PRICING RULES
    // ============================================

    /**
     * Busca regra de preço por módulo e feature
     */
    public function findPricingRule(string $moduleKey, string $featureKey): ?array {
        $stmt = $this->db->prepare("SELECT * FROM pricing_rules WHERE module_key = ? AND feature_key = ? AND is_active = 1");
        $stmt->execute([$moduleKey, $featureKey]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Busca todas as regras de um módulo
     */
    public function findRulesByModule(string $moduleKey): array {
        $stmt = $this->db->prepare("SELECT * FROM pricing_rules WHERE module_key = ? AND is_active = 1 ORDER BY price_monthly ASC, price_per_use ASC");
        $stmt->execute([$moduleKey]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca todas as regras de preço
     */
    public function findAllRules(): array {
        $stmt = $this->db->query("SELECT * FROM pricing_rules WHERE is_active = 1 ORDER BY module_key, feature_key");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ============================================
    // TRANSAÇÕES
    // ============================================

    /**
     * Cria um registro inicial de transação (Status: Pending)
     */
    public function createTransaction($userId, $planId, $amount, $externalRef = null, $billingCycle = 'monthly', $durationDays = 30, $moduleKey = null, $featureKey = null) {
        $sql = "INSERT INTO transactions (user_id, plan_id, module_key, feature_key, amount, status, external_reference, billing_cycle, duration_days, created_at) 
                VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW())";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $planId, $moduleKey, $featureKey, $amount, $externalRef, $billingCycle, $durationDays]);
        return $this->db->lastInsertId();
    }

    /**
     * Atualiza o status quando o Webhook avisar que foi aprovado
     */
    public function updateStatusByExternalId($externalRef, $status) {
        $sql = "UPDATE transactions SET status = ?, updated_at = NOW() WHERE external_reference = ?";
        return $this->db->prepare($sql)->execute([$status, $externalRef]);
    }

    /**
     * Busca transação por external_reference
     */
    public function getTransactionByExternalId($externalRef) {
        $stmt = $this->db->prepare("SELECT * FROM transactions WHERE external_reference = ?");
        $stmt->execute([$externalRef]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Busca transações do usuário
     */
    public function findTransactionsByUser(int $userId, int $limit = 100): array {
        $stmt = $this->db->prepare("
            SELECT id, plan_id, module_key, feature_key, transaction_type, 
                   amount, status, created_at, approved_at
            FROM transactions 
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca transação por ID
     */
    public function findTransactionById(int $transactionId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$transactionId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Atualiza status da transação
     */
    public function updateTransactionStatus(int $id, string $status): bool {
        $approvedAt = $status === 'approved' ? ', approved_at = NOW()' : '';
        $sql = "UPDATE transactions SET status = ?{$approvedAt}, updated_at = NOW() WHERE id = ?";
        return $this->db->prepare($sql)->execute([$status, $id]);
    }

    /**
     * Atualiza transação com dados do gateway
     */
    public function updateTransactionWithGateway(int $id, string $gatewayId, string $paymentMethod): bool {
        $sql = "UPDATE transactions SET gateway_id = ?, payment_method = ?, updated_at = NOW() WHERE id = ?";
        return $this->db->prepare($sql)->execute([$gatewayId, $paymentMethod, $id]);
    }

    /**
     * Verifica se usuário tem transação aprovada para plano
     */
    public function hasApprovedPlanTransaction(int $userId, int $planId): bool {
        $stmt = $this->db->prepare("
            SELECT id FROM transactions 
            WHERE user_id = ? AND plan_id = ? AND status = 'approved' 
            LIMIT 1
        ");
        $stmt->execute([$userId, $planId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ============================================
    // MÓDULOS
    // ============================================

    /**
     * Busca módulo ativo do usuário
     */
    public function findActiveModuleForUser(int $userId, string $moduleKey): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM user_modules 
            WHERE user_id = ? AND module_key = ? AND status = 'active'
        ");
        $stmt->execute([$userId, $moduleKey]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Ativa módulo para o usuário
     */
    public function activateModule(int $userId, string $moduleKey, ?string $expiresAt = null, ?int $planId = null): bool {
        if ($expiresAt === null) {
            $sql = "INSERT INTO user_modules (user_id, module_key, status, activated_at, plan_id) 
                    VALUES (?, ?, 'active', NOW(), ?) 
                    ON DUPLICATE KEY UPDATE status = 'active', activated_at = NOW(), plan_id = ?";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([$userId, $moduleKey, $planId, $planId]);
        } else {
            $sql = "INSERT INTO user_modules (user_id, module_key, status, activated_at, expires_at, plan_id) 
                    VALUES (?, ?, 'active', NOW(), ?, ?) 
                    ON DUPLICATE KEY UPDATE status = 'active', activated_at = NOW(), expires_at = ?, plan_id = ?";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([$userId, $moduleKey, $expiresAt, $planId, $expiresAt, $planId]);
        }
    }

    /**
     * Desativa módulo do usuário
     */
    public function deactivateModule(int $userId, string $moduleKey): bool {
        $stmt = $this->db->prepare("UPDATE user_modules SET status = 'inactive', deactivated_at = NOW() WHERE user_id = ? AND module_key = ?");
        return $stmt->execute([$userId, $moduleKey]);
    }

    /**
     * Busca todos os módulos do usuário
     */
    public function findUserModules(int $userId): array {
        $stmt = $this->db->prepare("SELECT * FROM user_modules WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ============================================
    // VERIFICAÇÃO
    // ============================================

    /**
     * Aplica selo de verificación ao usuário
     */
    public function applyVerificationBadge(int $userId, string $expiresAt): bool {
        $stmt = $this->db->prepare("
            UPDATE users 
            SET is_verified = 1, verified_until = ?, verified_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([$expiresAt, $userId]);
    }

    /**
     * Verifica se usuário está verificado
     */
    public function isUserVerified(int $userId): bool {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND is_verified = 1 AND verified_until > NOW()");
        $stmt->execute([$userId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Cria documento do usuário
     */
    public function createUserDocument(int $userId, string $type, string $filePath, string $description = null): int {
        $stmt = $this->db->prepare("
            INSERT INTO user_documents (user_id, document_type, file_path, description, status, created_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$userId, $type, $filePath, $description]);
        return $this->db->lastInsertId();
    }

    /**
     * Busca documentos do usuário
     */
    public function getUserDocuments(int $userId): array {
        $stmt = $this->db->prepare("SELECT * FROM user_documents WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Deleta documento do usuário
     */
    public function deleteUserDocument(int $docId): bool {
        $stmt = $this->db->prepare("DELETE FROM user_documents WHERE id = ?");
        return $stmt->execute([$docId]);
    }

    // ============================================
    // CARTEIRA (CREDITOS)
    // ============================================

    /**
     * Busca saldo da carteira
     */
    public function getWalletBalance(int $userId): float {
        $stmt = $this->db->prepare("SELECT COALESCE(credit_balance, 0) as balance FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (float)$result['balance'] : 0;
    }

    /**
     * Debita da carteira
     */
    public function debitWallet(int $userId, float $amount, string $description): bool {
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("UPDATE users SET credit_balance = credit_balance - ? WHERE id = ? AND credit_balance >= ?");
            $stmt->execute([$amount, $userId, $amount]);
            
            if ($stmt->rowCount() === 0) {
                $this->db->rollBack();
                return false;
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO credit_transactions (user_id, amount, type, description, created_at)
                VALUES (?, ?, 'debit', ?, NOW())
            ");
            $stmt->execute([$userId, $amount, $description]);
            
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("ERRO debitWallet: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Credita na carteira
     */
    public function creditWallet(int $userId, float $amount, string $description): bool {
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("UPDATE users SET credit_balance = credit_balance + ? WHERE id = ?");
            $stmt->execute([$amount, $userId]);
            
            $stmt = $this->db->prepare("
                INSERT INTO credit_transactions (user_id, amount, type, description, created_at)
                VALUES (?, ?, 'credit', ?, NOW())
            ");
            $stmt->execute([$userId, $amount, $description]);
            
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("ERRO creditWallet: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Busca histórico de créditos
     */
    public function getCreditTransactions(int $userId, int $limit = 50): array {
        $stmt = $this->db->prepare("
            SELECT * FROM credit_transactions 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ============================================
    // FRETES (PROMOÇÕES)
    // ============================================

    /**
     * Aplica promoção de destaque a um frete
     */
    public function applyFreightFeatured(int $freightId, string $expiresAt): bool {
        $stmt = $this->db->prepare("UPDATE freights SET is_featured = 1, featured_until = ? WHERE id = ?");
        return $stmt->execute([$expiresAt, $freightId]);
    }

    /**
     * Aplica promoção de urgente a um frete
     */
    public function applyFreightUrgent(int $freightId, string $expiresAt): bool {
        $stmt = $this->db->prepare("UPDATE freights SET is_urgent = 1, urgent_until = ? WHERE id = ?");
        return $stmt->execute([$expiresAt, $freightId]);
    }

    /**
     * Busca运费 por ID e usuário
     */
    public function findFreightByIdAndUser(int $freightId, int $userId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM freights WHERE id = ? AND user_id = ?");
        $stmt->execute([$freightId, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
}