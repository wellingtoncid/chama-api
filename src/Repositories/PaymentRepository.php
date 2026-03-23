<?php
namespace App\Repositories;

use PDO;

class PaymentRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Cria um registro inicial de transação (Status: Pending)
     * Suporta billing_cycle e duration_days para planos com ciclos
     */
    public function createTransaction($userId, $planId, $amount, $externalRef = null, $billingCycle = 'monthly', $durationDays = 30) {
        $sql = "INSERT INTO transactions (user_id, plan_id, amount, status, external_reference, billing_cycle, duration_days, created_at) 
                VALUES (?, ?, ?, 'pending', ?, ?, ?, NOW())";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $planId, $amount, $externalRef, $billingCycle, $durationDays]);
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
     * Busca o ID do usuário e do plano vinculado a um pagamento externo
     */
    public function getTransactionByExternalId($externalRef) {
        $stmt = $this->db->prepare("SELECT * FROM transactions WHERE external_reference = ?");
        $stmt->execute([$externalRef]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}