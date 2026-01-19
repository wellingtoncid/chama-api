<?php
/**
 * FreightController.php - Gestão de Fretes e Interessados
 * Sincronizado com SQL: chama_frete (3).sql
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

            case 'finish-freight':
                return $this->updateStatus($data['id'] ?? 0, 'FINISHED', $loggedUser);

            case 'toggle-favorite':
                return $this->toggleFavorite($loggedUser['id'] ?? null, $data['freight_id'] ?? null);

            case 'my-favorites':
                return $this->listFavorites($loggedUser['id'] ?? null);

            case 'metrics':
            case 'register-click':
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

    private function listAll($userId) {
        // Cleanup: Fretes expirados passam a EXPIRED automaticamente
        $this->db->query("UPDATE freights SET status = 'EXPIRED' WHERE status = 'OPEN' AND expires_at < NOW()");

        $search = $_GET['search'] ?? '';
        $origin_state = $_GET['origin_state'] ?? '';
        $vehicleType = $_GET['vehicleType'] ?? $_GET['vehicle_type'] ?? '';
        $bodyType = $_GET['bodyType'] ?? ''; 

        $sql = "SELECT f.*, u.name as company_name, p.avatar_url,
                (CASE WHEN fav.id IS NOT NULL THEN 1 ELSE 0 END) as is_favorite
                FROM freights f
                LEFT JOIN users u ON f.user_id = u.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                LEFT JOIN favorites fav ON f.id = fav.freight_id AND fav.user_id = :u_id
                WHERE f.status = 'OPEN'";
        
        $params = ['u_id' => $userId];

        if ($search) {
            $sql .= " AND (f.origin LIKE :t OR f.destination LIKE :t OR f.product LIKE :t OR f.vehicleType LIKE :t OR f.bodyType LIKE :t)";
            $params['t'] = "%$search%";
        }

        if ($origin_state) { $sql .= " AND f.origin_state = :os"; $params['os'] = $origin_state; }
        if ($vehicleType) { $sql .= " AND f.vehicleType = :vt"; $params['vt'] = $vehicleType; }
        if ($bodyType) { $sql .= " AND f.bodyType = :bt"; $params['bt'] = $bodyType; }
        
        $sql .= " ORDER BY f.is_featured DESC, f.id DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            $data['origin'] ?? '', 
            $data['origin_state'] ?? '',
            $data['destination'] ?? '', 
            $data['dest_state'] ?? '',
            $data['product'] ?? '', 
            $data['weight'] ?? '',
            $data['vehicleType'] ?? $data['vehicle_type'] ?? '',
            $data['bodyType'] ?? '',
            $data['description'] ?? '',
            $status,
            $data['price'] ?? 0,
            $expires_at
        ]);

        return [
            "success" => $success, 
            "id" => $this->db->lastInsertId(),
            "message" => ($status === 'PENDING') ? "Frete enviado para análise!" : "Frete publicado!"
        ];
    }

    private function getUserPosts($targetUserId) {
        // ESSENCIAL PARA O PAINEL DA EMPRESA: Lista os fretes e a contagem de interessados
        $stmt = $this->db->prepare("
            SELECT f.*, 
            (SELECT COUNT(*) FROM freight_clicks WHERE freight_id = f.id) as interested_count
            FROM freights f 
            WHERE f.user_id = ? 
            ORDER BY f.id DESC
        ");
        $stmt->execute([$targetUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getInterestedDrivers($companyId) {
        if (!$companyId) return [];
        
        // Agrupamos por motorista e por frete para contar as repetições
        $stmt = $this->db->prepare("
            SELECT 
                u.id, 
                u.name, 
                u.whatsapp as phone, 
                u.is_verified,
                p.avatar_url as photo,
                f.origin as freight_origin, 
                f.destination as freight_destination,
                MAX(fc.created_at) as last_clicked_at,
                COUNT(fc.id) as total_clicks
            FROM freight_clicks fc
            JOIN users u ON fc.user_id = u.id
            LEFT JOIN user_profiles p ON u.id = p.user_id
            JOIN freights f ON fc.freight_id = f.id
            WHERE f.user_id = ?
            GROUP BY u.id, f.id
            ORDER BY last_clicked_at DESC
        ");
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function logMetric($data, $loggedUser) {
        $id = $data['id'] ?? $data['freight_id'] ?? 0;
        if (!$id) return ["success" => false];

        // Incrementa contador geral na tabela freights
        $stmt = $this->db->prepare("UPDATE freights SET clicks_count = clicks_count + 1 WHERE id = ?");
        $stmt->execute([$id]);

        // Se houver motorista logado, registra o interesse individual para a empresa ver
        if ($loggedUser) {
            $check = $this->db->prepare("SELECT id FROM freight_clicks WHERE user_id = ? AND freight_id = ?");
            $check->execute([$loggedUser['id'], $id]);
            if (!$check->fetch()) {
                $ins = $this->db->prepare("INSERT INTO freight_clicks (user_id, freight_id) VALUES (?, ?)");
                $ins->execute([$loggedUser['id'], $id]);
            }
        }
        return ["success" => true];
    }

    private function deleteFreight($id, $user) {
        if (!$user) return ["success" => false];
        $stmt = $this->db->prepare("DELETE FROM freights WHERE id = ? AND (user_id = ? OR ? = 'ADMIN')");
        return ["success" => $stmt->execute([$id, $user['id'], $user['role']])];
    }

    private function updateStatus($id, $status, $user) {
        if (!$user || !$id) return ["success" => false];
        $stmt = $this->db->prepare("UPDATE freights SET status = ? WHERE id = ? AND (user_id = ? OR ? = 'ADMIN')");
        return ["success" => $stmt->execute([$status, $id, $user['id'], $user['role']])];
    }

    private function listFavorites($userId) {
        if (!$userId) return [];
        $stmt = $this->db->prepare("
            SELECT f.*, u.name as company_name 
            FROM favorites fav 
            JOIN freights f ON fav.freight_id = f.id 
            JOIN users u ON f.user_id = u.id 
            WHERE fav.user_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getRecommended($loggedUser) {
        // Busca baseada na região preferida do perfil
        $stmt = $this->db->prepare("SELECT preferred_region FROM user_profiles WHERE user_id = ?");
        $stmt->execute([$loggedUser['id'] ?? 0]);
        $pref = $stmt->fetch(PDO::FETCH_ASSOC);

        $region = $pref['preferred_region'] ?? null;
        if(!$region) return $this->listAll($loggedUser['id'] ?? null);

        $stmt = $this->db->prepare("SELECT * FROM freights WHERE origin_state = ? AND status = 'OPEN' LIMIT 5");
        $stmt->execute([$region]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getMyMatches($loggedUser) {
        if (!$loggedUser) return ["success" => false, "data" => []];
        $stmt = $this->db->prepare("SELECT vehicleType, bodyType, preferred_region FROM user_profiles WHERE user_id = ?");
        $stmt->execute([$loggedUser['id']]);
        $pref = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pref) return ["success" => true, "data" => []];

        $sql = "SELECT f.*, u.name as company_name FROM freights f
                LEFT JOIN users u ON f.user_id = u.id
                WHERE f.status = 'OPEN' AND (f.vehicleType = ? OR f.bodyType = ? OR f.origin_state = ?)
                ORDER BY f.is_featured DESC LIMIT 15";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$pref['vehicleType'], $pref['bodyType'], $pref['preferred_region']]);
        return ["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function toggleFavorite($userId, $freightId) {
        if (!$userId) return ["success" => false, "error" => "Login necessário"];
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