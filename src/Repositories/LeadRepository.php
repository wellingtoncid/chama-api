<?php

namespace App\Repositories;

use PDO;

class LeadRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    // Retorna leads que NÃO foram deletados (Soft Delete)
    public function getLeads($filters = []) {
        $sql = "SELECT * FROM portal_requests WHERE deleted_at IS NULL";
        
        if (isset($filters['type']) && $filters['type'] !== 'all') {
            $sql .= " AND type = :type";
        }
        
        $sql .= " ORDER BY created_at DESC";
        $stmt = $this->db->prepare($sql);
        
        if (isset($filters['type']) && $filters['type'] !== 'all') {
            $stmt->bindValue(':type', $filters['type']);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Permite editar uma nota específica do histórico (opcional)
     */
    public function updateNote($noteId, $content) {
        $sql = "UPDATE portal_requests_notes SET content = ? WHERE id = ?";
        return $this->db->prepare($sql)->execute([$content, $noteId]);
    }

    /**
     * Atualiza status e notas administrativas
     */
    public function updateLead($id, $status, $adminNotes) {
        $sql = "UPDATE portal_requests 
                SET status = ?, admin_notes = ?, last_contact = NOW() 
                WHERE id = ?";
        return $this->db->prepare($sql)->execute([$status, $adminNotes, $id]);
    }

    /**
     * Implementação de Soft Delete
     */
    public function softDelete($id) {
        $sql = "UPDATE portal_requests SET deleted_at = NOW() WHERE id = ?";
        return $this->db->prepare($sql)->execute([$id]);
    }

    /**
     * Busca histórico de notas (se você criar a tabela portal_request_notes)
     */
    public function getLeadHistory($requestId) {
        $sql = "SELECT * FROM portal_requests_notes WHERE request_id = ? ORDER BY created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$requestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // SALVAR HISTÓRICO (Opcional, mas ideal)
    public function addHistoryNote($leadId, $userId, $note) {
        $sql = "INSERT INTO lead_history (lead_id, user_id, note) VALUES (?, ?, ?)";
        return $this->db->prepare($sql)->execute([$leadId, $userId, $note]);
    }

    /**
     * Busca todas as notas de um lead específico
     */
    public function getNotesByLead($requestId) {
        $sql = "SELECT * FROM portal_requests_notes 
                WHERE request_id = ? 
                ORDER BY created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$requestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Grava uma nova entrada no histórico imutável
     */
    public function addNote($requestId, $userId, $userName, $content) {
      try {
          $sql = "INSERT INTO portal_requests_notes (request_id, author_id, author_name, content, created_at) 
                  VALUES (?, ?, ?, ?, NOW())";
          $stmt = $this->db->prepare($sql);
          $result = $stmt->execute([
              (int)$requestId, 
              (int)$userId, 
              (string)$userName, 
              (string)$content
          ]);
          return $result;
      } catch (\PDOException $e) {
          // Isso vai fazer o erro aparecer no console do navegador (Aba Network -> Response)
          throw new \Exception("Erro no Banco: " . $e->getMessage());
      }
  }
}