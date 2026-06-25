<?php
try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=chama_frete_dev;charset=utf8mb4", "root", "Root@Chama123#");
    $stmt = $pdo->query("SELECT COUNT(*) FROM promotion_queue");
    echo "promotion_queue table exists, rows: " . $stmt->fetchColumn() . "\n";
    $stmt2 = $pdo->query("SELECT COUNT(*) FROM pricing_rules WHERE module_key='promotions' AND feature_key='whatsapp_promotion'");
    echo "Pricing rule: " . $stmt2->fetchColumn() . "\n";
} catch (PDOException $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
}
