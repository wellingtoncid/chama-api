<?php

namespace App\Repositories;

use App\Core\Auth;
use PDO;

class AdRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Busca inteligente com prioridade geográfica, fallback global
     * e filtro obrigatório de saldo de créditos.
     */
    public function findAds($position = '', $state = '', $search = '', $city = '', $limit = 10)
    {
        $this->deactivateExpiredPlanAds();
        $this->expireOverdueAds();
        $params = [];

        $sql = "SELECT a.*,
                COALESCE(a.destination_url, '') as link_url,
                COALESCE(u.ad_credits, 0) as ad_credits,
                u.name as advertiser_name,
                u.is_verified as advertiser_verified,
                p.advertiser_tier,
                p.name as plan_name
                FROM ads a
                LEFT JOIN users u ON a.user_id = u.id
                LEFT JOIN user_modules um ON a.user_id = um.user_id AND um.module_key = 'advertiser' AND um.status = 'active'
                LEFT JOIN plans p ON um.plan_id = p.id
                WHERE a.status = 'active'
                AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())";

        // Filtro por posição
        if (!empty($position)) {
            $sql .= ' AND a.position = :position';
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
            $sql .= ' AND (a.title LIKE :search OR a.description LIKE :search)';
            $params[':search'] = "%$search%";
        }

        // ORDENAÇÃO INTELIGENTE:
        // 1. Plano do anunciante (sponsor_master > maintainer_premium > supporter_connect > sem plano)
        // 2. Prioridade manual do sistema (coluna priority)
        // 3. Localização (Cidade exata > Estado > Nacional)
        // 4. Aleatório (para não viciar sempre nos mesmos anúncios)
        $sql .= ' ORDER BY
                    CASE p.advertiser_tier
                        WHEN \'sponsor_master\' THEN 0
                        WHEN \'maintainer_premium\' THEN 1
                        WHEN \'supporter_connect\' THEN 2
                        ELSE 3
                    END ASC,
                    a.priority DESC,
                    (CASE WHEN a.location_city = :order_city THEN 1
                        WHEN a.location_state = :order_state THEN 2
                        ELSE 3 END) ASC,
                    RAND()
                    LIMIT ' . (int)$limit;

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
            error_log('Erro no findAds: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Incrementa visualizações em massa (Usado no AdController@list)
     * Agora também debita créditos proporcionalmente.
     */
    public function incrementViews(array $ids)
    {
        if (empty($ids)) {
            return false;
        }

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
                error_log('Erro ao debitar créditos: ' . $e->getMessage());
            }

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Erro no incrementViews em massa: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Incrementa cliques e gera histórico temporal.
     */
    public function incrementClick($id)
    {
        try {
            $this->db->beginTransaction();
            $this->db->prepare('UPDATE ads SET clicks_count = clicks_count + 1 WHERE id = ?')->execute([$id]);
            $this->db->prepare("INSERT INTO ads_stats (ad_id, type) VALUES (?, 'click')")->execute([$id]);
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Converte position para feature_key (usado em verificações de preço)
     * Agora position e feature_key são iguais
     */
    private function getFeatureKeyFromPosition($position)
    {
        return $position;
    }

    /**
     * ESSENCIAL PARA MONETIZAÇÃO: Busca dados para o gráfico de performance.
     */
    public function getPerformanceReport($adId, $days = 30)
    {
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

    public function save($data)
    {
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
            $stmtCurrent = $this->db->prepare('SELECT image_url, expires_at, destination_url, link_whatsapp FROM ads WHERE id = ?');
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

            $stmt = $this->db->prepare('SELECT duration_days FROM pricing_rules WHERE feature_key = ? AND is_active = 1');
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
            'user_id'         => $data['user_id'] ?? $data['target_user_id'] ?? null,
        ];

        if ($id) {
            $sql = 'UPDATE ads SET title=:title, category=:category, description=:description,
                    image_url=:image_url, destination_url=:destination_url, link_whatsapp=:link_whatsapp,
                    location_city=:location_city, location_state=:location_state, position=:position, status=:status,
                    expires_at=:expires_at, view_limit=:view_limit, user_id=:user_id
                    WHERE id = :id';
            $fields['id'] = $id;
        } else {
            $sql = 'INSERT INTO ads (title, category, description, image_url, destination_url, link_whatsapp, location_city, location_state, position, status, expires_at, view_limit, user_id)
                    VALUES (:title, :category, :description, :image_url, :destination_url, :link_whatsapp, :location_city, :location_state, :position, :status, :expires_at, :view_limit, :user_id)';
        }

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($fields) ? ($id ?: $this->db->lastInsertId()) : false;
    }

    /**
     * Incrementa eventos individuais (CLIQUES, WHATSAPP, etc)
     * Mantém a funcionalidade original de mapeamento de colunas.
     */
    public function incrementCounter($id, $eventType)
    {
        // 1. Busca os custos configurados no banco (site_settings)
        $sqlSettings = "SELECT setting_key, setting_value FROM site_settings WHERE category = 'advertising'";
        $stmtSettings = $this->db->query($sqlSettings);
        $settings = $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR);

        // Mapeamento dinâmico baseado no banco de dados
        $costs = [
            'VIEW'           => (int)($settings['ad_cost_view'] ?? 1),
            'VIEW_DETAILS'   => (int)($settings['ad_cost_view_details'] ?? 2),
            'CLICK'          => (int)($settings['ad_cost_click'] ?? 10),
            'WHATSAPP_CLICK' => (int)($settings['ad_cost_whatsapp'] ?? 15),
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
                error_log('Erro ao debitar créditos: ' . $e->getMessage());
            }

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Erro no counter dinâmico: ' . $e->getMessage());
            return false;
        }
    }

    public function getAdsByUserId($userId)
    {
        $this->deactivateExpiredPlanAds();
        $this->expireOverdueAds($userId);

        $sql = 'SELECT
                    a.id,
                    a.title,
                    a.description,
                    a.image_url,
                    a.category,
                    a.destination_url,
                    a.link_whatsapp,
                    a.location_city,
                    a.location_state,
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
                ORDER BY a.created_at DESC';

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':user_id' => $userId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log('Erro ao buscar anúncios do usuário (findAds): ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Busca pacotes de créditos ativos no sistema
     */
    public function getPackages()
    {
        return $this->db->query('SELECT * FROM ad_packages WHERE active = 1 ORDER BY price ASC')
                        ->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Mapeia feature_key para position (mantido para compatibilidade)
     * Agora ambos usam o mesmo valor, mapeamento direto
     */
    private function getPositionFromFeature($featureKey)
    {
        return $featureKey;
    }

    /**
     * Verifica se usuário pode usar uma posição de anúncio
     * Retorna: ['allowed' => bool, 'reason' => string, 'requires_payment' => bool]
     */
    public function checkAdPositionEligibility($userId, $featureKey)
    {
        // Equipe interna pode criar anúncios sem restrições
        $stmt = $this->db->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && Auth::isInternal($user['role'])) {
            return ['allowed' => true, 'reason' => 'Equipe interna - sem custo', 'requires_payment' => false];
        }

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

        // Se não houver regra, permite criar (sem custo)
        if (!$rule) {
            return ['allowed' => true, 'reason' => 'Sem restrição de preço definida', 'requires_payment' => false];
        }

        // Se a regra é free_limit com limite disponível, permite
        if ($rule['pricing_type'] === 'free_limit' && $rule['free_limit'] > 0) {
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

        // --- VERIFICAÇÕES OBRIGATÓRIAS PARA QUALQUER TIPO DE PREÇO ---

        // 1. Verifica assinatura mensal ativa do módulo advertiser
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
            // Verificar limite de anúncios ativos do plano
            $planCheck = $this->checkPlanAdLimit($userId, $position);
            if (!$planCheck['allowed']) {
                return $planCheck;
            }

            // Verificar se posição é permitida pelo tipo do plano
            $positionCheck = $this->checkPlanPositionAllowed($userId, $position);
            if (!$positionCheck['allowed']) {
                return $positionCheck;
            }

            return ['allowed' => true, 'reason' => 'Assinatura mensal ativa', 'requires_payment' => false];
        }

        // 2. Verifica se tem transação aprovada para este recurso nos últimos 30 dias
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

        // --- NENHUMA CONDIÇÃO ATENDIDA: EXIGE PAGAMENTO ---
        $price = $rule['price_monthly'] > 0 ? $rule['price_monthly'] : $rule['price_per_use'];
        return [
            'allowed' => false,
            'reason' => 'Você precisa de um plano ativo ou pagamento avulso para criar anúncios nesta posição.',
            'requires_payment' => true,
            'price_monthly' => $rule['price_monthly'],
            'price_per_use' => $rule['price_per_use'],
            'feature_name' => $rule['feature_name'] ?? '',
        ];
    }

    /**
     * Busca o plano ativo de publicidade do usuário
     */
    public function getUserAdvertisingPlan($userId)
    {
        $stmt = $this->db->prepare("
            SELECT p.*, um.expires_at as plan_expires_at, um.created_at as plan_created_at
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
                SELECT p.*, um.expires_at as plan_expires_at, um.created_at as plan_created_at
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
     * Suporta position_limits do features JSON para controle por posição
     * Conta anúncios criados no período de faturamento (desde a contratação)
     */
    public function checkPlanAdLimit($userId, $position = null)
    {
        $plan = $this->getUserAdvertisingPlan($userId);

        if (!$plan) {
            return ['allowed' => true, 'reason' => 'Sem plano específico, usa regras padrão'];
        }

        // Check for per-position limit in features JSON
        $perPositionLimit = null;
        if ($position && !empty($plan['features'])) {
            $features = is_string($plan['features']) ? json_decode($plan['features'], true) : $plan['features'];
            if (is_array($features)) {
                $hasPositionLimits = isset($features['position_limits']) && is_array($features['position_limits']);
                $inPositions = isset($features['positions']) && is_array($features['positions']) && in_array($position, $features['positions']);

                if ($hasPositionLimits && isset($features['position_limits'][$position])) {
                    $perPositionLimit = (int)$features['position_limits'][$position];
                } elseif ($inPositions) {
                    $perPositionLimit = 1; // Default: 1 per billing period
                }
            }
        }

        $limit = $perPositionLimit !== null ? $perPositionLimit : (int)$plan['limit_monthly'];

        // 0 = ilimitado
        if ($limit === 0 || $limit > 500) {
            return ['allowed' => true, 'reason' => 'Plano com anúncios ilimitados'];
        }

        // Determina início do período de faturamento atual
        $durationDays = (int)($plan['duration_days'] ?? 30);
        if (!empty($plan['plan_expires_at'])) {
            $periodStart = date('Y-m-d H:i:s', strtotime("-{$durationDays} days", strtotime($plan['plan_expires_at'])));
        } elseif (!empty($plan['plan_created_at'])) {
            // Sem expiração: conta desde a criação da assinatura
            $periodStart = $plan['plan_created_at'];
        } else {
            // Fallback: últimos 30 dias
            $periodStart = date('Y-m-d H:i:s', strtotime('-30 days'));
        }

        // Conta anúncios criados no período de faturamento (exclui soft-deleted)
        $sql = "SELECT COUNT(*) as total FROM ads WHERE user_id = :user_id AND deleted_at IS NULL AND created_at >= :period_start";
        $params = [':user_id' => $userId, ':period_start' => $periodStart];

        if ($position) {
            $sql .= ' AND position = :position';
            $params[':position'] = $position;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $used = (int)$stmt->fetch()['total'];

        if ($used >= $limit) {
            return [
                'allowed' => false,
                'reason' => $perPositionLimit !== null
                    ? "Limite de {$limit} anúncio(s) neste período para esta posição. Remova anúncios existentes ou aguarde o próximo ciclo."
                    : "Limite de {$limit} anúncios ativos atingido. Upgrade seu plano ou remova anúncios existentes.",
                'requires_payment' => false,
                'limit' => $limit,
                'used' => $used,
                'remaining' => max(0, $limit - $used),
                'period_start' => $periodStart,
                'plan_name' => $plan['name'] ?? 'Plano atual',
            ];
        }

        return [
            'allowed' => true,
            'reason' => 'Dentro do limite do plano',
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'period_start' => $periodStart,
            'plan_name' => $plan['name'] ?? 'Plano atual',
        ];
    }

    /**
     * Verifica se posição é permitida pelo plano
     * Lê de features.positions (JSON) primeiro, fallback para mapeamento por type
     * supporter_connect -> sidebar apenas
     * maintainer_premium -> sidebar + freight_list + infeed_wide + marketplace_list + groups_list
     * sponsor_master -> todas
     */
    public function checkPlanPositionAllowed($userId, $position)
    {
        $plan = $this->getUserAdvertisingPlan($userId);

        if (!$plan) {
            return ['allowed' => true, 'reason' => 'Sem plano específico'];
        }

        // Tenta ler posições do JSON features.positions
        $allowed = null;
        if (!empty($plan['features'])) {
            $features = is_string($plan['features']) ? json_decode($plan['features'], true) : $plan['features'];
            if (is_array($features) && isset($features['positions']) && is_array($features['positions'])) {
                $allowed = $features['positions'];
            }
        }

        // Fallback: mapeamento por tipo do plano (backward compatibility)
        if ($allowed === null) {
            $planType = strtolower($plan['type'] ?? 'sidebar');
            $allowedPositions = [
                'sidebar' => ['sidebar'],
                'freight_list' => ['sidebar', 'freight_list', 'infeed_wide', 'marketplace_list', 'groups_list'],
                'total' => ['sidebar', 'freight_list', 'infeed_wide', 'marketplace_list', 'groups_list', 'footer', 'spotlight', 'chat_header', 'popup', 'header'],
            ];
            $allowed = $allowedPositions[$planType] ?? ['sidebar'];
        }

        if (!in_array($position, $allowed)) {
            return [
                'allowed' => false,
                'reason' => "Plano {$plan['name']} não permite anúncios em {$position}. Upgrade para ter acesso a esta posição.",
                'requires_payment' => true,
                'current_plan' => $plan['type'] ?? 'sidebar',
                'allowed_positions' => $allowed,
                'requested_position' => $position,
            ];
        }

        return [
            'allowed' => true,
            'reason' => 'Posição permitida pelo plano',
            'plan_type' => $plan['type'] ?? 'sidebar',
        ];
    }

    /**
     * Verifica se usuário tem acesso a uma posição específica (para display)
     * Agora position e feature_key são o mesmo valor
     */
    public function userCanUsePosition($userId, $position)
    {
        $result = $this->checkAdPositionEligibility($userId, $position);
        return $result['allowed'];
    }

    /**
     * Lista todos os anúncios para o admin (sem filtros de posição)
     * Suporta filtros: all, active, paused, expired
     */
    public function listAll($status = null, $search = null)
    {
        $sql = 'SELECT a.*, u.name as user_name, u.email as user_email,
                DATEDIFF(a.expires_at, CURDATE()) as days_until_expiry
                FROM ads a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE 1=1';

        $params = [];

        // Filtro por status: all = todos, ou status específico
        if ($status && $status !== 'all') {
            $sql .= ' AND a.status = :status';
            $params[':status'] = $status;
        }

        if ($search) {
            $sql .= ' AND (a.title LIKE :search OR a.description LIKE :search OR u.name LIKE :search)';
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
            error_log('Erro listAll ads: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Calcula o status calculado de um anúncio
     */
    private function computeAdStatus($ad)
    {
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
    public function renewAd($id, $days = 30)
    {
        try {
            $newExpiry = date('Y-m-d H:i:s', strtotime("+$days days"));
            $stmt = $this->db->prepare("UPDATE ads SET status = 'active', expires_at = ? WHERE id = ?");
            $stmt->execute([$newExpiry, $id]);
            return true;
        } catch (\Exception $e) {
            error_log('Erro renewAd: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Pausa um anúncio
     */
    public function pauseAd($id)
    {
        try {
            $stmt = $this->db->prepare("UPDATE ads SET status = 'paused' WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (\Exception $e) {
            error_log('Erro pauseAd: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Ativa um anúncio
     */
    public function activateAd($id)
    {
        try {
            $stmt = $this->db->prepare("UPDATE ads SET status = 'active' WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (\Exception $e) {
            error_log('Erro activateAd: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Desativa anúncios de usuários com planos de publicidade expirados
     * Retorna o número de anúncios desativados
     */
    public function deactivateExpiredPlanAds(): int
    {
        try {
            // Busca usuários com user_modules advertiser expirados
            $stmt = $this->db->query("
                SELECT um.user_id
                FROM user_modules um
                WHERE um.module_key = 'advertiser'
                AND um.status = 'active'
                AND um.expires_at IS NOT NULL
                AND um.expires_at < NOW()
            ");
            $expiredUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($expiredUsers)) {
                return 0;
            }

            $userIds = array_column($expiredUsers, 'user_id');
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));

            // Desativa anúncios ativos desses usuários
            $stmt = $this->db->prepare("
                UPDATE ads
                SET status = 'expired', updated_at = NOW()
                WHERE user_id IN ($placeholders)
                AND status = 'active'
            ");
            $stmt->execute($userIds);
            $deactivatedAds = $stmt->rowCount();

            // Marca user_modules como expired
            $stmt = $this->db->prepare("
                UPDATE user_modules
                SET status = 'expired', updated_at = NOW()
                WHERE module_key = 'advertiser'
                AND status = 'active'
                AND expires_at IS NOT NULL
                AND expires_at < NOW()
            ");
            $stmt->execute();

            if ($deactivatedAds > 0) {
                error_log("Auto-expirou {$deactivatedAds} anúncios de " . count($userIds) . " usuários com planos expirados.");
            }

            return $deactivatedAds;
        } catch (\Exception $e) {
            error_log('Erro deactivateExpiredPlanAds: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Expira anúncios individuais cujo expires_at já passou
     */
    public function expireOverdueAds($userId = null): int
    {
        try {
            $sql = "UPDATE ads SET status = 'expired', updated_at = NOW()
                    WHERE status = 'active'
                    AND expires_at IS NOT NULL
                    AND expires_at < NOW()
                    AND deleted_at IS NULL";
            $params = [];

            if ($userId !== null) {
                $sql .= ' AND user_id = :user_id';
                $params[':user_id'] = $userId;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $expired = $stmt->rowCount();

            if ($expired > 0) {
                error_log("Expirou {$expired} anúncios por vencimento de expires_at" . ($userId ? " (user {$userId})" : ''));
            }

            return $expired;
        } catch (\Exception $e) {
            error_log('Erro expireOverdueAds: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Define prioridade manual de um anúncio
     */
    public function setPriority($id, $priority)
    {
        try {
            $stmt = $this->db->prepare("UPDATE ads SET priority = ? WHERE id = ?");
            $stmt->execute([(int)$priority, (int)$id]);
            return true;
        } catch (\Exception $e) {
            error_log('Erro setPriority: ' . $e->getMessage());
            return false;
        }
    }
}
