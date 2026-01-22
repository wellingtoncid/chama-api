<?php
namespace App\Controllers;

use PDO;

class ListingsController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    // LISTAR MEUS ITENS (Painel do Vendedor)
    public function getMyListings($userId) {
        $stmt = $this->pdo->prepare("SELECT * FROM listings WHERE user_id = ? AND status != 'deleted' ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // LISTAR TODOS (Marketplace Público com Filtros)
    public function getAll($category = null, $featuredOnly = false) {
        $sql = "SELECT l.*, u.name as seller_name FROM listings l 
                JOIN users u ON l.user_id = u.id 
                WHERE l.status = 'active'";
        
        if ($category) $sql .= " AND l.category = :category";
        if ($featuredOnly) $sql .= " AND l.is_featured = 1";
        
        $sql .= " ORDER BY l.is_featured DESC, l.created_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        if ($category) $stmt->bindValue(':category', $category);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // CRIAR COM REGRA DE NEGÓCIO
    public function create($data, $files) {
        // Exemplo de Regra: Verificar se o usuário já tem anúncios ativos
        // if ($this->countActive($data['user_id']) >= 3) return ['error' => 'Limite atingido'];

        $imagePath = $this->handleUpload($files);
        
        $sql = "INSERT INTO listings (user_id, category, title, description, price, item_condition, location_city, location_state, main_image) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->pdo->prepare($sql);
        $success = $stmt->execute([
            $data['user_id'], $data['category'], $data['title'], 
            $data['description'], $data['price'], $data['item_condition'], 
            $data['location_city'], $data['location_state'], $imagePath
        ]);

        return ['success' => $success, 'id' => $this->pdo->lastInsertId()];
    }

    // UPGRADE PARA DESTAQUE (Integração com Financeiro)
    public function setFeatured($listingId, $days = 7) {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+$days days"));
        $stmt = $this->pdo->prepare("UPDATE listings SET is_featured = 1, featured_expires_at = ? WHERE id = ?");
        return $stmt->execute([$expiresAt, $listingId]);
    }

    private function handleUpload($files) {
        if (!isset($files['image'])) return null;
        $path = "uploads/listings/";
        if (!file_exists($path)) mkdir($path, 0777, true);
        $name = time() . "_" . $files['image']['name'];
        move_uploaded_file($files['image']['tmp_name'], $path . $name);
        return $path . $name;
    }
}