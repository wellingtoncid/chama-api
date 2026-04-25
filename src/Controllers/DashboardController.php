<?php

namespace App\Controllers;

use App\Core\Response;
use App\Core\Auth;
use PDO;

class DashboardController {
    private $db;

    public function __construct($db, $loggedUser = null) {
        $this->db = $db;
    }
    
    private function authorize() {
        $user = Auth::getAuthenticatedUser();
        if (!$user) {
            throw new \Exception("Não autorizado", 401);
        }
        return $user;
    }

    /**
     * Mapeamento de widgets padrão por role (sincronizado com tabela roles)
     */
    private function getDefaultWidgetsForRole(string $role): array {
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
    public function getWidgets($data = []) {
        $loggedUser = $this->authorize();
        $userId = $loggedUser['id'];
        $role = $loggedUser['role'] ?? 'guest';
        
        error_log("[DashboardController] getWidgets - userId: $userId, role: $role");

        try {
            $stmt = $this->db->prepare("
                SELECT widget_key, widget_type, position_order, col_span, is_visible, filters
                FROM dashboard_widgets 
                WHERE user_id = ?
                ORDER BY position_order ASC
            ");
            $stmt->execute([$userId]);
            $userWidgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($userWidgets)) {
                $userWidgets = $this->getDefaultWidgetsForRole($role);
            }

            $stmt = $this->db->query("
                SELECT widget_key, widget_type, label, description, icon, category
                FROM dashboard_available_widgets 
                ORDER BY category, widget_key
            ");
            $availableWidgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("[DashboardController] availableWidgets count: " . count($availableWidgets));

            return Response::json([
                "success" => true,
                "data" => [
                    "user_widgets" => $userWidgets,
                    "available_widgets" => $availableWidgets
                ]
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao carregar widgets"], 500);
        }
    }

    /**
     * PUT /api/admin/dashboard/widgets - Salvar preferências de widgets
     */
    public function saveWidgets($data = []) {
        $loggedUser = $this->authorize();
        $userId = $loggedUser['id'];
        $widgets = $data['widgets'] ?? [];

        if (!is_array($widgets)) {
            return Response::json(["success" => false, "message" => "Widgets inválidos"], 400);
        }

        try {
            $this->db->beginTransaction();
            $this->db->prepare("DELETE FROM dashboard_widgets WHERE user_id = ?")->execute([$userId]);

            $stmt = $this->db->prepare("
                INSERT INTO dashboard_widgets (user_id, widget_key, widget_type, position_order, col_span, is_visible, filters)
                VALUES (?, ?, ?, ?, ?, TRUE, NULL)
            ");

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
                "success" => true,
                "message" => "Widgets salvos com sucesso"
            ]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return Response::json(["success" => false, "message" => "Erro ao salvar widgets"], 500);
        }
    }

    /**
     * GET /api/admin/dashboard/widgets/available - Lista todos widgets disponíveis
     */
    public function getAvailableWidgets($data = []) {
        $this->authorize();

        try {
            $stmt = $this->db->query("
                SELECT widget_key, widget_type, label, description, icon, category, required_permission
                FROM dashboard_available_widgets 
                ORDER BY category, widget_key
            ");
            $widgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json([
                "success" => true,
                "data" => $widgets
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao carregar widgets"], 500);
        }
    }

    /**
     * POST /api/admin/dashboard/widgets/reset - Reseta para default do cargo
     */
    public function resetWidgets($data = []) {
        $loggedUser = $this->authorize();
        $userId = $loggedUser['id'];

        try {
            $this->db->prepare("DELETE FROM dashboard_widgets WHERE user_id = ?")->execute([$userId]);

            return Response::json([
                "success" => true,
                "message" => "Widgets resetados para padrão do cargo"
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao resetar widgets"], 500);
        }
    }
}