<?php

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\AuditRepository;
use Exception;

class AuditController {
    private $auditRepo;
    private $db;

    public function __construct($db) {
        $this->db = $db;
        $this->auditRepo = new AuditRepository($db);
    }

    /**
     * Lista todos os logs com paginação e filtros
     * Apenas para ADMIN e MANAGER
     */
    public function index($data, $loggedUser) {
        // 1. Validação de Acesso
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role']), ['ADMIN', 'MANAGER'])) {
            return Response::json(["success" => false, "message" => "Acesso negado"], 403);
        }

        try {
            // 2. Parâmetros de Filtro e Paginação
            $filters = [
                'user_id'     => $data['user_id'] ?? null,
                'target_type' => $data['target_type'] ?? null, // ex: 'freights' ou 'users'
                'target_id'   => $data['target_id'] ?? null,
                'action_type' => $data['action_type'] ?? null,
                'date_start'  => $data['date_start'] ?? null,
                'date_end'    => $data['date_end'] ?? null
            ];

            $page = (int)($data['page'] ?? 1);
            $perPage = (int)($data['perPage'] ?? 20);

            // 3. Busca os dados no repositório
            $logs = $this->auditRepo->listLogs($filters, $page, $perPage);
            $total = $this->auditRepo->countLogs($filters);

            return Response::json([
                "success" => true,
                "data" => $logs,
                "pagination" => [
                    "current_page" => $page,
                    "per_page" => $perPage,
                    "total_records" => $total,
                    "total_pages" => ceil($total / $perPage)
                ]
            ]);

        } catch (Exception $e) {
            error_log("Erro ao listar logs: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro interno ao carregar logs"], 500);
        }
    }

    /**
     * Busca detalhes de um log específico (útil para ver o diff do JSON)
     */
    public function show($data, $loggedUser) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role']), ['ADMIN', 'MANAGER'])) {
            return Response::json(["success" => false, "message" => "Acesso negado"], 403);
        }

        $id = (int)($data['id'] ?? 0);
        $log = $this->auditRepo->findById($id);

        if (!$log) {
            return Response::json(["success" => false, "message" => "Log não encontrado"], 404);
        }

        // Decodifica os JSONs para o Front tratar como objeto
        $log['old_values'] = json_decode($log['old_values'], true);
        $log['new_values'] = json_decode($log['new_values'], true);

        return Response::json([
            "success" => true,
            "data" => $log
        ]);
    }
}