<?php
define('APP_ACCESS', true);
require_once 'config.php';
require_once 'autoload.php';

// Set content type header
header('Content-Type: application/json; charset=utf-8');

// Allow CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
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
