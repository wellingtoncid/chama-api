<?php
require_once __DIR__ . '/src/Core/Database.php';

$db = \App\Core\Database::getConnection();

echo "=== VERIFICANDO REGRAS ADVERTISER ===\n\n";

// Primeiro ver todas as regras atuais
$all = $db->query("SELECT id, feature_key, feature_name, price_monthly, price_per_use, pricing_type FROM pricing_rules WHERE module_key = 'advertiser' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

echo "=== ANTES ===\n";
foreach ($all as $r) {
    echo "ID {$r['id']}: {$r['feature_key']} - {$r['feature_name']} - monthly: R\$ {$r['price_monthly']}, per_use: R\$ {$r['price_per_use']}\n";
}

// Manter apenas as regras originais (IDs 52-57 e 89-97)
// As outras foram criadas duplicadas ou com problemas
$keepIds = [52, 53, 54, 55, 56, 57, 89, 90, 91, 92, 93, 94, 95, 96, 97];

// Remove as que não estão na lista de keepers
$placeholders = implode(',', array_fill(0, count($keepIds), '?'));
$stmt = $db->prepare("DELETE FROM pricing_rules WHERE module_key = 'advertiser' AND id NOT IN ($placeholders)");
$stmt->execute($keepIds);

echo "\n=== DEPOIS (regras mantidas) ===\n";
$remaining = $db->query("SELECT feature_key, feature_name, price_monthly, price_per_use FROM pricing_rules WHERE module_key = 'advertiser' ORDER BY price_monthly DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($remaining as $r) {
    echo "{$r['feature_key']}: {$r['feature_name']} - R\$ {$r['price_monthly']}\n";
}

echo "\nPronto! Regras duplicadas removidas.\n";