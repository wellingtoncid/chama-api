<?php
namespace App\Repositories;

class ChatRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getOrCreateRoom($freightId, $buyerId, $sellerId) {
        $stmt = $this->db->prepare("SELECT id FROM chat_rooms WHERE freight_id = ? AND buyer_id = ? AND seller_id = ?");
        $stmt->execute([$freightId, $buyerId, $sellerId]);
        $room = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($room) return $room['id'];

        $stmt = $this->db->prepare("INSERT INTO chat_rooms (freight_id, buyer_id, seller_id, status) VALUES (?, ?, ?, 'active')");
        $stmt->execute([$freightId, $buyerId, $sellerId]);
        
        return $this->db->lastInsertId();
    }

    public function saveMessage($roomId, $senderId, $message) {
        $sql = "INSERT INTO chat_messages (room_id, sender_id, message, created_at) VALUES (?, ?, ?, NOW())";
        $this->db->prepare($sql)->execute([$roomId, $senderId, $message]);
        return $this->db->lastInsertId();
    }

    public function getMessages($roomId) {
        $sql = "SELECT m.*, u.name as sender_name 
                FROM chat_messages m
                JOIN users u ON m.sender_id = u.id
                WHERE m.room_id = ? 
                ORDER BY m.created_at ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$roomId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function markAsRead($roomId, $userId) {
        $sql = "UPDATE chat_messages SET is_read = 1 
                WHERE room_id = ? AND sender_id != ? AND is_read = 0";
        return $this->db->prepare($sql)->execute([$roomId, $userId]);
    }

    public function getUserRooms($userId) {
        // SQL Otimizado: Traz o contato, última mensagem, data e contador de não lidas
        $sql = "SELECT 
                    r.id as room_id, 
                    r.freight_id, 
                    f.product as freight_product,
                    u.id as contact_id,
                    u.name as contact_name,
                    up.avatar_url as contact_avatar,
                    lm.message as last_message,
                    lm.created_at as last_message_time,
                    (SELECT COUNT(*) FROM chat_messages 
                     WHERE room_id = r.id AND sender_id != ? AND is_read = 0) as unread_count
                FROM chat_rooms r
                JOIN freights f ON r.freight_id = f.id
                JOIN users u ON (CASE WHEN r.buyer_id = ? THEN r.seller_id = u.id ELSE r.buyer_id = u.id END)
                LEFT JOIN user_profiles up ON u.id = up.user_id
                LEFT JOIN (
                    SELECT room_id, message, created_at 
                    FROM chat_messages 
                    WHERE id IN (SELECT MAX(id) FROM chat_messages GROUP BY room_id)
                ) lm ON r.id = lm.room_id
                WHERE r.buyer_id = ? OR r.seller_id = ?
                ORDER BY lm.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $userId, $userId, $userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}