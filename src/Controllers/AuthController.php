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
        $password = $data['password'] ?? '';

        if (empty($loginIdentifier) || empty($password)) {
            return Response::json(["success" => false, "message" => "Usuário e senha são obrigatórios"], 400);
        }

        $searchKey = (strpos($loginIdentifier, '@') === false) 
            ? preg_replace('/\D/', '', $loginIdentifier) 
            : $loginIdentifier;

        // Busca o usuário no repositório
        $user = $this->userRepo->findByEmailOrWhatsapp($searchKey);

        // 1. Verificação de existência e senha
        if (!$user || !password_verify($password, $user['password'])) {
            return Response::json(["success" => false, "message" => "E-mail/WhatsApp ou senha incorretos"], 401);
        }
        // 2. Tratamento do Status (Evita a mensagem vazia)
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

        // 3. Preparação do Token JWT
        $issuedAt = time();
        $expire = $issuedAt + (int)($_ENV['JWT_EXPIRE'] ?? 86400); 

        $payload = [
            "iat" => $issuedAt,
            "exp" => $expire,
            "sub" => $user['id'],
            "data" => [
                "id"    => $user['id'],
                "email" => $user['email'],
                "role"  => strtoupper($user['role'] ?? 'DRIVER'),
                "type"  => $user['user_type'] ?? 'motorista'
            ]
        ];

        try {
            // Geração do token
            $jwt = JWT::encode($payload, $this->secret, 'HS256');
            
            // Atualiza último login sem travar o processo se falhar
            try {
                $this->userRepo->updateLastLogin($user['id']);
            } catch (Exception $e) {
                error_log("Erro ao atualizar last_login: " . $e->getMessage());
            }

            return Response::json([
                "success" => true,
                "token"   => $jwt,
                "user"    => [
                    "id"          => (int)$user['id'],
                    "name"        => $user['name'],
                    "role"        => strtoupper($user['role']),
                    "type" => $user['user_type'],
                    "is_verified" => filter_var($user['is_verified'] ?? false),
                    "avatar"      => $user['avatar_url'] ?? null
                ]
            ]);

        } catch (Exception $e) {
            error_log("Erro JWT: " . $e->getMessage());
            return Response::json(["success" => false, "message" => "Erro ao processar acesso seguro"], 500);
        }
    }

    /**
     * Registro de Usuário (Motorista ou Empresa)
     */
    public function register($data) {
        try {
            // 1. Captura e Sanitização
            $name     = strip_tags(trim($data['name'] ?? ''));
            $email    = strtolower(trim($data['email'] ?? ''));
            $whatsapp = preg_replace('/\D/', '', $data['whatsapp'] ?? '');
            $password = $data['password'] ?? '';
            $role     = strtolower($data['role'] ?? 'driver'); 

            // 2. Validações
            if (empty($name) || empty($email) || empty($password)) {
                return Response::json(["success" => false, "message" => "Dados obrigatórios faltando"], 400);
            }

            // 3. O PULO DO GATO: Criação do user_type para o Banco
            // React manda 'driver' -> PHP traduz para 'motorista'
            // React manda 'company' -> PHP traduz para 'empresa'
            $userTypeMap = [
                'driver'  => 'motorista',
                'company' => 'empresa'
            ];
            $userType = $userTypeMap[$role] ?? 'motorista';

            // 4. Preparação do Array para o Repository
            $preparedData = [
                'name'      => $name,
                'email'     => $email,
                'whatsapp'  => $whatsapp,
                'role'      => $role,      // Salva 'driver'
                'user_type' => $userType,  // Salva 'motorista' ou 'empresa'
                'password'  => password_hash($password, PASSWORD_BCRYPT),
                'rating_avg'=> 5.00
            ];

            // 5. Chamada do Repository
            $userId = $this->userRepo->create($preparedData);

            // Criação da Empresa (Silenciosa e Segura)
            if ($userId && $userType === 'empresa') {
                // Passamos a responsabilidade para o repository que já tem a conexão
                $this->userRepo->createCompanyRecord($userId, $name);
            }

            return Response::json([
                "success" => true, 
                "message" => "Cadastro realizado com sucesso!",
                "userId"  => $userId
            ], 201);

        } catch (Exception $e) {
            error_log("Erro no registro: " . $e->getMessage());
            return Response::json([
                "success" => false, 
                "message" => "Erro no servidor: " . $e->getMessage()
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