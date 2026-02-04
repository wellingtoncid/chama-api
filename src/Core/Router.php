<?php
namespace App\Core;

use App\Services\NotificationService;
use App\Repositories\FreightRepository;
use App\Repositories\ChatRepository;
use App\Repositories\MetricsRepository;
use App\Repositories\AdRepository;
use App\Repositories\GroupRepository;
use App\Repositories\ListingRepository;

class Router {
    private $uri;
    private $method;
    private $routes = [];

    public function __construct($uri, $method) {
        // 1. Pega apenas o caminho da URL (remove query strings)
        $path = parse_url($uri, PHP_URL_PATH);

        // 2. Normaliza o prefixo /api
        if (strpos($path, '/api') !== false) {
            $path = strstr($path, '/api');
        }

        $this->uri = '/' . trim($path, '/');
        $this->method = strtoupper($method);
    }

    public function post($path, $handler) { 
        $this->routes['POST']['/' . trim($path, '/')] = $handler; 
    }
    
    public function get($path, $handler) { 
        $this->routes['GET']['/' . trim($path, '/')] = $handler; 
    }

    public function put($path, $handler) { 
        $this->routes['PUT']['/' . trim($path, '/')] = $handler; 
    }

    public function delete($path, $handler) { 
        $this->routes['DELETE']['/' . trim($path, '/')] = $handler; 
    }

    public function run($db, $loggedUser = null, $data = []) {
        $matchedHandler = null;
        $params = [];

        // Verifica se existem rotas para o método solicitado
        if (!isset($this->routes[$this->method])) {
            http_response_code(404);
            return ["success" => false, "error" => "Método não suportado"];
        }

        // Tenta encontrar uma rota que case com a URI atual
        foreach ($this->routes[$this->method] as $routePath => $handler) {
            // Converte padrões como :slug em regex (captura tudo até a próxima barra)
            $pattern = preg_replace('/:[a-zA-Z0-9_]+/', '([^/]+)', $routePath);
            $pattern = str_replace('/', '\/', $pattern);

            if (preg_match('/^' . $pattern . '$/', $this->uri, $matches)) {
                $matchedHandler = $handler;

                // Extrai os nomes das variáveis da rota (ex: slug)
                preg_match_all('/:([a-zA-Z0-9_]+)/', $routePath, $paramNames);
                
                // Associa os valores capturados na URL aos nomes das variáveis
                foreach ($paramNames[1] as $index => $name) {
                    $data[$name] = $matches[$index + 1];
                }
                break;
            }
        }

        if (!$matchedHandler) {
            http_response_code(404);
            return ["success" => false, "error" => "Rota {$this->uri} não encontrada"];
        }

        [$controllerName, $method] = explode('@', $matchedHandler);
        $controllerClass = "App\\Controllers\\$controllerName";

        if (!class_exists($controllerClass)) {
            http_response_code(500);
            return ["success" => false, "error" => "Controller $controllerClass não encontrado"];
        }

        // --- Injeção de Dependências ---
        $notificationService = new NotificationService($db);
        $chatRepo    = new ChatRepository($db);
        $metricsRepo = new MetricsRepository($db);
        $freightRepo = new FreightRepository($db);
        $adRepo      = new AdRepository($db);
        $groupRepo   = new GroupRepository($db);
        $listingRepo = new ListingRepository($db);

        switch ($controllerName) {
            case 'FreightController':
                $freightRepo = new FreightRepository($db);
                $controller = new $controllerClass($freightRepo, $notificationService, $chatRepo);
                break;

            case 'MetricsController':
                $controller = new $controllerClass(
                    $metricsRepo, 
                    $freightRepo, 
                    $adRepo, 
                    $groupRepo, 
                    $listingRepo
                );
                break;    

            case 'ChatController':
                $controller = new $controllerClass($db); 
                break;

            case 'NotificationController':
                $controller = new $controllerClass($notificationService);
                break;

            case 'ReviewController':
                $controller = new $controllerClass($db);
                break;

            case 'AdController':
                $controller = new $controllerClass($db);
                break;

            default:
                $controller = new $controllerClass($db);
                break;
        }
        
        if (!method_exists($controller, $method)) {
            http_response_code(405);
            return ["success" => false, "error" => "Método $method não encontrado no controller"];
        }

        // O $data agora contém tanto o que veio via JSON/POST quanto o :slug da URL
        return $controller->$method($data, $loggedUser);
    }
}