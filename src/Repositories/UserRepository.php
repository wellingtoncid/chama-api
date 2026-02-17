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

            // 1. Identificadores
            $tempDoc = 'TEMP_' . time() . '_' . rand(100, 999); 
            $accountUuid = bin2hex(random_bytes(16)); 

            // 2. Inserção na tabela ACCOUNTS
            $sqlAccount = "INSERT INTO accounts (uuid, document_type, document_number, corporate_name, status) 
                            VALUES (:uuid, :doc_type, :doc_num, :name, 'active')";
            
            $stmtAcc = $this->db->prepare($sqlAccount);
            $stmtAcc->execute([
                ':uuid'     => $accountUuid,
                ':doc_type' => $data['document_type'] ?? 'CPF',
                ':doc_num'  => $data['document'] ?? $tempDoc,
                ':name'     => $data['name']
            ]);
            
            $accountId = $this->db->lastInsertId();

            // 3. Buscar Role ID
            $roleSlug = strtolower($data['role'] ?? 'driver');
            $stmtRole = $this->db->prepare("SELECT id FROM roles WHERE slug = :slug LIMIT 1");
            $stmtRole->execute([':slug' => $roleSlug]);
            $roleId = $stmtRole->fetchColumn() ?: 2;

            // 4. Criar o Usuário vinculado à Account
            $sqlUser = "INSERT INTO users (
                            name, email, password, whatsapp, 
                            account_id, role_id, role, user_type, status, plan_id
                        ) VALUES (
                            :name, :email, :password, :whatsapp, 
                            :account_id, :role_id, :role, :user_type, 'active', 1
                        )";
            
            $stmtUser = $this->db->prepare($sqlUser);
            $stmtUser->execute([
                ':name'       => $data['name'],
                ':email'      => $data['email'],
                ':password'   => $data['password'],
                ':whatsapp'   => $data['whatsapp'],
                ':account_id' => $accountId,
                ':role_id'    => $roleId,
                ':role'       => $data['role'],
                ':user_type'  => $data['user_type'] ?? ($roleSlug === 'driver' ? 'motorista' : 'empresa')
            ]);

            $userId = $this->db->lastInsertId(); // AGORA SIM o $userId existe!

            // 5. CRIAR O PERFIL DA EMPRESA (Agora que temos o $userId)
            if ($roleSlug === 'company') {
                $sqlCompany = "INSERT INTO companies (
                    owner_id, name_fantasy, corporate_name, cnpj, business_type, coverage_area
                ) VALUES (
                    :owner_id, :name, :name, :cnpj, 'pendente', 'a definir'
                )";

                $stmtComp = $this->db->prepare($sqlCompany);
                $stmtComp->execute([
                    ':owner_id' => $userId,
                    ':name'     => $data['name'],
                    ':cnpj'     => $data['document'] ?? null
                ]);
            }    

            // 6. Criar Perfil Público (user_profiles)
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $data['name']))) . '-' . $userId;
            $sqlProfile = "INSERT INTO user_profiles (user_id, slug) VALUES (:user_id, :slug)";
            $this->db->prepare($sqlProfile)->execute([':user_id' => $userId, ':slug' => $slug]);

            $this->db->commit();
            return $userId;

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
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
                    u.id, u.name, u.email, u.whatsapp, u.role, u.user_type, u.company_id, u.account_id,
                    a.document_number as document, -- Busca o documento da conta vinculada
                    p.bio, p.avatar_url, p.cover_url, p.slug, p.vehicle_type, p.body_type,
                    c.name_fantasy as company_name, 
                    c.cnpj as company_cnpj
                FROM users u
                LEFT JOIN accounts a ON u.account_id = a.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                LEFT JOIN companies c ON u.id = c.owner_id
                WHERE u.id = :id AND u.deleted_at IS NULL
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => (int)$id]);
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) return null;

        // 2. Garante que os campos JSON ou booleanos sejam formatados
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

        // 1. Preparamos a variação apenas numérica para o WhatsApp
        $onlyNumbers = preg_replace('/\D/', '', $identifier);

        /**
         * 2. SQL Otimizado para o Novo Modelo (Alto Padrão)
         * - Buscamos o slug da role para o Auth::hasRole funcionar
         * - Buscamos o account_id para o multi-tenancy (SaaS)
         * - Mantemos o avatar do perfil
         */
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
                AND (u.deleted_at IS NULL OR u.deleted_at = '0000-00-00 00:00:00')
                LIMIT 1";

        try {
            $stmt = $this->db->prepare($sql);
            
            $stmt->execute([
                ':email'    => $identifier,
                ':whatsapp' => !empty($onlyNumbers) ? $onlyNumbers : $identifier
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // 3. Tipagem Garantida (Essencial para o Frontend/React não bugar)
                $user['id'] = (int)$user['id'];
                $user['account_id'] = $user['account_id'] ? (int)$user['account_id'] : null;
                $user['role_id'] = $user['role_id'] ? (int)$user['role_id'] : null;
                $user['plan_id'] = isset($user['plan_id']) ? (int)$user['plan_id'] : 1;
                
                // Manter compatibilidade com seu Auth.php antigo se necessário
                $user['role'] = $user['role_slug']; 
            }

            return $user ?: null;

        } catch (\PDOException $e) {
            error_log("Erro Crítico em findByEmailOrWhatsapp: " . $e->getMessage());
            return null;
        }
    }
    
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
    public function updateProfileFields($userId, $data) {
        try {
            if (!$this->db->inTransaction()) $this->db->beginTransaction();

            $nullIfEmpty = fn($val) => (isset($val) && trim((string)$val) !== '') ? trim((string)$val) : null;

            // 1. Atualiza Usuário (Dados básicos)
            $sqlUser = "UPDATE users SET 
                            name = COALESCE(:name, name), 
                            whatsapp = :whatsapp,
                            city = :city,
                            state = :state,
                            updated_at = NOW()
                        WHERE id = :id";
            
            $this->db->prepare($sqlUser)->execute([
                ':name'     => $nullIfEmpty($data['name'] ?? $data['name_fantasy'] ?? null),
                ':whatsapp' => preg_replace('/\D/', '', $data['whatsapp'] ?? ''),
                ':city'     => $nullIfEmpty($data['city'] ?? null),
                ':state'    => $nullIfEmpty($data['state'] ?? null),
                ':id'       => $userId
            ]);

            // 2. Atualiza Perfil (Bio, Slug, Imagens e Atributos Dinâmicos)
            $safeSlug = isset($data['slug']) ? preg_replace('/[^a-z0-9\-]/', '', strtolower($data['slug'])) : null;
            
            // OPCIONAL: Se quiser manter o JSON extended_attributes para histórico, inclua aqui
            $extrasMapping = ['plate', 'antt', 'anos_experiencia', 'instagram', 'website'];
            $newExtras = [];
            foreach ($extrasMapping as $field) {
                if (isset($data[$field])) {
                    $val = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
                    $newExtras[$field] = ($val === '') ? null : $val;
                }
            }

            $sqlProfile = "UPDATE user_profiles SET 
                            bio = :bio, 
                            slug = COALESCE(:slug, slug),
                            avatar_url = COALESCE(:avatar, avatar_url),
                            cover_url = COALESCE(:cover, cover_url),
                            extended_attributes = JSON_MERGE_PATCH(COALESCE(extended_attributes, '{}'), :json),
                            updated_at = NOW()
                        WHERE user_id = :id";
            
            $this->db->prepare($sqlProfile)->execute([
                ':bio'    => $nullIfEmpty($data['bio'] ?? $data['description'] ?? null),
                ':slug'   => $safeSlug,
                ':avatar' => $nullIfEmpty($data['avatar_url'] ?? null),
                ':cover'  => $nullIfEmpty($data['cover_url'] ?? null),
                ':json'   => json_encode($newExtras, JSON_UNESCAPED_UNICODE),
                ':id'     => $userId
            ]);

            // 3. Lógica de Empresa (Tabela 'companies')
            if (isset($data['role']) && in_array(strtoupper($data['role']), ['COMPANY', 'TRANSPORTADORA', 'LOGISTICS'])) {
                // Garante o vínculo (users.company_id) e retorna o ID da empresa
                $companyId = $this->linkOrCreateCompany($userId, $data);
                
                // Salva TODOS os campos que você listou (CNPJ, Endereço, Certificações, frotas, etc)
                $this->updateCompanyDetails($companyId, $data);
            }

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro no UserRepository: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * getProfileData atualizado para trazer os atributos JSON
     */
    public function getProfileData($userId) {
        $sql = "SELECT 
                u.id, u.name, u.email, u.whatsapp, u.role, u.status,
                u.plan_id, u.is_verified, u.company_id, u.created_at,
                u.is_subscriber, u.plan_expires_at, u.user_type, u.plan_type,
                u.rating_avg, u.rating_count, u.balance,
                u.city as user_city, u.state as user_state,
                a.document_number as document, 
                p.avatar_url, p.cover_url, 
                p.bio, p.slug, p.vehicle_type, p.body_type, p.verification_status, p.extended_attributes,
                -- CAMPOS DA EMPRESA (Acréscimos necessários)
                c.name_fantasy as company_name, 
                c.cnpj as company_cnpj,
                c.corporate_name,
                c.main_contact_name,
                c.business_type, 
                c.operation_type,
                c.postal_code,
                c.address,
                c.address_number,
                c.neighborhood,
                c.city as company_city,
                c.state as company_state,
                c.transport_services,
                c.logistics_services,
                c.coverage_area,
                c.website_url
            FROM users u
            LEFT JOIN accounts a ON u.account_id = a.id
            LEFT JOIN user_profiles p ON u.id = p.user_id
            LEFT JOIN companies c ON u.id = c.owner_id
            WHERE u.id = :id AND u.deleted_at IS NULL
            LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;

        // 1. Processa Atributos Dinâmicos (JSON do Perfil)
        if (!empty($row['extended_attributes'])) {
            $extras = json_decode($row['extended_attributes'], true);
            if (is_array($extras)) {
                foreach ($extras as $key => $value) {
                    if (!isset($row[$key])) $row[$key] = $value;
                }
            }
        }

        // 2. Processa Campos de Serviços da Empresa (Convertendo string JSON para Array do React)
        $row['transport_services'] = !empty($row['transport_services']) ? json_decode($row['transport_services'], true) : [];
        $row['logistics_services'] = !empty($row['logistics_services']) ? json_decode($row['logistics_services'], true) : [];

        /// 3. Normalização de tipos (FORMA SEGURA)
        $row['is_verified'] = (isset($row['is_verified']) && (int)$row['is_verified'] === 1);
        $row['is_subscriber'] = (isset($row['is_subscriber']) && (int)$row['is_subscriber'] === 1);

        $row['rating_avg'] = round((float)($row['rating_avg'] ?? 0), 1);
        $row['balance'] = (float)($row['balance'] ?? 0);

        // 4. Fallbacks inteligentes para Localização
        // Se for empresa, prioriza a cidade da empresa, senão a do usuário
        $row['display_city'] = $row['company_city'] ?: ($row['user_city'] ?: 'Brasil');
        $row['display_state'] = $row['company_state'] ?: ($row['user_state'] ?: '--');

        // 5. Aliases para o Front-end
        $row['avatar_url'] = $row['avatar_url'] ?: null;
        $row['cover_url'] = $row['cover_url'] ?: null;
        $row['banner_url'] = $row['cover_url']; 
        
        $row['display_name'] = ($row['user_type'] === 'COMPANY' && !empty($row['company_name'])) 
            ? $row['company_name'] 
            : $row['name'];

        return $row;
    }

    public function linkOrCreateCompany($ownerId, $data) {
        // 1. Busca empresa existente pelo owner_id
        $stmt = $this->db->prepare("SELECT id FROM companies WHERE owner_id = :owner_id LIMIT 1");
        $stmt->execute([':owner_id' => $ownerId]);
        $company = $stmt->fetch();

        if ($company) {
            $companyId = $company['id'];
        } else {
            // 2. Cria se não existir
            $sqlIn = "INSERT INTO companies (owner_id, name_fantasy, status, created_at) 
                    VALUES (:owner_id, :name, 'active', NOW())";
            $this->db->prepare($sqlIn)->execute([
                ':owner_id' => $ownerId,
                ':name'     => $data['name_fantasy'] ?? $data['name']
            ]);
            $companyId = $this->db->lastInsertId();
        }

        // 3. VÍNCULO: Garante que o users.company_id está preenchido
        $this->db->prepare("UPDATE users SET company_id = :cid WHERE id = :uid")
                ->execute([':cid' => $companyId, ':uid' => $ownerId]);

        return $companyId;
    }

    private function updateCompanyDetails($companyId, $data) {
        $nullIfEmpty = fn($val) => (isset($val) && trim((string)$val) !== '') ? trim((string)$val) : null;
        
        // Tratamento de campos JSON para o Banco
        $certifications = isset($data['certifications']) ? json_encode($data['certifications'], JSON_UNESCAPED_UNICODE) : null;
        $fleet_types = isset($data['fleet_types']) ? json_encode($data['fleet_types'], JSON_UNESCAPED_UNICODE) : null;
        $infra = isset($data['storage_infrastructure']) ? json_encode($data['storage_infrastructure'], JSON_UNESCAPED_UNICODE) : null;

        $sql = "UPDATE companies SET 
                    name_fantasy = :nf,
                    description = :desc,
                    cnpj = :cnpj,
                    postal_code = :zip,
                    address = :addr,
                    address_number = :num,
                    neighborhood = :neigh,
                    city = :city,
                    state = :state,
                    business_type = :bt,
                    coverage_area = :ca,
                    website_url = :url,
                    certifications = :certs,
                    fleet_types = :fleets,
                    storage_infrastructure = :infra,
                    updated_at = NOW()
                WHERE id = :id";

        $this->db->prepare($sql)->execute([
            ':nf'    => $data['name_fantasy'] ?? $data['name'] ?? null,
            ':desc'  => $data['description'] ?? $data['bio'] ?? null,
            ':cnpj'  => preg_replace('/\D/', '', $data['cnpj'] ?? ''),
            ':zip'   => preg_replace('/\D/', '', $data['postal_code'] ?? ''),
            ':addr'  => $nullIfEmpty($data['address'] ?? null),
            ':num'   => $nullIfEmpty($data['address_number'] ?? null),
            ':neigh' => $nullIfEmpty($data['neighborhood'] ?? null),
            ':city'  => $nullIfEmpty($data['city'] ?? null),
            ':state' => $nullIfEmpty($data['state'] ?? null),
            ':bt'    => $data['business_type'] ?? null,
            ':ca'    => $data['coverage_area'] ?? null,
            ':url'   => $data['website_url'] ?? $data['website'] ?? null,
            ':certs' => $certifications,
            ':fleets' => $fleet_types,
            ':infra' => $infra,
            ':id'    => $companyId
        ]);
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

            // 3. Notificação (Opcional deixar aqui ou no Controller)
            try {
                $notif = new \App\Controllers\NotificationController($this->db);
                $notif->notify($userId, "🎉 Perfil Verificado!", "Selo de confiança ativado.");
            } catch (\Throwable $e) {}

        } elseif (!$deservesBadge && $currentStatus === 1) {
            $stmt = $this->db->prepare("UPDATE user_profiles SET is_verified = 0 WHERE user_id = :id");
            $stmt->execute([':id' => $userId]);
        }

        return (object)['is_verified' => $deservesBadge, 'score' => $points];
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
                    u.name, u.whatsapp, u.is_verified, u.role, u.user_type,
                    u.rating_avg, u.rating_count,
                    u.city as user_city, u.state as user_state,
                    p.bio, p.avatar_url, p.cover_url, p.vehicle_type, p.body_type, 
                    p.city as profile_city, p.state as profile_state,
                    p.extended_attributes
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
            $profile['city']  = $profile['profile_city'] ?: ($profile['user_city'] ?: 'Brasil');
            $profile['state'] = $profile['profile_state'] ?: ($profile['user_state'] ?: '--');

            // 4. Tratamento de Atributos Extras (JSON)
            $profile['extras'] = [];
            if (!empty($profile['extended_attributes'])) {
                $decoded = json_decode($profile['extended_attributes'], true);
                $profile['extras'] = is_array($decoded) ? $decoded : [];
            }

            // Limpeza de campos de suporte
            unset(
                $profile['profile_city'], $profile['profile_state'], 
                $profile['user_city'], $profile['user_state'],
                $profile['extended_attributes']
            );

            return $profile;

        } catch (\PDOException $e) {
            error_log("Erro ao buscar perfil pelo slug ($slug): " . $e->getMessage());
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

    // @deprecated - verificar e remover 
    public function createCompanyRecord($userId, $name) {
        try {
            // 1. Cria a empresa
            $sql = "INSERT INTO companies (owner_id, name_fantasy, created_at) VALUES (:owner_id, :name, NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':owner_id' => $userId,
                ':name'     => $name
            ]);

            $companyId = $this->db->lastInsertId();

            // 2. Vincula a empresa ao usuário
            $updateSql = "UPDATE users SET company_id = :c_id WHERE id = :u_id";
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->execute([
                ':c_id' => $companyId,
                ':u_id' => $userId
            ]);

            return $companyId;
        } catch (\Exception $e) {
            // Loga o erro mas não trava o registro do usuário
            error_log("Erro ao criar registro de empresa: " . $e->getMessage());
            return false;
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
        $sql = "SELECT 
                    u.id, 
                    u.name, 
                    u.user_type, 
                    u.whatsapp, 
                    u.city, 
                    u.state, 
                    u.created_at,
                    up.bio, 
                    up.avatar_url, 
                    up.cover_url, 
                    up.vehicle_type, 
                    up.body_type, 
                    up.instagram, 
                    up.website,
                    up.private_data,
                    c.name_fantasy,
                    c.razao_social,
                    c.cnpj
                FROM users u
                INNER JOIN user_profiles up ON u.id = up.user_id
                LEFT JOIN companies c ON u.id = c.owner_id
                WHERE up.slug = :slug AND u.status = 'active'
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':slug' => $slug]);
        
        $profile = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($profile) {
            // Define um nome de exibição inteligente
            $profile['display_name'] = !empty($profile['name_fantasy']) 
                ? $profile['name_fantasy'] 
                : $profile['name'];
                
            // Decodifica dados extras se existirem
            if ($profile && !empty($profile['private_data'])) {
                $profile['details'] = json_decode($profile['private_data'], true);
            }
        }

        return $profile;
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
}