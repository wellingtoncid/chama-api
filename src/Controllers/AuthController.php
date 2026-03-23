<?php
namespace App\Controllers;

use App\Core\Response;
use App\Repositories\UserRepository;
use Firebase\JWT\JWT;
use PHPMailer\PHPMailer\PHPMailer;
use Exception;

class AuthController {
    private $userRepo;
    private $secret;

    public function __construct($db) {
        $this->userRepo = new UserRepository($db);
        $this->secret = $_ENV['JWT_SECRET'] ?? 'chave_mestra_segura_2026';
    }

    /**
     * Login Unificado
     */
    public function login($data) {
        // Sanitização básica das entradas
        $loginIdentifier = trim($data['login'] ?? $data['email'] ?? '');
        // Normalize and trim password to avoid issues with accidental whitespace
        $password = isset($data['password']) ? (string)$data['password'] : '';

        if (empty($loginIdentifier) || empty($password)) {
            return Response::json(["success" => false, "message" => "Usuário e senha são obrigatórios"], 400);
        }

        // Busca o usuário no repositório
        $user = $this->userRepo->findByEmailOrWhatsapp($loginIdentifier);
        // Debug: log de encontrado/no encontrado
        if (!$user) {
            error_log("DEBUG_LOGIN: Usuário não encontrado para: $loginIdentifier");
            return Response::json(["success" => false, "message" => "E-mail/WhatsApp ou senha incorretos"], 401);
        }

       // Verificação da Senha (Onde ocorre o 401)
        if (!password_verify($password, $user['password'])) {
            error_log("DEBUG_LOGIN: Senha inválida para ID: {$user['id']}");
            return Response::json(["success" => false, "message" => "E-mail/WhatsApp ou senha incorretos"], 401);
        }
        // Tratamento do Status (Evita a mensagem vazia)
        // Se o status for nulo, assumimos 'pending' por segurança
        $currentStatus = $user['status'] ?? 'pending'; 
        if ($currentStatus !== 'active') {
            $statusMessages = [
                'pending'   => 'pendente de ativação',
                'blocked'   => 'bloqueada',
                'suspended' => 'suspensa temporariamente'
            ];
            $reason = $statusMessages[$currentStatus] ?? 'inativa';
            return Response::json([
                "success" => false, 
                "message" => "Sua conta está {$reason}. Por favor, contate o suporte."
            ], 403);
        }

        // Preparação do Token JWT (Com a nova estrutura)
        $issuedAt = time();
        $expire = $issuedAt + (int)($_ENV['JWT_EXPIRE'] ?? 86400); 

        $payload = [
            "iat" => $issuedAt,
            "exp" => $expire,
            "sub" => $user['id'],
            "data" => [
                "id"         => $user['id'],
                "email"      => $user['email'],
                "account_id" => $user['account_id'], // IMPORTANTE: Para o multi-tenancy
                "role"       => strtoupper($user['role_slug'] ?? 'driver'), // Vem do JOIN que fizemos
                "type"       => $user['user_type'] ?? 'motorista'
            ]
        ];

        try {
            $jwt = JWT::encode($payload, $this->secret, 'HS256');
            
            // Atualiza último login
            $this->userRepo->updateLastLogin($user['id']);

            // Sincroniza status de verificação do perfil
            $this->userRepo->autoApproveProfile($user['id']);

            // Busca dados atualizados do perfil para retornar
            $profileData = $this->userRepo->getProfileData($user['id']);

            return Response::json([
                "success" => true,
                "token"   => $jwt,
                "user"    => [
                    "id"          => (int)$user['id'],
                    "name"        => $user['name'],
                    "account_id"  => $user['account_id'],
                    "role"        => strtoupper($user['role_slug'] ?? 'driver'),
                    "company"     => $user['company_name'] ?? null,
                    "avatar"      => $user['avatar_url'] ?? null,
                    "company_name" => $profileData['company_name'] ?? null,
                    "document"    => $profileData['document'] ?? null,
                    "verification_status" => $profileData['verification_status'] ?? 'none',
                    "user_type"   => $profileData['user_type'] ?? null
                ]
            ]);

        } catch (Exception $e) {
            error_log("Erro JWT: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao processar acesso"], 500);
        }
    }    

    /**
     * Registro de Usuário (Motorista ou Empresa) - Versão SaaS
     */
    public function register($data) {
        try {
            // Validação básica
            if (empty($data['email']) || empty($data['password']) || empty($data['name'])) {
                return Response::json(["success" => false, "message" => "Campos obrigatórios ausentes."], 400);
            }

            // Sanitização dos dados vindos do Front
            $email = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
            $whatsapp = preg_replace('/\D/', '', $data['whatsapp'] ?? '');
            $document = preg_replace('/\D/', '', $data['document'] ?? ''); // CAPTURA O DOCUMENTO

            // Prepara os dados para o UserRepository
            $preparedData = [
                'name'          => trim($data['name']),
                'email'         => $email,
                'whatsapp'      => $whatsapp,
                'password'      => password_hash($data['password'], PASSWORD_BCRYPT),
                'role'          => $data['role'] ?? 'driver',
                'user_type'     => ($data['role'] === 'company' ? 'empresa' : 'motorista'),
                'document'      => $document, // PASSA O DOCUMENTO REAL
                'document_type' => (strlen($document) > 11) ? 'CNPJ' : 'CPF'
            ];

            // Chama o create do repositório (aquele que ajustamos com rand e UUID)
            $userId = $this->userRepo->create($preparedData);

            return Response::json([
                "success" => true, 
                "message" => "Cadastro realizado com sucesso!",
                "userId"  => $userId
            ], 201);

        } catch (Exception $e) {
            // Log para você ver o erro real no log do PHP (C:\xampp\php\logs\php_error.log)
            error_log("Erro no Registro: " . $e->getMessage());

            // Se o erro for duplicidade (E-mail ou Documento)
            if (strpos($e->getMessage(), '1062') !== false) {
                return Response::json([
                    "success" => false, 
                    "message" => "Este E-mail, WhatsApp ou CPF/CNPJ já está cadastrado."
                ], 409);
            }

            return Response::json([
                "success" => false, 
                "message" => "Erro interno: " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Método auxiliar para não poluir o fluxo principal de registro
     */
    private function handlePostRegister($email, $name) {
        try {
            if (method_exists($this, 'sendWelcomeEmail')) {
                $this->sendWelcomeEmail($email, $name);
            }
        } catch (Exception $e) {
            error_log("E-mail de boas-vindas falhou para {$email}: " . $e->getMessage());
        }
    }

    /**
     * Recuperação de Senha (Passo a Passo)
     */
    public function resetPassword($data) {
        $step = $data['step'] ?? '';
        $email = strtolower(trim($data['email'] ?? ''));

        // --- PASSO 1: Solicitação do Token ---
        if ($step === 'request') {
            if (empty($email)) {
                return Response::json(["success" => false, "message" => "E-mail é obrigatório"], 400);
            }

            $user = $this->userRepo->findByEmailOrWhatsapp($email);
            
            // Estratégia de Segurança: Sempre retornar 200 (OK) para evitar 
            // que hackers descubram quais e-mails existem na sua base.
            $genericSuccess = "Se o e-mail estiver cadastrado, você receberá um código de 6 dígitos em instantes.";

            if ($user && ($user['status'] ?? 'active') === 'active') {
                // Geramos um código numérico de 6 dígitos (mais fácil para mobile)
                $token = (string)random_int(100000, 999999);
                $expires = date("Y-m-d H:i:s", strtotime("+15 minutes"));

                // Salva e envia
                if ($this->userRepo->saveResetToken($user['id'], $token, $expires)) {
                    try {
                        $this->sendResetEmail($user['email'], $token);
                    } catch (Exception $e) {
                        error_log("Falha ao enviar e-mail de reset para {$email}: " . $e->getMessage());
                    }
                }
            }

            return Response::json(["success" => true, "message" => $genericSuccess]);
        }

        // --- PASSO 2: Confirmação e Troca da Senha ---
        if ($step === 'confirm') {
            $token = trim($data['token'] ?? '');
            $newPassword = $data['newPassword'] ?? '';

            // Validações básicas
            if (empty($token) || empty($newPassword) || empty($email)) {
                return Response::json(["success" => false, "message" => "Dados incompletos"], 400);
            }

            if (strlen($newPassword) < 6) {
                return Response::json(["success" => false, "message" => "A nova senha deve ter no mínimo 6 caracteres"], 400);
            }
            
            // Valida o token contra o banco (já checa expiração e status do usuário)
            $user = $this->userRepo->validateResetToken($email, $token);
            
            if (!$user) {
                return Response::json(["success" => false, "message" => "Código inválido ou expirado"], 400);
            }

            // Executa a troca (o updatePassword já limpa os tokens por segurança)
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            $success = $this->userRepo->updatePassword($user['id'], $hashedPassword);

            if ($success) {
                return Response::json([
                    "success" => true, 
                    "message" => "Senha atualizada com sucesso! Use sua nova senha para entrar."
                ]);
            }

            return Response::json(["success" => false, "message" => "Erro ao processar nova senha. Tente novamente."], 500);
        }

        return Response::json(["success" => false, "message" => "Operação inválida"], 400);
    }

    /**
     * Configuração PHPMailer Centralizada
     */
    private function getMailer() {
        $mail = new PHPMailer(true);

        try {
            // Configurações do Servidor
            $mail->isSMTP();
            $mail->Host       = $_ENV['SMTP_HOST'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $_ENV['SMTP_USER'];
            $mail->Password   = $_ENV['SMTP_PASS'];
            $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 587);
            
            // Protocolo de Segurança Dinâmico
            // STARTTLS para porta 587 ou SMTPS para 465
            $mail->SMTPSecure = ($mail->Port === 465) 
                ? PHPMailer::ENCRYPTION_SMTPS 
                : PHPMailer::ENCRYPTION_STARTTLS;

            // Configurações de Identidade e Codificação
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($_ENV['SMTP_USER'], $_ENV['APP_NAME'] ?? 'Chama Frete');
            
            // Timeout para evitar que a requisição do usuário fique "pendurada"
            $mail->Timeout = 10; 

            return $mail;

        } catch (Exception $e) {
            error_log("Erro ao configurar PHPMailer: " . $e->getMessage());
            throw new Exception("Serviço de e-mail temporariamente indisponível.");
        }
    }

    private function sendResetEmail($to, $token) {
        try {
            $mail = $this->getMailer();
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = 'Recuperação de Senha - ' . ($_ENV['APP_NAME'] ?? 'Chama Frete');

            // Template HTML mais amigável
            $appName = $_ENV['APP_NAME'] ?? 'Chama Frete';
            $mail->Body = "
                <div style='font-family: sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #eee; padding: 20px;'>
                    <h2 style='color: #2c3e50;'>Recuperação de Acesso</h2>
                    <p>Olá,</p>
                    <p>Você solicitou a recuperação de senha para sua conta no <strong>{$appName}</strong>. Use o código abaixo para prosseguir:</p>
                    <div style='background: #f8f9fa; padding: 15px; text-align: center; border-radius: 5px; margin: 20px 0;'>
                        <span style='font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #007bff;'>{$token}</span>
                    </div>
                    <p style='color: #666; font-size: 14px;'>Este código é válido por <strong>15 minutos</strong>.</p>
                    <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                    <p style='font-size: 12px; color: #999;'>Se você não solicitou esta alteração, ignore este e-mail. Sua senha permanecerá a mesma.</p>
                </div>
            ";

            // Versão em Texto Puro (Fallback para leitores de e-mail antigos e anti-spam)
            $mail->AltBody = "Seu código de recuperação no {$appName} é: {$token}. Válido por 15 minutos.";

            return $mail->send();

        } catch (Exception $e) {
            error_log("Erro ao enviar e-mail de reset para {$to}: " . $e->getMessage());
            return false;
        }
    }

    private function sendWelcomeEmail($to, $name) {
        try {
            $mail = $this->getMailer();
            $mail->addAddress($to);
            $mail->isHTML(true);
            
            $appName = $_ENV['APP_NAME'] ?? 'Chama Frete';
            $mail->Subject = "Bem-vindo ao {$appName}, {$name}! 🚛";

            // Template de Boas-vindas
            $mail->Body = "
                <div style='font-family: sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #eee; padding: 20px;'>
                    <div style='text-align: center; margin-bottom: 20px;'>
                        <h1 style='color: #007bff; margin: 0;'>Bem-vindo a bordo!</h1>
                    </div>
                    
                    <p>Olá, <strong>{$name}</strong>,</p>
                    <p>É um prazer ter você no <strong>{$appName}</strong>. Sua conta foi criada com sucesso e já está pronta para uso.</p>
                    
                    <div style='background: #fdfdfd; border-left: 4px solid #007bff; padding: 15px; margin: 20px 0;'>
                        <p style='margin: 0; font-weight: bold;'>O que você pode fazer agora?</p>
                        <ul style='margin: 10px 0 0 0; padding-left: 20px; color: #444;'>
                            <li>Completar seu perfil profissional.</li>
                            <li>Anunciar ou buscar fretes disponíveis.</li>
                            <li>Verificar sua conta para ganhar mais confiança.</li>
                        </ul>
                    </div>

                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='{$_ENV['APP_URL']}/login' 
                        style='background: #007bff; color: #fff; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;'>
                        Acessar Minha Conta
                        </a>
                    </div>

                    <p style='font-size: 14px; color: #666;'>Se tiver qualquer dúvida, basta responder a este e-mail. Nossa equipe está pronta para ajudar!</p>
                    
                    <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                    <p style='font-size: 11px; color: #999; text-align: center;'>
                        © " . date('Y') . " {$appName}. Todos os direitos reservados.
                    </p>
                </div>
            ";

            // Versão em texto puro para maior entregabilidade
            $mail->AltBody = "Olá {$name}, bem-vindo ao {$appName}! Sua conta foi criada com sucesso. Acesse {$_ENV['APP_URL']}/login para começar.";

            return $mail->send();

        } catch (Exception $e) {
            error_log("Erro ao enviar boas-vindas para {$to}: " . $e->getMessage());
            return false;
        }
    }
}
