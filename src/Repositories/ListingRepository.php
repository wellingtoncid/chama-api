<?php
namespace App\Repositories;

use PDO;
use App\Services\GeocodingService;

class ListingRepository {
    private $db;
    private $geoService;

    public function __construct($db) {
        $this->db = $db;
        $this->geoService = new GeocodingService($db);
    }

    public function findActiveWithFilters($filters, $page = 1) {
        $limit = 12;
        $offset = ($page - 1) * $limit;
        
        $sql = "SELECT l.*, u.name as seller_name 
                FROM listings l 
                JOIN users u ON l.user_id = u.id 
                WHERE l.status = 'active' 
                AND (l.expires_at IS NULL OR l.expires_at > NOW())
                AND EXISTS (
                    SELECT 1 FROM user_modules um 
                    WHERE um.user_id = l.user_id 
                    AND um.module_key = 'marketplace' 
                    AND um.status = 'active'
                )";
        
        $countSql = "SELECT COUNT(*) FROM listings l 
                      JOIN users u ON l.user_id = u.id 
                      WHERE l.status = 'active' 
                      AND (l.expires_at IS NULL OR l.expires_at > NOW())
                      AND EXISTS (
                          SELECT 1 FROM user_modules um 
                          WHERE um.user_id = l.user_id 
                          AND um.module_key = 'marketplace' 
                          AND um.status = 'active'
                      )";
        
        $params = [];
        
        // Filtro por categoria
        if (!empty($filters['category']) && $filters['category'] !== 'todos') {
            $sql .= " AND l.category = :cat";
            $countSql .= " AND l.category = :cat";
            $params[':cat'] = $filters['category'];
        }

        // Filtro de busca inteligente (Google-like)
        if (!empty($filters['search'])) {
            $searchTerm = trim($filters['search']);
            $words = preg_split('/\s+/', $searchTerm);
            
            $searchConditions = [];
            foreach ($words as $index => $word) {
                $word = trim($word);
                if (strlen($word) >= 2) {
                    $paramKey = ":search_word_{$index}";
                    $searchConditions[] = "(l.title LIKE {$paramKey} OR l.description LIKE {$paramKey} OR l.category LIKE {$paramKey})";
                    $params[$paramKey] = "%{$word}%";
                }
            }
            
            if (!empty($searchConditions)) {
                $condition = "(" . implode(" AND ", $searchConditions) . ")";
                $sql .= " AND $condition";
                $countSql .= " AND $condition";
            }
        }
        
        // Filtro por estado
        if (!empty($filters['state'])) {
            $sql .= " AND l.location_state = :state";
            $countSql .= " AND l.location_state = :state";
            $params[':state'] = strtoupper($filters['state']);
        }
        
        // Filtro por cidade
        if (!empty($filters['city'])) {
            $sql .= " AND l.location_city LIKE :city";
            $countSql .= " AND l.location_city LIKE :city";
            $params[':city'] = "%{$filters['city']}%";
        }

        // Filtro por condição
        if (!empty($filters['condition'])) {
            $sql .= " AND l.item_condition = :condition";
            $countSql .= " AND l.item_condition = :condition";
            $params[':condition'] = $filters['condition'];
        }
        
        // Filtro por preço
        if (!empty($filters['min_price'])) {
            $sql .= " AND l.price >= :min_price";
            $countSql .= " AND l.price >= :min_price";
            $params[':min_price'] = floatval($filters['min_price']);
        }
        if (!empty($filters['max_price'])) {
            $sql .= " AND l.price <= :max_price";
            $countSql .= " AND l.price <= :max_price";
            $params[':max_price'] = floatval($filters['max_price']);
        }
        
        $sql .= " ORDER BY l.is_featured DESC, l.created_at DESC LIMIT $limit OFFSET $offset";
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Filtrar por raio se coordenadas forem fornecidas
        if (!empty($filters['latitude']) && !empty($filters['longitude']) && !empty($filters['radius'])) {
            $lat = floatval($filters['latitude']);
            $lng = floatval($filters['longitude']);
            $radius = intval($filters['radius']);
            
            $items = array_filter($items, function($item) use ($lat, $lng, $radius) {
                if (empty($item['latitude']) || empty($item['longitude'])) {
                    return false;
                }
                $distance = $this->geoService->calculateDistance(
                    $lat, $lng,
                    floatval($item['latitude']), floatval($item['longitude'])
                );
                return $distance <= $radius;
            });
        }
        
        // Reordenar após filtro de raio
        usort($items, function($a, $b) {
            if ($a['is_featured'] != $b['is_featured']) {
                return $b['is_featured'] - $a['is_featured'];
            }
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        $countStmt = $this->db->prepare($countSql);
        foreach ($params as $key => $val) {
            $countStmt->bindValue($key, $val);
        }
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();
        
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ];
    }

    public function findByUser($userId) {
        $stmt = $this->db->prepare("
            SELECT l.* 
            FROM listings l 
            WHERE l.user_id = ? 
            ORDER BY l.created_at DESC
        ");
        $stmt->execute([$userId]);
        $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar imagens para cada listing
        if (!empty($listings)) {
            $ids = array_column($listings, 'id');
            $allImages = $this->getImagesForList($ids);
            foreach ($listings as &$listing) {
                $listing['images'] = $allImages[$listing['id']] ?? [];
            }
        }
        
        return $listings;
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

    public function findBySlug($slug) {
        $stmt = $this->db->prepare("
            SELECT l.*, u.name as seller_name, u.whatsapp as seller_phone,
                   u.is_verified as seller_verified, 
                   u.created_at as seller_since, u.city as seller_city, u.state as seller_state,
                   p.slug as seller_slug, p.avatar_url as seller_avatar
            FROM listings l 
            JOIN users u ON l.user_id = u.id 
            LEFT JOIN user_profiles p ON u.id = p.user_id
            WHERE l.slug = ? AND l.status IN ('active', 'paused', 'sold')
        ");
        $stmt->execute([$slug]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function countUserListings($userId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total FROM listings 
            WHERE user_id = ? AND status = 'active'
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['total'] ?? 0);
    }

    public function findRelated($listingId, $category, $userId, $limit = 4) {
        $stmt = $this->db->prepare("
            SELECT l.*, u.name as seller_name, p.slug as seller_slug
            FROM listings l 
            JOIN users u ON l.user_id = u.id 
            LEFT JOIN user_profiles p ON u.id = p.user_id
            WHERE l.status = 'active' 
            AND l.id != ?
            AND l.user_id != ?
            AND (l.expires_at IS NULL OR l.expires_at > NOW())
            ORDER BY 
                CASE WHEN l.category = ? THEN 1 ELSE 2 END,
                l.is_featured DESC, 
                l.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$listingId, $userId, $category, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function findSuggestions($listingId, $category, $state, $userId, $limit = 8) {
        $stmt = $this->db->prepare("
            SELECT l.*, u.name as seller_name, p.slug as seller_slug
            FROM listings l 
            JOIN users u ON l.user_id = u.id 
            LEFT JOIN user_profiles p ON u.id = p.user_id
            WHERE l.status = 'active' 
            AND l.id != ?
            AND l.user_id != ?
            AND (l.expires_at IS NULL OR l.expires_at > NOW())
            ORDER BY 
                CASE WHEN l.category = ? THEN 1 ELSE 2 END,
                CASE WHEN l.location_state = ? THEN 1 ELSE 2 END,
                l.is_featured DESC,
                l.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$listingId, $userId, $category, $state, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save($data) {
        $sql = "INSERT INTO listings (user_id, title, slug, description, price, category, main_image, location_city, location_state, status, expires_at, is_featured, latitude, longitude, item_condition, is_affiliate, external_url) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $success = $stmt->execute([
            $data['user_id'], $data['title'], $data['slug'], 
            $data['description'], $data['price'], $data['category'], 
            $data['main_image'] ?? null, 
            $data['location_city'] ?? null, 
            $data['location_state'] ?? null,
            $data['expires_at'] ?? null,
            $data['is_featured'] ?? 0,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['item_condition'] ?? null,
            !empty($data['is_affiliate']) ? 1 : 0,
            $data['external_url'] ?? null
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

    public function incrementCounter($id, $eventType) {
        $column = ($eventType === 'VIEW' || $eventType === 'VIEW_DETAILS') ? 'views_count' : 'clicks_count';
        $column = ($eventType === 'WHATSAPP_CLICK') ? 'clicks_count' : 'views_count';
        
        $tableName = 'listings';
        $sql = "UPDATE {$tableName} SET {$column} = {$column} + 1 WHERE id = :id";
            try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([':id' => (int)$id]);
        } catch (\Exception $e) {
            error_log("Erro ao incrementar contador na tabela {$tableName}: " . $e->getMessage());
            return false;
        }
    }

    // ==================== ADMIN METHODS ====================

    public function findAll($filters = []) {
        $sql = "SELECT l.*, u.name as seller_name, u.email as seller_email 
                FROM listings l 
                LEFT JOIN users u ON l.user_id = u.id
                WHERE 1=1";
        
        $params = [];

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $sql .= " AND l.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['category']) && $filters['category'] !== 'all') {
            $sql .= " AND l.category = :category";
            $params[':category'] = $filters['category'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (l.title LIKE :search OR u.name LIKE :search OR u.email LIKE :search)";
            $params[':search'] = "%{$filters['search']}%";
        }

        if (!empty($filters['is_affiliate']) && $filters['is_affiliate'] !== 'all') {
            $sql .= " AND l.is_affiliate = :is_affiliate";
            $params[':is_affiliate'] = ($filters['is_affiliate'] === 'yes') ? 1 : 0;
        }

        $sql .= " ORDER BY l.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByIdAdmin($id) {
        $stmt = $this->db->prepare("
            SELECT l.*, u.name as seller_name, u.email as seller_email 
            FROM listings l 
            LEFT JOIN users u ON l.user_id = u.id
            WHERE l.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $data) {
        $fields = [];
        $params = [':id' => $id];

        $allowedFields = ['title', 'description', 'price', 'category', 'location_city', 'location_state', 'status', 'is_featured', 'expires_at', 'latitude', 'longitude', 'item_condition', 'main_image', 'is_affiliate', 'external_url'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $field === 'is_affiliate' ? ($data[$field] ? 1 : 0) : $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE listings SET " . implode(', ', $fields) . " WHERE id = :id");
        return $stmt->execute($params);
    }

    public function delete($id) {
        $stmt = $this->db->prepare("UPDATE listings SET status = 'deleted' WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function setFeatured($id, $isFeatured, $expiresAt = null) {
        $sql = "UPDATE listings SET is_featured = ?, expires_at = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$isFeatured ? 1 : 0, $expiresAt, $id]);
    }

    public function extendExpiration($id, $newExpiresAt) {
        $stmt = $this->db->prepare("UPDATE listings SET expires_at = ? WHERE id = ?");
        return $stmt->execute([$newExpiresAt, $id]);
    }

    public function updateCoords(int $listingId, float $lat, float $lng): bool {
        try {
            $stmt = $this->db->prepare("UPDATE listings SET latitude = ?, longitude = ? WHERE id = ?");
            return $stmt->execute([$lat, $lng, $listingId]);
        } catch (\Exception $e) {
            error_log("Erro updateCoords: " . $e->getMessage());
            return false;
        }
    }
    
    public function expireOldListings() {
        $stmt = $this->db->prepare("
            UPDATE listings 
            SET status = 'expired' 
            WHERE status = 'active' 
            AND expires_at IS NOT NULL 
            AND expires_at < NOW()
        ");
        return $stmt->execute();
    }
}