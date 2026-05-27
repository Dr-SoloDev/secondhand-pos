<?php
// Prevent direct access
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'pos_system');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// API settings
define('JWT_SECRET', getenv('JWT_SECRET') ?: (function() {
    error_log('CRITICAL: JWT_SECRET environment variable is not set');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server configuration error']);
    exit;
})());
define('JWT_EXPIRY', 86400); // 24 hours
define('API_URL', '/api');

// Backup settings - outside web root for security
define('BACKUP_DIR', __DIR__.'/../../data/backups');
define('TEMP_DIR', __DIR__.'/../../data/temp');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Timezone
date_default_timezone_set('Asia/Bangkok');
