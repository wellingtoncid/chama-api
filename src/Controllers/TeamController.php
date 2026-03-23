<?php

namespace App\Controllers;

use App\Core\Response;
use Exception;
use PDO;

class TeamController {
    private $db;
    private $loggedUser;

    public function __construct($db, $loggedUser = null) {
        $this->db = $db;
        $this->loggedUser = $loggedUser;
    }

    private function authorize($roles = ['ADMIN', 'MANAGER', 'COMPANY']) {
        if (!$this->loggedUser) {
            throw new Exception("Sessão expirada", 401);
        }
        $userRole = strtoupper($this->loggedUser['role'] ?? '');
        if (!in_array($userRole, array_map('strtoupper', $roles))) {
            throw new Exception("Acesso negado", 403);
        }
    }

    public function getTeam($data, $loggedUser) {
        $this->authorize();
        
        try {
            $companyId = $data['company_id'] ?? $this->loggedUser['account_id'] ?? null;
            $role = $data['role'] ?? '%';
            
            if (!$companyId && in_array(strtoupper($this->loggedUser['role']), ['ADMIN', 'MANAGER'])) {
                $companyId = null;
            }
            
            $sql = "
                SELECT u.id, u.name, u.email, u.phone, u.whatsapp, u.role, u.user_type,
                    u.city, u.state, u.status, u.created_at, u.last_active_at,
                    u.plan_type, u.is_verified, u.avatar
                FROM users u
                WHERE 1=1
            ";
            
            $params = [];
            
            if ($companyId) {
                $sql .= " AND u.account_id = ?";
                $params[] = $companyId;
            }
            
            if ($role !== '%') {
                $sql .= " AND u.role LIKE ?";
                $params[] = $role;
            }
            
            $sql .= " ORDER BY u.created_at DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return Response::json([
                "success" => true,
                "data" => $members
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao carregar equipe"]);
        }
    }

    public function invite($data, $loggedUser) {
        $this->authorize();
        
        try {
            $email = trim($data['email'] ?? '');
            $role = trim($data['role'] ?? 'employee');
            $permissions = $data['permissions'] ?? [];
            $companyId = $this->loggedUser['account_id'] ?? null;
            
            if (empty($email)) {
                return Response::json(["success" => false, "message" => "Email é obrigatório"], 400);
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return Response::json(["success" => false, "message" => "Email inválido"], 400);
            }
            
            $checkStmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
            $checkStmt->execute([$email]);
            if ($checkStmt->fetch()) {
                return Response::json(["success" => false, "message" => "Este email já está cadastrado"], 400);
            }
            
            $checkInvite = $this->db->prepare("SELECT id FROM team_invitations WHERE email = ? AND status = 'pending'");
            $checkInvite->execute([$email]);
            if ($checkInvite->fetch()) {
                return Response::json(["success" => false, "message" => "Este email já possui um convite pendente"], 400);
            }
            
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
            
            $stmt = $this->db->prepare("
                INSERT INTO team_invitations (email, role, permissions, invited_by, company_id, token, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $email,
                $role,
                json_encode($permissions),
                $loggedUser['id'],
                $companyId,
                $token,
                $expiresAt
            ]);
            
            $appUrl = 'https://chamafrete.com.br';
            $inviteLink = "{$appUrl}/accept-invite?token={$token}";
            
            return Response::json([
                "success" => true,
                "message" => "Convite enviado com sucesso",
                "data" => [
                    "token" => $token,
                    "invite_link" => $inviteLink,
                    "expires_at" => $expiresAt
                ]
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao enviar convite"]);
        }
    }

    public function getInvitations($data, $loggedUser) {
        $this->authorize();
        
        try {
            $companyId = $this->loggedUser['account_id'] ?? null;
            
            $sql = "
                SELECT ti.*, u.name as inviter_name
                FROM team_invitations ti
                LEFT JOIN users u ON ti.invited_by = u.id
                WHERE 1=1
            ";
            
            $params = [];
            
            if ($companyId) {
                $sql .= " AND ti.company_id = ?";
                $params[] = $companyId;
            }
            
            $sql .= " ORDER BY ti.created_at DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return Response::json([
                "success" => true,
                "data" => $invitations
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao carregar convites"]);
        }
    }

    public function cancelInvitation($data, $loggedUser) {
        $this->authorize();
        
        try {
            $id = $data['id'] ?? null;
            
            if (!$id) {
                return Response::json(["success" => false, "message" => "ID é obrigatório"], 400);
            }
            
            $stmt = $this->db->prepare("DELETE FROM team_invitations WHERE id = ? AND status = 'pending'");
            $stmt->execute([$id]);
            
            return Response::json([
                "success" => true,
                "message" => "Convite cancelado com sucesso"
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao cancelar convite"]);
        }
    }

    public function acceptInvitation($data, $loggedUser) {
        try {
            $token = $data['token'] ?? '';
            
            if (empty($token)) {
                return Response::json(["success" => false, "message" => "Token é obrigatório"], 400);
            }
            
            $stmt = $this->db->prepare("SELECT * FROM team_invitations WHERE token = ? AND status = 'pending'");
            $stmt->execute([$token]);
            $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invitation) {
                return Response::json(["success" => false, "message" => "Convite inválido ou expirado"], 404);
            }
            
            if (strtotime($invitation['expires_at']) < time()) {
                return Response::json(["success" => false, "message" => "Convite expirado"], 400);
            }
            
            return Response::json([
                "success" => true,
                "data" => [
                    "email" => $invitation['email'],
                    "role" => $invitation['role'],
                    "permissions" => json_decode($invitation['permissions'] ?? '[]', true)
                ]
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao aceitar convite"]);
        }
    }

    public function removeMember($data, $loggedUser) {
        $this->authorize();
        
        try {
            $userId = $data['user_id'] ?? null;
            
            if (!$userId) {
                return Response::json(["success" => false, "message" => "user_id é obrigatório"], 400);
            }
            
            if ($userId == $loggedUser['id']) {
                return Response::json(["success" => false, "message" => "Você não pode remover a si mesmo"], 400);
            }
            
            $checkStmt = $this->db->prepare("SELECT id, role FROM users WHERE id = ?");
            $checkStmt->execute([$userId]);
            $user = $checkStmt->fetch();
            
            if (!$user) {
                return Response::json(["success" => false, "message" => "Usuário não encontrado"], 404);
            }
            
            if ($user['role'] === 'admin') {
                return Response::json(["success" => false, "message" => "Não é possível remover um administrador"], 400);
            }
            
            $stmt = $this->db->prepare("UPDATE users SET deleted_at = NOW(), status = 'inactive' WHERE id = ?");
            $stmt->execute([$userId]);
            
            return Response::json([
                "success" => true,
                "message" => "Membro removido com sucesso"
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao remover membro"]);
        }
    }

    public function updateMember($data, $loggedUser) {
        $this->authorize();
        
        try {
            $userId = $data['user_id'] ?? null;
            $role = $data['role'] ?? null;
            $status = $data['status'] ?? null;
            $permissions = $data['permissions'] ?? null;
            
            if (!$userId) {
                return Response::json(["success" => false, "message" => "user_id é obrigatório"], 400);
            }
            
            $updates = [];
            $params = [];
            
            if ($role) {
                $updates[] = "role = ?";
                $params[] = $role;
            }
            
            if ($status) {
                $updates[] = "status = ?";
                $params[] = $status;
            }
            
            if ($permissions !== null) {
                $updates[] = "permissions = ?";
                $params[] = json_encode($permissions);
            }
            
            if (empty($updates)) {
                return Response::json(["success" => false, "message" => "Nenhuma atualização informada"], 400);
            }
            
            $params[] = $userId;
            
            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return Response::json([
                "success" => true,
                "message" => "Membro atualizado com sucesso"
            ]);
        } catch (\Throwable $e) {
            return Response::json(["success" => false, "message" => "Erro ao atualizar membro"]);
        }
    }
}
