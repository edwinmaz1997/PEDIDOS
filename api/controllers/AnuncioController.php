<?php
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/PushNotification.php';
require_once __DIR__ . '/../config/database.php';

class AnuncioController {
    private $db;
    public function __construct() { $this->db = Database::connect(); }

    // GET /api/anuncios — todos los activos (clientes)
    public function index(): void {
        AuthMiddleware::authenticate();
        $stmt = $this->db->query("
            SELECT a.*, b.name as business_name, b.logo as business_logo
            FROM business_anuncios a
            JOIN businesses b ON b.id = a.business_id
            WHERE a.is_active = 1
            ORDER BY a.created_at DESC
        ");
        Response::success($stmt->fetchAll());
    }

    // GET /api/anuncios/mine — del negocio autenticado
    public function mine(): void {
        $user = AuthMiddleware::requireRole('negocio');
        $biz = $this->db->prepare("SELECT id FROM businesses WHERE user_id=? AND is_active=1 LIMIT 1");
        $biz->execute([$user['id']]);
        $biz = $biz->fetch();
        if (!$biz) Response::error('Negocio no encontrado', 404);
        $stmt = $this->db->prepare("SELECT * FROM business_anuncios WHERE business_id=? ORDER BY created_at DESC");
        $stmt->execute([$biz['id']]);
        Response::success($stmt->fetchAll());
    }

    // POST /api/anuncios
    public function store(array $body): void {
        $user = AuthMiddleware::requireRole('negocio');
        $bizStmt = $this->db->prepare("SELECT id, name FROM businesses WHERE user_id=? AND is_active=1 LIMIT 1");
        $bizStmt->execute([$user['id']]);
        $biz = $bizStmt->fetch();
        if (!$biz) Response::error('Negocio no encontrado', 404);
        if (empty($body['title']) || empty($body['description'])) Response::error('Título y descripción son requeridos', 422);

        $stmt = $this->db->prepare("INSERT INTO business_anuncios (business_id, title, description, image_url, is_active) VALUES (?,?,?,?,1)");
        $stmt->execute([$biz['id'], $body['title'], $body['description'], $body['image_url'] ?? null]);
        $id = $this->db->lastInsertId();

        // Notificar a todos los clientes activos
        $clients = $this->db->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='cliente' AND u.is_active=1")->fetchAll();
        $userIds = array_column($clients, 'id');
        if ($userIds) {
            PushNotification::sendToMany($userIds, '📢 Nuevo anuncio de '.$biz['name'], $body['title'], '/cliente/anuncios.html');
            // Insertar en tabla notifications
            $insStmt = $this->db->prepare("INSERT INTO notifications (user_id, type, title, message, data) VALUES (?,?,?,?,?)");
            foreach ($userIds as $uid) {
                $insStmt->execute([$uid, 'anuncio', '📢 '.$biz['name'], $body['title'], json_encode(['url'=>'/cliente/anuncios.html'])]);
            }
        }
        Response::success(['id' => (int)$id], 'Anuncio publicado');
    }

    // PUT /api/anuncios/{id}
    public function update(int $id, array $body): void {
        $user = AuthMiddleware::authenticate();
        if ($user['role'] === 'admin') {
            $stmt = $this->db->prepare("SELECT * FROM business_anuncios WHERE id=?");
            $stmt->execute([$id]);
            $anuncio = $stmt->fetch();
        } else {
            AuthMiddleware::requireRole('negocio');
            $bizStmt = $this->db->prepare("SELECT id FROM businesses WHERE user_id=? LIMIT 1");
            $bizStmt->execute([$user['id']]);
            $biz = $bizStmt->fetch();
            $stmt = $this->db->prepare("SELECT * FROM business_anuncios WHERE id=? AND business_id=?");
            $stmt->execute([$id, $biz['id']]);
            $anuncio = $stmt->fetch();
        }
        if (!$anuncio) Response::notFound('Anuncio no encontrado');

        $this->db->prepare("UPDATE business_anuncios SET title=?, description=?, image_url=COALESCE(?,image_url), is_active=? WHERE id=?")
            ->execute([
                $body['title'] ?? $anuncio['title'],
                $body['description'] ?? $anuncio['description'],
                $body['image_url'] ?? null,
                isset($body['is_active']) ? (int)$body['is_active'] : $anuncio['is_active'],
                $id
            ]);
        Response::success(null, 'Anuncio actualizado');
    }

    // DELETE /api/anuncios/{id}
    public function destroy(int $id): void {
        $user = AuthMiddleware::authenticate();
        if ($user['role'] === 'admin') {
            $this->db->prepare("DELETE FROM business_anuncios WHERE id=?")->execute([$id]);
        } else {
            AuthMiddleware::requireRole('negocio');
            $bizStmt = $this->db->prepare("SELECT id FROM businesses WHERE user_id=? LIMIT 1");
            $bizStmt->execute([$user['id']]);
            $biz = $bizStmt->fetch();
            $this->db->prepare("DELETE FROM business_anuncios WHERE id=? AND business_id=?")->execute([$id, $biz['id']]);
        }
        Response::success(null, 'Anuncio eliminado');
    }

    // POST /api/anuncios/{id}/imagen
    public function uploadImage(int $id): void {
        $user = AuthMiddleware::requireRole('negocio');
        if (empty($_FILES['image'])) Response::error('No se recibió imagen', 422);
        $bizStmt = $this->db->prepare("SELECT id FROM businesses WHERE user_id=? LIMIT 1");
        $bizStmt->execute([$user['id']]);
        $biz = $bizStmt->fetch();
        $file = $_FILES['image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) Response::error('Formato no válido', 422);
        $filename = 'anuncio_' . $id . '_' . time() . '.' . $ext;
        $dest = __DIR__ . '/../../uploads/anuncios/' . $filename;
        if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
        if (!move_uploaded_file($file['tmp_name'], $dest)) Response::error('Error al subir imagen', 500);
        $url = 'https://nuevaexpress.com/uploads/anuncios/' . $filename;
        $this->db->prepare("UPDATE business_anuncios SET image_url=? WHERE id=?")->execute([$url, $id]);
        Response::success(['url' => $url]);
    }
}
