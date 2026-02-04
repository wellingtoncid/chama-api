<?php

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\AdminRepository;
use Exception;

class AdminController {
    private $repo;
    private $db;
    private $loggedUser;
    private $notif;

    public function __construct($db, $loggedUser = null) {
        $this->db = $db;
        $this->repo = new AdminRepository($db);
        
        // Carregamento dinâmico do NotificationController como um serviço
        require_once __DIR__ . '/NotificationController.php';
        $this->notif = new \NotificationController($db);
        
        $this->loggedUser = $loggedUser;
        $this->checkAuth();
    }

    /**
     * Middleware de Segurança Interno
     */
    private function checkAuth() {
        if (!$this->loggedUser || strtoupper($this->loggedUser['role'] ?? '') !== 'ADMIN') {
            Response::json(["success" => false, "message" => "Acesso Negado: Privilégios insuficientes"], 403);
            exit;
        }
    }

    /**
     * Roteador de Entrada (Pattern Gateway)
     */
    public function handle(string $endpoint, array $data = []) {
        try {
            return match ($endpoint) {
                'admin-dashboard-data' => $this->getDashboardData(),
                'admin-revenue-report' => $this->getRevenueReport(),
                'admin-audit-logs'     => $this->listLogs($data['limit'] ?? 50),
                'admin-list-users'     => $this->listUsers($data),
                'admin-update-user'    => $this->manageUsers($data),
                'admin-verify-user'    => $this->verifyUser($data),
                'admin-delete-user'    => $this->deleteUser($data['id'] ?? null, $data['permanent'] ?? false),
                'admin-list-freights'  => $this->listAllFreights(),
                'approve-freight'      => $this->updateFreightStatus($data['id'] ?? null, 'OPEN', true),
                'reject-freight'       => $this->updateFreightStatus($data['id'] ?? null, 'CLOSED', false),
                'update-lead-internal' => $this->updateLeadInternal($data),
                'manage-ads'           => $this->manageAds($data),
                'update-settings'      => $this->updateSettings($data),
                'manage-plans'         => $this->managePlans($data),
                default                => Response::json(["success" => false, "message" => "Endpoint não mapeado"], 404)
            };
        } catch (Exception $e) {
            error_log("Erro em AdminController [$endpoint]: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro interno no servidor"], 500);
        }
    }

    // =========================================================================
    // SERVIÇOS DE DADOS
    // =========================================================================

    private function getDashboardData() {
        return Response::json(["success" => true, "data" => $this->repo->getDashboardStats()]);
    }

    private function getRevenueReport() {
        return Response::json(["success" => true, "data" => $this->repo->getDetailedRevenue()]);
    }

    private function listLogs($limit) {
        return Response::json(["success" => true, "data" => $this->repo->getAuditLogs((int)$limit)]);
    }

    // =========================================================================
    // GESTÃO DE USUÁRIOS
    // =========================================================================

    private function listUsers($data) {
        $users = $this->repo->listUsersByRole($data['role'] ?? '%', $data['search'] ?? '%');
        return Response::json(["success" => true, "data" => $users]);
    }

    private function deleteUser($id, $permanent) {
        if (!$id) throw new Exception("ID necessário para exclusão.");

        $success = $permanent ? $this->repo->deleteUserPermanently($id) : $this->repo->softDeleteUser($id);
        
        if ($success) {
            $action = $permanent ? 'PERMANENT_DELETE' : 'SOFT_DELETE';
            $this->repo->saveLog($this->loggedUser['id'], $this->loggedUser['name'], $action, "Processou exclusão do usuário #$id", $id, 'USER');
        }
        return Response::json(["success" => $success]);
    }

    private function verifyUser($data) {
        $id = $data['id'] ?? null;
        $status = $data['status'] ?? 1;
        
        if ($this->repo->setUserVerification($id, $status)) {
            $this->repo->saveLog($this->loggedUser['id'], $this->loggedUser['name'], 'VERIFY_USER', "Status verificação ID $id: $status", $id, 'USER');
            if ($status == 1) {
                $this->notif->notify($id, "Perfil Verificado!", "Sua conta recebeu o selo de confiança.");
            }
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false]);
    }

    private function manageUsers($data) {
        if ($this->repo->updateUserDetails($data)) {
            $this->repo->saveLog($this->loggedUser['id'], $this->loggedUser['name'], 'UPDATE_USER', "Editou dados do usuário #{$data['id']}", $data['id'], 'USER');
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false]);
    }

    // =========================================================================
    // LOGÍSTICA & FRETES
    // =========================================================================

    private function listAllFreights() {
        return Response::json(["success" => true, "data" => $this->repo->listAllFreights()]);
    }

    private function updateFreightStatus($id, $status, $approveFeatured) {
        if (!$id) return Response::json(["success" => false, "message" => "ID do frete não informado"]);
        
        $freight = $this->repo->getFreightById($id);
        if (!$freight) return Response::json(["success" => false, "message" => "Frete inexistente"]);

        if ($this->repo->updateFreightStatus($id, $status, $approveFeatured)) {
            $this->repo->saveLog($this->loggedUser['id'], $this->loggedUser['name'], 'FREIGHT_STATUS', "Frete #$id alterado para $status", $id, 'FREIGHT');
            
            if ($status === 'OPEN') {
                $this->notif->notify($freight['user_id'], "Frete Online!", "Seu anúncio foi aprovado.");
                $this->triggerMatches($freight); 
            }
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false]);
    }

    private function triggerMatches($freight) {
        // Otimização: Passamos o objeto freight pronto para evitar nova query
        $drivers = $this->repo->findCompatibleDrivers(
            $freight['vehicle_type'], 
            $freight['body_type'], 
            $freight['origin_state']
        );

        foreach ($drivers as $driver) {
            $this->notif->notify($driver['user_id'], "Carga compatível!", "Nova carga de {$freight['product']} disponível.");
        }
    }

    // =========================================================================
    // CRM, ADS & CONFIGURAÇÕES
    // =========================================================================

    private function updateLeadInternal($data) {
        if ($this->repo->updateLeadInternal($data['id'], $data['admin_notes'] ?? '', $data['status'] ?? 'pending')) {
            $this->repo->saveLog($this->loggedUser['id'], $this->loggedUser['name'], 'UPDATE_LEAD', "Anotação no lead #{$data['id']}", $data['id'], 'LEAD');
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false]);
    }

    private function manageAds($data) {
        $id = $data['id'] ?? null;
        if ($data['action'] === 'delete') {
            $this->repo->saveLog($this->loggedUser['id'], $this->loggedUser['name'], 'DELETE_AD', "Desativou anúncio #$id", $id, 'AD');
            return Response::json(["success" => $this->repo->softDeleteAd($id)]);
        }
        return Response::json(["success" => $this->repo->toggleAdStatus($id)]);
    }

    private function updateSettings($data) {
        if ($this->repo->saveSettings($data)) {
            $this->repo->saveLog($this->loggedUser['id'], $this->loggedUser['name'], 'SETTING_UPDATE', "Alterou configurações globais", 0, 'SYSTEM');
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false]);
    }

    private function managePlans($data) {
        return Response::json(["success" => true, "data" => $this->repo->managePlans($data)]);
    }
}