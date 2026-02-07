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
     * Lista anúncios com inteligência geográfica e controle de créditos
     * GET /api/ads?position=...&state=...&city=...&search=...
     */
    public function list($data) {
        // 1. Captura de dados (Mantendo a funcionalidade original)
        $position = $data['position'] ?? $_GET['position'] ?? '';
        $state    = $data['state']    ?? $_GET['state']    ?? '';
        $city     = $data['city']     ?? $_GET['city']     ?? '';
        $search   = $data['search']   ?? $_GET['search']   ?? '';

        // 2. Busca com prioridade geográfica E filtro de saldo (findAds atualizado)
        $ads = $this->adRepo->findAds(
            $position,
            $state,
            $search,
            $city,
            5 // Limite de anúncios por bloco
        );

        // 3. Incremento e Débito (Funcionalidade de log estatístico + Financeiro)
        // Só ocorre se houver resultados para evitar chamadas inúteis ao banco
        if (!empty($ads)) {
            $ids = array_column($ads, 'id');
            
            /** * Importante: O método incrementViews agora é transacional no Repository.
             * Ele incrementa o views_count e debita os ad_credits dos donos.
             */
            $this->adRepo->incrementViews($ids);
        }

        // 4. Resposta para o Frontend (Mantendo compatibilidade com React)
        return Response::json([
            "success" => true, 
            "data" => $ads ?: [],
            // Mantém a funcionalidade de avisar o React para ativar fallback (ex: Google Adsense)
            "show_fallback" => count($ads) < 2 
        ]);
    }

   /**
     * Registra o clique e processa o débito (Model de Impulsão/Créditos)
     * Suporta POST ou GET /api/ads/click/:id?type=CLICK
     */
    public function recordClick($data) {
        // 1. Captura o ID e o tipo de clique (pode ser CLICK ou WHATSAPP_CLICK)
        $id = $data['id'] ?? null;
        $type = $data['type'] ?? 'CLICK'; // Default para clique no banner

        if (!$id) {
            return Response::json(["success" => false, "message" => "ID do anúncio ausente"]);
        }

        /** * 2. Chamamos o novo método unificado do Repository.
         * Ele vai: 
         * - Incrementar o contador de cliques na tabela 'ads'
         * - Debitar os créditos do usuário dono do anúncio (valor dinâmico da site_settings)
         * - Gravar a transação no extrato
         */
        $result = $this->adRepo->incrementCounter($id, $type);
        
        return Response::json([
            "success" => $result,
            "message" => $result ? "Interação registrada e processada" : "Erro ao processar interação ou saldo insuficiente"
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

    /**
     * Lista os anúncios da própria empresa (Painel de Gestão)
     */
    public function listMyAds($data, $loggedUser) {
        if (!$loggedUser) return Response::json(["success" => false, "message" => "Não autorizado"], 401);

        $ads = $this->adRepo->getAdsByUserId($loggedUser['id']);
        
        // Pegamos o saldo atual do usuário (do primeiro registro ou busca direta)
        $credits = !empty($ads) ? $ads[0]['ad_credits'] : 0;

        return Response::json([
            "success" => true,
            "data" => $ads,
            "ad_credits" => $credits,
            "message" => empty($ads) ? "Você ainda não possui anúncios." : ""
        ]);
    }

    /**
     * Retorna os pacotes de anúncios para a tela de compra
     */
    public function getPackages() {
        $packages = $this->adRepo->getPackages();
        return Response::json([
            "success" => true,
            "data" => $packages
        ]);
    }
}