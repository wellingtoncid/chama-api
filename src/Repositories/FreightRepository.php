<?php
namespace App\Repositories;

use PDO;

class FreightRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Listagem com filtros avançados e busca textual
     */
    public function listPaginated($userId, $filters = [], $page = 1, $perPage = 15) {
        try {
            $this->db->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);

            $search = isset($filters['search']) ? trim((string)$filters['search']) : '';
            $viewerId = isset($filters['viewer_id']) ? (int)$filters['viewer_id'] : 0;
            $page = (int)$page;
            $perPage = (int)$perPage;
            $offset = (max(1, (int)$page) - 1) * (int)$perPage; 
            
            $params = [];
            $accountId = $filters['account_id'] ?? null;
            $where = " WHERE f.deleted_at IS NULL AND f.status = 'OPEN'";

            // 1. Filtros de Escopo
            if ($accountId) {
                $where .= " AND f.account_id = :account_id";
                $params[':account_id'] = $accountId;
            } 
            elseif ($userId !== null && (int)$userId > 0) {
                $where .= " AND f.user_id = :u_id";
                $params[':u_id'] = (int)$userId;
            }

            // 2. Filtro de busca inteligente
            if ($search !== "") {
                $words = explode(' ', $search);
                $smartSearch = '';
                foreach ($words as $word) {
                    $word = trim($word);
                    if (strlen($word) >= 2) $smartSearch .= "+{$word}* ";
                }
                $smartSearch = trim($smartSearch);

                if (!empty($smartSearch)) {
                    $where .= " AND (
                        MATCH(f.product, f.origin_city, f.origin_state, f.dest_city, f.dest_state, f.vehicle_type, f.body_type, f.category, f.description) 
                        AGAINST(:term IN BOOLEAN MODE)
                        OR f.product LIKE :term_like 
                        OR f.origin_city LIKE :term_like 
                        OR u.name LIKE :term_like
                        OR a.trade_name LIKE :term_like
                    )";
                    $params[':term'] = $smartSearch;
                    $params[':term_like'] = "%$search%";
                } else {
                    $where .= " AND (f.product LIKE :term_like OR f.origin_state LIKE :term_like OR f.dest_state LIKE :term_like)";
                    $params[':term_like'] = "%$search%";
                }
            }

            $viewerId = (int)($filters['viewer_id'] ?? 0);
            $params[':fav_id'] = $viewerId;

            // 3. Contagem Total (Trocado companies por accounts)
            $sqlCount = "SELECT COUNT(DISTINCT f.id) 
                        FROM freights f 
                        LEFT JOIN users u ON f.user_id = u.id 
                        LEFT JOIN accounts a ON u.account_id = a.id
                        $where";
            
            $stmtCount = $this->db->prepare($sqlCount);
            foreach ($params as $key => $val) {
                if (strpos($sqlCount, $key) !== false) {
                    $stmtCount->bindValue($key, $val);
                }
            }
            $stmtCount->execute();
            $totalItems = (int)$stmtCount->fetchColumn();

            // 4. Query Principal
            $sql = "SELECT 
                        f.*, 
                        COALESCE(a.trade_name, a.corporate_name, u.name) as company_name,
                        p.avatar_url,
                        (SELECT COUNT(*) FROM click_logs WHERE target_id = f.id AND event_type = 'WHATSAPP_CLICK') as total_leads,
                        (SELECT COUNT(*) FROM click_logs WHERE target_id = f.id AND (event_type = 'VIEW_DETAILS' OR event_type = 'VIEW')) as total_views,
                        (SELECT COUNT(*) FROM click_logs WHERE target_id = f.id AND event_type = 'CARD_CLICK') as total_clicks,
                        (SELECT MAX(created_at) FROM click_logs WHERE target_id = f.id) as last_interaction_at,
                        (CASE WHEN fav.id IS NOT NULL THEN 1 ELSE 0 END) as is_favorite
                    FROM freights f 
                    LEFT JOIN users u ON f.user_id = u.id 
                    LEFT JOIN accounts a ON u.account_id = a.id 
                    LEFT JOIN user_profiles p ON u.id = p.user_id
                    LEFT JOIN favorites fav ON f.id = fav.target_id 
                        AND fav.target_type = 'FREIGHT' 
                        AND fav.user_id = :fav_id
                    $where 
                    GROUP BY f.id
                    ORDER BY f.is_featured DESC, f.id DESC
                    LIMIT :limit OFFSET :offset";
                
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', (int)$perPage, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
                
            foreach ($params as $key => $val) {
                if (strpos($sql, $key) !== false) {
                    $stmt->bindValue($key, $val);
                }
            }

            $stmt->execute();
            $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'data'    => $items ?: [],
                'meta'    => [
                    'total_items' => $totalItems,
                    'total_pages' => ceil($totalItems / $perPage) ?: 1,
                    'current_page' => (int)$page
                ]
            ];
        } catch (\Exception $e) {
            error_log("FALHA REPOSITORY: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }
  
    // Método auxiliar para inteligência de dados
    private function logSearch($userId, $term) {
        try {
            $stmt = $this->db->prepare("INSERT INTO search_logs (user_id, term, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$userId, substr($term, 0, 100)]);
        } catch (Exception $e) {
            // Silencioso: Não trava a busca se o log falhar
        }
    }

    public function getPopularSearches($limit = 10) {
        $sql = "SELECT term, COUNT(*) as total 
                FROM search_logs 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY term 
                ORDER BY total DESC 
                LIMIT :limit";
                
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
   
    public function save($data) {
        // Usamos Named Parameters (:user_id) para evitar erros de ordem dos campos
        $sql = "INSERT INTO freights (
                    user_id, account_id, origin_city, origin_state, dest_city, 
                    dest_state, product, weight, vehicle_type, body_type, description, 
                    status, price, expires_at, is_featured, slug, created_at
                ) VALUES (
                    :user_id, :account_id, :origin_city, :origin_state, :dest_city, :dest_state, 
                    :product, :weight, :vehicle_type, :body_type, :description, 
                    :status, :price, :expires_at, :is_featured, :slug, NOW()
                )";
        
        try {
            $stmt = $this->db->prepare($sql);
            
            // Fazemos o bind explícito para garantir os tipos de dados
            $stmt->bindValue(':user_id',      (int)$data['user_id'], PDO::PARAM_INT);
            $stmt->bindValue(':account_id',   (int)$data['account_id'], \PDO::PARAM_INT);
            $stmt->bindValue(':origin_city',  $data['origin_city']);
            $stmt->bindValue(':origin_state', $data['origin_state']);
            $stmt->bindValue(':dest_city',    $data['dest_city']);
            $stmt->bindValue(':dest_state',   $data['dest_state']);
            $stmt->bindValue(':product',      $data['product']);
            $stmt->bindValue(':weight',       $data['weight']);
            $stmt->bindValue(':vehicle_type', $data['vehicle_type']);
            $stmt->bindValue(':body_type',    $data['body_type']);
            $stmt->bindValue(':description',  $data['description']);
            $stmt->bindValue(':status',       $data['status']);
            $stmt->bindValue(':price',        $data['price']);
            $stmt->bindValue(':expires_at',   $data['expires_at']);
            $stmt->bindValue(':is_featured',  (int)$data['is_featured'], PDO::PARAM_INT);
            $stmt->bindValue(':slug',         $data['slug']);

            if ($stmt->execute()) {
                return $this->db->lastInsertId();
            }
            return false;
        } catch (\PDOException $e) {
            error_log("Erro SQL ao salvar frete: " . $e->getMessage());
            return false; 
        }
    }
    
    public function update($id, $data) {
        // Definimos exatamente o que o banco aceita. 
        // Se o React mandar 'whatsapp', o código vai ignorar e não vai quebrar.
        $sql = "UPDATE freights SET 
                    user_id = :user_id,
                    origin_city = :origin_city, 
                    origin_state = :origin_state, 
                    dest_city = :dest_city, 
                    dest_state = :dest_state, 
                    product = :product, 
                    weight = :weight, 
                    vehicle_type = :vehicle_type, 
                    body_type = :body_type, 
                    description = :description, 
                    price = :price,
                    slug = :slug
                WHERE id = :id";

        try {
            $stmt = $this->db->prepare($sql);
            
            // Binds manuais para garantir que o tipo do dado está correto
            $stmt->bindValue(':id',           (int)$id, \PDO::PARAM_INT);
            $stmt->bindValue(':user_id',      (int)$data['user_id'], \PDO::PARAM_INT);
            $stmt->bindValue(':origin_city',  $data['origin_city']);
            $stmt->bindValue(':origin_state', $data['origin_state']);
            $stmt->bindValue(':dest_city',    $data['dest_city']);
            $stmt->bindValue(':dest_state',   $data['dest_state']);
            $stmt->bindValue(':product',      $data['product']);
            $stmt->bindValue(':weight',       $data['weight']);
            $stmt->bindValue(':vehicle_type', $data['vehicle_type']);
            $stmt->bindValue(':body_type',    $data['body_type']);
            $stmt->bindValue(':description',  $data['description']);
            $stmt->bindValue(':price',        $data['price']);
            $stmt->bindValue(':slug',         $data['slug'] ?? ''); 

            return $stmt->execute();
        } catch (\Exception $e) {
            error_log("Erro Crítico no Repository Update: " . $e->getMessage());
            return false;
        }
    }
 
    public function getRawById($id) {
        if (!$id) return null;

        // Selecionamos explicitamente todos os campos que o seu formulário React usa
        $sql = "SELECT 
                    id, 
                    user_id, 
                    origin_city, 
                    origin_state, 
                    dest_city, 
                    dest_state, 
                    product, 
                    weight, 
                    vehicle_type, 
                    body_type, 
                    price, 
                    description, 
                    status, 
                    slug
                FROM freights 
                WHERE id = :id AND deleted_at IS NULL 
                LIMIT 1";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => (int)$id]);
            return $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("Erro no getRawById: " . $e->getMessage());
            return null;
        }
    }

    public function getLastFreightTime($userId) {
        $sql = "SELECT created_at FROM freights WHERE user_id = :uid ORDER BY created_at DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':uid' => (int)$userId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ? $res['created_at'] : null;
    }

    /**
     * Registra métricas de visualização e cliques de forma otimizada.
     */
    public function logMetric($targetId, $targetType, $userId, $eventType = 'VIEW', $meta = []) {
        try {
            $targetType = strtoupper($targetType);
            $eventType  = strtoupper($eventType);   
        
            // 1. Sanitização (Segurança)
            $allowedTypes  = ['FREIGHT', 'AD', 'WA_GROUP', 'LISTING']; // Conforme seu ENUM no SQL
            if (!in_array($targetType, $allowedTypes)) return false;

            // 2. Incremento no contador rápido (Tabelas de Carga ou Anúncios)
            // Definimos qual coluna atualizar de forma limpa
            $column = null;
            if ($eventType === 'VIEW') {
                $column = 'views_count';
            } elseif (in_array($eventType, ['WHATSAPP_CLICK', 'SHARE', 'CONTACT_INIT'])) {
                $column = 'clicks_count';
            }

            if ($column) {
                // Mapeia qual tabela deve ser atualizada baseado no target_type
                $tableMap = [
                    'FREIGHT' => 'freights',
                    'AD'      => 'ads',
                    'LISTING' => 'listings'
                ];

                if (isset($tableMap[$targetType])) {
                    $tableName = $tableMap[$targetType];
                    $sqlIncr = "UPDATE {$tableName} SET {$column} = {$column} + 1 WHERE id = :id";
                    $this->db->prepare($sqlIncr)->execute([':id' => $targetId]);
                }
            }

            // 3. Registro no Log Real (Sincronizado com sua tabela click_logs)
            $sqlLog = "INSERT INTO click_logs (
                            user_id, 
                            target_id, 
                            target_type, 
                            event_type, 
                            ip_address, 
                            user_agent, 
                            referer_url,
                            created_at
                        ) VALUES (
                            :u_id, :t_id, :t_type, :e_type, :ip, :ua, :ref, NOW()
                        )";
            
            $stmtLog = $this->db->prepare($sqlLog);
            // Garante que se o userId for 0 ou vazio, salve como NULL no banco
            $finalUserId = (!empty($userId) && $userId > 0) ? $userId : null;
            return $stmtLog->execute([
                ':u_id'   => $finalUserId, 
                ':t_id'   => $targetId,
                ':t_type' => $targetType,
                ':e_type' => $eventType,
                ':ip'     => $meta['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
                ':ua'     => substr($meta['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null, 0, 255),
                ':ref'    => substr($meta['referer'] ?? $_SERVER['HTTP_REFERER'] ?? null, 0, 255)
            ]);

        } catch (\Exception $e) {
            error_log("Erro Crítico logMetric: " . $e->getMessage());
            return false;
        }
    }

    public function getInterestedDrivers($companyId, $freightId = null) {
        $params = [':company_id' => (int)$companyId];
        $extraFilter = "";

        // Filtro extra mantido da sua lógica original
        if ($freightId) {
            $extraFilter = " AND f.id = :freight_id";
            $params[':freight_id'] = (int)$freightId;
        }
        
        $sql = "SELECT 
                    u.id as driver_id, 
                    u.name as driver_name, 
                    u.whatsapp as driver_whatsapp, 
                    u.rating_avg as rating,
                    p.avatar_url,
                    p.vehicle_type,
                    f.id as freight_id,
                    f.product, 
                    f.origin_city, 
                    f.dest_city, 
                    MAX(el.created_at) as last_interest_at
                FROM click_logs el
                INNER JOIN users u ON el.user_id = u.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                INNER JOIN freights f ON el.target_id = f.id
                WHERE f.user_id = :company_id 
                AND el.target_type = 'FREIGHT'
                $extraFilter
                GROUP BY u.id, f.id 
                ORDER BY last_interest_at DESC";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Formatação para o Dashboard
            return array_map(function($row) {
                $row['rating'] = round((float)($row['rating'] ?? 0), 1);
                return $row;
            }, $results);

        } catch (\Exception $e) {
            error_log("Erro ao buscar drivers interessados (Dono: $companyId): " . $e->getMessage());
            return [];
        }
    }

    /**
     * Completa a função de toggleFavorite 
     */
    public function toggleFavorite($userId, $freightId) {
        try {
            // 1. Verificação Simples (Evita transação se o frete não existir)
            // Dica: Se o banco tiver Constraints de Foreign Key, você pode até pular isso 
            // e deixar o Try/Catch pegar o erro, mas a checagem manual é mais amigável.
            $exists = $this->db->prepare("SELECT 1 FROM freights WHERE id = ? AND deleted_at IS NULL");
            $exists->execute([$freightId]);
            
            if (!$exists->fetch()) {
                return ["success" => false, "message" => "Carga indisponível ou inexistente."];
            }

            $this->db->beginTransaction();

            // 2. Busca o estado atual usando Named Parameters
            $check = $this->db->prepare("
                SELECT id FROM favorites 
                WHERE user_id = :u_id AND target_id = :t_id AND target_type = 'FREIGHT'
            ");
            $check->execute(['u_id' => $userId, 't_id' => $freightId]);
            $favorite = $check->fetch();

            if ($favorite) {
                // 3. REMOVE (Estado: Estava favoritado)
                $stmt = $this->db->prepare("
                    DELETE FROM favorites WHERE id = :fav_id
                ");
                $stmt->execute(['fav_id' => $favorite['id']]);
                $action = "removed";
                $isFavorite = false;
            } else {
                // 4. ADICIONA (Estado: Não estava favoritado)
                $stmt = $this->db->prepare("
                    INSERT INTO favorites (user_id, target_id, target_type, created_at) 
                    VALUES (:u_id, :t_id, 'FREIGHT', NOW())
                ");
                $stmt->execute(['u_id' => $userId, 't_id' => $freightId]);
                $action = "added";
                $isFavorite = true;
            }

            

            $this->db->commit();
            
            return [
                "success" => true, 
                "action" => $action, 
                "favorited" => $isFavorite // Nome de campo amigável para o React
            ];

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro ToggleFavorite (User: $userId, Freight: $freightId): " . $e->getMessage());
            return ["success" => false, "message" => "Não foi possível atualizar seus favoritos."];
        }
    }

    /**
     * Verifica se um frete específico é favorito de um usuário
     */
    public function checkFavorite($freightId, $userId) {
        // 1. Falha silenciosa e segura para visitantes
        if (!$userId || !$freightId) {
            return false;
        }

        try {
            // 2. Query otimizada
            $sql = "SELECT 1 FROM favorites 
                    WHERE user_id = :u_id 
                    AND target_id = :t_id 
                    AND target_type = 'FREIGHT'
                    LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'u_id' => (int)$userId,
                't_id' => (int)$freightId
            ]);
            
            // 3. Retorno booleano explícito
            return $stmt->fetchColumn() !== false;

        } catch (\Exception $e) {
            // Se houver erro no banco (ex: tabela bloqueada), 
            // logamos mas não travamos a renderização da página.
            error_log("Erro ao verificar favorito: " . $e->getMessage());
            return false;
        }
    }

    public function getDriverStats($userId) {
        $sql = "SELECT 
            (SELECT COUNT(*) FROM freights WHERE deleted_at IS NULL AND status = 'OPEN') as total_open,
            (SELECT COUNT(*) FROM favorites WHERE user_id = ? AND target_type = 'FREIGHT') as total_favs,
            (SELECT COUNT(*) FROM user_alerts WHERE user_id = ? AND type = 'INVITATION' AND status = 'unread') as total_invitations";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $userId, $userId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function getSmartMatchFreights($userId) {
        $profile = $this->db->query("SELECT vehicle_type, body_type FROM user_profiles WHERE user_id = $userId")->fetch();
        
        if (!$profile || empty($profile['vehicle_type'])) {
            return []; // Retorna vazio se o motorista não preencheu o perfil
        }

        // Usamos LIKE para evitar problemas com espaços ou letras maiúsculas/minúsculas
        $sql = "SELECT f.*, 1 as is_smart_match 
                FROM freights f 
                WHERE f.deleted_at IS NULL 
                AND f.status = 'OPEN'
                AND f.vehicle_type LIKE ? 
                AND f.body_type LIKE ?
                ORDER BY f.created_at DESC LIMIT 20";
                
        $stmt = $this->db->prepare($sql);
        // O % ajuda a encontrar se o texto estiver ligeiramente diferente
        $stmt->execute([
            "%" . $profile['vehicle_type'] . "%", 
            "%" . $profile['body_type'] . "%"
        ]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    public function getFavoritesByUser($userId) {
        try {
            // Buscamos os fretes fazendo um JOIN com a tabela de favoritos
            $sql = "SELECT f.*, 
                    COALESCE(a.trade_name, u.name) as company_name,
                    1 as is_favorite
                    FROM favorites fav
                    INNER JOIN freights f ON fav.target_id = f.id
                    LEFT JOIN users u ON f.user_id = u.id
                    LEFT JOIN accounts a ON f.account_id = a.id
                    WHERE fav.user_id = ? 
                    AND fav.target_type = 'FREIGHT'
                    AND f.deleted_at IS NULL
                    ORDER BY fav.created_at DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([(int)$userId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("Erro no Repository ao buscar favoritos: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Retorna os fretes do próprio usuário (Dashboard)
     */
    public function getByUserId($userId, $status = null) {
        $id = (int)$userId;
        $params = [':u_id' => $id];
        $statusFilter = $status ? " AND f.status = :status" : "";
        if ($status) $params[':status'] = strtoupper($status);

        $sql = "SELECT 
                    f.*, 
                    -- Contagem segmentada por tipo de evento
                    IFNULL(SUM(CASE WHEN cl.event_type = 'WHATSAPP_CLICK' THEN 1 ELSE 0 END), 0) as total_leads,
                    IFNULL(SUM(CASE WHEN cl.event_type = 'VIEW_DETAILS' OR cl.event_type = 'VIEW' THEN 1 ELSE 0 END), 0) as total_views,
                    IFNULL(COUNT(cl.id), 0) as total_interactions
                FROM freights f 
                LEFT JOIN click_logs cl ON f.id = cl.target_id AND cl.target_type = 'FREIGHT'
                WHERE f.user_id = :u_id 
                AND f.deleted_at IS NULL
                $statusFilter
                GROUP BY f.id
                ORDER BY f.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(function($row) {
            $row['total_leads'] = (int)$row['total_leads'];
            $row['total_views'] = (int)$row['total_views'];
            $row['total_interactions'] = (int)$row['total_interactions'];
            return $row;
        }, $results);
    }

    public function getById($id) {
        if (!$id) return null;

        $sql = "SELECT f.*, 
                    u.name as owner_name, 
                    u.whatsapp as owner_whatsapp,
                    u.email as owner_email,
                    p.avatar_url as owner_avatar,
                    p.rating as owner_rating,
                    p.is_verified as owner_verified
                FROM freights f 
                INNER JOIN users u ON f.user_id = u.id 
                LEFT JOIN user_profiles p ON u.id = p.user_id
                WHERE f.id = :id 
                AND f.deleted_at IS NULL 
                LIMIT 1";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => (int)$id]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$result) return null;

            // 1. Inteligência de Contato: Prioriza o WhatsApp do Frete, 
            // depois o do Usuário, depois o telefone geral.
            $result['display_phone'] = !empty($result['whatsapp']) 
                ? $result['whatsapp'] 
                : ($result['owner_whatsapp'] ?? '');

            // 2. Limpeza de Segurança: Não precisamos enviar dados sensíveis para o Front
            unset($result['owner_email']); 

            return $result;

        } catch (\Exception $e) {
            error_log("Erro ao buscar frete ID {$id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Vincula o motorista e muda status
     */
    public function assignDriver($freightId, $driverId) {
        try {
            $this->db->beginTransaction();

            // 1. Verificação de Segurança (Lock for Update)
            // O FOR UPDATE impede que outro processo altere este frete até o fim da transação
            $check = $this->db->prepare("
                SELECT status FROM freights 
                WHERE id = :f_id 
                FOR UPDATE
            ");
            $check->execute(['f_id' => $freightId]);
            $freight = $check->fetch();

            if (!$freight || $freight['status'] !== 'OPEN') {
                $this->db->rollBack();
                return ["success" => false, "message" => "Este frete já não está mais disponível."];
            }

            // 2. Atualização do Status e Motorista
            $sql = "UPDATE freights 
                    SET assigned_driver_id = :d_id, 
                        status = 'IN_PROGRESS',
                        updated_at = NOW()
                    WHERE id = :f_id AND status = 'OPEN'";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                'd_id' => $driverId,
                'f_id' => $freightId
            ]);

            if (!$success) {
                $this->db->rollBack();
                return ["success" => false, "message" => "Erro ao atribuir motorista."];
            }

            // 3. Registro de Evento (Histórico)
            // Importante para saber QUANDO o frete saiu de 'OPEN' para 'IN_PROGRESS'
            $this->logMetric($freightId, 'FREIGHT', $driverId, 'ASSIGNED');

            

            $this->db->commit();
            return ["success" => true, "message" => "Motorista atribuído com sucesso!"];

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro em assignDriver: " . $e->getMessage());
            return ["success" => false, "message" => "Erro interno no servidor."];
        }
    }

    public function updatePaymentStatus($freightId, $status) {
        // 1. Whitelist de Status (Segurança de integridade)
        $allowedStatus = ['PENDING', 'PAID', 'REFUNDED', 'CANCELLED'];
        $status = strtoupper($status);

        if (!in_array($status, $allowedStatus)) {
            return ["success" => false, "message" => "Status de pagamento inválido."];
        }

        try {
            $this->db->beginTransaction();

            // 2. Atualização com Timestamp
            $sql = "UPDATE freights 
                    SET payment_status = :status, 
                        updated_at = NOW() 
                    WHERE id = :id";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                'status' => $status,
                'id'     => $freightId
            ]);

            if (!$success) {
                $this->db->rollBack();
                return ["success" => false, "message" => "Frete não encontrado."];
            }

            // 3. Log de Auditoria Financeira
            // Importante para saber QUEM alterou o status e QUANDO
            $this->logMetric($freightId, 'FREIGHT', null, 'PAYMENT_' . $status);

            

            $this->db->commit();
            return ["success" => true, "message" => "Pagamento atualizado para $status."];

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro em updatePaymentStatus: " . $e->getMessage());
            return ["success" => false, "message" => "Erro ao processar atualização financeira."];
        }
    }

    public function finishFreight($freightId) {
        try {
            // 1. Só permitimos finalizar fretes que estão 'IN_PROGRESS'
            // Isso evita que alguém finalize um frete que já foi 'CANCELLED' ou que ainda está 'OPEN'
            $sql = "UPDATE freights 
                    SET status = 'FINISHED', 
                        finished_at = NOW(), 
                        updated_at = NOW() 
                    WHERE id = :id 
                    AND status = 'IN_PROGRESS'";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute(['id' => $freightId]);

            // 2. Verificamos se alguma linha foi realmente afetada
            if ($stmt->rowCount() === 0) {
                // Se não afetou nada, ou o ID não existe, ou o status não era IN_PROGRESS
                return [
                    "success" => false, 
                    "message" => "Não foi possível finalizar. O frete deve estar em andamento."
                ];
            }

            // 3. Registro do Log de Conclusão
            $this->logMetric($freightId, 'FREIGHT', null, 'DELIVERED');

            

            return [
                "success" => true, 
                "message" => "Carga finalizada com sucesso!"
            ];

        } catch (\Exception $e) {
            error_log("Erro ao finalizar frete {$freightId}: " . $e->getMessage());
            return ["success" => false, "message" => "Erro interno ao encerrar o frete."];
        }
    }
    
    public function getDriversWhoClicked($freightId) {
        $sql = "SELECT 
                    u.id, 
                    u.name, 
                    u.whatsapp, 
                    u.rating_avg as rating,
                    p.avatar_url, 
                    p.vehicle_type, 
                    MAX(el.created_at) as last_clicked_at,
                    COUNT(el.id) as click_count
                FROM click_logs el
                INNER JOIN users u ON el.user_id = u.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                WHERE el.target_id = :f_id 
                AND el.target_type = 'FREIGHT' 
                GROUP BY u.id
                ORDER BY last_clicked_at DESC";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['f_id' => (int)$freightId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Ajuste de tipos para o Frontend
            return array_map(function($row) {
                $row['rating'] = round((float)($row['rating'] ?? 0), 1);
                $row['click_count'] = (int)$row['click_count'];
                return $row;
            }, $rows);

        } catch (\Exception $e) {
            error_log("Erro em getDriversWhoClicked para o frete {$freightId}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Busca dados básicos para o link de WhatsApp no Controller
     */
    public function getUserBasicData($userId) {
        if (!$userId) {
            return ['name' => 'Visitante', 'whatsapp' => '', 'avatar_url' => null];
        }

        // Incluímos o avatar_url via LEFT JOIN, pois é um dado "básico" visual
        $sql = "SELECT u.name, u.whatsapp, p.avatar_url 
                FROM users u 
                LEFT JOIN user_profiles p ON u.id = p.user_id 
                WHERE u.id = :id 
                LIMIT 1";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => (int)$userId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Retorno defensivo: se o usuário foi deletado ou não existe
            if (!$result) {
                return [
                    'name' => 'Usuário não encontrado', 
                    'whatsapp' => '', 
                    'avatar_url' => null
                ];
            }

            return $result;

        } catch (\Exception $e) {
            error_log("Erro em getUserBasicData: " . $e->getMessage());
            return ['name' => 'Erro ao carregar', 'whatsapp' => '', 'avatar_url' => null];
        }
    }

    public function findBySlug($slug) {
        if (empty($slug)) return null;

        try {
            // PASSO 1: Busca apenas a carga pelo slug (Sem travas de JOIN ou Status)
            // Isso garante que se o slug existir, a carga seja encontrada.
            $sql = "SELECT * FROM freights WHERE slug = :slug LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':slug' => trim(strip_tags($slug))]);
            $freight = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$freight) return null;

            // PASSO 2: Anexa os dados do dono e formata (Usando a lógica centralizada)
            return $this->attachOwnerData($freight);

        } catch (\Exception $e) {
            error_log("❌ ERRO REPOSITORY findBySlug ({$slug}): " . $e->getMessage());
            return null;
        }
    }
    
    public function findById($id) {
        if (!$id) return null;

        try {
            // Passo 1: Busca apenas a carga pelo ID (Sem travas de JOIN)
            $sql = "SELECT * FROM freights WHERE id = :id LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':id', (int)$id, \PDO::PARAM_INT);
            $stmt->execute();
            $freight = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$freight) return null;

            // Passo 2: Tenta anexar os dados do dono
            return $this->attachOwnerData($freight);

        } catch (\Exception $e) {
            error_log("Erro findById: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Anexa os dados do proprietário (Usuário, Perfil e Empresa) ao objeto do Frete.
     */
    private function attachOwnerData($freight) {
        if (!$freight) return null;

        $sql = "SELECT 
                    u.name as owner_user_name, 
                    u.whatsapp as owner_whatsapp,
                    u.is_verified as owner_is_verified,
                    u.created_at as owner_created_at,
                    p.avatar_url,
                    p.slug as owner_slug,
                    p.extended_attributes,
                    a.trade_name,
                    a.corporate_name,
                    (SELECT COUNT(*) FROM freights f WHERE f.user_id = u.id AND f.status IN ('open', 'active')) as total_owner_freights
                FROM users u
                LEFT JOIN user_profiles p ON u.id = p.user_id
                LEFT JOIN accounts a ON u.account_id = a.id
                WHERE u.id = :uid LIMIT 1";
            
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':uid' => $freight['user_id']]);
            $owner = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($owner) {
                $details = json_decode($owner['extended_attributes'] ?? '{}', true);

                $displayName = $details['company_name'] 
                    ?? ($owner['trade_name'] 
                    ?? ($owner['corporate_name'] ?? $owner['owner_user_name']));

                $freight['owner_name'] = $displayName;
                $freight['company_name'] = $displayName;
                
                $freight['owner_whatsapp'] = $owner['owner_whatsapp'];
                $freight['owner_avatar'] = $owner['avatar_url'];
                $freight['owner_slug'] = $owner['owner_slug'];
                $freight['owner_is_verified'] = (int)($owner['owner_is_verified'] ?? 0);
                $freight['owner_created_at'] = $owner['owner_created_at'];
                $freight['total_owner_freights'] = (int)($owner['total_owner_freights'] ?? 0);
            }

            return method_exists($this, 'formatFreightData') ? $this->formatFreightData($freight) : $freight;

        } catch (\Exception $e) {
            error_log("❌ ERRO attachOwnerData: " . $e->getMessage());
            return $freight;
        }
    }

    /**
     * Formata e normaliza os dados para o Front-end (React).
     */
    private function formatFreightData($f) {
        // Garante que campos numéricos sejam retornados com o tipo correto
        $f['id'] = (int)$f['id'];
        $f['user_id'] = (int)$f['user_id'];
        $f['weight'] = (float)($f['weight'] ?? 0);
        $f['price'] = (float)($f['price'] ?? 0);
        $f['is_featured'] = (bool)($f['is_featured'] ?? false);
        $f['owner_rating'] = (float)($f['owner_rating'] ?? 5.0);
        
        // Inteligência de Contato: Prioriza o WhatsApp direto do frete,
        // caso contrário usa o do perfil do proprietário.
        $f['display_phone'] = !empty($f['whatsapp']) 
            ? $f['whatsapp'] 
            : ($f['owner_whatsapp_field'] ?? '');

        // Formatação de Datas
        if (!empty($f['created_at'])) {
            $f['created_at_formatted'] = date('d/m/Y H:i', strtotime($f['created_at']));
        }
        
        if (!empty($f['expires_at'])) {
            $f['is_expired'] = strtotime($f['expires_at']) < time();
        }

        // Limpeza de campos auxiliares internos
        unset($f['owner_whatsapp_field']);

        return $f;
    }

    /**
     * Formata o número para exibição visual: (99) 99999-9999
     */
    private function formatPhoneNumber($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) === 11) {
            return "(" . substr($phone, 0, 2) . ") " . substr($phone, 2, 5) . "-" . substr($phone, 7);
        } elseif (strlen($phone) === 10) {
            return "(" . substr($phone, 0, 2) . ") " . substr($phone, 2, 4) . "-" . substr($phone, 6);
        }
        return $phone;
    }

    private function generateSlug($text, $id) {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = strtolower($text);
        return $text . '-' . $id;
    }

    public function softDelete($id, $userId) {
        try {
            // 1. Executamos o Update com segurança de dono
            $sql = "UPDATE freights 
                    SET deleted_at = NOW(), 
                        status = 'CLOSED',
                        updated_at = NOW()
                    WHERE id = :id 
                    AND user_id = :user_id 
                    AND deleted_at IS NULL";

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                'id'      => (int)$id,
                'user_id' => (int)$userId
            ]);

            // 2. Verificamos se a linha foi realmente afetada
            // Se rowCount for 0, ou o frete não existe, ou não pertence ao usuário, ou já foi deletado.
            if ($stmt->rowCount() === 0) {
                return [
                    "success" => false, 
                    "message" => "Não foi possível remover: frete não encontrado ou sem permissão."
                ];
            }

            // 3. Log de Auditoria
            $this->logMetric($id, 'FREIGHT', $userId, 'SOFT_DELETE');

            return [
                "success" => true,
                "message" => "Frete removido com sucesso."
            ];

        } catch (\Exception $e) {
            error_log("Erro ao deletar frete {$id}: " . $e->getMessage());
            return [
                "success" => false, 
                "message" => "Erro interno ao processar a remoção."
            ];
        }
    }

    public function getSummary($userId) {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'OPEN' THEN 1 ELSE 0 END) as open,
                    SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending
                FROM freights 
                WHERE user_id = :u_id AND deleted_at IS NULL"; // Corrigido para deleted_at
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':u_id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getFreightPerformance($freightId) {
        $sql = "SELECT 
                    SUM(CASE WHEN event_type = 'WHATSAPP_CLICK' THEN 1 ELSE 0 END) as leads,
                    SUM(CASE WHEN event_type = 'VIEW_DETAILS' THEN 1 ELSE 0 END) as views
                FROM click_logs 
                WHERE target_id = :id AND target_type = 'FREIGHT'";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $freightId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function resetTestMetrics($freightId) {
        $sql = "DELETE FROM click_logs WHERE target_id = :id AND target_type = 'FREIGHT'";
        return $this->db->prepare($sql)->execute([':id' => $freightId]);
    }

    public function getUserStats($userId) {
        $sql = "SELECT 
                    COUNT(id) as total_active_freights,
                    IFNULL(SUM(views_count), 0) as global_views,
                    IFNULL(SUM(clicks_count), 0) as global_clicks
                FROM freights 
                WHERE user_id = :u_id AND deleted_at IS NULL";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':u_id' => (int)$userId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function incrementCounter($id, $eventType) {
        $column = ($eventType === 'VIEW' || $eventType === 'VIEW_DETAILS') ? 'views_count' : 'clicks_count';
        $column = ($eventType === 'WHATSAPP_CLICK') ? 'clicks_count' : 'views_count';
        
        $tableName = 'freights';
        $sql = "UPDATE {$tableName} SET {$column} = {$column} + 1 WHERE id = :id";
            try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([':id' => (int)$id]);
        } catch (\Exception $e) {
            error_log("Erro ao incrementar contador na tabela {$tableName}: " . $e->getMessage());
            return false;
        }
    }

    public function getPublicPostsByUser($userId) {
        $sql = "SELECT 
                    id, 
                    product, 
                    origin_city, 
                    origin_state, 
                    dest_city,      -- Mantenha o nome igual ao banco
                    dest_state, 
                    weight, 
                    price, 
                    vehicle_type, 
                    body_type, 
                    description, 
                    status,
                    user_id,
                    created_at, 
                    slug
                FROM freights 
                WHERE user_id = :uid 
                AND status = 'OPEN' 
                AND (deleted_at IS NULL)
                ORDER BY created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':uid' => (int)$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function confirmStep($freightId, $userId, $step, $notes = "") {
        // Insere o evento de confirmação
        $sql = "INSERT INTO freight_tracking 
                (freight_id, status, description, created_at) 
                VALUES (:fid, :status, :desc, NOW())";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':fid' => $freightId,
            ':status' => $step, // Ex: 'FINANCIAL_AGREEMENT' ou 'DELIVERY_CONFIRMED'
            ':desc' => "Confirmado pelo usuário ID: $userId. Obs: $notes"
        ]);
    }

    public function respondToInvitation($alertId, $action) {
        try {
            $this->db->beginTransaction();

            // 1. Busca os dados do alerta/convite
            $stmt = $this->db->prepare("SELECT user_id, link FROM user_alerts WHERE id = ?");
            $stmt->execute([$alertId]);
            $alert = $stmt->fetch();

            if (!$alert) throw new Exception("Alerta não encontrado.");

            // Extrai o ID do frete do link (ex: /frete/123)
            $freightId = str_replace('/frete/', '', $alert['link']);

            if ($action === 'accept') {
                // 2. Registra o Match na freight_tracking
                $sqlTrack = "INSERT INTO freight_tracking 
                            (freight_id, driver_id, status, description, created_at) 
                            VALUES (?, ?, 'MATCH_ACCEPTED', 'Motorista aceitou o convite via marketplace.', NOW())";
                $this->db->prepare($sqlTrack)->execute([$freightId, $alert['user_id']]);

                // 3. Notificar a empresa de volta
                $stmtCompany = $this->db->prepare("SELECT user_id FROM freights WHERE id = ?");
                $stmtCompany->execute([$freightId]);
                $company = $stmtCompany->fetch();
                
                if ($company) {
                    $this->db->prepare("INSERT INTO user_alerts (user_id, message, type) VALUES (?, 'O motorista aceitou seu convite!', 'MATCH')")
                            ->execute([$company['user_id']]);
                }
            }

            // 4. Marca o alerta como lido/processado
            $this->db->prepare("UPDATE user_alerts SET status = 'read' WHERE id = ?")->execute([$alertId]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log($e->getMessage());
            return false;
        }
    }

    /**
     * Busca o frete que o motorista está a realizar no momento (Testemunha Digital)
     */
    public function getActiveFreight($driverId) {
        $sql = "SELECT f.*, ft.status as tracking_status 
                FROM freights f
                INNER JOIN freight_tracking ft ON f.id = ft.freight_id
                WHERE ft.driver_id = ? 
                AND f.status = 'in_transit'
                AND ft.status IN ('MATCH_ACCEPTED', 'PICKED_UP')
                ORDER BY ft.created_at DESC 
                LIMIT 1";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$driverId]);
        return $stmt->fetch();
    }

    /**
     * Busca os alertas de convite (Invitations)
     */
    public function getUserAlerts($userId, $type = 'INVITATION', $status = 'unread') {
        $sql = "SELECT id, message, link, status, created_at 
                FROM user_alerts 
                WHERE user_id = ? AND type = ? AND status = ?
                ORDER BY created_at DESC";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $type, $status]);
        return $stmt->fetchAll();
    }

    public function getTopAdvertisers($limit = 10) {
        try {
            $sql = "SELECT 
                        COALESCE(a.trade_name, a.corporate_name, u.name) as name,
                        p.avatar_url as logo,   
                        COUNT(f.id) as freights
                    FROM freights f
                    INNER JOIN users u ON f.user_id = u.id
                    LEFT JOIN accounts a ON u.account_id = a.id
                    LEFT JOIN user_profiles p ON u.id = p.user_id 
                    WHERE f.deleted_at IS NULL 
                    AND f.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    GROUP BY u.id, a.id, a.trade_name, a.corporate_name, u.name, p.avatar_url
                    HAVING freights > 0
                    ORDER BY freights DESC
                    LIMIT :limit";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', (int)$limit, \PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'data' => $rows ?: []
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}