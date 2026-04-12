<?php
namespace App\Repositories;

use PDO;

class GroupCategoryRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function findAll($activeOnly = false)
    {
        $sql = "SELECT * FROM group_categories";
        
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        
        $sql .= " ORDER BY sort_order ASC, name ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById($id)
    {
        $stmt = $this->db->prepare("SELECT * FROM group_categories WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findBySlug($slug)
    {
        $stmt = $this->db->prepare("SELECT * FROM group_categories WHERE slug = ?");
        $stmt->execute([$slug]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($data)
    {
        $slug = $this->generateSlug($data['name']);
        
        $sql = "INSERT INTO group_categories (name, slug, color, description, sort_order, is_active) 
                VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['name'],
            $slug,
            $data['color'] ?? '#6366f1',
            $data['description'] ?? null,
            $data['sort_order'] ?? 0,
            $data['is_active'] ?? 1
        ]);
        
        return $this->db->lastInsertId();
    }

    public function update($id, $data)
    {
        $fields = [];
        $params = [];
        
        if (isset($data['name'])) {
            $fields[] = "name = ?";
            $params[] = $data['name'];
            $fields[] = "slug = ?";
            $params[] = $this->generateSlug($data['name'], $id);
        }
        
        if (isset($data['color'])) {
            $fields[] = "color = ?";
            $params[] = $data['color'];
        }
        
        if (isset($data['description'])) {
            $fields[] = "description = ?";
            $params[] = $data['description'];
        }
        
        if (isset($data['sort_order'])) {
            $fields[] = "sort_order = ?";
            $params[] = (int) $data['sort_order'];
        }
        
        if (isset($data['is_active'])) {
            $fields[] = "is_active = ?";
            $params[] = (int) $data['is_active'];
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $params[] = $id;
        $sql = "UPDATE group_categories SET " . implode(", ", $fields) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete($id)
    {
        $stmt = $this->db->prepare("DELETE FROM group_categories WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function toggleActive($id)
    {
        $stmt = $this->db->prepare("UPDATE group_categories SET is_active = NOT is_active WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function reorder($ids)
    {
        $this->db->beginTransaction();
        
        try {
            foreach ($ids as $index => $id) {
                $stmt = $this->db->prepare("UPDATE group_categories SET sort_order = ? WHERE id = ?");
                $stmt->execute([$index + 1, $id]);
            }
            
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function count()
    {
        $stmt = $this->db->query("SELECT COUNT(*) as total FROM group_categories");
        return $stmt->fetch()['total'];
    }

    public function countActive()
    {
        $stmt = $this->db->query("SELECT COUNT(*) as total FROM group_categories WHERE is_active = 1");
        return $stmt->fetch()['total'];
    }

    private function generateSlug($name, $excludeId = null)
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        
        $stmt = $this->db->prepare("SELECT id FROM group_categories WHERE slug = ? AND id != ?");
        $stmt->execute([$slug, $excludeId ?? 0]);
        
        if ($stmt->fetch()) {
            $slug .= '-' . time();
        }
        
        return $slug;
    }
}
