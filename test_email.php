<?php
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    // Configurações do Servidor
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'chamafretebr@gmail.com'; // Seu e-mail
    $mail->Password   = 'gjjq xper tydj ksti';    // Aquela de 16 dígitos do Google
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // Destinatário
    $mail->setFrom('chamafretebr@gmail.com', 'Teste Chama Frete');
    $mail->addAddress('chamafretebr@gmail.com'); // Envie para você mesmo

    // Conteúdo
    $mail->isHTML(true);
    $mail->Subject = 'Teste de Conexão SMTP';
    $mail->Body    = 'Se você recebeu este e-mail, o PHPMailer está configurado corretamente!';

    $mail->send();
    echo "<h1>Sucesso!</h1><p>O e-mail foi enviado com êxito.</p>";
} catch (Exception $e) {
    echo "<h1>Erro ao enviar:</h1><p>{$mail->ErrorInfo}</p>";
}