<?php
namespace App\Controllers;

use PDO;
use Exception;

class ListingsController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * VITRINE PÚBLICA
     * Lista todos os itens ativos com filtros e prioridade para impulsionados
     */
    public function getAll($params = []) {
        $category = $params['category'] ?? null;
        $search = $params['search'] ?? null;
        $minPrice = $params['min_price'] ?? null;
        $maxPrice = $params['max_price'] ?? null;
        
        $sql = "SELECT l.*, u.name as seller_name, u.whatsapp as seller_phone 
                FROM listings l 
                JOIN users u ON l.user_id = u.id 
                WHERE l.status = 'active'";
        
        $binds = [];
        if ($category && $category !== 'todos') {
            $sql .= " AND l.category = :category";
            $binds[':category'] = $category;
        }
        if ($search) {
            $sql .= " AND (l.title LIKE :search OR l.description LIKE :search)";
            $binds[':search'] = "%$search%";
        }
        if ($minPrice) {
            $sql .= " AND l.price >= :min";
            $binds[':min'] = $minPrice;
        }
        if ($maxPrice) {
            $sql .= " AND l.price <= :max";
            $binds[':max'] = $maxPrice;
        }
        
        // Itens 'is_featured' aparecem primeiro
        $sql .= " ORDER BY l.is_featured DESC, l.created_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        foreach ($binds as $key => $val) $stmt->bindValue($key, $val);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * PAINEL DO VENDEDOR
     * Lista itens do usuário logado trazendo métricas reais das tabelas de logs
     */
    public function getMyListings($userId) {
        $sql = "SELECT l.*, 
                (SELECT COUNT(*) FROM view_logs WHERE target_id = l.id AND target_type = 'LISTING') as real_views,
                (SELECT COUNT(*) FROM click_logs WHERE target_id = l.id AND target_type = 'LISTING') as real_clicks
                FROM listings l 
                WHERE l.user_id = ? AND l.status != 'deleted' 
                ORDER BY l.created_at DESC";
                
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * TRACKING INTEGRADO (Analytics)
     * Usa as tabelas view_logs e click_logs que você já tem no SQL
     */
    public function logActivity($listingId, $userId, $type = 'view') {
        $table = ($type === 'view') ? 'view_logs' : 'click_logs';
        
        // 1. Insere log detalhado na tabela de logs (histórico)
        $stmt = $this->pdo->prepare("INSERT INTO $table (user_id, target_id, target_type) VALUES (?, ?, 'LISTING')");
        $stmt->execute([$userId, $listingId]);

        // 2. Atualiza o contador rápido na tabela listings para performance do front-end
        $column = ($type === 'view') ? 'views_count' : 'clicks_count';
        $update = $this->pdo->prepare("UPDATE listings SET $column = $column + 1 WHERE id = ?");
        return $update->execute([$listingId]);
    }

    /**
     * CRIAÇÃO COM REGRA FREEMIUM E SLUG
     */
    public function create($data, $files) {
        $userId = $data['user_id'];

        // Validação de Limite por Plano (conforme sua tabela 'users')
        $stmt = $this->pdo->prepare("SELECT plan_type FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $plan = $stmt->fetchColumn();

        if ($plan === 'free' || !$plan) {
            $count = $this->pdo->prepare("SELECT COUNT(*) FROM listings WHERE user_id = ? AND status = 'active'");
            $count->execute([$userId]);
            if ($count->fetchColumn() >= 3) {
                return ["success" => false, "error" => "Limite de 3 anúncios atingido. Faça um upgrade!"];
            }
        }

        $imagePath = $this->handleUpload($files);
        $slug = $this->generateSlug($data['title']);
        
        $sql = "INSERT INTO listings (user_id, category, title, slug, description, price, item_condition, location_city, location_state, main_image, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
        
        $stmt = $this->pdo->prepare($sql);
        $success = $stmt->execute([
            $userId, $data['category'], $data['title'], $slug, 
            $data['description'] ?? '', $data['price'], $data['item_condition'], 
            $data['location_city'], $data['location_state'], $imagePath
        ]);

        return ['success' => $success, 'id' => $this->pdo->lastInsertId()];
    }

    /**
     * ATUALIZAÇÃO (EDIÇÃO)
     */
    public function update($id, $userId, $data, $files) {
        $stmt = $this->pdo->prepare("SELECT main_image FROM listings WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        $current = $stmt->fetch();
        
        if (!$current) return ["success" => false, "error" => "Anúncio não encontrado ou sem permissão."];

        $imagePath = $current['main_image'];
        if (isset($files['image']) && $files['image']['size'] > 0) {
            $imagePath = $this->handleUpload($files);
        }

        $sql = "UPDATE listings SET title = ?, category = ?, price = ?, description = ?, item_condition = ?, location_city = ?, location_state = ?, main_image = ? 
                WHERE id = ? AND user_id = ?";
        $stmt = $this->pdo->prepare($sql);
        return ['success' => $stmt->execute([
            $data['title'], $data['category'], $data['price'], $data['description'],
            $data['item_condition'], $data['location_city'], $data['location_state'],
            $imagePath, $id, $userId
        ])];
    }

    public function delete($id, $userId) {
        $stmt = $this->pdo->prepare("UPDATE listings SET status = 'deleted' WHERE id = ? AND user_id = ?");
        return ['success' => $stmt->execute([$id, $userId])];
    }

    public function setFeatured($listingId, $days = 7) {
        $until = date('Y-m-d H:i:s', strtotime("+$days days"));
        $stmt = $this->pdo->prepare("UPDATE listings SET is_featured = 1, featured_until = ? WHERE id = ?");
        return $stmt->execute([$until, $listingId]);
    }

    // --- MÉTODOS AUXILIARES ---

    private function generateSlug($text) {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = strtolower($text);
        return $text . '-' . rand(1000, 9999);
    }

    private function handleUpload($files) {
        if (!isset($files['image']) || $files['image']['error'] !== UPLOAD_ERR_OK) return null;
        $path = "uploads/listings/";
        if (!file_exists($path)) mkdir($path, 0777, true);
        $fileName = time() . "_" . uniqid() . "." . pathinfo($files['image']['name'], PATHINFO_EXTENSION);
        move_uploaded_file($files['image']['tmp_name'], $path . $fileName);
        return $path . $fileName;
    }
}