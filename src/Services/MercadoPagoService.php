<?php
namespace App\Services;

use App\Repositories\PaymentRepository;
use Exception;

class MercadoPagoService {
    private $accessToken;
    private $baseUrl;
    private $paymentRepo;
    private $webhookSecret;

    public function __construct($db) {
        $this->accessToken = $_ENV['MP_ACCESS_TOKEN'] ?? getenv('MP_ACCESS_TOKEN') ?: '';
        $this->baseUrl = $_ENV['BASE_URL'] ?? getenv('BASE_URL') ?: 'http://127.0.0.1:8000';
        $this->frontendUrl = $_ENV['FRONTEND_URL'] ?? getenv('FRONTEND_URL') ?: 'http://127.0.0.1:5173';
        $this->paymentRepo = new PaymentRepository($db);
        $this->webhookSecret = $_ENV['MP_WEBHOOK_SECRET'] ?? getenv('MP_WEBHOOK_SECRET') ?: '';
    }

    public function isWebhookSignatureValid($payload, $signature) {
        if (empty($this->webhookSecret)) {
            // Development mode: skip strict signature validation
            return true;
        }
        $computed = hash_hmac('sha256', $payload, $this->webhookSecret);
        return hash_equals($computed, $signature);
    }

    /**
     * Cria a preferência de pagamento (Checkout Pro)
     */
    public function createPreference($data, $userId) {
        $billingCycle = $data['billing_cycle'] ?? 'monthly';
        $durationDays = $data['duration_days'] ?? 30;
        
        $transactionId = $this->paymentRepo->createTransaction(
            $userId, 
            $data['plan_id'] ?? null, 
            $data['amount'], 
            null,
            $billingCycle,
            $durationDays,
            $data['module_key'] ?? null,
            $data['feature_key'] ?? null
        );

        if (!$transactionId) throw new Exception("Falha ao registrar transação local.");

        // Usa URLs do frontend para back_urls
        $backUrlSuccess = $this->frontendUrl . "/payment/success";
        $backUrlFailure = $this->frontendUrl . "/payment/failure";
        $backUrlPending = $this->frontendUrl . "/payment/pending";
        
        // Só adiciona notification_url se for URL pública (não localhost)
        $notificationUrl = null;
        if (!preg_match('/localhost|127\.0\.0\.1/i', $this->baseUrl)) {
            $notificationUrl = $this->baseUrl . "/api/webhook-mp";
        }
        
        $payload = [
            "items" => [[
                "title" => mb_convert_encoding($data['title'] ?? "Assinatura Chama Frete", 'UTF-8'),
                "quantity" => 1,
                "unit_price" => (float)$data['amount'],
                "currency_id" => "BRL"
            ]],
            "external_reference" => (string)$transactionId,
            "back_urls" => [
                "success" => $backUrlSuccess,
                "failure" => $backUrlFailure,
                "pending" => $backUrlPending
            ]
        ];

        if ($notificationUrl) {
            $payload["notification_url"] = $notificationUrl;
        }

        // Só adiciona auto_return se for URL pública (não localhost)
        if (!preg_match('/localhost|127\.0\.0\.1/i', $this->frontendUrl)) {
            $payload["auto_return"] = "approved";
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        error_log("MercadoPagoService: Enviando para MP - " . $jsonPayload);

        $response = $this->callAPI('POST', 'checkout/preferences', $payload);

        error_log("MercadoPagoService: Resposta API - " . json_encode($response));

        if (empty($response) || isset($response['error'])) {
            throw new Exception("Erro do MercadoPago: " . ($response['error'] ?? 'Resposta vazia'));
        }

        return [
            "init_point" => $response['init_point'] ?? null,
            "preference_id" => $response['id'] ?? null,
            "transaction_id" => $transactionId
        ];
    }

    /**
     * Valida um pagamento recebido via Webhook
     * Consultar a API do MP é a única forma 100% segura de validar um status
     */
    public function verifyPaymentStatus($resourceId) {
        // Consulta o status real no servidor do Mercado Pago
        $paymentData = $this->callAPI('GET', "v1/payments/{$resourceId}");
        
        $status = $paymentData['status'] ?? 'pending';
        $transactionId = $paymentData['external_reference'] ?? null;

        if ($transactionId && $status === 'approved') {
            // Atualiza no banco via Repository
            return $this->paymentRepo->updateStatusByExternalId($transactionId, 'approved');
        }

        return false;
    }

    /**
     * Retorna dados do pagamento
     */
    public function getPaymentData($resourceId) {
        return $this->callAPI('GET', "v1/payments/{$resourceId}");
    }

    /**
     * Helper para chamadas cURL (POST/GET)
     */
    private function callAPI($method, $endpoint, $payload = null) {
        $ch = curl_init("https://api.mercadopago.com/$endpoint");
        
        $headers = [
            "Authorization: Bearer {$this->accessToken}",
            "Content-Type: application/json"
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $jsonBody = null;
        if ($method === 'POST' && $payload !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            error_log("MercadoPago API Request Body: " . $jsonBody);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        error_log("MercadoPago API: HTTP Code = {$httpCode}");

        if ($httpCode >= 400 || $error) {
            error_log("MercadoPago API Error: HTTP {$httpCode} - {$error}");
            error_log("MercadoPago API Error Response: {$response}");
            return ['error' => "HTTP {$httpCode}: {$error}", 'response' => $response];
        }

        return json_decode($response, true);
    }

    /**
     * Cria preferência de pagamento para promoção de frete (destaque/urgente)
     */
    public function createFreightPromotionPreference($data, $userId) {
        $promotionType = $data['type'] ?? 'featured';
        
        $transactionId = $this->paymentRepo->createTransaction(
            $userId, 
            null, 
            $data['amount'], 
            "FRET{$data['freight_id']}_{$promotionType}",
            'one_time',
            $data['duration_days'],
            'freight',
            'freight_promotion_' . $promotionType
        );

        if (!$transactionId) throw new Exception("Falha ao registrar transação local.");

        $backUrlSuccess = $this->frontendUrl . "/payment/success?type=freight_promotion&promotion={$promotionType}&freight_id={$data['freight_id']}";
        $backUrlFailure = $this->frontendUrl . "/payment/failure";
        
        $notificationUrl = null;
        if (!preg_match('/localhost|127\.0\.0\.1/i', $this->baseUrl)) {
            $notificationUrl = $this->baseUrl . "/api/webhook-mp";
        }
        
        $payload = [
            "items" => [[
                "title" => mb_convert_encoding($data['title'] ?? "Impulsionar Frete", 'UTF-8'),
                "description" => mb_convert_encoding($data['description'] ?? "Destaque/urgente para frete", 'UTF-8'),
                "quantity" => 1,
                "unit_price" => (float)$data['amount'],
                "currency_id" => "BRL"
            ]],
            "external_reference" => (string)$transactionId,
            "back_urls" => [
                "success" => $backUrlSuccess,
                "failure" => $backUrlFailure,
                "pending" => $backUrlSuccess
            ]
        ];

        if ($notificationUrl) {
            $payload["notification_url"] = $notificationUrl;
        }

        if (!preg_match('/localhost|127\.0\.0\.1/i', $this->frontendUrl)) {
            $payload["auto_return"] = "approved";
        }

        $response = $this->callAPI('POST', 'checkout/preferences', $payload);

        if (empty($response) || isset($response['error'])) {
            throw new Exception("Erro do MercadoPago: " . ($response['error'] ?? 'Resposta vazia'));
        }

        return [
            "init_point" => $response['init_point'] ?? null,
            "preference_id" => $response['id'] ?? null,
            "transaction_id" => $transactionId
        ];
    }

    /**
     * Cria preferência de pagamento para promoção de anúncio (destaque/bump/patrocinado)
     */
    public function createListingPromotionPreference($data, $userId) {
        $promotionType = $data['type'] ?? 'featured';
        
        $transactionId = $this->paymentRepo->createTransaction(
            $userId, 
            null, 
            $data['amount'], 
            "LIST{$data['listing_id']}_{$promotionType}",
            'one_time',
            $data['duration_days'],
            'marketplace',
            'listing_promotion_' . $promotionType
        );

        if (!$transactionId) throw new Exception("Falha ao registrar transação local.");

        $backUrlSuccess = $this->frontendUrl . "/payment/success?type=listing_promotion&promotion={$promotionType}&listing_id={$data['listing_id']}";
        $backUrlFailure = $this->frontendUrl . "/payment/failure";
        
        $notificationUrl = null;
        if (!preg_match('/localhost|127\.0\.0\.1/i', $this->baseUrl)) {
            $notificationUrl = $this->baseUrl . "/api/webhook-mp";
        }
        
        $payload = [
            "items" => [[
                "title" => mb_convert_encoding($data['title'] ?? "Impulsionar Anúncio", 'UTF-8'),
                "description" => mb_convert_encoding($data['description'] ?? "Destaque para anúncio", 'UTF-8'),
                "quantity" => 1,
                "unit_price" => (float)$data['amount'],
                "currency_id" => "BRL"
            ]],
            "external_reference" => (string)$transactionId,
            "back_urls" => [
                "success" => $backUrlSuccess,
                "failure" => $backUrlFailure,
                "pending" => $backUrlSuccess
            ]
        ];

        if ($notificationUrl) {
            $payload["notification_url"] = $notificationUrl;
        }

        if (!preg_match('/localhost|127\.0\.0\.1/i', $this->frontendUrl)) {
            $payload["auto_return"] = "approved";
        }

        $response = $this->callAPI('POST', 'checkout/preferences', $payload);

        if (empty($response) || isset($response['error'])) {
            throw new Exception("Erro do MercadoPago: " . ($response['error'] ?? 'Resposta vazia'));
        }

        return [
            "init_point" => $response['init_point'] ?? null,
            "preference_id" => $response['id'] ?? null,
            "transaction_id" => $transactionId
        ];
    }
}
