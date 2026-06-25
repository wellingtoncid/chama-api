<?php
$pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=chama_frete_dev;charset=utf8mb4", "root", "Root@Chama123#");

echo "=== permissions ===\n";
$stmt = $pdo->query("DESCRIBE permissions");
foreach ($stmt as $row) echo "  {$row['Field']} {$row['Type']}\n";

echo "=== roles ===\n";
$stmt = $pdo->query("DESCRIBE roles");
foreach ($stmt as $row) echo "  {$row['Field']} {$row['Type']}\n";

echo "=== role_permissions ===\n";
$stmt = $pdo->query("DESCRIBE role_permissions");
foreach ($stmt as $row) echo "  {$row['Field']} {$row['Type']}\n";

echo "=== users (first 3) ===\n";
$stmt = $pdo->query("SELECT id, email, role, name FROM users LIMIT 3");
foreach ($stmt as $row) echo "  {$row['id']} {$row['email']} {$row['role']} {$row['name']}\n";
