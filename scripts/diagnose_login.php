<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

if ($argc < 3) {
    echo "Usage: php diagnose_login.php <email> <password>\n";
    exit(2);
}

$email = $argv[1];
$password = $argv[2];

$db = Database::getConnection();

try {
    // Buscar usuário por email
    $stmt = $db->prepare("SELECT u.id, u.email, u.password, u.status, u.account_id, r.slug AS role_slug, u.user_type, p.avatar_url, a.corporate_name AS company_name FROM users u LEFT JOIN roles r ON u.role_id = r.id LEFT JOIN accounts a ON u.account_id = a.id LEFT JOIN user_profiles p ON u.id = p.user_id WHERE u.email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo "User not found.\n";
        exit(0);
    }

    echo "User: " . $user['id'] . " | email: " . $user['email'] . " | status: " . $user['status'] . "\n";
    echo "Account: " . ($user['account_id'] ?? 'NULL') . " | company: " . ($user['company_name'] ?? 'NULL') . "\n";
    echo "Role slug: " . ($user['role_slug'] ?? 'NULL') . " | user_type: " . ($user['user_type'] ?? 'NULL') . "\n";
    echo "Password hash: " . ($user['password'] ?? 'NULL') . "\n";

    $matched = password_verify($password, $user['password'] ?? '');
    echo "Password verify result: " . ($matched ? 'MATCH' : 'NO MATCH') . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
