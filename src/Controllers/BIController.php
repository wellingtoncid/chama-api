<?php

namespace App\Controllers;

use App\Core\Response;
use App\Core\Auth;
use App\Services\BIService;

class BIController {
    private $db;

    public function __construct($db, $loggedUser = null) {
        $this->db = $db;
    }

    private function authorize() {
        $user = Auth::getAuthenticatedUser();
        if (!$user) {
            throw new \Exception("Não autorizado", 401);
        }
        if (!Auth::hasPermission('bi.view')) {
            throw new \Exception("Sem permissão para acessar BI", 403);
        }
        return $user;
    }

    /**
     * GET /api/admin/bi - Resumo completo do BI
     */
    public function summary($data = []) {
        $this->authorize();
        $period = $data['period'] ?? 'this_month';

        try {
            $biService = new BIService($this->db, $period);
            $summary = $biService->getSummary();

            return Response::json([
                "success" => true,
                "data" => $summary
            ]);
        } catch (\Throwable $e) {
            error_log("BI Summary Error: " . $e->getMessage());
            return Response::json([
                "success" => false,
                "message" => "Erro ao carregar BI: " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/admin/bi/freights - Métricas de fretes
     */
    public function freights($data = []) {
        $this->authorize();
        $period = $data['period'] ?? 'this_month';
        $type = $data['type'] ?? 'summary';

        try {
            $biService = new BIService($this->db, $period);
            $freights = $biService->getFreights($type);

            return Response::json([
                "success" => true,
                "data" => $freights
            ]);
        } catch (\Throwable $e) {
            return Response::json([
                "success" => false,
                "message" => "Erro ao carregar fretes"
            ], 500);
        }
    }

    /**
     * GET /api/admin/bi/users - Métricas de usuários
     */
    public function users($data = []) {
        $this->authorize();
        $period = $data['period'] ?? 'this_month';

        try {
            $biService = new BIService($this->db, $period);
            $users = $biService->getUsers();
            $drivers = $biService->getDrivers();
            $companies = $biService->getCompanies();

            return Response::json([
                "success" => true,
                "data" => [
                    "users" => $users,
                    "drivers" => $drivers,
                    "companies" => $companies
                ]
            ]);
        } catch (\Throwable $e) {
            return Response::json([
                "success" => false,
                "message" => "Erro ao carregar usuários"
            ], 500);
        }
    }

    /**
     * GET /api/admin/bi/finance - Métricas financeiras
     */
    public function finance($data = []) {
        $this->authorize();
        $period = $data['period'] ?? 'this_month';

        try {
            $biService = new BIService($this->db, $period);
            $finance = $biService->getFinance();
            $plans = $biService->getPlans();
            $ads = $biService->getAds();

            return Response::json([
                "success" => true,
                "data" => [
                    "wallet" => $finance,
                    "plans" => $plans,
                    "ads" => $ads
                ]
            ]);
        } catch (\Throwable $e) {
            return Response::json([
                "success" => false,
                "message" => "Erro ao carregar financeiro"
            ], 500);
        }
    }

    /**
     * GET /api/admin/bi/quotes - Métricas de cotações
     */
    public function quotes($data = []) {
        $this->authorize();
        $period = $data['period'] ?? 'this_month';

        try {
            $biService = new BIService($this->db, $period);
            $quotes = $biService->getQuotes();

            return Response::json([
                "success" => true,
                "data" => $quotes
            ]);
        } catch (\Throwable $e) {
            return Response::json([
                "success" => false,
                "message" => "Erro ao carregar cotações"
            ], 500);
        }
    }

    /**
     * GET /api/admin/bi/support - Métricas de suporte
     */
    public function support($data = []) {
        $this->authorize();
        $period = $data['period'] ?? 'this_month';

        try {
            $biService = new BIService($this->db, $period);
            $tickets = $biService->getTickets();

            return Response::json([
                "success" => true,
                "data" => $tickets
            ]);
        } catch (\Throwable $e) {
            return Response::json([
                "success" => false,
                "message" => "Erro ao carregar suporte"
            ], 500);
        }
    }

    /**
     * GET /api/admin/bi/groups - Métricas de grupos
     */
    public function groups($data = []) {
        $this->authorize();

        try {
            $biService = new BIService($this->db, 'this_month');
            $groups = $biService->getGroups();

            return Response::json([
                "success" => true,
                "data" => $groups
            ]);
        } catch (\Throwable $e) {
            return Response::json([
                "success" => false,
                "message" => "Erro ao carregar grupos"
            ], 500);
        }
    }

    /**
     * GET /api/admin/bi/marketplace - Métricas de marketplace
     */
    public function marketplace($data = []) {
        $this->authorize();
        $period = $data['period'] ?? 'this_month';

        try {
            $biService = new BIService($this->db, $period);
            $marketplace = $biService->getMarketplace();

            return Response::json([
                "success" => true,
                "data" => $marketplace
            ]);
        } catch (\Throwable $e) {
            return Response::json([
                "success" => false,
                "message" => "Erro ao carregar marketplace"
            ], 500);
        }
    }
}