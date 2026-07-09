<?php
/**
 * Chama Frete - Migration Runner
 *
 * Uso: php database/migrate.php [--rollback=filename.sql]
 *
 * Executa migrations pendentes (arquivos .sql não registrados na tabela `migrations`).
 * Deve ser executado do diretório raiz da API.
 */

$rootDir = __DIR__ . '/..';
require_once $rootDir . '/vendor/autoload.php';

use App\Core\Database;

// Load env
$envFile = file_exists($rootDir . '/.env.production') ? $rootDir . '/.env.production' : $rootDir . '/.env';
if (file_exists($envFile)) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname($envFile));
    $dotenv->load();
}

$migrationsDir = __DIR__ . '/migrations';
$db = Database::getConnection();

// Ensure migrations table exists
$db->exec("CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checksum VARCHAR(64) NOT NULL,
    execution_time_ms INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$executed = $db->query("SELECT filename, checksum FROM migrations")->fetchAll(PDO::FETCH_KEY_PAIR);

$files = glob($migrationsDir . '/*.sql');
sort($files);

$count = 0;

foreach ($files as $file) {
    $filename = basename($file);

    if (isset($executed[$filename])) {
        continue;
    }

    $sql = file_get_contents($file);
    $checksum = hash('sha256', $sql);

    if ($sql === false || trim($sql) === '') {
        echo "  [SKIP] {$filename} (vazio)\n";
        continue;
    }

    try {
        $start = microtime(true);

        $db->exec($sql);

        $time = round((microtime(true) - $start) * 1000);

        $stmt = $db->prepare("INSERT INTO migrations (filename, name, executed_at, checksum, execution_time_ms) VALUES (:file, :name, NOW(), :checksum, :time)");
        $stmt->execute([
            ':file' => $filename,
            ':name' => str_replace(['.sql', '_'], ['', ' '], $filename),
            ':checksum' => $checksum,
            ':time' => $time,
        ]);

        echo "  [OK] {$filename} ({$time}ms)\n";
        $count++;
    } catch (Exception $e) {
        echo "  [ERROR] {$filename}: {$e->getMessage()}\n";
        exit(1);
    }
}

echo "\n{$count} migration(s) executada(s).\n";
