<?php

namespace App\Repositories;

use PDO;

class CouponRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findAll(bool $includeInactive = true): array
    {
        $sql = 'SELECT c.*, u.name as created_by_name
                FROM coupons c
                LEFT JOIN users u ON u.id = c.created_by';
        if (!$includeInactive) {
            $sql .= ' WHERE c.is_active = 1';
        }
        $sql .= ' ORDER BY c.created_at DESC';
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM coupons WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM coupons WHERE code = :code');
        $stmt->execute([':code' => $code]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO coupons (code, type, value, max_uses, max_uses_per_user, expires_at, is_active, created_by, created_at)
            VALUES (:code, :type, :value, :max_uses, :max_uses_per_user, :expires_at, :is_active, :created_by, NOW())
        ');
        $stmt->execute([
            ':code' => $data['code'],
            ':type' => $data['type'] ?? 'fixed',
            ':value' => $data['value'],
            ':max_uses' => $data['max_uses'] ?? null,
            ':max_uses_per_user' => $data['max_uses_per_user'] ?? 1,
            ':expires_at' => $data['expires_at'] ?? null,
            ':is_active' => $data['is_active'] ?? 1,
            ':created_by' => $data['created_by'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = [':id' => $id];
        $allowed = ['code', 'value', 'max_uses', 'max_uses_per_user', 'expires_at', 'is_active'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }
        if (empty($fields)) return;
        $sql = 'UPDATE coupons SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $this->db->prepare($sql)->execute($params);
    }

    public function incrementUses(int $id): void
    {
        $this->db->prepare('UPDATE coupons SET current_uses = current_uses + 1 WHERE id = :id')
            ->execute([':id' => $id]);
    }

    public function recordUse(int $couponId, int $userId, int $transactionId, float $amount): void
    {
        $this->db->prepare('
            INSERT INTO coupon_uses (coupon_id, user_id, transaction_id, amount, used_at)
            VALUES (:coupon_id, :user_id, :transaction_id, :amount, NOW())
        ')->execute([
            ':coupon_id' => $couponId,
            ':user_id' => $userId,
            ':transaction_id' => $transactionId,
            ':amount' => $amount,
        ]);
    }

    public function countUserUses(int $couponId, int $userId): int
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as total FROM coupon_uses
            WHERE coupon_id = :coupon_id AND user_id = :user_id
        ');
        $stmt->execute([':coupon_id' => $couponId, ':user_id' => $userId]);
        return (int)$stmt->fetch()['total'];
    }

    public function getUses(int $couponId): array
    {
        $stmt = $this->db->prepare('
            SELECT cu.*, u.name as user_name, u.email as user_email
            FROM coupon_uses cu
            LEFT JOIN users u ON u.id = cu.user_id
            WHERE cu.coupon_id = :coupon_id
            ORDER BY cu.used_at DESC
        ');
        $stmt->execute([':coupon_id' => $couponId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function generateCode(int $length = 8): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while ($this->findByCode($code));
        return $code;
    }
}
