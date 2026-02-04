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
     */
    public function createTransaction($userId, $planId, $amount, $externalId = null) {
        $sql = "INSERT INTO transactions (user_id, plan_id, amount, status, external_id, created_at) 
                VALUES (?, ?, ?, 'pending', ?, NOW())";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $planId, $amount, $externalId]);
        return $this->db->lastInsertId();
    }

    /**
     * Atualiza o status quando o Webhook avisar que foi aprovado
     */
    public function updateStatusByExternalId($externalId, $status) {
        $sql = "UPDATE transactions SET status = ?, updated_at = NOW() WHERE external_id = ?";
        return $this->db->prepare($sql)->execute([$status, $externalId]);
    }

    /**
     * Busca o ID do usuário e do plano vinculado a um pagamento externo
     */
    public function getTransactionByExternalId($externalId) {
        $stmt = $this->db->prepare("SELECT * FROM transactions WHERE external_id = ?");
        $stmt->execute([$externalId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}