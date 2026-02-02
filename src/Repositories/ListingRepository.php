<?php
namespace App\Repositories;

use PDO;

class ListingRepository {
    private $db;
    public function __construct($db) { $this->db = $db; }

    public function findActiveWithFilters($filters, $page = 1) {
        $limit = 12;
        $offset = ($page - 1) * $limit;
        
        $sql = "SELECT l.*, u.name as seller_name 
                FROM listings l 
                JOIN users u ON l.user_id = u.id 
                WHERE l.status = 'active'";
        
        $params = [];
        if (!empty($filters['category']) && $filters['category'] !== 'todos') {
            $sql .= " AND l.category = :cat";
            $params[':cat'] = $filters['category'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (l.title LIKE :search OR l.description LIKE :search)";
            $params[':search'] = "%{$filters['search']}%";
        }
        
        $sql .= " ORDER BY l.is_featured DESC, l.created_at DESC LIMIT $limit OFFSET $offset";
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca todas as imagens de um conjunto de anúncios de uma vez só
     * Melhora a performance drasticamente na listagem
     */
    public function getImagesForList(array $listingIds) {
        if (empty($listingIds)) return [];
        $placeholders = implode(',', array_fill(0, count($listingIds), '?'));
        $stmt = $this->db->prepare("SELECT listing_id, image_url FROM listing_images WHERE listing_id IN ($placeholders) ORDER BY sort_order ASC");
        $stmt->execute($listingIds);
        
        $images = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $images[$row['listing_id']][] = $row['image_url'];
        }
        return $images;
    }

    public function findById($id) {
        $stmt = $this->db->prepare("
            SELECT l.*, u.name as seller_name, u.whatsapp as seller_phone 
            FROM listings l 
            JOIN users u ON l.user_id = u.id 
            WHERE l.id = ? AND l.status != 'deleted'
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function save($data) {
        $sql = "INSERT INTO listings (user_id, title, slug, description, price, category, main_image, location_city, location_state, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
        $stmt = $this->db->prepare($sql);
        $success = $stmt->execute([
            $data['user_id'], $data['title'], $data['slug'], 
            $data['description'], $data['price'], $data['category'], 
            $data['main_image'], $data['location_city'] ?? null, $data['location_state'] ?? null
        ]);
        return $success ? $this->db->lastInsertId() : false;
    }

    public function addImage($listingId, $url, $order = 0) {
        $stmt = $this->db->prepare("INSERT INTO listing_images (listing_id, image_url, sort_order) VALUES (?, ?, ?)");
        return $stmt->execute([$listingId, $url, $order]);
    }

    public function getImages($listingId) {
        $stmt = $this->db->prepare("SELECT image_url FROM listing_images WHERE listing_id = ? ORDER BY sort_order ASC");
        $stmt->execute([$listingId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}