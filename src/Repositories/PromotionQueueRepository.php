<?php

namespace App\Repositories;

use PDO;

class PromotionQueueRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO promotion_queue (user_id, reference_type, reference_id, amount_paid, status)
                VALUES (:user_id, :reference_type, :reference_id, :amount_paid, :status)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $data['user_id'],
            ':reference_type' => $data['reference_type'],
            ':reference_id' => $data['reference_id'],
            ':amount_paid' => $data['amount_paid'] ?? 0,
            ':status' => $data['status'] ?? 'pending_payment',
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM promotion_queue WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByUser(int $userId, array $filters = []): array
    {
        $sql = "SELECT pq.*, 
                       CASE 
                           WHEN pq.reference_type = 'freight' THEN f.product 
                           WHEN pq.reference_type = 'listing' THEN l.title 
                       END as reference_title
                FROM promotion_queue pq
                LEFT JOIN freights f ON pq.reference_type = 'freight' AND pq.reference_id = f.id AND f.is_deleted = 0
                LEFT JOIN listings l ON pq.reference_type = 'listing' AND pq.reference_id = l.id
                WHERE pq.user_id = :user_id";
        $params = [':user_id' => $userId];

        if (!empty($filters['status'])) {
            $sql .= " AND pq.status = :status";
            $params[':status'] = $filters['status'];
        }

        $sql .= " ORDER BY pq.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findAll(array $filters = []): array
    {
        $sql = "SELECT pq.*, u.name as user_name, u.email as user_email,
                       CASE 
                           WHEN pq.reference_type = 'freight' THEN f.product 
                           WHEN pq.reference_type = 'listing' THEN l.title 
                       END as reference_title,
                       CASE 
                           WHEN pq.reference_type = 'freight' THEN f.slug 
                           WHEN pq.reference_type = 'listing' THEN l.slug 
                       END as reference_slug
                FROM promotion_queue pq
                JOIN users u ON pq.user_id = u.id
                LEFT JOIN freights f ON pq.reference_type = 'freight' AND pq.reference_id = f.id AND f.is_deleted = 0
                LEFT JOIN listings l ON pq.reference_type = 'listing' AND pq.reference_id = l.id
                WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND pq.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND pq.user_id = :user_id";
            $params[':user_id'] = $filters['user_id'];
        }

        $sql .= " ORDER BY pq.created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT :limit";
            $params[':limit'] = (int) $filters['limit'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateStatus(int $id, string $status, ?array $extra = []): bool
    {
        $allowed = ['pending_payment', 'pending_approval', 'approved', 'sent', 'failed', 'rejected'];
        if (!in_array($status, $allowed)) {
            return false;
        }

        $sets = ['status = :status'];
        $params = [':status' => $status, ':id' => $id];

        if ($status === 'approved' && !empty($extra['approved_by'])) {
            $sets[] = 'approved_by = :approved_by';
            $sets[] = 'approved_at = NOW()';
            $params[':approved_by'] = $extra['approved_by'];
        }

        if ($status === 'rejected' && !empty($extra['rejection_reason'])) {
            $sets[] = 'rejection_reason = :rejection_reason';
            $params[':rejection_reason'] = $extra['rejection_reason'];
        }

        if ($status === 'sent' && isset($extra['groups_sent'])) {
            $sets[] = 'groups_sent = :groups_sent';
            $sets[] = 'total_groups = :total_groups';
            $params[':groups_sent'] = (int) $extra['groups_sent'];
            $params[':total_groups'] = (int) ($extra['total_groups'] ?? 0);
        }

        if ($status === 'failed' && !empty($extra['error_message'])) {
            $sets[] = 'error_message = :error_message';
            $params[':error_message'] = $extra['error_message'];
        }

        $sql = "UPDATE promotion_queue SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function getStats(): array
    {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending_approval' THEN 1 ELSE 0 END) as pending_approval,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    COALESCE(SUM(amount_paid), 0) as total_revenue
                FROM promotion_queue";
        $stmt = $this->db->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function countByUserAndRef(int $userId, string $referenceType, int $referenceId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM promotion_queue 
                                     WHERE user_id = :user_id AND reference_type = :ref_type AND reference_id = :ref_id
                                     AND status NOT IN ('rejected', 'failed')");
        $stmt->execute([':user_id' => $userId, ':ref_type' => $referenceType, ':ref_id' => $referenceId]);
        return (int) $stmt->fetchColumn();
    }

    public function getActiveGroupsWithOpenwa(): array
    {
        $stmt = $this->db->prepare("SELECT id, openwa_group_id, region_name, member_count 
                                     FROM whatsapp_groups 
                                     WHERE status = 'active' AND is_deleted = 0 AND openwa_group_id IS NOT NULL
                                     ORDER BY member_count DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateGroupOpenwaId(int $groupId, string $openwaGroupId): bool
    {
        $stmt = $this->db->prepare("UPDATE whatsapp_groups SET openwa_group_id = :openwa_id WHERE id = :id");
        return $stmt->execute([':openwa_id' => $openwaGroupId, ':id' => $groupId]);
    }
}
