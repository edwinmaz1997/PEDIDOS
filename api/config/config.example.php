<?php
// ============================================================
// Database Configuration
// IMPORTANT: Rename this file to config.php and fill in values
//            Never commit config.php to version control
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');
define('DB_CHARSET', 'utf8mb4');

// App settings
define('APP_URL', 'https://yourdomain.com');
define('APP_NAME', 'Pedidos GT');
define('APP_ENV', 'production'); // 'development' or 'production'

// Security
define('JWT_SECRET', 'change_this_to_a_random_64_char_string');
define('SESSION_LIFETIME', 7200); // seconds (2 hours)
define('CSRF_TOKEN_LIFETIME', 3600);

// Rate limiting
define('RATE_LIMIT_MAX', 60);       // max requests
define('RATE_LIMIT_WINDOW', 60);    // per X seconds
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900);  // 15 minutes

// File uploads
define('UPLOAD_DIR', __DIR__ . '/../../uploads/');
define('UPLOAD_URL', APP_URL . '/uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

// Fees (Quetzales)
define('SERVICE_FEE', 5.00);
define('DELIVERY_FEE_CENTRAL', 15.00);

// Timezone
date_default_timezone_set('America/Guatemala');
