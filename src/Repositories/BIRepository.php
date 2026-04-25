<?php

namespace App\Repositories;

use PDO;

class BIRepository {
    private $db;
    private $periodStart;
    private $periodEnd;
    private $previousPeriodStart;
    private $previousPeriodEnd;

    public function __construct($db, $period = 'this_month') {
        $this->db = $db;
        $this->calculatePeriods($period);
    }

private function calculatePeriods($period) {
        $now = new \DateTime();
        
        switch ($period) {
            case 'this_month':
                $this->periodStart = new \DateTime(date('Y-m-01'));
                $this->periodEnd = clone $now;
                $this->previousPeriodStart = (new \DateTime(date('Y-m-01')))->modify('-1 month');
                $this->previousPeriodEnd = (new \DateTime(date('Y-m-01')))->modify('-1 day');
                break;
            case 'last_month':
                $this->periodStart = (new \DateTime(date('Y-m-01')))->modify('-1 month');
                $this->periodEnd = (new \DateTime(date('Y-m-01')))->modify('-1 day');
                $this->previousPeriodStart = (new \DateTime(date('Y-m-01')))->modify('-2 months');
                $this->previousPeriodEnd = (new \DateTime(date('Y-m-01')))->modify('-1 month')->modify('-1 day');
                break;
            case 'last_3_months':
                $this->periodStart = (new \DateTime(date('Y-m-01')))->modify('-2 months');
                $this->periodEnd = clone $now;
                $this->previousPeriodStart = (new \DateTime(date('Y-m-01')))->modify('-5 months');
                $this->previousPeriodEnd = (new \DateTime(date('Y-m-01')))->modify('-3 months')->modify('-1 day');
                break;
            case 'this_year':
                $this->periodStart = new \DateTime(date('Y-01-01'));
                $this->periodEnd = clone $now;
                $this->previousPeriodStart = (new \DateTime())->modify('-1 year');
                $previousEnd = (new \DateTime())->modify('-1 year');
                $this->previousPeriodEnd = $previousEnd->modify('12 months')->modify('-1 day');
                break;
            case 'all_time':
                $this->periodStart = new \DateTime('2020-01-01');
                $this->periodEnd = clone $now;
                $this->previousPeriodStart = new \DateTime('2019-01-01');
                $this->previousPeriodEnd = new \DateTime('2019-12-31');
                break;
            default:
                $this->periodStart = new \DateTime(date('Y-m-01'));
                $this->periodEnd = clone $now;
                $this->previousPeriodStart = (new \DateTime(date('Y-m-01')))->modify('-1 month');
                $this->previousPeriodEnd = (new \DateTime(date('Y-m-01')))->modify('-1 day');
}
    }

    private function formatDate($date) {
        return $date->format('Y-m-d');
    }

    private function calculateGrowth($current, $previous) {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function safeQuery($sql, $params = []) {
        try {
            if (!empty($params)) {
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
            } else {
                $stmt = $this->db->query($sql);
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("BI Query Error: " . $e->getMessage() . " - SQL: " . $sql);
            return [];
        }
    }

    private function safeCount($table, $where = '') {
        $sql = "SELECT COUNT(*) as total FROM {$table}" . ($where ? " WHERE {$where}" : "");
        $result = $this->safeQuery($sql);
        return (int)($result[0]['total'] ?? 0);
    }

    public function getFreights() {
        try {
            $periodStart = $this->formatDate($this->periodStart);
            $periodEnd = $this->formatDate($this->periodEnd);
            $prevStart = $this->formatDate($this->previousPeriodStart);
            $prevEnd = $this->formatDate($this->previousPeriodEnd);

            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM freights WHERE created_at BETWEEN :start AND :end");
            $stmt->execute([':start' => $periodStart, ':end' => $periodEnd]);
            $current = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM freights WHERE created_at BETWEEN :start AND :end");
            $stmt->execute([':start' => $prevStart, ':end' => $prevEnd]);
            $previous = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $stmt = $this->db->query("SELECT COUNT(*) as total FROM freights");
            $allTime = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $stmt = $this->db->query("SELECT status, COUNT(*) as total FROM freights GROUP BY status");
            $byStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'current' => $current,
                'previous' => $previous,
                'growth' => $this->calculateGrowth($current, $previous),
                'all_time' => $allTime,
                'by_status' => $byStatus,
                'by_day' => []
            ];
        } catch (\Throwable $e) {
            error_log("BI getFreights Error: " . $e->getMessage());
            return ['current' => 0, 'previous' => 0, 'growth' => 0, 'all_time' => 0, 'by_status' => [], 'by_day' => []];
        }
    }

    public function getUsers() {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) as total FROM users");
            $allTime = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $stmt = $this->db->query("SELECT role, COUNT(*) as total FROM users GROUP BY role");
            $byRole = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $this->db->query("SELECT SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active, SUM(CASE WHEN status != 'active' THEN 1 ELSE 0 END) as inactive FROM users");
            $status = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'current' => 0,
                'previous' => 0,
                'growth' => 0,
                'all_time' => $allTime,
                'by_role' => $byRole,
                'active' => (int)($status['active'] ?? 0),
                'inactive' => (int)($status['inactive'] ?? 0)
            ];
        } catch (\Throwable $e) {
            error_log("BI getUsers Error: " . $e->getMessage());
            return ['current' => 0, 'previous' => 0, 'growth' => 0, 'all_time' => 0, 'by_role' => [], 'active' => 0, 'inactive' => 0];
        }
    }

    public function getDrivers() {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) as total FROM users WHERE role = 'driver'");
            $total = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $stmt = $this->db->query("SELECT COUNT(*) as verified FROM users WHERE role = 'driver' AND status = 'active'");
            $verified = (int)($stmt->fetch(PDO::FETCH_ASSOC)['verified'] ?? 0);

            return ['current' => 0, 'total' => $total, 'verified' => $verified, 'pending' => $total - $verified];
        } catch (\Throwable $e) {
            error_log("BI getDrivers Error: " . $e->getMessage());
            return ['current' => 0, 'total' => 0, 'verified' => 0, 'pending' => 0];
        }
    }

    public function getCompanies() {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) as total FROM users WHERE role = 'company'");
            $total = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $stmt = $this->db->query("SELECT COUNT(*) as verified FROM users WHERE role = 'company' AND status = 'active'");
            $verified = (int)($stmt->fetch(PDO::FETCH_ASSOC)['verified'] ?? 0);

            return ['current' => 0, 'total' => $total, 'verified' => $verified, 'pending' => $total - $verified];
        } catch (\Throwable $e) {
            error_log("BI getCompanies Error: " . $e->getMessage());
            return ['current' => 0, 'total' => 0, 'verified' => 0, 'pending' => 0];
        }
    }

    public function getFinance() {
        try {
            $stmt = $this->db->query("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE type = 'credit' AND status = 'completed'");
            $allTime = (float)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $stmt = $this->db->query("SELECT COUNT(*) as total FROM transactions");
            $transactions = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            return [
                'current' => 0,
                'previous' => 0,
                'growth' => 0,
                'all_time' => $allTime,
                'transactions' => $transactions,
                'avg_ticket' => $transactions > 0 ? round($allTime / $transactions, 2) : 0
            ];
        } catch (\Throwable $e) {
            error_log("BI getFinance Error: " . $e->getMessage());
            return ['current' => 0, 'previous' => 0, 'growth' => 0, 'all_time' => 0, 'transactions' => 0, 'avg_ticket' => 0];
        }
    }

    public function getPlans() {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) as active FROM transactions WHERE status = 'active' AND type = 'subscription'");
            $active = (int)($stmt->fetch(PDO::FETCH_ASSOC)['active'] ?? 0);

            return ['current' => $active, 'total_active' => $active, 'revenue' => 0];
        } catch (\Throwable $e) {
            error_log("BI getPlans Error: " . $e->getMessage());
            return ['current' => 0, 'total_active' => 0, 'revenue' => 0];
        }
    }

    public function getAds() {
        return ['revenue' => 0, 'impressions' => 0, 'clicks' => 0, 'ctr' => 0];
    }

    public function getQuotes() {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) as total FROM quotes");
            $allTime = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $stmt = $this->db->query("SELECT status, COUNT(*) as total FROM quotes GROUP BY status");
            $byStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return ['total' => 0, 'previous' => 0, 'growth' => 0, 'all_time' => $allTime, 'by_status' => $byStatus];
        } catch (\Throwable $e) {
            error_log("BI getQuotes Error: " . $e->getMessage());
            return ['total' => 0, 'previous' => 0, 'growth' => 0, 'all_time' => 0, 'by_status' => []];
        }
    }

    public function getTickets() {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) as open FROM support_tickets WHERE status = 'open'");
            $totalOpen = (int)($stmt->fetch(PDO::FETCH_ASSOC)['open'] ?? 0);

            $stmt = $this->db->query("SELECT COUNT(*) as closed FROM support_tickets WHERE status = 'closed'");
            $closed = (int)($stmt->fetch(PDO::FETCH_ASSOC)['closed'] ?? 0);

            $stmt = $this->db->query("SELECT COUNT(*) as total FROM support_tickets");
            $total = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            return ['open' => $totalOpen, 'closed' => $closed, 'total' => $total, 'new_this_period' => 0];
        } catch (\Throwable $e) {
            error_log("BI getTickets Error: " . $e->getMessage());
            return ['open' => 0, 'closed' => 0, 'total' => 0, 'new_this_period' => 0];
        }
    }

    public function getGroups() {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) as total FROM whatsapp_groups WHERE status = 'active'");
            $total = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $stmt = $this->db->query("SELECT COALESCE(SUM(member_count), 0) as members FROM whatsapp_groups WHERE status = 'active'");
            $members = (int)($stmt->fetch(PDO::FETCH_ASSOC)['members'] ?? 0);

            return ['total' => $total, 'members' => $members];
        } catch (\Throwable $e) {
            error_log("BI getGroups Error: " . $e->getMessage());
            return ['total' => 0, 'members' => 0];
        }
    }

    public function getMarketplace() {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) as active FROM listings WHERE status = 'active'");
            $active = (int)($stmt->fetch(PDO::FETCH_ASSOC)['active'] ?? 0);

            $stmt = $this->db->query("SELECT COUNT(*) as total FROM listings");
            $total = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            return ['active' => $active, 'new_this_period' => 0, 'total' => $total];
        } catch (\Throwable $e) {
            error_log("BI getMarketplace Error: " . $e->getMessage());
            return ['active' => 0, 'new_this_period' => 0, 'total' => 0];
        }
    }

public function getSummary() {
        try {
            return [
                'period' => [
                    'start' => $this->formatDate($this->periodStart),
                    'end' => $this->formatDate($this->periodEnd)
                ],
                'freights' => $this->getFreights(),
                'users' => $this->getUsers(),
                'companies' => $this->getCompanies(),
                'drivers' => $this->getDrivers(),
                'finance' => $this->getFinance(),
                'quotes' => $this->getQuotes(),
                'tickets' => $this->getTickets(),
                'groups' => $this->getGroups(),
                'marketplace' => $this->getMarketplace(),
                'ads' => $this->getAds(),
                'plans' => $this->getPlans()
            ];
        } catch (\Throwable $e) {
            error_log("BI getSummary Error: " . $e->getMessage());
            return ['error' => $e->getMessage(), 'period' => ['start' => '', 'end' => '']];
        }
    }
}