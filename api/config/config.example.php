<?php
// ============================================================
// Database Configuration
// Renombra este archivo a config.php y llena tus datos de cPanel
// NUNCA subas config.php al repositorio
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');   // ej: nuevaexp_pedidos
define('DB_USER', 'your_database_user');   // ej: nuevaexp_pedidos
define('DB_PASS', 'your_database_password');
define('DB_CHARSET', 'utf8mb4');

// App
define('APP_URL', 'https://nuevaexpress.com');
define('APP_NAME', 'NuevaExpress');
define('APP_ENV', 'production'); // cambiar a 'development' para debug

// Security
define('JWT_SECRET', 'cambiar_por_string_aleatorio_64_caracteres_minimo');
define('SESSION_LIFETIME', 604800); // 7 dias
define('CSRF_TOKEN_LIFETIME', 3600);

// Rate limiting
define('RATE_LIMIT_MAX', 300);
define('RATE_LIMIT_WINDOW', 60);
define('LOGIN_MAX_ATTEMPTS', 10);
define('LOGIN_LOCKOUT_TIME', 300);

// File uploads
define('UPLOAD_DIR', __DIR__ . '/../../uploads/');
define('UPLOAD_URL', 'https://nuevaexpress.com/uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024);
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

// Fees (Quetzales)
define('SERVICE_FEE', 3.00);
define('DELIVERY_FEE_CENTRAL', 15.00); // Mínimo — cada negocio puede definir el suyo

date_default_timezone_set('America/Guatemala');
