<?php

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\AdminRepository;

class SupportController {
    private $repo;
    private $notif;

    public function __construct($db) {
        $this->repo = new AdminRepository($db);
        require_once __DIR__ . '/NotificationController.php';
        $this->notif = new \NotificationController($db);
    }

    /**
     * Middleware de Segurança para Atendimento
     */
    private function authorize($loggedUser) {
        $role = strtoupper($loggedUser['role'] ?? '');
        if (!$loggedUser || !in_array($role, ['ADMIN', 'MANAGER', 'SUPPORT'])) {
            Response::json(["success" => false, "message" => "Acesso restrito ao suporte"], 403);
            exit;
        }
    }

    public function listAllTickets($data, $loggedUser) {
        $this->authorize($loggedUser);
        $status = $data['status'] ?? '%';
        return Response::json(["success" => true, "data" => $this->repo->getTickets($status)]);
    }

    public function reply($data, $loggedUser) {
        $this->authorize($loggedUser);
        
        $ticketId = $data['ticket_id'] ?? null;
        $message = $data['message'] ?? '';

        if (!$ticketId || empty($message)) {
            return Response::json(["success" => false, "message" => "Dados incompletos"]);
        }

        if ($this->repo->addTicketMessage($ticketId, $loggedUser['id'], $message, true)) {
            $ticket = $this->repo->getTicketById($ticketId);
            // Notifica o motorista/empresa
            $this->notif->notify($ticket['user_id'], "Suporte: Novo retorno", "Você recebeu uma resposta em seu chamado.");
            
            return Response::json(["success" => true]);
        }
        return Response::json(["success" => false]);
    }

    public function closeTicket($data, $loggedUser) {
        $this->authorize($loggedUser);
        $id = $data['id'] ?? null;
        $sql = "UPDATE support_tickets SET status = 'CLOSED' WHERE id = ?";
        $success = $this->db->prepare($sql)->execute([$id]); // Precisa passar o $db no construtor
        return Response::json(["success" => $success]); 
    }
    
    /**
     * Lista chamados filtrando por Admin/Manager/Suporte
     */
    public function listTickets($data, $loggedUser) {
        // Apenas Admin, Manager ou Support podem ver todos os chamados
        if (!in_array($loggedUser['role'], ['admin', 'manager', 'support'])) {
            return Response::json(["success" => false, "message" => "Proibido"], 403);
        }

        $status = $data['status'] ?? '%';
        $tickets = $this->repo->getTickets($status);
        
        return Response::json(["success" => true, "data" => $tickets]);
    }

    /**
     * Resposta oficial da plataforma
     */
    public function adminReply($data, $loggedUser) {
        $ticketId = $data['ticket_id'];
        $message = $data['message'];

        // Salva a mensagem marcando is_admin_reply = 1
        $success = $this->repo->addTicketMessage($ticketId, $loggedUser['id'], $message, true);

        if ($success) {
            // Aqui você dispararia a notificação push/email para o motorista
            return Response::json(["success" => true, "message" => "Resposta enviada"]);
        }

        return Response::json(["success" => false, "message" => "Erro ao responder"]);
    }
}