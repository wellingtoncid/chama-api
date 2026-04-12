<?php
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Repositories/ListingRepository.php';
require_once __DIR__ . '/../src/Controllers/ListingController.php';

use App\Core\Database;
use App\Controllers\ListingController;

$db = Database::getConnection();
$controller = new ListingController($db);

echo "=== Testando getPublicBySlug ===\n";

$testSlug = 'sprinter-313-top-288';
echo "Slug: $testSlug\n\n";

$result = $controller->getPublicBySlug(['slug' => $testSlug]);
echo "Result:\n";
print_r($result);
