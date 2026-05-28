<?php
/**
 * Migração: extrai vehicle_types, body_types, equipment_types, certification_types
 * do site_settings para a nova tabela lookup_lists.
 *
 * Uso: php scripts/migrate_lists_to_lookup_table.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbname = $_ENV['DB_NAME'] ?? 'chama_frete_dev';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$port = $_ENV['DB_PORT'] ?? '3306';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Erro de conexão: " . $e->getMessage() . "\n");
}

$listTypes = ['vehicle_types', 'body_types', 'equipment_types', 'certification_types'];

// Lê as listas do site_settings
$placeholders = implode(',', array_fill(0, count($listTypes), '?'));
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
$stmt->execute($listTypes);
$rows = $stmt->fetchAll();

$insertStmt = $pdo->prepare(
    'INSERT IGNORE INTO lookup_lists (list_type, value, label, description, sort_order, is_active)
     VALUES (?, ?, ?, ?, ?, 1)'
);

$totalInserted = 0;

foreach ($rows as $row) {
    $listType = $row['setting_key'];
    $decoded = json_decode($row['setting_value'], true);
    if (!is_array($decoded)) {
        echo "Ignorando $listType: não é um array JSON válido\n";
        continue;
    }

    foreach ($decoded as $i => $item) {
        if (!is_array($item)) {
            // Formato flat string: ["Econômico", "Completo", ...]
            $value = $item;
            $label = $item;
            $description = null;
        } elseif (isset($item['value'])) {
            // Formato {value, label}
            $value = $item['value'];
            $label = $item['label'] ?? $item['value'];
            $description = $item['description'] ?? null;
        } elseif (isset($item['label'])) {
            // Formato {label, desc} (certification_types)
            $value = $item['label'];
            $label = $item['label'];
            $description = $item['desc'] ?? null;
        } else {
            echo "Ignorando item $i de $listType: formato não reconhecido\n";
            continue;
        }

        $insertStmt->execute([$listType, $value, $label, $description, $i]);
        $totalInserted++;
    }
}

echo "Migração concluída! $totalInserted itens inseridos em lookup_lists.\n";
