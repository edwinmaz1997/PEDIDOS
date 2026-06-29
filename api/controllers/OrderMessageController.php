<?php
// ============================================================
// Order Messages Controller — Chat por pedido
// ============================================================
class OrderMessageController {
    private $db;
    public function __construct() { $this->db = Database::connect(); }

    // GET /api/orders/{id}/messages[?after={id}]
    public function index($orderId) {
        $user  = AuthMiddleware::authenticate();
        $order = $this->getOrder($orderId, $user);
        $after = isset($_GET['after']) ? (int)$_GET['after'] : 0;

        // Mark messages as read
        $this->db->prepare("UPDATE order_messages SET is_read = 1 WHERE order_id = ? AND sender_id != ?")
                 ->execute([$orderId, $user['id']]);

        if ($after > 0) {
            // Only fetch new messages since last known id
            $stmt = $this->db->prepare("
                SELECT m.*, u.name as sender_name
                FROM order_messages m
                JOIN users u ON m.sender_id = u.id
                WHERE m.order_id = ? AND m.id > ?
                ORDER BY m.created_at ASC
            ");
            $stmt->execute([$orderId, $after]);
            $messages = $stmt->fetchAll();

            // Only return data if there are new messages
            if (empty($messages)) {
                Response::success(['messages' => [], 'order' => null, 'unread_count' => 0]);
                return;
            }
        } else {
            // Full load on first request
            $stmt = $this->db->prepare("
                SELECT m.*, u.name as sender_name
                FROM order_messages m
                JOIN users u ON m.sender_id = u.id
                WHERE m.order_id = ?
                ORDER BY m.created_at ASC
            ");
            $stmt->execute([$orderId]);
            $messages = $stmt->fetchAll();
        }

        $unread = $this->db->prepare("SELECT COUNT(*) FROM order_messages WHERE order_id = ? AND sender_id != ? AND is_read = 0");
        $unread->execute([$orderId, $user['id']]);

        Response::success([
            'messages'     => $messages,
            'order'        => $order,
            'unread_count' => (int)$unread->fetchColumn()
        ]);
    }

    // POST /api/orders/{id}/messages
    public function store($orderId, $body) {
        $user    = AuthMiddleware::authenticate();
        $order   = $this->getOrder($orderId, $user);
        $message = trim($body['message'] ?? '');

        if (!$message) Response::error('El mensaje no puede estar vacío', 400);
        if (strlen($message) > 1000) Response::error('Mensaje muy largo (máx 1000 caracteres)', 400);

        $role = $user['role'];
        if (!in_array($role, ['cliente','negocio','admin','repartidor'])) Response::forbidden();

        $this->db->prepare("INSERT INTO order_messages (order_id, sender_id, sender_role, message) VALUES (?,?,?,?)")
                 ->execute([$orderId, $user['id'], $role, $message]);

        // Notify the other party según rol
        if ($role === 'cliente') {
            // Cliente → notificar negocio
            $bizStmt = $this->db->prepare("SELECT user_id FROM businesses WHERE id = ?");
            $bizStmt->execute([$order['business_id']]);
            $biz = $bizStmt->fetch();
            if ($biz) {
                $this->notify($biz['user_id'], 'new_message', '💬 Nuevo mensaje', "El cliente envió un mensaje en el pedido #{$order['order_number']}", '/negocio/pedido-detalle.html?id='.$orderId);
            }
        } elseif ($role === 'negocio') {
            // Negocio → notificar cliente
            $this->notify($order['client_id'], 'new_message', '💬 Respuesta del negocio', "El negocio respondió en tu pedido #{$order['order_number']}", '/cliente/pedido-detalle.html?id='.$orderId);
        }
        // repartidor y admin — no generan push de chat
        // sus acciones ya generan notificaciones propias (tomó, liberó, recogió, entregó)

        Response::success(['id' => $this->db->lastInsertId()], 'Mensaje enviado', 201);
    }

    // PUT /api/orders/{id}/total — negocio updates total
    public function updateTotal($orderId, $body) {
        $user  = AuthMiddleware::requireRole(['negocio', 'admin']);
        $order = $this->getOrder($orderId, $user);

        $subtotal    = isset($body['subtotal'])    ? (float)$body['subtotal']    : (float)$order['subtotal'];
        $serviceFee  = (float)$order['service_fee'];
        $deliveryFee = (float)$order['delivery_fee'];
        $total       = $subtotal + $serviceFee + $deliveryFee;
        $clientTotal = $subtotal + $deliveryFee; // lo que ve el cliente

        $this->db->prepare("UPDATE orders SET subtotal = ?, total = ? WHERE id = ?")
                 ->execute([$subtotal, $total, $orderId]);

        // Actualizar unit_price en order_items con los precios asignados por el negocio
        if (!empty($lines)) {
            // Obtener items actuales del pedido
            $itemStmt = $this->db->prepare("SELECT id, product_name FROM order_items WHERE order_id = ? ORDER BY id ASC");
            $itemStmt->execute([$orderId]);
            $dbItems = $itemStmt->fetchAll();
            foreach ($lines as $i => $line) {
                $linePrice = (float)($line['price'] ?? 0);
                $lineQty   = (int)($line['qty'] ?? 1);
                if (isset($dbItems[$i]) && $linePrice > 0) {
                    $this->db->prepare("UPDATE order_items SET unit_price = ?, quantity = ? WHERE id = ?")
                             ->execute([$linePrice, $lineQty, $dbItems[$i]['id']]);
                }
            }
        }

        // Notify client con total sin service_fee
        $this->notify($order['client_id'], 'total_updated', '💰 Total actualizado',
            "El negocio actualizó el total de tu pedido #{$order['order_number']} a Q" . number_format($clientTotal, 2), '/cliente/pedido-detalle.html?id='.$orderId);

        // Send system message
        // Build itemized message
        $lines   = $body['lines']   ?? [];
        $detail  = $body['detail']  ?? '';
        $msg     = "💰 *Desglose del pedido:*
";
        if (!empty($lines)) {
            foreach ($lines as $line) {
                $lineName  = Security::sanitize($line['name']  ?? '');
                $lineQty   = (int)($line['qty']   ?? 1);
                $linePrice = (float)($line['price'] ?? 0);
                if ($lineName) {
                    $lineTotal = $linePrice * $lineQty;
                    $msg .= "• {$lineName} ×{$lineQty}" . ($linePrice > 0 ? " = Q" . number_format($lineTotal, 2) : "") . "
";
                }
            }
        }
        // service_fee omitido del mensaje visible (ingreso interno NuevaExpress)
        if ($deliveryFee > 0) $msg .= "Delivery: Q" . number_format($deliveryFee, 2) . "
";
        $msg .= "✅ *Total: Q" . number_format($clientTotal, 2) . "*";

        $this->db->prepare("INSERT INTO order_messages (order_id, sender_id, sender_role, message) VALUES (?,?,?,?)")
                 ->execute([$orderId, $user['id'], $user['role'], $msg]);

        Response::success([
            'subtotal'    => $subtotal,
            'service_fee' => $serviceFee,
            'delivery_fee'=> $deliveryFee,
            'total'       => $clientTotal
        ], 'Total actualizado');
    }

    // GET /api/orders/unread — unread message counts per order
    public function unreadCounts() {
        $user = AuthMiddleware::authenticate();
        $stmt = $this->db->prepare("
            SELECT order_id, COUNT(*) as unread
            FROM order_messages
            WHERE sender_id != ? AND is_read = 0
            AND order_id IN (
                SELECT id FROM orders WHERE client_id = ? OR business_id IN (
                    SELECT id FROM businesses WHERE user_id = ?
                )
            )
            GROUP BY order_id
        ");
        $stmt->execute([$user['id'], $user['id'], $user['id']]);
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['order_id']] = (int)$row['unread'];
        }
        Response::success($counts);
    }

    private function getOrder($orderId, $user) {
        $stmt = $this->db->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) Response::notFound('Pedido no encontrado');

        // Verify access
        if ($user['role'] === 'admin') return $order;
        if ($user['role'] === 'cliente' && $order['client_id'] == $user['id']) return $order;
        if ($user['role'] === 'negocio') {
            $biz = $this->db->prepare("SELECT id FROM businesses WHERE user_id = ? AND id = ?");
            $biz->execute([$user['id'], $order['business_id']]);
            if ($biz->fetch()) return $order;
        }
        if ($user['role'] === 'repartidor') {
            $del = $this->db->prepare("SELECT id FROM deliveries WHERE order_id = ? AND repartidor_id = ?");
            $del->execute([$orderId, $user['id']]);
            if ($del->fetch()) return $order;
            // También puede ver si el pedido está disponible (aún no tomado)
            $avail = $this->db->prepare("SELECT id FROM deliveries WHERE order_id = ? AND status = 'disponible'");
            $avail->execute([$orderId]);
            if ($avail->fetch()) return $order;
        }
        Response::forbidden();
    }

    private function notify($userId, $type, $title, $message, $url = '/') {
        $this->db->prepare("INSERT INTO notifications (user_id, type, title, message, data) VALUES (?,?,?,?,?)")
                 ->execute([$userId, $type, $title, $message, json_encode(['url' => $url])]);
        PushNotification::send($userId, $title, $message, $url);
    }
}
