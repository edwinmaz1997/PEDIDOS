<?php
// ============================================================
// Security Helper
// Handles: XSS, CSRF, Input Sanitization, Security Headers
// ============================================================

class Security {

    // --------------------------------------------------------
    // Security Headers (call at top of every request)
    // --------------------------------------------------------
    public static function setSecurityHeaders(): void {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob: https:; connect-src 'self'");
        if (APP_ENV === 'production') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    // --------------------------------------------------------
    // Sanitize string — prevents XSS
    // --------------------------------------------------------
    public static function sanitize(mixed $input): mixed {
        if (is_array($input)) {
            return array_map([self::class, 'sanitize'], $input);
        }
        if (is_string($input)) {
            return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $input;
    }

    // --------------------------------------------------------
    // Sanitize for output in HTML (double encode protection)
    // --------------------------------------------------------
    public static function escape(string $str): string {
        return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // --------------------------------------------------------
    // Validate email
    // --------------------------------------------------------
    public static function validateEmail(string $email): bool {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    // --------------------------------------------------------
    // Validate phone (Guatemala format or international)
    // --------------------------------------------------------
    public static function validatePhone(string $phone): bool {
        return (bool) preg_match('/^\+?[\d\s\-]{7,20}$/', $phone);
    }

    // --------------------------------------------------------
    // CSRF Token — generate
    // --------------------------------------------------------
    public static function generateCsrfToken(): string {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();
        return $token;
    }

    // --------------------------------------------------------
    // CSRF Token — validate
    // --------------------------------------------------------
    public static function validateCsrfToken(string $token): bool {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time'])) {
            return false;
        }
        if (time() - $_SESSION['csrf_token_time'] > CSRF_TOKEN_LIFETIME) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    // --------------------------------------------------------
    // Rate Limiting (DB-based)
    // --------------------------------------------------------
    public static function checkRateLimit(string $ip, string $endpoint, int $max = RATE_LIMIT_MAX, int $window = RATE_LIMIT_WINDOW): bool {
        $db = Database::connect();
        $windowStart = date('Y-m-d H:i:s', time() - $window);

        // Clean old entries
        $db->prepare("DELETE FROM rate_limits WHERE window_start < ?")->execute([$windowStart]);

        // Count attempts
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

    // --------------------------------------------------------
    // Hash password
    // --------------------------------------------------------
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    // --------------------------------------------------------
    // Verify password
    // --------------------------------------------------------
    public static function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }

    // --------------------------------------------------------
    // Generate secure token (for sessions, resets, etc.)
    // --------------------------------------------------------
    public static function generateToken(int $length = 64): string {
        return bin2hex(random_bytes($length / 2));
    }

    // --------------------------------------------------------
    // Get client IP (with proxy awareness)
    // --------------------------------------------------------
    public static function getClientIp(): string {
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

    // --------------------------------------------------------
    // Validate uploaded image
    // --------------------------------------------------------
    public static function validateImage(array $file): array {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'message' => 'Error al subir el archivo'];
        }
        if ($file['size'] > MAX_FILE_SIZE) {
            return ['valid' => false, 'message' => 'El archivo excede el tamaño máximo de 5MB'];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, ALLOWED_IMAGE_TYPES)) {
            return ['valid' => false, 'message' => 'Tipo de archivo no permitido. Use JPG, PNG o WebP'];
        }
        return ['valid' => true, 'mime' => $mime];
    }

    // --------------------------------------------------------
    // Save uploaded image securely
    // --------------------------------------------------------
    public static function saveImage(array $file, string $folder = 'general'): string|false {
        $validation = self::validateImage($file);
        if (!$validation['valid']) return false;

        $ext = match($validation['mime']) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg'
        };

        $uploadDir = UPLOAD_DIR . $folder . '/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $filename = self::generateToken(32) . '.' . $ext;
        $destination = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            return UPLOAD_URL . $folder . '/' . $filename;
        }
        return false;
    }
}
