<?php
header('Content-Type: application/json');

$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'services' => []
];

// Check database
try {
    $db = new PDO('mysql:host=localhost;dbname=chama_frete', 'user', 'pass');
    $health['services']['database'] = 'connected';
} catch(Exception $e) {
    $health['status'] = 'unhealthy';
    $health['services']['database'] = 'disconnected';
}

// Check disk space
$free = disk_free_space("/");
$total = disk_total_space("/");
$health['services']['disk'] = [
    'free' => round($free / 1024 / 1024, 2) . 'MB',
    'used_percent' => round(100 - ($free / $total * 100), 2) . '%'
];

echo json_encode($health, JSON_PRETTY_PRINT);