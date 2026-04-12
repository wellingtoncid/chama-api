<?php

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\AdminRepository;
use PDO;

class SupportController {
    private $db;
    private $repo;

    public function __construct($db) {
        $this->db = $db;
        $this->repo = new AdminRepository($db);
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
        $role = strtolower($loggedUser['role'] ?? '');
        if (!in_array($role, ['admin', 'manager', 'support'])) {
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

    // ============================================
    // ENDPOINTS PARA USUÁRIOS (PÚBLICOS)
    // ============================================

    /**
     * Lista tickets do usuário logado
     * GET /api/my-tickets
     */
    public function myTickets($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        try {
            $userId = $loggedUser['id'];
            $stmt = $this->db->prepare("
                SELECT t.*, 
                       (SELECT message FROM support_messages WHERE ticket_id = t.id ORDER BY created_at DESC LIMIT 1) as last_message
                FROM support_tickets t
                WHERE t.user_id = ?
                ORDER BY t.last_update DESC
            ");
            $stmt->execute([$userId]);
            $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json(["success" => true, "data" => $tickets]);
        } catch (\Throwable $e) {
            error_log("ERRO myTickets: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro interno"], 500);
        }
    }

    /**
     * Cria um novo ticket de suporte
     * POST /api/my-tickets
     */
    public function createTicket($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $subject = trim($data['subject'] ?? '');
        $message = trim($data['message'] ?? '');
        $category = strtoupper($data['category'] ?? 'general');
        $urgencyCode = strtoupper($data['urgency_code'] ?? 'U1');

        // Mapeia categorias válidas
        $validCategories = ['FINANCIAL', 'TECHNICAL', 'COMPLAINT', 'OTHER', 'GENERAL'];
        if (!in_array($category, $validCategories)) {
            $category = 'OTHER';
        }

        // Mapeia códigos de urgência válidos
        $validUrgency = ['U1', 'U2', 'U3', 'U4', 'U5'];
        if (!in_array($urgencyCode, $validUrgency)) {
            $urgencyCode = 'U1';
        }

        if (empty($subject) || empty($message)) {
            return Response::json(["success" => false, "message" => "Preencha o assunto e a mensagem"]);
        }

        try {
            $userId = $loggedUser['id'];
            
            // Determina nível de prioridade automaticamente
            $priorityLevel = $this->getUserPriorityLevel($userId);

            $stmt = $this->db->prepare("
                INSERT INTO support_tickets (user_id, subject, category, priority, urgency_code, priority_level, status, created_at, last_update)
                VALUES (?, ?, ?, 'LOW', ?, ?, 'OPEN', NOW(), NOW())
            ");
            $stmt->execute([$userId, $subject, $category, $urgencyCode, $priorityLevel]);
            $ticketId = $this->db->lastInsertId();

            // Adiciona a primeira mensagem
            $msgStmt = $this->db->prepare("
                INSERT INTO support_messages (ticket_id, sender_id, message, is_admin_reply, created_at)
                VALUES (?, ?, ?, 0, NOW())
            ");
            $msgStmt->execute([$ticketId, $userId, $message]);

            // Se for cliente VIP (nível 4-5), notifica admin
            if ($priorityLevel >= 4) {
                // TODO: Implementar notificação push/email para admin
                error_log("VIP TICKET: Usuário nível $priorityLevel criou ticket #$ticketId");
            }

            return Response::json([
                "success" => true,
                "message" => "Ticket criado com sucesso",
                "ticket_id" => $ticketId,
                "priority_level" => $priorityLevel
            ]);
        } catch (\Throwable $e) {
            return Response::json([
                "success" => false, 
                "message" => "Erro ao criar ticket",
                "debug" => $e->getMessage(),
                "file" => basename($e->getFile()),
                "line" => $e->getLine()
            ], 500);
        }
    }

    /**
     * Adiciona resposta a um ticket existente
     * POST /api/my-tickets/reply
     */
    public function addReply($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $ticketId = $data['ticket_id'] ?? null;
        $message = trim($data['message'] ?? '');

        if (!$ticketId || empty($message)) {
            return Response::json(["success" => false, "message" => "Dados incompletos"]);
        }

        // Verifica se o ticket pertence ao usuário
        $stmt = $this->db->prepare("SELECT id, user_id, status FROM support_tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            return Response::json(["success" => false, "message" => "Ticket não encontrado"], 404);
        }

        if ($ticket['user_id'] != $loggedUser['id']) {
            return Response::json(["success" => false, "message" => "Acesso negado"], 403);
        }

        if ($ticket['status'] === 'CLOSED') {
            return Response::json(["success" => false, "message" => "Ticket fechado, não é possível responder"]);
        }

        try {
            // Adiciona a mensagem
            $msgStmt = $this->db->prepare("
                INSERT INTO support_messages (ticket_id, sender_id, message, is_admin_reply, created_at)
                VALUES (?, ?, ?, 0, NOW())
            ");
            $msgStmt->execute([$ticketId, $loggedUser['id'], $message]);

            // Atualiza o ticket
            $updateStmt = $this->db->prepare("
                UPDATE support_tickets SET status = 'OPEN', last_update = NOW() WHERE id = ?
            ");
            $updateStmt->execute([$ticketId]);

            return Response::json(["success" => true, "message" => "Resposta enviada"]);
        } catch (\Throwable $e) {
            error_log("ERRO addReply: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao enviar resposta"], 500);
        }
    }

    /**
     * Fecha um ticket
     * POST /api/my-tickets/close
     */
    public function closeMyTicket($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $ticketId = $data['ticket_id'] ?? null;

        if (!$ticketId) {
            return Response::json(["success" => false, "message" => "ID do ticket é obrigatório"]);
        }

        // Verifica se o ticket pertence ao usuário
        $stmt = $this->db->prepare("SELECT id, user_id FROM support_tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            return Response::json(["success" => false, "message" => "Ticket não encontrado"], 404);
        }

        if ($ticket['user_id'] != $loggedUser['id']) {
            return Response::json(["success" => false, "message" => "Acesso negado"], 403);
        }

        try {
            $updateStmt = $this->db->prepare("
                UPDATE support_tickets SET status = 'CLOSED', last_update = NOW() WHERE id = ?
            ");
            $updateStmt->execute([$ticketId]);

            return Response::json(["success" => true, "message" => "Ticket fechado"]);
        } catch (\Throwable $e) {
            error_log("ERRO closeMyTicket: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao fechar ticket"], 500);
        }
    }

    /**
     * Lista mensagens de um ticket
     * GET /api/my-tickets/:id/messages
     */
    public function getTicketMessages($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }

        $ticketId = $data['id'] ?? null;

        if (!$ticketId) {
            return Response::json(["success" => false, "message" => "ID do ticket é obrigatório"]);
        }

        // Verifica se o ticket pertence ao usuário
        $stmt = $this->db->prepare("SELECT id, user_id FROM support_tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            return Response::json(["success" => false, "message" => "Ticket não encontrado"], 404);
        }

        if ($ticket['user_id'] != $loggedUser['id']) {
            return Response::json(["success" => false, "message" => "Acesso negado"], 403);
        }

        try {
            $msgStmt = $this->db->prepare("
                SELECT m.*, u.name as sender_name, u.role as sender_role
                FROM support_messages m
                LEFT JOIN users u ON m.sender_id = u.id
                WHERE m.ticket_id = ?
                ORDER BY m.created_at ASC
            ");
            $msgStmt->execute([$ticketId]);
            $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json(["success" => true, "data" => $messages]);
        } catch (\Throwable $e) {
            error_log("ERRO getTicketMessages: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao carregar mensagens"], 500);
        }
    }

    /**
     * Lista mensagens de um ticket (Admin) - acessa qualquer ticket
     * GET /api/support/tickets/:id/messages
     */
    public function getTicketMessagesAdmin($data, $loggedUser) {
        $this->authorize($loggedUser);

        $ticketId = $data['id'] ?? null;

        if (!$ticketId) {
            return Response::json(["success" => false, "message" => "ID do ticket é obrigatório"]);
        }

        try {
            $msgStmt = $this->db->prepare("
                SELECT m.*, u.name as sender_name, u.role as sender_role
                FROM support_messages m
                LEFT JOIN users u ON m.sender_id = u.id
                WHERE m.ticket_id = ?
                ORDER BY m.created_at ASC
            ");
            $msgStmt->execute([$ticketId]);
            $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json(["success" => true, "data" => $messages]);
        } catch (\Throwable $e) {
            error_log("ERRO getTicketMessagesAdmin: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao carregar mensagens"], 500);
        }
    }

    /**
     * Admin altera urgência e prioridade do ticket
     * POST /api/support/update-ticket
     */
    public function updateTicket($data, $loggedUser) {
        $this->authorize($loggedUser);
        
        $ticketId = $data['ticket_id'] ?? null;
        $urgencyCode = strtoupper($data['urgency_code'] ?? '');
        $priority = strtoupper($data['priority'] ?? '');
        $assignedTo = $data['assigned_to'] ?? null;
        $status = strtoupper($data['status'] ?? '');

        if (!$ticketId) {
            return Response::json(["success" => false, "message" => "ID do ticket é obrigatório"]);
        }

        // Validações
        $validUrgency = ['U1', 'U2', 'U3', 'U4', 'U5'];
        $validPriority = ['LOW', 'MEDIUM', 'HIGH'];

        $updates = [];
        $params = [];

        if (!empty($urgencyCode) && in_array($urgencyCode, $validUrgency)) {
            $updates[] = "urgency_code = ?";
            $params[] = $urgencyCode;
        }

        if (!empty($priority) && in_array($priority, $validPriority)) {
            $updates[] = "priority = ?";
            $params[] = $priority;
        }

        if ($assignedTo !== null) {
            $updates[] = "assigned_to = ?";
            $params[] = $assignedTo;
        }

        if (!empty($status)) {
            $validStatus = ['OPEN', 'IN_PROGRESS', 'CLOSED'];
            if (in_array($status, $validStatus)) {
                $updates[] = "status = ?";
                $params[] = $status;
                if ($status === 'CLOSED') {
                    $updates[] = "resolved_at = NOW()";
                }
            }
        }

        if (empty($updates)) {
            return Response::json(["success" => false, "message" => "Nenhuma alteração informada"]);
        }

        $updates[] = "last_update = NOW()";
        $params[] = $ticketId;

        try {
            $sql = "UPDATE support_tickets SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute($params);

            if ($success) {
                // Notifica usuário sobre a alteração
                $ticketStmt = $this->db->prepare("SELECT user_id FROM support_tickets WHERE id = ?");
                $ticketStmt->execute([$ticketId]);
                $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($ticket) {
                    $this->notif->notify($ticket['user_id'], "Ticket atualizado", "Seu chamado foi atualizado pelo suporte.");
                }
                
                return Response::json(["success" => true, "message" => "Ticket atualizado"]);
            }
            
            return Response::json(["success" => false, "message" => "Erro ao atualizar"]);
        } catch (\Throwable $e) {
            error_log("ERRO updateTicket: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao atualizar ticket"], 500);
        }
    }

    /**
     * Determina o nível de prioridade do usuário baseado no plano
     */
    private function getUserPriorityLevel($userId) {
        // Buscar módulos ativos do usuário
        $stmt = $this->db->prepare("
            SELECT p.name, p.category, p.price
            FROM user_modules um
            LEFT JOIN plans p ON p.id = um.plan_id
            WHERE um.user_id = ?
            AND um.status = 'active'
            AND (um.expires_at IS NULL OR um.expires_at >= NOW())
            ORDER BY p.price DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            return 1; // Free
        }

        $planName = strtolower($plan['name'] ?? '');
        $category = strtolower($plan['category'] ?? '');

        // Verifica plano de publicidade
        if (strpos($planName, 'ouro') !== false || strpos($planName, 'gold') !== false) {
            return 5;
        }
        if (strpos($planName, 'prata') !== false || strpos($planName, 'silver') !== false) {
            return 4;
        }
        if (strpos($planName, 'bronze') !== false) {
            return 3;
        }

        // Verifica assinatura de recursos (qualquer assinatura paga)
        if ($category === 'advertising' || $category === 'user_subscription') {
            return 2;
        }

        return 1; // Free
    }
}