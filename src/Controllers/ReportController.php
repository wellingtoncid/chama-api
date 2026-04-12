<?php
namespace App\Controllers;

use App\Core\Response;
use App\Repositories\ReportRepository;

class ReportController {
    private $db;
    private $repo;
    private $notif;

    public function __construct($db, $loggedUser = null) {
        $this->db = $db;
        $this->repo = new ReportRepository($db);
        $this->loggedUser = $loggedUser;
    }

    public function create($data, $loggedUser = null) {
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Você precisa estar logado para fazer uma denúncia."], 401);
        }

        $targetType = $data['target_type'] ?? '';
        $targetId = (int)($data['target_id'] ?? 0);
        $reason = $data['reason'] ?? '';
        $description = trim($data['description'] ?? '');
        
        $validReasons = ['spam', 'harassment', 'fake', 'fraud', 'inappropriate', 'other'];
        $validTargets = ['user', 'review', 'freight', 'listing', 'message'];

        if (!in_array($reason, $validReasons)) {
            return Response::json(["success" => false, "message" => "Motivo inválido."], 400);
        }

        if (!in_array($targetType, $validTargets)) {
            return Response::json(["success" => false, "message" => "Tipo de alvo inválido."], 400);
        }

        if (!$targetId) {
            return Response::json(["success" => false, "message" => "ID do alvo é obrigatório."], 400);
        }

        if ($reason === 'other' && empty($description)) {
            return Response::json(["success" => false, "message" => "Por favor, descreva o motivo da denúncia."], 400);
        }

        if ($this->repo->existsDuplicate($loggedUser['id'], $targetType, $targetId)) {
            return Response::json(["success" => false, "message" => "Você já fez uma denúncia para este item."], 400);
        }

        $targetUserId = null;
        if ($targetType === 'user') {
            $targetUserId = $targetId;
        } elseif ($targetType === 'review') {
            $stmt = $this->db->prepare("SELECT target_id FROM reviews WHERE id = ?");
            $stmt->execute([$targetId]);
            $review = $stmt->fetch();
            if ($review) {
                $targetUserId = $review['target_id'];
            }
        }

        try {
            $reportId = $this->repo->create([
                'reporter_id' => $loggedUser['id'],
                'target_user_id' => $targetUserId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'reason' => $reason,
                'description' => $description
            ]);

            if ($reportId) {
                error_log("REPORT CREATED: Report {$reportId} by user {$loggedUser['id']} for {$targetType}:{$targetId}");
                return Response::json([
                    "success" => true,
                    "message" => "Denúncia enviada com sucesso. Nossa equipe irá analisar.",
                    "report_id" => $reportId
                ]);
            }

            return Response::json(["success" => false, "message" => "Erro ao criar denúncia."], 500);
        } catch (\Exception $e) {
            error_log("Error creating report: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao processar denúncia."], 500);
        }
    }

    public function listMine($data, $loggedUser = null) {
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Não autorizado."], 401);
        }

        $limit = (int)($data['limit'] ?? 20);
        $offset = (int)($data['offset'] ?? 0);

        $reports = $this->repo->getByReporter($loggedUser['id'], $limit, $offset);

        return Response::json([
            "success" => true,
            "data" => $reports
        ]);
    }

    public function getAll($data, $loggedUser = null) {
        $role = strtoupper($loggedUser['role'] ?? '');
        if (!in_array($role, ['ADMIN', 'MANAGER', 'SUPPORT', 'FINANCE', 'GERENTE'])) {
            return Response::json(["success" => false, "message" => "Não autorizado."], 403);
        }

        $status = $data['status'] ?? null;
        $limit = (int)($data['limit'] ?? 20);
        $offset = (int)($data['offset'] ?? 0);

        $reports = $this->repo->getAll([
            'status' => $status,
            'limit' => $limit,
            'offset' => $offset
        ]);

        $counts = $this->repo->countByStatus();

        return Response::json([
            "success" => true,
            "data" => $reports,
            "counts" => $counts
        ]);
    }

    public function get($data, $loggedUser = null) {
        $role = strtoupper($loggedUser['role'] ?? '');
        if (!in_array($role, ['ADMIN', 'MANAGER', 'SUPPORT', 'FINANCE', 'GERENTE'])) {
            return Response::json(["success" => false, "message" => "Não autorizado."], 403);
        }

        $reportId = (int)($data['id'] ?? 0);
        if (!$reportId) {
            return Response::json(["success" => false, "message" => "ID é obrigatório."], 400);
        }

        $report = $this->repo->findById($reportId);
        if (!$report) {
            return Response::json(["success" => false, "message" => "Denúncia não encontrada."], 404);
        }

        return Response::json([
            "success" => true,
            "data" => $report
        ]);
    }

    public function assign($data, $loggedUser = null) {
        $role = strtoupper($loggedUser['role'] ?? '');
        if (!in_array($role, ['ADMIN', 'MANAGER', 'SUPPORT', 'FINANCE', 'GERENTE'])) {
            return Response::json(["success" => false, "message" => "Não autorizado."], 403);
        }

        $reportId = (int)($data['id'] ?? 0);
        if (!$reportId) {
            return Response::json(["success" => false, "message" => "ID é obrigatório."], 400);
        }

        $report = $this->repo->findById($reportId);
        if (!$report) {
            return Response::json(["success" => false, "message" => "Denúncia não encontrada."], 404);
        }

        if ($report['status'] !== 'pending') {
            return Response::json(["success" => false, "message" => "Esta denúncia já está em análise."], 400);
        }

        $this->repo->assign($reportId, $loggedUser['id']);
        
        error_log("REPORT ASSIGNED: Report {$reportId} assigned to admin {$loggedUser['id']}");

        return Response::json([
            "success" => true,
            "message" => "Denúncia atribuída a você."
        ]);
    }

    public function resolve($data, $loggedUser = null) {
        $role = strtoupper($loggedUser['role'] ?? '');
        if (!in_array($role, ['ADMIN', 'MANAGER', 'GERENTE'])) {
            return Response::json(["success" => false, "message" => "Não autorizado."], 403);
        }

        $reportId = (int)($data['id'] ?? 0);
        $notes = trim($data['notes'] ?? 'Denúncia resolvida.');

        if (!$reportId) {
            return Response::json(["success" => false, "message" => "ID é obrigatório."], 400);
        }

        $report = $this->repo->findById($reportId);
        if (!$report) {
            return Response::json(["success" => false, "message" => "Denúncia não encontrada."], 404);
        }

        $this->repo->resolve($reportId, $loggedUser['id'], $notes);
        
        error_log("REPORT RESOLVED: Report {$reportId} resolved by admin {$loggedUser['id']}. Notes: {$notes}");

        return Response::json([
            "success" => true,
            "message" => "Denúncia resolvida."
        ]);
    }

    public function dismiss($data, $loggedUser = null) {
        $role = strtoupper($loggedUser['role'] ?? '');
        if (!in_array($role, ['ADMIN', 'MANAGER', 'SUPPORT', 'GERENTE'])) {
            return Response::json(["success" => false, "message" => "Não autorizado."], 403);
        }

        $reportId = (int)($data['id'] ?? 0);
        $notes = trim($data['notes'] ?? 'Denúncia descartada.');

        if (!$reportId) {
            return Response::json(["success" => false, "message" => "ID é obrigatório."], 400);
        }

        $report = $this->repo->findById($reportId);
        if (!$report) {
            return Response::json(["success" => false, "message" => "Denúncia não encontrada."], 404);
        }

        $this->repo->dismiss($reportId, $loggedUser['id'], $notes);
        
        error_log("REPORT DISMISSED: Report {$reportId} dismissed by admin {$loggedUser['id']}. Notes: {$notes}");

        return Response::json([
            "success" => true,
            "message" => "Denúncia descartada."
        ]);
    }

    public function delete($data, $loggedUser = null) {
        $role = strtoupper($loggedUser['role'] ?? '');
        if ($role !== 'ADMIN') {
            return Response::json(["success" => false, "message" => "Não autorizado."], 403);
        }

        $reportId = (int)($data['id'] ?? 0);
        if (!$reportId) {
            return Response::json(["success" => false, "message" => "ID é obrigatório."], 400);
        }

        $this->repo->delete($reportId);
        
        error_log("REPORT DELETED: Report {$reportId} deleted by admin {$loggedUser['id']}");

        return Response::json([
            "success" => true,
            "message" => "Denúncia excluída."
        ]);
    }
}
