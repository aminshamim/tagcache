# TagCache CLI Usage Guide

TagCache now includes a comprehensive command-line interface that allows you to interact with a running TagCache server as both a client and server.

## Installation

### Homebrew (macOS/Linux)
```bash
brew install tagcache
```

### Debian/Ubuntu
```bash
wget https://github.com/aminshamim/tagcache/releases/latest/download/tagcache_amd64.deb
sudo dpkg -i tagcache_amd64.deb
```

## Basic Usage

TagCache can operate in two modes:

1. **Server mode** (default): Starts the TagCache server
2. **Client mode**: Connects to a running server to execute commands

### Server Mode

Start the TagCache server:
```bash
tagcache server
# or simply
tagcache
```

### Client Mode

All client commands follow this pattern:
```bash
tagcache [OPTIONS] <COMMAND> [COMMAND_OPTIONS]
```

## Authentication

TagCache requires authentication for most operations. You can authenticate using:

1. **Username and password**:
   ```bash
   tagcache --username myuser --password mypass <command>
   ```

2. **Authentication token**:
   ```bash
   tagcache --token mytoken <command>
   ```

3. **Environment variables**:
   ```bash
   export TAGCACHE_USERNAME="myuser"
   export TAGCACHE_PASSWORD="mypass"
   tagcache <command>
   ```

## Available Commands

### 1. PUT - Store Key-Value Pairs

Store a value with optional tags and TTL:
```bash
# Basic put
tagcache put mykey "my value"

# With tags
tagcache put mykey "my value" --tags "tag1,tag2,tag3"

# With TTL (time-to-live in milliseconds)
tagcache put mykey "my value" --ttl-ms 60000

# Complete example
tagcache --username myuser --password mypass put session:123 "user data" --tags "session,user,active" --ttl-ms 3600000
```

### 2. GET - Retrieve Data

#### Get by Key
```bash
tagcache get key mykey
```

#### Get Keys by Tags
```bash
# Single tag
tagcache get tag "session"

# Multiple tags
tagcache get tag "session,active"
```

### 3. FLUSH - Remove Data

#### Flush Single Key
```bash
tagcache flush key mykey
```

#### Flush by Tags
```bash
# Remove all entries with specified tags
tagcache flush tag "session,expired"
```

#### Flush All Data
```bash
tagcache flush all
```

### 4. STATS - Server Statistics

Get comprehensive server statistics:
```bash
tagcache stats
```

Example output:
```
TagCache Statistics:
==================
Hits: 1542
Misses: 23
Puts: 890
Invalidations: 45
Hit Ratio: 98.53%
Total Items: 845
Total Bytes: 125430
Total Tags: 67
Shards: 16
```

### 5. STATUS - Server Status

Check server health and basic information:
```bash
tagcache status
```

### 6. HEALTH - Health Check

Simple health check:
```bash
tagcache health
```

### 7. RESTART - Server Restart

Show restart instructions for different deployment methods:
```bash
tagcache restart
```

## Connection Options

### Custom Host and Port

Connect to a TagCache server on a different host or port:
```bash
tagcache --host 192.168.1.100 --port 9090 stats
```

### Default Connection

By default, TagCache connects to:
- Host: `localhost`
- Port: `8080`

## Performance Testing

TagCache includes a TCP benchmark tool:
```bash
# Basic benchmark
bench_tcp

# Custom parameters
bench_tcp <host> <port> <connections> <duration_seconds>

# Example: benchmark localhost:1984 with 10 connections for 5 seconds
bench_tcp localhost 1984 10 5
```

## Examples

### Complete Workflow Example

```bash
# 1. Start server (in one terminal)
tagcache server

# 2. Check health (in another terminal)
tagcache health

# 3. Store some data
tagcache --username myuser --password mypass put user:1001 "John Doe" --tags "user,active"
tagcache --username myuser --password mypass put user:1002 "Jane Smith" --tags "user,premium,active"
tagcache --username myuser --password mypass put session:abc123 "session data" --tags "session,user:1001" --ttl-ms 1800000

# 4. Retrieve data
tagcache --username myuser --password mypass get key user:1001
tagcache --username myuser --password mypass get tag "active"

# 5. Check statistics
tagcache --username myuser --password mypass stats

# 6. Clean up expired sessions
tagcache --username myuser --password mypass flush tag "session"
```

### Batch Operations with Shell Scripting

```bash
#!/bin/bash
AUTH="--username myuser --password mypass"

# Store multiple user sessions
for i in {1..10}; do
    tagcache $AUTH put "session:$i" "session_data_$i" --tags "session,batch" --ttl-ms 3600000
done

# Check how many sessions we have
tagcache $AUTH get tag "session"

# Get statistics
tagcache $AUTH stats
```

## Environment Variables for Server

When running in server mode, TagCache respects these environment variables:

- `PORT`: HTTP server port (default: 8080)
- `TCP_PORT`: TCP server port (default: 1984)
- `NUM_SHARDS`: Number of cache shards (default: 16)
- `CLEANUP_INTERVAL_MS`: Cleanup interval in milliseconds
- `CLEANUP_INTERVAL_SECONDS`: Cleanup interval in seconds (default: 60)
- `ALLOWED_ORIGIN`: CORS allowed origin
- `RUST_LOG`: Logging level (debug, info, warn, error)

## Error Handling

The CLI provides clear error messages:

- **Authentication errors**: Check your username/password or token
- **Connection errors**: Ensure the server is running and accessible
- **Not found errors**: Key or tag doesn't exist
- **Invalid commands**: Use `--help` for correct syntax

## Tips and Best Practices

1. **Use tags effectively**: Group related data with meaningful tags for easy bulk operations
2. **Set appropriate TTLs**: Use TTL for temporary data like sessions or caches
3. **Monitor with stats**: Regularly check `tagcache stats` to monitor performance
4. **Authentication**: Store credentials securely, consider using environment variables
5. **Performance testing**: Use `bench_tcp` to test your server performance under load

## Getting Help

- `tagcache --help`: Show all available commands
- `tagcache <command> --help`: Show help for a specific command
- `tagcache --version`: Show version information

For more information, visit: https://github.com/aminshamim/tagcache
