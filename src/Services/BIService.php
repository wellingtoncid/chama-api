<?php

namespace App\Services;

use App\Repositories\BIRepository;

class BIService {
    private $biRepo;

    public function __construct($db, $period = 'this_month') {
        $this->biRepo = new BIRepository($db, $period);
    }

    public function getFreights($type = 'summary') {
        switch ($type) {
            case 'summary':
                return $this->biRepo->getFreights();
            case 'by_origin':
                return $this->biRepo->getFreightsByOrigin();
            case 'by_category':
                return $this->biRepo->getFreightsByCategory();
            default:
                return $this->biRepo->getFreights();
        }
    }

    public function getUsers($type = 'summary') {
        switch ($type) {
            case 'summary':
            default:
                return $this->biRepo->getUsers();
        }
    }

    public function getDrivers($type = 'summary') {
        return $this->biRepo->getDrivers();
    }

    public function getCompanies($type = 'summary') {
        return $this->biRepo->getCompanies();
    }

    public function getFinance($type = 'summary') {
        return $this->biRepo->getFinance();
    }

    public function getQuotes($type = 'summary') {
        return $this->biRepo->getQuotes();
    }

    public function getTickets($type = 'summary') {
        return $this->biRepo->getTickets();
    }

    public function getGroups($type = 'summary') {
        return $this->biRepo->getGroups();
    }

    public function getMarketplace($type = 'summary') {
        return $this->biRepo->getMarketplace();
    }

    public function getAds($type = 'summary') {
        return $this->biRepo->getAds();
    }

    public function getPlans($type = 'summary') {
        return $this->biRepo->getPlans();
    }

    public function getSummary() {
        return $this->biRepo->getSummary();
    }

    public function getModule($module, $type = 'summary') {
        $method = 'get' . ucfirst($module);
        if (method_exists($this, $method)) {
            return $this->$method($type);
        }
        return null;
    }
}