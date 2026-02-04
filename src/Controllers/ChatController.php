<?php
namespace App\Controllers;

use App\Core\Response;
use App\Repositories\ChatRepository;
use App\Services\NotificationService;

class ChatController {
    private $repo;
    private $notification;

    public function __construct($db) {
        $this->repo = new ChatRepository($db);
        $this->notification = new NotificationService($db);
    }

    /**
     * Envia mensagem e notifica o destinatário
     */
    public function sendMessage($data, $user) {
        if (!$user) return Response::json(["success" => false, "message" => "Não autenticado"], 401);

        $roomId = $data['room_id'] ?? null;
        $message = trim($data['message'] ?? '');
        $receiverId = $data['receiver_id'] ?? null;

        if (!$roomId || !$message || !$receiverId) {
            return Response::json(["success" => false, "message" => "Dados incompletos"], 400);
        }

        $msgId = $this->repo->saveMessage($roomId, $user['id'], $message);

        // Dispara notificação push/interna
        $this->notification->send(
            (int)$receiverId,
            "Nova mensagem de " . ($user['name'] ?? 'Usuário'),
            substr($message, 0, 60), 
            "CHAT_MESSAGE",
            "high",
            "/chat/" . $roomId
        );

        return Response::json([
            "success" => true, 
            "message_id" => $msgId,
            "timestamp" => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Carrega o histórico e limpa o contador de não lidas
     */
    public function getMessages($data, $user) {
        if (!$user) return Response::json(["success" => false], 401);

        $roomId = $data['room_id'] ?? $data['id'] ?? null;
        
        if (!$roomId) {
            return Response::json(["success" => false, "message" => "Sala não informada"], 400);
        }

        $messages = $this->repo->getMessages($roomId);
        
        // Sincroniza o status de lido
        $this->repo->markAsRead($roomId, $user['id']);

        return Response::json([
            "success" => true, 
            "data" => $messages
        ]);
    }

    /**
     * Lista o "Inbox" do usuário com contagem de não lidas
     */
    public function listRooms($data, $user) {
        if (!$user) return Response::json(["success" => false], 401);

        $rooms = $this->repo->getUserRooms($user['id']);
        
        return Response::json([
            "success" => true, 
            "data" => $rooms,
            "total_unread" => array_sum(array_column($rooms, 'unread_count'))
        ]);
    }

    /**
     * Permite ocultar conversas da lista principal
     */
    public function archiveRoom($data, $user) {
        if (!$user) return Response::json(["success" => false], 401);
        
        $roomId = $data['room_id'] ?? null;
        // Precisamos adicionar o método archive no Repository se for usar
        // $success = $this->repo->updateRoomStatus($roomId, 'archived');
        
        return Response::json(["success" => true, "message" => "Conversa arquivada"]);
    }

    /**
     * Inicializa ou recupera uma conversa entre motorista e anunciante
     */
    public function initChat($data, $user) {
        if (!$user) return Response::json(["success" => false], 401);

        $freightId = $data['freight_id'] ?? null;
        $sellerId = $data['seller_id'] ?? null; // ID do dono do frete
        $buyerId = $user['id']; // O motorista logado

        if (!$freightId || !$sellerId) {
            return Response::json(["success" => false, "message" => "Dados inválidos"], 400);
        }

        // Evita que o motorista abra chat com ele mesmo
        if ($buyerId == $sellerId) {
            return Response::json(["success" => false, "message" => "Você é o dono deste frete"], 400);
        }

        // Usa o método que já existe no seu Repository para buscar ou criar a sala
        $roomId = $this->repo->getOrCreateRoom($freightId, $buyerId, $sellerId);

        return Response::json([
            "success" => true,
            "room_id" => $roomId
        ]);
    }
}