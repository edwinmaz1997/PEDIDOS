<?php
// ============================================================
// API Bootstrap — NuevaExpress — PHP 7.4 compatible
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
require_once __DIR__ . '/controllers/OrderMessageController.php';
require_once __DIR__ . '/controllers/DeliveryZoneController.php';
require_once __DIR__ . '/controllers/CategoryController.php';

// CORS
$allowedOrigins = ['https://nuevaexpress.com', 'https://www.nuevaexpress.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins) || APP_ENV === 'development') {
    header('Access-Control-Allow-Origin: ' . (APP_ENV === 'development' ? '*' : $origin));
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
header('Access-Control-Max-Age: 86400');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

Security::setSecurityHeaders();

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
$subAction = null;
$subId     = null;
if ($id && isset($parts[2])) {
    $subAction = !is_numeric($parts[2]) ? $parts[2] : null;
    $subId     =  is_numeric($parts[2]) ? (int)$parts[2] : (isset($parts[3]) ? (int)$parts[3] : null);
}
if ($action && isset($parts[2])) {
    $subAction = $parts[2];
}

$body = [];
$raw  = file_get_contents('php://input');
if (!empty($raw)) $body = json_decode($raw, true) ?? [];
$body = Security::sanitize($body);

try {
    switch ($resource) {

        // ── AUTH ─────────────────────────────────────────────
        case 'auth':
            $ctrl   = new AuthController();
            $pwCtrl = new PasswordResetController();
            switch ($action) {
                case 'login':              $ctrl->login($body);         break;
                case 'register':           $ctrl->register($body);      break;
                case 'logout':             $ctrl->logout();              break;
                case 'me':                 $ctrl->me();                  break;
                case 'forgot-password':    $pwCtrl->forgot($body);      break;
                case 'reset-password':     $pwCtrl->reset($body);       break;
                case 'verify-reset-token': $pwCtrl->verifyToken($body); break;
                default: Response::notFound();
            }
            break;

        // ── USER PROFILE ─────────────────────────────────────
        case 'user':
            $ctrl = new UserController();
            if ($action === 'profile' && $method === 'GET')         $ctrl->profile();
            elseif ($action === 'profile' && $method === 'PUT')     $ctrl->updateProfile($body);
            elseif ($action === 'password' && $method === 'PUT')    $ctrl->changePassword($body);
            else Response::notFound();
            break;

        // ── BUSINESSES ───────────────────────────────────────
        case 'businesses':
            $ctrl      = new BusinessController();
            $photoCtrl = new PhotoController();

            // /businesses/{id}/zones — business zone coverage
            if ($id && $subAction === 'zones') {
                $zoneCtrl = new DeliveryZoneController();
                if ($method === 'GET')  $zoneCtrl->bizZones($id);
                elseif ($method === 'POST') $zoneCtrl->bizUpdateZones($id, $body);
                else Response::notFound();
                break;
            }
            if ($id && $subAction === 'photos') {
                $user = AuthMiddleware::authenticate();
                if ($method === 'POST')                    $photoCtrl->store($id, $body);
                elseif ($method === 'DELETE' && $subId)    $photoCtrl->destroy($id, $subId, $user);
                else Response::notFound();
                break;
            }
            if ($action === 'search')       { $ctrl->search();                          break; }
            if ($action === 'by-category')  { $ctrl->byCategory((int)($parts[2]??0));   break; }
            if ($method === 'GET'    && !$id)    $ctrl->index();
            elseif ($method === 'GET'    && $id) $ctrl->show($id);
            elseif ($method === 'POST'   && !$id)$ctrl->store($body);
            elseif ($method === 'PUT'    && $id) $ctrl->update($id, $body);
            elseif ($method === 'DELETE' && $id) $ctrl->destroy($id);
            else Response::notFound();
            break;

        // ── PRODUCTS ─────────────────────────────────────────
        case 'products':
            $ctrl = new ProductController();
            if ($method === 'GET'    && !$id)    $ctrl->index();
            elseif ($method === 'GET'    && $id) $ctrl->show($id);
            elseif ($method === 'POST')          $ctrl->store($body);
            elseif ($method === 'PUT'    && $id) $ctrl->update($id, $body);
            elseif ($method === 'DELETE' && $id) $ctrl->destroy($id);
            else Response::notFound();
            break;

        // ── ORDERS ───────────────────────────────────────────
        case 'orders':
            $ctrl    = new OrderController();
            $msgCtrl = new OrderMessageController();

            // Re-parse URI directly to avoid any variable conflicts
            $uriParts   = explode('/', ltrim(str_replace('/api', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/'));
            $orderId2   = isset($uriParts[1]) && is_numeric($uriParts[1]) ? (int)$uriParts[1] : null;
            $orderSub   = isset($uriParts[2]) ? trim($uriParts[2]) : null;

            // /orders/unread
            if ($parts[1] === 'unread') { $msgCtrl->unreadCounts(); break; }

            // /orders/{id}/messages
            if ($orderId2 && $orderSub === 'messages' && $method === 'GET')  { $msgCtrl->index($orderId2);        break; }
            if ($orderId2 && $orderSub === 'messages' && $method === 'POST') { $msgCtrl->store($orderId2, $body); break; }

            // /orders/{id}/total
            if ($orderId2 && $orderSub === 'total' && $method === 'PUT') { $msgCtrl->updateTotal($orderId2, $body); break; }

            // /orders/{id}/respond
            if ($orderId2 && $orderSub === 'respond') { $ctrl->businessRespond($orderId2, $body); break; }

            // /orders/{id}/status
            if ($orderId2 && $orderSub === 'status') { $ctrl->updateStatus($orderId2, $body); break; }

            // Standard CRUD
            if ($method === 'GET'  && !$id)   $ctrl->index();
            elseif ($method === 'GET'  && $id) $ctrl->show($id);
            elseif ($method === 'POST' && !$id)$ctrl->store($body);
            elseif ($method === 'PUT'  && $id) $ctrl->updateStatus($id, $body);
            else Response::notFound();
            break;

        // ── DELIVERIES ───────────────────────────────────────
        case 'deliveries':
            $ctrl = new DeliveryController();
            if ($method === 'GET')               $ctrl->index();
            elseif ($subAction === 'assign')     $ctrl->assign($id, $body);
            elseif ($method === 'PUT' && $id)    $ctrl->update($id, $body);
            else Response::notFound();
            break;

        // ── ADMIN ────────────────────────────────────────────
        case 'admin':
            // /admin/assign-business
            if ($action === 'assign-business' && $method === 'POST') {
                $ctrl->assignBusiness($body['business_id'] ?? 0, $body['user_id'] ?? 0);
                break;
            }
            $ctrl     = new AdminController();
            $userCtrl = new UserController();
            // /api/admin/users/{id} — subAction holds the id when action is a word
            $adminUserId = null;
            if ($action === 'users' && isset($parts[2]) && is_numeric($parts[2])) {
                $adminUserId = (int)$parts[2];
            }
            $adminUserAction = isset($parts[3]) ? $parts[3] : null;

            if ($action === 'users' && $adminUserId && $adminUserAction === 'toggle') {
                $userCtrl->adminToggle($adminUserId);
                break;
            }
            if ($action === 'users' && $adminUserId && $method === 'PUT') {
                $userCtrl->adminUpdate($adminUserId, $body);
                break;
            }
            if ($action === 'users' && $adminUserId && $method === 'DELETE') {
                $userCtrl->adminDelete($adminUserId);
                break;
            }
            // Admin delivery zones CRUD
            $zoneCtrl2 = new DeliveryZoneController();
            if ($action === 'delivery-zones' && !$id && $method === 'GET')  { $zoneCtrl2->adminIndex(); break; }
            if ($action === 'delivery-zones' && !$id && $method === 'POST') { $zoneCtrl2->store($body); break; }
            $zoneAdminId = isset($parts[2]) && is_numeric($parts[2]) ? (int)$parts[2] : null;
            if ($action === 'delivery-zones' && $zoneAdminId && $method === 'PUT')    { $zoneCtrl2->update($zoneAdminId, $body); break; }
            if ($action === 'delivery-zones' && $zoneAdminId && $method === 'DELETE') { $zoneCtrl2->destroy($zoneAdminId); break; }

            // Admin categories CRUD
            $catCtrl = new CategoryController();
            if ($action === 'categories' && !$id && $method === 'GET')  { $catCtrl->index();  break; }
            if ($action === 'categories' && !$id && $method === 'POST') { $catCtrl->store($body); break; }
            $catId = isset($parts[2]) && is_numeric($parts[2]) ? (int)$parts[2] : null;
            if ($action === 'categories' && $catId && $method === 'PUT')    { $catCtrl->update($catId, $body); break; }
            if ($action === 'categories' && $catId && $method === 'DELETE') { $catCtrl->destroy($catId); break; }

            // DELETE /api/admin/orders/{id}
            $adminOrderId = isset($parts[2]) && is_numeric($parts[2]) ? (int)$parts[2] : null;
            if ($action === 'orders' && $adminOrderId && $method === 'DELETE') { $ctrl->deleteOrder($adminOrderId); break; }

            switch ($action) {
                case 'dashboard':  $ctrl->dashboard();  break;
                case 'users':      $ctrl->users();       break;
                case 'businesses': $ctrl->businesses();  break;
                case 'orders':     $ctrl->orders();      break;
                default: Response::notFound();
            }
            break;

        // ── NOTIFICATIONS ────────────────────────────────────
        case 'notifications':
            $ctrl = new NotificationController();
            if ($method === 'GET')              $ctrl->index();
            elseif ($subAction === 'read')      $ctrl->markRead($id);
            elseif ($action === 'read-all')     $ctrl->markAllRead();
            else Response::notFound();
            break;

        // ── CATEGORIES ───────────────────────────────────────
        case 'categories':
            $db = Database::connect();
            Response::success($db->query("SELECT * FROM business_categories WHERE is_active=1 ORDER BY name")->fetchAll());
            break;

        // ── UPLOAD ───────────────────────────────────────────
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

        // ── HEALTH ───────────────────────────────────────────
        case 'health':
            Response::success(['status' => 'ok', 'version' => '1.2', 'php' => PHP_VERSION, 'domain' => APP_URL]);
            break;

        default:
            Response::notFound('Endpoint no encontrado');
    }
} catch (Throwable $e) {
    error_log('[NuevaExpress] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::serverError(APP_ENV === 'development' ? $e->getMessage() : 'Error interno del servidor');
}
