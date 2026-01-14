<?php

// Load configuration and dependencies
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/HytaleServerStatus.php';
require_once __DIR__ . '/../lib/HyQueryServerStatus.php';

/**
 * Ping endpoint - handles both GET and POST requests
 */

// Set JSON response header
header('Content-Type: application/json');

// Handle GET request - single server query
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    handleGetRequest($config);
}
// Handle POST request - multiple server query
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePostRequest($config);
}
// Method not allowed
else {
    http_response_code(405);
    echo json_encode([
        'error' => 'Method not allowed',
        'allowed_methods' => ['GET', 'POST']
    ]);
}

/**
 * Handle GET request for single server query
 *
 * @param array $config Configuration array
 */
function handleGetRequest(array $config): void
{
    // Validate required parameter
    if (!isset($_GET['host']) || empty($_GET['host'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Missing required parameter: host',
            'usage' => '/api/ping?host=your-server.com&port=5523&fields=server,players&method=nitrado'
        ]);
        return;
    }

    $host = trim($_GET['host']);
    $method = isset($_GET['method']) ? strtolower(trim($_GET['method'])) : 'nitrado';

    // Determine default port based on method
    $defaultPort = ($method === 'hyquery') ? 5520 : $config['default_port'];
    $port = isset($_GET['port']) ? (int)$_GET['port'] : $defaultPort;
    $fields = isset($_GET['fields']) ? parseFields($_GET['fields']) : [];

    // Validate port range
    if ($port < 1 || $port > 65535) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid port number. Must be between 1 and 65535'
        ]);
        return;
    }

    // Validate method
    if (!in_array($method, ['nitrado', 'hyquery'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid method. Must be "nitrado" or "hyquery"'
        ]);
        return;
    }

    // Query the server
    $status = queryServer($host, $port, $config, $fields, $method);

    // Return response
    http_response_code(200);
    echo json_encode($status, JSON_PRETTY_PRINT);
}

/**
 * Handle POST request for multiple server queries
 *
 * @param array $config Configuration array
 */
function handlePostRequest(array $config): void
{
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    // Validate JSON
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid JSON',
            'details' => json_last_error_msg()
        ]);
        return;
    }

    // Validate servers array
    if (!isset($data['servers']) || !is_array($data['servers'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Missing or invalid "servers" array',
            'usage' => [
                'servers' => [
                    ['host' => 'your-server.com', 'port' => 5523],
                    ['host' => 'another-server.com']
                ]
            ]
        ]);
        return;
    }

    // Limit number of servers to prevent abuse
    $maxServers = 50;
    if (count($data['servers']) > $maxServers) {
        http_response_code(400);
        echo json_encode([
            'error' => "Too many servers. Maximum is {$maxServers} per request"
        ]);
        return;
    }

    // Get global parameters (apply to all servers unless overridden)
    $globalFields = isset($data['fields']) ? parseFields($data['fields']) : [];
    $globalMethod = isset($data['method']) ? strtolower(trim($data['method'])) : 'nitrado';

    // Validate global method
    if (!in_array($globalMethod, ['nitrado', 'hyquery'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid method. Must be "nitrado" or "hyquery"'
        ]);
        return;
    }

    // Query all servers
    $results = [];

    foreach ($data['servers'] as $server) {
        if (!isset($server['host']) || empty($server['host'])) {
            $results[] = [
                'error' => 'Missing host parameter',
                'online' => false
            ];
            continue;
        }

        $host = trim($server['host']);

        // Use server-specific method if provided, otherwise use global method
        $method = isset($server['method']) ? strtolower(trim($server['method'])) : $globalMethod;

        // Validate method
        if (!in_array($method, ['nitrado', 'hyquery'])) {
            $results[] = [
                'host' => $host,
                'online' => false,
                'error' => 'Invalid method'
            ];
            continue;
        }

        // Determine default port based on method
        $defaultPort = ($method === 'hyquery') ? 5520 : $config['default_port'];
        $port = isset($server['port']) ? (int)$server['port'] : $defaultPort;

        // Use server-specific fields if provided, otherwise use global fields
        $fields = isset($server['fields']) ? parseFields($server['fields']) : $globalFields;

        // Validate port
        if ($port < 1 || $port > 65535) {
            $results[] = [
                'host' => $host,
                'online' => false,
                'error' => 'Invalid port number'
            ];
            continue;
        }

        // Query the server
        $results[] = queryServer($host, $port, $config, $fields, $method);
    }

    // Return results
    http_response_code(200);
    echo json_encode([
        'results' => $results
    ], JSON_PRETTY_PRINT);
}

/**
 * Query a Hytale server and return status
 *
 * @param string $host Server hostname or IP
 * @param int $port Server port
 * @param array $config Configuration array
 * @param array $fields Fields to include in response
 * @param string $method Query method ('nitrado' or 'hyquery')
 * @return array Server status data
 */
function queryServer(string $host, int $port, array $config, array $fields = [], string $method = 'nitrado'): array
{
    // Check cache if enabled
    $cacheKey = null;
    if ($config['cache_duration'] > 0) {
        // Include fields and method in cache key to avoid returning wrong data
        $fieldsKey = empty($fields) ? 'all' : implode('_', $fields);
        $cacheKey = "server_{$method}_{$host}_{$port}_{$fieldsKey}";
        $cached = getFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
    }

    // Create appropriate server status instance based on method
    if ($method === 'hyquery') {
        $serverStatus = new HyQueryServerStatus(
            $host,
            $port,
            $config['timeout_seconds']
        );
    } else {
        $serverStatus = new HytaleServerStatus(
            $host,
            $port,
            $config['timeout_seconds'],
            $config['verify_ssl']
        );
    }

    $result = $serverStatus->query($fields);

    // Store in cache if enabled
    if ($config['cache_duration'] > 0 && isset($result['online']) && $result['online']) {
        storeInCache($cacheKey, $result, $config['cache_duration']);
    }

    return $result;
}

/**
 * Get data from simple file-based cache
 *
 * @param string $key Cache key
 * @return array|null Cached data or null if not found/expired
 */
function getFromCache(string $key): ?array
{
    $cacheDir = __DIR__ . '/../cache';
    $cacheFile = $cacheDir . '/' . md5($key) . '.json';

    if (!file_exists($cacheFile)) {
        return null;
    }

    $data = json_decode(file_get_contents($cacheFile), true);

    if (!$data || !isset($data['expires']) || $data['expires'] < time()) {
        @unlink($cacheFile);
        return null;
    }

    return $data['data'] ?? null;
}

/**
 * Store data in simple file-based cache
 *
 * @param string $key Cache key
 * @param array $data Data to cache
 * @param int $duration Cache duration in seconds
 */
function storeInCache(string $key, array $data, int $duration): void
{
    $cacheDir = __DIR__ . '/../cache';

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $cacheFile = $cacheDir . '/' . md5($key) . '.json';

    $cacheData = [
        'expires' => time() + $duration,
        'data' => $data
    ];

    @file_put_contents($cacheFile, json_encode($cacheData));
}

/**
 * Parse and validate fields parameter
 *
 * @param string|array $fieldsInput Fields as string (comma-separated) or array
 * @return array Array of valid field names
 */
function parseFields($fieldsInput): array
{
    // Valid field names
    $validFields = ['server', 'universe', 'players', 'plugins'];

    // If already an array, just validate and return
    if (is_array($fieldsInput)) {
        $fields = array_map('trim', $fieldsInput);
        $fields = array_map('strtolower', $fields);
        return array_values(array_intersect($fields, $validFields));
    }

    // If string, split by comma and validate
    if (is_string($fieldsInput)) {
        $fields = array_map('trim', explode(',', $fieldsInput));
        $fields = array_map('strtolower', $fields);
        return array_values(array_intersect($fields, $validFields));
    }

    // Return empty array if invalid input
    return [];
}
