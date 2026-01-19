<?php
class MercadoPagoService {
    private $db;
    
    public function __construct($db) { 
        $this->db = $db; 
    }

    public function createPreference($data, $user) {
        $access_token = $_ENV['MP_ACCESS_TOKEN'] ?? null;

        if (!$access_token) return ["error" => "Token não configurado"];

        $plan_id = (int)($data['plan_id'] ?? 0);
        
        // Busca o plano no banco
        $stmt = $this->db->prepare("SELECT * FROM plans WHERE id = ?");
        $stmt->execute([$plan_id]);
        $plan = $stmt->fetch();

        if (!$plan) return ["error" => "Plano não encontrado"];

        // Cria a transação no banco (Status Pendente)
        $stmt = $this->db->prepare("INSERT INTO transactions (user_id, plan_id, amount, status) VALUES (?, ?, ?, 'pending')");
        $stmt->execute([$user['id'] ?? null, $plan['id'], $plan['price']]);
        $transactionId = $this->db->lastInsertId();

        // DADOS MÍNIMOS PARA O MODO TESTE (TEST-...)
       $preferenceData = [
            "items" => [[
                "title" => "CHAMA FRETE - " . $plan['name'],
                "quantity" => 1,
                "currency_id" => "BRL",
                "unit_price" => (float)$plan['price']
            ]],
            "payer" => [
                "email" => $user['email'] // Agora usamos o e-mail real do usuário logado
            ],
            "external_reference" => (string)$transactionId,
            "back_urls" => [
                "success" => "https://chamafrete.com.br/payment-success",
                "failure" => "https://chamafrete.com.br/anuncie",
                "pending" => "https://chamafrete.com.br/anuncie"
            ],
            "auto_return" => "approved", // Agora que terá HTTPS, pode reativar
            "notification_url" => "https://chamafrete.com.br/api/webhook-mp", // ESSENCIAL para o plano ativar sozinho
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $rawResponse = curl_exec($ch);
        $response = json_decode($rawResponse, true);
        curl_close($ch);

        // Se der erro, vamos ver o objeto completo do Mercado Pago
        if (!isset($response['init_point'])) {
            return ["error" => "Erro MP", "details" => $response];
        }

        return ["checkout_url" => $response['init_point']];
    }

    public function handleNotification($params) {
        // O MP envia o ID do pagamento de formas diferentes dependendo da versão
        $id = $params['data']['id'] ?? $params['id'] ?? null;
        
        // Log para debug (importante em produção)
        error_log("Webhook Recebido: " . json_encode($params));

        if ($id) {
            $access_token = $_ENV['MP_ACCESS_TOKEN'] ?? $_SERVER['MP_ACCESS_TOKEN'];
            
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
                
                // 1. Finaliza a Transação
                $this->db->prepare("UPDATE transactions SET status = 'completed', updated_at = NOW() WHERE id = ?")
                         ->execute([$transId]);

                $type = $trans['plan_type'];
                $days = (int)$trans['duration_days'];

                // 2. Lógica por Tipo de Plano
                switch ($type) {
                    case 'sidebar':
                    case 'freight_list':
                    case 'total':
                        // Cria anúncio inativo para o admin aprovar a arte/banner
                        $this->db->prepare("INSERT INTO ads (user_id, title, is_active, expires_at, position) VALUES (?, ?, 0, DATE_ADD(NOW(), INTERVAL ? DAY), ?)")
                                 ->execute([$trans['user_id'], "Anúncio " . $trans['plan_name'], $days, $type]);
                        break;

                    case 'featured':
                        $this->db->prepare("UPDATE freights SET is_featured = 1, featured_until = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?")
                                 ->execute([$days, $trans['freight_id']]);
                        break;

                    case 'urgent':
                        $this->db->prepare("UPDATE freights SET is_urgent = 1, urgent_until = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?")
                                 ->execute([$days, $trans['freight_id']]);
                        break;

                    case 'combo':
                        $this->db->prepare("UPDATE freights SET is_featured = 1, is_urgent = 1, featured_until = DATE_ADD(NOW(), INTERVAL ? DAY), urgent_until = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?")
                                 ->execute([$days, $days, $trans['freight_id']]);
                        break;

                    case 'driver_verified':
                        $this->db->prepare("UPDATE users SET is_verified = 1, verified_until = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?")
                                 ->execute([$days, $trans['user_id']]);
                        break;
                }

                $this->db->commit();
                return ["success" => true];
            }
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro no ProcessCompletion: " . $e->getMessage());
        }
        return ["success" => false];
    }
}