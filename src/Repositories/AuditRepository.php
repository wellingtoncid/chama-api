<?php

namespace App\Repositories;

use PDO;
use Exception;

class AuditRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Registra uma ação no sistema
     * * @param int $userId ID do usuário que executou a ação
     * @param string $userName Nome do usuário (para histórico rápido sem join)
     * @param string $action Tipo da ação (ex: UPDATE_FREIGHT, DELETE_USER)
     * @param string $description Texto legível sobre a alteração
     * @param int|null $targetId ID do registro afetado
     * @param string|null $targetType Tabela ou Entidade afetada (ex: 'freights')
     * @param array|null $oldValues Dados antes da alteração
     * @param array|null $newValues Dados enviados para alteração
     */
    public function saveLog(
        $userId, 
        $userName, 
        $action, 
        $description, 
        $targetId = null, 
        $targetType = null, 
        $oldValues = null, 
        $newValues = null
    ) {
        try {
            $sql = "INSERT INTO logs_auditoria (
                        user_id, user_name, action_type, description, 
                        target_id, target_type, old_values, new_values, 
                        ip_address, user_agent
                    ) VALUES (
                        :u_id, :u_name, :action, :desc, 
                        :t_id, :t_type, :old, :new, 
                        :ip, :agent
                    )";

            $stmt = $this->db->prepare($sql);
            
            return $stmt->execute([
                ':u_id'   => $userId,
                ':u_name' => $userName,
                ':action' => $action,
                ':desc'   => $description,
                ':t_id'   => $targetId,
                ':t_type' => $targetType,
                ':old'    => $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
                ':new'    => $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
                ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ':agent'  => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
        } catch (Exception $e) {
            error_log("Erro Crítico de Auditoria: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Lista logs para o painel administrativo com filtros
     */
    public function listLogs($filters = [], $limit = 50) {
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = "user_id = :uid";
            $params[':uid'] = $filters['user_id'];
        }

        if (!empty($filters['target_type'])) {
            $where[] = "target_type = :ttype";
            $params[':ttype'] = $filters['target_type'];
        }

        $sql = "SELECT * FROM logs_auditoria 
                WHERE " . implode(" AND ", $where) . " 
                ORDER BY created_at DESC LIMIT " . (int)$limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countLogs($filters = []) {
      $where = ["1=1"];
      $params = [];

      if (!empty($filters['user_id'])) {
          $where[] = "user_id = :uid";
          $params[':uid'] = $filters['user_id'];
      }
      // ... adicione os outros filtros aqui ...

      $sql = "SELECT COUNT(*) FROM logs_auditoria WHERE " . implode(" AND ", $where);
      $stmt = $this->db->prepare($sql);
      $stmt->execute($params);
      return (int)$stmt->fetchColumn();
  }
}