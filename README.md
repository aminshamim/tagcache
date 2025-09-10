# TagCache

A lightweight, sharded, tag-aware in‑memory cache server written in Rust. It exposes:

- A JSON HTTP API (default port 8080)
- A low‑latency custom TCP text protocol (default port 1984)
- Tag-based invalidation (by key or by tag)
- Optional per-entry TTL
- Periodic background expiration sweeping

## Features
- Fast concurrent access via multi-shard design (DashMap + hash sharding)
- Associate multiple tags with each key
- Invalidate a single key or all keys sharing a tag
- TTL specified in milliseconds or seconds (first non-null of `ttl_ms`, `ttl_seconds`)
- Stats endpoint (hits, misses, puts, invalidations, hit ratio)
- Compact TCP protocol for reduced overhead vs HTTP/JSON

## Installation

### Pre-built Binaries (Recommended)

#### macOS (Both Intel and Apple Silicon supported)
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

#### Windows
```bash
# Download and extract
curl -L -o tagcache-windows.zip https://github.com/aminshamim/tagcache/releases/latest/download/tagcache-windows-x86_64.exe.zip
# Extract and run tagcache.exe
```

### Homebrew (macOS/Linux)
```bash
# Install from our tap
brew tap aminshamim/tap
brew install tagcache

# Or install directly
brew install aminshamim/tap/tagcache
```

### Debian/Ubuntu (Building)
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

### From Source (Rust)
```bash
cargo install --git https://github.com/aminshamim/tagcache
```

### Docker
```bash
docker run -p 8080:8080 -p 1984:1984 tagcache:latest
```

## Build & Run (Development)
```bash
cargo build --release
./target/release/tagcache
```
Server starts HTTP on `:8080` and TCP on `:1984` by default.

## Environment Variables
Primary (preferred):
- `PORT` – HTTP port (default 8080)
- `TCP_PORT` – TCP protocol port (default 1984)
- `NUM_SHARDS` – number of shards (default 16)
- `CLEANUP_INTERVAL_MS` – sweep interval in ms (fallback to seconds if not set)
- `CLEANUP_INTERVAL_SECONDS` – sweep interval in seconds (if ms not set)

Legacy (still accepted, logged when used):
- `TC_HTTP_PORT`, `TC_TCP_PORT`, `TC_NUM_SHARDS`, `TC_SWEEP_INTERVAL_MS`

## Authentication

TagCache ships with a lightweight built‑in authentication layer used by both the dashboard UI and any API clients.

### Credential File (bootstrap)
On first startup (when `credential.txt` is absent) the server auto‑generates a file in the working directory:
```
credential.txt
username=<random>
password=<random>
created_at=2025-09-09T00:00:00Z
version=1
```
File permissions on Unix are restricted to `600` (owner read/write) for basic safety. Keep this file secret; anyone with it can obtain an auth token. You may commit a different credentials management flow in production (env injection / secret manager) by pre‑creating `credential.txt` before launch.

### Login Flow
Login uses a POST to `/auth/login` with:
1. A Basic Auth header (`Authorization: Basic base64(username:password)`) – used server side for quick validation.
2. The same credentials in a JSON body (mirrors header for clarity & future flexibility).

Example:
```bash
USER=$(grep '^username=' credential.txt | cut -d= -f2)
PASS=$(grep '^password=' credential.txt | cut -d= -f2)
B64=$(printf '%s:%s' "$USER" "$PASS" | base64)
curl -s -X POST http://127.0.0.1:8080/auth/login \
  -H "Authorization: Basic $B64" \
  -H 'Content-Type: application/json' \
  -d '{"username":"'"$USER"'","password":"'"$PASS"'"}'
```
Response:
```json
{"token":"<48-char-random>","expires_in":3600}
```

### Using the Token
Pass the token with the Bearer scheme:
```bash
TOKEN="<token-from-login>"
curl -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8080/stats
```
The dashboard automatically stores the token (localStorage) so refreshes keep the session.

### Rotation
POST `/auth/rotate` with a **current valid token** rotates both username/password (new random values) and invalidates **all existing tokens**:
```bash
curl -X POST http://127.0.0.1:8080/auth/rotate \
  -H "Authorization: Bearer $TOKEN"
```
Response:
```json
{"ok":true,"username":"<newUser>","password":"<newPass>"}
```
Update any external clients with the new credentials, then obtain a new token via `/auth/login`.

### Setup Detection
`GET /auth/setup_required` returns `{ "setup_required": true }` only before the first credential file is created, enabling UI onboarding flows.

### Fallbacks & Validation Order
For every protected endpoint the server checks:
1. `Authorization: Bearer <token>` (valid token present in in‑memory token set)
2. If not a bearer match, `Authorization: Basic <base64>` (compared to current credential pair)
If neither matches: HTTP 401 `{ "error":"unauthorized" }`.

### Hardening Tips
- Mount the working directory with proper file ownership (`credential.txt` should not be writable by untrusted users).
- Put the server behind TLS (reverse proxy like Caddy, Nginx, Traefik) – the server itself is plaintext HTTP.
- Use a secret manager or inject credentials via volume mount and rebuild the file with the same format if you prefer deterministic credentials.
- Set `ALLOWED_ORIGIN` (env var) for strict CORS if exposing dashboard remotely.

### Quick Scripted Login Helper
```bash
login() { local host=${1:-http://127.0.0.1:8080}; \
  local u=$(grep '^username=' credential.txt|cut -d= -f2); \
  local p=$(grep '^password=' credential.txt|cut -d= -f2); \
  local b=$(printf '%s:%s' "$u" "$p" | base64); \
  curl -s -X POST "$host/auth/login" -H "Authorization: Basic $b" -H 'Content-Type: application/json' -d '{"username":"'"$u"'","password":"'"$p"'"}'; }
```

### Frontend Behavior
- On successful login: token + username persisted; all API calls automatically attach `Authorization: Bearer <token>`.
- On 401 responses (not yet implemented): a future enhancement can clear stored token and redirect to the login screen.

---

## HTTP API
Base URL: `http://host:PORT`

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

## TCP Protocol
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

## Docker
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

## Benchmarking
### Included TCP Bench Tool

Basic usage:
```bash
# Using cargo (recommended)
cargo run --release --bin bench_tcp

# Using compiled binary directly
./target/release/bench_tcp
```

Advanced usage with parameters:
```bash
# Basic benchmark with defaults (GET mode, 32 connections, 10 seconds, 100 keys)
cargo run --release --bin bench_tcp

# PUT benchmark with custom settings
cargo run --release --bin bench_tcp -- --mode put --conns 64 --duration 8 --keys 2000

# GET benchmark with custom settings  
cargo run --release --bin bench_tcp -- --mode get --conns 64 --duration 8 --keys 2000

# High-load benchmark
cargo run --release --bin bench_tcp -- --conns 100 --duration 30 --keys 1000 --mode put

# Custom host and port
cargo run --release --bin bench_tcp -- --host 127.0.0.1 --port 1984 --conns 50 --duration 15
```

Available parameters:
- `--host` - Server host (default: 127.0.0.1)
- `--port` - Server port (default: 1984) 
- `--conns` - Number of concurrent connections (default: 32)
- `--duration` - Test duration in seconds (default: 10)
- `--keys` - Number of keys to use (default: 100)
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

## Development
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
(Choose a license and place it here, e.g. MIT or Apache-2.0.)

---
PRs and issues welcome.
