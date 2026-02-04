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

            // REMOVEMOS created_at e updated_at daqui, 
            // pois o banco já tem DEFAULT current_timestamp()
            $sqlUser = "INSERT INTO users (
                            name, email, password, whatsapp, role, 
                            user_type, status, plan_id
                        ) VALUES (
                            :name, :email, :password, :whatsapp, :role, 
                            :user_type, 'active', 1
                        )";
            
            $stmtUser = $this->db->prepare($sqlUser);
            $stmtUser->execute([
                ':name'      => $data['name'],
                ':email'     => $data['email'],
                ':password'  => $data['password'],
                ':whatsapp'  => $data['whatsapp'],
                ':role'      => $data['role'],
                ':user_type' => $data['user_type']
            ]);

            $userId = $this->db->lastInsertId();

            // Criar Perfil
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $data['name']))) . '-' . $userId;
            $sqlProfile = "INSERT INTO user_profiles (user_id, slug) VALUES (:user_id, :slug)";
            
            $this->db->prepare($sqlProfile)->execute([
                ':user_id' => $userId,
                ':slug'    => $slug
            ]);

            $this->db->commit();
            return $userId;

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // Isso vai mostrar se o erro mudou para outra coluna
            throw new Exception("Erro ao registrar: " . $e->getMessage());
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
        // 1. Usamos um LEFT JOIN para trazer dados do perfil e da empresa no mesmo tiro (Single Query)
        // Isso evita o problema de N+1 consultas no seu banco de dados.
        $sql = "SELECT 
                    u.*, 
                    p.bio, p.avatar_url, p.cover_url, p.slug, p.vehicle_type, p.body_type,
                    c.name as company_name, c.document as company_cnpj
                FROM users u
                LEFT JOIN user_profiles p ON u.id = p.user_id
                LEFT JOIN companies c ON u.id = c.owner_id
                WHERE u.id = :id AND u.deleted_at IS NULL
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) return null;

        // 2. Tratamento de tipos (O PDO retorna tudo como string)
        // Garantimos que números e booleano cheguem tipados para o Controller
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
         * 2. SQL Otimizado
         * - u.id AS id: Garante que o ID principal não seja sobrescrito por IDs de joins (como c.id)
         * - LEFT JOIN: Essencial pois no momento do cadastro o perfil/empresa pode ainda não existir
         */
        $sql = "SELECT 
                    u.*, 
                    p.avatar_url, 
                    p.slug,
                    c.name_fantasy as company_name
                FROM users u
                LEFT JOIN user_profiles p ON u.id = p.user_id
                LEFT JOIN companies c ON u.id = c.owner_id
                WHERE (u.email = :email OR u.whatsapp = :whatsapp) 
                AND (u.deleted_at IS NULL OR u.deleted_at = '0000-00-00 00:00:00')
                LIMIT 1";

        try {
            $stmt = $this->db->prepare($sql);
            
            /**
             * 3. Mapeamento Inteligente
             * Passamos o identifier bruto para o e-mail e a versão limpa para o whatsapp.
             * Isso permite que o usuário logue com "11999999999" ou "(11) 99999-9999"
             */
            $stmt->execute([
                ':email'    => $identifier,
                ':whatsapp' => !empty($onlyNumbers) ? $onlyNumbers : $identifier
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // 4. Tipagem Garantida (Evita comportamentos estranhos no PHP/React)
                $user['id'] = (int)$user['id'];
                $user['is_verified'] = isset($user['is_verified']) ? (int)$user['is_verified'] : 0;
                
                // Caso existam campos de plano ou empresa que devam ser inteiros
                if (isset($user['plan_id'])) $user['plan_id'] = (int)$user['plan_id'];
                if (isset($user['company_id'])) $user['company_id'] = (int)$user['company_id'];
            }

            return $user ?: null;

        } catch (\PDOException $e) {
            error_log("Erro Crítico em findByEmailOrWhatsapp: " . $e->getMessage());
            // Em ambiente de desenvolvimento, você pode querer lançar a exceção
            // throw $e; 
            return null;
        }
    }
    /**
     * Atualiza dados da tabela principal 'users'
     * Adicionado: suporte a documento e is_verified
     */
    public function updateBasicInfo($userId, $data) {
        // 1. Sanitização e Normalização de campos do Payload
        // Note que verificamos 'cnpj' ou 'document' para preencher o campo de documento
        $name     = !empty($data['name'])     ? trim($data['name']) : null;
        $whatsapp = !empty($data['whatsapp']) ? preg_replace('/\D/', '', $data['whatsapp']) : null;
        
        // Prioriza 'cnpj' se vier preenchido, senão tenta 'document'
        $rawDoc   = !empty($data['cnpj']) ? $data['cnpj'] : ($data['document'] ?? null);
        $document = $rawDoc ? preg_replace('/\D/', '', $rawDoc) : null;

        // Localização (essencial para aparecer no perfil e filtros)
        $city     = !empty($data['city'])  ? trim($data['city']) : null;
        $state    = !empty($data['state']) ? strtoupper(trim($data['state'])) : null;
        
        // 2. Query atualizada com os campos que faltavam
        $sql = "UPDATE users SET 
                    name = COALESCE(:name, name), 
                    whatsapp = COALESCE(:whatsapp, whatsapp),
                    document = COALESCE(:document, document),
                    city = COALESCE(:city, city),
                    state = COALESCE(:state, state),
                    is_verified = COALESCE(:is_verified, is_verified)
                WHERE id = :id AND deleted_at IS NULL";
        
        $params = [
            ':name'        => $name,
            ':whatsapp'    => $whatsapp,
            ':document'    => $document,
            ':city'        => $city,
            ':state'       => $state,
            ':is_verified' => isset($data['is_verified']) ? (int)$data['is_verified'] : null,
            ':id'          => $userId
        ];

        try {
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute($params);

            // Debug opcional caso continue não salvando (remover em produção)
            if ($stmt->rowCount() === 0) {
                error_log("Aviso: updateBasicInfo executado mas nenhuma linha foi alterada para o ID $userId. Os dados podem ser idênticos aos já existentes.");
            }

            return $success;
        } catch (\PDOException $e) {
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

            // 1. Atualiza Tabela 'users'
            $sqlUser = "UPDATE users SET 
                            name = COALESCE(:name, name), 
                            whatsapp = :whatsapp,
                            document = COALESCE(:doc, document),
                            city = :city,
                            state = :state
                        WHERE id = :id LIMIT 1";
            
            $this->db->prepare($sqlUser)->execute([
                ':name'     => $nullIfEmpty($data['name'] ?? null),
                ':whatsapp' => preg_replace('/\D/', '', $data['whatsapp'] ?? ''),
                ':doc'      => preg_replace('/\D/', '', $data['cnpj'] ?? $data['document'] ?? ''),
                ':city'     => $nullIfEmpty($data['city'] ?? null),
                ':state'    => $nullIfEmpty($data['state'] ?? null),
                ':id'       => $userId
            ]);

            // 2. Atualiza Tabela 'user_profiles'
            $sqlProfile = "UPDATE user_profiles SET 
                            bio = :bio, 
                            slug = :slug,
                            avatar_url = COALESCE(:avatar, avatar_url),
                            cover_url = COALESCE(:cover, cover_url),
                            vehicle_type = :v_type, 
                            body_type = :b_type
                        WHERE user_id = :id";
            
            $this->db->prepare($sqlProfile)->execute([
                ':bio'    => $nullIfEmpty($data['bio'] ?? null),
                ':slug'   => $data['slug'],
                ':avatar' => $nullIfEmpty($data['avatar_url'] ?? null),
                ':cover'  => $nullIfEmpty($data['cover_url'] ?? null),
                ':v_type' => $data['vehicle_type'] ?? null,
                ':b_type' => $data['body_type'] ?? null,
                ':id'     => $userId
            ]);

            // 3. Atributos Dinâmicos no JSON (Campos extras do Front)
            $extrasMapping = [
                'plate', 'antt', 'anos_experiencia', 'company_name', 
                'instagram', 'website', 'cidades_atendidas', 'phone'
            ];
            $newExtras = [];
            foreach ($extrasMapping as $field) {
                if (isset($data[$field])) {
                    $val = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
                    $newExtras[$field] = ($val === '') ? null : $val;
                }
            }

            if (!empty($newExtras)) {
                $sqlJson = "UPDATE user_profiles SET 
                                extended_attributes = JSON_MERGE_PATCH(COALESCE(extended_attributes, '{}'), :json) 
                            WHERE user_id = :id";
                $this->db->prepare($sqlJson)->execute([
                    ':json' => json_encode($newExtras, JSON_UNESCAPED_UNICODE),
                    ':id'   => $userId
                ]);
            }

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro Repository: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * getProfileData atualizado para trazer os atributos JSON
     */
   public function getProfileData($userId) {
        $sql = "SELECT 
                u.id, u.name, u.email, u.whatsapp, u.role, u.status, u.document,
                u.plan_id, u.is_verified, u.company_id, u.created_at,
                u.is_subscriber, u.plan_expires_at, u.user_type, u.plan_type,
                u.is_advertiser, u.is_shipper, u.is_seller,
                u.rating_avg, u.rating_count, u.balance,
                u.city as user_city, u.state as user_state, 
                p.avatar_url, p.cover_url, 
                p.bio, p.slug, p.vehicle_type, p.body_type, p.extended_attributes,
                c.name_fantasy as company_name, c.cnpj as company_cnpj
            FROM users u
            LEFT JOIN user_profiles p ON u.id = p.user_id
            LEFT JOIN companies c ON u.id = c.owner_id
            WHERE u.id = :id AND u.deleted_at IS NULL
            LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;

        // 1. Extração de atributos dinâmicos (JSON para primeiro nível)
        // Isso resolve o problema de company_name ou instagram não aparecerem no front
        if (!empty($row['extended_attributes'])) {
            $extras = json_decode($row['extended_attributes'], true);
            if (is_array($extras)) {
                foreach ($extras as $key => $value) {
                    if (!isset($row[$key])) { // Não sobrescreve campos nativos
                        $row[$key] = $value;
                    }
                }
            }
        }

        // 2. Normalização de tipos
        $booleanFields = ['is_verified', 'is_subscriber', 'is_advertiser', 'is_shipper', 'is_seller'];
        foreach ($booleanFields as $field) {
            $row[$field] = (isset($row[$field]) && (int)$row[$field] === 1);
        }

        $row['rating_avg'] = round((float)($row['rating_avg'] ?? 0), 1);
        $row['balance'] = (float)($row['balance'] ?? 0);

        // 3. Fallbacks e Aliases para o Front-end
        $row['city'] = $row['user_city'] ?: 'Brasil';
        $row['state'] = $row['user_state'] ?: '--';
        $row['avatar_url'] = $row['avatar_url'] ?: null;
        $row['cover_url'] = $row['cover_url'] ?: null;
        $row['banner_url'] = $row['cover_url']; // Alias comum no seu front

        return $row;
    }

    public function linkOrCreateCompany($ownerId, $companyName, $cnpj = null) {
        try {
            // 1. Iniciamos uma transação para garantir consistência entre tabelas
            if (!$this->db->inTransaction()) $this->db->beginTransaction();

            $companyName = trim($companyName);
            $cnpj = $cnpj ? preg_replace('/\D/', '', $cnpj) : null;

            // 2. Verifica se o usuário já é dono de alguma empresa
            $stmt = $this->db->prepare("SELECT id FROM companies WHERE owner_id = :owner_id LIMIT 1");
            $stmt->execute([':owner_id' => $ownerId]);
            $company = $stmt->fetch();

            if ($company) {
                // Atualiza empresa existente
                $companyId = $company['id'];
                $sqlUp = "UPDATE companies SET name_fantasy = :name, cnpj = :cnpj, updated_at = NOW() WHERE id = :id";
                $this->db->prepare($sqlUp)->execute([
                    ':name' => $companyName,
                    ':cnpj' => $cnpj,
                    ':id'   => $companyId
                ]);
            } else {
                // Cria nova empresa
                $sqlIn = "INSERT INTO companies (owner_id, name_fantasy, cnpj, status, created_at) 
                        VALUES (:owner_id, :name, :cnpj, 'active', NOW())";
                $this->db->prepare($sqlIn)->execute([
                    ':owner_id' => $ownerId,
                    ':name'     => $companyName,
                    ':cnpj'     => $cnpj
                ]);
                $companyId = $this->db->lastInsertId();
            }

            // 3. Sincroniza o company_id na tabela users
            // Isso é vital para o JOIN do getProfileData funcionar perfeitamente
            $this->db->prepare("UPDATE users SET company_id = :company_id WHERE id = :user_id")
                    ->execute([
                        ':company_id' => $companyId,
                        ':user_id'    => $ownerId
                    ]);

            $this->db->commit();
            return $companyId;

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro em linkOrCreateCompany: " . $e->getMessage());
            return false;
        }
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
                    u.*, 
                    p.bio, 
                    p.slug, 
                    p.avatar_url, 
                    p.cover_url, 
                    p.vehicle_type, 
                    p.body_type, 
                    p.extended_attributes,
                    p.verification_status
                FROM users u
                INNER JOIN user_profiles p ON u.id = p.user_id
                WHERE p.slug = :slug AND u.status = 'active'
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':slug' => $slug]);
        
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($profile && !empty($profile['extended_attributes'])) {
            $profile['extras'] = json_decode($profile['extended_attributes'], true);
        }

        return $profile;
    }
}