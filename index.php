<?php
ob_start();

/**
 * 1. CONFIGURAÇÕES DE CORS
 */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed_origins = ['http://localhost:5173', 'http://127.0.0.1:5173', 'https://seu-dominio.com'];

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: http://127.0.0.1:5173");
}

header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Origin, Accept");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json; charset=utf-8");

/**
 * 2. CARREGAMENTO DE DEPENDÊNCIAS
 */
require_once __DIR__ . '/vendor/autoload.php';

try {
    // Carrega o .env se existir
    if (file_exists(__DIR__ . '/.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__); 
        $dotenv->load();
    }

    ini_set('display_errors', 1); 
    error_reporting(E_ALL);

    // Requisitos de arquivos
    require_once __DIR__ . '/config/Database.php';
    require_once __DIR__ . '/src/Models/User.php';
    require_once __DIR__ . '/src/Controllers/AuthController.php';
    require_once __DIR__ . '/src/Controllers/FreightController.php';
    require_once __DIR__ . '/src/Controllers/AdminController.php'; 
    require_once __DIR__ . '/src/Controllers/AdController.php'; // NOVO CONTROLLER
    require_once __DIR__ . '/src/Services/MercadoPagoService.php';
    require_once __DIR__ . '/src/Controllers/NotificationController.php';
    require_once __DIR__ . '/src/Controllers/ReviewController.php';
    require_once __DIR__ . '/src/Controllers/GroupController.php';
    require_once __DIR__ . '/src/Controllers/UserController.php';

    /**
     * 3. PREPARAÇÃO DA REQUISIÇÃO
     */
    $db = Database::getConnection();
    $method = $_SERVER['REQUEST_METHOD'];

    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    if (!is_array($data)) {
        $data = array_merge($_GET, $_POST);
    }

    // Identificação do Endpoint
    $endpoint = $data['endpoint'] ?? $_GET['endpoint'] ?? null;
    if (!$endpoint) {
        $path = $_SERVER['PATH_INFO'] ?? explode('?', str_replace('/chama-frete/api/', '', $_SERVER['REQUEST_URI']))[0];
        $endpoint = trim($path, '/');
    }

    /**
     * 4. AUTENTICAÇÃO VIA JWT
     */
    $auth = new AuthController($db);
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);
    
    // Proteção: não valida se o token for inválido ou strings indesejadas
    $loggedUser = (empty($token) || $token === 'undefined' || $token === 'null') ? null : $auth->validateToken($token);

    /**
     * 5. ROTEAMENTO UNIVERSAL
     */
    switch ($endpoint) {
        
        case 'login':
        case 'register':
        case 'reset-password': 
        case 'update-user-basic':
            echo json_encode($auth->handle($endpoint, $data));
            break;

        case 'freights':
            $controller = new FreightController($db);
            // Se for GET, passamos o $_GET (que contém a busca)
            // Se for POST/PUT, passamos o $data (JSON do body)
            $payload = ($method === 'GET') ? $_GET : $data;
            echo json_encode($controller->handle($method, $endpoint, $payload, $loggedUser));
            break;
        case 'list-my-freights':
        case 'update-freight':
        case 'delete-freight':
        case 'get-user-posts':
        case 'toggle-favorite':
        case 'my-favorites':
        case 'register-click':
        case 'log-event':
        case 'track-metric':
        case 'get-interested-drivers': 
        case 'recommended-freights':
        case 'my-matches':
        case 'finish-freight':
            $freightCtrl = new FreightController($db);
            echo json_encode($freightCtrl->handle($method, $endpoint, $data, $loggedUser));
            break;

        // NOVA SEÇÃO DE ANÚNCIOS (Utilizando o AdController específico)
        case 'ads':
        case 'upload-ad':
        case 'manage-ads':
        case 'register-ad-event':
        case 'log-ad-view':
        case 'log-ad-click':
            $adCtrl = new AdController($db);
            // IMPORTANTE: Para o endpoint 'ads' (GET), precisamos dos dados da URL
            // Para 'manage-ads' (POST), precisamos do $data (JSON)
            $payload = ($endpoint === 'ads') ? array_merge($data, $_GET) : $data;
            echo json_encode($adCtrl->handle($method, $endpoint, $payload));
            break;

        case 'groups':
        case 'portal-request':
        case 'get-settings':
            $adminCtrl = new AdminController($db);
            echo json_encode($adminCtrl->handle($endpoint, $data));
            break;

        case 'get-public-profile':
        case 'get-by-slug':
        case 'save-profile':
        case 'get-my-profile':
        case 'check-slug':
        case 'upload-image':
            $userModel = new User($db);
            if ($endpoint === 'get-public-profile' || $endpoint === 'get-by-slug') {
                 $slug = $data['slug'] ?? $_GET['slug'] ?? '';
                 echo json_encode($userModel->getPublicProfile($slug));
            } else {
                 echo json_encode($userModel->handleProfile($endpoint, $data, $loggedUser));
            }
            break;

        case 'activate-free-verification':
            if (!$loggedUser) {
                http_response_code(401);
                echo json_encode(["error" => "Não autorizado"]);
            } else {
                $stmt = $db->prepare("UPDATE users SET is_verified = 1, verified_until = DATE_ADD(NOW(), INTERVAL 90 DAY) WHERE id = ?");
                $success = $stmt->execute([$loggedUser['id']]);
                echo json_encode(["success" => $success]);
            }
            break; 

        case 'process-checkout':
            $mpService = new MercadoPagoService($db);
            echo json_encode($mpService->createPreference($data, $loggedUser));
            break;

        case 'webhook-mp':
        case 'webhook-payment':
            $mpService = new MercadoPagoService($db);
            echo json_encode($mpService->handleNotification($data ?: $_GET));
            break;

        case 'list-notifications':
        case 'mark-as-read':
        case 'unread-count':
            echo json_encode((new NotificationController($db))->handle($endpoint, $data, $loggedUser));
            break;

        case 'submit-review':
        case 'get-user-reviews':
            echo json_encode((new ReviewController($db))->handle($endpoint, $data, $loggedUser));
            break;

        case 'list-groups':
        case 'manage-groups':
        case 'log-group-click':
            echo json_encode((new GroupController($db))->handle($method, $endpoint, $data, $loggedUser));
            break;

        case 'public-profile':
        case 'update-profile':
            echo json_encode((new UserController($db))->handle($method, $endpoint, $data, $loggedUser));
            break;

        // ÁREA ADMINISTRATIVA
        case 'admin-dashboard-data': 
        case 'admin-stats':
        case 'admin-list-users':
        case 'admin-list-freights':
        case 'admin-audit-logs':
        case 'admin-click-logs':
        case 'admin-portal-requests':
        case 'list-all-users':
        case 'manage-users-admin':
        case 'manage-freights-admin':
        case 'approve-freight':
        case 'reject-freight':
        case 'manage-plans':
        case 'update-settings':
        case 'create-user-admin':
            if (!$loggedUser || strtoupper($loggedUser['role'] ?? '') !== 'ADMIN') {
                http_response_code(403);
                echo json_encode(["success" => false, "message" => "Acesso restrito."]);
            } else {
                $adminCtrl = new AdminController($db);
                echo json_encode($adminCtrl->handle($endpoint, $data));
            }
            break;

        default:
            http_response_code(404);
            echo json_encode(["error" => "Endpoint não encontrado: " . $endpoint]);
            break;
    }
    
} catch (Throwable $e) {
    // Captura erros fatais e exceções
    http_response_code(500);
    echo json_encode([
        "error" => "Erro crítico no roteador", 
        "details" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ]);
}

ob_end_flush();