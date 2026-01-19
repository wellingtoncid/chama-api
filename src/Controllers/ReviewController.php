<?php
class ReviewController {
    private $db;
    public function __construct($db) { $this->db = $db; }

    public function handle($endpoint, $data, $loggedUser) {
        if (!$loggedUser) return ["success" => false, "message" => "Não autorizado"];

        switch ($endpoint) {
            case 'submit-review':
                return $this->submitReview($data, $loggedUser['id']);
            
            case 'get-user-reviews':
                return $this->getUserReviews($data['target_id'] ?? $_GET['target_id'] ?? 0);

            default:
                return ["error" => "Endpoint de avaliação inválido"];
        }
    }

    private function submitReview($data, $reviewerId) {
        $targetId = $data['target_id'];
        $rating = (int)$data['rating']; // 1 a 5
        $comment = $data['comment'] ?? '';

        // 1. Impede que o utilizador se avalie a si mesmo
        if ($reviewerId == $targetId) {
            return ["success" => false, "message" => "Não pode avaliar o seu próprio perfil"];
        }

        // 2. Verifica se já existe uma avaliação recente para evitar spam
        $check = $this->db->prepare("SELECT id FROM reviews WHERE reviewer_id = ? AND target_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $check->execute([$reviewerId, $targetId]);
        if ($check->fetch()) {
            return ["success" => false, "message" => "Já avaliou este utilizador recentemente"];
        }

        // 3. Insere a avaliação
        $sql = "INSERT INTO reviews (reviewer_id, target_id, rating, comment) VALUES (?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $success = $stmt->execute([$reviewerId, $targetId, $rating, $comment]);

        if ($success) {
            // --- GATILHOS PÓS-AVALIAÇÃO ---

            // A. Notificar o alvo da avaliação
            require_once __DIR__ . '/NotificationController.php';
            $notif = new NotificationController($this->db);
            $notif->notify(
                $targetId, 
                "Nova Avaliação!", 
                "Alguém deixou uma nota de $rating estrelas no seu perfil."
            );

            // B. Verificar Promoção para Selo de Verificado
            // Chamamos o UserController para recalcular a média e dar o selo se merecido
            require_once __DIR__ . '/UserController.php';
            $userCtrl = new UserController($this->db);
            $userCtrl->checkAndVerify($targetId);
        }

        return ["success" => $success];
    }

    private function getUserReviews($targetId) {
        // Busca as avaliações e os nomes de quem avaliou
        $stmt = $this->db->prepare("
            SELECT r.*, u.name as reviewer_name 
            FROM reviews r 
            JOIN users u ON r.reviewer_id = u.id 
            WHERE r.target_id = ? 
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$targetId]);
        $reviews = $stmt->fetchAll();

        // Calcula a média
        $avg = $this->db->prepare("SELECT AVG(rating) as average, COUNT(*) as count FROM reviews WHERE target_id = ?");
        $avg->execute([$targetId]);
        $stats = $avg->fetch();

        return [
            "reviews" => $reviews,
            "average" => round($stats['average'] ?? 0, 1),
            "total" => $stats['count']
        ];
    }
}