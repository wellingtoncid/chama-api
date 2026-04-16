<?php
namespace App\Controllers;

use App\Core\Response;
use App\Core\Auth;
use App\Repositories\UserRepository;
use App\Controllers\NotificationController;
use App\Services\GeocodingService;
use App\Services\ContentFilterService;
use PDO;

class UserController {
    private $userRepo;
    private $db;
    private $geocoding;

    public function __construct($db) {
        $this->db = $db;
        $this->userRepo = new UserRepository($db);
        $this->geocoding = new GeocodingService($db);
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

        // Validar bio com ContentFilter
        if (!empty($data['bio']) && !ContentFilterService::isClean($data['bio'])) {
            $reason = ContentFilterService::getReason($data['bio']);
            return Response::json(["success" => false, "message" => $reason ?: "Sua bio contém conteúdo não permitido."], 400);
        }

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
     * Toggle rápido de disponibilidade do driver
     * Rota: POST /api/toggle-availability
     */
    public function toggleAvailability($data, $loggedUser) {
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Não autorizado"], 401);
        }

        try {
            $userId = $loggedUser['id'];
            $newStatus = isset($data['is_available']) ? (int)$data['is_available'] : null;

            if ($newStatus === null || ($newStatus !== 0 && $newStatus !== 1)) {
                return Response::json(["success" => false, "message" => "Valor inválido"], 400);
            }

            $this->userRepo->toggleAvailability($userId, $newStatus);

            return Response::json([
                "success" => true,
                "message" => $newStatus === 1 ? "Você está disponível" : "Você está indisponível",
                "is_available" => $newStatus
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

            $stmt = $this->db->prepare("
                SELECT module_key, status, activated_at, expires_at, 
                       requires_approval, approval_status, requested_at, plan_id 
                FROM user_modules WHERE user_id = :user_id
                  AND (expires_at IS NULL OR expires_at > NOW())
            ");
            $stmt->execute([':user_id' => $userId]);
            $userModules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $modulesMap = [];
            foreach ($userModules as $m) {
                $modulesMap[$m['module_key']] = $m;
            }

            // Módulos que requerem aprovação do admin
            $approvalRequiredModules = ['quotes', 'advertiser'];

            // Para empresas, módulos permitidos são ativos por padrão
            $isCompanyDefault = $isCompany;

            $modules = [];
            foreach ($availableModules as $mod) {
                $userMod = $modulesMap[$mod['key']] ?? null;
                $isAllowed = in_array($mod['key'], $allowedModules);
                $requiresApproval = in_array($mod['key'], $approvalRequiredModules);
                
                // Se não há registro do usuário, usa o padrão
                $isActive = false;
                if ($userMod) {
                    $isActive = $userMod['status'] === 'active';
                } elseif ($isAllowed && !$requiresApproval) {
                    // Para empresas, módulos permitidos são ativos por padrão (exceto os que requerem aprovação)
                    // Para motoristas, fretes é obrigatório e ativo
                    $isActive = $isCompanyDefault || $mod['key'] === 'freights';
                }

                // Determina status de aprovação
                $approvalStatus = null;
                if ($requiresApproval && $userMod) {
                    $approvalStatus = $userMod['approval_status'] ?? ($isAllowed ? 'approved' : 'pending');
                }

                $modules[] = [
                    'key' => $mod['key'],
                    'name' => $mod['name'],
                    'description' => $mod['description'],
                    'status' => $isActive ? 'active' : ($userMod ? $userMod['status'] : 'inactive'),
                    'is_active' => $isActive,
                    'activated_at' => $userMod['activated_at'] ?? ($isActive ? date('Y-m-d H:i:s') : null),
                    'expires_at' => $userMod['expires_at'] ?? null,
                    'is_allowed' => $isAllowed,
                    'requires_approval' => $requiresApproval,
                    'approval_status' => $approvalStatus,
                    'requested_at' => $userMod['requested_at'] ?? null
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
            $allowedModules = ['freights', 'marketplace', 'quotes', 'advertiser', 'chat', 'financial', 'groups', 'plans', 'support', 'identity_verification'];
        } elseif ($isCompany) {
            // Empresas podem ativar: freights, marketplace, chat, groups, identity_verification
            // quotes e advertiser requerem aprovação
            $allowedModules = ['freights', 'marketplace', 'chat', 'groups', 'identity_verification'];
            $approvalRequiredModules = ['quotes', 'advertiser'];
        } else {
            // Motoristas: freights (leitura), marketplace, identity_verification
            $allowedModules = ['freights', 'marketplace', 'identity_verification'];
            $approvalRequiredModules = [];
        }
        
        if (!in_array($moduleKey, $allowedModules) && !in_array($moduleKey, $approvalRequiredModules)) {
            return Response::json(["success" => false, "message" => "Módulo não disponível para seu perfil"], 400);
        }

        // Bloqueia ativação de módulos que requerem aprovação
        if ($action === 'activate' && in_array($moduleKey, $approvalRequiredModules)) {
            return Response::json([
                "success" => false, 
                "message" => "Este módulo requer aprovação. Use 'request_access' para solicitar.",
                "requires_approval" => true
            ], 400);
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
     * Rota: POST /api/user/modules/request - Solicita acesso a módulo que requer aprovação
     */
    public function requestModuleAccess($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $moduleKey = $data['module_key'] ?? '';
        $contactInfo = $data['contact_info'] ?? '';
        $justification = $data['justification'] ?? '';
        
        $role = strtoupper($loggedUser['role'] ?? '');
        $isCompany = ($role === 'COMPANY');
        
        // Apenas empresas podem solicitar módulos que requerem aprovação
        if (!$isCompany) {
            return Response::json(["success" => false, "message" => "Apenas empresas podem solicitar módulos"], 400);
        }
        
        // Apenas quotes e advertiser requerem aprovação
        $approvalRequiredModules = ['quotes', 'advertiser'];
        if (!in_array($moduleKey, $approvalRequiredModules)) {
            return Response::json(["success" => false, "message" => "Este módulo não requer aprovação"], 400);
        }

        try {
            $userId = $loggedUser['id'];
            $userName = $loggedUser['name'] ?? '';
            $companyName = $loggedUser['company_name'] ?? '';
            
            // Verifica se já existe solicitação pendente
            $stmt = $this->db->prepare("
                SELECT id FROM portal_requests 
                WHERE user_id = :user_id 
                AND request_type = 'module_request' 
                AND module_key = :module_key 
                AND status = 'pending'
            ");
            $stmt->execute([':user_id' => $userId, ':module_key' => $moduleKey]);
            if ($stmt->fetch()) {
                return Response::json(["success" => false, "message" => "Você já possui uma solicitação pendente para este módulo"], 400);
            }

            // Cria solicitação usando a tabela portal_requests
            $stmt = $this->db->prepare("
                INSERT INTO portal_requests 
                (user_id, request_type, module_key, title, contact_info, description, status, created_at)
                VALUES (:user_id, 'module_request', :module_key, :title, :contact_info, :description, 'pending', NOW())
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':module_key' => $moduleKey,
                ':title' => "Solicitação de Módulo: {$moduleKey} - {$companyName}",
                ':contact_info' => $contactInfo,
                ':description' => $justification ?: "Solicitação de acesso ao módulo {$moduleKey}"
            ]);

            // Atualiza status na user_modules
            $stmt = $this->db->prepare("
                INSERT INTO user_modules (user_id, module_key, status, requires_approval, approval_status, requested_at)
                VALUES (:user_id, :module_key, 'pending', 1, 'pending', NOW())
                ON DUPLICATE KEY UPDATE requires_approval = 1, approval_status = 'pending', requested_at = NOW()
            ");
            $stmt->execute([':user_id' => $userId, ':module_key' => $moduleKey]);

            return Response::json([
                "success" => true, 
                "message" => "Solicitação enviada com sucesso! Nossa equipe analisará e entrará em contato."
            ]);

        } catch (\Throwable $e) {
            error_log("ERRO requestModuleAccess: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao processar solicitação"], 500);
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
     * Rota: GET /api/ad-positions - Lista posições de publicidade (público)
     */
    public function getAdPositions($data, $loggedUser) {
        try {
            $stmt = $this->db->query("
                SELECT 
                    feature_key, 
                    feature_name, 
                    description,
                    ad_size,
                    icon_key,
                    price_monthly, 
                    duration_days
                FROM pricing_rules 
                WHERE module_key = 'advertiser' 
                AND is_active = 1
                ORDER BY price_monthly ASC
            ");
            $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return Response::json(["success" => true, "data" => $positions]);
        } catch (\Throwable $e) {
            error_log("ERRO getAdPositions: " . $e->getMessage());
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
     * Rota: GET /api/public/site-settings - Retorna listas públicas do site
     */
    public function getPublicLists($data, $loggedUser) {
        try {
            $keys = ['vehicle_types', 'body_types', 'equipment_types', 'certification_types'];
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
            $stmt->execute($keys);
            
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = [];
            foreach ($settings as $s) {
                $result[$s['setting_key']] = $s['setting_value'];
            }
            
            return Response::json([
                "success" => true,
                "data" => $result
            ]);
        } catch (\Throwable $e) {
            error_log("ERRO getPublicLists: " . $e->getMessage());
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
                SELECT id, name, slug, price, price_quarterly, price_semiannual, price_yearly, 
                       duration_days, type, billing_type, description, features, active, 
                       is_highlighted, category
                FROM plans 
                WHERE active = 1 
                ORDER BY sort_order ASC, price ASC
            ");
            $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse features JSON
            foreach ($plans as &$plan) {
                if ($plan['features'] && is_string($plan['features'])) {
                    $decoded = json_decode($plan['features'], true);
                    if (is_array($decoded) && !empty($decoded) && isset($decoded[0])) {
                        $plan['features'] = array_values($decoded);
                    } elseif (is_array($decoded) && isset($decoded['features']) && is_array($decoded['features'])) {
                        $plan['features'] = $decoded['features'];
                    }
                }
                if (!isset($plan['features']) || !is_array($plan['features'])) {
                    $plan['features'] = [];
                }
            }
            
            return Response::json(["success" => true, "plans" => $plans]);
        } catch (\Throwable $e) {
            error_log("ERRO getPlans: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro interno"], 500);
        }
    }

    /**
     * Rota: POST /api/verify-cnpj - Verifica CNPJ na Receita Federal
     */
    public function verifyCnpj($data, $loggedUser) {
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Não autorizado"], 401);
        }

        $cnpj = preg_replace('/\D/', '', $data['cnpj'] ?? '');

        if (strlen($cnpj) !== 14) {
            return Response::json(["success" => false, "message" => "CNPJ inválido. Deve ter 14 dígitos."], 400);
        }

        try {
            // Consulta API ReceitaWS (free tier: 3 requests por minuto)
            $ch = curl_init("https://www.receitaws.com.br/v1/cnpj/{$cnpj}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Accept: application/json']
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$response) {
                return Response::json([
                    "success" => false, 
                    "message" => "Não foi possível consultar a Receita Federal. Tente novamente em alguns minutos."
                ], 500);
            }

            $result = json_decode($response, true);

            if (isset($result['status']) && $result['status'] === 'ERROR') {
                return Response::json([
                    "success" => false, 
                    "message" => $result['message'] ?? "CNPJ não encontrado"
                ], 400);
            }

            // Extrai dados principais
            $cnpjData = [
                'cnpj' => $result['cnpj'] ?? $cnpj,
                'razao_social' => $result['nome'] ?? '',
                'nome_fantasia' => $result['fantasia'] ?? '',
                'situacao' => $result['situacao'] ?? '',
                'logradouro' => $result['logradouro'] ?? '',
                'numero' => $result['numero'] ?? '',
                'complemento' => $result['complemento'] ?? '',
                'bairro' => $result['bairro'] ?? '',
                'cidade' => $result['municipio'] ?? '',
                'estado' => $result['uf'] ?? '',
                'cep' => $result['cep'] ?? '',
                'telefone' => $result['telefone'] ?? '',
                'email' => $result['email'] ?? '',
                'data_abertura' => $result['abertura'] ?? '',
                'natureza_juridica' => $result['natureza_juridica'] ?? '',
                'cnae' => $result['cnae'] ?? '',
                'porte' => $result['porte'] ?? '',
            ];

            // Salva dados verificados no banco
            $stmt = $this->db->prepare("
                INSERT INTO verified_cnpj (user_id, cnpj, razao_social, nome_fantasia, situacao, 
                    logradouro, numero, complemento, bairro, cidade, estado, cep, telefone, email, 
                    data_abertura, natureza_juridica, cnae, porte, verified_at)
                VALUES (:user_id, :cnpj, :razao_social, :nome_fantasia, :situacao,
                    :logradouro, :numero, :complemento, :bairro, :cidade, :estado, :cep, :telefone, :email,
                    :data_abertura, :natureza_juridica, :cnae, :porte, NOW())
                ON DUPLICATE KEY UPDATE 
                    cnpj = VALUES(cnpj),
                    razao_social = VALUES(razao_social),
                    nome_fantasia = VALUES(nome_fantasia),
                    situacao = VALUES(situacao),
                    logradouro = VALUES(logradouro),
                    numero = VALUES(numero),
                    complemento = VALUES(complemento),
                    bairro = VALUES(bairro),
                    cidade = VALUES(cidade),
                    estado = VALUES(estado),
                    cep = VALUES(cep),
                    telefone = VALUES(telefone),
                    email = VALUES(email),
                    data_abertura = VALUES(data_abertura),
                    natureza_juridica = VALUES(natureza_juridica),
                    cnae = VALUES(cnae),
                    porte = VALUES(porte),
                    verified_at = NOW(),
                    status = 'verified'
            ");
            $stmt->execute([
                ':user_id' => $loggedUser['id'],
                ':cnpj' => $cnpjData['cnpj'],
                ':razao_social' => $cnpjData['razao_social'],
                ':nome_fantasia' => $cnpjData['nome_fantasia'],
                ':situacao' => $cnpjData['situacao'],
                ':logradouro' => $cnpjData['logradouro'],
                ':numero' => $cnpjData['numero'],
                ':complemento' => $cnpjData['complemento'],
                ':bairro' => $cnpjData['bairro'],
                ':cidade' => $cnpjData['cidade'],
                ':estado' => $cnpjData['estado'],
                ':cep' => $cnpjData['cep'],
                ':telefone' => $cnpjData['telefone'],
                ':email' => $cnpjData['email'],
                ':data_abertura' => $cnpjData['data_abertura'],
                ':natureza_juridica' => $cnpjData['natureza_juridica'],
                ':cnae' => $cnpjData['cnae'],
                ':porte' => $cnpjData['porte'],
            ]);

            return Response::json([
                "success" => true,
                "message" => "CNPJ verificado com sucesso!",
                "data" => $cnpjData,
                "is_active" => strtoupper($cnpjData['situacao']) === 'ATIVA'
            ]);

        } catch (\Throwable $e) {
            error_log("ERRO verifyCnpj: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao verificar CNPJ: " . $e->getMessage()], 500);
        }
    }

    /**
     * Rota: GET /api/get-cnpj-data - Retorna dados do CNPJ verificado do usuário
     */
    public function getCnpjData($data, $loggedUser) {
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Não autorizado"], 401);
        }

        try {
            $stmt = $this->db->prepare("
                SELECT * FROM verified_cnpj 
                WHERE user_id = :user_id 
                ORDER BY verified_at DESC 
                LIMIT 1
            ");
            $stmt->execute([':user_id' => $loggedUser['id']]);
            $cnpjData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cnpjData) {
                return Response::json(["success" => true, "data" => null, "message" => "Nenhum CNPJ verificado"]);
            }

            return Response::json(["success" => true, "data" => $cnpjData]);

        } catch (\Throwable $e) {
            error_log("ERRO getCnpjData: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao buscar dados do CNPJ"], 500);
        }
    }
    
    /**
     * Rota: GET /api/geocode/cep - Geocodifica CEP para lat/lng
     */
    public function geocodeCep($data, $loggedUser) {
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Não autorizado"], 401);
        }
        
        $cep = $data['cep'] ?? $_GET['cep'] ?? '';
        
        if (!$cep) {
            return Response::json(["success" => false, "message" => "CEP não informado"], 400);
        }
        
        try {
            $result = $this->geocoding->geocodeFromCep($cep);
            
            if (!$result) {
                return Response::json(["success" => false, "message" => "CEP não encontrado"], 404);
            }
            
            return Response::json(["success" => true, "data" => $result]);
            
        } catch (\Throwable $e) {
            error_log("ERRO geocodeCep: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao geocodificar CEP"], 500);
        }
    }
    
    /**
     * Rota: POST /api/driver/location - Atualiza localização do motorista
     */
    public function updateDriverLocation($data, $loggedUser) {
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Não autorizado"], 401);
        }
        
        $lat = (float)($data['lat'] ?? 0);
        $lng = (float)($data['lng'] ?? 0);
        $city = trim($data['city'] ?? '');
        $state = trim($data['state'] ?? '');
        $cep = trim($data['cep'] ?? '');
        
        if (!$lat || !$lng) {
            return Response::json(["success" => false, "message" => "Coordenadas inválidas"], 400);
        }
        
        try {
            // Se tem CEP mas não tem cidade, tenta obter via CEP
            if ($cep && (!$city || !$state)) {
                $viaCep = $this->geocoding->geocodeFromCep($cep);
                if ($viaCep && isset($viaCep['display_name'])) {
                    // Extrair cidade e estado do display_name
                    $parts = explode(',', $viaCep['display_name']);
                    if (count($parts) >= 3) {
                        $city = $city ?: trim($parts[count($parts) - 3]);
                    }
                }
            }
            
            $this->geocoding->saveDriverLocation($loggedUser['id'], $lat, $lng, $city, $state);
            $this->recalculateProfileCompleteness($loggedUser['id']);
            
            return Response::json(["success" => true, "message" => "Localização atualizada"]);
            
        } catch (\Throwable $e) {
            error_log("ERRO updateDriverLocation: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao atualizar localização"], 500);
        }
    }
    
    /**
     * Rota: GET /api/profile/completeness - Retorna score de completude do perfil
     */
    public function getProfileCompleteness($data, $loggedUser) {
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Não autorizado"], 401);
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    u.name, p.bio, p.avatar_url, p.vehicle_type, p.body_type, 
                    p.home_lat, p.home_lng, p.rntrc_number, p.verification_status,
                    p.profile_completeness, p.available_equipment, p.certifications
                FROM users u
                INNER JOIN user_profiles p ON u.id = p.user_id
                WHERE u.id = ?
            ");
            $stmt->execute([$loggedUser['id']]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$profile) {
                return Response::json(["success" => false, "message" => "Perfil não encontrado"], 404);
            }
            
            // Calcula score detalhado
            $score = 0;
            $missing = [];
            $completed = [];
            
            if (!empty($profile['name'])) {
                $score += 10;
                $completed[] = 'name';
            } else {
                $missing[] = 'name';
            }
            
            if (!empty($profile['bio'])) {
                $score += 10;
                $completed[] = 'bio';
            } else {
                $missing[] = 'bio';
            }
            
            if (!empty($profile['avatar_url'])) {
                $score += 10;
                $completed[] = 'avatar';
            } else {
                $missing[] = 'avatar';
            }
            
            if (!empty($profile['vehicle_type'])) {
                $score += 15;
                $completed[] = 'vehicle_type';
            } else {
                $missing[] = 'vehicle_type';
            }
            
            if (!empty($profile['body_type'])) {
                $score += 15;
                $completed[] = 'body_type';
            } else {
                $missing[] = 'body_type';
            }
            
            if (!empty($profile['home_lat']) && !empty($profile['home_lng'])) {
                $score += 15;
                $completed[] = 'location';
            } else {
                $missing[] = 'location';
            }
            
            if (!empty($profile['rntrc_number'])) {
                $score += 10;
                $completed[] = 'rntrc';
            } else {
                $missing[] = 'rntrc';
            }
            
            if ($profile['verification_status'] === 'verified') {
                $score += 15;
                $completed[] = 'verification';
            } else {
                $missing[] = 'verification';
            }
            
            // Atualiza no banco
            if ($score !== (int)$profile['profile_completeness']) {
                $update = $this->db->prepare("UPDATE user_profiles SET profile_completeness = ? WHERE user_id = ?");
                $update->execute([$score, $loggedUser['id']]);
            }
            
            return Response::json([
                "success" => true,
                "data" => [
                    "score" => $score,
                    "completed" => $completed,
                    "missing" => $missing,
                    "is_complete" => $score >= 80
                ]
            ]);
            
        } catch (\Throwable $e) {
            error_log("ERRO getProfileCompleteness: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao calcular completude"], 500);
        }
    }
    
    /**
     * Rota: POST /api/driver/equipment - Atualiza equipamentos do motorista
     */
    public function updateDriverEquipment($data, $loggedUser) {
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Não autorizado"], 401);
        }
        
        $equipment = $data['equipment'] ?? [];
        
        if (!is_array($equipment)) {
            $equipment = [];
        }
        
        try {
            $stmt = $this->db->prepare("UPDATE user_profiles SET available_equipment = ? WHERE user_id = ?");
            $stmt->execute([json_encode($equipment), $loggedUser['id']]);
            
            return Response::json(["success" => true, "message" => "Equipamentos atualizados"]);
            
        } catch (\Throwable $e) {
            error_log("ERRO updateDriverEquipment: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao atualizar"], 500);
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

}