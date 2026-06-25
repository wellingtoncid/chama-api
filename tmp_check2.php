<?php
try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=chama_frete_dev;charset=utf8mb4", "root", "Root@Chama123#");
    
    // Check user 1
    $stmt = $pdo->prepare("SELECT id, email, role, name FROM users WHERE id = ?");
    $stmt->execute([1]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "User 1: " . json_encode($user) . "\n";
    
    // Check 'grupos.edit' permission
    $stmt2 = $pdo->query("SELECT * FROM permissions WHERE slug = 'grupos.edit'");
    echo "grupos.edit permission: " . json_encode($stmt2->fetch(PDO::FETCH_ASSOC)) . "\n";
    
    // Check admin role
    $stmt3 = $pdo->query("SELECT * FROM roles WHERE slug = 'admin'");
    echo "Admin role: " . json_encode($stmt3->fetch(PDO::FETCH_ASSOC)) . "\n";
    
} catch (PDOException $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
}
