<?php
namespace App\Controllers;

use App\Core\Response;
use App\Repositories\GroupRepository;
use App\Repositories\AdminRepository;

class GroupController {
    private $groupRepo;
    private $adminRepo;
    private $loggedUser;

    public function __construct($db, $loggedUser = null) {
        $this->groupRepo = new GroupRepository($db);
        $this->adminRepo = new AdminRepository($db);
        $this->loggedUser = $loggedUser;
    }

    public function listGroups() {
        $role = $this->loggedUser['role'] ?? 'all';
        $groups = $this->groupRepo->listActive($role);

        // Se não for admin, incrementa visualizações de forma otimizada
        if (!in_array(strtolower($role), ['admin', 'manager']) && !empty($groups)) {
            $ids = array_column($groups, 'id');
            $this->groupRepo->incrementViews($ids);
        }

        return Response::json(["success" => true, "data" => $groups]);
    }

    /**
     * Cria ou atualiza um grupo (Apenas Admin/Manager)
     */
    public function manageGroups($data, $loggedUser) {
        $this->authorize($loggedUser);
        
        $result = $this->groupRepo->save($data);
        if ($result) {
            $action = isset($data['id']) ? 'UPDATE_GROUP' : 'CREATE_GROUP';
            $this->adminRepo->saveLog(
                $loggedUser['id'], 
                $loggedUser['name'], 
                $action, 
                "Gerenciou grupo: " . ($data['region_name'] ?? 'ID '.$result), 
                $result, 
                'WA_GROUP'
            );
            return Response::json(["success" => true, "id" => $result]);
        }

        return Response::json(["success" => false, "message" => "Erro ao salvar grupo"]);
    }

    /**
     * Rastreia cliques no link do WhatsApp
     */
    public function logGroupClick($data, $loggedUser) {
        $id = $data['id'] ?? null;
        if ($id && $this->groupRepo->incrementClick($id)) {
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false, "message" => "ID inválido"], 400);
    }

    public function store($data) {
        $this->checkAuth();
        
        $result = $this->groupRepo->save($data);
        if ($result) {
            $action = isset($data['id']) ? 'UPDATE_GROUP' : 'CREATE_GROUP';
            $this->adminRepo->saveLog(
                $this->loggedUser['id'], 
                $this->loggedUser['name'], 
                $action, 
                "Gerenciou grupo: " . ($data['region_name'] ?? 'ID '.$result), 
                $result, 
                'WA_GROUP'
            );
        }

        return Response::json(["success" => (bool)$result, "id" => $result]);
    }

    /**
     * Deleta um grupo (Apenas Admin/Manager)
     */
    public function deleteGroup($data, $loggedUser) {
        $this->authorize($loggedUser);
        $id = $data['id'] ?? null;

        if ($id && $this->groupRepo->softDelete($id)) {
            $this->adminRepo->saveLog(
                $loggedUser['id'], 
                $loggedUser['name'], 
                'DELETE_GROUP', 
                "Removeu grupo ID: $id", 
                $id, 
                'WA_GROUP'
            );
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false, "message" => "Erro ao remover ou ID inválido"], 400);
    }

    public function checkAuth() {
        $role = strtolower($this->loggedUser['role'] ?? '');
        if (!in_array($role, ['admin', 'manager'])) {
            Response::json(["success" => false, "message" => "Não autorizado"], 403);
            exit;
        }
    }
}