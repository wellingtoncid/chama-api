<?php
namespace App\Controllers;

use App\Core\Response;
use App\Core\Auth;
use App\Repositories\ArticleAuthorRequestRepository;

class ArticleAuthorRequestController {
    private $requestRepo;

    public function __construct($db) {
        $this->requestRepo = new ArticleAuthorRequestRepository($db);
    }

    /**
     * GET /api/article-author-status - Check if user is approved author
     */
    public function status($data) {
        $user = Auth::requireAuth();
        
        $isApproved = $this->requestRepo->isApprovedAuthor($user['id']);
        $pendingRequest = $this->requestRepo->getPendingByUser($user['id']);

        return Response::json([
            'success' => true,
            'data' => [
                'is_author' => (bool)$isApproved,
                'has_pending_request' => (bool)$pendingRequest,
                'request' => $pendingRequest ?: null
            ]
        ]);
    }

    /**
     * POST /api/article-author-request - Submit author request
     */
    public function store($data) {
        $user = Auth::requireAuth();

        // Check if already an approved author
        if ($this->requestRepo->isApprovedAuthor($user['id'])) {
            return Response::json([
                'success' => false, 
                'message' => 'Você já é um autor aprovado'
            ], 400);
        }

        // Check if there's already a pending request
        $pending = $this->requestRepo->getPendingByUser($user['id']);
        if ($pending) {
            return Response::json([
                'success' => false, 
                'message' => 'Você já tem uma solicitação pendente'
            ], 400);
        }

        $referencesLinks = $data['references_links'] ?? null;
        
        $requestId = $this->requestRepo->create($user['id'], $referencesLinks);
        
        if (!$requestId) {
            return Response::json([
                'success' => false, 
                'message' => 'Erro ao criar solicitação'
            ], 500);
        }

        return Response::json([
            'success' => true,
            'message' => 'Solicitação enviada com sucesso! Aguarde a análise da equipe.',
            'data' => ['request_id' => $requestId]
        ], 201);
    }

    /**
     * GET /api/admin/article-author-requests - Get all requests (admin)
     */
    public function getAll($data) {
        Auth::requireRole('admin');
        
        $filters = [
            'status' => $data['status'] ?? null,
            'limit' => min((int)($data['limit'] ?? 50), 100),
            'offset' => (int)($data['offset'] ?? 0)
        ];

        $requests = $this->requestRepo->getAll($filters);
        $stats = [
            'total' => (int)$this->requestRepo->count(),
            'pending' => (int)$this->requestRepo->count('pending'),
            'approved' => (int)$this->requestRepo->count('approved'),
            'rejected' => (int)$this->requestRepo->count('rejected')
        ];

        return Response::json([
            'success' => true,
            'data' => [
                'requests' => $requests,
                'stats' => $stats
            ]
        ]);
    }

    /**
     * GET /api/admin/article-author-requests/pending - Get pending requests (admin)
     */
    public function getPending($data) {
        Auth::requireRole('admin');
        
        $limit = min((int)($data['limit'] ?? 20), 50);
        $offset = (int)($data['offset'] ?? 0);

        $requests = $this->requestRepo->getPending($limit, $offset);
        $total = $this->requestRepo->count('pending');

        return Response::json([
            'success' => true,
            'data' => [
                'requests' => $requests,
                'total' => $total
            ]
        ]);
    }

    /**
     * PUT /api/admin/article-author-requests/:id/approve - Approve request (admin)
     */
    public function approve($data) {
        Auth::requireRole('admin');
        
        $id = (int)$data['id'];
        $request = $this->requestRepo->findById($id);
        
        if (!$request) {
            return Response::json(['success' => false, 'message' => 'Solicitação não encontrada'], 404);
        }

        $adminUser = Auth::requireAuth();
        $this->requestRepo->approve($id, $adminUser['id']);

        return Response::json([
            'success' => true,
            'message' => 'Autor aprovado com sucesso!'
        ]);
    }

    /**
     * PUT /api/admin/article-author-requests/:id/reject - Reject request (admin)
     */
    public function reject($data) {
        Auth::requireRole('admin');
        
        $id = (int)$data['id'];
        $reason = $data['reason'] ?? 'Solicitação não atendida';
        
        $request = $this->requestRepo->findById($id);
        if (!$request) {
            return Response::json(['success' => false, 'message' => 'Solicitação não encontrada'], 404);
        }

        $adminUser = Auth::requireAuth();
        $this->requestRepo->reject($id, $adminUser['id'], $reason);

        return Response::json([
            'success' => true,
            'message' => 'Solicitação rejeitada'
        ]);
    }
}