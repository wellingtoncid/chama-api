<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=chama_frete_dev', 'root', 'Root@Chama123#');

echo "=== Imagens dos listings ===\n";
$stmt = $pdo->query("SELECT * FROM listing_images LIMIT 5");
$images = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($images);

echo "\n=== Listing ID 1 ===\n";
$stmt = $pdo->prepare("SELECT * FROM listings WHERE id = 1");
$stmt->execute();
print_r($stmt->fetch(PDO::FETCH_ASSOC));
