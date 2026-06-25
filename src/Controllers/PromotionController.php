<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\PromotionQueueRepository;
use App\Services\CreditService;
use App\Services\MercadoPagoService;
use App\Services\OpenWAService;
use PDO;

class PromotionController
{
    private PDO $db;
    private PromotionQueueRepository $promotionRepo;
    private CreditService $creditService;
    private MercadoPagoService $mpService;
    private ?OpenWAService $openwa = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->promotionRepo = new PromotionQueueRepository($db);
        $this->creditService = new CreditService($db);
        $this->mpService = new MercadoPagoService($db);
    }

    private function getOpenWA(): OpenWAService
    {
        if ($this->openwa === null) {
            $this->openwa = new OpenWAService();
        }
        return $this->openwa;
    }

    public function index($data, $loggedUser): array
    {
        $userId = $loggedUser['id'] ?? null;
        if (!$userId) {
            return Response::json(['success' => false, 'message' => 'Não autorizado'], 401);
        }

        $role = strtoupper($loggedUser['role'] ?? '');

        if (in_array($role, ['ADMIN', 'GERENTE', 'SUPORTE', 'MARKETING', 'VENDAS', 'COORDENADOR', 'SUPERVISOR'])) {
            $filters = [];
            if (!empty($data['status'])) {
                $filters['status'] = $data['status'];
            }
            $promotions = $this->promotionRepo->findAll($filters);
        } else {
            $filters = ['user_id' => $userId];
            if (!empty($data['status'])) {
                $filters['status'] = $data['status'];
            }
            $promotions = $this->promotionRepo->findByUser($userId, $filters);
        }

        return Response::json(['success' => true, 'data' => $promotions]);
    }

    public function store($data, $loggedUser): array
    {
        $userId = $loggedUser['id'] ?? null;
        if (!$userId) {
            return Response::json(['success' => false, 'message' => 'Não autorizado'], 401);
        }

        $referenceType = $data['reference_type'] ?? '';
        $referenceId = (int) ($data['reference_id'] ?? 0);

        if (!in_array($referenceType, ['freight', 'listing'])) {
            return Response::json(['success' => false, 'message' => 'Tipo de referência inválido'], 400);
        }

        if ($referenceId <= 0) {
            return Response::json(['success' => false, 'message' => 'Referência inválida'], 400);
        }

        $existing = $this->promotionRepo->countByUserAndRef($userId, $referenceType, $referenceId);
        if ($existing > 0) {
            return Response::json(['success' => false, 'message' => 'Esta publicação já foi divulgada'], 400);
        }

        $price = $this->getPromotionPrice();
        if ($price <= 0) {
            return Response::json(['success' => false, 'message' => 'Preço não configurado para esta feature'], 400);
        }

        $promotionId = $this->promotionRepo->create([
            'user_id' => $userId,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'amount_paid' => $price,
            'status' => 'pending_payment',
        ]);

        if (!$promotionId) {
            return Response::json(['success' => false, 'message' => 'Erro ao criar promoção'], 500);
        }

        $balance = $this->creditService->getBalance($userId);
        if ($balance >= $price) {
            $debited = $this->creditService->debit($userId, $price, 'promotions', 'whatsapp_promotion');
            if ($debited) {
                $this->promotionRepo->updateStatus($promotionId, 'pending_approval');
                return Response::json([
                    'success' => true,
                    'data' => [
                        'id' => $promotionId,
                        'status' => 'pending_approval',
                        'payment_method' => 'wallet',
                        'amount' => $price,
                        'message' => 'Promoção criada com sucesso! Aguarde aprovação.',
                    ],
                ], 201);
            }
        }

        $result = $this->mpService->createPreference([
            'title' => 'Divulgação em Grupos WhatsApp - Chama Frete',
            'amount' => $price,
            'plan_id' => null,
            'module_key' => 'promotions',
            'feature_key' => 'whatsapp_promotion',
        ], $userId);

        return Response::json([
            'success' => true,
            'data' => [
                'id' => $promotionId,
                'status' => 'pending_payment',
                'payment_method' => 'mercadopago',
                'url' => $result['init_point'] ?? null,
                'transaction_id' => $result['transaction_id'] ?? null,
                'amount' => $price,
                'wallet_balance' => $balance,
                'message' => 'Pagamento necessário para divulgar nos grupos.',
            ],
        ], 201);
    }

    public function confirmPayment($data, $loggedUser): array
    {
        $promotionId = (int) ($data['id'] ?? 0);
        if ($promotionId <= 0) {
            return Response::json(['success' => false, 'message' => 'ID inválido'], 400);
        }

        $promotion = $this->promotionRepo->findById($promotionId);
        if (!$promotion) {
            return Response::json(['success' => false, 'message' => 'Promoção não encontrada'], 404);
        }

        if ($promotion['status'] !== 'pending_payment') {
            return Response::json(['success' => false, 'message' => 'Status inválido para confirmação'], 400);
        }

        $this->promotionRepo->updateStatus($promotionId, 'pending_approval');

        return Response::json([
            'success' => true,
            'message' => 'Pagamento confirmado! Aguarde aprovação.',
        ]);
    }

    public function approve($data, $loggedUser): array
    {
        $userId = $loggedUser['id'] ?? null;
        if (!$userId) {
            return Response::json(['success' => false, 'message' => 'Não autorizado'], 401);
        }

        $role = strtoupper($loggedUser['role'] ?? '');
        if (!in_array($role, ['ADMIN', 'GERENTE', 'SUPORTE', 'MARKETING', 'COORDENADOR', 'SUPERVISOR'])) {
            return Response::json(['success' => false, 'message' => 'Permissão negada'], 403);
        }

        $promotionId = (int) ($data['id'] ?? 0);
        if ($promotionId <= 0) {
            return Response::json(['success' => false, 'message' => 'ID inválido'], 400);
        }

        $promotion = $this->promotionRepo->findById($promotionId);
        if (!$promotion) {
            return Response::json(['success' => false, 'message' => 'Promoção não encontrada'], 404);
        }

        if (!in_array($promotion['status'], ['pending_approval', 'approved'])) {
            return Response::json(['success' => false, 'message' => 'Promoção não está aguardando aprovação'], 400);
        }

        $this->promotionRepo->updateStatus($promotionId, 'approved', ['approved_by' => $userId]);

        $result = $this->sendToGroups($promotion);

        return Response::json([
            'success' => true,
            'message' => 'Promoção aprovada e enviada para os grupos!',
            'data' => $result,
        ]);
    }

    public function reject($data, $loggedUser): array
    {
        $userId = $loggedUser['id'] ?? null;
        if (!$userId) {
            return Response::json(['success' => false, 'message' => 'Não autorizado'], 401);
        }

        $role = strtoupper($loggedUser['role'] ?? '');
        if (!in_array($role, ['ADMIN', 'GERENTE', 'SUPORTE', 'MARKETING', 'COORDENADOR', 'SUPERVISOR'])) {
            return Response::json(['success' => false, 'message' => 'Permissão negada'], 403);
        }

        $promotionId = (int) ($data['id'] ?? 0);
        if ($promotionId <= 0) {
            return Response::json(['success' => false, 'message' => 'ID inválido'], 400);
        }

        $reason = trim($data['reason'] ?? '');
        if (empty($reason)) {
            return Response::json(['success' => false, 'message' => 'Informe o motivo da rejeição'], 400);
        }

        $promotion = $this->promotionRepo->findById($promotionId);
        if (!$promotion) {
            return Response::json(['success' => false, 'message' => 'Promoção não encontrada'], 404);
        }

        if (!in_array($promotion['status'], ['pending_approval', 'approved'])) {
            return Response::json(['success' => false, 'message' => 'Promoção não está aguardando aprovação'], 400);
        }

        $this->promotionRepo->updateStatus($promotionId, 'rejected', ['rejection_reason' => $reason]);

        $this->creditService->credit($promotion['user_id'], $promotion['amount_paid'], 'promotions', 'refund_rejected');

        return Response::json([
            'success' => true,
            'message' => 'Promoção rejeitada. Valor estornado para a carteira do usuário.',
        ]);
    }

    public function stats($data, $loggedUser): array
    {
        $role = strtoupper($loggedUser['role'] ?? '');
        if (!in_array($role, ['ADMIN', 'GERENTE', 'SUPORTE', 'MARKETING', 'COORDENADOR', 'SUPERVISOR'])) {
            return Response::json(['success' => false, 'message' => 'Permissão negada'], 403);
        }

        $stats = $this->promotionRepo->getStats();
        return Response::json(['success' => true, 'data' => $stats]);
    }

    public function syncGroups($data, $loggedUser): array
    {
        $role = strtoupper($loggedUser['role'] ?? '');
        if (!in_array($role, ['ADMIN', 'GERENTE', 'MARKETING'])) {
            return Response::json(['success' => false, 'message' => 'Permissão negada'], 403);
        }

        try {
            $openwa = $this->getOpenWA();
            $groups = $openwa->listGroups();

            if ($groups === null) {
                return Response::json(['success' => false, 'message' => 'Erro ao conectar com OpenWA. Verifique se o serviço está rodando.'], 502);
            }

            $synced = 0;
            $groupList = $groups['data'] ?? $groups['groups'] ?? $groups;

            if (is_array($groupList)) {
                foreach ($groupList as $g) {
                    $gid = $g['id'] ?? $g['jid'] ?? $g['group_id'] ?? null;
                    $gname = $g['name'] ?? $g['group_name'] ?? $g['subject'] ?? '';
                    if ($gid) {
                        $stmt = $this->db->prepare("SELECT id FROM whatsapp_groups WHERE openwa_group_id = :gid AND is_deleted = 0");
                        $stmt->execute([':gid' => $gid]);
                        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

                        if (!$existing) {
                            $stmt = $this->db->prepare("INSERT INTO whatsapp_groups (openwa_group_id, region_name, status, display_location, is_visible_home, target_role, member_count) VALUES (:gid, :name, 'active', 'platform', 0, 'ALL', 0)");
                            $stmt->execute([':gid' => $gid, ':name' => $gname]);
                        }
                        $synced++;
                    }
                }
            }

            return Response::json([
                'success' => true,
                'data' => ['synced' => $synced],
                'message' => "Sincronização concluída. {$synced} grupos processados.",
            ]);
        } catch (\Throwable $e) {
            error_log('Erro syncGroups: ' . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Erro ao sincronizar grupos'], 500);
        }
    }

    public function sessionStatus($data, $loggedUser): array
    {
        $role = strtoupper($loggedUser['role'] ?? '');
        if (!in_array($role, ['ADMIN', 'GERENTE', 'MARKETING'])) {
            return Response::json(['success' => false, 'message' => 'Permissão negada'], 403);
        }

        $openwa = $this->getOpenWA();
        $status = $openwa->sessionStatus();
        $qrCode = null;

        $s = $status['status'] ?? '';
        if (!$status || ($s !== 'connected' && $s !== 'ready')) {
            $qrCode = $openwa->getQrCode();
        }

        return Response::json([
            'success' => true,
            'data' => [
                'connected' => $openwa->isConnected(),
                'status' => $status,
                'qrCode' => $qrCode,
            ],
        ]);
    }

    private function getPromotionPrice(): float
    {
        $stmt = $this->db->prepare("SELECT price_per_use FROM pricing_rules WHERE module_key = 'promotions' AND feature_key = 'whatsapp_promotion' AND is_active = 1 LIMIT 1");
        $stmt->execute();
        $rule = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $rule ? (float) $rule['price_per_use'] : 14.90;
    }

    private function sendToGroups(array $promotion): array
    {
        $openwa = $this->getOpenWA();
        if (!$openwa->isConnected()) {
            $this->promotionRepo->updateStatus((int) $promotion['id'], 'failed', ['error_message' => 'OpenWA não conectado']);
            return ['sent' => 0, 'total' => 0, 'error' => 'OpenWA não conectado'];
        }

        $groups = $this->promotionRepo->getActiveGroupsWithOpenwa();
        $total = count($groups);
        $sent = 0;

        $text = $this->buildPromotionMessage($promotion);
        $variations = $this->buildMessageVariations($promotion);

        foreach ($groups as $i => $group) {
            $message = $variations[$i % count($variations)] ?? $text;

            $success = $openwa->sendWithDelay($group['openwa_group_id'], $message, ($i > 0) ? 4000 : 0);
            if ($success) {
                $sent++;
            }

            if ($i >= 9) {
                break;
            }
        }

        $finalStatus = $sent > 0 ? 'sent' : 'failed';
        $errorMsg = $sent === 0 ? 'Nenhuma mensagem enviada' : null;
        $this->promotionRepo->updateStatus((int) $promotion['id'], $finalStatus, [
            'groups_sent' => $sent,
            'total_groups' => $total,
            'error_message' => $errorMsg,
        ]);

        return ['sent' => $sent, 'total' => $total];
    }

    private function buildPromotionMessage(array $promotion): string
    {
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? getenv('FRONTEND_URL') ?: 'https://chamafrete.com.br';

        if ($promotion['reference_type'] === 'freight') {
            $stmt = $this->db->prepare("SELECT f.product as title, u.name as user_name, up.slug FROM freights f JOIN users u ON f.user_id = u.id LEFT JOIN user_profiles up ON u.id = up.user_id WHERE f.id = :id AND f.is_deleted = 0");
        } else {
            $stmt = $this->db->prepare("SELECT l.title, u.name as user_name, up.slug FROM listings l JOIN users u ON l.user_id = u.id LEFT JOIN user_profiles up ON u.id = up.user_id WHERE l.id = :id");
        }
        $stmt->execute([':id' => $promotion['reference_id']]);
        $item = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$item) {
            return "Confira esta oportunidade no Chama Frete!";
        }

        $slug = $item['slug'] ?? '';
        $title = $item['title'] ?? 'Oportunidade';
        $userName = $item['user_name'] ?? 'Usuário';

        $url = $promotion['reference_type'] === 'freight'
            ? "{$frontendUrl}/frete/{$slug}"
            : "{$frontendUrl}/anuncio/{$slug}";

        return "🚛 *Chama Frete*\n\n{$userName} está oferecendo: *{$title}*\n\nConfira aqui: {$url}\n\n#ChamaFrete #Frete #Carga";
    }

    private function buildMessageVariations(array $promotion): array
    {
        $base = $this->buildPromotionMessage($promotion);
        $variations = [$base];

        $variations[] = str_replace('Confira aqui', 'Acesse agora', $base);
        $variations[] = str_replace('#ChamaFrete #Frete #Carga', '#ChamaFrete #Oportunidade #Carga', $base);
        $variations[] = str_replace('está oferecendo', 'publicou', $base);

        return $variations;
    }
}
