<?php
namespace App\Services;

use App\Repositories\PaymentRepository;
use Exception;

class MercadoPagoService {
    private $accessToken;
    private $baseUrl;
    private $paymentRepo;

    public function __construct($db) {
        $this->accessToken = $_ENV['MP_ACCESS_TOKEN'];
        $this->baseUrl = $_ENV['BASE_URL'];
        // Injetando o repositório para gerenciar o banco
        $this->paymentRepo = new PaymentRepository($db);
    }

    /**
     * Cria a preferência de pagamento (Checkout Pro)
     */
    public function createPreference($data, $userId) {
        // 1. Registra a intenção de compra no nosso banco via Repository
        $transactionId = $this->paymentRepo->createTransaction(
            $userId, 
            $data['plan_id'] ?? null, 
            $data['amount'], 
            null // ID Externo será atualizado depois
        );

        if (!$transactionId) throw new Exception("Falha ao registrar transação local.");

        // 2. Monta o Payload para o Mercado Pago
        $payload = [
            "items" => [[
                "title" => $data['title'] ?? "Assinatura Chama Frete",
                "quantity" => 1,
                "unit_price" => (float)$data['amount'],
                "currency_id" => "BRL"
            ]],
            "external_reference" => (string)$transactionId, // O ID da nossa tabela
            "notification_url" => $this->baseUrl . "/api/payment-webhook",
            "back_urls" => [
                "success" => $this->baseUrl . "/dashboard?payment=success",
                "failure" => $this->baseUrl . "/dashboard?payment=failure",
                "pending" => $this->baseUrl . "/dashboard?payment=pending"
            ],
            "auto_return" => "approved"
        ];

        $response = $this->callAPI('POST', 'checkout/preferences', $payload);

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
            return $this->paymentRepo->updateStatusByExternalId($transactionId, 'paid');
        }

        return false;
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

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            error_log("MercadoPago API Error: " . $response);
            return [];
        }

        return json_decode($response, true);
    }
}