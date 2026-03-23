<?php
namespace App\Repositories;

use PDO;

class QuoteRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO quotes (
                shipper_id, type, title, origin_city, dest_city,
                commodity_type, requires_insurance, weight, cargo_value,
                volume, period_days, pickup_date, description, status
            ) VALUES (
                :shipper_id, :type, :title, :origin_city, :dest_city,
                :commodity_type, :requires_insurance, :weight, :cargo_value,
                :volume, :period_days, :pickup_date, :description, 'open'
            )
        ");

        $stmt->execute([
            ':shipper_id' => $data['shipper_id'],
            ':type' => $data['type'],
            ':title' => $data['title'],
            ':origin_city' => $data['origin_city'] ?? null,
            ':dest_city' => $data['dest_city'] ?? null,
            ':commodity_type' => $data['commodity_type'] ?? null,
            ':requires_insurance' => $data['requires_insurance'] ?? 1,
            ':weight' => $data['weight'] ?? null,
            ':cargo_value' => $data['cargo_value'] ?? null,
            ':volume' => $data['volume'] ?? null,
            ':period_days' => $data['period_days'] ?? null,
            ':pickup_date' => $data['pickup_date'] ?? null,
            ':description' => $data['description'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool {
        $fields = [];
        $params = [':id' => $id];

        $allowedFields = ['title', 'origin_city', 'dest_city', 'commodity_type', 
                         'requires_insurance', 'weight', 'cargo_value', 'volume', 
                         'period_days', 'pickup_date', 'description', 'status'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE quotes SET " . implode(', ', $fields) . " WHERE id = :id");
        return $stmt->execute($params);
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM quotes WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT q.*, u.name as shipper_name, u.email as shipper_email
            FROM quotes q
            LEFT JOIN users u ON q.shipper_id = u.id
            WHERE q.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function getByShipper(int $shipperId, string $status = null): array {
        $sql = "SELECT * FROM quotes WHERE shipper_id = :shipper_id";
        $params = [':shipper_id' => $shipperId];

        if ($status) {
            $sql .= " AND status = :status";
            $params[':status'] = $status;
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getOpenQuotes(int $excludeShipperId = null): array {
        $sql = "SELECT q.*, u.name as shipper_name 
                FROM quotes q
                LEFT JOIN users u ON q.shipper_id = u.id
                WHERE q.status = 'open'";

        $params = [];
        if ($excludeShipperId) {
            $sql .= " AND q.shipper_id != :exclude_shipper_id";
            $params[':exclude_shipper_id'] = $excludeShipperId;
        }

        $sql .= " ORDER BY q.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addResponse(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO quote_responses (
                quote_id, company_id, price, delivery_time, 
                conditions, notes, status
            ) VALUES (
                :quote_id, :company_id, :price, :delivery_time,
                :conditions, :notes, 'pending'
            )
        ");

        $stmt->execute([
            ':quote_id' => $data['quote_id'],
            ':company_id' => $data['company_id'],
            ':price' => $data['price'],
            ':delivery_time' => $data['delivery_time'] ?? null,
            ':conditions' => $data['conditions'] ?? null,
            ':notes' => $data['notes'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function getResponsesByQuote(int $quoteId): array {
        $stmt = $this->db->prepare("
            SELECT qr.*, u.name as company_name, u.slug as company_slug
            FROM quote_responses qr
            LEFT JOIN users u ON qr.company_id = u.id
            WHERE qr.quote_id = :quote_id
            ORDER BY qr.created_at DESC
        ");
        $stmt->execute([':quote_id' => $quoteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getResponsesByCompany(int $companyId): array {
        $stmt = $this->db->prepare("
            SELECT qr.*, q.title as quote_title, q.type as quote_type
            FROM quote_responses qr
            LEFT JOIN quotes q ON qr.quote_id = q.id
            WHERE qr.company_id = :company_id
            ORDER BY qr.created_at DESC
        ");
        $stmt->execute([':company_id' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function acceptResponse(int $responseId): bool {
        $stmt = $this->db->prepare("SELECT quote_id FROM quote_responses WHERE id = :id");
        $stmt->execute([':id' => $responseId]);
        $response = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$response) {
            return false;
        }

        $quoteId = $response['quote_id'];

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("UPDATE quotes SET winner_bid_id = :response_id, status = 'closed' WHERE id = :quote_id");
            $stmt->execute([':response_id' => $responseId, ':quote_id' => $quoteId]);

            $stmt = $this->db->prepare("UPDATE quote_responses SET status = 'accepted' WHERE id = :id");
            $stmt->execute([':id' => $responseId]);

            $stmt = $this->db->prepare("UPDATE quote_responses SET status = 'rejected' WHERE quote_id = :quote_id AND id != :response_id");
            $stmt->execute([':quote_id' => $quoteId, ':response_id' => $responseId]);

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function hasUserResponded(int $quoteId, int $companyId): bool {
        $stmt = $this->db->prepare("
            SELECT id FROM quote_responses 
            WHERE quote_id = :quote_id AND company_id = :company_id
        ");
        $stmt->execute([':quote_id' => $quoteId, ':company_id' => $companyId]);
        return (bool)$stmt->fetch();
    }

    public function countByShipper(int $shipperId): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM quotes WHERE shipper_id = :shipper_id");
        $stmt->execute([':shipper_id' => $shipperId]);
        return (int)$stmt->fetch()['total'];
    }
}
