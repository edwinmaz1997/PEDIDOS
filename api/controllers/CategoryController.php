<?php
// ============================================================
// Category Controller - Admin CRUD
// ============================================================
class CategoryController {
    private $db;
    public function __construct() { $this->db = Database::connect(); }

    // GET /api/categories (public — already in router)
    // GET /api/admin/categories
    public function index() {
        $stmt = $this->db->query("SELECT *, (SELECT COUNT(*) FROM businesses WHERE category_id=c.id AND is_active=1) as biz_count FROM business_categories c ORDER BY c.sort_order ASC, c.name ASC");
        Response::success($stmt->fetchAll());
    }

    // POST /api/admin/categories
    public function store($body) {
        AuthMiddleware::requireRole('admin');
        $name  = Security::sanitize(trim($body['name'] ?? ''));
        $icon  = Security::sanitize(trim($body['icon'] ?? 'store'));
        $color = Security::sanitize(trim($body['color'] ?? '#888888'));
        if (!$name) Response::error('El nombre es requerido', 400);

        // Check unique
        $stmt = $this->db->prepare("SELECT id FROM business_categories WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetch()) Response::error('Ya existe una categoría con ese nombre', 409);

        $this->db->prepare("INSERT INTO business_categories (name, icon, color) VALUES (?,?,?)")
                 ->execute([$name, $icon, $color]);
        Response::success(['id' => $this->db->lastInsertId()], 'Categoría creada', 201);
    }

    // PUT /api/admin/categories/{id}
    public function update($id, $body) {
        AuthMiddleware::requireRole('admin');
        $sets = []; $params = [];
        $allowed = ['name','icon','color','is_active','sort_order'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $sets[] = "$f = ?";
                $params[] = Security::sanitize($body[$f]);
            }
        }
        if (!$sets) Response::error('Sin datos', 400);
        $params[] = $id;
        $this->db->prepare("UPDATE business_categories SET ".implode(',',$sets)." WHERE id = ?")->execute($params);
        Response::success(null, 'Categoría actualizada');
    }

    // DELETE /api/admin/categories/{id}
    public function destroy($id) {
        AuthMiddleware::requireRole('admin');
        // Check if has businesses
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM businesses WHERE category_id = ? AND is_active = 1");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) Response::error('No se puede eliminar: tiene negocios activos asignados', 400);
        $this->db->prepare("DELETE FROM business_categories WHERE id = ?")->execute([$id]);
        Response::success(null, 'Categoría eliminada');
    }
}
