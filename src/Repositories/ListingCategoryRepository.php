<?php
namespace App\Repositories;

use PDO;

class ListingCategoryRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function findAll($activeOnly = true) {
        $sql = "SELECT * FROM listing_categories WHERE 1=1";
        if ($activeOnly) {
            $sql .= " AND is_active = 1 AND deleted_at IS NULL";
        }
        $sql .= " ORDER BY sort_order ASC, name ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById($id) {
        $stmt = $this->db->prepare("SELECT * FROM listing_categories WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findBySlug($slug) {
        $stmt = $this->db->prepare("SELECT * FROM listing_categories WHERE slug = ? AND deleted_at IS NULL");
        $stmt->execute([$slug]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($data) {
        $stmt = $this->db->prepare("
            INSERT INTO listing_categories (name, slug, icon, description, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $maxOrder = $this->db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM listing_categories")->fetchColumn();
        
        $stmt->execute([
            $data['name'],
            $data['slug'] ?? $this->generateSlug($data['name']),
            $data['icon'] ?? null,
            $data['description'] ?? null,
            $data['sort_order'] ?? $maxOrder,
            $data['is_active'] ?? 1
        ]);
        
        return $this->db->lastInsertId();
    }

    public function update($id, $data) {
        $fields = [];
        $params = [];
        
        if (isset($data['name'])) {
            $fields[] = "name = ?";
            $params[] = $data['name'];
        }
        if (isset($data['slug'])) {
            $fields[] = "slug = ?";
            $params[] = $data['slug'];
        }
        if (isset($data['icon'])) {
            $fields[] = "icon = ?";
            $params[] = $data['icon'];
        }
        if (isset($data['description'])) {
            $fields[] = "description = ?";
            $params[] = $data['description'];
        }
        if (isset($data['sort_order'])) {
            $fields[] = "sort_order = ?";
            $params[] = $data['sort_order'];
        }
        if (isset($data['is_active'])) {
            $fields[] = "is_active = ?";
            $params[] = $data['is_active'];
        }
        
        if (empty($fields)) return false;
        
        $params[] = $id;
        $sql = "UPDATE listing_categories SET " . implode(", ", $fields) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete($id) {
        $stmt = $this->db->prepare("UPDATE listing_categories SET deleted_at = NOW() WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function toggleActive($id) {
        $stmt = $this->db->prepare("UPDATE listing_categories SET is_active = NOT is_active WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function reorder($orderedIds) {
        $stmt = $this->db->prepare("UPDATE listing_categories SET sort_order = ? WHERE id = ?");
        foreach ($orderedIds as $order => $id) {
            $stmt->execute([$order + 1, $id]);
        }
        return true;
    }

    private function generateSlug($text) {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        return strtolower($text) . '-' . substr(md5(time()), 0, 4);
    }
}
