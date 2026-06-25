<?php
$pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=chama_frete_dev;charset=utf8mb4", "root", "Root@Chama123#");

echo "=== freights ===\n";
$stmt = $pdo->query("DESCRIBE freights");
foreach ($stmt as $row) echo "  {$row['Field']}\n";

echo "\n=== listings ===\n";
$stmt = $pdo->query("DESCRIBE listings");
foreach ($stmt as $row) echo "  {$row['Field']}\n";
