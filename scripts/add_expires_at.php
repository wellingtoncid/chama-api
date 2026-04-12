<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=chama_frete_dev', 'root', 'Root@Chama123#');

// Adicionar coluna expires_at se não existir
$stmt = $pdo->query("SHOW COLUMNS FROM listings LIKE 'expires_at'");
if (!$stmt->fetch()) {
    $pdo->exec("ALTER TABLE listings ADD COLUMN expires_at DATETIME NULL AFTER featured_expires_at");
    echo "Coluna expires_at adicionada!\n";
} else {
    echo "Coluna expires_at já existe.\n";
}

// Também adicionar featured_expires_at para listings antigos
$stmt = $pdo->query("SHOW COLUMNS FROM listings LIKE 'featured_expires_at'");
if (!$stmt->fetch()) {
    $pdo->exec("ALTER TABLE listings ADD COLUMN featured_expires_at DATETIME NULL AFTER expires_at");
    echo "Coluna featured_expires_at adicionada!\n";
} else {
    echo "Coluna featured_expires_at já existe.\n";
}

echo "Done!\n";
