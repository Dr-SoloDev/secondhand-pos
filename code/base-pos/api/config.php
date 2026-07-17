<?php
// Prevent direct access
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'pos_system');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: (function() {
    error_log('CRITICAL: DB_PASS environment variable is not set');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server configuration error']);
    exit;
})());
define('DB_CHARSET', 'utf8mb4');

// API settings
define('JWT_SECRET', getenv('JWT_SECRET') ?: (function() {
    error_log('CRITICAL: JWT_SECRET environment variable is not set');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server configuration error']);
    exit;
})());
define('JWT_EXPIRY', 28800); // 8 hours (reduced from 24h for security)
define('API_URL', '/api');

// Backup settings - outside web root for security
define('BACKUP_DIR', __DIR__.'/../../data/backups');
define('TEMP_DIR', __DIR__.'/../../data/temp');

// Upload directory — use env var, fallback to Docker path, then relative to document root
if (!defined('UPLOAD_DIR')) {
    $envDir = getenv('UPLOAD_DIR');
    if ($envDir) {
        define('UPLOAD_DIR', $envDir);
    } elseif (defined('PHP_WEBROOT')) {
        define('UPLOAD_DIR', PHP_WEBROOT . '/uploads');
    } elseif (isset($_SERVER['DOCUMENT_ROOT'])) {
        define('UPLOAD_DIR', $_SERVER['DOCUMENT_ROOT'] . '/uploads');
    } else {
        define('UPLOAD_DIR', __DIR__ . '/../../base-pos/uploads');
    }
}

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Timezone
date_default_timezone_set('Asia/Bangkok');
