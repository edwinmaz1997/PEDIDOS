<?php
// ============================================================
// Auth Middleware — compatible PHP 7.4+
// ============================================================

class AuthMiddleware {

    public static function authenticate() {
        $token = self::extractToken();
        if (!$token) Response::unauthorized('Token no proporcionado');

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT s.user_id, s.expires_at, u.id, u.name, u.email, u.phone, u.address, u.google_maps_url, u.role_id, u.is_active, r.name as role
            FROM user_sessions s
            JOIN users u ON s.user_id = u.id
            JOIN roles r ON u.role_id = r.id
            WHERE s.token = ? AND s.expires_at > NOW()
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) Response::unauthorized('Sesión inválida o expirada. Para corregirlo, cierra sesión e inicia sesión nuevamente.');
        if (!$user['is_active']) Response::forbidden('Cuenta desactivada');

        $db->prepare("UPDATE user_sessions SET expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE token = ?")
           ->execute([SESSION_LIFETIME, $token]);

        return $user;
    }

    // $roles can be string or array
    public static function requireRole($roles) {
        $user  = self::authenticate();
        $roles = (array) $roles;
        if (!in_array($user['role'], $roles)) {
            Response::forbidden('No tienes permiso para esta acción');
        }
        return $user;
    }

    private static function extractToken() {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
}
