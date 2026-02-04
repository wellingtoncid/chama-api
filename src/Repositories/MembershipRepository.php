<?php
namespace App\Repositories;

use PDO;

class MembershipRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getUserSubscription(int $userId) {
        $stmt = $this->db->prepare("
            SELECT plan_type, is_subscriber, subscription_expires_at 
            FROM users WHERE id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getActiveAds(int $userId) {
        $stmt = $this->db->prepare("
            SELECT id, title, expires_at, status 
            FROM ads 
            WHERE user_id = ? AND status = 'active' AND expires_at > NOW()
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTransactions(int $userId) {
        $stmt = $this->db->prepare("
            SELECT t.*, p.name as plan_name 
            FROM transactions t 
            LEFT JOIN plans p ON t.plan_id = p.id 
            WHERE t.user_id = ? 
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}