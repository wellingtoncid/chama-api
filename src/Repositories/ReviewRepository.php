<?php
namespace App\Repositories;

use PDO;

class ReviewRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function create($data) {
        // Adicionamos freight_id para rastreabilidade
        $sql = "INSERT INTO reviews (reviewer_id, target_id, freight_id, target_type, rating, comment, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $data['reviewer_id'],
            $data['target_id'],
            $data['freight_id'] ?? null, // Vínculo com o frete
            $data['target_type'],
            $data['rating'],
            $data['comment']
        ]);
    }

    /**
     * Verifica se existe um vínculo de serviço (Ex: Frete finalizado)
     * Isso impede que usuários "comprem" ou "fakeiem" avaliações sem trabalhar.
     */
    public function canReviewUser($reviewerId, $targetId) {
        // Exemplo: Verifica se existe um frete onde ambos estiveram envolvidos
        // Ajuste 'freights' para o nome da sua tabela de transações/serviços
        $sql = "SELECT id FROM freights 
                WHERE ((requester_id = ? AND driver_id = ?) OR (requester_id = ? AND driver_id = ?))
                AND status = 'completed' LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$reviewerId, $targetId, $targetId, $reviewerId]);
        return (bool)$stmt->fetch();
    }

    public function hasAlreadyReviewed($reviewerId, $targetId, $freightId) {
        $stmt = $this->db->prepare("SELECT id FROM reviews WHERE reviewer_id = ? AND target_id = ? AND freight_id = ? LIMIT 1");
        $stmt->execute([$reviewerId, $targetId, $freightId]);
        return (bool)$stmt->fetch();
    }

    public function getByTarget($targetId) {
        $sql = "SELECT r.*, u.name as reviewer_name, p.avatar_url 
                FROM reviews r
                JOIN users u ON r.reviewer_id = u.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                WHERE r.target_id = ? AND r.target_type = 'USER'
                ORDER BY r.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$targetId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Recalcula a média e o total de avaliações de um usuário
     * Sincroniza os campos rating_avg e rating_count da tabela 'users'
     */
    public function refreshReputation($userId) {
        // 1. Calcula a nova média e contagem direto da tabela de reviews
        $sql = "SELECT COUNT(*) as total, AVG(rating) as media 
                FROM reviews 
                WHERE target_id = ? AND target_type = 'USER'";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        $total = $result['total'] ?? 0;
        $media = $result['media'] ?? 0.00;

        // 2. Atualiza a tabela principal de usuários
        $updateSql = "UPDATE users SET rating_avg = ?, rating_count = ? WHERE id = ?";
        $updateStmt = $this->db->prepare($updateSql);
        
        return $updateStmt->execute([$media, $total, $userId]);
    }
}