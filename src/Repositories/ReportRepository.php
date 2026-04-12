<?php
namespace App\Repositories;

use PDO;

class ReportRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function create($data) {
        $sql = "INSERT INTO reports (reporter_id, target_user_id, target_type, target_id, reason, description, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([
            $data['reporter_id'],
            $data['target_user_id'] ?? null,
            $data['target_type'],
            $data['target_id'],
            $data['reason'],
            $data['description'] ?? null
        ]);
        
        return $result ? $this->db->lastInsertId() : false;
    }

    public function findById($id) {
        $sql = "SELECT r.*, 
                       reporter.name as reporter_name,
                       target.name as target_user_name,
                       assigned.name as assigned_name
                FROM reports r
                LEFT JOIN users reporter ON r.reporter_id = reporter.id
                LEFT JOIN users target ON r.target_user_id = target.id
                LEFT JOIN users assigned ON r.assigned_to = assigned.id
                WHERE r.id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function existsDuplicate($reporterId, $targetType, $targetId) {
        $sql = "SELECT id FROM reports 
                WHERE reporter_id = ? AND target_type = ? AND target_id = ? 
                AND status IN ('pending', 'reviewing') LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$reporterId, $targetType, $targetId]);
        return (bool)$stmt->fetch();
    }

    public function getAll($options = []) {
        $limit = $options['limit'] ?? 20;
        $offset = $options['offset'] ?? 0;
        $status = $options['status'] ?? null;
        
        $where = "WHERE 1=1";
        $params = [];
        
        if ($status) {
            $where .= " AND r.status = ?";
            $params[] = $status;
        }
        
        $sql = "SELECT r.*, 
                       reporter.name as reporter_name,
                       reporter.role as reporter_role,
                       target.name as target_user_name,
                       target.role as target_user_role,
                       assigned.name as assigned_name
                FROM reports r
                LEFT JOIN users reporter ON r.reporter_id = reporter.id
                LEFT JOIN users target ON r.target_user_id = target.id
                LEFT JOIN users assigned ON r.assigned_to = assigned.id
                {$where}
                ORDER BY r.created_at DESC
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByReporter($reporterId, $limit = 20, $offset = 0) {
        $sql = "SELECT r.*, 
                       target.name as target_user_name,
                       target.role as target_user_role
                FROM reports r
                LEFT JOIN users target ON r.target_user_id = target.id
                WHERE r.reporter_id = ?
                ORDER BY r.created_at DESC
                LIMIT ? OFFSET ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$reporterId, $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countAll($status = null) {
        $sql = "SELECT COUNT(*) as total FROM reports";
        if ($status) {
            $sql .= " WHERE status = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$status]);
        } else {
            $stmt = $this->db->query($sql);
        }
        return (int)($stmt->fetch()['total'] ?? 0);
    }

    public function countByStatus() {
        $sql = "SELECT status, COUNT(*) as count FROM reports GROUP BY status";
        $stmt = $this->db->query($sql);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $counts = [
            'pending' => 0,
            'reviewing' => 0,
            'resolved' => 0,
            'dismissed' => 0,
            'total' => 0
        ];
        
        foreach ($results as $row) {
            $counts[$row['status']] = (int)$row['count'];
            $counts['total'] += (int)$row['count'];
        }
        
        return $counts;
    }

    public function assign($reportId, $adminId) {
        $sql = "UPDATE reports SET assigned_to = ?, status = 'reviewing', updated_at = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$adminId, $reportId]);
    }

    public function resolve($reportId, $adminId, $notes) {
        $sql = "UPDATE reports SET status = 'resolved', assigned_to = ?, resolution_notes = ?, updated_at = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$adminId, $notes, $reportId]);
    }

    public function dismiss($reportId, $adminId, $notes) {
        $sql = "UPDATE reports SET status = 'dismissed', assigned_to = ?, resolution_notes = ?, updated_at = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$adminId, $notes, $reportId]);
    }

    public function updateStatus($reportId, $status) {
        $sql = "UPDATE reports SET status = ?, updated_at = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$status, $reportId]);
    }

    public function delete($reportId) {
        $sql = "DELETE FROM reports WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$reportId]);
    }
}
