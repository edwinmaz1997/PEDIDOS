<?php
// ============================================================
// Photo Controller — business_photos CRUD
// ============================================================
class PhotoController {
    private PDO $db;
    public function __construct() { $this->db = Database::connect(); }

    // POST /api/businesses/{id}/photos
    public function store(int $bizId, array $body): void {
        $user = AuthMiddleware::requireRole(['negocio','admin']);
        $this->verifyOwnership($bizId, $user);
        $url = Security::sanitize($body['url'] ?? '');
        if (!$url) Response::error('URL requerida', 400);
        $count = $this->db->prepare("SELECT COUNT(*) FROM business_photos WHERE business_id = ?");
        $count->execute([$bizId]);
        if ($count->fetchColumn() >= 10) Response::error('Máximo 10 fotos por negocio', 400);
        $this->db->prepare("INSERT INTO business_photos (business_id, photo_url, caption) VALUES (?,?,?)")
                 ->execute([$bizId, $url, $body['caption'] ?? null]);
        Response::success(['id' => $this->db->lastInsertId()], 'Foto agregada', 201);
    }

    // DELETE /api/businesses/{bizId}/photos/{photoId}
    public function destroy(int $bizId, int $photoId, array $user): void {
        $this->verifyOwnership($bizId, $user);
        $this->db->prepare("DELETE FROM business_photos WHERE id = ? AND business_id = ?")->execute([$photoId, $bizId]);
        Response::success(null, 'Foto eliminada');
    }

    private function verifyOwnership(int $bizId, array $user): void {
        if ($user['role'] === 'admin') return;
        $stmt = $this->db->prepare("SELECT user_id FROM businesses WHERE id = ?");
        $stmt->execute([$bizId]);
        $biz = $stmt->fetch();
        if (!$biz || $biz['user_id'] !== $user['id']) Response::forbidden();
    }
}
