<?php

/**
 * Hytale Server Polling API - Main Router
 *
 * This file handles routing and IP-based access control
 */

// Load configuration
$config = require_once __DIR__ . '/config.php';

// Set JSON response header
header('Content-Type: application/json');

// IP Access Control - Check if client IP is whitelisted
$clientIp = getClientIp();

if (!isIpAllowed($clientIp, $config['allowed_ips'])) {
    http_response_code(403);
    echo json_encode([
        'error' => 'Access denied',
        'message' => 'Your IP address is not whitelisted',
        'your_ip' => $clientIp
    ]);
    exit;
}

// Get the request URI and remove query string
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove base path if API is in a subdirectory
$basePath = '/hytale/hy';
if (strpos($requestUri, $basePath) === 0) {
    $requestUri = substr($requestUri, strlen($basePath));
}
$requestUri = str_replace('/index.php', '', $requestUri);

// Ensure we have a leading slash
if (empty($requestUri) || $requestUri[0] !== '/') {
    $requestUri = '/' . $requestUri;
}

// Route the request
switch ($requestUri) {
    case '/':
    case '':
        // Root endpoint - show API info
        showWelcome($config);
        break;

    case '/api/ping':
        require __DIR__ . '/api/ping.php';
        break;

    case '/api/meta':
        require __DIR__ . '/api/meta.php';
        break;

    default:
        // 404 Not Found
        http_response_code(404);
        echo json_encode([
            'error' => 'Endpoint not found',
            'path' => $requestUri,
            'available_endpoints' => [
                '/api/ping',
                '/api/meta'
            ]
        ]);
        break;
}

/**
 * Get the client's real IP address
 * Checks common proxy headers
 *
 * @return string Client IP address
 */
function getClientIp(): string
{
    // Check for proxy headers (in order of priority)
    $headers = [
        'HTTP_CF_CONNECTING_IP',    // Cloudflare
        'HTTP_X_REAL_IP',           // Nginx proxy
        'HTTP_X_FORWARDED_FOR',     // Standard proxy header
        'REMOTE_ADDR'               // Direct connection
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];

            // Handle X-Forwarded-For which may contain multiple IPs
            if ($header === 'HTTP_X_FORWARDED_FOR') {
                $ips = array_map('trim', explode(',', $ip));
                $ip = $ips[0]; // Use the first (original client) IP
            }

            // Validate IP address
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return 'unknown';
}

/**
 * Check if an IP address is in the allowed list
 *
 * @param string $clientIp Client IP address
 * @param array $allowedIps Array of allowed IP addresses
 * @return bool True if allowed, false otherwise
 */
function isIpAllowed(string $clientIp, array $allowedIps): bool
{
    // Allow localhost variations
    $localhostIps = ['127.0.0.1', '::1', 'localhost'];

    foreach ($allowedIps as $allowedIp) {
        $allowedIp = trim($allowedIp);

        // Direct match
        if ($clientIp === $allowedIp) {
            return true;
        }

        // Check if it's a localhost reference
        if (in_array($allowedIp, $localhostIps) && in_array($clientIp, $localhostIps)) {
            return true;
        }

        // Check CIDR notation (e.g., 192.168.1.0/24)
        if (strpos($allowedIp, '/') !== false) {
            if (ipInCidr($clientIp, $allowedIp)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Check if an IP address is within a CIDR range
 *
 * @param string $ip IP address to check
 * @param string $cidr CIDR notation (e.g., 192.168.1.0/24)
 * @return bool True if IP is in range
 */
function ipInCidr(string $ip, string $cidr): bool
{
    list($subnet, $mask) = explode('/', $cidr);

    // Convert IPs to long format
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);

    if ($ipLong === false || $subnetLong === false) {
        return false;
    }

    // Calculate network mask
    $maskLong = -1 << (32 - (int)$mask);
    $subnetLong &= $maskLong; // Apply mask to subnet

    // Check if IP is in range
    return ($ipLong & $maskLong) === $subnetLong;
}

/**
 * Show welcome message and API information
 *
 * @param array $config Configuration array
 */
function showWelcome(array $config): void
{
    http_response_code(200);
    echo json_encode([
        'message' => 'Welcome to ' . $config['api_name'],
        'version' => $config['api_version'],
        'documentation' => [
            'endpoints' => [
                [
                    'path' => '/api/ping',
                    'methods' => ['GET', 'POST'],
                    'description' => 'Query Hytale server status via Nitrado Query API',
                    'examples' => [
                        'GET /api/ping?host=your-server.com&port=5523',
                        'POST /api/ping with JSON body'
                    ]
                ],
                [
                    'path' => '/api/meta',
                    'methods' => ['GET'],
                    'description' => 'Get API configuration and metadata'
                ]
            ]
        ]
    ], JSON_PRETTY_PRINT);
}
