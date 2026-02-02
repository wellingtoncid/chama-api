<?php
namespace App\Controllers;

use App\Core\Response;
use App\Repositories\AdRepository;

class AdController {
    private $adRepo;

    public function __construct($db) {
        $this->adRepo = new AdRepository($db);
    }

    /**
     * Lista anúncios com inteligência geográfica
     * GET /api/ads?position=...&state=...&city=...&search=...
     */
    public function list($data) {
        // Captura dados da requisição
        $position = $data['position'] ?? $_GET['position'] ?? '';
        $state    = $data['state']    ?? $_GET['state']    ?? '';
        $city     = $data['city']     ?? $_GET['city']     ?? '';
        $search   = $data['search']   ?? $_GET['search']   ?? '';

        // Busca com a nova lógica de prioridade (Cidade > Estado > Brasil)
        $ads = $this->adRepo->findAds(
            $position,
            $state,
            $search,
            $city,
            5 // Limite de anúncios por bloco
        );

        // Incrementa visualizações e gera log estatístico apenas se houver resultados
        if (!empty($ads)) {
            $ids = array_column($ads, 'id');
            $this->adRepo->incrementViews($ids);
        }

        return Response::json([
            "success" => true, 
            "data" => $ads ?: [],
            "show_fallback" => count($ads) < 2 // Exemplo: se tiver menos de 2 anúncios, avisa o React para ligar o Google
        ]);
    }

    /**
     * Endpoint para registrar o clique (Essencial para cobrança por clique)
     * POST ou GET /api/ads/click/:id
     */
    public function recordClick($data) {
        $id = $data['id'] ?? null;
        
        if (!$id) {
            return Response::json(["success" => false, "message" => "ID ausente"]);
        }

        $result = $this->adRepo->incrementClick($id);
        
        return Response::json([
            "success" => $result,
            "message" => $result ? "Clique registrado" : "Erro ao registrar"
        ]);
    }

    /**
     * Salva ou Atualiza Anúncio (Admin/Empresa)
     */
    public function store($data) {
        if (($data['action'] ?? '') === 'delete') {
            return Response::json(["success" => $this->adRepo->softDelete($data['id'])]);
        }

        // Se houver upload de imagem
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $uploadPath = $this->uploadFile($_FILES['image']);
            if ($uploadPath) $data['image_url'] = $uploadPath;
        }

        $result = $this->adRepo->save($data);
        
        return Response::json([
            "success" => (bool)$result,
            "id" => $result,
            "message" => $result ? "Anúncio salvo com sucesso" : "Erro ao salvar"
        ]);
    }

    private function uploadFile($file) {
        $targetDir = __DIR__ . "/../../public/uploads/ads/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = time() . "_" . uniqid() . "." . $ext;
        
        if (move_uploaded_file($file['tmp_name'], $targetDir . $fileName)) {
            return "uploads/ads/" . $fileName;
        }
        return null;
    }
}