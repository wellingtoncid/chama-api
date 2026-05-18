<?php

namespace App\Repositories;

use PDO;

class MembershipRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function getUserSubscription(int $userId)
    {
        $stmt = $this->db->prepare('
            SELECT plan_type, is_subscriber, subscription_expires_at
            FROM users WHERE id = ?
        ');
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getActiveAds(int $userId)
    {
        $stmt = $this->db->prepare("
            SELECT id, title, expires_at, status
            FROM ads
            WHERE user_id = ? AND status = 'active' AND expires_at > NOW()
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Marca como 'cancelled' transações pendentes com mais de 24h
     * (checkout abandonado no MercadoPago)
     */
    public function expireAbandonedPending(int $userId): void
    {
        $stmt = $this->db->prepare("
            UPDATE transactions
            SET status = 'cancelled',
                gateway_error_message = 'Pagamento abandonado (expirado automaticamente)',
                updated_at = NOW()
            WHERE user_id = :user_id
              AND status = 'pending'
              AND created_at < NOW() - INTERVAL 24 HOUR
        ");
        $stmt->execute([':user_id' => $userId]);

        // Remove duplicatas órfãs (antigo bug: WalletController criava 2 transações)
        // Cancela pendentes sem transaction_type que são redundantes com wallet_recharge
        // Nenhuma transação legítima tem transaction_type+module_key+feature_key tudo NULL
        $stmt = $this->db->prepare("
            UPDATE transactions
            SET status = 'cancelled',
                gateway_error_message = 'Transação duplicada (limpeza automática)',
                updated_at = NOW()
            WHERE user_id = :user_id
              AND status = 'pending'
              AND (transaction_type IS NULL OR transaction_type = '')
              AND (module_key IS NULL OR module_key = '')
              AND (feature_key IS NULL OR feature_key = '')
        ");
        $stmt->execute([':user_id' => $userId]);
    }

    public function getTransactions(int $userId)
    {
        $stmt = $this->db->prepare('
            SELECT t.*, p.name as plan_name
            FROM transactions t
            LEFT JOIN plans p ON t.plan_id = p.id
            WHERE t.user_id = ?
              AND NOT (
                t.status = \'pending\'
                AND (t.transaction_type IS NULL OR t.transaction_type = \'\')
                AND (t.module_key IS NULL OR t.module_key = \'\')
                AND (t.feature_key IS NULL OR t.feature_key = \'\')
              )
            ORDER BY t.created_at DESC
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
