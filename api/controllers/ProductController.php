<?php
// ============================================================
// Product Controller
// ============================================================
class ProductController {
    private PDO $db;
    public function __construct() { $this->db = Database::connect(); }

    public function index(): void {
        $businessId = (int)($_GET['business_id'] ?? 0);
        if (!$businessId) Response::error('business_id requerido', 400);
        $stmt = $this->db->prepare("SELECT * FROM products_services WHERE business_id = ? AND is_available = 1 ORDER BY sort_order");
        $stmt->execute([$businessId]);
        Response::success($stmt->fetchAll());
    }

    public function show(int $id): void {
        $stmt = $this->db->prepare("SELECT * FROM products_services WHERE id = ?");
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p) Response::notFound();
        Response::success($p);
    }

    public function store(array $body): void {
        $user = AuthMiddleware::requireRole(['negocio', 'admin']);
        $businessId = (int)($body['business_id'] ?? 0);
        if (!$businessId) Response::error('business_id requerido', 400);
        if (empty($body['name'])) Response::error('Nombre requerido', 400);

        $stmt = $this->db->prepare("INSERT INTO products_services (business_id, name, description, price, sort_order) VALUES (?,?,?,?,?)");
        $stmt->execute([$businessId, $body['name'], $body['description'] ?? null, $body['price'] ?? null, $body['sort_order'] ?? 0]);
        Response::success(['id' => $this->db->lastInsertId()], 'Producto creado', 201);
    }

    public function update(int $id, array $body): void {
        AuthMiddleware::requireRole(['negocio', 'admin']);
        $fields = ['name','description','price','is_available','sort_order'];
        $sets = []; $params = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $body)) { $sets[] = "$f = ?"; $params[] = $body[$f]; }
        }
        if (!$sets) Response::error('Sin datos', 400);
        $params[] = $id;
        $this->db->prepare("UPDATE products_services SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        Response::success(null, 'Producto actualizado');
    }

    public function destroy(int $id): void {
        AuthMiddleware::requireRole(['negocio', 'admin']);
        $this->db->prepare("UPDATE products_services SET is_available = 0 WHERE id = ?")->execute([$id]);
        Response::success(null, 'Producto eliminado');
    }
}
