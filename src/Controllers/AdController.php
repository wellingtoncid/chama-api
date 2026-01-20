<?php
/**
 * AdController.php - Gestão de Banners e Publicidade Nativa
 * Sincronizado com SQL (chama_frete 5) e Busca Inteligente
 */

class AdController {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function handle($method, $endpoint, $data) {
        switch ($endpoint) {
            case 'ads':
                return $this->listAll();
            case 'manage-ads':
                return $this->manage($data);
            case 'log-ad-event':
            case 'register-ad-event':
                return $this->logEvent($data);
            default:
                return ["error" => "Endpoint de anúncio não encontrado"];
        }
    }

    /**
     * listAll - Lista anúncios com suporte a filtros de busca, localização e posição
     */
    /**
     * listAll - Lista anúncios com suporte a filtros de busca e localização
     * @param array $data Dados vindos do roteador (combinando GET e JSON)
     */
    private function listAll($data = []) {
        // 1. Captura filtros priorizando o que vem do roteador ($data) e depois $_GET
        $search   = $data['search'] ?? $_GET['search'] ?? '';
        $location = $data['city'] ?? $data['state'] ?? $_GET['city'] ?? $_GET['state'] ?? '';
        $position = $data['position'] ?? $_GET['position'] ?? '';

        // 2. Base da Query (Apenas ativos e não deletados)
        $sql = "SELECT * FROM ads WHERE is_deleted = 0 AND is_active = 1";
        $params = [];

        // 3. Filtro de Posição (Essencial para AdCard saber onde exibir)
        if (!empty($position)) {
            $sql .= " AND position = ?";
            $params[] = $position;
        }

        // 4. Filtro de Busca Inteligente (Fragmentado)
        if (!empty($search)) {
            $keywords = explode(' ', preg_replace('/\s+/', ' ', trim($search)));
            $searchConditions = [];
            foreach ($keywords as $keyword) {
                // Busca no título, categoria ou descrição
                $searchConditions[] = "(title LIKE ? OR category LIKE ? OR description LIKE ?)";
                $params[] = "%$keyword%";
                $params[] = "%$keyword%";
                $params[] = "%$keyword%";
            }
            if (count($searchConditions) > 0) {
                $sql .= " AND (" . implode(" AND ", $searchConditions) . ")";
            }
        }

        // 5. Filtro de Localização (Se pesquisar 'Irecê', traz anúncios de Irecê OU 'Brasil')
        if (!empty($location) && strtolower($location) !== 'brasil') {
            $sql .= " AND (LOWER(location_city) = LOWER(?) OR LOWER(location_city) = 'brasil')";
            $params[] = $location;
        } else {
            // Se não houver cidade, ou for 'Brasil', traz apenas os nacionais por padrão
            // para evitar sobrecarregar o componente com anúncios irrelevantes
            $sql .= " AND LOWER(location_city) = 'brasil'";
        }

        $sql .= " ORDER BY id DESC";

        // 6. Executa a busca
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 7. Funcionalidade Anterior: Incrementa visualização para os anúncios listados
        // Isso garante que mesmo que o log do frontend falhe, o banco registra a entrega
        if (count($ads) > 0) {
            $ids = array_column($ads, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $updateSql = "UPDATE ads SET views_count = views_count + 1 WHERE id IN ($placeholders)";
            $stmtUpdate = $this->db->prepare($updateSql);
            $stmtUpdate->execute($ids);
        }

        return $ads;
    }

    /**
     * manage - Gerencia CRUD e Métricas
     */
    private function manage($data) {
        $id = $data['id'] ?? null;
        $action = $data['action'] ?? '';

        // 1. Ações de métricas (Trata incrementos enviados pelo Axios)
        if ($action === 'increment-view') {
            $stmt = $this->db->prepare("UPDATE ads SET views_count = views_count + 1 WHERE id = ?");
            return ["success" => $stmt->execute([$id])];
        }

        if ($action === 'increment-click') {
            $stmt = $this->db->prepare("UPDATE ads SET clicks_count = clicks_count + 1 WHERE id = ?");
            return ["success" => $stmt->execute([$id])];
        }

        // 2. Ação de exclusão lógica
        if ($action === 'delete') {
            $stmt = $this->db->prepare("UPDATE ads SET is_deleted = 1, is_active = 0 WHERE id = ?");
            return ["success" => $stmt->execute([$id])];
        }

        // 3. Mapeamento de campos (Salvamento/Edição)
        $title       = $data['title'] ?? '';
        $category    = $data['category'] ?? 'OUTROS';
        $description = $data['description'] ?? '';
        $image       = $data['image_url'] ?? '';
        $link_wa     = $data['link_whatsapp'] ?? '';
        $city        = $data['location_city'] ?? 'Brasil';
        $pos         = $data['position'] ?? 'sidebar'; 
        $active      = isset($data['is_active']) ? (int)$data['is_active'] : 1;

        if ($id) {
            $sql = "UPDATE ads SET 
                    title = ?, category = ?, description = ?, image_url = ?, 
                    link_whatsapp = ?, location_city = ?, position = ?, is_active = ? 
                    WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$title, $category, $description, $image, $link_wa, $city, $pos, $active, $id]);
        } else {
            $sql = "INSERT INTO ads (title, category, description, image_url, link_whatsapp, location_city, position, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$title, $category, $description, $image, $link_wa, $city, $pos, $active]);
        }
        
        return ["success" => $success, "id" => $id ?: $this->db->lastInsertId()];
    }

    private function logEvent($data) {
        $id = $data['id'] ?? $data['ad_id'] ?? 0;
        $type = $data['type'] ?? 'click'; 
        
        if (!$id) return ["success" => false];

        $column = ($type === 'click') ? 'clicks_count' : 'views_count';
        $stmt = $this->db->prepare("UPDATE ads SET $column = $column + 1 WHERE id = ?");
        return ["success" => $stmt->execute([$id])];
    }
}