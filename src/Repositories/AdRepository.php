<?php
namespace App\Repositories;

use PDO;

class AdRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Busca inteligente com prioridade geográfica e fallback para anúncios globais.
     */
    public function findAds($position = '', $state = '', $search = '', $city = '', $limit = 10) {
        $params = [
            ':city' => $city,
            ':state' => $state
        ];
        
        $sql = "SELECT *, COALESCE(destination_url, '') as link_url,
                (CASE 
                    WHEN (location_city IS NOT NULL AND location_city != '' AND UPPER(location_city) = UPPER(:city)) THEN 100
                    WHEN (location_state IS NOT NULL AND location_state != '' AND UPPER(location_state) = UPPER(:state)) THEN 50
                    WHEN (location_state = 'Brasil' OR category = 'PLATAFORMA' OR location_state IS NULL OR location_state = '') THEN 10
                    ELSE 1 
                END) as priority
                FROM ads 
                WHERE is_deleted = 0 AND status = 'active' 
                AND (expires_at IS NULL OR expires_at >= CURDATE())";

        if (!empty($position)) {
            $sql .= " AND position = :position";
            $params[':position'] = $position;
        }

        if (!empty($search)) {
            $sql .= " AND (title LIKE :search OR category LIKE :search OR description LIKE :search OR category = 'PLATAFORMA')";
            $params[':search'] = "%$search%";
        }

        $sql .= " ORDER BY priority DESC, RAND() LIMIT " . (int)$limit;

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Incrementa visualizações e gera histórico temporal para relatórios.
     */
    public function incrementViews(array $ids) {
        if (empty($ids)) return false;

        try {
            $this->db->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            // 1. Update no contador rápido da tabela ads
            $this->db->prepare("UPDATE ads SET views_count = views_count + 1 WHERE id IN ($placeholders)")->execute($ids);

            // 2. Insert no log detalhado para gráficos e faturamento
            $stmtLog = $this->db->prepare("INSERT INTO ads_stats (ad_id, type) VALUES (?, 'view')");
            foreach ($ids as $id) {
                $stmtLog->execute([$id]);
            }

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Incrementa cliques e gera histórico temporal.
     */
    public function incrementClick($id) {
        try {
            $this->db->beginTransaction();
            $this->db->prepare("UPDATE ads SET clicks_count = clicks_count + 1 WHERE id = ?")->execute([$id]);
            $this->db->prepare("INSERT INTO ads_stats (ad_id, type) VALUES (?, 'click')")->execute([$id]);
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * ESSENCIAL PARA MONETIZAÇÃO: Busca dados para o gráfico de performance.
     */
    public function getPerformanceReport($adId, $days = 30) {
        $sql = "SELECT DATE(created_at) as day, 
                       SUM(CASE WHEN type = 'view' THEN 1 ELSE 0 END) as views,
                       SUM(CASE WHEN type = 'click' THEN 1 ELSE 0 END) as clicks
                FROM ads_stats 
                WHERE ad_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY DATE(created_at) ORDER BY day ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$adId, $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save($data) {
        $id = $data['id'] ?? null;
        $fields = [
            'title' => $data['title'],
            'category' => $data['category'] ?? 'OUTROS',
            'description' => $data['description'] ?? '',
            'image_url' => $data['image_url'] ?? '',
            'destination_url' => $data['destination_url'] ?? '',
            'location_city' => $data['location_city'] ?? '',
            'location_state' => $data['location_state'] ?? '',
            'position' => $data['position'] ?? 'sidebar',
            'status' => $data['status'] ?? 'active',
            'expires_at' => !empty($data['expires_at']) ? $data['expires_at'] : null
        ];

        if ($id) {
            $sql = "UPDATE ads SET title=:title, category=:category, description=:description, 
                    image_url=:image_url, destination_url=:destination_url, location_city=:location_city, 
                    location_state=:location_state, position=:position, status=:status, expires_at=:expires_at 
                    WHERE id = :id";
            $fields['id'] = $id;
        } else {
            $sql = "INSERT INTO ads (title, category, description, image_url, destination_url, location_city, location_state, position, status, expires_at) 
                    VALUES (:title, :category, :description, :image_url, :destination_url, :location_city, :location_state, :position, :status, :expires_at)";
        }

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($fields) ? ($id ?: $this->db->lastInsertId()) : false;
    }

    public function softDelete($id) {
        return $this->db->prepare("UPDATE ads SET is_deleted = 1, status = 'rejected' WHERE id = ?")->execute([$id]);
    }

    public function incrementCounter($id, $eventType) {
        $column = ($eventType === 'VIEW' || $eventType === 'VIEW_DETAILS') ? 'views_count' : 'clicks_count';
        $column = ($eventType === 'WHATSAPP_CLICK') ? 'clicks_count' : 'views_count';
        
        $tableName = 'ads';
        $sql = "UPDATE {$tableName} SET {$column} = {$column} + 1 WHERE id = :id";
            try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([':id' => (int)$id]);
        } catch (\Exception $e) {
            error_log("Erro ao incrementar contador na tabela {$tableName}: " . $e->getMessage());
            return false;
        }
    }
}