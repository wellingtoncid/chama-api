<?php
namespace App\Repositories;

use PDO;
use Exception;

class UserRepository {
    private $db;

    public function __construct($db) { 
        $this->db = $db; 
    }

    public function create($data) {
        try {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
            }

            // 1. Identificadores e Sanitização
            $tempDoc = 'TEMP_' . time() . '_' . rand(100, 999); 
            $accountUuid = bin2hex(random_bytes(16)); 
            $roleSlug = strtolower($data['role'] ?? 'driver');
            $cleanDoc = preg_replace('/\D/', '', $data['document'] ?? $tempDoc);
            $isCompany = ($roleSlug === 'company');

            // 2. Definir dados para accounts
            // Para empresas: corporate_name = Razão Social, trade_name = Nome Fantasia
            // Para motoristas: corporate_name = Nome do motorista
            $corporateName = $isCompany ? ($data['corporate_name'] ?? $data['name']) : $data['name'];
            $tradeName = $isCompany ? ($data['name_fantasy'] ?? null) : $data['name'];
            
            // 3. Definir nome do usuário
            // Para empresas: users.name = Nome do responsável (owner_name)
            // Para motoristas: users.name = Nome do motorista
            $userName = $isCompany ? ($data['owner_name'] ?? $data['name']) : $data['name'];

            // 4. Inserção na tabela ACCOUNTS (Entidade Fiscal)
            $sqlAccount = "INSERT INTO accounts (uuid, document_type, document_number, corporate_name, trade_name, status) 
                            VALUES (:uuid, :doc_type, :doc_num, :corporate_name, :trade_name, 'active')";
            
            $stmtAcc = $this->db->prepare($sqlAccount);
            $stmtAcc->execute([
                ':uuid'          => $accountUuid,
                ':doc_type'      => $data['document_type'] ?? (strlen($cleanDoc) === 14 ? 'CNPJ' : 'CPF'),
                ':doc_num'       => $cleanDoc,
                ':corporate_name' => $corporateName,
                ':trade_name'    => $tradeName
            ]);
            
            $accountId = $this->db->lastInsertId();

            // 5. Buscar Role ID
            $stmtRole = $this->db->prepare("SELECT id FROM roles WHERE slug = :slug LIMIT 1");
            $stmtRole->execute([':slug' => $roleSlug]);
            $roleId = $stmtRole->fetchColumn() ?: 2;

            // 6. Criar o Usuário vinculado à Account
            $sqlUser = "INSERT INTO users (
                            name, email, password, whatsapp, 
                            account_id, role_id, role, user_type, status, plan_id, created_at
                        ) VALUES (
                            :name, :email, :password, :whatsapp, 
                            :account_id, :role_id, :role, :user_type, 'active', 1, NOW()
                        )";
            
            $stmtUser = $this->db->prepare($sqlUser);
            $stmtUser->execute([
                ':name'       => $userName,
                ':email'      => $data['email'],
                ':password'   => $data['password'], // Deve estar hasheado via password_hash()
                ':whatsapp'   => preg_replace('/\D/', '', $data['whatsapp'] ?? ''),
                ':account_id' => $accountId,
                ':role_id'    => $roleId,
                ':role'       => $roleSlug,
                ':user_type'  => strtoupper($data['user_type'] ?? ($roleSlug === 'driver' ? 'DRIVER' : 'COMPANY'))
            ]);

            $userId = $this->db->lastInsertId();

            // 8. Popular user_modules com módulos padrão baseado no cargo
            $this->populateUserModules((int)$userId, $roleSlug);

            // 7. Criar Perfil Público (user_profiles)
            // Geramos o slug e já inicializamos o JSON private_data
            $baseSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $userName)));
            $uniqueSlug = $baseSlug . '-' . $userId;
            
            $initialAttributes = [
                'business_segment' => ($roleSlug === 'driver' ? 'motorista' : 'geral'),
                'created_via' => 'registration',
                'display_name' => $tradeName ?? $userName
            ];

            $sqlProfile = "INSERT INTO user_profiles (user_id, slug, extended_attributes, availability_status, created_at) 
                        VALUES (:user_id, :slug, :json, 'available', NOW())";
            
            $this->db->prepare($sqlProfile)->execute([
                ':user_id'      => $userId,
                ':slug'         => $uniqueSlug,
                ':json'         => json_encode($initialAttributes)
            ]);

            $this->db->commit();
            return $userId;

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro ao criar usuário: " . $e->getMessage());
            throw $e; 
        }
    }

    /**
     * Gera um slug amigável para a URL do perfil
     */
    private function generateSlug($name, $id) {
        $string = $this->removeAccents($name);
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string)));
        return $slug . '-' . $id;
    }

    /**
     * Auxiliar para limpar nomes para o slug
     */
    private function removeAccents($string) {
        return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string);
    }

    public function findById($id) {
        $sql = "SELECT 
                    u.id, u.name, u.email, u.whatsapp, u.role, u.user_type, u.account_id,
                    a.document_number as document, 
                    a.trade_name as account_trade_name,
                    a.corporate_name,
                    p.bio, p.avatar_url, p.cover_url, p.slug, p.vehicle_type, p.body_type,
                    p.extended_attributes
                FROM users u
                LEFT JOIN accounts a ON u.account_id = a.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                WHERE u.id = :id AND u.deleted_at IS NULL
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => (int)$id]);
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) return null;

        // --- NORMALIZAÇÃO PARA COMPATIBILIDADE ---
        // Decodifica o JSON para pegar dados que antes estavam na tabela companies
        $details = !empty($user['extended_attributes']) 
            ? json_decode($user['extended_attributes'], true) 
            : [];

        // Mapeia os nomes para o que o seu sistema espera (retrocompatibilidade)
        $user['company_name'] = $user['account_trade_name'] ?: ($details['trade_name'] ?? $user['corporate_name']);
        $user['company_cnpj'] = $user['document']; // O documento da conta é o CNPJ para empresas

        // Se houver campos específicos no JSON que você usa com frequência, pode extrair aqui:
        $user['fleet_types'] = $details['fleet_types'] ?? [];

        // Mantém a sua função de formatação original
        return $this->formatUserTypes($user);
    }
    /**
     * Método auxiliar para garantir tipos de dados consistentes
     */
    private function formatUserTypes($user) {
        if (isset($user['is_verified']))   $user['is_verified'] = (int)$user['is_verified'] === 1;
        if (isset($user['is_subscriber'])) $user['is_subscriber'] = (int)$user['is_subscriber'] === 1;
        if (isset($user['rating_avg']))    $user['rating_avg'] = (float)$user['rating_avg'];
        if (isset($user['rating_count']))  $user['rating_count'] = (int)$user['rating_count'];
        
        return $user;
    }

    public function findByEmailOrWhatsapp($identifier) {
        $identifier = trim($identifier);
        if (empty($identifier)) return null;

        $isEmail = strpos($identifier, '@') !== false;
        $whatsappSearch = preg_replace('/\D/', '', $identifier);

        $sql = "SELECT 
                    u.*, 
                    r.slug as role_slug, 
                    a.id as account_id, 
                    a.corporate_name as company_name,
                    p.avatar_url, 
                    p.slug as profile_slug
                FROM users u
                LEFT JOIN roles r ON u.role_id = r.id
                LEFT JOIN accounts a ON u.account_id = a.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                WHERE (u.email = :email OR u.whatsapp = :whatsapp) 
                AND u.deleted_at IS NULL
                LIMIT 1";

        try {
            $stmt = $this->db->prepare($sql);   
            $stmt->execute([
                ':email'    => $identifier,
                ':whatsapp' => $isEmail ? '---' : $whatsappSearch
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // 3. Tipagem Garantida (Essencial para o Frontend/React não bugar)
                $user['id'] = (int)$user['id'];
                $user['account_id'] = $user['account_id'] ? (int)$user['account_id'] : null;
                $user['role_slug'] = $user['role_slug'] ?? 'driver';
                $user['status'] = $user['status'] ?? 'pending'; 
                    if (!isset($user['password'])) {
                    error_log("ERRO: Campo password não retornado do banco.");
                }
            }

            return $user ?: null;

        } catch (\PDOException $e) {
                error_log("Erro Crítico em findByEmailOrWhatsapp: " . $e->getMessage());
                return null;
        }
    }

    // findByEmailOnly foi removido para manter o comportamento original sem fallback
    
    /**
     * Atualiza dados da tabela principal 'users'
     * Adicionado: suporte a documento e is_verified
     */
    public function updateBasicInfo($userId, $data) {
        // 1. Sanitização
        $name     = !empty($data['name'])     ? trim($data['name']) : null;
        $whatsapp = !empty($data['whatsapp']) ? preg_replace('/\D/', '', $data['whatsapp']) : null;
        $city     = !empty($data['city'])     ? trim($data['city']) : null;
        $state    = !empty($data['state'])    ? strtoupper(trim($data['state'])) : null;
        
        // Pegamos o documento para atualizar na tabela ACCOUNTS depois
        $rawDoc   = !empty($data['cnpj']) ? $data['cnpj'] : ($data['document'] ?? null);
        $document = $rawDoc ? preg_replace('/\D/', '', $rawDoc) : null;

        try {
            if (!$this->db->inTransaction()) $this->db->beginTransaction();

            // 2. ATUALIZAÇÃO DA TABELA USERS (Removido o campo 'document')
            $sql = "UPDATE users SET 
                        name = COALESCE(:name, name), 
                        whatsapp = COALESCE(:whatsapp, whatsapp),
                        city = COALESCE(:city, city),
                        state = COALESCE(:state, state),
                        is_verified = COALESCE(:is_verified, is_verified)
                    WHERE id = :id AND deleted_at IS NULL";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':name'         => $name,
                ':whatsapp'     => $whatsapp,
                ':city'         => $city,
                ':state'        => $state,
                ':is_verified'  => isset($data['is_verified']) ? (int)$data['is_verified'] : null,
                ':id'           => $userId
            ]);

            // 3. ATUALIZAÇÃO DA TABELA ACCOUNTS (Onde o documento realmente vive agora)
            if ($document || $name) {
                $sqlAcc = "UPDATE accounts a 
                        JOIN users u ON u.account_id = a.id 
                        SET a.document_number = COALESCE(:doc, a.document_number),
                            a.corporate_name = COALESCE(:name, a.corporate_name)
                        WHERE u.id = :userId";
                
                $this->db->prepare($sqlAcc)->execute([
                    ':doc'    => $document,
                    ':name'   => $name,
                    ':userId' => $userId
                ]);
            }

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro crítico updateBasicInfo (ID $userId): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Atualiza dados da tabela 'user_profiles'
     * Adicionado: extended_attributes (JSON) e avatar_url/banner_url
     */
    public function updateFullProfile($userId, $data) {
        try {
            if (!$this->db->inTransaction()) $this->db->beginTransaction();

            $nullIfEmpty = fn($val) => (isset($val) && trim((string)$val) !== '') ? trim((string)$val) : null;

            // 1. Atualiza Identidade (users)
            $this->db->prepare("UPDATE users SET name = ?, whatsapp = ?, city = ?, state = ?, updated_at = NOW() WHERE id = ?")
                ->execute([
                    $nullIfEmpty($data['name'] ?? null),
                    preg_replace('/\D/', '', $data['whatsapp'] ?? ''),
                    $nullIfEmpty($data['city'] ?? null),
                    $nullIfEmpty($data['state'] ?? null),
                    $userId
                ]);

            // 2. Atualiza Conta Jurídica (accounts)
            $stmt = $this->db->prepare("SELECT account_id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $acc = $stmt->fetch();
            
            if ($acc['account_id']) {
                $this->db->prepare("UPDATE accounts SET trade_name = ?, document_number = COALESCE(?, document_number) WHERE id = ?")
                    ->execute([
                        $nullIfEmpty($data['name_fantasy'] ?? $data['trade_name'] ?? null),
                        $data['clean_document'] ?? null,
                        $acc['account_id']
                    ]);
            }

            // 3. Organiza o JSON de Atributos Extras (O que não tem coluna própria)
            $extraDetails = [
                'address'            => $data['address'] ?? null,
                'address_number'     => $data['address_number'] ?? null,
                'neighborhood'       => $data['neighborhood'] ?? null,
                'postal_code'        => preg_replace('/\D/', '', $data['postal_code'] ?? ''),
                'business_segment'   => $data['business_segment'] ?? 'transport',
                'fleet_types'        => $data['fleet_types'] ?? [],       // Array de tipos de frota
                'transport_services' => $data['transport_services'] ?? [], // Ex: Mudanças, Refrigeração
                'certifications'     => $data['certifications'] ?? [],     // Ex: SASSMAQ, ISO
                'website'            => $data['website'] ?? null,
                'social' => [
                    'instagram' => $data['instagram'] ?? null,
                    'linkedin'  => $data['linkedin'] ?? null
                ],
                // Disponibilidade do motorista (0/1) mantida também no JSON para possíveis usos futuros
                'is_available'       => isset($data['is_available']) ? (int)$data['is_available'] : null
            ];

            // 4. Atualiza Vitrine e Dados Técnicos (user_profiles)
            $sqlProfile = "UPDATE user_profiles SET 
                            bio = :bio, 
                            avatar_url = COALESCE(:avatar, avatar_url),
                            cover_url = COALESCE(:cover, cover_url),
                            vehicle_type = :v_type,
                            body_type = :b_type,
                            experience_years = :exp,
                            rntrc_number = :antt,
                            slug = :slug,
                            extended_attributes = :json,
                            updated_at = NOW()
                        WHERE user_id = :id";

            $this->db->prepare($sqlProfile)->execute([
                ':bio'    => $nullIfEmpty($data['bio'] ?? null),
                ':avatar' => $data['avatar_url'] ?? null,
                ':cover'  => $data['cover_url'] ?? null,
                ':v_type' => $data['vehicle_type'] ?? null,
                ':b_type' => $data['body_type'] ?? null,
                ':exp'    => (int)($data['experience_years'] ?? 0),
                ':antt'   => $data['antt'] ?? $data['rntrc_number'] ?? null,
                ':slug'   => $data['slug'] ?? null,
                ':json'   => json_encode($extraDetails, JSON_UNESCAPED_UNICODE),
                ':id'     => $userId
            ]);

            $this->db->commit();

            $this->autoApproveProfile($userId);

            return true;
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Toggle rápido de disponibilidade - atualiza apenas availability_status
     */
    public function toggleAvailability($userId, $isAvailable) {
        $status = $isAvailable === 1 ? 'available' : 'offline';
        
        $sql = "UPDATE user_profiles SET 
                    availability_status = :status,
                    updated_at = NOW()
                WHERE user_id = :user_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':user_id' => $userId
        ]);
    }

    /**
     * getProfileData atualizado para trazer os atributos JSON
     */
    public function getProfileData($userId) {
        $sql = "SELECT 
                    u.id, u.name as user_name, u.email, u.whatsapp, u.role, u.status,
                    u.user_type, u.account_id, u.parent_id,
                    u.city as user_city, u.state as user_state, u.is_verified, 
                    a.document_number as document, a.corporate_name, a.trade_name as account_trade_name,
                    p.avatar_url, p.cover_url, p.bio, p.slug, 
                    u.city as profile_city, u.state as profile_state,
                    p.vehicle_type, p.body_type, p.verification_status, 
                    p.availability_status, p.extended_attributes 
                FROM users u
                LEFT JOIN accounts a ON u.account_id = a.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                WHERE u.id = :id AND u.deleted_at IS NULL
                LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;

        // --- TRATAMENTO DO JSON ---
        $row['details'] = !empty($row['extended_attributes']) 
            ? json_decode($row['extended_attributes'], true) 
            : [];

        // --- COMPATIBILIDADE COM O FRONT-END ---
        // Mapeia o que era da tabela 'companies' para o primeiro nível do array
        $legacyFields = [
            'business_type', 'transport_services', 'fleet_types', 
            'certifications', 'website_url', 'postal_code', 
            'address', 'address_number', 'neighborhood'
        ];

        foreach ($legacyFields as $field) {
            $row[$field] = $row['details'][$field] ?? ($field === 'fleet_types' || $field === 'transport_services' ? [] : '');
        }

        // --- REDES SOCIAIS (compatibilidade com formato antigo e novo "social") ---
        $social = is_array($row['details']['social'] ?? null) ? $row['details']['social'] : [];
        $row['instagram'] = $social['instagram'] ?? ($row['details']['instagram'] ?? null);
        $row['linkedin']  = $social['linkedin']  ?? ($row['details']['linkedin']  ?? null);

        // --- NORMALIZAÇÃO DE LOCALIZAÇÃO ---
        $row['city'] = $row['profile_city'] ?: ($row['user_city'] ?: '');
        $row['state'] = $row['profile_state'] ?: ($row['user_state'] ?: '');

        // --- LÓGICA DE NOMES (SENSÍVEL A HIERARQUIA) ---
        // 1. Nome da Empresa (Vem da Conta compartilhada ou do JSON)
        $row['company_name'] = $row['account_trade_name'] ?: ($row['details']['trade_name'] ?? $row['corporate_name']);
        
        // 2. Nome de Exibição Principal (Se for Empresa, mostra o nome da empresa. Se for motorista, o nome dele)
        $row['display_name'] = ($row['user_type'] === 'COMPANY') 
            ? $row['company_name'] 
            : $row['user_name'];

        // 3. Campos de compatibilidade com o Front (painel antigo)
        //    - name: sempre algum nome de exibição
        //    - trade_name: prioriza nome de empresa, depois nome do usuário
        $row['name'] = $row['display_name'];
        $row['trade_name'] = $row['company_name'] ?: $row['user_name'];

        // 4. Normaliza URLs de avatar e capa para o front (absolutas quando começarem com "/")
        if (!empty($row['avatar_url']) || !empty($row['cover_url'])) {
            $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'http';
            $host = $_SERVER['HTTP_HOST'] ?? ($_ENV['APP_URL'] ?? '');
            $baseUrl = is_string($host) && str_starts_with($host, 'http')
                ? rtrim($host, '/')
                : rtrim(($scheme . '://' . $host), '/');

            if (!empty($row['avatar_url']) && str_starts_with($row['avatar_url'], '/')) {
                $row['avatar_url'] = $baseUrl . $row['avatar_url'];
            }
            if (!empty($row['cover_url']) && str_starts_with($row['cover_url'], '/')) {
                $row['cover_url'] = $baseUrl . $row['cover_url'];
            }
        }

        // Injeta as permissões
        $row['permissions'] = $this->resolvePermissions($row['user_type'], $row['details']['business_segment'] ?? 'general');

        $row['is_available'] = ($row['availability_status'] ?? 'available') === 'available' ? 1 : 0;

        // Verifica se o usuário tem identidade confirmada (módulo identity_verification ativo)
        $stmt = $this->db->prepare("
            SELECT 1 FROM user_modules 
            WHERE user_id = :user_id 
            AND module_key = 'identity_verification' 
            AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $row['identity_confirmed'] = $stmt->fetch() ? 1 : 0;

        return $row;
    }

    private function resolvePermissions($type, $segment) {
        if ($type === 'DRIVER') {
            return ['label' => 'Motorista', 'features' => ['marketplace_seller']];
        }
        return match($segment) {
            'shipper'   => ['label' => 'Embarcador', 'features' => ['post_freight', 'request_quote']],
            'logistics' => ['label' => 'Transportadora', 'features' => ['post_freight', 'respond_quote']],
            default     => ['label' => 'Empresa Geral', 'features' => ['ads']],
        };
    }

    public function runVerificationProcess($userId) {
        // 1. Busca os dados usando $this (o próprio repositório)
        $user = $this->getProfileData($userId); 
        
        if (!$user) return null;

        $points = 0;
        $fieldsToTrack = ['name', 'whatsapp', 'avatar_url', 'city', 'bio'];
        foreach ($fieldsToTrack as $f) {
            if (!empty($user[$f])) $points += 20;
        }

        $avg = (float)($user['rating_avg'] ?? 0);
        $count = (int)($user['rating_count'] ?? 0);
        $deservesBadge = ($points >= 80) || ($count >= 5 && $avg >= 4.5);
        $currentStatus = (int)($user['is_verified'] ?? 0);
        
        if ($deservesBadge && $currentStatus === 0) {
            // 2. Atualiza o banco diretamente aqui
            $stmt = $this->db->prepare("UPDATE user_profiles SET is_verified = 1 WHERE user_id = :id");
            $stmt->execute([':id' => $userId]);

            $this->sendVerificationNotify($userId, true);

        } elseif (!$deservesBadge && $currentStatus === 1) {
            $stmt = $this->db->prepare("UPDATE user_profiles SET is_verified = 0 WHERE user_id = :id");
            $stmt->execute([':id' => $userId]);
        }

        return (object)['is_verified' => $deservesBadge, 'score' => $points];
    }

    public function autoApproveProfile($userId) {
        $user = $this->getProfileData($userId);
        if (!$user) return null;

        $userType = $user['user_type'] ?? 'DRIVER';
        $score = 0;
        $maxScore = 0;

        if ($userType === 'COMPANY') {
            $companyFields = [
                'company_name' => 25,
                'document' => 25,
                'city' => 15,
                'state' => 10,
                'phone' => 10,
                'bio' => 10,
                'avatar_url' => 5
            ];
            foreach ($companyFields as $field => $weight) {
                $maxScore += $weight;
                $value = $user[$field] ?? ($user['details'][lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $field))))] ?? '');
                if (!empty($value)) $score += $weight;
            }
        } else {
            $driverFields = [
                'name' => 25,
                'whatsapp' => 20,
                'city' => 15,
                'state' => 10,
                'avatar_url' => 15,
                'bio' => 10,
                'vehicle_type' => 5
            ];
            foreach ($driverFields as $field => $weight) {
                $maxScore += $weight;
                $value = $user[$field] ?? '';
                if (!empty($value)) $score += $weight;
            }
        }

        $percentage = $maxScore > 0 ? round(($score / $maxScore) * 100) : 0;
        
        $currentStatus = $user['verification_status'] ?? 'none';
        $newStatus = ($percentage >= 60) ? 'verified' : 'pending';

        if ($newStatus !== $currentStatus) {
            $stmt = $this->db->prepare("UPDATE user_profiles SET verification_status = :status WHERE user_id = :id");
            $stmt->execute([':status' => $newStatus, ':id' => $userId]);
        }

        return (object)[
            'verification_status' => $newStatus,
            'profile_completion' => $percentage,
            'score' => $score,
            'max_score' => $maxScore
        ];
    }

    private function sendVerificationNotify($userId, $status) {
        try {
            $notif = new \App\Controllers\NotificationController($this->db);
            if ($status && method_exists($notif, 'notify')) {
                $notif->notify($userId, "🎉 Perfil Verificado!", "Seu selo de confiança foi ativado.");
            }
        } catch (\Throwable $e) {}
    }

    public function getReviewStats($userId) {
        // 1. Query com tratamento de NULL (IFNULL) 
        // Garante que se não houver reviews, retorne 0 em vez de null
        $sql = "SELECT 
                    COUNT(*) as total, 
                    IFNULL(AVG(rating), 0) as media 
                FROM reviews 
                WHERE target_id = :userId 
                AND status = 'published'"; // Assume que você tem um controle de moderação

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':userId' => $userId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // 2. Formatação para o Front-end
            $stats['media'] = round((float)$stats['media'], 1);
            $stats['total'] = (int)$stats['total'];

            // 3. Sincronização Opcional (Cache)
            // Se a média mudar, você pode querer atualizar o campo rating_avg 
            // na tabela 'users' para que listagens globais fiquem ultra rápidas
            $this->syncUserRating($userId, $stats['media'], $stats['total']);

            return $stats;

        } catch (\PDOException $e) {
            error_log("Erro ao buscar stats de review: " . $e->getMessage());
            return ['total' => 0, 'media' => 0];
        }
    }

    public function incrementStats($userId, $type = 'VIEW') {
        $column = ($type === 'CLICK') ? 'clicks_count' : 'views_count';
        
        if (!$userId) return false;

        try {
            $sql = "UPDATE user_profiles SET {$column} = {$column} + 1 WHERE user_id = :uid";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([':uid' => (int)$userId]);
        } catch (\Exception $e) {
            // Log o erro no arquivo do servidor para debug
            error_log("Erro ao incrementar stats: " . $e->getMessage());
            return false; 
        }
    }

    /**
     * Atualiza o cache de notas na tabela users
     * Isso evita que a busca de motoristas precise fazer JOIN com reviews
     */
    private function syncUserRating($userId, $media, $total) {
        $sql = "UPDATE users SET rating_avg = ?, rating_count = ? WHERE id = ?";
        $this->db->prepare($sql)->execute([$media, $total, $userId]);
    }
 
    public function setVerified($userId, $status = 1) {
        // 1. Garantimos que o status seja um inteiro (0 ou 1)
        $statusValue = (int)$status;

        // 2. Atualizamos is_verified e a data de modificação
        // No seu dump, a coluna updated_at (35) registra a última atividade
        $sql = "UPDATE users SET 
                    is_verified = :status,
                    updated_at = NOW()
                WHERE id = :id 
                AND deleted_at IS NULL";

        try {
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                ':status' => $statusValue,
                ':id'     => $userId
            ]);

            // Log de segurança (opcional, mas recomendado para ações administrativas)
            if ($success) {
                error_log("User ID $userId teve status de verificação alterado para $statusValue");
            }

            return $success;

        } catch (\PDOException $e) {
            error_log("Erro ao definir verificação do usuário $userId: " . $e->getMessage());
            return false;
        }
    }
 
    public function updateProfileImage($userId, $url, $type = 'avatar') {
        // 1. Mapeamento rigoroso de colunas para evitar SQL Injection e erros de nomeclatura
        // No seu dump: avatar_url (coluna 6) e cover_url (coluna 8)
        $allowedColumns = [
            'avatar' => 'avatar_url',
            'banner' => 'cover_url', // Mapeia 'banner' (front) para 'cover_url' (banco)
            'cover'  => 'cover_url'
        ];

        $column = $allowedColumns[$type] ?? 'avatar_url';

        // 2. Query preparada
        // Usamos a variável $column de forma controlada após a validação do whitelist
        $sql = "UPDATE user_profiles SET 
                    $column = :url, 
                    updated_at = NOW() 
                WHERE user_id = :id";

        try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':url' => $url,
                ':id'  => $userId
            ]);
        } catch (\PDOException $e) {
            error_log("Erro ao atualizar imagem de perfil (Tipo: $type): " . $e->getMessage());
            return false;
        }
    }

    public function getProfileBySlug($slug) {
        // 1. SQL Selecionando apenas o necessário para exibição pública
        // Evitamos p.* para não vazar IDs internos e timestamps desnecessários
        $sql = "SELECT 
                    u.id, u.name, u.whatsapp, u.is_verified, u.role, u.user_type,
                    u.rating_avg, u.rating_count,
                    u.city as user_city, u.state as user_state,
                    p.bio, p.avatar_url, p.cover_url, p.vehicle_type, p.body_type,
                    p.availability_status, p.extended_attributes
                FROM user_profiles p
                JOIN users u ON p.user_id = u.id
                WHERE p.slug = :slug 
                AND u.deleted_at IS NULL 
                AND u.status = 'active'
                LIMIT 1";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':slug' => $slug]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$profile) return null;

            // 2. Normalização de Tipos e Fallbacks
            $profile['is_verified'] = (int)$profile['is_verified'] === 1;
            $profile['rating_avg']  = (float)($profile['rating_avg'] ?? 0);
            $profile['rating_count']= (int)($profile['rating_count'] ?? 0);

            // 3. Localização inteligente (Perfil Profissional > Cadastro Base)
            $profile['city']  = $profile['user_city'] ?: 'Brasil';
            $profile['state'] = $profile['user_state'] ?: '--';

            // 4. Tratamento de Atributos Extras (JSON)
            $profile['extras'] = [];
            if (!empty($profile['extended_attributes'])) {
                $decoded = json_decode($profile['extended_attributes'], true);
                $profile['extras'] = is_array($decoded) ? $decoded : [];
            }

            // 5. Disponibilidade do perfil
            $profile['is_available'] = ($profile['availability_status'] ?? 'available') === 'available' ? 1 : 0;

            return $profile;

        } catch (\PDOException $e) {
            error_log("Erro getProfileBySlug ao buscar perfil pelo slug ($slug): " . $e->getMessage());
            return null;
        }
    }

    public function isSlugAvailable($slug, $excludeUserId = 0) {
        // 1. Sanitização do Slug
        // Garante que estamos checando a disponibilidade de uma string limpa
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9-]/', '', $slug);

        if (empty($slug)) return false;

        // 2. Query de contagem
        // Verificamos se existe algum perfil com esse slug que NÃO pertença ao usuário atual
        $sql = "SELECT COUNT(*) FROM user_profiles 
                WHERE slug = :slug 
                AND user_id != :userId";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':slug'   => $slug,
                ':userId' => $excludeUserId
            ]);

            // Retorna true se a contagem for 0 (está disponível)
            return (int)$stmt->fetchColumn() === 0;

        } catch (\PDOException $e) {
            error_log("Erro ao verificar disponibilidade de slug ($slug): " . $e->getMessage());
            return false; // Por segurança, assume que não está disponível em caso de erro
        }
    }

    public function softDelete($userId) {
        try {
            if (!$this->db->inTransaction()) $this->db->beginTransaction();

            $timestamp = time();

            // 1. Libera o Slug no perfil
            // Renomeamos para 'slug-deleted-1706450000' para evitar duplicidade futura
            $sqlProfile = "UPDATE user_profiles 
                        SET slug = CONCAT(slug, '-deleted-', :ts),
                            updated_at = NOW() 
                        WHERE user_id = :id";
            
            $this->db->prepare($sqlProfile)->execute([
                ':ts' => $timestamp,
                ':id' => $userId
            ]);

            // 2. Desativa o Usuário e "esconde" o e-mail
            // Concatenamos o e-mail com o timestamp para liberar o e-mail original para novo cadastro
            $sqlUser = "UPDATE users SET 
                            status = 'inactive', 
                            email = CONCAT(email, '.deleted.', :ts),
                            deleted_at = NOW(),
                            updated_at = NOW() 
                        WHERE id = :id";

            $success = $this->db->prepare($sqlUser)->execute([
                ':ts' => $timestamp,
                ':id' => $userId
            ]);

            $this->db->commit();
            return $success;

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro Crítico no SoftDelete do Usuário $userId: " . $e->getMessage());
            return false;
        }
    }

    public function updatePassword($userId, $hashedPassword) {
        // 1. SQL atualiza a senha e limpa TODOS os campos de recuperação por segurança
        // No seu dump, temos reset_token (coluna 32) e token_expires (coluna 33)
        $sql = "UPDATE users SET 
                    password = :password, 
                    reset_token = NULL, 
                    token_expires = NULL,
                    updated_at = NOW()
                WHERE id = :id 
                AND deleted_at IS NULL";
        
        try {
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                ':password' => $hashedPassword,
                ':id'       => $userId
            ]);

            // 2. Log de segurança
            if ($success) {
                error_log("Senha alterada com sucesso para o usuário ID: $userId");
            }

            return $success;

        } catch (\PDOException $e) {
            error_log("Erro crítico ao atualizar senha (ID $userId): " . $e->getMessage());
            return false;
        }
    }

    public function updateSubscription($userId, $planId, $days = 30, $planType = 'monthly') {
        // 1. SQL dinâmico para aceitar diferentes períodos de expiração
        // Usamos o INTERVAL para calcular a data de expiração baseada no parâmetro $days
        $sql = "UPDATE users SET 
                    plan_id = :plan_id, 
                    is_subscriber = 1, 
                    plan_type = :plan_type,
                    plan_expires_at = DATE_ADD(NOW(), INTERVAL :days DAY),
                    updated_at = NOW() 
                WHERE id = :id 
                AND deleted_at IS NULL";
        
        try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':plan_id'   => $planId,
                ':plan_type' => $planType,
                ':days'      => (int)$days,
                ':id'        => $userId
            ]);
        } catch (\PDOException $e) {
            error_log("Erro ao atualizar assinatura (User: $userId): " . $e->getMessage());
            return false;
        }
    }

    public function saveResetToken($userId, $token, $expires) {
        // 1. SQL focado em segurança e integridade
        // Usamos o updated_at para rastrear quando a solicitação de recuperação foi feita
        $sql = "UPDATE users SET 
                    reset_token = :token, 
                    token_expires = :expires,
                    updated_at = NOW()
                WHERE id = :id 
                AND deleted_at IS NULL 
                AND status = 'active'";

        try {
            $stmt = $this->db->prepare($sql);
            
            // 2. Execução com parâmetros nomeados
            $success = $stmt->execute([
                ':token'   => $token,
                ':expires' => $expires, // Formato esperado: 'YYYY-MM-DD HH:MM:SS'
                ':id'      => $userId
            ]);

            // 3. Verificação de impacto
            // Se o usuário não estiver ativo ou não existir, o rowCount será 0
            return $success && $stmt->rowCount() > 0;

        } catch (\PDOException $e) {
            error_log("Erro ao salvar reset_token (User: $userId): " . $e->getMessage());
            return false;
        }
    }

    public function validateResetToken($email, $token) {
        // 1. SQL robusto com verificação de expiração e status da conta
        // Garantimos que o usuário não esteja deletado e esteja ativo
        $sql = "SELECT id, email, name 
                FROM users 
                WHERE email = :email 
                AND reset_token = :token 
                AND token_expires > NOW() 
                AND deleted_at IS NULL 
                AND status = 'active'
                LIMIT 1";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':email' => strtolower(trim($email)),
                ':token' => $token
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Retorna o array com os dados do usuário se válido, ou null se inválido/expirado
            return $user ?: null;

        } catch (\PDOException $e) {
            error_log("Erro ao validar token de reset ($email): " . $e->getMessage());
            return null;
        }
    }

    public function clearResetToken($userId) {
        // 1. SQL limpa os tokens e atualiza o carimbo de data/hora
        $sql = "UPDATE users SET 
                    reset_token = NULL, 
                    token_expires = NULL,
                    updated_at = NOW() 
                WHERE id = :id 
                AND deleted_at IS NULL";

        try {
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([':id' => $userId]);

            // Retorna true se a query rodou e encontrou o usuário
            return $success && $stmt->rowCount() > 0;

        } catch (\PDOException $e) {
            error_log("Erro ao limpar reset_token (User: $userId): " . $e->getMessage());
            return false;
        }
    }

    public function updateLastLogin($userId) {
        // 1. SQL focado na coluna de rastreio de acesso
        // Não alteramos o 'updated_at' aqui para preservar a data da última 
        // modificação real de dados do perfil (como troca de senha ou bio).
        $sql = "UPDATE users SET 
                    last_login = NOW() 
                WHERE id = :id 
                AND deleted_at IS NULL";

        try {
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([':id' => $userId]);

            // Opcional: Você pode querer registrar um log de auditoria aqui
            // se o seu sistema exigir histórico de acessos (tabela user_logins)

            return $success && $stmt->rowCount() > 0;

        } catch (\PDOException $e) {
            error_log("Erro ao atualizar last_login (User: $userId): " . $e->getMessage());
            return false;
        }
    }

    public function saveQuickProfile($userId, $userData, $profileData) {
        try {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
            }

            // 1. Reutilizamos o updateBasicInfo (que já limpa o whatsapp e document)
            // Isso garante que o nome e o telefone na tabela 'users' estejam corretos
            $this->updateBasicInfo($userId, [
                'name'     => $userData['name'] ?? null,
                'whatsapp' => $userData['whatsapp'] ?? $userData['phone'] ?? null
            ]);

            // 2. Upsert no Perfil (Cria se não existir, atualiza se existir)
            // Incluímos city e state, garantindo que o motorista tenha localização básica
            $sqlProfile = "INSERT INTO user_profiles (user_id, city, state, created_at, updated_at) 
                        VALUES (:user_id, :city, :state, NOW(), NOW()) 
                        ON DUPLICATE KEY UPDATE 
                            city = VALUES(city), 
                            state = VALUES(state), 
                            updated_at = NOW()";
            
            $this->db->prepare($sqlProfile)->execute([
                ':user_id' => $userId,
                ':city'    => $profileData['city'] ?? null,
                ':state'   => $profileData['state'] ?? null
            ]);

            $this->db->commit();
            return true;

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Erro no saveQuickProfile (User ID $userId): " . $e->getMessage());
            throw $e;
        }
    }

    public function getDashboardStats($userId) {
        $sql = "SELECT 
                    COUNT(id) as total_fretes,
                    IFNULL(SUM(views_count), 0) as total_views,
                    IFNULL(SUM(clicks_count), 0) as total_clicks,
                    IFNULL(SUM(contact_requests_count), 0) as total_leads
                FROM freights 
                WHERE user_id = :u_id AND deleted_at IS NULL";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':u_id' => (int)$userId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function findBySlug($slug) {
        if (empty($slug)) return null;

        $sql = "SELECT 
                    u.id, 
                    u.name as user_name, 
                    u.role,
                    u.user_type, 
                    u.is_verified,
                    u.verified_until,
                    u.rating_avg,
                    u.rating_count,
                    u.whatsapp, 
                    u.city as user_city, 
                    u.state as user_state,
                    u.created_at as member_since,
                    up.bio, 
                    up.avatar_url, 
                    up.cover_url, 
                    up.vehicle_type, 
                    up.body_type,
                    up.availability_status,
                    up.instagram as profile_instagram,
                    up.website as profile_website,
                    up.extended_attributes,
                    a.trade_name,
                    a.corporate_name,
                    a.document_number as cnpj
                FROM user_profiles up
                INNER JOIN users u ON up.user_id = u.id
                LEFT JOIN accounts a ON u.account_id = a.id
                WHERE up.slug = :slug 
                AND u.status = 'active' 
                AND u.deleted_at IS NULL
                LIMIT 1";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':slug' => $slug]);
            $profile = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$profile) return null;

            // Decodifica o JSON de atributos
            $details = [];
            if (!empty($profile['extended_attributes'])) {
                $details = json_decode($profile['extended_attributes'], true) ?: [];
            }

            // Nome de exibição: Prioriza JSON -> Conta -> Usuário
            $profile['display_name'] = $details['company_name'] 
                ?? ($profile['trade_name'] 
                ?? ($profile['corporate_name'] ?? $profile['user_name']));

            // Mapeia name para compatibilidade com frontend
            $profile['name'] = $profile['user_name'];
            
            // Mapeia banner_url para cover_url
            $profile['banner_url'] = $profile['cover_url'];
            
            // Business type do JSON
            $profile['business_type'] = $details['business_type'] ?? null;

            // Normalização de campos para o Front-end
            $profile['city'] = $profile['user_city'];
            $profile['state'] = $profile['user_state'];

            // Redes sociais (compatível com JSON antigo e novo)
            $social = is_array($details['social'] ?? null) ? $details['social'] : [];
            $profile['instagram'] = $profile['profile_instagram']
                ?? ($social['instagram'] ?? ($details['instagram'] ?? null));
            $profile['linkedin'] = $social['linkedin'] ?? ($details['linkedin'] ?? null);
            $profile['website'] = $profile['profile_website'] ?? ($details['website'] ?? null);

            // Disponibilidade pública
            $status = $profile['availability_status'] ?? '';
            $profile['availability_status'] = in_array($status, ['available', 'busy', 'offline']) ? $status : 'available';
            $profile['is_available'] = $profile['availability_status'] === 'available' ? 1 : 0;

            // Verifica se o usuário tem identidade confirmada
            $stmt = $this->db->prepare("
                SELECT 1 FROM user_modules 
                WHERE user_id = :user_id 
                AND module_key = 'identity_verification' 
                AND status = 'active'
                LIMIT 1
            ");
            $stmt->execute([':user_id' => $profile['id']]);
            $profile['identity_confirmed'] = $stmt->fetch() ? 1 : 0;

            return $profile;

        } catch (\Exception $e) {
            error_log("❌ ERRO findBySlug: " . $e->getMessage());
            return null;
        }
    }

    public function getUserTypeAndName($id) {
        $sql = "SELECT id, user_type, name FROM users WHERE id = :id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => (int)$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function listAll() {
        // Retorna apenas o essencial para não pesar a requisição
        return $this->db->query("
            SELECT id, name, email, role, company_name, status 
            FROM users 
            WHERE role = 'company' 
            ORDER BY company_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // Caso seu Controller use o método listUsersByRole:
    public function listUsersByRole($role = '%', $search = '%') {
        $stmt = $this->db->prepare("
            SELECT id, name, email, role, company_name, status 
            FROM users 
            WHERE role LIKE :role 
            AND (name LIKE :search OR email LIKE :search OR company_name LIKE :search)
            ORDER BY created_at DESC
        ");
        $stmt->execute([
            'role' => $role,
            'search' => "%$search%"
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
}
