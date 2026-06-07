<?php
// ============================================================
// Delivery Zone Controller — Global zones (admin only)
// Businesses select which zones they cover
// ============================================================
class DeliveryZoneController {
    private $db;
    public function __construct() { $this->db = Database::connect(); }

    // GET /api/delivery-zones — all active zones (public)
    public function index() {
        $stmt = $this->db->query("SELECT * FROM delivery_zones WHERE is_active=1 ORDER BY sort_order, price");
        Response::success($stmt->fetchAll());
    }

    // GET /api/admin/delivery-zones — all zones including inactive (admin)
    public function adminIndex() {
        AuthMiddleware::requireRole('admin');
        $stmt = $this->db->query("SELECT * FROM delivery_zones ORDER BY sort_order, price");
        Response::success($stmt->fetchAll());
    }

    // POST /api/admin/delivery-zones
    public function store($body) {
        AuthMiddleware::requireRole('admin');
        $name  = Security::sanitize(trim($body['name'] ?? ''));
        $desc  = Security::sanitize($body['description'] ?? '');
        $price = (float)($body['price'] ?? 15);
        $order = (int)($body['sort_order'] ?? 0);
        if (!$name) Response::error('Nombre requerido', 400);
        if ($price < 0) Response::error('Precio inválido', 400);
        $this->db->prepare("INSERT INTO delivery_zones (name, description, price, sort_order) VALUES (?,?,?,?)")
                 ->execute([$name, $desc, $price, $order]);
        Response::success(['id' => $this->db->lastInsertId()], 'Zona creada', 201);
    }

    // PUT /api/admin/delivery-zones/{id}
    public function update($id, $body) {
        AuthMiddleware::requireRole('admin');
        $allowed = ['name','description','price','is_active','sort_order'];
        $sets = []; $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $sets[] = "$f = ?";
                $params[] = $f === 'price' ? (float)$body[$f] : Security::sanitize($body[$f]);
            }
        }
        if (!$sets) Response::error('Sin datos', 400);
        $params[] = $id;
        $this->db->prepare("UPDATE delivery_zones SET ".implode(',',$sets)." WHERE id = ?")->execute($params);
        Response::success(null, 'Zona actualizada');
    }

    // DELETE /api/admin/delivery-zones/{id}
    public function destroy($id) {
        AuthMiddleware::requireRole('admin');
        $this->db->prepare("DELETE FROM business_delivery_zones WHERE zone_id = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM delivery_zones WHERE id = ?")->execute([$id]);
        Response::success(null, 'Zona eliminada');
    }

    // GET /api/businesses/{id}/zones — zones a business covers
    public function bizZones($bizId) {
        try {
            $stmt = $this->db->prepare("
                SELECT z.*, 
                       CASE WHEN bdz.id IS NOT NULL THEN 1 ELSE 0 END as covered,
                       bdz.custom_price
                FROM delivery_zones z
                LEFT JOIN business_delivery_zones bdz ON z.id = bdz.zone_id AND bdz.business_id = ?
                WHERE z.is_active = 1
                ORDER BY z.sort_order, z.price
            ");
            $stmt->execute([$bizId]);
            Response::success($stmt->fetchAll());
        } catch (\Exception \$e) {
            Response::success([]); // Return empty if table doesn't exist yet
        }
    }

    // POST /api/businesses/{id}/zones — business selects which zones it covers
    public function bizUpdateZones($bizId, $body) {
        $user = AuthMiddleware::requireRole(['negocio','admin']);
        if ($user['role'] === 'negocio') {
            $stmt = $this->db->prepare("SELECT user_id FROM businesses WHERE id = ?");
            $stmt->execute([$bizId]);
            $biz = $stmt->fetch();
            if (!$biz || $biz['user_id'] != $user['id']) Response::forbidden();
        }
        // zone_ids = array of zone IDs the business covers
        $zoneIds = $body['zone_ids'] ?? [];
        // Clear existing
        $this->db->prepare("DELETE FROM business_delivery_zones WHERE business_id = ?")->execute([$bizId]);
        // Insert new
        foreach ($zoneIds as $zid) {
            $this->db->prepare("INSERT INTO business_delivery_zones (business_id, zone_id) VALUES (?,?)")
                     ->execute([$bizId, (int)$zid]);
        }
        Response::success(null, 'Zonas actualizadas');
    }
}
