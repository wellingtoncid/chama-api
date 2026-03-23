<?php
namespace App\Controllers;

use App\Core\Response;
use App\Repositories\QuoteRepository;
use App\Repositories\UserRepository;
use Exception;

class QuoteController {
    private QuoteRepository $quoteRepo;
    private ?UserRepository $userRepo;
    private $db;

    public function __construct($db, $userRepo = null) {
        $this->db = $db;
        $this->quoteRepo = new QuoteRepository($db);
        $this->userRepo = $userRepo;
    }

    private function userHasModule(int $userId, string $moduleKey, string $featureKey = null): bool {
        $sql = "SELECT id FROM user_modules 
                WHERE user_id = :user_id AND module_key = :module_key 
                AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())";
        
        $params = [':user_id' => $userId, ':module_key' => $moduleKey];
        
        if ($featureKey) {
            $sql .= " AND plan_id IN (SELECT id FROM plans WHERE module_key = :module_key AND feature_key = :feature_key AND is_active = 1)";
            $params[':feature_key'] = $featureKey;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetch();
    }

    public function create($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $required = ['type', 'title'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return Response::json(['success' => false, 'message' => "Campo obrigatório: $field"], 400);
            }
        }

        $allowedTypes = ['frete', 'armazenagem', 'operacao_logistica', 'cross_docking'];
        if (!in_array($data['type'], $allowedTypes)) {
            return Response::json(['success' => false, 'message' => 'Tipo de cotação inválido'], 400);
        }

        $hasModule = $this->userHasModule($loggedUser['id'], 'quotes', 'request_quote');
        if (!$hasModule) {
            return Response::json([
                'success' => false, 
                'message' => 'Você precisa do módulo Solicitar Cotação para criar cotações'
            ], 403);
        }

        try {
            $quoteData = [
                'shipper_id' => $loggedUser['id'],
                'type' => $data['type'],
                'title' => trim($data['title']),
                'origin_city' => $data['origin_city'] ?? null,
                'dest_city' => $data['dest_city'] ?? null,
                'commodity_type' => $data['commodity_type'] ?? null,
                'requires_insurance' => isset($data['requires_insurance']) ? (int)$data['requires_insurance'] : 1,
                'weight' => $data['weight'] ?? null,
                'cargo_value' => $data['cargo_value'] ?? null,
                'volume' => $data['volume'] ?? null,
                'period_days' => $data['period_days'] ?? null,
                'pickup_date' => $data['pickup_date'] ?? null,
                'description' => $data['description'] ?? null
            ];

            $quoteId = $this->quoteRepo->create($quoteData);

            return Response::json([
                'success' => true,
                'message' => 'Cotação criada com sucesso',
                'data' => ['id' => $quoteId]
            ], 201);
        } catch (Exception $e) {
            error_log("Erro ao criar cotação: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao criar cotação'], 500);
        }
    }

    public function update($data, $loggedUser, $id) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $quote = $this->quoteRepo->findById($id);
        if (!$quote) {
            return Response::json(['success' => false, 'message' => 'Cotação não encontrada'], 404);
        }

        if ($quote['shipper_id'] != $loggedUser['id']) {
            return Response::json(['success' => false, 'message' => 'Você não tem permissão'], 403);
        }

        if ($quote['status'] !== 'open') {
            return Response::json(['success' => false, 'message' => 'Não é possível editar cotação fechada'], 400);
        }

        try {
            $this->quoteRepo->update($id, $data);
            return Response::json(['success' => true, 'message' => 'Cotação atualizada']);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao atualizar'], 500);
        }
    }

    public function delete($data, $loggedUser, $id) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $quote = $this->quoteRepo->findById($id);
        if (!$quote) {
            return Response::json(['success' => false, 'message' => 'Cotação não encontrada'], 404);
        }

        if ($quote['shipper_id'] != $loggedUser['id']) {
            return Response::json(['success' => false, 'message' => 'Você não tem permissão'], 403);
        }

        if ($quote['status'] !== 'open') {
            return Response::json(['success' => false, 'message' => 'Não é possível excluir cotação fechada'], 400);
        }

        try {
            $this->quoteRepo->delete($id);
            return Response::json(['success' => true, 'message' => 'Cotação excluída']);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao excluir'], 500);
        }
    }

    public function getMyQuotes($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $status = $data['status'] ?? null;
        $quotes = $this->quoteRepo->getByShipper($loggedUser['id'], $status);

        return Response::json([
            'success' => true,
            'data' => $quotes
        ]);
    }

    public function getQuote($data, $loggedUser, $id) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $quote = $this->quoteRepo->findById($id);
        if (!$quote) {
            return Response::json(['success' => false, 'message' => 'Cotação não encontrada'], 404);
        }

        $responses = $this->quoteRepo->getResponsesByQuote($id);
        $quote['responses'] = $responses;

        return Response::json([
            'success' => true,
            'data' => $quote
        ]);
    }

    public function getOpenQuotes($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $hasModule = $this->userHasModule($loggedUser['id'], 'quotes', 'receive_quotes');
        if (!$hasModule) {
            return Response::json([
                'success' => false, 
                'message' => 'Você precisa do módulo Receber Cotações para ver as cotações disponíveis'
            ], 403);
        }

        $quotes = $this->quoteRepo->getOpenQuotes($loggedUser['id']);

        return Response::json([
            'success' => true,
            'data' => $quotes
        ]);
    }

    public function respond($data, $loggedUser, $quoteId) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        if (empty($data['price'])) {
            return Response::json(['success' => false, 'message' => 'Preço é obrigatório'], 400);
        }

        $quote = $this->quoteRepo->findById($quoteId);
        if (!$quote) {
            return Response::json(['success' => false, 'message' => 'Cotação não encontrada'], 404);
        }

        if ($quote['status'] !== 'open') {
            return Response::json(['success' => false, 'message' => 'Cotação já está fechada'], 400);
        }

        $hasModule = $this->userHasModule($loggedUser['id'], 'quotes', 'receive_quotes');
        if (!$hasModule) {
            return Response::json([
                'success' => false, 
                'message' => 'Você precisa do módulo Receber Cotações para responder'
            ], 403);
        }

        if ($this->quoteRepo->hasUserResponded($quoteId, $loggedUser['id'])) {
            return Response::json(['success' => false, 'message' => 'Você já respondeu esta cotação'], 400);
        }

        try {
            $responseId = $this->quoteRepo->addResponse([
                'quote_id' => $quoteId,
                'company_id' => $loggedUser['id'],
                'price' => $data['price'],
                'delivery_time' => $data['delivery_time'] ?? null,
                'conditions' => $data['conditions'] ?? null,
                'notes' => $data['notes'] ?? null
            ]);

            return Response::json([
                'success' => true,
                'message' => 'Resposta enviada com sucesso',
                'data' => ['id' => $responseId]
            ], 201);
        } catch (Exception $e) {
            error_log("Erro ao responder cotação: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao enviar resposta'], 500);
        }
    }

    public function acceptResponse($data, $loggedUser, $quoteId) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        if (empty($data['response_id'])) {
            return Response::json(['success' => false, 'message' => 'response_id obrigatório'], 400);
        }

        $quote = $this->quoteRepo->findById($quoteId);
        if (!$quote) {
            return Response::json(['success' => false, 'message' => 'Cotação não encontrada'], 404);
        }

        if ($quote['shipper_id'] != $loggedUser['id']) {
            return Response::json(['success' => false, 'message' => 'Você não tem permissão'], 403);
        }

        try {
            $this->quoteRepo->acceptResponse($data['response_id']);
            return Response::json(['success' => true, 'message' => 'Resposta aceita!']);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao aceitar resposta'], 500);
        }
    }

    public function getMyResponses($data, $loggedUser) {
        if (!$loggedUser || !isset($loggedUser['id'])) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $responses = $this->quoteRepo->getResponsesByCompany($loggedUser['id']);

        return Response::json([
            'success' => true,
            'data' => $responses
        ]);
    }

    // ==================== ADMIN METHODS ====================

    public function adminList($data, $loggedUser) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER', 'SUPPORT'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }

        $status = $data['status'] ?? null;
        $type = $data['type'] ?? null;
        $search = $data['search'] ?? null;

        $sql = "SELECT q.*, u.name as shipper_name, u.email as shipper_email,
                (SELECT COUNT(*) FROM quote_responses WHERE quote_id = q.id) as responses_count
                FROM quotes q
                LEFT JOIN users u ON q.shipper_id = u.id
                WHERE 1=1";
        
        $params = [];

        if ($status && $status !== 'all') {
            $sql .= " AND q.status = :status";
            $params[':status'] = $status;
        }

        if ($type && $type !== 'all') {
            $sql .= " AND q.type = :type";
            $params[':type'] = $type;
        }

        if ($search) {
            $sql .= " AND (q.title LIKE :search OR u.name LIKE :search OR u.email LIKE :search)";
            $params[':search'] = "%$search%";
        }

        $sql .= " ORDER BY q.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return Response::json([
            'success' => true,
            'data' => $quotes
        ]);
    }

    public function adminGetQuote($data, $loggedUser, $id) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER', 'SUPPORT'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }

        $quote = $this->quoteRepo->findById($id);
        if (!$quote) {
            return Response::json(['success' => false, 'message' => 'Cotação não encontrada'], 404);
        }

        $responses = $this->quoteRepo->getResponsesByQuote($id);
        $quote['responses'] = $responses;

        return Response::json([
            'success' => true,
            'data' => $quote
        ]);
    }

    public function adminUpdateQuote($data, $loggedUser, $id) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }

        $quote = $this->quoteRepo->findById($id);
        if (!$quote) {
            return Response::json(['success' => false, 'message' => 'Cotação não encontrada'], 404);
        }

        try {
            $this->quoteRepo->update($id, $data);
            return Response::json(['success' => true, 'message' => 'Cotação atualizada']);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao atualizar'], 500);
        }
    }

    public function adminDeleteQuote($data, $loggedUser, $id) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }

        $quote = $this->quoteRepo->findById($id);
        if (!$quote) {
            return Response::json(['success' => false, 'message' => 'Cotação não encontrada'], 404);
        }

        try {
            $this->quoteRepo->delete($id);
            return Response::json(['success' => true, 'message' => 'Cotação excluída']);
        } catch (Exception $e) {
            return Response::json(['success' => false, 'message' => 'Erro ao excluir'], 500);
        }
    }

    public function adminRespondQuote($data, $loggedUser, $quoteId) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }

        if (empty($data['company_id']) || empty($data['price'])) {
            return Response::json(['success' => false, 'message' => 'company_id e price são obrigatórios'], 400);
        }

        $quote = $this->quoteRepo->findById($quoteId);
        if (!$quote) {
            return Response::json(['success' => false, 'message' => 'Cotação não encontrada'], 404);
        }

        try {
            $responseId = $this->quoteRepo->addResponse([
                'quote_id' => $quoteId,
                'company_id' => $data['company_id'],
                'price' => $data['price'],
                'delivery_time' => $data['delivery_time'] ?? null,
                'conditions' => $data['conditions'] ?? null,
                'notes' => $data['notes'] ?? 'Resposta enviada pela administração'
            ]);

            return Response::json([
                'success' => true,
                'message' => 'Resposta enviada em nome da empresa',
                'data' => ['id' => $responseId]
            ], 201);
        } catch (Exception $e) {
            error_log("Erro admin responder cotação: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao enviar resposta'], 500);
        }
    }

    public function adminCreate($data, $loggedUser) {
        if (!$loggedUser || !in_array(strtoupper($loggedUser['role'] ?? ''), ['ADMIN', 'MANAGER'])) {
            return Response::json(['success' => false, 'message' => 'Acesso restrito'], 403);
        }

        $required = ['type', 'title'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return Response::json(['success' => false, 'message' => "Campo obrigatório: $field"], 400);
            }
        }

        $allowedTypes = ['frete', 'armazenagem', 'operacao_logistica', 'cross_docking'];
        if (!in_array($data['type'], $allowedTypes)) {
            return Response::json(['success' => false, 'message' => 'Tipo de cotação inválido'], 400);
        }

        // Se admin especificar user_id, cria em nome dele; senão usa o próprio admin
        $shipperId = !empty($data['user_id']) ? (int)$data['user_id'] : $loggedUser['id'];

        // Se não for criando para si mesmo, não precisa verificar módulo
        if ($shipperId != $loggedUser['id']) {
            // Admin criando para outro usuário - permite sem verificação de módulo
            $shipperId = (int)$data['user_id'];
        }

        try {
            $quoteData = [
                'shipper_id' => $shipperId,
                'type' => $data['type'],
                'title' => trim($data['title']),
                'origin_city' => $data['origin_city'] ?? null,
                'dest_city' => $data['dest_city'] ?? null,
                'commodity_type' => $data['commodity_type'] ?? null,
                'requires_insurance' => isset($data['requires_insurance']) ? (int)$data['requires_insurance'] : 1,
                'weight' => $data['weight'] ?? null,
                'cargo_value' => $data['cargo_value'] ?? null,
                'volume' => $data['volume'] ?? null,
                'period_days' => $data['period_days'] ?? null,
                'pickup_date' => $data['pickup_date'] ?? null,
                'description' => $data['description'] ?? null
            ];

            $quoteId = $this->quoteRepo->create($quoteData);

            return Response::json([
                'success' => true,
                'message' => 'Cotação criada com sucesso',
                'data' => ['id' => $quoteId]
            ], 201);
        } catch (Exception $e) {
            error_log("Erro admin criar cotação: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao criar cotação'], 500);
        }
    }
}
