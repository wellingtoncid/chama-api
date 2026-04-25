<?php

namespace App\Repositories;

use PDO;
use Exception;

class AdminRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

   // --- AUDITORIA & LOGS ---
    public function saveLog($uId, $uName, $type, $desc, $targetId, $targetType, $old = null, $new = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // Converta arrays para string JSON se necessário
        $oldStr = $old ? json_encode($old) : null;
        $newStr = $new ? json_encode($new) : null;

        $sql = "INSERT INTO logs_auditoria 
            (user_id, user_name, action_type, description, target_id, target_type, ip_address, user_agent, old_values, new_values) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        return $this->db->prepare($sql)->execute([
            $uId, $uName, $type, $desc, $targetId, $targetType, $ip, $agent, $oldStr, $newStr
        ]);
    }

     /**
     * Busca os logs mais recentes de auditoria para o feed do dashboard    
     */
    public function getRecentActivities() {
        $sql = "SELECT 
                    id,
                    user_name as user, 
                    description as action, 
                    created_at as time, 
                    target_type as type,
                    ip_address,
                    user_agent
                FROM logs_auditoria 
                ORDER BY created_at DESC 
                LIMIT 10";
                
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    // ============================================
    // ESTATÍSTICAS POR PERÍODO
    // ============================================

    public function getFreightsCountByDateRange(string $startDate, string $endDate): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM freights WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ?");
        $stmt->execute([$startDate, $endDate . ' 23:59:59']);
        return (int)$stmt->fetch()['total'];
    }

    public function getUsersCountByDateRange(string $startDate, string $endDate): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM users WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ?");
        $stmt->execute([$startDate, $endDate . ' 23:59:59']);
        return (int)$stmt->fetch()['total'];
    }

    public function getCompaniesCountByDateRange(string $startDate, string $endDate): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM users WHERE deleted_at IS NULL AND role = 'company' AND created_at BETWEEN ? AND ?");
        $stmt->execute([$startDate, $endDate . ' 23:59:59']);
        return (int)$stmt->fetch()['total'];
    }

    public function getRevenueByDateRange(string $startDate, string $endDate): float {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE status = 'approved' AND created_at BETWEEN ? AND ?");
        $stmt->execute([$startDate, $endDate . ' 23:59:59']);
        return (float)$stmt->fetch()['total'];
    }

    public function getActiveSubscriptionsCount(string $startDate, string $endDate): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM transactions WHERE status = 'approved' AND DATE_ADD(created_at, INTERVAL COALESCE(duration_days, 30) DAY) > NOW() AND created_at BETWEEN ? AND ?");
        $stmt->execute([$startDate, $endDate . ' 23:59:59']);
        return (int)$stmt->fetch()['total'];
    }

    public function getTopOrigins(string $startDate, string $endDate, int $limit = 10): array {
        $stmt = $this->db->prepare("SELECT origin_city, COUNT(*) as total FROM freights WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ? AND origin_city IS NOT NULL AND origin_city != '' GROUP BY origin_city ORDER BY total DESC LIMIT ?");
        $stmt->execute([$startDate, $endDate . ' 23:59:59', $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDateSeries(string $table, string $dateColumn, string $startDate, string $endDate): array {
        $stmt = $this->db->prepare("SELECT DATE(created_at) as date, COUNT(*) as total FROM {$table} WHERE deleted_at IS NULL AND {$dateColumn} BETWEEN ? AND ? GROUP BY DATE({$dateColumn}) ORDER BY date");
        $stmt->execute([$startDate, $endDate . ' 23:59:59']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ============================================
    // SETTINGS
    // ============================================

    public function getSetting(string $key): ?string {
        $stmt = $this->db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['setting_value'] : null;
    }

    public function setSetting(string $key, string $value): bool {
        $stmt = $this->db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        return $stmt->execute([$key, $value, $value]);
    }

    public function getAllSettings(): array {
        $stmt = $this->db->query("SELECT * FROM site_settings");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    // ============================================
    // DASHBOARD STATS
    // ============================================

    public function getDashboardStats() {
        // 1. Contadores Gerais
        // Mudança: Contamos ACCOUNTs únicas para saber o número real de empresas/anunciantes
        // e usuários totais para saber o volume de pessoas.
        $userStats = $this->db->query("
            SELECT 
                COUNT(*) as total_users,
                COUNT(CASE WHEN user_type = 'DRIVER' THEN 1 END) as drivers,
                -- Contamos contas únicas do tipo COMPANY para não inflar com sub-usuários
                (SELECT COUNT(*) FROM accounts WHERE status = 'active') as actual_companies,
                COUNT(CASE WHEN user_type = 'ADVERTISER' THEN 1 END) as advertisers
            FROM users 
            WHERE deleted_at IS NULL
        ")->fetch(PDO::FETCH_ASSOC);

        // 2. Estatísticas de Fretes e Interações (Mantém como está)
        $freightStats = $this->db->query("
            SELECT 
                COUNT(CASE WHEN status = 'PENDING' THEN 1 END) as pending_freights,
                COUNT(CASE WHEN status = 'OPEN' THEN 1 END) as active_freights,
                COUNT(CASE WHEN is_featured = 1 AND status = 'OPEN' THEN 1 END) as featured_freights,
                IFNULL(SUM(views_count), 0) as total_views,
                IFNULL(SUM(clicks_count), 0) as total_clicks
            FROM freights
            WHERE deleted_at IS NULL
        ")->fetch(PDO::FETCH_ASSOC);

        // 3. Leads (Portal Requests)
        $pendingLeads = $this->db->query("
            SELECT COUNT(*) FROM portal_requests WHERE status = 'pending'
        ")->fetchColumn();

        // 4. Financeiro
        $revenue = $this->db->query("
            SELECT 
                IFNULL(SUM(CASE WHEN status IN ('approved', 'completed') THEN amount ELSE 0 END), 0) as confirmed,
                IFNULL(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending
            FROM transactions
        ")->fetch(PDO::FETCH_ASSOC);

        return [
            'counters' => [
                'total_users'       => (int)$userStats['total_users'],
                'drivers'           => (int)$userStats['drivers'],
                // Exibimos o número de Contas Jurídicas reais
                'companies'         => (int)$userStats['actual_companies'], 
                'advertisers'       => (int)$userStats['advertisers'],
                'pending_freights'  => (int)$freightStats['pending_freights'],
                'active_freights'   => (int)$freightStats['active_freights'],
                'featured_freights' => (int)$freightStats['featured_freights'],
                'total_views'       => (int)$freightStats['total_views'],
                'total_clicks'      => (int)$freightStats['total_clicks'],
                'pending_leads'     => (int)$pendingLeads
            ],
            'revenue' => [
                'confirmed' => number_format((float)$revenue['confirmed'], 2, '.', ''),
                'pending'   => number_format((float)$revenue['pending'], 2, '.', '')
            ]
        ];
    }

    // --- GESTÃO DE FRETES ---
    public function getAllFreightsForAdmin() {
        $sql = "SELECT 
                    f.id, 
                    f.origin_city, 
                    f.origin_state,
                    f.dest_city,      
                    f.dest_state,
                    f.product, 
                    f.weight,         
                    f.price,         
                    f.vehicle_type, 
                    f.body_type,     
                    f.description,        
                    f.is_featured, 
                    f.requested_featured,
                    f.user_id,
                    f.status,
                    f.payment_status,
                    f.created_at,
                    COALESCE(a.trade_name, a.corporate_name, u.name) as company_name,
                    u.email as user_email
                FROM freights f
                LEFT JOIN users u ON f.user_id = u.id
                LEFT JOIN accounts a ON u.account_id = a.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                WHERE f.deleted_at IS NULL
                ORDER BY f.created_at DESC";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC); 
        } catch (\Exception $e) {
            error_log("Erro no AdminRepository: " . $e->getMessage());
            return [];
        }
    }   

    public function toggleFeatured($id, $featured) {
        $sql = "UPDATE freights SET is_featured = ?, requested_featured = '0' WHERE id = ?";
        return $this->db->prepare($sql)->execute([$featured, $id]);
    }
    
    public function getAuditLogs(array $filters = []) {
        error_log("getAuditLogs: called with filters=" . json_encode($filters));
        
        $page = $filters['page'] ?? 1;
        $perPage = $filters['per_page'] ?? 50;
        $userId = $filters['user_id'] ?? null;
        $targetType = $filters['target_type'] ?? null;
        $actionType = $filters['action_type'] ?? null;
        $search = $filters['search'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        
        $where = [];
        $params = [];
        
        if ($userId) {
            $where[] = "user_id = :user_id";
            $params[':user_id'] = (int)$userId;
        }
        
        if ($targetType) {
            $where[] = "target_type = :target_type";
            $params[':target_type'] = $targetType;
        }
        
        if ($actionType) {
            $where[] = "action_type = :action_type";
            $params[':action_type'] = $actionType;
        }
        
        if ($search) {
            $where[] = "(description LIKE :search OR user_name LIKE :search)";
            $params[':search'] = "%{$search}%";
        }
        
        if ($dateFrom) {
            $where[] = "created_at >= :date_from";
            $params[':date_from'] = $dateFrom . " 00:00:00";
        }
        
        if ($dateTo) {
            $where[] = "created_at <= :date_to";
            $params[':date_to'] = $dateTo . " 23:59:59";
        }
        
        $whereClause = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";
        
        $offset = ($page - 1) * $perPage;
        
        $sql = "SELECT * FROM logs_auditoria {$whereClause} ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $countSql = "SELECT COUNT(*) as total FROM logs_auditoria {$whereClause}";
        
        try {
            $stmt = $this->db->prepare($sql);
            $countStmt = $this->db->prepare($countSql);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
                $countStmt->bindValue($key, $value);
            }
            
            $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $countStmt->execute();
            
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            
            return [
                'logs' => $logs,
                'total' => (int)$total,
                'page' => (int)$page,
                'per_page' => (int)$perPage,
                'total_pages' => ceil($total / $perPage)
            ];
        } catch (\Exception $e) {
            error_log("Erro ao buscar logs: " . $e->getMessage());
            return ['logs' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 0];
        }
    }
    
    public function getAuditLogsStats() {
        try {
            $today = date('Y-m-d');
            $weekAgo = date('Y-m-d', strtotime('-7 days'));
            $monthAgo = date('Y-m-d', strtotime('-30 days'));
            
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(CASE WHEN DATE(created_at) = :today THEN 1 END) as today,
                    COUNT(CASE WHEN DATE(created_at) >= :weekAgo THEN 1 END) as this_week,
                    COUNT(CASE WHEN DATE(created_at) >= :monthAgo THEN 1 END) as this_month,
                    COUNT(*) as total
                FROM logs_auditoria
            ");
            $stmt->execute([':today' => $today, ':weekAgo' => $weekAgo, ':monthAgo' => $monthAgo]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("Erro ao buscar stats de audit: " . $e->getMessage());
            return ['today' => 0, 'this_week' => 0, 'this_month' => 0, 'total' => 0];
        }
    }
    
    public function getAuditLogsDistinctTypes() {
        try {
            $stmt = $this->db->query("
                SELECT DISTINCT target_type as value, target_type as label 
                FROM logs_auditoria 
                WHERE target_type IS NOT NULL AND target_type != ''
                ORDER BY target_type
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }
    
    public function getAuditLogsDistinctActions() {
        try {
            $stmt = $this->db->query("
                SELECT DISTINCT action_type as value, action_type as label 
                FROM logs_auditoria 
                WHERE action_type IS NOT NULL AND action_type != ''
                ORDER BY action_type
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    // DASHBOARD & ESTATÍSTICAS AVANÇADAS
    public function getDetailedRevenue() {
        return $this->db->query("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                SUM(amount) as total,
                COUNT(id) as transactions
            FROM transactions 
            WHERE status IN ('approved', 'completed')
            GROUP BY month 
            ORDER BY month DESC 
            LIMIT 12
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getFinancialStats() {
        $confirmed = $this->db->query("
            SELECT IFNULL(SUM(amount), 0) as total 
            FROM transactions 
            WHERE status = 'approved'
        ")->fetch(PDO::FETCH_ASSOC);

        $pending = $this->db->query("
            SELECT IFNULL(SUM(amount), 0) as total 
            FROM transactions 
            WHERE status = 'pending'
        ")->fetch(PDO::FETCH_ASSOC);

        $subscriberCount = $this->db->query("
            SELECT COUNT(DISTINCT user_id) as total 
            FROM transactions 
            WHERE status = 'approved'
            AND transaction_type IN ('subscription', 'monthly')
        ")->fetch(PDO::FETCH_ASSOC);

        $latestTransactions = $this->db->query("
            SELECT t.id, t.user_id, t.amount, t.status, t.created_at, t.module_key, t.feature_key,
                   u.name as user_name, p.name as plan_name
            FROM transactions t
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN plans p ON t.plan_id = p.id
            ORDER BY t.created_at DESC
            LIMIT 1000
        ")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'total_revenue' => number_format((float)$confirmed['total'], 2, '.', ''),
            'pending_revenue' => number_format((float)$pending['total'], 2, '.', ''),
            'subscriber_count' => (int)($subscriberCount['total'] ?? 0),
            'latest_transactions' => $latestTransactions
        ];
    }

    // USUÁRIOS (Incluso Soft Delete e Filtros)

    public function listUsersByRole($role = '%', $search = '%') {
        $sql = "SELECT 
                    u.id, 
                    u.parent_id,
                    u.email, 
                    u.whatsapp, 
                    u.role, 
                    u.status, 
                    u.created_at,
                    u.user_type,
                    u.plan_id,
                    u.account_id,
                    u.is_verified,
                    u.access_level,
                    u.name as user_name,
                    a.trade_name as company_name,
                    a.corporate_name as company_corporate_name,
                    a.document_number as company_document,
                    a.document_type as company_document_type,
                    p.name as parent_name,
                    COALESCE(a.trade_name, a.corporate_name, u.name) as display_name,
                    up.extended_attributes as profile_details
                FROM users u
                LEFT JOIN accounts a ON u.account_id = a.id
                LEFT JOIN user_profiles up ON u.id = up.user_id 
                LEFT JOIN users p ON u.parent_id = p.id
                WHERE u.deleted_at IS NULL 
                AND (? = '%' OR u.role LIKE ? OR u.user_type LIKE ?)
                AND (
                    u.name LIKE ? 
                    OR u.email LIKE ? 
                    OR (a.trade_name IS NOT NULL AND a.trade_name LIKE ?) 
                    OR (a.corporate_name IS NOT NULL AND a.corporate_name LIKE ?)
                    OR (up.extended_attributes IS NOT NULL AND up.extended_attributes LIKE ?)
                )
                ORDER BY u.id DESC";

        $stmt = $this->db->prepare($sql);
        
        $searchTerm = "%$search%";
        $roleTerm = ($role === '' || $role === null || $role === '%') ? '%' : $role;

        // Executamos com os parâmetros mapeados para a nova estrutura
        $stmt->execute([
            $roleTerm,     // u.role
            $roleTerm,     // u.user_type (backup de busca)
            $roleTerm,     // Comparação direta para o OR ? = '%'
            $searchTerm,   // u.name
            $searchTerm,   // u.email
            $searchTerm,   // a.trade_name
            $searchTerm,   // a.corporate_name
            $searchTerm    // up.extended_attributes
        ]);
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Pós-processamento: Extrair campos do JSON para o Admin se necessário
        return array_map(function($user) {
            if (!empty($user['profile_details'])) {
                $details = json_decode($user['profile_details'], true);
                $user['business_type'] = $details['business_segment'] ?? 'N/A';
            } else {
                $user['business_type'] = 'N/A';
            }
            unset($user['profile_details']); // Limpa o JSON bruto para não pesar o retorno
            return $user;
        }, $users);
    }

    public function setUserVerification($id, $status) {
        return $this->db->prepare("UPDATE users SET is_verified = ? WHERE id = ?")->execute([$status, $id]);
    }

    public function updateUserDetails($data) {
        try {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
            }

            // 1. Atualiza a tabela USERS (Dados de Acesso e Conta)
            $perms = is_array($data['permissions'] ?? null) ? json_encode($data['permissions']) : ($data['permissions'] ?? '[]');
            
            $sqlUser = "UPDATE users SET 
                            name = ?, whatsapp = ?, role = ?, 
                            status = ?, permissions = ?, plan_id = ?,
                            user_type = ?, access_level = ?
                        WHERE id = ?";
            
            $this->db->prepare($sqlUser)->execute([
                $data['user_name'] ?? $data['name'] ?? '', 
                preg_replace('/\D/', '', $data['whatsapp'] ?? ''), 
                $data['role'] ?? 'company', 
                $data['status'] ?? 'pending', 
                $perms, 
                $data['plan_id'] ?? 0, 
                $data['user_type'] ?? 'COMPANY',
                $data['access_level'] ?? null,
                $data['id']
            ]);

            // 2. Atualiza ACCOUNTS (Dados Fiscais/Identidade da Empresa)
            // Primeiro busca o account_id vinculado a este usuário
            $stmtAccId = $this->db->prepare("SELECT account_id FROM users WHERE id = ?");
            $stmtAccId->execute([$data['id']]);
            $accountId = $stmtAccId->fetchColumn();

            if ($accountId) {
                // Atualiza ACCOUNTS com os dados do documento (CNPJ ou CPF)
                $documentNumber = preg_replace('/\D/', '', $data['document'] ?? $data['company_document'] ?? $data['cnpj'] ?? '');
                $documentType = $data['document_type'] ?? $data['company_document_type'] ?? (strlen($documentNumber) === 14 ? 'CNPJ' : (strlen($documentNumber) === 11 ? 'CPF' : null));
                
                $sqlAccount = "UPDATE accounts SET 
                                    trade_name = ?, 
                                    document_number = ?,
                                    document_type = ?,
                                    corporate_name = ?
                                WHERE id = ?";
                $this->db->prepare($sqlAccount)->execute([
                    $data['company_name'] ?? ($data['name'] ?? ''),
                    $documentNumber,
                    $documentType,
                    $data['company_corporate_name'] ?? $data['corporate_name'] ?? ($data['company_name'] ?? $data['name']),
                    $accountId
                ]);
            } elseif (!empty($data['document']) || !empty($data['company_document']) || !empty($data['cnpj'])) {
                // Se não tem account ainda mas tem documento, cria um
                $documentNumber = preg_replace('/\D/', '', $data['document'] ?? $data['company_document'] ?? $data['cnpj'] ?? '');
                $documentType = $data['document_type'] ?? $data['company_document_type'] ?? (strlen($documentNumber) === 14 ? 'CNPJ' : 'CPF');
                
                $accountUuid = bin2hex(random_bytes(16));
                $sqlAccount = "INSERT INTO accounts (uuid, document_type, document_number, corporate_name, trade_name, status) 
                                VALUES (?, ?, ?, ?, ?, 'active')";
                $this->db->prepare($sqlAccount)->execute([
                    $accountUuid,
                    $documentType,
                    $documentNumber,
                    $data['company_corporate_name'] ?? $data['company_name'] ?? $data['name'] ?? '',
                    $data['company_name'] ?? $data['name'] ?? ''
                ]);
                $accountId = $this->db->lastInsertId();
                
                // Vincula o usuário à nova conta
                $this->db->prepare("UPDATE users SET account_id = ? WHERE id = ?")->execute([$accountId, $data['id']]);
            }

            // 2b. Atualiza user_profiles com documento (CPF para drivers)
            $documentNumber = preg_replace('/\D/', '', $data['document'] ?? '');
            if ($documentNumber && strlen($documentNumber) === 11) {
                $stmtCheckProfile = $this->db->prepare("SELECT id FROM user_profiles WHERE user_id = ?");
                $stmtCheckProfile->execute([$data['id']]);
                $profileExists = $stmtCheckProfile->fetchColumn();

                if ($profileExists) {
                    $this->db->prepare("UPDATE user_profiles SET cpf_cnpj = ?, document_type = 'CPF' WHERE user_id = ?")
                        ->execute([$documentNumber, $data['id']]);
                } else {
                    $this->db->prepare("INSERT INTO user_profiles (user_id, cpf_cnpj, document_type) VALUES (?, ?, 'CPF')")
                        ->execute([$data['id'], $documentNumber]);
                }
            }

            // 3. Atualiza USER_PROFILES (Dados Técnicos no JSON extended_attributes)
            // Primeiro buscamos o JSON atual para não sobrescrever outros campos (como redes sociais)
            $stmtProf = $this->db->prepare("SELECT extended_attributes FROM user_profiles WHERE user_id = ?");
            $stmtProf->execute([$data['id']]);
            $currentJson = $stmtProf->fetchColumn();
            $details = $currentJson ? json_decode($currentJson, true) : [];

            // Mesclamos os novos dados técnicos vindos do formulário
            $details['business_type'] = $data['business_type'] ?? ($details['business_type'] ?? '');
            $details['storage_capacity_m2'] = $data['storage_capacity_m2'] ?? ($details['storage_capacity_m2'] ?? 0);
            $details['has_dock'] = (int)($data['has_dock'] ?? ($details['has_dock'] ?? 0));
            $details['coverage_area'] = $data['coverage_area'] ?? ($details['coverage_area'] ?? '');
            
            // Também guardamos o trade_name no JSON para busca rápida se necessário
            $details['trade_name'] = $data['company_name'] ?? ($data['name'] ?? '');

            $sqlProfile = "UPDATE user_profiles SET 
                                name = ?,
                                extended_attributes = ?
                        WHERE user_id = ?";
            
            $this->db->prepare($sqlProfile)->execute([
                $data['company_name'] ?? $data['name'],
                json_encode($details),
                $data['id']
            ]);

            if (isset($data['role'])) {
                $this->syncUserModulesByRole((int)$data['id'], $data['role']);
            }

            $this->db->commit();
            return ["success" => true];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Erro ao atualizar detalhes do usuário: " . $e->getMessage());
            return ["success" => false, "error" => $e->getMessage()];
        }
    }

    public function getUserById($id) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function softDeleteUser($id) {
        // Marcamos como deletado mas mantemos os dados para auditoria
        return $this->db->prepare("UPDATE users SET deleted_at = NOW(), status = 'inactive' WHERE id = ? AND role != 'admin'")->execute([$id]);
    }

    public function deleteUserPermanently($id) {
        $this->db->prepare("DELETE FROM freights WHERE user_id = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM user_profiles WHERE user_id = ?")->execute([$id]);
        return $this->db->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'")->execute([$id]);
    }

    public function createFullUser(array $data, $currentAdminId) {
        try {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
            }

            // 1. Criar ou Vincular a ACCOUNT (Entidade Fiscal)
            // No Admin, podemos estar criando um usuário para uma empresa que já existe ou uma nova.
            $accountId = $data['account_id'] ?? null;

            if (!$accountId && ($data['user_type'] === 'COMPANY' || $data['role'] === 'company')) {
                $accountUuid = bin2hex(random_bytes(16));
                $sqlAccount = "INSERT INTO accounts (uuid, document_type, document_number, corporate_name, trade_name, status) 
                            VALUES (?, ?, ?, ?, ?, 'active')";
                
                $docType = (isset($data['cnpj']) && strlen(preg_replace('/\D/', '', $data['cnpj'])) > 11) ? 'CNPJ' : 'CPF';
                
                $stmtAcc = $this->db->prepare($sqlAccount);
                $stmtAcc->execute([
                    $accountUuid,
                    $docType,
                    preg_replace('/\D/', '', $data['cnpj'] ?? ($data['document'] ?? '')),
                    $data['corporate_name'] ?? ($data['company_name_fantasy'] ?? $data['name']),
                    $data['company_name_fantasy'] ?? $data['name']
                ]);
                $accountId = $this->db->lastInsertId();
            }

            // 2. Inserção na tabela principal: USERS
            $sqlUser = "INSERT INTO users (
                name, email, whatsapp, password, role, user_type, 
                city, state, status, plan_id, account_id, 
                parent_id, access_level, area, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $this->db->prepare($sqlUser);
            $stmt->execute([
                $data['name'],
                $data['email'],
                preg_replace('/\D/', '', $data['whatsapp'] ?? ''),
                password_hash($data['password'], PASSWORD_BCRYPT),
                $data['role'] ?? 'driver',
                strtoupper($data['user_type'] ?? 'COMPANY'), 
                $data['city'] ?? null,
                $data['state'] ?? null,
                $data['status'] ?? 'active',
                $data['plan_id'] ?? 1,
                $accountId,
                $data['parent_id'] ?? null,
                $data['access_level'] ?? null,
                $data['area'] ?? null
            ]);
            $userId = $this->db->lastInsertId();

            // 6. Popular user_modules baseado no cargo
            $this->populateUserModules((int)$userId, $data['role'] ?? 'driver');

            // 3. Inserção no perfil: USER_PROFILES (Incluindo dados que eram da companies no JSON)
            $slug = $this->generateSlug($data['name']) . '-' . $userId;
            
            // Preparar o JSON com dados técnicos da empresa/motorista
            $privateData = [
                'business_type' => $data['business_type'] ?? 'transportadora',
                'experience_years' => $data['experience_years'] ?? 0,
                'storage_capacity' => $data['storage_capacity_m2'] ?? 0,
                'created_by_admin' => $currentAdminId
            ];

            $sqlProfile = "INSERT INTO user_profiles (
                user_id, name, slug, profile_template, 
                vehicle_type, body_type, availability_status, extended_attributes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $this->db->prepare($sqlProfile)->execute([
                $userId,
                $data['company_name_fantasy'] ?? $data['name'],
                $slug,
                strtolower($data['user_type']), 
                $data['vehicle_type'] ?? null,
                $data['body_type'] ?? null,
                'available',
                json_encode($privateData)
            ]);

            // 4. Inicializa a Carteira: user_wallets
            $this->db->prepare("INSERT INTO user_wallets (user_id, balance_available) VALUES (?, 0)")
                    ->execute([$userId]);

            // 5. Auditoria
            $this->saveLog($currentAdminId, 'Admin', 'CREATE_USER', "Criou usuário completo {$data['email']} vinculado à conta #{$accountId}", $userId, 'USER');

            $this->db->commit();
            return ['success' => true, 'user_id' => $userId, 'account_id' => $accountId];

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("ERRO createFullUser: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function generateSlug($string) {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string), '-'));
    }

    public function createInternalUser(array $data, $adminId) {
        try {
            $this->db->beginTransaction();

            // 1. Inserção na 'users' com o cargo (role) e permissões
            $sql = "INSERT INTO users (name, email, password, role, user_type, permissions, status) 
                    VALUES (?, ?, ?, ?, ?, ?, 'active')";
            $this->db->prepare($sql)->execute([
                $data['name'],
                $data['email'],
                password_hash($data['password'], PASSWORD_BCRYPT),
                $data['role'],
                $data['user_type'],
                $data['permissions']
            ]);

            $userId = $this->db->lastInsertId();

            // 2. Perfil básico (user_profiles) - importante para o avatar do admin no chat
            $this->db->prepare("INSERT INTO user_profiles (user_id, slug, profile_template) VALUES (?, ?, ?)")
                    ->execute([$userId, 'staff-' . $userId, 'default']);

            // 3. Log de auditoria (Crucial para usuários internos)
            $this->saveLog($adminId, 'SYSTEM', 'CREATE_INTERNAL_USER', "Novo colaborador criado: {$data['role']}", $userId, 'USER');

            $this->db->commit();
            return ['success' => true, 'id' => $userId];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // FRETES & MATCHING

    public function listAllFreights() {
        $sql = "SELECT 
                    f.*, 
                    COALESCE(a.trade_name, p.name, u.name) as company_name 
                FROM freights f 
                LEFT JOIN users u ON f.user_id = u.id 
                LEFT JOIN accounts a ON u.account_id = a.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                WHERE f.deleted_at IS NULL
                ORDER BY f.created_at DESC";

        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getFreightById($id) {
        $stmt = $this->db->prepare("SELECT * FROM freights WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateFreightStatus($id, $status, $approveFeatured) {
        $sql = "UPDATE freights SET status = ?, 
                is_featured = CASE WHEN requested_featured = 1 AND ? = 1 THEN 1 ELSE is_featured END,
                requested_featured = 0 WHERE id = ?";
        return $this->db->prepare($sql)->execute([$status, $approveFeatured ? 1 : 0, $id]);
    }

    public function findCompatibleDrivers($vehicleType, $bodyType, $originState) {
        // Busca motoristas com perfil compatível e que possuam push_token para notificação
        $sql = "SELECT u.id, u.name, p.push_token 
                FROM users u 
                JOIN user_profiles p ON u.id = p.user_id 
                WHERE u.role = 'driver' 
                AND u.deleted_at IS NULL
                AND ((p.vehicle_type = ? AND p.body_type = ?) OR p.preferred_region = ?)
                LIMIT 100";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$vehicleType, $bodyType, $originState]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // CRM / PORTAL REQUESTS (Leads)

    public function savePortalRequest($data, $priority) {
        $sql = "INSERT INTO portal_requests (type, title, link, contact_info, status, description, priority) 
                VALUES (?, ?, ?, ?, 'pending', ?, ?)";
        return $this->db->prepare($sql)->execute([
            $data['type'] ?? 'suggestion',
            $data['title'] ?? null,
            $data['link'] ?? null,
            $data['contact_info'] ?? null,
            $data['description'] ?? null,
            $priority
        ]);
    }

    public function updateLeadInternal($id, $note, $status) {
        return $this->db->prepare("UPDATE portal_requests SET admin_notes = ?, status = ? WHERE id = ?")
                        ->execute([$note, $status, $id]);
    }

    public function getPortalRequests($filters) {
        $status = $filters['status'] ?? '%';
        $type = $filters['type'] ?? '%';
        $search = isset($filters['search']) ? "%{$filters['search']}%" : '%';

        $sql = "SELECT * FROM portal_requests 
                WHERE status LIKE ? AND type LIKE ? 
                AND (title LIKE ? OR contact_info LIKE ? OR description LIKE ?)
                ORDER BY priority DESC, created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$status, $type, $search, $search, $search]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ADS, SETTINGS & PLANS

    public function softDeleteAd($id) {
        return $this->db->prepare("UPDATE ads SET deleted_at = NOW(), status = 'rejected' WHERE id = ?")->execute([$id]);
    }

    public function toggleAdStatus($id) {
        return $this->db->prepare("UPDATE ads SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([$id]);
    }

    public function saveSettings($data) {
        $this->db->beginTransaction();
        try {
            foreach ($data as $key => $value) {
                if ($key === 'id') continue;
                $stmt = $this->db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) 
                                           ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function managePlans($data) {
        $action = $data['action'] ?? 'list';
        if ($action === 'save') {
            if (isset($data['id']) && $data['id'] > 0) {
                $sql = "UPDATE plans SET name=?, type=?, price=?, duration_days=?, description=? WHERE id=?";
                return $this->db->prepare($sql)->execute([$data['name'], $data['type'], $data['price'], $data['duration_days'], $data['description'], $data['id']]);
            } else {
                $sql = "INSERT INTO plans (name, type, price, duration_days, description, active) VALUES (?, ?, ?, ?, ?, 1)";
                return $this->db->prepare($sql)->execute([$data['name'], $data['type'], $data['price'], $data['duration_days'], $data['description']]);
            }
        }
        return $this->db->query("SELECT * FROM plans ORDER BY price ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
    // SUPORTE & HELP DESK (Tickets)

    /**
     * Lista chamados com filtros de status
     */
    public function getTickets($status = '%') {
        $sql = "SELECT t.*, u.name as user_name, u.role as user_role 
                FROM support_tickets t
                JOIN users u ON t.user_id = u.id
                WHERE t.status LIKE ?
                ORDER BY t.priority DESC, t.last_update DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$status]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTicketById($id) {
        $stmt = $this->db->prepare("SELECT * FROM support_tickets WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Adiciona uma mensagem ao ticket e atualiza o timestamp de atividade
     */
    public function addTicketMessage($ticketId, $senderId, $message, $isAdmin) {
        try {
            $this->db->beginTransaction();

            // 1. Insere a mensagem
            $sqlMsg = "INSERT INTO support_messages (ticket_id, sender_id, message, is_admin_reply) VALUES (?, ?, ?, ?)";
            $this->db->prepare($sqlMsg)->execute([$ticketId, $senderId, $message, $isAdmin ? 1 : 0]);

            // 2. Atualiza o status do ticket (Se admin responde, vira IN_PROGRESS ou mantem status)
            $newStatus = $isAdmin ? 'IN_PROGRESS' : 'OPEN';
            $sqlTicket = "UPDATE support_tickets SET status = ?, last_update = NOW() WHERE id = ?";
            $this->db->prepare($sqlTicket)->execute([$newStatus, $ticketId]);

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    // NOTAS INTERNAS (CRM DE AUDITORIA)

    /**
     * Salva uma nota sobre o usuário que apenas Admin/Manager podem ver
     */
    public function saveInternalNote($targetUserId, $adminId, $note) {
        $sql = "INSERT INTO user_internal_notes (user_id, admin_id, note) VALUES (?, ?, ?)";
        return $this->db->prepare($sql)->execute([$targetUserId, $adminId, $note]);
    }

    public function getUserNotes($userId) {
        $sql = "SELECT n.*, u.name as admin_name 
                FROM user_internal_notes n
                JOIN users u ON n.admin_id = u.id
                WHERE n.user_id = ?
                ORDER BY n.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
    * Busca fretes com status 'PENDING' para aprovação no dashboard
    */
    public function getPendingFreights() {
       $sql = "SELECT 
                    f.id, 
                    f.origin_city, 
                    f.origin_state,
                    f.dest_city,      
                    f.dest_state,     
                    f.product,
                    f.weight,         
                    f.price,          
                    f.created_at,
                    f.user_id,        
                    u.name as company_name
                FROM freights f
                JOIN users u ON f.user_id = u.id
                WHERE f.status = 'PENDING'
                WHERE f.deleted_at IS NULL
                ORDER BY f.created_at DESC
                LIMIT 10";
                
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("Erro ao buscar fretes pendentes: " . $e->getMessage());
            return [];
        }
    }

    // --- QUERIES DE DOCUMENTOS ---

    public function getPendingDocuments() {
        $sql = "SELECT d.*, u.name as user_name, u.email as user_email 
                FROM user_documents d
                JOIN users u ON d.entity_id = u.id
                WHERE d.status = 'PENDING'
                ORDER BY d.created_at ASC";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDocumentById($id) {
        $sql = "SELECT * FROM user_documents WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateDocumentStatus($id, $status, $reason = '') {
        $sql = "UPDATE user_documents SET status = ?, rejection_reason = ?, reviewed_at = NOW() WHERE id = ?";
        return $this->db->prepare($sql)->execute([$status, $reason, $id]);
    }

    public function getUserFullDetails($id) {
        // 1. Busca os dados principais (Usuário + Dados de Conta/Empresa + Saldo + Perfil)
        $sql = "SELECT 
                    u.id, u.name as user_name, u.email, u.whatsapp, u.role, u.status, u.created_at,
                    u.user_type, u.plan_id, u.account_id, u.is_verified, u.access_level, u.parent_id,
                    a.trade_name as company_name, 
                    a.corporate_name as company_corporate_name,
                    a.document_number as company_document,
                    a.document_type as company_document_type,
                    p.extended_attributes,
                    p.avatar_url,
                    p.cpf_cnpj as document,
                    p.document_type as document_type,
                    COALESCE(uw.balance_available, 0) as wallet_balance,
                    parent.name as parent_name
                FROM users u
                LEFT JOIN accounts a ON u.account_id = a.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                LEFT JOIN user_wallets uw ON u.id = uw.user_id
                LEFT JOIN users parent ON u.parent_id = parent.id
                WHERE u.id = ?"; 
                    
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) return null;

        // Decodifica o JSON do perfil para extrair dados operacionais que eram da tabela companie
        $details = $user['extended_attributes'] ? json_decode($user['extended_attributes'], true) : [];
        $user['business_type'] = $details['business_type'] ?? 'N/A';
        $user['storage_capacity_m2'] = $details['storage_capacity_m2'] ?? 0;
        $user['has_dock'] = $details['has_dock'] ?? 0;
        $user['coverage_area'] = $details['coverage_area'] ?? '';
        
        // Removemos o campo bruto para limpar o retorno
        unset($user['extended_attributes']);

        // 2. Busca os documentos associados
        // Agora vinculamos ao ACCOUNT_ID para que documentos da empresa apareçam para todos os membros
        $accountId = $user['account_id'];

        $sqlDocs = "SELECT id, document_type, file_path, status, created_at, entity_type 
                    FROM user_documents 
                    WHERE (entity_id = ? AND entity_type = 'USER')
                    OR (entity_id = ? AND entity_type = 'ACCOUNT') -- Mudado de COMPANY para ACCOUNT
                    ORDER BY created_at DESC";
        
        $stmtDocs = $this->db->prepare($sqlDocs);
        $stmtDocs->execute([$id, $accountId]); 
        $user['documents'] = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

        // 3. Busca as Notas Internas
        // O contexto de notas de empresa agora usa o account_id
        $sqlNotes = "SELECT n.*, admin.name as admin_name 
                    FROM user_internal_notes n
                    JOIN users admin ON n.admin_id = admin.id
                    WHERE n.user_id = ? 
                        OR (n.account_id = ? AND n.account_id IS NOT NULL) -- Contexto de conta compartilhada
                    ORDER BY n.created_at DESC";

        $stmtNotes = $this->db->prepare($sqlNotes);
        $stmtNotes->execute([$id, $accountId]);
        $user['internal_notes'] = $stmtNotes->fetchAll(PDO::FETCH_ASSOC);

        // 4. Busca os Logs de Auditoria
        $sqlLogs = "SELECT id, action_type, description, user_name, ip_address, created_at 
                    FROM logs_auditoria 
                    WHERE target_id = ? AND target_type = 'USER'
                    ORDER BY created_at DESC 
                    LIMIT 15";
        $stmtLogs = $this->db->prepare($sqlLogs);
        $stmtLogs->execute([$id]);
        $user['audit_logs'] = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

        return $user;
    }

    public function addInternalNote($data) {
        $sql = "INSERT INTO user_internal_notes (user_id, admin_id, note, context, company_id) 
                VALUES (?, ?, ?, ?, ?)";
        
        // Se o context for COMPANY, buscamos o company_id do usuário alvo
        $companyId = null;
        if ($data['context'] === 'COMPANY') {
            $stmt = $this->db->prepare("SELECT company_id FROM users WHERE id = ?");
            $stmt->execute([$data['user_id']]);
            $companyId = $stmt->fetchColumn();
        }

        return $this->db->prepare($sql)->execute([
            $data['user_id'],
            $data['admin_id'],
            $data['note'],
            $data['context'] ?? 'USER',
            $companyId
        ]);
    }

    public function getCompanyMembers($companyId) {
        $sql = "SELECT id, name as user_name, email, role, status, whatsapp, created_at, access_level, parent_id
                FROM users 
                WHERE account_id = ? 
                ORDER BY parent_id ASC, name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function emailExists($email) {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch() !== false;
    }

    public function provisionUser(array $data, int $currentAdminId) {
        try {
            $this->db->beginTransaction();

            // 1. Camada de Acesso (users)
            $sqlUser = "INSERT INTO users (name, email, password, role, user_type, permissions, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $this->db->prepare($sqlUser);
            $stmt->execute([
                $data['name'], 
                $data['email'], 
                password_hash($data['password'], PASSWORD_DEFAULT),
                $data['role'], 
                $data['user_type'], 
                json_encode($data['permissions']), // Guardando a matriz de poder
                $data['status'] ?? 'active'
            ]);
            $userId = $this->db->lastInsertId();

            // 2. Camada de Identidade (user_profiles)
            $sqlProfile = "INSERT INTO user_profiles (user_id, cpf_cnpj, phone, city, state) VALUES (?, ?, ?, ?, ?)";
            $this->db->prepare($sqlProfile)->execute([
                $userId, $this->onlyNumbers($data['cpf_cnpj']), $this->onlyNumbers($data['phone']), 
                $data['city'], $data['state']
            ]);

            // 3. Camada Financeira (user_wallets) - Essencial para o sucesso do negócio
            $this->db->prepare("INSERT INTO user_wallets (user_id, balance) VALUES (?, 0)")->execute([$userId]);

            // 4. Camada de Auditoria (user_internal_notes) - Padrão de conformidade (Compliance)
            $this->db->prepare("INSERT INTO user_internal_notes (user_id, admin_id, note) VALUES (?, ?, ?)")
                    ->execute([$userId, $currentAdminId, "Entidade provisionada via Master Admin"]);

            $this->db->commit();
            return ['success' => true, 'id' => $userId];
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("Erro no Provisionamento: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function onlyNumbers($str) {
        return preg_replace('/\D/', '', $str);
    }

    private function populateUserModules(int $userId, string $roleSlug): void {
        $stmt = $this->db->prepare("
            SELECT module_key FROM modules 
            WHERE JSON_CONTAINS(default_for, :role)
            OR is_required = 1
        ");
        $stmt->execute([':role' => '"' . $roleSlug . '"']);
        $modules = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($modules as $moduleKey) {
            $this->db->prepare("
                INSERT IGNORE INTO user_modules (user_id, module_key, status, activated_at)
                VALUES (:user_id, :module_key, 'active', NOW())
            ")->execute([
                ':user_id' => $userId,
                ':module_key' => $moduleKey
            ]);
        }
    }

private function syncUserModulesByRole(int $userId, string $newRole): void {
        $this->db->prepare("DELETE FROM user_modules WHERE user_id = ?")->execute([$userId]);
        
        $stmt = $this->db->prepare("
            SELECT module_key FROM modules 
            WHERE JSON_CONTAINS(default_for, :role)
            OR is_required = 1
        ");
        $stmt->execute([':role' => '"' . $newRole . '"']);
        $modules = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($modules as $moduleKey) {
            $this->db->prepare("
                INSERT INTO user_modules (user_id, module_key, status, activated_at)
                VALUES (:user_id, :module_key, 'active', NOW())
            ")->execute([
                ':user_id' => $userId,
                ':module_key' => $moduleKey
            ]);
        }
    }

    public function syncUserModulesByRolePermissions(int $roleId, array $newPermissionIds): array {
        // Mapeamento de permissões para módulos
        $permissionToModule = [
            // Freight
            'freight.view' => 'freights',
            'freight.create' => 'freights',
            'freight.edit' => 'freights',
            'freight.delete' => 'freights',
            
            // Marketplace
            'marketplace.view' => 'marketplace',
            'marketplace.create' => 'marketplace',
            'marketplace.edit' => 'marketplace',
            'marketplace.delete' => 'marketplace',
            
            // Cotações (doubled - old and new naming)
            'cotacoes.view' => 'quotes',
            'cotacoes.edit' => 'quotes',
            'quotes.view' => 'quotes',
            'quotes.create' => 'quotes',
            'quotes.manage' => 'quotes',
            'quotes.respond' => 'quotes',
            
            // Publicidade
            'ads.view' => 'advertiser',
            'ads.create' => 'advertiser',
            'ads.edit' => 'advertiser',
            'ads.manage' => 'advertiser',
            'ads.delete' => 'advertiser',
            
            // Financeiro
            'financeiro.view' => 'financial',
            'financeiro.manage' => 'financial',
            'wallet.view' => 'financial',
            'wallet.manage' => 'financial',
            
            // Grupos
            'grupos.view' => 'groups',
            'grupos.create' => 'groups',
            'grupos.edit' => 'groups',
            'grupos.delete' => 'groups',
            
            // Suporte
            'support.view' => 'support',
            'support.create' => 'support',
            'support.respond' => 'support',
            'support.manage' => 'support',
            
            // Chat
            'chat.view' => 'chat',
            'chat.send' => 'chat',
            'chat.manage' => 'chat',
            'chat.delete' => 'chat',
            
            // Planos
            'planos.view' => 'plans',
            'planos.manage' => 'plans',
            
            // Artigos
            'articles.view' => 'articles',
            'articles.create' => 'articles',
            'articles.edit' => 'articles',
            'articles.delete' => 'articles',
            'articles.approve' => 'articles',
            'articles.manage' => 'articles',
        ];
        
        // Buscar role atual
        $stmt = $this->db->prepare("SELECT slug FROM roles WHERE id = ?");
        $stmt->execute([$roleId]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$role) {
            return ['success' => false, 'message' => 'Cargo não encontrado'];
        }
        
        $roleSlug = $role['slug'];
        
        // Buscar permissões ANTIGAS do cargo (para identificar o que foi removido)
        $stmt = $this->db->prepare("
            SELECT p.slug 
            FROM role_permissions rp
            JOIN permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = ?
        ");
        $stmt->execute([$roleId]);
        $oldPermissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Buscar novas permissões pelo ID
        $placeholders = implode(',', array_fill(0, count($newPermissionIds), '?'));
        $stmt = $this->db->prepare("SELECT slug FROM permissions WHERE id IN ($placeholders)");
        $stmt->execute($newPermissionIds);
        $newPermissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Identificar módulos a adicionar e remover
        $oldModules = [];
        $newModules = [];
        
        foreach ($oldPermissions as $perm) {
            if (isset($permissionToModule[$perm])) {
                $oldModules[] = $permissionToModule[$perm];
            }
        }
        
        foreach ($newPermissions as $perm) {
            if (isset($permissionToModule[$perm])) {
                $newModules[] = $permissionToModule[$perm];
            }
        }
        
        $modulesToAdd = array_diff($newModules, $oldModules);
        $modulesToRemove = array_diff($oldModules, $newModules);
        $modulesToAdd = array_unique($modulesToAdd);
        $modulesToRemove = array_unique($modulesToRemove);
        
        // Buscar usuários com esse cargo
        $stmt = $this->db->prepare("SELECT id FROM users WHERE role = ? AND deleted_at IS NULL");
        $stmt->execute([$roleSlug]);
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $usersUpdated = 0;
        
        foreach ($users as $userId) {
            $changed = false;
            
            // Adicionar módulos das novas permissões
            foreach ($modulesToAdd as $moduleKey) {
                $checkStmt = $this->db->prepare("
                    SELECT 1 FROM user_modules 
                    WHERE user_id = ? AND module_key = ?
                ");
                $checkStmt->execute([$userId, $moduleKey]);
                
                if (!$checkStmt->fetch()) {
                    $this->db->prepare("
                        INSERT INTO user_modules (user_id, module_key, status, activated_at)
                        VALUES (?, ?, 'active', NOW())
                    ")->execute([$userId, $moduleKey]);
                    $changed = true;
                }
            }
            
            // Remover módulos das permissões removidas
            foreach ($modulesToRemove as $moduleKey) {
                $this->db->prepare("
                    DELETE FROM user_modules 
                    WHERE user_id = ? AND module_key = ?
                ")->execute([$userId, $moduleKey]);
                $changed = true;
            }
            
            if ($changed) {
                $usersUpdated++;
            }
        }
        
        return [
            'success' => true,
            'users_updated' => $usersUpdated,
            'modules_added' => array_values($modulesToAdd),
            'modules_removed' => array_values($modulesToRemove)
        ];
    }

    public function searchUsers(string $query, int $limit = 10): array {
        $stmt = $this->db->prepare("
            SELECT u.id, u.name, u.email, u.role, u.status, a.corporate_name, a.trade_name
            FROM users u
            LEFT JOIN accounts a ON u.account_id = a.id
            WHERE (u.name LIKE :query OR u.email LIKE :query)
            ORDER BY u.name ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':query', "%$query%", PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTeamUsers(): array {
        $stmt = $this->db->query("
            SELECT id, name, email, role, status
            FROM users
            WHERE role NOT IN ('driver', 'company')
            AND deleted_at IS NULL
            ORDER BY name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}