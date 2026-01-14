# HyQuery Method Examples

This document provides examples of using the HyQuery protocol with the Hytale Server Polling API.

## Single Server Query

### Basic Query (GET)

```bash
# Query using HyQuery protocol
curl "http://localhost/api/ping?host=192.168.1.78&port=5520&method=hyquery"

# Query with specific fields
curl "http://localhost/api/ping?host=192.168.1.78&port=5520&method=hyquery&fields=server,players"

# Compare with Nitrado method
curl "http://localhost/api/ping?host=your-server.com&port=5523&method=nitrado"
```

### Response Example

```json
{
    "online": true,
    "host": "192.168.1.78",
    "port": 5520,
    "latency_ms": 5.7,
    "method": "hyquery",
    "server": {
        "name": "THIS IS THE SERVER NAME",
        "version": "2026.01.13-dcad8778f",
        "motd": "§aWelcome to §lMy Server§r!",
        "max_players": 100
    },
    "universe": {
        "current_players": 0,
        "default_world": "unknown"
    },
    "players": {
        "online": 0,
        "max": 100,
        "list": []
    },
    "plugins": {
        "count": 0,
        "list": []
    }
}
```

## Batch Queries

### Mixed Protocol Query (POST)

Query multiple servers using different protocols in a single request:

```bash
curl -X POST http://localhost/api/ping \
  -H "Content-Type: application/json" \
  -d '{
    "servers": [
        {
            "host": "192.168.1.78",
            "port": 5520,
            "method": "hyquery"
        },
        {
            "host": "your-server.com",
            "port": 5523,
            "method": "nitrado"
        }
    ]
}'
```

### All HyQuery Servers

```bash
curl -X POST http://localhost/api/ping \
  -H "Content-Type: application/json" \
  -d '{
    "method": "hyquery",
    "servers": [
        {"host": "192.168.1.78", "port": 5520},
        {"host": "192.168.1.79", "port": 5520},
        {"host": "192.168.1.80", "port": 5520}
    ],
    "fields": "server,players"
}'
```

## Performance Comparison

| Method   | Avg Latency | Port | Protocol | Setup Complexity |
|----------|-------------|------|----------|------------------|
| HyQuery  | 5-10ms      | 5520 | UDP      | Low (1 plugin)   |
| Nitrado  | 200-500ms   | 5523 | HTTPS    | Medium (2 plugins + SSL) |

## Use Cases

### Server Lists

Use HyQuery for fast polling of many servers:

```bash
curl -X POST http://localhost/api/ping \
  -H "Content-Type: application/json" \
  -d '{
    "method": "hyquery",
    "fields": "server,players",
    "servers": [
        {"host": "server1.example.com"},
        {"host": "server2.example.com"},
        {"host": "server3.example.com"}
    ]
}'
```

### Detailed Server Info

Use Nitrado when you need complete plugin information:

```bash
curl "http://localhost/api/ping?host=your-server.com&port=5523&method=nitrado&fields=plugins"
```

### Hybrid Approach

Query basic info via HyQuery, then fetch details for specific servers via Nitrado:

```bash
# Step 1: Fast poll all servers
curl -X POST http://localhost/api/ping \
  -H "Content-Type: application/json" \
  -d '{
    "method": "hyquery",
    "fields": "server",
    "servers": [...]
}'

# Step 2: Get detailed info for selected server
curl "http://localhost/api/ping?host=selected-server.com&port=5523&method=nitrado"
```

## PHP Integration

```php
<?php

require_once 'lib/HyQueryServerStatus.php';

// Query single server
$status = new HyQueryServerStatus('192.168.1.78', 5520, 5);
$result = $status->query(['server', 'players']);

if ($result['online']) {
    echo "Server: " . $result['server']['name'] . "\n";
    echo "Players: " . $result['players']['online'] . "/" . $result['players']['max'] . "\n";
    echo "Version: " . $result['server']['version'] . "\n";
    echo "MOTD: " . $result['server']['motd'] . "\n";
}
```

## Troubleshooting

### No Response

```bash
# Test directly with test script
python3 /path/to/hyquery/test_hyquery.py 192.168.1.78 5520 0
```

### Wrong Port

HyQuery uses the **game server port** (default 5520), not the Nitrado port (5523):

```bash
# Correct
curl "http://localhost/api/ping?host=server.com&port=5520&method=hyquery"

# Wrong (will timeout)
curl "http://localhost/api/ping?host=server.com&port=5523&method=hyquery"
```

### UDP Blocked

Ensure UDP traffic is allowed through firewalls:

```bash
# Check if port is open (Linux)
nc -zuv 192.168.1.78 5520
```

## Caching

Both methods benefit from caching, but HyQuery's cache is especially valuable due to the connectionless UDP nature:

- Cache key includes method name: `server_hyquery_192.168.1.78_5520_all`
- Separate cache entries for different protocols
- Recommended cache duration: 30-60 seconds for server lists
