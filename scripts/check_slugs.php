<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=chama_frete_dev', 'root', 'Root@Chama123#');

echo "=== Listings com slug ===\n";
$stmt = $pdo->query("SELECT id, title, slug, main_image FROM listings LIMIT 5");
$listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($listings);

echo "\n=== Verificar se findBySlug funciona ===\n";
$stmt = $pdo->prepare("SELECT l.*, u.name as seller_name FROM listings l JOIN users u ON l.user_id = u.id WHERE l.slug = ? AND l.status IN ('active', 'paused', 'sold')");
$stmt->execute(['sprinter-313-top-288']);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo $result ? "Encontrou: " . $result['title'] . "\n" : "Não encontrou\n";
