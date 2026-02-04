<?php
namespace App\Controllers;

use App\Core\Response;
use App\Repositories\MetricsRepository;
use App\Repositories\FreightRepository;
use App\Repositories\AdRepository; 
use App\Repositories\GroupRepository; 
use App\Repositories\ListingRepository;

class MetricsController {
    private $metricsRepository;
    private $freightRepository;
    private $adRepository;
    private $groupRepository;
    private $listingRepository;

    public function __construct(
        $metricsRepository,
        $freightRepository,
        $adRepository,
        $groupRepository,
        $listingRepository
    ) {
        $this->metricsRepository = $metricsRepository;
        $this->freightRepository = $freightRepository;
        $this->adRepository      = $adRepository;
        $this->groupRepository   = $groupRepository;
        $this->listingRepository = $listingRepository;
    }

    /**
     * Registra eventos (Views/Clicks) vindos do Frontend
     */
    public function registerEvent() {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        
        $targetId   = isset($data['target_id']) ? (int)$data['target_id'] : null;
        $targetType = strtoupper($data['target_type'] ?? ''); 
        $eventType  = strtoupper($data['event_type'] ?? '');  
        $userId     = $data['user_id'] ?? ($_SESSION['user_id'] ?? null);

        if (!$targetId || !$targetType || !$eventType) {
            http_response_code(400);
            return json_encode(['success' => false, 'message' => 'Parâmetros insuficientes']);
        }

        try {
            // 1. Log detalhado
            $this->metricsRepository->saveLog($targetId, $targetType, $eventType, $userId);

            // 2. Incremento nos contadores das tabelas específicas
            switch ($targetType) {
                case 'FREIGHT':
                    $this->freightRepository->incrementCounter($targetId, $eventType);
                    break;
                case 'AD':
                    $this->adRepository->incrementCounter($targetId, $eventType);
                    break;
                case 'GROUP':
                    $this->groupRepository->incrementCounter($targetId, $eventType);
                    break;
                case 'LISTING':
                    $this->listingRepository->incrementCounter($targetId, $eventType);
                    break;
            }

            return json_encode(['success' => true]);
        } catch (\Exception $e) {
            http_response_code(500);
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Resumo para o Dashboard do Usuário/Empresa
     */
    public function getDashboardSummary($data, $loggedUser) {
        if (!$loggedUser) return Response::json(["success" => false], 401);

        $userId = $loggedUser['id'];
        
        if (strtoupper($loggedUser['role'] ?? '') === 'ADMIN' && isset($data['target_user_id'])) {
            $userId = (int)$data['target_user_id'];
        }

        try {
            // Buscando todas as métricas, incluindo Listings
            $freights = $this->metricsRepository->getUserEntityStats($userId, 'FREIGHT');
            $ads      = $this->metricsRepository->getUserEntityStats($userId, 'AD');
            $whatsapp_groups = $this->metricsRepository->getUserEntityStats($userId, 'GROUP');
            $listings = $this->metricsRepository->getUserEntityStats($userId, 'LISTING');

            return Response::json([
                "success" => true,
                "data" => [
                    "freights" => [
                        "active_count" => (int)($freights['total_items'] ?? 0),
                        "total_views"  => (int)($freights['views'] ?? 0),
                        "total_clicks" => (int)($freights['clicks'] ?? 0)
                    ],
                    "ads" => [
                        "active_count" => (int)($ads['total_items'] ?? 0),
                        "total_views"  => (int)($ads['views'] ?? 0),
                        "total_clicks" => (int)($ads['clicks'] ?? 0)
                    ],
                    "whatsapp_groups" => [
                        "active_count" => (int)($whatsapp_groups['total_items'] ?? 0),
                        "total_views"  => (int)($whatsapp_groups['views'] ?? 0),
                        "total_clicks" => (int)($whatsapp_groups['clicks'] ?? 0)
                    ],
                    "listings" => [
                        "active_count" => (int)($listings['total_items'] ?? 0),
                        "total_views"  => (int)($listings['views'] ?? 0),
                        "total_clicks" => (int)($listings['clicks'] ?? 0)
                    ],
                    "recent" => $this->metricsRepository->getRecentActivity($userId, 5)
                ]
            ]);
        } catch (\Exception $e) {
            error_log("Erro MetricsController: " . $e->getMessage());
            return Response::json(["success" => false, "error" => $e->getMessage()], 500);
        }
    }

    /**
     * VISÃO GLOBAL - Apenas para Administradores
     * GET /api/metrics/global
     */
    public function getGlobalStats($data, $loggedUser) {
        if (!$loggedUser || strtoupper($loggedUser['role'] ?? '') !== 'ADMIN') {
            return Response::json(["success" => false, "message" => "Acesso negado"], 403);
        }

        try {
            return Response::json([
                "success" => true,
                "data" => [
                    "overview" => [
                        "freights"          => $this->metricsRepository->getUserEntityStats(null, 'FREIGHT'),
                        "ads"               => $this->metricsRepository->getUserEntityStats(null, 'AD'),
                        "whatsapp_groups"   => $this->metricsRepository->getUserEntityStats(null, 'GROUP'),
                        "listings"          => $this->metricsRepository->getUserEntityStats(null, 'LISTING'),
                    ],
                    "activity" => [
                        "total_24h" => $this->metricsRepository->getLogCountByPeriod('24 HOUR'),
                        "total_7d"  => $this->metricsRepository->getLogCountByPeriod('7 DAY'),
                        "recent_logs" => $this->metricsRepository->getRecentActivity(null, 20)
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return Response::json(["success" => false, "error" => $e->getMessage()], 500);
        }
    }

    private function calculateCTR($views, $clicks) {
        if (!$views || $views == 0) return 0;
        return round(($clicks / $views) * 100, 2);
    }
}