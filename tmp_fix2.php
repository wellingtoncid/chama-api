<?php
$pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=chama_frete_dev;charset=utf8mb4", "root", "Root@Chama123#");

// 1. Add grupos.edit permission
$stmt = $pdo->prepare("SELECT id FROM permissions WHERE slug = ?");
$stmt->execute(['grupos.edit']);
if (!$permRow = $stmt->fetch()) {
    $pdo->prepare("INSERT INTO permissions (slug, label) VALUES (?, ?)")->execute(['grupos.edit', 'Editar Grupos']);
    $permId = $pdo->lastInsertId();
    echo "Created grupos.edit (id=$permId)\n";
} else {
    $permId = $permRow['id'];
    echo "grupos.edit already exists (id=$permId)\n";
}

// 2. Link to admin role
$roleId = $pdo->query("SELECT id FROM roles WHERE slug='admin'")->fetchColumn();
if ($roleId) {
    $stmt = $pdo->prepare("SELECT 1 FROM role_permissions WHERE role_id=? AND permission_id=?");
    $stmt->execute([$roleId, $permId]);
    if (!$stmt->fetch()) {
        $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)")->execute([$roleId, $permId]);
        echo "Linked grupos.edit to admin role\n";
    } else {
        echo "Already linked\n";
    }
}

// 3. Also link to other internal roles
foreach (['gerente', 'suporte', 'marketing', 'coordenador', 'supervisor'] as $slug) {
    $rid = $pdo->prepare("SELECT id FROM roles WHERE slug=?");
    $rid->execute([$slug]);
    if ($r = $rid->fetch()) {
        $chk = $pdo->prepare("SELECT 1 FROM role_permissions WHERE role_id=? AND permission_id=?");
        $chk->execute([$r['id'], $permId]);
        if (!$chk->fetch()) {
            $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)")->execute([$r['id'], $permId]);
            echo "Linked to $slug\n";
        }
    }
}

echo "Done.\n";
