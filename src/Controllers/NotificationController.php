<?php
class NotificationController {
    private $db;
    public function __construct($db) { $this->db = $db; }

    public function handle($endpoint, $data, $loggedUser) {
        if (!$loggedUser) return ["success" => false, "message" => "Não autorizado"];
        $userId = $loggedUser['id'];

        switch ($endpoint) {
            case 'list-notifications':
                $stmt = $this->db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
                $stmt->execute([$userId]);
                return $stmt->fetchAll();

            case 'mark-as-read':
                $stmt = $this->db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
                return ["success" => $stmt->execute([$userId])];

            case 'unread-count':
                $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM notifications WHERE user_id = ? AND is_read = 0");
                $stmt->execute([$userId]);
                return $stmt->fetch();

            default:
                return ["error" => "Endpoint inválido"];
        }
    }

    // Função auxiliar que será usada por OUTROS controllers (ex: ao aprovar frete)
    public function notify($userId, $title, $message) {
        $stmt = $this->db->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
        return $stmt->execute([$userId, $title, $message]);
    }
}