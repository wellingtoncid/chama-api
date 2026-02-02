<?php
namespace App\Services;

use App\Repositories\MembershipRepository;

class MembershipService {
    private $repo;

    public function __construct($db) {
        $this->repo = new MembershipRepository($db);
    }

    public function getUserDashboardData(int $userId) {
        return [
            "ads" => $this->repo->getActiveAds($userId),
            "subscription" => $this->repo->getUserSubscription($userId)
        ];
    }

    /**
     * Exemplo de lógica de negócio: O usuário pode ver fretes exclusivos?
     */
    public function hasPremiumAccess(int $userId): bool {
        $sub = $this->repo->getUserSubscription($userId);
        return ($sub && $sub['is_subscriber'] == 1);
    }

    public function getHistory(int $userId) {
        return $this->repo->getTransactions($userId);
    }
}