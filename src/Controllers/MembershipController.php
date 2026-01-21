<?php

class MembershipController {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function handle($method, $endpoint, $data, $loggedUser) {
        if (!$loggedUser) return ["success" => false, "message" => "Não autorizado"];

        switch ($endpoint) {
            case 'my-services': // Lista tudo que o usuário pagou e está ativo
                return $this->getMyActiveServices($loggedUser['id']);
            
            case 'payment-history': // Histórico de faturas/transações
                return $this->getPaymentHistory($loggedUser['id']);
            
            case 'check-expirations': // Opcional: para o front checar alertas
                return $this->checkSoonToExpire($loggedUser['id']);

            default:
                return ["error" => "Endpoint de assinatura inválido"];
        }
    }

    private function getMyActiveServices($userId) {
        try {
            // 1. Busca Anúncios (Banners)
            $stmtAds = $this->db->prepare("
                SELECT id, title, position, expires_at, is_active, 
                DATEDIFF(expires_at, NOW()) as days_left
                FROM ads 
                WHERE user_id = ? AND is_deleted = 0 AND expires_at > NOW()
            ");
            $stmtAds->execute([$userId]);
            $ads = $stmtAds->fetchAll(PDO::FETCH_ASSOC);

            // 2. Busca Fretes Destacados (Featured ou Urgent)
            $stmtFreights = $this->db->prepare("
                SELECT id, product, origin, destination, featured_until, urgent_until,
                DATEDIFF(GREATEST(IFNULL(featured_until, NOW()), IFNULL(urgent_until, NOW())), NOW()) as days_left
                FROM freights 
                WHERE user_id = ? AND (featured_until > NOW() OR urgent_until > NOW())
            ");
            $stmtFreights->execute([$userId]);
            $freights = $stmtFreights->fetchAll(PDO::FETCH_ASSOC);

            // 3. Status de Verificação Pro
            $stmtUser = $this->db->prepare("SELECT is_verified, verified_until FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $accountStatus = $stmtUser->fetch(PDO::FETCH_ASSOC);

            // 4. Histórico de Transações
            $stmtTrans = $this->db->prepare("
                SELECT t.*, p.name as plan_name 
                FROM transactions t 
                JOIN plans p ON t.plan_id = p.id 
                WHERE t.user_id = ? 
                ORDER BY t.created_at DESC
            ");
            $stmtTrans->execute([$userId]);
            $transactions = $stmtTrans->fetchAll(PDO::FETCH_ASSOC);

            return [
                "success" => true,
                "ads" => $ads,
                "featured_freights" => $freights,
                "account_status" => $accountStatus,
                "transactions" => $transactions
            ];

        } catch (Exception $e) {
            return ["success" => false, "error" => $e->getMessage()];
        }
    }

    private function getPaymentHistory($userId) {
      $stmt = $this->db->prepare("
          SELECT 
              t.id as invoice_id, 
              t.amount, 
              t.status, 
              t.created_at as date,
              p.name as plan_name,
              t.freight_id
          FROM transactions t
          LEFT JOIN plans p ON t.plan_id = p.id
          WHERE t.user_id = ?
          ORDER BY t.created_at DESC
      ");
      $stmt->execute([$userId]);
      return ["success" => true, "transactions" => $stmt->fetchAll(PDO::FETCH_ASSOC)];
  }
}