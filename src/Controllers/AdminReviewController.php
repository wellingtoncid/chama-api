<?php

namespace App\Controllers;

use App\Core\Response;
use Exception;
use PDO;

class AdminReviewController
{
    private PDO $db;
    private $notif;
    private ?array $loggedUser;

    public function __construct(PDO $db, ?array $loggedUser = null)
    {
        $this->db = $db;
        $this->notif = new \App\Services\NotificationService($db);
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

    public function getReviews($data, $loggedUser = null)
    {
        $this->authorize($loggedUser, 'MANAGER');
        try {
            $status = $data['status'] ?? null;
            $limit = (int)($data['limit'] ?? 20);
            $offset = (int)($data['offset'] ?? 0);
            $reviewRepo = new \App\Repositories\ReviewRepository($this->db);
            $reviews = $reviewRepo->getAll(['limit' => $limit, 'offset' => $offset, 'status' => $status]);
            $pendingCount = $reviewRepo->getCountAll(['status' => 'pending']);
            $publishedCount = $reviewRepo->getCountAll(['status' => 'published']);
            $rejectedCount = $reviewRepo->getCountAll(['status' => 'rejected']);
            return Response::json([
                'success' => true,
                'data' => $reviews,
                'counts' => ['pending' => $pendingCount, 'published' => $publishedCount, 'rejected' => $rejectedCount, 'total' => $pendingCount + $publishedCount + $rejectedCount],
                'pagination' => ['limit' => $limit, 'offset' => $offset],
            ]);
        } catch (\Exception $e) {
            error_log('Error getting reviews: ' . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao buscar avaliações.'], 500);
        }
    }

    public function approveReview($data, $loggedUser = null)
    {
        $this->authorize($loggedUser, 'MANAGER');
        $reviewId = (int)($data['review_id'] ?? $data['id'] ?? 0);
        if (!$reviewId) {
            return Response::json(['success' => false, 'message' => 'ID da avaliação é obrigatório.'], 400);
        }
        try {
            $reviewRepo = new \App\Repositories\ReviewRepository($this->db);
            $review = $reviewRepo->findById($reviewId);
            if (!$review) {
                return Response::json(['success' => false, 'message' => 'Avaliação não encontrada.'], 404);
            }
            if ($review['status'] !== 'pending') {
                return Response::json(['success' => false, 'message' => 'Apenas avaliações pendentes podem ser aprovadas.'], 400);
            }
            $reviewRepo->approve($reviewId);
            if ($this->notif) {
                $this->notif->createNotification($review['target_id'], 'Avaliação Aprovada', 'Sua avaliação foi publicada no seu perfil.', 'review', $reviewId);
            }
            error_log("REVIEW APPROVED: Review {$reviewId} approved by admin {$loggedUser['id']}");
            return Response::json(['success' => true, 'message' => 'Avaliação aprovada e publicada.']);
        } catch (\Exception $e) {
            error_log('Error approving review: ' . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao aprovar avaliação.'], 500);
        }
    }

    public function rejectReview($data, $loggedUser = null)
    {
        $this->authorize($loggedUser, 'MANAGER');
        $reviewId = (int)($data['review_id'] ?? $data['id'] ?? 0);
        $reason = trim($data['reason'] ?? 'Violação das diretrizes da comunidade.');
        if (!$reviewId) {
            return Response::json(['success' => false, 'message' => 'ID da avaliação é obrigatório.'], 400);
        }
        try {
            $reviewRepo = new \App\Repositories\ReviewRepository($this->db);
            $review = $reviewRepo->findById($reviewId);
            if (!$review) {
                return Response::json(['success' => false, 'message' => 'Avaliação não encontrada.'], 404);
            }
            $reviewRepo->reject($reviewId, $reason);
            if ($this->notif) {
                $this->notif->createNotification($review['reviewer_id'], 'Avaliação Rejeitada', "Sua avaliação foi rejeitada: {$reason}", 'review', $reviewId);
            }
            error_log("REVIEW REJECTED: Review {$reviewId} rejected by admin {$loggedUser['id']}. Reason: {$reason}");
            return Response::json(['success' => true, 'message' => 'Avaliação rejeitada.']);
        } catch (\Exception $e) {
            error_log('Error rejecting review: ' . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao rejeitar avaliação.'], 500);
        }
    }

    public function deleteReview($data, $loggedUser = null)
    {
        $this->authorize($loggedUser, 'MANAGER');
        $reviewId = (int)($data['review_id'] ?? $data['id'] ?? 0);
        if (!$reviewId) {
            return Response::json(['success' => false, 'message' => 'ID da avaliação é obrigatório.'], 400);
        }
        try {
            $reviewRepo = new \App\Repositories\ReviewRepository($this->db);
            $review = $reviewRepo->findById($reviewId);
            if (!$review) {
                return Response::json(['success' => false, 'message' => 'Avaliação não encontrada.'], 404);
            }
            $reviewRepo->delete($reviewId);
            error_log("REVIEW DELETED: Review {$reviewId} deleted by admin {$loggedUser['id']}");
            return Response::json(['success' => true, 'message' => 'Avaliação excluída.']);
        } catch (\Exception $e) {
            error_log('Error deleting review: ' . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao excluir avaliação.'], 500);
        }
    }
}
