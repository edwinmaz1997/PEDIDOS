<?php
class PromotionController {
    private $db;
    public function __construct() { $this->db = Database::connect(); }

    // GET /api/promotions — cliente ve promociones vigentes
    public function index(): void {
        AuthMiddleware::authenticate();
        try {
            $today = date('Y-m-d');
            // Auto-desactivar vencidas
            $this->db->prepare("UPDATE promotions SET is_active = 0 WHERE ends_at < ? AND is_active = 1")
                     ->execute([$today]);

            $stmt = $this->db->prepare("
                SELECT p.*, b.name as business_name, b.logo as business_logo
                FROM promotions p
                JOIN businesses b ON b.id = p.business_id
                WHERE p.is_active = 1 AND p.starts_at <= ? AND p.ends_at >= ? AND b.is_active = 1
                ORDER BY p.created_at DESC
            ");
            $stmt->execute([$today, $today]);
            $promos = $stmt->fetchAll();
            foreach ($promos as &$promo) {
                $iStmt = $this->db->prepare("SELECT * FROM promotion_items WHERE promotion_id = ?");
                $iStmt->execute([$promo['id']]);
                $promo['items'] = $iStmt->fetchAll();
            }
            Response::success($promos);
        } catch (\Exception $e) {
            error_log("promotions index: " . $e->getMessage());
            Response::success([]);
        }
    }

    // GET /api/promotions/mine — negocio ve sus promociones
    public function mine(): void {
        $user = AuthMiddleware::requireRole('negocio');
        try {
            $biz  = $this->getBiz($user['id']);
            // Auto-desactivar vencidas
            $this->db->prepare("UPDATE promotions SET is_active = 0 WHERE business_id = ? AND ends_at < CURDATE() AND is_active = 1")
                     ->execute([$biz['id']]);

            $stmt = $this->db->prepare("SELECT * FROM promotions WHERE business_id = ? ORDER BY created_at DESC");
            $stmt->execute([$biz['id']]);
            $promos = $stmt->fetchAll();
            foreach ($promos as &$promo) {
                $iStmt = $this->db->prepare("SELECT * FROM promotion_items WHERE promotion_id = ?");
                $iStmt->execute([$promo['id']]);
                $promo['items'] = $iStmt->fetchAll();
            }
            Response::success($promos);
        } catch (\Exception $e) {
            error_log("promotions/mine: " . $e->getMessage());
            Response::error('Ejecuta las migraciones SQL primero. Tablas de promociones no encontradas.', 500);
        }
    }

    // POST /api/promotions — crear promoción
    public function store(array $body): void {
        $user  = AuthMiddleware::requireRole('negocio');
        $biz   = $this->getBiz($user['id']);
        $title = trim($body['title'] ?? '');
        $desc  = trim($body['description'] ?? '');
        $start = $body['starts_at'] ?? '';
        $end   = $body['ends_at'] ?? '';
        $items = $body['items'] ?? [];

        if (!$title || !$desc || !$start || !$end) Response::error('Faltan campos requeridos', 400);
        if (empty($items)) Response::error('Agrega al menos un producto', 400);
        if ($end < $start) Response::error('La fecha fin debe ser mayor a la de inicio', 400);

        $this->db->prepare("INSERT INTO promotions (business_id, title, description, starts_at, ends_at) VALUES (?,?,?,?,?)")
                 ->execute([$biz['id'], $title, $desc, $start, $end]);
        $promoId = $this->db->lastInsertId();

        foreach ($items as $item) {
            $this->db->prepare("INSERT INTO promotion_items (promotion_id, product_id, product_name, original_price, promo_price) VALUES (?,?,?,?,?)")
                     ->execute([$promoId, $item['product_id'] ?? null, $item['product_name'], $item['original_price'], $item['promo_price']]);
        }

        $this->notifyClients($biz['name'], $title, $desc, $promoId);
        $this->db->prepare("UPDATE promotions SET notified_at = NOW() WHERE id = ?")->execute([$promoId]);
        Response::success(['id' => $promoId], 'Promoción creada y clientes notificados');
    }

    // PUT /api/promotions/{id}
    public function update(int $id, array $body): void {
        $user  = AuthMiddleware::requireRole('negocio');
        $biz   = $this->getBiz($user['id']);
        $promo = $this->getPromo($id, $biz['id']);

        $this->db->prepare("UPDATE promotions SET title=?, description=?, starts_at=?, ends_at=?, is_active=? WHERE id=?")
                 ->execute([
                     $body['title']       ?? $promo['title'],
                     $body['description'] ?? $promo['description'],
                     $body['starts_at']   ?? $promo['starts_at'],
                     $body['ends_at']     ?? $promo['ends_at'],
                     isset($body['is_active']) ? (int)$body['is_active'] : $promo['is_active'],
                     $id
                 ]);

        if (!empty($body['items'])) {
            $this->db->prepare("DELETE FROM promotion_items WHERE promotion_id = ?")->execute([$id]);
            foreach ($body['items'] as $item) {
                $this->db->prepare("INSERT INTO promotion_items (promotion_id, product_id, product_name, original_price, promo_price) VALUES (?,?,?,?,?)")
                         ->execute([$id, $item['product_id'] ?? null, $item['product_name'], $item['original_price'], $item['promo_price']]);
            }
        }
        Response::success(null, 'Promoción actualizada');
    }

    // DELETE /api/promotions/{id}
    public function destroy(int $id): void {
        $user = AuthMiddleware::requireRole('negocio');
        $biz  = $this->getBiz($user['id']);
        $this->getPromo($id, $biz['id']);
        $this->db->prepare("DELETE FROM promotions WHERE id = ?")->execute([$id]);
        Response::success(null, 'Promoción eliminada');
    }

    private function getBiz(int $userId): array {
        $stmt = $this->db->prepare("SELECT * FROM businesses WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $biz = $stmt->fetch();
        if (!$biz) Response::error('Negocio no encontrado', 404);
        return $biz;
    }

    private function getPromo(int $id, int $bizId): array {
        $stmt = $this->db->prepare("SELECT * FROM promotions WHERE id = ? AND business_id = ?");
        $stmt->execute([$id, $bizId]);
        $promo = $stmt->fetch();
        if (!$promo) Response::notFound('Promoción no encontrada');
        return $promo;
    }

    private function notifyClients(string $bizName, string $title, string $desc, int $promoId): void {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE role_id = 3 AND is_active = 1");
        $stmt->execute();
        $clientIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($clientIds)) return;

        $notifTitle = "🏷️ Promoción de {$bizName}";
        $notifMsg   = "{$title}: {$desc}";
        PushNotification::sendToMany($clientIds, $notifTitle, $notifMsg, '/cliente/promociones.html');

        $insertStmt = $this->db->prepare("INSERT INTO notifications (user_id, type, title, message, data) VALUES (?,?,?,?,?)");
        foreach (array_slice($clientIds, 0, 200) as $clientId) {
            $insertStmt->execute([$clientId, 'promotion', $notifTitle, $notifMsg, json_encode(['promotion_id' => $promoId])]);
        }
    }
}
