<?php
// Direct test of chat room endpoint
$ch = curl_init('http://127.0.0.1:8000/api/chat/room/1');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer test',
    'Content-Type: application/json'
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP: $httpCode\n";
echo "Response: $response\n";
