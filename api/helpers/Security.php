<?php
// ============================================================
// Security Helper — compatible PHP 7.4+
// ============================================================

class Security {

    public static function setSecurityHeaders() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob: https:; connect-src 'self'");
        if (defined('APP_ENV') && APP_ENV === 'production') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function sanitize($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitize'], $input);
        }
        if (is_string($input)) {
            return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $input;
    }

    public static function escape($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function validateEmail($email) {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public static function validatePhone($phone) {
        return (bool) preg_match('/^\+?[\d\s\-]{7,20}$/', $phone);
    }

    public static function generateCsrfToken() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();
        return $token;
    }

    public static function validateCsrfToken($token) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time'])) return false;
        if (time() - $_SESSION['csrf_token_time'] > CSRF_TOKEN_LIFETIME) return false;
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function checkRateLimit($ip, $endpoint, $max = RATE_LIMIT_MAX, $window = RATE_LIMIT_WINDOW) {
        $db = Database::connect();
        $windowStart = date('Y-m-d H:i:s', time() - $window);
        $db->prepare("DELETE FROM rate_limits WHERE window_start < ?")->execute([$windowStart]);
        $stmt = $db->prepare("SELECT attempts FROM rate_limits WHERE ip_address = ? AND endpoint = ? AND window_start >= ?");
        $stmt->execute([$ip, $endpoint, $windowStart]);
        $row = $stmt->fetch();
        if ($row) {
            if ($row['attempts'] >= $max) return false;
            $db->prepare("UPDATE rate_limits SET attempts = attempts + 1 WHERE ip_address = ? AND endpoint = ?")->execute([$ip, $endpoint]);
        } else {
            $db->prepare("INSERT INTO rate_limits (ip_address, endpoint) VALUES (?, ?)")->execute([$ip, $endpoint]);
        }
        return true;
    }

    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }

    public static function generateToken($length = 64) {
        return bin2hex(random_bytes($length / 2));
    }

    public static function getClientIp() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $forwarded = trim($parts[0]);
            if (filter_var($forwarded, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $ip = $forwarded;
            }
        }
        return $ip;
    }

    public static function validateImage($file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'message' => 'Error al subir el archivo'];
        }
        if ($file['size'] > MAX_FILE_SIZE) {
            return ['valid' => false, 'message' => 'El archivo excede el tamaño máximo de 5MB'];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!in_array($mime, ALLOWED_IMAGE_TYPES)) {
            return ['valid' => false, 'message' => 'Tipo de archivo no permitido. Use JPG, PNG o WebP'];
        }
        return ['valid' => true, 'mime' => $mime];
    }

    public static function saveImage($file, $folder = 'general') {
        $validation = self::validateImage($file);
        if (!$validation['valid']) return false;

        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];
        $ext = isset($mimeMap[$validation['mime']]) ? $mimeMap[$validation['mime']] : 'jpg';

        $uploadDir = UPLOAD_DIR . $folder . '/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $filename    = self::generateToken(32) . '.' . $ext;
        $destination = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            return UPLOAD_URL . $folder . '/' . $filename;
        }
        return false;
    }
}
