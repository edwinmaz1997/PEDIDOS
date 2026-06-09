<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/Response.php';
require_once __DIR__ . '/helpers/Security.php';
require_once __DIR__ . '/middleware/AuthMiddleware.php';

// CORS
$allowedOrigins = ['https://nuevaexpress.com', 'https://www.nuevaexpress.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins) || (defined('APP_ENV') && APP_ENV === 'development')) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Access-Control-Allow-Credentials: true");
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { Response::error('Método no permitido', 405); }

$user = AuthMiddleware::requireRole('admin');

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];

if (($body['confirm'] ?? '') !== 'CONFIRMAR_RESET') {
    Response::error('Confirmación inválida', 400);
}

$db = Database::connect();

try {
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");

    $tables = [
        'order_status_log',
        'order_messages',
        'order_items',
        'deliveries',
        'notifications',
        'email_verifications',
        'orders',
    ];

    $cleaned = [];
    $skipped = [];

    foreach ($tables as $table) {
        $check = $db->query("SHOW TABLES LIKE '{$table}'")->fetch();
        if (!$check) { $skipped[] = $table; continue; }
        $db->exec("DELETE FROM `{$table}`");
        try { $db->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = 1"); } catch(Exception $e) {}
        $cleaned[] = $table;
    }

    $db->exec("SET FOREIGN_KEY_CHECKS = 1");

    error_log("[RESET] Admin {$user['id']} ({$user['name']}) limpió datos de prueba");
    Response::success(['cleaned' => $cleaned, 'skipped' => $skipped], 'Base de datos limpiada correctamente.');

} catch (Exception $e) {
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    error_log("Reset error: " . $e->getMessage());
    Response::error('Error: ' . $e->getMessage(), 500);
}
