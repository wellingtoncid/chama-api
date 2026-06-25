<?php
require __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;

$secret = '4e1b1f326c471c509d7c70ff7714bb9728bbc76fab18113ed8bf6fc54e260194';
$payload = ['data' => ['id' => 2, 'role' => 'admin', 'email' => 'admin@chamafrete.com.br'], 'sub' => 2, 'role' => 'admin', 'iat' => time(), 'exp' => time() + 3600];
$token = JWT::encode($payload, $secret, 'HS256');
echo $token . PHP_EOL;
