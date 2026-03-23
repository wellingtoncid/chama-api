<?php

namespace App\Controllers;

use App\Core\Response;
use Exception;
use PDO;

class ModuleController {
    private $db;
    private $loggedUser;

    public function __construct($db, $loggedUser = null) {
        $this->db = $db;
        $this->loggedUser = $loggedUser;
    }

    private function authorize() {
        if (!$this->loggedUser) {
            throw new Exception("Sessão expirada", 401);
        }
        $userRole = strtoupper($this->loggedUser['role'] ?? '');
        if ($userRole !== 'ADMIN') {
            throw new Exception("Acesso negado", 403);
        }
    }

    public function getAll($data, $loggedUser) {
        $this->authorize();
        
        try {
            $stmt = $this->db->query("SELECT * FROM modules ORDER BY sort_order ASC, id ASC");
            $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $rolesStmt = $this->db->query("SELECT * FROM roles ORDER BY id ASC");
            $roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $permStmt = $this->db->query("SELECT * FROM permissions ORDER BY id ASC");
            $allPermissions = $permStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $rolePerms = [];
            foreach ($roles as $role) {
                $rpStmt = $this->db->prepare("
                    SELECT p.slug FROM permissions p
                    JOIN role_permissions rp ON p.id = rp.permission_id
                    WHERE rp.role_id = ?
                ");
                $rpStmt->execute([$role['id']]);
                $rolePerms[$role['id']] = array_column($rpStmt->fetchAll(PDO::FETCH_ASSOC), 'slug');
            }
            
            foreach ($modules as &$module) {
                $prefix = $module['permission_prefix'] ?? '';
                
                $module['permissions'] = array_filter($allPermissions, function($p) use ($prefix) {
                    return str_starts_with($p['slug'], $prefix . '.');
                });
                
                $module['roles_with_access'] = [];
                
                foreach ($roles as $role) {
                    $roleSlug = strtolower($role['slug']);
                    
                    if ($roleSlug === 'admin') {
                        $module['roles_with_access'][] = [
                            'id' => $role['id'],
                            'name' => $role['name'],
                            'slug' => $role['slug'],
                            'type' => 'internal',
                            'access' => 'full'
                        ];
                        continue;
                    }
                    
                    $roleSlugs = $rolePerms[$role['id']] ?? [];
                    $modulePerms = array_filter($roleSlugs, function($s) use ($prefix) {
                        return str_starts_with($s, $prefix . '.');
                    });
                    
                    if (!empty($modulePerms)) {
                        $hasView = in_array($prefix . '.view', $modulePerms);
                        $hasFull = !empty(array_filter($modulePerms, fn($s) => $s !== $prefix . '.view'));
                        $module['roles_with_access'][] = [
                            'id' => $role['id'],
                            'name' => $role['name'],
                            'slug' => $role['slug'],
                            'type' => $role['type'] ?? 'internal',
                            'access' => $hasFull ? 'full' : ($hasView ? 'view' : 'none')
                        ];
                    }
                }
            }
            
            return Response::json([
                "success" => true,
                "data" => $modules,
                "roles" => $roles,
                "rolePermissions" => $rolePerms
            ]);
        } catch (\Throwable $e) {
            error_log('[ModuleController] Error: ' . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao carregar módulos"]);
        }
    }

    public function create($data, $loggedUser) {
        $this->authorize();
        
        try {
            $key = trim($data['key'] ?? '');
            $label = trim($data['label'] ?? '');
            $description = trim($data['description'] ?? '');
            $icon = trim($data['icon'] ?? '');
            $defaultFor = $data['default_for'] ?? [];
            $isRequired = $data['is_required'] ?? false;
            
            if (empty($key) || empty($label)) {
                return Response::json(["success" => false, "message" => "Chave e Nome são obrigatórios"], 400);
            }
            
            $checkStmt = $this->db->prepare("SELECT id FROM modules WHERE module_key = ?");
            $checkStmt->execute([$key]);
            if ($checkStmt->fetch()) {
                return Response::json(["success" => false, "message" => "Esta chave já existe"], 400);
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO modules (module_key, label, description, icon, default_for, is_required) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $key, 
                $label, 
                $description, 
                $icon, 
                json_encode($defaultFor), 
                $isRequired ? 1 : 0
            ]);
            
            $id = $this->db->lastInsertId();
            
            return Response::json([
                "success" => true,
                "message" => "Módulo criado com sucesso",
                "data" => ["id" => $id, "key" => $key, "label" => $label]
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao criar módulo"]);
        }
    }

    public function update($data, $loggedUser) {
        $this->authorize();
        
        try {
            $id = $data['id'] ?? null;
            $key = trim($data['key'] ?? '');
            $label = trim($data['label'] ?? '');
            $description = trim($data['description'] ?? '');
            $icon = trim($data['icon'] ?? '');
            $defaultFor = $data['default_for'] ?? [];
            $isRequired = $data['is_required'] ?? false;
            
            if (!$id) {
                return Response::json(["success" => false, "message" => "ID é obrigatório"], 400);
            }
            
            $stmt = $this->db->prepare("
                UPDATE modules 
                SET label = ?, description = ?, icon = ?, default_for = ?, is_required = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $label, 
                $description, 
                $icon, 
                json_encode($defaultFor), 
                $isRequired ? 1 : 0,
                $id
            ]);
            
            return Response::json([
                "success" => true,
                "message" => "Módulo atualizado com sucesso"
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao atualizar módulo"]);
        }
    }

    public function delete($data, $loggedUser) {
        $this->authorize();
        
        try {
            $id = $data['id'] ?? null;
            
            if (!$id) {
                return Response::json(["success" => false, "message" => "ID é obrigatório"], 400);
            }
            
            $checkStmt = $this->db->prepare("SELECT module_key FROM modules WHERE id = ? AND is_required = 1");
            $checkStmt->execute([$id]);
            if ($checkStmt->fetch()) {
                return Response::json(["success" => false, "message" => "Módulos obrigatórios não podem ser excluídos"], 400);
            }
            
            $stmt = $this->db->prepare("DELETE FROM modules WHERE id = ?");
            $stmt->execute([$id]);
            
            return Response::json([
                "success" => true,
                "message" => "Módulo removido com sucesso"
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao remover módulo"]);
        }
    }
}
