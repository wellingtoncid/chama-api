<?php

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\AdminRepository;
use App\Repositories\UserRepository;
use App\Services\NotificationService;
use Exception;
use PDO;

class AdminUserController
{
    private PDO $db;
    private AdminRepository $repo;
    private NotificationService $notif;
    private ?array $loggedUser;

    public function __construct(PDO $db, ?array $loggedUser = null)
    {
        $this->db = $db;
        $this->repo = new AdminRepository($db);
        $this->notif = new NotificationService($db);
        $this->loggedUser = $loggedUser;
    }

    private function authorize(?array $loggedUser = null, string $minRole = 'MANAGER'): void
    {
        $user = $loggedUser ?? $this->loggedUser;
        if (!$user) {
            throw new Exception('Sessão expirada ou usuário não identificado.', 401);
        }
        $userRole = strtolower($user['role'] ?? '');
        $roleHierarchy = ['admin' => 5, 'manager' => 4, 'analyst' => 3, 'assistant' => 2];
        $userLevel = $roleHierarchy[$userRole] ?? 0;
        $requiredLevel = $roleHierarchy[strtolower($minRole)] ?? 0;
        if ($userLevel < $requiredLevel) {
            throw new Exception('Acesso negado. Permissão insuficiente.', 403);
        }
    }

    public function createUser($loggedUser)
    {
        try {
            $this->authorize($loggedUser, 'MANAGER');
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                return Response::json(['success' => false, 'message' => 'Dados inválidos'], 400);
            }

            $userType = strtoupper($input['user_type'] ?? '');
            if ($userType === 'DRIVER') {
                $requiredFields = ['name', 'document', 'email', 'password'];
                foreach ($requiredFields as $field) {
                    if (empty($input[$field])) {
                        return Response::json(['success' => false, 'message' => "O campo $field é obrigatório para Motorista"], 400);
                    }
                }
                $cpf = preg_replace('/\D/', '', $input['document']);
                if (strlen($cpf) !== 11) {
                    return Response::json(['success' => false, 'message' => 'CPF deve ter 11 dígitos'], 400);
                }
            } elseif ($userType === 'COMPANY') {
                $requiredFields = ['name', 'document', 'email', 'password', 'owner_name'];
                foreach ($requiredFields as $field) {
                    if (empty($input[$field])) {
                        return Response::json(['success' => false, 'message' => "O campo $field é obrigatório para Empresa"], 400);
                    }
                }
                $cnpj = preg_replace('/\D/', '', $input['document']);
                if (strlen($cnpj) !== 14) {
                    return Response::json(['success' => false, 'message' => 'CNPJ deve ter 14 dígitos'], 400);
                }
            } elseif ($userType === 'SYSTEM') {
                $requiredFields = ['name', 'email', 'password', 'role'];
                foreach ($requiredFields as $field) {
                    if (empty($input[$field])) {
                        return Response::json(['success' => false, 'message' => "O campo $field é obrigatório para usuário do Sistema"], 400);
                    }
                }
            } else {
                return Response::json(['success' => false, 'message' => 'Tipo de usuário inválido. Use: DRIVER, COMPANY ou SYSTEM'], 400);
            }

            if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
                return Response::json(['success' => false, 'message' => 'Email em formato inválido'], 400);
            }

            $userRepo = new UserRepository($this->db);

            if ($userType === 'SYSTEM') {
                $stmtCheck = $this->db->prepare('SELECT id FROM users WHERE email = ?');
                $stmtCheck->execute([$input['email']]);
                if ($stmtCheck->fetch()) {
                    return Response::json(['success' => false, 'message' => 'Este email já está cadastrado'], 400);
                }

                $roleSlug = strtolower($input['role']);
                $stmtRole = $this->db->prepare('SELECT id FROM roles WHERE slug = ? LIMIT 1');
                $stmtRole->execute([$roleSlug]);
                $roleId = $stmtRole->fetchColumn() ?: 2;

                $stmtInsert = $this->db->prepare("INSERT INTO users (name, email, password, whatsapp, role, role_id, user_type, account_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'OPERATOR', 1, 'active', NOW())");
                $stmtInsert->execute([$input['name'], $input['email'], password_hash($input['password'], PASSWORD_BCRYPT), preg_replace('/\D/', '', $input['whatsapp'] ?? ''), $roleSlug, $roleId]);
                $userId = $this->db->lastInsertId();

                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $input['name']))) . '-' . $userId;
                $this->db->prepare("INSERT INTO user_profiles (user_id, slug, availability_status, created_at) VALUES (?, ?, 'available', NOW())")->execute([$userId, $slug]);

                return Response::json(['success' => true, 'message' => 'Usuário do sistema criado com sucesso!', 'user_id' => $userId], 201);
            } else {
                $dataForRepo = [
                    'name' => $input['name'],
                    'email' => $input['email'],
                    'password' => password_hash($input['password'], PASSWORD_BCRYPT),
                    'whatsapp' => $input['whatsapp'] ?? '',
                    'document' => $input['document'],
                    'document_type' => ($userType === 'COMPANY') ? 'CNPJ' : 'CPF',
                    'role' => ($userType === 'COMPANY') ? 'company' : 'driver',
                    'user_type' => $userType,
                ];
                if ($userType === 'COMPANY') {
                    $dataForRepo['owner_name'] = $input['owner_name'];
                    $dataForRepo['corporate_name'] = $input['name'];
                    $dataForRepo['name_fantasy'] = $input['name_fantasy'] ?? null;
                }
                try {
                    $userId = $userRepo->create($dataForRepo);
                    return Response::json(['success' => true, 'message' => ($userType === 'COMPANY' ? 'Empresa' : 'Motorista') . ' criado com sucesso!', 'user_id' => $userId], 201);
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), '1062') !== false) {
                        return Response::json(['success' => false, 'message' => 'Este email ou documento já está cadastrado no sistema.'], 409);
                    }
                    throw $e;
                }
            }
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro interno no servidor: ' . $e->getMessage()], 500);
        }
    }

    public function createInternalUser($loggedUser)
    {
        try {
            $this->authorize($loggedUser, 'admin');
            $input = json_decode(file_get_contents('php://input'), true);
            $required = ['name', 'email', 'password', 'role'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    throw new Exception("Campo $field obrigatório.");
                }
            }
            $input['user_type'] = ($input['role'] === 'admin') ? 'ADMIN' : 'OPERATOR';
            $input['permissions'] = json_encode($this->getDefaultPermissionsByRole($input['role']));
            $result = $this->repo->createInternalUser($input, $loggedUser['id']);
            return Response::json($result, $result['success'] ? 201 : 500);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    private function getDefaultPermissionsByRole($role)
    {
        $roles = [
            'admin'     => ['all' => true],
            'manager'   => ['edit_freight' => true, 'view_finance' => true, 'support_chat' => true],
            'analyst'   => ['view_freight' => true, 'verify_documents' => true],
            'assistant' => ['support_chat' => true, 'view_users' => true],
        ];
        return $roles[$role] ?? [];
    }

    public function listUsers($data, $loggedUser)
    {
        try {
            $this->authorize($loggedUser, 'manager');
            $role = (!empty($data['role'])) ? $data['role'] : '%';
            $search = (!empty($data['search'])) ? $data['search'] : '';
            $users = $this->repo->listUsersByRole($role, $search);
            return Response::json(['success' => true, 'count' => count($users), 'data' => $users]);
        } catch (Exception $e) {
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 400;
            return Response::json(['success' => false, 'message' => $e->getMessage()], $code);
        }
    }

    public function searchUsers($data, $loggedUser)
    {
        try {
            if (!$loggedUser) {
                return Response::json(['success' => false, 'message' => 'Não autorizado'], 401);
            }
            $query = (!empty($data['q'])) ? trim($data['q']) : '';
            if (strlen($query) < 2) {
                return Response::json(['success' => true, 'data' => []]);
            }
            $limit = (!empty($data['limit'])) ? (int)$data['limit'] : 10;
            $users = $this->repo->searchUsers($query, $limit);
            return Response::json(['success' => true, 'data' => $users]);
        } catch (Exception $e) {
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 400;
            return Response::json(['success' => false, 'message' => $e->getMessage()], $code);
        }
    }

    public function getTeamUsers($data, $loggedUser)
    {
        try {
            $users = $loggedUser ? $this->repo->getTeamUsers() : [];
            return Response::json(['success' => true, 'data' => $users]);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function manageUsers($data, $loggedUser)
    {
        try {
            $this->authorize($loggedUser);
            $id = $data['id'] ?? null;
            if (!$id) {
                throw new Exception('ID do usuário é obrigatório.');
            }
            $oldData = $this->repo->getUserById($id);
            if ($this->repo->updateUserDetails($data)) {
                $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'UPDATE_USER', "Editou dados do usuário #{$id}", $id, 'USER', $oldData, $data);
                return Response::json(['success' => true]);
            }
            return Response::json(['success' => false, 'message' => 'Falha ao atualizar no banco.']);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function verifyUser($data, $loggedUser)
    {
        try {
            $this->authorize($loggedUser);
            $id = $data['id'] ?? null;
            $status = isset($data['status']) ? (int)$data['status'] : 1;
            if ($this->repo->setUserVerification($id, $status)) {
                $actionLabel = $status == 1 ? 'Verificado/Aprovado' : 'Removida Verificação';
                $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'VERIFY_USER', "$actionLabel usuário #$id", $id, 'USER');
                if ($status == 1) {
                    $this->notif->notify($id, 'Perfil Verificado!', 'Sua conta foi aprovada manualmente pelo administrador.');
                }
                return Response::json(['success' => true]);
            }
            return Response::json(['success' => false]);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function deleteUser($data, $loggedUser)
    {
        try {
            $this->authorize($loggedUser);
            $id = $data['id'] ?? null;
            $permanent = isset($data['permanent']) && $data['permanent'] === true;
            $targetUser = $this->repo->getUserById($id);
            $userName = $targetUser['name'] ?? 'Desconhecido';
            if ($permanent) {
                $this->authorize($loggedUser, 'ADMIN');
                $success = $this->repo->deleteUserPermanently($id);
                $logMsg = "EXCLUSÃO PERMANENTE do usuário #$id ($userName)";
            } else {
                $success = $this->repo->softDeleteUser($id);
                $logMsg = "Soft Delete (Desativação) do usuário #$id ($userName)";
            }
            if ($success) {
                $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'DELETE_USER', $logMsg, $id, 'USER', $targetUser, ['status' => 'deleted/inactive']);
            }
            return Response::json(['success' => $success]);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getUserDetails($data, $loggedUser)
    {
        $this->authorize($loggedUser);
        $id = $data['id'] ?? null;
        if (!$id) {
            return Response::json(['success' => false, 'message' => 'ID não fornecido'], 400);
        }
        $user = $this->repo->getUserFullDetails($id);
        if (!$user) {
            return Response::json(['success' => false, 'message' => 'Usuário não encontrado'], 404);
        }
        return Response::json(['success' => true, 'data' => $user]);
    }

    public function updateUser($data, $loggedUser)
    {
        $this->authorize($loggedUser);
        if (!isset($data['id'])) {
            return Response::json(['success' => false, 'message' => 'ID do usuário é obrigatório'], 400);
        }
        $result = $this->repo->updateUserDetails($data);
        if ($result['success']) {
            $this->repo->saveLog($loggedUser['id'], $loggedUser['name'], 'USER_UPDATE', 'Editou dados do usuário ID: ' . $data['id'], $data['id'], 'USER');
            return Response::json(['success' => true, 'message' => 'Usuário atualizado com sucesso!']);
        }
        return Response::json(['success' => false, 'message' => 'Erro ao atualizar: ' . $result['error']], 500);
    }

    public function listCompanyMembers($loggedUser)
    {
        $this->authorize($loggedUser);
        $companyId = $_GET['company_id'] ?? null;
        if (!$companyId || !is_numeric($companyId)) {
            return Response::json(['success' => false, 'message' => 'ID da empresa inválido']);
        }
        $members = $this->repo->getCompanyMembers($companyId);
        return Response::json(['success' => true, 'data' => $members]);
    }

    public function listAllUsers($data, $loggedUser)
    {
        $this->authorize($loggedUser, 'ADMIN');
        try {
            $role = $data['role'] ?? '%';
            $status = $data['status'] ?? '%';
            $search = $data['search'] ?? '';
            $sql = 'SELECT u.id, u.name, u.email, u.role, u.phone, u.whatsapp, u.created_at, u.user_type, u.access_level, u.parent_id, u.verification_status, u.is_active, u.completion_score, a.corporate_name as company_name, a.document_number as company_document, a.trade_name as company_trade_name, p.name as parent_name, pl.name as plan_name FROM users u LEFT JOIN accounts a ON u.account_id = a.id LEFT JOIN users p ON u.parent_id = p.id LEFT JOIN plans pl ON u.plan_id = pl.id WHERE u.role LIKE :role AND u.deleted_at IS NULL ORDER BY u.created_at DESC LIMIT 100';
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':role' => $role]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Response::json(['success' => true, 'data' => $users]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao listar usuários']);
        }
    }

    public function addUserNote($data, $loggedUser)
    {
        $this->authorize($loggedUser);
        $targetUserId = $data['user_id'];
        $note = $data['note'];
        $success = $this->repo->saveInternalNote($targetUserId, $loggedUser['id'], $note);
        return Response::json(['success' => $success]);
    }

    public function getUserNotes($data, $loggedUser)
    {
        $this->authorize($loggedUser);
        $userId = $data['user_id'] ?? null;
        if (!$userId) {
            return Response::json(['success' => false, 'message' => 'user_id é obrigatório']);
        }
        $stmt = $this->db->prepare("SELECT id, description, created_at, created_by_name FROM audit_log WHERE target_id = :target_id AND target_type = 'USER' ORDER BY created_at DESC LIMIT 50");
        $stmt->execute([':target_id' => $userId]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return Response::json(['success' => true, 'data' => $notes]);
    }
}
