<?php
class User {
    private $db;
    public function __construct($db) { $this->db = $db; }

    public function findByLogin($login) {
        $login = trim($login);
        // Remove máscaras para busca por número puro
        $onlyNumbers = preg_replace('/\D/', '', $login);
        $emailSearch = strtolower($login);

        // Busca robusta: e-mail, número limpo ou entrada original
        $stmt = $this->db->prepare("SELECT * FROM users WHERE LOWER(email) = ? OR whatsapp = ? OR whatsapp = ? LIMIT 1");
        $stmt->execute([$emailSearch, $onlyNumbers, $login]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($data) {
        $hash = password_hash($data['password'], PASSWORD_DEFAULT);
        $whatsapp = preg_replace('/\D/', '', $data['whatsapp'] ?? '');
        
        $stmt = $this->db->prepare("INSERT INTO users (name, email, whatsapp, password, role, status) VALUES (?, ?, ?, ?, ?, ?)");
        $success = $stmt->execute([
            $data['name'], 
            strtolower($data['email']), 
            $whatsapp, 
            $hash, 
            strtoupper($data['role'] ?? 'DRIVER'),
            $data['status'] ?? 'approved'
        ]);

        if ($success) {
            $userId = $this->db->lastInsertId();
            // Cria o perfil inicial. O slug padrão é 'user-' + ID para evitar conflitos
            $this->db->prepare("INSERT INTO user_profiles (user_id, slug) VALUES (?, ?)")
                     ->execute([$userId, 'user-' . $userId]);
        }
        return $success;
    }

    public function handleProfile($endpoint, $data, $loggedUser) {
        if (!$loggedUser) return ["success" => false, "message" => "Não autorizado"];
        
        $userId = $loggedUser['id'];

        switch ($endpoint) {
            case 'get-my-profile':
                $stmt = $this->db->prepare("
                    SELECT u.email, u.name, u.role, u.company_name, u.cnpj, u.whatsapp, u.is_verified,
                           p.bio, p.slug, p.private_data, p.avatar_url, p.banner_url, p.social_links 
                    FROM users u 
                    LEFT JOIN user_profiles p ON u.id = p.user_id 
                    WHERE u.id = ?
                ");
                $stmt->execute([$userId]);
                $profile = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($profile) {
                    $profile['private_data'] = json_decode($profile['private_data'] ?? '{}', true);
                    $profile['social_links'] = json_decode($profile['social_links'] ?? '{}', true);
                }
                return $profile;

            case 'save-profile':
                // 1. Atualiza dados básicos na tabela 'users'
                $whatsapp = preg_replace('/\D/', '', $data['whatsapp'] ?? $loggedUser['whatsapp']);
                $stmtU = $this->db->prepare("UPDATE users SET name = ?, company_name = ?, cnpj = ?, whatsapp = ? WHERE id = ?");
                $stmtU->execute([
                    $data['name'] ?? $loggedUser['name'],
                    $data['company_name'] ?? null,
                    $data['cnpj'] ?? null,
                    $whatsapp,
                    $userId
                ]);

                // 2. Atualiza a tabela 'user_profiles'
                $privateData = is_array($data['private_data'] ?? null) ? json_encode($data['private_data']) : ($data['private_data'] ?? '{}');
                $socialLinks = is_array($data['social_links'] ?? null) ? json_encode($data['social_links']) : ($data['social_links'] ?? '{}');

                $sql = "UPDATE user_profiles SET 
                        bio = ?, 
                        slug = ?, 
                        private_data = ?, 
                        social_links = ?,
                        avatar_url = ?, 
                        banner_url = ? 
                        WHERE user_id = ?";
                
                $stmtP = $this->db->prepare($sql);
                $success = $stmtP->execute([
                    $data['bio'] ?? '', 
                    $data['slug'], 
                    $privateData, 
                    $socialLinks,
                    $data['avatar_url'] ?? null, 
                    $data['banner_url'] ?? null, 
                    $userId
                ]);

                return ["success" => $success];

            case 'check-slug':
                $slug = preg_replace('/[^a-z0-0-]/', '-', strtolower($data['slug'] ?? ''));
                $stmt = $this->db->prepare("SELECT id FROM user_profiles WHERE slug = ? AND user_id != ?");
                $stmt->execute([$slug, $userId]);
                return ["available" => !$stmt->fetch(), "slug" => $slug];
            
            default:
                return ["success" => false, "error" => "Ação de perfil desconhecida"];
        }
    }

    public function getPublicProfile($slugOrId) {
        $stmt = $this->db->prepare("
            SELECT u.id as user_id, u.name, u.whatsapp, u.role, u.is_verified, u.company_name,
                   p.slug, p.bio, p.social_links, p.avatar_url, p.banner_url, 
                   (SELECT AVG(rating) FROM reviews WHERE target_id = u.id) as rating_avg,
                   (SELECT COUNT(*) FROM reviews WHERE target_id = u.id) as rating_count
            FROM users u 
            LEFT JOIN user_profiles p ON u.id = p.user_id 
            WHERE p.slug = ? OR u.id = ? LIMIT 1
        ");
        $stmt->execute([$slugOrId, $slugOrId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($res) {
            $res['social_links'] = json_decode($res['social_links'] ?? '{}', true);
            $res['rating_avg'] = round((float)$res['rating_avg'], 1);
        }
        return $res;
    }

    // Métodos de Recuperação de Senha
    public function saveResetToken($userId, $token, $expires) {
        return $this->db->prepare("UPDATE users SET reset_token = ?, token_expires = ? WHERE id = ?")
                        ->execute([$token, $expires, $userId]);
    }

    public function validateResetToken($email, $token) {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ? AND reset_token = ? AND token_expires > NOW() LIMIT 1");
        $stmt->execute([$email, $token]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updatePassword($userId, $hashedPassword) {
        return $this->db->prepare("UPDATE users SET password = ? WHERE id = ?")
                        ->execute([$hashedPassword, $userId]);
    }

    public function clearResetToken($userId) {
        return $this->db->prepare("UPDATE users SET reset_token = NULL, token_expires = NULL WHERE id = ?")
                        ->execute([$userId]);
    }
}