<?php
use App\Controllers\NotificationController;
use App\Core\Database;
/**
 * Cron Job - Executar 1x por dia (ex: 09:00 AM)
 * Comando: php /caminho/do/seu/projeto/cron/check_expirations.php
 */

require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Services/NotificationService.php';

$db = Database::getConnection();
$notifier = new NotificationService($db);

// 0. EXPIRAR MÓDULOS VENCIDOS (expiração automática)
$expiredModules = $db->query("
    SELECT id, user_id, module_key, expires_at 
    FROM user_modules 
    WHERE status = 'active' 
    AND expires_at IS NOT NULL 
    AND expires_at < NOW()
")->fetchAll(PDO::FETCH_ASSOC);

if (!empty($expiredModules)) {
    $ids = array_column($expiredModules, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE user_modules SET status = 'expired' WHERE id IN ($placeholders)")->execute($ids);
    echo "Expirados " . count($expiredModules) . " módulos\n";
    
    foreach ($expiredModules as $mod) {
        $notifier->send(
            $mod['user_id'],
            "Módulo expirado: " . strtoupper($mod['module_key']),
            "Seu acesso ao módulo {$mod['module_key']} expirou em " . date('d/m/Y', strtotime($mod['expires_at'])) . ". Renove para continuar usando.",
            'system', 'high'
        );
    }
}

// 0b. EXPIRAR ANÚNCIOS VENCIDOS
$expiredAds = $db->query("
    SELECT id, user_id, title 
    FROM ads 
    WHERE status = 'active'
    AND expires_at IS NOT NULL 
    AND expires_at < NOW()
")->fetchAll(PDO::FETCH_ASSOC);

if (!empty($expiredAds)) {
    $ids = array_column($expiredAds, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE ads SET status = 'expired' WHERE id IN ($placeholders)")->execute($ids);
    echo "Expirados " . count($expiredAds) . " anúncios\n";
}

// 0c. EXPIRAR DESTAQUES DE FRETES
$expiredFreights = $db->query("
    SELECT id, user_id, product 
    FROM freights 
    WHERE is_featured = 1 
    AND featured_until IS NOT NULL 
    AND featured_until < NOW()
")->fetchAll(PDO::FETCH_ASSOC);

if (!empty($expiredFreights)) {
    $ids = array_column($expiredFreights, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE freights SET is_featured = 0, featured_until = NULL WHERE id IN ($placeholders)")->execute($ids);
    echo "Expirados " . count($expiredFreights) . " fretes destacados\n";
}

// 0d. EXPIRAR VERIFICAÇÃO DE MOTORISTAS
$expiredVerified = $db->query("
    SELECT id, name 
    FROM users 
    WHERE is_verified = 1 
    AND verified_until IS NOT NULL 
    AND verified_until < NOW()
")->fetchAll(PDO::FETCH_ASSOC);

if (!empty($expiredVerified)) {
    $ids = array_column($expiredVerified, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE users SET is_verified = 0, verified_until = NULL WHERE id IN ($placeholders)")->execute($ids);
    echo "Expirados " . count($expiredVerified) . " perfis verificados\n";
}

// 1. Notificar Banners (Ads) vencendo em 3 dias
$sqlAds = "SELECT a.id, a.user_id, a.title, DATEDIFF(a.expires_at, NOW()) as days 
           FROM ads a WHERE a.status = 'active' AND DATEDIFF(a.expires_at, NOW()) = 3";
$ads = $db->query($sqlAds)->fetchAll(PDO::FETCH_ASSOC);

foreach ($ads as $ad) {
    $notifier->send(
        $ad['user_id'],
        "Seu anúncio está vencendo!",
        "O banner '{$ad['title']}' expira em 3 dias. Renove agora para não perder sua posição.",
        'system', 'medium'
    );
}

// 2. Notificar Fretes Destacados vencendo em 2 dias
$sqlFreights = "SELECT id, user_id, product, DATEDIFF(featured_until, NOW()) as days 
                FROM freights WHERE is_featured = 1 AND DATEDIFF(featured_until, NOW()) = 2";
$freights = $db->query($sqlFreights)->fetchAll(PDO::FETCH_ASSOC);

foreach ($freights as $f) {
    $notifier->send(
        $f['user_id'],
        "Destaque de Frete expirando!",
        "O frete para '{$f['product']}' deixará de ser destaque em 48h. Renove para continuar no topo!",
        'system', 'medium'
    );
}

// 3. Notificar Motorista Verificado (Plano Pro) vencendo em 5 dias
$sqlUsers = "SELECT id, name, DATEDIFF(verified_until, NOW()) as days 
             FROM users WHERE is_verified = 1 AND verified_until IS NOT NULL AND DATEDIFF(verified_until, NOW()) = 5";
$users = $db->query($sqlUsers)->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $u) {
    $notifier->send(
        $u['id'],
        "Seu Selo Pro vai expirar",
        "Sua verificação premium vence em 5 dias. Mantenha seu perfil com credibilidade máxima renovando seu plano.",
        'system', 'medium'
    );
}

echo "Processamento de notificações concluído: " . date('Y-m-d H:i:s');