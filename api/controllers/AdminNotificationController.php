<?php
// ============================================================
// Admin Controller
// ============================================================
class AdminController {
    private $db;
    public function __construct() { $this->db = Database::connect(); }

    public function dashboard(): void {
        AuthMiddleware::requireRole('admin');
        $stats = [
            'total_businesses' => $this->db->query("SELECT COUNT(*) FROM businesses WHERE is_active=1")->fetchColumn(),
            'total_users'      => $this->db->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn(),
            'total_orders'     => $this->db->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
            'orders_today'     => $this->db->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
            'revenue_today'    => $this->db->query("SELECT COALESCE(SUM(service_fee),0) FROM orders WHERE DATE(created_at)=CURDATE() AND status NOT IN ('cancelado')")->fetchColumn(),
            'pending_orders'   => $this->db->query("SELECT COUNT(*) FROM orders WHERE status='pendiente'")->fetchColumn(),
            'available_deliveries' => $this->db->query("SELECT COUNT(*) FROM deliveries WHERE status='disponible'")->fetchColumn(),
        ];
        $recentOrders = $this->db->query("SELECT o.*, b.name as business_name, u.name as client_name FROM orders o JOIN businesses b ON o.business_id=b.id JOIN users u ON o.client_id=u.id ORDER BY o.created_at DESC LIMIT 200")->fetchAll();
        Response::success(['stats' => $stats, 'recent_orders' => $recentOrders]);
    }

    public function users(): void {
        AuthMiddleware::requireRole('admin');
        $users = $this->db->query("SELECT u.*, r.name as role FROM users u JOIN roles r ON u.role_id=r.id ORDER BY u.created_at DESC")->fetchAll();
        Response::success($users);
    }

    public function businesses(): void {
        AuthMiddleware::requireRole('admin');
        $businesses = $this->db->query("SELECT b.*, bc.name as category_name, u.name as owner_name FROM businesses b JOIN business_categories bc ON b.category_id=bc.id JOIN users u ON b.user_id=u.id ORDER BY b.created_at DESC")->fetchAll();
        Response::success($businesses);
    }

    public function orders(): void {
        AuthMiddleware::requireRole('admin');
        $orders = $this->db->query("SELECT o.*, b.name as business_name, u.name as client_name FROM orders o JOIN businesses b ON o.business_id=b.id JOIN users u ON o.client_id=u.id ORDER BY o.created_at DESC LIMIT 2000")->fetchAll();
        Response::success($orders);
    }

    public function deleteOrder($id): void {
        AuthMiddleware::requireRole('admin');
        $stmt = $this->db->prepare("SELECT id FROM orders WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) Response::notFound('Pedido no encontrado');
        // Delete all related records
        $this->db->prepare("DELETE FROM order_messages WHERE order_id = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM order_status_log WHERE order_id = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM deliveries WHERE order_id = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM orders WHERE id = ?")->execute([$id]);
        Response::success(null, 'Pedido eliminado correctamente');
    }

    public function assignBusiness($bizId, $userId) {
        AuthMiddleware::requireRole('admin');
        if (!$bizId || !$userId) Response::error('Datos requeridos', 400);
        $this->db->prepare("UPDATE businesses SET user_id = ? WHERE id = ?")
                 ->execute([$userId, $bizId]);
        Response::success(null, 'Negocio asignado al propietario');
    }

}

// ============================================================
// Notification Controller
// ============================================================
class NotificationController {
    private $db;
    public function __construct() { $this->db = Database::connect(); }

    public function index(): void {
        $user = AuthMiddleware::authenticate();
        $stmt = $this->db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $stmt->execute([$user['id']]);
        $notifications = $stmt->fetchAll();
        $unread = $this->db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $unread->execute([$user['id']]);
        Response::success(['notifications' => $notifications, 'unread_count' => (int)$unread->fetchColumn()]);
    }

    public function markRead(?int $id): void {
        $user = AuthMiddleware::authenticate();
        if ($id) {
            $this->db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$id, $user['id']]);
        }
        Response::success(null, 'Marcado como leído');
    }

    public function markAllRead(): void {
        $user = AuthMiddleware::authenticate();
        $this->db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$user['id']]);
        Response::success(null, 'Todas marcadas como leídas');
    }

    // POST /api/admin/reset-data — eliminar datos de prueba
    public function resetData(array $body): void {
        error_log("resetData called, body: " . json_encode($body));
        $user = AuthMiddleware::requireRole('admin');
        error_log("resetData user: " . json_encode($user['id']));

        // Confirmación doble obligatoria
        $confirm = $body['confirm'] ?? '';
        if ($confirm !== 'CONFIRMAR_RESET') {
            Response::error('Confirmación inválida', 400);
        }

        try {
            $this->db->exec("SET FOREIGN_KEY_CHECKS = 0");

            $tables = [
                'order_status_log',
                'order_messages',
                'order_items',
                'deliveries',
                'notifications',
                'email_verifications',
                'orders',
            ];

            $cleaned = [];
            $skipped = [];

            foreach ($tables as $table) {
                // Verificar que la tabla existe antes de borrar
                $check = $this->db->query("SHOW TABLES LIKE '{$table}'")->fetch();
                if (!$check) { $skipped[] = $table; continue; }
                $this->db->exec("DELETE FROM `{$table}`");
                try { $this->db->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = 1"); } catch(\Exception $e) {}
                $cleaned[] = $table;
            }

            $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");

            Response::success([
                'cleaned' => $cleaned,
                'skipped' => $skipped
            ], 'Base de datos limpiada. Usuarios, negocios y categorías conservados.');

        } catch (\Exception $e) {
            $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");
            error_log("Reset error: " . $e->getMessage());
            Response::error('Error: ' . $e->getMessage(), 500);
        }

        error_log("[RESET] Admin user {$user['id']} ({$user['name']}) ejecutó reset de datos de prueba");

        Response::success(null, 'Base de datos limpiada. Usuarios, negocios y categorías conservados.');
    }

    public function sendAviso(array $body): void {
        $user = AuthMiddleware::requireRole('admin');
        $title = trim($body['title'] ?? '');
        $desc  = trim($body['description'] ?? '');
        if (!$title || !$desc) Response::error('Título y descripción son requeridos', 400);

        // Obtener todos los usuarios con rol negocio activos
        $stmt = $this->db->prepare("
            SELECT u.id FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE r.name = 'negocio' AND u.is_active = 1
        ");
        $stmt->execute();
        $userIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $notifTitle = "📢 {$title}";
        $insertStmt = $this->db->prepare("INSERT INTO notifications (user_id, type, title, message, data) VALUES (?,?,?,?,?)");
        foreach ($userIds as $uid) {
            $insertStmt->execute([$uid, 'admin_aviso', $notifTitle, $desc, json_encode(['url' => '/negocio/index.html'])]);
        }

        if (!empty($userIds)) {
            PushNotification::sendToMany($userIds, $notifTitle, $desc, '/negocio/index.html');
        }

        // Guardar en historial
        $this->db->prepare("INSERT INTO admin_avisos (title, description, recipient_count, created_by) VALUES (?,?,?,?)")
                 ->execute([$title, $desc, count($userIds), $user['id']]);

        Response::success(['count' => count($userIds)], 'Aviso enviado');
    }

    public function getAvisos(): void {
        AuthMiddleware::requireRole('admin');
        $stmt = $this->db->query("SELECT * FROM admin_avisos ORDER BY created_at DESC LIMIT 30");
        Response::success($stmt->fetchAll());
    }
}
