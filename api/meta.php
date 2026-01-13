<?php

// Load configuration
$config = require __DIR__ . '/../config.php';

/**
 * Meta endpoint - returns API information and configuration
 */

// Set JSON response header
header('Content-Type: application/json');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'error' => 'Method not allowed',
        'allowed_methods' => ['GET']
    ]);
    exit;
}

// Build response
$response = [
    'name' => $config['api_name'],
    'version' => $config['api_version'],
    'language' => $config['language'],
    'endpoints' => [
        '/api/ping',
        '/api/meta'
    ],
    'config' => [
        'allowed_ips' => $config['allowed_ips'],
        'default_port' => $config['default_port'],
        'timeout_seconds' => $config['timeout_seconds'],
        'verify_ssl' => $config['verify_ssl']
    ]
];

// Optionally include cache info if enabled
if ($config['cache_duration'] > 0) {
    $response['config']['cache_duration'] = $config['cache_duration'];
}

// Return response
http_response_code(200);
echo json_encode($response, JSON_PRETTY_PRINT);
