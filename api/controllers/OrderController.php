<?php
// ============================================================
// Order Controller
// ============================================================

class OrderController {

    private $db;

    public function __construct() {
        $this->db = Database::connect();
    }

    // GET /api/orders  (filtered by role)
    public function index(): void {
        $user  = AuthMiddleware::authenticate();
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 15)));
        $offset = ($page - 1) * $limit;

        // Para reportes del negocio — traer todos sin paginación
        $fetchAll = isset($_GET['all']) && $_GET['all'] === '1' && in_array($user['role'], ['negocio', 'admin']);
        $status = Security::sanitize($_GET['status'] ?? '');

        $sql    = "SELECT o.*, b.name as business_name, b.logo as business_logo,
                          u.name as client_name, u.phone as client_phone
                   FROM orders o
                   JOIN businesses b ON o.business_id = b.id
                   JOIN users u ON o.client_id = u.id
                   WHERE 1=1";
        $params = [];

        switch ($user['role']) {
            case 'cliente':
                $sql .= " AND o.client_id = ?"; $params[] = $user['id']; break;
            case 'negocio':
                $biz = $this->getUserBusiness($user['id']);
                $sql .= " AND o.business_id = ?"; $params[] = $biz['id']; break;
            case 'admin':
                break; // sees all
            default:
                Response::forbidden();
        }

        if ($status) { $sql .= " AND o.status = ?"; $params[] = $status; }
        $sql .= " ORDER BY o.created_at DESC";
        if (!$fetchAll) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll();

        foreach ($orders as &$order) {
            $order['items'] = $this->getItems($order['id']);
            // client_total = lo que paga el cliente (sin service_fee)
            $order['client_total'] = (float)$order['subtotal'] + (float)$order['delivery_fee'];
            if ($user['role'] === 'negocio') {
                // negocio ve: total que pagó el cliente, y su ingreso neto (subtotal - service_fee)
                $order['total']      = $order['client_total'];
                $order['net_income'] = (float)$order['subtotal'] - (float)$order['service_fee'];
            } elseif (in_array($user['role'], ['cliente', 'repartidor'])) {
                $order['total']       = $order['client_total'];
                $order['service_fee'] = null;
            }
        }

        Response::success(['orders' => $orders]);
    }

    // GET /api/orders/{id}
    public function show(int $id): void {
        $user  = AuthMiddleware::authenticate();
        $order = $this->findOrder($id);
        $this->authorizeOrder($order, $user);

        $order['items']      = $this->getItems($id);
        $order['status_log'] = $this->getStatusLog($id);

        $order['client_total'] = (float)$order['subtotal'] + (float)$order['delivery_fee'];
        if ($user['role'] === 'negocio') {
            $order['total']      = $order['client_total'];
            $order['net_income'] = (float)$order['subtotal'] - (float)$order['service_fee'];
        } elseif (in_array($user['role'], ['cliente', 'repartidor'])) {
            $order['total']       = $order['client_total'];
            $order['service_fee'] = null;
        }

        Response::success($order);
    }

    // POST /api/orders  (cliente creates order)
    public function store(array $body): void {
        $user = AuthMiddleware::requireRole('cliente');

        $businessId    = (int)($body['business_id'] ?? 0);
        $deliveryType  = in_array($body['delivery_type'] ?? '', ['pickup','delivery']) ? $body['delivery_type'] : 'pickup';
        $notes         = $body['notes'] ?? '';
        $items         = $body['items'] ?? [];

        if (!$businessId) Response::error('Negocio requerido', 400);
        if (empty($items)) Response::error('El pedido debe tener al menos un producto', 400);
        if ($deliveryType === 'delivery' && empty($body['delivery_address'])) {
            Response::error('Dirección de entrega requerida', 400);
        }

        // Verify business exists
        $stmt = $this->db->prepare("SELECT * FROM businesses WHERE id = ? AND is_active = 1");
        $stmt->execute([$businessId]);
        $business = $stmt->fetch();
        if (!$business) Response::notFound('Negocio no encontrado');

        // Calculate fees
        $serviceFee  = SERVICE_FEE;
        $deliveryFee = ($deliveryType === 'delivery' && $business['accepts_delivery']) ? (float)$business['delivery_fee'] : 0;

        // Subtotal from known products
        $subtotal = 0;
        $orderItems = [];
        foreach ($items as $item) {
            $productId   = isset($item['product_id']) ? (int)$item['product_id'] : null;
            $productName = Security::sanitize($item['name'] ?? '');
            $quantity    = max(1, (int)($item['quantity'] ?? 1));
            $unitPrice   = null;

            if ($productId) {
                $pStmt = $this->db->prepare("SELECT * FROM products_services WHERE id = ? AND business_id = ? AND is_available = 1");
                $pStmt->execute([$productId, $businessId]);
                $product = $pStmt->fetch();
                if ($product) {
                    $productName = $product['name'];
                    $unitPrice   = (float)$product['price'];
                    $subtotal   += $unitPrice * $quantity;
                }
            }
            $orderItems[] = [
                'product_id'   => $productId,
                'product_name' => $productName ?: 'Producto personalizado',
                'quantity'     => $quantity,
                'unit_price'   => $unitPrice,
                'notes'        => Security::sanitize($item['notes'] ?? ''),
            ];
        }

        $total = $subtotal + $serviceFee + $deliveryFee;

        // Create order
        $orderNumber = 'PED-' . strtoupper(substr(uniqid(), -8));
        $stmt = $this->db->prepare("
            INSERT INTO orders
                (order_number, client_id, business_id, delivery_type, delivery_address, notes,
                 subtotal, service_fee, delivery_fee, total)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $orderNumber, $user['id'], $businessId, $deliveryType,
            $body['delivery_address'] ?? null, $notes,
            $subtotal, $serviceFee, $deliveryFee, $total
        ]);
        $orderId = $this->db->lastInsertId();

        // Insert items
        foreach ($orderItems as $item) {
            $this->db->prepare("
                INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, notes)
                VALUES (?,?,?,?,?,?)
            ")->execute([$orderId, $item['product_id'], $item['product_name'], $item['quantity'], $item['unit_price'], $item['notes']]);
        }

        // Log status
        $this->logStatus($orderId, 'pendiente', 'Pedido recibido', $user['id']);

        // Notify business
        $this->notify($business['user_id'], 'new_order', '🛒 Nuevo pedido', "Tienes un nuevo pedido #{$orderNumber}", '/negocio/index.html');



        Response::success([
            'order_id'     => $orderId,
            'order_number' => $orderNumber,
            'subtotal'     => $subtotal,
            'delivery_fee' => $deliveryFee,
            'total'        => $subtotal + $deliveryFee, // service_fee es ingreso interno, no visible al cliente
        ], 'Pedido creado exitosamente', 201);
    }

    // PUT /api/orders/{id}/respond  (negocio responds)
    public function businessRespond(int $id, array $body): void {
        $user  = AuthMiddleware::requireRole('negocio');
        $order = $this->findOrder($id);
        $biz   = $this->getUserBusiness($user['id']);

        if ($order['business_id'] !== $biz['id']) Response::forbidden();
        if ($order['status'] !== 'pendiente') Response::error('El pedido ya fue procesado', 400);

        $action   = $body['action'] ?? '';
        $response = Security::sanitize($body['response'] ?? '');
        $estTime  = isset($body['estimated_time']) ? (int)$body['estimated_time'] : null;

        if (!in_array($action, ['aceptar', 'rechazar'])) Response::error('Acción inválida', 400);

        $newStatus = $action === 'aceptar' ? 'aceptado' : 'cancelado';
        $this->db->prepare("UPDATE orders SET status = ?, business_response = ?, estimated_time = ? WHERE id = ?")
                 ->execute([$newStatus, $response, $estTime, $id]);

        $this->logStatus($id, $newStatus, $response, $user['id']);

        // If accepted and delivery type, create delivery record + notify repartidores
        if ($newStatus === 'aceptado' && $order['delivery_type'] === 'delivery') {
            try {
                $this->db->prepare("INSERT INTO deliveries (order_id) VALUES (?)")->execute([$id]);
            } catch (\Exception $e) {
                error_log("deliveries insert error order $id: " . $e->getMessage());
            }
            try {
                $this->notifyRepartidores("🛵 Pedido disponible para tomar",
                    "Nuevo delivery #{$order['order_number']} — ¡Tómalo antes que otro!");
            } catch (\Exception $e) {
                error_log("notifyRepartidores error: " . $e->getMessage());
            }
        }

        // Notify client
        try {
            $msg = $action === 'aceptar'
                ? "Tu pedido #{$order['order_number']} fue aceptado. Tiempo estimado: {$estTime} min."
                : "Tu pedido #{$order['order_number']} fue rechazado. {$response}";
            $this->notify($order['client_id'], 'order_update', '📦 Actualización de pedido', $msg, '/cliente/pedido-detalle.html?id='.$id);
        } catch (\Exception $e) {
            error_log("notify client error: " . $e->getMessage());
        }

        Response::success(null, 'Respuesta enviada');
    }

    // PUT /api/orders/{id}/status  (negocio or repartidor updates status)
    public function updateStatus(int $id, array $body): void {
        $user   = AuthMiddleware::requireRole(['negocio', 'repartidor', 'admin']);
        $order  = $this->findOrder($id);
        $status = Security::sanitize($body['status'] ?? '');

        $validStatuses = ['en_preparacion', 'listo', 'en_camino', 'entregado', 'cancelado'];
        if (!in_array($status, $validStatuses)) Response::error('Estado inválido', 400);

        $this->db->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$status, $id]);
        $this->logStatus($id, $status, $body['message'] ?? null, $user['id']);

        // Notify client
        $statusLabels = [
            'en_preparacion' => 'en preparación',
            'listo'          => 'listo para recoger',
            'en_camino'      => 'en camino',
            'entregado'      => 'entregado',
            'cancelado'      => 'cancelado',
        ];
        $this->notify($order['client_id'], 'order_update', '📦 Actualización de pedido',
            "Tu pedido #{$order['order_number']} está {$statusLabels[$status]}.");

        Response::success(null, 'Estado actualizado');
    }

    // PUT /api/orders/{id}
    public function update(int $id, array $body): void {
        $this->updateStatus($id, $body);
    }

    // --------------------------------------------------------
    // Private helpers
    // --------------------------------------------------------
    private function findOrder(int $id): array {
        $stmt = $this->db->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) Response::notFound('Pedido no encontrado');
        return $order;
    }

    private function authorizeOrder(array $order, array $user): void {
        if ($user['role'] === 'admin') return;
        if ($user['role'] === 'cliente' && $order['client_id'] === $user['id']) return;
        if ($user['role'] === 'negocio') {
            $biz = $this->getUserBusiness($user['id']);
            if ($order['business_id'] === $biz['id']) return;
        }
        Response::forbidden();
    }

    private function getUserBusiness(int $userId): array {
        $stmt = $this->db->prepare("SELECT * FROM businesses WHERE user_id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$userId]);
        $biz = $stmt->fetch();
        if (!$biz) Response::error('No tienes un negocio registrado', 403);
        return $biz;
    }

    private function getItems(int $orderId): array {
        $stmt = $this->db->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }

    private function getStatusLog(int $orderId): array {
        $stmt = $this->db->prepare("
            SELECT sl.*, u.name as changed_by_name
            FROM order_status_log sl
            LEFT JOIN users u ON sl.changed_by = u.id
            WHERE sl.order_id = ?
            ORDER BY sl.created_at ASC
        ");
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }

    private function logStatus(int $orderId, string $status, ?string $message, ?int $userId): void {
        $this->db->prepare("INSERT INTO order_status_log (order_id, status, message, changed_by) VALUES (?,?,?,?)")
                 ->execute([$orderId, $status, $message, $userId]);
    }

    private function notify(int $userId, string $type, string $title, string $message, string $url = '/'): void {
        $data = json_encode(['url' => $url]);
        $this->db->prepare("INSERT INTO notifications (user_id, type, title, message, data) VALUES (?,?,?,?,?)")
                 ->execute([$userId, $type, $title, $message, $data]);
        // Send real push via OneSignal
        PushNotification::send($userId, $title, $message, $url);
    }

    private function notifyRepartidores(string $title, string $message): void {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE role_id = 4 AND is_active = 1");
        $stmt->execute();
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        error_log('notifyRepartidores — encontrados: ' . count($ids) . ' — ids: ' . implode(',', $ids));
        if (empty($ids)) return;
        PushNotification::sendToMany($ids, $title, $message, '/repartidor/index.html');
    }
}
