<?php

namespace App\Controllers;

use App\Core\Response;
use Exception;
use PDO;

class PermissionController {
    private $db;
    private $loggedUser;

    public function __construct($db, $loggedUser = null) {
        $this->db = $db;
        $this->loggedUser = $loggedUser;
    }

    private function authorize($roles = ['ADMIN', 'MANAGER']) {
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
            $stmt = $this->db->query("SELECT * FROM permissions ORDER BY id ASC");
            $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $grouped = [];
            foreach ($permissions as $perm) {
                $module = explode('.', $perm['slug'])[0];
                $grouped[$module][] = $perm;
            }
            
            return Response::json([
                "success" => true,
                "data" => $permissions,
                "grouped" => $grouped
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao carregar permissões"]);
        }
    }

    public function create($data, $loggedUser) {
        $this->authorize();
        
        try {
            $slug = trim($data['slug'] ?? '');
            $label = trim($data['label'] ?? '');
            
            if (empty($slug) || empty($label)) {
                return Response::json(["success" => false, "message" => "Slug e Label são obrigatórios"], 400);
            }
            
            $stmt = $this->db->prepare("INSERT INTO permissions (slug, label) VALUES (?, ?)");
            $stmt->execute([$slug, $label]);
            
            $id = $this->db->lastInsertId();
            
            return Response::json([
                "success" => true,
                "message" => "Permissão criada com sucesso",
                "data" => ["id" => $id, "slug" => $slug, "label" => $label]
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao criar permissão"]);
        }
    }

    public function update($data, $loggedUser) {
        $this->authorize();
        
        try {
            $id = $data['id'] ?? null;
            $slug = trim($data['slug'] ?? '');
            $label = trim($data['label'] ?? '');
            
            if (!$id || empty($slug) || empty($label)) {
                return Response::json(["success" => false, "message" => "ID, Slug e Label são obrigatórios"], 400);
            }
            
            $stmt = $this->db->prepare("UPDATE permissions SET slug = ?, label = ? WHERE id = ?");
            $stmt->execute([$slug, $label, $id]);
            
            return Response::json([
                "success" => true,
                "message" => "Permissão atualizada com sucesso"
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao atualizar permissão"]);
        }
    }

    public function delete($data, $loggedUser) {
        $this->authorize();
        
        try {
            $id = $data['id'] ?? null;
            
            if (!$id) {
                return Response::json(["success" => false, "message" => "ID é obrigatório"], 400);
            }
            
            $stmt = $this->db->prepare("DELETE FROM permissions WHERE id = ?");
            $stmt->execute([$id]);
            
            return Response::json([
                "success" => true,
                "message" => "Permissão removida com sucesso"
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao remover permissão"]);
        }
    }

    public function getRolePermissions($data, $loggedUser) {
        $this->authorize();
        
        try {
            $roleId = $data['role_id'] ?? null;
            
            if (!$roleId) {
                return Response::json(["success" => false, "message" => "role_id é obrigatório"], 400);
            }
            
            $stmt = $this->db->prepare("
                SELECT p.* FROM permissions p
                JOIN role_permissions rp ON p.id = rp.permission_id
                WHERE rp.role_id = ?
            ");
            $stmt->execute([$roleId]);
            $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return Response::json([
                "success" => true,
                "data" => $permissions
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao carregar permissões do cargo"]);
        }
    }

    public function setRolePermissions($data, $loggedUser) {
        $this->authorize();
        
        try {
            $roleId = $data['role_id'] ?? null;
            $permissionIds = $data['permission_ids'] ?? [];
            
            if (!$roleId) {
                return Response::json(["success" => false, "message" => "role_id é obrigatório"], 400);
            }
            
            $this->db->beginTransaction();
            
            $this->db->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$roleId]);
            
            foreach ($permissionIds as $permId) {
                $this->db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)")
                    ->execute([$roleId, $permId]);
            }
            
            $this->db->commit();
            
            return Response::json([
                "success" => true,
                "message" => "Permissões atualizadas com sucesso"
            ]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return Response::json(["success" => false, "message" => "Erro ao salvar permissões"]);
        }
    }

    public function getUserPermissions($data, $loggedUser) {
        $this->authorize();
        
        try {
            $userId = $data['user_id'] ?? null;
            
            if (!$userId) {
                return Response::json(["success" => false, "message" => "user_id é obrigatório"], 400);
            }
            
            $stmt = $this->db->prepare("SELECT permissions FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $userPerms = $user ? json_decode($user['permissions'] ?? '[]', true) : [];
            
            return Response::json([
                "success" => true,
                "data" => $userPerms
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao carregar permissões do usuário"]);
        }
    }

    public function setUserPermissions($data, $loggedUser) {
        $this->authorize();
        
        try {
            $userId = $data['user_id'] ?? null;
            $permissions = $data['permissions'] ?? [];
            
            if (!$userId) {
                return Response::json(["success" => false, "message" => "user_id é obrigatório"], 400);
            }
            
            $stmt = $this->db->prepare("UPDATE users SET permissions = ? WHERE id = ?");
            $stmt->execute([json_encode($permissions), $userId]);
            
            return Response::json([
                "success" => true,
                "message" => "Permissões atualizadas com sucesso"
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao salvar permissões"]);
        }
    }
}
