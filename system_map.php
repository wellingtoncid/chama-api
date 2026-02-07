<?php
// Scan todo o projeto e crie documentação automática
$projectPath = 'c:/xampp/htdocs/chama-frete/api';
$map = [];

function scanCode($path) {
    $files = glob($path . '/*');
    foreach($files as $file) {
        if(is_dir($file)) {
            scanCode($file);
        } elseif(preg_match('/\.(php|sql)$/', $file)) {
            $content = file_get_contents($file);
            // Extrair informações importantes
            preg_match_all('/class (\w+)/', $content, $classes);
            preg_match_all('/function (\w+)/', $content, $functions);
            
            echo "📁 $file\n";
            echo "   Classes: " . implode(', ', $classes[1]) . "\n";
            echo "   Funções: " . implode(', ', $functions[1]) . "\n\n";
        }
    }
}

scanCode($projectPath);
?>