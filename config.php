<?php

/**
 * Hytale Server Polling API Configuration
 */

return [
    // API Information
    'api_name' => 'Hytale Server Polling API',
    'api_version' => '1.0.0',
    'language' => 'PHP ' . PHP_VERSION,

    // Server defaults
    'default_port' => 5523, // Default Hytale Nitrado Query port
    'timeout_seconds' => 5,
    'verify_ssl' => false, // Set to true in production with valid SSL certificates

    // IP Access Control
    // Add your allowed IP addresses here
    // Supports individual IPs and CIDR notation (e.g., '192.168.1.0/24')
    'allowed_ips' => [
        '127.0.0.1',        // Localhost
        '::1',              // IPv6 localhost
        // Add your IP addresses here
        // '203.0.113.1',   // Example IP
        // '192.168.1.0/24', // Example CIDR range
    ],

    // Cache settings (set to 0 to disable caching)
    'cache_duration' => 30, // Cache duration in seconds
];
