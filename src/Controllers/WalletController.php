<?php
namespace App\Controllers;

use App\Core\Response;
use App\Services\CreditService;
use App\Services\MercadoPagoService;
use PDO;

class WalletController {
    private CreditService $creditService;
    private MercadoPagoService $mpService;
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->creditService = new CreditService($db);
        $this->mpService = new MercadoPagoService($db);
    }

    /**
     * Obtém o saldo da carteira do usuário logado
     * GET /api/wallet/balance
     */
    public function getBalance($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Não autorizado"], 401);
        }

        $balance = $this->creditService->getBalance($loggedUser['id']);
        $transactions = $this->creditService->getTransactions($loggedUser['id'], 20);

        return Response::json([
            "success" => true,
            "data" => [
                "balance" => $balance,
                "transactions" => $transactions
            ]
        ]);
    }

    /**
     * Gera PIX para recarga da carteira
     * POST /api/wallet/recharge
     */
    public function recharge($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Não autorizado"], 401);
        }

        $amount = isset($data['amount']) ? (float)$data['amount'] : 0;

        if ($amount <= 0) {
            return Response::json(["success" => false, "message" => "Valor inválido. Mínimo: R$ 0,01"], 400);
        }

        try {
            $userId = $loggedUser['id'];

            $stmt = $this->db->prepare("
                INSERT INTO transactions 
                (user_id, module_key, feature_key, transaction_type, amount, status, gateway_payload, created_at)
                VALUES (:user_id, 'wallet', 'recharge', 'wallet_recharge', :amount, 'pending', :payload, NOW())
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':amount' => $amount,
                ':payload' => json_encode(['description' => 'Recarga de carteira via PIX'])
            ]);
            $transactionId = $this->db->lastInsertId();

            $mpData = [
                'title' => "Recarga Carteira - Chama Frete",
                'amount' => $amount,
                'plan_id' => null,
                'billing_cycle' => 'one_time'
            ];

            $result = $this->mpService->createPreference($mpData, $userId);

            if (!empty($result['init_point'])) {
                $stmt = $this->db->prepare("
                    UPDATE transactions 
                    SET external_reference = :ext_ref 
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':ext_ref' => (string)$transactionId,
                    ':id' => $transactionId
                ]);

                return Response::json([
                    "success" => true,
                    "url" => $result['init_point'],
                    "transaction_id" => $transactionId,
                    "amount" => $amount,
                    "message" => "PIX gerado com sucesso"
                ]);
            }

            return Response::json(["success" => false, "message" => "Erro ao gerar PIX"], 500);

        } catch (\Exception $e) {
            error_log("Erro ao recarregar carteira: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao processar recarga"], 500);
        }
    }

    /**
     * Lista transações do usuário
     * GET /api/wallet/transactions
     */
    public function getTransactions($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Não autorizado"], 401);
        }

        $limit = isset($data['limit']) ? (int)$data['limit'] : 50;
        $limit = min($limit, 100);

        $transactions = $this->creditService->getTransactions($loggedUser['id'], $limit);

        return Response::json([
            "success" => true,
            "data" => $transactions
        ]);
    }

    /**
     * Obtém preços de um módulo
     * GET /api/wallet/pricing?module=freights
     */
    public function getPricing($data) {
        $moduleKey = $data['module'] ?? '';

        if (empty($moduleKey)) {
            return Response::json(["success" => false, "message" => "Módulo não informado"], 400);
        }

        try {
            $stmt = $this->db->prepare("
                SELECT * FROM pricing_rules 
                WHERE module_key = :module AND is_active = 1
                ORDER BY feature_key
            ");
            $stmt->execute([':module' => $moduleKey]);
            $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json([
                "success" => true,
                "data" => $rules
            ]);
        } catch (\Exception $e) {
            error_log("Erro ao buscar preços: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao buscar preços"], 500);
        }
    }

    /**
     * Webhook para receber notificações de pagamento de recarga
     * POST /api/wallet/webhook
     */
    public function webhook($data) {
        $type = $data['type'] ?? $data['topic'] ?? null;
        $resourceId = null;

        if ($type === 'payment') {
            $resourceId = $data['data']['id'] ?? null;
        } elseif ($type === 'merchant_order') {
            $resourceId = $data['id'] ?? null;
        }

        if ($resourceId && $type === 'payment') {
            $paymentData = $this->mpService->getPaymentData($resourceId);
            $status = $paymentData['status'] ?? 'pending';
            $externalRef = $paymentData['external_reference'] ?? null;

            if ($externalRef && $status === 'approved') {
                $stmt = $this->db->prepare("
                    SELECT * FROM transactions 
                    WHERE id = :id AND transaction_type = 'wallet_recharge' AND status = 'pending'
                ");
                $stmt->execute([':id' => $externalRef]);
                $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($transaction) {
                    $amount = (float)$transaction['amount'];
                    $userId = (int)$transaction['user_id'];

                    $this->creditService->credit($userId, $amount, "Recarga via PIX - ID: {$resourceId}");

                    $stmt = $this->db->prepare("
                        UPDATE transactions 
                        SET status = 'approved', 
                            gateway_id = :mp_id,
                            approved_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':mp_id' => $resourceId,
                        ':id' => $externalRef
                    ]);

                    return Response::json(["status" => "wallet_recharged"], 200);
                }
            }
        }

        return Response::json(["status" => "ignored"], 200);
    }
}
