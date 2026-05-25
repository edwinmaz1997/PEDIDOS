<?php
// ============================================================
// Response Helper — compatible PHP 7.4+
// ============================================================

class Response {

    public static function json($data, $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success($data = null, $message = 'OK', $code = 200) {
        self::json(['success' => true, 'message' => $message, 'data' => $data], $code);
    }

    public static function error($message = 'Error', $code = 400, $errors = null) {
        $payload = ['success' => false, 'message' => $message];
        if ($errors !== null) $payload['errors'] = $errors;
        self::json($payload, $code);
    }

    public static function unauthorized($message = 'No autorizado') {
        self::error($message, 401);
    }

    public static function forbidden($message = 'Acceso denegado') {
        self::error($message, 403);
    }

    public static function notFound($message = 'No encontrado') {
        self::error($message, 404);
    }

    public static function serverError($message = 'Error interno del servidor') {
        self::error($message, 500);
    }
}
