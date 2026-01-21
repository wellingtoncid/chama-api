<?php

// 1. Recebe o ID do pagamento enviado pelo Mercado Pago
$json_event = file_get_contents('php://input');
$event = json_decode($json_event, true);

if (isset($event['type']) && $event['type'] == 'payment') {
    $paymentId = $event['data']['id'];

    // 2. Consulta o status real no Mercado Pago via CURL usando seu ACCESS_TOKEN
    // Isso evita que alguém simule um aviso de pagamento falso
    $ch = curl_init("https://api.mercadopago.com/v1/payments/" . $paymentId);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . YOUR_MP_ACCESS_TOKEN]);
    $payment_info = json_decode(curl_exec($ch), true);
    curl_close($ch);

    // 3. Se o status for 'approved', atualiza o usuário
    if ($payment_info['status'] === 'approved') {
        $external_reference = $payment_info['external_reference']; // Aqui deve estar o ID do Usuário
        $plan_id = $payment_info['metadata']['plan_id']; // ID do plano contratado

        // ATUALIZAÇÃO NO BANCO
        $stmt = $pdo->prepare("UPDATE users SET is_subscriber = 1, plan_id = ? WHERE id = ?");
        $stmt->execute([$plan_id, $external_reference]);
        
        // Opcional: Registrar a transação na sua tabela de logs
    }
}

http_response_code(200); // MP exige resposta 200