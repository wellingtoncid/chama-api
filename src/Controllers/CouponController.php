<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\CouponRepository;
use App\Services\CreditService;
use App\Services\NotificationService;
use PDO;

class CouponController
{
    private CouponRepository $repo;
    private CreditService $creditService;
    private NotificationService $notif;
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->repo = new CouponRepository($db);
        $this->creditService = new CreditService($db);
        $this->notif = new NotificationService($db);
    }

    /**
     * GET /api/admin/coupons — Listar cupons (admin)
     */
    public function listAll($data, $loggedUser): void
    {
        $user = Auth::requireAuth();
        if (!Auth::hasAnyRole(['admin', 'gerente', 'financeiro', 'supervisor'])) {
            Response::json(['success' => false, 'message' => 'Sem permissão'], 403);
            return;
        }
        $coupons = $this->repo->findAll();
        $result = [];
        foreach ($coupons as $c) {
            $c['uses_count'] = count($this->repo->getUses($c['id']));
            $result[] = $c;
        }
        Response::json(['success' => true, 'data' => $result]);
    }

    /**
     * POST /api/admin/coupons — Criar cupom (admin)
     */
    public function create($data, $loggedUser): void
    {
        $user = Auth::requireAuth();
        if (!Auth::hasAnyRole(['admin', 'gerente', 'financeiro'])) {
            Response::json(['success' => false, 'message' => 'Sem permissão'], 403);
            return;
        }

        $code = trim($data['code'] ?? '');
        $value = floatval($data['value'] ?? 0);

        if (empty($code)) {
            $code = $this->repo->generateCode();
        }
        if ($value <= 0) {
            Response::json(['success' => false, 'message' => 'Valor do cupom deve ser maior que zero'], 400);
            return;
        }
        if ($this->repo->findByCode($code)) {
            Response::json(['success' => false, 'message' => 'Código já existe'], 400);
            return;
        }

        $expiresAt = !empty($data['expires_at']) ? $data['expires_at'] : null;
        $maxUses = isset($data['max_uses']) && $data['max_uses'] !== '' ? (int)$data['max_uses'] : null;

        $id = $this->repo->create([
            'code' => strtoupper($code),
            'type' => 'fixed',
            'value' => $value,
            'max_uses' => $maxUses,
            'max_uses_per_user' => (int)($data['max_uses_per_user'] ?? 1),
            'expires_at' => $expiresAt,
            'is_active' => 1,
            'created_by' => $user['id'],
        ]);

        Response::json(['success' => true, 'data' => ['id' => $id, 'code' => strtoupper($code)]]);
    }

    /**
     * PUT /api/admin/coupons/:id — Atualizar cupom (admin)
     */
    public function update($data, $loggedUser): void
    {
        $user = Auth::requireAuth();
        if (!Auth::hasAnyRole(['admin', 'gerente', 'financeiro'])) {
            Response::json(['success' => false, 'message' => 'Sem permissão'], 403);
            return;
        }

        $id = (int)($data['id'] ?? 0);
        $existing = $this->repo->findById($id);
        if (!$existing) {
            Response::json(['success' => false, 'message' => 'Cupom não encontrado'], 404);
            return;
        }

        $updateData = [];
        if (isset($data['value'])) $updateData['value'] = floatval($data['value']);
        if (isset($data['max_uses'])) $updateData['max_uses'] = $data['max_uses'] !== '' ? (int)$data['max_uses'] : null;
        if (isset($data['max_uses_per_user'])) $updateData['max_uses_per_user'] = (int)$data['max_uses_per_user'];
        if (isset($data['expires_at'])) $updateData['expires_at'] = $data['expires_at'] ?: null;
        if (isset($data['is_active'])) $updateData['is_active'] = (int)$data['is_active'];
        if (isset($data['code'])) $updateData['code'] = strtoupper(trim($data['code']));

        $this->repo->update($id, $updateData);
        Response::json(['success' => true]);
    }

    /**
     * DELETE /api/admin/coupons/:id — Deletar cupom (admin)
     */
    public function delete($data, $loggedUser): void
    {
        $user = Auth::requireAuth();
        if (!Auth::hasAnyRole(['admin', 'gerente', 'financeiro'])) {
            Response::json(['success' => false, 'message' => 'Sem permissão'], 403);
            return;
        }

        $id = (int)($data['id'] ?? 0);
        $existing = $this->repo->findById($id);
        if (!$existing) {
            Response::json(['success' => false, 'message' => 'Cupom não encontrado'], 404);
            return;
        }

        $this->db->prepare('DELETE FROM coupons WHERE id = :id')->execute([':id' => $id]);
        Response::json(['success' => true]);
    }

    /**
     * POST /api/admin/coupons/generate-code — Gerar código aleatório (admin)
     */
    public function generateCode($data, $loggedUser): void
    {
        $user = Auth::requireAuth();
        if (!Auth::hasAnyRole(['admin', 'gerente', 'financeiro'])) {
            Response::json(['success' => false, 'message' => 'Sem permissão'], 403);
            return;
        }
        $code = $this->repo->generateCode((int)($data['length'] ?? 8));
        Response::json(['success' => true, 'data' => ['code' => $code]]);
    }

    /**
     * GET /api/admin/coupons/:id/uses — Histórico de usos (admin)
     */
    public function getUses($data, $loggedUser): void
    {
        $user = Auth::requireAuth();
        if (!Auth::hasAnyRole(['admin', 'gerente', 'financeiro'])) {
            Response::json(['success' => false, 'message' => 'Sem permissão'], 403);
            return;
        }

        $id = (int)($data['id'] ?? 0);
        $uses = $this->repo->getUses($id);
        Response::json(['success' => true, 'data' => $uses]);
    }

    /**
     * POST /api/coupons/redeem — Usuário resgata cupom
     */
    public function redeem($data, $loggedUser): void
    {
        $user = Auth::requireAuth();
        $userId = $user['id'];
        $code = strtoupper(trim($data['code'] ?? ''));

        if (empty($code)) {
            Response::json(['success' => false, 'message' => 'Informe o código do cupom'], 400);
            return;
        }

        $coupon = $this->repo->findByCode($code);
        if (!$coupon) {
            Response::json(['success' => false, 'message' => 'Cupom não encontrado'], 404);
            return;
        }

        // Validações
        if (!$coupon['is_active']) {
            Response::json(['success' => false, 'message' => 'Este cupom está inativo'], 400);
            return;
        }

        if ($coupon['expires_at'] && strtotime($coupon['expires_at']) < time()) {
            Response::json(['success' => false, 'message' => 'Este cupom expirou'], 400);
            return;
        }

        if ($coupon['max_uses'] !== null && $coupon['current_uses'] >= $coupon['max_uses']) {
            Response::json(['success' => false, 'message' => 'Este cupom já atingiu o limite de usos'], 400);
            return;
        }

        $userUses = $this->repo->countUserUses($coupon['id'], $userId);
        if ($userUses >= $coupon['max_uses_per_user']) {
            Response::json(['success' => false, 'message' => 'Você já utilizou este cupom'], 400);
            return;
        }

        // Aplica o crédito
        $amount = (float)$coupon['value'];

        try {
            $this->db->beginTransaction();

            $this->creditService->credit($userId, $amount, "Cupom: {$code}");

            // Pega a última transação criada
            $txStmt = $this->db->prepare('SELECT id FROM transactions WHERE user_id = :uid ORDER BY id DESC LIMIT 1');
            $txStmt->execute([':uid' => $userId]);
            $tx = $txStmt->fetch(\PDO::FETCH_ASSOC);
            $transactionId = $tx ? (int)$tx['id'] : null;

            $this->repo->recordUse($coupon['id'], $userId, $transactionId, $amount);
            $this->repo->incrementUses($coupon['id']);

            $this->db->commit();

            $newBalance = $this->creditService->getBalance($userId);

            $this->notif->notify($userId, 'Cupom Resgatado!', "Você ganhou R\$ " . number_format($amount, 2, ',', '.') . " de crédito na carteira.", '/dashboard/financeiro');

            Response::json([
                'success' => true,
                'data' => [
                    'amount' => $amount,
                    'new_balance' => $newBalance,
                    'message' => "Cupom resgatado! R\$ " . number_format($amount, 2, ',', '.') . " adicionados à sua carteira.",
                ]
            ]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('Erro ao resgatar cupom: ' . $e->getMessage());
            Response::json(['success' => false, 'message' => 'Erro ao resgatar cupom'], 500);
        }
    }
}
