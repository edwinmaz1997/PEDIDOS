<?php
// ============================================================
// Cron Job — Recordatorios de pedidos sin atender
// Ejecutar cada 5 minutos:
//   */5 * * * * php /home/tu_usuario/public_html/api/cron/reminders.php
// ============================================================

// Solo permitir ejecución desde CLI o IP del servidor
if (php_sapi_name() !== 'cli' && $_SERVER['REMOTE_ADDR'] !== '127.0.0.1') {
    http_response_code(403);
    exit('Forbidden');
}

define('ROOT', dirname(__DIR__));
require_once ROOT . '/config/config.php';
require_once ROOT . '/config/Database.php';
require_once ROOT . '/helpers/PushNotification.php';

$db = Database::connect();

// ── 1. Negocios que no han aceptado un pedido en más de 5 minutos ──────────
$stmt = $db->prepare("
    SELECT o.id, o.order_number, o.business_id, o.created_at,
           b.name as business_name, b.user_id as biz_user_id
    FROM orders o
    JOIN businesses b ON b.id = o.business_id
    WHERE o.status = 'pendiente'
    AND o.created_at <= NOW() - INTERVAL 5 MINUTE
    AND o.created_at >= NOW() - INTERVAL 30 MINUTE
    AND NOT EXISTS (
        SELECT 1 FROM notifications n
        WHERE n.user_id = b.user_id
        AND n.type = 'reminder_accept'
        AND n.data LIKE CONCAT('%\"order_id\":', o.id, '%')
        AND n.created_at >= NOW() - INTERVAL 5 MINUTE
    )
");
$stmt->execute();
$pendingOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($pendingOrders as $order) {
    $mins = round((time() - strtotime($order['created_at'])) / 60);
    $title = '⏰ Pedido pendiente de aceptar';
    $msg   = "Tu pedido #{$order['order_number']} lleva {$mins} min esperando. ¡Acéptalo pronto!";

    // Push al negocio
    PushNotification::send((int)$order['biz_user_id'], $title, $msg, '/negocio/index.html');

    // Guardar notificación para no repetir
    $db->prepare("INSERT INTO notifications (user_id, type, title, message, data) VALUES (?,?,?,?,?)")
       ->execute([
           $order['biz_user_id'],
           'reminder_accept',
           $title,
           $msg,
           json_encode(['order_id' => (int)$order['id']])
       ]);

    echo "[" . date('Y-m-d H:i:s') . "] Negocio recordatorio: #{$order['order_number']} → user {$order['biz_user_id']}\n";
}

// ── 2. Repartidores — pedido disponible sin tomar en más de 5 minutos ──────
$stmt2 = $db->prepare("
    SELECT d.id, o.order_number, o.id as order_id, b.name as business_name,
           d.created_at as assigned_at
    FROM deliveries d
    JOIN orders o ON o.id = d.order_id
    JOIN businesses b ON b.id = o.business_id
    WHERE d.status = 'disponible'
    AND d.created_at <= NOW() - INTERVAL 5 MINUTE
    AND d.created_at >= NOW() - INTERVAL 30 MINUTE
    AND NOT EXISTS (
        SELECT 1 FROM notifications n
        WHERE n.type = 'reminder_delivery'
        AND n.data LIKE CONCAT('%\"delivery_id\":', d.id, '%')
        AND n.created_at >= NOW() - INTERVAL 5 MINUTE
    )
");
$stmt2->execute();
$pendingDeliveries = $stmt2->fetchAll(PDO::FETCH_ASSOC);

foreach ($pendingDeliveries as $delivery) {
    // Obtener todos los repartidores activos
    $rStmt = $db->prepare("SELECT id FROM users WHERE role_id = 4 AND is_active = 1");
    $rStmt->execute();
    $repartidorIds = $rStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($repartidorIds)) continue;

    $title = '🛵 Pedido sin repartidor';
    $msg   = "El pedido #{$delivery['order_number']} de {$delivery['business_name']} lleva 5 min sin ser tomado. ¡Tómalo ahora!";

    PushNotification::sendToMany($repartidorIds, $title, $msg, '/repartidor/index.html');

    // Guardar notificación para no repetir (en el primer repartidor como referencia)
    $db->prepare("INSERT INTO notifications (user_id, type, title, message, data) VALUES (?,?,?,?,?)")
       ->execute([
           $repartidorIds[0],
           'reminder_delivery',
           $title,
           $msg,
           json_encode(['delivery_id' => (int)$delivery['id'], 'order_number' => $delivery['order_number']])
       ]);

    echo "[" . date('Y-m-d H:i:s') . "] Repartidor recordatorio: #{$delivery['order_number']} → {$delivery['business_name']}\n";
}

// ── 3. Pedido listo para recoger — notificar al repartidor asignado ─────────
$stmt3 = $db->prepare("
    SELECT d.id, d.repartidor_id, o.order_number, b.name as business_name
    FROM deliveries d
    JOIN orders o ON o.id = d.order_id
    JOIN businesses b ON b.id = o.business_id
    WHERE d.status = 'disponible'
    AND o.status = 'listo'
    AND d.repartidor_id IS NOT NULL
    AND NOT EXISTS (
        SELECT 1 FROM notifications n
        WHERE n.user_id = d.repartidor_id
        AND n.type = 'order_ready'
        AND n.data LIKE CONCAT('%\"delivery_id\":', d.id, '%')
    )
");
$stmt3->execute();
$readyOrders = $stmt3->fetchAll(PDO::FETCH_ASSOC);

foreach ($readyOrders as $ready) {
    $title = '📦 Pedido listo para recoger';
    $msg   = "El pedido #{$ready['order_number']} de {$ready['business_name']} ya está listo. ¡Ve a recogerlo!";

    PushNotification::send((int)$ready['repartidor_id'], $title, $msg, '/repartidor/index.html');

    $db->prepare("INSERT INTO notifications (user_id, type, title, message, data) VALUES (?,?,?,?,?)")
       ->execute([
           $ready['repartidor_id'],
           'order_ready',
           $title,
           $msg,
           json_encode(['delivery_id' => (int)$ready['id']])
       ]);

    echo "[" . date('Y-m-d H:i:s') . "] Pedido listo: #{$ready['order_number']} → repartidor {$ready['repartidor_id']}\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Cron completado. Pendientes negocio: " . count($pendingOrders) . " | Deliveries sin tomar: " . count($pendingDeliveries) . " | Listos: " . count($readyOrders) . "\n";
