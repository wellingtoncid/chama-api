<?php
/**
 * Cron Job - Executar 1x por dia (ex: 09:00 AM)
 * Comando: php /caminho/do/seu/projeto/cron/check_expirations.php
 */

require_once __DIR__ . '/../config/database.php'; // Ajuste conforme seu caminho
require_once __DIR__ . '/../controllers/NotificationController.php';

$db = (new Database())->connect();
$notifier = new NotificationController($db);

// 1. Notificar Banners (Ads) vencendo em 3 dias
$sqlAds = "SELECT a.id, a.user_id, a.title, DATEDIFF(a.expires_at, NOW()) as days 
           FROM ads a WHERE a.is_active = 1 AND DATEDIFF(a.expires_at, NOW()) = 3";
$ads = $db->query($sqlAds)->fetchAll(PDO::FETCH_ASSOC);

foreach ($ads as $ad) {
    $notifier->notify(
        $ad['user_id'],
        "Seu anúncio está vencendo!",
        "O banner '{$ad['title']}' expira em 3 dias. Renove agora para não perder sua posição."
    );
}

// 2. Notificar Fretes Destacados vencendo em 2 dias
$sqlFreights = "SELECT id, user_id, product, DATEDIFF(featured_until, NOW()) as days 
                FROM freights WHERE is_featured = 1 AND DATEDIFF(featured_until, NOW()) = 2";
$freights = $db->query($sqlFreights)->fetchAll(PDO::FETCH_ASSOC);

foreach ($freights as $f) {
    $notifier->notify(
        $f['user_id'],
        "Destaque de Frete expirando!",
        "O frete para '{$f['product']}' deixará de ser destaque em 48h. Renove para continuar no topo!"
    );
}

// 3. Notificar Motorista Verificado (Plano Pro) vencendo em 5 dias
$sqlUsers = "SELECT id, name, DATEDIFF(verified_until, NOW()) as days 
             FROM users WHERE is_verified = 1 AND verified_until IS NOT NULL AND DATEDIFF(verified_until, NOW()) = 5";
$users = $db->query($sqlUsers)->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $u) {
    $notifier->notify(
        $u['id'],
        "Seu Selo Pro vai expirar",
        "Sua verificação premium vence em 5 dias. Mantenha seu perfil com credibilidade máxima renovando seu plano."
    );
}

echo "Processamento de notificações concluído: " . date('Y-m-d H:i:s');