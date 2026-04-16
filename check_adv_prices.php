<?php
require_once __DIR__ . '/src/Core/Database.php';

$db = \App\Core\Database::getConnection();

echo "=== ADVERTISER RULES WITH PRICES ===\n";
$all = $db->query("SELECT feature_key, feature_name, price_monthly, is_active FROM pricing_rules WHERE module_key = 'advertiser' ORDER BY price_monthly DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($all as $r) {
    $price = $r['price_monthly'] > 0 ? 'R$ ' . number_format($r['price_monthly'], 2, ',', '.') : 'GRÁTIS';
    echo "{$r['feature_key']}: {$r['feature_name']} - {$price}\n";
}