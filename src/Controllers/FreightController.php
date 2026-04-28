<?php
namespace App\Controllers;

use App\Core\Response;
use App\Repositories\FreightRepository;
use App\Services\NotificationService;
use App\Services\CreditService;
use App\Services\AccessControlService;
use Exception;

class FreightController {
    private $db;
    private $userRepo;
    private $repo;
    private $notificationService;
    private $chatRepo;
private $auditRepo;
    private $creditService;
    private $accessControlService;

    public function __construct(
    $freightRepo, 
    $notificationService, 
    $userRepo = null,
    $db = null,
    $chatRepo = null,
    $auditRepo = null
) { 
    $this->repo = $freightRepo;
    $this->notificationService = $notificationService;
    $this->userRepo = $userRepo;
    $this->db = $db;
    $this->chatRepo = $chatRepo;
    $this->auditRepo = $auditRepo;
$this->creditService = $db ? new CreditService($db) : null;
        $this->accessControlService = $db ? new AccessControlService($db) : null;
    }

    public function listAll($data, $loggedUser) {
        // 1. Limpeza de inputs
        $search = $data['search'] ?? $_GET['search'] ?? '';
        $page = isset($data['page']) ? (int)$data['page'] : (isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $perPage = isset($data['perPage']) ? (int)$data['perPage'] : 15;
        $isSmartMatch = isset($data['smart_match']) && $data['smart_match'] == 1; // <--- NOVA LINHA
        
        if ($page < 1) $page = 1;

        // 2. ListAll SEMPRE retorna todos os fretes (público)
        // Para fretes da empresa, usar /api/list-my-freights
        $userIdParam = null;

        try {
            // 3. SE O RADAR SMART ESTIVER ATIVO (Apenas para Motoristas)
            if ($isSmartMatch && isset($loggedUser['id'])) {
                $freights = $this->repo->getSmartMatchFreights($loggedUser['id']);
                return Response::json([
                    "success" => true,
                    "data" => $freights,
                    "total" => count($freights),
                    "page" => 1,
                    "perPage" => 50
                ]);
            }

            // 4. Lógica Padrão (Empresa vê os dela, Motorista vê tudo ou Busca)
            $results = $this->repo->listPaginated(
                $userIdParam, 
                [
                    'search' => $search,
                    'viewer_id' => $loggedUser['id'] ?? null 
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

    public function myFreights($data, $loggedUser) {
        if (!$loggedUser) {
            return Response::json(["success" => false, "message" => "Unauthorized"], 401);
        }
        
        $userId = $loggedUser['id'];
        $search = $data['search'] ?? '';
        
        try {
            $results = $this->repo->listPaginated(
                $userId, 
                ['search' => $search],
                1,
                50
            );
            
            return Response::json($results);
        } catch (Exception $e) {
            error_log("❌ ERRO NO CONTROLLER myFreights: " . $e->getMessage());
            return Response::json([
                "success" => false, 
                "message" => "Erro ao carregar fretes."
            ], 500);
        }
    }

public function createFreight($data, $user) {
        // 0. Verificar limite de publicações (Access Control)
        if ($this->accessControlService) {
            $canPublish = $this->accessControlService->canPublish((int)$user['id'], 'freights');
            if (!$canPublish['allowed']) {
                return Response::json([
                    "success" => false, 
                    "message" => $canPublish['reason'],
                    "code" => "LIMIT_EXCEEDED",
                    "used" => $canPublish['used'],
                    "limit" => $canPublish['limit'],
                    "remaining" => $canPublish['remaining']
                ], 402);
            }
        }

        // 1. Anti-spam: 1 minuto entre postagens
        $lastFreight = $this->repo->getLastFreightTime((int)$user['id']);
        if ($lastFreight && (time() - strtotime($lastFreight)) < 60) {
            return Response::json(["success" => false, "message" => "Aguarde um minuto para postar novamente."], 429);
        }

        // 2. Validação de Regra (Apenas Empresa ou Admin)
        $role = strtoupper($user['role'] ?? '');
        if ($role !== 'COMPANY' && $role !== 'ADMIN') {
            return Response::json(["success" => false, "message" => "Acesso negado."], 403);
        }

        // 3. Validação de Onboarding
        if ($role === 'COMPANY' && (empty($user['document']) || empty($user['name']))) {
            return Response::json([
                "success" => false, 
                "message" => "Perfil incompleto. Por favor, preencha seu CNPJ e nome para publicar."
            ], 403);
        }

        // 4. Validação de Dados do Frete
        if (empty($data['origin_city']) || empty($data['dest_city']) || empty($data['product'])) {
            return Response::json(["success" => false, "message" => "Informe origem, destino e o produto."], 400);
        }

        // 5. Verificar tipo de publicação e calcular valor
        $isUrgent = !empty($data['is_urgent']) && $data['is_urgent'] == true;
        $isFeatured = !empty($data['is_featured']) && $data['is_featured'] == true;

        $featureKey = 'publish';
        $amount = 14.90;
        $expiresDays = 7;

        if ($isUrgent) {
            $featureKey = 'urgent';
            $amount = 24.90;
            $expiresDays = 2;
        } elseif ($isFeatured) {
            $featureKey = 'boost';
            $amount = 19.90;
            $expiresDays = 5;
        }

        $userId = ($role === 'ADMIN' && !empty($data['user_id'])) ? (int)$data['user_id'] : (int)$user['id'];

        // Admin não paga
        $paymentRequired = ($role !== 'ADMIN');

        // 6. Verificar saldo e debitar
        if ($paymentRequired && $this->creditService) {
            $balance = $this->creditService->getBalance($userId);
            
            if ($balance < $amount) {
                return Response::json([
                    "success" => false,
                    "message" => "Saldo insuficiente. Você tem R$ " . number_format($balance, 2, ',', '.') . " na carteira. Custo: R$ " . number_format($amount, 2, ',', '.') . ".",
                    "balance" => $balance,
                    "required" => $amount,
                    "code" => "INSUFFICIENT_BALANCE"
                ], 402);
            }

            $debited = $this->creditService->debit($userId, $amount, 'freights', $featureKey);
            if (!$debited) {
                return Response::json([
                    "success" => false,
                    "message" => "Erro ao debitar saldo. Tente novamente."
                ], 500);
            }
        }

        try {
            // 7. Definição de Status
            $status = 'OPEN'; 

            // 8. Verificação de Conteúdo
            $contentToVerify = ($data['product'] ?? '') . ' ' . ($data['description'] ?? '');
            if (!$this->isContentClean($contentToVerify)) {
                $status = 'PENDING';
            }

            // 9. Preparação do Payload
            $slugBase = trim($data['product']) . " de " . trim($data['origin_city']) . " para " . trim($data['dest_city']);
            $uniqueSuffix = bin2hex(random_bytes(3));
            $finalSlug = $this->generateSlug($slugBase, $uniqueSuffix);

            $payload = [
                'user_id'      => $userId,
                'account_id'   => $user['account_id'] ?? null,
                'origin_city'  => trim($data['origin_city']),
                'origin_state' => strtoupper(trim($data['origin_state'] ?? '')),
                'dest_city'    => trim($data['dest_city']),
                'dest_state'   => strtoupper(trim($data['dest_state'] ?? '')),
                'product'      => trim($data['product']),
                'weight'       => max(0.0, (float)($data['weight'] ?? 0)),
                'price'        => max(0.0, (float)($data['price'] ?? 0)),
                'vehicle_type' => $data['vehicle_type'] ?? 'Qualquer',
                'body_type'    => $data['body_type'] ?? 'Qualquer',
                'description'  => strip_tags($data['description'] ?? ''),
                'status'       => $status,
                'slug'         => $finalSlug,
                'expires_at'   => date('Y-m-d H:i:s', strtotime("+$expiresDays days")),
                'is_featured'  => $isFeatured ? 1 : 0,
                'is_urgent'    => $isUrgent ? 1 : 0
            ];

$id = $this->repo->save($payload);

            // 10. Registrar uso para controle de limite
            if ($this->accessControlService && $id) {
                $this->accessControlService->recordUsage((int)$user['id'], 'freights', (int)$id, 'freight');
            }

            $newBalance = $paymentRequired && $this->creditService ? $this->creditService->getBalance($userId) : null;

            return Response::json([
                "success" => true, 
                "id"      => (int)$id, 
                "status"  => $status,
                "cost"    => $paymentRequired ? $amount : 0,
                "balance" => $newBalance,
                "message" => $status === 'PENDING' ? "Frete publicado! (Aguardando revisão de conteúdo)" : "Frete publicado com sucesso!"
            ]);

        } catch (Exception $e) {
            error_log("Erro Create Freight: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro interno ao salvar."], 500);
        }
    }

    /**
     * Registra interesse e métricas de um FRETE (Auditável)
     * Chamado quando o motorista clica em "Ver Telefone", "WhatsApp" ou abre o frete.
     */
    public function logEvent($data, $user) {
        // 1. Captura e higienização (Normalizando os campos que o seu Front pode enviar)
        $targetId   = (int)($data['id'] ?? $data['target_id'] ?? 0);
        $targetType = strtoupper($data['target_type'] ?? 'FREIGHT'); 
        $eventType  = strtoupper($data['event_type'] ?? $data['type'] ?? 'VIEW'); 

        if ($targetId <= 0) {
            return Response::json(["success" => false, "message" => "ID inválido"], 400);
        }

        // 2. Coleta de dados de contexto (Essencial para o Manager detectar abusos)
        $meta = [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        ];

        try {
            /**
             * 3. Registro Duplo no Repository:
             * O método logMetric deve:
             * a) Inserir um registro na tabela 'metrics' ou 'search_logs' (Histórico)
             * b) Chamar o incrementCounter interno para somar +1 na tabela 'freights' (Contador rápido)
             */
            $success = $this->repo->logMetric(
                $targetId, 
                $targetType, 
                $user['id'] ?? null, 
                $eventType,
                $meta
            );

            return Response::json(["success" => true]);

        } catch (Exception $e) {
            error_log("Erro ao registrar métrica de frete ({$eventType}): " . $e->getMessage());
            // Retornamos true para o front não dar erro visual ao motorista
            return Response::json(["success" => true]); 
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

    public function myFavorites($data, $loggedUser) {
        // 1. Verifica se o usuário está logado
        $userId = $loggedUser['id'] ?? $data['user_id'] ?? null;
        
        if (!$userId) {
            return Response::json(["success" => false, "message" => "Usuário não identificado"], 400);
        }

        // 2. Chama o Repository
        $favorites = $this->repo->getFavoritesByUser($userId);

        // 3. Retorna para o Front-end
        return Response::json([
            "success" => true,
            "data" => $favorites
        ]);
    }

    /**
     * Retorna os totais de cargas, favoritos e convites para os KPIs do topo
     */
    public function getDriverStats($data, $loggedUser) {
        $userId = $loggedUser['id'] ?? $data['user_id'] ?? null;

        if (!$userId) {
            return Response::json(["success" => false, "message" => "Usuário não identificado"], 400);
        }

        // Chama o Repository que criamos anteriormente
        $stats = $this->repo->getDriverStats($userId);

        return Response::json([
            "success" => true,
            "data" => $stats
        ]);
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
    
    public function inviteDriver($freightId, $driverId, $companyId) {
        // 1. Busca os detalhes do frete para a mensagem
        $f = $this->db->query("SELECT product, origin_city, dest_city FROM freights WHERE id = $freightId")->fetch();
        $company = $this->db->query("SELECT name FROM users WHERE id = $companyId")->fetch();

        $message = "A empresa {$company['name']} te convidou para transportar {$f['product']} de {$f['origin_city']} para {$f['dest_city']}.";

        // 2. Insere na tabela de alertas que você já possui
        $sql = "INSERT INTO user_alerts (user_id, type, message, link, status, created_at) 
                VALUES (?, 'INVITATION', ?, ?, 'unread', NOW())";
        
        $stmt = $this->db->prepare($sql);
        $link = "/frete/" . $freightId; // Link para ele ver o frete
        
        return $stmt->execute([$driverId, $message, $link]);
    }

    public function respondInvitation(Request $request) {
        $alertId = $request->input('alert_id');
        $action = $request->input('action'); // 'accept' ou 'decline'

        // Chama o repository
        $success = $this->freightRepository->respondToInvitation($alertId, $action);

        if ($success) {
            return response()->json(['success' => true, 'message' => 'Resposta registrada!']);
        } else {
            return response()->json(['success' => false, 'message' => 'Erro ao processar convite.'], 500);
        }
    }

    // Endpoint: my-active-freight
    public function myActiveFreight(Request $request) {
        $userId = $request->input('user_id');
        
        if (!$userId) {
            return response()->json(['error' => 'Usuário não identificado'], 400);
        }

        $data = $this->freightRepository->getActiveFreight($userId);
        return response()->json($data ?: null);
    }

    // Endpoint: user-alerts
    public function userAlerts(Request $request) {
        $userId = $request->input('user_id');
        $type = $request->input('type', 'INVITATION');
        $status = $request->input('status', 'unread');

        $data = $this->freightRepository->getUserAlerts($userId, $type, $status);
        return response()->json($data);
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

    public function updateFreight($data, $loggedUser) {
        if (!$loggedUser) return Response::json(["success" => false], 401);

        $id = (int)($data['id'] ?? 0);
        
        // 1. Busca o frete original
        $currentFreight = $this->repo->getRawById($id);
        if (!$currentFreight) {
            return Response::json(["success" => false, "message" => "Frete não encontrado"], 404);
        }

        $is_admin = in_array(strtolower($loggedUser['role'] ?? ''), ['admin', 'manager']);
        if (!$is_admin && (int)$currentFreight['user_id'] !== (int)$loggedUser['id']) {
            return Response::json(["success" => false, "message" => "Acesso negado"], 403);
        }

        try {
             // --- NOVO: VALIDAÇÃO DE TERMOS PROIBIDOS --- Validamos o produto e a descrição ---
            $contentToValidate = ($data['product'] ?? '') . ' ' . ($data['description'] ?? '');
            if (!$this->isContentClean($contentToValidate)) {
                return Response::json([
                    "success" => false, 
                    "message" => "Conteúdo impróprio: Remova termos proibidos ou excesso de links."
                ], 400);
            }

            if (!$this->db->inTransaction()) $this->db->beginTransaction();

            // 2. Preparação dos dados
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
            ];

            if ($is_admin && isset($data['user_id'])) {
                $payload['user_id'] = (int)$data['user_id'];
            }

            // --- CORREÇÃO DO SLUG ---
            $hasLocationChanged = ($payload['origin_city'] !== $currentFreight['origin_city'] || $payload['dest_city'] !== $currentFreight['dest_city']);
            $hasProductChanged = ($payload['product'] !== $currentFreight['product']);

            if ($hasLocationChanged || $hasProductChanged || empty($currentFreight['slug'])) {
                // Se mudou algo importante OU se o frete original estava sem slug, gera um novo
                $slugBase = $payload['product'] . " de " . $payload['origin_city'] . " para " . $payload['dest_city'];
                $payload['slug'] = $this->generateSlug($slugBase, $id);
            } else {
                // Se não mudou nada que afete o slug, mantemos o slug que já existia no banco
                $payload['slug'] = $currentFreight['slug'];
            }

            // 3. Salva no banco
            $updateSuccess = $this->repo->update($id, $payload);

            if ($updateSuccess) {
                // Auditoria de logs
                $changes = [];
                foreach ($payload as $key => $value) {
                    if (isset($currentFreight[$key]) && $value != $currentFreight[$key]) {
                        $changes['old'][$key] = $currentFreight[$key];
                        $changes['new'][$key] = $value;
                    }
                }

                if ($this->auditRepo && !empty($changes)) {
                    $userName = $loggedUser['name'] ?? $loggedUser['company_name'] ?? 'Usuário '.$loggedUser['id'];
                    $this->auditRepo->saveLog(
                        $loggedUser['id'], $userName, 'UPDATE_FREIGHT',
                        "Editou o frete #{$id}", $id, 'freights',
                        $changes['old'], $changes['new']
                    );
                }
            }

            $this->db->commit();

            return Response::json([
                "success" => true, 
                "message" => "Frete atualizado com sucesso!",
                "slug"    => $payload['slug'] // Retorna o slug (novo ou mantido)
            ]);

        } catch (Exception $e) {
            if ($this->db && $this->db->inTransaction()) $this->db->rollBack();
            return Response::json(["success" => false, "message" => "Erro: " . $e->getMessage()], 500);
        }
    }
    
    /**
     * Helper para gerar URLs amigáveis
     */
    private function generateSlug($text, $id) {
        // 1. Converter para minúsculo e remover espaços extras
        $text = mb_strtolower(trim($text), 'UTF-8');
        // 2. Substituição manual de acentos (Garante funcionamento em qualquer servidor)
        $chars = [
            'a' => '/[áàâãäå]/u', 'e' => '/[éèêë]/u', 'i' => '/[íìîï]/u',
            'o' => '/[óòôõö]/u', 'u' => '/[úùûü]/u', 'c' => '/[ç]/u',
            'n' => '/[ñ]/u'
        ];
        foreach ($chars as $replacement => $pattern) {
            $text = preg_replace($pattern, $replacement, $text);
        }
        // 3. Remove tudo que não for letra, número ou espaço
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        // 4. Transforma espaços e múltiplos hifens em um único hífen
        $text = preg_replace('/[\s-]+/', '-', $text);
        // 5. Remove hifens das extremidades e concatena o ID
        $slug = trim($text, '-');
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

    public function confirmMatch($freightId, $driverId, $companyId, $agreedAmount) {
        try {
            $this->db->beginTransaction();

            // 1. Registrar na tabela financeira (Documentação Jurídica do Valor)
            $sqlPay = "INSERT INTO freight_payments 
                    (freight_id, payer_id, payee_id, amount, status, description, created_at) 
                    VALUES (?, ?, ?, ?, 'escrow', 'Acordo digital firmado entre as partes.', NOW())";
            $stmtPay = $this->db->prepare($sqlPay);
            $stmtPay->execute([$freightId, $companyId, $driverId, $agreedAmount]);

            // 2. Registrar na tabela de tracking (Ciclo de Vida)
            $sqlTrack = "INSERT INTO freight_tracking 
                        (freight_id, driver_id, company_id, status, description, created_at) 
                        VALUES (?, ?, ?, 'MATCH_CONFIRMED', 'Aperto de mão digital realizado.', NOW())";
            $stmtTrack = $this->db->prepare($sqlTrack);
            $stmtTrack->execute([$freightId, $driverId, $companyId]);

            // 3. Atualizar o frete para 'em andamento'
            $sqlFreight = "UPDATE freights SET status = 'in_transit' WHERE id = ?";
            $stmtFreight = $this->db->prepare($sqlFreight);
            $stmtFreight->execute([$freightId]);

            $this->db->commit();
            return ["success" => true, "message" => "Acordo firmado com sucesso!"];

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        }
    }

    public function getSuggestedDrivers($freightId) {
        // Busca os requisitos da carga postada
        $freight = $this->db->query("SELECT origin_city, vehicle_type, body_type FROM freights WHERE id = $freightId")->fetch();

        // Busca motoristas compatíveis
        $sql = "SELECT 
                    u.id, u.name, u.whatsapp, 
                    up.avatar_url, up.vehicle_type, up.body_type,
                    (SELECT COUNT(*) FROM reviews WHERE related_id = u.id) as total_reviews,
                    (SELECT AVG(rating) FROM reviews WHERE related_id = u.id) as rating
                FROM users u
                INNER JOIN user_profiles up ON u.id = up.user_id
                WHERE u.user_type = 'DRIVER'
                AND up.vehicle_type = :v_type
                AND (u.city = :city OR up.preferred_region = :city)
                ORDER BY rating DESC, total_reviews DESC
                LIMIT 4";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':v_type' => $freight['vehicle_type'],
            ':city'   => $freight['origin_city']
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Retorna as empresas que mais anunciam (Top 10)
     */
    public function getTopAdvertisersFreight($request, $response) {
        try {
            // CORREÇÃO DO ERRO "ON ARRAY":
            // Verifica se é array (pega direto) ou objeto (usa o método do Slim)
            $limit = 10;
            if (is_array($request)) {
                $limit = isset($request['limit']) ? (int)$request['limit'] : 10;
            } elseif (method_exists($request, 'getQueryParams')) {
                $params = $request->getQueryParams();
                $limit = isset($params['limit']) ? (int)$params['limit'] : 10;
            }

            // Garante que o repo existe
            if (!$this->repo) {
                return $response->withJson([
                    'success' => false, 
                    'message' => 'Repositório não configurado'
                ], 500);
            }

            $result = $this->repo->getTopAdvertisers($limit);

            if ($result['success']) {
                return \App\Core\Response::json([
                    'success' => true,
                    'data' => $result['data']
                ], 200);
            }

            return \App\Core\Response::json([
                'success' => false,
                'message' => 'Erro ao buscar anunciantes'
            ], 400);

        } catch (\Exception $e) {
            return \App\Core\Response::json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Busca o histórico de tracking de um frete
     */
    public function getFreightTracking($data, $loggedUser) {
        try {
            $freightId = intval($data['freight_id'] ?? 0);
            
            if (!$freightId) {
                return Response::json([
                    'success' => false,
                    'message' => 'ID do frete é obrigatório'
                ], 400);
            }

            $stmt = $this->db->prepare("
                SELECT 
                    ft.id,
                    ft.status,
                    ft.description,
                    ft.latitude,
                    ft.longitude,
                    ft.created_at,
                    ft.is_final_step,
                    d.name as driver_name,
                    d.whatsapp as driver_whatsapp,
                    c.name as company_name
                FROM freight_tracking ft
                LEFT JOIN users d ON ft.driver_id = d.id
                LEFT JOIN users c ON ft.company_id = c.id
                WHERE ft.freight_id = ?
                ORDER BY ft.created_at ASC
            ");
            $stmt->execute([$freightId]);
            $tracking = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Busca info do frete
            $stmt = $this->db->prepare("
                SELECT 
                    f.id,
                    f.status,
                    f.origin_city,
                    f.origin_state,
                    f.destination_city,
                    f.destination_state,
                    f.vehicle_type,
                    f.body_type,
                    f.cargo_type,
                    f.weight,
                    f.agreed_amount,
                    f.driver_id,
                    f.company_id,
                    u.name as driver_name,
                    u.phone as driver_phone,
                    c.name as company_name
                FROM freights f
                LEFT JOIN users u ON f.driver_id = u.id
                LEFT JOIN users c ON f.company_id = c.id
                WHERE f.id = ?
            ");
            $stmt->execute([$freightId]);
            $freight = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$freight) {
                return Response::json([
                    'success' => false,
                    'message' => 'Frete não encontrado'
                ], 404);
            }

            return Response::json([
                'success' => true,
                'data' => [
                    'freight' => $freight,
                    'tracking' => $tracking
                ]
            ]);
        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Busca motoristas compatíveis com um frete específico
     * Rota: GET /api/freight/:id/matching-drivers
     */
    public function findMatchingDrivers($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(["success" => false, "message" => "Sessão expirada"], 401);
        }
        
        try {
            $freightId = (int)($data['id'] ?? 0);
            $maxDistance = (int)($data['max_distance_km'] ?? 200);
            
            if (!$freightId) {
                return Response::json(["success" => false, "message" => "ID do frete não informado"], 400);
            }
            
            // Verifica se o frete pertence ao usuário ou se é admin
            $stmt = $this->db->prepare("
                SELECT id, origin_city, origin_state, origin_lat, origin_lng,
                       dest_city, dest_state, vehicle_type, body_type, product,
                       user_id, equipment_needed, certifications_needed
                FROM freights 
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$freightId]);
            $freight = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$freight) {
                return Response::json(["success" => false, "message" => "Frete não encontrado"], 404);
            }
            
            // Empresas só veem fretes delas
            if ($loggedUser['role'] === 'company' && $freight['user_id'] != $loggedUser['id']) {
                return Response::json(["success" => false, "message" => "Acesso negado"], 403);
            }
            
            // Se não tem coordenadas, retorna mensagem
            if (!$freight['origin_lat'] || !$freight['origin_lng']) {
                return Response::json([
                    "success" => true,
                    "drivers" => [],
                    "message" => "Frete ainda não possui localização georreferenciada. Atualize o endereço para encontrar motoristas próximos."
                ]);
            }
            
            // Busca motoristas compatíveis
            $query = "
                SELECT 
                    u.id AS driver_id,
                    u.name AS driver_name,
                    u.slug AS driver_slug,
                    u.whatsapp AS driver_whatsapp,
                    p.vehicle_type,
                    p.body_type,
                    p.home_city,
                    p.home_state,
                    p.service_radius_km,
                    p.available_equipment,
                    p.rntrc_number,
                    p.avatar_url,
                    p.verification_status,
                    p.profile_completeness,
                    ROUND(
                        6371 * ACOS(
                            LEAST(1.0, GREATEST(-1.0,
                            COS(RADIANS(:origin_lat)) * COS(RADIANS(p.home_lat)) *
                            COS(RADIANS(p.home_lng) - RADIANS(:origin_lng)) +
                            SIN(RADIANS(:origin_lat)) * SIN(RADIANS(p.home_lat))
                            ))
                        )
                    , 2) AS distance_km,
                    CASE WHEN p.availability_status = 'available' THEN 30 ELSE 0 END +
                    CASE WHEN p.vehicle_type = :vehicle_type THEN 30 ELSE 0 END +
                    CASE WHEN p.body_type = :body_type THEN 20 ELSE 0 END +
                    CASE WHEN p.verification_status = 'verified' THEN 20 ELSE 0 END AS match_score
                FROM users u
                INNER JOIN user_profiles p ON u.id = p.user_id
                WHERE u.role = 'driver'
                    AND u.status = 'active'
                    AND p.availability_status = 'available'
                    AND p.home_lat IS NOT NULL
                    AND p.home_lng IS NOT NULL
                    AND ROUND(
                        6371 * ACOS(
                            LEAST(1.0, GREATEST(-1.0,
                            COS(RADIANS(:origin_lat2)) * COS(RADIANS(p.home_lat)) *
                            COS(RADIANS(p.home_lng) - RADIANS(:origin_lng2)) +
                            SIN(RADIANS(:origin_lat2)) * SIN(RADIANS(p.home_lat))
                            ))
                        )
                    , 2) <= :max_distance
                    AND ROUND(
                        6371 * ACOS(
                            LEAST(1.0, GREATEST(-1.0,
                            COS(RADIANS(:origin_lat3)) * COS(RADIANS(p.home_lat)) *
                            COS(RADIANS(p.home_lng) - RADIANS(:origin_lng3)) +
                            SIN(RADIANS(:origin_lat3)) * SIN(RADIANS(p.home_lat))
                            ))
                        )
                    , 2) <= p.service_radius_km
                ORDER BY match_score DESC, distance_km ASC
                LIMIT 30
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':origin_lat' => $freight['origin_lat'],
                ':origin_lng' => $freight['origin_lng'],
                ':vehicle_type' => $freight['vehicle_type'] ?? '',
                ':body_type' => $freight['body_type'] ?? '',
                ':origin_lat2' => $freight['origin_lat'],
                ':origin_lng2' => $freight['origin_lng'],
                ':origin_lat3' => $freight['origin_lat'],
                ':origin_lng3' => $freight['origin_lng'],
                ':max_distance' => $maxDistance,
            ]);
            
            $drivers = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Parse JSON e limpa dados
            foreach ($drivers as &$driver) {
                $driver['available_equipment'] = isset($driver['available_equipment']) 
                    ? json_decode($driver['available_equipment'], true) ?? [] 
                    : [];
                // Não expõe dados sensíveis
                unset($driver['rntrc_number']);
            }
            
            return Response::json([
                "success" => true,
                "freight_id" => $freightId,
                "origin" => $freight['origin_city'] . '/' . $freight['origin_state'],
                "vehicle_type" => $freight['vehicle_type'],
                "body_type" => $freight['body_type'],
                "drivers" => $drivers,
                "total" => count($drivers)
            ]);
            
        } catch (\Exception $e) {
            error_log("ERRO findMatchingDrivers: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao buscar motoristas"], 500);
        }
    }
}