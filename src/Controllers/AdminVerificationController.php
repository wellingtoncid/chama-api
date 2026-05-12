<?php

namespace App\Controllers;

use App\Core\Response;
use App\Services\CreditService;
use App\Services\NotificationService;
use Exception;
use PDO;

class AdminVerificationController {
    private PDO $db;
    private NotificationService $notif;
    private CreditService $creditService;
    private ?array $loggedUser;

    public function __construct(PDO $db, ?array $loggedUser = null) {
        $this->db = $db;
        $this->notif = new NotificationService($db);
        $this->creditService = new CreditService($db);
        $this->loggedUser = $loggedUser;
    }

    private function authorize(?array $loggedUser = null, $minRole = 'MANAGER'): void {
        $user = $loggedUser ?? $this->loggedUser;
        if (!$user) throw new Exception("Sessão expirada ou usuário não identificado.", 401);
        $userRole = strtolower($user['role'] ?? '');
        $roleHierarchy = ['admin' => 5, 'manager' => 4, 'analyst' => 3, 'assistant' => 2];
        $userLevel = $roleHierarchy[$userRole] ?? 0;
        if (is_string($minRole)) {
            $requiredLevel = $roleHierarchy[strtolower($minRole)] ?? 0;
            if ($userLevel < $requiredLevel) throw new Exception("Acesso negado. Permissão insuficiente.", 403);
        } elseif (is_array($minRole)) {
            $allowed = array_map('strtolower', $minRole);
            if (!in_array($userRole, $allowed)) throw new Exception("Acesso negado. Permissão insuficiente.", 403);
        }
    }

    public function listPendingDocuments($data, $loggedUser) {
        $this->authorize($loggedUser);
        $repo = new \App\Repositories\AdminRepository($this->db);
        return Response::json(["success" => true, "data" => $repo->getPendingDocuments()]);
    }

    public function reviewDocument($data, $loggedUser) {
        $this->authorize($loggedUser);
        $repo = new \App\Repositories\AdminRepository($this->db);
        $docId = $data['doc_id'] ?? null;
        $status = $data['status'] ?? null;
        $reason = $data['reason'] ?? '';
        if (!$docId || !in_array($status, ['APPROVED', 'REJECTED'])) {
            return Response::json(["success" => false, "message" => "Dados insuficientes"]);
        }
        $doc = $repo->getDocumentById($docId);
        if (!$doc) return Response::json(["success" => false, "message" => "Documento não encontrado"]);
        if ($repo->updateDocumentStatus($docId, $status, $reason)) {
            $repo->saveLog($loggedUser['id'], $loggedUser['name'], 'DOC_REVIEW', "Doc #{$docId} avaliado como {$status}", $doc['entity_id'], 'USER');
            $msg = ($status === 'APPROVED') ? "Seu documento ({$doc['document_type']}) foi aprovado!" : "Seu documento ({$doc['document_type']}) foi recusado. Motivo: {$reason}";
            $this->notif->notify($doc['entity_id'], "Verificação de Documentos", $msg);
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false]);
    }

    public function listDriverVerifications($data, $loggedUser) {
        $this->authorize($loggedUser);
        $status = $data['status'] ?? 'awaiting_review';
        $limit = $data['limit'] ?? 50;
        try {
            $stmt = $this->db->prepare("
                SELECT t.id as transaction_id, t.user_id, t.status, t.amount, t.created_at as requested_at, t.gateway_id,
                       u.name as user_name, u.email as user_email, u.whatsapp as user_whatsapp, u.role as user_role
                FROM transactions t
                JOIN users u ON t.user_id = u.id
                WHERE t.module_key = 'driver' AND t.feature_key = 'document_verification'
                    AND t.status = :status
                    AND t.id = (SELECT MAX(t2.id) FROM transactions t2 WHERE t2.user_id = t.user_id AND t2.module_key = 'driver' AND t2.feature_key = 'document_verification' AND t2.status = :status2)
                ORDER BY t.created_at DESC LIMIT :limit
            ");
            $stmt->bindValue(':status', $status, PDO::PARAM_STR);
            $stmt->bindValue(':status2', $status, PDO::PARAM_STR);
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            $verifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($verifications as &$v) {
                $stmt = $this->db->prepare("SELECT document_type, file_path, status, rejection_reason, created_at FROM user_documents WHERE entity_id = ? AND entity_type = 'user' AND status != 'replaced' ORDER BY id DESC");
                $stmt->execute([$v['user_id']]);
                $allDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $seenTypes = [];
                $v['documents'] = [];
                foreach ($allDocs as $doc) {
                    if (!in_array($doc['document_type'], $seenTypes)) {
                        $v['documents'][] = $doc;
                        $seenTypes[] = $doc['document_type'];
                    }
                }
            }
            return Response::json(["success" => true, "count" => count($verifications), "data" => $verifications]);
        } catch (\Throwable $e) {
            error_log("ERRO listDriverVerifications: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao buscar verificações"], 500);
        }
    }

    public function approveDriverVerification($data, $loggedUser) {
        $this->authorize($loggedUser);
        $transactionId = $data['transaction_id'] ?? null;
        $notes = $data['notes'] ?? null;
        if (!$transactionId) return Response::json(["success" => false, "message" => "ID da transação não informado"], 400);
        try {
            $stmt = $this->db->prepare("SELECT * FROM transactions WHERE id = ? AND module_key = 'driver' AND feature_key = 'document_verification'");
            $stmt->execute([$transactionId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$transaction) return Response::json(["success" => false, "message" => "Transação não encontrada"], 404);
            if ($transaction['status'] === 'approved') return Response::json(["success" => false, "message" => "Verificação já foi aprovada"], 400);
            $stmt = $this->db->prepare("UPDATE transactions SET status = 'approved', reviewed_at = NOW(), reviewed_by = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$loggedUser['id'], $transactionId]);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
            $stmt = $this->db->prepare("UPDATE users SET is_verified = 1, verified_at = NOW(), verified_until = ?, verified_by = ? WHERE id = ?");
            $stmt->execute([$expiresAt, $loggedUser['id'], $transaction['user_id']]);
            $stmt = $this->db->prepare("UPDATE user_documents SET status = 'approved', verified_at = NOW(), verified_by = ? WHERE entity_id = ? AND entity_type = 'user' AND status = 'pending'");
            $stmt->execute([$loggedUser['id'], $transaction['user_id']]);
            if ($this->notif) {
                $this->notif->createNotification($transaction['user_id'], 'Verificação Aprovada', 'Sua verificação de documentos foi aprovada! Seu perfil agora exibe o badge de usuário verificado.', 'verification', $transactionId);
            }
            error_log("DRIVER VERIFICATION: Aprovada para usuário {$transaction['user_id']} por {$loggedUser['id']}");
            return Response::json(["success" => true, "message" => "Verificação aprovada com sucesso!", "expires_at" => $expiresAt]);
        } catch (\Throwable $e) {
            error_log("ERRO approveDriverVerification: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao aprovar verificação"], 500);
        }
    }

    public function rejectDriverVerification($data, $loggedUser) {
        $this->authorize($loggedUser);
        $transactionId = $data['transaction_id'] ?? null;
        $reason = $data['reason'] ?? '';
        $reasonTemplate = $data['reason_template'] ?? null;
        if (!$transactionId) return Response::json(["success" => false, "message" => "ID da transação não informado"], 400);
        if (empty($reason) && empty($reasonTemplate)) return Response::json(["success" => false, "message" => "Motivo da rejeição é obrigatório"], 400);
        $fullReason = $reasonTemplate;
        if (!empty($reasonTemplate) && !empty($reason)) $fullReason = $reasonTemplate . ': ' . $reason;
        elseif (!empty($reason)) $fullReason = $reason;
        try {
            $stmt = $this->db->prepare("SELECT * FROM transactions WHERE id = ? AND module_key = 'driver' AND feature_key = 'document_verification'");
            $stmt->execute([$transactionId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$transaction) return Response::json(["success" => false, "message" => "Transação não encontrada"], 404);
            if ($transaction['status'] !== 'awaiting_review') return Response::json(["success" => false, "message" => "Esta transação não pode ser rejeitada (status atual: {$transaction['status']})"], 400);
            $amount = (float)$transaction['amount'];
            if ($amount > 0) $this->creditService->refund($transaction['user_id'], $amount, "Verificação rejeitada: {$fullReason}");
            $stmt = $this->db->prepare("UPDATE transactions SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ?, rejection_reason = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$loggedUser['id'], $fullReason, $transactionId]);
            $stmt = $this->db->prepare("UPDATE user_documents SET status = 'rejected', rejection_reason = ? WHERE entity_id = ? AND entity_type = 'user' AND status = 'pending'");
            $stmt->execute([$fullReason, $transaction['user_id']]);
            if ($this->notif) {
                $this->notif->createNotification($transaction['user_id'], 'Verificação Recusada', "Sua verificação de documentos foi recusada. Motivo: {$fullReason}. O valor de R$ " . number_format($amount, 2, ',', '.') . " foi devolvido para sua carteira.", 'verification', $transactionId);
            }
            error_log("DRIVER VERIFICATION: Rejeitada para usuário {$transaction['user_id']} por {$loggedUser['id']}. Motivo: {$fullReason}. Valor estornado: {$amount}");
            return Response::json(["success" => true, "message" => "Verificação recusada. Valor estornado: R$ " . number_format($amount, 2, ',', '.'), "amount_refunded" => $amount]);
        } catch (\Throwable $e) {
            error_log("ERRO rejectDriverVerification: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao recusar verificação: " . $e->getMessage()], 500);
        }
    }

    public function getAllVerifications($data, $loggedUser) {
        $this->authorize($loggedUser, ['ADMIN', 'MANAGER', 'SUPPORT']);
        try {
            $type = $data['type'] ?? 'all';
            $status = $data['status'] ?? 'pending';
            $page = intval($data['page'] ?? 1);
            $limit = intval($data['limit'] ?? 20);
            $offset = ($page - 1) * $limit;
            $results = [];
            if ($type === 'all' || $type === 'driver') {
                $driverSql = "
                    SELECT DISTINCT u.id as user_id, u.name as user_name, u.email as user_email, u.role as user_type,
                        u.is_verified, u.whatsapp as user_whatsapp, u.document_verified_at,
                        COALESCE(t.status, CASE WHEN EXISTS (SELECT 1 FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER' AND status = 'rejected') THEN 'rejected' WHEN EXISTS (SELECT 1 FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER' AND status = 'pending') THEN 'pending' WHEN EXISTS (SELECT 1 FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER') THEN 'pending' ELSE NULL END, 'pending') as transaction_status,
                        CASE WHEN u.is_verified = 1 THEN 'approved' WHEN COALESCE(t.status, 'pending') = 'rejected' OR EXISTS (SELECT 1 FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER' AND status = 'rejected') THEN 'rejected' WHEN u.is_verified = 0 AND EXISTS (SELECT 1 FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER') THEN 'pending' WHEN t.status IN ('pending', 'awaiting_review') THEN 'pending' ELSE 'pending' END as status,
                        u.document_verified_at as verified_at,
                        COALESCE(t.created_at, (SELECT MIN(created_at) FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER')) as requested_at,
                        t.amount, COALESCE(t.id, (SELECT MAX(id) FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER')) as verification_id,
                        (SELECT rejection_reason FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER' AND status = 'rejected' ORDER BY id DESC LIMIT 1) as rejection_reason
                    FROM users u
                    LEFT JOIN transactions t ON t.user_id = u.id AND t.module_key = 'driver' AND t.feature_key = 'document_verification' AND t.id = (SELECT MAX(t2.id) FROM transactions t2 WHERE t2.user_id = u.id AND t2.module_key = 'driver' AND t2.feature_key = 'document_verification')
                    WHERE u.role = 'driver' AND u.deleted_at IS NULL AND (EXISTS (SELECT 1 FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER') OR EXISTS (SELECT 1 FROM transactions WHERE user_id = u.id AND module_key = 'driver' AND feature_key = 'document_verification'))
                ";
                if ($status === 'pending') $driverSql .= " AND u.is_verified = 0 AND (t.status IN ('pending', 'awaiting_review') OR EXISTS (SELECT 1 FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER' AND status = 'pending'))";
                elseif ($status === 'approved') $driverSql .= " AND (t.status = 'approved' OR u.is_verified = 1)";
                elseif ($status === 'rejected') $driverSql .= " AND (t.status = 'rejected' OR EXISTS (SELECT 1 FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER' AND status = 'rejected'))";
                $driverSql .= " ORDER BY requested_at DESC";
                $stmt = $this->db->prepare($driverSql);
                $stmt->execute();
                $driverVerifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($driverVerifications as $v) {
                    $v['type'] = 'driver';
                    $v['description'] = 'Documentos do Motorista';
                    $v['documents'] = $this->getDriverDocuments($v['user_id']);
                    $results[] = $v;
                }
            }
            if ($type === 'all' || $type === 'company') {
                $sql = "SELECT 'company' as type, u.id as user_id, u.name as user_name, u.email as user_email, u.role as user_type, v.id as verification_id, v.cnpj, v.razao_social, v.nome_fantasia, v.situacao, v.status, v.created_at, v.verified_at, v.reviewed_at, v.rejection_reason, ru.name as reviewed_by_name, tr.amount as transaction_amount, CONCAT('CNPJ: ', v.cnpj) as description FROM verified_cnpj v JOIN users u ON v.user_id = u.id LEFT JOIN transactions tr ON tr.user_id = v.user_id AND tr.module_key = 'company_pro' AND tr.feature_key = 'identity_verification' AND tr.status = 'approved' LEFT JOIN users ru ON v.reviewed_by = ru.id WHERE 1=1";
                $params = [];
                if ($status !== 'all') { $sql .= " AND v.status = ?"; $params[] = $status; }
                $sql .= " ORDER BY v.created_at DESC";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            usort($results, function($a, $b) { return strtotime($b['created_at'] ?? $b['requested_at']) - strtotime($a['created_at'] ?? $a['requested_at']); });
            $total = count($results);
            $paginatedResults = array_slice($results, $offset, $limit);
            return Response::json(["success" => true, "data" => ["verifications" => $paginatedResults, "total" => $total, "page" => $page, "limit" => $limit, "total_pages" => ceil($total / $limit)]]);
        } catch (\Throwable $e) {
            error_log("ERRO getAllVerifications: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao buscar verificações: " . $e->getMessage()], 500);
        }
    }

    public function approveVerification($data, $loggedUser) {
        $this->authorize($loggedUser, ['ADMIN', 'MANAGER']);
        try {
            $id = intval($data['id'] ?? 0);
            $type = $data['type'] ?? '';
            if (!$id || !in_array($type, ['driver', 'company'])) return Response::json(["success" => false, "message" => "ID e tipo são obrigatórios"], 400);
            return $type === 'driver' ? $this->approveDriverByUserId($id, $loggedUser) : $this->approveCompanyById($id, $loggedUser);
        } catch (\Throwable $e) {
            error_log("ERRO approveVerification: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao aprovar verificação: " . $e->getMessage()], 500);
        }
    }

    private function approveDriverByUserId($id, $loggedUser) {
        $stmt = $this->db->prepare("SELECT user_id FROM transactions WHERE id = ? AND module_key = 'driver' AND feature_key = 'document_verification'");
        $stmt->execute([$id]);
        $trans = $stmt->fetch();
        $userId = $trans ? $trans['user_id'] : $id;
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? AND role = 'driver'");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return Response::json(["success" => false, "message" => "Motorista não encontrado"], 404);
        if ($trans) {
            $stmt = $this->db->prepare("UPDATE transactions SET status = 'approved', approved_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
        } else {
            $stmt = $this->db->prepare("UPDATE transactions SET status = 'approved', approved_at = NOW() WHERE user_id = ? AND module_key = 'driver' AND feature_key = 'document_verification' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$userId]);
        }
        $stmt = $this->db->prepare("UPDATE user_documents SET status = 'approved', verified_by = ?, verified_at = NOW() WHERE entity_id = ? AND entity_type = 'USER'");
        $stmt->execute([$loggedUser['id'], $userId]);
        $stmt = $this->db->prepare("UPDATE users SET is_verified = 1, document_verified_at = NOW() WHERE id = ?");
        $stmt->execute([$userId]);
        $stmt = $this->db->prepare("INSERT INTO user_modules (user_id, module_key, is_active, activated_at) VALUES (?, 'identity_verification', 1, NOW()) ON DUPLICATE KEY UPDATE is_active = 1, activated_at = NOW()");
        $stmt->execute([$userId]);
        if ($this->notif) {
            $this->notif->createNotification($userId, 'Verificação Aprovada!', 'Parabéns! Sua verificação de documentos foi aprovada. Seu perfil agora exibe o badge de identidade confirmada.', 'verification', $userId);
        }
        error_log("VERIFICATION APPROVED: Driver {$userId} approved by {$loggedUser['id']}");
        return Response::json(["success" => true, "message" => "Verificação do motorista aprovada com sucesso!"]);
    }

    private function approveCompanyById($cnpjId, $loggedUser) {
        $stmt = $this->db->prepare("SELECT * FROM verified_cnpj WHERE id = ?");
        $stmt->execute([$cnpjId]);
        $cnpj = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cnpj) return Response::json(["success" => false, "message" => "CNPJ não encontrado"], 404);
        $stmt = $this->db->prepare("UPDATE verified_cnpj SET status = 'verified', reviewed_by = ?, reviewed_at = NOW(), verified_at = NOW() WHERE id = ?");
        $stmt->execute([$loggedUser['id'], $cnpjId]);
        $stmt = $this->db->prepare("UPDATE users SET is_verified = 1 WHERE id = ?");
        $stmt->execute([$cnpj['user_id']]);
        $stmt = $this->db->prepare("INSERT INTO user_modules (user_id, module_key, is_active, activated_at) VALUES (?, 'identity_verification', 1, NOW()) ON DUPLICATE KEY UPDATE is_active = 1, activated_at = NOW()");
        $stmt->execute([$cnpj['user_id']]);
        if ($this->notif) {
            $this->notif->createNotification($cnpj['user_id'], 'Verificação Aprovada!', 'Parabéns! Sua verificação de empresa foi aprovada. Seu perfil agora exibe o badge de identidade confirmada.', 'verification', $cnpjId);
        }
        error_log("VERIFICATION APPROVED: Company {$cnpj['user_id']} (CNPJ {$cnpj['cnpj']}) approved by {$loggedUser['id']}");
        return Response::json(["success" => true, "message" => "Verificação da empresa aprovada com sucesso!"]);
    }

    public function rejectVerification($data, $loggedUser) {
        $this->authorize($loggedUser, ['ADMIN', 'MANAGER']);
        try {
            $id = intval($data['id'] ?? 0);
            $type = $data['type'] ?? '';
            $reason = trim($data['reason'] ?? '');
            if (!$id || !in_array($type, ['driver', 'company'])) return Response::json(["success" => false, "message" => "ID e tipo são obrigatórios"], 400);
            if (empty($reason)) return Response::json(["success" => false, "message" => "Motivo da rejeição é obrigatório"], 400);
            return $type === 'driver' ? $this->rejectDriverByUserId($id, $loggedUser, $reason) : $this->rejectCompanyById($id, $loggedUser, $reason);
        } catch (\Throwable $e) {
            error_log("ERRO rejectVerification: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao rejeitar verificação: " . $e->getMessage()], 500);
        }
    }

    private function rejectDriverByUserId($id, $loggedUser, $reason) {
        $stmt = $this->db->prepare("SELECT user_id FROM transactions WHERE id = ? AND module_key = 'driver' AND feature_key = 'document_verification'");
        $stmt->execute([$id]);
        $trans = $stmt->fetch();
        $userId = $trans ? $trans['user_id'] : $id;
        $stmt = $this->db->prepare("SELECT u.*, tr.amount, tr.id as trans_id FROM users u LEFT JOIN transactions tr ON tr.user_id = u.id AND tr.module_key = 'driver' AND tr.feature_key = 'document_verification' WHERE u.id = ? AND u.role = 'driver'");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return Response::json(["success" => false, "message" => "Motorista não encontrado"], 404);
        $stmt = $this->db->prepare("UPDATE user_documents SET status = 'rejected', rejection_reason = ? WHERE entity_id = ? AND entity_type = 'USER' AND status != 'approved'");
        $stmt->execute([$reason, $userId]);
        if (!empty($user['trans_id'])) {
            $stmt = $this->db->prepare("UPDATE transactions SET status = 'rejected' WHERE id = ?");
            $stmt->execute([$user['trans_id']]);
        } else {
            $stmt = $this->db->prepare("UPDATE transactions SET status = 'rejected' WHERE user_id = ? AND module_key = 'driver' AND feature_key = 'document_verification' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$userId]);
        }
        $amount = floatval($user['amount'] ?? 0);
        if ($amount > 0) $this->creditService->refund($userId, $amount, "Verificação rejeitada: {$reason}");
        if ($this->notif) {
            $msg = $amount > 0 ? "Sua verificação de documentos foi recusada. Motivo: {$reason}. O valor de R$ " . number_format($amount, 2, ',', '.') . " foi devolvido para sua carteira." : "Sua verificação de documentos foi recusada. Motivo: {$reason}.";
            $this->notif->createNotification($userId, 'Verificação Recusada', $msg, 'verification', $userId);
        }
        error_log("VERIFICATION REJECTED: Driver {$userId} rejected by {$loggedUser['id']}. Reason: {$reason}. Refunded: {$amount}");
        return Response::json(["success" => true, "message" => "Verificação do motorista recusada." . ($amount > 0 ? " Valor estornado: R$ " . number_format($amount, 2, ',', '.') : ""), "amount_refunded" => $amount]);
    }

    private function rejectCompanyById($cnpjId, $loggedUser, $reason) {
        $stmt = $this->db->prepare("SELECT vc.*, tr.amount FROM verified_cnpj vc LEFT JOIN transactions tr ON tr.user_id = vc.user_id AND tr.module_key = 'company_pro' AND tr.feature_key = 'identity_verification' AND tr.status = 'approved' WHERE vc.id = ?");
        $stmt->execute([$cnpjId]);
        $cnpj = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cnpj) return Response::json(["success" => false, "message" => "CNPJ não encontrado"], 404);
        $stmt = $this->db->prepare("UPDATE verified_cnpj SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
        $stmt->execute([$reason, $loggedUser['id'], $cnpjId]);
        $amount = floatval($cnpj['amount'] ?? 0);
        if ($amount > 0) $this->creditService->refund($cnpj['user_id'], $amount, "Verificação CNPJ rejeitada: {$reason}");
        if ($this->notif) {
            $msg = $amount > 0 ? "Sua verificação de empresa foi recusada. Motivo: {$reason}. O valor de R$ " . number_format($amount, 2, ',', '.') . " foi devolvido para sua carteira." : "Sua verificação de empresa foi recusada. Motivo: {$reason}.";
            $this->notif->createNotification($cnpj['user_id'], 'Verificação Recusada', $msg, 'verification', $cnpjId);
        }
        error_log("VERIFICATION REJECTED: Company {$cnpj['user_id']} (CNPJ {$cnpj['cnpj']}) rejected by {$loggedUser['id']}. Reason: {$reason}. Refunded: {$amount}");
        return Response::json(["success" => true, "message" => "Verificação da empresa recusada." . ($amount > 0 ? " Valor estornado: R$ " . number_format($amount, 2, ',', '.') : ""), "amount_refunded" => $amount]);
    }

    private function getDriverDocuments($userId) {
        $stmt = $this->db->prepare("SELECT document_type, file_path, status, rejection_reason FROM user_documents WHERE entity_id = ? AND entity_type = 'USER' ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
