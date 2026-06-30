<?php
// ============================================================
// API Bootstrap — NuevaExpress — PHP 7.4 compatible
// ============================================================
header('Content-Type: application/json; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/Security.php';
require_once __DIR__ . '/helpers/Response.php';
require_once __DIR__ . '/helpers/GeoHelper.php';
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
require_once __DIR__ . '/controllers/PromotionController.php';
require_once __DIR__ . '/helpers/PushNotification.php';
require_once __DIR__ . '/helpers/Mailer.php';
require_once __DIR__ . '/controllers/ProductCategoryController.php';
require_once __DIR__ . '/controllers/DeliveryZoneController.php';
require_once __DIR__ . '/controllers/BusinessServiceController.php';
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

// Skip rate limiting for chat polling endpoints
$skipRateLimit = (
    strpos($endpoint, '/messages') !== false ||
    strpos($endpoint, '/notifications') !== false
);

if (!$skipRateLimit && !Security::checkRateLimit($ip, $endpoint)) {
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

        // ── PROMOTIONS ────────────────────────────────────────
        case 'promotions':
            $pc = new PromotionController();
            $promoId = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
            $subAction = $parts[1] ?? null;
            if ($subAction === 'mine' && $method === 'GET')                  { $pc->mine(); break; }
            if (!$promoId && $method === 'GET')                              { $pc->index(); break; }
            if ($promoId  && $method === 'GET')                              { $pc->show($promoId); break; }
            if (!$promoId && $method === 'POST')                             { $pc->store($body); break; }
            if ($promoId  && $method === 'PUT')                              { $pc->update($promoId, $body); break; }
            if ($promoId  && $method === 'DELETE')                           { $pc->destroy($promoId); break; }
            Response::notFound();
            break;
        case 'reset-data':
            $rUser = AuthMiddleware::requireRole('admin');
            if ($method !== 'POST') Response::error('Método no permitido', 405);
            if (($body['confirm'] ?? '') !== 'CONFIRMAR_RESET') Response::error('Confirmación inválida', 400);
            $rDb = Database::connect();
            $rDb->exec("SET FOREIGN_KEY_CHECKS = 0");
            $rTables = ['order_status_log','order_messages','order_items','deliveries','notifications','email_verifications','orders'];
            $rCleaned = [];
            foreach ($rTables as $rT) {
                if ($rDb->query("SHOW TABLES LIKE '{$rT}'")->fetch()) {
                    $rDb->exec("DELETE FROM `{$rT}`");
                    try { $rDb->exec("ALTER TABLE `{$rT}` AUTO_INCREMENT = 1"); } catch(Exception $e) {}
                    $rCleaned[] = $rT;
                }
            }
            $rDb->exec("SET FOREIGN_KEY_CHECKS = 1");
            error_log("[RESET] Admin {$rUser['id']} limpió datos de prueba: " . implode(',', $rCleaned));
            Response::success(['cleaned' => $rCleaned], 'Base de datos limpiada correctamente.');
            break;
        case 'auth':
            $ctrl   = new AuthController();
            $pwCtrl = new PasswordResetController();
            switch ($action) {
                case 'login':              $ctrl->login($body);         break;
                case 'register':           $ctrl->register($body);      break;
                case 'logout':             $ctrl->logout();              break;
                case 'send-code':          $ctrl->sendCode($body);      break;
                case 'verify-code':        $ctrl->verifyCode($body);    break;
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

            // /businesses/{id}/services
            if ($id && $subAction === 'services') {
                $svcCtrl = new BusinessServiceController();
                if ($method === 'GET')                        $svcCtrl->index($id);
                elseif ($method === 'POST')                   $svcCtrl->store($id, $body);
                elseif ($method === 'PUT'    && $subId)       $svcCtrl->update($id, $subId, $body);
                elseif ($method === 'DELETE' && $subId)       $svcCtrl->destroy($id, $subId);
                else Response::notFound();
                break;
            }

            // /businesses/{id}/zones — business zone coverage
            if ($id && $subAction === 'zones') {
                $zoneCtrl = new DeliveryZoneController();
                if ($method === 'GET')  $zoneCtrl->bizZones($id);
                elseif ($method === 'POST') $zoneCtrl->bizUpdateZones($id, $body);
                else Response::notFound();
                break;
            }

            // /businesses/{id}/calculate-delivery — calcular tarifa por distancia
            if ($id && $subAction === 'calculate-delivery') {
                $ctrl = new BusinessController();
                if ($method === 'POST') $ctrl->calculateDelivery($id, $body);
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
            if ($action === 'mine')         { $ctrl->mine();                             break; }
            if ($method === 'GET'    && !$id)    $ctrl->index();
            elseif ($method === 'GET'    && $id) $ctrl->show($id);
            elseif ($method === 'POST'   && !$id)$ctrl->store($body);
            elseif ($method === 'PUT'    && $id) $ctrl->update($id, $body);
            elseif ($method === 'DELETE' && $id) $ctrl->destroy($id);
            else Response::notFound();
            break;

        // ── PRODUCTS ─────────────────────────────────────────
        case 'product-categories':
            $catCtrl = new ProductCategoryController();
            if ($method === 'GET')                        { $catCtrl->index(); break; }
            if ($method === 'POST')                       { $catCtrl->store($body); break; }
            if ($id && $method === 'PUT')                 { $catCtrl->update($id, $body); break; }
            if ($id && $method === 'DELETE')              { $catCtrl->destroy($id); break; }
            Response::notFound();
            break;

        case 'products':
            $ctrl = new ProductController();
            if ($method === 'POST'   && $action === 'bulk') $ctrl->bulkImport($body);
            elseif ($method === 'GET'    && !$id)           $ctrl->index();
            elseif ($method === 'GET'    && $id)            $ctrl->show($id);
            elseif ($method === 'POST')                     $ctrl->store($body);
            elseif ($method === 'PUT'    && $id)            $ctrl->update($id, $body);
            elseif ($method === 'DELETE' && $id)            $ctrl->destroy($id);
            else Response::notFound();
            break;

        // ── ORDERS ───────────────────────────────────────────
        case 'orders':
            $ctrl    = new OrderController();
            $msgCtrl = new OrderMessageController();

            // Re-parse URI directly to avoid any variable conflicts
            $uriParts   = explode('/', ltrim(str_replace('/api', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/'));
            $rawId2     = isset($uriParts[1]) ? explode('?', $uriParts[1])[0] : null;
            $orderId2   = $rawId2 && is_numeric($rawId2) ? (int)$rawId2 : null;
            $orderSub   = isset($uriParts[2]) ? trim(explode('?', $uriParts[2])[0]) : null;

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
            if ($method === 'GET'  && !$orderId2)   $ctrl->index();
            elseif ($method === 'GET'  && $orderId2) $ctrl->show($orderId2);
            elseif ($method === 'POST' && !$orderId2)$ctrl->store($body);
            elseif ($method === 'PUT'  && $orderId2) $ctrl->updateStatus($orderId2, $body);
            else Response::notFound();
            break;

        // ── DELIVERIES ───────────────────────────────────────
        case 'deliveries':
            $ctrl = new DeliveryController();
            if ($method === 'GET'  && $action === 'stats')                  { $ctrl->stats(); break; }
            if ($method === 'GET'  && !$id && !$action)                     { $ctrl->index(); break; }
            if ($id && $subAction === 'claim'   && $method === 'POST')      { $ctrl->claim($id); break; }
            if ($id && $subAction === 'release' && $method === 'POST')      { $ctrl->release($id); break; }
            if ($id && $subAction === 'status'  && $method === 'PUT')       { $ctrl->updateStatus($id, $body); break; }
            if ($id && $method === 'PUT')                                    { $ctrl->updateStatus($id, $body); break; }
            Response::notFound();
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

            // Avisos a negocios
            if ($action === 'avisos' && $method === 'POST') { $ctrl->sendAviso($body); break; }
            if ($action === 'avisos' && $method === 'GET')  { $ctrl->getAvisos(); break; }

            // POST /api/admin/reset-data — limpiar datos de prueba
            if ($action === 'reset-data' && $method === 'POST') { $ctrl->resetData($body); break; }

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
            $user = AuthMiddleware::authenticate();
            $db   = Database::connect();
            $notifId = isset($segments[1]) && is_numeric($segments[1]) ? (int)$segments[1] : null;

            if ($method === 'GET') {
                // Traer últimas 30 notificaciones
                $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 30");
                $stmt->execute([$user['id']]);
                $notifs = $stmt->fetchAll();
                // Contar no leídas
                $countStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
                $countStmt->execute([$user['id']]);
                $unread = (int)$countStmt->fetchColumn();
                Response::success(['notifications' => $notifs, 'unread' => $unread]);
            } elseif ($method === 'PUT' && $notifId) {
                // Marcar una como leída
                $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$notifId, $user['id']]);
                Response::success(null);
            } elseif ($method === 'PUT') {
                // Marcar todas como leídas
                $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$user['id']]);
                Response::success(null);
            } elseif ($method === 'DELETE') {
                // Borrar todas
                $db->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$user['id']]);
                Response::success(null);
            }
            break;
        case 'categories':
            $db = Database::connect();
            Response::success($db->query("SELECT * FROM business_categories WHERE is_active=1 ORDER BY sort_order ASC, name ASC")->fetchAll());
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

        // ── TEST PUSH ─────────────────────────────────────────
        case 'test-push':
            AuthMiddleware::authenticate();
            $uid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
            if (!$uid) Response::error('Falta uid', 400);
            PushNotification::send($uid, '🔔 Test NuevaExpress', 'Push funcionando correctamente para uid=' . $uid, '/');
            Response::success(['uid' => $uid], 'Push enviado');
            break;

        case 'test-email':
            $email = $_GET['email'] ?? '';
            if (!$email) Response::error('Falta email', 400);
            require_once __DIR__ . '/helpers/Mailer.php';
            $ok = Mailer::orderAccepted($email, 'Usuario de prueba', 'TEST-001', 'Negocio Demo', 15);
            Response::success(['sent' => $ok, 'to' => $email], $ok ? 'Email enviado' : 'Error al enviar');
            break;

        default:
            Response::notFound('Endpoint no encontrado');
    }
} catch (Throwable $e) {
    error_log('[NuevaExpress] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::serverError(APP_ENV === 'development' ? $e->getMessage() : 'Error interno del servidor');
}
