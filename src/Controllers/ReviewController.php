<?php
namespace App\Controllers;

use App\Core\Response;
use App\Repositories\ReviewRepository;
use App\Repositories\UserRepository;

class ReviewController {
    private $repo;
    private $userRepo;

    public function __construct($db) {
        $this->repo = new ReviewRepository($db);
        $this->userRepo = new UserRepository($db);
    }

    public function submit($data, $loggedUser) {
        if (!$loggedUser) return Response::json(["success" => false], 401);

        $freightId = $data['freight_id'] ?? 0;
        $targetId = $data['target_id'] ?? 0;
        $rating = (int)($data['rating'] ?? 0);

        // Validação básica
        if (!$freightId) return Response::json(["success" => false, "message" => "ID do frete é obrigatório."], 400);

        try {
            $this->db->beginTransaction(); // Use a conexão injetada ou do repo

            // 1. Checa se já avaliou ESTE frete
            if ($this->repo->hasAlreadyReviewed($loggedUser['id'], $targetId, $freightId)) {
                throw new \Exception("Você já avaliou este serviço.");
            }

            // 2. Salva a review
            $this->repo->create([
                'reviewer_id' => $loggedUser['id'],
                'target_id'   => $targetId,
                'freight_id'  => $freightId,
                'target_type' => 'USER',
                'rating'      => $rating,
                'comment'     => $data['comment'] ?? ''
            ]);

            // 3. Recalcula a média direto no banco
            $this->repo->refreshReputation($targetId);

            $this->db->commit();
            
            return Response::json(["success" => true, "message" => "Avaliação enviada!"]);

        } catch (\Exception $e) {
            $this->db->rollBack();
            return Response::json(["success" => false, "message" => $e->getMessage()], 400);
        }
    }

    public function list($data) {
        $targetId = $data['target_id'] ?? 0;
        if (!$targetId) return Response::json(["success" => false, "message" => "ID do alvo não fornecido"], 400);

        $reviews = $this->repo->getByTarget($targetId);

        return Response::json([
            "success" => true,
            "data" => $reviews,
            "count" => count($reviews)
        ]);
    }
}