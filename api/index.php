<?php
// ============================================================
// API Bootstrap — NuevaExpress
// ============================================================
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/Security.php';
require_once __DIR__ . '/helpers/Response.php';
require_once __DIR__ . '/middleware/AuthMiddleware.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/PasswordResetController.php';
require_once __DIR__ . '/controllers/UserController.php';
require_once __DIR__ . '/controllers/BusinessController.php';
require_once __DIR__ . '/controllers/ProductController.php';
require_once __DIR__ . '/controllers/OrderController.php';
require_once __DIR__ . '/controllers/DeliveryController.php';
require_once __DIR__ . '/controllers/AdminNotificationController.php';
require_once __DIR__ . '/controllers/PhotoController.php';

// CORS
$allowedOrigins = ['https://nuevaexpress.com','https://www.nuevaexpress.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins) || APP_ENV === 'development') {
    header('Access-Control-Allow-Origin: ' . (APP_ENV === 'development' ? '*' : $origin));
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
header('Access-Control-Max-Age: 86400');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

Security::setSecurityHeaders();

// Rate limiting — skip for static/health
$ip       = Security::getClientIp();
$endpoint = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!Security::checkRateLimit($ip, $endpoint)) {
    Response::error('Demasiadas solicitudes. Intenta de nuevo en un momento.', 429);
}

// Router
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = rtrim(preg_replace('/\/+/', '/', $uri), '/');
$base   = '/api';
if (strpos($uri, $base) === 0) $uri = substr($uri, strlen($base));
$parts     = explode('/', ltrim($uri, '/'));
$resource  = $parts[0] ?? '';
$id        = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
$action    = isset($parts[1]) && !is_numeric($parts[1]) ? $parts[1] : null;
$subAction = null; $subId = null;
if ($id   && isset($parts[2])) { $subAction = is_numeric($parts[2]) ? null : $parts[2]; $subId = is_numeric($parts[2]) ? (int)$parts[2] : (isset($parts[3]) ? (int)$parts[3] : null); }
if ($action && isset($parts[2])) { $subAction = $parts[2]; }

// Parse body
$body = [];
$raw  = file_get_contents('php://input');
if (!empty($raw)) $body = json_decode($raw, true) ?? [];
$body = Security::sanitize($body);

try {
    switch ($resource) {

        // ── AUTH ──────────────────────────────────────────────
        case 'auth':
            $ctrl = new AuthController();
            $pwCtrl = new PasswordResetController();
            match($action) {
                'login'            => $ctrl->login($body),
                'register'         => $ctrl->register($body),
                'logout'           => $ctrl->logout(),
                'me'               => $ctrl->me(),
                'forgot-password'  => $pwCtrl->forgot($body),
                'reset-password'   => $pwCtrl->reset($body),
                'verify-reset-token' => $pwCtrl->verifyToken($body),
                default            => Response::notFound()
            };
            break;

        // ── USER PROFILE ──────────────────────────────────────
        case 'user':
            $ctrl = new UserController();
            if ($action === 'profile' && $method === 'GET')  $ctrl->profile();
            elseif ($action === 'profile' && $method === 'PUT') $ctrl->updateProfile($body);
            elseif ($action === 'password' && $method === 'PUT') $ctrl->changePassword($body);
            else Response::notFound();
            break;

        // ── BUSINESSES ────────────────────────────────────────
        case 'businesses':
            $ctrl      = new BusinessController();
            $photoCtrl = new PhotoController();
            if ($id && $subAction === 'photos') {
                $user = AuthMiddleware::authenticate();
                if ($method === 'POST')                        $photoCtrl->store($id, $body);
                elseif ($method === 'DELETE' && $subId)        $photoCtrl->destroy($id, $subId, $user);
                else Response::notFound();
                break;
            }
            if ($action === 'search')                          { $ctrl->search(); break; }
            if ($action === 'by-category')                     { $ctrl->byCategory((int)($parts[2]??0)); break; }
            if ($method === 'GET'    && !$id)                  $ctrl->index();
            elseif ($method === 'GET'    && $id)               $ctrl->show($id);
            elseif ($method === 'POST'   && !$id)              $ctrl->store($body);
            elseif ($method === 'PUT'    && $id)               $ctrl->update($id, $body);
            elseif ($method === 'DELETE' && $id)               $ctrl->destroy($id);
            else Response::notFound();
            break;

        // ── PRODUCTS ──────────────────────────────────────────
        case 'products':
            $ctrl = new ProductController();
            if ($method === 'GET'    && !$id)   $ctrl->index();
            elseif ($method === 'GET'    && $id) $ctrl->show($id);
            elseif ($method === 'POST')          $ctrl->store($body);
            elseif ($method === 'PUT'    && $id) $ctrl->update($id, $body);
            elseif ($method === 'DELETE' && $id) $ctrl->destroy($id);
            else Response::notFound();
            break;

        // ── ORDERS ────────────────────────────────────────────
        case 'orders':
            $ctrl = new OrderController();
            if ($method === 'GET'  && !$id)           $ctrl->index();
            elseif ($method === 'GET'  && $id)         $ctrl->show($id);
            elseif ($method === 'POST' && !$id)        $ctrl->store($body);
            elseif ($subAction === 'respond')          $ctrl->businessRespond($id, $body);
            elseif ($subAction === 'status' || ($method === 'PUT' && $id)) $ctrl->updateStatus($id, $body);
            else Response::notFound();
            break;

        // ── DELIVERIES ────────────────────────────────────────
        case 'deliveries':
            $ctrl = new DeliveryController();
            if ($method === 'GET')                $ctrl->index();
            elseif ($subAction === 'assign')      $ctrl->assign($id, $body);
            elseif ($method === 'PUT' && $id)     $ctrl->update($id, $body);
            else Response::notFound();
            break;

        // ── ADMIN ─────────────────────────────────────────────
        case 'admin':
            $ctrl     = new AdminController();
            $userCtrl = new UserController();
            // /admin/users/{id}/toggle
            if ($action === 'users' && $id && $subAction === 'toggle') {
                $userCtrl->adminToggle($id);
                break;
            }
            match($action) {
                'dashboard'  => $ctrl->dashboard(),
                'users'      => $ctrl->users(),
                'businesses' => $ctrl->businesses(),
                'orders'     => $ctrl->orders(),
                default      => Response::notFound()
            };
            break;

        // ── NOTIFICATIONS ─────────────────────────────────────
        case 'notifications':
            $ctrl = new NotificationController();
            if ($method === 'GET')             $ctrl->index();
            elseif ($subAction === 'read')     $ctrl->markRead($id);
            elseif ($action === 'read-all')    $ctrl->markAllRead();
            else Response::notFound();
            break;

        // ── CATEGORIES ────────────────────────────────────────
        case 'categories':
            $db = Database::connect();
            Response::success($db->query("SELECT * FROM business_categories WHERE is_active=1 ORDER BY name")->fetchAll());
            break;

        // ── UPLOAD ────────────────────────────────────────────
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

        // ── HEALTH CHECK ──────────────────────────────────────
        case 'health':
            Response::success(['status' => 'ok', 'version' => '1.2', 'domain' => APP_URL]);
            break;

        default:
            Response::notFound('Endpoint no encontrado');
    }
} catch (Throwable $e) {
    error_log('[NuevaExpress API] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::serverError(APP_ENV === 'development' ? $e->getMessage() : 'Error interno del servidor');
}
