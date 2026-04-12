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

        $limit = (int)($data['limit'] ?? 20);
        $notifications = $this->service->getUserNotifications($user['id'], $limit);
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

    /**
     * Verifica e notifica sobre perfil incompleto
     */
    public function checkProfileCompleteness($data, $user) {
        if (!$user) return Response::json(["success" => false, "message" => "Não autorizado"], 401);
        
        $notified = $this->service->checkAndNotifyIncompleteProfile($user['id']);
        
        return Response::json([
            "success" => true,
            "notified" => $notified
        ]);
    }

    /**
     * Envia notificação de convite de frete para motorista
     */
    public function sendFreightInvite($data, $user) {
        if (!$user) return Response::json(["success" => false, "message" => "Não autorizado"], 401);
        
        $driverId = (int)($data['driver_id'] ?? 0);
        $freightId = (int)($data['freight_id'] ?? 0);
        $companyName = $data['company_name'] ?? 'Uma empresa';
        
        if (!$driverId || !$freightId) {
            return Response::json(["success" => false, "message" => "Dados incompletos"], 400);
        }
        
        $success = $this->service->notifyFreightInvite($driverId, $freightId, $companyName);
        
        return Response::json(["success" => $success]);
    }
}
