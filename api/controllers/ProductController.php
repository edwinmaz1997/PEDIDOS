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
        $products = $stmt->fetchAll();
        // Agregar variantes y extras a cada producto
        $eStmt = $this->db->prepare("SELECT * FROM product_extras WHERE product_id = ? AND is_available = 1 ORDER BY sort_order, id");
        foreach ($products as &$p) {
            if ($p['has_variants']) {
                $vStmt = $this->db->prepare("SELECT * FROM product_variants WHERE product_id = ? AND is_available = 1 ORDER BY sort_order, id");
                $vStmt->execute([$p['id']]);
                $p['variants'] = $vStmt->fetchAll();
            } else {
                $p['variants'] = [];
            }
            $eStmt->execute([$p['id']]);
            $p['extras'] = $eStmt->fetchAll();
        }
        Response::success($products);
    }

    public function show(int $id): void {
        $stmt = $this->db->prepare("SELECT * FROM products_services WHERE id = ?");
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p) Response::notFound();
        if ($p['has_variants']) {
            $vStmt = $this->db->prepare("SELECT * FROM product_variants WHERE product_id = ? ORDER BY sort_order, id");
            $vStmt->execute([$id]);
            $p['variants'] = $vStmt->fetchAll();
        }
        Response::success($p);
    }

    private function saveExtras(int $productId, array $extras): void {
        $this->db->prepare("DELETE FROM product_extras WHERE product_id = ?")->execute([$productId]);
        $stmt = $this->db->prepare("INSERT INTO product_extras (product_id, name, price, sort_order) VALUES (?,?,?,?)");
        foreach ($extras as $i => $e) {
            if (!empty($e['name'])) {
                $stmt->execute([$productId, $e['name'], (float)($e['price'] ?? 0), $i]);
            }
        }
    }

    private function saveVariants(int $productId, array $variants): void {
        $this->db->prepare("DELETE FROM product_variants WHERE product_id = ?")->execute([$productId]);
        $vStmt = $this->db->prepare("INSERT INTO product_variants (product_id, name, price, sort_order) VALUES (?,?,?,?)");
        foreach ($variants as $i => $v) {
            if (!empty($v['name']) && isset($v['price'])) {
                $vStmt->execute([$productId, $v['name'], (float)$v['price'], $i]);
            }
        }
    }

    public function store(array $body): void {
        $user = AuthMiddleware::requireRole(['negocio', 'admin']);
        $businessId = (int)($body['business_id'] ?? 0);
        if (!$businessId) Response::error('business_id requerido', 400);
        if (empty($body['name'])) Response::error('Nombre requerido', 400);

        $hasVariants = !empty($body['has_variants']) ? 1 : 0;
        $requiresBoleta = !empty($body['requires_boleta']) ? 1 : 0;
        $stmt = $this->db->prepare("INSERT INTO products_services (business_id, name, description, price, photo, sort_order, category_id, has_variants, requires_boleta) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$businessId, $body['name'], $body['description'] ?? null, $body['price'] ?? null, $body['photo'] ?? null, $body['sort_order'] ?? 0, $body['category_id'] ?? null, $hasVariants, $requiresBoleta]);
        $productId = (int)$this->db->lastInsertId();

        if ($hasVariants && !empty($body['variants'])) {
            $this->saveVariants($productId, $body['variants']);
        }
        if (array_key_exists('extras', $body)) {
            $this->saveExtras($productId, $body['extras'] ?: []);
        }
        Response::success(['id' => $productId], 'Producto creado', 201);
    }

    public function update(int $id, array $body): void {
        AuthMiddleware::requireRole(['negocio', 'admin']);
        $fields = ['name','description','price','is_available','sort_order','category_id','photo','has_variants','requires_boleta'];
        $sets = []; $params = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $body)) { $sets[] = "$f = ?"; $params[] = $body[$f]; }
        }
        if (!$sets) Response::error('Sin datos', 400);
        $params[] = $id;
        $this->db->prepare("UPDATE products_services SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

        // Actualizar variantes si se enviaron
        if (array_key_exists('variants', $body)) {
            $this->saveVariants($id, $body['variants'] ?: []);
        }
        if (array_key_exists('extras', $body)) {
            $this->saveExtras($id, $body['extras'] ?: []);
        }
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

    public function bulkImport(array $body): void {
        $user = AuthMiddleware::requireRole(['negocio', 'admin']);
        $businessId = (int)($body['business_id'] ?? 0);
        $rows       = $body['products'] ?? [];

        if (!$businessId) Response::error('business_id requerido', 400);
        if (empty($rows)) Response::error('No se enviaron productos', 400);
        if (count($rows) > 500) Response::error('Máximo 500 productos por importación', 400);

        // Cargar categorías del negocio para mapear por nombre
        $catStmt = $this->db->prepare("SELECT id, name FROM product_categories WHERE business_id = ?");
        $catStmt->execute([$businessId]);
        $cats = [];
        foreach ($catStmt->fetchAll() as $c) {
            $cats[mb_strtolower(trim($c['name']))] = (int)$c['id'];
        }

        $stmt = $this->db->prepare("INSERT INTO products_services (business_id, name, description, price, category_id, is_available) VALUES (?,?,?,?,?,1)");
        $imported = 0;
        $errors   = [];

        foreach ($rows as $i => $row) {
            $name = trim($row['name'] ?? '');
            if (!$name) { $errors[] = 'Fila ' . ($i+2) . ': nombre vacío'; continue; }
            $desc    = trim($row['description'] ?? '') ?: null;
            $price   = isset($row['price']) && $row['price'] !== '' ? (float)$row['price'] : null;
            $catName = mb_strtolower(trim($row['category'] ?? ''));
            $catId   = ($catName && $catName !== 'sin categoria' && $catName !== 'sin categoría') ? ($cats[$catName] ?? null) : null;
            $stmt->execute([$businessId, $name, $desc, $price, $catId]);
            $imported++;
        }

        Response::success(['imported' => $imported, 'errors' => $errors],
            $imported . ' producto(s) importado(s)' . (count($errors) ? ' con ' . count($errors) . ' error(es).' : '.'));
    }
}
