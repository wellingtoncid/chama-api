<?php

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\LeadRepository;
use App\Repositories\AdminRepository;

class LeadController {
    private $leadRepo;
    private $adminRepo; // Para salvar logs de auditoria

    public function __construct($db) {
        $this->leadRepo = new LeadRepository($db);
        $this->adminRepo = new AdminRepository($db);
    }

    public function createRequest($data) {
        // 1. Validar dados básicos
        if (empty($data['contact_info']) || empty($data['title'])) {
            return Response::json(["success" => false, "message" => "Dados incompletos"], 400);
        } 
        
        // Define uma prioridade baseada no tipo
        $priority = ($data['type'] === 'business_ad') ? 2 : 1;

        if ($this->adminRepo->savePortalRequest($data, $priority)) {
            return Response::json(["success" => true, "message" => "Solicitação enviada!"]);
        }

        return Response::json(["success" => false, "message" => "Erro interno ao salvar"], 500);
    }

    /**
     * Lista os leads para o painel
     */
    public function listLeads($data, $loggedUser) {
        // Apenas gerentes ou admins podem ver
        if (!$loggedUser || !in_array($loggedUser['role'], ['ADMIN', 'MANAGER'])) {
            return Response::json(["success" => false, "message" => "Não autorizado"], 403);
        }

        $leads = $this->leadRepo->getLeads($data);
        return Response::json(["success" => true, "data" => $leads]);
    }

    public function getHistory($data, $loggedUser) {
      if (!$loggedUser) return Response::json(["success" => false], 401);
      $id = $data['id'] ?? null;
      $notes = $this->leadRepo->getNotesByLead($id);
      return Response::json(["success" => true, "data" => $notes]);
  }

    /**
     * Processa atualizações ou exclusões
     */
    public function handleAction($data, $loggedUser) {
      if (!$loggedUser) return Response::json(["success" => false], 401);

      $id = $data['id'] ?? null;
      if (!$id) return Response::json(["success" => false, "message" => "ID ausente"]);

      $authorId = $loggedUser['id'] ?? 0;
      $authorName = $loggedUser['name'] ?? 'Sistema/Admin';

      // 1. Busca o lead atual para saber o status antigo antes de mudar
      $currentLead = null;
      $leads = $this->leadRepo->getLeads(); 
      foreach($leads as $l) { if($l['id'] == $id) { $currentLead = $l; break; } }

      // 2. Caso seja uma ação de DELEÇÃO
      if (isset($data['action']) && $data['action'] === 'delete') {
          if ($this->leadRepo->softDelete($id)) {
              $this->adminRepo->saveLog($authorId, $authorName, 'DELETE_LEAD', "Arquivou lead #$id", $id, 'LEAD');
              return Response::json(["success" => true, "message" => "Lead arquivado"]);
          }
      }

      // 3. Dados vindos do Front
      $status = $data['status'] ?? 'pending';
      $notes = $data['admin_notes'] ?? '';
      $newNote = $data['new_note'] ?? '';

      if ($this->leadRepo->updateLead($id, $status, $notes)) {
          
          $logDesc = "Atualizou dados do lead #$id"; // Descrição padrão

          // --- LÓGICA DE NOTAS NA TIMELINE ---
          if (!empty($newNote)) {
              // Se o front enviou nota (manual ou clique no WhatsApp)
              $this->leadRepo->addNote($id, $authorId, $authorName, $newNote);
              $logDesc = "Adicionou nota ao lead #$id: " . mb_substr($newNote, 0, 50) . "...";
          } 
          else if ($currentLead && $currentLead['status'] !== $status) {
              // Se não tem nota manual, mas o STATUS mudou, gera nota automática
              $statusLabels = [
                  'pending' => 'Pendente', 
                  'in_negotiation' => 'Em Negociação', 
                  'analyzed' => 'Finalizado'
              ];
              $oldStatus = $statusLabels[$currentLead['status']] ?? $currentLead['status'];
              $newStatusLabel = $statusLabels[$status] ?? $status;
              
              $msg = "Alterou o status de [{$oldStatus}] para [{$newStatusLabel}]";
              $this->leadRepo->addNote($id, $authorId, $authorName, $msg);
              $logDesc = "Alterou status do lead #$id para $status";
          }

          // 4. Log de auditoria (Na tabela logs_auditoria)
          $this->adminRepo->saveLog($authorId, $authorName, 'UPDATE_LEAD', $logDesc, $id, 'LEAD');

          return Response::json(["success" => true, "message" => "Lead atualizado!"]);
      }
      
      return Response::json(["success" => false, "message" => "Erro ao atualizar"]);
  }
}