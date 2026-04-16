<?php
require_once __DIR__ . '/src/Core/Database.php';

$db = \App\Core\Database::getConnection();

$rules = [
    ['marketplace', 'sponsored', 'Patrocinado', 'per_use', 0, 9.90, 0.00, 7],
    ['freights', 'sponsored', 'Frete Patrocinado', 'per_use', 0, 9.90, 0.00, 7],
];

foreach ($rules as $rule) {
    list($moduleKey, $featureKey, $featureName, $pricingType, $freeLimit, $pricePerUse, $priceMonthly, $durationDays) = $rule;
    
    $stmt = $db->prepare("SELECT id FROM pricing_rules WHERE module_key = ? AND feature_key = ?");
    $stmt->execute([$moduleKey, $featureKey]);
    
    if ($stmt->fetch()) {
        echo "SKIP: $moduleKey.$featureKey\n";
        continue;
    }
    
    $stmt = $db->prepare("INSERT INTO pricing_rules (module_key, feature_key, feature_name, pricing_type, free_limit, price_per_use, price_monthly, price_daily, duration_days, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
    $stmt->execute([$moduleKey, $featureKey, $featureName, $pricingType, $freeLimit, $pricePerUse, $priceMonthly, 0, $durationDays]);
    echo "INSERT: $moduleKey.$featureKey\n";
}

echo "\n=== MARKETPLACE ===\n";
$all = $db->query("SELECT feature_key, feature_name, price_per_use, duration_days FROM pricing_rules WHERE module_key = 'marketplace' ORDER BY feature_key")->fetchAll(PDO::FETCH_ASSOC);
foreach ($all as $r) echo "{$r['feature_key']} - R\$ {$r['price_per_use']} ({$r['duration_days']} dias)\n";

echo "\n=== FREIGHTS ===\n";
$all = $db->query("SELECT feature_key, feature_name, price_per_use, duration_days FROM pricing_rules WHERE module_key = 'freights' ORDER BY feature_key")->fetchAll(PDO::FETCH_ASSOC);
foreach ($all as $r) echo "{$r['feature_key']} - R\$ {$r['price_per_use']} ({$r['duration_days']} dias)\n";