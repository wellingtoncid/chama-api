<?php
require __DIR__ . '/../vendor/autoload.php';

$envFile = is_file(__DIR__ . '/../.env.production') ? __DIR__ . '/../.env.production' : __DIR__ . '/../.env';
if (is_file($envFile)) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname($envFile));
    $dotenv->load();
}

$db = App\Core\Database::getConnection();

$data = [
  ['label'=>'MOPP','desc'=>'Produtos Perigosos'],
  ['label'=>'Carga Indivisível','desc'=>'Cargas Especiais'],
  ['label'=>'Coletivo','desc'=>'Passageiros/Vans'],
  ['label'=>'Escolar','desc'=>'Transporte Escolar'],
  ['label'=>'Emergência','desc'=>'Ambulância/Bombeiros'],
  ['label'=>'Motofrete','desc'=>'Atividade Remunerada']
];

$stmt = $db->prepare('UPDATE site_settings SET setting_value = :v WHERE setting_key = :k');
$stmt->execute([':v'=>json_encode($data, JSON_UNESCAPED_UNICODE), ':k'=>'certification_types']);

echo 'certification_types updated: ' . count($data) . ' items {label, desc}' . PHP_EOL;
