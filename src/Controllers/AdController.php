<?php
namespace App\Controllers;

use App\Core\Response;
use App\Repositories\AdRepository;
use App\Services\ContentFilterService;
use PDO;

class AdController {
    private $adRepo;
    private $db;

    public function __construct($db) {
        $this->db = $db;
        $this->adRepo = new AdRepository($db);
    }

    /**
     * Cria novo anúncio (chama store)
     * POST /api/upload-ad
     */
    public function create($data, $loggedUser = null) {
        return $this->store($data, $loggedUser);
    }

    /**
     * Lista anúncios com inteligência geográfica e controle de créditos
     * GET /api/ads?position=...&state=...&city=...&search=...
     * NÃO incrementa mais views automaticamente - views são contadas via frontend com IntersectionObserver
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

        // Views agora são contadas pelo frontend via IntersectionObserver
        // Não incrementamos mais automaticamente para evitar contagem duplicada

        // 3. Resposta para o Frontend (Mantendo compatibilidade com React)
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
    public function store($data, $loggedUser = null) {
        // Validar conteúdo com ContentFilter
        if (!empty($data['title']) && !ContentFilterService::isClean($data['title'])) {
            $reason = ContentFilterService::getReason($data['title']);
            return Response::json(["success" => false, "message" => $reason ?: "O título contém conteúdo não permitido."], 400);
        }
        if (!empty($data['description']) && !ContentFilterService::isClean($data['description'])) {
            $reason = ContentFilterService::getReason($data['description']);
            return Response::json(["success" => false, "message" => $reason ?: "A descrição contém conteúdo não permitido."], 400);
        }

        $action = $data['action'] ?? '';
        
        if ($action === 'delete') {
            $id = $data['id'] ?? null;
            if ($id) {
                $this->adRepo->save(['id' => $id, 'status' => 'rejected', 'deleted_at' => date('Y-m-d H:i:s')]);
                return Response::json(["success" => true]);
            }
            return Response::json(["success" => false, "message" => "ID inválido"]);
        }

        // Update existing ad
        if (!empty($data['id'])) {
            // Se houver upload de imagem
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadPath = $this->uploadFile($_FILES['image']);
                if ($uploadPath) $data['image_url'] = $uploadPath;
            }
            
            $result = $this->adRepo->save($data);
            return Response::json([
                "success" => $result,
                "message" => $result ? "Anúncio atualizado com sucesso" : "Erro ao atualizar"
            ]);
        }

        // Se for admin criando para outro usuário, usa o user_id informado
        // Se não, usa o usuário logado
        $targetUserId = $data['target_user_id'] ?? $data['user_id'] ?? null;
        
        // Verifica se é admin (pode criar sem pagar)
        $role = strtolower($loggedUser['role'] ?? '');
        $isAdmin = in_array($role, ['admin', 'manager']);
        
        // Verifica elegibilidade se for usuário logado ou user_id especificado (mas não para admin)
        $position = $data['position'] ?? 'sidebar';
        $featureKey = $this->getFeatureKeyFromPosition($position);
        
        $checkUserId = $targetUserId ?? ($loggedUser['id'] ?? null);
        
        // Admin pode criar anúncios sem restrição de pagamento
        if ($checkUserId && !$isAdmin) {
            $eligibility = $this->adRepo->checkAdPositionEligibility($checkUserId, $featureKey);
            
            if (!$eligibility['allowed']) {
                return Response::json([
                    "success" => false,
                    "message" => $eligibility['reason'],
                    "requires_payment" => $eligibility['requires_payment'],
                    "price_monthly" => $eligibility['price_monthly'] ?? 0,
                    "price_per_use" => $eligibility['price_per_use'] ?? 0,
                    "feature_name" => $eligibility['feature_name'] ?? ''
                ], 402);
            }
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

    /**
     * Converte posição para feature_key
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

    /**
     * Retorna relatório de anúncios do usuário logado
     * GET /api/ads/my-report?period=weekly|monthly|all
     */
    public function getUserAdsReport($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $userId = $loggedUser['id'];
        $period = $data['period'] ?? 'monthly';

        // Define o filtro de data baseado no período
        $dateFilter = match($period) {
            'weekly' => 'AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            'monthly' => 'AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
            'daily' => 'AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)',
            default => '' // 'all' ou qualquer outro
        };

        try {
            // Estatísticas gerais dos anúncios do usuário
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_ads,
                    SUM(views_count) as total_views,
                    SUM(clicks_count) as total_clicks,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_ads,
                    SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_ads
                FROM ads 
                WHERE user_id = ? $dateFilter
            ");
            $stmt->execute([$userId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Lista de anúncios com métricas
            $adsStmt = $this->db->prepare("
                SELECT 
                    id, title, position, status, views_count, clicks_count, 
                    created_at, expires_at,
                    CASE 
                        WHEN clicks_count > 0 AND views_count > 0 
                        THEN ROUND((clicks_count / views_count) * 100, 2)
                        ELSE 0 
                    END as ctr
                FROM ads 
                WHERE user_id = ? $dateFilter
                ORDER BY views_count DESC, clicks_count DESC
                LIMIT 50
            ");
            $adsStmt->execute([$userId]);
            $ads = $adsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Calcula CTR geral
            $overallCtr = 0;
            if ($stats['total_views'] > 0 && $stats['total_clicks'] > 0) {
                $overallCtr = round(($stats['total_clicks'] / $stats['total_views']) * 100, 2);
            }

            return Response::json([
                "success" => true,
                "data" => [
                    "period" => $period,
                    "summary" => [
                        "total_ads" => (int)($stats['total_ads'] ?? 0),
                        "active_ads" => (int)($stats['active_ads'] ?? 0),
                        "expired_ads" => (int)($stats['expired_ads'] ?? 0),
                        "total_views" => (int)($stats['total_views'] ?? 0),
                        "total_clicks" => (int)($stats['total_clicks'] ?? 0),
                        "overall_ctr" => $overallCtr
                    ],
                    "ads" => $ads
                ]
            ]);
        } catch (\Throwable $e) {
            error_log("ERRO getUserAdsReport: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao carregar relatório"], 500);
        }
    }
}