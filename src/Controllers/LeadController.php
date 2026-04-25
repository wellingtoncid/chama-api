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
        $role = strtolower($loggedUser['role'] ?? '');
        if (!$loggedUser || !in_array($role, ['admin', 'manager'])) {
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

      // 3. Dados vindos do Front (suporta novos campos CRM)
      $updateData = [
        'status' => $data['status'] ?? 'pending',
        'admin_notes' => $data['admin_notes'] ?? '',
      ];
      
      // Novos campos CRM
      if (isset($data['pipeline_stage'])) $updateData['pipeline_stage'] = $data['pipeline_stage'];
      if (isset($data['deal_value'])) $updateData['deal_value'] = $data['deal_value'];
      if (isset($data['score'])) $updateData['score'] = $data['score'];
      if (isset($data['assigned_to'])) $updateData['assigned_to'] = $data['assigned_to'];
      if (isset($data['priority'])) $updateData['priority'] = $data['priority'];

      if ($this->leadRepo->updateLead($id, $updateData)) {
          
          $logDesc = "Atualizou dados do lead #$id"; // Descrição padrão

          // --- LÓGICA DE NOTAS NA TIMELINE ---
          $newNote = $data['new_note'] ?? '';
          if (!empty($newNote)) {
              // Se o front enviou nota (manual ou clique no WhatsApp)
              $this->leadRepo->addNote($id, $authorId, $authorName, $newNote);
              $logDesc = "Adicionou nota ao lead #$id: " . mb_substr($newNote, 0, 50) . "...";
          } 
          else if ($currentLead && isset($data['status']) && $currentLead['status'] !== $data['status']) {
              // Se não tem nota manual, mas o STATUS mudou, gera nota automática
              $statusLabels = [
                  'pending' => 'Pendente', 
                  'in_progress' => 'Em Andamento',
                  'completed' => 'Concluído'
              ];
              $oldStatus = $statusLabels[$currentLead['status']] ?? $currentLead['status'];
              $newStatusLabel = $statusLabels[$data['status']] ?? $data['status'];
              
              $msg = "Alterou status de [{$oldStatus}] para [{$newStatusLabel}]";
              $this->leadRepo->addNote($id, $authorId, $authorName, $msg);
              $logDesc = "Alterou status do lead #$id para " . ($data['status']);
          }

          // 4. Log de auditoria (Na tabela logs_auditoria)
          $this->adminRepo->saveLog($authorId, $authorName, 'UPDATE_LEAD', $logDesc, $id, 'LEAD');

          return Response::json(["success" => true, "message" => "Lead atualizado!"]);
      }
      
      return Response::json(["success" => false, "message" => "Erro ao atualizar"]);
  }
}