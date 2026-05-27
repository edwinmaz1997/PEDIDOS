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

        // Update order status
        $orderStatus = $dbStatus === 'entregado' ? 'entregado' : 'en_camino';
        $stmt = $this->db->prepare("SELECT order_id FROM deliveries WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $this->db->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$orderStatus, $row['order_id']]);
        }

        Response::success(null, 'Estado actualizado');
    }
}
