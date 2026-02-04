<?php
namespace App\Controllers;

use App\Core\Response;
use App\Services\MercadoPagoService;

class PaymentController {
    private $mpService;

    public function __construct($db) {
        $this->mpService = new MercadoPagoService($db);
    }

    /**
     * Inicia o fluxo de pagamento
     */
    public function checkout($data) {
        // Validação de entrada
        if (empty($data['plan_id']) || empty($data['user_id']) || empty($data['amount'])) {
            return Response::json(["success" => false, "message" => "Informações de plano ou usuário ausentes"], 400);
        }

        try {
            // Usamos o método refatorado que cria a preferência e registra no banco
            $result = $this->mpService->createPreference($data, $data['user_id']);
            
            if (!empty($result['init_point'])) {
                return Response::json([
                    "success" => true, 
                    "url" => $result['init_point'],
                    "transaction_id" => $result['transaction_id']
                ]);
            }
            
            return Response::json(["success" => false, "message" => "Erro ao gerar link de pagamento"], 500);
        } catch (\Exception $e) {
            return Response::json(["success" => false, "message" => $e->getMessage()], 500);
        }
    }

    /**
     * Recebe notificações automáticas do Mercado Pago (IPN/Webhooks)
     */
    public function webhook($data) {
        // 1. Identifica o tipo de recurso. O MP envia notificações de vários tipos.
        // Geralmente o que nos interessa é 'payment'.
        $type = $data['type'] ?? $data['topic'] ?? null;
        $resourceId = null;

        if ($type === 'payment') {
            $resourceId = $data['data']['id'] ?? null;
        } elseif ($type === 'merchant_order') {
            $resourceId = $data['id'] ?? null;
        }

        // 2. Se tivermos um ID de recurso, validamos diretamente na API do MP
        if ($resourceId && $type === 'payment') {
            // Chamamos a verificação de segurança (Service consulta a API oficial)
            $success = $this->mpService->verifyPaymentStatus($resourceId);
            
            if ($success) {
                return Response::json(["status" => "approved_and_processed"], 200);
            }
            
            return Response::json(["status" => "received_but_pending_or_failed"], 200);
        }

        // 3. Resposta padrão para o MP parar de tentar enviar a mesma notificação
        return Response::json(["status" => "ignored"], 200);
    }
}