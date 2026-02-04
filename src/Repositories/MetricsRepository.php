<?php
namespace App\Repositories;

class MetricsRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function saveLog($targetId, $targetType, $eventType, $userId) {
        $sql = "INSERT INTO click_logs (
                    user_id, target_id, target_type, event_type, 
                    ip_address, user_agent, created_at
                ) VALUES (
                    :u_id, :t_id, :t_type, :e_type, 
                    :ip, :ua, NOW()
                )";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':u_id'   => $userId,
            ':t_id'   => $targetId,
            ':t_type' => $targetType,
            ':e_type' => $eventType,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'     => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        ]);
    }

    /**
     * Busca estatísticas agregadas. 
     * Se $userId for null, traz o global (para o Admin).
     */
    public function getUserEntityStats($userId = null, $type = 'FREIGHT') {
        $table = match($type) {
            'FREIGHT'  => 'freights',
            'AD'       => 'ads',
            'GROUP'    => 'whatsapp_groups',
            'LISTING'  => 'listings',
            default    => 'freights'
        };

        $hasUserIdColumn = ($type !== 'GROUP');

        // Construção dinâmica do WHERE
        $where = ($userId && $hasUserIdColumn) ? "WHERE user_id = :user_id" : "";
        $params = ($userId && $hasUserIdColumn) ? [':user_id' => $userId] : [];

        $sql = "SELECT 
                COUNT(*) as total_items,
                SUM(COALESCE(views_count, 0)) as views,
                SUM(COALESCE(clicks_count, 0)) as clicks
            FROM {$table} 
            {$where}";
                
        try {
          $stmt = $this->db->prepare($sql);
          $stmt->execute($params);
          return $stmt->fetch(\PDO::FETCH_ASSOC);
      } catch (\PDOException $e) {
          // Se der erro de coluna, retorna zerado para não quebrar o Front
          return ['total_items' => 0, 'views' => 0, 'clicks' => 0];
      }
    }

    /**
     * Busca logs de eventos recentes (Histórico para o Admin ou Empresa)
     */
    public function getRecentActivity($userId = null, $limit = 10) {
        $where = $userId ? "WHERE l.user_id = :user_id" : "";
        $sql = "SELECT l.*, 
                CASE 
                    WHEN l.target_type = 'FREIGHT' THEN f.product 
                    WHEN l.target_type = 'AD' THEN a.title 
                END as target_name
                FROM click_logs l
                LEFT JOIN freights f ON l.target_id = f.id AND l.target_type = 'FREIGHT'
                LEFT JOIN ads a ON l.target_id = a.id AND l.target_type = 'AD'
                {$where}
                ORDER BY l.created_at DESC 
                LIMIT :limit";

        $stmt = $this->db->prepare($sql);
        if ($userId) $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':limit', (int)$limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getLogCountByPeriod($interval = '24 HOUR') {
      $sql = "SELECT COUNT(*) as total FROM click_logs WHERE created_at >= NOW() - INTERVAL {$interval}";
      $stmt = $this->db->query($sql);
      $res = $stmt->fetch(\PDO::FETCH_ASSOC);
      return (int)$res['total'];
  }
}