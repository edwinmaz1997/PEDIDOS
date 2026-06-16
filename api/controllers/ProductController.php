<?php
// ============================================================
// Product Controller
// ============================================================
class ProductController {
    private $db;
    public function __construct() { $this->db = Database::connect(); }

    public function index(): void {
        $businessId = (int)($_GET['business_id'] ?? 0);
        if (!$businessId) Response::error('business_id requerido', 400);
        $stmt = $this->db->prepare("SELECT p.*, c.name as category_name, c.icon as category_icon FROM products_services p LEFT JOIN product_categories c ON p.category_id = c.id WHERE p.business_id = ? ORDER BY p.is_available DESC, c.sort_order, p.sort_order, p.name");
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

        $stmt = $this->db->prepare("INSERT INTO products_services (business_id, name, description, price, photo, sort_order, category_id) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$businessId, $body['name'], $body['description'] ?? null, $body['price'] ?? null, $body['photo'] ?? null, $body['sort_order'] ?? 0, $body['category_id'] ?? null]);
        Response::success(['id' => $this->db->lastInsertId()], 'Producto creado', 201);
    }

    public function update(int $id, array $body): void {
        AuthMiddleware::requireRole(['negocio', 'admin']);
        $fields = ['name','description','price','is_available','sort_order','category_id','photo'];
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
        $user = AuthMiddleware::requireRole(['negocio', 'admin']);

        if ($user['role'] === 'negocio') {
            $biz = $this->db->prepare("SELECT id FROM businesses WHERE user_id = ?");
            $biz->execute([$user['id']]);
            $bizRow = $biz->fetch();
            if (!$bizRow) Response::error('Negocio no encontrado', 404);
            $check = $this->db->prepare("SELECT id FROM products_services WHERE id = ? AND business_id = ?");
            $check->execute([$id, $bizRow['id']]);
            if (!$check->fetch()) Response::notFound('Producto no encontrado');
        }

        $this->db->prepare("DELETE FROM products_services WHERE id = ?")->execute([$id]);
        Response::success(null, 'Producto eliminado');
    }
}
