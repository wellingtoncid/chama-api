<?php
namespace App\Repositories;

use PDO;

class ReviewRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function create($data) {
        $sql = "INSERT INTO reviews (reviewer_id, target_id, freight_id, target_type, rating, comment, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $data['reviewer_id'],
            $data['target_id'],
            $data['freight_id'] ?? null,
            $data['target_type'] ?? 'USER',
            $data['rating'],
            $data['comment'] ?? '',
            $data['status'] ?? 'published'
        ]);
    }

    public function saveReply($reviewId, $replyText) {
        $sql = "UPDATE reviews SET reply_text = ?, replied_at = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$replyText, $reviewId]);
    }

    public function deleteReply($reviewId) {
        $sql = "UPDATE reviews SET reply_text = NULL, replied_at = NULL WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$reviewId]);
    }

    public function canReviewUser($reviewerId, $targetId) {
        $sql = "SELECT id FROM freights 
                WHERE ((requester_id = ? AND driver_id = ?) OR (requester_id = ? AND driver_id = ?))
                AND status = 'completed' LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$reviewerId, $targetId, $targetId, $reviewerId]);
        return (bool)$stmt->fetch();
    }

    public function hasAlreadyReviewed($reviewerId, $targetId, $freightId = null) {
        if ($freightId) {
            $stmt = $this->db->prepare("SELECT id FROM reviews WHERE reviewer_id = ? AND target_id = ? AND freight_id = ? LIMIT 1");
            $stmt->execute([$reviewerId, $targetId, $freightId]);
        } else {
            $stmt = $this->db->prepare("SELECT id FROM reviews WHERE reviewer_id = ? AND target_id = ? LIMIT 1");
            $stmt->execute([$reviewerId, $targetId]);
        }
        return (bool)$stmt->fetch();
    }

    public function hasReviewed($reviewerId, $targetId) {
        $stmt = $this->db->prepare("SELECT id FROM reviews WHERE reviewer_id = ? AND target_id = ? LIMIT 1");
        $stmt->execute([$reviewerId, $targetId]);
        return (bool)$stmt->fetch();
    }

    public function getByTarget($targetId, $options = []) {
        $limit = $options['limit'] ?? 10;
        $offset = $options['offset'] ?? 0;
        $status = $options['status'] ?? 'published';
        $months = $options['months'] ?? null; // null = todos, 3 = últimos 3 meses, etc.

        $where = "WHERE r.target_id = ? AND r.target_type = 'USER'";
        $params = [$targetId];

        if ($status) {
            $where .= " AND r.status = ?";
            $params[] = $status;
        }

        if ($months) {
            $where .= " AND r.created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)";
            $params[] = $months;
        }

        $sql = "SELECT r.*, 
                       u.name as reviewer_name, 
                       p.avatar_url as reviewer_avatar,
                       u.role as reviewer_role,
                       f.origin_city, f.origin_state, f.dest_city, f.dest_state, f.product
                FROM reviews r
                JOIN users u ON r.reviewer_id = u.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                LEFT JOIN freights f ON r.freight_id = f.id
                {$where}
                ORDER BY r.created_at DESC
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCountByTarget($targetId, $options = []) {
        $status = $options['status'] ?? 'published';
        $months = $options['months'] ?? null;

        $where = "WHERE target_id = ? AND target_type = 'USER'";
        $params = [$targetId];

        if ($status) {
            $where .= " AND status = ?";
            $params[] = $status;
        }

        if ($months) {
            $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)";
            $params[] = $months;
        }

        $sql = "SELECT COUNT(*) as total FROM reviews {$where}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)($stmt->fetch()['total'] ?? 0);
    }

    public function getDistribution($targetId, $months = null) {
        $where = "WHERE target_id = ? AND target_type = 'USER' AND status = 'published'";
        $params = [$targetId];

        if ($months) {
            $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)";
            $params[] = $months;
        }

        $sql = "SELECT rating, COUNT(*) as count 
                FROM reviews {$where}
                GROUP BY rating 
                ORDER BY rating DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($results as $row) {
            $distribution[$row['rating']] = (int)$row['count'];
        }
        return $distribution;
    }

    public function getRecentStats($targetId, $months = 3) {
        $sql = "SELECT COUNT(*) as total, AVG(rating) as avg_rating
                FROM reviews 
                WHERE target_id = ? AND target_type = 'USER' AND status = 'published'
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$targetId, $months]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function refreshReputation($userId) {
        $sql = "SELECT COUNT(*) as total, AVG(rating) as media 
                FROM reviews 
                WHERE target_id = ? AND target_type = 'USER' AND status = 'published'";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $total = $result['total'] ?? 0;
        $media = $result['media'] ?? 0.00;

        $updateSql = "UPDATE users SET rating_avg = ?, rating_count = ? WHERE id = ?";
        $updateStmt = $this->db->prepare($updateSql);
        
        return $updateStmt->execute([round($media, 2), $total, $userId]);
    }

    public function getPending($limit = 20, $offset = 0) {
        $sql = "SELECT r.*, 
                       u.name as reviewer_name, 
                       u.role as reviewer_role,
                       t.name as target_name,
                       t.role as target_role,
                       p.avatar_url as reviewer_avatar
                FROM reviews r
                JOIN users u ON r.reviewer_id = u.id
                JOIN users t ON r.target_id = t.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                WHERE r.status = 'pending'
                ORDER BY r.created_at DESC
                LIMIT ? OFFSET ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                       u.name as reviewer_name, 
                       u.role as reviewer_role,
                       t.name as target_name,
                       t.role as target_role,
                       p.avatar_url as reviewer_avatar
                FROM reviews r
                JOIN users u ON r.reviewer_id = u.id
                JOIN users t ON r.target_id = t.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                {$where}
                ORDER BY r.created_at DESC
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCountAll($options = []) {
        $status = $options['status'] ?? null;

        $where = "WHERE 1=1";
        $params = [];

        if ($status) {
            $where .= " AND status = ?";
            $params[] = $status;
        }

        $sql = "SELECT COUNT(*) as total FROM reviews {$where}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)($stmt->fetch()['total'] ?? 0);
    }

    public function approve($reviewId) {
        $stmt = $this->db->prepare("UPDATE reviews SET status = 'published' WHERE id = ?");
        $result = $stmt->execute([$reviewId]);
        
        if ($result) {
            $stmt = $this->db->prepare("SELECT target_id FROM reviews WHERE id = ?");
            $stmt->execute([$reviewId]);
            $review = $stmt->fetch();
            if ($review) {
                $this->refreshReputation($review['target_id']);
            }
        }
        
        return $result;
    }

    public function reject($reviewId, $reason = null) {
        $stmt = $this->db->prepare("UPDATE reviews SET status = 'rejected' WHERE id = ?");
        return $stmt->execute([$reviewId]);
    }

    public function findById($id) {
        $stmt = $this->db->prepare("SELECT * FROM reviews WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function delete($reviewId) {
        $review = $this->findById($reviewId);
        if (!$review) {
            return false;
        }
        
        $stmt = $this->db->prepare("DELETE FROM reviews WHERE id = ?");
        $result = $stmt->execute([$reviewId]);
        
        if ($result) {
            $this->refreshReputation($review['target_id']);
        }
        
        return $result;
    }

    public function block($reviewId) {
        $review = $this->findById($reviewId);
        if (!$review) {
            return false;
        }
        
        $stmt = $this->db->prepare("UPDATE reviews SET status = 'rejected' WHERE id = ?");
        $result = $stmt->execute([$reviewId]);
        
        if ($result) {
            $this->refreshReputation($review['target_id']);
        }
        
        return $result;
    }
}
