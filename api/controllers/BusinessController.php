<?php
// ============================================================
// Business Controller
// ============================================================

class BusinessController {

    private $db;

    public function __construct() {
        $this->db = Database::connect();
    }

    // GET /api/businesses
    public function index(): void {
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 12)));
        $offset = ($page - 1) * $limit;

        $stmt = $this->db->prepare("
            SELECT b.*, bc.name as category_name, bc.icon as category_icon, bc.color as category_color,
                   u.name as owner_name
            FROM businesses b
            JOIN business_categories bc ON b.category_id = bc.id
            JOIN users u ON b.user_id = u.id
            WHERE b.is_active = 1
            ORDER BY b.is_featured DESC, b.sort_order ASC, b.rating DESC, b.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $businesses = $stmt->fetchAll();

        // Attach photos and products preview
        foreach ($businesses as &$biz) {
            $biz['photos'] = $this->getPhotos($biz['id'], 3);
            $biz['products_preview'] = $this->getProductsPreview($biz['id'], 5);
        }

        $total = $this->db->query("SELECT COUNT(*) FROM businesses WHERE is_active = 1")->fetchColumn();

        Response::success([
            'businesses' => $businesses,
            'pagination' => [
                'total'       => (int)$total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => ceil($total / $limit),
            ]
        ]);
    }

    // GET /api/businesses/{id}
    // GET /api/businesses/mine
    public function mine(): void {
        $user = AuthMiddleware::requireRole(["negocio", "admin"]);
        $stmt = $this->db->prepare("SELECT b.*, bc.name as category_name FROM businesses b JOIN business_categories bc ON b.category_id = bc.id WHERE b.user_id = ? LIMIT 1");
        $stmt->execute([$user["id"]]);
        $biz = $stmt->fetch();
        if (!$biz) Response::notFound("No tienes un negocio registrado");
        $biz['photos'] = $this->getPhotos($biz['id']);
        Response::success($biz);
    }

    public function show(int $id): void {
        $stmt = $this->db->prepare("
            SELECT b.*, bc.name as category_name, bc.icon as category_icon,
                   u.name as owner_name, u.email as owner_email
            FROM businesses b
            JOIN business_categories bc ON b.category_id = bc.id
            JOIN users u ON b.user_id = u.id
            WHERE b.id = ? AND b.is_active = 1
        ");
        $stmt->execute([$id]);
        $business = $stmt->fetch();
        if (!$business) Response::notFound('Negocio no encontrado');

        $business['photos']   = $this->getPhotos($id);
        $business['products'] = $this->getAllProducts($id);
        $business['opening_hours'] = json_decode($business['opening_hours'] ?? '{}', true);

        Response::success($business);
    }

    // GET /api/businesses/search?q=ceviche&category=1
    public function search(): void {
        $q        = Security::sanitize($_GET['q'] ?? '');
        $category = isset($_GET['category']) ? (int)$_GET['category'] : null;
        $zone     = Security::sanitize($_GET['zone'] ?? '');

        $sql    = "SELECT b.*, bc.name as category_name, bc.icon as category_icon, bc.color as category_color
                   FROM businesses b
                   JOIN business_categories bc ON b.category_id = bc.id
                   WHERE b.is_active = 1";
        $params = [];

        if ($q) {
            // Search in business name, description AND products/services
            $sql .= " AND (
                MATCH(b.name, b.description, b.what_we_offer) AGAINST(? IN BOOLEAN MODE)
                OR b.id IN (
                    SELECT DISTINCT business_id FROM products_services
                    WHERE MATCH(name, description) AGAINST(? IN BOOLEAN MODE) AND is_available = 1
                )
                OR b.name LIKE ?
            )";
            $params[] = $q . '*';
            $params[] = $q . '*';
            $params[] = '%' . $q . '%';
        }
        if ($category) {
            $sql .= " AND b.category_id = ?";
            $params[] = $category;
        }
        if ($zone) {
            $sql .= " AND b.zone LIKE ?";
            $params[] = '%' . $zone . '%';
        }

        $sql .= " ORDER BY b.is_featured DESC, b.sort_order ASC, b.rating DESC LIMIT 50";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $businesses = $stmt->fetchAll();

        foreach ($businesses as &$biz) {
            $biz['photos']           = $this->getPhotos($biz['id'], 2);
            $biz['products_preview'] = $this->getProductsPreview($biz['id'], 4);
        }

        Response::success($businesses);
    }

    // GET /api/businesses/by-category/{category_id}
    public function byCategory(int $categoryId): void {
        $stmt = $this->db->prepare("
            SELECT b.*, bc.name as category_name, bc.icon as category_icon, bc.color as category_color
            FROM businesses b
            JOIN business_categories bc ON b.category_id = bc.id
            WHERE b.category_id = ? AND b.is_active = 1
            ORDER BY b.is_featured DESC, b.sort_order ASC, b.rating DESC
            LIMIT 100
        ");
        $stmt->execute([$categoryId]);
        $businesses = $stmt->fetchAll();
        foreach ($businesses as &$biz) {
            $biz['photos'] = $this->getPhotos($biz['id'], 2);
        }
        Response::success($businesses);
    }

    // POST /api/businesses
    public function store(array $body): void {
        $user = AuthMiddleware::requireRole(['admin', 'negocio']);

        $errors = $this->validate($body);
        if ($errors) Response::error('Datos inválidos', 422, $errors);

        $slug = $this->generateSlug($body['name']);

        // Admin can specify owner_user_id to assign business to another user
        $ownerId = $user['id'];
        if ($user['role'] === 'admin' && !empty($body['owner_user_id'])) {
            $ownerId = (int)$body['owner_user_id'];
        }

        $businessType = in_array($body['business_type'] ?? '', ['pedidos','servicios','delivery']) ? $body['business_type'] : 'pedidos';

        // Asignar el siguiente número de orden disponible (al final de la lista)
        $maxOrder = (int)$this->db->query("SELECT COALESCE(MAX(sort_order), 0) FROM businesses")->fetchColumn();
        $nextOrder = $maxOrder + 1;

        $stmt = $this->db->prepare("
            INSERT INTO businesses
                (user_id, category_id, business_type, name, slug, description, what_we_offer,
                 address, city, zone, phone, whatsapp, email, website,
                 latitude, longitude, google_maps_url, opening_hours,
                 accepts_delivery, delivery_fee, service_fee, is_active, is_verified, sort_order)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $ownerId,
            (int)$body['category_id'],
            $businessType,
            $body['name'],
            $slug,
            $body['description'] ?? null,
            $body['what_we_offer'] ?? null,
            $body['address'] ?? null,
            $body['city'] ?? null,
            $body['zone'] ?? null,
            $body['phone'] ?? null,
            $body['whatsapp'] ?? null,
            $body['email'] ?? null,
            $body['website'] ?? null,
            isset($body['latitude']) ? (float)$body['latitude'] : null,
            isset($body['longitude']) ? (float)$body['longitude'] : null,
            $body['google_maps_url'] ?? null,
            isset($body['opening_hours']) ? json_encode($body['opening_hours']) : null,
            isset($body['accepts_delivery']) ? (int)$body['accepts_delivery'] : 0,
            DELIVERY_FEE_CENTRAL,
            SERVICE_FEE,
            0, // is_active - pending admin approval
            0, // is_verified
            $nextOrder,
        ]);

        $id = $this->db->lastInsertId();

        // Notify all admins of new business pending approval
        $adminStmt = $this->db->query("SELECT id FROM users WHERE role_id = 1 AND is_active = 1");
        foreach ($adminStmt->fetchAll() as $admin) {
            $bizName = Security::sanitize($body['name'] ?? 'Nuevo negocio');
            PushNotification::send($admin['id'], '🏪 Nuevo negocio pendiente',
                        "El negocio '$bizName' se registró y está pendiente de aprobación.",
                        '/admin/negocios.html');
        }

        Response::success(['id' => $id, 'slug' => $slug], 'Negocio creado. Pendiente de aprobación del administrador.', 201);
    }

    // PUT /api/businesses/{id}
    public function update(int $id, array $body): void {
        $user     = AuthMiddleware::requireRole(['admin', 'negocio']);
        $business = $this->findOwned($id, $user);

        $allowed = ['category_id','name','description','what_we_offer','address','city','zone',
                    'phone','whatsapp','email','website','latitude','longitude','google_maps_url',
                    'opening_hours','accepts_delivery','is_active','logo','cover_photo','is_verified','owner_user_id','business_type','sort_order','is_featured','min_order_delivery'];
        $sets = [];
        $params = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $sets[] = "$field = ?";
                $value = $body[$field];
                if ($field === 'opening_hours' && is_array($value)) $value = json_encode($value);
                $params[] = $value;
            }
        }
        if (!$sets) Response::error('No hay datos para actualizar', 400);

        $params[] = $id;
        $this->db->prepare("UPDATE businesses SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        Response::success(null, 'Negocio actualizado');
    }

    // DELETE /api/businesses/{id}
    public function destroy(int $id): void {
        $user = AuthMiddleware::requireRole(['admin', 'negocio']);
        $this->findOwned($id, $user);
        // Hard delete — remove photos, products, then business
        $this->db->prepare("DELETE FROM business_photos WHERE business_id = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM products_services WHERE business_id = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM businesses WHERE id = ?")->execute([$id]);
        Response::success(null, 'Negocio eliminado correctamente');
    }

    // --------------------------------------------------------
    // Private helpers
    // --------------------------------------------------------
    private function getPhotos($businessId, $limit = 20) {
        $stmt = $this->db->prepare("SELECT * FROM business_photos WHERE business_id = ? ORDER BY sort_order LIMIT ?");
        $stmt->execute([$businessId, $limit]);
        return $stmt->fetchAll();
    }

    private function getProductsPreview($businessId, $limit = 5) {
        $stmt = $this->db->prepare("SELECT id, name, price, photo FROM products_services WHERE business_id = ? AND is_available = 1 ORDER BY sort_order LIMIT ?");
        $stmt->execute([$businessId, $limit]);
        return $stmt->fetchAll();
    }

    private function getAllProducts($businessId) {
        $stmt = $this->db->prepare("SELECT p.*, c.name as category_name, c.icon as category_icon FROM products_services p LEFT JOIN product_categories c ON p.category_id = c.id WHERE p.business_id = ? AND p.is_available = 1 ORDER BY c.sort_order, p.sort_order, p.name");
        $stmt->execute([$businessId]);
        return $stmt->fetchAll();
    }

    private function generateSlug($name) {
        $slug = strtolower(trim($name));
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT', $slug);
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        // Ensure uniqueness
        $base = $slug;
        $i = 1;
        while (true) {
            $stmt = $this->db->prepare("SELECT id FROM businesses WHERE slug = ?");
            $stmt->execute([$slug]);
            if (!$stmt->fetch()) break;
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    /**
     * POST /api/businesses/{id}/calculate-delivery
     * Body: { client_maps_url: "https://maps.app.goo.gl/..." }
     * Calcula la distancia entre el negocio y la dirección del cliente,
     * y devuelve la tarifa de envío según la tabla de distancias.
     */
    public function calculateDelivery(int $businessId, array $body): void {
        AuthMiddleware::authenticate();

        $clientMapsUrl = trim($body['client_maps_url'] ?? '');
        $originMapsUrl = trim($body['origin_maps_url'] ?? '');
        if (!$clientMapsUrl) Response::error('Falta la ubicación de entrega', 400);

        $stmt = $this->db->prepare("SELECT id, name, latitude, longitude, google_maps_url, accepts_delivery FROM businesses WHERE id = ? AND is_active = 1");
        $stmt->execute([$businessId]);
        $biz = $stmt->fetch();
        if (!$biz) Response::notFound('Negocio no encontrado');
        if (!$biz['accepts_delivery']) Response::error('Este negocio no realiza entregas a domicilio', 400);

        // Coordenadas de origen: si se envía origin_maps_url (ej. envío de paquetes,
        // recogida en una dirección distinta al negocio) se usa esa; sino la del negocio
        $originCoords = null;
        if ($originMapsUrl) {
            $originCoords = GeoHelper::extractCoords($originMapsUrl);
        } elseif ($biz['latitude'] && $biz['longitude']) {
            $originCoords = ['lat' => (float)$biz['latitude'], 'lng' => (float)$biz['longitude']];
        } elseif ($biz['google_maps_url']) {
            $originCoords = GeoHelper::extractCoords($biz['google_maps_url']);
        }
        if (!$originCoords) {
            $msg = $originMapsUrl
                ? 'No se pudo determinar la ubicación de recogida desde el link proporcionado.'
                : 'El negocio no tiene una ubicación válida configurada. Contacta al negocio o al soporte.';
            Response::error($msg, 422);
        }

        // Coordenadas del destino
        $clientCoords = GeoHelper::extractCoords($clientMapsUrl);
        if (!$clientCoords) {
            Response::error('No se pudo determinar la ubicación de entrega desde el link proporcionado. Verifica que sea un link válido de Google Maps.', 422);
        }

        $distanceKm = GeoHelper::distanceKm(
            $originCoords['lat'], $originCoords['lng'],
            $clientCoords['lat'], $clientCoords['lng']
        );

        $fee = GeoHelper::feeForDistance($distanceKm);

        if ($fee === null) {
            Response::success([
                'distance_km'   => round($distanceKm, 2),
                'delivery_fee'  => null,
                'in_coverage'   => false,
                'message'       => 'Tu ubicación está fuera de la zona de cobertura (' . round($distanceKm, 1) . ' km).',
            ]);
            return;
        }

        Response::success([
            'distance_km'  => round($distanceKm, 2),
            'delivery_fee' => $fee,
            'in_coverage'  => true,
        ]);
    }

    private function findOwned($id, $user) {
        $stmt = $this->db->prepare("SELECT * FROM businesses WHERE id = ?");
        $stmt->execute([$id]);
        $biz = $stmt->fetch();
        if (!$biz) Response::notFound('Negocio no encontrado');
        if ($user['role'] !== 'admin' && $biz['user_id'] !== $user['id']) Response::forbidden();
        return $biz;
    }

    private function validate($body) {
        $errors = [];
        if (empty($body['name'])) $errors['name'] = 'El nombre es requerido';
        if (empty($body['category_id'])) $errors['category_id'] = 'La categoría es requerida';
        if (!empty($body['email']) && !Security::validateEmail($body['email'])) $errors['email'] = 'Email inválido';
        if (!empty($body['phone']) && !Security::validatePhone($body['phone'])) $errors['phone'] = 'Teléfono inválido';
        if (!empty($body['whatsapp']) && !Security::validatePhone($body['whatsapp'])) $errors['whatsapp'] = 'WhatsApp inválido';
        return $errors;
    }
}
