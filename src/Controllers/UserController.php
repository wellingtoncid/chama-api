<?php
namespace App\Controllers;

use App\Core\Response;
use App\Core\Auth;
use App\Repositories\UserRepository;
use App\Controllers\NotificationController;
use PDO;

class UserController {
    private $userRepo;
    private $db;

    public function __construct($db) {
        $this->db = $db;
        $this->userRepo = new UserRepository($db);
    }

    /**
     * Rota: GET /api/get-my-profile
     */
    public function getProfile($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        try {
            $user = $this->userRepo->getProfileData($loggedUser['id']);

            if (!$user) {
                return Response::json(["success" => false, "message" => "Perfil não encontrado"], 404);
            }

            // Score de completude (única lógica que fica no Controller)
            $points = 0;
            foreach (['name', 'whatsapp', 'avatar_url', 'city', 'bio'] as $field) {
                if (!empty($user[$field])) $points += 20;
            }
            $user['completion_score'] = $points;

            // Limpeza final de segurança
            unset($user['password'], $user['reset_token']);

            return Response::json(["success" => true, "user" => $user]);

        } catch (\Throwable $e) {
            // Log detalhado para você ver no terminal/arquivo de log o que realmente quebrou
            error_log("ERRO FATAL getProfile: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine());
            return Response::json([
                "success" => false, 
                "message" => "Erro interno no servidor",
                "error" => $e->getMessage() // Opcional: remova em produção
            ], 500);
        }
    }
    
    /**
     * Rota: GET /api/get-public-profile
     */
    public function getUserSummary($data, $loggedUser) {
        $id = $data['id'] ?? $data['user_id'] ?? 0;
        if (!$id) return Response::json(["success" => false, "message" => "ID inválido"], 400);

        $profile = $this->userRepo->getProfileData($id);
        if (!$profile) return Response::json(["success" => false, "message" => "Perfil não encontrado"], 404);

        $stats = $this->userRepo->getReviewStats($id);
        $profile['rating_average'] = round($stats['media'] ?? 0, 1);
        $profile['total_reviews'] = $stats['total'] ?? 0;

        $sensitiveData = ['password', 'reset_token', 'email', 'deleted_at', 'status', 'company_id'];
        foreach ($sensitiveData as $key) unset($profile[$key]);
        
        $profile['member_since'] = isset($profile['created_at']) ? date('m/Y', strtotime($profile['created_at'])) : 'Recente';

        return Response::json(["success" => true, "data" => $profile]);
    }

    /**
     * Rota: POST /api/update-profile
     */
    public function updateProfile($data, $loggedUser) {
        if (!$loggedUser) return Response::json(["success" => false, "message" => "Não autorizado"], 401);

        try {
            $userId = $loggedUser['id'];
            $userType = $loggedUser['user_type'] ?? 'DRIVER';

            // 1. Processamento de Imagens (Avatar e Capa)
            $files = ['avatar_file' => 'avatar_url', 'cover_file' => 'cover_url'];
            foreach ($files as $fileKey => $dataKey) {
                if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                    $folder = ($fileKey === 'avatar_file') ? 'avatars' : 'covers';
                    $path = $this->handleFileUpload($_FILES[$fileKey], $folder, $userId);
                    if ($path) $data[$dataKey] = $path;
                }
            }

            // 2. Processamento de Documento de Verificação (PDF/Foto)
            if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
                $docPath = $this->handleFileUpload($_FILES['document_file'], 'documents', $userId);
                if ($docPath) {
                    $this->userRepo->saveUserDocument($userId, [
                        'file_path' => $docPath,
                        'document_type' => $data['doc_type_name'] ?? 'Identificação',
                        'status' => 'pending'
                    ]);
                }
            }

            // 3. Validação do CPF/CNPJ
            $fiscalDoc = preg_replace('/\D/', '', $data['document_number'] ?? $data['document'] ?? $data['cnpj'] ?? '');
            if ($fiscalDoc && !$this->isValidDocument($fiscalDoc)) {
                return Response::json(["success" => false, "message" => "Documento inválido"], 400);
            }
            $data['clean_document'] = $fiscalDoc ?: null;

            // 4. Mesclar extended_attributes (JSON) nos dados para o updateFullProfile
            if (!empty($data['extended_attributes'])) {
                $extras = is_string($data['extended_attributes'])
                    ? json_decode($data['extended_attributes'], true)
                    : (array)$data['extended_attributes'];
                if (is_array($extras)) {
                    $data = array_merge($data, $extras);
                }
            }

            // 5. Mapear trade_name → name para o repository
            if (!empty($data['trade_name']) && empty($data['name'])) {
                $data['name'] = $data['trade_name'];
            }

            // 6. Salvar no banco (users, accounts, user_profiles)
            $this->userRepo->updateFullProfile($userId, $data);

            // 7. Retornar perfil atualizado
            $updatedUser = $this->userRepo->getProfileData($userId);

            return Response::json([
                "success" => true,
                "message" => "Perfil atualizado!",
                "user" => $updatedUser
            ]);

        } catch (\Exception $e) {
            return Response::json(["success" => false, "message" => $e->getMessage()], 500);
        }
    }

    /**
     * Função auxiliar privada para processar uploads de arquivos
     */
    private function handleFileUpload($file, $folder, $userId) {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        
        // Lista de mimes permitidos (Imagens e PDFs para documentos)
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        if (!in_array($mime, $allowed)) return null;

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = $folder . "_" . $userId . "_" . time() . "." . $ext;
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/" . $folder . "/";

        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
            return "/uploads/" . $folder . "/" . $fileName;
        }
        return null;
    }

    public function getBySlug($db, $loggedUser, $data) {
        $slug = $data['slug'] ?? '';
        
        if (empty($slug)) {
            return Response::json(["success" => false, "message" => "Slug não fornecido"], 400);
        }

        // Busca o perfil completo (Join entre users e user_profiles)
        $profile = $this->userRepo->getPublicProfileBySlug($slug);

        if (!$profile) {
            return Response::json(["success" => false, "message" => "Perfil não encontrado"], 404);
        }

        // Normalização de dados públicos
        $profile['is_verified'] = (int)($profile['is_verified'] ?? 0) === 1;
        
        // Formata o objeto de rating para o padrão do Front
        $profile['rating'] = [
            'average' => round((float)($profile['rating_avg'] ?? 0), 1),
            'count'   => (int)($profile['rating_count'] ?? 0)
        ];

        // Segurança: Remove dados que não devem ser públicos
        unset($profile['email'], $profile['document'], $profile['balance']);

        return Response::json([
            "success" => true, 
            "data" => $profile
        ]);
    }

    public function checkSlug($db, $loggedUser, $data) {
        // 1. Limpeza do slug ( slugs não devem ter espaços ou caracteres especiais)
        $slug = $data['slug'] ?? '';
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '', str_replace(' ', '-', $slug)));

        if (empty($slug) || strlen($slug) < 3) {
            return Response::json([
                "success" => true, 
                "available" => false, 
                "message" => "Slug muito curto ou inválido"
            ]);
        }

        // 2. Verifica disponibilidade no Repository
        // Passamos o ID do usuário logado para que ele possa usar o próprio slug atual
        $currentUserId = $loggedUser['id'] ?? 0;
        $available = $this->userRepo->isSlugAvailable($slug, $currentUserId);

        return Response::json([
            "success" => true, 
            "available" => $available,
            "slug_suggested" => $slug // Retorna o slug formatado para o front-end
        ]);
    }

    public function deleteAccount($db, $loggedUser, $data) {
        // 1. Verificação de autenticação
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Não autorizado"], 401);
        }

        $userId = $loggedUser['id'];

        try {
            // 2. Executa o Soft Delete (Mantém os dados mas oculta do sistema)
            $success = $this->userRepo->softDelete($userId);

            if ($success) {
                // 3. Aqui você pode adicionar lógica para disparar um e-mail de despedida
                // ou logar o motivo da exclusão se vier no $data['reason']

                return Response::json([
                    "success" => true, 
                    "message" => "Sua conta foi desativada com sucesso. Sentiremos sua falta!"
                ]);
            }

            return Response::json([
                "success" => false, 
                "message" => "Não foi possível desativar a conta no momento."
            ], 500);

        } catch (\Exception $e) {
            error_log("Erro ao deletar conta ID {$userId}: " . $e->getMessage());
            return Response::json([
                "success" => false, 
                "message" => "Erro interno ao processar a exclusão."
            ], 500);
        }
    }

    // Auxiliar para mensagens de erro do PHP
    private function getUploadErrorMessage($errCode) {
        return match($errCode) {
            UPLOAD_ERR_INI_SIZE   => "O arquivo excede o limite do servidor (php.ini).",
            UPLOAD_ERR_FORM_SIZE  => "O arquivo excede o limite do formulário.",
            UPLOAD_ERR_PARTIAL    => "O upload foi feito apenas parcialmente.",
            UPLOAD_ERR_NO_FILE    => "Nenhum arquivo foi enviado.",
            default               => "Erro desconhecido no upload.",
        };
    }

    private function isValidDocument($doc) {
        $doc = preg_replace('/\D/', '', $doc);
        return match (strlen($doc)) {
            11 => $this->validateCPF($doc),
            14 => $this->validateCNPJ($doc),
            default => false,
        };
    }

    /**
     * Validação de CPF (Algoritmo oficial)
     */
    private function validateCPF($cpf) {
        if (preg_match('/(\d)\1{10}/', $cpf)) return false;
        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) $d += $cpf[$c] * (($t + 1) - $c);
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d) return false;
        }
        return true;
    }

    /**
     * Validação de CNPJ (Algoritmo oficial)
     */
    private function validateCNPJ($cnpj) {
        if (preg_match('/(\d)\1{13}/', $cnpj)) return false;
        for ($i = 0, $j = 5, $soma = 0; $i < 12; $i++) {
            $soma += $cnpj[$i] * $j;
            $j = ($j == 2) ? 9 : $j - 1;
        }
        $resto = $soma % 11;
        if ($cnpj[12] != ($resto < 2 ? 0 : 11 - $resto)) return false;
        for ($i = 0, $j = 6, $soma = 0; $i < 13; $i++) {
            $soma += $cnpj[$i] * $j;
            $j = ($j == 2) ? 9 : $j - 1;
        }
        $resto = $soma % 11;
        return $cnpj[13] == ($resto < 2 ? 0 : 11 - $resto);
    }

    /**
     * Rota: GET /api/company/summary
     */
    public function getCompanySummary($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        try {
            $userId = $loggedUser['id'];
            
            $profile = $this->userRepo->getProfileData($userId);
            
            if (!$profile) {
                return Response::json(["success" => false, "message" => "Perfil não encontrado"], 404);
            }

            $summary = [
                'id' => $profile['id'],
                'name' => $profile['name'],
                'company_name' => $profile['company_name'] ?? null,
                'trade_name' => $profile['trade_name'] ?? null,
                'corporate_name' => $profile['corporate_name'] ?? null,
                'document' => $profile['document'] ?? null,
                'avatar_url' => $profile['avatar_url'] ?? null,
                'cover_url' => $profile['cover_url'] ?? null,
                'slug' => $profile['slug'] ?? null,
                'bio' => $profile['bio'] ?? null,
                'city' => $profile['city'] ?? null,
                'state' => $profile['state'] ?? null,
                'rating' => (float)($profile['rating_avg'] ?? 5.0),
                'rating_count' => (int)($profile['rating_count'] ?? 0),
                'verification_status' => $profile['verification_status'] ?? 'none',
                'status' => $profile['status'] ?? 'pending',
                'user_type' => $profile['user_type'] ?? null,
                'is_verified' => (int)($profile['is_verified'] ?? 0) === 1,
                'created_at' => $profile['created_at'] ?? null,
                'extended_attributes' => $profile['details'] ?? []
            ];

            return Response::json(["success" => true, "data" => $summary]);

        } catch (\Throwable $e) {
            error_log("ERRO getCompanySummary: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro interno"], 500);
        }
    }

    /**
     * Rota: GET /api/user/modules - Lista módulos do usuário
     */
    public function getUserModules($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        try {
            $userId = $loggedUser['id'];
            $userType = strtoupper($loggedUser['user_type'] ?? 'DRIVER');
            $role = strtoupper($loggedUser['role'] ?? '');
            
            // Verifica se é empresa pelo role
            $isCompany = ($role === 'COMPANY');
            
            $availableModules = [];
            $allowedModules = [];

            if ($role === 'ADMIN') {
                $availableModules = [
                    ['key' => 'freights', 'name' => 'Fretes', 'description' => 'Publicação e gestão de cargas'],
                    ['key' => 'marketplace', 'name' => 'Marketplace', 'description' => 'Compra e venda de itens'],
                    ['key' => 'quotes', 'name' => 'Cotações', 'description' => 'Solicitar e responder cotações'],
                    ['key' => 'advertiser', 'name' => 'Publicidade', 'description' => 'Anúncios publicitários'],
                    ['key' => 'chat', 'name' => 'Chat', 'description' => 'Mensagens e conversas'],
                    ['key' => 'financial', 'name' => 'Financeiro', 'description' => 'Transações e relatórios'],
                    ['key' => 'groups', 'name' => 'Grupos', 'description' => 'Grupos WhatsApp'],
                    ['key' => 'plans', 'name' => 'Planos', 'description' => 'Planos de assinatura'],
                    ['key' => 'support', 'name' => 'Suporte', 'description' => 'Tickets de atendimento']
                ];
                $allowedModules = ['freights', 'marketplace', 'quotes', 'advertiser', 'chat', 'financial', 'groups', 'plans', 'support'];
            } elseif ($isCompany) {
                $availableModules = [
                    ['key' => 'freights', 'name' => 'Fretes', 'description' => 'Publicação e gestão de cargas'],
                    ['key' => 'marketplace', 'name' => 'Marketplace', 'description' => 'Compra e venda de itens'],
                    ['key' => 'quotes', 'name' => 'Cotações', 'description' => 'Solicitar e responder cotações'],
                    ['key' => 'advertiser', 'name' => 'Publicidade', 'description' => 'Anúncios publicitários'],
                    ['key' => 'chat', 'name' => 'Chat', 'description' => 'Mensagens e conversas'],
                    ['key' => 'financial', 'name' => 'Financeiro', 'description' => 'Transações e relatórios'],
                    ['key' => 'groups', 'name' => 'Grupos', 'description' => 'Grupos WhatsApp'],
                    ['key' => 'plans', 'name' => 'Planos', 'description' => 'Planos de assinatura'],
                    ['key' => 'support', 'name' => 'Suporte', 'description' => 'Tickets de atendimento']
                ];
                $allowedModules = ['freights', 'marketplace', 'quotes', 'advertiser', 'chat', 'financial', 'groups', 'plans', 'support'];
            } else {
                $availableModules = [
                    ['key' => 'driver', 'name' => 'Driver Pro', 'description' => 'Recursos premium para motoristas'],
                    ['key' => 'freights', 'name' => 'Radar de Cargas', 'description' => 'Encontre cargas compatíveis'],
                    ['key' => 'marketplace', 'name' => 'Marketplace', 'description' => 'Compra e venda de itens'],
                    ['key' => 'chat', 'name' => 'Chat', 'description' => 'Mensagens e conversas'],
                    ['key' => 'groups', 'name' => 'Grupos', 'description' => 'Grupos WhatsApp'],
                    ['key' => 'plans', 'name' => 'Planos', 'description' => 'Planos de assinatura'],
                    ['key' => 'support', 'name' => 'Suporte', 'description' => 'Tickets de atendimento']
                ];
                $allowedModules = ['driver', 'freights', 'marketplace', 'chat', 'groups', 'plans', 'support'];
            }

            $stmt = $this->db->prepare("SELECT module_key, status, activated_at, expires_at FROM user_modules WHERE user_id = :user_id");
            $stmt->execute([':user_id' => $userId]);
            $userModules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $modulesMap = [];
            foreach ($userModules as $m) {
                $modulesMap[$m['module_key']] = $m;
            }

            $modules = [];
            foreach ($availableModules as $mod) {
                $userMod = $modulesMap[$mod['key']] ?? null;
                $modules[] = [
                    'key' => $mod['key'],
                    'name' => $mod['name'],
                    'description' => $mod['description'],
                    'status' => $userMod ? $userMod['status'] : 'inactive',
                    'is_active' => $userMod && $userMod['status'] === 'active',
                    'activated_at' => $userMod['activated_at'] ?? null,
                    'expires_at' => $userMod['expires_at'] ?? null,
                    'is_allowed' => in_array($mod['key'], $allowedModules)
                ];
            }

            // Para empresas, todos os módulos permitidos são ativos por padrão
            $isCompanyDefault = $isCompany;

            $modules = [];
            foreach ($availableModules as $mod) {
                $userMod = $modulesMap[$mod['key']] ?? null;
                $isAllowed = in_array($mod['key'], $allowedModules);
                
                // Se não há registro do usuário, usa o padrão
                // Empresas: módulos permitidos são ativos por padrão
                // Motoristas: fretes é ativo, demais depende do registro
                $isActive = false;
                if ($userMod) {
                    $isActive = $userMod['status'] === 'active';
                } elseif ($isAllowed) {
                    // Para empresas, todos os módulos permitidos são ativos
                    // Para motoristas, fretes é obrigatório e ativo
                    $isActive = $isCompanyDefault || $mod['key'] === 'fretes';
                }

                $modules[] = [
                    'key' => $mod['key'],
                    'name' => $mod['name'],
                    'description' => $mod['description'],
                    'status' => $isActive ? 'active' : 'inactive',
                    'is_active' => $isActive,
                    'activated_at' => $userMod['activated_at'] ?? ($isActive ? date('Y-m-d H:i:s') : null),
                    'expires_at' => $userMod['expires_at'] ?? null,
                    'is_allowed' => $isAllowed
                ];
            }

            return Response::json([
                "success" => true, 
                "data" => [
                    'modules' => $modules,
                    'user_type' => $userType,
                    'role' => $role
                ]
            ]);

        } catch (\Throwable $e) {
            error_log("ERRO getUserModules: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro interno"], 500);
        }
    }

    /**
     * Rota: POST /api/user/modules - Ativa/desativa módulo
     */
    public function toggleModule($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $moduleKey = $data['module_key'] ?? '';
        $action = $data['action'] ?? 'activate';
        
        $role = strtoupper($loggedUser['role'] ?? '');
        $userType = strtoupper($loggedUser['user_type'] ?? 'DRIVER');
        $isCompany = ($role === 'COMPANY');
        
        // Define módulos permitidos por tipo de usuário (chaves em inglês)
        if ($role === 'ADMIN') {
            $allowedModules = ['freights', 'marketplace', 'quotes', 'advertiser', 'chat', 'financial', 'groups', 'plans', 'support'];
        } elseif ($isCompany) {
            // Empresas podem ativar: freights, marketplace, quotes, advertiser, chat, groups
            $allowedModules = ['freights', 'marketplace', 'quotes', 'advertiser', 'chat', 'groups'];
        } else {
            // Motoristas: freights (leitura), marketplace
            $allowedModules = ['freights', 'marketplace'];
        }
        
        if (!in_array($moduleKey, $allowedModules)) {
            return Response::json(["success" => false, "message" => "Módulo não disponível para seu perfil"], 400);
        }

        try {
            $userId = $loggedUser['id'];

            if ($action === 'activate') {
                $stmt = $this->db->prepare("
                    INSERT INTO user_modules (user_id, module_key, status, activated_at) 
                    VALUES (:user_id, :module_key, 'active', NOW())
                    ON DUPLICATE KEY UPDATE status = 'active', activated_at = NOW()
                ");
                $stmt->execute([':user_id' => $userId, ':module_key' => $moduleKey]);
                $message = "Módulo ativado com sucesso!";
            } else {
                $stmt = $this->db->prepare("UPDATE user_modules SET status = 'inactive' WHERE user_id = :user_id AND module_key = :module_key");
                $stmt->execute([':user_id' => $userId, ':module_key' => $moduleKey]);
                $message = "Módulo desativado!";
            }

            return Response::json(["success" => true, "message" => $message]);

        } catch (\Throwable $e) {
            error_log("ERRO toggleModule: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao processar"], 500);
        }
    }

    /**
     * Rota: GET /api/pricing/rules - Lista preços configuráveis (público)
     */
    public function getPricingRules($data, $loggedUser) {
        try {
            $stmt = $this->db->query("
                SELECT * FROM pricing_rules 
                WHERE is_active = 1 
                ORDER BY module_key, feature_key
            ");
            $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return Response::json(["success" => true, "data" => $rules]);
        } catch (\Throwable $e) {
            error_log("ERRO getPricingRules: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro interno"], 500);
        }
    }

    /**
     * Rota: GET /api/site-settings - Retorna configurações do site
     */
    public function getSiteSettings($data, $loggedUser) {
        try {
            $keys = $data['keys'] ?? '';
            
            if ($keys) {
                $keyList = explode(',', $keys);
                $placeholders = implode(',', array_fill(0, count($keyList), '?'));
                $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
                $stmt->execute($keyList);
            } else {
                $stmt = $this->db->query("SELECT setting_key, setting_value FROM site_settings");
            }
            
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = [];
            foreach ($settings as $s) {
                $result[$s['setting_key']] = $s['setting_value'];
            }
            
            return Response::json($result);
        } catch (\Throwable $e) {
            error_log("ERRO getSiteSettings: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro interno"], 500);
        }
    }

    /**
     * Rota: GET /api/user/usage - Consulta uso atual do usuário
     */
    public function getUserUsage($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        try {
            $userId = $loggedUser['id'];
            
            // Busca módulos ativos do usuário
            $stmt = $this->db->prepare("
                SELECT module_key, status FROM user_modules 
                WHERE user_id = :user_id AND status = 'active'
            ");
            $stmt->execute([':user_id' => $userId]);
            $activeModules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Conta uso por módulo (exemplo para freights)
            $usage = [];
            
            // Freights publicados este mês
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM freights 
                WHERE user_id = :user_id 
                AND MONTH(created_at) = MONTH(CURRENT_DATE())
                AND YEAR(created_at) = YEAR(CURRENT_DATE())
            ");
            $stmt->execute([':user_id' => $userId]);
            $usage['freights_published'] = (int)$stmt->fetch()['total'];
            
            // Anúncios marketplace ativos
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM listings 
                WHERE user_id = :user_id AND status = 'active'
            ");
            $stmt->execute([':user_id' => $userId]);
            $usage['marketplace_listings'] = (int)$stmt->fetch()['total'];
            
            return Response::json([
                "success" => true, 
                "data" => [
                    'active_modules' => array_column($activeModules, 'module_key'),
                    'usage' => $usage
                ]
            ]);
        } catch (\Throwable $e) {
            error_log("ERRO getUserUsage: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro interno"], 500);
        }
    }

    /**
     * Rota: GET /api/companies - Lista empresas (público, usado no admin)
     */
    public function listCompanies($data, $loggedUser) {
        try {
            $stmt = $this->db->query("
                SELECT id, name, email, phone, user_type, created_at 
                FROM users 
                WHERE (user_type = 'COMPANY' OR role = 'company' OR role = 'partner')
                AND deleted_at IS NULL
                AND status = 'active'
                ORDER BY name ASC
            ");
            $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Response::json(["success" => true, "companies" => $companies]);
        } catch (\Throwable $e) {
            error_log("ERRO listCompanies: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro interno"], 500);
        }
    }

    /**
     * Rota: GET /api/plans - Lista planos disponíveis (público para usuários logados)
     */
    public function getPlans($data, $loggedUser) {
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Login necessário"], 401);
        }

        try {
            $stmt = $this->db->query("
                SELECT id, name, price, duration_days, type, description, active 
                FROM plans 
                WHERE active = 1 
                ORDER BY price ASC
            ");
            $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return Response::json(["success" => true, "plans" => $plans]);
        } catch (\Throwable $e) {
            error_log("ERRO getPlans: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro interno"], 500);
        }
    }

}