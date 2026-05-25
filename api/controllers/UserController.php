<?php
// ============================================================
// User Profile Controller
// ============================================================
class UserController {
    private PDO $db;
    public function __construct() { $this->db = Database::connect(); }

    // GET /api/user/profile
    public function profile(): void {
        $user = AuthMiddleware::authenticate();
        $stmt = $this->db->prepare("SELECT u.id, u.name, u.email, u.phone, u.created_at, u.last_login, r.name as role FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $stmt->execute([$user['id']]);
        Response::success($stmt->fetch());
    }

    // PUT /api/user/profile
    public function updateProfile(array $body): void {
        $user   = AuthMiddleware::authenticate();
        $name   = Security::sanitize(trim($body['name'] ?? ''));
        $phone  = Security::sanitize(trim($body['phone'] ?? ''));
        $errors = [];
        if ($name && strlen($name) < 2) $errors['name'] = 'Nombre muy corto';
        if ($phone && !Security::validatePhone($phone)) $errors['phone'] = 'Teléfono inválido';
        if ($errors) Response::error('Datos inválidos', 422, $errors);

        $sets = []; $params = [];
        if ($name)  { $sets[] = 'name = ?';  $params[] = $name; }
        if ($phone) { $sets[] = 'phone = ?'; $params[] = $phone; }
        if (!$sets) Response::error('Sin datos para actualizar', 400);
        $params[] = $user['id'];
        $this->db->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        Response::success(null, 'Perfil actualizado');
    }

    // PUT /api/user/password
    public function changePassword(array $body): void {
        $user        = AuthMiddleware::authenticate();
        $current     = $body['current_password'] ?? '';
        $newPassword = $body['new_password'] ?? '';
        $confirm     = $body['confirm_password'] ?? '';

        if (!$current || !$newPassword) Response::error('Todos los campos son requeridos', 400);
        if ($newPassword !== $confirm) Response::error('Las contraseñas no coinciden', 400);
        if (strlen($newPassword) < 8) Response::error('Mínimo 8 caracteres', 400);
        if (!preg_match('/[A-Z]/', $newPassword)) Response::error('Debe incluir una mayúscula', 400);
        if (!preg_match('/[0-9]/', $newPassword)) Response::error('Debe incluir un número', 400);

        $stmt = $this->db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();
        if (!Security::verifyPassword($current, $row['password_hash'])) {
            Response::error('Contraseña actual incorrecta', 401);
        }
        $hash = Security::hashPassword($newPassword);
        $this->db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $user['id']]);
        Response::success(null, 'Contraseña actualizada');
    }

    // PUT /api/admin/users/{id}/toggle  (admin only)
    public function adminToggle(int $id): void {
        AuthMiddleware::requireRole('admin');
        $stmt = $this->db->prepare("SELECT id, is_active FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if (!$u) Response::notFound('Usuario no encontrado');
        $newStatus = $u['is_active'] ? 0 : 1;
        $this->db->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$newStatus, $id]);
        if (!$newStatus) $this->db->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$id]);
        Response::success(['is_active' => $newStatus], $newStatus ? 'Usuario activado' : 'Usuario desactivado');
    }
}
