<?php
namespace App\Repositories;

use PDO;

class GroupRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Lista grupos com base no perfil do usuário e status
     */
    public function listActive($userRole = 'all') {
        $sql = "SELECT * FROM whatsapp_groups 
                WHERE is_deleted = 0 AND status = 'active'";
        $params = [];

        if ($userRole !== 'admin' && $userRole !== 'manager' && $userRole !== 'all') {
            $sql .= " AND (target_role = ? OR target_role = 'ALL')";
            $params[] = strtoupper($userRole);
        }

        $sql .= " ORDER BY priority_level DESC, region_name ASC";
        
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

    public function save(array $data) {
        $id = $data['id'] ?? null;
        
        $fields = [
            'region_name'      => $data['region_name'] ?? '',
            'invite_link'      => $data['invite_link'] ?? '',
            'member_count'     => (int)($data['member_count'] ?? 0),
            'is_public'        => (int)($data['is_public'] ?? 0),
            'is_visible_home'  => (int)($data['is_visible_home'] ?? 0),
            'target_role'      => $data['target_role'] ?? 'ALL',
            'category'         => $data['category'] ?? 'Geral',
            'priority_level'   => (int)($data['priority_level'] ?? 0),
            'internal_notes'   => $data['internal_notes'] ?? '',
            'group_admin_name' => $data['group_admin_name'] ?? '',
            'status'           => $data['status'] ?? 'active',
            'is_verified'      => (int)($data['is_verified'] ?? 0),
            'is_premium'       => (int)($data['is_premium'] ?? 0),
            'display_location' => $data['display_location'] ?? 'both',
            'access_type'      => $data['access_type'] ?? 'public'
        ];

        if ($id) {
            $sql = "UPDATE whatsapp_groups SET 
                    region_name = :region_name, invite_link = :invite_link, member_count = :member_count,
                    is_public = :is_public, is_visible_home = :is_visible_home, target_role = :target_role,
                    category = :category, priority_level = :priority_level, internal_notes = :internal_notes,
                    group_admin_name = :group_admin_name, status = :status, is_verified = :is_verified,
                    is_premium = :is_premium, display_location = :display_location, access_type = :access_type
                    WHERE id = :id";
            $fields['id'] = $id;
        } else {
            $sql = "INSERT INTO whatsapp_groups 
                    (region_name, invite_link, member_count, is_public, is_visible_home, target_role, 
                    category, priority_level, internal_notes, group_admin_name, status, is_verified, 
                    is_premium, display_location, access_type) 
                    VALUES 
                    (:region_name, :invite_link, :member_count, :is_public, :is_visible_home, :target_role, 
                    :category, :priority_level, :internal_notes, :group_admin_name, :status, :is_verified, 
                    :is_premium, :display_location, :access_type)";
        }

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($fields) ? ($id ?: $this->db->lastInsertId()) : false;
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