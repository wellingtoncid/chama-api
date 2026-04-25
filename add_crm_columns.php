<?php
$db = new PDO('mysql:host=127.0.0.1;dbname=chama_frete_dev', 'root', 'Root@Chama123#');
$check = $db->query("SHOW COLUMNS FROM portal_requests LIKE 'pipeline_stage'");
if (!$check->fetch()) {
    echo "Adding columns...\n";
    $db->exec("
        ALTER TABLE portal_requests 
        ADD COLUMN pipeline_stage VARCHAR(50) DEFAULT 'new',
        ADD COLUMN deal_value DECIMAL(12,2) DEFAULT 0,
        ADD COLUMN score INT DEFAULT 0,
        ADD COLUMN assigned_to INT DEFAULT NULL
    ");
    echo "Done! Columns added.\n";
} else {
    echo "Columns already exist\n";
}