<?php

class GroupController {
    private $db;

    public function __construct($db) { 
        $this->db = $db; 
    }

    public function handle($method, $endpoint, $data, $loggedUser) {
        switch ($endpoint) {
            case 'list-groups':
                $role = isset($loggedUser['role']) ? strtolower($loggedUser['role']) : 'all';
                return $this->listGroups($role);
            
            case 'manage-groups':
                $userRole = isset($loggedUser['role']) ? strtolower($loggedUser['role']) : '';
                if (!in_array($userRole, ['admin', 'manager'])) {
                    return ["success" => false, "message" => "Não autorizado"];
                }
                return $this->manageGroups($method, $data, $loggedUser);

            case 'log-group-click':
                return $this->incrementMetric($data['id'] ?? null, 'clicks_count');

            default:
                return ["success" => false, "error" => "Endpoint inválido"];
        }
    }

    private function listGroups($userRole) {
        // AJUSTE: Incluídas as novas colunas is_verified, is_premium, access_type e display_location
        $sql = "SELECT id, region_name, invite_link, member_count, is_public, 
                       is_visible_home, target_role, category, priority_level, 
                       internal_notes, views_count, clicks_count, status, group_admin_name,
                       is_verified, is_premium, access_type, display_location
                FROM whatsapp_groups 
                WHERE is_deleted = 0 
                ORDER BY priority_level DESC, region_name ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Se for um usuário comum visualizando, incrementamos o view_count
        if ($userRole !== 'admin' && $userRole !== 'manager') {
            foreach ($groups as $g) {
                $this->db->prepare("UPDATE whatsapp_groups SET views_count = views_count + 1 WHERE id = ?")
                         ->execute([$g['id']]);
            }
        }
        return $groups;
    }

    private function manageGroups($method, $data, $loggedUser) {
        $adminId = $loggedUser['id'] ?? 0;
        $adminName = $loggedUser['name'] ?? 'Admin';

        if ($method === 'POST') {
            try {
                $id = isset($data['id']) ? (int)$data['id'] : 0;
                $memberCount = isset($data['member_count']) ? (int)$data['member_count'] : 0;
                $priority = isset($data['priority_level']) ? (int)$data['priority_level'] : 0;
                $isPublic = (isset($data['is_public']) && ($data['is_public'] == 1 || $data['is_public'] === true)) ? 1 : 0;
                $isHome = (isset($data['is_visible_home']) && ($data['is_visible_home'] == 1 || $data['is_visible_home'] === true)) ? 1 : 0;
                
                // NOVOS AJUSTES: Capturando os dados de selos e regras
                $isVerified = (isset($data['is_verified']) && ($data['is_verified'] == 1 || $data['is_verified'] === true)) ? 1 : 0;
                $isPremium = (isset($data['is_premium']) && ($data['is_premium'] == 1 || $data['is_premium'] === true)) ? 1 : 0;
                $displayLocation = $data['display_location'] ?? 'both';
                $accessType = $data['access_type'] ?? 'public';

                $params = [
                    $data['region_name'] ?? '',
                    $data['invite_link'] ?? '',
                    $memberCount,
                    $isPublic,
                    $isHome,
                    $data['target_role'] ?? 'all',
                    $data['category'] ?? 'Geral',
                    $priority,
                    $data['internal_notes'] ?? '',
                    $data['group_admin_name'] ?? '',
                    $data['status'] ?? 'active',
                    // Adicionados ao array de params
                    $isVerified,
                    $isPremium,
                    $displayLocation,
                    $accessType
                ];

                if ($id > 0) {
                    // AJUSTE: Query de UPDATE com novas colunas
                    $sql = "UPDATE whatsapp_groups SET 
                            region_name = ?, invite_link = ?, member_count = ?, 
                            is_public = ?, is_visible_home = ?, target_role = ?, 
                            category = ?, priority_level = ?, internal_notes = ?, 
                            group_admin_name = ?, status = ?, 
                            is_verified = ?, is_premium = ?, display_location = ?, access_type = ? 
                            WHERE id = ?";
                    $params[] = $id;
                } else {
                    // AJUSTE: Query de INSERT com novas colunas
                    $sql = "INSERT INTO whatsapp_groups 
                            (region_name, invite_link, member_count, is_public, is_visible_home, 
                             target_role, category, priority_level, internal_notes, group_admin_name, 
                             status, is_verified, is_premium, display_location, access_type) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                }
                
                $stmt = $this->db->prepare($sql);
                $success = $stmt->execute($params);
                
                if ($success) {
                    $logId = $id ?: $this->db->lastInsertId();
                    $this->saveLog($adminId, $adminName, $id > 0 ? 'UPDATE' : 'CREATE', "Gerenciou grupo: " . ($data['region_name'] ?? 'N/A'), $logId, 'WA_GROUP');
                }
                return ["success" => $success];

            } catch (PDOException $e) {
                return ["success" => false, "message" => "Erro no Banco: " . $e->getMessage()];
            }
        }

        if ($method === 'DELETE') {
            if (!isset($data['id'])) return ["success" => false, "message" => "ID ausente"];
            $stmt = $this->db->prepare("UPDATE whatsapp_groups SET is_deleted = 1, status = 'inactive' WHERE id = ?");
            $success = $stmt->execute([$data['id']]);
            if ($success) {
                $this->saveLog($adminId, $adminName, 'DELETE', "Removeu grupo ID: " . $data['id'], $data['id'], 'WA_GROUP');
            }
            return ["success" => $success];
        }
    }

    private function incrementMetric($id, $column) {
        if (!$id || !in_array($column, ['views_count', 'clicks_count'])) return ["success" => false];
        $stmt = $this->db->prepare("UPDATE whatsapp_groups SET $column = $column + 1 WHERE id = ?");
        return ["success" => $stmt->execute([$id])];
    }

    private function saveLog($uId, $uName, $type, $desc, $targetId, $targetType) {
        try {
            $sql = "INSERT INTO admin_actions_logs (user_id, user_name, action_type, description, target_id, target_type, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $this->db->prepare($sql)->execute([$uId, $uName, $type, $desc, (int)$targetId, $targetType]);
        } catch (Exception $e) {}
    }
}