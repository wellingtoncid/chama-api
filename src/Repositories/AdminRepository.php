<?php

namespace App\Repositories;

use PDO;

class AdminRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    // =========================================================================
    // LOGS & AUDITORIA (logsauditoria)
    // =========================================================================

    public function saveLog($uId, $uName, $type, $desc, $targetId, $targetType) {
        $sql = "INSERT INTO logsauditoria (user_id, user_name, action_type, description, target_id, target_type) 
                VALUES (?, ?, ?, ?, ?, ?)";
        return $this->db->prepare($sql)->execute([$uId, $uName, $type, $desc, $targetId, $targetType]);
    }

    public function getAuditLogs($limit = 50) {
        $sql = "SELECT * FROM logsauditoria ORDER BY created_at DESC LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // DASHBOARD & ESTATÍSTICAS AVANÇADAS
    // =========================================================================

    public function getDashboardStats() {
        $counters = $this->db->query("
            SELECT 
                (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) as total_users,
                (SELECT COUNT(*) FROM users WHERE role = 'driver' AND deleted_at IS NULL) as drivers,
                (SELECT COUNT(*) FROM users WHERE role = 'company' AND deleted_at IS NULL) as companies,
                (SELECT COUNT(*) FROM freights WHERE status = 'PENDING') as pending_freights,
                (SELECT COUNT(*) FROM freights WHERE status = 'OPEN') as active_freights,
                (SELECT COUNT(*) FROM portal_requests WHERE status = 'pending') as pending_leads,
                IFNULL(SUM(views_count), 0) as total_views,
                IFNULL(SUM(clicks_count), 0) as total_clicks
            FROM freights
        ")->fetch(PDO::FETCH_ASSOC);

        $revenue = $this->db->query("
            SELECT 
                IFNULL(SUM(CASE WHEN status IN ('approved', 'completed') THEN amount ELSE 0 END), 0) as confirmed,
                IFNULL(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending
            FROM transactions
        ")->fetch(PDO::FETCH_ASSOC);

        return [
            'counters' => $counters,
            'revenue' => $revenue
        ];
    }

    public function getDetailedRevenue() {
        return $this->db->query("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                SUM(amount) as total,
                COUNT(id) as transactions
            FROM transactions 
            WHERE status IN ('approved', 'completed')
            GROUP BY month 
            ORDER BY month DESC 
            LIMIT 12
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // USUÁRIOS (Incluso Soft Delete e Filtros)
    // =========================================================================

    public function listUsersByRole($role = '%', $search = '%') {
        $sql = "SELECT id, name, email, whatsapp, role, is_verified, status, created_at 
                FROM users 
                WHERE role LIKE ? AND (name LIKE ? OR email LIKE ?) AND deleted_at IS NULL 
                ORDER BY id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$role, "%$search%", "%$search%"]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setUserVerification($id, $status) {
        return $this->db->prepare("UPDATE users SET is_verified = ? WHERE id = ?")->execute([$status, $id]);
    }

    public function updateUserDetails($data) {
        $perms = json_encode($data['permissions'] ?? []);
        $sql = "UPDATE users SET name = ?, whatsapp = ?, role = ?, status = ?, permissions = ?, company_name = ?, cnpj = ? WHERE id = ?";
        return $this->db->prepare($sql)->execute([
            $data['name'], $data['whatsapp'], $data['role'], 
            $data['status'], $perms, $data['company_name'], $data['cnpj'], $data['id']
        ]);
    }

    public function softDeleteUser($id) {
        // Marcamos como deletado mas mantemos os dados para auditoria
        return $this->db->prepare("UPDATE users SET deleted_at = NOW(), status = 'inactive' WHERE id = ? AND role != 'admin'")->execute([$id]);
    }

    public function deleteUserPermanently($id) {
        $this->db->prepare("DELETE FROM freights WHERE user_id = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM user_profiles WHERE user_id = ?")->execute([$id]);
        return $this->db->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'")->execute([$id]);
    }

    // =========================================================================
    // FRETES & MATCHING
    // =========================================================================

    public function listAllFreights() {
        return $this->db->query("
            SELECT f.*, u.name as company_name 
            FROM freights f 
            LEFT JOIN users u ON f.user_id = u.id 
            ORDER BY f.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getFreightById($id) {
        $stmt = $this->db->prepare("SELECT * FROM freights WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateFreightStatus($id, $status, $approveFeatured) {
        $sql = "UPDATE freights SET status = ?, 
                is_featured = CASE WHEN requested_featured = 1 AND ? = 1 THEN 1 ELSE is_featured END,
                requested_featured = 0 WHERE id = ?";
        return $this->db->prepare($sql)->execute([$status, $approveFeatured ? 1 : 0, $id]);
    }

    public function findCompatibleDrivers($vehicleType, $bodyType, $originState) {
        // Busca motoristas com perfil compatível e que possuam push_token para notificação
        $sql = "SELECT u.id, u.name, p.push_token 
                FROM users u 
                JOIN user_profiles p ON u.id = p.user_id 
                WHERE u.role = 'driver' 
                AND u.deleted_at IS NULL
                AND ((p.vehicle_type = ? AND p.body_type = ?) OR p.preferred_region = ?)
                LIMIT 100";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$vehicleType, $bodyType, $originState]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // CRM / PORTAL REQUESTS (Leads)
    // =========================================================================

    public function savePortalRequest($data, $priority) {
        $sql = "INSERT INTO portal_requests (type, title, link, contact_info, status, description, priority) 
                VALUES (?, ?, ?, ?, 'pending', ?, ?)";
        return $this->db->prepare($sql)->execute([
            $data['type'] ?? 'suggestion',
            $data['title'] ?? null,
            $data['link'] ?? null,
            $data['contact_info'] ?? null,
            $data['description'] ?? null,
            $priority
        ]);
    }

    public function updateLeadInternal($id, $note, $status) {
        return $this->db->prepare("UPDATE portal_requests SET admin_notes = ?, status = ? WHERE id = ?")
                        ->execute([$note, $status, $id]);
    }

    public function getPortalRequests($filters) {
        $status = $filters['status'] ?? '%';
        $type = $filters['type'] ?? '%';
        $search = isset($filters['search']) ? "%{$filters['search']}%" : '%';

        $sql = "SELECT * FROM portal_requests 
                WHERE status LIKE ? AND type LIKE ? 
                AND (title LIKE ? OR contact_info LIKE ? OR description LIKE ?)
                ORDER BY priority DESC, created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$status, $type, $search, $search, $search]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // ADS, SETTINGS & PLANS
    // =========================================================================

    public function softDeleteAd($id) {
        return $this->db->prepare("UPDATE ads SET is_deleted = 1, is_active = 0 WHERE id = ?")->execute([$id]);
    }

    public function toggleAdStatus($id) {
        return $this->db->prepare("UPDATE ads SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    }

    public function saveSettings($data) {
        $this->db->beginTransaction();
        try {
            foreach ($data as $key => $value) {
                if ($key === 'id') continue;
                $stmt = $this->db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) 
                                           ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function managePlans($data) {
        $action = $data['action'] ?? 'list';
        if ($action === 'save') {
            if (isset($data['id']) && $data['id'] > 0) {
                $sql = "UPDATE plans SET name=?, type=?, price=?, duration_days=?, description=? WHERE id=?";
                return $this->db->prepare($sql)->execute([$data['name'], $data['type'], $data['price'], $data['duration_days'], $data['description'], $data['id']]);
            } else {
                $sql = "INSERT INTO plans (name, type, price, duration_days, description, active) VALUES (?, ?, ?, ?, ?, 1)";
                return $this->db->prepare($sql)->execute([$data['name'], $data['type'], $data['price'], $data['duration_days'], $data['description']]);
            }
        }
        return $this->db->query("SELECT * FROM plans ORDER BY price ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
}