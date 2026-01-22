<?php
ob_start();

/**
 * 1. CONFIGURAÇÕES DE CORS
 */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
// Adicionado suporte dinâmico para evitar bloqueios de CORS em produção ou local
header("Access-Control-Allow-Origin: $origin");
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
    if (file_exists(__DIR__ . '/.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__); 
        $dotenv->load();
    }

    // Configuração de Erros para Debug (Pode ser desligado em produção)
    ini_set('display_errors', 1); 
    error_reporting(E_ALL);

    // Requisitos de arquivos de estrutura
    require_once __DIR__ . '/config/Database.php';
    require_once __DIR__ . '/src/Models/User.php';
    
    // Controladores
    require_once __DIR__ . '/src/Controllers/AuthController.php';
    require_once __DIR__ . '/src/Controllers/FreightController.php';
    require_once __DIR__ . '/src/Controllers/AdminController.php'; 
    require_once __DIR__ . '/src/Controllers/AdController.php';
    require_once __DIR__ . '/src/Controllers/NotificationController.php';
    require_once __DIR__ . '/src/Controllers/ReviewController.php';
    require_once __DIR__ . '/src/Controllers/GroupController.php';
    require_once __DIR__ . '/src/Controllers/UserController.php';
    require_once __DIR__ . '/src/Services/MercadoPagoService.php';
    require_once __DIR__ . '/src/Controllers/ListingsController.php';
    require_once __DIR__ . '/src/Controllers/PaymentController.php';

    /**
     * 3. PREPARAÇÃO DA REQUISIÇÃO (CAPTURAR JSON OU FORM-DATA)
     */
    $db = Database::getConnection();
    $method = $_SERVER['REQUEST_METHOD'];
    // Captura o corpo da requisição JSON
    $input = file_get_contents("php://input");
    $jsonData = json_decode($input, true) ?? [];
    // Mescla TUDO para garantir que nada falte: JSON + $_POST (Multipart) + $_GET (Query Params)
    // Isso é vital para que uploads de imagens funcionem junto com campos de texto
    $data = array_merge($jsonData, $_POST, $_GET);

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
    
    $loggedUser = (empty($token) || $token === 'undefined' || $token === 'null') ? null : $auth->validateToken($token);

    /**
     * 5. ROTEAMENTO UNIVERSAL
     */
    switch ($endpoint) {
        
        case 'get-advertising-plans':
            $adminCtrl = new AdminController($db);
            echo json_encode($adminCtrl->getAdvertisingPlans());
            break;

        case 'login':
        case 'register':
        case 'reset-password': 
        case 'update-user-basic':
            echo json_encode($auth->handle($endpoint, $data));
            break;

        case 'freights':
            $controller = new FreightController($db);
            echo json_encode($controller->handle($method, $endpoint, $data, $loggedUser));
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

        case 'ads':
        case 'upload-ad':
        case 'manage-ads':
        case 'register-ad-event':
        case 'log-ad-view':
        case 'log-ad-click':
            $adCtrl = new AdController($db);
            echo json_encode($adCtrl->handle($method, $endpoint, $data));
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

        case 'listings':
        case 'my-listings':
        case 'create-listing':
        case 'update-listing':
        case 'delete-listing':
        case 'log-listing-activity':
            $listingCtrl = new ListingsController($db);
            echo json_encode($listingCtrl->handle($method, $endpoint, $data, $loggedUser));
            break;

        case 'process-checkout':
        case 'checkout':
            $paymentCtrl = new PaymentController($db, new MercadoPagoService($db));
            if ($method === 'POST') {
                echo json_encode($paymentCtrl->checkout($data));
            } else {
                http_response_code(405);
                echo json_encode(["error" => "Método não permitido"]);
            }
            break;
            
        case 'webhook-mp':
        case 'webhook-payment':
            $paymentCtrl = new PaymentController($db, new MercadoPagoService($db));
            echo json_encode($paymentCtrl->webhook());
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

        case 'my-services':
        case 'payment-history':
        case 'check-expirations':
            $membership = new MembershipController($db);
            echo json_encode($membership->handle($method, $endpoint, $data, $loggedUser));
            break;

        case 'admin-financial-report':
            if (!$loggedUser || strtoupper($loggedUser['role'] ?? '') !== 'ADMIN') {
                http_response_code(403);
                echo json_encode(["success" => false, "message" => "Acesso restrito."]);
            } else {
                $adminCtrl = new AdminController($db);
                echo json_encode($adminCtrl->handle($endpoint, $data));
            }

        case 'admin-dashboard-data': 
        case 'admin-stats':
        case 'admin-list-users':
        case 'admin-list-freights':
        case 'admin-audit-logs':
        case 'admin-click-logs':
        case 'admin-portal-requests':
        case 'admin-update-portal-request':   
        case 'admin-update-portal-request-details':
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
    http_response_code(500);
    echo json_encode([
        "error" => "Erro crítico no roteador", 
        "details" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ]);
}

ob_end_flush();