<?php

namespace App\Controllers;

use App\Controllers\NotificationController;
use App\Core\Response;
use App\Repositories\AdminRepository;
use App\Repositories\AdRepository;
use App\Repositories\GroupRepository;
use App\Repositories\FreightRepository;
use App\Repositories\UserRepository;
use App\Services\CreditService;
use Exception;
use PDO;


class AdminController {
    private $repo;
    private $db;
    private $notif;
    private $creditService;
    private $loggedUser;
    private $groupRepo;
    private $freightRepo;
    private $adRepo;
    private $adminRepo;

    public function __construct($db, $adminRepo = null, $loggedUser = null) {
        $this->db = $db;
        $this->adminRepo = $adminRepo ?: new AdminRepository($db);
        $this->repo = new AdminRepository($db);
        $this->groupRepo = new GroupRepository($db);
        $this->freightRepo = new FreightRepository($db);
        $this->adRepo = new AdRepository($db);
        $this->creditService = new CreditService($db);
        $this->loggedUser = $loggedUser; 

        // Definição do caminho antes do uso
        $notifPath = __DIR__ . '/NotificationController.php';
        
        if (file_exists($notifPath)) {
            require_once $notifPath;
            if (class_exists('NotificationController')) {
                $this->notif = new NotificationController($db);
            }
        }
    }

    /**
     * Middleware de Segurança
     */
    private function authorize($loggedUser = null, $minRole = 'MANAGER') {
        $user = $loggedUser ?? $this->loggedUser;
        
        if (!$user) {
            throw new Exception("Sessão expirada ou usuário não identificado.", 401);
        }

        $userRole = strtolower($user['role'] ?? '');
        $requiredRole = strtolower($minRole);

        $isUserAdmin = ($userRole === 'admin');
        $isUserManager = ($userRole === 'manager');

        if ($requiredRole === 'admin') {
            if (!$isUserAdmin) {
                throw new Exception("Acesso negado: Requer nível ADMINISTRADOR.", 403);
            }
        } elseif ($requiredRole === 'manager') {
            if (!$isUserAdmin && !$isUserManager) {
                throw new Exception("Acesso negado: Requer nível GERENTE ou superior.", 403);
            }
        } else {
            if (!$isUserAdmin && !$isUserManager && $userRole !== $requiredRole) {
                throw new Exception("Acesso negado: Permissão insuficiente.", 403);
            }
        }

        return true; 
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

            return Response::json([
                "success" => true, 
                "data" => $formattedData
            ]);
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
            
            // Usuários - verificando se a tabela existe e tem dados
            $stmt = $this->db->query("SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as new_30d
                FROM users WHERE deleted_at IS NULL");
            $userStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['users'] = [
                'total' => isset($userStats['total']) ? (int)$userStats['total'] : 0,
                'new_30d' => isset($userStats['new_30d']) ? (int)$userStats['new_30d'] : 0
            ];

            // Empresas
            $stmt = $this->db->query("SELECT 
                COUNT(*) as total
                FROM users 
                WHERE deleted_at IS NULL AND role = 'company'");
            $companyStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['companies'] = ['total' => isset($companyStats['total']) ? (int)$companyStats['total'] : 0];

            // Fretes
            $stmt = $this->db->query("SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as new_7d
                FROM freights WHERE deleted_at IS NULL");
            $freightStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['freights'] = [
                'total' => isset($freightStats['total']) ? (int)$freightStats['total'] : 0,
                'new_7d' => isset($freightStats['new_7d']) ? (int)$freightStats['new_7d'] : 0
            ];

            // Anúncios Marketplace
            $stmt = $this->db->query("SELECT COUNT(*) as total FROM listings WHERE status = 'active'");
            $listingStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['listings'] = ['total' => isset($listingStats['total']) ? (int)$listingStats['total'] : 0];

            // Cotações
            $stmt = $this->db->query("SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed
                FROM quotes");
            $quoteStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['quotes'] = [
                'total' => isset($quoteStats['total']) ? (int)$quoteStats['total'] : 0,
                'open' => isset($quoteStats['open']) ? (int)$quoteStats['open'] : 0,
                'closed' => isset($quoteStats['closed']) ? (int)$quoteStats['closed'] : 0
            ];

            // Módulos ativos
            $stmt = $this->db->query("SELECT module_key, COUNT(*) as total FROM user_modules WHERE status = 'active' GROUP BY module_key");
            $moduleStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $modules = [];
            foreach ($moduleStats as $m) {
                if (isset($m['module_key'])) {
                    $modules[$m['module_key']] = (int)$m['total'];
                }
            }
            $stats['modules'] = $modules;

            // Planos ativos (baseado em duração)
            $stmt = $this->db->query("SELECT COUNT(*) as total FROM transactions WHERE status = 'approved' AND DATE_ADD(created_at, INTERVAL COALESCE(duration_days, 30) DAY) > NOW()");
            $planStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['active_plans'] = ['total' => isset($planStats['total']) ? (int)$planStats['total'] : 0];

            // Tickets de suporte
            $stmt = $this->db->query("SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'OPEN' THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN status = 'CLOSED' THEN 1 ELSE 0 END) as closed
                FROM support_tickets");
            $ticketStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['support_tickets'] = [
                'total' => isset($ticketStats['total']) ? (int)$ticketStats['total'] : 0,
                'open' => isset($ticketStats['open']) ? (int)$ticketStats['open'] : 0,
                'closed' => isset($ticketStats['closed']) ? (int)$ticketStats['closed'] : 0
            ];

            // Atividades recentes
            $recentActivities = $this->repo->getRecentActivities(10);
            
            // Receita do mês
            $stmt = $this->db->query("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE status = 'approved' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
            $revenueStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['revenue'] = ['month' => isset($revenueStats['total']) ? (float)$revenueStats['total'] : 0.0];

            return Response::json([
                'success' => true,
                'data' => $stats,
                'recent_activities' => $recentActivities,
                'user_role' => $role
            ]);
        } catch (Exception $e) {
            error_log("Erro getHomeStats: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao carregar dados: ' . $e->getMessage()], 500);
        }
    }

    public function getBIStats($data = [], $loggedUser = null) {
        if (!$loggedUser) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $role = strtoupper($loggedUser['role'] ?? '');
        if (!in_array($role, ['ADMIN', 'MANAGER'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }

        // Parse period
        $period = $data['period'] ?? 'this_month';
        $startDate = null;
        $endDate = null;

        switch ($period) {
            case 'this_month':
                $startDate = date('Y-m-01');
                $endDate = date('Y-m-d');
                $prevStart = date('Y-m-01', strtotime('-1 month'));
                $prevEnd = date('Y-m-t', strtotime('-1 month'));
                break;
            case 'last_month':
                $startDate = date('Y-m-01', strtotime('-1 month'));
                $endDate = date('Y-m-t', strtotime('-1 month'));
                $prevStart = date('Y-m-01', strtotime('-2 months'));
                $prevEnd = date('Y-m-t', strtotime('-1 month'));
                break;
            case 'last_3_months':
                $startDate = date('Y-m-01', strtotime('-3 months'));
                $endDate = date('Y-m-d');
                $prevStart = date('Y-m-01', strtotime('-6 months'));
                $prevEnd = date('Y-m-t', strtotime('-3 months'));
                break;
            case 'custom':
                $startDate = $data['start'] ?? date('Y-m-01');
                $endDate = $data['end'] ?? date('Y-m-d');
                $prevStart = date('Y-m-01', strtotime($startDate . ' -' . (ceil((strtotime($endDate) - strtotime($startDate)) / 30) + 1) . ' months'));
                $prevEnd = date('Y-m-t', strtotime($startDate . ' -1 day'));
                break;
            default:
                $startDate = date('Y-m-01');
                $endDate = date('Y-m-d');
                $prevStart = date('Y-m-01', strtotime('-1 month'));
                $prevEnd = date('Y-m-t', strtotime('-1 month'));
        }

        try {
            // Current period stats
            $stats = [];

            // Fretes
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM freights WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ?");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $currentFreights = (int)$stmt->fetch()['total'];

            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM freights WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ?");
            $stmt->execute([$prevStart, $prevEnd . ' 23:59:59']);
            $prevFreights = (int)$stmt->fetch()['total'];
            
            $stats['freights'] = [
                'current' => $currentFreights,
                'previous' => $prevFreights,
                'growth' => $prevFreights > 0 ? round(($currentFreights - $prevFreights) / $prevFreights * 100, 1) : 0
            ];

            // Usuários
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM users WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ?");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $currentUsers = (int)$stmt->fetch()['total'];

            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM users WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ?");
            $stmt->execute([$prevStart, $prevEnd . ' 23:59:59']);
            $prevUsers = (int)$stmt->fetch()['total'];

            $stats['users'] = [
                'current' => $currentUsers,
                'previous' => $prevUsers,
                'growth' => $prevUsers > 0 ? round(($currentUsers - $prevUsers) / $prevUsers * 100, 1) : 0
            ];

            // Cotações
            $stmt = $this->db->prepare("SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed
                FROM quotes WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $quoteStats = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM quotes WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$prevStart, $prevEnd . ' 23:59:59']);
            $prevQuotes = (int)$stmt->fetch()['total'];

            $stats['quotes'] = [
                'total' => (int)$quoteStats['total'],
                'open' => (int)$quoteStats['open'],
                'closed' => (int)$quoteStats['closed'],
                'previous' => $prevQuotes,
                'growth' => $prevQuotes > 0 ? round(((int)$quoteStats['total'] - $prevQuotes) / $prevQuotes * 100, 1) : 0
            ];

            // Receita
            $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE status = 'approved' AND created_at BETWEEN ? AND ?");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $currentRevenue = (float)$stmt->fetch()['total'];

            $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE status = 'approved' AND created_at BETWEEN ? AND ?");
            $stmt->execute([$prevStart, $prevEnd . ' 23:59:59']);
            $prevRevenue = (float)$stmt->fetch()['total'];

            $stats['revenue'] = [
                'current' => $currentRevenue,
                'previous' => $prevRevenue,
                'growth' => $prevRevenue > 0 ? round(($currentRevenue - $prevRevenue) / $prevRevenue * 100, 1) : 0
            ];

            // Empresas
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM users WHERE deleted_at IS NULL AND role = 'company' AND created_at BETWEEN ? AND ?");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $currentCompanies = (int)$stmt->fetch()['total'];

            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM users WHERE deleted_at IS NULL AND role = 'company' AND created_at BETWEEN ? AND ?");
            $stmt->execute([$prevStart, $prevEnd . ' 23:59:59']);
            $prevCompanies = (int)$stmt->fetch()['total'];

            $stats['companies'] = [
                'current' => $currentCompanies,
                'previous' => $prevCompanies,
                'growth' => $prevCompanies > 0 ? round(($currentCompanies - $prevCompanies) / $prevCompanies * 100, 1) : 0
            ];

            // Anúncios Marketplace
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM listings WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $currentListings = (int)$stmt->fetch()['total'];

            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM listings WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$prevStart, $prevEnd . ' 23:59:59']);
            $prevListings = (int)$stmt->fetch()['total'];

            $stats['listings'] = [
                'current' => $currentListings,
                'previous' => $prevListings,
                'growth' => $prevListings > 0 ? round(($currentListings - $prevListings) / $prevListings * 100, 1) : 0
            ];

            // Tickets de Suporte
            $stmt = $this->db->prepare("SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'OPEN' THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN status = 'CLOSED' THEN 1 ELSE 0 END) as closed
                FROM support_tickets WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $ticketStats = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM support_tickets WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$prevStart, $prevEnd . ' 23:59:59']);
            $prevTickets = (int)$stmt->fetch()['total'];

            $stats['tickets'] = [
                'total' => (int)$ticketStats['total'],
                'open' => (int)$ticketStats['open'],
                'closed' => (int)$ticketStats['closed'],
                'previous' => $prevTickets,
                'growth' => $prevTickets > 0 ? round(((int)$ticketStats['total'] - $prevTickets) / $prevTickets * 100, 1) : 0
            ];

            // Planos Ativos
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM transactions WHERE status = 'approved' AND DATE_ADD(created_at, INTERVAL COALESCE(duration_days, 30) DAY) > NOW() AND created_at BETWEEN ? AND ?");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $currentPlans = (int)$stmt->fetch()['total'];

            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM transactions WHERE status = 'approved' AND DATE_ADD(created_at, INTERVAL COALESCE(duration_days, 30) DAY) > NOW() AND created_at BETWEEN ? AND ?");
            $stmt->execute([$prevStart, $prevEnd . ' 23:59:59']);
            $prevPlans = (int)$stmt->fetch()['total'];

            $stats['plans'] = [
                'current' => $currentPlans,
                'previous' => $prevPlans,
                'growth' => $prevPlans > 0 ? round(($currentPlans - $prevPlans) / $prevPlans * 100, 1) : 0
            ];

            // Anúncios Publicitários (ads)
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM ads WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $currentAds = (int)$stmt->fetch()['total'];

            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM ads WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$prevStart, $prevEnd . ' 23:59:59']);
            $prevAds = (int)$stmt->fetch()['total'];

            $stats['ads'] = [
                'current' => $currentAds,
                'previous' => $prevAds,
                'growth' => $prevAds > 0 ? round(($currentAds - $prevAds) / $prevAds * 100, 1) : 0
            ];

            // Chart data - Fretes por dia
            $stmt = $this->db->prepare("SELECT DATE(created_at) as date, COUNT(*) as total FROM freights WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY date");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $freightsByDay = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Chart data - Usuários por dia
            $stmt = $this->db->prepare("SELECT DATE(created_at) as date, COUNT(*) as total FROM users WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY date");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $usersByDay = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Top cidades (fretes)
            $stmt = $this->db->prepare("SELECT origin_city, COUNT(*) as total FROM freights WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ? AND origin_city IS NOT NULL AND origin_city != '' GROUP BY origin_city ORDER BY total DESC LIMIT 10");
            $stmt->execute([$startDate, $endDate . ' 23:59:59']);
            $topCities = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json([
                'success' => true,
                'data' => $stats,
                'charts' => [
                    'freights_by_day' => $freightsByDay,
                    'users_by_day' => $usersByDay
                ],
                'top_cities' => $topCities,
                'period' => [
                    'start' => $startDate,
                    'end' => $endDate
                ]
            ]);
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

    public function listLogs($data, $loggedUser) {
        if (!$this->adminRepo) {
             $this->adminRepo = $this->repo; 
        }

        $role = strtolower($loggedUser['role'] ?? '');
        if (!$loggedUser || $role !== 'admin') {
            return Response::json(["success" => false, "message" => "Não autorizado"], 403);
        }

        $limit = isset($data['limit']) ? (int)$data['limit'] : 50;
        $logs = $this->adminRepo->getAuditLogs($limit); 

        return Response::json([
            "success" => true, 
            "data" => $logs
        ]);
    }

    // --- GESTÃO DE USUÁRIOS ---

    public function createUser($loggedUser) {
        try {
            // 1. SEGURANÇA: Apenas ADMIN ou MANAGER podem criar usuários
            $this->authorize($loggedUser, 'MANAGER');

            // 2. CAPTURA: Pega o input JSON do Frontend (Axios/Fetch)
            $input = json_decode(file_get_contents('php://input'), true);

            if (!$input) {
                return Response::json(["success" => false, "message" => "Dados inválidos"], 400);
            }

            $userType = strtoupper($input['user_type'] ?? '');

            // 3. VALIDAÇÃO POR TIPO DE USUÁRIO
            if ($userType === 'DRIVER') {
                // Motorista: nome, CPF, email, senha obrigatórios
                $requiredFields = ['name', 'document', 'email', 'password'];
                foreach ($requiredFields as $field) {
                    if (empty($input[$field])) {
                        return Response::json(["success" => false, "message" => "O campo $field é obrigatório para Motorista"], 400);
                    }
                }
                // Validar CPF (11 dígitos)
                $cpf = preg_replace('/\D/', '', $input['document']);
                if (strlen($cpf) !== 11) {
                    return Response::json(["success" => false, "message" => "CPF deve ter 11 dígitos"], 400);
                }
            } elseif ($userType === 'COMPANY') {
                // Empresa: razão social, CNPJ, nome do responsável, email, senha obrigatórios
                $requiredFields = ['name', 'document', 'email', 'password', 'owner_name'];
                foreach ($requiredFields as $field) {
                    if (empty($input[$field])) {
                        return Response::json(["success" => false, "message" => "O campo $field é obrigatório para Empresa"], 400);
                    }
                }
                // Validar CNPJ (14 dígitos)
                $cnpj = preg_replace('/\D/', '', $input['document']);
                if (strlen($cnpj) !== 14) {
                    return Response::json(["success" => false, "message" => "CNPJ deve ter 14 dígitos"], 400);
                }
            } elseif ($userType === 'SYSTEM') {
                // Sistema: nome, email, senha, role obrigatórios
                $requiredFields = ['name', 'email', 'password', 'role'];
                foreach ($requiredFields as $field) {
                    if (empty($input[$field])) {
                        return Response::json(["success" => false, "message" => "O campo $field é obrigatório para usuário do Sistema"], 400);
                    }
                }
            } else {
                return Response::json(["success" => false, "message" => "Tipo de usuário inválido. Use: DRIVER, COMPANY ou SYSTEM"], 400);
            }

            if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
                return Response::json(["success" => false, "message" => "Email em formato inválido"], 400);
            }

            // 4. CRIAÇÃO DO USUÁRIO
            $userRepo = new UserRepository($this->db);

            if ($userType === 'SYSTEM') {
                // Sistema: cria usuário direto na tabela users, vinculado ao account da Chama Frete
                $stmtCheck = $this->db->prepare("SELECT id FROM users WHERE email = ?");
                $stmtCheck->execute([$input['email']]);
                if ($stmtCheck->fetch()) {
                    return Response::json(["success" => false, "message" => "Este email já está cadastrado"], 400);
                }

                $roleSlug = strtolower($input['role']);
                $stmtRole = $this->db->prepare("SELECT id FROM roles WHERE slug = ? LIMIT 1");
                $stmtRole->execute([$roleSlug]);
                $roleId = $stmtRole->fetchColumn() ?: 2;

                $stmtInsert = $this->db->prepare("INSERT INTO users (
                    name, email, password, whatsapp, role, role_id, user_type, account_id, status, plan_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'OPERATOR', 1, 'active', 1, NOW())");
                
                $stmtInsert->execute([
                    $input['name'],
                    $input['email'],
                    password_hash($input['password'], PASSWORD_BCRYPT),
                    preg_replace('/\D/', '', $input['whatsapp'] ?? ''),
                    $roleSlug,
                    $roleId
                ]);
                
                $userId = $this->db->lastInsertId();

                // Criar perfil básico
                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $input['name']))) . '-' . $userId;
                $this->db->prepare("INSERT INTO user_profiles (user_id, slug, availability_status, created_at) VALUES (?, ?, 'available', NOW())")
                    ->execute([$userId, $slug]);

                return Response::json([
                    "success" => true,
                    "message" => "Usuário do sistema criado com sucesso!",
                    "user_id" => $userId
                ], 201);

            } else {
                // Motorista ou Empresa: usa o mesmo fluxo do cadastro público
                $dataForRepo = [
                    'name' => $input['name'], // Para empresa: Razão Social
                    'email' => $input['email'],
                    'password' => password_hash($input['password'], PASSWORD_BCRYPT),
                    'whatsapp' => $input['whatsapp'] ?? '',
                    'document' => $input['document'],
                    'document_type' => ($userType === 'COMPANY') ? 'CNPJ' : 'CPF',
                    'role' => ($userType === 'COMPANY') ? 'company' : 'driver',
                    'user_type' => $userType
                ];

                // Para empresas, adicionar campos específicos
                if ($userType === 'COMPANY') {
                    $dataForRepo['owner_name'] = $input['owner_name']; // Nome do responsável
                    $dataForRepo['corporate_name'] = $input['name']; // Razão Social
                    $dataForRepo['name_fantasy'] = $input['name_fantasy'] ?? null; // Nome Fantasia
                }

                try {
                    $userId = $userRepo->create($dataForRepo);
                    return Response::json([
                        "success" => true,
                        "message" => ($userType === 'COMPANY' ? 'Empresa' : 'Motorista') . " criado com sucesso!",
                        "user_id" => $userId
                    ], 201);
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), '1062') !== false) {
                        return Response::json(["success" => false, "message" => "Este email ou documento já está cadastrado no sistema."], 409);
                    }
                    throw $e;
                }
            }

        } catch (Exception $e) {
            return Response::json([
                "success" => false, 
                "message" => "Erro interno no servidor: " . $e->getMessage()
            ], 500);
        }
    }

    public function createInternalUser($loggedUser) {
        try {
            // Apenas ADMIN master pode criar outros administradores ou gerentes
            $this->authorize($loggedUser, 'admin');

            $input = json_decode(file_get_contents('php://input'), true);

            // Validação específica para internos
            $required = ['name', 'email', 'password', 'role'];
            foreach ($required as $field) {
                if (empty($input[$field])) throw new Exception("Campo $field obrigatório.");
            }

            // Definimos o user_type como 'OPERATOR' ou 'ADMIN' para diferenciar de motoristas/empresas
            $input['user_type'] = ($input['role'] === 'admin') ? 'ADMIN' : 'OPERATOR';
            
            // No painel administrativo, podemos definir permissões granulares via JSON
            // Ex: {"view_reports": true, "delete_users": false}
            $input['permissions'] = $this->getDefaultPermissionsByRole($input['role']);

            $result = $this->repo->createInternalUser($input, $loggedUser['id']);

            return Response::json($result, $result['success'] ? 201 : 500);
        } catch (Exception $e) {
            return Response::json(["success" => false, "message" => $e->getMessage()], 400);
        }
    }

    /**
     * Define o que cada cargo interno pode fazer por padrão
     */
    private function getDefaultPermissionsByRole($role) {
        $roles = [
            'admin'     => ['all' => true],
            'manager'   => ['edit_freight' => true, 'view_finance' => true, 'support_chat' => true],
            'analyst'   => ['view_freight' => true, 'verify_documents' => true],
            'assistant' => ['support_chat' => true, 'view_users' => true]
        ];
        return json_encode($roles[$role] ?? []);
    }

    /**
     * Lista todos os usuários. 
     * Corrigido para aceitar filtros e garantir que todos os perfis apareçam.
     */
    public function listUsers($data, $loggedUser) {
        try {
            $this->authorize($loggedUser, 'manager');

            $role = (!empty($data['role'])) ? $data['role'] : '%';
            $search = (!empty($data['search'])) ? $data['search'] : '';

            $users = $this->repo->listUsersByRole($role, $search); 
            
            return Response::json([
                "success" => true, 
                "count" => count($users),
                "data" => $users
            ]);
        } catch (Exception $e) {
            // Se a exceção tiver código (401, 403), usa ele. Senão, usa 400.
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 400;
            return Response::json([
                "success" => false, 
                "message" => $e->getMessage()
            ], $code);
        }
    }

    /**
     * Busca usuários para autocomplete (sem paginação, apenas resultados básicos).
     */
    public function searchUsers($data, $loggedUser) {
        try {
            // Verifica se está logado (qualquer role)
            if (!$loggedUser) {
                return Response::json(["success" => false, "message" => "Não autorizado"], 401);
            }

            $query = (!empty($data['q'])) ? trim($data['q']) : '';
            
            if (strlen($query) < 2) {
                return Response::json(["success" => true, "data" => []]);
            }

            $limit = (!empty($data['limit'])) ? (int)$data['limit'] : 10;
            
            $users = $this->repo->searchUsers($query, $limit);
            
            return Response::json([
                "success" => true,
                "data" => $users
            ]);
        } catch (Exception $e) {
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 400;
            return Response::json([
                "success" => false,
                "message" => $e->getMessage()
            ], $code);
        }
    }

    /**
     * Gerencia a edição de usuários com Log de Auditoria detalhado.
     */
    public function manageUsers($data, $loggedUser) {
        try {
            $this->authorize($loggedUser);
            $id = $data['id'] ?? null;

            if (!$id) throw new Exception("ID do usuário é obrigatório.");

            // 1. Antes de atualizar, buscamos os dados atuais para o log
            $oldData = $this->repo->getUserById($id); // Você precisa desse método simples no Repo

            if ($this->repo->updateUserDetails($data)) {
                // 2. Salvamos o log com o estado anterior e o novo
                $this->repo->saveLog(
                    $loggedUser['id'], 
                    $loggedUser['name'], 
                    'UPDATE_USER', 
                    "Editou dados do usuário #{$id}", 
                    $id, 
                    'USER',
                    $oldData, // Valores antigos
                    $data     // Valores novos
                );
                return Response::json(["success" => true]);
            }
            return Response::json(["success" => false, "error" => "Falha ao atualizar no banco."]);
        } catch (Exception $e) {
            return Response::json(["success" => false, "error" => $e->getMessage()]);
        }
    }

    /**
     * Aprovação Manual e Verificação de Badge
     */
    public function verifyUser($data, $loggedUser) {
        try {
            $this->authorize($loggedUser);
            $id = $data['id'] ?? null;
            $status = isset($data['status']) ? (int)$data['status'] : 1;
            
            if ($this->repo->setUserVerification($id, $status)) {
                $actionLabel = $status == 1 ? "Verificado/Aprovado" : "Removida Verificação";
                
                $this->repo->saveLog(
                    $loggedUser['id'], 
                    $loggedUser['name'], 
                    'VERIFY_USER', 
                    "$actionLabel usuário #$id", 
                    $id, 
                    'USER'
                );

                if ($status == 1) {
                    $this->notif->notify($id, "Perfil Verificado!", "Sua conta foi aprovada manualmente pelo administrador.");
                }

                return Response::json(["success" => true]);
            }
            return Response::json(["success" => false]);
        } catch (Exception $e) {
            return Response::json(["success" => false, "error" => $e->getMessage()]);
        }
    }

    /**
     * Exclusão de Usuário (Soft Delete por padrão)
     */
    public function deleteUser($data, $loggedUser) {
        try {
            // Apenas ADMIN (não Manager) pode excluir permanentemente
            $this->authorize($loggedUser);
            
            $id = $data['id'] ?? null;
            $permanent = isset($data['permanent']) && $data['permanent'] === true;

            // Busca nome para o log
            $targetUser = $this->repo->getUserById($id);
            $userName = $targetUser['name'] ?? 'Desconhecido';

            if ($permanent) {
                $this->authorize($loggedUser, 'ADMIN');
                $success = $this->repo->deleteUserPermanently($id);
                $logMsg = "EXCLUSÃO PERMANENTE do usuário #$id ($userName)";
            } else {
                $success = $this->repo->softDeleteUser($id);
                $logMsg = "Soft Delete (Desativação) do usuário #$id ($userName)";
            }

            if ($success) {
                $this->repo->saveLog(
                    $loggedUser['id'], 
                    $loggedUser['name'], 
                    'DELETE_USER', 
                    $logMsg, 
                    $id, 
                    'USER',
                    $targetUser, // Salva os dados deletados no log para recuperação se necessário
                    ['status' => 'deleted/inactive']
                );
            }

            return Response::json(["success" => $success]);
        } catch (Exception $e) {
            return Response::json(["success" => false, "error" => $e->getMessage()]);
        }
    }

    // --- GESTÃO DE FRETES ---
    public function listAllFreights($data, $loggedUser) {
        try {
            $freights = $this->adminRepo->getAllFreightsForAdmin(); 
            
            return Response::json([
                'success' => true,
                'data' => $freights
            ]);
        } catch (Exception $e) {
            return Response::json([
                'success' => false, 
                'data' => [],
                'error' => $e->getMessage()
            ]);
        }
    }

    public function updateFreightStatus($data, $loggedUser) {
        $this->authorize($loggedUser);
        $id = $data['id'] ?? null;
        $status = $data['status'] ?? 'OPEN'; // 'OPEN' para aprovar, 'CLOSED' para rejeitar
        $approveFeatured = $data['approve_featured'] ?? false;

        $freight = $this->repo->getFreightById($id);
        if (!$freight) return Response::json(["success" => false, "message" => "Frete não encontrado"]);

        if ($this->repo->updateFreightStatus($id, $status, $approveFeatured)) {
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'FREIGHT_STATUS', "Frete #$id -> $status", $id, 'FREIGHT');
            if ($status === 'OPEN') {
                $this->notif->notify($freight['user_id'], "Frete Online!", "Seu anúncio foi aprovado.");
                $this->triggerMatches($freight); 
            }
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false]);
    }

    private function triggerMatches($freight) {
        $drivers = $this->repo->findCompatibleDrivers($freight['vehicle_type'], $freight['body_type'], $freight['origin_state']);
        foreach ($drivers as $driver) {
            $this->notif->notify($driver['user_id'], "Carga compatível!", "Nova carga de {$freight['product']} disponível.");
        }
    }

    // --- LEADS, ADS E CONFIGURAÇÕES ---

    public function getPortalRequests($data, $loggedUser) {
        $this->authorize($loggedUser);
        return Response::json(["success" => true, "data" => $this->repo->getPortalRequests($data)]);
    }

    public function updateLeadInternal($data, $loggedUser) {
        $this->authorize($loggedUser);
        if ($this->repo->updateLeadInternal($data['id'], $data['admin_notes'] ?? '', $data['status'] ?? 'pending')) {
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'UPDATE_LEAD', "Nota no lead #{$data['id']}", $data['id'], 'LEAD');
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false]);
    }

    public function softDeleteLead($id) {
        // Em vez de apagar, apenas marca o tempo da exclusão
        $sql = "UPDATE portal_requests SET deleted_at = NOW() WHERE id = ?";
        return $this->db->prepare($sql)->execute([$id]);
    }

    public function manageAds($data, $loggedUser) {
        $this->authorize($loggedUser);
        
        $action = $data['action'] ?? '';
        $id = $data['id'] ?? null;
        
        // List all ads for admin
        if ($_SERVER['REQUEST_METHOD'] === 'GET' || $action === 'list') {
            $status = $data['status'] ?? null;
            $search = $data['search'] ?? null;
            $ads = $this->adRepo->listAll($status, $search);
            return Response::json(["success" => true, "data" => $ads]);
        }
        
        // Delete ad
        if ($action === 'delete') {
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'DELETE_AD', "Removeu anúncio #$id", $id, 'AD');
            return Response::json(["success" => $this->repo->softDeleteAd($id)]);
        }
        
        // Pause ad
        if ($action === 'pause' && $id) {
            $success = $this->adRepo->pauseAd($id);
            if ($success) {
                $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'PAUSE_AD', "Pausou anúncio #$id", $id, 'AD');
            }
            return Response::json(["success" => $success]);
        }
        
        // Activate ad
        if ($action === 'activate' && $id) {
            $success = $this->adRepo->activateAd($id);
            if ($success) {
                $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'ACTIVATE_AD', "Ativou anúncio #$id", $id, 'AD');
            }
            return Response::json(["success" => $success]);
        }
        
        // Renew ad (estender expiração)
        if ($action === 'renew' && $id) {
            $days = isset($data['days']) ? (int)$data['days'] : 30;
            $success = $this->adRepo->renewAd($id, $days);
            if ($success) {
                $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'RENEW_AD', "Renovou anúncio #$id por $days dias", $id, 'AD');
            }
            return Response::json(["success" => $success]);
        }
        
        // Toggle status (legacy)
        return Response::json(["success" => $this->repo->toggleAdStatus($id)]);
    }

    public function managePlans($data, $loggedUser) {
        $this->authorize($loggedUser, 'ADMIN');
        
        $action = $data['action'] ?? 'list';
        
        // Se for GET (listar), retorna todos os planos
        if ($_SERVER['REQUEST_METHOD'] === 'GET' || $action === 'list') {
            $plans = $this->repo->managePlans(['action' => 'list']);
            return Response::json(["success" => true, "plans" => $plans]);
        }
        
        // Para POST (salvar)
        return Response::json(["success" => true, "data" => $this->repo->managePlans($data)]);
    }

    /**
     * Retorna planos de publicidade formatados para landing page
     * Endpoint público - não requer autenticação
     */
    public function getAdvertisingPlans($data, $loggedUser) {
        try {
            $category = $data['category'] ?? 'advertising';
            
            $sql = "SELECT * FROM plans 
                    WHERE active = 1 
                    AND category = :category 
                    ORDER BY sort_order ASC, price ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':category' => $category]);
            $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Formatar dados para o frontend
            $formatted = [];
            foreach ($plans as $plan) {
                $formatted[] = [
                    'id' => $plan['id'],
                    'name' => $plan['name'],
                    'slug' => $plan['slug'],
                    'type' => $plan['type'],
                    'description' => $plan['description'],
                    'features' => $plan['features'] ? json_decode($plan['features'], true) : [],
                    'is_highlighted' => (bool)$plan['is_highlighted'],
                    'prices' => [
                        'monthly' => (float)$plan['price'],
                        'quarterly' => $plan['price_quarterly'] ? (float)$plan['price_quarterly'] : null,
                        'semiannual' => $plan['price_semiannual'] ? (float)$plan['price_semiannual'] : null,
                        'yearly' => $plan['price_yearly'] ? (float)$plan['price_yearly'] : null
                    ],
                    'discounts' => [
                        'quarterly' => (int)$plan['discount_quarterly'],
                        'semiannual' => (int)$plan['discount_semiannual'],
                        'yearly' => (int)$plan['discount_yearly']
                    ],
                    'duration_days' => (int)$plan['duration_days'],
                    'limit_ads_active' => (int)$plan['limit_ads_active'],
                    'has_verification_badge' => (bool)$plan['has_verification_badge'],
                    'priority_support' => (bool)$plan['priority_support']
                ];
            }
            
            return Response::json([
                "success" => true, 
                "data" => $formatted
            ]);
            
        } catch (Exception $e) {
            error_log("Erro em getAdvertisingPlans: " . $e->getMessage());
            return Response::json([
                "success" => false, 
                "message" => "Erro ao carregar planos"
            ], 500);
        }
    }

    public function getSettings($data, $loggedUser) {
        error_log("getSettings chamado com loggedUser: " . json_encode($loggedUser));
        
        // Se loggedUser não foi passado, usa o interno
        if (!$loggedUser && $this->loggedUser) {
            $loggedUser = $this->loggedUser;
        }
        
        try {
            $this->authorize($loggedUser, 'ADMIN');
        } catch (\Throwable $e) {
            error_log("Authorize falhou: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Não autorizado: " . $e->getMessage()], 403);
        }
        
        try {
            $stmt = $this->db->query("SELECT * FROM site_settings");
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $settingsMap = [];
            $byCategory = [];
            foreach ($settings as $s) {
                $key = $s['setting_key'];
                $cat = $s['category'] ?? 'general';
                $settingsMap[$key] = $s['setting_value'];
                $byCategory[$cat][$key] = $s['setting_value'];
            }
            
            $plans = [];
            try {
                $plansStmt = $this->db->query("SELECT id, name, price, duration_days, type, description FROM plans ORDER BY price ASC");
                $plans = $plansStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                error_log("Tabela plans não existe ou erro: " . $e->getMessage());
            }
            
            return Response::json([
                "success" => true, 
                "data" => $settingsMap,
                "byCategory" => $byCategory,
                "plans" => $plans
            ]);
        } catch (\Throwable $e) {
            error_log("ERRO getSettings: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao carregar configurações"]);
        }
    }

    public function updateSettings($data, $loggedUser) {
        $this->authorize($loggedUser, 'ADMIN');
        
        try {
            $key = $data['key'] ?? null;
            $value = $data['value'] ?? null;
            
            // Bulk update mode - recebe todas as configs de uma vez
            if (!$key && isset($data['site_name'])) {
                $allowedKeys = [
                    'site_name', 'site_email', 'site_phone', 'site_whatsapp', 'site_logo', 'site_favicon',
                    'module_freights', 'module_quotes', 'module_marketplace', 'module_groups', 'module_ads',
                    'auto_approve_users', 'freight_expiration_days', 'commission_percent', 'min_withdraw',
                    'mp_client_id', 'mp_client_secret', 'mp_access_token', 'referral_enabled', 'referral_commission',
                    'default_plan', 'freight_free_limit', 'maintenance_mode',
                    'vehicle_types', 'body_types', 'equipment_types', 'certification_types',
                    'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from_email', 'smtp_from_name',
                    // Moderation settings
                    'review_auto_approve_high_rating', 'review_auto_approve_threshold', 'review_auto_reject_bad_words',
                    'report_auto_dismiss_duplicate'
                ];
                
                foreach ($allowedKeys as $settingKey) {
                    if (isset($data[$settingKey])) {
                        $this->upsertSetting($settingKey, $data[$settingKey]);
                    }
                }
                
                error_log("SETTINGS_BULK_UPDATE: by_user={$loggedUser['id']}");
                return Response::json(["success" => true, "message" => "Configurações salvas com sucesso"]);
            }
            
            // Single key update mode
            if (!$key) {
                return Response::json(["success" => false, "message" => "Chave não informada"], 400);
            }
            
            $this->upsertSetting($key, $value);
            error_log("SETTINGS_UPDATE: key={$key}, by_user={$loggedUser['id']}");
            
            return Response::json([
                "success" => true, 
                "message" => "Configuração atualizada com sucesso"
            ]);
        } catch (\Throwable $e) {
            error_log("ERRO updateSettings: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao atualizar configuração"]);
        }
    }
    
    private function upsertSetting(string $key, $value): void {
        $stmt = $this->db->prepare("SELECT setting_key FROM site_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $exists = $stmt->fetch();
        
        if ($exists) {
            $update = $this->db->prepare("
                UPDATE site_settings 
                SET setting_value = ?, updated_at = NOW() 
                WHERE setting_key = ?
            ");
            $update->execute([(string)$value, $key]);
        }
    }
    
    /**
     * Encontra motoristas compatíveis com um frete usando Haversine
     * Rota: GET /api/admin/freight-matching
     */
    public function findMatchingDrivers($data, $loggedUser) {
        $this->authorize($loggedUser, 'ADMIN');
        
        try {
            $freightId = (int)($data['freight_id'] ?? 0);
            $maxDistance = (int)($data['max_distance_km'] ?? 200);
            
            if (!$freightId) {
                return Response::json(["success" => false, "message" => "ID do frete não informado"], 400);
            }
            
            // Busca dados do frete
            $stmt = $this->db->prepare("
                SELECT id, origin_city, origin_state, origin_lat, origin_lng,
                       dest_city, dest_state, vehicle_type, body_type, product,
                       equipment_needed, certifications_needed
                FROM freights 
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$freightId]);
            $freight = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$freight) {
                return Response::json(["success" => false, "message" => "Frete não encontrado"], 404);
            }
            
            // Se não tem coordenadas, retorna erro
            if (!$freight['origin_lat'] || !$freight['origin_lng']) {
                return Response::json([
                    "success" => false,
                    "message" => "Frete não possui coordenadas de origem. Use a página de edição para geolocalizar."
                ], 400);
            }
            
            // Busca motoristas compatíveis usando a stored procedure ou query direta
            // Query direta para compatibilidade com MySQL 8 sem stored procedure
            $query = "
                SELECT 
                    u.id AS driver_id,
                    u.name AS driver_name,
                    u.slug AS driver_slug,
                    u.whatsapp AS driver_whatsapp,
                    p.vehicle_type,
                    p.body_type,
                    p.home_city,
                    p.home_state,
                    p.service_radius_km,
                    p.available_equipment,
                    p.certifications,
                    p.avatar_url,
                    p.verification_status,
                    p.profile_completeness,
                    ROUND(
                        6371 * ACOS(
                            COS(RADIANS(:origin_lat)) * COS(RADIANS(p.home_lat)) *
                            COS(RADIANS(p.home_lng) - RADIANS(:origin_lng)) +
                            SIN(RADIANS(:origin_lat)) * SIN(RADIANS(p.home_lat))
                        )
                    , 2) AS distance_km,
                    CASE WHEN p.availability_status = 'available' THEN 30 ELSE 0 END +
                    CASE WHEN p.vehicle_type = :vehicle_type THEN 30 ELSE 0 END +
                    CASE WHEN p.body_type = :body_type THEN 20 ELSE 0 END +
                    CASE WHEN p.verification_status = 'verified' THEN 20 ELSE 0 END AS match_score
                FROM users u
                INNER JOIN user_profiles p ON u.id = p.user_id
                WHERE u.role = 'driver'
                    AND u.status = 'active'
                    AND p.availability_status = 'available'
                    AND p.home_lat IS NOT NULL
                    AND p.home_lng IS NOT NULL
                    AND ROUND(
                        6371 * ACOS(
                            COS(RADIANS(:origin_lat2)) * COS(RADIANS(p.home_lat)) *
                            COS(RADIANS(p.home_lng) - RADIANS(:origin_lng2)) +
                            SIN(RADIANS(:origin_lat2)) * SIN(RADIANS(p.home_lat))
                        )
                    , 2) <= :max_distance
                    AND ROUND(
                        6371 * ACOS(
                            COS(RADIANS(:origin_lat3)) * COS(RADIANS(p.home_lat)) *
                            COS(RADIANS(p.home_lng) - RADIANS(:origin_lng3)) +
                            SIN(RADIANS(:origin_lat3)) * SIN(RADIANS(p.home_lat))
                        )
                    , 2) <= p.service_radius_km
                ORDER BY match_score DESC, distance_km ASC
                LIMIT 50
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':origin_lat' => $freight['origin_lat'],
                ':origin_lng' => $freight['origin_lng'],
                ':vehicle_type' => $freight['vehicle_type'] ?? '',
                ':body_type' => $freight['body_type'] ?? '',
                ':origin_lat2' => $freight['origin_lat'],
                ':origin_lng2' => $freight['origin_lng'],
                ':origin_lat3' => $freight['origin_lat'],
                ':origin_lng3' => $freight['origin_lng'],
                ':max_distance' => $maxDistance,
            ]);
            
            $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse JSON fields
            foreach ($drivers as &$driver) {
                if (isset($driver['available_equipment'])) {
                    $driver['available_equipment'] = json_decode($driver['available_equipment'], true) ?? [];
                }
                if (isset($driver['certifications'])) {
                    $driver['certifications'] = json_decode($driver['certifications'], true) ?? [];
                }
            }
            
            return Response::json([
                "success" => true,
                "freight" => [
                    "id" => $freight['id'],
                    "origin" => $freight['origin_city'] . '/' . $freight['origin_state'],
                    "destination" => $freight['dest_city'] . '/' . $freight['dest_state'],
                    "product" => $freight['product'],
                    "vehicle_type" => $freight['vehicle_type'],
                    "body_type" => $freight['body_type'],
                    "equipment_needed" => $freight['equipment_needed'] ? json_decode($freight['equipment_needed'], true) : [],
                    "certifications_needed" => $freight['certifications_needed'] ? json_decode($freight['certifications_needed'], true) : [],
                ],
                "drivers" => $drivers,
                "total" => count($drivers)
            ]);
            
        } catch (\Throwable $e) {
            error_log("ERRO findMatchingDrivers: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao buscar motoristas"], 500);
        }
    }
    
    /**
     * Atualiza coordenadas e dados de localização do perfil do motorista
     * Rota: POST /api/admin/driver-location
     */
    public function updateDriverLocation($data, $loggedUser) {
        $this->authorize($loggedUser, 'ADMIN');
        
        try {
            $userId = (int)($data['user_id'] ?? 0);
            $homeLat = (float)($data['home_lat'] ?? 0);
            $homeLng = (float)($data['home_lng'] ?? 0);
            $homeCity = trim($data['home_city'] ?? '');
            $homeState = trim($data['home_state'] ?? '');
            $serviceRadius = (int)($data['service_radius_km'] ?? 100);
            
            if (!$userId) {
                return Response::json(["success" => false, "message" => "ID do usuário não informado"], 400);
            }
            
            if (!$homeLat || !$homeLng) {
                return Response::json(["success" => false, "message" => "Coordenadas inválidas"], 400);
            }
            
            $stmt = $this->db->prepare("
                UPDATE user_profiles 
                SET home_lat = ?, home_lng = ?, home_city = ?, home_state = ?, service_radius_km = ?
                WHERE user_id = ?
            ");
            $stmt->execute([$homeLat, $homeLng, $homeCity, $homeState, $serviceRadius, $userId]);
            
            // Recalcula completeness
            $this->recalculateProfileCompleteness($userId);
            
            return Response::json(["success" => true, "message" => "Localização atualizada"]);
            
        } catch (\Throwable $e) {
            error_log("ERRO updateDriverLocation: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao atualizar localização"], 500);
        }
    }
    
    private function recalculateProfileCompleteness(int $userId): void {
        try {
            $stmt = $this->db->prepare("
                SELECT u.name, p.bio, p.avatar_url, p.vehicle_type, p.body_type, 
                       p.home_lat, p.home_lng, p.rntrc_number, p.verification_status
                FROM users u
                INNER JOIN user_profiles p ON u.id = p.user_id
                WHERE u.id = ?
            ");
            $stmt->execute([$userId]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$profile) return;
            
            $score = 0;
            if (!empty($profile['name'])) $score += 10;
            if (!empty($profile['bio'])) $score += 10;
            if (!empty($profile['avatar_url'])) $score += 10;
            if (!empty($profile['vehicle_type'])) $score += 15;
            if (!empty($profile['body_type'])) $score += 15;
            if (!empty($profile['home_lat']) && !empty($profile['home_lng'])) $score += 15;
            if (!empty($profile['rntrc_number'])) $score += 10;
            if ($profile['verification_status'] === 'verified') $score += 15;
            
            $update = $this->db->prepare("UPDATE user_profiles SET profile_completeness = ? WHERE user_id = ?");
            $update->execute([$score, $userId]);
            
        } catch (\Throwable $e) {
            error_log("ERRO recalculateProfileCompleteness: " . $e->getMessage());
        }
    }

    public function getActivityLogs($data, $loggedUser) {
        $this->authorize($loggedUser, 'ADMIN');
        
        try {
            $limit = $data['limit'] ?? 50;
            $page = $data['page'] ?? 1;
            $offset = ($page - 1) * $limit;
            
            // Usa a tabela existente logs_auditoria
            $stmt = $this->db->prepare("
                SELECT la.*, u.name as user_name
                FROM logs_auditoria la
                LEFT JOIN users u ON la.user_id = u.id
                ORDER BY la.created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Conta total
            $countStmt = $this->db->query("SELECT COUNT(*) as total FROM logs_auditoria");
            $total = $countStmt->fetch()['total'];
            
            return Response::json([
                "success" => true, 
                "data" => $logs,
                "total" => $total,
                "page" => $page,
                "limit" => $limit
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao carregar atividades"]);
        }
    }

    public function listAllUsers($data, $loggedUser) {
        $this->authorize($loggedUser, 'ADMIN');
        
        try {
            $role = $data['role'] ?? '%';
            $status = $data['status'] ?? '%';
            $search = $data['search'] ?? '';
            
            $sql = "SELECT 
                        u.id, u.name, u.email, u.role, u.phone, u.whatsapp, u.created_at, 
                        u.user_type, u.access_level, u.parent_id,
                        u.verification_status, u.is_active, u.completion_score,
                        -- Company info from accounts table
                        a.corporate_name as company_name,
                        a.document_number as company_document,
                        a.trade_name as company_trade_name,
                        -- Parent user name (for team members)
                        p.name as parent_name,
                        -- Plan info
                        pl.name as plan_name
                    FROM users u
                    LEFT JOIN accounts a ON u.account_id = a.id
                    LEFT JOIN users p ON u.parent_id = p.id
                    LEFT JOIN plans pl ON u.plan_id = pl.id
                    WHERE u.role LIKE :role 
                    AND u.deleted_at IS NULL
                    ORDER BY u.created_at DESC
                    LIMIT 100";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':role' => $role]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return Response::json(["success" => true, "data" => $users]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao listar usuários"]);
        }
    }

    public function managePricing($data, $loggedUser) {
        $this->authorize($loggedUser, 'ADMIN');
        
        $action = $data['action'] ?? 'list';
        
        try {
            if ($action === 'list') {
                $stmt = $this->db->query("SELECT * FROM pricing_rules ORDER BY module_key, feature_key");
                $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return Response::json(["success" => true, "data" => $rules]);
            }
            
            if ($action === 'save') {
                $id = $data['id'] ?? 0;
                $fields = [
                    'module_key' => $data['module_key'],
                    'feature_key' => $data['feature_key'],
                    'feature_name' => $data['feature_name'],
                    'pricing_type' => $data['pricing_type'],
                    'free_limit' => (int)($data['free_limit'] ?? 0),
                    'price_per_use' => (float)($data['price_per_use'] ?? 0),
                    'price_monthly' => (float)($data['price_monthly'] ?? 0),
                    'price_daily' => (float)($data['price_daily'] ?? 0),
                    'duration_days' => (int)($data['duration_days'] ?? 30),
                    'is_active' => isset($data['is_active']) ? 1 : 0
                ];
                
                if ($id > 0) {
                    $sql = "UPDATE pricing_rules SET 
                        module_key = :module_key, feature_key = :feature_key, feature_name = :feature_name,
                        pricing_type = :pricing_type, free_limit = :free_limit, 
                        price_per_use = :price_per_use, price_monthly = :price_monthly, 
                        price_daily = :price_daily, duration_days = :duration_days, is_active = :is_active
                        WHERE id = :id";
                    $fields['id'] = $id;
                } else {
                    $sql = "INSERT INTO pricing_rules 
                        (module_key, feature_key, feature_name, pricing_type, free_limit, price_per_use, price_monthly, price_daily, duration_days, is_active)
                        VALUES (:module_key, :feature_key, :feature_name, :pricing_type, :free_limit, :price_per_use, :price_monthly, :price_daily, :duration_days, :is_active)";
                }
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute($fields);
                return Response::json(["success" => true, "message" => "Regra salva com sucesso!"]);
            }
            
            if ($action === 'delete') {
                $id = $data['id'] ?? 0;
                $stmt = $this->db->prepare("DELETE FROM pricing_rules WHERE id = ?");
                $stmt->execute([$id]);
                return Response::json(["success" => true, "message" => "Regra excluída!"]);
            }
            
            return Response::json(["success" => false, "message" => "Ação inválida"]);
            
        } catch (\Throwable $e) {
            error_log("ERRO managePricing: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro interno"]);
        }
    }

    public function manualAddCredits($data, $loggedUser) {
        $this->authorize($loggedUser);
        $userId = $data['user_id'] ?? null;
        $amount = (int)($data['amount'] ?? 0);
        $reason = $data['reason'] ?? 'Adição manual via painel';

        if (!$userId || $amount <= 0) return Response::json(["success" => false, "message" => "Dados inválidos"]);

        try {
            $this->db->beginTransaction();

            // Valida se usuário existe
            $stmtCheck = $this->db->prepare("SELECT id FROM users WHERE id = ?");
            $stmtCheck->execute([$userId]);
            if (!$stmtCheck->fetch()) {
                throw new Exception("Usuário não encontrado");
            }
            
            $this->db->prepare("UPDATE users SET ad_credits = ad_credits + :amount WHERE id = :id")->execute([':amount' => $amount, ':id' => $userId]);
            
            $this->db->prepare("INSERT INTO credit_transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'recharge', ?, NOW())")
                     ->execute([$userId, $amount, $reason . " (Por: {$loggedUser['name']})"]);

            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'MANUAL_CREDIT', "Adicionou $amount créditos ao usuário #$userId", $userId, 'USER');
            $this->db->commit();
            
            $this->notif->notify($userId, "Créditos Adicionados!", "Você recebeu $amount créditos.");
            return Response::json(["success" => true]);
        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return Response::json(["success" => false]);
        }
    }

    public function manageFreights($data, $loggedUser) {
        $this->authorize($loggedUser);
        $id = $data['id'] ?? null;
        $action = $data['action'] ?? '';
        $success = false;

        if (!$id) return Response::json(["success" => false, "message" => "ID ausente"]);

        switch ($action) {
            case 'toggle-featured':
                $featured = (int)($data['featured'] ?? 0);
                $success = $this->repo->toggleFeatured($id, $featured);
                $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'UPDATE', "Alterou destaque do frete #$id", $id, 'FREIGHT');
                break;

            case 'delete':
                // Na sua tabela existe is_deleted, use preferencialmente ela
                $success = $this->repo->updateFreightStatus($id, 'DELETED', false); 
                // Se tiver o método, use: $this->repo->softDeleteFreight($id);
                $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'DELETE', "Removeu frete #$id", $id, 'FREIGHT');
                break;

            case 'approve':
                $success = $this->repo->updateFreightStatus($id, 'OPEN', true);
                break;
                
            default:
                return Response::json(["success" => false, "message" => "Ação inválida"]);
        }

        return Response::json(["success" => $success]);
    }

    /**
     * Lista todos os chamados para o Painel Administrativo
     */
    public function listTickets($data, $loggedUser) {
        $this->authorize($loggedUser); // Manager e Admin podem ver
        $status = $data['status'] ?? '%';
        $tickets = $this->repo->getTickets($status);
        return Response::json(["success" => true, "data" => $tickets]);
    }

    /**
     * Resposta do suporte para o usuário
     */
    public function replyTicket($data, $loggedUser) {
        $this->authorize($loggedUser);
        $ticketId = $data['ticket_id'];
        $message = $data['message'];

        if ($this->repo->addTicketMessage($ticketId, $loggedUser['id'], $message, true)) {
            // Notifica o usuário que o suporte respondeu
            $ticket = $this->repo->getTicketById($ticketId);
            $this->notif->notify($ticket['user_id'], "Suporte respondeu!", "Verifique seu chamado: " . $ticket['subject']);
            
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false]);
    }

    public function addUserNote($data, $loggedUser) {
        $this->authorize($loggedUser);
        $targetUserId = $data['user_id'];
        $note = $data['note'];
        
        // Salva na tabela de notas internas
        $success = $this->repo->saveInternalNote($targetUserId, $loggedUser['id'], $note);
        return Response::json(["success" => $success]);
    }

    // --- GESTÃO DE DOCUMENTOS (KYC) ---

    /**
     * Lista documentos que aguardam revisão
     */
    public function listPendingDocuments($data, $loggedUser) {
        $this->authorize($loggedUser);
        $docs = $this->repo->getPendingDocuments();
        return Response::json(["success" => true, "data" => $docs]);
    }

    /**
     * Aprova ou Rejeita um documento específico
     */
    public function reviewDocument($data, $loggedUser) {
        $this->authorize($loggedUser);
        
        $docId = $data['doc_id'] ?? null;
        $status = $data['status'] ?? null; // 'APPROVED' ou 'REJECTED'
        $reason = $data['reason'] ?? '';   // Motivo em caso de rejeição

        if (!$docId || !in_array($status, ['APPROVED', 'REJECTED'])) {
            return Response::json(["success" => false, "message" => "Dados insuficientes"]);
        }

        $doc = $this->repo->getDocumentById($docId);
        if (!$doc) return Response::json(["success" => false, "message" => "Documento não encontrado"]);

        if ($this->repo->updateDocumentStatus($docId, $status, $reason)) {
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'DOC_REVIEW', "Doc #$docId avaliado como $status", $doc['entity_id'], 'USER');
            
            // Notifica o usuário sobre o resultado
            $msg = ($status === 'APPROVED') 
                ? "Seu documento ({$doc['document_type']}) foi aprovado!" 
                : "Seu documento ({$doc['document_type']}) foi recusado. Motivo: $reason";
            
            $this->notif->notify($doc['entity_id'], "Verificação de Documentos", $msg);
            
            return Response::json(["success" => true]);
        }
        
        return Response::json(["success" => false]);
    }

    // --- NOVO: GESTÃO DE GRUPOS (WHATSAPP) ---
    
    public function listAllGroups($data, $loggedUser) {
        $this->authorize($loggedUser);
        // Usa listAll para admin ver todos os grupos (sem filtro de display_location)
        $groups = $this->groupRepo->listAll(); 
        return Response::json(["success" => true, "data" => $groups]);
    }

    public function manageGroups($data, $loggedUser) {
        try {
            $this->authorize($loggedUser);
            
            $result = $this->groupRepo->save($data);
            if ($result) {
                $action = isset($data['id']) ? 'UPDATE_GROUP' : 'CREATE_GROUP';
                $this->adminRepo->saveLog(
                    $loggedUser['id'], 
                    $loggedUser['name'], 
                    $action, 
                    "Gerenciou grupo: " . ($data['region_name'] ?? 'ID '.$result), 
                    $result, 
                    'WA_GROUP'
                );
                return Response::json(["success" => true, "id" => $result]);
            }
            return Response::json(["success" => false, "message" => "Erro ao salvar grupo"]);
        } catch (Exception $e) {
            $code = $e->getCode() ?: 400;
            return Response::json(["success" => false, "message" => $e->getMessage()], $code);
        }
    }

    public function getUserDetails($data, $loggedUser) {
        $this->authorize($loggedUser);
        
        $id = $data['id'] ?? null;
        if (!$id) {
            return Response::json(["success" => false, "message" => "ID não fornecido"], 400);
        }

        $user = $this->repo->getUserFullDetails($id);
        
        if (!$user) {
            return Response::json(["success" => false, "message" => "Usuário não encontrado"], 404);
        }

        return Response::json([
            "success" => true,
            "data" => $user
        ]);
    }

    public function updateUser($data, $loggedUser) {
        $this->authorize($loggedUser);

        if (!isset($data['id'])) {
            return Response::json(["success" => false, "message" => "ID do usuário é obrigatório"], 400);
        }

        $result = $this->repo->updateUserDetails($data);

        if ($result['success']) {
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'USER_UPDATE', "Editou dados do usuário ID: ".$data['id'], $data['id'], 'USER');
            return Response::json(["success" => true, "message" => "Usuário atualizado com sucesso!"]);
        }

        return Response::json(["success" => false, "message" => "Erro ao atualizar: " . $result['error']], 500);
    }

    public function listCompanyMembers($loggedUser) {
        // 1. SEGURANÇA: Verifica se quem está logado é realmente um admin
        $this->authorize($loggedUser);

        // 2. CAPTURA: Como o Axios enviou um GET, pegamos da URL (query string)
        // Se você usa um roteador que injeta params, mantenha o $data, 
        // caso contrário, use $_GET:
        $companyId = $_GET['company_id'] ?? null;

        // 3. VALIDAÇÃO: Verifica se o ID é válido
        if (!$companyId || !is_numeric($companyId)) {
            return Response::json([
                "success" => false, 
                "message" => "ID da empresa inválido ou não informado."
            ], 400);
        }

        // 4. EXECUÇÃO: Busca no repository
        $members = $this->repo->getCompanyMembers($companyId);

        // 5. RESPOSTA: Retorna os dados
        return Response::json([
            "success" => true, 
            "count" => count($members),
            "data" => $members
        ]);
    }

    /**
     * Lista verificações de drivers pendentes
     */
    public function listDriverVerifications($data, $loggedUser) {
        $this->authorize($loggedUser);

        $status = $data['status'] ?? 'awaiting_review';
        $limit = $data['limit'] ?? 50;

        try {
            // Busca apenas a transação mais recente por usuário
            $stmt = $this->db->prepare("
                SELECT 
                    t.id as transaction_id,
                    t.user_id,
                    t.status,
                    t.amount,
                    t.created_at as requested_at,
                    t.gateway_id,
                    u.name as user_name,
                    u.email as user_email,
                    u.whatsapp as user_whatsapp,
                    u.role as user_role
                FROM transactions t
                JOIN users u ON t.user_id = u.id
                WHERE t.module_key = 'driver' 
                    AND t.feature_key = 'document_verification'
                    AND t.status = :status
                    AND t.id = (
                        SELECT MAX(t2.id) 
                        FROM transactions t2 
                        WHERE t2.user_id = t.user_id 
                        AND t2.module_key = 'driver' 
                        AND t2.feature_key = 'document_verification'
                        AND t2.status = :status2
                    )
                ORDER BY t.created_at DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':status', $status, PDO::PARAM_STR);
            $stmt->bindValue(':status2', $status, PDO::PARAM_STR);
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            $verifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Busca documentos do usuário (apenas o mais recente de cada tipo)
            foreach ($verifications as &$v) {
                $stmt = $this->db->prepare("
                    SELECT document_type, file_path, status, rejection_reason, created_at 
                    FROM user_documents 
                    WHERE entity_id = ? AND entity_type = 'user' AND status != 'replaced'
                    ORDER BY id DESC
                ");
                $stmt->execute([$v['user_id']]);
                $allDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Filtra para pegar apenas o mais recente de cada tipo
                $seenTypes = [];
                $v['documents'] = [];
                foreach ($allDocs as $doc) {
                    if (!in_array($doc['document_type'], $seenTypes)) {
                        $v['documents'][] = $doc;
                        $seenTypes[] = $doc['document_type'];
                    }
                }
            }

            return Response::json([
                "success" => true,
                "count" => count($verifications),
                "data" => $verifications
            ]);
        } catch (\Throwable $e) {
            error_log("ERRO listDriverVerifications: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao buscar verificações"], 500);
        }
    }

    /**
     * Aprova verificação de driver
     */
    public function approveDriverVerification($data, $loggedUser) {
        $this->authorize($loggedUser);

        $transactionId = $data['transaction_id'] ?? null;
        $notes = $data['notes'] ?? null;

        if (!$transactionId) {
            return Response::json(["success" => false, "message" => "ID da transação não informado"], 400);
        }

        try {
            // Busca transação
            $stmt = $this->db->prepare("
                SELECT * FROM transactions WHERE id = ? AND module_key = 'driver' AND feature_key = 'document_verification'
            ");
            $stmt->execute([$transactionId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transaction) {
                return Response::json(["success" => false, "message" => "Transação não encontrada"], 404);
            }

            if ($transaction['status'] === 'approved') {
                return Response::json(["success" => false, "message" => "Verificação já foi aprovada"], 400);
            }

            // Atualiza transação para approved
            $stmt = $this->db->prepare("
                UPDATE transactions 
                SET status = 'approved', reviewed_at = NOW(), reviewed_by = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$loggedUser['id'], $transactionId]);

            // Calcula data de expiração (30 dias)
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

            // Ativa verificação no usuário
            $stmt = $this->db->prepare("
                UPDATE users 
                SET is_verified = 1, 
                    verified_at = NOW(), 
                    verified_until = ?,
                    verified_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$expiresAt, $loggedUser['id'], $transaction['user_id']]);

            // Atualiza os documentos do usuário para approved
            $stmt = $this->db->prepare("
                UPDATE user_documents 
                SET status = 'approved', 
                    verified_at = NOW(),
                    verified_by = ?
                WHERE entity_id = ? AND entity_type = 'user' AND status = 'pending'
            ");
            $stmt->execute([$loggedUser['id'], $transaction['user_id']]);

            // Notifica o driver
            if ($this->notif) {
                $this->notif->createNotification(
                    $transaction['user_id'],
                    'Verificação Aprovada',
                    'Sua verificação de documentos foi aprovada! Seu perfil agora exibe o badge de usuário verificado.',
                    'verification',
                    $transactionId
                );
            }

            error_log("DRIVER VERIFICATION: Aprovada para usuário {$transaction['user_id']} por {$loggedUser['id']}");

            return Response::json([
                "success" => true,
                "message" => "Verificação aprovada com sucesso!",
                "expires_at" => $expiresAt
            ]);
        } catch (\Throwable $e) {
            error_log("ERRO approveDriverVerification: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao aprovar verificação"], 500);
        }
    }

    /**
     * Rejeita verificação de driver
     */
    public function rejectDriverVerification($data, $loggedUser) {
        $this->authorize($loggedUser);

        $transactionId = $data['transaction_id'] ?? null;
        $reason = $data['reason'] ?? '';
        $reasonTemplate = $data['reason_template'] ?? null;

        if (!$transactionId) {
            return Response::json(["success" => false, "message" => "ID da transação não informado"], 400);
        }

        if (empty($reason) && empty($reasonTemplate)) {
            return Response::json(["success" => false, "message" => "Motivo da rejeição é obrigatório"], 400);
        }

        // Combina template + reason livre
        $fullReason = $reasonTemplate;
        if (!empty($reasonTemplate) && !empty($reason)) {
            $fullReason = $reasonTemplate . ': ' . $reason;
        } elseif (!empty($reason)) {
            $fullReason = $reason;
        }

        try {
            // Busca transação
            $stmt = $this->db->prepare("
                SELECT * FROM transactions WHERE id = ? AND module_key = 'driver' AND feature_key = 'document_verification'
            ");
            $stmt->execute([$transactionId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transaction) {
                return Response::json(["success" => false, "message" => "Transação não encontrada"], 404);
            }

            if ($transaction['status'] !== 'awaiting_review') {
                return Response::json(["success" => false, "message" => "Esta transação não pode ser rejeitada (status atual: {$transaction['status']})"], 400);
            }

            // Estorna o valor para a carteira do usuário
            $amount = (float)$transaction['amount'];
            if ($amount > 0) {
                $this->creditService->refund($transaction['user_id'], $amount, "Verificação rejeitada: {$fullReason}");
            }

            // Atualiza transação para rejected com motivo
            $stmt = $this->db->prepare("
                UPDATE transactions 
                SET status = 'rejected', 
                    reviewed_at = NOW(), 
                    reviewed_by = ?,
                    rejection_reason = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$loggedUser['id'], $fullReason, $transactionId]);

            // Atualiza os documentos do usuário para rejected com o motivo
            $stmt = $this->db->prepare("
                UPDATE user_documents 
                SET status = 'rejected', 
                    rejection_reason = ?
                WHERE entity_id = ? AND entity_type = 'user' AND status = 'pending'
            ");
            $stmt->execute([$fullReason, $transaction['user_id']]);

            // Notifica o driver
            if ($this->notif) {
                $this->notif->createNotification(
                    $transaction['user_id'],
                    'Verificação Recusada',
                    "Sua verificação de documentos foi recusada. Motivo: {$fullReason}. O valor de R$ " . number_format($amount, 2, ',', '.') . " foi devolvido para sua carteira.",
                    'verification',
                    $transactionId
                );
            }

            error_log("DRIVER VERIFICATION: Rejeitada para usuário {$transaction['user_id']} por {$loggedUser['id']}. Motivo: {$fullReason}. Valor estornado: {$amount}");

            return Response::json([
                "success" => true,
                "message" => "Verificação recusada. Valor estornado: R$ " . number_format($amount, 2, ',', '.'),
                "amount_refunded" => $amount
            ]);
        } catch (\Throwable $e) {
            error_log("ERRO rejectDriverVerification: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao recusar verificação: " . $e->getMessage()], 500);
        }
    }

    public function getAllVerifications($data, $loggedUser) {
        $this->authorize(['ADMIN', 'MANAGER', 'SUPPORT']);

        try {
            $type = $data['type'] ?? 'all';
            $status = $data['status'] ?? 'pending';
            $page = intval($data['page'] ?? 1);
            $limit = intval($data['limit'] ?? 20);
            $offset = ($page - 1) * $limit;

            $results = [];

            if ($type === 'all' || $type === 'driver') {
                // Para drivers, buscar tanto por transações quanto por documentos
                // Se não tiver transação ainda mas tiver documentos, também aparece
                $driverSql = "
                    SELECT DISTINCT
                        u.id as user_id,
                        u.name as user_name,
                        u.email as user_email,
                        u.role as user_type,
                        u.is_verified,
                        u.whatsapp as user_whatsapp,
                        u.document_verified_at,
                        COALESCE(
                            t.status,
                            CASE 
                                WHEN EXISTS (SELECT 1 FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER' AND status = 'rejected') THEN 'rejected'
                                WHEN EXISTS (SELECT 1 FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER' AND status = 'pending') THEN 'pending'
                                WHEN EXISTS (SELECT 1 FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER') THEN 'pending'
                                ELSE NULL
                            END,
                            'pending'
                        ) as transaction_status,
                        CASE 
                            WHEN u.is_verified = 1 THEN 'approved'
                            WHEN COALESCE(t.status, 'pending') = 'rejected' OR EXISTS (SELECT 1 FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER' AND status = 'rejected') THEN 'rejected'
                            WHEN u.is_verified = 0 AND EXISTS (SELECT 1 FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER') THEN 'pending'
                            WHEN t.status IN ('pending', 'awaiting_review') THEN 'pending'
                            ELSE 'pending'
                        END as status,
                        u.document_verified_at as verified_at,
                        COALESCE(t.created_at, (SELECT MIN(created_at) FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER')) as requested_at,
                        t.amount,
                        COALESCE(t.id, (SELECT MAX(id) FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER')) as verification_id,
                        (SELECT rejection_reason FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER' AND status = 'rejected' ORDER BY id DESC LIMIT 1) as rejection_reason
                    FROM users u
                    LEFT JOIN transactions t ON t.user_id = u.id 
                        AND t.module_key = 'driver' 
                        AND t.feature_key = 'document_verification'
                    WHERE u.role = 'driver'
                    AND u.deleted_at IS NULL
                    AND (
                        EXISTS (SELECT 1 FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER')
                        OR EXISTS (SELECT 1 FROM transactions WHERE user_id = u.id AND module_key = 'driver' AND feature_key = 'document_verification')
                    )
                ";

                if ($status === 'pending') {
                    $driverSql .= " AND u.is_verified = 0 AND (t.status IN ('pending', 'awaiting_review') OR EXISTS (SELECT 1 FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER' AND status = 'pending'))";
                } elseif ($status === 'approved') {
                    $driverSql .= " AND (t.status = 'approved' OR u.is_verified = 1)";
                } elseif ($status === 'rejected') {
                    $driverSql .= " AND (t.status = 'rejected' OR EXISTS (SELECT 1 FROM user_documents WHERE entity_id = u.id AND entity_type = 'USER' AND status = 'rejected'))";
                }

                $driverSql .= " ORDER BY requested_at DESC";

                $stmt = $this->db->prepare($driverSql);
                $stmt->execute();
                $driverVerifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($driverVerifications as $v) {
                    $v['type'] = 'driver';
                    $v['description'] = 'Documentos do Motorista';
                    $v['documents'] = $this->getDriverDocuments($v['user_id']);
                    $results[] = $v;
                }
            }

            if ($type === 'all' || $type === 'company') {
                $sql = "
                    SELECT 
                        'company' as type,
                        u.id as user_id,
                        u.name as user_name,
                        u.email as user_email,
                        u.role as user_type,
                        v.id as verification_id,
                        v.cnpj,
                        v.razao_social,
                        v.nome_fantasia,
                        v.situacao,
                        v.status,
                        v.created_at,
                        v.verified_at,
                        v.reviewed_at,
                        v.rejection_reason,
                        ru.name as reviewed_by_name,
                        tr.amount as transaction_amount,
                        CONCAT('CNPJ: ', v.cnpj) as description
                    FROM verified_cnpj v
                    JOIN users u ON v.user_id = u.id
                    LEFT JOIN transactions tr ON tr.user_id = v.user_id 
                        AND tr.module_key = 'company_pro' 
                        AND tr.feature_key = 'identity_verification'
                        AND tr.status = 'approved'
                    LEFT JOIN users ru ON v.reviewed_by = ru.id
                    WHERE 1=1
                ";

                $params = [];

                if ($status !== 'all') {
                    $sql .= " AND v.status = ?";
                    $params[] = $status;
                }

                $sql .= " ORDER BY v.created_at DESC";

                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                $companyVerifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $results = array_merge($results, $companyVerifications);
            }

            usort($results, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });

            $total = count($results);
            $paginatedResults = array_slice($results, $offset, $limit);

            return Response::json([
                "success" => true,
                "data" => [
                    "verifications" => $paginatedResults,
                    "total" => $total,
                    "page" => $page,
                    "limit" => $limit,
                    "total_pages" => ceil($total / $limit)
                ]
            ]);
        } catch (\Throwable $e) {
            error_log("ERRO getAllVerifications: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao buscar verificações: " . $e->getMessage()], 500);
        }
    }

    public function approveVerification($data, $loggedUser) {
        $this->authorize(['ADMIN', 'MANAGER']);

        try {
            $id = intval($data['id'] ?? 0);
            $type = $data['type'] ?? '';

            if (!$id || !in_array($type, ['driver', 'company'])) {
                return Response::json(["success" => false, "message" => "ID e tipo são obrigatórios"], 400);
            }

            if ($type === 'driver') {
                return $this->approveDriverByUserId($id, $loggedUser);
            } else {
                return $this->approveCompanyById($id, $loggedUser);
            }
        } catch (\Throwable $e) {
            error_log("ERRO approveVerification: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao aprovar verificação: " . $e->getMessage()], 500);
        }
    }

    private function approveDriverByUserId($id, $loggedUser) {
        // $id pode ser transaction_id ou user_id
        // Primeiro tenta buscar por transaction_id
        $stmt = $this->db->prepare("SELECT user_id FROM transactions WHERE id = ? AND module_key = 'driver' AND feature_key = 'document_verification'");
        $stmt->execute([$id]);
        $trans = $stmt->fetch();
        
        $userId = $trans ? $trans['user_id'] : $id;
        
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? AND role = 'driver'");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return Response::json(["success" => false, "message" => "Motorista não encontrado"], 404);
        }
        
        // Atualiza a transação para approved
        if ($trans) {
            $stmt = $this->db->prepare("UPDATE transactions SET status = 'approved', approved_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
        } else {
            // Se não encontrou transação pelo ID, busca e atualiza a última transação do usuário
            $stmt = $this->db->prepare("UPDATE transactions SET status = 'approved', approved_at = NOW() WHERE user_id = ? AND module_key = 'driver' AND feature_key = 'document_verification' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$userId]);
        }

        $stmt = $this->db->prepare("UPDATE user_documents SET status = 'approved', verified_by = ?, verified_at = NOW() WHERE entity_id = ? AND entity_type = 'USER'");
        $stmt->execute([$loggedUser['id'], $userId]);

        $stmt = $this->db->prepare("UPDATE users SET is_verified = 1, document_verified_at = NOW() WHERE id = ?");
        $stmt->execute([$userId]);

        $stmt = $this->db->prepare("
            INSERT INTO user_modules (user_id, module_key, is_active, activated_at)
            VALUES (?, 'identity_verification', 1, NOW())
            ON DUPLICATE KEY UPDATE is_active = 1, activated_at = NOW()
        ");
        $stmt->execute([$userId]);

        if ($this->notif) {
            $this->notif->createNotification(
                $userId,
                'Verificação Aprovada!',
                'Parabéns! Sua verificação de documentos foi aprovada. Seu perfil agora exibe o badge de identidade confirmada.',
                'verification',
                $userId
            );
        }

        error_log("VERIFICATION APPROVED: Driver {$userId} approved by {$loggedUser['id']}");

        return Response::json([
            "success" => true,
            "message" => "Verificação do motorista aprovada com sucesso!"
        ]);
    }

    private function approveCompanyById($cnpjId, $loggedUser) {
        $stmt = $this->db->prepare("SELECT * FROM verified_cnpj WHERE id = ?");
        $stmt->execute([$cnpjId]);
        $cnpj = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cnpj) {
            return Response::json(["success" => false, "message" => "CNPJ não encontrado"], 404);
        }

        $stmt = $this->db->prepare("UPDATE verified_cnpj SET status = 'verified', reviewed_by = ?, reviewed_at = NOW(), verified_at = NOW() WHERE id = ?");
        $stmt->execute([$loggedUser['id'], $cnpjId]);

        $stmt = $this->db->prepare("UPDATE users SET is_verified = 1 WHERE id = ?");
        $stmt->execute([$cnpj['user_id']]);

        $stmt = $this->db->prepare("
            INSERT INTO user_modules (user_id, module_key, is_active, activated_at)
            VALUES (?, 'identity_verification', 1, NOW())
            ON DUPLICATE KEY UPDATE is_active = 1, activated_at = NOW()
        ");
        $stmt->execute([$cnpj['user_id']]);

        if ($this->notif) {
            $this->notif->createNotification(
                $cnpj['user_id'],
                'Verificação Aprovada!',
                'Parabéns! Sua verificação de empresa foi aprovada. Seu perfil agora exibe o badge de identidade confirmada.',
                'verification',
                $cnpjId
            );
        }

        error_log("VERIFICATION APPROVED: Company {$cnpj['user_id']} (CNPJ {$cnpj['cnpj']}) approved by {$loggedUser['id']}");

        return Response::json([
            "success" => true,
            "message" => "Verificação da empresa aprovada com sucesso!"
        ]);
    }

    public function rejectVerification($data, $loggedUser) {
        $this->authorize(['ADMIN', 'MANAGER']);

        try {
            $id = intval($data['id'] ?? 0);
            $type = $data['type'] ?? '';
            $reason = trim($data['reason'] ?? '');

            if (!$id || !in_array($type, ['driver', 'company'])) {
                return Response::json(["success" => false, "message" => "ID e tipo são obrigatórios"], 400);
            }

            if (empty($reason)) {
                return Response::json(["success" => false, "message" => "Motivo da rejeição é obrigatório"], 400);
            }

            if ($type === 'driver') {
                return $this->rejectDriverByUserId($id, $loggedUser, $reason);
            } else {
                return $this->rejectCompanyById($id, $loggedUser, $reason);
            }
        } catch (\Throwable $e) {
            error_log("ERRO rejectVerification: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao rejeitar verificação: " . $e->getMessage()], 500);
        }
    }

    private function rejectDriverByUserId($id, $loggedUser, $reason) {
        // $id pode ser transaction_id ou user_id
        // Primeiro tenta buscar por transaction_id
        $stmt = $this->db->prepare("SELECT user_id FROM transactions WHERE id = ? AND module_key = 'driver' AND feature_key = 'document_verification'");
        $stmt->execute([$id]);
        $trans = $stmt->fetch();
        
        $userId = $trans ? $trans['user_id'] : $id;
        
        $stmt = $this->db->prepare("SELECT u.*, tr.amount, tr.id as trans_id FROM users u LEFT JOIN transactions tr ON tr.user_id = u.id AND tr.module_key = 'driver' AND tr.feature_key = 'document_verification' WHERE u.id = ? AND u.role = 'driver'");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return Response::json(["success" => false, "message" => "Motorista não encontrado"], 404);
        }

        $stmt = $this->db->prepare("UPDATE user_documents SET status = 'rejected', rejection_reason = ? WHERE entity_id = ? AND entity_type = 'USER' AND status != 'approved'");
        $stmt->execute([$reason, $userId]);

        // Atualiza a transação para rejected
        if (!empty($user['trans_id'])) {
            $stmt = $this->db->prepare("UPDATE transactions SET status = 'rejected' WHERE id = ?");
            $stmt->execute([$user['trans_id']]);
        } else {
            // Busca e atualiza a última transação do usuário
            $stmt = $this->db->prepare("UPDATE transactions SET status = 'rejected' WHERE user_id = ? AND module_key = 'driver' AND feature_key = 'document_verification' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$userId]);
        }

        $amount = floatval($user['amount'] ?? 0);
        if ($amount > 0) {
            $this->creditService->refund($userId, $amount, "Verificação rejeitada: {$reason}");
        }

        if ($this->notif) {
            $msg = $amount > 0 
                ? "Sua verificação de documentos foi recusada. Motivo: {$reason}. O valor de R$ " . number_format($amount, 2, ',', '.') . " foi devolvido para sua carteira."
                : "Sua verificação de documentos foi recusada. Motivo: {$reason}.";
            $this->notif->createNotification($userId, 'Verificação Recusada', $msg, 'verification', $userId);
        }

        error_log("VERIFICATION REJECTED: Driver {$userId} rejected by {$loggedUser['id']}. Reason: {$reason}. Refunded: {$amount}");

        return Response::json([
            "success" => true,
            "message" => "Verificação do motorista recusada." . ($amount > 0 ? " Valor estornado: R$ " . number_format($amount, 2, ',', '.') : ""),
            "amount_refunded" => $amount
        ]);
    }

    private function rejectCompanyById($cnpjId, $loggedUser, $reason) {
        $stmt = $this->db->prepare("SELECT vc.*, tr.amount FROM verified_cnpj vc LEFT JOIN transactions tr ON tr.user_id = vc.user_id AND tr.module_key = 'company_pro' AND tr.feature_key = 'identity_verification' AND tr.status = 'approved' WHERE vc.id = ?");
        $stmt->execute([$cnpjId]);
        $cnpj = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cnpj) {
            return Response::json(["success" => false, "message" => "CNPJ não encontrado"], 404);
        }

        $stmt = $this->db->prepare("UPDATE verified_cnpj SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
        $stmt->execute([$reason, $loggedUser['id'], $cnpjId]);

        $amount = floatval($cnpj['amount'] ?? 0);
        if ($amount > 0) {
            $this->creditService->refund($cnpj['user_id'], $amount, "Verificação CNPJ rejeitada: {$reason}");
        }

        if ($this->notif) {
            $msg = $amount > 0 
                ? "Sua verificação de empresa foi recusada. Motivo: {$reason}. O valor de R$ " . number_format($amount, 2, ',', '.') . " foi devolvido para sua carteira."
                : "Sua verificação de empresa foi recusada. Motivo: {$reason}.";
            $this->notif->createNotification($cnpj['user_id'], 'Verificação Recusada', $msg, 'verification', $cnpjId);
        }

        error_log("VERIFICATION REJECTED: Company {$cnpj['user_id']} (CNPJ {$cnpj['cnpj']}) rejected by {$loggedUser['id']}. Reason: {$reason}. Refunded: {$amount}");

        return Response::json([
            "success" => true,
            "message" => "Verificação da empresa recusada." . ($amount > 0 ? " Valor estornado: R$ " . number_format($amount, 2, ',', '.') : ""),
            "amount_refunded" => $amount
        ]);
    }

    private function getDriverDocuments($userId) {
        $stmt = $this->db->prepare("
            SELECT document_type, file_path, status, rejection_reason 
            FROM user_documents 
            WHERE entity_id = ? AND entity_type = 'USER'
            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =====================================================
    // GESTÃO DE AVALIAÇÕES (REVIEWS)
    // =====================================================

    public function getReviews($data, $loggedUser = null) {
        $this->authorize($loggedUser, 'MANAGER');

        try {
            $status = $data['status'] ?? null;
            $limit = (int)($data['limit'] ?? 20);
            $offset = (int)($data['offset'] ?? 0);

            $reviewRepo = new \App\Repositories\ReviewRepository($this->db);
            $reviews = $reviewRepo->getAll([
                'limit' => $limit,
                'offset' => $offset,
                'status' => $status
            ]);

            $pendingCount = $reviewRepo->getCountAll(['status' => 'pending']);
            $publishedCount = $reviewRepo->getCountAll(['status' => 'published']);
            $rejectedCount = $reviewRepo->getCountAll(['status' => 'rejected']);

            return Response::json([
                "success" => true,
                "data" => $reviews,
                "counts" => [
                    "pending" => $pendingCount,
                    "published" => $publishedCount,
                    "rejected" => $rejectedCount,
                    "total" => $pendingCount + $publishedCount + $rejectedCount
                ],
                "pagination" => [
                    "limit" => $limit,
                    "offset" => $offset
                ]
            ]);
        } catch (\Exception $e) {
            error_log("Error getting reviews: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao buscar avaliações."], 500);
        }
    }

    public function approveReview($data, $loggedUser = null) {
        $this->authorize($loggedUser, 'MANAGER');

        $reviewId = (int)($data['review_id'] ?? 0);
        if (!$reviewId) {
            return Response::json(["success" => false, "message" => "ID da avaliação é obrigatório."], 400);
        }

        try {
            $reviewRepo = new \App\Repositories\ReviewRepository($this->db);
            
            $review = $reviewRepo->findById($reviewId);
            if (!$review) {
                return Response::json(["success" => false, "message" => "Avaliação não encontrada."], 404);
            }

            if ($review['status'] !== 'pending') {
                return Response::json(["success" => false, "message" => "Apenas avaliações pendentes podem ser aprovadas."], 400);
            }

            $reviewRepo->approve($reviewId);

            // Notificar usuário
            if ($this->notif) {
                $this->notif->createNotification(
                    $review['target_id'],
                    'Avaliação Aprovada',
                    "Sua avaliação foi publicada no seu perfil.",
                    'review',
                    $reviewId
                );
            }

            error_log("REVIEW APPROVED: Review {$reviewId} approved by admin {$loggedUser['id']}");

            return Response::json([
                "success" => true,
                "message" => "Avaliação aprovada e publicada."
            ]);
        } catch (\Exception $e) {
            error_log("Error approving review: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao aprovar avaliação."], 500);
        }
    }

    public function rejectReview($data, $loggedUser = null) {
        $this->authorize($loggedUser, 'MANAGER');

        $reviewId = (int)($data['review_id'] ?? 0);
        $reason = trim($data['reason'] ?? 'Violação das diretrizes da comunidade.');
        
        if (!$reviewId) {
            return Response::json(["success" => false, "message" => "ID da avaliação é obrigatório."], 400);
        }

        try {
            $reviewRepo = new \App\Repositories\ReviewRepository($this->db);
            
            $review = $reviewRepo->findById($reviewId);
            if (!$review) {
                return Response::json(["success" => false, "message" => "Avaliação não encontrada."], 404);
            }

            $reviewRepo->reject($reviewId, $reason);

            // Notificar usuário
            if ($this->notif) {
                $this->notif->createNotification(
                    $review['reviewer_id'],
                    'Avaliação Rejeitada',
                    "Sua avaliação foi rejeitada: {$reason}",
                    'review',
                    $reviewId
                );
            }

            error_log("REVIEW REJECTED: Review {$reviewId} rejected by admin {$loggedUser['id']}. Reason: {$reason}");

            return Response::json([
                "success" => true,
                "message" => "Avaliação rejeitada."
            ]);
        } catch (\Exception $e) {
            error_log("Error rejecting review: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao rejeitar avaliação."], 500);
        }
    }

    public function deleteReview($data, $loggedUser = null) {
        $this->authorize($loggedUser, 'MANAGER');

        $reviewId = (int)($data['review_id'] ?? 0);
        
        if (!$reviewId) {
            return Response::json(["success" => false, "message" => "ID da avaliação é obrigatório."], 400);
        }

        try {
            $reviewRepo = new \App\Repositories\ReviewRepository($this->db);
            
            $review = $reviewRepo->findById($reviewId);
            if (!$review) {
                return Response::json(["success" => false, "message" => "Avaliação não encontrada."], 404);
            }

            $reviewRepo->delete($reviewId);

            error_log("REVIEW DELETED: Review {$reviewId} deleted by admin {$loggedUser['id']}");

            return Response::json([
                "success" => true,
                "message" => "Avaliação excluída."
            ]);
        } catch (\Exception $e) {
            error_log("Error deleting review: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao excluir avaliação."], 500);
        }
    }
}