<?php
declare(strict_types=1);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$db   = getenv('DB_NAME') ?: 'chama_frete_dev';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?? '';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Erro de conexão: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

// Garantir tabela de migrations
$pdo->exec("
  CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(255) NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$migrationsDir = __DIR__ . '/../migrations';
$files = array_filter(glob($migrationsDir . '/*.sql'), function($path) {
    return preg_match('/^\d{3}_.*\.sql$/', basename($path));
});
natsort($files);

// Quais migrations já foram aplicadas
$applied = $pdo->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);

foreach ($files as $file) {
    $version = basename($file);
    if (in_array($version, $applied)) continue;

    $sql = file_get_contents($file);
    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $stmt = $pdo->prepare("INSERT INTO schema_migrations (version) VALUES (:v)");
        $stmt->execute([':v' => $version]);
        $pdo->commit();
        echo "Migrated: {$version}\n";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, "Erro ao aplicar {$version}: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
echo "Todas migrations foram aplicadas (ou já estavam aplicadas).\n";
