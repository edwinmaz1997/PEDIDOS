<?php
// ============================================================
// Delivery Zones Controller
// ============================================================
class DeliveryZoneController {
    private $db;
    public function __construct() { $this->db = Database::connect(); }

    // GET /api/businesses/{id}/zones
    public function index($bizId) {
        $stmt = $this->db->prepare("SELECT * FROM delivery_zones WHERE business_id = ? ORDER BY sort_order, price ASC");
        $stmt->execute([$bizId]);
        Response::success($stmt->fetchAll());
    }

    // POST /api/businesses/{id}/zones
    public function store($bizId, $body) {
        $user = AuthMiddleware::requireRole(['negocio','admin']);
        $this->verifyOwnership($bizId, $user);

        $name  = Security::sanitize(trim($body['name'] ?? ''));
        $price = (float)($body['price'] ?? 15);
        $desc  = Security::sanitize($body['description'] ?? '');
        $order = (int)($body['sort_order'] ?? 0);

        if (!$name) Response::error('El nombre de la zona es requerido', 400);
        if ($price < 0) Response::error('El precio no puede ser negativo', 400);

        $this->db->prepare("INSERT INTO delivery_zones (business_id, name, description, price, sort_order) VALUES (?,?,?,?,?)")
                 ->execute([$bizId, $name, $desc, $price, $order]);
        Response::success(['id' => $this->db->lastInsertId()], 'Zona creada', 201);
    }

    // PUT /api/businesses/{id}/zones/{zoneId}
    public function update($bizId, $zoneId, $body) {
        $user = AuthMiddleware::requireRole(['negocio','admin']);
        $this->verifyOwnership($bizId, $user);

        $sets = []; $params = [];
        $allowed = ['name','description','price','is_active','sort_order'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $sets[] = "$f = ?";
                $params[] = $f === 'price' ? (float)$body[$f] : Security::sanitize($body[$f]);
            }
        }
        if (!$sets) Response::error('Sin datos', 400);
        $params[] = $zoneId; $params[] = $bizId;
        $this->db->prepare("UPDATE delivery_zones SET ".implode(',',$sets)." WHERE id = ? AND business_id = ?")->execute($params);
        Response::success(null, 'Zona actualizada');
    }

    // DELETE /api/businesses/{id}/zones/{zoneId}
    public function destroy($bizId, $zoneId) {
        $user = AuthMiddleware::requireRole(['negocio','admin']);
        $this->verifyOwnership($bizId, $user);
        $this->db->prepare("DELETE FROM delivery_zones WHERE id = ? AND business_id = ?")->execute([$zoneId, $bizId]);
        Response::success(null, 'Zona eliminada');
    }

    private function verifyOwnership($bizId, $user) {
        if ($user['role'] === 'admin') return;
        $stmt = $this->db->prepare("SELECT user_id FROM businesses WHERE id = ?");
        $stmt->execute([$bizId]);
        $biz = $stmt->fetch();
        if (!$biz || $biz['user_id'] != $user['id']) Response::forbidden();
    }
}
