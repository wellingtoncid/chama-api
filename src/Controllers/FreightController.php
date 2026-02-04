<?php
namespace App\Controllers;

use App\Core\Response;
use App\Repositories\FreightRepository;
use App\Services\NotificationService;
use Exception;

class FreightController {
    private $db;
    private $userRepo;
    private $repo;
    private $notificationService;
    private $chatRepo;

    public function __construct($freightRepo, $notificationService, $chatRepo = null, $userRepo = null, $db = null) { 
        $this->repo = $freightRepo;
        $this->notificationService = $notificationService;
        $this->chatRepo = $chatRepo;
        $this->userRepo = $userRepo; 
        $this->db = $db; 
    }

   public function listAll($data, $loggedUser) {
        // 1. Limpeza de inputs
        $search = $data['search'] ?? $_GET['search'] ?? '';
        $page = isset($data['page']) ? (int)$data['page'] : (isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $perPage = isset($data['perPage']) ? (int)$data['perPage'] : 15;
        if ($page < 1) $page = 1;

        try {
            $results = $this->repo->listPaginated(
                null, // Importante: NULL garante que a vitrine seja pública
                [
                    'search' => $search,
                    'viewer_id' => $loggedUser['id'] ?? null // Usado apenas para ver se o viewer favoritou
                ], 
                $page,
                $perPage
            );

            return Response::json($results);

        } catch (Exception $e) {
            error_log("❌ ERRO NO CONTROLLER listAll: " . $e->getMessage());
            return Response::json([
                "success" => false, 
                "message" => "Erro ao processar listagem de fretes."
            ], 500);
        }
    }

    public function create($data, $user) {
        $lastFreight = $this->repo->getLastFreightTime((int)$user['id']);
            if ($lastFreight && (time() - strtotime($lastFreight)) < 60) {
                return Response::json(["success" => false, "message" => "Aguarde um minuto para postar novamente."], 429);
            }
        // 1. Validação de Acesso
        $role = strtoupper($user['role'] ?? '');
        if ($role !== 'COMPANY' && $role !== 'ADMIN') {
            return Response::json(["success" => false, "message" => "Acesso negado."], 403);
        }

        // 2. Validação de Dados (Adicionei o Estado aqui também)
        if (empty($data['origin_city']) || empty($data['dest_city']) || empty($data['product'])) {
            return Response::json(["success" => false, "message" => "Dados obrigatórios faltando."], 400);
        }

        // --- NOVA VALIDAÇÃO DE DOCUMENTOS ---
        $profile = $this->userRepo->getProfile($user['id']); 
        if ($role !== 'ADMIN' && ($profile['document_status'] ?? '') !== 'approved') {
            return Response::json([
                "success" => false, 
                "message" => "Seus documentos ainda não foram aprovados. Você não pode publicar fretes."
            ], 403);
        }

        try {
            // 3. Preparação dos Dados
            $status = ($role === 'ADMIN' || ($user['is_verified'] ?? 0) == 1) ? 'OPEN' : 'PENDING';
            $isFeatured = !empty($data['is_featured']) && $data['is_featured'] == true;
            $days = $isFeatured ? 30 : 7;
            
            // Limpeza de WhatsApp
            $whatsapp = preg_replace('/\D/', '', !empty($data['whatsapp']) ? $data['whatsapp'] : ($user['whatsapp'] ?? ''));

            // Geração do Slug ANTES do insert (Evita o UPDATE extra)
            // Usamos um hash curto aleatório para garantir unicidade sem precisar do ID do banco
            $slugBase = trim($data['product']) . " de " . trim($data['origin_city']) . " para " . trim($data['dest_city']);
            $uniqueSuffix = bin2hex(random_bytes(3)); // Gera 6 caracteres aleatórios (ex: a1b2c3)
            $finalSlug = $this->generateSlug($slugBase, $uniqueSuffix);

            $payload = [
                'user_id'      => (int)$user['id'],
                'origin_city'  => trim($data['origin_city']),
                'origin_state' => strtoupper(trim($data['origin_state'] ?? '')),
                'dest_city'    => trim($data['dest_city']),
                'dest_state'   => strtoupper(trim($data['dest_state'] ?? '')),
                'product'      => trim($data['product']),
                'weight'       => max(0.0, (float)($data['weight'] ?? 0)),
                'price'        => max(0.0, (float)($data['price'] ?? 0)), // Garante preço positivo
                'vehicle_type' => $data['vehicle_type'] ?? 'Qualquer',
                'body_type'    => $data['body_type'] ?? 'Qualquer',
                'description'  => strip_tags($data['description'] ?? ''),
                'status'       => $status,
                'slug'         => $finalSlug, // Slug já vai no INSERT
                'expires_at'   => date('Y-m-d H:i:s', strtotime("+$days days")),
                'is_featured'  => $isFeatured ? 1 : 0,
                'whatsapp'     => $whatsapp
            ];

            $contentToVerify = ($data['product'] ?? '') . ' ' . ($data['description'] ?? '');

            if (!$this->isContentClean($contentToVerify)) {
                //$status = 'PENDING';
                return Response::json([
                    "success" => false, 
                    "message" => "O conteúdo contém termos não permitidos ou excesso de links."
                ], 400);
            }

            // 4. Persistência Única
            $this->db->beginTransaction();
            $id = $this->repo->save($payload);
            $this->db->commit();

            return Response::json([
                "success" => true, 
                "id"      => (int)$id, 
                "status"  => $status,
                "slug"    => $finalSlug,
                "message" => $status === 'PENDING' ? "Em análise." : "Publicado!"
            ]);

        } catch (Exception $e) {
            if ($this->db && $this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro Create Freight: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro interno."], 500);
        }
    }

    /**
     * Registra interesse: Quando o motorista clica em "Ver Telefone"
     */
    public function logEvent($data, $user) {
        // 1. Captura e higienização
        $targetId  = (int)($data['id'] ?? $data['target_id'] ?? 0);
        $targetType = strtoupper($data['target_type'] ?? 'FREIGHT'); // Ex: FREIGHT, COMPANY_PROFILE
        $eventType  = strtoupper($data['event_type'] ?? 'VIEW');    // Ex: VIEW, WHATSAPP_CLICK, SHARE

        if ($targetId <= 0) {
            return Response::json(["success" => false, "message" => "ID de alvo inválido"], 400);
        }

        // 2. Coleta de dados de contexto para auditoria e evitar fraudes
        $meta = [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        ];

        try {
            // 3. Registro da Métrica
            // Passamos o ID do usuário (se logado) e os metadados
            $success = $this->repo->logMetric(
                $targetId, 
                $targetType, 
                $user['id'] ?? null, 
                $eventType,
                $meta
            );

            // Retornamos 200 sempre para o front-end não travar, 
            // mesmo que o log falhe silenciosamente no banco.
            return Response::json(["success" => true]);

        } catch (Exception $e) {
            // Logs de métricas não devem derrubar a experiência do usuário
            error_log("Erro ao registrar métrica ({$eventType}): " . $e->getMessage());
            return Response::json(["success" => false], 500);
        }
    }

    /**
     * Dashboard: Lista motoristas que clicaram nos meus fretes
     */
    public function getLeads($data, $user) {
        // 1. Bloqueio de Segurança
        if (!$user || strtoupper($user['role'] ?? '') !== 'COMPANY') {
            return Response::json([
                "success" => false, 
                "message" => "Acesso restrito a empresas contratantes."
            ], 403);
        }

        // 2. Filtro Opcional por Frete Específico
        // Se vier um freight_id, filtramos apenas os leads daquela carga
        $freightId = isset($data['freight_id']) ? (int)$data['freight_id'] : null;

        try {
            // 3. Busca de Dados
            // O Repository deve garantir que a empresa só veja leads de fretes que ELA criou
            $leads = $this->repo->getInterestedDrivers((int)$user['id'], $freightId);

            // 4. Resposta Limpa
            return Response::json([
                "success" => true,
                "total"   => count($leads),
                "data"    => $leads
            ]);

        } catch (Exception $e) {
            error_log("Erro ao buscar leads da empresa {$user['id']}: " . $e->getMessage());
            return Response::json([
                "success" => false, 
                "message" => "Erro ao carregar lista de interessados."
            ], 500);
        }
    }

    public function toggleFavorite($data, $user) {
        // 1. Verificação de Autenticação
        if (!$user) {
            return Response::json([
                "success" => false, 
                "message" => "Você precisa estar logado para favoritar."
            ], 401);
        }

        // 2. Validação do ID do Frete
        $freightId = (int)($data['id'] ?? 0);
        if ($freightId <= 0) {
            return Response::json([
                "success" => false, 
                "message" => "ID do frete inválido."
            ], 400);
        }

        try {
            // 3. Execução no Repository
            // O toggleFavorite deve retornar um array informando o estado final:
            // ['success' => true, 'action' => 'added' ou 'removed']
            $result = $this->repo->toggleFavorite((int)$user['id'], $freightId);

            if ($result['success']) {
                $isAdded = ($result['action'] === 'added');
                return Response::json([
                    "success" => true,
                    "favorited" => $isAdded, // Booleano para o React
                    "message" => $isAdded ? "Frete salvo nos favoritos" : "Removido dos favoritos"
                ]);
            }

            return Response::json(["success" => false, "message" => "Erro ao processar favorito"], 500);

        } catch (Exception $e) {
            error_log("Erro ToggleFavorite: " . $e->getMessage());
            return Response::json([
                "success" => false, 
                "message" => "Não foi possível atualizar favoritos."
            ], 500);
        }
    }

    /**
     * Unificação: Decide se abre WhatsApp, Chat Interno ou pede Escolha
     */
    public function contact($data, $user) {
        if (!$user) return Response::json(["success" => false, "message" => "Login necessário"], 401);

        $freightId = (int)($data['id'] ?? 0);
        $freight = $this->repo->getById($freightId);

        if (!$freight) {
            return Response::json(["success" => false, "message" => "Frete não encontrado"], 404);
        }

        // 1. Registra o Lead (Métrica de Intenção)
        // Importante: Usamos o tipo de evento 'CONTACT_INIT' para diferenciar de um simples 'CLICK'
        $this->repo->logMetric($freightId, 'FREIGHT', $user['id'], 'WHATSAPP_CLICK');

        // 2. Higienização do WhatsApp (Sempre priorizando o campo whatsapp revisado)
        $ownerWhatsapp = preg_replace('/\D/', '', $freight['whatsapp'] ?? $freight['owner_phone'] ?? '');
        
        // 3. Preferência de Contato
        $preference = strtolower($freight['contact_preference'] ?? 'whatsapp');

        // Mensagem padrão para o WhatsApp
        $msgText = "Olá, vi seu frete de " . $freight['origin_city'] . " (" . $freight['origin_state'] . ") para " . $freight['dest_city'] . " no Chama Frete!";
        $whatsappUrl = "https://wa.me/55{$ownerWhatsapp}?text=" . urlencode($msgText);

        

        // 4. Lógica de Direcionamento
        switch ($preference) {
            case 'whatsapp':
                return Response::json([
                    "success" => true,
                    "type" => "WHATSAPP",
                    "url" => $whatsappUrl
                ]);

            case 'chat':
                $roomId = $this->chatRepo->getOrCreateRoom($freightId, $user['id'], $freight['user_id']);
                return Response::json([
                    "success" => true,
                    "type" => "CHAT",
                    "room_id" => (int)$roomId,
                    "receiver_id" => (int)$freight['user_id']
                ]);

            case 'both':
                // Cria a sala de chat preventivamente se o usuário optar por ela
                $roomId = $this->chatRepo->getOrCreateRoom($freightId, $user['id'], $freight['user_id']);
                return Response::json([
                    "success" => true,
                    "type" => "CHOICE_REQUIRED",
                    "options" => ["chat", "whatsapp"],
                    "data" => [
                        "whatsapp_url" => $whatsappUrl,
                        "room_id" => (int)$roomId,
                        "receiver_id" => (int)$freight['user_id']
                    ]
                ]);

            default:
                // Fallback seguro: se algo falhar, manda para o WhatsApp
                return Response::json([
                    "success" => true,
                    "type" => "WHATSAPP",
                    "url" => $whatsappUrl,
                    "note" => "Fallback aplicado"
                ]);
        }
    }
    
    /**
     * Confirmação manual de pagamento (feita pela Empresa)
     */
    public function confirmPayment($data, $user) {
        // 1. Verificação de Autenticação
        if (!$user) return Response::json(["success" => false, "message" => "Login necessário"], 401);

        $freightId = (int)($data['id'] ?? 0);
        
        // 2. Busca o frete para verificar a propriedade
        $freight = $this->repo->getById($freightId);

        if (!$freight) {
            return Response::json(["success" => false, "message" => "Frete não encontrado"], 404);
        }

        // 3. Trava de Segurança: Somente o criador do frete (ou ADMIN) pode confirmar pagamento
        if ((int)$freight['user_id'] !== (int)$user['id'] && strtoupper($user['role']) !== 'ADMIN') {
            return Response::json([
                "success" => false, 
                "message" => "Você não tem permissão para alterar este frete."
            ], 403);
        }

        try {
            $this->db->beginTransaction();

            // 4. Atualiza o status de pagamento e encerra o frete
            // Ao confirmar pagamento, o frete deve ser marcado como 'FINISHED' ou 'CLOSED'
            $success = $this->repo->updatePaymentStatus($freightId, 'PAID');
            $this->repo->updateStatus($freightId, 'FINISHED');

            $this->db->commit();

            return Response::json([
                "success" => true, 
                "message" => "Pagamento confirmado e frete finalizado com sucesso!"
            ]);

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro ao confirmar pagamento: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao processar confirmação."], 500);
        }
    }

    /**
     * Confirmação de entrega (feita pelo Motorista ou Empresa)
     */
    public function confirmDelivery($data, $user) {
        // 1. Verificação de Autenticação e Role
        if (!$user) return Response::json(["success" => false], 401);

        $freightId = (int)($data['id'] ?? 0);
        
        // 2. Busca o frete para verificar quem é o motorista designado
        // Assumindo que seu banco tem uma coluna 'driver_id' para quando o frete é fechado
        $freight = $this->repo->getById($freightId);

        if (!$freight) {
            return Response::json(["success" => false, "message" => "Frete não encontrado"], 404);
        }

        // 3. Validação de Segurança
        // Somente o motorista que aceitou o frete (ou ADMIN) pode confirmar a entrega
        $isDriver = (int)($freight['driver_id'] ?? 0) === (int)$user['id'];
        $isOwner = (int)$freight['user_id'] === (int)$user['id'];
        if (!$isDriver && !$isOwner && strtoupper($user['role']) !== 'ADMIN') {
            return Response::json([
                "success" => false, 
                "message" => "Apenas o responsável pode confirmar a entrega."
            ], 403);
        }

        try {
            $this->db->beginTransaction();

            // 4. Finaliza o Frete no Repositório
            // Muda status para 'DELIVERED' ou 'FINISHED'
            $success = $this->repo->finishFreight($freightId);

            if ($success) {
                // 5. Gamificação e Reputação
                // Atualiza a reputação do motorista baseado no histórico dele
                $this->userRepo->refreshReputation($user['id']);
                
                // Opcional: Registrar evento de sucesso para o dashboard de conquistas
                $this->repo->logMetric($freightId, 'FREIGHT', $user['id'], 'DELIVERY_CONFIRMED');

                $this->db->commit();
                return Response::json([
                    "success" => true, 
                    "message" => "Entrega confirmada! Sua reputação foi atualizada."
                ]);
            }

            throw new Exception("Falha ao atualizar status do frete.");

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro ConfirmDelivery: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao processar entrega."], 500);
        }
    }
    
    public function acceptDriver($data, $loggedUser) {
        // 1. Validação de Entrada
        $freightId = (int)($data['freight_id'] ?? 0);
        $driverId  = (int)($data['driver_id'] ?? 0);

        if ($freightId <= 0 || $driverId <= 0) {
            return Response::json(["success" => false, "message" => "Dados inválidos"], 400);
        }

        // 2. Verificação de Propriedade
        $freight = $this->repo->getById($freightId);
        if (!$freight) {
            return Response::json(["success" => false, "message" => "Frete não encontrado"], 404);
        }

        // Apenas o dono do frete pode aceitar um motorista
        if ((int)$freight['user_id'] !== (int)$loggedUser['id'] && strtoupper($loggedUser['role']) !== 'ADMIN') {
            return Response::json(["success" => false, "message" => "Acesso negado"], 403);
        }

        try {
            $this->db->beginTransaction();

            // 3. Vincula o Motorista e muda o status do frete para 'IN_PROGRESS' ou 'PICKED_UP'
            // Isso retira o frete da listagem pública automaticamente
            $this->repo->assignDriver($freightId, $driverId);
            $this->repo->updateStatus($freightId, 'IN_PROGRESS');

            // Busca dados do motorista para o retorno e notificação
            $driver = $this->repo->getUserBasicData($driverId);
            if (!$driver) throw new Exception("Motorista não encontrado no sistema.");

            // 4. Notificação via Sistema (Sininho)
            $this->notificationService->send(
                $driverId, 
                "Carga Confirmada! 🚛", 
                "Você foi escolhido para o frete: " . $freight['product'],
                'match', 
                'high', 
                "/freight/details/" . ($freight['slug'] ?? $freightId)
            );

            $this->db->commit();

            // 5. Prepara o WhatsApp para a Empresa (Facilitar o contato pós-match)
            $cleanPhone = preg_replace('/\D/', '', $driver['whatsapp'] ?? '');
            $whatsappMsg = "Olá {$driver['name']}, sua proposta para o frete {$freight['product']} foi aceita no Chama Frete! Vamos combinar os detalhes?";
            $whatsappUrl = "https://wa.me/55{$cleanPhone}?text=" . urlencode($whatsappMsg);

            

            return Response::json([
                "success" => true,
                "message" => "Motorista vinculado com sucesso!",
                "data" => [
                    "whatsapp_url" => $whatsappUrl,
                    "driver_name"  => $driver['name'],
                    "status"       => "IN_PROGRESS"
                ]
            ]);

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro AcceptDriver: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao vincular motorista"], 500);
        }
    }

    /**
     * Lista motoristas que demonstraram interesse em um frete específico
     */
    public function listInterests($data, $loggedUser) {
        // 1. Identifica o contexto: Busca interessados em UM frete ou em TODOS da empresa?
        $freightId = (int)($data['id'] ?? $_GET['id'] ?? 0);

        try {
            if ($freightId > 0) {
                // Caso 1: Interessados em um frete específico (Verificação de segurança)
                $freight = $this->repo->findById($freightId);
                if (!$freight) {
                    return Response::json(["success" => false, "message" => "Frete não encontrado"], 404);
                }

                if ((int)$freight['user_id'] !== (int)$loggedUser['id'] && strtoupper($loggedUser['role'] ?? '') !== 'ADMIN') {
                    return Response::json(["success" => false, "message" => "Acesso negado"], 403);
                }

                $drivers = $this->repo->getDriversWhoClicked($freightId);
            } else {
                // Caso 2: Interessados em QUALQUER frete desta empresa (Usado no Dashboard Geral)
                $drivers = $this->repo->getInterestedDrivers($loggedUser['id']);
            }

            return Response::json([
                "success" => true,
                "total_interests" => count($drivers),
                "data" => $drivers 
            ]);

        } catch (Exception $e) {
            error_log("Erro ao listar interesses: " . $e->getMessage());
            return Response::json([
                "success" => false, 
                "message" => "Erro ao carregar lista de interessados."
            ], 500);
        }
    }
    
    /**
     * Rota: GET /api/list-my-freights
     */
    public function listMyFreights($data, $loggedUser) {
        // 1. Validação Crítica de Segurança
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Usuário não autenticado"], 401);
        }

        $userId = (int) $loggedUser['id'];

        try {
            // Normalização de paginação
            $page = max(1, (int)($data['page'] ?? 1));
            $perPage = max(1, min(100, (int)($data['perPage'] ?? 15)));

            // 2. Busca de Estatísticas Globais (BI)
            // Alterado para usar o $this->repo (FreightRepository) que já possui o método getUserStats
            // ou $this->userRepo caso você o tenha injetado.
            $stats = $this->repo->getUserStats($userId);

            // 3. Busca a listagem paginada
            // Note que passamos filtros vazios ou o próprio $data para o repositório
            $results = $this->repo->listPaginated($userId, $data, $page, $perPage);

            // O seu Repository retorna os dados em chaves diferentes dependendo da versão
            // Ajustamos para garantir que pegamos o array de itens
            $freights = $results['items'] ?? $results['data'] ?? [];
            $totalItems = $results['total'] ?? $results['meta']['total_items'] ?? 0;

            // 4. Montagem do Resumo (Summary) com cálculos defensivos
            $totalViews  = (int)($stats['global_views'] ?? 0);
            $totalClicks = (int)($stats['global_clicks'] ?? 0);
            // Leads geralmente são cliques no WhatsApp ou contatos diretos
            $totalLeads  = (int)($stats['total_leads'] ?? $totalClicks); 

            $summary = [
                'total'           => (int)$totalItems,
                'total_views'     => $totalViews,
                'total_leads'     => $totalLeads,
                'total_clicks'    => $totalClicks,
                'conversion_rate' => 0
            ];

            // Cálculo de Performance (Conversão de View para Clique/Lead)
            if ($totalViews > 0) {
                $summary['conversion_rate'] = round(($totalLeads / $totalViews) * 100, 2);
            }

            return Response::json([
                "success" => true,
                "summary" => $summary,
                "meta"    => [
                    "current_page" => $page,
                    "per_page"     => $perPage,
                    "total_items"  => $totalItems,
                    "total_pages"  => ceil($totalItems / $perPage)
                ],
                "data"    => $freights 
            ]);

        } catch (\Exception $e) {
            error_log("❌ Erro listMyFreights (User ID $userId): " . $e->getMessage());
            return Response::json([
                "success" => false, 
                "message" => "Erro ao processar métricas do painel.",
                "debug"   => $e->getMessage() // Remova o debug em produção
            ], 500);
        }
    }

    public function delete($data, $loggedUser) {
        // 1. Verificação de Autenticação
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Não autorizado"], 401);
        }

        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            return Response::json(["success" => false, "message" => "ID inválido"], 400);
        }

        try {
            // 2. Busca o frete para validação de contexto
            $freight = $this->repo->getById($id);

            if (!$freight) {
                return Response::json(["success" => false, "message" => "Frete não encontrado"], 404);
            }

            // 3. Trava de Segurança: Apenas dono ou ADMIN
            $isOwner = (int)$freight['user_id'] === (int)$loggedUser['id'];
            $isAdmin = strtoupper($loggedUser['role'] ?? '') === 'ADMIN';

            if (!$isOwner && !$isAdmin) {
                return Response::json([
                    "success" => false, 
                    "message" => "Você não tem permissão para excluir este frete."
                ], 403);
            }

            // 4. Execução do Soft Delete
            // O repositório deve setar status = 'DELETED' ou preencher deleted_at = NOW()
            $success = $this->repo->softDelete($id);

            

            if ($success) {
                return Response::json([
                    "success" => true, 
                    "message" => "O frete foi removido com sucesso."
                ]);
            }

            throw new Exception("Falha na execução do banco de dados.");

        } catch (Exception $e) {
            error_log("Erro ao deletar frete {$id}: " . $e->getMessage());
            return Response::json([
                "success" => false, 
                "message" => "Erro interno ao tentar remover o frete."
            ], 500);
        }
    }

    public function update($data, $loggedUser) {
        if (!$loggedUser) return Response::json(["success" => false], 401);

        $id = (int)($data['id'] ?? 0);
        
        // 1. Busca o frete original para validar posse
        $currentFreight = $this->repo->getById($id);
        if (!$currentFreight) {
            return Response::json(["success" => false, "message" => "Frete não encontrado"], 404);
        }

        // 2. Trava de Segurança
        if ((int)$currentFreight['user_id'] !== (int)$loggedUser['id'] && strtoupper($loggedUser['role']) !== 'ADMIN') {
            return Response::json(["success" => false, "message" => "Acesso negado"], 403);
        }

        try {
            $this->db->beginTransaction();

            // 3. Preparação dos dados (Apenas o que é permitido editar)
            $payload = [
                'origin_city'  => trim($data['origin_city'] ?? $currentFreight['origin_city']),
                'origin_state' => strtoupper(trim($data['origin_state'] ?? $currentFreight['origin_state'])),
                'dest_city'    => trim($data['dest_city'] ?? $currentFreight['dest_city']),
                'dest_state'   => strtoupper(trim($data['dest_state'] ?? $currentFreight['dest_state'])),
                'product'      => trim($data['product'] ?? $currentFreight['product']),
                'weight'       => (float)($data['weight'] ?? $currentFreight['weight']),
                'vehicle_type' => $data['vehicle_type'] ?? $currentFreight['vehicle_type'],
                'body_type'    => $data['body_type'] ?? $currentFreight['body_type'],
                'description'  => strip_tags($data['description'] ?? $currentFreight['description']),
                'price'        => (float)($data['price'] ?? $currentFreight['price']),
                'whatsapp'     => preg_replace('/\D/', '', $data['whatsapp'] ?? $currentFreight['whatsapp'])
            ];

            // 4. Lógica de Atualização do Slug
            // Se mudou o produto ou as cidades, o slug antigo fica "mentiroso". Vamos gerar um novo.
            if ($payload['product'] !== $currentFreight['product'] || 
                $payload['origin_city'] !== $currentFreight['origin_city'] ||
                $payload['dest_city'] !== $currentFreight['dest_city']) {
                
                $slugBase = $payload['product'] . " de " . $payload['origin_city'] . " para " . $payload['dest_city'];
                $payload['slug'] = $this->generateSlug($slugBase, $id);
            }

            // 5. Salva as alterações
            $this->repo->update($id, $payload);

            $this->db->commit();

            return Response::json([
                "success" => true, 
                "message" => "Frete atualizado com sucesso!",
                "slug"    => $payload['slug'] ?? $currentFreight['slug']
            ]);

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Erro no Update Freight {$id}: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao salvar alterações"], 500);
        }
    }
    
    /**
     * Helper para gerar URLs amigáveis
     */
    private function generateSlug($text, $id) {
        // Exemplo: "Carga de Café" -> "carga-de-cafe-123"
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text); 
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $text)));
        return $slug . '-' . $id;
    }

    private function isContentClean($text) {
        // Lista de termos proibidos (Spam, Ofensas, Concorrência)
        $badWords = [
            'idiota', 'golpe', 'urubu do pix', 'ganhe dinheiro fácil', 
            'site-concorrente.com', 'maldito', 'desgraça' // Adicione quantos quiser
        ];

        $text = mb_strtolower($text);

        foreach ($badWords as $word) {
            if (str_contains($text, $word)) {
                return false;
            }
        }

        // Validação extra: Evitar excesso de links (Spam)
        if (preg_match_all('/http|www/i', $text) > 2) {
            return false;
        }

        return true;
    }

    public function getSuggestions($query) {
        $query = trim($query);
        if (strlen($query) < 2) return Response::json([]);

        // Busca termos populares que começam com o que o usuário digitou
        $sql = "SELECT term, COUNT(*) as popularity 
                FROM search_logs 
                WHERE term LIKE :q 
                GROUP BY term 
                ORDER BY popularity DESC 
                LIMIT 5";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':q' => $query . '%']);
        $suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return Response::json($suggestions);
    }
}