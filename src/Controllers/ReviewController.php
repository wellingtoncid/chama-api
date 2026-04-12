<?php
namespace App\Controllers;

use App\Core\Response;
use App\Repositories\ReviewRepository;
use App\Repositories\UserRepository;
use App\Services\ContentFilterService;
use App\Services\SettingsService;

class ReviewController {
    private $repo;
    private $userRepo;
    private $db;
    private $settings;

    public function __construct($db) {
        $this->db = $db;
        $this->repo = new ReviewRepository($db);
        $this->userRepo = new UserRepository($db);
        $this->settings = new SettingsService($db);
    }

    private function getSetting($key, $default = false) {
        $value = $this->settings->get($key);
        return $value === 'true' || $value === '1' || $value === true || $value === 1;
    }

    public function submit($data, $loggedUser) {
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Faça login para avaliar."], 401);
        }

        $targetId = (int)($data['target_id'] ?? 0);
        $rating = (int)($data['rating'] ?? 0);
        $comment = trim($data['comment'] ?? '');
        $freightId = isset($data['freight_id']) ? (int)$data['freight_id'] : null;

        // Validações básicas
        if (!$targetId) {
            return Response::json(["success" => false, "message" => "ID do usuário é obrigatório."], 400);
        }

        if ($rating < 1 || $rating > 5) {
            return Response::json(["success" => false, "message" => "Nota deve ser entre 1 e 5."], 400);
        }

        try {
            // 1. NÃO PODE SE AUTO-AVALIAR
            if ($loggedUser['id'] === $targetId) {
                throw new \Exception("Você não pode avaliar a si mesmo.");
            }

            // 2. BUSCAR DADOS DO REVIEWER E TARGET PARA VALIDAR ROLES
            $reviewerData = $this->userRepo->findById($loggedUser['id']);
            $targetData = $this->userRepo->findById($targetId);

            if (!$targetData) {
                throw new \Exception("Usuário não encontrado.");
            }

            $reviewerRole = strtolower($reviewerData['role'] ?? '');
            $targetRole = strtolower($targetData['role'] ?? '');

            // 3. MOTORISTA NÃO PODE AVALIAR MOTORISTA
            if ($reviewerRole === 'driver' && $targetRole === 'driver') {
                throw new \Exception("Motoristas não podem avaliar outros motoristas.");
            }

            // 4. VERIFICAR SE JÁ AVALIOU ESTE USUÁRIO
            if ($this->repo->hasReviewed($loggedUser['id'], $targetId)) {
                throw new \Exception("Você já avaliou este usuário.");
            }

            // 5. VALIDAR COMENTÁRIO COM CONTENT FILTER
            $autoRejectBadWords = $this->getSetting('review_auto_reject_bad_words', true);
            if (!empty($comment)) {
                if (!ContentFilterService::isClean($comment)) {
                    if ($autoRejectBadWords) {
                        throw new \Exception("Seu comentário contém conteúdo não permitido e foi bloqueado.");
                    }
                }
            }

            // 6. DETERMINAR STATUS BASEADO EM CONFIGURAÇÕES
            $autoApproveHighRating = $this->getSetting('review_auto_approve_high_rating', true);
            $autoApproveThreshold = (int)$this->settings->get('review_auto_approve_threshold', 4);
            
            $status = 'pending';
            if ($autoApproveHighRating && $rating >= $autoApproveThreshold) {
                $status = 'published';
            } elseif (!$autoApproveHighRating) {
                $status = 'published';
            }

            // 7. SALVAR
            $this->db->beginTransaction();

            $this->repo->create([
                'reviewer_id' => $loggedUser['id'],
                'target_id'   => $targetId,
                'freight_id'  => $freightId,
                'target_type' => 'USER',
                'rating'      => $rating,
                'comment'     => $comment ? ContentFilterService::sanitize($comment) : '',
                'status'      => $status
            ]);

            $this->db->commit();
            
            // Atualizar reputação do usuário se publicado
            if ($status === 'published') {
                $this->repo->refreshReputation($targetId);
            }
            
            $message = $status === 'published' 
                ? "Avaliação publicada com sucesso!" 
                : "Avaliação enviada para análise e será publicada em breve.";

            return Response::json([
                "success" => true, 
                "message" => $message,
                "status" => $status
            ]);

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return Response::json(["success" => false, "message" => $e->getMessage()], 400);
        }
    }

    public function replyReview($data, $loggedUser = null) {
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Você precisa estar logado."], 401);
        }

        $reviewId = (int)($data['review_id'] ?? 0);
        $replyText = trim($data['reply_text'] ?? '');

        if (!$reviewId) {
            return Response::json(["success" => false, "message" => "ID da avaliação é obrigatório."], 400);
        }

        if (empty($replyText)) {
            return Response::json(["success" => false, "message" => "Texto da resposta é obrigatório."], 400);
        }

        if (strlen($replyText) > 1000) {
            return Response::json(["success" => false, "message" => "Resposta muito longa (máximo 1000 caracteres)."], 400);
        }

        try {
            $review = $this->repo->findById($reviewId);
            if (!$review) {
                return Response::json(["success" => false, "message" => "Avaliação não encontrada."], 404);
            }

            $isTarget = $review['target_id'] === $loggedUser['id'];
            $isAdmin = in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER', 'GERENTE']);

            if (!$isTarget && !$isAdmin) {
                return Response::json(["success" => false, "message" => "Você não pode responder esta avaliação."], 403);
            }

            $this->repo->saveReply($reviewId, $replyText);

            error_log("REVIEW REPLY: Review {$reviewId} replied by user {$loggedUser['id']}");

            return Response::json([
                "success" => true,
                "message" => "Resposta publicada com sucesso!"
            ]);
        } catch (\Exception $e) {
            error_log("Error replying review: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao responder avaliação."], 500);
        }
    }

    public function deleteReply($data, $loggedUser = null) {
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Você precisa estar logado."], 401);
        }

        $reviewId = (int)($data['review_id'] ?? 0);

        if (!$reviewId) {
            return Response::json(["success" => false, "message" => "ID da avaliação é obrigatório."], 400);
        }

        try {
            $review = $this->repo->findById($reviewId);
            if (!$review) {
                return Response::json(["success" => false, "message" => "Avaliação não encontrada."], 404);
            }

            $isTarget = $review['target_id'] === $loggedUser['id'];
            $isAdmin = in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER', 'GERENTE']);

            if (!$isTarget && !$isAdmin) {
                return Response::json(["success" => false, "message" => "Você não pode excluir esta resposta."], 403);
            }

            $this->repo->deleteReply($reviewId);

            return Response::json([
                "success" => true,
                "message" => "Resposta removida."
            ]);
        } catch (\Exception $e) {
            error_log("Error deleting reply: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao excluir resposta."], 500);
        }
    }

    public function list($data) {
        $targetId = (int)($data['target_id'] ?? 0);
        if (!$targetId) {
            return Response::json(["success" => false, "message" => "ID do usuário é obrigatório"], 400);
        }

        $limit = (int)($data['limit'] ?? 10);
        $offset = (int)($data['offset'] ?? 0);
        $months = isset($data['months']) ? (int)$data['months'] : null;

        try {
            $reviews = $this->repo->getByTarget($targetId, [
                'limit' => $limit,
                'offset' => $offset,
                'months' => $months,
                'status' => 'published'
            ]);

            $total = $this->repo->getCountByTarget($targetId, [
                'months' => $months,
                'status' => 'published'
            ]);

            $recentStats = $this->repo->getRecentStats($targetId, $months ?? 3);

            $distribution = $this->repo->getDistribution($targetId, $months);
            $totalDist = array_sum($distribution);
            $distributionPercent = [];
            foreach ($distribution as $star => $count) {
                $distributionPercent[$star] = $totalDist > 0 ? round(($count / $totalDist) * 100) : 0;
            }

            return Response::json([
                "success" => true,
                "data" => $reviews,
                "count" => $total,
                "stats" => [
                    "avg_rating" => round($recentStats['avg_rating'] ?? 0, 1),
                    "total" => (int)($recentStats['total'] ?? 0),
                    "months" => $months ?? 'all'
                ],
                "distribution" => $distributionPercent,
                "pagination" => [
                    "limit" => $limit,
                    "offset" => $offset,
                    "total" => $total
                ]
            ]);
        } catch (\Exception $e) {
            return Response::json(["success" => false, "message" => "Erro ao buscar avaliações."], 500);
        }
    }

    public function getStats($data) {
        $targetId = (int)($data['target_id'] ?? 0);
        if (!$targetId) {
            return Response::json(["success" => false, "message" => "ID do usuário é obrigatório"], 400);
        }

        try {
            $recentStats = $this->repo->getRecentStats($targetId, 3);
            $distribution = $this->repo->getDistribution($targetId, 3);
            $total = $this->repo->getCountByTarget($targetId, ['status' => 'published']);

            $totalDist = array_sum($distribution);
            $distributionPercent = [];
            foreach ($distribution as $star => $count) {
                $distributionPercent[$star] = $totalDist > 0 ? round(($count / $totalDist) * 100) : 0;
            }

            return Response::json([
                "success" => true,
                "data" => [
                    "avg_rating" => round($recentStats['avg_rating'] ?? 0, 1),
                    "total" => (int)($recentStats['total'] ?? 0),
                    "total_all" => $total,
                    "distribution" => $distributionPercent
                ]
            ]);
        } catch (\Exception $e) {
            return Response::json(["success" => false, "message" => "Erro ao buscar estatísticas."], 500);
        }
    }
}
