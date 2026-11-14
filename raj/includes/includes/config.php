<?php
// /raj/includes/config.php
define('APP_NAME', 'BetterGrowth Solutions');
define('APP_TIMEZONE', 'Asia/Kolkata');
date_default_timezone_set(APP_TIMEZONE);

define('DEBUG_MODE', true);
if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

// Database
define('DB_HOST', 'FILLUP DB DETAILS ');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');

// Encryption
define('ENCRYPTION_KEY', 'MAKE Encryption PASSKEY');

// File upload
define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024);
define('ALLOWED_MIME_TYPES', ['image/jpeg', 'image/png', 'application/pdf']);
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png']);
define('STORAGE_PATH', __DIR__ . '/../storage/agents/');

// Gmail SMTP Configuration - USE YOUR CREDENTIALS
define('USE_SMTP', true);
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', '@gmail.com');  // REPLACE WITH YOUR GMAIL
define('SMTP_PASSWORD', 'PASSKEY ');      // YOUR APP PASSWORD (remove spaces)
define('SMTP_FROM', '@gmail.com');      // REPLACE WITH YOUR GMAIL
define('SMTP_FROM_NAME', APP_NAME);

// Session
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
ini_set('session.cookie_samesite', 'Lax');
session_set_cookie_params(3600, '/', '', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', true);
?>