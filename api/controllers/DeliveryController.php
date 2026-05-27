<?php
// ============================================================
// Delivery Controller
// ============================================================
class DeliveryController {
    private $db;
    public function __construct() { $this->db = Database::connect(); }

    // GET /api/deliveries — repartidor sees available + their own
    public function index(): void {
        $user = AuthMiddleware::requireRole(['repartidor', 'admin']);
        if ($user['role'] === 'admin') {
            $stmt = $this->db->query("SELECT d.*, o.order_number, o.delivery_address, o.total, b.name as business_name, u.name as client_name, u.phone as client_phone FROM deliveries d JOIN orders o ON d.order_id = o.id JOIN businesses b ON o.business_id = b.id JOIN users u ON o.client_id = u.id ORDER BY d.id DESC");
        } else {
            $stmt = $this->db->prepare("SELECT d.*, o.order_number, o.delivery_address, o.delivery_fee, o.total, o.notes, o.delivery_type, b.name as business_name, b.address as pickup_address, u.name as client_name, u.phone as client_phone FROM deliveries d JOIN orders o ON d.order_id = o.id JOIN businesses b ON o.business_id = b.id JOIN users u ON o.client_id = u.id WHERE d.status = 'disponible' OR d.repartidor_id = ? ORDER BY d.id DESC");
            $stmt->execute([$user['id']]);
        }
        Response::success($stmt->fetchAll());
    }

    // PUT /api/deliveries/{id}/assign — repartidor claims delivery
    public function assign(int $id, array $body): void {
        $user = AuthMiddleware::requireRole('repartidor');
        $stmt = $this->db->prepare("SELECT * FROM deliveries WHERE id = ? AND status = 'disponible'");
        $stmt->execute([$id]);
        $delivery = $stmt->fetch();
        if (!$delivery) Response::error('Entrega no disponible', 400);

        $this->db->prepare("UPDATE deliveries SET repartidor_id = ?, status = 'asignado', assigned_at = NOW() WHERE id = ?")
                 ->execute([$user['id'], $id]);
        // Update order status
        $this->db->prepare("UPDATE orders SET status = 'en_camino' WHERE id = ?")->execute([$delivery['order_id']]);
        Response::success(null, 'Entrega asignada');
    }

    // PUT /api/deliveries/{id} — update delivery status
    public function update(int $id, array $body): void {
        $user   = AuthMiddleware::requireRole(['repartidor', 'admin']);
        $status = Security::sanitize($body['status'] ?? '');
        if (!in_array($status, ['recogido','entregado'])) Response::error('Estado inválido', 400);

        $extra = $status === 'recogido' ? ', picked_up_at = NOW()' : ', delivered_at = NOW()';
        $this->db->prepare("UPDATE deliveries SET status = ? $extra WHERE id = ?")->execute([$status, $id]);

        if ($status === 'entregado') {
            $stmt = $this->db->prepare("SELECT order_id FROM deliveries WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row) $this->db->prepare("UPDATE orders SET status = 'entregado' WHERE id = ?")->execute([$row['order_id']]);
        }
        Response::success(null, 'Estado actualizado');
    }
}
