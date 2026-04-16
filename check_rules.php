<?php
require_once __DIR__ . '/src/Core/Database.php';

$db = \App\Core\Database::getConnection();

$all = $db->query("SELECT module_key, feature_key, feature_name FROM pricing_rules WHERE module_key IN ('marketplace','freights') ORDER BY module_key, feature_key")->fetchAll(PDO::FETCH_ASSOC);

foreach ($all as $r) {
    echo "{$r['module_key']}.{$r['feature_key']} - {$r['feature_name']}\n";
}