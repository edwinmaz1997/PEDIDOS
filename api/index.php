<?php
// ============================================================
// API Bootstrap — Entry point for all API requests
// ============================================================

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/Security.php';
require_once __DIR__ . '/helpers/Response.php';
require_once __DIR__ . '/middleware/AuthMiddleware.php';

require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/BusinessController.php';
require_once __DIR__ . '/controllers/ProductController.php';
require_once __DIR__ . '/controllers/OrderController.php';
require_once __DIR__ . '/controllers/DeliveryController.php';
require_once __DIR__ . '/controllers/AdminNotificationController.php';
require_once __DIR__ . '/controllers/PhotoController.php';

// ── CORS ─────────────────────────────────────────────────────
$allowedOrigins = ['https://nuevaexpress.com', 'https://www.nuevaexpress.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins) || (defined('APP_ENV') && APP_ENV === 'development')) {
    header('Access-Control-Allow-Origin: ' . (APP_ENV === 'development' ? '*' : $origin));
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
header('Access-Control-Max-Age: 86400');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Security Headers ─────────────────────────────────────────
Security::setSecurityHeaders();

// ── Rate Limiting ─────────────────────────────────────────────
$ip       = Security::getClientIp();
$endpoint = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!Security::checkRateLimit($ip, $endpoint)) {
    Response::error('Demasiadas solicitudes. Intenta de nuevo en un momento.', 429);
}

// ── Router ───────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = rtrim(preg_replace('/\/+/', '/', $uri), '/');

$basePath = '/api';
if (strpos($uri, $basePath) === 0) $uri = substr($uri, strlen($basePath));

$parts    = explode('/', ltrim($uri, '/'));
$resource = $parts[0] ?? '';
$id       = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
$action   = isset($parts[1]) && !is_numeric($parts[1]) ? $parts[1] : null;
$subId    = isset($parts[2]) && is_numeric($parts[2]) ? (int)$parts[2] : null;
$subAction= isset($parts[2]) && !is_numeric($parts[2]) ? $parts[2] : null;
if ($id && isset($parts[2])) { $subAction = $parts[2]; $subId = isset($parts[3]) ? (int)$parts[3] : null; }

// Parse JSON body
$body = [];
$raw = file_get_contents('php://input');
if (!empty($raw)) $body = json_decode($raw, true) ?? [];
$body = Security::sanitize($body);

try {
    switch ($resource) {

        case 'auth':
            $ctrl = new AuthController();
            match($action) {
                'login'    => $ctrl->login($body),
                'register' => $ctrl->register($body),
                'logout'   => $ctrl->logout(),
                'me'       => $ctrl->me(),
                default    => Response::notFound()
            };
            break;

        case 'businesses':
            $ctrl = new BusinessController();
            $photoCtrl = new PhotoController();

            // /businesses/{id}/photos/{photoId}
            if ($id && $subAction === 'photos') {
                $user = AuthMiddleware::authenticate();
                if ($method === 'POST')   $photoCtrl->store($id, $body);
                elseif ($method === 'DELETE' && $subId) $photoCtrl->destroy($id, $subId, $user);
                else Response::notFound();
                break;
            }
            // /businesses/search
            if ($action === 'search')       { $ctrl->search(); break; }
            // /businesses/by-category/{id}
            if ($action === 'by-category')  { $ctrl->byCategory((int)($parts[2]??0)); break; }

            if ($method === 'GET' && !$id)       $ctrl->index();
            elseif ($method === 'GET' && $id)    $ctrl->show($id);
            elseif ($method === 'POST' && !$id)  $ctrl->store($body);
            elseif ($method === 'PUT' && $id)    $ctrl->update($id, $body);
            elseif ($method === 'DELETE' && $id) $ctrl->destroy($id);
            else Response::notFound();
            break;

        case 'products':
            $ctrl = new ProductController();
            if ($method === 'GET' && !$id)       $ctrl->index();
            elseif ($method === 'GET' && $id)    $ctrl->show($id);
            elseif ($method === 'POST')          $ctrl->store($body);
            elseif ($method === 'PUT' && $id)    $ctrl->update($id, $body);
            elseif ($method === 'DELETE' && $id) $ctrl->destroy($id);
            else Response::notFound();
            break;

        case 'orders':
            $ctrl = new OrderController();
            if ($method === 'GET' && !$id)          $ctrl->index();
            elseif ($method === 'GET' && $id)        $ctrl->show($id);
            elseif ($method === 'POST' && !$id)      $ctrl->store($body);
            elseif ($subAction === 'respond')        $ctrl->businessRespond($id, $body);
            elseif ($subAction === 'status')         $ctrl->updateStatus($id, $body);
            elseif ($method === 'PUT' && $id)        $ctrl->updateStatus($id, $body);
            else Response::notFound();
            break;

        case 'deliveries':
            $ctrl = new DeliveryController();
            if ($method === 'GET')                   $ctrl->index();
            elseif ($subAction === 'assign')         $ctrl->assign($id, $body);
            elseif ($method === 'PUT' && $id)        $ctrl->update($id, $body);
            else Response::notFound();
            break;

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

        case 'notifications':
            $ctrl = new NotificationController();
            if ($method === 'GET')              $ctrl->index();
            elseif ($subAction === 'read')      $ctrl->markRead($id);
            elseif ($action === 'read-all')     $ctrl->markAllRead();
            else Response::notFound();
            break;

        case 'categories':
            $db = Database::connect();
            $cats = $db->query("SELECT * FROM business_categories WHERE is_active = 1 ORDER BY name")->fetchAll();
            Response::success($cats);
            break;

        case 'upload':
            if ($method !== 'POST') Response::error('Método no permitido', 405);
            AuthMiddleware::authenticate();
            $file   = $_FILES['image'] ?? null;
            $folder = Security::sanitize($_POST['folder'] ?? 'general');
            if (!$file) Response::error('No se recibió ningún archivo');
            $url = Security::saveImage($file, $folder);
            if (!$url) Response::error('Error al guardar la imagen');
            Response::success(['url' => $url]);
            break;

        default:
            Response::notFound('Endpoint no encontrado');
    }
} catch (Throwable $e) {
    error_log('API Error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::serverError(defined('APP_ENV') && APP_ENV === 'development' ? $e->getMessage() : 'Error interno del servidor');
}
