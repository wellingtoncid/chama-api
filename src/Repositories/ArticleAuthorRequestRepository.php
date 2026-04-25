<?php
namespace App\Repositories;

use PDO;

class ArticleAuthorRequestRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Check if user has a pending request
     */
    public function getPendingByUser($userId) {
        $stmt = $this->db->prepare("
            SELECT * FROM article_author_requests 
            WHERE user_id = :user_id AND status = 'pending'
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Check if user is already an approved author
     */
    public function isApprovedAuthor($userId) {
        $stmt = $this->db->prepare("
            SELECT id FROM article_author_requests 
            WHERE user_id = :user_id AND status = 'approved'
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Create author request
     */
    public function create($userId, $referencesLinks = null) {
        // Check if there's already a request
        $existing = $this->getPendingByUser($userId);
        if ($existing) {
            return false;
        }

        $stmt = $this->db->prepare("
            INSERT INTO article_author_requests (user_id, references_links, requested_at)
            VALUES (:user_id, :references_links, NOW())
        ");
        
        $stmt->execute([
            ':user_id' => $userId,
            ':references_links' => $referencesLinks
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Get request by ID
     */
    public function findById($id) {
        $stmt = $this->db->prepare("
            SELECT r.*, u.name as user_name, u.email as user_email, up.avatar_url as user_avatar
            FROM article_author_requests r
            LEFT JOIN users u ON r.user_id = u.id
            LEFT JOIN user_profiles up ON r.user_id = up.user_id
            WHERE r.id = :id
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get all requests for admin
     */
    public function getAll($filters = []) {
        $where = "1=1";
        $params = [];

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $where .= " AND r.status = :status";
            $params[':status'] = $filters['status'];
        }

        $orderBy = "r.requested_at DESC";

        $limit = $filters['limit'] ?? 50;
        $offset = $filters['offset'] ?? 0;

        $stmt = $this->db->prepare("
            SELECT r.*, u.name as user_name, u.email as user_email, up.avatar_url as user_avatar
            FROM article_author_requests r
            LEFT JOIN users u ON r.user_id = u.id
            LEFT JOIN user_profiles up ON r.user_id = up.user_id
            WHERE {$where}
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
        ");
        
        $stmt->execute(array_merge($params, [':limit' => $limit, ':offset' => $offset]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get pending requests
     */
    public function getPending($limit = 20, $offset = 0) {
        $stmt = $this->db->prepare("
            SELECT r.*, u.name as user_name, u.email as user_email, up.avatar_url as user_avatar
            FROM article_author_requests r
            LEFT JOIN users u ON r.user_id = u.id
            LEFT JOIN user_profiles up ON r.user_id = up.user_id
            WHERE r.status = 'pending'
            ORDER BY r.requested_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->execute([':limit' => $limit, ':offset' => $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count requests by status
     */
    public function count($status = null) {
        $where = "1=1";
        $params = [];

        if ($status) {
            $where .= " AND status = :status";
            $params[':status'] = $status;
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM article_author_requests WHERE {$where}");
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    /**
     * Approve request
     */
    public function approve($id, $reviewerId) {
        $stmt = $this->db->prepare("
            UPDATE article_author_requests 
            SET status = 'approved', reviewed_at = NOW(), reviewed_by = :reviewed_by
            WHERE id = :id
        ");
        return $stmt->execute([':id' => $id, ':reviewed_by' => $reviewerId]);
    }

    /**
     * Reject request
     */
    public function reject($id, $reviewerId, $reason) {
        $stmt = $this->db->prepare("
            UPDATE article_author_requests 
            SET status = 'rejected', rejection_reason = :reason, reviewed_at = NOW(), reviewed_by = :reviewed_by
            WHERE id = :id
        ");
        return $stmt->execute([':id' => $id, ':reviewed_by' => $reviewerId, ':reason' => $reason]);
    }

    /**
     * Delete request (soft delete)
     */
    public function delete($id) {
        $stmt = $this->db->prepare("UPDATE article_author_requests SET deleted_at = NOW() WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}