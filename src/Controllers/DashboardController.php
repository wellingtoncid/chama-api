<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use PDO;

class DashboardController
{
    private $db;

    public function __construct($db, $loggedUser = null)
    {
        $this->db = $db;
    }

    private function authorize()
    {
        $user = Auth::getAuthenticatedUser();
        if (!$user) {
            throw new \Exception('Não autorizado', 401);
        }
        return $user;
    }

    /**
     * Mapeamento de widgets padrão por role (sincronizado com tabela roles)
     */
    private function getDefaultWidgetsForRole(string $role): array
    {
        $defaults = [
            'admin' => [
                ['widget_key' => 'freights_total', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'freights_growth', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'users_total', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'companies_total', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'drivers_total', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'wallet_revenue', 'widget_type' => 'kpi', 'col_span' => 2],
                ['widget_key' => 'quotes_total', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'tickets_open', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'plans_active', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'listings_active', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'groups_total', 'widget_type' => 'kpi', 'col_span' => 1],
            ],
            'gerente' => [
                ['widget_key' => 'freights_total', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'users_total', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'quotes_total', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'tickets_open', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'wallet_revenue', 'widget_type' => 'kpi', 'col_span' => 2],
            ],
            'suporte' => [
                ['widget_key' => 'tickets_open', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'tickets_closed', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'users_total', 'widget_type' => 'kpi', 'col_span' => 1],
            ],
            'financeiro' => [
                ['widget_key' => 'wallet_revenue', 'widget_type' => 'kpi', 'col_span' => 2],
                ['widget_key' => 'avg_ticket', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'plans_active', 'widget_type' => 'kpi', 'col_span' => 1],
            ],
            'marketing' => [
                ['widget_key' => 'listings_active', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'groups_total', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'users_total', 'widget_type' => 'kpi', 'col_span' => 1],
            ],
            'vendas' => [
                ['widget_key' => 'freights_total', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'users_total', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'companies_total', 'widget_type' => 'kpi', 'col_span' => 1],
            ],
            'coordenador' => [
                ['widget_key' => 'freights_total', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'quotes_total', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'tickets_open', 'widget_type' => 'kpi', 'col_span' => 1],
            ],
            'supervisor' => [
                ['widget_key' => 'freights_total', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'quotes_total', 'widget_type' => 'kpi', 'col_span' => 1],
            ],
            'driver' => [
                ['widget_key' => 'my_freights', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'my_earnings', 'widget_type' => 'kpi', 'col_span' => 2],
                ['widget_key' => 'my_freights_chart', 'widget_type' => 'chart_bar', 'col_span' => 2],
            ],
            'company' => [
                ['widget_key' => 'my_freights', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'my_freights_growth', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'my_listings', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'my_revenue', 'widget_type' => 'kpi', 'col_span' => 2],
                ['widget_key' => 'my_quotes_pending', 'widget_type' => 'kpi', 'col_span' => 1],
                ['widget_key' => 'my_freights_chart', 'widget_type' => 'chart_bar', 'col_span' => 2],
            ],
        ];

        return $defaults[$role] ?? $defaults['admin'];
    }

    /**
     * GET /api/admin/dashboard/widgets - Lista widgets do usuário
     */
    public function getWidgets($data = [])
    {
        $loggedUser = $this->authorize();
        $userId = $loggedUser['id'];
        $role = $loggedUser['role'] ?? 'guest';

        error_log("[DashboardController] getWidgets - userId: $userId, role: $role");

        try {
            $stmt = $this->db->prepare('
                SELECT widget_key, widget_type, position_order, col_span, is_visible, filters
                FROM dashboard_widgets
                WHERE user_id = ?
                ORDER BY position_order ASC
            ');
            $stmt->execute([$userId]);
            $userWidgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($userWidgets)) {
                $userWidgets = $this->getDefaultWidgetsForRole($role);
            }

            $stmt = $this->db->query('
                SELECT widget_key, widget_type, label, description, icon, category
                FROM dashboard_available_widgets
                ORDER BY category, widget_key
            ');
            $availableWidgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            error_log('[DashboardController] availableWidgets count: ' . count($availableWidgets));

            return Response::json([
                'success' => true,
                'data' => [
                    'user_widgets' => $userWidgets,
                    'available_widgets' => $availableWidgets,
                ],
            ]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao carregar widgets'], 500);
        }
    }

    /**
     * PUT /api/admin/dashboard/widgets - Salvar preferências de widgets
     */
    public function saveWidgets($data = [])
    {
        $loggedUser = $this->authorize();
        $userId = $loggedUser['id'];
        $widgets = $data['widgets'] ?? [];

        if (!is_array($widgets)) {
            return Response::json(['success' => false, 'message' => 'Widgets inválidos'], 400);
        }

        try {
            $this->db->beginTransaction();
            $this->db->prepare('DELETE FROM dashboard_widgets WHERE user_id = ?')->execute([$userId]);

            $stmt = $this->db->prepare('
                INSERT INTO dashboard_widgets (user_id, widget_key, widget_type, position_order, col_span, is_visible, filters)
                VALUES (?, ?, ?, ?, ?, TRUE, NULL)
            ');

            foreach ($widgets as $index => $widget) {
                $widgetKey = $widget['widget_key'] ?? '';
                $widgetType = $widget['widget_type'] ?? 'kpi';
                $colSpan = $widget['col_span'] ?? 1;

                if (!empty($widgetKey)) {
                    $stmt->execute([$userId, $widgetKey, $widgetType, $index, $colSpan]);
                }
            }

            $this->db->commit();

            return Response::json([
                'success' => true,
                'message' => 'Widgets salvos com sucesso',
            ]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return Response::json(['success' => false, 'message' => 'Erro ao salvar widgets'], 500);
        }
    }

    /**
     * GET /api/admin/dashboard/widgets/available - Lista todos widgets disponíveis
     */
    public function getAvailableWidgets($data = [])
    {
        $this->authorize();

        try {
            $stmt = $this->db->query('
                SELECT widget_key, widget_type, label, description, icon, category, required_permission
                FROM dashboard_available_widgets
                ORDER BY category, widget_key
            ');
            $widgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json([
                'success' => true,
                'data' => $widgets,
            ]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao carregar widgets'], 500);
        }
    }

    /**
     * POST /api/admin/dashboard/widgets/reset - Reseta para default do cargo
     */
    public function resetWidgets($data = [])
    {
        $loggedUser = $this->authorize();
        $userId = $loggedUser['id'];

        try {
            $this->db->prepare('DELETE FROM dashboard_widgets WHERE user_id = ?')->execute([$userId]);

            return Response::json([
                'success' => true,
                'message' => 'Widgets resetados para padrão do cargo',
            ]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao resetar widgets'], 500);
        }
    }

    /**
     * GET /api/company/dashboard - Dashboard principal da empresa
     */
    public function getCompanyDashboard($data = [])
    {
        $loggedUser = Auth::requireAuth();
        $userId = (int)$loggedUser['id'];

        try {
            // 1. Fretes ativos + métricas agregadas
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN f.status IN ('OPEN','PENDING') THEN 1 ELSE 0 END) as active_count,
                    COALESCE(SUM(f.views_count), 0) as total_views,
                    COALESCE(SUM(f.clicks_count), 0) as total_interests
                FROM freights f
                WHERE f.user_id = ?
                  AND f.deleted_at IS NULL
            ");
            $stmt->execute([$userId]);
            $freightStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $freightStats['total'] = (int)$freightStats['total'];
            $freightStats['active_count'] = (int)$freightStats['active_count'];
            $freightStats['total_views'] = (int)$freightStats['total_views'];
            $freightStats['total_interests'] = (int)$freightStats['total_interests'];

            // 2. Fretes por dia (últimos 7 dias)
            $stmt = $this->db->prepare("
                SELECT DATE(f.created_at) as day, COUNT(*) as count
                FROM freights f
                WHERE f.user_id = ?
                  AND f.deleted_at IS NULL
                  AND f.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(f.created_at)
                ORDER BY day ASC
            ");
            $stmt->execute([$userId]);
            $chartRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $chartData = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $dayLabel = $i === 0 ? 'Hoje' : ($i === 1 ? 'Ontem' : date('D', strtotime("-$i days")));
                $found = 0;
                foreach ($chartRaw as $row) {
                    if ($row['day'] === $date) {
                        $found = (int)$row['count'];
                        break;
                    }
                }
                $chartData[] = ['day' => $dayLabel, 'date' => $date, 'count' => $found];
            }

            // 3. Atividades recentes (últimos 5 fretes)
            $stmt = $this->db->prepare("
                SELECT id, product, origin_city, origin_state, dest_city, dest_state,
                       status, views_count, clicks_count, created_at
                FROM freights
                WHERE user_id = ? AND deleted_at IS NULL
                ORDER BY created_at DESC
                LIMIT 5
            ");
            $stmt->execute([$userId]);
            $recentFreights = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $recentActivity = array_map(function ($f) {
                $statusLabel = match ($f['status']) {
                    'OPEN' => 'publicado',
                    'PENDING' => 'ativo',
                    'FINISHED', 'CLOSED' => 'concluído',
                    'CANCELLED' => 'cancelado',
                    default => 'atualizado',
                };
                return [
                    'type' => 'freight',
                    'message' => "Frete #{$f['id']} ({$f['product']}) {$statusLabel} — {$f['origin_city']}/{$f['origin_state']} → {$f['dest_city']}/{$f['dest_state']}",
                    'time' => date('d/m/Y H:i', strtotime($f['created_at'])),
                    'created_at' => $f['created_at'],
                    'freight_id' => (int)$f['id'],
                    'views' => (int)$f['views_count'],
                    'interests' => (int)$f['clicks_count'],
                ];
            }, $recentFreights);

            // 4. Marketplace (se o módulo estiver ativo)
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total
                FROM listings
                WHERE user_id = ? AND deleted_at IS NULL AND status = 'active'
            ");
            $stmt->execute([$userId]);
            $listingCount = (int)$stmt->fetchColumn();

            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(clicks_count), 0) as total_interests
                FROM listings
                WHERE user_id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$userId]);
            $listingInterests = (int)$stmt->fetchColumn();

            // 5. Publicidade (se o módulo estiver ativo)
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total, COALESCE(SUM(clicks_count), 0) as total_clicks
                FROM ads
                WHERE user_id = ? AND status IN ('active', 'pending')
            ");
            $stmt->execute([$userId]);
            $adStats = $stmt->fetch(PDO::FETCH_ASSOC);

            // 6. Consumo do plano
            $usage = [
                'freights' => ['used' => $freightStats['total'], 'limit' => 0, 'remaining' => 0],
                'marketplace' => ['used' => $listingCount, 'limit' => 0, 'remaining' => 0],
            ];

            $stmt = $this->db->prepare("
                SELECT p.limit_monthly, p.name as plan_name
                FROM user_modules um
                JOIN plans p ON p.id = um.plan_id
                WHERE um.user_id = ? AND um.module_key = 'freights'
                  AND um.status = 'active'
                  AND (um.expires_at IS NULL OR um.expires_at >= NOW())
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($plan && $plan['limit_monthly'] > 0) {
                $usage['freights']['limit'] = (int)$plan['limit_monthly'];
                $usage['freights']['remaining'] = max(0, (int)$plan['limit_monthly'] - $usage['freights']['used']);
                $usage['freights']['plan_name'] = $plan['plan_name'];
            } else {
                $stmt = $this->db->prepare("
                    SELECT free_limit FROM pricing_rules
                    WHERE module_key = 'freights' AND feature_key = 'publish' AND is_active = 1
                    LIMIT 1
                ");
                $stmt->execute();
                $pricing = $stmt->fetch(PDO::FETCH_ASSOC);
                $freeLimit = $pricing ? (int)$pricing['free_limit'] : 0;
                $usage['freights']['limit'] = $freeLimit;
                $usage['freights']['remaining'] = max(0, $freeLimit - $usage['freights']['used']);
            }

            return Response::json([
                'success' => true,
                'data' => [
                    'freights' => $freightStats,
                    'marketplace' => [
                        'active_listings' => $listingCount,
                        'total_interests' => $listingInterests,
                    ],
                    'advertising' => [
                        'active_campaigns' => (int)$adStats['total'],
                        'total_clicks' => (int)$adStats['total_clicks'],
                    ],
                    'usage' => $usage,
                    'chart' => $chartData,
                    'recent_activity' => $recentActivity,
                ],
            ]);
        } catch (\Throwable $e) {
            error_log("Erro ao carregar dashboard da empresa: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao carregar dashboard'], 500);
        }
    }
}
