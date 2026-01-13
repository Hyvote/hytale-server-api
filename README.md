# Hytale Server Polling API

A PHP-based API for querying Hytale game servers using the Nitrado Query protocol. This API mirrors the functionality of the Minecraft Server Polling API but is specifically designed for Hytale servers.

## Features

- Query Hytale servers via HTTPS (Nitrado Query API)
- Get server information (name, version, players, plugins)
- Support for single and batch server queries
- Built-in caching system
- IP-based access control
- Clean JSON responses

## Directory Structure

```
hy/
├── api/
│   ├── ping.php       # Server query endpoint
│   └── meta.php       # API metadata endpoint
├── lib/
│   └── HytaleServerStatus.php  # Core query library
├── cache/             # Cache directory (auto-created)
├── config.php         # Configuration file
├── index.php          # Main router
├── hytale-api.conf    # Apache configuration
└── README.md          # This file
```

## Installation

1. **Copy files to your web server:**
   ```bash
   cp -r hy/ /var/www/hytale-api/
   ```

2. **Configure access control:**
   Edit `config.php` and add your allowed IP addresses:
   ```php
   'allowed_ips' => [
       '127.0.0.1',
       'your.ip.address.here',
   ]
   ```

3. **Set up Apache (optional):**
   ```bash
   sudo cp hytale-api.conf /etc/apache2/sites-available/
   sudo a2ensite hytale-api.conf
   sudo systemctl reload apache2
   ```

4. **Set permissions:**
   ```bash
   sudo chown -R www-data:www-data /var/www/hytale-api
   sudo chmod 755 /var/www/hytale-api/cache
   ```

## API Endpoints

### 1. Root Endpoint
```
GET /
```
Returns API information and available endpoints.

### 2. Server Ping (Single Query)
```
GET /api/ping?host=your-server.com&port=5523&fields=server,players
```

**Parameters:**
- `host` (required): Server hostname or IP address
- `port` (optional): Server port (default: 5523)
- `fields` (optional): Comma-separated list of fields to include in response
  - Valid values: `server`, `universe`, `players`, `plugins`
  - Default: all fields
  - Example: `fields=server,players` returns only server and player info

**Example Response:**
```json
{
    "online": true,
    "host": "your-server.com",
    "port": 5523,
    "latency_ms": 401.9,
    "server": {
        "name": "THIS IS THE SERVER NAME",
        "version": "2026.01.13-dcad8778f",
        "revision": "dcad8778f19e4e56af55d74b58575c91c50a018d",
        "patchline": "release",
        "protocol_version": 1,
        "max_players": 100
    },
    "universe": {
        "current_players": 0,
        "default_world": "default"
    },
    "players": {
        "online": 0,
        "max": 100,
        "list": []
    },
    "plugins": {
        "count": 83,
        "list": [...]
    }
}
```

### 3. Server Ping (Batch Query)
```
POST /api/ping
Content-Type: application/json

{
    "servers": [
        {"host": "your-server.com", "port": 5523},
        {"host": "another-server.com", "fields": "server,players"}
    ],
    "fields": "server,universe"
}
```

**Parameters:**
- `servers` (required): Array of server objects to query
  - Each server can have: `host`, `port`, `fields`
- `fields` (optional): Global fields filter (applies to all servers unless overridden)
  - Can be a comma-separated string or array
  - Valid values: `server`, `universe`, `players`, `plugins`
  - Individual servers can override with their own `fields` parameter

**Example Response:**
```json
{
    "results": [
        { "online": true, "server": {...}, "universe": {...} },
        { "online": false, "error": "Connection failed" }
    ]
}
```

### 4. API Metadata
```
GET /api/meta
```
Returns API configuration and settings.

## Field Filtering

The API supports filtering response data to include only specific sections. This is useful for reducing payload size and bandwidth usage.

### Available Fields

- `server` - Server information (name, version, max players, etc.)
- `universe` - Universe/world information (current players, default world)
- `players` - Player list and counts
- `plugins` - Plugin information and counts

### Usage Examples

**Single field:**
```
GET /api/ping?host=your-server.com&port=5523&fields=server
```

**Multiple fields:**
```
GET /api/ping?host=your-server.com&port=5523&fields=server,players
```

**All fields (default):**
```
GET /api/ping?host=your-server.com&port=5523
```

### No Permissions Response

If a requested field is not available from the server (due to permissions or missing data), the API returns:
```json
{
    "online": true,
    "server": "no_permissions",
    "players": {
        "online": 0,
        "max": 100
    }
}
```

This indicates that the server data was unavailable but the query succeeded.

## Configuration Options

Edit `config.php` to customize:

| Option | Default | Description |
|--------|---------|-------------|
| `default_port` | 5523 | Default Hytale Nitrado Query port |
| `timeout_seconds` | 5 | Connection timeout |
| `verify_ssl` | false | Verify SSL certificates (set true for production) |
| `cache_duration` | 30 | Cache duration in seconds (0 to disable) |
| `allowed_ips` | `['127.0.0.1']` | Whitelisted IP addresses |

## Security Features

1. **IP Whitelisting**: Only allowed IPs can access the API
2. **CIDR Support**: Use ranges like `192.168.1.0/24`
3. **Protected Directories**: `lib/` and `cache/` are blocked via Apache
4. **Security Headers**: XSS protection, content type sniffing protection

## Testing

Test the API using curl:

```bash
# Single query
curl "http://localhost/api/ping?host=your-server.com&port=5523"

# Batch query
curl -X POST http://localhost/api/ping \
  -H "Content-Type: application/json" \
  -d '{"servers":[{"host":"your-server.com","port":5523}]}'

# API metadata
curl "http://localhost/api/meta"
```

## Differences from Minecraft API

1. **Protocol**: Uses HTTPS JSON API instead of binary socket protocol
2. **Port**: Default port is 5523 (not 25565)
3. **SSL**: Supports SSL verification (disable for self-signed certs)
4. **No Player API**: Hytale doesn't use Mojang's API (no `/api/player` endpoint)
5. **Plugin Info**: Returns detailed plugin information from Nitrado Query

## Caching

The API includes a simple file-based cache to reduce server load:
- Cache files stored in `cache/` directory
- Automatically created and cleaned
- Only caches successful responses
- Configurable duration via `cache_duration` in config

## Error Handling

The API returns appropriate HTTP status codes:
- `200`: Success
- `400`: Bad request (missing parameters, invalid input)
- `403`: Forbidden (IP not whitelisted)
- `404`: Not found (invalid endpoint)
- `405`: Method not allowed

Error responses include helpful messages:
```json
{
    "error": "Access denied",
    "message": "Your IP address is not whitelisted",
    "your_ip": "203.0.113.1"
}
```

## Requirements

- PHP 7.4 or higher
- `allow_url_fopen` enabled
- OpenSSL extension (for HTTPS)
- Apache with mod_rewrite (optional)

## License

This API is provided as-is for querying Hytale game servers.

## Support

For issues or questions, refer to the Hytale documentation or Nitrado Query API specifications.
