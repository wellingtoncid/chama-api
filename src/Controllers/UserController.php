<?php
namespace App\Controllers;

use App\Core\Response;
use App\Core\Auth;
use App\Repositories\UserRepository;
use App\Controllers\NotificationController;

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

            // --- Lógica de Upload de AVATAR ---
            if (isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] === UPLOAD_ERR_OK) {
                $path = $this->handleFileUpload($_FILES['avatar_file'], 'avatars', $userId);
                if ($path) $data['avatar_url'] = $path;
            }

            // --- Lógica de Upload de CAPA (BANNER) ---
            if (isset($_FILES['cover_file']) && $_FILES['cover_file']['error'] === UPLOAD_ERR_OK) {
                $path = $this->handleFileUpload($_FILES['cover_file'], 'covers', $userId);
                if ($path) $data['cover_url'] = $path;
            }

            // Validação de Documento
            $document = preg_replace('/\D/', '', $data['cnpj'] ?? $data['document'] ?? '');
            if ($document && !$this->isValidDocument($document)) {
                return Response::json(["success" => false, "message" => "Documento inválido"], 400);
            }

            // Chamada única para o Repository (Passando o tipo de usuário)
            $data['user_type'] = $loggedUser['user_type'] ?? 'DRIVER';
            $success = $this->userRepo->updateProfileFields($userId, $data);

            return Response::json([
                "success" => true,
                "message" => "Perfil atualizado com sucesso!",
                "user" => $this->userRepo->getProfileData($userId)
            ]);

        } catch (\Exception $e) {
            return Response::json(["success" => false, "message" => $e->getMessage()], 500);
        }
    }

    // Método auxiliar para evitar repetição de código
    private function handleFileUpload($file, $folder, $userId) {
        $uploadDir = __DIR__ . "/../../public/uploads/{$folder}/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = "{$folder}_{$userId}_" . time() . ".{$ext}";
        
        if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
            return "/uploads/{$folder}/" . $fileName;
        }
        return null;
    }

    /**
     * Lógica de Verificação (Selo de Confiança) - PUBLIC para o Router acessar
     */
    public function runVerificationProcess($userId) {
        $user = $this->userRepo->getProfileData($userId);
        
        $points = 0;
        $fieldsToTrack = ['name', 'whatsapp', 'avatar_url', 'city', 'bio'];
        foreach ($fieldsToTrack as $f) {
            if (!empty($user[$f])) $points += 20;
        }

        $avg = (float)($user['rating_avg'] ?? 0);
        $count = (int)($user['rating_count'] ?? 0);

        $deservesBadge = ($points >= 80) || ($count >= 5 && $avg >= 4.5);

        $currentStatus = (int)($user['is_verified'] ?? 0);
        
        if ($deservesBadge && (int)$user['is_verified'] === 0) {
            $this->userRepo->updateProfileField($userId, 'is_verified', 1);
            try {
                $notif = new NotificationController($this->db);
                $notif->notify($userId, "🎉 Perfil Verificado!", "Selo de confiança ativado.");
            } catch (\Throwable $e) {}
        } elseif (!$deservesBadge && $currentStatus === 1) {
            $this->userRepo->updateProfileField($userId, 'is_verified', 0);
        }

        return (object)['is_verified' => $deservesBadge, 'score' => $points];
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

    public function uploadImage($db, $loggedUser, $data) {
        // 1. Verificação de Autenticação
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Não autorizado"], 401);
        }

        $userId = $loggedUser['id'];
        $imageUrl = $data['image_url'] ?? '';
        $type = $data['type'] ?? 'avatar'; // 'avatar' ou 'cover'/'banner'

        if (empty($imageUrl)) {
            return Response::json(["success" => false, "message" => "URL da imagem não fornecida"], 400);
        }

        // 2. Mapeamento de tipos para as colunas reais do seu banco
        // Seu banco usa 'avatar_url' e 'cover_url'
        $columnMap = [
            'avatar' => 'avatar_url',
            'cover'  => 'cover_url',
            'banner' => 'cover_url' // Alias comum no Front
        ];

        $targetColumn = $columnMap[$type] ?? 'avatar_url';

        // 3. Persistência via Repository
        try {
            $success = $this->userRepo->updateProfileField($userId, $targetColumn, $imageUrl);
            
            return Response::json([
                "success" => $success,
                "message" => $success ? "Imagem atualizada!" : "Erro ao salvar no banco",
                "column_updated" => $targetColumn
            ]);
        } catch (\Exception $e) {
            error_log("Erro em uploadImage: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro interno no servidor"], 500);
        }
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

    /**
     * Rota: POST /api/upload-avatar
     */
    public function uploadAvatar($db, $loggedUser, $data) {
        if (!$loggedUser) return Response::json(["success" => false], 401);

        $file = $_FILES['avatar'] ?? $_FILES['image'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return Response::json(["success" => false, "message" => "Falha no arquivo"], 400);
        }

        // Validação Real de MIME Type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
            return Response::json(["success" => false, "message" => "Formato inválido"], 400);
        }

        $fileName = "avatar_" . md5($loggedUser['id']) . "_" . time() . ".jpg";
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/avatars/";
        
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
            $url = "/uploads/avatars/" . $fileName;
            $this->userRepo->updateProfileFields($loggedUser['id'], ['avatar_url' => $url]);
            $this->runVerificationProcess($loggedUser['id']);
            
            return Response::json(["success" => true, "url" => $url]);
        }

        return Response::json(["success" => false, "message" => "Erro de permissão no servidor"], 500);
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

}