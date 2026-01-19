<?php
class UserController {
    private $db;
    public function __construct($db) { $this->db = $db; }

    public function handle($method, $endpoint, $data, $loggedUser) {
        switch ($endpoint) {
            case 'public-profile':
                return $this->getPublicProfile($data['id'] ?? $_GET['id'] ?? 0);
            
            case 'update-profile':
                return $this->updateProfile($data, $loggedUser);

            case 'check-verification':
                return $this->checkAndVerify($loggedUser['id'] ?? 0);

            default:
                return ["error" => "Endpoint de usuário inválido"];
        }
    }

    public function getPublicProfile($id) {
        $sql = "SELECT 
                    u.id, u.name, u.role, u.is_verified, u.created_at,
                    p.bio, p.avatar_url, p.banner_url, p.vehicle_type, p.bodyType, p.city, p.state,
                    (SELECT AVG(rating) FROM reviews WHERE target_id = u.id) as rating_avg,
                    (SELECT COUNT(*) FROM reviews WHERE target_id = u.id) as rating_count
                FROM users u
                LEFT JOIN user_profiles p ON u.id = p.user_id
                WHERE u.id = ? LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        $profile = $stmt->fetch();

        if (!$profile) return ["success" => false, "message" => "Usuário não encontrado"];

        $profile['member_since'] = date('m/Y', strtotime($profile['created_at']));
        $profile['rating_avg'] = round($profile['rating_avg'] ?? 0, 1);

        return ["success" => true, "data" => $profile];
    }

    private function updateProfile($data, $user) {
        if (!$user) return ["success" => false];

        // Atualiza a tabela user_profiles
        $sql = "UPDATE user_profiles SET 
                bio = ?, city = ?, state = ?, vehicle_type = ?, bodyType = ? 
                WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        $success = $stmt->execute([
            $data['bio'], $data['city'], $data['state'], 
            $data['vehicle_type'], $data['bodyType'], $user['id']
        ]);

        return ["success" => $success];
    }

    private function updateVerificationStatus($db, $userId) {
        $stmt = $db->prepare("SELECT name, email, phone, photo, city, bio FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $points = 0;
        if (!empty($user['name'])) $points += 20;
        if (!empty($user['phone'])) $points += 20;
        if (!empty($user['photo'])) $points += 20;
        if (!empty($user['city'])) $points += 20;
        if (!empty($user['bio'])) $points += 20;

        // Se atingir 80% (4 dos 5 campos), ganha o selo
        $isVerified = ($points >= 80) ? 1 : 0;

        $update = $db->prepare("UPDATE users SET is_verified = ? WHERE id = ?");
        $update->execute([$isVerified, $userId]);
        
        return $points; // Retorna a porcentagem para o Front-end
    }

    /**
     * Automação: Verifica se o usuário merece o selo de verificado
     * Regra: Mais de 5 avaliações com média acima de 4.5
     */
    public function checkAndVerify($userId) {
        if (!$userId) return;

        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total, AVG(rating) as media 
            FROM reviews WHERE target_id = ?
        ");
        $stmt->execute([$userId]);
        $stats = $stmt->fetch();

        if ($stats['total'] >= 5 && $stats['media'] >= 4.5) {
            $update = $this->db->prepare("UPDATE users SET is_verified = 1 WHERE id = ? AND is_verified = 0");
            $updated = $update->execute([$userId]);

            if ($updated && $update->rowCount() > 0) {
                // Notifica o usuário sobre a conquista
                require_once __DIR__ . '/NotificationController.php';
                (new NotificationController($this->db))->notify(
                    $userId, 
                    "Selo de Verificado!", 
                    "Parabéns! Pelo seu excelente desempenho, você agora é um usuário Verificado."
                );
            }
        }
    }
}