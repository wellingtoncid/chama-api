<?php
$pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=chama_frete_dev;charset=utf8mb4", "root", "Root@Chama123#");

// 1. Criar permissão grupos.edit se não existir
$stmt = $pdo->prepare("SELECT id FROM permissions WHERE slug = ?");
$stmt->execute(['grupos.edit']);
if (!$stmt->fetch()) {
    $pdo->prepare("INSERT INTO permissions (slug, name, module) VALUES (?, ?, ?)")->execute(['grupos.edit', 'Editar Grupos', 'grupos']);
    echo "Created grupos.edit permission\n";
} else {
    echo "grupos.edit already exists\n";
}

// 2. Verificar se admin role tem essa permissão
$stmt = $pdo->query("SELECT rp.id FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id JOIN roles r ON r.id = rp.role_id WHERE r.slug = 'admin' AND p.slug = 'grupos.edit'");
if (!$stmt->fetch()) {
    $permId = $pdo->query("SELECT id FROM permissions WHERE slug = 'grupos.edit'")->fetchColumn();
    $roleId = $pdo->query("SELECT id FROM roles WHERE slug = 'admin'")->fetchColumn();
    if ($permId && $roleId) {
        $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)")->execute([$roleId, $permId]);
        echo "Linked grupos.edit to admin role\n";
    }
} else {
    echo "Admin role already has grupos.edit\n";
}

// 3. Verificar pricing_rules
$stmt = $pdo->query("SELECT id, module_key, feature_key, price_per_use FROM pricing_rules WHERE module_key='promotions' AND feature_key='whatsapp_promotion'");
$rule = $stmt->fetch(PDO::FETCH_ASSOC);
if ($rule) {
    echo "Pricing rule exists: " . json_encode($rule) . "\n";
} else {
    echo "Pricing rule MISSING - creating\n";
    $pdo->exec("INSERT INTO pricing_rules (module_key, feature_key, feature_name, pricing_type, free_limit, price_per_use, is_active) VALUES ('promotions', 'whatsapp_promotion', 'Divulgação em Grupos WhatsApp', 'per_use', 0, 14.90, 1)");
    echo "Created pricing rule\n";
}

// 4. Check whatsapp_groups has openwa_group_id
$stmt = $pdo->query("SHOW COLUMNS FROM whatsapp_groups LIKE 'openwa_group_id'");
if ($stmt->fetch()) {
    echo "openwa_group_id column exists\n";
} else {
    echo "openwa_group_id MISSING - adding\n";
    $pdo->exec("ALTER TABLE whatsapp_groups ADD COLUMN openwa_group_id VARCHAR(100) NULL");
}

// 5. Check promotion_queue table
$stmt = $pdo->query("SHOW TABLES LIKE 'promotion_queue'");
if ($stmt->fetch()) {
    echo "promotion_queue table exists\n";
} else {
    echo "promotion_queue MISSING - creating\n";
    $pdo->exec("CREATE TABLE promotion_queue (
        id INT NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        reference_type ENUM('freight','listing') NOT NULL,
        reference_id INT NOT NULL,
        amount_paid DECIMAL(10,2) DEFAULT '0.00',
        status ENUM('pending_payment','pending_approval','approved','sent','failed','rejected') DEFAULT 'pending_payment',
        approved_by INT DEFAULT NULL,
        approved_at DATETIME DEFAULT NULL,
        rejection_reason TEXT,
        groups_sent INT DEFAULT '0',
        total_groups INT DEFAULT '0',
        error_message TEXT,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user_id (user_id),
        KEY idx_reference (reference_type, reference_id),
        KEY idx_status (status),
        KEY idx_approved_by (approved_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

echo "\nAll checks done.\n";
