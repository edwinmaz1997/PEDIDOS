<?php
// ============================================================
// API Bootstrap — Entry point for all API requests
// ============================================================

// Load config (rename config.example.php → config.php)
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/Security.php';
require_once __DIR__ . '/helpers/Response.php';
require_once __DIR__ . '/middleware/AuthMiddleware.php';

// Controllers
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/BusinessController.php';
require_once __DIR__ . '/controllers/ProductController.php';
require_once __DIR__ . '/controllers/OrderController.php';
require_once __DIR__ . '/controllers/DeliveryController.php';
require_once __DIR__ . '/controllers/AdminController.php';
require_once __DIR__ . '/controllers/NotificationController.php';

// --------------------------------------------------------
// CORS Headers
// --------------------------------------------------------
$allowedOrigins = [APP_URL];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins) || APP_ENV === 'development') {
    header('Access-Control-Allow-Origin: ' . ($APP_ENV === 'development' ? '*' : $origin));
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --------------------------------------------------------
// Security Headers
// --------------------------------------------------------
Security::setSecurityHeaders();

// --------------------------------------------------------
// Rate Limiting (global)
// --------------------------------------------------------
$ip = Security::getClientIp();
$endpoint = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!Security::checkRateLimit($ip, $endpoint)) {
    Response::error('Demasiadas solicitudes. Intenta de nuevo en un momento.', 429);
}

// --------------------------------------------------------
// Router
// --------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = rtrim(preg_replace('/\/+/', '/', $uri), '/');

// Extract path after /api/
$basePath = '/api';
if (strpos($uri, $basePath) === 0) {
    $uri = substr($uri, strlen($basePath));
}

$parts = explode('/', ltrim($uri, '/'));
$resource = $parts[0] ?? '';
$id = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
$action = isset($parts[1]) && !is_numeric($parts[1]) ? $parts[1] : null;
if ($id && isset($parts[2])) $action = $parts[2];

// Parse JSON body
$body = [];
$rawInput = file_get_contents('php://input');
if (!empty($rawInput)) {
    $body = json_decode($rawInput, true) ?? [];
}
$body = Security::sanitize($body);

// --------------------------------------------------------
// Route Definitions
// --------------------------------------------------------
try {
    switch ($resource) {

        // AUTH
        case 'auth':
            $ctrl = new AuthController();
            match($action) {
                'login'    => $ctrl->login($body),
                'register' => $ctrl->register($body),
                'logout'   => $ctrl->logout(),
                'me'       => $ctrl->me(),
                default    => Response::notFound('Ruta no encontrada')
            };
            break;

        // BUSINESSES
        case 'businesses':
            $ctrl = new BusinessController();
            if ($method === 'GET' && !$id)        $ctrl->index();
            elseif ($method === 'GET' && $id)     $ctrl->show($id);
            elseif ($method === 'POST' && !$id)   $ctrl->store($body);
            elseif ($method === 'PUT' && $id)     $ctrl->update($id, $body);
            elseif ($method === 'DELETE' && $id)  $ctrl->destroy($id);
            elseif ($action === 'search')         $ctrl->search();
            elseif ($action === 'by-category')    $ctrl->byCategory($id);
            else Response::notFound();
            break;

        // PRODUCTS/SERVICES
        case 'products':
            $ctrl = new ProductController();
            if ($method === 'GET' && $id && !$action)      $ctrl->show($id);
            elseif ($method === 'GET' && !$id)             $ctrl->index();
            elseif ($method === 'POST')                    $ctrl->store($body);
            elseif ($method === 'PUT' && $id)              $ctrl->update($id, $body);
            elseif ($method === 'DELETE' && $id)           $ctrl->destroy($id);
            else Response::notFound();
            break;

        // ORDERS
        case 'orders':
            $ctrl = new OrderController();
            if ($method === 'GET' && !$id)          $ctrl->index();
            elseif ($method === 'GET' && $id)       $ctrl->show($id);
            elseif ($method === 'POST' && !$id)     $ctrl->store($body);
            elseif ($method === 'PUT' && $id)       $ctrl->update($id, $body);
            elseif ($action === 'respond')          $ctrl->businessRespond($id, $body);
            elseif ($action === 'status')           $ctrl->updateStatus($id, $body);
            else Response::notFound();
            break;

        // DELIVERIES
        case 'deliveries':
            $ctrl = new DeliveryController();
            if ($method === 'GET')                  $ctrl->index();
            elseif ($method === 'PUT' && $id)       $ctrl->update($id, $body);
            elseif ($action === 'assign')           $ctrl->assign($id, $body);
            else Response::notFound();
            break;

        // ADMIN
        case 'admin':
            $ctrl = new AdminController();
            match($action) {
                'dashboard'  => $ctrl->dashboard(),
                'users'      => $ctrl->users(),
                'businesses' => $ctrl->businesses(),
                'orders'     => $ctrl->orders(),
                default      => Response::notFound()
            };
            break;

        // NOTIFICATIONS
        case 'notifications':
            $ctrl = new NotificationController();
            if ($method === 'GET')              $ctrl->index();
            elseif ($action === 'read')         $ctrl->markRead($id);
            elseif ($action === 'read-all')     $ctrl->markAllRead();
            else Response::notFound();
            break;

        // CATEGORIES
        case 'categories':
            $db = Database::connect();
            $cats = $db->query("SELECT * FROM business_categories WHERE is_active = 1 ORDER BY name")->fetchAll();
            Response::success($cats);
            break;

        // UPLOAD
        case 'upload':
            if ($method !== 'POST') Response::error('Método no permitido', 405);
            AuthMiddleware::authenticate();
            $file = $_FILES['image'] ?? null;
            if (!$file) Response::error('No se recibió ningún archivo');
            $folder = Security::sanitize($_POST['folder'] ?? 'general');
            $url = Security::saveImage($file, $folder);
            if (!$url) Response::error('Error al guardar la imagen');
            Response::success(['url' => $url]);
            break;

        default:
            Response::notFound('Endpoint no encontrado');
    }
} catch (Throwable $e) {
    error_log('API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    Response::serverError(APP_ENV === 'development' ? $e->getMessage() : 'Error interno del servidor');
}
