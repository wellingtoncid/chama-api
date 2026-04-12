<?php
namespace App\Services;

use PDO;
use Exception;

class CreditService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Obtém o saldo disponível do usuário
     */
    public function getBalance(int $userId): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(balance_available, 0) as balance 
            FROM user_wallets 
            WHERE user_id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float)($result['balance'] ?? 0);
    }

    /**
     * Verifica se o usuário tem saldo suficiente
     */
    public function verifyBalance(int $userId, float $amount): bool {
        $balance = $this->getBalance($userId);
        return $balance >= $amount;
    }

    /**
     * Debita valor da carteira do usuário
     * Retorna true se sucesso, false se saldo insuficiente
     */
    public function debit(int $userId, float $amount, string $module, string $feature, ?int $referenceId = null): bool {
        if ($amount <= 0) {
            throw new Exception("Valor para débito deve ser maior que zero");
        }

        if (!$this->verifyBalance($userId, $amount)) {
            return false;
        }

        try {
            $this->db->beginTransaction();

            // Usa a mesma query do verifyBalance para evitar race conditions
            $stmt = $this->db->prepare("
                UPDATE user_wallets 
                SET balance_available = balance_available - :debit_amount,
                    updated_at = NOW()
                WHERE user_id = :user_id 
                AND balance_available >= :check_amount
            ");
            $stmt->execute([
                ':debit_amount' => $amount,
                ':check_amount' => $amount,
                ':user_id' => $userId
            ]);

            if ($stmt->rowCount() === 0) {
                $this->db->rollBack();
                return false;
            }

            $description = "Débito: {$module} - {$feature}";
            if ($referenceId) {
                $description .= " (Ref: {$referenceId})";
            }

            $stmt = $this->db->prepare("
                INSERT INTO transactions 
                (user_id, module_key, feature_key, amount, status, transaction_type, gateway_payload, created_at)
                VALUES (:user_id, :module, :feature, :amount, 'approved', 'wallet_debit', :description, NOW())
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':module' => $module,
                ':feature' => $feature,
                ':amount' => -$amount,
                ':description' => json_encode(['description' => $description])
            ]);

            $this->db->commit();
            return true;

        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Erro ao debitar carteira: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Credita valor na carteira do usuário
     */
    public function credit(int $userId, float $amount, string $description = 'Crédito via PIX'): bool {
        if ($amount <= 0) {
            throw new Exception("Valor para crédito deve ser maior que zero");
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                UPDATE user_wallets 
                SET balance_available = balance_available + :amount,
                    updated_at = NOW()
                WHERE user_id = :user_id
            ");
            $stmt->execute([
                ':amount' => $amount,
                ':user_id' => $userId
            ]);

            if ($stmt->rowCount() === 0) {
                $stmt = $this->db->prepare("
                    INSERT INTO user_wallets (user_id, balance_available, updated_at)
                    VALUES (:user_id, :amount, NOW())
                ");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':amount' => $amount
                ]);
            }

            $stmt = $this->db->prepare("
                INSERT INTO transactions 
                (user_id, module_key, feature_key, amount, status, transaction_type, gateway_payload, created_at)
                VALUES (:user_id, 'wallet', 'recharge', :amount, 'approved', 'wallet_recharge', :description, NOW())
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':amount' => $amount,
                ':description' => json_encode(['description' => $description])
            ]);

            $this->db->commit();
            return true;

        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Erro ao creditar carteira: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Obtém histórico de transações do usuário
     */
    public function getTransactions(int $userId, int $limit = 50): array {
        $stmt = $this->db->prepare("
            SELECT * FROM transactions 
            WHERE user_id = :user_id 
            ORDER BY created_at DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtém o preço de um recurso na pricing_rules
     */
    public function getPrice(string $moduleKey, string $featureKey): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM pricing_rules 
            WHERE module_key = :module AND feature_key = :feature AND is_active = 1
        ");
        $stmt->execute([
            ':module' => $moduleKey,
            ':feature' => $featureKey
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Calcula o valor a cobrar considerando o que já foi pago
     * Usado para upgrades (ex: adicionar destaque em freight já publicado)
     */
    public function calculateUpgradeCost(string $moduleKey, string $newFeature, ?string $currentFeature = null): float {
        $newPrice = $this->getPrice($moduleKey, $newFeature);
        if (!$newPrice) {
            throw new Exception("Preço não encontrado para {$moduleKey} - {$newFeature}");
        }

        $newAmount = (float)$newPrice['price_per_use'];

        if ($currentFeature === null) {
            return $newAmount;
        }

        $currentPrice = $this->getPrice($moduleKey, $currentFeature);
        if (!$currentPrice) {
            return $newAmount;
        }

        $currentAmount = (float)$currentPrice['price_per_use'];

        $diff = $newAmount - $currentAmount;
        return max(0, $diff);
    }

    /**
     * Inicializa carteira para novo usuário
     */
    public function initializeWallet(int $userId): bool {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_wallets (user_id, balance_available, updated_at)
                VALUES (:user_id, 0, NOW())
                ON DUPLICATE KEY UPDATE updated_at = NOW()
            ");
            $stmt->execute([':user_id' => $userId]);
            return true;
        } catch (Exception $e) {
            error_log("Erro ao inicializar carteira: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Estorna valor para a carteira do usuário
     * Usado quando uma transação é rejeitada/cancelada
     */
    public function refund(int $userId, float $amount, string $reason = 'Estorno'): bool {
        if ($amount <= 0) {
            throw new Exception("Valor para estorno deve ser maior que zero");
        }

        try {
            $this->db->beginTransaction();

            // Credita o valor na carteira
            $stmt = $this->db->prepare("
                UPDATE user_wallets 
                SET balance_available = balance_available + :amount,
                    updated_at = NOW()
                WHERE user_id = :user_id
            ");
            $stmt->execute([
                ':amount' => $amount,
                ':user_id' => $userId
            ]);

            // Se não encontrou carteira, cria uma com o saldo
            if ($stmt->rowCount() === 0) {
                $stmt = $this->db->prepare("
                    INSERT INTO user_wallets (user_id, balance_available, updated_at)
                    VALUES (:user_id, :amount, NOW())
                ");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':amount' => $amount
                ]);
            }

            // Registra a transação de estorno
            $description = "Estorno: {$reason}";
            
            $stmt = $this->db->prepare("
                INSERT INTO transactions 
                (user_id, module_key, feature_key, amount, status, transaction_type, gateway_payload, created_at)
                VALUES (:user_id, 'wallet', 'refund', :amount, 'approved', 'wallet_refund', :description, NOW())
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':amount' => $amount,
                ':description' => json_encode(['description' => $description, 'reason' => $reason])
            ]);

            $this->db->commit();
            error_log("REFUND: Usuario {$userId} recebeu estorno de R$ {$amount}. Motivo: {$reason}");
            return true;

        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Erro ao estornar carteira: " . $e->getMessage());
            throw $e;
        }
    }
}
