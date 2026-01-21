<?php
/**
 * AdController.php - Gestão de Banners e Publicidade Nativa
 * Sincronizado com SQL v7 e Suporte a Upload de Arquivos
 */

class AdController {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function handle($method, $endpoint, $data) {
        switch ($endpoint) {
            case 'ads':
                return $this->listAll($data);
            case 'manage-ads':
                return $this->manage($data);
            case 'log-ad-event':
            case 'register-ad-event':
            case 'log-ad-view':
            case 'log-ad-click':
                return $this->logEvent($data, $endpoint);
            default:
                return ["error" => "Endpoint de anúncio não encontrado: " . $endpoint];
        }
    }

    /**
     * Lista anúncios com filtros de busca, localização e expiração
     */
    private function listAll($data = []) {
        $search   = $data['search'] ?? '';
        $location = $data['city'] ?? $data['location_city'] ?? '';
        $position = $data['position'] ?? '';

        // Base da Query: Filtra por ativos, não deletados e que não expiraram
        $sql = "SELECT * FROM ads WHERE is_deleted = 0 AND status = 'active' 
                AND (expires_at IS NULL OR expires_at >= CURDATE())";
        $params = [];

        if (!empty($position)) {
            $sql .= " AND position = ?";
            $params[] = $position;
        }

        if (!empty($search)) {
            $keywords = explode(' ', preg_replace('/\s+/', ' ', trim($search)));
            $searchConditions = [];
            foreach ($keywords as $keyword) {
                $searchConditions[] = "(title LIKE ? OR category LIKE ? OR description LIKE ?)";
                $params[] = "%$keyword%";
                $params[] = "%$keyword%";
                $params[] = "%$keyword%";
            }
            $sql .= " AND (" . implode(" AND ", $searchConditions) . ")";
        }

        if (!empty($location) && strtolower($location) !== 'brasil') {
            $sql .= " AND (LOWER(location_city) = LOWER(?) OR LOWER(location_city) = 'brasil')";
            $params[] = $location;
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Auto-incremento de visualização ao listar (Impressão passiva)
        if (count($ads) > 0) {
            $ids = array_column($ads, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmtUpdate = $this->db->prepare("UPDATE ads SET views_count = views_count + 1 WHERE id IN ($placeholders)");
            $stmtUpdate->execute($ids);
        }

        return $ads;
    }

    /**
     * Gerencia CRUD (Criação, Edição, Exclusão) e Upload de Imagem
     */
    private function manage($data) {
        $id = $data['id'] ?? null;
        $action = $data['action'] ?? '';

        // Exclusão Lógica
        if ($action === 'delete') {
            $stmt = $this->db->prepare("UPDATE ads SET is_deleted = 1, status = 'inactive' WHERE id = ?");
            return ["success" => $stmt->execute([$id])];
        }

        // Processamento de Upload de Imagem
        $imageUrl = $data['image_url'] ?? '';
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $imageUrl = $this->uploadFile($_FILES['image']);
        }

        // Mapeamento de campos para o SQL v7
        $title       = $data['title'] ?? '';
        $category    = $data['category'] ?? 'PROMOÇÃO';
        $description = $data['description'] ?? '';
        $destUrl     = $data['destination_url'] ?? $data['link_whatsapp'] ?? '';
        $city        = $data['location_city'] ?? 'Brasil';
        $pos         = $data['position'] ?? 'sidebar'; 
        $status      = $data['status'] ?? 'active';
        $expiresAt   = !empty($data['expires_at']) ? $data['expires_at'] : null;

        if ($id) {
            $sql = "UPDATE ads SET 
                    title = ?, category = ?, description = ?, image_url = ?, 
                    destination_url = ?, location_city = ?, position = ?, 
                    status = ?, expires_at = ? 
                    WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$title, $category, $description, $imageUrl, $destUrl, $city, $pos, $status, $expiresAt, $id]);
        } else {
            $sql = "INSERT INTO ads (title, category, description, image_url, destination_url, location_city, position, status, expires_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$title, $category, $description, $imageUrl, $destUrl, $city, $pos, $status, $expiresAt]);
            $id = $this->db->lastInsertId();
        }
        
        return [
            "success" => $success, 
            "id" => $id,
            "message" => $success ? "Anúncio salvo com sucesso" : "Erro ao salvar no banco"
        ];
    }

    /**
     * Incrementa cliques ou visualizações individuais
     */
    private function logEvent($data, $endpoint) {
        $id = $data['id'] ?? $data['ad_id'] ?? 0;
        
        // Determina se é clique ou view baseado no endpoint ou tipo enviado
        $type = $data['type'] ?? '';
        if ($endpoint === 'log-ad-click' || $type === 'click') {
            $column = 'clicks_count';
        } else {
            $column = 'views_count';
        }
        
        if (!$id) return ["success" => false, "error" => "ID ausente"];

        $stmt = $this->db->prepare("UPDATE ads SET $column = $column + 1 WHERE id = ?");
        return ["success" => $stmt->execute([$id])];
    }

    /**
     * Auxiliar: Move o arquivo enviado para a pasta de uploads
     */
    private function uploadFile($file) {
        $targetDir = __DIR__ . "/../../uploads/ads/";
        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        
        $fileExt = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = time() . "_" . uniqid() . "." . $fileExt;
        $targetFile = $targetDir . $fileName;

        if (move_uploaded_file($file['tmp_name'], $targetFile)) {
            // Retorna o caminho que o navegador usará para acessar a imagem
            return "uploads/ads/" . $fileName;
        }
        return "";
    }
}