<?php

namespace App\Repositories;

use PDO;
use Exception;

class AdminRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

   // --- AUDITORIA & LOGS ---
    public function saveLog($uId, $uName, $type, $desc, $targetId, $targetType, $old = null, $new = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // Converta arrays para string JSON se necessário
        $oldStr = $old ? json_encode($old) : null;
        $newStr = $new ? json_encode($new) : null;

        $sql = "INSERT INTO logs_auditoria 
            (user_id, user_name, action_type, description, target_id, target_type, ip_address, user_agent, old_values, new_values) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        return $this->db->prepare($sql)->execute([
            $uId, $uName, $type, $desc, $targetId, $targetType, $ip, $agent, $oldStr, $newStr
        ]);
    }

     /**
     * Busca os logs mais recentes de auditoria para o feed do dashboard    
     */
    public function getRecentActivities() {
        $sql = "SELECT 
                    id,
                    user_name as user, 
                    description as action, 
                    created_at as time, 
                    target_type as type,
                    ip_address,
                    user_agent
                FROM logs_auditoria 
                ORDER BY created_at DESC 
                LIMIT 10";
                
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    // --- DASHBOARD STATS ---
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

    // --- GESTÃO DE FRETES ---
    public function getAllFreightsForAdmin() {
        $sql = "SELECT 
                    f.id, 
                    f.origin_city, 
                    f.origin_state,
                    f.dest_city,      
                    f.dest_state,
                    f.product, 
                    f.weight,         
                    f.price,         
                    f.vehicle_type, 
                    f.body_type,     
                    f.description,        
                    f.is_featured, 
                    f.requested_featured,
                    f.user_id,
                    f.status,
                    f.payment_status,
                    COALESCE(c.name_fantasy, u.name) as company_name
                FROM freights f
                LEFT JOIN users u ON f.user_id = u.id
                LEFT JOIN companies c ON f.user_id = c.owner_id
                WHERE f.deleted_at IS NULL
                AND f.status != 'DELETED'
                ORDER BY f.created_at DESC";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("Erro AdminRepository: " . $e->getMessage());
            return [];
        }
    }

    public function toggleFeatured($id, $featured) {
        $sql = "UPDATE freights SET is_featured = ?, requested_featured = '0' WHERE id = ?";
        return $this->db->prepare($sql)->execute([$featured, $id]);
    }
    
    public function getAuditLogs($limit = 50) {
        $sql = "SELECT * FROM logs_auditoria ORDER BY created_at DESC LIMIT :limit";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("Erro ao buscar logs: " . $e->getMessage());
            return [];
        }
    }

    // DASHBOARD & ESTATÍSTICAS AVANÇADAS
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

    // USUÁRIOS (Incluso Soft Delete e Filtros)

    public function listUsersByRole($role = '%', $search = '%') {
        $sql = "SELECT 
                    u.id, 
                    u.email, 
                    u.whatsapp, 
                    u.role, 
                    u.is_verified, 
                    u.status, 
                    u.created_at,
                    -- Se houver empresa vinculada, usa o Nome Fantasia, senão usa o nome do usuário
                    COALESCE(c.name_fantasy, u.name) as name,
                    c.name_fantasy as company_name
                FROM users u
                LEFT JOIN companies c ON u.company_id = c.id
                WHERE u.role LIKE ? 
                AND (u.name LIKE ? OR u.email LIKE ? OR c.name_fantasy LIKE ?) 
                AND u.deleted_at IS NULL 
                ORDER BY u.id DESC";

        $stmt = $this->db->prepare($sql);
        
        // Adicionamos o termo de busca também para o nome da empresa
        $searchTerm = "%$search%";
        $stmt->execute([$role, $searchTerm, $searchTerm, $searchTerm]);
        
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

    // FRETES & MATCHING

    public function listAllFreights() {
        return $this->db->query("
            SELECT f.*, c.name_fantasy as company_name 
            FROM freights f 
            LEFT JOIN users u ON f.user_id = u.id 
            LEFT JOIN companies c ON u.company_id = c.id
            ORDER BY f.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        //return Response::json(["success" => true, "data" => $freights]);
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

    // CRM / PORTAL REQUESTS (Leads)

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

    // ADS, SETTINGS & PLANS

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
    // SUPORTE & HELP DESK (Tickets)

    /**
     * Lista chamados com filtros de status
     */
    public function getTickets($status = '%') {
        $sql = "SELECT t.*, u.name as user_name, u.role as user_role 
                FROM support_tickets t
                JOIN users u ON t.user_id = u.id
                WHERE t.status LIKE ?
                ORDER BY t.priority DESC, t.last_update DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$status]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTicketById($id) {
        $stmt = $this->db->prepare("SELECT * FROM support_tickets WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Adiciona uma mensagem ao ticket e atualiza o timestamp de atividade
     */
    public function addTicketMessage($ticketId, $senderId, $message, $isAdmin) {
        try {
            $this->db->beginTransaction();

            // 1. Insere a mensagem
            $sqlMsg = "INSERT INTO support_messages (ticket_id, sender_id, message, is_admin_reply) VALUES (?, ?, ?, ?)";
            $this->db->prepare($sqlMsg)->execute([$ticketId, $senderId, $message, $isAdmin ? 1 : 0]);

            // 2. Atualiza o status do ticket (Se admin responde, vira IN_PROGRESS ou mantem status)
            $newStatus = $isAdmin ? 'IN_PROGRESS' : 'OPEN';
            $sqlTicket = "UPDATE support_tickets SET status = ?, last_update = NOW() WHERE id = ?";
            $this->db->prepare($sqlTicket)->execute([$newStatus, $ticketId]);

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    // NOTAS INTERNAS (CRM DE AUDITORIA)

    /**
     * Salva uma nota sobre o usuário que apenas Admin/Manager podem ver
     */
    public function saveInternalNote($targetUserId, $adminId, $note) {
        $sql = "INSERT INTO user_internal_notes (user_id, admin_id, note) VALUES (?, ?, ?)";
        return $this->db->prepare($sql)->execute([$targetUserId, $adminId, $note]);
    }

    public function getUserNotes($userId) {
        $sql = "SELECT n.*, u.name as admin_name 
                FROM user_internal_notes n
                JOIN users u ON n.admin_id = u.id
                WHERE n.user_id = ?
                ORDER BY n.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
    * Busca fretes com status 'PENDING' para aprovação no dashboard
    */
    public function getPendingFreights() {
       $sql = "SELECT 
                    f.id, 
                    f.origin_city, 
                    f.origin_state,
                    f.dest_city,      
                    f.dest_state,     
                    f.product,
                    f.weight,         
                    f.price,          
                    f.created_at,
                    f.user_id,        
                    u.name as company_name
                FROM freights f
                JOIN users u ON f.user_id = u.id
                WHERE f.status = 'PENDING'
                WHERE f.deleted_at IS NULL
                ORDER BY f.created_at DESC
                LIMIT 10";
                
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("Erro ao buscar fretes pendentes: " . $e->getMessage());
            return [];
        }
    }

    // --- QUERIES DE DOCUMENTOS ---

    public function getPendingDocuments() {
        $sql = "SELECT d.*, u.name as user_name, u.email as user_email 
                FROM user_documents d
                JOIN users u ON d.entity_id = u.id
                WHERE d.status = 'PENDING'
                ORDER BY d.created_at ASC";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDocumentById($id) {
        $sql = "SELECT * FROM user_documents WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateDocumentStatus($id, $status, $reason = '') {
        $sql = "UPDATE user_documents SET status = ?, rejection_reason = ?, reviewed_at = NOW() WHERE id = ?";
        return $this->db->prepare($sql)->execute([$status, $reason, $id]);
    }
}