<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=chama_frete_dev', 'root', 'Root@Chama123#');

echo "=== Corrigindo listings existentes ===\n\n";

// Buscar listings sem expires_at ou sem main_image
$stmt = $pdo->query("SELECT id, user_id, title FROM listings WHERE status != 'deleted'");
$listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$fixedCount = 0;
foreach ($listings as $listing) {
    $updates = [];
    $params = [];
    
    // Se não tem expires_at, adicionar 30 dias
    $stmtCheck = $pdo->prepare("SELECT expires_at FROM listings WHERE id = ?");
    $stmtCheck->execute([$listing['id']]);
    $current = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if (empty($current['expires_at'])) {
        $updates[] = "expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY)";
    }
    
    // Se não tem main_image, buscar da tabela listing_images
    if (empty($current['main_image'])) {
        $stmtImg = $pdo->prepare("SELECT image_url FROM listing_images WHERE listing_id = ? ORDER BY sort_order ASC LIMIT 1");
        $stmtImg->execute([$listing['id']]);
        $img = $stmtImg->fetch(PDO::FETCH_ASSOC);
        
        if ($img && !empty($img['image_url'])) {
            $updates[] = "main_image = ?";
            $params[] = $img['image_url'];
        }
    }
    
    if (!empty($updates)) {
        $params[] = $listing['id'];
        $sql = "UPDATE listings SET " . implode(", ", $updates) . " WHERE id = ?";
        $stmtUpdate = $pdo->prepare($sql);
        $stmtUpdate->execute($params);
        $fixedCount++;
        echo "Corrigido listing #{$listing['id']}: {$listing['title']}\n";
    }
}

echo "\nTotal corrigidos: $fixedCount\n";
