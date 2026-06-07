<?php
class ProductCategoryController {
    private $db;
    public function __construct() { $this->db = Database::connect(); }

    // GET /api/product-categories?business_id=X
    public function index(): void {
        $bizId = (int)($_GET['business_id'] ?? 0);
        if (!$bizId) Response::error('business_id requerido', 400);
        $stmt = $this->db->prepare("SELECT * FROM product_categories WHERE business_id = ? ORDER BY sort_order, name");
        $stmt->execute([$bizId]);
        Response::success($stmt->fetchAll());
    }

    // POST /api/product-categories
    public function store(array $body): void {
        $user  = AuthMiddleware::requireRole(['negocio','admin']);
        $bizId = (int)($body['business_id'] ?? 0);
        $name  = Security::sanitize($body['name'] ?? '');
        $icon  = Security::sanitize($body['icon'] ?? '🏷️');
        if (!$name) Response::error('Nombre requerido', 400);
        $this->db->prepare("INSERT INTO product_categories (business_id,name,icon) VALUES (?,?,?)")
                 ->execute([$bizId, $name, $icon]);
        Response::success(['id' => $this->db->lastInsertId()], 'Categoría creada', 201);
    }

    // PUT /api/product-categories/{id}
    public function update(int $id, array $body): void {
        AuthMiddleware::requireRole(['negocio','admin']);
        $name = Security::sanitize($body['name'] ?? '');
        $icon = Security::sanitize($body['icon'] ?? '🏷️');
        $this->db->prepare("UPDATE product_categories SET name=?, icon=? WHERE id=?")->execute([$name, $icon, $id]);
        Response::success(null, 'Categoría actualizada');
    }

    // DELETE /api/product-categories/{id}
    public function destroy(int $id): void {
        AuthMiddleware::requireRole(['negocio','admin']);
        // Set category_id to null in products
        $this->db->prepare("UPDATE products_services SET category_id = NULL WHERE category_id = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM product_categories WHERE id = ?")->execute([$id]);
        Response::success(null, 'Categoría eliminada');
    }
}
