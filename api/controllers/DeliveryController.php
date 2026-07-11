<?php
// ============================================================
// Delivery Controller
// ============================================================
require_once __DIR__ . '/../helpers/Mailer.php';
class DeliveryController {
    private $db;
    public function __construct() { $this->db = Database::connect(); }

    // GET /api/deliveries
    public function index(): void {
        $user = AuthMiddleware::requireRole(['repartidor', 'admin']);
        $stmt = $this->db->prepare("
            SELECT d.*, 
                   o.order_number, o.delivery_address, o.delivery_fee, o.total, o.subtotal,
                   o.notes, o.delivery_type, o.status as order_status,
                   o.created_at, o.estimated_time, o.accepted_at, o.preparation_started_at,
                   UNIX_TIMESTAMP(o.preparation_started_at) as prep_ts,
                   b.name as business_name, b.address as pickup_address, b.phone as business_phone, b.google_maps_url as business_maps_url, b.business_type, b.cash_on_delivery,
                   u.name as client_name, u.phone as client_phone, u.house_description as client_house_description
            FROM deliveries d
            JOIN orders o ON d.order_id = o.id
            JOIN businesses b ON o.business_id = b.id
            JOIN users u ON o.client_id = u.id
            WHERE (
              (d.status = 'disponible' AND o.status NOT IN ('entregado','cancelado'))
              OR (d.repartidor_id = ? AND o.status NOT IN ('cancelado'))
            )
            ORDER BY d.id DESC
        ");
        $stmt->execute([$user['id']]);
        $all = $stmt->fetchAll();

        $available = array_filter($all, function($d) { return $d['status'] === 'disponible'; });
        $mine      = array_filter($all, function($d) use ($user) { return $d['repartidor_id'] == $user['id']; });

        // Fetch items for each delivery
        $allData = array_values($available) + array_values($mine);
        $orderIds = array_unique(array_column(array_merge(array_values($available), array_values($mine)), 'order_id'));
        $items = [];
        if (!empty($orderIds)) {
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $itemStmt = $this->db->prepare("SELECT * FROM order_items WHERE order_id IN ($placeholders)");
            $itemStmt->execute($orderIds);
            foreach ($itemStmt->fetchAll() as $item) {
                $items[$item['order_id']][] = $item;
            }
        }

        // Attach items to each delivery
        $attachItems = function(&$list) use ($items) {
            foreach ($list as &$d) {
                $d['items'] = $items[$d['order_id']] ?? [];
            }
        };
        $avail = array_values($available);
        $mine2 = array_values($mine);
        $attachItems($avail);
        $attachItems($mine2);

        Response::success([
            'available' => $avail,
            'mine'      => $mine2
        ]);
    }

    // POST /api/deliveries/{id}/claim — repartidor claims delivery
    public function claim(int $id): void {
        $user = AuthMiddleware::requireRole('repartidor');
        $stmt = $this->db->prepare("SELECT * FROM deliveries WHERE id = ? AND status = 'disponible'");
        $stmt->execute([$id]);
        $delivery = $stmt->fetch();
        if (!$delivery) Response::error('Entrega no disponible', 400);

        $this->db->prepare("UPDATE deliveries SET repartidor_id = ?, status = 'asignado', assigned_at = NOW() WHERE id = ?")
                 ->execute([$user['id'], $id]);

        // Obtener datos del pedido y repartidor
        $oStmt = $this->db->prepare("SELECT o.*, b.user_id as biz_user_id FROM orders o JOIN businesses b ON b.id = o.business_id WHERE o.id = ?");
        $oStmt->execute([$delivery['order_id']]);
        $order = $oStmt->fetch();

        if ($order) {
            $rName  = $user['name'] ?? 'El repartidor';
            $rPhone = $user['phone'] ?? null;
            $phoneStr = $rPhone ? " — 📞 {$rPhone}" : '';

            // Notificar al cliente
            $this->pushToUser((int)$order['client_id'],
                '🛵 Repartidor asignado',
                "Tu pedido #{$order['order_number']} fue asignado a {$rName}{$phoneStr}.",
                '/cliente/pedido-detalle.html?id=' . $delivery['order_id']
            );

            // Notificar al negocio
            $this->pushToUser((int)$order['biz_user_id'],
                '🛵 Repartidor tomó el pedido',
                "{$rName} tomó el pedido #{$order['order_number']}.",
                '/negocio/pedido-detalle.html?id=' . $delivery['order_id']
            );
        }

        Response::success(['order_id' => $delivery['order_id']], 'Entrega tomada');
    }

    // POST /api/deliveries/{id}/release — repartidor releases delivery back to available
    public function release(int $id): void {
        $user = AuthMiddleware::requireRole(['repartidor', 'admin']);
        $stmt = $this->db->prepare("SELECT * FROM deliveries WHERE id = ?");
        $stmt->execute([$id]);
        $d = $stmt->fetch();
        if (!$d) Response::notFound('Entrega no encontrada');

        // Only owner or admin can release
        if ($user['role'] !== 'admin' && $d['repartidor_id'] != $user['id']) Response::forbidden();
        if (in_array($d['status'], ['entregado','cancelado'])) Response::error('No se puede liberar una entrega completada', 400);

        $this->db->prepare("UPDATE deliveries SET repartidor_id = NULL, status = 'disponible', assigned_at = NULL WHERE id = ?")
                 ->execute([$id]);

        // Reset order status back to aceptado
        $this->db->prepare("UPDATE orders SET status = 'aceptado' WHERE id = ?")->execute([$d['order_id']]);

        // Obtener datos del pedido para notificaciones
        $oStmt = $this->db->prepare("SELECT o.*, b.user_id as biz_user_id, b.name as biz_name FROM orders o JOIN businesses b ON b.id = o.business_id WHERE o.id = ?");
        $oStmt->execute([$d['order_id']]);
        $order = $oStmt->fetch();
        $orderNumber = $order['order_number'] ?? '—';

        // Notificar al cliente
        if ($order) {
            $this->pushToUser((int)$order['client_id'], '🔄 Pedido sin repartidor',
                "Tu pedido #{$orderNumber} quedó sin repartidor. Estamos buscando uno nuevo.",
                '/cliente/pedido-detalle.html?id=' . $d['order_id']);

            // Notificar al negocio
            $this->pushToUser((int)$order['biz_user_id'], '🔄 Repartidor liberó pedido',
                "El repartidor liberó el pedido #{$orderNumber}. Se está buscando uno nuevo.",
                '/negocio/pedido-detalle.html?id=' . $d['order_id']);
        }

        // Notify all active repartidores
        $rStmt = $this->db->prepare("SELECT id FROM users WHERE role_id = 4 AND is_active = 1");
        $rStmt->execute();
        $repartidorIds = $rStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($repartidorIds)) {
            PushNotification::sendToMany(
                $repartidorIds,
                '🔄 Pedido liberado — disponible de nuevo',
                "El pedido #{$orderNumber} fue liberado y está disponible para tomar.",
                '/repartidor/index.html'
            );
        }

        Response::success(['order_id' => $d['order_id']], 'Entrega liberada — disponible para otros repartidores');
    }

    // PUT /api/deliveries/{id}/status
    public function updateStatus(int $id, array $body): void {
        $user   = AuthMiddleware::requireRole(['repartidor', 'admin']);
        $status = Security::sanitize($body['status'] ?? '');

        // Map frontend status to DB status
        $map = [
            'en_camino' => 'asignado',
            'entregado' => 'entregado',
            'recogido'  => 'recogido',
            'asignado'  => 'asignado',
        ];
        $dbStatus = $map[$status] ?? $status;
        if (!in_array($dbStatus, ['asignado','recogido','entregado'])) Response::error('Estado inválido', 400);

        $extra = '';
        if ($dbStatus === 'recogido')  $extra = ', picked_up_at = NOW()';
        if ($dbStatus === 'entregado') $extra = ', delivered_at = NOW()';

        $this->db->prepare("UPDATE deliveries SET status = ? $extra WHERE id = ?")->execute([$dbStatus, $id]);

        // Update order status + notify client
        $orderStatus = $dbStatus === 'entregado' ? 'entregado' : 'en_camino';
        $stmt = $this->db->prepare("
            SELECT o.id, o.client_id, o.order_number, u.email as client_email, u.name as client_name,
                   b.name as business_name, us.name as repartidor_name
            FROM deliveries d
            JOIN orders o ON o.id = d.order_id
            JOIN users u ON o.client_id = u.id
            JOIN businesses b ON o.business_id = b.id
            LEFT JOIN users us ON d.repartidor_id = us.id
            WHERE d.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $this->db->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$orderStatus, $row['id']]);

            // Notify client on picked up or delivered
            if ($dbStatus === 'recogido') {
                $this->pushToUser((int)$row['client_id'], '🛵 Tu pedido está en camino',
                    "Tu pedido #{$row['order_number']} fue recogido y ya va en camino. ¡Pronto llegará!",
                    '/cliente/pedido-detalle.html?id=' . $row['id']);
                if (!empty($row['client_email'])) {
                    try { Mailer::orderOnTheWay($row['client_email'], $row['client_name'], $row['order_number'], $row['repartidor_name'] ?? ''); }
                    catch (\Exception $e) { error_log('Mailer error: ' . $e->getMessage()); }
                }
            } elseif ($dbStatus === 'entregado') {
                $this->pushToUser((int)$row['client_id'], '✅ Pedido entregado',
                    "Tu pedido #{$row['order_number']} fue entregado. ¡Gracias por elegirnos, esperamos verte pronto!",
                    '/cliente/pedido-detalle.html?id=' . $row['id']);
                if (!empty($row['client_email'])) {
                    try { Mailer::orderDelivered($row['client_email'], $row['client_name'], $row['order_number'], $row['business_name']); }
                    catch (\Exception $e) { error_log('Mailer error: ' . $e->getMessage()); }
                }
            }
        }

        Response::success(null, 'Estado actualizado');
    }

    private function pushToUser(int $userId, string $title, string $message, string $url = '/'): void {
        $this->db->prepare("INSERT INTO notifications (user_id, type, title, message, data) VALUES (?,?,?,?,?)")
                 ->execute([$userId, 'order_update', $title, $message, json_encode(['url' => $url])]);
        PushNotification::send($userId, $title, $message, $url);
    }

    public function stats(): void {
        try {
            $user = AuthMiddleware::requireRole(['repartidor']);
            $rid  = (int)$user['id'];
            $period = $_GET['period'] ?? 'today';

            $now   = new \DateTime('now', new \DateTimeZone('America/Guatemala'));
            $today = $now->format('Y-m-d');

            if ($period === 'week') {
                $dow  = (int)$now->format('N') - 1;
                $from = (clone $now)->modify("-{$dow} days")->format('Y-m-d');
                $to   = $today;
            } elseif ($period === 'month') {
                $from = $now->format('Y-m-01');
                $to   = $today;
            } elseif ($period === 'custom' && !empty($_GET['date'])) {
                $from = $_GET['date'];
                $to   = $_GET['date'];
            } else {
                $from = $today;
                $to   = $today;
            }

            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total_entregas,
                       COALESCE(SUM(o.delivery_fee),0) as total_delivery,
                       COALESCE(SUM(o.delivery_fee*0.5),0) as ganancia_neta,
                       COALESCE(SUM(CASE WHEN b.cash_on_delivery=1 THEN o.delivery_fee ELSE o.subtotal + o.delivery_fee END),0) as total_cobrado
                FROM deliveries d
                JOIN orders o ON d.order_id=o.id
                JOIN businesses b ON o.business_id=b.id
                WHERE d.repartidor_id=? AND d.status='entregado'
                  AND DATE(d.delivered_at) BETWEEN ? AND ?
            ");
            $stmt->execute([$rid, $from, $to]);
            $s = $stmt->fetch();

            $dStmt = $this->db->prepare("
                SELECT d.id, d.delivered_at as updated_at, o.order_number, o.delivery_fee,
                       ROUND(o.delivery_fee*0.5,2) as mi_ganancia,
                       ROUND(CASE WHEN b.cash_on_delivery=1 THEN o.delivery_fee ELSE o.subtotal + o.delivery_fee END,2) as total_cobrado,
                       b.cash_on_delivery,
                       o.delivery_address, b.name as negocio
                FROM deliveries d
                JOIN orders o ON d.order_id=o.id
                LEFT JOIN businesses b ON o.business_id=b.id
                WHERE d.repartidor_id=? AND d.status='entregado'
                  AND DATE(d.delivered_at) BETWEEN ? AND ?
                ORDER BY d.delivered_at DESC
            ");
            $dStmt->execute([$rid, $from, $to]);

            Response::success([
                'period'         => $period,
                'total_entregas' => (int)($s['total_entregas'] ?? 0),
                'total_delivery' => (float)($s['total_delivery'] ?? 0),
                'ganancia_neta'  => (float)($s['ganancia_neta'] ?? 0),
                'total_cobrado'  => (float)($s['total_cobrado'] ?? 0),
                'entregas'       => $dStmt->fetchAll() ?: [],
            ]);
        } catch (\Throwable $e) {
            Response::error('Error en reportes: ' . $e->getMessage(), 500);
        }
    }
}
