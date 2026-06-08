<?php
// ============================================================
// Delivery Controller
// ============================================================
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
                   o.created_at,
                   b.name as business_name, b.address as pickup_address, b.phone as business_phone, b.google_maps_url as business_maps_url,
                   u.name as client_name, u.phone as client_phone
            FROM deliveries d
            JOIN orders o ON d.order_id = o.id
            JOIN businesses b ON o.business_id = b.id
            JOIN users u ON o.client_id = u.id
            WHERE (d.status = 'disponible' OR d.repartidor_id = ?)
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

        Response::success(null, 'Entrega tomada');
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

        // Notify all active repartidores — pedido vuelve a estar disponible
        $rStmt = $this->db->prepare("SELECT id FROM users WHERE role_id = 4 AND is_active = 1");
        $rStmt->execute();
        $repartidorIds = $rStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($repartidorIds)) {
            $oStmt = $this->db->prepare("SELECT order_number FROM orders WHERE id = ?");
            $oStmt->execute([$d['order_id']]);
            $orderNumber = $oStmt->fetchColumn() ?: '—';
            PushNotification::sendToMany(
                $repartidorIds,
                '🔄 Pedido liberado — disponible de nuevo',
                "El pedido #{$orderNumber} fue liberado y está disponible para tomar.",
                '/repartidor/index.html'
            );
        }

        Response::success(null, 'Entrega liberada — disponible para otros repartidores');
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
        $stmt = $this->db->prepare("SELECT o.id, o.client_id, o.order_number FROM deliveries d JOIN orders o ON o.id = d.order_id WHERE d.id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $this->db->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$orderStatus, $row['id']]);

            // Notify client on picked up or delivered
            if ($dbStatus === 'recogido') {
                $this->pushToUser((int)$row['client_id'], '🛵 Tu pedido fue recogido',
                    "Tu pedido #{$row['order_number']} ya está en camino. ¡Pronto llegará!",
                    '/cliente/pedido-detalle.html?id=' . $row['id']);
            } elseif ($dbStatus === 'entregado') {
                $this->pushToUser((int)$row['client_id'], '✅ Pedido entregado',
                    "Tu pedido #{$row['order_number']} fue entregado. ¡Buen provecho!",
                    '/cliente/pedido-detalle.html?id=' . $row['id']);
            }
        }

        Response::success(null, 'Estado actualizado');
    }

    private function pushToUser(int $userId, string $title, string $message, string $url = '/'): void {
        $this->db->prepare("INSERT INTO notifications (user_id, type, title, message, data) VALUES (?,?,?,?,?)")
                 ->execute([$userId, 'order_update', $title, $message, json_encode(['url' => $url])]);
        PushNotification::send($userId, $title, $message, $url);
    }
}
