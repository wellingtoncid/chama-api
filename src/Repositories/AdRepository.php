<?php
namespace App\Repositories;

use PDO;

class AdRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Busca inteligente com prioridade geográfica, fallback global 
     * e filtro obrigatório de saldo de créditos.
     */
    public function findAds($position = '', $state = '', $search = '', $city = '', $limit = 10) {
        $params = [
            ':city' => $city,
            ':state' => $state
        ];
        
       $sql = "SELECT a.*, COALESCE(a.destination_url, '') as link_url, u.ad_credits,
                (CASE 
                    -- 1. Prioridade Geográfica (Base)
                    WHEN (UPPER(a.location_city) = UPPER(:city)) THEN 100
                    WHEN (UPPER(a.location_state) = UPPER(:state)) THEN 50
                    ELSE 10 
                END + 
                -- 2. BÔNUS DE IMPULSIONAMENTO (Aqui entra o crédito)
                CASE 
                    WHEN u.ad_credits > 0 THEN 200 -- Se tem crédito, pula pra frente de todos
                    ELSE 0 
                END) as final_priority
                FROM ads a
                INNER JOIN users u ON a.user_id = u.id
                WHERE a.is_deleted = 0 
                AND a.status = 'active'
                AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())";

        // Filtro por posição (ex: 'sidebar', 'top')
        if (!empty($position)) {
            $sql .= " AND a.position = :position";
            $params[':position'] = $position;
        }

        // Filtro de busca textual
        if (!empty($search)) {
            $sql .= " AND (a.title LIKE :search OR a.category LIKE :search OR a.description LIKE :search OR a.category = 'PLATAFORMA')";
            $params[':search'] = "%$search%";
        }

        // Ordenação por prioridade geográfica e depois aleatório para rotatividade
        $sql .= " ORDER BY final_priority DESC, RAND() LIMIT " . (int)$limit;

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("Erro no findAds (AdRepository): " . $e->getMessage());
            return [];
        }
    }

    /**
     * Incrementa visualizações em massa (Usado no AdController@list)
     * Agora também debita créditos proporcionalmente.
     */
    public function incrementViews(array $ids) {
        if (empty($ids)) return false;

        $costPerView = 1; // 1 crédito por visualização
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        try {
            $this->db->beginTransaction();

            // 1. Incrementa contadores de visualização em massa
            $sqlAds = "UPDATE ads SET views_count = views_count + 1 WHERE id IN ($placeholders)";
            $stmtAds = $this->db->prepare($sqlAds);
            $stmtAds->execute($ids);

            // 2. Debita créditos de cada dono de anúncio (JOIN para performance)
            // O MySQL permite dar UPDATE com JOIN para descontar de uma vez só
            $sqlUsers = "UPDATE users u 
                        INNER JOIN ads a ON a.user_id = u.id 
                        SET u.ad_credits = u.ad_credits - :cost 
                        WHERE a.id IN ($placeholders) AND u.ad_credits >= :cost";
            
            $stmtUsers = $this->db->prepare($sqlUsers);
            
            // Bind manual dos IDs para o segundo set de placeholders
            $params = [':cost' => $costPerView];
            $stmtUsers->execute(array_merge($ids, $params)); // Reutiliza os IDs

            // 3. Pausa anúncios de quem ficou sem saldo
            $this->db->prepare("UPDATE ads a 
                                INNER JOIN users u ON a.user_id = u.id 
                                SET a.status = 'pending' 
                                WHERE a.id IN ($placeholders) AND u.ad_credits < :cost")
                    ->execute(array_merge($ids, $params));

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro no incrementViews em massa: " . $e->getMessage());
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

    /**
     * Incrementa eventos individuais (CLIQUES, WHATSAPP, etc)
     * Mantém a funcionalidade original de mapeamento de colunas.
     */
    public function incrementCounter($id, $eventType) {
        // 1. Busca os custos configurados no banco (site_settings)
        $sqlSettings = "SELECT setting_key, setting_value FROM site_settings WHERE category = 'advertising'";
        $stmtSettings = $this->db->query($sqlSettings);
        $settings = $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR);

        // Mapeamento dinâmico baseado no banco de dados
        $costs = [
            'VIEW'           => (int)($settings['ad_cost_view'] ?? 1),
            'VIEW_DETAILS'   => (int)($settings['ad_cost_view_details'] ?? 2),
            'CLICK'          => (int)($settings['ad_cost_click'] ?? 10),
            'WHATSAPP_CLICK' => (int)($settings['ad_cost_whatsapp'] ?? 15)
        ];

        $cost = $costs[$eventType] ?? 1;
        $column = (str_contains($eventType, ['VIEW', 'VIEW_DETAILS'])) ? 'views_count' : 'clicks_count';

        try {
            $this->db->beginTransaction();

            // Incrementa o contador do anúncio
            $this->db->prepare("UPDATE ads SET {$column} = {$column} + 1 WHERE id = ?")->execute([$id]);

            // Débito de créditos
            $sqlUser = "UPDATE users u 
                        INNER JOIN ads a ON a.user_id = u.id 
                        SET u.ad_credits = u.ad_credits - :cost 
                        WHERE a.id = :ad_id AND u.ad_credits >= :cost";
            
            $stmtUser = $this->db->prepare($sqlUser);
            $stmtUser->execute([':cost' => $cost, ':ad_id' => $id]);

            // Se debitou, registra no histórico
        if ($stmtUser->rowCount() > 0) {
            $this->insertTransaction($id, -$cost, $eventType);
            } else {
                // REGISTRO DE HISTÓRICO (Obrigatório para o Manager conferir)
                $this->db->prepare("INSERT INTO credit_transactions (user_id, amount, type, description) 
                                    SELECT user_id, :cost, 'consumption', :desc FROM ads WHERE id = :ad_id")
                        ->execute([
                            ':cost' => -$cost, 
                            ':desc' => "Consumo: $eventType no anúncio #$id",
                            ':ad_id' => $id
                        ]);
            }

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro no débito dinâmico: " . $e->getMessage());
            return false;
        }
    }

    public function getAdsByUserId($userId) {
        // 1. Buscamos o saldo atual do usuário para retornar junto com os anúncios
        // 2. Incluímos os contadores de performance
        // 3. Verificamos o status de 'boost' (se o usuário tem crédito, o anúncio está impulsionado)
        $sql = "SELECT 
                    a.id, 
                    a.title, 
                    a.description, 
                    a.image_url, 
                    a.category, 
                    a.destination_url, 
                    a.created_at, 
                    a.status,
                    a.views_count,
                    a.clicks_count,
                    u.ad_credits,
                    -- Flag para o Frontend saber se o anúncio está recebendo prioridade
                    (CASE WHEN u.ad_credits > 0 THEN 1 ELSE 0 END) as is_boosted
                FROM ads a
                INNER JOIN users u ON a.user_id = u.id
                WHERE a.user_id = :user_id 
                AND a.is_deleted = 0
                ORDER BY a.created_at DESC";
                    
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':user_id' => $userId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("Erro ao buscar anúncios do usuário: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Busca pacotes de créditos ativos no sistema
     */
    public function getPackages() {
        return $this->db->query("SELECT * FROM ad_packages WHERE active = 1 ORDER BY price ASC")
                        ->fetchAll(\PDO::FETCH_ASSOC);
    }
}