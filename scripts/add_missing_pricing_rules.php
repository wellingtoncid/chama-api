<?php
/**
 * Script para adicionar regras de pricing que faltam
 * Execute: php add_missing_pricing_rules.php
 */

require_once __DIR__ . '/../src/Core/Database.php';

use App\Core\Database;

$db = Database::getConnection();

$rules = [
    // Marketplace - Patrocinado
    ['marketplace', 'sponsored', 'Patrocinado', 'per_use', 0, 9.90, 0.00, 7],
    
    // Freights - Renew (prorrogar)
    ['freights', 'renew', 'Prorrogar Frete', 'per_use', 0, 9.90, 0.00, 7],
    ['freights', 'sponsored', 'Frete Patrocinado', 'per_use', 0, 9.90, 0.00, 7],
];

$inserted = 0;
$skipped = 0;

foreach ($rules as $rule) {
    list($moduleKey, $featureKey, $featureName, $pricingType, $freeLimit, $pricePerUse, $priceMonthly, $durationDays) = $rule;
    
    // Verifica se já existe
    $stmt = $db->prepare("SELECT id FROM pricing_rules WHERE module_key = ? AND feature_key = ?");
    $stmt->execute([$moduleKey, $featureKey]);
    
    if ($stmt->fetch()) {
        echo "SKIP: $moduleKey.$featureKey já existe\n";
        $skipped++;
        continue;
    }
    
    $stmt = $db->prepare("
        INSERT INTO pricing_rules 
        (module_key, feature_key, feature_name, pricing_type, free_limit, price_per_use, price_monthly, price_daily, duration_days, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
    ");
    
    $stmt->execute([$moduleKey, $featureKey, $featureName, $pricingType, $freeLimit, $pricePerUse, $priceMonthly, 0, $durationDays]);
    echo "INSERT: $moduleKey.$featureKey\n";
    $inserted++;
}

echo "\n=== RESULTADO ===\n";
echo "Inseridos: $inserted\n";
echo "Ignorados: $skipped\n";

// Lista marketplace rules
echo "\n=== MARKETPLACE RULES ===\n";
$all = $db->query("SELECT feature_key, feature_name, pricing_type, price_per_use, duration_days FROM pricing_rules WHERE module_key = 'marketplace' ORDER BY feature_key")->fetchAll(PDO::FETCH_ASSOC);
foreach ($all as $r) {
    echo sprintf("%-20s %-30s %-10s R$ %.2f/dur: %d\n", 
        $r['feature_key'], 
        $r['feature_name'], 
        $r['pricing_type'],
        $r['price_per_use'],
        $r['duration_days']
    );
}

// Lista freights rules
echo "\n=== FREIGHTS RULES ===\n";
$all = $db->query("SELECT feature_key, feature_name, pricing_type, price_per_use, duration_days FROM pricing_rules WHERE module_key = 'freights' ORDER BY feature_key")->fetchAll(PDO::FETCH_ASSOC);
foreach ($all as $r) {
    echo sprintf("%-20s %-30s %-10s R$ %.2f/dur: %d\n", 
        $r['feature_key'], 
        $r['feature_name'], 
        $r['pricing_type'],
        $r['price_per_use'],
        $r['duration_days']
    );
}