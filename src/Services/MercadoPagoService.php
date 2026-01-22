<?php
namespace App\Services;

use Exception;

class MercadoPagoService {
    private $db;
    
    public function __construct($db) { 
        $this->db = $db; 
    }

    public function createPreference($data, $user) {
        $access_token = $_ENV['MP_ACCESS_TOKEN'] ?? null;
        $baseUrl = $_ENV['BASE_URL'] ?? 'https://chamafrete.com.br';

        if (!$access_token) return ["error" => "Configuração ausente"];

        $plan_id = isset($data['plan_id']) ? (int)$data['plan_id'] : null;
        $freight_id = isset($data['freight_id']) ? (int)$data['freight_id'] : null;
        $listing_id = isset($data['listing_id']) ? (int)$data['listing_id'] : null;
        $amount = (float)$data['amount'];
        $title = $data['title'] ?? "Pagamento Chama Frete";

        // 1. Registra a transação unificada no banco
        $stmt = $this->db->prepare("
            INSERT INTO transactions (user_id, plan_id, freight_id, listing_id, amount, status) 
            VALUES (?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$user['id'], $plan_id, $freight_id, $listing_id, $amount]);
        $transactionId = $this->db->lastInsertId();

        // 2. Monta Preferência
        $preferenceData = [
            "items" => [[
                "id" => (string)($plan_id ?? $listing_id ?? $freight_id),
                "title" => "CHAMA FRETE - " . $title,
                "quantity" => 1,
                "currency_id" => "BRL",
                "unit_price" => $amount
            ]],
            "payer" => [
                "email" => $user['email'],
                "name" => $user['name'] ?? ''
            ],
            "external_reference" => (string)$transactionId,
            "back_urls" => [
                "success" => "$baseUrl/payment-success",
                "failure" => "$baseUrl/dashboard",
                "pending" => "$baseUrl/dashboard"
            ],
            "auto_return" => "approved",
            "notification_url" => "$baseUrl/api/webhook-mp", 
            "binary_mode" => true
        ];

        return $this->callMP($preferenceData, $access_token, $transactionId);
    }

    private function callMP($data, $token, $transId) {
        $ch = curl_init("https://api.mercadopago.com/checkout/preferences");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        return isset($response['init_point']) 
            ? ["success" => true, "checkout_url" => $response['init_point'], "transaction_id" => $transId]
            : ["error" => "Erro MP", "details" => $response];
    }

    public function handleNotification($params) {
        $id = $params['data']['id'] ?? $params['id'] ?? null;
        if (!$id) return ["status" => "error"];

        $access_token = $_ENV['MP_ACCESS_TOKEN'];
        $ch = curl_init("https://api.mercadopago.com/v1/payments/" . $id);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $access_token"]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $payment = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($payment['status']) && $payment['status'] === 'approved') {
            return $this->processCompletion($payment['external_reference']);
        }
        return ["status" => "pending"];
    }

    private function processCompletion($transId) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM transactions WHERE id = ? AND status = 'pending'");
            $stmt->execute([$transId]);
            $trans = $stmt->fetch();

            if (!$trans) return ["status" => "not_found_or_paid"];

            $this->db->beginTransaction();

            // 1. Marca como pago
            $this->db->prepare("UPDATE transactions SET status = 'completed', paid_at = NOW() WHERE id = ?")->execute([$transId]);

            // 2. Lógica de Liberação por tipo de alvo
            if ($trans['listing_id']) {
                // Ativa Destaque Marketplace (7 dias)
                $this->db->prepare("UPDATE listings SET is_featured = 1, featured_until = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?")
                         ->execute([$trans['listing_id']]);
            } else if ($trans['plan_id']) {
                // Lógica de Planos de Assinatura
                $this->db->prepare("UPDATE users SET plan_type = (SELECT type FROM plans WHERE id = ?), is_subscriber = 1 WHERE id = ?")
                         ->execute([$trans['plan_id'], $trans['user_id']]);
            }

            $this->db->commit();
            return ["success" => true];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ["error" => $e->getMessage()];
        }
    }
}