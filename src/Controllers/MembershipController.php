<?php
namespace App\Controllers;

use App\Core\Response;
use App\Services\MembershipService;

class MembershipController {
    private $service;

    public function __construct($db) {
        $this->service = new MembershipService($db);
    }

    public function getMyActiveServices($data, $loggedUser) {
        if (!$loggedUser) return Response::json(["success" => false], 401);
        
        $dashboard = $this->service->getUserDashboardData($loggedUser['id']);
        return Response::json([
            "success" => true,
            "ads" => $dashboard['ads'],
            "subscription" => $dashboard['subscription']
        ]);
    }

    public function getPaymentHistory($data, $loggedUser) {
        if (!$loggedUser) return Response::json(["success" => false], 401);

        $history = $this->service->getHistory($loggedUser['id']);
        return Response::json(["success" => true, "data" => $history]);
    }
}