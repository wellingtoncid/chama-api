<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class AuthController {
    private $userModel;
    private $key;

    public function __construct($db) {
        $this->userModel = new User($db);
        // Garante que a chave nunca seja vazia para não quebrar o JWT
        $this->key = $_ENV['JWT_SECRET'] ?? 'chave_mestra_32_caracteres_minimo_segura';
    }

    public function handle($endpoint, $data) {
        switch ($endpoint) {
            case 'login': return $this->login($data);
            case 'register': return $this->register($data);
            case 'reset-password': return $this->resetPassword($data);
            case 'update-user-basic': return $this->updateUserBasic($data);
            case 'validate': return ["success" => true, "user" => $data];
            default: return ["error" => "Ação inválida no Auth"];
        }
    }

    private function login($data) {
        $loginIdentifier = $data['email'] ?? $data['login'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($loginIdentifier) || empty($password)) {
            return ["success" => false, "message" => "Preencha todos os campos"];
        }

        $user = $this->userModel->findByLogin($loginIdentifier);

        if ($user && password_verify($password, $user['password'])) {
            // Remove dados sensíveis
            unset($user['password'], $user['reset_token'], $user['token_expires']);

            try {
                $issuedAt = time();
                $expire = $issuedAt + (int)($_ENV['JWT_EXPIRE'] ?? 86400); 
                
                $payload = [
                    "iat" => $issuedAt,
                    "exp" => $expire,
                    "data" => [
                        "id" => $user['id'],
                        "email" => $user['email'],
                        "role" => strtoupper($user['role'] ?? 'USER') // Normaliza para ADMIN/USER
                    ]
                ];

                $jwt = JWT::encode($payload, $this->key, 'HS256');

                return [
                    "success" => true,
                    "message" => "Bem-vindo!",
                    "token" => $jwt,
                    "user" => $user
                ];

            } catch (Exception $e) {
                error_log("Erro JWT: " . $e->getMessage());
                return ["success" => false, "message" => "Erro interno no servidor."];
            }
        }

        return ["success" => false, "message" => "Credenciais incorretas."];
    }

    private function register($data) {
        // Validação de campos obrigatórios
        if (empty($data['name']) || empty($data['email']) || empty($data['password'])) {
            return ["success" => false, "message" => "Campos obrigatórios ausentes."];
        }

        if ($this->userModel->findByLogin($data['email'])) {
            return ["success" => false, "message" => "Este e-mail já está em uso."];
        }
        
        // WhatsApp opcional mas único se enviado
        if (!empty($data['whatsapp']) && $this->userModel->findByLogin($data['whatsapp'])) {
            return ["success" => false, "message" => "Este WhatsApp já está cadastrado."];
        }
        
        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        $success = $this->userModel->create($data);
        
        return ["success" => $success, "message" => $success ? "Cadastro realizado!" : "Erro ao cadastrar."];
    }

    private function updateUserBasic($data) {
        // Rota chamada pelo index.php para atualizações rápidas de perfil
        if (!isset($data['id'])) return ["success" => false, "message" => "ID não fornecido."];
        
        $success = $this->userModel->updateBasicInfo($data['id'], $data);
        return ["success" => $success, "message" => $success ? "Perfil atualizado!" : "Nada foi alterado."];
    }

    private function resetPassword($data) {
        $step = $data['step'] ?? '';
        $email = strtolower(trim($data['email'] ?? ''));

        if ($step === 'request') {
            $user = $this->userModel->findByLogin($email);
            if (!$user) {
                return ["success" => true, "message" => "Se o e-mail existir, você receberá o código."];
            }

            $token = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires = date("Y-m-d H:i:s", strtotime("+15 minutes"));

            $this->userModel->saveResetToken($user['id'], $token, $expires);
            $sent = $this->sendResetEmail($email, $token);
            
            return ["success" => true, "message" => "Código enviado para o e-mail."];
        }

        if ($step === 'confirm') {
            $token = trim($data['token'] ?? '');
            $newPassword = $data['newPassword'] ?? '';
            
            $user = $this->userModel->validateResetToken($email, $token);
            
            if ($user) {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $this->userModel->updatePassword($user['id'], $hashedPassword);
                $this->userModel->clearResetToken($user['id']);
                return ["success" => true, "message" => "Senha alterada!"];
            }
            return ["success" => false, "message" => "Código inválido ou expirado."];
        }
    }

    private function sendResetEmail($to, $token) {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = $_ENV['SMTP_USER'] ?? 'chamafretebr@gmail.com'; 
            $mail->Password   = $_ENV['SMTP_PASS'] ?? 'gjjq xper tydj ksti';  
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom($mail->Username, 'Chama Frete');
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = 'Seu Código de Recuperação';
            
            $mail->Body = "
                <div style='font-family: Arial; padding: 20px; color: #333;'>
                    <h2>Código de Acesso</h2>
                    <p>Use o código abaixo para redefinir sua senha:</p>
                    <h1 style='color: #f97316; letter-spacing: 5px;'>$token</h1>
                    <p>Expira em 15 minutos.</p>
                </div>";

            return $mail->send();
        } catch (Exception $e) {
            error_log("Falha Email: " . $mail->ErrorInfo);
            return false;
        }
    }

    public function validateToken($token) {
        if (empty($token)) return null;
        try {
            // Usa Key object exigido pelas versões recentes do Firebase/JWT
            $decoded = JWT::decode($token, new Key($this->key, 'HS256'));
            return (array) $decoded->data; 
        } catch (Exception $e) {
            return null; 
        }
    }
}