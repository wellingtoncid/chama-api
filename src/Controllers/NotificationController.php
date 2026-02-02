<?php
namespace App\Controllers;

use App\Core\Response;
use App\Services\NotificationService;

class NotificationController {
    private $service;

    public function __construct($notificationService) {
        $this->service = $notificationService;
    }

    /**
     * Lista as notificações do usuário logado
     */
    public function index($data, $user) {
        if (!$user) return Response::json(["success" => false, "message" => "Não autorizado"], 401);

        $notifications = $this->service->getUserNotifications($user['id']);
        $unreadCount = $this->service->getUnreadCount($user['id']);

        return Response::json([
            "success" => true,
            "unread_count" => $unreadCount,
            "data" => $notifications
        ]);
    }

    /**
     * Marca uma notificação específica como lida
     */
    public function markAsRead($data, $user) {
        if (!$user) return Response::json(["success" => false], 401);
        
        $id = $data['id'] ?? 0;
        $success = $this->service->markAsRead($id, $user['id']);
        
        return Response::json(["success" => $success]);
    }

    /**
     * Limpa todas as notificações (Marcar tudo como lido)
     */
    public function markAllRead($data, $user) {
        if (!$user) return Response::json(["success" => false], 401);
        
        $success = $this->service->markAllRead($user['id']);
        return Response::json(["success" => $success]);
    }
}