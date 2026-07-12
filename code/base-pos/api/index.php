<?php
define('APP_ACCESS', true);
require_once 'config.php';
require_once 'autoload.php';

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'");

// Validate request content length
if (($_SERVER['CONTENT_LENGTH'] ?? 0) > 10 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['status' => 'error', 'message' => 'Request entity too large']);
    exit;
}

// Allow CORS - restrict to known origins (env-driven for production)
// ALLOWED_ORIGINS = comma-separated list, e.g. "https://pos.example.com,https://admin.example.com"
$envOrigins = getenv('ALLOWED_ORIGINS');
if ($envOrigins !== false && trim($envOrigins) !== '') {
    $allowedOrigins = array_filter(array_map('trim', explode(',', $envOrigins)));
} else {
    $allowedOrigins = ['http://localhost:8080', 'http://127.0.0.1:8080', 'http://localhost', 'http://127.0.0.1'];
}
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    // Initialize router
    $router = new Router();

    // Register routes
    $router->registerRoutes();

    // Process the request
    $router->dispatch();
} catch (\Throwable $e) {
    error_log("[FATAL] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal server error. Please contact administrator.'
    ]);
}
