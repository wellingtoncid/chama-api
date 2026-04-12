<?php
/**
 * Script de migração para adicionar colunas na credit_transactions
 * Executar: php api/scripts/migrate_wallet.php
 */

require_once __DIR__ . '/../src/Core/Database.php';

use App\Core\Database;

$db = Database::getConnection();

echo "=== MIGRATE WALLET TABLES ===\n\n";

try {
    // 1. Adicionar colunas em credit_transactions se não existirem
    $columnsToAdd = [
        'module_key VARCHAR(50) DEFAULT NULL',
        'feature_key VARCHAR(50) DEFAULT NULL',
        'reference_id INT DEFAULT NULL'
    ];

    foreach ($columnsToAdd as $column) {
        $columnName = explode(' ', $column)[0];
        
        $stmt = $db->prepare("
            SELECT COUNT(*) as exists_col 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'credit_transactions' 
            AND COLUMN_NAME = :col
        ");
        $stmt->execute([':col' => $columnName]);
        $exists = (bool)$stmt->fetch()['exists_col'];

        if (!$exists) {
            $sql = "ALTER TABLE credit_transactions ADD COLUMN {$column}";
            $db->exec($sql);
            echo "ADDED: credit_transactions.{$columnName}\n";
        } else {
            echo "EXISTS: credit_transactions.{$columnName}\n";
        }
    }

    // 2. Verificar se user_wallets existe
    $stmt = $db->query("SHOW TABLES LIKE 'user_wallets'");
    if ($stmt->rowCount() === 0) {
        echo "\nERROR: Tabela user_wallets não existe!\n";
        echo "Execute o script de criação de tabelas primeiro.\n";
        exit(1);
    } else {
        echo "\nOK: user_wallets existe\n";
    }

    // 3. Verificar se pricing_rules existe
    $stmt = $db->query("SHOW TABLES LIKE 'pricing_rules'");
    if ($stmt->rowCount() === 0) {
        echo "\nWARNING: pricing_rules não existe!\n";
    } else {
        echo "OK: pricing_rules existe\n";
    }

    echo "\n=== MIGRATION COMPLETE ===\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
