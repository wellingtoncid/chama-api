<?php

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\AdminRepository;
use Exception;
use PDO;

class AdminPlanController
{
    private PDO $db;
    private AdminRepository $repo;
    private ?array $loggedUser;

    public function __construct(PDO $db, ?array $loggedUser = null)
    {
        $this->db = $db;
        $this->repo = new AdminRepository($db);
        $this->loggedUser = $loggedUser;
    }

    private function authorize(?array $loggedUser = null, string $minRole = 'ADMIN'): void
    {
        $user = $loggedUser ?? $this->loggedUser;
        if (!$user) {
            throw new Exception('Sessão expirada ou usuário não identificado.', 401);
        }
        $userRole = strtolower($user['role'] ?? '');
        $roleHierarchy = ['admin' => 5, 'manager' => 4, 'analyst' => 3, 'assistant' => 2];
        $userLevel = $roleHierarchy[$userRole] ?? 0;
        $requiredLevel = $roleHierarchy[strtolower($minRole)] ?? 0;
        if ($userLevel < $requiredLevel) {
            throw new Exception('Acesso negado. Permissão insuficiente.', 403);
        }
    }

    public function managePlans($data, $loggedUser)
    {
        $this->authorize($loggedUser, 'ADMIN');
        $action = $data['action'] ?? 'list';
        if ($_SERVER['REQUEST_METHOD'] === 'GET' || $action === 'list') {
            $plans = $this->repo->managePlans(['action' => 'list']);
            return Response::json(['success' => true, 'plans' => $plans]);
        }
        return Response::json(['success' => true, 'data' => $this->repo->managePlans($data)]);
    }

    public function getAdvertisingPlans($data, $loggedUser)
    {
        try {
            $category = $data['category'] ?? 'advertising';
            $sql = 'SELECT * FROM plans WHERE active = 1 AND category = :category ORDER BY sort_order ASC, price ASC';
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':category' => $category]);
            $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                        'yearly' => $plan['price_yearly'] ? (float)$plan['price_yearly'] : null,
                    ],
                    'discounts' => [
                        'quarterly' => (int)$plan['discount_quarterly'],
                        'semiannual' => (int)$plan['discount_semiannual'],
                        'yearly' => (int)$plan['discount_yearly'],
                    ],
                    'duration_days' => (int)$plan['duration_days'],
                    'limit_monthly' => (int)$plan['limit_monthly'],
                    'has_verification_badge' => (bool)$plan['has_verification_badge'],
                    'priority_support' => (bool)$plan['priority_support'],
                ];
            }
            return Response::json(['success' => true, 'data' => $formatted]);
        } catch (Exception $e) {
            error_log('Erro em getAdvertisingPlans: ' . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao carregar planos'], 500);
        }
    }

    public function managePricing($data, $loggedUser)
    {
        $this->authorize($loggedUser, 'ADMIN');
        $action = $data['action'] ?? 'list';
        try {
            if ($action === 'list') {
                $stmt = $this->db->query('SELECT * FROM pricing_rules ORDER BY module_key, feature_key');
                return Response::json(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
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
                    'is_active' => isset($data['is_active']) ? 1 : 0,
                ];
                if ($id > 0) {
                    $sql = 'UPDATE pricing_rules SET module_key = :module_key, feature_key = :feature_key, feature_name = :feature_name, pricing_type = :pricing_type, free_limit = :free_limit, price_per_use = :price_per_use, price_monthly = :price_monthly, price_daily = :price_daily, duration_days = :duration_days, is_active = :is_active WHERE id = :id';
                    $fields['id'] = $id;
                } else {
                    $sql = 'INSERT INTO pricing_rules (module_key, feature_key, feature_name, pricing_type, free_limit, price_per_use, price_monthly, price_daily, duration_days, is_active) VALUES (:module_key, :feature_key, :feature_name, :pricing_type, :free_limit, :price_per_use, :price_monthly, :price_daily, :duration_days, :is_active)';
                }
                $stmt = $this->db->prepare($sql);
                $stmt->execute($fields);
                return Response::json(['success' => true, 'message' => 'Regra salva com sucesso!']);
            }
            if ($action === 'delete') {
                $id = $data['id'] ?? 0;
                $stmt = $this->db->prepare('DELETE FROM pricing_rules WHERE id = ?');
                $stmt->execute([$id]);
                return Response::json(['success' => true, 'message' => 'Regra excluída!']);
            }
            return Response::json(['success' => false, 'message' => 'Ação inválida']);
        } catch (\Throwable $e) {
            error_log('ERRO managePricing: ' . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro interno']);
        }
    }
}
