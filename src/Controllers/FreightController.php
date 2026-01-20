<?php
/**
 * FreightController.php - Gestão de Fretes, Interessados e métricas Pro
 * Sincronizado com SQL e Sistema de Native Ads
 */

class FreightController {
    private $db;

    public function __construct($db) { 
        $this->db = $db; 
    }

    public function handle($method, $endpoint, $data, $loggedUser) {
        switch ($endpoint) {
            case 'freights':
                if ($method === 'GET') return $this->listAll($loggedUser['id'] ?? null);
                if ($method === 'POST') return $this->create($data, $loggedUser);
                break;

            case 'log-event': // Unificado para Cliques/Views de Fretes, Ads e Grupos
                return $this->logEvent($data, $loggedUser);

            case 'finish-freight':
                return $this->updateStatus($data['id'] ?? 0, 'FINISHED', $loggedUser);

            case 'toggle-favorite':
                return $this->toggleFavorite($loggedUser['id'] ?? null, $data['freight_id'] ?? null);

            case 'my-favorites':
                return $this->listFavorites($loggedUser['id'] ?? null);

            case 'register-click': // Compatibilidade com chamadas antigas
                return $this->logMetric($data, $loggedUser);

            case 'list-my-freights':    
            case 'get-user-posts':
                $targetId = $data['user_id'] ?? $_GET['user_id'] ?? $loggedUser['id'] ?? 0;
                return $this->getUserPosts($targetId);

            case 'delete-freight':
                return $this->deleteFreight($data['id'] ?? 0, $loggedUser);

            case 'recommended-freights':
                return $this->getRecommended($loggedUser);

            case 'my-matches':
                return $this->getMyMatches($loggedUser);
            
            case 'get-interested-drivers':
                $targetId = $_GET['user_id'] ?? $data['user_id'] ?? $loggedUser['id'] ?? 0;
                return $this->getInterestedDrivers($targetId);

            default: 
                return ["error" => "Endpoint não suportado no FreightController"];
        }
    }

    /**
     * listAll - Busca Global por Semelhança (Cidade, Estado, Produto, Veículo, Empresa)
     */
    private function listAll($userId) {
        // Cleanup automático de expirados
        $this->db->query("UPDATE freights SET status = 'EXPIRED' WHERE status = 'OPEN' AND expires_at < NOW()");

        $search = $_GET['search'] ?? '';
            
        $sql = "SELECT f.*, u.name as company_name, p.avatar_url,
                (CASE WHEN fav.id IS NOT NULL THEN 1 ELSE 0 END) as is_favorite
                FROM freights f
                LEFT JOIN users u ON f.user_id = u.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                LEFT JOIN favorites fav ON f.id = fav.freight_id AND fav.user_id = :u_id
                WHERE f.status = 'OPEN'";
        
        $params = [':u_id' => $userId];

        if (!empty($search)) {
            // 1. Limpa espaços extras e divide a frase em palavras
            $keywords = explode(' ', preg_replace('/\s+/', ' ', trim($search)));
            
            foreach ($keywords as $index => $keyword) {
                $key = ":t" . $index;
                // Para cada palavra, ela deve existir em ALGUMA das colunas abaixo
                $sql .= " AND (
                    f.origin LIKE $key OR 
                    f.destination LIKE $key OR 
                    f.product LIKE $key OR 
                    f.description LIKE $key OR 
                    f.vehicleType LIKE $key OR 
                    f.bodyType LIKE $key OR
                    u.name LIKE $key
                )";
                $params[$key] = "%$keyword%";
            }
        }

        $sql .= " ORDER BY f.is_featured DESC, f.id DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * logEvent - Centraliza o rastreio de performance do portal
     */
    private function logEvent($data, $loggedUser) {
        $itemId     = $data['item_id'] ?? $data['id'] ?? 0;
        $targetType = $data['target_type'] ?? 'FREIGHT'; // 'AD', 'FREIGHT', 'WA_GROUP'
        $eventType  = $data['event_type'] ?? 'CLICK';   // 'VIEW', 'CLICK'
        $userId     = $loggedUser['id'] ?? null;

        if (!$itemId) return ["success" => false];

        try {
            $tableMap = ['AD' => 'ads', 'FREIGHT' => 'freights', 'WA_GROUP' => 'whatsapp_groups'];
            $targetTable = $tableMap[$targetType] ?? 'freights';
            $column = ($eventType === 'VIEW') ? 'views_count' : 'clicks_count';
            
            // 1. Incrementa contador rápido
            $this->db->prepare("UPDATE $targetTable SET $column = $column + 1 WHERE id = ?")->execute([$itemId]);

            // 2. Log detalhado (click_logs ou view_logs)
            $logTable = ($eventType === 'VIEW') ? 'view_logs' : 'click_logs';
            $stmtLog = $this->db->prepare("INSERT INTO $logTable (target_id, target_type, user_id) VALUES (?, ?, ?)");
            $stmtLog->execute([$itemId, $targetType, $userId]);

            // 3. Caso especial: Leads de Frete para Empresas (freight_clicks)
            if ($targetType === 'FREIGHT' && $eventType === 'CLICK' && $userId) {
                $check = $this->db->prepare("SELECT id FROM freight_clicks WHERE user_id = ? AND freight_id = ?");
                $check->execute([$userId, $itemId]);
                if (!$check->fetch()) {
                    $this->db->prepare("INSERT INTO freight_clicks (user_id, freight_id) VALUES (?, ?)")->execute([$userId, $itemId]);
                }
            }
            return ["success" => true];
        } catch (Exception $e) {
            return ["success" => false, "error" => $e->getMessage()];
        }
    }

    private function create($data, $user) {
        if (!$user) return ["success" => false, "message" => "Login necessário"];

        $status = ($user['role'] === 'ADMIN' || ($user['is_verified'] ?? 0) == 1) ? 'OPEN' : 'PENDING';
        $days = (isset($data['is_featured']) && $data['is_featured']) ? 30 : 7;
        $expires_at = date('Y-m-d H:i:s', strtotime("+$days days"));

        $sql = "INSERT INTO freights (
                user_id, origin, origin_state, destination, dest_state, 
                product, weight, vehicleType, bodyType, description, status, price, expires_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        $success = $stmt->execute([
            $user['id'], 
            $data['origin'] ?? '', $data['origin_state'] ?? '',
            $data['destination'] ?? '', $data['dest_state'] ?? '',
            $data['product'] ?? '', $data['weight'] ?? '',
            $data['vehicleType'] ?? $data['vehicle_type'] ?? '',
            $data['bodyType'] ?? '', $data['description'] ?? '',
            $status, $data['price'] ?? 0, $expires_at
        ]);

        return ["success" => $success, "id" => $this->db->lastInsertId()];
    }

    private function getUserPosts($targetUserId) {
        $stmt = $this->db->prepare("
            SELECT f.*, 
            (SELECT COUNT(*) FROM click_logs WHERE target_id = f.id AND target_type = 'FREIGHT') as interested_count
            FROM freights f WHERE f.user_id = ? ORDER BY f.id DESC
        ");
        $stmt->execute([$targetUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getInterestedDrivers($companyId) {
        $stmt = $this->db->prepare("
            SELECT u.id, u.name, u.whatsapp as phone, p.avatar_url as photo,
                   f.origin, f.destination, MAX(cl.created_at) as last_clicked_at
            FROM click_logs cl
            JOIN users u ON cl.user_id = u.id
            LEFT JOIN user_profiles p ON u.id = p.user_id
            JOIN freights f ON cl.target_id = f.id
            WHERE f.user_id = ? AND cl.target_type = 'FREIGHT'
            GROUP BY u.id, f.id ORDER BY last_clicked_at DESC
        ");
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function logMetric($data, $loggedUser) {
        $data['item_id'] = $data['id'] ?? $data['freight_id'] ?? 0;
        return $this->logEvent($data, $loggedUser);
    }

    private function updateStatus($id, $status, $user) {
        $stmt = $this->db->prepare("UPDATE freights SET status = ? WHERE id = ? AND (user_id = ? OR ? = 'ADMIN')");
        return ["success" => $stmt->execute([$status, $id, $user['id'], $user['role']])];
    }

    private function deleteFreight($id, $user) {
        $stmt = $this->db->prepare("DELETE FROM freights WHERE id = ? AND (user_id = ? OR ? = 'ADMIN')");
        return ["success" => $stmt->execute([$id, $user['id'], $user['role']])];
    }

    private function listFavorites($userId) {
        $stmt = $this->db->prepare("SELECT f.*, u.name as company_name FROM favorites fav 
            JOIN freights f ON fav.freight_id = f.id JOIN users u ON f.user_id = u.id WHERE fav.user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getRecommended($loggedUser) {
        $stmt = $this->db->prepare("SELECT preferred_region FROM user_profiles WHERE user_id = ?");
        $stmt->execute([$loggedUser['id'] ?? 0]);
        $region = $stmt->fetchColumn();
        if(!$region) return $this->listAll($loggedUser['id'] ?? null);
        
        $stmt = $this->db->prepare("SELECT * FROM freights WHERE origin_state = ? AND status = 'OPEN' LIMIT 10");
        $stmt->execute([$region]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getMyMatches($loggedUser) {
        $stmt = $this->db->prepare("SELECT vehicleType, bodyType, preferred_region FROM user_profiles WHERE user_id = ?");
        $stmt->execute([$loggedUser['id'] ?? 0]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) return ["success" => true, "data" => []];

        $stmt = $this->db->prepare("SELECT f.* FROM freights f WHERE f.status = 'OPEN' 
            AND (f.vehicleType = ? OR f.bodyType = ? OR f.origin_state = ?) LIMIT 15");
        $stmt->execute([$p['vehicleType'], $p['bodyType'], $p['preferred_region']]);
        return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function toggleFavorite($userId, $freightId) {
        $check = $this->db->prepare("SELECT id FROM favorites WHERE user_id = ? AND freight_id = ?");
        $check->execute([$userId, $freightId]);
        if ($check->fetch()) {
            $this->db->prepare("DELETE FROM favorites WHERE user_id = ? AND freight_id = ?")->execute([$userId, $freightId]);
            return ["status" => "removed", "is_favorite" => 0];
        }
        $this->db->prepare("INSERT INTO favorites (user_id, freight_id) VALUES (?, ?)")->execute([$userId, $freightId]);
        return ["status" => "added", "is_favorite" => 1];
    }
}