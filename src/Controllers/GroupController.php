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

    /**
     * Busca um grupo pelo ID (para página de detalhes)
     */
    public function getGroup($data, $loggedUser = null) {
        $id = (int)($data['id'] ?? 0);
        
        error_log("getGroup called with data: " . json_encode($data) . ", id: $id");
        
        if (!$id) {
            return Response::json(['success' => false, 'message' => 'ID não fornecido'], 400);
        }
        
        $group = $this->groupRepo->findById($id);
        
        if (!$group) {
            error_log("getGroup: group not found for id: $id");
            return Response::json(['success' => false, 'message' => 'Grupo não encontrado'], 404);
        }
        
        return Response::json(['success' => true, 'data' => $group]);
    }

    public function listGroups($data = [], $loggedUser = null) {
        $role = $this->loggedUser['role'] ?? 'all';
        $homeOnly = !empty($data['home']);
        
        $groups = $this->groupRepo->listActive($role, $homeOnly);

        // Se não for admin, incrementa visualizações de forma otimizada
        if (!in_array(strtolower($role), ['admin', 'manager']) && !empty($groups)) {
            $ids = array_column($groups, 'id');
            $this->groupRepo->incrementViews($ids);
        }

        return Response::json(["success" => true, "data" => $groups]);
    }

    /**
     * Lista grupos para a plataforma (usuários logados)
     * Filtra por display_location = 'platform' ou 'both'
     */
    public function listPlatformGroups() {
        $role = $this->loggedUser['role'] ?? 'all';
        $groups = $this->groupRepo->listForPlatform($role);

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
        $this->checkAuth();
        
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
        $this->checkAuth();
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

    /**
     * Upload de imagem do grupo
     */
    public function uploadImage($data, $loggedUser) {
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Não autorizado"], 401);
        }
        
        $role = strtolower($loggedUser['role'] ?? '');
        if (!in_array($role, ['admin', 'manager'])) {
            return Response::json(["success" => false, "message" => "Não autorizado"], 403);
        }
        
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            return Response::json(["success" => false, "message" => "Nenhuma imagem enviada ou erro no upload"], 400);
        }
        
        $file = $_FILES['image'];
        
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
        if (!in_array($file['type'], $allowed)) {
            return Response::json(["success" => false, "message" => "Tipo de arquivo não permitido. Use JPG, PNG ou WebP."], 400);
        }
        
        $maxSize = 5 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            return Response::json(["success" => false, "message" => "Arquivo muito grande. Máximo 5MB."], 400);
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = 'group_' . time() . '_' . uniqid() . '.' . $extension;
        $uploadDir = __DIR__ . "/../../public/uploads/groups/";
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $targetPath = $uploadDir . $fileName;
        
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $imageUrl = "uploads/groups/" . $fileName;
            return Response::json(["success" => true, "url" => $imageUrl]);
        }
        
        return Response::json(["success" => false, "message" => "Erro ao salvar imagem"], 500);
    }

    public function checkAuth() {
        $role = strtolower($this->loggedUser['role'] ?? '');
        if (!in_array($role, ['admin', 'manager'])) {
            Response::json(["success" => false, "message" => "Não autorizado"], 403);
            exit;
        }
    }
}