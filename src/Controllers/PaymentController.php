<?php
namespace App\Controllers;

use App\Services\MercadoPagoService;
use PDO;

class PaymentController {
    private $mpService;
    private $pdo;

    public function __construct($pdo, MercadoPagoService $mpService) {
        $this->pdo = $pdo;
        $this->mpService = $mpService;
    }

    /**
     * Ponto de entrada para criar qualquer pagamento (Plano ou Destaque)
     */
    public function checkout($data) {
        // Validação básica
        if (!isset($data['user_id']) || !isset($data['amount'])) {
            return ["success" => false, "error" => "Dados insuficientes para checkout"];
        }

        // Busca dados do usuário para o Mercado Pago
        $stmt = $this->pdo->prepare("SELECT name, email FROM users WHERE id = ?");
        $stmt->execute([$data['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) return ["success" => false, "error" => "Usuário não encontrado"];

        // Chama o Service para gerar a preferência
        // O $data pode conter plan_id, listing_id ou freight_id
        return $this->mpService->createPreference($data, [
            'id' => $data['user_id'],
            'name' => $user['name'],
            'email' => $user['email']
        ]);
    }

    /**
     * Endpoint que recebe o POST do Mercado Pago
     */
    public function webhook() {
        // Recebe o corpo da requisição (JSON)
        $json = file_get_contents('php://input');
        $params = json_decode($json, true);

        if (!$params) {
            // Caso o MP envie via query params (versões antigas ou testes)
            $params = $_GET;
        }

        // O Service processa a lógica de banco (ativar plano ou anúncio)
        $result = $this->mpService->handleNotification($params);

        if (isset($result['success']) && $result['success']) {
            return ["status" => "success", "message" => "Pagamento processado"];
        }

        return ["status" => "pending", "message" => "Aguardando aprovação ou erro"];
    }
}