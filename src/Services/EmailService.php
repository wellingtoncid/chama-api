<?php

namespace App\Services;

use PDO;

class EmailService {
    private PDO $db;
    private array $smtpConfig;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->loadSmtpConfig();
    }

    private function loadSmtpConfig(): void {
        $keys = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from_email', 'smtp_from_name'];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        
        $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);
        
        $settings = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($settings as $s) {
            $key = str_replace('smtp_', '', $s['setting_key']);
            $this->smtpConfig[$key] = $s['setting_value'];
        }
        
        $this->smtpConfig['port'] = $this->smtpConfig['port'] ?? '587';
        $this->smtpConfig['from_name'] = $this->smtpConfig['from_name'] ?? 'Chama Frete';
    }

    public function isConfigured(): bool {
        return !empty($this->smtpConfig['host']) 
            && !empty($this->smtpConfig['user']) 
            && !empty($this->smtpConfig['pass']);
    }

    public function send(string $to, string $subject, string $body, bool $isHtml = true): bool {
        if (!$this->isConfigured()) {
            error_log("EmailService: SMTP não configurado");
            return false;
        }

        $host = $this->smtpConfig['host'];
        $port = (int)$this->smtpConfig['port'];
        $user = $this->smtpConfig['user'];
        $pass = $this->smtpConfig['pass'];
        $from = $this->smtpConfig['from_email'] ?? $user;
        $fromName = $this->smtpConfig['from_name'];

        $headers = [
            "From: {$fromName} <{$from}>",
            "Reply-To: {$from}",
            "Return-Path: {$from}",
            "MIME-Version: 1.0",
            "Content-Type: " . ($isHtml ? "text/html; charset=UTF-8" : "text/plain; charset=UTF-8"),
            "X-Mailer: ChamaFrete/1.0"
        ];

        // Tenta usar SMTP direto via socket
        if ($this->sendViaSMTP($host, $port, $user, $pass, $from, $fromName, $to, $subject, $body, $headers)) {
            return true;
        }

        // Fallback para mail() nativo do PHP
        return $this->sendViaMail($to, $subject, $body, $headers, $from);
    }

    private function sendViaSMTP(string $host, int $port, string $user, string $pass, string $from, string $fromName, string $to, string $subject, string $body, array $headers): bool {
        $crlf = "\r\n";
        
        $socket = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$socket) {
            error_log("EmailService: Não foi possível conectar ao SMTP ({$host}:{$port})");
            return false;
        }

        stream_set_timeout($socket, 10);

        // Ler banner
        $response = fgets($socket, 515);
        
        // EHLO
        fwrite($socket, "EHLO " . gethostname() . $crlf);
        $this->readSMTPResponse($socket);
        
        // STARTTLS se porta 587
        if ($port === 587) {
            fwrite($socket, "STARTTLS" . $crlf);
            $this->readSMTPResponse($socket);
            
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                error_log("EmailService: Falha ao iniciar TLS");
                fclose($socket);
                return false;
            }
            
            // Re-EHLO após TLS
            fwrite($socket, "EHLO " . gethostname() . $crlf);
            $this->readSMTPResponse($socket);
        }

        // AUTH LOGIN
        fwrite($socket, "AUTH LOGIN" . $crlf);
        $this->readSMTPResponse($socket);
        
        fwrite($socket, base64_encode($user) . $crlf);
        $this->readSMTPResponse($socket);
        
        fwrite($socket, base64_encode($pass) . $crlf);
        $authResponse = $this->readSMTPResponse($socket);
        
        if (strpos($authResponse, '235') === false) {
            error_log("EmailService: Falha na autenticação SMTP");
            fclose($socket);
            return false;
        }

        // MAIL FROM
        fwrite($socket, "MAIL FROM: <{$from}>" . $crlf);
        $this->readSMTPResponse($socket);

        // RCPT TO
        fwrite($socket, "RCPT TO: <{$to}>" . $crlf);
        $this->readSMTPResponse($socket);

        // DATA
        fwrite($socket, "DATA" . $crlf);
        $this->readSMTPResponse($socket);

        // Message
        $message = "Subject: {$subject}" . $crlf;
        $message .= implode($crlf, $headers) . $crlf;
        $message .= $crlf;
        $message .= $body . $crlf;
        $message .= "." . $crlf;

        fwrite($socket, $message);
        $this->readSMTPResponse($socket);

        // QUIT
        fwrite($socket, "QUIT" . $crlf);
        fclose($socket);

        return true;
    }

    private function readSMTPResponse($socket): string {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $response;
    }

    private function sendViaMail(string $to, string $subject, string $body, array $headers, string $from): bool {
        $headerString = implode("\r\n", $headers);
        return mail($to, $subject, $body, $headerString, "-f{$from}");
    }

    /**
     * Envia email de recuperação de senha
     */
    public function sendPasswordReset(string $to, string $name, string $token): bool {
        $resetUrl = $this->getBaseUrl() . "/reset-password?token={$token}";
        
        $subject = "Recuperar senha - Chama Frete";
        $body = "
            <h2>Olá, {$name}!</h2>
            <p>Você solicitou a recuperação de senha no Chama Frete.</p>
            <p>Clique no botão abaixo para criar uma nova senha:</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$resetUrl}' style='background-color: #f97316; color: white; padding: 15px 30px; text-decoration: none; border-radius: 10px; font-weight: bold;'>
                    CRIAR NOVA SENHA
                </a>
            </p>
            <p style='color: #666; font-size: 12px;'>
                Se você não solicitou esta recuperação, ignore este email.<br>
                Este link expira em 1 hora.
            </p>
        ";
        
        return $this->send($to, $subject, $body);
    }

    /**
     * Envia email de boas-vindas
     */
    public function sendWelcomeEmail(string $to, string $name, string $role): bool {
        $dashboardUrl = $this->getBaseUrl() . "/dashboard";
        
        $subject = "Bem-vindo ao Chama Frete!";
        $roleLabel = $role === 'driver' ? 'Motorista' : 'Empresa';
        
        $body = "
            <h2>Bem-vindo ao Chama Frete, {$name}!</h2>
            <p>Sua conta como <strong>{$roleLabel}</strong> foi criada com sucesso.</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$dashboardUrl}' style='background-color: #f97316; color: white; padding: 15px 30px; text-decoration: none; border-radius: 10px; font-weight: bold;'>
                    ACESSAR PLATAFORMA
                </a>
            </p>
            <h3>Próximos passos:</h3>
            <ul>
                <li>Complete seu perfil</li>
                <li>" . ($role === 'driver' ? 'Cadastre seu veículo' : 'Publique seu primeiro frete') . "</li>
                <li>Verifique seus documentos</li>
            </ul>
        ";
        
        return $this->send($to, $subject, $body);
    }

    private function getBaseUrl(): string {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'chamafrete.com.br';
        return "{$scheme}://{$host}";
    }
}
