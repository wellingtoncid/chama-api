<?php
/**
 * Migration: Convert list settings from flat strings to {value, label} objects
 * Run: php scripts/migrate_lists_v4_value_label.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = is_file(__DIR__ . '/../.env.production') ? __DIR__ . '/../.env.production' : __DIR__ . '/../.env';
if (is_file($envFile)) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname($envFile));
    $dotenv->load();
}

$db = App\Core\Database::getConnection();

// Source data from freightOptions.ts (VEHICLE_TYPES with value/label)
$vehicleTypes = [
    ["value" => "Motocicleta", "label" => "Motocicleta (até 50kg)"],
    ["value" => "Triciclo", "label" => "Triciclo de carga (150kg a 500kg)"],
    ["value" => "Carro de passeio", "label" => "Carro de passeio (300kg a 500kg)"],
    ["value" => "Pick-up leve", "label" => "Pick-up leve (500kg a 750kg)"],
    ["value" => "Furgão leve", "label" => "Furgão leve (600kg a 1.500kg)"],
    ["value" => "Van / Furgão médio", "label" => "Van / Furgão médio (1.200kg a 2.000kg)"],
    ["value" => "VUC", "label" => "VUC (3.000kg a 4.000kg)"],
    ["value" => "Caminhão 3/4", "label" => "Caminhão 3/4 (4.000kg a 5.000kg)"],
    ["value" => "Toco", "label" => "Caminhão Toco - 2 eixos (6t a 8t)"],
    ["value" => "Truck", "label" => "Caminhão Truck - 3 eixos (10t a 14t)"],
    ["value" => "Bitruck", "label" => "Bitruck - 4 eixos (15t a 18t)"],
    ["value" => "Carreta LS", "label" => "Carreta Simples/LS (25t a 28t)"],
    ["value" => "Romeu e Julieta", "label" => "Romeu e Julieta (30t a 33t)"],
    ["value" => "Bitrem", "label" => "Bitrem - 7 eixos (36t a 40t)"],
    ["value" => "Rodotrem", "label" => "Rodotrem - 9 eixos (48t a 55t)"],
    ["value" => "Tritrem", "label" => "Tritrem (acima de 50t)"],
    ["value" => "CVE", "label" => "CVE - Especial (acima de 100t)"],
];

$bodyTypes = ["Baú", "Baú Frigorifico", "Sider", "Grade Baixa", "Graneleiro", "Prancha", "Porta Container", "Caçamba", "Tanque", "Cegonha"];
$equipmentTypes = ["Plataforma Elevatória", "Rastreador GPS", "Guincho", "Dolly", "Munck", "Empilhadeira", "Cegonha", "Hidrante"];
$certificationTypes = ["MOPP", "Carga Indivisível", "Coletivo", "Escolar", "Emergência", "Motofrete"];

function toValueLabel(array $strings): array {
    return array_map(fn($s) => ["value" => trim($s), "label" => trim($s)], $strings);
}

$updates = [
    'vehicle_types' => json_encode($vehicleTypes, JSON_UNESCAPED_UNICODE),
    'body_types' => json_encode(toValueLabel($bodyTypes), JSON_UNESCAPED_UNICODE),
    'equipment_types' => json_encode(toValueLabel($equipmentTypes), JSON_UNESCAPED_UNICODE),
    'certification_types' => json_encode(toValueLabel($certificationTypes), JSON_UNESCAPED_UNICODE),
];

echo "=== Executando migração value+label ===\n\n";

$stmt = $db->prepare("UPDATE site_settings SET setting_value = :value WHERE setting_key = :key");
foreach ($updates as $key => $value) {
    $stmt->execute([':value' => $value, ':key' => $key]);
    $decoded = json_decode($value, true);
    echo "$key: " . count($decoded) . " items em formato {value, label}\n";
}

echo "\n=== Verificação pós-migração ===\n";
$check = $db->query("SELECT setting_key, setting_value FROM site_settings WHERE category = 'lists' ORDER BY setting_key");
while ($row = $check->fetch(PDO::FETCH_ASSOC)) {
    $decoded = json_decode($row['setting_value'], true);
    $first = is_array($decoded) && count($decoded) > 0 ? $decoded[0] : 'EMPTY';
    $type = is_array($decoded) ? (is_string($first) ? 'flat strings' : 'objects') : 'NOT ARRAY';
    echo "{$row['setting_key']}: $type (" . count($decoded) . " items)\n";
    if (is_array($first)) {
        echo "  sample: " . ($first['value'] ?? '?') . " → " . ($first['label'] ?? '?') . "\n";
    }
}

echo "\n✓ Migração concluída!\n";
