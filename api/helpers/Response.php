<?php
// ============================================================
// Response Helper — Standardized JSON responses
// ============================================================

class Response {

    public static function json(array $data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success(mixed $data = null, string $message = 'OK', int $code = 200): void {
        self::json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    public static function error(string $message = 'Error', int $code = 400, mixed $errors = null): void {
        $payload = [
            'success' => false,
            'message' => $message,
        ];
        if ($errors !== null) $payload['errors'] = $errors;
        self::json($payload, $code);
    }

    public static function unauthorized(string $message = 'No autorizado'): void {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'Acceso denegado'): void {
        self::error($message, 403);
    }

    public static function notFound(string $message = 'No encontrado'): void {
        self::error($message, 404);
    }

    public static function serverError(string $message = 'Error interno del servidor'): void {
        self::error($message, 500);
    }
}
