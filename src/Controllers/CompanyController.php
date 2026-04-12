<?php

namespace App\Controllers;

use App\Core\Response;
use App\Core\Auth;
use Exception;
use PDO;

class CompanyController {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getVerificationStatus($data, $loggedUser) {
        try {
            if (!$loggedUser || !isset($loggedUser['id'])) {
                return Response::json([
                    "success" => false,
                    "message" => "Usuário não autenticado"
                ], 401);
            }
            
            if (strtoupper($loggedUser['role']) !== 'COMPANY') {
                return Response::json([
                    "success" => false,
                    "message" => "Acesso negado. Apenas empresas podem acessar este recurso."
                ], 403);
            }
            
            $userId = $loggedUser['id'];

            $stmt = $this->db->prepare("
                SELECT v.*, 
                    u.name as reviewed_by_name,
                    tr.amount as transaction_amount,
                    tr.status as transaction_status
                FROM verified_cnpj v
                LEFT JOIN users u ON v.reviewed_by = u.id
                LEFT JOIN transactions tr ON tr.user_id = v.user_id 
                    AND tr.module_key = 'company_pro' 
                    AND tr.feature_key = 'identity_verification'
                    AND tr.status = 'approved'
                    AND tr.created_at >= v.created_at
                WHERE v.user_id = ?
                ORDER BY v.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $verification = $stmt->fetch(PDO::FETCH_ASSOC);

            $isVerified = $verification && $verification['status'] === 'verified';
            $hasPending = $verification && $verification['status'] === 'pending';

            $moduleStmt = $this->db->prepare("
                SELECT is_active FROM user_modules 
                WHERE user_id = ? AND module_key = 'company_pro'
            ");
            $moduleStmt->execute([$userId]);
            $module = $moduleStmt->fetch();

            $hasContracted = $module && $module['is_active'] == 1;

            return Response::json([
                "success" => true,
                "data" => [
                    "is_verified" => $isVerified,
                    "has_pending" => $hasPending,
                    "has_contracted" => $hasContracted,
                    "verification" => $verification ? [
                        "id" => $verification['id'],
                        "cnpj" => $verification['cnpj'],
                        "razao_social" => $verification['razao_social'],
                        "nome_fantasia" => $verification['nome_fantasia'],
                        "situacao" => $verification['situacao'],
                        "status" => $verification['status'],
                        "rejection_reason" => $verification['rejection_reason'],
                        "created_at" => $verification['created_at'],
                        "verified_at" => $verification['verified_at']
                    ] : null
                ]
            ]);
        } catch (Exception $e) {
            error_log("getVerificationStatus error: " . $e->getMessage());
            return Response::json([
                "success" => false,
                "message" => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    public function verifyCnpj($data, $loggedUser) {
        try {
            if (!$loggedUser || strtoupper($loggedUser['role'] ?? '') !== 'COMPANY') {
                return Response::json([
                    "success" => false,
                    "message" => "Acesso negado"
                ], 403);
            }
            
            $cnpj = preg_replace('/\D/', '', $data['cnpj'] ?? '');

            if (strlen($cnpj) !== 14) {
                return Response::json([
                    "success" => false,
                    "message" => "CNPJ inválido. Deve conter 14 dígitos."
                ], 400);
            }

            $cnpjData = $this->consultarReceitaWS($cnpj);

            if (!$cnpjData) {
                return Response::json([
                    "success" => false,
                    "message" => "Não foi possível consultar o CNPJ. Tente novamente."
                ], 500);
            }

            if (isset($cnpjData['status']) && $cnpjData['status'] === 'ERROR') {
                return Response::json([
                    "success" => false,
                    "message" => $cnpjData['message'] ?? "CNPJ não encontrado."
                ], 400);
            }

            return Response::json([
                "success" => true,
                "data" => $cnpjData
            ]);
        } catch (Exception $e) {
            error_log("verifyCnpj error: " . $e->getMessage());
            return Response::json([
                "success" => false,
                "message" => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    public function submitVerification($data, $loggedUser) {
        try {
            if (!$loggedUser || strtoupper($loggedUser['role'] ?? '') !== 'COMPANY') {
                return Response::json([
                    "success" => false,
                    "message" => "Acesso negado"
                ], 403);
            }
            
            $userId = $loggedUser['id'];

            $cnpj = preg_replace('/\D/', '', $data['cnpj'] ?? '');
            $razaoSocial = trim($data['razao_social'] ?? '');
            $nomeFantasia = trim($data['nome_fantasia'] ?? '');
            $situacao = trim($data['situacao'] ?? '');

            if (strlen($cnpj) !== 14) {
                return Response::json([
                    "success" => false,
                    "message" => "CNPJ inválido."
                ], 400);
            }

            if (empty($razaoSocial)) {
                return Response::json([
                    "success" => false,
                    "message" => "Razão Social é obrigatória."
                ], 400);
            }

            $stmt = $this->db->prepare("SELECT id FROM verified_cnpj WHERE user_id = ? AND status = 'pending'");
            $stmt->execute([$userId]);
            if ($stmt->fetch()) {
                return Response::json([
                    "success" => false,
                    "message" => "Você já possui uma verificação pendente."
                ], 400);
            }

            $stmt = $this->db->prepare("
                INSERT INTO verified_cnpj 
                (user_id, cnpj, razao_social, nome_fantasia, situacao, status)
                VALUES (?, ?, ?, ?, ?, 'pending')
                ON DUPLICATE KEY UPDATE
                cnpj = VALUES(cnpj),
                razao_social = VALUES(razao_social),
                nome_fantasia = VALUES(nome_fantasia),
                situacao = VALUES(situacao),
                status = 'pending',
                rejection_reason = NULL,
                reviewed_by = NULL,
                reviewed_at = NULL
            ");
            $stmt->execute([
                $userId,
                $cnpj,
                $razaoSocial,
                $nomeFantasia,
                $situacao
            ]);

            $this->logAudit($userId, 'COMPANY_VERIFICATION_SUBMIT', "CNPJ $cnpj submetido para verificação");

            return Response::json([
                "success" => true,
                "message" => "Verificação submetida com sucesso. Aguarde a análise.",
                "data" => [
                    "verification_id" => $this->db->lastInsertId() ?: $userId,
                    "status" => "pending"
                ]
            ]);
        } catch (Exception $e) {
            error_log("submitVerification error: " . $e->getMessage());
            return Response::json([
                "success" => false,
                "message" => "Erro ao submeter verificação."
            ], 500);
        }
    }

    public function purchaseVerification($data, $loggedUser) {
        try {
            if (!$loggedUser || strtoupper($loggedUser['role'] ?? '') !== 'COMPANY') {
                return Response::json([
                    "success" => false,
                    "message" => "Acesso negado"
                ], 403);
            }
            
            $userId = $loggedUser['id'];

            $stmt = $this->db->prepare("SELECT * FROM pricing_rules WHERE module_key = 'company_pro' AND feature_key = 'identity_verification' AND is_active = 1");
            $stmt->execute();
            $rule = $stmt->fetch();

            if (!$rule) {
                return Response::json([
                    "success" => false,
                    "message" => "Regra de preço não encontrada."
                ], 400);
            }

            $price = floatval($rule['price_monthly']);
            
            if ($price <= 0) {
                return Response::json([
                    "success" => false,
                    "message" => "Este recurso não possui preço configurado."
                ], 400);
            }

            $stmt = $this->db->prepare("SELECT balance FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch();
            $balance = floatval($userData['balance'] ?? 0);

            if ($balance < $price) {
                return Response::json([
                    "success" => false,
                    "message" => "Saldo insuficiente. Você precisa de R$ " . number_format($price, 2, ',', '.') . " para contratar este serviço."
                ], 400);
            }

            $this->db->beginTransaction();

            try {
                $stmt = $this->db->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$price, $userId]);

                $stmt = $this->db->prepare("
                    INSERT INTO transactions 
                    (user_id, amount, status, payment_method, module_key, feature_key, transaction_type, gateway_payload, created_at)
                    VALUES (?, ?, 'pending', 'wallet', 'company_pro', 'identity_verification', 'monthly', '{\"source\": \"company_verification\"}', NOW())
                ");
                $stmt->execute([$userId, $price]);
                $transactionId = $this->db->lastInsertId();

                $this->db->commit();

                return Response::json([
                    "success" => true,
                    "message" => "Pagamento processado. Agora você pode enviar seus documentos para verificação.",
                    "data" => [
                        "transaction_id" => $transactionId,
                        "amount_charged" => $price,
                        "new_balance" => $balance - $price,
                        "requires_verification" => true
                    ]
                ]);
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            error_log("purchaseVerification error: " . $e->getMessage());
            return Response::json([
                "success" => false,
                "message" => "Erro ao processar pagamento."
            ], 500);
        }
    }

    private function consultarReceitaWS($cnpj) {
        $url = "https://www.receitaws.com.br/v1/cnpj/" . $cnpj;
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);

        if (!$data || isset($data['status']) && $data['status'] === 'ERROR') {
            return [
                'status' => 'ERROR',
                'message' => $data['message'] ?? 'CNPJ não encontrado'
            ];
        }

        return [
            'cnpj' => $data['cnpj'] ?? $cnpj,
            'razao_social' => $data['nome'] ?? '',
            'nome_fantasia' => $data['fantasia'] ?? '',
            'situacao' => $data['situacao'] ?? '',
            'logradouro' => $data['logradouro'] ?? '',
            'numero' => $data['numero'] ?? '',
            'complemento' => $data['complemento'] ?? '',
            'bairro' => $data['bairro'] ?? '',
            'cidade' => $data['municipio'] ?? '',
            'estado' => $data['uf'] ?? '',
            'cep' => $data['cep'] ?? '',
            'telefone' => $data['telefone'] ?? '',
            'email' => $data['email'] ?? '',
            'data_abertura' => $data['abertura'] ?? '',
            'natureza_juridica' => $data['natureza_juridica'] ?? '',
            'cnae' => $data['atividade_principal'][0]['code'] ?? '',
            'porte' => $data['porte'] ?? ''
        ];
    }

    private function logAudit($userId, $action, $description) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO logs_auditoria 
                (user_id, action_type, description, ip_address, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $userId,
                $action,
                $description,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log("logAudit error: " . $e->getMessage());
        }
    }
}
