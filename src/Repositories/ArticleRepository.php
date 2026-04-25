<?php
namespace App\Repositories;

use PDO;

class ArticleRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getDb() {
        return $this->db;
    }

    /**
     * Create a new article
     */
    public function create($data) {
        $stmt = $this->db->prepare("
            INSERT INTO articles (title, slug, excerpt, content, author_id, category_id, featured, is_paid, paid_plan, paid_until, paid_banner_image, paid_banner_url, status, created_at)
            VALUES (:title, :slug, :excerpt, :content, :author_id, :category_id, :featured, :is_paid, :paid_plan, :paid_until, :paid_banner_image, :paid_banner_url, :status, NOW())
        ");
        
        $stmt->execute([
            ':title' => $data['title'],
            ':slug' => $data['slug'],
            ':excerpt' => $data['excerpt'] ?? null,
            ':content' => $data['content'],
            ':author_id' => $data['author_id'],
            ':category_id' => $data['category_id'] ?? null,
            ':featured' => $data['featured'] ?? false,
            ':is_paid' => $data['is_paid'] ?? false,
            ':paid_plan' => $data['paid_plan'] ?? null,
            ':paid_until' => $data['paid_until'] ?? null,
            ':paid_banner_image' => $data['paid_banner_image'] ?? null,
            ':paid_banner_url' => $data['paid_banner_url'] ?? null,
            ':status' => $data['status'] ?? 'pending'
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Get article by ID
     */
    public function findById($id) {
        $stmt = $this->db->prepare("
            SELECT a.*, u.name as author_name, up.avatar_url as author_avatar, ac.name as category_name, ac.slug as category_slug
            FROM articles a
            LEFT JOIN users u ON a.author_id = u.id
            LEFT JOIN user_profiles up ON a.author_id = up.user_id
            LEFT JOIN article_categories ac ON a.category_id = ac.id
            WHERE a.id = :id AND a.deleted_at IS NULL
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get article by slug
     */
    public function findBySlug($slug) {
        $stmt = $this->db->prepare("
            SELECT a.*, u.name as author_name, up.avatar_url as author_avatar, up.bio as author_bio, up.slug as author_slug,
                   ac.name as category_name, ac.slug as category_slug
            FROM articles a
            LEFT JOIN users u ON a.author_id = u.id
            LEFT JOIN user_profiles up ON a.author_id = up.user_id
            LEFT JOIN article_categories ac ON a.category_id = ac.id
            WHERE a.slug = :slug AND a.status = 'published' AND a.deleted_at IS NULL
        ");
        $stmt->execute([':slug' => $slug]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get all published articles with filters
     */
    public function getAll($filters = []) {
        $where = "a.status = 'published' AND a.deleted_at IS NULL";
        $params = [];

        if (!empty($filters['category_id'])) {
            $where .= " AND a.category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }

        if (!empty($filters['category_slug'])) {
            $where .= " AND ac.slug = :category_slug";
            $params[':category_slug'] = $filters['category_slug'];
        }

        if (!empty($filters['featured'])) {
            $where .= " AND a.featured = 1";
        }

        if (!empty($filters['is_paid'])) {
            $where .= " AND a.is_paid = 1 AND (a.paid_until IS NULL OR a.paid_until > NOW())";
        }

        $orderBy = "a.published_at DESC";
        if (!empty($filters['order'])) {
            switch ($filters['order']) {
                case 'popular':
                    $orderBy = "a.views_count DESC";
                    break;
                case 'oldest':
                    $orderBy = "a.published_at ASC";
                    break;
                default:
                    $orderBy = "a.published_at DESC";
            }
        }

        $limit = $filters['limit'] ?? 20;
        $offset = $filters['offset'] ?? 0;

        $stmt = $this->db->prepare("
            SELECT a.*, u.name as author_name, up.avatar_url as author_avatar, ac.name as category_name, ac.slug as category_slug
            FROM articles a
            LEFT JOIN users u ON a.author_id = u.id
            LEFT JOIN user_profiles up ON a.author_id = up.user_id
            LEFT JOIN article_categories ac ON a.category_id = ac.id
            WHERE {$where}
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
        ");
        
        $stmt->execute(array_merge($params, [':limit' => $limit, ':offset' => $offset]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get pending articles for admin
     */
    public function getPending($limit = 20, $offset = 0) {
        $stmt = $this->db->prepare("
            SELECT a.*, u.name as author_name, u.email as author_email
            FROM articles a
            LEFT JOIN users u ON a.author_id = u.id
            WHERE a.status = 'pending' AND a.deleted_at IS NULL
            ORDER BY a.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->execute([':limit' => $limit, ':offset' => $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get articles by author
     */
    public function getByAuthor($authorId, $status = null) {
        $where = "a.author_id = :author_id AND a.deleted_at IS NULL";
        $params = [':author_id' => $authorId];

        if ($status) {
            $where .= " AND a.status = :status";
            $params[':status'] = $status;
        }

        $stmt = $this->db->prepare("
            SELECT a.*, ac.name as category_name
            FROM articles a
            LEFT JOIN article_categories ac ON a.category_id = ac.id
            WHERE {$where}
            ORDER BY a.created_at DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update article
     */
    public function update($id, $data) {
        $fields = [];
        $params = [':id' => $id];

        $allowedFields = ['title', 'slug', 'excerpt', 'content', 'category_id', 'featured', 'status', 'rejection_reason'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (isset($data['published_at'])) {
            $fields[] = "published_at = :published_at";
            $params[':published_at'] = $data['published_at'];
        }

        if (empty($fields)) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE articles SET " . implode(', ', $fields) . " WHERE id = :id");
        return $stmt->execute($params);
    }

    /**
     * Publish article (admin approves)
     */
    public function publish($id) {
        $stmt = $this->db->prepare("
            UPDATE articles 
            SET status = 'published', published_at = NOW(), rejection_reason = NULL, rejection_count = 0
            WHERE id = :id
        ");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Reject article
     */
    public function reject($id, $reason) {
        $stmt = $this->db->prepare("
            UPDATE articles 
            SET status = 'rejected', rejection_reason = :reason, rejection_count = rejection_count + 1, rejection_last_at = NOW()
            WHERE id = :id
        ");
        return $stmt->execute([':id' => $id, ':reason' => $reason]);
    }

    /**
     * Delete article (soft delete)
     */
    public function delete($id) {
        $stmt = $this->db->prepare("UPDATE articles SET deleted_at = NOW() WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Increment views count
     */
    public function incrementViews($id) {
        $stmt = $this->db->prepare("UPDATE articles SET views_count = views_count + 1 WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Increment clicks count
     */
    public function incrementClicks($id) {
        $stmt = $this->db->prepare("UPDATE articles SET clicks_count = clicks_count + 1 WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Get total count
     */
    public function count($filters = []) {
        $where = "a.deleted_at IS NULL";
        $params = [];

        if (!empty($filters['status'])) {
            $where .= " AND a.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['author_id'])) {
            $where .= " AND a.author_id = :author_id";
            $params[':author_id'] = $filters['author_id'];
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM articles a WHERE {$where}");
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    /**
     * Check if slug exists
     */
    public function slugExists($slug, $excludeId = null) {
        $sql = "SELECT COUNT(*) FROM articles WHERE slug = :slug AND deleted_at IS NULL";
        $params = [':slug' => $slug];

        if ($excludeId) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC)['total'] > 0;
    }

    /**
     * Check for banned words
     */
    public function checkBannedWords($content) {
        $stmt = $this->db->query("SELECT word, severity FROM article_banned_words");
        $bannedWords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $foundWords = [];
        foreach ($bannedWords as $word) {
            if (stripos($content, $word['word']) !== false) {
                $foundWords[] = $word;
            }
        }

        return $foundWords;
    }

    /**
     * Get related articles
     */
    public function getRelated($articleId, $categoryId, $limit = 3) {
        $stmt = $this->db->prepare("
            SELECT a.*, u.name as author_name, up.avatar_url as author_avatar
            FROM articles a
            LEFT JOIN users u ON a.author_id = u.id
            LEFT JOIN user_profiles up ON a.author_id = up.user_id
            WHERE a.id != :article_id 
            AND a.category_id = :category_id 
            AND a.status = 'published' 
            AND a.deleted_at IS NULL
            ORDER BY a.published_at DESC
            LIMIT :limit
        ");
        $stmt->execute([
            ':article_id' => $articleId,
            ':category_id' => $categoryId,
            ':limit' => $limit
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all articles for admin (all statuses)
     */
    public function getAllForAdmin($filters = []) {
        $where = "a.deleted_at IS NULL";
        $params = [];

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $where .= " AND a.status = :status";
            $params[':status'] = $filters['status'];
        }

        $orderBy = "a.created_at DESC";

        $limit = $filters['limit'] ?? 50;
        $offset = $filters['offset'] ?? 0;

        $stmt = $this->db->prepare("
            SELECT a.*, u.name as author_name, u.email as author_email, up.avatar_url as author_avatar, ac.name as category_name
            FROM articles a
            LEFT JOIN users u ON a.author_id = u.id
            LEFT JOIN user_profiles up ON a.author_id = up.user_id
            LEFT JOIN article_categories ac ON a.category_id = ac.id
            WHERE {$where}
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
        ");
        
        $stmt->execute(array_merge($params, [':limit' => $limit, ':offset' => $offset]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get article stats for admin
     */
    public function getStats() {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft
            FROM articles 
            WHERE deleted_at IS NULL
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get expired paid articles
     */
    public function getExpiredPaidArticles() {
        $stmt = $this->db->query("
            SELECT id FROM articles 
            WHERE is_paid = 1 
            AND paid_until IS NOT NULL 
            AND paid_until < NOW()
            AND status = 'published'
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update paid article expiration
     */
    public function removePaidStatus($id) {
        $stmt = $this->db->prepare("
            UPDATE articles 
            SET is_paid = false, paid_plan = NULL, paid_until = NULL, paid_banner_image = NULL, paid_banner_url = NULL
            WHERE id = :id
        ");
        return $stmt->execute([':id' => $id]);
    }
}