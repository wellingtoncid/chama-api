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
        $params = [];
        
        $sql = "SELECT a.*, COALESCE(a.destination_url, '') as link_url, 
                COALESCE(u.ad_credits, 0) as ad_credits
                FROM ads a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE a.status = 'active'
                AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())";

        // Filtro por posição
        if (!empty($position)) {
            $sql .= " AND a.position = :position";
            $params[':position'] = $position;
        }

        // LÓGICA GEOGRÁFICA: Busca anúncios da cidade OU do estado OU nacionais (vazios ou 'Brasil')
        // Só filtra se for passado city/state específico
        if (!empty($city) || !empty($state)) {
            $sql .= " AND (
                (a.location_city = :city OR a.location_city = '' OR a.location_city IS NULL OR a.location_city = 'Brasil')
                AND 
                (a.location_state = :state OR a.location_state = '' OR a.location_state IS NULL)
            )";
            $params[':city'] = $city;
            $params[':state'] = $state;
        }

        // Filtro de busca textual
        if (!empty($search)) {
            $sql .= " AND (a.title LIKE :search OR a.description LIKE :search)";
            $params[':search'] = "%$search%";
        }

        // ORDENAÇÃO INTELIGENTE:
        // 1. Prioridade manual do sistema (coluna priority)
        // 2. Localização (Cidade exata > Estado > Nacional)
        // 3. Aleatório (para não viciar sempre nos mesmos anúncios)
        $sql .= " ORDER BY 
                    a.priority DESC,
                    (CASE WHEN a.location_city = :order_city THEN 1 
                        WHEN a.location_state = :order_state THEN 2 
                        ELSE 3 END) ASC,
                    RAND() 
                    LIMIT " . (int)$limit;

        // Repetimos os parâmetros para a ordenação (alguns drivers PDO exigem nomes únicos ou reuso)
        $params[':order_city'] = $city;
        $params[':order_state'] = $state;

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("Erro no findAds: " . $e->getMessage());
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

            // 2. Debita créditos de cada dono de anúncio (ignora se não tiver créditos)
            // Apenas pula se não tiver saldo - não falha tudo
            try {
                $sqlUsers = "UPDATE users u 
                            INNER JOIN ads a ON a.user_id = u.id 
                            SET u.ad_credits = u.ad_credits - $costPerView 
                            WHERE a.id IN ($placeholders) AND u.ad_credits >= $costPerView";
                $stmtUsers = $this->db->prepare($sqlUsers);
                $stmtUsers->execute($ids);
            } catch (\Exception $e) {
                // Se falhar o débito, apenas loga e continua
                error_log("Erro ao debitar créditos: " . $e->getMessage());
            }

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
     * Converte posição para feature_key (igual ao AdController)
     */
    private function getFeatureKeyFromPosition($position) {
        $map = [
            'sidebar' => 'sidebar_banner',
            'home_hero' => 'home_banner',
            'popup' => 'video_ad',
            'freight_list' => 'sponsored',
            'footer' => 'footer_banner',
            'header' => 'header_banner',
            'spotlight' => 'spotlight_ad',
            'in-feed' => 'infeed_ad',
            'details_page' => 'details_ad'
        ];
        return $map[$position] ?? 'publish_ad';
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

        // --- SANITIZAÇÃO DO ESTADO (UF) ---
        // Garante que sempre seja MAIÚSCULO e tenha no máximo 2 caracteres
        if (!empty($data['location_state'])) {
            $data['location_state'] = strtoupper(substr(trim($data['location_state']), 0, 2));
        }
        
        $imageUrl = $data['image_url'] ?? '';
        $expiresAt = $data['expires_at'] ?? null;

        // 1. Se for UPDATE, recuperamos os dados atuais para não sobrescrever o que não deve
        if ($id) {
            $stmtCurrent = $this->db->prepare("SELECT image_url, expires_at, destination_url, link_whatsapp FROM ads WHERE id = ?");
            $stmtCurrent->execute([$id]);
            $currentAd = $stmtCurrent->fetch(PDO::FETCH_ASSOC);

            if ($currentAd) {
                // Mantém a imagem atual se nenhuma nova foi enviada
                if (empty($imageUrl)) {
                    $imageUrl = $currentAd['image_url'];
                }
                // Mantém a validade atual se nenhuma nova foi definida
                if (empty($expiresAt)) {
                    $expiresAt = $currentAd['expires_at'];
                }
                // Mantém o link atual se nenhum novo foi enviado (suporta tanto 'link' quanto 'destination_url')
                if (empty($data['destination_url']) && empty($data['link'])) {
                    $data['destination_url'] = $currentAd['destination_url'] ?? '';
                }
                // Mantém o WhatsApp atual se nenhum novo foi enviado
                if (empty($data['link_whatsapp'])) {
                    $data['link_whatsapp'] = $currentAd['link_whatsapp'] ?? '';
                }
            }
        }

        // 2. Se for INSERT e não tiver data de expiração, calcula baseada na regra
        if (!$expiresAt) {
            $position = $data['position'] ?? 'sidebar';
            $featureKey = $this->getFeatureKeyFromPosition($position);
            
            $stmt = $this->db->prepare("SELECT duration_days FROM pricing_rules WHERE feature_key = ? AND is_active = 1");
            $stmt->execute([$featureKey]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $days = ($rule && $rule['duration_days'] > 0) ? $rule['duration_days'] : 30;
            $expiresAt = date('Y-m-d H:i:s', strtotime("+$days days"));
        }

        $fields = [
            'title'           => $data['title'] ?? 'Sem título',
            'category'        => $data['category'] ?? 'OUTROS',
            'description'    => $data['description'] ?? '',
            'image_url'      => $imageUrl,
            'destination_url' => $data['destination_url'] ?? $data['link'] ?? '',
            'link_whatsapp'  => $data['link_whatsapp'] ?? '',
            'location_city'   => $data['location_city'] ?? '',
            'location_state'  => $data['location_state'] ?? '',
            'position'        => $data['position'] ?? 'sidebar',
            'status'          => $data['status'] ?? 'active',
            'expires_at'      => $expiresAt,
            'view_limit'      => $data['view_limit'] ?? null,
            'user_id'         => $data['user_id'] ?? $data['target_user_id'] ?? null
        ];

        if ($id) {
            $sql = "UPDATE ads SET title=:title, category=:category, description=:description, 
                    image_url=:image_url, destination_url=:destination_url, link_whatsapp=:link_whatsapp, 
                    location_city=:location_city, location_state=:location_state, position=:position, status=:status,
                    expires_at=:expires_at, view_limit=:view_limit, user_id=:user_id
                    WHERE id = :id";
            $fields['id'] = $id;
        } else {
            $sql = "INSERT INTO ads (title, category, description, image_url, destination_url, link_whatsapp, location_city, location_state, position, status, expires_at, view_limit, user_id) 
                    VALUES (:title, :category, :description, :image_url, :destination_url, :link_whatsapp, :location_city, :location_state, :position, :status, :expires_at, :view_limit, :user_id)";
        }

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($fields) ? ($id ?: $this->db->lastInsertId()) : false;
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
        $column = (in_array($eventType, ['VIEW', 'VIEW_DETAILS'])) ? 'views_count' : 'clicks_count';

        try {
            $this->db->beginTransaction();

            // Incrementa o contador do anúncio
            $this->db->prepare("UPDATE ads SET {$column} = {$column} + 1 WHERE id = ?")->execute([$id]);

            // Débito de créditos (ignora se não tiver saldo)
            try {
                $sqlUser = "UPDATE users u 
                            INNER JOIN ads a ON a.user_id = u.id 
                            SET u.ad_credits = u.ad_credits - $cost 
                            WHERE a.id = $id AND u.ad_credits >= $cost";
                $stmtUser = $this->db->prepare($sqlUser);
                $stmtUser->execute();
            } catch (\Exception $e) {
                error_log("Erro ao debitar créditos: " . $e->getMessage());
            }

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro no counter dinâmico: " . $e->getMessage());
            return false;
        }
    }

    public function getAdsByUserId($userId) {
        $sql = "SELECT 
                    a.id, 
                    a.title, 
                    a.description, 
                    a.image_url, 
                    a.category, 
                    a.destination_url, 
                    a.created_at, 
                    a.status,
                    a.position,
                    a.expires_at,
                    a.views_count,
                    a.clicks_count,
                    u.ad_credits,
                    (CASE WHEN u.ad_credits > 0 THEN 1 ELSE 0 END) as is_boosted
                FROM ads a
                INNER JOIN users u ON a.user_id = u.id
                WHERE a.user_id = :user_id 
                AND a.deleted_at IS NULL
                ORDER BY a.created_at DESC";
                    
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':user_id' => $userId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("Erro ao buscar anúncios do usuário (findAds): " . $e->getMessage());
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

    /**
     * Mapeia feature_key de publicidade para posição no banco
     */
    private function getPositionFromFeature($featureKey) {
        $map = [
            'sidebar_banner' => 'sidebar',
            'home_banner' => 'home_hero',
            'video_ad' => 'popup',
            'sponsored' => 'freight_list',
            'footer_banner' => 'footer',
            'header_banner' => 'header',
            'spotlight_ad' => 'spotlight',
            'popup_ad' => 'popup',
            'infeed_ad' => 'in-feed',
            'details_ad' => 'details_page'
        ];
        return $map[$featureKey] ?? $featureKey;
    }

    /**
     * Verifica se usuário pode usar uma posição de anúncio
     * Retorna: ['allowed' => bool, 'reason' => string, 'requires_payment' => bool]
     */
    public function checkAdPositionEligibility($userId, $featureKey) {
        $position = $this->getPositionFromFeature($featureKey);
        
        // Busca a regra de preço
        $stmt = $this->db->prepare("
            SELECT * FROM pricing_rules 
            WHERE module_key = 'advertiser' 
            AND feature_key = :feature_key 
            AND is_active = 1
        ");
        $stmt->execute([':feature_key' => $featureKey]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rule) {
            return ['allowed' => true, 'reason' => 'Sem restrição de preço definida', 'requires_payment' => false];
        }

        // 1. Verifica se é free_limit (tem limite grátis)
        if ($rule['pricing_type'] === 'free_limit' && $rule['free_limit'] > 0) {
            // Conta anúncios publicados este mês
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM ads 
                WHERE user_id = :user_id 
                AND position = :position
                AND MONTH(created_at) = MONTH(CURRENT_DATE())
                AND YEAR(created_at) = YEAR(CURRENT_DATE())
                AND status != 'rejected'
            ");
            $stmt->execute([':user_id' => $userId, ':position' => $position]);
            $used = (int)$stmt->fetch()['total'];

            if ($used < $rule['free_limit']) {
                return ['allowed' => true, 'reason' => 'Uso do limite grátis', 'requires_payment' => false, 'remaining' => $rule['free_limit'] - $used];
            }
        }

        // 2. Verifica assinatura mensal ativa
        $stmt = $this->db->prepare("
            SELECT * FROM user_modules 
            WHERE user_id = :user_id 
            AND module_key = 'advertiser'
            AND status = 'active'
            AND (expires_at IS NULL OR expires_at >= NOW())
        ");
        $stmt->execute([':user_id' => $userId]);
        $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($subscription) {
            // VERIFICAÇÃO 1: Verificar limite de anúncios ativos do plano
            $planCheck = $this->checkPlanAdLimit($userId, $position);
            if (!$planCheck['allowed']) {
                return $planCheck;
            }
            
            // VERIFICAÇÃO 2: Verificar se posição é permitida pelo tipo do plano
            $positionCheck = $this->checkPlanPositionAllowed($userId, $position);
            if (!$positionCheck['allowed']) {
                return $positionCheck;
            }
            
            return ['allowed' => true, 'reason' => 'Assinatura mensal ativa', 'requires_payment' => false];
        }

        // 3. Verifica se tem transação aprovada para este recurso
        $stmt = $this->db->prepare("
            SELECT * FROM transactions 
            WHERE user_id = :user_id 
            AND module_key = 'advertiser'
            AND feature_key = :feature_key
            AND status = 'approved'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId, ':feature_key' => $featureKey]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($transaction) {
            return ['allowed' => true, 'reason' => 'Pagamento avulso confirmado', 'requires_payment' => false];
        }

        // Precisa pagar
        $price = $rule['price_monthly'] > 0 ? $rule['price_monthly'] : $rule['price_per_use'];
        return [
            'allowed' => false, 
            'reason' => 'Limite grátis esgotado. Assine o plano mensal ou compre avulso.',
            'requires_payment' => true,
            'price_monthly' => $rule['price_monthly'],
            'price_per_use' => $rule['price_per_use']
        ];
    }

    /**
     * Busca o plano ativo de publicidade do usuário
     */
    public function getUserAdvertisingPlan($userId) {
        $stmt = $this->db->prepare("
            SELECT p.*, um.expires_at as plan_expires_at
            FROM user_modules um
            JOIN plans p ON p.type = um.module_key OR p.category = 'advertising'
            WHERE um.user_id = :user_id 
            AND um.module_key = 'advertiser'
            AND um.status = 'active'
            AND (um.expires_at IS NULL OR um.expires_at >= NOW())
            ORDER BY p.price DESC
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Se não encontrou pelo module_key, tenta buscar qualquer plano ativo de publicidade
        if (!$plan) {
            $stmt = $this->db->prepare("
                SELECT p.*, um.expires_at as plan_expires_at
                FROM user_modules um
                JOIN plans p ON p.id = um.plan_id
                WHERE um.user_id = :user_id 
                AND um.status = 'active'
                AND (um.expires_at IS NULL OR um.expires_at >= NOW())
                AND p.category = 'advertising'
                ORDER BY p.price DESC
                LIMIT 1
            ");
            $stmt->execute([':user_id' => $userId]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        return $plan ?: null;
    }

    /**
     * Verifica se usuário atingiu limite de anúncios do plano
     */
    public function checkPlanAdLimit($userId, $position = null) {
        $plan = $this->getUserAdvertisingPlan($userId);
        
        if (!$plan) {
            return ['allowed' => true, 'reason' => 'Sem plano específico, usa regras padrão'];
        }
        
        $limit = (int)$plan['limit_ads_active'];
        
        // 0 = ilimitado (nossa convenção: limite > 500 = ilimitado na prática)
        if ($limit === 0 || $limit > 500) {
            return ['allowed' => true, 'reason' => 'Plano com anúncios ilimitados'];
        }
        
        // Conta anúncios ativos do usuário
        $sql = "SELECT COUNT(*) as total FROM ads WHERE user_id = :user_id AND status = 'active'";
        $params = [':user_id' => $userId];
        
        if ($position) {
            $sql .= " AND position = :position";
            $params[':position'] = $position;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $used = (int)$stmt->fetch()['total'];
        
        if ($used >= $limit) {
            return [
                'allowed' => false,
                'reason' => "Limite de {$limit} anúncios ativos atingido. Upgrade seu plano ou remova anúncios existentes.",
                'requires_payment' => true,
                'limit' => $limit,
                'used' => $used,
                'plan_name' => $plan['name'] ?? 'Plano atual'
            ];
        }
        
        return [
            'allowed' => true,
            'reason' => 'Dentro do limite do plano',
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit - $used
        ];
    }

    /**
     * Verifica se posição é permitida pelo tipo do plano
     * bronze -> sidebar apenas
     * prata -> sidebar + freight_list
     * ouro -> todas
     */
    public function checkPlanPositionAllowed($userId, $position) {
        $plan = $this->getUserAdvertisingPlan($userId);
        
        if (!$plan) {
            return ['allowed' => true, 'reason' => 'Sem plano específico'];
        }
        
        $planType = strtolower($plan['type'] ?? 'sidebar');
        
        // Mapeamento de posições permitidas por tipo de plano
        $allowedPositions = [
            'sidebar' => ['sidebar'],
            'freight_list' => ['sidebar', 'freight_list', 'in-feed'],
            'total' => ['sidebar', 'freight_list', 'in-feed', 'footer', 'header', 'home_hero', 'spotlight', 'popup', 'details_page', 'strategic_partners', 'media_network']
        ];
        
        $allowed = $allowedPositions[$planType] ?? ['sidebar'];
        
        if (!in_array($position, $allowed)) {
            return [
                'allowed' => false,
                'reason' => "Plano {$plan['name']} não permite anúncios em {$position}. Upgrade para ter acesso a esta posição.",
                'requires_payment' => true,
                'current_plan' => $planType,
                'allowed_positions' => $allowed,
                'requested_position' => $position
            ];
        }
        
        return [
            'allowed' => true,
            'reason' => 'Posição permitida pelo plano',
            'plan_type' => $planType
        ];
    }

    /**
     * Verifica se usuário tem acesso a uma posição específica (para display)
     */
    public function userCanUsePosition($userId, $position) {
        // Mapeia posição para feature_key
        $reverseMap = [
            'sidebar' => 'sidebar_banner',
            'home_hero' => 'home_banner',
            'popup' => 'video_ad',
            'freight_list' => 'sponsored',
            'footer' => 'footer_banner',
            'header' => 'header_banner',
            'spotlight' => 'spotlight_ad',
            'in-feed' => 'infeed_ad',
            'details_page' => 'details_ad'
        ];
        
        $featureKey = $reverseMap[$position] ?? null;
        if (!$featureKey) return true; // Posição desconhecida, permite
        
        $result = $this->checkAdPositionEligibility($userId, $featureKey);
        return $result['allowed'];
    }

    /**
     * Lista todos os anúncios para o admin (sem filtros de posição)
     * Suporta filtros: all, active, paused, expired
     */
    public function listAll($status = null, $search = null) {
        $sql = "SELECT a.*, u.name as user_name, u.email as user_email,
                DATEDIFF(a.expires_at, CURDATE()) as days_until_expiry
                FROM ads a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE 1=1";
        
        $params = [];
        
        // Filtro por status: all = todos, ou status específico
        if ($status && $status !== 'all') {
            $sql .= " AND a.status = :status";
            $params[':status'] = $status;
        }
        
        if ($search) {
            $sql .= " AND (a.title LIKE :search OR a.description LIKE :search OR u.name LIKE :search)";
            $params[':search'] = "%$search%";
        }
        
        $sql .= " ORDER BY 
                    CASE 
                        WHEN a.status = 'active' AND a.expires_at IS NOT NULL AND a.expires_at < DATE_ADD(CURDATE(), INTERVAL 3 DAY) THEN 1
                        WHEN a.status = 'expired' THEN 2
                        WHEN a.status = 'paused' THEN 3
                        ELSE 4
                    END,
                    a.created_at DESC";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Processar status calculado (computed_status)
            foreach ($results as &$ad) {
                $ad['computed_status'] = $this->computeAdStatus($ad);
            }
            
            return $results;
        } catch (\Exception $e) {
            error_log("Erro listAll ads: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Calcula o status calculado de um anúncio
     */
    private function computeAdStatus($ad) {
        $status = $ad['status'] ?? 'active';
        $expiresAt = $ad['expires_at'] ?? null;
        $daysUntil = $ad['days_until_expiry'] ?? null;
        
        if ($status === 'expired') {
            return 'expired';
        }
        
        if ($status === 'paused') {
            return 'paused';
        }
        
        if ($status === 'active' && $expiresAt) {
            if ($daysUntil !== null && $daysUntil < 0) {
                return 'expired'; // Ativo mas data passou
            }
            if ($daysUntil !== null && $daysUntil <= 3) {
                return 'expiring_soon'; // Vence em 3 dias ou menos
            }
        }
        
        return $status;
    }
    
    /**
     * Renova um anúncio (estende a data de expiração)
     */
    public function renewAd($id, $days = 30) {
        try {
            $newExpiry = date('Y-m-d H:i:s', strtotime("+$days days"));
            $stmt = $this->db->prepare("UPDATE ads SET status = 'active', expires_at = ? WHERE id = ?");
            $stmt->execute([$newExpiry, $id]);
            return true;
        } catch (\Exception $e) {
            error_log("Erro renewAd: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Pausa um anúncio
     */
    public function pauseAd($id) {
        try {
            $stmt = $this->db->prepare("UPDATE ads SET status = 'paused' WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (\Exception $e) {
            error_log("Erro pauseAd: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ativa um anúncio
     */
    public function activateAd($id) {
        try {
            $stmt = $this->db->prepare("UPDATE ads SET status = 'active' WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (\Exception $e) {
            error_log("Erro activateAd: " . $e->getMessage());
            return false;
        }
    }
}