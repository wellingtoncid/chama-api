<?php

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\AdminRepository;
use Exception;
use PDO;

class AdminDashboardController {
    private PDO $db;
    private AdminRepository $repo;
    private ?array $loggedUser;

    public function __construct(PDO $db, ?array $loggedUser = null) {
        $this->db = $db;
        $this->repo = new AdminRepository($db);
        $this->loggedUser = $loggedUser;
    }

    private function authorize(?array $loggedUser = null, string $minRole = 'MANAGER'): void {
        $user = $loggedUser ?? $this->loggedUser;
        if (!$user) throw new Exception("Sessão expirada ou usuário não identificado.", 401);
        $userRole = strtolower($user['role'] ?? '');
        $roleHierarchy = ['admin' => 5, 'manager' => 4, 'analyst' => 3, 'assistant' => 2];
        $userLevel = $roleHierarchy[$userRole] ?? 0;
        $requiredLevel = $roleHierarchy[strtolower($minRole)] ?? 0;
        if ($userLevel < $requiredLevel) throw new Exception("Acesso negado. Permissão insuficiente.", 403);
    }

    public function getDashboardData($data = [], $loggedUser = null) {
        $this->authorize($loggedUser);
        try {
            $rawStats = $this->repo->getDashboardStats();
            $formattedData = [
                "stats" => [
                    "total_pending"      => (int)($rawStats['counters']['pending_freights'] ?? 0),
                    "revenue"            => (string)($rawStats['revenue']['confirmed'] ?? "0.00"),
                    "pending_revenue"    => (string)($rawStats['revenue']['pending'] ?? "0.00"),
                    "total_users"        => (int)($rawStats['counters']['total_users'] ?? 0),
                    "drivers"            => (int)($rawStats['counters']['drivers'] ?? 0),
                    "companies"          => (int)($rawStats['counters']['companies'] ?? 0),
                    "advertisers"        => (int)($rawStats['counters']['advertisers'] ?? 0),
                    "active_freights"    => (int)($rawStats['counters']['active_freights'] ?? 0),
                    "featured_freights"  => 0,
                    "total_interactions" => (int)($rawStats['counters']['total_clicks'] ?? 0),
                    "conversion_rate"    => (isset($rawStats['counters']['total_views']) && $rawStats['counters']['total_views'] > 0) ?
                        round(($rawStats['counters']['total_clicks'] / $rawStats['counters']['total_views']) * 100, 1) : 0
                ],
                "pending_approvals" => $this->repo->getPendingFreights() ?: [],
                "recent_activities" => $this->repo->getRecentActivities() ?: []
            ];
            return Response::json(["success" => true, "data" => $formattedData]);
        } catch (Exception $e) {
            return Response::json(["success" => false, "message" => "Erro ao processar dashboard"], 500);
        }
    }

    public function getHomeStats($data = [], $loggedUser = null) {
        if (!$loggedUser) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        $role = strtoupper($loggedUser['role'] ?? '');
        try {
            $stats = [];
            $stmt = $this->db->query("SELECT COUNT(*) as total, SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as new_30d, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending FROM users WHERE deleted_at IS NULL");
            $userStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['users'] = ['total' => (int)($userStats['total'] ?? 0), 'new_30d' => (int)($userStats['new_30d'] ?? 0), 'pending' => (int)($userStats['pending'] ?? 0)];

            $stmt = $this->db->query("SELECT COUNT(*) as total FROM users WHERE deleted_at IS NULL AND role = 'company'");
            $stats['companies'] = ['total' => (int)$stmt->fetch()['total']];

            $stmt = $this->db->query("SELECT COUNT(*) as total, SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as new_7d FROM freights WHERE deleted_at IS NULL");
            $fStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['freights'] = ['total' => (int)($fStats['total'] ?? 0), 'new_7d' => (int)($fStats['new_7d'] ?? 0)];

            $stmt = $this->db->query("SELECT COUNT(*) as total FROM listings WHERE status = 'active'");
            $stats['listings'] = ['total' => (int)$stmt->fetch()['total']];

            $stmt = $this->db->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open, SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed FROM quotes");
            $qStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['quotes'] = ['total' => (int)($qStats['total'] ?? 0), 'open' => (int)($qStats['open'] ?? 0), 'closed' => (int)($qStats['closed'] ?? 0)];

            $stmt = $this->db->query("SELECT module_key, COUNT(*) as total FROM user_modules WHERE status = 'active' GROUP BY module_key");
            $modules = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                if (isset($m['module_key'])) $modules[$m['module_key']] = (int)$m['total'];
            }
            $stats['modules'] = $modules;

            $stmt = $this->db->query("SELECT COUNT(*) as total FROM transactions WHERE status = 'approved' AND DATE_ADD(created_at, INTERVAL COALESCE(duration_days, 30) DAY) > NOW()");
            $stats['active_plans'] = ['total' => (int)$stmt->fetch()['total']];

            $stmt = $this->db->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'OPEN' THEN 1 ELSE 0 END) as open, SUM(CASE WHEN status = 'CLOSED' THEN 1 ELSE 0 END) as closed FROM support_tickets");
            $tStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['support_tickets'] = ['total' => (int)($tStats['total'] ?? 0), 'open' => (int)($tStats['open'] ?? 0), 'closed' => (int)($tStats['closed'] ?? 0)];

            $recentActivities = $this->repo->getRecentActivities(10);

            $stmt = $this->db->query("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE status = 'approved' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
            $stats['revenue'] = ['month' => (float)$stmt->fetch()['total']];

            $stmt = $this->db->query("SELECT COUNT(*) as total FROM reports WHERE status = 'pending'");
            $stats['reports'] = ['pending' => (int)$stmt->fetch()['total']];

            $stmt = $this->db->query("SELECT COUNT(*) as total FROM portal_requests WHERE deleted_at IS NULL");
            $stats['leads'] = ['total' => (int)$stmt->fetch()['total']];

            return Response::json(['success' => true, 'data' => $stats, 'recent_activities' => $recentActivities, 'user_role' => $role]);
        } catch (Exception $e) {
            error_log("Erro getHomeStats: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao carregar dados: ' . $e->getMessage()], 500);
        }
    }

    public function getBIStats($data = [], $loggedUser = null) {
        if (!$loggedUser) return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        $role = strtoupper($loggedUser['role'] ?? '');
        if (!in_array($role, ['ADMIN', 'MANAGER'])) return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);

        $period = $data['period'] ?? 'this_month';
        $startDate = $endDate = $prevStart = $prevEnd = null;
        switch ($period) {
            case 'this_month':
                $startDate = date('Y-m-01'); $endDate = date('Y-m-d');
                $prevStart = date('Y-m-01', strtotime('-1 month')); $prevEnd = date('Y-m-t', strtotime('-1 month'));
                break;
            case 'last_month':
                $startDate = date('Y-m-01', strtotime('-1 month')); $endDate = date('Y-m-t', strtotime('-1 month'));
                $prevStart = date('Y-m-01', strtotime('-2 months')); $prevEnd = date('Y-m-t', strtotime('-1 month'));
                break;
            case 'last_3_months':
                $startDate = date('Y-m-01', strtotime('-3 months')); $endDate = date('Y-m-d');
                $prevStart = date('Y-m-01', strtotime('-6 months')); $prevEnd = date('Y-m-t', strtotime('-3 months'));
                break;
            case 'custom':
                $startDate = $data['start'] ?? date('Y-m-01'); $endDate = $data['end'] ?? date('Y-m-d');
                $prevStart = date('Y-m-01', strtotime($startDate . ' -' . (ceil((strtotime($endDate) - strtotime($startDate)) / 30) + 1) . ' months'));
                $prevEnd = date('Y-m-t', strtotime($startDate . ' -1 day'));
                break;
            default:
                $startDate = date('Y-m-01'); $endDate = date('Y-m-d');
                $prevStart = date('Y-m-01', strtotime('-1 month')); $prevEnd = date('Y-m-t', strtotime('-1 month'));
        }

        try {
            $currentFreights = $this->repo->getFreightsCountByDateRange($startDate, $endDate . ' 23:59:59');
            $prevFreights = $this->repo->getFreightsCountByDateRange($prevStart, $prevEnd . ' 23:59:59');
            $stats['freights'] = ['current' => $currentFreights, 'previous' => $prevFreights, 'growth' => $prevFreights > 0 ? round(($currentFreights - $prevFreights) / $prevFreights * 100, 1) : 0];

            $currentUsers = $this->repo->getUsersCountByDateRange($startDate, $endDate . ' 23:59:59');
            $prevUsers = $this->repo->getUsersCountByDateRange($prevStart, $prevEnd . ' 23:59:59');
            $stats['users'] = ['current' => $currentUsers, 'previous' => $prevUsers, 'growth' => $prevUsers > 0 ? round(($currentUsers - $prevUsers) / $prevUsers * 100, 1) : 0];

            $stmt = $this->db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open, SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed FROM quotes WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $qStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM quotes WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$prevStart, $prevEnd . ' 23:59:59']);
            $prevQuotes = (int)$stmt->fetch()['total'];
            $stats['quotes'] = ['total' => (int)$qStats['total'], 'open' => (int)$qStats['open'], 'closed' => (int)$qStats['closed'], 'previous' => $prevQuotes, 'growth' => $prevQuotes > 0 ? round(((int)$qStats['total'] - $prevQuotes) / $prevQuotes * 100, 1) : 0];

            $currentRevenue = $this->repo->getRevenueByDateRange($startDate, $endDate . ' 23:59:59');
            $prevRevenue = $this->repo->getRevenueByDateRange($prevStart, $prevEnd . ' 23:59:59');
            $stats['revenue'] = ['current' => $currentRevenue, 'previous' => $prevRevenue, 'growth' => $prevRevenue > 0 ? round(($currentRevenue - $prevRevenue) / $prevRevenue * 100, 1) : 0];

            $currentCompanies = $this->repo->getCompaniesCountByDateRange($startDate, $endDate . ' 23:59:59');
            $prevCompanies = $this->repo->getCompaniesCountByDateRange($prevStart, $prevEnd . ' 23:59:59');
            $stats['companies'] = ['current' => $currentCompanies, 'previous' => $prevCompanies, 'growth' => $prevCompanies > 0 ? round(($currentCompanies - $prevCompanies) / $prevCompanies * 100, 1) : 0];

            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM listings WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $currentListings = (int)$stmt->fetch()['total'];
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM listings WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$prevStart, $prevEnd . ' 23:59:59']);
            $prevListings = (int)$stmt->fetch()['total'];
            $stats['listings'] = ['current' => $currentListings, 'previous' => $prevListings, 'growth' => $prevListings > 0 ? round(($currentListings - $prevListings) / $prevListings * 100, 1) : 0];

            $stmt = $this->db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'OPEN' THEN 1 ELSE 0 END) as open, SUM(CASE WHEN status = 'CLOSED' THEN 1 ELSE 0 END) as closed FROM support_tickets WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $tStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM support_tickets WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$prevStart, $prevEnd . ' 23:59:59']);
            $prevTickets = (int)$stmt->fetch()['total'];
            $stats['tickets'] = ['total' => (int)$tStats['total'], 'open' => (int)$tStats['open'], 'closed' => (int)$tStats['closed'], 'previous' => $prevTickets, 'growth' => $prevTickets > 0 ? round(((int)$tStats['total'] - $prevTickets) / $prevTickets * 100, 1) : 0];

            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM transactions WHERE status = 'approved' AND DATE_ADD(created_at, INTERVAL COALESCE(duration_days, 30) DAY) > NOW() AND created_at BETWEEN ? AND ?");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $currentPlans = (int)$stmt->fetch()['total'];
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM transactions WHERE status = 'approved' AND DATE_ADD(created_at, INTERVAL COALESCE(duration_days, 30) DAY) > NOW() AND created_at BETWEEN ? AND ?");
            $stmt->execute([$prevStart, $prevEnd . ' 23:59:59']);
            $prevPlans = (int)$stmt->fetch()['total'];
            $stats['plans'] = ['current' => $currentPlans, 'previous' => $prevPlans, 'growth' => $prevPlans > 0 ? round(($currentPlans - $prevPlans) / $prevPlans * 100, 1) : 0];

            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM ads WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $currentAds = (int)$stmt->fetch()['total'];
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM ads WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$prevStart, $prevEnd . ' 23:59:59']);
            $prevAds = (int)$stmt->fetch()['total'];
            $stats['ads'] = ['current' => $currentAds, 'previous' => $prevAds, 'growth' => $prevAds > 0 ? round(($currentAds - $prevAds) / $prevAds * 100, 1) : 0];

            $stmt = $this->db->prepare("SELECT DATE(created_at) as date, COUNT(*) as total FROM freights WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY date");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $freightsByDay = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $this->db->prepare("SELECT DATE(created_at) as date, COUNT(*) as total FROM users WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY date");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $usersByDay = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $this->db->prepare("SELECT origin_city, COUNT(*) as total FROM freights WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ? AND origin_city IS NOT NULL AND origin_city != '' GROUP BY origin_city ORDER BY total DESC LIMIT 10");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $topCities = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json(['success' => true, 'data' => $stats, 'charts' => ['freights_by_day' => $freightsByDay, 'users_by_day' => $usersByDay], 'top_cities' => $topCities, 'period' => ['start' => $startDate, 'end' => $endDate]]);
        } catch (Exception $e) {
            error_log("Erro getBIStats: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao carregar dados'], 500);
        }
    }

    public function getRevenueReport($data, $loggedUser) {
        $this->authorize($loggedUser, 'ADMIN');
        return Response::json(["success" => true, "data" => $this->repo->getDetailedRevenue()]);
    }

    public function getFinancialStats($data, $loggedUser) {
        $this->authorize($loggedUser, 'ADMIN');
        return Response::json(["success" => true, "data" => $this->repo->getFinancialStats()]);
    }
}
