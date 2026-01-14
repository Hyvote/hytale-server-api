<?php

/**
 * HyQueryServerStatus - HyQuery UDP Protocol Implementation
 *
 * Implements the HyQuery UDP protocol for querying Hytale servers
 * using binary packets on the game server port (typically 5520).
 */
class HyQueryServerStatus
{
    private string $host;
    private int $port;
    private int $timeout;

    // Protocol constants
    private const REQUEST_MAGIC = "HYQUERY\0";
    private const RESPONSE_MAGIC = "HYREPLY\0";
    private const TYPE_BASIC = 0x00;
    private const TYPE_FULL = 0x01;

    /**
     * Create a new HyQueryServerStatus instance
     *
     * @param string $host Server hostname or IP
     * @param int $port Server port (default 5520 - game port)
     * @param int $timeout Connection timeout in seconds
     */
    public function __construct(string $host, int $port = 5520, int $timeout = 3)
    {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
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
            // Determine query type based on requested fields
            $queryType = $this->shouldUseFullQuery($fields) ? self::TYPE_FULL : self::TYPE_BASIC;

            // Create request packet
            $request = self::REQUEST_MAGIC . chr($queryType);

            // Send UDP query
            $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($socket === false) {
                throw new Exception('Failed to create UDP socket');
            }

            // Set timeout
            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, [
                'sec' => $this->timeout,
                'usec' => 0
            ]);

            // Send request
            $sent = @socket_sendto($socket, $request, strlen($request), 0, $this->host, $this->port);
            if ($sent === false) {
                socket_close($socket);
                throw new Exception('Failed to send query packet');
            }

            // Receive response
            $response = '';
            $from = '';
            $port = 0;
            $received = @socket_recvfrom($socket, $response, 65535, 0, $from, $port);

            socket_close($socket);

            if ($received === false) {
                throw new Exception('No response from server (timeout)');
            }

            // Calculate latency
            $latency = round((microtime(true) - $startTime) * 1000, 1);

            // Parse response
            return $this->parseResponse($response, $latency, $fields);

        } catch (Exception $e) {
            return [
                'online' => false,
                'host' => $this->host,
                'port' => $this->port,
                'error' => $e->getMessage(),
                'method' => 'hyquery'
            ];
        }
    }

    /**
     * Determine if full query is needed based on requested fields
     *
     * @param array $fields Requested fields
     * @return bool True if full query is needed
     */
    private function shouldUseFullQuery(array $fields): bool
    {
        // If no fields specified, use basic query
        if (empty($fields)) {
            return false;
        }

        // Full query needed if plugins or players list is requested
        $fullQueryFields = ['plugins', 'players'];
        foreach ($fullQueryFields as $field) {
            if (in_array($field, $fields)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse binary response from server
     *
     * @param string $response Raw binary response
     * @param float $latency Connection latency in ms
     * @param array $fields Requested fields
     * @return array Parsed server data
     */
    private function parseResponse(string $response, float $latency, array $fields = []): array
    {
        $offset = 0;

        // Check magic bytes
        $magic = substr($response, $offset, 8);
        if ($magic !== self::RESPONSE_MAGIC) {
            throw new Exception('Invalid response magic bytes');
        }
        $offset += 8;

        // Read response type
        $responseType = ord($response[$offset]);
        $offset += 1;

        // Read server name
        $serverName = $this->readString($response, $offset);

        // Read MOTD
        $motd = $this->readString($response, $offset);

        // Read player counts
        $onlinePlayers = $this->readInt32LE($response, $offset);
        $maxPlayers = $this->readInt32LE($response, $offset);

        // Read port
        $serverPort = $this->readInt32LE($response, $offset);

        // Read version
        $version = $this->readString($response, $offset);

        // Base response
        $result = [
            'online' => true,
            'host' => $this->host,
            'port' => $this->port,
            'latency_ms' => $latency,
            'method' => 'hyquery'
        ];

        // Add fields based on what was requested (or all if none specified)
        if (empty($fields)) {
            $fields = ['server', 'universe', 'players', 'plugins'];
        }

        // Normalize field names
        $fields = array_map('strtolower', $fields);

        // Server info
        if (in_array('server', $fields)) {
            $result['server'] = [
                'name' => $serverName,
                'version' => $version,
                'motd' => $motd,
                'max_players' => $maxPlayers
            ];
        }

        // Universe info
        if (in_array('universe', $fields)) {
            $result['universe'] = [
                'current_players' => $onlinePlayers,
                'default_world' => 'unknown'  // HyQuery doesn't provide this
            ];
        }

        // Player info
        if (in_array('players', $fields)) {
            $playerList = [];

            // Parse player list if full response
            if ($responseType === self::TYPE_FULL && $offset < strlen($response)) {
                $playerCount = $this->readInt32LE($response, $offset);

                for ($i = 0; $i < $playerCount; $i++) {
                    $username = $this->readString($response, $offset);
                    $uuid = $this->readUUID($response, $offset);

                    $playerList[] = [
                        'name' => $username,
                        'uuid' => $uuid,
                        'ping' => null  // HyQuery doesn't provide ping
                    ];
                }
            }

            $result['players'] = [
                'online' => $onlinePlayers,
                'max' => $maxPlayers,
                'list' => $playerList
            ];
        }

        // Plugin info
        if (in_array('plugins', $fields)) {
            $pluginList = [];

            // Parse plugin list if full response
            if ($responseType === self::TYPE_FULL && $offset < strlen($response)) {
                // Skip player list if we haven't read it yet
                if (!in_array('players', $fields)) {
                    $playerCount = $this->readInt32LE($response, $offset);
                    for ($i = 0; $i < $playerCount; $i++) {
                        $this->readString($response, $offset);  // username
                        $offset += 16;  // UUID
                    }
                }

                $pluginCount = $this->readInt32LE($response, $offset);

                for ($i = 0; $i < $pluginCount; $i++) {
                    $pluginName = $this->readString($response, $offset);

                    $pluginList[] = [
                        'name' => $pluginName,
                        'version' => 'Unknown',  // HyQuery doesn't provide version
                        'loaded' => true,
                        'enabled' => true,
                        'state' => 'LOADED'
                    ];
                }
            }

            $result['plugins'] = [
                'count' => count($pluginList),
                'list' => $pluginList
            ];
        }

        return $result;
    }

    /**
     * Read a length-prefixed string from binary data
     *
     * @param string $data Binary data
     * @param int &$offset Current offset (updated after read)
     * @return string Decoded string
     */
    private function readString(string $data, int &$offset): string
    {
        // Read 2-byte little-endian length
        $length = unpack('v', substr($data, $offset, 2))[1];
        $offset += 2;

        // Read string data
        $string = substr($data, $offset, $length);
        $offset += $length;

        return $string;
    }

    /**
     * Read a 4-byte little-endian integer from binary data
     *
     * @param string $data Binary data
     * @param int &$offset Current offset (updated after read)
     * @return int Integer value
     */
    private function readInt32LE(string $data, int &$offset): int
    {
        $value = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;
        return $value;
    }

    /**
     * Read a UUID (16 bytes) from binary data
     *
     * @param string $data Binary data
     * @param int &$offset Current offset (updated after read)
     * @return string UUID string
     */
    private function readUUID(string $data, int &$offset): string
    {
        $bytes = substr($data, $offset, 16);
        $offset += 16;

        // Convert to hex string with dashes
        $hex = bin2hex($bytes);
        return sprintf(
            '%08s-%04s-%04s-%04s-%012s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
