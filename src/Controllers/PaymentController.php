<?php
namespace App\Controllers;

use App\Core\Response;
use App\Services\MercadoPagoService;
use PDO;

class PaymentController {
    private $mpService;
    private $db;

    public function __construct($db) {
        $this->db = $db;
        $this->mpService = new MercadoPagoService($db);
    }

    /**
     * Inicia o fluxo de pagamento
     * Suporta billing_cycle: monthly, quarterly, semiannual, yearly
     */
    public function checkout($data) {
        // Validação de entrada básica
        if (empty($data['plan_id']) || empty($data['user_id'])) {
            return Response::json(["success" => false, "message" => "Informações de plano ou usuário ausentes"], 400);
        }

        $billingCycle = $data['billing_cycle'] ?? 'monthly';
        
        try {
            // Busca o plano para obter o preço correto conforme o ciclo
            $stmt = $this->db->prepare("SELECT * FROM plans WHERE id = :id AND active = 1");
            $stmt->execute([':id' => $data['plan_id']]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$plan) {
                return Response::json(["success" => false, "message" => "Plano não encontrado"], 404);
            }

            // Calcula o valor conforme o ciclo de cobrança
            $amount = $this->calculatePlanPrice($plan, $billingCycle);
            
            if ($amount <= 0) {
                return Response::json(["success" => false, "message" => "Valor do plano inválido"], 400);
            }

            // Prepara os dados para o MercadoPago
            $paymentData = [
                'plan_id' => $data['plan_id'],
                'amount' => $amount,
                'title' => $plan['name'] . ' - ' . $this->getCycleLabel($billingCycle),
                'billing_cycle' => $billingCycle,
                'duration_days' => $this->getCycleDuration($billingCycle)
            ];

            // Usamos o método refatorado que cria a preferência e registra no banco
            $result = $this->mpService->createPreference($paymentData, $data['user_id']);
            
            if (!empty($result['init_point'])) {
                return Response::json([
                    "success" => true, 
                    "url" => $result['init_point'],
                    "transaction_id" => $result['transaction_id'],
                    "amount" => $amount,
                    "billing_cycle" => $billingCycle
                ]);
            }
            
            return Response::json(["success" => false, "message" => "Erro ao gerar link de pagamento"], 500);
        } catch (\Exception $e) {
            return Response::json(["success" => false, "message" => $e->getMessage()], 500);
        }
    }

    /**
     * Calcula o preço do plano conforme o ciclo de cobrança
     */
    private function calculatePlanPrice($plan, $billingCycle) {
        switch ($billingCycle) {
            case 'quarterly':
                return $plan['price_quarterly'] ? (float)$plan['price_quarterly'] : null;
            case 'semiannual':
                return $plan['price_semiannual'] ? (float)$plan['price_semiannual'] : null;
            case 'yearly':
                return $plan['price_yearly'] ? (float)$plan['price_yearly'] : null;
            case 'monthly':
            default:
                return (float)$plan['price'];
        }
    }

    /**
     * Retorna label do ciclo para display
     */
    private function getCycleLabel($billingCycle) {
        $labels = [
            'monthly' => 'Mensal',
            'quarterly' => 'Trimestral',
            'semiannual' => 'Semestral',
            'yearly' => 'Anual'
        ];
        return $labels[$billingCycle] ?? 'Mensal';
    }

    /**
     * Retorna duração em dias conforme o ciclo
     */
    private function getCycleDuration($billingCycle) {
        $durations = [
            'monthly' => 30,
            'quarterly' => 90,
            'semiannual' => 180,
            'yearly' => 365
        ];
        return $durations[$billingCycle] ?? 30;
    }

    /**
     * Cria pagamento por uso avulso de módulo
     */
    public function purchasePerUse($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $moduleKey = $data['module_key'] ?? '';
        $featureKey = $data['feature_key'] ?? '';

        if (empty($moduleKey) || empty($featureKey)) {
            return Response::json(["success" => false, "message" => "Módulo ou recurso inválido"], 400);
        }

        try {
            // Busca preço
            $stmt = $this->db->prepare("
                SELECT * FROM pricing_rules 
                WHERE module_key = :module_key AND feature_key = :feature_key AND is_active = 1
            ");
            $stmt->execute([
                ':module_key' => $moduleKey, 
                ':feature_key' => $featureKey
            ]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rule || $rule['price_per_use'] <= 0) {
                return Response::json(["success" => false, "message" => "Preço não configurado para este recurso"], 400);
            }

            $amount = (float)$rule['price_per_use'];
            $userId = $loggedUser['id'];

            // Registra transação
            $stmt = $this->db->prepare("
                INSERT INTO transactions 
                (user_id, module_key, feature_key, transaction_type, amount, status, external_reference, created_at)
                VALUES (:user_id, :module_key, :feature_key, 'per_use', :amount, 'pending', :external_ref, NOW())
            ");
            $externalRef = 'MODULE_' . uniqid();
            $stmt->execute([
                ':user_id' => $userId,
                ':module_key' => $moduleKey,
                ':feature_key' => $featureKey,
                ':amount' => $amount,
                ':external_ref' => $externalRef
            ]);
            $transactionId = $this->db->lastInsertId();

            // Se não há token do MercadoPago, registra como aprovado
            $mpToken = $_ENV['MP_ACCESS_TOKEN'] ?? getenv('MP_ACCESS_TOKEN') ?: '';
            
            if (empty($mpToken) || $mpToken === 'TEST-xxx') {
                $this->db->prepare("UPDATE transactions SET status = 'approved' WHERE id = ?")->execute([$transactionId]);
                
                return Response::json([
                    "success" => true,
                    "message" => "Recurso adquirido com sucesso!",
                    "payment_not_required" => true
                ]);
            }

            // Cria preferência no MercadoPago
            $mpData = [
                'title' => "{$rule['feature_name']} - Chama Frete",
                'amount' => $amount,
                'plan_id' => null
            ];

            $result = $this->mpService->createPreference($mpData, $userId);

            // Atualiza external_reference com o ID da transação
            $stmt = $this->db->prepare("UPDATE transactions SET external_reference = :ext_ref WHERE id = :id");
            $stmt->execute([':ext_ref' => (string)$transactionId, ':id' => $transactionId]);

            return Response::json([
                "success" => true,
                "url" => $result['init_point'],
                "transaction_id" => $transactionId,
                "amount" => $amount
            ]);

        } catch (\Throwable $e) {
            error_log("ERRO purchasePerUse: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao processar pagamento"], 500);
        }
    }

    /**
     * Assina plano mensal de módulo
     */
    public function subscribeMonthly($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $moduleKey = $data['module_key'] ?? '';
        $featureKey = $data['feature_key'] ?? '';

        if (empty($moduleKey)) {
            return Response::json(["success" => false, "message" => "Módulo inválido: " . json_encode($data)], 400);
        }

        try {
            // Busca preço mensal
            $stmt = $this->db->prepare("
                SELECT * FROM pricing_rules 
                WHERE module_key = :module_key AND is_active = 1
                ORDER BY price_monthly ASC
                LIMIT 1
            ");
            $stmt->execute([':module_key' => $moduleKey]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rule) {
                return Response::json(["success" => false, "message" => "Nenhuma regra de preço encontrada para: $moduleKey"], 400);
            }

            if ($rule['price_monthly'] <= 0) {
                return Response::json(["success" => false, "message" => "Plano mensal não configurado para este módulo"], 400);
            }

            $amount = (float)$rule['price_monthly'];
            $userId = $loggedUser['id'];

            // Registra transação
            $stmt = $this->db->prepare("
                INSERT INTO transactions 
                (user_id, module_key, feature_key, transaction_type, amount, status, external_reference, created_at)
                VALUES (:user_id, :module_key, :feature_key, 'monthly', :amount, 'pending', :external_ref, NOW())
            ");
            $externalRef = 'MODULE_' . uniqid();
            $stmt->execute([
                ':user_id' => $userId,
                ':module_key' => $moduleKey,
                ':feature_key' => $featureKey ?: 'subscription',
                ':amount' => $amount,
                ':external_ref' => $externalRef
            ]);
            $transactionId = $this->db->lastInsertId();

            // Se não há token do MercadoPago, ativa diretamente (modo teste/grátis)
            $mpToken = $_ENV['MP_ACCESS_TOKEN'] ?? getenv('MP_ACCESS_TOKEN') ?: '';
            
            if (empty($mpToken) || $mpToken === 'TEST-xxx') {
                // Ativa o módulo diretamente sem pagamento
                $stmt = $this->db->prepare("
                    INSERT INTO user_modules (user_id, module_key, status, activated_at, expires_at, plan_id) 
                    VALUES (:user_id, :module_key, 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), :plan_id)
                    ON DUPLICATE KEY UPDATE status = 'active', activated_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY), plan_id = :plan_id2
                ");
                $stmt->execute([
                    ':user_id' => $userId, 
                    ':module_key' => $moduleKey,
                    ':plan_id' => $data['plan_id'] ?? null,
                    ':plan_id2' => $data['plan_id'] ?? null
                ]);
                
                // Atualiza transação para approved
                $this->db->prepare("UPDATE transactions SET status = 'approved' WHERE id = ?")->execute([$transactionId]);
                
                // Aplica selo verificado se o plano permitir
                $this->applyVerificationBadge($userId, $data['plan_id'] ?? null);
                
                return Response::json([
                    "success" => true,
                    "message" => "Módulo ativado com sucesso!",
                    "payment_not_required" => true,
                    "expires_at" => date('Y-m-d H:i:s', strtotime('+30 days'))
                ]);
            }

            // Cria preferência no MercadoPago
            $mpData = [
                'title' => "Plano Mensal {$rule['feature_name']} - Chama Frete",
                'amount' => $amount,
                'plan_id' => null
            ];

            $result = $this->mpService->createPreference($mpData, $userId);

            // Atualiza external_reference com o ID da transação
            $stmt = $this->db->prepare("UPDATE transactions SET external_reference = :ext_ref WHERE id = :id");
            $stmt->execute([':ext_ref' => (string)$transactionId, ':id' => $transactionId]);

            return Response::json([
                "success" => true,
                "url" => $result['init_point'],
                "transaction_id" => $transactionId,
                "amount" => $amount,
                "expires_at" => date('Y-m-d H:i:s', strtotime('+30 days'))
            ]);

        } catch (\Throwable $e) {
            error_log("ERRO subscribeMonthly: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao processar assinatura: " . $e->getMessage()], 500);
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
            // Consulta dados do pagamento
            $paymentData = $this->mpService->getPaymentData($resourceId);
            $status = $paymentData['status'] ?? 'pending';
            $externalRef = $paymentData['external_reference'] ?? null;
            
            if ($externalRef && $status === 'approved') {
                // Verifica se é transação de módulo (pela coluna module_key)
                $stmt = $this->db->prepare("SELECT * FROM transactions WHERE id = :id AND module_key IS NOT NULL LIMIT 1");
                $stmt->execute([':id' => $externalRef]);
                $moduleTx = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($moduleTx) {
                    // Atualiza transação
                    $stmt = $this->db->prepare("
                        UPDATE transactions 
                        SET status = 'approved', 
                            gateway_id = :mp_id, 
                            payment_method = :method,
                            approved_at = NOW() 
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':mp_id' => $resourceId,
                        ':method' => $paymentData['payment_method_id'] ?? 'mercadopago',
                        ':id' => $externalRef
                    ]);
                    
                    // Se for assinatura mensal, calcula data de expiração
                    $expiresAt = null;
                    if ($moduleTx['transaction_type'] === 'monthly') {
                        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
                    }
                    
                    // Ativa o módulo para o usuário
                    $stmt = $this->db->prepare("
                        INSERT INTO user_modules (user_id, module_key, status, activated_at, expires_at) 
                        VALUES (:user_id, :module_key, 'active', NOW(), :expires_at)
                        ON DUPLICATE KEY UPDATE status = 'active', activated_at = NOW(), expires_at = :expires_at2
                    ");
                    $stmt->execute([
                        ':user_id' => $moduleTx['user_id'],
                        ':module_key' => $moduleTx['module_key'],
                        ':expires_at' => $expiresAt,
                        ':expires_at2' => $expiresAt
                    ]);

                    // Aplica selo verificado se o plano permitir
                    $this->applyVerificationBadge($moduleTx['user_id'], $moduleTx['plan_id']);

                    return Response::json(["status" => "module_activated"], 200);
                }
                
                // Se não for transação de módulo, usa o método original
                $success = $this->mpService->verifyPaymentStatus($resourceId);
                if ($success) {
                    return Response::json(["status" => "approved_and_processed"], 200);
                }
            }
            
            return Response::json(["status" => "received_but_pending_or_failed"], 200);
        }

        // 3. Resposta padrão para o MP parar de tentar enviar a mesma notificação
        return Response::json(["status" => "ignored"], 200);
    }

    /**
     * Verifica se usuário pode usar uma posição de anúncio
     */
    public function checkAdEligibility($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $featureKey = $data['feature_key'] ?? '';
        
        if (empty($featureKey)) {
            return Response::json(["success" => false, "message" => "Feature key é obrigatória"], 400);
        }

        try {
            $adRepo = new \App\Repositories\AdRepository($this->db);
            $result = $adRepo->checkAdPositionEligibility($loggedUser['id'], $featureKey);
            
            return Response::json([
                "success" => true,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            error_log("ERRO checkAdEligibility: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao verificar elegibilidade"], 500);
        }
    }

    /**
     * Aplica selo de parceiro verificado ao usuário se o plano permitir
     */
    private function applyVerificationBadge($userId, $planId = null) {
        if (!$planId) {
            return false;
        }

        try {
            // Busca o plano para verificar se tem has_verification_badge
            $stmt = $this->db->prepare("SELECT has_verification_badge, duration_days FROM plans WHERE id = ?");
            $stmt->execute([$planId]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$plan || empty($plan['has_verification_badge'])) {
                return false;
            }

            // Calcula data de expiração do selo
            $days = $plan['duration_days'] ?? 30;
            $verifiedUntil = date('Y-m-d H:i:s', strtotime("+{$days} days"));

            // Aplica o selo ao usuário
            $stmt = $this->db->prepare("
                UPDATE users 
                SET is_verified = 1, 
                    verified_until = ?,
                    verified_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$verifiedUntil, $userId]);

            error_log("VERIFICATION BADGE: Usuário $userId recebeu selo verificado (plano $planId)");

            return true;
        } catch (\Throwable $e) {
            error_log("ERRO applyVerificationBadge: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retorna as transações do usuário logado
     */
    public function getMyTransactions($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        try {
            $userId = $loggedUser['id'];
            
            $stmt = $this->db->prepare("
                SELECT id, module_key, feature_key, transaction_type, amount, status, 
                       created_at, approved_at
                FROM transactions 
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT 100
            ");
            $stmt->execute([$userId]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json([
                "success" => true,
                "data" => $transactions
            ]);
        } catch (\Throwable $e) {
            error_log("ERRO getMyTransactions: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao buscar transações"], 500);
        }
    }
}