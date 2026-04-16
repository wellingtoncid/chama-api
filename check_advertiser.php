<?php
require_once __DIR__ . '/src/Core/Database.php';

$db = \App\Core\Database::getConnection();

echo "=== ADVERTISER RULES ===\n";
$all = $db->query("SELECT feature_key, feature_name, price_monthly FROM pricing_rules WHERE module_key = 'advertiser' ORDER BY price_monthly DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($all as $r) {
    echo "{$r['feature_key']} - {$r['feature_name']} - R\$ {$r['price_monthly']}\n";
}