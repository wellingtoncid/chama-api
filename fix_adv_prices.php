<?php
require_once __DIR__ . '/src/Core/Database.php';

$db = \App\Core\Database::getConnection();

echo "=== VERIFICANDO PREÇOS monthlies ===\n\n";

$all = $db->query("SELECT feature_key, feature_name, price_monthly, price_per_use, pricing_type, is_active FROM pricing_rules WHERE module_key = 'advertiser' ORDER BY price_monthly DESC")->fetchAll(PDO::FETCH_ASSOC);

$problems = [];
foreach ($all as $r) {
    $price = $r['price_monthly'];
    $perUse = $r['price_per_use'];
    $type = $r['pricing_type'];
    
    if ($price <= 0 && $perUse <= 0) {
        $problems[] = $r['feature_key'];
        echo "PROBLEMA: {$r['feature_key']} - {$r['feature_name']} - monthly: $price, per_use: $perUse\n";
    } else {
        $priceShow = $price > 0 ? 'R$ ' . number_format($price, 2, ',', '.') : 'R$ 0';
        echo "OK: {$r['feature_key']} - {$r['feature_name']} - $priceShow\n";
    }
}

echo "\n=== PROBLEMAS ENCONTRADOS: " . count($problems) . " ===\n";
if (count($problems) > 0) {
    echo "Keys com preço zerado: " . implode(', ', $problems) . "\n";
    
    // Corrige
    echo "\n=== CORRIGINDO ===\n";
    foreach ($problems as $key) {
        // Define preços padrão baseado na posição
        $defaultPrices = [
            'sidebar' => 79.90,
            'sidebar_banner' => 99.00,
            'header' => 149.90,
            'header_banner' => 149.00,
            'footer' => 79.90,
            'footer_banner' => 79.00,
            'home_banner' => 199.00,
            'spotlight' => 249.90,
            'spotlight_banner' => 149.00,
            'infeed' => 129.90,
            'interstitial' => 129.00,
            'return_banner' => 99.00,
            'details' => 89.90,
            'sponsor_section' => 79.00,
            'floating_button' => 49.00,
            'video_ad' => 299.90,
        ];
        
        $price = $defaultPrices[$key] ?? 79.90;
        
        $stmt = $db->prepare("UPDATE pricing_rules SET price_monthly = ? WHERE module_key = 'advertiser' AND feature_key = ?");
        $stmt->execute([$price, $key]);
        echo "UPDATE $key -> R$ $price\n";
    }
    
    echo "\n=== VERIFICANDO NOVAMENTE ===\n";
    $all = $db->query("SELECT feature_key, price_monthly FROM pricing_rules WHERE module_key = 'advertiser' ORDER BY price_monthly DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all as $r) {
        echo "{$r['feature_key']}: R\$ {$r['price_monthly']}\n";
    }
}