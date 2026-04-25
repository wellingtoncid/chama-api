<?php
namespace App\Repositories;

use PDO;

class ArticleCategoryRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Get all categories
     */
    public function getAll() {
        $stmt = $this->db->query("
            SELECT * FROM article_categories 
            WHERE deleted_at IS NULL 
            ORDER BY name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get active categories (for public use)
     */
    public function getActive() {
        $stmt = $this->db->query("
            SELECT * FROM article_categories 
            WHERE deleted_at IS NULL 
            ORDER BY name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get category by ID
     */
    public function findById($id) {
        $stmt = $this->db->prepare("SELECT * FROM article_categories WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get category by slug
     */
    public function findBySlug($slug) {
        $stmt = $this->db->prepare("SELECT * FROM article_categories WHERE slug = :slug AND deleted_at IS NULL");
        $stmt->execute([':slug' => $slug]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Create category
     */
    public function create($data) {
        $stmt = $this->db->prepare("
            INSERT INTO article_categories (name, slug, description, color, created_at)
            VALUES (:name, :slug, :description, :color, NOW())
        ");
        
        $stmt->execute([
            ':name' => $data['name'],
            ':slug' => $data['slug'],
            ':description' => $data['description'] ?? null,
            ':color' => $data['color'] ?? '#1f4ead'
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Update category
     */
    public function update($id, $data) {
        $fields = [];
        $params = [':id' => $id];

        if (isset($data['name'])) {
            $fields[] = "name = :name";
            $params[':name'] = $data['name'];
        }
        if (isset($data['slug'])) {
            $fields[] = "slug = :slug";
            $params[':slug'] = $data['slug'];
        }
        if (isset($data['description'])) {
            $fields[] = "description = :description";
            $params[':description'] = $data['description'];
        }
        if (isset($data['color'])) {
            $fields[] = "color = :color";
            $params[':color'] = $data['color'];
        }

        if (empty($fields)) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE article_categories SET " . implode(', ', $fields) . " WHERE id = :id");
        return $stmt->execute($params);
    }

    /**
     * Delete category (soft delete)
     */
    public function delete($id) {
        $stmt = $this->db->prepare("UPDATE article_categories SET deleted_at = NOW() WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Check if slug exists
     */
    public function slugExists($slug, $excludeId = null) {
        $sql = "SELECT COUNT(*) FROM article_categories WHERE slug = :slug AND deleted_at IS NULL";
        $params = [':slug' => $slug];

        if ($excludeId) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC)['total'] > 0;
    }
}