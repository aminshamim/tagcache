# 🚀 TagCache

<div align="center">

**A lightning-fast, tag-aware in-memory cache server written in Rust**

*Built for speed, designed for simplicity, ready for production*

[![Rust](https://img.shields.io/badge/rust-%23000000.svg?style=for-the-badge&logo=rust&logoColor=white)](https://www.rust-lang.org/)
[![License](https://img.shields.io/badge/license-MIT-blue?style=for-the-badge)](LICENSE)
[![Cross Platform](https://img.shields.io/badge/platform-macOS%20%7C%20Linux%20%7C%20Windows-lightgrey?style=for-the-badge)](https://github.com/aminshamim/tagcache/releases)

</div>

---

## ⚡ What is TagCache?

TagCache is a high-performance, sharded, tag-aware in-memory cache server that offers:

🔹 **JSON HTTP API** (port 8080) - RESTful interface for web applications  
🔹 **TCP Protocol** (port 1984) - Ultra-low latency binary protocol  
🔹 **Tag-based Invalidation** - Organize and clear related data efficiently  
🔹 **Built-in Web Dashboard** - Beautiful React UI for monitoring and management  
🔹 **CLI Interface** - Complete command-line control  
🔹 **Production Ready** - Authentication, monitoring, and deployment tools  

## ✨ Key Features

| Feature | Description |
|---------|-------------|
| 🏎️ **Blazing Fast** | Multi-shard design with DashMap + hash sharding for maximum concurrency |
| 🏷️ **Tag-Aware** | Associate multiple tags with keys for advanced invalidation strategies |
| ⏱️ **Flexible TTL** | Precise expiration control in milliseconds or seconds |
| 🔄 **Dual Protocols** | Choose between JSON HTTP API or high-performance TCP protocol |
| 📊 **Rich Monitoring** | Real-time statistics, performance metrics, and health checks |
| 🔐 **Secure by Default** | Built-in authentication with password management |
| 🌍 **Cross-Platform** | Native support for macOS, Linux, and Windows |
| 📦 **Easy Deploy** | Homebrew, Debian packages, Docker, and binary releases |

## 📚 Table of Contents

- [🚀 Quick Start](#-quick-start)
- [📥 Installation](#-installation)
- [⚙️ Configuration Management](#%EF%B8%8F-configuration-management)
- [🔐 Authentication & Security](#-authentication--security)
- [💻 Command Line Interface](#-command-line-interface-cli)
- [🌐 HTTP JSON API](#-http-json-api)
- [⚡ TCP Protocol](#-tcp-protocol)
- [📊 Performance Testing](#-performance-testing)
- [🐳 Docker](#-docker)
- [⚙️ Configuration](#-configuration)
- [🔧 Development](#-development)

## 🚀 Quick Start

```bash
# Install (choose your method)
brew install aminshamim/tap/tagcache

# Start server
tagcache server

# Use CLI with default credentials (admin/password)
tagcache --username admin --password password put "hello" "world"
tagcache --username admin --password password get key "hello"
tagcache --username admin --password password stats

# Visit web dashboard
open http://localhost:8080
```

## 📥 Installation

### 📦 Pre-built Binaries (Recommended)

#### 🍎 macOS (Intel & Apple Silicon)
```bash
# For macOS Intel (x86_64)
wget https://github.com/aminshamim/tagcache/releases/latest/download/tagcache-macos-x86_64.tar.gz
tar xzf tagcache-macos-x86_64.tar.gz
sudo cp tagcache /usr/local/bin/

# For macOS Apple Silicon (ARM64/M1/M2)
wget https://github.com/aminshamim/tagcache/releases/latest/download/tagcache-macos-arm64.tar.gz
tar xzf tagcache-macos-arm64.tar.gz
sudo cp tagcache /usr/local/bin/

# Then run
tagcache
```

#### 🪟 Windows
```bash
# Download and extract
curl -L -o tagcache-windows.zip https://github.com/aminshamim/tagcache/releases/latest/download/tagcache-windows-x86_64.exe.zip
# Extract and run tagcache.exe
```

### 🍺 Homebrew (macOS/Linux)
```bash
# Install from our tap
brew tap aminshamim/tap
brew install tagcache

# Or install directly
brew install aminshamim/tap/tagcache
```

### 🐧 Debian/Ubuntu
```bash
# Download from releases page (when available)
wget https://github.com/aminshamim/tagcache/releases/download/v1.0.2/tagcache_1.0.2_amd64.deb
sudo dpkg -i tagcache_1.0.2_amd64.deb

# Start the service
sudo systemctl enable tagcache
sudo systemctl start tagcache
```

### RHEL/CentOS/Fedora
```bash
# Download from releases page
wget https://github.com/aminshamim/tagcache/releases/download/v0.1.0/tagcache-0.1.0-1.x86_64.rpm
sudo rpm -ivh tagcache-0.1.0-1.x86_64.rpm

# Start the service
sudo systemctl enable tagcache
sudo systemctl start tagcache
```

### Windows
1. Download `tagcache-windows-x86_64.zip` from [releases](https://github.com/aminshamim/tagcache/releases)
2. Extract `tagcache.exe` to your desired location
3. Run `tagcache.exe` from command prompt

### 🦀 From Source (Rust)
```bash
cargo install --git https://github.com/aminshamim/tagcache
```

### 🐳 Docker
```bash
docker run -p 8080:8080 -p 1984:1984 tagcache:latest
```

## ⚙️ Configuration

### 🔧 Build & Run (Development)
```bash
cargo build --release
./target/release/tagcache
```
Server starts HTTP on `:8080` and TCP on `:1984` by default.

### 🌍 Environment Variables
Primary (preferred):
- `PORT` – HTTP port (default 8080)
- `TCP_PORT` – TCP protocol port (default 1984)
- `NUM_SHARDS` – number of shards (default 16)
- `CLEANUP_INTERVAL_MS` – sweep interval in ms (fallback to seconds if not set)
- `CLEANUP_INTERVAL_SECONDS` – sweep interval in seconds (if ms not set)

Legacy (still accepted, logged when used):
- `TC_HTTP_PORT`, `TC_TCP_PORT`, `TC_NUM_SHARDS`, `TC_SWEEP_INTERVAL_MS`

## ⚙️ Configuration Management

TagCache uses a configuration file system similar to `php.ini` or `nginx.conf` for persistent settings. All configuration changes persist across server restarts.

### 📁 Configuration File Location

TagCache automatically looks for `tagcache.conf` in:
1. Current working directory (highest priority)
2. User config directory (`~/.config/tagcache/tagcache.conf` on Linux/macOS)

```bash
# Check current configuration file path
tagcache config path

# Show current configuration
tagcache config show
```

### 🔧 Configuration Management Commands

```bash
# Create configuration file with defaults (if it doesn't exist)
tagcache config show  # Creates tagcache.conf with defaults

# Change authentication credentials
tagcache config set authentication.username "my_admin"
tagcache config set authentication.password "secure_password_123"

# Change server settings
tagcache config set server.http_port 9090
tagcache config set server.tcp_port 1985
tagcache config set server.num_shards 32

# Change cache settings
tagcache config set cache.max_key_length 2048
tagcache config set logging.level "debug"

# Reset to defaults
tagcache config reset

# View example configuration
cat tagcache.conf.example
```

### 📝 Configuration Sections

The configuration file includes these sections:

- **`[server]`** - HTTP/TCP ports, shards, cleanup interval
- **`[authentication]`** - Username, password, token lifetime
- **`[cache]`** - TTL settings, size limits, tag limits
- **`[logging]`** - Log level, format, file output
- **`[performance]`** - TCP settings, connection limits
- **`[security]`** - Auth requirements, rate limiting, IP restrictions

### 🔄 Configuration Changes

Configuration changes via CLI persist to the file immediately:

```bash
# Change password via configuration
tagcache config set authentication.password "new_password"

# Change password via API (also persists to config file)
tagcache --username admin --password current_password change-password "new_password"

# Both methods update tagcache.conf and persist across server restarts
```

**Important:** Server restart is required for configuration changes to take effect.

## 🔐 Authentication & Security

TagCache includes built-in authentication with default credentials and flexible management options.

### 🚀 Quick Start - Default Credentials
TagCache starts with secure default credentials:
- **Username:** `admin`
- **Password:** `password`

**⚠️ Security Notice:** Change the default password immediately in production!

### 🛠️ Password Management CLI Commands

#### Change Password
```bash
# Change the password (requires current credentials)
tagcache --username admin --password password change-password "your-new-secure-password"
```

#### Reset to Defaults
```bash
# Master reset to default credentials (requires current credentials)
tagcache --username admin --password current-password reset-credentials
# After reset: username=admin, password=password
```

#### Example Password Management Flow
```bash
# 1. Change from defaults
tagcache --username admin --password password change-password "MySecure123!"

# 2. Use new password for operations
tagcache --username admin --password "MySecure123!" stats

# 3. Reset if needed (emergency recovery)
tagcache --username admin --password "MySecure123!" reset-credentials
```

### 🌐 Authentication for HTTP API

All API endpoints (except `/health`) require authentication using Basic Auth:

```bash
# Using current credentials
curl -u admin:password http://localhost:8080/stats

# Or with explicit Basic Auth header
echo -n "admin:password" | base64  # YWRtaW46cGFzc3dvcmQ=
curl -H "Authorization: Basic YWRtaW46cGFzc3dvcmQ=" http://localhost:8080/stats
```

### 🔄 Advanced Authentication (Tokens & Rotation)

#### Login for Token-Based Auth
```bash
# Get an auth token (alternative to Basic Auth)
curl -s -X POST http://localhost:8080/auth/login \
  -u admin:password \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"password"}'
```
Response:
```json
{"token":"<48-char-random>","expires_in":3600}
```

#### Using Tokens
```bash
# Use token instead of username/password
TOKEN="your-token-here"
curl -H "Authorization: Bearer $TOKEN" http://localhost:8080/stats
```

#### Credential Rotation (Advanced)
```bash
# Rotate to new random credentials (invalidates all tokens)
curl -X POST http://localhost:8080/auth/rotate \
  -H "Authorization: Bearer $TOKEN"
```
Response:
```json
{"ok":true,"username":"newRandomUser","password":"newRandomPass"}
```

### 🔒 Security Best Practices

1. **Change Default Password:** Always change from `admin/password` in production
2. **Use HTTPS:** Put TagCache behind a TLS proxy (nginx, Caddy, Traefik)
3. **Network Security:** Bind to specific interfaces, use firewalls
4. **Environment Variables:** Set `ALLOWED_ORIGIN` for CORS restrictions
5. **Regular Rotation:** Use the CLI or API to rotate credentials periodically

### 🎯 Authentication Summary
- **Default:** `admin` / `password` (change immediately!)
- **CLI Management:** `change-password` and `reset-credentials` commands  
- **API Access:** Basic Auth for all endpoints (except health check)
- **Web Dashboard:** Integrated login with token management
- **Token-based:** Optional Bearer token authentication
- **Emergency Recovery:** Master reset command available

---

## HTTP API
Base URL: `http://host:PORT`

---

## 💻 Command Line Interface (CLI)

TagCache includes a comprehensive CLI for all cache operations. Perfect for scripts, testing, and interactive use.

### Quick Start
```bash
# Start server
tagcache server

# Basic operations (use your credentials from credential.txt)
tagcache --username <user> --password <pass> put mykey "my value" --tags "tag1,tag2"
tagcache --username <user> --password <pass> get key mykey
tagcache --username <user> --password <pass> stats
```

### Available Commands

#### 📦 Cache Operations
- `tagcache put <key> <value>` - Store data with optional tags and TTL
- `tagcache get key <key>` - Retrieve value by key  
- `tagcache get tag <tags>` - Get keys by comma-separated tags
- `tagcache flush key <key>` - Remove specific key
- `tagcache flush tag <tags>` - Remove all keys with tags
- `tagcache flush all` - Clear entire cache

#### 📊 Monitoring & Status
- `tagcache stats` - Show detailed statistics
- `tagcache status` - Show server status  
- `tagcache health` - Health check (no auth required)
- `tagcache restart` - Restart instructions

#### 🔐 Security & Authentication
- `tagcache change-password <new-password>` - Change the password
- `tagcache reset-credentials` - Reset to default admin/password

#### 🚀 Server Management
- `tagcache server` - Start the TagCache server

### CLI Examples
```bash
# Store session data with 1-hour TTL
tagcache put "session:abc123" "user_data" --tags "session,user:1001" --ttl-ms 3600000

# Get all active sessions
tagcache get tag "session,active"

# Remove expired sessions  
tagcache flush tag "session,expired"

# Check cache performance
tagcache stats
```

📖 **[Complete CLI Documentation](docs/CLI_USAGE.md)**

---

## 🌐 HTTP JSON API

The HTTP interface is JSON-based and ideal for web applications.

For all examples below, first export a Basic Auth header (reads bootstrap credentials from `credential.txt`):
```bash
USER=$(grep '^username=' credential.txt | cut -d= -f2)
PASS=$(grep '^password=' credential.txt | cut -d= -f2)
B64=$(printf '%s:%s' "$USER" "$PASS" | base64)
AUTH="-H Authorization: Basic $B64"
```
Then prepend `$AUTH` (or copy the header literal) to every curl command.

### PUT /put
Store/update a value.
```bash
curl -X POST http://127.0.0.1:8080/put \
  -H "Authorization: Basic $B64" \
  -H 'Content-Type: application/json' \
  -d '{"key":"user:42","value":"hello","tags":["users","trial"],"ttl_ms":6000000}'
```
Response:
```json
{"ok":true,"ttl_ms":60000}
```

### GET /get/:key
```bash
curl -H "Authorization: Basic $B64" http://127.0.0.1:8080/get/user:42
```
Response (hit):
```json
{"value":"hello"}
```
Response (miss):
```json
{"error":"not_found"}
```

### GET /keys-by-tag?tag=TAG&limit=N
```bash
curl -H "Authorization: Basic $B64" 'http://127.0.0.1:8080/keys-by-tag?tag=users&limit=50'
```
Response:
```json
{"keys":["user:42", "user:7"]}
```

### POST /invalidate-key
```bash
curl -X POST http://127.0.0.1:8080/invalidate-key \
  -H "Authorization: Basic $B64" \
  -H 'Content-Type: application/json' \
  -d '{"key":"user:42"}'
```
Response: `{ "success": true }`

### POST /invalidate-tag
```bash
curl -X POST http://127.0.0.1:8080/invalidate-tag \
  -H "Authorization: Basic $B64" \
  -H 'Content-Type: application/json' \
  -d '{"tag":"trial"}'
```
Response: `{ "success": true, "count": <removed> }`

### GET /stats
```bash
curl -H "Authorization: Basic $B64" http://127.0.0.1:8080/stats
```
Response (extended fields may appear in newer versions):
```json
{
  "hits": 10,
  "misses": 2,
  "puts": 12,
  "invalidations": 1,
  "hit_ratio": 0.8333,
  "items": 2500,
  "bytes": 1827364,
  "tags": 37
}
```

---

## ⚡ TCP Protocol
Line-based, tab-delimited. One command per line. Fields separated by `\t` (TAB). Newline terminates command.

Commands:
```
PUT <key> <ttl_ms|- > <tag1,tag2|- > <value>
GET <key>
DEL <key>
INV_TAG <tag>
KEYS_BY_TAG <tag>   (alias: KEYS <tag>)
STATS
```
Responses (one line):
```
OK | ERR <msg>
VALUE <value> | NF
DEL ok | DEL nf
INV_TAG <count>
KEYS <k1,k2,...>
STATS <hits> <misses> <puts> <invalidations> <hit_ratio>
```
Example session (using `nc` and showing literal tabs as ↹ for clarity):
```
PUT↹user:1↹60000↹users,trial↹hello world
OK
GET↹user:1
VALUE	hello world
INV_TAG↹trial
INV_TAG	1
GET↹user:1
NF
```
Notes:
- Value, key, and tags must not contain tabs or newlines.
- `-` means no TTL or no tags.
- No escaping layer; for binary or large payloads consider a future binary protocol.

---

## 🐳 Docker
Build image:
```bash
docker build -t tagcache:latest .
```
Run:
```bash
docker run --rm -p 8080:8080 -p 1984:1984 tagcache:latest
```
Environment overrides:
```bash
docker run -e NUM_SHARDS=64 -e CLEANUP_INTERVAL_MS=5000 -p 8080:8080 -p 1984:1984 tagcache:latest
```

## PHP Example
Provided under `examples/php/test.php`:
```bash
php examples/php/test.php
```
Set `TAGCACHE_URL` for a custom base URL.

---

## 📊 Performance Testing

### Included TCP Benchmark Tool

TagCache includes `bench_tcp`, a high-performance benchmarking tool for testing server throughput and latency.

```bash
# Basic benchmark (if installed via Homebrew/package)
bench_tcp

# From source
cargo run --release --bin bench_tcp
./target/release/bench_tcp

# Custom benchmark parameters
bench_tcp localhost 1984 64 15  # host port connections duration_seconds
```

Example output:
```
Benchmark config: host=127.0.0.1 port=1984 conns=32 duration=10s keys=100 mode=get ttl_ms=60000
Results:
Total ops: 1877859
Throughput: 187785.90 ops/sec  
Latency (microseconds): min 19.4 p50 166.2 p90 224.4 p95 244.6 p99 289.9 max 914.4 avg 170.2
```

The benchmark tool tests the high-performance TCP protocol and can achieve very high throughput rates depending on your hardware.
- `--mode` - Benchmark mode: `get` or `put` (default: get)
- `--ttl` - TTL in milliseconds (default: 60000)

**Note:** Make sure TagCache server is running first using `./tagcache.sh`

Sample results (reference only):
```
PUT:  ~69.9 K ops/sec  p50 ~0.87 ms  p99 ~1.79 ms
GET:  ~65.2 K ops/sec  p50 ~0.95 ms  p99 ~2.25 ms
```

### HTTP via wrk
`put.lua`:
```lua
wrk.method = "POST"
wrk.path   = "/put"
wrk.headers["Content-Type"] = "application/json"
function request()
  local k = math.random(1,100000)
  return wrk.format(nil, nil, nil, '{"key":"k'..k..'","value":"v","tags":["t"],"ttl_ms":60000}')
end
```
Run:
```bash
wrk -t8 -c64 -d10s -s put.lua http://127.0.0.1:8080
```
`get.lua`:
```lua
math.randomseed(os.time())
function request()
  local k = math.random(1,100000)
  return wrk.format("GET", "/get/k"..k)
end
```
Run:
```bash
wrk -t8 -c64 -d10s -s get.lua http://127.0.0.1:8080
```

## Performance Tuning
- Increase `NUM_SHARDS` to reduce contention (power of 2 often helps hash distribution)
- Use `RUSTFLAGS="-C target-cpu=native"` for maximum local CPU optimizations
- Pin process / adjust OS networking (e.g. `ulimit -n`, TCP backlog) for very high concurrency
- Reduce JSON overhead by preferring TCP protocol in latency-sensitive paths

## Limitations / Roadmap
- No persistence (in-memory only)
- No replication / clustering (future: consistent hashing + peer discovery)
- No compression / binary protocol (planned binary frame optional layer)
- Tag cardinality not bounded (monitor memory usage with many distinct tags)

---

## 🔧 Development
Run in debug:
```bash
cargo run
```
Run benchmarks (release recommended):
```bash
cargo build --release
./target/release/bench_tcp --mode get --conns 32 --duration 5 --keys 5000
```

## License
MIT License - see [LICENSE](LICENSE) file for details.

---

## 👨‍💻 Author

**Md. Aminul Islam Sarker**
- 📧 Email: [aminshamim@gmail.com](mailto:aminshamim@gmail.com)
- 💼 LinkedIn: [https://www.linkedin.com/in/aminshamim/](https://www.linkedin.com/in/aminshamim/)
- 🐙 GitHub: [@aminshamim](https://github.com/aminshamim)

---
PRs and issues welcome!
