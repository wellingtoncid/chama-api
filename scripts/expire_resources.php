<?php
/**
 * CRON: Expiração Automática de Recursos
 * Execute via CRON: php api/scripts/expire_resources.php
 * Recomendado: Executar a cada 5 minutos ou 1 vez ao dia
 * 
 * No CRON do servidor (cPanel/Linux):
 * Exemplo a cada 5 minutos:
 * 5 * * * * php /path/to/api/scripts/expire_resources.php
 * Ou diariamente:
 * 0 0 * * * php /path/to/api/scripts/expire_resources.php
 */

require_once __DIR__ . '/../src/Core/Database.php';

use App\Core\Database;

$db = Database::getConnection();

echo "=== EXPIRAÇÃO AUTOMÁTICA DE RECURSOS ===\n";
echo "Iniciado em: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // 1. Expirar fretes
    $stmt = $db->prepare("
        UPDATE freights 
        SET status = 'EXPIRED' 
        WHERE status = 'OPEN' 
        AND expires_at IS NOT NULL 
        AND expires_at < NOW()
    ");
    $stmt->execute();
    $freightsExpired = $stmt->rowCount();
    echo "Fretes expirados: $freightsExpired\n";

    // 2. Expirar listings (marketplace)
    $stmt = $db->prepare("
        UPDATE listings 
        SET status = 'expired' 
        WHERE status = 'active' 
        AND expires_at IS NOT NULL 
        AND expires_at < NOW()
    ");
    $stmt->execute();
    $listingsExpired = $stmt->rowCount();
    echo "Listings expirados: $listingsExpired\n";

    // 3. Expirar cotações
    $stmt = $db->prepare("
        UPDATE quotes 
        SET status = 'expired' 
        WHERE status = 'open' 
        AND expires_at IS NOT NULL 
        AND expires_at < NOW()
    ");
    $stmt->execute();
    $quotesExpired = $stmt->rowCount();
    echo "Cotações expiradas: $quotesExpired\n";

    // 4. Expirar anúncios publicitários
    $stmt = $db->prepare("
        UPDATE ads 
        SET status = 'expired' 
        WHERE status = 'active' 
        AND expires_at IS NOT NULL 
        AND expires_at < NOW()
    ");
    $stmt->execute();
    $adsExpired = $stmt->rowCount();
    echo "Anúncios expirados: $adsExpired\n";

    // 5. Atualizar destaque/urgente expirado para normal
    $stmt = $db->prepare("
        UPDATE freights 
        SET is_featured = 0, is_urgent = 0 
        WHERE status = 'OPEN' 
        AND expires_at IS NOT NULL 
        AND expires_at < NOW() 
        AND (is_featured = 1 OR is_urgent = 1)
    ");
    $stmt->execute();
    $freightsDowngraded = $stmt->rowCount();
    echo "Fretes rebaixados (destaque/urgente): $freightsDowngraded\n";

    echo "\n=== FINALIZADO ===\n";
    echo "Total recursos expirados: " . ($freightsExpired + $listingsExpired + $quotesExpired + $adsExpired) . "\n";
    echo "Finalizado em: " . date('Y-m-d H:i:s') . "\n";

} catch (Exception $e) {
    error_log("ERRO no CRON de expiração: " . $e->getMessage());
    echo "ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
