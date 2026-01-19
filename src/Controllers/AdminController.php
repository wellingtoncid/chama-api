<?php
// Requer o NotificationController para enviar os alertas automáticos
require_once __DIR__ . '/NotificationController.php';

class AdminController {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function handle($endpoint, $data = []) {
        switch ($endpoint) {
            // --- DASHBOARD & STATS ---
            case 'admin-dashboard-data':
                return $this->getDashboardData();
            case 'admin-stats':
                return $this->getAdminStats();
            case 'admin-audit-logs':
                return $this->getAuditLogs();
            case 'admin-verify-user':
                return $this->verifyUser($data['id'] ?? null, $data['status'] ?? 1);

            // --- PORTAL REQUESTS (LEADS & COMUNIDADES) ---
            case 'admin-portal-requests':
                return $this->getPortalRequests($data);
            case 'admin-update-portal-request':
                return $this->updatePortalRequest($data);
            case 'portal-request':
                return $this->storePortalRequest($data);

            // --- USER MANAGEMENT ---
            case 'admin-list-users':
            case 'list-all-users':
                return $this->listUsers($_GET['role'] ?? '%');
            case 'admin-update-user':
            case 'update-user-permissions':
            case 'manage-users-admin':
                return $this->manageUsers($data);
            case 'create-user-admin':
                return $this->createUserAdmin($data);
            
            // --- FREIGHT MANAGEMENT ---
            case 'admin-list-freights':
                return $this->listAllFreights();
            case 'manage-freights-admin':
                return $this->manageFreights($data);
            case 'approve-freight':
                return $this->updateFreightStatus($data['id'] ?? null, 'OPEN', true);
            case 'reject-freight':
                return $this->updateFreightStatus($data['id'] ?? null, 'CLOSED', false);
            case 'admin-toggle-featured':
                return $this->toggleFreightFeatured($data['id'] ?? null);

            // --- SETTINGS & PLANS ---
            case 'manage-plans':
                return $this->managePlans($data);
            case 'update-settings':
                return $this->updateSettings($data);
            case 'get-settings':
                return $this->getSettings();

            // --- ADS MANAGEMENT ---    
            case 'ads':
                return $this->getAds();
            case 'upload-ad':
                return $this->uploadAd($_POST, $_FILES);
            case 'manage-ads':
                return $this->manageAds($data);    

            default:
                return ["success" => false, "error" => "Rota admin não encontrada: " . $endpoint];
        }
    }

    // --- MÉTODOS DE APOIO ---

    private function saveLog($uId, $uName, $type, $desc, $targetId, $targetType) {
        $sql = "INSERT INTO admin_actions_logs (user_id, user_name, action_type, description, target_id, target_type) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $this->db->prepare($sql)->execute([$uId, $uName, $type, $desc, $targetId, $targetType]);
    }

    private function getDashboardData() {
    // 1. Estatísticas Gerais (KPIs)
    $total_users = (int)$this->db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $drivers = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'driver'")->fetchColumn();
    $companies = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'company'")->fetchColumn();
    
    // Cálculo de Cliques vs Visualizações para Taxa de Conversão
    $metrics = $this->db->query("SELECT SUM(views_count) as views, SUM(clicks_count) as clicks FROM freights")->fetch();
    $views = (int)($metrics['views'] ?? 0);
    $clicks = (int)($metrics['clicks'] ?? 0);
    $conversion_rate = ($views > 0) ? round(($clicks / $views) * 100, 1) : 0;

    $stats = [
        'total_pending'     => (int)$this->db->query("SELECT COUNT(*) FROM freights WHERE status = 'PENDING'")->fetchColumn(),
        'revenue'           => "0,00", // Integre com sua tabela de pagamentos futuramente
        'pending_revenue'   => "0,00",
        'total_users'       => $total_users,
        'drivers'           => $drivers,
        'companies'         => $companies,
        'partners'          => 0, // Caso tenha parceiros/anunciantes separados
        'active_freights'   => (int)$this->db->query("SELECT COUNT(*) FROM freights WHERE status = 'OPEN'")->fetchColumn(),
        'featured_freights' => (int)$this->db->query("SELECT COUNT(*) FROM freights WHERE is_featured = 1 AND status = 'OPEN'")->fetchColumn(),
        'total_interactions'=> $views + $clicks,
        'conversion_rate'   => $conversion_rate
    ];

    // 2. Fila de Validação (O que o seu DashboardAdmin.tsx renderiza à direita)
    $pending_approvals = $this->db->query("
        SELECT f.id, u.name as company_name, f.origin, f.destination, f.created_at 
        FROM freights f 
        JOIN users u ON f.user_id = u.id 
        WHERE f.status = 'PENDING' 
        ORDER BY f.created_at DESC LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 3. Atividades Recentes
    $recent_activities = $this->db->query("
       (SELECT u.name as user, CONCAT('Publicou frete: ', f.product) as action, f.created_at as time, 'FREIGHT' as type 
        FROM freights f JOIN users u ON f.user_id = u.id)
        UNION ALL
        (SELECT contact_info as user, CONCAT('Solicitação: ', type) as action, created_at as time, 'REQUEST' as type
        FROM portal_requests)
        ORDER BY time DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);

    return [
        "stats" => $stats,
        "pending_approvals" => $pending_approvals,
        "recent_activities" => $recent_activities
    ];
}

    private function listUsers($role) {
        $stmt = $this->db->prepare("SELECT id, name, email, whatsapp, role, is_verified, status, created_at 
                                   FROM users WHERE role LIKE ? ORDER BY id DESC");
        $stmt->execute([$role]);
        return $stmt->fetchAll();
    }

    private function verifyUser($id, $status) {
        if (!$id) return ["success" => false];
        $stmt = $this->db->prepare("UPDATE users SET is_verified = ? WHERE id = ?");
        $success = $stmt->execute([$status, $id]);

        if ($success && $status == 1) {
            $notif = new NotificationController($this->db);
            $notif->notify($id, "Perfil Verificado!", "Parabéns! Sua conta agora possui o selo de verificação.");
        }
        return ["success" => $success];
    }

    private function updateFreightStatus($id, $status, $approveFeatured) {
        if (!$id) return ["success" => false];
        
        // 1. Busca dados do frete antes de atualizar para notificar o dono e cruzar matches
        $stmt_info = $this->db->prepare("SELECT user_id, origin, origin_state, destination, product, vehicle_type, bodyType FROM freights WHERE id = ?");
        $stmt_info->execute([$id]);
        $freight = $stmt_info->fetch();

        if (!$freight) return ["success" => false, "message" => "Frete não encontrado"];

        // 2. Executa a atualização do Status e do Destaque
        $sql = "UPDATE freights SET status = ?, 
                is_featured = CASE WHEN requested_featured = 1 AND ? = true THEN 1 ELSE is_featured END,
                requested_featured = 0 WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $success = $stmt->execute([$status, $approveFeatured, $id]);

        if ($success) {
            $notif = new NotificationController($this->db);
            
            // Ação se o frete foi APROVADO
            if ($status === 'OPEN') {
                // Notifica o DONO do frete
                $notif->notify($freight['user_id'], "Frete Aprovado!", "Seu frete de {$freight['origin']} para {$freight['destination']} está online.");
                
                // DISPARA MATCHES para motoristas compatíveis
                $this->triggerMatches($id);
                
            } 
            // Ação se o frete foi REJEITADO/FECHADO pelo Admin
            else if ($status === 'CLOSED') {
                $notif->notify($freight['user_id'], "Frete Rejeitado", "Seu anúncio não cumpre as diretrizes e foi removido.");
            }
        }

        return ["success" => $success];
    }

    private function triggerMatches($freightId) {
        // 1. Busca os detalhes do frete recém-aprovado
        $stmt = $this->db->prepare("SELECT origin_state, vehicle_type, bodyType, product FROM freights WHERE id = ?");
        $stmt->execute([$freightId]);
        $f = $stmt->fetch();

        if (!$f) return;

        // 2. Procura motoristas compatíveis
        // Critério: Mesmo estado de origem OU mesmo tipo de veículo/carroçaria
        $sql = "SELECT user_id FROM user_profiles 
                WHERE (vehicle_type = ? AND bodyType = ?) 
                OR preferred_region = ? 
                LIMIT 50";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$f['vehicle_type'], $f['bodyType'], $f['origin_state']]);
        $drivers = $stmt->fetchAll();

        // 3. Dispara a notificação interna para cada motorista encontrado
        $notifier = new NotificationController($this->db);
        foreach ($drivers as $driver) {
            // Evita notificar o próprio dono do frete caso ele também tenha perfil de motorista
            $notifier->notify(
                $driver['user_id'], 
                "Frete para o seu perfil!", 
                "Novo frete de {$f['product']} saindo de {$f['origin_state']} compatível com o seu veículo."
            );
        }
    }

    private function manageUsers($data) {
        $action = $data['action'] ?? '';
        $id = $data['id'] ?? null;

        if ($action === 'delete-user') {
            $this->db->prepare("DELETE FROM freights WHERE user_id = ?")->execute([$id]);
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
            return ["success" => $stmt->execute([$id])];
        }

        if ($action === 'approve-user') {
            $stmt = $this->db->prepare("UPDATE users SET status = 'approved' WHERE id = ?");
            return ["success" => $stmt->execute([$id])];
        }

        $perms = json_encode($data['permissions'] ?? []);
        $sql = "UPDATE users SET name = ?, whatsapp = ?, role = ?, status = ?, permissions = ?, company_name = ?, cnpj = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return ["success" => $stmt->execute([
            $data['name'], $data['whatsapp'], $data['role'], 
            $data['status'], $perms, $data['company_name'], $data['cnpj'], $id
        ])];
    }

    private function manageFreights($data) {
        $action = $data['action'] ?? '';
        $id = $data['id'] ?? 0;
        $adminId = $data['admin_id'] ?? 0;
        $adminName = $data['admin_name'] ?? 'Admin';

        if ($action === 'delete') {
            $info = $this->db->prepare("SELECT user_id, origin, destination FROM freights WHERE id = ?");
            $info->execute([$id]);
            $f = $info->fetch();
            
            $res = $this->db->prepare("DELETE FROM freights WHERE id = ?")->execute([$id]);
            if ($res && $f) {
                (new NotificationController($this->db))->notify($f['user_id'], "Anúncio Removido", "Um de seus fretes foi removido pela moderação.");
            }

            $this->saveLog($adminId, $adminName, 'DELETE', "Excluiu frete #$id", $id, 'FREIGHT');
            return ["success" => true];
        }

        if ($action === 'toggle-featured') {
            $val = $data['featured'];
            $this->db->prepare("UPDATE freights SET is_featured = ?, requested_featured = 0 WHERE id = ?")->execute([$val, $id]);
            return ["success" => true];
        }
        return ["success" => false];
    }

    private function listAllFreights() {
        return $this->db->query("SELECT f.*, u.name as company_name FROM freights f 
                                LEFT JOIN users u ON f.user_id = u.id ORDER BY f.created_at DESC")->fetchAll();
    }

    private function managePlans($data) {
        $action = $data['action'] ?? '';
        if ($action === 'save') {
            if (isset($data['id']) && $data['id'] > 0) {
                $sql = "UPDATE plans SET name=?, type=?, price=?, duration_days=?, description=? WHERE id=?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$data['name'], $data['type'], $data['price'], $data['duration_days'], $data['description'] ?? '', $data['id']]);
            } else {
                $sql = "INSERT INTO plans (name, type, price, duration_days, description, active) VALUES (?, ?, ?, ?, ?, 1)";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$data['name'], $data['type'], $data['price'], $data['duration_days'], $data['description'] ?? '']);
            }
        } elseif ($action === 'delete') {
            $this->db->prepare("UPDATE plans SET active = 0 WHERE id = ?")->execute([$data['id']]);
        }
        return ["success" => true];
    }

    private function updateSettings($data) {
        $this->db->beginTransaction();
        try {
            foreach ($data as $key => $value) {
                if ($key === 'plans' || $key === 'id') continue;
                $val = is_bool($value) ? ($value ? '1' : '0') : $value;
                $stmt = $this->db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) 
                                           ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $val, $val]);
            }
            $this->db->commit();
            return ["success" => true];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ["success" => false, "error" => $e->getMessage()];
        }
    }

    private function getSettings() {
        $settings = $this->db->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $plans = $this->db->query("SELECT * FROM plans WHERE active = 1 ORDER BY price ASC")->fetchAll();
        return ["settings" => $settings, "plans" => $plans];
    }

    private function getAuditLogs() {
        return $this->db->query("SELECT * FROM admin_actions_logs ORDER BY created_at DESC LIMIT 50")->fetchAll();
    }

    private function getAdminStats() {
        return [
            'total_freights' => $this->db->query("SELECT COUNT(*) FROM freights")->fetchColumn(),
            'total_users'    => $this->db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'companies'      => $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'company'")->fetchColumn(),
            'drivers'        => $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'driver'")->fetchColumn(),
            'pending'        => $this->db->query("SELECT COUNT(*) FROM freights WHERE status = 'pending'")->fetchColumn()
        ];
    }

    private function getAds() {
        // Captura os dados via $_GET
        $position = $_GET['position'] ?? null;
        $search = $_GET['search'] ?? null;
        $city = $_GET['city'] ?? null;

        // CORREÇÃO: Unificamos a query para não sobrescrever a variável
        // Filtramos apenas anúncios ativos e que NÃO foram deletados
        $sql = "SELECT * FROM ads WHERE is_deleted = 0 AND is_active = 1";
        $params = [];

        if ($position) {
            $sql .= " AND position = ?";
            $params[] = $position;
        }

        // Lógica de busca e ordenação
        if ($search || $city) {
            $sql .= " ORDER BY 
                CASE 
                    WHEN title LIKE ? THEN 1 
                    WHEN description LIKE ? THEN 2
                    WHEN location_city LIKE ? THEN 3
                    ELSE 4 
                END ASC, RAND()";
            
            $searchTerm = "%" . ($search ?? '') . "%";
            $cityTerm = "%" . ($city ?? '') . "%";
            
            $params[] = $searchTerm; // Match title
            $params[] = $searchTerm; // Match description
            $params[] = $cityTerm;   // Match city
        } else {
            // Se não houver busca, apenas rotaciona os anúncios aleatoriamente
            $sql .= " ORDER BY RAND()";
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Opcional: Você pode tratar a URL aqui no PHP se preferir, 
            // mas como já fizemos o tratamento no React (getFullImageUrl), 
            // deixaremos o banco retornar o dado puro para manter a flexibilidade.
            return $ads;

        } catch (PDOException $e) {
            // Log de erro para debug se necessário
            return [];
        }
    }
    private function uploadAd($post, $files) {
        $id = $post['id'] ?? null;
        $imageUrl = $post['image_url'] ?? null; // URL externa vinda do campo de texto
        $fileName = null;

        // 1. Tratamento de Upload de Arquivo (se houver)
        if (isset($files['image']) && $files['image']['error'] === 0) {
            $uploadDir = __DIR__ . '/../uploads/ads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            $fileName = time() . '_' . basename($files['image']['name']);
            if (move_uploaded_file($files['image']['tmp_name'], $uploadDir . $fileName)) {
                // Salvamos apenas o caminho relativo para evitar quebra de links em outros domínios
                $imageUrl = 'uploads/ads/' . $fileName;
            }
        }

        // Validação para novos anúncios
        if (!$id && empty($imageUrl)) {
            return ["success" => false, "error" => "Imagem ou URL externa é obrigatória para novos anúncios"];
        }

        // 2. Lógica de Banco de Dados
        if ($id) {
            // --- EDIÇÃO ---
            // Só atualizamos a image_url se um novo arquivo foi subido OU se uma nova URL externa foi preenchida
            $sql = "UPDATE ads SET title=?, category=?, description=?, link_whatsapp=?, location_city=?, position=?";
            $params = [
                $post['title'], 
                $post['category'], 
                $post['description'], 
                $post['link_whatsapp'], 
                $post['location_city'], 
                $post['position']
            ];

            if (!empty($imageUrl)) {
                $sql .= ", image_url=?";
                $params[] = $imageUrl;
            }

            $sql .= " WHERE id=?";
            $params[] = $id;

            return ["success" => $this->db->prepare($sql)->execute($params)];
        } else {
            // --- NOVO ANÚNCIO ---
            $sql = "INSERT INTO ads (title, category, description, image_url, link_whatsapp, location_city, position, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
            
            $params = [
                $post['title'], 
                $post['category'], 
                $post['description'], 
                $imageUrl, 
                $post['link_whatsapp'], 
                $post['location_city'], 
                $post['position']
            ];

            return ["success" => $this->db->prepare($sql)->execute($params)];
        }
    }

    private function manageAds($data) {
        if ($data['action'] === 'delete') {
            $stmt = $this->db->prepare("UPDATE ads SET is_deleted = 1, is_active = 0 WHERE id = ?");
            return ["success" => $stmt->execute([$id])];
        }
        if ($data['action'] === 'toggle-status') {
            $stmt = $this->db->prepare("UPDATE ads SET is_active = NOT is_active WHERE id = ?");
            return ["success" => $stmt->execute([$data['id']])];
        }
        if ($data['action'] === 'increment-view') {
            $stmt = $this->db->prepare("UPDATE ads SET views_count = views_count + 1 WHERE id = ?");
            return ["success" => $stmt->execute([$data['id']])];
        }

        if ($data['action'] === 'increment-click') {
            $stmt = $this->db->prepare("UPDATE ads SET clicks_count = clicks_count + 1 WHERE id = ?");
            return ["success" => $stmt->execute([$data['id']])];
        }
    }

    // --- MÉTODOS DE APOIO: PORTAL REQUESTS ---

    /**
     * Lista todos os leads/pedidos vindos do modal do portal
     */

    private function storePortalRequest($data) {
        try {
            $sql = "INSERT INTO portal_requests (type, title, link, contact_info, status, description) 
                    VALUES (?, ?, ?, ?, 'pending', ?)";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $data['type'] ?? 'suggestion',
                $data['title'] ?? null,
                $data['link'] ?? null,
                $data['contact_info'] ?? null,
                $data['description'] ?? null
            ]);

            return ["success" => $success];
        } catch (Exception $e) {
            return ["success" => false, "error" => $e->getMessage()];
        }
    }

    private function getPortalRequests($data) {
        $status = $data['status'] ?? '%';
        $type = $data['type'] ?? '%';

        $sql = "SELECT * FROM portal_requests 
                WHERE status LIKE ? AND type LIKE ? 
                ORDER BY created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$status, $type]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Atualiza o status de uma solicitação (ex: de 'pending' para 'analyzed')
     */
    private function updatePortalRequest($data) {
        $id = $data['id'] ?? null;
        $status = $data['status'] ?? 'analyzed';
        $notes = $data['admin_notes'] ?? null;

        if (!$id) return ["success" => false, "error" => "ID necessário"];

        $sql = "UPDATE portal_requests SET status = ?, admin_notes = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $success = $stmt->execute([$status, $notes, $id]);

        return ["success" => $success];
    }

}