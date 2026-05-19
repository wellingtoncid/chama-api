<?php

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\ChatRepository;
use App\Services\NotificationService;

class ChatController
{
    private $repo;
    private $notification;

    public function __construct($db)
    {
        $this->repo = new ChatRepository($db);
        $this->notification = new NotificationService($db);
    }

    public function sendMessage($data, $user)
    {
        if (!$user) {
            return Response::json(['success' => false, 'message' => 'Não autenticado'], 401);
        }

        $roomId = $data['room_id'] ?? null;
        $message = trim($data['message'] ?? '');
        $receiverId = $data['receiver_id'] ?? null;

        if (!$roomId || !$message || !$receiverId) {
            return Response::json(['success' => false, 'message' => 'Dados incompletos'], 400);
        }

        $msgId = $this->repo->saveMessage($roomId, $user['id'], $message);

        $this->notification->send(
            (int)$receiverId,
            'Nova mensagem de ' . ($user['name'] ?? 'Usuário'),
            substr($message, 0, 60),
            'CHAT_MESSAGE',
            'high',
            '/chat/' . $roomId
        );

        return Response::json([
            'success' => true,
            'message_id' => $msgId,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    public function getMessages($data, $user)
    {
        if (!$user) {
            return Response::json(['success' => false], 401);
        }

        $roomId = $data['room_id'] ?? $data['id'] ?? null;

        if (!$roomId) {
            return Response::json(['success' => false, 'message' => 'Sala não informada'], 400);
        }

        $messages = $this->repo->getMessages($roomId);
        $this->repo->markAsRead($roomId, $user['id']);

        return Response::json([
            'success' => true,
            'data' => $messages,
        ]);
    }

    public function listRooms($data, $user)
    {
        if (!$user) {
            return Response::json(['success' => false], 401);
        }

        $rooms = $this->repo->getUserRooms($user['id']);

        return Response::json([
            'success' => true,
            'data' => $rooms,
            'total_unread' => array_sum(array_column($rooms, 'unread_count')),
        ]);
    }

    public function getRoom($data, $user)
    {
        if (!$user) {
            return Response::json(['success' => false], 401);
        }

        $roomId = $data['id'] ?? null;
        if (!$roomId) {
            return Response::json(['success' => false, 'message' => 'Sala não informada'], 400);
        }

        $room = $this->repo->getRoom($roomId, $user['id']);
        if (!$room) {
            return Response::json(['success' => false, 'message' => 'Sala não encontrada'], 404);
        }

        return Response::json(['success' => true, 'data' => $room]);
    }

    public function initChat($data, $user)
    {
        if (!$user) {
            return Response::json(['success' => false], 401);
        }

        $freightId = $data['freight_id'] ?? null;
        $sellerId = $data['seller_id'] ?? null;
        $buyerId = $user['id'];

        if (!$freightId || !$sellerId) {
            return Response::json(['success' => false, 'message' => 'Dados inválidos'], 400);
        }

        if ($buyerId == $sellerId) {
            return Response::json(['success' => false, 'message' => 'Você é o dono deste frete'], 400);
        }

        $roomId = $this->repo->getOrCreateRoom($freightId, $buyerId, $sellerId);

        return Response::json([
            'success' => true,
            'room_id' => $roomId,
        ]);
    }

    public function markUnread($data, $user)
    {
        if (!$user) {
            return Response::json(['success' => false], 401);
        }

        $roomId = $data['room_id'] ?? null;
        if (!$roomId) {
            return Response::json(['success' => false, 'message' => 'Sala não informada'], 400);
        }

        $this->repo->markAsUnread($roomId, $user['id']);

        return Response::json(['success' => true, 'message' => 'Mensagens marcadas como não lidas']);
    }

    public function deleteChat($data, $user)
    {
        if (!$user) {
            return Response::json(['success' => false], 401);
        }

        $roomId = $data['room_id'] ?? null;
        if (!$roomId) {
            return Response::json(['success' => false, 'message' => 'Sala não informada'], 400);
        }

        $this->repo->hideRoom($roomId, $user['id']);

        return Response::json(['success' => true, 'message' => 'Conversa excluída']);
    }

    public function blockUser($data, $user)
    {
        if (!$user) {
            return Response::json(['success' => false], 401);
        }

        $userId = $data['user_id'] ?? null;
        if (!$userId) {
            return Response::json(['success' => false, 'message' => 'Usuário não informado'], 400);
        }

        if ($userId == $user['id']) {
            return Response::json(['success' => false, 'message' => 'Você não pode bloquear a si mesmo'], 400);
        }

        $this->repo->blockUser($user['id'], $userId);

        return Response::json(['success' => true, 'message' => 'Usuário bloqueado']);
    }
}
