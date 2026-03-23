<?php
$password = 'admin123'; // Senha que você vai digitar no login
$hash = password_hash($password, PASSWORD_BCRYPT);

echo "<h3>Teste de Credenciais</h3>";
echo "Senha para digitar no Front: <b>$password</b><br>";
echo "Hash para copiar e colar no Banco (SQL): <br><input style='width:500px' value='$hash' readonly><br><br>";

// Simulação do password_verify que o seu login faz
if (password_verify('admin123', $hash)) {
    echo "<span style='color:green'>✅ O PHP confirmou que a senha bate com este hash.</span>";
}
?>