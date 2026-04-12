<?php
namespace App\Repositories;

use PDO;

class GroupRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Lista grupos para o site público (não logado)
     * Filtra por display_location = 'site' ou 'both'
     * Se $homeOnly = true, filtra apenas grupos visíveis na home
     */
    public function listActive($userRole = 'all', $homeOnly = false) {
        $userRole = strtolower($userRole);
        $isAdmin = in_array($userRole, ['admin', 'manager']);

        $sql = "SELECT g.*, 
                       gc.name as category_name, 
                       gc.color as category_color,
                       gc.slug as category_slug
                FROM whatsapp_groups g
                LEFT JOIN group_categories gc ON g.category_id = gc.id
                WHERE g.is_deleted = 0
                AND (g.display_location = 'site' OR g.display_location = 'both')";
        
        if (!$isAdmin) {
            $sql .= " AND g.status = 'active'";
        }

        if ($homeOnly) {
            $sql .= " AND g.is_visible_home = 1";
        }
        
        $params = [];

        if (!$isAdmin && $userRole !== 'all') {
            $sql .= " AND (g.target_role = ? OR g.target_role = 'ALL')";
            $params[] = strtoupper($userRole);
        }

        $sql .= " ORDER BY g.is_visible_home DESC, g.is_premium DESC, g.priority_level DESC, g.region_name ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lista grupos para a plataforma (usuários logados)
     * Filtra por display_location = 'platform' ou 'both'
     */
    public function listForPlatform($userRole = 'all') {
        $userRole = strtolower($userRole);
        $isAdmin = in_array($userRole, ['admin', 'manager']);

        $sql = "SELECT g.*, 
                       gc.name as category_name, 
                       gc.color as category_color,
                       gc.slug as category_slug
                FROM whatsapp_groups g
                LEFT JOIN group_categories gc ON g.category_id = gc.id
                WHERE g.is_deleted = 0
                AND (g.display_location = 'platform' OR g.display_location = 'both')";
        
        if (!$isAdmin) {
            $sql .= " AND g.status = 'active'";
        }

        $params = [];

        if (!$isAdmin && $userRole !== 'all') {
            $sql .= " AND (g.target_role = ? OR g.target_role = 'ALL')";
            $params[] = strtoupper($userRole);
        }

        $sql .= " ORDER BY g.is_premium DESC, g.priority_level DESC, g.region_name ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Incrementa visualizações em lote para performance
     */
    public function incrementViews(array $ids) {
        if (empty($ids)) return false;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->prepare("UPDATE whatsapp_groups SET views_count = views_count + 1 WHERE id IN ($placeholders)")
                        ->execute($ids);
    }

    public function incrementClick($id) {
        return $this->db->prepare("UPDATE whatsapp_groups SET clicks_count = clicks_count + 1 WHERE id = ?")
                        ->execute([$id]);
    }

    /**
     * Lista TODOS os grupos para admin (sem filtro de display_location)
     */
    public function listAll() {
        $sql = "SELECT g.*, gc.name as category_name, gc.color as category_color 
                FROM whatsapp_groups g 
                LEFT JOIN group_categories gc ON g.category_id = gc.id 
                WHERE g.is_deleted = 0 
                ORDER BY g.priority_level DESC, g.region_name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca um grupo pelo ID com informações do admin
     */
    public function findById($id) {
        $sql = "SELECT g.*, 
                       gc.name as category_name, 
                       gc.color as category_color, 
                       gc.slug as category_slug,
                       u.id as admin_user_id,
                       u.name as admin_user_name,
                       u.whatsapp as admin_user_whatsapp,
                       u.is_verified as admin_user_verified,
                       up.slug as admin_user_slug,
                       up.avatar_url as admin_user_avatar,
                       a.corporate_name as admin_company_name,
                       (SELECT COUNT(*) FROM whatsapp_groups WHERE admin_user_id = u.id AND is_deleted = 0) as admin_total_groups
                FROM whatsapp_groups g 
                LEFT JOIN group_categories gc ON g.category_id = gc.id 
                LEFT JOIN users u ON g.admin_user_id = u.id
                LEFT JOIN user_profiles up ON u.id = up.user_id
                LEFT JOIN accounts a ON u.account_id = a.id
                WHERE g.id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            if (!empty($result['other_admins'])) {
                $decoded = json_decode($result['other_admins'], true);
                $result['other_admins'] = is_array($decoded) ? $decoded : [];
            }
        }
        
        error_log("findById($id): " . ($result ? "found" : "not found"));
        return $result;
    }

    public function save(array $data) {
        try {
            $id = !empty($data['id']) ? (int)$data['id'] : null;
            
            $otherAdmins = null;
            if (!empty($data['other_admins']) && is_array($data['other_admins'])) {
                $otherAdmins = json_encode($data['other_admins'], JSON_UNESCAPED_UNICODE);
            }
            
            $fields = [
                'region_name'      => strip_tags($data['region_name'] ?? ''),
                'invite_link'      => trim($data['invite_link'] ?? ''),
                'image_url'        => trim($data['image_url'] ?? ''),
                'description'      => $data['description'] ?? null,
                'admin_user_id'    => !empty($data['admin_user_id']) ? (int)$data['admin_user_id'] : null,
                'member_count'     => (int)($data['member_count'] ?? 0),
                'is_public'        => (int)($data['is_public'] ?? 0),
                'is_visible_home'  => (int)($data['is_visible_home'] ?? 0),
                'target_role'      => strtoupper($data['target_role'] ?? 'ALL'),
                'category_id'      => !empty($data['category_id']) ? (int)$data['category_id'] : null,
                'priority_level'   => (int)($data['priority_level'] ?? 0),
                'internal_notes'   => $data['internal_notes'] ?? null,
                'group_admin_name' => $data['group_admin_name'] ?? null,
                'other_admins'    => $otherAdmins,
                'status'           => $data['status'] ?? 'active',
                'is_verified'      => (int)($data['is_verified'] ?? 0),
                'is_premium'       => (int)($data['is_premium'] ?? 0),
                'display_location' => $data['display_location'] ?? 'both',
                'access_type'      => $data['access_type'] ?? 'public'
            ];

            if ($id) {
                $sql = "UPDATE whatsapp_groups SET 
                            region_name = :region_name, 
                            invite_link = :invite_link, 
                            image_url = :image_url,
                            description = :description,
                            admin_user_id = :admin_user_id,
                            member_count = :member_count,
                            is_public = :is_public, 
                            is_visible_home = :is_visible_home, 
                            target_role = :target_role,
                            category_id = :category_id, 
                            priority_level = :priority_level, 
                            internal_notes = :internal_notes,
                            group_admin_name = :group_admin_name, 
                            other_admins = :other_admins,
                            status = :status, 
                            is_verified = :is_verified,
                            is_premium = :is_premium, 
                            display_location = :display_location, 
                            access_type = :access_type
                        WHERE id = :id AND is_deleted = 0";
                $fields['id'] = $id;
            } else {
                $sql = "INSERT INTO whatsapp_groups 
                            (region_name, invite_link, image_url, description, admin_user_id, member_count, is_public, is_visible_home, target_role, 
                            category_id, priority_level, internal_notes, group_admin_name, other_admins, status, is_verified, 
                            is_premium, display_location, access_type) 
                        VALUES 
                            (:region_name, :invite_link, :image_url, :description, :admin_user_id, :member_count, :is_public, :is_visible_home, :target_role, 
                            :category_id, :priority_level, :internal_notes, :group_admin_name, :other_admins, :status, :is_verified, 
                            :is_premium, :display_location, :access_type)";
            }

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute($fields);

            if ($success) {
                return $id ?: $this->db->lastInsertId();
            }
            
            return false;

        } catch (\PDOException $e) {
            error_log("Erro ao salvar grupo: " . $e->getMessage());
            return false;
        }
    }

    public function softDelete($id) {
        return $this->db->prepare("UPDATE whatsapp_groups SET is_deleted = 1, status = 'inactive' WHERE id = ?")
                        ->execute([$id]);
    }

    public function incrementCounter($id, $eventType) {
        $column = ($eventType === 'VIEW' || $eventType === 'VIEW_DETAILS') ? 'views_count' : 'clicks_count';
        $column = ($eventType === 'WHATSAPP_CLICK') ? 'clicks_count' : 'views_count';
        
        $tableName = 'whatsapp_groups';
        $sql = "UPDATE {$tableName} SET {$column} = {$column} + 1 WHERE id = :id";
            try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([':id' => (int)$id]);
        } catch (\Exception $e) {
            error_log("Erro ao incrementar contador na tabela {$tableName}: " . $e->getMessage());
            return false;
        }
    }
}