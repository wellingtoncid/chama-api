<?php
namespace App\Controllers;

use PDO;
use App\Core\Response;
use App\Core\Auth;

class AdminAffiliateController
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function listInterests($params)
    {
        $user = Auth::requireAuth();
        
        if (!Auth::hasAnyRole(['admin', 'gerente', 'suporte'])) {
            return Response::json([
                'success' => false,
                'message' => 'Acesso não autorizado.'
            ], 403);
        }

        $status = $params['status'] ?? null;
        $page = (int)($params['page'] ?? 1);
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $where = "WHERE 1=1";
        $params = [];

        if ($status && in_array($status, ['pending', 'approved', 'rejected'])) {
            $where .= " AND ai.status = ?";
            $params[] = $status;
        }

        $countSql = "SELECT COUNT(*) as total FROM affiliate_interests ai {$where}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetch()['total'];

        $sql = "SELECT 
                    ai.*,
                    u.name as user_name,
                    u.email as user_email,
                    u.role as user_role,
                    p.avatar_url as user_avatar,
                    a.name as approver_name
                FROM affiliate_interests ai
                JOIN users u ON ai.user_id = u.id
                LEFT JOIN user_profiles p ON u.id = p.user_id
                LEFT JOIN users a ON ai.approved_by = a.id
                {$where}
                ORDER BY ai.created_at DESC
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $interests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return Response::json([
            'success' => true,
            'data' => $interests,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }

    public function approveInterest($params, $data)
    {
        $user = Auth::requireAuth();
        
        if (!Auth::hasAnyRole(['admin', 'gerente'])) {
            return Response::json([
                'success' => false,
                'message' => 'Acesso não autorizado.'
            ], 403);
        }

        $interestId = (int)($params['id'] ?? 0);

        if (!$interestId) {
            return Response::json([
                'success' => false,
                'message' => 'ID da solicitação não encontrado.'
            ], 400);
        }

        $stmt = $this->db->prepare("SELECT * FROM affiliate_interests WHERE id = ?");
        $stmt->execute([$interestId]);
        $interest = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$interest) {
            return Response::json([
                'success' => false,
                'message' => 'Solicitação não encontrada.'
            ], 404);
        }

        if ($interest['status'] !== 'pending') {
            return Response::json([
                'success' => false,
                'message' => 'Esta solicitação já foi processada.'
            ], 400);
        }

        $this->db->beginTransaction();

        try {
            $updateStmt = $this->db->prepare("
                UPDATE affiliate_interests 
                SET status = 'approved', 
                    approved_by = ?, 
                    approved_at = NOW(),
                    admin_notes = ?
                WHERE id = ?
            ");
            $adminNotes = trim($data['admin_notes'] ?? 'Aprovado');
            $updateStmt->execute([$user['id'], $adminNotes, $interestId]);

            $userUpdateStmt = $this->db->prepare("UPDATE users SET has_affiliate_access = 1 WHERE id = ?");
            $userUpdateStmt->execute([$interest['user_id']]);

            $this->db->commit();

            return Response::json([
                'success' => true,
                'message' => 'Acesso ao recurso de afiliados liberado com sucesso!'
            ]);
        } catch (\Exception $e) {
            $this->db->rollBack();
            
            return Response::json([
                'success' => false,
                'message' => 'Erro ao processar aprovação.'
            ], 500);
        }
    }

    public function rejectInterest($params, $data)
    {
        $user = Auth::requireAuth();
        
        if (!Auth::hasAnyRole(['admin', 'gerente'])) {
            return Response::json([
                'success' => false,
                'message' => 'Acesso não autorizado.'
            ], 403);
        }

        $interestId = (int)($params['id'] ?? 0);

        if (!$interestId) {
            return Response::json([
                'success' => false,
                'message' => 'ID da solicitação não encontrado.'
            ], 400);
        }

        $stmt = $this->db->prepare("SELECT * FROM affiliate_interests WHERE id = ?");
        $stmt->execute([$interestId]);
        $interest = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$interest) {
            return Response::json([
                'success' => false,
                'message' => 'Solicitação não encontrada.'
            ], 404);
        }

        if ($interest['status'] !== 'pending') {
            return Response::json([
                'success' => false,
                'message' => 'Esta solicitação já foi processada.'
            ], 400);
        }

        $updateStmt = $this->db->prepare("
            UPDATE affiliate_interests 
            SET status = 'rejected', 
                approved_by = ?,
                approved_at = NOW(),
                admin_notes = ?
            WHERE id = ?
        ");
        $adminNotes = trim($data['admin_notes'] ?? 'Rejeitado');
        $updateStmt->execute([$user['id'], $adminNotes, $interestId]);

        return Response::json([
            'success' => true,
            'message' => 'Solicitação rejeitada.'
        ]);
    }

    public function revokeAccess($params)
    {
        $user = Auth::requireAuth();
        
        if (!Auth::hasAnyRole(['admin', 'gerente'])) {
            return Response::json([
                'success' => false,
                'message' => 'Acesso não autorizado.'
            ], 403);
        }

        $interestId = (int)($params['id'] ?? 0);

        if (!$interestId) {
            return Response::json([
                'success' => false,
                'message' => 'ID não encontrado.'
            ], 400);
        }

        $stmt = $this->db->prepare("SELECT user_id FROM affiliate_interests WHERE id = ?");
        $stmt->execute([$interestId]);
        $interest = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$interest) {
            return Response::json([
                'success' => false,
                'message' => 'Registro não encontrado.'
            ], 404);
        }

        $revokeStmt = $this->db->prepare("UPDATE users SET has_affiliate_access = 0 WHERE id = ?");
        $revokeStmt->execute([$interest['user_id']]);

        $updateStmt = $this->db->prepare("
            UPDATE affiliate_interests 
            SET status = 'pending',
                approved_by = NULL,
                approved_at = NULL,
                admin_notes = 'Acesso revogado'
            WHERE id = ?
        ");
        $updateStmt->execute([$interestId]);

        return Response::json([
            'success' => true,
            'message' => 'Acesso ao recurso de afiliados foi revogado.'
        ]);
    }

    public function getStats()
    {
        $user = Auth::requireAuth();
        
        if (!Auth::hasAnyRole(['admin', 'gerente', 'suporte'])) {
            return Response::json([
                'success' => false,
                'message' => 'Acesso não autorizado.'
            ], 403);
        }

        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                AVG(willing_to_pay) as avg_willing_to_pay
            FROM affiliate_interests
        ");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $usersWithAccess = $this->db->query("
            SELECT COUNT(*) as total FROM users WHERE has_affiliate_access = 1
        ")->fetch()['total'];

        return Response::json([
            'success' => true,
            'data' => [
                'interests' => [
                    'total' => (int)$stats['total'],
                    'pending' => (int)$stats['pending'],
                    'approved' => (int)$stats['approved'],
                    'rejected' => (int)$stats['rejected'],
                    'avg_willing_to_pay' => round((float)$stats['avg_willing_to_pay'], 2)
                ],
                'users_with_access' => (int)$usersWithAccess
            ]
        ]);
    }
}
