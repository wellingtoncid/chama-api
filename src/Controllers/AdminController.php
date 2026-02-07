<?php

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\AdminRepository;
use Exception;

class AdminController {
    private $repo;
    private $db;
    private $notif;
    private $loggedUser;

    public function __construct($db, $loggedUser = null) {
        $this->db = $db;
        $this->repo = new AdminRepository($db);
        $this->loggedUser = $loggedUser; 

        // Definição do caminho antes do uso
        $notifPath = __DIR__ . '/NotificationController.php';
        
        if (file_exists($notifPath)) {
            require_once $notifPath;
            if (class_exists('NotificationController')) {
                $this->notif = new \NotificationController($db);
            }
        }
    }

    /**
     * Middleware de Segurança
     */
    private function authorize($loggedUser = null, $minRole = 'MANAGER') {
        // Fallback para o usuário da classe caso o parâmetro venha nulo
        $user = $loggedUser ?? $this->loggedUser;
        
        if (!$user) {
            Response::json(["success" => false, "message" => "Sessão expirada"], 401);
            exit;
        }

        $role = strtoupper($user['role'] ?? '');
        
        if (($minRole === 'ADMIN' && $role !== 'ADMIN') || 
            ($minRole === 'MANAGER' && !in_array($role, ['ADMIN', 'MANAGER']))) {
            Response::json(["success" => false, "message" => "Acesso Negado"], 403);
            exit; 
        }
    }

    public function getDashboardData($data = [], $loggedUser = null) {
        $this->authorize($loggedUser);
        
        try {
            $rawStats = $this->repo->getDashboardStats();

            $formattedData = [
                "stats" => [
                    "total_pending"      => (int)($rawStats['counters']['pending_freights'] ?? 0),
                    "revenue"            => (string)($rawStats['revenue']['confirmed'] ?? "0.00"),
                    "pending_revenue"    => (string)($rawStats['revenue']['pending'] ?? "0.00"),
                    "total_users"        => (int)($rawStats['counters']['total_users'] ?? 0),
                    "drivers"            => (int)($rawStats['counters']['drivers'] ?? 0),
                    "companies"          => (int)($rawStats['counters']['companies'] ?? 0),
                    "advertisers"        => (int)($rawStats['counters']['advertisers'] ?? 0), 
                    "active_freights"    => (int)($rawStats['counters']['active_freights'] ?? 0),
                    "featured_freights"  => 0,
                    "total_interactions" => (int)($rawStats['counters']['total_clicks'] ?? 0),
                    "conversion_rate"    => (isset($rawStats['counters']['total_views']) && $rawStats['counters']['total_views'] > 0) ? 
                        round(($rawStats['counters']['total_clicks'] / $rawStats['counters']['total_views']) * 100, 1) : 0
                ],
                "pending_approvals" => $this->repo->getPendingFreights() ?: [],
                "recent_activities" => $this->repo->getRecentActivities() ?: []
            ];

            return Response::json([
                "success" => true, 
                "data" => $formattedData
            ]);
        } catch (Exception $e) {
            return Response::json(["success" => false, "message" => "Erro ao processar dashboard"], 500);
        }
    }

    public function getRevenueReport($data, $loggedUser) {
        $this->authorize($loggedUser, 'ADMIN');
        return Response::json(["success" => true, "data" => $this->repo->getDetailedRevenue()]);
    }

    public function listLogs($data, $loggedUser) {
        $this->authorize($loggedUser, 'ADMIN');
        $limit = $data['limit'] ?? 50;
        return Response::json(["success" => true, "data" => $this->repo->getAuditLogs((int)$limit)]);
    }

    // --- GESTÃO DE USUÁRIOS ---

    public function listUsers($data, $loggedUser) {
        $this->authorize($loggedUser);
        $users = $this->repo->listUsersByRole($data['role'] ?? '%', $data['search'] ?? '%');
        return Response::json(["success" => true, "data" => $users]);
    }

    public function manageUsers($data, $loggedUser) {
        $this->authorize($loggedUser);
        if ($this->repo->updateUserDetails($data)) {
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'UPDATE_USER', "Editou usuário #{$data['id']}", $data['id'], 'USER');
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false]);
    }

    public function verifyUser($data, $loggedUser) {
        $this->authorize($loggedUser);
        $id = $data['id'] ?? null;
        $status = $data['status'] ?? 1;
        
        if ($this->repo->setUserVerification($id, $status)) {
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'VERIFY_USER', "Verificação ID $id: $status", $id, 'USER');
            if ($status == 1) {
                $this->notif->notify($id, "Perfil Verificado!", "Sua conta recebeu o selo de confiança.");
            }
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false]);
    }

    public function deleteUser($data, $loggedUser) {
        $this->authorize($loggedUser, 'ADMIN');
        $id = $data['id'] ?? null;
        $permanent = $data['permanent'] ?? false;

        $success = $permanent ? $this->repo->deleteUserPermanently($id) : $this->repo->softDeleteUser($id);
        if ($success) {
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'DELETE_USER', "Excluiu usuário #$id", $id, 'USER');
        }
        return Response::json(["success" => $success]);
    }

    // --- GESTÃO DE FRETES ---

    public function listAllFreights($data, $loggedUser) {
        $this->authorize($loggedUser); 
        try {
            $freights = $this->repo->getAllFreightsForAdmin();
            return Response::json($freights); // Retorna a array direta como o seu React espera
        } catch (Exception $e) {
            return Response::json([]);
        }
    }

    public function updateFreightStatus($data, $loggedUser) {
        $this->authorize($loggedUser);
        $id = $data['id'] ?? null;
        $status = $data['status'] ?? 'OPEN'; // 'OPEN' para aprovar, 'CLOSED' para rejeitar
        $approveFeatured = $data['approve_featured'] ?? false;

        $freight = $this->repo->getFreightById($id);
        if (!$freight) return Response::json(["success" => false, "message" => "Frete não encontrado"]);

        if ($this->repo->updateFreightStatus($id, $status, $approveFeatured)) {
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'FREIGHT_STATUS', "Frete #$id -> $status", $id, 'FREIGHT');
            if ($status === 'OPEN') {
                $this->notif->notify($freight['user_id'], "Frete Online!", "Seu anúncio foi aprovado.");
                $this->triggerMatches($freight); 
            }
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false]);
    }

    private function triggerMatches($freight) {
        $drivers = $this->repo->findCompatibleDrivers($freight['vehicle_type'], $freight['body_type'], $freight['origin_state']);
        foreach ($drivers as $driver) {
            $this->notif->notify($driver['user_id'], "Carga compatível!", "Nova carga de {$freight['product']} disponível.");
        }
    }

    // --- LEADS, ADS E CONFIGURAÇÕES ---

    public function getPortalRequests($data, $loggedUser) {
        $this->authorize($loggedUser);
        return Response::json(["success" => true, "data" => $this->repo->getPortalRequests($data)]);
    }

    public function updateLeadInternal($data, $loggedUser) {
        $this->authorize($loggedUser);
        if ($this->repo->updateLeadInternal($data['id'], $data['admin_notes'] ?? '', $data['status'] ?? 'pending')) {
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'UPDATE_LEAD', "Nota no lead #{$data['id']}", $data['id'], 'LEAD');
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false]);
    }

    public function manageAds($data, $loggedUser) {
        $this->authorize($loggedUser);
        $id = $data['id'] ?? null;
        if (($data['action'] ?? '') === 'delete') {
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'DELETE_AD', "Removeu anúncio #$id", $id, 'AD');
            return Response::json(["success" => $this->repo->softDeleteAd($id)]);
        }
        return Response::json(["success" => $this->repo->toggleAdStatus($id)]);
    }

    public function updateSettings($data, $loggedUser) {
        $this->authorize($loggedUser, 'ADMIN');
        if ($this->repo->saveSettings($data)) {
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'SETTING_UPDATE', "Alterou configurações do sistema", 0, 'SYSTEM');
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false]);
    }

    public function managePlans($data, $loggedUser) {
        $this->authorize($loggedUser, 'ADMIN');
        return Response::json(["success" => true, "data" => $this->repo->managePlans($data)]);
    }

    public function manualAddCredits($data, $loggedUser) {
        $this->authorize($loggedUser);
        $userId = $data['user_id'] ?? null;
        $amount = (int)($data['amount'] ?? 0);
        $reason = $data['reason'] ?? 'Adição manual via painel';

        if (!$userId || $amount <= 0) return Response::json(["success" => false, "message" => "Dados inválidos"]);

        try {
            $this->db->beginTransaction();
            $this->db->prepare("UPDATE users SET ad_credits = ad_credits + :amount WHERE id = :id")->execute([':amount' => $amount, ':id' => $userId]);
            
            $this->db->prepare("INSERT INTO credit_transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'recharge', ?, NOW())")
                     ->execute([$userId, $amount, $reason . " (Por: {$loggedUser['name']})"]);

            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'MANUAL_CREDIT', "Adicionou $amount créditos ao usuário #$userId", $userId, 'USER');
            $this->db->commit();
            
            $this->notif->notify($userId, "Créditos Adicionados!", "Você recebeu $amount créditos.");
            return Response::json(["success" => true]);
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return Response::json(["success" => false]);
        }
    }

    public function manageFreights($data, $loggedUser) {
        $this->authorize($loggedUser);
        $id = $data['id'] ?? null;
        $action = $data['action'] ?? '';
        $success = false;

        if (!$id) return Response::json(["success" => false, "message" => "ID ausente"]);

        switch ($action) {
            case 'toggle-featured':
                $featured = (int)($data['featured'] ?? 0);
                $success = $this->repo->toggleFeatured($id, $featured);
                $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'UPDATE', "Alterou destaque do frete #$id", $id, 'FREIGHT');
                break;

            case 'delete':
                // Na sua tabela existe is_deleted, use preferencialmente ela
                $success = $this->repo->updateFreightStatus($id, 'DELETED'); 
                // Se tiver o método, use: $this->repo->softDeleteFreight($id);
                $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'DELETE', "Removeu frete #$id", $id, 'FREIGHT');
                break;

            case 'approve':
                $success = $this->repo->updateFreightStatus($id, 'OPEN');
                break;
                
            default:
                return Response::json(["success" => false, "message" => "Ação inválida"]);
        }

        return Response::json(["success" => $success]);
    }

    /**
     * Lista todos os chamados para o Painel Administrativo
     */
    public function listTickets($data, $loggedUser) {
        $this->authorize($loggedUser); // Manager e Admin podem ver
        $status = $data['status'] ?? '%';
        $tickets = $this->repo->getTickets($status);
        return Response::json(["success" => true, "data" => $tickets]);
    }

    /**
     * Resposta do suporte para o usuário
     */
    public function replyTicket($data, $loggedUser) {
        $this->authorize($loggedUser);
        $ticketId = $data['ticket_id'];
        $message = $data['message'];

        if ($this->repo->addTicketMessage($ticketId, $loggedUser['id'], $message, true)) {
            // Notifica o usuário que o suporte respondeu
            $ticket = $this->repo->getTicketById($ticketId);
            $this->notif->notify($ticket['user_id'], "Suporte respondeu!", "Verifique seu chamado: " . $ticket['subject']);
            
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false]);
    }

    public function addUserNote($data, $loggedUser) {
        $this->authorize($loggedUser);
        $targetUserId = $data['user_id'];
        $note = $data['note'];
        
        // Salva na tabela de notas internas
        $success = $this->repo->saveInternalNote($targetUserId, $loggedUser['id'], $note);
        return Response::json(["success" => $success]);
    }

    // --- GESTÃO DE DOCUMENTOS (KYC) ---

    /**
     * Lista documentos que aguardam revisão
     */
    public function listPendingDocuments($data, $loggedUser) {
        $this->authorize($loggedUser);
        $docs = $this->repo->getPendingDocuments();
        return Response::json(["success" => true, "data" => $docs]);
    }

    /**
     * Aprova ou Rejeita um documento específico
     */
    public function reviewDocument($data, $loggedUser) {
        $this->authorize($loggedUser);
        
        $docId = $data['doc_id'] ?? null;
        $status = $data['status'] ?? null; // 'APPROVED' ou 'REJECTED'
        $reason = $data['reason'] ?? '';   // Motivo em caso de rejeição

        if (!$docId || !in_array($status, ['APPROVED', 'REJECTED'])) {
            return Response::json(["success" => false, "message" => "Dados insuficientes"]);
        }

        $doc = $this->repo->getDocumentById($docId);
        if (!$doc) return Response::json(["success" => false, "message" => "Documento não encontrado"]);

        if ($this->repo->updateDocumentStatus($docId, $status, $reason)) {
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'DOC_REVIEW', "Doc #$docId avaliado como $status", $doc['entity_id'], 'USER');
            
            // Notifica o usuário sobre o resultado
            $msg = ($status === 'APPROVED') 
                ? "Seu documento ({$doc['document_type']}) foi aprovado!" 
                : "Seu documento ({$doc['document_type']}) foi recusado. Motivo: $reason";
            
            $this->notif->notify($doc['entity_id'], "Verificação de Documentos", $msg);
            
            return Response::json(["success" => true]);
        }
        
        return Response::json(["success" => false]);
    }
}