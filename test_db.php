<?php
// Inclua sua conexão aqui (ajuste o caminho conforme seu projeto)
require_once 'App/Core/Database.php'; 
$db = \App\Core\Database::getInstance(); 

$termo = "Cebola";
$stmt = $db->prepare("SELECT id, product, status FROM freights WHERE product LIKE :t");
$stmt->execute([':t' => "%$termo%"]);
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h1>Teste de Diagnóstico</h1>";
echo "Busca por: " . $termo . "<br>";
echo "Registros encontrados: " . count($result) . "<br>";
echo "<pre>";
print_r($result);
echo "</pre>";