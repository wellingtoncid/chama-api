<?php

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\AdminRepository;
use Exception;
use PDO;

class RoleController {
    private $db;
    private $loggedUser;
    private $adminRepo;

    public function __construct($db, $loggedUser = null) {
        $this->db = $db;
        $this->loggedUser = $loggedUser;
        $this->adminRepo = new AdminRepository($db);
    }

    private function authorize($roles = ['ADMIN']) {
        if (!$this->loggedUser) {
            throw new Exception("Sessão expirada", 401);
        }
        $userRole = strtoupper($this->loggedUser['role'] ?? '');
        if (!in_array($userRole, array_map('strtoupper', $roles))) {
            throw new Exception("Acesso negado", 403);
        }
    }

    public function getAll($data, $loggedUser) {
        $this->authorize();
        
        try {
            $stmt = $this->db->query("
                SELECT r.*, 
                    (SELECT COUNT(*) FROM users u WHERE u.role = r.slug) as user_count
                FROM roles r 
                ORDER BY r.id ASC
            ");
            $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $permissionsStmt = $this->db->query("SELECT * FROM permissions ORDER BY id ASC");
            $allPermissions = $permissionsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $rolePerms = [];
            foreach ($roles as $role) {
                $permStmt = $this->db->prepare("
                    SELECT p.id, p.slug, p.label 
                    FROM permissions p
                    JOIN role_permissions rp ON p.id = rp.permission_id
                    WHERE rp.role_id = ?
                ");
                $permStmt->execute([$role['id']]);
                $rolePerms[$role['id']] = $permStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            return Response::json([
                "success" => true,
                "data" => $roles,
                "permissions" => $allPermissions,
                "rolePermissions" => $rolePerms
            ]);
        } catch (\Throwable $e) {
            error_log('[RoleController] Error: ' . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao carregar cargos"]);
        }
    }

    public function create($data, $loggedUser) {
        $this->authorize();
        
        try {
            $name = trim($data['name'] ?? '');
            $slug = trim($data['slug'] ?? '');
            $permissionIds = $data['permission_ids'] ?? [];
            
            if (empty($name) || empty($slug)) {
                return Response::json(["success" => false, "message" => "Nome e Slug são obrigatórios"], 400);
            }
            
            $slug = strtolower(preg_replace('/[^a-z0-9_-]/', '', str_replace(' ', '_', $slug)));
            
            $checkStmt = $this->db->prepare("SELECT id FROM roles WHERE slug = ?");
            $checkStmt->execute([$slug]);
            if ($checkStmt->fetch()) {
                return Response::json(["success" => false, "message" => "Este slug já existe"], 400);
            }
            
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("INSERT INTO roles (name, slug) VALUES (?, ?)");
            $stmt->execute([$name, $slug]);
            $roleId = $this->db->lastInsertId();
            
            foreach ($permissionIds as $permId) {
                $this->db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)")
                    ->execute([$roleId, $permId]);
            }
            
            $this->db->commit();
            
            return Response::json([
                "success" => true,
                "message" => "Cargo criado com sucesso",
                "data" => ["id" => $roleId, "name" => $name, "slug" => $slug]
            ]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return Response::json(["success" => false, "message" => "Erro ao criar cargo"]);
        }
    }

    public function update($data, $loggedUser) {
        $this->authorize();
        
        try {
            $id = $data['id'] ?? null;
            $name = trim($data['name'] ?? '');
            $slug = trim($data['slug'] ?? '');
            $permissionIds = $data['permission_ids'] ?? [];
            
            if (!$id || empty($name) || empty($slug)) {
                return Response::json(["success" => false, "message" => "ID, Nome e Slug são obrigatórios"], 400);
            }
            
            $slug = strtolower(preg_replace('/[^a-z0-9_-]/', '', str_replace(' ', '_', $slug)));
            
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("UPDATE roles SET name = ?, slug = ? WHERE id = ?");
            $stmt->execute([$name, $slug, $id]);
            
            $this->db->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$id]);
            
            foreach ($permissionIds as $permId) {
                $this->db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)")
                    ->execute([$id, $permId]);
            }
            
            $syncResult = $this->adminRepo->syncUserModulesByRolePermissions($id, $permissionIds);
            
            $this->db->commit();
            
            return Response::json([
                "success" => true,
                "message" => "Cargo atualizado com sucesso",
                "sync_result" => $syncResult
            ]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return Response::json(["success" => false, "message" => "Erro ao atualizar cargo"]);
        }
    }

    public function delete($data, $loggedUser) {
        $this->authorize();
        
        try {
            $id = $data['id'] ?? null;
            
            if (!$id) {
                return Response::json(["success" => false, "message" => "ID é obrigatório"], 400);
            }
            
            $checkStmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM users WHERE role_id = ?");
            $checkStmt->execute([$id]);
            if ($checkStmt->fetch()['cnt'] > 0) {
                return Response::json(["success" => false, "message" => "Este cargo está em uso por usuários"], 400);
            }
            
            $stmt = $this->db->prepare("DELETE FROM roles WHERE id = ? AND slug NOT IN ('admin', 'driver', 'company')");
            $stmt->execute([$id]);
            
            return Response::json([
                "success" => true,
                "message" => "Cargo removido com sucesso"
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao remover cargo"]);
        }
    }

    public function getById($data, $loggedUser) {
        $this->authorize();
        
        try {
            $id = $data['id'] ?? null;
            
            if (!$id) {
                return Response::json(["success" => false, "message" => "ID é obrigatório"], 400);
            }
            
            $stmt = $this->db->prepare("SELECT * FROM roles WHERE id = ?");
            $stmt->execute([$id]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$role) {
                return Response::json(["success" => false, "message" => "Cargo não encontrado"], 404);
            }
            
            $permStmt = $this->db->prepare("
                SELECT p.* FROM permissions p
                JOIN role_permissions rp ON p.id = rp.permission_id
                WHERE rp.role_id = ?
            ");
            $permStmt->execute([$id]);
            $permissions = $permStmt->fetchAll(PDO::FETCH_ASSOC);
            
            return Response::json([
                "success" => true,
                "data" => $role,
                "permissions" => $permissions
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao carregar cargo"]);
        }
    }
}
