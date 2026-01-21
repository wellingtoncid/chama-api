<?php

class MercadoPagoService {
    private $db;
    
    public function __construct($db) { 
        $this->db = $db; 
    }

    public function createPreference($data, $user) {
        $access_token = $_ENV['MP_ACCESS_TOKEN'] ?? null;
        $baseUrl = $_ENV['BASE_URL'] ?? 'https://chamafrete.com.br';

        if (!$access_token) return ["error" => "Token Mercado Pago não configurado"];

        $plan_id = (int)($data['plan_id'] ?? 0);
        $freight_id = isset($data['freight_id']) ? (int)$data['freight_id'] : null;
        
        // 1. Busca o plano no banco
        $stmt = $this->db->prepare("SELECT * FROM plans WHERE id = ?");
        $stmt->execute([$plan_id]);
        $plan = $stmt->fetch();

        if (!$plan) return ["error" => "Plano não encontrado"];

        // 2. Cria a transação no banco (Status Pendente) com freight_id se houver
        $stmt = $this->db->prepare("INSERT INTO transactions (user_id, plan_id, amount, status, freight_id) VALUES (?, ?, ?, 'pending', ?)");
        $stmt->execute([$user['id'] ?? null, $plan['id'], $plan['price'], $freight_id]);
        $transactionId = $this->db->lastInsertId();

        // 3. Monta a preferência para o Mercado Pago
        $preferenceData = [
            "items" => [[
                "id" => (string)$plan['id'],
                "title" => "CHAMA FRETE - " . $plan['name'],
                "quantity" => 1,
                "currency_id" => "BRL",
                "unit_price" => (float)$plan['price']
            ]],
            "payer" => [
                "email" => $user['email'],
                "name" => $user['name'] ?? ''
            ],
            "external_reference" => (string)$transactionId,
            "back_urls" => [
                "success" => "$baseUrl/payment-success",
                "failure" => "$baseUrl/anuncie",
                "pending" => "$baseUrl/anuncie"
            ],
            "auto_return" => "approved",
            "notification_url" => "$baseUrl/api/webhook-mp", 
            "binary_mode" => true
        ];

        $ch = curl_init("https://api.mercadopago.com/checkout/preferences");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $access_token",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($preferenceData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); // Segurança de produção

        $rawResponse = curl_exec($ch);
        $response = json_decode($rawResponse, true);
        curl_close($ch);

        if (!isset($response['init_point'])) {
            error_log("Erro MP Preference: " . $rawResponse);
            return ["error" => "Erro ao gerar link de pagamento", "details" => $response];
        }

        return [
            "success" => true,
            "checkout_url" => $response['init_point'],
            "transaction_id" => $transactionId
        ];
    }

    public function handleNotification($params) {
        // O MP pode enviar o ID via query param ou body
        $id = $params['data']['id'] ?? $params['id'] ?? null;
        $type = $params['type'] ?? $params['topic'] ?? null;
        
        error_log("Webhook Recebido: Tipo [$type] ID [$id]");

        // Só buscamos detalhes se for uma notificação de pagamento
        if ($id && ($type === 'payment' || !$type)) {
            $access_token = $_ENV['MP_ACCESS_TOKEN'];
            
            $ch = curl_init("https://api.mercadopago.com/v1/payments/" . $id);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $access_token"]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $payment = json_decode(curl_exec($ch), true);
            curl_close($ch);

            if (isset($payment['status']) && $payment['status'] === 'approved') {
                return $this->processCompletion($payment['external_reference']);
            }
        }
        return ["status" => "ignored"];
    }

    private function processCompletion($transId) {
        try {
            // Busca transação e detalhes do plano
            $stmt = $this->db->prepare("
                SELECT t.*, p.type as plan_type, p.duration_days, p.name as plan_name 
                FROM transactions t 
                JOIN plans p ON t.plan_id = p.id 
                WHERE t.id = ? AND t.status = 'pending'
            ");
            $stmt->execute([$transId]);
            $trans = $stmt->fetch();

            if ($trans) {
                $this->db->beginTransaction();
                
                // 1. Finaliza a Transação (usamos 'completed' para bater com seu Dashboard)
                $this->db->prepare("UPDATE transactions SET status = 'completed', paid_at = NOW(), updated_at = NOW() WHERE id = ?")
                         ->execute([$transId]);

                $type = $trans['plan_type'];
                $days = (int)$trans['duration_days'];
                $userId = $trans['user_id'];
                $freightId = $trans['freight_id'];

                // 2. Liberação de Benefícios
                switch ($type) {
                    case 'sidebar':
                    case 'freight_list':
                    case 'total':
                        // Criar anúncio inativo (Admin deve subir a imagem depois)
                        $this->db->prepare("INSERT INTO ads (user_id, title, is_active, expires_at, position) VALUES (?, ?, 0, DATE_ADD(NOW(), INTERVAL ? DAY), ?)")
                                 ->execute([$userId, "Anúncio " . $trans['plan_name'], $days, $type]);
                        break;

                    case 'featured':
                        if($freightId) {
                            $this->db->prepare("UPDATE freights SET is_featured = 1, featured_until = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?")
                                     ->execute([$days, $freightId]);
                        }
                        break;

                    case 'urgent':
                        if($freightId) {
                            $this->db->prepare("UPDATE freights SET is_urgent = 1, urgent_until = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?")
                                     ->execute([$days, $freightId]);
                        }
                        break;

                    case 'combo':
                        if($freightId) {
                            $this->db->prepare("UPDATE freights SET is_featured = 1, is_urgent = 1, featured_until = DATE_ADD(NOW(), INTERVAL ? DAY), urgent_until = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?")
                                     ->execute([$days, $days, $freightId]);
                        }
                        break;

                    case 'driver_verified':
                        $this->db->prepare("UPDATE users SET is_verified = 1, verified_until = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?")
                                 ->execute([$days, $userId]);
                        break;
                }

                $this->db->commit();
                error_log("Pagamento $transId processado com sucesso.");
                return ["success" => true];
            }
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro Crítico Webhook: " . $e->getMessage());
        }
        return ["success" => false];
    }
}