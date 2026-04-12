<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=chama_frete_dev', 'root', 'Root@Chama123#');

echo "=== Estrutura da tabela listings ===\n";
$stmt = $pdo->query("DESCRIBE listings");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $col) {
    if (in_array($col['Field'], ['main_image', 'images', 'expires_at', 'is_featured'])) {
        echo "- {$col['Field']}: {$col['Type']} (Default: {$col['Default']})\n";
    }
}

echo "\n=== Últimos 3 listings ===\n";
$stmt = $pdo->query("SELECT id, title, main_image, expires_at, is_featured, status FROM listings ORDER BY id DESC LIMIT 3");
$listings = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($listings);

echo "\n=== Imagens dos últimos 3 listings ===\n";
$stmt = $pdo->query("SELECT listing_id, image_url FROM listing_images WHERE listing_id IN (SELECT id FROM listings ORDER BY id DESC LIMIT 3) ORDER BY sort_order");
$images = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($images);
