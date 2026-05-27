<?php
define('APP_ACCESS', true);
require_once 'config.php';
require_once 'autoload.php';

// Set content type header
header('Content-Type: application/json; charset=utf-8');

// Validate request content length
if (($_SERVER['CONTENT_LENGTH'] ?? 0) > 10 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['status' => 'error', 'message' => 'Request entity too large']);
    exit;
}

// Allow CORS - restrict to origin if sent
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . $origin);
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
} catch (Exception $e) {
    // Log the error
    error_log('API Error: '.$e->getMessage());

    // Send error response
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal server error'
    ]);
}
