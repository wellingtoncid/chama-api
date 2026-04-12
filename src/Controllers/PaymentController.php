<?php
namespace App\Controllers;

use App\Core\Response;
use App\Services\MercadoPagoService;
use App\Services\CreditService;
use App\Repositories\PaymentRepository;
use PDO;

class PaymentController {
    private $mpService;
    private $creditService;
    private $db;
    private $paymentRepo;

    public function __construct($db) {
        $this->db = $db;
        $this->mpService = new MercadoPagoService($db);
        $this->creditService = new CreditService($db);
        $this->paymentRepo = new PaymentRepository($db);
    }

    // Convenience: alias to start a payment flow via /api/payments/create
    public function createPayment($data) {
        // Reuse existing checkout flow for MVP
        return $this->checkout($data);
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
     * Prioriza saldo da carteira, fallback para MercadoPago
     */
    public function purchasePerUse($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $moduleKey = $data['module_key'] ?? '';
        $featureKey = $data['feature_key'] ?? '';
        $paymentMethod = $data['payment_method'] ?? 'auto'; // 'auto', 'wallet', 'mercadopago'

        if (empty($moduleKey) || empty($featureKey)) {
            return Response::json(["success" => false, "message" => "Módulo ou recurso inválido"], 400);
        }

        $userId = $loggedUser['id'];

        try {
            // Módulos ativos por padrão
            $defaultActiveModules = ['freights', 'marketplace'];
            
            // Verificar se o módulo está ativo para o usuário
            $stmt = $this->db->prepare("
                SELECT * FROM user_modules 
                WHERE user_id = :user_id AND module_key = :module_key AND status = 'active'
            ");
            $stmt->execute([':user_id' => $userId, ':module_key' => $moduleKey]);
            $moduleAccess = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$moduleAccess && !in_array($moduleKey, $defaultActiveModules)) {
                return Response::json([
                    "success" => false, 
                    "message" => "Módulo não está ativo. Solicite acesso primeiro.",
                    "requires_module_activation" => true,
                    "module_key" => $moduleKey
                ], 403);
            }

            // Se é módulo ativo por padrão mas não tem registro, cria um
            if (!$moduleAccess && in_array($moduleKey, $defaultActiveModules)) {
                $stmt = $this->db->prepare("
                    INSERT INTO user_modules (user_id, module_key, status, activated_at) 
                    VALUES (:user_id, :module_key, 'active', NOW())
                    ON DUPLICATE KEY UPDATE status = 'active', activated_at = NOW()
                ");
                $stmt->execute([':user_id' => $userId, ':module_key' => $moduleKey]);
            }

            // Busca preço
            $stmt = $this->db->prepare("
                SELECT * FROM pricing_rules 
                WHERE module_key = :module_key AND feature_key = :feature_key AND is_active = 1
            ");
            $stmt->execute([':module_key' => $moduleKey, ':feature_key' => $featureKey]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rule || $rule['price_per_use'] <= 0) {
                return Response::json(["success" => false, "message" => "Preço não configurado para este recurso"], 400);
            }

            $amount = (float)$rule['price_per_use'];

            error_log("PURCHASE_PER_USE: module={$moduleKey}, feature={$featureKey}, amount={$amount}, paymentMethod={$paymentMethod}");

            // === OPÇÃO 1: Usar saldo da carteira ===
            if ($paymentMethod === 'auto' || $paymentMethod === 'wallet') {
                $balance = $this->creditService->getBalance($userId);
                
                if ($balance >= $amount) {
                    // Saldo suficiente - debita da carteira
                    $success = $this->creditService->debit($userId, $amount, $moduleKey, $featureKey);
                    
                    if ($success) {
                        return Response::json([
                            "success" => true,
                            "payment_method" => "wallet",
                            "message" => "Recurso adquirido com sucesso!",
                            "new_balance" => $this->creditService->getBalance($userId),
                            "amount" => $amount
                        ]);
                    }
                }
                
                // Se escolheu carteira explicitamente mas não tem saldo
                if ($paymentMethod === 'wallet') {
                    return Response::json([
                        "success" => false,
                        "message" => "Saldo insuficiente. Seu saldo: R$ " . number_format($balance, 2, ',', '.'),
                        "insufficient_balance" => true,
                        "required" => $amount,
                        "available" => $balance
                    ], 400);
                }
            }

            // === OPÇÃO 2: Usar MercadoPago ===
            $mpToken = $_ENV['MP_ACCESS_TOKEN'] ?? getenv('MP_ACCESS_TOKEN') ?: '';
            
            if (empty($mpToken)) {
                return Response::json([
                    "success" => false,
                    "message" => "Saldo insuficiente e gateway de pagamento não configurado.",
                    "insufficient_balance" => true,
                    "wallet_balance" => $this->creditService->getBalance($userId),
                    "required" => $amount
                ], 400);
            }

            // Cria preferência MercadoPago
            $mpData = [
                'title' => $rule['feature_name'] . " - Chama Frete",
                'amount' => $amount,
                'plan_id' => null,
                'module_key' => $moduleKey,
                'feature_key' => $featureKey
            ];

            $result = $this->mpService->createPreference($mpData, $userId);

            return Response::json([
                "success" => true,
                "payment_method" => "mercadopago",
                "url" => $result['init_point'],
                "transaction_id" => $result['transaction_id'],
                "amount" => $amount,
                "wallet_balance" => $this->creditService->getBalance($userId)
            ]);

        } catch (\Throwable $e) {
            error_log("ERRO purchasePerUse: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao processar pagamento"], 500);
        }
    }

    /**
     * Compra com pagamento parcial (saldo + MercadoPago)
     */
    public function purchasePartial($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $moduleKey = $data['module_key'] ?? '';
        $featureKey = $data['feature_key'] ?? '';
        $walletAmount = (float)($data['wallet_amount'] ?? 0);

        if (empty($moduleKey) || empty($featureKey)) {
            return Response::json(["success" => false, "message" => "Módulo ou recurso inválido"], 400);
        }

        if ($walletAmount <= 0) {
            return Response::json(["success" => false, "message" => "Valor do saldo inválido"], 400);
        }

        $userId = $loggedUser['id'];

        try {
            // Verifica saldo disponível
            $balance = $this->creditService->getBalance($userId);
            
            if ($balance < $walletAmount) {
                return Response::json([
                    "success" => false,
                    "message" => "Saldo insuficiente. Saldo disponível: R$ " . number_format($balance, 2, ',', '.')
                ], 400);
            }

            // Busca preço do recurso
            $stmt = $this->db->prepare("
                SELECT * FROM pricing_rules 
                WHERE module_key = :module_key AND feature_key = :feature_key AND is_active = 1
            ");
            $stmt->execute([':module_key' => $moduleKey, ':feature_key' => $featureKey]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rule || $rule['price_per_use'] <= 0) {
                return Response::json(["success" => false, "message" => "Preço não configurado para este recurso"], 400);
            }

            $totalAmount = (float)$rule['price_per_use'];

            if ($walletAmount > $totalAmount) {
                return Response::json(["success" => false, "message" => "Valor do saldo excede o valor do recurso"], 400);
            }

            // Debita o saldo da carteira
            $success = $this->creditService->debit($userId, $walletAmount, $moduleKey, $featureKey . '_partial');
            
            if (!$success) {
                return Response::json(["success" => false, "message" => "Erro ao debitar saldo"], 500);
            }

            $remaining = $totalAmount - $walletAmount;

            // Se ainda falta pagar algo, redireciona para MP
            if ($remaining > 0) {
                $mpToken = $_ENV['MP_ACCESS_TOKEN'] ?? getenv('MP_ACCESS_TOKEN') ?: '';
                
                if (empty($mpToken)) {
                    // Reverte o débito e retorna erro
                    $this->creditService->credit($userId, $walletAmount, "Estorno - MP não disponível");
                    return Response::json([
                        "success" => false,
                        "message" => "Gateway de pagamento não disponível"
                    ], 500);
                }

                // Cria preferência MP para o restante
                $mpData = [
                    'title' => $rule['feature_name'] . " (R$ " . number_format($walletAmount, 2, ',', '.') . " do saldo + restante)",
                    'amount' => $remaining,
                    'plan_id' => null,
                    'module_key' => $moduleKey,
                    'feature_key' => $featureKey,
                    'wallet_amount_used' => $walletAmount
                ];

                $result = $this->mpService->createPreference($mpData, $userId);

                return Response::json([
                    "success" => true,
                    "wallet_amount_used" => $walletAmount,
                    "remaining_to_pay" => $remaining,
                    "new_balance" => $this->creditService->getBalance($userId),
                    "url" => $result['init_point'],
                    "transaction_id" => $result['transaction_id']
                ]);
            }

            // Pagamento completo com saldo
            return Response::json([
                "success" => true,
                "payment_method" => "wallet",
                "wallet_amount_used" => $walletAmount,
                "new_balance" => $this->creditService->getBalance($userId),
                "message" => "Recurso adquirido com sucesso!"
            ]);

        } catch (\Throwable $e) {
            error_log("ERRO purchasePartial: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao processar pagamento parcial"], 500);
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

        $userId = $loggedUser['id'];

        try {
            // Módulos ativos por padrão
            $defaultActiveModules = ['freights', 'marketplace'];
            
            // Verificar se o módulo está ativo para o usuário
            $stmt = $this->db->prepare("
                SELECT * FROM user_modules 
                WHERE user_id = :user_id AND module_key = :module_key AND status = 'active'
            ");
            $stmt->execute([':user_id' => $userId, ':module_key' => $moduleKey]);
            $moduleAccess = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$moduleAccess && !in_array($moduleKey, $defaultActiveModules)) {
                return Response::json([
                    "success" => false, 
                    "message" => "Módulo não está ativo. Solicite acesso primeiro.",
                    "requires_module_activation" => true,
                    "module_key" => $moduleKey
                ], 403);
            }

            // Se é módulo ativo por padrão mas não tem registro, cria um
            if (!$moduleAccess && in_array($moduleKey, $defaultActiveModules)) {
                $stmt = $this->db->prepare("
                    INSERT INTO user_modules (user_id, module_key, status, activated_at) 
                    VALUES (:user_id, :module_key, 'active', NOW())
                    ON DUPLICATE KEY UPDATE status = 'active', activated_at = NOW()
                ");
                $stmt->execute([':user_id' => $userId, ':module_key' => $moduleKey]);
            }

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

            // Se não há token do MercadoPago, não pode processar
            $mpToken = $_ENV['MP_ACCESS_TOKEN'] ?? getenv('MP_ACCESS_TOKEN') ?: '';
            
            if (empty($mpToken)) {
                // Remove transação pendente
                $this->db->prepare("DELETE FROM transactions WHERE id = ?")->execute([$transactionId]);
                return Response::json([
                    "success" => false,
                    "message" => "Gateway de pagamento não configurado. Entre em contato com o suporte."
                ], 500);
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
     * Assina plano de assinatura (plans table)
     */
    public function subscribePlan($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $planId = $data['plan_id'] ?? null;
        if (!$planId) {
            return Response::json(["success" => false, "message" => "Plano não informado"], 400);
        }

        $userId = $loggedUser['id'];

        try {
            // Busca o plano
            $stmt = $this->db->prepare("SELECT * FROM plans WHERE id = :id AND active = 1");
            $stmt->execute([':id' => $planId]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$plan) {
                return Response::json(["success" => false, "message" => "Plano não encontrado"], 404);
            }

            $amount = (float)$plan['price'];
            $billingCycle = $data['billing_cycle'] ?? 'monthly';
            
            // Determina preço pelo ciclo
            switch ($billingCycle) {
                case 'quarterly': $amount = (float)($plan['price_quarterly'] ?? $plan['price']); break;
                case 'semiannual': $amount = (float)($plan['price_semiannual'] ?? $plan['price']); break;
                case 'yearly': $amount = (float)($plan['price_yearly'] ?? $plan['price']); break;
            }

            error_log("SUBSCRIBE_PLAN: plan_id={$planId}, billing_cycle={$billingCycle}, amount={$amount}, plan_price={$plan['price']}");

            // Plano gratuito - ativa direto
            if ($amount <= 0) {
                $this->activatePlan($userId, $plan);
                return Response::json([
                    "success" => true,
                    "message" => "Plano ativado com sucesso!",
                    "payment_not_required" => true,
                    "plan_name" => $plan['name']
                ]);
            }

            // Se não há token do MercadoPago, não pode processar
            $mpToken = $_ENV['MP_ACCESS_TOKEN'] ?? getenv('MP_ACCESS_TOKEN') ?: '';
            
            if (empty($mpToken)) {
                return Response::json([
                    "success" => false,
                    "message" => "Gateway de pagamento não configurado. Entre em contato com o suporte."
                ], 500);
            }

            // Com MP token → cria preferência (MercadoPagoService cria a transação)
            $duration = $plan['duration_days'] ?? 30;
            $mpData = [
                'title' => $plan['name'] . " - Chama Frete",
                'amount' => $amount,
                'plan_id' => $planId,
                'billing_cycle' => $billingCycle,
                'duration_days' => $duration
            ];

            error_log("SUBSCRIBE_PLAN: Criando preferência MP - amount={$amount}");

            $result = $this->mpService->createPreference($mpData, $userId);

            error_log("SUBSCRIBE_PLAN: Resultado MP - " . json_encode($result));

            if (empty($result['init_point'])) {
                error_log("MercadoPago Error: Falha ao criar preferência para plano {$planId}");
                return Response::json([
                    "success" => false,
                    "message" => "Erro ao processar pagamento. Tente novamente ou entre em contato com o suporte."
                ], 500);
            }

            return Response::json([
                "success" => true,
                "url" => $result['init_point'],
                "transaction_id" => $result['transaction_id'],
                "amount" => $amount
            ]);

        } catch (\Throwable $e) {
            error_log("ERRO subscribePlan: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return Response::json(["success" => false, "message" => "Erro ao processar assinatura: " . $e->getMessage()], 500);
        }
    }

    /**
     * Ativa um plano para o usuário
     */
    private function activatePlan($userId, $plan) {
        $duration = $plan['duration_days'] ?? 30;
        $billingType = $plan['billing_type'] ?? 'subscription';
        
        // Determina o module_key baseado na categoria do plano
        $moduleKey = match($plan['category']) {
            'freight_subscription' => 'freights',
            'marketplace_subscription' => 'marketplace',
            'advertising' => 'advertiser',
            default => null
        };

        // Planos 'one_time' não expiram
        $expiresAt = ($billingType === 'one_time') ? null : "DATE_ADD(NOW(), INTERVAL {$duration} DAY)";

        // Verifica se já existe transação aprovada para este plano
        $stmtCheck = $this->db->prepare("SELECT id FROM transactions WHERE user_id = ? AND plan_id = ? AND status = 'approved' LIMIT 1");
        $stmtCheck->execute([$userId, $plan['id']]);
        $existingTx = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingTx) {
            // Cria transação para o plano
            $stmt = $this->db->prepare("
                INSERT INTO transactions (user_id, plan_id, module_key, transaction_type, amount, status, created_at, approved_at)
                VALUES (:user_id, :plan_id, :module_key, 'subscription', :amount, 'approved', NOW(), NOW())
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':plan_id' => $plan['id'],
                ':module_key' => $moduleKey,
                ':amount' => 0
            ]);
            error_log("TRANSACTION_CREATED: plano_id={$plan['id']}, user_id={$userId}, modulo={$moduleKey}");
        } else {
            error_log("TRANSACTION_EXISTS: plano_id={$plan['id']}, user_id={$userId}, tx_id={$existingTx['id']}");
        }

        // Ativa o módulo correspondente
        if ($moduleKey) {
            if ($expiresAt === null) {
                // Plano one_time: sem expiração
                $stmt = $this->db->prepare("
                    INSERT INTO user_modules (user_id, module_key, status, activated_at, expires_at, plan_id) 
                    VALUES (:user_id, :module_key, 'active', NOW(), NULL, :plan_id)
                    ON DUPLICATE KEY UPDATE status = 'active', activated_at = NOW(), expires_at = NULL, plan_id = :plan_id2
                ");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':module_key' => $moduleKey,
                    ':plan_id' => $plan['id'],
                    ':plan_id2' => $plan['id']
                ]);
            } else {
                // Plano com expiração
                $stmt = $this->db->prepare("
                    INSERT INTO user_modules (user_id, module_key, status, activated_at, expires_at, plan_id) 
                    VALUES (:user_id, :module_key, 'active', NOW(), {$expiresAt}, :plan_id)
                    ON DUPLICATE KEY UPDATE status = 'active', activated_at = NOW(), expires_at = {$expiresAt}, plan_id = :plan_id2
                ");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':module_key' => $moduleKey,
                    ':plan_id' => $plan['id'],
                    ':plan_id2' => $plan['id']
                ]);
            }
        }

        // Aplica selo verificado se o plano permitir
        if ($plan['has_verification_badge']) {
            $this->applyVerificationBadge($userId, $plan['id']);
        }
    }

    /**
     * Recebe notificações automáticas do Mercado Pago (IPN/Webhooks)
     */
    public function webhook() {
        $raw = file_get_contents("php://input");
        $data = json_decode($raw, true) ?? [];
        // Verifica assinatura do webhook (seguro) – se não configurado, continua (modo dev)
        $headers = getallheaders();
        $signature = $headers['X-MercadoPago-Signature'] ?? $headers['x_mp_signature'] ?? '';
        if (!$this->mpService->isWebhookSignatureValid($raw, $signature)) {
            return Response::json(["success" => false, "message" => "Webhook signature inválida"], 403);
        }
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
                // Primeiro verifica transação de módulo (com module_key)
                $stmt = $this->db->prepare("SELECT * FROM transactions WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $externalRef]);
                $tx = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($tx) {
                    // Se tem module_key, processa como módulo
                    if (!empty($tx['module_key'])) {
                        $isDriverVerification = ($tx['module_key'] === 'driver' && $tx['feature_key'] === 'document_verification');
                        
                        if ($isDriverVerification) {
                            $stmt = $this->db->prepare("
                                UPDATE transactions 
                                SET status = 'awaiting_review', gateway_id = :mp_id, payment_method = :method, approved_at = NOW() 
                                WHERE id = :id
                            ");
                            $stmt->execute([':mp_id' => $resourceId, ':method' => $paymentData['payment_method_id'] ?? 'mercadopago', ':id' => $externalRef]);
                            error_log("DRIVER VERIFICATION: Pagamento confirmado para usuário {$tx['user_id']}. Aguardando revisão manual.");
                            return Response::json(["status" => "awaiting_manual_review"], 200);
                        }
                        
                        $stmt = $this->db->prepare("UPDATE transactions SET status = 'approved', gateway_id = :mp_id, payment_method = :method, approved_at = NOW() WHERE id = :id");
                        $stmt->execute([':mp_id' => $resourceId, ':method' => $paymentData['payment_method_id'] ?? 'mercadopago', ':id' => $externalRef]);
                        
                        $expiresAt = ($tx['transaction_type'] === 'monthly') ? date('Y-m-d H:i:s', strtotime('+30 days')) : null;
                        
                        $stmt = $this->db->prepare("
                            INSERT INTO user_modules (user_id, module_key, status, activated_at, expires_at) 
                            VALUES (:user_id, :module_key, 'active', NOW(), :expires_at)
                            ON DUPLICATE KEY UPDATE status = 'active', activated_at = NOW(), expires_at = :expires_at2
                        ");
                        $stmt->execute([':user_id' => $tx['user_id'], ':module_key' => $tx['module_key'], ':expires_at' => $expiresAt, ':expires_at2' => $expiresAt]);
                        $this->applyVerificationBadge($tx['user_id'], $tx['plan_id']);
                        return Response::json(["status" => "module_activated"], 200);
                    }
                    
                    // Se tem plan_id mas não module_key, processa como assinatura de plano
                    if (!empty($tx['plan_id'])) {
                        $stmt = $this->db->prepare("SELECT * FROM plans WHERE id = :id AND active = 1");
                        $stmt->execute([':id' => $tx['plan_id']]);
                        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($plan) {
                            $stmt = $this->db->prepare("UPDATE transactions SET status = 'approved', gateway_id = :mp_id, payment_method = :method, approved_at = NOW() WHERE id = :id");
                            $stmt->execute([':mp_id' => $resourceId, ':method' => $paymentData['payment_method_id'] ?? 'mercadopago', ':id' => $externalRef]);
                            
                            $duration = $tx['duration_days'] ?? $plan['duration_days'] ?? 30;
                            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$duration} days"));
                            
                            $moduleKey = match($plan['category']) {
                                'freight_subscription' => 'freights',
                                'marketplace_subscription' => 'marketplace',
                                'advertising' => 'advertiser',
                                default => null
                            };
                            
                            if ($moduleKey) {
                                $stmt = $this->db->prepare("
                                    INSERT INTO user_modules (user_id, module_key, status, activated_at, expires_at, plan_id) 
                                    VALUES (:user_id, :module_key, 'active', NOW(), :expires_at, :plan_id)
                                    ON DUPLICATE KEY UPDATE status = 'active', activated_at = NOW(), expires_at = :expires_at2, plan_id = :plan_id2
                                ");
                                $stmt->execute([':user_id' => $tx['user_id'], ':module_key' => $moduleKey, ':expires_at' => $expiresAt, ':expires_at2' => $expiresAt, ':plan_id' => $tx['plan_id'], ':plan_id2' => $tx['plan_id']]);
                            }
                            
                            if ($plan['has_verification_badge']) {
                                $stmt = $this->db->prepare("UPDATE users SET is_verified = 1, verified_until = :until, verified_at = NOW() WHERE id = :id");
                                $stmt->execute([':until' => $expiresAt, ':id' => $tx['user_id']]);
                            }
                            
                            error_log("PLAN SUBSCRIPTION: Usuário {$tx['user_id']} assinou plano {$plan['name']} (ID {$plan['id']})");
                            return Response::json(["status" => "plan_activated"], 200);
                        }
                    }
                    
                    // Processa promoção de frete (destaque/urgente)
                    if (!empty($tx['feature_key']) && strpos($tx['feature_key'], 'freight_promotion_') === 0) {
                        $externalRef = $tx['external_reference'] ?? '';
                        if (preg_match('/^FRET(\d+)_(\w+)$/', $externalRef, $matches)) {
                            $freightId = intval($matches[1]);
                            $promotionType = $matches[2]; // 'boost' ou 'urgent'
                            
                            $durationDays = $tx['duration_days'] ?? 7;
                            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$durationDays} days"));
                            
                            if ($promotionType === 'boost') {
                                $stmt = $this->db->prepare("UPDATE freights SET is_featured = 1, featured_until = :until WHERE id = :id");
                                $stmt->execute([':until' => $expiresAt, ':id' => $freightId]);
                                error_log("FREIGHT PROMOTION: Frete {$freightId} destacado até {$expiresAt}");
                            }
                            
                            if ($promotionType === 'urgent') {
                                $stmt = $this->db->prepare("UPDATE freights SET is_urgent = 1, urgent_until = :until WHERE id = :id");
                                $stmt->execute([':until' => $expiresAt, ':id' => $freightId]);
                                error_log("FREIGHT PROMOTION: Frete {$freightId} marcado como urgente até {$expiresAt}");
                            }
                            
                            $stmt = $this->db->prepare("UPDATE transactions SET status = 'approved', gateway_id = :mp_id, approved_at = NOW() WHERE id = :id");
                            $stmt->execute([':mp_id' => $resourceId, ':id' => $externalRef]);
                            
                            return Response::json(["status" => "freight_promoted"], 200);
                        }
                    }
                }
                
                $success = $this->mpService->verifyPaymentStatus($resourceId);
                if ($success) {
                    return Response::json(["status" => "approved_and_processed"], 200);
                }
            }
            
            return Response::json(["status" => "received_but_pending_or_failed"], 200);
        }

        return Response::json(["status" => "ignored"], 200);
    }

    /**
     * Get status of a MercadoPago payment by external reference (transaction id)
     * MVP helper endpoint
     */
    public function getPaymentStatus($data) {
        $externalRef = $data['external_ref'] ?? $data['payment_id'] ?? null;
        if (!$externalRef) {
            return Response::json(["success" => false, "message" => "Referência de pagamento não informada"], 400);
        }
        try {
            // Primeiro tenta pelo DB (transação existente)
            $tx = $this->paymentRepo->getTransactionByExternalId($externalRef);
            if ($tx) {
                return Response::json(["success" => true, "status" => $tx['status'], "transaction" => $tx]);
            }
            // Se não encontrado, fallback para MP
            $updated = $this->mpService->verifyPaymentStatus($externalRef);
            return Response::json(["success" => true, "status_updated" => $updated]);
        } catch (\Throwable $e) {
            error_log("ERRO getPaymentStatus: " . $e->getMessage());
            return Response::json(["success" => false, "message" => $e->getMessage()], 500);
        }
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
                SELECT id, plan_id, module_key, feature_key, transaction_type, 
                       amount, status, created_at, approved_at
                FROM transactions 
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT 100
            ");
            $stmt->execute([$userId]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Mapas para exibição
            $categoryMap = [
                'freight_subscription' => 'Frete',
                'marketplace_subscription' => 'Marketplace',
                'advertising' => 'Publicidade',
                'freights' => 'Frete',
                'marketplace' => 'Marketplace',
                'advertiser' => 'Anúncios',
                'driver' => 'Motorista',
                'quotes' => 'Cotações',
                'wallet_recharge' => 'Recarga',
                'wallet_debit' => 'Débito',
                'wallet_refund' => 'Estorno',
            ];

            // Ícones dos módulos
            $moduleIconMap = [
                'freight_subscription' => 'truck',
                'marketplace_subscription' => 'shopping-bag',
                'advertising' => 'megaphone',
                'freights' => 'truck',
                'marketplace' => 'shopping-bag',
                'advertiser' => 'megaphone',
                'driver' => 'user',
                'quotes' => 'file-text',
            ];
            
            foreach ($transactions as &$tx) {
                // Determina o módulo para badge
                $moduleKey = $tx['module_key'] ?? '';
                $tx['module_icon'] = $moduleIconMap[$moduleKey] ?? 'credit-card';
                $tx['category_label'] = $categoryMap[$moduleKey] ?? 'Outro';

                // Busca nome real do plano se existir
                if (!empty($tx['plan_id'])) {
                    $stmtPlan = $this->db->prepare("SELECT name FROM plans WHERE id = ?");
                    $stmtPlan->execute([$tx['plan_id']]);
                    $plan = $stmtPlan->fetch(PDO::FETCH_ASSOC);
                    if ($plan) {
                        $tx['display_name'] = $plan['name'];
                        $tx['plan_name'] = $plan['name'];
                        // Atualiza category_label com base na categoria do plano
                        $stmtCat = $this->db->prepare("SELECT category FROM plans WHERE id = ?");
                        $stmtCat->execute([$tx['plan_id']]);
                        $planCat = $stmtCat->fetch(PDO::FETCH_ASSOC);
                        if ($planCat && isset($categoryMap[$planCat['category']])) {
                            $tx['category_label'] = $categoryMap[$planCat['category']];
                            $tx['module_icon'] = $moduleIconMap[$planCat['category']] ?? 'credit-card';
                        }
                    }
                }
                // Busca nome real do feature se existir
                elseif (!empty($tx['feature_key'])) {
                    $stmtFeature = $this->db->prepare("SELECT feature_name FROM pricing_rules WHERE feature_key = ? LIMIT 1");
                    $stmtFeature->execute([$tx['feature_key']]);
                    $feature = $stmtFeature->fetch(PDO::FETCH_ASSOC);
                    if ($feature) {
                        $tx['display_name'] = $feature['feature_name'];
                    } else {
                        $tx['display_name'] = ucfirst(str_replace('_', ' ', $tx['feature_key']));
                    }
                } else {
                    $tx['display_name'] = ucfirst(str_replace('_', ' ', $moduleKey ?: 'Assinatura'));
                }
            }

            return Response::json([
                "success" => true,
                "data" => $transactions
            ]);
        } catch (\Throwable $e) {
            error_log("ERRO getMyTransactions: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao buscar transações: " . $e->getMessage()], 500);
        }
    }

    /**
     * Compra de Verificação de Documentos para Drivers
     * Cria transação com status 'awaiting_review' após confirmação do pagamento
     */
    public function purchaseDriverVerification($data, $loggedUser) {
        error_log("DRIVER VERIFICATION: Método iniciado");
        
        if (!$loggedUser || !isset($loggedUser['id'])) {
            error_log("DRIVER VERIFICATION: Usuário não autenticado");
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $userId = $loggedUser['id'];
        error_log("DRIVER VERIFICATION: Usuário ID={$userId}, role={$loggedUser['role']}");

        // Verifica se é driver (role vem em maiúsculas do JWT)
        $userRole = $loggedUser['role'] ?? '';
        if ($userRole !== 'DRIVER') {
            error_log("DRIVER VERIFICATION: Usuário {$userId} não é driver, role='{$userRole}'");
            return Response::json(["success" => false, "message" => "Apenas motoristas podem usar este recurso"], 403);
        }

        // Verifica se já tem verificação ativa
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? AND is_verified = 1 AND verified_until > NOW()");
        $stmt->execute([$userId]);
        $currentVerification = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($currentVerification) {
            error_log("DRIVER VERIFICATION: Usuário {$userId} já tem verificação ativa");
            return Response::json([
                "success" => false, 
                "message" => "Você já possui verificação ativa até " . date('d/m/Y', strtotime($currentVerification['verified_until']))
            ], 400);
        }

        // Busca preço mensal da verificação de documentos
        $stmt = $this->db->prepare("
            SELECT * FROM pricing_rules 
            WHERE module_key = 'driver' AND feature_key = 'document_verification' AND is_active = 1
        ");
        $stmt->execute();
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rule) {
            error_log("DRIVER VERIFICATION: Regra de preço não encontrada");
            return Response::json(["success" => false, "message" => "Regra de preço não encontrada"], 400);
        }

        $amount = (float)$rule['price_monthly'];
        error_log("DRIVER VERIFICATION: Amount={$amount}");

        if ($amount <= 0) {
            error_log("DRIVER VERIFICATION: Preço inválido {$amount}");
            return Response::json(["success" => false, "message" => "Preço não configurado para este recurso"], 400);
        }

        // Verifica se há transação pendente ou awaiting_review
        $stmt = $this->db->prepare("
            SELECT * FROM transactions 
            WHERE user_id = ? AND module_key = 'driver' AND feature_key = 'document_verification' 
            AND status IN ('pending', 'awaiting_review')
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$userId]);
        $pendingTx = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pendingTx) {
            error_log("DRIVER VERIFICATION: Usuário {$userId} já tem transação pendente");
            return Response::json([
                "success" => false, 
                "message" => "Você já possui uma solicitação pendente de verificação. Aguarde a análise."
            ], 400);
        }

        // Verifica se já foi rejeitada recentemente (para mostrar instruções)
        $stmt = $this->db->prepare("
            SELECT * FROM transactions 
            WHERE user_id = ? AND module_key = 'driver' AND feature_key = 'document_verification' 
            AND status = 'rejected'
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$userId]);
        $rejectedTx = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verifica saldo na carteira
        $balance = $this->creditService->getBalance($userId);
        if ($balance < $amount) {
            error_log("DRIVER VERIFICATION: Saldo insuficiente. Saldo={$balance}, Necessário={$amount}");
            return Response::json([
                "success" => false, 
                "message" => "Saldo insuficiente. Você precisa de R$ " . number_format($amount, 2, ',', '.') . " para solicitar a verificação. Saldo atual: R$ " . number_format($balance, 2, ',', '.') . ".",
                "insufficient_balance" => true,
                "balance" => $balance,
                "required" => $amount,
                "rejection_reason" => $rejectedTx['rejection_reason'] ?? null
            ], 400);
        }

        try {
            // Debita da carteira
            $debitado = $this->creditService->debit($userId, $amount, 'driver', 'document_verification');
            
            if (!$debitado) {
                error_log("DRIVER VERIFICATION: Falha ao debitar carteira");
                return Response::json([
                    "success" => false, 
                    "message" => "Erro ao processar pagamento. Tente novamente."
                ], 500);
            }

            // Registra transação com status 'awaiting_review'
            $stmt = $this->db->prepare("
                INSERT INTO transactions 
                (user_id, module_key, feature_key, transaction_type, amount, status, external_reference, created_at)
                VALUES (:user_id, :module_key, :feature_key, 'monthly', :amount, 'awaiting_review', :external_ref, NOW())
            ");
            $externalRef = 'DRIVER_VERIFY_' . uniqid();
            $stmt->execute([
                ':user_id' => $userId,
                ':module_key' => 'driver',
                ':feature_key' => 'document_verification',
                ':amount' => $amount,
                ':external_ref' => $externalRef
            ]);
            $transactionId = $this->db->lastInsertId();
            error_log("DRIVER VERIFICATION: Transação {$transactionId} criada. Valor: {$amount}");

            return Response::json([
                "success" => true,
                "message" => "Solicitação enviada! Sua verificação está aguardando análise da equipe Chama Frete.",
                "payment_processed" => true,
                "transaction_id" => $transactionId,
                "status" => "awaiting_review",
                "amount_charged" => $amount
            ]);

        } catch (\Throwable $e) {
            error_log("DRIVER VERIFICATION ERRO: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return Response::json(["success" => false, "message" => "Erro ao processar verificação: " . $e->getMessage()], 500);
        }
    }

    /**
     * Obter status da verificação do driver
     */
    public function getDriverVerificationStatus($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $userId = $loggedUser['id'];

        // Busca verificação ativa
        $stmt = $this->db->prepare("
            SELECT is_verified, verified_at, verified_until 
            FROM users WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Busca transação mais recente
        $stmt = $this->db->prepare("
            SELECT id, status, created_at, approved_at, rejection_reason 
            FROM transactions 
            WHERE user_id = ? AND module_key = 'driver' AND feature_key = 'document_verification'
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$userId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        // Busca documentos do usuário (exclui replaced, só pega o mais recente de cada tipo)
        $stmt = $this->db->prepare("
            SELECT id, document_type, file_path, status, rejection_reason, created_at 
            FROM user_documents 
            WHERE entity_id = ? AND entity_type = 'user' AND status != 'replaced'
            ORDER BY id DESC
        ");
        $stmt->execute([$userId]);
        $allDocuments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Filtra para pegar apenas o mais recente de cada tipo
        $documents = [];
        $seenTypes = [];
        foreach ($allDocuments as $doc) {
            if (!in_array($doc['document_type'], $seenTypes)) {
                // Considera empty string como rejected
                if ($doc['status'] === '' || $doc['status'] === null) {
                    $doc['status'] = 'rejected';
                    if (empty($doc['rejection_reason'])) {
                        $doc['rejection_reason'] = 'Documento enviado anteriormente';
                    }
                }
                $documents[] = $doc;
                $seenTypes[] = $doc['document_type'];
            }
        }

        $isActive = $user && $user['is_verified'] == 1 && 
                    $user['verified_until'] && 
                    strtotime($user['verified_until']) > time();

        return Response::json([
            "success" => true,
            "data" => [
                "is_verified" => $isActive,
                "verified_until" => $user['verified_until'] ?? null,
                "last_transaction_status" => $transaction['status'] ?? null,
                "last_transaction_id" => $transaction['id'] ?? null,
                "rejection_reason" => $transaction['rejection_reason'] ?? null,
                "documents" => $documents
            ]
        ]);
    }

    /**
     * Upload de documento para verificação
     */
    public function uploadDocument($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $userId = $loggedUser['id'];
        $documentType = $data['document_type'] ?? '';

        // Tipos de documento permitidos
        $allowedTypes = ['cnh_front', 'cnh_back', 'crlv', 'rg', 'address_proof'];
        if (!in_array($documentType, $allowedTypes)) {
            return Response::json([
                "success" => false, 
                "message" => "Tipo de documento inválido. Tipos permitidos: " . implode(', ', $allowedTypes)
            ], 400);
        }

        // Verifica se arquivo foi enviado
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'Arquivo excede o limite do servidor',
                UPLOAD_ERR_FORM_SIZE => 'Arquivo excede o limite do formulário',
                UPLOAD_ERR_PARTIAL => 'Arquivo foi enviado parcialmente',
                UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado',
                UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária não encontrada',
                UPLOAD_ERR_CANT_WRITE => 'Erro ao salvar arquivo',
            ];
            $error = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            return Response::json([
                "success" => false, 
                "message" => $errorMessages[$error] ?? 'Erro ao enviar arquivo'
            ], 400);
        }

        $file = $_FILES['file'];

        // Validação de tamanho (2MB)
        $maxSize = 2 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            return Response::json([
                "success" => false, 
                "message" => 'Arquivo muito grande. Máximo: 2MB'
            ], 400);
        }

        // Validação de tipo MIME
        $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedMimes)) {
            return Response::json([
                "success" => false, 
                "message" => 'Tipo de arquivo não permitido. Use JPG, PNG ou PDF'
            ], 400);
        }

        // Determina extensão
        $ext = match($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => 'jpg'
        };

        // Cria pasta se não existir
        $uploadDir = __DIR__ . '/../../public/uploads/documents/' . $userId;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Gera nome único
        $filename = $documentType . '_' . uniqid() . '.' . $ext;
        $filePath = '/uploads/documents/' . $userId . '/' . $filename;
        $fullPath = $uploadDir . '/' . $filename;

        // Move arquivo
        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            return Response::json([
                "success" => false, 
                "message" => 'Erro ao salvar arquivo'
            ], 500);
        }

        // Remove documento anterior do mesmo tipo (QUALQUER status, exceto o que será inserido)
        // Primeiro, pega o ID máximo atual para evitar marcar o novo documento
        $stmt = $this->db->prepare("
            SELECT id FROM user_documents 
            WHERE entity_id = ? AND entity_type = 'user' AND document_type = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$userId, $documentType]);
        $latestDoc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Marca todos os outros documentos do mesmo tipo como 'replaced'
        $sql = "UPDATE user_documents SET status = 'replaced' WHERE entity_id = ? AND entity_type = 'user' AND document_type = ?";
        $params = [$userId, $documentType];
        
        if ($latestDoc) {
            $sql .= " AND id != ?";
            $params[] = $latestDoc['id'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        // Salva no banco
        $stmt = $this->db->prepare("
            INSERT INTO user_documents 
            (entity_id, entity_type, document_type, file_path, status, created_at)
            VALUES (?, 'user', ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$userId, $documentType, $filePath]);
        $docId = $this->db->lastInsertId();

        error_log("DOCUMENT UPLOAD: Usuário {$userId} enviou {$documentType}, arquivo {$filePath}");

        return Response::json([
            "success" => true,
            "message" => "Documento enviado com sucesso!",
            "document" => [
                "id" => $docId,
                "document_type" => $documentType,
                "file_path" => $filePath,
                "status" => "pending"
            ]
        ]);
    }

    /**
     * Lista documentos do usuário logado
     */
    public function listMyDocuments($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $userId = $loggedUser['id'];

        $stmt = $this->db->prepare("
            SELECT id, document_type, file_path, status, rejection_reason, created_at
            FROM user_documents 
            WHERE entity_id = ? AND entity_type = 'user' AND status != 'replaced'
            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Tipos obrigatórios
        $requiredTypes = ['cnh_front', 'cnh_back', 'crlv', 'rg', 'address_proof'];
        $uploadedTypes = array_column($documents, 'document_type');
        $missingTypes = array_diff($requiredTypes, $uploadedTypes);

        return Response::json([
            "success" => true,
            "documents" => $documents,
            "required_types" => $requiredTypes,
            "missing_types" => array_values($missingTypes),
            "all_uploaded" => empty($missingTypes)
        ]);
    }

    /**
     * Deleta documento do usuário
     */
    public function deleteDocument($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $userId = $loggedUser['id'];
        $docId = $data['document_id'] ?? null;

        if (!$docId) {
            return Response::json(["success" => false, "message" => "ID do documento não informado"], 400);
        }

        // Verifica se documento pertence ao usuário
        $stmt = $this->db->prepare("
            SELECT * FROM user_documents 
            WHERE id = ? AND entity_id = ? AND entity_type = 'user'
        ");
        $stmt->execute([$docId, $userId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$document) {
            return Response::json(["success" => false, "message" => "Documento não encontrado"], 404);
        }

        // Remove arquivo físico
        $fullPath = __DIR__ . '/../../public' . $document['file_path'];
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }

        // Remove do banco
        $stmt = $this->db->prepare("DELETE FROM user_documents WHERE id = ?");
        $stmt->execute([$docId]);

        return Response::json([
            "success" => true,
            "message" => "Documento removido com sucesso"
        ]);
    }

    /**
     * Verifica se todos os documentos obrigatórios foram enviados
     */
    public function checkRequiredDocuments($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $userId = $loggedUser['id'];

        // Busca todos os documentos (exceto replaced) - considera empty string como rejeitado
        $stmt = $this->db->prepare("
            SELECT document_type, status, rejection_reason 
            FROM user_documents 
            WHERE entity_id = ? AND entity_type = 'user' AND status != 'replaced'
            ORDER BY id DESC
        ");
        $stmt->execute([$userId]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Status de cada documento (só pega o mais recente de cada tipo)
        $documentsStatus = [];
        $uploadedTypes = [];
        $pendingTypes = [];
        $rejectedDocs = [];
        $seenTypes = [];
        
        foreach ($documents as $doc) {
            // Só considera o documento mais recente de cada tipo
            if (in_array($doc['document_type'], $seenTypes)) {
                continue;
            }
            $seenTypes[] = $doc['document_type'];
            
            // Considera empty string ou rejected como rejeitado
            $isRejected = ($doc['status'] === 'rejected' || $doc['status'] === '');
            
            $documentsStatus[$doc['document_type']] = [
                'status' => $isRejected ? 'rejected' : $doc['status'],
                'rejection_reason' => $doc['rejection_reason'] ?? null
            ];
            $uploadedTypes[] = $doc['document_type'];
            
            if ($doc['status'] === 'pending') {
                $pendingTypes[] = $doc['document_type'];
            }
            
            if ($isRejected) {
                $rejectedDocs[] = [
                    'type' => $doc['document_type'],
                    'reason' => $doc['rejection_reason'] ?: 'Documento enviado anteriormente'
                ];
            }
        }

        $requiredTypes = ['cnh_front', 'cnh_back', 'crlv', 'rg', 'address_proof'];
        $missingTypes = array_diff($requiredTypes, $uploadedTypes);

        return Response::json([
            "success" => true,
            "all_uploaded" => empty($missingTypes),
            "missing_types" => array_values($missingTypes),
            "has_pending" => !empty($pendingTypes),
            "has_rejected" => !empty($rejectedDocs),
            "rejected_docs" => $rejectedDocs,
            "documents_status" => $documentsStatus,
            "documents_uploaded" => $uploadedTypes
        ]);
    }

    /**
     * Promove um frete (destaque/urgente) via pagamento
     */
    public function promoteFreight($data) {
        $user = \App\Core\Auth::requireAuth();
        $userId = $user['id'];
        
        $freightId = $data['freight_id'] ?? null;
        $type = $data['type'] ?? null; // 'featured' ou 'urgent'
        
        if (!$freightId || !$type || !in_array($type, ['boost', 'urgent'])) {
            return Response::json(["success" => false, "message" => "Tipo inválido. Use 'boost' ou 'urgent'"], 400);
        }
        
        // Busca preço e duração do pricing_rules
        $featureKey = $type; // 'boost' ou 'urgent'
        $stmt = $this->db->prepare("SELECT * FROM pricing_rules WHERE module_key = 'freights' AND feature_key = :key");
        $stmt->execute([':key' => $featureKey]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Fallback com valores padrão se não encontrar
        $finalPrice = $rule ? floatval($rule['price_per_use']) : ($type === 'boost' ? 9.90 : 14.90);
        $durationDays = $rule ? intval($rule['duration_days'] ?? 7) : 7;
        
        // Verifica se o frete pertence ao usuário
        $stmt = $this->db->prepare("SELECT * FROM freights WHERE id = :id AND user_id = :user_id");
        $stmt->execute([':id' => $freightId, ':user_id' => $userId]);
        $freight = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$freight) {
            return Response::json(["success" => false, "message" => "Frete não encontrado"], 404);
        }
        
        // Verifica se já tem destaque/urgente ativo
        if ($type === 'boost') {
            if (!empty($freight['is_featured']) && !empty($freight['featured_until']) && 
                strtotime($freight['featured_until']) > time()) {
                return Response::json(["success" => false, "message" => "Frete já possui destaque ativo"], 400);
            }
        } else {
            if (!empty($freight['is_urgent']) && !empty($freight['urgent_until']) && 
                strtotime($freight['urgent_until']) > time()) {
                return Response::json(["success" => false, "message" => "Frete já possui marcação urgente ativa"], 400);
            }
        }
        
        $typeNames = [
            'boost' => 'Destaque Frete',
            'urgent' => 'Frete Urgente'
        ];
        
        $paymentData = [
            'freight_id' => $freightId,
            'type' => $type,
            'amount' => $finalPrice,
            'title' => "Impulsionar Frete #{$freightId} - {$typeNames[$type]}",
            'duration_days' => $durationDays,
            'description' => "{$durationDays} dias de visibilidade para {$freight['product']} ({$freight['origin_city']} → {$freight['dest_city']})"
        ];
        
        $result = $this->mpService->createFreightPromotionPreference($paymentData, $userId);
        
        if (!empty($result['init_point'])) {
            return Response::json([
                "success" => true,
                "checkout_url" => $result['init_point'],
                "transaction_id" => $result['transaction_id'],
                "amount" => $finalPrice,
                "type" => $type,
                "duration_days" => $durationDays
            ]);
        }
        
        return Response::json(["success" => false, "message" => "Erro ao gerar link de pagamento"], 500);
    }
}
