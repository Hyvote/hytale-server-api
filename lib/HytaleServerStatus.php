<?php

/**
 * HytaleServerStatus - Hytale Server Query Implementation
 *
 * Implements the Nitrado Query API protocol for Hytale servers
 * to retrieve server status information via HTTPS.
 */
class HytaleServerStatus
{
    private string $host;
    private int $port;
    private int $timeout;
    private bool $verifySsl;

    /**
     * Create a new HytaleServerStatus instance
     *
     * @param string $host Server hostname or IP
     * @param int $port Server port (default 5523)
     * @param int $timeout Connection timeout in seconds
     * @param bool $verifySsl Whether to verify SSL certificates
     */
    public function __construct(string $host, int $port = 5523, int $timeout = 3, bool $verifySsl = true)
    {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
        $this->verifySsl = $verifySsl;
    }

    /**
     * Query the server and return status information
     *
     * @param array $fields Optional array of fields to include (server, universe, players, plugins)
     * @return array Status data or error information
     */
    public function query(array $fields = []): array
    {
        $startTime = microtime(true);

        try {
            // Build the Nitrado Query endpoint URL
            // Check if host already includes protocol
            if (preg_match('/^https?:\/\//i', $this->host)) {
                // Host includes protocol, use as-is (no fallback)
                $url = "{$this->host}:{$this->port}/Nitrado/Query";
                $response = $this->makeRequest($url);
            } else {
                // No protocol specified, try HTTPS first, fallback to HTTP
                $httpsUrl = "https://{$this->host}:{$this->port}/Nitrado/Query";

                try {
                    $response = $this->makeRequest($httpsUrl);
                } catch (Exception $httpsError) {
                    // HTTPS failed, try HTTP as fallback
                    $httpUrl = "http://{$this->host}:{$this->port}/Nitrado/Query";
                    $response = $this->makeRequest($httpUrl);
                }
            }

            // Calculate latency
            $latency = round((microtime(true) - $startTime) * 1000, 1);

            // Parse JSON response
            $data = json_decode($response, true, 512, JSON_INVALID_UTF8_IGNORE);

            if (!$data) {
                $jsonError = json_last_error_msg();
                throw new Exception("Invalid JSON response from server: {$jsonError}");
            }

            // Format response
            return $this->formatResponse($data, $latency, $fields);

        } catch (Exception $e) {
            return [
                'online' => false,
                'host' => $this->host,
                'port' => $this->port,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Make HTTPS request to Hytale server
     *
     * @param string $url Request URL
     * @return string Response body
     * @throws Exception on request failure
     */
    private function makeRequest(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout,
                'user_agent' => 'Hytale-Server-Polling-API/1.0',
                'header' => 'Accept: application/json',
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => $this->verifySsl,
                'verify_peer_name' => $this->verifySsl
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new Exception('Connection failed: Unable to connect to Hytale server');
        }

        // Parse HTTP response code from headers
        $httpCode = 200;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/\d\.\d\s+(\d{3})/', $header, $matches)) {
                    $httpCode = (int)$matches[1];
                    break;
                }
            }
        }

        if ($httpCode !== 200) {
            throw new Exception("HTTP {$httpCode} error from Hytale server");
        }

        return $response;
    }

    /**
     * Format the server response into standardized format
     *
     * @param array $data Raw server data
     * @param float $latency Connection latency in ms
     * @param array $fields Optional fields to include (empty = all fields)
     * @return array Formatted response
     */
    private function formatResponse(array $data, float $latency, array $fields = []): array
    {
        // If no fields specified, include all
        if (empty($fields)) {
            $fields = ['server', 'universe', 'players', 'plugins'];
        }

        // Normalize field names to lowercase
        $fields = array_map('strtolower', $fields);

        $server = $data['Server'] ?? null;
        $universe = $data['Universe'] ?? null;
        $players = $data['Players'] ?? null;
        $plugins = $data['Plugins'] ?? null;

        // Base response
        $response = [
            'online' => true,
            'host' => $this->host,
            'port' => $this->port,
            'latency_ms' => $latency
        ];

        // Add server info if requested
        if (in_array('server', $fields)) {
            if ($server !== null) {
                $response['server'] = [
                    'name' => $server['Name'] ?? 'Unknown',
                    'version' => $server['Version'] ?? 'Unknown',
                    'revision' => $server['Revision'] ?? null,
                    'patchline' => $server['Patchline'] ?? null,
                    'protocol_version' => $server['ProtocolVersion'] ?? null,
                    'protocol_hash' => $server['ProtocolHash'] ?? null,
                    'max_players' => $server['MaxPlayers'] ?? 0
                ];
            } else {
                $response['server'] = 'no_permissions';
            }
        }

        // Add universe info if requested
        if (in_array('universe', $fields)) {
            if ($universe !== null) {
                $response['universe'] = [
                    'current_players' => $universe['CurrentPlayers'] ?? 0,
                    'default_world' => $universe['DefaultWorld'] ?? 'unknown'
                ];
            } else {
                $response['universe'] = 'no_permissions';
            }
        }

        // Add player info if requested
        if (in_array('players', $fields)) {
            if ($universe !== null || $players !== null) {
                $response['players'] = [
                    'online' => $universe['CurrentPlayers'] ?? 0,
                    'max' => $server['MaxPlayers'] ?? 0,
                    'list' => is_array($players) ? $this->extractPlayerList($players) : []
                ];
            } else {
                $response['players'] = 'no_permissions';
            }
        }

        // Add plugin info if requested
        if (in_array('plugins', $fields)) {
            if ($plugins !== null) {
                $response['plugins'] = [
                    'count' => count($plugins),
                    'list' => $this->extractPluginList($plugins)
                ];
            } else {
                $response['plugins'] = 'no_permissions';
            }
        }

        return $response;
    }

    /**
     * Extract player list from Players array
     *
     * @param array $players Players data
     * @return array Array of player information
     */
    private function extractPlayerList(array $players): array
    {
        $playerList = [];

        foreach ($players as $player) {
            if (is_array($player)) {
                $playerList[] = [
                    'name' => $player['name'] ?? $player['username'] ?? 'Unknown',
                    'uuid' => $player['uuid'] ?? null,
                    'ping' => $player['ping'] ?? null
                ];
            }
        }

        return $playerList;
    }

    /**
     * Extract plugin information
     *
     * @param array $plugins Plugins data
     * @return array Array of plugin information
     */
    private function extractPluginList(array $plugins): array
    {
        $pluginList = [];

        foreach ($plugins as $name => $info) {
            if (is_array($info)) {
                $pluginList[] = [
                    'name' => $name,
                    'version' => $info['Version'] ?? 'Unknown',
                    'loaded' => $info['Loaded'] ?? false,
                    'enabled' => $info['Enabled'] ?? false,
                    'state' => $info['State'] ?? 'UNKNOWN'
                ];
            }
        }

        return $pluginList;
    }
}
