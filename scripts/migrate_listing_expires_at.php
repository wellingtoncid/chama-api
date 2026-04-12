<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=chama_frete_dev', 'root', 'Root@Chama123#');

echo "=== Migrando featured_expires_at para expires_at ===\n\n";

$stmt = $pdo->query("SHOW COLUMNS FROM listings LIKE 'featured_expires_at'");
if ($stmt->fetch()) {
    $pdo->exec("ALTER TABLE listings CHANGE COLUMN featured_expires_at expires_at DATETIME NULL DEFAULT NULL");
    echo "Coluna renomeada: featured_expires_at -> expires_at\n";
} else {
    $stmt = $pdo->query("SHOW COLUMNS FROM listings LIKE 'expires_at'");
    if ($stmt->fetch()) {
        echo "Coluna expires_at já existe.\n";
    } else {
        $pdo->exec("ALTER TABLE listings ADD COLUMN expires_at DATETIME NULL AFTER is_featured");
        echo "Coluna expires_at adicionada.\n";
    }
}

echo "\n=== Verificando estrutura da tabela listings ===\n";
$stmt = $pdo->query("DESCRIBE listings");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    if (in_array($col['Field'], ['expires_at', 'featured_expires_at', 'is_featured'])) {
        echo "- {$col['Field']}: {$col['Type']} (Default: {$col['Default']})\n";
    }
}

echo "\nDone!\n";
