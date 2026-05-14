<?php

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\AdminRepository;
use App\Repositories\AdRepository;
use App\Repositories\FreightRepository;
use App\Repositories\GroupRepository;
use App\Services\CreditService;
use App\Services\NotificationService;
use Exception;
use PDO;

/**
 * @deprecated Use specialized Admin* controllers instead.
 *             Extraídos: AdminUserController, AdminDashboardController,
 *             AdminVerificationController, AdminPlanController, AdminReviewController.
 *             Este controller será removido na Fase 6.
 */
class AdminController
{
    private $repo;
    private $db;
    private $notif;
    private $creditService;
    private $loggedUser;
    private $groupRepo;
    private $freightRepo;
    private $adRepo;
    private $adminRepo;

    public function __construct($db, $adminRepo = null, $loggedUser = null)
    {
        $this->db = $db;
        $this->adminRepo = $adminRepo ?? new AdminRepository($db);
        $this->repo = new AdminRepository($db);
        $this->groupRepo = new GroupRepository($db);
        $this->freightRepo = new FreightRepository($db);
        $this->adRepo = new AdRepository($db);
        $this->creditService = new CreditService($db);
        $this->loggedUser = $loggedUser;
        $this->notif = new NotificationService($db);
    }

    /**
     * Middleware de Segurança
     */
    private function authorize($loggedUser = null, $minRole = 'MANAGER')
    {
        $user = $loggedUser ?? $this->loggedUser;
        if (!$user) {
            throw new Exception('Sessão expirada ou usuário não identificado.', 401);
        }
        $userRole = strtolower($user['role'] ?? '');
        $requiredRole = strtolower($minRole);
        if ($userRole === 'admin') {
            return;
        }
        if ($minRole === 'users.manage' || $minRole === 'users.view') {
            if (\App\Core\Auth::hasPermission($minRole, $user['id'] ?? null)) {
                return;
            }
            throw new Exception('Acesso negado: Permissão insuficiente.', 403);
        }
        $isUserManager = ($userRole === 'manager');
        if ($requiredRole === 'admin') {
            throw new Exception('Acesso negado: Requer nível ADMINISTRADOR.', 403);
        } elseif ($requiredRole === 'manager') {
            if (!$isUserManager) {
                throw new Exception('Acesso negado: Requer nível GERENTE ou superior.', 403);
            }
        } else {
            if (!$isUserManager && $userRole !== $requiredRole) {
                throw new Exception('Acesso negado: Permissão insuficiente.', 403);
            }
        }
    }

    // ===================== LOGS =====================

    public function listLogs($data, $loggedUser)
    {
        if (!$this->adminRepo) {
            $this->adminRepo = $this->repo;
        }
        $role = strtolower($loggedUser['role'] ?? '');
        $allowedRoles = ['admin', 'gerente', 'suporte'];
        if (!$loggedUser || !in_array($role, $allowedRoles)) {
            return Response::json(['success' => false, 'message' => 'Não autorizado'], 403);
        }
        $filters = [
            'page' => isset($data['page']) ? (int)$data['page'] : 1,
            'per_page' => isset($data['per_page']) ? (int)$data['per_page'] : 50,
            'user_id' => $data['user_id'] ?? null,
            'target_type' => $data['target_type'] ?? null,
            'action_type' => $data['action_type'] ?? null,
            'search' => $data['search'] ?? null,
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
        ];
        $result = $this->adminRepo->getAuditLogs($filters);
        $stats = $this->adminRepo->getAuditLogsStats();
        $targetTypes = $this->adminRepo->getAuditLogsDistinctTypes();
        $actionTypes = $this->adminRepo->getAuditLogsDistinctActions();
        return Response::json([
            'success' => true, 'data' => $result['logs'],
            'pagination' => ['page' => $result['page'], 'per_page' => $result['per_page'], 'total' => $result['total'], 'total_pages' => $result['total_pages']],
            'stats' => $stats,
            'filters' => ['target_types' => $targetTypes, 'action_types' => $actionTypes],
        ]);
    }

    // ===================== GESTÃO DE FRETES =====================

    public function listAllFreights($data, $loggedUser)
    {
        try {
            return Response::json(['success' => true, 'data' => $this->adminRepo->getAllFreightsForAdmin()]);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'data' => [], 'error' => $e->getMessage()]);
        }
    }

    public function updateFreightStatus($data, $loggedUser)
    {
        $this->authorize($loggedUser);
        $id = $data['id'] ?? null;
        $status = $data['status'] ?? 'OPEN';
        $approveFeatured = $data['approve_featured'] ?? false;
        $freight = $this->repo->getFreightById($id);
        if (!$freight) {
            return Response::json(['success' => false, 'message' => 'Frete não encontrado']);
        }
        if ($this->repo->updateFreightStatus($id, $status, $approveFeatured)) {
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'FREIGHT_STATUS', "Frete #{$id} -> {$status}", $id, 'FREIGHT');
            if ($status === 'OPEN') {
                $this->notif->notify($freight['user_id'], 'Frete Online!', 'Seu anúncio foi aprovado.');
                $this->triggerMatches($freight);
            }
            return Response::json(['success' => true]);
        }
        return Response::json(['success' => false]);
    }

    private function triggerMatches($freight)
    {
        $drivers = $this->repo->findCompatibleDrivers($freight['vehicle_type'], $freight['body_type'], $freight['origin_state']);
        foreach ($drivers as $driver) {
            $this->notif->notify($driver['user_id'], 'Carga compatível!', "Nova carga de {$freight['product']} disponível.");
        }
    }

    // ===================== LEADS =====================

    public function getPortalRequests($data, $loggedUser)
    {
        $this->authorize($loggedUser);
        return Response::json(['success' => true, 'data' => $this->repo->getPortalRequests($data)]);
    }

    public function updateLeadInternal($data, $loggedUser)
    {
        $this->authorize($loggedUser);
        if ($this->repo->updateLeadInternal($data['id'], $data['admin_notes'] ?? '', $data['status'] ?? 'pending')) {
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'UPDATE_LEAD', "Nota no lead #{$data['id']}", $data['id'], 'LEAD');
            return Response::json(['success' => true]);
        }
        return Response::json(['success' => false]);
    }

    public function softDeleteLead($id)
    {
        return $this->db->prepare('UPDATE portal_requests SET deleted_at = NOW() WHERE id = ?')->execute([$id]);
    }

    // ===================== ADS =====================

    public function manageAds($data, $loggedUser)
    {
        $this->authorize($loggedUser);
        $action = $data['action'] ?? '';
        $id = $data['id'] ?? null;
        if ($_SERVER['REQUEST_METHOD'] === 'GET' || $action === 'list') {
            return Response::json(['success' => true, 'data' => $this->adRepo->listAll($data['status'] ?? null, $data['search'] ?? null)]);
        }
        if ($action === 'delete') {
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'DELETE_AD', "Removeu anúncio #{$id}", $id, 'AD');
            return Response::json(['success' => $this->repo->softDeleteAd($id)]);
        }
        if ($action === 'pause' && $id) {
            $success = $this->adRepo->pauseAd($id);
            if ($success) {
                $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'PAUSE_AD', "Pausou anúncio #{$id}", $id, 'AD');
            }
            return Response::json(['success' => $success]);
        }
        if ($action === 'activate' && $id) {
            $success = $this->adRepo->activateAd($id);
            if ($success) {
                $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'ACTIVATE_AD', "Ativou anúncio #{$id}", $id, 'AD');
            }
            return Response::json(['success' => $success]);
        }
        if ($action === 'renew' && $id) {
            $days = isset($data['days']) ? (int)$data['days'] : 30;
            $success = $this->adRepo->renewAd($id, $days);
            if ($success) {
                $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'RENEW_AD', "Renovou anúncio #{$id} por {$days} dias", $id, 'AD');
            }
            return Response::json(['success' => $success]);
        }
        return Response::json(['success' => $this->repo->toggleAdStatus($id)]);
    }

    // ===================== SETTINGS =====================

    public function getSettings($data, $loggedUser)
    {
        error_log('getSettings chamado com loggedUser: ' . json_encode($loggedUser));
        if (!$loggedUser && $this->loggedUser) {
            $loggedUser = $this->loggedUser;
        }
        try {
            $this->authorize($loggedUser, 'ADMIN');
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => 'Não autorizado: ' . $e->getMessage()], 403);
        }
        try {
            $stmt = $this->db->query('SELECT * FROM site_settings');
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
                $plansStmt = $this->db->query('SELECT id, name, price, duration_days, type, description FROM plans ORDER BY price ASC');
                $plans = $plansStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                error_log('Tabela plans não existe ou erro: ' . $e->getMessage());
            }
            return Response::json(['success' => true, 'data' => $settingsMap, 'byCategory' => $byCategory, 'plans' => $plans]);
        } catch (\Throwable $e) {
            error_log('ERRO getSettings: ' . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao carregar configurações']);
        }
    }

    public function updateSettings($data, $loggedUser)
    {
        $this->authorize($loggedUser, 'ADMIN');
        try {
            $key = $data['key'] ?? null;
            $value = $data['value'] ?? null;
            if (!$key && isset($data['site_name'])) {
                $allowedKeys = [
                    'site_name', 'site_email', 'site_phone', 'site_whatsapp', 'site_logo', 'site_favicon',
                    'module_freights', 'module_quotes', 'module_marketplace', 'module_groups', 'module_ads',
                    'auto_approve_users', 'freight_expiration_days', 'commission_percent', 'min_withdraw',
                    'mp_client_id', 'mp_client_secret', 'mp_access_token', 'referral_enabled', 'referral_commission',
                    'default_plan', 'freight_free_limit', 'maintenance_mode',
                    'vehicle_types', 'body_types', 'equipment_types', 'certification_types',
                    'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from_email', 'smtp_from_name',
                    'review_auto_approve_high_rating', 'review_auto_approve_threshold', 'review_auto_reject_bad_words',
                    'report_auto_dismiss_duplicate',
                ];
                foreach ($allowedKeys as $settingKey) {
                    if (isset($data[$settingKey])) {
                        $this->upsertSetting($settingKey, $data[$settingKey]);
                    }
                }
                error_log("SETTINGS_BULK_UPDATE: by_user={$loggedUser['id']}");
                return Response::json(['success' => true, 'message' => 'Configurações salvas com sucesso']);
            }
            if (!$key) {
                return Response::json(['success' => false, 'message' => 'Chave não informada'], 400);
            }
            $this->upsertSetting($key, $value);
            error_log("SETTINGS_UPDATE: key={$key}, by_user={$loggedUser['id']}");
            return Response::json(['success' => true, 'message' => 'Configuração atualizada com sucesso']);
        } catch (\Throwable $e) {
            error_log('ERRO updateSettings: ' . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao atualizar configuração']);
        }
    }

    private function upsertSetting(string $key, $value): void
    {
        $stmt = $this->db->prepare('SELECT setting_key FROM site_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        if ($stmt->fetch()) {
            $this->db->prepare('UPDATE site_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?')->execute([(string)$value, $key]);
        }
    }

    // ===================== MATCHING DE MOTORISTAS =====================

    public function findMatchingDrivers($data, $loggedUser)
    {
        $this->authorize($loggedUser, 'ADMIN');
        try {
            $freightId = (int)($data['freight_id'] ?? 0);
            $maxDistance = (int)($data['max_distance_km'] ?? 200);
            if (!$freightId) {
                return Response::json(['success' => false, 'message' => 'ID do frete não informado'], 400);
            }
            $stmt = $this->db->prepare('SELECT id, origin_city, origin_state, origin_lat, origin_lng, dest_city, dest_state, vehicle_type, body_type, product, equipment_needed, certifications_needed FROM freights WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$freightId]);
            $freight = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$freight) {
                return Response::json(['success' => false, 'message' => 'Frete não encontrado'], 404);
            }
            if (!$freight['origin_lat'] || !$freight['origin_lng']) {
                return Response::json(['success' => false, 'message' => 'Frete não possui coordenadas de origem. Use a página de edição para geolocalizar.'], 400);
            }

            $query = "SELECT u.id AS driver_id, u.name AS driver_name, u.slug AS driver_slug, u.whatsapp AS driver_whatsapp, p.vehicle_type, p.body_type, p.home_city, p.home_state, p.service_radius_km, p.available_equipment, p.certifications, p.avatar_url, p.verification_status, p.profile_completeness,
                ROUND(6371 * ACOS(COS(RADIANS(:origin_lat)) * COS(RADIANS(p.home_lat)) * COS(RADIANS(p.home_lng) - RADIANS(:origin_lng)) + SIN(RADIANS(:origin_lat)) * SIN(RADIANS(p.home_lat))), 2) AS distance_km,
                CASE WHEN p.availability_status = 'available' THEN 30 ELSE 0 END + CASE WHEN p.vehicle_type = :vehicle_type THEN 30 ELSE 0 END + CASE WHEN p.body_type = :body_type THEN 20 ELSE 0 END + CASE WHEN p.verification_status = 'verified' THEN 20 ELSE 0 END AS match_score
                FROM users u INNER JOIN user_profiles p ON u.id = p.user_id
                WHERE u.role = 'driver' AND u.status = 'active' AND p.availability_status = 'available' AND p.home_lat IS NOT NULL AND p.home_lng IS NOT NULL
                AND ROUND(6371 * ACOS(COS(RADIANS(:origin_lat2)) * COS(RADIANS(p.home_lat)) * COS(RADIANS(p.home_lng) - RADIANS(:origin_lng2)) + SIN(RADIANS(:origin_lat2)) * SIN(RADIANS(p.home_lat))), 2) <= :max_distance
                AND ROUND(6371 * ACOS(COS(RADIANS(:origin_lat3)) * COS(RADIANS(p.home_lat)) * COS(RADIANS(p.home_lng) - RADIANS(:origin_lng3)) + SIN(RADIANS(:origin_lat3)) * SIN(RADIANS(p.home_lat))), 2) <= p.service_radius_km
                ORDER BY match_score DESC, distance_km ASC LIMIT 50";
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':origin_lat' => $freight['origin_lat'], ':origin_lng' => $freight['origin_lng'],
                ':vehicle_type' => $freight['vehicle_type'] ?? '', ':body_type' => $freight['body_type'] ?? '',
                ':origin_lat2' => $freight['origin_lat'], ':origin_lng2' => $freight['origin_lng'],
                ':origin_lat3' => $freight['origin_lat'], ':origin_lng3' => $freight['origin_lng'],
                ':max_distance' => $maxDistance,
            ]);
            $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($drivers as &$driver) {
                if (isset($driver['available_equipment'])) {
                    $driver['available_equipment'] = json_decode($driver['available_equipment'], true) ?? [];
                }
                if (isset($driver['certifications'])) {
                    $driver['certifications'] = json_decode($driver['certifications'], true) ?? [];
                }
            }
            return Response::json([
                'success' => true,
                'freight' => ['id' => $freight['id'], 'origin' => $freight['origin_city'] . '/' . $freight['origin_state'], 'destination' => $freight['dest_city'] . '/' . $freight['dest_state'], 'product' => $freight['product'], 'vehicle_type' => $freight['vehicle_type'], 'body_type' => $freight['body_type'], 'equipment_needed' => $freight['equipment_needed'] ? json_decode($freight['equipment_needed'], true) : [], 'certifications_needed' => $freight['certifications_needed'] ? json_decode($freight['certifications_needed'], true) : []],
                'drivers' => $drivers, 'total' => count($drivers),
            ]);
        } catch (\Throwable $e) {
            error_log('ERRO findMatchingDrivers: ' . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao buscar motoristas'], 500);
        }
    }

    public function updateDriverLocation($data, $loggedUser)
    {
        $this->authorize($loggedUser, 'ADMIN');
        try {
            $userId = (int)($data['user_id'] ?? 0);
            $homeLat = (float)($data['home_lat'] ?? 0);
            $homeLng = (float)($data['home_lng'] ?? 0);
            $homeCity = trim($data['home_city'] ?? '');
            $homeState = trim($data['home_state'] ?? '');
            $serviceRadius = (int)($data['service_radius_km'] ?? 100);
            if (!$userId) {
                return Response::json(['success' => false, 'message' => 'ID do usuário não informado'], 400);
            }
            if (!$homeLat || !$homeLng) {
                return Response::json(['success' => false, 'message' => 'Coordenadas inválidas'], 400);
            }
            $stmt = $this->db->prepare('UPDATE user_profiles SET home_lat = ?, home_lng = ?, home_city = ?, home_state = ?, service_radius_km = ? WHERE user_id = ?');
            $stmt->execute([$homeLat, $homeLng, $homeCity, $homeState, $serviceRadius, $userId]);
            $this->recalculateProfileCompleteness($userId);
            return Response::json(['success' => true, 'message' => 'Localização atualizada']);
        } catch (\Throwable $e) {
            error_log('ERRO updateDriverLocation: ' . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao atualizar localização'], 500);
        }
    }

    private function recalculateProfileCompleteness(int $userId): void
    {
        try {
            $stmt = $this->db->prepare('SELECT u.name, p.bio, p.avatar_url, p.vehicle_type, p.body_type, p.home_lat, p.home_lng, p.rntrc_number, p.verification_status FROM users u INNER JOIN user_profiles p ON u.id = p.user_id WHERE u.id = ?');
            $stmt->execute([$userId]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$profile) {
                return;
            }
            $score = 0;
            if (!empty($profile['name'])) {
                $score += 10;
            }
            if (!empty($profile['bio'])) {
                $score += 10;
            }
            if (!empty($profile['avatar_url'])) {
                $score += 10;
            }
            if (!empty($profile['vehicle_type'])) {
                $score += 15;
            }
            if (!empty($profile['body_type'])) {
                $score += 15;
            }
            if (!empty($profile['home_lat']) && !empty($profile['home_lng'])) {
                $score += 15;
            }
            if (!empty($profile['rntrc_number'])) {
                $score += 10;
            }
            if ($profile['verification_status'] === 'verified') {
                $score += 15;
            }
            $this->db->prepare('UPDATE user_profiles SET profile_completeness = ? WHERE user_id = ?')->execute([$score, $userId]);
        } catch (\Throwable $e) {
            error_log('ERRO recalculateProfileCompleteness: ' . $e->getMessage());
        }
    }

    // ===================== ACTIVITY LOGS =====================

    public function getActivityLogs($data, $loggedUser)
    {
        $this->authorize($loggedUser, 'ADMIN');
        try {
            $limit = $data['limit'] ?? 50;
            $page = $data['page'] ?? 1;
            $offset = ($page - 1) * $limit;
            $stmt = $this->db->prepare('SELECT la.*, u.name as user_name FROM logs_auditoria la LEFT JOIN users u ON la.user_id = u.id ORDER BY la.created_at DESC LIMIT :limit OFFSET :offset');
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = $this->db->query('SELECT COUNT(*) as total FROM logs_auditoria')->fetch()['total'];
            return Response::json(['success' => true, 'data' => $logs, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao carregar atividades']);
        }
    }

    // ===================== CRÉDITOS =====================

    public function manualAddCredits($data, $loggedUser)
    {
        $this->authorize($loggedUser);
        $userId = $data['user_id'] ?? null;
        $amount = (int)($data['amount'] ?? 0);
        $reason = $data['reason'] ?? 'Adição manual via painel';
        if (!$userId || $amount <= 0) {
            return Response::json(['success' => false, 'message' => 'Dados inválidos']);
        }
        try {
            $this->db->beginTransaction();
            $stmtCheck = $this->db->prepare('SELECT id FROM users WHERE id = ?');
            $stmtCheck->execute([$userId]);
            if (!$stmtCheck->fetch()) {
                throw new Exception('Usuário não encontrado');
            }
            $this->db->prepare('UPDATE users SET ad_credits = ad_credits + :amount WHERE id = :id')->execute([':amount' => $amount, ':id' => $userId]);
            $this->db->prepare("INSERT INTO credit_transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'recharge', ?, NOW())")->execute([$userId, $amount, $reason . " (Por: {$loggedUser['name']})"]);
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'MANUAL_CREDIT', "Adicionou {$amount} créditos ao usuário #{$userId}", $userId, 'USER');
            $this->db->commit();
            $this->notif->notify($userId, 'Créditos Adicionados!', "Você recebeu {$amount} créditos.");
            return Response::json(['success' => true]);
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return Response::json(['success' => false]);
        }
    }

    // ===================== GESTÃO DE FRETES (ADMIN) =====================

    public function manageFreights($data, $loggedUser)
    {
        $this->authorize($loggedUser);
        $id = $data['id'] ?? null;
        $action = $data['action'] ?? '';
        $success = false;
        if (!$id) {
            return Response::json(['success' => false, 'message' => 'ID ausente']);
        }
        switch ($action) {
            case 'toggle-featured':
                $featured = (int)($data['featured'] ?? 0);
                $success = $this->repo->toggleFeatured($id, $featured);
                $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'UPDATE', "Alterou destaque do frete #{$id}", $id, 'FREIGHT');
                break;
            case 'delete':
                $success = $this->repo->updateFreightStatus($id, 'DELETED', false);
                $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'DELETE', "Removeu frete #{$id}", $id, 'FREIGHT');
                break;
            case 'approve':
                $success = $this->repo->updateFreightStatus($id, 'OPEN', true);
                break;
            default:
                return Response::json(['success' => false, 'message' => 'Ação inválida']);
        }
        return Response::json(['success' => $success]);
    }

    // ===================== TICKETS =====================

    public function listTickets($data, $loggedUser)
    {
        $this->authorize($loggedUser);
        return Response::json(['success' => true, 'data' => $this->repo->getTickets($data['status'] ?? '%')]);
    }

    public function replyTicket($data, $loggedUser)
    {
        $this->authorize($loggedUser);
        $ticketId = $data['ticket_id'];
        $message = $data['message'];
        if ($this->repo->addTicketMessage($ticketId, $loggedUser['id'], $message, true)) {
            $ticket = $this->repo->getTicketById($ticketId);
            $this->notif->notify($ticket['user_id'], 'Suporte respondeu!', 'Verifique seu chamado: ' . $ticket['subject']);
            return Response::json(['success' => true]);
        }
        return Response::json(['success' => false]);
    }

    // ===================== GRUPOS =====================

    public function listAllGroups($data, $loggedUser)
    {
        $this->authorize($loggedUser);
        return Response::json(['success' => true, 'data' => $this->groupRepo->listAll()]);
    }

    public function manageGroups($data, $loggedUser)
    {
        try {
            $this->authorize($loggedUser);
            $result = $this->groupRepo->save($data);
            if ($result) {
                $action = isset($data['id']) ? 'UPDATE_GROUP' : 'CREATE_GROUP';
                $this->adminRepo->saveLog($loggedUser['id'], $loggedUser['name'], $action, 'Gerenciou grupo: ' . ($data['region_name'] ?? 'ID ' . $result), $result, 'WA_GROUP');
                return Response::json(['success' => true, 'id' => $result]);
            }
            return Response::json(['success' => false, 'message' => 'Erro ao salvar grupo']);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        }
    }
}
