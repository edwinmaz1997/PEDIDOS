<?php
// ============================================================
// Business Service Controller — para negocios tipo "servicios"
// ============================================================
class BusinessServiceController {

    private $db;

    public function __construct() {
        $this->db = Database::connect();
    }

    // GET /api/businesses/{id}/services
    public function index(int $businessId): void {
        $stmt = $this->db->prepare("SELECT * FROM business_services WHERE business_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt->execute([$businessId]);
        Response::success($stmt->fetchAll());
    }

    // POST /api/businesses/{id}/services
    public function store(int $businessId, array $body): void {
        $user = AuthMiddleware::requireRole(['negocio', 'admin']);
        $this->authorizeBusiness($businessId, $user);

        $name      = Security::sanitize($body['name'] ?? '');
        $desc      = Security::sanitize($body['description'] ?? '');
        $priceFrom = isset($body['price_from']) && $body['price_from'] !== '' ? (float)$body['price_from'] : null;
        $priceTo   = isset($body['price_to']) && $body['price_to'] !== '' ? (float)$body['price_to'] : null;
        $photoUrl  = $body['photo_url'] ?? null;

        if (!$name) Response::error('El nombre del servicio es requerido', 400);

        $this->db->prepare("INSERT INTO business_services (business_id, name, description, photo_url, price_from, price_to) VALUES (?,?,?,?,?,?)")
                 ->execute([$businessId, $name, $desc ?: null, $photoUrl, $priceFrom, $priceTo]);

        $id = $this->db->lastInsertId();
        $stmt = $this->db->prepare("SELECT * FROM business_services WHERE id = ?");
        $stmt->execute([$id]);
        Response::success($stmt->fetch(), 'Servicio agregado', 201);
    }

    // PUT /api/businesses/{id}/services/{sid}
    public function update(int $businessId, int $sid, array $body): void {
        $user = AuthMiddleware::requireRole(['negocio', 'admin']);
        $this->authorizeBusiness($businessId, $user);

        $name      = Security::sanitize($body['name'] ?? '');
        $desc      = Security::sanitize($body['description'] ?? '');
        $priceFrom = isset($body['price_from']) && $body['price_from'] !== '' ? (float)$body['price_from'] : null;
        $priceTo   = isset($body['price_to']) && $body['price_to'] !== '' ? (float)$body['price_to'] : null;
        $available = isset($body['is_available']) ? (int)$body['is_available'] : 1;
        $photoUrl  = array_key_exists('photo_url', $body) ? ($body['photo_url'] ?: null) : false;

        if (!$name) Response::error('El nombre del servicio es requerido', 400);

        if ($photoUrl !== false) {
            $this->db->prepare("UPDATE business_services SET name=?, description=?, photo_url=?, price_from=?, price_to=?, is_available=? WHERE id=? AND business_id=?")
                     ->execute([$name, $desc ?: null, $photoUrl, $priceFrom, $priceTo, $available, $sid, $businessId]);
        } else {
            $this->db->prepare("UPDATE business_services SET name=?, description=?, price_from=?, price_to=?, is_available=? WHERE id=? AND business_id=?")
                     ->execute([$name, $desc ?: null, $priceFrom, $priceTo, $available, $sid, $businessId]);
        }

        Response::success(null, 'Servicio actualizado');
    }

    // DELETE /api/businesses/{id}/services/{sid}
    public function destroy(int $businessId, int $sid): void {
        $user = AuthMiddleware::requireRole(['negocio', 'admin']);
        $this->authorizeBusiness($businessId, $user);

        $this->db->prepare("DELETE FROM business_services WHERE id=? AND business_id=?")->execute([$sid, $businessId]);
        Response::success(null, 'Servicio eliminado');
    }

    private function authorizeBusiness(int $businessId, array $user): void {
        if ($user['role'] === 'admin') return;
        $stmt = $this->db->prepare("SELECT id FROM businesses WHERE id=? AND user_id=?");
        $stmt->execute([$businessId, $user['id']]);
        if (!$stmt->fetch()) Response::forbidden();
    }
}
